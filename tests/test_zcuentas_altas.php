<?php
// PASO E: enganches en las altas (trial, suscripción automática, vinculación admin, vinculación manual/retorno/lote).
// Corre al final (nombre test_z*) porque reusa ayudantes de test_cuentas.php (ct_*), test_cuentas_mail.php (cm_*) y test_punto4 (vm_*).
if ( PHP_SAPI !== 'cli' ) {
	exit;
}

class CE_Db extends CM_Db {
	public function get_var( $sql ) {
		if ( preg_match( "/^SELECT id FROM wp_cn_miembros WHERE (trial_payment_id|preapproval_id|celular_hash) = '(.*)' LIMIT 1$/", $sql, $m ) ) {
			$this->consultas[] = $sql;
			foreach ( $this->t['miembros'] as $f ) {
				if ( isset( $f[ $m[1] ] ) && (string) $f[ $m[1] ] === stripslashes( $m[2] ) ) {
					return $f['id'];
				}
			}
			return null;
		}
		return parent::get_var( $sql );
	}
	public function get_results( $sql ) {
		if ( preg_match( "/^SELECT id, celular_hash FROM wp_cn_miembros WHERE LOWER\\(TRIM\\(nombre_apellido\\)\\) = LOWER\\(TRIM\\('(.*)'\\)\\)$/", $sql, $m ) ) {
			$this->consultas[] = $sql;
			$out = array();
			foreach ( $this->t['miembros'] as $f ) {
				if ( strtolower( trim( (string) $f['nombre_apellido'] ) ) === strtolower( trim( stripslashes( $m[1] ) ) ) ) {
					$out[] = (object) array( 'id' => $f['id'], 'celular_hash' => $f['celular_hash'] );
				}
			}
			return $out;
		}
		return parent::get_results( $sql );
	}
	public function tomar_handlers( $otro ) {
		$this->handlers = ( function () {
			return $this->handlers;
		} )->call( $otro );
	}
}
/** Base cuyo INSERT de cuentas explota: simula un error dentro de provisionar(). */
class CE_Db_Falla extends CE_Db {
	public function insert( $tabla, $data, $f = null ) {
		if ( false !== strpos( $tabla, 'cn_cuentas' ) ) {
			throw new RuntimeException( 'falla simulada del insert' );
		}
		return parent::insert( $tabla, $data, $f );
	}
}

function ce_preparar( $modo = 'on', $fichas = null, $clase = 'CE_Db' ) {
	$viejo = ct_preparar( $modo, $fichas );
	$db    = new $clase();
	$db->t = $viejo->t;
	$db->ids = $viejo->ids;
	$GLOBALS['wpdb'] = $db;
	CN_Cuentas::$notificador = null;
	$GLOBALS['cn_mails'] = array();
	$GLOBALS['cm_cron']  = array();
	return $db;
}
function ce_cuentas( $db ) {
	return count( $db->t['cuentas'] );
}
function ce_asuntos() {
	return array_map( function ( $m ) {
		return $m['asunto'];
	}, $GLOBALS['cn_mails'] );
}
const CE_VIEJO_TRIAL = 'Ya sos parte del Club Natureza 🎉 — tus datos de acceso';
const CE_VIEJO_SUB   = '¡Ya sos socia del Club Natureza! 🌿 — tus datos de acceso';
const CE_EMAIL       = 'nueva.socia@example.com';

// ------------------------------------------------------------------ flag apagado
seccion( 'PASO E: flag off = idéntico a hoy (las bienvenidas de siempre, sin consultas ni cuentas)' );
$db = ce_preparar( 'off', array( ct_ficha( 10, array( 'trial_payment_id' => 'pay-1', 'preapproval_id' => 'pre-1' ) ) ) );
$antes = count( $db->consultas );
t( 'off: los enganches no hacen nada ni consultan', null === CN_Cuentas::enganche_trial( 'pay-1' ) && null === CN_Cuentas::enganche_preapproval( 'pre-1' ) && null === CN_Cuentas::enganche_ficha( 10, 'vinculo_admin' ) );
t( 'off: la guarda de bienvenida devuelve false sin consultar', false === CN_Cuentas::reemplaza_bienvenida_datos( 'Socia Prueba 10', CT_CEL ) && false === CN_Cuentas::reemplaza_bienvenida( 10 ) && count( $db->consultas ) === $antes );
cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida', 'Socia Prueba 10', CT_CEL, CE_EMAIL );
cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida_suscripcion', 'Socia Prueba 10', CT_CEL, CE_EMAIL, 'Club Natureza' );
t( 'off: salen las dos bienvenidas de siempre, con su asunto de siempre', array( CE_VIEJO_TRIAL, CE_VIEJO_SUB ) === ce_asuntos() );
t( 'off: la bienvenida del trial sigue mostrando "CONTRASEÑA (tu celular)" como hoy', false !== strpos( $GLOBALS['cn_mails'][0]['cuerpo'], 'CONTRASEÑA (tu celular)' ) );
t( 'off: cero cuentas y cero consultas a las tablas nuevas', 0 === ce_cuentas( $db ) && array() === $db->t['cuenta_tokens'] && count( $db->consultas ) === $antes );

