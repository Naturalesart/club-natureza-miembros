<?php
// API de solo lectura del panel de socias (CN_Panel_API): flag, HTTPS, 401/403, rate limit, forma del JSON, enmascarado y cero escrituras.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public $headers = array();
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function header( $k, $v ) {
			$this->headers[ $k ] = $v;
		}
	}
	class WP_REST_Request {
		public $h = array();
		public function get_header( $k ) {
			return $this->h[ $k ] ?? null;
		}
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $p = '' ) {
		return 'https://ejemplo.test/wp-json/' . $p;
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $ns, $ruta, $args ) {
		$GLOBALS['cn_rutas'][] = $ns . $ruta;
	}
}
function pa_req( $token = null ) {
	$r = new WP_REST_Request();
	if ( null !== $token ) {
		$r->h['x_cn_panel_token'] = $token;
	}
	return $r;
}
function pa_preparar() {
	cn_test_reset();
	$GLOBALS['cn_ssl']   = true;
	$GLOBALS['cn_rutas'] = array();
	update_option( 'cn_panel_api_activo', '1' );
	update_option( 'cn_panel_token_hash', hash( 'sha256', 'tok-bueno' ) );
	return $GLOBALS['wpdb'];
}
function pa_ficha( $id, $extra = array() ) {
	return cn_fila( array_merge( array(
		'id' => $id, 'nombre_apellido' => 'Socia ' . $id, 'estado' => 'activo', 'preapproval_id' => null, 'plan' => null, 'acceso_hasta' => null, 'baja_en' => null,
		'fecha_alta' => '2026-09-01 10:00:00', 'fecha_fin_trial' => null, 'email' => 'juana@gmail.com', 'celular_texto_plano' => '1155667788',
	), $extra ) );
}

seccion( 'Panel API: flag apagado' );
$w = pa_preparar();
update_option( 'cn_panel_api_activo', '0' );
CN_Panel_API::registrar_rutas();
t( 'flag apagado: la ruta no se registra', array() === $GLOBALS['cn_rutas'] );
$r = CN_Panel_API::manejar( pa_req( 'tok-bueno' ) );
t( 'flag apagado: 404 aunque el token sea bueno', 404 === $r->status );
t( 'flag apagado: no escribe nada (ni transient ni base)', array() === $GLOBALS['cn_transients'] && array() === $w->log );
update_option( 'cn_panel_api_activo', '1' );
CN_Panel_API::registrar_rutas();
t( 'flag prendido: registra cn/v1/panel-datos', array( 'cn/v1/panel-datos' ) === $GLOBALS['cn_rutas'] );

seccion( 'Panel API: HTTPS, 401, 403' );
$w                 = pa_preparar();
$GLOBALS['cn_ssl'] = false;
t( 'sin HTTPS: 403', 403 === CN_Panel_API::manejar( pa_req( 'tok-bueno' ) )->status );
$GLOBALS['cn_ssl'] = true;
t( 'sin token: 401', 401 === CN_Panel_API::manejar( pa_req() )->status );
t( 'token vacío: 401', 401 === CN_Panel_API::manejar( pa_req( '  ' ) )->status );
t( 'token malo: 403', 403 === CN_Panel_API::manejar( pa_req( 'otro' ) )->status );
$_GET['token'] = $_REQUEST['token'] = 'tok-bueno';
t( 'token por query string: no se acepta (401)', 401 === CN_Panel_API::manejar( pa_req() )->status );
$_GET = $_REQUEST = array();
update_option( 'cn_panel_token_hash', '' );
t( 'sin token generado: 403 con cualquier valor', 403 === CN_Panel_API::manejar( pa_req( 'tok-bueno' ) )->status );
t( 'los rechazos no cuentan para el rate limit ni escriben', array() === $GLOBALS['cn_transients'] && array() === $w->log );

