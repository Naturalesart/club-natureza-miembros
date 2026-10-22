<?php
// Fase A, tarea 3: cero mails/webhooks de error. Los errores técnicos van a mp_log (aviso_error) y al cartel "Avisos".
// Única salida que queda: "una socia pagó y no pudo recibir acceso", con tope de 1 por hora.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
function n8n_calls() {
	return cn_http_calls( '#club-trial-alerta#' );
}
function avisos_insertados( $w ) {
	$o = array();
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && CN_Webhook::TIPO_AVISO === $l[2]['tipo'] ) {
			$o[] = $l[2];
		}
	}
	return $o;
}

seccion( 'avisar_error_n8n: ya no habla con n8n ni manda mails' );
cn_test_reset();
global $wpdb;
cn_http_on( 'POST', '#club-trial-alerta#', array() );
CN_Webhook::avisar_error_n8n( 'pago_no_encontrado', array( 'payment_id' => '123' ) );
CN_Webhook::avisar_error_n8n( 'crear_preference_trial_fallo', array( 'error' => 'x' ) );
CN_Webhook::avisar_error_n8n( 'aviso_dia5_sin_celular', array( 'miembro_id' => 4 ) );
CN_Webhook::avisar_error_n8n( 'capi_purchase_fallo', array( 'event_id' => 'e1' ) );
CN_Webhook::avisar_error_n8n( 'trial_pago_directo', array( 'miembro_id' => 4 ) );
CN_Webhook::avisar_error_n8n( 'sub_conflicto', array( 'x' => 1 ) );
t( 'errores técnicos: cero llamadas HTTP a n8n', ! $GLOBALS['cn_http_log'] );
t( 'errores técnicos: cero mails', ! $GLOBALS['cn_mails'] );
t( 'cada uno queda en mp_log como aviso_error', 6 === count( avisos_insertados( $wpdb ) ) );
CN_Webhook::sub_avisar_una_vez_por_hora( 'sub_error', 'ref1', array( 'mensaje' => 'boom' ) );
t( 'sub_avisar_una_vez_por_hora: también va solo al cartel', ! $GLOBALS['cn_http_log'] && 7 === count( avisos_insertados( $wpdb ) ) );

seccion( 'excepción: "una socia pagó y no pudo recibir acceso" sigue avisando, máx. 1 por hora' );
foreach ( CN_Webhook::MOTIVOS_PAGO_SIN_ACCESO as $motivo ) {
	cn_test_reset();
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	CN_Webhook::avisar_error_n8n( $motivo, array( 'payment_id' => '1' ) );
	CN_Webhook::avisar_error_n8n( $motivo, array( 'payment_id' => '2' ) );
	t( "{$motivo}: 1 sola llamada a n8n por hora (la 2ª queda solo en el cartel)", 1 === count( n8n_calls() ) && 2 === count( avisos_insertados( $GLOBALS['wpdb'] ) ) && ! $GLOBALS['cn_mails'] );
	delete_transient( 'cn_pago_sin_acceso_' . md5( $motivo ) ); // pasó la hora
	CN_Webhook::avisar_error_n8n( $motivo, array( 'payment_id' => '3' ) );
	t( "{$motivo}: pasada la hora vuelve a avisar", 2 === count( n8n_calls() ) );
}
t( 'son exactamente los motivos de pago sin acceso, y ningún error técnico está en la lista', array( 'excepcion_trial_alta', 'metadata_incompleta', 'preapproval_sin_match', 'sub_sin_vincular' ) === CN_Webhook::MOTIVOS_PAGO_SIN_ACCESO );

seccion( 'cartel Avisos: deduplicado, con contador, últimos 50' );
cn_test_reset();
$w = $GLOBALS['wpdb'];
$filas = array();
$mk = function ( $motivo, $detalle, $fecha ) {
	return cn_fila( array( 'payload' => json_encode( array( 'motivo' => $motivo, 'detalle' => $detalle, 'clave' => md5( $motivo . '|' . json_encode( $detalle ) ) ) ), 'fecha' => $fecha ) );
};
// Más nuevas primero (ORDER BY id DESC): 5 veces el mismo error, 1 distinto, y 60 errores distintos más viejos.
for ( $i = 0; $i < 5; $i++ ) {
	$filas[] = $mk( 'mp_api_error', array( 'ruta' => '/preapproval/{id}', 'http' => 429 ), '2026-10-13 1' . $i . ':00:00' );
}
$filas[] = $mk( 'pago_no_encontrado', array( 'payment_id' => '9' ), '2026-10-12 09:00:00' );
for ( $i = 0; $i < 60; $i++ ) {
	$filas[] = $mk( 'sub_error', array( 'n' => $i ), '2026-10-11 00:00:00' );
}
$w->on( '/SELECT payload, fecha FROM wp_cn_mp_log WHERE tipo = \'aviso_error\'/', $filas );
$a = CN_Webhook::listar_avisos( 50 );
t( 'contador: 66 registros en 62 líneas distintas', 66 === $a['total'] && 62 === $a['distintos'], json_encode( array( $a['total'], $a['distintos'] ) ) );
t( 'el mismo error repetido = 1 línea con contador 5 y la fecha de la última vez', 'mp_api_error' === $a['lineas'][0]['motivo'] && 5 === $a['lineas'][0]['veces'] && '2026-10-130:00:00' !== $a['lineas'][0]['ultima'] && '2026-10-13 10:00:00' === $a['lineas'][0]['ultima'], json_encode( $a['lineas'][0] ) );
t( 'muestra como máximo 50 líneas', 50 === count( $a['lineas'] ) );
t( 'la consulta lee solo el tipo aviso_error (y con tope de filas)', (bool) array_filter( $w->consultas, function ( $q ) {
	return false !== strpos( $q, "tipo = 'aviso_error'" ) && false !== strpos( $q, 'LIMIT 1000' );
} ) );
$html = CN_Admin::cartel_avisos();
t( 'el cartel se dibuja con tipo, fecha, veces y detalle, y escapa el HTML', false !== strpos( $html, 'mp_api_error' ) && false !== strpos( $html, '2026-10-13 10:00:00' ) && false !== strpos( $html, '<td>5</td>' ) && false !== strpos( $html, '62 distintos' ) );
cn_test_reset();
t( 'sin avisos: el cartel lo dice', false !== strpos( CN_Admin::cartel_avisos(), 'sin errores técnicos' ) );
$w = $GLOBALS['wpdb'];
$w->on( '/SELECT payload, fecha FROM wp_cn_mp_log/', array( cn_fila( array( 'payload' => json_encode( array( 'motivo' => 'x<script>', 'detalle' => '<b>hola</b>', 'clave' => 'k' ) ), 'fecha' => '2026-10-13 10:00:00' ) ) ) );
t( 'un detalle con HTML no se inyecta en el panel', false === strpos( CN_Admin::cartel_avisos(), '<script>' ) && false === strpos( CN_Admin::cartel_avisos(), '<b>hola' ) );

