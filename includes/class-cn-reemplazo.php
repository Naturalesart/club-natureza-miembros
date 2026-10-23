<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * "Reemplazar suscripción" (Socias → Reemplazar suscripción). Para una ficha que YA tiene una suscripción vinculada vieja
 * (cancelada o pausada en Mercado Pago) y la socia se volvió a suscribir: la suscripción nueva (sin vincular) pasa a ser
 * la de la ficha. Se llega desde "Suscripciones sin vincular" (botón "Reemplazar") o a mano.
 *
 * Reglas duras:
 *  - Mercado Pago: SOLO GET (CN_MP::get_con_reintentos). Nunca cancela, pausa ni reembolsa nada; la suscripción vieja
 *    no se toca en MP y su historial queda en mp_log (vinculo_backup + reemplazo_suscripcion).
 *  - Solo manage_options + nonce, de a una ficha por vez, nunca en lote. Sin mails ni eventos Meta (no hay opción para activarlos).
 *  - Cargar la pantalla (GET) no consulta a MP. "Verificar" (POST + nonce) es solo lectura. "Confirmar" revalida TODO en vivo
 *    (no se confía en la vista previa) y escribe en UNA transacción con FOR UPDATE; ante cualquier duda, ROLLBACK.
 *
 * Se habilita Confirmar únicamente si: la nueva está authorized en MP, plan conocido y sin vincular a otra ficha; la vieja se
 * consultó ahora y NO está authorized (cancelled o paused; si está authorized = posible doble pago, no se puede forzar);
 * ambas consultas a MP respondieron; la ficha tiene una vieja distinta de la nueva y no cambió desde la vista previa.
 * Si cambia el plan o el monto hace falta tildar "confirmo el cambio de plan".
 *
 * Hereda de CN_Vinculo_Manual solo para reutilizar backup(), mensaje_http() y los helpers de CN_Webhook; no los modifica.
 */
class CN_Reemplazo extends CN_Vinculo_Manual {
	const NONCE_R  = 'cn_reemplazo';
	const PAGINA   = 'cn-reemplazar';
	const LOG_TIPO = 'reemplazo_suscripcion';
	const RE_VIEJO = '/^[A-Za-z0-9_-]{16,64}$/';

	public static function registrar() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	public static function menu() {
		add_submenu_page( 'cn-socias', 'Reemplazar suscripción', 'Reemplazar suscripción', 'manage_options', self::PAGINA, array( __CLASS__, 'pagina' ) );
	}

	/** Enlace del botón "Reemplazar" de "Suscripciones sin vincular": solo precarga, no consulta ni escribe nada. */
	public static function url( $id_nuevo, $socia_id ) {
		return add_query_arg( array( 'page' => self::PAGINA, 'cn_rp_id' => (string) $id_nuevo, 'cn_rp_socia' => (int) $socia_id ), admin_url( 'admin.php' ) );
	}

	protected static function corto( $id ) {
		return substr( (string) $id, 0, 8 ) . '…';
	}

	/** Foto del estado de la ficha que importa: si cambia entre Verificar y Confirmar, no se escribe nada. */
	public static function foto( $f ) {
		return md5( implode( '|', array(
			(string) $f->preapproval_id, (string) $f->estado, (string) $f->plan, (string) $f->acceso_hasta, (string) $f->baja_en, (string) $f->fecha_fin_trial,
		) ) );
	}

	// ------------------------------------------------------------------ lecturas a MP (solo GET)

