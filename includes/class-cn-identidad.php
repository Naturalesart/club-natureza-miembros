<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Etapa 3 del alta — una sola ficha por persona (flag cn_identidad_modo, off | log | on; default off).
 *
 * Antes de crear una ficha al activar una suscripción "sub_" (CN_Webhook::activar_por_suscripcion_sub), se busca si la
 * persona ya existe, en este orden:
 *  1. Ficha del token: ficha_id de la pendiente (link firmado / formulario precargado) o el id de una referencia
 *     sub_f<ficha>_<hash>. Es la señal más fuerte: si existe, se usa esa y no se mira nada más.
 *  2. Email exacto normalizado (minúsculas, sin espacios) contra el email de las fichas.
 *  3. Celular normalizado (CN_Helpers::normalizar_celular, el mismo de siempre), hint + verificación del hash.
 * Resultado:
 *  - 1 ficha (o email y celular apuntan a la misma): se convierte esa ficha en suscripta (conserva su historial).
 *  - 0: se crea una ficha nueva, como siempre.
 *  - Dudoso (2+ fichas con el mismo email o el mismo celular, o email y celular apuntan a fichas distintas): se crea
 *    una ficha nueva, se anota en cn_posibles_duplicados (tabla nueva) y se alerta a hola@. NUNCA se fusiona sola.
 * Modo 'log': no cambia nada; deja identidad_log en mp_log con la ficha que habría usado.
 *
 * Solo lectura sobre wp_cn_miembros (las escrituras siguen en el webhook, dentro de su transacción). No re-hashea nada.
 */
class CN_Identidad {
	const OPT_MODO = 'cn_identidad_modo';
	const COLS     = 'id, celular_hash, preapproval_id, plan, acceso_hasta, celular_texto_plano, email';

	public static function modo() {
		return CN_Flujo_Alta::modo( self::OPT_MODO );
	}

	public static function normalizar_email( $email ) {
		return strtolower( trim( (string) $email ) );
	}

	/** Id de ficha de una referencia sub_f<ficha>_<hash>; 0 si no es de ese formato. */
	public static function ficha_de_referencia( $external_reference ) {
		return preg_match( '/^sub_f([1-9][0-9]{0,11})_[a-f0-9]{8}$/', (string) $external_reference, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Busca la ficha de la persona de una pendiente. Lanza RuntimeException ante un error de base (quien llama hace ROLLBACK).
	 *
	 * @return array{ficha:?object,via:string,dudoso:bool,candidatas:int[]}
	 *         via: token | email | celular | email+celular | '' (ninguna)
	 */
	public static function resolver( $fila ) {
		global $wpdb;
		$t   = CN_DB::tabla( 'miembros' );
		$out = array( 'ficha' => null, 'via' => '', 'dudoso' => false, 'candidatas' => array() );
		// 1. Ficha del token.
		$id = ( isset( $fila->ficha_id ) && (int) $fila->ficha_id > 0 ) ? (int) $fila->ficha_id : self::ficha_de_referencia( $fila->external_reference ?? '' );
		if ( $id > 0 ) {
			$f = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLS . " FROM {$t} WHERE id = %d", $id ) );
			self::chequear_error( 'token' );
			if ( $f ) {
				$out['ficha']      = $f;
				$out['via']        = 'token';
				$out['candidatas'] = array( (int) $f->id );
				return $out;
			}
		}
		// 2. Email normalizado.
		$por_email = array();
		$email     = self::normalizar_email( $fila->email ?? '' );
		if ( '' !== $email ) {
			$por_email = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLS . " FROM {$t} WHERE LOWER(TRIM(email)) = %s", $email ) );
			self::chequear_error( 'email' );
		}
		// 3. Celular normalizado (el de la pendiente ya viene normalizado: no se re-normaliza).
		$por_celular = array();
		$cel         = (string) ( $fila->celular ?? '' );
		if ( '' !== $cel ) {
			$cands = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLS . " FROM {$t} WHERE celular_hint = %s", CN_Helpers::hint_celular( $cel ) ) );
			self::chequear_error( 'celular' );
			foreach ( $cands as $c ) {
				if ( CN_Helpers::verificar_celular( $cel, (string) $c->celular_hash ) ) {
					$por_celular[] = $c;
				}
			}
		}
		$ids_e = array_map( function ( $f ) { return (int) $f->id; }, $por_email );
		$ids_c = array_map( function ( $f ) { return (int) $f->id; }, $por_celular );
		$out['candidatas'] = array_values( array_unique( array_merge( $ids_e, $ids_c ) ) );
		if ( count( $ids_e ) > 1 || count( $ids_c ) > 1 || ( 1 === count( $ids_e ) && 1 === count( $ids_c ) && $ids_e[0] !== $ids_c[0] ) ) {
			$out['dudoso'] = true;
			$out['via']    = 1 === count( $ids_e ) && 1 === count( $ids_c ) ? 'email+celular' : ( count( $ids_e ) > 1 ? 'email' : 'celular' );
			return $out;
		}
		if ( 1 === count( $ids_e ) ) {
			$out['ficha'] = $por_email[0];
			$out['via']   = 1 === count( $ids_c ) ? 'email+celular' : 'email';
		} elseif ( 1 === count( $ids_c ) ) {
			$out['ficha'] = $por_celular[0];
			$out['via']   = 'celular';
		}
		return $out;
	}

	protected static function chequear_error( $paso ) {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'error buscando la ficha (' . $paso . '): ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
		}
	}

	/**
	 * Ficha nueva creada en un caso dudoso: la anota para revisar a mano y alerta a hola@. Nunca fusiona ni lanza.
	 * Sin datos personales (solo ids de fichas y el motivo).
	 */
	public static function marcar_posible_duplicado( $ficha_nueva, $candidatas, $via ) {
		global $wpdb;
		$cands = array_slice( array_map( 'intval', (array) $candidatas ), 0, 20 );
		try {
			$wpdb->insert(
				CN_DB::tabla( 'posibles_duplicados' ),
				array(
					'ficha_id'   => (int) $ficha_nueva,
					'candidatas' => implode( ',', $cands ),
					'motivo'     => mb_substr( (string) $via, 0, 30 ),
					'creado'     => current_time( 'mysql', true ),
					'revisado'   => 0,
				),
				array( '%d', '%s', '%s', '%s', '%d' )
			);
		} catch ( Throwable $e ) {
			// Accesorio: el alta ya está hecha.
		}
		CN_Webhook::registrar_log_mp( 'identidad_posible_duplicado', array( 'ficha_id' => (int) $ficha_nueva, 'candidatas' => $cands, 'via' => (string) $via ) );
		CN_Flujo_Alta::alertar(
			'posible_duplicado|' . (int) $ficha_nueva,
			'Posible ficha duplicada (ficha ' . (int) $ficha_nueva . ')',
			array(
				'Una suscripción nueva coincidió con más de una ficha (por ' . (string) $via . ') y no se pudo saber cuál es la persona.',
				'Se creó la ficha ' . (int) $ficha_nueva . ' y quedó activa. Fichas candidatas: ' . implode( ', ', $cands ) . '.',
				'No se fusionó nada: revisalas a mano.',
			)
		);
	}
}
