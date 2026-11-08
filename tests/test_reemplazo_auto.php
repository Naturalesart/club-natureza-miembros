<?php
// Reemplazo automático (CN_Reemplazo_Auto): modo log por defecto, modo activo, ambiguos, doble pago, MP caído, carreras y topes.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
require_once __DIR__ . '/test_reemplazo.php';
function au_id( $n ) {
	return lt_id( 200 + $n );
}
/** Ficha con suscripción vieja au_id($n*2) y email propio del par. */
function au_ficha( $n, $extra = array() ) {
	return lt_fila( 10 + $n, '11555510' . str_pad( (string) $n, 2, '0', STR_PAD_LEFT ), array_merge( array(
		'nombre_apellido' => 'Socia Par ' . $n, 'estado' => 'activo', 'email' => 'par' . $n . '@example.com', 'preapproval_id' => au_id( $n * 2 ), 'plan' => 'basico',
		'acceso_hasta' => '2026-11-01 00:00:00', 'baja_en' => '2026-10-20 00:00:00',
	), $extra ) );
}
/** Suscripción NUEVA au_id($n*2+1) del par $n, tal como la lista /preapproval/search de MP. */
function au_item( $n, $extra = array() ) {
	return array_merge( lt_mp( au_id( $n * 2 + 1 ) ), array( 'payer_email' => 'par' . $n . '@example.com', 'date_created' => gmdate( 'c', time() - 3 * DAY_IN_SECONDS ) ), $extra );
}
/** Respuestas de MP por id para el par $n. */
function au_subs( $n, $viejo = 'cancelled', $m_viejo = 15500, $m_nuevo = 15500 ) {
	return array( au_id( $n * 2 ) => lt_mp( au_id( $n * 2 ), $viejo, $m_viejo ), au_id( $n * 2 + 1 ) => lt_mp( au_id( $n * 2 + 1 ), 'authorized', $m_nuevo ) );
}
function au_run( $fichas, $items, $subs, $modo = null, $cb = null, $falla_update = false ) {
	$w = lt_preparar( $fichas, $subs );
	cn_http_on( 'GET', '#/authorized_payments/search#', array( 'results' => array( array( 'payment' => array( 'id' => 1, 'status' => 'approved' ), 'debit_date' => '2026-06-05T10:00:00.000-03:00' ) ) ) );
	cn_http_on( 'GET', '#/preapproval/search#', null === $items ? null : array( 'results' => $items, 'paging' => array( 'total' => count( $items ) ) ) );
	if ( null !== $modo ) {
		update_option( CN_Reemplazo_Auto::OPT_MODO, $modo );
	}
	if ( $falla_update ) {
		$n = new RP_Wpdb();
		$n->adoptar( $w );
		$n->falla_update = true;
		$GLOBALS['wpdb'] = $w = $n;
	}
	if ( $cb ) {
		$cb( $w );
	}
	return array( CN_Reemplazo_Auto::correr(), $w );
}
function au_nada( $w ) {
	$t = $w->log_mp_tipos();
	return ! $w->escrituras( 'cn_miembros' ) && ! in_array( 'vinculo_backup', $t, true ) && ! in_array( 'reemplazo_suscripcion', $t, true ) && ! in_array( 'START TRANSACTION', $w->transaccion(), true );
}
function au_solo_get() {
	foreach ( $GLOBALS['cn_http_log'] as $c ) {
		if ( 'GET' !== $c['metodo'] ) {
			return false;
		}
	}
	return true;
}
function au_tipo( $w, $tipo ) {
	return count( array_filter( $w->log_mp_tipos(), function ( $t ) use ( $tipo ) {
		return $t === $tipo;
	} ) );
}
function au_gets_sub( $id ) {
	return count( cn_http_calls( '#/preapproval/' . $id . '#' ) );
}

