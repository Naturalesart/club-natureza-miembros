<?php
/**
 * Cuentas del Club con email + contraseña (PASO B: solo el núcleo, sin pantallas ni enganches).
 *
 * Cuenta propia del Club, independiente de los usuarios de WordPress/WooCommerce (sin roles ni wp-admin).
 * Tablas nuevas y aditivas: cn_cuentas y cn_cuenta_tokens (CN_DB 2.9.0). Las fichas de wp_cn_miembros
 * NUNCA se escriben desde acá: solo se leen para decidir el acceso.
 *
 *  - La contraseña solo IDENTIFICA. El acceso lo sigue decidiendo la ficha con las mismas reglas que
 *    CN_Auth (trial vencido sin suscripción, pausado, cancelada). Si la ficha no puede entrar, no se
 *    abre sesión aunque la contraseña sea correcta. A diferencia de CN_Auth::login, acá no se marca la
 *    ficha como cancelada: de eso se siguen ocupando get_miembro_actual() y el cron, como hoy.
 *  - La sesión es la misma de siempre (cookie cn_session, CN_Auth::crear_sesion) a través de la
 *    subclase CN_Acceso_Sesion, así el resto del plugin no se entera de cómo entró la socia.
 *  - Tokens: 32 bytes aleatorios; en la base solo queda el sha256; el token real viaja solo por mail.
 *    Un solo uso, con vencimiento por propósito. Pedir uno nuevo anula los abiertos del mismo propósito;
 *    cambiar la contraseña anula todos y cierra las otras sesiones de esa socia.
 *  - Respuestas neutras: login fallido y pedidos de link responden lo mismo exista o no el email.
 *  - mp_log: solo id de cuenta/ficha, propósito, origen y email enmascarado. Nunca tokens ni contraseñas.
 *  - Nada usa WhatsApp: el contacto es responder el mail o escribir a hola@naturalesart.com.
 *
 * Flag cn_cuentas_modo = off | log | on (por defecto off). Con off, provisionar() vuelve sin leer ni
 * escribir nada. Con log solo deja en mp_log qué haría. Con on crea la cuenta y avisa por mail (el mail
 * es del PASO D: hasta que exista CN_Mail_Cuenta, solo queda un rastro sin token).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( class_exists( 'CN_Auth' ) && ! class_exists( 'CN_Acceso_Sesion' ) ) {
	/**
	 * Puente con CN_Auth: hereda SOLO para reutilizar sus funciones protegidas (crear_sesion y la regla
	 * del trial) sin cambiar CN_Auth. No pisa ningún método.
	 */
	class CN_Acceso_Sesion extends CN_Auth {
		/** Misma sesión y cookie que el login por nombre + celular. */
		public static function abrir( $miembro_id ) {
			self::crear_sesion( (int) $miembro_id );
		}

		/**
		 * ¿Esta ficha puede entrar? 'ok' | 'pausado' | 'vencido'. Mismas reglas y mismo orden que
		 * CN_Auth::login, pero sin escribir nada en la ficha.
		 */
		public static function motivo_acceso( $ficha ) {
			if ( ! $ficha ) {
				return 'vencido';
			}
			if ( self::trial_vence_el_acceso( $ficha ) ) {
				return 'vencido';
			}
			if ( 'pausado' === (string) $ficha->estado ) {
				return 'pausado';
			}
			if ( 'cancelada' === (string) $ficha->estado ) {
				return 'vencido';
			}
			return 'ok';
		}

		/** Cierra las sesiones de la socia, menos la indicada (vacío = todas). Solo toca cn_sesiones. */
		public static function revocar( $miembro_id, $excepto_token = '' ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare(
				'DELETE FROM ' . CN_DB::tabla( 'sesiones' ) . ' WHERE miembro_id = %d AND token <> %s',
				(int) $miembro_id,
				(string) $excepto_token
			) );
		}

		/** Token de la sesión actual (si la cookie tiene el formato válido), o ''. */
		public static function token_actual() {
			if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
				return '';
			}
			$t = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
			return preg_match( '/^[a-f0-9]{64}$/', $t ) ? $t : '';
		}
	}
}

class CN_Cuentas {
	const OPT_MODO  = 'cn_cuentas_modo';
	const OPT_DESDE = 'cn_cuentas_desde';
	const OPT_RELLENO = 'cn_cuentas_hash_relleno'; // hash de una contraseña al azar, para que el email inexistente tarde lo mismo.

	// Vencimientos de los tokens (segundos).
	const VENCE = array(
		'entrar'    => 900,     // 15 min
		'recuperar' => 3600,    // 60 min
		'crear'     => 604800,  // 7 días
	);

	// Topes.
	const LOGIN_MAX_FALLOS   = 5;
	const LOGIN_VENTANA      = 900;  // 15 min
	const LINK_MAX_EMAIL     = 3;
	const LINK_MAX_IP        = 10;
	const LINK_VENTANA       = 3600; // 1 hora

	// Contraseña.
	const PASS_MIN = 8;
	const PASS_MAX = 128;

