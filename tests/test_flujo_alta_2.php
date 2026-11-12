<?php
// Flujo de alta — PR 2 (etapa 3, una sola ficha por persona): CN_Identidad::resolver() y su uso en
// CN_Webhook::activar_por_suscripcion_sub() detrás de cn_identidad_modo (off = como siempre, log = solo registra, on).
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $p = '' ) {
		return 'https://naturalesart.com/wp-json/' . ltrim( $p, '/' );
	}
}
const FB_CEL_TRIAL = '1155550304'; // celular con el que se hizo el trial (ficha 304)
const FB_CEL_OTRO  = '1166660340'; // celular escrito en /planes-club/ (distinto: caso real)

function fb_ficha( $extra = array() ) {
	return cn_fila( $extra + array(
		'id' => 304, 'celular_hash' => CN_Helpers::hash_celular( FB_CEL_TRIAL ), 'preapproval_id' => null, 'plan' => null,
		'acceso_hasta' => null, 'celular_texto_plano' => null, 'email' => 'Socia.304@Gmail.com ',
		'nombre_apellido' => 'Socia Trescientos', 'estado' => 'activo', 'baja_en' => null,
	) );
}
function fb_pend( $extra = array() ) {
	return cn_fila( $extra + array(
		'id' => 88, 'external_reference' => 'sub_' . FB_CEL_OTRO . '_abcdef12', 'nombre_apellido' => 'Socia Trescientos',
		'celular' => FB_CEL_OTRO, 'email' => 'socia.304@gmail.com', 'plan' => 'basico', 'origen' => 'landing',
		'fbp' => null, 'fbc' => null, 'ip' => null, 'user_agent' => null, 'usado' => 0,
	) );
}
/** $por_email / $por_celular: filas que devuelve la base para esa búsqueda; $por_id: ficha que existe por id. */
function fb_prep( $modo, $pend, $por_email = array(), $por_celular = array(), $por_id = null ) {
	cn_test_reset();
	global $wpdb;
	update_option( 'cn_mp_access_token', 'TOKEN-SUB' );
	update_option( 'cn_sub_auto_activar', '1' );
	update_option( CN_Identidad::OPT_MODO, $modo );
	$wpdb->on( '/FROM wp_cn_suscripciones_pendientes WHERE external_reference/', array( $pend ) );
	$wpdb->on( '/LOWER\(TRIM\(email\)\)/', $por_email );
	$wpdb->on( '/celular_hint = /', $por_celular );
	if ( $por_id ) {
		$wpdb->on( '/FROM wp_cn_miembros WHERE id = ' . (int) $por_id->id . '\b/', array( $por_id ) );
	}
	cn_http_on( 'GET', '#/preapproval/pre_abc#', array( 'id' => 'pre_abc', 'status' => 'authorized', 'next_payment_date' => '2026-12-11T21:33:41.000-04:00' ) );
	return $wpdb;
}
function fb_correr( $pend ) {
	return cn_llamar( 'CN_Webhook', 'activar_por_suscripcion_sub', $pend->external_reference, 'pre_abc' );
}
function fb_ins( $w ) {
	return array_values( array_filter( $w->escrituras( 'miembros' ), function ( $l ) { return 'insert' === $l[0]; } ) );
}
function fb_upd( $w ) {
	return array_values( array_filter( $w->escrituras( 'miembros' ), function ( $l ) { return 'update' === $l[0]; } ) );
}
function fb_log( $w, $tipo ) {
	$o = array();
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && $tipo === $l[2]['tipo'] ) {
			$o[] = json_decode( $l[2]['payload'], true );
		}
	}
	return $o;
}

seccion( 'PR2 resolver: piezas' );
cn_test_reset();
t( 'email normalizado', 'socia.304@gmail.com' === CN_Identidad::normalizar_email( '  Socia.304@Gmail.COM ' ) );
t( 'ficha de referencia sub_f304_', 304 === CN_Identidad::ficha_de_referencia( 'sub_f304_abcdef12' ) );
t( 'referencia de siempre no es ficha', 0 === CN_Identidad::ficha_de_referencia( 'sub_1155550304_abcdef12' ) );

