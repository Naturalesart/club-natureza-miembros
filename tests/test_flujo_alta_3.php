<?php
// Flujo de alta — PR 3 (etapas 4, 2B y 5, entrega del acceso): registro y reintento del mail de bienvenida, plan B de
// activación (página de vuelta y sync), "reenviame el acceso" y subscription_authorized_payment. Flags off | log | on.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $p = '' ) {
		return 'https://naturalesart.com/wp-json/' . ltrim( $p, '/' );
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook, $args = array() ) {
		$GLOBALS['cn_single_events'][] = array( 'ts' => (int) $ts, 'hook' => $hook, 'args' => $args );
		return true;
	}
}
const FC_PRE = 'aaaabbbbccccddddeeeeffff00001111'; // preapproval_id de prueba (32 hex)
const FC_CEL = '1155550304';

function fc_ficha( $extra = array() ) {
	return cn_fila( $extra + array(
		'id' => 304, 'nombre_apellido' => 'Socia Trescientos', 'email' => 'socia.304@gmail.com', 'celular_texto_plano' => null,
		'celular_hash' => CN_Helpers::hash_celular( FC_CEL ), 'estado' => 'activo', 'preapproval_id' => null, 'plan' => null,
		'acceso_hasta' => null, 'baja_en' => null,
	) );
}
function fc_pend( $extra = array() ) {
	return cn_fila( $extra + array(
		'id' => 91, 'external_reference' => 'sub_f304_abcdef12', 'nombre_apellido' => 'Socia Trescientos', 'celular' => '',
		'email' => 'socia.304@gmail.com', 'plan' => 'basico', 'origen' => 'mail_d7', 'fbp' => null, 'fbc' => null,
		'ip' => null, 'user_agent' => null, 'usado' => 0, 'ficha_id' => 304,
	) );
}
/**
 * Base con: alta automática prendida, pendiente sub_f304_ sin usar, ficha 304 por id, MP con la suscripción autorizada.
 * La base "recuerda" la activación: después del UPDATE de la ficha, la pendiente figura usada y la ficha tiene el preapproval.
 */
function fc_prep( $flags = array() ) {
	cn_test_reset();
	global $wpdb;
	$GLOBALS['cn_single_events'] = array();
	$_GET = $_POST = array();
	$_SERVER['REMOTE_ADDR'] = '190.1.2.3';
	update_option( 'cn_mp_access_token', 'TOKEN-SUB' );
	update_option( 'cn_sub_auto_activar', '1' );
	update_option( 'cn_suscribir_fallback_modo', 'on' );
	foreach ( $flags as $k => $v ) {
		update_option( $k, $v );
	}
	$activada = function () use ( $wpdb ) {
		foreach ( $wpdb->log as $l ) {
			if ( 'update' === $l[0] && false !== strpos( $l[1], 'miembros' ) && isset( $l[2]['preapproval_id'] ) ) {
				return true;
			}
		}
		return false;
	};
	$wpdb->on( '/FROM wp_cn_suscripciones_pendientes WHERE external_reference/', function () use ( $activada ) {
		return array( fc_pend( array( 'usado' => $activada() ? 1 : 0 ) ) );
	} );
	$wpdb->on( '/FROM wp_cn_miembros WHERE id = 304\b/', array( fc_ficha() ) );
	$wpdb->on( '/FROM wp_cn_miembros WHERE preapproval_id = /', function () use ( $activada ) {
		return $activada() ? 304 : null;
	} );
	cn_http_on( 'GET', '#/preapproval/' . FC_PRE . '$#', array( 'id' => FC_PRE, 'status' => 'authorized', 'external_reference' => 'sub_f304_abcdef12', 'next_payment_date' => '2026-12-11T21:33:41.000-04:00' ) );
	return $wpdb;
}
function fc_log( $w, $tipo ) {
	$o = array();
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && $tipo === $l[2]['tipo'] ) {
			$o[] = json_decode( $l[2]['payload'], true );
		}
	}
	return $o;
}
function fc_mails_a( $to ) {
	return array_values( array_filter( $GLOBALS['cn_mails'], function ( $m ) use ( $to ) { return $to === $m['to']; } ) );
}
function fc_activaciones( $w ) {
	return count( array_filter( $w->log, function ( $l ) { return 'update' === $l[0] && false !== strpos( $l[1], 'miembros' ) && isset( $l[2]['preapproval_id'] ); } ) );
}

