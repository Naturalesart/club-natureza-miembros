<?php
// Punto 3: guarda de MP en el cron de trials vencidos (CN_Auth::revocar_trials_vencidos) + regresión de los escenarios existentes.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
function mh( $seg_atras ) { // fecha de MP "hace N segundos" (con offset -03:00, como manda MP).
	return gmdate( 'Y-m-d\TH:i:s.000', time() - $seg_atras ) . '-00:00';
}
function trial_row( $id, $extra = array() ) {
	return cn_fila( array_merge( array(
		'id' => $id, 'preapproval_id' => null, 'nombre_apellido' => 'Socia ' . $id, 'email' => 'socia' . $id . '@example.com',
		'fecha_fin_trial' => '2026-10-12 10:00:00', 'trial_payment_id' => '9000' . $id,
	), $extra ) );
}
function pago_mp( $id, $monto, $seg_atras, $email = null, $extra = array() ) {
	return array_merge( array( 'id' => $id, 'status' => 'approved', 'transaction_amount' => $monto, 'date_created' => mh( $seg_atras ), 'payer' => array( 'email' => $email ?? 'x@example.com' ) ), $extra );
}
/** Prepara: filas vencidas; la relectura FOR UPDATE de cada fila devuelve la fila tal cual (estado activo). */
function preparar_trials( $rows ) {
	cn_test_reset();
	global $wpdb;
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	$GLOBALS['cn_now'] = '2026-10-13 15:00:00';
	$wpdb->on( '/SELECT id, preapproval_id, nombre_apellido, email, fecha_fin_trial, trial_payment_id FROM wp_cn_miembros/', $rows );
	foreach ( $rows as $r ) {
		$wpdb->on( '/SELECT id, estado, preapproval_id, fecha_fin_trial FROM wp_cn_miembros WHERE id = ' . $r->id . ' FOR UPDATE/', array( cn_fila( array( 'id' => $r->id, 'estado' => 'activo', 'preapproval_id' => $r->preapproval_id, 'fecha_fin_trial' => $r->fecha_fin_trial ) ) ) );
	}
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	return $wpdb;
}
function mp_pagos_email( $email, $results ) {
	cn_http_on( 'GET', '#/v1/payments/search\?.*payer\.email=' . preg_quote( rawurlencode( $email ), '#' ) . '#', array( 'results' => $results ) );
}
function mp_subs_email( $email, $results ) {
	cn_http_on( 'GET', '#/preapproval/search\?.*payer_email=' . preg_quote( rawurlencode( $email ), '#' ) . '#', array( 'results' => $results ) );
}
function cancelaciones( $w ) {
	return array_values( array_filter( $w->escrituras( 'cn_miembros' ), function ( $e ) {
		return 'update' === $e[0] && isset( $e[2]['estado'] ) && 'cancelada' === $e[2]['estado'];
	} ) );
}
function limpiezas( $w ) {
	return array_values( array_filter( $w->escrituras( 'cn_miembros' ), function ( $e ) {
		return 'update' === $e[0] && array_key_exists( 'fecha_fin_trial', $e[2] ) && null === $e[2]['fecha_fin_trial'];
	} ) );
}
function alertas( $motivo ) {
	return array_values( array_filter( cn_http_calls( '#club-trial-alerta#' ), function ( $c ) use ( $motivo ) {
		return false !== strpos( (string) $c['body'], '"motivo":"' . $motivo . '"' );
	} ) );
}

seccion( 'opción cn_guarda_trial_mp' );
cn_test_reset();
t( 'activada por defecto', CN_Sync::guarda_trial_activa() );
update_option( 'cn_guarda_trial_mp', '0' );
t( 'se puede apagar', ! CN_Sync::guarda_trial_activa() );

