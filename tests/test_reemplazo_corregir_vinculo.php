<?php
// "Corregir vínculo" (CN_Corregir_Vinculo): cambiar la sub A de una ficha por la sub B, sin tocar nada más.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
require_once __DIR__ . '/test_punto6_lote.php';
function cv_a() {
	return lt_id( 66 );
}
function cv_b() {
	return lt_id( 77 );
}
function cv_ficha( $extra = array() ) {
	return lt_fila( 13, '1155551313', array_merge( array(
		'nombre_apellido' => 'Carla Costa Benitez', 'estado' => 'activo', 'preapproval_id' => cv_a(), 'plan' => 'basico',
		'acceso_hasta' => '2026-11-08 15:00:00', 'baja_en' => '2026-11-20 00:00:00', 'fecha_fin_trial' => null,
	), $extra ) );
}
function cv_run( $accion, $ficha, $subs, $post = array(), $cb = null, $falla_update = false ) {
	$w = lt_preparar( array( $ficha ), $subs );
	cn_http_on( 'GET', '#/authorized_payments/search#', array( 'results' => array(
		array( 'payment' => array( 'id' => 1, 'status' => 'approved' ), 'debit_date' => '2026-10-05T10:00:00.000-03:00' ),
	) ) );
	if ( $falla_update ) {
		$n = new RP_Wpdb();
		$n->adoptar( $w );
		$n->falla_update = true;
		$GLOBALS['wpdb'] = $w = $n;
	}
	if ( $cb ) {
		$cb( $w );
	}
	$_POST = array_merge( array(
		'cn_cv_accion' => $accion, 'cn_cv_nonce' => 'x', 'cn_cv_socia' => '13', 'cn_cv_a' => cv_a(), 'cn_cv_b' => cv_b(),
		'cn_cv_ficha_ok' => '1', 'cn_cv_foto' => CN_Corregir_Vinculo::foto( $ficha ),
	), $post );
	return array( CN_Corregir_Vinculo::procesar_cv(), $w );
}
function cv_subs( $a = 'authorized', $b = 'authorized', $m_a = 15500, $m_b = 15500 ) {
	return array( cv_a() => lt_mp( cv_a(), $a, $m_a ), cv_b() => lt_mp( cv_b(), $b, $m_b ) );
}
function cv_cache() {
	set_transient( CN_Sin_Vincular::TRANSIENT, array( 'ok' => true ) );
}
function cv_nada( $w ) {
	$tipos = $w->log_mp_tipos();
	return ! $w->escrituras( 'cn_miembros' ) && ! in_array( 'corregir_vinculo', $tipos, true ) && ! in_array( 'vinculo_backup', $tipos, true ) && ! in_array( 'START TRANSACTION', $w->transaccion(), true );
}
function cv_solo_get() {
	foreach ( $GLOBALS['cn_http_log'] as $c ) {
		if ( 'GET' !== $c['metodo'] ) {
			return false;
		}
	}
	return (bool) $GLOBALS['cn_http_log'];
}

