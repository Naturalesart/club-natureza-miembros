<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * "Vincular en lote" (panel Socias). El admin pega pares "preapproval_id, id de socia" ya decididos (no se adivina
 * nada acá) y primero PREVISUALIZA todo el lote en solo lectura. Después confirma de a pocos (máx. 5 por clic).
 * Cada par se escribe con CN_Vinculo_Manual::confirmar (transacción FOR UPDATE, idempotente, backup vinculo_backup,
 * nunca pisa otro preapproval). NUNCA manda mail ni evento Meta.
 *
 * Hereda de CN_Vinculo_Manual solo para reutilizar sus helpers; no toca webhook, login ni flags.
 */
class CN_Vinculo_Lote extends CN_Vinculo_Manual {
	const NONCE_L     = 'cn_vinculo_lote';
	const MAX_PARES   = 20; // pares por lote pegado
	const MAX_CONFIRM = 5;  // pares que se escriben por clic
	const FRASE       = 'VINCULAR';

	/**
	 * Pares válidos de un texto: una línea por par, "<32 hex> <id de socia>" separados por coma, espacio, tab o ;.
	 * Líneas vacías y las que empiezan con # se ignoran.
	 *
	 * @return array{pares:array,errores:string[]}
	 */
	public static function parsear( $texto ) {
		$pares   = array();
		$errores = array();
		$vistos  = array();
		$socias  = array();
		$n       = 0;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $texto ) as $i => $linea ) {
			$linea = trim( $linea );
			if ( '' === $linea || 0 === strpos( $linea, '#' ) ) {
				continue;
			}
			$n++;
			$nro = $i + 1;
			if ( $n > self::MAX_PARES ) {
				$errores[] = 'Máximo ' . self::MAX_PARES . ' pares por lote: lo que sigue de la línea ' . $nro . ' se ignoró.';
				break;
			}
			$t = preg_split( '/[\s,;]+/', $linea, -1, PREG_SPLIT_NO_EMPTY );
			if ( 2 !== count( $t ) ) {
				$errores[] = 'Línea ' . $nro . ': hacen falta exactamente 2 datos (ID de 32 caracteres e id de socia).';
				continue;
			}
			$id = strtolower( $t[0] );
			if ( ! self::id_valido( $id ) ) {
				$errores[] = 'Línea ' . $nro . ': el ID de preapproval no tiene 32 caracteres hexadecimales.';
				continue;
			}
			if ( ! ctype_digit( $t[1] ) || (int) $t[1] <= 0 ) {
				$errores[] = 'Línea ' . $nro . ': el id de socia tiene que ser un número.';
				continue;
			}
			$sid = (int) $t[1];
			if ( isset( $vistos[ $id ] ) ) {
				$errores[] = 'Línea ' . $nro . ': ese preapproval ya figura en la línea ' . $vistos[ $id ] . '.';
				continue;
			}
			if ( isset( $socias[ $sid ] ) ) {
				$errores[] = 'Línea ' . $nro . ': la socia #' . $sid . ' ya figura en la línea ' . $socias[ $sid ] . '.';
				continue;
			}
			$vistos[ $id ]   = $nro;
			$socias[ $sid ]  = $nro;
			$pares[]         = array( 'id' => $id, 'socia' => $sid );
		}
		return array( 'pares' => $pares, 'errores' => $errores );
	}

	protected static function leer_socia( $sid ) {
		global $wpdb;
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLS . ' FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE id = %d', (int) $sid ) );
		return $f ? $f : null;
	}

	/** Otras fichas que podrían ser la misma persona (mismo celular o mismo nombre normalizado). */
	protected static function gemelas( $socia ) {
		global $wpdb;
		$t      = CN_DB::tabla( 'miembros' );
		$nombre = CN_Vinculo::nombre_normalizado( $socia->nombre_apellido );
		$res    = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, nombre_apellido, celular_hint FROM ' . $t . ' WHERE id <> %d', (int) $socia->id ) ) as $o ) {
			$mismo_cel = ( (string) $o->celular_hint === (string) $socia->celular_hint );
			$mismo_nom = ( '' !== $nombre && CN_Vinculo::nombre_normalizado( $o->nombre_apellido ) === $nombre );
			if ( $mismo_cel || $mismo_nom ) {
				$res[ (int) $o->id ] = '#' . (int) $o->id . ' ' . $o->nombre_apellido . ' ' . CN_Vinculo::mascara_celular( $o->celular_hint );
			}
		}
		return $res;
	}

	// ------------------------------------------------------------------ candidatas seguras (SOLO LECTURA)

	/** Email enmascarado: inicial + dominio (a***@gmail.com). */
	protected static function email_mascara( $email ) {
		$email = CN_Vinculo::email_normalizado( $email );
		if ( '' === $email ) {
			return '—';
		}
		$p = explode( '@', $email, 2 );
		return substr( $p[0], 0, 1 ) . '***@' . $p[1];
	}

	/** ¿Dos nombres son iguales o parecidos? (normalizados; mismas palabras en otro orden, o a ≤2 letras de distancia). */
	protected static function nombres_parecidos( $a, $b ) {
		$a = CN_Vinculo::nombre_normalizado( $a );
		$b = CN_Vinculo::nombre_normalizado( $b );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return true;
		}
		$ta = explode( ' ', $a );
		$tb = explode( ' ', $b );
		sort( $ta );
		sort( $tb );
		if ( $ta === $tb ) {
			return true;
		}
		return min( strlen( $a ), strlen( $b ) ) >= 5 && levenshtein( substr( $a, 0, 100 ), substr( $b, 0, 100 ) ) <= 2;
	}

	/** ¿Celulares iguales o que difieren en ≤1 dígito (normalizados)? Vacíos/inválidos nunca coinciden. */
	protected static function celulares_parecidos( $a, $b ) {
		$na = CN_Helpers::normalizar_celular( (string) $a );
		$nb = CN_Helpers::normalizar_celular( (string) $b );
		if ( empty( $na['valido'] ) || empty( $nb['valido'] ) ) {
			return false;
		}
		return levenshtein( $na['normalizado'], $nb['normalizado'] ) <= 1;
	}

	/** Id de otra ficha (cualquier estado, distinto id) que pueda ser la misma persona: mismo email, nombre parecido o celular a ≤1 dígito. 0 si no hay. */
	protected static function ficha_parecida( $socia, $socias ) {
		$email = CN_Vinculo::email_normalizado( isset( $socia->email ) ? $socia->email : '' );
		foreach ( (array) $socias as $o ) {
			if ( (int) $o->id === (int) $socia->id ) {
				continue;
			}
			if ( ( '' !== $email && CN_Vinculo::email_normalizado( isset( $o->email ) ? $o->email : '' ) === $email )
				|| self::nombres_parecidos( $socia->nombre_apellido, $o->nombre_apellido )
				|| self::celulares_parecidos( $socia->celular_texto_plano, isset( $o->celular_texto_plano ) ? $o->celular_texto_plano : '' ) ) {
				return (int) $o->id;
			}
		}
		return 0;
	}

	/**
	 * Candidatas seguras (puro, SOLO LECTURA). Reutiliza CN_Vinculo::proponer.
	 *
	 * @param array $items  suscripciones authorized de MP
	 * @param array $socias filas de wp_cn_miembros con self::COLS
	 * @return array{pares:array,excluidas:array,restantes:int,extra_usadas:int,tope_alcanzado:bool}
	 */
	public static function candidatas_seguras( $items, $socias, $pagos = array(), $tope = CN_Vinculo::TOPE_CONSULTAS_EXTRA ) {
		$prop      = CN_Vinculo::proponer( $items, $socias, $pagos, $tope );
		$ok        = array();
		$excluidas = array();
		$excluir   = function ( $f, $motivo ) use ( &$excluidas ) {
			$excluidas[] = array( 'id' => $f['id'], 'socia' => $f['socia'], 'email' => $f['email'], 'motivo' => $motivo );
		};
		foreach ( $prop['filas'] as $f ) {
			if ( CN_Vinculo::YA_VINCULADA === $f['confianza'] ) {
				continue; // ya vinculada: no se propone ni se lista.
			}
			if ( CN_Vinculo::SIN_EMAIL === $f['confianza'] ) {
				$excluir( $f, 'sin email' );
			} elseif ( CN_Vinculo::VARIAS_SOCIAS === $f['confianza'] ) {
				$excluir( $f, 'más de una socia' );
			} elseif ( CN_Vinculo::SIN_MATCH === $f['confianza'] || ! $f['socia'] ) {
				$excluir( $f, 'sin match' );
			} elseif ( null === $f['plan'] ) {
				$excluir( $f, 'monto sin plan conocido' );
			} elseif ( '' !== (string) $f['socia']->preapproval_id ) {
				$excluir( $f, 'la socia ya tiene otra suscripción' );
			} else {
				$norm = CN_Helpers::normalizar_celular( (string) $f['socia']->celular_texto_plano );
				$otra = self::ficha_parecida( $f['socia'], $socias );
				if ( empty( $norm['valido'] ) ) {
					$excluir( $f, 'sin celular válido' );
				} elseif ( $otra ) {
					$excluir( $f, 'ficha parecida #' . $otra );
				} else {
					$ok[] = $f;
				}
			}
		}
		// Una misma socia para dos suscripciones: ambigua, no se propone ninguna.
		$cuenta = array();
		foreach ( $ok as $f ) {
			$k            = (int) $f['socia']->id;
			$cuenta[ $k ] = isset( $cuenta[ $k ] ) ? $cuenta[ $k ] + 1 : 1;
		}
		$pares = array();
		foreach ( $ok as $f ) {
			if ( $cuenta[ (int) $f['socia']->id ] > 1 ) {
				$excluir( $f, 'misma socia en más de una suscripción' );
			} else {
				$pares[] = array( 'id' => $f['id'], 'socia' => (int) $f['socia']->id );
			}
		}
		return array(
			'pares'          => array_slice( $pares, 0, self::MAX_CONFIRM ),
			'excluidas'      => $excluidas,
			'restantes'      => max( 0, count( $pares ) - self::MAX_CONFIRM ),
			'extra_usadas'   => $prop['extra_usadas'],
			'tope_alcanzado' => $prop['tope_alcanzado'],
		);
	}

	/**
	 * Completa celular_texto_plano de una ficha que lo tiene vacío o inválido. Solo si el celular tipeado (normalizado)
	 * coincide con celular_hint Y con celular_hash (password_verify). Es lo único que se escribe; backup antes.
	 * El celular tipeado nunca se devuelve ni se registra.
	 */
	protected static function completar_celular( $sid, $celular ) {
		global $wpdb;
		$err = function ( $m ) {
			return array( 'paso' => 'celular', 'ok' => false, 'mensaje' => $m . ' No se cambió nada.', 'texto' => '' );
		};
		if ( $sid <= 0 ) {
			return $err( 'Indicá el id de la socia.' );
		}
		$socia = self::leer_socia( $sid );
		if ( ! $socia ) {
			return $err( 'No existe la socia #' . $sid . '.' );
		}
		$actual = CN_Helpers::normalizar_celular( (string) $socia->celular_texto_plano );
		if ( ! empty( $actual['valido'] ) ) {
			return $err( 'La socia #' . $sid . ' ya tiene un celular guardado válido.' );
		}
		$norm = CN_Helpers::normalizar_celular( $celular );
		if ( empty( $norm['valido'] ) ) {
			return $err( 'El celular no es válido (se esperan 10 dígitos con código de área, sin 0 ni 15).' );
		}
		if ( CN_Helpers::hint_celular( $norm['normalizado'] ) !== (string) $socia->celular_hint ) {
			return $err( 'Los últimos 4 dígitos no coinciden con los de la ficha (' . CN_Vinculo::mascara_celular( $socia->celular_hint ) . ').' );
		}
		if ( ! CN_Helpers::verificar_celular( $norm['normalizado'], (string) $socia->celular_hash ) ) {
			return $err( 'Ese celular no es el de la ficha (no coincide con el guardado).' );
		}
		try {
			self::backup( $socia, '' ); // lanza si no se pudo guardar: entonces no se escribe.
		} catch ( Exception $e ) {
			return $err( 'No se pudo guardar el backup previo.' );
		}
		$ok = $wpdb->update(
			CN_DB::tabla( 'miembros' ),
			array( 'celular_texto_plano' => $norm['normalizado'] ),
			array( 'id' => (int) $sid ),
			array( '%s' ),
			array( '%d' )
		);
		if ( false === $ok ) {
			return $err( 'No se pudo guardar.' );
		}
		return array( 'paso' => 'celular', 'ok' => true, 'mensaje' => 'Listo: se completó el celular de la socia #' . $sid . ' (' . CN_Vinculo::mascara_celular( $socia->celular_hint ) . ').', 'texto' => '' );
	}

	/** Trae de MP y de la base lo necesario y arma el paso "candidatas". SOLO LECTURA. */
	protected static function cargar_candidatas( $texto_previo ) {
		global $wpdb;
		$l = CN_Vinculo::listar_authorized();
		if ( empty( $l['ok'] ) ) {
			return array( 'paso' => 'error', 'mensaje' => $l['error'], 'texto' => $texto_previo );
		}
		$socias = $wpdb->get_results( 'SELECT ' . self::COLS . ' FROM ' . CN_DB::tabla( 'miembros' ) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $socias ) ) {
			return array( 'paso' => 'error', 'mensaje' => 'No se pudo leer la tabla de socias.', 'texto' => $texto_previo );
		}
		$c   = self::candidatas_seguras( $l['items'], $socias, CN_Vinculo::cargar_pagos_aprobados( 40, 3 ) );
		$txt = '';
		foreach ( $c['pares'] as $par ) {
			$txt .= $par['id'] . ' ' . $par['socia'] . "\n";
		}
		$errores = array();
		if ( $l['truncada'] ) {
			$errores[] = 'La lista de Mercado Pago se leyó incompleta (tope de 400): puede haber más suscripciones.';
		}
		if ( $c['tope_alcanzado'] ) {
			$errores[] = 'Se llegó al tope de consultas a Mercado Pago para buscar emails: algunas quedaron como "sin email" por eso.';
		}
		return array( 'paso' => 'candidatas', 'texto' => rtrim( $txt ), 'errores' => $errores, 'cand' => $c );
	}

	/** SOLO LECTURA: qué pasaría con un par. */
	protected static function evaluar( $par ) {
		global $wpdb;
		$r = array( 'id' => $par['id'], 'socia_id' => $par['socia'], 'ok' => false, 'texto' => '', 'avisos' => array(), 'mp' => null, 'socia' => null );
		$mp = self::consultar( $par['id'] );
		if ( ! $mp['ok'] ) {
			$r['texto'] = $mp['error'];
			return $r;
		}
		$r['mp'] = $mp;
		$socia   = self::leer_socia( $par['socia'] );
		if ( ! $socia ) {
			$r['texto'] = 'No existe la socia #' . (int) $par['socia'] . '.';
			return $r;
		}
		$r['socia'] = $socia;
		if ( ! $mp['confirmable'] ) {
			$r['texto'] = $mp['motivo'];
			return $r;
		}
		$vinculada = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLS . ' FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE preapproval_id = %s LIMIT 1', $par['id'] ) );
		list( $accion, $fila ) = self::vinc_decidir( $vinculada ? $vinculada : null, $socia, $par['id'] );
		$norm = CN_Helpers::normalizar_celular( (string) $socia->celular_texto_plano );
		if ( empty( $norm['valido'] ) ) {
			$r['texto'] = 'La socia no tiene un celular guardado válido: no se puede vincular desde acá.';
			return $r;
		}
		if ( 'conflicto' === $accion ) {
			$r['texto'] = 'CONFLICTO: la socia ya tiene OTRA suscripción vinculada. No se tocaría nada.';
			return $r;
		}
		if ( 'rechazada' === $accion ) {
			$r['texto'] = 'RECHAZADA: ese preapproval ya está vinculado a otra socia (#' . (int) $fila->id . ').';
			return $r;
		}
		$r['ok']    = true;
		$r['texto'] = ( 'activada' === $accion )
			? 'Se activa (hoy: ' . $socia->estado . ( ! empty( $socia->fecha_fin_trial ) ? ', en trial' : '' ) . ') y se le vincula la suscripción.'
			: 'Ya estaba vinculada: solo se completa plan/acceso si están vacíos.';
		foreach ( self::gemelas( $socia ) as $txt ) {
			$r['avisos'][] = $txt;
		}
		return $r;
	}

	/** null si no se envió este formulario; si no, array con paso. */
	public static function procesar() {
		if ( ! isset( $_POST['cn_vl_accion'] ) ) {
			return null;
		}
		$texto = sanitize_textarea_field( wp_unslash( $_POST['cn_vl_pares'] ?? '' ) );
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'paso' => 'error', 'mensaje' => 'No tenés permisos para esto.', 'texto' => $texto );
		}
		check_admin_referer( self::NONCE_L, 'cn_vl_nonce' );
		$accion = sanitize_key( wp_unslash( $_POST['cn_vl_accion'] ) );
		if ( 'completar_celular' === $accion ) {
			return self::completar_celular(
				absint( $_POST['cn_vl_cc_id'] ?? 0 ),
				sanitize_text_field( wp_unslash( $_POST['cn_vl_cc_celular'] ?? '' ) )
			);
		}
		if ( 'candidatas' === $accion ) {
			return self::cargar_candidatas( $texto ); // solo completa el texto: no escribe nada.
		}
		$p      = self::parsear( $texto );
		if ( ! $p['pares'] ) {
			return array( 'paso' => 'error', 'mensaje' => 'No hay pares válidos para procesar.', 'texto' => $texto, 'errores' => $p['errores'] );
		}
		if ( 'confirmar' === $accion ) {
			return self::confirmar_lote( $texto, $p );
		}
		$filas = array();
		foreach ( $p['pares'] as $par ) {
			$filas[] = self::evaluar( $par );
		}
		return array( 'paso' => 'previsualizado', 'texto' => $texto, 'errores' => $p['errores'], 'filas' => $filas );
	}

	protected static function confirmar_lote( $texto, $p ) {
		$frase = trim( sanitize_text_field( wp_unslash( $_POST['cn_vl_frase'] ?? '' ) ) );
		if ( self::FRASE !== $frase ) {
			return array( 'paso' => 'error', 'mensaje' => 'Para confirmar escribí ' . self::FRASE . ' en el campo de confirmación. No se cambió nada.', 'texto' => $texto, 'errores' => $p['errores'] );
		}
		$acepta_avisos = ! empty( $_POST['cn_vl_avisos'] );
		$filas         = array();
		$escritos      = 0;
		foreach ( $p['pares'] as $par ) {
			$f = array( 'id' => $par['id'], 'socia_id' => $par['socia'], 'ok' => false, 'texto' => '', 'avisos' => array(), 'hecho' => false );
			if ( $escritos >= self::MAX_CONFIRM ) {
				$f['texto'] = 'Pendiente: el máximo es ' . self::MAX_CONFIRM . ' por clic. Volvé a confirmar para seguir.';
				$filas[]    = $f;
				continue;
			}
			$ev = self::evaluar( $par );
			if ( ! $ev['ok'] ) {
				$f['texto'] = 'No se vinculó: ' . $ev['texto'];
				$filas[]    = $f;
				continue;
			}
			if ( $ev['avisos'] && ! $acepta_avisos ) {
				$f['texto']  = 'No se vinculó: tiene advertencias (otras fichas parecidas) y no marcaste que las revisaste.';
				$f['avisos'] = $ev['avisos'];
				$filas[]     = $f;
				continue;
			}
			$norm = CN_Helpers::normalizar_celular( (string) $ev['socia']->celular_texto_plano );
			$d    = array( 'id' => $par['id'], 'celular' => (string) $ev['socia']->celular_texto_plano, 'email' => '', 'nombre' => '', 'notificar' => false, 'socia' => (string) $par['socia'] );
			$r    = self::confirmar( $d, (string) $norm['normalizado'], 'vinculo_lote' );
			$f['hecho'] = true;
			$f['ok']    = ! empty( $r['ok'] );
			$f['texto'] = strtoupper( (string) $r['accion'] ) . ': ' . (string) $r['mensaje'];
			if ( $f['ok'] && 'completada' !== $r['accion'] ) {
				$escritos++;
			}
			$filas[] = $f;
		}
		return array( 'paso' => 'confirmado', 'texto' => $texto, 'errores' => $p['errores'], 'filas' => $filas );
	}

	// ------------------------------------------------------------------ pantalla

	public static function render( $res ) {
		$paso  = is_array( $res ) ? $res['paso'] : '';
		$texto = is_array( $res ) ? $res['texto'] : '';
		$ancla = esc_url( '#cn-vincular-lote' );
		echo '<h2 id="cn-vincular-lote">Vincular en lote</h2>';
		echo '<p style="max-width:760px;">Pegá un par por línea: <code>ID_de_preapproval id_de_socia</code> (ej. <code>7e9baadc542b441fb37131549f6bbad7 222</code>). Primero <em>Previsualizar</em> (solo lectura) y después <em>Confirmar</em>: escribe como máximo ' . (int) self::MAX_CONFIRM . ' por clic, sin mail ni evento Meta. <strong>Antes de confirmar, exportá la tabla de socias desde phpMyAdmin.</strong></p>';
		if ( is_array( $res ) && ! empty( $res['errores'] ) ) {
			foreach ( $res['errores'] as $e ) {
				self::aviso_html( $e, 'warning' );
			}
		}
		if ( 'error' === $paso ) {
			self::aviso_html( $res['mensaje'], 'error' );
		}
		if ( 'celular' === $paso ) {
			self::aviso_html( $res['mensaje'], $res['ok'] ? 'success' : 'error' );
		}
		if ( 'candidatas' === $paso ) {
			$c = $res['cand'];
			echo '<p><strong>' . (int) count( $c['pares'] ) . ' candidata(s) segura(s) cargada(s) en el cuadro de abajo.</strong> Todavía no se vinculó nada: revisalas con <em>Previsualizar</em>.';
			if ( $c['restantes'] > 0 ) {
				echo ' Quedan ' . (int) $c['restantes'] . ' más para la próxima pasada (después de vincular estas).';
			}
			echo '</p>';
			if ( $c['excluidas'] ) {
				echo '<h3>Excluidas (' . (int) count( $c['excluidas'] ) . ')</h3><table class="widefat striped" style="max-width:1000px;margin-bottom:12px;"><thead><tr><th>Suscripción</th><th>Email</th><th>Socia propuesta</th><th>Motivo</th></tr></thead><tbody>';
				foreach ( $c['excluidas'] as $x ) {
					$sp = $x['socia'] ? '#' . (int) $x['socia']->id . ' ' . CN_Vinculo::mascara_celular( $x['socia']->celular_hint ) : '—';
					echo '<tr><td><code>' . esc_html( substr( $x['id'], 0, 8 ) ) . '</code></td><td>' . esc_html( self::email_mascara( $x['email'] ) ) . '</td><td>' . esc_html( $sp ) . '</td><td>' . esc_html( $x['motivo'] ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}
		if ( 'previsualizado' === $paso || 'confirmado' === $paso ) {
			$hay_avisos = false;
			echo '<table class="widefat striped" style="max-width:1000px;margin-bottom:12px;"><thead><tr><th>Suscripción</th><th>MP</th><th>Socia</th><th>Resultado</th></tr></thead><tbody>';
			foreach ( $res['filas'] as $f ) {
				$mp = isset( $f['mp'] ) ? $f['mp'] : null;
				$s  = isset( $f['socia'] ) ? $f['socia'] : null;
				echo '<tr><td><code>' . esc_html( $f['id'] ) . '</code></td><td>';
				if ( $mp ) {
					echo esc_html( $mp['status'] . ' · ' . ( null !== $mp['plan'] ? $mp['plan_nombre'] : 'plan desconocido' ) . ' · próx. ' . ( '' !== $mp['next_payment_date'] ? substr( $mp['next_payment_date'], 0, 10 ) : '—' ) );
				} else {
					echo '—';
				}
				echo '</td><td>#' . (int) $f['socia_id'];
				if ( $s ) {
					echo ' ' . esc_html( $s->nombre_apellido . ' ' . CN_Vinculo::mascara_celular( $s->celular_hint ) . ' · ' . $s->estado . ' · suscripción actual: ' . ( '' !== (string) $s->preapproval_id ? 'otra' : 'ninguna' ) );
				}
				echo '</td><td>' . ( ! empty( $f['ok'] ) ? '✅ ' : '⛔ ' ) . esc_html( $f['texto'] );
				foreach ( $f['avisos'] as $a ) {
					$hay_avisos = true;
					echo '<br><strong style="color:#b32d2e;">⚠ Otra ficha parecida: ' . esc_html( $a ) . '</strong>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		?>
		<form method="post" style="max-width:760px;" action="<?php echo $ancla; ?>">
			<?php wp_nonce_field( self::NONCE_L, 'cn_vl_nonce' ); ?>
			<p><textarea name="cn_vl_pares" rows="6" class="large-text code" placeholder="ID_de_preapproval id_de_socia"><?php echo esc_textarea( $texto ); ?></textarea></p>
			<p><button type="submit" name="cn_vl_accion" value="previsualizar" class="button button-secondary">Previsualizar lote</button>
			<button type="submit" name="cn_vl_accion" value="candidatas" class="button">Cargar candidatas seguras</button>
			<span class="description">Solo completa el cuadro (máx. <?php echo (int) self::MAX_CONFIRM; ?>); no vincula nada.</span></p>
			<?php if ( 'previsualizado' === $paso ) : ?>
				<hr>
				<?php if ( ! empty( $hay_avisos ) ) : ?>
					<p><label><input type="checkbox" name="cn_vl_avisos" value="1"> Revisé las advertencias de fichas parecidas y quiero vincular igual esas filas</label></p>
				<?php endif; ?>
				<p><label>Para confirmar escribí <strong><?php echo esc_html( self::FRASE ); ?></strong>: <input type="text" name="cn_vl_frase" autocomplete="off" style="width:140px;"></label></p>
				<p><button type="submit" name="cn_vl_accion" value="confirmar" class="button button-primary">Confirmar (máx. <?php echo (int) self::MAX_CONFIRM; ?>)</button></p>
			<?php endif; ?>
		</form>
		<h3>Completar celular</h3>
		<p style="max-width:760px;">Para una ficha que quedó <em>sin celular válido</em>: ingresá el id de la socia y su celular completo. Solo se guarda si los últimos 4 dígitos <strong>y</strong> el celular protegido de la ficha coinciden; si no, no se cambia nada.</p>
		<form method="post" style="max-width:760px;" action="<?php echo $ancla; ?>" autocomplete="off">
			<?php wp_nonce_field( self::NONCE_L, 'cn_vl_nonce' ); ?>
			<p><label>Id de socia: <input type="number" name="cn_vl_cc_id" min="1" style="width:100px;"></label>
			<label>Celular completo: <input type="text" name="cn_vl_cc_celular" autocomplete="off" style="width:180px;"></label>
			<button type="submit" name="cn_vl_accion" value="completar_celular" class="button">Completar celular</button></p>
		</form>
		<?php
	}
}
