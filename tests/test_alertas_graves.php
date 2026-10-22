<?php
// Alertas GRAVES (CN_Alertas): heartbeat del sync, token de MP inválido y resumen de pagos sin acceso a las 72 hs.
// Son las únicas 3 salidas a n8n (mail). Todo lo demás: solo mp_log + cartel.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
function ag_reset() {
	cn_test_reset();
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => array(), 'paging' => array( 'total' => 0 ) ) );
	cn_http_on( 'GET', '#/v1/payments/search#', array( 'results' => array(), 'paging' => array( 'total' => 0 ) ) );
}
/** Cuerpos (decodificados) de los POST a n8n. */
function ag_alertas() {
	return array_map( function ( $c ) {
		return json_decode( (string) $c['body'], true );
	}, cn_http_calls( '#club-trial-alerta#' ) );
}
function ag_tipos() {
	return $GLOBALS['wpdb']->log_mp_tipos();
}

seccion( 'heartbeat del sync (D2)' );
ag_reset();
CN_Sync::correr();
t( 'primera corrida: empieza a vigilar, no alerta, y al terminar bien guarda la última corrida exitosa', ! ag_alertas() && abs( (int) get_option( CN_Alertas::OPT_SYNC_OK ) - time() ) <= 2 && (int) get_option( CN_Alertas::OPT_SYNC_VIGILADO ) > 0 );
ag_reset();
update_option( CN_Alertas::OPT_SYNC_VIGILADO, time() - 10 * 86400 );
update_option( CN_Alertas::OPT_SYNC_OK, time() - 23 * 3600 );
CN_Sync::correr();
t( 'a las 23 hs sin corrida exitosa: todavía no alerta', ! ag_alertas() );
ag_reset();
update_option( CN_Alertas::OPT_SYNC_VIGILADO, time() - 10 * 86400 );
update_option( CN_Alertas::OPT_SYNC_OK, time() - 25 * 3600 );
$w = $GLOBALS['wpdb'];
$w->on( '/SELECT id, preapproval_id FROM/', function () use ( $w ) { // el sync sigue roto
	$w->last_error = 'boom';
	return array();
} );
CN_Sync::correr();
$a = ag_alertas();
t( 'a las 25 hs sin corrida exitosa: UNA alerta sync_caido con asunto claro', 1 === count( $a ) && 'sync_caido' === $a[0]['motivo'] && CN_Alertas::ASUNTO_SYNC === $a[0]['asunto'] && false !== strpos( $a[0]['cuerpo'], 'Qué hacer' ) && false !== strpos( $a[0]['cuerpo'], 'hace 25 hs' ) );
t( 'queda marcado como caído y la corrida fallida NO renueva la última exitosa', '1' === get_option( CN_Alertas::OPT_SYNC_CAIDO ) && time() - (int) get_option( CN_Alertas::OPT_SYNC_OK ) >= 25 * 3600 - 5 );
CN_Sync::correr();
CN_Sync::correr();
t( 'sigue caído: no repite el mail dentro de las 24 hs', 1 === count( ag_alertas() ) );
update_option( CN_Alertas::OPT_MAIL_SYNC, time() - 25 * 3600 );
CN_Sync::correr();
t( 'pasadas 24 hs y sigue caído: vuelve a avisar (1 por día)', 2 === count( ag_alertas() ) );
$GLOBALS['wpdb'] = new CN_Fake_Wpdb(); // la base vuelve a andar
$w = $GLOBALS['wpdb'];
CN_Sync::correr();
t( 'recuperación: mp_log sync_recuperado, se desmarca, sin mail', in_array( 'sync_recuperado', $w->log_mp_tipos(), true ) && '0' === get_option( CN_Alertas::OPT_SYNC_CAIDO ) && 2 === count( ag_alertas() ) && abs( (int) get_option( CN_Alertas::OPT_SYNC_OK ) - time() ) <= 2 );
CN_Sync::correr();
t( 'una corrida buena más: no repite sync_recuperado', 1 === count( array_keys( $w->log_mp_tipos(), 'sync_recuperado', true ) ) );
ag_reset();
update_option( CN_Alertas::OPT_SYNC_VIGILADO, time() - 10 * 86400 );
update_option( CN_Alertas::OPT_SYNC_OK, 0 );
CN_Sync::correr();
t( 'nunca corrió bien pero se vigila hace días: alerta igual ("nunca")', 1 === count( ag_alertas() ) && false !== strpos( ag_alertas()[0]['cuerpo'], 'nunca' ) );
ag_reset();
update_option( CN_Alertas::OPT_SYNC_VIGILADO, time() - 10 * 86400 );
update_option( CN_Alertas::OPT_SYNC_OK, time() - 25 * 3600 );
update_option( 'cn_sync_mp_activo', '0' );
CN_Sync::correr();
t( 'sync apagado por flag: no vigila ni alerta', ! ag_alertas() );
ag_reset();
update_option( CN_Alertas::OPT_SYNC_VIGILADO, time() - 10 * 86400 );
update_option( CN_Alertas::OPT_SYNC_OK, time() - 25 * 3600 );
set_transient( 'cn_sync_mp_lock', 1 );
CN_Sync::correr();
t( 'con otra corrida en curso (lock): no chequea', ! ag_alertas() );
ag_reset();
update_option( CN_Alertas::OPT_SYNC_VIGILADO, time() - 10 * 86400 );
update_option( CN_Alertas::OPT_SYNC_OK, time() - 3600 );
update_option( CN_Alta_Mail::OPT_MODO, 'log' );
update_option( CN_Alta_Mail::OPT_DESDE, time() - 100 );
cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', function () {
	throw new RuntimeException( 'explota el alta por mail' );
} );
CN_Sync::correr();
t( 'una excepción en cualquiera de los 3 pasos = corrida NO exitosa (no renueva el heartbeat)', time() - (int) get_option( CN_Alertas::OPT_SYNC_OK ) >= 3600 - 5 );

