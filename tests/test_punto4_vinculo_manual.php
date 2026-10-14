<?php
// Punto 4: "Vincular suscripción por celular" (CN_Vinculo_Manual).
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
const VM_ID = '7e9baadc542b441fb37131549f6bbad7';
function vm_mp( $status = 'authorized', $monto = 15500 ) {
	return array(
		'id' => VM_ID, 'status' => $status, 'payer_email' => 'grilli@example.com',
		'next_payment_date' => '2026-11-10T12:00:00.000-03:00', 'auto_recurring' => array( 'transaction_amount' => $monto ),
	);
}
function vm_fila( $id, $cel, $extra = array() ) {
	return cn_fila( array_merge( array(
		'id' => $id, 'nombre_apellido' => 'Griselda Grilli', 'estado' => 'activo', 'preapproval_id' => null, 'plan' => null,
		'acceso_hasta' => null, 'email' => 'g' . $id . '@example.com', 'fecha_fin_trial' => '2026-10-14 04:06:41', 'fecha_modificacion' => null,
		'baja_en' => null, 'celular_hint' => substr( $cel, -4 ), 'celular_hash' => CN_Helpers::hash_celular( $cel ), 'celular_texto_plano' => $cel,
	), $extra ) );
}
function vm_preparar( $filas, $vinculada = null, $mp = null ) {
	cn_test_reset();
	global $wpdb;
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	cn_http_on( 'GET', '#/preapproval/' . VM_ID . '#', null === $mp ? vm_mp() : $mp );
	$wpdb->on( '/celular_hint = /', function ( $sql ) use ( $filas ) {
		return array_values( array_filter( $filas, function ( $f ) use ( $sql ) {
			return false !== strpos( $sql, "'" . $f->celular_hint . "'" );
		} ) );
	} );
	$wpdb->on( '/celular_texto_plano LIKE/', $filas );
	$wpdb->on( '/FROM wp_cn_miembros$/', $filas );
	foreach ( $filas as $f ) {
		$wpdb->on( '/WHERE id = ' . $f->id . ' FOR UPDATE/', array( $f ) );
	}
	$wpdb->on( '/WHERE preapproval_id = /', $vinculada ? array( $vinculada ) : array() );
	return $wpdb;
}
function vm_datos( $extra = array() ) {
	return array_merge( array( 'id' => VM_ID, 'celular' => '+54 9 11 5555-2165', 'email' => '', 'nombre' => '', 'notificar' => false, 'socia' => '' ), $extra );
}
function vm_escrituras() {
	return $GLOBALS['wpdb']->escrituras( 'cn_miembros' );
}
function vm_payload( $w, $tipo ) {
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && $tipo === ( $l[2]['tipo'] ?? '' ) ) {
			return $l[2]['payload'];
		}
	}
	return '';
}

seccion( 'validaciones' );
t( 'ID de 32 hex válido', CN_Vinculo_Manual::id_valido( VM_ID ) );
t( 'ID corto inválido', ! CN_Vinculo_Manual::id_valido( 'abc' ) );
t( 'ID con letras fuera de hex inválido', ! CN_Vinculo_Manual::id_valido( str_repeat( 'z', 32 ) ) );
t( 'email enmascarado', 'gr***@example.com' === CN_Vinculo_Manual::mascara_email( 'grilli@example.com' ) );
t( 'mensajes por HTTP distintos', false !== strpos( CN_Vinculo_Manual::mensaje_http( 404 ), '404' ) && false !== strpos( CN_Vinculo_Manual::mensaje_http( 401 ), '401' ) && false !== strpos( CN_Vinculo_Manual::mensaje_http( 429 ), '429' ) && false !== strpos( CN_Vinculo_Manual::mensaje_http( 503 ), '503' ) );