	/** Una suscripción de MP leída ahora. 'ok' = respuesta válida de esa suscripción; el resto lo decide quien llama. */
	protected static function leer_sub( $id, &$pres ) {
		$r = array( 'ok' => false, 'error' => '', 'id' => $id, 'status' => '', 'monto' => null, 'plan' => null, 'plan_nombre' => '', 'acceso_hasta' => null, 'proximo' => '', 'alta' => '' );
		$h = CN_MP::get_con_reintentos( '/preapproval/' . rawurlencode( $id ), CN_MP::get_access_token(), $pres );
		if ( ! $h['ok'] ) {
			$r['error'] = 'tope' === $h['http'] ? 'Se agotó el tiempo de consulta a Mercado Pago. Probá de nuevo. No se cambió nada.' : self::mensaje_http( $h['http'] );
			return $r;
		}
		$data = $h['data'];
		if ( ! isset( $data['id'] ) || (string) $data['id'] !== $id ) {
			$r['error'] = 'Mercado Pago devolvió una suscripción con otro ID. No se cambió nada.';
			return $r;
		}
		if ( empty( $data['status'] ) || ! is_string( $data['status'] ) ) {
			$r['error'] = 'Mercado Pago no informó el estado de la suscripción. No se cambió nada.';
			return $r;
		}
		$r['ok']           = true;
		$r['status']       = sanitize_text_field( $data['status'] );
		$r['monto']        = isset( $data['auto_recurring']['transaction_amount'] ) ? $data['auto_recurring']['transaction_amount'] : null;
		$r['acceso_hasta'] = self::acceso_desde_data( $data );
		$r['proximo']      = ( isset( $data['next_payment_date'] ) && is_string( $data['next_payment_date'] ) ) ? substr( sanitize_text_field( $data['next_payment_date'] ), 0, 10 ) : '';
		$r['alta']         = ( isset( $data['date_created'] ) && is_string( $data['date_created'] ) ) ? substr( sanitize_text_field( $data['date_created'] ), 0, 10 ) : '';
		$plan              = self::plan_por_monto( $r['monto'] );
		if ( null !== $plan ) {
			$r['plan']        = $plan;
			$r['plan_nombre'] = (string) CN_MP::get_planes()[ $plan ]['nombre'];
		}
		return $r;
	}

	/** Fecha del último cobro aprobado de la suscripción vieja (informativo: si no se puede leer, no bloquea). '' si no hay. */
	protected static function ultimo_cobro( $id, &$pres ) {
		$h = CN_MP::get_con_reintentos( '/authorized_payments/search?preapproval_id=' . rawurlencode( $id ), CN_MP::get_access_token(), $pres );
		if ( ! $h['ok'] ) {
			return null;
		}
		$ultimo = '';
		foreach ( ( isset( $h['data']['results'] ) && is_array( $h['data']['results'] ) ) ? $h['data']['results'] : array() as $it ) {
			if ( ! is_array( $it ) || ( isset( $it['payment']['status'] ) ? 'approved' !== $it['payment']['status'] : ( ! isset( $it['status'] ) || 'processed' !== $it['status'] ) ) ) {
				continue;
			}
			$f = substr( (string) ( $it['debit_date'] ?? $it['date_created'] ?? '' ), 0, 10 );
			if ( $f > $ultimo ) {
				$ultimo = $f;
			}
		}
		return $ultimo;
	}

	// ------------------------------------------------------------------ evaluación (SOLO LECTURA: MP en vivo + SELECT sin lock)

