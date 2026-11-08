<?php
// Espejo de pausas/cancelaciones de Mercado Pago (CN_Espejo_MP): modos off/log/on, gracia, fail-safe, tope por corrida.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
CN_Sync::$pausa_us = 0;
define( 'EM_DIA', 86400 );
function em_utc( $dias ) {
	return gmdate( 'Y-m-d H:i:s', time() + (int) round( $dias * EM_DIA ) );
}
function em_pid( $n ) {
	return 'PRE_ESPEJO_' . str_pad( (string) $n, 8, '0', STR_PAD_LEFT );
}
function em_ficha( $n, $extra = array() ) {
	return cn_fila( $extra + array(
		'id' => $n, 'estado' => 'activo', 'plan' => 'basico', 'preapproval_id' => em_pid( $n ),
		'acceso_hasta' => em_utc( 10 ), 'baja_en' => null, 'fecha_fin_trial' => null,
	) );
}
function em_mp( $n, $status, $next = null ) {
	$d = array( 'id' => em_pid( $n ), 'status' => $status );
	if ( null !== $next ) {
		$d['next_payment_date'] = $next;
	}
	return $d;
}
function em_pago( $dias_atras, $estado = 'approved' ) {
	$f = gmdate( 'Y-m-d\TH:i:s.000\Z', time() - (int) round( $dias_atras * EM_DIA ) );
	return array( 'id' => 1, 'status' => 'processed', 'debit_date' => $f, 'date_created' => $f, 'last_modified' => $f, 'payment' => array( 'id' => 5000, 'status' => $estado ) );
}
/** Prepara modo, token, la ficha (lectura simple y FOR UPDATE) y los cobros de MP. $reread = ficha distinta para el FOR UPDATE. */
function em_preparar( $modo, $fichas, $pagos = array(), $reread = array() ) {
	cn_test_reset();
	global $wpdb;
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	if ( null !== $modo ) {
		update_option( CN_Espejo_MP::OPT_MODO, $modo );
	}
	foreach ( $fichas as $n => $f ) {
		$wpdb->on( '/FROM wp_cn_miembros WHERE id = ' . $n . '$/', array( $f ) );
		$wpdb->on( '/FROM wp_cn_miembros WHERE id = ' . $n . ' FOR UPDATE/', array( $reread[ $n ] ?? $f ) );
		cn_http_on( 'GET', '#/authorized_payments/search\?preapproval_id=' . em_pid( $n ) . '#', array( 'results' => $pagos[ $n ] ?? array() ) );
	}
	CN_Espejo_MP::iniciar_corrida();
	return $wpdb;
}
function em_pres() {
	return array( 'requests' => 50, 'espera' => 10, 'hasta' => time() + 60 );
}
/** Corre una corrida completa del espejo con las respuestas de MP dadas ( n => data ). */
function em_correr( $respuestas ) {
	$p = em_pres();
	CN_Espejo_MP::iniciar_corrida();
	foreach ( $respuestas as $n => $data ) {
		CN_Espejo_MP::observar( $n, em_pid( $n ), $data, $p );
	}
	CN_Espejo_MP::cerrar_corrida();
}
/** UPDATEs sobre miembros (van por query() y por update()). */
function em_escrituras( $w ) {
	$o = array();
	foreach ( $w->log as $l ) {
		if ( 'q' === $l[0] && 0 === strpos( $l[1], 'UPDATE wp_cn_miembros' ) ) {
			$o[] = $l[1];
		}
	}
	return array_merge( $o, $w->escrituras( 'cn_miembros' ) );
}
function em_ultima_tx( $w ) {
	$tx = $w->transaccion();
	return end( $tx );
}
/** Solo la parte SET de un UPDATE (el WHERE sí nombra estado). */
function em_set( $sql ) {
	return (string) preg_replace( '/^.*? SET (.*?) WHERE .*$/s', '$1', (string) $sql );
}
function em_payload( $w, $tipo ) {
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && $tipo === $l[2]['tipo'] ) {
			return (string) $l[2]['payload'];
		}
	}
	return '';
}
function em_tipos( $w, $tipo ) {
	return count( array_keys( $w->log_mp_tipos(), $tipo, true ) );
}
function em_avisos( $w ) {
	$o = array();
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && 'aviso_error' === $l[2]['tipo'] ) {
			$o[] = json_decode( $l[2]['payload'], true )['motivo'];
		}
	}
	return $o;
}

