<?php
// Punto 6: "Vincular en lote" (CN_Vinculo_Lote).
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
function lt_id( $n ) {
	return str_pad( dechex( $n ), 32, 'a', STR_PAD_LEFT );
}
function lt_mp( $id, $status = 'authorized', $monto = 15500 ) {
	return array(
		'id' => $id, 'status' => $status, 'payer_email' => 'p@example.com',
		'next_payment_date' => '2026-11-10T12:00:00.000-03:00', 'auto_recurring' => array( 'transaction_amount' => $monto ),
	);
}
function lt_fila( $id, $cel, $extra = array() ) {
	return cn_fila( array_merge( array(
		'id' => $id, 'nombre_apellido' => 'Socia Numero ' . $id, 'estado' => 'cancelada', 'preapproval_id' => null, 'plan' => null,
		'acceso_hasta' => null, 'email' => 's' . $id . '@example.com', 'fecha_fin_trial' => null, 'fecha_modificacion' => null,
		'baja_en' => null, 'celular_hint' => substr( $cel, -4 ), 'celular_hash' => CN_Helpers::hash_celular( $cel ), 'celular_texto_plano' => $cel,
	), $extra ) );
}
function lt_preparar( $filas, $subs = null ) {
	cn_test_reset();
	global $wpdb;
	$_POST = array();
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	foreach ( (array) $subs as $id => $resp ) {
		cn_http_on( 'GET', '#/preapproval/' . $id . '#', $resp );
	}
	$wpdb->on( '/celular_hint = /', function ( $sql ) use ( $filas ) {
		return array_values( array_filter( $filas, function ( $f ) use ( $sql ) {
			return false !== strpos( $sql, "'" . $f->celular_hint . "'" );
		} ) );
	} );
	$wpdb->on( '/celular_texto_plano LIKE/', function ( $sql ) use ( $filas ) {
		return array_values( array_filter( $filas, function ( $f ) use ( $sql ) {
			return false !== strpos( $sql, "'" . substr( (string) $f->celular_texto_plano, 0, 8 ) . "%'" );
		} ) );
	} );
	$wpdb->on( '/FROM wp_cn_miembros$/', $filas );
	$wpdb->on( '/WHERE id <> (\d+)/', function ( $sql ) use ( $filas ) {
		preg_match( '/WHERE id <> (\d+)/', $sql, $m );
		return array_values( array_filter( $filas, function ( $f ) use ( $m ) {
			return (int) $f->id !== (int) $m[1];
		} ) );
	} );
	foreach ( $filas as $f ) {
		$wpdb->on( '/WHERE id = ' . $f->id . '\b/', array( $f ) );
	}
	$wpdb->on( '/WHERE preapproval_id = /', array() );
	return $wpdb;
}
function lt_post( $texto, $accion, $extra = array() ) {
	$_POST = array_merge( array( 'cn_vl_accion' => $accion, 'cn_vl_pares' => $texto, 'cn_vl_nonce' => 'x' ), $extra );
}
function lt_esc() {
	return $GLOBALS['wpdb']->escrituras( 'cn_miembros' );
}

seccion( 'lote: parsear' );
$ok = CN_Vinculo_Lote::parsear( "# comentario\n" . lt_id( 1 ) . ' 10' . "\n\n" . strtoupper( lt_id( 2 ) ) . ",11\n" . lt_id( 3 ) . "\t12;" );
t( 'formatos válidos, comentario y vacías ignorados', 3 === count( $ok['pares'] ) && lt_id( 2 ) === $ok['pares'][1]['id'] && 11 === $ok['pares'][1]['socia'], json_encode( $ok ) );
t( 'línea con 3 datos o ID corto: error, no par', 3 === count( CN_Vinculo_Lote::parsear( "abc 1\n" . lt_id( 1 ) . " x\n" . lt_id( 1 ) . ' 1 2' )['errores'] ) );
$dup = CN_Vinculo_Lote::parsear( lt_id( 1 ) . " 10\n" . lt_id( 1 ) . " 11\n" . lt_id( 2 ) . ' 10' );
t( 'preapproval o socia repetidos: se rechazan', 1 === count( $dup['pares'] ) && 2 === count( $dup['errores'] ) );
$muchos = '';
for ( $i = 1; $i <= 25; $i++ ) {
	$muchos .= lt_id( $i ) . ' ' . ( 100 + $i ) . "\n";
}
$m = CN_Vinculo_Lote::parsear( $muchos );
t( 'tope de 20 pares', 20 === count( $m['pares'] ) && 1 === count( $m['errores'] ) );

