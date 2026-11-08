<?php
// "Extender acceso" (CN_Extender_Acceso): ampliar acceso_hasta de UNA ficha, sin tocar nada más ni consultar a MP.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
require_once __DIR__ . '/test_punto6_lote.php';
function ea_ficha( $extra = array() ) {
	return lt_fila( 223, '1155552233', array_merge( array(
		'nombre_apellido' => 'Silvina Prueba', 'estado' => 'activo', 'preapproval_id' => lt_id( 66 ), 'plan' => 'basico',
		'acceso_hasta' => '2026-11-08 15:00:00', 'baja_en' => null,
	), $extra ) );
}
function ea_run( $accion, $ficha, $post = array(), $cb = null ) {
	$w = lt_preparar( array( $ficha ), array() );
	if ( $cb ) {
		$cb( $w );
	}
	$_POST = array_merge( array(
		'cn_ea_accion' => $accion, 'cn_ea_nonce' => 'x', 'cn_ea_socia' => '223', 'cn_ea_fecha' => '2026-12-08',
		'cn_ea_motivo' => 'Pago del 2do mes cayó en suscripción sin vincular', 'cn_ea_foto' => CN_Extender_Acceso::foto( $ficha ),
	), $post );
	return array( CN_Extender_Acceso::procesar_ea(), $w );
}
function ea_nada( $w ) {
	return ! $w->escrituras( 'cn_miembros' ) && ! $w->log_mp_tipos() && ! in_array( 'START TRANSACTION', $w->transaccion(), true );
}
function ea_sin_red() {
	return ! $GLOBALS['cn_http_log'] && ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'];
}

seccion( 'extender acceso: confirmar' );
$f = ea_ficha();
list( $r, $w ) = ea_run( 'confirmar', $f );
$res = $r['resultado'];
$esc = $w->escrituras( 'cn_miembros' );
t( 'extiende: ok', $res['ok'] && 'extendido' === $res['accion'], json_encode( $res ) );
t( 'un solo UPDATE, solo acceso_hasta y fecha_modificacion, misma hora del acceso actual', 1 === count( $esc ) && array( 'id' => 223 ) === $esc[0][3] && array( 'acceso_hasta', 'fecha_modificacion' ) === array_keys( $esc[0][2] ) && '2026-12-08 15:00:00' === $esc[0][2]['acceso_hasta'], json_encode( $esc ) );
t( 'no hay otras escrituras en miembros (insert/delete)', 1 === count( array_filter( $w->log, function ( $l ) {
	return in_array( $l[0], array( 'update', 'insert', 'delete' ), true ) && false !== strpos( $l[1], 'cn_miembros' );
} ) ) );
t( 'backup antes del update y log extension_acceso', array( 'vinculo_backup', 'extension_acceso' ) === $w->log_mp_tipos() && 'insert' === $w->log[1][0], json_encode( $w->log_mp_tipos() ) );
$bk = $lg = null;
foreach ( $w->log as $l ) {
	if ( 'insert' === $l[0] && 'vinculo_backup' === $l[2]['tipo'] ) {
		$bk = json_decode( $l[2]['payload'], true );
	}
	if ( 'insert' === $l[0] && 'extension_acceso' === $l[2]['tipo'] ) {
		$lg = json_decode( $l[2]['payload'], true );
	}
}
t( 'el backup guarda la fila previa (acceso anterior, estado, plan, suscripción)', $bk && '2026-11-08 15:00:00' === $bk['fila_previa']['acceso_hasta'] && 'activo' === $bk['fila_previa']['estado'] && lt_id( 66 ) === $bk['fila_previa']['preapproval_id'] && 223 === $bk['fila_previa']['id'] );
t( 'log con ficha, fecha anterior, fecha nueva, motivo y usuario; sin datos personales', $lg && 223 === $lg['miembro_id'] && '2026-11-08 15:00:00' === $lg['acceso_anterior'] && '2026-12-08 15:00:00' === $lg['acceso_nuevo'] && false !== strpos( $lg['motivo'], 'Pago del 2do mes' ) && 'admin' === $lg['usuario'] && false === strpos( json_encode( $lg ), '1155' ) );
$q = $w->transaccion();
t( 'transacción: START, FOR UPDATE, COMMIT, sin ROLLBACK', 'START TRANSACTION' === $q[0] && 'COMMIT' === end( $q ) && ! in_array( 'ROLLBACK', $q, true ) && 1 === count( array_filter( $w->consultas, function ( $s ) {
	return false !== strpos( $s, 'id = 223' ) && false !== strpos( $s, 'FOR UPDATE' );
} ) ) );
t( 'cero HTTP (ni a MP), cero mails, cero Meta', ea_sin_red() );
list( $r, $w ) = ea_run( 'confirmar', ea_ficha( array( 'acceso_hasta' => null ) ) );
$esc = $w->escrituras( 'cn_miembros' );
t( 'sin acceso previo: permitido (00:00:00)', $r['resultado']['ok'] && '2026-12-08 00:00:00' === $esc[0][2]['acceso_hasta'] );
list( $r, $w ) = ea_run( 'confirmar', ea_ficha( array( 'estado' => 'pausado', 'baja_en' => '2026-11-15 00:00:00' ) ) );
$esc = $w->escrituras( 'cn_miembros' );
t( 'ficha pausada con baja programada: extiende sin tocar estado ni baja_en', $r['resultado']['ok'] && ! array_key_exists( 'baja_en', $esc[0][2] ) && ! array_key_exists( 'estado', $esc[0][2] ) );

