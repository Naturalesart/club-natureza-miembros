<?php
// Alta más rápida: sync cada cn_sync_intervalo_min minutos (default 30) y mail "completá tus datos" a los cn_mail_recuperacion_min
// minutos (default 45). Sin las opciones, todo exactamente como antes. Con 5/10: lock, ciclo completo cada 30 min, corte ante 429,
// máx. 1 mail por suscripción aunque el sync corra 6 veces por hora, y cero WhatsApp en lo que sale.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
CN_Sync::$pausa_us = 0;
function amr_id( $n ) {
	return md5( 'rapida' . $n );
}
function amr_item( $n, $hace, $monto = 15500 ) {
	return array( 'id' => amr_id( $n ), 'status' => 'authorized', 'date_created' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - $hace ), 'auto_recurring' => array( 'transaction_amount' => $monto ) );
}
/** Alta por mail en 'on' con una suscripción pagada hace $hace segundos y un cobro aprobado con email. Sin filas vinculadas en el lote. */
function amr_preparar( $hace, $modo = 'on' ) {
	cn_test_reset();
	global $wpdb;
	$_GET = $_POST = array();
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	update_option( CN_Alta_Mail::OPT_MODO, $modo );
	update_option( CN_Alta_Mail::OPT_DESDE, time() - 172800 );
	update_option( CN_Alta_Mail::OPT_URL, 'https://naturalesart.com/suscripcion-lista/' );
	update_option( 'cn_retorno_activo', '1' );
	$items = array( amr_item( 1, $hace ) );
	cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => $items, 'paging' => array( 'total' => 1 ) ) );
	cn_http_on( 'GET', '#/authorized_payments/search\?preapproval_id=' . amr_id( 1 ) . '#', array( 'results' => array( array( 'id' => 1, 'payment' => array( 'id' => 7000001, 'status' => 'approved' ) ) ) ) );
	cn_http_on( 'GET', '#/v1/payments/7000001$#', array( 'id' => 7000001, 'status' => 'approved', 'payer' => array( 'id' => 99, 'email' => 'clienta@gmail.com' ) ) );
	cn_http_on( 'GET', '#/v1/payments/search#', array( 'results' => array(), 'paging' => array( 'total' => 0 ) ) );
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	$wpdb->on( '/SELECT preapproval_id FROM wp_cn_miembros WHERE preapproval_id IN/', array() );
	$wpdb->on( '/SELECT id, preapproval_id FROM wp_cn_miembros\s+WHERE preapproval_id IS NOT NULL/', array() );
	$wpdb->on( '/SELECT id, nombre_apellido, estado, email, celular_hint, preapproval_id, fecha_fin_trial FROM/', array() );
	return $wpdb;
}
/** Lote de 2 suscripciones vinculadas en MP (para probar el corte ante 429). */
function amr_preparar_lote() {
	cn_test_reset();
	global $wpdb;
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	$rows = array( cn_fila( array( 'id' => 1, 'preapproval_id' => 'PRE_RAPIDA00000000001' ) ), cn_fila( array( 'id' => 2, 'preapproval_id' => 'PRE_RAPIDA00000000002' ) ) );
	$wpdb->on( '/SELECT id, preapproval_id FROM wp_cn_miembros\s+WHERE preapproval_id IS NOT NULL/', $rows );
	foreach ( $rows as $f ) {
		$wpdb->on( "/SELECT id, estado, plan, acceso_hasta, baja_en FROM wp_cn_miembros WHERE preapproval_id = '" . $f->preapproval_id . "'/", array( cn_fila( array( 'id' => $f->id, 'estado' => 'activo', 'plan' => 'basico', 'acceso_hasta' => '2026-10-20 12:00:00', 'baja_en' => null ) ) ) );
	}
	cn_http_on( 'GET', '#/preapproval/PRE_RAPIDA00000000001$#', array( '__status' => 429, '__headers' => array(), '__body' => array() ) );
	cn_http_on( 'GET', '#/preapproval/PRE_RAPIDA00000000002$#', array( 'id' => 'PRE_RAPIDA00000000002', 'status' => 'authorized', 'next_payment_date' => '2026-12-01T12:00:00.000-03:00', 'auto_recurring' => array( 'transaction_amount' => 15500 ) ) );
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	return $wpdb;
}
function amr_esperas_capturadas() {
	$esperas = array();
	CN_MP::$dormir = function ( $s ) use ( &$esperas ) {
		$esperas[] = $s;
	};
	return function () use ( &$esperas ) {
		return $esperas;
	};
}
function amr_sin_whatsapp( $texto ) {
	return false === stripos( (string) $texto, 'whatsapp' ) && false === stripos( (string) $texto, 'wa.me' );
}

