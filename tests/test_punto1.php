<?php
// Punto 1: vincular suscripción, propuesta por email y duplicados.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
const PRE = 'abcdef0123456789abcd';
function pre_mp( $status = 'authorized', $monto = 15500, $extra = array() ) {
	return array_merge( array(
		'id'                => PRE,
		'status'            => $status,
		'payer_email'       => 'marcela@example.com',
		'reason'            => 'Club NATUREZA',
		'next_payment_date' => '2026-11-10T12:00:00.000-03:00',
		'auto_recurring'    => array( 'transaction_amount' => $monto ),
	), $extra );
}
function socia( $id, $extra = array() ) {
	return cn_fila( array_merge( array(
		'id' => $id, 'nombre_apellido' => 'Marcela Palomo', 'estado' => 'activo', 'preapproval_id' => null, 'plan' => null,
		'acceso_hasta' => null, 'email' => 'marcela@example.com', 'fecha_fin_trial' => '2026-10-14 04:06:41',
		'celular_texto_plano' => '1155556641', 'celular_hint' => '6641', 'fecha_alta' => '2026-10-07 01:00:00',
	), $extra ) );
}
function preparar( $socia, $vinculada = null ) {
	cn_test_reset();
	global $wpdb;
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	$wpdb->on( '/FROM wp_cn_miembros WHERE id = ' . ( $socia ? $socia->id : 77 ) . '\b/', $socia ? array( $socia ) : array() );
	$wpdb->on( '/WHERE preapproval_id = /', $vinculada ? array( $vinculada ) : array() );
	return $wpdb;
}
function escrituras_miembros() {
	return $GLOBALS['wpdb']->escrituras( 'cn_miembros' );
}

seccion( 'plan_por_monto' );
t( '15500 -> basico', 'basico' === CN_Webhook::plan_por_monto( 15500 ) );
t( '15000 (plan viejo) -> basico', 'basico' === CN_Webhook::plan_por_monto( 15000 ) );
t( '32000 -> full', 'full' === CN_Webhook::plan_por_monto( 32000 ) );
t( '"32000.00" -> full', 'full' === CN_Webhook::plan_por_monto( '32000.00' ) );
t( '15500.0 float -> basico', 'basico' === CN_Webhook::plan_por_monto( 15500.0 ) );
t( '7000 -> null', null === CN_Webhook::plan_por_monto( 7000 ) );
t( '15500.5 -> null', null === CN_Webhook::plan_por_monto( 15500.5 ) );
t( '"abc" -> null', null === CN_Webhook::plan_por_monto( 'abc' ) );
t( 'null -> null', null === CN_Webhook::plan_por_monto( null ) );

seccion( 'vinc_decidir (decisión pura)' );
$dec = function ( $vinc, $socia ) {
	return cn_llamar( 'CN_Webhook', 'vinc_decidir', $vinc, $socia, PRE )[0];
};
t( 'sin socia -> inexistente (nunca se crea)', 'inexistente' === $dec( null, null ) );
t( 'socia sin preapproval (null) -> activada', 'activada' === $dec( null, socia( 1 ) ) );
t( "socia con '' -> activada", 'activada' === $dec( null, socia( 1, array( 'preapproval_id' => '' ) ) ) );
t( 'mismo preapproval -> completada', 'completada' === $dec( socia( 1, array( 'preapproval_id' => PRE ) ), socia( 1, array( 'preapproval_id' => PRE ) ) ) );
t( 'otro preapproval -> conflicto', 'conflicto' === $dec( null, socia( 1, array( 'preapproval_id' => 'OTRO0000000000000000' ) ) ) );
t( "preapproval '0' cuenta como valor (conflicto, no 'vacío')", 'conflicto' === $dec( null, socia( 1, array( 'preapproval_id' => '0' ) ) ) );
t( 'preapproval ya en OTRA socia -> rechazada', 'rechazada' === $dec( socia( 2, array( 'preapproval_id' => PRE ) ), socia( 1 ) ) );