seccion( 'PASO E: modo log = registra qué haría, sin cuentas ni mails de cuenta' );
$db = ce_preparar( 'log', array( ct_ficha( 10, array( 'trial_payment_id' => 'pay-1', 'preapproval_id' => 'pre-1' ) ) ) );
cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida', 'Socia Prueba 10', CT_CEL, CE_EMAIL );
cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida_suscripcion', 'Socia Prueba 10', CT_CEL, CE_EMAIL, 'Club Natureza' );
t( 'log: salen las bienvenidas de siempre', array( CE_VIEJO_TRIAL, CE_VIEJO_SUB ) === ce_asuntos() );
CN_Cuentas::enganche_trial( 'pay-1' );
CN_Cuentas::enganche_preapproval( 'pre-1', 'sub' );
$log = $db->mp_payloads();
t( 'log: el enganche anota qué haría, con email enmascarado', substr_count( $log, 'cuenta_provisionar_log' ) >= 2 && false !== strpos( $log, CN_Cuentas::mascara( CE_EMAIL ) ) && false === strpos( $log, CE_EMAIL ) );
t( 'log: no hay cuentas, ni tokens, ni mails de cuenta', 0 === ce_cuentas( $db ) && array() === $db->t['cuenta_tokens'] && 2 === count( $GLOBALS['cn_mails'] ) );

// ------------------------------------------------------------------ on: el mail de cuenta reemplaza al de siempre
seccion( 'PASO E: modo on = UN mail (el de cuenta) y UNA cuenta' );
$db = ce_preparar( 'on', array( ct_ficha( 10, array( 'trial_payment_id' => 'pay-1', 'preapproval_id' => 'pre-1', 'plan' => 'full' ) ) ) );
cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida', 'Socia Prueba 10', CT_CEL, CE_EMAIL );
t( 'trial: 1 cuenta y 1 solo mail, el de cuenta (no el viejo)', 1 === ce_cuentas( $db ) && 1 === count( $GLOBALS['cn_mails'] ) && 0 === strpos( $GLOBALS['cn_mails'][0]['asunto'], 'Bienvenida al Club Natureza' ) );
t( 'trial: el mail nuevo no muestra el celular como contraseña', false === strpos( $GLOBALS['cn_mails'][0]['cuerpo'], 'CONTRASEÑA (tu celular)' ) && false === strpos( $GLOBALS['cn_mails'][0]['cuerpo'], CT_CEL ) );
CN_Cuentas::enganche_trial( 'pay-1' );
cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida', 'Socia Prueba 10', CT_CEL, CE_EMAIL );
CN_Cuentas::enganche_trial( 'pay-1' );
t( 'trial: repetir el aviso o el enganche (2 veces o más) = 1 cuenta y 1 mail', 1 === ce_cuentas( $db ) && 1 === count( $GLOBALS['cn_mails'] ) );
t( 'trial: la cuenta quedó sin contraseña y con un token "crear" válido', null === $db->t['cuentas'][0]['pass_hash'] && CN_Cuentas::revisar_token( cm_token_de( $GLOBALS['cn_mails'][0] ), 'crear' )['ok'] );

