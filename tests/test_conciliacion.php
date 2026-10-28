<?php
// D1 — Conciliación diaria MP <-> fichas (CN_Conciliacion): modo 'log' por defecto (solo mp_log), 'on' manda UN mail grave
// con tope global de 1 por hora, 'off' no hace nada. Solo SELECT y solo GET a MP.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
const CC_A = 'aaaaaaaa11111111aaaaaaaa11111111'; // authorized con ficha
const CC_B = 'bbbbbbbb22222222bbbbbbbb22222222'; // authorized sin ficha, vieja (> 72 hs)
const CC_C = 'cccccccc33333333cccccccc33333333'; // authorized sin ficha, reciente (< 1 h)
const CC_D = 'dddddddd44444444dddddddd44444444'; // ficha activa, ya no authorized, acceso vencido
const CC_E = 'eeeeeeee55555555eeeeeeee55555555'; // ficha activa, ya no authorized, acceso a futuro (gracia)
const CC_P = 'pppppppp66666666pppppppp66666666'; // pausada

function cc_reset( $auth = null, $pau = null, $fichas = null ) {
	cn_test_reset();
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	$auth   = null === $auth ? array(
		array( 'id' => CC_A, 'date_created' => gmdate( 'c', time() - 30 * 86400 ), 'payer_email' => 'x@y.com' ),
		array( 'id' => CC_B, 'date_created' => gmdate( 'c', time() - 5 * 86400 ), 'payer_email' => 'x@y.com' ),
		array( 'id' => CC_C, 'date_created' => gmdate( 'c', time() - 600 ), 'payer_email' => 'x@y.com' ),
	) : $auth;
	$pau    = null === $pau ? array( array( 'id' => CC_P, 'date_created' => gmdate( 'c', time() - 9 * 86400 ) ) ) : $pau;
	$fichas = null === $fichas ? array(
		cn_fila( array( 'id' => 11, 'estado' => 'activo', 'preapproval_id' => CC_A, 'acceso_hasta' => gmdate( 'Y-m-d H:i:s', time() + 5 * 86400 ) ) ),
		cn_fila( array( 'id' => 12, 'estado' => 'activo', 'preapproval_id' => CC_D, 'acceso_hasta' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ) ) ),
		cn_fila( array( 'id' => 13, 'estado' => 'activo', 'preapproval_id' => CC_E, 'acceso_hasta' => gmdate( 'Y-m-d H:i:s', time() + 3 * 86400 ) ) ),
		cn_fila( array( 'id' => 14, 'estado' => 'cancelada', 'preapproval_id' => 'ffffffff77777777ffffffff77777777', 'acceso_hasta' => null ) ),
	) : $fichas;
	cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => $auth, 'paging' => array( 'total' => count( $auth ) ) ) );
	cn_http_on( 'GET', '#/preapproval/search\?status=paused#', array( 'results' => $pau, 'paging' => array( 'total' => count( $pau ) ) ) );
	$GLOBALS['wpdb']->on( '/preapproval_id IS NOT NULL/', $fichas );
}
function cc_mails() {
	return array_map( function ( $c ) {
		return json_decode( (string) $c['body'], true );
	}, cn_http_calls( '#club-trial-alerta#' ) );
}
function cc_logs( $tipo ) {
	$out = array();
	foreach ( $GLOBALS['wpdb']->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && $tipo === $l[2]['tipo'] ) {
			$out[] = json_decode( $l[2]['payload'], true );
		}
	}
	return $out;
}

seccion( 'modo por defecto: log' );
cc_reset();
t( 'sin opción, el modo es log', 'log' === CN_Conciliacion::modo() );
update_option( CN_Conciliacion::OPT_MODO, 'cualquier-cosa' );
t( 'un valor raro cae en log', 'log' === CN_Conciliacion::modo() );
cc_reset();
$r = CN_Conciliacion::correr();
t( 'detecta la suscripción sin ficha (vieja) y NO la reciente', array( CC_B ) === $r['sin_ficha'] );
t( 'detecta la ficha activa con acceso vencido y sin authorized (no la de gracia ni la cancelada)', array( CC_D ) === $r['ficha_sin_authorized'] );
t( 'primera corrida: las pausadas fijan la línea base, no cuentan como nuevas', $r['linea_base'] && array() === $r['pausadas_nuevas'] );
t( 'en log NO manda mail', ! cc_mails() && false === $r['mail'] );
$l = cc_logs( 'conciliacion_diaria' );
t( 'deja UNA fila mp_log conciliacion_diaria con cantidades', 1 === count( $l ) && 1 === $l[0]['sin_ficha'] && 1 === $l[0]['sin_ficha_mas_72h'] && 1 === $l[0]['ficha_sin_authorized'] && 3 === $l[0]['authorized_en_mp'] );
$json = json_encode( $l );
t( 'ids enmascarados: no aparece ningún id completo ni emails', false === strpos( $json, CC_B ) && false === strpos( $json, 'x@y.com' ) && false !== strpos( $json, 'bbbbbb...' ) );
$w = $GLOBALS['wpdb'];
t( 'no escribe en miembros ni en sesiones; solo inserts en mp_log', ! $w->escrituras( 'cn_miembros' ) && ! $w->escrituras( 'sesiones' ) && ! array_filter( $w->log, function ( $x ) {
	return 'insert' === $x[0] && false === strpos( $x[1], 'mp_log' );
} ) );
t( 'solo GET a MP (ningún POST/PUT hacia mercadopago)', ! array_filter( $GLOBALS['cn_http_log'], function ( $c ) {
	return false !== strpos( $c['url'], 'mercadopago' ) && 'GET' !== $c['metodo'];
} ) );

