<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class CN_Webhook {
	const N8N_ALERTA_URL = 'https://n8n.naturalesart.com/webhook/club-trial-alerta';
	const TRIAL_DIAS = 7;
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
	}
	public static function manejar( WP_REST_Request $request ) {
		global $wpdb;
		$raw = $request->get_body();
		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) ) {
			$json = array();
		}
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
		$wpdb->insert(
			CN_DB::tabla( 'mp_log' ),
			array(
				'payload' => wp_json_encode( array( 'body' => $json, 'query' => $request->get_query_params() ) ),
				'tipo'    => $tipo ? $tipo : 'desconocido',
				'fecha'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
		if ( $id && in_array( $tipo, array( 'preapproval', 'subscription_preapproval' ), true ) ) {
			self::procesar_preapproval( $id );
		} elseif ( $id && in_array( $tipo, array( 'payment', 'subscription_authorized_payment' ), true ) ) {
			self::procesar_payment( $id );
		}
		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}
	protected static function procesar_preapproval( $id ) {
		$data = CN_MP::obtener_preapproval( $id );
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			return;
		}
		$status              = $data['status'];
		$external_reference  = isset( $data['external_reference'] ) ? $data['external_reference'] : '';
		self::aplicar_estado( $status, $external_reference, $id );
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
			$preapproval_id = $data['point_of_interaction']['transaction_data']['preapproval_id'];
		} elseif ( ! empty( $data['metadata']['preapproval_id'] ) ) {
			$preapproval_id = $data['metadata']['preapproval_id'];
		}
		self::aplicar_estado( $status, $external_reference, $preapproval_id );
	}
	protected static function aplicar_estado( $status, $external_reference, $preapproval_id ) {
		global $wpdb;
		$estados_activo  = array( 'authorized', 'approved' );
		$estados_pausado = array( 'cancelled', 'rejected', 'paused' );
		if ( ! in_array( $status, array_merge( $estados_activo, $estados_pausado ), true ) ) {
			return;
		}
		$pendiente = null;
		if ( $external_reference ) {
			$pendiente = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM " . CN_DB::tabla( 'preapprovals_pendientes' ) . " WHERE external_reference = %s",
					$external_reference
				)
			);
		}
		$nuevo_estado = in_array( $status, $estados_activo, true ) ? 'activo' : 'pausado';
		if ( $pendiente ) {
			self::upsert_miembro_por_celular( $pendiente->nombre_apellido, $pendiente->celular, $nuevo_estado, $preapproval_id );
			return;
		}
		if ( $preapproval_id ) {
			$t_miembros = CN_DB::tabla( 'miembros' );
			$wpdb->update(
				$t_miembros,
				array( 'estado' => $nuevo_estado ),
				array( 'preapproval_id' => $preapproval_id ),
				array( '%s' ),
				array( '%s' )
			);
		}
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
		$wpdb->insert(
			CN_DB::tabla( 'mp_log' ),
			array(
				'payload' => wp_json_encode( array( 'body' => $json, 'query' => $request->get_query_params(), 'endpoint' => 'trial-alta' ) ),
				'tipo'    => $tipo ? $tipo : 'desconocido',
				'fecha'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
		if ( ! $id || ! in_array( $tipo, array( 'payment', '' ), true ) ) {
			if ( ! $id ) {
				return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
			}
		}
		try {
			self::procesar_trial_payment( $id );
		} catch ( Throwable $e ) {
			self::avisar_error_n8n( 'excepcion_trial_alta', array(
				'payment_id' => $id,
				'mensaje'    => $e->getMessage(),
			) );
		}
		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}
	protected static function procesar_trial_payment( $id ) {
		global $wpdb;
		$data = CN_MP::obtener_pago( $id );
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			self::avisar_error_n8n( 'pago_no_encontrado', array( 'payment_id' => $id ) );
			return;
		}
		$external_reference = isset( $data['external_reference'] ) ? (string) $data['external_reference'] : '';
		if ( 0 !== strpos( $external_reference, 'trial_' ) ) {
			return;
		}
		if ( 'approved' !== $data['status'] ) {
			return;
		}
		$t_miembros = CN_DB::tabla( 'miembros' );
		$ya_procesado = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$t_miembros} WHERE trial_payment_id = %s", $id )
		);
		if ( $ya_procesado ) {
			return;
		}
		$nombre  = isset( $data['metadata']['cn_nombre'] ) ? sanitize_text_field( $data['metadata']['cn_nombre'] ) : '';
		$celular = isset( $data['metadata']['cn_celular'] ) ? sanitize_text_field( $data['metadata']['cn_celular'] ) : '';
		$email = isset( $data['payer']['email'] ) ? sanitize_email( $data['payer']['email'] ) : '';
		if ( ( '' === $nombre || '' === $celular ) && $external_reference ) {
			$pendiente = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM " . CN_DB::tabla( 'trial_pendientes' ) . " WHERE external_reference = %s",
					$external_reference
				)
			);
			if ( $pendiente ) {
				$nombre  = $pendiente->nombre_apellido;
				$celular = $pendiente->celular;
			}
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
				),
				array( 'id' => $miembro_id ),
				array( '%s', '%s', '%s', '%f', '%s', '%s' ),
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
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s' )
			);
			if ( $wpdb->last_error && false !== strpos( $wpdb->last_error, 'trial_payment_id' ) ) {
				return;
			}
		}
	}
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
	const RATE_LIMIT_MAX_INTENTOS = 8;
	const RATE_LIMIT_VENTANA_MIN  = 10;
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
		if ( '' === $nombre || strlen( $nombre ) < 3 ) {
			self::registrar_intento_pref_fallido( $key );
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Ingresá tu nombre y apellido.' ), 400 );
		}
		if ( '' === $celular ) {
			self::registrar_intento_pref_fallido( $key );
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Ingresá un celular válido.' ), 400 );
		}
		$resultado = CN_MP::crear_preference_trial( $nombre, $celular );
		if ( ! $resultado['ok'] ) {
			self::registrar_intento_pref_fallido( $key );
			self::avisar_error_n8n( 'crear_preference_trial_fallo', array(
				'nombre'  => $nombre,
				'celular' => $celular,
				'error'   => $resultado['error'],
			) );
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'No pudimos iniciar el pago. Probá de nuevo en un minuto.' ), 500 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'init_point' => $resultado['init_point'] ), 200 );
	}
	protected static function registrar_intento_pref_fallido( $key ) {
		$intentos = (int) get_transient( $key );
		set_transient( $key, $intentos + 1, self::RATE_LIMIT_VENTANA_MIN * MINUTE_IN_SECONDS );
	}
}
