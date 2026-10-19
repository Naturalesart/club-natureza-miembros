<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Rol "Colaboradora Club": acceso acotado para cargar contenido (cursos, videos y material) desde el celular.
 *
 * - El rol tiene SOLO 'read' + el permiso propio cn_gestionar_contenido. Nunca manage_options.
 * - Una sola pantalla (menú "Cargar contenido") con altas. No borra ni edita, y no ve Socias, Cruce MP ni Config
 *   (ahí están los tokens de Mercado Pago, Meta y Drive).
 * - Los links se validan contra YouTube / Google Drive para evitar errores de pegado.
 * - Las pantallas actuales de CN_Admin no se tocan: los administradores siguen igual.
 */
class CN_Colaboradora {
	const ROL    = 'cn_colaboradora';
	const CAP    = 'cn_gestionar_contenido';
	const PAGINA = 'cn-colab';

	const HOSTS_YOUTUBE = array( 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be' );
	const HOSTS_DRIVE   = array( 'drive.google.com', 'docs.google.com' );

	/** Crea el rol una sola vez (get_role lee de la opción ya cacheada: casi gratis en cada request). */
	public static function registrar_rol() {
		if ( get_role( self::ROL ) ) {
			return;
		}
		add_role( self::ROL, 'Colaboradora Club', array( 'read' => true, self::CAP => true ) );
	}

	/** Puede usar la pantalla: la colaboradora, o un administrador (para ver cómo la ve ella). */
	public static function puede() {
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}

	public static function registrar_menu() {
		if ( ! self::puede() ) {
			return;
		}
		add_menu_page( 'Cargar contenido', 'Cargar contenido', 'read', self::PAGINA, array( __CLASS__, 'pagina' ), 'dashicons-upload', 3 );
	}

	/** Al iniciar sesión, la colaboradora (sin permisos de administrador) cae directo en su pantalla. */
	public static function redirigir_login( $url, $pedido, $usuario ) {
		if ( is_object( $usuario ) && ! is_wp_error( $usuario ) && user_can( $usuario, self::CAP ) && ! user_can( $usuario, 'manage_options' ) ) {
			return admin_url( 'admin.php?page=' . self::PAGINA );
		}
		return $url;
	}

	/** true si $url es http(s) y su host está en la lista. */
	public static function link_ok( $url, $hosts ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return false;
		}
		$esquema = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		$host    = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		return in_array( $esquema, array( 'http', 'https' ), true ) && in_array( $host, $hosts, true );
	}

	protected static function resultado( $ok, $texto ) {
		return array( 'tipo' => $ok ? 'success' : 'error', 'texto' => $texto );
	}

