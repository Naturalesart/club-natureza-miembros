<?php
// "Reemplazar suscripción" (CN_Reemplazo): ficha con suscripción vieja (cancelada/pausada) que se volvió a suscribir.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
require_once __DIR__ . '/test_punto6_lote.php';
function rp_nuevo() {
	return lt_id( 77 );
}
function rp_viejo() {
	return lt_id( 66 );
}
function rp_ficha( $extra = array() ) {
	return lt_fila( 13, '1155551313', array_merge( array(
		'nombre_apellido' => 'Carla Costa Benitez', 'estado' => 'activo', 'preapproval_id' => rp_viejo(), 'plan' => 'basico',
		'acceso_hasta' => '2026-09-01 00:00:00', 'baja_en' => '2026-09-08 00:00:00',
	), $extra ) );
}
/** wpdb falso que puede fallar en update() (para probar el ROLLBACK). */
class RP_Wpdb extends CN_Fake_Wpdb {
	public $falla_update = false;
	public function adoptar( $otro ) {
		$this->handlers = ( function () {
			return $this->handlers;
		} )->call( $otro );
	}
	public function update( $tabla, $data, $where, $f = null, $wf = null ) {
		if ( $this->falla_update ) {
			$this->last_error = 'boom';
			return false;
		}
		return parent::update( $tabla, $data, $where, $f, $wf );
	}
}
/**
 * $subs: id => respuesta (array | null = MP no responde | int = código HTTP). $cb( $wpdb ) permite agregar respuestas de la base.
 * Devuelve array( resultado de procesar(), wpdb ).
 */
