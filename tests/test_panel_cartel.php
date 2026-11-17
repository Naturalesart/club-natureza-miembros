<?php
// Bloque `cartel` de CN_Panel_API (métricas del cartel de la PR #44): forma del JSON, solo números (sin datos de fichas ni de MP),
// solo lectura y que con la API apagada o sin token no responda.
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
function pcar_req( $token = 'tok-cartel' ) {
	$r = new WP_REST_Request();
	if ( null !== $token ) {
		$r->h['x_cn_panel_token'] = $token;
	}
	return $r;
}
function pcar_dia( $hace = 0 ) {
	return gmdate( 'Y-m-d', time() - $hace * 86400 );
}
/** API prendida + token; la tabla de pendientes devuelve los agregados que se pasen (como los devolvería el GROUP BY). */
function pcar_preparar( $agregados = array(), $conteo = null, $modo = 'on' ) {
	cn_test_reset();
	$GLOBALS['cn_ssl']   = true;
	$GLOBALS['cn_rutas'] = array();
	update_option( 'cn_panel_api_activo', '1' );
	update_option( 'cn_panel_token_hash', hash( 'sha256', 'tok-cartel' ) );
	if ( null !== $modo ) {
		update_option( 'cn_cartel_trial_modo', $modo );
	}
	if ( null !== $conteo ) {
		update_option( 'cn_cartel_trial_conteo', $conteo );
	}
	global $wpdb;
	$wpdb->on( '/FROM wp_cn_suscripciones_pendientes WHERE origen IN/', array_map( 'cn_fila', $agregados ) );
	return $wpdb;
}
/** El JSON del bloque, decodificado como arrays. */
function pcar_cartel( $r ) {
	return json_decode( wp_json_encode( $r->data['cartel'] ), true );
}
/** Recorre todas las hojas: true si todas son números (int) salvo modo/zona, que son las dos únicas cadenas. */
function pcar_solo_numeros( $c ) {
	$ok = true;
	array_walk_recursive( $c, function ( $v, $k ) use ( &$ok ) {
		if ( in_array( $k, array( 'modo', 'zona' ), true ) ) {
			$ok = $ok && is_string( $v );
			return;
		}
		$ok = $ok && is_int( $v );
	} );
	return $ok;
}

$agregados = array(
	// hoy: 3 clics al Full (1 ya pagó), 2 al Club (ninguno pagó)
	array( 'dia' => pcar_dia( 0 ), 'origen' => 'club_cartel_full', 'plan' => 'full', 'usado' => '0', 'n' => '2' ),
	array( 'dia' => pcar_dia( 0 ), 'origen' => 'club_cartel_full', 'plan' => 'full', 'usado' => '1', 'n' => '1' ),
	array( 'dia' => pcar_dia( 0 ), 'origen' => 'club_cartel_club', 'plan' => 'basico', 'usado' => '0', 'n' => '2' ),
	// hace 2 días: 1 clic al Club que pagó
	array( 'dia' => pcar_dia( 2 ), 'origen' => 'club_cartel_club', 'plan' => 'basico', 'usado' => '1', 'n' => '1' ),
	// hace 40 días: entra en los totales, no en por_dia
	array( 'dia' => pcar_dia( 40 ), 'origen' => 'club_cartel_full', 'plan' => 'full', 'usado' => '1', 'n' => '4' ),
);
$conteo = array(
	pcar_dia( 0 )  => array( 'mostrado' => 5, 'sin_email' => 1 ),
	pcar_dia( 1 )  => array( 'se_mostraria' => 3, 'suscribir_off' => 2, 'sin_celular' => 1, 'sin_secreto' => 0, 'sin_token' => 0, 'con_cache' => 0 ),
	pcar_dia( 45 ) => array( 'mostrado' => 9 ), // fuera de los 30 días
);

seccion( 'Panel cartel: forma del JSON' );
$w = pcar_preparar( $agregados, $conteo );
$r = CN_Panel_API::manejar( pcar_req() );
t( 'con token: 200 y trae el bloque cartel', 200 === $r->status && isset( $r->data['cartel'] ) );
$c = pcar_cartel( $r );
t( 'los 8 motivos del cartel están en la lista cerrada', array( 'mostrado', 'se_mostraria', 'sin_email', 'sin_celular', 'suscribir_off', 'sin_secreto', 'sin_token', 'con_cache' ) === CN_Panel_API::CARTEL_MOTIVOS
	&& ( ! class_exists( 'CN_Cartel_Trial' ) || array() === array_diff( CN_Cartel_Trial::MOTIVOS, CN_Panel_API::CARTEL_MOTIVOS ) ) );
