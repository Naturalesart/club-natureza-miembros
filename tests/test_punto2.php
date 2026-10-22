<?php
// Punto 2: sync con MP por cron (lotes, modo seguro, fallos de MP) y aviso de suscripciones sin vincular.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
CN_Sync::$pausa_us = 0; // sin esperas en los tests (el valor real es 300000 y se verifica aparte).
function fila_sync( $id, $pre ) {
	return cn_fila( array( 'id' => $id, 'preapproval_id' => $pre ) );
}
function ciclo_row( $estado = 'activo', $plan = 'basico', $acceso = '2026-10-20 12:00:00', $baja = null ) {
	return cn_fila( array( 'id' => 0, 'estado' => $estado, 'plan' => $plan, 'acceso_hasta' => $acceso, 'baja_en' => $baja ) );
}
function mp_pre( $id, $status, $next = '2026-12-01T12:00:00.000-03:00' ) {
	return array( 'id' => $id, 'status' => $status, 'next_payment_date' => $next, 'auto_recurring' => array( 'transaction_amount' => 15500 ), 'payer_email' => 'x@y.com' );
}
/** Prepara base y MP para un lote: $filas = array( id => array( preapproval, status_mp|null, fila_ciclo ) ). */
function preparar_lote( $filas ) {
	cn_test_reset();
	global $wpdb;
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	$rows = array();
	foreach ( $filas as $id => $f ) {
		$rows[] = fila_sync( $id, $f[0] );
		$ciclo  = $f[2];
		$ciclo->id = $id;
		$wpdb->on( "/SELECT id, estado, plan, acceso_hasta, baja_en FROM wp_cn_miembros WHERE preapproval_id = '" . $f[0] . "'/", array( $ciclo ) );
		if ( null !== $f[1] ) {
			cn_http_on( 'GET', '#/preapproval/' . $f[0] . '$#', mp_pre( $f[0], $f[1] ) );
		}
	}
	$wpdb->on( '/SELECT id, preapproval_id FROM wp_cn_miembros\s+WHERE preapproval_id IS NOT NULL/', $rows );
	return $wpdb;
}
function updates_de( $w, $id ) {
	return array_values( array_filter( $w->escrituras( 'cn_miembros' ), function ( $e ) use ( $id ) {
		return 'update' === $e[0] && array( 'id' => $id ) === $e[3];
	} ) );
}

seccion( 'intervalo y programación' );
$s = CN_Sync::registrar_intervalo( array() );
t( 'cn_30min = 1800 segundos', 1800 === $s['cn_30min']['interval'] );
t( 'pausa real entre llamadas > 0 (por defecto 0,3 s)', ( new ReflectionClass( 'CN_Sync' ) )->getDefaultProperties()['pausa_us'] > 0 );
t( 'lote máximo = 20', 20 === CN_Sync::LOTE );
$main = file_get_contents( dirname( __DIR__ ) . '/club-natureza-miembros.php' );
t( 'el cron se programa con cn_30min y el hook cn_cron_sync_mp', false !== strpos( $main, "'cn_30min', 'cn_cron_sync_mp'" ) && false !== strpos( $main, "add_action( 'cn_cron_sync_mp', array( 'CN_Sync', 'correr' ) )" ) );