seccion( 'Panel API: 200, forma del JSON y cero escrituras' );
$w = pa_preparar();
$w->on( '/FROM wp_cn_miembros\s+WHERE estado IN/', array(
	pa_ficha( 1, array( 'preapproval_id' => 'abc123', 'plan' => 'full', 'acceso_hasta' => '2026-11-10 00:00:00' ) ),
	pa_ficha( 2, array( 'estado' => 'pausado', 'celular_texto_plano' => '12345', 'email' => 'sinarroba' ) ),
	pa_ficha( 3, array( 'fecha_fin_trial' => '2026-10-20 00:00:00', 'celular_texto_plano' => '+54 9 11 5566-7788' ) ),
	pa_ficha( 4, array( 'estado' => 'cancelada', 'preapproval_id' => 'zzz', 'celular_texto_plano' => null, 'email' => null ) ),
) );
$w->on( "/FROM wp_cn_mp_log WHERE tipo = 'aviso_error' AND payload LIKE/", array(
	cn_fila( array( 'fecha' => '2026-10-13 12:00:00', 'payload' => json_encode( array( 'motivo' => 'sub_sin_vincular', 'detalle' => array( 'items' => array(
		array( 'preapproval_id' => 'sinficha1', 'plan' => 'basico', 'monto' => 15500 ), array( 'preapproval_id' => 'yaligada', 'plan' => 'full', 'monto' => 20000 ) ) ) ) ) ) ),
	cn_fila( array( 'fecha' => '2026-10-08 12:00:00', 'payload' => json_encode( array( 'motivo' => 'sub_sin_vincular', 'detalle' => array( 'items' => array(
		array( 'preapproval_id' => 'sinficha1', 'plan' => 'basico', 'monto' => 15500 ) ) ) ) ) ) ),
) );
$w->on( '/SELECT preapproval_id FROM wp_cn_miembros WHERE preapproval_id IN/', array( 'yaligada', 'pendligada' ) );
$w->on( "/SELECT COUNT\(\*\).*tipo = 'downgrade_omitido'/", '4' );
$w->on( "/SELECT COUNT\(\*\).*tipo = 'mp_api_error'/", '7' );
$w->on( "/SELECT COUNT\(\*\).*tipo = 'mp_api_error'.*LIKE/", '2' );
update_option( 'cn_retorno_pendientes', array( 'pendligada' => array( 'fecha' => '2026-10-13 09:00:00', 'motivo' => 'x' ), 'pend2' => array( 'fecha' => '2026-10-13 09:30:00', 'motivo' => 'intentos' ) ) );
update_option( 'cn_sync_ultima_ok', strtotime( '2026-10-13 14:30:00 UTC' ) );
update_option( 'cn_sync_mp_cursor', 42 );
$r = CN_Panel_API::manejar( pa_req( 'tok-bueno' ) );
t( '200 con token bueno', 200 === $r->status );
t( 'Cache-Control: no-store', 'no-store' === ( $r->headers['Cache-Control'] ?? '' ) );
$j = json_decode( json_encode( $r->data ), true );
t( 'JSON con las 4 secciones y generado_en ISO', isset( $j['generado_en'], $j['socias'], $j['alertas'], $j['config'], $j['sync'] ) && 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $j['generado_en'] ) );
t( 'socia suscripta: tipo, plan y acceso_hasta ISO', 'suscripta' === $j['socias'][0]['tipo'] && 'full' === $j['socias'][0]['plan'] && '2026-11-10T00:00:00Z' === $j['socias'][0]['acceso_hasta'] );
t( 'email enmascarado j***@gmail.com', 'j***@gmail.com' === $j['socias'][0]['email_enmascarado'] );
t( 'celular válido: solo dígitos', '1155667788' === $j['socias'][0]['celular'] && '1155667788' === $j['socias'][2]['celular'] );
t( 'celular inválido o vacío: null', null === $j['socias'][1]['celular'] && null === $j['socias'][3]['celular'] );
t( 'email sin arroba o vacío: null', null === $j['socias'][1]['email_enmascarado'] && null === $j['socias'][3]['email_enmascarado'] );
t( 'tipos: inactiva / trial / inactiva', 'inactiva' === $j['socias'][1]['tipo'] && 'trial' === $j['socias'][2]['tipo'] && 'inactiva' === $j['socias'][3]['tipo'] );
t( 'plan y preapproval vacíos en null; ultimo_acceso siempre null', null === $j['socias'][1]['plan'] && null === $j['socias'][1]['preapproval_id'] && null === $j['socias'][0]['ultimo_acceso'] );
t( 'el JSON no trae email completo ni hash de celular', false === strpos( json_encode( $j ), 'juana@' ) && false === strpos( json_encode( $j ), 'celular_hash' ) );
$sf = $j['alertas']['authorized_sin_ficha'];
t( 'sin ficha: descarta la que ya tiene ficha y deduplica', 1 === $sf['cantidad'] && 'sinficha1' === $sf['items'][0]['preapproval_id'] && 'basico' === $sf['items'][0]['plan'] && 15500.0 === (float) $sf['items'][0]['monto'] );
t( 'sin ficha: primer aviso viejo => mas_de_72h; horas desconocidas = null', true === $sf['items'][0]['mas_de_72h'] && true === $sf['mas_de_72h'] && null === $sf['items'][0]['horas_sin_vincular'] );
$ea = $j['alertas']['esperando_acceso'];
t( 'esperando acceso: saca la que ya tiene ficha', 1 === $ea['cantidad'] && 'pend2' === $ea['lista'][0]['preapproval_id'] );
t( 'pausadas modo seguro: 4 en 72 h', 4 === $j['alertas']['pausadas_modo_seguro']['cantidad'] );
t( 'avisos abiertos: vacío sin avisos', 0 === $j['alertas']['avisos_abiertos']['cantidad'] && array() === $j['alertas']['avisos_abiertos']['tipos'] );
t( 'config: solo modos y booleanos', array( 'cn_alta_mail_modo' => 'off', 'cn_sub_modo_seguro' => true, 'cn_sub_auto_activar' => false, 'cn_retorno_activo' => false, 'espejo' => 'off', 'sync_activo' => true, 'cn_guarda_trial_solo_log' => true ) === $j['config'] );
t( 'sync: última ok, resultado, 429, errores, cursor', '2026-10-13T14:30:00Z' === $j['sync']['ultima_corrida_ok'] && 'ok' === $j['sync']['resultado'] && 2 === $j['sync']['errores_429_24h'] && 7 === $j['sync']['errores_mp_api_24h'] && 42 === $j['sync']['cursor'] && null === $j['sync']['ultimo_lote_con_fallos'] );
t( 'cero escrituras en la base (solo SELECT)', array() === $w->log );
t( 'solo se escribió el transient del rate limit', 1 === count( $GLOBALS['cn_transients'] ) && 0 === strpos( (string) key( $GLOBALS['cn_transients'] ), 'cn_panel_rl_' ) );
t( 'ninguna llamada HTTP (ni a Mercado Pago)', array() === $GLOBALS['cn_http_log'] );