seccion( 'modo off (por defecto): no hace nada' );
t( 'el modo por defecto es off y un valor raro cuenta como off', 'off' === CN_Espejo_MP::modo() );
$w = em_preparar( null, array( 1 => em_ficha( 1 ) ) );
em_correr( array( 1 => em_mp( 1, 'paused' ) ) );
t( 'off: ni consultas a la base, ni a MP, ni escrituras, ni log', ! $w->consultas && ! $w->log && ! $GLOBALS['cn_http_log'] );
update_option( CN_Espejo_MP::OPT_MODO, 'cualquier-cosa' );
t( 'valor raro = off', 'off' === CN_Espejo_MP::modo() );
t( 'off: el cartel de admin no muestra nada', '' === CN_Espejo_MP::cartel() );

seccion( 'authorized sin baja: sin cambios' );
$w = em_preparar( 'on', array( 1 => em_ficha( 1 ) ) );
em_correr( array( 1 => em_mp( 1, 'authorized' ) ) );
t( 'authorized: cero escrituras, cero log, cero pedidos a MP', ! em_escrituras( $w ) && ! $w->log_mp_tipos() && ! $GLOBALS['cn_http_log'] );

seccion( 'on: paused programa la baja y NO corta el acceso' );
$acceso = em_utc( 10 );
$w = em_preparar( 'on', array( 1 => em_ficha( 1, array( 'acceso_hasta' => $acceso ) ) ), array( 1 => array( em_pago( 60 ) ) ) );
em_correr( array( 1 => em_mp( 1, 'paused' ) ) );
$u = em_escrituras( $w );
t( 'una sola escritura sobre miembros', 1 === count( $u ), json_encode( $u ) );
$esperada = gmdate( 'Y-m-d H:i:s', strtotime( $acceso . ' UTC' ) + 7 * EM_DIA );
t( 'baja_en = fin del período pagado + 7 días', false !== strpos( $u[0] ?? '', "baja_en = '" . $esperada . "'" ), $u[0] ?? '' );
t( 'no toca estado ni acceso_hasta (la socia sigue activa)', false === strpos( em_set( $u[0] ?? '' ), 'estado' ) && false === strpos( em_set( $u[0] ?? '' ), 'acceso_hasta' ) );
$tx = $w->transaccion();
t( 'transacción: START, SELECT ... FOR UPDATE y COMMIT', 'START TRANSACTION' === $tx[0] && 'COMMIT' === end( $tx ) && count( preg_grep( '/FOR UPDATE/', $w->consultas ) ) === 1 );
t( 'queda el rastro espejo_mp_baja_programada y ningún aviso grave', 1 === em_tipos( $w, 'espejo_mp_baja_programada' ) && ! em_avisos( $w ) && ! $GLOBALS['cn_mails'] );
t( 'solo GET a Mercado Pago', array( 'GET' ) === array_values( array_unique( array_column( $GLOBALS['cn_http_log'], 'metodo' ) ) ) );
// Idempotente: segunda corrida con la baja ya programada.
$w2 = em_preparar( 'on', array( 1 => em_ficha( 1, array( 'acceso_hasta' => $acceso, 'baja_en' => $esperada ) ) ) );
em_correr( array( 1 => em_mp( 1, 'paused' ) ) );
t( 'idempotente: con la baja ya programada no vuelve a escribir', ! em_escrituras( $w2 ) && ! $w2->log_mp_tipos() );

