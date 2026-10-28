<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Alertas GRAVES: las únicas tres situaciones por las que sale un mail (vía el webhook de n8n "Alerta de errores").
 * Todo lo demás queda en mp_log + cartel Avisos (CN_Webhook::avisar_error_n8n ya no manda nada afuera).
 *
 *  1. Sync caído: más de 24 hs sin una corrida COMPLETA y exitosa de CN_Sync::correr. Máx. 1 mail cada 24 hs; al
 *     recuperarse queda mp_log 'sync_recuperado' (sin mail).
 *  2. Token de Mercado Pago inválido: una racha de GET con 401/403 (429 y 5xx no cuentan) que lleva 60 min o más sin ningún
 *     2xx en el medio. Antes de los 60 min solo se marca la racha. Máx. 1 mail cada 24 hs mientras siga; se limpia solo cuando
 *     una llamada vuelve a andar.
 *  3. Pagos sin acceso: los casos "pagó y no tiene acceso" (preapproval_sin_match, excepcion_trial_alta,
 *     metadata_incompleta) se anotan con la fecha de la primera vez (opción acotada a 500) y un
 *     chequeo diario manda UN mail resumen con los que llevan más de 72 hs sin resolverse (resuelto = ya hay ficha con
 *     ese preapproval_id o ese pago). Máx. 1 mail por día; ids enmascarados (6 caracteres + ...), nunca emails ni nombres.
 *
 *  4. WordPress no puede enviar mails: 3 o más fallos de wp_mail (hook wp_mail_failed, de cualquier plugin) en 6 hs;
 *     máx. 1 mail cada 24 hs. Sin limpieza: la ventana de 6 hs lo resuelve sola.
 *
 * Solo lectura sobre la base (SELECT) y cero llamadas a Mercado Pago. Sin migraciones: options + mp_log.
 */
class CN_Alertas {
	const OPT_SYNC_OK        = 'cn_sync_ultima_ok';          // timestamp de la última corrida completa y exitosa del sync
	const OPT_SYNC_VIGILADO  = 'cn_sync_vigilado_desde';     // desde cuándo se vigila (para alertar aunque nunca haya corrido bien)
	const OPT_SYNC_CAIDO     = 'cn_sync_caido';              // '1' mientras está alertado como caído
	const OPT_MAIL_SYNC      = 'cn_alerta_sync_ultimo_mail';
	const OPT_TOKEN_DESDE    = 'cn_mp_token_invalido_desde'; // timestamp del primer 401/403 de la racha; 0 = todo bien
	const OPT_TOKEN_HTTP     = 'cn_mp_token_invalido_http';
	const OPT_MAIL_TOKEN     = 'cn_alerta_token_ultimo_mail';
	const OPT_CASOS          = 'cn_casos_sin_acceso';        // clave => array( motivo, tipo, id, desde )
	const OPT_MAIL_CASOS     = 'cn_alerta_casos_ultimo_mail';
	const OPT_ULTIMO_ENVIO   = 'cn_alertas_ultimo_envio';    // timestamp del último mail de cualquier alerta (tope global de 1 por hora para las alertas nuevas)
	const ENTRE_MAILS_SEG    = 3600;
	const MAX_CASOS          = 500;
	const SYNC_MAX_SEG       = 86400; // 24 hs sin corrida exitosa
	const CASO_MAX_SEG       = 259200; // 72 hs sin resolverse
	const REPETIR_SEG        = 86400; // cada alerta, como máximo 1 mail por día
	const TOKEN_RACHA_SEG    = 3600;  // 60 min de 401/403 seguidos (sin ningún 2xx) antes de mandar el mail del token
	/** Motivos que cuentan como "pagó y no tiene acceso" y de qué campo del detalle sale el id a vigilar. */
	const MOTIVOS_CASO = array(
		'preapproval_sin_match' => 'preapproval_id',
		'excepcion_trial_alta'  => 'payment_id',
		'metadata_incompleta'   => 'payment_id',
	);
	const ASUNTO_SYNC  = '[Club Natureza] GRAVE: sync caído hace más de 24 hs';
	const ASUNTO_TOKEN = '[Club Natureza] GRAVE: token de Mercado Pago inválido';
	const ASUNTO_CASOS = '[Club Natureza] GRAVE: %d pagos sin acceso hace más de 72 hs';

	/** Id enmascarado: 6 caracteres + "...". */
	public static function mascara( $id ) {
		return substr( (string) $id, 0, 6 ) . '...';
	}

