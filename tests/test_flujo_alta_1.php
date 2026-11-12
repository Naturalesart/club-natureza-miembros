<?php
// Flujo de alta — PR 1 (etapa 1, del clic al pago): link firmado sin celular (plan A), formulario precargado (plan B),
// checkout fijo + pendiente de vincular + alerta a hola@ (plan C), y la ficha destino en el webhook. Flag
// cn_suscribir_fallback_modo: off = como siempre, log = solo registra, on = lo hace.
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

const FA_SECRETO = 'cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12';
const FA_CEL     = '1155550304';

function fa_ficha( $extra = array() ) {
	return cn_fila( $extra + array(
		'id'                  => 304,
		'nombre_apellido'     => 'Socia Trescientos',
		'estado'              => 'activo',
		'preapproval_id'      => null,
		'baja_en'             => null,
		'email'               => 'socia.304@gmail.com',
		'celular_texto_plano' => null, // caso real 304: celular solo hasheado
		'celular_hash'        => CN_Helpers::hash_celular( FA_CEL ),
	) );
}
function fa_prep( $fb = 'on', $ficha = null, $mp_ok = true ) {
	cn_test_reset();
	global $wpdb;
	$_GET = $_POST = $_COOKIE = array();
	$_SERVER['REMOTE_ADDR']     = '190.1.2.3';
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (test)';
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CLIENT_IP'] );
	update_option( 'cn_mp_access_token', 'TOKEN-SUB' );
	update_option( CN_Suscribir::OPT_MODO, 'on' );
	update_option( CN_Suscribir::OPT_SECRETO, FA_SECRETO );
	update_option( CN_Suscribir_Fallback::OPT_MODO, $fb );
	cn_http_on( 'POST', '#/preapproval$#', $mp_ok ? array( 'id' => 'pre_9', 'init_point' => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=pre_9' ) : 400 );
	$ficha = null === $ficha ? fa_ficha() : $ficha;
	if ( false !== $ficha ) {
		$wpdb->on( '/FROM wp_cn_miembros WHERE id = ' . (int) $ficha->id . '\b/', array( $ficha ) );
	}
	return $wpdb;
}
function fa_token( $id = 304, $plan = 'basico', $origen = 'mail_d7' ) {
	return CN_Suscribir::crear_token( $id, $plan, $origen, null, FA_SECRETO );
}
function fa_pend( $w ) {
	foreach ( $w->escrituras( 'suscripciones_pendientes' ) as $l ) {
		if ( 'insert' === $l[0] ) {
			return $l[2];
		}
	}
	return null;
}
function fa_payloads( $w, $tipo ) {
	$o = array();
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && $tipo === $l[2]['tipo'] ) {
			$o[] = json_decode( $l[2]['payload'], true );
		}
	}
	return $o;
}
function fa_sin_datos( $texto ) {
	return false === stripos( $texto, 'socia.304' ) && false === strpos( $texto, FA_CEL ) && false === stripos( $texto, 'Trescientos' );
}

seccion( 'PR1 off: link firmado a ficha sin celular = comportamiento de siempre' );
$w = fa_prep( 'off' );
$r = CN_Suscribir::procesar( fa_token() );
t( 'off: caso sin_celular', 'sin_celular' === $r['caso'], $r['caso'] );
t( 'off: va a /planes-club/ sin token', 'https://naturalesart.com/planes-club/' === $r['redirect'], $r['redirect'] );
t( 'off: no llama a MP', ! cn_http_calls( '#/preapproval$#' ) );
t( 'off: sin pendiente', null === fa_pend( $w ) );
t( 'off: sin rastro del fallback', ! array_intersect( $w->log_mp_tipos(), array( 'suscribir_fallback_log', 'suscribir_fallback_b', 'suscribir_fallback_c' ) ) );