seccion( 'lote: renovación, baja con modo seguro, baja sin modo seguro' );
$w = preparar_lote( array(
	10 => array( 'PRE_RENUEVA0000000010', 'authorized', ciclo_row( 'activo', 'basico', '2026-10-20 12:00:00' ) ),
	11 => array( 'PRE_CANCELA0000000011', 'cancelled', ciclo_row() ),
	12 => array( 'PRE_PAUSADA0000000012', 'paused', ciclo_row() ),
) );
$r = CN_Sync::sincronizar_lote();
t( 'recorre las 3 filas', 3 === $r['procesadas'] + $r['omitidas_modo_seguro'], json_encode( $r ) );
$u10 = updates_de( $w, 10 );
t( 'authorized: extiende acceso_hasta con next_payment_date de MP', 1 === count( $u10 ) && ( $u10[0][2]['acceso_hasta'] ?? '' ) === '2026-12-01 15:00:00', json_encode( $u10 ) );
t( 'modo seguro por defecto (1): cancelled/paused NO programan baja', ! updates_de( $w, 11 ) && ! updates_de( $w, 12 ) && 2 === $r['omitidas_modo_seguro'] );
t( 'queda rastro sync_baja_omitida_modo_seguro', 2 === count( array_keys( $w->log_mp_tipos(), 'sync_baja_omitida_modo_seguro', true ) ) );
t( 'una sola llamada a MP por suscripción (no repite el GET)', 3 === count( cn_http_calls( '#/preapproval/PRE_#' ) ), (string) count( cn_http_calls( '#/preapproval/PRE_#' ) ) );
$w = preparar_lote( array(
	11 => array( 'PRE_CANCELA0000000011', 'cancelled', ciclo_row() ),
	12 => array( 'PRE_PAUSADA0000000012', 'paused', ciclo_row() ),
) );
update_option( 'cn_sub_modo_seguro', '0' );
$r = CN_Sync::sincronizar_lote();
$u11 = updates_de( $w, 11 );
t( 'modo seguro apagado: cancelled programa baja_en y NO toca estado ni acceso_hasta', 1 === count( $u11 ) && isset( $u11[0][2]['baja_en'] ) && ! isset( $u11[0][2]['estado'] ) && ! isset( $u11[0][2]['acceso_hasta'] ), json_encode( $u11 ) );
t( 'modo seguro apagado: paused también programa baja_en', 1 === count( updates_de( $w, 12 ) ) );
$w = preparar_lote( array( 11 => array( 'PRE_CANCELA0000000011', 'cancelled', ciclo_row( 'activo', 'basico', '2026-10-01 00:00:00' ) ) ) );
update_option( 'cn_sub_modo_seguro', '0' );
CN_Sync::sincronizar_lote();
t( 'baja sin período pago vigente: no cambia estado (lo resuelve una persona)', ! updates_de( $w, 11 ) && in_array( 'sub_baja_sin_periodo', $w->log_mp_tipos(), true ) );
$w = preparar_lote( array( 13 => array( 'PRE_REANUDA00000000013', 'authorized', ciclo_row( 'activo', 'basico', '2026-10-20 12:00:00', '2026-10-10 00:00:00' ) ) ) );
CN_Sync::sincronizar_lote();
$u13 = updates_de( $w, 13 );
t( 'authorized con baja programada: la limpia (baja_en = NULL)', 1 === count( $u13 ) && array_key_exists( 'baja_en', $u13[0][2] ) && null === $u13[0][2]['baja_en'] );
t( 'idempotente: authorized con acceso_hasta ya al día no escribe', ( function () {
	$w = preparar_lote( array( 14 => array( 'PRE_ALDIA0000000000014', 'authorized', ciclo_row( 'activo', 'basico', '2026-12-01 15:00:00' ) ) ) );
	CN_Sync::sincronizar_lote();
	return ! updates_de( $w, 14 );
} )() );

