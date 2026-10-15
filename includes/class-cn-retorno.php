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
	const MAX_ENVIOS_ID  = 5;    // envíos del formulario por preapproval_id
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
		if ( isset( $_POST['cn_ret_submit'] ) ) {
			return self::procesar_envio( $id );
		}
		return self::mostrar_formulario( $id );
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
			return self::envolver( self::caja( 'Hubo muchos intentos con esta suscripción. Esperá una hora y probá de nuevo, o escribinos por WhatsApp.', 'error' ) );
		}
		$celular = trim( sanitize_text_field( wp_unslash( $_POST['cn_ret_celular'] ?? '' ) ) );
		$nombre  = trim( sanitize_text_field( wp_unslash( $_POST['cn_ret_nombre'] ?? '' ) ) );
		$email   = sanitize_email( trim( sanitize_text_field( wp_unslash( $_POST['cn_ret_email'] ?? '' ) ) ) );
		$norm    = CN_Helpers::normalizar_celular( $celular );
		if ( empty( $norm['valido'] ) || '' === (string) $norm['normalizado'] ) {
			return self::mostrar_formulario( $id, self::caja( 'El celular no es válido. Revisalo (código de área + número).', 'error' ) );
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
			// Contra quien tipea un celular ajeno: el nombre tiene que parecerse al guardado. Si no, no se escribe nada y la
			// respuesta es la neutra (no revela que el celular existe); se deja rastro sin datos personales y se avisa.
			if ( ! self::nombres_parecidos( $nombre, (string) $filas[ $exactas[0] ]->nombre_apellido ) ) {
				self::rastro( 'retorno_nombre_distinto', $id, array( 'socia' => $exactas[0] ) );
				self::sub_avisar_una_vez_por_hora( 'retorno_nombre_distinto', 'ret_' . $id, array( 'preapproval_id' => $id, 'socia' => $exactas[0] ) );
				return self::envolver( self::caja( self::mensaje_contacto() ) );
			}
			// Existente: el mail de bienvenida va al email guardado de la socia (o al del pagador en MP), nunca al que tipeó
			// quien abrió la página, para no filtrar datos de la cuenta.
			$d = array( 'id' => $id, 'celular' => $celular, 'email' => '', 'nombre' => '', 'notificar' => true, 'socia' => (string) $exactas[0] );
		} else {
			if ( '' === $nombre || '' === $email || ! is_email( $email ) ) {
				return self::mostrar_formulario( $id, self::caja( 'Completá tu nombre y apellido y un email válido.', 'error' ) );
			}
			$d = array( 'id' => $id, 'celular' => $celular, 'email' => $email, 'nombre' => $nombre, 'notificar' => true, 'socia' => 'nueva' );
		}

		$r = self::confirmar( $d, $norm, 'retorno_vinculo' );
		if ( ! empty( $r['ok'] ) ) {
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
