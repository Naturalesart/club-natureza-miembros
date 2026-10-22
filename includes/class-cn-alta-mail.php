<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Alta por mail: una suscripción pagada por el link directo del plan, de alguien sin ficha, no trae email ni referencia y
 * MP no devuelve a la clienta con el preapproval_id. El sync (cada 30 min) la detecta, saca el email del pagador del
 * cobro aprobado y le manda a esa casilla un link FIRMADO a la página de retorno ([cn_retorno_suscripcion]) para que
 * complete celular y nombre. La página (CN_Retorno) hace todo lo demás con sus protecciones de siempre.
 *
 * Modos (opción cn_alta_mail_modo, por defecto 'off'):
 *  - 'off': no hace nada (ni una consulta a MP).
 *  - 'log': solo registra en mp_log (alta_mail_simulado, email enmascarado) qué haría. NO manda mail.
 *  - 'on' : manda el mail (máx. 1 por suscripción).
 * Al salir de 'off' Config guarda cn_alta_mail_desde = ahora: solo suscripciones creadas desde ese momento son elegibles
 * (el backlog de suscripciones sin vincular no se toca).
 *
 * Mercado Pago: SOLO GET (con CN_MP::get_con_reintentos y presupuesto propio de pedidos/espera/tiempo). No vincula ni
 * crea nada por su cuenta: eso lo hace la clienta en la página. Errores técnicos: solo mp_log + cartel Avisos.
 */
class CN_Alta_Mail {
	const OPT_MODO      = 'cn_alta_mail_modo';
	const OPT_DESDE     = 'cn_alta_mail_desde';    // timestamp: solo suscripciones con date_created >= esto
	const OPT_URL       = 'cn_alta_mail_url';      // URL de la página con [cn_retorno_suscripcion] (si está vacía se busca sola)
	const OPT_ENVIADOS  = 'cn_alta_mail_enviados'; // preapproval_id => timestamp (mail mandado)
	const OPT_SIMULADOS = 'cn_alta_mail_simulados'; // ídem para el modo 'log' (no cuenta como enviado)
	const OPT_AVISADOS  = 'cn_alta_mail_avisados'; // 'motivo|id' => timestamp (una sola línea en el cartel por caso)
	const MAX_ACCIONES  = 3;    // suscripciones por corrida a las que se les manda (o simula) el mail
	const MAX_CONSULTAS = 10;   // suscripciones por corrida a las que se les consulta el cobro (acota los pedidos)
	const MAX_PAGOS_POR_SUB = 3;
	const TOKEN_DIAS    = 14;
	const SIN_EMAIL_SEG = 259200; // 72 hs
	const TOPE_REQUESTS = 40;
	const TOPE_ESPERA   = 20;
	const TOPE_TIEMPO   = 60;
	const MAX_MAPA      = 500;
	/** Monto mensual => plan. Solo estos dos: el plan viejo de $15.000 NO es elegible (solo cartel). */
	const MONTOS = array( 15500 => 'basico', 32000 => 'full' );

	public static function modo() {
		$m = (string) get_option( self::OPT_MODO, 'off' );
		return in_array( $m, array( 'log', 'on' ), true ) ? $m : 'off';
	}

	/** Lo llama Config al guardar: al salir de 'off' fija el corte (solo suscripciones nuevas desde ahora). */
	public static function al_guardar_modo( $anterior, $nuevo ) {
		if ( 'off' === $anterior && in_array( $nuevo, array( 'log', 'on' ), true ) ) {
			update_option( self::OPT_DESDE, time() );
		}
	}

	// ---------------------------------------------------------------- token firmado
	protected static function firma( $payload ) {
		return hash_hmac( 'sha256', 'cn_alta_mail|' . $payload, wp_salt( 'auth' ) );
	}