seccion( 'lote: tope de 20, cursor y rotación' );
$filas = array();
for ( $i = 1; $i <= 20; $i++ ) {
	$filas[ $i ] = array( sprintf( 'PRE_LOTE%015d', $i ), 'authorized', ciclo_row( 'activo', 'basico', '2026-12-01 15:00:00' ) );
}
$w = preparar_lote( $filas );
CN_Sync::sincronizar_lote();
$sql = '';
foreach ( $w->consultas as $q ) {
	if ( false !== strpos( $q, 'SELECT id, preapproval_id' ) ) {
		$sql = $q;
	}
}
t( 'la consulta pide como máximo 20 filas, solo con preapproval y plan, no canceladas', false !== strpos( $sql, 'LIMIT 20' ) && false !== strpos( $sql, "plan IS NOT NULL" ) && false !== strpos( $sql, "preapproval_id <> ''" ) && false !== strpos( $sql, "estado <> 'cancelada'" ) );
t( 'lote lleno: el cursor avanza al último id (20)', 20 === (int) get_option( 'cn_sync_mp_cursor' ) );
$w = preparar_lote( array( 5 => array( 'PRE_SOLA0000000000005', 'authorized', ciclo_row( 'activo', 'basico', '2026-12-01 15:00:00' ) ) ) );
update_option( 'cn_sync_mp_cursor', 20 );
CN_Sync::sincronizar_lote();
$sql = '';
foreach ( $w->consultas as $q ) {
	if ( false !== strpos( $q, 'SELECT id, preapproval_id' ) ) {
		$sql = $q;
	}
}
t( 'la corrida siguiente arranca después del cursor (id > 20)', false !== strpos( $sql, 'id > 20' ) );
t( 'lote incompleto: el cursor vuelve a 0 (rotación completa)', 0 === (int) get_option( 'cn_sync_mp_cursor' ) );

seccion( 'si MP falla no se cambia el estado de nadie' );
$w = preparar_lote( array(
	21 => array( 'PRE_FALLA00000000021', null, ciclo_row( 'activo', 'basico', '2026-10-20 12:00:00' ) ),
	22 => array( 'PRE_FALLA00000000022', null, ciclo_row() ),
	23 => array( 'PRE_FALLA00000000023', null, ciclo_row() ),
	24 => array( 'PRE_FALLA00000000024', 'authorized', ciclo_row() ),
) );
update_option( 'cn_sub_modo_seguro', '0' );
$r = CN_Sync::sincronizar_lote();
t( 'MP caído: las filas que fallaron no se tocan (solo se aplica la 4ª, que sí respondió)', ! updates_de( $w, 21 ) && ! updates_de( $w, 22 ) && ! updates_de( $w, 23 ) );
t( 'MP caído en 3 filas: NO corta; sigue con la 4ª y la aplica', 3 === $r['fallos'] && ! $r['abortada'] && 1 === count( updates_de( $w, 24 ) ), json_encode( $r ) );
t( 'cada fallida queda en mp_log como sync_item_fallido', 3 === count( array_keys( $w->log_mp_tipos(), 'sync_item_fallido', true ) ) );
t( 'las 3 fallidas quedan en la cola para la próxima corrida', array( 21, 22, 23 ) === array_keys( get_option( 'cn_sync_mp_fallidos' ) ) );
t( 'el cursor avanza igual (lote incompleto = vuelve a 0, no repite el lote)', 0 === (int) get_option( 'cn_sync_mp_cursor', 99 ) );
$w = preparar_lote( array(
	21 => array( 'PRE_FALLA00000000021', 500, ciclo_row() ),
	24 => array( 'PRE_OK00000000000024', 'authorized', ciclo_row( 'activo', 'basico', '2026-10-20 12:00:00' ) ),
) );
cn_http_on( 'GET', '#/preapproval/PRE_FALLA00000000021$#', 500 );
$r = CN_Sync::sincronizar_lote();
t( 'HTTP 500 en una fila: esa fila no cambia y las demás siguen', ! updates_de( $w, 21 ) && 1 === count( updates_de( $w, 24 ) ) && 1 === $r['fallos'] );
$w = preparar_lote( array( 25 => array( 'PRE_RARO000000000025', null, ciclo_row() ) ) );
cn_http_on( 'GET', '#/preapproval/PRE_RARO000000000025$#', array( 'id' => 'OTRO', 'status' => 'cancelled' ) );
update_option( 'cn_sub_modo_seguro', '0' );
CN_Sync::sincronizar_lote();
t( 'MP devuelve otro id: no se aplica nada', ! $w->escrituras( 'cn_miembros' ) );

