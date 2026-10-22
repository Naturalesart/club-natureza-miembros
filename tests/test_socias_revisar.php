<?php
// "Socias a revisar": listado de solo lectura y reactivación de a una, solo con authorized en MP (solo GET).
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
const SR_PRE = 'abcdef1234567890abcdef1234567890';
function sr_ficha( $id, $estado = 'cancelada', $pre = SR_PRE, $extra = array() ) {
	return cn_fila( array_merge( array( 'id' => $id, 'nombre_apellido' => 'Sandra Perez', 'estado' => $estado, 'plan' => 'basico', 'fecha_fin_trial' => '2026-10-01 10:00:00', 'preapproval_id' => $pre, 'fecha_modificacion' => '2026-10-10 10:00:00' ), $extra ) );
}
/** Prepara una ficha 5 (lectura simple y relectura FOR UPDATE) y la respuesta de MP (array | código | null). */
function sr_preparar( $ficha, $mp, $ficha_locked = false ) {
	cn_test_reset();
	global $wpdb;
	unset( $GLOBALS['cn_caps'], $GLOBALS['cn_nonce_ok'] );
	$_POST = array();
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	$wpdb->on( '/FROM wp_cn_miembros WHERE id = 5 FOR UPDATE/', array( false === $ficha_locked ? $ficha : $ficha_locked ) );
	$wpdb->on( '/FROM wp_cn_miembros WHERE id = 5$/', array( $ficha ) );
	if ( false !== $mp ) {
		cn_http_on( 'GET', '#/preapproval/' . SR_PRE . '$#', $mp );
	}
	return $wpdb;
}
function sr_escribe( $w ) {
	return $w->escrituras( 'cn_miembros' );
}
function sr_pagina( $post ) {
	$_POST = $post;
	ob_start();
	CN_Socias_Revisar::pagina();
	$h = ob_get_clean();
	$_POST = array();
	return $h;
}

seccion( 'listado: solo lectura, solo fichas con suscripción' );
cn_test_reset();
$w = $GLOBALS['wpdb'];
$w->on( '/FROM wp_cn_miembros WHERE preapproval_id IS NOT NULL/', array( sr_ficha( 5 ), sr_ficha( 6, 'activo', SR_PRE, array( 'nombre_apellido' => 'Con Trial' ) ) ) );
$filas = CN_Socias_Revisar::listar();
$sql   = '';
foreach ( $w->consultas as $q ) {
	$sql = $q;
}
t( 'la consulta pide preapproval_id no nulo ni vacío Y (cancelada O con fecha_fin_trial), con LIMIT 200', false !== strpos( $sql, "preapproval_id IS NOT NULL AND preapproval_id <> ''" ) && false !== strpos( $sql, "( estado = 'cancelada' OR fecha_fin_trial IS NOT NULL )" ) && false !== strpos( $sql, 'LIMIT 200' ), $sql );
t( 'trae las columnas pedidas', false !== strpos( $sql, 'id, nombre_apellido, estado, plan, fecha_fin_trial, preapproval_id, fecha_modificacion' ) && 2 === count( $filas ) );
t( 'solo lectura: el listado no escribe', ! $w->log );
t( 'la consulta no puede traer fichas sin preapproval_id (condición obligatoria, no una alternativa del OR)', 1 === preg_match( "/WHERE preapproval_id IS NOT NULL AND preapproval_id <> '' AND \(/", $sql ) );
t( 'máscara: primeros 6 + ...', 'abcdef...' === CN_Socias_Revisar::mascara_pre( SR_PRE ) );
$h = ( function () use ( $w ) {
	$GLOBALS['cn_caps'] = array( 'manage_options' );
	$w->on( '/FROM wp_cn_miembros WHERE preapproval_id IS NOT NULL/', array( sr_ficha( 5 ), sr_ficha( 6, 'activo', SR_PRE, array( 'nombre_apellido' => '<b>Con Trial</b>' ) ) ) );
	return sr_pagina( array() );
} )();
unset( $GLOBALS['cn_caps'] );
t( 'la pantalla muestra el preapproval enmascarado (nunca completo) y escapa el HTML', false !== strpos( $h, 'abcdef...' ) && false === strpos( $h, SR_PRE ) && false === strpos( $h, '<b>Con Trial</b>' ) );
t( 'botón Reactivar solo en la cancelada (1 de 2 filas), con nonce y confirmación JS', 1 === substr_count( $h, 'cn_rev_reactivar' ) && false !== strpos( $h, 'confirm(' ) && false !== strpos( $h, 'cn_rev_nonce' ) );