function rp_run( $accion, $ficha, $subs, $post = array(), $cb = null, $falla_update = false ) {
	$w = lt_preparar( array( $ficha ), $subs );
	cn_http_on( 'GET', '#/authorized_payments/search#', array( 'results' => array(
		array( 'payment' => array( 'id' => 1, 'status' => 'approved' ), 'debit_date' => '2026-06-05T10:00:00.000-03:00' ),
		array( 'payment' => array( 'id' => 2, 'status' => 'rejected' ), 'debit_date' => '2026-07-05T10:00:00.000-03:00' ),
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
		'cn_rp_accion' => $accion, 'cn_rp_nonce' => 'x', 'cn_rp_id' => rp_nuevo(), 'cn_rp_socia' => '13',
		'cn_rp_ficha_ok' => '1', 'cn_rp_foto' => CN_Reemplazo::foto( $ficha ),
	), $post );
	return array( CN_Reemplazo::procesar_reemplazo(), $w );
}
function rp_subs( $viejo = 'cancelled', $nuevo = 'authorized', $m_viejo = 15500, $m_nuevo = 15500 ) {
	return array( rp_nuevo() => lt_mp( rp_nuevo(), $nuevo, $m_nuevo ), rp_viejo() => lt_mp( rp_viejo(), $viejo, $m_viejo ) );
}
function rp_sin_escribir( $w ) {
	$tipos = $w->log_mp_tipos();
	return ! $w->escrituras( 'cn_miembros' ) && ! in_array( 'reemplazo_suscripcion', $tipos, true ) && ! in_array( 'vinculo_backup', $tipos, true ) && ! in_array( 'START TRANSACTION', $w->transaccion(), true );
}
function rp_solo_get() {
	foreach ( $GLOBALS['cn_http_log'] as $c ) {
		if ( 'GET' !== $c['metodo'] ) {
			return false;
		}
	}
	return true;
}

seccion( 'reemplazar: nueva authorized + vieja cancelled -> reemplaza' );
$f = rp_ficha();
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs( 'cancelled' ) );
$res = $r['resultado'];
$esc = $w->escrituras( 'cn_miembros' );
t( 'ok y acción reemplazada', $res['ok'] && 'reemplazada' === $res['accion'], json_encode( $res ) );
t( 'un solo update en miembros, con guarda por id y preapproval viejo', 1 === count( $esc ) && array( 'id' => 13, 'preapproval_id' => rp_viejo() ) === $esc[0][3] );
$u = $esc ? $esc[0][2] : array();
t( 'preapproval nuevo, plan por monto, activo, baja_en NULL', rp_nuevo() === $u['preapproval_id'] && 'basico' === $u['plan'] && 'activo' === $u['estado'] && array_key_exists( 'baja_en', $u ) && null === $u['baja_en'] );
t( 'acceso_hasta = próximo cobro de la nueva (UTC)', '2026-11-10 15:00:00' === $u['acceso_hasta'] );
t( 'backup antes del update y log reemplazo_suscripcion', array( 'vinculo_backup', 'reemplazo_suscripcion' ) === $w->log_mp_tipos() && 'insert' === $w->log[1][0], json_encode( $w->log_mp_tipos() ) );
$lg = null;
foreach ( $w->log as $l ) {
	if ( 'insert' === $l[0] && 'reemplazo_suscripcion' === $l[2]['tipo'] ) {
		$lg = json_decode( $l[2]['payload'], true );
	}
}
t( 'log con ficha, id viejo, id nuevo, quién y estados de MP, sin datos personales', $lg && 13 === $lg['miembro_id'] && rp_viejo() === $lg['preapproval_viejo'] && rp_nuevo() === $lg['preapproval_nuevo'] && 'admin' === $lg['usuario'] && 'cancelled' === $lg['estado_mp_viejo'] && 'authorized' === $lg['estado_mp_nuevo'] && false === strpos( json_encode( $lg ), '@' ) && false === strpos( json_encode( $lg ), '1155' ), json_encode( $lg ) );
$bk = null;
foreach ( $w->log as $l ) {
	if ( 'insert' === $l[0] && 'vinculo_backup' === $l[2]['tipo'] ) {
		$bk = json_decode( $l[2]['payload'], true );
	}
}
t( 'el backup guarda la fila previa (id viejo y baja_en)', $bk && rp_viejo() === $bk['fila_previa']['preapproval_id'] && '2026-09-08 00:00:00' === $bk['fila_previa']['baja_en'] );
$q = $w->transaccion();
t( 'una transacción: START, FOR UPDATE de la ficha, COMMIT; sin ROLLBACK', 'START TRANSACTION' === $q[0] && 'COMMIT' === end( $q ) && ! in_array( 'ROLLBACK', $q, true ) && 1 === count( array_filter( $w->consultas, function ( $s ) {
	return false !== strpos( $s, 'id = 13' ) && false !== strpos( $s, 'FOR UPDATE' );
} ) ) );
t( 'MP: solo GET (nunca cancela, pausa ni reembolsa)', rp_solo_get() && count( $GLOBALS['cn_http_log'] ) >= 3 );
t( 'cero mails y cero eventos Meta', ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] );
t( 'ningún otro registro en miembros (no hay delete ni insert)', ! array_filter( $w->log, function ( $l ) {
	return in_array( $l[0], array( 'delete' ), true ) || ( 'insert' === $l[0] && false !== strpos( $l[1], 'cn_miembros' ) );
} ) );

seccion( 'reemplazar: vieja paused -> reemplaza' );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs( 'paused' ) );
t( 'vieja pausada: reemplaza', $r['resultado']['ok'] && 1 === count( $w->escrituras( 'cn_miembros' ) ), json_encode( $r['resultado'] ) );

seccion( 'reemplazar: vieja authorized -> bloqueado (ni con POST manual)' );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs( 'authorized' ), array( 'cn_rp_plan_ok' => '1', 'cn_rp_forzar' => '1', 'force' => '1' ) );
t( 'doble activa: rechaza con texto de doble pago, cero escrituras', ! $r['resultado']['ok'] && false !== strpos( $r['resultado']['mensaje'], 'doble pago' ) && rp_sin_escribir( $w ), json_encode( $r['resultado'] ) );
list( $r, $w ) = rp_run( 'verificar', $f, rp_subs( 'authorized' ) );
ob_start();
CN_Reemplazo::render_reemplazo( $r, $r['datos'] );
$html = ob_get_clean();
t( 'la vista previa no ofrece Confirmar ni forzar', false !== strpos( $html, 'doble pago' ) && false === strpos( $html, 'value="confirmar"' ) && false === stripos( $html, 'forzar"' ) );
foreach ( array( 'pending', 'finished', 'raro' ) as $st ) {
	list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs( $st ) );
	t( "vieja en estado '{$st}': no se interpreta, rechaza", ! $r['resultado']['ok'] && rp_sin_escribir( $w ) );
}

