<?php
// Arnés de tests con stubs (sin WordPress, sin base, sin red). Solo se corre por línea de comandos:
//   php tests/run.php
// Si alguien lo abre por HTTP en producción (el deploy sube todo el repo), no ejecuta nada.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
// mbstring no siempre está en la máquina local; WordPress trae este respaldo en producción.
if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $s, $start, $len = null, $enc = null ) { // cuenta CARACTERES UTF-8, como el respaldo de WordPress (no bytes).
		$chars = preg_split( '//u', (string) $s, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return null === $len ? substr( (string) $s, $start ) : substr( (string) $s, $start, $len );
		}
		return implode( '', null === $len ? array_slice( $chars, $start ) : array_slice( $chars, $start, $len ) );
	}
}
if ( ! function_exists( 'mb_strlen' ) ) {
	function mb_strlen( $s, $enc = null ) {
		$n = preg_match_all( '/./us', (string) $s );
		return false === $n ? strlen( (string) $s ) : $n;
	}
}
if ( ! function_exists( 'mb_strtoupper' ) ) {
	function mb_strtoupper( $s, $enc = null ) {
		return strtoupper( strtr( (string) $s, array( 'á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú', 'ñ' => 'Ñ', 'ü' => 'Ü' ) ) );
	}
}
// ---------------------------------------------------------------- estado global de los stubs
$GLOBALS['cn_opts']       = array();
$GLOBALS['cn_transients'] = array();
$GLOBALS['cn_now']        = '2026-10-13 15:00:00'; // "ahora" fijo (UTC) para los tests.
$GLOBALS['cn_http']       = array(); // reglas: array( array( 'metodo' => 'GET', 'patron' => '#regex#', 'resp' => array|null|callable ), ... )
$GLOBALS['cn_http_log']   = array();
$GLOBALS['cn_mails']      = array();
$GLOBALS['cn_capi']       = array();
function cn_test_reset() {
	$GLOBALS['cn_opts']       = array();
	$GLOBALS['cn_transients'] = array();
	$GLOBALS['cn_http']       = array();
	$GLOBALS['cn_http_log']   = array();
	$GLOBALS['cn_mails']      = array();
	$GLOBALS['cn_mail_falla'] = false;
	$GLOBALS['cn_capi']       = array();
	$GLOBALS['cn_cron']       = array();
	$GLOBALS['cm_cron']       = array();
	$GLOBALS['cn_cron_log']   = array();
	$GLOBALS['wpdb']         = new CN_Fake_Wpdb();
}
// ---------------------------------------------------------------- funciones de WordPress
function current_time( $tipo, $gmt = 0 ) {
	return $GLOBALS['cn_now'];
}
function wp_unslash( $v ) {
	return $v;
}
function absint( $v ) {
	return abs( (int) $v );
}
function sanitize_text_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_email( $s ) {
	$s = trim( (string) $s );
	return preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $s ) ? $s : '';
}
function is_email( $s ) {
	return '' !== sanitize_email( $s );
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}
function get_option( $k, $def = false ) {
	return array_key_exists( $k, $GLOBALS['cn_opts'] ) ? $GLOBALS['cn_opts'][ $k ] : $def;
}
function update_option( $k, $v ) {
	$GLOBALS['cn_opts'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['cn_opts'][ $k ] );
	return true;
}
// Cron de WordPress (mínimo): un evento por hook. $GLOBALS['cn_cron'][ hook ] = array( 'timestamp', 'schedule' ); $GLOBALS['cm_cron'][ hook ] = schedule
// (la vista que ya usaban test_cuentas_mail / test_zcuentas_altas, que la resetean por su cuenta: por eso un evento vale solo si está en las dos).
function wp_next_scheduled( $hook ) {
	return isset( $GLOBALS['cn_cron'][ $hook ], $GLOBALS['cm_cron'][ $hook ] ) ? $GLOBALS['cn_cron'][ $hook ]['timestamp'] : false;
}
function wp_get_scheduled_event( $hook ) {
	return isset( $GLOBALS['cn_cron'][ $hook ], $GLOBALS['cm_cron'][ $hook ] ) ? (object) ( $GLOBALS['cn_cron'][ $hook ] + array( 'hook' => $hook ) ) : false;
}
function wp_schedule_event( $ts, $schedule, $hook ) {
	$GLOBALS['cn_cron'][ $hook ] = array( 'timestamp' => (int) $ts, 'schedule' => (string) $schedule );
	$GLOBALS['cm_cron'][ $hook ] = (string) $schedule;
	$GLOBALS['cn_cron_log'][]    = array( 'schedule', $hook, (string) $schedule, (int) $ts );
	return true;
}
function wp_clear_scheduled_hook( $hook ) {
	unset( $GLOBALS['cn_cron'][ $hook ], $GLOBALS['cm_cron'][ $hook ] );
	$GLOBALS['cn_cron_log'][] = array( 'clear', $hook );
	return 1;
}
function get_transient( $k ) {
	return $GLOBALS['cn_transients'][ $k ] ?? false;
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['cn_transients'][ $k ] = $v;
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['cn_transients'][ $k ] );
	return true;
}
function home_url( $p = '' ) {
	return 'https://naturalesart.com' . $p;
}
function wp_mail( $to, $asunto, $cuerpo, $headers = array() ) {
	$GLOBALS['cn_mails'][] = array( 'to' => $to, 'asunto' => $asunto, 'cuerpo' => $cuerpo, 'headers' => $headers );
	return empty( $GLOBALS['cn_mail_falla'] ); // $GLOBALS['cn_mail_falla'] = true simula que wp_mail no puede enviar (el intento igual queda registrado).
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_url( $s ) {
	return (string) $s;
}
function remove_accents( $s ) {
	return strtr( (string) $s, array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N' ) );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_textarea( $s ) {
	return htmlspecialchars( (string) $s );
}
function admin_url( $p = '' ) {
	return 'https://naturalesart.com/wp-admin/' . $p;
}
function add_query_arg( $args, $url = '' ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}
function mysql2date( $f, $d ) {
	return date( $f, strtotime( $d ) );
}
function wp_nonce_field( $a = '', $b = '' ) {
	echo '<input type="hidden" name="' . $b . '">';
}
function current_user_can( $c ) {
	return isset( $GLOBALS['cn_caps'] ) ? in_array( $c, $GLOBALS['cn_caps'], true ) : true;
}
function esc_url_raw( $s ) {
	return trim( (string) $s );
}
function add_role( $rol, $nombre, $caps = array() ) {
	$GLOBALS['cn_roles'][ $rol ] = array( 'nombre' => $nombre, 'caps' => $caps );
}
function get_role( $rol ) {
	return $GLOBALS['cn_roles'][ $rol ] ?? null;
}
function add_menu_page( $t, $m, $cap, $slug, $cb = null, $icono = '', $pos = null ) {
	$GLOBALS['cn_menus'][] = array( $slug, $cap );
}
function user_can( $u, $c ) {
	return in_array( $c, $u->caps ?? array(), true );
}
function check_admin_referer( $a = '', $b = '' ) {
	return false !== ( $GLOBALS['cn_nonce_ok'] ?? true );
}
function sanitize_textarea_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function wp_enqueue_style( $h ) {
}
function add_shortcode( $t, $cb ) {
}
function add_action( $h, $cb ) {
	$GLOBALS['cn_hooks'][ $h ][] = $cb;
}
function wp_verify_nonce( $n, $a ) {
	return false !== ( $GLOBALS['cn_nonce_ok'] ?? true );
}
function get_current_user_id() {
	return 1;
}
function wp_get_current_user() {
	return (object) array( 'user_login' => 'admin' );
}
function sanitize_key( $s ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
}
function is_wp_error( $x ) {
	return $x instanceof WP_Error;
}
class WP_Error {
	public $msg;
	public $data;
	public function __construct( $code = '', $msg = '', $data = null ) {
		$this->msg  = $msg;
		$this->data = $data;
	}
	public function get_error_message() {
		return $this->msg;
	}
	public function get_error_data() {
		return $this->data;
	}
}
function wp_remote_retrieve_response_code( $r ) {
	return $r['response']['code'];
}
function wp_remote_retrieve_body( $r ) {
	return $r['body'];
}
// Contraseñas (WordPress real usa bcrypt/phpass; acá alcanza con password_hash).
function wp_hash_password( $p ) {
	return password_hash( (string) $p, PASSWORD_BCRYPT, array( 'cost' => 4 ) );
}
function wp_check_password( $p, $h, $uid = '' ) {
	return password_verify( (string) $p, (string) $h );
}
function wp_salt( $s = 'auth' ) {
	return 'sal-de-prueba-' . $s;
}
function get_permalink( $id = 0 ) { // acepta ID u objeto post; los tests del panel fijan $GLOBALS['cn_permalink'].
	if ( isset( $GLOBALS['cn_permalink'] ) ) {
		return $GLOBALS['cn_permalink'];
	}
	if ( is_object( $id ) ) {
		$id = isset( $id->ID ) ? $id->ID : 0;
	}
	return 'https://naturalesart.com/?p=' . (int) $id;
}
function is_ssl() { // los tests lo prenden con $GLOBALS['cn_ssl'] = true (por defecto: HTTP).
	return ! empty( $GLOBALS['cn_ssl'] );
}
function nocache_headers() {
	$GLOBALS['cn_nocache'] = ( $GLOBALS['cn_nocache'] ?? 0 ) + 1;
}
function wp_remote_retrieve_header( $r, $h ) {
	return $r['headers'][ strtolower( $h ) ] ?? '';
}
/**
 * Router HTTP falso. Cada regla: metodo, patron (regex sobre la URL), resp:
 *  - array  -> 200 con ese JSON
 *  - null   -> falla de red (WP_Error)
 *  - int    -> ese código HTTP con cuerpo {}
 *  - callable( $url, $args ) -> cualquiera de los anteriores
 * Sin regla que matchee: WP_Error (como si MP no respondiera).
 */
function cn_http_request( $metodo, $url, $args ) {
	$GLOBALS['cn_http_log'][] = array( 'metodo' => $metodo, 'url' => $url, 'body' => $args['body'] ?? null );
	foreach ( $GLOBALS['cn_http'] as $regla ) {
		if ( $regla['metodo'] === $metodo && preg_match( $regla['patron'], $url ) ) {
			$resp = is_callable( $regla['resp'] ) ? call_user_func( $regla['resp'], $url, $args ) : $regla['resp'];
			if ( null === $resp ) {
				return new WP_Error( 'http', 'sin respuesta' );
			}
			if ( is_int( $resp ) ) {
				return array( 'response' => array( 'code' => $resp ), 'body' => '{}' );
			}
			if ( isset( $resp['__status'] ) ) { // respuesta con código y encabezados propios: array( '__status' => 429, '__headers' => array( 'retry-after' => '7' ), '__body' => array )
				return array( 'response' => array( 'code' => $resp['__status'] ), 'headers' => $resp['__headers'] ?? array(), 'body' => json_encode( $resp['__body'] ?? new stdClass() ) );
			}
			return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $resp ) );
		}
	}
	return new WP_Error( 'http', 'sin regla' );
}
function wp_remote_get( $url, $args = array() ) {
	return cn_http_request( 'GET', $url, $args );
}
function wp_remote_post( $url, $args = array() ) {
	return cn_http_request( 'POST', $url, $args );
}
/** Helpers de test para el router. */
function cn_http_on( $metodo, $patron, $resp ) {
	array_unshift( $GLOBALS['cn_http'], array( 'metodo' => $metodo, 'patron' => $patron, 'resp' => $resp ) );
}
function cn_http_calls( $patron ) {
	return array_values( array_filter( $GLOBALS['cn_http_log'], function ( $c ) use ( $patron ) {
		return preg_match( $patron, $c['url'] );
	} ) );
}
// Stubs del panel de contenido (punto 12).
$GLOBALS['cn_logged'] = true;
function is_user_logged_in() {
	return false !== ( $GLOBALS['cn_logged'] ?? true );
}
function is_singular() {
	return true;
}
function get_post() { // cada test fija su pagina: cn_post (panel) o ca_post (cuentas/acceso); por defecto, la del panel.
	if ( isset( $GLOBALS['cn_post'] ) ) {
		return $GLOBALS['cn_post'];
	}
	if ( array_key_exists( 'ca_post', $GLOBALS ) ) {
		return $GLOBALS['ca_post'];
	}
	return (object) array( 'ID' => 50, 'post_content' => '[cn_panel_contenido]' );
}
function has_shortcode( $contenido, $tag ) { // como WordPress: [tag], [tag attr] o [tag/], no [tag_otro].
	return (bool) preg_match( '/\[' . preg_quote( (string) $tag, '/' ) . '[\s\]\/]/', (string) $contenido );
}
function wp_login_form( $a = array() ) {
	echo '<form id="loginform" data-redirect="' . htmlspecialchars( (string) ( $a['redirect'] ?? '' ) ) . '" data-remember="' . ( empty( $a['value_remember'] ) ? '0' : '1' ) . '"></form>';
}
function wp_lostpassword_url( $r = '' ) {
	return 'https://naturalesart.com/wp-login.php?action=lostpassword';
}
function wp_logout_url( $r = '' ) {
	return 'https://naturalesart.com/wp-login.php?action=logout';
}
function wp_safe_redirect( $u ) {
	$GLOBALS['cn_redirect'] = $u;
}
function add_filter( $h, $cb, $p = 10, $n = 1 ) {
}
// ---------------------------------------------------------------- $wpdb falso
class CN_Fake_Wpdb {
	public $prefix      = 'wp_';
	public $posts       = 'wp_posts';
	public $last_error  = '';
	public $insert_id   = 0;
	public $log         = array(); // escrituras y transacciones, en orden
	public $consultas   = array(); // todo SQL que pasó por get_*
	protected $handlers = array();
	/** Registra una respuesta para consultas get_row/get_results/get_var cuyo SQL matchee el regex. */
	public function on( $regex, $respuesta ) {
		array_unshift( $this->handlers, array( $regex, $respuesta ) );
	}
	protected function resolver( $sql ) {
		$this->consultas[] = $sql;
		foreach ( $this->handlers as $h ) {
			if ( preg_match( $h[0], $sql ) ) {
				return is_callable( $h[1] ) ? call_user_func( $h[1], $sql ) : $h[1];
			}
		}
		return null;
	}
	public function esc_like( $t ) {
		return addcslashes( (string) $t, '_%\\' );
	}
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $sql );
	}
	public function get_row( $sql ) {
		$r = $this->resolver( $sql );
		return is_array( $r ) && isset( $r[0] ) && is_object( $r[0] ) ? $r[0] : ( is_object( $r ) ? $r : null );
	}
	public function get_results( $sql ) {
		$r = $this->resolver( $sql );
		return is_array( $r ) ? $r : array();
	}
	public function get_col( $sql ) {
		$r = $this->resolver( $sql );
		return is_array( $r ) ? $r : array();
	}
	public function get_var( $sql ) {
		$r = $this->resolver( $sql );
		return is_scalar( $r ) ? $r : null;
	}
	public function query( $sql ) {
		$this->log[] = array( 'q', $sql );
		return 1;
	}
	public function update( $tabla, $data, $where, $f = null, $wf = null ) {
		$this->log[] = array( 'update', $tabla, $data, $where );
		return 1;
	}
	public function insert( $tabla, $data, $f = null ) {
		$this->log[] = array( 'insert', $tabla, $data );
		if ( ! empty( $GLOBALS['cn_insert_falla'] ) ) { // los tests simulan un INSERT que falla: wpdb devuelve false.
			$this->last_error = 'insert simulado que falla';
			return false;
		}
		$this->insert_id = 999;
		return 1;
	}
	public function delete( $tabla, $where, $f = null ) {
		$this->log[] = array( 'delete', $tabla, $where );
		return 1;
	}
	/** Escrituras a una tabla (sin contar el log de mp_log). */
	public function escrituras( $contiene = 'miembros' ) {
		return array_values( array_filter( $this->log, function ( $l ) use ( $contiene ) {
			return in_array( $l[0], array( 'update', 'insert', 'delete' ), true ) && false !== strpos( $l[1], $contiene );
		} ) );
	}
	public function transaccion() {
		$t = array();
		foreach ( $this->log as $l ) {
			if ( 'q' === $l[0] ) {
				$t[] = $l[1];
			}
		}
		return $t;
	}
	public function log_mp_tipos() {
		$t = array();
		foreach ( $this->log as $l ) {
			if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) ) {
				$t[] = $l[2]['tipo'];
			}
		}
		return $t;
	}
}
// ---------------------------------------------------------------- clases con efectos externos, sustituidas
class CN_Meta_Capi {
	public static function enviar_subscribe() {
		$GLOBALS['cn_capi'][] = func_get_args();
	}
}
// ---------------------------------------------------------------- código real a probar
$cn_raiz = dirname( __DIR__ ) . '/';
require_once $cn_raiz . 'includes/class-cn-helpers.php';
require_once $cn_raiz . 'includes/class-cn-db.php';
require_once $cn_raiz . 'includes/class-cn-mp.php';
require_once $cn_raiz . 'includes/class-cn-webhook.php';
require_once $cn_raiz . 'includes/class-cn-alertas.php';
require_once $cn_raiz . 'includes/class-cn-conciliacion.php';
require_once $cn_raiz . 'includes/class-cn-vinculo.php';
require_once $cn_raiz . 'includes/class-cn-vinculo-manual.php';
require_once $cn_raiz . 'includes/class-cn-retorno.php';
require_once $cn_raiz . 'includes/class-cn-alta-mail.php';
if ( file_exists( $cn_raiz . 'includes/class-cn-suscribir.php' ) ) {
	require_once $cn_raiz . 'includes/class-cn-suscribir.php';
}
require_once $cn_raiz . 'includes/class-cn-mail-carta.php';
require_once $cn_raiz . 'includes/class-cn-vinculo-lote.php';
require_once $cn_raiz . 'includes/class-cn-para-contactar.php';
require_once $cn_raiz . 'includes/class-cn-espejo-mp.php';
require_once $cn_raiz . 'includes/class-cn-espejo-auditoria.php';
if ( file_exists( $cn_raiz . 'includes/class-cn-sync.php' ) ) {
	require_once $cn_raiz . 'includes/class-cn-sync.php';
}
if ( file_exists( $cn_raiz . 'includes/class-cn-autovincular.php' ) ) {
	require_once $cn_raiz . 'includes/class-cn-autovincular.php';
}
require_once $cn_raiz . 'includes/class-cn-auth.php';
if ( ! getenv( 'CN_TEST_CLASE_VIEJA' ) && file_exists( $cn_raiz . 'includes/class-cn-cuentas.php' ) ) { // CN_TEST_CLASE_VIEJA: los tests lanzan un proceso aparte que simula el deploy a medias (clase vieja sin los métodos nuevos).
	require_once $cn_raiz . 'includes/class-cn-cuentas.php';
}
if ( ! getenv( 'CN_TEST_CLASE_VIEJA' ) && file_exists( $cn_raiz . 'includes/class-cn-acceso.php' ) ) {
	require_once $cn_raiz . 'includes/class-cn-acceso.php';
}
if ( ! getenv( 'CN_TEST_CLASE_VIEJA' ) && file_exists( $cn_raiz . 'includes/class-cn-mail-cuenta.php' ) ) {
	require_once $cn_raiz . 'includes/class-cn-mail-cuenta.php';
}
require_once $cn_raiz . 'includes/class-cn-admin.php';
require_once $cn_raiz . 'includes/class-cn-colaboradora.php';
require_once $cn_raiz . 'includes/class-cn-diag-pagador.php';
require_once $cn_raiz . 'includes/class-cn-socias-revisar.php';
require_once $cn_raiz . 'includes/class-cn-sin-vincular.php';
require_once $cn_raiz . 'includes/class-cn-panel-api.php';
require_once $cn_raiz . 'includes/class-cn-reemplazo.php';
require_once $cn_raiz . 'includes/class-cn-reemplazo-auto.php';
require_once $cn_raiz . 'includes/class-cn-extender-acceso.php';
require_once $cn_raiz . 'includes/class-cn-corregir-vinculo.php';
require_once $cn_raiz . 'includes/class-cn-drive.php';
require_once $cn_raiz . 'includes/class-cn-panel-contenido.php';
CN_MP::$dormir = function () { // los tests nunca esperan de verdad (el 429 del sync pide esperas de segundos).
};
// ---------------------------------------------------------------- mini framework de tests
$GLOBALS['cn_t_ok']   = 0;
$GLOBALS['cn_t_fail'] = array();
function t( $nombre, $cond, $detalle = '' ) {
	if ( $cond ) {
		$GLOBALS['cn_t_ok']++;
		return;
	}
	$GLOBALS['cn_t_fail'][] = $nombre . ( '' !== $detalle ? ' — ' . $detalle : '' );
	echo "  FALLA: {$nombre}" . ( '' !== $detalle ? " ({$detalle})" : '' ) . "\n";
}
function seccion( $titulo ) {
	echo "\n== {$titulo}\n";
}
/** Llama a un método protected/private de CN_Webhook u otra clase. */
function cn_llamar( $clase, $metodo, ...$args ) {
	$m = new ReflectionMethod( $clase, $metodo );
	return $m->invoke( null, ...$args );
}
function cn_fila( $datos ) {
	return (object) $datos;
}
