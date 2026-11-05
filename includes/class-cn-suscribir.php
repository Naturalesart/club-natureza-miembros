<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * FASE 2 (identidad antes del pago, en los mails): endpoint público  https://naturalesart.com/?cn_suscribir=<token>
 *
 * El botón "Suscribirme" de los mails (secuencia del trial d5–d7, win-back, upsell) deja de ir al link genérico del plan
 * de Mercado Pago (que cobra sin saber quién es) y pasa por acá con un link FIRMADO que identifica la FICHA. Con el token
 * válido se crea la suscripción personal de esa ficha —sin plan, con external_reference `sub_`, exactamente como el
 * formulario de /planes-club/ (CN_MP::crear_suscripcion_link)— y recién ahí se redirige al init_point de MP. El pago
 * vuelve con referencia, el webhook llega y el alta es automática, sin formulario.
 *
 * Token = <ficha_id>.<plan>.<origen>.<vence>.<HMAC>
 *   - plan   en lista cerrada: basico | full
 *   - origen en lista cerrada: mail_d5 | mail_d6 | mail_d7 | winback | upsell
 *   - vence  = timestamp Unix (segundos); el generador usa 30 días por defecto
 *   - HMAC   = hash_hmac( 'sha256', "<ficha_id>.<plan>.<origen>.<vence>", SECRETO ) en hex (64 caracteres, minúsculas)
 *   SECRETO = opción cn_suscribir_secreto (texto plano, compartido con n8n; wp_salt no sirve porque n8n tiene que
 *   poder firmar). Con la opción vacía el endpoint está apagado.
 *   Validación, en este orden: formato → firma (hash_equals) → vencimiento → ficha existe.
 *
 * Modo (opción cn_suscribir_modo, 'off' por defecto): off = redirige a /planes-club/ sin tocar nada (ni leer la base ni
 * MP); on = lo de arriba. No hay modo log: crear una pendiente no cobra nada.
 *
 * Reglas: token inválido/vencido → 302 a /planes-club/ (ahí está el formulario con identidad), nunca una pantalla de
 * error. Ficha con suscripción ACTIVA ya vinculada → NO se crea otra: 302 a /planes-club/ + rastro suscribir_ya_activa
 * (mp_log + cartel). Ficha sin suscripción activa → se reusa la pendiente de las últimas 24 h de ese celular+plan si la
 * hay (doble clic / dos pestañas = un solo preapproval); si no, crear_suscripcion_link con nombre/celular/email de la
 * ficha y el origen del token. Rate limit por IP (8 cada 10 min, como el formulario de planes).
 *
 * Mercado Pago: GET nunca (todo sale de la base) y POST SOLO a /preapproval vía crear_suscripcion_link (suscripción
 * pendiente sin card_token). Jamás cancela, pausa ni cobra. Cero UPDATE/DELETE en wp_cn_miembros. Ningún dato personal
 * en la URL (el token lleva el id de la ficha), en mp_log ni en el cartel.
 */
class CN_Suscribir {
	const QUERY_VAR     = 'cn_suscribir';
	const OPT_MODO      = 'cn_suscribir_modo';    // off | on
	const OPT_SECRETO   = 'cn_suscribir_secreto'; // texto plano: n8n firma con el mismo secreto
	const PLANES        = array( 'basico', 'full' );
	const ORIGENES      = array( 'mail_d5', 'mail_d6', 'mail_d7', 'winback', 'upsell' );
	const VENCE_DIAS    = 30;      // vencimiento por defecto del generador de tokens
	const MAX_INTENTOS  = 8;       // pedidos por IP…
	const VENTANA_SEG   = 600;     // …cada 10 minutos (igual que [cn_planes_form])
	const REUSO_SEG     = 86400;   // una pendiente de las últimas 24 h del mismo celular+plan se reusa
	const PAGINA_PLANES = '/planes-club/';
	const AVISO_SEG     = 86400;   // el cartel avisa cada caso (ficha) como máximo 1 vez por día

