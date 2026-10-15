<?php
// Punto 8: "Completar celular" (CN_Vinculo_Lote, acción completar_celular).
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
function cc_socia( $id, $cel_real, $texto, $extra = array() ) {
	return cn_fila( array_merge( array(
		'id' => $id, 'nombre_apellido' => 'Socia ' . $id, 'estado' => 'activo', 'preapproval_id' => null, 'plan' => null,
		'acceso_hasta' => null, 'email' => 's' . $id . '@example.com', 'fecha_fin_trial' => null, 'fecha_modificacion' => null,
		'baja_en' => null, 'celular_hint' => substr( $cel_real, -4 ), 'celular_hash' => CN_Helpers::hash_celular( $cel_real ), 'celular_texto_plano' => $texto,
	), $extra ) );
}
function cc_run( $fila, $id, $celular ) {
	cn_test_reset();
	global $wpdb;
	$wpdb->on( '/WHERE id = ' . $fila->id . '\b/', array( $fila ) );
	$_POST = array( 'cn_vl_accion' => 'completar_celular', 'cn_vl_nonce' => 'x', 'cn_vl_cc_id' => (string) $id, 'cn_vl_cc_celular' => $celular );
	return array( CN_Vinculo_Lote::procesar(), $wpdb );
}

seccion( 'completar celular' );
$f = cc_socia( 267, '1121672167', null );
list( $r, $w ) = cc_run( $f, 267, '+54 9 11 2167-2167' );
$esc = $w->escrituras( 'cn_miembros' );
t( 'celular correcto: guarda solo celular_texto_plano normalizado', $r['ok'] && 1 === count( $esc ) && array( 'celular_texto_plano' => '1121672167' ) === $esc[0][2] && array( 'id' => 267 ) === $esc[0][3], json_encode( $r ) );
t( 'deja backup vinculo_backup antes de escribir', array( 'vinculo_backup' ) === $w->log_mp_tipos() && 'insert' === $w->log[0][0] && false !== strpos( $w->log[0][1], 'mp_log' ) );
t( 'el mensaje no repite el celular completo', false === strpos( $r['mensaje'], '1121672167' ) );

list( $r, $w ) = cc_run( $f, 267, '1133332167' ); // hint ok (2167), hash mal
t( 'hint ok pero hash mal: no guarda ni hace backup', ! $r['ok'] && ! $w->escrituras( 'cn_miembros' ) && ! $w->log_mp_tipos() );

$g = cc_socia( 124, '1163906390', null, array( 'celular_hash' => CN_Helpers::hash_celular( '1163906391' ) ) );
list( $r, $w ) = cc_run( $g, 124, '1163906391' ); // hash ok, hint mal (6390 vs 6391)
t( 'hash ok pero hint mal: no guarda', ! $r['ok'] && ! $w->escrituras( 'cn_miembros' ) && ! $w->log_mp_tipos() && false !== strpos( $r['mensaje'], 'últimos 4' ) );

list( $r, $w ) = cc_run( $f, 267, '123' );
t( 'celular inválido: no guarda', ! $r['ok'] && ! $w->escrituras( 'cn_miembros' ) );

$ya = cc_socia( 267, '1121672167', '1121672167' );
list( $r, $w ) = cc_run( $ya, 267, '1121672167' );
t( 'ficha que ya tiene celular válido: no escribe', ! $r['ok'] && ! $w->escrituras( 'cn_miembros' ) );

$mal = cc_socia( 267, '1121672167', '12' ); // texto inválido: sí se puede completar
list( $r, $w ) = cc_run( $mal, 267, '1121672167' );
t( 'texto inválido existente: se completa', $r['ok'] && 1 === count( $w->escrituras( 'cn_miembros' ) ) );

list( $r, $w ) = cc_run( $f, 999, '1121672167' );
t( 'socia inexistente: error sin escribir', ! $r['ok'] && ! $w->escrituras( 'cn_miembros' ) );

$_POST = array( 'cn_vl_accion' => 'completar_celular', 'cn_vl_nonce' => 'x', 'cn_vl_cc_id' => '267', 'cn_vl_cc_celular' => '1121672167' );
ob_start();
CN_Vinculo_Lote::render( array( 'paso' => 'celular', 'ok' => true, 'mensaje' => 'Listo', 'texto' => '' ) );
$html = ob_get_clean();
t( 'la pantalla tiene el formulario Completar celular', false !== strpos( $html, 'value="completar_celular"' ) );
