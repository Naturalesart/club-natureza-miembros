<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Conversions API (server-side) para el evento Purchase del trial. Complementa
 * al Pixel (client-side, en /gracias-prueba/) — ambos mandan el MISMO event_id
 * (el external_reference de la Preference, ya único por transacción) para que
 * Meta los deduplique como un solo evento en vez de contar la conversión doble.
 */
class CN_Meta_Capi {
	const PIXEL_ID    = '424753682060500'; // Pixel único del sitio, no es secreto.
	const API_VERSION = 'v21.0';
	public static function get_token() {
		return trim( (string) get_option( 'cn_meta_capi_token', '' ) );
	}
	/**
	 * Manda el evento Purchase del trial a Meta vía CAPI. No debe romper el
	 * alta si falla (por eso va después de que la socia ya quedó activa),
	 * pero sí queda logueado en el canal de alertas de n8n para diagnosticar.
	 */
	public static function enviar_purchase( $event_id, $valor, $email, $celular ) {
		$token = self::get_token();
		if ( ! $token || ! $event_id ) {
			return; // Sin token configurado o sin event_id: no hay nada que mandar.
		}
		$user_data = array();
		if ( $email ) {
			$user_data['em'] = array( hash( 'sha256', strtolower( trim( $email ) ) ) );
		}
		if ( $celular ) {
			// Meta espera E.164 (con código de país) antes de hashear.
			$tel = preg_replace( '/\D+/', '', $celular );
			if ( $tel && '54' !== substr( $tel, 0, 2 ) ) {
				$tel = '54' . $tel;
			}
			if ( $tel ) {
				$user_data['ph'] = array( hash( 'sha256', $tel ) );
			}
		}
		$evento = array(
			'event_name'       => 'Purchase',
			'event_time'       => time(),
			'event_id'         => $event_id,
			'action_source'    => 'website',
			'event_source_url' => home_url( '/gracias-prueba/' ),
			'user_data'        => $user_data,
			'custom_data'      => array(
				'currency'     => 'ARS',
				'value'        => $valor,
				'content_name' => 'Club Natureza - Prueba 7 dias',
				'content_type' => 'product',
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
		if ( is_wp_error( $respuesta ) || wp_remote_retrieve_response_code( $respuesta ) >= 300 ) {
			$detalle = is_wp_error( $respuesta ) ? $respuesta->get_error_message() : wp_remote_retrieve_body( $respuesta );
			wp_remote_post( CN_Webhook::N8N_ALERTA_URL, array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( array(
					'motivo'  => 'capi_purchase_fallo',
					'detalle' => array( 'event_id' => $event_id, 'error' => $detalle ),
					'fecha'   => current_time( 'mysql', true ),
				) ),
			) );
		}
	}
}