$db = ce_preparar( 'on', array( ct_ficha( 10, array( 'preapproval_id' => 'pre-1', 'plan' => 'full' ) ) ) );
cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida_suscripcion', 'Socia Prueba 10', CT_CEL, CE_EMAIL, 'Club Natureza Full' );
t( 'suscripción: 1 cuenta y 1 solo mail, el de cuenta con el plan', 1 === ce_cuentas( $db ) && 1 === count( $GLOBALS['cn_mails'] ) && 0 === strpos( $GLOBALS['cn_mails'][0]['asunto'], '¡Ya sos socia del Club Natureza! 🌿 Elegí' ) && false !== strpos( $GLOBALS['cn_mails'][0]['cuerpo'], 'Club Natureza Full' ) );
t( 'suscripción: no lleva la línea de sin cargo automático', false === stripos( $GLOBALS['cn_mails'][0]['cuerpo'], 'cobra nada de forma autom' ) );
CN_Cuentas::enganche_preapproval( 'pre-1', 'sub' );
CN_Cuentas::enganche_preapproval( 'pre-1', 'sub' );
t( 'suscripción: el enganche repetido deja 1 cuenta y 1 mail', 1 === ce_cuentas( $db ) && 1 === count( $GLOBALS['cn_mails'] ) );

// por el camino completo de sub_post_alta (mail + CAPI), como en la suscripción automática
$db = ce_preparar( 'on', array( ct_ficha( 10, array( 'preapproval_id' => 'pre-1', 'plan' => 'full' ) ) ) );
$fila = (object) array( 'nombre_apellido' => 'Socia Prueba 10', 'celular' => CT_CEL, 'email' => CE_EMAIL, 'external_reference' => 'sub_x', 'fbp' => '', 'fbc' => '', 'ip' => '', 'user_agent' => '' );
cn_llamar( 'CN_Webhook', 'sub_post_alta', $fila, 'full', CN_MP::get_planes() );
CN_Cuentas::enganche_preapproval( 'pre-1', 'sub' );
t( 'sub_post_alta + enganche: 1 cuenta y 1 mail (el de cuenta)', 1 === ce_cuentas( $db ) && 1 === count( $GLOBALS['cn_mails'] ) && 0 === strpos( $GLOBALS['cn_mails'][0]['asunto'], '¡Ya sos socia' ) );

// ------------------------------------------------------------------ casos que NO provisionan
seccion( 'PASO E: casos que caen a la bienvenida de siempre' );
// email ya usado por OTRA ficha
$db = ce_preparar( 'on', array( ct_ficha( 10 ), ct_ficha( 11, array( 'celular_hash' => CN_Helpers::hash_celular( '1155550099' ), 'celular_texto_plano' => '1155550099' ) ) ) );
cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida', 'Socia Prueba 10', CT_CEL, CE_EMAIL );
t( 'email en otra ficha: no se crea cuenta y sale la bienvenida de siempre', 0 === ce_cuentas( $db ) && array( CE_VIEJO_TRIAL ) === ce_asuntos() );
t( 'email en otra ficha: queda cuenta_email_en_uso enmascarado', false !== strpos( $db->mp_payloads(), 'cuenta_email_en_uso' ) && false === strpos( $db->mp_payloads(), CE_EMAIL ) );
// email ya usado por otra CUENTA
$db = ce_preparar( 'on', array( ct_ficha( 10 ), ct_ficha( 11, array( 'email' => 'otra@example.com', 'celular_hash' => CN_Helpers::hash_celular( '1155550099' ), 'celular_texto_plano' => '1155550099' ) ) ) );
$db->t['cuentas'][] = array( 'id' => 1, 'miembro_id' => 11, 'email' => CE_EMAIL, 'pass_hash' => null, 'creada' => '2026-10-13 10:00:00', 'ultimo_login' => null, 'intentos' => 0 );
$db->ids['cuentas'] = 1;
cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida_suscripcion', 'Socia Prueba 10', CT_CEL, CE_EMAIL, 'Club Natureza' );
t( 'email de otra cuenta: no se pisa, sale la bienvenida de siempre', 1 === ce_cuentas( $db ) && 11 === (int) $db->t['cuentas'][0]['miembro_id'] && array( CE_VIEJO_SUB ) === ce_asuntos() );
// email inválido y sin email
$db = ce_preparar( 'on', array( ct_ficha( 10, array( 'email' => 'esto-no-es-un-mail' ) ) ) );
cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida', 'Socia Prueba 10', CT_CEL, 'esto-no-es-un-mail' );
t( 'email inválido: no se crea cuenta y sale la bienvenida de siempre', 0 === ce_cuentas( $db ) && array( CE_VIEJO_TRIAL ) === ce_asuntos() );
$db = ce_preparar( 'on', array( ct_ficha( 10, array( 'email' => '' ) ) ) );
cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida', 'Socia Prueba 10', CT_CEL, '' );
t( 'sin email: no se crea cuenta y todo sigue como hoy (no hay a quién mandar)', 0 === ce_cuentas( $db ) && array() === $GLOBALS['cn_mails'] );
// re-trial: ficha anterior a cn_cuentas_desde
$db = ce_preparar( 'on', array( ct_ficha( 10, array( 'trial_payment_id' => 'pay-1', 'fecha_alta' => '2026-09-01 10:00:00' ) ) ) );
cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida', 'Socia Prueba 10', CT_CEL, CE_EMAIL );
$r = CN_Cuentas::enganche_trial( 'pay-1' );
t( 're-trial (ficha anterior a cn_cuentas_desde): no provisiona y sale la bienvenida de siempre', 0 === ce_cuentas( $db ) && array( CE_VIEJO_TRIAL ) === ce_asuntos() && 're_trial' === $r['accion'] );
// lote
$db = ce_preparar( 'on', array( ct_ficha( 10 ) ) );
t( 'lote: origen_de_log_tipo no mapea vinculo_lote y el enganche no hace nada', '' === CN_Cuentas::origen_de_log_tipo( 'vinculo_lote' ) && null === CN_Cuentas::enganche_ficha( 10, CN_Cuentas::origen_de_log_tipo( 'vinculo_lote' ) ) && 0 === ce_cuentas( $db ) );
t( 'manual y retorno sí mapean a sus orígenes', 'vinculo_manual' === CN_Cuentas::origen_de_log_tipo( 'vinculo_manual' ) && 'retorno' === CN_Cuentas::origen_de_log_tipo( 'retorno_vinculo' ) );
// sin cn_cuentas_desde
$db = ce_preparar( 'on', array( ct_ficha( 10, array( 'preapproval_id' => 'pre-1' ) ) ), 'CE_Db' );
unset( $GLOBALS['cn_opts']['cn_cuentas_desde'] );
cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida_suscripcion', 'Socia Prueba 10', CT_CEL, CE_EMAIL, 'Club Natureza' );
t( 'sin cn_cuentas_desde: nada se crea y sale la bienvenida de siempre', 0 === ce_cuentas( $db ) && array( CE_VIEJO_SUB ) === ce_asuntos() );