seccion( 'token de Mercado Pago inválido (D3)' );
foreach ( array( 401, 403 ) as $code ) {
	ag_reset();
	cn_http_on( 'GET', '#/preapproval/PRE1$#', $code );
	CN_MP::obtener_preapproval( 'PRE1' );
	$a = ag_alertas();
	t( "{$code}: UNA alerta mp_token_invalido con asunto claro y el código", 1 === count( $a ) && 'mp_token_invalido' === $a[0]['motivo'] && CN_Alertas::ASUNTO_TOKEN === $a[0]['asunto'] && false !== strpos( $a[0]['cuerpo'], "HTTP {$code}" ) && false !== strpos( $a[0]['cuerpo'], 'Config' ) );
	CN_MP::obtener_preapproval( 'PRE1' );
	CN_MP::obtener_preapproval( 'PRE1' );
	t( "{$code} repetido: no repite el mail dentro de las 24 hs", 1 === count( ag_alertas() ) && (int) get_option( CN_Alertas::OPT_TOKEN_DESDE ) > 0 );
}
foreach ( array( 429, 500, 502, 404, 400 ) as $code ) {
	ag_reset();
	cn_http_on( 'GET', '#/preapproval/PRE1$#', $code );
	CN_MP::obtener_preapproval( 'PRE1' );
	t( "{$code}: NO es token inválido (sin mail, sin marca)", ! ag_alertas() && 0 === (int) get_option( CN_Alertas::OPT_TOKEN_DESDE, 0 ) );
}
ag_reset();
cn_http_on( 'GET', '#/preapproval/PRE1$#', null );
CN_MP::obtener_preapproval( 'PRE1' );
t( 'sin respuesta (red): tampoco', ! ag_alertas() );
ag_reset();
cn_http_on( 'GET', '#/preapproval/PRE1$#', 401 );
CN_MP::obtener_preapproval( 'PRE1' );
update_option( CN_Alertas::OPT_MAIL_TOKEN, time() - 25 * 3600 );
CN_MP::obtener_preapproval( 'PRE1' );
t( 'sigue en 401 pasadas 24 hs: vuelve a avisar (1 por día)', 2 === count( ag_alertas() ) );
cn_http_on( 'GET', '#/preapproval/PRE1$#', array( 'id' => 'PRE1', 'status' => 'authorized' ) );
CN_MP::obtener_preapproval( 'PRE1' );
t( 'una llamada que vuelve a andar limpia la condición (mp_token_recuperado, sin mail)', 0 === (int) get_option( CN_Alertas::OPT_TOKEN_DESDE ) && in_array( 'mp_token_recuperado', ag_tipos(), true ) && 2 === count( ag_alertas() ) );
CN_MP::obtener_preapproval( 'PRE1' );
t( 'con el token sano no escribe nada más', 1 === count( array_keys( ag_tipos(), 'mp_token_recuperado', true ) ) );
cn_http_on( 'GET', '#/preapproval/PRE1$#', 401 );
CN_MP::obtener_preapproval( 'PRE1' );
t( 'si vuelve a fallar enseguida después de recuperarse: nueva racha, pero el tope de 1 mail por 24 hs sigue valiendo (sin spam por intermitencias)', 2 === count( ag_alertas() ) && (int) get_option( CN_Alertas::OPT_TOKEN_DESDE ) > 0 );
update_option( CN_Alertas::OPT_MAIL_TOKEN, time() - 25 * 3600 );
CN_MP::obtener_preapproval( 'PRE1' );
t( '...y pasadas 24 hs del último mail, avisa de la nueva racha', 3 === count( ag_alertas() ) );
// Las tres vías: get(), get_con_reintentos() (sync/alta por mail) y obtener_preapproval_detalle() (vínculo/retorno).
ag_reset();
cn_http_on( 'GET', '#/preapproval/PRE2$#', 403 );
$pres = array( 'requests' => 10, 'espera' => 10, 'hasta' => time() + 60 );
CN_MP::obtener_preapproval_con_reintentos( 'PRE2', $pres );
t( 'get_con_reintentos (sync): 403 también alerta', 1 === count( ag_alertas() ) );
ag_reset();
cn_http_on( 'GET', '#/preapproval/PRE3$#', 401 );
CN_MP::obtener_preapproval_detalle( 'PRE3' );
t( 'obtener_preapproval_detalle: 401 también alerta', 1 === count( ag_alertas() ) );
cn_http_on( 'GET', '#/preapproval/PRE3$#', array( 'id' => 'PRE3', 'status' => 'authorized' ) );
CN_MP::obtener_preapproval_detalle( 'PRE3' );
t( '...y un 200 por esa vía también limpia', 0 === (int) get_option( CN_Alertas::OPT_TOKEN_DESDE ) );
ag_reset();
cn_http_on( 'GET', '#/preapproval/PRE1$#', 401 );
CN_MP::obtener_preapproval( 'PRE1' );
t( 'el 401 sigue dejando mp_api_error y el aviso del cartel como antes', in_array( 'mp_api_error', ag_tipos(), true ) && in_array( CN_Webhook::TIPO_AVISO, ag_tipos(), true ) );