seccion( 'reemplazo automático: modo log (por defecto)' );
$f1 = au_ficha( 1 );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1 ) );
t( 'sin la opción creada, el modo es log', 'log' === CN_Reemplazo_Auto::modo() && 'log' === $r['modo'] );
t( 'propone una vez y NO escribe en miembros ni abre transacción', 1 === $r['propuestas'] && 0 === $r['aplicadas'] && au_nada( $w ), json_encode( $r ) );
$sim = null;
foreach ( $w->log as $l ) {
	if ( 'insert' === $l[0] && 'reemplazo_auto_simulado' === $l[2]['tipo'] ) {
		$sim = json_decode( $l[2]['payload'], true );
	}
}
t( 'deja reemplazo_auto_simulado con ficha, vieja, nueva y estados de MP', $sim && 11 === $sim['miembro_id'] && au_id( 2 ) === $sim['preapproval_viejo'] && au_id( 3 ) === $sim['preapproval_nuevo'] && 'cancelled' === $sim['estado_mp_viejo'] && 'authorized' === $sim['estado_mp_nuevo'] && 'log' === $sim['modo'], json_encode( $sim ) );
t( 'solo GET a MP; cero mails y cero Meta', au_solo_get() && ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1 ), 'cualquier-cosa' );
t( 'un valor raro de la opción cae en log', 'log' === $r['modo'] && au_nada( $w ) && 1 === $r['propuestas'] );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1 ), 'log' );
list( $r2 ) = array( CN_Reemplazo_Auto::correr() );
t( 'segunda vuelta: no reevalúa (reposo) ni repite el log', 1 === au_tipo( $w, 'reemplazo_auto_simulado' ) && 1 === au_gets_sub( au_id( 3 ) ) );

seccion( 'reemplazo automático: modo activo (caso feliz)' );
$GLOBALS['cn_transients'] = array();
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1 ), 'activo', function () {
	set_transient( CN_Sin_Vincular::TRANSIENT, array( 'ok' => true ) );
} );
$esc = $w->escrituras( 'cn_miembros' );
t( 'aplica un reemplazo', 1 === $r['aplicadas'] && 'activo' === $r['modo'], json_encode( $r ) );
t( 'un solo UPDATE: preapproval nuevo, plan, activo, baja_en NULL, acceso = próximo cobro', 1 === count( $esc ) && au_id( 3 ) === $esc[0][2]['preapproval_id'] && 'basico' === $esc[0][2]['plan'] && 'activo' === $esc[0][2]['estado'] && null === $esc[0][2]['baja_en'] && '2026-11-10 15:00:00' === $esc[0][2]['acceso_hasta'] && array( 'id' => 11, 'preapproval_id' => au_id( 2 ) ) === $esc[0][3], json_encode( $esc ) );
t( 'backup y log reemplazo_suscripcion con origen auto, sin usuario', array( 'vinculo_backup', 'reemplazo_suscripcion' ) === $w->log_mp_tipos() );
$lg = null;
foreach ( $w->log as $l ) {
	if ( 'insert' === $l[0] && 'reemplazo_suscripcion' === $l[2]['tipo'] ) {
		$lg = json_decode( $l[2]['payload'], true );
	}
}
t( 'el log dice origen auto, ids viejo y nuevo', $lg && 'auto' === $lg['origen'] && au_id( 2 ) === $lg['preapproval_viejo'] && au_id( 3 ) === $lg['preapproval_nuevo'] );
$q = $w->transaccion();
t( 'una transacción con FOR UPDATE y COMMIT', 'START TRANSACTION' === $q[0] && 'COMMIT' === end( $q ) && ! in_array( 'ROLLBACK', $q, true ) && 0 < count( array_filter( $w->consultas, function ( $s ) {
	return false !== strpos( $s, 'id = 11' ) && false !== strpos( $s, 'FOR UPDATE' );
} ) ) );
t( 'solo GET, sin mails ni Meta, y borra las cachés de Sin vincular', au_solo_get() && ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] && false === get_transient( CN_Sin_Vincular::TRANSIENT ) );
$hecha = au_ficha( 1, array( 'preapproval_id' => au_id( 3 ), 'baja_en' => null ) );
list( $r, $w ) = au_run( array( $hecha ), array( au_item( 1 ) ), au_subs( 1 ), 'activo' );
t( 'idempotente: si la ficha ya tiene la nueva (vinculada) no hace nada ni consulta las suscripciones', 0 === $r['evaluadas'] && au_nada( $w ) && 0 === au_gets_sub( au_id( 3 ) ) );

