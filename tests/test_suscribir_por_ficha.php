<?php
// FASE 2: endpoint ?cn_suscribir=<token> (CN_Suscribir). Token firmado por ficha, modo off/on, secreto, ficha con sub activa,
// reuso de la pendiente de 24 h, rate limit, y cero datos personales / WhatsApp en URLs, logs y salidas.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) {
		return mt_rand( (int) $min, $max ? (int) $max : mt_getrandmax() );
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $p = '' ) {
		return 'https://naturalesart.com/wp-json/' . ltrim( $p, '/' );
	}
}
const SP_SECRETO = 'ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34';
const SP_CEL     = '1155550077';
const SP_PLANES  = 'https://naturalesart.com/planes-club/';
function sp_ficha( $extra = array() ) {
	return cn_fila( $extra + array(
		'id'                  => 21,
		'nombre_apellido'     => 'Socia Prueba',
		'estado'              => 'activo',
		'preapproval_id'      => null,
		'baja_en'             => null,
		'email'               => 'socia.prueba@gmail.com',
		'celular_texto_plano' => '011 15 5555-0077',
		'celular_hash'        => CN_Helpers::hash_celular( SP_CEL ),
	) );
}
/** Modo on + secreto + MP responde al POST /preapproval; la ficha 21 existe (o la que se pase). */
function sp_preparar( $ficha = null, $modo = 'on', $secreto = SP_SECRETO ) {
	cn_test_reset();
	global $wpdb;
	$_GET = $_POST = $_COOKIE = array();
	$_SERVER['REMOTE_ADDR']     = '190.1.2.3';
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (test)';
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CLIENT_IP'] );
	update_option( 'cn_mp_access_token', 'TOKEN-SUB' );
	update_option( CN_Suscribir::OPT_MODO, $modo );
	update_option( CN_Suscribir::OPT_SECRETO, $secreto );
	cn_http_on( 'POST', '#/preapproval$#', array( 'id' => 'pre_9', 'init_point' => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=pre_9' ) );
	$ficha = null === $ficha ? sp_ficha() : $ficha;
	if ( false !== $ficha ) {
		$wpdb->on( '/FROM wp_cn_miembros WHERE id = ' . (int) $ficha->id . '\b/', array( $ficha ) );
	}
	return $wpdb;
}
function sp_token( $ficha_id = 21, $plan = 'basico', $origen = 'mail_d5', $vence = null ) {
	return CN_Suscribir::crear_token( $ficha_id, $plan, $origen, $vence, SP_SECRETO );
}
function sp_pendientes( $w ) {
	return array_values( array_filter( $w->escrituras( 'suscripciones_pendientes' ), function ( $l ) {
		return 'insert' === $l[0];
	} ) );
}
function sp_todo_texto( $w, $r = null ) {
	$t = json_encode( $w->log ) . json_encode( $GLOBALS['cn_http_log'] ) . json_encode( $r );
	return $t;
}
function sp_limpio( $texto ) {
	return false === stripos( $texto, 'whatsapp' ) && false === stripos( $texto, 'wa.me' );
}
/** Payloads (texto) de todas las filas de mp_log, concatenados (json_encode del log escaparía las comillas internas). */
function sp_payloads( $w, $tipo = null ) {
	$o = '';
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && ( null === $tipo || $tipo === $l[2]['tipo'] ) ) {
			$o .= (string) $l[2]['payload'] . "\n";
		}
	}
	return $o;
}