seccion( 'sin las opciones: los intervalos de siempre, exactos (30 y 45)' );
cn_test_reset();
t( 'intervalo por defecto = 30 min', 30 === CN_Sync::intervalo_min() && 1800 === CN_Sync::intervalo_seg() && 'cn_30min' === CN_Sync::nombre_intervalo() );
t( 'sin opción no hay "frecuencia alta": ciclo completo en TODA corrida', ! CN_Sync::frecuencia_alta() && CN_Sync::toca_ciclo_completo() && ! CN_Sync::corta_ante_429() );
t( 'lock de 25 min, como siempre', 1500 === CN_Sync::lock_seg() );
$s = CN_Sync::registrar_intervalo( array() );
t( 'cron_schedules: solo cn_30min = 1800 (ningún intervalo extra)', array( 'cn_30min' ) === array_keys( $s ) && 1800 === $s['cn_30min']['interval'] );
t( 'espera del mail = 45 min (2700 s)', 2700 === CN_Alta_Mail::espera_seg() && 2700 === CN_Alta_Mail::EDAD_MIN_SEG );
foreach ( array( '', ' ', 'abc', null, false, array( 5 ) ) as $raro ) {
	update_option( CN_Sync::OPT_INTERVALO, $raro );
	update_option( CN_Alta_Mail::OPT_ESPERA_MIN, $raro );
	t( 'valor raro en las opciones (' . json_encode( $raro ) . ') = los defaults de siempre', 30 === CN_Sync::intervalo_min() && 2700 === CN_Alta_Mail::espera_seg() );
}
cn_test_reset();
t( 'programar() sin evento: cn_30min dentro de 5 min (lo de siempre)', true === CN_Sync::programar() && 'cn_30min' === $GLOBALS['cn_cron']['cn_cron_sync_mp']['schedule'] && abs( $GLOBALS['cn_cron']['cn_cron_sync_mp']['timestamp'] - ( time() + 300 ) ) <= 2 );
$GLOBALS['cn_cron_log'] = array();
t( 'programar() con el evento ya en cn_30min: no toca el cron', false === CN_Sync::programar() && array() === $GLOBALS['cn_cron_log'] );
$main = file_get_contents( dirname( __DIR__ ) . '/club-natureza-miembros.php' );
t( 'el init llama a CN_Sync::programar con guard (deploy archivo por archivo) y conserva el fallback cn_30min', false !== strpos( $main, "method_exists( 'CN_Sync', 'programar' )" ) && false !== strpos( $main, "'cn_30min', 'cn_cron_sync_mp'" ) );

