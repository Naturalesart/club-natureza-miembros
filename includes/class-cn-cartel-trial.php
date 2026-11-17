<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Experimento: cartel "suscribite" ADENTRO del club, solo en la portada ([cn_login] sin ?cn_curso), para las fichas con
 * trial activo a las que les quedan 48 h o menos.
 *
 * Texto real, sin urgencia falsa: "Entraste a N clases del club. Tu prueba termina el jueves 19/11." (con N = 0, solo la
 * fecha). Dos botones, cada uno con el link firmado existente (?cn_suscribir=, CN_Suscribir) de ESA ficha:
 *   - "Quiero el Full →"           plan full   · origen club_cartel_full
 *   - "Seguir con el Plan Club →"  plan basico · origen club_cartel_club
 * Un clic = el endpoint crea (o reusa, 24 h) la suscripción personal y redirige directo al init_point de Mercado Pago.
 *
 * A quién (todo a la vez): estado 'activo', SIN preapproval_id (las suscriptas no), CON fecha_fin_trial (las manuales no),
 * fecha_fin_trial en el futuro (las vencidas no; igual CN_Auth ya no las deja entrar) y a 48 h o menos.
 *
 * Flag cn_cartel_trial_modo (off por defecto):
 *   off = no hace nada (ni lee la base).
 *   log = no muestra nada; por ficha elegible y por día registra en mp_log `cartel_trial_elegible` (ficha_id, horas, vistos)
 *         y suma 1 al conteo diario por motivo (opción cn_cartel_trial_conteo): se_mostraria | suscribir_off | sin_secreto |
 *         sin_email | sin_celular | sin_token | con_cache. Los motivos son solo conteos: sin id ni datos de la ficha.
 *   on  = muestra el cartel si no hay motivo para frenarlo; registra `cartel_trial_mostrado` (una vez por ficha y día) y el
 *         mismo conteo (mostrado | <motivo>).
 *
 * Caché: la página de [cn_login] ya manda DONOTCACHEPAGE + no-cache (club-natureza-miembros.php). El cartel lleva tokens de
 * la ficha, así que además se exige que DONOTCACHEPAGE esté definida al renderizar: si no, no se muestra (motivo con_cache).
 * El token vence en 6 h o al fin del trial (lo que pase primero) y MP solo deja pagar a la cuenta del email de la ficha.
 *
 * Solo LECTURA de wp_cn_miembros y wp_cn_vistos. No toca login, sesiones, acceso, webhook ni Mercado Pago (el único efecto
 * en MP lo hace el endpoint de CN_Suscribir cuando la socia hace clic). Sin nombres, emails ni celulares en el HTML del
 * cartel, en los links ni en los logs. Sin WhatsApp.
 */
class CN_Cartel_Trial {
	const OPT_MODO     = 'cn_cartel_trial_modo';   // off | log | on
	const OPT_CONTEO   = 'cn_cartel_trial_conteo'; // array( 'Y-m-d' => array( motivo => n ) ), últimos DIAS_CONTEO días
	const VENTANA_SEG  = 172800;                   // 48 h antes de fecha_fin_trial
	const TOKEN_SEG    = 21600;                    // el token del cartel vence a las 6 h (o antes, si termina el trial)
	const DIAS_CONTEO  = 30;
	const ORIGEN_FULL  = 'club_cartel_full';
	const ORIGEN_CLUB  = 'club_cartel_club';
	const MOTIVOS      = array( 'suscribir_off', 'sin_secreto', 'sin_email', 'sin_celular', 'sin_token', 'con_cache' );
	const DIAS_SEMANA  = array( 'domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado' );

	/** Reloj (timestamp); los tests lo fijan. null = time(). */
	public static $reloj = null;
	/** Los tests fuerzan la condición "página sin caché". null = DONOTCACHEPAGE definida. */
	public static $sin_cache = null;

	public static function modo() {
		$m = (string) get_option( self::OPT_MODO, 'off' );
		return in_array( $m, array( 'log', 'on' ), true ) ? $m : 'off';
	}

