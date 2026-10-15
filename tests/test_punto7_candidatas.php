<?php
// Punto 7: "Cargar candidatas seguras" (CN_Vinculo_Lote::candidatas_seguras / procesar 'candidatas'). Solo lectura.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
function cs_id( $n ) {
	return str_pad( dechex( $n ), 32, 'b', STR_PAD_LEFT );
}
function cs_item( $n, $email = null, $monto = 15500 ) {
	return array(
		'id' => cs_id( $n ), 'status' => 'authorized', 'payer_email' => null === $email ? 'u' . $n . '@example.com' : $email,
		'next_payment_date' => '2026-11-10T12:00:00.000-03:00', 'auto_recurring' => array( 'transaction_amount' => $monto ),
	);
}
// Fichas bien distintas entre sí (nombre y celular) salvo que el test pida lo contrario.
function cs_socia( $id, $nombre, $cel, $extra = array() ) {
	return cn_fila( array_merge( array(
		'id' => $id, 'nombre_apellido' => $nombre, 'estado' => 'cancelada', 'preapproval_id' => null, 'plan' => null,
		'acceso_hasta' => null, 'email' => 'u' . $id . '@example.com', 'fecha_fin_trial' => null, 'fecha_modificacion' => null,
		'baja_en' => null, 'celular_hint' => substr( $cel, -4 ), 'celular_hash' => 'h' . $id, 'celular_texto_plano' => $cel,
	), $extra ) );
}
function cs_base() {
	return array(
		cs_socia( 1, 'Maria Gomez', '1155550001' ),
		cs_socia( 2, 'Ana Rodriguez', '1166660002' ),
		cs_socia( 3, 'Lucia Fernandez', '1177770003' ),
	);
}
function cs_motivo( $r, $n ) {
	foreach ( $r['excluidas'] as $x ) {
		if ( $x['id'] === cs_id( $n ) ) {
			return $x['motivo'];
		}
	}
	return null;
}

seccion( 'candidatas seguras: lógica' );
cn_test_reset();
$r = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1 ), cs_item( 2, null, 32000 ) ), cs_base() );
t( 'candidata válida entra (básico y full)', array( array( 'id' => cs_id( 1 ), 'socia' => 1 ), array( 'id' => cs_id( 2 ), 'socia' => 2 ) ) === $r['pares'] && ! $r['excluidas'], json_encode( $r['pares'] ) );

$r = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1, 'u1@example.com', 15000 ) ), cs_base() );
t( 'monto $15.000 también', 1 === count( $r['pares'] ) );

$r = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1, null, 99999 ) ), cs_base() );
t( 'excluye: monto sin plan conocido', ! $r['pares'] && 'monto sin plan conocido' === cs_motivo( $r, 1 ) );

$ya = cs_base();
$ya[0]->preapproval_id = cs_id( 1 );
$r  = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1 ) ), $ya );
t( 'no propone ni lista suscripciones ya vinculadas', ! $r['pares'] && ! $r['excluidas'] );

$ya = cs_base();
$ya[0]->preapproval_id = cs_id( 99 );
$r  = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1 ) ), $ya );
t( 'excluye: la socia ya tiene otro preapproval', ! $r['pares'] && 'la socia ya tiene otra suscripción' === cs_motivo( $r, 1 ) );

$r = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1, '' ) ), cs_base() );
t( 'excluye: sin email', ! $r['pares'] && 'sin email' === cs_motivo( $r, 1 ) );

$r = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1, 'nadie@example.com' ) ), cs_base() );
t( 'excluye: sin match', ! $r['pares'] && 'sin match' === cs_motivo( $r, 1 ) );

$dos = cs_base();
$dos[1]->email = 'u1@example.com';
$r   = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1 ) ), $dos );
t( 'excluye: más de una socia con ese email', ! $r['pares'] && 'más de una socia' === cs_motivo( $r, 1 ) );

$sc = cs_base();
$sc[0]->celular_texto_plano = '123';
$r  = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1 ) ), $sc );
t( 'excluye: sin celular válido', ! $r['pares'] && 'sin celular válido' === cs_motivo( $r, 1 ) );
$sc[0]->celular_texto_plano = null;
$r = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1 ) ), $sc );
t( 'excluye: celular vacío', ! $r['pares'] && 'sin celular válido' === cs_motivo( $r, 1 ) );

$g   = cs_base();
$g[] = cs_socia( 4, 'Otra Persona', '1188880004', array( 'email' => 'U1@Example.com ' ) ); // mismo email que #1 (otra ficha)
$r   = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1, 'u2@example.com' ) ), $g );
t( 'ficha con el mismo email que la socia #2 (email normalizado) excluye si es parecida: control sin parecido entra', 1 === count( $r['pares'] ) );

$g   = cs_base();
$g[] = cs_socia( 4, 'maría  gómez', '1188880004' ); // mismo nombre normalizado que #1
$r   = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1 ) ), $g );
t( 'excluye: otra ficha con el mismo nombre normalizado (#4)', ! $r['pares'] && 'ficha parecida #4' === cs_motivo( $r, 1 ), json_encode( $r['excluidas'] ) );