seccion( 'lote: previsualizar (solo lectura)' );
$a = lt_fila( 10, '1155552165' );
$b = lt_fila( 11, '1155553333', array( 'preapproval_id' => 'otro00000000000000000000000000aa' ) );
$g = lt_fila( 12, '1155554444' );
$g2 = lt_fila( 13, '1155554444' ); // mismo celular que #12 -> advertencia
$h = lt_fila( 14, '1155556666' );
$w = lt_preparar( array( $a, $b, $g, $g2, $h ), array(
	lt_id( 1 ) => lt_mp( lt_id( 1 ) ), lt_id( 2 ) => lt_mp( lt_id( 2 ) ), lt_id( 3 ) => lt_mp( lt_id( 3 ) ),
	lt_id( 4 ) => lt_mp( lt_id( 4 ), 'paused' ), lt_id( 5 ) => 404,
) );
lt_post( lt_id( 1 ) . " 10\n" . lt_id( 2 ) . " 11\n" . lt_id( 3 ) . " 12\n" . lt_id( 4 ) . " 14\n" . lt_id( 5 ) . ' 99', 'previsualizar' );
$r = CN_Vinculo_Lote::procesar();
t( 'previsualizar no escribe nada en miembros ni backup', ! lt_esc() && ! in_array( 'vinculo_backup', $w->log_mp_tipos(), true ) && 'previsualizado' === $r['paso'] );
$f = $r['filas'];
t( 'fila 1: ok, se activa', $f[0]['ok'] && false !== strpos( $f[0]['texto'], 'Se activa' ) && ! $f[0]['avisos'] );
t( 'fila 2: conflicto (ya tiene otra suscripción)', ! $f[1]['ok'] && false !== strpos( $f[1]['texto'], 'CONFLICTO' ) );
t( 'fila 3: ok pero con advertencia de ficha con el mismo celular', $f[2]['ok'] && 1 === count( $f[2]['avisos'] ) && false !== strpos( $f[2]['avisos'][0], '#13' ) );
t( 'fila 4: pausada en MP, no confirmable', ! $f[3]['ok'] && false !== strpos( $f[3]['texto'], 'no está autorizada' ) );
t( 'fila 5: MP 404, mensaje claro', ! $f[4]['ok'] && false !== strpos( $f[4]['texto'], '404' ) );
ob_start();
CN_Vinculo_Lote::render( $r );
$html = ob_get_clean();
t( 'la pantalla muestra la frase de confirmación y no muestra emails', false !== strpos( $html, 'VINCULAR' ) && false === strpos( $html, '@example.com' ) );

seccion( 'lote: confirmar' );
$w = lt_preparar( array( $a ), array( lt_id( 1 ) => lt_mp( lt_id( 1 ) ) ) );
lt_post( lt_id( 1 ) . ' 10', 'confirmar', array( 'cn_vl_frase' => 'vincular?' ) );
$r = CN_Vinculo_Lote::procesar();
t( 'sin la frase correcta: no escribe', ! lt_esc() && 'error' === $r['paso'] );
$w = lt_preparar( array( $a ), array( lt_id( 1 ) => lt_mp( lt_id( 1 ) ) ) );
lt_post( lt_id( 1 ) . ' 10', 'confirmar', array( 'cn_vl_frase' => 'VINCULAR' ) );
$r   = CN_Vinculo_Lote::procesar();
$esc = lt_esc();
t( 'vincula: un update sobre la socia 10 con preapproval, plan y acceso', 1 === count( $esc ) && 'update' === $esc[0][0] && array( 'id' => 10 ) === $esc[0][3] && lt_id( 1 ) === $esc[0][2]['preapproval_id'] && 'basico' === $esc[0][2]['plan'] && '2026-11-10 15:00:00' === $esc[0][2]['acceso_hasta'], json_encode( $r['filas'] ) );
t( 'deja backup antes y registro vinculo_lote', in_array( 'vinculo_backup', $w->log_mp_tipos(), true ) && in_array( 'vinculo_lote', $w->log_mp_tipos(), true ) && ! in_array( 'vinculo_manual', $w->log_mp_tipos(), true ) );
t( 'nunca manda mail ni evento Meta', ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] );

$w = lt_preparar( array( $g, $g2 ), array( lt_id( 3 ) => lt_mp( lt_id( 3 ) ) ) );
lt_post( lt_id( 3 ) . ' 12', 'confirmar', array( 'cn_vl_frase' => 'VINCULAR' ) );
$r = CN_Vinculo_Lote::procesar();
t( 'con advertencia y sin tildar: no escribe', ! lt_esc() && false !== strpos( $r['filas'][0]['texto'], 'advertencias' ) );
$w = lt_preparar( array( $g, $g2 ), array( lt_id( 3 ) => lt_mp( lt_id( 3 ) ) ) );
lt_post( lt_id( 3 ) . ' 12', 'confirmar', array( 'cn_vl_frase' => 'VINCULAR', 'cn_vl_avisos' => '1' ) );
$r = CN_Vinculo_Lote::procesar();
t( 'con la advertencia aceptada: vincula', 1 === count( lt_esc() ) );

$filas = array();
$subs  = array();
$txt   = '';
for ( $i = 1; $i <= 7; $i++ ) {
	$filas[]           = lt_fila( 20 + $i, '11555500' . str_pad( (string) ( 10 * $i ), 2, '0', STR_PAD_LEFT ) );
	$subs[ lt_id( $i ) ] = lt_mp( lt_id( $i ) );
	$txt              .= lt_id( $i ) . ' ' . ( 20 + $i ) . "\n";
}
$w = lt_preparar( $filas, $subs );
lt_post( $txt, 'confirmar', array( 'cn_vl_frase' => 'VINCULAR' ) );
$r = CN_Vinculo_Lote::procesar();
t( 'máximo 5 escrituras por clic; el resto queda pendiente', 5 === count( lt_esc() ) && 2 === count( array_filter( $r['filas'], function ( $x ) {
	return false !== strpos( $x['texto'], 'Pendiente' );
} ) ), count( lt_esc() ) . ' escrituras' );

seccion( 'lote: idempotente' );
$ya = lt_fila( 10, '1155552165', array( 'estado' => 'activo', 'preapproval_id' => lt_id( 1 ), 'plan' => 'basico', 'acceso_hasta' => '2026-11-10 15:00:00' ) );
$w  = lt_preparar( array( $ya ), array( lt_id( 1 ) => lt_mp( lt_id( 1 ) ) ) );
$w->on( '/WHERE preapproval_id = /', array( $ya ) );
lt_post( lt_id( 1 ) . ' 10', 'confirmar', array( 'cn_vl_frase' => 'VINCULAR' ) );
$r = CN_Vinculo_Lote::procesar();
t( 'repetir el lote: completada, cero escrituras', ! lt_esc() && $r['filas'][0]['ok'] && false !== strpos( $r['filas'][0]['texto'], 'COMPLETADA' ), json_encode( $r['filas'] ) );