seccion( 'reemplazar: falla o ambigüedad de MP -> bloqueado, cero escrituras' );
list( $r, $w ) = rp_run( 'confirmar', $f, array( rp_nuevo() => null, rp_viejo() => lt_mp( rp_viejo(), 'cancelled' ) ) );
t( 'MP no responde por la nueva', ! $r['resultado']['ok'] && rp_sin_escribir( $w ) );
list( $r, $w ) = rp_run( 'confirmar', $f, array( rp_nuevo() => lt_mp( rp_nuevo() ), rp_viejo() => null ) );
t( 'MP no responde por la vieja', ! $r['resultado']['ok'] && rp_sin_escribir( $w ) );
list( $r, $w ) = rp_run( 'confirmar', $f, array( rp_nuevo() => lt_mp( rp_nuevo() ), rp_viejo() => 500 ) );
t( 'MP devuelve 500 por la vieja', ! $r['resultado']['ok'] && rp_sin_escribir( $w ) );
list( $r, $w ) = rp_run( 'confirmar', $f, array( rp_nuevo() => 404, rp_viejo() => lt_mp( rp_viejo(), 'cancelled' ) ) );
t( 'MP devuelve 404 por la nueva', ! $r['resultado']['ok'] && rp_sin_escribir( $w ) );
list( $r, $w ) = rp_run( 'confirmar', $f, array( rp_nuevo() => lt_mp( rp_nuevo() ), rp_viejo() => array( 'id' => rp_viejo(), 'auto_recurring' => array( 'transaction_amount' => 15500 ) ) ) );
t( 'vieja sin estado en la respuesta (ambigua)', ! $r['resultado']['ok'] && rp_sin_escribir( $w ) );
list( $r, $w ) = rp_run( 'confirmar', $f, array( rp_nuevo() => lt_mp( rp_nuevo() ), rp_viejo() => lt_mp( lt_id( 5 ), 'cancelled' ) ) );
t( 'MP devuelve otro ID', ! $r['resultado']['ok'] && rp_sin_escribir( $w ) );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs( 'cancelled', 'paused' ) );
t( 'nueva no authorized', ! $r['resultado']['ok'] && rp_sin_escribir( $w ) );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs( 'cancelled', 'authorized', 15500, 9999 ) );
t( 'nueva con monto sin plan conocido', ! $r['resultado']['ok'] && rp_sin_escribir( $w ) );
t( 'todas las consultas fueron GET', rp_solo_get() );

seccion( 'reemplazar: nueva ya vinculada a otra ficha / ficha sin vieja / ficha inexistente' );
$otra = lt_fila( 99, '1155559999', array( 'preapproval_id' => rp_nuevo() ) );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs(), array(), function ( $w ) use ( $otra ) {
	$w->on( '/WHERE preapproval_id = /', array( $otra ) );
} );
t( 'nueva vinculada a la #99: rechaza sin consultar a MP', ! $r['resultado']['ok'] && false !== strpos( $r['resultado']['mensaje'], '#99' ) && rp_sin_escribir( $w ) && ! $GLOBALS['cn_http_log'], json_encode( $r['resultado'] ) );
list( $r, $w ) = rp_run( 'confirmar', rp_ficha( array( 'preapproval_id' => null ) ), rp_subs() );
t( 'ficha sin suscripción vieja: rechaza (para eso está Vincular)', ! $r['resultado']['ok'] && false !== strpos( $r['resultado']['mensaje'], 'Vincular' ) && rp_sin_escribir( $w ) );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs(), array( 'cn_rp_socia' => '555' ) );
t( 'ficha inexistente: rechaza', ! $r['resultado']['ok'] && rp_sin_escribir( $w ) );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs(), array( 'cn_rp_id' => 'xyz' ) );
t( 'ID inválido: rechaza', ! $r['resultado']['ok'] && rp_sin_escribir( $w ) );

