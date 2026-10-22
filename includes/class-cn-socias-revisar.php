<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Pantalla "Socias a revisar" (Socias → Socias a revisar). Solo manage_options + nonce.
 *
 * Lista (solo lectura, hasta 200 filas) las fichas que tienen suscripción (preapproval_id <> '') y están canceladas o
 * todavía con fecha_fin_trial: son las que el viejo camino de "trial vencido" pudo dejar sin acceso aunque su sub siga vigente.
 *
 * Por cada ficha cancelada, el botón "Reactivar" (de a UNA, POST con nonce y confirmación) hace:
 *  1. relee la ficha y verifica que siga cancelada y con preapproval_id;
 *  2. consulta a Mercado Pago SOLO con GET /preapproval/{id}: reactiva únicamente si status === 'authorized'; si no, muestra el
 *     status real y no cambia nada (lo mismo si MP falla o devuelve otra suscripción);
 *  3. con authorized, en una transacción: relee con FOR UPDATE, vuelve a verificar y pone estado='activo', fecha_fin_trial=NULL y
 *     fecha_modificacion; después deja mp_log tipo reactivada_manual (id de ficha, preapproval_id y admin).
 * Nunca toca fichas sin preapproval_id, nunca reactiva en lote, nunca escribe en Mercado Pago, sin flags.
 * No la llama el webhook, el login, el sync ni los crons.
 */
class CN_Socias_Revisar {
	const NONCE  = 'cn_socias_revisar';
	const LIMITE = 200;

	public static function registrar() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	public static function menu() {
		add_submenu_page( 'cn-socias', 'Socias a revisar', 'Socias a revisar', 'manage_options', 'cn-socias-revisar', array( __CLASS__, 'pagina' ) );
	}

	/** Primeros 6 caracteres + "..." (el id completo no se muestra). */
	public static function mascara_pre( $id ) {
		return substr( (string) $id, 0, 6 ) . '...';
	}