	// Orígenes que pueden recibir cuenta. El lote NO (decisión de Leandro, 04/11): son socias actuales.
	const ORIGENES = array( 'trial', 'sub', 'vinculo_admin', 'vinculo_manual', 'retorno' );

	// Textos (sin WhatsApp).
	const CONTACTO           = 'respondé el mail que te mandamos o escribí a hola@naturalesart.com';
	const MSG_LOGIN_INVALIDO = 'Email o contraseña incorrectos. Revisá los datos e intentá de nuevo.';
	const MSG_DEMASIADOS     = 'Hiciste muchos intentos seguidos. Esperá un rato y probá de nuevo.';
	const MSG_LINK_NEUTRO    = 'Si ese email está registrado, en unos minutos te llega un mail con el link. Revisá también la carpeta de spam o promociones.';
	const MSG_TOKEN_INVALIDO = 'Este link ya no sirve (venció o ya se usó). Pedí uno nuevo desde la página de acceso.';

	/**
	 * Quien manda los mails. null = CN_Mail_Cuenta::enviar si existe (PASO D); si no, solo un rastro.
	 * Los tests lo reemplazan por una función que anota. Firma: function( $cuenta, $ficha, $proposito, $token ).
	 */
	public static $notificador = null;

	/* ------------------------------------------------------------------ flag */

	public static function modo() {
		$m = (string) get_option( self::OPT_MODO, 'off' );
		return in_array( $m, array( 'off', 'log', 'on' ), true ) ? $m : 'off';
	}

	/**
	 * Engancha (PASO E) update_option_cn_cuentas_modo y add_option_cn_cuentas_modo (la primera vez que se guarda
	 * la opción WordPress usa esta segunda). Solo guarda cn_cuentas_desde: no hace nada más.
	 */
	public static function registrar() {
		add_action( 'update_option_' . self::OPT_MODO, array( __CLASS__, 'al_cambiar_modo' ), 10, 2 );
		add_action( 'add_option_' . self::OPT_MODO, array( __CLASS__, 'al_crear_modo' ), 10, 2 );
	}

	/** add_option_{opción} manda ( nombre, valor ): antes no había nada, o sea off. */
	public static function al_crear_modo( $opcion, $valor ) {
		self::al_cambiar_modo( 'off', $valor );
	}

	/**
	 * Al salir de off se guarda desde cuándo corre, una sola vez. Cualquier valor anterior que no sea log u on
	 * (off, vacío, inválido) cuenta como off.
	 */
	public static function al_cambiar_modo( $anterior, $nuevo ) {
		if ( ! in_array( (string) $anterior, array( 'log', 'on' ), true ) && in_array( (string) $nuevo, array( 'log', 'on' ), true ) && ! get_option( self::OPT_DESDE ) ) {
			update_option( self::OPT_DESDE, self::ahora_ts() );
		}
	}

	/* ------------------------------------------------------------------ utilidades */

	protected static function ahora_ts() {
		return (int) strtotime( current_time( 'mysql', true ) . ' UTC' );
	}

	protected static function ahora_mysql() {
		return current_time( 'mysql', true );
	}

	/** Email en minúsculas y sin espacios; '' si no es válido. */
	public static function normalizar_email( $email ) {
		$e = strtolower( trim( (string) $email ) );
		if ( '' === $e || strlen( $e ) > 191 || ! is_email( $e ) ) {
			return '';
		}
		return $e;
	}

	/** ab***@dominio (para logs). */
	public static function mascara( $email ) {
		$email = trim( (string) $email );
		if ( '' === $email || false === strpos( $email, '@' ) ) {
			return '' === $email ? '—' : '***';
		}
		list( $u, $d ) = explode( '@', $email, 2 );
		return substr( $u, 0, 2 ) . '***@' . $d;
	}

	protected static function log( $tipo, $datos ) {
		if ( class_exists( 'CN_Webhook' ) ) {
			CN_Webhook::registrar_log_mp( $tipo, $datos );
		}
	}

