<?php
// Punto 5: página de retorno [cn_retorno_suscripcion] (CN_Retorno).
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
const RP_ID = '7e9baadc542b441fb37131549f6bbad7';
function rp_mp( $status = 'authorized', $monto = 15500 ) {
	return array(
		'id' => RP_ID, 'status' => $status, 'payer_email' => 'pagadora@example.com',
		'next_payment_date' => '2026-11-10T12:00:00.000-03:00', 'auto_recurring' => array( 'transaction_amount' => $monto ),
	);
}
function rp_fila( $id, $cel, $extra = array() ) {
	return cn_fila( array_merge( array(
		'id' => $id, 'nombre_apellido' => 'Socia Secreta ' . $id, 'estado' => 'activo', 'preapproval_id' => null, 'plan' => null,
		'acceso_hasta' => null, 'email' => 'guardado' . $id . '@example.com', 'fecha_fin_trial' => '2026-10-14 04:06:41', 'fecha_modificacion' => null,
		'baja_en' => null, 'celular_hint' => substr( $cel, -4 ), 'celular_hash' => CN_Helpers::hash_celular( $cel ), 'celular_texto_plano' => $cel,
	), $extra ) );
}
function rp_preparar( $filas, $vinculada = null, $mp = null, $flag = '1' ) {
	cn_test_reset();
	global $wpdb;
	$_GET = $_POST = array();
	$_SERVER['REMOTE_ADDR'] = '1.2.3.4';
	$GLOBALS['cn_nonce_ok'] = true;
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	update_option( 'cn_retorno_activo', $flag );
	cn_http_on( 'GET', '#/preapproval/' . RP_ID . '#', null === $mp ? rp_mp() : $mp );
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	$wpdb->on( '/celular_hint = /', function ( $sql ) use ( $filas ) {
		return array_values( array_filter( $filas, function ( $f ) use ( $sql ) {
			return false !== strpos( $sql, "'" . $f->celular_hint . "'" );
		} ) );
	} );
	$wpdb->on( '/celular_texto_plano LIKE/', function ( $sql ) use ( $filas ) {
		return array_values( array_filter( $filas, function ( $f ) use ( $sql ) {
			return false !== strpos( $sql, "'" . substr( (string) $f->celular_texto_plano, 0, 8 ) . "%'" );
		} ) );
	} );
	$wpdb->on( '/FROM wp_cn_miembros$/', $filas );
	foreach ( $filas as $f ) {
		$wpdb->on( '/WHERE id = ' . $f->id . ' FOR UPDATE/', array( $f ) );
	}
	$wpdb->on( '/WHERE preapproval_id = /', $vinculada ? array( $vinculada ) : array() );
	return $wpdb;
}
function rp_post( $extra = array() ) {
	$_POST = array_merge( array( 'cn_ret_submit' => '1', 'cn_ret_id' => RP_ID, 'cn_ret_nonce' => 'x', 'cn_ret_celular' => '11 5555-2165', 'cn_ret_nombre' => 'Socia Secreta 10', 'cn_ret_email' => '' ), $extra );
}
function rp_esc() {
	return $GLOBALS['wpdb']->escrituras( 'cn_miembros' );
}

seccion( 'retorno: apagado / sin ID' );
rp_preparar( array(), null, null, '0' );
$_GET['preapproval_id'] = RP_ID;
$h = CN_Retorno::shortcode();
t( 'apagado: mensaje genérico, sin formulario ni consulta a MP', false === strpos( $h, '<form' ) && ! $GLOBALS['cn_http_log'] );
rp_preparar( array() );
$h = CN_Retorno::shortcode();
t( 'sin preapproval_id: mensaje genérico, sin consulta a MP', false === strpos( $h, '<form' ) && ! $GLOBALS['cn_http_log'] );
$_GET['preapproval_id'] = 'no-es-hex';
$h = CN_Retorno::shortcode();
t( 'ID inválido: genérico, sin consulta a MP', false === strpos( $h, '<form' ) && ! $GLOBALS['cn_http_log'] );