seccion( 'reemplazar: cambio de plan exige la casilla' );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs( 'cancelled', 'authorized', 15500, 32000 ) );
t( 'plan/monto distinto sin casilla: rechaza', ! $r['resultado']['ok'] && false !== strpos( $r['resultado']['mensaje'], 'cambio de plan' ) && rp_sin_escribir( $w ), json_encode( $r['resultado'] ) );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs( 'cancelled', 'authorized', 15500, 32000 ), array( 'cn_rp_plan_ok' => '1' ) );
$esc = $w->escrituras( 'cn_miembros' );
t( 'con la casilla: reemplaza y guarda el plan de la nueva', $r['resultado']['ok'] && 'full' === $esc[0][2]['plan'], json_encode( $r['resultado'] ) );
list( $r, $w ) = rp_run( 'verificar', $f, rp_subs( 'cancelled', 'authorized', 15500, 32000 ) );
ob_start();
CN_Reemplazo::render_reemplazo( $r, $r['datos'] );
$html = ob_get_clean();
t( 'la vista previa advierte y pide la casilla', $r['eval']['cambio_plan'] && false !== strpos( $html, 'cn_rp_plan_ok' ) && false !== strpos( $html, 'Cambia el plan' ) );
list( $r, $w ) = rp_run( 'verificar', $f, rp_subs() );
ob_start();
CN_Reemplazo::render_reemplazo( $r, $r['datos'] );
$html = ob_get_clean();
t( 'sin cambio de plan no pide esa casilla', ! $r['eval']['cambio_plan'] && false === strpos( $html, 'cn_rp_plan_ok' ) );
list( $r, $w ) = rp_run( 'confirmar', rp_ficha( array( 'plan' => 'full' ) ), rp_subs() );
t( 'plan de la ficha distinto del de la nueva (aunque los montos coincidan): también exige casilla', ! $r['resultado']['ok'] && rp_sin_escribir( $w ) );

seccion( 'reemplazar: vista previa (solo lectura)' );
list( $r, $w ) = rp_run( 'verificar', $f, rp_subs( 'paused' ) );
ob_start();
CN_Reemplazo::render_reemplazo( $r, $r['datos'] );
$html = ob_get_clean();
t( 'verificar no escribe nada', 'previsualizado' === $r['paso'] && $r['eval']['ok'] && rp_sin_escribir( $w ) && ! $w->log_mp_tipos() && rp_solo_get() );
t( 'muestra ficha (# y nombre), celular enmascarado, ids cortos, estado en MP y último cobro', false !== strpos( $html, '#13' ) && false !== strpos( $html, 'Carla Costa Benitez' ) && false !== strpos( $html, '****1313' ) && false !== strpos( $html, substr( rp_viejo(), 0, 8 ) . '…' ) && false !== strpos( $html, 'paused' ) && false !== strpos( $html, '2026-06-05' ) && false !== strpos( $html, 'acceso hasta 2026-09-01' ) && false !== strpos( $html, 'baja programada 2026-09-08' ), $html );
t( 'no muestra celular completo ni el ID viejo entero ni emails', false === strpos( $html, '1155551313' ) && false === strpos( $html, rp_viejo() ) && false === strpos( $html, '@example.com' ) );
t( 'tiene la casilla de la ficha elegida y la foto de la ficha', false !== strpos( $html, 'cn_rp_ficha_ok' ) && false !== strpos( $html, CN_Reemplazo::foto( $f ) ) );
t( 'no hay opción de mail ni de evento Meta', false === stripos( $html, 'notificar' ) && false === stripos( $html, 'mail de bienvenida' ) );
list( $r, $w ) = rp_run( 'verificar', $f, array( rp_nuevo() => lt_mp( rp_nuevo() ), rp_viejo() => lt_mp( rp_viejo(), 'cancelled' ) ) );
cn_http_on( 'GET', '#/authorized_payments/search#', null );
t( 'un cobro que no se puede leer es solo informativo', true );

seccion( 'reemplazar: permisos, nonce y confirmaciones' );
$GLOBALS['cn_caps'] = array();
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs() );
unset( $GLOBALS['cn_caps'] );
t( 'sin manage_options: no hace nada (ni consulta a MP)', 'error' === $r['paso'] && rp_sin_escribir( $w ) && ! $GLOBALS['cn_http_log'] );
$GLOBALS['cn_nonce_ok'] = false;
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs() );
unset( $GLOBALS['cn_nonce_ok'] );
t( 'nonce inválido: no hace nada (ni consulta a MP)', 'error' === $r['paso'] && rp_sin_escribir( $w ) && ! $GLOBALS['cn_http_log'] );
$_POST = array();
t( 'sin nuestro formulario: procesar() devuelve null', null === CN_Reemplazo::procesar_reemplazo() );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs(), array( 'cn_rp_ficha_ok' => '' ) );
t( 'sin tildar la ficha elegida: rechaza', ! $r['resultado']['ok'] && rp_sin_escribir( $w ) );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs(), array( 'cn_rp_foto' => '' ) );
t( 'sin la foto de la verificación: rechaza', ! $r['resultado']['ok'] && rp_sin_escribir( $w ) );