	/**
	 * Todo lo que hace falta para decidir. 'ok' = se puede confirmar; 'ya_hecho' = la ficha ya apunta a la nueva (nada que escribir).
	 * 'bloqueos' = textos para el admin. No escribe nada.
	 */
	protected static function evaluar( $id_nuevo, $socia_id ) {
		global $wpdb;
		$e = array( 'ok' => false, 'ya_hecho' => false, 'bloqueos' => array(), 'ficha' => null, 'nueva' => null, 'vieja' => null, 'ultimo_cobro' => null, 'cambio_plan' => false, 'foto' => '', 'id_viejo' => '' );
		if ( ! self::id_valido( $id_nuevo ) ) {
			$e['bloqueos'][] = 'El ID de la suscripción nueva tiene que tener 32 caracteres hexadecimales (0-9, a-f).';
			return $e;
		}
		if ( $socia_id <= 0 ) {
			$e['bloqueos'][] = 'Indicá el número de la ficha (id de socia).';
			return $e;
		}
		$t     = CN_DB::tabla( 'miembros' );
		$ficha = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLS . " FROM {$t} WHERE id = %d", $socia_id ) );
		if ( ! $ficha ) {
			$e['bloqueos'][] = 'No existe la ficha #' . $socia_id . '.';
			return $e;
		}
		$e['ficha'] = $ficha;
		$e['foto']  = self::foto( $ficha );
		$viejo      = (string) $ficha->preapproval_id;
		if ( $viejo === $id_nuevo ) {
			$e['ok']       = true;
			$e['ya_hecho'] = true;
			return $e;
		}
		if ( '' === $viejo ) {
			$e['bloqueos'][] = 'La ficha no tiene ninguna suscripción vinculada: para eso usá "Vincular suscripción de Mercado Pago".';
			return $e;
		}
		if ( 1 !== preg_match( self::RE_VIEJO, $viejo ) ) {
			$e['bloqueos'][] = 'El ID de la suscripción vieja de la ficha tiene un formato raro. No se puede consultar: resolvelo a mano.';
			return $e;
		}
		$e['id_viejo'] = $viejo;
		$otra          = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . $t . ' WHERE preapproval_id = %s LIMIT 1', $id_nuevo ) );
		if ( $otra ) {
			$e['bloqueos'][] = 'La suscripción nueva ya está vinculada a la ficha #' . (int) $otra->id . '. No se pisa nada.';
			return $e;
		}
		$pres  = array( 'requests' => 8, 'espera' => 15, 'hasta' => time() + 40 );
		$nueva = self::leer_sub( $id_nuevo, $pres );
		$vieja = self::leer_sub( $viejo, $pres );
		$e['nueva'] = $nueva;
		$e['vieja'] = $vieja;
		if ( ! $nueva['ok'] ) {
			$e['bloqueos'][] = 'Suscripción nueva: ' . $nueva['error'];
		} elseif ( 'authorized' !== $nueva['status'] ) {
			$e['bloqueos'][] = 'La suscripción nueva no está autorizada en Mercado Pago (estado: ' . $nueva['status'] . '). Solo se vincula una autorizada.';
		} elseif ( null === $nueva['plan'] ) {
			$e['bloqueos'][] = 'Plan desconocido: el monto de la suscripción nueva (' . ( is_scalar( $nueva['monto'] ) ? sanitize_text_field( (string) $nueva['monto'] ) : 'ninguno' ) . ') no coincide con ningún plan.';
		}
		if ( ! $vieja['ok'] ) {
			$e['bloqueos'][] = 'Suscripción vieja: ' . $vieja['error'];
		} elseif ( 'authorized' === $vieja['status'] ) {
			$e['bloqueos'][] = 'Las dos suscripciones están activas (authorized) en Mercado Pago: posible doble pago. Resolverlo en Mercado Pago (cancelar la que sobre) y volver a verificar. Acá no se puede forzar.';
		} elseif ( ! in_array( $vieja['status'], array( 'cancelled', 'paused' ), true ) ) {
			$e['bloqueos'][] = 'La suscripción vieja está en un estado que no se puede interpretar (' . $vieja['status'] . '). Solo se reemplaza una cancelada o pausada.';
		}
		if ( $vieja['ok'] ) {
			$e['ultimo_cobro'] = self::ultimo_cobro( $viejo, $pres );
		}
		if ( $nueva['ok'] && $vieja['ok'] && null !== $nueva['plan'] ) {
			$monto_distinto = ! is_numeric( $vieja['monto'] ) || ! is_numeric( $nueva['monto'] ) || abs( (float) $vieja['monto'] - (float) $nueva['monto'] ) > 0.001;
			$e['cambio_plan'] = $monto_distinto || (string) $ficha->plan !== (string) $nueva['plan'];
		}
		// "Otras vinculaciones en curso": otra suscripción sin vincular que también apunta a esta ficha (según la última consulta de "Sin vincular", sin pedir nada a MP).
		$cache = get_transient( CN_Sin_Vincular::TRANSIENT );
		foreach ( ( is_array( $cache ) && ! empty( $cache['filas'] ) ) ? $cache['filas'] : array() as $f ) {
			if ( (string) $f['id'] === $id_nuevo ) {
				continue;
			}
			foreach ( (array) $f['candidatas'] as $c ) {
				if ( (int) $c->id === (int) $ficha->id ) {
					$e['bloqueos'][] = 'Esta ficha tiene otra suscripción sin vincular en curso (' . self::corto( $f['id'] ) . '). Resolvé de a una por vez.';
					break 2;
				}
			}
		}
		$e['ok'] = ! $e['bloqueos'];
		return $e;
	}

	// ------------------------------------------------------------------ pasos

	protected static function datos_rp() {
		$t = function ( $k ) {
			return trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ) );
		};
		return array(
			'id'       => strtolower( $t( 'cn_rp_id' ) ),
			'socia'    => absint( $t( 'cn_rp_socia' ) ),
			'foto'     => $t( 'cn_rp_foto' ),
			'ficha_ok' => '1' === $t( 'cn_rp_ficha_ok' ),
			'plan_ok'  => '1' === $t( 'cn_rp_plan_ok' ),
		);
	}

	/** null si no se envió nuestro formulario; si no, array( 'paso' => error|previsualizado|confirmado, ... ). */
	public static function procesar_reemplazo() {
		if ( ! isset( $_POST['cn_rp_accion'] ) ) {
			return null;
		}
		$d = self::datos_rp();
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'paso' => 'error', 'mensaje' => 'No tenés permisos para esto.', 'datos' => $d );
		}
		if ( ! check_admin_referer( self::NONCE_R, 'cn_rp_nonce' ) ) { // en WordPress corta con wp_die(); esto es el cinturón.
			return array( 'paso' => 'error', 'mensaje' => 'El enlace venció. Recargá la página.', 'datos' => $d );
		}
		$accion = sanitize_key( wp_unslash( $_POST['cn_rp_accion'] ) );
		if ( 'confirmar' === $accion ) {
			return array( 'paso' => 'confirmado', 'datos' => $d, 'resultado' => self::confirmar_reemplazo( $d ) );
		}
		return array( 'paso' => 'previsualizado', 'datos' => $d, 'eval' => self::evaluar( $d['id'], $d['socia'] ) );
	}

	protected static function rechazo( $mensaje ) {
		return array( 'ok' => false, 'accion' => 'rechazada', 'mensaje' => $mensaje . ' No se cambió nada.', 'socia_id' => null );
	}

	/** Confirma el reemplazo. Revalida todo del lado del servidor (MP en vivo incluido). */
	public static function confirmar_reemplazo( $d ) {
		global $wpdb;
		$id = $d['id'];
		$ev = self::evaluar( $id, (int) $d['socia'] );
		if ( ! $ev['ok'] ) {
			return self::rechazo( implode( ' ', $ev['bloqueos'] ) );
		}
		$ficha = $ev['ficha'];
		if ( $ev['ya_hecho'] ) {
			return array( 'ok' => true, 'accion' => 'ya_hecho', 'mensaje' => 'Ya está hecho: la ficha ya tiene esta suscripción. No se cambió nada.', 'socia_id' => (int) $ficha->id );
		}
		if ( ! $d['ficha_ok'] ) {
			return self::rechazo( 'Tildá la confirmación de que es la ficha correcta.' );
		}
		if ( $ev['cambio_plan'] && ! $d['plan_ok'] ) {
			return self::rechazo( 'El plan o el monto cambia: tildá "confirmo el cambio de plan".' );
		}
		if ( '' === $d['foto'] || $d['foto'] !== $ev['foto'] ) {
			return self::rechazo( 'La ficha cambió desde la verificación. Volvé a verificar.' );
		}
		$nueva    = $ev['nueva'];
		$vieja    = $ev['vieja'];
		$viejo_id = $ev['id_viejo'];
		$t        = CN_DB::tabla( 'miembros' );
		$en_tx    = false;
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'no se pudo abrir la transaccion' );
			}
			$en_tx = true;
			$f     = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLS . " FROM {$t} WHERE id = %d FOR UPDATE", (int) $ficha->id ) ); // primero la ficha y después el preapproval (mismo orden que Vincular).
			$otra  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLS . " FROM {$t} WHERE preapproval_id = %s LIMIT 1 FOR UPDATE", $id ) );
			if ( '' !== (string) $wpdb->last_error ) {
				throw new RuntimeException( 'error buscando en la base: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			if ( ! $f ) {
				$wpdb->query( 'ROLLBACK' );
				return self::rechazo( 'La ficha ya no existe.' );
			}
			if ( (string) $f->preapproval_id === $id && ( ! $otra || (int) $otra->id === (int) $f->id ) ) { // otro clic se adelantó.
				$wpdb->query( 'ROLLBACK' );
				return array( 'ok' => true, 'accion' => 'ya_hecho', 'mensaje' => 'Ya está hecho: la ficha ya tiene esta suscripción. No se cambió nada.', 'socia_id' => (int) $f->id );
			}
			if ( $otra ) {
				$wpdb->query( 'ROLLBACK' );
				return self::rechazo( 'La suscripción nueva ya está vinculada a la ficha #' . (int) $otra->id . '.' );
			}
			if ( (string) $f->preapproval_id !== $viejo_id || self::foto( $f ) !== $ev['foto'] ) {
				$wpdb->query( 'ROLLBACK' );
				return self::rechazo( 'Mientras tanto cambió la ficha. Volvé a verificar.' );
			}
			self::backup( $f, $id ); // vinculo_backup con la fila previa (incluye baja_en); lanza si no se pudo guardar.
			$acceso = $nueva['acceso_hasta'];
			if ( ! empty( $f->acceso_hasta ) && ( null === $acceso || (string) $f->acceso_hasta > $acceso ) ) {
				$acceso = (string) $f->acceso_hasta; // nunca se recorta un acceso existente más largo.
			}
			$ahora = current_time( 'mysql', true );
			$ok    = $wpdb->update(
				$t,
				array( 'preapproval_id' => $id, 'plan' => $nueva['plan'], 'estado' => 'activo', 'acceso_hasta' => $acceso, 'baja_en' => null, 'fecha_modificacion' => $ahora ),
				array( 'id' => (int) $f->id, 'preapproval_id' => $viejo_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);
			if ( false === $ok || 0 === (int) $ok ) {
				throw new RuntimeException( 'no se pudo reemplazar la suscripción: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			$usuario = wp_get_current_user();
			self::registrar_log_mp( self::LOG_TIPO, array( // dentro de la transacción: si algo falla, no queda rastro de algo que no pasó.
				'miembro_id'       => (int) $f->id,
				'preapproval_viejo' => $viejo_id,
				'preapproval_nuevo' => $id,
				'estado_mp_viejo'  => $vieja['status'],
				'estado_mp_nuevo'  => $nueva['status'],
				'estado_anterior'  => (string) $f->estado,
				'plan_anterior'    => (string) $f->plan,
				'plan'             => $nueva['plan'],
				'cambio_plan'      => (bool) $ev['cambio_plan'],
				'usuario_id'       => (int) get_current_user_id(),
				'usuario'          => ( $usuario && ! empty( $usuario->user_login ) ) ? (string) $usuario->user_login : '',
			) );
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'no se pudo confirmar la transaccion' );
			}
			$en_tx = false;
		} catch ( Throwable $e ) {
			if ( $en_tx ) {
				$wpdb->query( 'ROLLBACK' );
			}
			self::registrar_log_mp( 'sub_error', array( 'paso' => 'reemplazo_suscripcion', 'external_reference' => 'vinc_' . $id, 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
			return self::rechazo( 'Error interno (quedó registrado en el log de Mercado Pago).' );
		}
		return array(
			'ok'       => true,
			'accion'   => 'reemplazada',
			'mensaje'  => 'Listo: la ficha #' . (int) $f->id . ' ahora tiene la suscripción ' . self::corto( $id ) . ' (antes ' . self::corto( $viejo_id ) . '). La suscripción vieja no se tocó en Mercado Pago. Sin mail ni evento Meta.',
			'socia_id' => (int) $f->id,
		);
	}

	// ------------------------------------------------------------------ pantalla

	public static function pagina() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$res = self::procesar_reemplazo();
		$d   = array( 'id' => '', 'socia' => 0 );
		if ( is_array( $res ) ) {
			$d = $res['datos'];
		} else {
			if ( isset( $_GET['cn_rp_id'] ) && self::id_valido( strtolower( trim( sanitize_text_field( wp_unslash( $_GET['cn_rp_id'] ) ) ) ) ) ) {
				$d['id'] = strtolower( trim( sanitize_text_field( wp_unslash( $_GET['cn_rp_id'] ) ) ) );
			}
			$d['socia'] = isset( $_GET['cn_rp_socia'] ) ? absint( $_GET['cn_rp_socia'] ) : 0;
		}
		echo '<div class="wrap"><h1>Reemplazar suscripción</h1>';
		echo '<p style="max-width:820px;">Para una ficha que ya tiene una suscripción vinculada <strong>vieja</strong> (cancelada o pausada en Mercado Pago) cuando la socia se volvió a suscribir. Se pasa la ficha a la suscripción <strong>nueva</strong>. Primero <em>Verificar</em> (consulta Mercado Pago en vivo, no cambia nada) y después <em>Confirmar</em>. De a una, sin mails ni eventos Meta. La suscripción vieja no se toca en Mercado Pago: si están las dos activas, se resuelve allá.</p>';
		self::render_reemplazo( $res, $d );
		echo '</div>';
	}

	protected static function aviso_rp( $texto, $tipo ) {
		echo '<div class="notice notice-' . esc_attr( $tipo ) . ' inline"><p>' . esc_html( $texto ) . '</p></div>';
	}

	public static function render_reemplazo( $res, $d ) {
		$paso = is_array( $res ) ? $res['paso'] : '';
		if ( 'error' === $paso ) {
			self::aviso_rp( $res['mensaje'], 'error' );
		} elseif ( 'confirmado' === $paso ) {
			$r = $res['resultado'];
			self::aviso_rp( strtoupper( (string) $r['accion'] ) . ': ' . (string) $r['mensaje'], ! empty( $r['ok'] ) ? 'success' : 'error' );
			if ( ! empty( $r['ok'] ) ) {
				$d = array( 'id' => '', 'socia' => 0 );
			}
		} elseif ( 'previsualizado' === $paso ) {
			self::preview_reemplazo( $res['eval'], $d );
		}
		?>
		<form method="post" style="max-width:680px;" action="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGINA ), admin_url( 'admin.php' ) ) ); ?>">
			<?php wp_nonce_field( self::NONCE_R, 'cn_rp_nonce' ); ?>
			<table class="form-table">
				<tr><th><label>Suscripción nueva (ID)</label></th><td><input type="text" name="cn_rp_id" class="regular-text" maxlength="32" pattern="[A-Fa-f0-9]{32}" required value="<?php echo esc_attr( $d['id'] ); ?>"><p class="description">La que está autorizada y sin vincular (32 caracteres).</p></td></tr>
				<tr><th><label>Ficha (número)</label></th><td><input type="number" name="cn_rp_socia" min="1" class="small-text" required value="<?php echo esc_attr( $d['socia'] ? (int) $d['socia'] : '' ); ?>"><p class="description">La ficha que ya tiene la suscripción vieja.</p></td></tr>
			</table>
			<p><button type="submit" name="cn_rp_accion" value="verificar" class="button button-secondary">Verificar</button></p>
		</form>
		<?php
	}

	protected static function fila_sub( $titulo, $s, $extra = '' ) {
		if ( ! $s || empty( $s['ok'] ) ) {
			echo '<tr><th>' . esc_html( $titulo ) . '</th><td><strong>no se pudo consultar</strong></td></tr>';
			return;
		}
		$plan = null !== $s['plan'] ? $s['plan_nombre'] . ' (' . $s['plan'] . ')' : 'plan desconocido';
		echo '<tr><th>' . esc_html( $titulo ) . '</th><td><code>' . esc_html( self::corto( $s['id'] ) ) . '</code> · ' . esc_html( $plan )
			. ' · monto ' . esc_html( is_scalar( $s['monto'] ) ? '$' . (string) $s['monto'] : '—' )
			. ' · estado en MP: <strong>' . esc_html( $s['status'] ) . '</strong>'
			. ' · alta ' . esc_html( '' !== $s['alta'] ? $s['alta'] : '—' )
			. ' · próximo cobro ' . esc_html( '' !== $s['proximo'] ? $s['proximo'] : '—' )
			. esc_html( $extra ) . '</td></tr>';
	}

	protected static function preview_reemplazo( $e, $d ) {
		$f = $e['ficha'];
		if ( $f ) {
			echo '<table class="widefat striped" style="max-width:900px;margin-bottom:12px;"><tbody>';
			echo '<tr><th style="width:200px;">Ficha</th><td>#' . (int) $f->id . ' · <strong>' . esc_html( $f->nombre_apellido ) . '</strong> · ' . esc_html( CN_Vinculo::mascara_celular( $f->celular_hint ) )
				. ' · estado ' . esc_html( $f->estado ) . ' · plan ' . esc_html( '' !== (string) $f->plan ? $f->plan : '—' )
				. ' · acceso hasta ' . esc_html( ! empty( $f->acceso_hasta ) ? substr( (string) $f->acceso_hasta, 0, 10 ) : '—' )
				. ' · baja programada ' . esc_html( ! empty( $f->baja_en ) ? substr( (string) $f->baja_en, 0, 10 ) : 'no' ) . '</td></tr>';
			if ( ! $e['ya_hecho'] ) {
				self::fila_sub( 'Suscripción NUEVA', $e['nueva'] );
				$cobro = $e['ultimo_cobro'];
				self::fila_sub( 'Suscripción VIEJA (hoy en la ficha)', $e['vieja'], ' · último cobro aprobado ' . ( null === $cobro ? 'no se pudo consultar' : ( '' === $cobro ? 'ninguno' : $cobro ) ) );
			}
			echo '</tbody></table>';
		}
		if ( $e['ya_hecho'] ) {
			self::aviso_rp( 'Ya está hecho: la ficha ya tiene esta suscripción. No hay nada para cambiar.', 'success' );
			return;
		}
		if ( ! $e['ok'] ) {
			foreach ( $e['bloqueos'] as $b ) {
				self::aviso_rp( 'No se puede reemplazar: ' . $b, 'error' );
			}
			return;
		}
		?>
		<div class="notice notice-info inline"><p>Al confirmar: la ficha pasa a la suscripción nueva (plan <?php echo esc_html( $e['nueva']['plan'] ); ?>), queda activa, se limpia la baja programada y el acceso nunca se acorta. La suscripción vieja no se toca en Mercado Pago y queda registrada en el log.</p></div>
		<?php if ( $e['cambio_plan'] ) : ?>
			<div class="notice notice-warning inline"><p><strong>Cambia el plan o el monto</strong> respecto de la suscripción vieja / la ficha. Hay que confirmarlo expresamente.</p></div>
		<?php endif; ?>
		<form method="post" style="max-width:820px;margin-bottom:16px;" action="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGINA ), admin_url( 'admin.php' ) ) ); ?>">
			<?php wp_nonce_field( self::NONCE_R, 'cn_rp_nonce' ); ?>
			<input type="hidden" name="cn_rp_id" value="<?php echo esc_attr( $d['id'] ); ?>">
			<input type="hidden" name="cn_rp_socia" value="<?php echo esc_attr( (int) $f->id ); ?>">
			<input type="hidden" name="cn_rp_foto" value="<?php echo esc_attr( $e['foto'] ); ?>">
			<p><label><input type="checkbox" name="cn_rp_ficha_ok" value="1" required> Confirmo que la ficha es <strong>#<?php echo (int) $f->id; ?> <?php echo esc_html( $f->nombre_apellido ); ?></strong> (<?php echo esc_html( CN_Vinculo::mascara_celular( $f->celular_hint ) ); ?>)</label></p>
			<?php if ( $e['cambio_plan'] ) : ?>
				<p><label><input type="checkbox" name="cn_rp_plan_ok" value="1" required> Confirmo el cambio de plan</label></p>
			<?php endif; ?>
			<p><button type="submit" name="cn_rp_accion" value="confirmar" class="button button-primary">Confirmar y reemplazar</button></p>
		</form>
		<?php
	}
}
CN_Reemplazo::registrar(); // se engancha sola: borrar la pantalla = quitar el require_once y este archivo.