	/**
	 * ÚNICA salida a n8n (mail). Fire-and-forget. Manda asunto y cuerpo ya armados (n8n hoy arma el asunto por su cuenta a
	 * partir de `motivo`; ver la descripción del PR para que use `asunto`/`cuerpo`).
	 */
	protected static function enviar( $motivo, $asunto, $cuerpo, $detalle ) {
		wp_remote_post( CN_Webhook::N8N_ALERTA_URL, array(
			'timeout'  => 5,
			'blocking' => false,
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( array(
				'motivo'  => $motivo,
				'asunto'  => $asunto,
				'cuerpo'  => $cuerpo,
				'detalle' => $detalle,
				'fecha'   => current_time( 'mysql', true ),
			) ),
		) );
		update_option( self::OPT_ULTIMO_ENVIO, time(), false );
		CN_Webhook::registrar_log_mp( 'alerta_grave_enviada', array( 'motivo' => $motivo, 'asunto' => $asunto ) );
	}

	/**
	 * Como enviar(), pero respeta el tope de 1 mail por hora entre TODAS las alertas y el período propio de la alerta
	 * ($opt_propia guarda cuándo salió su último mail). Si no corresponde mandar, deja rastro y devuelve false.
	 */
	public static function enviar_limitado( $motivo, $asunto, $cuerpo, $detalle, $opt_propia ) {
		if ( time() - (int) get_option( self::OPT_ULTIMO_ENVIO, 0 ) < self::ENTRE_MAILS_SEG ) {
			CN_Webhook::registrar_log_mp( 'alerta_grave_demorada', array( 'motivo' => $motivo, 'razon' => 'ya salió otro mail en la última hora' ) );
			return false;
		}
		update_option( $opt_propia, time(), false );
		self::enviar( $motivo, $asunto, $cuerpo, $detalle );
		return true;
	}

	protected static function hace( $ts ) {
		$h = (int) floor( ( time() - (int) $ts ) / 3600 );
		return $h < 48 ? $h . ' hs' : (int) floor( $h / 24 ) . ' días';
	}

	// ======================================================================
	// 1. HEARTBEAT DEL SYNC
	// ======================================================================
	/** Lo llama CN_Sync::correr ANTES de correr (con el sync activo y sin otra corrida en curso). */
	public static function chequear_sync() {
		$ultima = (int) get_option( self::OPT_SYNC_OK, 0 );
		$desde  = (int) get_option( self::OPT_SYNC_VIGILADO, 0 );
		if ( $desde <= 0 ) {
			update_option( self::OPT_SYNC_VIGILADO, time(), false ); // primera vez: se empieza a contar desde ahora.
			return;
		}
		$referencia = max( $ultima, $desde );
		if ( time() - $referencia <= self::SYNC_MAX_SEG ) {
			return;
		}
		update_option( self::OPT_SYNC_CAIDO, '1', false );
		if ( time() - (int) get_option( self::OPT_MAIL_SYNC, 0 ) < self::REPETIR_SEG ) {
			return; // ya se avisó en las últimas 24 hs.
		}
		update_option( self::OPT_MAIL_SYNC, time(), false );
		$cuando = $ultima > 0 ? gmdate( 'd/m/Y H:i', $ultima ) . ' UTC (hace ' . self::hace( $ultima ) . ')' : 'nunca (se vigila desde ' . gmdate( 'd/m/Y H:i', $desde ) . ' UTC)';
		self::enviar(
			'sync_caido',
			self::ASUNTO_SYNC,
			"Qué pasó: la sincronización con Mercado Pago (cada 30 min) no completa una corrida exitosa desde hace más de 24 hs.\n"
			. "Última corrida exitosa: {$cuando}.\n"
			. "Qué hacer: en wp-admin → Club Natureza → Socias, mirar el cartel Avisos (errores sub_error / mp_api_error) y Config (sync activado, token de Mercado Pago). Si el cron de WordPress no corre, revisar el hosting.",
			array( 'ultima_ok' => $ultima > 0 ? gmdate( 'Y-m-d H:i:s', $ultima ) : null )
		);
	}

	/** Lo llama CN_Sync::correr al terminar una corrida completa SIN errores. */
	public static function sync_ok() {
		update_option( self::OPT_SYNC_OK, time(), false );
		if ( '1' === (string) get_option( self::OPT_SYNC_CAIDO, '0' ) ) {
			update_option( self::OPT_SYNC_CAIDO, '0', false );
			CN_Webhook::registrar_log_mp( 'sync_recuperado', array( 'ultimo_mail' => gmdate( 'Y-m-d H:i:s', (int) get_option( self::OPT_MAIL_SYNC, 0 ) ) ) );
		}
	}

