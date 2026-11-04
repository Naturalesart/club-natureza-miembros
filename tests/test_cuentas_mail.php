<?php
// PASO D: mails de cuenta (CN_Mail_Cuenta) y recordatorios. Usa los ayudantes de test_cuentas.php (ct_*).
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
foreach ( array( 'wp_next_scheduled', 'wp_schedule_event' ) as $f ) {
	if ( ! function_exists( $f ) ) {
		eval( 'function wp_next_scheduled( $h ) { return $GLOBALS["cm_cron"][ $h ] ?? false; } function wp_schedule_event( $t, $r, $h ) { $GLOBALS["cm_cron"][ $h ] = $r; return true; }' );
		break;
	}
}

/** Base en memoria de CT_Db + las dos consultas que usan los recordatorios. */
class CM_Db extends CT_Db {
	public function get_results( $sql ) {
		if ( preg_match( "/^SELECT \\* FROM wp_cn_cuentas WHERE \\(pass_hash IS NULL OR pass_hash = ''\\) AND creada > '([^']*)' AND creada <= '([^']*)' ORDER BY id ASC LIMIT (\\d+)$/", $sql, $m ) ) {
			$this->consultas[] = $sql;
			$out = array();
			foreach ( $this->t['cuentas'] as $f ) {
				if ( ( null === $f['pass_hash'] || '' === $f['pass_hash'] ) && $f['creada'] > $m[1] && $f['creada'] <= $m[2] && count( $out ) < (int) $m[3] ) {
					$out[] = (object) $f;
				}
			}
			return $out;
		}
		return parent::get_results( $sql );
	}
	public function query( $sql ) {
		if ( preg_match( "/^DELETE FROM wp_cn_cuenta_tokens WHERE token_hash = '([a-f0-9]+)' AND proposito = '(\\w+)'$/", $sql, $m ) ) {
			parent::query( $sql );
			foreach ( $this->t['cuenta_tokens'] as $i => $f ) {
				if ( $f['token_hash'] === $m[1] && $f['proposito'] === $m[2] ) {
					unset( $this->t['cuenta_tokens'][ $i ] );
				}
			}
			return 1;
		}
		return parent::query( $sql );
	}
}

/** Como ct_preparar pero con CM_Db y SIN el notificador falso: acá salen los mails de verdad (el wp_mail de los tests los anota). */
function cm_preparar( $modo = 'on', $fichas = null ) {
	$viejo = ct_preparar( $modo, $fichas );
	$db    = new CM_Db();
	$db->t = $viejo->t;
	$db->ids = $viejo->ids;
	$GLOBALS['wpdb'] = $db;
	CN_Cuentas::$notificador = null;
	$GLOBALS['cn_mails'] = array();
	$GLOBALS['cm_cron']  = array();
	return $db;
}
function cm_token_de( $mail ) {
	return preg_match( '/cn_tok=([a-f0-9]{64})/', $mail['cuerpo'], $m ) ? $m[1] : '';
}
function cm_sin_wa( $texto ) {
	return ! preg_match( '/whatsapp|wa\.me|CN_WA_SOPORTE/i', (string) $texto );
}
function cm_reloj( $horas ) { // adelanta el reloj de los tests $horas horas desde la hora de ct_preparar (13/10/2026 15:00 UTC).
	$GLOBALS['cn_now'] = gmdate( 'Y-m-d H:i:s', strtotime( '2026-10-13 15:00:00 UTC' ) + (int) round( $horas * 3600 ) );
}