seccion( 'pagos sin acceso: resumen diario a las 72 hs (D1/D4)' );
const AG_PRE = 'abcdef1234567890abcdef1234567890';
function ag_casos( $casos ) {
	ag_reset();
	$m = array();
	foreach ( $casos as $c ) {
		$m[ $c[0] . '|' . $c[1] ] = array( 'motivo' => $c[3], 'tipo' => $c[0], 'id' => $c[1], 'desde' => time() - $c[2] );
	}
	update_option( CN_Alertas::OPT_CASOS, $m );
	return $GLOBALS['wpdb'];
}
ag_reset();
CN_Webhook::avisar_error_n8n( 'preapproval_sin_match', array( 'preapproval_id' => AG_PRE, 'payer_email' => 'sandra@gmail.com' ) );
CN_Webhook::avisar_error_n8n( 'excepcion_trial_alta', array( 'payment_id' => '5550001', 'mensaje' => 'x' ) );
CN_Webhook::avisar_error_n8n( 'metadata_incompleta', array( 'payment_id' => '5550002' ) );
CN_Webhook::avisar_error_n8n( 'alta_mail_sin_email', array( 'preapproval_id' => AG_PRE, 'horas' => 80 ) );
$c = get_option( CN_Alertas::OPT_CASOS );
t( 'los 4 motivos se anotan como caso, sin mail; el mismo preapproval por 2 motivos es UN caso', ! ag_alertas() && ! $GLOBALS['cn_mails'] && 3 === count( $c ) && isset( $c[ 'preapproval|' . AG_PRE ], $c['pago|5550001'], $c['pago|5550002'] ) );
t( 'el caso guarda la fecha de la primera vez y no datos personales', abs( $c[ 'preapproval|' . AG_PRE ]['desde'] - time() ) <= 2 && false === strpos( json_encode( $c ), 'sandra' ) );
$d1 = $c[ 'preapproval|' . AG_PRE ]['desde'] - 100;
$c[ 'preapproval|' . AG_PRE ]['desde'] = $d1;
update_option( CN_Alertas::OPT_CASOS, $c );
CN_Webhook::avisar_error_n8n( 'preapproval_sin_match', array( 'preapproval_id' => AG_PRE ) );
t( 'repetir el aviso conserva la fecha de la primera vez', $d1 === get_option( CN_Alertas::OPT_CASOS )[ 'preapproval|' . AG_PRE ]['desde'] );
ag_reset();
CN_Webhook::avisar_error_n8n( 'metadata_incompleta', array( 'external_reference' => 'x' ) );
t( 'sin id en el detalle: queda en el cartel pero no como caso', array() === get_option( CN_Alertas::OPT_CASOS, array() ) );
$w = ag_casos( array( array( 'preapproval', AG_PRE, 71 * 3600, 'preapproval_sin_match' ), array( 'pago', '5550001', 10 * 3600, 'excepcion_trial_alta' ) ) );
$n = CN_Alertas::chequear_casos();
t( 'ningún caso con más de 72 hs: no alerta', 0 === $n && ! ag_alertas() );
$w = ag_casos( array( array( 'preapproval', AG_PRE, 73 * 3600, 'preapproval_sin_match' ), array( 'pago', '5550001', 10 * 3600, 'excepcion_trial_alta' ), array( 'pago', '5550002', 100 * 3600, 'metadata_incompleta' ) ) );
$n = CN_Alertas::chequear_casos();
$a = ag_alertas();
t( 'con 2 casos de más de 72 hs: UN mail resumen con la cantidad en el asunto', 2 === $n && 1 === count( $a ) && 'pagos_sin_acceso' === $a[0]['motivo'] && '[Club Natureza] GRAVE: 2 pagos sin acceso hace más de 72 hs' === $a[0]['asunto'] );
t( 'el cuerpo trae ids enmascarados (6 + ...) y nunca el id completo, emails ni nombres', false !== strpos( $a[0]['cuerpo'], 'abcdef...' ) && false !== strpos( $a[0]['cuerpo'], '555000...' ) && false === strpos( $a[0]['cuerpo'], AG_PRE ) && false === strpos( $a[0]['cuerpo'], '5550002' ) && false === strpos( json_encode( $a[0] ), '@' ) && false !== strpos( $a[0]['cuerpo'], 'Qué hacer' ) );
t( 'el caso de 10 hs no va en el resumen', false === strpos( $a[0]['cuerpo'], '5550001' ) && false === strpos( $a[0]['cuerpo'], '555000...·' ) );
CN_Alertas::chequear_casos();
CN_Alertas::chequear_casos();
t( 'una vez por día: no repite el mail', 1 === count( ag_alertas() ) );
update_option( CN_Alertas::OPT_MAIL_CASOS, time() - 25 * 3600 );
CN_Alertas::chequear_casos();
t( 'al día siguiente, si siguen sin resolverse, vuelve a mandar', 2 === count( ag_alertas() ) );
// Se resuelve solo: aparece una ficha con ese preapproval_id / trial_payment_id.
$w = ag_casos( array( array( 'preapproval', AG_PRE, 73 * 3600, 'preapproval_sin_match' ), array( 'pago', '5550002', 100 * 3600, 'metadata_incompleta' ) ) );
$w->on( '/SELECT preapproval_id FROM wp_cn_miembros WHERE preapproval_id IN/', array( AG_PRE ) );
$n = CN_Alertas::chequear_casos();
t( 'ya existe ficha con ese preapproval_id: el caso se resuelve solo y sale de la lista', 1 === $n && ! isset( get_option( CN_Alertas::OPT_CASOS )[ 'preapproval|' . AG_PRE ] ) && '[Club Natureza] GRAVE: 1 pagos sin acceso hace más de 72 hs' === ag_alertas()[0]['asunto'] );
$w = ag_casos( array( array( 'pago', '5550002', 100 * 3600, 'metadata_incompleta' ) ) );
$w->on( '/SELECT trial_payment_id FROM wp_cn_miembros WHERE trial_payment_id IN/', array( '5550002' ) );
$n = CN_Alertas::chequear_casos();
t( 'ya existe ficha con ese trial_payment_id: resuelto, sin mail', 0 === $n && ! ag_alertas() && array() === get_option( CN_Alertas::OPT_CASOS ) );
$w = ag_casos( array( array( 'pago', '5550002', 100 * 3600, 'metadata_incompleta' ) ) );
$w->on( '/SELECT trial_payment_id FROM/', function () use ( $w ) {
	$w->last_error = 'boom';
	return array();
} );
CN_Alertas::chequear_casos();
t( 'error de base al depurar: no se da por resuelto nada (el caso sigue)', 1 === count( get_option( CN_Alertas::OPT_CASOS ) ) );
t( 'la lista de casos se acota a 500', ( function () {
	ag_reset();
	for ( $i = 0; $i < 510; $i++ ) {
		CN_Webhook::avisar_error_n8n( 'metadata_incompleta', array( 'payment_id' => (string) ( 7000000 + $i ) ) );
	}
	return 500 === count( get_option( CN_Alertas::OPT_CASOS ) );
} )() );
t( 'el chequeo diario tiene su cron en el archivo principal y nunca lanza', false !== strpos( file_get_contents( dirname( __DIR__ ) . '/club-natureza-miembros.php' ), "add_action( 'cn_cron_alertas_graves', array( 'CN_Alertas', 'chequeo_diario' ) )" ) && ( function () {
	ag_reset();
	$GLOBALS['wpdb']->on( '/.*/', function () {
		throw new RuntimeException( 'explota' );
	} );
	update_option( CN_Alertas::OPT_CASOS, array( 'pago|1' => array( 'motivo' => 'x', 'tipo' => 'pago', 'id' => '1', 'desde' => 1 ) ) );
	CN_Alertas::chequeo_diario();
	return true;
} )() );

