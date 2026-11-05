<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Auto-vinculación por candidata única: una suscripción pagada por el link común del plan llega sin email, celular ni
 * referencia y hoy queda "sin vincular" hasta que una persona la une a mano. Esta clase corre después del sync (mismo
 * hook cn_cron_sync_mp, prioridad 20) y, SOLO cuando hay exactamente UNA ficha candidata segura, la vincula reutilizando
 * CN_Webhook::vincular_suscripcion() — el MISMO camino de la vinculación manual (transacción, FOR UPDATE, trial →
 * suscripta con fecha_fin_trial = null, rastro sub_vinculada_admin) — con notificar = false: sin mail y sin evento Meta.
 *
 * PRIORIDAD ABSOLUTA: nunca vincular a la persona equivocada. Ante cualquier duda, no vincula y alerta.
 * Candidata válida SOLO si cumple TODO:
 *  - Ficha sin suscripción de MP vinculada (preapproval_id vacío); una ficha ya suscripta jamás se toca.
 *  - Alta (o última modificación) dentro de los 30 días previos al pago, y alta NUNCA posterior al pago.
 *  - El nombre del pagador de MP coincide con el de la ficha tras normalizar (minúsculas, sin tildes ni puntuación,
 *    espacios simples), comparados como CONJUNTO de palabras sin importar el orden, con mínimo 2 palabras de cada
 *    lado (un solo nombre de pila no alcanza) — o el email de MP coincide EXACTO con el de la ficha (coincidencia
 *    fuerte; igual exige candidata única).
 * Con 0 o 2+ candidatas: no vincula; alerta con la lista enmascarada (id de ficha, nombre enmascarado, últimos 4).
 * Tope: máximo 3 acciones por corrida; lo que sobra queda alertado sin vincular.
 *
 * Modos (opción cn_autovincular_modo, 'off' por defecto): off = no hace nada (ni una consulta); solo_alertar = loguea
 * "vincularía a la ficha X" sin escribir; on = vincula. Cada decisión deja un rastro autovincular_* en mp_log, con el
 * estado previo de la ficha (estado, plan, acceso_hasta, fecha_fin_trial) para poder revertir a mano.
 *
 * Mercado Pago: solo GET (la lista de authorized y los cobros que ya usa el aviso de "sin vincular"). Sin tablas ni
 * columnas nuevas. Convive con el PR #36 (sync cada 5 min): corre a lo sumo una vez cada ESPACIADO_SEG aunque el cron
 * dispare más seguido, con su propio lock.
 */
class CN_Autovincular {
	const OPT_MODO       = 'cn_autovincular_modo';     // off | solo_alertar | on
	const OPT_ULTIMA     = 'cn_autovincular_ultima';   // timestamp de la última corrida real (espaciado)
	const OPT_AVISADOS   = 'cn_autovincular_avisados'; // 'motivo|preapproval' => timestamp (máx. 1 alerta por día y caso)
	const LOCK           = 'cn_autovincular_lock';
	const ESPACIADO_SEG  = 1500;  // corre a lo sumo cada 25 min, aunque el cron del sync sea más frecuente (#36)
	const MIN_EDAD_SEG   = 3600;  // 1 h: antes de eso el alta automática normal (webhook / external_reference) puede resolverla sola
	const VENTANA_DIAS   = 30;    // actividad de la ficha dentro de los 30 días previos al pago
	const TOPE_ACCIONES  = 3;     // vinculaciones (o simulaciones) por corrida; el resto se alerta sin vincular
	const TOPE_EMAIL     = 10;    // consultas extra a MP para resolver emails (CN_Vinculo::resolver_email)
	const MAX_SUBS       = 20;    // suscripciones evaluadas por corrida
	const REAVISO_SEG    = 86400; // una alerta por día por (motivo, suscripción)
	const PODA_SEG       = 604800;

	public static function registrar() {
		add_action( 'cn_cron_sync_mp', array( __CLASS__, 'correr' ), 20 ); // después de CN_Sync::correr (prioridad 10).
	}

	/** Modo actual; cualquier valor raro cuenta como off. */
	public static function modo() {
		$m = (string) get_option( self::OPT_MODO, 'off' );
		return in_array( $m, array( 'solo_alertar', 'on' ), true ) ? $m : 'off';
	}

