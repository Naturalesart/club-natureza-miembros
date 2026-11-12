<?php
// Flujo de alta — los 6 casos de punta a punta, con todos los flags del flujo en 'on'. Cada caso: clic/formulario →
// pendiente → activación (webhook, o página de vuelta/sync si el webhook no llega) → 1 ficha, activa, bienvenida_mail_enviado.
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
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook, $args = array() ) {
		$GLOBALS['cn_single_events'][] = array( 'ts' => (int) $ts, 'hook' => $hook, 'args' => $args );
		return true;
	}
}
require_once dirname( __DIR__ ) . '/includes/class-cn-shortcodes.php';

const C6_SECRETO = 'ef56ab12ef56ab12ef56ab12ef56ab12ef56ab12ef56ab12ef56ab12ef56ab12';
const C6_PRE     = 'cccc0000cccc0000cccc0000cccc0000';
const C6_CEL     = '1155550304'; // celular del trial (ficha 304)

function c6_ficha( $extra = array() ) {
	return cn_fila( $extra + array(
		'id' => 304, 'nombre_apellido' => 'Socia Trescientos', 'estado' => 'activo', 'preapproval_id' => null, 'baja_en' => null,
		'email' => 'socia.304@gmail.com', 'celular_texto_plano' => '011 15 5555-0304', 'celular_hash' => CN_Helpers::hash_celular( C6_CEL ),
		'plan' => null, 'acceso_hasta' => null,
	) );
}
/** Todos los flags en on. $ficha = la trial que ya existe (o null = persona nueva). La base "recuerda" la activación. */
function c6_prep( $ficha ) {
	cn_test_reset();
	global $wpdb;
	$GLOBALS['cn_single_events'] = array();
	$GLOBALS['c6_pend']          = null;
	$_GET = $_POST = $_COOKIE = array();
	$_SERVER['REMOTE_ADDR']     = '190.1.2.3';
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (test)';
	update_option( 'cn_mp_access_token', 'TOKEN-SUB' );
	update_option( 'cn_sub_auto_activar', '1' );
	update_option( CN_Suscribir::OPT_MODO, 'on' );
	update_option( CN_Suscribir::OPT_SECRETO, C6_SECRETO );
	foreach ( array_keys( CN_Flujo_Alta::flags() ) as $f ) {
		update_option( $f, 'on' );
	}
	cn_http_on( 'POST', '#/preapproval$#', array( 'id' => C6_PRE, 'init_point' => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=' . C6_PRE ) );
	$activada = function () use ( $wpdb ) {
		foreach ( $wpdb->log as $l ) {
			if ( in_array( $l[0], array( 'update', 'insert' ), true ) && false !== strpos( $l[1], 'miembros' ) && isset( $l[2]['preapproval_id'] ) ) {
				return true;
			}
		}
		return false;
	};
	// La pendiente que se creó en el paso 1 es la que lee el webhook.
	$wpdb->on( '/FROM wp_cn_suscripciones_pendientes WHERE external_reference/', function () use ( $activada ) {
		$p = $GLOBALS['c6_pend'];
		return $p ? array( cn_fila( array( 'id' => 91, 'usado' => $activada() ? 1 : 0 ) + $p + array( 'fbp' => null, 'fbc' => null, 'ip' => null, 'user_agent' => null, 'ficha_id' => null ) ) ) : array();
	} );
	$wpdb->on( '/FROM wp_cn_miembros WHERE preapproval_id = /', function () use ( $activada ) {
		return $activada() ? 1 : null;
	} );
	if ( $ficha ) {
		// Como la base real: después de activarla, la ficha ya tiene el preapproval_id.
		$viva = function () use ( $ficha, $activada ) {
			$f = clone $ficha;
			if ( $activada() ) {
				$f->preapproval_id = C6_PRE;
			}
			return array( $f );
		};
		$wpdb->on( '/FROM wp_cn_miembros WHERE id = ' . (int) $ficha->id . '\b/', $viva );
		$wpdb->on( '/LOWER\(TRIM\(email\)\) = \'' . preg_quote( strtolower( trim( $ficha->email ) ), '/' ) . '\'/', $viva );
	}
	return $wpdb;
}
/** Guarda la pendiente insertada (con la misma external_reference que recibió MP). */
function c6_capturar( $w ) {
	foreach ( $w->escrituras( 'suscripciones_pendientes' ) as $l ) {
		if ( 'insert' === $l[0] ) {
			$GLOBALS['c6_pend'] = $l[2];
		}
	}
	return $GLOBALS['c6_pend'];
}
function c6_mp_responde( $pend ) {
	cn_http_on( 'GET', '#/preapproval/' . C6_PRE . '$#', array( 'id' => C6_PRE, 'status' => 'authorized', 'external_reference' => $pend['external_reference'], 'next_payment_date' => '2026-12-11T21:33:41.000-04:00' ) );
}
/** Resultado: fichas creadas, fichas activadas (id), bienvenidas registradas. */
function c6_resultado( $w ) {
	$ins = $upd = array();
	foreach ( $w->escrituras( 'miembros' ) as $l ) {
		if ( 'insert' === $l[0] ) {
			$ins[] = $l[2];
		} elseif ( 'update' === $l[0] && isset( $l[2]['preapproval_id'] ) ) {
			$upd[] = $l;
		}
	}
	$bien = array();
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && 'bienvenida_mail_enviado' === $l[2]['tipo'] ) {
			$bien[] = json_decode( $l[2]['payload'], true );
		}
	}
	return array( 'ins' => $ins, 'upd' => $upd, 'bien' => $bien );
}
/** 1 ficha, activa, con la suscripción, y UNA bienvenida registrada para esa ficha. */
function c6_check( $caso, $r, $ficha_esperada ) {
	$fichas = count( $r['ins'] ) + ( $r['upd'] ? 1 : 0 );
	t( "$caso: termina con 1 ficha (ni duplicada ni perdida)", 1 === $fichas && count( $r['upd'] ) <= 1, json_encode( array( count( $r['ins'] ), count( $r['upd'] ) ) ) );
	$fila = $r['ins'] ? $r['ins'][0] : ( $r['upd'] ? $r['upd'][0][2] : array() );
	t( "$caso: activa y con su suscripción", 'activo' === ( $fila['estado'] ?? '' ) && C6_PRE === ( $fila['preapproval_id'] ?? '' ) );
	$id = $r['ins'] ? 999 : ( $r['upd'] ? (int) $r['upd'][0][3]['id'] : 0 );
	t( "$caso: es la ficha esperada ($ficha_esperada)", $ficha_esperada === $id, (string) $id );
	t( "$caso: bienvenida_mail_enviado (una vez, de esa ficha)", 1 === count( $r['bien'] ) && $ficha_esperada === $r['bien'][0]['ficha_id'] );
}

