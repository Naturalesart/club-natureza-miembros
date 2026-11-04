<?php
/**
 * Mails de la cuenta del Club con email + contraseña (PASO D), armados sobre CN_Mail_Carta.
 *
 * Plantillas: bienvenida_trial, bienvenida_suscripcion, recuperar, entrar, recordatorio_24h, recordatorio_72h.
 * Todos con Reply-To hola@naturalesart.com, el remitente de siempre del plugin, un botón grande y un link de
 * texto de respaldo. Sin WhatsApp: el contacto es responder el mail o escribir a hola@naturalesart.com.
 *
 * Un único camino de envío: CN_Mail_Cuenta::enviar( $cuenta_id, $plantilla ).
 *   - cn_cuentas_modo = on  → arma y manda el mail (emite el token si no se le pasó uno).
 *   - cn_cuentas_modo = log → solo deja en mp_log qué mandaría (email enmascarado, SIN token).
 *   - cn_cuentas_modo = off → no hace nada.
 * El token real viaja únicamente dentro del mail: nunca en mp_log ni en ningún otro log.
 *
 * Recordatorios (24 h y 72 h) para cuentas que todavía no eligieron contraseña: cron propio de WordPress
 * (cn_cuentas_recordatorios, cada hora), que solo actúa con el modo en 'on'. Sin columnas nuevas: la marca
 * "ya se mandó" es una fila en cn_cuenta_tokens (CN_Cuentas::marca_tomar), así dos corridas no repiten el mail.
 *
 * Vista previa: acción de admin con destinatario FIJO en el código (naturalesartcursos@gmail.com), con datos
 * ficticios y un link que no abre nada. El destinatario no sale de la base ni de ningún parámetro.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CN_Mail_Cuenta {
	const REPLY_TO         = 'hola@naturalesart.com';
	const PREVIEW_DESTINO  = 'naturalesartcursos@gmail.com'; // fijo a propósito: la vista previa nunca le llega a nadie más.
	const CRON_HOOK        = 'cn_cuentas_recordatorios';
	const NONCE_PREVIEW    = 'cn_cuentas_vista_previa';
	const MAX_HORAS_RECORD = 336;  // una cuenta de más de 14 días ya no recibe recordatorios.
	const LOTE_RECORD      = 50;

	/** plantilla => propósito del token y marca de recordatorio. */
	const PLANTILLAS = array(
		'bienvenida_trial'       => array( 'proposito' => 'crear',     'marca' => '' ),
		'bienvenida_suscripcion' => array( 'proposito' => 'crear',     'marca' => '' ),
		'recuperar'              => array( 'proposito' => 'recuperar', 'marca' => '' ),
		'entrar'                 => array( 'proposito' => 'entrar',    'marca' => '' ),
		'recordatorio_24h'       => array( 'proposito' => 'crear',     'marca' => 'rec24' ),
		'recordatorio_72h'       => array( 'proposito' => 'crear',     'marca' => 'rec72' ),
	);

	const POSDATA_NO_PEDIDO = 'Si no lo pediste vos, ignorá este mail: tu cuenta sigue igual.';

	public static function registrar() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'recordatorios' ) );
		add_action( 'init', array( __CLASS__, 'programar_cron' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	/** Programa el cron solo con el modo en 'on' (con el flag apagado no se escribe nada). */
	public static function programar_cron() {
		if ( 'on' === CN_Cuentas::modo() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	/* ------------------------------------------------------------------ armado */

	protected static function primer_link( $proposito, $token ) {
		if ( 'entrar' === $proposito ) {
			return CN_Acceso_Paginas::url_acceso( array( 'cn_tok' => $token, 'cn_p' => 'entrar' ) );
		}
		return CN_Acceso_Paginas::url_crear( array( 'cn_tok' => $token, 'cn_p' => $proposito ) );
	}

	/**
	 * Arma un mail. $datos: nombre, email, token y (bienvenida de suscripción) plan.
	 * Devuelve array( 'asunto', 'html', 'texto', 'url' ).
	 */
	public static function armar( $plantilla, $datos ) {
		$nombre = trim( (string) ( $datos['nombre'] ?? '' ) );
		$email  = (string) ( $datos['email'] ?? '' );
		$token  = (string) ( $datos['token'] ?? '' );
		$plan   = (string) ( $datos['plan'] ?? '' );
		$prop   = self::PLANTILLAS[ $plantilla ]['proposito'];
		$url    = self::primer_link( $prop, $token );
		$acceso = CN_Acceso_Paginas::url_acceso();
		$posdata_crear = 'El botón vale 7 días. Si vence, entrá a ' . $acceso . ' y tocá «Olvidé mi contraseña». Si algo no te sale, respondé este mail y te ayudamos.';
		$saludo = '' !== $nombre ? $nombre : 'socia';

		switch ( $plantilla ) {
			case 'bienvenida_trial':
				$asunto = 'Bienvenida al Club Natureza 🌿 Elegí tu contraseña y empezá';
				$args   = array(
					'titulo'    => '¡Bienvenida, ' . $saludo . '! Tu semana de pintura empieza hoy',
					'preheader' => 'Elegí tu contraseña y entrá a tu primera clase.',
					'parrafos'  => array(
						'Ya tenés abierta tu semana de prueba: siete días para pintar a tu ritmo, sin apuro y sin compromiso.',
						'Para entrar, elegí tu contraseña con el botón de abajo.',
					),
					'destacado' => array( array( 'etiqueta' => 'Tu usuario', 'valor' => $email ) ),
					'boton'     => array( 'texto' => 'Elijo mi contraseña', 'url' => $url ),
					'cobro'     => 'trial',
					'posdata'   => $posdata_crear,
				);
				break;
			case 'bienvenida_suscripcion':
				$asunto  = '¡Ya sos socia del Club Natureza! 🌿 Elegí tu contraseña y empezá';
				$planes  = class_exists( 'CN_MP' ) ? CN_MP::get_planes() : array();
				$nombre_plan = ( '' !== $plan && 'basico' !== $plan && isset( $planes[ $plan ]['nombre'] ) ) ? (string) $planes[ $plan ]['nombre'] : '';
				$args    = array(
					'titulo'    => '¡Ya sos socia del Club Natureza!',
					'preheader' => 'Elegí tu contraseña y entrá al Club.',
					'parrafos'  => array(
						'Tu suscripción al Club Natureza' . ( '' !== $nombre_plan ? ' (' . $nombre_plan . ')' : '' ) . ' está activa. ¡Gracias por sumarte, ' . $saludo . '!',
						'Para entrar, elegí tu contraseña con el botón de abajo.',
					),
					'destacado' => array( array( 'etiqueta' => 'Tu usuario', 'valor' => $email ) ),
					'boton'     => array( 'texto' => 'Elijo mi contraseña', 'url' => $url ),
					'cobro'     => 'suscripcion',
					'posdata'   => $posdata_crear,
				);
				break;
			case 'recuperar':
				$asunto = 'Tu link para elegir una contraseña nueva';
				$args   = array(
					'titulo'    => 'Elegí tu contraseña nueva',
					'preheader' => 'Tocá el botón para elegir una contraseña nueva.',
					'parrafos'  => array(
						'Pediste cambiar tu contraseña del Club Natureza. Tocá el botón de abajo para elegir una nueva.',
						'El botón vale 60 minutos y se usa una sola vez.',
					),
					'boton'     => array( 'texto' => 'Elijo mi contraseña nueva', 'url' => $url ),
					'cobro'     => 'ninguna',
					'posdata'   => self::POSDATA_NO_PEDIDO,
				);
				break;
			case 'entrar':
				$asunto = 'Tu link para entrar al Club';
				$args   = array(
					'titulo'    => 'Tu link para entrar al Club',
					'preheader' => 'Tocá el botón y entrás, sin escribir contraseña.',
					'parrafos'  => array(
						'Tocá el botón de abajo para entrar al Club sin escribir tu contraseña.',
						'El botón vale 15 minutos y se usa una sola vez.',
					),
					'boton'     => array( 'texto' => 'Entrar al Club', 'url' => $url ),
					'cobro'     => 'ninguna',
					'posdata'   => self::POSDATA_NO_PEDIDO,
				);
				break;
			case 'recordatorio_24h':
				$asunto = 'Tu lugar en el Club te está esperando 🌿';
				$args   = array(
					'titulo'    => $saludo . ', tu lugar en el Club te está esperando',
					'preheader' => 'Solo falta que elijas tu contraseña.',
					'parrafos'  => array(
						'Vimos que todavía no elegiste tu contraseña, y es lo único que falta para que puedas entrar y pintar.',
						'Te dejamos un botón nuevo (el anterior ya no sirve). Cuando quieras, sin apuro.',
					),
					'boton'     => array( 'texto' => 'Elijo mi contraseña', 'url' => $url ),
					'cobro'     => 'ninguna',
					'posdata'   => 'El botón vale 7 días. Si algo no te sale, respondé este mail y te ayudamos.',
				);
				break;
			default: // recordatorio_72h
				$asunto = 'Tu lugar en el Club sigue guardado 🌿';
				$args   = array(
					'titulo'    => $saludo . ', tu lugar en el Club sigue guardado',
					'preheader' => 'Es el último recordatorio: solo falta tu contraseña.',
					'parrafos'  => array(
						'Tu lugar en el Club te está esperando. Solo falta que elijas tu contraseña para entrar.',
						'Este es el último recordatorio que te mandamos, para no llenarte la casilla. El botón de abajo es nuevo (el anterior ya no sirve).',
					),
					'boton'     => array( 'texto' => 'Elijo mi contraseña', 'url' => $url ),
					'cobro'     => 'ninguna',
					'posdata'   => 'El botón vale 7 días. Si necesitás una mano, respondé este mail y te ayudamos.',
				);
		}
		$args['respaldo'] = $url;
		$m                = CN_Mail_Carta::armar( $args );
		$m['asunto']      = $asunto;
		$m['url']         = $url;
		return $m;
	}

	protected static function headers() {
		return array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . self::REPLY_TO );
	}

	/* ------------------------------------------------------------------ envío */

	protected static function log( $tipo, $datos ) {
		if ( class_exists( 'CN_Webhook' ) ) {
			CN_Webhook::registrar_log_mp( $tipo, $datos );
		}
	}

	/**
	 * Único camino de envío. Devuelve 'off' | 'log' | 'enviado' | 'error' | 'plantilla' | 'sin_cuenta'.
	 * $token: el que ya emitió CN_Cuentas (alta, "olvidé", "link a mi mail"); si viene vacío y el modo es 'on', se emite acá.
	 */
	public static function enviar( $cuenta_id, $plantilla, $token = '' ) {
		$modo = CN_Cuentas::modo();
		if ( 'off' === $modo ) {
			return 'off';
		}
		if ( ! isset( self::PLANTILLAS[ $plantilla ] ) ) {
			return 'plantilla';
		}
		$cuenta = CN_Cuentas::cuenta_por_id( (int) $cuenta_id );
		if ( ! $cuenta ) {
			return 'sin_cuenta';
		}
		$email = (string) $cuenta->email;
		if ( 'log' === $modo ) {
			self::log( 'cuenta_mail_log', array( 'cuenta_id' => (int) $cuenta->id, 'plantilla' => $plantilla, 'email' => CN_Cuentas::mascara( $email ), 'haria' => 'enviar_mail' ) );
			return 'log';
		}
		if ( '' === (string) $token ) {
			$token = CN_Cuentas::emitir_token( (int) $cuenta->id, self::PLANTILLAS[ $plantilla ]['proposito'] );
		}
		if ( '' === (string) $token ) {
			self::log( 'cuenta_mail_error', array( 'cuenta_id' => (int) $cuenta->id, 'plantilla' => $plantilla, 'paso' => 'token' ) );
			return 'error';
		}
		if ( ! class_exists( 'CN_Mail_Carta' ) || ! class_exists( 'CN_Acceso_Paginas' ) ) { // el deploy por SFTP sube archivo por archivo: sin las piezas no se arma nada.
			self::log( 'cuenta_mail_error', array( 'cuenta_id' => (int) $cuenta->id, 'plantilla' => $plantilla, 'paso' => 'sin_clases' ) );
			return 'error';
		}
		$ficha = CN_Cuentas::ficha( (int) $cuenta->miembro_id );
		$m     = self::armar( $plantilla, array(
			'nombre' => $ficha ? (string) $ficha->nombre_apellido : '',
			'email'  => $email,
			'token'  => (string) $token,
			'plan'   => $ficha && isset( $ficha->plan ) ? (string) $ficha->plan : '',
		) );
		$ok = wp_mail( $email, $m['asunto'], $m['html'], self::headers() );
		self::log( $ok ? 'cuenta_mail_enviado' : 'cuenta_mail_error', array( 'cuenta_id' => (int) $cuenta->id, 'plantilla' => $plantilla ) );
		return $ok ? 'enviado' : 'error';
	}

	/* ------------------------------------------------------------------ recordatorios */

	/**
	 * Cron cada hora. Solo con el modo en 'on'. A las 24 h manda el primer recordatorio y a las 72 h el segundo; nunca
	 * más de uno por cuenta y corrida, nunca repetido, nunca a quien ya tiene contraseña, y tampoco a quien igual
	 * no podría entrar (ficha pausada, cancelada o trial vencido). Devuelve cuántos mandó: array( 'rec24' => n, 'rec72' => n ).
	 */
	public static function recordatorios() {
		$res = array( 'rec24' => 0, 'rec72' => 0 );
		if ( 'on' !== CN_Cuentas::modo() ) {
			return $res;
		}
		$tandas = array(
			array( 'recordatorio_72h', 'rec72', CN_Cuentas::hace_horas( self::MAX_HORAS_RECORD ), CN_Cuentas::hace_horas( 72 ) ),
			array( 'recordatorio_24h', 'rec24', CN_Cuentas::hace_horas( 72 ), CN_Cuentas::hace_horas( 24 ) ),
		);
		foreach ( $tandas as $t ) {
			list( $plantilla, $marca, $desde, $hasta ) = $t;
			foreach ( CN_Cuentas::cuentas_sin_password( $desde, $hasta, self::LOTE_RECORD ) as $cuenta ) {
				try {
					if ( self::recordar( $cuenta, $plantilla, $marca ) ) {
						$res[ $marca ]++;
					}
				} catch ( Throwable $e ) {
					self::log( 'cuenta_recordatorio_error', array( 'cuenta_id' => (int) $cuenta->id, 'plantilla' => $plantilla, 'mensaje' => mb_substr( $e->getMessage(), 0, 200 ) ) );
				}
			}
		}
		return $res;
	}

	protected static function recordar( $cuenta, $plantilla, $marca ) {
		if ( CN_Cuentas::marca_existe( (int) $cuenta->id, $marca ) ) {
			return false;
		}
		$ficha = CN_Cuentas::ficha( (int) $cuenta->miembro_id );
		if ( ! $ficha || 'ok' !== CN_Acceso_Sesion::motivo_acceso( $ficha ) ) {
			return false;
		}
		if ( ! CN_Cuentas::marca_tomar( (int) $cuenta->id, $marca ) ) { // otra corrida se adelantó: no se repite.
			return false;
		}
		if ( 'enviado' !== self::enviar( (int) $cuenta->id, $plantilla ) ) {
			CN_Cuentas::marca_soltar( (int) $cuenta->id, $marca ); // no salió: el próximo ciclo lo reintenta.
			return false;
		}
		return true;
	}

	/* ------------------------------------------------------------------ vista previa (destinatario fijo) */

	/** Datos ficticios: nada de personas reales. El token no existe en la base, así que el link no abre nada. */
	public static function datos_ejemplo() {
		return array( 'nombre' => 'María Ejemplo', 'email' => 'maria.ejemplo@example.com', 'token' => hash( 'sha256', 'vista-previa-sin-valor' ), 'plan' => 'full' );
	}

	/** Manda UNA vista previa a naturalesartcursos@gmail.com. El destinatario es la constante: no hay parámetro para cambiarlo. */
	public static function enviar_vista_previa( $plantilla ) {
		if ( ! isset( self::PLANTILLAS[ $plantilla ] ) ) {
			return false;
		}
		$m = self::armar( $plantilla, self::datos_ejemplo() );
		return (bool) wp_mail( self::PREVIEW_DESTINO, '[VISTA PREVIA] ' . $m['asunto'], $m['html'], self::headers() );
	}

	public static function menu() {
		add_submenu_page( 'cn-socias', 'Mails de cuenta', 'Mails de cuenta', 'manage_options', 'cn-mails-cuenta', array( __CLASS__, 'pagina' ) );
	}

	public static function pagina() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>Mails de cuenta: vista previa</h1>';
		echo '<p>Manda <strong>un solo mail de ejemplo</strong> con datos ficticios a <code>' . esc_html( self::PREVIEW_DESTINO ) . '</code>. El destinatario es fijo y no se puede cambiar desde acá. El link del botón no abre nada.</p>';
		if ( isset( $_POST['cn_mc_plantilla'] ) && check_admin_referer( self::NONCE_PREVIEW, 'cn_mc_nonce' ) ) {
			$p  = sanitize_key( wp_unslash( $_POST['cn_mc_plantilla'] ) );
			$ok = self::enviar_vista_previa( $p );
			echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . '"><p>' . ( $ok ? 'Vista previa enviada.' : 'No se pudo enviar.' ) . '</p></div>';
		}
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_PREVIEW, 'cn_mc_nonce' );
		foreach ( array_keys( self::PLANTILLAS ) as $p ) {
			echo '<p><button type="submit" name="cn_mc_plantilla" value="' . esc_attr( $p ) . '" class="button">' . esc_html( $p ) . '</button></p>';
		}
		echo '</form></div>';
	}
}
