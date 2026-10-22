<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Panel de contenido para el celular: shortcode [cn_panel_contenido].
 *
 * Que la colaboradora (rol CN_Colaboradora) suba y edite contenido del Club sin entrar a wp-admin.
 *
 * - Sin sesión: solo un login. Con sesión sin permiso: aviso. Con permiso (cn_gestionar_contenido o manage_options): el panel.
 * - Las altas reutilizan CN_Colaboradora::procesar() (mismas reglas, mismos links permitidos: no se duplica nada).
 * - Las ediciones cambian solo título / link / tipo / curso / orden / duración. NO hay borrado ni ocultar.
 * - Cada edición guarda antes un backup de la fila previa en mp_log (tipo contenido_backup), igual que vinculo_backup:
 *   sin migraciones. Si el backup falla, no se edita.
 * - Los ítems de Drive (fuente = drive_auto) los vuelve a escribir CN_Drive::sincronizar_curso() (título, tipo, link y curso)
 *   en cada sincronización; por eso de esos ítems solo se editan orden y duración (la sincronización no los toca).
 * - Toda escritura es POST + capability + nonce y termina en redirect (POST-redirect-GET) con un mensaje.
 * - La página sale noindex y sin caché. No toca webhook, cobros ni el área de socias.
 */
class CN_Panel_Contenido {
	const SHORTCODE  = 'cn_panel_contenido';
	const POR_PAGINA = 20;
	const VISTAS     = array( 'inicio', 'video', 'link', 'curso', 'lista', 'editar' );

