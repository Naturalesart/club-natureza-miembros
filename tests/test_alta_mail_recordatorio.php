<?php
// Recordatorio del alta por mail (2º y último mail con link nuevo) + motivo de las sin ficha en panel-datos.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public $headers = array();
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function header( $k, $v ) {
			$this->headers[ $k ] = $v;
		}
	}
	class WP_REST_Request {
		public $h = array();
		public function get_header( $k ) {
			return $this->h[ $k ] ?? null;
		}
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $p = '' ) {
		return 'https://ejemplo.test/wp-json/' . $p;
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $ns, $ruta, $args ) {
		$GLOBALS['cn_rutas'][] = $ns . $ruta;
	}
}
function amrec_id( $n ) {
	return md5( 'rec' . $n );
}
function amrec_item( $n, $hace = 20 * 86400 ) {
	return array( 'id' => amrec_id( $n ), 'status' => 'authorized', 'date_created' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - $hace ), 'auto_recurring' => array( 'transaction_amount' => 15500 ) );
}
/** Modo, corte hace 30 días, retorno activo, MP con las suscripciones y su cobro; $enviados = n => hace cuántos segundos salió el 1er mail. */
function amrec_preparar( $modo, $items, $emails, $enviados = array(), $vinculadas = array(), $conflictos = array() ) {
	cn_test_reset();
	global $wpdb;
	$_GET = $_POST = array();
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	update_option( CN_Alta_Mail::OPT_MODO, $modo );
	update_option( CN_Alta_Mail::OPT_DESDE, time() - 30 * 86400 );
	update_option( CN_Alta_Mail::OPT_URL, 'https://naturalesart.com/suscripcion-lista/' );
	update_option( 'cn_retorno_activo', '1' );
	$env = array();
	foreach ( $enviados as $n => $hace ) {
		$env[ amrec_id( $n ) ] = time() - $hace;
	}
	update_option( CN_Alta_Mail::OPT_ENVIADOS, $env );
	cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => $items, 'paging' => array( 'total' => count( $items ) ) ) );
	foreach ( $emails as $n => $email ) {
		$pid = 7000000 + $n;
		cn_http_on( 'GET', '#/authorized_payments/search\?preapproval_id=' . amrec_id( $n ) . '#', array( 'results' => array( array( 'id' => 1, 'payment' => array( 'id' => $pid, 'status' => 'approved' ) ) ) ) );
		cn_http_on( 'GET', '#/v1/payments/' . $pid . '$#', array( 'id' => $pid, 'status' => 'approved', 'payer' => array( 'id' => 99, 'email' => $email ) ) );
	}
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	$wpdb->on( '/SELECT preapproval_id FROM wp_cn_miembros WHERE preapproval_id IN/', array_map( 'amrec_id', $vinculadas ) );
	$wpdb->on( "/FROM wp_cn_mp_log WHERE tipo = 'sub_conflicto'/", array_map( function ( $n ) {
		return cn_fila( array( 'payload' => json_encode( array( 'origen' => 'retorno_vinculo', 'miembro_id' => 5, 'preapproval_nuevo' => amrec_id( $n ) ) ) ) );
	}, $conflictos ) );
	return $wpdb;
}
function amrec_tipos( $w, $tipo ) {
	return count( array_keys( $w->log_mp_tipos(), $tipo, true ) );
}
function amrec_token( $mail ) {
	return preg_match( '/cn_alta=([a-f0-9.]+)/', $mail['cuerpo'] ?? '', $m ) ? $m[1] : '';
}

seccion( 'Recordatorio: link vencido => reenvía UNA vez con link nuevo' );
$w = amrec_preparar( 'on', array( amrec_item( 1 ) ), array( 1 => 'marta@gmail.com' ), array( 1 => 15 * 86400 ) );
$r = CN_Alta_Mail::correr();
$mail = $GLOBALS['cn_mails'][0] ?? array();
t( 'link vencido: sale 1 recordatorio al email del pagador', 1 === count( $GLOBALS['cn_mails'] ) && 'marta@gmail.com' === ( $mail['to'] ?? '' ) && 1 === $r['recordatorios'] && 0 === $r['acciones'] );
t( 'asunto distinto: "Te falta un paso para entrar al Club Natureza"', 'Te falta un paso para entrar al Club Natureza' === ( $mail['asunto'] ?? '' ) );
$tok = amrec_token( $mail );
t( 'link firmado NUEVO, válido y de 14 días para ESA suscripción', array( 'ok' => true, 'id' => amrec_id( 1 ), 'motivo' => '' ) === CN_Alta_Mail::verificar( $tok ) && abs( (int) explode( '.', $tok )[1] - ( time() + 14 * 86400 ) ) <= 5 );
t( 'queda registrado (recordatorios + log sin email completo) y no se escribe en miembros', isset( get_option( CN_Alta_Mail::OPT_RECORDATORIOS )[ amrec_id( 1 ) ] ) && 1 === amrec_tipos( $w, 'alta_mail_recordatorio_enviado' ) && false === strpos( json_encode( $w->log ), 'marta@gmail.com' ) && ! $w->escrituras( 'cn_miembros' ) );
t( 'solo GET a Mercado Pago (las lecturas de siempre)', array( 'GET' ) === array_values( array_unique( array_column( $GLOBALS['cn_http_log'], 'metodo' ) ) ) );
CN_Alta_Mail::correr();
CN_Alta_Mail::correr();
t( 'corre varias veces y sigue siendo 1 solo recordatorio (máx. 2 mails en total)', 1 === count( $GLOBALS['cn_mails'] ) );