seccion( 'con las opciones en 5 y 10: nueva frecuencia y nueva espera' );
cn_test_reset();
update_option( CN_Sync::OPT_INTERVALO, '5' );
update_option( CN_Alta_Mail::OPT_ESPERA_MIN, '10' );
t( 'intervalo 5 min = 300 s, intervalo cn_sync_5min, frecuencia alta', 5 === CN_Sync::intervalo_min() && 300 === CN_Sync::intervalo_seg() && 'cn_sync_5min' === CN_Sync::nombre_intervalo() && CN_Sync::frecuencia_alta() );
$s = CN_Sync::registrar_intervalo( array( 'hourly' => array( 'interval' => 3600, 'display' => 'x' ) ) );
t( 'cron_schedules: se agrega cn_sync_5min = 300 y queda cn_30min', 300 === $s['cn_sync_5min']['interval'] && 1800 === $s['cn_30min']['interval'] && isset( $s['hourly'] ) );
t( 'lock = 5 min (no frena 4 corridas si una se corta de golpe)', 300 === CN_Sync::lock_seg() );
t( 'espera del mail = 10 min (600 s)', 600 === CN_Alta_Mail::espera_seg() );
update_option( CN_Sync::OPT_INTERVALO, 10 );
t( 'intervalo 10 (entero) = 600 s y lock de 10 min', 600 === CN_Sync::intervalo_seg() && 600 === CN_Sync::lock_seg() && 'cn_sync_10min' === CN_Sync::nombre_intervalo() );
update_option( CN_Sync::OPT_INTERVALO, '1' );
update_option( CN_Alta_Mail::OPT_ESPERA_MIN, '0' );
t( 'piso: nunca más seguido que 5 min ni menos de 5 min de espera', 5 === CN_Sync::intervalo_min() && 300 === CN_Alta_Mail::espera_seg() );
update_option( CN_Sync::OPT_INTERVALO, '99999' );
update_option( CN_Alta_Mail::OPT_ESPERA_MIN, '99999' );
t( 'techo: 1440 min', 1440 === CN_Sync::intervalo_min() && 86400 === CN_Alta_Mail::espera_seg() );
// Reprogramación del cron al cambiar la opción.
cn_test_reset();
wp_schedule_event( time() + 100, 'cn_30min', 'cn_cron_sync_mp' );
update_option( CN_Sync::OPT_INTERVALO, '5' );
$GLOBALS['cn_cron_log'] = array();
t( 'al pasar a 5: borra el evento de 30 y programa cn_sync_5min', true === CN_Sync::programar() && array( 'clear', 'schedule' ) === array_column( $GLOBALS['cn_cron_log'], 0 ) && 'cn_sync_5min' === $GLOBALS['cn_cron']['cn_cron_sync_mp']['schedule'] );
$GLOBALS['cn_cron_log'] = array();
t( 'segunda carga con la misma opción: no toca el cron', false === CN_Sync::programar() && array() === $GLOBALS['cn_cron_log'] );
delete_option( CN_Sync::OPT_INTERVALO );
t( 'marcha atrás: al borrar la opción vuelve a cn_30min', true === CN_Sync::programar() && 'cn_30min' === $GLOBALS['cn_cron']['cn_cron_sync_mp']['schedule'] );

seccion( 'el mail NO sale antes del minuto configurado (y sí después)' );
foreach ( array( array( '', 2699, false ), array( '', 2701, true ), array( '10', 599, false ), array( '10', 601, true ), array( '10', 2000, true ) ) as $caso ) {
	$w = amr_preparar( $caso[1] );
	update_option( CN_Alta_Mail::OPT_ESPERA_MIN, $caso[0] );
	CN_Alta_Mail::correr();
	$salio = 1 === count( $GLOBALS['cn_mails'] );
	t( 'espera ' . ( '' === $caso[0] ? '45 (default)' : $caso[0] ) . ' min, pago hace ' . $caso[1] . ' s: ' . ( $caso[2] ? 'sale' : 'NO sale (ni consulta el cobro)' ), $caso[2] === $salio && ( $caso[2] || ! cn_http_calls( '#authorized_payments/search#' ) ) );
}
$w = amr_preparar( 599, 'log' );
update_option( CN_Alta_Mail::OPT_ESPERA_MIN, '10' );
CN_Alta_Mail::correr();
t( 'modo log: tampoco simula antes del minuto configurado', ! in_array( 'alta_mail_simulado', $w->log_mp_tipos(), true ) );