	/** Token = <preapproval_id>.<vencimiento>.<HMAC>. No adivinable: sin la sal del sitio no se puede fabricar. */
	public static function crear_token( $id, $vence = null ) {
		$vence   = null === $vence ? time() + self::TOKEN_DIAS * DAY_IN_SECONDS : (int) $vence;
		$payload = strtolower( $id ) . '.' . $vence;
		return $payload . '.' . self::firma( $payload );
	}

	/**
	 * Valida un token. Orden: formato -> firma (tiempo constante) -> vencimiento -> que el preapproval no esté ya vinculado.
	 *
	 * @return array{ok:bool,id:string,motivo:string}  motivo: formato | firma | vencido | vinculado | ''
	 */
	public static function verificar( $token ) {
		$r = array( 'ok' => false, 'id' => '', 'motivo' => 'formato' );
		if ( ! is_string( $token ) || ! preg_match( '/^([a-f0-9]{32})\.(\d{9,11})\.([a-f0-9]{64})\z/', $token, $m ) ) {
			return $r;
		}
		if ( ! hash_equals( self::firma( $m[1] . '.' . $m[2] ), $m[3] ) ) {
			$r['motivo'] = 'firma';
			return $r;
		}
		if ( (int) $m[2] < time() ) {
			$r['motivo'] = 'vencido';
			return $r;
		}
		if ( self::vinculado( $m[1] ) ) {
			$r['motivo'] = 'vinculado';
			return $r;
		}
		return array( 'ok' => true, 'id' => $m[1], 'motivo' => '' );
	}

	/** ¿Alguna ficha tiene ya ese preapproval_id? Ante un error de base se asume que sí (no se hace nada). */
	protected static function vinculado( $id ) {
		global $wpdb;
		$f = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE preapproval_id = %s LIMIT 1', $id ) );
		return '' !== (string) $wpdb->last_error || null !== $f;
	}

	// ---------------------------------------------------------------- utilidades
	protected static function mapa( $opt ) {
		$m = get_option( $opt, array() );
		return is_array( $m ) ? $m : array();
	}

	protected static function mapa_set( $opt, $m ) {
		update_option( $opt, array_slice( $m, -self::MAX_MAPA, null, true ), false );
	}

	/** Una sola línea en el cartel Avisos por (motivo, suscripción). */
	protected static function avisar_una_vez( $motivo, $id, $detalle ) {
		$av = self::mapa( self::OPT_AVISADOS );
		$k  = $motivo . '|' . $id;
		if ( isset( $av[ $k ] ) ) {
			return;
		}
		$av[ $k ] = time();
		self::mapa_set( self::OPT_AVISADOS, $av );
		CN_Webhook::avisar_error_n8n( $motivo, array( 'preapproval_id' => $id ) + $detalle );
	}

	public static function mascara_email( $email ) {
		$email = trim( (string) $email );
		$p     = strpos( $email, '@' );
		return ( false === $p || $p < 1 ) ? '***' : substr( $email, 0, min( 2, $p ) ) . '***' . substr( $email, $p );
	}

	/** URL de la página con [cn_retorno_suscripcion]: la de Config o, si está vacía, la primera página publicada que lo tenga. */
	public static function url_pagina() {
		global $wpdb;
		$u = trim( (string) get_option( self::OPT_URL, '' ) );
		if ( '' !== $u ) {
			return $u;
		}
		$pid = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND post_content LIKE '%[" . CN_Retorno::SHORTCODE . "%' ORDER BY ID ASC LIMIT 1" );
		return $pid > 0 ? (string) get_permalink( $pid ) : '';
	}