seccion( 'PR2 off — caso 4 hoy: trial paga por /planes-club/ con otro celular → ficha nueva (como siempre)' );
$p = fb_pend();
$w = fb_prep( 'off', $p, array( fb_ficha() ) );
fb_correr( $p );
t( 'off: crea ficha nueva (comportamiento actual, sin cambios)', 1 === count( fb_ins( $w ) ) );
t( 'off: no busca por email', ! array_filter( $w->consultas, function ( $q ) { return false !== strpos( $q, 'LOWER(TRIM(email))' ); } ) );
t( 'off: sin rastro de identidad', ! array_intersect( $w->log_mp_tipos(), array( 'identidad_log', 'identidad_resuelta' ) ) );

seccion( 'PR2 log: no cambia nada, registra qué ficha habría usado' );
$w = fb_prep( 'log', $p, array( fb_ficha() ) );
fb_correr( $p );
$l = fb_log( $w, 'identidad_log' );
t( 'log: igual crea la ficha nueva', 1 === count( fb_ins( $w ) ) );
t( 'log: registra que habría usado la 304 por email', 1 === count( $l ) && 304 === $l[0]['ficha_id'] && 'email' === $l[0]['via'] );
t( 'log: el rastro no lleva email ni celular', false === stripos( json_encode( $l ), 'gmail' ) && false === strpos( json_encode( $l ), FB_CEL_OTRO ) );

seccion( 'PR2 on — caso 4: trial paga por /planes-club/ → NO duplica, convierte su ficha' );
$w = fb_prep( 'on', $p, array( fb_ficha() ) );
t( 'on: se hizo cargo', true === fb_correr( $p ) );
$u = fb_upd( $w );
t( 'on: no crea ficha', ! fb_ins( $w ) );
t( 'on: convierte la 304 en suscripta', $u && 304 === $u[0][3]['id'] && 'activo' === $u[0][2]['estado'] && 'pre_abc' === $u[0][2]['preapproval_id'] && 'basico' === $u[0][2]['plan'] );
t( 'on: no toca nombre, celular ni email de la ficha (conserva historial)', $u && ! array_intersect( array_keys( $u[0][2] ), array( 'nombre_apellido', 'celular_hash', 'email', 'fecha_alta' ) ) );
t( 'on: la pendiente queda usada', (bool) array_filter( $w->escrituras( 'suscripciones_pendientes' ), function ( $l ) { return 'update' === $l[0] && 1 === $l[2]['usado']; } ) );
t( 'on: mail de bienvenida al email de la pendiente', 1 === count( array_filter( $GLOBALS['cn_mails'], function ( $m ) { return 'socia.304@gmail.com' === $m['to']; } ) ) );

seccion( 'PR2 on — caso 5: trial paga con OTRO email en MP → se vincula por la ficha del token' );
$p5 = fb_pend( array( 'email' => 'otra.cuenta@hotmail.com', 'ficha_id' => 304, 'origen' => 'mail_d7' ) );
$w  = fb_prep( 'on', $p5, array(), array(), fb_ficha() );
fb_correr( $p5 );
$u = fb_upd( $w );
t( 'caso 5: no crea ficha', ! fb_ins( $w ) );
t( 'caso 5: activa la 304', $u && 304 === $u[0][3]['id'] );
t( 'caso 5: vía token', 'token' === ( fb_log( $w, 'identidad_resuelta' )[0]['via'] ?? '' ) );
$p5b = fb_pend( array( 'external_reference' => 'sub_f304_abcdef12', 'celular' => '', 'email' => 'otra.cuenta@hotmail.com' ) );
$w   = fb_prep( 'on', $p5b, array(), array(), fb_ficha() );
fb_correr( $p5b );
t( 'caso 5 (referencia sub_f, sin ficha_id): activa la 304', ! fb_ins( $w ) && ( fb_upd( $w )[0][3]['id'] ?? 0 ) === 304 );
$w = fb_prep( 'on', $p5, array( fb_ficha( array( 'id' => 500 ) ) ), array(), fb_ficha() );
fb_correr( $p5 );
t( 'el token gana aunque el email apunte a otra ficha', ( fb_upd( $w )[0][3]['id'] ?? 0 ) === 304 && ! fb_ins( $w ) );