	protected static function ahora() {
		return null === self::$reloj ? time() : (int) self::$reloj;
	}

	protected static function pagina_sin_cache() {
		if ( null !== self::$sin_cache ) {
			return (bool) self::$sin_cache;
		}
		return defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE;
	}

	/**
	 * Timestamp de fin del trial si la ficha es del público del cartel (trial activo, sin suscripción, termina en 48 h o
	 * menos); null si no. Usa solo lo que ya trae CN_Auth::get_miembro_actual().
	 */
	public static function fin_trial_si_elegible( $miembro ) {
		if ( ! is_object( $miembro ) || 'activo' !== (string) ( $miembro->estado ?? '' ) ) {
			return null;
		}
		$pre = isset( $miembro->preapproval_id ) ? $miembro->preapproval_id : null;
		if ( null !== $pre && '' !== (string) $pre ) { // misma regla que CN_Auth: NULL y '' = sin suscripción.
			return null;
		}
		// fecha_fin_trial se guarda en UTC (gmdate en el alta del trial, CN_Webhook) y se compara EXACTAMENTE como
		// CN_Auth::trial_vencido(): vacía o '0000-00-00 00:00:00' = sin trial (manual); vencida si
		// strtotime( fecha . ' UTC' ) < ahora. En el segundo exacto del fin todavía tiene acceso, así que todavía es elegible.
		$fin = isset( $miembro->fecha_fin_trial ) ? $miembro->fecha_fin_trial : null;
		if ( empty( $fin ) || '0000-00-00 00:00:00' === $fin ) {
			return null; // manual (sin trial)
		}
		$ts = strtotime( $fin . ' UTC' );
		if ( false === $ts ) {
			return null;
		}
		$ahora = self::ahora();
		if ( $ts < $ahora || $ts - $ahora > self::VENTANA_SEG ) {
			return null; // vencida (misma condición que CN_Auth) o le quedan más de 48 h
		}
		return $ts;
	}