	// ======================================================================
	// 2. TOKEN DE MERCADO PAGO INVÁLIDO
	// ======================================================================
	/** Lo llama CN_MP cuando un GET devuelve 401/403 (nunca por 429 ni 5xx). Manda el mail solo si la racha lleva TOKEN_RACHA_SEG o más. */
	public static function token_invalido( $ruta, $http ) {
		$http = (int) $http;
		if ( ! in_array( $http, array( 401, 403 ), true ) ) {
			return;
		}
		$desde = (int) get_option( self::OPT_TOKEN_DESDE, 0 );
		if ( $desde <= 0 ) {
			$desde = time();
			update_option( self::OPT_TOKEN_DESDE, $desde, false );
		}
		update_option( self::OPT_TOKEN_HTTP, $http, false );
		if ( time() - $desde < self::TOKEN_RACHA_SEG ) {
			return; // racha corta (puede ser un rechazo puntual): solo se marca; si sigue a los 60 min, ahí se avisa.
		}
		if ( time() - (int) get_option( self::OPT_MAIL_TOKEN, 0 ) < self::REPETIR_SEG ) {
			return;
		}
		update_option( self::OPT_MAIL_TOKEN, time(), false );
		self::enviar(
			'mp_token_invalido',
			self::ASUNTO_TOKEN,
			"Qué pasó: Mercado Pago rechaza el acceso (HTTP {$http}) en " . (string) $ruta . ". Sin token válido no se sincronizan suscripciones, no funciona la guarda de trials ni el alta por mail.\n"
			. 'Desde cuándo: ' . gmdate( 'd/m/Y H:i', $desde ) . ' UTC (hace ' . self::hace( $desde ) . ").\n"
			. 'Qué hacer: en wp-admin → Club Natureza → Config, revisar/renovar el Access Token de Suscripciones (y el del Trial). La alerta se apaga sola cuando una llamada vuelve a funcionar.',
			array( 'http' => $http, 'ruta' => (string) $ruta, 'desde' => gmdate( 'Y-m-d H:i:s', $desde ) )
		);
	}

	/** Lo llama CN_MP cuando un GET responde 2xx: la racha de token inválido (si la había) termina. */
	public static function token_ok() {
		if ( (int) get_option( self::OPT_TOKEN_DESDE, 0 ) > 0 ) {
			update_option( self::OPT_TOKEN_DESDE, 0, false );
			CN_Webhook::registrar_log_mp( 'mp_token_recuperado', array() );
		}
	}

	// ======================================================================
	// 3. PAGÓ Y NO TIENE ACCESO (resumen diario a las 72 hs)
	// ======================================================================
	protected static function casos() {
		$c = get_option( self::OPT_CASOS, array() );
		return is_array( $c ) ? $c : array();
	}

	/**
	 * Lo llama CN_Webhook::avisar_error_n8n para los motivos de MOTIVOS_CASO. Anota la primera vez del caso; no manda nada.
	 * Sin id en el detalle no hay forma de saber si se resolvió: queda solo en el cartel.
	 */
	public static function registrar_caso( $motivo, $detalle ) {
		if ( ! isset( self::MOTIVOS_CASO[ $motivo ] ) || ! is_array( $detalle ) ) {
			return false;
		}
		$campo = self::MOTIVOS_CASO[ $motivo ];
		$id    = isset( $detalle[ $campo ] ) && is_scalar( $detalle[ $campo ] ) ? trim( (string) $detalle[ $campo ] ) : '';
		if ( '' === $id ) {
			return false;
		}
		$tipo  = 'payment_id' === $campo ? 'pago' : 'preapproval';
		$clave = $tipo . '|' . $id;
		$casos = self::casos();
		if ( isset( $casos[ $clave ] ) ) {
			return true; // ya anotado: se conserva la fecha de la primera vez.
		}
		$casos[ $clave ] = array( 'motivo' => (string) $motivo, 'tipo' => $tipo, 'id' => $id, 'desde' => time() );
		update_option( self::OPT_CASOS, array_slice( $casos, -self::MAX_CASOS, null, true ), false );
		return true;
	}

