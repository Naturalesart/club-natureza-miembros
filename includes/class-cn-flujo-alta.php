<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Flujo de alta (11/11/2026, caso ficha 304 → 340): flags compartidos, alerta a hola@ y bloque de Config.
 *
 * Cada etapa nueva del alta vive detrás de su propio flag off | log | on (default 'off' = comportamiento de siempre):
 *  - off: no cambia nada (ni lee, ni registra).
 *  - log: no cambia comportamiento; solo deja en mp_log qué habría hecho.
 *  - on : lo hace.
 * Marcha atrás de cualquier etapa: su flag en 'off'.
 *
 * Alerta a hola@: wp_mail directo (no pasa por n8n), como máximo 1 mail por caso y hora, sin datos personales en el
 * asunto ni en el cuerpo (solo id de ficha, plan, origen y motivo).
 */
class CN_Flujo_Alta {
	const OPT_ALERTA_EMAIL = 'cn_alerta_hola_email';
	const ALERTA_EMAIL_DEF = 'hola@naturalesart.com';
	const ALERTA_SEG       = 3600; // por caso (clave)
	const MODOS            = array( 'off', 'log', 'on' );

	/** option => texto de Config. Cada PR agrega los suyos. */
	public static function flags() {
		return array(
			'cn_suscribir_fallback_modo' => 'Link firmado: planes B y C (sin celular, formulario precargado, checkout + alerta)',
			'cn_identidad_modo'          => 'Una sola ficha por persona al activar (ficha del token → email → celular)',
			'cn_alta_reintento_modo'     => 'Mail de bienvenida: registrar resultado y reintentar (10 min, máx. 3)',
			'cn_retorno_activar_modo'    => 'Activar sin webhook: página de vuelta de MP y sync (plan B)',
			'cn_reenvio_acceso_modo'     => 'Login: "No puedo entrar → reenviame el acceso"',
			'cn_authorized_payment_modo' => 'Webhook: cobros de suscripción por /authorized_payments (arregla el 404)',
		);
	}

	/** off | log | on de un flag; cualquier otro valor = off. */
	public static function modo( $opt ) {
		$m = (string) get_option( (string) $opt, 'off' );
		return in_array( $m, self::MODOS, true ) ? $m : 'off';
	}

	/** Destino de las alertas del flujo de alta. */
	public static function email_alerta() {
		$e = sanitize_email( trim( (string) get_option( self::OPT_ALERTA_EMAIL, self::ALERTA_EMAIL_DEF ) ) );
		return ( '' !== $e && is_email( $e ) ) ? $e : self::ALERTA_EMAIL_DEF;
	}

	/**
	 * Mail de alerta a hola@. $lineas: texto plano SIN email, celular ni ids de Mercado Pago. Devuelve true si se mandó.
	 * Máximo 1 mail por $clave cada ALERTA_SEG. Deja rastro alerta_hola_enviada | alerta_hola_fallo | alerta_hola_demorada.
	 */
	public static function alertar( $clave, $asunto, $lineas ) {
		$t = 'cn_alerta_hola_' . md5( (string) $clave );
		if ( get_transient( $t ) ) {
			CN_Webhook::registrar_log_mp( 'alerta_hola_demorada', array( 'clave' => (string) $clave ) );
			return false;
		}
		set_transient( $t, 1, self::ALERTA_SEG );
		$cuerpo = implode( "\n", (array) $lineas ) . "\n\n" . admin_url( 'admin.php?page=cn-socias' ) . "\n";
		$ok     = false;
		try {
			$ok = (bool) wp_mail( self::email_alerta(), '[Club Natureza] ' . (string) $asunto, $cuerpo );
		} catch ( Throwable $e ) {
			$ok = false;
		}
		CN_Webhook::registrar_log_mp( $ok ? 'alerta_hola_enviada' : 'alerta_hola_fallo', array( 'clave' => (string) $clave, 'asunto' => (string) $asunto ) );
		return $ok;
	}

	// ---------------------------------------------------------------- Config
	/** Guarda los flags que vinieron en el POST de Config (solo valores de la lista cerrada). */
	public static function guardar_config( $post ) {
		foreach ( array_keys( self::flags() ) as $opt ) {
			if ( isset( $post[ $opt ] ) ) {
				$v = sanitize_key( wp_unslash( $post[ $opt ] ) );
				update_option( $opt, in_array( $v, self::MODOS, true ) ? $v : 'off' );
			}
		}
	}

	/** Filas de la tabla de Config. */
	public static function filas_config() {
		foreach ( self::flags() as $opt => $texto ) {
			$m = self::modo( $opt );
			echo '<tr><th><label>' . esc_html( $texto ) . '</label></th><td><select name="' . esc_attr( $opt ) . '">';
			foreach ( array( 'off' => 'Apagado (como siempre)', 'log' => 'Solo registrar (no cambia nada)', 'on' => 'Encendido' ) as $v => $l ) {
				echo '<option value="' . esc_attr( $v ) . '"' . ( $v === $m ? ' selected' : '' ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select><p class="description"><code>' . esc_html( $opt ) . '</code>. Marcha atrás: Apagado.</p></td></tr>';
		}
	}
}
