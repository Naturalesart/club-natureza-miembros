<?php
/**
 * Pantallas del acceso con email + contraseña (PASO C): shortcodes [cn_acceso] y [cn_crear_password].
 *
 * Solo pantallas. La lógica (cuentas, topes, tokens, sesión) está en CN_Cuentas (PASO B). No manda mails
 * (PASO D) ni se engancha a las altas (PASO E).
 *
 *  - INERTE con cn_cuentas_modo distinto de 'on': los shortcodes no muestran nada, no se procesa ningún POST
 *    y [cn_login] queda idéntico al de siempre (el link nuevo solo aparece con el flag en 'on').
 *  - Las páginas las crea Leandro en WordPress (cada una con su shortcode). Las direcciones esperadas son
 *    /acceso-club/ y /crear-password/.
 *  - El token de un link viaja en la dirección (GET) solo para MOSTRAR una pantalla con un botón. Se gasta
 *    únicamente por POST (nonce + honeypot), así los escáneres de mails que abren los links no lo consumen.
 *  - Respuestas neutras (login fallido, "olvidé", "link a mi mail") con un piso de tiempo parecido.
 *  - Sin caché (DONOTCACHEPAGE + X-LiteSpeed-Cache-Control: no-cache + Alt-Svc: clear), sin Referer
 *    (el token no viaja a terceros) y sin indexar.
 *  - Cero WhatsApp: el contacto es responder el mail o escribir a hola@naturalesart.com.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CN_Acceso_Paginas {
	const SC_ACCESO   = 'cn_acceso';
	const SC_CREAR    = 'cn_crear_password';
	const SLUG_ACCESO = 'acceso-club';
	const SLUG_CREAR  = 'crear-password';
	const SLUG_CLUB   = 'club-natureza-miembros';
	const HONEYPOT    = 'cn_web';
	const MAX_CAMPO   = 254;
	const MAX_PASS    = 200; // se corta antes de hashear, para que nadie mande megabytes.

	/** Milisegundos mínimos que tarda una respuesta de login / pedido de link (iguala el tiempo con y sin cuenta). Los tests lo ponen en 0. */
	public static $piso_ms = 350;

	/** Resultado del POST de este request: array( 'tipo' => 'ok'|'error'|'entro'|'neutro'|'token', 'mensaje' => string, 'accion' => string ). */
	protected static $estado = null;

	public static function registrar() {
		add_shortcode( self::SC_ACCESO, array( __CLASS__, 'shortcode_acceso' ) );
		add_shortcode( self::SC_CREAR, array( __CLASS__, 'shortcode_crear' ) );
		add_action( 'template_redirect', array( __CLASS__, 'iniciar' ) );
	}

	public static function activo() {
		return class_exists( 'CN_Cuentas' ) && 'on' === CN_Cuentas::modo();
	}

	/* ------------------------------------------------------------------ direcciones */

	public static function url_acceso( $args = array() ) {
		return self::url( self::SLUG_ACCESO, $args );
	}
	public static function url_crear( $args = array() ) {
		return self::url( self::SLUG_CREAR, $args );
	}
	public static function url_club() {
		return home_url( '/' . self::SLUG_CLUB . '/' );
	}
	protected static function url( $slug, $args ) {
		$u = home_url( '/' . $slug . '/' );
		return $args ? add_query_arg( $args, $u ) : $u;
	}

	/** Link discreto para [cn_login]. Vacío salvo con el flag en 'on' (así [cn_login] queda igual que hoy). */
	public static function enlace_login() {
		if ( ! self::activo() ) {
			return '';
		}
		return '<p class="cn-acceso__enlace"><a href="' . esc_url( self::url_acceso() ) . '">Entrar con email y contraseña</a></p>';
	}

	/* ------------------------------------------------------------------ pedido: caché, headers y POST */

	/** Headers de las páginas del acceso: sin caché, sin Referer, sin indexar. */
	public static function headers_pagina() {
		return array(
			'X-LiteSpeed-Cache-Control: no-cache',
			'Alt-Svc: clear',
			'Referrer-Policy: no-referrer',
			'X-Robots-Tag: noindex, nofollow',
		);
	}

	/** template_redirect: en las páginas con alguno de los shortcodes, sin caché y procesa el POST (los headers aún no salieron). */
	public static function iniciar() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		$acceso = has_shortcode( $post->post_content, self::SC_ACCESO );
		$crear  = has_shortcode( $post->post_content, self::SC_CREAR );
		if ( ! $acceso && ! $crear ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			foreach ( self::headers_pagina() as $h ) {
				header( $h );
			}
		}
		self::procesar_post( $acceso ? 'acceso' : 'crear' );
	}

	protected static function post_texto( $clave, $max = self::MAX_CAMPO ) {
		$v = isset( $_POST[ $clave ] ) && is_string( $_POST[ $clave ] ) ? sanitize_text_field( wp_unslash( $_POST[ $clave ] ) ) : '';
		return substr( $v, 0, $max );
	}
	/** Contraseñas: tal cual (sin sanitizar ni recortar espacios), solo con tope de largo. */
	protected static function post_pass( $clave ) {
		$v = isset( $_POST[ $clave ] ) && is_string( $_POST[ $clave ] ) ? wp_unslash( $_POST[ $clave ] ) : '';
		return substr( $v, 0, self::MAX_PASS );
	}
	protected static function token_valido( $t ) {
		$t = strtolower( trim( (string) $t ) );
		return preg_match( '/^[a-f0-9]{64}$/', $t ) ? $t : '';
	}
	protected static function nonce_ok( $accion ) {
		$n = isset( $_POST['cn_acc_nonce'] ) && is_string( $_POST['cn_acc_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['cn_acc_nonce'] ) ) : '';
		return '' !== $n && wp_verify_nonce( $n, 'cn_acc_' . $accion );
	}

	/** Espera lo que falte para llegar al piso de tiempo. */
	protected static function igualar_tiempo( $desde ) {
		$falta = (int) ( self::$piso_ms * 1000 - ( microtime( true ) - $desde ) * 1000000 );
		if ( $falta > 0 ) {
			usleep( $falta );
		}
	}

	/**
	 * Procesa el POST de la página ('acceso' | 'crear'). No hace nada si el flag no está en 'on'. Deja el resultado
	 * en self::$estado para que el shortcode lo muestre. Público para poder probarlo.
	 */
	public static function procesar_post( $pagina ) {
		self::$estado = null;
		if ( ! self::activo() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		$accion = self::post_texto( 'cn_acc_accion', 20 );
		$permitidas = 'crear' === $pagina ? array( 'elegir' ) : array( 'login', 'olvide', 'link_pedir', 'link_entrar' );
		if ( ! in_array( $accion, $permitidas, true ) ) {
			return;
		}
		$t0 = microtime( true );
		if ( '' !== self::post_texto( self::HONEYPOT, 100 ) ) { // un robot llenó el campo escondido: se responde igual que siempre y no se hace nada.
			self::$estado = in_array( $accion, array( 'olvide', 'link_pedir' ), true )
				? array( 'tipo' => 'neutro', 'mensaje' => CN_Cuentas::MSG_LINK_NEUTRO, 'accion' => $accion )
				: array( 'tipo' => 'error', 'mensaje' => CN_Cuentas::MSG_LOGIN_INVALIDO, 'accion' => $accion );
			self::igualar_tiempo( $t0 );
			return;
		}
		if ( ! self::nonce_ok( $accion ) ) {
			self::$estado = array( 'tipo' => 'error', 'mensaje' => 'Hubo un problema, recargá la página e intentá de nuevo.', 'accion' => $accion );
			return;
		}
		switch ( $accion ) {
			case 'login':
				$r = CN_Cuentas::login( self::post_texto( 'cn_email' ), self::post_pass( 'cn_pass' ) );
				self::$estado = array( 'tipo' => $r['ok'] ? 'entro' : 'error', 'mensaje' => $r['mensaje'], 'accion' => $accion );
				self::igualar_tiempo( $t0 );
				break;
			case 'olvide':
			case 'link_pedir':
				$r = CN_Cuentas::pedir_link( self::post_texto( 'cn_email' ), 'olvide' === $accion ? 'recuperar' : 'entrar' );
				self::$estado = array( 'tipo' => 'neutro', 'mensaje' => CN_Cuentas::MSG_LINK_NEUTRO, 'accion' => $accion );
				self::igualar_tiempo( $t0 );
				break;
			case 'link_entrar':
				$r = CN_Cuentas::entrar_con_link( self::token_valido( self::post_texto( 'cn_tok', 80 ) ) );
				self::$estado = array( 'tipo' => $r['ok'] ? 'entro' : ( 'token' === $r['motivo'] ? 'token' : 'error' ), 'mensaje' => $r['mensaje'], 'accion' => $accion );
				break;
			case 'elegir':
				$p = self::post_texto( 'cn_p', 12 );
				$r = CN_Cuentas::elegir_password( self::token_valido( self::post_texto( 'cn_tok', 80 ) ), $p, self::post_pass( 'cn_pass' ), self::post_pass( 'cn_pass2' ) );
				self::$estado = array( 'tipo' => $r['ok'] ? 'entro' : ( 'token' === $r['motivo'] ? 'token' : 'error' ), 'mensaje' => $r['mensaje'], 'accion' => $accion );
				break;
		}
	}

	/* ------------------------------------------------------------------ piezas de HTML */

	protected static function envolver( $html ) {
		wp_enqueue_style( 'cn-style' );
		return '<div class="cn-wrap cn-acceso">' . $html . '</div>';
	}
	protected static function caja( $texto, $tipo ) {
		return '<div class="cn-alerta cn-alerta--' . esc_attr( $tipo ) . '" role="' . ( 'error' === $tipo ? 'alert' : 'status' ) . '">' . esc_html( $texto ) . '</div>';
	}
	protected static function campos_ocultos( $accion, $extra = array() ) {
		ob_start();
		wp_nonce_field( 'cn_acc_' . $accion, 'cn_acc_nonce' );
		$h = ob_get_clean();
		$h .= '<input type="hidden" name="cn_acc_accion" value="' . esc_attr( $accion ) . '">';
		foreach ( $extra as $k => $v ) {
			$h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		// Honeypot: escondido para las personas (y para lectores de pantalla); un robot lo completa.
		$h .= '<div class="cn-acceso__trampa" aria-hidden="true"><label for="cn_web">No completar este campo</label>'
			. '<input type="text" id="cn_web" name="' . self::HONEYPOT . '" value="" tabindex="-1" autocomplete="off"></div>';
		return $h;
	}
	protected static function campo_email( $valor = '' ) {
		return '<div class="cn-campo"><label for="cn_email">Tu email</label>'
			. '<input type="email" id="cn_email" name="cn_email" value="' . esc_attr( $valor ) . '" required maxlength="' . self::MAX_CAMPO . '" autocomplete="username" inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false"></div>';
	}
	/** Campo de contraseña con botón Mostrar/Ocultar (el botón aparece solo si hay JavaScript). */
	protected static function campo_pass( $id, $etiqueta, $autocomplete ) {
		return '<div class="cn-campo"><label for="' . esc_attr( $id ) . '">' . esc_html( $etiqueta ) . '</label>'
			. '<div class="cn-acceso__pass"><input type="password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" required maxlength="' . self::MAX_PASS . '" autocomplete="' . esc_attr( $autocomplete ) . '" autocapitalize="none" autocorrect="off" spellcheck="false">'
			. '<button type="button" class="cn-acceso__ver" data-cn-ver="' . esc_attr( $id ) . '" aria-pressed="false" hidden>Mostrar</button></div></div>';
	}
	/** JavaScript mínimo para mostrar/ocultar las contraseñas. Sin él, el campo funciona igual. */
	protected static function script_ver() {
		return '<script>(function(){document.querySelectorAll("[data-cn-ver]").forEach(function(b){var i=document.getElementById(b.getAttribute("data-cn-ver"));if(!i){return;}b.hidden=false;b.addEventListener("click",function(){var v=i.type==="password";i.type=v?"text":"password";b.textContent=v?"Ocultar":"Mostrar";b.setAttribute("aria-pressed",v?"true":"false");});});})();</script>';
	}
	protected static function formulario( $titulo, $accion, $cuerpo, $boton, $extra = array() ) {
		return '<form class="cn-form cn-acceso__form" method="post" action="' . esc_url( self::url_de_accion( $accion ) ) . '" autocomplete="on">'
			. '<h2 class="cn-form__titulo">' . esc_html( $titulo ) . '</h2>'
			. $cuerpo
			. self::campos_ocultos( $accion, $extra )
			. '<button type="submit" class="cn-boton">' . esc_html( $boton ) . '</button>'
			. '</form>';
	}
	/** Los formularios mandan a la página sin parámetros: el token va en un campo oculto, no en la dirección. */
	protected static function url_de_accion( $accion ) {
		return 'elegir' === $accion ? self::url_crear() : self::url_acceso();
	}
	protected static function enlace( $url, $texto ) {
		return '<p class="cn-acceso__enlace"><a href="' . esc_url( $url ) . '">' . esc_html( $texto ) . '</a></p>';
	}
	protected static function boton_club( $texto = 'Ir al Club →' ) {
		return '<p><a class="cn-boton" href="' . esc_url( self::url_club() ) . '">' . esc_html( $texto ) . '</a></p>';
	}

	/* ------------------------------------------------------------------ [cn_acceso] */

	public static function shortcode_acceso( $atts = array() ) {
		if ( ! self::activo() ) {
			return '';
		}
		$e = self::$estado;
		if ( $e && 'entro' === $e['tipo'] ) {
			return self::envolver( self::caja( '¡Listo! Ya estás adentro del Club.', 'success' ) . self::boton_club() );
		}
		if ( $e && 'neutro' === $e['tipo'] ) {
			return self::envolver( self::caja( $e['mensaje'], 'success' ) . self::enlace( self::url_acceso(), '← Volver' ) );
		}
		$miembro = CN_Auth::get_miembro_actual();
		if ( $miembro && ! ( $e && 'error' === $e['tipo'] ) ) {
			return self::envolver( self::caja( 'Ya estás adentro del Club.', 'success' ) . self::boton_club() );
		}

		$aviso = $e && in_array( $e['tipo'], array( 'error', 'token' ), true ) ? self::caja( $e['mensaje'], 'error' ) : '';
		$tok   = self::token_valido( isset( $_GET['cn_tok'] ) && is_string( $_GET['cn_tok'] ) ? sanitize_text_field( wp_unslash( $_GET['cn_tok'] ) ) : '' );
		$prop  = isset( $_GET['cn_p'] ) && is_string( $_GET['cn_p'] ) ? sanitize_key( wp_unslash( $_GET['cn_p'] ) ) : '';
		$vista = isset( $_GET['cn_vista'] ) && is_string( $_GET['cn_vista'] ) ? sanitize_key( wp_unslash( $_GET['cn_vista'] ) ) : '';
		if ( $e && in_array( $e['accion'], array( 'olvide', 'link_pedir' ), true ) ) {
			$vista = 'olvide' === $e['accion'] ? 'olvide' : 'link';
		}

		// Link del mail. GET: solo se MIRA el token (no se gasta); el botón lo gasta por POST.
		if ( '' !== $tok && 'entrar' === $prop && ! ( $e && 'token' === $e['tipo'] ) ) {
			$r = CN_Cuentas::revisar_token( $tok, 'entrar' );
			if ( $r['ok'] ) {
				return self::envolver( $aviso . self::formulario( 'Entrar al Club', 'link_entrar',
					'<p>Tocá el botón para entrar. Este link se usa una sola vez.</p>', 'Entrar al Club', array( 'cn_tok' => $tok ) ) );
			}
			$aviso = self::caja( CN_Cuentas::MSG_TOKEN_INVALIDO, 'error' );
			$vista = 'link';
		}
		if ( '' !== $tok && in_array( $prop, array( 'crear', 'recuperar' ), true ) && ! ( $e && 'token' === $e['tipo'] ) ) {
			return self::vista_password( $tok, $prop, $aviso );
		}
		if ( $e && 'token' === $e['tipo'] ) {
			$vista = 'olvide';
		}

		if ( 'olvide' === $vista ) {
			return self::envolver( $aviso . self::formulario( 'Recuperar mi contraseña', 'olvide',
				'<p>Escribí tu email y te mandamos un link para elegir una contraseña nueva.</p>' . self::campo_email(), 'Mandame el link' )
				. self::enlace( self::url_acceso(), '← Volver' ) );
		}
		if ( 'link' === $vista ) {
			return self::envolver( $aviso . self::formulario( 'Entrar con un link a mi mail', 'link_pedir',
				'<p>Escribí tu email y te mandamos un link para entrar sin contraseña.</p>' . self::campo_email(), 'Mandame el link' )
				. self::enlace( self::url_acceso(), '← Volver' ) );
		}

		return self::envolver( $aviso . self::formulario( 'Ingresá al Club Natureza', 'login',
				self::campo_email() . self::campo_pass( 'cn_pass', 'Contraseña', 'current-password' ), 'Entrar' )
			. self::enlace( self::url_acceso( array( 'cn_vista' => 'olvide' ) ), 'Olvidé mi contraseña' )
			. self::enlace( self::url_acceso( array( 'cn_vista' => 'link' ) ), 'Entrar con un link a mi mail' )
			. self::enlace( self::url_club(), 'Entrar con mi nombre y mi celular' )
			. self::script_ver() );
	}

	/* ------------------------------------------------------------------ [cn_crear_password] */

	public static function shortcode_crear( $atts = array() ) {
		if ( ! self::activo() ) {
			return '';
		}
		$e = self::$estado;
		if ( $e && 'entro' === $e['tipo'] ) {
			return self::envolver( self::caja( '¡Listo! Tu contraseña quedó guardada y ya estás adentro del Club.', 'success' ) . self::boton_club() );
		}
		$tok  = self::token_valido( isset( $_POST['cn_tok'] ) && is_string( $_POST['cn_tok'] ) ? sanitize_text_field( wp_unslash( $_POST['cn_tok'] ) ) : ( isset( $_GET['cn_tok'] ) && is_string( $_GET['cn_tok'] ) ? sanitize_text_field( wp_unslash( $_GET['cn_tok'] ) ) : '' ) );
		$prop = isset( $_POST['cn_p'] ) && is_string( $_POST['cn_p'] ) ? sanitize_key( wp_unslash( $_POST['cn_p'] ) ) : ( isset( $_GET['cn_p'] ) && is_string( $_GET['cn_p'] ) ? sanitize_key( wp_unslash( $_GET['cn_p'] ) ) : 'crear' );
		$prop = in_array( $prop, array( 'crear', 'recuperar' ), true ) ? $prop : 'crear';
		$aviso = $e && 'error' === $e['tipo'] ? self::caja( $e['mensaje'], 'error' ) : '';
		if ( $e && 'token' === $e['tipo'] ) {
			return self::sin_link( $e['mensaje'] );
		}
		return self::vista_password( $tok, $prop, $aviso );
	}

	/** Pantalla para elegir contraseña. GET: mira el token sin gastarlo; el botón lo gasta por POST. */
	protected static function vista_password( $tok, $prop, $aviso = '' ) {
		if ( '' === $tok ) {
			return self::sin_link( CN_Cuentas::MSG_TOKEN_INVALIDO );
		}
		$r = CN_Cuentas::revisar_token( $tok, $prop );
		if ( ! $r['ok'] ) {
			return self::sin_link( CN_Cuentas::MSG_TOKEN_INVALIDO );
		}
		$titulo = 'recuperar' === $prop ? 'Elegí tu contraseña nueva' : 'Elegí tu contraseña';
		$cuerpo = '<p>Al menos ' . (int) CN_Cuentas::PASS_MIN . ' caracteres. No puede ser tu email ni tu celular.</p>'
			. self::campo_pass( 'cn_pass', 'Contraseña', 'new-password' )
			. self::campo_pass( 'cn_pass2', 'Repetí la contraseña', 'new-password' );
		return self::envolver( $aviso . self::formulario( $titulo, 'elegir', $cuerpo, 'Guardar y entrar', array( 'cn_tok' => $tok, 'cn_p' => $prop ) ) . self::script_ver() );
	}

	protected static function sin_link( $mensaje ) {
		return self::envolver( self::caja( $mensaje, 'error' ) . self::enlace( self::url_acceso( array( 'cn_vista' => 'olvide' ) ), 'Pedir un link nuevo' ) );
	}
}
