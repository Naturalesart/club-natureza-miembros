<?php
// Punto 3: guarda de MP en el cron de trials vencidos (CN_Auth::revocar_trials_vencidos).
// Fase A: la guarda cruza payer.id del cobro del trial con /preapproval/search?payer_id (solo GET) y arranca en modo solo-log.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
function trial_row( $id, $extra = array() ) {
	return cn_fila( array_merge( array(
		'id' => $id, 'preapproval_id' => null, 'nombre_apellido' => 'Socia ' . $id, 'email' => 'socia' . $id . '@example.com',
		'fecha_fin_trial' => '2026-10-12 10:00:00', 'trial_payment_id' => '9000' . $id,
	), $extra ) );
}
/** Prepara: filas vencidas; la relectura FOR UPDATE de cada fila devuelve la fila tal cual (estado activo). */
function preparar_trials( $rows, $solo_log = '0' ) {
	cn_test_reset();
	global $wpdb;
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	update_option( 'cn_guarda_trial_solo_log', $solo_log );
	$GLOBALS['cn_now'] = '2026-10-13 15:00:00';
	$wpdb->on( '/SELECT id, preapproval_id, nombre_apellido, email, fecha_fin_trial, trial_payment_id FROM wp_cn_miembros/', $rows );
	foreach ( $rows as $r ) {
		$wpdb->on( '/SELECT id, estado, preapproval_id, fecha_fin_trial FROM wp_cn_miembros WHERE id = ' . $r->id . ' FOR UPDATE/', array( cn_fila( array( 'id' => $r->id, 'estado' => 'activo', 'preapproval_id' => $r->preapproval_id, 'fecha_fin_trial' => $r->fecha_fin_trial ) ) ) );
	}
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	return $rows ? $wpdb : $wpdb;
}
/** GET /v1/payments/{id} -> payer.id (o el valor/código que se pida). */
function mp_pago_trial( $payment_id, $payer_id ) {
	cn_http_on( 'GET', '#/v1/payments/' . preg_quote( (string) $payment_id, '#' ) . '$#', array( 'id' => (int) $payment_id, 'status' => 'approved', 'payer' => null === $payer_id ? array( 'email' => 'x@example.com' ) : array( 'id' => $payer_id ) ) );
}
/** GET /preapproval/search?...payer_id=N -> results (con paging opcional). */
function mp_subs_payer( $payer_id, $results, $total = null ) {
	cn_http_on( 'GET', '#/preapproval/search\?.*payer_id=' . preg_quote( (string) $payer_id, '#' ) . '#', array( 'results' => $results, 'paging' => array( 'offset' => 0, 'limit' => 100, 'total' => null === $total ? count( $results ) : $total ) ) );
}
function sub_mp( $id, $status, $payer_id ) {
	return array( 'id' => $id, 'status' => $status, 'payer_id' => $payer_id, 'auto_recurring' => array( 'transaction_amount' => 15500 ), 'date_created' => '2026-10-10T10:00:00.000-03:00' );
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
/** Payload decodificado de las filas de mp_log de un tipo. */
function log_payloads( $w, $tipo ) {
	$o = array();
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && $tipo === $l[2]['tipo'] ) {
			$o[] = json_decode( $l[2]['payload'], true );
		}
	}
	return $o;
}
function solo_gets_a_mp() {
	foreach ( $GLOBALS['cn_http_log'] as $c ) {
		if ( 'GET' !== $c['metodo'] && false !== strpos( $c['url'], 'api.mercadopago.com' ) ) {
			return false;
		}
	}
	return true;
}

seccion( 'flags: cn_guarda_trial_mp y cn_guarda_trial_solo_log' );
cn_test_reset();
t( 'guarda activada por defecto', CN_Sync::guarda_trial_activa() );
t( 'solo-log activado por defecto (no cambia el comportamiento hasta que alguien lo apague)', CN_Sync::guarda_trial_solo_log() );
update_option( 'cn_guarda_trial_mp', '0' );
t( 'la guarda se puede apagar', ! CN_Sync::guarda_trial_activa() );
update_option( 'cn_guarda_trial_solo_log', '0' );
t( 'solo-log se puede apagar', ! CN_Sync::guarda_trial_solo_log() );

