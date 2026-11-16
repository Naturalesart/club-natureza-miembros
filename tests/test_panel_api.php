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

seccion( 'Panel API: campos extendidos (email completo + uso), apagados por defecto' );
function pa_uso_base( $w ) {
	$w->on( '/FROM wp_cn_miembros\s+WHERE estado IN/', array(
		pa_ficha( 1, array( 'preapproval_id' => 'abc123', 'plan' => 'full' ) ),
		pa_ficha( 2, array( 'email' => 'sinarroba' ) ),
		pa_ficha( 3, array( 'fecha_fin_trial' => '2026-10-20 00:00:00', 'email' => 'Trial@Gmail.com' ) ),
	) );
}
$w = pa_preparar();
pa_uso_base( $w );
$j = json_decode( json_encode( CN_Panel_API::manejar( pa_req( 'tok-bueno' ) )->data ), true );
t( 'flag de uso apagado: sin email completo ni bloque uso, extendido=false', false === $j['extendido'] && ! isset( $j['socias'][0]['email'] ) && ! isset( $j['socias'][0]['uso'] ) );
t( 'flag apagado: no consulta sesiones, vistos ni progreso', ! array_filter( $w->consultas, function ( $q ) {
	return preg_match( '/wp_cn_(sesiones|vistos|progreso)/', $q );
} ) );
t( 'flag apagado: ultimo_acceso sigue en null', null === $j['socias'][0]['ultimo_acceso'] );

$w = pa_preparar();
update_option( 'cn_panel_uso_activo', '1' );
pa_uso_base( $w );
$exp = gmdate( 'Y-m-d H:i:s', time() + 100 * 86400 ); // login hace 80 días (sesión de 180)
$w->on( '/FROM wp_cn_sesiones WHERE miembro_id IN/', array( cn_fila( array( 'miembro_id' => 1, 'expira' => $exp ) ), cn_fila( array( 'miembro_id' => 3, 'expira' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ) ) ) );
$w->on( '/FROM wp_cn_vistos WHERE miembro_id IN/', array( cn_fila( array( 'miembro_id' => 1, 'n' => '7', 'primera' => '2026-10-14 12:30:00', 'ultima' => '2026-10-20 15:00:00' ) ) ) );
$w->on( '/FROM wp_cn_progreso WHERE miembro_id IN/', array( cn_fila( array( 'miembro_id' => 1, 'curso_id' => 4, 'contenido_id' => 22, 'actualizado' => '2026-10-20 15:00:00' ) ) ) );
$r = CN_Panel_API::manejar( pa_req( 'tok-bueno' ) );
$j = json_decode( json_encode( $r->data ), true );
$u = $j['socias'][0]['uso'];
t( 'extendido=true y email completo en las fichas con email válido', true === $j['extendido'] && 'juana@gmail.com' === $j['socias'][0]['email'] && 'Trial@Gmail.com' === $j['socias'][2]['email'] );
t( 'email sin arroba: null', null === $j['socias'][1]['email'] );
t( 'sigue estando el email enmascarado (compatibilidad)', 'j***@gmail.com' === $j['socias'][0]['email_enmascarado'] );
t( 'uso: contenidos vistos, último visto y última actividad en ISO', 7 === $u['contenidos_vistos'] && '2026-10-20T15:00:00Z' === $u['ultimo_visto'] && '2026-10-20T15:00:00Z' === $u['ultima_actividad'] && 4 === $u['ultimo_curso_id'] && 22 === $u['ultimo_contenido_id'] );
t( 'uso: primer_visto = primera vez que marcó un contenido como visto (ISO) y null si nunca vio nada', '2026-10-14T12:30:00Z' === $u['primer_visto'] && null === $j['socias'][1]['uso']['primer_visto'] );
t( 'uso: sesión abierta y último login aproximado = expira - 180 días', true === $u['sesion_abierta'] && gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $exp . ' UTC' ) - 180 * 86400 ) === $u['ultimo_login_aprox'] );
t( 'sesión vencida: sesion_abierta=false (con fecha aproximada)', false === $j['socias'][2]['uso']['sesion_abierta'] && null !== $j['socias'][2]['uso']['ultimo_login_aprox'] );
t( 'sin sesión, sin vistos y sin progreso (la consulta anduvo): false / 0 / null', false === $j['socias'][1]['uso']['sesion_abierta'] && 0 === $j['socias'][1]['uso']['contenidos_vistos'] && null === $j['socias'][1]['uso']['ultima_actividad'] && null === $j['socias'][1]['uso']['ultimo_login_aprox'] );
t( 'tres consultas agrupadas en total (no una por socia)', 3 === count( array_filter( $w->consultas, function ( $q ) {
	return preg_match( '/FROM wp_cn_(sesiones|vistos|progreso) WHERE miembro_id IN/', $q );
} ) ) );
t( 'cero escrituras en la base (solo el transient del rate limit)', array() === $w->log );
$json = json_encode( $j );
t( 'el bloque uso tiene solo las 8 claves previstas (sin celular ni nombre) y el JSON no lleva el token', array( 'contenidos_vistos', 'primer_visto', 'sesion_abierta', 'ultima_actividad', 'ultimo_contenido_id', 'ultimo_curso_id', 'ultimo_login_aprox', 'ultimo_visto' ) === ( function ( $k ) { sort( $k ); return $k; } )( array_keys( $u ) ) && false === strpos( $json, 'tok-bueno' ) );