seccion( 'reactivar: solo con authorized en Mercado Pago' );
$w = sr_preparar( sr_ficha( 5 ), array( 'id' => SR_PRE, 'status' => 'authorized' ) );
$r = CN_Socias_Revisar::reactivar( 5 );
$esc = sr_escribe( $w );
t( 'authorized: reactiva', true === $r['ok'] );
t( 'un solo UPDATE: estado activo, fecha_fin_trial NULL y fecha_modificacion; nada más', 1 === count( $esc ) && array( 'estado' => 'activo', 'fecha_fin_trial' => null, 'fecha_modificacion' => $GLOBALS['cn_now'] ) === $esc[0][2] && array( 'id' => 5 ) === $esc[0][3] );
t( 'en una transacción con FOR UPDATE (START, COMMIT)', $w->transaccion() === array( 'START TRANSACTION', 'COMMIT' ) && (bool) array_filter( $w->consultas, function ( $q ) {
	return false !== strpos( $q, 'FOR UPDATE' );
} ) );
$p = '';
foreach ( $w->log as $l ) {
	if ( 'insert' === $l[0] && 'reactivada_manual' === $l[2]['tipo'] ) {
		$p = $l[2]['payload'];
	}
}
t( 'deja mp_log reactivada_manual con el id de la ficha y el admin', false !== strpos( $p, '"miembro_id":5' ) && false !== strpos( $p, '"usuario":"admin"' ) && false !== strpos( $p, '"usuario_id":1' ) );
t( 'solo GET a MP, y es el GET de la suscripción', array( 'GET' ) === array_values( array_unique( array_column( $GLOBALS['cn_http_log'], 'metodo' ) ) ) && 1 === count( cn_http_calls( '#/preapproval/' . SR_PRE . '$#' ) ) );

seccion( 'reactivar: otro estado en MP, falla o respuesta rara -> no cambia nada' );
foreach ( array( 'paused', 'cancelled', 'pending' ) as $st ) {
	$w = sr_preparar( sr_ficha( 5 ), array( 'id' => SR_PRE, 'status' => $st ) );
	$r = CN_Socias_Revisar::reactivar( 5 );
	t( "MP dice {$st}: no reactiva, muestra el status real y no abre transacción ni escribe", false === $r['ok'] && false !== strpos( $r['mensaje'], $st ) && ! sr_escribe( $w ) && ! $w->transaccion() && ! in_array( 'reactivada_manual', $w->log_mp_tipos(), true ) );
}
foreach ( array( 404 => 404, 401 => 401, 429 => 429, 500 => 500, 'sin respuesta' => null ) as $nombre => $resp ) {
	$w = sr_preparar( sr_ficha( 5 ), $resp );
	$r = CN_Socias_Revisar::reactivar( 5 );
	t( "MP falla ({$nombre}): no cambia nada", false === $r['ok'] && ! sr_escribe( $w ) && ! $w->transaccion() );
}
$w = sr_preparar( sr_ficha( 5 ), array( 'id' => 'OTRO-ID', 'status' => 'authorized' ) );
$r = CN_Socias_Revisar::reactivar( 5 );
t( 'MP devuelve otro id: no cambia nada', false === $r['ok'] && ! sr_escribe( $w ) );
$w = sr_preparar( sr_ficha( 5 ), array( 'id' => SR_PRE ) );
$r = CN_Socias_Revisar::reactivar( 5 );
t( 'sin status en la respuesta: no cambia nada', false === $r['ok'] && ! sr_escribe( $w ) );
cn_test_reset();
update_option( 'cn_mp_access_token', '' );
$GLOBALS['wpdb']->on( '/FROM wp_cn_miembros WHERE id = 5$/', array( sr_ficha( 5 ) ) );
$r = CN_Socias_Revisar::reactivar( 5 );
t( 'sin token de MP: no cambia nada', false === $r['ok'] && ! sr_escribe( $GLOBALS['wpdb'] ) );