seccion( 'extender acceso: bloqueos (cero escrituras)' );
foreach ( array( '2026-11-08' => 'igual', '2026-11-07' => 'anterior', '2025-01-01' => 'muy anterior' ) as $fecha => $que ) {
	list( $r, $w ) = ea_run( 'confirmar', $f, array( 'cn_ea_fecha' => $fecha ) );
	t( "fecha {$que}: bloqueado", ! $r['resultado']['ok'] && false !== strpos( $r['resultado']['mensaje'], 'POSTERIOR' ) && ea_nada( $w ), json_encode( $r['resultado'] ) );
}
list( $r, $w ) = ea_run( 'confirmar', $f, array( 'cn_ea_fecha' => '2027-11-18' ) );
t( 'más de 400 días: bloqueado', ! $r['resultado']['ok'] && false !== strpos( $r['resultado']['mensaje'], '400' ) && ea_nada( $w ) );
list( $r, $w ) = ea_run( 'confirmar', $f, array( 'cn_ea_fecha' => '2027-11-17' ) );
t( 'justo 400 días: permitido', $r['resultado']['ok'] );
list( $r, $w ) = ea_run( 'confirmar', ea_ficha( array( 'estado' => 'cancelada' ) ) );
t( 'ficha cancelada: bloqueado y manda a Vincular/Reemplazar', ! $r['resultado']['ok'] && false !== strpos( $r['resultado']['mensaje'], 'Reemplazar' ) && ea_nada( $w ) );
list( $r, $w ) = ea_run( 'confirmar', $f, array( 'cn_ea_motivo' => '' ) );
t( 'sin motivo: bloqueado', ! $r['resultado']['ok'] && ea_nada( $w ) );
list( $r, $w ) = ea_run( 'confirmar', $f, array( 'cn_ea_motivo' => str_repeat( 'x', 201 ) ) );
t( 'motivo de más de 200 caracteres: bloqueado', ! $r['resultado']['ok'] && ea_nada( $w ) );
foreach ( array( '2026-13-45', '08/12/2026', 'mañana', '2026-02-30', '' ) as $malo ) {
	list( $r, $w ) = ea_run( 'confirmar', $f, array( 'cn_ea_fecha' => $malo ) );
	t( 'fecha inválida "' . $malo . '": bloqueado', ! $r['resultado']['ok'] && ea_nada( $w ) );
}
list( $r, $w ) = ea_run( 'confirmar', $f, array( 'cn_ea_socia' => '999' ) );
t( 'ficha inexistente: bloqueado', ! $r['resultado']['ok'] && ea_nada( $w ) );
list( $r, $w ) = ea_run( 'confirmar', $f, array( 'cn_ea_foto' => '' ) );
t( 'sin la foto de la verificación: rechaza', ! $r['resultado']['ok'] && ea_nada( $w ) );
t( 'los bloqueos tampoco tocaron la red', ea_sin_red() );

