<?php
// Punto 10: mail de bienvenida de suscripción (CN_Webhook::enviar_mail_bienvenida_suscripcion) y que el del trial no cambió.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
function mb_enviar( $plan_nombre ) {
	cn_test_reset();
	cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida_suscripcion', 'Leandro Lencina', '1155551234', 'socia@example.com', $plan_nombre );
	return $GLOBALS['cn_mails'];
}

seccion( 'mail de bienvenida: plan básico' );
$m = mb_enviar( CN_MP::get_planes()['basico']['nombre'] );
$c = $m[0]['cuerpo'];
t( 'se manda un solo mail', 1 === count( $m ) && 'socia@example.com' === $m[0]['to'] );
t( 'básico: "Tu suscripción al Club Natureza está activa." sin paréntesis', false !== strpos( $c, 'Tu suscripción al Club Natureza está activa.' ) && false === strpos( $c, 'Club Natureza (' ) );
t( 'etiqueta "CELULAR (el que registraste)" y ya no "CONTRASEÑA"', false !== strpos( $c, 'CELULAR (el que registraste)' ) && false === strpos( $c, 'CONTRASEÑA' ) );
t( 'aviso "Escribí tu nombre y apellido tal cual figura arriba."', false !== strpos( $c, 'Escribí tu nombre y apellido tal cual figura arriba.' ) );
t( 'el aviso va debajo de la caja de datos y antes del botón', strpos( $c, 'CELULAR (el que registraste)' ) < strpos( $c, 'tal cual figura arriba' ) && strpos( $c, 'tal cual figura arriba' ) < strpos( $c, 'Entrar al Club' ) );

seccion( 'mail de bienvenida: plan Full' );
$m = mb_enviar( CN_MP::get_planes()['full']['nombre'] );
$c = $m[0]['cuerpo'];
t( 'Full: mantiene "(plan)": "Tu suscripción al Club Natureza (Club Natureza Full) está activa."', false !== strpos( $c, 'Tu suscripción al Club Natureza (Club Natureza Full) está activa.' ) );
t( 'Full: también tiene la etiqueta CELULAR y el aviso del nombre', false !== strpos( $c, 'CELULAR (el que registraste)' ) && false !== strpos( $c, 'Escribí tu nombre y apellido tal cual figura arriba.' ) && false === strpos( $c, 'CONTRASEÑA' ) );

seccion( 'mail de bienvenida: lo que no cambia' );
t( 'asunto y headers intactos', '¡Ya sos socia del Club Natureza! 🌿 — tus datos de acceso' === $m[0]['asunto'] && array( 'Content-Type: text/html; charset=UTF-8' ) === $m[0]['headers'] );
t( 'botón, pie, usuario y celular intactos', false !== strpos( $c, 'Entrar al Club →' ) && false !== strpos( $c, 'https://naturalesart.com/club-natureza-miembros/' ) && false !== strpos( $c, 'Tu suscripción se renueva cada mes y podés cancelarla cuando quieras desde Mercado Pago. Cualquier duda, escribinos por WhatsApp.' ) && false !== strpos( $c, 'USUARIO (nombre y apellido)' ) && false !== strpos( $c, 'Leandro Lencina' ) && false !== strpos( $c, '1155551234' ) );
t( 'sin email: no manda nada', 0 === count( ( function () {
	cn_test_reset();
	cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida_suscripcion', 'X', '1155551234', '', 'Club Natureza' );
	return $GLOBALS['cn_mails'];
} )() ) );

seccion( 'mail del trial: no se tocó' );
cn_test_reset();
cn_llamar( 'CN_Webhook', 'enviar_mail_bienvenida', 'Leandro Lencina', '1155551234', 'socia@example.com' );
$tr = $GLOBALS['cn_mails'][0]['cuerpo'] ?? '';
t( 'el mail del trial conserva "CONTRASEÑA (tu celular)" y no tiene los textos nuevos', false !== strpos( $tr, 'CONTRASEÑA (tu celular)' ) && false === strpos( $tr, 'CELULAR (el que registraste)' ) && false === strpos( $tr, 'tal cual figura arriba' ) );
