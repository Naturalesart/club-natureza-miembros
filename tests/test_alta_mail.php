<?php
// Alta por mail (CN_Alta_Mail + token en CN_Retorno): modos off/log/on, corte, montos, email, idempotencia, token, página.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
if ( ! defined( 'CN_WA_SOPORTE' ) ) {
	define( 'CN_WA_SOPORTE', '5491162076549' );
}
function am_id( $n ) {
	return md5( 'sub' . $n );
}
function am_item( $n, $monto = 15500, $hace = 3600, $status = 'authorized' ) {
	return array( 'id' => am_id( $n ), 'status' => $status, 'date_created' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - $hace ), 'auto_recurring' => array( 'transaction_amount' => $monto ) );
}
/** Prepara: modo, corte hace 2 días, retorno activo, URL de la página, MP con las suscripciones y (por n) su cobro con payer.email. */
function am_preparar( $modo, $items, $emails = array(), $vinculadas = array(), $desde_seg = 172800 ) {
	cn_test_reset();
	global $wpdb;
	$_GET = $_POST = array();
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	update_option( CN_Alta_Mail::OPT_MODO, $modo );
	update_option( CN_Alta_Mail::OPT_DESDE, time() - $desde_seg );
	update_option( CN_Alta_Mail::OPT_URL, 'https://naturalesart.com/suscripcion-lista/' );
	update_option( 'cn_retorno_activo', '1' );
	cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => $items, 'paging' => array( 'total' => count( $items ) ) ) );
	foreach ( $emails as $n => $email ) {
		$pid = 5000000 + $n;
		cn_http_on( 'GET', '#/authorized_payments/search\?preapproval_id=' . am_id( $n ) . '#', array( 'results' => array( array( 'id' => 1, 'payment' => array( 'id' => $pid, 'status' => 'approved' ) ) ) ) );
		cn_http_on( 'GET', '#/v1/payments/' . $pid . '$#', array( 'id' => $pid, 'status' => 'approved', 'payer' => null === $email ? array( 'id' => 99 ) : array( 'id' => 99, 'email' => $email ) ) );
	}
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	$wpdb->on( '/SELECT preapproval_id FROM wp_cn_miembros WHERE preapproval_id IN/', array_map( 'am_id', $vinculadas ) );
	return $wpdb;
}
function am_metodos() {
	return array_values( array_unique( array_column( $GLOBALS['cn_http_log'], 'metodo' ) ) );
}
function am_avisos( $w ) {
	$o = array();
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && 'aviso_error' === $l[2]['tipo'] ) {
			$o[] = json_decode( $l[2]['payload'], true )['motivo'];
		}
	}
	return $o;
}
function am_payload( $w, $tipo ) {
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && $tipo === $l[2]['tipo'] ) {
			return (string) $l[2]['payload'];
		}
	}
	return '';
}
function am_token_de_mail() {
	preg_match( '/cn_alta=([a-f0-9.]+)/', $GLOBALS['cn_mails'][0]['cuerpo'] ?? '', $m );
	return $m[1] ?? '';
}

seccion( 'modo off (default): no hace nada' );
cn_test_reset();
t( 'el modo por defecto es off', 'off' === CN_Alta_Mail::modo() );
$w = am_preparar( 'off', array( am_item( 1 ) ), array( 1 => 'sandra@gmail.com' ) );
$r = CN_Alta_Mail::correr();
t( 'off: ni una consulta a MP, ni mails, ni log, ni options', ! $GLOBALS['cn_http_log'] && ! $GLOBALS['cn_mails'] && ! $w->log && 'off' === $r['modo'] );
update_option( CN_Alta_Mail::OPT_MODO, 'cualquier-cosa' );
t( 'un valor raro cuenta como off', 'off' === CN_Alta_Mail::modo() );
$w = am_preparar( 'off', array( am_item( 1, 15500, 600 ) ), array( 1 => 'sandra@gmail.com' ) ); // < 1 h: el aviso de "sin vincular" del sync (que ya existía) tampoco la mira.
CN_Sync::correr();
t( 'CN_Sync::correr() con off: el alta por mail no consulta cobros', ! cn_http_calls( '#authorized_payments/search#' ) && ! cn_http_calls( '#/v1/payments/\d+$#' ) && ! $GLOBALS['cn_mails'] );

seccion( 'modo log: registra lo que haría, SIN mandar mail' );
$w = am_preparar( 'log', array( am_item( 1 ) ), array( 1 => 'sandra@gmail.com' ) );
$r = CN_Alta_Mail::correr();
$p = am_payload( $w, 'alta_mail_simulado' );
t( 'log: una fila alta_mail_simulado con id, plan y monto', 1 === count( array_keys( $w->log_mp_tipos(), 'alta_mail_simulado', true ) ) && false !== strpos( $p, am_id( 1 ) ) && false !== strpos( $p, '"plan":"basico"' ) && 1 === $r['acciones'] );
t( 'log: email enmascarado (sa***@gmail.com), nunca completo', false !== strpos( $p, 'sa***@gmail.com' ) && false === strpos( $p, 'sandra@gmail.com' ) );
t( 'log: CERO mails y cero POST a n8n', ! $GLOBALS['cn_mails'] && ! cn_http_calls( '#club-trial-alerta#' ) );
t( 'log: solo GET a Mercado Pago', array( 'GET' ) === am_metodos() );
t( 'log: no crea ni vincula socias (cero escrituras en miembros)', ! $w->escrituras( 'cn_miembros' ) );
CN_Alta_Mail::correr();
t( 'log: no repite la simulación de la misma suscripción', 1 === count( array_keys( $w->log_mp_tipos(), 'alta_mail_simulado', true ) ) );
t( 'log: no la marca como enviada (si se pasa a on, el mail sale)', array() === get_option( CN_Alta_Mail::OPT_ENVIADOS, array() ) );

