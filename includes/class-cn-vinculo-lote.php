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
			<p><button type="submit" name="cn_vl_accion" value="previsualizar" class="button button-secondary">Previsualizar lote</button></p>
			<?php if ( 'previsualizado' === $paso ) : ?>
				<hr>
				<?php if ( ! empty( $hay_avisos ) ) : ?>
					<p><label><input type="checkbox" name="cn_vl_avisos" value="1"> Revisé las advertencias de fichas parecidas y quiero vincular igual esas filas</label></p>
				<?php endif; ?>
				<p><label>Para confirmar escribí <strong><?php echo esc_html( self::FRASE ); ?></strong>: <input type="text" name="cn_vl_frase" autocomplete="off" style="width:140px;"></label></p>
				<p><button type="submit" name="cn_vl_accion" value="confirmar" class="button button-primary">Confirmar (máx. <?php echo (int) self::MAX_CONFIRM; ?>)</button></p>
			<?php endif; ?>
		</form>
		<?php
	}
}