// ------------------------------------------------------------------ errores: nunca rompen el alta ni dejan sin mail
seccion( 'PASO E: un error dentro de provisionar no rompe el alta ni deja sin mail' );
$db = ce_preparar( 'on', array( ct_ficha( 10, array( 'trial_payment_id' => 'pay-1', 'preapproval_id' => 'pre-1' ) ) ), 'CE_Db_Falla' );
$ok = true;
try {
	cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida', 'Socia Prueba 10', CT_CEL, CE_EMAIL );
	$r1 = CN_Cuentas::enganche_trial( 'pay-1' );
	$r2 = CN_Cuentas::enganche_preapproval( 'pre-1' );
	$r3 = CN_Cuentas::enganche_ficha( 10, 'vinculo_admin' );
} catch ( Throwable $e ) {
	$ok = false;
}
t( 'la excepción no sale de los enganches', $ok && 'error' === $r1['accion'] && 'error' === $r2['accion'] && 'error' === $r3['accion'] );
t( 'y sale la bienvenida de siempre (nunca queda sin mail)', array( CE_VIEJO_TRIAL ) === ce_asuntos() && 0 === ce_cuentas( $db ) );
t( 'el error queda anotado sin email ni token', false !== strpos( $db->mp_payloads(), 'cuenta_enganche_error' ) && false === strpos( $db->mp_payloads(), CE_EMAIL ) );
// el mail de cuenta falla: la cuenta queda, pero sale el de siempre
$db = ce_preparar( 'on', array( ct_ficha( 10 ) ) );
$GLOBALS['cn_mail_falla'] = true;
$r = CN_Cuentas::reemplaza_bienvenida( 10, 'trial' );
$GLOBALS['cn_mail_falla'] = false;
t( 'si el mail de cuenta no sale, la guarda no reemplaza (sale el de siempre)', false === $r && 1 === ce_cuentas( $db ) );

