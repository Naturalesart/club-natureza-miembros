<?php
// C1: con preapproval_id, la fecha_fin_trial vencida NO cancela ni bloquea (login y get_miembro_actual); solo manda estado.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
foreach ( array( 'MINUTE_IN_SECONDS' => 60, 'COOKIEPATH' => '/', 'COOKIE_DOMAIN' => '' ) as $k => $v ) {
	if ( ! defined( $k ) ) {
		define( $k, $v );
	}
}
if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl() {
		return false;
	}
}
const C1_CEL = '1155552165';
const C1_VENCIDA = '2026-10-01 10:00:00'; // anterior a time() real: vencida
function c1_ficha( $pre, $estado = 'activo', $fecha = C1_VENCIDA ) {
	return cn_fila( array( 'id' => 7, 'nombre_apellido' => 'Sandra Perez', 'celular_hash' => CN_Helpers::hash_celular( C1_CEL ), 'estado' => $estado, 'fecha_fin_trial' => $fecha, 'preapproval_id' => $pre ) );
}
function c1_login( $ficha ) {
	cn_test_reset();
	global $wpdb;
	$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
	$_COOKIE = array();
	$wpdb->on( '/FROM wp_cn_miembros WHERE LOWER\(TRIM/', array( $ficha ) );
	ob_start();
	$r = CN_Auth::login( 'Sandra Perez', '11 5555-2165' );
	ob_end_clean();
	return array( $r, $wpdb );
}
function c1_sesion( $ficha ) {
	cn_test_reset();
	global $wpdb;
	$_COOKIE = array( CN_Auth::COOKIE_NAME => str_repeat( 'a', 64 ) );
	$wpdb->on( '/INNER JOIN/', array( $ficha ) );
	ob_start();
	$m = CN_Auth::get_miembro_actual();
	ob_end_clean();
	return array( $m, $wpdb );
}
/** Escrituras que cancelan: UPDATE por query() con estado cancelada, o update() con ese estado. */
function c1_cancela( $w ) {
	foreach ( $w->log as $l ) {
		if ( 'q' === $l[0] && false !== strpos( $l[1], "SET estado = 'cancelada'" ) ) {
			return true;
		}
		if ( 'update' === $l[0] && false !== strpos( $l[1], 'miembros' ) && isset( $l[2]['estado'] ) ) {
			return true;
		}
	}
	return false;
}
function c1_borra_sesion( $w ) {
	foreach ( $w->log as $l ) {
		if ( 'delete' === $l[0] ) {
			return true;
		}
	}
	return false;
}
function c1_crea_sesion( $w ) {
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'sesiones' ) ) {
			return true;
		}
	}
	return false;
}

seccion( 'login: con suscripción, el trial vencido no cancela' );
list( $r, $w ) = c1_login( c1_ficha( 'PRE_ABCDEF1234567890' ) );
t( 'con preapproval_id + trial vencido + estado activo: entra', true === $r['ok'] && 'ok' === $r['motivo'] );
t( 'no escribe estado (ni UPDATE ni update()) y crea la sesión', ! c1_cancela( $w ) && c1_crea_sesion( $w ) );
t( 'no consulta a Mercado Pago', ! $GLOBALS['cn_http_log'] );
t( 'el SELECT del login trae preapproval_id', (bool) array_filter( $w->consultas, function ( $q ) {
	return false !== strpos( $q, 'fecha_fin_trial, preapproval_id FROM' );
} ) );
list( $r, $w ) = c1_login( c1_ficha( '0' ) );
t( "preapproval_id '0' cuenta como valor (igual que el cron): entra y no cancela", true === $r['ok'] && ! c1_cancela( $w ) );

seccion( 'login: sin suscripción sigue cancelándose (regresión)' );
foreach ( array( 'NULL' => null, "''" => '' ) as $nombre => $pre ) {
	list( $r, $w ) = c1_login( c1_ficha( $pre ) );
	t( "preapproval_id {$nombre} + trial vencido: 'vencido', se cancela y no hay sesión", false === $r['ok'] && 'vencido' === $r['motivo'] && c1_cancela( $w ) && ! c1_crea_sesion( $w ) );
}
list( $r, $w ) = c1_login( c1_ficha( null, 'activo', '2099-01-01 00:00:00' ) );
t( 'trial vigente sin suscripción: entra, sin cambios', true === $r['ok'] && ! c1_cancela( $w ) );
list( $r, $w ) = c1_login( c1_ficha( null, 'activo', null ) );
t( 'sin fecha_fin_trial (manual): entra, sin cambios', true === $r['ok'] && ! c1_cancela( $w ) );

