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
t( 'celular en 2 socias: no adivina, log retorno_ambiguo, aviso en el cartel (sin n8n), sin escrituras', false !== strpos( $h, 'WhatsApp' ) && ! rp_esc() && in_array( 'retorno_ambiguo', $w->log_mp_tipos(), true ) && ! cn_http_calls( '#club-trial-alerta#' ) && in_array( CN_Webhook::TIPO_AVISO, $w->log_mp_tipos(), true ) );
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
for ( $i = 0; $i < 4; $i++ ) {
	rp_post( array( 'cn_ret_celular' => 'x' ) );
	$h = CN_Retorno::shortcode();
}
t( 'rate limit: el 4.º intento sobre la misma suscripción queda bloqueado (sin formulario)', false === strpos( $h, '<form' ) && ! rp_esc() );
function rp_payload( $w, $tipo ) {
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && $tipo === ( $l[2]['tipo'] ?? '' ) ) {
			return $l[2]['payload'];
		}
	}
	return '';
}

seccion( 'retorno: éxito uniforme (2a)' );
$w = rp_preparar( array() );
rp_post( array( 'cn_ret_nombre' => 'Ana Nueva', 'cn_ret_email' => 'ana@example.com' ) );
$h_nueva = CN_Retorno::shortcode();
$w = rp_preparar( array( $a ) );
rp_post( array( 'cn_ret_nombre' => 'Socia Secreta 10' ) );
$h_exist = CN_Retorno::shortcode();
t( '2a: el texto de éxito es idéntico para nueva y existente', $h_nueva === $h_exist && false !== strpos( $h_nueva, 'Tu suscripción quedó activada' ) && false === strpos( $h_nueva, 'creada' ) && false === strpos( $h_nueva, 'Encontramos' ) );

seccion( 'retorno: segundo factor (nombre O email)' );
$a2 = rp_fila( 10, '1155552165', array( 'email' => 'Guardado.Socia@Example.com' ) ); // con email guardado
function rp_ok( $w ) {
	return 1 === count( rp_esc() );
}
$w = rp_preparar( array( $a2 ) );
rp_post( array( 'cn_ret_nombre' => 'Maria Gonzalez Perez', 'cn_ret_email' => '  guardado.socia@example.COM ' ) );
$h = CN_Retorno::shortcode();
t( 'email coincide (mayúsculas/espacios) aunque el nombre no: activa', rp_ok( $w ) && false !== strpos( $h, 'Tu suscripción quedó activada' ) );
$w = rp_preparar( array( $a2 ) );
rp_post( array( 'cn_ret_nombre' => 'Socia Secreta 10', 'cn_ret_email' => 'otro@example.com' ) );
CN_Retorno::shortcode();
t( 'nombre coincide aunque el email no: activa', rp_ok( $w ) );
$w = rp_preparar( array( $a2 ) );
rp_post( array( 'cn_ret_nombre' => 'Maria Gonzalez Perez', 'cn_ret_email' => 'otro@example.com' ) );
$h = CN_Retorno::shortcode();
t( 'ni nombre ni email coinciden: no escribe, sin mail/Meta, vuelve el formulario con el aviso', ! rp_esc() && ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] && false !== strpos( $h, '<form' ) && false !== strpos( $h, CN_Retorno::MSG_REVISAR ) && false === strpos( $h, 'Secreta' ) );
t( 'deja rastro sin nombre/celular y NO avisa a n8n', in_array( 'retorno_nombre_distinto', $w->log_mp_tipos(), true ) && false === strpos( rp_payload( $w, 'retorno_nombre_distinto' ), 'Maria' ) && false === strpos( rp_payload( $w, 'retorno_nombre_distinto' ), '1155552165' ) && ! cn_http_calls( '#club-trial-alerta#' ) );
$sin_mail = rp_fila( 10, '1155552165', array( 'email' => '' ) );
$w = rp_preparar( array( $sin_mail ) );
rp_post( array( 'cn_ret_nombre' => 'Maria Gonzalez Perez', 'cn_ret_email' => '' ) );
CN_Retorno::shortcode();
t( 'ficha sin email y nombre distinto: no activa', ! rp_esc() );
$w = rp_preparar( array( $sin_mail ) );
rp_post( array( 'cn_ret_nombre' => 'Maria Gonzalez Perez', 'cn_ret_email' => 'cualquiera@example.com' ) );
CN_Retorno::shortcode();
t( 'ficha sin email: un email tipeado no vale (solo el nombre)', ! rp_esc() );
$w = rp_preparar( array( $sin_mail ) );
rp_post( array( 'cn_ret_nombre' => 'Socia Secreta 10' ) );
CN_Retorno::shortcode();
t( 'ficha sin email y nombre parecido: activa', rp_ok( $w ) );
$w = rp_preparar( array( $a2 ) ); // el email de una ficha, pero un celular que no existe: NO activa a esa ficha
rp_post( array( 'cn_ret_celular' => '11 4444-9999', 'cn_ret_nombre' => 'Otra Cualquiera', 'cn_ret_email' => 'guardado.socia@example.com' ) );
CN_Retorno::shortcode();
t( 'solo el email, sin celular exacto: no activa la ficha 10 (no hay ficha)', ! array_filter( rp_esc(), function ( $e ) {
	return 'update' === $e[0] && array( 'id' => 10 ) === $e[3];
} ) );
$w = rp_preparar( array( $a2 ) );
rp_post( array( 'cn_ret_nombre' => 'Maria Gonzalez Perez', 'cn_ret_email' => '' ) );
$h = CN_Retorno::shortcode();
t( 'ficha existente sin pasar ningún factor: aviso MSG_REVISAR', false !== strpos( $h, CN_Retorno::MSG_REVISAR ) );
$w = rp_preparar( array() );
rp_post( array( 'cn_ret_nombre' => '', 'cn_ret_email' => '' ) );
$h2 = CN_Retorno::shortcode();
t( 'celular nuevo sin datos: mismo aviso (no distingue de una ficha existente)', false !== strpos( $h2, CN_Retorno::MSG_REVISAR ) );

