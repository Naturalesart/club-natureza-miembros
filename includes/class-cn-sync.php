<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Fase 5: sincronización con Mercado Pago SIN depender de webhooks, y guarda del cron de trials.
 *
 *  - correr(): cron cada 30 min. (a) Lote de hasta LOTE suscripciones ya vinculadas: consulta GET /preapproval/{id}
 *    y aplica el MISMO CN_Webhook::gestionar_ciclo_sub() que el webhook. (b) Aviso a n8n de suscripciones
 *    authorized en MP sin vincular hace más de 1 hora. NUNCA vincula nada solo.
 *  - guarda_trial(): antes de cancelar un trial vencido sin suscripción, mira si pagó por link directo.
 *
 * Regla común: si MP falla (red, 4xx/5xx, respuesta rara) NO se cambia el estado de nadie; se reintenta en la
 * próxima corrida.
 */
class CN_Sync {
	const LOTE             = 20;       // suscripciones por corrida
	const FALLOS_ABORTAR   = 3;        // fallos seguidos de MP que cortan la corrida
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
		delete_transient( 'cn_sync_mp_lock' );
	}
	/**
	 * Lote de hasta LOTE filas con preapproval_id y plan no nulos (las que vinculó el sistema), no canceladas,
	 * recorridas en rotación por id (la opción cn_sync_mp_cursor guarda por dónde iba). Por cada una:
	 * GET /preapproval/{id} (con pausa entre llamadas) y gestionar_ciclo_sub() con el estado de MP.
	 *  - authorized: limpia baja_en o extiende acceso_hasta con next_payment_date.
	 *  - cancelled / paused: programa baja_en sin cortar el acceso. Con cn_sub_modo_seguro = '1' (el valor por
	 *    defecto) NO se programa nada: solo queda un rastro en el log (como máximo 1 por día y suscripción).
	 * Si MP falla o devuelve algo inválido para una fila, esa fila no se toca. Tras FALLOS_ABORTAR fallos seguidos
	 * se corta la corrida (MP está caído: no tiene sentido seguir golpeándolo).
	 *
	 * @return array{procesadas:int,fallos:int,omitidas_modo_seguro:int,abortada:bool}
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
		$res   = array( 'procesadas' => 0, 'fallos' => 0, 'omitidas_modo_seguro' => 0, 'abortada' => false );
		$modo_seguro = self::flag( 'cn_sub_modo_seguro', '1' );
		$seguidos    = 0;
		foreach ( $filas as $i => $fila ) {
			if ( $i > 0 && self::$pausa_us > 0 ) {
				usleep( self::$pausa_us );
			}
			$pid  = (string) $fila->preapproval_id;
			$data = CN_MP::obtener_preapproval( $pid );
			if ( ! is_array( $data ) || empty( $data['status'] ) || ! is_string( $data['status'] )
				|| ( isset( $data['id'] ) && (string) $data['id'] !== $pid ) ) {
				// MP falló o no devolvió esta suscripción: no se cambia nada (CN_MP::get ya dejó el rastro del error).
				$res['fallos']++;
				if ( ++$seguidos >= self::FALLOS_ABORTAR ) {
					$res['abortada'] = true;
					break;
				}
				continue;
			}
			$seguidos = 0;
			$status   = $data['status'];
			if ( ! in_array( $status, array( 'authorized', 'cancelled', 'paused' ), true ) ) {
				continue; // pending u otro estado transitorio: nada que sincronizar.
			}
			if ( 'authorized' !== $status && $modo_seguro ) {
				$res['omitidas_modo_seguro']++;
				$clave = 'cn_sync_ms_' . md5( $pid );
				if ( ! get_transient( $clave ) ) {
					set_transient( $clave, 1, DAY_IN_SECONDS );
					CN_Webhook::registrar_log_mp( 'sync_baja_omitida_modo_seguro', array( 'miembro_id' => (int) $fila->id, 'preapproval_id' => $pid, 'status_mp' => $status ) );
				}
				continue;
			}
			CN_Webhook::gestionar_ciclo_sub( $status, $pid, $data ); // misma lógica que el webhook; los errores los registra ella.
			$res['procesadas']++;
		}
		// Avanza el cursor (lote incompleto = se llegó al final: la próxima corrida arranca de nuevo). Si se abortó por
		// fallos de MP no avanza: la próxima corrida reintenta el mismo lote.
		if ( ! $res['abortada'] ) {
			update_option( 'cn_sync_mp_cursor', count( $filas ) < self::LOTE ? 0 : (int) end( $filas )->id );
		}
		if ( $res['fallos'] || $res['abortada'] ) {
			CN_Webhook::registrar_log_mp( 'sync_mp_lote', $res + array( 'tamano_lote' => count( $filas ) ) );
		}
		if ( $res['abortada'] ) {
			CN_Webhook::sub_avisar_una_vez_por_hora( 'sub_error', 'sync_abortada', array( 'paso' => 'sync_lote', 'mensaje' => 'Mercado Pago falló ' . self::FALLOS_ABORTAR . ' veces seguidas; se cortó la corrida sin cambiar nada.' ) );
		}
		return $res;
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
	/**
	 * Antes de cancelar a alguien con trial vencido y sin preapproval_id: ¿pagó por fuera del trial?
	 *  1. Cobro aprobado de ese email, POSTERIOR al de $7.000 del trial, de monto >= $15.500.
	 *  2. Si no, suscripción authorized de ese email en MP.
	 * Resultado:
	 *  - 'pago':     encontró pago o suscripción (el cron NO cancela).
	 *  - 'sin_pago': MP respondió bien y no hay nada (el cron cancela como siempre).
	 *  - 'error':    MP falló en cualquiera de las consultas (el cron NO cancela y reintenta en la próxima corrida).
	 *  - 'sin_email': la socia no tiene un email usable; no se puede verificar (el cron sigue como siempre).
	 *
	 * @param object $miembro fila con id, email, fecha_fin_trial, trial_payment_id
	 * @return array{resultado:string,via:string,monto:?float,fecha:string,detalle:string}
	 */
	public static function guarda_trial( $miembro ) {
		$out   = array( 'resultado' => 'error', 'via' => '', 'monto' => null, 'fecha' => '', 'detalle' => '' );
		$email = CN_Vinculo::email_normalizado( isset( $miembro->email ) ? $miembro->email : '' );
		if ( '' === $email ) {
			$out['resultado'] = 'sin_email';
			return $out;
		}
		$pagos = CN_MP::buscar_pagos_aprobados_por_email( $email );
		if ( ! is_array( $pagos ) || ! isset( $pagos['results'] ) || ! is_array( $pagos['results'] ) ) {
			$out['detalle'] = 'no se pudo consultar los cobros en Mercado Pago';
			return $out;
		}
		$ref_ts = self::referencia_trial( $pagos['results'], $miembro );
		$cobro  = self::cobro_posterior( $pagos['results'], $ref_ts, (string) ( isset( $miembro->trial_payment_id ) ? $miembro->trial_payment_id : '' ) );
		if ( null !== $cobro ) {
			return array( 'resultado' => 'pago', 'via' => 'cobro', 'monto' => $cobro['monto'], 'fecha' => $cobro['fecha'], 'detalle' => 'cobro aprobado ' . $cobro['id'] );
		}
		$subs = CN_MP::buscar_preapprovals_por_email( $email );
		if ( ! is_array( $subs ) || ! isset( $subs['results'] ) || ! is_array( $subs['results'] ) ) {
			$out['detalle'] = 'no se pudo consultar las suscripciones en Mercado Pago';
			return $out;
		}
		$sub = self::suscripcion_authorized_de( $subs['results'], $email );
		if ( null !== $sub ) {
			return array( 'resultado' => 'pago', 'via' => 'suscripcion', 'monto' => $sub['monto'], 'fecha' => $sub['fecha'], 'detalle' => 'suscripcion ' . $sub['id'] );
		}
		$out['resultado'] = 'sin_pago';
		return $out;
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