	/** Fichas con suscripción y (canceladas o con fecha_fin_trial). Solo lectura. */
	public static function listar() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT id, nombre_apellido, estado, plan, fecha_fin_trial, preapproval_id, fecha_modificacion FROM ' . CN_DB::tabla( 'miembros' ) .
			" WHERE preapproval_id IS NOT NULL AND preapproval_id <> '' AND ( estado = 'cancelada' OR fecha_fin_trial IS NOT NULL ) ORDER BY id DESC LIMIT " . (int) self::LIMITE
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Reactiva UNA ficha cancelada si Mercado Pago dice que su suscripción está authorized.
	 *
	 * @return array{ok:bool,mensaje:string}
	 */
	public static function reactivar( $id ) {
		global $wpdb;
		$id = (int) $id;
		$t  = CN_DB::tabla( 'miembros' );
		$no = function ( $m ) {
			return array( 'ok' => false, 'mensaje' => $m . ' No se cambió nada.' );
		};
		$f = $id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT id, estado, preapproval_id FROM {$t} WHERE id = %d", $id ) ) : null;
		if ( ! $f || 'cancelada' !== (string) $f->estado || '' === (string) $f->preapproval_id ) {
			return $no( 'La ficha no existe, ya no está cancelada o no tiene suscripción.' );
		}
		$pre = (string) $f->preapproval_id;

		// Solo GET a Mercado Pago, antes de abrir la transacción (no se bloquean filas esperando a MP).
		$det = CN_MP::obtener_preapproval_detalle( $pre );
		if ( ! $det['ok'] ) {
			return $no( CN_Vinculo_Manual::mensaje_http( $det['http'] ) );
		}
		$data = $det['data'];
		if ( isset( $data['id'] ) && (string) $data['id'] !== $pre ) {
			return $no( 'Mercado Pago devolvió una suscripción con otro ID.' );
		}
		$status = ( isset( $data['status'] ) && is_string( $data['status'] ) ) ? sanitize_text_field( $data['status'] ) : '';
		if ( 'authorized' !== $status ) {
			return $no( 'Mercado Pago dice que la suscripción está "' . ( '' !== $status ? $status : 'sin estado' ) . '", no "authorized".' );
		}

		$en_transaccion = false;
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'no se pudo abrir la transaccion' );
			}
			$en_transaccion = true;
			$fila = $wpdb->get_row( $wpdb->prepare( "SELECT id, estado, preapproval_id FROM {$t} WHERE id = %d FOR UPDATE", $id ) );
			if ( ! $fila || 'cancelada' !== (string) $fila->estado || (string) $fila->preapproval_id !== $pre ) {
				$wpdb->query( 'ROLLBACK' );
				$en_transaccion = false;
				return $no( 'Mientras tanto la ficha cambió (ya no está cancelada o cambió su suscripción).' );
			}
			$ok = $wpdb->update(
				$t,
				array( 'estado' => 'activo', 'fecha_fin_trial' => null, 'fecha_modificacion' => current_time( 'mysql', true ) ),
				array( 'id' => $id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $ok ) {
				throw new RuntimeException( 'no se pudo reactivar: ' . mb_substr( (string) $wpdb->last_error, 0, 200 ) );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'no se pudo confirmar la transaccion' );
			}
			$en_transaccion = false;
		} catch ( Throwable $e ) {
			if ( $en_transaccion ) {
				$wpdb->query( 'ROLLBACK' );
			}
			CN_Webhook::registrar_log_mp( 'sub_error', array( 'paso' => 'reactivar_manual', 'miembro_id' => $id, 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
			CN_Webhook::avisar_error_n8n( 'reactivar_manual_error', array( 'miembro_id' => $id, 'mensaje' => mb_substr( $e->getMessage(), 0, 300 ) ) );
			return $no( 'Error interno; quedó registrado en el log.' );
		}
		$u = wp_get_current_user();
		CN_Webhook::registrar_log_mp( 'reactivada_manual', array(
			'miembro_id'     => $id,
			'preapproval_id' => $pre,
			'usuario_id'     => (int) get_current_user_id(),
			'usuario'        => ( $u && ! empty( $u->user_login ) ) ? (string) $u->user_login : '',
		) );
		return array( 'ok' => true, 'mensaje' => 'Ficha ' . $id . ' reactivada: Mercado Pago confirma la suscripción como authorized.' );
	}

	public static function pagina() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		echo '<div class="wrap"><h1>Socias a revisar</h1>';
		echo '<p style="max-width:820px;">Fichas con suscripción (<code>preapproval_id</code>) que están <strong>canceladas</strong> o todavía tienen <strong>fecha de fin de trial</strong>. Son las que el viejo cierre por "trial vencido" pudo dejar sin acceso aunque su suscripción siga vigente. Solo lectura, hasta ' . (int) self::LIMITE . ' filas. <strong>Reactivar</strong> funciona de a una ficha: consulta a Mercado Pago (solo lectura) y solo reactiva si la suscripción está <em>authorized</em>.</p>';
		if ( isset( $_POST['cn_rev_reactivar'] ) ) {
			if ( check_admin_referer( self::NONCE, 'cn_rev_nonce' ) ) {
				$r = self::reactivar( absint( $_POST['cn_rev_reactivar'] ) );
				echo '<div class="notice notice-' . ( $r['ok'] ? 'success' : 'warning' ) . '"><p>' . esc_html( $r['mensaje'] ) . '</p></div>';
			}
		}
		$filas = self::listar();
		if ( ! $filas ) {
			echo '<p><em>No hay fichas para revisar.</em></p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Nombre</th><th>Estado</th><th>Plan</th><th>Fin de trial (UTC)</th><th>Suscripción</th><th>Modificada</th><th></th></tr></thead><tbody>';
		foreach ( $filas as $f ) {
			echo '<tr><td>' . (int) $f->id . '</td><td>' . esc_html( (string) $f->nombre_apellido ) . '</td><td>' . esc_html( (string) $f->estado ) . '</td><td>' . esc_html( (string) $f->plan ) . '</td><td>' . esc_html( (string) $f->fecha_fin_trial ) . '</td><td><code>' . esc_html( self::mascara_pre( $f->preapproval_id ) ) . '</code></td><td>' . esc_html( (string) $f->fecha_modificacion ) . '</td><td>';
			if ( 'cancelada' === (string) $f->estado ) {
				echo '<form method="post" onsubmit="return confirm(\'¿Reactivar esta ficha? Se consulta a Mercado Pago y solo se reactiva si su suscripción está authorized.\');">';
				wp_nonce_field( self::NONCE, 'cn_rev_nonce' );
				echo '<button type="submit" name="cn_rev_reactivar" value="' . (int) $f->id . '" class="button">Reactivar</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
CN_Socias_Revisar::registrar(); // se engancha solo: borrar la pantalla = quitar el require_once y este archivo.