seccion( 'on: cancelled dentro de la gracia (el período pagado ya venció hace 3 días)' );
$w = em_preparar( 'on', array( 2 => em_ficha( 2, array( 'acceso_hasta' => em_utc( -3 ) ) ) ), array( 2 => array( em_pago( 70 ) ) ) );
em_correr( array( 2 => em_mp( 2, 'cancelled' ) ) );
$u = em_escrituras( $w );
preg_match( "/baja_en = '([^']+)'/", $u[0] ?? '', $m );
$baja = isset( $m[1] ) ? strtotime( $m[1] . ' UTC' ) : 0;
t( 'se programa baja a no menos de 7 días desde ahora (nadie queda cortada de golpe)', 1 === count( $u ) && $baja >= time() + 7 * EM_DIA - 120, $u[0] ?? '' );
t( 'sigue activa: no se toca estado', false === strpos( em_set( $u[0] ?? '' ), 'estado' ) );
$w = em_preparar( 'on', array( 3 => em_ficha( 3, array( 'acceso_hasta' => null ) ) ), array( 3 => array( em_pago( 70 ) ) ) );
em_correr( array( 3 => em_mp( 3, 'cancelled' ) ) );
t( 'sin fecha de período pagado conocida: no se programa nada (lo resuelve una persona)', ! em_escrituras( $w ) && 1 === em_tipos( $w, 'espejo_mp_omitida' ) && false !== strpos( em_payload( $w, 'espejo_mp_omitida' ), 'sin_periodo' ) );
$w = em_preparar( 'on', array( 3 => em_ficha( 3, array( 'acceso_hasta' => null ) ) ), array( 3 => array( em_pago( 70 ) ) ) );
em_correr( array( 3 => em_mp( 3, 'cancelled', gmdate( 'Y-m-d\TH:i:s.000-03:00', time() + 5 * EM_DIA ) ) ) );
t( 'sin acceso_hasta pero con next_payment_date de MP: usa el de MP', 1 === count( em_escrituras( $w ) ) );

seccion( 'on: MP vuelve a authorized dentro de la gracia -> se cancela la baja' );
$bj = em_utc( 4 );
$w = em_preparar( 'on', array( 4 => em_ficha( 4, array( 'baja_en' => $bj ) ) ) );
em_correr( array( 4 => em_mp( 4, 'authorized' ) ) );
$u = em_escrituras( $w );
t( 'authorized con baja programada: baja_en = NULL, sin tocar estado ni acceso', 1 === count( $u ) && false !== strpos( $u[0], 'baja_en = NULL' ) && false === strpos( em_set( $u[0] ), 'estado' ) && false === strpos( em_set( $u[0] ), 'acceso_hasta' ), $u[0] ?? '' );
t( 'queda el rastro espejo_mp_baja_revertida', 1 === em_tipos( $w, 'espejo_mp_baja_revertida' ) );
$w = em_preparar( 'on', array( 4 => em_ficha( 4, array( 'baja_en' => $bj ) ) ), array(), array( 4 => em_ficha( 4, array( 'baja_en' => null ) ) ) );
em_correr( array( 4 => em_mp( 4, 'authorized' ) ) );
t( 'idempotente: si ya no tiene baja al releer, no escribe (ROLLBACK)', ! em_escrituras( $w ) && 'ROLLBACK' === em_ultima_tx( $w ) );