seccion( 'retorno: GET' );
$w = rp_preparar( array() );
$_GET['preapproval_id'] = strtoupper( RP_ID );
$h = CN_Retorno::shortcode();
t( 'authorized: formulario con celular, nombre, email, honeypot, nonce e ID', false !== strpos( $h, 'name="cn_ret_celular"' ) && false !== strpos( $h, 'name="cn_ret_nombre"' ) && false !== strpos( $h, 'name="cn_ret_email"' ) && false !== strpos( $h, 'name="cn_ret_web"' ) && false !== strpos( $h, 'cn_ret_nonce' ) && false !== strpos( $h, 'value="' . RP_ID . '"' ) );
t( 'GET no escribe nada', ! rp_esc() );
$w = rp_preparar( array() , null, rp_mp( 'pending' ) );
$_GET['preapproval_id'] = RP_ID;
$h = CN_Retorno::shortcode();
t( 'no authorized: mensaje amable, sin formulario, log retorno_no_autorizado, sin escrituras', false === strpos( $h, '<form' ) && false !== strpos( $h, 'WhatsApp' ) && in_array( 'retorno_no_autorizado', $w->log_mp_tipos(), true ) && ! rp_esc() );
$w = rp_preparar( array(), null, rp_mp( 'authorized', 9999 ) );
$_GET['preapproval_id'] = RP_ID;
$h = CN_Retorno::shortcode();
t( 'monto sin plan: sin formulario y con log', false === strpos( $h, '<form' ) && in_array( 'retorno_no_autorizado', $w->log_mp_tipos(), true ) );
foreach ( array( 404, 401, 429, 503 ) as $code ) {
	$w = rp_preparar( array() );
	cn_http_on( 'GET', '#/preapproval/' . RP_ID . '#', $code );
	$_GET['preapproval_id'] = RP_ID;
	$h = CN_Retorno::shortcode();
	t( "MP {$code}: mensaje genérico, sin formulario, mp_api_error, sin escrituras", false === strpos( $h, '<form' ) && in_array( 'mp_api_error', $w->log_mp_tipos(), true ) && ! rp_esc() );
}

seccion( 'retorno: POST alta nueva' );
$w = rp_preparar( array() );
rp_post( array( 'cn_ret_nombre' => 'Ana Nueva', 'cn_ret_email' => 'ana@example.com', 'cn_ret_celular' => '+54 9 11 5555-2165' ) );
$h   = CN_Retorno::shortcode();
$esc = rp_esc();
t( 'crea socia activa con preapproval, plan y acceso_hasta', 1 === count( $esc ) && 'insert' === $esc[0][0] && RP_ID === $esc[0][2]['preapproval_id'] && 'basico' === $esc[0][2]['plan'] && '2026-11-10 15:00:00' === $esc[0][2]['acceso_hasta'] && 'activo' === $esc[0][2]['estado'] );
t( 'confirmación con link de login', false !== strpos( $h, '/club-natureza-miembros/' ) && false !== strpos( $h, 'Tu suscripción quedó activada' ) );
t( 'log retorno_vinculo (sin vinculo_manual) y sin email/celular en el log', in_array( 'retorno_vinculo', $w->log_mp_tipos(), true ) && ! in_array( 'vinculo_manual', $w->log_mp_tipos(), true ) && false === strpos( rp_payload( $w, 'retorno_vinculo' ), '@' ) && false === strpos( rp_payload( $w, 'retorno_vinculo' ), '1155552165' ) );
t( 'mail + Subscribe una vez (event_id vinc_<id>)', 1 === count( $GLOBALS['cn_mails'] ) && 'ana@example.com' === $GLOBALS['cn_mails'][0]['to'] && 1 === count( $GLOBALS['cn_capi'] ) && 'vinc_' . RP_ID === $GLOBALS['cn_capi'][0][0] );
$w = rp_preparar( array() );
rp_post( array( 'cn_ret_celular' => '1155552165' ) );
$h = CN_Retorno::shortcode();
t( 'celular nuevo sin nombre/email: vuelve al formulario y no escribe', false !== strpos( $h, '<form' ) && ! rp_esc() );