seccion( 'vincular_suscripcion: activa a una socia en trial' );
$w = preparar( socia( 5 ) );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp( 'authorized', 15500 ) );
$r = CN_Webhook::vincular_suscripcion( PRE, 5 );
$esc = escrituras_miembros();
t( 'ok y acción activada', $r['ok'] && 'activada' === $r['accion'], json_encode( $r ) );
t( 'una sola escritura sobre miembros', 1 === count( $esc ) && 'update' === $esc[0][0] );
$d = $esc ? $esc[0][2] : array();
t( 'estado activo', ( $d['estado'] ?? '' ) === 'activo' );
t( 'preapproval_id seteado', ( $d['preapproval_id'] ?? '' ) === PRE );
t( 'plan basico por monto 15500', ( $d['plan'] ?? '' ) === 'basico' );
t( 'acceso_hasta = next_payment_date en UTC', ( $d['acceso_hasta'] ?? '' ) === '2026-11-10 15:00:00', (string) ( $d['acceso_hasta'] ?? '' ) );
t( 'fecha_fin_trial = NULL', array_key_exists( 'fecha_fin_trial', $d ) && null === $d['fecha_fin_trial'] );
t( 'where por id', ( $esc ? $esc[0][3] : array() ) === array( 'id' => 5 ) );
t( 'transacción START..COMMIT, sin ROLLBACK', $w->transaccion() === array( 'START TRANSACTION', 'COMMIT' ), json_encode( $w->transaccion() ) );
t( 'lee con FOR UPDATE', (bool) array_filter( $w->consultas, function ( $q ) {
	return false !== strpos( $q, 'FOR UPDATE' ) && false !== strpos( $q, 'WHERE id = 5' );
} ) );
t( 'sin insert (nunca crea socias)', ! array_filter( $w->log, function ( $l ) {
	return 'insert' === $l[0] && false !== strpos( $l[1], 'cn_miembros' );
} ) );
t( 'por defecto no manda mail ni Meta', ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] );
t( 'deja log de auditoría', in_array( 'sub_vinculada_admin', $w->log_mp_tipos(), true ) );

seccion( 'vincular_suscripcion: notificar solo si se pide' );
preparar( socia( 5 ) );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp( 'authorized', 32000 ) );
$r = CN_Webhook::vincular_suscripcion( PRE, 5, true );
t( 'plan full por monto 32000', 'full' === $r['plan'] );
t( 'con notificar=true manda 1 mail y 1 evento Meta (después del COMMIT)', 1 === count( $GLOBALS['cn_mails'] ) && 1 === count( $GLOBALS['cn_capi'] ) );
t( 'event_id estable vinc_<preapproval>', 'vinc_' . PRE === ( $GLOBALS['cn_capi'][0][0] ?? '' ) );
preparar( socia( 5, array( 'celular_texto_plano' => null ) ) );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp() );
$r = CN_Webhook::vincular_suscripcion( PRE, 5, true );
t( 'sin celular guardado no manda mail ni Meta, pero vincula', $r['ok'] && ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] );

seccion( 'vincular_suscripcion: idempotente' );
$ya = socia( 5, array( 'preapproval_id' => PRE, 'plan' => 'basico', 'acceso_hasta' => '2026-11-10 15:00:00', 'fecha_fin_trial' => null ) );
$w  = preparar( $ya, $ya );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp() );
$r = CN_Webhook::vincular_suscripcion( PRE, 5, true );
t( 'segunda vez: completada, ok', $r['ok'] && 'completada' === $r['accion'], json_encode( $r ) );
t( 'no escribe nada en miembros', ! escrituras_miembros() );
t( 'no manda mail ni Meta', ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] );
$ya2 = socia( 5, array( 'preapproval_id' => PRE, 'plan' => null, 'acceso_hasta' => null, 'fecha_fin_trial' => null ) );
$w   = preparar( $ya2, $ya2 );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp() );
$r   = CN_Webhook::vincular_suscripcion( PRE, 5 );
$esc = escrituras_miembros();
t( 'completada: solo completa plan y acceso_hasta NULL', 'completada' === $r['accion'] && 1 === count( $esc ) && array_keys( $esc[0][2] ) === array( 'plan', 'acceso_hasta' ) );

