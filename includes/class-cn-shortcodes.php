<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CN_Shortcodes {

	/**
	 * Mensajes de resultado del POST. Se setean en procesar_formularios()
	 * (sobre template_redirect, con los headers todavía sin enviar) y los
	 * shortcodes los leen al renderizar más tarde, durante the_content.
	 */
	protected static $login_mensaje = '';
	protected static $login_tipo    = '';
	protected static $susc_mensaje  = '';

	// true cuando el login recién se resolvió con éxito en ESTE request (POST).
	// Se usa para limpiar la URL por JS y evitar el aviso de "reenviar formulario".
	protected static $login_recien  = false;

	public static function registrar() {
		add_shortcode( 'cn_login', array( __CLASS__, 'shortcode_login' ) );
		add_shortcode( 'cn_suscripcion', array( __CLASS__, 'shortcode_suscripcion' ) );
		add_shortcode( 'cn_boton_suscriptora', array( __CLASS__, 'shortcode_boton_suscriptora' ) );

		// El procesamiento de login/logout/suscripción DEBE correr antes de que
		// el theme empiece a imprimir HTML: setcookie() y wp_safe_redirect()
		// requieren que los headers HTTP todavía no se hayan enviado. Un
		// shortcode corre durante the_content, cuando el <head> y el body ya
		// salieron al navegador — ahí setcookie/redirect fallan y el exit
		// posterior deja el área de contenido en blanco. template_redirect
		// corre justo antes de cargar el template, con los headers aún abiertos.
		add_action( 'template_redirect', array( __CLASS__, 'procesar_formularios' ) );
	}

	/**
	 * Procesa los POST de login, logout y suscripción en el momento correcto
	 * del ciclo de request (headers sin enviar). Guarda el mensaje de resultado
	 * en las propiedades estáticas para que el shortcode correspondiente lo
	 * muestre al renderizar. En caso de éxito redirige y corta (patrón PRG).
	 */
	public static function procesar_formularios() {
		if ( is_admin() ) {
			return;
		}

		// Logout. Igual que el login: no redirigimos (evitamos la navegación que
		// la CDN cuelga en HTTP/3). La cookie ya se borró, así que el shortcode
		// renderiza el formulario de login directo en esta misma respuesta.
		if ( isset( $_POST['cn_logout'], $_POST['cn_logout_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cn_logout_nonce'] ) ), 'cn_logout' ) ) {
			CN_Auth::logout();
			self::$login_recien = true;
		}

		// Login.
		if ( isset( $_POST['cn_login_submit'] ) ) {
			if ( ! isset( $_POST['cn_login_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cn_login_nonce'] ) ), 'cn_login' ) ) {
				self::$login_mensaje = 'Hubo un problema, recargá la página e intentá de nuevo.';
				self::$login_tipo    = 'error';
			} else {
				$nombre  = isset( $_POST['cn_nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['cn_nombre'] ) ) : '';
				$celular = isset( $_POST['cn_celular'] ) ? sanitize_text_field( wp_unslash( $_POST['cn_celular'] ) ) : '';

				$resultado = CN_Auth::login( $nombre, $celular );

				if ( $resultado['ok'] ) {
					// Éxito. NO redirigimos (antes hacíamos PRG con wp_safe_redirect):
					// el redirect post-login es una navegación extra que la CDN de
					// Hostinger (HTTP/3 QUIC) cuelga en algunas redes móviles, dejando
					// la página en blanco hasta que la socia "vuelve" y recarga. La
					// cookie ya quedó seteada y en $_COOKIE (CN_Auth::crear_sesion),
					// así que dejamos seguir el request y el shortcode renderiza el
					// dashboard directo en la respuesta de este mismo POST — sin
					// ninguna navegación que se pueda colgar. La URL se limpia por JS
					// para que un F5 no dispare el "reenviar formulario".
					self::$login_recien = true;
				} else {
					// Solo en fallo: mostramos el motivo. (Antes esto no necesitaba
					// guard porque el éxito hacía exit; ahora que renderizamos en el
					// mismo request, hay que excluir explícitamente el caso ok.)
					switch ( $resultado['motivo'] ) {
						case 'bloqueado':
							self::$login_mensaje = 'Demasiados intentos. Probá de nuevo en 15 minutos.';
							break;
						case 'pausado':
							$wa = 'https://wa.me/' . CN_WA_SOPORTE;
							self::$login_mensaje = 'Tu suscripción está en pausa, contactanos por WhatsApp: <a href="' . esc_url( $wa ) . '" target="_blank" rel="noopener">' . esc_html( $wa ) . '</a>';
							break;
						default:
							self::$login_mensaje = 'Nombre o celular incorrecto. Revisá los datos e intentá de nuevo.';
					}
					self::$login_tipo = 'error';
				}
			}
		}

		// Suscripción (Mercado Pago) — mismo problema latente que el login:
		// el redirect al init_point de MP no puede salir desde el shortcode.
		if ( isset( $_POST['cn_suscripcion_submit'] ) ) {
			if ( ! isset( $_POST['cn_suscripcion_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cn_suscripcion_nonce'] ) ), 'cn_suscripcion' ) ) {
				self::$susc_mensaje = 'Hubo un problema, recargá la página e intentá de nuevo.';
			} else {
				$nombre  = isset( $_POST['cn_nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['cn_nombre'] ) ) : '';
				$celular = isset( $_POST['cn_celular'] ) ? sanitize_text_field( wp_unslash( $_POST['cn_celular'] ) ) : '';

				$norm = CN_Helpers::normalizar_celular( $celular );

				if ( '' === trim( $nombre ) || ! $norm['valido'] ) {
					self::$susc_mensaje = 'Revisá el nombre y el celular ingresados.';
				} else {
					$resultado = CN_MP::crear_preapproval( trim( $nombre ), $norm['normalizado'] );
					if ( $resultado['ok'] ) {
						wp_redirect( $resultado['init_point'] );
						exit;
					}
					self::$susc_mensaje = 'No pudimos iniciar la suscripción: ' . $resultado['error'];
				}
			}
		}
	}

	/**
	 * Botón grande "Ya soy suscriptora" para insertar en la página de ventas.
	 * Lleva directo a la página de login/dashboard ([cn_login]). Si la socia
	 * ya tiene sesión guardada en el dispositivo, entra directo sin volver a
	 * pedirle nada (la sesión dura 30 días, ver CN_Auth::SESSION_DIAS).
	 *
	 * Uso: [cn_boton_suscriptora texto="Ya soy suscriptora"]
	 */
	public static function shortcode_boton_suscriptora( $atts ) {
		wp_enqueue_style( 'cn-style' );

		$atts = shortcode_atts( array(
			'texto' => 'Ya soy suscriptora',
		), $atts, 'cn_boton_suscriptora' );

		$pagina_login = get_page_by_path( 'club-natureza-miembros', OBJECT, 'page' );
		$url          = $pagina_login instanceof WP_Post ? get_permalink( $pagina_login ) : home_url( '/club-natureza-miembros/' );

		return '<div class="cn-wrap cn-wrap--suelto"><a class="cn-boton cn-boton--suscriptora" href="' . esc_url( $url ) . '">' . esc_html( $atts['texto'] ) . '</a></div>';
	}

	public static function shortcode_login( $atts ) {
		wp_enqueue_style( 'cn-style' );
		wp_enqueue_script( 'cn-club' );

		// El POST (login/logout) ya se procesó en procesar_formularios(), sobre
		// template_redirect. Acá solo se renderiza según el estado actual.
		$mensaje      = self::$login_mensaje;
		$tipo_mensaje = self::$login_tipo;

		$miembro = CN_Auth::get_miembro_actual();

		// Resolvemos la vista antes de abrir el <div> para poder marcar el wrap
		// como "home" (dashboard) y aplicarle el fondo cálido del panel sin que
		// eso afecte al login ni a la página de un curso individual.
		$curso    = null;
		$es_home  = false;
		$pausada  = ( $miembro && 'pausado' === $miembro->estado );
		if ( $miembro && ! $pausada ) {
			$curso_id = isset( $_GET['cn_curso'] ) ? absint( $_GET['cn_curso'] ) : 0;
			$curso    = $curso_id ? self::get_curso( $curso_id ) : null;
			$es_home  = ! $curso;
		}

		$wrap_class = 'cn-wrap' . ( $es_home ? ' cn-wrap--home' : '' );

		ob_start();
		echo '<div class="' . esc_attr( $wrap_class ) . '"' . ( self::$login_recien ? ' data-cn-fresh-login="1"' : '' ) . '>';

		if ( $mensaje ) {
			echo '<div class="cn-alerta cn-alerta--' . esc_attr( $tipo_mensaje ) . '">' . wp_kses_post( $mensaje ) . '</div>';
		}

		if ( $pausada ) {
			$wa = 'https://wa.me/' . CN_WA_SOPORTE;
			echo '<div class="cn-alerta cn-alerta--error">Tu suscripción está en pausa, contactanos por WhatsApp: <a href="' . esc_url( $wa ) . '" target="_blank" rel="noopener">' . esc_html( $wa ) . '</a></div>';
		} elseif ( $miembro ) {
			self::render_nav( $miembro );

			if ( $curso ) {
				self::render_curso( $miembro, $curso );
			} else {
				self::render_home( $miembro );
			}
		} else {
			self::render_form_login();
		}

		echo '</div>';
		return ob_get_clean();
	}

	protected static function render_form_login() {
		?>
		<form class="cn-form" method="post">
			<h2 class="cn-form__titulo">Ingresá al Club Natureza</h2>
			<div class="cn-campo">
				<label for="cn_nombre">Nombre y Apellido</label>
				<input type="text" id="cn_nombre" name="cn_nombre" required autocomplete="name">
			</div>
			<div class="cn-campo">
				<label for="cn_celular">Celular</label>
				<input type="tel" id="cn_celular" name="cn_celular" inputmode="numeric" pattern="[0-9+ ]*" required autocomplete="tel">
			</div>
			<?php wp_nonce_field( 'cn_login', 'cn_login_nonce' ); ?>
			<button type="submit" name="cn_login_submit" value="1" class="cn-boton">Entrar</button>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * DATOS
	 * ------------------------------------------------------------------- */

	protected static function get_curso( $curso_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . CN_DB::tabla( 'cursos' ) . " WHERE id = %d", $curso_id ) );
	}

	protected static function get_cursos() {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM " . CN_DB::tabla( 'cursos' ) . " ORDER BY orden ASC, id ASC" );
	}

	protected static function get_contenidos_curso( $curso_id, $tipo = '' ) {
		global $wpdb;
		$tabla = CN_DB::tabla( 'contenidos' );
		if ( $tipo ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE curso_id = %d AND tipo = %s ORDER BY orden ASC, id ASC", $curso_id, $tipo ) );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE curso_id = %d ORDER BY tipo ASC, orden ASC, id ASC", $curso_id ) );
	}

	/**
	 * URL de portada de un curso = miniatura del primer video (por orden).
	 * Sirve tanto de Drive como de YouTube (ver CN_Helpers::video_thumbnail_url).
	 * Devuelve '' si el curso no tiene videos o no se puede resolver la miniatura;
	 * en ese caso el front cae al bloque de color del curso.
	 */
	protected static function portada_curso( $curso_id ) {
		global $wpdb;
		$tabla = CN_DB::tabla( 'contenidos' );
		$link  = $wpdb->get_var( $wpdb->prepare(
			"SELECT link FROM {$tabla} WHERE curso_id = %d AND tipo = 'video' ORDER BY orden ASC, id ASC LIMIT 1",
			$curso_id
		) );
		return $link ? CN_Helpers::video_thumbnail_url( $link ) : '';
	}

	protected static function contar_contenidos( $curso_id ) {
		global $wpdb;
		$tabla = CN_DB::tabla( 'contenidos' );
		$filas = $wpdb->get_results( $wpdb->prepare( "SELECT tipo, COUNT(*) AS total FROM {$tabla} WHERE curso_id = %d GROUP BY tipo", $curso_id ) );
		$conteo = array( 'video' => 0, 'pdf' => 0, 'audio' => 0 );
		foreach ( $filas as $fila ) {
			$conteo[ $fila->tipo ] = (int) $fila->total;
		}
		return $conteo;
	}

	protected static function url_curso( $curso_id, $extra = array() ) {
		return esc_url( add_query_arg( array_merge( array( 'cn_curso' => $curso_id ), $extra ), self::url_base() ) );
	}

	protected static function url_base() {
		return remove_query_arg( array( 'cn_curso', 'cn_ver' ) );
	}

	/* ---------------------------------------------------------------------
	 * NAV
	 * ------------------------------------------------------------------- */

	protected static function render_nav( $miembro ) {
		// Solo el primer nombre para el saludo (más cálido y corto en el header).
		$partes        = preg_split( '/\s+/', trim( (string) $miembro->nombre_apellido ) );
		$primer_nombre = $partes && '' !== $partes[0] ? $partes[0] : $miembro->nombre_apellido;
		$total_cursos  = count( self::get_cursos() );
		?>
		<header class="cn-top">
			<div class="cn-top__saludo">
				<p class="cn-top__eyebrow">Tu espacio de aprendizaje</p>
				<h1 class="cn-top__titulo">Hola de nuevo, <?php echo esc_html( $primer_nombre ); ?></h1>
			</div>
			<div class="cn-top__acciones">
				<div class="cn-pill">
					<span class="cn-pill__avatar" aria-hidden="true"><?php echo esc_html( CN_Helpers::iniciales( $miembro->nombre_apellido ) ); ?></span>
					<span class="cn-pill__texto">
						<span class="cn-pill__nombre">Club Natureza</span>
						<span class="cn-pill__sub"><?php echo esc_html( $total_cursos ); ?> curso<?php echo 1 === $total_cursos ? '' : 's'; ?> disponible<?php echo 1 === $total_cursos ? '' : 's'; ?></span>
					</span>
				</div>
				<form method="post" class="cn-logout">
					<?php wp_nonce_field( 'cn_logout', 'cn_logout_nonce' ); ?>
					<button type="submit" name="cn_logout" value="1" class="cn-logout__btn">Cerrar sesión</button>
				</form>
			</div>
		</header>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * HOME
	 * ------------------------------------------------------------------- */

	protected static function render_home( $miembro ) {
		$cursos = self::get_cursos();
		$ultimo = CN_Progreso::get_ultimo( $miembro->id );

		if ( $ultimo ) {
			$color_hero = $ultimo->curso_color ? $ultimo->curso_color : 'verde';
			$pct        = CN_Progreso::progreso_pct( $miembro->id, $ultimo->curso_id );
			// Clases que le faltan en ese curso (para el "quedan N clases").
			$conteo_hero = self::contar_contenidos( $ultimo->curso_id );
			$vistos_hero = count( CN_Progreso::get_vistos_ids( $miembro->id, $ultimo->curso_id ) );
			$quedan      = max( 0, (int) $conteo_hero['video'] - $vistos_hero );
			$portada     = self::portada_curso( $ultimo->curso_id );
			?>
			<section class="cn-seccion">
				<h2 class="cn-seccion__titulo">Seguí donde dejaste</h2>
				<div class="cn-hero cn-curso-color--<?php echo esc_attr( $color_hero ); ?>">
					<div class="cn-hero__cover<?php echo $portada ? ' cn-cover--foto' : ' cn-ph'; ?>"<?php echo $portada ? ' style="background-image:url(\'' . esc_url( $portada ) . '\');"' : ''; ?>>
						<span class="cn-hero__badge">EN CURSO</span>
					</div>
					<div class="cn-hero__cuerpo">
						<div>
							<h3 class="cn-hero__titulo"><?php echo esc_html( $ultimo->curso_nombre ); ?></h3>
							<p class="cn-hero__sub">Seguiste con · <span><?php echo esc_html( $ultimo->contenido_titulo ); ?></span></p>
						</div>
						<div class="cn-hero__fila">
							<div class="cn-hero__avance">
								<div class="cn-progressbar" role="progressbar" aria-valuenow="<?php echo esc_attr( $pct ); ?>" aria-valuemin="0" aria-valuemax="100">
									<div class="cn-progressbar__fill" style="width:<?php echo esc_attr( $pct ); ?>%;"></div>
								</div>
								<p class="cn-hero__meta"><?php echo esc_html( $pct ); ?>% completado<?php echo $quedan > 0 ? ' · quedan ' . esc_html( $quedan ) . ' clase' . ( 1 === $quedan ? '' : 's' ) : ''; ?></p>
							</div>
							<a class="cn-boton cn-hero__cta" href="<?php echo self::url_curso( $ultimo->curso_id, array( 'cn_ver' => $ultimo->contenido_id ) ); ?>#cn-reproductor">Continuar ▸</a>
						</div>
					</div>
				</div>
			</section>
			<?php
		} elseif ( $cursos ) {
			$primero    = $cursos[0];
			$color_hero = $primero->color ? $primero->color : 'verde';
			$portada    = self::portada_curso( $primero->id );
			?>
			<section class="cn-seccion">
				<div class="cn-hero cn-curso-color--<?php echo esc_attr( $color_hero ); ?>">
					<div class="cn-hero__cover<?php echo $portada ? ' cn-cover--foto' : ' cn-ph'; ?>"<?php echo $portada ? ' style="background-image:url(\'' . esc_url( $portada ) . '\');"' : ''; ?>>
						<span class="cn-hero__badge">EMPEZÁ ACÁ</span>
					</div>
					<div class="cn-hero__cuerpo">
						<div>
							<h3 class="cn-hero__titulo">¡Bienvenida al Club Natureza!</h3>
							<p class="cn-hero__sub">Empezá por · <span><?php echo esc_html( $primero->nombre ); ?></span></p>
						</div>
						<div class="cn-hero__fila">
							<div class="cn-hero__avance"></div>
							<a class="cn-boton cn-hero__cta" href="<?php echo self::url_curso( $primero->id ); ?>">Empezar ▸</a>
						</div>
					</div>
				</div>
			</section>
			<?php
		}
		?>
		<section class="cn-seccion" id="cn-tus-cursos">
			<div class="cn-seccion__head">
				<h2 class="cn-seccion__titulo">Tus cursos</h2>
				<?php if ( $cursos ) : ?>
					<span class="cn-seccion__count"><?php echo esc_html( count( $cursos ) ); ?> curso<?php echo 1 === count( $cursos ) ? '' : 's'; ?></span>
				<?php endif; ?>
			</div>
			<?php if ( $cursos ) : ?>
				<div class="cn-grid">
					<?php foreach ( $cursos as $curso ) :
						$conteo   = self::contar_contenidos( $curso->id );
						$color    = $curso->color ? $curso->color : 'verde';
						$pct      = CN_Progreso::progreso_pct( $miembro->id, $curso->id );
						$empezado = $pct > 0;
						$portada  = self::portada_curso( $curso->id );
						?>
						<div class="cn-card cn-card--curso cn-curso-color--<?php echo esc_attr( $color ); ?>">
							<div class="cn-card__cover<?php echo $portada ? ' cn-cover--foto' : ' cn-ph'; ?>"<?php echo $portada ? ' style="background-image:url(\'' . esc_url( $portada ) . '\');"' : ''; ?>>
								<?php if ( $empezado ) : ?><span class="cn-card__badge">EN CURSO</span><?php endif; ?>
							</div>
							<div class="cn-card__cuerpo">
								<h3 class="cn-card__titulo"><?php echo esc_html( $curso->nombre ); ?></h3>
								<div class="cn-card__tags">
									<?php if ( $conteo['video'] > 0 ) : ?>
										<span class="cn-tag">▶ <?php echo esc_html( $conteo['video'] ); ?> video<?php echo $conteo['video'] > 1 ? 's' : ''; ?></span>
									<?php endif; ?>
									<?php if ( $conteo['pdf'] > 0 ) : ?>
										<span class="cn-tag">📄 <?php echo esc_html( $conteo['pdf'] ); ?> PDF<?php echo $conteo['pdf'] > 1 ? 's' : ''; ?></span>
									<?php endif; ?>
									<?php if ( $conteo['audio'] > 0 ) : ?>
										<span class="cn-tag">♪ <?php echo esc_html( $conteo['audio'] ); ?> audio<?php echo $conteo['audio'] > 1 ? 's' : ''; ?></span>
									<?php endif; ?>
									<?php if ( 0 === $conteo['video'] + $conteo['pdf'] + $conteo['audio'] ) : ?>
										<span class="cn-tag cn-tag--vacio">Contenido próximamente</span>
									<?php endif; ?>
								</div>
								<div class="cn-card__pie">
									<?php if ( $empezado ) : ?>
										<div class="cn-card__avance">
											<div class="cn-progressbar" role="progressbar" aria-valuenow="<?php echo esc_attr( $pct ); ?>" aria-valuemin="0" aria-valuemax="100">
												<div class="cn-progressbar__fill" style="width:<?php echo esc_attr( $pct ); ?>%;"></div>
											</div>
											<p class="cn-card__meta"><?php echo esc_html( $pct ); ?>% completado</p>
										</div>
									<?php endif; ?>
									<a class="cn-boton" href="<?php echo self::url_curso( $curso->id ); ?>"><?php echo $empezado ? 'Continuar ▸' : 'Entrar al curso'; ?></a>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="cn-vacio">Todavía no hay cursos cargados.</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * CURSO INDIVIDUAL
	 * ------------------------------------------------------------------- */

	protected static function render_curso( $miembro, $curso ) {
		$color = $curso->color ? $curso->color : 'verde';

		$videos = self::get_contenidos_curso( $curso->id, 'video' );
		$pdfs   = self::get_contenidos_curso( $curso->id, 'pdf' );
		$audios = self::get_contenidos_curso( $curso->id, 'audio' );

		// Si vino un cn_ver válido para este curso, registrar apertura antes de listar vistos.
		$seleccionado_id = isset( $_GET['cn_ver'] ) ? absint( $_GET['cn_ver'] ) : 0;
		$video_seleccionado = null;
		if ( $seleccionado_id ) {
			foreach ( $videos as $video ) {
				if ( (int) $video->id === $seleccionado_id ) {
					$video_seleccionado = $video;
					break;
				}
			}
			if ( $video_seleccionado ) {
				CN_Progreso::registrar_apertura( $miembro->id, $curso->id, $video_seleccionado->id );
			}
		}

		$vistos_ids = CN_Progreso::get_vistos_ids( $miembro->id, $curso->id );
		?>
		<div class="cn-curso-header cn-curso-color--<?php echo esc_attr( $color ); ?>">
			<a class="cn-volver" href="<?php echo esc_url( self::url_base() . '#cn-tus-cursos' ); ?>">&larr; Volver a mis cursos</a>
			<h2 class="cn-curso-header__titulo"><?php echo esc_html( $curso->nombre ); ?></h2>
		</div>

		<section class="cn-seccion" id="cn-videos">
			<h3 class="cn-seccion__titulo">Videos</h3>

			<div class="cn-reproductor" id="cn-reproductor"<?php
				if ( $video_seleccionado ) {
					$thumb_sel = CN_Helpers::video_thumbnail_url( $video_seleccionado->link );
					if ( $thumb_sel ) {
						echo ' data-cn-thumb="' . esc_url( $thumb_sel ) . '"';
					}
				}
			?>>
				<?php if ( $video_seleccionado ) :
					$embed = CN_Helpers::embed_video_html( $video_seleccionado->link, $video_seleccionado->titulo );
					if ( $embed ) :
						// En desktop se muestra el reproductor embebido (anda bien). En
						// celular el player de Drive apila una barra de controles fija que
						// no se puede ocultar (iframe cross-origin), así que ahí se muestra
						// una tarjeta-póster con botón que abre el video en el visor de
						// Drive a pantalla completa (limpio). El toggle desktop/móvil es
						// por CSS (ver .cn-verdrive y la media query de cn-style.css).
						echo $embed;
						$thumb_verdrive = CN_Helpers::video_thumbnail_url( $video_seleccionado->link );
						// Para abrir sin que Drive pida elegir cuenta de Google, usamos el
						// visor /preview (anónimo, el mismo que reproduce sin login) en vez
						// de /view cuando es un archivo de Drive. YouTube abre con su link.
						$drive_file_id = CN_Helpers::extraer_drive_file_id( $video_seleccionado->link );
						$abrir_url = $drive_file_id ? 'https://drive.google.com/file/d/' . $drive_file_id . '/preview' : $video_seleccionado->link;
						?>
						<a class="cn-verdrive" href="<?php echo esc_url( $abrir_url ); ?>" target="_blank" rel="noopener"<?php if ( $thumb_verdrive ) : ?> style="background-image:url('<?php echo esc_url( $thumb_verdrive ); ?>');"<?php endif; ?>>
							<span class="cn-verdrive__play" aria-hidden="true"></span>
							<span class="cn-verdrive__texto">
								<strong><?php echo esc_html( $video_seleccionado->titulo ); ?></strong>
								<small>Tocá para ver en pantalla completa</small>
							</span>
						</a>
						<div class="cn-reproductor__barra">
							<p class="cn-reproductor__titulo"><?php echo esc_html( $video_seleccionado->titulo ); ?></p>
							<button type="button" class="cn-boton cn-boton--chico cn-fullscreen-btn" data-cn-fs aria-label="Ver en pantalla completa">
								<span aria-hidden="true">&#9974;</span> Pantalla completa
							</button>
						</div>
						<?php
					else :
						?>
						<div class="cn-reproductor__vacio">
							<p><?php echo esc_html( $video_seleccionado->titulo ); ?></p>
							<a class="cn-boton" href="<?php echo esc_url( $video_seleccionado->link ); ?>" target="_blank" rel="noopener">Ver esta clase</a>
						</div>
						<?php
					endif;
				else :
					?>
					<div class="cn-reproductor__vacio">Elegí una clase de la lista para empezar a mirar.</div>
					<?php
				endif; ?>
			</div>

			<?php if ( $videos ) : ?>
				<ul class="cn-galeria">
					<?php foreach ( $videos as $indice => $video ) :
						$visto  = in_array( (int) $video->id, $vistos_ids, true );
						$activa = $video_seleccionado && (int) $video_seleccionado->id === (int) $video->id;
						$clases = 'cn-clase' . ( $visto ? ' cn-clase--vista' : '' ) . ( $activa ? ' cn-clase--activa' : '' );
						$thumb  = CN_Helpers::video_thumbnail_url( $video->link );
						?>
						<li class="<?php echo esc_attr( $clases ); ?>">
							<a class="cn-clase__link" href="<?php echo self::url_curso( $curso->id, array( 'cn_ver' => $video->id ) ); ?>#cn-reproductor">
								<span class="cn-clase__thumb"<?php if ( $thumb ) : ?> style="background-image:url('<?php echo esc_url( $thumb ); ?>');"<?php endif; ?>>
									<span class="cn-clase__play" aria-hidden="true"></span>
									<span class="cn-clase__num" aria-hidden="true"><?php echo (int) ( $indice + 1 ); ?></span>
									<?php if ( $activa ) : ?><span class="cn-clase__reproduciendo">Reproduciendo</span><?php endif; ?>
									<?php if ( $visto ) : ?><span class="cn-clase__vista" aria-hidden="true">&#10003;</span><?php endif; ?>
								</span>
								<span class="cn-clase__pie">
									<span class="cn-clase__titulo"><?php echo esc_html( $video->titulo ); ?></span>
									<?php if ( $visto ) : ?><span class="cn-clase__estado">Vista</span><?php endif; ?>
								</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="cn-vacio">Todavía no hay videos cargados para este curso.</p>
			<?php endif; ?>
		</section>

		<section class="cn-seccion" id="cn-material">
			<h3 class="cn-seccion__titulo">Material descargable</h3>
			<?php if ( $pdfs ) : ?>
				<ul class="cn-lista-archivos">
					<?php foreach ( $pdfs as $pdf ) : ?>
						<li class="cn-archivo">
							<span class="cn-archivo__icono" aria-hidden="true">PDF</span>
							<span class="cn-archivo__nombre"><?php echo esc_html( $pdf->titulo ); ?></span>
							<a class="cn-boton cn-boton--chico" href="<?php echo esc_url( CN_Helpers::drive_download_url( $pdf->link ) ); ?>" target="_blank" rel="noopener">Descargar</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="cn-vacio">Todavía no hay material descargable para este curso.</p>
			<?php endif; ?>
		</section>

		<section class="cn-seccion" id="cn-inspirar">
			<h3 class="cn-seccion__titulo">Para inspirar</h3>
			<?php if ( $audios ) : ?>
				<ul class="cn-lista-audios">
					<?php foreach ( $audios as $audio ) :
						// "Para inspirar" reúne videos/audios motivacionales. Se embeben con
						// el reproductor de video (16:9) en vez del marco de audio de 90px,
						// para que un video (ej. de Drive) se vea completo y no recortado.
						$embed = CN_Helpers::embed_video_html( $audio->link, $audio->titulo );
						?>
						<li class="cn-audio">
							<div class="cn-audio__cabecera">
								<span class="cn-audio__nombre"><?php echo esc_html( $audio->titulo ); ?></span>
								<?php if ( $audio->duracion ) : ?><span class="cn-audio__duracion"><?php echo esc_html( $audio->duracion ); ?></span><?php endif; ?>
							</div>
							<?php if ( $embed ) : ?>
								<?php echo $embed; ?>
							<?php else : ?>
								<a class="cn-boton cn-boton--chico" href="<?php echo esc_url( $audio->link ); ?>" target="_blank" rel="noopener">Ver</a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="cn-vacio">Todavía no hay contenido para inspirar en este curso.</p>
			<?php endif; ?>
		</section>
		<?php
	}

	public static function shortcode_suscripcion( $atts ) {
		wp_enqueue_style( 'cn-style' );

		// El POST ya se procesó en procesar_formularios() (template_redirect).
		$mensaje = self::$susc_mensaje;

		ob_start();
		?>
		<div class="cn-wrap">
			<?php if ( $mensaje ) : ?>
				<div class="cn-alerta cn-alerta--error"><?php echo esc_html( $mensaje ); ?></div>
			<?php endif; ?>
			<form class="cn-form" method="post">
				<h2 class="cn-form__titulo">Sumate al Club Natureza</h2>
				<div class="cn-campo">
					<label for="cn_nombre">Nombre y Apellido</label>
					<input type="text" id="cn_nombre" name="cn_nombre" required autocomplete="name">
				</div>
				<div class="cn-campo">
					<label for="cn_celular">Celular</label>
					<input type="tel" id="cn_celular" name="cn_celular" inputmode="numeric" required autocomplete="tel">
				</div>
				<?php wp_nonce_field( 'cn_suscripcion', 'cn_suscripcion_nonce' ); ?>
				<button type="submit" name="cn_suscripcion_submit" value="1" class="cn-boton">Quiero sumarme</button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
