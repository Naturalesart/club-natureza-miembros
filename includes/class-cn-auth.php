<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class CN_Auth {
		const COOKIE_NAME   = 'cn_session';
		const SESSION_DIAS  = 180;
		const MAX_INTENTOS  = 5;
		const BLOQUEO_MIN   = 15;
		public static function ip_bloqueada() {
					$ip = CN_Helpers::get_client_ip();
					return (bool) get_transient( 'cn_block_' . md5( $ip ) );
		}
		protected static function registrar_intento_fallido() {
					$ip  = CN_Helpers::get_client_ip();
					$key = 'cn_fails_' . md5( $ip );
					$intentos = (int) get_transient( $key );
					$intentos++;
					if ( $intentos >= self::MAX_INTENTOS ) {
									set_transient( 'cn_block_' . md5( $ip ), 1, self::BLOQUEO_MIN * MINUTE_IN_SECONDS );
									delete_transient( $key );
					} else {
									set_transient( $key, $intentos, self::BLOQUEO_MIN * MINUTE_IN_SECONDS );
					}
		}
		protected static function limpiar_intentos() {
					$ip = CN_Helpers::get_client_ip();
					delete_transient( 'cn_fails_' . md5( $ip ) );
					delete_transient( 'cn_block_' . md5( $ip ) );
		}
		public static function login( $nombre_raw, $celular_raw ) {
					global $wpdb;
					if ( self::ip_bloqueada() ) {
									return array( 'ok' => false, 'motivo' => 'bloqueado' );
					}
					$nombre = sanitize_text_field( $nombre_raw );
					$nombre = trim( $nombre );
					$norm = CN_Helpers::normalizar_celular( $celular_raw );
					$celular_normalizado = $norm['normalizado'];
					if ( '' === $nombre || '' === $celular_normalizado ) {
									self::registrar_intento_fallido();
									return array( 'ok' => false, 'motivo' => 'invalido' );
					}
					$tabla = CN_DB::tabla( 'miembros' );
					$candidatos = $wpdb->get_results(
									$wpdb->prepare(
													"SELECT id, nombre_apellido, celular_hash, estado, fecha_fin_trial FROM {$tabla} WHERE LOWER(TRIM(nombre_apellido)) = LOWER(TRIM(%s))",
													$nombre
												)
									);
					foreach ( $candidatos as $candidato ) {
									if ( CN_Helpers::verificar_celular( $celular_normalizado, $candidato->celular_hash ) ) {
													if ( self::trial_vencido( $candidato->fecha_fin_trial ) ) {
																	self::marcar_cancelada_si_hace_falta( (int) $candidato->id, $candidato->estado );
																	self::limpiar_intentos();
																	return array( 'ok' => false, 'motivo' => 'vencido' );
													}
													if ( 'pausado' === $candidato->estado ) {
																	self::limpiar_intentos();
																	return array( 'ok' => false, 'motivo' => 'pausado' );
													}
													if ( 'cancelada' === $candidato->estado ) {
																	self::limpiar_intentos();
																	return array( 'ok' => false, 'motivo' => 'vencido' );
													}
													self::crear_sesion( (int) $candidato->id );
													self::limpiar_intentos();
													return array( 'ok' => true, 'motivo' => 'ok' );
									}
					}
					self::registrar_intento_fallido();
					return array( 'ok' => false, 'motivo' => 'invalido' );
		}
		protected static function crear_sesion( $miembro_id ) {
					global $wpdb;
					$token   = CN_Helpers::generar_token();
					$expira  = gmdate( 'Y-m-d H:i:s', time() + self::SESSION_DIAS * DAY_IN_SECONDS );
					$tabla   = CN_DB::tabla( 'sesiones' );
					$wpdb->insert(
									$tabla,
									array(
													'miembro_id' => $miembro_id,
													'token'      => $token,
													'expira'     => $expira,
												),
									array( '%d', '%s', '%s' )
								);
					$secure = is_ssl();
					setcookie(
									self::COOKIE_NAME,
									$token,
									array(
													'expires'  => time() + self::SESSION_DIAS * DAY_IN_SECONDS,
													'path'     => COOKIEPATH ? COOKIEPATH : '/',
													'domain'   => COOKIE_DOMAIN,
													'secure'   => $secure,
													'httponly' => true,
													'samesite' => 'Lax',
												),
								);
					$_COOKIE[ self::COOKIE_NAME ] = $token;
		}
		public static function get_miembro_actual() {
					global $wpdb;
					if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
									return null;
					}
					$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
					if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
									return null;
					}
					$t_sesiones = CN_DB::tabla( 'sesiones' );
					$t_miembros = CN_DB::tabla( 'miembros' );
					$miembro = $wpdb->get_row(
									$wpdb->prepare(
													"SELECT m.id, m.nombre_apellido, m.estado, m.fecha_fin_trial
																 FROM {$t_sesiones} s
																 				 INNER JOIN {$t_miembros} m ON m.id = s.miembro_id
																 				 				 WHERE s.token = %s AND s.expira > UTC_TIMESTAMP()",
													$token
												)
									);
					if ( ! $miembro ) {
									return null;
					}
					if ( self::trial_vencido( $miembro->fecha_fin_trial ) ) {
									self::marcar_cancelada_si_hace_falta( (int) $miembro->id, $miembro->estado );
									self::logout();
									return null;
					}
					if ( 'pausado' === $miembro->estado || 'cancelada' === $miembro->estado ) {
									return null;
					}
					return $miembro;
		}
		public static function logout() {
					global $wpdb;
					if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
									$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
									$tabla = CN_DB::tabla( 'sesiones' );
									$wpdb->delete( $tabla, array( 'token' => $token ), array( '%s' ) );
									setcookie(
													self::COOKIE_NAME,
													'',
													array(
																	'expires'  => time() - HOUR_IN_SECONDS,
																	'path'     => COOKIEPATH ? COOKIEPATH : '/',
																	'domain'   => COOKIE_DOMAIN,
																	'secure'   => is_ssl(),
																	'httponly' => true,
																	'samesite' => 'Lax',
																)
												);
									unset( $_COOKIE[ self::COOKIE_NAME ] );
					}
		}
		protected static function trial_vencido( $fecha_fin_trial ) {
					if ( empty( $fecha_fin_trial ) || '0000-00-00 00:00:00' === $fecha_fin_trial ) {
									return false;
					}
					return strtotime( $fecha_fin_trial . ' UTC' ) < time();
		}
		protected static function marcar_cancelada_si_hace_falta( $miembro_id, $estado_actual ) {
					global $wpdb;
					if ( 'cancelada' === $estado_actual ) {
									return;
					}
					$wpdb->update(
									CN_DB::tabla( 'miembros' ),
									array(
													'estado'             => 'cancelada',
													'fecha_modificacion' => current_time( 'mysql', true ),
												),
									array( 'id' => $miembro_id ),
									array( '%s', '%s' ),
									array( '%d' )
								);
		}
		/**
		 * Cron diario (cn_cron_revocar_trials): da de baja los trials vencidos que NO se convirtieron en suscripción.
		 *  - Con preapproval_id: solo se limpia fecha_fin_trial. La comparación es ESTRICTA contra '' (NULL y '' son
		 *    "sin suscripción"; el texto '0' cuenta como un valor, o sea que tampoco se cancela: decisión conservadora,
		 *    nunca se baja a alguien por un valor raro en esa columna).
		 *  - Sin preapproval_id y con la guarda activa (opción cn_guarda_trial_mp, activada por defecto): antes de
		 *    cancelar se busca en Mercado Pago si pagó por fuera del trial (CN_Sync::guarda_trial). Si pagó, NO se
		 *    cancela: se limpia fecha_fin_trial y se avisa por n8n ("pagó por link directo, vincular"). Si MP falla, NO
		 *    se cancela y fecha_fin_trial queda como está, así la próxima corrida lo reintenta.
		 *  - Si no pagó (o no hay email para verificar) se cancela y se manda el mail de fin de trial, como siempre. La
		 *    cancelación vuelve a leer la fila con FOR UPDATE: si en el medio se vinculó una suscripción, no se toca.
		 */
		public static function revocar_trials_vencidos() {
			global $wpdb;
			$t_miembros = CN_DB::tabla( 'miembros' );
			$ahora      = current_time( 'mysql', true );
			$vencidos   = $wpdb->get_results(
				"SELECT id, preapproval_id, nombre_apellido, email, fecha_fin_trial, trial_payment_id FROM {$t_miembros}
				 WHERE fecha_fin_trial IS NOT NULL
				   AND fecha_fin_trial < UTC_TIMESTAMP()
				   AND estado != 'cancelada'"
			);
			$guarda_activa = class_exists( 'CN_Sync' ) ? CN_Sync::guarda_trial_activa() : true;
			foreach ( (array) $vencidos as $miembro ) {
				if ( '' !== (string) $miembro->preapproval_id ) {
					$wpdb->update(
						$t_miembros,
						array( 'fecha_fin_trial' => null, 'fecha_modificacion' => $ahora ),
						array( 'id' => $miembro->id ),
						array( '%s', '%s' ),
						array( '%d' )
					);
					continue;
				}
				if ( $guarda_activa ) {
					$g = class_exists( 'CN_Sync' )
						? CN_Sync::guarda_trial( $miembro )
						: array( 'resultado' => 'error', 'detalle' => 'CN_Sync no está cargada' );
					if ( 'error' === $g['resultado'] ) {
						// MP falló: no se cancela a nadie; fecha_fin_trial sigue vencida y la próxima corrida lo reintenta.
						CN_Webhook::registrar_log_mp( 'trial_guarda_error', array( 'miembro_id' => (int) $miembro->id, 'detalle' => $g['detalle'] ) );
						continue;
					}
					if ( 'pago' === $g['resultado'] ) {
						$ok = $wpdb->update(
							$t_miembros,
							array( 'fecha_fin_trial' => null, 'fecha_modificacion' => $ahora ),
							array( 'id' => $miembro->id ),
							array( '%s', '%s' ),
							array( '%d' )
						);
						if ( false === $ok ) {
							CN_Webhook::registrar_log_mp( 'trial_guarda_error', array( 'miembro_id' => (int) $miembro->id, 'detalle' => 'no se pudo limpiar fecha_fin_trial' ) );
							continue; // sigue vencida: se reintenta (y se avisa) en la próxima corrida.
						}
						$detalle = array(
							'miembro_id' => (int) $miembro->id,
							'nombre'     => (string) $miembro->nombre_apellido,
							'email'      => (string) $miembro->email,
							'via'        => $g['via'],
							'monto'      => $g['monto'],
							'fecha'      => $g['fecha'],
							'detalle'    => $g['detalle'],
							'aviso'      => 'Pagó por link directo después del trial: no se canceló. Vincular su suscripción en Club Natureza → Socias → Vincular.',
						);
						CN_Webhook::registrar_log_mp( 'trial_pago_directo', $detalle );
						CN_Webhook::avisar_error_n8n( 'trial_pago_directo', $detalle );
						continue;
					}
					if ( 'sin_email' === $g['resultado'] ) {
						CN_Webhook::registrar_log_mp( 'trial_guarda_sin_email', array( 'miembro_id' => (int) $miembro->id ) );
					}
				}
				if ( self::cancelar_trial_vencido( (int) $miembro->id, $ahora )
					&& class_exists( 'CN_Webhook' ) && ! empty( $miembro->email ) ) {
					CN_Webhook::enviar_mail_fin_trial( $miembro->nombre_apellido, $miembro->email );
				}
			}
		}
		/**
		 * Cancela UN trial vencido en una transacción: relee la fila con FOR UPDATE y solo cancela si sigue vencido,
		 * sin suscripción (misma comparación estricta) y no cancelada. true = se canceló.
		 */
		protected static function cancelar_trial_vencido( $miembro_id, $ahora ) {
			global $wpdb;
			$t_miembros     = CN_DB::tabla( 'miembros' );
			$en_transaccion = false;
			try {
				if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
					throw new RuntimeException( 'no se pudo abrir la transaccion' );
				}
				$en_transaccion = true;
				$fila = $wpdb->get_row(
					$wpdb->prepare( "SELECT id, estado, preapproval_id, fecha_fin_trial FROM {$t_miembros} WHERE id = %d FOR UPDATE", $miembro_id )
				);
				if ( ! $fila || 'cancelada' === (string) $fila->estado || '' !== (string) $fila->preapproval_id
					|| empty( $fila->fecha_fin_trial ) || (string) $fila->fecha_fin_trial >= $ahora ) {
					$wpdb->query( 'ROLLBACK' ); // cambió mientras tanto (p. ej. se vinculó una suscripción): no se toca.
					return false;
				}
				$ok = $wpdb->update(
					$t_miembros,
					array( 'estado' => 'cancelada', 'fecha_modificacion' => $ahora ),
					array( 'id' => $miembro_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				if ( false === $ok ) {
					throw new RuntimeException( 'no se pudo cancelar: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
				}
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new RuntimeException( 'no se pudo confirmar la transaccion' );
				}
				return true;
			} catch ( Throwable $e ) {
				if ( $en_transaccion ) {
					$wpdb->query( 'ROLLBACK' );
				}
				if ( class_exists( 'CN_Webhook' ) ) {
					CN_Webhook::registrar_log_mp( 'trial_guarda_error', array( 'miembro_id' => $miembro_id, 'detalle' => mb_substr( $e->getMessage(), 0, 200 ) ) );
				}
				return false;
			}
		}
}