seccion( 'errores de la API de MP: antes mandaban n8n (401/403/429/5xx), ahora solo el cartel' );
cn_test_reset();
update_option( 'cn_mp_access_token', 'APP_USR-test' );
cn_http_on( 'GET', '#/preapproval/PRE1$#', 500 );
CN_MP::obtener_preapproval( 'PRE1' );
CN_MP::obtener_preapproval( 'PRE1' );
$w = $GLOBALS['wpdb'];
t( 'HTTP 500: no sale nada a n8n ni mail; queda mp_api_error y un aviso (1/hora por ruta+código)', ! n8n_calls() && ! $GLOBALS['cn_mails'] && 2 === count( array_keys( $w->log_mp_tipos(), 'mp_api_error', true ) ) && 1 === count( avisos_insertados( $w ) ) );
cn_http_on( 'GET', '#/preapproval/PRE2$#', 404 );
CN_MP::obtener_preapproval( 'PRE2' );
t( 'HTTP 404: solo mp_api_error, sin aviso (como antes)', 1 === count( avisos_insertados( $w ) ) );

seccion( 'los mails buenos no se tocan' );
cn_test_reset();
update_option( 'cn_admin_alerta_email', 'admin@example.com' );
update_option( 'cn_clase_especial_link', 'https://example.com/clase' );
CN_Webhook::enviar_mail_fin_trial( 'Socia', 'socia@example.com' );
t( 'mail de fin de trial: se sigue mandando a la socia', 1 === count( $GLOBALS['cn_mails'] ) && 'socia@example.com' === $GLOBALS['cn_mails'][0]['to'] );
cn_test_reset();
update_option( 'cn_admin_alerta_email', 'admin@example.com' );
cn_llamar( 'CN_Webhook', 'enviar_aviso_dia5', 'Socia Uno', '1155551234' );
t( 'aviso de día 5: sigue llegando al mail del admin con el link de WhatsApp', 1 === count( $GLOBALS['cn_mails'] ) && 'admin@example.com' === $GLOBALS['cn_mails'][0]['to'] && false !== strpos( $GLOBALS['cn_mails'][0]['cuerpo'], 'wa.me' ) );

seccion( 'el código no tiene otra salida de errores a n8n ni a mail' );
$raiz = dirname( __DIR__ ) . '/includes/';
$hallazgos = array();
foreach ( glob( $raiz . '*.php' ) as $f ) {
	$src = file_get_contents( $f );
	if ( false !== strpos( $src, 'N8N_ALERTA_URL' ) && 'class-cn-webhook.php' !== basename( $f ) ) {
		$hallazgos[] = basename( $f ) . ' usa N8N_ALERTA_URL';
	}
}
t( 'N8N_ALERTA_URL solo se usa en CN_Webhook', ! $hallazgos, implode( ', ', $hallazgos ) );
$web = file_get_contents( $raiz . 'class-cn-webhook.php' );
t( 'dentro de CN_Webhook, N8N_ALERTA_URL solo en avisar_pago_sin_acceso()', 2 === substr_count( $web, 'N8N_ALERTA_URL' ) && preg_match( '/function avisar_pago_sin_acceso.*?N8N_ALERTA_URL/s', $web ) === 1 ); // definición de la constante + un único uso
$mails = array();
foreach ( glob( $raiz . '*.php' ) as $f ) {
	if ( preg_match_all( '/wp_mail\(/', file_get_contents( $f ), $m ) ) {
		$mails[ basename( $f ) ] = count( $m[0] );
	}
}
t( 'wp_mail solo en class-cn-webhook.php (bienvenida, fin de trial, día 5 y otro de socias): 4 lugares, ninguno de errores', array( 'class-cn-webhook.php' => 4 ) === $mails, json_encode( $mails ) );
t( 'cn_admin_alerta_email se lee solo para el aviso de día 5', 1 === substr_count( $web, "get_option( 'cn_admin_alerta_email'" ) );