seccion( 'lock: dos corridas simultáneas = una sola ejecuta' );
$w = amr_preparar( 2000 );
update_option( CN_Sync::OPT_INTERVALO, '5' );
update_option( CN_Alta_Mail::OPT_ESPERA_MIN, '10' );
update_option( CN_Sync::OPT_CICLO_TS, time() - 60 ); // tick intermedio: la única lista a MP la hace el alta por mail.
$entradas = 0;
cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', function () use ( &$entradas ) {
	$entradas++;
	if ( 1 === $entradas ) {
		t( 'durante la corrida el lock está puesto', 1 === get_transient( 'cn_sync_mp_lock' ) );
		CN_Sync::correr(); // "segunda corrida" que arranca mientras la primera sigue viva: tiene que salir sin hacer nada.
	}
	return array( 'results' => array( amr_item( 1, 2000 ) ), 'paging' => array( 'total' => 1 ) );
} );
CN_Sync::correr();
t( 'la segunda corrida no consultó nada: una sola lista a MP y un solo mail', 1 === $entradas && 1 === count( cn_http_calls( '#/preapproval/search\?status=authorized#' ) ) && 1 === count( $GLOBALS['cn_mails'] ) );
t( 'al terminar, el lock queda libre', false === get_transient( 'cn_sync_mp_lock' ) );
cn_test_reset();
update_option( CN_Sync::OPT_INTERVALO, '5' );
update_option( 'cn_mp_access_token', 'APP_USR-test' );
set_transient( 'cn_sync_mp_lock', 1 );
CN_Sync::correr();
t( 'con el lock puesto por otra corrida: ni una consulta', ! $GLOBALS['cn_http_log'] );

seccion( 'ticks de 5 min: solo alta por mail; el barrido completo (lote, sin vincular, reemplazo) una vez cada 30 min' );
$w = amr_preparar( 2000 );
update_option( CN_Sync::OPT_INTERVALO, '5' );
update_option( CN_Alta_Mail::OPT_ESPERA_MIN, '10' );
update_option( CN_Sync::OPT_CICLO_TS, time() - 60 ); // el ciclo completo corrió hace 1 min
$rows = array( cn_fila( array( 'id' => 1, 'preapproval_id' => 'PRE_RAPIDA00000000001' ) ) );
$w->on( '/SELECT id, preapproval_id FROM wp_cn_miembros\s+WHERE preapproval_id IS NOT NULL/', $rows );
$w->on( "/SELECT id, estado, plan, acceso_hasta, baja_en FROM wp_cn_miembros WHERE preapproval_id = 'PRE_RAPIDA00000000001'/", array( cn_fila( array( 'id' => 1, 'estado' => 'activo', 'plan' => 'basico', 'acceso_hasta' => '2026-10-20 12:00:00', 'baja_en' => null ) ) ) );
cn_http_on( 'GET', '#/preapproval/PRE_RAPIDA00000000001$#', array( 'id' => 'PRE_RAPIDA00000000001', 'status' => 'authorized', 'next_payment_date' => '2026-12-01T12:00:00.000-03:00', 'auto_recurring' => array( 'transaction_amount' => 15500 ) ) );
t( 'hace 1 min hubo ciclo completo: este tick NO lo repite', ! CN_Sync::toca_ciclo_completo() );
CN_Sync::correr();
t( 'tick intermedio: no consulta las vinculadas (cero GET /preapproval/{id}) ni escribe en miembros', ! cn_http_calls( '#/preapproval/PRE_#' ) && ! $w->escrituras( 'cn_miembros' ) );
t( 'tick intermedio: sí corre el alta por mail (el mail sale)', 1 === count( $GLOBALS['cn_mails'] ) && 'clienta@gmail.com' === $GLOBALS['cn_mails'][0]['to'] );
t( 'tick intermedio: no hace latir el heartbeat ni mueve el timestamp del ciclo', 0 === (int) get_option( CN_Alertas::OPT_SYNC_OK, 0 ) && abs( (int) get_option( CN_Sync::OPT_CICLO_TS ) - ( time() - 60 ) ) <= 2 );
update_option( CN_Sync::OPT_CICLO_TS, time() - 1700 ); // ~28 min: con medio intervalo de tolerancia, toca
t( 'pasados ~30 min (tolerancia de medio intervalo) toca el ciclo completo', CN_Sync::toca_ciclo_completo() );
update_option( CN_Alertas::OPT_SYNC_VIGILADO, time() - 100 );
CN_Sync::correr();
t( 'ciclo completo: consulta las vinculadas, aplica la renovación y marca el ciclo', 1 === count( cn_http_calls( '#/preapproval/PRE_RAPIDA00000000001$#' ) ) && 1 === count( $w->escrituras( 'cn_miembros' ) ) && abs( (int) get_option( CN_Sync::OPT_CICLO_TS ) - time() ) <= 2 );
t( 'ciclo completo sin errores: late el heartbeat', abs( (int) get_option( CN_Alertas::OPT_SYNC_OK, 0 ) - time() ) <= 2 );
t( 'sigue siendo máx. 1 mail por suscripción (ya se mandó en el tick anterior)', 1 === count( $GLOBALS['cn_mails'] ) );
update_option( CN_Sync::OPT_CICLO_TS, time() - 1000 );
t( 'con 10 min la tolerancia es de 5: a los ~17 min no toca; a los 25 sí', ( function () {
	update_option( CN_Sync::OPT_INTERVALO, '10' );
	$no = ! CN_Sync::toca_ciclo_completo();
	update_option( CN_Sync::OPT_CICLO_TS, time() - 1500 );
	return $no && CN_Sync::toca_ciclo_completo();
} )() );