// ------------------------------------------------------------------ plantillas
seccion( 'PASO D: las 6 plantillas' );
$ej = CN_Mail_Cuenta::datos_ejemplo();
$asuntos = array(
	'bienvenida_trial'       => 'Bienvenida al Club Natureza 🌿 Elegí tu contraseña y empezá',
	'bienvenida_suscripcion' => '¡Ya sos socia del Club Natureza! 🌿 Elegí tu contraseña y empezá',
	'recuperar'              => 'Tu link para elegir una contraseña nueva',
	'entrar'                 => 'Tu link para entrar al Club',
	'recordatorio_24h'       => 'Tu lugar en el Club te está esperando 🌿',
	'recordatorio_72h'       => 'Tu lugar en el Club sigue guardado 🌿',
);
$botones = array(
	'bienvenida_trial' => 'Elijo mi contraseña', 'bienvenida_suscripcion' => 'Elijo mi contraseña', 'recuperar' => 'Elijo mi contraseña nueva',
	'entrar' => 'Entrar al Club', 'recordatorio_24h' => 'Elijo mi contraseña', 'recordatorio_72h' => 'Elijo mi contraseña',
);
$props = array( 'bienvenida_trial' => 'crear', 'bienvenida_suscripcion' => 'crear', 'recuperar' => 'recuperar', 'entrar' => 'entrar', 'recordatorio_24h' => 'crear', 'recordatorio_72h' => 'crear' );
foreach ( $asuntos as $p => $asunto ) {
	$m = CN_Mail_Cuenta::armar( $p, $ej );
	t( "$p: asunto exacto", $asunto === $m['asunto'] );
	t( "$p: botón con el texto y la flecha", false !== strpos( $m['html'], htmlspecialchars( $botones[ $p ], ENT_QUOTES ) . "\xC2\xA0→" ) );
	$esperada = ( 'entrar' === $props[ $p ] ? CN_Acceso_Paginas::url_acceso( array( 'cn_tok' => $ej['token'], 'cn_p' => 'entrar' ) ) : CN_Acceso_Paginas::url_crear( array( 'cn_tok' => $ej['token'], 'cn_p' => $props[ $p ] ) ) );
	t( "$p: el botón apunta a la página nueva con cn_tok y cn_p=" . $props[ $p ], false !== strpos( $m['html'], 'href="' . esc_url( $esperada ) . '"' ) && $esperada === $m['url'] );
	t( "$p: link de texto de respaldo en HTML y en texto plano", substr_count( $m['html'], $esperada ) >= 2 && false !== strpos( $m['texto'], 'copiá este link' ) && false !== strpos( $m['texto'], $esperada ) );
	t( "$p: sin WhatsApp (asunto, HTML y texto)", cm_sin_wa( $m['asunto'] ) && cm_sin_wa( $m['html'] ) && cm_sin_wa( $m['texto'] ) );
	t( "$p: no repite el link de WhatsApp de otros mails ni el celular", false === strpos( $m['html'], 'CONTRASEÑA (tu celular)' ) );
}
$trial = CN_Mail_Cuenta::armar( 'bienvenida_trial', $ej );
$sub   = CN_Mail_Cuenta::armar( 'bienvenida_suscripcion', $ej );
t( 'trial: título y párrafos pedidos', false !== strpos( $trial['html'], '¡Bienvenida, María Ejemplo! Tu semana de pintura empieza hoy' ) && false !== strpos( $trial['html'], 'siete días para pintar a tu ritmo, sin apuro y sin compromiso.' ) && false !== strpos( $trial['html'], 'Para entrar, elegí tu contraseña con el botón de abajo.' ) );
t( 'trial: caja "Tu usuario" con el email', false !== strpos( $trial['html'], 'TU USUARIO' ) && false !== strpos( $trial['html'], 'maria.ejemplo@example.com' ) );
t( 'trial: posdata con el link a [cn_acceso] y "Olvidé mi contraseña"', false !== strpos( $trial['html'], 'El botón vale 7 días.' ) && false !== strpos( $trial['html'], CN_Acceso_Paginas::url_acceso() ) && false !== strpos( $trial['html'], 'Olvidé mi contraseña' ) && false !== strpos( $trial['html'], 'respondé este mail y te ayudamos' ) );
t( 'la línea de "sin cargo automático" está SOLO en el trial', false !== strpos( $trial['html'], 'Nunca se te cobra nada de forma automática' ) );
foreach ( array_diff( array_keys( $asuntos ), array( 'bienvenida_trial' ) ) as $p ) {
	$m = CN_Mail_Cuenta::armar( $p, $ej );
	t( "$p: no tiene la línea de sin cargo automático", false === stripos( $m['html'], 'cobra nada de forma autom' ) && false === stripos( $m['texto'], 'cobra nada de forma autom' ) );
}
t( 'suscripción: título, plan Full mencionado y mismo botón', false !== strpos( $sub['html'], '¡Ya sos socia del Club Natureza!' ) && false !== strpos( $sub['html'], 'Club Natureza Full' ) && false !== strpos( $sub['html'], 'Elijo mi contraseña' ) );
$sub_club = CN_Mail_Cuenta::armar( 'bienvenida_suscripcion', array_merge( $ej, array( 'plan' => 'basico' ) ) );
t( 'suscripción del plan Club: no repite el nombre entre paréntesis', false === strpos( $sub_club['html'], '(Club Natureza)' ) );
$rec = CN_Mail_Cuenta::armar( 'recuperar', $ej );
t( 'recuperar: 60 minutos y la posdata de "si no lo pediste"', false !== strpos( $rec['html'], '60 minutos' ) && false !== strpos( $rec['html'], 'Si no lo pediste vos, ignorá este mail: tu cuenta sigue igual.' ) );
$ent = CN_Mail_Cuenta::armar( 'entrar', $ej );
t( 'entrar: 15 minutos y la misma posdata', false !== strpos( $ent['html'], '15 minutos' ) && false !== strpos( $ent['html'], 'Si no lo pediste vos, ignorá este mail: tu cuenta sigue igual.' ) );
$r24 = CN_Mail_Cuenta::armar( 'recordatorio_24h', $ej );
$r72 = CN_Mail_Cuenta::armar( 'recordatorio_72h', $ej );
t( 'recordatorios: tono suave ("tu lugar en el Club") y el 72 h avisa que es el último', false !== strpos( $r24['html'], 'tu lugar en el Club te está esperando' ) && false !== strpos( $r72['html'], 'último recordatorio' ) );
t( 'el nombre y el email se escapan (sin inyección de HTML)', false === strpos( CN_Mail_Cuenta::armar( 'bienvenida_trial', array_merge( $ej, array( 'nombre' => '<script>x</script>' ) ) )['html'], '<script>x' ) );