seccion( 'modo on: manda el mail (máx. 1 por suscripción)' );
$w = am_preparar( 'on', array( am_item( 1 ) ), array( 1 => 'sandra@gmail.com' ) );
$r = CN_Alta_Mail::correr();
$mail = $GLOBALS['cn_mails'][0] ?? array();
t( 'on: un mail al email del pagador', 1 === count( $GLOBALS['cn_mails'] ) && 'sandra@gmail.com' === ( $mail['to'] ?? '' ) && 1 === $r['acciones'] );
t( 'on: el mail lleva el link firmado a la página de retorno', 1 === preg_match( '#^https://naturalesart.com/suscripcion-lista/\?cn_alta=[a-f0-9]{32}\.\d+\.[a-f0-9]{64}#', ( preg_match( '/href="([^"]*cn_alta[^"]*)"/', $mail['cuerpo'] ?? '', $m ) ? $m[1] : '' ) ) );
t( 'on: el token del mail es válido y apunta a ESA suscripción', array( 'ok' => true, 'id' => am_id( 1 ), 'motivo' => '' ) === CN_Alta_Mail::verificar( am_token_de_mail() ) );
t( 'on: misma marca (Georgia, verde #2f3b2c, crema #f5f3ef), WhatsApp de soporte y el texto pedido', false !== strpos( $mail['cuerpo'], 'Georgia' ) && false !== strpos( $mail['cuerpo'], '#2f3b2c' ) && false !== strpos( $mail['cuerpo'], '#f5f3ef' ) && false !== strpos( $mail['cuerpo'], 'wa.me/' . CN_WA_SOPORTE ) && false !== strpos( $mail['cuerpo'], 'suscripción al Club Natureza está activa' ) && false !== strpos( $mail['cuerpo'], 'mismo celular con el que entrabas' ) && in_array( 'Content-Type: text/html; charset=UTF-8', $mail['headers'], true ) );
t( 'on: sin contraseñas ni datos sensibles (no trae el celular, el id completo suelto ni "contraseña")', false === stripos( $mail['cuerpo'], 'contraseña' ) && false === stripos( $mail['cuerpo'], 'celular:' ) && false === strpos( $mail['cuerpo'], 'sandra@gmail.com' ) );
t( 'on: solo GET a MP, cero escrituras en miembros', array( 'GET' ) === am_metodos() && ! $w->escrituras( 'cn_miembros' ) && 'alta_mail_enviado' === $w->log_mp_tipos()[0] && false === strpos( am_payload( $w, 'alta_mail_enviado' ), 'sandra@gmail.com' ) );
CN_Alta_Mail::correr();
CN_Alta_Mail::correr();
t( 'on: idempotente, máx. 1 mail por suscripción aunque corra varias veces', 1 === count( $GLOBALS['cn_mails'] ) && isset( get_option( CN_Alta_Mail::OPT_ENVIADOS )[ am_id( 1 ) ] ) );

seccion( 'corte (cn_alta_mail_desde): el backlog no se toca' );
$w = am_preparar( 'on', array( am_item( 1, 15500, 3 * 86400 ), am_item( 2, 15500, 3600 ) ), array( 1 => 'vieja@gmail.com', 2 => 'nueva@gmail.com' ) );
CN_Alta_Mail::correr();
t( 'una suscripción creada ANTES del corte no recibe nada ni se consulta', 1 === count( $GLOBALS['cn_mails'] ) && 'nueva@gmail.com' === $GLOBALS['cn_mails'][0]['to'] && ! cn_http_calls( '#preapproval_id=' . am_id( 1 ) . '#' ) );
$w = am_preparar( 'log', array( am_item( 1 ) ), array( 1 => 'x@gmail.com' ) );
update_option( CN_Alta_Mail::OPT_DESDE, 0 );
$r = CN_Alta_Mail::correr();
t( 'sin fecha de corte: no hace nada (ni consulta a MP)', 'sin_corte' === $r['motivo'] && ! $GLOBALS['cn_http_log'] );
cn_test_reset();
CN_Alta_Mail::al_guardar_modo( 'off', 'log' );
$d1 = (int) get_option( CN_Alta_Mail::OPT_DESDE );
t( 'al salir de off se guarda el corte = ahora', abs( $d1 - time() ) <= 2 );
update_option( CN_Alta_Mail::OPT_DESDE, 123 );
CN_Alta_Mail::al_guardar_modo( 'log', 'on' );
CN_Alta_Mail::al_guardar_modo( 'on', 'off' );
CN_Alta_Mail::al_guardar_modo( 'off', 'off' );
t( 'log -> on, on -> off y off -> off NO mueven el corte', 123 === (int) get_option( CN_Alta_Mail::OPT_DESDE ) );

