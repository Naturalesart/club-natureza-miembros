<?php
// "Para contactar" (CN_Para_Contactar): pagaron y no tienen acceso hace más de 72 hs + detección de doble suscripción. Solo lectura.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
CN_Sin_Vincular::$terminar = false;
function pc_id( $n ) {
	return md5( 'pc' . $n );
}
function pc_sub( $n, $email = null, $monto = 15500, $dias = 4 ) {
	$s = array( 'id' => pc_id( $n ), 'status' => 'authorized', 'date_created' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - (int) round( $dias * 86400 ) ), 'next_payment_date' => '2026-11-10T00:00:00.000-03:00', 'auto_recurring' => array( 'transaction_amount' => $monto ) );
	if ( null !== $email ) {
		$s['payer_email'] = $email;
	}
	return $s;
}
function pc_ficha( $id, $nombre, $extra = array() ) {
	return cn_fila( array_merge( array(
		'id' => $id, 'nombre_apellido' => $nombre, 'estado' => 'activo', 'preapproval_id' => null, 'plan' => null, 'acceso_hasta' => null, 'email' => 'f' . $id . '@example.com',
		'fecha_fin_trial' => null, 'fecha_modificacion' => null, 'baja_en' => null, 'celular_hint' => '1234', 'celular_hash' => 'h', 'celular_texto_plano' => '5491100001234', 'trial_payment_id' => null,
	), $extra ) );
}
/** $cobros: n => nombre del titular | true (cobro sin titular) | false (sin cobro) | int (error HTTP). */
function pc_preparar( $subs, $fichas, $cobros = array() ) {
	cn_test_reset();
	global $wpdb;
	unset( $GLOBALS['cn_caps'], $GLOBALS['cn_nonce_ok'] );
	$_POST = $_GET = array();
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => $subs, 'paging' => array( 'total' => count( $subs ) ) ) );
	cn_http_on( 'GET', '#/v1/payments/search#', array( 'results' => array(), 'paging' => array( 'total' => 0 ) ) );
	cn_http_on( 'GET', '#/authorized_payments/search#', array( 'results' => array() ) );
	foreach ( $cobros as $n => $c ) {
		$pid = 8000000 + $n;
		if ( is_int( $c ) ) {
			cn_http_on( 'GET', '#/authorized_payments/search\?preapproval_id=' . pc_id( $n ) . '#', $c );
		} elseif ( false !== $c ) {
			cn_http_on( 'GET', '#/authorized_payments/search\?preapproval_id=' . pc_id( $n ) . '#', array( 'results' => array( array( 'id' => 1, 'payment' => array( 'id' => $pid, 'status' => 'approved' ) ) ) ) );
			cn_http_on( 'GET', '#/v1/payments/' . $pid . '$#', true === $c ? array( 'id' => $pid, 'status' => 'approved' ) : array( 'id' => $pid, 'status' => 'approved', 'card' => array( 'cardholder' => array( 'name' => $c ) ) ) );
		}
	}
	$wpdb->on( '/SELECT ' . preg_quote( CN_Vinculo_Manual::COLS, '/' ) . ' FROM wp_cn_miembros/', $fichas );
	$wpdb->on( '/SELECT id, nombre_apellido, estado, email, celular_hint, preapproval_id, plan, fecha_modificacion, trial_payment_id FROM wp_cn_miembros/', $fichas );
	return $wpdb;
}
function pc_pagina( $consultar = true ) {
	$_POST = $consultar ? array( 'cn_pc_accion' => 'consultar' ) : array();
	ob_start();
	CN_Para_Contactar::pagina();
	$h = ob_get_clean();
	$_POST = array();
	return $h;
}
function pc_escrituras( $w ) {
	$o = $w->escrituras( 'cn_miembros' );
	foreach ( $w->log as $l ) {
		if ( 'q' === $l[0] && preg_match( '/^(UPDATE|INSERT|DELETE)/', $l[1] ) ) {
			$o[] = $l;
		}
	}
	return $o;
}
function pc_avisos( $w ) {
	$o = array();
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && 'aviso_error' === $l[2]['tipo'] ) {
			$o[] = json_decode( $l[2]['payload'], true )['motivo'];
		}
	}
	return $o;
}
function pc_solo_get() {
	return array( 'GET' ) === array_values( array_unique( array_column( $GLOBALS['cn_http_log'], 'metodo' ) ) );
}