// ------------------------------------------------------------------ envío por modo
seccion( 'PASO D: enviar() según cn_cuentas_modo' );
$db = cm_preparar( 'off' );
$db->t['cuentas'][] = array( 'id' => 1, 'miembro_id' => 10, 'email' => 'nueva.socia@example.com', 'pass_hash' => null, 'creada' => '2026-10-13 15:00:00', 'ultimo_login' => null, 'intentos' => 0 );
$db->ids['cuentas'] = 1;
$antes = count( $db->consultas );
t( 'off: no envía y devuelve off', 'off' === CN_Mail_Cuenta::enviar( 1, 'recuperar' ) && array() === $GLOBALS['cn_mails'] );
t( 'off: ni siquiera consulta la base', count( $db->consultas ) === $antes );
update_option( 'cn_cuentas_modo', 'log' );
t( 'log: no envía y devuelve log', 'log' === CN_Mail_Cuenta::enviar( 1, 'recuperar' ) && array() === $GLOBALS['cn_mails'] );
$log = $db->mp_payloads();
t( 'log: deja en mp_log qué mandaría, con el email enmascarado', false !== strpos( $log, 'cuenta_mail_log' ) && false !== strpos( $log, CN_Cuentas::mascara( 'nueva.socia@example.com' ) ) && false === strpos( $log, 'nueva.socia@example.com' ) );
t( 'log: no emite ningún token', array() === $db->t['cuenta_tokens'] );
update_option( 'cn_cuentas_modo', 'on' );
t( 'on: manda UN mail y devuelve enviado', 'enviado' === CN_Mail_Cuenta::enviar( 1, 'recuperar' ) && 1 === count( $GLOBALS['cn_mails'] ) );
$mail = $GLOBALS['cn_mails'][0];
t( 'on: va al email de la cuenta, con Reply-To a hola@naturalesart.com y HTML', 'nueva.socia@example.com' === $mail['to'] && in_array( 'Reply-To: hola@naturalesart.com', $mail['headers'], true ) && in_array( 'Content-Type: text/html; charset=UTF-8', $mail['headers'], true ) );
$tok = cm_token_de( $mail );
t( 'on: el mail lleva un token válido de recuperar', '' !== $tok && CN_Cuentas::revisar_token( $tok, 'recuperar' )['ok'] );
$log = $db->mp_payloads();
t( 'el token NUNCA aparece en mp_log, ni el email completo', false === strpos( $log, $tok ) && false === strpos( $log, 'nueva.socia@example.com' ) && false !== strpos( $log, 'cuenta_mail_enviado' ) );
t( 'plantilla inexistente o cuenta inexistente: no envía', 'plantilla' === CN_Mail_Cuenta::enviar( 1, 'inventada' ) && 'sin_cuenta' === CN_Mail_Cuenta::enviar( 99, 'entrar' ) && 1 === count( $GLOBALS['cn_mails'] ) );
$GLOBALS['cn_mail_falla'] = true;
t( 'si wp_mail falla devuelve error y lo anota sin token', 'error' === CN_Mail_Cuenta::enviar( 1, 'entrar' ) && false !== strpos( $db->mp_payloads(), 'cuenta_mail_error' ) );
$GLOBALS['cn_mail_falla'] = false;