seccion( 'fail-safe: error de MP, respuesta vacía o ambigua -> no cambia nada' );
foreach ( array( 'vacía' => array(), 'sin status' => array( 'id' => em_pid( 5 ) ), 'status raro' => em_mp( 5, 'pending' ), 'status no texto' => array( 'id' => em_pid( 5 ), 'status' => array( 'paused' ) ), 'otra suscripción' => array( 'id' => 'OTRA', 'status' => 'cancelled' ), 'sin id' => array( 'status' => 'cancelled' ), 'no es array' => null ) as $nombre => $data ) {
	$w = em_preparar( 'on', array( 5 => em_ficha( 5 ) ) );
	em_correr( array( 5 => $data ) );
	t( "respuesta {$nombre}: cero escrituras, cero log", ! em_escrituras( $w ) && ! $w->log_mp_tipos() );
}
foreach ( array( 401 => 401, 403 => 403, 500 => 500, 503 => 503, 'red' => null ) as $nombre => $resp ) {
	// Falla la consulta de cobros (paso previo a programar la baja).
	$w = em_preparar( 'on', array( 5 => em_ficha( 5 ) ) );
	cn_http_on( 'GET', '#/authorized_payments/search#', $resp );
	em_correr( array( 5 => em_mp( 5, 'cancelled' ) ) );
	t( "cobros de MP con {$nombre}: no se cambia nada y queda reintento", ! em_escrituras( $w ) && 1 === em_tipos( $w, 'espejo_mp_omitida' ) && false !== strpos( em_payload( $w, 'espejo_mp_omitida' ), 'consulta_pagos_fallo' ) );
}
$w = em_preparar( 'on', array( 5 => em_ficha( 5 ) ) );
cn_http_on( 'GET', '#/authorized_payments/search#', array( 'results' => array( em_pago( 90 ) ), 'paging' => array( 'total' => 40 ) ) );
em_correr( array( 5 => em_mp( 5, 'cancelled' ) ) );
t( 'lista de cobros incompleta (paging.total > resultados): ambiguo, no se baja', ! em_escrituras( $w ) );
// Extremo a extremo con el sync: MP responde 500 / 401 / red al GET del preapproval.
foreach ( array( 500, 401, null ) as $resp ) {
	cn_test_reset();
	global $wpdb;
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	update_option( CN_Espejo_MP::OPT_MODO, 'on' );
	update_option( 'cn_sub_modo_seguro', '0' );
	$wpdb->on( '/SELECT id, preapproval_id FROM wp_cn_miembros\s+WHERE preapproval_id IS NOT NULL/', array( cn_fila( array( 'id' => 6, 'preapproval_id' => em_pid( 6 ) ) ) ) );
	cn_http_on( 'GET', '#/preapproval/' . em_pid( 6 ) . '$#', $resp );
	CN_Sync::sincronizar_lote();
	t( 'sync con MP ' . ( $resp ?? 'sin red' ) . ': el espejo no escribe nada en las fichas', ! em_escrituras( $wpdb ) && ! array_intersect( $wpdb->log_mp_tipos(), array( 'espejo_mp_baja_programada', 'espejo_mp_simulado' ) ) );
}

seccion( 'alcance: fichas sin vínculo, manuales y trials se ignoran' );
$casos = array(
	'sin preapproval_id' => array( 'preapproval_id' => null ),
	'preapproval_id vacío' => array( 'preapproval_id' => '' ),
	'ficha manual (sin plan)' => array( 'plan' => null ),
	'plan vacío' => array( 'plan' => '' ),
	'trial vigente' => array( 'fecha_fin_trial' => em_utc( 3 ) ),
	'ya cancelada' => array( 'estado' => 'cancelada' ),
	'pausada en el sitio' => array( 'estado' => 'pausado' ),
	'otro preapproval_id' => array( 'preapproval_id' => 'OTRO_ID' ),
);
foreach ( $casos as $nombre => $extra ) {
	$w = em_preparar( 'on', array( 7 => em_ficha( 7, $extra ) ), array( 7 => array( em_pago( 90 ) ) ) );
	em_correr( array( 7 => em_mp( 7, 'cancelled' ) ) );
	t( "{$nombre}: ignorada (cero escrituras, cero pedidos de cobros)", ! em_escrituras( $w ) && ! cn_http_calls( '#authorized_payments#' ) );
}

seccion( 'pago reciente: nunca se baja a una socia con un pago de los últimos 35 días' );
$w = em_preparar( 'on', array( 8 => em_ficha( 8 ) ), array( 8 => array( em_pago( 90 ), em_pago( 10 ) ) ) );
em_correr( array( 8 => em_mp( 8, 'cancelled' ) ) );
t( 'pago aprobado hace 10 días: no se baja', ! em_escrituras( $w ) && false !== strpos( em_payload( $w, 'espejo_mp_omitida' ), 'pago_reciente' ) );
$w = em_preparar( 'on', array( 8 => em_ficha( 8 ) ), array( 8 => array( em_pago( 34 ) ) ) );
em_correr( array( 8 => em_mp( 8, 'paused' ) ) );
t( 'pago aprobado hace 34 días: no se baja', ! em_escrituras( $w ) );
$w = em_preparar( 'on', array( 8 => em_ficha( 8 ) ), array( 8 => array( em_pago( 36 ), em_pago( 3, 'rejected' ) ) ) );
em_correr( array( 8 => em_mp( 8, 'paused' ) ) );
t( 'pago aprobado hace 36 días y uno rechazado reciente (no cuenta): sí se programa', 1 === count( em_escrituras( $w ) ) );
$w = em_preparar( 'on', array( 8 => em_ficha( 8 ) ), array( 8 => array( array( 'id' => 1, 'payment' => array( 'status' => 'approved' ) ) ) ) );
em_correr( array( 8 => em_mp( 8, 'paused' ) ) );
t( 'cobro aprobado sin fecha legible: ante la duda, no se baja', ! em_escrituras( $w ) );

