<?php
// Listados /preapproval/search (CN_MP::listar_preapprovals): reintentan ante 429 con presupuesto chico; flag cn_listar_reintentos = '0' vuelve a un solo intento.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
function lr_reset() {
	cn_test_reset();
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
}
/** Respuesta secuencial: cada pedido toma el siguiente elemento (el último se repite). */
function lr_secuencia( $respuestas ) {
	$i = 0;
	cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', function () use ( &$i, $respuestas ) {
		return $respuestas[ min( $i++, count( $respuestas ) - 1 ) ];
	} );
}
function lr_pedidos() {
	return count( cn_http_calls( '#/preapproval/search#' ) );
}
function lr_logs( $tipo ) {
	return count( array_filter( $GLOBALS['wpdb']->log_mp_tipos(), function ( $t ) use ( $tipo ) {
		return $t === $tipo;
	} ) );
}
$ok  = array( 'results' => array( array( 'id' => 'sub1' ) ), 'paging' => array( 'total' => 1 ) );
$e429 = array( '__status' => 429, '__headers' => array( 'retry-after' => '2' ), '__body' => array( 'message' => 'rate' ) );

seccion( '429 y después 200: se recupera sin que nadie se entere' );
lr_reset();
$esperas = array();
CN_MP::$dormir = function ( $s ) use ( &$esperas ) {
	$esperas[] = $s;
};
lr_secuencia( array( $e429, $ok ) );
$r = CN_MP::listar_preapprovals( 'authorized' );
t( 'devuelve los resultados tras 1 reintento (2 pedidos)', is_array( $r ) && 'sub1' === $r['results'][0]['id'] && 2 === lr_pedidos() );
t( 'esperó lo que pidió Retry-After (2 s) y no dejó mp_api_error', array( 2 ) === $esperas && 0 === lr_logs( 'mp_api_error' ) );

seccion( 'listar_authorized (lo usan el sync y las pantallas) también se recupera' );
lr_reset();
lr_secuencia( array( $e429, $e429, $ok ) );
$l = CN_Vinculo::listar_authorized();
t( 'ok=true con 3 pedidos', true === $l['ok'] && 1 === count( $l['items'] ) && 3 === lr_pedidos() );

seccion( '429 que no cede: se rinde dentro del presupuesto y lo registra una vez' );
lr_reset();
lr_secuencia( array( $e429 ) );
$r = CN_MP::listar_preapprovals( 'authorized' );
t( 'devuelve null (nunca "lista vacía") y no pasa de 4 pedidos', null === $r && 4 === lr_pedidos() );
t( 'queda UN mp_api_error', 1 === lr_logs( 'mp_api_error' ) );
lr_reset();
lr_secuencia( array( $e429 ) );
$l = CN_Vinculo::listar_authorized();
t( 'listar_authorized: ok=false (quien llama no concluye nada)', false === $l['ok'] && array() === $l['items'] );
lr_reset();
lr_secuencia( array( array( '__status' => 429, '__headers' => array( 'retry-after' => '25' ), '__body' => array() ) ) );
t( 'Retry-After mayor que el presupuesto: no espera ni insiste (1 pedido)', null === CN_MP::listar_preapprovals( 'authorized' ) && 1 === lr_pedidos() );

seccion( 'otros errores no se reintentan' );
foreach ( array( 401, 403, 500, 404 ) as $code ) {
	lr_reset();
	lr_secuencia( array( $code ) );
	t( "{$code}: un solo pedido y null", null === CN_MP::listar_preapprovals( 'authorized' ) && 1 === lr_pedidos() );
}
lr_reset();
lr_secuencia( array( null ) );
t( 'sin respuesta de red: un solo pedido y null', null === CN_MP::listar_preapprovals( 'authorized' ) && 1 === lr_pedidos() );
lr_reset();
update_option( 'cn_mp_access_token', '' );
t( 'sin token: null y ningún pedido', null === CN_MP::listar_preapprovals( 'authorized' ) && 0 === lr_pedidos() );

seccion( 'flag apagado: comportamiento anterior' );
lr_reset();
update_option( CN_MP::OPT_LISTAR_REINTENTOS, '0' );
lr_secuencia( array( $e429, $ok ) );
t( 'cn_listar_reintentos=0: un solo intento ante 429 (null)', null === CN_MP::listar_preapprovals( 'authorized' ) && 1 === lr_pedidos() );
lr_reset();
update_option( CN_MP::OPT_LISTAR_REINTENTOS, '0' );
lr_secuencia( array( $ok ) );
$r = CN_MP::listar_preapprovals( 'authorized' );
t( 'cn_listar_reintentos=0: con 200 devuelve lo mismo que antes', is_array( $r ) && 'sub1' === $r['results'][0]['id'] );

seccion( 'solo lectura' );
lr_reset();
lr_secuencia( array( $e429, $ok ) );
CN_MP::listar_preapprovals( 'authorized' );
t( 'solo GET a MP y ninguna escritura en miembros', ! array_filter( $GLOBALS['cn_http_log'], function ( $c ) {
	return false !== strpos( $c['url'], 'mercadopago' ) && 'GET' !== $c['metodo'];
} ) && ! $GLOBALS['wpdb']->escrituras( 'cn_miembros' ) );
CN_MP::$dormir = function () {
};