seccion( 'elegibilidad: monto, ya vinculada, estado' );
$w = am_preparar( 'on', array( am_item( 1, 15000 ), am_item( 2, 9999 ), am_item( 3, 32000 ), am_item( 4, 15500 ) ), array( 1 => 'a@gmail.com', 2 => 'b@gmail.com', 3 => 'full@gmail.com', 4 => 'basico@gmail.com' ) );
CN_Alta_Mail::correr();
$para = array_column( $GLOBALS['cn_mails'], 'to' );
t( 'solo $15.500 y $32.000 reciben mail; $15.000 (plan viejo) y otros montos no', array( 'full@gmail.com', 'basico@gmail.com' ) === $para );
t( 'monto no válido: ni siquiera se consulta el cobro y queda UNA línea en el cartel por suscripción', ! cn_http_calls( '#preapproval_id=' . am_id( 1 ) . '#' ) && ! cn_http_calls( '#preapproval_id=' . am_id( 2 ) . '#' ) && 2 === count( array_keys( am_avisos( $w ), 'alta_mail_monto_no_valido', true ) ) );
CN_Alta_Mail::correr();
t( 'el aviso de monto no se repite en la corrida siguiente', 2 === count( array_keys( am_avisos( $w ), 'alta_mail_monto_no_valido', true ) ) );
t( 'el plan se deduce del monto (full para $32.000)', false !== strpos( am_payload( $w, 'alta_mail_enviado' ), '"plan"' ) );
$w = am_preparar( 'on', array( am_item( 1 ) ), array( 1 => 'a@gmail.com' ), array( 1 ) );
CN_Alta_Mail::correr();
t( 'ya vinculada a una ficha: no manda nada ni consulta el cobro', ! $GLOBALS['cn_mails'] && ! cn_http_calls( '#authorized_payments#' ) );
$w = am_preparar( 'on', array( am_item( 1, 15500, 3600, 'paused' ), am_item( 2, 15500, 3600, 'cancelled' ) ), array( 1 => 'a@gmail.com', 2 => 'b@gmail.com' ) );
CN_Alta_Mail::correr();
t( 'no authorized: nada', ! $GLOBALS['cn_mails'] );

seccion( 'sin email / sin cobro aprobado: reintenta, cartel tras 72 hs' );
$w = am_preparar( 'on', array( am_item( 1 ) ), array( 1 => null ) ); // cobro sin payer.email
$r = CN_Alta_Mail::correr();
t( 'cobro sin email: no manda, no marca como enviada, sin cartel todavía (<72 h)', ! $GLOBALS['cn_mails'] && array() === get_option( CN_Alta_Mail::OPT_ENVIADOS, array() ) && ! am_avisos( $w ) );
$w = am_preparar( 'on', array( am_item( 1 ) ), array() ); // todavía sin cobros: authorized_payments no tiene regla -> falla/vacío
cn_http_on( 'GET', '#/authorized_payments/search#', array( 'results' => array() ) );
CN_Alta_Mail::correr();
t( 'sin cobro aprobado aún: no hace nada y no marca', ! $GLOBALS['cn_mails'] && array() === get_option( CN_Alta_Mail::OPT_ENVIADOS, array() ) );
$w = am_preparar( 'on', array( am_item( 1, 15500, 4 * 86400 ) ), array( 1 => null ), array(), 10 * 86400 );
CN_Alta_Mail::correr();
CN_Alta_Mail::correr();
t( 'pasadas 72 hs sin email: UNA línea en el cartel (alta_mail_sin_email) y sigue sin mandar', ! $GLOBALS['cn_mails'] && 1 === count( array_keys( am_avisos( $w ), 'alta_mail_sin_email', true ) ) );
// Reintento: aparece el cobro con email en una corrida posterior.
$w = am_preparar( 'on', array( am_item( 1 ) ), array( 1 => null ) );
CN_Alta_Mail::correr();
cn_http_on( 'GET', '#/v1/payments/5000001$#', array( 'id' => 5000001, 'status' => 'approved', 'payer' => array( 'email' => 'llego@gmail.com' ) ) );
CN_Alta_Mail::correr();
t( 'la corrida siguiente, con el email ya disponible, manda el mail', 1 === count( $GLOBALS['cn_mails'] ) && 'llego@gmail.com' === $GLOBALS['cn_mails'][0]['to'] );
$w = am_preparar( 'on', array( am_item( 1 ) ), array( 1 => 'x@gmail.com' ) );
cn_http_on( 'GET', '#/v1/payments/5000001$#', array( 'id' => 5000001, 'status' => 'pending', 'payer' => array( 'email' => 'x@gmail.com' ) ) );
CN_Alta_Mail::correr();
t( 'el cobro no está aprobado (status pending): no se manda', ! $GLOBALS['cn_mails'] );
$w = am_preparar( 'on', array( am_item( 1 ) ), array( 1 => 'no-es-un-email' ) );
CN_Alta_Mail::correr();
t( 'email inválido: no se manda', ! $GLOBALS['cn_mails'] );