seccion( 'token: crear + verificar, receta documentada' );
cn_test_reset();
update_option( CN_Suscribir::OPT_SECRETO, SP_SECRETO );
$vence = 1800000000;
$tok   = CN_Suscribir::crear_token( 21, 'basico', 'mail_d5', $vence );
$receta = '21.basico.mail_d5.' . $vence . '.' . hash_hmac( 'sha256', '21.basico.mail_d5.' . $vence, SP_SECRETO );
t( 'el token es <ficha>.<plan>.<origen>.<vence>.<hmac sha256 hex del payload con el secreto> (la receta para n8n reproduce la firma)', $tok === $receta );
t( 'forma: 5 partes, firma de 64 hex en minúscula', 1 === preg_match( '/^21\.basico\.mail_d5\.1800000000\.[a-f0-9]{64}$/', $tok ) );
t( 'el token no lleva email ni celular', false === strpos( $tok, '@' ) && false === strpos( $tok, SP_CEL ) );
t( 'la URL del endpoint es home/?cn_suscribir=<token>', 'https://naturalesart.com/?cn_suscribir=' . $tok === CN_Suscribir::url_token( $tok ) );
$tok_def = CN_Suscribir::crear_token( 21, 'full', 'winback' );
$partes  = explode( '.', $tok_def );
t( 'vencimiento por defecto: 30 días', abs( (int) $partes[3] - ( time() + 30 * 86400 ) ) <= 2 && 'full' === $partes[1] && 'winback' === $partes[2] );
t( 'plan u origen fuera de la lista cerrada, ficha 0 o sin secreto: no se arma token', '' === CN_Suscribir::crear_token( 21, 'premium', 'mail_d5' ) && '' === CN_Suscribir::crear_token( 21, 'basico', 'landing' ) && '' === CN_Suscribir::crear_token( 0, 'basico', 'mail_d5' ) && '' === CN_Suscribir::crear_token( 21, 'basico', 'mail_d5', null, '' ) );
$w = sp_preparar();
$v = CN_Suscribir::verificar( sp_token( 21, 'basico', 'mail_d5', $vence ) );
t( 'verificar: ida y vuelta (ficha 21, basico, mail_d5, vence)', $v['ok'] && 21 === $v['ficha_id'] && 'basico' === $v['plan'] && 'mail_d5' === $v['origen'] && $vence === $v['vence'] && 21 === (int) $v['ficha']->id );
t( 'verificar no consulta MP', ! $GLOBALS['cn_http_log'] );
foreach ( array(
	'formato: vacío'            => array( '', 'formato' ),
	'formato: basura'           => array( 'hola', 'formato' ),
	'formato: plan inválido'    => array( '21.premium.mail_d5.1800000000.' . str_repeat( 'a', 64 ), 'formato' ),
	'formato: origen inválido'  => array( '21.basico.landing.1800000000.' . str_repeat( 'a', 64 ), 'formato' ),
	'formato: firma corta'      => array( '21.basico.mail_d5.1800000000.abc', 'formato' ),
	'formato: firma en mayúscula' => array( strtoupper( sp_token( 21, 'basico', 'mail_d5', $vence ) ), 'formato' ),
	'firma mala'                => array( '21.basico.mail_d5.' . $vence . '.' . str_repeat( 'a', 64 ), 'firma' ),
	'firma de otro secreto'     => array( CN_Suscribir::crear_token( 21, 'basico', 'mail_d5', $vence, 'otro-secreto' ), 'firma' ),
	'payload cambiado (otro plan con la misma firma)' => array( str_replace( '.basico.', '.full.', sp_token( 21, 'basico', 'mail_d5', $vence ) ), 'firma' ),
	'vencido'                   => array( sp_token( 21, 'basico', 'mail_d5', time() - 1 ), 'vencido' ),
	'ficha inexistente'         => array( sp_token( 999, 'basico', 'mail_d5', $vence ), 'sin_ficha' ),
) as $nombre => $caso ) {
	$v = CN_Suscribir::verificar( $caso[0] );
	t( "verificar: {$nombre} => {$caso[1]}", ! $v['ok'] && $caso[1] === $v['motivo'] && null === $v['ficha'], $v['motivo'] );
}
update_option( CN_Suscribir::OPT_SECRETO, '' );
$v = CN_Suscribir::verificar( sp_token( 21, 'basico', 'mail_d5', $vence ) );
t( 'secreto vacío: verificar dice sin_secreto antes de mirar nada', ! $v['ok'] && 'sin_secreto' === $v['motivo'] );
$s = CN_Suscribir::generar_secreto();
t( 'generar_secreto: 64 hex guardados en la opción', 1 === preg_match( '/^[a-f0-9]{64}$/', $s ) && $s === CN_Suscribir::secreto() );