seccion( 'retorno: POST socia existente (celular exacto)' );
$a = rp_fila( 10, '1155552165' );
$w = rp_preparar( array( $a ) );
rp_post( array( 'cn_ret_email' => 'otro@example.com', 'cn_ret_nombre' => 'socia secreta 10' ) );
$h   = CN_Retorno::shortcode();
$esc = rp_esc();
t( 'activa a la socia 10 (update) con backup previo', 1 === count( $esc ) && 'update' === $esc[0][0] && array( 'id' => 10 ) === $esc[0][3] && in_array( 'vinculo_backup', $w->log_mp_tipos(), true ) );
t( 'no muestra nombre ni email de la socia ni el tipeado', false === strpos( $h, 'Secreta' ) && false === strpos( $h, 'guardado10' ) && false === strpos( $h, 'otro@example.com' ) && false !== strpos( $h, 'Tu suscripción quedó activada' ) );
t( 'el mail va al email guardado, no al tipeado', 1 === count( $GLOBALS['cn_mails'] ) && 'guardado10@example.com' === $GLOBALS['cn_mails'][0]['to'] );
$ya = rp_fila( 10, '1155552165', array( 'preapproval_id' => RP_ID, 'plan' => 'basico', 'acceso_hasta' => '2026-11-10 15:00:00', 'fecha_fin_trial' => null ) );
$w  = rp_preparar( array( $ya ), $ya );
rp_post();
$h = CN_Retorno::shortcode();
t( 'doble submit: ok, cero escrituras, sin mail ni Meta', false !== strpos( $h, 'Tu suscripción quedó activada' ) && ! rp_esc() && ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] );

seccion( 'retorno: ambigüedad y conflictos' );
$b = rp_fila( 11, '1155552165' );
$w = rp_preparar( array( $a, $b ) );
rp_post();
$h = CN_Retorno::shortcode();
t( 'celular en 2 socias: no adivina, log retorno_ambiguo, alerta n8n, sin escrituras', false !== strpos( $h, 'WhatsApp' ) && ! rp_esc() && in_array( 'retorno_ambiguo', $w->log_mp_tipos(), true ) && 1 === count( cn_http_calls( '#club-trial-alerta#' ) ) );
$g = rp_fila( 12, '1155552167' );
$w = rp_preparar( array( $g ) );
rp_post( array( 'cn_ret_nombre' => 'Otra Persona', 'cn_ret_email' => 'otra@example.com' ) );
$h = CN_Retorno::shortcode();
t( 'solo hay una parecida (****2167): no crea, no adivina, retorno_ambiguo', false !== strpos( $h, 'WhatsApp' ) && ! rp_esc() && in_array( 'retorno_ambiguo', $w->log_mp_tipos(), true ) );
$w = rp_preparar( array( rp_fila( 10, '1155552165', array( 'preapproval_id' => 'otro0000000000000000000000000000' ) ) ) );
rp_post();
$h = CN_Retorno::shortcode();
t( 'socia con OTRO preapproval: mensaje de contacto, no pisa, log sub_conflicto', false !== strpos( $h, 'WhatsApp' ) && ! rp_esc() && in_array( 'sub_conflicto', $w->log_mp_tipos(), true ) );
$w = rp_preparar( array( $a ), rp_fila( 77, '1166667777', array( 'preapproval_id' => RP_ID ) ) );
rp_post();
$h = CN_Retorno::shortcode();
t( 'preapproval ya en OTRA socia: rechaza sin escribir', false !== strpos( $h, 'WhatsApp' ) && ! rp_esc() );
$w = rp_preparar( array( $a ) );
cn_http_on( 'GET', '#/preapproval/' . RP_ID . '#', 500 );
rp_post();
$h = CN_Retorno::shortcode();
t( 'MP 500 al enviar: no escribe y avisa genérico', ! rp_esc() && in_array( 'mp_api_error', $w->log_mp_tipos(), true ) );

