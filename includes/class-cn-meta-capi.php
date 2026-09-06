<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Conversions API (server-side) para el evento Purchase del trial. Complementa
 * al Pixel (client-side, en /gracias-prueba/) — ambos mandan el MISMO event_id
 * (el external_reference de la Preference, ya único por transacción) para que
 * Meta los deduplique como un solo evento en vez de contar la conversión doble.
 */
class CN_Meta_Capi {
	const PIXEL_ID    = '424753682060500';
	const API_VERSION = 'v21.0';
	public static function get_token() {
		return trim( (string) get_option( 'cn_meta_capi_token', '' ) );
	}
	/**
	 * Formatea un celular normalizado a E.164 sin el "+".
	 * - Si son exactamente 10 dígitos → asumimos AR y prependemos "54".
	 * - Si son 11+ dígitos → asumimos que ya trae código de país (LATAM).
	 * Criterio alineado con CN_Helpers::normalizar_celular (que ya distingue AR vs resto).
	 */
	protected static function formato_e164( $celular_normalizado ) {
		$tel = preg_replace( '/\D+/', '', (string) $celular_normalizado );
		if ( '' === $tel ) {
			return '';
		}
		if ( 10 === strlen( $tel ) ) {
			return '54' . $tel;
		}
		return $tel;
	}
	/**
	 * Manda el evento Purchase del trial a Meta vía CAPI. No debe romper el
	 * alta si falla (por eso va después de que la socia ya quedó activa),
	 * pero sí queda logueado en el canal de alertas de n8n para diagnosticar.
	 *
	 * $fbp/$fbc/$ip/$user_agent vienen de trial_pendientes (capturados en el
	 * navegador al crear la Preference) — sin esto el EMQ del Purchase server-side
	 * queda bajo porque solo matchea por email/teléfono, no por navegador.
	 */
	public static function enviar_purchase( $event_id, $valor, $email, $celular, $fbp = '', $fbc = '', $ip = '', $user_agent = '' ) {
		global $wpdb;
		$token = self::get_token();
		if ( ! $token || ! $event_id ) {
			return;
		}
		$user_data = array();
		if ( $email ) {
			$user_data['em'] = array( hash( 'sha256', strtolower( trim( $email ) ) ) );
		}
		$tel = self::formato_e164( $celular );
		if ( $tel ) {
			$user_data['ph'] = array( hash( 'sha256', $tel ) );
		}
		// Estos NO van hasheados — Meta los espera en texto plano.
		if ( $fbp ) {
			$user_data['fbp'] = $fbp;
		}
		if ( $fbc ) {
			$user_data['fbc'] = $fbc;
		}
		if ( $ip ) {
			$user_data['client_ip_address'] = $ip;
		}
		if ( $user_agent ) {
			$user_data['client_user_agent'] = $user_agent;
		}
		$evento = array(
			'event_name'       => 'Purchase',
			'event_time'       => time(),
			'event_id'         => $event_id,
			'action_source'    => 'website',
			'event_source_url' => add_query_arg( 'eid', $event_id, home_url( '/gracias-prueba/' ) ),
			'user_data'        => $user_data,
			'custom_data'      => array(
				'currency'     => 'ARS',
				'value'        => $valor,
				'content_name' => 'Club Natureza - Prueba 7 dias',
				'content_type' => 'product',
				'content_ids'  => array( 'club-natureza-trial-7d' ),
			),
		);
		$respuesta = wp_remote_post(
			'https://graph.facebook.com/' . self::API_VERSION . '/' . self::PIXEL_ID . '/events?access_token=' . rawurlencode( $token ),
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'data' => array( $evento ) ) ),
			)
		);
		$codigo   = is_wp_error( $respuesta ) ? 0 : wp_remote_retrieve_response_code( $respuesta );
		$body_raw = is_wp_error( $respuesta ) ? $respuesta->get_error_message() : wp_remote_retrieve_body( $respuesta );
		// Log diagnóstico en mp_log — qué campos user_data se mandaron (sin PII: solo qué llegó,
		// no el contenido) + código HTTP de Meta. Sirve para diagnosticar EMQ sin abrir Events Manager.
		$wpdb->insert(
			CN_DB::tabla( 'mp_log' ),
			array(
				'payload' => wp_json_encode( array(
					'evento'         => 'capi_purchase',
					'event_id'       => $event_id,
					'valor'          => $valor,
					'user_data_keys' => array_keys( $user_data ),
					'http_code'      => $codigo,
					'meta_response'  => substr( (string) $body_raw, 0, 500 ),
				) ),
				'tipo'    => 'capi_purchase',
				'fecha'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
		if ( is_wp_error( $respuesta ) || $codigo >= 300 ) {
			wp_remote_post( CN_Webhook::N8N_ALERTA_URL, array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( array(
					'motivo'  => 'capi_purchase_fallo',
					'detalle' => array( 'event_id' => $event_id, 'http_code' => $codigo, 'error' => substr( (string) $body_raw, 0, 500 ) ),
					'fecha'   => current_time( 'mysql', true ),
				) ),
			) );
		}
	}
}
