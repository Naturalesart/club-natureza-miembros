<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Página de retorno (back_url) de las suscripciones por link directo del plan: shortcode [cn_retorno_suscripcion].
 * Mercado Pago redirige acá tras pagar con ?preapproval_id=<32 hex>. La clienta deja su celular (y nombre/email si
 * es nueva) y queda activa sola, con la MISMA lógica transaccional que "Vincular por celular" (CN_Vinculo_Manual::confirmar):
 * nunca pisa otro preapproval, rechaza uno ya usado por otra socia, es idempotente, hace backup (vinculo_backup) y
 * manda mail + Subscribe una sola vez por preapproval.
 *
 * Página pública: honeypot, nonce, rate limit por IP, no muestra datos de socias, no loguea tokens ni datos completos.
 * Apagada por defecto: opción cn_retorno_activo (Config).
 */
class CN_Retorno extends CN_Vinculo_Manual {
	const SHORTCODE  = 'cn_retorno_suscripcion';
	const NONCE_R    = 'cn_retorno';
	const MAX_ENVIOS = 5;   // envíos del formulario por IP
	const MAX_VISTAS = 20;  // consultas a MP al abrir la página, por IP
	const VENTANA    = 600; // 10 minutos
	const MAX_ENVIOS_ID  = 3;    // intentos del formulario por preapproval_id (agotados: salida por WhatsApp + pendiente)
	const OPT_WHATSAPP   = 'cn_retorno_whatsapp'; // Config: número de WhatsApp (solo dígitos, con código de país) para la salida
	const OPT_PENDIENTES = 'cn_retorno_pendientes'; // [ preapproval_id => [ fecha, motivo ] ]: SIN nombres ni celulares
	const MAX_PENDIENTES = 50;
	const MSG_REVISAR    = 'Revisá que el nombre, el celular y el email sean los de tu inscripción.';
	const MAX_ENVIOS_CEL = 5;    // envíos del formulario por celular
	const VENTANA_LARGA  = 3600; // 1 hora (topes por preapproval_id y por celular)
	const TEXTO_CONTACTO = 'Estamos confirmando tu suscripción; si en unos minutos no podés ingresar, escribinos por WhatsApp.';

