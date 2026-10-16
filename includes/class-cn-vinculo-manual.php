<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * "Vincular suscripción por celular" (panel Socias). Asocia a mano un preapproval de MP (los que llegan por el link
 * directo del plan, sin email ni referencia) con una socia buscada por celular.
 *
 * Hereda de CN_Webhook SOLO para reutilizar sus funciones protegidas (vinc_decidir, sub_post_alta, plan_por_monto,
 * registrar_log_mp): no modifica nada de esa clase. Nada de esto lo llama el webhook, el login ni los crons.
 *
 * Dos pasos, cada uno con su nonce y manage_options:
 *  - previsualizar: SOLO LECTURA (consulta MP y SELECT). No escribe nada.
 *  - confirmar: transacción con FOR UPDATE, idempotente. Antes de tocar una fila existente guarda su backup en
 *    mp_log (tipo vinculo_backup). Al terminar deja vinculo_manual.
 */
class CN_Vinculo_Manual extends CN_Webhook {
	const RE_ID   = '/^[a-f0-9]{32}$/i';
	const COLS    = 'id, nombre_apellido, estado, preapproval_id, plan, acceso_hasta, email, fecha_fin_trial, fecha_modificacion, baja_en, celular_hint, celular_hash, celular_texto_plano';
	const NONCE_A = 'cn_vinculo_manual';

	// ------------------------------------------------------------------ utilidades puras

	public static function mascara_email( $email ) {
		$email = trim( (string) $email );
		if ( '' === $email || false === strpos( $email, '@' ) ) {
			return '' === $email ? '—' : '***';
		}
		list( $u, $d ) = explode( '@', $email, 2 );
		return substr( $u, 0, 2 ) . '***@' . $d;
	}

	public static function id_valido( $id ) {
		return is_string( $id ) && 1 === preg_match( self::RE_ID, $id );
	}

	/** Mensaje claro para cada tipo de falla de la API de MP (el rastro mp_api_error ya lo dejó CN_MP). */
	public static function mensaje_http( $http ) {
		if ( 'sin_token' === $http ) {
			return 'Falta el token de Suscripciones de Mercado Pago (Config). No se cambió nada.';
		}
		if ( 'wp_error' === $http ) {
			return 'No se pudo conectar con Mercado Pago. Probá de nuevo en un rato. No se cambió nada.';
		}
		$h = (int) $http;
		if ( 404 === $h ) {
			return 'Mercado Pago no encontró esa suscripción (404). Revisá el ID. No se cambió nada.';
		}
		if ( 401 === $h || 403 === $h ) {
			return 'Mercado Pago rechazó el acceso (' . $h . '): revisá el token de Suscripciones en Config. No se cambió nada.';
		}
		if ( 429 === $h ) {
			return 'Mercado Pago pide esperar (429, demasiadas consultas). Probá en unos minutos. No se cambió nada.';
		}
		if ( $h >= 500 ) {
			return 'Mercado Pago tiene un problema (' . $h . '). Probá más tarde. No se cambió nada.';
		}
		return 'Mercado Pago devolvió un error (' . $h . '). No se cambió nada.';
	}

	/** Consulta MP y arma lo que muestra el paso Previsualizar. 'ok' = se pudo leer; la validez para confirmar es 'confirmable'. */
	protected static function consultar( $id ) {
		$r = array(
			'ok' => false, 'error' => '', 'status' => '', 'monto' => null, 'plan' => null, 'plan_nombre' => '', 'precio' => 0,
			'next_payment_date' => '', 'acceso_hasta' => null, 'payer_email' => '', 'confirmable' => false, 'motivo' => '',
		);
		$det = CN_MP::obtener_preapproval_detalle( $id );
		if ( ! $det['ok'] ) {
			$r['error'] = self::mensaje_http( $det['http'] );
			return $r;
		}
		$data = $det['data'];
		if ( isset( $data['id'] ) && (string) $data['id'] !== $id ) {
			$r['error'] = 'Mercado Pago devolvió una suscripción con otro ID. No se vincula.';
			return $r;
		}
		$r['ok']                = true;
		$r['status']            = ( isset( $data['status'] ) && is_string( $data['status'] ) ) ? sanitize_text_field( $data['status'] ) : '';
		$r['monto']             = isset( $data['auto_recurring']['transaction_amount'] ) ? $data['auto_recurring']['transaction_amount'] : null;
		$r['next_payment_date'] = ( isset( $data['next_payment_date'] ) && is_string( $data['next_payment_date'] ) ) ? sanitize_text_field( $data['next_payment_date'] ) : '';
		$r['acceso_hasta']      = self::acceso_desde_data( $data );
		$r['payer_email']       = ( isset( $data['payer_email'] ) && is_string( $data['payer_email'] ) ) ? sanitize_email( trim( $data['payer_email'] ) ) : '';
		$plan                   = self::plan_por_monto( $r['monto'] );
		if ( null !== $plan ) {
			$planes           = CN_MP::get_planes();
			$r['plan']        = $plan;
			$r['plan_nombre'] = (string) $planes[ $plan ]['nombre'];
			$r['precio']      = $planes[ $plan ]['precio'];
		}
		if ( 'authorized' !== $r['status'] ) {
			$r['motivo'] = 'La suscripción no está autorizada en Mercado Pago (estado: ' . ( '' !== $r['status'] ? $r['status'] : 'desconocido' ) . '). Solo se vinculan las autorizadas.';
		} elseif ( null === $plan ) {
			$r['motivo'] = 'Plan desconocido: el monto (' . ( is_scalar( $r['monto'] ) ? sanitize_text_field( (string) $r['monto'] ) : 'ninguno' ) . ') no coincide con ningún plan. No se puede confirmar.';
		} else {
			$r['confirmable'] = true;
		}
		return $r;
	}