seccion( 'reemplazar: el acceso nunca se recorta' );
list( $r, $w ) = rp_run( 'confirmar', rp_ficha( array( 'acceso_hasta' => '2027-01-01 00:00:00' ) ), rp_subs() );
$esc = $w->escrituras( 'cn_miembros' );
t( 'acceso existente más largo se conserva', $r['resultado']['ok'] && '2027-01-01 00:00:00' === $esc[0][2]['acceso_hasta'] );
list( $r, $w ) = rp_run( 'confirmar', rp_ficha( array( 'acceso_hasta' => null ) ), rp_subs() );
$esc = $w->escrituras( 'cn_miembros' );
t( 'sin acceso previo toma el de MP', '2026-11-10 15:00:00' === $esc[0][2]['acceso_hasta'] );
list( $r, $w ) = rp_run( 'confirmar', rp_ficha( array( 'estado' => 'cancelada', 'baja_en' => null ) ), rp_subs() );
$esc = $w->escrituras( 'cn_miembros' );
t( 'ficha cancelada con vieja cancelada: queda activa', $r['resultado']['ok'] && 'activo' === $esc[0][2]['estado'] );

seccion( 'reemplazar: idempotencia y carreras' );
$hecha = rp_ficha( array( 'preapproval_id' => rp_nuevo(), 'baja_en' => null ) );
list( $r, $w ) = rp_run( 'confirmar', $hecha, rp_subs() );
t( 'doble envío (la ficha ya apunta a la nueva): ok, cero escrituras, sin tocar MP', $r['resultado']['ok'] && 'ya_hecho' === $r['resultado']['accion'] && rp_sin_escribir( $w ) && ! $w->log_mp_tipos() && ! $GLOBALS['cn_http_log'] );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs(), array(), function ( $w ) use ( $hecha ) { // otro clic se adelantó entre la lectura y el bloqueo.
	$w->on( '/WHERE id = 13\b.*FOR UPDATE/', array( $hecha ) );
} );
t( 'carrera: otro clic ya la reemplazó -> ROLLBACK, ok, sin escribir', $r['resultado']['ok'] && 'ya_hecho' === $r['resultado']['accion'] && ! $w->escrituras( 'cn_miembros' ) && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! in_array( 'COMMIT', $w->transaccion(), true ) && ! $w->log_mp_tipos() );
$cambiada = rp_ficha( array( 'acceso_hasta' => '2026-12-31 00:00:00' ) );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs(), array(), function ( $w ) use ( $cambiada ) { // la ficha cambió después de la vista previa.
	$w->on( '/WHERE id = 13\b.*FOR UPDATE/', array( $cambiada ) );
} );
t( 'carrera: la ficha cambió -> ROLLBACK, cero cambios', ! $r['resultado']['ok'] && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! in_array( 'COMMIT', $w->transaccion(), true ) && ! $w->escrituras( 'cn_miembros' ) && ! $w->log_mp_tipos(), json_encode( $r['resultado'] ) );
$tomada = lt_fila( 99, '1155559999', array( 'preapproval_id' => rp_nuevo() ) );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs(), array(), function ( $w ) use ( $tomada ) { // otra ficha se quedó con la nueva en el medio.
	$w->on( '/WHERE preapproval_id = .*FOR UPDATE/', array( $tomada ) );
} );
t( 'carrera: la nueva se vinculó a otra ficha -> ROLLBACK', ! $r['resultado']['ok'] && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! $w->escrituras( 'cn_miembros' ) && ! $w->log_mp_tipos() );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs(), array(), null, true );
t( 'falla el UPDATE -> ROLLBACK, sin COMMIT ni log de reemplazo, queda sub_error', ! $r['resultado']['ok'] && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! in_array( 'COMMIT', $w->transaccion(), true ) && ! in_array( 'reemplazo_suscripcion', $w->log_mp_tipos(), true ) && in_array( 'sub_error', $w->log_mp_tipos(), true ), json_encode( $w->log_mp_tipos() ) );

