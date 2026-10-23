<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * "Corregir vínculo" (Socias → Corregir vínculo). En UNA ficha, cambia la suscripción vinculada A por otra suscripción B
 * (por ejemplo, la ficha quedó vinculada a la suscripción equivocada). Mismo esquema que "Reemplazar suscripción".
 *
 * Reglas duras:
 *  - Mercado Pago: SOLO GET (CN_MP::get_con_reintentos). No cancela, pausa ni reembolsa nada; A queda sin vincular y en MP no se toca.
 *  - Solo manage_options + nonce, de a una ficha. Sin mails ni eventos Meta (no hay opción para activarlos). Sin opciones.
 *  - Lo único que se escribe en la ficha es preapproval_id (= B) y fecha_modificacion. NO toca acceso_hasta, estado, baja_en, plan,
 *    fecha de alta ni fecha_fin_trial.
 *  - Verificar es solo lectura. Confirmar revalida TODO en vivo (MP incluido) y escribe en UNA transacción con FOR UPDATE:
 *    backup de la fila (vinculo_backup) + log corregir_vinculo (con A, B y la ficha: de ahí se revierte). Ante cualquier duda, ROLLBACK.
 *
 * Se habilita Confirmar solo si: A es la suscripción vinculada hoy a esa ficha; B no está vinculada a ninguna otra ficha; B está
 * authorized en MP con un plan conocido igual al de la ficha; MP respondió y es inequívoco para A y para B; la ficha no cambió.
 * Al confirmar se borran las cachés de "Suscripciones sin vincular".
 *
 * Hereda de CN_Vinculo_Manual solo para reutilizar backup(), mensaje_http() y los helpers de CN_Webhook; no los modifica.
 */
class CN_Corregir_Vinculo extends CN_Vinculo_Manual {
	const NONCE_C  = 'cn_corregir_vinculo';
	const PAGINA   = 'cn-corregir-vinculo';
	const LOG_TIPO = 'corregir_vinculo';

	public static function registrar() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	public static function menu() {
		add_submenu_page( 'cn-socias', 'Corregir vínculo', 'Corregir vínculo', 'manage_options', self::PAGINA, array( __CLASS__, 'pagina' ) );
	}

	protected static function corto( $id ) {
		return substr( (string) $id, 0, 8 ) . '…';
	}

	/** Foto del estado de la ficha: si cambia entre Verificar y Confirmar, no se escribe nada. */
	public static function foto( $f ) {
		return md5( implode( '|', array(
			(int) $f->id, (string) $f->preapproval_id, (string) $f->estado, (string) $f->plan, (string) $f->acceso_hasta, (string) $f->baja_en, (string) $f->fecha_fin_trial,
		) ) );
	}

	// ------------------------------------------------------------------ lecturas a MP (solo GET)

	protected static function leer_sub( $id, &$pres ) {
		$r = array( 'ok' => false, 'error' => '', 'id' => $id, 'status' => '', 'monto' => null, 'plan' => null, 'plan_nombre' => '', 'email' => '', 'payer_id' => '', 'proximo' => '', 'alta' => '' );
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
		$r['ok']       = true;
		$r['status']   = sanitize_text_field( $data['status'] );
		$r['monto']    = isset( $data['auto_recurring']['transaction_amount'] ) ? $data['auto_recurring']['transaction_amount'] : null;
		$r['email']    = ( isset( $data['payer_email'] ) && is_string( $data['payer_email'] ) ) ? sanitize_email( trim( $data['payer_email'] ) ) : '';
		$r['payer_id'] = ( isset( $data['payer_id'] ) && is_scalar( $data['payer_id'] ) ) ? sanitize_text_field( (string) $data['payer_id'] ) : '';
		$r['proximo']  = ( isset( $data['next_payment_date'] ) && is_string( $data['next_payment_date'] ) ) ? substr( sanitize_text_field( $data['next_payment_date'] ), 0, 10 ) : '';
		$r['alta']     = ( isset( $data['date_created'] ) && is_string( $data['date_created'] ) ) ? substr( sanitize_text_field( $data['date_created'] ), 0, 10 ) : '';
		$plan          = self::plan_por_monto( $r['monto'] );
		if ( null !== $plan ) {
			$r['plan']        = $plan;
			$r['plan_nombre'] = (string) CN_MP::get_planes()[ $plan ]['nombre'];
		}
		return $r;
	}