seccion( 'PR1 log: no cambia nada, solo registra' );
$w = fa_prep( 'log' );
$r = CN_Suscribir::procesar( fa_token() );
t( 'log: caso sin_celular igual que off', 'sin_celular' === $r['caso'] );
t( 'log: no llama a MP', ! cn_http_calls( '#/preapproval$#' ) );
$l = fa_payloads( $w, 'suscribir_fallback_log' );
t( 'log: registra que haría a_sin_celular', 1 === count( $l ) && 'a_sin_celular' === $l[0]['haria'] && 304 === $l[0]['ficha_id'] );

seccion( 'PR1 on — caso 2: trial con celular solo hasheado paga por link firmado (plan A)' );
$w = fa_prep( 'on' );
$r = CN_Suscribir::procesar( fa_token() );
$p = fa_pend( $w );
t( 'A: caso creada', 'creada' === $r['caso'], $r['caso'] );
t( 'A: redirige al init_point de MP', 0 === strpos( $r['redirect'], 'https://www.mercadopago.com.ar/' ) );
t( 'A: pendiente con ficha_id 304', $p && 304 === $p['ficha_id'] );
t( 'A: pendiente sin celular', $p && '' === $p['celular'] );
t( 'A: external_reference sub_f304_<hash>', $p && 1 === preg_match( '/^sub_f304_[a-f0-9]{8}$/', $p['external_reference'] ), $p ? $p['external_reference'] : '' );
t( 'A: origen mail_d7', $p && 'mail_d7' === $p['origen'] );
$body = json_decode( cn_http_calls( '#/preapproval$#' )[0]['body'], true );
t( 'A: MP recibe la misma referencia y el payer_email', $body['external_reference'] === $p['external_reference'] && 'socia.304@gmail.com' === $body['payer_email'] );
$c = fa_payloads( $w, 'suscribir_creada' );
t( 'A: suscribir_creada marca sin_celular', 1 === count( $c ) && ! empty( $c[0]['sin_celular'] ) );

seccion( 'PR1 on — caso 1: trial con celular en texto paga por link firmado' );
$w = fa_prep( 'on', fa_ficha( array( 'celular_texto_plano' => '011 15 5555-0304' ) ) );
$r = CN_Suscribir::procesar( fa_token() );
$p = fa_pend( $w );
t( 'caso 1: creada', 'creada' === $r['caso'] );
t( 'caso 1: referencia de siempre sub_<celular>_', $p && 0 === strpos( $p['external_reference'], 'sub_' . FA_CEL . '_' ) );
t( 'caso 1: igual guarda la ficha destino', $p && 304 === $p['ficha_id'] );

seccion( 'PR1 on: con el flag apagado, la pendiente no lleva ficha_id (INSERT idéntico al de siempre)' );
$w = fa_prep( 'off', fa_ficha( array( 'celular_texto_plano' => '011 15 5555-0304' ) ) );
CN_Suscribir::procesar( fa_token() );
$p = fa_pend( $w );
t( 'off: pendiente sin la clave ficha_id', $p && ! array_key_exists( 'ficha_id', $p ) );

seccion( 'PR1 on: plan B ante sin email' );
$w = fa_prep( 'on', fa_ficha( array( 'email' => '' ) ) );
$r = CN_Suscribir::procesar( fa_token() );
t( 'B: caso sin_email + fallback b', 'sin_email' === $r['caso'] && 'b' === ( $r['fallback'] ?? '' ) );
t( 'B: va a /planes-club/?cn_pre=…#cn-pf-basico', 1 === preg_match( '#^https://naturalesart\.com/planes-club/\?cn_pre=304\.basico\.mail_d7\.[0-9]+\.[a-f0-9]{64}\#cn-pf-basico$#', $r['redirect'] ), $r['redirect'] );
t( 'B: la URL no lleva datos personales', fa_sin_datos( $r['redirect'] ) );
t( 'B: rastro suscribir_fallback_b', 1 === count( fa_payloads( $w, 'suscribir_fallback_b' ) ) );