seccion( 'ningún otro motivo manda mail (D5)' );
ag_reset();
foreach ( array( 'pago_no_encontrado', 'crear_preference_trial_fallo', 'aviso_dia5_sin_celular', 'capi_purchase_fallo', 'capi_subscribe_fallo', 'trial_pago_directo', 'sub_conflicto', 'sub_error', 'sub_baja_sin_periodo', 'retorno_ambiguo', 'mp_api_error', 'sync_item_fallido_reiterado', 'sub_sin_vincular', 'alta_mail_error', 'alta_mail_monto_no_valido', 'alta_mail_fallo_envio', 'reactivar_manual_error' ) as $m ) {
	CN_Webhook::avisar_error_n8n( $m, array( 'x' => 1, 'preapproval_id' => AG_PRE, 'payment_id' => '1' ) );
	CN_Webhook::sub_avisar_una_vez_por_hora( $m, 'ref', array( 'x' => 1 ) );
}
t( '17 motivos técnicos: cero POST a n8n, cero mails, cero casos; todo en el cartel', ! ag_alertas() && ! $GLOBALS['cn_mails'] && array() === get_option( CN_Alertas::OPT_CASOS, array() ) && 34 === count( array_keys( ag_tipos(), CN_Webhook::TIPO_AVISO, true ) ) );
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-alertas.php' );
t( 'CN_Alertas no llama a Mercado Pago ni escribe en miembros (solo SELECT)', false === strpos( $src, 'wp_remote_get' ) && false === strpos( $src, 'api.mercadopago' ) && ! preg_match( '/->insert\(|->update\(|->delete\(|->query\(/', $src ) );
t( 'los 3 asuntos son distintos y empiezan con [Club Natureza] GRAVE', 3 === count( array_unique( array( CN_Alertas::ASUNTO_SYNC, CN_Alertas::ASUNTO_TOKEN, sprintf( CN_Alertas::ASUNTO_CASOS, 2 ) ) ) ) && 0 === strpos( CN_Alertas::ASUNTO_SYNC, '[Club Natureza] GRAVE' ) && 0 === strpos( CN_Alertas::ASUNTO_TOKEN, '[Club Natureza] GRAVE' ) && 0 === strpos( CN_Alertas::ASUNTO_CASOS, '[Club Natureza] GRAVE' ) );
t( 'cada alerta deja rastro alerta_grave_enviada en mp_log', ( function () {
	ag_reset();
	cn_http_on( 'GET', '#/preapproval/PRE1$#', 401 );
	CN_MP::obtener_preapproval( 'PRE1' );
	return in_array( 'alerta_grave_enviada', ag_tipos(), true );
} )() );