	// ---------------------------------------------------------------- corrida (la llama CN_Sync::correr)
	/**
	 * @return array{modo:string,candidatas:int,consultadas:int,acciones:int,motivo:string}
	 */
	public static function correr() {
		$res  = array( 'modo' => self::modo(), 'candidatas' => 0, 'consultadas' => 0, 'acciones' => 0, 'motivo' => '' );
		$modo = $res['modo'];
		if ( 'off' === $modo ) {
			return $res;
		}
		$desde = (int) get_option( self::OPT_DESDE, 0 );
		if ( $desde <= 0 ) {
			$res['motivo'] = 'sin_corte'; // sin fecha de corte no se toca nada (Config la fija al salir de 'off').
			CN_Webhook::registrar_log_mp( 'alta_mail_sin_corte', array( 'modo' => $modo ) );
			return $res;
		}
		$token = CN_MP::get_access_token();
		if ( '' === $token ) {
			$res['motivo'] = 'sin_token';
			return $res;
		}
		$pres  = array( 'requests' => self::TOPE_REQUESTS, 'espera' => self::TOPE_ESPERA, 'hasta' => time() + self::TOPE_TIEMPO );
		$items = self::listar_authorized( $token, $pres );
		if ( null === $items ) {
			$res['motivo'] = 'mp_lista'; // MP falló: el rastro mp_api_error ya está; se reintenta en la próxima corrida.
			return $res;
		}
		$enviados   = self::mapa( self::OPT_ENVIADOS );
		$simulados  = self::mapa( self::OPT_SIMULADOS );
		$candidatas = array();
		foreach ( $items as $it ) {
			$id = isset( $it['id'] ) && is_string( $it['id'] ) ? strtolower( $it['id'] ) : '';
			if ( ! CN_Vinculo_Manual::id_valido( $id ) || ( $it['status'] ?? '' ) !== 'authorized' || isset( $enviados[ $id ] ) || ( 'log' === $modo && isset( $simulados[ $id ] ) ) ) {
				continue;
			}
			$creada = isset( $it['date_created'] ) ? strtotime( (string) $it['date_created'] ) : false;
			if ( false === $creada || $creada < $desde ) {
				continue; // anterior al corte (backlog) o sin fecha: no se toca.
			}
			$candidatas[ $id ] = array( 'item' => $it, 'creada' => $creada );
		}
		$candidatas = self::sacar_vinculadas( $candidatas );
		uasort( $candidatas, function ( $a, $b ) {
			return $a['creada'] <=> $b['creada'];
		} );
		$res['candidatas'] = count( $candidatas );

		foreach ( $candidatas as $id => $c ) {
			if ( $res['acciones'] >= self::MAX_ACCIONES || $res['consultadas'] >= self::MAX_CONSULTAS ) {
				break;
			}
			$monto = isset( $c['item']['auto_recurring']['transaction_amount'] ) ? $c['item']['auto_recurring']['transaction_amount'] : null;
			if ( ! is_numeric( $monto ) || ! isset( self::MONTOS[ (int) round( (float) $monto ) ] ) || abs( (float) $monto - round( (float) $monto ) ) > 0.001 ) {
				self::avisar_una_vez( 'alta_mail_monto_no_valido', $id, array( 'monto' => is_scalar( $monto ) ? (string) $monto : '' ) );
				continue;
			}
			$plan = self::MONTOS[ (int) round( (float) $monto ) ];
			$res['consultadas']++;
			$email = self::email_del_pagador( $id, $token, $pres );
			if ( null === $email ) {
				if ( time() - $c['creada'] > self::SIN_EMAIL_SEG ) {
					self::avisar_una_vez( 'alta_mail_sin_email', $id, array( 'horas' => (int) floor( ( time() - $c['creada'] ) / 3600 ) ) );
				}
				continue; // sin cobro aprobado con email todavía: se reintenta en la próxima corrida.
			}
			if ( self::accionar( $modo, $id, $email, $plan, $monto ) ) {
				$res['acciones']++;
			}
		}
		return $res;
	}