seccion( '6 corridas por hora: sigue saliendo máximo 1 mail por suscripción' );
$w = amr_preparar( 2000 );
update_option( CN_Sync::OPT_INTERVALO, '5' );
update_option( CN_Alta_Mail::OPT_ESPERA_MIN, '10' );
for ( $i = 0; $i < 6; $i++ ) {
	CN_Sync::correr();
}
t( '6 corridas seguidas con la misma suscripción: UN solo mail y una sola fila alta_mail_enviado', 1 === count( $GLOBALS['cn_mails'] ) && 1 === count( array_keys( $w->log_mp_tipos(), 'alta_mail_enviado', true ) ) );
t( 'intentos de envío: 1 (no se gastan los 3 por correr seguido)', 1 === (int) ( get_option( CN_Alta_Mail::OPT_INTENTOS )[ amr_id( 1 ) ] ?? 0 ) );
t( 'cero escrituras en miembros y solo GET a Mercado Pago', ! $w->escrituras( 'cn_miembros' ) && array( 'GET' ) === array_values( array_unique( array_column( array_filter( $GLOBALS['cn_http_log'], function ( $c ) {
	return false !== strpos( $c['url'], 'mercadopago' );
} ), 'metodo' ) ) ) );
t( 'token de 14 días y máx. 3 intentos: sin cambios', 14 === CN_Alta_Mail::TOKEN_DIAS && 3 === CN_Alta_Mail::MAX_ENVIOS );

