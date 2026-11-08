<?php
// Formulario de planes de /planes-club/ ([cn_planes_form]): CN_Shortcodes::procesar_planes_form().
// Verifica que la suscripción nazca en suscripciones_pendientes con origen 'landing' y los
// fbp/fbc/ip/user_agent del navegador, como hace el trial. No toca webhook ni alta.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
foreach ( array( 'MINUTE_IN_SECONDS' => 60 ) as $k => $v ) {
	if ( ! defined( $k ) ) {
		define( $k, $v );
	}
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) {
		return mt_rand( (int) $min, $max ? (int) $max : mt_getrandmax() );
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $p = '' ) {
		return 'https://naturalesart.com/wp-json/' . ltrim( $p, '/' );
	}
}
if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( $pairs, $atts, $tag = '' ) {
		$atts = (array) $atts;
		$out  = array();
		foreach ( $pairs as $k => $v ) {
			$out[ $k ] = array_key_exists( $k, $atts ) ? $atts[ $k ] : $v;
		}
		return $out;
	}
}
require_once dirname( __DIR__ ) . '/includes/class-cn-shortcodes.php';

function pf_preparar( $cookies = array(), $get = array(), $ua = 'Mozilla/5.0 (test)', $ip = '190.1.2.3' ) {
	cn_test_reset();
	$GLOBALS['cn_opts']['cn_mp_access_token'] = 'TOKEN-SUB';
	$_COOKIE = $cookies;
	$_GET    = $get;
	$_SERVER['HTTP_USER_AGENT'] = $ua;
	$_SERVER['REMOTE_ADDR']     = $ip;
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CLIENT_IP'] );
	cn_http_on( 'POST', '#/preapproval$#', array( 'id' => 'pre_1', 'init_point' => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=pre_1' ) );
}
function pf_post( $extra = array() ) {
	return array_merge( array(
		'cn_planes_submit'  => '1',
		'cn_planes_plan'    => 'basico',
		'cn_planes_nombre'  => 'Socia Prueba',
		'cn_planes_celular' => '011 15 5555-0001',
	), $extra );
}
function pf_pendiente() {
	$e = $GLOBALS['wpdb']->escrituras( 'suscripciones_pendientes' );
	foreach ( $e as $l ) {
		if ( 'insert' === $l[0] ) {
			return $l[2];
		}
	}
	return null;
}
function pf_mp_body() {
	$c = cn_http_calls( '#/preapproval$#' );
	return $c ? json_decode( $c[0]['body'], true ) : null;
}

seccion( 'Planes form: con cookies _fbp/_fbc' );
pf_preparar( array( '_fbp' => 'fb.1.1700000000000.111', '_fbc' => 'fb.1.1700000000000.AbCdEf' ) );
$r = CN_Shortcodes::procesar_planes_form( pf_post() );
t( 'ok y redirige al init_point', $r['ok'] && 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=pre_1' === $r['init_point'] );
$p = pf_pendiente();
t( 'deja fila en suscripciones_pendientes', is_array( $p ) );
t( 'origen landing', is_array( $p ) && 'landing' === $p['origen'] );
t( 'plan basico', is_array( $p ) && 'basico' === $p['plan'] );
t( 'fbp guardado', is_array( $p ) && 'fb.1.1700000000000.111' === $p['fbp'] );
t( 'fbc guardado', is_array( $p ) && 'fb.1.1700000000000.AbCdEf' === $p['fbc'] );
t( 'ip guardada', is_array( $p ) && '190.1.2.3' === $p['ip'] );
t( 'user_agent guardado', is_array( $p ) && 'Mozilla/5.0 (test)' === $p['user_agent'] );
t( 'celular normalizado una sola vez (10 dígitos)', is_array( $p ) && '1155550001' === $p['celular'] );
t( 'external_reference sub_', is_array( $p ) && 0 === strpos( $p['external_reference'], 'sub_1155550001_' ) );
t( 'sin email (no se manda payer_email)', is_array( $p ) && null === $p['email'] && ! isset( pf_mp_body()['payer_email'] ) );
t( 'MP recibe el monto del plan y el webhook', 15500.0 === (float) pf_mp_body()['auto_recurring']['transaction_amount'] && false !== strpos( pf_mp_body()['notification_url'], 'mp-webhook' ) );
t( 'no cuenta como intento fallido', false === get_transient( 'cn_planes_fails_' . md5( '190.1.2.3' ) ) );
t( 'no escribe miembros ni trial', ! $GLOBALS['wpdb']->escrituras( 'miembros' ) && ! $GLOBALS['wpdb']->escrituras( 'trial_pendientes' ) );

seccion( 'Planes form: sin _fbc pero con fbclid en la URL' );
pf_preparar( array( '_fbp' => 'fb.1.1700000000000.222' ), array( 'fbclid' => 'IwAR0xyz' ) );
$r = CN_Shortcodes::procesar_planes_form( pf_post( array( 'cn_planes_plan' => 'full' ) ) );
$p = pf_pendiente();
t( 'ok plan full', $r['ok'] && is_array( $p ) && 'full' === $p['plan'] );
t( 'fbc armado desde fbclid', is_array( $p ) && 1 === preg_match( '/^fb\.1\.[0-9]{13}\.IwAR0xyz$/', (string) $p['fbc'] ) );
t( 'monto del plan full', 32000.0 === (float) pf_mp_body()['auto_recurring']['transaction_amount'] );

seccion( 'Planes form: sin cookies ni fbclid' );
pf_preparar( array(), array(), '', '10.0.0.9' );
$r = CN_Shortcodes::procesar_planes_form( pf_post() );
$p = pf_pendiente();
t( 'ok igual', $r['ok'] && is_array( $p ) );
t( 'fbp/fbc/user_agent quedan null', null === $p['fbp'] && null === $p['fbc'] && null === $p['user_agent'] );
t( 'ip igual se guarda', '10.0.0.9' === $p['ip'] );
t( 'origen landing', 'landing' === $p['origen'] );

seccion( 'Planes form: _fbc tiene prioridad sobre fbclid' );
pf_preparar( array( '_fbc' => 'fb.1.1.cookie' ), array( 'fbclid' => 'url' ) );
CN_Shortcodes::procesar_planes_form( pf_post() );
t( 'usa la cookie', 'fb.1.1.cookie' === pf_pendiente()['fbc'] );

seccion( 'Planes form: celular inválido' );
pf_preparar();
$r = CN_Shortcodes::procesar_planes_form( pf_post( array( 'cn_planes_celular' => '123' ) ) );
t( 'rechaza', ! $r['ok'] && false !== strpos( $r['mensaje'], 'celular' ) && 'basico' === $r['plan'] );
t( 'no llama a MP ni guarda fila', ! cn_http_calls( '#/preapproval#' ) && null === pf_pendiente() );
t( 'cuenta un intento fallido', 1 === (int) get_transient( 'cn_planes_fails_' . md5( '190.1.2.3' ) ) );

seccion( 'Planes form: nombre vacío' );
pf_preparar();
$r = CN_Shortcodes::procesar_planes_form( pf_post( array( 'cn_planes_nombre' => '  ' ) ) );
t( 'rechaza', ! $r['ok'] && false !== strpos( $r['mensaje'], 'nombre' ) );
t( 'no llama a MP', ! cn_http_calls( '#/preapproval#' ) );

seccion( 'Planes form: plan inválido' );
pf_preparar();
$r = CN_Shortcodes::procesar_planes_form( pf_post( array( 'cn_planes_plan' => 'premium' ) ) );
t( 'rechaza con plan vacío', ! $r['ok'] && '' === $r['plan'] && false !== strpos( $r['mensaje'], 'plan' ) );
t( 'no llama a MP ni guarda fila', ! cn_http_calls( '#/preapproval#' ) && null === pf_pendiente() );

seccion( 'Planes form: honeypot' );
pf_preparar();
$r = CN_Shortcodes::procesar_planes_form( pf_post( array( 'cn_planes_web' => 'http://spam' ) ) );
t( 'rechaza en silencio', ! $r['ok'] && ! cn_http_calls( '#/preapproval#' ) && null === pf_pendiente() );

seccion( 'Planes form: rate limit por IP' );
pf_preparar();
$GLOBALS['cn_transients'][ 'cn_planes_fails_' . md5( '190.1.2.3' ) ] = CN_Shortcodes::PLANES_MAX_INTENTOS;
$r = CN_Shortcodes::procesar_planes_form( pf_post() );
t( 'bloquea', ! $r['ok'] && false !== strpos( $r['mensaje'], 'Demasiados' ) );
t( 'no llama a MP', ! cn_http_calls( '#/preapproval#' ) );

seccion( 'Planes form: MP falla' );
pf_preparar();
cn_http_on( 'POST', '#/preapproval$#', 400 );
$r = CN_Shortcodes::procesar_planes_form( pf_post() );
t( 'mensaje genérico', ! $r['ok'] && false !== strpos( $r['mensaje'], 'No pudimos' ) );
t( 'la fila pendiente se borra', (bool) array_filter( $GLOBALS['wpdb']->escrituras( 'suscripciones_pendientes' ), function ( $l ) {
	return 'delete' === $l[0];
} ) );
t( 'deja rastro planes_form_fallo', in_array( 'planes_form_fallo', $GLOBALS['wpdb']->log_mp_tipos(), true ) );
t( 'cuenta un intento fallido', 1 === (int) get_transient( 'cn_planes_fails_' . md5( '190.1.2.3' ) ) );

seccion( 'Planes form: shortcode' );
pf_preparar();
$html = CN_Shortcodes::shortcode_planes_form( array( 'plan' => 'basico', 'texto' => 'Quiero el Club' ) );
t( 'form con los campos', false !== strpos( $html, 'name="cn_planes_nombre"' ) && false !== strpos( $html, 'name="cn_planes_celular"' ) && false !== strpos( $html, 'name="cn_planes_plan" value="basico"' ) );
t( 'botón con el texto', false !== strpos( $html, '>Quiero el Club</button>' ) );
t( 'precio del plan', false !== strpos( $html, '$15.500/mes' ) );
t( 'CSS inline una vez', 1 === substr_count( $html, '<style>' ) );
t( 'sin scripts ni whatsapp', false === stripos( $html, '<script' ) && false === stripos( $html, 'wa.me' ) && false === stripos( $html, 'whatsapp' ) );
$html2 = CN_Shortcodes::shortcode_planes_form( array( 'plan' => 'full' ) );
t( 'segundo form sin repetir CSS', 0 === substr_count( $html2, '<style>' ) && false !== strpos( $html2, '$32.000/mes' ) && false !== strpos( $html2, '>Quiero sumarme</button>' ) );
t( 'plan inválido no renderiza', '' === CN_Shortcodes::shortcode_planes_form( array( 'plan' => 'otro' ) ) );
$_COOKIE = array();
$_GET    = array();