seccion( 'PR1 on: plan B ante fallo de MP' );
$w = fa_prep( 'on', null, false );
$r = CN_Suscribir::procesar( fa_token() );
t( 'B: caso fallo + fallback b', 'fallo' === $r['caso'] && 'b' === ( $r['fallback'] ?? '' ) );

seccion( 'PR1 log: fallo de MP solo registra (redirect de siempre)' );
$w = fa_prep( 'log', fa_ficha( array( 'celular_texto_plano' => '011 15 5555-0304' ) ), false );
$r = CN_Suscribir::procesar( fa_token() );
t( 'log: redirect de siempre', 'https://naturalesart.com/planes-club/' === $r['redirect'] );
$l = fa_payloads( $w, 'suscribir_fallback_log' );
t( 'log: registra haria b', (bool) array_filter( $l, function ( $x ) { return 'b' === $x['haria']; } ) );

seccion( 'PR1: token B (firma, vencimiento, dominio propio)' );
fa_prep( 'on' );
$tb = CN_Suscribir_Fallback::token( 304, 'basico', 'mail_d7' );
$v  = CN_Suscribir_Fallback::verificar( $tb );
t( 'token B válido', $v['ok'] && 304 === $v['ficha_id'] && 'mail_d7' === $v['origen'] );
t( 'token B adulterado = inválido', ! CN_Suscribir_Fallback::verificar( substr( $tb, 0, -1 ) . ( 'a' === substr( $tb, -1 ) ? 'b' : 'a' ) )['ok'] );
t( 'token B vencido = inválido', ! CN_Suscribir_Fallback::verificar( CN_Suscribir_Fallback::token( 304, 'basico', 'mail_d7', time() - 10 ) )['ok'] );
t( 'el token de los mails no sirve como token B', ! CN_Suscribir_Fallback::verificar( fa_token() )['ok'] );
update_option( CN_Suscribir_Fallback::OPT_MODO, 'log' );
t( 'con el flag en log el token B no hace nada', ! CN_Suscribir_Fallback::verificar( $tb )['ok'] );

seccion( 'PR1: formulario precargado (render)' );
fa_prep( 'on' );
$_GET['cn_pre'] = CN_Suscribir_Fallback::token( 304, 'basico', 'mail_d7' );
$html = CN_Shortcodes::shortcode_planes_form( array( 'plan' => 'basico' ) );
t( 'render: precarga el nombre', false !== strpos( $html, 'value="Socia Trescientos"' ) );
t( 'render: precarga el email', false !== strpos( $html, 'value="socia.304@gmail.com"' ) );
t( 'render: lleva el token oculto', false !== strpos( $html, 'name="cn_planes_pre"' ) );
t( 'render: mensaje amable', false !== strpos( $html, esc_html( CN_Suscribir_Fallback::MENSAJE_B ) ) );
t( 'render: celular no obligatorio', 1 !== preg_match( '/name="cn_planes_celular" required/', $html ) );
$html_full = CN_Shortcodes::shortcode_planes_form( array( 'plan' => 'full' ) );
t( 'render: el form de otro plan no se precarga', false === strpos( $html_full, 'cn_planes_pre' ) );
update_option( CN_Suscribir_Fallback::OPT_MODO, 'off' );
$html = CN_Shortcodes::shortcode_planes_form( array( 'plan' => 'basico' ) );
t( 'render off: formulario de siempre', false === strpos( $html, 'cn_planes_pre' ) && false === strpos( $html, 'Socia Trescientos' ) );