seccion( 'Panel API: tope de 600 fichas' );
$w      = pa_preparar();
$muchas = array();
for ( $i = 1; $i <= 601; $i++ ) {
	$muchas[] = pa_ficha( $i );
}
$w->on( '/FROM wp_cn_miembros\s+WHERE estado IN/', $muchas );
$j = json_decode( json_encode( CN_Panel_API::manejar( pa_req( 'tok-bueno' ) )->data ), true );
t( 'máx. 600 fichas y avisa que se truncó', 600 === count( $j['socias'] ) && true === $j['socias_truncadas'] );

seccion( 'Panel API: rate limit' );
$w       = pa_preparar();
$codigos = array();
for ( $i = 1; $i <= 31; $i++ ) {
	$codigos[] = CN_Panel_API::manejar( pa_req( 'tok-bueno' ) )->status;
}
t( '30 pedidos 200 y el 31 da 429', 30 === count( array_keys( $codigos, 200, true ) ) && 429 === $codigos[30] );
$r = CN_Panel_API::manejar( pa_req( 'tok-bueno' ) );
t( '429 con Retry-After y no-store', 'no-store' === $r->headers['Cache-Control'] && isset( $r->headers['Retry-After'] ) );

seccion( 'Panel API: token (solo se guarda el hash)' );
$w     = pa_preparar();
$_POST = array( 'cn_panel_generar_token' => '1' );
$tk    = CN_Panel_API::procesar_generar_token();
t( 'generar token: 64 hex y se guarda solo el sha256', is_string( $tk ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $tk ) && hash( 'sha256', $tk ) === get_option( 'cn_panel_token_hash' ) );
t( 'el token nuevo funciona y el viejo ya no', 403 === CN_Panel_API::manejar( pa_req( 'tok-bueno' ) )->status && 200 === CN_Panel_API::manejar( pa_req( $tk ) )->status );
t( 'el token no queda en mp_log ni en ninguna opción', array() === $w->log && false === strpos( json_encode( $GLOBALS['cn_opts'] ), $tk ) );
$_POST = array();
t( 'sin POST no genera nada', null === CN_Panel_API::procesar_generar_token() );