	/** Lee y limpia el formulario. */
	protected static function datos_post() {
		$t = function ( $k ) {
			return trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ) );
		};
		return array(
			'id'        => strtolower( $t( 'cn_vm2_id' ) ),
			'celular'   => $t( 'cn_vm2_celular' ),
			'email'     => sanitize_email( $t( 'cn_vm2_email' ) ),
			'nombre'    => $t( 'cn_vm2_nombre' ),
			'notificar' => ! empty( $_POST['cn_vm2_notificar'] ),
			'socia'     => $t( 'cn_vm2_socia' ), // '' | 'nueva' | id numérico (solo en confirmar).
		);
	}

	/** Devuelve array( error|'' , celular normalizado ). */
	protected static function validar_entrada( $d ) {
		if ( ! self::id_valido( $d['id'] ) ) {
			return array( 'El ID de preapproval tiene que tener 32 caracteres hexadecimales (0-9, a-f).', '' );
		}
		$norm = CN_Helpers::normalizar_celular( $d['celular'] );
		if ( empty( $norm['valido'] ) || '' === (string) $norm['normalizado'] ) {
			return array( 'El celular no es válido. Revisalo (código de área + número).', '' );
		}
		return array( '', (string) $norm['normalizado'] );
	}

	/**
	 * Socias posibles para ese celular (solo lectura). Cada elemento: array( 'fila' => object, 'motivos' => string[] ).
	 *  - celular exacto (hint + verificación del hash, igual que el alta sub_ y el login),
	 *  - celular casi igual (mismos primeros 8 dígitos: típico de un dígito mal tipeado),
	 *  - mismo nombre normalizado que otra candidata exacta o que el nombre escrito,
	 *  - mismo email (el escrito o el del pagador en MP).
	 * Con más de una, el admin tiene que elegir: nunca se adivina.
	 */
	protected static function candidatas( $norm, $email, $nombre ) {
		global $wpdb;
		$t   = CN_DB::tabla( 'miembros' );
		$res = array();
		$add = function ( $fila, $motivo ) use ( &$res ) {
			$k = (int) $fila->id;
			if ( ! isset( $res[ $k ] ) ) {
				$res[ $k ] = array( 'fila' => $fila, 'motivos' => array() );
			}
			if ( ! in_array( $motivo, $res[ $k ]['motivos'], true ) ) {
				$res[ $k ]['motivos'][] = $motivo;
			}
		};
		$por_hint = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT ' . self::COLS . " FROM {$t} WHERE celular_hint = %s", CN_Helpers::hint_celular( $norm ) )
		);
		$exactas = array();
		foreach ( $por_hint as $f ) {
			if ( CN_Helpers::verificar_celular( $norm, (string) $f->celular_hash ) ) {
				$add( $f, 'celular exacto' );
				$exactas[] = $f;
			}
		}
		if ( strlen( $norm ) >= 8 ) {
			$cerca = (array) $wpdb->get_results(
				$wpdb->prepare( 'SELECT ' . self::COLS . " FROM {$t} WHERE celular_texto_plano LIKE %s", $wpdb->esc_like( substr( $norm, 0, 8 ) ) . '%' )
			);
			foreach ( $cerca as $f ) {
				$add( $f, 'celular parecido (mismos primeros 8 dígitos)' );
			}
		}
		if ( '' !== $email ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLS . " FROM {$t} WHERE email = %s", $email ) ) as $f ) {
				$add( $f, 'mismo email' );
			}
		}
		$nombres = array();
		foreach ( $exactas as $f ) {
			$nombres[ CN_Vinculo::nombre_normalizado( $f->nombre_apellido ) ] = true;
		}
		if ( '' !== $nombre ) {
			$nombres[ CN_Vinculo::nombre_normalizado( $nombre ) ] = true;
		}
		unset( $nombres[''] );
		if ( $nombres ) {
			foreach ( (array) $wpdb->get_results( 'SELECT ' . self::COLS . " FROM {$t}" ) as $f ) {
				if ( isset( $nombres[ CN_Vinculo::nombre_normalizado( $f->nombre_apellido ) ] ) ) {
					$add( $f, 'mismo nombre' );
				}
			}
		}
		ksort( $res );
		return $res;
	}

	// ------------------------------------------------------------------ pasos

	/** null si no se envió nuestro formulario; si no, array( 'paso' => ..., ... ). */
	public static function procesar() {
		if ( ! isset( $_POST['cn_vm2_accion'] ) ) {
			return null;
		}
		$d = self::datos_post();
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'paso' => 'error', 'mensaje' => 'No tenés permisos para esto.', 'datos' => $d );
		}
		check_admin_referer( self::NONCE_A, 'cn_vm2_nonce' );
		$accion = sanitize_key( wp_unslash( $_POST['cn_vm2_accion'] ) );
		if ( 'cargar_lista' === $accion ) {
			return array( 'paso' => 'lista', 'datos' => $d, 'lista' => self::sin_vincular() );
		}
		list( $err, $norm ) = self::validar_entrada( $d );
		if ( '' !== $err ) {
			return array( 'paso' => 'error', 'mensaje' => $err, 'datos' => $d );
		}
		if ( 'confirmar' === $accion ) {
			return array( 'paso' => 'confirmado', 'datos' => $d, 'resultado' => self::confirmar( $d, $norm ) );
		}
		$mp = self::consultar( $d['id'] );
		if ( ! $mp['ok'] ) {
			return array( 'paso' => 'error', 'mensaje' => $mp['error'], 'datos' => $d );
		}
		$email_busq = '' !== $d['email'] ? $d['email'] : $mp['payer_email'];
		return array(
			'paso'       => 'previsualizado',
			'datos'      => $d,
			'norm'       => $norm,
			'mp'         => $mp,
			'candidatas' => self::candidatas( $norm, $email_busq, $d['nombre'] ),
		);
	}

	/** Backup de la fila previa en mp_log (sin email completo ni celular en claro ni hash). Lanza si no se pudo guardar. */
	protected static function backup( $fila, $preapproval_id ) {
		global $wpdb;
		$previa = array(
			'id'                 => (int) $fila->id,
			'nombre_apellido'    => (string) $fila->nombre_apellido,
			'celular_hint'       => (string) $fila->celular_hint,
			'email'              => self::mascara_email( $fila->email ),
			'estado'             => $fila->estado,
			'preapproval_id'     => $fila->preapproval_id,
			'plan'               => $fila->plan,
			'acceso_hasta'       => $fila->acceso_hasta,
			'fecha_fin_trial'    => $fila->fecha_fin_trial,
			'fecha_modificacion' => $fila->fecha_modificacion,
			'baja_en'            => $fila->baja_en,
		);
		$ok = $wpdb->insert(
			CN_DB::tabla( 'mp_log' ),
			array(
				'payload' => wp_json_encode( array( 'preapproval_id' => $preapproval_id, 'fila_previa' => $previa ) ),
				'tipo'    => 'vinculo_backup',
				'fecha'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			throw new RuntimeException( 'no se pudo guardar el backup: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
		}
	}

	/** ¿Dos nombres son iguales o parecidos? (normalizados; mismas palabras en otro orden, o a ≤2 letras de distancia). Compartida con Lote y Retorno. */
	protected static function nombres_parecidos( $a, $b ) {
		$a = CN_Vinculo::nombre_normalizado( $a );
		$b = CN_Vinculo::nombre_normalizado( $b );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return true;
		}
		$ta = explode( ' ', $a );
		$tb = explode( ' ', $b );
		sort( $ta );
		sort( $tb );
		if ( $ta === $tb ) {
			return true;
		}
		return min( strlen( $a ), strlen( $b ) ) >= 5 && levenshtein( substr( $a, 0, 100 ), substr( $b, 0, 100 ) ) <= 2;
	}

	protected static function resultado_base() {
		return array( 'ok' => false, 'accion' => 'rechazada', 'mensaje' => '', 'socia_id' => null, 'estado_anterior' => null, 'estado_nuevo' => null, 'notificacion' => '', 'motivo' => '' );
	}

	/** Confirma el vínculo. Revalida todo del lado del servidor (MP incluido). */
	public static function confirmar( $d, $norm, $log_tipo = 'vinculo_manual' ) {
		global $wpdb;
		$res = self::resultado_base();
		$id  = $d['id'];

		$mp = self::consultar( $id ); // antes de abrir la transacción: no se bloquean filas esperando a MP.
		if ( ! $mp['ok'] ) {
			$res['mensaje'] = $mp['error'];
			$res['motivo']  = 'mp';
			return $res;
		}
		if ( ! $mp['confirmable'] ) {
			$res['mensaje'] = $mp['motivo'] . ' No se cambió nada.';
			$res['motivo']  = 'no_confirmable';
			self::registrar_log_mp( 'sub_vinculacion_rechazada', array( 'preapproval_id' => $id, 'origen' => 'vinculo_manual', 'motivo' => mb_substr( $mp['motivo'], 0, 200 ) ) );
			return $res;
		}
		$email_busq = '' !== $d['email'] ? $d['email'] : $mp['payer_email'];
		$cands      = self::candidatas( $norm, $email_busq, $d['nombre'] );
		$eleccion   = $d['socia'];
		if ( 'nueva' === $eleccion ) {
			if ( $cands && ! self::ya_vinculada_con_celular( $id, $norm ) ) {
				$res['mensaje'] = 'Ahora hay socias posibles para ese celular: volvé a previsualizar y elegí. No se cambió nada.';
				$res['motivo']  = 'ambigua';
				return $res;
			}
			if ( '' === $d['nombre'] || '' === $d['email'] ) {
				$res['mensaje'] = 'Para crear una socia nueva hacen falta nombre y apellido y un email válido. No se cambió nada.';
				$res['motivo']  = 'datos';
				return $res;
			}
		} elseif ( ctype_digit( $eleccion ) && (int) $eleccion > 0 ) {
			if ( ! isset( $cands[ (int) $eleccion ] ) ) {
				$res['mensaje'] = 'La socia elegida no es una de las posibles para ese celular. Volvé a previsualizar. No se cambió nada.';
				$res['motivo']  = 'ambigua';
				return $res;
			}
		} else {
			$res['mensaje'] = 'Elegí a qué socia vincular. No se cambió nada.';
			return $res;
		}

		$t_miembros     = CN_DB::tabla( 'miembros' );
		$en_transaccion = false;
		$es_alta        = false;
		$aviso          = null;
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'no se pudo abrir la transaccion' );
			}
			$en_transaccion = true;
			$socia          = null;
			if ( 'nueva' !== $eleccion ) { // siempre primero la socia y después el preapproval (mismo orden que vincular_suscripcion).
				$socia = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLS . " FROM {$t_miembros} WHERE id = %d FOR UPDATE", (int) $eleccion ) );
			}
			$vinculada = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLS . " FROM {$t_miembros} WHERE preapproval_id = %s LIMIT 1 FOR UPDATE", $id ) );
			if ( '' !== (string) $wpdb->last_error ) {
				throw new RuntimeException( 'error buscando en la base: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			if ( 'nueva' === $eleccion ) {
				if ( $vinculada ) {
					// Segunda confirmación de una alta nueva: si ese preapproval ya quedó en la socia de ESE celular, es el mismo caso.
					if ( CN_Helpers::verificar_celular( $norm, (string) $vinculada->celular_hash ) ) {
						$socia = $vinculada;
					} else {
						$accion = 'rechazada';
						$fila   = $vinculada;
					}
				} else {
					foreach ( self::candidatas( $norm, '', '' ) as $c ) { // revalida con la transacción abierta.
						if ( in_array( 'celular exacto', $c['motivos'], true ) ) {
							$wpdb->query( 'ROLLBACK' );
							$en_transaccion = false;
							$res['mensaje'] = 'Mientras tanto apareció una socia con ese celular. Volvé a previsualizar. No se cambió nada.';
							$res['motivo']  = 'ambigua';
							return $res;
						}
					}
					$accion = 'crear';
					$fila   = null;
				}
			}
			if ( ! isset( $accion ) ) {
				list( $accion, $fila ) = self::vinc_decidir( $vinculada, $socia, $id );
			}
			$ahora = current_time( 'mysql', true );

			if ( 'inexistente' === $accion ) {
				$wpdb->query( 'ROLLBACK' );
				$en_transaccion = false;
				$res['mensaje'] = 'La socia elegida ya no existe. No se cambió nada.';
				self::registrar_log_mp( 'sub_vinculacion_rechazada', array( 'preapproval_id' => $id, 'origen' => 'vinculo_manual', 'motivo' => 'socia_inexistente' ) );
				return $res;
			}
			if ( 'rechazada' === $accion ) {
				$wpdb->query( 'ROLLBACK' );
				$en_transaccion = false;
				$res['mensaje'] = 'Ese preapproval ya está vinculado a OTRA socia (id ' . (int) $fila->id . '). No se cambió nada.';
				self::registrar_log_mp( 'sub_vinculacion_rechazada', array( 'preapproval_id' => $id, 'origen' => 'vinculo_manual', 'motivo' => 'ya_vinculado_a_otra_socia', 'miembro_id' => (int) $fila->id ) );
				return $res;
			}
			if ( 'conflicto' === $accion ) {
				$wpdb->query( 'ROLLBACK' );
				$en_transaccion = false;
				$res['accion']   = 'conflicto';
				$res['socia_id'] = (int) $fila->id;
				$res['mensaje']  = 'La socia (id ' . (int) $fila->id . ') ya tiene OTRA suscripción vinculada. No se pisó nada: resolvelo a mano.';
				$clave           = 'vinc_' . $id;
				$detalle         = array( 'origen' => 'vinculo_manual', 'external_reference' => $clave, 'miembro_id' => (int) $fila->id, 'preapproval_nuevo' => $id );
				self::registrar_log_mp( 'sub_conflicto', $detalle + array( 'preapproval_actual' => (string) $fila->preapproval_id ) );
				self::sub_avisar_una_vez_por_hora( 'sub_conflicto', $clave, $detalle );
				return $res;
			}

			if ( 'crear' === $accion ) {
				$ok = $wpdb->insert(
					$t_miembros,
					array(
						'nombre_apellido'     => $d['nombre'],
						'celular_hash'        => CN_Helpers::hash_celular( $norm ),
						'celular_hint'        => CN_Helpers::hint_celular( $norm ),
						'estado'              => 'activo',
						'fecha_alta'          => $ahora,
						'fecha_modificacion'  => $ahora,
						'email'               => $d['email'],
						'celular_texto_plano' => $norm,
						'plan'                => $mp['plan'],
						'preapproval_id'      => $id,
						'acceso_hasta'        => $mp['acceso_hasta'],
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				if ( false === $ok ) {
					throw new RuntimeException( 'no se pudo crear la socia: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
				}
				$res['socia_id']        = (int) $wpdb->insert_id;
				$res['estado_nuevo']    = 'activo';
				$res['mensaje']         = 'Socia nueva creada y vinculada.';
				$es_alta                = true;
				$aviso                  = array( 'nombre' => $d['nombre'], 'celular' => $norm, 'email' => $d['email'] );
			} elseif ( 'activada' === $accion ) {
				self::backup( $fila, $id );
				$ok = $wpdb->update(
					$t_miembros,
					array(
						'estado'             => 'activo',
						'preapproval_id'     => $id,
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
				$res['socia_id']        = (int) $fila->id;
				$res['estado_anterior'] = (string) $fila->estado;
				$res['estado_nuevo']    = 'activo';
				$res['mensaje']         = 'Socia existente activada y vinculada.';
				$es_alta                = true;
				$aviso                  = array(
					'nombre'  => (string) $fila->nombre_apellido,
					'celular' => trim( (string) $fila->celular_texto_plano ),
					'email'   => '' !== trim( (string) $fila->email ) ? trim( (string) $fila->email ) : ( '' !== $d['email'] ? $d['email'] : $mp['payer_email'] ),
				);
			} else { // completada: ya tenía ESTE preapproval_id. Solo se completan plan/acceso_hasta si están en NULL.
				$completar = array();
				if ( null !== $mp['plan'] && null === $fila->plan ) {
					$completar['plan'] = $mp['plan'];
				}
				if ( null !== $mp['acceso_hasta'] && null === $fila->acceso_hasta ) {
					$completar['acceso_hasta'] = $mp['acceso_hasta'];
				}
				if ( $completar ) {
					self::backup( $fila, $id );
					$ok = $wpdb->update( $t_miembros, $completar, array( 'id' => (int) $fila->id ), array_fill( 0, count( $completar ), '%s' ), array( '%d' ) );
					if ( false === $ok ) {
						throw new RuntimeException( 'no se pudo completar plan/acceso_hasta: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
					}
				}
				$res['socia_id']        = (int) $fila->id;
				$res['estado_anterior'] = (string) $fila->estado;
				$res['estado_nuevo']    = (string) $fila->estado;
				$res['mensaje']         = $completar
					? 'Ya estaba vinculada: se completaron ' . implode( ' y ', array_keys( $completar ) ) . '. El estado no cambió (' . (string) $fila->estado . ').'
					: 'Ya estaba vinculada con esta suscripción: no había nada para completar.';
			}
			$res['accion'] = $accion;
			$res['ok']     = true;
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'no se pudo confirmar la transaccion' );
			}
			$en_transaccion = false;
		} catch ( Throwable $e ) {
			if ( $en_transaccion ) {
				$wpdb->query( 'ROLLBACK' );
			}
			$clave   = 'vinc_' . $id;
			$detalle = array( 'paso' => 'vinculo_manual', 'external_reference' => $clave, 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) );
			self::registrar_log_mp( 'sub_error', $detalle );
			self::sub_avisar_una_vez_por_hora( 'sub_error', $clave, $detalle );
			$res            = self::resultado_base();
			$res['mensaje'] = 'Error interno: no se guardó nada. Quedó registrado en el log de Mercado Pago.';
			$res['motivo']  = 'error';
			return $res;
		}

		// Ya confirmado: auditoría (sin emails ni celulares ni tokens).
		$usuario = wp_get_current_user();
		self::registrar_log_mp( $log_tipo, array(
			'preapproval_id'  => $id,
			'miembro_id'      => $res['socia_id'],
			'accion'          => $res['accion'],
			'estado_anterior' => $res['estado_anterior'],
			'estado_nuevo'    => $res['estado_nuevo'],
			'plan'            => $mp['plan'],
			'notificar'       => (bool) $d['notificar'],
			'usuario_id'      => (int) get_current_user_id(),
			'usuario'         => ( $usuario && ! empty( $usuario->user_login ) ) ? (string) $usuario->user_login : '',
		) );
		if ( $es_alta && $d['notificar'] && is_array( $aviso ) ) {
			$res['notificacion'] = self::notificar( $id, $aviso, $mp['plan'] );
		}
		return $res;
	}

	/** ¿Ese preapproval ya está en la socia de ESTE celular? (para que reconfirmar una alta nueva sea idempotente). */
	protected static function ya_vinculada_con_celular( $id, $norm ) {
		global $wpdb;
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT celular_hash FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE preapproval_id = %s LIMIT 1', $id ) );
		return $f && CN_Helpers::verificar_celular( $norm, (string) $f->celular_hash );
	}

	/** Mail de bienvenida + Subscribe, una sola vez por preapproval (marca en mp_log). Nunca afecta lo ya guardado. */
	protected static function notificar( $id, $aviso, $plan ) {
		global $wpdb;
		$t_log  = CN_DB::tabla( 'mp_log' );
		$previo = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t_log} WHERE tipo = 'vinculo_bienvenida' AND payload LIKE %s",
			'%' . $wpdb->esc_like( '"preapproval_id":"' . $id . '"' ) . '%'
		) );
		if ( $previo > 0 ) {
			return 'No se reenvió el mail ni el evento Meta: ya se habían mandado para esta suscripción.';
		}
		if ( '' === $aviso['celular'] ) {
			return 'No se envió mail ni evento Meta: la socia no tiene el celular guardado.';
		}
		$fila_post = (object) array(
			'nombre_apellido'    => $aviso['nombre'],
			'celular'            => $aviso['celular'],
			'email'              => $aviso['email'],
			'external_reference' => 'vinc_' . $id, // mismo event_id (hasheado) que la vinculación por id: Meta deduplica.
			'fbp'                => '',
			'fbc'                => '',
			'ip'                 => '',
			'user_agent'         => '',
		);
		self::sub_post_alta( $fila_post, $plan, CN_MP::get_planes() ); // try/catch propio.
		self::registrar_log_mp( 'vinculo_bienvenida', array( 'preapproval_id' => $id ) );
		return ( '' !== $aviso['email'] ? 'Se envió el mail de bienvenida y el evento Meta.' : 'Se envió el evento Meta (no hay email para el mail).' );
	}

	/** Preapprovals authorized de MP (los mismos que lista el Cruce) que no están en ninguna socia. */
	protected static function sin_vincular() {
		global $wpdb;
		$l = CN_Vinculo::listar_authorized();
		if ( empty( $l['ok'] ) ) {
			return array( 'ok' => false, 'error' => $l['error'], 'filas' => array(), 'truncada' => false );
		}
		$ids = array();
		foreach ( $l['items'] as $it ) {
			if ( ! empty( $it['id'] ) && is_string( $it['id'] ) && self::id_valido( $it['id'] ) ) {
				$ids[ $it['id'] ] = $it;
			}
		}
		$vinc = array();
		if ( $ids ) {
			$marc = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
			$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT preapproval_id FROM ' . CN_DB::tabla( 'miembros' ) . " WHERE preapproval_id IN ({$marc})", array_keys( $ids ) ) );
			$vinc = array_flip( array_map( 'strtolower', (array) $rows ) );
		}
		$filas = array();
		foreach ( $ids as $id => $it ) {
			if ( ! isset( $vinc[ strtolower( $id ) ] ) ) {
				$filas[] = $it;
			}
		}
		return array( 'ok' => true, 'error' => '', 'filas' => $filas, 'truncada' => ! empty( $l['truncada'] ) );
	}

	// ------------------------------------------------------------------ pantalla

	protected static function etiqueta_socia( $f ) {
		return '#' . (int) $f->id . ' · ' . (string) $f->nombre_apellido . ' · ' . CN_Vinculo::mascara_celular( $f->celular_hint ) . ' · ' . (string) $f->estado
			. ( ! empty( $f->fecha_fin_trial ) ? ' (trial)' : '' ) . ' · suscripción actual: ' . ( '' !== (string) $f->preapproval_id ? (string) $f->preapproval_id : 'ninguna' );
	}

	public static function render( $res ) {
		$paso = is_array( $res ) ? $res['paso'] : '';
		$d    = array( 'id' => '', 'celular' => '', 'email' => '', 'nombre' => '', 'notificar' => false, 'socia' => '' );
		if ( is_array( $res ) ) {
			$d = $res['datos'];
		} elseif ( isset( $_GET['cn_vm2_id'] ) ) { // enlace "Vincular" de la lista: solo precarga el ID.
			$g = strtolower( trim( sanitize_text_field( wp_unslash( $_GET['cn_vm2_id'] ) ) ) );
			if ( self::id_valido( $g ) ) {
				$d['id'] = $g;
			}
		}
		$actual = esc_url( '#cn-vincular-celular' );
		echo '<h2 id="cn-vincular-celular">Vincular suscripción (por celular)</h2>';
		echo '<p style="max-width:760px;">Para suscripciones que llegaron por el link directo del plan, sin email ni referencia. Buscás a la socia por celular, <em>Previsualizás</em> (solo lectura) y recién después <em>Confirmás</em>.</p>';
		if ( 'error' === $paso ) {
			self::aviso_html( $res['mensaje'], 'error' );
		} elseif ( 'confirmado' === $paso ) {
			$r    = $res['resultado'];
			$tipo = ! empty( $r['ok'] ) ? 'success' : ( 'conflicto' === $r['accion'] ? 'warning' : 'error' );
			$txt  = strtoupper( (string) $r['accion'] ) . ': ' . (string) $r['mensaje'];
			if ( '' !== (string) $r['notificacion'] ) {
				$txt .= ' ' . $r['notificacion'];
			} elseif ( ! empty( $r['ok'] ) && ! $d['notificar'] ) {
				$txt .= ' No se envió mail ni evento Meta.';
			}
			self::aviso_html( $txt, $tipo );
		} elseif ( 'lista' === $paso ) {
			self::aviso_html( $res['lista']['ok'] ? 'Lista actualizada.' : $res['lista']['error'], $res['lista']['ok'] ? 'success' : 'error' );
		} elseif ( 'previsualizado' === $paso ) {
			self::render_preview( $res );
		}
		?>
		<form method="post" style="max-width:680px;" action="<?php echo $actual; ?>">
			<?php wp_nonce_field( self::NONCE_A, 'cn_vm2_nonce' ); ?>
			<table class="form-table">
				<tr><th><label>ID de preapproval</label></th><td><input type="text" name="cn_vm2_id" class="regular-text" maxlength="32" pattern="[A-Fa-f0-9]{32}" required value="<?php echo esc_attr( 'confirmado' === $paso && ! empty( $res['resultado']['ok'] ) ? '' : $d['id'] ); ?>"><p class="description">32 caracteres (0-9, a-f).</p></td></tr>
				<tr><th><label>Celular de la socia</label></th><td><input type="text" name="cn_vm2_celular" class="regular-text" required value="<?php echo esc_attr( 'confirmado' === $paso && ! empty( $res['resultado']['ok'] ) ? '' : $d['celular'] ); ?>"><p class="description">Cualquier formato (+54 9, 0, 15…): se normaliza igual que en el alta.</p></td></tr>
				<tr><th><label>Email</label></th><td><input type="email" name="cn_vm2_email" class="regular-text" value="<?php echo esc_attr( $d['email'] ); ?>"><p class="description">Obligatorio solo si la socia no existe.</p></td></tr>
				<tr><th><label>Nombre y apellido</label></th><td><input type="text" name="cn_vm2_nombre" class="regular-text" value="<?php echo esc_attr( $d['nombre'] ); ?>"><p class="description">Obligatorio solo si hay que crear la socia. Si lo ponés, también ayuda a encontrar duplicados.</p></td></tr>
				<tr><th>Avisos</th><td><label><input type="checkbox" name="cn_vm2_notificar" value="1" <?php checked( ! empty( $d['notificar'] ) ); ?>> Enviar mail de bienvenida y evento Subscribe</label></td></tr>
			</table>
			<p><button type="submit" name="cn_vm2_accion" value="previsualizar" class="button button-secondary">Previsualizar</button></p>
		</form>
		<h3>Suscripciones autorizadas sin vincular</h3>
		<form method="post" action="<?php echo $actual; ?>">
			<?php wp_nonce_field( self::NONCE_A, 'cn_vm2_nonce' ); ?>
			<p><button type="submit" name="cn_vm2_accion" value="cargar_lista" class="button">Consultar Mercado Pago</button> <span class="description">Las mismas que muestra el Cruce con Mercado Pago, menos las ya vinculadas.</span></p>
		</form>
		<?php
		if ( 'lista' === $paso && $res['lista']['ok'] ) {
			$filas = $res['lista']['filas'];
			if ( ! $filas ) {
				echo '<p>No hay suscripciones autorizadas sin vincular.</p>';
			} else {
				echo '<table class="widefat striped" style="max-width:900px;"><thead><tr><th>ID de preapproval</th><th>Plan / reason</th><th>Email del pagador</th><th>Próximo cobro</th><th>Vincular</th></tr></thead><tbody>';
				foreach ( $filas as $it ) {
					$url = add_query_arg( array( 'page' => 'cn-socias', 'cn_vm2_id' => $it['id'] ), admin_url( 'admin.php' ) ) . '#cn-vincular-celular';
					echo '<tr><td><code>' . esc_html( $it['id'] ) . '</code></td>';
					echo '<td>' . esc_html( isset( $it['reason'] ) && is_string( $it['reason'] ) ? $it['reason'] : '—' ) . '</td>';
					echo '<td>' . esc_html( self::mascara_email( isset( $it['payer_email'] ) && is_string( $it['payer_email'] ) ? $it['payer_email'] : '' ) ) . '</td>';
					echo '<td>' . esc_html( isset( $it['next_payment_date'] ) && is_string( $it['next_payment_date'] ) ? substr( $it['next_payment_date'], 0, 10 ) : '—' ) . '</td>';
					echo '<td><a class="button button-small" href="' . esc_url( $url ) . '">Vincular</a></td></tr>';
				}
				echo '</tbody></table>';
				if ( $res['lista']['truncada'] ) {
					echo '<p class="description">La lista de Mercado Pago es larga: se muestran las primeras páginas.</p>';
				}
			}
		}
	}

	protected static function aviso_html( $texto, $tipo ) {
		echo '<div class="notice notice-' . esc_attr( $tipo ) . ' inline"><p>' . esc_html( $texto ) . '</p></div>';
	}

	protected static function render_preview( $res ) {
		$d  = $res['datos'];
		$mp = $res['mp'];
		$c  = $res['candidatas'];
		$plan_txt = null !== $mp['plan'] ? $mp['plan_nombre'] . ' — $' . number_format( (float) $mp['precio'], 0, ',', '.' ) : 'plan desconocido';
		$prox     = '' !== $mp['next_payment_date'] ? substr( $mp['next_payment_date'], 0, 10 ) : '—';
		echo '<table class="widefat striped" style="max-width:760px;margin-bottom:12px;"><tbody>';
		echo '<tr><th style="width:220px;">ID de preapproval</th><td><code>' . esc_html( $d['id'] ) . '</code></td></tr>';
		echo '<tr><th>Estado en Mercado Pago</th><td>' . esc_html( $mp['status'] ) . '</td></tr>';
		echo '<tr><th>Monto</th><td>' . esc_html( is_scalar( $mp['monto'] ) ? (string) $mp['monto'] : '—' ) . '</td></tr>';
		echo '<tr><th>Plan (según el monto)</th><td>' . esc_html( $plan_txt ) . '</td></tr>';
		echo '<tr><th>Próximo cobro (acceso hasta)</th><td>' . esc_html( $prox ) . '</td></tr>';
		echo '<tr><th>Email del pagador</th><td>' . esc_html( self::mascara_email( $mp['payer_email'] ) ) . '</td></tr>';
		echo '<tr><th>Socia</th><td>';
		if ( ! $c ) {
			echo '<strong>Se creará una socia nueva</strong> (celular ****' . esc_html( substr( $res['norm'], -4 ) ) . ').';
		} else {
			echo count( $c ) . ' posible(s):<ul style="margin:6px 0 0 18px;list-style:disc;">';
			foreach ( $c as $x ) {
				echo '<li>' . esc_html( self::etiqueta_socia( $x['fila'] ) ) . ' <em>[' . esc_html( implode( ', ', $x['motivos'] ) ) . ']</em></li>';
			}
			echo '</ul>';
		}
		echo '</td></tr></tbody></table>';
		if ( ! $mp['confirmable'] ) {
			self::aviso_html( $mp['motivo'], 'warning' );
			return;
		}
		$crear = ! $c;
		if ( $crear && ( '' === $d['nombre'] || '' === $d['email'] ) ) {
			self::aviso_html( 'Para crear una socia nueva completá nombre y apellido y un email válido arriba, y volvé a previsualizar.', 'warning' );
			return;
		}
		?>
		<form method="post" style="max-width:760px;margin-bottom:16px;" action="<?php echo esc_url( '#cn-vincular-celular' ); ?>">
			<?php wp_nonce_field( self::NONCE_A, 'cn_vm2_nonce' ); ?>
			<input type="hidden" name="cn_vm2_id" value="<?php echo esc_attr( $d['id'] ); ?>">
			<input type="hidden" name="cn_vm2_celular" value="<?php echo esc_attr( $d['celular'] ); ?>">
			<input type="hidden" name="cn_vm2_email" value="<?php echo esc_attr( $d['email'] ); ?>">
			<input type="hidden" name="cn_vm2_nombre" value="<?php echo esc_attr( $d['nombre'] ); ?>">
			<?php if ( $crear ) : ?>
				<input type="hidden" name="cn_vm2_socia" value="nueva">
			<?php else : ?>
				<p><strong><?php echo count( $c ) > 1 ? 'Hay más de una socia posible: elegí a cuál vincular (no se adivina).' : 'Socia a vincular:'; ?></strong></p>
				<?php foreach ( $c as $k => $x ) : ?>
					<p style="margin:2px 0;"><label><input type="radio" name="cn_vm2_socia" value="<?php echo esc_attr( (int) $k ); ?>" required <?php checked( 1 === count( $c ) ); ?>> <?php echo esc_html( self::etiqueta_socia( $x['fila'] ) ); ?></label></p>
				<?php endforeach; ?>
			<?php endif; ?>
			<p><label><input type="checkbox" name="cn_vm2_notificar" value="1" <?php checked( ! empty( $d['notificar'] ) ); ?>> Enviar mail de bienvenida y evento Subscribe</label></p>
			<p><button type="submit" name="cn_vm2_accion" value="confirmar" class="button button-primary">Confirmar y vincular</button></p>
		</form>
		<?php
	}
}