$w = pa_preparar();
update_option( 'cn_panel_uso_activo', '1' );
pa_uso_base( $w );
$w->on( '/FROM wp_cn_vistos WHERE miembro_id IN/', function () use ( $w ) {
	$w->last_error = 'Table doesn\'t exist';
	return array();
} );
$j = json_decode( json_encode( CN_Panel_API::manejar( pa_req( 'tok-bueno' ) )->data ), true );
t( 'si falla una consulta: ese campo queda en null (no 0) y el resto sigue', 200 === 200 && null === $j['socias'][0]['uso']['contenidos_vistos'] && false === $j['socias'][0]['uso']['sesion_abierta'] );

seccion( 'Panel API: nunca se guarda en caché (LiteSpeed)' );
$w = pa_preparar();
$w->on( '/FROM wp_cn_miembros\s+WHERE estado IN/', array( pa_ficha( 1 ) ) );
$nc = function ( $r ) {
	return 'no-cache' === ( $r->headers['X-LiteSpeed-Cache-Control'] ?? '' ) && 'no-store' === ( $r->headers['Cache-Control'] ?? '' );
};
t( '200 con token: X-LiteSpeed-Cache-Control no-cache y no-store', $nc( CN_Panel_API::manejar( pa_req( 'tok-bueno' ) ) ) );
t( '401 sin token: también no-cache', $nc( CN_Panel_API::manejar( pa_req() ) ) );
t( '403 token malo: también no-cache', $nc( CN_Panel_API::manejar( pa_req( 'otro' ) ) ) );
$GLOBALS['cn_ssl'] = false;
t( '403 sin HTTPS: también no-cache', $nc( CN_Panel_API::manejar( pa_req( 'tok-bueno' ) ) ) );
$GLOBALS['cn_ssl'] = true;
$w = pa_preparar();
$w->on( '/FROM wp_cn_miembros\s+WHERE estado IN/', function () {
	throw new RuntimeException( 'boom' );
} );
t( '500 interno: también no-cache', $nc( CN_Panel_API::manejar( pa_req( 'tok-bueno' ) ) ) );
for ( $i = 0; $i < 31; $i++ ) {
	$r = CN_Panel_API::manejar( pa_req( 'tok-bueno' ) );
}
t( '429 rate limit: también no-cache', 429 === $r->status && $nc( $r ) );