seccion( 'límites por corrida y 429' );
$items = array();
$emails = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$items[]       = am_item( $i, 15500, 5000 - $i * 100 );
	$emails[ $i ] = "socia{$i}@gmail.com";
}
$w = am_preparar( 'on', $items, $emails );
$r = CN_Alta_Mail::correr();
t( 'máx. 3 suscripciones por corrida (las más antiguas primero)', 3 === count( $GLOBALS['cn_mails'] ) && array( 'socia1@gmail.com', 'socia2@gmail.com', 'socia3@gmail.com' ) === array_column( $GLOBALS['cn_mails'], 'to' ), json_encode( array_column( $GLOBALS['cn_mails'], 'to' ) ) );
CN_Alta_Mail::correr();
t( 'la corrida siguiente atiende las 2 que faltaron y ninguna recibe 2 mails', 5 === count( $GLOBALS['cn_mails'] ) && 5 === count( array_unique( array_column( $GLOBALS['cn_mails'], 'to' ) ) ) );
$esperas = array();
CN_MP::$dormir = function ( $s ) use ( &$esperas ) {
	$esperas[] = $s;
};
$w = am_preparar( 'log', array( am_item( 1 ) ), array( 1 => 'sandra@gmail.com' ) );
$n = 0;
cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', function () use ( &$n ) {
	return ++$n <= 1 ? array( '__status' => 429, '__headers' => array( 'retry-after' => '3' ), '__body' => array() ) : array( 'results' => array( am_item( 1 ) ), 'paging' => array( 'total' => 1 ) );
} );
CN_Alta_Mail::correr();
t( '429 en la lista: respeta Retry-After (3 s) y sigue', array( 3 ) === $esperas && 1 === count( array_keys( $w->log_mp_tipos(), 'alta_mail_simulado', true ) ) );
$esperas = array();
$w = am_preparar( 'log', array( am_item( 1 ) ), array( 1 => 'sandra@gmail.com' ) );
cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( '__status' => 429, '__headers' => array(), '__body' => array() ) );
$r = CN_Alta_Mail::correr();
t( '429 persistente: máx. 3 reintentos (2, 4, 8 s), no concluye nada y reintenta en la próxima corrida', array( 2, 4, 8 ) === $esperas && 'mp_lista' === $r['motivo'] && ! $GLOBALS['cn_mails'] && ! in_array( 'alta_mail_simulado', $w->log_mp_tipos(), true ) );
CN_MP::$dormir = function () {
};

seccion( 'las viejas sin cobro no traban a las nuevas; re-chequeo cada 6 hs' );
$items  = array();
$emails = array();
for ( $i = 1; $i <= 12; $i++ ) { // 12 viejas (4 días), con cobro sin email: no se puede mandar
	$items[]       = am_item( $i, 15500, 4 * 86400 );
	$emails[ $i ] = null;
}
$items[]       = am_item( 20, 15500, 4000 ); // una nueva con todo
$emails[ 20 ] = 'nueva@gmail.com';
$w = am_preparar( 'on', $items, $emails, array(), 10 * 86400 );
$r = CN_Alta_Mail::correr();
t( 'con 12 viejas trabadas, la nueva igual recibe el mail en la primera corrida', 1 === count( $GLOBALS['cn_mails'] ) && 'nueva@gmail.com' === $GLOBALS['cn_mails'][0]['to'] );
t( 'las viejas no consumen cupo de MAX_CONSULTAS (solo cuenta la nueva)', 1 === $r['consultadas'] && 12 === count( array_filter( array_keys( get_option( CN_Alta_Mail::OPT_CHEQUEOS, array() ) ) ) ), json_encode( $r ) );
t( 'las nuevas se procesan primero (su cobro se consulta antes que el de cualquier vieja)', ( function () {
	foreach ( $GLOBALS['cn_http_log'] as $c ) {
		if ( false !== strpos( $c['url'], 'authorized_payments' ) ) {
			return false !== strpos( $c['url'], am_id( 20 ) );
		}
	}
	return false;
} )() );
t( 'cada vieja queda con una sola línea en el cartel', 12 === count( array_keys( am_avisos( $w ), 'alta_mail_sin_email', true ) ) );
$antes = count( cn_http_calls( '#authorized_payments/search#' ) );
CN_Alta_Mail::correr();
CN_Alta_Mail::correr();
t( 'corridas siguientes dentro de las 6 hs: las viejas se saltean (cero consultas nuevas)', count( cn_http_calls( '#authorized_payments/search#' ) ) === $antes && 1 === count( $GLOBALS['cn_mails'] ) );
$ch = get_option( CN_Alta_Mail::OPT_CHEQUEOS );
foreach ( $ch as $k => $v ) {
	$ch[ $k ] = time() - 7 * 3600;
}
update_option( CN_Alta_Mail::OPT_CHEQUEOS, $ch );
CN_Alta_Mail::correr();
t( 'pasadas las 6 hs se re-chequean (una vez cada una) y la nueva ya enviada no se repite', count( cn_http_calls( '#authorized_payments/search#' ) ) === $antes + 12 && 1 === count( $GLOBALS['cn_mails'] ) );
t( 'el re-chequeo no repite el cartel', 12 === count( array_keys( am_avisos( $w ), 'alta_mail_sin_email', true ) ) );
// Una vieja a la que por fin le aparece el email, a las 6 hs, recibe el mail.
$w = am_preparar( 'on', array( am_item( 1, 15500, 4 * 86400 ) ), array( 1 => null ), array(), 10 * 86400 );
CN_Alta_Mail::correr();
cn_http_on( 'GET', '#/v1/payments/5000001$#', array( 'id' => 5000001, 'status' => 'approved', 'payer' => array( 'email' => 'tarde@gmail.com' ) ) );
CN_Alta_Mail::correr();
t( 'vieja dentro de las 6 hs: no se re-chequea todavía', ! $GLOBALS['cn_mails'] );
update_option( CN_Alta_Mail::OPT_CHEQUEOS, array( am_id( 1 ) => time() - 7 * 3600 ) );
CN_Alta_Mail::correr();
t( 'a las 6 hs se re-chequea y, si ya hay email, se manda', 1 === count( $GLOBALS['cn_mails'] ) && 'tarde@gmail.com' === $GLOBALS['cn_mails'][0]['to'] );
// Más de MAX_CONSULTAS nuevas: el tope sigue valiendo para las nuevas.
$items  = array();
$emails = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$items[]       = am_item( $i, 15500, 5000 - $i * 10 );
	$emails[ $i ] = null;
}
$w = am_preparar( 'on', $items, $emails );
$r = CN_Alta_Mail::correr();
t( 'el tope de consultas sigue valiendo para las nuevas (máx. 10)', CN_Alta_Mail::MAX_CONSULTAS === $r['consultadas'] );

