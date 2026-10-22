<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Fase 5: sincronización con Mercado Pago SIN depender de webhooks, y guarda del cron de trials.
 *
 *  - correr(): cron cada 30 min. (a) Lote de hasta LOTE suscripciones ya vinculadas: consulta GET /preapproval/{id}
 *    y aplica el MISMO CN_Webhook::gestionar_ciclo_sub() que el webhook. (b) Aviso de suscripciones
 *    authorized en MP sin vincular hace más de 1 hora. NUNCA vincula nada solo.
 *  - guarda_trial(): antes de cancelar un trial vencido sin suscripción, mira si pagó por link directo.
 *
 * Regla común: si MP falla (red, 4xx/5xx, respuesta rara) NO se cambia el estado de nadie; se reintenta en la
 * próxima corrida.
 */
class CN_Sync {
	const LOTE             = 20;       // suscripciones por corrida
	const TOPE_REQUESTS    = 80;       // pedidos a MP por corrida (incluye reintentos por 429)
	const TOPE_ESPERA_SEG  = 40;       // segundos totales de espera por 429 por corrida
	const TOPE_TIEMPO_SEG  = 100;      // la corrida deja de pedir a MP pasado este tiempo (el resto queda para la próxima)
	const MAX_INTENTOS_ITEM = 5;       // corridas seguidas en que un ítem puede fallar antes de salir de la cola de reintento
	const MAX_COLA         = 200;
	const OPT_FALLIDOS     = 'cn_sync_mp_fallidos'; // cola de ítems fallidos: id de socia => array( preapproval_id, intentos, desde )
	const LOCK_SEG         = 1500;     // una corrida no pisa a la anterior (25 min)
	const MIN_SIN_VINCULAR = 3600;     // una suscripción authorized sin vincular se avisa pasada 1 hora
	const REAVISO_SEG      = 86400;    // y se vuelve a avisar como máximo 1 vez por día
	const MAX_ITEMS_ALERTA = 20;
	const MONTO_MIN_PLAN   = 15500;    // cobro "de plan" para la guarda del trial
	/** Pausa entre llamadas a MP dentro de un lote, en microsegundos (0,3 s). Propiedad y no constante solo para poder probar sin esperar. */
	public static $pausa_us = 300000;
	/** Agrega el intervalo de 30 minutos al cron de WordPress (filtro cron_schedules). */
	public static function registrar_intervalo( $schedules ) {
		if ( ! isset( $schedules['cn_30min'] ) ) {
			$schedules['cn_30min'] = array( 'interval' => 1800, 'display' => 'Cada 30 minutos (Club Natureza)' );
		}
		return $schedules;
	}
	/** Opción en '1'/'0' con valor por defecto. */
	protected static function flag( $nombre, $defecto ) {
		return '1' === (string) get_option( $nombre, $defecto );
	}
	// ======================================================================
	// PUNTO 2 — SYNC POR CRON
	// ======================================================================
	/** Entrada del cron. Nunca lanza: cada parte tiene su try/catch y deja rastro en wp_cn_mp_log. */
	public static function correr() {
		if ( ! self::flag( 'cn_sync_mp_activo', '1' ) ) {
			return;
		}
		if ( get_transient( 'cn_sync_mp_lock' ) ) {
			return; // la corrida anterior sigue en curso.
		}
		set_transient( 'cn_sync_mp_lock', 1, self::LOCK_SEG );
		try {
			self::sincronizar_lote();
		} catch ( Throwable $e ) {
			CN_Webhook::registrar_log_mp( 'sub_error', array( 'paso' => 'sync_lote', 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
			CN_Webhook::sub_avisar_una_vez_por_hora( 'sub_error', 'sync_lote', array( 'paso' => 'sync_lote', 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
		}
		try {
			self::alertar_sin_vincular();
		} catch ( Throwable $e ) {
			CN_Webhook::registrar_log_mp( 'sub_error', array( 'paso' => 'sync_alerta', 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
		}
		try {
			CN_Alta_Mail::correr(); // 'off' por defecto: no hace nada. Solo GET a MP; nunca vincula ni crea socias.
		} catch ( Throwable $e ) {
			CN_Webhook::registrar_log_mp( 'sub_error', array( 'paso' => 'alta_mail', 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
			CN_Webhook::avisar_error_n8n( 'alta_mail_error', array( 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
		}
		delete_transient( 'cn_sync_mp_lock' );
	}
	/**
	 * Lote de hasta LOTE filas con preapproval_id y plan no nulos (las que vinculó el sistema), no canceladas,
	 * recorridas en rotación por id (la opción cn_sync_mp_cursor guarda por dónde iba), MÁS los ítems que fallaron en
	 * corridas anteriores (cola cn_sync_mp_fallidos, hasta LOTE). Por cada una:
	 * GET /preapproval/{id} (con pausa entre llamadas, reintentos ante 429 y topes por corrida) y gestionar_ciclo_sub()
	 * con el estado de MP.
	 *  - authorized: limpia baja_en o extiende acceso_hasta con next_payment_date.
	 *  - cancelled / paused: programa baja_en sin cortar el acceso. Con cn_sub_modo_seguro = '1' (el valor por
	 *    defecto) NO se programa nada: solo queda un rastro en el log (como máximo 1 por día y suscripción).
	 * Si MP falla o devuelve algo inválido para una fila (tras los reintentos), esa fila no se toca: queda en mp_log
	 * (sync_item_fallido) y en la cola para reintentar en la próxima corrida, y la corrida SIGUE con las demás. El
	 * cursor avanza siempre. Si se llega a un tope (pedidos, espera o tiempo) el resto del lote se encola igual.
	 *
	 * @return array{procesadas:int,fallos:int,omitidas_modo_seguro:int,abortada:bool,reintentadas:int,en_cola:int}
	 *         abortada = true si un tope cortó la corrida antes de terminar (lo pendiente quedó en la cola).
	 */
	public static function sincronizar_lote() {
		global $wpdb;
		$t_miembros = CN_DB::tabla( 'miembros' );
		$cursor     = (int) get_option( 'cn_sync_mp_cursor', 0 );
		$filas      = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, preapproval_id FROM {$t_miembros}
			 WHERE preapproval_id IS NOT NULL AND preapproval_id <> '' AND plan IS NOT NULL AND plan <> ''
			   AND estado <> 'cancelada' AND id > %d ORDER BY id ASC LIMIT %d",
			$cursor,
			self::LOTE
		) );
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'error buscando suscripciones a sincronizar: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
		}
		$filas = is_array( $filas ) ? $filas : array();
		$cola  = self::cola_fallidos();
		// Primero los que fallaron antes (si siguen vigentes), después el lote de la rotación; sin repetir.
		$trabajo = array();
		foreach ( self::filas_de_la_cola( $cola ) as $f ) {
			$trabajo[ (int) $f->id ] = $f;
		}
		$reintentadas = count( $trabajo );
		foreach ( $filas as $f ) {
			if ( ! isset( $trabajo[ (int) $f->id ] ) ) {
				$trabajo[ (int) $f->id ] = $f;
			}
		}
		$trabajo = array_values( $trabajo );
		$res     = array( 'procesadas' => 0, 'fallos' => 0, 'omitidas_modo_seguro' => 0, 'abortada' => false, 'reintentadas' => $reintentadas, 'en_cola' => 0 );
		$modo_seguro = self::flag( 'cn_sub_modo_seguro', '1' );
		$presupuesto = array( 'requests' => self::TOPE_REQUESTS, 'espera' => self::TOPE_ESPERA_SEG, 'hasta' => time() + self::TOPE_TIEMPO_SEG );
		$ahora       = time();
		// Solo se queda en la cola lo que falle en ESTA corrida (los vigentes que salgan bien se sacan; los que ya no
		// están vigentes —cancelados, sin suscripción— dejan de reintentarse).
		$cola_nueva = array();
		foreach ( $trabajo as $i => $fila ) {
			$id  = (int) $fila->id;
			$pid = (string) $fila->preapproval_id;
			if ( $i > 0 && self::$pausa_us > 0 ) {
				usleep( self::$pausa_us );
			}
			$r    = CN_MP::obtener_preapproval_con_reintentos( $pid, $presupuesto );
			$data = $r['ok'] ? $r['data'] : null;
			if ( ! is_array( $data ) || empty( $data['status'] ) || ! is_string( $data['status'] )
				|| ( isset( $data['id'] ) && (string) $data['id'] !== $pid ) ) {
				// MP falló (tras los reintentos) o no devolvió esta suscripción: no se cambia nada; queda para la próxima corrida.
				$motivo = $r['ok'] ? 'respuesta de MP inválida o de otra suscripción' : $r['motivo'];
				self::marcar_fallido( $cola, $cola_nueva, $id, $pid, $motivo, $r['intentos'], $ahora );
				$res['fallos']++;
				if ( 'tope' === $r['http'] ) {
					// Se acabaron los pedidos/tiempo de la corrida: lo que falta no se pidió; queda en la cola, no se saltea.
					$res['abortada'] = true;
					foreach ( array_slice( $trabajo, $i + 1 ) as $resto ) {
						self::marcar_fallido( $cola, $cola_nueva, (int) $resto->id, (string) $resto->preapproval_id, 'no se llegó: tope de la corrida', 0, $ahora );
						$res['fallos']++;
					}
					break;
				}
				continue;
			}
			$status = $data['status'];
			if ( ! in_array( $status, array( 'authorized', 'cancelled', 'paused' ), true ) ) {
				continue; // pending u otro estado transitorio: nada que sincronizar.
			}
			if ( 'authorized' !== $status && $modo_seguro ) {
				$res['omitidas_modo_seguro']++;
				$clave = 'cn_sync_ms_' . md5( $pid );
				if ( ! get_transient( $clave ) ) {
					set_transient( $clave, 1, DAY_IN_SECONDS );
					CN_Webhook::registrar_log_mp( 'sync_baja_omitida_modo_seguro', array( 'miembro_id' => $id, 'preapproval_id' => $pid, 'status_mp' => $status ) );
				}
				continue;
			}
			CN_Webhook::gestionar_ciclo_sub( $status, $pid, $data ); // misma lógica que el webhook; los errores los registra ella.
			$res['procesadas']++;
		}
		// El cursor avanza SIEMPRE (lote incompleto = se llegó al final: la próxima corrida arranca de nuevo). Lo que falló
		// no se repite por rotación sino por la cola.
		update_option( 'cn_sync_mp_cursor', count( $filas ) < self::LOTE ? 0 : (int) end( $filas )->id );
		$cola_nueva = array_slice( $cola_nueva, 0, self::MAX_COLA, true );
		update_option( self::OPT_FALLIDOS, $cola_nueva );
		$res['en_cola'] = count( $cola_nueva );
		if ( $res['fallos'] || $res['abortada'] ) {
			CN_Webhook::registrar_log_mp( 'sync_mp_lote', $res + array( 'tamano_lote' => count( $filas ) ) );
		}
		if ( $res['abortada'] ) {
			CN_Webhook::sub_avisar_una_vez_por_hora( 'sub_error', 'sync_abortada', array( 'paso' => 'sync_lote', 'mensaje' => 'La corrida llegó a su tope de pedidos/tiempo con Mercado Pago; lo pendiente queda en cola para la próxima, sin cambiar nada.' ) );
		}
		return $res;
	}
	/** Cola de ítems fallidos (id de socia => datos). */
	protected static function cola_fallidos() {
		$c = get_option( self::OPT_FALLIDOS, array() );
		return is_array( $c ) ? $c : array();
	}
	/** Filas (id, preapproval_id) de la cola que siguen vigentes (suscripción vinculada, no cancelada). Hasta LOTE. */
	protected static function filas_de_la_cola( $cola ) {
		global $wpdb;
		if ( ! $cola ) {
			return array();
		}
		$ids = array_map( 'intval', array_slice( array_keys( $cola ), 0, self::LOTE ) );
		$in  = implode( ',', $ids );
		$res = $wpdb->get_results(
			"SELECT id, preapproval_id FROM " . CN_DB::tabla( 'miembros' ) . "
			 WHERE id IN ({$in}) AND preapproval_id IS NOT NULL AND preapproval_id <> '' AND plan IS NOT NULL AND plan <> '' AND estado <> 'cancelada'
			 ORDER BY id ASC"
		);
		return is_array( $res ) ? $res : array();
	}
	/**
	 * Anota un ítem fallido: fila sync_item_fallido en mp_log y entrada en la cola nueva (con contador de corridas
	 * fallidas seguidas, tomado de la cola anterior). Tras MAX_INTENTOS_ITEM corridas sale de la cola y queda un aviso
	 * en el cartel; la rotación normal lo vuelve a tocar en la próxima vuelta.
	 */
	protected static function marcar_fallido( $cola_vieja, &$cola_nueva, $id, $pid, $motivo, $pedidos, $ahora ) {
		$corridas = ( isset( $cola_vieja[ $id ]['intentos'] ) ? (int) $cola_vieja[ $id ]['intentos'] : 0 ) + 1;
		$desde    = isset( $cola_vieja[ $id ]['desde'] ) ? (int) $cola_vieja[ $id ]['desde'] : $ahora;
		CN_Webhook::registrar_log_mp( 'sync_item_fallido', array(
			'miembro_id' => $id, 'preapproval_id' => $pid, 'motivo' => mb_substr( (string) $motivo, 0, 200 ),
			'pedidos' => (int) $pedidos, 'corridas_fallidas' => $corridas, 'se_reintenta' => $corridas < self::MAX_INTENTOS_ITEM,
		) );
		if ( $corridas >= self::MAX_INTENTOS_ITEM ) {
			CN_Webhook::avisar_error_n8n( 'sync_item_fallido_reiterado', array( 'miembro_id' => $id, 'preapproval_id' => $pid, 'corridas' => $corridas, 'motivo' => mb_substr( (string) $motivo, 0, 200 ) ) );
			return;
		}
		$cola_nueva[ $id ] = array( 'preapproval_id' => $pid, 'intentos' => $corridas, 'desde' => $desde );
	}
	/**
	 * Suscripciones authorized en MP que ninguna socia tiene vinculadas y que tienen más de 1 hora de creadas:
	 * UN aviso a n8n con la propuesta de cada una (la misma lógica de CN_Vinculo::proponer: nunca se auto-vincula).
	 * Cada suscripción se avisa como máximo 1 vez por día (opción cn_sync_alertados). Si MP no responde la
	 * lista completa no se concluye nada.
	 *
	 * @return array{avisadas:int,pendientes:int}|null null si no hubo nada para hacer o MP falló
	 */
	public static function alertar_sin_vincular() {
		global $wpdb;
		if ( '' === CN_MP::get_access_token() ) {
			return null;
		}
		$lista = CN_Vinculo::listar_authorized();
		if ( ! $lista['ok'] ) {
			return null; // lista incompleta o MP caído: no se puede decir qué está "sin vincular".
		}
		$t_miembros = CN_DB::tabla( 'miembros' );
		$socias     = $wpdb->get_results( "SELECT id, nombre_apellido, estado, email, celular_hint, preapproval_id, fecha_fin_trial FROM {$t_miembros}" );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $socias ) ) {
			return null;
		}
		$indice  = CN_Vinculo::indice_socias( $socias );
		$ahora   = time();
		$previos = get_option( 'cn_sync_alertados', array() );
		$previos = is_array( $previos ) ? $previos : array();
		$candidatas = array();
		foreach ( $lista['items'] as $item ) {
			$id = isset( $item['id'] ) ? (string) $item['id'] : '';
			if ( '' === $id || isset( $indice['por_preapproval'][ $id ] ) ) {
				continue;
			}
			$creada = isset( $item['date_created'] ) ? strtotime( (string) $item['date_created'] ) : false;
			if ( false !== $creada && $creada > $ahora - self::MIN_SIN_VINCULAR ) {
				continue; // todavía no pasó 1 hora: puede que el alta automática la vincule.
			}
			if ( isset( $previos[ $id ] ) && (int) $previos[ $id ] > $ahora - self::REAVISO_SEG ) {
				continue; // ya se avisó hoy.
			}
			$candidatas[] = $item;
		}
		// Se poda lo avisado hace más de 7 días (las suscripciones que ya no están sin vincular).
		foreach ( $previos as $k => $ts ) {
			if ( (int) $ts < $ahora - 7 * DAY_IN_SECONDS ) {
				unset( $previos[ $k ] );
			}
		}
		if ( ! $candidatas ) {
			update_option( 'cn_sync_alertados', $previos );
			return array( 'avisadas' => 0, 'pendientes' => 0 );
		}
		$prop  = CN_Vinculo::proponer( $candidatas, $socias, CN_Vinculo::cargar_pagos_aprobados( 40, 3 ) );
		$items = array();
		foreach ( array_slice( $prop['filas'], 0, self::MAX_ITEMS_ALERTA ) as $f ) {
			$items[] = array(
				'preapproval_id' => $f['id'],
				'plan'           => $f['plan'],
				'monto'          => $f['monto'],
				'proximo_cobro'  => $f['proximo_cobro'],
				'email'          => $f['email'],
				'confianza'      => $f['confianza'],
				'socia_id'       => $f['socia'] ? (int) $f['socia']->id : null,
				'socia_nombre'   => $f['socia'] ? (string) $f['socia']->nombre_apellido : null,
				'candidatas'     => array_map( function ( $c ) {
					return (int) $c->id;
				}, $f['candidatas'] ),
			);
		}
		CN_Webhook::avisar_error_n8n( 'sub_sin_vincular', array(
			'cantidad'       => count( $prop['filas'] ),
			'items'          => $items,
			'tope_consultas' => (bool) $prop['tope_alcanzado'],
			'aviso'          => 'Suscripciones authorized en Mercado Pago sin vincular hace más de 1 hora. No se vinculó nada: revisalas en Club Natureza → Cruce con Mercado Pago.',
		) );
		CN_Webhook::registrar_log_mp( 'sub_sin_vincular_avisada', array( 'cantidad' => count( $prop['filas'] ), 'ids' => array_column( $items, 'preapproval_id' ) ) );
		foreach ( $prop['filas'] as $f ) {
			$previos[ $f['id'] ] = $ahora;
		}
		update_option( 'cn_sync_alertados', $previos );
		return array( 'avisadas' => count( $prop['filas'] ), 'pendientes' => count( $candidatas ) );
	}
	// ======================================================================
	// PUNTO 3 — GUARDA DEL CRON DE TRIALS
	// ======================================================================
	/** ¿Está activada la guarda? (cn_guarda_trial_mp, activada por defecto). */
	public static function guarda_trial_activa() {
		return self::flag( 'cn_guarda_trial_mp', '1' );
	}
	/** ¿La guarda solo registra su decisión sin actuar? (cn_guarda_trial_solo_log, '1' por defecto.) */
	public static function guarda_trial_solo_log() {
		return self::flag( 'cn_guarda_trial_solo_log', '1' );
	}
	/**
	 * Antes de cancelar a alguien con trial vencido y sin preapproval_id: ¿tiene una suscripción authorized en MP?
	 * Todo GET (solo lectura). La búsqueda de cobros por email (/v1/payments/search?payer.email) la rechaza MP con 400,
	 * así que se cruza por el id del pagador:
	 *  1. GET /v1/payments/{trial_payment_id} -> payer.id.
	 *  2. GET /preapproval/search?payer_id={id} -> alguna con status authorized y ese mismo payer_id.
	 * Resultado:
	 *  - 'pago':          hay suscripción authorized de ese pagador (el cron, con la guarda actuando, NO cancela).
	 *  - 'sin_pago':      MP respondió bien y ese pagador no tiene ninguna authorized. OJO: no cubre a quien pagó con otra
	 *                     cuenta de MP distinta de la del trial; por eso la guarda arranca en modo solo-log.
	 *  - 'indeterminado': no hay forma confiable de decidir (sin trial_payment_id, el cobro no trae payer.id, más
	 *                     resultados que una página). NUNCA se adivina: el cron no cancela.
	 *  - 'error':         MP falló en alguna consulta (el cron no cancela y reintenta en la próxima corrida).
	 *
	 * @param object $miembro fila con id, trial_payment_id
	 * @return array{resultado:string,via:string,monto:?float,fecha:string,detalle:string,motivo:string,payer_id:string}
	 */
	public static function guarda_trial( $miembro ) {
		$out = array( 'resultado' => 'error', 'via' => '', 'monto' => null, 'fecha' => '', 'detalle' => '', 'motivo' => '', 'payer_id' => '' );
		$tid = trim( (string) ( isset( $miembro->trial_payment_id ) ? $miembro->trial_payment_id : '' ) );
		if ( '' === $tid ) {
			$out['resultado'] = 'indeterminado';
			$out['motivo']    = $out['detalle'] = 'la socia no tiene trial_payment_id: no hay cobro del cual sacar el payer.id';
			return $out;
		}
		$pago = CN_MP::obtener_pago_para_guarda( $tid );
		if ( ! is_array( $pago ) ) {
			$out['motivo'] = $out['detalle'] = 'no se pudo consultar el cobro del trial en Mercado Pago';
			return $out;
		}
		$payer = isset( $pago['payer']['id'] ) ? (string) $pago['payer']['id'] : '';
		if ( '' === $payer || ! ctype_digit( $payer ) || (int) $payer <= 0 ) {
			$out['resultado'] = 'indeterminado';
			$out['motivo']    = $out['detalle'] = 'el cobro del trial no trae un payer.id usable';
			return $out;
		}
		$out['payer_id'] = $payer;
		$subs = CN_MP::buscar_preapprovals_por_payer_id( $payer );
		if ( ! is_array( $subs ) || ! isset( $subs['results'] ) || ! is_array( $subs['results'] ) ) {
			$out['motivo'] = $out['detalle'] = 'no se pudo consultar las suscripciones del pagador en Mercado Pago';
			return $out;
		}
		$total = isset( $subs['paging']['total'] ) && is_numeric( $subs['paging']['total'] ) ? (int) $subs['paging']['total'] : count( $subs['results'] );
		$sub   = self::suscripcion_authorized_de_payer( $subs['results'], $payer );
		if ( null !== $sub ) {
			return array( 'resultado' => 'pago', 'via' => 'suscripcion', 'monto' => $sub['monto'], 'fecha' => $sub['fecha'], 'detalle' => 'suscripcion ' . $sub['id'], 'motivo' => 'suscripción authorized del payer_id ' . $payer, 'payer_id' => $payer );
		}
		if ( $total > count( $subs['results'] ) ) {
			$out['resultado'] = 'indeterminado';
			$out['motivo']    = $out['detalle'] = 'el pagador tiene más suscripciones que las que devolvió la página de MP';
			return $out;
		}
		$out['resultado'] = 'sin_pago';
		$out['motivo']    = 'ninguna suscripción authorized para el payer_id ' . $payer;
		return $out;
	}
	/**
	 * Suscripción authorized cuyo payer_id es EXACTAMENTE ese (puro): no se confía en el filtro de MP.
	 *
	 * @return array{id:string,monto:?float,fecha:string}|null
	 */
	public static function suscripcion_authorized_de_payer( $subs, $payer_id ) {
		foreach ( (array) $subs as $s ) {
			if ( ! is_array( $s ) || ( $s['status'] ?? '' ) !== 'authorized' || (string) ( $s['payer_id'] ?? '' ) !== (string) $payer_id ) {
				continue;
			}
			return array(
				'id'    => (string) ( $s['id'] ?? '' ),
				'monto' => isset( $s['auto_recurring']['transaction_amount'] ) ? (float) $s['auto_recurring']['transaction_amount'] : null,
				'fecha' => isset( $s['date_created'] ) ? substr( (string) $s['date_created'], 0, 10 ) : '',
			);
		}
		return null;
	}
	/**
	 * Momento (timestamp) del cobro del trial: el pago con ese trial_payment_id si está en la lista; si no, el fin
	 * del trial menos TRIAL_DIAS días. Los cobros "posteriores" se miden contra este momento.
	 */
	public static function referencia_trial( $pagos, $miembro ) {
		$trial_id = (string) ( isset( $miembro->trial_payment_id ) ? $miembro->trial_payment_id : '' );
		if ( '' !== $trial_id ) {
			foreach ( $pagos as $p ) {
				if ( is_array( $p ) && isset( $p['id'] ) && (string) $p['id'] === $trial_id && ! empty( $p['date_created'] ) ) {
					$ts = strtotime( (string) $p['date_created'] );
					if ( false !== $ts ) {
						return $ts;
					}
				}
			}
		}
		$fin = isset( $miembro->fecha_fin_trial ) ? strtotime( (string) $miembro->fecha_fin_trial . ' UTC' ) : false;
		return false !== $fin ? $fin - CN_Webhook::TRIAL_DIAS * DAY_IN_SECONDS : 0;
	}
	/**
	 * Primer cobro APROBADO, posterior a $ref_ts, de monto >= MONTO_MIN_PLAN y que no sea el del trial (puro).
	 *
	 * @return array{id:string,monto:float,fecha:string}|null
	 */
	public static function cobro_posterior( $pagos, $ref_ts, $trial_payment_id = '' ) {
		foreach ( (array) $pagos as $p ) {
			if ( ! is_array( $p ) || ( isset( $p['status'] ) && 'approved' !== $p['status'] ) ) {
				continue;
			}
			$monto = isset( $p['transaction_amount'] ) && is_numeric( $p['transaction_amount'] ) ? (float) $p['transaction_amount'] : 0.0;
			$ts    = isset( $p['date_created'] ) ? strtotime( (string) $p['date_created'] ) : false;
			$id    = isset( $p['id'] ) ? (string) $p['id'] : '';
			if ( $monto < self::MONTO_MIN_PLAN || false === $ts || $ts <= $ref_ts || ( '' !== $trial_payment_id && $id === $trial_payment_id ) ) {
				continue;
			}
			return array( 'id' => $id, 'monto' => $monto, 'fecha' => gmdate( 'Y-m-d H:i:s', $ts ) );
		}
		return null;
	}
	/**
	 * Suscripción authorized cuyo payer_email es EXACTAMENTE ese email (puro): no se confía en el filtro de MP.
	 *
	 * @return array{id:string,monto:?float,fecha:string}|null
	 */
	public static function suscripcion_authorized_de( $subs, $email ) {
		foreach ( (array) $subs as $s ) {
			if ( ! is_array( $s ) || ( $s['status'] ?? '' ) !== 'authorized' ) {
				continue;
			}
			if ( CN_Vinculo::email_normalizado( $s['payer_email'] ?? '' ) !== $email ) {
				continue;
			}
			return array(
				'id'    => (string) ( $s['id'] ?? '' ),
				'monto' => isset( $s['auto_recurring']['transaction_amount'] ) ? (float) $s['auto_recurring']['transaction_amount'] : null,
				'fecha' => isset( $s['date_created'] ) ? substr( (string) $s['date_created'], 0, 10 ) : '',
			);
		}
		return null;
	}
}
