<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Pantalla "Suscripciones sin vincular" (Socias → Suscripciones sin vincular). Solo manage_options + nonce. SOLO LECTURA.
 *
 * Lista las suscripciones authorized de Mercado Pago que ninguna ficha tiene vinculadas, con la/s ficha/s candidata/s y
 * un nivel de confianza derivado de las reglas que YA existen (ninguna regla nueva de match):
 *  - fuerte:    CN_Vinculo::proponer dio "email exacto" (una sola ficha con ese email) y CN_Vinculo_Lote::candidatas_seguras
 *               no la excluyó (ficha sin otra suscripción, plan conocido, celular válido, sin ficha parecida, sin repetir socia).
 *  - medio:     hay ficha/s por email pero hace falta que una persona elija: ficha parecida/gemela, varias fichas con ese email,
 *               la ficha ya tiene OTRA suscripción, sin celular válido, o la misma socia aparece en dos suscripciones.
 *  - medio (por nombre): sin candidata por email, pero el nombre del titular de la tarjeta (card.cardholder.name del primer
 *    cobro aprobado) coincide con el de una o varias fichas sin suscripción (tokens normalizados: todos los de la ficha están en
 *    los del titular, o al revés, con 2 o más en común). NUNCA es fuerte: siempre decide una persona.
 *  - sin match: no hay email, o ninguna ficha tiene ese email, o el monto no corresponde a un plan.
 *
 * El botón "Vincular" NO escribe nada: enlaza al bloque existente "Vincular suscripción de Mercado Pago" (Socias) con el id
 * (y la ficha, si hay una sola) precargados; ahí se verifica y se confirma de a una, con la lógica de siempre. Nunca en lote.
 * Mercado Pago: únicamente GET (los mismos que hace el Cruce). El resultado se guarda 10 min en un transient para no repetir
 * las consultas entre la vista y el CSV. Sin opciones ni migraciones.
 *
 * CSV (POST con nonce, solo manage_options): se emite en el hook admin_init (descargar_csv), ANTES de cualquier salida de
 * wp-admin (si se hiciera dentro de pagina(), admin-header.php ya mandó HTML y el CSV quedaría pegado en la página). Trae el
 * preapproval_id y el email del pagador COMPLETOS (hacen falta para ubicar a la persona en el panel de MP), plan, monto,
 * próximo cobro, alta, nivel, motivo y la candidata (id, nombre, estado), sin celular. Con BOM UTF-8 para que Excel lea bien las tildes.
 */
class CN_Sin_Vincular {
	const NONCE     = 'cn_sin_vincular';
	const TRANSIENT = 'cn_sin_vincular_cache';
	const CACHE_SEG = 600;
	const FUERTE    = 'fuerte';
	const MEDIO     = 'medio';
	const SIN_MATCH = 'sin_match';
	const MEDIO_NOMBRE = 'medio_nombre';
	const TRANSIENT_TITULARES = 'cn_sin_vincular_titulares'; // preapproval_id => array( nombre, estado ); 6 hs
	const TITULARES_SEG       = 21600;
	const TITULAR_REQUESTS    = 90; // pedidos a MP por consulta para buscar titulares (2 por suscripción)
	const TITULAR_ESPERA_SEG  = 20;
	const TITULAR_TIEMPO_SEG  = 60;
	const MOTIVO_NOMBRE       = 'nombre del titular coincide';
	/** Motivos de exclusión de candidatas_seguras que dejan la suscripción en nivel "medio" (hay ficha, decide una persona). */
	const MOTIVOS_MEDIO = array( 'ficha parecida #', 'la socia ya tiene otra suscripción', 'más de una socia', 'misma socia en más de una suscripción', 'sin celular válido' );
	/** En tests se pone en false para que el CSV no corte la ejecución. */
	public static $terminar = true;