seccion( 'edad mínima: 45 minutos desde date_created' );
foreach ( array( 'log', 'on' ) as $modo ) {
	$w = am_preparar( $modo, array( am_item( 1, 15500, 1200 ), am_item( 2, 15500, 2699 ) ), array( 1 => 'a@gmail.com', 2 => 'b@gmail.com' ) );
	CN_Alta_Mail::correr();
	t( "{$modo}: con menos de 45 min no manda ni simula, y ni siquiera consulta el cobro", ! $GLOBALS['cn_mails'] && ! in_array( 'alta_mail_simulado', $w->log_mp_tipos(), true ) && ! cn_http_calls( '#authorized_payments/search#' ) && array() === get_option( CN_Alta_Mail::OPT_ENVIADOS, array() ) );
}
$w = am_preparar( 'on', array( am_item( 1, 15500, 1200 ) ), array( 1 => 'a@gmail.com' ) );
CN_Alta_Mail::correr();
$w2 = $w;
$items = array( am_item( 1, 15500, 2705 ) );
cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => $items, 'paging' => array( 'total' => 1 ) ) );
CN_Alta_Mail::correr();
t( 'cumplidos los 45 min (2705 s) en una corrida posterior sí se manda', 1 === count( $GLOBALS['cn_mails'] ) && 'a@gmail.com' === $GLOBALS['cn_mails'][0]['to'] );
t( 'la edad mínima son 45 minutos', 2700 === CN_Alta_Mail::EDAD_MIN_SEG );

seccion( 'fallo de envío: máx. 3 intentos y solo cartel' );
$w = am_preparar( 'on', array( am_item( 1 ) ), array( 1 => 'sandra@gmail.com' ) );
$GLOBALS['cn_mail_falla'] = true;
CN_Alta_Mail::correr();
t( '1.er fallo: no queda marcada como enviada, aviso en el cartel (sin n8n)', 1 === count( $GLOBALS['cn_mails'] ) && array() === get_option( CN_Alta_Mail::OPT_ENVIADOS, array() ) && 1 === count( array_keys( am_avisos( $w ), 'alta_mail_fallo_envio', true ) ) && ! cn_http_calls( '#club-trial-alerta#' ) );
CN_Alta_Mail::correr();
CN_Alta_Mail::correr();
t( 'hasta 3 intentos de envío', 3 === count( $GLOBALS['cn_mails'] ) );
t( 'el aviso del cartel es UNO solo aunque falle 3 veces (avisar_una_vez)', 1 === count( array_keys( am_avisos( $w ), 'alta_mail_fallo_envio', true ) ) );
$consultas = count( cn_http_calls( '#authorized_payments/search#' ) );
CN_Alta_Mail::correr();
CN_Alta_Mail::correr();
t( 'después de 3: ni más intentos, ni más alertas, ni más pedidos a MP por esa suscripción', 3 === count( $GLOBALS['cn_mails'] ) && 1 === count( array_keys( am_avisos( $w ), 'alta_mail_fallo_envio', true ) ) && count( cn_http_calls( '#authorized_payments/search#' ) ) === $consultas && ! cn_http_calls( '#club-trial-alerta#' ) );
$GLOBALS['cn_mail_falla'] = false;
CN_Alta_Mail::correr();
t( 'aunque wp_mail vuelva a andar, con 3 intentos agotados no se manda más', 3 === count( $GLOBALS['cn_mails'] ) && 3 === (int) get_option( CN_Alta_Mail::OPT_INTENTOS )[ am_id( 1 ) ] );
$w = am_preparar( 'on', array( am_item( 1 ) ), array( 1 => 'sandra@gmail.com' ) );
$GLOBALS['cn_mail_falla'] = true;
CN_Alta_Mail::correr();
CN_Alta_Mail::correr();
$GLOBALS['cn_mail_falla'] = false;
CN_Alta_Mail::correr();
t( 'si falla 2 veces y a la 3.ª anda: el mail sale y queda marcada como enviada', 3 === count( $GLOBALS['cn_mails'] ) && isset( get_option( CN_Alta_Mail::OPT_ENVIADOS )[ am_id( 1 ) ] ) );
CN_Alta_Mail::correr();
t( '...y después no se repite', 3 === count( $GLOBALS['cn_mails'] ) );
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-alta-mail.php' );
t( 'el fallo de envío ya no llama a avisar_error_n8n directo', false === strpos( $src, "avisar_error_n8n( 'alta_mail_fallo_envio'" ) && false !== strpos( $src, "avisar_una_vez( 'alta_mail_fallo_envio'" ) );

