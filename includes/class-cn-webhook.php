<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class CN_Webhook {
	// Path del workflow de alertas de n8n (Club Natureza - Alerta de errores (Trial),
	// ID YmU7WWLAkWwj8xZT). Fire-and-forget: si n8n está caído, el trial igual sucede.
	const N8N_ALERTA_URL = 'https://n8n.naturalesart.com/webhook/club-trial-alerta';
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
		$estados_activo  = array( 'authorized', 'approved' );
		$estados_pausado = array( 'cancelled', 'rejected', 'paused' );
		if ( ! in_array( $status, array_merge( $estados_activo, $estados_pausado ), true ) ) {
			return; // pending u otro estado transitorio: no hacemos nada todavía.
		}
		$nuevo_estado = in_array( $status, $estados_activo, true ) ? 'activo' : 'pausado';
		$t_miembros   = CN_DB::tabla( 'miembros' );
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
	 * Aviso fire-and-forget a n8n. Nunca bloquea ni hace fallar el alta —
	 * timeout corto y sin esperar respuesta (regla #2 del proyecto).
	 */
	protected static function avisar_error_n8n( $motivo, $detalle ) {
		wp_remote_post( self::N8N_ALERTA_URL, array(
			'timeout'  => 5,
			'blocking' => false,
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( array(
				'motivo'  => $motivo,
				'detalle' => $detalle,
				'fecha'   => current_time( 'mysql', true ),
			) ),
		) );
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
