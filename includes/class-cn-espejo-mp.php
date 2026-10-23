<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Espejo de pausas/cancelaciones de Mercado Pago sobre las fichas YA vinculadas (preapproval_id + plan).
 * Se engancha al sync de 30 min (CN_Sync::sincronizar_lote): reutiliza el GET /preapproval/{id} que el sync ya hizo y
 * su mismo presupuesto de pedidos (CN_MP::get_con_reintentos). Solo GET a Mercado Pago.
 *
 * Modos (opción cn_espejo_mp_modo, por defecto 'off'):
 *  - 'off': no hace nada (ni una consulta a la base ni a MP).
 *  - 'log': NO cambia ninguna ficha. Registra en mp_log (espejo_mp_simulado) qué haría: ficha, estado actual -> propuesto, motivo.
 *  - 'on' : paused/cancelled en MP -> "baja programada" (baja_en = fin del período pagado + 7 días de gracia; el acceso
 *           sigue hasta entonces: la ficha queda 'activo'). authorized dentro de la gracia -> se cancela la baja.
 *
 * Fail-safe: error de MP (401/403/5xx/tope/red), respuesta vacía o ambigua, sin período pagado conocido, o pago acreditado
 * en los últimos 35 días => no se cambia nada. Máximo MAX_BAJAS bajas por corrida: si hay más, no se programa ninguna y queda un aviso.
 * Nunca toca fichas sin vínculo (manuales, trials). Sin mails: solo mp_log + cartel Avisos.
 * La baja efectiva la ejecuta el cron diario existente (CN_Webhook::revocar_bajas_sub_vencidas), cuando baja_en <= ahora.
 */