	public static function ficha( $miembro_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT id, nombre_apellido, estado, fecha_fin_trial, preapproval_id, email, celular_texto_plano, fecha_alta, plan FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE id = %d',
			(int) $miembro_id
		) );
	}

	public static function cuenta_por_email( $email ) {
		global $wpdb;
		$e = self::normalizar_email( $email );
		if ( '' === $e ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CN_DB::tabla( 'cuentas' ) . ' WHERE email = %s', $e ) );
	}

	public static function cuenta_por_miembro( $miembro_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CN_DB::tabla( 'cuentas' ) . ' WHERE miembro_id = %d', (int) $miembro_id ) );
	}

	public static function cuenta_por_id( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CN_DB::tabla( 'cuentas' ) . ' WHERE id = %d', (int) $id ) );
	}

	/** Hash de relleno (se genera una vez y se guarda): verificar contra él cuesta lo mismo que contra uno real. */
	protected static function hash_relleno() {
		$h = (string) get_option( self::OPT_RELLENO, '' );
		if ( '' === $h ) {
			$h = wp_hash_password( bin2hex( random_bytes( 16 ) ) );
			update_option( self::OPT_RELLENO, $h );
		}
		return $h;
	}

	/* ------------------------------------------------------------------ topes */

	protected static function clave_tope( $clase, $sujeto ) {
		return 'cn_cta_' . $clase . '_' . md5( (string) $sujeto );
	}

	protected static function tope_lleno( $clase, $sujeto, $max ) {
		return (int) get_transient( self::clave_tope( $clase, $sujeto ) ) >= $max;
	}

	protected static function tope_sumar( $clase, $sujeto, $ventana ) {
		$k = self::clave_tope( $clase, $sujeto );
		set_transient( $k, (int) get_transient( $k ) + 1, $ventana );
	}

	/* ------------------------------------------------------------------ contraseña */

	/** '' si sirve; si no, el texto para mostrarle a la socia. */
	public static function validar_password( $pass, $email = '', $celular = '' ) {
		$pass = (string) $pass;
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $pass ) : strlen( $pass );
		if ( $len < self::PASS_MIN ) {
			return 'La contraseña tiene que tener al menos ' . self::PASS_MIN . ' caracteres.';
		}
		if ( $len > self::PASS_MAX ) {
			return 'La contraseña puede tener hasta ' . self::PASS_MAX . ' caracteres.';
		}
		$e = strtolower( trim( (string) $email ) );
		if ( '' !== $e && strtolower( trim( $pass ) ) === $e ) {
			return 'La contraseña no puede ser tu email. Elegí otra.';
		}
		$cel = (string) $celular;
		if ( '' !== trim( $cel ) ) {
			$solo = preg_replace( '/[\s\-\+\(\)\.]/', '', $pass );
			if ( '' !== $solo && ctype_digit( $solo ) ) {
				$cel_dig  = preg_replace( '/\D+/', '', $cel );
				$n_cel    = CN_Helpers::normalizar_celular( $cel );
				$n_pass   = CN_Helpers::normalizar_celular( $solo );
				$es_igual = ( $solo === $cel_dig )
					|| ( '' !== (string) $n_cel['normalizado'] && (string) $n_pass['normalizado'] === (string) $n_cel['normalizado'] );
				if ( $es_igual ) {
					return 'La contraseña no puede ser tu celular. Elegí otra.';
				}
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ tokens */

	/** Crea un token nuevo (anula los abiertos del mismo propósito). Devuelve el token real o ''. */
	public static function emitir_token( $cuenta_id, $proposito ) {
		global $wpdb;
		if ( ! isset( self::VENCE[ $proposito ] ) ) {
			return '';
		}
		$t = CN_DB::tabla( 'cuenta_tokens' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET usado = 1 WHERE cuenta_id = %d AND usado = 0 AND proposito = %s", (int) $cuenta_id, $proposito ) );
		$token = bin2hex( random_bytes( 32 ) );
		$ok    = $wpdb->insert(
			$t,
			array(
				'cuenta_id'  => (int) $cuenta_id,
				'proposito'  => $proposito,
				'token_hash' => hash( 'sha256', $token ),
				'expira'     => gmdate( 'Y-m-d H:i:s', self::ahora_ts() + self::VENCE[ $proposito ] ),
				'usado'      => 0,
				'creado'     => self::ahora_mysql(),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		return false === $ok ? '' : $token;
	}

	/**
	 * Revisa un token SIN gastarlo. array( 'ok' => bool, 'motivo' => 'ok'|'formato'|'no_existe'|'proposito'|'usado'|'vencido', 'fila' => ?, 'cuenta' => ? ).
	 */
	public static function revisar_token( $token, $proposito ) {
		global $wpdb;
		$token = strtolower( trim( (string) $token ) );
		$res   = array( 'ok' => false, 'motivo' => 'formato', 'fila' => null, 'cuenta' => null );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) || ! isset( self::VENCE[ $proposito ] ) ) {
			return $res;
		}
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CN_DB::tabla( 'cuenta_tokens' ) . ' WHERE token_hash = %s', hash( 'sha256', $token ) ) );
		if ( ! $fila ) {
			$res['motivo'] = 'no_existe';
			return $res;
		}
		if ( (string) $fila->proposito !== $proposito ) {
			$res['motivo'] = 'proposito';
			return $res;
		}
		if ( 1 === (int) $fila->usado ) {
			$res['motivo'] = 'usado';
			return $res;
		}
		if ( strtotime( (string) $fila->expira . ' UTC' ) <= self::ahora_ts() ) {
			$res['motivo'] = 'vencido';
			return $res;
		}
		$cuenta = self::cuenta_por_id( (int) $fila->cuenta_id );
		if ( ! $cuenta ) {
			$res['motivo'] = 'no_existe';
			return $res;
		}
		return array( 'ok' => true, 'motivo' => 'ok', 'fila' => $fila, 'cuenta' => $cuenta );
	}

	/** Gasta el token de forma atómica (si dos pedidos llegan juntos, solo uno gana). */
	protected static function gastar_token( $token_id ) {
		global $wpdb;
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . CN_DB::tabla( 'cuenta_tokens' ) . ' SET usado = 1 WHERE id = %d AND usado = 0', (int) $token_id ) );
		return 1 === (int) $n;
	}

	protected static function anular_tokens( $cuenta_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . CN_DB::tabla( 'cuenta_tokens' ) . ' SET usado = 1 WHERE cuenta_id = %d AND usado = 0', (int) $cuenta_id ) );
	}

	/* ------------------------------------------------------------------ resultados */

	protected static function resultado( $ok, $motivo, $mensaje = '' ) {
		return array( 'ok' => (bool) $ok, 'motivo' => $motivo, 'mensaje' => $mensaje );
	}

	/** Texto para una ficha que no puede entrar (sin WhatsApp). */
	public static function mensaje_sin_acceso( $motivo ) {
		if ( 'pausado' === $motivo ) {
			return 'Tu acceso al Club está en pausa. Si creés que es un error, ' . self::CONTACTO . '.';
		}
		return 'Tu acceso al Club no está activo en este momento. Si creés que es un error, ' . self::CONTACTO . '.';
	}

	/** Si la ficha puede entrar abre la sesión (cookie cn_session) y anota el último login. */
	protected static function entrar( $cuenta, $ficha ) {
		global $wpdb;
		$motivo = CN_Acceso_Sesion::motivo_acceso( $ficha );
		if ( 'ok' !== $motivo ) {
			return self::resultado( false, $motivo, self::mensaje_sin_acceso( $motivo ) );
		}
		CN_Acceso_Sesion::abrir( (int) $ficha->id );
		$wpdb->update(
			CN_DB::tabla( 'cuentas' ),
			array( 'ultimo_login' => self::ahora_mysql(), 'intentos' => 0 ),
			array( 'id' => (int) $cuenta->id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
		return self::resultado( true, 'ok' );
	}

	/* ------------------------------------------------------------------ login */

	/**
	 * Email + contraseña. Topes: 5 fallos / 15 min por IP y por email (exista o no). Mismo mensaje para
	 * email inexistente, cuenta sin contraseña y contraseña incorrecta.
	 */
	public static function login( $email_raw, $pass ) {
		global $wpdb;
		$ip    = CN_Helpers::get_client_ip();
		$email = self::normalizar_email( $email_raw );
		$suj   = '' !== $email ? $email : strtolower( trim( (string) $email_raw ) );
		if ( self::tope_lleno( 'login_ip', $ip, self::LOGIN_MAX_FALLOS ) || self::tope_lleno( 'login_em', $suj, self::LOGIN_MAX_FALLOS ) ) {
			return self::resultado( false, 'demasiados', self::MSG_DEMASIADOS );
		}
		$cuenta = '' !== $email ? self::cuenta_por_email( $email ) : null;
		$hash   = ( $cuenta && '' !== (string) $cuenta->pass_hash ) ? (string) $cuenta->pass_hash : self::hash_relleno();
		$coincide = wp_check_password( (string) $pass, $hash ) && $cuenta && '' !== (string) $cuenta->pass_hash;
		if ( ! $coincide ) {
			self::tope_sumar( 'login_ip', $ip, self::LOGIN_VENTANA );
			self::tope_sumar( 'login_em', $suj, self::LOGIN_VENTANA );
			if ( $cuenta ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . CN_DB::tabla( 'cuentas' ) . ' SET intentos = intentos + 1 WHERE id = %d', (int) $cuenta->id ) );
			}
			return self::resultado( false, 'invalido', self::MSG_LOGIN_INVALIDO );
		}
		delete_transient( self::clave_tope( 'login_em', $suj ) ); // el de IP no se limpia: una cuenta propia no habilita a probar otras.
		$ficha = self::ficha( (int) $cuenta->miembro_id );
		if ( ! $ficha ) {
			return self::resultado( false, 'invalido', self::MSG_LOGIN_INVALIDO );
		}
		return self::entrar( $cuenta, $ficha );
	}

	/* ------------------------------------------------------------------ links por mail */

	/**
	 * "Olvidé mi contraseña" ('recuperar') o "Entrar con un link a mi mail" ('entrar'). Siempre responde
	 * el mismo mensaje neutro; si la cuenta existe y no saltó ningún tope, emite el token y avisa.
	 */
	public static function pedir_link( $email_raw, $proposito ) {
		$neutro = self::resultado( true, 'neutro', self::MSG_LINK_NEUTRO );
		if ( ! in_array( $proposito, array( 'recuperar', 'entrar' ), true ) ) {
			return $neutro;
		}
		$ip    = CN_Helpers::get_client_ip();
		$email = self::normalizar_email( $email_raw );
		$suj   = '' !== $email ? $email : strtolower( trim( (string) $email_raw ) );
		$lleno = self::tope_lleno( 'link_ip', $ip, self::LINK_MAX_IP ) || self::tope_lleno( 'link_em', $suj, self::LINK_MAX_EMAIL );
		self::tope_sumar( 'link_ip', $ip, self::LINK_VENTANA );
		self::tope_sumar( 'link_em', $suj, self::LINK_VENTANA );
		if ( $lleno || '' === $email ) {
			return $neutro;
		}
		$cuenta = self::cuenta_por_email( $email );
		if ( ! $cuenta ) {
			return $neutro;
		}
		$token = self::emitir_token( (int) $cuenta->id, $proposito );
		if ( '' !== $token ) {
			self::notificar( $cuenta, self::ficha( (int) $cuenta->miembro_id ), $proposito, $token );
		}
		return $neutro;
	}

	/** Link de entrada (15 min, un uso). Gasta el token y, si la ficha puede, abre la sesión. */
	public static function entrar_con_link( $token ) {
		$r = self::revisar_token( $token, 'entrar' );
		if ( ! $r['ok'] || ! self::gastar_token( (int) $r['fila']->id ) ) {
			return self::resultado( false, 'token', self::MSG_TOKEN_INVALIDO );
		}
		self::log( 'cuenta_entrar_link', array( 'cuenta_id' => (int) $r['cuenta']->id ) );
		return self::entrar( $r['cuenta'], self::ficha( (int) $r['cuenta']->miembro_id ) );
	}

	/**
	 * Elegir contraseña con un token 'crear' (alta) o 'recuperar' (olvido). Si la contraseña no sirve, el
	 * token NO se gasta (puede reintentar). Al guardar: anula todos los tokens, cierra todas las sesiones
	 * de la socia y, si la ficha puede entrar, abre una nueva.
	 */
	public static function elegir_password( $token, $proposito, $pass, $pass2 ) {
		global $wpdb;
		if ( ! in_array( $proposito, array( 'crear', 'recuperar' ), true ) ) {
			return self::resultado( false, 'token', self::MSG_TOKEN_INVALIDO );
		}
		$r = self::revisar_token( $token, $proposito );
		if ( ! $r['ok'] ) {
			return self::resultado( false, 'token', self::MSG_TOKEN_INVALIDO );
		}
		$cuenta = $r['cuenta'];
		$ficha  = self::ficha( (int) $cuenta->miembro_id );
		if ( (string) $pass !== (string) $pass2 ) {
			return self::resultado( false, 'distintas', 'Las dos contraseñas no coinciden. Escribilas de nuevo.' );
		}
		$err = self::validar_password( $pass, (string) $cuenta->email, $ficha ? (string) $ficha->celular_texto_plano : '' );
		if ( '' !== $err ) {
			return self::resultado( false, 'debil', $err );
		}
		if ( ! self::gastar_token( (int) $r['fila']->id ) ) {
			return self::resultado( false, 'token', self::MSG_TOKEN_INVALIDO );
		}
		$ok = $wpdb->update(
			CN_DB::tabla( 'cuentas' ),
			array( 'pass_hash' => wp_hash_password( (string) $pass ), 'intentos' => 0 ),
			array( 'id' => (int) $cuenta->id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
		if ( false === $ok ) {
			return self::resultado( false, 'error', 'No pudimos guardar la contraseña. Probá de nuevo en unos minutos.' );
		}
		self::anular_tokens( (int) $cuenta->id );
		CN_Acceso_Sesion::revocar( (int) $cuenta->miembro_id );
		self::log( 'cuenta_password', array( 'cuenta_id' => (int) $cuenta->id, 'proposito' => $proposito ) );
		return self::entrar( $cuenta, $ficha );
	}

	/**
	 * Cambio de contraseña con la sesión abierta (sabe la actual). Anula los tokens y cierra las OTRAS
	 * sesiones; la de este dispositivo sigue.
	 */
	public static function cambiar_password( $miembro_id, $actual, $nueva, $nueva2 ) {
		global $wpdb;
		$cuenta = self::cuenta_por_miembro( (int) $miembro_id );
		if ( ! $cuenta || '' === (string) $cuenta->pass_hash || ! wp_check_password( (string) $actual, (string) $cuenta->pass_hash ) ) {
			return self::resultado( false, 'invalido', 'La contraseña actual no es correcta.' );
		}
		if ( (string) $nueva !== (string) $nueva2 ) {
			return self::resultado( false, 'distintas', 'Las dos contraseñas no coinciden. Escribilas de nuevo.' );
		}
		$ficha = self::ficha( (int) $miembro_id );
		$err   = self::validar_password( $nueva, (string) $cuenta->email, $ficha ? (string) $ficha->celular_texto_plano : '' );
		if ( '' !== $err ) {
			return self::resultado( false, 'debil', $err );
		}
		$ok = $wpdb->update( CN_DB::tabla( 'cuentas' ), array( 'pass_hash' => wp_hash_password( (string) $nueva ) ), array( 'id' => (int) $cuenta->id ), array( '%s' ), array( '%d' ) );
		if ( false === $ok ) {
			return self::resultado( false, 'error', 'No pudimos guardar la contraseña. Probá de nuevo en unos minutos.' );
		}
		self::anular_tokens( (int) $cuenta->id );
		CN_Acceso_Sesion::revocar( (int) $miembro_id, CN_Acceso_Sesion::token_actual() );
		self::log( 'cuenta_password', array( 'cuenta_id' => (int) $cuenta->id, 'proposito' => 'cambio' ) );
		return self::resultado( true, 'ok' );
	}

	/* ------------------------------------------------------------------ alta de cuentas */

	/**
	 * Le da cuenta a una ficha nueva (lo llamarán las altas en el PASO E, después del COMMIT). Idempotente:
	 * si la ficha ya tiene cuenta no crea otra ni manda otro mail. Nunca escribe en wp_cn_miembros.
	 * Devuelve array( 'accion' => off|excluido|sin_desde|sin_ficha|re_trial|sin_email|ya_tiene|email_en_uso|log|creada|error ).
	 */
	public static function provisionar( $ficha_id, $origen ) {
		$modo = self::modo();
		if ( 'off' === $modo ) {
			return array( 'accion' => 'off' ); // sin lecturas ni escrituras: idéntico a hoy.
		}
		$ficha_id = (int) $ficha_id;
		if ( $ficha_id <= 0 || ! in_array( (string) $origen, self::ORIGENES, true ) ) {
			return array( 'accion' => 'excluido' );
		}
		$desde = (int) get_option( self::OPT_DESDE, 0 );
		if ( $desde <= 0 ) {
			self::log( 'cuenta_sin_desde', array( 'miembro_id' => $ficha_id, 'origen' => $origen ) );
			return array( 'accion' => 'sin_desde' );
		}
		$ficha = self::ficha( $ficha_id );
		if ( ! $ficha ) {
			return array( 'accion' => 'sin_ficha' );
		}
		// Re-trial: una ficha que ya existía antes de prender esto es una socia actual (decisión 04/11).
		$alta_ts = strtotime( (string) $ficha->fecha_alta . ' UTC' );
		if ( 'trial' === $origen && ( false === $alta_ts || $alta_ts < $desde ) ) {
			return array( 'accion' => 're_trial' );
		}
		$email = self::normalizar_email( (string) $ficha->email );
		if ( '' === $email ) {
			self::log( 'cuenta_sin_email', array( 'miembro_id' => $ficha_id, 'origen' => $origen ) );
			return array( 'accion' => 'sin_email' );
		}
		if ( self::cuenta_por_miembro( $ficha_id ) ) {
			return array( 'accion' => 'ya_tiene' );
		}
		if ( self::email_en_uso( $email, $ficha_id ) ) {
			self::log( 'cuenta_email_en_uso', array( 'miembro_id' => $ficha_id, 'origen' => $origen, 'email' => self::mascara( $email ) ) );
			return array( 'accion' => 'email_en_uso' );
		}
		if ( 'log' === $modo ) {
			self::log( 'cuenta_provisionar_log', array( 'miembro_id' => $ficha_id, 'origen' => $origen, 'email' => self::mascara( $email ), 'haria' => 'crear_cuenta_y_mail' ) );
			return array( 'accion' => 'log' );
		}
		global $wpdb;
		$ok = $wpdb->insert(
			CN_DB::tabla( 'cuentas' ),
			array(
				'miembro_id' => $ficha_id,
				'email'      => $email,
				'pass_hash'  => null,
				'creada'     => self::ahora_mysql(),
				'intentos'   => 0,
			),
			array( '%d', '%s', '%s', '%s', '%d' )
		);
		if ( false === $ok ) {
			// Dos altas a la vez: la UNIQUE frena la segunda. Si ya quedó la de esta ficha, no es un error.
			if ( self::cuenta_por_miembro( $ficha_id ) ) {
				return array( 'accion' => 'ya_tiene' );
			}
			self::log( 'cuenta_error', array( 'miembro_id' => $ficha_id, 'origen' => $origen, 'paso' => 'insert' ) );
			return array( 'accion' => 'error' );
		}
		$cuenta = self::cuenta_por_miembro( $ficha_id );
		if ( ! $cuenta ) {
			self::log( 'cuenta_error', array( 'miembro_id' => $ficha_id, 'origen' => $origen, 'paso' => 'releer' ) );
			return array( 'accion' => 'error' );
		}
		$token = self::emitir_token( (int) $cuenta->id, 'crear' );
		self::log( 'cuenta_creada', array( 'cuenta_id' => (int) $cuenta->id, 'miembro_id' => $ficha_id, 'origen' => $origen ) );
		$mail_ok = false;
		if ( '' !== $token ) {
			$mail_ok = self::notificar( $cuenta, $ficha, 'crear', $token, (string) $origen );
		}
		return array( 'accion' => 'creada', 'cuenta_id' => (int) $cuenta->id, 'mail_ok' => $mail_ok );
	}

	/** ¿El email ya es de OTRA cuenta o de otra ficha? En ese caso no se mezcla ni se pisa nada. */
	protected static function email_en_uso( $email, $ficha_id ) {
		global $wpdb;
		$otra = self::cuenta_por_email( $email );
		if ( $otra && (int) $otra->miembro_id !== (int) $ficha_id ) {
			return true;
		}
		$fichas = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE LOWER(TRIM(email)) = %s', $email ) );
		foreach ( (array) $fichas as $id ) {
			if ( (int) $id !== (int) $ficha_id ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ marcas (recordatorios) */

	/**
	 * Marca "ya se mandó X" para una cuenta, sin columnas nuevas: una fila en cn_cuenta_tokens con un propósito de
	 * marca (rec24 / rec72), ya usada y con un hash único por cuenta y marca. La UNIQUE de token_hash hace que
	 * solo un proceso pueda tomarla: devuelve true si la tomó ahora, false si ya estaba. Nunca es un token válido.
	 */
	public static function marca_tomar( $cuenta_id, $marca ) {
		global $wpdb;
		$ahora = self::ahora_mysql();
		$ok = $wpdb->insert(
			CN_DB::tabla( 'cuenta_tokens' ),
			array( 'cuenta_id' => (int) $cuenta_id, 'proposito' => (string) $marca, 'token_hash' => self::hash_marca( $cuenta_id, $marca ), 'expira' => $ahora, 'usado' => 1, 'creado' => $ahora ),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		return false !== $ok && 0 !== $ok;
	}

	/** Suelta la marca (si el mail falló, el próximo ciclo lo reintenta). */
	public static function marca_soltar( $cuenta_id, $marca ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CN_DB::tabla( 'cuenta_tokens' ) . ' WHERE token_hash = %s AND proposito = %s', self::hash_marca( $cuenta_id, $marca ), (string) $marca ) );
	}

	public static function marca_existe( $cuenta_id, $marca ) {
		global $wpdb;
		return (bool) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CN_DB::tabla( 'cuenta_tokens' ) . ' WHERE token_hash = %s', self::hash_marca( $cuenta_id, $marca ) ) );
	}

	protected static function hash_marca( $cuenta_id, $marca ) {
		return hash( 'sha256', 'marca|' . (int) $cuenta_id . '|' . $marca );
	}

	/** Cuentas SIN contraseña creadas entre $desde_mysql (más viejas) y $hasta_mysql (más nuevas), de las más viejas a las más nuevas. */
	public static function cuentas_sin_password( $desde_mysql, $hasta_mysql, $limite = 50 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . CN_DB::tabla( 'cuentas' ) . " WHERE (pass_hash IS NULL OR pass_hash = '') AND creada > %s AND creada <= %s ORDER BY id ASC LIMIT %d",
			(string) $desde_mysql,
			(string) $hasta_mysql,
			(int) $limite
		) );
	}

	public static function hace_horas( $horas ) {
		return gmdate( 'Y-m-d H:i:s', self::ahora_ts() - (int) $horas * 3600 );
	}

	/* ------------------------------------------------------------------ enganches con las altas (PASO E) */

	/** Origen de provisionar() según el tipo de log de CN_Vinculo_Manual::confirmar. '' = no provisiona (el lote). */
	public static function origen_de_log_tipo( $log_tipo ) {
		$mapa = array( 'vinculo_manual' => 'vinculo_manual', 'retorno_vinculo' => 'retorno' );
		return isset( $mapa[ $log_tipo ] ) ? $mapa[ $log_tipo ] : '';
	}

	protected static function id_ficha_por( $columna, $valor ) {
		global $wpdb;
		if ( '' === (string) $valor ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE ' . $columna . ' = %s LIMIT 1', (string) $valor ) );
	}

	/** provisionar() sin dejar pasar ninguna excepción: un error acá nunca rompe el alta. */
	protected static function enganchar( $ficha_id, $origen, $ruta ) {
		try {
			return self::provisionar( (int) $ficha_id, $origen );
		} catch ( Throwable $e ) {
			self::log( 'cuenta_enganche_error', array( 'ruta' => $ruta, 'origen' => $origen, 'mensaje' => mb_substr( $e->getMessage(), 0, 200 ) ) );
			return array( 'accion' => 'error' );
		}
	}

	/** Trial recién dado de alta (webhook, después del mail de bienvenida). Con el modo en off no lee ni escribe nada. */
	public static function enganche_trial( $trial_payment_id ) {
		if ( 'off' === self::modo() ) {
			return null;
		}
		try {
			$id = self::id_ficha_por( 'trial_payment_id', $trial_payment_id );
		} catch ( Throwable $e ) {
			return null;
		}
		return $id > 0 ? self::enganchar( $id, 'trial', 'trial' ) : null;
	}

	/** Suscripción automática dada de alta (webhook, después de sub_post_alta). La ficha se busca por preapproval_id. */
	public static function enganche_preapproval( $preapproval_id, $origen = 'sub' ) {
		if ( 'off' === self::modo() ) {
			return null;
		}
		try {
			$id = self::id_ficha_por( 'preapproval_id', $preapproval_id );
		} catch ( Throwable $e ) {
			return null;
		}
		return $id > 0 ? self::enganchar( $id, $origen, 'preapproval' ) : null;
	}

	/** Vinculación (admin o manual/retorno) con ficha ya conocida. $origen vacío (el lote) no hace nada. */
	public static function enganche_ficha( $ficha_id, $origen ) {
		if ( 'off' === self::modo() || '' === (string) $origen || (int) $ficha_id <= 0 ) {
			return null;
		}
		return self::enganchar( (int) $ficha_id, (string) $origen, 'ficha' );
	}

	/**
	 * Guarda de los mails de bienvenida de siempre: true = "este alta ya recibe el mail de cuenta, no mandes el viejo".
	 * Solo con el modo en 'on'. Crea la cuenta y manda su mail ACÁ; devuelve true únicamente si la cuenta quedó creada
	 * y el mail salió (o si la cuenta ya existía: reintento de un aviso). Si algo falla (sin email, email en uso,
	 * re-trial, error) devuelve false y sale la bienvenida de siempre: nunca queda una socia sin acceso.
	 */
	public static function reemplaza_bienvenida( $ficha_id, $origen = 'trial' ) {
		if ( 'on' !== self::modo() ) {
			return false;
		}
		try {
			$r = self::provisionar( (int) $ficha_id, (string) $origen );
		} catch ( Throwable $e ) {
			self::log( 'cuenta_enganche_error', array( 'ruta' => 'bienvenida', 'origen' => $origen, 'mensaje' => mb_substr( $e->getMessage(), 0, 200 ) ) );
			return false;
		}
		if ( 'ya_tiene' === $r['accion'] ) {
			return true;
		}
		return 'creada' === $r['accion'] && ! empty( $r['mail_ok'] );
	}

	/**
	 * Igual, para los mails de bienvenida de siempre, que no conocen el id de la ficha: la identifican como lo hace el
	 * login (nombre + celular; el hash del celular lleva sal, así que no se busca por igualdad sino verificándolo).
	 * Si no hay exactamente una ficha, devuelve false y sale la bienvenida de siempre.
	 */
	public static function reemplaza_bienvenida_datos( $nombre, $celular, $origen = 'trial' ) {
		if ( 'on' !== self::modo() ) {
			return false; // antes de cualquier consulta: con off/log la bienvenida de siempre sale exactamente igual.
		}
		try {
			$id = self::id_ficha_por_nombre_celular( (string) $nombre, (string) $celular );
		} catch ( Throwable $e ) {
			return false;
		}
		return $id > 0 && self::reemplaza_bienvenida( $id, $origen );
	}

	/** Id de la ficha de ESE nombre y celular (misma búsqueda que CN_Auth::login); 0 si no hay o hay más de una. */
	protected static function id_ficha_por_nombre_celular( $nombre, $celular ) {
		global $wpdb;
		$norm = CN_Helpers::normalizar_celular( $celular );
		$cel  = isset( $norm['normalizado'] ) ? (string) $norm['normalizado'] : '';
		$nom  = trim( (string) $nombre );
		if ( '' === $cel || '' === $nom ) {
			return 0;
		}
		$candidatos = $wpdb->get_results( $wpdb->prepare( 'SELECT id, celular_hash FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE LOWER(TRIM(nombre_apellido)) = LOWER(TRIM(%s))', $nom ) );
		$ids = array();
		foreach ( (array) $candidatos as $c ) {
			if ( CN_Helpers::verificar_celular( $cel, (string) $c->celular_hash ) ) {
				$ids[] = (int) $c->id;
			}
		}
		return 1 === count( $ids ) ? $ids[0] : 0;
	}

	/** Avisa por mail. Nunca deja el token en logs. Un fallo acá no deshace nada. Devuelve true solo si el mail salió. */
	protected static function notificar( $cuenta, $ficha, $proposito, $token, $origen = '' ) {
		try {
			if ( is_callable( self::$notificador ) ) {
				call_user_func( self::$notificador, $cuenta, $ficha, $proposito, $token );
				return true;
			}
			if ( class_exists( 'CN_Mail_Cuenta' ) && method_exists( 'CN_Mail_Cuenta', 'enviar' ) ) {
				$plantilla = array( 'recuperar' => 'recuperar', 'entrar' => 'entrar' );
				$plantilla = isset( $plantilla[ $proposito ] ) ? $plantilla[ $proposito ] : ( 'trial' === $origen ? 'bienvenida_trial' : 'bienvenida_suscripcion' );
				return 'enviado' === CN_Mail_Cuenta::enviar( (int) $cuenta->id, $plantilla, $token );
			}
			self::log( 'cuenta_mail_sin_plantilla', array( 'cuenta_id' => (int) $cuenta->id, 'proposito' => $proposito ) );
		} catch ( Throwable $e ) {
			self::log( 'cuenta_mail_error', array( 'cuenta_id' => (int) $cuenta->id, 'proposito' => $proposito, 'mensaje' => mb_substr( $e->getMessage(), 0, 200 ) ) );
		}
		return false;
	}
}