// ------------------------------------------------------------------ conexión con CN_Cuentas
seccion( 'PASO D: provisionar / pedir_link mandan los mails de cuenta' );
$db = cm_preparar( 'on' );
$r  = CN_Cuentas::provisionar( 10, 'trial' );
t( 'alta por trial: 1 cuenta y 1 mail de bienvenida del trial', 'creada' === $r['accion'] && 1 === count( $GLOBALS['cn_mails'] ) && 0 === strpos( $GLOBALS['cn_mails'][0]['asunto'], 'Bienvenida al Club Natureza' ) );
t( 'el mail de bienvenida lleva un token "crear" válido y Reply-To', CN_Cuentas::revisar_token( cm_token_de( $GLOBALS['cn_mails'][0] ), 'crear' )['ok'] && in_array( 'Reply-To: hola@naturalesart.com', $GLOBALS['cn_mails'][0]['headers'], true ) );
CN_Cuentas::provisionar( 10, 'trial' );
t( 'segunda llamada: sigue 1 cuenta y 1 mail', 1 === count( $db->t['cuentas'] ) && 1 === count( $GLOBALS['cn_mails'] ) );
$db = cm_preparar( 'on', array( ct_ficha( 10, array( 'plan' => 'full' ) ) ) );
CN_Cuentas::provisionar( 10, 'sub' );
t( 'alta por suscripción: mail de suscripción con el plan', 1 === count( $GLOBALS['cn_mails'] ) && 0 === strpos( $GLOBALS['cn_mails'][0]['asunto'], '¡Ya sos socia' ) && false !== strpos( $GLOBALS['cn_mails'][0]['cuerpo'], 'Club Natureza Full' ) );
CN_Cuentas::pedir_link( 'nueva.socia@example.com', 'recuperar' );
t( 'olvidé mi contraseña: sale el mail de recuperar', 2 === count( $GLOBALS['cn_mails'] ) && 'Tu link para elegir una contraseña nueva' === $GLOBALS['cn_mails'][1]['asunto'] );
CN_Cuentas::pedir_link( 'nueva.socia@example.com', 'entrar' );
t( 'link a mi mail: sale el mail de entrar', 3 === count( $GLOBALS['cn_mails'] ) && 'Tu link para entrar al Club' === $GLOBALS['cn_mails'][2]['asunto'] );
CN_Cuentas::pedir_link( 'fantasma@example.com', 'recuperar' );
t( 'email sin cuenta: no sale ningún mail', 3 === count( $GLOBALS['cn_mails'] ) );
$db = cm_preparar( 'log' );
$r  = CN_Cuentas::provisionar( 10, 'trial' );
t( 'modo log: provisionar no crea cuenta ni manda mails', 'log' === $r['accion'] && array() === $db->t['cuentas'] && array() === $GLOBALS['cn_mails'] );
$db = cm_preparar( 'off' );
CN_Cuentas::provisionar( 10, 'trial' );
t( 'modo off: nada de nada', array() === $db->t['cuentas'] && array() === $GLOBALS['cn_mails'] );