seccion( 'PR3 mail de bienvenida: off = nada nuevo' );
$w = fc_prep( array( 'cn_alta_reintento_modo' => 'off' ) );
CN_Webhook::activar_sub_publico( 'sub_f304_abcdef12', FC_PRE );
t( 'off: el mail sale como siempre', 1 === count( fc_mails_a( 'socia.304@gmail.com' ) ) );
t( 'off: sin registro nuevo', ! array_intersect( $w->log_mp_tipos(), array( 'bienvenida_mail_enviado', 'bienvenida_mail_fallo' ) ) );

seccion( 'PR3 mail de bienvenida: on registra bienvenida_mail_enviado con ficha_id y sin email' );
$w = fc_prep( array( 'cn_alta_reintento_modo' => 'on' ) );
CN_Webhook::activar_sub_publico( 'sub_f304_abcdef12', FC_PRE );
$e = fc_log( $w, 'bienvenida_mail_enviado' );
t( 'on: bienvenida_mail_enviado de la ficha 304', 1 === count( $e ) && 304 === $e[0]['ficha_id'] );
t( 'on: el registro no lleva email', false === stripos( json_encode( $e ), 'gmail' ) );
t( 'on: no queda reintento programado', ! $GLOBALS['cn_single_events'] );

seccion( 'PR3 mail de bienvenida: falla → reintento a los 10 min, máx 3, después alerta a hola@' );
$w = fc_prep( array( 'cn_alta_reintento_modo' => 'on' ) );
$GLOBALS['cn_mail_falla'] = true;
CN_Webhook::activar_sub_publico( 'sub_f304_abcdef12', FC_PRE );
$ev = $GLOBALS['cn_single_events'];
t( 'fallo: bienvenida_mail_fallo', 1 === count( fc_log( $w, 'bienvenida_mail_fallo' ) ) );
t( 'fallo: reintento programado a los 10 min para la 304', 1 === count( $ev ) && CN_Entrega::CRON === $ev[0]['hook'] && array( 304 ) === $ev[0]['args'] && $ev[0]['ts'] >= time() + 590 );
CN_Entrega::reintentar( 304 );
CN_Entrega::reintentar( 304 );
t( 'fallo: 2 reintentos más, cada uno reprogramado', 3 === count( $GLOBALS['cn_single_events'] ) );
CN_Entrega::reintentar( 304 );
$hola = fc_mails_a( 'hola@naturalesart.com' );
t( 'fallo: tras el 3.er reintento, alerta a hola@ y no reprograma', 1 === count( $hola ) && 3 === count( $GLOBALS['cn_single_events'] ) );
t( 'fallo: alerta sin datos personales', $hola && false === stripos( $hola[0]['asunto'] . $hola[0]['cuerpo'], 'gmail' ) && false === stripos( $hola[0]['cuerpo'], 'Trescientos' ) );
t( 'fallo: 4 intentos registrados (envío + 3 reintentos)', 4 === count( fc_log( $w, 'bienvenida_mail_fallo' ) ) );
CN_Entrega::reintentar( 304 );
t( 'fallo: un cron de más no hace nada', 4 === count( fc_log( $w, 'bienvenida_mail_fallo' ) ) );
$w = fc_prep( array( 'cn_alta_reintento_modo' => 'on' ) );
$GLOBALS['cn_mail_falla'] = true;
CN_Webhook::activar_sub_publico( 'sub_f304_abcdef12', FC_PRE );
$GLOBALS['cn_mail_falla'] = false;
CN_Entrega::reintentar( 304 );
t( 'reintento que sale bien: bienvenida_mail_enviado y no reprograma', 1 === count( fc_log( $w, 'bienvenida_mail_enviado' ) ) && 1 === count( $GLOBALS['cn_single_events'] ) );
$w = fc_prep( array( 'cn_alta_reintento_modo' => 'log' ) );
$GLOBALS['cn_mail_falla'] = true;
CN_Webhook::activar_sub_publico( 'sub_f304_abcdef12', FC_PRE );
t( 'log: registra el fallo pero no reintenta', 1 === count( fc_log( $w, 'bienvenida_mail_fallo' ) ) && ! $GLOBALS['cn_single_events'] );