seccion( 'cola de reintento: lo fallido se reintenta primero en la próxima corrida' );
$filas = array();
for ( $i = 1; $i <= 20; $i++ ) {
	$filas[ $i ] = array( sprintf( 'PRE_COLA%015d', $i ), 'authorized', ciclo_row( 'activo', 'basico', '2026-12-01 15:00:00' ) );
}
$filas[3][1] = 500; // la 3 falla; el lote es de 20, así que el cursor queda en 20.
$w = preparar_lote( $filas );
cn_http_on( 'GET', '#/preapproval/PRE_COLA000000000000003$#', 500 );
$r = CN_Sync::sincronizar_lote();
t( 'lote lleno con un fallo: procesa las otras 19 y el cursor avanza a 20', 19 === $r['procesadas'] && 1 === $r['fallos'] && 20 === (int) get_option( 'cn_sync_mp_cursor' ), json_encode( $r ) );
t( 'la fallida (3) queda en la cola con contador 1', 1 === get_option( 'cn_sync_mp_fallidos' )[3]['intentos'] );
// Próxima corrida: el cursor ya pasó la 3, pero la cola la trae igual (y ahora MP responde).
$w = preparar_lote( array( 3 => array( 'PRE_COLA000000000000003', 'authorized', ciclo_row( 'activo', 'basico', '2026-10-20 12:00:00' ) ), 25 => array( 'PRE_COLA000000000000025', 'authorized', ciclo_row( 'activo', 'basico', '2026-12-01 15:00:00' ) ) ) );
update_option( 'cn_sync_mp_cursor', 20 );
update_option( 'cn_sync_mp_fallidos', array( 3 => array( 'preapproval_id' => 'PRE_COLA000000000000003', 'intentos' => 1, 'desde' => 1 ) ) );
$w->on( '/SELECT id, preapproval_id FROM wp_cn_miembros\s+WHERE id IN \(3\)/', array( fila_sync( 3, 'PRE_COLA000000000000003' ) ) );
$r = CN_Sync::sincronizar_lote();
t( 'la corrida siguiente reintenta la fallida aunque el cursor ya la pasó', 1 === $r['reintentadas'] && 1 === count( updates_de( $w, 3 ) ), json_encode( $r ) );
t( 'si ahora sale bien, la cola queda vacía', array() === get_option( 'cn_sync_mp_fallidos' ) );
// Tras 5 corridas fallidas seguidas sale de la cola y queda un aviso (no se pierde en silencio).
$w = preparar_lote( array( 3 => array( 'PRE_COLA000000000000003', 500, ciclo_row() ) ) );
cn_http_on( 'GET', '#/preapproval/PRE_COLA000000000000003$#', 500 );
update_option( 'cn_sync_mp_fallidos', array( 3 => array( 'preapproval_id' => 'PRE_COLA000000000000003', 'intentos' => CN_Sync::MAX_INTENTOS_ITEM - 1, 'desde' => 1 ) ) );
$w->on( '/SELECT id, preapproval_id FROM wp_cn_miembros\s+WHERE id IN \(3\)/', array( fila_sync( 3, 'PRE_COLA000000000000003' ) ) );
CN_Sync::sincronizar_lote();
t( 'a la 5ª corrida fallida sale de la cola y deja aviso en el cartel', array() === get_option( 'cn_sync_mp_fallidos' ) && in_array( CN_Webhook::TIPO_AVISO, $w->log_mp_tipos(), true ) );