seccion( 'retorno: seguridad' );
$w = rp_preparar( array( $a ) );
rp_post( array( 'cn_ret_web' => 'http://spam' ) );
$h = CN_Retorno::shortcode();
t( 'honeypot lleno: no consulta MP ni escribe', ! rp_esc() && ! $GLOBALS['cn_http_log'] );
$w = rp_preparar( array( $a ) );
$GLOBALS['cn_nonce_ok'] = false;
rp_post();
$h = CN_Retorno::shortcode();
t( 'nonce inválido: no escribe', ! rp_esc() && false !== strpos( $h, 'venció' ) );
$w = rp_preparar( array() );
$bloqueado = false;
for ( $i = 0; $i < 6; $i++ ) {
	rp_post( array( 'cn_ret_celular' => 'x' ) );
	$h = CN_Retorno::shortcode();
	$bloqueado = false !== strpos( $h, 'muchos intentos' );
}
t( 'rate limit: el 6.º envío en 10 min queda bloqueado', $bloqueado );
function rp_payload( $w, $tipo ) {
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && $tipo === ( $l[2]['tipo'] ?? '' ) ) {
			return $l[2]['payload'];
		}
	}
	return '';
}

seccion( 'retorno: éxito uniforme y nombre (fix/retorno-seguro)' );
// 2a) mismo mensaje para socia nueva y existente.
$w = rp_preparar( array() );
rp_post( array( 'cn_ret_nombre' => 'Ana Nueva', 'cn_ret_email' => 'ana@example.com' ) );
$h_nueva = CN_Retorno::shortcode();
$w = rp_preparar( array( $a ) );
rp_post( array( 'cn_ret_nombre' => 'Socia Secreta 10' ) );
$h_exist = CN_Retorno::shortcode();
t( '2a: el texto de éxito es idéntico para nueva y existente', $h_nueva === $h_exist && false !== strpos( $h_nueva, 'Tu suscripción quedó activada' ) && false === strpos( $h_nueva, 'creada' ) && false === strpos( $h_nueva, 'Encontramos' ) );

// 2b) ficha existente: el nombre tiene que parecerse.
$w = rp_preparar( array( $a ) );
rp_post( array( 'cn_ret_nombre' => 'Maria Gonzalez Perez' ) );
$h = CN_Retorno::shortcode();
t( '2b: nombre distinto en ficha existente: no escribe, no manda mail/Meta, mensaje neutro', ! rp_esc() && ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] && false !== strpos( $h, CN_Retorno::TEXTO_CONTACTO ) && false === strpos( $h, '<form' ) && false === strpos( $h, 'Secreta' ) );
t( '2b: deja rastro retorno_nombre_distinto sin nombre ni celular', in_array( 'retorno_nombre_distinto', $w->log_mp_tipos(), true ) && false === strpos( rp_payload( $w, 'retorno_nombre_distinto' ), 'Maria' ) && false === strpos( rp_payload( $w, 'retorno_nombre_distinto' ), '1155552165' ) );
$w = rp_preparar( array( $a ) );
rp_post( array( 'cn_ret_nombre' => '' ) );
$h = CN_Retorno::shortcode();
t( '2b: nombre vacío en ficha existente: no escribe', ! rp_esc() );
$w = rp_preparar( array( $a ) );
rp_post( array( 'cn_ret_nombre' => 'Secreta Socia 10' ) ); // mismas palabras, otro orden
CN_Retorno::shortcode();
t( '2b: mismas palabras en otro orden: activa', 1 === count( rp_esc() ) );
$w = rp_preparar( array( $a ) );
rp_post( array( 'cn_ret_nombre' => 'Socia Secreta 1O' ) ); // una letra distinta
CN_Retorno::shortcode();
t( '2b: a ≤2 letras de distancia: activa', 1 === count( rp_esc() ) );

