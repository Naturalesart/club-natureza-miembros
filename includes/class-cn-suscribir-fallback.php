<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Etapa 1 del alta — del clic en el mail al pago (flag cn_suscribir_fallback_modo, off | log | on; default off).
 *
 * Plan A (en CN_Suscribir::procesar): el link firmado crea la suscripción aunque la ficha no tenga el celular en texto.
 *   La firma ya identifica la ficha: la pendiente guarda ficha_id y, sin celular, la external_reference es
 *   sub_f<ficha_id>_<hash> (sigue empezando con "sub_", así el webhook la reconoce igual). El webhook usa primero esa
 *   ficha (CN_Webhook::activar_por_suscripcion_sub).
 * Plan B: ante cualquier fallo de A (sin email, MP rechazó, etc.) → /planes-club/?cn_pre=<token> con el formulario de ese
 *   plan precargado (nombre y email de la ficha, que se leen de la base: nada personal viaja en la URL) y la ficha
 *   destino fijada. Un clic para pagar. Token de corta vida (2 hs), firmado con el mismo secreto que el link de los mails
 *   pero con otro dominio ("pre|"), así un token no sirve por el otro.
 * Plan C: si B también falla al crear la suscripción → checkout fijo del plan en MP + fila en cn_vincular_pendientes
 *   (para vincular a mano) + alerta a hola@. Si el plan no tiene checkout fijo configurado, queda el mensaje amable en
 *   /planes-club/ y la alerta igual sale.
 *
 * Rastro en mp_log: suscribir_fallback_b, suscribir_fallback_c, suscribir_fallback_log (modo log: qué habría hecho).
 * Nunca muestra jerga ni manda mails a la clienta.
 */
class CN_Suscribir_Fallback {
	const OPT_MODO  = 'cn_suscribir_fallback_modo';
	const QUERY_VAR = 'cn_pre';
	const VENCE_SEG = 7200;
	const OPT_LINK  = 'cn_checkout_link_'; // + plan: URL fija del checkout del plan en MP (plan C)
	/** Checkout fijo por plan si la opción está vacía. El de $15.500 es el que ya usa el aviso del día 5. */
	const LINKS_DEF = array( 'basico' => 'https://mpago.la/1FsDjhK', 'full' => '' );
	const HOSTS_MP  = array( 'mpago.la', 'www.mercadopago.com.ar', 'mercadopago.com.ar' );
	const MENSAJE_B = 'Ya casi está: revisá tus datos y tocá el botón para pagar en Mercado Pago.';
	const MENSAJE_C = 'No pudimos abrir el pago en este momento. Ya nos avisaron y te escribimos para ayudarte a terminar la suscripción.';

	public static function modo() {
		return CN_Flujo_Alta::modo( self::OPT_MODO );
	}

	// ---------------------------------------------------------------- token B
	protected static function firma( $payload ) {
		return hash_hmac( 'sha256', 'pre|' . (string) $payload, CN_Suscribir::secreto() );
	}

	/** <ficha>.<plan>.<origen>.<vence>.<hmac>; '' si falta el secreto o algo no está en la lista cerrada. */
	public static function token( $ficha_id, $plan, $origen, $vence = null ) {
		$id = (int) $ficha_id;
		if ( '' === CN_Suscribir::secreto() || $id <= 0 || ! in_array( $plan, CN_Suscribir::PLANES, true ) || ! in_array( $origen, CN_Suscribir::ORIGENES, true ) ) {
			return '';
		}
		$vence   = null === $vence ? time() + self::VENCE_SEG : (int) $vence;
		$payload = $id . '.' . $plan . '.' . $origen . '.' . $vence;
		return $payload . '.' . self::firma( $payload );
	}