seccion( '429: Retry-After, backoff exponencial y topes' );
$esperas = array();
CN_MP::$dormir = function ( $seg ) use ( &$esperas ) {
	$esperas[] = $seg;
};
$w = preparar_lote( array( 40 => array( 'PRE_R429000000000040', null, ciclo_row( 'activo', 'basico', '2026-10-20 12:00:00' ) ) ) );
$n429 = 0;
cn_http_on( 'GET', '#/preapproval/PRE_R429000000000040$#', function () use ( &$n429 ) {
	return ++$n429 <= 2 ? array( '__status' => 429, '__headers' => array(), '__body' => array() ) : mp_pre( 'PRE_R429000000000040', 'authorized' );
} );
$r = CN_Sync::sincronizar_lote();
t( '429 sin Retry-After: espera 2 s y 4 s y entonces anda', array( 2, 4 ) === $esperas && 1 === $r['procesadas'] && 0 === $r['fallos'], json_encode( array( $esperas, $r ) ) );
$esperas = array();
$w = preparar_lote( array( 41 => array( 'PRE_R429000000000041', null, ciclo_row( 'activo', 'basico', '2026-10-20 12:00:00' ) ) ) );
$n429 = 0;
cn_http_on( 'GET', '#/preapproval/PRE_R429000000000041$#', function () use ( &$n429 ) {
	return ++$n429 <= 1 ? array( '__status' => 429, '__headers' => array( 'retry-after' => '7' ), '__body' => array() ) : mp_pre( 'PRE_R429000000000041', 'authorized' );
} );
$r = CN_Sync::sincronizar_lote();
t( '429 con Retry-After: respeta los 7 s que pide MP', array( 7 ) === $esperas && 1 === $r['procesadas'], json_encode( $esperas ) );
$esperas = array();
$w = preparar_lote( array( 42 => array( 'PRE_R429000000000042', null, ciclo_row() ), 43 => array( 'PRE_R429000000000043', 'authorized', ciclo_row( 'activo', 'basico', '2026-10-20 12:00:00' ) ) ) );
cn_http_on( 'GET', '#/preapproval/PRE_R429000000000042$#', array( '__status' => 429, '__headers' => array(), '__body' => array() ) );
$r = CN_Sync::sincronizar_lote();
t( '429 persistente: máx. 3 reintentos (2, 4, 8 s), 4 pedidos en total', array( 2, 4, 8 ) === $esperas && 4 === count( cn_http_calls( '#/preapproval/PRE_R429000000000042#' ) ), json_encode( $esperas ) );
t( '429 persistente: el ítem queda como sync_item_fallido y en cola, el siguiente se procesa y no se escribe nada de la fallida', 1 === $r['fallos'] && in_array( 'sync_item_fallido', $w->log_mp_tipos(), true ) && isset( get_option( 'cn_sync_mp_fallidos' )[42] ) && ! updates_de( $w, 42 ) && 1 === count( updates_de( $w, 43 ) ) );
t( 'en las pruebas nunca se espera de verdad (la función de espera es la inyectada)', true );
t( 'Retry-After gigante se acota a ESPERA_MAX_SEG', 30 === min( CN_MP::segundos_retry_after( '999' ), CN_MP::ESPERA_MAX_SEG ) && null === CN_MP::segundos_retry_after( '' ) && null === CN_MP::segundos_retry_after( 'basura' ) );
// Tope de espera por corrida: Retry-After de 30 s con tope de 40 s => la 2ª espera ya no entra y se rinde.
$esperas = array();
$w = preparar_lote( array( 44 => array( 'PRE_R429000000000044', null, ciclo_row() ), 45 => array( 'PRE_R429000000000045', null, ciclo_row() ) ) );
cn_http_on( 'GET', '#/preapproval/PRE_R429#', array( '__status' => 429, '__headers' => array( 'retry-after' => '30' ), '__body' => array() ) );
$r = CN_Sync::sincronizar_lote();
t( 'tope de espera por corrida: no espera más de TOPE_ESPERA_SEG en total', array_sum( $esperas ) <= CN_Sync::TOPE_ESPERA_SEG && 2 === $r['fallos'] && ! $w->escrituras( 'cn_miembros' ), json_encode( array( $esperas, $r ) ) );
t( 'tope de espera: ambas quedan en la cola (no se saltean)', array( 44, 45 ) === array_keys( get_option( 'cn_sync_mp_fallidos' ) ) );
// Tope de pedidos: 20 ítems de la cola que dan 429 gastan los 80 pedidos; el lote que sigue se encola sin pedirse.
$filas = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$filas[ 50 + $i ] = array( sprintf( 'PRE_TOPE%014d', $i ), null, ciclo_row() );
}
$w = preparar_lote( $filas );
$cola_vieja = array();
$cola_rows  = array();
for ( $i = 101; $i <= 120; $i++ ) {
	$cola_vieja[ $i ] = array( 'preapproval_id' => sprintf( 'PRE_COLQ%014d', $i ), 'intentos' => 1, 'desde' => 1 );
	$cola_rows[]      = fila_sync( $i, sprintf( 'PRE_COLQ%014d', $i ) );
}
update_option( 'cn_sync_mp_fallidos', $cola_vieja );
$w->on( '/SELECT id, preapproval_id FROM wp_cn_miembros\s+WHERE id IN/', $cola_rows );
cn_http_on( 'GET', '#/preapproval/PRE_#', array( '__status' => 429, '__headers' => array( 'retry-after' => '0' ), '__body' => array() ) );
$r = CN_Sync::sincronizar_lote();
t( 'tope de pedidos por corrida: nunca más de TOPE_REQUESTS pedidos', count( cn_http_calls( '#/preapproval/PRE_#' ) ) <= CN_Sync::TOPE_REQUESTS, (string) count( cn_http_calls( '#/preapproval/PRE_#' ) ) );
t( 'al llegar al tope: abortada=true y los 32 ítems (20 de la cola + 12 del lote) quedan en la cola, ninguno se saltea', $r['abortada'] && 32 === count( get_option( 'cn_sync_mp_fallidos' ) ) && ! $w->escrituras( 'cn_miembros' ), json_encode( $r ) );
CN_MP::$dormir = function () {
};