seccion( 'retorno: reintentos y tope de 3' );
$w = rp_preparar( array( $a2 ) );
$h = array();
for ( $i = 1; $i <= 3; $i++ ) {
	rp_post( array( 'cn_ret_nombre' => 'Maria Gonzalez Perez', 'cn_ret_email' => 'otro@example.com' ) );
	$h[ $i ] = CN_Retorno::shortcode();
}
t( 'intentos 1 y 2: vuelve el formulario con el aviso', false !== strpos( $h[1], '<form' ) && false !== strpos( $h[2], '<form' ) && false !== strpos( $h[2], CN_Retorno::MSG_REVISAR ) );
t( 'intento 3 fallido: sin formulario, salida por WhatsApp', false === strpos( $h[3], '<form' ) && false !== strpos( $h[3], 'WhatsApp' ) && ! rp_esc() );
rp_post( array( 'cn_ret_nombre' => 'Socia Secreta 10' ) ); // el 4.º, aunque ahora sea correcto, ya no pasa
$h4 = CN_Retorno::shortcode();
t( 'intento 4 (aunque sea correcto): bloqueado, no escribe', false === strpos( $h4, '<form' ) && ! rp_esc() );
$_POST = array();
$_GET['preapproval_id'] = RP_ID;
t( 'abrir la página de nuevo con intentos agotados: sin formulario', false === strpos( CN_Retorno::shortcode(), '<form' ) );