// ------------------------------------------------------------------ vinculación manual (camino completo de confirmar)
seccion( 'PASO E: vinculación manual, retorno y lote por CN_Vinculo_Manual::confirmar' );
function ce_confirmar( $modo, $notificar, $log_tipo = 'vinculo_manual', $clase = 'CE_Db' ) {
	$a   = vm_fila( 10, '1155552165', array( 'email' => 'nueva.socia@example.com' ) );
	$w0  = vm_preparar( array( $a ) );
	$db  = new $clase();
	$db->tomar_handlers( $w0 );
	$db->t['miembros'][] = ct_ficha( 10, array( 'nombre_apellido' => 'Griselda Grilli', 'celular_hash' => CN_Helpers::hash_celular( '1155552165' ), 'celular_texto_plano' => '1155552165' ) );
	$GLOBALS['wpdb'] = $db;
	if ( null !== $modo ) {
		update_option( 'cn_cuentas_modo', $modo );
		update_option( 'cn_cuentas_desde', strtotime( CT_DESDE_TXT . ' UTC' ) );
	}
	$GLOBALS['cn_now']       = '2026-10-13 15:00:00';
	$_SERVER['REMOTE_ADDR']  = '9.9.9.9';
	CN_Cuentas::$notificador = null;
	$GLOBALS['cn_mails']     = array();
	$r = CN_Vinculo_Manual::confirmar( vm_datos( array( 'socia' => '10', 'notificar' => $notificar ) ), '1155552165', $log_tipo );
	return array( $db, $r );
}
list( $db, $r ) = ce_confirmar( 'off', true );
t( 'confirmar con flag off: alta y bienvenida de siempre, sin cuentas', $r['ok'] && 'activada' === $r['accion'] && 0 === ce_cuentas( $db ) && in_array( CE_VIEJO_SUB, ce_asuntos(), true ) );
list( $db, $r ) = ce_confirmar( 'log', true );
t( 'confirmar con log: bienvenida de siempre y solo un rastro', $r['ok'] && 0 === ce_cuentas( $db ) && in_array( CE_VIEJO_SUB, ce_asuntos(), true ) && false !== strpos( $db->mp_payloads(), 'cuenta_provisionar_log' ) );
list( $db, $r ) = ce_confirmar( 'on', true );
t( 'confirmar con on + notificar: 1 cuenta y el mail de cuenta (no el viejo)', $r['ok'] && 1 === ce_cuentas( $db ) && 10 === (int) $db->t['cuentas'][0]['miembro_id'] && ! in_array( CE_VIEJO_SUB, ce_asuntos(), true ) && 1 === count( array_filter( ce_asuntos(), function ( $a ) { return 0 === strpos( $a, '¡Ya sos socia' ); } ) ) );
list( $db, $r ) = ce_confirmar( 'on', false );
t( 'confirmar con on pero notificar=false: NO crea cuenta y no manda nada', $r['ok'] && 0 === ce_cuentas( $db ) && array() === $GLOBALS['cn_mails'] );
list( $db, $r ) = ce_confirmar( 'on', true, 'retorno_vinculo' );
t( 'retorno: crea la cuenta con origen retorno', $r['ok'] && 1 === ce_cuentas( $db ) );
list( $db, $r ) = ce_confirmar( 'on', false, 'vinculo_lote' );
t( 'lote: no crea cuenta (el lote nunca notifica)', $r['ok'] && 0 === ce_cuentas( $db ) && array() === $GLOBALS['cn_mails'] );
list( $db, $r ) = ce_confirmar( 'on', true, 'vinculo_manual', 'CE_Db_Falla' );
t( 'confirmar con una falla dentro de provisionar: el alta sigue ok y sale la bienvenida de siempre', $r['ok'] && 'activada' === $r['accion'] && 0 === ce_cuentas( $db ) && in_array( CE_VIEJO_SUB, ce_asuntos(), true ) );

