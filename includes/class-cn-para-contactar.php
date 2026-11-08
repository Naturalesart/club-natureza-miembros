<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Pantalla "Para contactar" (Socias → Para contactar). Solo manage_options + nonce. SOLO LECTURA, solo para administración.
 *
 * Ayuda a contactar a quienes pagan y no tienen acceso hace más de 72 hs. Junta, SIN reglas nuevas de match:
 *  - suscripciones authorized sin vincular (las de "Suscripciones sin vincular"), con pago acreditado;
 *  - fichas vinculadas en estado cancelada/pausado cuya suscripción sigue authorized en MP, con pago acreditado;
 *  - los casos del cartel "pagó y no tiene acceso" (cn_casos_sin_acceso) de más de 72 hs que siguen sin ficha.
 * Por fila: antigüedad, plan, titular, email, ficha candidata, motivo y "Copiar mensaje de WhatsApp" (copia un texto sugerido
 * al portapapeles; NO envía nada, ni mails ni mensajes).
 *
 * Datos: el cache de CN_Sin_Vincular (transient de 10 min, que ahora guarda también un resumen de las authorized) y su cache de
 * titulares/cobros de 6 hs. Cargar la página NUNCA consulta a Mercado Pago: solo el botón "Consultar Mercado Pago" (POST con
 * nonce) renueva el cache y verifica el cobro de las filas que faltan, con el presupuesto de pedidos de CN_Sin_Vincular. Solo GET.
 * Sin pago acreditado verificado no aparece (si no se pudo verificar, se cuenta aparte). Cero escrituras en miembros.
 *
 * Doble suscripción (solo DETECTA, nunca actúa): 2+ suscripciones authorized con el mismo email del pagador o, si no, el mismo
 * titular de la tarjeta. "Posible doble pago" = mismo email y mismo plan. "Revisar" = planes distintos (puede ser legítimo) o
 * coincidencia solo por titular. Deja un aviso en el cartel Avisos, como máximo 1 por día por caso. No reembolsa ni cancela.
 */
class CN_Para_Contactar {
	const NONCE     = 'cn_para_contactar';
	const MIN_SEG   = 259200; // 72 hs
	const MAX_FILAS = 300;
	const AVISO_SEG = 86400;

	public static function registrar() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	public static function menu() {
		add_submenu_page( 'cn-socias', 'Para contactar', 'Para contactar', 'manage_options', 'cn-para-contactar', array( __CLASS__, 'pagina' ) );
	}

