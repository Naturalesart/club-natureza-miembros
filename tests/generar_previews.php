<?php
// Genera las vistas previas de los 6 mails de cuenta en tests/previews/ (datos ficticios, no se despliegan).
//   php tests/generar_previews.php
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
require __DIR__ . '/bootstrap.php';
$dir = __DIR__ . '/previews';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0777, true );
}
$i = 0;
foreach ( array_keys( CN_Mail_Cuenta::PLANTILLAS ) as $p ) {
	$i++;
	$m = CN_Mail_Cuenta::armar( $p, CN_Mail_Cuenta::datos_ejemplo() );
	file_put_contents( sprintf( '%s/%02d-%s.html', $dir, $i, $p ), $m['html'] );
	file_put_contents( sprintf( '%s/%02d-%s.txt', $dir, $i, $p ), 'Asunto: ' . $m['asunto'] . "\n\n" . $m['texto'] . "\n" );
	echo sprintf( "%02d %s -> %s\n", $i, $p, $m['asunto'] );
}