	/** Fecha del último cobro aprobado (informativo: si no se puede leer, no bloquea). null = no se pudo consultar; '' = ninguno. */
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

	/** 'ok' = se puede confirmar; 'ya_hecho' = la ficha ya apunta a B. No escribe nada. */
	protected static function evaluar( $d ) {
		global $wpdb;
		$e = array( 'ok' => false, 'ya_hecho' => false, 'bloqueos' => array(), 'ficha' => null, 'a' => null, 'b' => null, 'cobro_a' => null, 'cobro_b' => null, 'foto' => '' );
		if ( ! self::id_valido( $d['a'] ) || ! self::id_valido( $d['b'] ) ) {
			$e['bloqueos'][] = 'Los dos IDs de suscripción tienen que tener 32 caracteres hexadecimales (0-9, a-f).';
			return $e;
		}
		if ( $d['a'] === $d['b'] ) {
			$e['bloqueos'][] = 'La suscripción actual y la nueva son la misma.';
			return $e;
		}
		if ( $d['socia'] <= 0 ) {
			$e['bloqueos'][] = 'Indicá el número de la ficha (id de socia).';
			return $e;
		}
		$t     = CN_DB::tabla( 'miembros' );
		$ficha = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLS . " FROM {$t} WHERE id = %d", $d['socia'] ) );
		if ( ! $ficha ) {
			$e['bloqueos'][] = 'No existe la ficha #' . (int) $d['socia'] . '.';
			return $e;
		}
		$e['ficha'] = $ficha;
		$e['foto']  = self::foto( $ficha );
		if ( (string) $ficha->preapproval_id === $d['b'] ) {
			$e['ok']       = true;
			$e['ya_hecho'] = true;
			return $e;
		}
		if ( (string) $ficha->preapproval_id !== $d['a'] ) {
			$e['bloqueos'][] = 'La suscripción actual indicada (' . self::corto( $d['a'] ) . ') no es la que tiene hoy la ficha (' . ( '' !== (string) $ficha->preapproval_id ? self::corto( $ficha->preapproval_id ) : 'ninguna' ) . ').';
			return $e;
		}
		$otra = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . $t . ' WHERE preapproval_id = %s LIMIT 1', $d['b'] ) );
		if ( $otra ) {
			$e['bloqueos'][] = 'La suscripción nueva ya está vinculada a la ficha #' . (int) $otra->id . '. No se pisa nada.';
			return $e;
		}
		$pres = array( 'requests' => 8, 'espera' => 15, 'hasta' => time() + 40 );
		$a    = self::leer_sub( $d['a'], $pres );
		$b    = self::leer_sub( $d['b'], $pres );
		$e['a'] = $a;
		$e['b'] = $b;
		if ( ! $a['ok'] ) {
			$e['bloqueos'][] = 'Suscripción actual: ' . $a['error'];
		}
		if ( ! $b['ok'] ) {
			$e['bloqueos'][] = 'Suscripción nueva: ' . $b['error'];
		} elseif ( 'authorized' !== $b['status'] ) {
			$e['bloqueos'][] = 'La suscripción nueva no está autorizada en Mercado Pago (estado: ' . $b['status'] . ').';
		} elseif ( null === $b['plan'] ) {
			$e['bloqueos'][] = 'Plan desconocido: el monto de la suscripción nueva (' . ( is_scalar( $b['monto'] ) ? sanitize_text_field( (string) $b['monto'] ) : 'ninguno' ) . ') no coincide con ningún plan.';
		} elseif ( (string) $b['plan'] !== (string) $ficha->plan ) {
			$e['bloqueos'][] = 'El plan de la suscripción nueva (' . $b['plan'] . ') es distinto al de la ficha (' . ( '' !== (string) $ficha->plan ? $ficha->plan : 'ninguno' ) . '). Corregir vínculo no cambia planes.';
		}
		if ( $a['ok'] ) {
			$e['cobro_a'] = self::ultimo_cobro( $d['a'], $pres );
		}
		if ( $b['ok'] ) {
			$e['cobro_b'] = self::ultimo_cobro( $d['b'], $pres );
		}
		$e['ok'] = ! $e['bloqueos'];
		return $e;
	}

	// ------------------------------------------------------------------ pasos

	protected static function datos_cv() {
		$t = function ( $k ) {
			return trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ) );
		};
		return array(
			'socia'    => absint( $t( 'cn_cv_socia' ) ),
			'a'        => strtolower( $t( 'cn_cv_a' ) ),
			'b'        => strtolower( $t( 'cn_cv_b' ) ),
			'foto'     => $t( 'cn_cv_foto' ),
			'ficha_ok' => '1' === $t( 'cn_cv_ficha_ok' ),
		);
	}

	/** null si no se envió nuestro formulario; si no, array( 'paso' => error|previsualizado|confirmado, ... ). */
	public static function procesar_cv() {
		if ( ! isset( $_POST['cn_cv_accion'] ) ) {
			return null;
		}
		$d = self::datos_cv();
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'paso' => 'error', 'mensaje' => 'No tenés permisos para esto.', 'datos' => $d );
		}
		if ( ! check_admin_referer( self::NONCE_C, 'cn_cv_nonce' ) ) { // en WordPress corta con wp_die(); esto es el cinturón.
			return array( 'paso' => 'error', 'mensaje' => 'El enlace venció. Recargá la página.', 'datos' => $d );
		}
		if ( 'confirmar' === sanitize_key( wp_unslash( $_POST['cn_cv_accion'] ) ) ) {
			return array( 'paso' => 'confirmado', 'datos' => $d, 'resultado' => self::confirmar_cv( $d ) );
		}
		return array( 'paso' => 'previsualizado', 'datos' => $d, 'eval' => self::evaluar( $d ) );
	}

	protected static function rechazo_cv( $mensaje ) {
		return array( 'ok' => false, 'accion' => 'rechazada', 'mensaje' => $mensaje . ' No se cambió nada.', 'socia_id' => null );
	}

	protected static function ya_hecho_cv( $f ) {
		return array( 'ok' => true, 'accion' => 'ya_hecho', 'mensaje' => 'Ya está hecho: la ficha ya tiene esa suscripción. No se cambió nada.', 'socia_id' => (int) $f->id );
	}

	/** Confirma la corrección. Revalida todo del lado del servidor (MP en vivo incluido). */
	public static function confirmar_cv( $d ) {
		global $wpdb;
		$ev = self::evaluar( $d );
		if ( ! $ev['ok'] ) {
			return self::rechazo_cv( implode( ' ', $ev['bloqueos'] ) );
		}
		if ( $ev['ya_hecho'] ) {
			return self::ya_hecho_cv( $ev['ficha'] );
		}
		if ( ! $d['ficha_ok'] ) {
			return self::rechazo_cv( 'Tildá la confirmación de que es la ficha correcta.' );
		}
		if ( '' === $d['foto'] || $d['foto'] !== $ev['foto'] ) {
			return self::rechazo_cv( 'La ficha cambió desde la verificación. Volvé a verificar.' );
		}
		$t     = CN_DB::tabla( 'miembros' );
		$en_tx = false;
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'no se pudo abrir la transaccion' );
			}
			$en_tx = true;
			$f     = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLS . " FROM {$t} WHERE id = %d FOR UPDATE", (int) $d['socia'] ) ); // primero la ficha y después el preapproval (mismo orden que Vincular).
			$otra  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLS . " FROM {$t} WHERE preapproval_id = %s LIMIT 1 FOR UPDATE", $d['b'] ) );
			if ( '' !== (string) $wpdb->last_error ) {
				throw new RuntimeException( 'error buscando en la base: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			if ( ! $f ) {
				$wpdb->query( 'ROLLBACK' );
				return self::rechazo_cv( 'La ficha ya no existe.' );
			}
			if ( (string) $f->preapproval_id === $d['b'] && ( ! $otra || (int) $otra->id === (int) $f->id ) ) { // otro clic se adelantó.
				$wpdb->query( 'ROLLBACK' );
				return self::ya_hecho_cv( $f );
			}
			if ( $otra ) {
				$wpdb->query( 'ROLLBACK' );
				return self::rechazo_cv( 'La suscripción nueva ya está vinculada a la ficha #' . (int) $otra->id . '.' );
			}
			if ( (string) $f->preapproval_id !== $d['a'] || self::foto( $f ) !== $ev['foto'] ) {
				$wpdb->query( 'ROLLBACK' );
				return self::rechazo_cv( 'Mientras tanto cambió la ficha. Volvé a verificar.' );
			}
			self::backup( $f, $d['b'] ); // vinculo_backup con la fila previa (incluye A); lanza si no se pudo guardar.
			$ok = $wpdb->update(
				$t,
				array( 'preapproval_id' => $d['b'], 'fecha_modificacion' => current_time( 'mysql', true ) ),
				array( 'id' => (int) $f->id, 'preapproval_id' => $d['a'] ),
				array( '%s', '%s' ),
				array( '%d', '%s' )
			);
			if ( false === $ok || 0 === (int) $ok ) {
				throw new RuntimeException( 'no se pudo corregir el vínculo: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			$usuario = wp_get_current_user();
			self::registrar_log_mp( self::LOG_TIPO, array( // dentro de la transacción: si algo falla, no queda rastro de algo que no pasó.
				'miembro_id'        => (int) $f->id,
				'preapproval_anterior' => $d['a'],
				'preapproval_nuevo' => $d['b'],
				'estado_mp_anterior' => $ev['a']['status'],
				'estado_mp_nuevo'   => $ev['b']['status'],
				'plan'              => $ev['b']['plan'],
				'usuario_id'        => (int) get_current_user_id(),
				'usuario'           => ( $usuario && ! empty( $usuario->user_login ) ) ? (string) $usuario->user_login : '',
			) );
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'no se pudo confirmar la transaccion' );
			}
			$en_tx = false;
		} catch ( Throwable $e ) {
			if ( $en_tx ) {
				$wpdb->query( 'ROLLBACK' );
			}
			self::registrar_log_mp( 'sub_error', array( 'paso' => 'corregir_vinculo', 'external_reference' => 'vinc_' . $d['b'], 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
			return self::rechazo_cv( 'Error interno (quedó registrado en el log de Mercado Pago).' );
		}
		delete_transient( CN_Sin_Vincular::TRANSIENT ); // la lista de "Sin vincular" ya no es válida (A pasó a estar sin vincular, B ya no).
		delete_transient( CN_Sin_Vincular::TRANSIENT_TITULARES );
		return array(
			'ok'       => true,
			'accion'   => 'corregida',
			'mensaje'  => 'Listo: la ficha #' . (int) $f->id . ' ahora tiene la suscripción ' . self::corto( $d['b'] ) . ' (antes ' . self::corto( $d['a'] ) . '; queda sin vincular). No se tocó acceso, estado, plan ni baja programada, ni nada en Mercado Pago. Sin mail ni evento Meta.',
			'socia_id' => (int) $f->id,
		);
	}

	// ------------------------------------------------------------------ pantalla

	public static function pagina() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$res = self::procesar_cv();
		$d   = is_array( $res ) ? $res['datos'] : array( 'socia' => 0, 'a' => '', 'b' => '', 'foto' => '' );
		echo '<div class="wrap"><h1>Corregir vínculo</h1>';
		echo '<p style="max-width:820px;">En <strong>una</strong> ficha, cambia la suscripción vinculada <strong>A</strong> por otra suscripción <strong>B</strong> (A queda sin vincular). Solo cambia el vínculo: no toca acceso, estado, plan ni baja programada, y no modifica nada en Mercado Pago (solo lo consulta). Sin mails ni eventos Meta. Primero <em>Verificar</em> (consulta Mercado Pago en vivo) y después <em>Confirmar</em>.</p>';
		self::render_cv( $res, $d );
		echo '</div>';
	}

	protected static function aviso_cv( $texto, $tipo ) {
		echo '<div class="notice notice-' . esc_attr( $tipo ) . ' inline"><p>' . esc_html( $texto ) . '</p></div>';
	}

	public static function render_cv( $res, $d ) {
		$paso = is_array( $res ) ? $res['paso'] : '';
		if ( 'error' === $paso ) {
			self::aviso_cv( $res['mensaje'], 'error' );
		} elseif ( 'confirmado' === $paso ) {
			$r = $res['resultado'];
			self::aviso_cv( strtoupper( (string) $r['accion'] ) . ': ' . (string) $r['mensaje'], ! empty( $r['ok'] ) ? 'success' : 'error' );
			if ( ! empty( $r['ok'] ) ) {
				$d = array( 'socia' => 0, 'a' => '', 'b' => '', 'foto' => '' );
			}
		} elseif ( 'previsualizado' === $paso ) {
			self::preview_cv( $res['eval'], $d );
		}
		?>
		<form method="post" style="max-width:680px;" action="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGINA ), admin_url( 'admin.php' ) ) ); ?>">
			<?php wp_nonce_field( self::NONCE_C, 'cn_cv_nonce' ); ?>
			<table class="form-table">
				<tr><th><label>Ficha (número)</label></th><td><input type="number" name="cn_cv_socia" min="1" class="small-text" required value="<?php echo esc_attr( $d['socia'] ? (int) $d['socia'] : '' ); ?>"></td></tr>
				<tr><th><label>Suscripción actual (A)</label></th><td><input type="text" name="cn_cv_a" class="regular-text" maxlength="32" pattern="[A-Fa-f0-9]{32}" required value="<?php echo esc_attr( $d['a'] ); ?>"><p class="description">La que hoy tiene la ficha (32 caracteres).</p></td></tr>
				<tr><th><label>Suscripción nueva (B)</label></th><td><input type="text" name="cn_cv_b" class="regular-text" maxlength="32" pattern="[A-Fa-f0-9]{32}" required value="<?php echo esc_attr( $d['b'] ); ?>"><p class="description">Autorizada en Mercado Pago, sin vincular a otra ficha y del mismo plan.</p></td></tr>
			</table>
			<p><button type="submit" name="cn_cv_accion" value="verificar" class="button button-secondary">Verificar</button></p>
		</form>
		<?php
	}

	protected static function fila_sub( $titulo, $s, $cobro ) {
		if ( ! $s || empty( $s['ok'] ) ) {
			echo '<tr><th>' . esc_html( $titulo ) . '</th><td><strong>no se pudo consultar</strong></td></tr>';
			return;
		}
		$plan = null !== $s['plan'] ? $s['plan_nombre'] . ' (' . $s['plan'] . ')' : 'plan desconocido';
		echo '<tr><th>' . esc_html( $titulo ) . '</th><td><code>' . esc_html( self::corto( $s['id'] ) ) . '</code> · ' . esc_html( $plan )
			. ' · monto ' . esc_html( is_scalar( $s['monto'] ) ? '$' . (string) $s['monto'] : '—' )
			. ' · estado en MP: <strong>' . esc_html( $s['status'] ) . '</strong>'
			. ' · email ' . esc_html( self::mascara_email( $s['email'] ) )
			. ' · pagador ' . esc_html( '' !== $s['payer_id'] ? '…' . substr( $s['payer_id'], -4 ) : '—' )
			. ' · alta ' . esc_html( '' !== $s['alta'] ? $s['alta'] : '—' )
			. ' · próximo cobro ' . esc_html( '' !== $s['proximo'] ? $s['proximo'] : '—' )
			. ' · último pago aprobado ' . esc_html( null === $cobro ? 'no se pudo consultar' : ( '' === $cobro ? 'ninguno' : $cobro ) ) . '</td></tr>';
	}

	protected static function preview_cv( $e, $d ) {
		$f = $e['ficha'];
		if ( $f ) {
			echo '<table class="widefat striped" style="max-width:960px;margin-bottom:12px;"><tbody>';
			echo '<tr><th style="width:200px;">Ficha</th><td>#' . (int) $f->id . ' · <strong>' . esc_html( $f->nombre_apellido ) . '</strong> · ' . esc_html( CN_Vinculo::mascara_celular( $f->celular_hint ) )
				. ' · estado ' . esc_html( $f->estado ) . ' · plan ' . esc_html( '' !== (string) $f->plan ? $f->plan : '—' )
				. ' · acceso hasta ' . esc_html( ! empty( $f->acceso_hasta ) ? substr( (string) $f->acceso_hasta, 0, 10 ) : '—' )
				. ' · baja programada ' . esc_html( ! empty( $f->baja_en ) ? substr( (string) $f->baja_en, 0, 10 ) : 'no' ) . '</td></tr>';
			if ( ! $e['ya_hecho'] ) {
				self::fila_sub( 'Suscripción ACTUAL (A)', $e['a'], $e['cobro_a'] );
				self::fila_sub( 'Suscripción NUEVA (B)', $e['b'], $e['cobro_b'] );
			}
			echo '</tbody></table>';
		}
		if ( $e['ya_hecho'] ) {
			self::aviso_cv( 'Ya está hecho: la ficha ya tiene esa suscripción. No hay nada para cambiar.', 'success' );
			return;
		}
		if ( ! $e['ok'] ) {
			foreach ( $e['bloqueos'] as $b ) {
				self::aviso_cv( 'No se puede corregir: ' . $b, 'error' );
			}
			return;
		}
		?>
		<div class="notice notice-info inline"><p>Al confirmar: la ficha pasa de A a B (solo cambia el vínculo). A queda sin vincular y no se toca en Mercado Pago. La fila previa queda en el log para revertir.</p></div>
		<form method="post" style="max-width:820px;margin-bottom:16px;" action="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGINA ), admin_url( 'admin.php' ) ) ); ?>">
			<?php wp_nonce_field( self::NONCE_C, 'cn_cv_nonce' ); ?>
			<input type="hidden" name="cn_cv_socia" value="<?php echo esc_attr( (int) $f->id ); ?>">
			<input type="hidden" name="cn_cv_a" value="<?php echo esc_attr( $d['a'] ); ?>">
			<input type="hidden" name="cn_cv_b" value="<?php echo esc_attr( $d['b'] ); ?>">
			<input type="hidden" name="cn_cv_foto" value="<?php echo esc_attr( $e['foto'] ); ?>">
			<p><label><input type="checkbox" name="cn_cv_ficha_ok" value="1" required> Confirmo que la ficha es <strong>#<?php echo (int) $f->id; ?> <?php echo esc_html( $f->nombre_apellido ); ?></strong> (<?php echo esc_html( CN_Vinculo::mascara_celular( $f->celular_hint ) ); ?>)</label></p>
			<p><button type="submit" name="cn_cv_accion" value="confirmar" class="button button-primary">Confirmar y corregir</button></p>
		</form>
		<?php
	}
}
CN_Corregir_Vinculo::registrar(); // se engancha sola: borrar la pantalla = quitar el require_once y este archivo.