$w = amrec_preparar( 'on', array( amrec_item( 1 ) ), array( 1 => 'marta@gmail.com' ), array( 1 => 14 * 86400 - 12 * 3600 ) );
CN_Alta_Mail::correr();
t( 'faltan menos de 24 hs para que venza: también reenvía', 1 === count( $GLOBALS['cn_mails'] ) );
$w = amrec_preparar( 'on', array( amrec_item( 1 ) ), array( 1 => 'marta@gmail.com' ), array( 1 => 10 * 86400 ) );
CN_Alta_Mail::correr();
t( 'link vigente (faltan 4 días): no reenvía ni consulta el cobro', ! $GLOBALS['cn_mails'] && ! cn_http_calls( '#authorized_payments#' ) );

seccion( 'Recordatorio: tope de 2 mails, vinculada, modo log, conflicto' );
$w = amrec_preparar( 'on', array( amrec_item( 1 ) ), array( 1 => 'marta@gmail.com' ), array( 1 => 40 * 86400 ) );
update_option( CN_Alta_Mail::OPT_RECORDATORIOS, array( amrec_id( 1 ) => time() - 20 * 86400 ) );
CN_Alta_Mail::correr();
t( 'ya hubo 2 mails (alta + recordatorio, los dos vencidos): no manda un 3º', ! $GLOBALS['cn_mails'] && ! cn_http_calls( '#authorized_payments#' ) );

$w = amrec_preparar( 'on', array( amrec_item( 1 ) ), array( 1 => 'marta@gmail.com' ), array( 1 => 15 * 86400 ), array( 1 ) );
CN_Alta_Mail::correr();
t( 'ya vinculada a una ficha: no manda ni consulta el cobro', ! $GLOBALS['cn_mails'] && ! cn_http_calls( '#authorized_payments#' ) );

$w = amrec_preparar( 'log', array( amrec_item( 1 ) ), array( 1 => 'marta@gmail.com' ), array( 1 => 15 * 86400 ) );
$r = CN_Alta_Mail::correr();
$p = '';
foreach ( $w->log as $l ) {
	if ( 'insert' === $l[0] && 'alta_mail_recordatorio_simulado' === ( $l[2]['tipo'] ?? '' ) ) {
		$p = (string) $l[2]['payload'];
	}
}
t( 'modo log: CERO mails, registra "reenviaria" con email enmascarado', ! $GLOBALS['cn_mails'] && 1 === $r['recordatorios'] && false !== strpos( $p, 'reenviaria' ) && false !== strpos( $p, 'ma***@gmail.com' ) && false === strpos( $p, 'marta@gmail.com' ) );
CN_Alta_Mail::correr();
t( 'modo log: no repite la simulación y no la marca como enviada', 1 === amrec_tipos( $w, 'alta_mail_recordatorio_simulado' ) && array() === get_option( CN_Alta_Mail::OPT_RECORDATORIOS, array() ) );