	// ====================================================================== nombre
	/**
	 * Palabras del nombre, normalizadas con la MISMA receta de CN_Vinculo::nombre_normalizado (sin tildes, minúsculas,
	 * sin puntuación, espacios simples), sin repetidas y ordenadas (puro).
	 */
	public static function palabras_nombre( $nombre ) {
		$n = CN_Vinculo::nombre_normalizado( $nombre );
		if ( '' === $n ) {
			return array();
		}
		$palabras = array_values( array_unique( explode( ' ', $n ) ) );
		sort( $palabras );
		return $palabras;
	}

	/**
	 * ¿Los dos nombres tienen EXACTAMENTE el mismo conjunto de palabras (sin importar el orden), con mínimo 2 palabras
	 * de cada lado? "ana lopez" = "López, Ana"; "ana" nunca alcanza; "ana lopez" ≠ "ana maria lopez" (puro).
	 */
	public static function nombres_coinciden( $a, $b ) {
		$pa = self::palabras_nombre( $a );
		$pb = self::palabras_nombre( $b );
		return count( $pa ) >= 2 && count( $pb ) >= 2 && $pa === $pb;
	}

	/** Nombre enmascarado para alertas y logs: primera letra de cada palabra + ***. */
	public static function mascara_nombre( $nombre ) {
		$palabras = array_filter( explode( ' ', CN_Vinculo::nombre_normalizado( $nombre ) ) );
		if ( ! $palabras ) {
			return '***';
		}
		return implode( ' ', array_map( function ( $p ) {
			return strtoupper( substr( $p, 0, 1 ) ) . '***';
		}, $palabras ) );
	}

	// ====================================================================== candidatas
	/**
	 * Fichas candidatas para una suscripción (puro, solo lectura). $pago_ts = date_created de la suscripción en MP.
	 *
	 * @param array  $item   suscripción de MP (payer_first_name, payer_last_name, ...)
	 * @param string $email  email del pagador ya resuelto ('' si no hay)
	 * @param array  $socias filas con id, nombre_apellido, estado, preapproval_id, plan, acceso_hasta, email,
	 *                       celular_hint, fecha_alta, fecha_fin_trial, fecha_modificacion
	 * @return array lista de array{socia:object,por:string} (por: nombre | email | nombre y email)
	 */
	public static function candidatas( $item, $email, $socias, $pago_ts ) {
		$nombre_mp = trim( (string) ( $item['payer_first_name'] ?? '' ) . ' ' . (string) ( $item['payer_last_name'] ?? '' ) );
		$email     = CN_Vinculo::email_normalizado( $email );
		$pago_ts   = (int) $pago_ts;
		$desde     = $pago_ts - self::VENTANA_DIAS * DAY_IN_SECONDS;
		$out       = array();
		foreach ( (array) $socias as $s ) {
			if ( '' !== trim( (string) ( $s->preapproval_id ?? '' ) ) ) {
				continue; // ya tiene una suscripción vinculada: jamás se toca.
			}
			$alta = strtotime( (string) ( $s->fecha_alta ?? '' ) . ' UTC' );
			if ( false === $alta || $alta > $pago_ts ) {
				continue; // sin fecha de alta usable, o ficha creada DESPUÉS del pago: nunca.
			}
			// Actividad que cuenta: el alta o la última modificación, siempre que no sea posterior al pago.
			$actividad = $alta;
			$mod       = strtotime( (string) ( $s->fecha_modificacion ?? '' ) . ' UTC' );
			if ( false !== $mod && $mod <= $pago_ts && $mod > $actividad ) {
				$actividad = $mod;
			}
			if ( $actividad < $desde ) {
				continue; // sin actividad en los 30 días previos al pago.
			}
			$por_nombre = self::nombres_coinciden( $nombre_mp, (string) ( $s->nombre_apellido ?? '' ) );
			$por_email  = '' !== $email && $email === CN_Vinculo::email_normalizado( $s->email ?? '' );
			if ( ! $por_nombre && ! $por_email ) {
				continue;
			}
			$out[] = array(
				'socia' => $s,
				'por'   => trim( ( $por_nombre ? 'nombre' : '' ) . ( $por_nombre && $por_email ? ' y ' : '' ) . ( $por_email ? 'email' : '' ) ),
			);
		}
		return $out;
	}

	/** Candidata en versión enmascarada para alertas y logs (sin nombre completo, celular ni email). */
	protected static function enmascarar( $c ) {
		$s = $c['socia'];
		return array(
			'ficha_id' => (int) $s->id,
			'nombre'   => self::mascara_nombre( (string) $s->nombre_apellido ),
			'celular'  => CN_Vinculo::mascara_celular( (string) ( $s->celular_hint ?? '' ) ),
			'estado'   => (string) ( $s->estado ?? '' ),
			'por'      => (string) $c['por'],
		);
	}