seccion( 'candidatas: dos filas (****2165 y ****2167) -> hay que elegir' );
$a = vm_fila( 10, '1155552165' );
$b = vm_fila( 11, '1155552167' );
vm_preparar( array( $a, $b ) );
$c = cn_llamar( 'CN_Vinculo_Manual', 'candidatas', '1155552165', '', '' );
t( 'devuelve las dos socias', array( 10, 11 ) === array_keys( $c ), json_encode( array_keys( $c ) ) );
t( 'la exacta lo dice', in_array( 'celular exacto', $c[10]['motivos'], true ) );
t( 'la otra no figura como exacta', ! in_array( 'celular exacto', $c[11]['motivos'], true ) );
$r = CN_Vinculo_Manual::confirmar( vm_datos( array( 'socia' => '' ) ), '1155552165' );
t( 'sin elegir: rechaza y no escribe', ! $r['ok'] && ! vm_escrituras() );
$r = CN_Vinculo_Manual::confirmar( vm_datos( array( 'socia' => '99' ) ), '1155552165' );
t( 'elegir una socia que no es candidata: rechaza', ! $r['ok'] && ! vm_escrituras() );
$r = CN_Vinculo_Manual::confirmar( vm_datos( array( 'socia' => 'nueva', 'nombre' => 'X Y', 'email' => 'x@y.com' ) ), '1155552165' );
t( 'crear nueva habiendo candidatas: rechaza', ! $r['ok'] && ! vm_escrituras() );

seccion( 'confirmar: activa socia existente (backup antes de escribir)' );
$w   = vm_preparar( array( $a, $b ) );
$r   = CN_Vinculo_Manual::confirmar( vm_datos( array( 'socia' => '10' ) ), '1155552165' );
$esc = vm_escrituras();
t( 'ok activada', $r['ok'] && 'activada' === $r['accion'], json_encode( $r ) );
t( 'un solo update sobre miembros, de la socia 10', 1 === count( $esc ) && 'update' === $esc[0][0] && array( 'id' => 10 ) === $esc[0][3] );
$d = $esc ? $esc[0][2] : array();
t( 'setea preapproval, plan basico, acceso_hasta UTC, trial NULL', ( $d['preapproval_id'] ?? '' ) === VM_ID && ( $d['plan'] ?? '' ) === 'basico' && ( $d['acceso_hasta'] ?? '' ) === '2026-11-10 15:00:00' && array_key_exists( 'fecha_fin_trial', $d ) && null === $d['fecha_fin_trial'] );
$orden = array();
foreach ( $w->log as $l ) {
	if ( 'update' === $l[0] ) {
		$orden[] = 'update';
	} elseif ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) ) {
		$orden[] = $l[2]['tipo'];
	}
}
t( 'orden: backup, update, vinculo_manual', $orden === array( 'vinculo_backup', 'update', 'vinculo_manual' ), json_encode( $orden ) );
$bk = vm_payload( $w, 'vinculo_backup' );
t( 'backup sin email completo ni celular ni hash', false === strpos( $bk, 'g10@example.com' ) && false === strpos( $bk, '1155552165' ) && false === strpos( $bk, '$2y$' ) && false !== strpos( $bk, '"id":10' ) );
t( 'transacción START..COMMIT con FOR UPDATE', $w->transaccion() === array( 'START TRANSACTION', 'COMMIT' ) && (bool) array_filter( $w->consultas, function ( $q ) {
	return false !== strpos( $q, 'WHERE id = 10 FOR UPDATE' );
} ) );
t( 'por defecto no manda mail ni Meta', ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] );
$log_vm = vm_payload( $w, 'vinculo_manual' );
t( 'vinculo_manual guarda preapproval, socia y usuario; sin emails', false !== strpos( $log_vm, VM_ID ) && false !== strpos( $log_vm, '"miembro_id":10' ) && false !== strpos( $log_vm, '"usuario":"admin"' ) && false === strpos( $log_vm, '@' ) );

seccion( 'confirmar: con checkbox manda mail + Subscribe una sola vez' );
$w = vm_preparar( array( $a ) );
$w->on( '/vinculo_bienvenida/', 0 );
$r = CN_Vinculo_Manual::confirmar( vm_datos( array( 'socia' => '10', 'notificar' => true ) ), '1155552165' );
t( 'manda 1 mail y 1 evento con event_id vinc_<id>', 1 === count( $GLOBALS['cn_mails'] ) && 1 === count( $GLOBALS['cn_capi'] ) && 'vinc_' . VM_ID === $GLOBALS['cn_capi'][0][0] );
t( 'deja marca vinculo_bienvenida', in_array( 'vinculo_bienvenida', $w->log_mp_tipos(), true ) );
$w = vm_preparar( array( $a ) );
$w->on( '/vinculo_bienvenida/', 1 );
$r = CN_Vinculo_Manual::confirmar( vm_datos( array( 'socia' => '10', 'notificar' => true ) ), '1155552165' );
t( 'si ya se había mandado, no repite', ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] );