seccion( 'Panel API: trials vencidos (cn_panel_incluir_trials_vencidos), apagado por defecto' );
function pa_vencidos_base( $w ) {
	$w->on( '/FROM wp_cn_miembros\s+WHERE estado IN/', array(
		pa_ficha( 1, array( 'preapproval_id' => 'abc123', 'plan' => 'full' ) ),
		pa_ficha( 3, array( 'fecha_fin_trial' => '2026-10-20 00:00:00' ) ),
	) );
	$w->on( "/FROM wp_cn_miembros\s+WHERE estado = 'cancelada'/", array(
		pa_ficha( 312, array( 'estado' => 'cancelada', 'fecha_fin_trial' => gmdate( 'Y-m-d H:i:s', time() - 86400 ), 'email' => 'vencida@gmail.com' ) ),
		pa_ficha( 3, array( 'estado' => 'cancelada', 'fecha_fin_trial' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ) ), // repetida a propósito: no se duplica
	) );
}
$w = pa_preparar();
pa_vencidos_base( $w );
$j_off = json_decode( json_encode( CN_Panel_API::manejar( pa_req( 'tok-bueno' ) )->data ), true );
t( 'flag apagado: no hace la consulta de canceladas', ! array_filter( $w->consultas, function ( $q ) {
	return preg_match( "/WHERE estado = 'cancelada'/", $q );
} ) );
t( 'flag apagado: mismas socias, sin trial_vencido ni clave trials_vencidos', 2 === count( $j_off['socias'] ) && ! isset( $j_off['trials_vencidos'] ) && false === strpos( json_encode( $j_off ), 'trial_vencido' ) );

$w = pa_preparar();
update_option( 'cn_panel_incluir_trials_vencidos', '1' );
pa_vencidos_base( $w );
$j = json_decode( json_encode( CN_Panel_API::manejar( pa_req( 'tok-bueno' ) )->data ), true );
$q = implode( "\n", array_filter( $w->consultas, function ( $q ) {
	return preg_match( "/WHERE estado = 'cancelada'/", $q );
} ) );
t( 'flag prendido: consulta canceladas sin suscripción, con trial pagado y vencidas hace <= 14 días', '' !== $q && false !== strpos( $q, "preapproval_id IS NULL OR preapproval_id = ''" ) && false !== strpos( $q, "trial_payment_id <> ''" ) && false !== strpos( $q, 'fecha_fin_trial >= ' . "'" . gmdate( 'Y-m-d', time() - 14 * 86400 ) ) );
t( 'flag prendido: agrega la vencida al final y no duplica la que ya estaba', 3 === count( $j['socias'] ) && 312 === $j['socias'][2]['id'] );
t( 'la agregada trae trial_vencido=true, tipo inactiva y los campos de siempre', true === $j['socias'][2]['trial_vencido'] && 'inactiva' === $j['socias'][2]['tipo'] && 'cancelada' === $j['socias'][2]['estado'] && 'v***@gmail.com' === $j['socias'][2]['email_enmascarado'] );
t( 'las fichas de siempre quedan idénticas a con el flag apagado', $j_off['socias'] === array_slice( $j['socias'], 0, 2 ) );
t( 'resumen trials_vencidos: cantidad, truncados, días y aptas (sin decisión = 0)', array( 'cantidad' => 2, 'truncados' => false, 'dias' => 14, 'aptas_winback' => 0 ) === $j['trials_vencidos'] );
t( 'sin decisión de la guarda: guarda_mp null y apta_winback false (ante la duda no se escribe)', null === $j['socias'][2]['guarda_mp'] && false === $j['socias'][2]['apta_winback'] && false === $j['socias'][2]['sub_sin_vincular'] );
t( 'las fichas de siempre no traen campos de guarda', ! isset( $j['socias'][0]['guarda_mp'] ) && ! isset( $j['socias'][1]['apta_winback'] ) );
t( 'flag prendido: cero escrituras en la base', array() === $w->log );