seccion( 'modo off (default): redirect a /planes-club/ y cero efectos' );
$w = sp_preparar( sp_ficha(), 'off' );
t( 'el modo por defecto es off', ( function () {
	update_option( CN_Suscribir::OPT_MODO, '' );
	$a = 'off' === CN_Suscribir::modo();
	update_option( CN_Suscribir::OPT_MODO, 'log' );
	$b = 'off' === CN_Suscribir::modo();
	update_option( CN_Suscribir::OPT_MODO, 'off' );
	return $a && $b;
} )() );
$r = CN_Suscribir::procesar( sp_token() );
t( 'off: redirect a /planes-club/, caso apagado', SP_PLANES === $r['redirect'] && 'apagado' === $r['caso'] );
t( 'off: ni lectura de la base, ni MP, ni log, ni transients', ! $w->consultas && ! $GLOBALS['cn_http_log'] && ! $w->log && ! $GLOBALS['cn_transients'] );

seccion( 'secreto vacío = endpoint apagado' );
$w = sp_preparar( sp_ficha(), 'on', '' );
$r = CN_Suscribir::procesar( '21.basico.mail_d5.1800000000.' . str_repeat( 'a', 64 ) );
t( 'on sin secreto: redirect a /planes-club/, caso sin_secreto, nada creado ni consultado', SP_PLANES === $r['redirect'] && 'sin_secreto' === $r['caso'] && ! $w->consultas && ! $GLOBALS['cn_http_log'] && ! sp_pendientes( $w ) );

seccion( 'token inválido / vencido / ficha inexistente: redirect, nada creado, nada en pendientes' );
foreach ( array(
	'formato malo'      => array( 'cualquier-cosa', 'formato' ),
	'firma mala'        => array( '21.basico.mail_d5.1800000000.' . str_repeat( 'b', 64 ), 'firma' ),
	'vencido'           => array( sp_token( 21, 'basico', 'mail_d5', time() - 60 ), 'vencido' ),
	'ficha inexistente' => array( sp_token( 999 ), 'sin_ficha' ),
) as $nombre => $caso ) {
	$w = sp_preparar();
	$r = CN_Suscribir::procesar( $caso[0] );
	t( "{$nombre}: redirect a /planes-club/ (caso token_{$caso[1]})", SP_PLANES === $r['redirect'] && 'token_' . $caso[1] === $r['caso'], $r['caso'] );
	t( "{$nombre}: cero POST a MP, cero pendientes, cero escrituras en miembros", ! cn_http_calls( '#/preapproval#' ) && ! sp_pendientes( $w ) && ! $w->escrituras( 'cn_miembros' ) );
	t( "{$nombre}: queda rastro suscribir_token_invalido con el motivo", in_array( 'suscribir_token_invalido', $w->log_mp_tipos(), true ) && false !== strpos( sp_payloads( $w, 'suscribir_token_invalido' ), '"motivo":"' . $caso[1] . '"' ) );
}