seccion( 'vincular_suscripcion: nunca pisa otro preapproval' );
$w = preparar( socia( 5, array( 'preapproval_id' => 'OTRO0000000000000000', 'plan' => 'full' ) ) );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp() );
cn_http_on( 'POST', '#club-trial-alerta#', array() );
$r = CN_Webhook::vincular_suscripcion( PRE, 5 );
t( 'conflicto, ok=false', ! $r['ok'] && 'conflicto' === $r['accion'] );
t( 'ROLLBACK y cero escrituras en miembros', ! escrituras_miembros() && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! in_array( 'COMMIT', $w->transaccion(), true ) );
t( 'log sub_conflicto', in_array( 'sub_conflicto', $w->log_mp_tipos(), true ) );
$av = cn_http_calls( '#club-trial-alerta#' );
t( 'alerta a n8n con motivo sub_conflicto', 1 === count( $av ) && false !== strpos( (string) $av[0]['body'], 'sub_conflicto' ) );

seccion( 'vincular_suscripcion: rechazos (no escribe nada)' );
$w = preparar( null );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp() );
$r = CN_Webhook::vincular_suscripcion( PRE, 77 );
t( 'socia inexistente: rechazada y NO se crea', ! $r['ok'] && 'rechazada' === $r['accion'] && ! escrituras_miembros() );
$w = preparar( socia( 5 ), socia( 9, array( 'preapproval_id' => PRE ) ) );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp() );
$r = CN_Webhook::vincular_suscripcion( PRE, 5 );
t( 'preapproval ya en otra socia: rechazada, sin escrituras', ! $r['ok'] && ! escrituras_miembros() && in_array( 'ROLLBACK', $w->transaccion(), true ) );
$w = preparar( socia( 5 ) ); // MP no responde (sin regla)
$r = CN_Webhook::vincular_suscripcion( PRE, 5 );
t( 'MP falla: no abre transacción ni cambia estado', ! $r['ok'] && ! $w->transaccion() && ! escrituras_miembros() );
$w = preparar( socia( 5 ) );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', 500 );
$r = CN_Webhook::vincular_suscripcion( PRE, 5 );
t( 'MP 500: no cambia nada', ! $r['ok'] && ! $w->transaccion() && ! escrituras_miembros() );
foreach ( array( 'paused', 'cancelled', 'pending' ) as $st ) {
	$w = preparar( socia( 5 ) );
	cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp( $st ) );
	$r = CN_Webhook::vincular_suscripcion( PRE, 5 );
	t( "status {$st}: solo se vinculan las authorized", ! $r['ok'] && ! escrituras_miembros() );
}
$w = preparar( socia( 5 ) );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp( 'authorized', 7000 ) );
$r = CN_Webhook::vincular_suscripcion( PRE, 5 );
t( 'monto desconocido ($7.000): rechazada', ! $r['ok'] && ! escrituras_miembros() );
$w = preparar( socia( 5 ) );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp( 'authorized', 15500, array( 'id' => 'OTROID00000000000000' ) ) );
$r = CN_Webhook::vincular_suscripcion( PRE, 5 );
t( 'MP devuelve otro id: rechazada', ! $r['ok'] && ! escrituras_miembros() );
$w = preparar( socia( 5 ) );
$r = CN_Webhook::vincular_suscripcion( 'corto', 5 );
t( 'id con formato inválido: rechazada sin llamar a MP', ! $r['ok'] && ! cn_http_calls( '#preapproval#' ) );
$w = preparar( socia( 5 ) );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp() );
$r = CN_Webhook::vincular_suscripcion( PRE, 0 );
t( 'sin socia elegida: rechazada', ! $r['ok'] && ! escrituras_miembros() );

seccion( 'vincular_suscripcion_verificar: solo lectura' );
$w = preparar( socia( 5 ) );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp() );
$v = CN_Webhook::vincular_suscripcion_verificar( PRE, 5 );
t( 'predice activada (caso trial)', $v['ok'] && 'activada' === $v['accion_prevista'] && 'trial' === $v['caso'], json_encode( $v ) );
t( 'no escribe ni abre transacción ni usa FOR UPDATE', ! $w->log && ! array_filter( $w->consultas, function ( $q ) {
	return false !== strpos( $q, 'FOR UPDATE' );
} ) );
$w = preparar( socia( 5, array( 'preapproval_id' => 'OTRO0000000000000000' ) ) );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp() );
t( 'predice conflicto', 'conflicto' === CN_Webhook::vincular_suscripcion_verificar( PRE, 5 )['accion_prevista'] );
$w = preparar( null );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp() );
$v = CN_Webhook::vincular_suscripcion_verificar( PRE, 77 );
t( 'socia inexistente: error, no ofrece crear', ! $v['ok'] && '' !== $v['error'] );