seccion( 'retorno: botón de WhatsApp' );
$w = rp_preparar( array( $a2 ) );
update_option( 'cn_retorno_whatsapp', '+54 9 11 2233-4455' );
set_transient( 'cn_ret_envio_id_' . md5( RP_ID ), 3, 3600 );
$_GET['preapproval_id'] = RP_ID;
$h = CN_Retorno::shortcode();
$esperado = 'https://wa.me/5491122334455?text=' . rawurlencode( 'Hola! Pagué mi suscripción en el Club Natureza y no puedo entrar. Código: ' . substr( RP_ID, 0, 8 ) );
t( 'con número en Config: botón wa.me con mensaje precargado y primeros 8 del preapproval', false !== strpos( $h, 'href="' . $esperado . '"' ) && false === strpos( $h, RP_ID ) );
$w = rp_preparar( array( $a2 ) );
set_transient( 'cn_ret_envio_id_' . md5( RP_ID ), 3, 3600 );
$_GET['preapproval_id'] = RP_ID;
$h = CN_Retorno::shortcode();
t( 'sin número en Config: no hay botón ni wa.me', false === strpos( $h, 'wa.me' ) && false === strpos( $h, '<a ' ) && false !== strpos( $h, CN_Retorno::TEXTO_CONTACTO ) );
$w = rp_preparar( array( $a2 ) );
update_option( 'cn_retorno_whatsapp', '12ab' ); // basura: no es un número
set_transient( 'cn_ret_envio_id_' . md5( RP_ID ), 3, 3600 );
$_GET['preapproval_id'] = RP_ID;
t( 'número inválido en Config: tampoco hay botón', false === strpos( CN_Retorno::shortcode(), 'wa.me' ) );
$adm = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-admin.php' );
t( 'Config guarda la opción cn_retorno_whatsapp solo con dígitos', 'cn_retorno_whatsapp' === CN_Retorno::OPT_WHATSAPP && false !== strpos( $adm, "CN_Retorno::OPT_WHATSAPP, preg_replace( '/\\D+/'," ) );

seccion( 'retorno: lista de pendientes' );
$w = rp_preparar( array( $a2 ) );
t( 'sin pendientes: el cartel no se muestra', '' === CN_Retorno::cartel_pendientes() );
for ( $i = 1; $i <= 3; $i++ ) {
	rp_post( array( 'cn_ret_nombre' => 'Maria Gonzalez Perez', 'cn_ret_email' => 'otro@example.com' ) );
	CN_Retorno::shortcode();
}
$p = get_option( 'cn_retorno_pendientes' );
t( 'agotados los 3 intentos: queda 1 pendiente con fecha y motivo, sin nombres ni celulares', is_array( $p ) && 1 === count( $p ) && isset( $p[ RP_ID ] ) && 'intentos_agotados' === $p[ RP_ID ]['motivo'] && ! empty( $p[ RP_ID ]['fecha'] ) && false === strpos( json_encode( $p ), 'Maria' ) && false === strpos( json_encode( $p ), '1155' ) && false === strpos( json_encode( $p ), '@' ) );
$w->on( '/WHERE preapproval_id IN/', array() ); // todavía sin vincular
$c = CN_Retorno::cartel_pendientes();
t( 'el cartel dice "1 clienta esperando acceso" con el código corto y el enlace a Vincular en lote precargado', false !== strpos( $c, '1 clienta esperando acceso' ) && false !== strpos( $c, substr( RP_ID, 0, 8 ) ) && false !== strpos( $c, 'cn_vl_pre=' . RP_ID ) && false !== strpos( $c, '#cn-vincular-lote' ) && false === strpos( $c, 'Maria' ) );
$_GET['cn_vl_pre'] = RP_ID;
ob_start();
CN_Vinculo_Lote::render( null );
$html = ob_get_clean();
unset( $_GET['cn_vl_pre'] );
t( 'Vincular en lote abre con el preapproval precargado en el cuadro', false !== strpos( $html, '>' . RP_ID . ' </textarea>' ) );
$w->on( '/WHERE preapproval_id IN/', array( (object) array( 'preapproval_id' => RP_ID ) ) ); // ya quedó vinculada a una socia
t( 'al quedar vinculada, el pendiente se borra solo y el cartel desaparece', '' === CN_Retorno::cartel_pendientes() && array() === get_option( 'cn_retorno_pendientes' ) );
$w = rp_preparar( array() );
cn_http_on( 'GET', '#/preapproval/' . str_repeat( 'b', 32 ) . '#', 404 );
set_transient( 'cn_ret_envio_id_' . md5( str_repeat( 'b', 32 ) ), 3, 3600 );
$_GET['preapproval_id'] = str_repeat( 'b', 32 );
CN_Retorno::shortcode();
t( 'un ID inventado (MP no lo confirma) no genera pendiente', ! get_option( 'cn_retorno_pendientes' ) );
$w = rp_preparar( array() );
update_option( 'cn_retorno_pendientes', array( RP_ID => array( 'fecha' => '2026-10-15 10:00:00', 'motivo' => 'intentos_agotados' ) ) );
rp_post( array( 'cn_ret_nombre' => 'Ana Nueva', 'cn_ret_email' => 'ana@example.com' ) );
CN_Retorno::shortcode();
t( 'si finalmente se activa por la página, el pendiente se saca', array() === get_option( 'cn_retorno_pendientes' ) );
t( 'la pantalla Socias muestra el cartel', false !== strpos( $adm, 'CN_Retorno::cartel_pendientes()' ) );