seccion( 'PR3 caso 6a: el webhook no llega → la página de vuelta activa y muestra cómo entrar' );
$w = fc_prep( array( 'cn_retorno_activar_modo' => 'on', 'cn_alta_reintento_modo' => 'on' ) );
$msg = CN_Entrega::mensaje_retorno( array( 'preapproval_id' => FC_PRE ) );
t( 'vuelta: activa la 304', 1 === fc_activaciones( $w ) && CN_Entrega::MSG_ACTIVA === $msg );
t( 'vuelta: el mensaje dice cómo entrar (nombre y apellido + celular)', false !== strpos( $msg, 'nombre y apellido' ) && false !== strpos( $msg, 'celular' ) );
t( 'vuelta: bienvenida_mail_enviado', 1 === count( fc_log( $w, 'bienvenida_mail_enviado' ) ) );
$msg2 = CN_Entrega::mensaje_retorno( array( 'preapproval_id' => FC_PRE ) );
CN_Webhook::activar_sub_publico( 'sub_f304_abcdef12', FC_PRE ); // el webhook llega tarde
t( 'idempotencia: recargar la página y el webhook tardío no activan de nuevo', 1 === fc_activaciones( $w ) && CN_Entrega::MSG_ACTIVA === $msg2 );
t( 'idempotencia: el mail sale UNA sola vez', 1 === count( fc_mails_a( 'socia.304@gmail.com' ) ) );

seccion( 'PR3 vuelta: off no hace nada; log no activa; sin alta automática no activa' );
$w = fc_prep( array( 'cn_retorno_activar_modo' => 'off' ) );
t( 'off: sin mensaje ni consulta a MP', '' === CN_Entrega::mensaje_retorno( array( 'preapproval_id' => FC_PRE ) ) && ! cn_http_calls( '#/preapproval/#' ) );
$w = fc_prep( array( 'cn_retorno_activar_modo' => 'log' ) );
$msg = CN_Entrega::mensaje_retorno( array( 'preapproval_id' => FC_PRE ) );
t( 'log: no activa, muestra "confirmando" y registra', 0 === fc_activaciones( $w ) && CN_Entrega::MSG_CONFIRMANDO === $msg && 1 === count( fc_log( $w, 'activar_plan_b_log' ) ) );
$w = fc_prep( array( 'cn_retorno_activar_modo' => 'on', 'cn_sub_auto_activar' => '0' ) );
CN_Entrega::mensaje_retorno( array( 'preapproval_id' => FC_PRE ) );
t( 'cn_sub_auto_activar apagado: no activa', 0 === fc_activaciones( $w ) );
$w = fc_prep( array( 'cn_retorno_activar_modo' => 'on' ) );
t( 'id inválido: nada', '' === CN_Entrega::mensaje_retorno( array( 'preapproval_id' => "x' OR 1=1" ) ) && ! cn_http_calls( '#/preapproval/#' ) );
cn_http_on( 'GET', '#/preapproval/' . FC_PRE . '$#', array( 'id' => FC_PRE, 'status' => 'pending', 'external_reference' => 'sub_f304_abcdef12' ) );
t( 'todavía pendiente en MP: "confirmando", sin activar', CN_Entrega::MSG_CONFIRMANDO === CN_Entrega::mensaje_retorno( array( 'preapproval_id' => FC_PRE ) ) && 0 === fc_activaciones( $w ) );

seccion( 'PR3 caso 6b: el webhook no llega → el sync activa' );
$w = fc_prep( array( 'cn_retorno_activar_modo' => 'on', 'cn_alta_reintento_modo' => 'on' ) );
$w->on( '/SELECT external_reference FROM wp_cn_suscripciones_pendientes WHERE usado = 0/', array( 'sub_f304_abcdef12', 'sub_1199990000_ffffffff' ) );
cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => array(
	array( 'id' => FC_PRE, 'status' => 'authorized', 'external_reference' => 'sub_f304_abcdef12' ),
	array( 'id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'status' => 'authorized', 'external_reference' => 'otra_cosa' ),
), 'paging' => array( 'total' => 2 ) ) );
$r = CN_Entrega::sync_activar();
t( 'sync: encuentra y activa la de la pendiente', 1 === $r['encontradas'] && 1 === $r['activadas'] && 1 === fc_activaciones( $w ) );
t( 'sync: no toca suscripciones ajenas', 1 === fc_activaciones( $w ) );
$r2 = CN_Entrega::sync_activar();
t( 'sync: segunda corrida no activa de nuevo y el mail sale una vez', 1 === fc_activaciones( $w ) && 1 === count( fc_mails_a( 'socia.304@gmail.com' ) ) );
$w = fc_prep( array( 'cn_retorno_activar_modo' => 'on' ) );
$w->on( '/SELECT external_reference FROM wp_cn_suscripciones_pendientes WHERE usado = 0/', array() );
CN_Entrega::sync_activar();
t( 'sync sin pendientes: no consulta a MP', ! cn_http_calls( '#/preapproval/search#' ) );