seccion( 'CN_Vinculo::clasificar / proponer' );
$s_a = socia( 1, array( 'email' => 'ana@example.com', 'nombre_apellido' => 'Ana' ) );
$s_b = socia( 2, array( 'email' => 'bea@example.com', 'nombre_apellido' => 'Bea', 'preapproval_id' => 'YAVINCULADA0000000000' ) );
$s_c = socia( 3, array( 'email' => 'Dup@Example.com', 'nombre_apellido' => 'Dora' ) );
$s_d = socia( 4, array( 'email' => 'dup@example.com', 'nombre_apellido' => 'Dora Bis' ) );
$todas = array( $s_a, $s_b, $s_c, $s_d );
cn_test_reset();
$items = array(
	array( 'id' => 'SUBEXACTA000000000001', 'payer_email' => 'ANA@example.com', 'auto_recurring' => array( 'transaction_amount' => 15500 ), 'next_payment_date' => '2026-11-10T00:00:00.000-03:00' ),
	array( 'id' => 'SUBVARIAS000000000002', 'payer_email' => 'dup@example.com', 'auto_recurring' => array( 'transaction_amount' => 32000 ) ),
	array( 'id' => 'SUBSINMATCH0000000003', 'payer_email' => 'nadie@example.com', 'auto_recurring' => array( 'transaction_amount' => 15000 ) ),
	array( 'id' => 'YAVINCULADA0000000000', 'payer_email' => 'bea@example.com', 'auto_recurring' => array( 'transaction_amount' => 15500 ) ),
	array( 'id' => 'SUBSINEMAIL0000000004', 'auto_recurring' => array( 'transaction_amount' => 15500 ) ),
);
update_option( 'cn_mp_access_token', 'APP_USR-test' );
cn_http_on( 'GET', '#authorized_payments/search#', array( 'results' => array() ) ); // sin cobros: no se puede resolver
$p = CN_Vinculo::proponer( $items, $todas, array() );
$c = array_column( $p['filas'], 'confianza', 'id' );
t( 'email exacto (insensible a mayúsculas)', CN_Vinculo::EMAIL_EXACTO === $c['SUBEXACTA000000000001'] );
t( 'varias socias con el mismo email', CN_Vinculo::VARIAS_SOCIAS === $c['SUBVARIAS000000000002'] );
t( 'sin match', CN_Vinculo::SIN_MATCH === $c['SUBSINMATCH0000000003'] );
t( 'ya vinculada', CN_Vinculo::YA_VINCULADA === $c['YAVINCULADA0000000000'] );
t( 'sin email', CN_Vinculo::SIN_EMAIL === $c['SUBSINEMAIL0000000004'] );
$f = array_column( $p['filas'], null, 'id' );
t( 'plan por monto en la propuesta', 'basico' === $f['SUBEXACTA000000000001']['plan'] && 'full' === $f['SUBVARIAS000000000002']['plan'] && 'basico' === $f['SUBSINMATCH0000000003']['plan'] );
t( 'socia propuesta = id 1', 1 === (int) $f['SUBEXACTA000000000001']['socia']->id );
t( 'varias: lista las 2 candidatas', 2 === count( $f['SUBVARIAS000000000002']['candidatas'] ) );
t( 'celular enmascarado', '****6641' === CN_Vinculo::mascara_celular( '6641' ) );
$sin_aviso = CN_Vinculo::proponer( array( array( 'id' => 'SUBEXACTAOTRA0000005', 'payer_email' => 'bea@example.com', 'auto_recurring' => array( 'transaction_amount' => 15500 ) ) ), $todas );
t( 'email exacto pero la socia ya tiene otra suscripción: avisa', '' !== $sin_aviso['filas'][0]['aviso'] );