// ------------------------------------------------------------------ cn_cuentas_desde al salir de off
seccion( 'PASO E: cn_cuentas_desde se guarda al salir de off' );
cn_test_reset();
unset( $GLOBALS['cn_hooks'] );
CN_Cuentas::registrar();
t( 'registrar() engancha update_option_ y add_option_ de cn_cuentas_modo', ! empty( $GLOBALS['cn_hooks']['update_option_cn_cuentas_modo'] ) && ! empty( $GLOBALS['cn_hooks']['add_option_cn_cuentas_modo'] ) );
t( 'sin cambios no hay cn_cuentas_desde', ! get_option( 'cn_cuentas_desde' ) );
CN_Cuentas::al_crear_modo( 'cn_cuentas_modo', 'log' );
$d1 = (int) get_option( 'cn_cuentas_desde' );
t( 'la primera vez (add_option) off→log guarda cn_cuentas_desde', $d1 > 0 );
$GLOBALS['cn_now'] = '2027-01-01 00:00:00';
CN_Cuentas::al_cambiar_modo( 'log', 'on' );
CN_Cuentas::al_cambiar_modo( 'off', 'on' );
t( 'después no se pisa', $d1 === (int) get_option( 'cn_cuentas_desde' ) );
cn_test_reset();
CN_Cuentas::al_cambiar_modo( '', 'on' );
t( 'un valor anterior vacío cuenta como off', (int) get_option( 'cn_cuentas_desde' ) > 0 );
cn_test_reset();
CN_Cuentas::al_cambiar_modo( 'on', 'off' );
t( 'pasar a off no guarda nada', ! get_option( 'cn_cuentas_desde' ) );

// ------------------------------------------------------------------ regresión: el login de siempre
seccion( 'PASO E: regresión de acceso (login clásico y la ficha decide)' );
list( $db ) = ct_con_password( 'pinturas-del-sur' );
$db->on( '/FROM wp_cn_miembros WHERE LOWER/', array( (object) $db->t['miembros'][0] ) );
$r = ct_q( function () {
	return CN_Auth::login( 'Socia Prueba 10', CT_CEL );
} );
t( 'ficha clásica: sigue entrando con nombre + celular con cuentas prendidas', $r['ok'] );
foreach ( array( 'pausado', 'cancelada' ) as $estado ) {
	list( $db ) = ct_con_password( 'pinturas-del-sur' );
	$db->t['miembros'][0]['estado'] = $estado;
	$r = ct_q( function () {
		return CN_Cuentas::login( CE_EMAIL, 'pinturas-del-sur' );
	} );
	t( "ficha $estado: no entra aunque la contraseña sea correcta y el texto no usa WhatsApp", ! $r['ok'] && false === stripos( $r['mensaje'], 'whatsapp' ) && empty( ct_sesiones_de( $db, 10 ) ) );
}
list( $db ) = ct_con_password( 'pinturas-del-sur' );
$db->t['miembros'][0]['fecha_fin_trial'] = CT_PASADO;
$r = ct_q( function () {
	return CN_Cuentas::login( CE_EMAIL, 'pinturas-del-sur' );
} );
t( 'trial vencido: no entra aunque tenga contraseña', ! $r['ok'] && empty( ct_sesiones_de( $db, 10 ) ) );

