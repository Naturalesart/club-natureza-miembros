<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CN_Auth {

	const COOKIE_NAME   = 'cn_session';
	// 180 días: la socia queda logueada ~6 meses por dispositivo, no tiene que
	// reingresar cada vez. La cookie (httponly, SameSite=Lax) y la fila de sesión
	// usan esta misma constante, así que se renueva todo junto.
	const SESSION_DIAS  = 180;
	const MAX_INTENTOS  = 5;
	const BLOQUEO_MIN   = 15;

	public static function ip_bloqueada() {
		$ip = CN_Helpers::get_client_ip();
		return (bool) get_transient( 'cn_block_' . md5( $ip ) );
	}

	protected static function registrar_intento_fallido() {
		$ip  = CN_Helpers::get_client_ip();
		$key = 'cn_fails_' . md5( $ip );
		$intentos = (int) get_transient( $key );
		$intentos++;

		if ( $intentos >= self::MAX_INTENTOS ) {
			set_transient( 'cn_block_' . md5( $ip ), 1, self::BLOQUEO_MIN * MINUTE_IN_SECONDS );
			delete_transient( $key );
		} else {
			set_transient( $key, $intentos, self::BLOQUEO_MIN * MINUTE_IN_SECONDS );
		}
	}

	protected static function limpiar_intentos() {
		$ip = CN_Helpers::get_client_ip();
		delete_transient( 'cn_fails_' . md5( $ip ) );
		delete_transient( 'cn_block_' . md5( $ip ) );
	}

	/**
	 * @return array { ok: bool, motivo: string }
	 * motivo: 'ok' | 'bloqueado' | 'pausado' | 'invalido'
	 */
	public static function login( $nombre_raw, $celular_raw ) {
		global $wpdb;

		if ( self::ip_bloqueada() ) {
			return array( 'ok' => false, 'motivo' => 'bloqueado' );
		}

		$nombre = sanitize_text_field( $nombre_raw );
		$nombre = trim( $nombre );

		$norm = CN_Helpers::normalizar_celular( $celular_raw );
		$celular_normalizado = $norm['normalizado'];

		if ( '' === $nombre || '' === $celular_normalizado ) {
			self::registrar_intento_fallido();
			return array( 'ok' => false, 'motivo' => 'invalido' );
		}

		$tabla = CN_DB::tabla( 'miembros' );

		$candidatos = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, nombre_apellido, celular_hash, estado FROM {$tabla} WHERE LOWER(TRIM(nombre_apellido)) = LOWER(TRIM(%s))",
				$nombre
			)
		);

		foreach ( $candidatos as $candidato ) {
			if ( CN_Helpers::verificar_celular( $celular_normalizado, $candidato->celular_hash ) ) {
				if ( 'pausado' === $candidato->estado ) {
					self::limpiar_intentos();
					return array( 'ok' => false, 'motivo' => 'pausado' );
				}

				self::crear_sesion( (int) $candidato->id );
				self::limpiar_intentos();
				return array( 'ok' => true, 'motivo' => 'ok' );
			}
		}

		self::registrar_intento_fallido();
		return array( 'ok' => false, 'motivo' => 'invalido' );
	}

	protected static function crear_sesion( $miembro_id ) {
		global $wpdb;

		$token   = CN_Helpers::generar_token();
		$expira  = gmdate( 'Y-m-d H:i:s', time() + self::SESSION_DIAS * DAY_IN_SECONDS );
		$tabla   = CN_DB::tabla( 'sesiones' );

		$wpdb->insert(
			$tabla,
			array(
				'miembro_id' => $miembro_id,
				'token'      => $token,
				'expira'     => $expira,
			),
			array( '%d', '%s', '%s' )
		);

		$secure = is_ssl();
		setcookie(
			self::COOKIE_NAME,
			$token,
			array(
				'expires'  => time() + self::SESSION_DIAS * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE_NAME ] = $token;
	}

	public static function get_miembro_actual() {
		global $wpdb;

		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return null;
		}

		$t_sesiones = CN_DB::tabla( 'sesiones' );
		$t_miembros = CN_DB::tabla( 'miembros' );

		$miembro = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.id, m.nombre_apellido, m.estado
				 FROM {$t_sesiones} s
				 INNER JOIN {$t_miembros} m ON m.id = s.miembro_id
				 WHERE s.token = %s AND s.expira > UTC_TIMESTAMP()",
				$token
			)
		);

		return $miembro ? $miembro : null;
	}

	public static function logout() {
		global $wpdb;

		if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
			$tabla = CN_DB::tabla( 'sesiones' );
			$wpdb->delete( $tabla, array( 'token' => $token ), array( '%s' ) );

			setcookie(
				self::COOKIE_NAME,
				'',
				array(
					'expires'  => time() - HOUR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
			unset( $_COOKIE[ self::COOKIE_NAME ] );
		}
	}
}
