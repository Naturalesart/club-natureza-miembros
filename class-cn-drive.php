<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Conector de solo lectura contra la API pública de Google Drive (v3).
 *
 * Funciona con una API key simple (sin OAuth) siempre que la carpeta del curso
 * esté compartida como "Cualquier persona con el enlace — Lector". Si no hay
 * API key configurada, o la carpeta no es pública, la sincronización automática
 * falla con un mensaje claro y el admin puede seguir cargando el contenido a
 * mano desde la pantalla "Contenido" (tabla wp_cn_contenidos, fuente = manual).
 */
class CN_Drive {

	const API_BASE = 'https://www.googleapis.com/drive/v3/files';

	// mimeType que Google le da a las carpetas.
	const FOLDER_MIME = 'application/vnd.google-apps.folder';

	// Cuántos niveles de subcarpetas se recorren como máximo (evita loops y runaway).
	const MAX_DEPTH = 6;

	public static function get_api_key() {
		return trim( (string) get_option( 'cn_google_drive_api_key', '' ) );
	}

	/**
	 * Clasifica un archivo de Drive en video / pdf / audio según su mimeType
	 * (y si hace falta, la extensión del nombre). Devuelve '' si no aplica
	 * (carpetas, Google Docs/Sheets, imágenes sueltas, etc.).
	 */
	public static function clasificar( $mime_type, $nombre ) {
		$mime_type = (string) $mime_type;

		if ( 0 === strpos( $mime_type, 'video/' ) ) {
			return 'video';
		}
		if ( 0 === strpos( $mime_type, 'audio/' ) ) {
			return 'audio';
		}
		if ( 'application/pdf' === $mime_type ) {
			return 'pdf';
		}

		$ext = strtolower( pathinfo( (string) $nombre, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'mp4', 'mov', 'm4v', 'avi', 'webm' ), true ) ) {
			return 'video';
		}
		if ( in_array( $ext, array( 'mp3', 'wav', 'm4a', 'aac', 'ogg' ), true ) ) {
			return 'audio';
		}
		if ( 'pdf' === $ext ) {
			return 'pdf';
		}