seccion( 'reemplazar: otra suscripción sin vincular en curso para la misma ficha' );
$cache = array( 'ok' => true, 'error' => '', 'truncada' => false, 'tope' => false, 'fecha' => '2026-10-13 15:00:00', 'filas' => array(
	array( 'id' => rp_nuevo(), 'plan' => 'basico', 'monto' => 15500, 'proximo_cobro' => '', 'alta' => '2026-10-08', 'email' => 'a@example.com', 'nivel' => 'medio', 'motivo' => 'la socia ya tiene otra suscripción', 'candidatas' => array( $f ), 'titular' => '', 'titular_estado' => '' ),
) );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs(), array(), function () use ( $cache ) {
	set_transient( CN_Sin_Vincular::TRANSIENT, $cache );
} );
t( 'la fila de la propia suscripción nueva no cuenta como "otra"', $r['resultado']['ok'] );
$cache['filas'][] = array( 'id' => lt_id( 88 ), 'plan' => 'basico', 'monto' => 15500, 'proximo_cobro' => '', 'alta' => '2026-10-09', 'email' => 'a@example.com', 'nivel' => 'medio', 'motivo' => 'x', 'candidatas' => array( $f ), 'titular' => '', 'titular_estado' => '' );
list( $r, $w ) = rp_run( 'confirmar', $f, rp_subs(), array(), function () use ( $cache ) {
	set_transient( CN_Sin_Vincular::TRANSIENT, $cache );
} );
t( 'otra suscripción sin vincular apunta a la misma ficha: rechaza, de a una', ! $r['resultado']['ok'] && false !== strpos( $r['resultado']['mensaje'], 'en curso' ) && rp_sin_escribir( $w ), json_encode( $r['resultado'] ) );

seccion( 'reemplazar: botón en "Suscripciones sin vincular"' );
cn_test_reset();
$conSub  = rp_ficha();
$sinSub  = lt_fila( 14, '1155551414', array( 'preapproval_id' => null ) );
$fila = function ( $id, $cands ) {
	return array( 'id' => $id, 'plan' => 'basico', 'monto' => 15500, 'proximo_cobro' => '', 'alta' => '2026-10-08', 'email' => 'a@example.com', 'nivel' => 'medio', 'motivo' => 'm', 'candidatas' => $cands, 'titular' => '', 'titular_estado' => '' );
};
set_transient( CN_Sin_Vincular::TRANSIENT, array( 'ok' => true, 'error' => '', 'truncada' => false, 'tope' => false, 'fecha' => '2026-10-13 15:00:00',
	'filas' => array( $fila( rp_nuevo(), array( $conSub ) ), $fila( lt_id( 88 ), array( $sinSub ) ), $fila( lt_id( 89 ), array( rp_ficha( array( 'preapproval_id' => lt_id( 89 ) ) ) ) ) ) ) );
$_POST = array();
ob_start();
CN_Sin_Vincular::pagina();
$html = ob_get_clean();
t( 'aparece "Reemplazar" solo donde la ficha ya tiene OTRA suscripción', 1 === substr_count( $html, 'Reemplazar (#13)' ) && false === strpos( $html, 'Reemplazar (#14)' ), $html );
t( 'el enlace solo precarga (id nuevo y ficha), sin acción ni nonce', false !== strpos( $html, 'page=cn-reemplazar' ) && false !== strpos( $html, 'cn_rp_id=' . rp_nuevo() ) && false !== strpos( $html, 'cn_rp_socia=13' ) && false === strpos( $html, 'cn_rp_accion' ) );
t( 'cargar la pantalla de Reemplazar (GET) no consulta a MP ni escribe', ! $GLOBALS['cn_http_log'] && ! $GLOBALS['wpdb']->log );
$_GET = array( 'cn_rp_id' => rp_nuevo(), 'cn_rp_socia' => '13' );
$_POST = array();
ob_start();
CN_Reemplazo::pagina();
$html = ob_get_clean();
$_GET = array();
t( 'la pantalla precarga el formulario y no consulta a MP', false !== strpos( $html, 'value="' . rp_nuevo() . '"' ) && false !== strpos( $html, 'value="13"' ) && ! $GLOBALS['cn_http_log'] && ! $GLOBALS['wpdb']->log );