// ------------------------------------------------------------------ recordatorios
seccion( 'PASO D: recordatorios de 24 h y 72 h' );
$db = cm_preparar( 'on' );
CN_Cuentas::provisionar( 10, 'trial' );
$GLOBALS['cn_mails'] = array();
$bienvenida_tok = '';
cm_reloj( 23 );
$r = CN_Mail_Cuenta::recordatorios();
t( 'a las 23 h todavía no manda nada', 0 === $r['rec24'] && 0 === $r['rec72'] && array() === $GLOBALS['cn_mails'] );
cm_reloj( 25 );
$r = CN_Mail_Cuenta::recordatorios();
t( 'a las 25 h manda el recordatorio de 24 h (uno solo)', 1 === $r['rec24'] && 0 === $r['rec72'] && 1 === count( $GLOBALS['cn_mails'] ) && 'Tu lugar en el Club te está esperando 🌿' === $GLOBALS['cn_mails'][0]['asunto'] );
$tok24 = cm_token_de( $GLOBALS['cn_mails'][0] );
t( 'el recordatorio trae un token nuevo y válido, con Reply-To', CN_Cuentas::revisar_token( $tok24, 'crear' )['ok'] && in_array( 'Reply-To: hola@naturalesart.com', $GLOBALS['cn_mails'][0]['headers'], true ) );
$r = CN_Mail_Cuenta::recordatorios();
cm_reloj( 26 );
$r2 = CN_Mail_Cuenta::recordatorios();
t( 'la misma hora y la hora siguiente: no se repite', 0 === $r['rec24'] + $r2['rec24'] && 1 === count( $GLOBALS['cn_mails'] ) );
cm_reloj( 71 );
CN_Mail_Cuenta::recordatorios();
t( 'a las 71 h tampoco', 1 === count( $GLOBALS['cn_mails'] ) );
cm_reloj( 73 );
$r = CN_Mail_Cuenta::recordatorios();
t( 'a las 73 h manda el de 72 h (el último)', 1 === $r['rec72'] && 0 === $r['rec24'] && 2 === count( $GLOBALS['cn_mails'] ) && 'Tu lugar en el Club sigue guardado 🌿' === $GLOBALS['cn_mails'][1]['asunto'] );
t( 'el de 72 h invalida el token del de 24 h', ! CN_Cuentas::revisar_token( $tok24, 'crear' )['ok'] && CN_Cuentas::revisar_token( cm_token_de( $GLOBALS['cn_mails'][1] ), 'crear' )['ok'] );
foreach ( array( 74, 100, 200, 400 ) as $h ) {
	cm_reloj( $h );
	CN_Mail_Cuenta::recordatorios();
}
t( 'después del de 72 h no se manda más', 2 === count( $GLOBALS['cn_mails'] ) );
t( 'los recordatorios no dejan token ni email completo en mp_log', false === strpos( $db->mp_payloads(), $tok24 ) && false === strpos( $db->mp_payloads(), 'nueva.socia@example.com' ) );