	public static function registrar() {
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'atender' ), 1 ); // antes que cualquier salida (el redirect necesita los headers abiertos).
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function modo() {
		return 'on' === (string) get_option( self::OPT_MODO, 'off' ) ? 'on' : 'off';
	}

	public static function secreto() {
		$s = get_option( self::OPT_SECRETO, '' );
		return is_string( $s ) ? trim( $s ) : '';
	}

	/** Genera y guarda un secreto nuevo (64 hex). Lo llama Config; se muestra una sola vez y hay que cargarlo en n8n. */
	public static function generar_secreto() {
		$s = bin2hex( random_bytes( 32 ) );
		update_option( self::OPT_SECRETO, $s, false );
		return $s;
	}

	public static function url_planes() {
		return home_url( self::PAGINA_PLANES );
	}

	// ---------------------------------------------------------------- token
	/** HMAC-SHA256 hex del payload con el secreto. Receta exacta para n8n: hash_hmac( 'sha256', payload, secreto ). */
	public static function firma( $payload, $secreto = null ) {
		$secreto = null === $secreto ? self::secreto() : (string) $secreto;
		return hash_hmac( 'sha256', (string) $payload, $secreto );
	}

	/**
	 * Token firmado para una ficha. '' si falta el secreto o algún argumento no está en la lista cerrada.
	 *
	 * @param int         $ficha_id id de wp_cn_miembros
	 * @param string      $plan     basico | full
	 * @param string      $origen   mail_d5 | mail_d6 | mail_d7 | winback | upsell
	 * @param int|null    $vence    timestamp; null = ahora + VENCE_DIAS días
	 * @param string|null $secreto  null = el de la opción (los tests pasan uno fijo)
	 */
	public static function crear_token( $ficha_id, $plan, $origen, $vence = null, $secreto = null ) {
		$secreto = null === $secreto ? self::secreto() : (string) $secreto;
		$id      = (int) $ficha_id;
		if ( '' === $secreto || $id <= 0 || ! in_array( $plan, self::PLANES, true ) || ! in_array( $origen, self::ORIGENES, true ) ) {
			return '';
		}
		$vence   = null === $vence ? time() + self::VENCE_DIAS * DAY_IN_SECONDS : (int) $vence;
		$payload = $id . '.' . $plan . '.' . $origen . '.' . $vence;
		return $payload . '.' . self::firma( $payload, $secreto );
	}

	/** URL completa del endpoint para un token. */
	public static function url_token( $token ) {
		return add_query_arg( array( self::QUERY_VAR => $token ), home_url( '/' ) );
	}

	/**
	 * Valida un token SIN tocar MP. Orden: formato → firma (tiempo constante) → vencimiento → ficha existe.
	 *
	 * @return array{ok:bool,motivo:string,ficha_id:int,plan:string,origen:string,vence:int,ficha:?object}
	 *         motivo: sin_secreto | formato | firma | vencido | sin_ficha | ''
	 */
	public static function verificar( $token ) {
		global $wpdb;
		$r = array( 'ok' => false, 'motivo' => 'formato', 'ficha_id' => 0, 'plan' => '', 'origen' => '', 'vence' => 0, 'ficha' => null );
		$secreto = self::secreto();
		if ( '' === $secreto ) {
			$r['motivo'] = 'sin_secreto';
			return $r;
		}
		if ( ! is_string( $token ) || strlen( $token ) > 160
			|| ! preg_match( '/^([1-9][0-9]{0,11})\.(basico|full)\.(mail_d5|mail_d6|mail_d7|winback|upsell)\.([0-9]{9,11})\.([a-f0-9]{64})\z/', $token, $m ) ) {
			return $r;
		}
		$payload = $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
		if ( ! hash_equals( self::firma( $payload, $secreto ), $m[5] ) ) {
			$r['motivo'] = 'firma';
			return $r;
		}
		$r['ficha_id'] = (int) $m[1];
		$r['plan']     = $m[2];
		$r['origen']   = $m[3];
		$r['vence']    = (int) $m[4];
		if ( $r['vence'] < time() ) {
			$r['motivo'] = 'vencido';
			return $r;
		}
		$ficha = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, nombre_apellido, estado, preapproval_id, baja_en, email, celular_texto_plano, celular_hash FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE id = %d',
			$r['ficha_id']
		) );
		if ( '' !== (string) $wpdb->last_error || ! $ficha ) {
			$r['motivo'] = 'sin_ficha';
			return $r;
		}
		$r['ok']     = true;
		$r['motivo'] = '';
		$r['ficha']  = $ficha;
		return $r;
	}

	// ---------------------------------------------------------------- endpoint
	/** template_redirect: si viene ?cn_suscribir=..., resuelve y redirige (302). Nunca muestra nada. */
	public static function atender() {
		$token = function_exists( 'get_query_var' ) ? get_query_var( self::QUERY_VAR, '' ) : '';
		if ( ! is_string( $token ) || '' === $token ) {
			$token = isset( $_GET[ self::QUERY_VAR ] ) && is_string( $_GET[ self::QUERY_VAR ] ) ? wp_unslash( $_GET[ self::QUERY_VAR ] ) : '';
		}
		if ( ! is_string( $token ) || '' === $token ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		$r = self::procesar( $token );
		wp_redirect( $r['redirect'], 302 );
		exit;
	}

	/**
	 * Resuelve un token y devuelve a dónde redirigir. Separado de atender() para poder probarlo sin redirect ni exit.
	 *
	 * @return array{redirect:string,caso:string,ficha_id:int,plan:string,origen:string}
	 *         caso: apagado | sin_secreto | rate_limit | token_<motivo> | ya_activa | sin_celular | reusada | creada | fallo
	 */
	public static function procesar( $token ) {
		global $wpdb;
		$out = array( 'redirect' => self::url_planes(), 'caso' => 'apagado', 'ficha_id' => 0, 'plan' => '', 'origen' => '' );
		if ( 'on' !== self::modo() ) {
			return $out; // apagado: cero efectos (ni base, ni MP, ni log).
		}
		if ( '' === self::secreto() ) {
			$out['caso'] = 'sin_secreto';
			return $out;
		}
		if ( ! self::permitido() ) {
			$out['caso'] = 'rate_limit';
			return $out;
		}
		$token = is_string( $token ) ? trim( $token ) : '';
		$v     = self::verificar( $token );
		if ( ! $v['ok'] ) {
			$out['caso'] = 'token_' . $v['motivo'];
			CN_Webhook::registrar_log_mp( 'suscribir_token_invalido', array( 'motivo' => $v['motivo'], 'ficha_id' => $v['ficha_id'] > 0 ? $v['ficha_id'] : null ) );
			return $out;
		}
		$ficha  = $v['ficha'];
		$id     = (int) $ficha->id;
		$plan   = $v['plan'];
		$origen = $v['origen'];
		$out['ficha_id'] = $id;
		$out['plan']     = $plan;
		$out['origen']   = $origen;
		$base = array( 'ficha_id' => $id, 'plan' => $plan, 'origen' => $origen );
		// Suscripción ACTIVA ya vinculada: el sistema nunca duplica ni cancela; Leandro decide.
		if ( self::tiene_sub_activa( $ficha ) ) {
			$out['caso'] = 'ya_activa';
			CN_Webhook::registrar_log_mp( 'suscribir_ya_activa', $base );
			self::avisar_una_vez( 'suscribir_ya_activa', $id, $base + array( 'detalle' => 'La ficha ya tiene una suscripción activa vinculada y clickeó "Suscribirme" en un mail. No se creó nada: fue a /planes-club/.' ) );
			return $out;
		}
		// Celular de la ficha (normalizado y verificado contra el hash): sin él no se puede armar la pendiente.
		$celular = self::celular_de( $ficha );
		if ( '' === $celular ) {
			$out['caso'] = 'sin_celular';
			CN_Webhook::registrar_log_mp( 'suscribir_sin_celular', $base );
			self::avisar_una_vez( 'suscribir_sin_celular', $id, $base + array( 'detalle' => 'La ficha no tiene un celular usable (celular_texto_plano vacío, inválido o que no coincide con el hash): fue a /planes-club/ a completar sus datos.' ) );
			return $out;
		}
		// Doble clic / dos pestañas / el mismo mail dos veces en el día: la misma pendiente, no otra.
		$previa = self::pendiente_reciente( $celular, $plan );
		if ( null !== $previa ) {
			$out['caso']     = 'reusada';
			$out['redirect'] = $previa;
			CN_Webhook::registrar_log_mp( 'suscribir_reusada', $base );
			return $out;
		}
		$email = isset( $ficha->email ) ? trim( (string) $ficha->email ) : '';
		$email = ( '' !== $email && is_email( $email ) ) ? $email : '';
		$meta  = array(
			'fbp'        => isset( $_COOKIE['_fbp'] ) && is_string( $_COOKIE['_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) : '',
			'fbc'        => isset( $_COOKIE['_fbc'] ) && is_string( $_COOKIE['_fbc'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) ) : '',
			'ip'         => CN_Helpers::get_client_ip(),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		);
		$res = CN_MP::crear_suscripcion_link( $plan, (string) $ficha->nombre_apellido, $celular, $email, $origen, $meta );
		if ( is_wp_error( $res ) ) {
			$out['caso'] = 'fallo';
			CN_Webhook::registrar_log_mp( 'suscribir_fallo', $base + array( 'error' => mb_substr( (string) $res->get_error_message(), 0, 200 ) ) );
			return $out; // a /planes-club/: ahí puede intentar con el formulario.
		}
		$out['caso']     = 'creada';
		$out['redirect'] = (string) $res;
		CN_Webhook::registrar_log_mp( 'suscribir_creada', $base + array( 'email' => '' !== $email ? CN_Alta_Mail::mascara_email( $email ) : '' ) );
		return $out;
	}

	// ---------------------------------------------------------------- piezas
	/** Cuenta un pedido de esta IP; false si ya pasó el tope en la ventana. */
	protected static function permitido() {
		$clave = 'cn_suscribir_ip_' . md5( CN_Helpers::get_client_ip() );
		$n     = (int) get_transient( $clave );
		if ( $n >= self::MAX_INTENTOS ) {
			return false;
		}
		set_transient( $clave, $n + 1, self::VENTANA_SEG );
		return true;
	}

	/** Suscripción activa vinculada = preapproval_id cargado, estado 'activo' y sin baja programada. */
	public static function tiene_sub_activa( $ficha ) {
		$pre  = isset( $ficha->preapproval_id ) ? trim( (string) $ficha->preapproval_id ) : '';
		$baja = isset( $ficha->baja_en ) ? trim( (string) $ficha->baja_en ) : '';
		return '' !== $pre && 'activo' === (string) ( $ficha->estado ?? '' ) && '' === $baja;
	}

	/** Celular normalizado de la ficha, solo si es válido y coincide con el hash; '' si no se puede confiar en él. */
	public static function celular_de( $ficha ) {
		$plano = isset( $ficha->celular_texto_plano ) ? (string) $ficha->celular_texto_plano : '';
		if ( '' === trim( $plano ) ) {
			return '';
		}
		$norm = CN_Helpers::normalizar_celular( $plano );
		if ( empty( $norm['valido'] ) || ! preg_match( '/^[0-9]{8,15}$/', (string) $norm['normalizado'] ) ) {
			return '';
		}
		$hash = isset( $ficha->celular_hash ) ? (string) $ficha->celular_hash : '';
		if ( '' !== $hash && ! CN_Helpers::verificar_celular( $norm['normalizado'], $hash ) ) {
			return '';
		}
		return (string) $norm['normalizado'];
	}

	/** init_point de una pendiente sin usar de las últimas 24 h para ese celular+plan (columna init_point, aditiva); null si no hay. */
	protected static function pendiente_reciente( $celular, $plan ) {
		global $wpdb;
		$desde = gmdate( 'Y-m-d H:i:s', time() - self::REUSO_SEG );
		$fila  = $wpdb->get_row( $wpdb->prepare(
			'SELECT external_reference, init_point FROM ' . CN_DB::tabla( 'suscripciones_pendientes' ) . " WHERE celular = %s AND plan = %s AND usado = 0 AND creado >= %s AND init_point IS NOT NULL AND init_point <> '' ORDER BY id DESC LIMIT 1",
			$celular,
			$plan,
			$desde
		) );
		if ( '' !== (string) $wpdb->last_error || ! $fila || empty( $fila->init_point ) ) {
			return null; // sin columna todavía (deploy a medias) o error: se crea una nueva, como siempre.
		}
		$url = trim( (string) $fila->init_point );
		return 0 === strpos( $url, 'https://' ) ? $url : null;
	}

	/** Una línea en el cartel Avisos por (motivo, ficha) y día. Sin datos personales. */
	protected static function avisar_una_vez( $motivo, $ficha_id, $detalle ) {
		$clave = 'cn_suscribir_av_' . md5( $motivo . '|' . (int) $ficha_id );
		if ( get_transient( $clave ) ) {
			return;
		}
		set_transient( $clave, 1, self::AVISO_SEG );
		CN_Webhook::avisar_error_n8n( $motivo, $detalle );
	}

	// ---------------------------------------------------------------- Config
	/** Botones de Config: generar/rotar secreto y armar un link de prueba. Devuelve lo que hay que mostrar una sola vez. */
	public static function procesar_config() {
		$out = array( 'secreto_nuevo' => null, 'link_prueba' => null );
		if ( ! current_user_can( 'manage_options' ) ) {
			return $out;
		}
		if ( isset( $_POST['cn_suscribir_generar'] ) && check_admin_referer( 'cn_suscribir_cfg', 'cn_suscribir_nonce' ) ) {
			$out['secreto_nuevo'] = self::generar_secreto();
		} elseif ( isset( $_POST['cn_suscribir_link'] ) && check_admin_referer( 'cn_suscribir_cfg', 'cn_suscribir_nonce' ) ) {
			$id  = isset( $_POST['cn_suscribir_ficha'] ) ? absint( wp_unslash( $_POST['cn_suscribir_ficha'] ) ) : 0;
			$pl  = isset( $_POST['cn_suscribir_plan'] ) ? sanitize_key( wp_unslash( $_POST['cn_suscribir_plan'] ) ) : '';
			$or  = isset( $_POST['cn_suscribir_origen'] ) ? sanitize_key( wp_unslash( $_POST['cn_suscribir_origen'] ) ) : '';
			$tok = self::crear_token( $id, $pl, $or );
			$out['link_prueba'] = '' !== $tok ? self::url_token( $tok ) : '';
		}
		return $out;
	}

	public static function bloque_config( $r = array() ) {
		$hay   = '' !== self::secreto();
		$modo  = self::modo();
		$nuevo = isset( $r['secreto_nuevo'] ) ? $r['secreto_nuevo'] : null;
		$link  = isset( $r['link_prueba'] ) ? $r['link_prueba'] : null;
		?>
		<h2>Suscribirse desde los mails (link firmado por ficha)</h2>
		<p style="max-width:820px;">Endpoint: <code><?php echo esc_html( home_url( '/?' . self::QUERY_VAR . '=' ) ); ?>&lt;token&gt;</code>. Con el token válido crea la suscripción personal de esa ficha (igual que el formulario de /planes-club/) y manda a pagar a Mercado Pago. Token inválido, vencido, ficha con suscripción activa o cualquier problema: va a /planes-club/, nunca una pantalla de error. Estado: <strong><?php echo 'on' === $modo ? 'ENCENDIDO' : 'apagado'; ?></strong> (se cambia en el formulario de arriba) · secreto: <strong><?php echo $hay ? 'cargado' : 'sin cargar (el endpoint no hace nada)'; ?></strong>.</p>
		<?php if ( $nuevo ) : ?>
			<div class="notice notice-warning inline" style="max-width:820px;padding:10px 14px;">
				<p><strong>Copiá este secreto ahora y cargalo en n8n: no se vuelve a mostrar.</strong> Los tokens firmados con el secreto anterior dejan de valer.</p>
				<input type="text" readonly class="regular-text" style="width:100%;font-family:monospace;" onclick="this.select();" value="<?php echo esc_attr( $nuevo ); ?>">
			</div>
		<?php endif; ?>
		<?php if ( null !== $link ) : ?>
			<div class="notice notice-info inline" style="max-width:820px;padding:10px 14px;">
				<?php if ( '' !== $link ) : ?>
					<p>Link de prueba (vale <?php echo (int) self::VENCE_DIAS; ?> días; lleva solo el número de ficha, el plan, el origen y el vencimiento):</p>
					<input type="text" readonly class="regular-text" style="width:100%;font-family:monospace;" onclick="this.select();" value="<?php echo esc_attr( $link ); ?>">
				<?php else : ?>
					<p>No se pudo armar el link: revisá que haya secreto, un número de ficha, un plan y un origen válidos.</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<form method="post" style="max-width:820px;">
			<?php wp_nonce_field( 'cn_suscribir_cfg', 'cn_suscribir_nonce' ); ?>
			<p><?php echo $hay ? 'Ya hay un secreto cargado. Generar uno nuevo invalida los links ya mandados (hay que cargarlo en n8n).' : 'Todavía no hay secreto.'; ?></p>
			<?php submit_button( $hay ? 'Rotar secreto' : 'Generar secreto', 'secondary', 'cn_suscribir_generar', false ); ?>
			<p style="margin-top:14px;"><strong>Armar un link de prueba:</strong>
				ficha <input type="number" name="cn_suscribir_ficha" min="1" class="small-text">
				plan <select name="cn_suscribir_plan"><?php foreach ( self::PLANES as $p ) : ?><option value="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( $p ); ?></option><?php endforeach; ?></select>
				origen <select name="cn_suscribir_origen"><?php foreach ( self::ORIGENES as $o ) : ?><option value="<?php echo esc_attr( $o ); ?>"><?php echo esc_html( $o ); ?></option><?php endforeach; ?></select>
				<?php submit_button( 'Armar link', 'secondary', 'cn_suscribir_link', false ); ?>
			</p>
			<p class="description">Receta del token (la misma para n8n): <code>&lt;ficha&gt;.&lt;plan&gt;.&lt;origen&gt;.&lt;vence&gt;</code> + <code>.</code> + HMAC-SHA256 en hex de ese texto con el secreto. Planes: basico, full. Orígenes: mail_d5, mail_d6, mail_d7, winback, upsell. Vence: timestamp Unix en segundos.</p>
		</form>
		<?php
	}
}