seccion( 'reactivar: solo fichas canceladas y con preapproval_id' );
$w = sr_preparar( sr_ficha( 5, 'activo' ), array( 'id' => SR_PRE, 'status' => 'authorized' ) );
$r = CN_Socias_Revisar::reactivar( 5 );
t( 'ficha activa (con trial): no se toca y ni siquiera se consulta a MP', false === $r['ok'] && ! sr_escribe( $w ) && ! $GLOBALS['cn_http_log'] );
$w = sr_preparar( sr_ficha( 5, 'pausado' ), array( 'id' => SR_PRE, 'status' => 'authorized' ) );
$r = CN_Socias_Revisar::reactivar( 5 );
t( 'ficha pausada: no se toca', false === $r['ok'] && ! sr_escribe( $w ) );
foreach ( array( 'NULL' => null, "''" => '' ) as $nombre => $pre ) {
	$w = sr_preparar( sr_ficha( 5, 'cancelada', $pre ), array( 'id' => SR_PRE, 'status' => 'authorized' ) );
	$r = CN_Socias_Revisar::reactivar( 5 );
	t( "cancelada SIN preapproval_id ({$nombre}): no se toca y no se consulta a MP", false === $r['ok'] && ! sr_escribe( $w ) && ! $GLOBALS['cn_http_log'] );
}
$w = sr_preparar( false, false );
$w->on( '/FROM wp_cn_miembros WHERE id = 5$/', array() );
t( 'ficha inexistente / id inválido: no se toca', false === CN_Socias_Revisar::reactivar( 5 )['ok'] && false === CN_Socias_Revisar::reactivar( 0 )['ok'] && ! sr_escribe( $w ) );
$w = sr_preparar( sr_ficha( 5 ), array( 'id' => SR_PRE, 'status' => 'authorized' ), sr_ficha( 5, 'activo' ) ); // otro proceso la reactivó mientras tanto
$r = CN_Socias_Revisar::reactivar( 5 );
t( 'la relectura con FOR UPDATE la ve cambiada: ROLLBACK, sin UPDATE', false === $r['ok'] && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! in_array( 'COMMIT', $w->transaccion(), true ) && ! sr_escribe( $w ) );
$w = sr_preparar( sr_ficha( 5 ), array( 'id' => SR_PRE, 'status' => 'authorized' ), sr_ficha( 5, 'cancelada', 'OTRA-SUSCRIPCION-1234' ) );
$r = CN_Socias_Revisar::reactivar( 5 );
t( 'si en el medio cambió su preapproval_id: no reactiva', false === $r['ok'] && ! sr_escribe( $w ) );

seccion( 'permisos, nonce y de a una' );
$w = sr_preparar( sr_ficha( 5 ), array( 'id' => SR_PRE, 'status' => 'authorized' ) );
$GLOBALS['cn_caps'] = array( 'edit_posts' );
$h = sr_pagina( array( 'cn_rev_reactivar' => '5', 'cn_rev_nonce' => 'x' ) );
t( 'sin manage_options: no muestra nada, no consulta a MP ni escribe', '' === $h && ! $GLOBALS['cn_http_log'] && ! sr_escribe( $w ) && ! $w->log );
$w = sr_preparar( sr_ficha( 5 ), array( 'id' => SR_PRE, 'status' => 'authorized' ) );
$GLOBALS['cn_nonce_ok'] = false;
$h = sr_pagina( array( 'cn_rev_reactivar' => '5', 'cn_rev_nonce' => 'x' ) );
t( 'nonce inválido: no consulta a MP ni escribe', ! $GLOBALS['cn_http_log'] && ! sr_escribe( $w ) && false === strpos( $h, 'reactivada' ) );
$w = sr_preparar( sr_ficha( 5 ), array( 'id' => SR_PRE, 'status' => 'authorized' ) );
$w->on( '/FROM wp_cn_miembros WHERE preapproval_id IS NOT NULL/', array() );
$h = sr_pagina( array( 'cn_rev_reactivar' => '5', 'cn_rev_nonce' => 'x' ) );
t( 'con permisos y nonce: reactiva y lo informa', 1 === count( sr_escribe( $w ) ) && false !== strpos( $h, 'reactivada' ) );
$w = sr_preparar( sr_ficha( 5 ), array( 'id' => SR_PRE, 'status' => 'authorized' ) );
$w->on( '/FROM wp_cn_miembros WHERE preapproval_id IS NOT NULL/', array() );
sr_pagina( array( 'cn_rev_reactivar' => '5', 'cn_rev_nonce' => 'x', 'cn_rev_ids' => '5,6,7' ) );
t( 'de a una: ni siquiera existe un camino en lote (una sola escritura aunque se manden más ids)', 1 === count( sr_escribe( $w ) ) );
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-socias-revisar.php' );
t( 'el código no usa POST/PUT/DELETE contra MP ni flags nuevos', ! preg_match( '/wp_remote_post|wp_remote_request|wp_remote_head|\'method\'|update_option|get_option/', $src ) );
t( 'una sola línea de require en el archivo principal', 1 === substr_count( file_get_contents( dirname( __DIR__ ) . '/club-natureza-miembros.php' ), 'class-cn-socias-revisar.php' ) );
