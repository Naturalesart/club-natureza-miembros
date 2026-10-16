<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class CN_Admin {
	public static function registrar_menu() {
		add_menu_page( 'Club Natureza', 'Club Natureza', 'manage_options', 'cn-socias', array( __CLASS__, 'pagina_socias' ), 'dashicons-groups', 58 );
		add_submenu_page( 'cn-socias', 'Socias', 'Socias', 'manage_options', 'cn-socias', array( __CLASS__, 'pagina_socias' ) );
		add_submenu_page( 'cn-socias', 'Cruce con Mercado Pago', 'Cruce con Mercado Pago', 'manage_options', 'cn-cruce-mp', array( __CLASS__, 'pagina_cruce_mp' ) );
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
	/**
	 * Procesa el formulario "Generar link de suscripción" (Fase 2C). Solo genera el link: no se envía a nadie.
	 * Devuelve null si no se envió el formulario; si se envió, array( 'ok' => bool, 'mensaje' => string, 'link' => string ).
	 * El celular se normaliza UNA sola vez acá; CN_MP::crear_suscripcion_link() no lo vuelve a normalizar.
	 */
	protected static function procesar_generar_link() {
		if ( ! isset( $_POST['cn_admin_generar_link'] ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'ok' => false, 'mensaje' => 'No tenés permisos para esto.', 'link' => '' );
		}
		check_admin_referer( 'cn_generar_link', 'cn_generar_link_nonce' );
		$planes  = CN_MP::get_planes();
		$plan    = sanitize_text_field( wp_unslash( $_POST['cn_gl_plan'] ?? '' ) );
		$nombre  = trim( sanitize_text_field( wp_unslash( $_POST['cn_gl_nombre'] ?? '' ) ) );
		$celular = sanitize_text_field( wp_unslash( $_POST['cn_gl_celular'] ?? '' ) );
		$email   = sanitize_text_field( wp_unslash( $_POST['cn_gl_email'] ?? '' ) );
		if ( ! isset( $planes[ $plan ] ) ) {
			return array( 'ok' => false, 'mensaje' => 'Elegí un plan válido.', 'link' => '' );
		}
		if ( '' === $nombre ) {
			return array( 'ok' => false, 'mensaje' => 'Ingresá nombre y apellido.', 'link' => '' );
		}
		$norm = CN_Helpers::normalizar_celular( $celular );
		if ( empty( $norm['valido'] ) || '' === (string) $norm['normalizado'] ) {
			return array( 'ok' => false, 'mensaje' => 'El celular no es válido. Revisalo (código de área + número).', 'link' => '' );
		}
		// El email es obligatorio (Mercado Pago lo exige para la suscripción): se valida acá, sin llamar a MP.
		$email = sanitize_email( trim( $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return array( 'ok' => false, 'mensaje' => 'Ingresá un email válido: es obligatorio para generar el link.', 'link' => '' );
		}
		$res = CN_MP::crear_suscripcion_link( $plan, $nombre, $norm['normalizado'], $email, 'panel', array() );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'mensaje' => $res->get_error_message(), 'link' => '' );
		}
		return array( 'ok' => true, 'mensaje' => '', 'link' => (string) $res );
	}
	/** Bloque visual "Generar link de suscripción" (Fase 2C). $res es lo que devolvió procesar_generar_link(). */
	protected static function bloque_generar_link( $res ) {
		$planes = CN_MP::get_planes();
		$sel    = sanitize_text_field( wp_unslash( $_POST['cn_gl_plan'] ?? '' ) );
		$err    = is_array( $res ) && empty( $res['ok'] );
		$val    = function ( $campo ) use ( $err ) {
			return $err ? sanitize_text_field( wp_unslash( $_POST[ $campo ] ?? '' ) ) : '';
		};
		?>
		<h2>Generar link de suscripción</h2>
		<?php
		if ( is_array( $res ) && ! empty( $res['ok'] ) ) :
			?>
			<div class="notice notice-success inline" style="max-width:720px;">
				<p><strong>Link generado:</strong></p>
				<p>
					<input type="text" id="cn-gl-link" class="large-text" readonly value="<?php echo esc_attr( $res['link'] ); ?>" onclick="this.select();">
				</p>
				<p>
					<button type="button" class="button" onclick="var i=document.getElementById('cn-gl-link');i.select();i.setSelectionRange(0,99999);if(navigator.clipboard){navigator.clipboard.writeText(i.value);}else{document.execCommand('copy');}this.textContent='¡Copiado!';">Copiar</button>
				</p>
				<p>Pasale este link a la socia por WhatsApp. El alta será automática cuando pague (si el alta automática está activada en Config).</p>
			</div>
			<?php
		elseif ( $err ) :
			self::aviso( $res['mensaje'], 'error' );
		endif;
		?>
		<form method="post" style="max-width:480px;">
			<?php wp_nonce_field( 'cn_generar_link', 'cn_generar_link_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th><label>Plan</label></th>
					<td>
						<select name="cn_gl_plan" required>
							<?php foreach ( $planes as $clave => $info ) : ?>
								<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $err ? $sel : '', $clave ); ?>><?php echo esc_html( $info['nombre'] . ' — $' . number_format( (float) $info['precio'], 0, ',', '.' ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label>Nombre y Apellido</label></th>
					<td><input type="text" name="cn_gl_nombre" class="regular-text" required value="<?php echo esc_attr( $val( 'cn_gl_nombre' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label>Celular</label></th>
					<td><input type="text" name="cn_gl_celular" class="regular-text" required value="<?php echo esc_attr( $val( 'cn_gl_celular' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label>Email</label></th>
					<td>
						<input type="email" name="cn_gl_email" class="regular-text" required value="<?php echo esc_attr( $val( 'cn_gl_email' ) ); ?>">
						<p class="description">Conviene que sea el email de la cuenta de Mercado Pago de la socia</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Generar link', 'secondary', 'cn_admin_generar_link' ); ?>
		</form>
		<?php
	}
	/** Texto de una socia en el selector buscable: "#id · nombre · ****1234 · estado · email". */
	protected static function vm_etiqueta_socia( $s ) {
		$partes = array( '#' . (int) $s->id, (string) $s->nombre_apellido, CN_Vinculo::mascara_celular( $s->celular_hint ), (string) $s->estado );
		if ( '' !== trim( (string) $s->email ) ) {
			$partes[] = trim( (string) $s->email );
		}
		return implode( ' · ', $partes );
	}
	/**
	 * Lee y limpia los campos del bloque "Vincular suscripción de Mercado Pago" (Fase 5). La socia se elige de la
	 * lista (el texto empieza con "#id"); en el paso de confirmación el id viaja en un campo oculto.
	 */
	protected static function vm_datos_post() {
		$socia_txt = trim( sanitize_text_field( wp_unslash( $_POST['cn_vm_socia'] ?? '' ) ) );
		$socia_id  = absint( $_POST['cn_vm_socia_id'] ?? 0 );
		if ( ! $socia_id && preg_match( '/^#\s*(\d{1,12})(?!\d)/', $socia_txt, $m ) ) {
			$socia_id = (int) $m[1];
		}
		return array(
			'id'        => trim( sanitize_text_field( wp_unslash( $_POST['cn_vm_id'] ?? '' ) ) ),
			'socia_txt' => $socia_txt,
			'socia_id'  => $socia_id,
			'notificar' => ! empty( $_POST['cn_vm_notificar'] ),
		);
	}
	/**
	 * Procesa el bloque "Vincular suscripción de Mercado Pago" (Fase 5). Dos pasos, mismo nonce y manage_options:
	 *  - 'verificar': SOLO LECTURA. Consulta MP y muestra qué pasaría; no escribe nada en la base.
	 *  - 'vincular': solo si viene del formulario de confirmación (cn_vm_confirmo = 1). Llama a
	 *    CN_Webhook::vincular_suscripcion(), que vuelve a validar todo (MP incluido) por su cuenta.
	 * Devuelve null si no se envió el formulario; si se envió, array( 'paso' => verificado|vinculado|error, 'datos' => array, ... ).
	 */
	protected static function procesar_vincular_mp() {
		if ( ! isset( $_POST['cn_vm_accion'] ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'paso' => 'error', 'mensaje' => 'No tenés permisos para esto.', 'datos' => self::vm_datos_post() );
		}
		check_admin_referer( 'cn_vincular_mp', 'cn_vincular_mp_nonce' );
		$datos  = self::vm_datos_post();
		$accion = sanitize_key( wp_unslash( $_POST['cn_vm_accion'] ) );
		if ( '' === $datos['id'] ) {
			return array( 'paso' => 'error', 'mensaje' => 'Ingresá el ID de preapproval.', 'datos' => $datos );
		}
		if ( $datos['socia_id'] <= 0 ) {
			return array( 'paso' => 'error', 'mensaje' => 'Elegí una socia existente de la lista (tiene que empezar con #número).', 'datos' => $datos );
		}
		if ( 'vincular' === $accion ) {
			if ( '1' !== (string) wp_unslash( $_POST['cn_vm_confirmo'] ?? '' ) ) {
				return array( 'paso' => 'error', 'mensaje' => 'Primero verificá la suscripción y después confirmá.', 'datos' => $datos );
			}
			$resultado = CN_Webhook::vincular_suscripcion( $datos['id'], $datos['socia_id'], $datos['notificar'] );
			return array( 'paso' => 'vinculado', 'datos' => $datos, 'resultado' => $resultado );
		}
		$verificacion = CN_Webhook::vincular_suscripcion_verificar( $datos['id'], $datos['socia_id'] );
		return array( 'paso' => 'verificado', 'datos' => $datos, 'verificacion' => $verificacion );
	}
	/** Bloque visual "Vincular suscripción de Mercado Pago" (Fase 5). $res es lo que devolvió procesar_vincular_mp(). */
	protected static function bloque_vincular_mp( $res ) {
		global $wpdb;
		$paso    = is_array( $res ) ? (string) $res['paso'] : '';
		$vinculo = ( 'vinculado' === $paso && ! empty( $res['resultado']['ok'] ) );
		$d       = array( 'id' => '', 'socia_txt' => '', 'socia_id' => 0, 'notificar' => false );
		$socias  = (array) $wpdb->get_results( 'SELECT id, nombre_apellido, estado, email, celular_hint FROM ' . CN_DB::tabla( 'miembros' ) . ' ORDER BY nombre_apellido ASC' );
		if ( is_array( $res ) && ! $vinculo ) {
			$d = $res['datos']; // se vuelve a mostrar lo escrito (salvo después de un vínculo exitoso).
		} elseif ( ! is_array( $res ) ) {
			// Viene del enlace "Vincular" de Cruce con Mercado Pago: solo precarga el ID y, si hay propuesta, la socia.
			if ( isset( $_GET['cn_vincular_id'] ) ) {
				$id_get = trim( sanitize_text_field( wp_unslash( $_GET['cn_vincular_id'] ) ) );
				if ( preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $id_get ) ) {
					$d['id'] = $id_get;
				}
			}
			if ( isset( $_GET['cn_vincular_socia'] ) ) {
				$d['socia_id'] = absint( $_GET['cn_vincular_socia'] );
			}
		}
		if ( $d['socia_id'] > 0 && '' === $d['socia_txt'] ) {
			foreach ( $socias as $s ) {
				if ( (int) $s->id === (int) $d['socia_id'] ) {
					$d['socia_txt'] = self::vm_etiqueta_socia( $s );
					break;
				}
			}
		}
		?>
		<h2 id="cn-vincular-mp">Vincular suscripción de Mercado Pago</h2>
		<p style="max-width:720px;">Une a mano una suscripción <strong>ya autorizada</strong> en Mercado Pago con una socia <strong>que ya existe</strong> (acá nunca se crean socias nuevas). El plan sale del monto de la suscripción. Primero <em>Verificar</em> (solo lectura: no cambia nada) y después <em>Confirmar y vincular</em>.</p>
		<?php
		if ( 'error' === $paso ) {
			self::aviso( $res['mensaje'], 'error' );
		} elseif ( 'vinculado' === $paso ) {
			$r    = $res['resultado'];
			$tipo = ! empty( $r['ok'] ) ? 'success' : ( 'conflicto' === $r['accion'] ? 'warning' : 'error' );
			$txt  = strtoupper( (string) $r['accion'] ) . ': ' . (string) $r['mensaje'];
			if ( ! empty( $r['ok'] ) ) {
				$txt .= ' Estado: ' . ( null === $r['estado_anterior'] ? '—' : (string) $r['estado_anterior'] ) . ' → ' . (string) $r['estado_nuevo'] . '.';
				$txt .= ( ! empty( $r['notificacion']['mail'] ) || ! empty( $r['notificacion']['capi'] ) )
					? ' Se pidió enviar: ' . implode( ' y ', array_filter( array( ! empty( $r['notificacion']['mail'] ) ? 'mail de bienvenida' : '', ! empty( $r['notificacion']['capi'] ) ? 'evento Meta' : '' ) ) ) . '.'
					: ' No se envió mail ni evento Meta.';
			}
			self::aviso( $txt, $tipo );
		} elseif ( 'verificado' === $paso ) {
			$v  = $res['verificacion'];
			$mp = $v['mp'];
			if ( ! $v['ok'] ) {
				self::aviso( $v['error'], 'error' );
			}
			if ( ! empty( $mp['status'] ) ) {
				$plan_txt = ! empty( $mp['plan'] ) ? (string) $mp['plan_nombre'] . ' — $' . number_format( (float) $mp['precio'], 0, ',', '.' ) . ' (según el monto de la suscripción en Mercado Pago)' : '—';
				$prox_txt = ! empty( $mp['next_payment_date'] ) ? substr( (string) $mp['next_payment_date'], 0, 10 ) : '—';
				?>
				<table class="widefat striped" style="max-width:720px;margin-bottom:12px;"><tbody>
					<tr><th style="width:220px;">ID de preapproval</th><td><code><?php echo esc_html( $d['id'] ); ?></code></td></tr>
					<tr><th>Estado en Mercado Pago</th><td><?php echo esc_html( $mp['status'] ); ?></td></tr>
					<tr><th>Plan</th><td><?php echo esc_html( $plan_txt ); ?></td></tr>
					<tr><th>Descripción (reason)</th><td><?php echo esc_html( '' !== (string) $mp['reason'] ? $mp['reason'] : '—' ); ?></td></tr>
					<tr><th>Próximo cobro (next_payment_date)</th><td><?php echo esc_html( $prox_txt ); ?></td></tr>
					<tr><th>Email del pagador</th><td><?php echo esc_html( '' !== (string) $mp['payer_email'] ? $mp['payer_email'] : '—' ); ?></td></tr>
					<?php if ( ! empty( $v['socia'] ) ) : ?>
						<tr><th>Socia elegida</th><td><?php echo esc_html( $v['socia']['nombre_apellido'] . ' (id ' . $v['socia']['id'] . ', estado: ' . $v['socia']['estado'] . ( ! empty( $v['socia']['en_trial'] ) ? ', en trial' : '' ) . ( '' !== $v['socia']['email'] ? ', email: ' . $v['socia']['email'] : '' ) . ')' ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $v['ok'] ) : ?>
						<tr><th>Qué pasaría al vincular</th><td><strong><?php echo esc_html( $v['mensaje'] ); ?></strong></td></tr>
					<?php endif; ?>
				</tbody></table>
				<?php
			}
			$puede_vincular = $v['ok'] && in_array( $v['accion_prevista'], array( 'activada', 'completada' ), true );
			if ( $v['ok'] && ! $puede_vincular && null !== $v['accion_prevista'] ) {
				self::aviso( 'No se puede vincular: ' . $v['mensaje'], 'warning' );
			}
			if ( $puede_vincular ) :
				?>
				<form method="post" style="max-width:720px;margin-bottom:16px;" action="<?php echo esc_url( '#cn-vincular-mp' ); ?>">
					<?php wp_nonce_field( 'cn_vincular_mp', 'cn_vincular_mp_nonce' ); ?>
					<input type="hidden" name="cn_vm_confirmo" value="1">
					<input type="hidden" name="cn_vm_id" value="<?php echo esc_attr( $d['id'] ); ?>">
					<input type="hidden" name="cn_vm_socia_id" value="<?php echo esc_attr( (int) $d['socia_id'] ); ?>">
					<?php if ( 'activada' === $v['accion_prevista'] ) : ?>
						<p><label><input type="checkbox" name="cn_vm_notificar" value="1"> Enviar mail de bienvenida y evento Meta</label>
							<span class="description">(desmarcado por defecto; solo cuando se activa a la socia)</span></p>
					<?php endif; ?>
					<p><button type="submit" name="cn_vm_accion" value="vincular" class="button button-primary">Confirmar y vincular</button></p>
				</form>
				<?php
			endif;
		}
		?>
		<form method="post" style="max-width:640px;" action="<?php echo esc_url( '#cn-vincular-mp' ); ?>">
			<?php wp_nonce_field( 'cn_vincular_mp', 'cn_vincular_mp_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th><label>ID de preapproval</label></th>
					<td><input type="text" name="cn_vm_id" class="regular-text" required value="<?php echo esc_attr( $d['id'] ); ?>"></td>
				</tr>
				<tr>
					<th><label>Socia existente</label></th>
					<td>
						<input type="text" name="cn_vm_socia" class="large-text" list="cn-vm-socias" autocomplete="off" required placeholder="Escribí nombre, email o últimos 4 del celular y elegí de la lista" value="<?php echo esc_attr( $d['socia_txt'] ); ?>">
						<datalist id="cn-vm-socias">
							<?php foreach ( $socias as $s ) : ?>
								<option value="<?php echo esc_attr( self::vm_etiqueta_socia( $s ) ); ?>"></option>
							<?php endforeach; ?>
						</datalist>
						<p class="description">Hay que elegir una de la lista (queda con <code>#número</code> adelante). No se crean socias nuevas.</p>
					</td>
				</tr>
			</table>
			<p><button type="submit" name="cn_vm_accion" value="verificar" class="button button-secondary">Verificar</button></p>
		</form>
		<?php
	}
	/**
	 * Tipo de una socia para mostrar (Suscripta / Trial / Manual / Inactiva): misma regla que el listado.
	 */
	protected static function tipo_socia_txt( $s ) {
		if ( 'activo' !== $s->estado ) {
			return 'Inactiva (' . $s->estado . ')';
		}
		if ( '' !== (string) $s->preapproval_id ) {
			return 'Suscripta';
		}
		return ! empty( $s->fecha_fin_trial ) ? 'Trial' : 'Manual';
	}
	/**
	 * Bloque desplegable de socias duplicadas (mismo email o mismo nombre normalizado). SOLO LECTURA.
	 * $socias = filas completas de wp_cn_miembros (antes de filtrar el listado).
	 */
	protected static function bloque_duplicados( $socias ) {
		$d       = CN_Vinculo::duplicados( $socias );
		$n_email = count( $d['por_email'] );
		$n_nom   = count( $d['por_nombre'] );
		$tope    = 200;
		?>
		<details style="max-width:900px;margin:16px 0;">
			<summary style="cursor:pointer;font-weight:600;">Posibles duplicados (solo lectura): <?php echo (int) $n_email; ?> por email · <?php echo (int) $n_nom; ?> por nombre</summary>
			<p class="description">Grupos con el mismo email, o con el mismo nombre una vez normalizado (sin acentos ni mayúsculas). No se cambia nada desde acá: revisalos a mano.</p>
			<?php foreach ( array( 'por_email' => 'Mismo email', 'por_nombre' => 'Mismo nombre normalizado' ) as $clave => $titulo ) : ?>
				<h4><?php echo esc_html( $titulo ); ?></h4>
				<?php if ( ! $d[ $clave ] ) : ?>
					<p>Sin duplicados.</p>
				<?php else : ?>
					<table class="widefat striped"><thead><tr><th>Clave</th><th>id</th><th>Nombre</th><th>Celular</th><th>Estado / tipo</th><th>Alta</th></tr></thead><tbody>
					<?php
					$mostrados = 0;
					foreach ( $d[ $clave ] as $k => $grupo ) {
						if ( ++$mostrados > $tope ) {
							echo '<tr><td colspan="6">Se muestran los primeros ' . (int) $tope . ' grupos.</td></tr>';
							break;
						}
						foreach ( $grupo as $i => $s ) {
							echo '<tr>';
							echo '<td>' . ( 0 === $i ? esc_html( (string) $k ) : '' ) . '</td>';
							echo '<td><a href="' . esc_url( add_query_arg( array( 'page' => 'cn-socias', 'editar' => (int) $s->id ), admin_url( 'admin.php' ) ) ) . '">' . (int) $s->id . '</a></td>';
							echo '<td>' . esc_html( $s->nombre_apellido ) . '</td>';
							echo '<td>' . esc_html( CN_Vinculo::mascara_celular( $s->celular_hint ) ) . '</td>';
							echo '<td>' . esc_html( self::tipo_socia_txt( $s ) ) . '</td>';
							echo '<td>' . esc_html( mysql2date( 'd/m/Y', $s->fecha_alta ) ) . '</td>';
							echo '</tr>';
						}
					}
					?>
					</tbody></table>
				<?php endif; ?>
			<?php endforeach; ?>
		</details>
		<?php
	}
	/**
	 * Sección "Propuesta de vínculo por email" del Cruce. SOLO LECTURA: muestra a qué socia correspondería cada
	 * suscripción autorizada, con qué confianza, y un enlace que precarga la pantalla Vincular (no vincula).
	 * $prop = resultado de CN_Vinculo::proponer().
	 */
	protected static function bloque_propuesta_vinculo( $prop ) {
		$etiquetas = array(
			CN_Vinculo::EMAIL_EXACTO  => 'email exacto',
			CN_Vinculo::VARIAS_SOCIAS => 'varias socias',
			CN_Vinculo::SIN_MATCH     => 'sin match',
			CN_Vinculo::SIN_EMAIL     => 'sin email',
			CN_Vinculo::YA_VINCULADA  => 'ya vinculada',
		);
		$fuentes = array( 'suscripcion' => 'de la suscripción', 'cobro' => 'del cobro aprobado', 'authorized_payments' => 'de authorized_payments' );
		$cuenta  = array_fill_keys( array_keys( $etiquetas ), 0 );
		foreach ( $prop['filas'] as $f ) {
			$cuenta[ $f['confianza'] ]++;
		}
		echo '<h2>Propuesta de vínculo por email</h2>';
		echo '<p>Solo lectura: no vincula nada. Para cada suscripción <strong>autorizada</strong> propone la socia por email; para vincular usá el botón, que lleva a la pantalla Vincular en Socias (ahí se verifica y se confirma).</p>';
		echo '<p>';
		foreach ( $etiquetas as $k => $txt ) {
			echo esc_html( ucfirst( $txt ) . ': ' . $cuenta[ $k ] . '   ' );
		}
		echo '</p>';
		if ( ! empty( $prop['tope_alcanzado'] ) ) {
			echo '<div class="notice notice-warning inline"><p>Se alcanzó el tope de ' . (int) $prop['tope'] . ' consultas extra a Mercado Pago para buscar emails (se usaron ' . (int) $prop['extra_usadas'] . '). Las suscripciones que quedaron sin email figuran como "sin email": no significa que no se pueda identificar a la pagadora.</p></div>';
		}
		echo '<table class="widefat striped cn-cruce-ids"><thead><tr><th>ID de preapproval</th><th>Plan</th><th>Próximo cobro</th><th>Email del pagador</th><th>Socia propuesta</th><th>Confianza</th><th>Vincular</th></tr></thead><tbody>';
		if ( ! $prop['filas'] ) {
			echo '<tr><td colspan="7">No hay suscripciones autorizadas.</td></tr>';
		}
		foreach ( $prop['filas'] as $f ) {
			$plan_txt = null !== $f['plan'] ? $f['plan'] . ' ($' . number_format( (float) $f['monto'], 0, ',', '.' ) . ')' : '? ($' . ( is_scalar( $f['monto'] ) ? (string) $f['monto'] : '?' ) . ')';
			echo '<tr>';
			echo '<td><code class="cn-id">' . esc_html( $f['id'] ) . '</code> <button type="button" class="button button-small cn-copiar" data-cn-copiar="' . esc_attr( $f['id'] ) . '">Copiar</button></td>';
			echo '<td>' . esc_html( $plan_txt ) . '</td>';
			echo '<td>' . esc_html( '' !== $f['proximo_cobro'] ? $f['proximo_cobro'] : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== $f['email'] ? $f['email'] : '—' ) . ( '' !== $f['email_fuente'] ? '<br><small>' . esc_html( $fuentes[ $f['email_fuente'] ] ?? $f['email_fuente'] ) . '</small>' : '' ) . '</td>';
			echo '<td>';
			if ( $f['socia'] ) {
				echo esc_html( '#' . (int) $f['socia']->id . ' ' . $f['socia']->nombre_apellido . ' · ' . CN_Vinculo::mascara_celular( $f['socia']->celular_hint ) . ' · ' . $f['socia']->estado );
			} elseif ( $f['candidatas'] ) {
				foreach ( $f['candidatas'] as $c ) {
					echo esc_html( '#' . (int) $c->id . ' ' . $c->nombre_apellido . ' · ' . CN_Vinculo::mascara_celular( $c->celular_hint ) . ' · ' . $c->estado ) . '<br>';
				}
			} else {
				echo '—';
			}
			if ( '' !== $f['aviso'] ) {
				echo '<br><small style="color:#b32d2e;">' . esc_html( $f['aviso'] ) . '</small>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $etiquetas[ $f['confianza'] ] ) . '</td>';
			echo '<td>';
			if ( CN_Vinculo::YA_VINCULADA === $f['confianza'] || ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $f['id'] ) ) {
				echo '&mdash;';
			} else {
				$args = array( 'page' => 'cn-socias', 'cn_vincular_id' => $f['id'] );
				if ( CN_Vinculo::EMAIL_EXACTO === $f['confianza'] && $f['socia'] ) {
					$args['cn_vincular_socia'] = (int) $f['socia']->id;
				}
				echo '<a class="button button-small" href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) . '#cn-vincular-mp' ) . '">Vincular</a>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
	public static function pagina_socias() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		global $wpdb;
		$tabla = CN_DB::tabla( 'miembros' );
		$cn_link_res = self::procesar_generar_link(); // Fase 2C: solo actúa si se envió su propio formulario.
		$cn_vm_res   = self::procesar_vincular_mp(); // Fase 4-G: solo actúa si se envió su propio formulario.
		$cn_vm2_res  = CN_Vinculo_Manual::procesar(); // Vincular por celular: solo actúa si se envió su propio formulario.
		$cn_vl_res   = CN_Vinculo_Lote::procesar(); // Vincular en lote: ídem (previsualiza o confirma de a pocos).
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
			<?php echo CN_Retorno::cartel_pendientes(); // vacío si no hay clientas esperando acceso. ?>
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
			<?php self::bloque_generar_link( $cn_link_res ); ?>
			<?php self::bloque_vincular_mp( $cn_vm_res ); ?>
			<?php CN_Vinculo_Manual::render( $cn_vm2_res ); ?>
			<?php CN_Vinculo_Lote::render( $cn_vl_res ); ?>
			<?php self::bloque_duplicados( $socias ); // Fase 5: solo lectura, sobre el listado completo (antes de filtrar). ?>
			<h2>Listado</h2>
			<?php
			// Resumen de socias por tipo (solo lectura, calculado sobre $socias ya cargada).
			$cn_pagas  = 0;
			$cn_trial  = 0;
			$cn_manual = 0;
			$cn_otras  = 0;
			foreach ( (array) $socias as $cn_s ) {
				if ( 'activo' !== $cn_s->estado ) {
					$cn_otras++;
				} elseif ( ! empty( $cn_s->preapproval_id ) ) {
					$cn_pagas++;
				} elseif ( ! empty( $cn_s->fecha_fin_trial ) ) {
					$cn_trial++;
				} else {
					$cn_manual++;
				}
			}
			?>
			<?php
			$cn_total_activas = $cn_pagas + $cn_manual;
			$cn_tipo_sel     = isset( $_GET['cn_tipo'] ) ? sanitize_key( wp_unslash( $_GET['cn_tipo'] ) ) : 'todas';
			$cn_orden_sel    = isset( $_GET['cn_orden'] ) ? sanitize_key( wp_unslash( $_GET['cn_orden'] ) ) : 'alta_desc';
			$cn_tipos_ok     = array( 'todas' => 'Todas', 'activas' => 'Activas (suscriptas y cargadas a mano)', 'suscripta' => 'Suscriptas', 'trial' => 'En trial', 'manual' => 'Cargadas a mano', 'inactivas' => 'Canceladas o pausadas' );
			$cn_ordenes_ok   = array( 'alta_desc' => 'Alta: más recientes primero', 'alta_asc' => 'Alta: más antiguas primero', 'nombre_asc' => 'Nombre: A a Z', 'nombre_desc' => 'Nombre: Z a A', 'tipo' => 'Tipo' );
			if ( ! isset( $cn_tipos_ok[ $cn_tipo_sel ] ) ) {
				$cn_tipo_sel = 'todas';
			}
			if ( ! isset( $cn_ordenes_ok[ $cn_orden_sel ] ) ) {
				$cn_orden_sel = 'alta_desc';
			}
			$cn_tipo_de = function ( $s ) {
				if ( 'activo' !== $s->estado ) {
					return 'inactiva';
				}
				if ( ! empty( $s->preapproval_id ) ) {
					return 'suscripta';
				}
				return ! empty( $s->fecha_fin_trial ) ? 'trial' : 'manual';
			};
			$cn_total_listado = count( (array) $socias );
			$cn_filtradas = array_values( array_filter( (array) $socias, function ( $s ) use ( $cn_tipo_sel, $cn_tipo_de ) {
				$t = $cn_tipo_de( $s );
				if ( 'activas' === $cn_tipo_sel ) {
					return in_array( $t, array( 'suscripta', 'manual' ), true );
				}
				if ( 'inactivas' === $cn_tipo_sel ) {
					return 'inactiva' === $t;
				}
				if ( 'todas' === $cn_tipo_sel ) {
					return true;
				}
				return $t === $cn_tipo_sel;
			} ) );
			$cn_rango = array( 'suscripta' => 0, 'trial' => 1, 'manual' => 2, 'inactiva' => 3 );
			usort( $cn_filtradas, function ( $a, $b ) use ( $cn_orden_sel, $cn_tipo_de, $cn_rango ) {
				switch ( $cn_orden_sel ) {
					case 'alta_asc':
						return strcmp( $a->fecha_alta, $b->fecha_alta );
					case 'nombre_asc':
						return strcasecmp( $a->nombre_apellido, $b->nombre_apellido );
					case 'nombre_desc':
						return strcasecmp( $b->nombre_apellido, $a->nombre_apellido );
					case 'tipo':
						$d = $cn_rango[ $cn_tipo_de( $a ) ] - $cn_rango[ $cn_tipo_de( $b ) ];
						return 0 !== $d ? $d : strcmp( $b->fecha_alta, $a->fecha_alta );
					default:
						return strcmp( $b->fecha_alta, $a->fecha_alta );
				}
			} );
			$socias = $cn_filtradas;
			?>
			<div style="display:flex;align-items:center;gap:32px;flex-wrap:wrap;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2e7d32;padding:16px 24px;margin:0 0 16px;">
				<div>
					<div style="font-size:13px;color:#50575e;">Suscriptoras activas (cualquier plan)</div>
					<div style="font-size:56px;font-weight:600;line-height:1.1;color:#2e7d32;"><?php echo (int) $cn_total_activas; ?></div>
				</div>
				<div style="font-size:14px;color:#50575e;line-height:1.8;">
					Con Mercado Pago: <?php echo (int) $cn_pagas; ?><br>
					Cargadas a mano: <?php echo (int) $cn_manual; ?><br>
					En trial (no incluidas): <?php echo (int) $cn_trial; ?> &middot; Canceladas o pausadas: <?php echo (int) $cn_otras; ?>
				</div>
			</div>
			<form method="get" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 12px;">
				<input type="hidden" name="page" value="cn-socias">
				<label>Mostrar
					<select name="cn_tipo">
						<?php foreach ( $cn_tipos_ok as $cn_k => $cn_v ) : ?>
							<option value="<?php echo esc_attr( $cn_k ); ?>" <?php selected( $cn_tipo_sel, $cn_k ); ?>><?php echo esc_html( $cn_v ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>Ordenar por
					<select name="cn_orden">
						<?php foreach ( $cn_ordenes_ok as $cn_k => $cn_v ) : ?>
							<option value="<?php echo esc_attr( $cn_k ); ?>" <?php selected( $cn_orden_sel, $cn_k ); ?>><?php echo esc_html( $cn_v ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button button-primary">Aplicar</button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cn-socias' ) ); ?>">Quitar filtros</a>
				<span style="color:#50575e;">Mostrando <?php echo (int) count( $socias ); ?> de <?php echo (int) $cn_total_listado; ?></span>
			</form>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Nombre y Apellido</th>
						<th>Celular</th>
						<th>Estado</th>
						<th>Tipo</th>
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
							<td><?php
							if ( 'activo' === $socia->estado ) {
								echo ! empty( $socia->preapproval_id ) ? '<strong>Suscripta</strong>' : ( ! empty( $socia->fecha_fin_trial ) ? 'Trial' : 'Manual' );
							} else {
								echo '&mdash;';
							}
							?></td>
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
			<?php echo CN_Retorno::registro_html(); // Registro de retorno: solo lectura. ?>
		</div>
		<?php
	}
	/* ---------------------------------------------------------------------
	 * CURSOS
	 * ------------------------------------------------------------------- */
	public static function pagina_cruce_mp() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		global $wpdb;
		?>
		<div class="wrap">
			<h1>Cruce con Mercado Pago</h1>
			<p>Compara las suscripciones de Mercado Pago con las socias del plugin (por email). Solo lectura: no cambia ningún acceso ni ningún dato.</p>
			<form method="post">
				<?php wp_nonce_field( 'cn_cruce_mp', 'cn_cruce_mp_nonce' ); ?>
				<button type="submit" name="cn_cruce_mp" value="1" class="button button-primary">Consultar Mercado Pago</button>
			</form>
		<?php
		if ( ! isset( $_POST['cn_cruce_mp'] ) || ! check_admin_referer( 'cn_cruce_mp', 'cn_cruce_mp_nonce' ) ) {
			echo '</div>';
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}
		$token   = '';
		$codigos = array();
		foreach ( array( 'suscripciones' => CN_MP::get_access_token(), 'trial' => CN_MP::get_access_token_trial() ) as $nombre_acceso => $acceso ) {
			if ( '' === $acceso ) {
				$codigos[] = $nombre_acceso . ': sin acceso guardado';
				continue;
			}
			$prueba = wp_remote_get( CN_MP::API_BASE . '/preapproval/search?limit=1', array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $acceso, 'Content-Type' => 'application/json' ),
			) );
			$cod_prueba = is_wp_error( $prueba ) ? 0 : (int) wp_remote_retrieve_response_code( $prueba );
			$codigos[]  = $nombre_acceso . ': código ' . $cod_prueba;
			if ( 200 === $cod_prueba ) {
				$token = $acceso;
				echo '<p style="color:#50575e;">Se consultó con el acceso de ' . esc_html( $nombre_acceso ) . '.</p>';
				break;
			}
		}
		if ( '' === $token ) {
			echo '<p style="color:#b32d2e;"><strong>Ningún acceso guardado en el plugin permite ver suscripciones</strong> (' . esc_html( implode( ' | ', $codigos ) ) . ').</p></div>';
			return;
		}
		$por_estado = array();
		$errores    = array();
		foreach ( array( 'authorized', 'paused', 'cancelled' ) as $estado ) {
			$lista  = array();
			$offset = 0;
			for ( $pag = 0; $pag < 4; $pag++ ) {
				$resp = wp_remote_get( CN_MP::API_BASE . '/preapproval/search?status=' . $estado . '&limit=100&offset=' . $offset, array(
					'timeout' => 15,
					'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
				) );
				if ( is_wp_error( $resp ) ) {
					$errores[] = $estado . ': ' . $resp->get_error_message();
					break;
				}
				$codigo = (int) wp_remote_retrieve_response_code( $resp );
				$data   = json_decode( wp_remote_retrieve_body( $resp ), true );
				if ( 200 !== $codigo || ! is_array( $data ) ) {
					$errores[] = $estado . ': Mercado Pago respondió código ' . $codigo;
					break;
				}
				$res = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array();
				foreach ( $res as $r ) {
					$lista[] = $r;
				}
				$total  = isset( $data['paging']['total'] ) ? (int) $data['paging']['total'] : count( $lista );
				$offset += 100;
				if ( count( $res ) < 100 || $offset >= $total ) {
					break;
				}
			}
			$por_estado[ $estado ] = $lista;
		}
		if ( $errores ) {
			echo '<p style="color:#b32d2e;"><strong>No se pudo consultar todo:</strong> ' . esc_html( implode( ' | ', $errores ) ) . '. Si dice 401 o 403, el acceso guardado en el plugin no permite ver suscripciones.</p>';
			echo '</div>';
			return;
		}
		$miembros = $wpdb->get_results( 'SELECT id, nombre_apellido, estado, email, fecha_fin_trial FROM ' . CN_DB::tabla( 'miembros' ) . " WHERE email IS NOT NULL AND email <> ''" );
		$por_email = array();
		foreach ( (array) $miembros as $m ) {
			$por_email[ strtolower( trim( (string) $m->email ) ) ] = $m;
		}
		$emails_aut = array();
		$montos     = array();
		$coinciden  = array();
		$sin_match  = array();
		foreach ( $por_estado['authorized'] as $r ) {
			$email = strtolower( trim( (string) ( isset( $r['payer_email'] ) ? $r['payer_email'] : '' ) ) );
			$monto = isset( $r['auto_recurring']['transaction_amount'] ) ? (string) $r['auto_recurring']['transaction_amount'] : '?';
			$montos[ $monto ] = isset( $montos[ $monto ] ) ? $montos[ $monto ] + 1 : 1;
			if ( '' !== $email ) {
				$emails_aut[ $email ] = true;
			}
			$fecha = isset( $r['date_created'] ) ? substr( (string) $r['date_created'], 0, 10 ) : '';
			if ( '' !== $email && isset( $por_email[ $email ] ) ) {
				$coinciden[] = array( 'email' => $email, 'socia' => $por_email[ $email ], 'monto' => $monto, 'fecha' => $fecha );
			} else {
				$sin_match[] = array( 'email' => $email, 'monto' => $monto, 'fecha' => $fecha );
			}
		}
		$de_trial = 0;
		foreach ( $coinciden as $c ) {
			if ( ! empty( $c['socia']->fecha_fin_trial ) ) {
				$de_trial++;
			}
		}
		$bajas_activas = array();
		foreach ( array( 'paused', 'cancelled' ) as $estado ) {
			foreach ( $por_estado[ $estado ] as $r ) {
				$email = strtolower( trim( (string) ( isset( $r['payer_email'] ) ? $r['payer_email'] : '' ) ) );
				if ( '' !== $email && ! isset( $emails_aut[ $email ] ) && isset( $por_email[ $email ] ) && 'activo' === $por_email[ $email ]->estado ) {
					$bajas_activas[ $email ] = array( 'socia' => $por_email[ $email ], 'estado_mp' => $estado );
				}
			}
		}
		?>
		<h2>Resultado</h2>
		<p><strong>Autorizadas en Mercado Pago: <?php echo (int) count( $por_estado['authorized'] ); ?></strong> &middot; Pausadas: <?php echo (int) count( $por_estado['paused'] ); ?> &middot; Canceladas: <?php echo (int) count( $por_estado['cancelled'] ); ?></p>
		<p>Por monto mensual: <?php foreach ( $montos as $mo => $n ) { echo esc_html( '$' . $mo . ': ' . $n . '   ' ); } ?></p>
		<p><strong>Coinciden con una socia del plugin (por email): <?php echo (int) count( $coinciden ); ?></strong> &middot; De esas, vienen de un trial: <?php echo (int) $de_trial; ?> &middot; Sin coincidencia: <?php echo (int) count( $sin_match ); ?></p>
		<h3>Pagan en Mercado Pago y no coinciden por email con ninguna socia</h3>
		<p>Pueden ser socias cargadas a mano (sin email en el plugin) o con otro email.</p>
		<table class="widefat striped" style="max-width:700px;"><thead><tr><th>Email</th><th>Monto</th><th>Alta en MP</th></tr></thead><tbody>
		<?php foreach ( $sin_match as $s ) : ?>
			<tr><td><?php echo esc_html( '' !== $s['email'] ? $s['email'] : '(sin email)' ); ?></td><td><?php echo esc_html( '$' . $s['monto'] ); ?></td><td><?php echo esc_html( $s['fecha'] ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<h3>Canceladas o pausadas en Mercado Pago cuya socia sigue activa en el plugin</h3>
		<p>Estas conservan el acceso. Revisalas a mano: este reporte no cambia nada.</p>
		<table class="widefat striped" style="max-width:700px;"><thead><tr><th>Socia</th><th>Email</th><th>Estado en MP</th></tr></thead><tbody>
		<?php foreach ( $bajas_activas as $em => $b ) : ?>
			<tr><td><?php echo esc_html( $b['socia']->nombre_apellido ); ?></td><td><?php echo esc_html( $em ); ?></td><td><?php echo esc_html( $b['estado_mp'] ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
		$por_mes = array();
		$desde_embudo = 0;
		foreach ( $por_estado['authorized'] as $r ) {
			$mes = isset( $r['date_created'] ) ? substr( (string) $r['date_created'], 0, 7 ) : '?';
			$por_mes[ $mes ] = isset( $por_mes[ $mes ] ) ? $por_mes[ $mes ] + 1 : 1;
			if ( isset( $r['date_created'] ) && substr( (string) $r['date_created'], 0, 10 ) >= '2026-09-07' ) {
				$desde_embudo++;
			}
		}
		ksort( $por_mes );
		echo '<h2>Altas de suscripci&oacute;n por mes (las autorizadas hoy)</h2><p>';
		foreach ( $por_mes as $mes => $n ) {
			echo esc_html( $mes . ': ' . $n . '   ' );
		}
		echo '</p><p><strong>Desde el 07/09 (inicio del embudo del trial): ' . (int) $desde_embudo . '</strong></p>';
		$cobros          = array();
		$pagos_total     = 0;
		$pagos_sin_email = 0;
		$errores_pagos   = array();
		$pagos_lista     = array(); // todos los cobros leídos (sin filtrar por monto): alimentan la propuesta de vínculo.
		$desde           = gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 40 * DAY_IN_SECONDS );
		$hasta           = gmdate( 'Y-m-d\TH:i:s.000\Z', time() );
		$off             = 0;
		for ( $pag = 0; $pag < 5; $pag++ ) {
			$resp = wp_remote_get( CN_MP::API_BASE . '/v1/payments/search?status=approved&sort=date_created&criteria=desc&range=date_created&begin_date=' . rawurlencode( $desde ) . '&end_date=' . rawurlencode( $hasta ) . '&limit=100&offset=' . $off, array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			) );
			if ( is_wp_error( $resp ) ) {
				$errores_pagos[] = $resp->get_error_message();
				break;
			}
			$codigo_p = (int) wp_remote_retrieve_response_code( $resp );
			$data_p   = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( 200 !== $codigo_p || ! is_array( $data_p ) ) {
				$errores_pagos[] = 'Mercado Pago respondió código ' . $codigo_p;
				break;
			}
			$res_p = isset( $data_p['results'] ) && is_array( $data_p['results'] ) ? $data_p['results'] : array();
			foreach ( $res_p as $p ) {
				$pagos_lista[] = $p;
				$monto_p = isset( $p['transaction_amount'] ) ? (int) $p['transaction_amount'] : 0;
				if ( ! in_array( $monto_p, array( 15000, 15500, 32000 ), true ) ) {
					continue;
				}
				$pagos_total++;
				$em_p = strtolower( trim( (string) ( isset( $p['payer']['email'] ) ? $p['payer']['email'] : '' ) ) );
				if ( '' === $em_p ) {
					$pagos_sin_email++;
					continue;
				}
				if ( ! isset( $cobros[ $em_p ] ) ) {
					$cobros[ $em_p ] = array( 'monto' => $monto_p, 'fecha' => isset( $p['date_created'] ) ? substr( (string) $p['date_created'], 0, 10 ) : '' );
				}
			}
			$total_p = isset( $data_p['paging']['total'] ) ? (int) $data_p['paging']['total'] : 0;
			$off    += 100;
			if ( count( $res_p ) < 100 || $off >= $total_p ) {
				break;
			}
		}
		echo '<h2>Cobros de planes en los &uacute;ltimos 40 d&iacute;as</h2>';
		if ( $errores_pagos ) {
			echo '<p style="color:#b32d2e;">No se pudieron consultar los cobros: ' . esc_html( implode( ' | ', $errores_pagos ) ) . '</p>';
		} else {
			$coinc_p = array();
			$de_trial_p = 0;
			foreach ( $cobros as $em_c => $c ) {
				if ( isset( $por_email[ $em_c ] ) ) {
					$coinc_p[ $em_c ] = $c;
					if ( ! empty( $por_email[ $em_c ]->fecha_fin_trial ) ) {
						$de_trial_p++;
					}
				}
			}
			echo '<p>Cobros de $15.000, $15.500 o $32.000 (puede incluir compras de la tienda por el mismo monto): <strong>' . (int) $pagos_total . '</strong> &middot; Personas distintas con email: ' . (int) count( $cobros ) . ' &middot; Cobros sin email: ' . (int) $pagos_sin_email . '</p>';
			echo '<p><strong>Coinciden con una socia del plugin (por email): ' . (int) count( $coinc_p ) . '</strong> &middot; De esas, vienen de un trial: ' . (int) $de_trial_p . '</p>';
			if ( $coinc_p ) {
				echo '<table class="widefat striped" style="max-width:700px;"><thead><tr><th>Socia</th><th>Tipo</th><th>Monto</th><th>&Uacute;ltimo cobro</th></tr></thead><tbody>';
				foreach ( $coinc_p as $em_c => $c ) {
					echo '<tr><td>' . esc_html( $por_email[ $em_c ]->nombre_apellido ) . '</td><td>' . ( ! empty( $por_email[ $em_c ]->fecha_fin_trial ) ? 'Viene de trial' : 'Otra' ) . '</td><td>' . esc_html( '$' . $c['monto'] ) . '</td><td>' . esc_html( $c['fecha'] ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}
		// Fase 5 (SOLO LECTURA): propuesta de vínculo por email. Usa lo ya consultado ($por_estado, $pagos_lista);
		// solo puede hacer consultas extra a MP (tope CN_Vinculo::TOPE_CONSULTAS_EXTRA) para suscripciones sin email.
		echo '<style>.cn-cruce-ids td, .cn-cruce-ids th { -webkit-user-select: text; user-select: text; vertical-align: middle; } .cn-cruce-ids code.cn-id { -webkit-user-select: all; user-select: all; word-break: break-all; }</style>';
		$todas_socias = $wpdb->get_results( 'SELECT id, nombre_apellido, estado, email, celular_hint, preapproval_id, fecha_fin_trial FROM ' . CN_DB::tabla( 'miembros' ) );
		self::bloque_propuesta_vinculo( CN_Vinculo::proponer( $por_estado['authorized'], $todas_socias, $pagos_lista ) );
		// Detalle de suscripciones (SOLO LECTURA): usa los mismos resultados ya consultados arriba ($por_estado),
		// sin llamadas nuevas a Mercado Pago. No se muestra ni se registra ningún token.
		$dash = static function ( $valor ) {
			$valor = is_scalar( $valor ) ? trim( (string) $valor ) : '';
			return '' === $valor ? '—' : $valor;
		};
		$dia = static function ( $valor ) {
			$valor = is_scalar( $valor ) ? trim( (string) $valor ) : '';
			return '' === $valor ? '—' : substr( $valor, 0, 10 );
		};
		$total_det = 0;
		foreach ( $por_estado as $lista_det ) {
			$total_det += count( $lista_det );
		}
		echo '<h2>Detalle de suscripciones en Mercado Pago (IDs)</h2>';
		echo '<p>Solo lectura, con los mismos datos de la consulta de arriba (' . (int) $total_det . ' suscripciones: autorizadas, pausadas y canceladas). Un guion (&mdash;) significa que Mercado Pago no envi&oacute; ese dato.</p>';
		?>
		<style>
			.cn-cruce-ids td, .cn-cruce-ids th { -webkit-user-select: text; user-select: text; vertical-align: middle; }
			.cn-cruce-ids code.cn-id { -webkit-user-select: all; user-select: all; word-break: break-all; }
		</style>
		<table class="widefat striped cn-cruce-ids"><thead><tr><th>ID de preapproval</th><th>external_reference</th><th>Estado</th><th>Plan / reason</th><th>Email del pagador</th><th>Alta</th><th>next_payment_date</th><th>Vincular</th></tr></thead><tbody>
		<?php
		foreach ( array( 'authorized', 'paused', 'cancelled' ) as $estado_det ) {
			foreach ( $por_estado[ $estado_det ] as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$id_det  = $dash( isset( $r['id'] ) ? $r['id'] : '' );
				$ref_det = $dash( isset( $r['external_reference'] ) ? $r['external_reference'] : '' );
				echo '<tr>';
				echo '<td><code class="cn-id">' . esc_html( $id_det ) . '</code>';
				if ( '—' !== $id_det ) {
					echo ' <button type="button" class="button button-small cn-copiar" data-cn-copiar="' . esc_attr( $id_det ) . '">Copiar</button>';
				}
				echo '</td>';
				echo '<td><code class="cn-id">' . esc_html( $ref_det ) . '</code>';
				if ( '—' !== $ref_det ) {
					echo ' <button type="button" class="button button-small cn-copiar" data-cn-copiar="' . esc_attr( $ref_det ) . '">Copiar</button>';
				}
				echo '</td>';
				echo '<td>' . esc_html( $dash( isset( $r['status'] ) ? $r['status'] : '' ) ) . '</td>';
				echo '<td>' . esc_html( $dash( isset( $r['reason'] ) ? $r['reason'] : '' ) ) . '</td>';
				echo '<td>' . esc_html( $dash( isset( $r['payer_email'] ) ? $r['payer_email'] : '' ) ) . '</td>';
				echo '<td>' . esc_html( $dia( isset( $r['date_created'] ) ? $r['date_created'] : '' ) ) . '</td>';
				echo '<td>' . esc_html( $dia( isset( $r['next_payment_date'] ) ? $r['next_payment_date'] : '' ) ) . '</td>';
				// Fase 4-G: enlace que precarga el ID en el bloque "Vincular suscripción" (solo suscripciones autorizadas).
				echo '<td>';
				if ( '—' !== $id_det && 'authorized' === $estado_det && preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $id_det ) ) {
					$url_vinc = add_query_arg( array( 'page' => 'cn-socias', 'cn_vincular_id' => $id_det ), admin_url( 'admin.php' ) ) . '#cn-vincular-mp';
					echo '<a class="button button-small" href="' . esc_url( $url_vinc ) . '">Vincular</a>';
				} else {
					echo '&mdash;';
				}
				echo '</td>';
				echo '</tr>';
			}
		}
		if ( 0 === $total_det ) {
			echo '<tr><td colspan="8">Mercado Pago no devolvi&oacute; suscripciones.</td></tr>';
		}
		?>
		</tbody></table>
		<script>
		document.addEventListener('click', function (e) {
			var btn = e.target && e.target.closest ? e.target.closest('.cn-copiar') : null;
			if (!btn) { return; }
			var txt = btn.getAttribute('data-cn-copiar') || '';
			function listo() {
				btn.textContent = 'Copiado';
				setTimeout(function () { btn.textContent = 'Copiar'; }, 1200);
			}
			function alternativa() {
				var t = document.createElement('textarea');
				t.value = txt;
				t.setAttribute('readonly', '');
				t.style.position = 'fixed';
				t.style.opacity = '0';
				document.body.appendChild(t);
				t.select();
				try { if (document.execCommand('copy')) { listo(); } } catch (err) {}
				document.body.removeChild(t);
			}
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(txt).then(listo, alternativa);
			} else {
				alternativa();
			}
		});
		</script>
		<?php
		echo '</div>';
	}
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
			update_option( 'cn_mp_access_token_trial', sanitize_text_field( wp_unslash( $_POST['cn_mp_access_token_trial'] ?? '' ) ) );
			update_option( 'cn_mp_precio_mensual', (float) sanitize_text_field( wp_unslash( $_POST['cn_mp_precio_mensual'] ?? '0' ) ) );
			update_option( 'cn_mp_precio_trial', (float) sanitize_text_field( wp_unslash( $_POST['cn_mp_precio_trial'] ?? '7000' ) ) );
			update_option( 'cn_mp_razon_plan', sanitize_text_field( wp_unslash( $_POST['cn_mp_razon_plan'] ?? '' ) ) );
			// IDs de plan de MP (alta automática): solo se guardan si el campo vino en el POST.
			foreach ( array( 'basico', 'full' ) as $cn_plan_clave ) {
				if ( isset( $_POST[ 'cn_plan_id_' . $cn_plan_clave ] ) ) {
					update_option( 'cn_plan_id_' . $cn_plan_clave, sanitize_text_field( wp_unslash( $_POST[ 'cn_plan_id_' . $cn_plan_clave ] ) ) );
				}
			}
			// Alta automática por suscripción "sub_": checkbox, apagado por defecto (si no viene tildado, queda en '0').
			update_option( 'cn_sub_auto_activar', isset( $_POST['cn_sub_auto_activar'] ) ? '1' : '0' );
			// Modo seguro: tildado por defecto. Tildado = ningún aviso de MP baja a una socia a "pausado".
			update_option( 'cn_sub_modo_seguro', isset( $_POST['cn_sub_modo_seguro'] ) ? '1' : '0' );
			// Fase 5: sync con Mercado Pago cada 30 min. Tildado por defecto.
			update_option( 'cn_sync_mp_activo', isset( $_POST['cn_sync_mp_activo'] ) ? '1' : '0' );
			// Fase 5: guarda del cron de trials (mira en MP si pagó por link directo antes de cancelar). Tildado por defecto.
			update_option( 'cn_guarda_trial_mp', isset( $_POST['cn_guarda_trial_mp'] ) ? '1' : '0' );
			// Página de retorno de suscripciones por link directo ([cn_retorno_suscripcion]). Apagada por defecto.
			update_option( 'cn_retorno_activo', isset( $_POST['cn_retorno_activo'] ) ? '1' : '0' );
			update_option( CN_Retorno::OPT_WHATSAPP, preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['cn_retorno_whatsapp'] ?? '' ) ) ) ); // solo dígitos
			update_option( 'cn_meta_capi_token', sanitize_text_field( wp_unslash( $_POST['cn_meta_capi_token'] ?? '' ) ) );
			update_option( 'cn_google_drive_api_key', sanitize_text_field( wp_unslash( $_POST['cn_google_drive_api_key'] ?? '' ) ) );
			update_option( 'cn_clase_especial_link', esc_url_raw( wp_unslash( $_POST['cn_clase_especial_link'] ?? '' ) ) );
			update_option( 'cn_admin_alerta_email', sanitize_email( wp_unslash( $_POST['cn_admin_alerta_email'] ?? '' ) ) );
			self::aviso( 'Configuración guardada.' );
		}
		$token        = CN_MP::get_access_token();
		$token_trial  = CN_MP::get_access_token_trial();
		$precio       = CN_MP::get_precio_mensual();
		$precio_trial = CN_MP::get_precio_trial();
		$razon        = CN_MP::get_razon_plan();
		$plan_id_basico = CN_MP::get_plan_id( 'basico' );
		$plan_id_full   = CN_MP::get_plan_id( 'full' );
		$sub_auto_activar = '1' === (string) get_option( 'cn_sub_auto_activar', '0' );
		$sub_modo_seguro  = '1' === (string) get_option( 'cn_sub_modo_seguro', '1' );
		$sync_mp_activo   = '1' === (string) get_option( 'cn_sync_mp_activo', '1' );
		$guarda_trial_mp  = '1' === (string) get_option( 'cn_guarda_trial_mp', '1' );
		$retorno_activo   = '1' === (string) get_option( 'cn_retorno_activo', '0' );
		$retorno_wa       = (string) get_option( CN_Retorno::OPT_WHATSAPP, '' );
		$capi_token   = CN_Meta_Capi::get_token();
		$drive_key    = CN_Drive::get_api_key();
		$clase_link   = get_option( 'cn_clase_especial_link', '' );
		$alerta_email = get_option( 'cn_admin_alerta_email', get_option( 'admin_email' ) );
		?>
		<div class="wrap">
			<h1>Config</h1>
			<form method="post" style="max-width:600px;">
				<?php wp_nonce_field( 'cn_config', 'cn_config_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label>Access Token de Mercado Pago — Suscripciones (producción)</label></th>
						<td><input type="text" name="cn_mp_access_token" class="regular-text" value="<?php echo esc_attr( $token ); ?>"></td>
					</tr>
					<tr>
						<th><label>Access Token de Mercado Pago — Trial / Checkout Pro (producción)</label></th>
						<td>
							<input type="text" name="cn_mp_access_token_trial" class="regular-text" value="<?php echo esc_attr( $token_trial ); ?>">
							<p class="description">
								Token de una app de Mercado Pago DISTINTA a la de Suscripciones — MP exige una app
								por producto integrado, así que el trial (Checkout Pro) necesita su propia app y su
								propio token, aunque sea la misma cuenta de Mercado Pago.
							</p>
						</td>
					</tr>
					<tr>
						<th><label>Precio mensual (ARS)</label></th>
						<td><input type="number" step="0.01" name="cn_mp_precio_mensual" value="<?php echo esc_attr( $precio ); ?>"></td>
					</tr>
					<tr>
						<th><label>Precio del trial de 7 días (ARS)</label></th>
						<td><input type="number" step="0.01" name="cn_mp_precio_trial" value="<?php echo esc_attr( $precio_trial ); ?>"></td>
					</tr>
					<tr>
						<th><label>Razón / título del plan</label></th>
						<td><input type="text" name="cn_mp_razon_plan" class="regular-text" value="<?php echo esc_attr( $razon ); ?>"></td>
					</tr>
					<tr>
						<th><label>ID del plan de Mercado Pago — Club Natureza ($15.500)</label></th>
						<td><input type="text" name="cn_plan_id_basico" class="regular-text" value="<?php echo esc_attr( $plan_id_basico ); ?>"></td>
					</tr>
					<tr>
						<th><label>ID del plan de Mercado Pago — Club Natureza Full ($32.000)</label></th>
						<td>
							<input type="text" name="cn_plan_id_full" class="regular-text" value="<?php echo esc_attr( $plan_id_full ); ?>">
							<p class="description">Todavía no se usan en ningún lado; se cargan para la futura alta automática por suscripción.</p>
						</td>
					</tr>
					<tr>
						<th><label>Alta automática por suscripción (sub_)</label></th>
						<td>
							<label><input type="checkbox" name="cn_sub_auto_activar" value="1" <?php checked( $sub_auto_activar ); ?>> Activar socias solas al autorizarse una suscripción con referencia <code>sub_</code></label>
							<p class="description">Apagado por defecto. Con esto apagado (o sin links <code>sub_</code>) el sistema se comporta exactamente como hasta ahora.</p>
						</td>
					</tr>
					<tr>
						<th><label>Modo seguro (suscripciones)</label></th>
						<td>
							<label><input type="checkbox" name="cn_sub_modo_seguro" value="1" <?php checked( $sub_modo_seguro ); ?>> Que un aviso de Mercado Pago nunca baje a una socia a "pausado"</label>
							<p class="description">Tildado por defecto. Destildarlo hace que cancelaciones y pausas de Mercado Pago pasen a "pausado" al instante en las socias que no son de alta automática. Dejalo tildado.</p>
						</td>
					</tr>
					<tr>
						<th><label>Sincronizar con Mercado Pago (cada 30 min)</label></th>
						<td>
							<label><input type="checkbox" name="cn_sync_mp_activo" value="1" <?php checked( $sync_mp_activo ); ?>> Revisar en Mercado Pago las suscripciones ya vinculadas, sin depender de los webhooks</label>
							<p class="description">Tildado por defecto. Cada 30 minutos revisa hasta 20 suscripciones: renovaciones y reanudaciones se aplican; cancelaciones y pausas solo programan la baja (sin cortar el acceso) si el Modo seguro está destildado. También avisa por n8n de las suscripciones autorizadas sin vincular hace más de 1 hora (no vincula nada).</p>
						</td>
					</tr>
					<tr>
						<th><label>Página de retorno de suscripción</label></th>
						<td>
							<label><input type="checkbox" name="cn_retorno_activo" value="1" <?php checked( $retorno_activo ); ?>> Activar el formulario de la página con <code>[cn_retorno_suscripcion]</code></label>
							<p class="description">Apagado por defecto. Apagado, esa página solo muestra un mensaje de agradecimiento (sin formulario ni consulta a Mercado Pago). Prendido, la clienta que paga por el link directo del plan deja su celular y queda activa sola.</p>
							<p style="margin-top:10px;"><label>WhatsApp para clientas que no logran activarse: <input type="text" name="cn_retorno_whatsapp" value="<?php echo esc_attr( $retorno_wa ); ?>" class="regular-text" placeholder="5491122334455" autocomplete="off"></label></p>
							<p class="description">Número con código de país, solo dígitos (ej. 5491122334455). Después de 3 intentos fallidos la página muestra un botón que abre este WhatsApp con el código de la suscripción. Vacío: no se muestra el botón.</p>
						</td>
					</tr>
					<tr>
						<th><label>Guarda del cron de trials (Mercado Pago)</label></th>
						<td>
							<label><input type="checkbox" name="cn_guarda_trial_mp" value="1" <?php checked( $guarda_trial_mp ); ?>> Antes de cancelar un trial vencido, mirar en Mercado Pago si ya pagó por link directo</label>
							<p class="description">Tildado por defecto. Si encuentra un cobro de $15.500 o más posterior al de $7.000 (o una suscripción autorizada con ese email), no cancela a la socia y avisa por n8n para vincularla. Si Mercado Pago no responde, tampoco cancela y reintenta en la próxima corrida diaria.</p>
						</td>
					</tr>
					<tr>
						<th><label>Meta Conversions API — Token</label></th>
						<td>
							<input type="text" name="cn_meta_capi_token" class="regular-text" value="<?php echo esc_attr( $capi_token ); ?>">
							<p class="description">
								Token de acceso del sistema (System User) del pixel <code>424753682060500</code>,
								generado en Events Manager → Configuración → Conversions API.
								Se usa para mandar el Purchase del trial deduplicado con el Pixel.
							</p>
						</td>
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
					<tr>
						<th><label>Link de la clase especial ("Yo no pinto, pinta el pincel")</label></th>
						<td>
							<input type="url" name="cn_clase_especial_link" class="regular-text" value="<?php echo esc_attr( $clase_link ); ?>" placeholder="https://...">
							<p class="description">Se usa en el aviso automático del día 5 de trial. Mientras esté vacío, el aviso no se manda.</p>
						</td>
					</tr>
					<tr>
						<th><label>Mail para el aviso de día 5 (WhatsApp)</label></th>
						<td>
							<input type="email" name="cn_admin_alerta_email" class="regular-text" value="<?php echo esc_attr( $alerta_email ); ?>">
							<p class="description">Acá llega el aviso con el link wa.me ya armado, para cada socia que llega al día 5.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Guardar configuración', 'primary', 'cn_admin_guardar_config' ); ?>
			</form>
			<h2>Webhooks de Mercado Pago</h2>
			<p>Suscripciones — pegá esta URL en la app de Suscripciones → Webhooks:</p>
			<input type="text" readonly class="regular-text" style="width:100%;max-width:600px;" onclick="this.select();" value="<?php echo esc_url( CN_MP::webhook_url() ); ?>">
			<p style="margin-top:14px;">Trial (Checkout Pro) — esta URL se configura sola en cada Preference que se crea, no hace falta pegarla en ningún lado del panel de MP.</p>
			<input type="text" readonly class="regular-text" style="width:100%;max-width:600px;" onclick="this.select();" value="<?php echo esc_url( CN_MP::trial_webhook_url() ); ?>">
		</div>
		<?php
	}
}