seccion( 'CASO 1: trial con celular en texto paga por link firmado' );
$w = c6_prep( c6_ficha() );
$r = CN_Suscribir::procesar( CN_Suscribir::crear_token( 304, 'basico', 'mail_d7', null, C6_SECRETO ) );
$p = c6_capturar( $w );
t( 'caso 1: va a pagar a MP', 'creada' === $r['caso'] );
CN_Webhook::activar_sub_publico( $p['external_reference'], C6_PRE );
c6_check( 'caso 1', c6_resultado( $w ), 304 );

seccion( 'CASO 2: trial con celular solo hasheado (caso 304) paga por link firmado' );
$w = c6_prep( c6_ficha( array( 'celular_texto_plano' => null ) ) );
$r = CN_Suscribir::procesar( CN_Suscribir::crear_token( 304, 'basico', 'mail_d7', null, C6_SECRETO ) );
$p = c6_capturar( $w );
t( 'caso 2: va a pagar a MP (antes: sin_celular)', 'creada' === $r['caso'] && 0 === strpos( $p['external_reference'], 'sub_f304_' ) );
CN_Webhook::activar_sub_publico( $p['external_reference'], C6_PRE );
c6_check( 'caso 2', c6_resultado( $w ), 304 );

seccion( 'CASO 3: persona nueva paga por /planes-club/' );
$w = c6_prep( null );
$r = CN_Shortcodes::procesar_planes_form( array( 'cn_planes_submit' => '1', 'cn_planes_plan' => 'basico', 'cn_planes_nombre' => 'Persona Nueva', 'cn_planes_celular' => '11 7777 0000', 'cn_planes_email' => 'nueva@gmail.com' ) );
$p = c6_capturar( $w );
t( 'caso 3: va a pagar a MP', $r['ok'] );
CN_Webhook::activar_sub_publico( $p['external_reference'], C6_PRE );
c6_check( 'caso 3', c6_resultado( $w ), 999 );

