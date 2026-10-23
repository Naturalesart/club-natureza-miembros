<?php
// Alerta GRAVE "WordPress no puede enviar mails": hook wp_mail_failed → mp_log + lista acotada; 3+ fallos en 6 hs → UN mail por n8n (máx. 1/24 hs).
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
function mw_reset() {
	cn_test_reset();
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
}
/** Simula lo que hace WordPress: wp_mail falló para esos destinatarios con ese mensaje. */
function mw_fallo( $msg = 'SMTP Error: Could not authenticate.', $to = 'socia@gmail.com' ) {
	CN_Alertas::wp_mail_fallo( new WP_Error( 'wp_mail_failed', $msg, array( 'to' => (array) $to, 'subject' => 'Bienvenida', 'phpmailer_exception_code' => 2 ) ) );
}
function mw_alertas() {
	return array_map( function ( $c ) {
		return json_decode( (string) $c['body'], true );
	}, cn_http_calls( '#club-trial-alerta#' ) );
}
function mw_logs( $tipo ) {
	$o = array();
	foreach ( $GLOBALS['wpdb']->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && $tipo === $l[2]['tipo'] ) {
			$o[] = json_decode( $l[2]['payload'], true );
		}
	}
	return $o;
}
/** Retrocede $seg segundos el fallo número $i de la lista (simula el paso del tiempo). */
function mw_envejecer( $i, $seg ) {
	$l = get_option( CN_Alertas::OPT_WPMAIL_FALLOS );
	$l[ $i ]['ts'] -= $seg;
	update_option( CN_Alertas::OPT_WPMAIL_FALLOS, $l );
}

seccion( 'registro de cada fallo' );
mw_reset();
mw_fallo( "SMTP Error: Could not authenticate.\n  Password not accepted. " . str_repeat( 'x', 300 ), 'maria.gomez@gmail.com' );
$l = mw_logs( 'wp_mail_fallo' );
t( 'cada fallo deja mp_log wp_mail_fallo con el mensaje truncado a 200 y en una línea', 1 === count( $l ) && 200 === mb_strlen( $l[0]['mensaje'] ) && false === strpos( $l[0]['mensaje'], "\n" ) && 0 === strpos( $l[0]['mensaje'], 'SMTP Error: Could not authenticate. Password not accepted.' ) );
t( 'destinatario enmascarado (2 letras + ***@dominio), nunca completo', array( 'ma***@gmail.com' ) === $l[0]['para'] && false === strpos( json_encode( $l[0] ), 'maria.gomez' ) );
t( 'queda en la lista de fallos (opción) con timestamp', 1 === count( get_option( CN_Alertas::OPT_WPMAIL_FALLOS ) ) && abs( get_option( CN_Alertas::OPT_WPMAIL_FALLOS )[0]['ts'] - time() ) <= 2 );
t( 'un solo fallo: sin alerta', ! mw_alertas() );
t( 'máscara: casos borde', 'a***@x.com' === CN_Alertas::mascara_email( 'a@x.com' ) && '***' === CN_Alertas::mascara_email( 'sin-arroba' ) && '***' === CN_Alertas::mascara_email( '' ) );
mw_reset();
mw_fallo( 'x', array( 'ana@example.com', 'bea@example.com' ) );
t( 'varios destinatarios: todos enmascarados', array( 'an***@example.com', 'be***@example.com' ) === mw_logs( 'wp_mail_fallo' )[0]['para'] );
mw_reset();
CN_Alertas::wp_mail_fallo( new WP_Error( 'wp_mail_failed', 'sin datos' ) );
CN_Alertas::wp_mail_fallo( 'un string suelto' );
t( 'un WP_Error sin datos o un valor raro: se registra igual sin romper', 2 === count( mw_logs( 'wp_mail_fallo' ) ) && array() === mw_logs( 'wp_mail_fallo' )[0]['para'] && 'un string suelto' === mw_logs( 'wp_mail_fallo' )[1]['mensaje'] );

seccion( 'umbral: 3 o más fallos en 6 hs' );
mw_reset();
mw_fallo();
mw_fallo();
t( '2 fallos: no alerta', ! mw_alertas() && 2 === count( get_option( CN_Alertas::OPT_WPMAIL_FALLOS ) ) );
mw_fallo();
$a = mw_alertas();
t( '3.er fallo en 6 hs: UNA alerta wp_mail_fallo con el asunto pedido', 1 === count( $a ) && 'wp_mail_fallo' === $a[0]['motivo'] && CN_Alertas::ASUNTO_WPMAIL === $a[0]['asunto'] );
t( 'cuerpo corto: qué pasó, desde cuándo y qué hacer (WP Mail SMTP, prueba de correo, contraseña de aplicación)', false !== strpos( $a[0]['cuerpo'], 'Qué pasó' ) && false !== strpos( $a[0]['cuerpo'], '3 mails' ) && false !== strpos( $a[0]['cuerpo'], 'Desde cuándo' ) && false !== strpos( $a[0]['cuerpo'], 'WP Mail SMTP' ) && false !== strpos( $a[0]['cuerpo'], 'Prueba de correo' ) && false !== strpos( $a[0]['cuerpo'], 'myaccount.google.com/apppasswords' ) );
t( 'el cuerpo no trae emails de destinatarios', false === strpos( $a[0]['cuerpo'], '@gmail.com' ) && false === strpos( json_encode( $a[0] ), 'socia@' ) );
t( 'deja alerta_grave_enviada', 1 === count( mw_logs( 'alerta_grave_enviada' ) ) );
mw_fallo();
mw_fallo();
mw_fallo();
t( 'siguen fallando: no manda otro mail dentro de las 24 hs', 1 === count( mw_alertas() ) && 6 === count( mw_logs( 'wp_mail_fallo' ) ) );
update_option( CN_Alertas::OPT_MAIL_WPMAIL, time() - 25 * 3600 );
mw_fallo();
t( 'pasadas 24 hs del mail y sigue fallando: vuelve a avisar (1 por día)', 2 === count( mw_alertas() ) );

