<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Herramienta TEMPORAL de diagnóstico "Diag MP pagador" (Socias → Diag MP pagador). Solo manage_options + nonce.
 * Responde: dado el id de una suscripción de Mercado Pago, ¿de dónde se puede sacar el nombre y el email del pagador?
 *
 * SOLO LECTURA: únicamente wp_remote_get a Mercado Pago, con el token de suscripciones ya guardado. No escribe nada:
 * ni mp_log, ni options, ni tablas. Los valores se muestran ENMASCARADOS (email ab***@dominio.com, nombre solo iniciales,
 * documentos y teléfonos nunca). No la llama el webhook, el login, el sync ni los crons.
 *
 * Para borrarla: quitar el require_once de este archivo en club-natureza-miembros.php y borrar el archivo (o git revert).
 */
class CN_Diag_Pagador {
	const NONCE = 'cn_diag_pagador';

	public static function registrar() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	public static function menu() {
		add_submenu_page( 'cn-socias', 'Diag MP pagador', 'Diag MP pagador', 'manage_options', 'cn-diag-pagador', array( __CLASS__, 'pagina' ) );
	}

	// ---------------------------------------------------------------- máscaras
	public static function mask_email( $v ) {
		$v = trim( (string) $v );
		$p = strpos( $v, '@' );
		if ( false === $p || $p < 1 ) {
			return '***';
		}
		return substr( $v, 0, min( 2, $p ) ) . '***' . substr( $v, $p );
	}

	/** Solo iniciales: "María García" -> "M. G." */
	public static function mask_nombre( $v ) {
		$out = array();
		foreach ( preg_split( '/\s+/u', trim( (string) $v ), -1, PREG_SPLIT_NO_EMPTY ) as $palabra ) {
			$out[] = ( function_exists( 'mb_substr' ) ? mb_substr( $palabra, 0, 1 ) : substr( $palabra, 0, 1 ) ) . '.';
		}
		return $out ? implode( ' ', $out ) : '***';
	}

	protected static function presente( $v ) {
		return null !== $v && '' !== $v && array() !== $v && false !== $v;
	}

	/** Valor a mostrar según el tipo de campo (nunca el valor crudo salvo tipos y estados inocuos). */
	public static function mostrar( $v, $tipo ) {
		if ( ! self::presente( $v ) ) {
			return '';
		}
		if ( ! is_scalar( $v ) ) {
			return '(estructura)';
		}
		switch ( $tipo ) {
			case 'email':
				return self::mask_email( $v );
			case 'nombre':
				return self::mask_nombre( $v );
			case 'id':
				return '(' . strlen( (string) $v ) . ' dígitos)';
			case 'plano': // estados y tipos de documento (DNI, CUIL…): no son datos personales.
				return mb_substr( preg_replace( '/[^\p{L}\p{N}_\- ]/u', '', (string) $v ), 0, 30 );
			default: // 'oculto': documento, teléfono, referencias.
				return '(oculto)';
		}
	}

	protected static function obtener( $arr, $ruta ) {
		foreach ( $ruta as $k ) {
			if ( ! is_array( $arr ) || ! array_key_exists( $k, $arr ) ) {
				return null;
			}
			$arr = $arr[ $k ];
		}
		return $arr;
	}

	/** Filas "endpoint, campo, presente, valor enmascarado" para una lista de campos de un JSON. */
	protected static function campos( $endpoint, $data, $campos ) {
		$filas = array();
		foreach ( $campos as $c ) {
			$v       = self::obtener( $data, $c[1] );
			$filas[] = array( $endpoint, $c[0], self::presente( $v ), self::mostrar( $v, $c[2] ) );
		}
		return $filas;
	}

	protected static function claves( $endpoint, $data ) {
		return array( $endpoint, '(claves de primer nivel)', is_array( $data ), is_array( $data ) ? implode( ', ', array_map( 'strval', array_keys( $data ) ) ) : '' );
	}

