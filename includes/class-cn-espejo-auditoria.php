<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * "Auditoría del espejo" (Socias → Auditoría del espejo). SOLO LECTURA, solo manage_options + nonce.
 *
 * Responde "¿qué haría el espejo HOY con cada ficha?" sin esperar a que el sync llegue a cada una: recorre TODAS las
 * fichas (no de a 20), consulta MP en vivo (únicamente GET, con CN_MP::get_con_reintentos y presupuesto propio) y
 * decide con la MISMA lógica del espejo (CN_Espejo_MP::decidir: en alcance, authorized/paused/cancelled, período
 * pagado, pago reciente), en simulación pura: no encola, no escribe en cn_miembros ni en mp_log, no toca opciones ni
 * transients, no manda mails ni eventos Meta, y no depende del modo del espejo (sirve también con el espejo apagado).
 *
 * Por tandas: hasta FICHAS_POR_TANDA fichas por clic, con tope de pedidos y de tiempo. Si no alcanza, muestra la tanda
 * como "parcial" y el botón "Continuar" sigue desde el cursor (un campo del formulario: nada queda guardado).
 * Las fichas fuera de alcance obvias (sin suscripción, sin plan, canceladas, trial) se clasifican sin consultar MP.
 *
 * Hereda de CN_Espejo_MP solo para reutilizar decidir(), ts_utc() y las constantes; no cambia nada de esa clase.
 */
class CN_Espejo_Auditoria extends CN_Espejo_MP {
	const NONCE_AU         = 'cn_espejo_auditoria';
	const PAGINA           = 'cn-espejo-auditoria';
	const FICHAS_POR_TANDA = 40;
	const TOPE_REQUESTS    = 90; // GET /preapproval + (si hace falta) /authorized_payments por ficha
	const TOPE_ESPERA_SEG  = 20;
	const TOPE_TIEMPO_SEG  = 60;
	const COLS_AU          = 'id, nombre_apellido, estado, preapproval_id, plan, acceso_hasta, baja_en, fecha_fin_trial, celular_hint';

	public static function registrar() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	public static function menu() {
		add_submenu_page( 'cn-socias', 'Auditoría del espejo', 'Auditoría del espejo', 'manage_options', self::PAGINA, array( __CLASS__, 'pagina' ) );
	}

	/** ¿La baja propuesta queda ANTES del acceso vigente? Nunca debería: sería cortar un acceso pago. */
	public static function corta_acceso( $baja_ts, $acceso_hasta ) {
		$acc = self::ts_utc( (string) $acceso_hasta );
		return null !== $acc && is_int( $baja_ts ) && $baja_ts < $acc;
	}

	/** Motivo "fuera de alcance" que no necesita consultar MP; '' si la ficha sí está en alcance. */
	protected static function fuera_sin_mp( $f ) {
		if ( '' === (string) ( $f->preapproval_id ?? '' ) ) {
			return 'sin suscripción vinculada';
		}
		if ( '' === (string) ( $f->plan ?? '' ) ) {
			return 'ficha sin plan (no la vinculó el sistema)';
		}
		if ( 'cancelada' === (string) $f->estado ) {
			return 'ficha cancelada';
		}
		if ( ! empty( $f->fecha_fin_trial ) ) {
			return 'ficha en trial';
		}
		return '';
	}

	/** Fila de resultado para la tabla. */
	protected static function fila( $f, $bucket, $decision_txt, $extra = array() ) {
		return $extra + array(
			'id'           => (int) $f->id,
			'nombre'       => (string) $f->nombre_apellido,
			'estado_ficha' => (string) $f->estado,
			'plan'         => '' !== (string) ( $f->plan ?? '' ) ? (string) $f->plan : '—',
			'status_mp'    => '',
			'acceso_hasta' => '' !== (string) ( $f->acceso_hasta ?? '' ) ? (string) $f->acceso_hasta : '',
			'baja_en'      => '' !== (string) ( $f->baja_en ?? '' ) ? (string) $f->baja_en : '',
			'bucket'       => $bucket,
			'decision'     => $decision_txt,
			'baja_prop'    => '',
			'corta_acceso' => false,
		);
	}

