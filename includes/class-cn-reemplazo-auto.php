<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Reemplazo automático (re-suscripción). Se engancha al sync de 30 min (CN_Sync::correr). Solo GET a Mercado Pago.
 *
 * Caso: una socia cancela su suscripción y se suscribe de nuevo; la nueva queda sin vincular y su ficha tiene la vieja.
 *
 * Modos (opción cn_reemplazo_auto_modo; por defecto y ante cualquier valor raro, 'log'):
 *  - 'log':    NO escribe en cn_miembros. Registra en mp_log (reemplazo_auto_simulado) qué haría.
 *  - 'activo': aplica con CN_Reemplazo::confirmar_reemplazo( ..., 'auto' ): la misma transacción FOR UPDATE, backup, baja_en NULL y log
 *              (reemplazo_suscripcion, origen auto) que el reemplazo manual. Máximo MAX_APLICAR por corrida.
 *
 * Solo propone/aplica si TODAS se cumplen: la sub nueva está authorized y sin vincular (y tiene más de 1 hora, para que el alta
 * automática vaya primero); hay UNA sola ficha con el mismo email del pagador (exacto) y el mismo plan que ya tiene una suscripción
 * distinta; la sub vieja de esa ficha está CANCELLED hoy en MP (paused o authorized no: puede ser doble pago); mismo plan y monto;
 * y todos los bloqueos de CN_Reemplazo::evaluar. Un fallo de MP o un dato ambiguo = no hace nada (se reintenta en la próxima corrida).
 * 2+ candidatas, o ninguna que califique habiendo fichas con ese email: queda "ambiguo" en mp_log y en el cartel Avisos.
 * Sin mails ni eventos Meta. Nunca lanza.
 */
class CN_Reemplazo_Auto extends CN_Reemplazo {
	const OPT_MODO      = 'cn_reemplazo_auto_modo';
	const MAX_APLICAR   = 3;  // reemplazos (o propuestas, en log) por corrida
	const MAX_INTENTOS  = 6;  // candidatas evaluadas (cada una cuesta unos pocos GET a MP) por corrida
	const TIPO_SIM      = 'reemplazo_auto_simulado';
	const TIPO_AMBIGUO  = 'reemplazo_auto_ambiguo';
	const TIPO_OMITIDO  = 'reemplazo_auto_omitido';
	const TIPO_TOPE     = 'reemplazo_auto_tope';
	const REPOSO_SEG    = 21600; // una candidata bloqueada por una regla de negocio no se vuelve a evaluar durante 6 hs

	public static function modo() {
		return 'activo' === (string) get_option( self::OPT_MODO, 'log' ) ? 'activo' : 'log';
	}

	/** true la primera vez en el período; las demás veces false (evita repetir lo mismo en cada vuelta del sync). */
	protected static function una_vez( $clave, $seg ) {
		$k = 'cn_ra_' . md5( $clave );
		if ( get_transient( $k ) ) {
			return false;
		}
		set_transient( $k, 1, $seg );
		return true;
	}

	protected static function omitido( $id, $f, $codigo, $texto, $extra = array() ) {
		if ( self::una_vez( 'om|' . $id . '|' . $codigo, DAY_IN_SECONDS ) ) {
			CN_Webhook::registrar_log_mp( self::TIPO_OMITIDO, array( 'preapproval_nuevo' => $id, 'miembro_id' => $f ? (int) $f->id : null, 'codigo' => $codigo, 'motivo' => mb_substr( $texto, 0, 300 ), 'modo' => self::modo() ) + $extra );
		}
	}

	protected static function ambiguo( $id, $email, $cuantas, $ids ) {
		if ( ! self::una_vez( 'amb|' . $id, DAY_IN_SECONDS ) ) {
			return;
		}
		$d = array( 'preapproval_nuevo' => $id, 'email' => self::mascara_email( $email ), 'fichas_candidatas' => $cuantas, 'miembro_ids' => array_slice( $ids, 0, 10 ), 'modo' => self::modo(), 'aviso' => 'Re-suscripción ambigua: no se reemplaza nada solo, lo resuelve una persona.' );
		CN_Webhook::registrar_log_mp( self::TIPO_AMBIGUO, $d );
		CN_Webhook::avisar_error_n8n( self::TIPO_AMBIGUO, $d );
	}