seccion( 'retorno: topes por suscripción, celular e IP' );
$w = rp_preparar( array() );
$h = '';
$ok3 = true;
for ( $i = 1; $i <= 4; $i++ ) {
	$_SERVER['REMOTE_ADDR'] = '9.9.9.' . $i; // IPs distintas: solo puede frenar el tope por suscripción.
	rp_post( array( 'cn_ret_celular' => '11 5555-' . str_pad( (string) ( 2000 + $i ), 4, '0', STR_PAD_LEFT ), 'cn_ret_nombre' => 'Ana Nueva', 'cn_ret_email' => '' ) ); // celulares distintos; sin email: no crea
	$h = CN_Retorno::shortcode();
	if ( $i <= 3 ) {
		$ok3 = $ok3 && ( false !== strpos( $h, CN_Retorno::MSG_REVISAR ) || false !== strpos( $h, 'WhatsApp' ) );
	}
}
t( '2c: tope por preapproval_id: 3 intentos pasan, el 4.º se bloquea aunque cambie la IP y el celular', $ok3 && false === strpos( $h, '<form' ) && ! rp_esc() );
$w = rp_preparar( array() );
set_transient( 'cn_ret_envio_cel_' . md5( '1155552165' ), 5, 3600 );
rp_post( array( 'cn_ret_nombre' => 'Ana Nueva', 'cn_ret_email' => 'ana@example.com' ) );
$h = CN_Retorno::shortcode();
t( '2c: tope por celular (5/hora): bloquea sin escribir', false !== strpos( $h, 'con ese celular' ) && ! rp_esc() );
$w = rp_preparar( array() );
set_transient( 'cn_ret_envio_cel_' . md5( '1155552165' ), 4, 3600 );
rp_post( array( 'cn_ret_nombre' => 'Ana Nueva', 'cn_ret_email' => 'ana@example.com' ) );
$h = CN_Retorno::shortcode();
t( '2c: con 4 envíos previos del celular todavía pasa (crea)', false === strpos( $h, 'muchos intentos' ) && 1 === count( rp_esc() ) );
$w = rp_preparar( array() );
set_transient( 'cn_ret_envio_' . md5( '1.2.3.4' ), 5, 600 );
rp_post( array( 'cn_ret_nombre' => 'Ana Nueva', 'cn_ret_email' => 'ana@example.com' ) );
$h = CN_Retorno::shortcode();
t( '2c: el tope por IP sigue funcionando', false !== strpos( $h, 'muchos intentos' ) && ! rp_esc() );

seccion( 'retorno: textos' );
$w = rp_preparar( array(), null, null, '0' );
$h = CN_Retorno::shortcode();
t( '2d: gate apagado usa el texto nuevo', false !== strpos( $h, CN_Retorno::TEXTO_CONTACTO ) );
$w = rp_preparar( array() );
$h = CN_Retorno::shortcode();
t( '2d: sin parámetros usa el texto nuevo', false !== strpos( $h, CN_Retorno::TEXTO_CONTACTO ) );
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-retorno.php' );
t( '2d: ya no queda "para activarte" ni "te contactamos" en la clase', false === strpos( $src, 'para activarte' ) && false === strpos( $src, 'te contactamos' ) );
t( 'ninguna alerta a n8n por retorno_nombre_distinto en el código', false === strpos( $src, "sub_avisar_una_vez_por_hora( 'retorno_nombre_distinto'" ) );