	/** Saca de la lista los casos que ya tienen ficha (por preapproval_id o por trial_payment_id). Solo SELECT. */
	public static function depurar_casos() {
		global $wpdb;
		$casos = self::casos();
		if ( ! $casos ) {
			return array();
		}
		$t = CN_DB::tabla( 'miembros' );
		foreach ( array( 'preapproval' => 'preapproval_id', 'pago' => 'trial_payment_id' ) as $tipo => $col ) {
			$ids = array();
			foreach ( $casos as $c ) {
				if ( $tipo === $c['tipo'] ) {
					$ids[] = (string) $c['id'];
				}
			}
			if ( ! $ids ) {
				continue;
			}
			$sql  = "SELECT {$col} FROM {$t} WHERE {$col} IN (" . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ')';
			$rows = $wpdb->get_col( $wpdb->prepare( $sql, $ids ) );
			if ( '' !== (string) $wpdb->last_error ) {
				continue; // ante la duda no se da por resuelto nada.
			}
			foreach ( (array) $rows as $v ) {
				unset( $casos[ $tipo . '|' . (string) $v ] );
			}
		}
		update_option( self::OPT_CASOS, $casos, false );
		return $casos;
	}

	/** Chequeo diario (cron cn_cron_alertas_graves): UN mail con los casos de más de 72 hs sin resolver; máx. 1 por día. */
	public static function chequear_casos() {
		$casos    = self::depurar_casos();
		$vencidos = array();
		foreach ( $casos as $c ) {
			if ( time() - (int) $c['desde'] > self::CASO_MAX_SEG ) {
				$vencidos[] = $c;
			}
		}
		if ( ! $vencidos ) {
			return 0;
		}
		if ( time() - (int) get_option( self::OPT_MAIL_CASOS, 0 ) < self::REPETIR_SEG ) {
			return count( $vencidos );
		}
		update_option( self::OPT_MAIL_CASOS, time(), false );
		$lineas = array();
		foreach ( array_slice( $vencidos, 0, 30 ) as $c ) {
			$lineas[] = '- ' . $c['motivo'] . ' · ' . ( 'pago' === $c['tipo'] ? 'pago ' : 'suscripción ' ) . self::mascara( $c['id'] ) . ' · desde ' . gmdate( 'd/m/Y', (int) $c['desde'] ) . ' (hace ' . self::hace( $c['desde'] ) . ')';
		}
		$n = count( $vencidos );
		self::enviar(
			'pagos_sin_acceso',
			sprintf( self::ASUNTO_CASOS, $n ),
			"Qué pasó: {$n} " . ( 1 === $n ? 'pago/suscripción lleva' : 'pagos/suscripciones llevan' ) . " más de 72 hs sin una ficha de socia asociada (la persona pagó y puede no tener acceso).\n"
			. implode( "\n", $lineas ) . ( $n > 30 ? "\n(y " . ( $n - 30 ) . ' más)' : '' ) . "\n"
			. 'Qué hacer: en wp-admin → Club Natureza → Socias, usar "Vincular suscripción (por celular)" o "Vincular en lote"; el detalle completo de cada caso está en el cartel Avisos. Este resumen se repite 1 vez por día mientras queden casos sin resolver.',
			array( 'cantidad' => $n, 'ids' => array_map( function ( $c ) {
				return self::mascara( $c['id'] );
			}, $vencidos ) )
		);
		return $n;
	}