seccion( 'quién aparece' );
$w = pc_preparar( array( pc_sub( 1, 'ana@gmail.com' ) ), array(), array( 1 => 'ANA PEREZ' ) );
$h = pc_pagina();
t( 'sin vincular, con pago y más de 72 hs: aparece con plan, titular, email, antigüedad y botón de copiar', false !== strpos( $h, 'ana@gmail.com' ) && false !== strpos( $h, 'ANA PEREZ' ) && false !== strpos( $h, 'basico' ) && false !== strpos( $h, '4 d' ) && false !== strpos( $h, 'Copiar mensaje de WhatsApp' ) && false !== strpos( $h, 'Hola Ana!' ), substr( strip_tags( $h ), 0, 300 ) );
t( 'el mensaje solo se copia: no hay envío (ni mails, ni POST a nadie)', ! $GLOBALS['cn_mails'] && pc_solo_get() && false === strpos( $h, 'wa.me' ) );
$w = pc_preparar( array( pc_sub( 1, 'ana@gmail.com' ) ), array(), array( 1 => false ) );
t( 'sin cobro aprobado: NO aparece', false === strpos( pc_pagina(), 'ana@gmail.com' ) );
$w = pc_preparar( array( pc_sub( 1, 'ana@gmail.com', 15500, 1 ) ), array(), array( 1 => 'ANA PEREZ' ) );
t( 'menos de 72 hs: NO aparece', false === strpos( pc_pagina(), 'ana@gmail.com' ) );
$w = pc_preparar( array( pc_sub( 1, 'ana@gmail.com', 15500, 3.5 ) ), array(), array( 1 => 'ANA PEREZ' ) );
t( 'más de 72 hs (3,5 días): aparece', false !== strpos( pc_pagina(), 'ana@gmail.com' ) );
$w = pc_preparar( array( pc_sub( 1, 'ana@gmail.com' ) ), array( pc_ficha( 5, 'Ana Perez', array( 'preapproval_id' => pc_id( 1 ), 'plan' => 'basico', 'email' => 'ana@gmail.com' ) ) ), array( 1 => 'ANA PEREZ' ) );
t( 'vinculada a una ficha activa: NO aparece', false === strpos( pc_pagina(), 'Copiar mensaje' ) );
foreach ( array( 'cancelada', 'pausado' ) as $est ) {
	$w = pc_preparar( array( pc_sub( 1, 'ana@gmail.com' ) ), array( pc_ficha( 5, 'Ana Perez', array( 'estado' => $est, 'preapproval_id' => pc_id( 1 ), 'plan' => 'basico', 'fecha_modificacion' => gmdate( 'Y-m-d H:i:s', time() - 5 * 86400 ) ) ) ), array( 1 => 'ANA PEREZ' ) );
	$h = pc_pagina();
	t( "ficha {$est} con suscripción authorized y pago: aparece con la ficha como candidata", false !== strpos( $h, '#5 Ana Perez' ) && false !== strpos( $h, 'sigue authorized' ), substr( strip_tags( $h ), 0, 200 ) );
}
$w = pc_preparar( array( pc_sub( 1, 'ana@gmail.com' ) ), array( pc_ficha( 5, 'Ana Perez', array( 'estado' => 'cancelada', 'preapproval_id' => pc_id( 1 ), 'fecha_modificacion' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ) ) ), array( 1 => 'ANA PEREZ' ) );
t( 'ficha cancelada hace menos de 72 hs: NO aparece', false === strpos( pc_pagina(), 'Copiar mensaje' ) );
$w = pc_preparar( array(), array( pc_ficha( 5, 'Ana Perez', array( 'estado' => 'cancelada', 'preapproval_id' => pc_id( 1 ), 'fecha_modificacion' => gmdate( 'Y-m-d H:i:s', time() - 9 * 86400 ) ) ) ) );
t( 'ficha cancelada cuya suscripción ya no está authorized en MP: NO aparece', false === strpos( pc_pagina(), 'Copiar mensaje' ) );
$w = pc_preparar( array( pc_sub( 1, 'ana@gmail.com' ) ), array( pc_ficha( 5, 'Ana Perez', array( 'email' => 'ana@gmail.com' ) ) ), array( 1 => 'ANA PEREZ' ) );
$h = pc_pagina();
t( 'con ficha candidata por email: se muestra la ficha (celular enmascarado)', false !== strpos( $h, '#5 Ana Perez' ) && false !== strpos( $h, '****1234' ) && false === strpos( $h, '5491100001234' ) );

seccion( 'casos del cartel "pagó y no tiene acceso"' );
$w = pc_preparar( array(), array( pc_ficha( 9, 'Con Pago', array( 'trial_payment_id' => 'P_RESUELTO' ) ) ) );
update_option( CN_Alertas::OPT_CASOS, array(
	'preapproval|PRE_VIEJO_CASO0001' => array( 'motivo' => 'preapproval_sin_match', 'tipo' => 'preapproval', 'id' => 'PRE_VIEJO_CASO0001', 'desde' => time() - 5 * 86400 ),
	'pago|P_NUEVO' => array( 'motivo' => 'metadata_incompleta', 'tipo' => 'pago', 'id' => 'P_NUEVO', 'desde' => time() - 3600 ),
	'pago|P_RESUELTO' => array( 'motivo' => 'excepcion_trial_alta', 'tipo' => 'pago', 'id' => 'P_RESUELTO', 'desde' => time() - 6 * 86400 ),
) );
$h = pc_pagina();
t( 'caso de más de 72 hs sin ficha: aparece', false !== strpos( $h, 'PRE_VI...' ) && false !== strpos( $h, 'preapproval_sin_match' ) );
t( 'caso de menos de 72 hs: NO aparece', false === strpos( $h, 'P_NUEVO' ) && false === strpos( $h, 'metadata_incompleta' ) );
t( 'caso que ya tiene ficha: NO aparece', false === strpos( $h, 'excepcion_trial_alta' ) );