class CN_Espejo_MP {
	const OPT_MODO           = 'cn_espejo_mp_modo';
	const GRACIA_DIAS        = 7;
	const PAGO_RECIENTE_DIAS = 35;
	const MAX_BAJAS          = 5;   // bajas programadas por corrida; más que eso = posible falla masiva
	const TIPO_SIMULADO      = 'espejo_mp_simulado';
	const TIPO_PROGRAMADA    = 'espejo_mp_baja_programada';
	const TIPO_REVERTIDA     = 'espejo_mp_baja_revertida';
	const TIPO_OMITIDA       = 'espejo_mp_omitida';
	/** Propuestas de la corrida en curso (se juntan en observar() y se resuelven juntas en cerrar_corrida()). */
	protected static $cola = array();
	public static function modo() {
		$m = (string) get_option( self::OPT_MODO, 'off' );
		return in_array( $m, array( 'log', 'on' ), true ) ? $m : 'off';
	}
	public static function iniciar_corrida() {
		self::$cola = array();
	}
	/** Ficha en el alcance del espejo: vinculada por el sistema (preapproval_id + plan), no cancelada y que no sea trial. */
	protected static function en_alcance( $f, $pid ) {
		return is_object( $f ) && (string) $f->preapproval_id === $pid && '' !== $pid
			&& '' !== (string) ( $f->plan ?? '' ) && 'cancelada' !== (string) $f->estado
			&& empty( $f->fecha_fin_trial );
	}
	protected static function ts_utc( $mysql ) {
		$ts = '' === (string) $mysql ? false : strtotime( (string) $mysql . ' UTC' );
		return false === $ts ? null : $ts;
	}
	/** ¿Pagó en los últimos 35 días? true / false; null = no se pudo saber (MP falló o la lista es incompleta). Solo GET. */
	protected static function pago_reciente( $pid, &$presupuesto ) {
		$r = CN_MP::get_con_reintentos( '/authorized_payments/search?preapproval_id=' . rawurlencode( $pid ), CN_MP::get_access_token(), $presupuesto );
		if ( ! $r['ok'] || ! isset( $r['data']['results'] ) || ! is_array( $r['data']['results'] ) ) {
			return null;
		}
		$total = isset( $r['data']['paging']['total'] ) ? (int) $r['data']['paging']['total'] : count( $r['data']['results'] );
		$corte = time() - self::PAGO_RECIENTE_DIAS * DAY_IN_SECONDS;
		foreach ( $r['data']['results'] as $it ) {
			if ( ! is_array( $it ) ) {
				return null; // elemento raro: ambiguo.
			}
			$pago = isset( $it['payment']['status'] ) ? (string) $it['payment']['status'] : '';
			if ( 'approved' !== $pago && ! ( 'processed' === ( $it['status'] ?? '' ) && ! isset( $it['payment']['status'] ) ) ) {
				continue;
			}
			$mejor = null;
			foreach ( array( 'debit_date', 'last_modified', 'date_created' ) as $campo ) {
				$ts = isset( $it[ $campo ] ) && is_string( $it[ $campo ] ) ? strtotime( $it[ $campo ] ) : false;
				if ( false !== $ts && ( null === $mejor || $ts > $mejor ) ) {
					$mejor = $ts;
				}
			}
			if ( null === $mejor || $mejor >= $corte ) {
				return true; // pago reciente (o sin fecha legible: ante la duda se protege).
			}
		}
		return $total > count( $r['data']['results'] ) ? null : false; // lista incompleta: no se concluye "sin pago reciente".
	}
	/** Lo llama el sync por cada ficha cuyo GET /preapproval/{id} salió bien (mismo presupuesto de pedidos). Nunca lanza ni escribe en miembros. */
	public static function observar( $miembro_id, $pid, $data, &$presupuesto ) {
		if ( 'off' === self::modo() ) {
			return;
		}
		global $wpdb;
		try {
			$pid    = (string) $pid;
			$status = is_array( $data ) && isset( $data['status'] ) && is_string( $data['status'] ) ? $data['status'] : '';
			if ( ! in_array( $status, array( 'authorized', 'paused', 'cancelled' ), true ) || ! isset( $data['id'] ) || (string) $data['id'] !== $pid ) {
				return; // vacío, ambiguo, otro estado o de otra suscripción: no se hace nada.
			}
			$f = $wpdb->get_row( $wpdb->prepare(
				'SELECT id, estado, plan, preapproval_id, acceso_hasta, baja_en, fecha_fin_trial FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE id = %d',
				(int) $miembro_id
			) );
			if ( '' !== (string) $wpdb->last_error ) {
				throw new RuntimeException( 'error leyendo la ficha: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			if ( ! self::en_alcance( $f, $pid ) ) {
				return;
			}
			$base = array( 'miembro_id' => (int) $f->id, 'preapproval_id' => $pid, 'status_mp' => $status );
			$baja = '' !== (string) ( $f->baja_en ?? '' );
			if ( 'authorized' === $status ) {
				if ( $baja ) {
					self::$cola[] = $base + array( 'accion' => 'revertir', 'de' => 'activo, con baja programada', 'a' => 'activo, sin baja', 'motivo' => 'MP volvió a authorized dentro de la gracia', 'baja_en' => (string) $f->baja_en );
				}
				return;
			}
			if ( $baja || 'activo' !== (string) $f->estado ) {
				return; // baja ya programada (idempotente) o ficha que no está activa: no hay nada que espejar.
			}
			$npd = isset( $data['next_payment_date'] ) && is_string( $data['next_payment_date'] ) ? strtotime( $data['next_payment_date'] ) : false;
			$fin = max( (int) self::ts_utc( $f->acceso_hasta ), (int) $npd ); // fin del período ya pagado: lo más tarde entre la base y MP.
			if ( $fin <= 0 ) {
				self::omitida( $base, 'sin_periodo', 'sin fecha de fin del período pagado: lo resuelve una persona' );
				return;
			}
			$reciente = self::pago_reciente( $pid, $presupuesto );
			if ( null === $reciente ) {
				self::omitida( $base, 'consulta_pagos_fallo', 'no se pudieron consultar los cobros en MP: no se cambia nada, se reintenta' );
				return;
			}
			if ( $reciente ) {
				self::omitida( $base, 'pago_reciente', 'pago acreditado en los últimos ' . self::PAGO_RECIENTE_DIAS . ' días' );
				return;
			}
			$baja_ts      = max( $fin + self::GRACIA_DIAS * DAY_IN_SECONDS, time() + self::GRACIA_DIAS * DAY_IN_SECONDS );
			self::$cola[] = $base + array(
				'accion'  => 'programar',
				'de'      => 'activo, sin baja',
				'a'       => 'activo, baja programada ' . gmdate( 'Y-m-d H:i', $baja_ts ) . ' UTC',
				'motivo'  => 'MP ' . $status . '; acceso hasta fin del período pagado + ' . self::GRACIA_DIAS . ' días',
				'baja_ts' => $baja_ts,
				'fin_ts'  => $fin,
			);
		} catch ( Throwable $e ) {
			self::error( 'observar', $e );
		}
	}
	/** Una fila por día y suscripción (evita repetir lo mismo en cada vuelta del sync). */
	protected static function una_vez_al_dia( $clave ) {
		$k = 'cn_espejo_' . md5( $clave );
		if ( get_transient( $k ) ) {
			return false;
		}
		set_transient( $k, 1, DAY_IN_SECONDS );
		return true;
	}
	protected static function omitida( $base, $codigo, $texto ) {
		if ( self::una_vez_al_dia( 'om|' . $base['preapproval_id'] . '|' . $codigo ) ) {
			CN_Webhook::registrar_log_mp( self::TIPO_OMITIDA, $base + array( 'codigo' => $codigo, 'motivo' => $texto, 'modo' => self::modo() ) );
		}
	}
	protected static function error( $paso, $e ) {
		$d = array( 'paso' => 'espejo_' . $paso, 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) );
		CN_Webhook::registrar_log_mp( 'sub_error', $d );
		CN_Webhook::avisar_error_n8n( 'sub_error', $d );
	}
	/** Resuelve las propuestas de la corrida: aplica ('on') o solo registra ('log'). Nunca lanza. */
	public static function cerrar_corrida() {
		$cola       = self::$cola;
		self::$cola = array();
		$modo       = self::modo();
		if ( ! $cola || 'off' === $modo ) {
			return;
		}
		try {
			$altas      = count( array_filter( $cola, function ( $p ) {
				return 'programar' === $p['accion'];
			} ) );
			$sobre_tope = $altas > self::MAX_BAJAS;
			if ( $sobre_tope ) {
				$d = array( 'propuestas' => $altas, 'tope' => self::MAX_BAJAS, 'modo' => $modo, 'aviso' => 'Más bajas de las esperables en una sola corrida (posible falla masiva): no se programó ninguna.' );
				CN_Webhook::registrar_log_mp( 'espejo_mp_tope', $d );
				CN_Webhook::avisar_error_n8n( 'espejo_mp_tope', $d );
			}
			foreach ( $cola as $p ) {
				try {
					if ( 'log' === $modo ) {
						if ( self::una_vez_al_dia( 'sim|' . $p['preapproval_id'] . '|' . $p['accion'] ) ) {
							unset( $p['baja_ts'], $p['fin_ts'] );
							CN_Webhook::registrar_log_mp( self::TIPO_SIMULADO, $p + array( 'sobre_tope' => $sobre_tope && 'programar' === $p['accion'] ) );
						}
					} elseif ( ! ( $sobre_tope && 'programar' === $p['accion'] ) ) {
						self::aplicar( $p );
					}
				} catch ( Throwable $e ) {
					self::error( 'aplicar', $e );
				}
			}
		} catch ( Throwable $e ) {
			self::error( 'cerrar', $e );
		}
	}
	/** Modo 'on': transacción con FOR UPDATE y re-lectura de la ficha antes de escribir. Idempotente. */
	protected static function aplicar( $p ) {
		global $wpdb;
		$t   = CN_DB::tabla( 'miembros' );
		$pid = (string) $p['preapproval_id'];
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new RuntimeException( 'no se pudo abrir la transaccion' );
		}
		try {
			$f = $wpdb->get_row( $wpdb->prepare( "SELECT id, estado, plan, preapproval_id, acceso_hasta, baja_en, fecha_fin_trial FROM {$t} WHERE id = %d FOR UPDATE", (int) $p['miembro_id'] ) );
			if ( '' !== (string) $wpdb->last_error ) {
				throw new RuntimeException( 'error leyendo la ficha: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			$baja  = is_object( $f ) && '' !== (string) ( $f->baja_en ?? '' );
			$vigente = self::en_alcance( $f, $pid ) && ( 'programar' === $p['accion'] ? ( ! $baja && 'activo' === (string) $f->estado ) : $baja );
			if ( ! $vigente ) {
				$wpdb->query( 'ROLLBACK' ); // la ficha cambió mientras tanto (otro proceso, o ya estaba hecho): no se escribe.
				CN_Webhook::registrar_log_mp( self::TIPO_OMITIDA, array( 'miembro_id' => (int) $p['miembro_id'], 'preapproval_id' => $pid, 'codigo' => 'ficha_cambio', 'accion' => $p['accion'] ) );
				return;
			}
			$ahora = current_time( 'mysql', true );
			if ( 'programar' === $p['accion'] ) {
				$baja_ts = max( (int) $p['baja_ts'], (int) self::ts_utc( $f->acceso_hasta ) + self::GRACIA_DIAS * DAY_IN_SECONDS ); // si el acceso se extendió mientras tanto, la baja no queda antes.
				$n = $wpdb->query( $wpdb->prepare(
					"UPDATE {$t} SET baja_en = %s, fecha_modificacion = %s WHERE id = %d AND preapproval_id = %s AND estado = 'activo' AND baja_en IS NULL",
					gmdate( 'Y-m-d H:i:s', $baja_ts ), $ahora, (int) $p['miembro_id'], $pid
				) );
				$tipo = self::TIPO_PROGRAMADA;
				$extra = array( 'baja_en' => gmdate( 'Y-m-d H:i:s', $baja_ts ), 'acceso_hasta' => (string) $f->acceso_hasta );
			} else {
				$n = $wpdb->query( $wpdb->prepare(
					"UPDATE {$t} SET baja_en = NULL, fecha_modificacion = %s WHERE id = %d AND preapproval_id = %s AND baja_en IS NOT NULL",
					$ahora, (int) $p['miembro_id'], $pid
				) );
				$tipo  = self::TIPO_REVERTIDA;
				$extra = array( 'baja_en_anterior' => (string) $f->baja_en );
			}
			if ( false === $n ) {
				throw new RuntimeException( 'no se pudo escribir: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'no se pudo confirmar la transaccion' );
			}
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
		CN_Webhook::registrar_log_mp( $tipo, array( 'miembro_id' => (int) $p['miembro_id'], 'preapproval_id' => $pid, 'status_mp' => $p['status_mp'], 'motivo' => $p['motivo'] ) + $extra );
	}
	/** Cartel de admin (Socias): contador de lo que el espejo propuso (log) o hizo (on). Vacío si está apagado. Solo lectura. */
	public static function cartel() {
		$modo = self::modo();
		if ( 'off' === $modo ) {
			return '';
		}
		global $wpdb;
		$filas = $wpdb->get_results( $wpdb->prepare(
			'SELECT payload, tipo, fecha FROM ' . CN_DB::tabla( 'mp_log' ) . ' WHERE tipo IN (%s, %s, %s) ORDER BY id DESC LIMIT 500',
			self::TIPO_SIMULADO, self::TIPO_PROGRAMADA, self::TIPO_REVERTIDA
		) );
		$filas = is_array( $filas ) ? $filas : array();
		$fichas = array();
		$lineas = array();
		foreach ( $filas as $f ) {
			$p = json_decode( (string) $f->payload, true );
			if ( ! is_array( $p ) ) {
				continue;
			}
			$fichas[ (int) ( $p['miembro_id'] ?? 0 ) ] = 1;
			if ( count( $lineas ) < 20 ) {
				$lineas[] = '<tr><td>#' . (int) ( $p['miembro_id'] ?? 0 ) . '</td><td>' . esc_html( (string) $f->fecha ) . '</td><td>' . esc_html( (string) ( $p['de'] ?? $f->tipo ) ) . ' → ' . esc_html( (string) ( $p['a'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $p['motivo'] ?? '' ) ) . '</td></tr>';
			}
		}
		$h  = '<div class="notice notice-info inline" style="padding:6px 12px;"><details><summary style="cursor:pointer;"><strong>Espejo de Mercado Pago (' . ( 'log' === $modo ? 'solo registrar: no cambia ninguna ficha' : 'activo' ) . '):</strong> ' . count( $fichas ) . ' fichas con cambios ' . ( 'log' === $modo ? 'propuestos' : 'propuestos o hechos' ) . ' (' . count( $filas ) . ' registros).</summary>';
		if ( $lineas ) {
			$h .= '<table class="widefat striped" style="margin:8px 0;"><thead><tr><th>Ficha</th><th>Fecha (UTC)</th><th>Estado actual → propuesto</th><th>Motivo</th></tr></thead><tbody>' . implode( '', $lineas ) . '</tbody></table>';
		}
		return $h . '</details></div>';
	}
}