seccion( 'corregir vínculo: caso feliz' );
$f = cv_ficha();
set_transient( CN_Sin_Vincular::TRANSIENT, array( 'ok' => true ) );
set_transient( CN_Sin_Vincular::TRANSIENT_TITULARES, array( 'x' => 1 ) );
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs() );
$res = $r['resultado'];
$esc = $w->escrituras( 'cn_miembros' );
t( 'ok y acción corregida', $res['ok'] && 'corregida' === $res['accion'], json_encode( $res ) );
t( 'un solo UPDATE: solo preapproval_id y fecha_modificacion, con guarda por id y A', 1 === count( $esc ) && array( 'preapproval_id', 'fecha_modificacion' ) === array_keys( $esc[0][2] ) && cv_b() === $esc[0][2]['preapproval_id'] && array( 'id' => 13, 'preapproval_id' => cv_a() ) === $esc[0][3], json_encode( $esc ) );
t( 'no hay otras escrituras en miembros', 1 === count( array_filter( $w->log, function ( $l ) {
	return in_array( $l[0], array( 'update', 'insert', 'delete' ), true ) && false !== strpos( $l[1], 'cn_miembros' );
} ) ) );
foreach ( array( 'acceso_hasta', 'estado', 'baja_en', 'plan', 'fecha_alta', 'fecha_fin_trial' ) as $campo ) {
	t( "no toca {$campo}", ! array_key_exists( $campo, $esc[0][2] ) );
}
t( 'backup antes del update y log corregir_vinculo', array( 'vinculo_backup', 'corregir_vinculo' ) === $w->log_mp_tipos() && 'insert' === $w->log[1][0], json_encode( $w->log_mp_tipos() ) );
$bk = $lg = null;
foreach ( $w->log as $l ) {
	if ( 'insert' === $l[0] && 'vinculo_backup' === $l[2]['tipo'] ) {
		$bk = json_decode( $l[2]['payload'], true );
	}
	if ( 'insert' === $l[0] && 'corregir_vinculo' === $l[2]['tipo'] ) {
		$lg = json_decode( $l[2]['payload'], true );
	}
}
t( 'el backup guarda la fila previa completa (A, acceso, baja)', $bk && cv_a() === $bk['fila_previa']['preapproval_id'] && '2026-11-08 15:00:00' === $bk['fila_previa']['acceso_hasta'] && '2026-11-20 00:00:00' === $bk['fila_previa']['baja_en'] && 'activo' === $bk['fila_previa']['estado'] );
t( 'log con ficha, A, B, estados de MP y quién (reversible); sin datos personales', $lg && 13 === $lg['miembro_id'] && cv_a() === $lg['preapproval_anterior'] && cv_b() === $lg['preapproval_nuevo'] && 'admin' === $lg['usuario'] && 'authorized' === $lg['estado_mp_nuevo'] && false === strpos( json_encode( $lg ), '@' ) && false === strpos( json_encode( $lg ), '1155' ), json_encode( $lg ) );
$q = $w->transaccion();
t( 'una transacción: START, FOR UPDATE de la ficha, COMMIT; sin ROLLBACK', 'START TRANSACTION' === $q[0] && 'COMMIT' === end( $q ) && ! in_array( 'ROLLBACK', $q, true ) && 1 === count( array_filter( $w->consultas, function ( $s ) {
	return false !== strpos( $s, 'id = 13' ) && false !== strpos( $s, 'FOR UPDATE' );
} ) ) );
t( 'borra las cachés de Sin vincular', false === get_transient( CN_Sin_Vincular::TRANSIENT ) && false === get_transient( CN_Sin_Vincular::TRANSIENT_TITULARES ) );
t( 'MP: solo GET; cero mails y cero Meta', cv_solo_get() && ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] );
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs( 'cancelled' ) );
t( 'A cancelada en MP también se puede corregir (A no es requisito)', $r['resultado']['ok'] );