seccion( 'tope por corrida: máximo 5 bajas; si hay más, se frena y se avisa' );
$fichas = $resp = array();
for ( $n = 20; $n < 26; $n++ ) {
	$fichas[ $n ] = em_ficha( $n );
	$resp[ $n ]   = em_mp( $n, 'cancelled' );
}
$w = em_preparar( 'on', $fichas, array() );
em_correr( array_slice( $resp, 0, 5, true ) );
t( 'exactamente 5 bajas en la corrida: se programan las 5', 5 === count( em_escrituras( $w ) ) && 5 === em_tipos( $w, 'espejo_mp_baja_programada' ) && ! in_array( 'espejo_mp_tope', em_avisos( $w ), true ) );
$w = em_preparar( 'on', $fichas, array() );
em_correr( $resp );
t( '6 bajas en una corrida: no se programa NINGUNA (posible falla masiva)', 0 === count( em_escrituras( $w ) ) && 0 === em_tipos( $w, 'espejo_mp_baja_programada' ) );
t( '6 bajas: queda el aviso espejo_mp_tope en el cartel Avisos (sin mails)', in_array( 'espejo_mp_tope', em_avisos( $w ), true ) && ! $GLOBALS['cn_mails'] );
$fichas[ 26 ] = em_ficha( 26, array( 'baja_en' => em_utc( 2 ) ) );
$w = em_preparar( 'on', $fichas, array() );
$w->on( '/FROM wp_cn_miembros WHERE id = 26 FOR UPDATE/', array( $fichas[26] ) );
$c = $resp;
$c[26] = em_mp( 26, 'authorized' );
em_correr( $c );
t( 'con el tope superado, las reactivaciones (baja_en = NULL) igual se aplican', 1 === count( em_escrituras( $w ) ) && false !== strpos( em_escrituras( $w )[0], 'baja_en = NULL' ) );

seccion( 'la ficha cambió entre la lectura y el lock: se re-lee y no se escribe' );
$w = em_preparar( 'on', array( 9 => em_ficha( 9 ) ), array(), array( 9 => em_ficha( 9, array( 'baja_en' => em_utc( 6 ) ) ) ) );
em_correr( array( 9 => em_mp( 9, 'cancelled' ) ) );
t( 'ya tenía baja al releer: ROLLBACK, cero UPDATE', ! em_escrituras( $w ) && 'ROLLBACK' === em_ultima_tx( $w ) );
$w = em_preparar( 'on', array( 9 => em_ficha( 9 ) ), array(), array( 9 => em_ficha( 9, array( 'estado' => 'cancelada' ) ) ) );
em_correr( array( 9 => em_mp( 9, 'cancelled' ) ) );
t( 'pasó a cancelada al releer: ROLLBACK, cero UPDATE', ! em_escrituras( $w ) );
$w = em_preparar( 'on', array( 9 => em_ficha( 9 ) ), array(), array( 9 => em_ficha( 9, array( 'preapproval_id' => 'OTRO' ) ) ) );
em_correr( array( 9 => em_mp( 9, 'cancelled' ) ) );
t( 'cambió de suscripción al releer: ROLLBACK, cero UPDATE', ! em_escrituras( $w ) );

