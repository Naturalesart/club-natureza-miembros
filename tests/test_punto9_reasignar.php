<?php
// Punto 9: "Reasignar vínculo" (CN_Vinculo_Lote, acciones reasignar_prev / reasignar_ejec).
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
require_once __DIR__ . '/test_punto6_lote.php';
function ra_id() {
	return lt_id( 77 );
}
function ra_socia( $id, $cel, $extra = array() ) {
	return lt_fila( $id, $cel, array_merge( array( 'estado' => 'cancelada' ), $extra ) );
}
function ra_origen() {
	return ra_socia( 10, '1155551111', array( 'estado' => 'activo', 'preapproval_id' => ra_id(), 'plan' => 'basico', 'acceso_hasta' => '2026-11-10 15:00:00' ) );
}
function ra_run( $accion, $filas, $titular, $mp = null, $extra = array() ) {
	$w = lt_preparar( $filas, array( ra_id() => $mp ? $mp : lt_mp( ra_id() ) ) );
	$w->on( '/WHERE preapproval_id = /', $titular ? array( $titular ) : array() );
	$_POST = array_merge( array( 'cn_vl_accion' => $accion, 'cn_vl_nonce' => 'x', 'cn_vl_ra_sub' => ra_id(), 'cn_vl_ra_dest' => '20', 'cn_vl_ra_frase' => 'REASIGNAR' ), $extra );
	$r = CN_Vinculo_Lote::procesar();
	return array( $r, $w );
}

seccion( 'reasignar: previsualizar (solo lectura)' );
$o = ra_origen();
$d = ra_socia( 20, '1155552222' );
list( $r, $w ) = ra_run( 'reasignar_prev', array( $o, $d ), $o );
t( 'previsualizar: ok, mover, no escribe nada', 'reasignar_prev' === $r['paso'] && $r['ev']['ok'] && 'mover' === $r['ev']['accion'] && ! $w->log, json_encode( $r['ev']['texto'] ) );
ob_start();
CN_Vinculo_Lote::render( $r );
$html = ob_get_clean();
t( 'la pantalla muestra ambas fichas, la frase y no muestra celular completo ni emails', false !== strpos( $html, '#10 ' ) && false !== strpos( $html, '#20 ' ) && false !== strpos( $html, 'REASIGNAR' ) && false === strpos( $html, '1155551111' ) && false === strpos( $html, '1155552222' ) && false === strpos( $html, '@example.com' ) );

seccion( 'reasignar: ejecutar' );
list( $r, $w ) = ra_run( 'reasignar_ejec', array( $o, $d ), $o );
$esc = $w->escrituras( 'cn_miembros' );
t( 'mueve bien: ok', $r['ok'], $r['mensaje'] );
t( 'dos updates: origen liberada y cancelada', 2 === count( $esc ) && array( 'id' => 10 ) === $esc[0][3] && null === $esc[0][2]['preapproval_id'] && null === $esc[0][2]['plan'] && null === $esc[0][2]['acceso_hasta'] && 'cancelada' === $esc[0][2]['estado'] );
t( 'destino: preapproval, plan por monto, acceso = next_payment_date (UTC), activo', array( 'id' => 20 ) === $esc[1][3] && ra_id() === $esc[1][2]['preapproval_id'] && 'basico' === $esc[1][2]['plan'] && '2026-11-10 15:00:00' === $esc[1][2]['acceso_hasta'] && 'activo' === $esc[1][2]['estado'] );
$tipos = $w->log_mp_tipos();
t( 'dos backups vinculo_backup antes de escribir y un vinculo_reasignado', array( 'vinculo_backup', 'vinculo_backup', 'vinculo_reasignado' ) === $tipos && 'insert' === $w->log[1][0], json_encode( $tipos ) );
$q = $w->transaccion();
t( 'transacción: START, FOR UPDATE por id en orden, COMMIT', 'START TRANSACTION' === $q[0] && 'COMMIT' === end( $q ) && 0 < count( array_filter( $w->consultas, function ( $s ) {
	return false !== strpos( $s, 'FOR UPDATE' );
} ) ) );
$orden = array_values( array_filter( $w->consultas, function ( $s ) {
	return false !== strpos( $s, 'WHERE id = ' ) && false !== strpos( $s, 'FOR UPDATE' );
} ) );
t( 'locks en orden ascendente de id', 2 === count( $orden ) && false !== strpos( $orden[0], 'id = 10' ) && false !== strpos( $orden[1], 'id = 20' ) );
$logr = null;
foreach ( $w->log as $l ) {
	if ( 'insert' === $l[0] && 'vinculo_reasignado' === $l[2]['tipo'] ) {
		$logr = json_decode( $l[2]['payload'], true );
	}
}
t( 'log vinculo_reasignado con origen, destino y sub, sin datos personales', $logr && 10 === $logr['origen'] && 20 === $logr['destino'] && ra_id() === $logr['preapproval_id'] && false === strpos( json_encode( $logr ), '@' ) && false === strpos( json_encode( $logr ), '1155' ) );
t( 'no manda mail ni evento Meta', ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] );