seccion( 'CASO 4: trial paga por /planes-club/ en vez del link (otro celular, mismo email) → NO duplica' );
$w = c6_prep( c6_ficha() );
$r = CN_Shortcodes::procesar_planes_form( array( 'cn_planes_submit' => '1', 'cn_planes_plan' => 'basico', 'cn_planes_nombre' => 'Socia Trescientos', 'cn_planes_celular' => '11 6666 0340', 'cn_planes_email' => 'Socia.304@gmail.com' ) );
$p = c6_capturar( $w );
CN_Webhook::activar_sub_publico( $p['external_reference'], C6_PRE );
c6_check( 'caso 4', c6_resultado( $w ), 304 );

seccion( 'CASO 5: trial paga con OTRO email en MP → se vincula por el token' );
$w = c6_prep( c6_ficha() );
$tb = CN_Suscribir_Fallback::token( 304, 'basico', 'mail_d7' );
$r = CN_Shortcodes::procesar_planes_form( array( 'cn_planes_submit' => '1', 'cn_planes_plan' => 'basico', 'cn_planes_nombre' => 'Socia Trescientos', 'cn_planes_celular' => '', 'cn_planes_email' => 'otra.cuenta.mp@hotmail.com', 'cn_planes_pre' => $tb ) );
$p = c6_capturar( $w );
t( 'caso 5: la pendiente lleva la ficha del token y el otro email', 304 === ( $p['ficha_id'] ?? 0 ) && 'otra.cuenta.mp@hotmail.com' === $p['email'] );
CN_Webhook::activar_sub_publico( $p['external_reference'], C6_PRE );
c6_check( 'caso 5', c6_resultado( $w ), 304 );

seccion( 'CASO 6: el webhook no llega → activa por la página de vuelta (y el sync / el webhook tardío no repiten)' );
$w = c6_prep( c6_ficha( array( 'celular_texto_plano' => null ) ) );
CN_Suscribir::procesar( CN_Suscribir::crear_token( 304, 'basico', 'mail_d7', null, C6_SECRETO ) );
$p = c6_capturar( $w );
c6_mp_responde( $p );
$msg = CN_Entrega::mensaje_retorno( array( 'preapproval_id' => C6_PRE ) );
t( 'caso 6: la página muestra cómo entrar', CN_Entrega::MSG_ACTIVA === $msg );
$w->on( '/SELECT external_reference FROM wp_cn_suscripciones_pendientes WHERE usado = 0/', array( $p['external_reference'] ) );
cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => array( array( 'id' => C6_PRE, 'status' => 'authorized', 'external_reference' => $p['external_reference'] ) ) ) );
CN_Entrega::sync_activar();
CN_Webhook::activar_sub_publico( $p['external_reference'], C6_PRE );
c6_check( 'caso 6 (vuelta)', c6_resultado( $w ), 304 );
t( 'caso 6: un solo mail a la socia', 1 === count( array_filter( $GLOBALS['cn_mails'], function ( $m ) { return 'socia.304@gmail.com' === $m['to']; } ) ) );

$w = c6_prep( c6_ficha( array( 'celular_texto_plano' => null ) ) );
CN_Suscribir::procesar( CN_Suscribir::crear_token( 304, 'basico', 'mail_d7', null, C6_SECRETO ) );
$p = c6_capturar( $w );
$w->on( '/SELECT external_reference FROM wp_cn_suscripciones_pendientes WHERE usado = 0/', array( $p['external_reference'] ) );
cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => array( array( 'id' => C6_PRE, 'status' => 'authorized', 'external_reference' => $p['external_reference'] ) ) ) );
CN_Entrega::sync_activar();
CN_Entrega::sync_activar();
c6_check( 'caso 6 (sync)', c6_resultado( $w ), 304 );