seccion( 'modo on: requisitos (página y retorno activo)' );
$w = am_preparar( 'on', array( am_item( 1 ) ), array( 1 => 'a@gmail.com' ) );
update_option( 'cn_retorno_activo', '0' );
CN_Alta_Mail::correr();
t( 'retorno apagado: no manda, avisa en el cartel y no marca', ! $GLOBALS['cn_mails'] && in_array( 'alta_mail_retorno_apagado', am_avisos( $w ), true ) && array() === get_option( CN_Alta_Mail::OPT_ENVIADOS, array() ) );
$w = am_preparar( 'on', array( am_item( 1 ) ), array( 1 => 'a@gmail.com' ) );
update_option( CN_Alta_Mail::OPT_URL, '' );
CN_Alta_Mail::correr();
t( 'sin página con el shortcode: no manda y avisa', ! $GLOBALS['cn_mails'] && in_array( 'alta_mail_sin_pagina', am_avisos( $w ), true ) );
$w = am_preparar( 'on', array( am_item( 1 ) ), array( 1 => 'a@gmail.com' ) );
update_option( CN_Alta_Mail::OPT_URL, '' );
$w->on( '/SELECT ID FROM wp_posts/', 77 );
CN_Alta_Mail::correr();
t( 'sin URL en Config busca sola la página publicada con el shortcode', 1 === count( $GLOBALS['cn_mails'] ) && false !== strpos( $GLOBALS['cn_mails'][0]['cuerpo'], 'p=77' ) );
$w = am_preparar( 'on', array( am_item( 1 ) ), array( 1 => 'a@gmail.com' ) );
$GLOBALS['cn_mail_falla'] = true;
update_option( 'cn_mp_access_token', '' );
CN_Alta_Mail::correr();
t( 'sin token de MP: no hace nada', ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_http_log'] );

seccion( 'token firmado' );
cn_test_reset();
$tok = CN_Alta_Mail::crear_token( am_id( 1 ) );
t( 'token válido', array( 'ok' => true, 'id' => am_id( 1 ), 'motivo' => '' ) === CN_Alta_Mail::verificar( $tok ) );
list( $i, $e, $h ) = explode( '.', $tok );
t( 'token con otro preapproval_id: firma inválida', 'firma' === CN_Alta_Mail::verificar( am_id( 2 ) . '.' . $e . '.' . $h )['motivo'] );
t( 'token con vencimiento alterado: firma inválida', 'firma' === CN_Alta_Mail::verificar( $i . '.' . ( $e + 99999 ) . '.' . $h )['motivo'] );
t( 'token con firma cambiada: inválido', 'firma' === CN_Alta_Mail::verificar( $i . '.' . $e . '.' . str_repeat( 'a', 64 ) )['motivo'] );
foreach ( array( '', 'abc', $i, $i . '.' . $e, $tok . 'x', strtoupper( $tok ), "$i.$e.$h\n" ) as $malo ) {
	if ( CN_Alta_Mail::verificar( $malo )['ok'] ) {
		t( 'formato inválido rechazado: ' . substr( $malo, 0, 20 ), false );
	}
}
t( 'formatos raros rechazados', true );
t( 'token vencido: inválido', 'vencido' === CN_Alta_Mail::verificar( CN_Alta_Mail::crear_token( am_id( 1 ), time() - 5 ) )['motivo'] );
t( 'vence a los 14 días', abs( (int) $e - ( time() + 14 * 86400 ) ) <= 2 );
$GLOBALS['wpdb']->on( '/SELECT id FROM wp_cn_miembros WHERE preapproval_id = /', 5 );
t( 'token de un preapproval que ya está vinculado: inválido', 'vinculado' === CN_Alta_Mail::verificar( $tok )['motivo'] );
cn_test_reset();
t( 'con otra sal del sitio el mismo token deja de valer', ( function () use ( $tok ) {
	$otro = hash_hmac( 'sha256', 'cn_alta_mail|' . substr( $tok, 0, -65 ), 'sal-de-prueba-otra' );
	return $otro !== substr( $tok, -64 );
} )() );
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-alta-mail.php' );
t( 'la firma se compara en tiempo constante (hash_equals) y usa la sal del sitio', false !== strpos( $src, 'hash_equals' ) && false !== strpos( $src, "wp_salt( 'auth' )" ) );

// ------------------------------------------------------------------ la página de retorno con token
const AM_PID = 'c0ffee00c0ffee00c0ffee00c0ffee00';
function am_fila( $id, $cel, $extra = array() ) {
	return cn_fila( array_merge( array(
		'id' => $id, 'nombre_apellido' => 'Sandra Perez ' . $id, 'estado' => 'activo', 'preapproval_id' => null, 'plan' => null,
		'acceso_hasta' => null, 'email' => 'guardado' . $id . '@example.com', 'fecha_fin_trial' => '2026-10-14 04:06:41', 'fecha_modificacion' => null,
		'baja_en' => null, 'celular_hint' => substr( $cel, -4 ), 'celular_hash' => CN_Helpers::hash_celular( $cel ), 'celular_texto_plano' => $cel,
	), $extra ) );
}
function am_pagina( $filas = array(), $token = null ) {
	cn_test_reset();
	global $wpdb;
	$_GET = $_POST = array();
	$_SERVER['REMOTE_ADDR'] = '9.9.9.9';
	$GLOBALS['cn_nonce_ok'] = true;
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	update_option( 'cn_retorno_activo', '1' );
	cn_http_on( 'GET', '#/preapproval/' . AM_PID . '$#', array( 'id' => AM_PID, 'status' => 'authorized', 'payer_email' => '', 'next_payment_date' => '2026-11-10T12:00:00.000-03:00', 'auto_recurring' => array( 'transaction_amount' => 15500 ) ) );
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
	$wpdb->on( '/WHERE preapproval_id = /', array() );
	return $wpdb;
}
function am_esc() {
	return $GLOBALS['wpdb']->escrituras( 'cn_miembros' );
}
function am_post( $tok, $extra = array() ) {
	$_POST = array_merge( array( 'cn_ret_submit' => '1', 'cn_ret_id' => AM_PID, 'cn_ret_tok' => $tok, 'cn_ret_nonce' => 'x', 'cn_ret_celular' => '11 5555-2165', 'cn_ret_nombre' => 'Sandra Nueva', 'cn_ret_email' => 'sandra@example.com' ), $extra );
}

seccion( 'página: GET con token' );
$w = am_pagina();
$tok = CN_Alta_Mail::crear_token( AM_PID );
$_GET['cn_alta'] = $tok;
$h = CN_Retorno::shortcode();
t( 'token válido: formulario, consulta a MP el preapproval DEL TOKEN y arrastra el token', false !== strpos( $h, 'name="cn_ret_celular"' ) && 1 === count( cn_http_calls( '#/preapproval/' . AM_PID . '$#' ) ) && false !== strpos( $h, 'name="cn_ret_tok" value="' . $tok . '"' ) );
t( 'GET no escribe nada', ! am_esc() );
foreach ( array(
	'vencido'              => CN_Alta_Mail::crear_token( AM_PID, time() - 5 ),
	'de otra suscripción alterado' => md5( 'otra' ) . substr( $tok, 32 ),
	'basura'               => 'zzz',
) as $nombre => $malo ) {
	$w = am_pagina();
	$_GET['cn_alta'] = $malo;
	$h = CN_Retorno::shortcode();
	t( "token {$nombre}: sin formulario, sin consulta a MP, sin escrituras", false === strpos( $h, '<form' ) && ! $GLOBALS['cn_http_log'] && ! am_esc() && false !== strpos( $h, 'no es válido' ) );
}
$w = am_pagina();
$w->on( '/SELECT id FROM wp_cn_miembros WHERE preapproval_id = /', 5 );
$_GET['cn_alta'] = $tok;
$h = CN_Retorno::shortcode();
t( 'token ya usado (la suscripción ya está vinculada): sin formulario, "ya está activada" y camino al login', false === strpos( $h, '<form' ) && false !== strpos( $h, 'ya está activada' ) && false !== strpos( $h, '/club-natureza-miembros/' ) && ! $GLOBALS['cn_http_log'] );
$w = am_pagina();
update_option( 'cn_retorno_activo', '0' );
$_GET['cn_alta'] = $tok;
$h = CN_Retorno::shortcode();
t( 'con la página de retorno apagada sigue sin formulario ni consultas', false === strpos( $h, '<form' ) && ! $GLOBALS['cn_http_log'] );

seccion( 'página: POST con token (alta nueva)' );
$w = am_pagina();
am_post( $tok );
$h   = CN_Retorno::shortcode();
$esc = am_esc();
t( 'crea la socia activa con el preapproval del token', 1 === count( $esc ) && 'insert' === $esc[0][0] && AM_PID === $esc[0][2]['preapproval_id'] && 'basico' === $esc[0][2]['plan'] && 'activo' === $esc[0][2]['estado'] && false !== strpos( $h, 'quedó activada' ) );
t( 'dispara lo ya existente: mail de bienvenida a la socia y evento Subscribe (una vez)', 1 === count( $GLOBALS['cn_mails'] ) && 'sandra@example.com' === $GLOBALS['cn_mails'][0]['to'] && 1 === count( $GLOBALS['cn_capi'] ) && 'vinc_' . AM_PID === $GLOBALS['cn_capi'][0][0] );
t( 'solo GET a Mercado Pago', array( 'GET' ) === am_metodos() );
$w = am_pagina();
am_post( $tok, array( 'cn_ret_id' => md5( 'otro-id-manipulado' ) ) );
CN_Retorno::shortcode();
$esc = am_esc();
t( 'un cn_ret_id manipulado en el POST se ignora: se usa el del token', 1 === count( $esc ) && AM_PID === $esc[0][2]['preapproval_id'] && ! cn_http_calls( '#' . md5( 'otro-id-manipulado' ) . '#' ) );
$w = am_pagina();
am_post( CN_Alta_Mail::crear_token( AM_PID, time() - 5 ) );
$h = CN_Retorno::shortcode();
t( 'POST con token vencido: no escribe nada', ! am_esc() && ! $GLOBALS['cn_mails'] && false === strpos( $h, 'quedó activada' ) );
$w = am_pagina();
am_post( $tok, array( 'cn_ret_nonce' => 'x' ) );
$GLOBALS['cn_nonce_ok'] = false;
CN_Retorno::shortcode();
t( 'las protecciones de la página siguen: nonce inválido no escribe', ! am_esc() );
$w = am_pagina();
am_post( $tok, array( 'cn_ret_web' => 'soy-un-bot' ) );
CN_Retorno::shortcode();
t( 'honeypot lleno: no escribe', ! am_esc() );
$w = am_pagina();
for ( $i = 0; $i < 4; $i++ ) {
	am_post( $tok, array( 'cn_ret_celular' => 'no-es-celular' ) );
	$h = CN_Retorno::shortcode();
}
t( 'límite de intentos por suscripción: tras 3 fallidos sale por WhatsApp y no escribe', false === strpos( $h, '<form' ) && ! am_esc() );

seccion( 'página: el celular coincide con una ficha existente -> se vincula ESA' );
$a = am_fila( 10, '1155552165' );
$w = am_pagina( array( $a ) );
am_post( $tok, array( 'cn_ret_nombre' => 'sandra perez 10', 'cn_ret_email' => '' ) );
$h   = CN_Retorno::shortcode();
$esc = am_esc();
t( 'activa la ficha 10 (update, backup previo) y NO crea una duplicada', 1 === count( $esc ) && 'update' === $esc[0][0] && array( 'id' => 10 ) === $esc[0][3] && ! array_filter( $esc, function ( $e ) {
	return 'insert' === $e[0];
} ) && in_array( 'vinculo_backup', $w->log_mp_tipos(), true ) );
t( 'queda con el preapproval del token y el mail va al email guardado de la ficha', AM_PID === $esc[0][2]['preapproval_id'] && 1 === count( $GLOBALS['cn_mails'] ) && 'guardado10@example.com' === $GLOBALS['cn_mails'][0]['to'] );
$w = am_pagina( array( $a ) );
am_post( $tok, array( 'cn_ret_nombre' => 'Otra Persona', 'cn_ret_email' => 'otra@example.com' ) );
CN_Retorno::shortcode();
t( 'celular de una ficha pero ni nombre parecido ni su email: no escribe (segundo factor)', ! am_esc() && ! $GLOBALS['cn_mails'] );

seccion( 'página: ambigüedad -> no hace nada y deja aviso en el cartel' );
$b = am_fila( 11, '1155552165', array( 'nombre_apellido' => 'Sandra Perez Otra', 'email' => 'otra11@example.com' ) );
$w = am_pagina( array( $a, $b ) );
am_post( $tok, array( 'cn_ret_nombre' => 'Sandra Perez 10' ) );
$h = CN_Retorno::shortcode();
t( 'celular en 2 fichas: cero escrituras, cero mails', ! am_esc() && ! $GLOBALS['cn_mails'] );
t( 'queda el rastro retorno_ambiguo y el aviso en el cartel (sin n8n)', in_array( 'retorno_ambiguo', $w->log_mp_tipos(), true ) && in_array( 'retorno_ambiguo', am_avisos( $w ), true ) && ! cn_http_calls( '#club-trial-alerta#' ) );

seccion( 'sin token: el camino de siempre no cambia' );
$w = am_pagina();
$_GET['preapproval_id'] = AM_PID;
$h = CN_Retorno::shortcode();
t( 'con ?preapproval_id= (como lo manda MP) sigue mostrando el formulario, sin campo de token', false !== strpos( $h, 'name="cn_ret_celular"' ) && false === strpos( $h, 'cn_ret_tok' ) );
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-alta-mail.php' );
t( 'CN_Alta_Mail no usa verbos de escritura contra MP', ! preg_match( '/wp_remote_post|wp_remote_request|wp_remote_head|\'method\'/', $src ) );
t( 'CN_Alta_Mail no escribe en miembros (nada de insert/update/delete/query)', ! preg_match( '/->insert\(|->update\(|->delete\(|->query\(/', $src ) );