seccion( 'regresión: los escenarios que ya existían' );
$w = preparar_trials( array( trial_row( 1, array( 'preapproval_id' => 'PRE_YA_SUSCRIPTA0001' ) ) ) );
CN_Auth::revocar_trials_vencidos();
t( 'con preapproval_id: solo limpia fecha_fin_trial, no cancela ni consulta a MP', 1 === count( limpiezas( $w ) ) && ! cancelaciones( $w ) && ! $GLOBALS['cn_http_log'] );
$w = preparar_trials( array( trial_row( 2 ) ) );
mp_pagos_email( 'socia2@example.com', array( pago_mp( '90002', 7000, 86400 * 8, 'socia2@example.com' ) ) ); // solo el cobro del trial
mp_subs_email( 'socia2@example.com', array() );
CN_Auth::revocar_trials_vencidos();
t( 'trial vencido sin pago posterior: se cancela y se manda el mail de fin de trial', 1 === count( cancelaciones( $w ) ) && 1 === count( $GLOBALS['cn_mails'] ) && 'socia2@example.com' === $GLOBALS['cn_mails'][0]['to'], json_encode( $w->escrituras( 'cn_miembros' ) ) );
t( 'la cancelación es una transacción con FOR UPDATE', $w->transaccion() === array( 'START TRANSACTION', 'COMMIT' ) && (bool) array_filter( $w->consultas, function ( $q ) {
	return false !== strpos( $q, 'FOR UPDATE' );
} ) );
update_option( 'cn_guarda_trial_mp', '0' );
$w = preparar_trials( array( trial_row( 3 ) ) );
update_option( 'cn_guarda_trial_mp', '0' );
CN_Auth::revocar_trials_vencidos();
t( 'guarda apagada: se comporta como antes (cancela sin consultar a MP)', 1 === count( cancelaciones( $w ) ) && ! $GLOBALS['cn_http_log'] );
t( 'la consulta de vencidos sigue siendo la misma condición (fecha_fin_trial vencida, no cancelada)', (bool) array_filter( $w->consultas, function ( $q ) {
	return false !== strpos( $q, 'fecha_fin_trial IS NOT NULL' ) && false !== strpos( $q, 'fecha_fin_trial < UTC_TIMESTAMP()' ) && false !== strpos( $q, "estado != 'cancelada'" );
} ) );

seccion( 'guarda: pagó por link directo (cobro >= $15.500 posterior al de $7.000)' );
foreach ( array( 15500, 32000, 20000 ) as $monto ) {
	$w = preparar_trials( array( trial_row( 4 ) ) );
	mp_pagos_email( 'socia4@example.com', array(
		pago_mp( '90004', 7000, 86400 * 8, 'socia4@example.com' ), // el del trial
		pago_mp( '5551', $monto, 86400 * 1, 'socia4@example.com' ),
	) );
	CN_Auth::revocar_trials_vencidos();
	t( "cobro de \${$monto}: NO cancela, limpia fecha_fin_trial y avisa por n8n", ! cancelaciones( $w ) && 1 === count( limpiezas( $w ) ) && 1 === count( alertas( 'trial_pago_directo' ) ) && ! $GLOBALS['cn_mails'] );
}
$a = alertas( 'trial_pago_directo' );
t( 'el aviso trae socia, email, monto y el pedido de vincular', false !== strpos( (string) $a[0]['body'], 'socia4@example.com' ) && false !== strpos( (string) $a[0]['body'], '5551' ) && false !== strpos( (string) $a[0]['body'], 'vincular' ) || false !== strpos( (string) $a[0]['body'], 'Vincular' ) );
t( 'deja log trial_pago_directo', in_array( 'trial_pago_directo', $w->log_mp_tipos(), true ) );
t( 'no vincula nada ni toca estado/plan/preapproval', ! array_filter( $w->escrituras( 'cn_miembros' ), function ( $e ) {
	return isset( $e[2]['estado'] ) || isset( $e[2]['preapproval_id'] ) || isset( $e[2]['plan'] );
} ) );

seccion( 'guarda: cobros que NO cuentan' );
$casos = array(
	'cobro de $7.000 (el del trial)'              => array( pago_mp( '90005', 7000, 86400 * 8, 'socia5@example.com' ) ),
	'cobro menor a $15.500 posterior ($15.000)'   => array( pago_mp( '90005', 7000, 86400 * 8, 'socia5@example.com' ), pago_mp( '5552', 15000, 3600, 'socia5@example.com' ) ),
	'cobro >= $15.500 ANTERIOR al del trial'      => array( pago_mp( '90005', 7000, 86400 * 8, 'socia5@example.com' ), pago_mp( '5553', 32000, 86400 * 30, 'socia5@example.com' ) ),
	'cobro no aprobado'                           => array( pago_mp( '90005', 7000, 86400 * 8, 'socia5@example.com' ), pago_mp( '5554', 32000, 3600, 'socia5@example.com', array( 'status' => 'rejected' ) ) ),
);
foreach ( $casos as $nombre => $pagos ) {
	$w = preparar_trials( array( trial_row( 5 ) ) );
	mp_pagos_email( 'socia5@example.com', $pagos );
	mp_subs_email( 'socia5@example.com', array() );
	CN_Auth::revocar_trials_vencidos();
	t( "{$nombre}: se cancela", 1 === count( cancelaciones( $w ) ) && ! alertas( 'trial_pago_directo' ) );
}
$w = preparar_trials( array( trial_row( 5, array( 'trial_payment_id' => null ) ) ) );
mp_pagos_email( 'socia5@example.com', array( pago_mp( '90005', 7000, 86400 * 8, 'socia5@example.com' ), pago_mp( '5555', 32000, 86400 * 9, 'socia5@example.com' ) ) );
mp_subs_email( 'socia5@example.com', array() );
CN_Auth::revocar_trials_vencidos();
t( 'sin trial_payment_id: la referencia es fin del trial - 7 días; un cobro previo a eso no cuenta', 1 === count( cancelaciones( $w ) ) );