seccion( 'reemplazo automático: ambiguos y fuera de alcance' );
$a = au_ficha( 1 );
$b = au_ficha( 1, array( 'id' => 99, 'nombre_apellido' => 'Gemela', 'preapproval_id' => au_id( 40 ) ) );
list( $r, $w ) = au_run( array( $a, $b ), array( au_item( 1 ) ), au_subs( 1 ), 'activo' );
t( '2 fichas con el mismo email y plan: ambiguo, cero escrituras, cero consultas a las suscripciones', 1 === $r['ambiguas'] && au_nada( $w ) && 0 === au_gets_sub( au_id( 3 ) ), json_encode( $r ) );
t( 'queda en mp_log y en el cartel Avisos', 1 === au_tipo( $w, 'reemplazo_auto_ambiguo' ) && 1 === au_tipo( $w, 'aviso_error' ) );
au_run( array( $a, $b ), array( au_item( 1 ) ), au_subs( 1 ), 'activo' );
CN_Reemplazo_Auto::correr();
t( 'no repite el aviso en cada vuelta del sync (1 por día)', 1 === au_tipo( $GLOBALS['wpdb'], 'reemplazo_auto_ambiguo' ) );
$full = au_ficha( 1, array( 'plan' => 'full' ) );
list( $r, $w ) = au_run( array( $full ), array( au_item( 1 ) ), au_subs( 1 ), 'activo' );
t( 'hay ficha con ese email pero de otro plan: 0 candidatas = ambiguo, sin escribir', 1 === $r['ambiguas'] && au_nada( $w ) && 1 === au_tipo( $w, 'reemplazo_auto_ambiguo' ) );
$otra_mail = au_ficha( 1, array( 'email' => 'otra@example.com' ) );
list( $r, $w ) = au_run( array( $otra_mail ), array( au_item( 1 ) ), au_subs( 1 ), 'activo' );
t( 'ninguna ficha con ese email (alta nueva normal): no es un caso de re-suscripción, no registra ni avisa', 0 === $r['ambiguas'] && au_nada( $w ) && ! $w->log_mp_tipos() );
list( $r, $w ) = au_run( array( au_ficha( 1, array( 'email' => 'PAR1@Example.com ' ) ) ), array( au_item( 1 ) ), au_subs( 1 ), 'activo' );
t( 'el email se compara exacto pero normalizado (mayúsculas y espacios)', 1 === $r['aplicadas'] );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1, array( 'date_created' => gmdate( 'c', time() - 600 ) ) ) ), au_subs( 1 ), 'activo' );
t( 'sub nueva de hace menos de 1 hora: no se toca (el alta automática va primero)', 0 === $r['evaluadas'] && au_nada( $w ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1, array( 'payer_email' => '' ) ) ), au_subs( 1 ), 'activo' );
t( 'sub sin email del pagador: no hace nada', 0 === $r['evaluadas'] && au_nada( $w ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1, array( 'auto_recurring' => array( 'transaction_amount' => 9999 ) ) ) ), au_subs( 1 ), 'activo' );
t( 'monto sin plan conocido: no hace nada', 0 === $r['evaluadas'] && au_nada( $w ) );