$w = amrec_preparar( 'off', array( amrec_item( 1 ) ), array( 1 => 'marta@gmail.com' ), array( 1 => 15 * 86400 ) );
CN_Alta_Mail::correr();
t( 'modo off: nada (ni MP)', ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_http_log'] );

$w = amrec_preparar( 'on', array( amrec_item( 1 ) ), array( 1 => 'marta@gmail.com' ), array( 1 => 15 * 86400 ), array(), array( 1 ) );
CN_Alta_Mail::correr();
t( 'en conflicto (la ficha ya tiene OTRA suscripción): no manda recordatorio y deja un aviso', ! $GLOBALS['cn_mails'] && 1 === amrec_tipos( $w, 'aviso_error' ) && false !== strpos( json_encode( $w->log ), 'alta_mail_recordatorio_conflicto' ) );

$w = amrec_preparar( 'on', array( amrec_item( 1 ) ), array( 1 => 'marta@gmail.com' ), array( 1 => 15 * 86400 ) );
$GLOBALS['cn_mail_falla'] = true;
CN_Alta_Mail::correr();
t( 'si wp_mail falla: se desmarca (no cuenta como mandado) y queda aviso', array() === get_option( CN_Alta_Mail::OPT_RECORDATORIOS ) && false !== strpos( json_encode( $w->log ), 'alta_mail_fallo_recordatorio' ) );
$GLOBALS['cn_mail_falla'] = false;
CN_Alta_Mail::correr();
CN_Alta_Mail::correr();
t( 'el reintento manda 1 solo recordatorio más (1 intento fallido + 1 enviado, y ahí se corta)', 2 === count( $GLOBALS['cn_mails'] ) && isset( get_option( CN_Alta_Mail::OPT_RECORDATORIOS )[ amrec_id( 1 ) ] ) );

seccion( 'Plantillas de alta: sin WhatsApp' );
cn_test_reset();
CN_Alta_Mail::enviar_mail( 'a@gmail.com', 'https://naturalesart.com/x/?cn_alta=abc' );
CN_Alta_Mail::enviar_mail( 'a@gmail.com', 'https://naturalesart.com/x/?cn_alta=abc', true );
$todo = json_encode( $GLOBALS['cn_mails'], JSON_UNESCAPED_UNICODE );
t( 'ni el mail de alta ni el recordatorio dicen "wa.me" ni "WhatsApp"', 2 === count( $GLOBALS['cn_mails'] ) && false === stripos( $todo, 'wa.me' ) && false === stripos( $todo, 'whatsapp' ) );
t( 'contacto = responder el mail o hola@naturalesart.com (en las dos)', 2 === count( array_filter( $GLOBALS['cn_mails'], function ( $m ) {
	return false !== strpos( $m['cuerpo'], 'respondé este mail' ) && false !== strpos( $m['cuerpo'], 'mailto:hola@naturalesart.com' );
} ) ) );
t( 'el mail de alta conserva su asunto de siempre', 'Tu suscripción al Club Natureza está activa: completá tus datos' === $GLOBALS['cn_mails'][0]['asunto'] );

seccion( 'Panel: motivo de las authorized sin ficha' );
cn_test_reset();
$GLOBALS['cn_ssl'] = true;
update_option( 'cn_panel_api_activo', '1' );
update_option( 'cn_panel_token_hash', hash( 'sha256', 'tok-rec' ) );
update_option( CN_Alta_Mail::OPT_ENVIADOS, array( amrec_id( 2 ) => time() - 15 * 86400, amrec_id( 4 ) => time() - 86400 ) );
$w = $GLOBALS['wpdb'];
$w->on( "/FROM wp_cn_mp_log WHERE tipo = 'aviso_error' AND payload LIKE/", array(
	cn_fila( array( 'fecha' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'payload' => json_encode( array( 'motivo' => 'sub_sin_vincular', 'detalle' => array( 'items' => array(
		array( 'preapproval_id' => amrec_id( 1 ), 'plan' => 'basico', 'monto' => 15500 ),
		array( 'preapproval_id' => amrec_id( 2 ), 'plan' => 'basico', 'monto' => 15500 ),
		array( 'preapproval_id' => amrec_id( 3 ), 'plan' => 'full', 'monto' => 32000 ),
		array( 'preapproval_id' => amrec_id( 4 ), 'plan' => 'full', 'monto' => 32000 ),
	) ) ) ) ) ),
) );
$w->on( "/FROM wp_cn_mp_log WHERE tipo = 'sub_conflicto'/", array( cn_fila( array( 'payload' => json_encode( array( 'miembro_id' => 9, 'preapproval_nuevo' => amrec_id( 1 ), 'preapproval_actual' => 'otra' ) ) ) ) ) );
$req = new WP_REST_Request();
$req->h['x_cn_panel_token'] = 'tok-rec';
$j   = json_decode( json_encode( CN_Panel_API::manejar( $req )->data ), true );
$sf  = $j['alertas']['authorized_sin_ficha'] ?? array();
$mot = array_column( $sf['items'] ?? array(), 'motivo', 'preapproval_id' );
t( 'conflicto: la ficha ya tiene otra suscripción => motivo "conflicto"', 'conflicto' === ( $mot[ amrec_id( 1 ) ] ?? '' ) );
t( 'mail de alta con link vencido => "link_vencido"', 'link_vencido' === ( $mot[ amrec_id( 2 ) ] ?? '' ) );
t( 'sin mail / link vigente => "sin_ficha"', 'sin_ficha' === ( $mot[ amrec_id( 3 ) ] ?? '' ) && 'sin_ficha' === ( $mot[ amrec_id( 4 ) ] ?? '' ) );
t( 'por_motivo cuenta solo números', array( 'sin_ficha' => 2, 'conflicto' => 1, 'link_vencido' => 1 ) === ( $sf['por_motivo'] ?? null ) );
t( 'sin campos nuevos de datos personales en cada item', array( 'preapproval_id', 'plan', 'monto', 'horas_sin_vincular', 'primer_aviso', 'mas_de_72h', 'motivo' ) === array_keys( $sf['items'][0] ?? array() ) );
t( 'el panel no escribe en la base ni llama a MP', array() === $w->log && array() === $GLOBALS['cn_http_log'] );