// ------------------------------------------------------------------ fuente: dónde y cómo están los enganches
seccion( 'PASO E: los enganches están donde deben, envueltos y detrás de class_exists' );
$raiz = dirname( __DIR__ ) . '/includes/';
$web  = file_get_contents( $raiz . 'class-cn-webhook.php' );
$vm   = file_get_contents( $raiz . 'class-cn-vinculo-manual.php' );
t( 'webhook: 2 guardas de bienvenida, como primera línea de cada función', 2 === preg_match_all( '/function enviar_mail_bienvenida(?:_suscripcion)?\([^)]*\) \{\R\t\tif \( class_exists\( \'CN_Cuentas\' \) && method_exists\( \'CN_Cuentas\', \'reemplaza_bienvenida_datos\' \) && CN_Cuentas::reemplaza_bienvenida_datos\(/', $web ) );
t( 'webhook: los 3 enganches (trial, sub, admin) están dentro de try/catch Throwable', 3 === preg_match_all( '/try \{ \/\/ PASO E:/', $web ) && 3 === preg_match_all( "/'cuenta_enganche_error'/", $web ) );
t( 'webhook: cada llamada va con class_exists( \'CN_Cuentas\' )', 5 === substr_count( $web, "class_exists( 'CN_Cuentas' )" ) );
t( 'webhook: el enganche del trial viene DESPUÉS del mail de bienvenida', strpos( $web, 'CN_Cuentas::enganche_trial' ) > strpos( $web, 'self::enviar_mail_bienvenida( $nombre, $celular, $email );' ) );
t( 'webhook: el enganche de la sub automática está dentro de if ( $es_alta ), después de sub_post_alta', 1 === preg_match( '/if \( \$es_alta \) \{\R\t+self::sub_post_alta\( \$fila, \$plan, \$planes \);\R\t+try \{ \/\/ PASO E:.*?enganche_preapproval/s', $web ) );
t( 'webhook: vincular_suscripcion solo con $es_alta && $notificar y antes del return $res', 1 === preg_match( '/\$es_alta && \$notificar && ! empty\( \$res\[\'miembro_id\'\] \).*?enganche_ficha\( \(int\) \$res\[\'miembro_id\'\], \'vinculo_admin\' \).*?\R\t\treturn \$res;/s', $web ) );
t( 'vinculo manual: un solo enganche, con $es_alta && notificar, try/catch y class_exists', 1 === substr_count( $vm, 'CN_Cuentas::enganche_ficha' ) && 1 === preg_match( '/try \{ \/\/ PASO E:.*?\$es_alta && \$d\[\'notificar\'\].*?class_exists\( \'CN_Cuentas\' \).*?catch \( Throwable/s', $vm ) );
$otros = '';
foreach ( glob( $raiz . '*.php' ) as $f ) {
	$b = basename( $f );
	if ( ! in_array( $b, array( 'class-cn-webhook.php', 'class-cn-vinculo-manual.php', 'class-cn-cuentas.php', 'class-cn-mail-cuenta.php', 'class-cn-acceso.php', 'class-cn-shortcodes.php' ), true ) && preg_match( '/CN_Cuentas|CN_Mail_Cuenta|CN_Acceso_Paginas/', file_get_contents( $f ) ) ) {
		$otros .= $b . ' ';
	}
}
t( 'ninguna otra clase (lote, retorno, sync, espejo, conciliación, auth…) menciona las cuentas', '' === $otros, $otros );
$nuevo = '';
foreach ( array( 'class-cn-webhook.php' => $web, 'class-cn-vinculo-manual.php' => $vm ) as $b => $c ) {
	if ( preg_match_all( '/^.*PASO E.*$/m', $c, $mm ) ) {
		$nuevo .= implode( ' ', $mm[0] );
	}
}
t( 'las líneas nuevas de las altas no mencionan WhatsApp', false === stripos( $nuevo, 'whatsapp' ) && false === stripos( $nuevo, 'wa.me' ) && false === strpos( $nuevo, 'CN_WA_SOPORTE' ) );

// ------------------------------------------------------------------ deploy por SFTP a medias
seccion( 'PASO E: clase vieja sin los métodos nuevos (deploy archivo por archivo)' );
$v = cm_proceso_viejo( 'altas' );
t( 'con una CN_Cuentas vieja las altas no dan fatal', isset( $v['escenario'] ), isset( $v['error'] ) ? $v['error'] : '' );
t( 'la clase simulada de verdad no tiene los métodos nuevos', false === ( $v['tiene_metodos_nuevos'] ?? null ) );
t( 'salen las dos bienvenidas de siempre (trial y suscripción)', array( CE_VIEJO_TRIAL, CE_VIEJO_SUB ) === ( $v['asuntos'] ?? null ) );
t( 'sub_post_alta sigue mandando su bienvenida de siempre (más el evento Meta sin cambios)', 3 === ( $v['mails_tras_sub_post_alta'] ?? null ) );
// cada llamada a un método NUEVO de CN_Cuentas lleva su method_exists en el mismo archivo
$falta = array();
foreach ( array( 'club-natureza-miembros.php' => dirname( __DIR__ ) . '/club-natureza-miembros.php', 'class-cn-webhook.php' => $raiz . 'class-cn-webhook.php', 'class-cn-vinculo-manual.php' => $raiz . 'class-cn-vinculo-manual.php' ) as $b => $ruta ) {
	$c = file_get_contents( $ruta );
	if ( preg_match_all( '/(?<![\w:])CN_Cuentas::(\w+)\(/', $c, $mm ) ) {
		foreach ( array_unique( $mm[1] ) as $metodo ) {
			if ( false === strpos( $c, "method_exists( 'CN_Cuentas', '$metodo' )" ) ) {
				$falta[] = "$b::$metodo";
			}
		}
	}
}
t( 'toda llamada a CN_Cuentas::método en el plugin y las altas tiene method_exists del mismo método', array() === $falta, implode( ', ', $falta ) );