seccion( 'regresión: los escenarios que no dependen de la guarda' );
$w = preparar_trials( array( trial_row( 1, array( 'preapproval_id' => 'PRE_YA_SUSCRIPTA0001' ) ) ) );
CN_Auth::revocar_trials_vencidos();
t( 'con preapproval_id: solo limpia fecha_fin_trial, no cancela ni consulta a MP', 1 === count( limpiezas( $w ) ) && ! cancelaciones( $w ) && ! $GLOBALS['cn_http_log'] );
update_option( 'cn_guarda_trial_mp', '0' );
$w = preparar_trials( array( trial_row( 3 ) ) );
update_option( 'cn_guarda_trial_mp', '0' );
CN_Auth::revocar_trials_vencidos();
t( 'guarda apagada: se comporta como antes (cancela sin consultar a MP) y manda el mail de fin de trial', 1 === count( cancelaciones( $w ) ) && ! $GLOBALS['cn_http_log'] && 1 === count( $GLOBALS['cn_mails'] ) && 'socia3@example.com' === $GLOBALS['cn_mails'][0]['to'] );
t( 'la cancelación es una transacción con FOR UPDATE', $w->transaccion() === array( 'START TRANSACTION', 'COMMIT' ) && (bool) array_filter( $w->consultas, function ( $q ) {
	return false !== strpos( $q, 'FOR UPDATE' );
} ) );
t( 'la consulta de vencidos sigue siendo la misma condición (fecha_fin_trial vencida, no cancelada)', (bool) array_filter( $w->consultas, function ( $q ) {
	return false !== strpos( $q, 'fecha_fin_trial IS NOT NULL' ) && false !== strpos( $q, 'fecha_fin_trial < UTC_TIMESTAMP()' ) && false !== strpos( $q, "estado != 'cancelada'" );
} ) );
$w = preparar_trials( array( trial_row( 11, array( 'preapproval_id' => '0' ) ) ) );
CN_Auth::revocar_trials_vencidos();
t( "preapproval_id '0': NO se cancela, solo se limpia fecha_fin_trial", ! cancelaciones( $w ) && 1 === count( limpiezas( $w ) ) && ! $GLOBALS['cn_http_log'] );