	protected static function curso_existe( $curso_id ) {
		global $wpdb;
		return $curso_id > 0 && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CN_DB::tabla( 'cursos' ) . ' WHERE id = %d', $curso_id ) ) > 0;
	}

	/**
	 * Procesa el formulario enviado (uno por vez). Devuelve null si no se envió ninguno, o array( 'tipo', 'texto' ).
	 */
	public static function procesar() {
		$accion = '';
		foreach ( array( 'cn_colab_video', 'cn_colab_contenido', 'cn_colab_curso', 'cn_colab_sync' ) as $clave ) {
			if ( isset( $_POST[ $clave ] ) ) {
				$accion = $clave;
				break;
			}
		}
		if ( '' === $accion ) {
			return null;
		}
		if ( ! self::puede() ) {
			return self::resultado( false, 'No tenés permisos para esto.' );
		}
		check_admin_referer( 'cn_colab', 'cn_colab_nonce' );
		global $wpdb;
		$titulo   = mb_substr( trim( sanitize_text_field( wp_unslash( $_POST['cn_titulo'] ?? '' ) ) ), 0, 200 );
		$link     = esc_url_raw( trim( (string) wp_unslash( $_POST['cn_link'] ?? '' ) ) );
		$curso_id = absint( $_POST['cn_curso_id'] ?? 0 );

		if ( 'cn_colab_video' === $accion ) {
			if ( '' === $titulo ) {
				return self::resultado( false, 'Escribí un título.' );
			}
			if ( ! self::link_ok( $link, self::HOSTS_YOUTUBE ) ) {
				return self::resultado( false, 'El link tiene que ser de YouTube (youtube.com o youtu.be).' );
			}
			if ( $curso_id && ! self::curso_existe( $curso_id ) ) {
				return self::resultado( false, 'Ese curso no existe.' );
			}
			$wpdb->insert(
				CN_DB::tabla( 'videos' ),
				array( 'titulo' => $titulo, 'link_youtube' => $link, 'curso_id' => $curso_id ? $curso_id : null, 'orden' => 0 ),
				array( '%s', '%s', '%d', '%d' )
			);
			return self::resultado( true, 'Video agregado.' );
		}

		if ( 'cn_colab_contenido' === $accion ) {
			$tipo = sanitize_key( wp_unslash( $_POST['cn_tipo'] ?? '' ) );
			if ( ! in_array( $tipo, array( 'video', 'pdf', 'audio' ), true ) ) {
				return self::resultado( false, 'Elegí el tipo: video, PDF o audio.' );
			}
			if ( ! self::curso_existe( $curso_id ) ) {
				return self::resultado( false, 'Elegí un curso.' );
			}
			if ( '' === $titulo ) {
				return self::resultado( false, 'Escribí un título.' );
			}
			if ( ! self::link_ok( $link, array_merge( self::HOSTS_YOUTUBE, self::HOSTS_DRIVE ) ) ) {
				return self::resultado( false, 'El link tiene que ser de YouTube o de Google Drive.' );
			}
			$wpdb->insert(
				CN_DB::tabla( 'contenidos' ),
				array(
					'curso_id'   => $curso_id,
					'tipo'       => $tipo,
					'titulo'     => $titulo,
					'link'       => $link,
					'duracion'   => mb_substr( sanitize_text_field( wp_unslash( $_POST['cn_duracion'] ?? '' ) ), 0, 20 ),
					'fuente'     => 'manual',
					'orden'      => 0,
					'fecha_alta' => current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
			return self::resultado( true, 'Contenido agregado.' );
		}

		if ( 'cn_colab_curso' === $accion ) {
			$nombre = mb_substr( trim( sanitize_text_field( wp_unslash( $_POST['cn_nombre'] ?? '' ) ) ), 0, 120 );
			if ( '' === $nombre ) {
				return self::resultado( false, 'Escribí el nombre del curso.' );
			}
			if ( ! self::link_ok( $link, self::HOSTS_DRIVE ) ) {
				return self::resultado( false, 'El link tiene que ser de la carpeta de Google Drive del curso.' );
			}
			$tabla  = CN_DB::tabla( 'cursos' );
			$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tabla}" );
			$maximo = (int) $wpdb->get_var( "SELECT COALESCE(MAX(orden), 0) FROM {$tabla}" );
			$wpdb->insert(
				$tabla,
				array( 'nombre' => $nombre, 'link_drive' => $link, 'orden' => $maximo + 1, 'color' => CN_Helpers::color_por_indice( $total ) ),
				array( '%s', '%s', '%d', '%s' )
			);
			return self::resultado( true, 'Curso creado. Ahora podés traer su contenido desde Drive.' );
		}

		// cn_colab_sync: trae videos, PDFs y audios de la carpeta de Drive del curso.
		if ( ! self::curso_existe( $curso_id ) ) {
			return self::resultado( false, 'Elegí un curso.' );
		}
		$res = CN_Drive::sincronizar_curso( $curso_id );
		if ( ! empty( $res['ok'] ) ) {
			return self::resultado( true, sprintf( 'Listo: %d nuevo(s), %d actualizado(s), %d ignorado(s).', (int) $res['agregados'], (int) $res['actualizados'], (int) $res['ignorados'] ) );
		}
		return self::resultado( false, 'No se pudo traer desde Drive: ' . ( isset( $res['error'] ) ? (string) $res['error'] : 'error desconocido' ) );
	}

	public static function pagina() {
		if ( ! self::puede() ) {
			return;
		}
		global $wpdb;
		$res    = self::procesar();
		$cursos = (array) $wpdb->get_results( 'SELECT id, nombre FROM ' . CN_DB::tabla( 'cursos' ) . ' ORDER BY orden ASC, id ASC' );
		$ultimos = (array) $wpdb->get_results( 'SELECT c.tipo, c.titulo, cu.nombre AS curso_nombre FROM ' . CN_DB::tabla( 'contenidos' ) . ' c LEFT JOIN ' . CN_DB::tabla( 'cursos' ) . ' cu ON cu.id = c.curso_id ORDER BY c.id DESC LIMIT 10' );
		$opciones = '';
		foreach ( $cursos as $c ) {
			$opciones .= '<option value="' . esc_attr( $c->id ) . '">' . esc_html( $c->nombre ) . '</option>';
		}
		?>
		<style>
			.cn-colab { max-width: 560px; }
			.cn-colab .card { max-width: none; padding: 4px 16px 16px; margin: 0 0 16px; }
			.cn-colab label { display: block; font-weight: 600; margin: 12px 0 4px; }
			.cn-colab input[type=text], .cn-colab input[type=url], .cn-colab select { width: 100%; font-size: 16px; min-height: 44px; }
			.cn-colab .button { min-height: 44px; font-size: 16px; margin-top: 14px; }
		</style>
		<div class="wrap cn-colab">
			<h1>Cargar contenido</h1>
			<?php if ( $res ) : ?>
				<div class="notice notice-<?php echo esc_attr( $res['tipo'] ); ?> is-dismissible"><p><?php echo esc_html( $res['texto'] ); ?></p></div>
			<?php endif; ?>
			<p>Pegá el link de YouTube o de Google Drive. Los archivos pesados se suben primero a Drive o YouTube.</p>

			<div class="card">
				<h2>Agregar material a un curso</h2>
				<form method="post">
					<?php wp_nonce_field( 'cn_colab', 'cn_colab_nonce' ); ?>
					<label>Curso</label>
					<select name="cn_curso_id" required><option value="">— Elegir curso —</option><?php echo $opciones; // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
					<label>Tipo</label>
					<select name="cn_tipo" required><option value="video">Video</option><option value="pdf">PDF</option><option value="audio">Audio</option></select>
					<label>Título</label>
					<input type="text" name="cn_titulo" maxlength="200" required>
					<label>Link (YouTube o Drive)</label>
					<input type="url" name="cn_link" required placeholder="https://...">
					<label>Duración (opcional, solo audio)</label>
					<input type="text" name="cn_duracion" maxlength="20" placeholder="12:30">
					<button type="submit" name="cn_colab_contenido" value="1" class="button button-primary">Agregar</button>
				</form>
			</div>

			<div class="card">
				<h2>Agregar un video de YouTube</h2>
				<form method="post">
					<?php wp_nonce_field( 'cn_colab', 'cn_colab_nonce' ); ?>
					<label>Título</label>
					<input type="text" name="cn_titulo" maxlength="200" required>
					<label>Link de YouTube</label>
					<input type="url" name="cn_link" required placeholder="https://youtu.be/...">
					<label>Curso (opcional)</label>
					<select name="cn_curso_id"><option value="0">— Sin curso —</option><?php echo $opciones; // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
					<button type="submit" name="cn_colab_video" value="1" class="button button-primary">Agregar video</button>
				</form>
			</div>

			<div class="card">
				<h2>Crear un curso nuevo</h2>
				<form method="post">
					<?php wp_nonce_field( 'cn_colab', 'cn_colab_nonce' ); ?>
					<label>Nombre del curso</label>
					<input type="text" name="cn_nombre" maxlength="120" required>
					<label>Link de la carpeta de Drive</label>
					<input type="url" name="cn_link" required placeholder="https://drive.google.com/drive/folders/...">
					<button type="submit" name="cn_colab_curso" value="1" class="button button-primary">Crear curso</button>
				</form>
			</div>

			<div class="card">
				<h2>Traer contenido desde Drive</h2>
				<p>Lee la carpeta del curso y agrega los videos, PDFs y audios nuevos.</p>
				<form method="post">
					<?php wp_nonce_field( 'cn_colab', 'cn_colab_nonce' ); ?>
					<label>Curso</label>
					<select name="cn_curso_id" required><option value="">— Elegir curso —</option><?php echo $opciones; // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
					<button type="submit" name="cn_colab_sync" value="1" class="button">Sincronizar con Drive</button>
				</form>
			</div>

			<div class="card">
				<h2>Lo último que se cargó</h2>
				<?php if ( ! $ultimos ) : ?>
					<p>Todavía no hay contenido cargado.</p>
				<?php else : ?>
					<ul>
						<?php foreach ( $ultimos as $u ) : ?>
							<li><?php echo esc_html( ucfirst( (string) $u->tipo ) . ' · ' . $u->titulo . ' · ' . ( $u->curso_nombre ? $u->curso_nombre : '—' ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
