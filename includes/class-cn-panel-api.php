<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Endpoint REST de SOLO LECTURA para el panel de socias (n8n): GET /wp-json/cn/v1/panel-datos.
 *
 * - Apagado por defecto (opción cn_panel_api_activo = '0'): con el flag apagado la ruta ni siquiera se registra (404 nativo de WordPress).
 * - Solo HTTPS. Auth: encabezado X-CN-Panel-Token comparado con hash_equals contra el sha256 guardado en cn_panel_token_hash.
 *   Falta → 401, incorrecto → 403. Nunca se acepta por query string y nunca se loguea ni se guarda en mp_log.
 * - Rate limit: 30 pedidos por minuto (transient), solo para pedidos con token válido (un tercero sin token no puede dejar sin cupo a n8n).
 * - Solo SELECT. Únicas escrituras de toda la clase: el transient del rate limit y la opción del token (botón "Generar/rotar token" de Config).
 *   Todo lo demás reutiliza lo que ya existe (CN_Webhook::listar_avisos, CN_Retorno::OPT_PENDIENTES, el caché de CN_Sin_Vincular, CN_Alta_Mail::modo,
 *   CN_Espejo_MP::modo, las opciones del sync). No consulta Mercado Pago, no usa los métodos que escriben (p. ej. pendientes_vigentes y
 *   CN_Sin_Vincular::consultar guardan opciones/transients) y no agrega tracking nuevo: lo que no existe en la base va en null.
 * - Campos extendidos (opción cn_panel_uso_activo, apagada por defecto): email completo de la ficha y bloque `uso` por socia (último login
 *   aproximado, contenidos vistos, última actividad). Solo SELECT; con el flag apagado la respuesta no cambia.
 * - Trials vencidos (opción cn_panel_incluir_trials_vencidos, apagada por defecto): suma al final de `socias` las fichas canceladas SIN
 *   suscripción que vinieron de un trial pagado (trial_payment_id) y cuyo trial venció hace 14 días o menos, con el campo extra
 *   "trial_vencido": true. Sirve para que la secuencia del trial (d8/d10) las vea; hoy las cancela el login al vencer y desaparecían
 *   del panel ("SIN FICHA"). Solo SELECT; con el flag apagado la respuesta es idéntica a la de antes. Rollback: apagar la opción.
 *   Guarda (solo en esas fichas): `guarda_mp` (última decisión trial_guarda_decision que dejó el cron en mp_log), `sub_sin_vincular`
 *   (alguna suscripción authorized sin vincular de los últimos 7 días apunta a esta ficha o a su email) y `apta_winback`
 *   = guarda_mp 'sin_pago' Y sin suscripción sin vincular. Sin decisión registrada => false: ante la duda no se le escribe.
 * - Anti-caché: todas las respuestas (200 y errores) llevan Cache-Control: no-store y X-LiteSpeed-Cache-Control: no-cache. Sin el segundo, LiteSpeed
 *   (con "Cache REST API" activo) guardaba la respuesta autenticada y la servía a pedidos sin token (incidente del 28/10/2026).
 * - Bloque `cartel` (métricas del cartel de suscripción de la portada, CN_Cartel_Trial / PR #44): SOLO NÚMEROS, nunca datos de fichas.
 *   modo (off|log|on), por_dia (el conteo de cn_cartel_trial_conteo tal cual, últimos 30 días), clics (pendientes con origen
 *   club_cartel_full / club_cartel_club: total, por origen y por día) y altas (las mismas con usado = 1, que marca el webhook al autorizar:
 *   total, por plan y por día). Días en UTC; el día de clics y altas es el del clic (columna `creado`): la pendiente no guarda la fecha
 *   del alta. Una sola consulta agregada (COUNT ... GROUP BY) a suscripciones_pendientes; no devuelve filas.
 * - Sin migraciones. Quitarlo = borrar el require_once del bootstrap y este archivo.
 */
class CN_Panel_API {
	const OPT_ACTIVO     = 'cn_panel_api_activo';
	const OPT_TOKEN_HASH = 'cn_panel_token_hash';
	const OPT_USO        = 'cn_panel_uso_activo'; // '0' por defecto: con el flag apagado la respuesta es idéntica a la de antes (sin email completo ni uso).
	const OPT_TRIALS_VENCIDOS = 'cn_panel_incluir_trials_vencidos'; // '0' por defecto: con el flag apagado no se consulta ni se agrega nada.
	const DIAS_TRIALS_VENCIDOS = 14;  // solo trials vencidos hace 14 días o menos (d8 y d10 caen adentro; el panel no crece sin límite).
	const MAX_TRIALS_VENCIDOS  = 200; // tope propio, aparte de MAX_SOCIAS.
	const MAX_SOCIAS     = 600;
	const MAX_POR_MIN    = 30;
	const MAX_SIN_FICHA  = 50;
	const MAX_ESPERANDO  = 10;
	const VENTANA_AVISOS = 604800; // 7 días: lo mismo que poda el sync en cn_sync_alertados.
	const NONCE          = 'cn_panel_token';
	const CARTEL_ORIGENES = array( 'club_cartel_full', 'club_cartel_club' ); // los de CN_Cartel_Trial (no se depende de esa clase: deploy a medias)
	const CARTEL_PLANES   = array( 'full', 'basico' );
	const CARTEL_OPT_MODO = 'cn_cartel_trial_modo';
	const CARTEL_OPT_CONTEO = 'cn_cartel_trial_conteo';
	const CARTEL_DIAS     = 30;
	const CARTEL_MOTIVOS  = array( 'mostrado', 'se_mostraria', 'sin_email', 'sin_celular', 'suscribir_off', 'sin_secreto', 'sin_token', 'con_cache' ); // lista cerrada: nada más sale

	public static function registrar() {
		add_action( 'rest_api_init', array( __CLASS__, 'registrar_rutas' ) );
	}

	public static function activo() {
		return '1' === (string) get_option( self::OPT_ACTIVO, '0' );
	}

	/** Campos extendidos de solo lectura (email completo y uso del área de socias). Apagado por defecto. */
	public static function uso_activo() {
		return '1' === (string) get_option( self::OPT_USO, '0' );
	}

	/** Trials vencidos y cancelados sin suscripción en `socias` (con trial_vencido: true). Apagado por defecto. */
	public static function trials_vencidos_activo() {
		return '1' === (string) get_option( self::OPT_TRIALS_VENCIDOS, '0' );
	}

	/** Con el flag apagado no se registra nada: la ruta no existe (404 de WordPress). */
	public static function registrar_rutas() {
		if ( ! self::activo() ) {
			return;
		}
		register_rest_route( 'cn/v1', '/panel-datos', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'manejar' ),
			'permission_callback' => '__return_true', // la autenticación (401/403) se resuelve en manejar() para controlar el código HTTP.
		) );
	}

	// ---------------------------------------------------------------- pedido
	protected static function responder( $datos, $status = 200, $extra = array() ) {
		$r = new WP_REST_Response( $datos, $status );
		$r->header( 'Cache-Control', 'no-store' );
		$r->header( 'X-LiteSpeed-Cache-Control', 'no-cache' ); // LiteSpeed ignora Cache-Control en la REST API: sin esto guarda la respuesta y se la sirve a cualquiera sin token.
		foreach ( $extra as $k => $v ) {
			$r->header( $k, $v );
		}
		return $r;
	}

	protected static function error( $codigo, $mensaje, $status, $extra = array() ) {
		return self::responder( array( 'code' => $codigo, 'message' => $mensaje, 'data' => array( 'status' => $status ) ), $status, $extra );
	}

	public static function manejar( $request ) {
		if ( ! self::activo() ) {
			return self::error( 'rest_no_route', 'No route was found matching the URL and request method.', 404 );
		}
		if ( ! is_ssl() ) {
			return self::error( 'https_requerido', 'Solo HTTPS.', 403 );
		}
		$token = trim( (string) $request->get_header( 'x_cn_panel_token' ) ); // solo el encabezado; el query string no se mira.
		if ( '' === $token ) {
			return self::error( 'token_faltante', 'Falta el encabezado X-CN-Panel-Token.', 401 );
		}
		$hash = (string) get_option( self::OPT_TOKEN_HASH, '' );
		if ( '' === $hash || ! hash_equals( $hash, hash( 'sha256', $token ) ) ) {
			return self::error( 'token_invalido', 'Token inválido.', 403 );
		}
		if ( ! self::permitir() ) {
			return self::error( 'demasiados_pedidos', 'Máximo ' . self::MAX_POR_MIN . ' pedidos por minuto.', 429, array( 'Retry-After' => '60' ) );
		}
		try {
			return self::responder( self::datos() );
		} catch ( Throwable $e ) {
			return self::error( 'error_interno', 'No se pudo armar la respuesta.', 500 ); // sin detalle: nada que filtrar, y nada se loguea.
		}
	}

	/** Ventana fija de 1 minuto en un transient. */
	protected static function permitir() {
		$clave = 'cn_panel_rl_' . (int) floor( time() / 60 );
		$n     = (int) get_transient( $clave );
		if ( $n >= self::MAX_POR_MIN ) {
			return false;
		}
		set_transient( $clave, $n + 1, 120 );
		return true;
	}

	// ---------------------------------------------------------------- respuesta
	public static function datos() {
		$ahora = time();
		$s     = self::socias( $ahora );
		$out   = array(
			'generado_en'      => gmdate( 'Y-m-d\TH:i:s\Z', $ahora ),
			'extendido'        => self::uso_activo(),
			'socias'           => $s['lista'],
			'socias_truncadas' => $s['truncadas'],
			'alertas'          => self::alertas( $ahora ),
			'config'           => self::config(),
			'sync'             => self::sync( $ahora ),
			'cartel'           => self::cartel( $ahora ),
		);
		if ( null !== $s['vencidos'] ) { // solo con el flag prendido: con el flag apagado el JSON no cambia ni en una clave.
			$out['trials_vencidos'] = $s['vencidos'];
		}
		return $out;
	}

	protected static function iso( $mysql_utc ) {
		$m = trim( (string) $mysql_utc );
		if ( '' === $m || 0 === strpos( $m, '0000-00-00' ) ) {
			return null;
		}
		$ts = strtotime( $m . ' UTC' );
		return false === $ts ? null : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}

	/** j***@gmail.com */
	public static function email_enmascarado( $email ) {
		$email = trim( (string) $email );
		$p     = strpos( $email, '@' );
		if ( false === $p || $p < 1 || $p === strlen( $email ) - 1 ) {
			return null;
		}
		return substr( $email, 0, 1 ) . '***' . substr( $email, $p );
	}

	/** Mismos criterios que el listado del admin (Suscripta / Trial / Manual / Inactiva). */
	public static function tipo( $f ) {
		if ( 'activo' !== (string) $f->estado ) {
			return 'inactiva';
		}
		if ( '' !== (string) $f->preapproval_id ) {
			return 'suscripta';
		}
		return ! empty( $f->fecha_fin_trial ) ? 'trial' : 'manual';
	}

	/** Dígitos del celular solo si el texto plano guardado es un celular válido; si no, null. */
	public static function celular( $texto_plano ) {
		$t = trim( (string) $texto_plano );
		if ( '' === $t ) {
			return null;
		}
		$n = CN_Helpers::normalizar_celular( $t );
		return $n['valido'] ? $n['normalizado'] : null;
	}

	/**
	 * Fichas de trial vencido: canceladas, sin suscripción (NULL o ''), con trial_payment_id y fin de trial dentro de los últimos
	 * DIAS_TRIALS_VENCIDOS días. Son disjuntas con la consulta principal (que no trae canceladas sin suscripción). Solo SELECT.
	 */
	protected static function filas_trials_vencidos( $ahora ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, nombre_apellido, estado, preapproval_id, plan, acceso_hasta, baja_en, fecha_alta, fecha_fin_trial, email, celular_texto_plano
			 FROM ' . CN_DB::tabla( 'miembros' ) . "
			 WHERE estado = 'cancelada'
			   AND ( preapproval_id IS NULL OR preapproval_id = '' )
			   AND trial_payment_id IS NOT NULL AND trial_payment_id <> ''
			   AND fecha_fin_trial IS NOT NULL AND fecha_fin_trial >= %s AND fecha_fin_trial <= %s
			 ORDER BY id ASC LIMIT %d",
			gmdate( 'Y-m-d H:i:s', $ahora - self::DIAS_TRIALS_VENCIDOS * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', $ahora ),
			self::MAX_TRIALS_VENCIDOS + 1
		) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Guarda de las fichas de trial vencido, con DOS consultas a mp_log (solo SELECT):
	 *  - trial_guarda_decision: la última decisión del cron para cada ficha (pago / sin_pago / indeterminado / error / sin_email).
	 *    El cron no mira las canceladas, así que la decisión es la de antes de cancelarse; si no hay, queda null.
	 *  - aviso sub_sin_vincular (últimos 7 días, menos las que hoy ya tienen ficha): si un item apunta a la ficha (socia_id o candidatas)
	 *    o trae el mismo email, puede que ya se haya suscripto por otro camino.
	 * apta_winback = 'sin_pago' y ningún aviso que la apunte. Ante cualquier duda (sin decisión, MP falló, aviso) => false.
	 *
	 * @param array $fichas filas de miembros (id, email)
	 * @return array<int,array{guarda_mp:?string,guarda_mp_fecha:?string,sub_sin_vincular:bool,apta_winback:bool}>
	 */
	protected static function guarda_trials_vencidos( $fichas, $ahora ) {
		global $wpdb;
		$out    = array();
		$emails = array(); // email normalizado => ids
		foreach ( $fichas as $f ) {
			$id         = (int) $f->id;
			$out[ $id ] = array( 'guarda_mp' => null, 'guarda_mp_fecha' => null, 'sub_sin_vincular' => false, 'apta_winback' => false );
			$m          = strtolower( trim( (string) $f->email ) );
			if ( '' !== $m && false !== strpos( $m, '@' ) ) {
				$emails[ $m ][] = $id;
			}
		}
		if ( ! $out ) {
			return $out;
		}
		$t    = CN_DB::tabla( 'mp_log' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT payload, fecha FROM {$t} WHERE tipo = %s AND fecha >= %s ORDER BY id DESC LIMIT %d",
			'trial_guarda_decision',
			gmdate( 'Y-m-d H:i:s', $ahora - ( self::DIAS_TRIALS_VENCIDOS + 14 ) * DAY_IN_SECONDS ),
			5000
		) );
		foreach ( is_array( $rows ) ? $rows : array() as $r ) { // de la más nueva a la más vieja: gana la primera que aparece.
			$p  = json_decode( (string) $r->payload, true );
			$id = is_array( $p ) && isset( $p['miembro_id'] ) ? (int) $p['miembro_id'] : 0;
			if ( $id && isset( $out[ $id ] ) && null === $out[ $id ]['guarda_mp'] && isset( $p['resultado'] ) && '' !== (string) $p['resultado'] ) {
				$out[ $id ]['guarda_mp']       = (string) $p['resultado'];
				$out[ $id ]['guarda_mp_fecha'] = self::iso( $r->fecha );
			}
		}
		$rows  = $wpdb->get_results( $wpdb->prepare(
			'SELECT payload FROM ' . $t . ' WHERE tipo = %s AND payload LIKE %s AND fecha >= %s ORDER BY id DESC LIMIT 100',
			CN_Webhook::TIPO_AVISO,
			'%' . $wpdb->esc_like( '"motivo":"sub_sin_vincular"' ) . '%',
			gmdate( 'Y-m-d H:i:s', $ahora - self::VENTANA_AVISOS )
		) );
		$items = array(); // preapproval_id => item
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$p = json_decode( (string) $r->payload, true );
			if ( ! is_array( $p ) || empty( $p['detalle']['items'] ) || ! is_array( $p['detalle']['items'] ) ) {
				continue;
			}
			foreach ( $p['detalle']['items'] as $it ) {
				$pid = is_array( $it ) && isset( $it['preapproval_id'] ) ? trim( (string) $it['preapproval_id'] ) : '';
				if ( '' !== $pid && ! isset( $items[ $pid ] ) ) {
					$items[ $pid ] = $it;
				}
			}
		}
		foreach ( self::vinculados( array_keys( $items ) ) as $pid ) { // las que ya se vincularon a alguna ficha no cuentan.
			unset( $items[ $pid ] );
		}
		foreach ( $items as $it ) {
			$ids = array();
			if ( ! empty( $it['socia_id'] ) ) {
				$ids[] = (int) $it['socia_id'];
			}
			if ( ! empty( $it['candidatas'] ) && is_array( $it['candidatas'] ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $it['candidatas'] ) );
			}
			$m = isset( $it['email'] ) ? strtolower( trim( (string) $it['email'] ) ) : '';
			if ( '' !== $m && isset( $emails[ $m ] ) ) {
				$ids = array_merge( $ids, $emails[ $m ] );
			}
			foreach ( $ids as $id ) {
				if ( isset( $out[ $id ] ) ) {
					$out[ $id ]['sub_sin_vincular'] = true;
				}
			}
		}
		foreach ( $out as $id => $g ) {
			$out[ $id ]['apta_winback'] = 'sin_pago' === $g['guarda_mp'] && ! $g['sub_sin_vincular'];
		}
		return $out;
	}

	protected static function socias( $ahora = null ) {
		global $wpdb;
		$ahora = null === $ahora ? time() : (int) $ahora;
		$t    = CN_DB::tabla( 'miembros' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, nombre_apellido, estado, preapproval_id, plan, acceso_hasta, baja_en, fecha_alta, fecha_fin_trial, email, celular_texto_plano
			 FROM {$t}
			 WHERE estado IN ('activo','pausado') OR ( preapproval_id IS NOT NULL AND preapproval_id <> '' )
			 ORDER BY id ASC LIMIT %d",
			self::MAX_SOCIAS + 1
		) );
		$rows      = is_array( $rows ) ? $rows : array();
		$truncadas = count( $rows ) > self::MAX_SOCIAS;
		$rows      = array_slice( $rows, 0, self::MAX_SOCIAS );
		$vencidos  = null;
		$marcados  = array(); // id => guarda, solo para las fichas de trial vencido
		if ( self::trials_vencidos_activo() ) {
			$extra    = self::filas_trials_vencidos( $ahora );
			$vencidos = array( 'cantidad' => min( count( $extra ), self::MAX_TRIALS_VENCIDOS ), 'truncados' => count( $extra ) > self::MAX_TRIALS_VENCIDOS, 'dias' => self::DIAS_TRIALS_VENCIDOS );
			$ya       = array();
			foreach ( $rows as $f ) {
				$ya[ (int) $f->id ] = true;
			}
			$nuevas = array();
			foreach ( array_slice( $extra, 0, self::MAX_TRIALS_VENCIDOS ) as $f ) {
				if ( ! isset( $ya[ (int) $f->id ] ) ) { // defensa: nunca duplicar una ficha que ya vino en la consulta principal.
					$rows[]   = $f;
					$nuevas[] = $f;
				}
			}
			$marcados = self::guarda_trials_vencidos( $nuevas, $ahora );
			$vencidos['aptas_winback'] = count( array_filter( $marcados, function ( $g ) {
				return $g['apta_winback'];
			} ) );
		}
		$lista     = array();
		$ext       = self::uso_activo();
		$uso       = $ext ? self::uso( array_map( function ( $f ) {
			return (int) $f->id;
		}, $rows ) ) : array();
		foreach ( $rows as $f ) {
			$pid     = trim( (string) $f->preapproval_id );
			$lista[] = array(
				'id'                => (int) $f->id,
				'nombre'            => (string) $f->nombre_apellido,
				'estado'            => (string) $f->estado,
				'tipo'              => self::tipo( $f ),
				'plan'              => '' !== trim( (string) $f->plan ) ? (string) $f->plan : null,
				'preapproval_id'    => '' !== $pid ? $pid : null,
				'acceso_hasta'      => self::iso( $f->acceso_hasta ),
				'baja_en'           => self::iso( $f->baja_en ),
				'alta'              => self::iso( $f->fecha_alta ),
				'fecha_fin_trial'   => self::iso( $f->fecha_fin_trial ),
				'celular'           => self::celular( $f->celular_texto_plano ),
				'email_enmascarado' => self::email_enmascarado( $f->email ),
				'ultimo_acceso'     => null, // no existe en la base: wp_cn_sesiones solo guarda la expiración y se borra al cerrar sesión.
			);
			if ( $ext ) { // Fase 0, paso 4: para cruzar trial -> suscripción por email y medir el uso del trial. Solo lectura.
				$i                      = count( $lista ) - 1;
				$mail                   = trim( (string) $f->email );
				$lista[ $i ]['email']   = '' !== $mail && false !== strpos( $mail, '@' ) ? $mail : null; // uso interno autorizado por Leandro; solo con token y HTTPS.
				$lista[ $i ]['uso']     = isset( $uso[ (int) $f->id ] ) ? $uso[ (int) $f->id ] : self::uso_vacio();
			}
			if ( isset( $marcados[ (int) $f->id ] ) ) { // solo en las fichas agregadas: las demás quedan exactamente como antes.
				$lista[ count( $lista ) - 1 ] += array( 'trial_vencido' => true ) + $marcados[ (int) $f->id ];
			}
		}
		return array( 'lista' => $lista, 'truncadas' => $truncadas, 'vencidos' => $vencidos );
	}

	// ---------------------------------------------------------------- uso del área de socias (solo si cn_panel_uso_activo = '1')
	protected static function uso_vacio() {
		return array( 'ultimo_login_aprox' => null, 'sesion_abierta' => null, 'contenidos_vistos' => null, 'primer_visto' => null, 'ultimo_visto' => null, 'ultima_actividad' => null, 'ultimo_curso_id' => null, 'ultimo_contenido_id' => null );
	}

	/**
	 * Uso por ficha, con TRES consultas agrupadas (solo SELECT) sobre las tablas que ya llena el área de socias:
	 *  - wp_cn_sesiones: guarda solo la expiración (login + CN_Auth::SESSION_DIAS) y se borra al cerrar sesión, así que el último login es
	 *    aproximado (MAX(expira) - SESSION_DIAS) y queda en null si la socia cerró sesión. No es "última visita".
	 *  - wp_cn_vistos:   contenidos marcados como vistos (cantidad, fecha del primero y del último).
	 *  - wp_cn_progreso: el último contenido abierto (una fila por socia) y cuándo.
	 * Si una consulta falla, sus campos quedan en null (nunca se inventa un 0). Sin celulares ni nombres.
	 *
	 * @return array<int,array> miembro_id => campos de uso
	 */
	protected static function uso( $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = array();
		if ( ! $ids ) {
			return $out;
		}
		foreach ( $ids as $id ) {
			$out[ $id ] = self::uso_vacio();
		}
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$ahora = time();

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT miembro_id, MAX(expira) AS expira FROM ' . CN_DB::tabla( 'sesiones' ) . " WHERE miembro_id IN ({$in}) GROUP BY miembro_id", $ids ) );
		if ( '' === (string) $wpdb->last_error && is_array( $rows ) ) {
			foreach ( $out as $id => $_ ) {
				$out[ $id ]['sesion_abierta'] = false; // la consulta anduvo: sin fila = sin sesión.
			}
			foreach ( $rows as $r ) {
				$ts = strtotime( (string) $r->expira . ' UTC' );
				if ( isset( $out[ (int) $r->miembro_id ] ) && false !== $ts ) {
					$out[ (int) $r->miembro_id ]['sesion_abierta']     = $ts > $ahora;
					$out[ (int) $r->miembro_id ]['ultimo_login_aprox'] = gmdate( 'Y-m-d\TH:i:s\Z', $ts - CN_Auth::SESSION_DIAS * DAY_IN_SECONDS );
				}
			}
		}
		$wpdb->last_error = '';

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT miembro_id, COUNT(*) AS n, MIN(fecha) AS primera, MAX(fecha) AS ultima FROM ' . CN_DB::tabla( 'vistos' ) . " WHERE miembro_id IN ({$in}) GROUP BY miembro_id", $ids ) );
		if ( '' === (string) $wpdb->last_error && is_array( $rows ) ) {
			foreach ( $out as $id => $_ ) {
				$out[ $id ]['contenidos_vistos'] = 0;
			}
			foreach ( $rows as $r ) {
				if ( isset( $out[ (int) $r->miembro_id ] ) ) {
					$out[ (int) $r->miembro_id ]['contenidos_vistos'] = (int) $r->n;
					$out[ (int) $r->miembro_id ]['primer_visto']      = self::iso( $r->primera ); // la primera vez que marcó un contenido como visto: la "primera clase iniciada" no se guarda en ningún lado.
					$out[ (int) $r->miembro_id ]['ultimo_visto']      = self::iso( $r->ultima );
				}
			}
		}
		$wpdb->last_error = '';

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT miembro_id, curso_id, contenido_id, actualizado FROM ' . CN_DB::tabla( 'progreso' ) . " WHERE miembro_id IN ({$in})", $ids ) );
		if ( '' === (string) $wpdb->last_error && is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				if ( isset( $out[ (int) $r->miembro_id ] ) ) {
					$out[ (int) $r->miembro_id ]['ultima_actividad']    = self::iso( $r->actualizado );
					$out[ (int) $r->miembro_id ]['ultimo_curso_id']     = (int) $r->curso_id;
					$out[ (int) $r->miembro_id ]['ultimo_contenido_id'] = (int) $r->contenido_id;
				}
			}
		}
		$wpdb->last_error = '';
		return $out;
	}

	// ---------------------------------------------------------------- alertas
	protected static function alertas( $ahora ) {
		return array(
			'authorized_sin_ficha' => self::sin_ficha( $ahora ),
			'esperando_acceso'     => self::esperando_acceso(),
			'avisos_abiertos'      => self::avisos_abiertos(),
			'pausadas_modo_seguro' => self::pausadas_modo_seguro( $ahora ),
		);
	}

	/** preapproval_ids que ya tienen ficha (de la lista dada). */
	protected static function vinculados( $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ), 'strlen' ) ) );
		if ( ! $ids ) {
			return array();
		}
		$sql = 'SELECT preapproval_id FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE preapproval_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ')';
		$col = $wpdb->get_col( $wpdb->prepare( $sql, $ids ) );
		return is_array( $col ) ? array_map( 'strval', $col ) : array();
	}

	/**
	 * Suscripciones authorized sin ficha, según el aviso sub_sin_vincular que ya emite CN_Sync (mp_log, últimos 7 días, menos las que hoy ya
	 * tienen ficha). horas_sin_vincular sale del caché de la pantalla "Suscripciones sin vincular" (date_created de MP) y solo existe si alguien
	 * la abrió en los últimos 10 min; si no, null. Sin ese dato, mas_de_72h se decide con el primer aviso (la suscripción llevaba al menos 1 hora
	 * sin vincular cuando se avisó): true si ya pasaron más de 72 hs, null si no se puede saber.
	 */
	protected static function sin_ficha( $ahora ) {
		global $wpdb;
		$desde = gmdate( 'Y-m-d H:i:s', $ahora - self::VENTANA_AVISOS );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			'SELECT payload, fecha FROM ' . CN_DB::tabla( 'mp_log' ) . ' WHERE tipo = %s AND payload LIKE %s AND fecha >= %s ORDER BY id DESC LIMIT 100',
			CN_Webhook::TIPO_AVISO,
			'%' . $wpdb->esc_like( '"motivo":"sub_sin_vincular"' ) . '%',
			$desde
		) );
		$vistas = array(); // preapproval_id => array( plan, monto, primer_aviso (UTC mysql) )
		foreach ( is_array( $rows ) ? $rows : array() as $r ) { // de la más nueva a la más vieja: la última que queda es el primer aviso.
			$p = json_decode( (string) $r->payload, true );
			if ( ! is_array( $p ) || empty( $p['detalle']['items'] ) || ! is_array( $p['detalle']['items'] ) ) {
				continue;
			}
			foreach ( $p['detalle']['items'] as $it ) {
				$id = is_array( $it ) && isset( $it['preapproval_id'] ) ? trim( (string) $it['preapproval_id'] ) : '';
				if ( '' === $id ) {
					continue;
				}
				$vistas[ $id ] = array(
					'plan'         => isset( $it['plan'] ) && '' !== (string) $it['plan'] ? (string) $it['plan'] : ( $vistas[ $id ]['plan'] ?? null ),
					'monto'        => isset( $it['monto'] ) && is_numeric( $it['monto'] ) ? (float) $it['monto'] : ( $vistas[ $id ]['monto'] ?? null ),
					'primer_aviso' => (string) $r->fecha,
				);
			}
		}
		foreach ( self::vinculados( array_keys( $vistas ) ) as $id ) {
			unset( $vistas[ $id ] );
		}
		$altas = array();
		$cache = get_transient( CN_Sin_Vincular::TRANSIENT );
		if ( is_array( $cache ) && ! empty( $cache['ok'] ) && ! empty( $cache['filas'] ) && is_array( $cache['filas'] ) ) {
			foreach ( $cache['filas'] as $f ) {
				$ts = ! empty( $f['alta_iso'] ) ? strtotime( (string) $f['alta_iso'] ) : false;
				if ( ! empty( $f['id'] ) && false !== $ts ) {
					$altas[ (string) $f['id'] ] = $ts;
				}
			}
		}
		$items      = array();
		$algun      = false;
		$nuevos     = method_exists( 'CN_Alta_Mail', 'ids_en_conflicto' ) && method_exists( 'CN_Alta_Mail', 'link_vencido' ); // deploy archivo por archivo: clase vieja => motivo null
		$conflictos = $vistas && $nuevos ? CN_Alta_Mail::ids_en_conflicto( $ahora ) : array();
		$conflictos = is_array( $conflictos ) ? $conflictos : array(); // error de base: sin dato de conflicto (no se inventa)
		$por_motivo = array( 'sin_ficha' => 0, 'conflicto' => 0, 'link_vencido' => 0 );
		foreach ( array_keys( $vistas ) as $id ) {
			$m                    = $nuevos ? self::motivo_sin_ficha( $id, $conflictos, $ahora ) : null;
			$vistas[ $id ]['mot'] = $m;
			if ( null !== $m ) {
				$por_motivo[ $m ]++;
			}
		}
		foreach ( array_slice( $vistas, 0, self::MAX_SIN_FICHA, true ) as $id => $v ) {
			$primer = strtotime( $v['primer_aviso'] . ' UTC' );
			if ( isset( $altas[ $id ] ) ) {
				$horas = round( ( $ahora - $altas[ $id ] ) / 3600, 1 );
				$mas   = $horas > 72;
			} else {
				$horas = null;
				$mas   = ( false !== $primer && ( $ahora - $primer + CN_Sync::MIN_SIN_VINCULAR ) > 72 * 3600 ) ? true : null;
			}
			$algun   = $algun || true === $mas;
			$items[] = array(
				'preapproval_id'      => $id,
				'plan'                => $v['plan'],
				'monto'               => $v['monto'],
				'horas_sin_vincular'  => $horas,
				'primer_aviso'        => self::iso( $v['primer_aviso'] ),
				'mas_de_72h'          => $mas,
				'motivo'              => $v['mot'],
			);
		}
		return array( 'cantidad' => count( $vistas ), 'mas_de_72h' => $algun, 'por_motivo' => $por_motivo, 'items' => $items );
	}

	/**
	 * Por qué una suscripción authorized sigue sin ficha (solo lectura: mp_log y options):
	 *  - conflicto:    sub_conflicto (webhook, vinculación manual o página de retorno) encontró una ficha que ya tiene OTRA suscripción.
	 *  - link_vencido: se le mandó el mail de alta (o el recordatorio) y el último link ya venció.
	 *  - sin_ficha:    el resto.
	 */
	protected static function motivo_sin_ficha( $id, $conflictos, $ahora ) {
		$id = strtolower( (string) $id );
		if ( isset( $conflictos[ $id ] ) ) {
			return 'conflicto';
		}
		return CN_Alta_Mail::link_vencido( $id, $ahora ) ? 'link_vencido' : 'sin_ficha';
	}

	/** Clientas esperando acceso (cartel de Socias). No usa CN_Retorno::pendientes_vigentes porque esa función limpia la opción (escribe). */
	protected static function esperando_acceso() {
		$p = get_option( CN_Retorno::OPT_PENDIENTES, array() );
		$p = is_array( $p ) ? $p : array();
		foreach ( self::vinculados( array_keys( $p ) ) as $id ) {
			unset( $p[ $id ] );
		}
		$lista = array();
		foreach ( array_slice( $p, 0, self::MAX_ESPERANDO, true ) as $id => $d ) {
			$lista[] = array(
				'preapproval_id' => (string) $id,
				'fecha'          => is_array( $d ) && isset( $d['fecha'] ) ? self::iso( $d['fecha'] ) : null,
				'motivo'         => is_array( $d ) && isset( $d['motivo'] ) ? mb_substr( (string) $d['motivo'], 0, 100 ) : null,
			);
		}
		return array( 'cantidad' => count( $p ), 'lista' => $lista );
	}

	/** Los mismos avisos que el cartel "Avisos" de Socias (CN_Webhook::listar_avisos): hoy no hay forma de "cerrarlos", son los registrados. */
	protected static function avisos_abiertos() {
		$a     = CN_Webhook::listar_avisos( 50 );
		$tipos = array();
		foreach ( $a['lineas'] as $l ) {
			$m = (string) $l['motivo'];
			if ( ! isset( $tipos[ $m ] ) ) {
				$tipos[ $m ] = array( 'tipo' => $m, 'veces' => 0, 'ultima' => self::iso( $l['ultima'] ) );
			}
			$tipos[ $m ]['veces'] += (int) $l['veces'];
		}
		return array( 'cantidad' => (int) $a['distintos'], 'total_registros' => (int) $a['total'], 'tipos' => array_values( $tipos ) );
	}

	protected static function contar_log( $tipo, $desde_ts, $payload_like = array() ) {
		global $wpdb;
		$sql  = 'SELECT COUNT(*) FROM ' . CN_DB::tabla( 'mp_log' ) . ' WHERE tipo = %s AND fecha >= %s';
		$args = array( $tipo, gmdate( 'Y-m-d H:i:s', $desde_ts ) );
		if ( $payload_like ) {
			$sql .= ' AND (' . implode( ' OR ', array_fill( 0, count( $payload_like ), 'payload LIKE %s' ) ) . ')';
			foreach ( $payload_like as $l ) {
				$args[] = '%' . $wpdb->esc_like( $l ) . '%';
			}
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	protected static function pausadas_modo_seguro( $ahora ) {
		return array( 'cantidad' => self::contar_log( 'downgrade_omitido', $ahora - 72 * 3600 ), 'ventana_horas' => 72 );
	}

	// ---------------------------------------------------------------- config y sync (solo modos y booleanos)
	protected static function flag( $nombre, $defecto ) {
		return '1' === (string) get_option( $nombre, $defecto );
	}

	protected static function config() {
		return array(
			'cn_alta_mail_modo'        => CN_Alta_Mail::modo(),
			'cn_sub_modo_seguro'       => self::flag( 'cn_sub_modo_seguro', '1' ),
			'cn_sub_auto_activar'      => self::flag( 'cn_sub_auto_activar', '0' ),
			'cn_retorno_activo'        => self::flag( 'cn_retorno_activo', '0' ),
			'espejo'                   => CN_Espejo_MP::modo(),
			'sync_activo'              => self::flag( 'cn_sync_mp_activo', '1' ),
			'cn_guarda_trial_solo_log' => self::flag( 'cn_guarda_trial_solo_log', '1' ),
		);
	}

	/**
	 * Hoy el sync solo guarda la última corrida COMPLETA y exitosa (cn_sync_ultima_ok); no registra intentos fallidos salvo los lotes con
	 * fallos (mp_log sync_mp_lote). Por eso "resultado" es ok / caido (más de 24 hs sin corrida exitosa) / null (nunca corrió bien).
	 */
	protected static function sync( $ahora ) {
		global $wpdb;
		$ok     = (int) get_option( CN_Alertas::OPT_SYNC_OK, 0 );
		$caido  = '1' === (string) get_option( CN_Alertas::OPT_SYNC_CAIDO, '0' );
		$desde  = $ahora - DAY_IN_SECONDS;
		$lote   = $wpdb->get_row( $wpdb->prepare(
			'SELECT payload, fecha FROM ' . CN_DB::tabla( 'mp_log' ) . ' WHERE tipo = %s ORDER BY id DESC LIMIT 1',
			'sync_mp_lote'
		) );
		$lote_p = $lote ? json_decode( (string) $lote->payload, true ) : null;
		$cola   = get_option( CN_Sync::OPT_FALLIDOS, array() );
		return array(
			'ultima_corrida_ok'      => $ok > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $ok ) : null,
			'resultado'              => $caido ? 'caido' : ( $ok > 0 ? 'ok' : null ),
			'ultimo_lote_con_fallos' => ( $lote && is_array( $lote_p ) ) ? array(
				'fecha'    => self::iso( $lote->fecha ),
				'fallos'   => isset( $lote_p['fallos'] ) ? (int) $lote_p['fallos'] : null,
				'abortada' => isset( $lote_p['abortada'] ) ? (bool) $lote_p['abortada'] : null,
			) : null,
			'errores_429_24h'        => self::contar_log( 'mp_api_error', $desde, array( '"http":429', '"http":"429"' ) ),
			'errores_mp_api_24h'     => self::contar_log( 'mp_api_error', $desde ),
			'cursor'                 => (int) get_option( 'cn_sync_mp_cursor', 0 ),
			'en_cola_reintento'      => is_array( $cola ) ? count( $cola ) : 0,
		);
	}

	// ---------------------------------------------------------------- cartel de la portada (solo números)
	/**
	 * Métricas del cartel de suscripción (CN_Cartel_Trial). Solo SELECT y solo agregados: ni filas, ni ids, ni datos de fichas o de MP.
	 *
	 * @return array{modo:string,dias:int,zona:string,por_dia:array,clics:array,altas:array}
	 */
	protected static function cartel( $ahora ) {
		global $wpdb;
		$desde_dia = gmdate( 'Y-m-d', $ahora - ( self::CARTEL_DIAS - 1 ) * DAY_IN_SECONDS );
		$modo      = (string) get_option( self::CARTEL_OPT_MODO, 'off' );
		$modo      = in_array( $modo, array( 'log', 'on' ), true ) ? $modo : 'off'; // mismo criterio que CN_Cartel_Trial::modo()

		// por_dia: la opción tal cual, saneada (claves Y-m-d de los últimos 30 días, solo los motivos de la lista cerrada, valores enteros).
		$conteo  = get_option( self::CARTEL_OPT_CONTEO, array() );
		$por_dia = array();
		if ( is_array( $conteo ) ) {
			foreach ( $conteo as $dia => $motivos ) {
				if ( ! is_string( $dia ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dia ) || $dia < $desde_dia || ! is_array( $motivos ) ) {
					continue;
				}
				foreach ( $motivos as $m => $n ) {
					if ( in_array( (string) $m, self::CARTEL_MOTIVOS, true ) ) {
						$por_dia[ $dia ][ (string) $m ] = (int) $n;
					}
				}
			}
		}
		krsort( $por_dia );

		$clics = array( 'total' => 0, 'por_origen' => array_fill_keys( self::CARTEL_ORIGENES, 0 ), 'por_dia' => array() );
		$altas = array( 'total' => 0, 'por_plan' => array_fill_keys( self::CARTEL_PLANES, 0 ), 'por_dia' => array() );
		$filas = $wpdb->get_results( $wpdb->prepare(
			'SELECT DATE(creado) AS dia, origen, plan, usado, COUNT(*) AS n FROM ' . CN_DB::tabla( 'suscripciones_pendientes' )
			. ' WHERE origen IN (%s, %s) GROUP BY DATE(creado), origen, plan, usado',
			self::CARTEL_ORIGENES[0],
			self::CARTEL_ORIGENES[1]
		) );
		foreach ( (array) $filas as $f ) {
			$origen = isset( $f->origen ) ? (string) $f->origen : '';
			if ( ! in_array( $origen, self::CARTEL_ORIGENES, true ) ) {
				continue;
			}
			$n   = (int) ( $f->n ?? 0 );
			$dia = isset( $f->dia ) ? (string) $f->dia : '';
			$dia = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dia ) && $dia >= $desde_dia ? $dia : '';
			$clics['total']                += $n;
			$clics['por_origen'][ $origen ] += $n;
			if ( '' !== $dia ) {
				if ( ! isset( $clics['por_dia'][ $dia ] ) ) {
					$clics['por_dia'][ $dia ] = array( 'total' => 0 ) + array_fill_keys( self::CARTEL_ORIGENES, 0 );
				}
				$clics['por_dia'][ $dia ]['total']   += $n;
				$clics['por_dia'][ $dia ][ $origen ] += $n;
			}
			$plan = isset( $f->plan ) ? (string) $f->plan : '';
			if ( 1 === (int) ( $f->usado ?? 0 ) && in_array( $plan, self::CARTEL_PLANES, true ) ) {
				$altas['total']             += $n;
				$altas['por_plan'][ $plan ] += $n;
				if ( '' !== $dia ) {
					if ( ! isset( $altas['por_dia'][ $dia ] ) ) {
						$altas['por_dia'][ $dia ] = array( 'total' => 0 ) + array_fill_keys( self::CARTEL_PLANES, 0 );
					}
					$altas['por_dia'][ $dia ]['total'] += $n;
					$altas['por_dia'][ $dia ][ $plan ] += $n;
				}
			}
		}
		krsort( $clics['por_dia'] );
		krsort( $altas['por_dia'] );
		return array(
			'modo'    => $modo,
			'dias'    => self::CARTEL_DIAS,
			'zona'    => 'UTC',
			'por_dia' => (object) $por_dia, // objeto también vacío: {} en el JSON, no []
			'clics'   => array( 'total' => $clics['total'], 'por_origen' => $clics['por_origen'], 'por_dia' => (object) $clics['por_dia'] ),
			'altas'   => array( 'total' => $altas['total'], 'por_plan' => $altas['por_plan'], 'por_dia' => (object) $altas['por_dia'] ),
		);
	}

	// ---------------------------------------------------------------- Config (wp-admin, manage_options)
	/** Procesa el botón "Generar/rotar token". Devuelve el token en claro UNA sola vez (solo se guarda el sha256) o null. */
	public static function procesar_generar_token() {
		if ( ! isset( $_POST['cn_panel_generar_token'] ) || ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::NONCE, 'cn_panel_token_nonce' ) ) {
			return null;
		}
		$token = bin2hex( random_bytes( 32 ) );
		update_option( self::OPT_TOKEN_HASH, hash( 'sha256', $token ), false );
		return $token;
	}

	public static function bloque_config( $token_nuevo = null ) {
		$hay = '' !== (string) get_option( self::OPT_TOKEN_HASH, '' );
		?>
		<h2>Panel de socias (API de solo lectura)</h2>
		<p>Endpoint: <code><?php echo esc_html( rest_url( 'cn/v1/panel-datos' ) ); ?></code> — encabezado <code>X-CN-Panel-Token</code>. Se prende con la casilla "Panel de socias" de arriba (apagado: la dirección no existe).</p>
		<?php if ( $token_nuevo ) : ?>
			<div class="notice notice-warning inline" style="max-width:700px;padding:10px 14px;">
				<p><strong>Copiá este token ahora: no se vuelve a mostrar.</strong> Si lo perdés, generá otro (el anterior deja de funcionar).</p>
				<input type="text" readonly class="regular-text" style="width:100%;font-family:monospace;" onclick="this.select();" value="<?php echo esc_attr( $token_nuevo ); ?>">
			</div>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, 'cn_panel_token_nonce' ); ?>
			<p><?php echo $hay ? 'Ya hay un token generado. Generar uno nuevo desactiva el anterior.' : 'Todavía no hay token generado.'; ?></p>
			<?php submit_button( $hay ? 'Rotar token' : 'Generar token', 'secondary', 'cn_panel_generar_token', false ); ?>
		</form>
		<?php
	}
}
CN_Panel_API::registrar(); // se engancha sola: quitar la API = borrar el require_once del bootstrap y este archivo.
