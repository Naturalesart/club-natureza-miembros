<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class CN_Webhook {
	// Path del workflow de alertas de n8n (Club Natureza - Alerta de errores (Trial),
	// ID YmU7WWLAkWwj8xZT). Fire-and-forget: si n8n está caído, el trial igual sucede.
	const N8N_ALERTA_URL = 'https://n8n.naturalesart.com/webhook/club-trial-alerta';
	// Alta de trial -> fila en la pestaña "Trial" de la sheet de seguimiento (workflow n8n
	// "Club Natureza - Alta Trial -> Sheet", ID E8UowHCBq1ohgWSQ). Fire-and-forget.
	const N8N_ALTA_SHEET_URL = 'https://n8n.naturalesart.com/webhook/club-trial-alta-sheet';
	// Eventos de suscripción de Mercado Pago -> pestaña "Suscripciones" de la sheet de seguimiento
	// (workflow n8n "Club Natureza - Suscripción -> Sheet", ID HJ4PHGSgqHmln8tP). Fire-and-forget.
	const N8N_SUSCRIPCION_SHEET_URL = 'https://n8n.naturalesart.com/webhook/club-suscripcion-sheet';
	const TRIAL_DIAS = 7;
	const DIAS_AVISO_TRIAL = 5;
	const NOMBRE_CLASE_ESPECIAL = 'Yo no pinto, pinta el pincel';
	public static function registrar_rutas() {
		register_rest_route( 'cn/v1', '/mp-webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'manejar' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'cn/v1', '/trial-alta', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'manejar_trial' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'cn/v1', '/crear-preference-trial', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'crear_preference_trial_endpoint' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'cn/v1', '/pixel-mark', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'pixel_mark' ),
			'permission_callback' => '__return_true',
		) );
	}
	public static function manejar( WP_REST_Request $request ) {
		global $wpdb;
		$raw = $request->get_body();
		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) ) {
			$json = array();
		}
		// El "tipo" puede venir en el body o como query params (formatos distintos de MP).
		$tipo = '';
		$id   = '';
		if ( ! empty( $json['type'] ) ) {
			$tipo = sanitize_text_field( $json['type'] );
		} elseif ( ! empty( $json['topic'] ) ) {
			$tipo = sanitize_text_field( $json['topic'] );
		} elseif ( $request->get_param( 'type' ) ) {
			$tipo = sanitize_text_field( $request->get_param( 'type' ) );
		} elseif ( $request->get_param( 'topic' ) ) {
			$tipo = sanitize_text_field( $request->get_param( 'topic' ) );
		}
		if ( ! empty( $json['data']['id'] ) ) {
			$id = sanitize_text_field( (string) $json['data']['id'] );
		} elseif ( $request->get_param( 'id' ) ) {
			$id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		} elseif ( ! empty( $json['id'] ) ) {
			$id = sanitize_text_field( (string) $json['id'] );
		}
		// 1. Loguear el payload crudo siempre, sin importar si lo podemos procesar.
		$wpdb->insert(
			CN_DB::tabla( 'mp_log' ),
			array(
				'payload' => wp_json_encode( array( 'body' => $json, 'query' => $request->get_query_params() ) ),
				'tipo'    => $tipo ? $tipo : 'desconocido',
				'fecha'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
		// 2. Nunca confiar en el payload: pedirle a la API real el estado.
		if ( $id && in_array( $tipo, array( 'preapproval', 'subscription_preapproval' ), true ) ) {
			self::procesar_preapproval( $id );
		} elseif ( $id && in_array( $tipo, array( 'payment', 'subscription_authorized_payment' ), true ) ) {
			self::procesar_payment( $id );
		}
		// 3. Responder rápido y siempre 200 para que MP no reintente en loop.
		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}
	protected static function procesar_preapproval( $id ) {
		$data = CN_MP::obtener_preapproval( $id );
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			return;
		}
		$status              = $data['status'];
		$external_reference  = isset( $data['external_reference'] ) ? $data['external_reference'] : '';
		$payer_email         = isset( $data['payer_email'] ) ? sanitize_email( trim( $data['payer_email'] ) ) : '';
		self::aplicar_estado( $status, $external_reference, $id, $payer_email );
		self::registrar_suscripcion_en_sheet( 'preapproval', $id, $status, $id, $payer_email, isset( $data['auto_recurring']['transaction_amount'] ) ? $data['auto_recurring']['transaction_amount'] : '' );
	}
	protected static function procesar_payment( $id ) {
		$data = CN_MP::obtener_pago( $id );
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			return;
		}
		$status              = $data['status'];
		$external_reference  = isset( $data['external_reference'] ) ? $data['external_reference'] : '';
		$preapproval_id      = '';
		if ( ! empty( $data['point_of_interaction']['transaction_data']['preapproval_id'] ) ) {
			$preapproval_id = sanitize_text_field( (string) $data['point_of_interaction']['transaction_data']['preapproval_id'] );
		} elseif ( ! empty( $data['metadata']['preapproval_id'] ) ) {
			$preapproval_id = sanitize_text_field( (string) $data['metadata']['preapproval_id'] );
		}
		$payer_email = isset( $data['payer']['email'] ) ? sanitize_email( trim( $data['payer']['email'] ) ) : '';
		self::aplicar_estado( $status, $external_reference, $preapproval_id, $payer_email );
		self::registrar_suscripcion_en_sheet( 'payment', $id, $status, $preapproval_id, $payer_email, isset( $data['transaction_amount'] ) ? $data['transaction_amount'] : '' );
	}
	/**
	 * Vincula un aviso de pago/suscripción con la fila de la socia. Tres niveles
	 * de matching, del más preciso al más genérico:
	 *  1. external_reference en preapprovals_pendientes — flujo dinámico (hoy sin
	 *     uso real, se deja por si se retoma).
	 *  2. preapproval_id ya guardado — cubre los cobros siguientes de una
	 *     suscripción ya vinculada (mes 2, 3...).
	 *  3. email del pagador — cubre el PRIMER pago con un link fijo de MP (los
	 *     $15.500/$32.000 de hoy), único dato que MP entrega ahí y que ya está
	 *     guardado en la socia desde que hizo el trial de $7.000.
	 * Si los tres niveles fallan (o el email da 2+ resultados, ambiguo), no se
	 * adivina: se avisa por n8n para resolver a mano — mejor una alerta visible
	 * que una socia pagando sin que el sistema se entere (bug real, Susana
	 * Cristófaro, 17-sep-2026).
	 */
	protected static function aplicar_estado( $status, $external_reference, $preapproval_id, $payer_email = '' ) {
		global $wpdb;
		// Alta automática por suscripción "sub_" (Fase 2B). Apagada por defecto: las dos primeras
		// condiciones son comparaciones de texto, así que cualquier otro evento sigue de largo sin
		// consultas extra. Si la función devuelve false, no le corresponde y se sigue como siempre.
		if ( 'authorized' === $status && is_string( $external_reference ) && 0 === strpos( $external_reference, 'sub_' )
			&& '1' === (string) get_option( 'cn_sub_auto_activar', '0' ) ) {
			if ( self::activar_por_suscripcion_sub( $external_reference, $preapproval_id ) ) {
				return;
			}
		}
		// Ciclo de vida de una suscripción "sub_" ya vinculada (Fase 3-C): renovaciones y bajas SIN cortar el
		// acceso. Solo actúa con el flag activo y sobre filas con preapproval_id Y plan no nulo (las que dio de
		// alta esta función): trial, manuales y socias de siempre tienen plan NULL y siguen el camino de hoy.
		if ( '' !== (string) $preapproval_id && '1' === (string) get_option( 'cn_sub_auto_activar', '0' )
			&& self::gestionar_ciclo_sub( $status, $preapproval_id ) ) {
			return;
		}
		$estados_activo  = array( 'authorized', 'approved' );
		$estados_pausado = array( 'cancelled', 'rejected', 'paused' );
		if ( ! in_array( $status, array_merge( $estados_activo, $estados_pausado ), true ) ) {
			return; // pending u otro estado transitorio: no hacemos nada todavía.
		}
		$nuevo_estado = in_array( $status, $estados_activo, true ) ? 'activo' : 'pausado';
		$t_miembros   = CN_DB::tabla( 'miembros' );
		// Modo seguro: mientras esté en '1', un evento de MP nunca baja a nadie a 'pausado'.
		if ( 'pausado' === $nuevo_estado && '1' === (string) get_option( 'cn_sub_modo_seguro', '1' ) ) {
			$fila_id = $preapproval_id
				? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t_miembros} WHERE preapproval_id = %s LIMIT 1", $preapproval_id ) )
				: null;
			self::registrar_log_mp( 'downgrade_omitido', array(
				'preapproval_id' => $preapproval_id,
				'status_mp'      => $status,
				'fila_id'        => $fila_id ? (int) $fila_id : null,
			) );
			return;
		}
		// 1. external_reference (flujo dinámico).
		$pendiente = null;
		if ( $external_reference ) {
			$pendiente = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM " . CN_DB::tabla( 'preapprovals_pendientes' ) . " WHERE external_reference = %s",
					$external_reference
				)
			);
		}
		if ( $pendiente ) {
			self::upsert_miembro_por_celular( $pendiente->nombre_apellido, $pendiente->celular, $nuevo_estado, $preapproval_id );
			return;
		}
		// 2. preapproval_id ya vinculado antes (cobros siguientes).
		// SELECT primero para distinguir "existe y no cambió estado" (return, sin
		// alerta) de "no existe" (bajar a nivel 3). Sin este check, un cobro del
		// mes 2 donde ya está 'activo' y llega otro 'authorized' hace UPDATE de 0
		// filas y dispararía alerta falsa.
		if ( $preapproval_id ) {
			$existe = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$t_miembros} WHERE preapproval_id = %s LIMIT 1",
					$preapproval_id
				)
			);
			if ( $existe ) {
				$wpdb->update(
					$t_miembros,
					array( 'estado' => $nuevo_estado ),
					array( 'preapproval_id' => $preapproval_id ),
					array( '%s' ),
					array( '%s' )
				);
				return;
			}
		}
		// 3. email del pagador — primer pago con link fijo.
		if ( $payer_email && self::upsert_miembro_por_email( $payer_email, $nuevo_estado, $preapproval_id ) ) {
			return;
		}
		// Nada matcheó: avisar en vez de perderlo en silencio.
		self::avisar_error_n8n( 'preapproval_sin_match', array(
			'status'             => $status,
			'external_reference' => $external_reference,
			'preapproval_id'     => $preapproval_id,
			'payer_email'        => $payer_email,
		) );
	}
	protected static function upsert_miembro_por_celular( $nombre, $celular_normalizado, $estado, $preapproval_id ) {
		global $wpdb;
		$t_miembros = CN_DB::tabla( 'miembros' );
		$hint       = CN_Helpers::hint_celular( $celular_normalizado );
		$candidatos = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, celular_hash FROM {$t_miembros} WHERE celular_hint = %s", $hint )
		);
		$miembro_id = 0;
		foreach ( $candidatos as $candidato ) {
			if ( CN_Helpers::verificar_celular( $celular_normalizado, $candidato->celular_hash ) ) {
				$miembro_id = (int) $candidato->id;
				break;
			}
		}
		if ( $miembro_id ) {
			$data   = array( 'estado' => $estado );
			$format = array( '%s' );
			if ( $preapproval_id ) {
				$data['preapproval_id'] = $preapproval_id;
				$format[]               = '%s';
			}
			$wpdb->update( $t_miembros, $data, array( 'id' => $miembro_id ), $format, array( '%d' ) );
			return;
		}
		$wpdb->insert(
			$t_miembros,
			array(
				'nombre_apellido' => $nombre,
				'celular_hash'    => CN_Helpers::hash_celular( $celular_normalizado ),
				'celular_hint'    => $hint,
				'estado'          => $estado,
				'preapproval_id'  => $preapproval_id ? $preapproval_id : null,
				'fecha_alta'      => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
	/**
	 * Busca por email exacto. Si hay 0 o 2+ candidatas, no adivina — devuelve
	 * false para que aplicar_estado() dispare la alerta en vez de arriesgarse
	 * a actualizar la fila equivocada.
	 */
	protected static function upsert_miembro_por_email( $email, $estado, $preapproval_id ) {
		global $wpdb;
		if ( '' === $email ) {
			return false;
		}
		$t_miembros = CN_DB::tabla( 'miembros' );
		$candidatos = $wpdb->get_results(
			$wpdb->prepare( "SELECT id FROM {$t_miembros} WHERE email = %s", $email )
		);
		if ( 1 !== count( $candidatos ) ) {
			return false; // 0 = no está en la base; 2+ = ambiguo, no adivinamos.
		}
		$data   = array( 'estado' => $estado );
		$format = array( '%s' );
		if ( $preapproval_id ) {
			$data['preapproval_id'] = $preapproval_id;
			$format[]               = '%s';
		}
		$wpdb->update( $t_miembros, $data, array( 'id' => (int) $candidatos[0]->id ), $format, array( '%d' ) );
		return true;
	}
	/**
	 * Alta/activación automática por una suscripción "sub_" autorizada (Fase 2B).
	 * Solo la llama aplicar_estado() con cn_sub_auto_activar = '1', estado MP 'authorized' y
	 * referencia "sub_...".
	 *
	 * Devuelve false si NO le corresponde (no hay pendiente, ya fue usada o falta el
	 * preapproval_id): aplicar_estado() sigue por el camino de siempre. Devuelve true si se
	 * hizo cargo del evento (alta hecha, conflicto o error): así los niveles 1-3 no pueden
	 * pisar el preapproval_id de una socia existente.
	 *
	 * Todo ocurre en una transacción con la fila pendiente bloqueada: dos avisos repetidos
	 * del mismo pago no duplican socias, y si algo falla no queda nada a medias.
	 * Nunca re-normaliza el celular: usa el que ya guardó la pendiente.
	 */
	protected static function activar_por_suscripcion_sub( $external_reference, $preapproval_id ) {
		global $wpdb;
		$preapproval_id = (string) $preapproval_id;
		if ( '' === $preapproval_id ) {
			return false;
		}
		$t_pend     = CN_DB::tabla( 'suscripciones_pendientes' );
		$t_miembros = CN_DB::tabla( 'miembros' );
		// Lectura sin lock: descarta lo que no es nuestro o ya fue procesado (aviso repetido).
		$pend = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, usado FROM {$t_pend} WHERE external_reference = %s", $external_reference )
		);
		if ( ! $pend || 1 === (int) $pend->usado ) {
			return false;
		}
		// Próxima fecha de cobro (solo informativa), consultada ANTES de abrir la transacción
		// para no tener la fila bloqueada mientras se espera a MP.
		$acceso_hasta = self::sub_acceso_hasta( $preapproval_id );
		$en_transaccion = false;
		$es_alta        = false; // true solo en alta nueva o activación (nunca en "mismo preapproval_id ya vinculado").
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'no se pudo abrir la transaccion' );
			}
			$en_transaccion = true;
			$fila = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$t_pend} WHERE external_reference = %s FOR UPDATE", $external_reference )
			);
			if ( ! $fila || 1 === (int) $fila->usado ) {
				$wpdb->query( 'ROLLBACK' ); // otro aviso ya la procesó mientras esperábamos el lock.
				$en_transaccion = false;
				return false;
			}
			$planes = CN_MP::get_planes();
			$plan   = ( is_string( $fila->plan ) && isset( $planes[ $fila->plan ] ) ) ? $fila->plan : null;
			$ahora  = current_time( 'mysql', true );
			// Buscar a la socia por celular, igual que el alta del trial (hint + verificación del hash).
			$hint       = CN_Helpers::hint_celular( $fila->celular );
			$candidatos = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, celular_hash, preapproval_id, plan, acceso_hasta FROM {$t_miembros} WHERE celular_hint = %s", $hint )
			);
			if ( '' !== (string) $wpdb->last_error ) {
				throw new RuntimeException( 'error buscando a la socia: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			$socia = null;
			foreach ( (array) $candidatos as $candidato ) {
				if ( CN_Helpers::verificar_celular( $fila->celular, $candidato->celular_hash ) ) {
					$socia = $candidato;
					break;
				}
			}
			if ( ! $socia ) {
				// Socia nueva.
				$ok = $wpdb->insert(
					$t_miembros,
					array(
						'nombre_apellido'     => $fila->nombre_apellido,
						'celular_hash'        => CN_Helpers::hash_celular( $fila->celular ),
						'celular_hint'        => $hint,
						'estado'              => 'activo',
						'fecha_alta'          => $ahora,
						'fecha_modificacion'  => $ahora,
						'email'               => ( '' !== (string) $fila->email ) ? $fila->email : null,
						'celular_texto_plano' => $fila->celular,
						'plan'                => $plan,
						'preapproval_id'      => $preapproval_id,
						'acceso_hasta'        => $acceso_hasta,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				if ( false === $ok ) {
					throw new RuntimeException( 'no se pudo crear la socia: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
				}
				$es_alta = true;
			} elseif ( '' === (string) $socia->preapproval_id ) {
				// Socia existente sin suscripción (trial, manual, pausada o cancelada): se activa.
				$ok = $wpdb->update(
					$t_miembros,
					array(
						'estado'             => 'activo',
						'preapproval_id'     => $preapproval_id,
						'plan'               => $plan,
						'acceso_hasta'       => $acceso_hasta,
						'fecha_fin_trial'    => null,
						'fecha_modificacion' => $ahora,
					),
					array( 'id' => (int) $socia->id ),
					array( '%s', '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
				if ( false === $ok ) {
					throw new RuntimeException( 'no se pudo activar a la socia: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
				}
				$es_alta = true;
			} elseif ( (string) $socia->preapproval_id !== $preapproval_id ) {
				// Ya tiene OTRA suscripción: no se pisa nada y la pendiente queda sin marcar.
				$wpdb->query( 'ROLLBACK' );
				$en_transaccion = false;
				self::registrar_log_mp( 'sub_conflicto', array(
					'external_reference'  => $external_reference,
					'pendiente_id'        => (int) $fila->id,
					'miembro_id'          => (int) $socia->id,
					'preapproval_nuevo'   => $preapproval_id,
					'preapproval_actual'  => (string) $socia->preapproval_id,
				) );
				self::sub_avisar_una_vez_por_hora( 'sub_conflicto', $external_reference, array(
					'external_reference' => $external_reference,
					'miembro_id'         => (int) $socia->id,
					'preapproval_nuevo'  => $preapproval_id,
				) );
				return true;
			} else {
				// Ya tiene ESE mismo preapproval_id: no se pisa nada. Solo se completan plan y
				// acceso_hasta si hoy están en NULL (p. ej. el pago llegó antes y el camino de
				// siempre vinculó el preapproval_id sin ellos). Si la propiedad no vino en el
				// SELECT, no se asume NULL: no se escribe.
				$completar = array();
				if ( null !== $plan && property_exists( $socia, 'plan' ) && null === $socia->plan ) {
					$completar['plan'] = $plan;
				}
				if ( null !== $acceso_hasta && property_exists( $socia, 'acceso_hasta' ) && null === $socia->acceso_hasta ) {
					$completar['acceso_hasta'] = $acceso_hasta;
				}
				if ( $completar ) {
					$ok = $wpdb->update(
						$t_miembros,
						$completar,
						array( 'id' => (int) $socia->id ),
						array_fill( 0, count( $completar ), '%s' ),
						array( '%d' )
					);
					if ( false === $ok ) {
						throw new RuntimeException( 'no se pudo completar plan/acceso_hasta: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
					}
				}
			}
			$ok = $wpdb->update( $t_pend, array( 'usado' => 1 ), array( 'id' => (int) $fila->id ), array( '%d' ), array( '%d' ) );
			if ( false === $ok ) {
				throw new RuntimeException( 'no se pudo marcar la pendiente: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'no se pudo confirmar la transaccion' );
			}
			$en_transaccion = false;
			// Ya confirmado: mail de bienvenida y CAPI quedan FUERA de la transacción y nunca afectan el alta.
			if ( $es_alta ) {
				self::sub_post_alta( $fila, $plan, $planes );
			}
			return true;
		} catch ( Throwable $e ) {
			if ( $en_transaccion ) {
				$wpdb->query( 'ROLLBACK' );
			}
			self::registrar_log_mp( 'sub_error', array(
				'external_reference' => $external_reference,
				'mensaje'            => mb_substr( $e->getMessage(), 0, 300 ),
			) );
			self::sub_avisar_una_vez_por_hora( 'sub_error', $external_reference, array(
				'external_reference' => $external_reference,
				'mensaje'            => mb_substr( $e->getMessage(), 0, 300 ),
			) );
			return true;
		}
	}
	/**
	 * Después del COMMIT de un alta/activación "sub_": mail de bienvenida y evento CAPI "Subscribe".
	 * Cada paso en su propio try/catch: si uno falla se deja el rastro y el alta ya hecha no se toca.
	 */
	protected static function sub_post_alta( $fila, $plan, $planes ) {
		$email    = trim( (string) $fila->email );
		$celular  = (string) $fila->celular;
		$nombre_p = ( null !== $plan && isset( $planes[ $plan ] ) ) ? (string) $planes[ $plan ]['nombre'] : 'Club Natureza';
		try {
			if ( '' !== $email ) {
				self::enviar_mail_bienvenida_suscripcion( (string) $fila->nombre_apellido, $celular, $email, $nombre_p );
			}
		} catch ( Throwable $e ) {
			self::registrar_log_mp( 'sub_post_alta_error', array( 'paso' => 'mail', 'external_reference' => (string) $fila->external_reference, 'mensaje' => mb_substr( $e->getMessage(), 0, 200 ) ) );
		}
		try {
			if ( null !== $plan && isset( $planes[ $plan ] ) ) {
				CN_Meta_Capi::enviar_subscribe(
					(string) $fila->external_reference,
					(float) $planes[ $plan ]['precio'],
					$email,
					$celular,
					$nombre_p,
					(string) $fila->fbp,
					(string) $fila->fbc,
					(string) $fila->ip,
					(string) $fila->user_agent
				);
			}
		} catch ( Throwable $e ) {
			self::registrar_log_mp( 'sub_post_alta_error', array( 'paso' => 'capi', 'external_reference' => (string) $fila->external_reference, 'mensaje' => mb_substr( $e->getMessage(), 0, 200 ) ) );
		}
	}
	/**
	 * Mail de bienvenida de una socia que se suscribió (no es el del trial: sin "7 días" ni "nunca se te cobra").
	 * Mismo wrapper HTML que enviar_mail_bienvenida(). El usuario es el nombre y la contraseña el celular.
	 */
	protected static function enviar_mail_bienvenida_suscripcion( $nombre, $celular, $email, $plan_nombre ) {
		if ( '' === $email ) {
			return;
		}
		$login_url = home_url( '/club-natureza-miembros/' );
		$asunto    = '¡Ya sos socia del Club Natureza! 🌿 — tus datos de acceso';
		$cuerpo  = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f5f3ef;font-family:Georgia,serif;">';
		$cuerpo .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f3ef;padding:32px 16px;">';
		$cuerpo .= '<tr><td align="center">';
		$cuerpo .= '<table role="presentation" width="100%" style="max-width:480px;background:#ffffff;border-radius:12px;overflow:hidden;">';
		$cuerpo .= '<tr><td style="background:#2f3b2c;padding:28px 32px;text-align:center;">';
		$cuerpo .= '<span style="color:#f5f3ef;font-size:22px;letter-spacing:1px;">🌿 Club Natureza</span></td></tr>';
		$cuerpo .= '<tr><td style="padding:32px;">';
		$cuerpo .= '<h1 style="font-size:20px;color:#2f3b2c;margin:0 0 16px;">¡Bienvenida, ' . esc_html( $nombre ) . '! 🎉</h1>';
		$planes    = CN_MP::get_planes();
		$es_basico = isset( $planes['basico']['nombre'] ) && (string) $planes['basico']['nombre'] === (string) $plan_nombre; // el básico se llama igual que el club: sin paréntesis repetido.
		$cuerpo .= '<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 20px;">Tu suscripción al Club Natureza' . ( $es_basico ? '' : ' (' . esc_html( $plan_nombre ) . ')' ) . ' está activa.</p>';
		$cuerpo .= '<table role="presentation" width="100%" style="background:#f5f3ef;border-radius:8px;margin:0 0 24px;">';
		$cuerpo .= '<tr><td style="padding:18px 20px;">';
		$cuerpo .= '<p style="font-size:13px;color:#777;margin:0 0 4px;">USUARIO (nombre y apellido)</p>';
		$cuerpo .= '<p style="font-size:16px;color:#2f3b2c;margin:0 0 14px;font-weight:bold;">' . esc_html( $nombre ) . '</p>';
		$cuerpo .= '<p style="font-size:13px;color:#777;margin:0 0 4px;">CELULAR (el que registraste)</p>';
		$cuerpo .= '<p style="font-size:16px;color:#2f3b2c;margin:0;font-weight:bold;">' . esc_html( $celular ) . '</p>';
		$cuerpo .= '</td></tr></table>';
		$cuerpo .= '<p style="font-size:14px;color:#555;line-height:1.6;margin:0 0 24px;">Escribí tu nombre y apellido tal cual figura arriba.</p>';
		$cuerpo .= '<table role="presentation" width="100%"><tr><td align="center">';
		$cuerpo .= '<a href="' . esc_url( $login_url ) . '" style="display:inline-block;background:#2f3b2c;color:#f5f3ef;text-decoration:none;padding:14px 32px;border-radius:8px;font-size:15px;margin-bottom:24px;">Entrar al Club →</a>';
		$cuerpo .= '</td></tr></table>';
		$cuerpo .= '<p style="font-size:14px;color:#555;line-height:1.6;margin:24px 0 0;">Tu suscripción se renueva cada mes y podés cancelarla cuando quieras desde Mercado Pago. Cualquier duda, escribinos por WhatsApp.</p>';
		$cuerpo .= '<p style="font-size:15px;color:#2f3b2c;margin:20px 0 0;">¡Bienvenida! 🌿</p>';
		$cuerpo .= '</td></tr></table></td></tr></table></body></html>';
		wp_mail( $email, $asunto, $cuerpo, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
	/**
	 * next_payment_date de la suscripción en MP, en UTC ('Y-m-d H:i:s'); null si no viene o
	 * no se puede leer (CN_MP::get ya deja el rastro 'mp_api_error', sin tokens).
	 */
	protected static function sub_acceso_hasta( $preapproval_id ) {
		return self::acceso_desde_data( CN_MP::obtener_preapproval( $preapproval_id ) );
	}
	/** next_payment_date de un preapproval YA consultado a MP, en UTC ('Y-m-d H:i:s'); null si no viene o no se puede leer. */
	public static function acceso_desde_data( $data ) {
		if ( ! is_array( $data ) || empty( $data['next_payment_date'] ) || ! is_string( $data['next_payment_date'] ) ) {
			return null;
		}
		$ts = strtotime( $data['next_payment_date'] );
		if ( false === $ts ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
	/** Antes: aviso a n8n con tope de 1 por hora. Ahora: queda en el cartel "Avisos" del panel (deduplicado, con contador); no manda nada afuera. */
	public static function sub_avisar_una_vez_por_hora( $motivo, $external_reference, $detalle ) {
		self::avisar_error_n8n( $motivo, $detalle );
	}
	/**
	 * Ciclo de vida de una suscripción "sub_" ya vinculada (Fase 3-C). Solo la llama aplicar_estado() con el
	 * flag activo. Actúa ÚNICAMENTE sobre una fila con ese preapproval_id y plan no nulo.
	 *  - cancelled/paused: programa la baja (baja_en = ahora), deja estado 'activo' y acceso_hasta tal cual
	 *    (el acceso corre hasta el fin del período pago). Sin acceso_hasta vigente: solo loguea y alerta.
	 *    Devuelve true (el evento queda atendido: el camino de siempre no vuelve a tocarlo).
	 *  - authorized: si había baja programada, la limpia (reanudación); si no, extiende acceso_hasta con el
	 *    next_payment_date de MP solo si es mayor. approved (pago): solo la extensión. Ambos devuelven false:
	 *    el camino de siempre sigue exactamente igual.
	 * Cualquier error se registra y alerta; para cancelled/paused devuelve true (nunca dejar que el camino de
	 * siempre baje a una socia por un fallo nuestro).
	 */
	public static function gestionar_ciclo_sub( $status, $preapproval_id, $data_mp = null ) {
		global $wpdb;
		$preapproval_id = (string) $preapproval_id;
		if ( '' === $preapproval_id || ! in_array( $status, array( 'authorized', 'approved', 'cancelled', 'paused' ), true ) ) {
			return false;
		}
		$es_baja = in_array( $status, array( 'cancelled', 'paused' ), true );
		try {
			$t_miembros = CN_DB::tabla( 'miembros' );
			$socia = $wpdb->get_row(
				$wpdb->prepare( "SELECT id, estado, plan, acceso_hasta, baja_en FROM {$t_miembros} WHERE preapproval_id = %s LIMIT 1", $preapproval_id )
			);
			if ( '' !== (string) $wpdb->last_error ) {
				throw new RuntimeException( 'error buscando la suscripcion: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			if ( ! $socia || ! isset( $socia->plan ) || '' === (string) $socia->plan ) {
				return false; // no es una alta de esta función: camino de siempre.
			}
			$ahora  = current_time( 'mysql', true );
			$acceso = isset( $socia->acceso_hasta ) ? (string) $socia->acceso_hasta : '';
			$baja   = isset( $socia->baja_en ) ? (string) $socia->baja_en : '';
			if ( $es_baja ) {
				if ( '' !== $baja ) {
					return true; // baja ya programada: no se pisa la fecha original.
				}
				if ( '' === $acceso || $acceso <= $ahora ) {
					$detalle = array(
						'miembro_id'     => (int) $socia->id,
						'preapproval_id' => $preapproval_id,
						'status_mp'      => $status,
						'acceso_hasta'   => '' === $acceso ? null : $acceso,
					);
					self::registrar_log_mp( 'sub_baja_sin_periodo', $detalle );
					self::sub_avisar_una_vez_por_hora( 'sub_baja_sin_periodo', $preapproval_id, $detalle );
					return true; // sin período pago vigente: no se cambia el estado, lo resuelve una persona.
				}
				$ok = $wpdb->update(
					$t_miembros,
					array( 'baja_en' => $ahora, 'fecha_modificacion' => $ahora ),
					array( 'id' => (int) $socia->id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				if ( false === $ok ) {
					throw new RuntimeException( 'no se pudo programar la baja: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
				}
				self::registrar_log_mp( 'sub_baja_programada', array(
					'miembro_id'     => (int) $socia->id,
					'preapproval_id' => $preapproval_id,
					'status_mp'      => $status,
					'acceso_hasta'   => $acceso,
				) );
				return true;
			}
			if ( '' !== $baja ) {
				if ( 'authorized' === $status ) {
					$ok = $wpdb->update(
						$t_miembros,
						array( 'baja_en' => null, 'fecha_modificacion' => $ahora ),
						array( 'id' => (int) $socia->id ),
						array( '%s', '%s' ),
						array( '%d' )
					);
					if ( false === $ok ) {
						throw new RuntimeException( 'no se pudo limpiar la baja: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
					}
					self::registrar_log_mp( 'sub_baja_revertida', array( 'miembro_id' => (int) $socia->id, 'preapproval_id' => $preapproval_id ) );
				}
				return false;
			}
			// Renovación: acceso_hasta solo avanza.
			// $data_mp: el sync por cron ya consultó el preapproval; así no se repite la llamada a MP.
			$nuevo = is_array( $data_mp ) ? self::acceso_desde_data( $data_mp ) : self::sub_acceso_hasta( $preapproval_id );
			if ( null !== $nuevo && ( '' === $acceso || $nuevo > $acceso ) ) {
				$ok = $wpdb->update(
					$t_miembros,
					array( 'acceso_hasta' => $nuevo ),
					array( 'id' => (int) $socia->id ),
					array( '%s' ),
					array( '%d' )
				);
				if ( false === $ok ) {
					throw new RuntimeException( 'no se pudo extender acceso_hasta: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
				}
			}
			return false;
		} catch ( Throwable $e ) {
			self::registrar_log_mp( 'sub_error', array(
				'paso'           => 'ciclo',
				'preapproval_id' => $preapproval_id,
				'mensaje'        => mb_substr( $e->getMessage(), 0, 300 ),
			) );
			self::sub_avisar_una_vez_por_hora( 'sub_error', $preapproval_id, array(
				'paso'           => 'ciclo',
				'preapproval_id' => $preapproval_id,
				'mensaje'        => mb_substr( $e->getMessage(), 0, 300 ),
			) );
			return $es_baja;
		}
	}
	/**
	 * Cron diario: pasa a 'cancelada' SOLO las suscripciones con baja programada cuyo período pago ya terminó.
	 * Condiciones (todas): baja_en NOT NULL, acceso_hasta vencido, preapproval_id no vacío y plan no nulo.
	 * Nunca toca filas sin baja_en: trial, cargadas a mano, y socias de siempre quedan fuera.
	 */
	public static function revocar_bajas_sub_vencidas() {
		global $wpdb;
		try {
			$t_miembros = CN_DB::tabla( 'miembros' );
			$donde = "baja_en IS NOT NULL AND baja_en <= UTC_TIMESTAMP() AND acceso_hasta IS NOT NULL AND acceso_hasta < UTC_TIMESTAMP() AND preapproval_id IS NOT NULL AND preapproval_id <> '' AND plan IS NOT NULL AND estado <> 'cancelada'";
			$filas = $wpdb->get_results( "SELECT id FROM {$t_miembros} WHERE {$donde}" );
			if ( '' !== (string) $wpdb->last_error ) {
				throw new RuntimeException( 'error buscando bajas vencidas: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			if ( ! $filas ) {
				return;
			}
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t_miembros} SET estado = 'cancelada', fecha_modificacion = %s WHERE {$donde}", current_time( 'mysql', true ) ) );
			if ( false === $n ) {
				throw new RuntimeException( 'no se pudieron cancelar las bajas vencidas: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			self::registrar_log_mp( 'sub_baja_ejecutada', array(
				'miembro_ids' => array_map( function ( $f ) {
					return (int) $f->id;
				}, $filas ),
				'filas'       => (int) $n,
			) );
		} catch ( Throwable $e ) {
			self::registrar_log_mp( 'sub_error', array( 'paso' => 'cron_bajas', 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
			self::sub_avisar_una_vez_por_hora( 'sub_error', 'cron_bajas', array( 'paso' => 'cron_bajas', 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
		}
	}
	// ==========================================================================
	// VINCULACIÓN MANUAL DE SUSCRIPCIONES (Fase 5, punto 1). Une un preapproval YA autorizado en MP con una socia
	// que YA existe (se elige por id). NUNCA crea socias. Misma lógica transaccional (FOR UPDATE) que
	// activar_por_suscripcion_sub(), pero sin fila en suscripciones_pendientes. La usa SOLO la pantalla "Vincular"
	// del admin: ni el webhook ni los crons la llaman, y nada se vincula solo.
	// ==========================================================================
	/**
	 * Monto mensual de la suscripción en MP -> clave del mapa CN_MP::PLANES. $15.000 es el plan viejo (= basico).
	 */
	const PLAN_POR_MONTO = array(
		15000 => 'basico',
		15500 => 'basico',
		32000 => 'full',
	);
	const VINC_COLUMNAS = 'id, nombre_apellido, estado, preapproval_id, plan, acceso_hasta, email, fecha_fin_trial, celular_texto_plano';
	/** Clave de plan para un monto de MP (acepta 15500, "15500.00", 15500.0). null si no es uno de los montos conocidos. */
	public static function plan_por_monto( $monto ) {
		if ( ! is_numeric( $monto ) ) {
			return null;
		}
		$f = (float) $monto;
		if ( abs( $f - round( $f ) ) > 0.001 ) {
			return null;
		}
		$k = (int) round( $f );
		if ( ! isset( self::PLAN_POR_MONTO[ $k ] ) ) {
			return null;
		}
		$plan = self::PLAN_POR_MONTO[ $k ];
		return isset( CN_MP::get_planes()[ $plan ] ) ? $plan : null;
	}
	/**
	 * Vincula un preapproval de MP con una socia EXISTENTE (por id).
	 *
	 * Exige en MP: status 'authorized' y un monto mensual conocido (plan_por_monto). El plan sale del monto y
	 * acceso_hasta de next_payment_date. Según lo que haya en la base (socia por id, con FOR UPDATE):
	 *  - activada:   la socia no tiene preapproval_id -> se activa (también si estaba cancelada/pausada), se le
	 *                guardan plan/preapproval_id/acceso_hasta y se limpia fecha_fin_trial.
	 *  - completada: ya tenía ESTE preapproval_id -> solo completa plan/acceso_hasta si estaban en NULL (idempotente).
	 *  - conflicto:  la socia tiene OTRO preapproval_id -> no se toca nada; log sub_conflicto + alerta n8n.
	 *  - rechazada:  validación o MP no cumple, la socia no existe (nunca se crea una), o el preapproval ya está
	 *                vinculado a OTRA socia.
	 * Mail de bienvenida y evento CAPI Subscribe solo si $notificar es true y la acción fue "activada", siempre
	 * DESPUÉS del COMMIT (sub_post_alta). Si MP falla no se escribe nada.
	 *
	 * @return array{ok:bool,accion:string,mensaje:string,miembro_id:?int,estado_anterior:?string,estado_nuevo:?string,plan:?string,acceso_hasta:?string,notificacion:array{mail:bool,capi:bool}}
	 */
	public static function vincular_suscripcion( $preapproval_id, $miembro_id, $notificar = false ) {
		global $wpdb;
		$res            = self::vinc_resultado_base();
		$preapproval_id = is_string( $preapproval_id ) ? trim( $preapproval_id ) : '';
		$miembro_id     = (int) $miembro_id;
		$notificar      = (bool) $notificar;
		if ( $miembro_id <= 0 ) {
			$res['mensaje'] = 'Elegí una socia existente.';
			return $res;
		}
		// MP se consulta ANTES de abrir la transacción (no se bloquean filas mientras se espera a MP).
		$mp = self::vinc_consultar_mp( $preapproval_id );
		if ( ! $mp['ok'] ) {
			$res['mensaje'] = $mp['error'];
			self::registrar_log_mp( 'sub_vinculacion_rechazada', array(
				'preapproval_id' => mb_substr( $preapproval_id, 0, 64 ),
				'motivo'         => mb_substr( $mp['error'], 0, 200 ),
			) );
			return $res;
		}
		$res['plan']         = $mp['plan'];
		$res['acceso_hasta'] = $mp['acceso_hasta'];
		$t_miembros     = CN_DB::tabla( 'miembros' );
		$en_transaccion = false;
		$es_alta        = false; // true solo si se activó (nunca en completada).
		$aviso          = null;  // datos para sub_post_alta, armados con la fila bloqueada.
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'no se pudo abrir la transaccion' );
			}
			$en_transaccion = true;
			list( $vinculada, $socia ) = self::vinc_buscar( $preapproval_id, $miembro_id, true );
			list( $accion, $fila )     = self::vinc_decidir( $vinculada, $socia, $preapproval_id );
			$ahora = current_time( 'mysql', true );
			if ( 'inexistente' === $accion ) {
				$wpdb->query( 'ROLLBACK' );
				$en_transaccion = false;
				$res['mensaje'] = 'No existe la socia con id ' . $miembro_id . '. No se crea ninguna socia nueva y no se cambió nada.';
				self::registrar_log_mp( 'sub_vinculacion_rechazada', array(
					'preapproval_id' => $preapproval_id,
					'motivo'         => 'socia_inexistente',
					'miembro_id'     => $miembro_id,
				) );
				return $res;
			}
			if ( 'rechazada' === $accion ) {
				$wpdb->query( 'ROLLBACK' );
				$en_transaccion = false;
				$res['mensaje'] = 'Ese preapproval ya está vinculado a otra socia (id ' . (int) $fila->id . '). No se cambió nada.';
				self::registrar_log_mp( 'sub_vinculacion_rechazada', array(
					'preapproval_id' => $preapproval_id,
					'motivo'         => 'ya_vinculado_a_otra_socia',
					'miembro_id'     => (int) $fila->id,
				) );
				return $res;
			}
			if ( 'conflicto' === $accion ) {
				$wpdb->query( 'ROLLBACK' );
				$en_transaccion = false;
				$res['accion']          = 'conflicto';
				$res['miembro_id']      = (int) $fila->id;
				$res['estado_anterior'] = (string) $fila->estado;
				$res['estado_nuevo']    = (string) $fila->estado;
				$res['mensaje']         = 'La socia (id ' . (int) $fila->id . ') ya tiene OTRA suscripción vinculada. No se cambió nada: resolvelo a mano.';
				$clave                  = 'vinc_' . $preapproval_id;
				$detalle                = array(
					'origen'             => 'vinculacion_manual',
					'external_reference' => $clave,
					'miembro_id'         => (int) $fila->id,
					'preapproval_nuevo'  => $preapproval_id,
				);
				self::registrar_log_mp( 'sub_conflicto', $detalle + array( 'preapproval_actual' => (string) $fila->preapproval_id ) );
				self::sub_avisar_una_vez_por_hora( 'sub_conflicto', $clave, $detalle );
				return $res;
			}
			if ( 'activada' === $accion ) {
				$ok = $wpdb->update(
					$t_miembros,
					array(
						'estado'             => 'activo',
						'preapproval_id'     => $preapproval_id,
						'plan'               => $mp['plan'],
						'acceso_hasta'       => $mp['acceso_hasta'],
						'fecha_fin_trial'    => null,
						'fecha_modificacion' => $ahora,
					),
					array( 'id' => (int) $fila->id ),
					array( '%s', '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
				if ( false === $ok ) {
					throw new RuntimeException( 'no se pudo activar a la socia: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
				}
				$res['estado_anterior'] = (string) $fila->estado;
				$res['estado_nuevo']    = 'activo';
				$res['mensaje']         = 'Socia existente activada con la suscripción.';
				$es_alta                = true;
				$email_aviso            = '' !== trim( (string) $fila->email ) ? trim( (string) $fila->email ) : (string) $mp['payer_email'];
				$aviso                  = array(
					'nombre'  => (string) $fila->nombre_apellido,
					'celular' => trim( (string) $fila->celular_texto_plano ),
					'email'   => $email_aviso,
				);
			} else { // completada: ya tenía ESTE preapproval_id.
				$completar = array();
				if ( null !== $mp['plan'] && null === $fila->plan ) {
					$completar['plan'] = $mp['plan'];
				}
				if ( null !== $mp['acceso_hasta'] && null === $fila->acceso_hasta ) {
					$completar['acceso_hasta'] = $mp['acceso_hasta'];
				}
				if ( $completar ) {
					$ok = $wpdb->update(
						$t_miembros,
						$completar,
						array( 'id' => (int) $fila->id ),
						array_fill( 0, count( $completar ), '%s' ),
						array( '%d' )
					);
					if ( false === $ok ) {
						throw new RuntimeException( 'no se pudo completar plan/acceso_hasta: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
					}
				}
				$res['estado_anterior'] = (string) $fila->estado;
				$res['estado_nuevo']    = (string) $fila->estado;
				$res['mensaje']         = $completar
					? 'Ya estaba vinculada: se completaron ' . implode( ' y ', array_keys( $completar ) ) . '. No se cambió el estado (' . (string) $fila->estado . ').'
					: 'Ya estaba vinculada con esta suscripción: no había nada para completar. No se cambió el estado (' . (string) $fila->estado . ').';
			}
			$res['miembro_id'] = (int) $fila->id;
			$res['accion']     = $accion;
			$res['ok']         = true;
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'no se pudo confirmar la transaccion' );
			}
			$en_transaccion = false;
		} catch ( Throwable $e ) {
			if ( $en_transaccion ) {
				$wpdb->query( 'ROLLBACK' );
			}
			$clave   = 'vinc_' . $preapproval_id;
			$detalle = array(
				'paso'               => 'vinculacion_manual',
				'external_reference' => $clave,
				'mensaje'            => mb_substr( $e->getMessage(), 0, 300 ),
			);
			self::registrar_log_mp( 'sub_error', $detalle );
			self::sub_avisar_una_vez_por_hora( 'sub_error', $clave, $detalle );
			$res            = self::vinc_resultado_base();
			$res['mensaje'] = 'Error interno al vincular: no se guardó nada. Quedó registrado en el log de Mercado Pago.';
			return $res;
		}
		// Ya confirmado: registro de auditoría (sin celular ni email) y, si se pidió, mail + CAPI fuera de la transacción.
		self::registrar_log_mp( 'sub_vinculada_admin', array(
			'preapproval_id'  => $preapproval_id,
			'accion'          => $res['accion'],
			'miembro_id'      => $res['miembro_id'],
			'estado_anterior' => $res['estado_anterior'],
			'estado_nuevo'    => $res['estado_nuevo'],
			'plan'            => $res['plan'],
			'notificar'       => $notificar,
		) );
		if ( $es_alta && $notificar && is_array( $aviso ) ) {
			if ( '' === $aviso['celular'] ) {
				// Sin el celular guardado el mail saldría sin contraseña: no se manda nada (ni Meta).
				$res['mensaje'] .= ' No se envió mail ni evento Meta: la socia no tiene el celular guardado.';
			} else {
				$fila_post = (object) array(
					'nombre_apellido'    => $aviso['nombre'],
					'celular'            => $aviso['celular'],
					'email'              => $aviso['email'],
					'external_reference' => 'vinc_' . $preapproval_id, // event_id estable por preapproval (sin celular).
					'fbp'                => '',
					'fbc'                => '',
					'ip'                 => '',
					'user_agent'         => '',
				);
				self::sub_post_alta( $fila_post, $mp['plan'], CN_MP::get_planes() ); // try/catch propio: nunca afecta lo ya guardado.
				$res['notificacion'] = array( 'mail' => ( '' !== $aviso['email'] ), 'capi' => true );
			}
		}
		return $res;
	}
	/**
	 * SOLO LECTURA: lo que haría vincular_suscripcion() con estos datos, sin escribir nada en la base (consulta
	 * a MP y SELECT sin lock). Lo usa el botón "Verificar" del admin.
	 * 'caso' (cuando accion_prevista no es null): trial | cancelada | sin_suscripcion | ya_vinculada | conflicto | otra_socia.
	 *
	 * @return array{ok:bool,error:string,mp:array,accion_prevista:?string,caso:?string,socia:?array,mensaje:string}
	 */
	public static function vincular_suscripcion_verificar( $preapproval_id, $miembro_id ) {
		$salida = array( 'ok' => false, 'error' => '', 'mp' => array(), 'accion_prevista' => null, 'caso' => null, 'socia' => null, 'mensaje' => '' );
		$preapproval_id = is_string( $preapproval_id ) ? trim( $preapproval_id ) : '';
		$miembro_id     = (int) $miembro_id;
		$mp = self::vinc_consultar_mp( $preapproval_id );
		$salida['mp'] = array(
			'status'            => $mp['status'],
			'plan'              => $mp['plan'],
			'plan_nombre'       => $mp['plan_nombre'],
			'precio'            => $mp['precio'],
			'monto'             => $mp['monto'],
			'acceso_hasta'      => $mp['acceso_hasta'],
			'next_payment_date' => $mp['next_payment_date'],
			'payer_email'       => $mp['payer_email'],
			'reason'            => $mp['reason'],
		);
		if ( ! $mp['ok'] ) {
			$salida['error'] = $mp['error'];
			return $salida;
		}
		if ( $miembro_id <= 0 ) {
			$salida['error'] = 'Elegí una socia existente de la lista.';
			return $salida;
		}
		$salida['ok'] = true;
		try {
			list( $vinculada, $socia ) = self::vinc_buscar( $preapproval_id, $miembro_id, false );
		} catch ( Throwable $e ) {
			$salida['ok']    = false;
			$salida['error'] = 'No se pudo consultar la base de socias.';
			return $salida;
		}
		list( $accion, $fila ) = self::vinc_decidir( $vinculada, $socia, $preapproval_id );
		$salida['accion_prevista'] = $accion;
		if ( $fila ) {
			$salida['socia'] = array(
				'id'              => (int) $fila->id,
				'nombre_apellido' => (string) $fila->nombre_apellido,
				'estado'          => (string) $fila->estado,
				'en_trial'        => ! empty( $fila->fecha_fin_trial ),
				'email'           => (string) $fila->email,
				'preapproval_id'  => (string) $fila->preapproval_id,
				'plan'            => (string) $fila->plan,
			);
		}
		if ( 'inexistente' === $accion ) {
			$salida['ok']      = false;
			$salida['error']   = 'No existe la socia con id ' . $miembro_id . '. Esta pantalla nunca crea socias nuevas.';
			$salida['mensaje'] = $salida['error'];
		} elseif ( 'activada' === $accion ) {
			if ( 'cancelada' === (string) $fila->estado ) {
				$salida['caso']    = 'cancelada';
				$salida['mensaje'] = 'Se reactivaría a la socia cancelada y se le vincularía la suscripción.';
			} elseif ( ! empty( $fila->fecha_fin_trial ) ) {
				$salida['caso']    = 'trial';
				$salida['mensaje'] = 'Se activaría la socia que está en trial (se limpia el fin de trial) y se le vincularía la suscripción.';
			} else {
				$salida['caso']    = 'sin_suscripcion';
				$salida['mensaje'] = 'Se activaría a la socia (hoy sin suscripción vinculada) y se le vincularía la suscripción.';
			}
		} elseif ( 'completada' === $accion ) {
			$salida['caso']    = 'ya_vinculada';
			$salida['mensaje'] = 'Ya está vinculada con esta suscripción: solo se completarían plan/acceso si estuvieran vacíos. No cambia el estado.';
		} elseif ( 'conflicto' === $accion ) {
			$salida['caso']    = 'conflicto';
			$salida['mensaje'] = 'CONFLICTO: esa socia ya tiene OTRA suscripción vinculada. No se podría vincular (se avisaría por n8n).';
		} else {
			$salida['caso']    = 'otra_socia';
			$salida['mensaje'] = 'RECHAZADA: ese preapproval ya está vinculado a otra socia.';
		}
		return $salida;
	}
	protected static function vinc_resultado_base() {
		return array(
			'ok'              => false,
			'accion'          => 'rechazada',
			'mensaje'         => '',
			'miembro_id'      => null,
			'estado_anterior' => null,
			'estado_nuevo'    => null,
			'plan'            => null,
			'acceso_hasta'    => null,
			'notificacion'    => array( 'mail' => false, 'capi' => false ),
		);
	}
	/**
	 * Consulta el preapproval a MP y valida lo que exige la vinculación: status 'authorized' y monto mensual conocido
	 * ($15.000 / $15.500 -> basico, $32.000 -> full). Solo lectura. acceso_hasta = next_payment_date en UTC, con la
	 * misma conversión que sub_acceso_hasta() (sin segunda consulta).
	 */
	protected static function vinc_consultar_mp( $preapproval_id ) {
		$r = array(
			'ok' => false, 'error' => '', 'status' => '', 'plan' => null, 'plan_nombre' => '', 'precio' => 0, 'monto' => null,
			'acceso_hasta' => null, 'next_payment_date' => '', 'payer_email' => '', 'reason' => '',
		);
		if ( ! is_string( $preapproval_id ) || ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $preapproval_id ) ) {
			$r['error'] = 'El ID de preapproval no tiene un formato válido.';
			return $r;
		}
		$data = CN_MP::obtener_preapproval( $preapproval_id );
		if ( ! is_array( $data ) || empty( $data['status'] ) || ! is_string( $data['status'] ) ) {
			$r['error'] = 'No se pudo consultar Mercado Pago o ese ID no existe. No se cambió nada.';
			return $r;
		}
		if ( isset( $data['id'] ) && (string) $data['id'] !== $preapproval_id ) {
			$r['error'] = 'Mercado Pago devolvió una suscripción con otro ID. No se vincula.';
			return $r;
		}
		$r['status']            = sanitize_text_field( $data['status'] );
		$r['reason']            = ( isset( $data['reason'] ) && is_string( $data['reason'] ) ) ? sanitize_text_field( $data['reason'] ) : '';
		$r['payer_email']       = ( isset( $data['payer_email'] ) && is_string( $data['payer_email'] ) ) ? sanitize_email( trim( $data['payer_email'] ) ) : '';
		$r['next_payment_date'] = ( isset( $data['next_payment_date'] ) && is_string( $data['next_payment_date'] ) ) ? sanitize_text_field( $data['next_payment_date'] ) : '';
		$r['acceso_hasta']      = self::acceso_desde_data( $data );
		$r['monto']             = isset( $data['auto_recurring']['transaction_amount'] ) ? $data['auto_recurring']['transaction_amount'] : null;
		if ( 'authorized' !== $r['status'] ) {
			$r['error'] = 'La suscripción no está autorizada en Mercado Pago (estado: ' . $r['status'] . '). Solo se vinculan las autorizadas.';
			return $r;
		}
		$plan = self::plan_por_monto( $r['monto'] );
		if ( null === $plan ) {
			$r['error'] = 'El monto mensual de esa suscripción no es uno de los conocidos ($15.000, $15.500 o $32.000; en MP: ' . ( is_scalar( $r['monto'] ) ? sanitize_text_field( (string) $r['monto'] ) : 'ninguno' ) . '). No se vincula.';
			return $r;
		}
		$planes           = CN_MP::get_planes();
		$r['plan']        = $plan;
		$r['plan_nombre'] = (string) $planes[ $plan ]['nombre'];
		$r['precio']      = $planes[ $plan ]['precio'];
		$r['ok']          = true;
		return $r;
	}
	/**
	 * Busca (1) a la socia por id y (2) la fila ya vinculada a ese preapproval_id. Con $bloquear = true usa
	 * FOR UPDATE (dentro de la transacción; siempre en ese orden para no cruzar locks). Lanza si la base da error.
	 *
	 * @return array{0:?object,1:?object} array( $vinculada, $socia )
	 */
	protected static function vinc_buscar( $preapproval_id, $miembro_id, $bloquear ) {
		global $wpdb;
		$t_miembros = CN_DB::tabla( 'miembros' );
		$lock       = $bloquear ? ' FOR UPDATE' : '';
		$socia      = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ' . self::VINC_COLUMNAS . " FROM {$t_miembros} WHERE id = %d{$lock}", (int) $miembro_id )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'error buscando a la socia: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
		}
		$vinculada = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ' . self::VINC_COLUMNAS . " FROM {$t_miembros} WHERE preapproval_id = %s LIMIT 1{$lock}", $preapproval_id )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'error buscando el preapproval: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
		}
		return array( $vinculada ? $vinculada : null, $socia ? $socia : null );
	}
	/**
	 * Decisión pura (sin escribir): inexistente | rechazada | conflicto | activada | completada, y la fila implicada.
	 * "Sin preapproval" es la comparación estricta de texto vacío: NULL y '' cuentan como vacío; '0' NO (es un valor).
	 *
	 * @return array{0:string,1:?object}
	 */
	protected static function vinc_decidir( $vinculada, $socia, $preapproval_id ) {
		if ( ! $socia ) {
			return array( 'inexistente', null );
		}
		if ( $vinculada && (int) $vinculada->id !== (int) $socia->id ) {
			return array( 'rechazada', $vinculada ); // ya vinculado a OTRA socia.
		}
		$actual = (string) $socia->preapproval_id;
		if ( '' === $actual ) {
			return array( 'activada', $socia );
		}
		if ( $actual !== (string) $preapproval_id ) {
			return array( 'conflicto', $socia );
		}
		return array( 'completada', $socia );
	}
	// ==========================================================================
	// TRIAL DE 7 DÍAS — endpoint nuevo, separado del flujo de suscripción mensual.
	// ==========================================================================
	public static function manejar_trial( WP_REST_Request $request ) {
		global $wpdb;
		$json = json_decode( $request->get_body(), true );
		if ( ! is_array( $json ) ) {
			$json = array();
		}
		$tipo = '';
		$id   = '';
		if ( ! empty( $json['type'] ) ) {
			$tipo = sanitize_text_field( $json['type'] );
		} elseif ( $request->get_param( 'type' ) ) {
			$tipo = sanitize_text_field( $request->get_param( 'type' ) );
		} elseif ( $request->get_param( 'topic' ) ) {
			$tipo = sanitize_text_field( $request->get_param( 'topic' ) );
		}
		if ( ! empty( $json['data']['id'] ) ) {
			$id = sanitize_text_field( (string) $json['data']['id'] );
		} elseif ( $request->get_param( 'id' ) ) {
			$id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		} elseif ( ! empty( $json['id'] ) ) {
			$id = sanitize_text_field( (string) $json['id'] );
		}
		// Loguear siempre, aunque no podamos procesar (misma tabla que el webhook de suscripción).
		$wpdb->insert(
			CN_DB::tabla( 'mp_log' ),
			array(
				'payload' => wp_json_encode( array( 'body' => $json, 'query' => $request->get_query_params(), 'endpoint' => 'trial-alta' ) ),
				'tipo'    => $tipo ? $tipo : 'desconocido',
				'fecha'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
		// Solo nos importan notificaciones de "payment" — el trial es pago único, no preapproval.
		// MP también manda notificaciones de tipo "merchant_order" (la orden, no el pago);
		// esas hay que ignorarlas siempre, aunque traigan un "id" — ese id es de la orden,
		// no de un pago, y consultarlo como pago vía /v1/payments/{id} siempre falla
		// ("pago_no_encontrado"), aunque el pago real detrás de esa orden sí exista y
		// esté aprobado. La notificación de tipo "payment" (con su propio id) es la
		// que efectivamente da el alta — esta rama nunca debe intentar procesar nada.
		if ( ! $id || ! in_array( $tipo, array( 'payment', '' ), true ) ) {
			return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
		}
		try {
			self::procesar_trial_payment( $id );
		} catch ( Throwable $e ) {
			self::avisar_error_n8n( 'excepcion_trial_alta', array(
				'payment_id' => $id,
				'mensaje'    => $e->getMessage(),
			) );
		}
		// Siempre 200: evita que MP reintente en loop. La idempotencia la garantiza
		// la columna UNIQUE trial_payment_id, no el código de respuesta HTTP.
		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}
	protected static function procesar_trial_payment( $id ) {
		global $wpdb;
		// 1. Nunca confiar en el payload de la notificación: pedirle el estado real a MP.
		// Usa el token de la app de Checkout Pro (distinta a la de Suscripciones).
		$data = CN_MP::obtener_pago_trial( $id );
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			self::avisar_error_n8n( 'pago_no_encontrado', array( 'payment_id' => $id ) );
			return;
		}
		// 2. Solo procesamos pagos de trial (distinguidos por external_reference "trial_...").
		$external_reference = isset( $data['external_reference'] ) ? (string) $data['external_reference'] : '';
		if ( 0 !== strpos( $external_reference, 'trial_' ) ) {
			return; // No es un pago de trial (puede ser de suscripción u otra cosa) — lo ignoramos acá.
		}
		if ( 'approved' !== $data['status'] ) {
			// pending, rejected, in_process, etc. — no damos alta todavía.
			// MP vuelve a notificar cuando cambie de estado.
			return;
		}
		// 3. Idempotencia: si este payment_id ya fue procesado, no hacer nada más.
		$t_miembros = CN_DB::tabla( 'miembros' );
		$ya_procesado = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$t_miembros} WHERE trial_payment_id = %s", $id )
		);
		if ( $ya_procesado ) {
			return; // Notificación duplicada de MP — ya dimos el alta antes, no repetir.
		}
		// 4. Reconstruir nombre y celular desde metadata (los pusimos ahí al crear la Preference).
		$nombre  = isset( $data['metadata']['cn_nombre'] ) ? sanitize_text_field( $data['metadata']['cn_nombre'] ) : '';
		$celular = isset( $data['metadata']['cn_celular'] ) ? sanitize_text_field( $data['metadata']['cn_celular'] ) : '';
		// El mail no lo pedimos nosotros — MP se lo exige a la persona en su propio
		// checkout, así que viene gratis en el payload del pago (payer.email).
		$email = isset( $data['payer']['email'] ) ? sanitize_email( $data['payer']['email'] ) : '';
		// Traer trial_pendientes SIEMPRE (no solo cuando falta metadata): también
		// necesitamos fbp/fbc/ip/user_agent que nunca viajan en el metadata de MP.
		$pendiente = null;
		if ( $external_reference ) {
			$pendiente = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM " . CN_DB::tabla( 'trial_pendientes' ) . " WHERE external_reference = %s",
					$external_reference
				)
			);
		}
		if ( ( '' === $nombre || '' === $celular ) && $pendiente ) {
			$nombre  = $pendiente->nombre_apellido;
			$celular = $pendiente->celular;
		}
		if ( '' === $nombre || '' === $celular ) {
			self::avisar_error_n8n( 'metadata_incompleta', array(
				'payment_id'         => $id,
				'external_reference' => $external_reference,
			) );
			return;
		}
		$monto           = isset( $data['transaction_amount'] ) ? (float) $data['transaction_amount'] : 0;
		$ahora            = current_time( 'mysql', true );
		$fecha_fin_trial  = gmdate( 'Y-m-d H:i:s', time() + self::TRIAL_DIAS * DAY_IN_SECONDS );
		// 5. Alta idempotente por celular: si ya existe (cualquier estado, incluida 'cancelada'),
		// la reactivamos como trial. Si no existe, la creamos.
		$hint       = CN_Helpers::hint_celular( $celular );
		$candidatos = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, celular_hash FROM {$t_miembros} WHERE celular_hint = %s", $hint )
		);
		$miembro_id = 0;
		foreach ( $candidatos as $candidato ) {
			if ( CN_Helpers::verificar_celular( $celular, $candidato->celular_hash ) ) {
				$miembro_id = (int) $candidato->id;
				break;
			}
		}
		if ( $miembro_id ) {
			$wpdb->update(
				$t_miembros,
				array(
					'estado'             => 'activo',
					'fecha_fin_trial'    => $fecha_fin_trial,
					'trial_payment_id'   => $id,
					'trial_monto'        => $monto,
					'fecha_modificacion' => $ahora,
					'email'              => $email ? $email : null,
					'celular_texto_plano' => $celular,
				),
				array( 'id' => $miembro_id ),
				array( '%s', '%s', '%s', '%f', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$t_miembros,
				array(
					'nombre_apellido'    => $nombre,
					'celular_hash'       => CN_Helpers::hash_celular( $celular ),
					'celular_hint'       => $hint,
					'estado'             => 'activo',
					'fecha_alta'         => $ahora,
					'fecha_fin_trial'    => $fecha_fin_trial,
					'trial_payment_id'   => $id,
					'trial_monto'        => $monto,
					'fecha_modificacion' => $ahora,
					'email'              => $email ? $email : null,
					'celular_texto_plano' => $celular,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s' )
			);
			// Si el insert falló por la UNIQUE de trial_payment_id (dos notificaciones
			// concurrentes procesadas casi al mismo tiempo), no es un error real: alguna
			// de las dos ganó la carrera y la socia ya quedó dada de alta.
			if ( $wpdb->last_error && false !== strpos( $wpdb->last_error, 'trial_payment_id' ) ) {
				return;
			}
		}
		self::enviar_mail_bienvenida( $nombre, $celular, $email );
		// Registro en la sheet de seguimiento (no bloqueante, nunca rompe el alta).
		self::registrar_alta_en_sheet( $nombre, $celular, $email, $monto, $id, $fecha_fin_trial, $ahora );
		// CAPI Purchase — mismo event_id (external_reference) que el Pixel dispara
		// en /gracias-prueba/, para que Meta dedupe los dos envíos como un evento.
		// Pasamos fbp/fbc/ip/user_agent de trial_pendientes para subir el EMQ del
		// evento server-side (sin esto solo matchea por email/teléfono).
		CN_Meta_Capi::enviar_purchase(
			$external_reference,
			$monto,
			$email,
			$celular,
			$pendiente ? (string) $pendiente->fbp : '',
			$pendiente ? (string) $pendiente->fbc : '',
			$pendiente ? (string) $pendiente->ip : '',
			$pendiente ? (string) $pendiente->user_agent : ''
		);
	}
	/**
	 * Aviso automático a las socias que llegan al día 5 de su trial (quedan 2 días):
	 * le mandamos, por WhatsApp, el link directo de pago del plan mensual de $15.500
	 * (autoservicio, sin esperar respuesta) y la invitamos a preguntar por el plan
	 * Full si le interesa el contacto por WhatsApp + los encuentros en vivo. No
	 * enviamos el WhatsApp directo (no hay integración de envío) — le mandamos al
	 * admin un mail con un link wa.me ya armado para que lo toque y se lo mande a mano.
	 */
	public static function avisar_dia5_trial() {
		global $wpdb;
		$t_miembros = CN_DB::tabla( 'miembros' );
		$candidatas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, nombre_apellido, celular_texto_plano
				FROM {$t_miembros}
				WHERE estado = 'activo'
				AND fecha_fin_trial IS NOT NULL
				AND dia5_avisado = 0
				AND fecha_alta <= %s",
				gmdate( 'Y-m-d H:i:s', time() - self::DIAS_AVISO_TRIAL * DAY_IN_SECONDS )
			)
		);
		if ( ! $candidatas ) return;
		foreach ( $candidatas as $candidata ) {
			$wpdb->update( $t_miembros, array( 'dia5_avisado' => 1 ), array( 'id' => $candidata->id ), array( '%d' ), array( '%d' ) );
			if ( '' === (string) $candidata->celular_texto_plano ) {
				self::avisar_error_n8n( 'aviso_dia5_sin_celular', array( 'miembro_id' => $candidata->id, 'nombre' => $candidata->nombre_apellido ) );
				continue;
			}
			self::enviar_aviso_dia5( $candidata->nombre_apellido, $candidata->celular_texto_plano );
		}
	}
	protected static function enviar_aviso_dia5( $nombre, $celular_texto_plano ) {
		$mensaje  = '¡Hola ' . $nombre . '! 👋 Soy Naty, del Club Natureza.' . "\n\n";
		$mensaje .= 'Ya casi terminan tus 7 días de prueba — espero que hayas disfrutado de pintar 🎨' . "\n\n";
		$mensaje .= 'Si querés seguir con acceso ilimitado a toda la biblioteca de cursos, activá tu lugar acá: ';
		$mensaje .= 'https://mpago.la/1FsDjhK ($15.500/mes)' . "\n\n";
		$mensaje .= 'Y si además te gustaría sumarte a los encuentros en vivo conmigo y al grupo de WhatsApp de socias, ';
		$mensaje .= 'contame y te cuento del plan Full ✨';
		$wa_numero = CN_Helpers::celular_whatsapp( $celular_texto_plano );
		$wa_link = 'https://wa.me/' . rawurlencode( $wa_numero ) . '?text=' . rawurlencode( $mensaje );
		$destino = trim( (string) get_option( 'cn_admin_alerta_email', get_option( 'admin_email' ) ) );
		if ( '' === $destino ) return;
		$asunto = 'Día 5 de trial — ' . $nombre . ' — mandale el link de pago (o contale del plan Full) por WhatsApp';
		$cuerpo = 'Hola,' . "\n\n" . $nombre . ' se dio de alta hace 5 días — quedan 2 días de prueba.' . "\n\n";
		$cuerpo .= 'Tocá para abrirle WhatsApp con el mensaje ya cargado (incluye el link de pago del plan de $15.500):' . "\n" . $wa_link . "\n\n";
		$cuerpo .= 'Celular: ' . $celular_texto_plano;
		wp_mail( $destino, $asunto, $cuerpo );
	}
	/**
	 * Inserta una fila en wp_cn_mp_log (misma tabla/columnas que usa manejar()).
	 * Cualquier cadena APP_USR-... del payload se oculta antes de guardar.
	 */
	public static function registrar_log_mp( $tipo, $payload ) {
		global $wpdb;
		$wpdb->insert(
			CN_DB::tabla( 'mp_log' ),
			array(
				'payload' => preg_replace( '/APP_USR-[A-Za-z0-9\-]+/', '[oculto]', (string) wp_json_encode( $payload ) ),
				'tipo'    => $tipo,
				'fecha'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
	}
	/** Tipo de fila de mp_log con que se registran los avisos técnicos (los lee el cartel "Avisos" de Club Natureza → Socias). */
	const TIPO_AVISO = 'aviso_error';
	/** Motivos de "una socia pagó y no pudo recibir acceso": se anotan como caso (CN_Alertas) y, si pasan 72 hs sin resolverse, entran en el resumen diario. Ya no mandan mail inmediato. */
	const MOTIVOS_PAGO_SIN_ACCESO = array( 'excepcion_trial_alta', 'metadata_incompleta', 'preapproval_sin_match' );
	/**
	 * Aviso técnico: NO manda mail ni webhook. Deja una fila en mp_log (tipo aviso_error) que el cartel "Avisos" muestra
	 * agrupada: mismo motivo + mismo detalle = una línea con contador. Nunca lanza ni bloquea (es accesorio).
	 * El nombre se mantiene porque lo llaman muchos lugares; ya no habla con n8n. Las únicas tres alertas con mail viven en CN_Alertas.
	 */
	public static function avisar_error_n8n( $motivo, $detalle ) {
		try {
			self::registrar_log_mp( self::TIPO_AVISO, array(
				'motivo'  => (string) $motivo,
				'detalle' => $detalle,
				'clave'   => md5( $motivo . '|' . wp_json_encode( $detalle ) ),
			) );
			if ( in_array( $motivo, self::MOTIVOS_PAGO_SIN_ACCESO, true ) ) {
				CN_Alertas::registrar_caso( $motivo, $detalle ); // solo anota; el mail (si corresponde) sale del resumen diario a las 72 hs.
			}
		} catch ( Throwable $e ) {
			// Accesorio: si el registro falla, no debe afectar el flujo que lo llamó.
		}
	}
	/**
	 * Avisos para el cartel: últimos $max_filas registros de tipo aviso_error, agrupados por clave (motivo + detalle).
	 *
	 * @return array{total:int,distintos:int,lineas:array<int,array{motivo:string,detalle:string,veces:int,ultima:string}>}
	 */
	public static function listar_avisos( $max_lineas = 50, $max_filas = 1000 ) {
		global $wpdb;
		$filas = $wpdb->get_results( $wpdb->prepare(
			"SELECT payload, fecha FROM " . CN_DB::tabla( 'mp_log' ) . " WHERE tipo = %s ORDER BY id DESC LIMIT %d",
			self::TIPO_AVISO,
			(int) $max_filas
		) );
		$filas  = is_array( $filas ) ? $filas : array();
		$grupos = array();
		foreach ( $filas as $f ) {
			$p = json_decode( (string) $f->payload, true );
			if ( ! is_array( $p ) ) {
				continue;
			}
			$clave = isset( $p['clave'] ) ? (string) $p['clave'] : md5( (string) $f->payload );
			if ( ! isset( $grupos[ $clave ] ) ) {
				$det = isset( $p['detalle'] ) ? ( is_scalar( $p['detalle'] ) ? (string) $p['detalle'] : (string) wp_json_encode( $p['detalle'], JSON_UNESCAPED_UNICODE ) ) : '';
				$grupos[ $clave ] = array( 'motivo' => (string) ( $p['motivo'] ?? '' ), 'detalle' => mb_substr( $det, 0, 400 ), 'veces' => 0, 'ultima' => (string) $f->fecha );
			}
			$grupos[ $clave ]['veces']++; // las filas vienen de la más nueva a la más vieja: la primera de cada grupo es la última vez.
		}
		return array(
			'total'     => count( $filas ),
			'distintos' => count( $grupos ),
			'lineas'    => array_slice( array_values( $grupos ), 0, (int) $max_lineas ),
		);
	}
	/**
	 * Aviso fire-and-forget a n8n con cada evento de suscripción de Mercado Pago, para verlo
	 * en la sheet de seguimiento. Mismo criterio que registrar_alta_en_sheet(): no bloqueante,
	 * try/catch, nunca debe afectar el procesamiento de la suscripción.
	 */
	protected static function registrar_suscripcion_en_sheet( $origen, $id, $status, $preapproval_id, $payer_email, $monto ) {
		try {
			wp_remote_post( self::N8N_SUSCRIPCION_SHEET_URL, array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( array(
					'origen'         => (string) $origen,
					'evento_id'      => (string) $origen . ':' . (string) $id . ':' . (string) $status,
					'status'         => (string) $status,
					'preapproval_id' => (string) $preapproval_id,
					'payer_email'    => (string) $payer_email,
					'monto'          => (string) $monto,
					'fecha'          => current_time( 'mysql', true ),
				) ),
			) );
		} catch ( Throwable $e ) {
			// Silencioso a propósito: este aviso es accesorio, nunca debe afectar la suscripción.
		}
	}
	/**
	 * Aviso fire-and-forget a n8n para registrar el alta del trial en la sheet.
	 * timeout corto, blocking=false y try/catch: si n8n está caído o algo falla,
	 * el alta, el mail y el CAPI siguen igual.
	 */
	protected static function registrar_alta_en_sheet( $nombre, $celular, $email, $monto, $trial_payment_id, $fecha_fin_trial, $fecha ) {
		try {
			wp_remote_post( self::N8N_ALTA_SHEET_URL, array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( array(
					'nombre'           => (string) $nombre,
					'celular'          => (string) $celular,
					'email'            => (string) $email,
					'monto'            => (string) $monto,
					'trial_payment_id' => (string) $trial_payment_id,
					'fecha_fin_trial'  => (string) $fecha_fin_trial,
					'fecha'            => (string) $fecha,
				) ),
			) );
		} catch ( Throwable $e ) {
			// Silencioso a propósito: este aviso es accesorio, nunca debe afectar el alta.
		}
	}

	/**
	 * Mail de bienvenida con los datos de acceso, apenas se confirma el alta.
	 * El "usuario" es el nombre y la "contraseña" es el celular — mismos datos
	 * que la persona ya escribió al pagar, no generamos nada nuevo. No bloquea
	 * el alta si falla (wp_mail devuelve false silenciosamente, no lanza excepción).
	 */
	protected static function enviar_mail_bienvenida( $nombre, $celular, $email ) {
		if ( '' === $email ) {
			return; // Sin mail no hay a quién mandarle nada — no es un error, solo no llegó el dato.
		}
		$login_url = home_url( '/club-natureza-miembros/' );
		$asunto    = 'Ya sos parte del Club Natureza 🎉 — tus datos de acceso';
		$cuerpo  = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f5f3ef;font-family:Georgia,serif;">';
		$cuerpo .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f3ef;padding:32px 16px;">';
		$cuerpo .= '<tr><td align="center">';
		$cuerpo .= '<table role="presentation" width="100%" style="max-width:480px;background:#ffffff;border-radius:12px;overflow:hidden;">';
		$cuerpo .= '<tr><td style="background:#2f3b2c;padding:28px 32px;text-align:center;">';
		$cuerpo .= '<span style="color:#f5f3ef;font-size:22px;letter-spacing:1px;">🌿 Club Natureza</span></td></tr>';
		$cuerpo .= '<tr><td style="padding:32px;">';
		$cuerpo .= '<h1 style="font-size:20px;color:#2f3b2c;margin:0 0 16px;">¡Bienvenida, ' . esc_html( $nombre ) . '! 🎉</h1>';
		$cuerpo .= '<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 20px;">Tu prueba de 7 días en el Club Natureza ya está activa.</p>';
		$cuerpo .= '<table role="presentation" width="100%" style="background:#f5f3ef;border-radius:8px;margin:0 0 24px;">';
		$cuerpo .= '<tr><td style="padding:18px 20px;">';
		$cuerpo .= '<p style="font-size:13px;color:#777;margin:0 0 4px;">USUARIO (nombre y apellido)</p>';
		$cuerpo .= '<p style="font-size:16px;color:#2f3b2c;margin:0 0 14px;font-weight:bold;">' . esc_html( $nombre ) . '</p>';
		$cuerpo .= '<p style="font-size:13px;color:#777;margin:0 0 4px;">CONTRASEÑA (tu celular)</p>';
		$cuerpo .= '<p style="font-size:16px;color:#2f3b2c;margin:0;font-weight:bold;">' . esc_html( $celular ) . '</p>';
		$cuerpo .= '</td></tr></table>';
		$cuerpo .= '<table role="presentation" width="100%"><tr><td align="center">';
		$cuerpo .= '<a href="' . esc_url( $login_url ) . '" style="display:inline-block;background:#2f3b2c;color:#f5f3ef;text-decoration:none;padding:14px 32px;border-radius:8px;font-size:15px;margin-bottom:24px;">Entrar al Club →</a>';
		$cuerpo .= '</td></tr></table>';
		$cuerpo .= '<p style="font-size:14px;color:#555;line-height:1.6;margin:24px 0 0;">Tu acceso dura 7 días. Cuando termine, si querés seguir en el Club de forma mensual, podés sumarte a la suscripción cuando quieras — <strong>nunca se te cobra nada de forma automática.</strong></p>';
		$cuerpo .= '<p style="font-size:14px;color:#555;margin:20px 0 0;">Cualquier duda, escribinos por WhatsApp.</p>';
		$cuerpo .= '<p style="font-size:15px;color:#2f3b2c;margin:20px 0 0;">¡Bienvenida! 🌿</p>';
		$cuerpo .= '</td></tr></table></td></tr></table></body></html>';
		wp_mail( $email, $asunto, $cuerpo, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
	/**
	 * Mail de fin de trial (7 días cumplidos y NO se suscribió). Mismo wrapper HTML
	 * que enviar_mail_bienvenida(). Se llama desde CN_Auth::revocar_trials_vencidos()
	 * solo cuando el miembro no tiene preapproval_id (o sea, no se suscribió).
	 */
	public static function enviar_mail_fin_trial( $nombre, $email ) {
		if ( '' === $email ) {
			return;
		}
		$asunto = 'Tu prueba de 7 días en el Club Natureza ya terminó';
		$cuerpo  = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f5f3ef;font-family:Georgia,serif;">';
		$cuerpo .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f3ef;padding:32px 16px;">';
		$cuerpo .= '<tr><td align="center">';
		$cuerpo .= '<table role="presentation" width="100%" style="max-width:480px;background:#ffffff;border-radius:12px;overflow:hidden;">';
		$cuerpo .= '<tr><td style="background:#2f3b2c;padding:28px 32px;text-align:center;">';
		$cuerpo .= '<span style="color:#f5f3ef;font-size:22px;letter-spacing:1px;">🌿 Club Natureza</span></td></tr>';
		$cuerpo .= '<tr><td style="padding:32px;">';
		$cuerpo .= '<h1 style="font-size:20px;color:#2f3b2c;margin:0 0 16px;">¡Hola, ' . esc_html( $nombre ) . '! 🌿</h1>';
		$cuerpo .= '<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 20px;">Tu prueba de 7 días en el Club Natureza terminó, así que tu acceso quedó pausado por ahora.</p>';
		$cuerpo .= '<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 20px;">Ojalá hayas podido asomarte a los cursos y disfrutar un poco de pintar.</p>';
		$cuerpo .= '<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 20px;">Si querés seguir con nosotras, podés sumarte a la suscripción mensual cuando quieras — no se te va a cobrar nada más de forma automática, esto es solo por si te interesa.</p>';
		$cuerpo .= '<table role="presentation" width="100%"><tr><td align="center">';
		$cuerpo .= '<a href="https://naturalesart.com/club-naturales/" style="display:inline-block;background:#2f3b2c;color:#f5f3ef;text-decoration:none;padding:14px 32px;border-radius:8px;font-size:15px;margin-bottom:24px;">Ver la suscripción →</a>';
		$cuerpo .= '</td></tr></table>';
		$cuerpo .= '<p style="font-size:14px;color:#555;margin:20px 0 0;">Cualquier duda, escribinos por WhatsApp.</p>';
		$cuerpo .= '<p style="font-size:15px;color:#2f3b2c;margin:20px 0 0;">¡Gracias por probarnos! 🌿</p>';
		$cuerpo .= '</td></tr></table></td></tr></table></body></html>';
		wp_mail( $email, $asunto, $cuerpo, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
	// ==========================================================================
	// FORMULARIO DE LANDING → PREFERENCE DINÁMICA
	// ==========================================================================
	const RATE_LIMIT_MAX_INTENTOS = 8;
	const RATE_LIMIT_VENTANA_MIN  = 10;
	/**
	 * Recibe nombre + celular del formulario de /prueba-club/, crea la Preference
	 * dinámica de MP y devuelve el link de pago (init_point) para redirigir.
	 * Público (sin login) — lo llama cualquier visitante de la landing, por eso
	 * tiene rate limiting propio (namespace de transient separado del de login,
	 * para no mezclar contadores con CN_Auth).
	 */
	public static function crear_preference_trial_endpoint( WP_REST_Request $request ) {
		$ip  = CN_Helpers::get_client_ip();
		$key = 'cn_pref_fails_' . md5( $ip );
		if ( (int) get_transient( $key ) >= self::RATE_LIMIT_MAX_INTENTOS ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Demasiados intentos. Probá de nuevo en unos minutos.' ), 429 );
		}
		$nombre_raw  = $request->get_param( 'nombre' );
		$celular_raw = $request->get_param( 'celular' );
		$nombre = trim( sanitize_text_field( (string) $nombre_raw ) );
		$norm    = CN_Helpers::normalizar_celular( (string) $celular_raw );
		$celular = $norm['normalizado'];
		// Capturar señales del navegador para el CAPI Purchase posterior ($ip ya
		// existe arriba para el rate-limiting, se reutiliza). El _fbc puede no
		// estar seteado si la visitante llega directo desde un anuncio (el pixel
		// base todavía no corrió); en ese caso lo construimos desde el fbclid del
		// URL con la forma "fb.1.<timestamp_ms>.<fbclid>" que espera Meta.
		$fbp = isset( $_COOKIE['_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) : '';
		$fbc = isset( $_COOKIE['_fbc'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) ) : '';
		if ( '' === $fbc && ! empty( $_GET['fbclid'] ) ) {
			$fbclid = sanitize_text_field( wp_unslash( $_GET['fbclid'] ) );
			$fbc    = 'fb.1.' . round( microtime( true ) * 1000 ) . '.' . $fbclid;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $nombre || strlen( $nombre ) < 3 ) {
			self::registrar_intento_pref_fallido( $key );
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Ingresá tu nombre y apellido.' ), 400 );
		}
		if ( '' === $celular ) {
			self::registrar_intento_pref_fallido( $key );
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Ingresá un celular válido.' ), 400 );
		}
		$resultado = CN_MP::crear_preference_trial( $nombre, $celular, $fbp, $fbc, $ip, $ua );
		if ( ! $resultado['ok'] ) {
			self::registrar_intento_pref_fallido( $key );
			self::avisar_error_n8n( 'crear_preference_trial_fallo', array(
				'nombre'  => $nombre,
				'celular' => $celular,
				'error'   => $resultado['error'],
			) );
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'No pudimos iniciar el pago. Probá de nuevo en un minuto.' ), 500 );
		}
		// Éxito: no cuenta como intento fallido, no tocamos el contador.
		// event_id = external_reference de la Preference — el frontend lo usa para
		// el fbq('track','InitiateCheckout') y lo vuelve a usar en /gracias-prueba/
		// para Purchase, así Meta dedupe Pixel+CAPI como un solo evento.
		return new WP_REST_Response( array( 'ok' => true, 'init_point' => $resultado['init_point'], 'event_id' => $resultado['external_reference'] ), 200 );
	}
	protected static function registrar_intento_pref_fallido( $key ) {
		$intentos = (int) get_transient( $key );
		set_transient( $key, $intentos + 1, self::RATE_LIMIT_VENTANA_MIN * MINUTE_IN_SECONDS );
	}
	public static function pixel_mark( WP_REST_Request $request ) {
		global $wpdb;
		$eid = sanitize_text_field( (string) $request->get_param( 'eid' ) );
		if ( '' === $eid ) {
			return new WP_REST_Response( array( 'gano' => false ), 200 );
		}
		$insertado = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO " . CN_DB::tabla( 'pixel_dedup' ) . " (eid, fired_at) VALUES (%s, %s)",
				$eid,
				current_time( 'mysql', true )
			)
		);
		return new WP_REST_Response( array( 'gano' => (bool) $insertado ), 200 );
	}
}