seccion( 'reemplazo automático: doble pago, pausada, cambio de plan' );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1, 'authorized' ), 'activo' );
t( 'vieja authorized (posible doble pago): no escribe, deja omitido y aviso', au_nada( $w ) && 1 === $r['omitidas'] && 1 === au_tipo( $w, 'reemplazo_auto_omitido' ) && 1 === au_tipo( $w, 'aviso_error' ), json_encode( $r ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1, 'authorized' ), 'log' );
t( 'doble pago en modo log: tampoco propone', au_nada( $w ) && 0 === au_tipo( $w, 'reemplazo_auto_simulado' ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1, 'paused' ), 'activo' );
t( 'vieja paused: el automático no reemplaza (el manual sí)', au_nada( $w ) && 1 === $r['omitidas'] && 0 === au_tipo( $w, 'aviso_error' ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1, 'pending' ), 'activo' );
t( 'vieja en estado raro: no hace nada', au_nada( $w ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1, 'cancelled', 32000, 15500 ), 'activo' );
t( 'cambia el monto entre vieja y nueva: no hace nada (requiere una persona)', au_nada( $w ) && 1 === $r['omitidas'] );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1, array( 'auto_recurring' => array( 'transaction_amount' => 32000 ) ) ) ), au_subs( 1, 'cancelled', 15500, 32000 ), 'activo' );
t( 'la nueva es de otro plan que la ficha: no es candidata (ambiguo)', au_nada( $w ) && 1 === $r['ambiguas'] );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1, 'authorized' ), 'activo' );
$antes = au_gets_sub( au_id( 3 ) );
CN_Reemplazo_Auto::correr();
t( 'reposo: la segunda vuelta no vuelve a consultar la suscripción bloqueada', $antes === au_gets_sub( au_id( 3 ) ) && 1 === au_tipo( $GLOBALS['wpdb'], 'reemplazo_auto_omitido' ) );

seccion( 'reemplazo automático: MP caído o ambiguo' );
list( $r, $w ) = au_run( array( $f1 ), null, au_subs( 1 ), 'activo' );
t( 'MP no responde la lista: no hace nada', null === $r && au_nada( $w ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), array( au_id( 2 ) => null, au_id( 3 ) => lt_mp( au_id( 3 ) ) ), 'activo' );
t( 'MP no responde por la vieja: no escribe, reintenta después (sin reposo)', au_nada( $w ) && 1 === $r['omitidas'] && ! get_transient( 'cn_ra_' . md5( 'rep|' . au_id( 3 ) ) ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), array( au_id( 2 ) => lt_mp( au_id( 2 ), 'cancelled' ), au_id( 3 ) => 500 ), 'activo' );
t( 'MP devuelve 500 por la nueva: no escribe', au_nada( $w ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), array( au_id( 2 ) => array( 'id' => au_id( 2 ), 'auto_recurring' => array( 'transaction_amount' => 15500 ) ), au_id( 3 ) => lt_mp( au_id( 3 ) ) ), 'activo' );
t( 'vieja sin estado en la respuesta (ambigua): no escribe', au_nada( $w ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), array( au_id( 2 ) => lt_mp( au_id( 9 ), 'cancelled' ), au_id( 3 ) => lt_mp( au_id( 3 ) ) ), 'activo' );
t( 'MP devuelve otro ID: no escribe', au_nada( $w ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), array( au_id( 2 ) => lt_mp( au_id( 2 ), 'cancelled' ), au_id( 3 ) => lt_mp( au_id( 3 ), 'paused' ) ), 'activo' );
t( 'la lista la trae authorized pero en vivo está pausada: no escribe', au_nada( $w ) );
cn_test_reset();
$GLOBALS['wpdb']->on( '/FROM wp_cn_miembros$/', array( $f1 ) );
t( 'sin token de MP: no hace nada ni consulta', null === CN_Reemplazo_Auto::correr() && ! $GLOBALS['cn_http_log'] );
t( 'todo fue GET', au_solo_get() );

