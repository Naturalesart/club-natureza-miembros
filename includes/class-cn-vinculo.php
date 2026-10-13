<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Fase 5: propuesta de vínculo por email (suscripción de MP -> socia) y detección de socias duplicadas.
 * TODO es de solo lectura: propone, nunca vincula ni escribe en la base. La usan la pantalla "Cruce con Mercado
 * Pago" y el aviso de suscripciones sin vincular del cron (CN_Sync), para que ambos razonen igual.
 */
class CN_Vinculo {
	// Confianza de una propuesta.
	const EMAIL_EXACTO  = 'email_exacto';
	const VARIAS_SOCIAS = 'varias_socias';
	const SIN_MATCH     = 'sin_match';
	const SIN_EMAIL     = 'sin_email';
	const YA_VINCULADA  = 'ya_vinculada';
	// Máximo de consultas extra a MP (por suscripción) para encontrar el email cuando la suscripción no lo trae.
	const TOPE_CONSULTAS_EXTRA = 30;
	/** Celular enmascarado: solo los últimos 4 dígitos (el hint que ya se guarda). */
	public static function mascara_celular( $hint ) {
		return '****' . (string) $hint;
	}
	/** Email en minúsculas y sin espacios; '' si no parece un email. */
	public static function email_normalizado( $email ) {
		$email = strtolower( trim( is_scalar( $email ) ? (string) $email : '' ) );
		return ( '' !== $email && false !== strpos( $email, '@' ) ) ? $email : '';
	}
	/**
	 * Índices de las socias para la propuesta (puro).
	 *
	 * @param array $socias filas con id, nombre_apellido, estado, email, celular_hint, preapproval_id
	 * @return array{por_email:array,por_preapproval:array}
	 */
	public static function indice_socias( $socias ) {
		$por_email       = array();
		$por_preapproval = array();
		foreach ( (array) $socias as $s ) {
			$email = self::email_normalizado( isset( $s->email ) ? $s->email : '' );
			if ( '' !== $email ) {
				$por_email[ $email ][] = $s;
			}
			$pre = isset( $s->preapproval_id ) ? (string) $s->preapproval_id : '';
			if ( '' !== $pre ) {
				$por_preapproval[ $pre ] = $s;
			}
		}
		return array( 'por_email' => $por_email, 'por_preapproval' => $por_preapproval );
	}
	/**
	 * Índice de cobros aprobados para sacar el email de una suscripción sin consultar a MP (puro). Un cobro se
	 * asocia a una suscripción por su preapproval_id (point_of_interaction o metadata) o por external_reference.
	 * Se queda con el cobro más reciente de cada clave.
	 *
	 * @param array $pagos pagos de MP ya decodificados
	 * @return array{por_preapproval:array,por_ref:array} clave => array( 'email' => string, 'fecha' => timestamp )
	 */
	public static function indexar_pagos( $pagos ) {
		$por_pre = array();
		$por_ref = array();
		foreach ( (array) $pagos as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$email = self::email_normalizado( isset( $p['payer']['email'] ) ? $p['payer']['email'] : '' );
			if ( '' === $email ) {
				continue;
			}
			$ts    = isset( $p['date_created'] ) ? (int) strtotime( (string) $p['date_created'] ) : 0;
			$pre   = '';
			if ( ! empty( $p['point_of_interaction']['transaction_data']['preapproval_id'] ) ) {
				$pre = (string) $p['point_of_interaction']['transaction_data']['preapproval_id'];
			} elseif ( ! empty( $p['metadata']['preapproval_id'] ) ) {
				$pre = (string) $p['metadata']['preapproval_id'];
			}
			$ref   = ( isset( $p['external_reference'] ) && is_scalar( $p['external_reference'] ) ) ? (string) $p['external_reference'] : '';
			$dato  = array( 'email' => $email, 'fecha' => $ts );
			if ( '' !== $pre && ( ! isset( $por_pre[ $pre ] ) || $ts > $por_pre[ $pre ]['fecha'] ) ) {
				$por_pre[ $pre ] = $dato;
			}
			if ( '' !== $ref && ( ! isset( $por_ref[ $ref ] ) || $ts > $por_ref[ $ref ]['fecha'] ) ) {
				$por_ref[ $ref ] = $dato;
			}
		}
		return array( 'por_preapproval' => $por_pre, 'por_ref' => $por_ref );
	}
	/**
	 * Clasifica una suscripción contra el índice de socias (puro). Precedencia: ya vinculada > sin email >
	 * una sola socia por email (exacto) > varias socias > sin match.
	 *
	 * @param array  $item        suscripción de MP (id, ...)
	 * @param string $email       email ya resuelto ('' si no se encontró)
	 * @param array  $indice      resultado de indice_socias()
	 * @return array{confianza:string,socia:?object,candidatas:array,aviso:string}
	 */
	public static function clasificar( $item, $email, $indice ) {
		$id  = isset( $item['id'] ) ? (string) $item['id'] : '';
		$out = array( 'confianza' => self::SIN_EMAIL, 'socia' => null, 'candidatas' => array(), 'aviso' => '' );
		if ( '' !== $id && isset( $indice['por_preapproval'][ $id ] ) ) {
			$out['confianza'] = self::YA_VINCULADA;
			$out['socia']     = $indice['por_preapproval'][ $id ];
			return $out;
		}
		$email = self::email_normalizado( $email );
		if ( '' === $email ) {
			return $out;
		}
		$cand = isset( $indice['por_email'][ $email ] ) ? $indice['por_email'][ $email ] : array();
		if ( 1 === count( $cand ) ) {
			$out['confianza'] = self::EMAIL_EXACTO;
			$out['socia']     = $cand[0];
			if ( '' !== (string) $cand[0]->preapproval_id ) {
				$out['aviso'] = 'La socia ya tiene OTRA suscripción vinculada: no se podría vincular.';
			}
		} elseif ( count( $cand ) > 1 ) {
			$out['confianza']  = self::VARIAS_SOCIAS;
			$out['candidatas'] = $cand;
		} else {
			$out['confianza'] = self::SIN_MATCH;
		}
		return $out;
	}
	/**
	 * Email del pagador de una suscripción: (1) el de la suscripción; (2) el del cobro aprobado más reciente
	 * (índice de cobros, sin consultar); (3) authorized_payments + el cobro (2 consultas por suscripción, contadas
	 * contra $extra con tope $tope; al llegar al tope ya no se consulta y se avisa).
	 *
	 * @param int $extra consultas extra ya usadas (por referencia)
	 * @return array{email:string,fuente:string,tope:bool}
	 */
	public static function resolver_email( $item, $idx_pagos, &$extra, $tope ) {
		$email = self::email_normalizado( isset( $item['payer_email'] ) ? $item['payer_email'] : '' );
		if ( '' !== $email ) {
			return array( 'email' => $email, 'fuente' => 'suscripcion', 'tope' => false );
		}
		$id  = isset( $item['id'] ) ? (string) $item['id'] : '';
		$ref = ( isset( $item['external_reference'] ) && is_scalar( $item['external_reference'] ) ) ? (string) $item['external_reference'] : '';
		$mejor = null;
		if ( '' !== $id && isset( $idx_pagos['por_preapproval'][ $id ] ) ) {
			$mejor = $idx_pagos['por_preapproval'][ $id ];
		}
		if ( '' !== $ref && isset( $idx_pagos['por_ref'][ $ref ] ) && ( null === $mejor || $idx_pagos['por_ref'][ $ref ]['fecha'] > $mejor['fecha'] ) ) {
			$mejor = $idx_pagos['por_ref'][ $ref ];
		}
		if ( null !== $mejor ) {
			return array( 'email' => $mejor['email'], 'fuente' => 'cobro', 'tope' => false );
		}
		if ( '' === $id ) {
			return array( 'email' => '', 'fuente' => '', 'tope' => false );
		}
		if ( $extra >= $tope ) {
			return array( 'email' => '', 'fuente' => '', 'tope' => true );
		}
		$extra++;
		$lista = CN_MP::buscar_authorized_payments( $id );
		$pago_id = '';
		if ( is_array( $lista ) && ! empty( $lista['results'] ) && is_array( $lista['results'] ) ) {
			foreach ( $lista['results'] as $ap ) {
				if ( is_array( $ap ) && ! empty( $ap['payment']['id'] ) ) {
					$pago_id = (string) $ap['payment']['id'];
					break;
				}
			}
		}
		if ( '' === $pago_id ) {
			return array( 'email' => '', 'fuente' => '', 'tope' => false );
		}
		if ( $extra >= $tope ) {
			return array( 'email' => '', 'fuente' => '', 'tope' => true );
		}
		$extra++;
		$pago  = CN_MP::obtener_pago( $pago_id );
		$email = self::email_normalizado( ( is_array( $pago ) && isset( $pago['payer']['email'] ) ) ? $pago['payer']['email'] : '' );
		return array( 'email' => $email, 'fuente' => '' !== $email ? 'authorized_payments' : '', 'tope' => false );
	}
	/**
	 * Propuesta de vínculo para una lista de suscripciones autorizadas. SOLO LECTURA.
	 *
	 * @param array $items  suscripciones 'authorized' de MP
	 * @param array $socias filas de wp_cn_miembros (id, nombre_apellido, estado, email, celular_hint, preapproval_id)
	 * @param array $pagos  cobros aprobados recientes de MP (puede ser vacío)
	 * @return array{filas:array,extra_usadas:int,tope:int,tope_alcanzado:bool}
	 */
	public static function proponer( $items, $socias, $pagos = array(), $tope = self::TOPE_CONSULTAS_EXTRA ) {
		$indice = self::indice_socias( $socias );
		$idx_p  = self::indexar_pagos( $pagos );
		$extra  = 0;
		$topado = false;
		$filas  = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			$id = (string) $item['id'];
			if ( isset( $indice['por_preapproval'][ $id ] ) ) {
				$email = self::email_normalizado( isset( $item['payer_email'] ) ? $item['payer_email'] : '' );
				$fuente = '' !== $email ? 'suscripcion' : '';
			} else {
				$r      = self::resolver_email( $item, $idx_p, $extra, (int) $tope );
				$email  = $r['email'];
				$fuente = $r['fuente'];
				$topado = $topado || $r['tope'];
			}
			$monto = isset( $item['auto_recurring']['transaction_amount'] ) ? $item['auto_recurring']['transaction_amount'] : null;
			$cls   = self::clasificar( $item, $email, $indice );
			$filas[] = array(
				'id'            => $id,
				'plan'          => CN_Webhook::plan_por_monto( $monto ),
				'monto'         => $monto,
				'proximo_cobro' => isset( $item['next_payment_date'] ) ? substr( (string) $item['next_payment_date'], 0, 10 ) : '',
				'date_created'  => isset( $item['date_created'] ) ? (string) $item['date_created'] : '',
				'email'         => $email,
				'email_fuente'  => $fuente,
				'confianza'     => $cls['confianza'],
				'socia'         => $cls['socia'],
				'candidatas'    => $cls['candidatas'],
				'aviso'         => $cls['aviso'],
			);
		}
		return array( 'filas' => $filas, 'extra_usadas' => $extra, 'tope' => (int) $tope, 'tope_alcanzado' => $topado );
	}
	/**
	 * Todas las suscripciones autorizadas de MP (hasta 400). ok = false si MP falló en cualquier página: quien
	 * llama NO debe sacar conclusiones ("sin vincular", etc.) de una lista incompleta.
	 *
	 * @return array{ok:bool,items:array,error:string,truncada:bool}
	 */
	public static function listar_authorized() {
		$items   = array();
		$offset  = 0;
		$truncada = false;
		for ( $pag = 0; $pag < 4; $pag++ ) {
			$data = CN_MP::listar_preapprovals( 'authorized', $offset, 100 );
			if ( ! is_array( $data ) || ! isset( $data['results'] ) || ! is_array( $data['results'] ) ) {
				return array( 'ok' => false, 'items' => array(), 'error' => 'Mercado Pago no respondió la lista de suscripciones.', 'truncada' => false );
			}
			foreach ( $data['results'] as $r ) {
				if ( is_array( $r ) ) {
					$items[] = $r;
				}
			}
			$total  = isset( $data['paging']['total'] ) ? (int) $data['paging']['total'] : count( $items );
			$offset += 100;
			if ( count( $data['results'] ) < 100 || $offset >= $total ) {
				break;
			}
			if ( 3 === $pag ) {
				$truncada = true;
			}
		}
		return array( 'ok' => true, 'items' => $items, 'error' => '', 'truncada' => $truncada );
	}
	/** Cobros aprobados de los últimos $dias días (hasta $max_paginas de 100). Si MP falla devuelve lo que alcanzó a leer. */
	public static function cargar_pagos_aprobados( $dias = 40, $max_paginas = 3 ) {
		$pagos  = array();
		$offset = 0;
		$hasta  = time();
		$desde  = $hasta - (int) $dias * DAY_IN_SECONDS;
		for ( $pag = 0; $pag < (int) $max_paginas; $pag++ ) {
			$data = CN_MP::buscar_pagos_aprobados_rango( $desde, $hasta, $offset, 100 );
			if ( ! is_array( $data ) || ! isset( $data['results'] ) || ! is_array( $data['results'] ) ) {
				break;
			}
			foreach ( $data['results'] as $p ) {
				$pagos[] = $p;
			}
			$total  = isset( $data['paging']['total'] ) ? (int) $data['paging']['total'] : 0;
			$offset += 100;
			if ( count( $data['results'] ) < 100 || $offset >= $total ) {
				break;
			}
		}
		return $pagos;
	}
	/**
	 * Nombre normalizado para detectar duplicados: sin acentos, minúsculas, solo letras/números y un espacio entre palabras.
	 */
	public static function nombre_normalizado( $nombre ) {
		$nombre = (string) $nombre;
		if ( function_exists( 'remove_accents' ) ) {
			$nombre = remove_accents( $nombre );
		}
		$nombre = strtolower( $nombre );
		$nombre = preg_replace( '/[^a-z0-9]+/', ' ', $nombre );
		return trim( (string) $nombre );
	}
	/**
	 * Socias duplicadas (puro, solo lectura): grupos con el mismo email o el mismo nombre normalizado.
	 *
	 * @return array{por_email:array,por_nombre:array} clave => lista de filas (solo grupos de 2 o más)
	 */
	public static function duplicados( $socias ) {
		$por_email  = array();
		$por_nombre = array();
		foreach ( (array) $socias as $s ) {
			$email = self::email_normalizado( isset( $s->email ) ? $s->email : '' );
			if ( '' !== $email ) {
				$por_email[ $email ][] = $s;
			}
			$nom = self::nombre_normalizado( isset( $s->nombre_apellido ) ? $s->nombre_apellido : '' );
			if ( '' !== $nom ) {
				$por_nombre[ $nom ][] = $s;
			}
		}
		$solo_grupos = function ( $mapa ) {
			return array_filter( $mapa, function ( $g ) {
				return count( $g ) > 1;
			} );
		};
		return array( 'por_email' => $solo_grupos( $por_email ), 'por_nombre' => $solo_grupos( $por_nombre ) );
	}
}
