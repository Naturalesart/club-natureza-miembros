<?php
// PASO C: pantallas del acceso con email + contraseña ([cn_acceso], [cn_crear_password], link en [cn_login]).
// Usa los ayudantes de test_cuentas.php (ct_*), que corre antes por el orden alfabético.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
require_once dirname( __DIR__ ) . '/includes/class-cn-shortcodes.php';
foreach ( array( 'is_admin' => false, 'is_singular' => true ) as $f => $v ) {
	if ( ! function_exists( $f ) ) {
		eval( "function $f() { return " . var_export( $v, true ) . '; }' );
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post() {
		return $GLOBALS['ca_post'] ?? null;
	}
}
if ( ! function_exists( 'has_shortcode' ) ) {
	function has_shortcode( $contenido, $tag ) {
		return false !== strpos( (string) $contenido, '[' . $tag . ']' );
	}
}

class CA_Sc extends CN_Shortcodes {
	public static function form_login() {
		ob_start();
		self::render_form_login();
		return ob_get_clean();
	}
}

CN_Acceso_Paginas::$piso_ms = 0;

function ca_get( $pagina, $get = array() ) {
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_POST = array();
	$_GET  = $get;
	CN_Acceso_Paginas::procesar_post( $pagina );
}
function ca_post( $pagina, $accion, $campos = array() ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_GET  = array();
	$_POST = array_merge( array( 'cn_acc_accion' => $accion, 'cn_acc_nonce' => 'n' ), $campos );
	ct_q( function () use ( $pagina ) {
		CN_Acceso_Paginas::procesar_post( $pagina );
	} );
}
function ca_html( $sc ) {
	ob_start();
	$h = 'crear' === $sc ? CN_Acceso_Paginas::shortcode_crear() : CN_Acceso_Paginas::shortcode_acceso();
	ob_end_clean();
	return $h;
}
function ca_limpiar() {
	$_POST = array();
	$_GET  = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	unset( $GLOBALS['cn_nonce_ok'] );
}
/** Cuenta nueva con un token 'crear' pendiente (sin contraseña todavía). */
function ca_cuenta_nueva( $modo = 'on' ) {
	$db = ct_preparar( $modo );
	CN_Cuentas::provisionar( 10, 'trial' );
	return $db;
}
function ca_token_ok( $tok, $prop ) {
	$r = CN_Cuentas::revisar_token( $tok, $prop );
	return $r['ok'];
}

// ------------------------------------------------------------------ flag apagado: todo inerte
seccion( 'PASO C: con el flag apagado no se ve ni se procesa nada' );
foreach ( array( 'off', 'log' ) as $modo ) {
	ct_preparar( $modo );
	ca_limpiar();
	t( "modo $modo: [cn_acceso] no muestra nada", '' === CN_Acceso_Paginas::shortcode_acceso() );
	t( "modo $modo: [cn_crear_password] no muestra nada", '' === CN_Acceso_Paginas::shortcode_crear() );
	t( "modo $modo: el link de [cn_login] está vacío", '' === CN_Acceso_Paginas::enlace_login() );
	$antes = count( $GLOBALS['wpdb']->consultas );
	ca_post( 'acceso', 'login', array( 'cn_email' => 'a@b.com', 'cn_pass' => 'x' ) );
	t( "modo $modo: un POST no toca la base ni abre sesión", count( $GLOBALS['wpdb']->consultas ) === $antes && empty( $_COOKIE['cn_session'] ) );
}
ct_preparar( 'off' );
ca_limpiar();
$form_off = CA_Sc::form_login();
t( '[cn_login] con el flag apagado termina exactamente como hoy (</form> y salto de línea)', 1 === preg_match( '#</form>\r?\n\t\t$#', $form_off ) );
t( '[cn_login] apagado no menciona el acceso nuevo', false === strpos( $form_off, 'email y contraseña' ) );
t( 'CN_Auth: el login nombre + celular sigue disponible en el formulario', false !== strpos( $form_off, 'name="cn_nombre"' ) && false !== strpos( $form_off, 'name="cn_celular"' ) );

// ------------------------------------------------------------------ link en [cn_login]
seccion( 'PASO C: link discreto en [cn_login] solo con el flag en on' );
ct_preparar( 'on' );
$form_on = CA_Sc::form_login();
t( 'con on aparece el link a la página de acceso', false !== strpos( $form_on, 'href="https://naturalesart.com/acceso-club/"' ) || false !== strpos( $form_on, '/acceso-club/' ) );
t( 'con on el resto del formulario es el mismo', 0 === strpos( $form_on, substr( $form_off, 0, -2 ) ) );
t( 'el link no menciona WhatsApp', false === stripos( CN_Acceso_Paginas::enlace_login(), 'whatsapp' ) );

// ------------------------------------------------------------------ pantalla de acceso
seccion( 'PASO C: [cn_acceso] (login con email)' );
ca_cuenta_nueva();
ca_limpiar();
$h = ca_html( 'acceso' );
t( 'el formulario pide email y contraseña', false !== strpos( $h, 'name="cn_email"' ) && false !== strpos( $h, 'name="cn_pass"' ) && false !== strpos( $h, 'type="password"' ) );
t( 'tiene nonce, acción y honeypot', false !== strpos( $h, 'name="cn_acc_nonce"' ) && false !== strpos( $h, 'value="login"' ) && false !== strpos( $h, 'name="cn_web"' ) );
t( 'tiene el botón Mostrar/Ocultar contraseña', false !== strpos( $h, 'data-cn-ver="cn_pass"' ) && false !== strpos( $h, 'Mostrar' ) );
t( 'ofrece olvidé, link al mail y la entrada de siempre', false !== strpos( $h, 'cn_vista=olvide' ) && false !== strpos( $h, 'cn_vista=link' ) && false !== strpos( $h, 'nombre y mi celular' ) );
t( 'letras grandes y campos de email correctos (inputmode, autocomplete)', false !== strpos( $h, 'inputmode="email"' ) && false !== strpos( $h, 'autocomplete="current-password"' ) );
t( 'ninguna pantalla nombra WhatsApp ni wa.me', false === stripos( $h, 'whatsapp' ) && false === stripos( $h, 'wa.me' ) );

list( $db ) = ct_con_password( 'pinturas-del-sur' );
ca_limpiar();
ca_post( 'acceso', 'login', array( 'cn_email' => 'nueva.socia@example.com', 'cn_pass' => 'mala-clave-123' ) );
$h = ca_html( 'acceso' );
t( 'login con clave mala: mensaje neutro y sin sesión', false !== strpos( $h, CN_Cuentas::MSG_LOGIN_INVALIDO ) && empty( ct_sesiones_de( $db, 10 ) ) );
ca_post( 'acceso', 'login', array( 'cn_email' => 'no.existe@example.com', 'cn_pass' => 'mala-clave-123' ) );
t( 'email que no existe: exactamente el mismo mensaje', false !== strpos( ca_html( 'acceso' ), CN_Cuentas::MSG_LOGIN_INVALIDO ) );
ca_post( 'acceso', 'login', array( 'cn_email' => 'nueva.socia@example.com', 'cn_pass' => 'pinturas-del-sur' ) );
$h = ca_html( 'acceso' );
t( 'login correcto: abre la sesión de siempre (cookie cn_session)', 1 === count( ct_sesiones_de( $db, 10 ) ) && ! empty( $_COOKIE['cn_session'] ) );
t( 'login correcto: confirmación y botón al Club', false !== strpos( $h, 'Ya estás adentro' ) && false !== strpos( $h, '/club-natureza-miembros/' ) );

list( $db ) = ct_con_password( 'pinturas-del-sur' );
ca_limpiar();
ca_post( 'acceso', 'login', array( 'cn_email' => 'nueva.socia@example.com', 'cn_pass' => 'pinturas-del-sur', 'cn_web' => 'robot' ) );
t( 'honeypot lleno: no entra aunque la clave sea buena', empty( ct_sesiones_de( $db, 10 ) ) && false !== strpos( ca_html( 'acceso' ), CN_Cuentas::MSG_LOGIN_INVALIDO ) );
$GLOBALS['cn_nonce_ok'] = false;
ca_post( 'acceso', 'login', array( 'cn_email' => 'nueva.socia@example.com', 'cn_pass' => 'pinturas-del-sur' ) );
t( 'nonce inválido: no entra y pide recargar', empty( ct_sesiones_de( $db, 10 ) ) && false !== strpos( ca_html( 'acceso' ), 'recargá la página' ) );
unset( $GLOBALS['cn_nonce_ok'] );

// ficha pausada: texto propio, sin WhatsApp
list( $db ) = ct_con_password( 'pinturas-del-sur' );
$db->t['miembros'][0]['estado'] = 'pausado';
ca_limpiar();
ca_post( 'acceso', 'login', array( 'cn_email' => 'nueva.socia@example.com', 'cn_pass' => 'pinturas-del-sur' ) );
$h = ca_html( 'acceso' );
t( 'ficha pausada: no entra y el texto manda al mail o a hola@naturalesart.com', empty( ct_sesiones_de( $db, 10 ) ) && false !== strpos( $h, 'hola@naturalesart.com' ) );
t( 'ficha pausada: sin WhatsApp', false === stripos( $h, 'whatsapp' ) && false === stripos( $h, 'wa.me' ) );

// topes: al quinto fallo se avisa sin decir cuál tope saltó
list( $db ) = ct_con_password( 'pinturas-del-sur' );
ca_limpiar();
for ( $i = 0; $i < 5; $i++ ) {
	ca_post( 'acceso', 'login', array( 'cn_email' => 'nueva.socia@example.com', 'cn_pass' => 'mala-' . $i ) );
}
ca_post( 'acceso', 'login', array( 'cn_email' => 'nueva.socia@example.com', 'cn_pass' => 'pinturas-del-sur' ) );
$h = ca_html( 'acceso' );
t( 'tras 5 fallos ni la clave buena entra, y el aviso no dice si fue por IP o por email', empty( ct_sesiones_de( $db, 10 ) ) && false !== strpos( $h, CN_Cuentas::MSG_DEMASIADOS ) && ! preg_match( '/\bIP\b/', $h ) );

// ------------------------------------------------------------------ olvidé / link al mail
seccion( 'PASO C: olvidé mi contraseña y link a mi mail (respuesta neutra)' );
list( $db ) = ct_con_password( 'pinturas-del-sur' );
ca_limpiar();
ca_get( 'acceso', array( 'cn_vista' => 'olvide' ) );
$h = ca_html( 'acceso' );
t( 'la vista "olvidé" pide solo el email, con nonce y honeypot', false !== strpos( $h, 'value="olvide"' ) && false !== strpos( $h, 'name="cn_email"' ) && false === strpos( $h, 'name="cn_pass"' ) && false !== strpos( $h, 'name="cn_web"' ) );
ca_post( 'acceso', 'olvide', array( 'cn_email' => 'nueva.socia@example.com' ) );
$h_existe = ca_html( 'acceso' );
$avisos_1 = count( $GLOBALS['ct_avisos'] );
ca_post( 'acceso', 'olvide', array( 'cn_email' => 'fantasma@example.com' ) );
$h_no = ca_html( 'acceso' );
t( 'con y sin cuenta la respuesta es idéntica', $h_existe === $h_no && false !== strpos( $h_no, CN_Cuentas::MSG_LINK_NEUTRO ) );
t( 'con cuenta se pidió un link; sin cuenta no', 1 === $avisos_1 && 1 === count( $GLOBALS['ct_avisos'] ) );
t( 'la respuesta no deja ver el token', false === strpos( $h_existe, ct_ultimo_token( 'recuperar' ) ) );
ca_post( 'acceso', 'link_pedir', array( 'cn_email' => 'nueva.socia@example.com' ) );
t( '"link a mi mail": mismo mensaje neutro', false !== strpos( ca_html( 'acceso' ), CN_Cuentas::MSG_LINK_NEUTRO ) && '' !== ct_ultimo_token( 'entrar' ) );
ca_post( 'acceso', 'olvide', array( 'cn_email' => 'nueva.socia@example.com', 'cn_web' => 'robot' ) );
$n = count( $GLOBALS['ct_avisos'] );
t( 'honeypot lleno en "olvidé": mismo mensaje y no se emite nada', false !== strpos( ca_html( 'acceso' ), CN_Cuentas::MSG_LINK_NEUTRO ) && $n === count( $GLOBALS['ct_avisos'] ) );
// tope de pedidos por email: el 4.º no emite y responde igual
ct_preparar( 'on' );
list( $db ) = ct_con_password( 'pinturas-del-sur' );
ca_limpiar();
for ( $i = 0; $i < 4; $i++ ) {
	ca_post( 'acceso', 'olvide', array( 'cn_email' => 'nueva.socia@example.com' ) );
	$ultimo = ca_html( 'acceso' );
}
t( 'pedido 4 en la hora: no emite y la respuesta es la misma', 3 === count( $GLOBALS['ct_avisos'] ) && false !== strpos( $ultimo, CN_Cuentas::MSG_LINK_NEUTRO ) );
// piso de tiempo
CN_Acceso_Paginas::$piso_ms = 60;
$t0 = microtime( true );
ca_post( 'acceso', 'olvide', array( 'cn_email' => 'fantasma@example.com' ) );
t( 'el pedido sin cuenta tarda al menos el piso de tiempo', ( microtime( true ) - $t0 ) * 1000 >= 55 );
CN_Acceso_Paginas::$piso_ms = 0;

// ------------------------------------------------------------------ entrar con link: GET no gasta, POST sí
seccion( 'PASO C: el token se gasta solo por POST' );
list( $db ) = ct_con_password( 'pinturas-del-sur' );
ca_limpiar();
ca_post( 'acceso', 'link_pedir', array( 'cn_email' => 'nueva.socia@example.com' ) );
$tok = ct_ultimo_token( 'entrar' );
ca_get( 'acceso', array( 'cn_tok' => $tok, 'cn_p' => 'entrar' ) );
$h = ca_html( 'acceso' );
t( 'GET con link válido: muestra el botón "Entrar al Club" con el token oculto', false !== strpos( $h, 'value="link_entrar"' ) && false !== strpos( $h, $tok ) );
t( 'GET con link válido: NO gasta el token ni abre sesión', ca_token_ok( $tok, 'entrar' ) && empty( ct_sesiones_de( $db, 10 ) ) );
ca_get( 'acceso', array( 'cn_tok' => $tok, 'cn_p' => 'entrar' ) );
ca_html( 'acceso' );
ca_get( 'acceso', array( 'cn_tok' => $tok, 'cn_p' => 'entrar' ) );
t( 'varios GET seguidos (escáner de mails): el token sigue vivo', ca_token_ok( $tok, 'entrar' ) );
t( 'la dirección del formulario no lleva el token', false === strpos( $h, 'action="' . CN_Acceso_Paginas::url_acceso() . '?' ) && false !== strpos( $h, 'action="' ) );
ca_post( 'acceso', 'link_entrar', array( 'cn_tok' => $tok ) );
$h = ca_html( 'acceso' );
t( 'POST: gasta el token y abre la sesión', ! ca_token_ok( $tok, 'entrar' ) && 1 === count( ct_sesiones_de( $db, 10 ) ) && false !== strpos( $h, 'Ya estás adentro' ) );
t( 'la respuesta del POST no repite el token', false === strpos( $h, $tok ) );
$_COOKIE = array();
$db->t['sesiones'] = array();
ca_post( 'acceso', 'link_entrar', array( 'cn_tok' => $tok ) );
t( 'segundo POST con el mismo token: no entra, mensaje de link vencido', empty( ct_sesiones_de( $db, 10 ) ) && false !== strpos( ca_html( 'acceso' ), CN_Cuentas::MSG_TOKEN_INVALIDO ) );
ca_get( 'acceso', array( 'cn_tok' => $tok, 'cn_p' => 'entrar' ) );
$h = ca_html( 'acceso' );
t( 'GET con token usado: mensaje claro y formulario para pedir otro', false !== strpos( $h, CN_Cuentas::MSG_TOKEN_INVALIDO ) && false !== strpos( $h, 'value="link_pedir"' ) );
ca_get( 'acceso', array( 'cn_tok' => 'zzz', 'cn_p' => 'entrar' ) );
t( 'GET con token basura: igual de neutro, sin botón de entrar', false === strpos( ca_html( 'acceso' ), 'value="link_entrar"' ) );

// ------------------------------------------------------------------ crear / recuperar contraseña
seccion( 'PASO C: [cn_crear_password]' );
$db = ca_cuenta_nueva();
ca_limpiar();
$tok = ct_ultimo_token( 'crear' );
ca_get( 'crear', array( 'cn_tok' => $tok, 'cn_p' => 'crear' ) );
$h = ca_html( 'crear' );
t( 'GET con link válido: dos campos de contraseña nuevos, Mostrar/Ocultar, nonce y honeypot', false !== strpos( $h, 'name="cn_pass"' ) && false !== strpos( $h, 'name="cn_pass2"' ) && false !== strpos( $h, 'autocomplete="new-password"' ) && false !== strpos( $h, 'data-cn-ver="cn_pass2"' ) && false !== strpos( $h, 'name="cn_acc_nonce"' ) && false !== strpos( $h, 'name="cn_web"' ) );
t( 'GET: el token NO se gasta, aunque se abra muchas veces', ca_token_ok( $tok, 'crear' ) && ca_html( 'crear' ) !== '' && ca_token_ok( $tok, 'crear' ) );
t( 'explica el mínimo (8) y no pide nada de WhatsApp', false !== strpos( $h, '8 caracteres' ) && false === stripos( $h, 'whatsapp' ) );
ca_post( 'crear', 'elegir', array( 'cn_tok' => $tok, 'cn_p' => 'crear', 'cn_pass' => 'corta', 'cn_pass2' => 'corta' ) );
$h = ca_html( 'crear' );
t( 'contraseña débil: error, el token sigue vivo y vuelve el formulario', ca_token_ok( $tok, 'crear' ) && false !== strpos( $h, 'name="cn_pass2"' ) && false !== strpos( $h, 'cn-alerta--error' ) );
ca_post( 'crear', 'elegir', array( 'cn_tok' => $tok, 'cn_p' => 'crear', 'cn_pass' => 'pinturas-del-sur', 'cn_pass2' => 'otra-distinta-1' ) );
t( 'contraseñas distintas: error y el token sigue vivo', ca_token_ok( $tok, 'crear' ) && false !== strpos( ca_html( 'crear' ), 'no coinciden' ) );
ca_post( 'crear', 'elegir', array( 'cn_tok' => $tok, 'cn_p' => 'crear', 'cn_pass' => 'pinturas-del-sur', 'cn_pass2' => 'pinturas-del-sur', 'cn_web' => 'robot' ) );
t( 'honeypot lleno: no se guarda nada ni se gasta el token', ca_token_ok( $tok, 'crear' ) && empty( ct_sesiones_de( $db, 10 ) ) );
ca_post( 'crear', 'elegir', array( 'cn_tok' => $tok, 'cn_p' => 'crear', 'cn_pass' => 'pinturas-del-sur', 'cn_pass2' => 'pinturas-del-sur' ) );
$h = ca_html( 'crear' );
t( 'contraseña buena: se guarda, se gasta el token y entra', ! ca_token_ok( $tok, 'crear' ) && 1 === count( ct_sesiones_de( $db, 10 ) ) && false !== strpos( $h, 'ya estás adentro' ) );
t( 'la respuesta no repite ni el token ni la contraseña', false === strpos( $h, $tok ) && false === strpos( $h, 'pinturas-del-sur' ) );
$_COOKIE = array();
ca_post( 'acceso', 'login', array( 'cn_email' => 'nueva.socia@example.com', 'cn_pass' => 'pinturas-del-sur' ) );
t( 'después se puede entrar con email y contraseña', 2 === count( ct_sesiones_de( $db, 10 ) ) );
ca_get( 'crear', array( 'cn_tok' => $tok, 'cn_p' => 'crear' ) );
$h = ca_html( 'crear' );
t( 'abrir de nuevo el mismo link: mensaje de link vencido y camino para pedir otro, sin formulario', false !== strpos( $h, CN_Cuentas::MSG_TOKEN_INVALIDO ) && false === strpos( $h, 'name="cn_pass"' ) && false !== strpos( $h, 'cn_vista=olvide' ) );
ca_get( 'crear' );
t( 'sin token: mismo mensaje, sin formulario', false !== strpos( ca_html( 'crear' ), CN_Cuentas::MSG_TOKEN_INVALIDO ) && false === strpos( ca_html( 'crear' ), 'name="cn_pass"' ) );

// recuperar: el link de "olvidé" abre la misma pantalla
list( $db ) = ct_con_password( 'pinturas-del-sur' );
ca_limpiar();
ca_post( 'acceso', 'olvide', array( 'cn_email' => 'nueva.socia@example.com' ) );
$tok = ct_ultimo_token( 'recuperar' );
ca_get( 'crear', array( 'cn_tok' => $tok, 'cn_p' => 'recuperar' ) );
t( 'recuperar: título de contraseña nueva y token sin gastar', false !== strpos( ca_html( 'crear' ), 'contraseña nueva' ) && ca_token_ok( $tok, 'recuperar' ) );
ca_get( 'acceso', array( 'cn_tok' => $tok, 'cn_p' => 'recuperar' ) );
t( 'el link de recuperar también funciona abierto en [cn_acceso]', false !== strpos( ca_html( 'acceso' ), 'name="cn_pass2"' ) );
ca_post( 'crear', 'elegir', array( 'cn_tok' => $tok, 'cn_p' => 'recuperar', 'cn_pass' => 'clave-nueva-2026', 'cn_pass2' => 'clave-nueva-2026' ) );
t( 'recuperar: cambia la clave, cierra las sesiones viejas y entra con la nueva', 1 === count( ct_sesiones_de( $db, 10 ) ) );
$_COOKIE = array();
$db->t['sesiones'] = array();
ca_post( 'acceso', 'login', array( 'cn_email' => 'nueva.socia@example.com', 'cn_pass' => 'pinturas-del-sur' ) );
t( 'la clave vieja ya no entra', empty( ct_sesiones_de( $db, 10 ) ) );
ca_post( 'acceso', 'login', array( 'cn_email' => 'nueva.socia@example.com', 'cn_pass' => 'clave-nueva-2026' ) );
t( 'la clave nueva sí', 1 === count( ct_sesiones_de( $db, 10 ) ) );

// el POST de crear solo vale en su página y el de acceso no acepta acciones ajenas
$db = ca_cuenta_nueva();
$tok = ct_ultimo_token( 'crear' );
ca_post( 'acceso', 'elegir', array( 'cn_tok' => $tok, 'cn_p' => 'crear', 'cn_pass' => 'pinturas-del-sur', 'cn_pass2' => 'pinturas-del-sur' ) );
t( 'acción "elegir" mandada a [cn_acceso]: se ignora', ca_token_ok( $tok, 'crear' ) );
ca_post( 'crear', 'login', array( 'cn_email' => 'nueva.socia@example.com', 'cn_pass' => 'x' ) );
t( 'acción "login" mandada a [cn_crear_password]: se ignora', empty( ct_sesiones_de( $db, 10 ) ) );

// ------------------------------------------------------------------ caché y headers
seccion( 'PASO C: sin caché, sin Referer, sin indexar' );
$hs = implode( "\n", CN_Acceso_Paginas::headers_pagina() );
t( 'headers: LiteSpeed no-cache, Alt-Svc clear, sin Referer y noindex', false !== strpos( $hs, 'X-LiteSpeed-Cache-Control: no-cache' ) && false !== strpos( $hs, 'Alt-Svc: clear' ) && false !== strpos( $hs, 'Referrer-Policy: no-referrer' ) && false !== strpos( $hs, 'noindex' ) );
ct_preparar( 'on' );
ca_limpiar();
$GLOBALS['cn_nocache'] = 0;
$GLOBALS['ca_post']    = (object) array( 'post_content' => 'Hola [cn_acceso]' );
ob_start();
CN_Acceso_Paginas::iniciar();
ob_end_clean();
t( 'en una página con [cn_acceso] se pide DONOTCACHEPAGE y nocache_headers', defined( 'DONOTCACHEPAGE' ) && 1 === $GLOBALS['cn_nocache'] );
$GLOBALS['cn_nocache'] = 0;
$GLOBALS['ca_post']    = (object) array( 'post_content' => 'Otra página' );
CN_Acceso_Paginas::iniciar();
t( 'en cualquier otra página no hace nada', 0 === $GLOBALS['cn_nocache'] );
unset( $GLOBALS['ca_post'] );

// ------------------------------------------------------------------ fuente: sin WhatsApp, sin mails, sin hooks de altas
seccion( 'PASO C: sin WhatsApp, sin mails y sin enganches a las altas' );
$fuente = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-acceso.php' );
$codigo = preg_replace( '#/\*.*?\*/#s', '', $fuente ); // sin comentarios de bloque
$codigo = preg_replace( '#^\s*//.*$#m', '', $codigo );
t( 'el código no usa CN_WA_SOPORTE ni wa.me ni whatsapp', false === stripos( $codigo, 'CN_WA_SOPORTE' ) && false === stripos( $codigo, 'wa.me' ) && false === stripos( $codigo, 'whatsapp' ) );
t( 'no manda mails ni toca el webhook, la vinculación o Mercado Pago', ! preg_match( '/wp_mail|CN_Webhook|CN_Vinculo|CN_MP::|CN_Mail/', $codigo ) );
t( 'no crea cuentas ni escribe fichas', ! preg_match( '/provisionar|UPDATE\s|INSERT\s|DELETE\s|\$wpdb/', $codigo ) );
t( 'el único hook que registra es template_redirect (y los dos shortcodes)', 1 === preg_match_all( '/add_action\(/', $codigo ) && false !== strpos( $codigo, "'template_redirect'" ) && 2 === preg_match_all( '/add_shortcode\(/', $codigo ) );
t( 'las clases de altas no mencionan las pantallas nuevas', ! preg_match( '/CN_Acceso_Paginas/', file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-webhook.php' ) . file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-vinculo-manual.php' ) . file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-auth.php' ) ) );
ca_limpiar();
