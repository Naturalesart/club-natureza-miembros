<?php
// Herramienta temporal "Diag MP pagador": solo GET, nonce, capability, enmascarado, sin escrituras.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
const DG_PRE = 'PRE12345678901234567890';
function dg_pagina( $post ) {
	$_POST = $post;
	ob_start();
	CN_Diag_Pagador::pagina();
	$h = ob_get_clean();
	$_POST = array();
	return $h;
}
function dg_armar() {
	cn_test_reset();
	unset( $GLOBALS['cn_caps'], $GLOBALS['cn_nonce_ok'] );
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	cn_http_on( 'GET', '#/preapproval/' . DG_PRE . '$#', array( 'id' => DG_PRE, 'status' => 'authorized', 'payer_id' => 123456789, 'payer_email' => 'maria.gomez@gmail.com', 'external_reference' => 'sub_abc', 'payer_first_name' => '', 'reason' => 'Club' ) );
	cn_http_on( 'GET', '#/authorized_payments/search\?preapproval_id=' . DG_PRE . '#', array( 'results' => array( array( 'id' => 77, 'preapproval_id' => DG_PRE, 'payment' => array( 'id' => 5550001, 'status' => 'approved' ) ) ) ) );
	cn_http_on( 'GET', '#/v1/payments/5550001$#', array( 'id' => 5550001, 'status' => 'approved', 'payer' => array( 'id' => 123456789, 'email' => 'maria.gomez@gmail.com', 'first_name' => 'María', 'last_name' => 'Gómez Pérez', 'identification' => array( 'type' => 'DNI', 'number' => '30111222' ), 'phone' => array( 'number' => '1155551234' ) ), 'card' => array( 'cardholder' => array( 'name' => 'MARIA GOMEZ' ) ) ) );
}
$ok_post = array( 'cn_diag_pagador' => '1', 'cn_diag_preapproval' => DG_PRE );

seccion( 'enmascarado' );
t( 'email: ab***@dominio.com', 'ma***@gmail.com' === CN_Diag_Pagador::mask_email( 'maria.gomez@gmail.com' ) && 'a***@x.com' === CN_Diag_Pagador::mask_email( 'a@x.com' ) && '***' === CN_Diag_Pagador::mask_email( 'sin-arroba' ) );
t( 'nombre: solo iniciales', 'M. G. P.' === CN_Diag_Pagador::mask_nombre( 'María Gómez Pérez' ) && '***' === CN_Diag_Pagador::mask_nombre( '' ) );
t( 'documento y teléfono: nunca el valor', '(oculto)' === CN_Diag_Pagador::mostrar( '30111222', 'oculto' ) && '(8 dígitos)' === CN_Diag_Pagador::mostrar( '30111222', 'id' ) );

seccion( 'diagnóstico completo: tabla endpoint -> campo -> presente' );
dg_armar();
$r = CN_Diag_Pagador::diagnosticar( DG_PRE );
$mapa = array();
foreach ( $r['filas'] as $f ) {
	$mapa[ $f[0] . ' | ' . $f[1] ] = $f;
}
t( '/preapproval: payer_id y payer_email presentes, payer_first_name vacío = no', $mapa['GET /preapproval/{id} | payer_id'][2] && $mapa['GET /preapproval/{id} | payer_email'][2] && ! $mapa['GET /preapproval/{id} | payer_first_name'][2] );
t( '/preapproval: email enmascarado', 'ma***@gmail.com' === $mapa['GET /preapproval/{id} | payer_email'][3] );
t( 'authorized_payments sirve y entrega el id del cobro', $mapa['GET /authorized_payments/search?preapproval_id= | results[].payment.id'][2] );
t( 'ya no prueba /v1/payments/search si authorized_payments sirvió', ! cn_http_calls( '#/v1/payments/search#' ) );
t( '/v1/payments/{id}: payer.email, first_name, last_name presentes, enmascarados', $mapa['GET /v1/payments/{id} | payer.email'][2] && 'ma***@gmail.com' === $mapa['GET /v1/payments/{id} | payer.email'][3] && 'M.' === $mapa['GET /v1/payments/{id} | payer.first_name'][3] && 'G. P.' === $mapa['GET /v1/payments/{id} | payer.last_name'][3] );
t( 'documento: presente sí, valor oculto; tipo de documento visible', $mapa['GET /v1/payments/{id} | payer.identification.number'][2] && '(oculto)' === $mapa['GET /v1/payments/{id} | payer.identification.number'][3] && 'DNI' === $mapa['GET /v1/payments/{id} | payer.identification.type'][3] );
$html = ( function () use ( $ok_post ) {
	dg_armar();
	return dg_pagina( $ok_post );
} )();
t( 'la página no contiene ningún dato crudo (email, nombre, documento, teléfono, ids)', ! preg_match( '/maria\.gomez|María|Gómez|MARIA|30111222|1155551234|123456789|5550001/', $html ), '' );
t( 'la página muestra la tabla enmascarada', false !== strpos( $html, 'ma***@gmail.com' ) && false !== strpos( $html, 'payer.identification.number' ) );