seccion( 'retorno: registro de visitas' );
// Tres visitas seguidas sin resetear (rp_preparar borra las opciones): formulario, ID inválido y error de MP.
$w = rp_preparar( array() );
$_GET['preapproval_id'] = RP_ID;
CN_Retorno::shortcode();
$_GET['preapproval_id'] = 'no-es-hex';
CN_Retorno::shortcode();
cn_http_on( 'GET', '#/preapproval/' . RP_ID . '#', 503 );
$_GET['preapproval_id'] = strtoupper( RP_ID );
CN_Retorno::shortcode();
$reg = get_option( 'cn_retorno_registro' );
t( 'cada visita GET se registra', is_array( $reg ) && 3 === count( $reg ), json_encode( $reg ) );
t( 'con formulario: ID válido, 8 primeros y caso formulario', $reg[0]['valido'] && substr( RP_ID, 0, 8 ) === $reg[0]['id8'] && 'formulario' === $reg[0]['caso'] );
t( 'ID inválido: valido=no, sin guardar lo que llegó, caso genérico', ! $reg[1]['valido'] && '' === $reg[1]['id8'] && 'generico' === $reg[1]['caso'] && false === strpos( json_encode( $reg[1] ), 'hex' ) );
t( 'MP caído: caso error_mp', $reg[2]['valido'] && 'error_mp' === $reg[2]['caso'] );
t( 'el registro no tiene nombres, celulares ni emails ni el ID completo', false === strpos( json_encode( $reg ), RP_ID ) && false === strpos( json_encode( $reg ), '@' ) && false === strpos( json_encode( $reg ), '1155' ) );
t( 'cada entrada tiene fecha', ! empty( $reg[0]['fecha'] ) && ! empty( $reg[2]['fecha'] ) );

$w = rp_preparar( array(), null, rp_mp( 'pending' ) );
$_GET['preapproval_id'] = RP_ID;
CN_Retorno::shortcode();
$w = null;
t( 'suscripción no autorizada: caso genérico', 'generico' === get_option( 'cn_retorno_registro' )[0]['caso'] );

rp_preparar( array() );
$_GET = array();
CN_Retorno::shortcode();
$e = get_option( 'cn_retorno_registro' );
t( 'visita sin parámetros: se registra como no válida', 1 === count( $e ) && ! $e[0]['valido'] && 'generico' === $e[0]['caso'] );

rp_preparar( array(), null, null, '0' );
$_GET['preapproval_id'] = RP_ID;
CN_Retorno::shortcode();
t( 'gate apagado: no se registra nada', false === get_option( 'cn_retorno_registro', false ) );

rp_preparar( array() );
rp_post( array( 'cn_ret_nombre' => 'Ana Nueva', 'cn_ret_email' => 'ana@example.com' ) );
CN_Retorno::shortcode();
t( 'un envío del formulario (POST) no se registra como visita', false === get_option( 'cn_retorno_registro', false ) );

rp_preparar( array() );
$ant = array();
for ( $i = 1; $i <= 60; $i++ ) {
	$ant[] = array( 'fecha' => '2026-10-15 10:00:00', 'valido' => false, 'id8' => '', 'caso' => 'generico', 'n' => $i );
}
update_option( 'cn_retorno_registro', $ant );
$_GET['preapproval_id'] = RP_ID;
CN_Retorno::shortcode();
$r = get_option( 'cn_retorno_registro' );
t( 'guarda solo las últimas 50 (descarta las más viejas)', 50 === count( $r ) && 'formulario' === $r[49]['caso'] && 12 === $r[0]['n'] );

$html = CN_Retorno::registro_html();
t( 'sección Registro de retorno: tabla con las visitas, la más nueva primero', false !== strpos( $html, 'Registro de retorno' ) && false !== strpos( $html, 'Formulario' ) && strpos( $html, 'Formulario' ) < strpos( $html, 'Mensaje genérico' ) && false !== strpos( $html, substr( RP_ID, 0, 8 ) ) );
t( 'la sección es de solo lectura (sin formularios ni botones)', false === strpos( $html, '<form' ) && false === strpos( $html, '<button' ) && false === strpos( $html, '<input' ) );
rp_preparar( array() );
t( 'sin visitas: mensaje vacío', false !== strpos( CN_Retorno::registro_html(), 'Todavía no hay visitas' ) );
$adm = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-admin.php' );
t( 'la pantalla Socias muestra el registro al final', false !== strpos( $adm, 'CN_Retorno::registro_html()' ) && strpos( $adm, 'CN_Retorno::registro_html()' ) > strpos( $adm, '<h2>Listado</h2>' ) );