$w = pa_preparar();
update_option( 'cn_panel_incluir_trials_vencidos', '1' );
update_option( 'cn_panel_uso_activo', '1' );
pa_vencidos_base( $w );
$j = json_decode( json_encode( CN_Panel_API::manejar( pa_req( 'tok-bueno' ) )->data ), true );
t( 'con uso prendido: la vencida trae email completo y bloque uso (el motor matchea por email)', 'vencida@gmail.com' === $j['socias'][2]['email'] && isset( $j['socias'][2]['uso'] ) );
$uq = implode( "\n", array_filter( $w->consultas, function ( $q ) {
	return preg_match( '/FROM wp_cn_vistos WHERE miembro_id IN/', $q );
} ) );
t( 'el uso se calcula también para la vencida (misma consulta agrupada)', false !== strpos( $uq, '312' ) );

$w = pa_preparar();
update_option( 'cn_panel_incluir_trials_vencidos', '1' );
$muchas = array();
for ( $i = 1000; $i <= 1200; $i++ ) {
	$muchas[] = pa_ficha( $i, array( 'estado' => 'cancelada', 'fecha_fin_trial' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ) );
}
$w->on( '/FROM wp_cn_miembros\s+WHERE estado IN/', array( pa_ficha( 1 ) ) );
$w->on( "/FROM wp_cn_miembros\s+WHERE estado = 'cancelada'/", $muchas );
$j = json_decode( json_encode( CN_Panel_API::manejar( pa_req( 'tok-bueno' ) )->data ), true );
t( 'tope propio de 200 vencidas y avisa que se truncó (no toca socias_truncadas)', 201 === count( $j['socias'] ) && true === $j['trials_vencidos']['truncados'] && 200 === $j['trials_vencidos']['cantidad'] && false === $j['socias_truncadas'] );

seccion( 'Panel API: guarda de los trials vencidos (no escribirle a quien ya se suscribió)' );
function pa_guarda( $decisiones, $avisos = array(), $vinculadas = array() ) {
	$w = pa_preparar();
	update_option( 'cn_panel_incluir_trials_vencidos', '1' );
	$w->on( '/FROM wp_cn_miembros\s+WHERE estado IN/', array( pa_ficha( 1, array( 'preapproval_id' => 'abc123', 'plan' => 'full' ) ) ) );
	$v = gmdate( 'Y-m-d H:i:s', time() - 86400 );
	$w->on( "/FROM wp_cn_miembros\s+WHERE estado = 'cancelada'/", array(
		pa_ficha( 312, array( 'estado' => 'cancelada', 'fecha_fin_trial' => $v, 'email' => 'Vencida@Gmail.com' ) ),
		pa_ficha( 313, array( 'estado' => 'cancelada', 'fecha_fin_trial' => $v, 'email' => 'otra@gmail.com' ) ),
		pa_ficha( 314, array( 'estado' => 'cancelada', 'fecha_fin_trial' => $v, 'email' => 'tercera@gmail.com' ) ),
	) );
	$w->on( "/FROM wp_cn_mp_log WHERE tipo = 'trial_guarda_decision'/", array_map( function ( $d ) {
		return cn_fila( array( 'fecha' => $d[2], 'payload' => json_encode( array( 'miembro_id' => $d[0], 'resultado' => $d[1], 'motivo' => '', 'solo_log' => true ) ) ) );
	}, $decisiones ) );
	$w->on( "/FROM wp_cn_mp_log WHERE tipo = 'aviso_error' AND payload LIKE/", $avisos ? array( cn_fila( array( 'fecha' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'payload' => json_encode( array( 'motivo' => 'sub_sin_vincular', 'detalle' => array( 'items' => $avisos ) ) ) ) ) ) : array() );
	$w->on( '/SELECT preapproval_id FROM wp_cn_miembros WHERE preapproval_id IN/', $vinculadas );
	$j = json_decode( json_encode( CN_Panel_API::manejar( pa_req( 'tok-bueno' ) )->data ), true );
	$por_id = array();
	foreach ( $j['socias'] as $s ) {
		$por_id[ $s['id'] ] = $s;
	}
	return array( $w, $j, $por_id );
}
list( $w, $j, $s ) = pa_guarda( array(
	array( 312, 'sin_pago', '2026-11-14 12:00:00' ),
	array( 312, 'error', '2026-11-13 12:00:00' ), // más vieja: no gana
	array( 313, 'pago', '2026-11-14 12:00:00' ),
	array( 314, 'indeterminado', '2026-11-14 12:00:00' ),
) );
t( 'gana la decisión más nueva y sin_pago => apta_winback', 'sin_pago' === $s[312]['guarda_mp'] && '2026-11-14T12:00:00Z' === $s[312]['guarda_mp_fecha'] && true === $s[312]['apta_winback'] );
t( 'guarda dice pago (pagó por link directo) => no apta', 'pago' === $s[313]['guarda_mp'] && false === $s[313]['apta_winback'] );
t( 'indeterminado / error => no apta', 'indeterminado' === $s[314]['guarda_mp'] && false === $s[314]['apta_winback'] );
t( 'resumen: 1 apta de 3', 1 === $j['trials_vencidos']['aptas_winback'] && 3 === $j['trials_vencidos']['cantidad'] );
t( 'la guarda solo lee (cero escrituras, cero HTTP)', array() === $w->log && array() === $GLOBALS['cn_http_log'] );

