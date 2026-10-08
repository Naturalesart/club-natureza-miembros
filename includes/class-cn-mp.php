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