seccion( 'MODO SOLO-LOG (default): registra la decisión y el cron queda exactamente como hoy (no cancela, no limpia, no avisa)' );
// Hoy la guarda siempre falla (400 de MP) y el cron no hace nada con la socia salvo dejar trial_guarda_error. Se preserva con cualquier decisión.
$casos = array(
	'decisión sin_pago'      => function () {
		mp_pago_trial( '90004', 555 );
		mp_subs_payer( 555, array() );
		return 'sin_pago';
	},
	'decisión pago'          => function () {
		mp_pago_trial( '90004', 555 );
		mp_subs_payer( 555, array( sub_mp( 'SUBPROPIA00000000001', 'authorized', 555 ) ) );
		return 'pago';
	},
	'decisión indeterminado' => function () {
		mp_pago_trial( '90004', null );
		return 'indeterminado';
	},
	'decisión error (MP caído)' => function () {
		return 'error';
	},
);
foreach ( $casos as $nombre => $armar ) {
	$w = preparar_trials( array( trial_row( 4 ) ), '1' );
	$esperado = $armar();
	CN_Auth::revocar_trials_vencidos();
	$d = log_payloads( $w, 'trial_guarda_decision' );
	t( "solo-log, {$nombre}: no escribe NADA en miembros, no abre transacción, no manda mail ni aviso", ! $w->escrituras( 'cn_miembros' ) && ! $w->transaccion() && ! $GLOBALS['cn_mails'] && ! alertas( 'trial_pago_directo' ) );
	t( "solo-log, {$nombre}: deja trial_guarda_decision con socia, resultado y motivo", 1 === count( $d ) && 4 === $d[0]['miembro_id'] && $esperado === $d[0]['resultado'] && '' !== (string) $d[0]['motivo'] && true === $d[0]['solo_log'], json_encode( $d ) );
	t( "solo-log, {$nombre}: sigue dejando trial_guarda_error como hoy (la fila queda vencida y se reintenta mañana)", in_array( 'trial_guarda_error', $w->log_mp_tipos(), true ) );
	t( "solo-log, {$nombre}: solo GET a Mercado Pago", solo_gets_a_mp() );
}
$w = preparar_trials( array( trial_row( 4 ) ), '1' );
mp_pago_trial( '90004', 555 );
mp_subs_payer( 555, array() );
CN_Auth::revocar_trials_vencidos();
t( 'la decisión sin_pago en solo-log NO cancela (hoy tampoco cancelaba: la guarda fallaba)', ! cancelaciones( $w ) && ! $GLOBALS['cn_mails'] );
// Excepción en la guarda: tampoco cambia nada.
$w = preparar_trials( array( trial_row( 4 ) ), '1' );
cn_http_on( 'GET', '#/v1/payments/#', function () {
	throw new RuntimeException( 'explotó' );
} );
CN_Auth::revocar_trials_vencidos();
t( 'si la guarda lanza una excepción: la socia no se toca y el cron sigue', ! $w->escrituras( 'cn_miembros' ) && 1 === count( log_payloads( $w, 'trial_guarda_decision' ) ) );

seccion( 'guarda ACTUANDO (cn_guarda_trial_solo_log = 0): hay suscripción authorized del mismo payer_id' );
$w = preparar_trials( array( trial_row( 6 ) ) );
mp_pago_trial( '90006', 777 );
mp_subs_payer( 777, array(
	sub_mp( 'SUBDEOTRA000000000001', 'authorized', 999 ), // otro payer: el filtro de MP no se respeta a ciegas
	sub_mp( 'SUBPROPIA000000000002', 'authorized', 777 ),
) );
CN_Auth::revocar_trials_vencidos();
t( 'con suscripción authorized: NO cancela, limpia fecha_fin_trial y deja aviso en el cartel (sin n8n ni mail)', ! cancelaciones( $w ) && 1 === count( limpiezas( $w ) ) && ! alertas( 'trial_pago_directo' ) && ! $GLOBALS['cn_mails'] && in_array( CN_Webhook::TIPO_AVISO, $w->log_mp_tipos(), true ) );
t( 'deja log trial_pago_directo y la decisión', in_array( 'trial_pago_directo', $w->log_mp_tipos(), true ) && 'pago' === log_payloads( $w, 'trial_guarda_decision' )[0]['resultado'] );
t( 'no vincula nada ni toca estado/plan/preapproval', ! array_filter( $w->escrituras( 'cn_miembros' ), function ( $e ) {
	return isset( $e[2]['estado'] ) || isset( $e[2]['preapproval_id'] ) || isset( $e[2]['plan'] );
} ) );
t( 'solo GET a MP, y son exactamente los dos: payment y preapproval/search?payer_id', solo_gets_a_mp() && 1 === count( cn_http_calls( '#/v1/payments/90006$#' ) ) && 1 === count( cn_http_calls( '#/preapproval/search\?.*payer_id=777#' ) ) && ! cn_http_calls( '#payer\.email|payer_email#' ) );