// 2c) topes por preapproval_id y por celular, además del de IP.
$w = rp_preparar( array() );
$h = '';
for ( $i = 1; $i <= 6; $i++ ) {
	$_SERVER['REMOTE_ADDR'] = '9.9.9.' . $i; // IPs distintas: solo puede frenar el tope por suscripción.
	rp_post( array( 'cn_ret_celular' => '11 5555-' . str_pad( (string) ( 2000 + $i ), 4, '0', STR_PAD_LEFT ) ) ); // celulares distintos
	$h = CN_Retorno::shortcode();
	if ( $i <= 5 ) {
		$ok5 = isset( $ok5 ) ? $ok5 && false === strpos( $h, 'muchos intentos' ) : false === strpos( $h, 'muchos intentos' );
	}
}
t( '2c: tope por preapproval_id: 5 pasan, el 6.º se bloquea aunque cambie la IP y el celular', $ok5 && false !== strpos( $h, 'con esta suscripción' ) );
$w = rp_preparar( array() );
set_transient( 'cn_ret_envio_cel_' . md5( '1155552165' ), 5, 3600 );
rp_post( array( 'cn_ret_nombre' => 'Ana Nueva', 'cn_ret_email' => 'ana@example.com' ) );
$h = CN_Retorno::shortcode();
t( '2c: tope por celular (5/hora): bloquea sin escribir ni consultar MP', false !== strpos( $h, 'con ese celular' ) && ! rp_esc() && ! $GLOBALS['cn_http_log'] );
$w = rp_preparar( array() );
set_transient( 'cn_ret_envio_cel_' . md5( '1155552165' ), 4, 3600 );
rp_post( array( 'cn_ret_nombre' => 'Ana Nueva', 'cn_ret_email' => 'ana@example.com' ) );
$h = CN_Retorno::shortcode();
t( '2c: con 4 envíos previos del celular todavía pasa (crea)', false === strpos( $h, 'muchos intentos' ) && 1 === count( rp_esc() ) );
$w = rp_preparar( array() );
set_transient( 'cn_ret_envio_id_' . md5( RP_ID ), 5, 3600 );
rp_post( array( 'cn_ret_nombre' => 'Ana Nueva', 'cn_ret_email' => 'ana@example.com' ) );
$h = CN_Retorno::shortcode();
t( '2c: tope por preapproval_id ya lleno: bloquea sin escribir', false !== strpos( $h, 'con esta suscripción' ) && ! rp_esc() );
$w = rp_preparar( array() );
set_transient( 'cn_ret_envio_' . md5( '1.2.3.4' ), 5, 600 );
rp_post( array( 'cn_ret_nombre' => 'Ana Nueva', 'cn_ret_email' => 'ana@example.com' ) );
$h = CN_Retorno::shortcode();
t( '2c: el tope por IP sigue funcionando', false !== strpos( $h, 'muchos intentos' ) && ! rp_esc() );

seccion( 'retorno: textos (fix/retorno-seguro)' );
$w = rp_preparar( array(), null, null, '0' );
$h = CN_Retorno::shortcode();
t( '2d: gate apagado usa el texto nuevo', false !== strpos( $h, CN_Retorno::TEXTO_CONTACTO ) );
$w = rp_preparar( array() );
$h = CN_Retorno::shortcode();
t( '2d: sin parámetros usa el texto nuevo', false !== strpos( $h, CN_Retorno::TEXTO_CONTACTO ) );
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-retorno.php' );
t( '2d: ya no queda "para activarte" ni "te contactamos" en la clase', false === strpos( $src, 'para activarte' ) && false === strpos( $src, 'te contactamos' ) );