seccion( 'ventana de 6 hs: los fallos viejos no cuentan' );
mw_reset();
mw_fallo();
mw_fallo();
mw_envejecer( 0, 7 * 3600 );
mw_envejecer( 1, 6 * 3600 + 60 );
mw_fallo();
t( '2 fallos de hace más de 6 hs + 1 nuevo = no alerta', ! mw_alertas() );
mw_fallo();
t( '2 recientes tampoco', ! mw_alertas() );
mw_fallo();
t( 'al 3.º reciente sí alerta y cuenta solo los 3 de la ventana', 1 === count( mw_alertas() ) && false !== strpos( mw_alertas()[0]['cuerpo'], '3 mails' ) );
mw_reset();
mw_fallo();
mw_envejecer( 0, 5 * 3600 );
mw_fallo();
mw_fallo();
t( 'un fallo de hace 5 hs sí cuenta (está dentro de la ventana)', 1 === count( mw_alertas() ) );
t( 'no hace falta limpiar nada cuando un mail sale bien: la lista solo guarda fallos y la ventana los deja atrás', ( function () {
	mw_reset();
	mw_fallo();
	mw_fallo();
	$GLOBALS['cn_mails'] = array();
	wp_mail( 'ok@example.com', 'ok', 'ok' ); // un envío exitoso no pasa por el hook
	mw_envejecer( 0, 7 * 3600 );
	mw_envejecer( 1, 7 * 3600 );
	mw_fallo();
	return ! mw_alertas() && 3 === count( get_option( CN_Alertas::OPT_WPMAIL_FALLOS ) );
} )() );

seccion( 'fallos de otros plugins, lista acotada, sin escrituras fuera de mp_log/options, sin re-entrada' );
mw_reset();
CN_Alertas::wp_mail_fallo( new WP_Error( 'wp_mail_failed', 'WooCommerce: SMTP connect() failed.', array( 'to' => array( 'cliente@tienda.com' ), 'subject' => 'Pedido #123' ) ) );
CN_Alertas::wp_mail_fallo( new WP_Error( 'wp_mail_failed', 'Contact Form 7', array( 'to' => 'admin@naturalesart.com' ) ) );
mw_fallo();
t( 'un fallo de otro plugin cuenta igual (3 en total → alerta)', 1 === count( mw_alertas() ) && array( 'cl***@tienda.com' ) === mw_logs( 'wp_mail_fallo' )[0]['para'] );
mw_reset();
for ( $i = 0; $i < 60; $i++ ) {
	mw_fallo( 'fallo ' . $i );
}
$l = get_option( CN_Alertas::OPT_WPMAIL_FALLOS );
t( 'la lista se acota a 50 entradas (las más nuevas)', 50 === count( $l ) && 'fallo 59' === end( $l )['mensaje'] && 'fallo 10' === $l[0]['mensaje'] );
t( 'con 60 fallos seguidos, un solo mail', 1 === count( mw_alertas() ) );
mw_reset();
mw_fallo();
mw_fallo();
mw_fallo();
$w = $GLOBALS['wpdb'];
t( 'no escribe nada fuera de mp_log (ni miembros ni sesiones) ni manda mails por wp_mail', ! $w->escrituras( 'cn_miembros' ) && ! $w->escrituras( 'sesiones' ) && ! $w->transaccion() && ! array_filter( $w->log, function ( $l ) {
	return 'insert' === $l[0] && false === strpos( $l[1], 'mp_log' );
} ) && ! $GLOBALS['cn_mails'] );
t( 'solo toca sus dos opciones', array( CN_Alertas::OPT_WPMAIL_FALLOS, CN_Alertas::OPT_MAIL_WPMAIL ) === array_keys( $GLOBALS['cn_opts'] ) );
t( 'no consulta a Mercado Pago ni hace GET alguno', ! array_filter( $GLOBALS['cn_http_log'], function ( $c ) {
	return 'POST' !== $c['metodo'] || false === strpos( $c['url'], 'club-trial-alerta' );
} ) );
mw_reset();
$p = new ReflectionProperty( 'CN_Alertas', 'en_wpmail_fallo' );
$p->setValue( null, true );
mw_fallo();
$p->setValue( null, false );
t( 'guarda contra re-entrada: con el flag puesto no registra nada', ! mw_logs( 'wp_mail_fallo' ) && array() === get_option( CN_Alertas::OPT_WPMAIL_FALLOS, array() ) );
mw_fallo();
t( '...y al terminar el flag queda bajo (el siguiente fallo se registra normal)', 1 === count( mw_logs( 'wp_mail_fallo' ) ) && false === $p->getValue() );
t( 'el hook está enganchado en el archivo principal', false !== strpos( file_get_contents( dirname( __DIR__ ) . '/club-natureza-miembros.php' ), "add_action( 'wp_mail_failed', array( 'CN_Alertas', 'wp_mail_fallo' ) )" ) );
t( 'el asunto es distinto de los otros 3', 4 === count( array_unique( array( CN_Alertas::ASUNTO_SYNC, CN_Alertas::ASUNTO_TOKEN, sprintf( CN_Alertas::ASUNTO_CASOS, 1 ), CN_Alertas::ASUNTO_WPMAIL ) ) ) );