seccion( 'PR3 reenvío del acceso' );
$w = fc_prep( array( 'cn_reenvio_acceso_modo' => 'off' ) );
t( 'off: no hay formulario ni proceso', '' === CN_Entrega::form_reenvio() && '' === CN_Entrega::reenvio( array( 'cn_reenvio_email' => 'socia.304@gmail.com' ) ) );
$w = fc_prep( array( 'cn_reenvio_acceso_modo' => 'on' ) );
$w->on( '/LOWER\(TRIM\(email\)\) = \'socia\.304@gmail\.com\' AND estado/', array( fc_ficha( array( 'celular_texto_plano' => '1155550304' ) ) ) );
$m = CN_Entrega::reenvio( array( 'cn_reenvio_email' => '  Socia.304@Gmail.com ' ) );
$a = fc_mails_a( 'socia.304@gmail.com' );
t( 'on: manda los datos al email de la ficha', 1 === count( $a ) && false !== strpos( $a[0]['cuerpo'], 'Socia Trescientos' ) );
t( 'on: respuesta genérica', CN_Entrega::MSG_REENVIO === $m );
t( 'on: registro con ficha_id, sin email', 1 === count( fc_log( $w, 'reenvio_acceso_enviado' ) ) && false === stripos( json_encode( fc_log( $w, 'reenvio_acceso_enviado' ) ), 'gmail' ) );
CN_Entrega::reenvio( array( 'cn_reenvio_email' => 'socia.304@gmail.com' ) );
t( 'on: el mismo email no recibe otro en la hora', 1 === count( fc_mails_a( 'socia.304@gmail.com' ) ) );
$m0 = CN_Entrega::reenvio( array( 'cn_reenvio_email' => 'nadie@gmail.com' ) );
t( 'email que no existe: misma respuesta, ningún mail', CN_Entrega::MSG_REENVIO === $m0 && 1 === count( $GLOBALS['cn_mails'] ) );
CN_Entrega::reenvio( array( 'cn_reenvio_email' => 'otro1@gmail.com' ) );
CN_Entrega::reenvio( array( 'cn_reenvio_email' => 'otro2@gmail.com' ) );
t( 'rate limit por IP (3 cada 10 min)', in_array( 'reenvio_acceso_limitado', $w->log_mp_tipos(), true ) );
$w = fc_prep( array( 'cn_reenvio_acceso_modo' => 'log' ) );
$w->on( '/LOWER\(TRIM\(email\)\) = /', array( fc_ficha() ) );
CN_Entrega::reenvio( array( 'cn_reenvio_email' => 'socia.304@gmail.com' ) );
t( 'log: no manda, registra', ! $GLOBALS['cn_mails'] && 1 === count( fc_log( $w, 'reenvio_acceso_log' ) ) );
t( 'el login no cambia: sigue comparando nombre + celular normalizado', false !== strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-auth.php' ), '$norm = CN_Helpers::normalizar_celular( $celular_raw );' ) );

seccion( 'PR3 subscription_authorized_payment (el 404)' );
$w = fc_prep( array( 'cn_authorized_payment_modo' => 'off' ) );
t( 'off: sigue el camino de siempre', false === CN_Entrega::authorized_payment( '7001' ) && ! cn_http_calls( '#/authorized_payments/#' ) );
$w = fc_prep( array( 'cn_authorized_payment_modo' => 'on' ) );
cn_http_on( 'GET', '#/authorized_payments/7001$#', array( 'id' => 7001, 'status' => 'processed', 'preapproval_id' => FC_PRE, 'payment' => array( 'id' => 123456789, 'status' => 'approved' ) ) );
cn_http_on( 'GET', '#/v1/payments/123456789$#', array( 'id' => 123456789, 'status' => 'approved', 'external_reference' => 'sub_f304_abcdef12', 'metadata' => array( 'preapproval_id' => FC_PRE ) ) );
t( 'on: se hace cargo', true === CN_Entrega::authorized_payment( '7001' ) );
t( 'on: consulta /authorized_payments y el pago real, nunca /v1/payments/7001', cn_http_calls( '#/authorized_payments/7001$#' ) && cn_http_calls( '#/v1/payments/123456789$#' ) && ! cn_http_calls( '#/v1/payments/7001$#' ) );
$w = fc_prep( array( 'cn_authorized_payment_modo' => 'log' ) );
cn_http_on( 'GET', '#/authorized_payments/7001$#', array( 'id' => 7001, 'payment' => array( 'id' => 123456789 ) ) );
t( 'log: registra y deja seguir el camino de siempre', false === CN_Entrega::authorized_payment( '7001' ) && 1 === count( fc_log( $w, 'authorized_payment' ) ) );
