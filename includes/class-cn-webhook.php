<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CN_Webhook {

	public static function registrar_rutas() {
		register_rest_route( 'cn/v1', '/mp-webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'manejar' ),
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
			return; // pending u otro estado transitorio: no hacemos nada todavía.
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
}