	/** Entrada del cron diario. Nunca lanza. */
	public static function chequeo_diario() {
		try {
			self::chequear_casos();
		} catch ( Throwable $e ) {
			CN_Webhook::registrar_log_mp( 'sub_error', array( 'paso' => 'alertas_graves', 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
		}
		try {
			CN_Conciliacion::correr(); // D1: modo 'log' por defecto (solo mp_log); solo GET a MP.
		} catch ( Throwable $e ) {
			CN_Webhook::registrar_log_mp( 'sub_error', array( 'paso' => 'conciliacion', 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
		}
	}
	// ======================================================================
	// 4. WORDPRESS NO PUEDE ENVIAR MAILS (hook wp_mail_failed)
	// ======================================================================
	const OPT_WPMAIL_FALLOS  = 'cn_wp_mail_fallos';            // lista acotada: array( array( 'ts' => int, 'mensaje' => string ) )
	const OPT_MAIL_WPMAIL    = 'cn_alerta_wpmail_ultimo_mail';
	const WPMAIL_MIN_FALLOS  = 3;      // fallos en la ventana para alertar
	const WPMAIL_VENTANA_SEG = 21600;  // 6 hs
	const MAX_WPMAIL_FALLOS  = 50;
	const ASUNTO_WPMAIL      = '[Club Natureza] GRAVE: WordPress no puede enviar mails';
	/** Guarda contra re-entrada (por si algo dentro del registro dispara otro wp_mail_failed). */
	protected static $en_wpmail_fallo = false;

	/** Email enmascarado: 2 letras + ***@dominio. */
	public static function mascara_email( $email ) {
		$email = trim( (string) $email );
		$p     = strpos( $email, '@' );
		return ( false === $p || $p < 1 ) ? '***' : substr( $email, 0, min( 2, $p ) ) . '***' . substr( $email, $p );
	}

	/**
	 * Hook 'wp_mail_failed' (recibe el WP_Error de wp_mail, de este plugin o de cualquier otro). Deja mp_log 'wp_mail_fallo'
	 * (mensaje truncado a 200, destinatario enmascarado), guarda el fallo en una lista acotada y, con WPMAIL_MIN_FALLOS o más
	 * en las últimas 6 hs, manda la alerta (máx. 1 cada 24 hs). Nunca lanza. No hace falta "limpiar": la ventana lo resuelve.
	 */
	public static function wp_mail_fallo( $error ) {
		if ( self::$en_wpmail_fallo ) {
			return;
		}
		self::$en_wpmail_fallo = true;
		try {
			$mensaje = is_object( $error ) && method_exists( $error, 'get_error_message' ) ? (string) $error->get_error_message() : (string) $error;
			$mensaje = mb_substr( trim( preg_replace( '/\s+/', ' ', $mensaje ) ), 0, 200 );
			$datos   = is_object( $error ) && method_exists( $error, 'get_error_data' ) ? $error->get_error_data() : null;
			$para    = array();
			if ( is_array( $datos ) && isset( $datos['to'] ) ) {
				foreach ( (array) $datos['to'] as $t ) {
					$para[] = self::mascara_email( $t );
				}
			}
			CN_Webhook::registrar_log_mp( 'wp_mail_fallo', array( 'mensaje' => $mensaje, 'para' => $para ) );
			$ahora   = time();
			$lista   = get_option( self::OPT_WPMAIL_FALLOS, array() );
			$lista   = is_array( $lista ) ? $lista : array();
			$lista[] = array( 'ts' => $ahora, 'mensaje' => mb_substr( $mensaje, 0, 120 ) );
			$lista   = array_slice( $lista, -self::MAX_WPMAIL_FALLOS );
			update_option( self::OPT_WPMAIL_FALLOS, $lista, false );
			$recientes = array_values( array_filter( $lista, function ( $f ) use ( $ahora ) {
				return isset( $f['ts'] ) && $ahora - (int) $f['ts'] <= self::WPMAIL_VENTANA_SEG;
			} ) );
			if ( count( $recientes ) < self::WPMAIL_MIN_FALLOS ) {
				return;
			}
			if ( $ahora - (int) get_option( self::OPT_MAIL_WPMAIL, 0 ) < self::REPETIR_SEG ) {
				return;
			}
			update_option( self::OPT_MAIL_WPMAIL, $ahora, false );
			$primero = (int) $recientes[0]['ts'];
			self::enviar(
				'wp_mail_fallo',
				self::ASUNTO_WPMAIL,
				'Qué pasó: WordPress no pudo enviar ' . count( $recientes ) . " mails en las últimas 6 hs (el sitio sigue andando, pero no salen bienvenidas, fin de trial ni avisos). Último error: {$mensaje}\n"
				. 'Desde cuándo: ' . gmdate( 'd/m/Y H:i', $primero ) . ' UTC (hace ' . self::hace( $primero ) . ").\n"
				. 'Qué hacer: en wp-admin → WP Mail SMTP → Herramientas → Prueba de correo. Si es la contraseña de aplicación de Gmail, crear una nueva en myaccount.google.com/apppasswords y pegarla en WP Mail SMTP. Esta alerta se repite como máximo 1 vez por día mientras sigan fallando.',
				array( 'fallos_6h' => count( $recientes ), 'ultimo_error' => $mensaje, 'desde' => gmdate( 'Y-m-d H:i:s', $primero ) )
			);
		} catch ( Throwable $e ) {
			// Accesorio: un fallo acá nunca debe afectar a quien llamó a wp_mail.
		} finally {
			self::$en_wpmail_fallo = false;
		}
	}
}