seccion( 'PR2 on — caso 3: persona nueva paga por /planes-club/ → una ficha nueva' );
$p3 = fb_pend( array( 'email' => 'nueva@gmail.com', 'external_reference' => 'sub_1177770000_abcdef12', 'celular' => '1177770000' ) );
$w  = fb_prep( 'on', $p3 );
fb_correr( $p3 );
$i = fb_ins( $w );
t( 'caso 3: crea UNA ficha', 1 === count( $i ) );
t( 'caso 3: activa, con plan y preapproval', $i && 'activo' === $i[0][2]['estado'] && 'pre_abc' === $i[0][2]['preapproval_id'] && 'basico' === $i[0][2]['plan'] );
t( 'caso 3: sin marca de duplicado', ! in_array( 'identidad_posible_duplicado', $w->log_mp_tipos(), true ) );

seccion( 'PR2 on: por celular sigue funcionando (mismo celular, sin email que coincida)' );
$pc = fb_pend( array( 'celular' => FB_CEL_TRIAL, 'external_reference' => 'sub_' . FB_CEL_TRIAL . '_abcdef12', 'email' => 'x@y.com' ) );
$w  = fb_prep( 'on', $pc, array(), array( fb_ficha() ) );
fb_correr( $pc );
t( 'celular: convierte la 304', ! fb_ins( $w ) && ( fb_upd( $w )[0][3]['id'] ?? 0 ) === 304 );

seccion( 'PR2 on: dudoso → crea, marca posible_duplicado, alerta, nunca fusiona' );
$w = fb_prep( 'on', $p, array( fb_ficha(), fb_ficha( array( 'id' => 410 ) ) ) );
fb_correr( $p );
$d = $w->escrituras( 'posibles_duplicados' );
t( '2 fichas con el mismo email: crea una nueva', 1 === count( fb_ins( $w ) ) && ! fb_upd( $w ) );
t( 'anota posible duplicado con las candidatas', 1 === count( $d ) && '304,410' === $d[0][2]['candidatas'] && 999 === $d[0][2]['ficha_id'] );
$m = $GLOBALS['cn_mails'];
$al = array_values( array_filter( $m, function ( $x ) { return 'hola@naturalesart.com' === $x['to']; } ) );
t( 'alerta a hola@ sin datos personales', 1 === count( $al ) && false === stripos( $al[0]['asunto'] . $al[0]['cuerpo'], 'gmail' ) && false === stripos( $al[0]['cuerpo'], 'Trescientos' ) );
$pd = fb_pend( array( 'celular' => FB_CEL_TRIAL, 'external_reference' => 'sub_' . FB_CEL_TRIAL . '_abcdef12' ) );
$w  = fb_prep( 'on', $pd, array( fb_ficha( array( 'id' => 410 ) ) ), array( fb_ficha() ) );
fb_correr( $pd );
t( 'email → 410 y celular → 304: dudoso, crea y marca', 1 === count( fb_ins( $w ) ) && 1 === count( $w->escrituras( 'posibles_duplicados' ) ) );
$w = fb_prep( 'on', $pd, array( fb_ficha() ), array( fb_ficha() ) );
fb_correr( $pd );
t( 'email y celular → la misma ficha: no es dudoso', ! fb_ins( $w ) && ! $w->escrituras( 'posibles_duplicados' ) );

seccion( 'PR2 on: ficha encontrada con OTRA suscripción → conflicto de siempre (no pisa nada)' );
$w = fb_prep( 'on', $p, array( fb_ficha( array( 'preapproval_id' => 'pre_viejo' ) ) ) );
fb_correr( $p );
t( 'conflicto: ni crea ni actualiza la ficha', ! fb_ins( $w ) && ! fb_upd( $w ) );
t( 'conflicto: sub_conflicto', in_array( 'sub_conflicto', $w->log_mp_tipos(), true ) );