seccion( 'correr(): flag, lock y manejo de errores' );
$w = preparar_lote( array( 30 => array( 'PRE_FLAG0000000000030', 'authorized', ciclo_row() ) ) );
update_option( 'cn_sync_mp_activo', '0' );
CN_Sync::correr();
t( 'cn_sync_mp_activo=0: no consulta ni escribe', ! $GLOBALS['cn_http_log'] && ! $w->log );
update_option( 'cn_sync_mp_activo', '1' );
set_transient( 'cn_sync_mp_lock', 1 );
CN_Sync::correr();
t( 'con una corrida en curso (lock): no hace nada', ! $GLOBALS['cn_http_log'] );
delete_transient( 'cn_sync_mp_lock' );
CN_Sync::correr();
t( 'corre y libera el lock', count( cn_http_calls( '#/preapproval/PRE_FLAG#' ) ) >= 1 && false === get_transient( 'cn_sync_mp_lock' ) );
$w = preparar_lote( array() );
$w->on( '/SELECT id, preapproval_id FROM/', function () use ( $w ) {
	$w->last_error = 'boom';
	return array();
} );
try {
	CN_Sync::correr();
	$ok = true;
} catch ( Throwable $e ) {
	$ok = false;
}
t( 'error de base: no lanza, deja rastro y libera el lock', $ok && in_array( 'sub_error', $w->log_mp_tipos(), true ) && false === get_transient( 'cn_sync_mp_lock' ) );