// cuenta que ya eligió contraseña, ficha que no puede entrar, cuentas muy viejas, modos
list( $db ) = ct_con_password( 'pinturas-del-sur' );
$db2 = cm_preparar( 'on' );
$db2->t = $db->t;
$db2->ids = $db->ids;
$GLOBALS['wpdb'] = $db2;
CN_Cuentas::$notificador = null;
$GLOBALS['cn_mails'] = array();
cm_reloj( 30 );
CN_Mail_Cuenta::recordatorios();
cm_reloj( 80 );
CN_Mail_Cuenta::recordatorios();
t( 'cuenta con contraseña: ningún recordatorio', array() === $GLOBALS['cn_mails'] );

$db = cm_preparar( 'on' );
CN_Cuentas::provisionar( 10, 'trial' );
$GLOBALS['cn_mails'] = array();
$db->t['miembros'][0]['estado'] = 'pausado';
cm_reloj( 30 );
CN_Mail_Cuenta::recordatorios();
t( 'ficha pausada: no se le recuerda un acceso que no tiene', array() === $GLOBALS['cn_mails'] );

$db = cm_preparar( 'on' );
CN_Cuentas::provisionar( 10, 'trial' );
$GLOBALS['cn_mails'] = array();
cm_reloj( 80 );
$r = CN_Mail_Cuenta::recordatorios();
t( 'cuenta de 80 h sin recordatorio previo: solo el de 72 h, no los dos', 1 === $r['rec72'] && 0 === $r['rec24'] && 1 === count( $GLOBALS['cn_mails'] ) );

$db = cm_preparar( 'on' );
CN_Cuentas::provisionar( 10, 'trial' );
$GLOBALS['cn_mails'] = array();
cm_reloj( 24 * 20 );
CN_Mail_Cuenta::recordatorios();
t( 'cuenta de 20 días: ya no recibe recordatorios', array() === $GLOBALS['cn_mails'] );

foreach ( array( 'off', 'log' ) as $modo ) {
	$db = cm_preparar( 'on' );
	CN_Cuentas::provisionar( 10, 'trial' );
	$GLOBALS['cn_mails'] = array();
	update_option( 'cn_cuentas_modo', $modo );
	cm_reloj( 30 );
	$antes = count( $db->consultas );
	$r = CN_Mail_Cuenta::recordatorios();
	t( "modo $modo: el cron no manda nada ni toca la base", array() === $GLOBALS['cn_mails'] && 0 === $r['rec24'] && count( $db->consultas ) === $antes );
}

// si el mail falla, la marca se suelta y el ciclo siguiente lo reintenta
$db = cm_preparar( 'on' );
CN_Cuentas::provisionar( 10, 'trial' );
$GLOBALS['cn_mails'] = array();
cm_reloj( 30 );
$GLOBALS['cn_mail_falla'] = true;
$r = CN_Mail_Cuenta::recordatorios();
$GLOBALS['cn_mail_falla'] = false;
t( 'wp_mail falla: no cuenta como enviado y no queda la marca', 0 === $r['rec24'] && ! CN_Cuentas::marca_existe( 1, 'rec24' ) );
$r = CN_Mail_Cuenta::recordatorios();
t( 'el ciclo siguiente lo reintenta y sale una sola vez', 1 === $r['rec24'] && CN_Cuentas::marca_existe( 1, 'rec24' ) );

// las marcas no son tokens válidos
t( 'las marcas de recordatorio no sirven como token', ! CN_Cuentas::revisar_token( hash( 'sha256', 'marca|1|rec24' ), 'crear' )['ok'] );

