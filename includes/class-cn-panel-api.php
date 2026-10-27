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
 * - Sin migraciones. Quitarlo = borrar el require_once del bootstrap y este archivo.
 */
class CN_Panel_API {
	const OPT_ACTIVO     = 'cn_panel_api_activo';
	const OPT_TOKEN_HASH = 'cn_panel_token_hash';
	const MAX_SOCIAS     = 600;
	const MAX_POR_MIN    = 30;
	const MAX_SIN_FICHA  = 50;
	const MAX_ESPERANDO  = 10;
	const VENTANA_AVISOS = 604800; // 7 días: lo mismo que poda el sync en cn_sync_alertados.
	const NONCE          = 'cn_panel_token';

	public static function registrar() {
		add_action( 'rest_api_init', array( __CLASS__, 'registrar_rutas' ) );
	}

	public static function activo() {
		return '1' === (string) get_option( self::OPT_ACTIVO, '0' );
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
		$s     = self::socias();
		return array(
			'generado_en'      => gmdate( 'Y-m-d\TH:i:s\Z', $ahora ),
			'socias'           => $s['lista'],
			'socias_truncadas' => $s['truncadas'],
			'alertas'          => self::alertas( $ahora ),
			'config'           => self::config(),
			'sync'             => self::sync( $ahora ),
		);
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

	protected static function socias() {
		global $wpdb;
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
		$lista     = array();
		foreach ( array_slice( $rows, 0, self::MAX_SOCIAS ) as $f ) {
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
		}
		return array( 'lista' => $lista, 'truncadas' => $truncadas );
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
		$items = array();
		$algun = false;
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
			);
		}
		return array( 'cantidad' => count( $vistas ), 'mas_de_72h' => $algun, 'items' => $items );
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