	/** Cantidad de clases (videos) que la socia abrió alguna vez. Solo lectura de wp_cn_vistos. */
	public static function clases_vistas( $miembro_id ) {
		global $wpdb;
		$n = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . CN_DB::tabla( 'vistos' ) . ' v INNER JOIN ' . CN_DB::tabla( 'contenidos' ) . " c ON c.id = v.contenido_id WHERE v.miembro_id = %d AND c.tipo = 'video'",
			(int) $miembro_id
		) );
		return max( 0, (int) $n );
	}

	/** "jueves 19/11" en la zona horaria del sitio (Argentina si no hay wp_timezone). */
	public static function dia_fin( $ts ) {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'America/Argentina/Buenos_Aires' );
		$d  = ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz );
		return self::DIAS_SEMANA[ (int) $d->format( 'w' ) ] . ' ' . $d->format( 'd/m' );
	}

	/** Precio del plan con formato argentino ("$" + miles con punto), leído de CN_MP::PLANES (nunca escrito a mano). */
	public static function precio( $plan ) {
		$planes = CN_MP::get_planes();
		$p      = isset( $planes[ $plan ]['precio'] ) ? (int) $planes[ $plan ]['precio'] : 0;
		return $p > 0 ? '$' . number_format( $p, 0, ',', '.' ) : '';
	}

	/** Texto de cada botón: "Quiero el Full · <precio>/mes →" y "Seguir con el Plan Club · <precio>/mes →". */
	public static function etiqueta( $plan ) {
		$precio = self::precio( $plan );
		$base   = 'full' === $plan ? 'Quiero el Full' : 'Seguir con el Plan Club';
		return $base . ( '' !== $precio ? ' · ' . $precio . '/mes' : '' ) . ' →';
	}

	public static function texto( $vistas, $ts_fin ) {
		$fecha = 'Tu prueba termina el ' . self::dia_fin( $ts_fin ) . '.';
		$n     = (int) $vistas;
		if ( $n <= 0 ) {
			return $fecha;
		}
		return 'Entraste a ' . $n . ' ' . ( 1 === $n ? 'clase' : 'clases' ) . ' del club. ' . $fecha;
	}

	/**
	 * Por qué NO se puede mostrar el cartel a esta ficha elegible ('' = se puede). Lee email y celular de la ficha solo
	 * para validarlos con las mismas reglas del endpoint (CN_Suscribir::email_de / celular_de): así ningún clic cae en
	 * /planes-club/ por falta de datos.
	 */
	protected static function motivo_bloqueo( $miembro_id ) {
		if ( ! class_exists( 'CN_Suscribir' ) || 'on' !== CN_Suscribir::modo() ) {
			return 'suscribir_off';
		}
		if ( '' === CN_Suscribir::secreto() ) {
			return 'sin_secreto';
		}
		global $wpdb;
		$ficha = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, email, celular_texto_plano, celular_hash FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE id = %d',
			(int) $miembro_id
		) );
		if ( ! $ficha || '' === CN_Suscribir::email_de( $ficha ) ) {
			return 'sin_email';
		}
		if ( '' === CN_Suscribir::celular_de( $ficha ) ) {
			return 'sin_celular';
		}
		if ( ! self::pagina_sin_cache() ) {
			return 'con_cache';
		}
		return '';
	}

	/** Tokens firmados de la ficha para los dos botones; null si no se pudo firmar alguno. */
	protected static function links( $miembro_id, $ts_fin ) {
		$vence = min( self::ahora() + self::TOKEN_SEG, (int) $ts_fin );
		$full  = CN_Suscribir::crear_token( (int) $miembro_id, 'full', self::ORIGEN_FULL, $vence );
		$club  = CN_Suscribir::crear_token( (int) $miembro_id, 'basico', self::ORIGEN_CLUB, $vence );
		if ( '' === $full || '' === $club ) {
			return null;
		}
		return array( 'full' => CN_Suscribir::url_token( $full ), 'club' => CN_Suscribir::url_token( $club ) );
	}

	/**
	 * HTML del cartel para la portada del club ('' si no corresponde). Lo llama CN_Shortcodes::shortcode_login solo en la
	 * rama de la socia ya logueada (después de CN_Auth) y solo en la portada.
	 */
	public static function html( $miembro ) {
		$modo = self::modo();
		if ( 'off' === $modo ) {
			return '';
		}
		$ts_fin = self::fin_trial_si_elegible( $miembro );
		if ( null === $ts_fin ) {
			return '';
		}
		$id     = (int) $miembro->id;
		$motivo = self::motivo_bloqueo( $id );
		$links  = null;
		if ( '' === $motivo ) {
			$links = self::links( $id, $ts_fin );
			if ( null === $links ) {
				$motivo = 'sin_token';
			}
		}
		$primera_hoy = self::primera_vez_hoy( $modo, $id );
		if ( 'log' === $modo ) {
			if ( $primera_hoy ) {
				self::contar( '' === $motivo ? 'se_mostraria' : $motivo );
				if ( '' === $motivo ) {
					CN_Webhook::registrar_log_mp( 'cartel_trial_elegible', array(
						'ficha_id' => $id,
						'horas'    => (int) floor( ( $ts_fin - self::ahora() ) / 3600 ),
						'vistos'   => self::clases_vistas( $id ),
					) );
				}
			}
			return '';
		}
		// on
		if ( '' !== $motivo ) {
			if ( $primera_hoy ) {
				self::contar( $motivo );
			}
			return '';
		}
		$vistas = self::clases_vistas( $id );
		if ( $primera_hoy ) {
			self::contar( 'mostrado' );
			CN_Webhook::registrar_log_mp( 'cartel_trial_mostrado', array(
				'ficha_id' => $id,
				'horas'    => (int) floor( ( $ts_fin - self::ahora() ) / 3600 ),
				'vistos'   => $vistas,
			) );
		}
		return self::render( self::texto( $vistas, $ts_fin ), $links );
	}

	protected static function render( $texto, $links ) {
		// Anti doble clic: ignora un segundo clic en cualquiera de los dos botones durante 5 s (y no deja los botones
		// muertos si vuelve con "atrás"). Igual el endpoint reusa la pendiente de 24 h del mismo plan.
		$js = "var w=this.closest('.cn-cartel-trial'),a=Date.now();if(w&&a-(+w.getAttribute('data-t')||0)<5000){return false;}if(w){w.setAttribute('data-t',a);w.classList.add('cn-cartel-trial--yendo');}";
		ob_start();
		?>
		<style>
		.cn-cartel-trial{background:var(--cn-crema,#fbf5e9)!important;border:1px solid var(--cn-borde,#efe0c8)!important;border-radius:18px!important;padding:20px 18px!important;margin:0 0 28px!important;box-sizing:border-box!important;max-width:100%!important}
		.cn-cartel-trial__texto{margin:0 0 16px!important;font-size:17px!important;line-height:1.45!important;color:var(--cn-texto,#43352c)!important}
		.cn-cartel-trial__botones{display:flex!important;flex-direction:column!important;gap:10px!important}
		.cn-cartel-trial .cn-boton{min-height:48px!important;box-sizing:border-box!important}
		.cn-cartel-trial .cn-boton--club{background:transparent!important;color:var(--cn-terracota,#bf5c3a)!important;border:2px solid var(--cn-terracota,#bf5c3a)!important;box-shadow:none!important}
		.cn-cartel-trial--yendo .cn-boton{opacity:.6!important;pointer-events:none!important}
		@media (min-width:640px){.cn-cartel-trial__botones{max-width:420px!important}}
		</style>
		<section class="cn-seccion cn-cartel-trial" aria-label="Tu prueba del club">
			<p class="cn-cartel-trial__texto"><?php echo esc_html( $texto ); ?></p>
			<div class="cn-cartel-trial__botones">
				<a class="cn-boton cn-boton--full" rel="nofollow" href="<?php echo esc_url( $links['full'] ); ?>" onclick="<?php echo esc_attr( $js ); ?>"><?php echo esc_html( self::etiqueta( 'full' ) ); ?></a>
				<a class="cn-boton cn-boton--club" rel="nofollow" href="<?php echo esc_url( $links['club'] ); ?>" onclick="<?php echo esc_attr( $js ); ?>"><?php echo esc_html( self::etiqueta( 'basico' ) ); ?></a>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** true la primera vez del día (UTC) para esta ficha y modo; marca el transient. */
	protected static function primera_vez_hoy( $modo, $miembro_id ) {
		$clave = 'cn_cartel_trial_' . $modo . '_' . (int) $miembro_id . '_' . gmdate( 'Ymd', self::ahora() );
		if ( get_transient( $clave ) ) {
			return false;
		}
		set_transient( $clave, 1, DAY_IN_SECONDS );
		return true;
	}

	/** Suma 1 al conteo del día para un motivo. Solo números: sin id ni datos de la ficha. */
	protected static function contar( $motivo ) {
		$c = get_option( self::OPT_CONTEO, array() );
		$c = is_array( $c ) ? $c : array();
		$d = gmdate( 'Y-m-d', self::ahora() );
		if ( ! isset( $c[ $d ] ) || ! is_array( $c[ $d ] ) ) {
			$c[ $d ] = array();
		}
		$c[ $d ][ $motivo ] = ( isset( $c[ $d ][ $motivo ] ) ? (int) $c[ $d ][ $motivo ] : 0 ) + 1;
		krsort( $c );
		$c = array_slice( $c, 0, self::DIAS_CONTEO, true );
		update_option( self::OPT_CONTEO, $c, false );
	}

	/** Conteo por día y motivo, para Config / revisión. */
	public static function conteo() {
		$c = get_option( self::OPT_CONTEO, array() );
		return is_array( $c ) ? $c : array();
	}
}
