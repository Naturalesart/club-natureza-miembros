<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CN_Activator {

	public static function activar() {
		CN_DB::instalar();
		update_option( 'cn_db_version', CN_DB::DB_VERSION );
		self::crear_paginas();
	}

	protected static function crear_paginas() {
		self::crear_pagina_si_no_existe( 'CLUB NATUREZA MIEMBROS', 'club-natureza-miembros', '[cn_login]' );
		self::crear_pagina_si_no_existe( 'Club Natureza — Suscripción', 'club-natureza-suscripcion', '[cn_suscripcion]' );
	}

	protected static function crear_pagina_si_no_existe( $titulo, $slug, $contenido ) {
		$existente = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existente instanceof WP_Post ) {
			return $existente->ID;
		}

		return wp_insert_post( array(
			'post_title'   => $titulo,
			'post_name'    => $slug,
			'post_content' => $contenido,
			'post_status'  => 'publish',
			'post_type'    => 'page',
		) );
	}
}