$o_post = ra_socia( 10, '1155551111', array( 'estado' => 'cancelada' ) );
$d_post = ra_socia( 20, '1155552222', array( 'estado' => 'activo', 'preapproval_id' => ra_id(), 'plan' => 'basico', 'acceso_hasta' => '2026-11-10 15:00:00' ) );
list( $r, $w ) = ra_run( 'reasignar_ejec', array( $o_post, $d_post ), $d_post );
t( 'doble ejecución: no duplica (ok, cero escrituras, sin backup ni log)', $r['ok'] && ! $w->escrituras( 'cn_miembros' ) && ! $w->log_mp_tipos(), json_encode( $r ) );

seccion( 'reasignar: rechazos (sin escribir)' );
$otra = ra_socia( 20, '1155552222', array( 'preapproval_id' => 'otro00000000000000000000000000aa' ) );
list( $r, $w ) = ra_run( 'reasignar_ejec', array( $o, $otra ), $o );
t( 'destino con otra sub: rechaza', ! $r['ok'] && false !== strpos( $r['mensaje'], 'OTRA' ) && ! $w->log, json_encode( $r ) );
$sin = ra_socia( 20, '1155552222', array( 'celular_texto_plano' => null ) );
list( $r, $w ) = ra_run( 'reasignar_ejec', array( $o, $sin ), $o );
t( 'destino sin celular válido: rechaza', ! $r['ok'] && false !== strpos( $r['mensaje'], 'celular' ) && ! $w->log, json_encode( $r ) );
list( $r, $w ) = ra_run( 'reasignar_ejec', array( $o, $d ), $o, lt_mp( ra_id(), 'paused' ) );
t( 'sub no authorized en MP: rechaza', ! $r['ok'] && ! $w->log );
list( $r, $w ) = ra_run( 'reasignar_ejec', array( $o, $d ), null );
t( 'sub sin ficha origen: rechaza', ! $r['ok'] && ! $w->log );
list( $r, $w ) = ra_run( 'reasignar_ejec', array( $o, $d ), $o, null, array( 'cn_vl_ra_dest' => '999' ) );
t( 'destino inexistente: rechaza', ! $r['ok'] && ! $w->log );
list( $r, $w ) = ra_run( 'reasignar_ejec', array( $o, $d ), $o, null, array( 'cn_vl_ra_frase' => 'si' ) );
t( 'sin la frase: rechaza antes de tocar MP o la base', ! $r['ok'] && ! $w->log );
list( $r, $w ) = ra_run( 'reasignar_ejec', array( $o, $d ), $o, null, array( 'cn_vl_ra_sub' => 'xyz' ) );
t( 'ID inválido: rechaza', ! $r['ok'] && ! $w->log );
