<?php
// Corre todos los tests/test_*.php:  php tests/run.php
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
require __DIR__ . '/bootstrap.php';
$archivos = glob( __DIR__ . '/test_*.php' );
sort( $archivos );
foreach ( $archivos as $a ) {
	echo "\n##### " . basename( $a ) . "\n";
	cn_test_reset();
	require $a;
}
echo "\n----------------------------------------\n";
echo 'OK: ' . $GLOBALS['cn_t_ok'] . ' | FALLAS: ' . count( $GLOBALS['cn_t_fail'] ) . "\n";
foreach ( $GLOBALS['cn_t_fail'] as $f ) {
	echo ' - ' . $f . "\n";
}
exit( $GLOBALS['cn_t_fail'] ? 1 : 0 );
