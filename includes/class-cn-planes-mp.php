<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Herramienta TEMPORAL "Planes de Mercado Pago" (Socias → Planes MP). Solo manage_options.
 *  - Consultar: SOLO LECTURA (GET /preapproval_plan/{id}). No muestra el token.
 *  - Igualar back_url: PUT /preapproval_plan/{id basico} enviando SOLO { back_url } (el del plan Full). Con nonce.
 * No toca monto, frecuencia ni status, ni suscripciones/cobros existentes. No la llama el webhook, el login ni los crons.
 */
class CN_Planes_MP {
	const ID_FULL   = '39f391c46f16478b8b7e5a752eb6cd0a';
	const ID_BASICO = 'c2d53771f9c949689600557b36eb1c66';
	const ID_A      = 'c70a2eecd53d4052896116280b814e85';
	const NONCE     = 'cn_planes_mp';

	public static function registrar() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	public static function menu() {
		add_submenu_page( 'cn-socias', 'Planes MP', 'Planes MP', 'manage_options', 'cn-planes-mp', array( __CLASS__, 'pagina' ) );
	}

	protected static function llamar( $metodo, $path, $token, $cuerpo = null ) {
		$args = array(
			'method'  => $metodo,
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
		);
		if ( null !== $cuerpo ) {
			$args['body'] = wp_json_encode( $cuerpo );
		}
		$r = wp_remote_request( CN_MP::API_BASE . $path, $args );
		if ( is_wp_error( $r ) ) {
			return array( 'http' => 0, 'data' => null );
		}
		return array( 'http' => (int) wp_remote_retrieve_response_code( $r ), 'data' => json_decode( wp_remote_retrieve_body( $r ), true ) );
	}

	/** Primer acceso guardado (suscripciones, luego trial) que puede leer el plan Full. */
	protected static function token() {
		foreach ( array( CN_MP::get_access_token(), CN_MP::get_access_token_trial() ) as $t ) {
			if ( '' === $t ) {
				continue;
			}
			$r = self::llamar( 'GET', '/preapproval_plan/' . self::ID_FULL, $t );
			if ( 200 === $r['http'] ) {
				return $t;
			}
		}
		return '';
	}

	protected static function resumen( $id, $token ) {
		$r = self::llamar( 'GET', '/preapproval_plan/' . rawurlencode( $id ), $token );
		if ( 200 !== $r['http'] || ! is_array( $r['data'] ) ) {
			return array( 'id' => $id, 'http' => $r['http'], 'ok' => false );
		}
		$d = $r['data'];
		return array(
			'id'       => $id,
			'http'     => 200,
			'ok'       => true,
			'back_url' => (string) ( $d['back_url'] ?? '' ),
			'reason'   => (string) ( $d['reason'] ?? '' ),
			'monto'    => $d['auto_recurring']['transaction_amount'] ?? '',
			'freq'     => ( $d['auto_recurring']['frequency'] ?? '' ) . ' ' . ( $d['auto_recurring']['frequency_type'] ?? '' ),
			'status'   => (string) ( $d['status'] ?? '' ),
		);
	}

	protected static function fila( $p ) {
		if ( empty( $p['ok'] ) ) {
			return '<tr><td><code>' . esc_html( $p['id'] ) . '</code></td><td colspan="4">No se pudo leer (HTTP ' . (int) $p['http'] . ')</td></tr>';
		}
		$back = '' === $p['back_url'] ? '<strong style="color:#b32d2e;">(vacío)</strong>' : esc_html( $p['back_url'] );
		return '<tr><td><code>' . esc_html( $p['id'] ) . '</code></td><td>' . esc_html( $p['reason'] ) . '</td><td>' . esc_html( $p['monto'] ) . ' (' . esc_html( $p['freq'] ) . ')</td><td>' . esc_html( $p['status'] ) . '</td><td style="word-break:break-all;">' . $back . '</td></tr>';
	}

	public static function pagina() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		echo '<div class="wrap"><h1>Planes de Mercado Pago</h1><p>Herramienta temporal. Consultar es solo lectura; "Igualar back_url" cambia únicamente el back_url del plan básico al del Full.</p>';
		echo '<form method="post">';
		wp_nonce_field( self::NONCE, 'cn_planes_nonce' );
		echo '<button type="submit" name="cn_planes_accion" value="consultar" class="button button-primary">Consultar planes</button></form>';

		$accion = isset( $_POST['cn_planes_accion'] ) ? sanitize_key( wp_unslash( $_POST['cn_planes_accion'] ) ) : '';
		if ( '' === $accion ) {
			echo '</div>';
			return;
		}
		check_admin_referer( self::NONCE, 'cn_planes_nonce' );
		$token = self::token();
		if ( '' === $token ) {
			echo '<p style="color:#b32d2e;"><strong>Ningún acceso guardado puede leer el plan Full.</strong> No se cambió nada.</p></div>';
			return;
		}

		if ( 'igualar' === $accion ) {
			$full = self::resumen( self::ID_FULL, $token );
			$bas  = self::resumen( self::ID_BASICO, $token );
			if ( empty( $full['ok'] ) || empty( $bas['ok'] ) || '' === $full['back_url'] ) {
				echo '<p style="color:#b32d2e;">No se pudo leer el Full/básico o el Full no tiene back_url. No se cambió nada.</p>';
			} elseif ( $full['back_url'] === $bas['back_url'] ) {
				echo '<p>El básico ya tiene el mismo back_url. No se cambió nada.</p>';
			} else {
				$r = self::llamar( 'PUT', '/preapproval_plan/' . self::ID_BASICO, $token, array( 'back_url' => $full['back_url'] ) );
				error_log( '[CN planes_mp] PUT back_url plan basico HTTP ' . (int) $r['http'] );
				echo '<p><strong>PUT al plan básico: HTTP ' . (int) $r['http'] . '</strong>' . ( 200 === $r['http'] ? ' — hecho.' : ' — MP no lo aceptó (no se insiste). ' . esc_html( wp_json_encode( $r['data'] ) ) ) . '</p>';
			}
		}

		$ids = array( self::ID_FULL, self::ID_BASICO, self::ID_A );
		foreach ( array( 'cn_plan_id_basico', 'cn_plan_id_full' ) as $opt ) {
			$v = trim( (string) get_option( $opt, '' ) );
			if ( preg_match( '/^[a-f0-9]{32}$/i', $v ) ) {
				$ids[] = $v;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		$res = array();
		echo '<table class="widefat striped" style="margin-top:12px;"><thead><tr><th>ID</th><th>Reason</th><th>Monto</th><th>Status</th><th>back_url</th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			$res[ $id ] = self::resumen( $id, $token );
			echo self::fila( $res[ $id ] );
		}
		echo '</tbody></table>';
		$full = $res[ self::ID_FULL ];
		$bas  = $res[ self::ID_BASICO ];
		if ( ! empty( $full['ok'] ) && ! empty( $bas['ok'] ) && '' !== $full['back_url'] && $full['back_url'] !== $bas['back_url'] ) {
			echo '<form method="post" style="margin-top:12px;" onsubmit="return confirm(\'Cambiar SOLO el back_url del plan básico al del Full?\');">';
			wp_nonce_field( self::NONCE, 'cn_planes_nonce' );
			echo '<button type="submit" name="cn_planes_accion" value="igualar" class="button">Igualar back_url del básico al del Full</button></form>';
		}
		echo '</div>';
	}
}
