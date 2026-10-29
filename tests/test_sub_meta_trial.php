<?php
// Subscribe con fbp/fbc del trial: CN_Webhook::sub_meta_desde_trial() vía sub_post_alta().
// Cubre los tres caminos de alta (webhook, vinculación por id y por celular): todos pasan por sub_post_alta().
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
class CN_Test_SubMeta extends CN_Webhook {
	public static function correr( $fila ) {
		self::sub_post_alta( $fila, 'club', array( 'club' => array( 'nombre' => 'Club', 'precio' => 15500 ) ) );
	}
}
function smt_fila( $extra = array() ) {
	return (object) array_merge( array(
		'nombre_apellido'    => 'Socia Prueba',
		'celular'            => '1155550001',
		'email'              => '',
		'external_reference' => 'sub_1155550001_abc12345',
		'fbp'                => '',
		'fbc'                => '',
		'ip'                 => '',
		'user_agent'         => '',
	), $extra );
}

function smt_trial( $id, $fbp, $fbc, $ip = '', $ua = '' ) {
	return (object) array( 'id' => $id, 'fbp' => $fbp, 'fbc' => $fbc, 'ip' => $ip, 'user_agent' => $ua );
}
function smt_preparar( $trial ) {
	cn_test_reset();
	global $wpdb;
	$wpdb->on( '/FROM wp_cn_trial_pendientes WHERE celular = /', $trial );
}
function smt_consulto_trial() {
	global $wpdb;
	foreach ( $wpdb->consultas as $q ) {
		if ( false !== strpos( $q, 'wp_cn_trial_pendientes' ) ) {
			return true;
		}
	}
	return false;
}
function smt_capi() {
	return $GLOBALS['cn_capi'][0] ?? null;
}

// 1. Alta sin fbp/fbc y trial con todo: se completan los cuatro desde la misma fila.
smt_preparar( smt_trial( 7, 'fb.1.1700000000000.111', 'fb.1.1700000000000.AbCdEf', '190.1.2.3', 'Mozilla/5.0 test' ) );
CN_Test_SubMeta::correr( smt_fila() );
$c = smt_capi();
t( '1 evento Subscribe', 1 === count( $GLOBALS['cn_capi'] ) );
t( 'event_id intacto', 'sub_1155550001_abc12345' === $c[0] );
t( 'fbp desde el trial', 'fb.1.1700000000000.111' === $c[5] );
t( 'fbc desde el trial', 'fb.1.1700000000000.AbCdEf' === $c[6] );
t( 'ip desde el trial', '190.1.2.3' === $c[7] );
t( 'user_agent desde el trial', 'Mozilla/5.0 test' === $c[8] );
t( 'consulta por celular exacto', (bool) preg_grep( "/celular = '1155550001'/", $GLOBALS['wpdb']->consultas ) );
t( 'deja rastro sub_meta_desde_trial', in_array( 'sub_meta_desde_trial', $GLOBALS['wpdb']->log_mp_tipos(), true ) );
t( 'sin escrituras en tablas de datos', ! $GLOBALS['wpdb']->escrituras( 'miembros' ) && ! $GLOBALS['wpdb']->escrituras( 'pendientes' ) );
$rastro = null;
foreach ( $GLOBALS['wpdb']->log as $l ) {
	if ( 'insert' === $l[0] && 'sub_meta_desde_trial' === $l[2]['tipo'] ) {
		$rastro = $l[2]['payload'];
	}
}
t( 'el rastro no lleva celular', is_string( $rastro ) && false === strpos( $rastro, '1155550001' ) && false !== strpos( $rastro, '"trial_pendiente_id":7' ) );
t( 'el rastro lleva el mismo event_id (hash) que capi_subscribe', is_string( $rastro ) && false !== strpos( $rastro, '"event_id":"' . substr( hash( 'sha256', 'sub_1155550001_abc12345' ), 0, 32 ) . '"' ) );