seccion( 'PR1: formulario con token B — un clic para pagar' );
$w = fa_prep( 'on' );
$post = array(
	'cn_planes_submit' => '1', 'cn_planes_plan' => 'basico', 'cn_planes_nombre' => 'Socia Trescientos',
	'cn_planes_celular' => '', 'cn_planes_email' => 'socia.304@gmail.com',
	'cn_planes_pre' => CN_Suscribir_Fallback::token( 304, 'basico', 'mail_d7' ),
);
$r = CN_Shortcodes::procesar_planes_form( $post );
$p = fa_pend( $w );
t( 'form B: ok', $r['ok'] && 0 === strpos( $r['init_point'], 'https://www.mercadopago.com.ar/' ) );
t( 'form B: pendiente con ficha 304 y origen del token', $p && 304 === $p['ficha_id'] && 'mail_d7' === $p['origen'] );
t( 'form B: sin celular → referencia sub_f304_', $p && 0 === strpos( $p['external_reference'], 'sub_f304_' ) );
$w = fa_prep( 'on' );
$r = CN_Shortcodes::procesar_planes_form( array( 'cn_planes_pre' => 'basura' ) + $post );
t( 'form con token inválido: pide celular como siempre', ! $r['ok'] && false !== strpos( $r['mensaje'], 'celular' ) );

seccion( 'PR1: plan C (B también falla)' );
$w = fa_prep( 'on', null, false );
$r = CN_Shortcodes::procesar_planes_form( array( 'cn_planes_pre' => CN_Suscribir_Fallback::token( 304, 'basico', 'mail_d7' ) ) + $post );
$vp = $w->escrituras( 'vincular_pendientes' );
t( 'C: redirige al checkout fijo del plan', $r['ok'] && 'https://mpago.la/1FsDjhK' === $r['init_point'], $r['init_point'] );
t( 'C: ficha 304 pendiente de vincular', 1 === count( $vp ) && 304 === $vp[0][2]['ficha_id'] && 'fallo_form_b' === $vp[0][2]['motivo'] );
t( 'C: rastro suscribir_fallback_c', 1 === count( fa_payloads( $w, 'suscribir_fallback_c' ) ) );
$m = $GLOBALS['cn_mails'];
t( 'C: alerta a hola@', 1 === count( $m ) && 'hola@naturalesart.com' === $m[0]['to'] );
t( 'C: alerta sin datos personales', $m && fa_sin_datos( $m[0]['asunto'] . $m[0]['cuerpo'] ) );
t( 'C: ningún mail a la clienta', ! array_filter( $m, function ( $x ) { return false !== stripos( (string) $x['to'], 'socia.304' ); } ) );
$r2 = CN_Shortcodes::procesar_planes_form( array( 'cn_planes_pre' => CN_Suscribir_Fallback::token( 304, 'basico', 'mail_d7' ) ) + $post );
t( 'C: la alerta del mismo caso no se repite en la hora', 1 === count( $GLOBALS['cn_mails'] ) && in_array( 'alerta_hola_demorada', $w->log_mp_tipos(), true ) );
$w = fa_prep( 'on', null, false );
$r = CN_Shortcodes::procesar_planes_form( array( 'cn_planes_plan' => 'full', 'cn_planes_pre' => CN_Suscribir_Fallback::token( 304, 'full', 'mail_d7' ) ) + $post );
t( 'C sin checkout fijo (full): mensaje amable sin jerga', ! $r['ok'] && CN_Suscribir_Fallback::MENSAJE_C === $r['mensaje'] );
t( 'C sin checkout: la alerta sale igual', 1 === count( $GLOBALS['cn_mails'] ) );
update_option( CN_Suscribir_Fallback::OPT_LINK . 'basico', 'https://evil.example.com/pagar' );
t( 'checkout fijo solo de dominios de MP', '' === CN_Suscribir_Fallback::link_checkout( 'basico' ) );