seccion( 'CN_Vinculo: de dónde sale el email (suscripción > cobro > authorized_payments, tope 30)' );
cn_test_reset();
update_option( 'cn_mp_access_token', 'APP_USR-test' );
$idx = CN_Vinculo::indexar_pagos( array(
	array( 'payer' => array( 'email' => 'Vieja@Example.com' ), 'date_created' => '2026-10-01T10:00:00.000-03:00', 'point_of_interaction' => array( 'transaction_data' => array( 'preapproval_id' => 'SUBPORCOBRO000000000006' ) ) ),
	array( 'payer' => array( 'email' => 'nueva@example.com' ), 'date_created' => '2026-10-09T10:00:00.000-03:00', 'metadata' => array( 'preapproval_id' => 'SUBPORCOBRO000000000006' ) ),
	array( 'payer' => array( 'email' => 'porref@example.com' ), 'date_created' => '2026-10-05T10:00:00.000-03:00', 'external_reference' => 'sub_ref_1' ),
) );
$extra = 0;
$r = CN_Vinculo::resolver_email( array( 'id' => 'SUBPORCOBRO000000000006' ), $idx, $extra, 30 );
t( 'sin email en la suscripción: usa el cobro aprobado MÁS RECIENTE', 'nueva@example.com' === $r['email'] && 'cobro' === $r['fuente'] && 0 === $extra );
$r = CN_Vinculo::resolver_email( array( 'id' => 'SUBPORREF0000000000007', 'external_reference' => 'sub_ref_1' ), $idx, $extra, 30 );
t( 'cobro asociado por external_reference', 'porref@example.com' === $r['email'] );
cn_http_on( 'GET', '#authorized_payments/search#', array( 'results' => array( array( 'payment' => array( 'id' => 555 ) ) ) ) );
cn_http_on( 'GET', '#/v1/payments/555#', array( 'payer' => array( 'email' => 'APPay@example.com' ) ) );
$extra = 0;
$r = CN_Vinculo::resolver_email( array( 'id' => 'SUBPORAUTH0000000000008' ), array( 'por_preapproval' => array(), 'por_ref' => array() ), $extra, 30 );
t( 'último recurso: authorized_payments + cobro (2 consultas)', 'appay@example.com' === $r['email'] && 'authorized_payments' === $r['fuente'] && 2 === $extra );
cn_http_on( 'GET', '#authorized_payments/search#', array( 'results' => array() ) );
$muchas = array();
for ( $i = 0; $i < 45; $i++ ) {
	$muchas[] = array( 'id' => sprintf( 'SUBTOPE%015d', $i ), 'auto_recurring' => array( 'transaction_amount' => 15500 ) );
}
$GLOBALS['cn_http_log'] = array();
$p = CN_Vinculo::proponer( $muchas, array() );
t( 'tope: nunca más de 30 consultas extra', 30 === $p['extra_usadas'] && 30 === count( cn_http_calls( '#authorized_payments/search#' ) ), (string) count( cn_http_calls( '#authorized_payments/search#' ) ) );
t( 'tope alcanzado se informa', true === $p['tope_alcanzado'] && 30 === $p['tope'] );
t( 'las 45 filas siguen apareciendo', 45 === count( $p['filas'] ) );

seccion( 'CN_Vinculo::duplicados' );
$socias = array(
	socia( 1, array( 'nombre_apellido' => 'María Pérez', 'email' => 'a@x.com' ) ),
	socia( 2, array( 'nombre_apellido' => 'maria  PEREZ', 'email' => 'b@x.com' ) ),
	socia( 3, array( 'nombre_apellido' => 'Otra Persona', 'email' => 'A@X.com ' ) ),
	socia( 4, array( 'nombre_apellido' => 'Sin Email', 'email' => null ) ),
	socia( 5, array( 'nombre_apellido' => 'Único', 'email' => 'u@x.com' ) ),
);
$d = CN_Vinculo::duplicados( $socias );
t( 'mismo email (sin importar mayúsculas/espacios)', 1 === count( $d['por_email'] ) && 2 === count( reset( $d['por_email'] ) ) );
t( 'nombre normalizado (sin acentos, mayúsculas ni espacios dobles)', 1 === count( $d['por_nombre'] ) && 'maria perez' === array_key_first( $d['por_nombre'] ) );
t( 'sin email no agrupa por email', ! isset( $d['por_email'][''] ) );

