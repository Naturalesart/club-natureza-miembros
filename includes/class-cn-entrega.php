<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Etapas 4, 2B y 5 del alta — entrega del acceso. Cuatro flags off | log | on (default off = comportamiento de siempre):
 *
 *  cn_alta_reintento_modo  Registra el resultado del mail de bienvenida de cada alta "sub_" (bienvenida_mail_enviado /
 *                          bienvenida_mail_fallo / bienvenida_mail_reemplazado, con ficha_id y SIN email). Con 'on', si
 *                          falló reintenta por cron a los 10 min, hasta 3 veces; tras el 3.º fallo alerta a hola@.
 *  cn_retorno_activar_modo Plan B de activación, si el webhook no llegó (o se demora):
 *                          - página de vuelta de MP (/club-natureza-miembros/?preapproval_id=…, el back_url de las
 *                            suscripciones sub_): consulta MP y, si está autorizada, activa con la MISMA función del
 *                            webhook (transaccional e idempotente); muestra en pantalla cómo entrar (no depende del mail).
 *                          - sync: pendientes sub_ sin usar de más de 10 min → busca su suscripción autorizada en MP y activa.
 *                          Las dos respetan cn_sub_auto_activar (con el alta automática apagada no activan nada).
 *  cn_reenvio_acceso_modo  "No puedo entrar → reenviame el acceso" debajo del login (el login NO cambia: nombre + apellido
 *                          + celular). Manda los datos al email de la ficha; rate limit por IP y por email; respuesta
 *                          siempre genérica (no dice si el email existe).
 *  cn_authorized_payment_modo  Avisos subscription_authorized_payment: hoy van a /v1/payments/<id> y dan 404 (ese id es de
 *                          un cobro de suscripción, no de un pago). Con 'on' se consulta /authorized_payments/<id> y se
 *                          procesa el pago real que trae; con 'log' solo registra; 'off' = como siempre.
 *
 * Mercado Pago: solo GET. Nunca cancela, pausa ni cobra. Sin datos personales en mp_log.
 */
class CN_Entrega {
	const OPT_REINTENTO = 'cn_alta_reintento_modo';
	const OPT_RETORNO   = 'cn_retorno_activar_modo';
	const OPT_REENVIO   = 'cn_reenvio_acceso_modo';
	const OPT_AUTHPAY   = 'cn_authorized_payment_modo';
	const OPT_PEND_MAIL = 'cn_bienvenida_pendientes'; // ficha_id => array( 'intentos' => n, 'plan' => ... )
	const CRON          = 'cn_bienvenida_reintento';
	const REINTENTO_SEG = 600;
	const MAX_REINTENTOS = 3;
	const SYNC_MIN_SEG  = 600;    // una pendiente se mira en el sync recién a los 10 min (antes, el webhook todavía puede llegar)
	const SYNC_MAX_SEG  = 604800; // y hasta 7 días
	const MAX_RETORNO_IP = 10;    // consultas a MP desde la página de vuelta, por IP cada 10 min
	const MAX_REENVIO_IP = 3;     // pedidos de reenvío por IP cada 10 min
	const REENVIO_EMAIL_SEG = 3600; // un reenvío por email por hora
	const VENTANA       = 600;
	const MSG_REENVIO   = 'Si ese email está registrado en el Club, te mandamos tus datos para entrar. Revisá también la carpeta de spam.';
	const MSG_ACTIVA    = '¡Listo! Tu suscripción está activa. Para entrar escribí tu nombre y apellido y tu celular acá abajo. También te mandamos un mail con tus datos.';
	const MSG_CONFIRMANDO = 'Estamos confirmando tu pago con Mercado Pago. En unos minutos vas a poder entrar con tu nombre y apellido y tu celular.';

	public static function registrar() {
		add_action( self::CRON, array( __CLASS__, 'reintentar' ) );
	}

	public static function modo( $opt ) {
		return CN_Flujo_Alta::modo( $opt );
	}