seccion( 'reemplazo automático: sub nueva vinculada a otra ficha y carreras' );
$tomada = au_ficha( 7, array( 'preapproval_id' => au_id( 3 ) ) );
list( $r, $w ) = au_run( array( $f1, $tomada ), array( au_item( 1 ) ), au_subs( 1 ), 'activo' );
t( 'la nueva ya está vinculada a otra ficha (según la base): no es "sin vincular"', 0 === $r['evaluadas'] && au_nada( $w ) );
$otra = lt_fila( 99, '1155559999', array( 'preapproval_id' => au_id( 3 ) ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1 ), 'activo', function ( $w ) use ( $otra ) { // se vincula entre la lista y la evaluación.
	$w->on( '/WHERE preapproval_id = /', array( $otra ) );
} );
t( 'se vinculó a otra ficha mientras tanto: bloqueado, cero escrituras', au_nada( $w ) && 0 === $r['aplicadas'] );
$ya = au_ficha( 1, array( 'preapproval_id' => au_id( 3 ), 'baja_en' => null ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1 ), 'activo', function ( $w ) use ( $ya ) { // otro proceso se adelantó.
	$w->on( '/WHERE id = 11\b.*FOR UPDATE/', array( $ya ) );
} );
t( 'carrera: la ficha ya fue reemplazada por otro proceso -> ROLLBACK, sin escribir', 0 === count( $w->escrituras( 'cn_miembros' ) ) && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! in_array( 'COMMIT', $w->transaccion(), true ) && ! in_array( 'reemplazo_suscripcion', $w->log_mp_tipos(), true ) );
$cambiada = au_ficha( 1, array( 'acceso_hasta' => '2026-12-31 00:00:00' ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1 ), 'activo', function ( $w ) use ( $cambiada ) { // la ficha cambia después de evaluar.
	$w->on( '/WHERE id = 11\b.*FOR UPDATE/', array( $cambiada ) );
} );
t( 'carrera: la ficha cambió -> ROLLBACK, cero cambios', 0 === $r['aplicadas'] && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! in_array( 'COMMIT', $w->transaccion(), true ) && ! $w->escrituras( 'cn_miembros' ) && ! in_array( 'reemplazo_suscripcion', $w->log_mp_tipos(), true ) );
list( $r, $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1 ), 'activo', null, true );
t( 'falla el UPDATE -> ROLLBACK, sin COMMIT ni log de reemplazo', 0 === $r['aplicadas'] && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! in_array( 'COMMIT', $w->transaccion(), true ) && ! in_array( 'reemplazo_suscripcion', $w->log_mp_tipos(), true ) );

seccion( 'reemplazo automático: tope por corrida' );
$fichas = array();
$items  = array();
$subs   = array();
for ( $n = 1; $n <= 5; $n++ ) {
	$fichas[] = au_ficha( $n );
	$items[]  = au_item( $n );
	$subs     = $subs + au_subs( $n );
}
list( $r, $w ) = au_run( $fichas, $items, $subs, 'activo' );
t( 'activo: 5 casos válidos, aplica 3 como máximo', 3 === $r['aplicadas'] && 3 === count( $w->escrituras( 'cn_miembros' ) ) && 1 === au_tipo( $w, 'reemplazo_auto_tope' ), json_encode( $r ) );
list( $r, $w ) = au_run( $fichas, $items, $subs, 'log' );
t( 'log: propone 3 como máximo y no escribe', 3 === $r['propuestas'] && 3 === au_tipo( $w, 'reemplazo_auto_simulado' ) && au_nada( $w ) );

seccion( 'reemplazo automático: lo llama el sync de 30 minutos' );
list( , $w ) = au_run( array( $f1 ), array( au_item( 1 ) ), au_subs( 1 ) );
$w->log = array();
$GLOBALS['cn_transients'] = array();
CN_Sync::correr();
t( 'CN_Sync::correr() ejecuta el reemplazo automático (en log solo registra)', in_array( 'reemplazo_auto_simulado', $w->log_mp_tipos(), true ) && ! $w->escrituras( 'cn_miembros' ) );
