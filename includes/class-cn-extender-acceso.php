<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * "Extender acceso" (Socias → Extender acceso). Amplía el acceso_hasta de UNA ficha (p. ej. una socia que pagó dos meses y el
 * segundo pago cayó en una suscripción no vinculada). Solo manage_options + nonce, de a una ficha por vez.
 *
 * Es lo único que escribe: acceso_hasta y fecha_modificacion (no toca estado, plan, preapproval_id ni baja_en). Cero llamadas a
 * Mercado Pago, cero mails, cero eventos Meta, sin opciones. Verificar es solo lectura; Confirmar revalida todo en una transacción
 * con FOR UPDATE, guarda la fila previa en mp_log (vinculo_backup) y deja el log extension_acceso. Si la ficha cambió entre Verificar
 * y Confirmar, ROLLBACK. Solo extiende (la nueva fecha es posterior a la actual), hasta 400 días hacia adelante, y no sobre fichas canceladas.
 *
 * Quién lee acceso_hasta: el login NO (decide por estado); lo usan el cron de bajas (revocar_bajas_sub_vencidas: cancela si hay baja_en
 * y acceso_hasta ya venció), el espejo de MP (calcula baja_en desde acceso_hasta + gracia) y las renovaciones del webhook/sync, que solo avanzan.
 *
 * Hereda de CN_Vinculo_Manual solo para reutilizar backup() y las constantes; no los modifica.
 */
class CN_Extender_Acceso extends CN_Vinculo_Manual {
	const NONCE_E  = 'cn_extender_acceso';
	const PAGINA   = 'cn-extender-acceso';
	const LOG_TIPO = 'extension_acceso';
	const MAX_DIAS = 400;
	const MAX_MOTIVO = 200;

	public static function registrar() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	public static function menu() {
		add_submenu_page( 'cn-socias', 'Extender acceso', 'Extender acceso', 'manage_options', self::PAGINA, array( __CLASS__, 'pagina' ) );
	}

	/** Foto del estado de la ficha: si cambia entre Verificar y Confirmar, no se escribe nada. */
	public static function foto( $f ) {
		return md5( implode( '|', array(
			(int) $f->id, (string) $f->estado, (string) $f->plan, (string) $f->preapproval_id, (string) $f->acceso_hasta, (string) $f->baja_en, (string) $f->fecha_fin_trial,
		) ) );
	}

	/** 'YYYY-MM-DD' válida -> timestamp UTC del día a la hora del acceso actual (o 00:00:00 si no tiene); null si no es una fecha real. */
	protected static function fecha_completa( $fecha, $actual ) {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		$hora = ( ! empty( $actual ) && false !== strtotime( (string) $actual ) ) ? gmdate( 'H:i:s', strtotime( (string) $actual . ' UTC' ) ) : '00:00:00';
		return $fecha . ' ' . $hora;
	}

	/** Motivos de bloqueo para esta ficha y esta fecha (puro). Se usa en Verificar, en Confirmar y de nuevo con la fila bloqueada. */
	protected static function bloqueos( $f, $nueva, $motivo ) {
		$b = array();
		if ( 'cancelada' === (string) $f->estado ) {
			$b[] = 'La ficha está cancelada: extender el acceso no la reactiva. Usá "Vincular suscripción" o "Reemplazar suscripción".';
		}
		if ( null === $nueva ) {
			$b[] = 'La fecha no es válida (formato AAAA-MM-DD).';
		} else {
			$ts_nueva = strtotime( $nueva . ' UTC' );
			if ( ! empty( $f->acceso_hasta ) && $ts_nueva <= strtotime( (string) $f->acceso_hasta . ' UTC' ) ) {
				$b[] = 'La nueva fecha tiene que ser POSTERIOR al acceso actual (' . substr( (string) $f->acceso_hasta, 0, 10 ) . '): esto solo extiende, nunca recorta.';
			}
			if ( $ts_nueva > strtotime( current_time( 'mysql', true ) . ' UTC' ) + self::MAX_DIAS * DAY_IN_SECONDS ) {
				$b[] = 'La nueva fecha supera el máximo de ' . self::MAX_DIAS . ' días hacia adelante.';
			}
		}
		if ( '' === $motivo ) {
			$b[] = 'El motivo es obligatorio.';
		} elseif ( mb_strlen( $motivo ) > self::MAX_MOTIVO ) {
			$b[] = 'El motivo es demasiado largo (máximo ' . self::MAX_MOTIVO . ' caracteres).';
		}
		return $b;
	}