		return '';
	}

	protected static function nombre_sin_extension( $nombre ) {
		$punto = strrpos( $nombre, '.' );
		return $punto ? substr( $nombre, 0, $punto ) : $nombre;
	}

	/**
	 * Trae todos los archivos de una carpeta de Drive, recorriendo también las
	 * subcarpetas (los videos de cada curso suelen estar en una subcarpeta como
	 * "Videos" dentro de la carpeta del curso). Las carpetas en sí no se devuelven
	 * como archivos, solo se recorren.
	 *
	 * @return array { ok: bool, archivos: array, error: string }
	 */
	protected static function listar_archivos( $folder_id ) {
		$api_key = self::get_api_key();
		if ( ! $api_key ) {
			return array( 'ok' => false, 'archivos' => array(), 'error' => 'Falta configurar la API key de Google Drive en Config.' );
		}

		$archivos = array();
		$error    = '';
		self::recorrer_carpeta( $folder_id, $api_key, $archivos, $error, 0 );

		if ( '' !== $error ) {
			return array( 'ok' => false, 'archivos' => array(), 'error' => $error );
		}

		return array( 'ok' => true, 'archivos' => $archivos, 'error' => '' );
	}

	/**
	 * Lista una carpeta (con paginación), separa archivos de subcarpetas, y
	 * recursivamente entra a cada subcarpeta. Los archivos encontrados se
	 * acumulan en $archivos (por referencia). Si algo falla, deja el mensaje
	 * en $error (por referencia) y corta.
	 */
	protected static function recorrer_carpeta( $folder_id, $api_key, &$archivos, &$error, $profundidad ) {
		if ( $profundidad > self::MAX_DEPTH ) {
			return;
		}

		$subcarpetas    = array();
		$page_token     = '';
		$paginas_vistas = 0;

		do {
			$args = array(
				'q'        => "'" . $folder_id . "' in parents and trashed = false",
				'fields'   => 'nextPageToken, files(id, name, mimeType)',
				'orderBy'  => 'folder,name_natural',
				'pageSize' => 200,
				'key'      => $api_key,
			);
			if ( $page_token ) {
				$args['pageToken'] = $page_token;
			}

			$url       = add_query_arg( $args, self::API_BASE );
			$respuesta = wp_remote_get( $url, array( 'timeout' => 20 ) );

			if ( is_wp_error( $respuesta ) ) {
				$error = $respuesta->get_error_message();
				return;
			}

			$codigo = wp_remote_retrieve_response_code( $respuesta );
			$data   = json_decode( wp_remote_retrieve_body( $respuesta ), true );

			if ( $codigo < 200 || $codigo >= 300 ) {
				$error = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Google Drive rechazó la solicitud (código ' . $codigo . ').';
				return;
			}

			if ( ! empty( $data['files'] ) && is_array( $data['files'] ) ) {
				foreach ( $data['files'] as $archivo ) {
					if ( isset( $archivo['mimeType'] ) && self::FOLDER_MIME === $archivo['mimeType'] ) {
						$subcarpetas[] = $archivo['id'];
					} else {
						$archivos[] = $archivo;
					}
				}
			}

			$page_token = isset( $data['nextPageToken'] ) ? $data['nextPageToken'] : '';
			$paginas_vistas++;
		} while ( $page_token && $paginas_vistas < 10 );

		foreach ( $subcarpetas as $sub_id ) {
			self::recorrer_carpeta( $sub_id, $api_key, $archivos, $error, $profundidad + 1 );
			if ( '' !== $error ) {
				return;
			}
		}
	}

	/**
	 * Sincroniza el contenido de un curso contra su carpeta de Drive:
	 * lista los archivos, los clasifica y los da de alta (o actualiza) en
	 * wp_cn_contenidos con fuente = drive_auto. No toca los ítems manuales.
	 *
	 * @return array { ok: bool, agregados: int, actualizados: int, ignorados: int, error: string }
	 */
	public static function sincronizar_curso( $curso_id ) {
		global $wpdb;

		$t_cursos = CN_DB::tabla( 'cursos' );
		$curso = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t_cursos} WHERE id = %d", $curso_id ) );
		if ( ! $curso ) {
			return array( 'ok' => false, 'agregados' => 0, 'actualizados' => 0, 'ignorados' => 0, 'error' => 'Curso no encontrado.' );
		}

		$folder_id = CN_Helpers::extraer_drive_folder_id( $curso->link_drive );
		if ( ! $folder_id ) {
			return array( 'ok' => false, 'agregados' => 0, 'actualizados' => 0, 'ignorados' => 0, 'error' => 'El link del curso no es un link de carpeta de Google Drive (debe contener /folders/).' );
		}

		$resultado = self::listar_archivos( $folder_id );
		if ( ! $resultado['ok'] ) {
			return array( 'ok' => false, 'agregados' => 0, 'actualizados' => 0, 'ignorados' => 0, 'error' => $resultado['error'] );
		}

		$t_contenidos = CN_DB::tabla( 'contenidos' );
		$agregados   = 0;
		$actualizados = 0;
		$ignorados   = 0;
		$orden       = 0;

		foreach ( $resultado['archivos'] as $archivo ) {
			$tipo = self::clasificar( $archivo['mimeType'] ?? '', $archivo['name'] ?? '' );
			if ( ! $tipo ) {
				$ignorados++;
				continue;
			}

			$drive_file_id = sanitize_text_field( $archivo['id'] );
			$titulo        = sanitize_text_field( self::nombre_sin_extension( $archivo['name'] ) );
			$link          = 'https://drive.google.com/file/d/' . $drive_file_id . '/view';

			$existente = $wpdb->get_row(
				$wpdb->prepare( "SELECT id FROM {$t_contenidos} WHERE drive_file_id = %s", $drive_file_id )
			);

			if ( $existente ) {
				$wpdb->update(
					$t_contenidos,
					array( 'titulo' => $titulo, 'tipo' => $tipo, 'link' => $link, 'curso_id' => $curso_id ),
					array( 'id' => $existente->id ),
					array( '%s', '%s', '%s', '%d' ),
					array( '%d' )
				);
				$actualizados++;
			} else {
				$wpdb->insert(
					$t_contenidos,
					array(
						'curso_id'      => $curso_id,
						'tipo'          => $tipo,
						'titulo'        => $titulo,
						'link'          => $link,
						'drive_file_id' => $drive_file_id,
						'fuente'        => 'drive_auto',
						'orden'         => $orden,
						'fecha_alta'    => current_time( 'mysql', true ),
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
				);
				$agregados++;
			}
			$orden++;
		}

		$wpdb->update(
			$t_cursos,
			array( 'drive_synced_at' => current_time( 'mysql', true ) ),
			array( 'id' => $curso_id ),
			array( '%s' ),
			array( '%d' )
		);

		return array( 'ok' => true, 'agregados' => $agregados, 'actualizados' => $actualizados, 'ignorados' => $ignorados, 'error' => '' );
	}
}