t( 'claves del bloque: modo, dias, zona, por_dia, clics, altas', array( 'modo', 'dias', 'zona', 'por_dia', 'clics', 'altas' ) === array_keys( $c ) );
t( 'modo on / 30 días / UTC', 'on' === $c['modo'] && 30 === $c['dias'] && 'UTC' === $c['zona'] );
t( 'por_dia: el conteo tal cual, solo los últimos 30 días, del más nuevo al más viejo',
	array( pcar_dia( 0 ) => array( 'mostrado' => 5, 'sin_email' => 1 ), pcar_dia( 1 ) => $conteo[ pcar_dia( 1 ) ] ) === $c['por_dia'] );
t( 'clics: total con todo el historial (incluye los de hace 40 días)', 10 === $c['clics']['total'] );
t( 'clics: por origen', array( 'club_cartel_full' => 7, 'club_cartel_club' => 3 ) === $c['clics']['por_origen'] );
t( 'clics: por día (solo 30 días), con total y por origen', array(
	pcar_dia( 0 ) => array( 'total' => 5, 'club_cartel_full' => 3, 'club_cartel_club' => 2 ),
	pcar_dia( 2 ) => array( 'total' => 1, 'club_cartel_full' => 0, 'club_cartel_club' => 1 ),
) === $c['clics']['por_dia'] );
t( 'altas: solo usado = 1, total', 6 === $c['altas']['total'] );
t( 'altas: por plan', array( 'full' => 5, 'basico' => 1 ) === $c['altas']['por_plan'] );
t( 'altas: por día (solo 30 días), separadas por plan', array(
	pcar_dia( 0 ) => array( 'total' => 1, 'full' => 1, 'basico' => 0 ),
	pcar_dia( 2 ) => array( 'total' => 1, 'full' => 0, 'basico' => 1 ),
) === $c['altas']['por_dia'] );
t( 'solo números (las únicas cadenas son modo y zona)', pcar_solo_numeros( $c ) );

seccion( 'Panel cartel: vacío y modos' );
$r = CN_Panel_API::manejar( pcar_req() ); // ya con datos: segunda vuelta para rate limit no importa
$w = pcar_preparar( array(), null, null );
$r = CN_Panel_API::manejar( pcar_req() );
$c = pcar_cartel( $r );
$j = wp_json_encode( $r->data['cartel'] );
t( 'sin datos: ceros y modo off por defecto', 'off' === $c['modo'] && 0 === $c['clics']['total'] && 0 === $c['altas']['total']
	&& array( 'club_cartel_full' => 0, 'club_cartel_club' => 0 ) === $c['clics']['por_origen'] && array( 'full' => 0, 'basico' => 0 ) === $c['altas']['por_plan'] );
t( 'sin datos: los por_dia salen como objeto vacío {} (no [])', 3 === substr_count( $j, '"por_dia":{}' ), $j );
foreach ( array( 'log' => 'log', 'on' => 'on', 'cualquiera' => 'off', '' => 'off' ) as $guardado => $esperado ) {
	pcar_preparar( array(), null, $guardado );
	t( "modo guardado '{$guardado}' => {$esperado}", $esperado === pcar_cartel( CN_Panel_API::manejar( pcar_req() ) )['modo'] );
}

seccion( 'Panel cartel: sin datos personales' );
$sucios = array(
	array( 'dia' => pcar_dia( 0 ), 'origen' => 'club_cartel_full', 'plan' => 'full', 'usado' => '1', 'n' => '1',
		'external_reference' => 'sub_secreto_123', 'celular' => '1155550099', 'email' => 'socia.x@gmail.com', 'nombre_apellido' => 'Nombre Secreto', 'preapproval_id' => 'pre_abc999', 'ficha_id' => '4321' ),
	array( 'dia' => pcar_dia( 0 ), 'origen' => 'mail_d5', 'plan' => 'full', 'usado' => '1', 'n' => '50' ), // otro origen: no cuenta
	array( 'dia' => pcar_dia( 0 ), 'origen' => 'club_cartel_club', 'plan' => 'otro', 'usado' => '1', 'n' => '2' ), // plan raro: clic sí, alta no
);
$conteo_sucio = array(
	pcar_dia( 0 )     => array( 'mostrado' => '3', 'Ficha 4321 <b>' => 1 ),
	'socia@x.com'   => array( 'mostrado' => 1 ),
	pcar_dia( 1 )     => 'no-es-array',
);
$w = pcar_preparar( $sucios, $conteo_sucio );
$r = CN_Panel_API::manejar( pcar_req() );
$c = pcar_cartel( $r );
$j = wp_json_encode( $r->data['cartel'] );
t( 'aunque la consulta trajera columnas de más, no salen', false === strpos( $j, 'sub_secreto' ) && false === strpos( $j, '1155550099' ) && false === strpos( $j, 'socia.x' )
	&& false === stripos( $j, 'Nombre Secreto' ) && false === strpos( $j, 'pre_abc' ) && false === strpos( $j, '4321' ), $j );