	// ================================================================ 1. mail de bienvenida: registro + reintento
	/**
	 * Lo llama CN_Webhook::sub_post_alta() después de intentar el mail. $resultado: true (wp_mail ok) | false (falló o
	 * no hay email) | null (lo reemplazó el mail de cuenta, cn_cuentas_modo = on). Nunca lanza.
	 */
	public static function bienvenida_resultado( $ficha_id, $resultado, $plan, $motivo = '', $intento = 0 ) {
		$modo = self::modo( self::OPT_REINTENTO );
		if ( 'off' === $modo || (int) $ficha_id <= 0 ) {
			return;
		}
		$base = array( 'ficha_id' => (int) $ficha_id, 'intento' => (int) $intento );
		if ( null === $resultado ) {
			CN_Webhook::registrar_log_mp( 'bienvenida_mail_reemplazado', $base );
			self::quitar( $ficha_id );
			return;
		}
		if ( true === $resultado ) {
			CN_Webhook::registrar_log_mp( 'bienvenida_mail_enviado', $base );
			self::quitar( $ficha_id );
			return;
		}
		CN_Webhook::registrar_log_mp( 'bienvenida_mail_fallo', $base + ( '' !== $motivo ? array( 'motivo' => (string) $motivo ) : array() ) );
		if ( 'sin_email' === $motivo ) {
			// Sin email no hay a quién reintentar: la página de vuelta y el login igual le muestran cómo entrar.
			CN_Flujo_Alta::alertar( 'bienvenida_sin_email|' . (int) $ficha_id, 'Alta sin email para el mail de bienvenida (ficha ' . (int) $ficha_id . ')', array(
				'La ficha ' . (int) $ficha_id . ' quedó activa pero no tiene email: no se pudo mandar el mail de bienvenida.',
				'Puede entrar igual con nombre y apellido + celular. Si hace falta, escribile.',
			) );
			return;
		}
		if ( 'on' !== $modo ) {
			return;
		}
		if ( (int) $intento >= self::MAX_REINTENTOS ) {
			self::quitar( $ficha_id );
			CN_Flujo_Alta::alertar( 'bienvenida_fallo|' . (int) $ficha_id, 'El mail de bienvenida no salió (ficha ' . (int) $ficha_id . ')', array(
				'El mail de bienvenida de la ficha ' . (int) $ficha_id . ' falló ' . ( (int) $intento + 1 ) . ' veces (el envío y ' . (int) $intento . ' reintentos).',
				'La suscripción y el acceso están bien: solo falta que le lleguen los datos. Revisá el envío de mails del sitio.',
			) );
			return;
		}
		$m                     = self::pendientes();
		$m[ (int) $ficha_id ] = array( 'intentos' => (int) $intento, 'plan' => (string) $plan );
		update_option( self::OPT_PEND_MAIL, array_slice( $m, -200, null, true ), false );
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + self::REINTENTO_SEG, self::CRON, array( (int) $ficha_id ) );
		}
	}

	protected static function pendientes() {
		$m = get_option( self::OPT_PEND_MAIL, array() );
		return is_array( $m ) ? $m : array();
	}

	protected static function quitar( $ficha_id ) {
		$m = self::pendientes();
		if ( isset( $m[ (int) $ficha_id ] ) ) {
			unset( $m[ (int) $ficha_id ] );
			update_option( self::OPT_PEND_MAIL, $m, false );
		}
	}

	/** Cron (10 min después de un fallo): reintenta el mail de bienvenida si sigue pendiente. */
	public static function reintentar( $ficha_id ) {
		global $wpdb;
		$ficha_id = (int) $ficha_id;
		$m        = self::pendientes();
		if ( 'on' !== self::modo( self::OPT_REINTENTO ) || ! isset( $m[ $ficha_id ] ) ) {
			return; // ya salió, se apagó el flag o nunca estuvo pendiente: nada que hacer (idempotente).
		}
		$intento = (int) $m[ $ficha_id ]['intentos'] + 1;
		$plan    = (string) $m[ $ficha_id ]['plan'];
		$f       = $wpdb->get_row( $wpdb->prepare( 'SELECT id, nombre_apellido, email, celular_texto_plano, estado FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE id = %d', $ficha_id ) );
		if ( ! $f || 'activo' !== (string) $f->estado ) {
			self::quitar( $ficha_id );
			CN_Webhook::registrar_log_mp( 'bienvenida_reintento_omitido', array( 'ficha_id' => $ficha_id, 'motivo' => $f ? 'no_activa' : 'sin_ficha' ) );
			return;
		}
		$email = trim( (string) $f->email );
		if ( '' === $email ) {
			self::quitar( $ficha_id );
			self::bienvenida_resultado( $ficha_id, false, $plan, 'sin_email', $intento );
			return;
		}
		$planes = CN_MP::get_planes();
		$res    = CN_Webhook::mail_bienvenida_sub( (string) $f->nombre_apellido, (string) $f->celular_texto_plano, $email, isset( $planes[ $plan ] ) ? (string) $planes[ $plan ]['nombre'] : 'Club Natureza' );
		self::bienvenida_resultado( $ficha_id, $res, $plan, '', $intento );
	}

	// ================================================================ 2. plan B de activación
	/**
	 * Activa una suscripción "sub_" consultando MP (no depende del webhook). Misma función que el webhook
	 * (CN_Webhook::activar_sub_publico → activar_por_suscripcion_sub): transaccional e idempotente. Solo con
	 * cn_sub_auto_activar = '1'.
	 *
	 * @return string activa | confirmando | '' (no aplica: id inválido, no es sub_, flag apagado o MP no respondió)
	 */
	public static function activar_por_preapproval( $preapproval_id, $origen, $data = null ) {
		$modo = self::modo( self::OPT_RETORNO );
		$id   = strtolower( trim( (string) $preapproval_id ) );
		if ( 'off' === $modo || ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return '';
		}
		if ( null === $data ) {
			$data = CN_MP::obtener_preapproval( $id );
		}
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			return '';
		}
		$ref = isset( $data['external_reference'] ) ? (string) $data['external_reference'] : '';
		if ( 0 !== strpos( $ref, 'sub_' ) ) {
			return '';
		}
		$ya = self::ficha_con_preapproval( $id );
		if ( 'authorized' !== $data['status'] ) {
			return $ya ? 'activa' : 'confirmando';
		}
		if ( $ya ) {
			return 'activa'; // el webhook (u otra vuelta) ya activó: no se hace nada (idempotencia).
		}
		if ( 'on' !== $modo || '1' !== (string) get_option( 'cn_sub_auto_activar', '0' ) ) {
			CN_Webhook::registrar_log_mp( 'activar_plan_b_log', array( 'origen' => (string) $origen, 'haria' => 'activar', 'auto_activar' => '1' === (string) get_option( 'cn_sub_auto_activar', '0' ) ) );
			return 'confirmando';
		}
		CN_Webhook::activar_sub_publico( $ref, $id );
		$ficha = self::ficha_con_preapproval( $id );
		CN_Webhook::registrar_log_mp( 'activar_plan_b', array( 'origen' => (string) $origen, 'ficha_id' => $ficha ? $ficha : null ) );
		return $ficha ? 'activa' : 'confirmando';
	}

	/** Id de la ficha con ese preapproval_id; 0 si no hay o la base falló. */
	protected static function ficha_con_preapproval( $id ) {
		global $wpdb;
		$f = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE preapproval_id = %s LIMIT 1', $id ) );
		return '' === (string) $wpdb->last_error && $f ? (int) $f : 0;
	}

	/** Página de vuelta de MP: mensaje para mostrar arriba del login ('' = nada). Rate limit por IP. */
	public static function mensaje_retorno( $get ) {
		if ( 'off' === self::modo( self::OPT_RETORNO ) || ! isset( $get['preapproval_id'] ) || ! is_string( $get['preapproval_id'] ) ) {
			return '';
		}
		$clave = 'cn_retorno_b_' . md5( CN_Helpers::get_client_ip() );
		$n     = (int) get_transient( $clave );
		if ( $n >= self::MAX_RETORNO_IP ) {
			return self::MSG_CONFIRMANDO;
		}
		set_transient( $clave, $n + 1, self::VENTANA );
		$caso = self::activar_por_preapproval( wp_unslash( $get['preapproval_id'] ), 'retorno' );
		if ( 'activa' === $caso ) {
			return self::MSG_ACTIVA;
		}
		return 'confirmando' === $caso ? self::MSG_CONFIRMANDO : '';
	}

	/**
	 * Sync: pendientes sub_ sin usar de entre 10 min y 7 días → su suscripción autorizada en MP → activar. Sin pendientes
	 * no consulta a MP. Devuelve un resumen (también va a mp_log como sync_activar).
	 */
	public static function sync_activar() {
		global $wpdb;
		$res = array( 'pendientes' => 0, 'encontradas' => 0, 'activadas' => 0 );
		if ( 'off' === self::modo( self::OPT_RETORNO ) ) {
			return $res;
		}
		$hasta = gmdate( 'Y-m-d H:i:s', time() - self::SYNC_MIN_SEG );
		$desde = gmdate( 'Y-m-d H:i:s', time() - self::SYNC_MAX_SEG );
		$refs  = $wpdb->get_col( $wpdb->prepare(
			'SELECT external_reference FROM ' . CN_DB::tabla( 'suscripciones_pendientes' ) . ' WHERE usado = 0 AND creado <= %s AND creado >= %s ORDER BY id DESC LIMIT 50',
			$hasta,
			$desde
		) );
		if ( '' !== (string) $wpdb->last_error || ! $refs ) {
			return $res;
		}
		$refs              = array_flip( array_map( 'strval', $refs ) );
		$res['pendientes'] = count( $refs );
		for ( $pag = 0; $pag < 4; $pag++ ) {
			$r = CN_MP::listar_preapprovals( 'authorized', $pag * 100, 100 );
			if ( ! is_array( $r ) || ! isset( $r['results'] ) || ! is_array( $r['results'] ) ) {
				break; // MP no respondió: se reintenta en la próxima corrida.
			}
			foreach ( $r['results'] as $it ) {
				$ref = isset( $it['external_reference'] ) ? (string) $it['external_reference'] : '';
				if ( '' === $ref || ! isset( $refs[ $ref ] ) || empty( $it['id'] ) ) {
					continue;
				}
				$res['encontradas']++;
				if ( 'activa' === self::activar_por_preapproval( (string) $it['id'], 'sync', $it ) ) {
					$res['activadas']++;
				}
			}
			if ( count( $r['results'] ) < 100 ) {
				break;
			}
		}
		CN_Webhook::registrar_log_mp( 'sync_activar', $res + array( 'modo' => self::modo( self::OPT_RETORNO ) ) );
		return $res;
	}

	// ================================================================ 3. "No puedo entrar → reenviame el acceso"
	/** Procesa el pedido. Siempre devuelve el mismo mensaje genérico. */
	public static function reenvio( $post ) {
		global $wpdb;
		$modo = self::modo( self::OPT_REENVIO );
		if ( 'off' === $modo ) {
			return '';
		}
		if ( ! empty( $post['cn_reenvio_web'] ) ) {
			return self::MSG_REENVIO; // honeypot
		}
		$clave_ip = 'cn_reenvio_ip_' . md5( CN_Helpers::get_client_ip() );
		$n        = (int) get_transient( $clave_ip );
		if ( $n >= self::MAX_REENVIO_IP ) {
			CN_Webhook::registrar_log_mp( 'reenvio_acceso_limitado', array( 'por' => 'ip' ) );
			return self::MSG_REENVIO;
		}
		set_transient( $clave_ip, $n + 1, self::VENTANA );
		$email = isset( $post['cn_reenvio_email'] ) ? strtolower( trim( sanitize_email( trim( (string) wp_unslash( $post['cn_reenvio_email'] ) ) ) ) ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return self::MSG_REENVIO;
		}
		$clave_e = 'cn_reenvio_em_' . md5( $email );
		if ( get_transient( $clave_e ) ) {
			CN_Webhook::registrar_log_mp( 'reenvio_acceso_limitado', array( 'por' => 'email' ) );
			return self::MSG_REENVIO;
		}
		set_transient( $clave_e, 1, self::REENVIO_EMAIL_SEG );
		$fichas = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT id, nombre_apellido, email, celular_texto_plano, estado FROM ' . CN_DB::tabla( 'miembros' ) . " WHERE LOWER(TRIM(email)) = %s AND estado = 'activo'",
			$email
		) );
		if ( 1 !== count( $fichas ) ) {
			CN_Webhook::registrar_log_mp( 'reenvio_acceso_sin_match', array( 'cantidad' => count( $fichas ) ) );
			return self::MSG_REENVIO; // 0 o 2+: no se adivina; la respuesta es la misma.
		}
		$f = $fichas[0];
		if ( 'on' !== $modo ) {
			CN_Webhook::registrar_log_mp( 'reenvio_acceso_log', array( 'ficha_id' => (int) $f->id, 'haria' => 'enviar' ) );
			return self::MSG_REENVIO;
		}
		$ok = self::mail_acceso( (string) $f->nombre_apellido, (string) $f->celular_texto_plano, trim( (string) $f->email ) );
		CN_Webhook::registrar_log_mp( $ok ? 'reenvio_acceso_enviado' : 'reenvio_acceso_fallo', array( 'ficha_id' => (int) $f->id ) );
		return self::MSG_REENVIO;
	}

	/** Mail con los datos para entrar (mismo estilo que los demás). El celular solo si la ficha lo tiene en texto. */
	public static function mail_acceso( $nombre, $celular, $email ) {
		$login = home_url( '/club-natureza-miembros/' );
		$cel   = '' !== trim( (string) $celular ) ? esc_html( $celular ) : 'El mismo con el que te sumaste al Club';
		$c  = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f5f3ef;font-family:Georgia,serif;">';
		$c .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f3ef;padding:32px 16px;"><tr><td align="center">';
		$c .= '<table role="presentation" width="100%" style="max-width:480px;background:#ffffff;border-radius:12px;overflow:hidden;">';
		$c .= '<tr><td style="background:#2f3b2c;padding:28px 32px;text-align:center;"><span style="color:#f5f3ef;font-size:22px;letter-spacing:1px;">🌿 Club Natureza</span></td></tr>';
		$c .= '<tr><td style="padding:32px;">';
		$c .= '<h1 style="font-size:20px;color:#2f3b2c;margin:0 0 16px;">¡Hola, ' . esc_html( $nombre ) . '! 🌿</h1>';
		$c .= '<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 20px;">Estos son tus datos para entrar al Club:</p>';
		$c .= '<table role="presentation" width="100%" style="background:#f5f3ef;border-radius:8px;margin:0 0 24px;"><tr><td style="padding:18px 20px;">';
		$c .= '<p style="font-size:13px;color:#777;margin:0 0 4px;">NOMBRE Y APELLIDO</p><p style="font-size:16px;color:#2f3b2c;margin:0 0 14px;font-weight:bold;">' . esc_html( $nombre ) . '</p>';
		$c .= '<p style="font-size:13px;color:#777;margin:0 0 4px;">CELULAR</p><p style="font-size:16px;color:#2f3b2c;margin:0;font-weight:bold;">' . $cel . '</p>';
		$c .= '</td></tr></table>';
		$c .= '<p style="font-size:14px;color:#555;line-height:1.6;margin:0 0 24px;">Escribí tu nombre y apellido tal cual figura arriba.</p>';
		$c .= '<table role="presentation" width="100%"><tr><td align="center"><a href="' . esc_url( $login ) . '" style="display:inline-block;background:#2f3b2c;color:#f5f3ef;text-decoration:none;padding:14px 32px;border-radius:8px;font-size:15px;">Entrar al Club →</a></td></tr></table>';
		$c .= '<p style="font-size:13px;color:#777;line-height:1.6;margin:24px 0 0;">Si no lo pediste vos, ignorá este mail.</p>';
		$c .= '</td></tr></table></td></tr></table></body></html>';
		return (bool) wp_mail( $email, 'Tus datos para entrar al Club Natureza', $c, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/** Formulario "No puedo entrar" (debajo del login). Vacío con el flag en 'off'. */
	public static function form_reenvio() {
		if ( 'off' === self::modo( self::OPT_REENVIO ) ) {
			return '';
		}
		ob_start();
		?>
		<details class="cn-reenvio" style="margin:16px 0 0;">
			<summary style="cursor:pointer;">No puedo entrar</summary>
			<form class="cn-form" method="post" style="margin-top:12px;">
				<p>Escribí el email con el que te sumaste y te mandamos tus datos para entrar.</p>
				<div class="cn-campo">
					<label for="cn_reenvio_email">Email</label>
					<input type="email" id="cn_reenvio_email" name="cn_reenvio_email" required autocomplete="email" inputmode="email">
				</div>
				<div style="position:absolute;left:-9999px;" aria-hidden="true"><input type="text" name="cn_reenvio_web" tabindex="-1" autocomplete="off"></div>
				<?php wp_nonce_field( 'cn_reenvio', 'cn_reenvio_nonce' ); ?>
				<button type="submit" name="cn_reenvio_submit" value="1" class="cn-boton">Reenviame el acceso</button>
			</form>
		</details>
		<?php
		return ob_get_clean();
	}

	// ================================================================ 4. subscription_authorized_payment
	/**
	 * Aviso subscription_authorized_payment. Devuelve true si se hizo cargo (modo on); false = que el webhook siga como
	 * siempre (modo off, o log después de registrar).
	 */
	public static function authorized_payment( $id ) {
		$modo = self::modo( self::OPT_AUTHPAY );
		if ( 'off' === $modo ) {
			return false;
		}
		$pres = array( 'requests' => 3, 'espera' => 5, 'hasta' => time() + 20 );
		$r    = CN_MP::get_con_reintentos( '/authorized_payments/' . rawurlencode( (string) $id ), CN_MP::get_access_token(), $pres );
		$pago = ( $r['ok'] && isset( $r['data']['payment']['id'] ) ) ? (string) $r['data']['payment']['id'] : '';
		CN_Webhook::registrar_log_mp( 'authorized_payment', array( 'modo' => $modo, 'ok' => (bool) $r['ok'], 'http' => $r['http'], 'tiene_pago' => '' !== $pago, 'estado' => $r['ok'] ? (string) ( $r['data']['status'] ?? '' ) : '' ) );
		if ( 'on' !== $modo ) {
			return false;
		}
		if ( '' !== $pago && ctype_digit( $pago ) ) {
			CN_Webhook::procesar_payment_publico( $pago );
		}
		return true;
	}
}