seccion( 'guarda ACTUANDO: sin suscripción -> cancela como siempre' );
foreach ( array(
	'el payer no tiene ninguna suscripción'            => array(),
	'solo suscripciones pausada/cancelada'              => array( sub_mp( 'SUBPAUSADA00000000003', 'paused', 777 ), sub_mp( 'SUBCANCEL00000000004', 'cancelled', 777 ) ),
	'solo una authorized de OTRO payer'                 => array( sub_mp( 'SUBDEOTRA000000000001', 'authorized', 999 ) ),
) as $nombre => $subs ) {
	$w = preparar_trials( array( trial_row( 5 ) ) );
	mp_pago_trial( '90005', 777 );
	mp_subs_payer( 777, $subs );
	CN_Auth::revocar_trials_vencidos();
	t( "{$nombre}: se cancela y se manda el mail de fin de trial", 1 === count( cancelaciones( $w ) ) && 1 === count( $GLOBALS['cn_mails'] ) && 'sin_pago' === log_payloads( $w, 'trial_guarda_decision' )[0]['resultado'] );
}

seccion( 'guarda ACTUANDO: indeterminado / error -> NUNCA cancela (no se adivina)' );
$w = preparar_trials( array( trial_row( 7, array( 'trial_payment_id' => null ) ) ) );
CN_Auth::revocar_trials_vencidos();
$d = log_payloads( $w, 'trial_guarda_decision' );
t( 'sin trial_payment_id: indeterminado, no cancela y no consulta a MP', ! $w->escrituras( 'cn_miembros' ) && 'indeterminado' === $d[0]['resultado'] && ! $GLOBALS['cn_http_log'] );
$w = preparar_trials( array( trial_row( 7 ) ) );
mp_pago_trial( '90007', null );
CN_Auth::revocar_trials_vencidos();
t( 'el cobro no trae payer.id: indeterminado, no cancela', ! $w->escrituras( 'cn_miembros' ) && 'indeterminado' === log_payloads( $w, 'trial_guarda_decision' )[0]['resultado'] && ! cn_http_calls( '#/preapproval/search#' ) );
$w = preparar_trials( array( trial_row( 7 ) ) );
cn_http_on( 'GET', '#/v1/payments/90007$#', array( 'id' => 90007, 'payer' => array( 'id' => '0' ) ) );
CN_Auth::revocar_trials_vencidos();
t( "payer.id '0' o no numérico: indeterminado", ! $w->escrituras( 'cn_miembros' ) && 'indeterminado' === log_payloads( $w, 'trial_guarda_decision' )[0]['resultado'] );
$w = preparar_trials( array( trial_row( 7 ) ) );
mp_pago_trial( '90007', 777 );
mp_subs_payer( 777, array( sub_mp( 'SUBA0000000000000001', 'cancelled', 777 ) ), 250 ); // MP dice que hay 250 y devolvió 1
CN_Auth::revocar_trials_vencidos();
t( 'más resultados que los devueltos en la página: indeterminado, no cancela', ! $w->escrituras( 'cn_miembros' ) && 'indeterminado' === log_payloads( $w, 'trial_guarda_decision' )[0]['resultado'] );
$w = preparar_trials( array( trial_row( 7 ) ) ); // sin reglas de MP: todo falla
CN_Auth::revocar_trials_vencidos();
t( 'el cobro del trial no se puede leer (MP caído): error, no cancela, no limpia, no manda mail, no abre transacción', ! $w->escrituras( 'cn_miembros' ) && ! $GLOBALS['cn_mails'] && ! $w->transaccion() && 'error' === log_payloads( $w, 'trial_guarda_decision' )[0]['resultado'] );
t( 'deja rastro trial_guarda_error', in_array( 'trial_guarda_error', $w->log_mp_tipos(), true ) );
$w = preparar_trials( array( trial_row( 7 ) ) );
mp_pago_trial( '90007', 777 );
cn_http_on( 'GET', '#/preapproval/search#', 401 );
CN_Auth::revocar_trials_vencidos();
t( 'el cobro se lee pero preapproval/search falla: error, no cancela', ! $w->escrituras( 'cn_miembros' ) && ! $GLOBALS['cn_mails'] && 'error' === log_payloads( $w, 'trial_guarda_decision' )[0]['resultado'] );
$w = preparar_trials( array( trial_row( 7 ) ) );
mp_pago_trial( '90007', 777 );
mp_subs_payer( 777, array() );
CN_Auth::revocar_trials_vencidos();
t( 'próxima corrida con MP andando y sin suscripción: ahora sí cancela', 1 === count( cancelaciones( $w ) ) );
$w = preparar_trials( array( trial_row( 8 ), trial_row( 9 ) ) );
mp_pago_trial( '90009', 888 );
mp_subs_payer( 888, array() );
CN_Auth::revocar_trials_vencidos(); // la 8 falla (sin reglas), la 9 anda
$c = cancelaciones( $w );
t( 'una fila con MP caído no frena a las demás (solo cancela la 9)', 1 === count( $c ) && array( 'id' => 9 ) === $c[0][3] );