	/**
	 * Valida el token B. Solo con el flag en 'on'. Orden: formato → firma → vencimiento → ficha existe.
	 *
	 * @return array{ok:bool,ficha_id:int,plan:string,origen:string,ficha:?object}
	 */
	public static function verificar( $token ) {
		global $wpdb;
		$r = array( 'ok' => false, 'ficha_id' => 0, 'plan' => '', 'origen' => '', 'ficha' => null );
		if ( 'on' !== self::modo() || '' === CN_Suscribir::secreto() || ! is_string( $token ) || strlen( $token ) > 160
			|| ! preg_match( '/^([1-9][0-9]{0,11})\.(basico|full)\.(mail_d5|mail_d6|mail_d7|winback|upsell)\.([0-9]{9,11})\.([a-f0-9]{64})\z/', trim( $token ), $m ) ) {
			return $r;
		}
		$payload = $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
		if ( ! hash_equals( self::firma( $payload ), $m[5] ) || (int) $m[4] < time() ) {
			return $r;
		}
		$ficha = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, nombre_apellido, estado, preapproval_id, baja_en, email, celular_texto_plano, celular_hash FROM ' . CN_DB::tabla( 'miembros' ) . ' WHERE id = %d',
			(int) $m[1]
		) );
		if ( '' !== (string) $wpdb->last_error || ! $ficha ) {
			return $r;
		}
		return array( 'ok' => true, 'ficha_id' => (int) $m[1], 'plan' => $m[2], 'origen' => $m[3], 'ficha' => $ficha );
	}

	/** Token B del request actual (GET en la página, POST al enviar el formulario), ya verificado. */
	public static function contexto( $post = null ) {
		$tok = '';
		if ( is_array( $post ) && isset( $post['cn_planes_pre'] ) && is_string( $post['cn_planes_pre'] ) ) {
			$tok = wp_unslash( $post['cn_planes_pre'] );
		} elseif ( null === $post && isset( $_GET[ self::QUERY_VAR ] ) && is_string( $_GET[ self::QUERY_VAR ] ) ) {
			$tok = wp_unslash( $_GET[ self::QUERY_VAR ] );
		}
		return '' === $tok ? array( 'ok' => false ) : self::verificar( $tok );
	}

	// ---------------------------------------------------------------- planes B y C
	/**
	 * Plan B: URL de /planes-club/ con el token. Con el flag en 'log' solo registra qué haría y devuelve ''.
	 * Si no se puede armar el token, pasa directo al plan C.
	 *
	 * @return array{redirect:string,plan:string} plan: '' (no hizo nada) | 'b' | 'c'
	 */
	public static function plan_b( $ficha_id, $plan, $origen, $motivo ) {
		$modo = self::modo();
		$base = array( 'ficha_id' => (int) $ficha_id, 'plan' => (string) $plan, 'origen' => (string) $origen, 'motivo' => (string) $motivo );
		if ( 'off' === $modo ) {
			return array( 'redirect' => '', 'plan' => '' );
		}
		if ( 'log' === $modo ) {
			CN_Webhook::registrar_log_mp( 'suscribir_fallback_log', $base + array( 'haria' => 'b' ) );
			return array( 'redirect' => '', 'plan' => '' );
		}
		$tok = self::token( $ficha_id, $plan, $origen );
		if ( '' === $tok ) {
			$c = self::plan_c( $ficha_id, $plan, $origen, $motivo . '+sin_token_b' );
			return array( 'redirect' => $c, 'plan' => 'c' );
		}
		CN_Webhook::registrar_log_mp( 'suscribir_fallback_b', $base );
		return array(
			'redirect' => add_query_arg( array( self::QUERY_VAR => $tok ), home_url( CN_Suscribir::PAGINA_PLANES ) ) . '#cn-pf-' . $plan,
			'plan'     => 'b',
		);
	}

	/**
	 * Plan C: deja la ficha "pendiente de vincular", alerta a hola@ y devuelve el checkout fijo del plan ('' si no hay).
	 * Solo con el flag en 'on' (quien llama ya lo verificó). Nunca lanza.
	 */
	public static function plan_c( $ficha_id, $plan, $origen, $motivo ) {
		global $wpdb;
		$link = self::link_checkout( $plan );
		try {
			$ok = $wpdb->insert(
				CN_DB::tabla( 'vincular_pendientes' ),
				array(
					'ficha_id' => (int) $ficha_id,
					'plan'     => mb_substr( (string) $plan, 0, 20 ),
					'origen'   => mb_substr( (string) $origen, 0, 20 ),
					'motivo'   => mb_substr( (string) $motivo, 0, 50 ),
					'creado'   => current_time( 'mysql', true ),
					'resuelto' => 0,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d' )
			);
		} catch ( Throwable $e ) {
			$ok = false;
		}
		CN_Webhook::registrar_log_mp( 'suscribir_fallback_c', array(
			'ficha_id'  => (int) $ficha_id,
			'plan'      => (string) $plan,
			'origen'    => (string) $origen,
			'motivo'    => (string) $motivo,
			'checkout'  => '' !== $link,
			'pendiente' => false !== $ok,
		) );
		CN_Flujo_Alta::alertar(
			'fallback_c|' . (int) $ficha_id,
			'Suscripción para vincular a mano (ficha ' . (int) $ficha_id . ')',
			array(
				'El link "Suscribirme" de un mail no pudo crear la suscripción y el formulario precargado tampoco.',
				'Ficha: ' . (int) $ficha_id . ' · plan: ' . (string) $plan . ' · origen: ' . (string) $origen . ' · motivo: ' . (string) $motivo,
				'' !== $link ? 'Se la mandó al checkout fijo del plan: si paga, el pago llega SIN referencia y hay que vincularlo a esa ficha.' : 'El plan no tiene checkout fijo configurado: quedó en /planes-club/ con un mensaje. Escribile para ayudarla a pagar.',
			)
		);
		return $link;
	}

	/** Checkout fijo del plan (opción cn_checkout_link_<plan> o el default). Solo https y dominios de MP; si no, ''. */
	public static function link_checkout( $plan ) {
		$plan = (string) $plan;
		$u    = trim( (string) get_option( self::OPT_LINK . $plan, '' ) );
		if ( '' === $u ) {
			$u = isset( self::LINKS_DEF[ $plan ] ) ? self::LINKS_DEF[ $plan ] : '';
		}
		$p = parse_url( $u );
		if ( ! is_array( $p ) || ( $p['scheme'] ?? '' ) !== 'https' || ! in_array( strtolower( (string) ( $p['host'] ?? '' ) ), self::HOSTS_MP, true ) ) {
			return '';
		}
		return $u;
	}
}