	/**
	 * Una corrida. Devuelve un resumen (para los tests) o null si no se pudo ni empezar (sin token / MP no respondió la lista).
	 *
	 * @return array{modo:string,evaluadas:int,aplicadas:int,propuestas:int,ambiguas:int,omitidas:int}|null
	 */
	public static function correr() {
		global $wpdb;
		if ( '' === CN_MP::get_access_token() ) {
			return null;
		}
		$lista = CN_Vinculo::listar_authorized();
		if ( empty( $lista['ok'] ) ) {
			return null; // MP caído o lista incompleta: no hay forma de saber qué está sin vincular.
		}
		$socias = $wpdb->get_results( 'SELECT ' . self::COLS . ' FROM ' . CN_DB::tabla( 'miembros' ) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $socias ) ) {
			return null;
		}
		$modo      = self::modo();
		$res       = array( 'modo' => $modo, 'evaluadas' => 0, 'aplicadas' => 0, 'propuestas' => 0, 'ambiguas' => 0, 'omitidas' => 0 );
		$vinculadas = array();
		$por_email  = array();
		foreach ( $socias as $s ) {
			if ( '' !== (string) $s->preapproval_id ) {
				$vinculadas[ strtolower( (string) $s->preapproval_id ) ] = true;
			}
			$e = CN_Vinculo::email_normalizado( $s->email );
			if ( '' !== $e ) {
				$por_email[ $e ][] = $s;
			}
		}
		$pendientes = 0;
		foreach ( $lista['items'] as $it ) {
			$id = isset( $it['id'] ) && is_string( $it['id'] ) ? strtolower( $it['id'] ) : '';
			if ( ! self::id_valido( $id ) || isset( $vinculadas[ $id ] ) ) {
				continue;
			}
			$creada = isset( $it['date_created'] ) && is_string( $it['date_created'] ) ? strtotime( $it['date_created'] ) : false;
			if ( false === $creada || $creada > time() - CN_Sync::MIN_SIN_VINCULAR ) {
				continue; // muy reciente (el alta automática va primero) o sin fecha legible.
			}
			$email = CN_Vinculo::email_normalizado( isset( $it['payer_email'] ) ? $it['payer_email'] : '' );
			$plan  = self::plan_por_monto( isset( $it['auto_recurring']['transaction_amount'] ) ? $it['auto_recurring']['transaction_amount'] : null );
			if ( '' === $email || null === $plan || empty( $por_email[ $email ] ) ) {
				continue; // sin email, plan desconocido o ninguna ficha con ese email: no es un caso de re-suscripción (lo muestra "Sin vincular").
			}
			$cands = array();
			foreach ( $por_email[ $email ] as $f ) {
				if ( (string) $f->plan === $plan && '' !== (string) $f->preapproval_id && strtolower( (string) $f->preapproval_id ) !== $id ) {
					$cands[] = $f;
				}
			}
			if ( 1 !== count( $cands ) ) {
				$res['ambiguas']++;
				self::ambiguo( $id, $email, count( $cands ), array_map( function ( $f ) {
					return (int) $f->id;
				}, $cands ?: $por_email[ $email ] ) );
				continue;
			}
			$f = $cands[0];
			if ( get_transient( 'cn_ra_' . md5( 'rep|' . $id ) ) ) {
				continue; // evaluada hace poco y bloqueada por una regla de negocio.
			}
			if ( $res['evaluadas'] >= self::MAX_INTENTOS || $res['aplicadas'] + $res['propuestas'] >= self::MAX_APLICAR ) {
				$pendientes++;
				continue;
			}
			$res['evaluadas']++;
			self::procesar_candidata( $id, $f, $modo, $res );
		}
		if ( $pendientes && self::una_vez( 'tope|' . gmdate( 'Y-m-d-H' ), 3600 ) ) {
			CN_Webhook::registrar_log_mp( self::TIPO_TOPE, array( 'pendientes' => $pendientes, 'tope_aplicar' => self::MAX_APLICAR, 'tope_evaluar' => self::MAX_INTENTOS, 'modo' => $modo ) );
		}
		return $res;
	}

	protected static function procesar_candidata( $id, $f, $modo, &$res ) {
		$ev = self::evaluar( $id, (int) $f->id ); // GET en vivo a MP de la nueva y la vieja + todos los bloqueos del reemplazo manual.
		if ( ( $ev['nueva'] && empty( $ev['nueva']['ok'] ) ) || ( $ev['vieja'] && empty( $ev['vieja']['ok'] ) ) ) {
			$res['omitidas']++;
			self::omitido( $id, $f, 'mp', 'Mercado Pago falló o fue ambiguo: no se hace nada, se reintenta en la próxima corrida.' );
			return; // sin reposo: se reintenta.
		}
		$reposo = function () use ( $id ) {
			set_transient( 'cn_ra_' . md5( 'rep|' . $id ), 1, self::REPOSO_SEG );
		};
		if ( ! $ev['ok'] ) {
			$res['omitidas']++;
			$doble = $ev['vieja'] && 'authorized' === $ev['vieja']['status'];
			self::omitido( $id, $f, $doble ? 'doble_pago' : 'bloqueada', implode( ' ', $ev['bloqueos'] ), array( 'estado_mp_viejo' => $ev['vieja'] ? $ev['vieja']['status'] : null ) );
			if ( $doble ) {
				CN_Webhook::avisar_error_n8n( 'reemplazo_auto_doble_pago', array( 'preapproval_nuevo' => $id, 'miembro_id' => (int) $f->id, 'aviso' => 'Las dos suscripciones de la ficha están activas en Mercado Pago: posible doble pago. Resolverlo en Mercado Pago.' ) );
			}
			$reposo();
			return;
		}
		if ( $ev['ya_hecho'] ) {
			return;
		}
		if ( 'cancelled' !== $ev['vieja']['status'] || $ev['cambio_plan'] ) {
			$res['omitidas']++;
			self::omitido( $id, $f, 'no_cancelada_o_cambia_plan', 'La suscripción vieja está ' . $ev['vieja']['status'] . ( $ev['cambio_plan'] ? ' y cambia el plan/monto' : '' ) . ': el automático solo reemplaza una cancelada del mismo plan. Lo resuelve una persona.' );
			$reposo();
			return;
		}
		$base = array( 'miembro_id' => (int) $f->id, 'preapproval_viejo' => $ev['id_viejo'], 'preapproval_nuevo' => $id, 'estado_mp_viejo' => $ev['vieja']['status'], 'estado_mp_nuevo' => $ev['nueva']['status'], 'plan' => $ev['nueva']['plan'] );
		if ( 'log' === $modo ) {
			$res['propuestas']++;
			if ( self::una_vez( 'sim|' . $id, DAY_IN_SECONDS ) ) {
				CN_Webhook::registrar_log_mp( self::TIPO_SIM, $base + array( 'accion' => 'reemplazar', 'acceso_hasta_actual' => $f->acceso_hasta, 'acceso_hasta_mp' => $ev['nueva']['acceso_hasta'], 'baja_en_actual' => $f->baja_en, 'modo' => 'log' ) );
			}
			$reposo();
			return;
		}
		$r = self::confirmar_reemplazo( array( 'id' => $id, 'socia' => (int) $f->id, 'foto' => $ev['foto'], 'ficha_ok' => true, 'plan_ok' => false ), 'auto' );
		if ( ! empty( $r['ok'] ) ) {
			$res['aplicadas']++;
			delete_transient( CN_Sin_Vincular::TRANSIENT ); // la lista de "Sin vincular" ya no es válida.
			delete_transient( CN_Sin_Vincular::TRANSIENT_TITULARES );
			return;
		}
		$res['omitidas']++;
		self::omitido( $id, $f, 'rechazada', (string) $r['mensaje'] );
		$reposo();
	}
}