seccion( 'doble suscripción: solo detectar' );
$ana = 'ana@gmail.com';
$d = CN_Para_Contactar::detectar_dobles( array( pc_sub( 1, $ana ), pc_sub( 2, $ana ) ), array() );
t( 'mismo email y mismo plan: "doble"', 1 === count( $d ) && 'doble' === $d[0]['tipo'] && 2 === count( $d[0]['subs'] ) );
$d = CN_Para_Contactar::detectar_dobles( array( pc_sub( 1, $ana ), pc_sub( 2, $ana, 32000 ) ), array() );
t( 'mismo email con planes distintos (legítimo): "revisar", NO "doble"', 1 === count( $d ) && 'revisar' === $d[0]['tipo'] );
$d = CN_Para_Contactar::detectar_dobles( array( pc_sub( 1, $ana ), pc_sub( 2, $ana, 9999 ) ), array() );
t( 'plan desconocido en una: "revisar"', 'revisar' === $d[0]['tipo'] );
$d = CN_Para_Contactar::detectar_dobles( array( pc_sub( 1, 'a@gmail.com' ), pc_sub( 2, 'b@gmail.com' ) ), array() );
t( 'emails distintos y sin titular: no se detecta nada', array() === $d );
$tit = array( pc_id( 1 ) => array( 'nombre' => 'ANA PEREZ', 'estado' => 'ok' ), pc_id( 2 ) => array( 'nombre' => 'Perez Ana', 'estado' => 'ok' ) );
$d = CN_Para_Contactar::detectar_dobles( array( pc_sub( 1, 'a@gmail.com' ), pc_sub( 2, 'b@gmail.com' ) ), array(), array(), $tit );
t( 'mismo titular con emails distintos: solo "revisar" (nunca "doble")', 1 === count( $d ) && 'revisar' === $d[0]['tipo'] && 'titular de la tarjeta' === $d[0]['criterio'] );
$tit1 = array( pc_id( 1 ) => array( 'nombre' => 'Ana', 'estado' => 'ok' ), pc_id( 2 ) => array( 'nombre' => 'Ana', 'estado' => 'ok' ) );
t( 'titular de un solo nombre (muy común): no se usa', array() === CN_Para_Contactar::detectar_dobles( array( pc_sub( 1, 'a@gmail.com' ), pc_sub( 2, 'b@gmail.com' ) ), array(), array(), $tit1 ) );
$ficha = pc_ficha( 5, 'Ana Perez', array( 'preapproval_id' => pc_id( 1 ), 'email' => $ana ) );
$d = CN_Para_Contactar::detectar_dobles( array( pc_sub( 1 ), pc_sub( 2, $ana ) ), array( $ficha ) );
t( 'misma ficha (una sub vinculada sin payer_email + otra con el email de la ficha): detectada', 1 === count( $d ) && 'doble' === $d[0]['tipo'] && 5 === $d[0]['subs'][0]['ficha'] );
$d = CN_Para_Contactar::detectar_dobles( array( pc_sub( 1, $ana ), pc_sub( 2, $ana ), pc_sub( 3, $ana ) ), array() );
t( 'tres con el mismo email: un solo caso con las tres', 1 === count( $d ) && 3 === count( $d[0]['subs'] ) );
$d = CN_Para_Contactar::detectar_dobles( array( pc_sub( 1, $ana ), pc_sub( 1, $ana ) ), array() );
t( 'la misma suscripción repetida en la lista no es doble', array() === $d );
// En la pantalla.
$w = pc_preparar( array( pc_sub( 1, $ana ), pc_sub( 2, $ana ), pc_sub( 3, 'zoe@gmail.com' ), pc_sub( 4, 'zoe@gmail.com', 32000 ) ), array(), array( 1 => 'ANA PEREZ', 2 => 'ANA PEREZ', 3 => 'ZOE LOPEZ', 4 => 'ZOE LOPEZ' ) );
$h = pc_pagina();
t( 'la pantalla muestra "Posible doble pago" y "Revisar" con ids cortos, fechas y montos', false !== strpos( $h, 'Posible doble pago' ) && false !== strpos( $h, '>Revisar<' ) && false !== strpos( $h, substr( pc_id( 1 ), 0, 6 ) . '...' ) && false !== strpos( $h, '$15.500' ) && false !== strpos( $h, '$32.000' ) );
$av = pc_avisos( $w );
t( 'deja avisos en el cartel (posible_doble_pago y doble_suscripcion_revisar), uno por caso', 1 === count( array_keys( $av, 'posible_doble_pago', true ) ) && 1 === count( array_keys( $av, 'doble_suscripcion_revisar', true ) ) );
pc_pagina();
t( 'una vez por día por caso: la segunda consulta no repite el aviso', 2 === count( array_filter( pc_avisos( $w ), function ( $m ) {
	return 0 === strpos( $m, 'posible_doble' ) || 0 === strpos( $m, 'doble_susc' );
} ) ) );
t( 'detectar no cancela, reembolsa ni escribe: cero POST/PUT/DELETE y cero escrituras en miembros', pc_solo_get() && ! pc_escrituras( $w ) && ! $GLOBALS['cn_mails'] );
// El sync (que ya tiene la lista) también deja el aviso.
$w = pc_preparar( array( pc_sub( 1, $ana, 15500, 0.01 ), pc_sub( 2, $ana, 15500, 0.01 ) ), array() );
CN_Sync::alertar_sin_vincular();
t( 'el cron de sin vincular deja el aviso de doble pago sin consultar nada extra', in_array( 'posible_doble_pago', pc_avisos( $w ), true ) && ! cn_http_calls( '#authorized_payments#' ) );