	public static function registrar() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'descargar_csv' ) );
	}

	public static function menu() {
		add_submenu_page( 'cn-socias', 'Suscripciones sin vincular', 'Suscripciones sin vincular', 'manage_options', 'cn-sin-vincular', array( __CLASS__, 'pagina' ) );
	}

	// ---------------------------------------------------------------- máscaras (pantalla)
	/** Id enmascarado: 6 caracteres + "...". */
	public static function mascara_id( $id ) {
		return substr( (string) $id, 0, 6 ) . '...';
	}

	// ---------------------------------------------------------------- cálculo (SOLO LECTURA)
	/**
	 * Nivel de cada suscripción sin vincular a partir de proponer() + candidatas_seguras() (puro: no consulta nada).
	 *
	 * @param array $filas   filas de CN_Vinculo::proponer
	 * @param array $cand    resultado de CN_Vinculo_Lote::candidatas_seguras sobre los mismos datos
	 * @return array lista de array{id,plan,monto,proximo_cobro,alta,email,nivel,motivo,candidatas:array<object>}
	 */
	public static function clasificar( $filas, $cand ) {
		$motivo_de = array();
		foreach ( (array) $cand['excluidas'] as $x ) {
			$motivo_de[ (string) $x['id'] ] = (string) $x['motivo'];
		}
		$out = array();
		foreach ( (array) $filas as $f ) {
			if ( CN_Vinculo::YA_VINCULADA === $f['confianza'] ) {
				continue; // ya tiene ficha: no es "sin vincular".
			}
			$id     = (string) $f['id'];
			$motivo = isset( $motivo_de[ $id ] ) ? $motivo_de[ $id ] : '';
			$cands  = $f['socia'] ? array( $f['socia'] ) : (array) $f['candidatas'];
			if ( CN_Vinculo::EMAIL_EXACTO === $f['confianza'] && '' === $motivo ) {
				$nivel  = self::FUERTE;
				$motivo = 'email exacto, única ficha, sin otra suscripción ni ficha parecida';
			} elseif ( $cands && self::es_motivo_medio( $motivo ) ) {
				$nivel = self::MEDIO;
			} else {
				$nivel = self::SIN_MATCH;
				if ( '' === $motivo ) {
					$motivo = CN_Vinculo::SIN_EMAIL === $f['confianza'] ? 'sin email' : 'sin match';
				}
				if ( CN_Vinculo::SIN_MATCH === $f['confianza'] ) {
					$cands = array(); // sin ficha: no hay nada que mostrar.
				}
			}
			$out[] = array(
				'id'            => $id,
				'plan'          => $f['plan'],
				'monto'         => $f['monto'],
				'proximo_cobro' => (string) $f['proximo_cobro'],
				'alta'          => substr( (string) $f['date_created'], 0, 10 ),
				'alta_iso'      => (string) $f['date_created'], // fecha y hora completas (la usa "Para contactar" para medir antigüedad).
				'email'         => (string) $f['email'],
				'nivel'         => $nivel,
				'motivo'        => $motivo,
				'candidatas'    => array_values( $cands ),
				'titular'       => '',
				'titular_estado' => '',
			);
		}
		return self::ordenar( $out );
	}

	/** Orden: fuerte, medio, medio (por nombre), sin match; dentro de cada nivel por alta. */
	public static function ordenar( $out ) {
		usort( $out, function ( $a, $b ) {
			$orden = array( self::FUERTE => 0, self::MEDIO => 1, self::MEDIO_NOMBRE => 2, self::SIN_MATCH => 3 );
			return ( $orden[ $a['nivel'] ] <=> $orden[ $b['nivel'] ] ) ?: strcmp( $a['alta'], $b['alta'] );
		} );
		return $out;
	}

	protected static function es_motivo_medio( $motivo ) {
		foreach ( self::MOTIVOS_MEDIO as $m ) {
			if ( 0 === strpos( (string) $motivo, $m ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Consulta MP (solo GET) y la tabla de socias, y arma la lista. Guarda el resultado 10 min.
	 *
	 * @return array{ok:bool,error:string,filas:array,truncada:bool,tope:bool,fecha:string}
	 */
	public static function consultar( $forzar = false ) {
		global $wpdb;
		if ( ! $forzar ) {
			$c = get_transient( self::TRANSIENT );
			if ( is_array( $c ) && ! empty( $c['ok'] ) ) {
				return $c;
			}
		}
		$r = array( 'ok' => false, 'error' => '', 'filas' => array(), 'truncada' => false, 'tope' => false, 'fecha' => current_time( 'mysql', true ) );
		if ( '' === CN_MP::get_access_token() ) {
			$r['error'] = 'Falta el token de Suscripciones de Mercado Pago (Config).';
			return $r;
		}
		$l = CN_Vinculo::listar_authorized();
		if ( empty( $l['ok'] ) ) {
			$r['error'] = (string) $l['error'];
			return $r;
		}
		$socias = $wpdb->get_results( 'SELECT ' . CN_Vinculo_Manual::COLS . ' FROM ' . CN_DB::tabla( 'miembros' ) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $socias ) ) {
			$r['error'] = 'No se pudo leer la tabla de socias.';
			return $r;
		}
		$pagos = CN_Vinculo::cargar_pagos_aprobados( 40, 3 );
		$prop  = CN_Vinculo::proponer( $l['items'], $socias, $pagos );
		$cand  = CN_Vinculo_Lote::candidatas_seguras( $l['items'], $socias, $pagos );
		$r['ok']       = true;
		$r['filas']    = self::agregar_titulares( self::clasificar( $prop['filas'], $cand ), $socias );
		$r['autorizadas'] = CN_Para_Contactar::resumir( $l['items'] ); // para "Para contactar" (detección de doble pago): sin pedir nada más a MP.
		$r['truncada'] = ! empty( $l['truncada'] );
		$r['tope']     = ! empty( $prop['tope_alcanzado'] ) || ! empty( $cand['tope_alcanzado'] );
		set_transient( self::TRANSIENT, $r, self::CACHE_SEG );
		return $r;
	}

	// ---------------------------------------------------------------- titular de la tarjeta y match por nombre (SOLO LECTURA)
	/** Tokens del nombre: minúsculas, sin tildes, solo letras, de 2 o más letras. */
	public static function tokens_nombre( $nombre ) {
		$n = (string) $nombre;
		if ( function_exists( 'remove_accents' ) ) {
			$n = remove_accents( $n );
		}
		$n = strtolower( $n );
		$n = preg_replace( '/[^a-z]+/', ' ', $n );
		$t = array();
		foreach ( preg_split( '/\s+/', trim( (string) $n ), -1, PREG_SPLIT_NO_EMPTY ) as $tok ) {
			if ( strlen( $tok ) >= 2 ) {
				$t[ $tok ] = true;
			}
		}
		return array_keys( $t );
	}

	/** ¿Coinciden titular y ficha? Todos los tokens de la ficha en los del titular, o al revés, con 2 o más en común. */
	public static function coincide_nombre( $titular, $ficha ) {
		$a = self::tokens_nombre( $titular );
		$b = self::tokens_nombre( $ficha );
		if ( count( $a ) < 2 || count( $b ) < 2 ) {
			return false;
		}
		$comunes = count( array_intersect( $a, $b ) );
		if ( $comunes < 2 ) {
			return false;
		}
		return ! array_diff( $b, $a ) || ! array_diff( $a, $b );
	}

	/**
	 * Titular de la tarjeta de una suscripción (solo GET): primer cobro aprobado de /authorized_payments/search y, de ese pago,
	 * card.cardholder.name. $pres es el presupuesto compartido de la consulta.
	 *
	 * @return array{nombre:string,estado:string} estado: ok | sin_cobro | sin_titular | error | tope
	 */
	public static function titular_mp( $id, $token, &$pres ) {
		$r = CN_MP::get_con_reintentos( '/authorized_payments/search?preapproval_id=' . rawurlencode( $id ), $token, $pres );
		if ( ! $r['ok'] ) {
			return array( 'nombre' => '', 'estado' => 'tope' === $r['http'] ? 'tope' : 'error' );
		}
		$pid = '';
		foreach ( ( isset( $r['data']['results'] ) && is_array( $r['data']['results'] ) ) ? $r['data']['results'] : array() as $it ) {
			$cand = isset( $it['payment']['id'] ) ? (string) $it['payment']['id'] : '';
			if ( '' !== $cand && ctype_digit( $cand ) && ( ! isset( $it['payment']['status'] ) || 'approved' === $it['payment']['status'] ) ) {
				$pid = $cand;
				break;
			}
		}
		if ( '' === $pid ) {
			return array( 'nombre' => '', 'estado' => 'sin_cobro' );
		}
		$p = CN_MP::get_con_reintentos( '/v1/payments/' . rawurlencode( $pid ), $token, $pres );
		if ( ! $p['ok'] ) {
			return array( 'nombre' => '', 'estado' => 'tope' === $p['http'] ? 'tope' : 'error' );
		}
		$nombre = isset( $p['data']['card']['cardholder']['name'] ) && is_scalar( $p['data']['card']['cardholder']['name'] ) ? trim( sanitize_text_field( (string) $p['data']['card']['cardholder']['name'] ) ) : '';
		return array( 'nombre' => $nombre, 'estado' => '' !== $nombre ? 'ok' : 'sin_titular' );
	}

	/**
	 * Para cada fila SIN candidata por email (y con plan conocido): busca el titular (con cache de 6 hs por preapproval_id) y
	 * propone fichas sin suscripción cuyo nombre coincide. Nunca sube a "fuerte". Solo GET; cero escrituras.
	 */
	public static function agregar_titulares( $filas, $socias ) {
		$cache = get_transient( self::TRANSIENT_TITULARES );
		$cache = is_array( $cache ) ? $cache : array();
		$token = CN_MP::get_access_token();
		$pres  = array( 'requests' => self::TITULAR_REQUESTS, 'espera' => self::TITULAR_ESPERA_SEG, 'hasta' => time() + self::TITULAR_TIEMPO_SEG );
		$nuevo = false;
		foreach ( $filas as &$f ) {
			if ( $f['candidatas'] ) {
				continue; // ya hay ficha por email: el titular no se consulta.
			}
			$id = (string) $f['id'];
			if ( isset( $cache[ $id ] ) ) {
				$t = $cache[ $id ];
			} else {
				$t = self::titular_mp( $id, $token, $pres );
				if ( in_array( $t['estado'], array( 'ok', 'sin_cobro', 'sin_titular' ), true ) ) {
					$cache[ $id ] = $t; // respuestas de MP se recuerdan 6 hs; errores y tope se reintentan en la próxima consulta.
					$nuevo        = true;
				}
			}
			$f['titular']        = (string) $t['nombre'];
			$f['titular_estado'] = (string) $t['estado'];
			if ( '' === $f['titular'] || null === $f['plan'] ) {
				continue;
			}
			$cands = array();
			foreach ( (array) $socias as $so ) {
				if ( '' !== (string) ( isset( $so->preapproval_id ) ? $so->preapproval_id : '' ) ) {
					continue; // con suscripción: nunca candidata por nombre.
				}
				if ( self::coincide_nombre( $f['titular'], isset( $so->nombre_apellido ) ? $so->nombre_apellido : '' ) ) {
					$cands[] = $so;
				}
			}
			if ( $cands ) {
				$f['nivel']      = self::MEDIO_NOMBRE;
				$f['motivo']     = self::MOTIVO_NOMBRE;
				$f['candidatas'] = $cands;
			}
		}
		unset( $f );
		if ( $nuevo ) {
			set_transient( self::TRANSIENT_TITULARES, $cache, self::TITULARES_SEG );
		}
		return self::ordenar( $filas );
	}

	/** Texto de la columna "Titular (MP)". */
	public static function titular_texto( $f ) {
		if ( '' !== (string) $f['titular'] ) {
			return (string) $f['titular'];
		}
		switch ( (string) $f['titular_estado'] ) {
			case 'sin_cobro':
				return 'sin titular (sin cobro aprobado)';
			case 'sin_titular':
				return 'sin titular (el cobro no lo trae)';
			case 'error':
				return 'sin titular (MP no respondió)';
			case 'tope':
				return 'sin titular (tope de pedidos; volvé a consultar)';
			default:
				return '—';
		}
	}

	// ---------------------------------------------------------------- enlaces y CSV
	/** URL del bloque existente "Vincular suscripción de Mercado Pago" con el id (y la ficha si hay una sola) precargados. */
	public static function url_vincular( $fila ) {
		$args = array( 'page' => 'cn-socias', 'cn_vincular_id' => (string) $fila['id'] );
		if ( 1 === count( $fila['candidatas'] ) && self::SIN_MATCH !== $fila['nivel'] ) {
			$args['cn_vincular_socia'] = (int) $fila['candidatas'][0]->id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) ) . '#cn-vincular-mp';
	}

	/** CSV completo (id y email del pagador sin enmascarar; la candidata sin celular). Devuelve el texto con BOM UTF-8. */
	public static function csv( $filas ) {
		$h = fopen( 'php://temp', 'r+' );
		fwrite( $h, "\xEF\xBB\xBF" );
		fputcsv( $h, array( 'preapproval_id', 'email_pagador', 'plan', 'monto', 'proximo_cobro', 'alta_mp', 'nivel', 'motivo', 'candidata_id', 'candidata_nombre', 'candidata_estado', 'titular_tarjeta', 'candidata_celular_ultimos4' ), ',', '"', '' );
		foreach ( (array) $filas as $f ) {
			$ids = $noms = $ests = $hints = array();
			foreach ( $f['candidatas'] as $c ) {
				$ids[]   = (int) $c->id;
				$noms[]  = (string) $c->nombre_apellido;
				$ests[]  = (string) $c->estado;
				$hints[] = substr( (string) ( isset( $c->celular_hint ) ? $c->celular_hint : '' ), -4 ); // solo los últimos 4 dígitos
			}
			fputcsv( $h, array(
				(string) $f['id'],
				(string) $f['email'],
				null !== $f['plan'] ? (string) $f['plan'] : '',
				is_scalar( $f['monto'] ) ? (string) $f['monto'] : '',
				(string) $f['proximo_cobro'],
				(string) $f['alta'],
				(string) $f['nivel'],
				(string) $f['motivo'],
				implode( ' | ', $ids ),
				implode( ' | ', $noms ),
				implode( ' | ', $ests ),
				(string) ( isset( $f['titular'] ) ? $f['titular'] : '' ),
				implode( ' | ', $hints ),
			), ',', '"', '' );
		}
		rewind( $h );
		$csv = stream_get_contents( $h );
		fclose( $h );
		return $csv;
	}

	/**
	 * Hook admin_init: descarga del CSV antes de que wp-admin emita nada. Solo actúa en esta página, con POST cn_sv_accion=csv,
	 * manage_options y nonce válido; si consultar() falla no emite nada (pagina() muestra el error de siempre).
	 */
	public static function descargar_csv() {
		if ( ! isset( $_GET['page'], $_POST['cn_sv_accion'] ) || 'cn-sin-vincular' !== (string) $_GET['page'] || 'csv' !== sanitize_key( wp_unslash( $_POST['cn_sv_accion'] ) ) ) {
			return false;
		}
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::NONCE, 'cn_sv_nonce' ) ) {
			return false;
		}
		$r = self::consultar();
		if ( empty( $r['ok'] ) ) {
			return false;
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="suscripciones-sin-vincular-' . gmdate( 'Ymd-Hi' ) . '.csv"' );
		}
		echo self::csv( $r['filas'] );
		if ( self::$terminar ) {
			exit;
		}
		return true;
	}

	// ---------------------------------------------------------------- página
	public static function pagina() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$accion = '';
		if ( isset( $_POST['cn_sv_accion'] ) && check_admin_referer( self::NONCE, 'cn_sv_nonce' ) ) {
			$accion = sanitize_key( wp_unslash( $_POST['cn_sv_accion'] ) );
		}
		echo '<div class="wrap"><h1>Suscripciones sin vincular</h1>';
		echo '<p style="max-width:860px;">Suscripciones <strong>autorizadas</strong> en Mercado Pago que ninguna ficha tiene vinculadas, con la ficha candidata según el <strong>email del pagador</strong> (las mismas reglas del Cruce y de "Vincular en lote") y, si por email no hay ninguna, según el <strong>nombre del titular de la tarjeta</strong> (nunca pasa de "medio": siempre decide una persona). Solo lectura: acá no se vincula nada. <strong>Vincular</strong> lleva al bloque de siempre en Socias, de a una, donde se verifica y se confirma. El resultado se guarda 10 minutos.</p>';
		echo '<form method="post" style="margin-bottom:12px;">';
		wp_nonce_field( self::NONCE, 'cn_sv_nonce' );
		echo '<button type="submit" name="cn_sv_accion" value="consultar" class="button button-primary">Consultar Mercado Pago</button> ';
		echo '<button type="submit" name="cn_sv_accion" value="csv" class="button">Descargar CSV (datos completos)</button>';
		echo '</form>';
		if ( 'csv' === $accion ) {
			// Si se llegó acá con "csv" es porque descargar_csv() (admin_init) no pudo emitir el archivo: se muestra el error de la consulta.
			$r = self::consultar();
		} elseif ( 'consultar' !== $accion ) {
			$c = get_transient( self::TRANSIENT );
			if ( ! is_array( $c ) || empty( $c['ok'] ) ) {
				echo '</div>';
				return;
			}
			$r = $c;
			echo '<p class="description">Mostrando la consulta de las ' . esc_html( (string) mysql2date( 'd/m/Y H:i', $r['fecha'] ) ) . ' UTC (se guarda 10 minutos). Volvé a consultar para actualizar.</p>';
		} else {
			$r = self::consultar( true );
		}
		if ( ! $r['ok'] ) {
			echo '<p style="color:#b32d2e;"><strong>' . esc_html( $r['error'] ) . '</strong> No se cambió nada.</p></div>';
			return;
		}
		if ( $r['truncada'] ) {
			echo '<div class="notice notice-warning inline"><p>La lista de Mercado Pago se leyó incompleta (tope de 400 suscripciones).</p></div>';
		}
		if ( $r['tope'] ) {
			echo '<div class="notice notice-warning inline"><p>Se llegó al tope de consultas extra a Mercado Pago para buscar emails: algunas quedaron como "sin email" por eso.</p></div>';
		}
		$n = array( self::FUERTE => 0, self::MEDIO => 0, self::MEDIO_NOMBRE => 0, self::SIN_MATCH => 0 );
		foreach ( $r['filas'] as $f ) {
			$n[ $f['nivel'] ]++;
		}
		echo '<p><strong>' . (int) count( $r['filas'] ) . ' sin vincular</strong> · fuerte: ' . (int) $n[ self::FUERTE ] . ' · medio: ' . (int) $n[ self::MEDIO ] . ' · medio (por nombre): ' . (int) $n[ self::MEDIO_NOMBRE ] . ' · sin match: ' . (int) $n[ self::SIN_MATCH ] . '</p>';
		if ( ! $r['filas'] ) {
			echo '<p><em>No hay suscripciones autorizadas sin vincular.</em></p></div>';
			return;
		}
		$etiq = array( self::FUERTE => 'Fuerte', self::MEDIO => 'Medio (elegir ficha)', self::MEDIO_NOMBRE => 'Medio (por nombre)', self::SIN_MATCH => 'Sin match' );
		echo '<table class="widefat striped"><thead><tr><th>Suscripción</th><th>Plan</th><th>Monto</th><th>Próximo cobro</th><th>Alta en MP</th><th>Email del pagador</th><th>Titular (MP)</th><th>Ficha candidata</th><th>Nivel</th><th></th></tr></thead><tbody>';
		foreach ( $r['filas'] as $f ) {
			echo '<tr><td><code>' . esc_html( self::mascara_id( $f['id'] ) ) . '</code></td>';
			echo '<td>' . esc_html( null !== $f['plan'] ? (string) $f['plan'] : '?' ) . '</td>';
			echo '<td>' . esc_html( is_scalar( $f['monto'] ) ? '$' . number_format( (float) $f['monto'], 0, ',', '.' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== $f['proximo_cobro'] ? $f['proximo_cobro'] : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== $f['alta'] ? $f['alta'] : '—' ) . '</td>';
			echo '<td>' . esc_html( CN_Vinculo_Manual::mascara_email( $f['email'] ) ) . '</td>';
			echo '<td>' . esc_html( self::titular_texto( $f ) ) . '</td>';
			echo '<td>';
			if ( ! $f['candidatas'] ) {
				echo '—';
			}
			foreach ( $f['candidatas'] as $c ) {
				echo esc_html( '#' . (int) $c->id . ' ' . (string) $c->nombre_apellido . ' · ' . (string) $c->estado . ' · ' . CN_Vinculo::mascara_celular( $c->celular_hint ) ) . '<br>';
			}
			echo '<small>' . esc_html( $f['motivo'] ) . '</small></td>';
			echo '<td>' . esc_html( $etiq[ $f['nivel'] ] ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( self::url_vincular( $f ) ) . '">Vincular</a>';
			foreach ( $f['candidatas'] as $c ) { // ficha que ya tiene OTRA suscripción vinculada: la socia pudo volver a suscribirse.
				if ( '' !== (string) $c->preapproval_id && (string) $c->preapproval_id !== (string) $f['id'] ) {
					echo '<br><a class="button button-small" href="' . esc_url( CN_Reemplazo::url( $f['id'], (int) $c->id ) ) . '">Reemplazar (#' . (int) $c->id . ')</a>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
CN_Sin_Vincular::registrar(); // se engancha sola: borrar la pantalla = quitar el require_once y este archivo.