seccion( '429 simulado: no reintenta más que hoy, y con el cron rápido la corrida termina' );
$esperas = amr_esperas_capturadas();
$w = amr_preparar_lote(); // sin opción: 30 min, como siempre
$r = CN_Sync::sincronizar_lote();
t( 'sin opción (30 min): 429 persistente = 4 pedidos (2, 4, 8 s) y SIGUE con la fila que sigue, como hoy', array( 2, 4, 8 ) === $esperas() && 4 === count( cn_http_calls( '#/preapproval/PRE_RAPIDA00000000001$#' ) ) && 1 === count( cn_http_calls( '#/preapproval/PRE_RAPIDA00000000002$#' ) ) && 1 === $r['fallos'] && 1 === $r['procesadas'] && ! $r['abortada'] && ! $r['corte_429'] );
$esperas = amr_esperas_capturadas();
$w = amr_preparar_lote();
update_option( CN_Sync::OPT_INTERVALO, '5' );
$r = CN_Sync::sincronizar_lote();
t( 'con 5 min: los mismos 4 pedidos (2, 4, 8 s), ni uno más', array( 2, 4, 8 ) === $esperas() && 4 === count( cn_http_calls( '#/preapproval/PRE_RAPIDA00000000001$#' ) ) );
t( 'con 5 min: la corrida termina ahí (la 2ª fila no se pide) y las dos quedan en cola para la próxima', ! cn_http_calls( '#/preapproval/PRE_RAPIDA00000000002$#' ) && $r['abortada'] && $r['corte_429'] && 2 === $r['fallos'] && array( 1, 2 ) === array_keys( get_option( CN_Sync::OPT_FALLIDOS ) ) && ! $w->escrituras( 'cn_miembros' ) );
t( 'con 5 min: el corte por 429 no duplica el aviso sync_abortada (ya está el mp_api_error)', in_array( 'mp_api_error', $w->log_mp_tipos(), true ) && ! in_array( 'sub_error', $w->log_mp_tipos(), true ) );
// Alta por mail: un 429 persistente al consultar el cobro termina la corrida (no se consulta la siguiente candidata).
$w = amr_preparar( 2000 );
update_option( CN_Sync::OPT_INTERVALO, '5' );
update_option( CN_Alta_Mail::OPT_ESPERA_MIN, '10' );
cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => array( amr_item( 1, 2000 ), amr_item( 2, 1900 ) ), 'paging' => array( 'total' => 2 ) ) );
cn_http_on( 'GET', '#/authorized_payments/search\?preapproval_id=' . amr_id( 1 ) . '#', array( '__status' => 429, '__headers' => array( 'retry-after' => '1' ), '__body' => array() ) );
cn_http_on( 'GET', '#/authorized_payments/search\?preapproval_id=' . amr_id( 2 ) . '#', array( 'results' => array( array( 'id' => 1, 'payment' => array( 'id' => 7000001, 'status' => 'approved' ) ) ) ) );
$r = CN_Alta_Mail::correr();
t( 'alta por mail: 429 persistente en el cobro = 4 pedidos (como hoy) y la corrida termina sin consultar la otra candidata ni mandar nada', 4 === count( cn_http_calls( '#preapproval_id=' . amr_id( 1 ) . '#' ) ) && ! cn_http_calls( '#preapproval_id=' . amr_id( 2 ) . '#' ) && 'corte_429' === $r['motivo'] && ! $GLOBALS['cn_mails'] );
cn_http_on( 'GET', '#/authorized_payments/search\?preapproval_id=' . amr_id( 1 ) . '#', array( 'results' => array( array( 'id' => 1, 'payment' => array( 'id' => 7000001, 'status' => 'approved' ) ) ) ) );
CN_Alta_Mail::correr();
t( 'en la corrida siguiente (MP ya responde) se manda a las dos candidatas, una vez cada una', 2 === count( $GLOBALS['cn_mails'] ) && isset( get_option( CN_Alta_Mail::OPT_ENVIADOS )[ amr_id( 1 ) ] ) && isset( get_option( CN_Alta_Mail::OPT_ENVIADOS )[ amr_id( 2 ) ] ) );
CN_Alta_Mail::correr();
t( 'y otra corrida más no repite ninguno', 2 === count( $GLOBALS['cn_mails'] ) );

seccion( 'ninguna salida nueva contiene "whatsapp" ni "wa.me"' );
$w = amr_preparar( 2000 );
update_option( CN_Sync::OPT_INTERVALO, '5' );
update_option( CN_Alta_Mail::OPT_ESPERA_MIN, '10' );
CN_Sync::correr();
$m = $GLOBALS['cn_mails'][0];
t( 'el mail "completá tus datos": sin WhatsApp; contacto = responder el mail o hola@naturalesart.com', amr_sin_whatsapp( $m['cuerpo'] ) && amr_sin_whatsapp( $m['asunto'] ) && false !== strpos( $m['cuerpo'], 'hola@naturalesart.com' ) && false !== strpos( $m['cuerpo'], 'respondé este mail' ) );
$todo_log = '';
foreach ( $w->log as $l ) {
	$todo_log .= json_encode( $l );
}
t( 'nada de lo que fue a mp_log en la corrida menciona WhatsApp', amr_sin_whatsapp( $todo_log ) );
t( 'el mail no trae el email completo en el registro (enmascarado)', false === strpos( $todo_log, 'clienta@gmail.com' ) );
$fuente = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-alta-mail.php' );
t( 'class-cn-alta-mail.php ya no menciona WhatsApp ni wa.me (ni como fallback)', amr_sin_whatsapp( $fuente ) );
$sync_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-sync.php' );
t( 'class-cn-sync.php tampoco', amr_sin_whatsapp( $sync_src ) );
t( 'cero UPDATE/DELETE en wp_cn_miembros en todo este archivo de tests (solo lectura)', ( function () use ( $w ) {
	foreach ( $w->log as $l ) {
		if ( in_array( $l[0], array( 'update', 'delete' ), true ) && false !== strpos( $l[1], 'cn_miembros' ) ) {
			return false;
		}
	}
	return true;
} )() );