seccion( 'guarda: suscripción authorized con ese email' );
$w = preparar_trials( array( trial_row( 6 ) ) );
mp_pagos_email( 'socia6@example.com', array( pago_mp( '90006', 7000, 86400 * 8, 'socia6@example.com' ) ) );
mp_subs_email( 'socia6@example.com', array(
	array( 'id' => 'SUBDEOTRA000000000001', 'status' => 'authorized', 'payer_email' => 'otra@example.com', 'auto_recurring' => array( 'transaction_amount' => 15500 ) ),
	array( 'id' => 'SUBPROPIA000000000002', 'status' => 'authorized', 'payer_email' => 'SOCIA6@example.com', 'auto_recurring' => array( 'transaction_amount' => 15500 ) ),
) );
CN_Auth::revocar_trials_vencidos();
t( 'suscripción authorized con ese email: no cancela y avisa', ! cancelaciones( $w ) && 1 === count( limpiezas( $w ) ) && 1 === count( alertas( 'trial_pago_directo' ) ) );
$w = preparar_trials( array( trial_row( 6 ) ) );
mp_pagos_email( 'socia6@example.com', array( pago_mp( '90006', 7000, 86400 * 8, 'socia6@example.com' ) ) );
mp_subs_email( 'socia6@example.com', array(
	array( 'id' => 'SUBDEOTRA000000000001', 'status' => 'authorized', 'payer_email' => 'otra@example.com', 'auto_recurring' => array( 'transaction_amount' => 15500 ) ), // el filtro de MP no se respeta
	array( 'id' => 'SUBPAUSADA00000000003', 'status' => 'paused', 'payer_email' => 'socia6@example.com', 'auto_recurring' => array( 'transaction_amount' => 15500 ) ),
) );
CN_Auth::revocar_trials_vencidos();
t( 'suscripción de OTRO email o no authorized: no cuenta, se cancela', 1 === count( cancelaciones( $w ) ) );

seccion( 'guarda: si MP falla NO se cancela y se reintenta' );
$w = preparar_trials( array( trial_row( 7 ) ) ); // sin reglas de MP: todo falla
CN_Auth::revocar_trials_vencidos();
t( 'cobros: MP no responde -> no cancela, no limpia, no manda mail, no abre transacción', ! $w->escrituras( 'cn_miembros' ) && ! $GLOBALS['cn_mails'] && ! $w->transaccion() );
t( 'deja rastro trial_guarda_error', in_array( 'trial_guarda_error', $w->log_mp_tipos(), true ) );
$w = preparar_trials( array( trial_row( 7 ) ) );
cn_http_on( 'GET', '#/v1/payments/search#', 500 );
CN_Auth::revocar_trials_vencidos();
t( 'cobros: HTTP 500 -> no cancela', ! $w->escrituras( 'cn_miembros' ) );
$w = preparar_trials( array( trial_row( 7 ) ) );
mp_pagos_email( 'socia7@example.com', array( pago_mp( '90007', 7000, 86400 * 8, 'socia7@example.com' ) ) );
cn_http_on( 'GET', '#/preapproval/search#', 401 );
CN_Auth::revocar_trials_vencidos();
t( 'cobros bien pero la consulta de suscripciones falla -> no cancela', ! $w->escrituras( 'cn_miembros' ) && ! $GLOBALS['cn_mails'] );
// Reintento: la corrida siguiente, con MP andando y sin pago, cancela; la fila seguía vencida.
$w = preparar_trials( array( trial_row( 7 ) ) );
mp_pagos_email( 'socia7@example.com', array( pago_mp( '90007', 7000, 86400 * 8, 'socia7@example.com' ) ) );
mp_subs_email( 'socia7@example.com', array() );
CN_Auth::revocar_trials_vencidos();
t( 'próxima corrida con MP andando: ahora sí cancela', 1 === count( cancelaciones( $w ) ) );
$w = preparar_trials( array( trial_row( 8 ), trial_row( 9 ) ) );
mp_pagos_email( 'socia9@example.com', array( pago_mp( '90009', 7000, 86400 * 8, 'socia9@example.com' ) ) );
mp_subs_email( 'socia9@example.com', array() );
CN_Auth::revocar_trials_vencidos(); // la 8 falla (sin reglas), la 9 anda
$c = cancelaciones( $w );
t( 'una fila con MP caído no frena a las demás (solo cancela la 9)', 1 === count( $c ) && array( 'id' => 9 ) === $c[0][3] );