t( 'sin claves de datos personales ni ids', 0 === preg_match( '/"(external_reference|celular|email|nombre|nombre_apellido|preapproval_id|ficha_id|miembro_id|id)"/', $j ) );
t( 'otros orígenes no cuentan', 3 === $c['clics']['total'] && 1 === $c['altas']['total'] );
t( 'conteo: fechas inválidas descartadas, solo motivos de la lista cerrada, valores enteros', array( pcar_dia( 0 ) => array( 'mostrado' => 3 ) ) === $c['por_dia'] && false === strpos( $j, '@' ) );
t( 'solo números', pcar_solo_numeros( $c ) );

seccion( 'Panel cartel: solo lectura' );
$w = pcar_preparar( $agregados, $conteo );
$opts_antes = $GLOBALS['cn_opts'];
CN_Panel_API::manejar( pcar_req() );
$sql_cartel = array_values( array_filter( $w->consultas, function ( $q ) {
	return false !== strpos( $q, 'suscripciones_pendientes' );
} ) );
t( 'una sola consulta a pendientes, agregada (COUNT + GROUP BY), solo los 2 orígenes del cartel', 1 === count( $sql_cartel )
	&& 0 === strpos( $sql_cartel[0], 'SELECT DATE(creado) AS dia, origen, plan, usado, COUNT(*) AS n FROM wp_cn_suscripciones_pendientes' )
	&& false !== strpos( $sql_cartel[0], "WHERE origen IN ('club_cartel_full', 'club_cartel_club') GROUP BY" ), $sql_cartel[0] ?? '' );
t( 'cero escrituras en la base (ni insert, ni update, ni delete, ni query)', array() === $w->log );
t( 'ninguna consulta de escritura', 0 === count( preg_grep( '/\b(UPDATE|DELETE|INSERT|REPLACE|ALTER|DROP|TRUNCATE)\b/i', $w->consultas ) ) );
t( 'no cambia ninguna opción (ni el conteo ni el modo del cartel)', $opts_antes === $GLOBALS['cn_opts'] );
t( 'no llama a Mercado Pago ni a nada externo', array() === $GLOBALS['cn_http_log'] );
$src = '';
foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-panel-api.php' ) ) as $tk ) {
	if ( is_array( $tk ) && in_array( $tk[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}
	$src .= is_array( $tk ) ? $tk[1] : $tk;
}
$ini   = strpos( $src, 'protected static function cartel(' );
$fin   = strpos( $src, 'public static function procesar_generar_token' );
$bloque = false !== $ini && false !== $fin ? substr( $src, $ini, $fin - $ini ) : '';
t( 'inspección: el método cartel() no escribe ni llama a otras clases del plugin', '' !== $bloque
	&& 0 === preg_match( '/->(insert|update|delete|query|replace)\(|update_option|delete_option|set_transient|delete_transient|CN_(Cartel_Trial|Suscribir|MP|Webhook|Auth|Sync)::|wp_mail|wp_remote_/', $bloque ) );

seccion( 'Panel cartel: con la API apagada o sin token no responde' );
$w = pcar_preparar( $agregados, $conteo );
update_option( 'cn_panel_api_activo', '0' );
CN_Panel_API::registrar_rutas();
$r = CN_Panel_API::manejar( pcar_req() );
t( 'API apagada: la ruta no se registra', array() === $GLOBALS['cn_rutas'] );
t( 'API apagada: 404 sin bloque cartel ni consultas', 404 === $r->status && ! isset( $r->data['cartel'] ) && array() === $w->consultas );
$w = pcar_preparar( $agregados, $conteo );
$r = CN_Panel_API::manejar( pcar_req( null ) );
t( 'sin token: 401 sin bloque cartel ni consultas', 401 === $r->status && ! isset( $r->data['cartel'] ) && array() === $w->consultas );
$r = CN_Panel_API::manejar( pcar_req( 'otro' ) );
t( 'token malo: 403 sin bloque cartel', 403 === $r->status && ! isset( $r->data['cartel'] ) && array() === $w->consultas );
$GLOBALS['cn_ssl'] = false;
$r = CN_Panel_API::manejar( pcar_req() );
t( 'sin HTTPS: 403 sin bloque cartel', 403 === $r->status && ! isset( $r->data['cartel'] ) );
$GLOBALS['cn_ssl'] = true;
$r = CN_Panel_API::manejar( pcar_req() );
t( 'la respuesta con el bloque lleva no-store y no-cache de LiteSpeed', 'no-store' === ( $r->headers['Cache-Control'] ?? '' ) && 'no-cache' === ( $r->headers['X-LiteSpeed-Cache-Control'] ?? '' ) );
