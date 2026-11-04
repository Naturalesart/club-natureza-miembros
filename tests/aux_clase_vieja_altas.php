<?php
// Escenario "altas" de aux_clase_vieja.php: con una CN_Cuentas VIEJA (sin reemplaza_bienvenida_datos ni enganches) las
// altas del webhook siguen mandando la bienvenida de siempre y nada rompe. Se incluye desde aux_clase_vieja.php.
if ( ! getenv( 'CN_TEST_CLASE_VIEJA' ) ) {
	exit;
}
$out['tiene_metodos_nuevos'] = method_exists( 'CN_Cuentas', 'reemplaza_bienvenida_datos' ) || method_exists( 'CN_Cuentas', 'enganche_trial' ) || method_exists( 'CN_Cuentas', 'registrar' );
$web = new ReflectionClass( 'CN_Webhook' );
$llamar = function ( $metodo, ...$args ) use ( $web ) {
	$m = $web->getMethod( $metodo );
	return $m->invoke( null, ...$args );
};
$llamar( 'enviar_mail_bienvenida', 'Socia Prueba', '1155550001', 'socia@example.com' );
$llamar( 'enviar_mail_bienvenida_suscripcion', 'Socia Prueba', '1155550001', 'socia@example.com', 'Club Natureza' );
$out['asuntos'] = array_map( function ( $m ) {
	return $m['asunto'];
}, $GLOBALS['cn_mails'] );
// el sitio de los enganches por el camino real de sub_post_alta (mail + CAPI)
$llamar( 'sub_post_alta', (object) array( 'nombre_apellido' => 'Socia Prueba', 'celular' => '1155550001', 'email' => 'socia@example.com', 'external_reference' => 'sub_x', 'fbp' => '', 'fbc' => '', 'ip' => '', 'user_agent' => '' ), 'full', CN_MP::get_planes() );
$out['mails_tras_sub_post_alta'] = count( $GLOBALS['cn_mails'] );