	/**
	 * Una tanda de auditoría (SOLO LECTURA). $cursor = última ficha ya auditada (0 = desde el principio).
	 *
	 * @return array{ok:bool,error:string,filas:array,conteos:array<string,int>,parcial:bool,cursor:int,motivo_parcial:string}
	 */
	public static function auditar( $cursor = 0 ) {
		global $wpdb;
		$res = array( 'ok' => false, 'error' => '', 'filas' => array(), 'conteos' => array( 'baja' => 0, 'revertir' => 0, 'omitida' => 0, 'al_dia' => 0, 'fuera' => 0, 'sin_datos' => 0 ), 'parcial' => false, 'cursor' => (int) $cursor, 'motivo_parcial' => '' );
		if ( '' === CN_MP::get_access_token() ) {
			$res['error'] = 'Falta el token de Suscripciones de Mercado Pago (Config). No se consultó nada.';
			return $res;
		}
		$fichas = $wpdb->get_results( $wpdb->prepare(
			'SELECT ' . self::COLS_AU . ' FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE id > %d ORDER BY id ASC LIMIT %d',
			(int) $cursor,
			self::FICHAS_POR_TANDA + 1 // una de más, solo para saber si quedan.
		) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $fichas ) ) {
			$res['error'] = 'No se pudo leer la tabla de socias.';
			return $res;
		}
		$res['ok'] = true;
		if ( count( $fichas ) > self::FICHAS_POR_TANDA ) {
			$res['parcial']        = true;
			$res['motivo_parcial'] = 'quedan más fichas';
			$fichas                = array_slice( $fichas, 0, self::FICHAS_POR_TANDA );
		}
		$pres = array( 'requests' => self::TOPE_REQUESTS, 'espera' => self::TOPE_ESPERA_SEG, 'hasta' => time() + self::TOPE_TIEMPO_SEG );
		foreach ( $fichas as $f ) {
			$fuera = self::fuera_sin_mp( $f );
			if ( '' !== $fuera ) {
				$res['filas'][] = self::fila( $f, 'fuera', 'fuera de alcance: ' . $fuera );
				$res['cursor']  = (int) $f->id;
				continue;
			}
			if ( $pres['requests'] <= 1 || time() >= $pres['hasta'] ) { // decidir() puede necesitar un pedido más (cobros): mejor cortar antes.
				$res['parcial']        = true;
				$res['motivo_parcial'] = 'se llegó al tope de pedidos o de tiempo de esta tanda';
				break; // el cursor queda en la anterior: esta ficha entra en la próxima tanda.
			}
			$pid = (string) $f->preapproval_id;
			$h   = CN_MP::get_con_reintentos( '/preapproval/' . rawurlencode( $pid ), CN_MP::get_access_token(), $pres );
			if ( ! $h['ok'] && 'tope' === $h['http'] ) {
				$res['parcial']        = true;
				$res['motivo_parcial'] = 'se llegó al tope de pedidos o de tiempo de esta tanda';
				break;
			}
			$res['cursor'] = (int) $f->id;
			if ( ! $h['ok'] ) {
				$res['filas'][] = self::fila( $f, 'sin_datos', 'sin datos: Mercado Pago no respondió por esta suscripción (se puede reintentar)' );
				continue;
			}
			$d     = self::decidir( $f, $pid, $h['data'], $pres ); // la MISMA decisión del espejo, sin encolar ni loguear.
			$extra = array( 'status_mp' => $d['status'] );
			if ( 'programar' === $d['decision'] ) {
				$baja_txt       = gmdate( 'Y-m-d H:i', (int) $d['baja_ts'] );
				$res['filas'][] = self::fila( $f, 'baja', 'propondría baja (baja_en ' . $baja_txt . ' UTC): ' . $d['motivo'], $extra + array( 'baja_prop' => $baja_txt, 'corta_acceso' => self::corta_acceso( $d['baja_ts'], $f->acceso_hasta ) ) );
			} elseif ( 'revertir' === $d['decision'] ) {
				$res['filas'][] = self::fila( $f, 'revertir', 'propondría revertir la baja programada: ' . $d['motivo'], $extra );
			} elseif ( 'omitida' === $d['decision'] && 'mp_ambiguo' !== $d['codigo'] ) {
				$res['filas'][] = self::fila( $f, 'omitida', 'omitida: ' . $d['codigo'] . ' (' . $d['motivo'] . ')', $extra );
			} elseif ( 'omitida' === $d['decision'] ) {
				$res['filas'][] = self::fila( $f, 'sin_datos', 'sin datos: ' . $d['motivo'], $extra );
			} elseif ( 'al_dia' === $d['codigo'] ) {
				$res['filas'][] = self::fila( $f, 'al_dia', 'al día: ' . $d['motivo'], $extra );
			} elseif ( 'fuera_alcance' === $d['decision'] ) {
				$res['filas'][] = self::fila( $f, 'fuera', 'fuera de alcance: ' . $d['motivo'], $extra );
			} else { // sin_cambios: baja ya programada o ficha no activa.
				$res['filas'][] = self::fila( $f, 'fuera', 'sin acción: ' . $d['motivo'] . ( 'ficha_no_activa' === $d['codigo'] ? ' (estado ' . (string) $f->estado . ')' : '' ), $extra );
			}
		}
		foreach ( $res['filas'] as $fila ) {
			$res['conteos'][ $fila['bucket'] ]++;
		}
		return $res;
	}

	// ------------------------------------------------------------------ pantalla

	public static function pagina() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$res = null;
		if ( isset( $_POST['cn_au_accion'] ) && check_admin_referer( self::NONCE_AU, 'cn_au_nonce' ) ) {
			$cursor = 'continuar' === sanitize_key( wp_unslash( $_POST['cn_au_accion'] ) ) ? absint( $_POST['cn_au_cursor'] ?? 0 ) : 0;
			$res    = self::auditar( $cursor );
		}
		echo '<div class="wrap"><h1>Auditoría del espejo</h1>';
		echo '<p style="max-width:860px;">Muestra qué haría hoy el <strong>espejo de Mercado Pago</strong> con cada ficha, según el estado EN VIVO de su suscripción, con la misma lógica de decisión del espejo y sin esperar a que el sync recorra la rotación. <strong>Solo lectura</strong>: no cambia ninguna ficha, no registra nada y no depende del modo del espejo (hoy: ' . esc_html( self::modo() ) . '). Mercado Pago se consulta únicamente con GET, por tandas de ' . (int) self::FICHAS_POR_TANDA . ' fichas.</p>';
		echo '<form method="post" style="margin-bottom:12px;">';
		wp_nonce_field( self::NONCE_AU, 'cn_au_nonce' );
		echo '<button type="submit" name="cn_au_accion" value="auditar" class="button button-primary">Auditar ahora</button>';
		echo '</form>';
		if ( null === $res ) {
			echo '</div>';
			return;
		}
		if ( ! $res['ok'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $res['error'] ) . '</p></div></div>';
			return;
		}
		$c = $res['conteos'];
		echo '<p><strong>' . (int) count( $res['filas'] ) . ' fichas en esta tanda</strong> · propondría baja: ' . (int) $c['baja'] . ' · propondría revertir: ' . (int) $c['revertir'] . ' · omitidas: ' . (int) $c['omitida'] . ' · al día: ' . (int) $c['al_dia'] . ' · fuera de alcance / sin acción: ' . (int) $c['fuera'] . ' · sin datos de MP: ' . (int) $c['sin_datos'] . '</p>';
		if ( $res['parcial'] ) {
			echo '<div class="notice notice-warning inline"><p><strong>Tanda parcial</strong> (' . esc_html( $res['motivo_parcial'] ) . '): faltan fichas después de la #' . (int) $res['cursor'] . '. Cada tanda muestra solo sus fichas (no se guarda nada entre tandas).</p></div>';
			echo '<form method="post" style="margin-bottom:12px;">';
			wp_nonce_field( self::NONCE_AU, 'cn_au_nonce' );
			echo '<input type="hidden" name="cn_au_cursor" value="' . (int) $res['cursor'] . '">';
			echo '<button type="submit" name="cn_au_accion" value="continuar" class="button">Continuar desde la ficha #' . (int) $res['cursor'] . '</button>';
			echo '</form>';
		}
		$rojas = count( array_filter( $res['filas'], function ( $x ) {
			return ! empty( $x['corta_acceso'] );
		} ) );
		if ( $rojas > 0 ) {
			echo '<div class="notice notice-error inline"><p><strong>Atención:</strong> ' . (int) $rojas . ' propuesta(s) de baja quedarían ANTES del acceso vigente. Eso nunca debería pasar: revisarlas antes de prender el espejo.</p></div>';
		}
		$etiq = array( '' => 'Todas', 'baja' => 'Propondría baja', 'revertir' => 'Propondría revertir', 'omitida' => 'Omitidas', 'al_dia' => 'Al día', 'fuera' => 'Fuera de alcance / sin acción', 'sin_datos' => 'Sin datos de MP' );
		echo '<p><label>Filtrar por decisión: <select id="cn-au-filtro">';
		foreach ( $etiq as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<table class="widefat striped"><thead><tr><th>Ficha</th><th>Estado ficha</th><th>Plan</th><th>Sub en MP</th><th>Acceso hasta</th><th>Baja programada</th><th>Decisión del espejo</th><th>¿Cortaría el acceso?</th></tr></thead><tbody id="cn-au-tabla">';
		foreach ( $res['filas'] as $x ) {
			echo '<tr data-bucket="' . esc_attr( $x['bucket'] ) . '">';
			echo '<td>#' . (int) $x['id'] . ' ' . esc_html( $x['nombre'] ) . '</td>';
			echo '<td>' . esc_html( $x['estado_ficha'] ) . '</td>';
			echo '<td>' . esc_html( $x['plan'] ) . '</td>';
			echo '<td>' . esc_html( '' !== $x['status_mp'] ? $x['status_mp'] : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== $x['acceso_hasta'] ? substr( $x['acceso_hasta'], 0, 10 ) : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== $x['baja_en'] ? substr( $x['baja_en'], 0, 10 ) : 'no' ) . '</td>';
			echo '<td>' . esc_html( $x['decision'] ) . '</td>';
			if ( ! empty( $x['corta_acceso'] ) ) {
				echo '<td style="color:#b32d2e;font-weight:700;">SÍ: baja ' . esc_html( $x['baja_prop'] ) . ' antes del acceso ' . esc_html( substr( $x['acceso_hasta'], 0, 10 ) ) . '</td>';
			} else {
				echo '<td>' . ( 'baja' === $x['bucket'] ? 'no' : '—' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<script>document.getElementById("cn-au-filtro").addEventListener("change",function(){var v=this.value;document.querySelectorAll("#cn-au-tabla tr").forEach(function(tr){tr.style.display=("" === v || tr.getAttribute("data-bucket") === v)?"":"none";});});</script>';
		echo '</div>';
	}
}
CN_Espejo_Auditoria::registrar(); // se engancha sola: borrar la pantalla = quitar el require_once y este archivo.