$g   = cs_base();
$g[] = cs_socia( 4, 'Gomez Maria', '1188880004' ); // mismas palabras, otro orden
$r   = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1 ) ), $g );
t( 'excluye: nombre con las palabras en otro orden', 'ficha parecida #4' === cs_motivo( $r, 1 ) );

$g   = cs_base();
$g[] = cs_socia( 4, 'Maria Gomes', '1188880004' ); // a 1 letra
$r   = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1 ) ), $g );
t( 'excluye: nombre a 1 letra de distancia', 'ficha parecida #4' === cs_motivo( $r, 1 ) );

$g   = cs_base();
$g[] = cs_socia( 4, 'Otra Persona', '1155550009' ); // celular a 1 dígito de #1 (1155550001)
$r   = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1 ) ), $g );
t( 'excluye: otra ficha con celular a 1 dígito', 'ficha parecida #4' === cs_motivo( $r, 1 ) );

$g   = cs_base();
$g[] = cs_socia( 4, 'Otra Persona', '1155550099' ); // 2 dígitos de diferencia: no alcanza
$r   = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1 ) ), $g );
t( 'celular a 2 dígitos NO excluye', 1 === count( $r['pares'] ) );

$g   = cs_base();
$g[] = cs_socia( 4, 'Maria Gomez', '1188880004', array( 'estado' => 'activo' ) );
$r   = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1 ) ), $g );
t( 'ficha parecida activa también excluye', 'ficha parecida #4' === cs_motivo( $r, 1 ) );

$r = CN_Vinculo_Lote::candidatas_seguras( array( cs_item( 1 ), cs_item( 2, 'u1@example.com' ) ), cs_base() );
t( 'dos suscripciones a la misma socia: no se propone ninguna', ! $r['pares'] && 2 === count( $r['excluidas'] ) );

seccion( 'candidatas seguras: tope de 5' );
$socias = array();
$items  = array();
$nombres = array( 'Abril Quiroga', 'Bernardo Salinas', 'Camila Ortega', 'Dolores Pereyra', 'Esteban Villalba', 'Florencia Benitez', 'Gaston Medina', 'Hilda Carrizo' );
foreach ( $nombres as $i => $nom ) {
	$n        = $i + 1;
	$socias[] = cs_socia( 100 + $n, $nom, '11' . str_pad( (string) ( $n * 1234567 ), 8, '3', STR_PAD_LEFT ), array( 'email' => 'm' . $n . '@example.com' ) );
	$items[]  = cs_item( $n, 'm' . $n . '@example.com' );
}
$r = CN_Vinculo_Lote::candidatas_seguras( $items, $socias );
t( 'nunca más de 5 pares y avisa cuántas quedan', 5 === count( $r['pares'] ) && 3 === $r['restantes'] && ! $r['excluidas'], json_encode( $r['pares'] ) . ' rest=' . $r['restantes'] );
t( 'los pares son parseables por el lote', 5 === count( CN_Vinculo_Lote::parsear( implode( "\n", array_map( function ( $p ) {
	return $p['id'] . ' ' . $p['socia'];
}, $r['pares'] ) ) )['pares'] ) );

seccion( 'candidatas seguras: botón (solo lectura)' );
cn_test_reset();
global $wpdb;
$_POST = array( 'cn_vl_accion' => 'candidatas', 'cn_vl_pares' => '', 'cn_vl_nonce' => 'x' );
update_option( 'cn_mp_access_token', 'APP_USR-test' );
$it = array( cs_item( 1, 'u1@example.com' ), cs_item( 2, 'u2@example.com' ), cs_item( 3, '' ) );
cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => $it, 'paging' => array( 'total' => 3 ) ) );
cn_http_on( 'GET', '#/v1/payments/search#', array( 'results' => array(), 'paging' => array( 'total' => 0 ) ) );
cn_http_on( 'GET', '#authorized_payments#', array( 'results' => array() ) );
$socias = cs_base();
$socias[1]->celular_texto_plano = 'x'; // #2 sin celular válido
$wpdb->on( '/FROM wp_cn_miembros$/', $socias );
$res = CN_Vinculo_Lote::procesar();
t( 'paso candidatas con 1 par en el textarea y 2 excluidas', 'candidatas' === $res['paso'] && cs_id( 1 ) . ' 1' === $res['texto'] && 2 === count( $res['cand']['excluidas'] ), json_encode( $res ) );
t( 'no escribe en miembros ni en el log de MP', ! $wpdb->escrituras( 'cn_miembros' ) && ! $wpdb->log_mp_tipos() );
ob_start();
CN_Vinculo_Lote::render( $res );
$html = ob_get_clean();
t( 'la pantalla muestra el botón, el textarea cargado y la tabla Excluidas sin datos completos',
	false !== strpos( $html, 'Cargar candidatas seguras' ) && false !== strpos( $html, cs_id( 1 ) . ' 1' ) && false !== strpos( $html, 'Excluidas' )
	&& false !== strpos( $html, substr( cs_id( 2 ), 0, 8 ) ) && false === strpos( $html, cs_id( 2 ) ) && false === strpos( $html, 'u2@example.com' ) && false !== strpos( $html, 'u***@example.com' ) && false === strpos( $html, '1166660002' ) && false !== strpos( $html, '****0002' ) );
t( 'no hay botón Confirmar tras cargar candidatas (hay que previsualizar)', false === strpos( $html, 'value="confirmar"' ) );