// ---------------------------------------------------------------- webhook: ficha destino
function fa_wh( $fb, $pend, $ficha = null ) {
	$w = fa_prep( $fb, false );
	update_option( 'cn_sub_auto_activar', '1' );
	$w->on( '/FROM wp_cn_suscripciones_pendientes WHERE external_reference/', array( $pend ) );
	if ( $ficha ) {
		$w->on( '/FROM wp_cn_miembros WHERE id = ' . (int) $ficha->id . '\b/', array( $ficha ) );
	}
	cn_http_on( 'GET', '#/preapproval/pre_abc#', array( 'id' => 'pre_abc', 'status' => 'authorized', 'next_payment_date' => '2026-12-11T21:33:41.000-04:00' ) );
	return $w;
}
function fa_pend_fila( $extra = array() ) {
	return cn_fila( $extra + array(
		'id' => 77, 'external_reference' => 'sub_f304_abcdef12', 'nombre_apellido' => 'Socia Trescientos', 'celular' => '',
		'email' => 'socia.304@gmail.com', 'plan' => 'basico', 'origen' => 'mail_d7', 'fbp' => null, 'fbc' => null,
		'ip' => null, 'user_agent' => null, 'usado' => 0, 'ficha_id' => 304,
	) );
}
function fa_upd_miembros( $w ) {
	return array_values( array_filter( $w->escrituras( 'miembros' ), function ( $l ) { return 'update' === $l[0]; } ) );
}
function fa_ins_miembros( $w ) {
	return array_values( array_filter( $w->escrituras( 'miembros' ), function ( $l ) { return 'insert' === $l[0]; } ) );
}

seccion( 'PR1 webhook on: pendiente sin celular activa la ficha destino (caso 304)' );
$w = fa_wh( 'on', fa_pend_fila(), fa_ficha() );
$ok = cn_llamar( 'CN_Webhook', 'activar_por_suscripcion_sub', 'sub_f304_abcdef12', 'pre_abc' );
$u = fa_upd_miembros( $w );
t( 'wh: se hizo cargo', true === $ok );
t( 'wh: convierte la ficha 304 (no crea otra)', ! fa_ins_miembros( $w ) && $u && 304 === $u[0][3]['id'] && 'activo' === $u[0][2]['estado'] && 'pre_abc' === $u[0][2]['preapproval_id'] );
t( 'wh: rastro sub_ficha_destino', in_array( 'sub_ficha_destino', $w->log_mp_tipos(), true ) );
$mb = array_values( array_filter( $GLOBALS['cn_mails'], function ( $x ) { return 'socia.304@gmail.com' === $x['to']; } ) );
t( 'wh: mail de bienvenida sale', 1 === count( $mb ) );
t( 'wh: sin celular el mail no deja el campo vacío', $mb && false !== strpos( $mb[0]['cuerpo'], 'El mismo con el que te sumaste al Club' ) );

seccion( 'PR1 webhook on: ficha destino que no existe y sin celular → no crea ficha, deja error visible' );
$w = fa_wh( 'on', fa_pend_fila() );
cn_llamar( 'CN_Webhook', 'activar_por_suscripcion_sub', 'sub_f304_abcdef12', 'pre_abc' );
t( 'wh: no crea ficha', ! fa_ins_miembros( $w ) && ! fa_upd_miembros( $w ) );
t( 'wh: sub_error + aviso', in_array( 'sub_error', $w->log_mp_tipos(), true ) );

seccion( 'PR1 webhook off: con ficha_id en la pendiente igual busca por celular, como siempre' );
$w = fa_wh( 'off', fa_pend_fila( array( 'celular' => FA_CEL, 'external_reference' => 'sub_' . FA_CEL . '_abcdef12' ) ), fa_ficha() );
cn_llamar( 'CN_Webhook', 'activar_por_suscripcion_sub', 'sub_' . FA_CEL . '_abcdef12', 'pre_abc' );
t( 'wh off: no lee la ficha por id', ! array_filter( $w->consultas, function ( $q ) { return false !== strpos( $q, 'WHERE id = 304' ); } ) );
t( 'wh off: sin rastro sub_ficha_destino', ! in_array( 'sub_ficha_destino', $w->log_mp_tipos(), true ) );
t( 'wh off: busca por celular (hint)', (bool) array_filter( $w->consultas, function ( $q ) { return false !== strpos( $q, "celular_hint = '0304'" ); } ) );