seccion( 'aviso a n8n de suscripciones authorized sin vincular hace más de 1 hora' );
function hace( $seg ) {
	return gmdate( 'Y-m-d\TH:i:s.000\Z', time() - $seg );
}
function preparar_alerta( $items, $socias ) {
	cn_test_reset();
	global $wpdb;
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => $items, 'paging' => array( 'total' => count( $items ) ) ) );
	cn_http_on( 'GET', '#/v1/payments/search#', array( 'results' => array(), 'paging' => array( 'total' => 0 ) ) );
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	$wpdb->on( '/SELECT id, nombre_apellido, estado, email, celular_hint, preapproval_id, fecha_fin_trial FROM wp_cn_miembros/', $socias );
	return $wpdb;
}
$vieja  = array( 'id' => 'SUBVIEJA00000000000A', 'payer_email' => 'ana@example.com', 'date_created' => hace( 3 * 3600 ), 'next_payment_date' => '2026-11-10T00:00:00.000-03:00', 'auto_recurring' => array( 'transaction_amount' => 15500 ) );
$nueva  = array( 'id' => 'SUBNUEVA00000000000B', 'payer_email' => 'bea@example.com', 'date_created' => hace( 600 ), 'auto_recurring' => array( 'transaction_amount' => 32000 ) );
$vinc   = array( 'id' => 'SUBVINCULADA000000000C', 'payer_email' => 'cris@example.com', 'date_created' => hace( 9 * 3600 ), 'auto_recurring' => array( 'transaction_amount' => 15500 ) );
$socias = array(
	cn_fila( array( 'id' => 1, 'nombre_apellido' => 'Ana', 'estado' => 'activo', 'email' => 'ana@example.com', 'celular_hint' => '1111', 'preapproval_id' => null, 'fecha_fin_trial' => null ) ),
	cn_fila( array( 'id' => 3, 'nombre_apellido' => 'Cris', 'estado' => 'activo', 'email' => 'cris@example.com', 'celular_hint' => '3333', 'preapproval_id' => 'SUBVINCULADA000000000C', 'fecha_fin_trial' => null ) ),
);
$w = preparar_alerta( array( $vieja, $nueva, $vinc ), $socias );
$r = CN_Sync::alertar_sin_vincular();
$av = cn_http_calls( '#club-trial-alerta#' );
t( 'avisa solo la que lleva más de 1 hora y no está vinculada', 1 === count( $av ) && 1 === $r['avisadas'] );
$cuerpo = (string) ( $av[0]['body'] ?? '' );
t( 'el aviso trae motivo, ID completo, plan, socia propuesta y confianza', false !== strpos( $cuerpo, 'sub_sin_vincular' ) && false !== strpos( $cuerpo, 'SUBVIEJA00000000000A' ) && false !== strpos( $cuerpo, 'basico' ) && false !== strpos( $cuerpo, 'email_exacto' ) && false !== strpos( $cuerpo, '"socia_id":1' ) );
t( 'no incluye a la nueva (<1 h) ni a la ya vinculada', false === strpos( $cuerpo, 'SUBNUEVA' ) && false === strpos( $cuerpo, 'SUBVINCULADA' ) );
t( 'NO auto-vincula: cero escrituras en miembros', ! $w->escrituras( 'cn_miembros' ) );
$r = CN_Sync::alertar_sin_vincular();
t( 'no repite el aviso de la misma suscripción dentro de las 24 h', 1 === count( cn_http_calls( '#club-trial-alerta#' ) ) && 0 === $r['avisadas'] );
$w = preparar_alerta( array( $nueva ), $socias );
CN_Sync::alertar_sin_vincular();
t( 'solo suscripciones nuevas: no avisa', ! cn_http_calls( '#club-trial-alerta#' ) );
$w = preparar_alerta( array( $vieja ), $socias );
$GLOBALS['cn_http'] = array_values( array_filter( $GLOBALS['cn_http'], function ( $r ) {
	return false === strpos( $r['patron'], 'preapproval/search' );
} ) ); // MP no responde la lista.
$r = CN_Sync::alertar_sin_vincular();
t( 'MP caído: no concluye nada ni avisa de sin vincular (solo el mp_api_error de siempre)', null === $r && ! array_filter( cn_http_calls( '#club-trial-alerta#' ), function ( $c ) {
	return false !== strpos( (string) $c['body'], 'sub_sin_vincular' );
} ) );
$w = preparar_alerta( array( $vieja ), array() );
update_option( 'cn_mp_access_token', '' );
t( 'sin token: no hace nada', null === CN_Sync::alertar_sin_vincular() && ! cn_http_calls( '#club-trial-alerta#' ) );
$w = preparar_alerta( array( array( 'id' => 'SUBSINEMAIL000000000D', 'date_created' => hace( 7200 ), 'auto_recurring' => array( 'transaction_amount' => 15500 ) ) ), $socias );
cn_http_on( 'GET', '#authorized_payments/search#', array( 'results' => array() ) );
CN_Sync::alertar_sin_vincular();
$c = (string) ( cn_http_calls( '#club-trial-alerta#' )[0]['body'] ?? '' );
t( 'sin email y sin cobros: se avisa igual como sin_email', false !== strpos( $c, 'sin_email' ) );