seccion( 'si authorized_payments no sirve: prueba /v1/payments/search y lo reporta' );
dg_armar();
cn_http_on( 'GET', '#/authorized_payments/search#', 404 );
cn_http_on( 'GET', '#/v1/payments/search\?.*external_reference=sub_abc#', array( 'results' => array( array( 'id' => 5550001 ) ) ) );
$r = CN_Diag_Pagador::diagnosticar( DG_PRE );
t( 'cae al segundo endpoint, lo usa y el cobro se lee', 1 === count( cn_http_calls( '#/v1/payments/search#' ) ) && 1 === count( cn_http_calls( '#/v1/payments/5550001$#' ) ) && false !== strpos( implode( ' ', $r['notas'] ), 'NO sirvió' ) && false !== strpos( implode( ' ', $r['notas'] ), 'SIRVE' ) );
dg_armar();
cn_http_on( 'GET', '#/authorized_payments/search#', 400 );
cn_http_on( 'GET', '#/v1/payments/search#', 400 );
$r = CN_Diag_Pagador::diagnosticar( DG_PRE );
t( 'ningún endpoint de cobros sirve: lo dice claro y no consulta /v1/payments/{id}', ! cn_http_calls( '#/v1/payments/5550001#' ) && (bool) array_filter( $r['notas'], function ( $n ) {
	return false !== strpos( $n, 'Ningún endpoint de cobros sirvió' );
} ) );
dg_armar();
cn_http_on( 'GET', '#/preapproval/' . DG_PRE . '$#', 404 );
cn_http_on( 'GET', '#/authorized_payments/search#', array( 'results' => array() ) );
$r = CN_Diag_Pagador::diagnosticar( DG_PRE );
t( 'suscripción inexistente (404): se reporta y no revienta', 'HTTP 404' === $r['filas'][0][3] && ! $r['error'] );
cn_test_reset();
t( 'sin token guardado: avisa y no consulta nada', '' !== CN_Diag_Pagador::diagnosticar( DG_PRE )['error'] && ! $GLOBALS['cn_http_log'] );

seccion( 'capability y nonce' );
dg_armar();
$GLOBALS['cn_caps'] = array( 'edit_posts' );
$h = dg_pagina( $ok_post );
t( 'sin manage_options: no muestra nada ni consulta a MP', '' === $h && ! $GLOBALS['cn_http_log'] );
dg_armar();
$GLOBALS['cn_nonce_ok'] = false;
$h = dg_pagina( $ok_post );
t( 'nonce inválido: no consulta a MP', ! $GLOBALS['cn_http_log'] && false === strpos( $h, '<table' ) );
dg_armar();
$h = dg_pagina( array() );
t( 'sin enviar el formulario: solo el formulario, sin consultas', ! $GLOBALS['cn_http_log'] && false !== strpos( $h, 'cn_diag_preapproval' ) );
$h = dg_pagina( array( 'cn_diag_pagador' => '1', 'cn_diag_preapproval' => '../../x?y=1' ) );
t( 'id con caracteres raros: rechazado, sin consultas', ! $GLOBALS['cn_http_log'] && false !== strpos( $h, 'ID inválido' ) );
t( 'el formulario lleva el campo nonce', false !== strpos( dg_pagina( array() ), 'cn_diag_nonce' ) );
t( 'el submenú exige manage_options', 1 === preg_match( "/add_submenu_page\( 'cn-socias', 'Diag MP pagador', 'Diag MP pagador', 'manage_options'/", file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-diag-pagador.php' ) ) );

seccion( 'solo GET y cero escrituras' );
dg_armar();
dg_pagina( $ok_post );
$metodos = array_unique( array_column( $GLOBALS['cn_http_log'], 'metodo' ) );
t( 'todas las llamadas a MP fueron GET', array( 'GET' ) === array_values( $metodos ) && count( $GLOBALS['cn_http_log'] ) >= 3 );
t( 'no escribe en la base (ni mp_log ni nada)', ! $GLOBALS['wpdb']->log );
$opciones_antes = array( 'cn_mp_access_token' => 'APP_USR-test' );
t( 'no escribe options', $GLOBALS['cn_opts'] === $opciones_antes, json_encode( $GLOBALS['cn_opts'] ) );
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-diag-pagador.php' );
t( 'el código no contiene verbos de escritura ni mp_log ni update_option', ! preg_match( '/wp_remote_post|wp_remote_request|wp_remote_head|\'method\'|registrar_log_mp|update_option|add_option|->insert\(|->update\(|->delete\(|->query\(|error_log/', $src ) );
t( 'está cargada con un solo require_once en el archivo principal', 1 === substr_count( file_get_contents( dirname( __DIR__ ) . '/club-natureza-miembros.php' ), 'class-cn-diag-pagador.php' ) );