	public static function registrar() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'sin_cache' ) );
	}

	/** Misma protección de caché que las páginas con [cn_login] (el formulario lleva un nonce). */
	public static function sin_cache() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}

	public static function activo() {
		return '1' === (string) get_option( 'cn_retorno_activo', '0' );
	}

	/** Cuenta un intento de esta IP; true si todavía está dentro del tope. */
	protected static function permitido( $clase, $max, $sujeto = null, $ventana = self::VENTANA ) {
		$clave = 'cn_ret_' . $clase . '_' . md5( null === $sujeto ? CN_Helpers::get_client_ip() : (string) $sujeto );
		$n     = (int) get_transient( $clave );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $clave, $n + 1, $ventana );
		return true;
	}

	protected static function caja( $texto, $tipo = 'success' ) {
		return '<div class="cn-alerta cn-alerta--' . esc_attr( $tipo ) . '">' . esc_html( $texto ) . '</div>';
	}

	protected static function envolver( $html ) {
		return '<div class="cn-wrap">' . $html . '</div>';
	}

	protected static function mensaje_contacto() {
		return self::TEXTO_CONTACTO;
	}

	/** Deja rastro sin datos personales: solo el ID de la suscripción y un motivo. */
	protected static function rastro( $tipo, $id, $extra = array() ) {
		self::registrar_log_mp( $tipo, array( 'preapproval_id' => (string) $id ) + $extra );
	}

	public static function shortcode( $atts = array() ) {
		wp_enqueue_style( 'cn-style' );
		if ( ! self::activo() ) {
			return self::envolver( self::caja( '¡Gracias por sumarte al Club Natureza! ' . self::TEXTO_CONTACTO ) );
		}
		$id_raw = isset( $_POST['cn_ret_id'] ) ? $_POST['cn_ret_id'] : ( isset( $_GET['preapproval_id'] ) ? $_GET['preapproval_id'] : '' );
		$id     = strtolower( trim( sanitize_text_field( wp_unslash( $id_raw ) ) ) );
		if ( ! self::id_valido( $id ) ) {
			return self::envolver( self::caja( 'Si acabás de suscribirte, ¡gracias! ' . self::TEXTO_CONTACTO, 'success' ) );
		}
		if ( self::agotado( $id ) ) {
			return self::agotar( $id );
		}
		if ( isset( $_POST['cn_ret_submit'] ) ) {
			return self::procesar_envio( $id );
		}
		return self::mostrar_formulario( $id );
	}

	// ------------------------------------------------------------------ intentos agotados: WhatsApp + pendientes

	/** Intentos de formulario ya hechos para esa suscripción en la última hora. */
	protected static function intentos_id( $id ) {
		return (int) get_transient( 'cn_ret_envio_id_' . md5( $id ) );
	}

	protected static function agotado( $id ) {
		return self::intentos_id( $id ) >= self::MAX_ENVIOS_ID;
	}

	/** Número de WhatsApp de Config (solo dígitos); '' si no está cargado o no parece un número. */
	protected static function whatsapp_numero() {
		$n = preg_replace( '/\D+/', '', (string) get_option( self::OPT_WHATSAPP, '' ) );
		return strlen( $n ) >= 8 ? $n : '';
	}

	/** Reintento (formulario con el aviso) o, si ya se agotaron los intentos, la salida por WhatsApp. */
	protected static function reintento( $id, $aviso ) {
		return self::agotado( $id ) ? self::agotar( $id ) : self::mostrar_formulario( $id, $aviso );
	}

	/** Pantalla de intentos agotados: sin formulario; botón de WhatsApp si hay número; deja el pendiente (una sola vez, solo si MP la confirma). */
	protected static function agotar( $id ) {
		if ( ! isset( self::pendientes()[ $id ] ) && self::permitido( 'vista', self::MAX_VISTAS ) ) {
			$mp = self::consultar( $id ); // evita que ids inventados llenen la lista: solo suscripciones reales authorized.
			if ( $mp['ok'] && $mp['confirmable'] ) {
				self::pendiente_agregar( $id, 'intentos_agotados' );
				self::rastro( 'retorno_agotado', $id );
			}
		}
		$html = self::caja( 'No pudimos activar tu acceso desde esta página. Escribinos por WhatsApp y lo resolvemos enseguida.', 'error' );
		$num  = self::whatsapp_numero();
		if ( '' !== $num ) {
			$msg   = 'Hola! Pagué mi suscripción en el Club Natureza y no puedo entrar. Código: ' . substr( $id, 0, 8 );
			$html .= '<p><a class="cn-boton" href="' . esc_url( 'https://wa.me/' . $num . '?text=' . rawurlencode( $msg ) ) . '" target="_blank" rel="noopener">Escribinos por WhatsApp</a></p>';
		} else {
			$html = self::caja( self::TEXTO_CONTACTO, 'error' );
		}
		return self::envolver( $html );
	}

	protected static function pendientes() {
		$p = get_option( self::OPT_PENDIENTES, array() );
		return is_array( $p ) ? $p : array();
	}

	protected static function pendiente_agregar( $id, $motivo ) {
		$p = self::pendientes();
		if ( isset( $p[ $id ] ) ) {
			return;
		}
		$p[ $id ] = array( 'fecha' => current_time( 'mysql', true ), 'motivo' => $motivo );
		update_option( self::OPT_PENDIENTES, array_slice( $p, -self::MAX_PENDIENTES, null, true ), false );
	}

	public static function pendiente_quitar( $id ) {
		$p = self::pendientes();
		if ( isset( $p[ $id ] ) ) {
			unset( $p[ $id ] );
			update_option( self::OPT_PENDIENTES, $p, false );
		}
	}

	/** Pendientes vigentes: saca solos los que ya quedaron vinculados a una socia (por la página, por lote o a mano). */
	public static function pendientes_vigentes() {
		global $wpdb;
		$p = self::pendientes();
		if ( ! $p ) {
			return array();
		}
		$ids = array_keys( $p );
		$sql = 'SELECT preapproval_id FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE preapproval_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ')';
		$vinc = (array) $wpdb->get_results( $wpdb->prepare( $sql, $ids ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return $p; // ante la duda no se borra nada.
		}
		foreach ( $vinc as $f ) {
			unset( $p[ (string) $f->preapproval_id ] );
		}
		if ( count( $p ) !== count( $ids ) ) {
			update_option( self::OPT_PENDIENTES, $p, false );
		}
		return $p;
	}

	/** Cartel de la pantalla Socias: vacío si no hay pendientes. */
	public static function cartel_pendientes() {
		$p = self::pendientes_vigentes();
		if ( ! $p ) {
			return '';
		}
		$n    = count( $p );
		$html = '<div class="notice notice-warning" style="padding:10px 14px;max-width:1000px;"><p><strong>' . (int) $n . ( 1 === $n ? ' clienta esperando acceso' : ' clientas esperando acceso' ) . '</strong>'
			. ' (pagaron por el link del plan y no lograron activarse en la página). Vinculá cada una a su ficha:</p><ul style="margin:0 0 6px 18px;list-style:disc;">';
		foreach ( $p as $id => $d ) {
			$url   = admin_url( 'admin.php?page=cn-socias&cn_vl_pre=' . rawurlencode( (string) $id ) . '#cn-vincular-lote' );
			$html .= '<li><code>' . esc_html( substr( (string) $id, 0, 8 ) ) . '</code> · ' . esc_html( (string) mysql2date( 'd/m/Y H:i', $d['fecha'] ) ) . ' · ' . esc_html( (string) $d['motivo'] )
				. ' · <a href="' . esc_url( $url ) . '">Vincular en lote</a></li>';
		}
		return $html . '</ul></div>';
	}

	/** GET: consulta MP y, si está autorizada y con plan conocido, muestra el formulario. */
	protected static function mostrar_formulario( $id, $aviso = '' ) {
		if ( ! self::permitido( 'vista', self::MAX_VISTAS ) ) {
			return self::envolver( self::caja( 'Hubo muchos intentos. Esperá unos minutos y volvé a abrir esta página.', 'error' ) );
		}
		$mp = self::consultar( $id ); // un error de MP ya dejó mp_api_error; no se escribe nada.
		if ( ! $mp['ok'] ) {
			return self::envolver( self::caja( 'No pudimos confirmar tu pago en este momento. Probá de nuevo en unos minutos; si sigue igual, escribinos por WhatsApp.', 'error' ) );
		}
		if ( ! $mp['confirmable'] ) {
			if ( ! get_transient( 'cn_ret_na_' . md5( $id ) ) ) { // un solo rastro cada 10 min por suscripción.
				set_transient( 'cn_ret_na_' . md5( $id ), 1, self::VENTANA );
				self::rastro( 'retorno_no_autorizado', $id, array( 'status' => mb_substr( (string) $mp['status'], 0, 30 ), 'plan_conocido' => null !== $mp['plan'] ) );
			}
			return self::envolver( self::caja( self::mensaje_contacto() ) );
		}
		ob_start();
		?>
		<form class="cn-form" method="post">
			<h2 class="cn-form__titulo">¡Tu suscripción está confirmada!</h2>
			<?php echo $aviso; // ya escapado en caja(). ?>
			<p>Dejanos tus datos para activar tu acceso al Club Natureza.</p>
			<div class="cn-campo">
				<label for="cn_ret_celular">Celular</label>
				<input type="tel" id="cn_ret_celular" name="cn_ret_celular" inputmode="numeric" required autocomplete="tel" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_POST['cn_ret_celular'] ?? '' ) ) ); ?>">
			</div>
			<div class="cn-campo">
				<label for="cn_ret_nombre">Nombre y apellido</label>
				<input type="text" id="cn_ret_nombre" name="cn_ret_nombre" required autocomplete="name" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_POST['cn_ret_nombre'] ?? '' ) ) ); ?>">
			</div>
			<div class="cn-campo">
				<label for="cn_ret_email">Email</label>
				<input type="email" id="cn_ret_email" name="cn_ret_email" autocomplete="email" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_POST['cn_ret_email'] ?? '' ) ) ); ?>">
			</div>
			<div style="position:absolute;left:-9999px;" aria-hidden="true"><label>No completar <input type="text" name="cn_ret_web" tabindex="-1" autocomplete="off"></label></div>
			<input type="hidden" name="cn_ret_id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( self::NONCE_R, 'cn_ret_nonce' ); ?>
			<button type="submit" name="cn_ret_submit" value="1" class="cn-boton">Activar mi acceso</button>
		</form>
		<?php
		return self::envolver( ob_get_clean() );
	}

	/** POST: valida, decide a qué socia corresponde (solo por celular exacto) y confirma con la lógica compartida. */
	protected static function procesar_envio( $id ) {
		if ( ! empty( $_POST['cn_ret_web'] ) ) { // honeypot: un bot lo llena. Respuesta neutra, sin tocar nada.
			return self::envolver( self::caja( self::mensaje_contacto() ) );
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cn_ret_nonce'] ?? '' ) ), self::NONCE_R ) ) {
			return self::envolver( self::caja( 'La página venció. Volvé a abrir el link que te llegó después de pagar.', 'error' ) );
		}
		if ( ! self::permitido( 'envio', self::MAX_ENVIOS ) ) {
			return self::envolver( self::caja( 'Hubo muchos intentos. Esperá unos minutos y probá de nuevo.', 'error' ) );
		}
		if ( ! self::permitido( 'envio_id', self::MAX_ENVIOS_ID, $id, self::VENTANA_LARGA ) ) { // tope por suscripción, venga de la IP que venga.
			return self::agotar( $id );
		}
		$celular = trim( sanitize_text_field( wp_unslash( $_POST['cn_ret_celular'] ?? '' ) ) );
		$nombre  = trim( sanitize_text_field( wp_unslash( $_POST['cn_ret_nombre'] ?? '' ) ) );
		$email   = sanitize_email( trim( sanitize_text_field( wp_unslash( $_POST['cn_ret_email'] ?? '' ) ) ) );
		$norm    = CN_Helpers::normalizar_celular( $celular );
		if ( empty( $norm['valido'] ) || '' === (string) $norm['normalizado'] ) {
			return self::reintento( $id, self::caja( 'El celular no es válido. Revisalo (código de área + número).', 'error' ) );
		}
		$norm = (string) $norm['normalizado'];
		if ( ! self::permitido( 'envio_cel', self::MAX_ENVIOS_CEL, $norm, self::VENTANA_LARGA ) ) { // tope por celular (hash en la clave: no se guarda el número).
			return self::envolver( self::caja( 'Hubo muchos intentos con ese celular. Esperá una hora y probá de nuevo, o escribinos por WhatsApp.', 'error' ) );
		}

		// Solo cuenta el celular EXACTO (mismo criterio que el login). Parecidos/nombre no se usan para decidir la socia.
		$exactas = array();
		$filas   = array();
		foreach ( self::candidatas( $norm, '', '' ) as $k => $c ) {
			if ( in_array( 'celular exacto', $c['motivos'], true ) ) {
				$exactas[]       = (int) $k;
				$filas[ (int) $k ] = $c['fila'];
			}
		}
		if ( count( $exactas ) > 1 ) {
			self::rastro( 'retorno_ambiguo', $id, array( 'socias' => count( $exactas ), 'motivo' => 'celular_en_varias' ) );
			self::sub_avisar_una_vez_por_hora( 'retorno_ambiguo', 'ret_' . $id, array( 'preapproval_id' => $id, 'socias' => $exactas ) );
			return self::envolver( self::caja( self::mensaje_contacto() ) );
		}
		if ( 1 === count( $exactas ) ) {
			// Contra quien tipea un celular ajeno: hace falta UN segundo factor, nombre parecido al guardado O email idéntico al
			// guardado (sin importar mayúsculas/espacios; si la ficha no tiene email, vale solo el nombre). El email solo NO alcanza:
			// sin celular exacto no hay ficha. Si no pasa ninguno: nada se escribe, mismo aviso que cualquier otro dato mal cargado
			// (no revela que el celular existe), rastro sin datos personales y SIN aviso a n8n; cuenta como intento.
			$ficha      = $filas[ $exactas[0] ];
			$mail_ficha = CN_Vinculo::email_normalizado( isset( $ficha->email ) ? $ficha->email : '' );
			$ok_nombre  = self::nombres_parecidos( $nombre, (string) $ficha->nombre_apellido );
			$ok_email   = '' !== $mail_ficha && CN_Vinculo::email_normalizado( $email ) === $mail_ficha;
			if ( ! $ok_nombre && ! $ok_email ) {
				self::rastro( 'retorno_nombre_distinto', $id, array( 'socia' => $exactas[0] ) );
				return self::reintento( $id, self::caja( self::MSG_REVISAR, 'error' ) );
			}
			// Existente: el mail de bienvenida va al email guardado de la socia (o al del pagador en MP), nunca al que tipeó
			// quien abrió la página, para no filtrar datos de la cuenta.
			$d = array( 'id' => $id, 'celular' => $celular, 'email' => '', 'nombre' => '', 'notificar' => true, 'socia' => (string) $exactas[0] );
		} else {
			if ( '' === $nombre || '' === $email || ! is_email( $email ) ) {
				return self::reintento( $id, self::caja( self::MSG_REVISAR, 'error' ) ); // mismo texto que el de arriba: no distingue ficha existente de nueva.
			}
			$d = array( 'id' => $id, 'celular' => $celular, 'email' => $email, 'nombre' => $nombre, 'notificar' => true, 'socia' => 'nueva' );
		}

		$r = self::confirmar( $d, $norm, 'retorno_vinculo' );
		if ( ! empty( $r['ok'] ) ) {
			self::pendiente_quitar( $id );
			$login = home_url( '/club-natureza-miembros/' );
			return self::envolver(
				self::caja( '¡Listo! Tu suscripción quedó activada.' ) // mismo texto para socia nueva y existente: no revela si el celular ya estaba.
				. '<p>Ingresá al área de socias con tu <strong>nombre y apellido</strong> y tu <strong>celular</strong>.</p>'
				. '<p><a class="cn-boton" href="' . esc_url( $login ) . '">Entrar al Club →</a></p>'
			);
		}
		if ( 'ambigua' === $r['motivo'] ) {
			self::rastro( 'retorno_ambiguo', $id, array( 'motivo' => 'posibles_socias' ) );
			self::sub_avisar_una_vez_por_hora( 'retorno_ambiguo', 'ret_' . $id, array( 'preapproval_id' => $id ) );
		}
		if ( 'mp' === $r['motivo'] ) {
			return self::envolver( self::caja( 'No pudimos confirmar tu pago en este momento. Probá de nuevo en unos minutos; si sigue igual, escribinos por WhatsApp.', 'error' ) );
		}
		// Conflicto / otra socia / no confirmable / error: ya quedó registrado y avisado por quien confirma; nada se escribió.
		return self::envolver( self::caja( self::mensaje_contacto() ) );
	}
}