seccion( 'modo log: NO escribe en las fichas, solo registra qué haría' );
$w = em_preparar( 'log', array( 10 => em_ficha( 10 ), 11 => em_ficha( 11, array( 'baja_en' => em_utc( 3 ) ) ) ), array( 10 => array( em_pago( 80 ) ) ) );
em_correr( array( 10 => em_mp( 10, 'paused' ), 11 => em_mp( 11, 'authorized' ) ) );
t( 'log: cero escrituras en miembros y cero transacciones', ! em_escrituras( $w ) && ! $w->transaccion() );
t( 'log: una propuesta por ficha en espejo_mp_simulado', 2 === em_tipos( $w, 'espejo_mp_simulado' ) );
$p = json_decode( em_payload( $w, 'espejo_mp_simulado' ), true );
t( 'log: registra ficha, estado actual -> propuesto, motivo y estado de MP', 10 === $p['miembro_id'] && em_pid( 10 ) === $p['preapproval_id'] && 'activo, sin baja' === $p['de'] && 0 === strpos( $p['a'], 'activo, baja programada' ) && '' !== $p['motivo'] && 'paused' === $p['status_mp'], json_encode( $p ) );
t( 'log: solo GET a MP, sin mails ni avisos graves', array( 'GET' ) === array_values( array_unique( array_column( $GLOBALS['cn_http_log'], 'metodo' ) ) ) && ! $GLOBALS['cn_mails'] );
em_correr( array( 10 => em_mp( 10, 'paused' ), 11 => em_mp( 11, 'authorized' ) ) );
t( 'log: no repite la misma propuesta en cada vuelta del sync', 2 === em_tipos( $w, 'espejo_mp_simulado' ) );
$w->on( '/FROM wp_cn_mp_log WHERE tipo IN/', array( cn_fila( array( 'payload' => em_payload( $w, 'espejo_mp_simulado' ), 'tipo' => 'espejo_mp_simulado', 'fecha' => '2026-10-13 15:00:00' ) ) ) );
$cartel = CN_Espejo_MP::cartel();
t( 'log: el cartel de admin muestra el contador y aclara que no cambia fichas', false !== strpos( $cartel, 'Espejo de Mercado Pago' ) && false !== strpos( $cartel, '1 fichas' ) && false !== strpos( $cartel, 'no cambia ninguna ficha' ) && false !== strpos( $cartel, '#10' ) );
// Extremo a extremo por el sync, con modo seguro de producción (1): el sync no escribe y el espejo en log tampoco.
cn_test_reset();
update_option( 'cn_mp_access_token', 'APP_USR-test' );
update_option( CN_Espejo_MP::OPT_MODO, 'log' );
$wpdb->on( '/SELECT id, preapproval_id FROM wp_cn_miembros\s+WHERE preapproval_id IS NOT NULL/', array( cn_fila( array( 'id' => 12, 'preapproval_id' => em_pid( 12 ) ) ) ) );
$wpdb->on( '/FROM wp_cn_miembros WHERE id = 12$/', array( em_ficha( 12 ) ) );
cn_http_on( 'GET', '#/preapproval/' . em_pid( 12 ) . '$#', em_mp( 12, 'paused' ) );
cn_http_on( 'GET', '#/authorized_payments/search#', array( 'results' => array() ) );
update_option( 'cn_sync_mp_cursor', 0 );
CN_Sync::sincronizar_lote();
t( 'sync + log (modo seguro intacto): cero escrituras en fichas, una propuesta registrada', ! em_escrituras( $wpdb ) && 1 === em_tipos( $wpdb, 'espejo_mp_simulado' ) );
t( 'el espejo no toca cn_sub_modo_seguro ni crea opciones de producción', ! array_key_exists( 'cn_sub_modo_seguro', $GLOBALS['cn_opts'] ) );

seccion( 'revocación: la baja con gracia no se ejecuta antes de baja_en' );
$w = $GLOBALS['wpdb'];
cn_test_reset();
CN_Webhook::revocar_bajas_sub_vencidas();
$q = implode( ' ', $GLOBALS['wpdb']->consultas );
t( 'el cron de bajas exige baja_en <= ahora además de acceso_hasta vencido', false !== strpos( $q, 'baja_en <= UTC_TIMESTAMP()' ) && false !== strpos( $q, 'acceso_hasta < UTC_TIMESTAMP()' ) );
$main = file_get_contents( dirname( __DIR__ ) . '/club-natureza-miembros.php' );
t( 'el espejo se carga antes del sync', strpos( $main, 'class-cn-espejo-mp.php' ) < strpos( $main, 'class-cn-sync.php' ) );
$fuente = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-espejo-mp.php' );
t( 'el espejo no usa ningún método de escritura de la API de MP', ! preg_match( '/wp_remote_(post|request)|crear_|CURLOPT_CUSTOMREQUEST|wp_mail/', $fuente ) );