// 2. Sin trial para ese celular: queda como hoy (vacío) y el evento sale igual.
smt_preparar( null );
CN_Test_SubMeta::correr( smt_fila() );
$c = smt_capi();
t( 'sin match: evento sale igual', 1 === count( $GLOBALS['cn_capi'] ) );
t( 'sin match: fbp/fbc vacíos', '' === $c[5] && '' === $c[6] && '' === $c[7] && '' === $c[8] );
t( 'sin match: no deja rastro', ! in_array( 'sub_meta_desde_trial', $GLOBALS['wpdb']->log_mp_tipos(), true ) );

// 3. La fila ya trae fbp y fbc (alta desde una landing con cookies): no se consulta el trial ni se pisa nada.
smt_preparar( smt_trial( 7, 'fbp-trial', 'fbc-trial', '9.9.9.9', 'ua-trial' ) );
CN_Test_SubMeta::correr( smt_fila( array( 'fbp' => 'fbp-propio', 'fbc' => 'fbc-propio', 'ip' => '1.1.1.1', 'user_agent' => 'ua-propio' ) ) );
$c = smt_capi();
t( 'con datos propios: no consulta trial', ! smt_consulto_trial() );
t( 'con datos propios: no pisa nada', 'fbp-propio' === $c[5] && 'fbc-propio' === $c[6] && '1.1.1.1' === $c[7] && 'ua-propio' === $c[8] );

// 4. La fila trae solo fbp: se completa fbc (y lo que falte) sin pisar el fbp propio.
smt_preparar( smt_trial( 8, 'fbp-trial', 'fbc-trial', '9.9.9.9', 'ua-trial' ) );
CN_Test_SubMeta::correr( smt_fila( array( 'fbp' => 'fbp-propio', 'ip' => '1.1.1.1' ) ) );
$c = smt_capi();
t( 'parcial: mantiene fbp propio', 'fbp-propio' === $c[5] );
t( 'parcial: completa fbc del trial', 'fbc-trial' === $c[6] );
t( 'parcial: mantiene ip propia y completa user_agent', '1.1.1.1' === $c[7] && 'ua-trial' === $c[8] );

// 5. Trial solo con fbp (sin fbc): se completa fbp y fbc queda vacío.
smt_preparar( smt_trial( 9, 'fbp-solo', null ) );
CN_Test_SubMeta::correr( smt_fila() );
$c = smt_capi();
t( 'trial solo fbp: completa fbp', 'fbp-solo' === $c[5] );
t( 'trial solo fbp: fbc sigue vacío', '' === $c[6] );

// 6. Sin celular (ficha vieja sin texto plano): no consulta nada y el evento sale como hoy.
smt_preparar( smt_trial( 7, 'fbp-trial', 'fbc-trial' ) );
CN_Test_SubMeta::correr( smt_fila( array( 'celular' => '' ) ) );
$c = smt_capi();
t( 'sin celular: no consulta trial', ! smt_consulto_trial() );
t( 'sin celular: evento sale vacío', 1 === count( $GLOBALS['cn_capi'] ) && '' === $c[5] && '' === $c[6] );

// 7. Error de base al consultar: se registra y el evento sale igual, sin meta.
cn_test_reset();
$GLOBALS['wpdb']->on( '/FROM wp_cn_trial_pendientes WHERE celular = /', function () {
	throw new RuntimeException( 'tabla rota' );
} );
CN_Test_SubMeta::correr( smt_fila() );
$c = smt_capi();
t( 'error DB: evento sale igual', 1 === count( $GLOBALS['cn_capi'] ) && '' === $c[5] && '' === $c[6] );
t( 'error DB: queda sub_post_alta_error', in_array( 'sub_post_alta_error', $GLOBALS['wpdb']->log_mp_tipos(), true ) );

// 8. La consulta prefiere la fila con fbc y, entre ellas, la más reciente.
smt_preparar( null );
CN_Test_SubMeta::correr( smt_fila() );
$sql = implode( "\n", $GLOBALS['wpdb']->consultas );
t( 'ordena por fbc presente y luego id DESC', false !== strpos( $sql, "ORDER BY ( fbc IS NOT NULL AND fbc <> '' ) DESC, id DESC LIMIT 1" ) );