	// ====================================================================== corrida
	/** Entrada del cron (prioridad 20 del mismo hook del sync). Nunca lanza. */
	public static function correr() {
		if ( 'off' === self::modo() ) {
			return null; // apagado: ni una consulta.
		}
		if ( get_transient( self::LOCK ) ) {
			return null;
		}
		if ( time() - (int) get_option( self::OPT_ULTIMA, 0 ) < self::ESPACIADO_SEG ) {
			return null; // con el sync cada 5 min (#36), igual corre a lo sumo cada 25 min.
		}
		set_transient( self::LOCK, 1, 600 );
		update_option( self::OPT_ULTIMA, time(), false ); // antes de procesar: un error no hace martillar a MP.
		$res = null;
		try {
			$res = self::procesar();
		} catch ( Throwable $e ) {
			CN_Webhook::registrar_log_mp( 'autovincular_error', array( 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
		}
		delete_transient( self::LOCK );
		return $res;
	}

	/**
	 * Una corrida completa. Devuelve un resumen (para tests y para el log).
	 *
	 * @return array{modo:string,motivo:string,evaluadas:int,vinculadas:int,simuladas:int,sin_candidata:int,ambiguas:int,tope:int}
	 */
	public static function procesar() {
		global $wpdb;
		$modo = self::modo();
		$res  = array( 'modo' => $modo, 'motivo' => '', 'evaluadas' => 0, 'vinculadas' => 0, 'simuladas' => 0, 'sin_candidata' => 0, 'ambiguas' => 0, 'tope' => 0 );
		if ( 'off' === $modo ) {
			$res['motivo'] = 'off'; // defensa en profundidad: correr() ya corta antes, pero procesar() solo tampoco actúa.
			return $res;
		}
		if ( '' === CN_MP::get_access_token() ) {
			$res['motivo'] = 'sin_token';
			return $res;
		}
		$lista = CN_Vinculo::listar_authorized();
		if ( ! $lista['ok'] ) {
			$res['motivo'] = 'mp_lista'; // MP falló: no se concluye nada; se reintenta en la próxima corrida.
			return $res;
		}
		$socias = $wpdb->get_results(
			'SELECT id, nombre_apellido, estado, preapproval_id, plan, acceso_hasta, email, celular_hint, fecha_alta, fecha_fin_trial, fecha_modificacion FROM ' . CN_DB::tabla( 'miembros' )
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $socias ) ) {
			$res['motivo'] = 'db';
			return $res;
		}
		$indice    = CN_Vinculo::indice_socias( $socias );
		$idx_pagos = CN_Vinculo::indexar_pagos( CN_Vinculo::cargar_pagos_aprobados( 40, 3 ) );
		$ahora     = time();
		$extra     = 0;
		$acciones  = 0;
		// De la más vieja a la más nueva: las que más esperaron, primero.
		$items = array_filter( (array) $lista['items'], 'is_array' );
		usort( $items, function ( $a, $b ) {
			return strcmp( (string) ( $a['date_created'] ?? '' ), (string) ( $b['date_created'] ?? '' ) );
		} );
		foreach ( $items as $item ) {
			$id = isset( $item['id'] ) && is_string( $item['id'] ) ? $item['id'] : '';
			if ( '' === $id || isset( $indice['por_preapproval'][ $id ] ) || ( $item['status'] ?? '' ) !== 'authorized' ) {
				continue; // sin id, ya vinculada, o no autorizada.
			}
			$pago_ts = strtotime( (string) ( $item['date_created'] ?? '' ) );
			if ( false === $pago_ts || $pago_ts > $ahora - self::MIN_EDAD_SEG ) {
				continue; // sin fecha, o con menos de 1 h: el alta automática normal todavía puede resolverla.
			}
			if ( null === CN_Webhook::plan_por_monto( $item['auto_recurring']['transaction_amount'] ?? null ) ) {
				continue; // monto desconocido: la resuelve una persona (el aviso sub_sin_vincular ya existe).
			}
			if ( $res['evaluadas'] >= self::MAX_SUBS ) {
				break;
			}
			$res['evaluadas']++;
			$re   = CN_Vinculo::resolver_email( $item, $idx_pagos, $extra, self::TOPE_EMAIL );
			$cand = self::candidatas( $item, $re['email'], $socias, $pago_ts );
			if ( 1 !== count( $cand ) ) {
				$motivo = $cand ? 'autovincular_ambigua' : 'autovincular_sin_candidata';
				$res[ $cand ? 'ambiguas' : 'sin_candidata' ]++;
				self::alertar( $motivo, $id, array(
					'candidatas' => array_map( array( __CLASS__, 'enmascarar' ), $cand ),
					'detalle'    => $cand
						? count( $cand ) . ' fichas posibles: no se vincula nada, decide una persona (Socias → Vincular suscripción).'
						: 'Ninguna ficha segura (nombre con 2+ palabras igual al del pagador, o email exacto, con actividad en los 30 días previos): decide una persona.',
				) );
				continue;
			}
			if ( $acciones >= self::TOPE_ACCIONES ) {
				$res['tope']++;
				self::alertar( 'autovincular_tope', $id, array(
					'candidatas' => array( self::enmascarar( $cand[0] ) ),
					'detalle'    => 'Había candidata única pero la corrida ya hizo ' . self::TOPE_ACCIONES . ' vinculaciones: queda para la próxima (o a mano).',
				) );
				continue;
			}
			$acciones++;
			$c     = $cand[0]['socia'];
			$base  = array(
				'preapproval_id' => $id,
				'ficha_id'       => (int) $c->id,
				'match'          => (string) $cand[0]['por'],
				'nombre'         => self::mascara_nombre( (string) $c->nombre_apellido ),
				// Estado previo COMPLETO de la ficha, para revertir a mano si hiciera falta.
				'previo'         => array(
					'estado'          => (string) ( $c->estado ?? '' ),
					'plan'            => null !== ( $c->plan ?? null ) ? (string) $c->plan : null,
					'acceso_hasta'    => null !== ( $c->acceso_hasta ?? null ) ? (string) $c->acceso_hasta : null,
					'fecha_fin_trial' => null !== ( $c->fecha_fin_trial ?? null ) ? (string) $c->fecha_fin_trial : null,
				),
			);
			if ( 'solo_alertar' === $modo ) {
				$res['simuladas']++;
				CN_Webhook::registrar_log_mp( 'autovincular_simulada', $base + array( 'detalle' => 'Vincularía la suscripción a la ficha ' . (int) $c->id . ' (modo solo_alertar: no se escribió nada).' ) );
				continue;
			}
			// Modo on: el MISMO camino que la vinculación manual (revalida TODO adentro: MP, FOR UPDATE, conflictos).
			// notificar = false: sin mail de bienvenida, sin evento Meta, sin cuenta.
			$r = CN_Webhook::vincular_suscripcion( $id, (int) $c->id, false );
			if ( ! empty( $r['ok'] ) && in_array( $r['accion'], array( 'activada', 'completada' ), true ) ) {
				$res['vinculadas']++;
				CN_Webhook::registrar_log_mp( 'autovincular_vinculada', $base + array( 'accion' => (string) $r['accion'], 'plan' => $r['plan'], 'estado_nuevo' => (string) $r['estado_nuevo'] ) );
			} else {
				// La revalidación del camino manual la rechazó (carrera, conflicto): no se tocó nada.
				CN_Webhook::registrar_log_mp( 'autovincular_rechazada', $base + array( 'accion' => (string) $r['accion'], 'mensaje' => mb_substr( (string) $r['mensaje'], 0, 200 ) ) );
			}
		}
		return $res;
	}

	/** Alerta (mp_log + cartel vía avisar_error_n8n), como máximo 1 por día por (motivo, suscripción). Sin datos completos. */
	protected static function alertar( $motivo, $preapproval_id, $detalle ) {
		$avisados = get_option( self::OPT_AVISADOS, array() );
		$avisados = is_array( $avisados ) ? $avisados : array();
		$ahora    = time();
		foreach ( $avisados as $k => $ts ) {
			if ( (int) $ts < $ahora - self::PODA_SEG ) {
				unset( $avisados[ $k ] );
			}
		}
		$clave = $motivo . '|' . $preapproval_id;
		if ( isset( $avisados[ $clave ] ) && (int) $avisados[ $clave ] > $ahora - self::REAVISO_SEG ) {
			update_option( self::OPT_AVISADOS, $avisados, false );
			return;
		}
		$avisados[ $clave ] = $ahora;
		update_option( self::OPT_AVISADOS, $avisados, false );
		CN_Webhook::registrar_log_mp( $motivo, array( 'preapproval_id' => $preapproval_id ) + $detalle );
		CN_Webhook::avisar_error_n8n( $motivo, array( 'preapproval_id' => $preapproval_id ) + $detalle );
	}
}