seccion( 'corregir vínculo: bloqueos (cero escrituras, caché intacta)' );
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs(), array( 'cn_cv_a' => lt_id( 5 ) ) );
t( 'A no es la vinculada hoy a la ficha', ! $r['resultado']['ok'] && cv_nada( $w ) && ! $GLOBALS['cn_http_log'], json_encode( $r['resultado'] ) );
list( $r, $w ) = cv_run( 'confirmar', cv_ficha( array( 'preapproval_id' => null ) ), cv_subs() );
t( 'ficha sin suscripción: bloqueado', ! $r['resultado']['ok'] && cv_nada( $w ) );
$otra = lt_fila( 99, '1155559999', array( 'preapproval_id' => cv_b() ) );
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs(), array(), function ( $w ) use ( $otra ) {
	$w->on( '/WHERE preapproval_id = /', array( $otra ) );
} );
t( 'B ya vinculada a otra ficha: bloqueado', ! $r['resultado']['ok'] && false !== strpos( $r['resultado']['mensaje'], '#99' ) && cv_nada( $w ) );
foreach ( array( 'paused', 'cancelled', 'pending' ) as $st ) {
	list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs( 'authorized', $st ) );
	t( "B en estado {$st}: bloqueado", ! $r['resultado']['ok'] && cv_nada( $w ) );
}
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs( 'authorized', 'authorized', 15500, 32000 ) );
t( 'plan de B distinto al de la ficha: bloqueado', ! $r['resultado']['ok'] && false !== strpos( $r['resultado']['mensaje'], 'distinto' ) && cv_nada( $w ) );
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs( 'authorized', 'authorized', 15500, 9999 ) );
t( 'monto de B sin plan conocido: bloqueado', ! $r['resultado']['ok'] && cv_nada( $w ) );
list( $r, $w ) = cv_run( 'confirmar', $f, array( cv_a() => lt_mp( cv_a() ), cv_b() => null ) );
t( 'MP no responde por B', ! $r['resultado']['ok'] && cv_nada( $w ) );
list( $r, $w ) = cv_run( 'confirmar', $f, array( cv_a() => null, cv_b() => lt_mp( cv_b() ) ) );
t( 'MP no responde por A', ! $r['resultado']['ok'] && cv_nada( $w ) );
list( $r, $w ) = cv_run( 'confirmar', $f, array( cv_a() => 500, cv_b() => lt_mp( cv_b() ) ) );
t( 'MP devuelve 500 por A', ! $r['resultado']['ok'] && cv_nada( $w ) );
list( $r, $w ) = cv_run( 'confirmar', $f, array( cv_a() => lt_mp( cv_a() ), cv_b() => 404 ) );
t( 'MP devuelve 404 por B', ! $r['resultado']['ok'] && cv_nada( $w ) );
list( $r, $w ) = cv_run( 'confirmar', $f, array( cv_a() => lt_mp( cv_a() ), cv_b() => array( 'id' => cv_b(), 'auto_recurring' => array( 'transaction_amount' => 15500 ) ) ) );
t( 'respuesta de B sin estado (ambigua)', ! $r['resultado']['ok'] && cv_nada( $w ) );
list( $r, $w ) = cv_run( 'confirmar', $f, array( cv_a() => lt_mp( cv_a() ), cv_b() => lt_mp( lt_id( 5 ) ) ) );
t( 'MP devuelve otro ID', ! $r['resultado']['ok'] && cv_nada( $w ) );
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs(), array( 'cn_cv_b' => cv_a() ) );
t( 'A y B iguales: bloqueado', ! $r['resultado']['ok'] && cv_nada( $w ) );
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs(), array( 'cn_cv_b' => 'xyz' ) );
t( 'ID inválido', ! $r['resultado']['ok'] && cv_nada( $w ) );
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs(), array( 'cn_cv_socia' => '555' ) );
t( 'ficha inexistente', ! $r['resultado']['ok'] && cv_nada( $w ) );
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs(), array( 'cn_cv_ficha_ok' => '' ), 'cv_cache' );
t( 'sin tildar la ficha: rechaza y no borra la caché', ! $r['resultado']['ok'] && cv_nada( $w ) && false !== get_transient( CN_Sin_Vincular::TRANSIENT ) );
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs(), array( 'cn_cv_foto' => '' ) );
t( 'sin la foto de la verificación: rechaza', ! $r['resultado']['ok'] && cv_nada( $w ) );
t( 'todas las consultas fueron GET', cv_solo_get() );

seccion( 'corregir vínculo: permisos, nonce y verificar' );
$GLOBALS['cn_caps'] = array();
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs() );
unset( $GLOBALS['cn_caps'] );
t( 'sin manage_options: nada ni consulta a MP', 'error' === $r['paso'] && cv_nada( $w ) && ! $GLOBALS['cn_http_log'] );
$GLOBALS['cn_nonce_ok'] = false;
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs() );
unset( $GLOBALS['cn_nonce_ok'] );
t( 'nonce inválido: nada ni consulta a MP', 'error' === $r['paso'] && cv_nada( $w ) && ! $GLOBALS['cn_http_log'] );
$_POST = array();
t( 'sin nuestro formulario: null', null === CN_Corregir_Vinculo::procesar_cv() );
list( $r, $w ) = cv_run( 'verificar', $f, cv_subs() );
ob_start();
CN_Corregir_Vinculo::render_cv( $r, $r['datos'] );
$html = ob_get_clean();
t( 'verificar no escribe nada y solo hace GET', 'previsualizado' === $r['paso'] && $r['eval']['ok'] && cv_nada( $w ) && cv_solo_get() );
t( 'muestra ficha, A y B (ids cortos, estado, plan, email enmascarado, último pago) y ofrece Confirmar', false !== strpos( $html, '#13' ) && false !== strpos( $html, 'Carla Costa Benitez' ) && false !== strpos( $html, '****1313' ) && false !== strpos( $html, substr( cv_a(), 0, 8 ) . '…' ) && false !== strpos( $html, substr( cv_b(), 0, 8 ) . '…' ) && false !== strpos( $html, 'authorized' ) && false !== strpos( $html, 'p***@example.com' ) && false !== strpos( $html, '2026-10-05' ) && false !== strpos( $html, 'acceso hasta 2026-11-08' ) && false !== strpos( $html, 'value="confirmar"' ), $html );
t( 'no muestra celular completo ni email completo ni A entero', false === strpos( $html, '1155551313' ) && false === strpos( $html, 'p@example.com' ) && false === strpos( preg_replace( '/<input[^>]*>/', '', $html ), cv_a() ) );
t( 'no hay opción de mail ni Meta', false === stripos( $html, 'notificar' ) && false === stripos( $html, 'bienvenida' ) );
list( $r, $w ) = cv_run( 'verificar', $f, cv_subs( 'authorized', 'paused' ) );
ob_start();
CN_Corregir_Vinculo::render_cv( $r, $r['datos'] );
$html = ob_get_clean();
t( 'verificar bloqueado: avisa y no ofrece Confirmar', false !== strpos( $html, 'No se puede corregir' ) && false === strpos( $html, 'value="confirmar"' ) );