seccion( 'pausadas nuevas (después de la línea base)' );
$r2 = CN_Conciliacion::correr();
t( 'segunda corrida con la misma pausada: ninguna nueva', ! $r2['linea_base'] && array() === $r2['pausadas_nuevas'] );
cc_reset( null, array( array( 'id' => CC_P ), array( 'id' => 'gggggggg88888888gggggggg88888888' ) ) );
update_option( CN_Conciliacion::OPT_PAUSADAS, array( CC_P => time() - 86400 ) );
$r3 = CN_Conciliacion::correr();
t( 'una pausada que no se había visto: aparece como nueva', array( 'gggggggg88888888gggggggg88888888' ) === $r3['pausadas_nuevas'] );

seccion( 'si MP falla no se concluye nada' );
cc_reset();
cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', 500 );
$r = CN_Conciliacion::correr();
t( 'authorized con 500: devuelve null, deja conciliacion_incompleta y ningún mail', null === $r && 1 === count( cc_logs( 'conciliacion_incompleta' ) ) && ! cc_logs( 'conciliacion_diaria' ) && ! cc_mails() );
cc_reset();
cn_http_on( 'GET', '#/preapproval/search\?status=paused#', 500 );
$r = CN_Conciliacion::correr();
t( 'paused con 500: igual concilia lo demás y las pausadas quedan "sin_dato"', is_array( $r ) && array( CC_B ) === $r['sin_ficha'] && 'sin_dato' === cc_logs( 'conciliacion_diaria' )[0]['pausadas_nuevas'] && false === get_option( CN_Conciliacion::OPT_PAUSADAS, false ) );
cc_reset();
$GLOBALS['wpdb']->on( '/preapproval_id IS NOT NULL/', function () {
	return array();
} );
$GLOBALS['wpdb']->last_error = 'boom';
$r = CN_Conciliacion::correr();
t( 'error leyendo fichas: no concluye (null) ni manda mail', null === $r && ! cc_mails() );
cc_reset();
update_option( 'cn_mp_access_token', '' );
t( 'sin token de MP: no hace nada', null === CN_Conciliacion::correr() && ! $GLOBALS['cn_http_log'] );

seccion( 'modo off' );
cc_reset();
update_option( CN_Conciliacion::OPT_MODO, 'off' );
t( 'off: no consulta MP ni escribe nada', null === CN_Conciliacion::correr() && ! $GLOBALS['cn_http_log'] && ! cc_logs( 'conciliacion_diaria' ) );

seccion( 'modo on: un solo mail, solo por lo grave, tope global' );
cc_reset();
update_option( CN_Conciliacion::OPT_MODO, 'on' );
$r = CN_Conciliacion::correr();
$m = cc_mails();
t( 'hay algo grave: UN mail con asunto claro y "Qué hacer"', true === $r['mail'] && 1 === count( $m ) && 'conciliacion_mp' === $m[0]['motivo'] && false !== strpos( $m[0]['asunto'], '[Club Natureza] GRAVE' ) && false !== strpos( $m[0]['cuerpo'], 'Qué hacer' ) );
t( 'el mail no lleva ids completos, emails ni nombres', false === strpos( json_encode( $m ), CC_B ) && false === strpos( json_encode( $m ), 'x@y.com' ) );
$r = CN_Conciliacion::correr();
t( 'segunda corrida el mismo día: no repite el mail', false === $r['mail'] && 1 === count( cc_mails() ) );
cc_reset();
update_option( CN_Conciliacion::OPT_MODO, 'on' );
update_option( CN_Alertas::OPT_ULTIMO_ENVIO, time() - 600 );
$r = CN_Conciliacion::correr();
t( 'si otra alerta salió hace 10 min: NO manda (tope de 1 por hora) y lo deja anotado', false === $r['mail'] && ! cc_mails() && 1 === count( cc_logs( 'alerta_grave_demorada' ) ) );
t( 'ese mail demorado no consume su período: queda disponible para la próxima corrida', 0 === (int) get_option( CN_Conciliacion::OPT_MAIL, 0 ) );
update_option( CN_Alertas::OPT_ULTIMO_ENVIO, time() - 2 * 3600 );
$r = CN_Conciliacion::correr();
t( 'pasada la hora: sale', true === $r['mail'] && 1 === count( cc_mails() ) );
cc_reset( array( array( 'id' => CC_A, 'date_created' => gmdate( 'c', time() - 30 * 86400 ) ), array( 'id' => CC_C, 'date_created' => gmdate( 'c', time() - 600 ) ) ), array(), array(
	cn_fila( array( 'id' => 11, 'estado' => 'activo', 'preapproval_id' => CC_A, 'acceso_hasta' => null ) ),
	cn_fila( array( 'id' => 13, 'estado' => 'activo', 'preapproval_id' => CC_E, 'acceso_hasta' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ) ),
) );
update_option( CN_Conciliacion::OPT_MODO, 'on' );
update_option( CN_Conciliacion::OPT_PAUSADAS, array() );
$r = CN_Conciliacion::correr();
t( 'sin nada grave (solo una sin ficha reciente y una ficha en gracia): no manda mail', false === $r['mail'] && ! cc_mails() );

seccion( 'está enganchado al cron diario' );
cc_reset();
CN_Alertas::chequeo_diario();
t( 'chequeo_diario corre la conciliación (deja conciliacion_diaria)', 1 === count( cc_logs( 'conciliacion_diaria' ) ) );
t( 'el archivo principal carga la clase', false !== strpos( file_get_contents( dirname( __DIR__ ) . '/club-natureza-miembros.php' ), 'class-cn-conciliacion.php' ) );