seccion( 'sin email: no se puede verificar' );
$w = preparar_trials( array( trial_row( 10, array( 'email' => null ) ) ) );
CN_Auth::revocar_trials_vencidos();
t( 'sin email sigue como antes (se cancela) y queda rastro', 1 === count( cancelaciones( $w ) ) && in_array( 'trial_guarda_sin_email', $w->log_mp_tipos(), true ) && ! $GLOBALS['cn_http_log'] );

seccion( "comparación estricta de preapproval_id ('0' no es vacío)" );
$w = preparar_trials( array( trial_row( 11, array( 'preapproval_id' => '0' ) ) ) );
CN_Auth::revocar_trials_vencidos();
t( "preapproval_id '0': NO se cancela, solo se limpia fecha_fin_trial (empty('0') daba true y cancelaba)", ! cancelaciones( $w ) && 1 === count( limpiezas( $w ) ) && ! $GLOBALS['cn_http_log'] );
$w = preparar_trials( array( trial_row( 12, array( 'preapproval_id' => '' ) ) ) );
mp_pagos_email( 'socia12@example.com', array( pago_mp( '90012', 7000, 86400 * 8, 'socia12@example.com' ) ) );
mp_subs_email( 'socia12@example.com', array() );
CN_Auth::revocar_trials_vencidos();
t( "preapproval_id '' (vacío): sigue el camino de trial sin suscripción", 1 === count( cancelaciones( $w ) ) );

seccion( 'carrera: se vinculó una suscripción mientras corría el cron' );
$w = preparar_trials( array( trial_row( 13 ) ) );
mp_pagos_email( 'socia13@example.com', array( pago_mp( '90013', 7000, 86400 * 8, 'socia13@example.com' ) ) );
mp_subs_email( 'socia13@example.com', array() );
$w->on( '/SELECT id, estado, preapproval_id, fecha_fin_trial FROM wp_cn_miembros WHERE id = 13 FOR UPDATE/', array( cn_fila( array( 'id' => 13, 'estado' => 'activo', 'preapproval_id' => 'PRE_RECIEN_VINCULADA01', 'fecha_fin_trial' => null ) ) ) );
CN_Auth::revocar_trials_vencidos();
t( 'la relectura con FOR UPDATE ve la suscripción nueva: ROLLBACK, no cancela, no manda mail', ! cancelaciones( $w ) && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! $GLOBALS['cn_mails'] );
$w = preparar_trials( array( trial_row( 14 ) ) );
mp_pagos_email( 'socia14@example.com', array( pago_mp( '90014', 7000, 86400 * 8, 'socia14@example.com' ) ) );
mp_subs_email( 'socia14@example.com', array() );
$w->on( '/SELECT id, estado, preapproval_id, fecha_fin_trial FROM wp_cn_miembros WHERE id = 14 FOR UPDATE/', array( cn_fila( array( 'id' => 14, 'estado' => 'cancelada', 'preapproval_id' => null, 'fecha_fin_trial' => '2026-10-12 10:00:00' ) ) ) );
CN_Auth::revocar_trials_vencidos();
t( 'ya cancelada por otro camino: no se vuelve a cancelar ni se manda otro mail', ! cancelaciones( $w ) && ! $GLOBALS['cn_mails'] );

seccion( 'funciones puras de la guarda' );
$ref = CN_Sync::referencia_trial( array( pago_mp( '777', 7000, 86400 * 8 ) ), cn_fila( array( 'trial_payment_id' => '777', 'fecha_fin_trial' => '2026-10-12 10:00:00' ) ) );
t( 'referencia = fecha del pago del trial si está en la lista', abs( $ref - ( time() - 86400 * 8 ) ) < 5 );
$ref = CN_Sync::referencia_trial( array(), cn_fila( array( 'trial_payment_id' => '777', 'fecha_fin_trial' => '2026-10-12 10:00:00' ) ) );
t( 'si no está: fin de trial - 7 días', gmdate( 'Y-m-d H:i:s', $ref ) === '2026-10-05 10:00:00', gmdate( 'Y-m-d H:i:s', $ref ) );