seccion( 'cache, errores y permisos' );
$w = pc_preparar( array( pc_sub( 1, 'ana@gmail.com' ) ), array(), array( 1 => 'ANA PEREZ' ) );
pc_pagina();
$GLOBALS['cn_http_log'] = array();
$h = pc_pagina( false );
t( 'con el cache válido, cargar la página NO hace ninguna llamada a MP', ! $GLOBALS['cn_http_log'] && false !== strpos( $h, 'ana@gmail.com' ) );
$h = pc_pagina( false );
t( 'y tampoco la segunda vez', ! $GLOBALS['cn_http_log'] );
$w = pc_preparar( array( pc_sub( 1, 'ana@gmail.com' ) ), array(), array( 1 => 'ANA PEREZ' ) );
$h = pc_pagina( false );
t( 'sin cache: la página no consulta a MP, pide tocar "Consultar"', ! $GLOBALS['cn_http_log'] && false !== strpos( $h, 'Consultar Mercado Pago' ) );
$w = pc_preparar( array( pc_sub( 1, 'ana@gmail.com' ) ), array(), array( 1 => 500 ) );
$h = pc_pagina();
t( 'MP no responde el cobro: no aparece como pagada y se avisa que falta verificar', false === strpos( $h, 'Copiar mensaje' ) && false !== strpos( $h, 'no se pudo verificar' ) && ! pc_escrituras( $w ) );
$w = pc_preparar( array( pc_sub( 1, 'ana@gmail.com' ) ), array(), array( 1 => 'ANA PEREZ' ) );
$GLOBALS['cn_caps'] = array();
$h = pc_pagina();
t( 'sin manage_options: no muestra nada ni consulta', '' === $h && ! $GLOBALS['cn_http_log'] );
unset( $GLOBALS['cn_caps'] );
$w = pc_preparar( array( pc_sub( 1, 'ana@gmail.com' ) ), array(), array( 1 => 'ANA PEREZ' ) );
$GLOBALS['cn_nonce_ok'] = false;
$h = pc_pagina();
t( 'nonce inválido: no consulta a MP', ! $GLOBALS['cn_http_log'] );
unset( $GLOBALS['cn_nonce_ok'] );

seccion( 'todo el flujo: solo lectura' );
$w = pc_preparar( array( pc_sub( 1, $ana ), pc_sub( 2, $ana ), pc_sub( 3, 'zoe@gmail.com' ) ), array( pc_ficha( 5, 'Zoe Lopez', array( 'estado' => 'cancelada', 'preapproval_id' => pc_id( 3 ), 'fecha_modificacion' => gmdate( 'Y-m-d H:i:s', time() - 6 * 86400 ) ) ) ), array( 1 => 'ANA PEREZ', 2 => 'ANA PEREZ', 3 => 'ZOE LOPEZ' ) );
pc_pagina();
pc_pagina( false );
t( 'cero escrituras en miembros, solo GET, cero mails', ! pc_escrituras( $w ) && pc_solo_get() && ! $GLOBALS['cn_mails'] );
$fuente = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-para-contactar.php' );
t( 'el código no tiene wp_mail, wp_remote_post ni escrituras sobre miembros', ! preg_match( '/wp_mail|wp_remote_(post|request)|->(update|insert|delete|query)\(/', $fuente ) );