seccion( 'corregir vínculo: idempotencia y carreras' );
$hecha = cv_ficha( array( 'preapproval_id' => cv_b() ) );
list( $r, $w ) = cv_run( 'confirmar', $hecha, cv_subs() );
t( 'doble envío (la ficha ya tiene B): ok, cero escrituras, sin tocar MP', $r['resultado']['ok'] && 'ya_hecho' === $r['resultado']['accion'] && cv_nada( $w ) && ! $GLOBALS['cn_http_log'] );
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs(), array(), function ( $w ) use ( $hecha ) {
	$w->on( '/WHERE id = 13\b.*FOR UPDATE/', array( $hecha ) );
} );
t( 'carrera: otro clic ya la corrigió -> ROLLBACK, ok, sin escribir', $r['resultado']['ok'] && 'ya_hecho' === $r['resultado']['accion'] && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! in_array( 'COMMIT', $w->transaccion(), true ) && ! $w->escrituras( 'cn_miembros' ) && ! $w->log_mp_tipos() );
foreach ( array( 'preapproval' => cv_ficha( array( 'preapproval_id' => lt_id( 44 ) ) ), 'acceso' => cv_ficha( array( 'acceso_hasta' => '2026-12-31 00:00:00' ) ), 'estado' => cv_ficha( array( 'estado' => 'cancelada' ) ) ) as $campo => $cambiada ) {
	list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs(), array(), function ( $w ) use ( $cambiada ) {
		$w->on( '/WHERE id = 13\b.*FOR UPDATE/', array( $cambiada ) );
	} );
	t( "carrera: la ficha cambió ({$campo}) -> ROLLBACK, cero cambios", ! $r['resultado']['ok'] && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! in_array( 'COMMIT', $w->transaccion(), true ) && ! $w->escrituras( 'cn_miembros' ) && ! $w->log_mp_tipos() );
}
$tomada = lt_fila( 99, '1155559999', array( 'preapproval_id' => cv_b() ) );
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs(), array(), function ( $w ) use ( $tomada ) {
	$w->on( '/WHERE preapproval_id = .*FOR UPDATE/', array( $tomada ) );
} );
t( 'carrera: B se vinculó a otra ficha -> ROLLBACK', ! $r['resultado']['ok'] && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! $w->escrituras( 'cn_miembros' ) && ! $w->log_mp_tipos() );
list( $r, $w ) = cv_run( 'confirmar', $f, cv_subs(), array(), 'cv_cache', true );
t( 'falla el UPDATE -> ROLLBACK, sin COMMIT ni log, queda sub_error y la caché intacta', ! $r['resultado']['ok'] && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! in_array( 'COMMIT', $w->transaccion(), true ) && ! in_array( 'corregir_vinculo', $w->log_mp_tipos(), true ) && in_array( 'sub_error', $w->log_mp_tipos(), true ) && false !== get_transient( CN_Sin_Vincular::TRANSIENT ) );
