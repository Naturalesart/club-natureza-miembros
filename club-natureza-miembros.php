<?php
/**
 * Plugin Name: Club Natureza Miembros
 * Description: Sistema propio de membresías para el Club Natureza (naturalesart.com) — login por nombre y celular, cursos, videos y suscripción vía Mercado Pago. Se embebe en el theme mediante shortcodes.
 * Version: 1.4.0
 * Author: Naturales Art
 * Text Domain: club-natureza-miembros
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Número de WhatsApp de soporte para socias en pausa (formato wa.me, solo dígitos con código de país).
define( 'CN_WA_SOPORTE', '5491162076549' );

define( 'CN_VERSION', '1.4.0' );
define( 'CN_PATH', plugin_dir_path( __FILE__ ) );
define( 'CN_URL', plugin_dir_url( __FILE__ ) );

require_once CN_PATH . 'includes/class-cn-helpers.php';
require_once CN_PATH . 'includes/class-cn-db.php';
require_once CN_PATH . 'includes/class-cn-activator.php';
require_once CN_PATH . 'includes/class-cn-auth.php';
require_once CN_PATH . 'includes/class-cn-progreso.php';
require_once CN_PATH . 'includes/class-cn-drive.php';
require_once CN_PATH . 'includes/class-cn-mp.php';
require_once CN_PATH . 'includes/class-cn-webhook.php';
require_once CN_PATH . 'includes/class-cn-shortcodes.php';
require_once CN_PATH . 'includes/class-cn-admin.php';

register_activation_hook( __FILE__, array( 'CN_Activator', 'activar' ) );

add_action( 'init', array( 'CN_Shortcodes', 'registrar' ) );
add_action( 'rest_api_init', array( 'CN_Webhook', 'registrar_rutas' ) );
add_action( 'admin_init', array( 'CN_DB', 'maybe_upgrade' ) );

// Protege el contenido de socias (podcast, vivos, youtube) para que solo se
// muestre si hay una sesion valida del Club Natureza (CN_Auth::get_miembro_actual()).
add_filter( 'do_shortcode_tag', function ( $output, $tag, $attr, $m ) {
	static $tags_protegidos = array(
		'naturales_podcast_section',
		'naturales_vivos_section',
		'naturales_youtube_section',
	);
	if ( ! in_array( $tag, $tags_protegidos, true ) ) {
		return $output;
	}
	$miembro = class_exists( 'CN_Auth' ) ? CN_Auth::get_miembro_actual() : null;
	if ( $miembro ) {
		return $output;
	}
	return '';
}, 10, 4 );



add_action( 'wp_enqueue_scripts', function () {
	wp_register_style( 'cn-google-fonts', 'https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600;700&family=Nunito+Sans:wght@400;600;700&display=swap', array(), null );
	wp_register_style( 'cn-style', CN_URL . 'assets/css/cn-style.css', array( 'cn-google-fonts' ), CN_VERSION );
	wp_register_script( 'cn-club', CN_URL . 'assets/js/cn-club.js', array(), CN_VERSION, true );
} );

if ( is_admin() ) {
	add_action( 'admin_menu', array( 'CN_Admin', 'registrar_menu' ) );
}

// Las páginas con [cn_login] o [cn_suscripcion] muestran contenido distinto según
// la cookie de sesión de cada socia (o el resultado de un POST). Nunca deben
// quedar en caché de página (LiteSpeed u otro) — si eso pasa, todas las visitas
// siguientes ven el HTML congelado de la primera visita, sesión incluida.
add_action( 'template_redirect', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! ( has_shortcode( $post->post_content, 'cn_login' ) || has_shortcode( $post->post_content, 'cn_suscripcion' ) ) ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	header( 'X-LiteSpeed-Cache-Control: no-cache' );

	// Mitigación del bug "carga en blanco la primera vez y anda al recargar":
	// el CDN de Hostinger anuncia HTTP/3 (QUIC) vía Alt-Svc y en algunas redes
	// móviles el handshake QUIC falla, dejando la primera navegación (incluido el
	// redirect post-login) en blanco. Al mandar Alt-Svc: clear le pedimos al
	// navegador que NO use QUIC para este origen, cayendo a HTTP/2 (estable).
	// Si el edge de Hostinger reescribe el header, esto no alcanza y hay que
	// desactivar QUIC/CDN desde hPanel — pero es inocuo intentarlo acá.
	header( 'Alt-Svc: clear' );
} );
