<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * D1 — Conciliación diaria Mercado Pago <-> fichas. La llama el cron diario de alertas (CN_Alertas::chequeo_diario).
 * Solo SELECT en la base y solo GET a Mercado Pago (CN_MP::get_con_reintentos, con presupuesto propio). No escribe en
 * wp_cn_miembros ni en MP; lo único que guarda son sus propias opciones y filas de mp_log.
 *
 * Compara tres cosas:
 *  1. sin_ficha:            suscripciones authorized en MP sin ninguna ficha con ese preapproval_id (más de 1 hora de vida).
 *  2. ficha_sin_authorized: fichas 'activo' con preapproval_id cuya suscripción ya NO está authorized en MP y cuyo acceso_hasta
 *                           ya pasó (o no tiene fecha). Con acceso_hasta a futuro es la gracia normal de una baja: no cuenta.
 *  3. pausadas_nuevas:      suscripciones paused en MP que no se habían visto antes (la primera corrida solo fija la línea base).
 *
 * Modos (opción cn_conciliacion_modo; por defecto y ante cualquier valor raro, 'log'):
 *  - 'off': no hace nada.
 *  - 'log': deja UNA fila mp_log 'conciliacion_diaria' con cantidades e ids enmascarados. Nunca manda mail.
 *  - 'on':  además manda UN mail (vía CN_Alertas) solo si hay algo grave: suscripciones sin ficha de más de 72 hs o
 *           fichas con acceso sin pago vigente. Máx. 1 mail por hora entre todas las alertas.
 *
 * Si MP no responde, o la lista de authorized quedó incompleta, NO se concluye nada (sería una falsa alarma): queda
 * 'conciliacion_incompleta' en mp_log. Sin emails, nombres ni celulares en logs ni en el mail. Nunca lanza.
 */
class CN_Conciliacion {
	const OPT_MODO     = 'cn_conciliacion_modo';
	const OPT_PAUSADAS = 'cn_conc_pausadas_vistas'; // id de suscripción => timestamp de la primera vez que se vio pausada
	const OPT_MAIL     = 'cn_conc_ultimo_mail';
	const MAX_PAGINAS  = 4;      // páginas de 100 por estado
	const MAX_VISTAS   = 500;
	const SIN_FICHA_SEG = 259200; // 72 hs
	const ASUNTO       = '[Club Natureza] GRAVE: conciliación con Mercado Pago encontró %d casos';

	public static function modo() {
		$m = (string) get_option( self::OPT_MODO, 'log' );
		return in_array( $m, array( 'off', 'on' ), true ) ? $m : 'log';
	}

	/** Todas las suscripciones de un estado, o null si MP falló o la lista quedó incompleta. */
	protected static function listar( $estado, &$pres ) {
		$items  = array();
		$offset = 0;
		for ( $pag = 0; $pag < self::MAX_PAGINAS; $pag++ ) {
			$r = CN_MP::get_con_reintentos( '/preapproval/search?status=' . rawurlencode( $estado ) . '&limit=100&offset=' . $offset, CN_MP::get_access_token(), $pres );
			if ( empty( $r['ok'] ) || ! isset( $r['data']['results'] ) || ! is_array( $r['data']['results'] ) ) {
				return null;
			}
			foreach ( $r['data']['results'] as $it ) {
				if ( is_array( $it ) ) {
					$items[] = $it;
				}
			}
			$total   = isset( $r['data']['paging']['total'] ) ? (int) $r['data']['paging']['total'] : count( $items );
			$offset += 100;
			if ( count( $r['data']['results'] ) < 100 || $offset >= $total ) {
				return $items;
			}
		}
		return null; // más de MAX_PAGINAS páginas: lista incompleta.
	}

	protected static function id_de( $it ) {
		$id = isset( $it['id'] ) && is_string( $it['id'] ) ? strtolower( trim( $it['id'] ) ) : '';
		return preg_match( '/^[a-z0-9]{8,64}$/', $id ) ? $id : '';
	}

