<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CN_Admin {

	public static function registrar_menu() {
		add_menu_page( 'Club Natureza', 'Club Natureza', 'manage_options', 'cn-socias', array( __CLASS__, 'pagina_socias' ), 'dashicons-groups', 58 );
		add_submenu_page( 'cn-socias', 'Socias', 'Socias', 'manage_options', 'cn-socias', array( __CLASS__, 'pagina_socias' ) );
		add_submenu_page( 'cn-socias', 'Cursos', 'Cursos', 'manage_options', 'cn-cursos', array( __CLASS__, 'pagina_cursos' ) );
		add_submenu_page( 'cn-socias', 'Contenido', 'Contenido', 'manage_options', 'cn-contenido', array( __CLASS__, 'pagina_contenido' ) );
		add_submenu_page( 'cn-socias', 'Videos', 'Videos', 'manage_options', 'cn-videos', array( __CLASS__, 'pagina_videos' ) );
		add_submenu_page( 'cn-socias', 'Config', 'Config', 'manage_options', 'cn-config', array( __CLASS__, 'pagina_config' ) );
	}

	protected static function aviso( $texto, $tipo = 'success' ) {
		echo '<div class="notice notice-' . esc_attr( $tipo ) . ' is-dismissible"><p>' . esc_html( $texto ) . '</p></div>';
	}

	/* ---------------------------------------------------------------------
	 * SOCIAS
	 * ------------------------------------------------------------------- */

	public static function pagina_socias() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		global $wpdb;
		$tabla = CN_DB::tabla( 'miembros' );

		// Alta rápida.
		if ( isset( $_POST['cn_admin_alta_socia'] ) && check_admin_referer( 'cn_alta_socia', 'cn_alta_socia_nonce' ) ) {
			$nombre  = sanitize_text_field( wp_unslash( $_POST['cn_nombre'] ?? '' ) );
			$celular = sanitize_text_field( wp_unslash( $_POST['cn_celular'] ?? '' ) );
			$norm    = CN_Helpers::normalizar_celular( $celular );

			if ( '' === trim( $nombre ) || '' === $norm['normalizado'] ) {
				self::aviso( 'Faltan datos para dar de alta a la socia.', 'error' );
			} else {
				$wpdb->insert(
					$tabla,
					array(
						'nombre_apellido' => trim( $nombre ),
						'celular_hash'    => CN_Helpers::hash_celular( $norm['normalizado'] ),
						'celular_hint'    => CN_Helpers::hint_celular( $norm['normalizado'] ),
						'estado'          => 'activo',
						'fecha_alta'      => current_time( 'mysql', true ),
					),
					array( '%s', '%s', '%s', '%s', '%s' )
				);
				if ( ! $norm['valido'] ) {
					self::aviso( 'Socia creada, pero el celular no matcheó el formato esperado (AR). Revisalo.', 'warning' );
				} else {
					self::aviso( 'Socia creada correctamente.' );
				}
			}
		}

		// Editar / notas.
		if ( isset( $_POST['cn_admin_editar_socia'] ) && check_admin_referer( 'cn_editar_socia', 'cn_editar_socia_nonce' ) ) {
			$id     = absint( $_POST['cn_id'] ?? 0 );
			$nombre = sanitize_text_field( wp_unslash( $_POST['cn_nombre'] ?? '' ) );
			$notas  = sanitize_textarea_field( wp_unslash( $_POST['cn_notas'] ?? '' ) );

			$wpdb->update(
				$tabla,
				array( 'nombre_apellido' => trim( $nombre ), 'notas' => $notas ),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			self::aviso( 'Socia actualizada.' );
		}

		// Acciones rápidas: pausar / reactivar / borrar.
		if ( isset( $_GET['cn_action'], $_GET['id'], $_GET['_wpnonce'] ) ) {
			$id = absint( $_GET['id'] );
			$accion = sanitize_key( $_GET['cn_action'] );

			if ( 'pausar' === $accion && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cn_pausar_' . $id ) ) {
				$wpdb->update( $tabla, array( 'estado' => 'pausado' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
				self::aviso( 'Socia pausada.' );
			} elseif ( 'reactivar' === $accion && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cn_reactivar_' . $id ) ) {
				$wpdb->update( $tabla, array( 'estado' => 'activo' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
				self::aviso( 'Socia reactivada.' );
			} elseif ( 'borrar' === $accion && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cn_borrar_' . $id ) ) {
				$wpdb->delete( $tabla, array( 'id' => $id ), array( '%d' ) );
				$wpdb->delete( CN_DB::tabla( 'sesiones' ), array( 'miembro_id' => $id ), array( '%d' ) );
				self::aviso( 'Socia borrada.' );
			}
		}

		$editar_id = isset( $_GET['editar'] ) ? absint( $_GET['editar'] ) : 0;
		$socia_editar = $editar_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d", $editar_id ) ) : null;

		$socias = $wpdb->get_results( "SELECT * FROM {$tabla} ORDER BY fecha_alta DESC" );
		?>
		<div class="wrap">
			<h1>Socias</h1>

			<?php if ( $socia_editar ) : ?>
				<h2>Editar socia</h2>
				<form method="post" style="max-width:480px;">
					<?php wp_nonce_field( 'cn_editar_socia', 'cn_editar_socia_nonce' ); ?>
					<input type="hidden" name="cn_id" value="<?php echo esc_attr( $socia_editar->id ); ?>">
					<table class="form-table">
						<tr>
							<th><label>Nombre y Apellido</label></th>
							<td><input type="text" name="cn_nombre" class="regular-text" value="<?php echo esc_attr( $socia_editar->nombre_apellido ); ?>" required></td>
						</tr>
						<tr>
							<th><label>Notas</label></th>
							<td><textarea name="cn_notas" class="large-text" rows="3"><?php echo esc_textarea( $socia_editar->notas ); ?></textarea></td>
						</tr>
					</table>
					<?php submit_button( 'Guardar cambios', 'primary', 'cn_admin_editar_socia' ); ?>
				</form>
				<hr>
			<?php endif; ?>

			<h2>Alta rápida</h2>
			<form method="post" style="max-width:480px;">
				<?php wp_nonce_field( 'cn_alta_socia', 'cn_alta_socia_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label>Nombre y Apellido</label></th>
						<td><input type="text" name="cn_nombre" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label>Celular</label></th>
						<td><input type="text" name="cn_celular" class="regular-text" required></td>
					</tr>
				</table>
				<?php submit_button( 'Dar de alta', 'primary', 'cn_admin_alta_socia' ); ?>
			</form>

			<h2>Listado</h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Nombre y Apellido</th>
						<th>Celular</th>
						<th>Estado</th>
						<th>Alta</th>
						<th>Acciones</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $socias ) : ?>
						<tr><td colspan="5">Todavía no hay socias cargadas.</td></tr>
					<?php endif; ?>
					<?php foreach ( $socias as $socia ) : ?>
						<tr>
							<td><?php echo esc_html( $socia->nombre_apellido ); ?></td>
							<td>****<?php echo esc_html( $socia->celular_hint ); ?></td>
							<td><?php echo 'activo' === $socia->estado ? '<span style="color:#2e7d32;">Activo</span>' : ( 'cancelada' === $socia->estado ? '<span style="color:#c62828;">Cancelada</span>' : '<span style="color:#c62828;">Pausado</span>' ); ?></td>
							<td><?php echo esc_html( mysql2date( 'd/m/Y', $socia->fecha_alta ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'cn-socias', 'editar' => $socia->id ) ) ); ?>">Editar</a> |
								<?php if ( 'activo' === $socia->estado ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'cn-socias', 'cn_action' => 'pausar', 'id' => $socia->id ) ), 'cn_pausar_' . $socia->id ) ); ?>">Pausar</a>
								<?php else : ?>
									<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'cn-socias', 'cn_action' => 'reactivar', 'id' => $socia->id ) ), 'cn_reactivar_' . $socia->id ) ); ?>">Reactivar</a>
								<?php endif; ?>
								|
								<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'cn-socias', 'cn_action' => 'borrar', 'id' => $socia->id ) ), 'cn_borrar_' . $socia->id ) ); ?>" onclick="return confirm('¿Borrar esta socia?');" style="color:#c62828;">Borrar</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * CURSOS
	 * ------------------------------------------------------------------- */

	public static function pagina_cursos() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		global $wpdb;
		$tabla = CN_DB::tabla( 'cursos' );

		if ( isset( $_POST['cn_admin_alta_curso'] ) && check_admin_referer( 'cn_alta_curso', 'cn_alta_curso_nonce' ) ) {
			$total_actual = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tabla}" );
			$wpdb->insert(
				$tabla,
				array(
					'nombre'     => sanitize_text_field( wp_unslash( $_POST['cn_nombre'] ?? '' ) ),
					'link_drive' => esc_url_raw( wp_unslash( $_POST['cn_link_drive'] ?? '' ) ),
					'orden'      => absint( $_POST['cn_orden'] ?? 0 ),
					'color'      => CN_Helpers::color_por_indice( $total_actual ),
				),
				array( '%s', '%s', '%d', '%s' )
			);
			self::aviso( 'Curso creado.' );
		}

		if ( isset( $_POST['cn_admin_editar_curso'] ) && check_admin_referer( 'cn_editar_curso', 'cn_editar_curso_nonce' ) ) {
			$id = absint( $_POST['cn_id'] ?? 0 );
			$wpdb->update(
				$tabla,
				array(
					'nombre'     => sanitize_text_field( wp_unslash( $_POST['cn_nombre'] ?? '' ) ),
					'link_drive' => esc_url_raw( wp_unslash( $_POST['cn_link_drive'] ?? '' ) ),
					'orden'      => absint( $_POST['cn_orden'] ?? 0 ),
					'color'      => sanitize_key( wp_unslash( $_POST['cn_color'] ?? 'verde' ) ),
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
			self::aviso( 'Curso actualizado.' );
		}

		if ( isset( $_GET['cn_action'], $_GET['id'], $_GET['_wpnonce'] ) ) {
			$id     = absint( $_GET['id'] );
			$accion = sanitize_key( $_GET['cn_action'] );

			if ( 'borrar_curso' === $accion && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cn_borrar_curso_' . $id ) ) {
				$wpdb->delete( $tabla, array( 'id' => $id ), array( '%d' ) );
				$wpdb->delete( CN_DB::tabla( 'contenidos' ), array( 'curso_id' => $id ), array( '%d' ) );
				self::aviso( 'Curso borrado.' );
			} elseif ( 'sync_drive' === $accion && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cn_sync_drive_' . $id ) ) {
				$resultado = CN_Drive::sincronizar_curso( $id );
				if ( $resultado['ok'] ) {
					self::aviso( sprintf( 'Sincronizado con Drive: %d nuevo(s), %d actualizado(s), %d ignorado(s) (no son video/PDF/audio).', $resultado['agregados'], $resultado['actualizados'], $resultado['ignorados'] ) );
				} else {
					self::aviso( 'No se pudo sincronizar con Drive: ' . $resultado['error'], 'error' );
				}
			}
		}

		$editar_id = isset( $_GET['editar'] ) ? absint( $_GET['editar'] ) : 0;
		$curso_editar = $editar_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d", $editar_id ) ) : null;

		$cursos = $wpdb->get_results( "SELECT * FROM {$tabla} ORDER BY orden ASC, id ASC" );
		$paleta = CN_Helpers::paleta_cursos();
		?>
		<div class="wrap">
			<h1>Cursos</h1>
			<p>Pegá el link de la <strong>carpeta</strong> de Google Drive del curso (no un archivo suelto). Después usá "Sincronizar con Drive" para traer automáticamente los videos, PDFs y audios que haya adentro — o cargalos a mano desde <a href="<?php echo esc_url( admin_url( 'admin.php?page=cn-contenido' ) ); ?>">Contenido</a> si la sincronización todavía no está lista.</p>

			<?php if ( $curso_editar ) : ?>
				<h2>Editar curso</h2>
				<form method="post" style="max-width:480px;">
					<?php wp_nonce_field( 'cn_editar_curso', 'cn_editar_curso_nonce' ); ?>
					<input type="hidden" name="cn_id" value="<?php echo esc_attr( $curso_editar->id ); ?>">
					<table class="form-table">
						<tr><th><label>Nombre</label></th><td><input type="text" name="cn_nombre" class="regular-text" value="<?php echo esc_attr( $curso_editar->nombre ); ?>" required></td></tr>
						<tr><th><label>Link Drive (carpeta)</label></th><td><input type="url" name="cn_link_drive" class="regular-text" value="<?php echo esc_attr( $curso_editar->link_drive ); ?>" required></td></tr>
						<tr><th><label>Orden</label></th><td><input type="number" name="cn_orden" value="<?php echo esc_attr( $curso_editar->orden ); ?>"></td></tr>
						<tr>
							<th><label>Color</label></th>
							<td>
								<select name="cn_color">
									<?php foreach ( $paleta as $color ) : ?>
										<option value="<?php echo esc_attr( $color ); ?>" <?php selected( $curso_editar->color, $color ); ?>><?php echo esc_html( ucfirst( $color ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
					<?php submit_button( 'Guardar cambios', 'primary', 'cn_admin_editar_curso' ); ?>
				</form>
				<hr>
			<?php endif; ?>

			<h2>Alta rápida</h2>
			<form method="post" style="max-width:480px;">
				<?php wp_nonce_field( 'cn_alta_curso', 'cn_alta_curso_nonce' ); ?>
				<table class="form-table">
					<tr><th><label>Nombre</label></th><td><input type="text" name="cn_nombre" class="regular-text" required></td></tr>
					<tr><th><label>Link Drive (carpeta)</label></th><td><input type="url" name="cn_link_drive" class="regular-text" required placeholder="https://drive.google.com/drive/folders/..."></td></tr>
					<tr><th><label>Orden</label></th><td><input type="number" name="cn_orden" value="0"></td></tr>
				</table>
				<?php submit_button( 'Crear curso', 'primary', 'cn_admin_alta_curso' ); ?>
			</form>

			<h2>Listado</h2>
			<table class="widefat striped">
				<thead><tr><th>Nombre</th><th>Color</th><th>Link</th><th>Orden</th><th>Última sinc. Drive</th><th>Acciones</th></tr></thead>
				<tbody>
					<?php if ( ! $cursos ) : ?>
						<tr><td colspan="6">Todavía no hay cursos cargados.</td></tr>
					<?php endif; ?>
					<?php foreach ( $cursos as $curso ) : ?>
						<tr>
							<td><?php echo esc_html( $curso->nombre ); ?></td>
							<td><?php echo esc_html( ucfirst( $curso->color ? $curso->color : '—' ) ); ?></td>
							<td><a href="<?php echo esc_url( $curso->link_drive ); ?>" target="_blank" rel="noopener">Ver carpeta</a></td>
							<td><?php echo esc_html( $curso->orden ); ?></td>
							<td><?php echo $curso->drive_synced_at ? esc_html( mysql2date( 'd/m/Y H:i', $curso->drive_synced_at ) ) : '— nunca —'; ?></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'cn-cursos', 'editar' => $curso->id ) ) ); ?>">Editar</a> |
								<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'cn-cursos', 'cn_action' => 'sync_drive', 'id' => $curso->id ) ), 'cn_sync_drive_' . $curso->id ) ); ?>">Sincronizar con Drive</a> |
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=cn-contenido&curso_id=' . $curso->id ) ); ?>">Ver contenido</a> |
								<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'cn-cursos', 'cn_action' => 'borrar_curso', 'id' => $curso->id ) ), 'cn_borrar_curso_' . $curso->id ) ); ?>" onclick="return confirm('¿Borrar este curso y todo su contenido cargado?');" style="color:#c62828;">Borrar</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * CONTENIDO (videos / PDFs / audios por curso — fallback manual + Drive)
	 * ------------------------------------------------------------------- */

	public static function pagina_contenido() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		global $wpdb;
		$tabla        = CN_DB::tabla( 'contenidos' );
		$tabla_cursos = CN_DB::tabla( 'cursos' );

		if ( isset( $_POST['cn_admin_alta_contenido'] ) && check_admin_referer( 'cn_alta_contenido', 'cn_alta_contenido_nonce' ) ) {
			$curso_id = absint( $_POST['cn_curso_id'] ?? 0 );
			$tipo     = sanitize_key( wp_unslash( $_POST['cn_tipo'] ?? '' ) );

			if ( ! $curso_id || ! in_array( $tipo, array( 'video', 'pdf', 'audio' ), true ) ) {
				self::aviso( 'Elegí un curso y un tipo de contenido válidos.', 'error' );
			} else {
				$wpdb->insert(
					$tabla,
					array(
						'curso_id'   => $curso_id,
						'tipo'       => $tipo,
						'titulo'     => sanitize_text_field( wp_unslash( $_POST['cn_titulo'] ?? '' ) ),
						'link'       => esc_url_raw( wp_unslash( $_POST['cn_link'] ?? '' ) ),
						'duracion'   => sanitize_text_field( wp_unslash( $_POST['cn_duracion'] ?? '' ) ),
						'fuente'     => 'manual',
						'orden'      => absint( $_POST['cn_orden'] ?? 0 ),
						'fecha_alta' => current_time( 'mysql', true ),
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
				);
				self::aviso( 'Contenido agregado.' );
			}
		}

		if ( isset( $_GET['cn_action'], $_GET['id'], $_GET['_wpnonce'] ) && 'borrar_contenido' === $_GET['cn_action'] ) {
			$id = absint( $_GET['id'] );
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cn_borrar_contenido_' . $id ) ) {
				$wpdb->delete( $tabla, array( 'id' => $id ), array( '%d' ) );
				self::aviso( 'Contenido borrado.' );
			}
		}

		$curso_filtro = isset( $_GET['curso_id'] ) ? absint( $_GET['curso_id'] ) : 0;
		$cursos = $wpdb->get_results( "SELECT id, nombre FROM {$tabla_cursos} ORDER BY orden ASC" );

		$where = $curso_filtro ? $wpdb->prepare( 'WHERE c.curso_id = %d', $curso_filtro ) : '';
		$contenidos = $wpdb->get_results( "SELECT c.*, cu.nombre AS curso_nombre FROM {$tabla} c LEFT JOIN {$tabla_cursos} cu ON cu.id = c.curso_id {$where} ORDER BY cu.orden ASC, c.tipo ASC, c.orden ASC, c.id ASC" );
		?>
		<div class="wrap">
			<h1>Contenido de los cursos</h1>
			<p>Esta pantalla es el <strong>fallback manual</strong>: cargá acá videos, PDFs o audios sueltos cuando la sincronización automática con Drive (en Cursos) todavía no esté configurada, o para corregir algo puntual.</p>

			<h2>Agregar contenido</h2>
			<form method="post" style="max-width:520px;">
				<?php wp_nonce_field( 'cn_alta_contenido', 'cn_alta_contenido_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label>Curso</label></th>
						<td>
							<select name="cn_curso_id" required>
								<option value="">— Elegir curso —</option>
								<?php foreach ( $cursos as $curso ) : ?>
									<option value="<?php echo esc_attr( $curso->id ); ?>" <?php selected( $curso_filtro, $curso->id ); ?>><?php echo esc_html( $curso->nombre ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label>Tipo</label></th>
						<td>
							<select name="cn_tipo" required>
								<option value="video">Video</option>
								<option value="pdf">PDF</option>
								<option value="audio">Audio</option>
							</select>
						</td>
					</tr>
					<tr><th><label>Título</label></th><td><input type="text" name="cn_titulo" class="regular-text" required></td></tr>
					<tr><th><label>Link</label></th><td><input type="url" name="cn_link" class="regular-text" required placeholder="Link de YouTube o de Drive"></td></tr>
					<tr><th><label>Duración (opcional, solo audio)</label></th><td><input type="text" name="cn_duracion" placeholder="ej: 12:30"></td></tr>
					<tr><th><label>Orden</label></th><td><input type="number" name="cn_orden" value="0"></td></tr>
				</table>
				<?php submit_button( 'Agregar contenido', 'primary', 'cn_admin_alta_contenido' ); ?>
			</form>

			<h2>Listado <?php echo $curso_filtro ? '(filtrado)' : ''; ?></h2>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cn-contenido' ) ); ?>">Ver todos los cursos</a>
				<?php foreach ( $cursos as $curso ) : ?>
					· <a href="<?php echo esc_url( admin_url( 'admin.php?page=cn-contenido&curso_id=' . $curso->id ) ); ?>"><?php echo esc_html( $curso->nombre ); ?></a>
				<?php endforeach; ?>
			</p>
			<table class="widefat striped">
				<thead><tr><th>Curso</th><th>Tipo</th><th>Título</th><th>Fuente</th><th>Duración</th><th>Orden</th><th>Acciones</th></tr></thead>
				<tbody>
					<?php if ( ! $contenidos ) : ?>
						<tr><td colspan="7">Todavía no hay contenido cargado.</td></tr>
					<?php endif; ?>
					<?php foreach ( $contenidos as $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item->curso_nombre ? $item->curso_nombre : '—' ); ?></td>
							<td><?php echo esc_html( ucfirst( $item->tipo ) ); ?></td>
							<td><a href="<?php echo esc_url( $item->link ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item->titulo ); ?></a></td>
							<td><?php echo 'drive_auto' === $item->fuente ? 'Drive (auto)' : 'Manual'; ?></td>
							<td><?php echo esc_html( $item->duracion ? $item->duracion : '—' ); ?></td>
							<td><?php echo esc_html( $item->orden ); ?></td>
							<td><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'cn-contenido', 'cn_action' => 'borrar_contenido', 'id' => $item->id ) ), 'cn_borrar_contenido_' . $item->id ) ); ?>" onclick="return confirm('¿Borrar este contenido?');" style="color:#c62828;">Borrar</a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * VIDEOS
	 * ------------------------------------------------------------------- */

	public static function pagina_videos() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		global $wpdb;
		$tabla       = CN_DB::tabla( 'videos' );
		$tabla_cursos = CN_DB::tabla( 'cursos' );

		if ( isset( $_POST['cn_admin_alta_video'] ) && check_admin_referer( 'cn_alta_video', 'cn_alta_video_nonce' ) ) {
			$curso_id = absint( $_POST['cn_curso_id'] ?? 0 );
			$wpdb->insert(
				$tabla,
				array(
					'titulo'       => sanitize_text_field( wp_unslash( $_POST['cn_titulo'] ?? '' ) ),
					'link_youtube' => esc_url_raw( wp_unslash( $_POST['cn_link_youtube'] ?? '' ) ),
					'curso_id'     => $curso_id ? $curso_id : null,
					'orden'        => absint( $_POST['cn_orden'] ?? 0 ),
				),
				array( '%s', '%s', '%d', '%d' )
			);
			self::aviso( 'Video creado.' );
		}

		if ( isset( $_GET['cn_action'], $_GET['id'], $_GET['_wpnonce'] ) && 'borrar_video' === $_GET['cn_action'] ) {
			$id = absint( $_GET['id'] );
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cn_borrar_video_' . $id ) ) {
				$wpdb->delete( $tabla, array( 'id' => $id ), array( '%d' ) );
				self::aviso( 'Video borrado.' );
			}
		}

		$cursos = $wpdb->get_results( "SELECT id, nombre FROM {$tabla_cursos} ORDER BY orden ASC" );
		$videos = $wpdb->get_results( "SELECT v.*, c.nombre AS curso_nombre FROM {$tabla} v LEFT JOIN {$tabla_cursos} c ON c.id = v.curso_id ORDER BY v.orden ASC, v.id ASC" );
		?>
		<div class="wrap">
			<h1>Videos</h1>

			<h2>Alta rápida</h2>
			<form method="post" style="max-width:480px;">
				<?php wp_nonce_field( 'cn_alta_video', 'cn_alta_video_nonce' ); ?>
				<table class="form-table">
					<tr><th><label>Título</label></th><td><input type="text" name="cn_titulo" class="regular-text" required></td></tr>
					<tr><th><label>Link YouTube</label></th><td><input type="url" name="cn_link_youtube" class="regular-text" required></td></tr>
					<tr>
						<th><label>Curso (opcional)</label></th>
						<td>
							<select name="cn_curso_id">
								<option value="0">— Sin curso —</option>
								<?php foreach ( $cursos as $curso ) : ?>
									<option value="<?php echo esc_attr( $curso->id ); ?>"><?php echo esc_html( $curso->nombre ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr><th><label>Orden</label></th><td><input type="number" name="cn_orden" value="0"></td></tr>
				</table>
				<?php submit_button( 'Crear video', 'primary', 'cn_admin_alta_video' ); ?>
			</form>

			<h2>Listado</h2>
			<table class="widefat striped">
				<thead><tr><th>Título</th><th>Curso</th><th>Orden</th><th>Acciones</th></tr></thead>
				<tbody>
					<?php if ( ! $videos ) : ?>
						<tr><td colspan="4">Todavía no hay videos cargados.</td></tr>
					<?php endif; ?>
					<?php foreach ( $videos as $video ) : ?>
						<tr>
							<td><?php echo esc_html( $video->titulo ); ?></td>
							<td><?php echo esc_html( $video->curso_nombre ? $video->curso_nombre : '—' ); ?></td>
							<td><?php echo esc_html( $video->orden ); ?></td>
							<td><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'cn-videos', 'cn_action' => 'borrar_video', 'id' => $video->id ) ), 'cn_borrar_video_' . $video->id ) ); ?>" onclick="return confirm('¿Borrar este video?');" style="color:#c62828;">Borrar</a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * CONFIG
	 * ------------------------------------------------------------------- */

	public static function pagina_config() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		if ( isset( $_POST['cn_admin_guardar_config'] ) && check_admin_referer( 'cn_config', 'cn_config_nonce' ) ) {
			update_option( 'cn_mp_access_token', sanitize_text_field( wp_unslash( $_POST['cn_mp_access_token'] ?? '' ) ) );
			update_option( 'cn_mp_precio_mensual', (float) sanitize_text_field( wp_unslash( $_POST['cn_mp_precio_mensual'] ?? '0' ) ) );
			update_option( 'cn_mp_razon_plan', sanitize_text_field( wp_unslash( $_POST['cn_mp_razon_plan'] ?? '' ) ) );
			update_option( 'cn_google_drive_api_key', sanitize_text_field( wp_unslash( $_POST['cn_google_drive_api_key'] ?? '' ) ) );
			self::aviso( 'Configuración guardada.' );
		}

		$token       = CN_MP::get_access_token();
		$precio      = CN_MP::get_precio_mensual();
		$razon       = CN_MP::get_razon_plan();
		$drive_key   = CN_Drive::get_api_key();
		?>
		<div class="wrap">
			<h1>Config</h1>
			<form method="post" style="max-width:600px;">
				<?php wp_nonce_field( 'cn_config', 'cn_config_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label>Access Token de Mercado Pago (producción)</label></th>
						<td><input type="text" name="cn_mp_access_token" class="regular-text" value="<?php echo esc_attr( $token ); ?>"></td>
					</tr>
					<tr>
						<th><label>Precio mensual (ARS)</label></th>
						<td><input type="number" step="0.01" name="cn_mp_precio_mensual" value="<?php echo esc_attr( $precio ); ?>"></td>
					</tr>
					<tr>
						<th><label>Razón / título del plan</label></th>
						<td><input type="text" name="cn_mp_razon_plan" class="regular-text" value="<?php echo esc_attr( $razon ); ?>"></td>
					</tr>
					<tr>
						<th><label>API key de Google Drive</label></th>
						<td>
							<input type="text" name="cn_google_drive_api_key" class="regular-text" value="<?php echo esc_attr( $drive_key ); ?>">
							<p class="description">
								Para sincronizar automáticamente el contenido de las carpetas de Drive de cada curso.
								Se genera en <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a>
								(habilitar "Google Drive API" → Credenciales → Crear API key). Cada carpeta de curso debe estar compartida
								como "Cualquier persona con el enlace — Lector". Si no se configura, el contenido se puede seguir cargando
								a mano desde <a href="<?php echo esc_url( admin_url( 'admin.php?page=cn-contenido' ) ); ?>">Contenido</a>.
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Guardar configuración', 'primary', 'cn_admin_guardar_config' ); ?>
			</form>

			<h2>Webhook de Mercado Pago</h2>
			<p>Pegá esta URL en el panel de Mercado Pago → Tu aplicación → Webhooks:</p>
			<input type="text" readonly class="regular-text" style="width:100%;max-width:600px;" onclick="this.select();" value="<?php echo esc_url( CN_MP::webhook_url() ); ?>">
		</div>
		<?php
	}
}
