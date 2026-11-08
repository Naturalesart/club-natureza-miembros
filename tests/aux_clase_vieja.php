<?php
// Proceso aparte que simula el deploy por SFTP a medias: existe una CN_Cuentas VIEJA (la del PASO B, sin los métodos
// nuevos y con ficha() y cuenta_por_id() todavía protegidos) y las clases nuevas ya subieron. No es un test: lo lanzan
// test_cuentas_mail.php y test_zcuentas_altas.php con CN_TEST_CLASE_VIEJA=1 y leen el JSON que imprime.
//   CN_TEST_CLASE_VIEJA=1 php tests/aux_clase_vieja.php mail|altas
if ( PHP_SAPI !== 'cli' || ! getenv( 'CN_TEST_CLASE_VIEJA' ) ) {
	exit;
}
require __DIR__ . '/bootstrap.php';
cn_test_reset();
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $h ) {
		return $GLOBALS['aux_cron'][ $h ] ?? false;
	}
	function wp_schedule_event( $t, $r, $h ) {
		$GLOBALS['aux_cron'][ $h ] = $r;
		return true;
	}
}
$GLOBALS['aux_cron'] = array();

/** La CN_Cuentas del PASO B: modo() y poco más. Sin registrar(), sin enganches, sin guardas, sin marcas. */
class CN_Cuentas {
	const OPT_MODO = 'cn_cuentas_modo';
	public static function modo() {
		return (string) get_option( self::OPT_MODO, 'off' );
	}
	public static function mascara( $e ) {
		return '***';
	}
	public static function emitir_token( $c, $p ) {
		return str_repeat( 'a', 64 );
	}
	public static function provisionar( $f, $o ) {
		return array( 'accion' => 'off' );
	}
	protected static function ficha( $id ) {
		return null;
	}
	protected static function cuenta_por_id( $id ) {
		return null;
	}
}
update_option( 'cn_cuentas_modo', 'on' );
update_option( 'cn_cuentas_desde', 1 );
$out = array( 'escenario' => $argv[1] ?? '' );

if ( 'mail' === ( $argv[1] ?? '' ) ) {
	require dirname( __DIR__ ) . '/includes/class-cn-mail-cuenta.php';
	$out['enviar']        = CN_Mail_Cuenta::enviar( 1, 'recuperar' );
	$out['recordatorios'] = CN_Mail_Cuenta::recordatorios();
	CN_Mail_Cuenta::programar_cron();
	$out['cron']          = $GLOBALS['aux_cron'];
	$out['vista_previa']  = CN_Mail_Cuenta::enviar_vista_previa( 'recuperar' );
	$out['mails']         = count( $GLOBALS['cn_mails'] );
}
if ( 'altas' === ( $argv[1] ?? '' ) && is_file( __DIR__ . '/aux_clase_vieja_altas.php' ) ) {
	require __DIR__ . '/aux_clase_vieja_altas.php';
}
echo json_encode( $out );