seccion( 'login: con suscripción el estado sigue mandando' );
list( $r, $w ) = c1_login( c1_ficha( 'PRE_ABCDEF1234567890', 'pausado' ) );
t( 'con sub + trial vencido + pausado: bloqueada (pausado), sin sesión y sin escrituras', false === $r['ok'] && 'pausado' === $r['motivo'] && ! c1_crea_sesion( $w ) && ! c1_cancela( $w ) );
list( $r, $w ) = c1_login( c1_ficha( 'PRE_ABCDEF1234567890', 'cancelada' ) );
t( 'con sub + trial vencido + cancelada: bloqueada, sin sesión y sin escrituras', false === $r['ok'] && 'vencido' === $r['motivo'] && ! c1_crea_sesion( $w ) && ! c1_cancela( $w ) );

seccion( 'get_miembro_actual (sesión abierta)' );
list( $m, $w ) = c1_sesion( c1_ficha( 'PRE_ABCDEF1234567890' ) );
t( 'con sub + trial vencido + activo: devuelve la socia, sin logout ni UPDATE', is_object( $m ) && 7 === (int) $m->id && ! c1_borra_sesion( $w ) && ! c1_cancela( $w ) && ! $w->log );
list( $m, $w ) = c1_sesion( c1_ficha( 'PRE_ABCDEF1234567890', 'pausado' ) );
t( 'con sub + pausado: null (bloquea) pero sin logout ni UPDATE', null === $m && ! c1_borra_sesion( $w ) && ! c1_cancela( $w ) );
list( $m, $w ) = c1_sesion( c1_ficha( 'PRE_ABCDEF1234567890', 'cancelada' ) );
t( 'con sub + cancelada: null, sin escrituras', null === $m && ! c1_cancela( $w ) );
list( $m, $w ) = c1_sesion( c1_ficha( null ) );
t( 'sin sub + trial vencido (regresión): se cancela, logout y null', null === $m && c1_cancela( $w ) && c1_borra_sesion( $w ) );
t( 'el SELECT de la sesión trae preapproval_id', (bool) array_filter( $w->consultas, function ( $q ) {
	return false !== strpos( $q, 'm.preapproval_id' );
} ) );

seccion( 'marcar_cancelada_si_hace_falta' );
cn_test_reset();
$w = $GLOBALS['wpdb'];
cn_llamar( 'CN_Auth', 'marcar_cancelada_si_hace_falta', 5, 'activo' );
$q = '';
foreach ( $w->log as $l ) {
	if ( 'q' === $l[0] ) {
		$q = $l[1];
	}
}
t( 'el UPDATE solo alcanza a fichas sin preapproval_id (NULL o vacío) y exige el id', false !== strpos( $q, 'WHERE id = 5 AND (preapproval_id IS NULL OR preapproval_id = \'\')' ) && false !== strpos( $q, "estado = 'cancelada'" ), $q );
cn_test_reset();
$w = $GLOBALS['wpdb'];
cn_llamar( 'CN_Auth', 'marcar_cancelada_si_hace_falta', 5, 'cancelada' );
t( 'ya cancelada: no escribe', ! $w->log );
t( "'0' cuenta como valor: no vence el acceso; NULL y '' sí", ! cn_llamar( 'CN_Auth', 'trial_vence_el_acceso', c1_ficha( '0' ) ) && cn_llamar( 'CN_Auth', 'trial_vence_el_acceso', c1_ficha( null ) ) && cn_llamar( 'CN_Auth', 'trial_vence_el_acceso', c1_ficha( '' ) ) && ! cn_llamar( 'CN_Auth', 'trial_vence_el_acceso', c1_ficha( 'PRE_X' ) ) );

seccion( 'alcance: no se tocó el cron, la guarda, el webhook, el sync ni MP' );
$raiz = dirname( __DIR__ ) . '/includes/';
$auth = file_get_contents( $raiz . 'class-cn-auth.php' );
t( 'CN_Auth no llama a Mercado Pago ni a flags nuevos', false === strpos( $auth, 'wp_remote_' ) && false === strpos( $auth, 'cn_c1' ) );
t( 'el cron sigue teniendo su comparación estricta y su guarda', false !== strpos( $auth, "'' !== (string) \$miembro->preapproval_id" ) && false !== strpos( $auth, 'guarda_trial_solo_log' ) );