seccion( "comparación estricta de preapproval_id ('' vacío sí va por la guarda)" );
$w = preparar_trials( array( trial_row( 12, array( 'preapproval_id' => '' ) ) ) );
mp_pago_trial( '900012', 321 );
mp_subs_payer( 321, array() );
CN_Auth::revocar_trials_vencidos();
t( "preapproval_id '' (vacío): sigue el camino de trial sin suscripción", 1 === count( cancelaciones( $w ) ) );

seccion( 'carrera: se vinculó una suscripción mientras corría el cron' );
$w = preparar_trials( array( trial_row( 13 ) ) );
mp_pago_trial( '900013', 321 );
mp_subs_payer( 321, array() );
$w->on( '/SELECT id, estado, preapproval_id, fecha_fin_trial FROM wp_cn_miembros WHERE id = 13 FOR UPDATE/', array( cn_fila( array( 'id' => 13, 'estado' => 'activo', 'preapproval_id' => 'PRE_RECIEN_VINCULADA01', 'fecha_fin_trial' => null ) ) ) );
CN_Auth::revocar_trials_vencidos();
t( 'la relectura con FOR UPDATE ve la suscripción nueva: ROLLBACK, no cancela, no manda mail', ! cancelaciones( $w ) && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! $GLOBALS['cn_mails'] );
$w = preparar_trials( array( trial_row( 14 ) ) );
mp_pago_trial( '900014', 321 );
mp_subs_payer( 321, array() );
$w->on( '/SELECT id, estado, preapproval_id, fecha_fin_trial FROM wp_cn_miembros WHERE id = 14 FOR UPDATE/', array( cn_fila( array( 'id' => 14, 'estado' => 'cancelada', 'preapproval_id' => null, 'fecha_fin_trial' => '2026-10-12 10:00:00' ) ) ) );
CN_Auth::revocar_trials_vencidos();
t( 'ya cancelada por otro camino: no se vuelve a cancelar ni se manda otro mail', ! cancelaciones( $w ) && ! $GLOBALS['cn_mails'] );

seccion( 'funciones puras de la guarda' );
t( 'suscripcion_authorized_de_payer: exige authorized Y el mismo payer_id', null === CN_Sync::suscripcion_authorized_de_payer( array( sub_mp( 'A', 'paused', 5 ), sub_mp( 'B', 'authorized', 6 ) ), 5 ) && 'C' === CN_Sync::suscripcion_authorized_de_payer( array( sub_mp( 'C', 'authorized', 5 ) ), '5' )['id'] );
$ref = CN_Sync::referencia_trial( array(), cn_fila( array( 'trial_payment_id' => '777', 'fecha_fin_trial' => '2026-10-12 10:00:00' ) ) );
t( 'referencia_trial (sin uso en el cron actual, se conserva): fin de trial - 7 días', gmdate( 'Y-m-d H:i:s', $ref ) === '2026-10-05 10:00:00', gmdate( 'Y-m-d H:i:s', $ref ) );