seccion( 'extender acceso: permisos, nonce y verificar' );
$GLOBALS['cn_caps'] = array();
list( $r, $w ) = ea_run( 'confirmar', $f );
unset( $GLOBALS['cn_caps'] );
t( 'sin manage_options: nada', 'error' === $r['paso'] && ea_nada( $w ) );
$GLOBALS['cn_nonce_ok'] = false;
list( $r, $w ) = ea_run( 'confirmar', $f );
unset( $GLOBALS['cn_nonce_ok'] );
t( 'nonce inválido: nada', 'error' === $r['paso'] && ea_nada( $w ) );
$_POST = array();
t( 'sin nuestro formulario: null', null === CN_Extender_Acceso::procesar_ea() );
list( $r, $w ) = ea_run( 'verificar', $f );
ob_start();
CN_Extender_Acceso::render_ea( $r, $r['datos'] );
$html = ob_get_clean();
t( 'verificar no escribe nada', 'previsualizado' === $r['paso'] && $r['eval']['ok'] && ea_nada( $w ) && ea_sin_red() );
t( 'muestra # nombre, celular enmascarado, estado, plan, suscripción corta, acceso actual y nuevo, baja', false !== strpos( $html, '#223' ) && false !== strpos( $html, 'Silvina Prueba' ) && false !== strpos( $html, '****2233' ) && false !== strpos( $html, 'estado activo' ) && false !== strpos( $html, 'plan basico' ) && false !== strpos( $html, substr( lt_id( 66 ), 0, 8 ) . '…' ) && false !== strpos( $html, '2026-11-08' ) && false !== strpos( $html, '2026-12-08' ) && false !== strpos( $html, 'baja programada no' ) && false !== strpos( $html, 'value="confirmar"' ), $html );
t( 'no muestra celular completo, suscripción entera ni email', false === strpos( $html, '1155552233' ) && false === strpos( $html, lt_id( 66 ) ) && false === strpos( $html, '@example.com' ) );
list( $r, $w ) = ea_run( 'verificar', $f, array( 'cn_ea_fecha' => '2026-11-01' ) );
ob_start();
CN_Extender_Acceso::render_ea( $r, $r['datos'] );
$html = ob_get_clean();
t( 'verificar con fecha mala: avisa y no ofrece Confirmar', false !== strpos( $html, 'No se puede extender' ) && false === strpos( $html, 'value="confirmar"' ) );

seccion( 'extender acceso: idempotencia y carreras' );
$hecha = ea_ficha( array( 'acceso_hasta' => '2026-12-08 15:00:00' ) );
list( $r, $w ) = ea_run( 'confirmar', $hecha, array( 'cn_ea_foto' => CN_Extender_Acceso::foto( $f ) ) );
t( 'doble clic (la ficha ya tiene la fecha nueva): ok, cero escrituras', $r['resultado']['ok'] && 'ya_hecho' === $r['resultado']['accion'] && ea_nada( $w ), json_encode( $r['resultado'] ) );
list( $r, $w ) = ea_run( 'confirmar', $hecha );
t( 'verificar+confirmar fresco con fecha igual a la actual: bloqueado, no "ya hecho"', ! $r['resultado']['ok'] && ea_nada( $w ) );
list( $r, $w ) = ea_run( 'confirmar', $f, array(), function ( $w ) use ( $hecha ) { // otro clic se adelantó entre la lectura y el bloqueo.
	$w->on( '/WHERE id = 223\b.*FOR UPDATE/', array( $hecha ) );
} );
t( 'carrera: otro clic ya la extendió -> ROLLBACK, ok, sin escribir', $r['resultado']['ok'] && 'ya_hecho' === $r['resultado']['accion'] && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! in_array( 'COMMIT', $w->transaccion(), true ) && ! $w->escrituras( 'cn_miembros' ) && ! $w->log_mp_tipos() );
foreach ( array( 'estado' => ea_ficha( array( 'estado' => 'cancelada' ) ), 'baja_en' => ea_ficha( array( 'baja_en' => '2026-11-20 00:00:00' ) ), 'acceso' => ea_ficha( array( 'acceso_hasta' => '2026-11-30 15:00:00' ) ) ) as $campo => $cambiada ) {
	list( $r, $w ) = ea_run( 'confirmar', $f, array(), function ( $w ) use ( $cambiada ) {
		$w->on( '/WHERE id = 223\b.*FOR UPDATE/', array( $cambiada ) );
	} );
	t( "carrera: la ficha cambió ({$campo}) entre Verificar y Confirmar -> ROLLBACK, cero cambios", ! $r['resultado']['ok'] && in_array( 'ROLLBACK', $w->transaccion(), true ) && ! in_array( 'COMMIT', $w->transaccion(), true ) && ! $w->escrituras( 'cn_miembros' ) && ! $w->log_mp_tipos() );
}
list( $r, $w ) = ea_run( 'confirmar', ea_ficha( array( 'baja_en' => '2026-11-20 00:00:00' ) ), array( 'cn_ea_foto' => CN_Extender_Acceso::foto( $f ) ) );
t( 'la foto vieja de Verificar ya no sirve si la ficha cambió', ! $r['resultado']['ok'] && ea_nada( $w ) );

seccion( 'extender acceso: la pantalla carga sin tocar nada' );
cn_test_reset();
$_POST = array();
ob_start();
CN_Extender_Acceso::pagina();
$html = ob_get_clean();
t( 'GET: formulario con nonce, sin red ni escrituras', false !== strpos( $html, 'cn_ea_nonce' ) && false !== strpos( $html, 'name="cn_ea_motivo"' ) && ea_sin_red() && ! $GLOBALS['wpdb']->log );
