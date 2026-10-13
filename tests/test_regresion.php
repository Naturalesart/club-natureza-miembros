<?php
// Regresión: los caminos del webhook que existían antes de la Fase 5 siguen igual.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
function reg_prep( $ciclo = null ) {
	cn_test_reset();
	global $wpdb;
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	if ( $ciclo ) {
		$wpdb->on( "/SELECT id, estado, plan, acceso_hasta, baja_en FROM wp_cn_miembros WHERE preapproval_id = 'PRE_REG00000000000001'/", array( $ciclo ) );
	}
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	return $wpdb;
}
function reg_ciclo( $extra = array() ) {
	return cn_fila( array_merge( array( 'id' => 7, 'estado' => 'activo', 'plan' => 'basico', 'acceso_hasta' => '2026-10-20 12:00:00', 'baja_en' => null ), $extra ) );
}
$PRE = 'PRE_REG00000000000001';

seccion( 'gestionar_ciclo_sub como lo llama el webhook (sin datos de MP)' );
$w = reg_prep( reg_ciclo() );
cn_http_on( 'GET', '#/preapproval/' . $PRE . '#', array( 'id' => $PRE, 'status' => 'authorized', 'next_payment_date' => '2026-12-01T12:00:00.000-03:00' ) );
$r = CN_Webhook::gestionar_ciclo_sub( 'authorized', $PRE );
$u = $w->escrituras( 'cn_miembros' );
t( 'authorized: consulta MP UNA vez y extiende acceso_hasta; devuelve false (el camino de siempre sigue)', false === $r && 1 === count( cn_http_calls( '#/preapproval/#' ) ) && 1 === count( $u ) && '2026-12-01 15:00:00' === $u[0][2]['acceso_hasta'] );
$w = reg_prep( reg_ciclo() );
$r = CN_Webhook::gestionar_ciclo_sub( 'cancelled', $PRE );
$u = $w->escrituras( 'cn_miembros' );
t( 'cancelled: programa baja_en, no toca estado ni acceso_hasta; devuelve true; no consulta a MP', true === $r && 1 === count( $u ) && isset( $u[0][2]['baja_en'] ) && ! isset( $u[0][2]['estado'] ) && ! $GLOBALS['cn_http_log'] );
$w = reg_prep( reg_ciclo( array( 'baja_en' => '2026-10-10 00:00:00' ) ) );
$r = CN_Webhook::gestionar_ciclo_sub( 'cancelled', $PRE );
t( 'cancelled con baja ya programada: no pisa la fecha', true === $r && ! $w->escrituras( 'cn_miembros' ) );
$w = reg_prep( reg_ciclo( array( 'plan' => null ) ) );
$r = CN_Webhook::gestionar_ciclo_sub( 'cancelled', $PRE );
t( 'fila sin plan (trial, manual, de siempre): no es de esta función -> false, sin escrituras', false === $r && ! $w->escrituras( 'cn_miembros' ) );
t( 'estado desconocido (pending): false', false === CN_Webhook::gestionar_ciclo_sub( 'pending', $PRE ) );
$w = reg_prep( reg_ciclo() );
cn_http_on( 'GET', '#/preapproval/#', 500 );
$r = CN_Webhook::gestionar_ciclo_sub( 'authorized', $PRE );
t( 'authorized con MP caído: no cambia nada', false === $r && ! $w->escrituras( 'cn_miembros' ) );
t( 'acceso_desde_data: UTC desde next_payment_date; null si falta o es inválida', '2026-11-10 15:00:00' === CN_Webhook::acceso_desde_data( array( 'next_payment_date' => '2026-11-10T12:00:00.000-03:00' ) ) && null === CN_Webhook::acceso_desde_data( array() ) && null === CN_Webhook::acceso_desde_data( null ) && null === CN_Webhook::acceso_desde_data( array( 'next_payment_date' => 'no es fecha' ) ) );

seccion( 'aplicar_estado (camino de siempre del webhook)' );
$w = reg_prep();
cn_llamar( 'CN_Webhook', 'aplicar_estado', 'cancelled', '', $PRE, 'ana@example.com' );
t( 'modo seguro (default): cancelled NO baja a nadie, queda log downgrade_omitido', ! $w->escrituras( 'cn_miembros' ) && in_array( 'downgrade_omitido', $w->log_mp_tipos(), true ) );
$w = reg_prep();
$w->on( "/SELECT id FROM wp_cn_miembros WHERE email = 'ana@example.com'/", array( cn_fila( array( 'id' => 5 ) ) ) );
cn_llamar( 'CN_Webhook', 'aplicar_estado', 'authorized', '', $PRE, 'ana@example.com' );
$u = $w->escrituras( 'cn_miembros' );
t( 'authorized + email de UNA socia: la activa y le guarda el preapproval_id (link fijo)', 1 === count( $u ) && 'activo' === $u[0][2]['estado'] && $PRE === $u[0][2]['preapproval_id'] && array( 'id' => 5 ) === $u[0][3] );
$w = reg_prep();
cn_llamar( 'CN_Webhook', 'aplicar_estado', 'authorized', '', $PRE, 'nadie@example.com' );
t( 'authorized sin match: no escribe y avisa preapproval_sin_match', ! $w->escrituras( 'cn_miembros' ) && 1 === count( array_filter( cn_http_calls( '#club-trial-alerta#' ), function ( $c ) {
	return false !== strpos( (string) $c['body'], 'preapproval_sin_match' );
} ) ) );
$w = reg_prep();
$w->on( "/SELECT id FROM wp_cn_miembros WHERE email = 'dup@example.com'/", array( cn_fila( array( 'id' => 1 ) ), cn_fila( array( 'id' => 2 ) ) ) );
cn_llamar( 'CN_Webhook', 'aplicar_estado', 'authorized', '', $PRE, 'dup@example.com' );
t( 'email de 2+ socias (ambiguo): no adivina, no escribe', ! $w->escrituras( 'cn_miembros' ) );

seccion( 'el webhook sigue registrando rutas y funciones públicas esperadas' );
foreach ( array( 'registrar_rutas', 'manejar', 'manejar_trial', 'revocar_bajas_sub_vencidas', 'avisar_dia5_trial', 'enviar_mail_fin_trial', 'vincular_suscripcion', 'vincular_suscripcion_verificar', 'registrar_log_mp', 'avisar_error_n8n' ) as $m ) {
	t( "CN_Webhook::{$m} existe y es pública", method_exists( 'CN_Webhook', $m ) && ( new ReflectionMethod( 'CN_Webhook', $m ) )->isPublic() );
}
t( 'login de socias (CN_Auth) sin cambios de API', method_exists( 'CN_Auth', 'get_miembro_actual' ) && method_exists( 'CN_Auth', 'revocar_trials_vencidos' ) );