// ------------------------------------------------------------------ cron
seccion( 'PASO D: el cron solo se programa con el modo en on' );
$db = cm_preparar( 'off' );
CN_Mail_Cuenta::programar_cron();
t( 'off: no programa nada', array() === $GLOBALS['cm_cron'] );
update_option( 'cn_cuentas_modo', 'log' );
CN_Mail_Cuenta::programar_cron();
t( 'log: no programa nada', array() === $GLOBALS['cm_cron'] );
update_option( 'cn_cuentas_modo', 'on' );
CN_Mail_Cuenta::programar_cron();
CN_Mail_Cuenta::programar_cron();
t( 'on: programa cn_cuentas_recordatorios cada hora, una sola vez', 'hourly' === ( $GLOBALS['cm_cron']['cn_cuentas_recordatorios'] ?? '' ) && 1 === count( $GLOBALS['cm_cron'] ) );
unset( $GLOBALS['cn_hooks']['cn_cuentas_recordatorios'] );
CN_Mail_Cuenta::registrar();
t( 'registrar() engancha el hook del cron', ! empty( $GLOBALS['cn_hooks']['cn_cuentas_recordatorios'] ) );

// ------------------------------------------------------------------ vista previa
seccion( 'PASO D: vista previa con destinatario fijo' );
$GLOBALS['cn_mails'] = array();
$db = cm_preparar( 'off' );
foreach ( array_keys( $asuntos ) as $p ) {
	t( "vista previa $p: sale a naturalesartcursos@gmail.com", true === CN_Mail_Cuenta::enviar_vista_previa( $p ) );
}
$todos = array_unique( array_map( function ( $m ) { return $m['to']; }, $GLOBALS['cn_mails'] ) );
t( 'las 6 vistas previas fueron a un único destinatario fijo', 6 === count( $GLOBALS['cn_mails'] ) && array( 'naturalesartcursos@gmail.com' ) === array_values( $todos ) );
t( 'asunto marcado [VISTA PREVIA] y con Reply-To', 0 === strpos( $GLOBALS['cn_mails'][0]['asunto'], '[VISTA PREVIA] ' ) && in_array( 'Reply-To: hola@naturalesart.com', $GLOBALS['cn_mails'][0]['headers'], true ) );
t( 'la vista previa usa datos ficticios (ningún dato de la base)', false !== strpos( $GLOBALS['cn_mails'][0]['cuerpo'], 'maria.ejemplo@example.com' ) && array() === $db->consultas );
$rm = new ReflectionMethod( 'CN_Mail_Cuenta', 'enviar_vista_previa' );
t( 'enviar_vista_previa solo recibe la plantilla: no hay parámetro de destinatario', 1 === $rm->getNumberOfParameters() );
$n = count( $GLOBALS['cn_mails'] );
t( 'plantilla inventada: no manda nada', false === CN_Mail_Cuenta::enviar_vista_previa( 'x@y.com' ) && $n === count( $GLOBALS['cn_mails'] ) );
t( 'la constante del destinatario es la de siempre', 'naturalesartcursos@gmail.com' === CN_Mail_Cuenta::PREVIEW_DESTINO );

// ------------------------------------------------------------------ fuente
seccion( 'PASO D: el código no usa WhatsApp ni toca fichas' );
$fuente = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-mail-cuenta.php' );
$codigo = preg_replace( '#/\*.*?\*/#s', '', $fuente );
$codigo = preg_replace( '#^\s*//.*$#m', '', $codigo );
t( 'sin whatsapp / wa.me / CN_WA_SOPORTE', cm_sin_wa( $codigo ) );
t( 'no escribe ni lee wp_cn_miembros (solo pide la ficha a CN_Cuentas)', ! preg_match( '/\$wpdb|UPDATE\s|INSERT\s|DELETE\s/', $codigo ) );
t( 'en la vista previa el destinatario no sale de $_POST ni $_GET', 1 === preg_match( '/function enviar_vista_previa\(.*?\n\t\}/s', $codigo, $mm ) && ! preg_match( '/\$_(POST|GET|REQUEST)/', $mm[0] ) );
t( 'la clase Carta de siempre sigue sin enviar nada', ! preg_match( '/wp_mail/', file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-mail-carta.php' ) ) );
$cm_pre = glob( dirname( __DIR__ ) . '/tests/previews/*.html' );
t( 'las 6 vistas previas HTML están en tests/previews (no se despliegan)', 6 === count( $cm_pre ) );