	// ---------------------------------------------------------------- datos (puros)
	/** Resumen mínimo de las suscripciones authorized (misma forma que MP en lo que se usa) para guardar en el cache. */
	public static function resumir( $items ) {
		$out = array();
		foreach ( (array) $items as $it ) {
			if ( ! is_array( $it ) || empty( $it['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id'             => (string) $it['id'],
				'payer_email'    => isset( $it['payer_email'] ) && is_scalar( $it['payer_email'] ) ? (string) $it['payer_email'] : '',
				'date_created'   => isset( $it['date_created'] ) && is_scalar( $it['date_created'] ) ? (string) $it['date_created'] : '',
				'auto_recurring' => array( 'transaction_amount' => isset( $it['auto_recurring']['transaction_amount'] ) ? $it['auto_recurring']['transaction_amount'] : null ),
			);
		}
		return $out;
	}

	public static function id_corto( $id ) {
		return substr( (string) $id, 0, 6 ) . '...';
	}

	protected static function ts( $texto ) {
		$ts = '' === (string) $texto ? false : strtotime( (string) $texto );
		return false === $ts ? null : $ts;
	}

	/** ¿Hay un cobro aprobado? true / false / null (no se sabe: sin consultar, error de MP o tope). */
	protected static function pagado( $estado ) {
		if ( in_array( $estado, array( 'ok', 'sin_titular' ), true ) ) {
			return true;
		}
		return 'sin_cobro' === $estado ? false : null;
	}

	/**
	 * Casos de doble suscripción entre las authorized (puro).
	 *
	 * @param array $items   suscripciones (id, payer_email, date_created, auto_recurring.transaction_amount)
	 * @param array $socias  fichas (id, nombre_apellido, estado, email, preapproval_id)
	 * @param array $emails  id => email ya resuelto para las que MP no trae payer_email
	 * @param array $titulares id => array( nombre, estado ) (cache de CN_Sin_Vincular)
	 * @return array lista de array{clave,tipo:doble|revisar,criterio,subs:array}
	 */
	public static function detectar_dobles( $items, $socias, $emails = array(), $titulares = array() ) {
		$idx  = CN_Vinculo::indice_socias( $socias );
		$subs = array();
		foreach ( (array) $items as $it ) {
			$id = is_array( $it ) && ! empty( $it['id'] ) ? (string) $it['id'] : '';
			if ( '' === $id || isset( $subs[ $id ] ) ) {
				continue;
			}
			$ficha = isset( $idx['por_preapproval'][ $id ] ) ? $idx['por_preapproval'][ $id ] : null;
			$email = CN_Vinculo::email_normalizado( isset( $it['payer_email'] ) ? $it['payer_email'] : '' );
			if ( '' === $email && isset( $emails[ $id ] ) ) {
				$email = CN_Vinculo::email_normalizado( $emails[ $id ] );
			}
			if ( '' === $email && $ficha ) {
				$email = CN_Vinculo::email_normalizado( isset( $ficha->email ) ? $ficha->email : '' );
			}
			$monto  = isset( $it['auto_recurring']['transaction_amount'] ) ? $it['auto_recurring']['transaction_amount'] : null;
			$tokens = isset( $titulares[ $id ]['nombre'] ) ? CN_Sin_Vincular::tokens_nombre( $titulares[ $id ]['nombre'] ) : array();
			sort( $tokens );
			$subs[ $id ] = array(
				'id'      => $id,
				'alta'    => isset( $it['date_created'] ) ? substr( (string) $it['date_created'], 0, 10 ) : '',
				'monto'   => $monto,
				'plan'    => CN_Webhook::plan_por_monto( $monto ),
				'email'   => $email,
				'titular' => count( $tokens ) >= 2 ? implode( ' ', $tokens ) : '',
				'ficha'   => $ficha ? (int) $ficha->id : null,
			);
		}
		$por_email = $por_titular = array();
		foreach ( $subs as $id => $s ) {
			if ( '' !== $s['email'] ) {
				$por_email[ $s['email'] ][] = $id;
			}
			if ( '' !== $s['titular'] ) {
				$por_titular[ $s['titular'] ][] = $id;
			}
		}
		$casos = array();
		foreach ( $por_email as $ids ) {
			if ( count( $ids ) > 1 ) {
				sort( $ids );
				$casos[ implode( '|', $ids ) ] = array( 'ids' => $ids, 'criterio' => 'email del pagador' );
			}
		}
		foreach ( $por_titular as $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			sort( $ids );
			$ya = false;
			foreach ( $casos as $c ) {
				$ya = $ya || ! array_diff( $ids, $c['ids'] ); // ya cubierto por un grupo de email.
			}
			if ( ! $ya ) {
				$casos[ implode( '|', $ids ) ] = array( 'ids' => $ids, 'criterio' => 'titular de la tarjeta' );
			}
		}
		$out = array();
		foreach ( $casos as $clave => $c ) {
			$lista  = array();
			$planes = array();
			foreach ( $c['ids'] as $id ) {
				$lista[]  = $subs[ $id ];
				$planes[] = $subs[ $id ]['plan'];
			}
			$mismo_plan = null !== $planes[0] && 1 === count( array_unique( $planes ) );
			$out[] = array(
				'clave'    => md5( $clave ),
				'tipo'     => ( 'email del pagador' === $c['criterio'] && $mismo_plan ) ? 'doble' : 'revisar',
				'criterio' => $c['criterio'],
				'subs'     => $lista,
			);
		}
		return $out;
	}

	/** Deja un aviso en el cartel Avisos por cada caso (1 por día por caso). Sin mails. Nunca lanza. */
	public static function avisar_dobles( $items, $socias, $emails = array(), $titulares = array() ) {
		try {
			foreach ( self::detectar_dobles( $items, $socias, $emails, $titulares ) as $c ) {
				$k = 'cn_dp_' . $c['clave'];
				if ( get_transient( $k ) ) {
					continue;
				}
				set_transient( $k, 1, self::AVISO_SEG );
				CN_Webhook::avisar_error_n8n( 'doble' === $c['tipo'] ? 'posible_doble_pago' : 'doble_suscripcion_revisar', array(
					'criterio' => $c['criterio'],
					'subs'     => array_map( function ( $s ) {
						return self::id_corto( $s['id'] ) . ' ' . $s['alta'] . ' $' . ( is_scalar( $s['monto'] ) ? $s['monto'] : '?' );
					}, $c['subs'] ),
					'aviso'    => 'Solo detectado: no se canceló ni reembolsó nada. Revisalo en Socias → Para contactar.',
				) );
			}
		} catch ( Throwable $e ) {
			// Accesorio: nunca debe afectar a quien lo llama (el sync).
		}
	}

	/**
	 * Arma la lista (puro: no consulta nada).
	 *
	 * @param array $cache     resultado de CN_Sin_Vincular::consultar() (ok)
	 * @param array $socias    fichas (id, nombre_apellido, estado, email, celular_hint, preapproval_id, plan, fecha_modificacion, trial_payment_id)
	 * @param array $titulares cache de titulares/cobros: preapproval_id => array( nombre, estado )
	 * @param array $casos     opción cn_casos_sin_acceso
	 * @return array{filas:array,dobles:array,sin_verificar:array,por_verificar:array}
	 */
	public static function armar( $cache, $socias, $titulares, $casos, $ahora = null ) {
		$ahora   = null === $ahora ? time() : (int) $ahora;
		$filas   = array();
		$sin_ver = array();
		$estado_de = function ( $id, $fila = null ) use ( $titulares ) {
			if ( isset( $titulares[ $id ]['estado'] ) ) {
				return (string) $titulares[ $id ]['estado'];
			}
			return $fila ? (string) ( $fila['titular_estado'] ?? '' ) : '';
		};
		$nombre_de = function ( $id, $fila = null ) use ( $titulares ) {
			if ( isset( $titulares[ $id ]['nombre'] ) ) {
				return (string) $titulares[ $id ]['nombre'];
			}
			return $fila ? (string) ( $fila['titular'] ?? '' ) : '';
		};
		$agregar = function ( $id, $dato ) use ( &$filas ) {
			if ( isset( $filas[ $id ] ) ) {
				$filas[ $id ]['motivo'] .= ' · ' . $dato['motivo'];
				return;
			}
			$filas[ $id ] = $dato;
		};
		$autorizadas = array();
		foreach ( (array) ( $cache['autorizadas'] ?? array() ) as $it ) {
			$autorizadas[ (string) $it['id'] ] = $it;
		}
		$emails = array();
		foreach ( (array) $cache['filas'] as $f ) {
			$id        = (string) $f['id'];
			$emails[ $id ] = (string) $f['email'];
			$alta_ts   = self::ts( $f['alta_iso'] ?? '' );
			if ( null === $alta_ts ) {
				$alta_ts = self::ts( $f['alta'] ) + DAY_IN_SECONDS; // solo trae el día: se cuenta como si fuera al final de ese día.
			}
			if ( $ahora - $alta_ts <= self::MIN_SEG ) {
				continue;
			}
			$pago = self::pagado( $estado_de( $id, $f ) );
			if ( null === $pago ) {
				$sin_ver[ $id ] = 'sub';
				continue;
			}
			if ( ! $pago ) {
				continue;
			}
			$cand = $f['candidatas'] ? $f['candidatas'][0] : null;
			$agregar( $id, array(
				'id' => $id, 'origen' => 'sin vincular', 'edad' => $ahora - $alta_ts, 'plan' => $f['plan'], 'monto' => $f['monto'],
				'titular' => $nombre_de( $id, $f ), 'email' => (string) $f['email'], 'candidatas' => $f['candidatas'],
				'motivo' => 'pagó y la suscripción no está vinculada a ninguna ficha (' . $f['motivo'] . ')',
			) );
		}
		foreach ( (array) $socias as $s ) {
			$pid = (string) ( $s->preapproval_id ?? '' );
			if ( '' === $pid || ! in_array( (string) $s->estado, array( 'cancelada', 'pausado' ), true ) || ! isset( $autorizadas[ $pid ] ) ) {
				continue;
			}
			$desde = self::ts( $s->fecha_modificacion ?? '' ) ?? self::ts( $autorizadas[ $pid ]['date_created'] );
			if ( null === $desde || $ahora - $desde <= self::MIN_SEG ) {
				continue;
			}
			$pago = self::pagado( $estado_de( $pid ) );
			if ( null === $pago ) {
				$sin_ver[ $pid ] = 'ficha';
				continue;
			}
			if ( ! $pago ) {
				continue;
			}
			$monto = $autorizadas[ $pid ]['auto_recurring']['transaction_amount'] ?? null;
			$agregar( $pid, array(
				'id' => $pid, 'origen' => 'ficha ' . $s->estado, 'edad' => $ahora - $desde, 'plan' => CN_Webhook::plan_por_monto( $monto ) ?? ( $s->plan ?? null ), 'monto' => $monto,
				'titular' => $nombre_de( $pid ), 'email' => CN_Vinculo::email_normalizado( $autorizadas[ $pid ]['payer_email'] ?? '' ) ?: (string) ( $s->email ?? '' ), 'candidatas' => array( $s ),
				'motivo' => 'la ficha está ' . $s->estado . ' pero la suscripción sigue authorized en Mercado Pago',
			) );
		}
		$con_pre   = $con_pago = array();
		foreach ( (array) $socias as $s ) {
			$con_pre[ (string) ( $s->preapproval_id ?? '' ) ] = true;
			$con_pago[ (string) ( $s->trial_payment_id ?? '' ) ] = true;
		}
		foreach ( (array) $casos as $c ) {
			if ( ! is_array( $c ) || empty( $c['id'] ) || $ahora - (int) $c['desde'] <= self::MIN_SEG ) {
				continue;
			}
			$id = (string) $c['id'];
			if ( 'preapproval' === $c['tipo'] ? isset( $con_pre[ $id ] ) : isset( $con_pago[ $id ] ) ) {
				continue; // ya tiene ficha: resuelto.
			}
			$agregar( $id, array(
				'id' => $id, 'origen' => 'cartel "casos sin acceso"', 'edad' => $ahora - (int) $c['desde'], 'plan' => null, 'monto' => null,
				'titular' => '', 'email' => '', 'candidatas' => array(), 'tipo_id' => $c['tipo'],
				'motivo' => 'caso registrado (' . $c['motivo'] . ')',
			) );
		}
		usort( $filas, function ( $a, $b ) {
			return $b['edad'] <=> $a['edad'];
		} );
		$todas = isset( $cache['autorizadas'] ) ? $cache['autorizadas'] : array();
		return array(
			'filas'         => array_slice( $filas, 0, self::MAX_FILAS ),
			'dobles'        => self::detectar_dobles( $todas, $socias, $emails, $titulares ),
			'sin_verificar' => $sin_ver,
			'por_verificar' => array_keys( array_filter( $sin_ver, function ( $v, $k ) use ( $titulares ) {
				return ! isset( $titulares[ $k ] ) || ! in_array( $titulares[ $k ]['estado'], array( 'ok', 'sin_cobro', 'sin_titular' ), true );
			}, ARRAY_FILTER_USE_BOTH ) ),
		);
	}

	// ---------------------------------------------------------------- lectura de la base y de los cache (sin MP)
	protected static function leer_socias() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, nombre_apellido, estado, email, celular_hint, preapproval_id, plan, fecha_modificacion, trial_payment_id FROM ' . CN_DB::tabla( 'miembros' ) );
		return ( '' === (string) $wpdb->last_error && is_array( $rows ) ) ? $rows : null;
	}

	protected static function titulares() {
		$t = get_transient( CN_Sin_Vincular::TRANSIENT_TITULARES );
		return is_array( $t ) ? $t : array();
	}

	/**
	 * Botón "Consultar": renueva el cache de CN_Sin_Vincular y verifica el cobro (solo GET, presupuesto de CN_Sin_Vincular) de las
	 * filas que lo necesitan. Es lo ÚNICO de esta pantalla que habla con Mercado Pago.
	 */
	public static function actualizar() {
		$r      = CN_Sin_Vincular::consultar( true );
		$socias = self::leer_socias();
		if ( empty( $r['ok'] ) || null === $socias ) {
			return $r;
		}
		$titulares = self::titulares();
		$a         = self::armar( $r, $socias, $titulares, (array) get_option( CN_Alertas::OPT_CASOS, array() ) );
		if ( $a['por_verificar'] ) {
			$token = CN_MP::get_access_token();
			$pres  = array( 'requests' => CN_Sin_Vincular::TITULAR_REQUESTS, 'espera' => CN_Sin_Vincular::TITULAR_ESPERA_SEG, 'hasta' => time() + CN_Sin_Vincular::TITULAR_TIEMPO_SEG );
			$nuevo = false;
			foreach ( $a['por_verificar'] as $id ) {
				$t = CN_Sin_Vincular::titular_mp( (string) $id, $token, $pres );
				if ( in_array( $t['estado'], array( 'ok', 'sin_cobro', 'sin_titular' ), true ) ) {
					$titulares[ $id ] = $t;
					$nuevo            = true;
				}
			}
			if ( $nuevo ) {
				set_transient( CN_Sin_Vincular::TRANSIENT_TITULARES, $titulares, CN_Sin_Vincular::TITULARES_SEG );
			}
		}
		self::avisar_dobles( $r['autorizadas'], $socias, array_column( $r['filas'], 'email', 'id' ), $titulares );
		return $r;
	}

	// ---------------------------------------------------------------- mensaje y presentación
	public static function antiguedad( $seg ) {
		$d = (int) floor( $seg / DAY_IN_SECONDS );
		$h = (int) floor( ( $seg % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		return $d > 0 ? $d . ' d ' . $h . ' h' : $h . ' h';
	}

	/** Texto sugerido para WhatsApp (solo se copia; nunca se envía). */
	public static function mensaje( $fila ) {
		$nombre = '' !== trim( (string) $fila['titular'] ) ? (string) $fila['titular'] : ( $fila['candidatas'] ? (string) $fila['candidatas'][0]->nombre_apellido : '' );
		$tokens = preg_split( '/\s+/', trim( $nombre ), -1, PREG_SPLIT_NO_EMPTY );
		$primero = $tokens ? ucfirst( strtolower( (string) $tokens[0] ) ) : '';
		return 'Hola' . ( '' !== $primero ? ' ' . $primero : '' ) . '! Te escribimos del Club Natureza 🌿 Vimos que tu pago se acreditó pero todavía no pudiste entrar al Club. ¿Me pasás el celular con el que querés ingresar así te activamos el acceso? ¡Gracias!';
	}

	public static function pagina() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$consultar = isset( $_POST['cn_pc_accion'] ) && 'consultar' === sanitize_key( wp_unslash( $_POST['cn_pc_accion'] ) ) && check_admin_referer( self::NONCE, 'cn_pc_nonce' );
		echo '<div class="wrap"><h1>Para contactar</h1>';
		echo '<p style="max-width:860px;">Gente que <strong>pagó</strong> y lleva <strong>más de 72 hs sin acceso</strong>: suscripciones sin vincular, fichas canceladas o pausadas con la suscripción todavía autorizada en Mercado Pago, y los casos del cartel. Solo lectura: <strong>no se envía nada</strong>; el botón copia un mensaje sugerido para que lo mandes vos por WhatsApp. Cargar esta página no consulta a Mercado Pago; "Consultar" sí (solo lectura) y guarda el resultado 10 minutos.</p>';
		echo '<form method="post" style="margin-bottom:12px;">';
		wp_nonce_field( self::NONCE, 'cn_pc_nonce' );
		echo '<button type="submit" name="cn_pc_accion" value="consultar" class="button button-primary">Consultar Mercado Pago</button></form>';
		$r = $consultar ? self::actualizar() : get_transient( CN_Sin_Vincular::TRANSIENT );
		if ( ! is_array( $r ) || empty( $r['ok'] ) ) {
			echo $consultar ? '<p style="color:#b32d2e;"><strong>' . esc_html( is_array( $r ) ? (string) $r['error'] : 'No se pudo consultar.' ) . '</strong> No se cambió nada.</p>' : '<p><em>Todavía no hay una consulta reciente: tocá "Consultar Mercado Pago".</em></p>';
			echo '</div>';
			return;
		}
		$socias = self::leer_socias();
		if ( null === $socias ) {
			echo '<p style="color:#b32d2e;"><strong>No se pudo leer la tabla de socias.</strong></p></div>';
			return;
		}
		$a = self::armar( $r, $socias, self::titulares(), (array) get_option( CN_Alertas::OPT_CASOS, array() ) );
		echo '<p class="description">Consulta de las ' . esc_html( (string) mysql2date( 'd/m/Y H:i', $r['fecha'] ) ) . ' UTC.</p>';
		if ( ! empty( $r['truncada'] ) ) {
			echo '<div class="notice notice-warning inline"><p>La lista de Mercado Pago se leyó incompleta (tope de 400 suscripciones).</p></div>';
		}
		if ( $a['sin_verificar'] ) {
			echo '<div class="notice notice-warning inline"><p>' . (int) count( $a['sin_verificar'] ) . ' suscripciones con más de 72 hs no se muestran porque todavía no se pudo verificar que pagaron (tope de consultas o Mercado Pago no respondió). Tocá "Consultar Mercado Pago" de nuevo.</p></div>';
		}
		if ( $a['dobles'] ) {
			echo '<h2>Posible doble pago / revisar</h2><p class="description">Solo detectado: no se canceló ni se reembolsó nada. "Posible doble pago" = mismo email y mismo plan. "Revisar" = planes distintos (puede ser legítimo) o coincidencia solo por titular.</p>';
			echo '<table class="widefat striped"><thead><tr><th>Tipo</th><th>Coincide por</th><th>Suscripciones (id · alta · monto · ficha)</th></tr></thead><tbody>';
			foreach ( $a['dobles'] as $c ) {
				$l = array();
				foreach ( $c['subs'] as $s ) {
					$l[] = '<code>' . esc_html( self::id_corto( $s['id'] ) ) . '</code> · ' . esc_html( $s['alta'] ) . ' · ' . esc_html( is_scalar( $s['monto'] ) ? '$' . number_format( (float) $s['monto'], 0, ',', '.' ) : '—' ) . ' · ' . ( null !== $s['ficha'] ? '#' . (int) $s['ficha'] : 'sin ficha' );
				}
				echo '<tr><td><strong>' . ( 'doble' === $c['tipo'] ? 'Posible doble pago' : 'Revisar' ) . '</strong></td><td>' . esc_html( $c['criterio'] ) . '</td><td>' . implode( '<br>', $l ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<h2>Para contactar (' . (int) count( $a['filas'] ) . ')</h2>';
		if ( ! $a['filas'] ) {
			echo '<p><em>No hay nadie para contactar con los datos de esta consulta.</em></p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Antigüedad</th><th>Plan</th><th>Titular</th><th>Email</th><th>Ficha candidata</th><th>Motivo</th><th></th></tr></thead><tbody>';
		foreach ( $a['filas'] as $f ) {
			echo '<tr><td>' . esc_html( self::antiguedad( $f['edad'] ) ) . '</td>';
			echo '<td>' . esc_html( null !== $f['plan'] ? (string) $f['plan'] : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== $f['titular'] ? $f['titular'] : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== $f['email'] ? $f['email'] : '—' ) . '</td><td>';
			if ( ! $f['candidatas'] ) {
				echo '—';
			}
			foreach ( $f['candidatas'] as $c ) {
				echo esc_html( '#' . (int) $c->id . ' ' . (string) $c->nombre_apellido . ' · ' . (string) $c->estado . ' · ' . CN_Vinculo::mascara_celular( $c->celular_hint ) ) . '<br>';
			}
			echo '</td><td><code>' . esc_html( self::id_corto( $f['id'] ) ) . '</code> ' . esc_html( $f['motivo'] ) . '<br><small>' . esc_html( $f['origen'] ) . '</small></td>';
			echo '<td><button type="button" class="button button-small cn-pc-copiar" data-msg="' . esc_attr( self::mensaje( $f ) ) . '">Copiar mensaje de WhatsApp</button></td></tr>';
		}
		echo '</tbody></table>';
		echo '<script>document.querySelectorAll(".cn-pc-copiar").forEach(function(b){b.addEventListener("click",function(){var t=b.getAttribute("data-msg");var ok=function(){b.textContent="¡Copiado!";};if(navigator.clipboard){navigator.clipboard.writeText(t).then(ok);}else{var a=document.createElement("textarea");a.value=t;document.body.appendChild(a);a.select();document.execCommand("copy");document.body.removeChild(a);ok();}});});</script></div>';
	}
}
CN_Para_Contactar::registrar(); // se engancha sola: borrar la pantalla = quitar el require_once y este archivo.
