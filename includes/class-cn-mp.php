<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class CN_MP {
	const API_BASE = 'https://api.mercadopago.com';
	public static function get_access_token() {
		return trim( (string) get_option( 'cn_mp_access_token', '' ) );
	}
	/**
	 * Token de la app de Checkout Pro (trial). Mercado Pago exige una app por
	 * producto integrado — la app de Suscripciones no puede usarse para crear
	 * Preferences, así que el trial vive en una app separada, con su propio token.
	 */
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
	/**
	 * Crea una preapproval (suscripción) en Mercado Pago.
	 *
	 * @return array { ok: bool, init_point: string, error: string }
	 */
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
	 * Crea una Preference de Checkout Pro para el pago único del trial de 7 días.
	 * A diferencia de la preapproval (suscripción recurrente), esto es un pago
	 * único de $7.000 ARS. El nombre y el celular viajan en "metadata" — MP los
	 * copia automáticamente al payment resultante, así el endpoint /trial-alta
	 * no depende de ninguna tabla de "pendientes" para reconstruir quién pagó.
	 *
	 * @return array { ok: bool, init_point: string, external_reference: string, error: string }
	 */
	public static function crear_preference_trial( $nombre, $celular_normalizado ) {
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
		// Respaldo ante la posibilidad (documentada en casos de pago offline/express)
		// de que "metadata" no viaje completo hasta el payment. El webhook de trial
		// intenta primero con metadata; si viene vacío, cae acá por external_reference.
		global $wpdb;
		$wpdb->insert(
			CN_DB::tabla( 'trial_pendientes' ),
			array(
				'nombre_apellido'    => $nombre,
				'celular'            => $celular_normalizado,
				'external_reference' => $external_reference,
				'fecha'              => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		return array( 'ok' => true, 'init_point' => $data['init_point'], 'external_reference' => $external_reference, 'error' => '' );
	}
	public static function obtener_preapproval( $id ) {
		return self::get( '/preapproval/' . rawurlencode( $id ), self::get_access_token() );
	}
	public static function obtener_pago( $id ) {
		return self::get( '/v1/payments/' . rawurlencode( $id ), self::get_access_token() );
	}
	/**
	 * Igual que obtener_pago(), pero con el token de la app de Checkout Pro
	 * (trial) — necesario porque el pago del trial se creó con esa app, y MP
	 * exige consultarlo con el mismo token/app que lo generó.
	 */
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
			return null;
		}
		$codigo = wp_remote_retrieve_response_code( $respuesta );
		if ( $codigo < 200 || $codigo >= 300 ) {
			return null;
		}
		return json_decode( wp_remote_retrieve_body( $respuesta ), true );
	}
}