	/**
	 * Una corrida. Devuelve el resumen (para los tests) o null si no hizo nada o no pudo concluir.
	 *
	 * @return array{modo:string,sin_ficha:array,ficha_sin_authorized:array,pausadas_nuevas:array,linea_base:bool,mail:bool}|null
	 */
	public static function correr() {
		global $wpdb;
		$modo = self::modo();
		if ( 'off' === $modo || '' === CN_MP::get_access_token() ) {
			return null;
		}
		$pres = array( 'requests' => 16, 'espera' => 30, 'hasta' => time() + 60 );
		$auth = self::listar( 'authorized', $pres );
		if ( null === $auth ) {
			CN_Webhook::registrar_log_mp( 'conciliacion_incompleta', array( 'motivo' => 'lista authorized no disponible o incompleta', 'modo' => $modo ) );
			return null;
		}
		$fichas = $wpdb->get_results( 'SELECT id, estado, preapproval_id, acceso_hasta FROM ' . CN_DB::tabla( 'miembros' ) . " WHERE preapproval_id IS NOT NULL AND preapproval_id <> ''" );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $fichas ) ) {
			CN_Webhook::registrar_log_mp( 'conciliacion_incompleta', array( 'motivo' => 'no se pudieron leer las fichas', 'modo' => $modo ) );
			return null;
		}
		$ahora   = time();
		$por_pre = array();
		foreach ( $fichas as $f ) {
			$por_pre[ strtolower( (string) $f->preapproval_id ) ] = $f;
		}
		$en_auth = array();
		$sin_ficha = array();
		foreach ( $auth as $it ) {
			$id = self::id_de( $it );
			if ( '' === $id ) {
				continue;
			}
			$en_auth[ $id ] = true;
			if ( isset( $por_pre[ $id ] ) ) {
				continue;
			}
			$creada = isset( $it['date_created'] ) && is_string( $it['date_created'] ) ? strtotime( $it['date_created'] ) : false;
			if ( false === $creada || $creada > $ahora - CN_Sync::MIN_SIN_VINCULAR ) {
				continue; // muy reciente (el alta automática va primero) o sin fecha legible.
			}
			$sin_ficha[ $id ] = $ahora - $creada;
		}
		$sin_authorized = array();
		foreach ( $por_pre as $id => $f ) {
			if ( 'activo' !== (string) $f->estado || isset( $en_auth[ $id ] ) ) {
				continue;
			}
			$hasta = ! empty( $f->acceso_hasta ) ? strtotime( (string) $f->acceso_hasta . ' UTC' ) : false;
			if ( false !== $hasta && $hasta >= $ahora ) {
				continue; // todavía dentro del período pagado / gracia.
			}
			$sin_authorized[ $id ] = (int) $f->id;
		}
		$pausadas   = self::listar( 'paused', $pres );
		$nuevas     = array();
		$linea_base = false;
		if ( null !== $pausadas ) {
			$vistas     = get_option( self::OPT_PAUSADAS, false );
			$linea_base = ! is_array( $vistas );
			$vistas     = is_array( $vistas ) ? $vistas : array();
			foreach ( $pausadas as $it ) {
				$id = self::id_de( $it );
				if ( '' === $id || isset( $vistas[ $id ] ) ) {
					continue;
				}
				$vistas[ $id ] = $ahora;
				if ( ! $linea_base ) {
					$nuevas[] = $id;
				}
			}
			update_option( self::OPT_PAUSADAS, array_slice( $vistas, -self::MAX_VISTAS, null, true ), false );
		}
		$graves_sin_ficha = array_filter( $sin_ficha, function ( $edad ) {
			return $edad > self::SIN_FICHA_SEG;
		} );
		$res = array(
			'modo'                 => $modo,
			'sin_ficha'            => array_keys( $sin_ficha ),
			'ficha_sin_authorized' => array_keys( $sin_authorized ),
			'pausadas_nuevas'      => $nuevas,
			'linea_base'           => $linea_base,
			'mail'                 => false,
		);
		CN_Webhook::registrar_log_mp( 'conciliacion_diaria', array(
			'modo'                 => $modo,
			'authorized_en_mp'     => count( $en_auth ),
			'fichas_con_sub'       => count( $por_pre ),
			'sin_ficha'            => count( $sin_ficha ),
			'sin_ficha_mas_72h'    => count( $graves_sin_ficha ),
			'ficha_sin_authorized' => count( $sin_authorized ),
			'pausadas_nuevas'      => null === $pausadas ? 'sin_dato' : count( $nuevas ),
			'linea_base_pausadas'  => $linea_base,
			'ids_sin_ficha'        => array_map( array( 'CN_Alertas', 'mascara' ), array_slice( array_keys( $sin_ficha ), 0, 10 ) ),
			'ids_ficha_sin_auth'   => array_map( array( 'CN_Alertas', 'mascara' ), array_slice( array_keys( $sin_authorized ), 0, 10 ) ),
			'ids_pausadas_nuevas'  => array_map( array( 'CN_Alertas', 'mascara' ), array_slice( $nuevas, 0, 10 ) ),
		) );
		$graves = count( $graves_sin_ficha ) + count( $sin_authorized );
		if ( 'on' === $modo && $graves > 0 && time() - (int) get_option( self::OPT_MAIL, 0 ) >= DAY_IN_SECONDS ) {
			$res['mail'] = self::mandar( count( $graves_sin_ficha ), array_keys( $graves_sin_ficha ), count( $sin_authorized ), array_keys( $sin_authorized ), count( $nuevas ) );
		}
		return $res;
	}

	protected static function mandar( $n_sin_ficha, $ids_sin_ficha, $n_sin_auth, $ids_sin_auth, $n_pausadas ) {
		$m = array( 'CN_Alertas', 'mascara' );
		$cuerpo = "Qué pasó: la conciliación diaria entre Mercado Pago y las fichas encontró diferencias.\n"
			. "- Suscripciones authorized en MP sin ficha hace más de 72 hs: {$n_sin_ficha}" . ( $n_sin_ficha ? ' (' . implode( ', ', array_map( $m, array_slice( $ids_sin_ficha, 0, 10 ) ) ) . ')' : '' ) . "\n"
			. "- Fichas activas con acceso vencido y sin suscripción authorized: {$n_sin_auth}" . ( $n_sin_auth ? ' (' . implode( ', ', array_map( $m, array_slice( $ids_sin_auth, 0, 10 ) ) ) . ')' : '' ) . "\n"
			. "- Suscripciones pausadas nuevas desde la última vez: {$n_pausadas}\n"
			. 'Qué hacer: en wp-admin → Club Natureza → Socias, usar "Vincular suscripción (por celular)" para las que no tienen ficha, y revisar "Socias a revisar" para las fichas con acceso sin pago. El detalle (ids enmascarados) está en el log de Mercado Pago, tipo conciliacion_diaria. Nada se cambió solo.';
		return CN_Alertas::enviar_limitado(
			'conciliacion_mp',
			sprintf( self::ASUNTO, $n_sin_ficha + $n_sin_auth ),
			$cuerpo,
			array( 'sin_ficha' => $n_sin_ficha, 'ficha_sin_authorized' => $n_sin_auth, 'pausadas_nuevas' => $n_pausadas ),
			self::OPT_MAIL
		);
	}
}
