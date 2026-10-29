<?php
// Plantilla de mails "carta" (CN_Mail_Carta): borrador que arma HTML + texto plano y NO envía nada.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}

seccion( 'Plantilla carta: no envía ni registra hooks' );
$cn_metodos = get_class_methods( 'CN_Mail_Carta' );
$cn_sosp    = array();
foreach ( $cn_metodos as $cn_m ) {
	if ( false !== stripos( $cn_m, 'enviar' ) || false !== stripos( $cn_m, 'send' ) ) {
		$cn_sosp[] = $cn_m;
	}
}
t( 'la clase no tiene métodos de envío', 0 === count( $cn_sosp ), implode( ',', $cn_sosp ) );
$cn_fuente = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-mail-carta.php' );
t( 'el archivo no llama a wp_mail', false === strpos( $cn_fuente, 'wp_mail' ) );
t( 'el archivo no registra hooks', false === strpos( $cn_fuente, 'add_action' ) && false === strpos( $cn_fuente, 'add_filter' ) );

seccion( 'Plantilla carta: especificaciones del diseño' );
$cn_m = CN_Mail_Carta::armar( array(
	'titulo'   => 'Hola, Prueba',
	'parrafos' => array( 'Primer párrafo.', 'Segundo párrafo.' ),
	'boton'    => array( 'texto' => 'Quiero entrar', 'url' => 'https://naturalesart.com/x' ),
) );
$cn_h = $cn_m['html'];
t( 'ancho 600 px', false !== strpos( $cn_h, 'max-width:600px' ) );
t( 'fondo crema', false !== strpos( $cn_h, '#FAF3E6' ) );
t( 'verde de acento', false !== strpos( $cn_h, '#2F4A3A' ) );
t( 'botón terracota con texto blanco', false !== strpos( $cn_h, '#A9502E' ) && false !== strpos( $cn_h, 'color:#FFFFFF' ) );
t( 'cuerpo de 17 px', false !== strpos( $cn_h, 'font-size:17px' ) );
t( 'interlineado 1,5', false !== strpos( $cn_h, 'line-height:1.5' ) );
t( 'títulos en Georgia', false !== strpos( $cn_h, 'Georgia' ) );
t( 'cuerpo en Arial/Helvetica', false !== strpos( $cn_h, 'Arial,Helvetica' ) );
t( 'meta color-scheme', false !== strpos( $cn_h, 'name="color-scheme" content="light dark"' ) );
t( 'overrides de modo oscuro', false !== strpos( $cn_h, 'prefers-color-scheme: dark' ) );
t( 'botón de al menos 48 px (15+20+15) y con margen lateral chico para que entre en el celular', false !== strpos( $cn_h, 'padding:15px 12px' ) && false !== strpos( $cn_h, 'font-size:17px;font-weight:bold;line-height:1.2' ) );
t( 'la flecha va pegada a la última palabra con espacio duro (nunca queda sola)', false !== strpos( $cn_h, "entrar\xC2\xA0→</a>" ) && false === strpos( $cn_h, 'entrar →</a>' ) );
t( 'el texto plano usa espacio común antes de la flecha', false !== strpos( $cn_m['texto'], 'Quiero entrar → https://' ) );
t( 'botón en primera persona con flecha', false !== strpos( $cn_h, "Quiero entrar →" ) );
t( 'un solo botón', 1 === substr_count( $cn_h, 'display:block;padding:15px 12px' ) );
t( 'línea fija de sin cargo debajo del botón', false !== strpos( $cn_h, CN_Mail_Carta::LINEA_SIN_CARGO ) );
t( 'firma del equipo destacada', false !== strpos( $cn_h, 'El equipo de Naturales Art' ) && false !== strpos( $cn_h, 'font-size:21px' ) );
t( 'baja en un clic', false !== strpos( $cn_h, 'Dejar de recibirlos' ) && false !== strpos( $cn_h, 'mailto:' ) );
t( 'sin banners, GIF ni fotos si no se piden', false === strpos( $cn_h, '.gif' ) && false === strpos( $cn_h, '<img' ) );

$cn_mf = CN_Mail_Carta::armar( array(
	'titulo'   => 'Con foto',
	'parrafos' => array( 'Hola.' ),
	'foto'     => array( 'url' => 'https://naturalesart.com/foto.jpg', 'alt' => 'Naty pintando' ),
	'boton'    => array( 'texto' => 'Sigo', 'url' => 'https://naturalesart.com/x' ),
) );
t( 'una foto como máximo y solo si se pide', 1 === substr_count( $cn_mf['html'], '<img' ) );

$cn_mx = CN_Mail_Carta::armar( array(
	'titulo'   => 'Hola <script>alert(1)</script>',
	'parrafos' => array( 'Texto con <b>html</b>.' ),
	'boton'    => array( 'texto' => 'Voy', 'url' => 'https://naturalesart.com/x' ),
) );
t( 'el título se escapa', false === strpos( $cn_mx['html'], '<script>' ) );
t( 'los párrafos se escapan', false === strpos( $cn_mx['html'], '<b>html</b>' ) );

seccion( 'Plantilla carta: versión en texto plano' );
$cn_mt = CN_Mail_Carta::armar( array(
	'titulo'    => 'Título de prueba',
	'parrafos'  => array( 'Un párrafo.' ),
	'destacado' => array( array( 'etiqueta' => 'Usuario', 'valor' => 'Prueba Uno' ) ),
	'boton'     => array( 'texto' => 'Entro', 'url' => 'https://naturalesart.com/ingreso' ),
) );
$cn_t = $cn_mt['texto'];
t( 'texto plano sin etiquetas HTML', false === strpos( $cn_t, '<' ) );
t( 'texto plano con el título', false !== strpos( $cn_t, 'Título de prueba' ) );
t( 'texto plano con los datos destacados', false !== strpos( $cn_t, 'USUARIO: Prueba Uno' ) );
t( 'texto plano con el link del botón', false !== strpos( $cn_t, 'https://naturalesart.com/ingreso' ) );
t( 'texto plano con la línea de sin cargo', false !== strpos( $cn_t, CN_Mail_Carta::LINEA_SIN_CARGO ) );
t( 'texto plano con la baja', false !== strpos( $cn_t, 'BAJA' ) );

seccion( 'Plantilla carta: los tres borradores' );
$cn_bs = array(
	'dia0'        => CN_Mail_Carta::borrador_dia0( 'Prueba Uno', '1100000000', 'https://naturalesart.com/ingreso' ),
	'dia5'        => CN_Mail_Carta::borrador_dia5( 'Prueba Uno', 'https://naturalesart.com/club-naturales/' ),
	'vencimiento' => CN_Mail_Carta::borrador_vencimiento( 'Prueba Uno', 'https://naturalesart.com/club-naturales/' ),
);
foreach ( $cn_bs as $cn_k => $cn_b ) {
	t( "borrador {$cn_k}: asunto, html y texto", '' !== $cn_b['asunto'] && false !== strpos( $cn_b['html'], '#A9502E' ) && '' !== $cn_b['texto'] );
	t( "borrador {$cn_k}: línea de sin cargo", false !== strpos( $cn_b['html'], CN_Mail_Carta::LINEA_SIN_CARGO ) );
	t( "borrador {$cn_k}: un solo botón", 1 === substr_count( $cn_b['html'], 'display:block;padding:15px 12px' ) );
}
t( 'día 0 lleva los datos de acceso', false !== strpos( $cn_bs['dia0']['html'], 'Prueba Uno' ) && false !== strpos( $cn_bs['dia0']['html'], '1100000000' ) );