seccion( 'confirmar: socia nueva (y reconfirmar no duplica)' );
$w   = vm_preparar( array() );
$d   = vm_datos( array( 'socia' => 'nueva', 'nombre' => 'Griselda Nueva', 'email' => 'nueva@example.com', 'celular' => '0 11 15 5555-2165' ) );
$r   = CN_Vinculo_Manual::confirmar( $d, '1155552165' );
$esc = vm_escrituras();
t( 'inserta una socia activa con preapproval/plan/acceso', $r['ok'] && 'crear' === $r['accion'] && 1 === count( $esc ) && 'insert' === $esc[0][0] && 'activo' === $esc[0][2]['estado'] && VM_ID === $esc[0][2]['preapproval_id'] && 'basico' === $esc[0][2]['plan'] && '1155552165' === $esc[0][2]['celular_texto_plano'], json_encode( $r ) );
t( 'sin backup (no hay fila previa) y con vinculo_manual', ! in_array( 'vinculo_backup', $w->log_mp_tipos(), true ) && in_array( 'vinculo_manual', $w->log_mp_tipos(), true ) );
$creada = vm_fila( 999, '1155552165', array( 'preapproval_id' => VM_ID, 'plan' => 'basico', 'acceso_hasta' => '2026-11-10 15:00:00', 'fecha_fin_trial' => null ) );
$w      = vm_preparar( array( $creada ), $creada );
$r      = CN_Vinculo_Manual::confirmar( $d, '1155552165' );
t( 'segunda confirmación: completada, cero escrituras', $r['ok'] && 'completada' === $r['accion'] && ! vm_escrituras(), json_encode( $r ) );
$w = vm_preparar( array() );
$r = CN_Vinculo_Manual::confirmar( vm_datos( array( 'socia' => 'nueva', 'celular' => '1155552165' ) ), '1155552165' );
t( 'nueva sin nombre/email: rechaza', ! $r['ok'] && ! vm_escrituras() );

seccion( 'confirmar: conflictos' );
$w = vm_preparar( array( vm_fila( 10, '1155552165', array( 'preapproval_id' => 'otro0000000000000000000000000000' ) ) ) );
cn_http_on( 'POST', '#club-trial-alerta#', array() );
$r = CN_Vinculo_Manual::confirmar( vm_datos( array( 'socia' => '10' ) ), '1155552165' );
t( 'otro preapproval: conflicto, ROLLBACK, cero escrituras, log sub_conflicto', ! $r['ok'] && 'conflicto' === $r['accion'] && ! vm_escrituras() && in_array( 'ROLLBACK', $w->transaccion(), true ) && in_array( 'sub_conflicto', $w->log_mp_tipos(), true ) );
$w = vm_preparar( array( $a ), vm_fila( 77, '1166667777', array( 'preapproval_id' => VM_ID ) ) );
$r = CN_Vinculo_Manual::confirmar( vm_datos( array( 'socia' => '10' ) ), '1155552165' );
t( 'preapproval en OTRA socia: rechaza sin escribir', ! $r['ok'] && 'rechazada' === $r['accion'] && ! vm_escrituras() && in_array( 'ROLLBACK', $w->transaccion(), true ) );

seccion( 'confirmar: MP no permite confirmar' );
vm_preparar( array( $a ), null, vm_mp( 'authorized', 9999 ) );
$r = CN_Vinculo_Manual::confirmar( vm_datos( array( 'socia' => '10' ) ), '1155552165' );
t( 'plan desconocido: no confirma ni escribe', ! $r['ok'] && false !== strpos( $r['mensaje'], 'Plan desconocido' ) && ! vm_escrituras() );
vm_preparar( array( $a ), null, vm_mp( 'paused', 15500 ) );
$r = CN_Vinculo_Manual::confirmar( vm_datos( array( 'socia' => '10' ) ), '1155552165' );
t( 'no authorized: no confirma ni escribe', ! $r['ok'] && ! vm_escrituras() );
foreach ( array( 404, 401, 429, 500 ) as $code ) {
	$w = vm_preparar( array( $a ) );
	cn_http_on( 'GET', '#/preapproval/' . VM_ID . '#', $code );
	$r = CN_Vinculo_Manual::confirmar( vm_datos( array( 'socia' => '10' ) ), '1155552165' );
	t( "MP {$code}: mensaje claro, no escribe, deja mp_api_error", ! $r['ok'] && false !== strpos( $r['mensaje'], (string) $code ) && ! vm_escrituras() && in_array( 'mp_api_error', $w->log_mp_tipos(), true ) );
}