$sp = array( array( 312, 'sin_pago', '2026-11-14 12:00:00' ), array( 313, 'sin_pago', '2026-11-14 12:00:00' ), array( 314, 'sin_pago', '2026-11-14 12:00:00' ) );
list( $w, $j, $s ) = pa_guarda( $sp, array(
	array( 'preapproval_id' => 'p1', 'email' => 'vencida@gmail.com', 'socia_id' => null, 'candidatas' => array() ), // mismo email (otra mayúscula)
	array( 'preapproval_id' => 'p2', 'email' => 'x@y.com', 'socia_id' => 313, 'candidatas' => array() ),             // apunta por socia_id
) );
t( 'suscripción sin vincular con su email => sub_sin_vincular y no apta', true === $s[312]['sub_sin_vincular'] && false === $s[312]['apta_winback'] );
t( 'suscripción sin vincular que la propone como socia => no apta', true === $s[313]['sub_sin_vincular'] && false === $s[313]['apta_winback'] );
t( 'la que no aparece en ningún aviso sigue apta', false === $s[314]['sub_sin_vincular'] && true === $s[314]['apta_winback'] );

list( $w, $j, $s ) = pa_guarda( $sp, array( array( 'preapproval_id' => 'p3', 'email' => 'x@y.com', 'socia_id' => null, 'candidatas' => array( 314 ) ) ), array( 'p3' ) );
t( 'aviso de una suscripción que ya se vinculó a otra ficha: no bloquea', false === $s[314]['sub_sin_vincular'] && true === $s[314]['apta_winback'] );
list( $w, $j, $s ) = pa_guarda( $sp, array( array( 'preapproval_id' => 'p4', 'email' => 'x@y.com', 'socia_id' => null, 'candidatas' => array( 314 ) ) ) );
t( 'aviso que la trae como candidata => no apta', true === $s[314]['sub_sin_vincular'] && false === $s[314]['apta_winback'] );

$w = pa_preparar();
pa_vencidos_base( $w );
CN_Panel_API::manejar( pa_req( 'tok-bueno' ) );
t( 'flag apagado: no lee trial_guarda_decision', ! array_filter( $w->consultas, function ( $q ) {
	return false !== strpos( $q, 'trial_guarda_decision' );
} ) );