seccion( 'ficha ok: UNA pendiente sub_ con el origen del token y redirect al init_point' );
$w = sp_preparar();
$_COOKIE = array( '_fbp' => 'fb.1.1700000000000.111' );
$r = CN_Suscribir::procesar( sp_token( 21, 'basico', 'mail_d5' ) );
$p = sp_pendientes( $w );
t( 'redirect 302 al init_point de MP, caso creada', 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=pre_9' === $r['redirect'] && 'creada' === $r['caso'] && 21 === $r['ficha_id'] );
t( 'UNA sola pendiente', 1 === count( $p ) );
$fila = $p ? $p[0][2] : array();
t( 'external_reference sub_<celular>_<hash>', 0 === strpos( (string) ( $fila['external_reference'] ?? '' ), 'sub_' . SP_CEL . '_' ) );
t( 'origen = el del token (mail_d5), plan basico, nombre de la ficha, celular normalizado y email vacío (null)', 'mail_d5' === ( $fila['origen'] ?? '' ) && 'basico' === ( $fila['plan'] ?? '' ) && 'Socia Prueba' === ( $fila['nombre_apellido'] ?? '' ) && array_key_exists( 'email', $fila ) && null === $fila['email'] && SP_CEL === ( $fila['celular'] ?? '' ) );
t( 'ip, user_agent y fbp del navegador (como el formulario de planes)', '190.1.2.3' === ( $fila['ip'] ?? '' ) && 'Mozilla/5.0 (test)' === ( $fila['user_agent'] ?? '' ) && 'fb.1.1700000000000.111' === ( $fila['fbp'] ?? '' ) );
$mp = cn_http_calls( '#/preapproval$#' );
$body = $mp ? json_decode( $mp[0]['body'], true ) : array();
t( 'UN solo POST a MP: /preapproval sin plan (sin preapproval_plan_id), con external_reference sub_, status pending, SIN payer_email aunque la ficha tenga un email válido', 1 === count( $mp ) && 'POST' === $mp[0]['metodo'] && ! isset( $body['preapproval_plan_id'] ) && ( $body['external_reference'] ?? '' ) === $fila['external_reference'] && 'pending' === ( $body['status'] ?? '' ) && ! array_key_exists( 'payer_email', $body ) && false === strpos( $mp[0]['body'], '@' ) && false === strpos( $mp[0]['body'], 'socia.prueba' ) && 15500.0 === (float) $body['auto_recurring']['transaction_amount'] );
t( 'ningún GET a MP ni otro POST (nunca cancelar/pausar/cobrar)', 1 === count( array_filter( $GLOBALS['cn_http_log'], function ( $c ) {
	return false !== strpos( $c['url'], 'mercadopago' );
} ) ) );
t( 'cero UPDATE/DELETE en wp_cn_miembros', ! $w->escrituras( 'cn_miembros' ) );
t( 'guarda el init_point en la pendiente (columna aditiva) para poder reusarla', ( function () use ( $w ) {
	foreach ( $w->escrituras( 'suscripciones_pendientes' ) as $l ) {
		if ( 'update' === $l[0] && isset( $l[2]['init_point'] ) && 0 === strpos( $l[2]['init_point'], 'https://www.mercadopago.com.ar/' ) ) {
			return true;
		}
	}
	return false;
} )() );
t( 'rastro suscribir_creada con ficha_id, plan y origen; sin email (ni enmascarado) ni celular', in_array( 'suscribir_creada', $w->log_mp_tipos(), true ) && ( function () use ( $w ) {
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && 'suscribir_creada' === $l[2]['tipo'] ) {
			$p = (string) $l[2]['payload'];
			return false !== strpos( $p, '"ficha_id":21' ) && false !== strpos( $p, '"origen":"mail_d5"' ) && false === strpos( $p, '"email"' ) && false === strpos( $p, 'so***' ) && false === strpos( $p, 'socia.prueba' ) && false === strpos( $p, SP_CEL );
		}
	}
	return false;
} )() );
foreach ( array( 'mail_d6', 'mail_d7', 'winback', 'upsell' ) as $o ) {
	$w = sp_preparar();
	CN_Suscribir::procesar( sp_token( 21, 'full', $o ) );
	$p = sp_pendientes( $w );
	t( "origen {$o} con plan full: pendiente con ese origen y 32000", 1 === count( $p ) && $o === $p[0][2]['origen'] && 'full' === $p[0][2]['plan'] && 32000.0 === (float) json_decode( cn_http_calls( '#/preapproval$#' )[0]['body'], true )['auto_recurring']['transaction_amount'] );
}
$w = sp_preparar( sp_ficha( array( 'email' => 'no-es-email' ) ) );
$r = CN_Suscribir::procesar( sp_token() );
t( 'ficha con email inválido: se crea igual, sin payer_email', 'creada' === $r['caso'] && null === sp_pendientes( $w )[0][2]['email'] && ! isset( json_decode( cn_http_calls( '#/preapproval$#' )[0]['body'], true )['payer_email'] ) );
foreach ( array( 'basico' => 'mail_d5', 'full' => 'winback' ) as $plan_e => $origen_e ) {
	$w = sp_preparar( sp_ficha( array( 'email' => 'Otra.Cuenta+club@Hotmail.com' ) ) );
	$r = CN_Suscribir::procesar( sp_token( 21, $plan_e, $origen_e ) );
	$mp_e = cn_http_calls( '#/preapproval$#' );
	t( "ficha con email válido ({$plan_e}/{$origen_e}): se crea y el email se envía VACÍO (ni a MP ni a la pendiente)", 'creada' === $r['caso'] && 1 === count( $mp_e ) && ! array_key_exists( 'payer_email', json_decode( $mp_e[0]['body'], true ) ) && false === stripos( $mp_e[0]['body'], 'hotmail' ) && 1 === count( sp_pendientes( $w ) ) && null === sp_pendientes( $w )[0][2]['email'] );
}
$fuente_e = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-suscribir.php' );
t( 'el código llama a crear_suscripcion_link con email literal vacío y no usa mascara_email', false !== strpos( $fuente_e, '$celular, ' . "''" . ', $origen, $meta )' ) && false === strpos( $fuente_e, 'mascara_email' ) );
$w = sp_preparar( sp_ficha( array( 'estado' => 'cancelada', 'preapproval_id' => 'PRE_VIEJA', 'baja_en' => null ) ) );
$r = CN_Suscribir::procesar( sp_token( 21, 'basico', 'winback' ) );
t( 'win-back: ficha cancelada con una suscripción vieja vinculada NO cuenta como activa: se crea la nueva', 'creada' === $r['caso'] && 1 === count( sp_pendientes( $w ) ) );
$w = sp_preparar( sp_ficha( array( 'estado' => 'activo', 'preapproval_id' => 'PRE_CONBAJA', 'baja_en' => '2026-11-30 00:00:00' ) ) );
$r = CN_Suscribir::procesar( sp_token() );
t( 'activa pero con baja programada: no cuenta como activa, se crea', 'creada' === $r['caso'] );