	protected static function leer( $id, $bloquear = false ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLS . ' FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE id = %d' . ( $bloquear ? ' FOR UPDATE' : '' ), (int) $id ) );
	}

	/** SOLO LECTURA. 'ok' = se puede confirmar. 'igual' = el acceso ya es exactamente esa fecha. */
	protected static function evaluar( $d ) {
		$e = array( 'ok' => false, 'igual' => false, 'bloqueos' => array(), 'ficha' => null, 'nueva' => null, 'foto' => '' );
		if ( $d['socia'] <= 0 ) {
			$e['bloqueos'][] = 'Indicá el número de la ficha.';
			return $e;
		}
		$f = self::leer( $d['socia'] );
		if ( ! $f ) {
			$e['bloqueos'][] = 'No existe la ficha #' . (int) $d['socia'] . '.';
			return $e;
		}
		$e['ficha']    = $f;
		$e['foto']     = self::foto( $f );
		$e['nueva']    = self::fecha_completa( $d['fecha'], $f->acceso_hasta );
		$e['igual']    = null !== $e['nueva'] && ! empty( $f->acceso_hasta ) && $e['nueva'] === (string) $f->acceso_hasta;
		$e['bloqueos'] = self::bloqueos( $f, $e['nueva'], $d['motivo'] );
		$e['ok']       = ! $e['bloqueos'];
		return $e;
	}

	protected static function datos_ea() {
		$t = function ( $k ) {
			return trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ) );
		};
		return array( 'socia' => absint( $t( 'cn_ea_socia' ) ), 'fecha' => $t( 'cn_ea_fecha' ), 'motivo' => $t( 'cn_ea_motivo' ), 'foto' => $t( 'cn_ea_foto' ) );
	}

	/** null si no se envió nuestro formulario; si no, array( 'paso' => error|previsualizado|confirmado, ... ). */
	public static function procesar_ea() {
		if ( ! isset( $_POST['cn_ea_accion'] ) ) {
			return null;
		}
		$d = self::datos_ea();
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'paso' => 'error', 'mensaje' => 'No tenés permisos para esto.', 'datos' => $d );
		}
		if ( ! check_admin_referer( self::NONCE_E, 'cn_ea_nonce' ) ) { // en WordPress corta con wp_die(); esto es el cinturón.
			return array( 'paso' => 'error', 'mensaje' => 'El enlace venció. Recargá la página.', 'datos' => $d );
		}
		if ( 'confirmar' === sanitize_key( wp_unslash( $_POST['cn_ea_accion'] ) ) ) {
			return array( 'paso' => 'confirmado', 'datos' => $d, 'resultado' => self::confirmar_ea( $d ) );
		}
		return array( 'paso' => 'previsualizado', 'datos' => $d, 'eval' => self::evaluar( $d ) );
	}

	protected static function rechazo_ea( $mensaje ) {
		return array( 'ok' => false, 'accion' => 'rechazada', 'mensaje' => $mensaje . ' No se cambió nada.', 'socia_id' => null );
	}

	protected static function ya_hecho_ea( $f ) {
		return array( 'ok' => true, 'accion' => 'ya_hecho', 'mensaje' => 'Ya está hecho: la ficha ya tiene ese acceso. No se cambió nada.', 'socia_id' => (int) $f->id );
	}

	/** Confirma la extensión. Revalida todo del lado del servidor. */
	public static function confirmar_ea( $d ) {
		global $wpdb;
		$ev = self::evaluar( $d );
		if ( $ev['ficha'] && $ev['igual'] && '' !== $d['foto'] && $d['foto'] !== $ev['foto'] && '' !== $d['motivo'] ) {
			return self::ya_hecho_ea( $ev['ficha'] ); // doble clic: el primero ya escribió y por eso la foto cambió.
		}
		if ( ! $ev['ok'] ) {
			return self::rechazo_ea( implode( ' ', $ev['bloqueos'] ) );
		}
		if ( '' === $d['foto'] || $d['foto'] !== $ev['foto'] ) {
			return self::rechazo_ea( 'La ficha cambió desde la verificación. Volvé a verificar.' );
		}
		$nueva = $ev['nueva'];
		$en_tx = false;
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'no se pudo abrir la transaccion' );
			}
			$en_tx = true;
			$f     = self::leer( $d['socia'], true );
			if ( '' !== (string) $wpdb->last_error ) {
				throw new RuntimeException( 'error buscando en la base: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			if ( ! $f ) {
				$wpdb->query( 'ROLLBACK' );
				return self::rechazo_ea( 'La ficha ya no existe.' );
			}
			if ( ! empty( $f->acceso_hasta ) && (string) $f->acceso_hasta === $nueva ) { // otro clic se adelantó.
				$wpdb->query( 'ROLLBACK' );
				return self::ya_hecho_ea( $f );
			}
			if ( self::foto( $f ) !== $d['foto'] || self::bloqueos( $f, $nueva, $d['motivo'] ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::rechazo_ea( 'Mientras tanto cambió la ficha. Volvé a verificar.' );
			}
			self::backup( $f, (string) $f->preapproval_id ); // vinculo_backup con la fila previa; lanza si no se pudo guardar.
			$ahora = current_time( 'mysql', true );
			$ok    = $wpdb->update( CN_DB::tabla( 'miembros' ), array( 'acceso_hasta' => $nueva, 'fecha_modificacion' => $ahora ), array( 'id' => (int) $f->id ), array( '%s', '%s' ), array( '%d' ) );
			if ( false === $ok ) {
				throw new RuntimeException( 'no se pudo extender el acceso: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			$usuario = wp_get_current_user();
			self::registrar_log_mp( self::LOG_TIPO, array(
				'miembro_id'      => (int) $f->id,
				'acceso_anterior' => empty( $f->acceso_hasta ) ? null : (string) $f->acceso_hasta,
				'acceso_nuevo'    => $nueva,
				'motivo'          => $d['motivo'],
				'usuario_id'      => (int) get_current_user_id(),
				'usuario'         => ( $usuario && ! empty( $usuario->user_login ) ) ? (string) $usuario->user_login : '',
			) );
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'no se pudo confirmar la transaccion' );
			}
			$en_tx = false;
		} catch ( Throwable $e ) {
			if ( $en_tx ) {
				$wpdb->query( 'ROLLBACK' );
			}
			self::registrar_log_mp( 'sub_error', array( 'paso' => 'extension_acceso', 'miembro_id' => (int) $d['socia'], 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
			return self::rechazo_ea( 'Error interno (quedó registrado en el log de Mercado Pago).' );
		}
		return array( 'ok' => true, 'accion' => 'extendido', 'mensaje' => 'Listo: el acceso de la ficha #' . (int) $f->id . ' ahora llega hasta ' . substr( $nueva, 0, 10 ) . '. No se tocó estado, plan, suscripción ni baja programada.', 'socia_id' => (int) $f->id );
	}

	// ------------------------------------------------------------------ pantalla

	public static function pagina() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$res = self::procesar_ea();
		$d   = is_array( $res ) ? $res['datos'] : array( 'socia' => 0, 'fecha' => '', 'motivo' => '', 'foto' => '' );
		echo '<div class="wrap"><h1>Extender acceso</h1>';
		echo '<p style="max-width:820px;">Amplía la fecha de <code>acceso_hasta</code> de <strong>una</strong> ficha (por ejemplo, un pago que cayó en una suscripción sin vincular). Solo extiende: la fecha nueva tiene que ser posterior a la actual, hasta ' . (int) self::MAX_DIAS . ' días hacia adelante. No cambia estado, plan, suscripción ni baja programada, no consulta Mercado Pago y no manda mails ni eventos Meta. Primero <em>Verificar</em> y después <em>Confirmar</em>.</p>';
		self::render_ea( $res, $d );
		echo '</div>';
	}

	protected static function aviso_ea( $texto, $tipo ) {
		echo '<div class="notice notice-' . esc_attr( $tipo ) . ' inline"><p>' . esc_html( $texto ) . '</p></div>';
	}

	public static function render_ea( $res, $d ) {
		$paso = is_array( $res ) ? $res['paso'] : '';
		if ( 'error' === $paso ) {
			self::aviso_ea( $res['mensaje'], 'error' );
		} elseif ( 'confirmado' === $paso ) {
			$r = $res['resultado'];
			self::aviso_ea( strtoupper( (string) $r['accion'] ) . ': ' . (string) $r['mensaje'], ! empty( $r['ok'] ) ? 'success' : 'error' );
			if ( ! empty( $r['ok'] ) ) {
				$d = array( 'socia' => 0, 'fecha' => '', 'motivo' => '', 'foto' => '' );
			}
		} elseif ( 'previsualizado' === $paso ) {
			self::preview_ea( $res['eval'], $d );
		}
		?>
		<form method="post" style="max-width:680px;" action="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGINA ), admin_url( 'admin.php' ) ) ); ?>">
			<?php wp_nonce_field( self::NONCE_E, 'cn_ea_nonce' ); ?>
			<table class="form-table">
				<tr><th><label>Ficha (número)</label></th><td><input type="number" name="cn_ea_socia" min="1" class="small-text" required value="<?php echo esc_attr( $d['socia'] ? (int) $d['socia'] : '' ); ?>"></td></tr>
				<tr><th><label>Nueva fecha de acceso</label></th><td><input type="text" name="cn_ea_fecha" placeholder="AAAA-MM-DD" pattern="\d{4}-\d{2}-\d{2}" maxlength="10" required value="<?php echo esc_attr( $d['fecha'] ); ?>"></td></tr>
				<tr><th><label>Motivo</label></th><td><input type="text" name="cn_ea_motivo" class="large-text" maxlength="<?php echo (int) self::MAX_MOTIVO; ?>" required value="<?php echo esc_attr( $d['motivo'] ); ?>"><p class="description">Obligatorio (máx. <?php echo (int) self::MAX_MOTIVO; ?> caracteres). Queda en el log.</p></td></tr>
			</table>
			<p><button type="submit" name="cn_ea_accion" value="verificar" class="button button-secondary">Verificar</button></p>
		</form>
		<?php
	}

	protected static function preview_ea( $e, $d ) {
		$f = $e['ficha'];
		if ( $f ) {
			echo '<table class="widefat striped" style="max-width:900px;margin-bottom:12px;"><tbody>';
			echo '<tr><th style="width:200px;">Ficha</th><td>#' . (int) $f->id . ' · <strong>' . esc_html( $f->nombre_apellido ) . '</strong> · ' . esc_html( CN_Vinculo::mascara_celular( $f->celular_hint ) )
				. ' · estado ' . esc_html( $f->estado ) . ' · plan ' . esc_html( '' !== (string) $f->plan ? $f->plan : '—' )
				. ' · suscripción ' . esc_html( '' !== (string) $f->preapproval_id ? substr( (string) $f->preapproval_id, 0, 8 ) . '…' : 'ninguna' )
				. ' · baja programada ' . esc_html( ! empty( $f->baja_en ) ? substr( (string) $f->baja_en, 0, 10 ) : 'no' ) . '</td></tr>';
			echo '<tr><th>Cambio propuesto</th><td>acceso hasta <strong>' . esc_html( ! empty( $f->acceso_hasta ) ? substr( (string) $f->acceso_hasta, 0, 10 ) : '—' ) . '</strong> → <strong>' . esc_html( null !== $e['nueva'] ? substr( $e['nueva'], 0, 10 ) : '—' ) . '</strong> (UTC)</td></tr>';
			echo '<tr><th>Motivo</th><td>' . esc_html( $d['motivo'] ) . '</td></tr></tbody></table>';
		}
		if ( ! $e['ok'] ) {
			foreach ( $e['bloqueos'] as $b ) {
				self::aviso_ea( 'No se puede extender: ' . $b, 'error' );
			}
			return;
		}
		?>
		<form method="post" style="max-width:820px;margin-bottom:16px;" action="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGINA ), admin_url( 'admin.php' ) ) ); ?>">
			<?php wp_nonce_field( self::NONCE_E, 'cn_ea_nonce' ); ?>
			<input type="hidden" name="cn_ea_socia" value="<?php echo esc_attr( (int) $f->id ); ?>">
			<input type="hidden" name="cn_ea_fecha" value="<?php echo esc_attr( $d['fecha'] ); ?>">
			<input type="hidden" name="cn_ea_motivo" value="<?php echo esc_attr( $d['motivo'] ); ?>">
			<input type="hidden" name="cn_ea_foto" value="<?php echo esc_attr( $e['foto'] ); ?>">
			<p><button type="submit" name="cn_ea_accion" value="confirmar" class="button button-primary">Confirmar extensión</button></p>
		</form>
		<?php
	}
}
CN_Extender_Acceso::registrar(); // se engancha sola: borrar la pantalla = quitar el require_once y este archivo.