	/** Engancha todo lo del panel (se llama una vez desde el archivo principal). */
	public static function registrar() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'al_cargar_pagina' ), 5 );
		add_filter( 'login_redirect', array( __CLASS__, 'redirigir_login' ), 20, 3 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'filtrar_barra_admin' ) );
		add_action( 'admin_init', array( __CLASS__, 'sacar_de_wp_admin' ) );
	}

	// ------------------------------------------------------------------ quién es quién

	/** La colaboradora "pura": con el permiso del panel pero sin ser administradora. */
	public static function es_colaboradora() {
		return is_user_logged_in() && current_user_can( CN_Colaboradora::CAP ) && ! current_user_can( 'manage_options' );
	}

	public static function puede() {
		return is_user_logged_in() && CN_Colaboradora::puede();
	}

	/** true si la página que se está mostrando contiene el shortcode del panel. */
	public static function es_pagina_panel() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		return $post && has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	/** URL de la página que tiene el shortcode (se busca por contenido; no se crea ninguna página). '' si no existe. */
	public static function url_panel() {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT ID FROM ' . $wpdb->prefix . "posts WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
			'%' . $wpdb->esc_like( '[' . self::SHORTCODE ) . '%'
		) );
		if ( $id <= 0 ) {
			return '';
		}
		$url = get_permalink( $id );
		return $url ? (string) $url : '';
	}

	// ------------------------------------------------------------------ ganchos

	/** Antes de mostrar la página: noindex, sin caché y, si hay un POST del panel, procesarlo y redirigir. */
	public static function al_cargar_pagina() {
		if ( ! self::es_pagina_panel() ) {
			return;
		}
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		add_filter( 'wp_robots', array( __CLASS__, 'filtrar_robots' ) );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['cn_panel'] ) ) {
			return;
		}
		$r = self::ejecutar_post();
		if ( is_user_logged_in() && ! empty( $r['res'] ) ) {
			set_transient( self::clave_flash(), $r['res'], 120 );
		}
		wp_safe_redirect( self::url_vista( $r['vista'], $r['args'] ) );
		exit;
	}

	public static function filtrar_robots( $robots ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		unset( $robots['follow'], $robots['index'] );
		return $robots;
	}

	/** La colaboradora cae directo en el panel al iniciar sesión (si la página existe). */
	public static function redirigir_login( $url, $pedido, $usuario ) {
		if ( is_object( $usuario ) && ! is_wp_error( $usuario ) && user_can( $usuario, CN_Colaboradora::CAP ) && ! user_can( $usuario, 'manage_options' ) ) {
			$panel = self::url_panel();
			if ( '' !== $panel ) {
				return $panel;
			}
		}
		return $url;
	}

	/** Sin barra de administración para la colaboradora. */
	public static function filtrar_barra_admin( $mostrar ) {
		return self::es_colaboradora() ? false : $mostrar;
	}

	/** Si la colaboradora entra a wp-admin la mandamos al panel (salvo ajax / admin-post / cron). Si no hay panel, no hace nada. */
	public static function sacar_de_wp_admin() {
		if ( ! self::es_colaboradora() ) {
			return;
		}
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		$script = basename( (string) parse_url( (string) ( $_SERVER['PHP_SELF'] ?? '' ), PHP_URL_PATH ) );
		if ( in_array( $script, array( 'admin-ajax.php', 'admin-post.php' ), true ) ) {
			return;
		}
		$panel = self::url_panel();
		if ( '' === $panel ) {
			return;
		}
		wp_safe_redirect( $panel );
		exit;
	}

	// ------------------------------------------------------------------ URLs y mensajes

	protected static function base() {
		return (string) get_permalink( get_post() );
	}

	/** URL del panel en una vista (solo vistas conocidas; los args son enteros o claves cortas). */
	public static function url_vista( $vista, $args = array() ) {
		$vista = in_array( $vista, self::VISTAS, true ) ? $vista : 'inicio';
		$q     = array();
		if ( 'inicio' !== $vista ) {
			$q['v'] = $vista;
		}
		foreach ( (array) $args as $k => $v ) {
			if ( in_array( $k, array( 'curso', 'id', 'pag', 'que', 'filtro' ), true ) && '' !== (string) $v && 0 !== $v ) {
				$q[ $k ] = is_int( $v ) ? $v : sanitize_key( (string) $v );
			}
		}
		$base = self::base();
		return $q ? add_query_arg( $q, $base ) : $base;
	}

	protected static function clave_flash() {
		return 'cn_panel_flash_' . (int) get_current_user_id();
	}

	/** wp_nonce_field() devolviendo el HTML en vez de imprimirlo (sin el campo referer). */
	protected static function nonce_html( $accion, $nombre ) {
		ob_start();
		wp_nonce_field( $accion, $nombre, false );
		return ob_get_clean();
	}

	protected static function resultado( $ok, $texto ) {
		return array( 'tipo' => $ok ? 'success' : 'error', 'texto' => $texto );
	}

	// ------------------------------------------------------------------ escrituras (POST)

	/**
	 * Procesa el formulario enviado. NO redirige (eso lo hace al_cargar_pagina): devuelve
	 * array( 'vista' => vista a la que volver, 'args' => array, 'res' => array( 'tipo', 'texto' ) ).
	 */
	public static function ejecutar_post() {
		$vista = sanitize_key( wp_unslash( $_POST['cn_vista'] ?? 'inicio' ) );
		$vista = in_array( $vista, self::VISTAS, true ) ? $vista : 'inicio';
		$args  = array( 'curso' => absint( $_POST['cn_volver_curso'] ?? 0 ) );
		$vuelta = function ( $ok, $texto, $v = null, $a = null ) use ( $vista, $args ) {
			return array( 'vista' => null === $v ? $vista : $v, 'args' => null === $a ? $args : $a, 'res' => self::resultado( $ok, $texto ) );
		};

		if ( ! self::puede() ) {
			return $vuelta( false, 'Tu usuario no tiene acceso a este panel.', 'inicio', array() );
		}
		$accion = sanitize_key( wp_unslash( $_POST['cn_panel'] ?? '' ) );

		if ( 'colab' === $accion ) {
			if ( ! wp_verify_nonce( (string) wp_unslash( $_POST['cn_colab_nonce'] ?? '' ), 'cn_colab' ) ) {
				return $vuelta( false, 'La página se venció. Probá de nuevo.' );
			}
			// El formulario "Pegar un link" elige el tipo; se traduce a la acción que ya entiende CN_Colaboradora.
			if ( isset( $_POST['cn_colab_link'] ) ) {
				$sel = sanitize_key( wp_unslash( $_POST['cn_tipo_sel'] ?? '' ) );
				if ( 'yt' === $sel ) {
					$_POST['cn_colab_video'] = '1';
				} else {
					$_POST['cn_colab_contenido'] = '1';
					$_POST['cn_tipo']            = $sel;
				}
			}
			$r = CN_Colaboradora::procesar();
			if ( ! $r ) {
				return $vuelta( false, 'No se recibió nada para guardar.' );
			}
			return $vuelta( 'success' === $r['tipo'], $r['texto'] );
		}

		if ( 'editar' === $accion ) {
			$tabla = sanitize_key( wp_unslash( $_POST['cn_tabla'] ?? '' ) );
			$id    = absint( $_POST['cn_id'] ?? 0 );
			if ( ! wp_verify_nonce( (string) wp_unslash( $_POST['cn_panel_nonce'] ?? '' ), 'cn_panel_editar_' . $tabla . '_' . $id ) ) {
				return $vuelta( false, 'La página se venció. Probá de nuevo.', 'lista', array() );
			}
			$r = self::editar( $tabla, $id );
			return $vuelta( 'success' === $r['tipo'], $r['texto'], 'lista', array( 'que' => 'video' === $tabla ? 'videos' : 'contenidos' ) );
		}

		return $vuelta( false, 'No se recibió nada para guardar.' );
	}

	/** Entero entre 0 y 9999 desde un texto; null si no sirve. */
	protected static function orden_ok( $valor ) {
		$valor = trim( (string) $valor );
		if ( ! preg_match( '/^\d{1,4}$/', $valor ) ) {
			return null;
		}
		return (int) $valor;
	}

	protected static function curso_existe( $curso_id ) {
		global $wpdb;
		return $curso_id > 0 && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CN_DB::tabla( 'cursos' ) . ' WHERE id = %d', $curso_id ) ) > 0;
	}

	/** Backup de la fila previa en mp_log (sin migración). false si no se pudo guardar. */
	protected static function backup( $tabla, $fila, $cambios ) {
		global $wpdb;
		$ok = $wpdb->insert(
			CN_DB::tabla( 'mp_log' ),
			array(
				'payload' => wp_json_encode( array( 'tabla' => $tabla, 'id' => (int) $fila->id, 'usuario_id' => (int) get_current_user_id(), 'fila_previa' => $fila, 'cambios' => $cambios ) ),
				'tipo'    => 'contenido_backup',
				'fecha'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
		return false !== $ok;
	}

	/** Edita un contenido o un video. Capability y nonce ya fueron verificados por ejecutar_post(). */
	public static function editar( $tabla, $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( 'contenido' === $tabla ) {
			$t_real = CN_DB::tabla( 'contenidos' );
			$fila   = $wpdb->get_row( $wpdb->prepare( "SELECT id, curso_id, tipo, titulo, link, duracion, fuente, orden FROM {$t_real} WHERE id = %d", $id ) );
		} elseif ( 'video' === $tabla ) {
			$t_real = CN_DB::tabla( 'videos' );
			$fila   = $wpdb->get_row( $wpdb->prepare( "SELECT id, titulo, link_youtube, curso_id, orden FROM {$t_real} WHERE id = %d", $id ) );
		} else {
			return self::resultado( false, 'No se encontró eso para editar.' );
		}
		if ( ! $fila ) {
			return self::resultado( false, 'No se encontró eso para editar.' );
		}

		$orden = self::orden_ok( wp_unslash( $_POST['cn_orden'] ?? '' ) );
		if ( null === $orden ) {
			return self::resultado( false, 'El orden tiene que ser un número entre 0 y 9999.' );
		}
		$cambios  = array();
		$formatos = array();

		if ( 'contenido' === $tabla && 'drive_auto' === $fila->fuente ) {
			// La sincronización con Drive pisa título, tipo, link y curso: acá solo lo que no pisa.
			$duracion = mb_substr( sanitize_text_field( wp_unslash( $_POST['cn_duracion'] ?? '' ) ), 0, 20 );
			$nuevo    = array( 'orden' => $orden, 'duracion' => $duracion );
		} else {
			$titulo = mb_substr( trim( sanitize_text_field( wp_unslash( $_POST['cn_titulo'] ?? '' ) ) ), 0, 191 );
			if ( '' === $titulo ) {
				return self::resultado( false, 'Escribí un título.' );
			}
			$link = esc_url_raw( trim( (string) wp_unslash( $_POST['cn_link'] ?? '' ) ) );
			if ( strlen( $link ) > 500 ) {
				return self::resultado( false, 'El link es demasiado largo.' );
			}
			$curso_id = absint( $_POST['cn_curso_id'] ?? 0 );
			if ( 'contenido' === $tabla ) {
				$tipo = sanitize_key( wp_unslash( $_POST['cn_tipo'] ?? '' ) );
				if ( ! in_array( $tipo, array( 'video', 'pdf', 'audio' ), true ) ) {
					return self::resultado( false, 'Elegí el tipo: video, PDF o audio.' );
				}
				if ( ! CN_Colaboradora::link_ok( $link, array_merge( CN_Colaboradora::HOSTS_YOUTUBE, CN_Colaboradora::HOSTS_DRIVE ) ) ) {
					return self::resultado( false, 'El link tiene que ser de YouTube o de Google Drive.' );
				}
				if ( ! self::curso_existe( $curso_id ) ) {
					return self::resultado( false, 'Elegí un curso.' );
				}
				$nuevo = array(
					'titulo'   => $titulo,
					'link'     => $link,
					'tipo'     => $tipo,
					'curso_id' => $curso_id,
					'orden'    => $orden,
					'duracion' => mb_substr( sanitize_text_field( wp_unslash( $_POST['cn_duracion'] ?? '' ) ), 0, 20 ),
				);
			} else {
				if ( ! CN_Colaboradora::link_ok( $link, CN_Colaboradora::HOSTS_YOUTUBE ) ) {
					return self::resultado( false, 'El link tiene que ser de YouTube (youtube.com o youtu.be).' );
				}
				if ( $curso_id && ! self::curso_existe( $curso_id ) ) {
					return self::resultado( false, 'Ese curso no existe.' );
				}
				$nuevo = array( 'titulo' => $titulo, 'link_youtube' => $link, 'curso_id' => $curso_id ? $curso_id : null, 'orden' => $orden );
			}
		}

		$tipos_fmt = array( 'titulo' => '%s', 'link' => '%s', 'link_youtube' => '%s', 'tipo' => '%s', 'curso_id' => '%d', 'orden' => '%d', 'duracion' => '%s' );
		foreach ( $nuevo as $campo => $valor ) {
			$previo = $fila->$campo ?? null;
			if ( (string) $previo !== (string) $valor ) {
				$cambios[ $campo ]  = $valor;
				$formatos[ $campo ] = $tipos_fmt[ $campo ];
			}
		}
		if ( ! $cambios ) {
			return self::resultado( true, 'No había cambios para guardar.' );
		}
		if ( ! self::backup( $tabla, $fila, $cambios ) ) {
			return self::resultado( false, 'No se pudo guardar el respaldo, así que no se cambió nada. Probá de nuevo.' );
		}
		$r = $wpdb->update( $t_real, $cambios, array( 'id' => $id ), array_values( $formatos ), array( '%d' ) );
		if ( false === $r ) {
			return self::resultado( false, 'No se pudo guardar. Probá de nuevo.' );
		}
		return self::resultado( true, 'Cambios guardados.' );
	}

	// ------------------------------------------------------------------ pantallas

	public static function shortcode( $atts = array() ) {
		wp_enqueue_style( 'cn-style' );
		if ( ! is_user_logged_in() ) {
			return self::vista_login();
		}
		if ( ! CN_Colaboradora::puede() ) {
			return self::vista_sin_acceso();
		}
		return self::vista_panel();
	}

	protected static function estilos() {
		return '<style>
.cn-panel{max-width:560px !important}
.cn-panel .cn-p-card{display:block !important;background:var(--cn-card-bg) !important;border:1px solid var(--cn-borde) !important;border-radius:16px !important;padding:18px !important;margin:0 0 14px !important;color:var(--cn-texto) !important;text-decoration:none !important}
.cn-panel a.cn-p-card strong{display:block;font-family:Fraunces,Georgia,serif;font-size:19px;color:var(--cn-verde-botanico);margin-bottom:4px}
.cn-panel a.cn-p-card span{font-size:16px;color:var(--cn-muted)}
.cn-panel .cn-boton{display:flex !important;align-items:center !important;justify-content:center !important;min-height:48px !important;width:100% !important;margin:12px 0 0 !important;text-align:center !important;cursor:pointer}
.cn-panel .cn-boton--suave{background:transparent !important;color:var(--cn-verde-botanico) !important;border:2px solid var(--cn-verde-botanico) !important}
.cn-panel label{display:block !important;font-weight:600 !important;margin:14px 0 6px !important}
.cn-panel input[type=text],.cn-panel input[type=url],.cn-panel input[type=number],.cn-panel input[type=password],.cn-panel select{width:100% !important;min-height:48px !important;font-size:16px !important;padding:8px 12px !important;border:1px solid var(--cn-borde) !important;border-radius:10px !important;background:#fff !important}
.cn-panel .cn-p-nav{margin:0 0 14px !important}
.cn-panel .cn-p-nav a{display:inline-flex;align-items:center;min-height:48px;color:var(--cn-verde-botanico) !important;font-weight:600}
.cn-panel .cn-p-fila{border-top:1px solid var(--cn-borde);padding:12px 0}
.cn-panel .cn-p-fila small{display:block;color:var(--cn-muted);font-size:14px}
.cn-panel .cn-p-pasos{padding-left:20px !important;margin:0 0 6px !important}
.cn-panel .cn-p-pasos li{margin:0 0 8px !important}
.cn-panel .cn-p-chip{display:inline-block;background:var(--cn-track);border-radius:20px;padding:2px 10px;font-size:13px;margin-left:6px}
.cn-panel .cn-p-pie{margin-top:24px;text-align:center}
</style>';
	}

	protected static function vista_login() {
		$volver = self::base();
		ob_start();
		echo self::estilos(); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<div class="cn-wrap cn-panel">
			<h2 class="cn-form__titulo">Panel de contenido</h2>
			<p>Ingresá con tu usuario para cargar contenido del Club.</p>
			<?php
			wp_login_form( array(
				'echo'           => true,
				'redirect'       => $volver,
				'remember'       => true,
				'value_remember' => true,
				'label_username' => 'Usuario o email',
				'label_password' => 'Contraseña',
				'label_remember' => 'Recordarme',
				'label_log_in'   => 'Entrar',
			) );
			?>
			<p class="cn-p-pie"><a href="<?php echo esc_url( wp_lostpassword_url( $volver ) ); ?>">¿Olvidaste tu contraseña?</a></p>
		</div>
		<?php
		return ob_get_clean();
	}

	protected static function vista_sin_acceso() {
		ob_start();
		echo self::estilos(); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<div class="cn-wrap cn-panel">
			<div class="cn-alerta cn-alerta--error">Tu usuario no tiene acceso a este panel.</div>
			<a class="cn-boton cn-boton--suave" href="<?php echo esc_url( wp_logout_url( self::base() ) ); ?>">Cerrar sesión</a>
		</div>
		<?php
		return ob_get_clean();
	}

	protected static function cursos() {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT id, nombre, link_drive FROM ' . CN_DB::tabla( 'cursos' ) . ' ORDER BY orden ASC, id ASC' );
	}

	protected static function opciones_cursos( $cursos, $elegido, $vacio ) {
		$html = '' === $vacio ? '' : '<option value="0">' . esc_html( $vacio ) . '</option>';
		foreach ( $cursos as $c ) {
			$html .= '<option value="' . esc_attr( $c->id ) . '"' . ( (int) $elegido === (int) $c->id ? ' selected' : '' ) . '>' . esc_html( $c->nombre ) . '</option>';
		}
		return $html;
	}

	protected static function vista_panel() {
		$vista = sanitize_key( wp_unslash( $_GET['v'] ?? 'inicio' ) );
		$vista = in_array( $vista, self::VISTAS, true ) ? $vista : 'inicio';

		$aviso = get_transient( self::clave_flash() );
		if ( $aviso ) {
			delete_transient( self::clave_flash() );
		}

		ob_start();
		echo self::estilos(); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="cn-wrap cn-panel">';
		if ( 'inicio' !== $vista ) {
			echo '<p class="cn-p-nav"><a href="' . esc_url( self::url_vista( 'inicio' ) ) . '">← Volver al inicio</a></p>';
		}
		if ( is_array( $aviso ) && ! empty( $aviso['texto'] ) ) {
			$clase = 'success' === ( $aviso['tipo'] ?? '' ) ? 'success' : 'error';
			echo '<div class="cn-alerta cn-alerta--' . esc_attr( $clase ) . '" role="status">' . esc_html( $aviso['texto'] ) . '</div>';
		}
		switch ( $vista ) {
			case 'video':
				echo self::pantalla_video(); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'link':
				echo self::pantalla_link(); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'curso':
				echo self::pantalla_curso(); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'lista':
				echo self::pantalla_lista(); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'editar':
				echo self::pantalla_editar(); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			default:
				echo self::pantalla_inicio(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<p class="cn-p-pie"><a href="' . esc_url( wp_logout_url( self::base() ) ) . '">Cerrar sesión</a></p>';
		echo '</div>';
		return ob_get_clean();
	}

	/** Campos comunes de un formulario de alta: nonce, marca del panel y a dónde volver. */
	protected static function campos_colab( $vista, $curso = 0 ) {
		ob_start();
		wp_nonce_field( 'cn_colab', 'cn_colab_nonce', false );
		echo '<input type="hidden" name="cn_panel" value="colab">';
		echo '<input type="hidden" name="cn_vista" value="' . esc_attr( $vista ) . '">';
		if ( $curso ) {
			echo '<input type="hidden" name="cn_volver_curso" value="' . esc_attr( (int) $curso ) . '">';
		}
		return ob_get_clean();
	}

	protected static function pantalla_inicio() {
		$h  = '<h2 class="cn-form__titulo">¿Qué querés hacer?</h2>';
		$h .= '<a class="cn-p-card" href="' . esc_url( self::url_vista( 'video' ) ) . '"><strong>Subir un video grabado con mi celu</strong><span>Lo subís a la carpeta de Drive del curso y lo traés a la web.</span></a>';
		$h .= '<a class="cn-p-card" href="' . esc_url( self::url_vista( 'link' ) ) . '"><strong>Pegar un link (YouTube, Drive, PDF, audio)</strong><span>Para material que ya está subido.</span></a>';
		$h .= '<a class="cn-p-card" href="' . esc_url( self::url_vista( 'lista' ) ) . '"><strong>Ver y editar lo cargado</strong><span>Cambiar títulos, links, orden o curso.</span></a>';
		$h .= '<a class="cn-boton cn-boton--suave" href="' . esc_url( self::url_vista( 'curso' ) ) . '">Crear un curso nuevo</a>';
		return $h;
	}

	protected static function pantalla_video() {
		$cursos = self::cursos();
		$curso  = absint( $_GET['curso'] ?? 0 );
		$h      = '<h2 class="cn-form__titulo">Subir un video grabado con mi celu</h2>';
		$sel    = null;
		foreach ( $cursos as $c ) {
			if ( (int) $c->id === $curso ) {
				$sel = $c;
			}
		}
		if ( ! $sel ) {
			$h .= '<p>Primero elegí el curso:</p>';
			if ( ! $cursos ) {
				$h .= '<p>Todavía no hay cursos. <a href="' . esc_url( self::url_vista( 'curso' ) ) . '">Creá uno</a>.</p>';
			}
			foreach ( $cursos as $c ) {
				$h .= '<a class="cn-p-card" href="' . esc_url( self::url_vista( 'video', array( 'curso' => (int) $c->id ) ) ) . '"><strong>' . esc_html( $c->nombre ) . '</strong></a>';
			}
			return $h;
		}
		$h .= '<p><strong>Curso: ' . esc_html( $sel->nombre ) . '</strong> · <a href="' . esc_url( self::url_vista( 'video' ) ) . '">cambiar</a></p>';
		$h .= '<ol class="cn-p-pasos"><li>Tocá "Abrir carpeta de Drive".</li><li>Dentro de la carpeta tocá "+", después "Subir" y elegí el video de tu galería (mejor con wifi).</li><li>Cuando termine de subir, volvé acá y tocá "Ya lo subí: traerlo a la web".</li></ol>';
		if ( CN_Colaboradora::link_ok( (string) $sel->link_drive, CN_Colaboradora::HOSTS_DRIVE ) ) {
			$h .= '<a class="cn-boton cn-boton--suave" href="' . esc_url( $sel->link_drive ) . '" target="_blank" rel="noopener noreferrer">Abrir carpeta de Drive</a>';
		} else {
			$h .= '<div class="cn-alerta cn-alerta--error">Este curso no tiene una carpeta de Drive válida.</div>';
		}
		$h .= '<form method="post" action="' . esc_url( self::url_vista( 'video', array( 'curso' => (int) $sel->id ) ) ) . '">'
			. self::campos_colab( 'video', (int) $sel->id )
			. '<input type="hidden" name="cn_curso_id" value="' . esc_attr( (int) $sel->id ) . '">'
			. '<button type="submit" name="cn_colab_sync" value="1" class="cn-boton">Ya lo subí: traerlo a la web</button></form>';
		return $h;
	}

	protected static function pantalla_link() {
		$cursos = self::cursos();
		$h  = '<h2 class="cn-form__titulo">Pegar un link</h2>';
		$h .= '<form method="post" action="' . esc_url( self::url_vista( 'link' ) ) . '">' . self::campos_colab( 'link' );
		$h .= '<input type="hidden" name="cn_colab_link" value="1">';
		$h .= '<label for="cnp-tipo">¿Qué es?</label><select id="cnp-tipo" name="cn_tipo_sel">'
			. '<option value="yt">Video de YouTube (curso opcional)</option>'
			. '<option value="video">Video de un curso (YouTube o Drive)</option>'
			. '<option value="pdf">PDF (de Drive)</option>'
			. '<option value="audio">Audio (de Drive)</option></select>';
		$h .= '<label for="cnp-curso">Curso</label><select id="cnp-curso" name="cn_curso_id">' . self::opciones_cursos( $cursos, 0, '— Sin curso —' ) . '</select>';
		$h .= '<p><small>Los PDF, audios y videos de un curso necesitan curso. El video de YouTube puede ir sin curso.</small></p>';
		$h .= '<label for="cnp-titulo">Título</label><input id="cnp-titulo" type="text" name="cn_titulo" maxlength="191" required>';
		$h .= '<label for="cnp-link">Link (YouTube o Drive)</label><input id="cnp-link" type="url" name="cn_link" required placeholder="https://...">';
		$h .= '<label for="cnp-dur">Duración (opcional)</label><input id="cnp-dur" type="text" name="cn_duracion" maxlength="20" placeholder="12:30">';
		$h .= '<button type="submit" class="cn-boton">Agregar</button></form>';
		return $h;
	}

	protected static function pantalla_curso() {
		$h  = '<h2 class="cn-form__titulo">Crear un curso</h2>';
		$h .= '<form method="post" action="' . esc_url( self::url_vista( 'curso' ) ) . '">' . self::campos_colab( 'curso' );
		$h .= '<label for="cnp-nombre">Nombre del curso</label><input id="cnp-nombre" type="text" name="cn_nombre" maxlength="120" required>';
		$h .= '<label for="cnp-drive">Link de la carpeta de Drive</label><input id="cnp-drive" type="url" name="cn_link" required placeholder="https://drive.google.com/drive/folders/...">';
		$h .= '<button type="submit" name="cn_colab_curso" value="1" class="cn-boton">Crear curso</button></form>';
		return $h;
	}

	/** Campos ocultos para que un formulario GET conserve los parámetros del permalink (por si no son "lindos"). */
	protected static function ocultos_base() {
		$h = '';
		parse_str( (string) parse_url( self::base(), PHP_URL_QUERY ), $q );
		foreach ( (array) $q as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
			}
		}
		return $h;
	}

	protected static function pantalla_lista() {
		global $wpdb;
		$que    = 'videos' === sanitize_key( wp_unslash( $_GET['que'] ?? '' ) ) ? 'videos' : 'contenidos';
		$filtro = absint( $_GET['filtro'] ?? 0 );
		$pag    = max( 1, absint( $_GET['pag'] ?? 1 ) );
		$cursos = self::cursos();
		$tc     = CN_DB::tabla( 'cursos' );

		if ( 'videos' === $que ) {
			$t     = CN_DB::tabla( 'videos' );
			$donde = $filtro ? $wpdb->prepare( ' WHERE x.curso_id = %d', $filtro ) : '';
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} x{$donde}" );
			$sql   = $wpdb->prepare( "SELECT x.id, x.titulo, x.link_youtube AS link, x.orden, cu.nombre AS curso_nombre FROM {$t} x LEFT JOIN {$tc} cu ON cu.id = x.curso_id{$donde} ORDER BY x.id DESC LIMIT %d OFFSET %d", self::POR_PAGINA, ( $pag - 1 ) * self::POR_PAGINA );
		} else {
			$t     = CN_DB::tabla( 'contenidos' );
			$donde = $filtro ? $wpdb->prepare( ' WHERE x.curso_id = %d', $filtro ) : '';
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} x{$donde}" );
			$sql   = $wpdb->prepare( "SELECT x.id, x.titulo, x.tipo, x.fuente, x.orden, cu.nombre AS curso_nombre FROM {$t} x LEFT JOIN {$tc} cu ON cu.id = x.curso_id{$donde} ORDER BY x.id DESC LIMIT %d OFFSET %d", self::POR_PAGINA, ( $pag - 1 ) * self::POR_PAGINA );
		}
		$filas   = (array) $wpdb->get_results( $sql );
		$paginas = max( 1, (int) ceil( $total / self::POR_PAGINA ) );

		$h  = '<h2 class="cn-form__titulo">Lo cargado</h2>';
		$h .= '<p><a class="cn-boton' . ( 'contenidos' === $que ? '' : ' cn-boton--suave' ) . '" href="' . esc_url( self::url_vista( 'lista', array( 'que' => 'contenidos', 'filtro' => $filtro ) ) ) . '">Material de los cursos</a>';
		$h .= '<a class="cn-boton' . ( 'videos' === $que ? '' : ' cn-boton--suave' ) . '" href="' . esc_url( self::url_vista( 'lista', array( 'que' => 'videos', 'filtro' => $filtro ) ) ) . '">Videos de YouTube</a></p>';
		$h .= '<form method="get" action="' . esc_url( strtok( self::base(), '?' ) ) . '">' . self::ocultos_base()
			. '<input type="hidden" name="v" value="lista"><input type="hidden" name="que" value="' . esc_attr( $que ) . '">'
			. '<label for="cnp-filtro">Filtrar por curso</label><select id="cnp-filtro" name="filtro">' . self::opciones_cursos( $cursos, $filtro, 'Todos los cursos' ) . '</select>'
			. '<button type="submit" class="cn-boton cn-boton--suave">Filtrar</button></form>';

		if ( ! $filas ) {
			$h .= '<p>No hay nada cargado para mostrar.</p>';
		}
		foreach ( $filas as $f ) {
			$tabla = 'videos' === $que ? 'video' : 'contenido';
			$meta  = 'videos' === $que ? 'YouTube' : ucfirst( (string) $f->tipo );
			$meta .= ' · ' . ( $f->curso_nombre ? $f->curso_nombre : 'sin curso' ) . ' · orden ' . (int) $f->orden;
			$h    .= '<div class="cn-p-fila"><strong>' . esc_html( $f->titulo ) . '</strong>'
				. ( 'contenidos' === $que && 'drive_auto' === ( $f->fuente ?? '' ) ? '<span class="cn-p-chip">de Drive</span>' : '' )
				. '<small>' . esc_html( $meta ) . '</small>'
				. '<a class="cn-boton cn-boton--suave" href="' . esc_url( self::url_vista( 'editar', array( 'que' => $tabla, 'id' => (int) $f->id ) ) ) . '">Editar</a></div>';
		}
		if ( $paginas > 1 ) {
			$h .= '<p class="cn-p-pie">Página ' . (int) $pag . ' de ' . (int) $paginas . '</p>';
			if ( $pag > 1 ) {
				$h .= '<a class="cn-boton cn-boton--suave" href="' . esc_url( self::url_vista( 'lista', array( 'que' => $que, 'filtro' => $filtro, 'pag' => $pag - 1 ) ) ) . '">← Anteriores</a>';
			}
			if ( $pag < $paginas ) {
				$h .= '<a class="cn-boton cn-boton--suave" href="' . esc_url( self::url_vista( 'lista', array( 'que' => $que, 'filtro' => $filtro, 'pag' => $pag + 1 ) ) ) . '">Siguientes →</a>';
			}
		}
		return $h;
	}

	protected static function pantalla_editar() {
		global $wpdb;
		$tabla = 'video' === sanitize_key( wp_unslash( $_GET['que'] ?? '' ) ) ? 'video' : 'contenido';
		$id    = absint( $_GET['id'] ?? 0 );
		if ( 'video' === $tabla ) {
			$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT id, titulo, link_youtube, curso_id, orden FROM ' . CN_DB::tabla( 'videos' ) . ' WHERE id = %d', $id ) );
		} else {
			$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT id, curso_id, tipo, titulo, link, duracion, fuente, orden FROM ' . CN_DB::tabla( 'contenidos' ) . ' WHERE id = %d', $id ) );
		}
		if ( ! $fila ) {
			return '<div class="cn-alerta cn-alerta--error">No se encontró eso para editar.</div>';
		}
		$cursos = self::cursos();
		$auto   = 'contenido' === $tabla && 'drive_auto' === $fila->fuente;

		$h  = '<h2 class="cn-form__titulo">Editar</h2>';
		$h .= '<form method="post" action="' . esc_url( self::url_vista( 'editar', array( 'que' => $tabla, 'id' => (int) $fila->id ) ) ) . '">';
		$h .= self::nonce_html( 'cn_panel_editar_' . $tabla . '_' . (int) $fila->id, 'cn_panel_nonce' );
		$h .= '<input type="hidden" name="cn_panel" value="editar"><input type="hidden" name="cn_vista" value="lista">'
			. '<input type="hidden" name="cn_tabla" value="' . esc_attr( $tabla ) . '"><input type="hidden" name="cn_id" value="' . esc_attr( (int) $fila->id ) . '">';

		if ( $auto ) {
			$h .= '<div class="cn-alerta cn-alerta--error">Este material viene de Drive. El título, el link, el tipo y el curso se vuelven a escribir solos cada vez que sincronizás con Drive, así que acá no se pueden cambiar (se perderían). Para cambiar el título, renombrá el archivo en Drive y sincronizá de nuevo. Lo que sí podés cambiar acá es el orden y la duración.</div>';
			$h .= '<p><strong>' . esc_html( $fila->titulo ) . '</strong><br><small>' . esc_html( ucfirst( (string) $fila->tipo ) ) . '</small></p>';
		} else {
			$h .= '<label for="cnp-e-titulo">Título</label><input id="cnp-e-titulo" type="text" name="cn_titulo" maxlength="191" required value="' . esc_attr( $fila->titulo ) . '">';
			$h .= '<label for="cnp-e-link">Link (YouTube' . ( 'contenido' === $tabla ? ' o Drive' : '' ) . ')</label><input id="cnp-e-link" type="url" name="cn_link" required value="' . esc_attr( 'video' === $tabla ? $fila->link_youtube : $fila->link ) . '">';
			if ( 'contenido' === $tabla ) {
				$h .= '<label for="cnp-e-tipo">Tipo</label><select id="cnp-e-tipo" name="cn_tipo">';
				foreach ( array( 'video' => 'Video', 'pdf' => 'PDF', 'audio' => 'Audio' ) as $v => $n ) {
					$h .= '<option value="' . esc_attr( $v ) . '"' . ( $fila->tipo === $v ? ' selected' : '' ) . '>' . esc_html( $n ) . '</option>';
				}
				$h .= '</select>';
			}
			$h .= '<label for="cnp-e-curso">Curso</label><select id="cnp-e-curso" name="cn_curso_id">' . self::opciones_cursos( $cursos, (int) $fila->curso_id, 'contenido' === $tabla ? '' : '— Sin curso —' ) . '</select>';
		}
		$h .= '<label for="cnp-e-orden">Orden (0 a 9999)</label><input id="cnp-e-orden" type="number" inputmode="numeric" min="0" max="9999" name="cn_orden" required value="' . esc_attr( (int) $fila->orden ) . '">';
		if ( 'contenido' === $tabla ) {
			$h .= '<label for="cnp-e-dur">Duración (opcional)</label><input id="cnp-e-dur" type="text" name="cn_duracion" maxlength="20" value="' . esc_attr( (string) $fila->duracion ) . '">';
		}
		$h .= '<button type="submit" class="cn-boton">Guardar cambios</button></form>';
		return $h;
	}
}
