<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class CN_MP {
	const API_BASE = 'https://api.mercadopago.com';
	public static function get_access_token() {
		return trim( (string) get_option( 'cn_mp_access_token', '' ) );
	}
	public static function get_access_token_trial() {
		return trim( (string) get_option( 'cn_mp_access_token_trial', '' ) );
	}
	public static function get_precio_mensual() {
		return (float) get_option( 'cn_mp_precio_mensual', 0 );
	}
	public static function get_razon_plan() {
		$razon = get_option( 'cn_mp_razon_plan', 'Club Natureza — Membresía mensual' );
		return $razon ? $razon : 'Club Natureza — Membresía mensual';
	}
	public static function get_precio_trial() {
		return (float) get_option( 'cn_mp_precio_trial', 7000 );
	}
	/**
	 * Planes de suscripción (alta automática). El link se genera SIN preapproval_plan_id
	 * (con plan asociado MP exige tarjeta al crearlo), así que el monto sale de este mapa.
	 * Las opciones cn_plan_id_basico / cn_plan_id_full quedan solo como dato informativo.
	 */
	const PLANES = array(
		'basico' => array( 'nombre' => 'Club Natureza', 'precio' => 15500 ),
		'full'   => array( 'nombre' => 'Club Natureza Full', 'precio' => 32000 ),
	);
	public static function get_planes() {
		return self::PLANES;
	}
	public static function get_plan_id( $plan ) {
		if ( ! is_string( $plan ) || ! isset( self::PLANES[ $plan ] ) ) {
			return '';
		}
		return trim( (string) get_option( 'cn_plan_id_' . $plan, '' ) );
	}
	public static function webhook_url() {
		return rest_url( 'cn/v1/mp-webhook' );
	}
	public static function trial_webhook_url() {
		return rest_url( 'cn/v1/trial-alta' );
	}
	public static function crear_preapproval( $nombre, $celular_normalizado ) {
		$token = self::get_access_token();
		if ( ! $token ) {
			return array( 'ok' => false, 'init_point' => '', 'error' => 'Falta configurar el access token de Mercado Pago.' );
		}
		$precio = self::get_precio_mensual();
		if ( $precio <= 0 ) {
			return array( 'ok' => false, 'init_point' => '', 'error' => 'Falta configurar el precio mensual del plan.' );
		}
		$hash_corto          = substr( md5( $celular_normalizado . time() ), 0, 8 );
		$external_reference  = 'cn_' . $celular_normalizado . '_' . $hash_corto;
		$body = array(
			'reason'             => self::get_razon_plan(),
			'external_reference' => $external_reference,
			'auto_recurring'     => array(
				'frequency'          => 1,
				'frequency_type'     => 'months',
				'transaction_amount' => $precio,
				'currency_id'        => 'ARS',
			),
			'back_url' => home_url( '/club-natureza-miembros/' ),
			'status'   => 'pending',
		);
		$respuesta = wp_remote_post( self::API_BASE . '/preapproval', array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $respuesta ) ) {
			return array( 'ok' => false, 'init_point' => '', 'error' => $respuesta->get_error_message() );
		}
		$codigo = wp_remote_retrieve_response_code( $respuesta );
		$data   = json_decode( wp_remote_retrieve_body( $respuesta ), true );
		if ( $codigo < 200 || $codigo >= 300 || empty( $data['init_point'] ) ) {
			$mensaje = isset( $data['message'] ) ? $data['message'] : 'Mercado Pago rechazó la solicitud.';
			return array( 'ok' => false, 'init_point' => '', 'error' => $mensaje );
		}
		global $wpdb;
		$wpdb->insert(
			CN_DB::tabla( 'preapprovals_pendientes' ),
			array(
				'nombre_apellido'    => $nombre,
				'celular'            => $celular_normalizado,
				'external_reference' => $external_reference,
				'fecha'              => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		return array( 'ok' => true, 'init_point' => $data['init_point'], 'error' => '' );
	}
	/**
	 * $fbp/$fbc/$ip/$user_agent: capturados en el navegador al momento del
	 * formulario (única oportunidad — el webhook de pago es servidor-a-servidor
	 * y no tiene acceso a cookies ni IP real). Se guardan en trial_pendientes
	 * para que el CAPI Purchase los recupere al confirmarse el pago.
	 */
	public static function crear_preference_trial( $nombre, $celular_normalizado, $fbp = '', $fbc = '', $ip = '', $user_agent = '' ) {
		$token = self::get_access_token_trial();
		if ( ! $token ) {
			return array( 'ok' => false, 'init_point' => '', 'error' => 'Falta configurar el access token de Mercado Pago para el trial (Checkout Pro).' );
		}
		$precio = self::get_precio_trial();
		if ( $precio <= 0 ) {
			return array( 'ok' => false, 'init_point' => '', 'error' => 'Falta configurar el precio del trial.' );
		}
		$hash_corto          = substr( md5( $celular_normalizado . time() ), 0, 8 );
		$external_reference  = 'trial_' . $celular_normalizado . '_' . $hash_corto;
		$body = array(
			'items' => array(
				array(
					'title'       => 'Club Natureza — Prueba de 7 días',
					'quantity'    => 1,
					'currency_id' => 'ARS',
					'unit_price'  => $precio,
				),
			),
			'external_reference' => $external_reference,
			'metadata'           => array(
				'cn_tipo'      => 'trial',
				'cn_nombre'    => $nombre,
				'cn_celular'   => $celular_normalizado,
			),
			'notification_url' => self::trial_webhook_url(),
			'back_urls'         => array(
				'success' => add_query_arg( 'eid', $external_reference, home_url( '/gracias-prueba/' ) ),
				'pending' => add_query_arg( 'eid', $external_reference, home_url( '/gracias-prueba/' ) ),
				'failure' => home_url( '/prueba-club/' ),
			),
			'auto_return' => 'approved',
		);
		$respuesta = wp_remote_post( self::API_BASE . '/checkout/preferences', array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $respuesta ) ) {
			return array( 'ok' => false, 'init_point' => '', 'error' => $respuesta->get_error_message() );
		}
		$codigo = wp_remote_retrieve_response_code( $respuesta );
		$data   = json_decode( wp_remote_retrieve_body( $respuesta ), true );
		if ( $codigo < 200 || $codigo >= 300 || empty( $data['init_point'] ) ) {
			$mensaje = isset( $data['message'] ) ? $data['message'] : 'Mercado Pago rechazó la solicitud.';
			return array( 'ok' => false, 'init_point' => '', 'error' => $mensaje );
		}
		global $wpdb;
		$wpdb->insert(
			CN_DB::tabla( 'trial_pendientes' ),
			array(
				'nombre_apellido'    => $nombre,
				'celular'            => $celular_normalizado,
				'external_reference' => $external_reference,
				'fecha'              => current_time( 'mysql', true ),
				'fbp'                => $fbp ? $fbp : null,
				'fbc'                => $fbc ? $fbc : null,
				'ip'                 => $ip ? $ip : null,
				'user_agent'         => $user_agent ? $user_agent : null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return array( 'ok' => true, 'init_point' => $data['init_point'], 'external_reference' => $external_reference, 'error' => '' );
	}
	/**
	 * Crea una suscripción pendiente sobre un plan fijo de MP y devuelve el link de pago.
	 * Hoy NADIE la llama (Fase 2A: base inerte).
	 *
	 * $celular debe venir YA normalizado (CN_Helpers::normalizar_celular), igual que en
	 * crear_preference_trial(): normalizar dos veces rompe los números extranjeros.
	 * $meta (opcional): fbp, fbc, ip, user_agent.
	 *
	 * Devuelve el init_point (string) o un WP_Error. Si falta el ID del plan en las
	 * opciones, devuelve WP_Error SIN llamar a MP. Si MP falla, no deja fila pendiente.
	 */
	public static function crear_suscripcion_link( $plan, $nombre, $celular, $email = '', $origen = 'panel', $meta = array() ) {
		global $wpdb;
		$planes = self::get_planes();
		if ( ! is_string( $plan ) || ! isset( $planes[ $plan ] ) ) {
			return new WP_Error( 'cn_plan_invalido', 'Plan inválido.' );
		}
		$token = self::get_access_token();
		if ( '' === $token ) {
			return new WP_Error( 'cn_sin_token', 'Falta configurar el access token de Mercado Pago (Suscripciones).' );
		}
		if ( ! is_string( $origen ) || ! in_array( $origen, array( 'panel', 'landing' ), true ) ) {
			return new WP_Error( 'cn_origen_invalido', 'Origen inválido.' );
		}
		$nombre = mb_substr( trim( sanitize_text_field( (string) $nombre ) ), 0, 191 );
		if ( strlen( $nombre ) < 3 ) {
			return new WP_Error( 'cn_nombre_invalido', 'Ingresá nombre y apellido.' );
		}
		$celular = (string) $celular;
		if ( ! preg_match( '/^[0-9]{8,15}$/', $celular ) ) {
			return new WP_Error( 'cn_celular_invalido', 'Celular inválido.' );
		}
		$email_raw = trim( (string) $email );
		$email     = '';
		if ( '' !== $email_raw ) {
			$email = sanitize_email( $email_raw );
			if ( '' === $email || ! is_email( $email ) ) {
				return new WP_Error( 'cn_email_invalido', 'Email inválido.' );
			}
		}
		$meta = is_array( $meta ) ? $meta : array();
		$fbp  = isset( $meta['fbp'] ) ? mb_substr( sanitize_text_field( (string) $meta['fbp'] ), 0, 255 ) : '';
		$fbc  = isset( $meta['fbc'] ) ? mb_substr( sanitize_text_field( (string) $meta['fbc'] ), 0, 255 ) : '';
		$ip   = isset( $meta['ip'] ) ? mb_substr( sanitize_text_field( (string) $meta['ip'] ), 0, 45 ) : '';
		$ua   = isset( $meta['user_agent'] ) ? mb_substr( sanitize_text_field( (string) $meta['user_agent'] ), 0, 512 ) : '';
		$hash_corto         = substr( md5( $celular . microtime( true ) . wp_rand() ), 0, 8 );
		$external_reference = 'sub_' . $celular . '_' . $hash_corto;
		// La fila pendiente se guarda ANTES de llamar a MP; si MP falla, se borra.
		$t_pend    = CN_DB::tabla( 'suscripciones_pendientes' );
		$insertado = $wpdb->insert(
			$t_pend,
			array(
				'external_reference' => $external_reference,
				'nombre_apellido'    => $nombre,
				'celular'            => $celular,
				'email'              => '' !== $email ? $email : null,
				'plan'               => $plan,
				'origen'             => $origen,
				'fbp'                => '' !== $fbp ? $fbp : null,
				'fbc'                => '' !== $fbc ? $fbc : null,
				'ip'                 => '' !== $ip ? $ip : null,
				'user_agent'         => '' !== $ua ? $ua : null,
				'creado'             => current_time( 'mysql', true ),
				'usado'              => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
		if ( false === $insertado ) {
			CN_Webhook::registrar_log_mp( 'susc_pendiente_error', array( 'plan' => $plan, 'db_error' => mb_substr( (string) $wpdb->last_error, 0, 200 ) ) );
			return new WP_Error( 'cn_susc_pendiente', 'No pudimos registrar la suscripción. Probá de nuevo.' );
		}
		$body = array(
			// Sin preapproval_plan_id: MP exige tarjeta en el momento (card_token_id) para las
			// suscripciones con plan asociado. Sin plan, el link para pagar después sí existe.
			// Mismo formato que crear_preapproval(); el monto sale del mapa PLANES.
			'reason'             => 'Club Natureza — ' . $planes[ $plan ]['nombre'],
			'external_reference' => $external_reference,
			'auto_recurring'     => array(
				'frequency'          => 1,
				'frequency_type'     => 'months',
				'transaction_amount' => (float) $planes[ $plan ]['precio'],
				'currency_id'        => 'ARS',
			),
			'back_url' => home_url( '/club-natureza-miembros/' ),
			'status'   => 'pending',
		);
		if ( '' !== $email ) {
			$body['payer_email'] = $email;
		}
		$respuesta = wp_remote_post( self::API_BASE . '/preapproval', array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $respuesta ) ) {
			self::registrar_error_api( '/preapproval', 'wp_error', $respuesta->get_error_message() );
			$wpdb->delete( $t_pend, array( 'external_reference' => $external_reference ), array( '%s' ) );
			return new WP_Error( 'cn_mp_error', 'No pudimos contactar a Mercado Pago. Probá de nuevo.' );
		}
		$codigo = (int) wp_remote_retrieve_response_code( $respuesta );
		$cuerpo = wp_remote_retrieve_body( $respuesta );
		$data   = json_decode( $cuerpo, true );
		if ( $codigo < 200 || $codigo >= 300 || ! is_array( $data ) || empty( $data['init_point'] ) ) {
			self::registrar_error_api( '/preapproval', $codigo, $cuerpo );
			$wpdb->delete( $t_pend, array( 'external_reference' => $external_reference ), array( '%s' ) );
			$mensaje = ( is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) ) ? $data['message'] : 'Mercado Pago rechazó la solicitud.';
			return new WP_Error( 'cn_mp_rechazo', $mensaje );
		}
		return (string) $data['init_point'];
	}
	public static function obtener_preapproval( $id ) {
		return self::get( '/preapproval/' . rawurlencode( $id ), self::get_access_token() );
	}
	public static function obtener_pago( $id ) {
		return self::get( '/v1/payments/' . rawurlencode( $id ), self::get_access_token() );
	}
	public static function obtener_pago_trial( $id ) {
		return self::get( '/v1/payments/' . rawurlencode( $id ), self::get_access_token_trial() );
	}
	protected static function get( $path, $token ) {
		if ( ! $token ) {
			return null;
		}
		$respuesta = wp_remote_get( self::API_BASE . $path, array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
			),
		) );
		if ( is_wp_error( $respuesta ) ) {
			self::registrar_error_api( $path, 'wp_error', $respuesta->get_error_message() );
			return null;
		}
		$codigo = wp_remote_retrieve_response_code( $respuesta );
		if ( $codigo < 200 || $codigo >= 300 ) {
			self::registrar_error_api( $path, $codigo, wp_remote_retrieve_body( $respuesta ) );
			return null;
		}
		return json_decode( wp_remote_retrieve_body( $respuesta ), true );
	}
	/**
	 * Deja rastro de un error de la API de MP (mp_log + aviso a n8n, máx. 1/hora por
	 * ruta+código). Nunca lanza ni cambia lo que devuelve get().
	 */
	protected static function registrar_error_api( $path, $http, $cuerpo ) {
		try {
			$ruta   = (string) strtok( (string) $path, '?' );
			$cuerpo = mb_substr( preg_replace( '/APP_USR-[A-Za-z0-9\-]+/', '[oculto]', (string) $cuerpo ), 0, 300 );
			CN_Webhook::registrar_log_mp( 'mp_api_error', array( 'ruta' => $ruta, 'http' => $http, 'cuerpo' => $cuerpo ) );
			// Solo avisa a n8n por errores de acceso/servicio; 404 y otros 4xx quedan solo en el log.
			$avisar = ( 'wp_error' === $http )
				|| ( is_numeric( $http ) && ( in_array( (int) $http, array( 401, 403, 429 ), true ) || (int) $http >= 500 ) );
			// El id va al final de la ruta: se normaliza para que 39 eventos con 401 no manden 39 avisos.
			$clave = 'cn_mpapi_err_' . md5( preg_replace( '#/[^/]+$#', '/{id}', $ruta ) . '|' . $http );
			if ( $avisar && ! get_transient( $clave ) ) {
				set_transient( $clave, 1, HOUR_IN_SECONDS );
				CN_Webhook::avisar_error_n8n( 'mp_api_error', array( 'ruta' => $ruta, 'http' => $http ) );
			}
		} catch ( \Throwable $e ) {
			// Accesorio: si el log o el aviso fallan, se ignora.
		}
	}
}