seccion( 'HTML de las pantallas (prueba de humo, sin errores de PHP)' );
cn_test_reset();
update_option( 'cn_mp_access_token', 'APP_USR-test' );
$prop = CN_Vinculo::proponer( $items, $todas, array() );
ob_start();
cn_llamar( 'CN_Admin', 'bloque_propuesta_vinculo', $prop );
$html = ob_get_clean();
t( 'propuesta: muestra ID completo, plan, confianza y enlace Vincular con socia propuesta', false !== strpos( $html, 'SUBEXACTA000000000001' ) && false !== strpos( $html, 'email exacto' ) && false !== strpos( $html, 'cn_vincular_socia=1' ) );
t( 'propuesta: no hay botón de vincular para "ya vinculada"', 1 === substr_count( $html, 'YAVINCULADA0000000000</code>' ) && 4 === substr_count( $html, 'Vincular</a>' ), (string) substr_count( $html, 'Vincular</a>' ) );
t( 'propuesta: celular enmascarado y sin datos completos', false !== strpos( $html, '****6641' ) && false === strpos( $html, '1155556641' ) );
ob_start();
cn_llamar( 'CN_Admin', 'bloque_duplicados', $socias );
$html = ob_get_clean();
t( 'duplicados: desplegable con los dos grupos', false !== strpos( $html, '<details' ) && false !== strpos( $html, 'maria perez' ) && false !== strpos( $html, '1 por email' ) );
$GLOBALS['wpdb']->on( '/SELECT id, nombre_apellido, estado, email, celular_hint/', array( socia( 5 ), socia( 6, array( 'nombre_apellido' => 'Ana' ) ) ) );
ob_start();
cn_llamar( 'CN_Admin', 'bloque_vincular_mp', null );
$html = ob_get_clean();
t( 'vincular: selector buscable con las socias, sin campo de plan ni de nombre/celular', false !== strpos( $html, '<datalist' ) && false !== strpos( $html, '#5 · Marcela Palomo' ) && false === strpos( $html, 'cn_vm_plan' ) && false === strpos( $html, 'cn_vm_celular' ) );
$_GET['cn_vincular_id'] = PRE;
$_GET['cn_vincular_socia'] = '5';
ob_start();
cn_llamar( 'CN_Admin', 'bloque_vincular_mp', null );
$html = ob_get_clean();
unset( $_GET['cn_vincular_id'], $_GET['cn_vincular_socia'] );
t( 'vincular: el enlace del Cruce precarga ID y socia', false !== strpos( $html, 'value="' . PRE . '"' ) && false !== strpos( $html, 'value="#5 · Marcela Palomo' ) );
$_POST = array( 'cn_vm_accion' => 'verificar', 'cn_vm_id' => PRE, 'cn_vm_socia' => '#5 · Marcela Palomo' );
$GLOBALS['wpdb']->on( '/FROM wp_cn_miembros WHERE id = 5\b/', array( socia( 5 ) ) );
cn_http_on( 'GET', '#/preapproval/' . PRE . '#', pre_mp() );
$res = cn_llamar( 'CN_Admin', 'procesar_vincular_mp' );
t( 'procesar verificar: parsea "#5" de la lista y no escribe', 'verificado' === $res['paso'] && 5 === $res['datos']['socia_id'] && ! escrituras_miembros() );
ob_start();
cn_llamar( 'CN_Admin', 'bloque_vincular_mp', $res );
$html = ob_get_clean();
t( 'vincular: tras verificar aparece "Confirmar y vincular" y el checkbox mail/Meta DESMARCADO', false !== strpos( $html, 'Confirmar y vincular' ) && false !== strpos( $html, 'name="cn_vm_notificar" value="1"> Enviar' ) && false === strpos( $html, 'cn_vm_notificar" value="1" checked' ) );
$_POST = array( 'cn_vm_accion' => 'vincular', 'cn_vm_id' => PRE, 'cn_vm_socia_id' => '5' );
$res = cn_llamar( 'CN_Admin', 'procesar_vincular_mp' );
t( 'procesar vincular sin confirmación: no escribe', 'error' === $res['paso'] && ! escrituras_miembros() );
$_POST = array( 'cn_vm_accion' => 'verificar', 'cn_vm_id' => PRE, 'cn_vm_socia' => 'Marcela sin elegir de la lista' );
$res = cn_llamar( 'CN_Admin', 'procesar_vincular_mp' );
t( 'sin elegir una socia de la lista: error', 'error' === $res['paso'] );
$_POST = array();