seccion( 'segundo clic en < 24 h: la MISMA pendiente, no otra' );
$w = sp_preparar();
$w->on( '/SELECT external_reference, init_point FROM wp_cn_suscripciones_pendientes WHERE celular = \'' . SP_CEL . '\' AND plan = \'basico\'/', array( cn_fila( array( 'external_reference' => 'sub_' . SP_CEL . '_abcd1234', 'init_point' => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=pre_previa' ) ) ) );
$r = CN_Suscribir::procesar( sp_token() );
t( 'reusa: redirect al init_point de la pendiente previa, caso reusada', 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=pre_previa' === $r['redirect'] && 'reusada' === $r['caso'] );
t( 'reusa: cero POST a MP, cero pendientes nuevas, rastro suscribir_reusada', ! cn_http_calls( '#/preapproval#' ) && ! sp_pendientes( $w ) && in_array( 'suscribir_reusada', $w->log_mp_tipos(), true ) );
t( 'la consulta de reuso pide usado = 0, últimas 24 h e init_point cargado', ( function () use ( $w ) {
	foreach ( $w->consultas as $q ) {
		if ( false !== strpos( $q, 'SELECT external_reference, init_point FROM' ) ) {
			return false !== strpos( $q, 'usado = 0' ) && false !== strpos( $q, "creado >= '" . substr( gmdate( 'Y-m-d H', time() - 86400 ), 0, 13 ) ) && false !== strpos( $q, "init_point <> ''" );
		}
	}
	return false;
} )() );
$w = sp_preparar();
$w->on( '/SELECT external_reference, init_point FROM wp_cn_suscripciones_pendientes WHERE celular = \'' . SP_CEL . '\' AND plan = \'full\'/', array( cn_fila( array( 'external_reference' => 'x', 'init_point' => 'https://www.mercadopago.com.ar/x' ) ) ) );
$r = CN_Suscribir::procesar( sp_token( 21, 'basico' ) );
t( 'la pendiente previa es de OTRO plan: no se reusa, se crea la del plan pedido', 'creada' === $r['caso'] && 1 === count( sp_pendientes( $w ) ) );
$w = sp_preparar();
$w->on( '/SELECT external_reference, init_point FROM wp_cn_suscripciones_pendientes/', array( cn_fila( array( 'external_reference' => 'x', 'init_point' => 'javascript:alert(1)' ) ) ) );
$r = CN_Suscribir::procesar( sp_token() );
t( 'un init_point guardado que no es https no se usa para redirigir: se crea una nueva', 'creada' === $r['caso'] && 0 === strpos( $r['redirect'], 'https://www.mercadopago.com.ar/' ) );

seccion( 'ficha con suscripción ACTIVA ya vinculada: nada creado, log suscribir_ya_activa + cartel' );
$w = sp_preparar( sp_ficha( array( 'preapproval_id' => 'PRE_ACTIVA000000001', 'estado' => 'activo', 'baja_en' => null ) ) );
$r = CN_Suscribir::procesar( sp_token( 21, 'full', 'upsell' ) );
t( 'redirect a /planes-club/, caso ya_activa', SP_PLANES === $r['redirect'] && 'ya_activa' === $r['caso'] );
t( 'cero POST a MP, cero pendientes, cero escrituras en miembros', ! $GLOBALS['cn_http_log'] && ! sp_pendientes( $w ) && ! $w->escrituras( 'cn_miembros' ) );
t( 'log suscribir_ya_activa + una línea en el cartel (aviso_error con motivo suscribir_ya_activa)', in_array( 'suscribir_ya_activa', $w->log_mp_tipos(), true ) && false !== strpos( sp_payloads( $w, 'aviso_error' ), '"motivo":"suscribir_ya_activa"' ) );
$n_antes = count( $w->log );
CN_Suscribir::procesar( sp_token( 21, 'full', 'upsell' ) );
t( 'segundo clic el mismo día: otro log pero NO otra línea en el cartel', 1 === substr_count( sp_payloads( $w, 'aviso_error' ), '"motivo":"suscribir_ya_activa"' ) && 2 === count( array_keys( $w->log_mp_tipos(), 'suscribir_ya_activa', true ) ) );
t( 'el cartel y el log no llevan email, celular ni preapproval', ( function () use ( $w ) {
	$t = json_encode( $w->log );
	return false === strpos( $t, 'socia.prueba' ) && false === strpos( $t, SP_CEL ) && false === strpos( $t, 'PRE_ACTIVA' );
} )() );

seccion( 'ficha sin celular usable: no se puede armar la pendiente' );
foreach ( array(
	'sin celular_texto_plano' => array( 'celular_texto_plano' => null ),
	'celular inválido'        => array( 'celular_texto_plano' => '123' ),
	'celular que no coincide con el hash' => array( 'celular_texto_plano' => '011 15 5555-0099' ),
) as $nombre => $extra ) {
	$w = sp_preparar( sp_ficha( $extra ) );
	$r = CN_Suscribir::procesar( sp_token() );
	t( "{$nombre}: redirect a /planes-club/, caso sin_celular, nada creado, log suscribir_sin_celular", SP_PLANES === $r['redirect'] && 'sin_celular' === $r['caso'] && ! $GLOBALS['cn_http_log'] && ! sp_pendientes( $w ) && in_array( 'suscribir_sin_celular', $w->log_mp_tipos(), true ) );
}
$w = sp_preparar( sp_ficha( array( 'celular_hash' => '' ) ) );
$r = CN_Suscribir::procesar( sp_token() );
t( 'ficha sin hash (dato viejo) pero con celular válido: se crea', 'creada' === $r['caso'] );

seccion( 'MP falla: redirect a /planes-club/, pendiente borrada (como el formulario), rastro suscribir_fallo' );
$w = sp_preparar();
cn_http_on( 'POST', '#/preapproval$#', 500 );
$r = CN_Suscribir::procesar( sp_token() );
t( 'MP 500: caso fallo, redirect a /planes-club/, la pendiente se borra, log suscribir_fallo', SP_PLANES === $r['redirect'] && 'fallo' === $r['caso'] && in_array( 'suscribir_fallo', $w->log_mp_tipos(), true ) && 1 === count( array_filter( $w->escrituras( 'suscripciones_pendientes' ), function ( $l ) {
	return 'delete' === $l[0];
} ) ) );

seccion( 'rate limit por IP: 8 cada 10 min, después corta' );
$w = sp_preparar();
for ( $i = 0; $i < CN_Suscribir::MAX_INTENTOS; $i++ ) {
	$r = CN_Suscribir::procesar( sp_token() );
}
t( 'los primeros 8 pasan (el 8º sigue creando)', 'creada' === $r['caso'] && CN_Suscribir::MAX_INTENTOS === count( sp_pendientes( $w ) ) );
$n_mp = count( $GLOBALS['cn_http_log'] );
$r    = CN_Suscribir::procesar( sp_token() );
t( 'el 9º: redirect a /planes-club/, caso rate_limit, sin POST a MP ni pendiente', SP_PLANES === $r['redirect'] && 'rate_limit' === $r['caso'] && $n_mp === count( $GLOBALS['cn_http_log'] ) && CN_Suscribir::MAX_INTENTOS === count( sp_pendientes( $w ) ) );
t( 'la cuenta es por IP, con la ventana de 10 min', CN_Suscribir::MAX_INTENTOS === (int) get_transient( 'cn_suscribir_ip_' . md5( '190.1.2.3' ) ) && 600 === CN_Suscribir::VENTANA_SEG );
$_SERVER['REMOTE_ADDR'] = '190.1.2.4';
$r = CN_Suscribir::procesar( sp_token() );
t( 'otra IP no está afectada', 'creada' === $r['caso'] );
$w = sp_preparar( sp_ficha(), 'off' );
$GLOBALS['cn_transients'][ 'cn_suscribir_ip_' . md5( '190.1.2.3' ) ] = 99;
CN_Suscribir::procesar( sp_token() );
t( 'con el modo off el rate limit ni se toca', 99 === $GLOBALS['cn_transients'][ 'cn_suscribir_ip_' . md5( '190.1.2.3' ) ] );

seccion( 'ninguna URL, log ni salida contiene email, celular, "whatsapp" ni "wa.me"' );
$w = sp_preparar();
$r = CN_Suscribir::procesar( sp_token() );
$texto = sp_todo_texto( $w, $r ) . CN_Suscribir::url_token( sp_token() );
$fuente = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-suscribir.php' );
t( 'la URL del endpoint y el redirect no llevan email ni celular', false === strpos( CN_Suscribir::url_token( sp_token() ), '@' ) && false === strpos( CN_Suscribir::url_token( sp_token() ), SP_CEL ) && false === strpos( $r['redirect'], '@' ) && false === strpos( $r['redirect'], SP_CEL ) );
t( 'mp_log de la corrida: sin email completo ni celular', ( function () use ( $w ) {
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) ) {
			$p = (string) $l[2]['payload'];
			if ( false !== strpos( $p, 'socia.prueba@gmail.com' ) || false !== strpos( $p, SP_CEL ) ) {
				return false;
			}
		}
	}
	return true;
} )() );
t( 'nada de lo que salió (log, pedidos, resultado) ni el código menciona WhatsApp', sp_limpio( $texto ) && sp_limpio( $fuente ) );
t( 'el código no habla con MP por su cuenta: ni wp_remote_*, y la única llamada a CN_MP es crear_suscripcion_link', false === strpos( $fuente, 'wp_remote_' ) && preg_match_all( '/CN_MP::(\w+)/', $fuente, $mm ) >= 1 && array( 'crear_suscripcion_link' ) === array_values( array_unique( $mm[1] ) ) );
t( 'el código no escribe en wp_cn_miembros (ni update ni delete)', false === strpos( $fuente, '->update(' ) && false === strpos( $fuente, '->delete(' ) && false === strpos( $fuente, '->insert(' ) );
t( 'crear_suscripcion_link acepta los orígenes de los mails y sigue rechazando uno inventado', is_wp_error( CN_MP::crear_suscripcion_link( 'basico', 'Socia Prueba', SP_CEL, '', 'instagram' ) ) && array( 'panel', 'landing', 'mail_d5', 'mail_d6', 'mail_d7', 'winback', 'upsell' ) === CN_MP::ORIGENES_SUSCRIPCION );
$main = file_get_contents( dirname( __DIR__ ) . '/club-natureza-miembros.php' );
t( 'el plugin carga la clase con guard y la engancha en init', false !== strpos( $main, "class-cn-suscribir.php" ) && false !== strpos( $main, "array( 'CN_Suscribir', 'registrar' )" ) );
$db = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-db.php' );
t( 'DB: una sola columna aditiva (init_point) en suscripciones_pendientes y versión 2.10.0', false !== strpos( $db, 'init_point varchar(500) DEFAULT NULL' ) && '2.10.0' === CN_DB::DB_VERSION );
