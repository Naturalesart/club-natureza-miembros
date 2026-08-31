<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Progreso mínimo viable de la socia: último curso/clase abierta, y qué
 * videos marcó como vistos. Tabla propia, no reutiliza nada externo.
 */
class CN_Progreso {

	/**
	 * Registra la apertura de una clase (video): actualiza "seguí donde
	 * dejaste" y marca el video como visto.
	 */
	public static function registrar_apertura( $miembro_id, $curso_id, $contenido_id ) {
		self::marcar_visto( $miembro_id, $contenido_id );
		self::actualizar_ultimo( $miembro_id, $curso_id, $contenido_id );
	}

	public static function marcar_visto( $miembro_id, $contenido_id ) {
		global $wpdb;
		$tabla = CN_DB::tabla( 'vistos' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$tabla} (miembro_id, contenido_id, fecha) VALUES (%d, %d, %s)",
				$miembro_id,
				$contenido_id,
				current_time( 'mysql', true )
			)
		);
	}

	public static function actualizar_ultimo( $miembro_id, $curso_id, $contenido_id ) {
		global $wpdb;
		$tabla = CN_DB::tabla( 'progreso' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$tabla} (miembro_id, curso_id, contenido_id, actualizado) VALUES (%d, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE curso_id = VALUES(curso_id), contenido_id = VALUES(contenido_id), actualizado = VALUES(actualizado)",
				$miembro_id,
				$curso_id,
				$contenido_id,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * @return object|null { curso_id, contenido_id, actualizado, curso_nombre, curso_color, contenido_titulo }
	 */
	public static function get_ultimo( $miembro_id ) {
		global $wpdb;
		$t_progreso   = CN_DB::tabla( 'progreso' );
		$t_cursos     = CN_DB::tabla( 'cursos' );
		$t_contenidos = CN_DB::tabla( 'contenidos' );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.curso_id, p.contenido_id, p.actualizado, c.nombre AS curso_nombre, c.color AS curso_color, ct.titulo AS contenido_titulo
				 FROM {$t_progreso} p
				 INNER JOIN {$t_cursos} c ON c.id = p.curso_id
				 INNER JOIN {$t_contenidos} ct ON ct.id = p.contenido_id
				 WHERE p.miembro_id = %d",
				$miembro_id
			)
		);
	}

	/**
	 * @return array de contenido_id (int) vistos por la socia dentro de un curso.
	 */
	public static function get_vistos_ids( $miembro_id, $curso_id ) {
		global $wpdb;
		$t_vistos     = CN_DB::tabla( 'vistos' );
		$t_contenidos = CN_DB::tabla( 'contenidos' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT v.contenido_id FROM {$t_vistos} v
				 INNER JOIN {$t_contenidos} c ON c.id = v.contenido_id
				 WHERE v.miembro_id = %d AND c.curso_id = %d",
				$miembro_id,
				$curso_id
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Porcentaje de videos vistos por la socia dentro de un curso (0-100).
	 */
	public static function progreso_pct( $miembro_id, $curso_id ) {
		global $wpdb;
		$t_contenidos = CN_DB::tabla( 'contenidos' );

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$t_contenidos} WHERE curso_id = %d AND tipo = 'video'", $curso_id )
		);
		if ( ! $total ) {
			return 0;
		}

		$vistos = count( self::get_vistos_ids( $miembro_id, $curso_id ) );
		return (int) round( ( $vistos / $total ) * 100 );
	}
}