	// ---------------------------------------------------------------- HTTP (solo GET)
	protected static function get( $path, $token ) {
		$r = wp_remote_get( CN_MP::API_BASE . $path, array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		) );
		if ( is_wp_error( $r ) ) {
			return array( 'http' => 0, 'data' => null );
		}
		return array( 'http' => (int) wp_remote_retrieve_response_code( $r ), 'data' => json_decode( wp_remote_retrieve_body( $r ), true ) );
	}

	protected static function fila_http( $endpoint, $r, $extra = '' ) {
		return array( $endpoint, '(respuesta)', 200 === $r['http'], ( 0 === $r['http'] ? 'sin respuesta' : 'HTTP ' . $r['http'] ) . ( '' !== $extra ? ' — ' . $extra : '' ) );
	}

	/**
	 * Corre el diagnóstico (todo GET) y devuelve las filas de la tabla más el veredicto de cada paso.
	 *
	 * @return array{error:string,filas:array,notas:array}
	 */
	public static function diagnosticar( $id ) {
		$out   = array( 'error' => '', 'filas' => array(), 'notas' => array() );
		$token = CN_MP::get_access_token();
		if ( '' === $token ) {
			$out['error'] = 'No hay token de Mercado Pago de suscripciones guardado.';
			return $out;
		}
		$f = &$out['filas'];

		// 1) La suscripción.
		$e1 = 'GET /preapproval/{id}';
		$r1 = self::get( '/preapproval/' . rawurlencode( $id ), $token );
		$f[] = self::fila_http( $e1, $r1 );
		if ( 200 === $r1['http'] && is_array( $r1['data'] ) ) {
			$f[] = self::claves( $e1, $r1['data'] );
			$f   = array_merge( $f, self::campos( $e1, $r1['data'], array(
				array( 'payer_id', array( 'payer_id' ), 'id' ),
				array( 'payer_email', array( 'payer_email' ), 'email' ),
				array( 'payer_first_name', array( 'payer_first_name' ), 'nombre' ),
				array( 'payer_last_name', array( 'payer_last_name' ), 'nombre' ),
				array( 'external_reference', array( 'external_reference' ), 'oculto' ),
				array( 'status', array( 'status' ), 'plano' ),
			) ) );
		}

		// 2a) Cobros de la suscripción: authorized_payments (el endpoint documentado de Suscripciones → Invoices).
		$e2a = 'GET /authorized_payments/search?preapproval_id=';
		$r2a = self::get( '/authorized_payments/search?preapproval_id=' . rawurlencode( $id ), $token );
		$res = ( is_array( $r2a['data'] ) && isset( $r2a['data']['results'] ) && is_array( $r2a['data']['results'] ) ) ? $r2a['data']['results'] : array();
		$f[] = self::fila_http( $e2a, $r2a, 200 === $r2a['http'] ? count( $res ) . ' cobro(s)' : '' );
		$payment_id = '';
		foreach ( $res as $item ) {
			$pid = self::obtener( $item, array( 'payment', 'id' ) );
			if ( self::presente( $pid ) ) {
				$payment_id = (string) $pid;
				break;
			}
		}
		if ( $res ) {
			$f[] = self::claves( $e2a . ' (1.º resultado)', $res[0] );
		}
		$f[] = array( $e2a, 'results[].payment.id', '' !== $payment_id, '' !== $payment_id ? '(' . strlen( $payment_id ) . ' dígitos)' : '' );
		$out['notas'][] = ( 200 === $r2a['http'] && '' !== $payment_id )
			? 'authorized_payments/search SIRVE para esta suscripción: trae el id del cobro.'
			: 'authorized_payments/search NO sirvió para esta suscripción (' . ( 200 === $r2a['http'] ? '200 pero sin cobros con payment.id' : 'HTTP ' . $r2a['http'] ) . '); se prueba /v1/payments/search.';

		// 2b) Alternativa solo si la primera no dio un cobro: pagos por external_reference de la suscripción.
		if ( '' === $payment_id ) {
			$ref = is_array( $r1['data'] ) ? (string) ( $r1['data']['external_reference'] ?? '' ) : '';
			$e2b = 'GET /v1/payments/search?external_reference=';
			if ( '' === $ref ) {
				$f[]            = array( $e2b, '(no se probó)', false, 'la suscripción no tiene external_reference' );
				$out['notas'][] = '/v1/payments/search no se pudo probar: la suscripción no tiene external_reference.';
			} else {
				$r2b = self::get( '/v1/payments/search?sort=date_created&criteria=desc&limit=5&external_reference=' . rawurlencode( $ref ), $token );
				$rb  = ( is_array( $r2b['data'] ) && isset( $r2b['data']['results'] ) && is_array( $r2b['data']['results'] ) ) ? $r2b['data']['results'] : array();
				$f[] = self::fila_http( $e2b, $r2b, 200 === $r2b['http'] ? count( $rb ) . ' cobro(s)' : '' );
				foreach ( $rb as $item ) {
					if ( is_array( $item ) && self::presente( $item['id'] ?? null ) ) {
						$payment_id = (string) $item['id'];
						break;
					}
				}
				$f[]            = array( $e2b, 'results[].id', '' !== $payment_id, '' !== $payment_id ? '(' . strlen( $payment_id ) . ' dígitos)' : '' );
				$out['notas'][] = '' !== $payment_id ? '/v1/payments/search por external_reference SIRVE para esta suscripción.' : '/v1/payments/search por external_reference tampoco devolvió cobros.';
			}
		}

		// 3) El cobro: ahí suelen estar los datos del pagador.
		if ( '' === $payment_id ) {
			$out['notas'][] = 'Ningún endpoint de cobros sirvió para esta suscripción: no hay cobro del cual leer el pagador.';
			return $out;
		}
		$e3 = 'GET /v1/payments/{id}';
		$r3 = self::get( '/v1/payments/' . rawurlencode( $payment_id ), $token );
		$f[] = self::fila_http( $e3, $r3 );
		if ( 200 === $r3['http'] && is_array( $r3['data'] ) ) {
			$f = array_merge( $f, self::campos( $e3, $r3['data'], array(
				array( 'payer.id', array( 'payer', 'id' ), 'id' ),
				array( 'payer.email', array( 'payer', 'email' ), 'email' ),
				array( 'payer.first_name', array( 'payer', 'first_name' ), 'nombre' ),
				array( 'payer.last_name', array( 'payer', 'last_name' ), 'nombre' ),
				array( 'payer.identification.type', array( 'payer', 'identification', 'type' ), 'plano' ),
				array( 'payer.identification.number', array( 'payer', 'identification', 'number' ), 'oculto' ),
				array( 'payer.phone.number', array( 'payer', 'phone', 'number' ), 'oculto' ),
				array( 'card.cardholder.name', array( 'card', 'cardholder', 'name' ), 'nombre' ),
				array( 'additional_info.payer.first_name', array( 'additional_info', 'payer', 'first_name' ), 'nombre' ),
				array( 'additional_info.payer.last_name', array( 'additional_info', 'payer', 'last_name' ), 'nombre' ),
				array( 'status', array( 'status' ), 'plano' ),
			) ) );
		} else {
			$out['notas'][] = 'No se pudo leer el cobro (HTTP ' . $r3['http'] . '): sin datos del pagador por esta vía.';
		}
		return $out;
	}

	// ---------------------------------------------------------------- página
	public static function pagina() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		echo '<div class="wrap"><h1>Diag MP pagador</h1>';
		echo '<p>Herramienta temporal, <strong>solo lectura</strong> (únicamente consultas GET a Mercado Pago; no guarda nada). Los datos salen enmascarados.</p>';
		echo '<form method="post">';
		wp_nonce_field( self::NONCE, 'cn_diag_nonce' );
		echo '<p><label>ID de la suscripción (preapproval_id): <input type="text" name="cn_diag_preapproval" class="regular-text" autocomplete="off"></label> ';
		echo '<button type="submit" name="cn_diag_pagador" value="1" class="button button-primary">Consultar</button></p></form>';
		if ( ! isset( $_POST['cn_diag_pagador'] ) ) {
			echo '</div>';
			return;
		}
		if ( ! check_admin_referer( self::NONCE, 'cn_diag_nonce' ) ) {
			echo '</div>';
			return;
		}
		$id = isset( $_POST['cn_diag_preapproval'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['cn_diag_preapproval'] ) ) ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_\-]{8,64}$/', $id ) ) {
			echo '<p style="color:#b32d2e;">ID inválido.</p></div>';
			return;
		}
		$r = self::diagnosticar( $id );
		if ( '' !== $r['error'] ) {
			echo '<p style="color:#b32d2e;"><strong>' . esc_html( $r['error'] ) . '</strong></p></div>';
			return;
		}
		echo '<table class="widefat striped" style="margin-top:12px;"><thead><tr><th>Endpoint</th><th>Campo</th><th>¿Presente?</th><th>Valor (enmascarado)</th></tr></thead><tbody>';
		foreach ( $r['filas'] as $fila ) {
			echo '<tr><td><code>' . esc_html( $fila[0] ) . '</code></td><td>' . esc_html( $fila[1] ) . '</td><td>' . ( $fila[2] ? 'sí' : '<strong>no</strong>' ) . '</td><td style="word-break:break-word;">' . esc_html( $fila[3] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		foreach ( $r['notas'] as $n ) {
			echo '<p>' . esc_html( $n ) . '</p>';
		}
		echo '</div>';
	}
}
CN_Diag_Pagador::registrar(); // se engancha solo: borrar la herramienta = quitar el require_once y este archivo.