	/** Suscripciones authorized (hasta 4 páginas de 100), con reintentos ante 429. null si MP falló. */
	protected static function listar_authorized( $token, &$pres ) {
		$items = array();
		for ( $pag = 0; $pag < 4; $pag++ ) {
			$r = CN_MP::get_con_reintentos( '/preapproval/search?status=authorized&limit=100&offset=' . ( $pag * 100 ), $token, $pres );
			if ( ! $r['ok'] || ! isset( $r['data']['results'] ) || ! is_array( $r['data']['results'] ) ) {
				return null;
			}
			foreach ( $r['data']['results'] as $it ) {
				if ( is_array( $it ) ) {
					$items[] = $it;
				}
			}
			$total = isset( $r['data']['paging']['total'] ) ? (int) $r['data']['paging']['total'] : count( $items );
			if ( count( $r['data']['results'] ) < 100 || ( $pag + 1 ) * 100 >= $total ) {
				break;
			}
		}
		return $items;
	}

	/** Quita las que alguna ficha ya tiene vinculadas. Ante un error de base no queda ninguna (no se hace nada). */
	protected static function sacar_vinculadas( $candidatas ) {
		global $wpdb;
		if ( ! $candidatas ) {
			return array();
		}
		$ids  = array_keys( $candidatas );
		$sql  = 'SELECT preapproval_id FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE preapproval_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ')';
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $ids ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return array();
		}
		foreach ( (array) $rows as $v ) {
			unset( $candidatas[ strtolower( (string) $v ) ] );
		}
		return $candidatas;
	}

	/** Email del pagador del primer cobro APROBADO de la suscripción (solo GET); null si todavía no hay o MP falló. */
	protected static function email_del_pagador( $id, $token, &$pres ) {
		$r = CN_MP::get_con_reintentos( '/authorized_payments/search?preapproval_id=' . rawurlencode( $id ), $token, $pres );
		if ( ! $r['ok'] || ! isset( $r['data']['results'] ) || ! is_array( $r['data']['results'] ) ) {
			return null;
		}
		$n = 0;
		foreach ( $r['data']['results'] as $it ) {
			$pid = isset( $it['payment']['id'] ) ? (string) $it['payment']['id'] : '';
			if ( '' === $pid || ! ctype_digit( $pid ) || ( isset( $it['payment']['status'] ) && 'approved' !== $it['payment']['status'] ) ) {
				continue;
			}
			if ( ++$n > self::MAX_PAGOS_POR_SUB ) {
				break;
			}
			$p = CN_MP::get_con_reintentos( '/v1/payments/' . rawurlencode( $pid ), $token, $pres );
			if ( ! $p['ok'] || ! is_array( $p['data'] ) || ( $p['data']['status'] ?? '' ) !== 'approved' ) {
				continue;
			}
			$email = sanitize_email( trim( (string) ( $p['data']['payer']['email'] ?? '' ) ) );
			if ( '' !== $email && is_email( $email ) ) {
				return strtolower( $email );
			}
		}
		return null;
	}

	/** Según el modo: simula (log) o manda (on). true si hizo la acción. */
	protected static function accionar( $modo, $id, $email, $plan, $monto ) {
		$base = array( 'preapproval_id' => $id, 'email' => self::mascara_email( $email ), 'plan' => $plan, 'monto' => (float) $monto );
		if ( 'log' === $modo ) {
			CN_Webhook::registrar_log_mp( 'alta_mail_simulado', $base + array( 'pagina' => '' !== self::url_pagina(), 'retorno_activo' => CN_Retorno::activo() ) );
			$s        = self::mapa( self::OPT_SIMULADOS );
			$s[ $id ] = time();
			self::mapa_set( self::OPT_SIMULADOS, $s );
			return true;
		}
		// modo 'on'
		$url = self::url_pagina();
		if ( '' === $url ) {
			self::avisar_una_vez( 'alta_mail_sin_pagina', $id, array( 'detalle' => 'No hay página con [cn_retorno_suscripcion]: cargá su URL en Config.' ) );
			return false;
		}
		if ( ! CN_Retorno::activo() ) {
			self::avisar_una_vez( 'alta_mail_retorno_apagado', $id, array( 'detalle' => 'La página de retorno está apagada (Config): no se mandó el mail.' ) );
			return false;
		}
		// Idempotencia: se marca ANTES de mandar (máx. 1 mail por suscripción); si el envío falla se desmarca.
		$e        = self::mapa( self::OPT_ENVIADOS );
		$e[ $id ] = time();
		self::mapa_set( self::OPT_ENVIADOS, $e );
		$ok = self::enviar_mail( $email, add_query_arg( array( 'cn_alta' => self::crear_token( $id ) ), $url ) );
		if ( ! $ok ) {
			$e = self::mapa( self::OPT_ENVIADOS );
			unset( $e[ $id ] );
			self::mapa_set( self::OPT_ENVIADOS, $e );
			CN_Webhook::avisar_error_n8n( 'alta_mail_fallo_envio', array( 'preapproval_id' => $id ) );
			return false;
		}
		CN_Webhook::registrar_log_mp( 'alta_mail_enviado', $base );
		return true;
	}

	/** Mail con la marca de los demás (Georgia, verde #2f3b2c, crema #f5f3ef). Sin contraseñas ni datos sensibles. */
	public static function enviar_mail( $email, $link ) {
		$wa      = defined( 'CN_WA_SOPORTE' ) ? 'https://wa.me/' . CN_WA_SOPORTE : '';
		$asunto  = 'Tu suscripción al Club Natureza está activa: completá tus datos';
		$cuerpo  = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f5f3ef;font-family:Georgia,serif;">';
		$cuerpo .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f3ef;padding:32px 16px;">';
		$cuerpo .= '<tr><td align="center">';
		$cuerpo .= '<table role="presentation" width="100%" style="max-width:480px;background:#ffffff;border-radius:12px;overflow:hidden;">';
		$cuerpo .= '<tr><td style="background:#2f3b2c;padding:28px 32px;text-align:center;">';
		$cuerpo .= '<span style="color:#f5f3ef;font-size:22px;letter-spacing:1px;">🌿 Club Natureza</span></td></tr>';
		$cuerpo .= '<tr><td style="padding:32px;">';
		$cuerpo .= '<h1 style="font-size:20px;color:#2f3b2c;margin:0 0 16px;">¡Gracias por sumarte! 🎉</h1>';
		$cuerpo .= '<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 20px;">Tu suscripción al Club Natureza está activa. Para entrar al Club, completá tus datos en este botón:</p>';
		$cuerpo .= '<table role="presentation" width="100%"><tr><td align="center">';
		$cuerpo .= '<a href="' . esc_url( $link ) . '" style="display:inline-block;background:#2f3b2c;color:#f5f3ef;text-decoration:none;padding:14px 32px;border-radius:8px;font-size:15px;margin-bottom:24px;">Completar mis datos →</a>';
		$cuerpo .= '</td></tr></table>';
		$cuerpo .= '<p style="font-size:14px;color:#555;line-height:1.6;margin:0 0 16px;">Si ya eras socia, usá el mismo celular con el que entrabas.</p>';
		$cuerpo .= '<p style="font-size:14px;color:#555;line-height:1.6;margin:0 0 16px;">El link es personal y vale ' . (int) self::TOKEN_DIAS . ' días.</p>';
		$cuerpo .= '<p style="font-size:14px;color:#555;line-height:1.6;margin:20px 0 0;">Cualquier duda, escribinos por WhatsApp' . ( '' !== $wa ? ': <a href="' . esc_url( $wa ) . '" style="color:#2f3b2c;">' . esc_html( $wa ) . '</a>' : '.' ) . '</p>';
		$cuerpo .= '<p style="font-size:15px;color:#2f3b2c;margin:20px 0 0;">¡Bienvenida! 🌿</p>';
		$cuerpo .= '</td></tr></table></td></tr></table></body></html>';
		return (bool) wp_mail( $email, $asunto, $cuerpo, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
