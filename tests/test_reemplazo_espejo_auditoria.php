<?php
// "Auditoría del espejo" (CN_Espejo_Auditoria): simulación de CN_Espejo_MP::decidir sobre todas las fichas. SOLO LECTURA.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
require_once __DIR__ . '/test_punto6_lote.php';
function ad_id( $n ) {
	return lt_id( 500 + $n );
}
/** Ficha $n (id 300+$n) vinculada a ad_id($n), activa y con acceso a futuro (determinista para la fecha de baja propuesta). */
function ad_ficha( $n, $extra = array() ) {
	return lt_fila( 300 + $n, '11555530' . str_pad( (string) $n, 2, '0', STR_PAD_LEFT ), array_merge( array(
		'nombre_apellido' => 'Auditada ' . $n, 'estado' => 'activo', 'preapproval_id' => ad_id( $n ), 'plan' => 'basico',
		'acceso_hasta' => '2027-01-10 00:00:00', 'baja_en' => null,
	), $extra ) );
}
/**
 * Prepara el entorno: fichas en la base (con el SELECT por cursor del auditor), respuesta de /preapproval por sub
 * ($subs: id => array|null|int) y cobros por sub ($pagos: id => 'reciente'|'viejo'|null). Devuelve el wpdb falso.
 */
function ad_preparar( $fichas, $subs = array(), $pagos = array() ) {
	cn_test_reset();
	global $wpdb;
	$_POST = array();
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	$wpdb->on( '/WHERE id > (\d+) ORDER BY id ASC LIMIT (\d+)/', function ( $sql ) use ( $fichas ) {
		preg_match( '/WHERE id > (\d+) ORDER BY id ASC LIMIT (\d+)/', $sql, $m );
		$out = array_values( array_filter( $fichas, function ( $f ) use ( $m ) {
			return (int) $f->id > (int) $m[1];
		} ) );
		usort( $out, function ( $a, $b ) {
			return (int) $a->id <=> (int) $b->id;
		} );
		return array_slice( $out, 0, (int) $m[2] );
	} );
	foreach ( $subs as $id => $resp ) {
		cn_http_on( 'GET', '#/preapproval/' . $id . '#', $resp );
	}
	$reciente = array( 'results' => array( array( 'payment' => array( 'id' => 1, 'status' => 'approved' ), 'debit_date' => gmdate( 'c', time() - 5 * DAY_IN_SECONDS ) ) ) );
	$viejo    = array( 'results' => array( array( 'payment' => array( 'id' => 1, 'status' => 'approved' ), 'debit_date' => gmdate( 'c', time() - 90 * DAY_IN_SECONDS ) ) ) );
	cn_http_on( 'GET', '#/authorized_payments/search#', $viejo ); // por defecto: sin pago reciente (los específicos van después: cn_http_on antepone).
	foreach ( $pagos as $id => $tipo ) {
		cn_http_on( 'GET', '#/authorized_payments/search\?preapproval_id=' . $id . '#', 'reciente' === $tipo ? $reciente : ( 'viejo' === $tipo ? $viejo : $tipo ) );
	}
	return $wpdb;
}
/**
 * La auditoría es SOLO LECTURA: nada en miembros, ningún registro propio en mp_log, sin opciones nuevas, sin mails ni
 * Meta. Única excepción tolerada: el rastro mp_api_error (y su transient cn_mpapi_*) que CN_MP ya deja solo cuando un
 * GET falla; no lo escribe la auditoría.
 */
function ad_limpia( $w, $opts_antes, $trans_antes ) {
	foreach ( $w->log as $l ) {
		if ( 'q' === $l[0] ) {
			return false; // ni transacciones.
		}
		if ( false === strpos( $l[1], 'mp_log' ) || ! in_array( $l[2]['tipo'], array( 'mp_api_error', 'aviso_error' ), true ) ) {
			return false;
		}
	}
	foreach ( array_diff_key( $GLOBALS['cn_transients'], $trans_antes ) as $k => $v ) {
		if ( 0 !== strpos( (string) $k, 'cn_mpapi_err_' ) ) {
			return false;
		}
	}
	return $opts_antes === $GLOBALS['cn_opts'] && ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'];
}
function ad_solo_get() {
	foreach ( $GLOBALS['cn_http_log'] as $c ) {
		if ( 'GET' !== $c['metodo'] ) {
			return false;
		}
	}
	return true;
}
function ad_fila_de( $res, $id ) {
	foreach ( $res['filas'] as $x ) {
		if ( $id === $x['id'] ) {
			return $x;
		}
	}
	return null;
}

seccion( 'auditoría: una tanda con todos los casos' );
$fichas = array(
	ad_ficha( 1 ),                                                            // authorized, sin baja -> al día
	ad_ficha( 2 ),                                                            // paused + pago reciente -> omitida
	ad_ficha( 3 ),                                                            // paused sin pago reciente -> propondría baja
	ad_ficha( 4 ),                                                            // cancelled sin pago reciente -> propondría baja
	ad_ficha( 5, array( 'estado' => 'pausado' ) ),                            // ficha ya pausada -> sin acción
	ad_ficha( 6, array( 'fecha_fin_trial' => '2026-10-20 00:00:00' ) ),       // trial -> fuera, sin consultar MP
	ad_ficha( 7, array( 'estado' => 'cancelada' ) ),                          // ficha cancelada -> fuera, sin consultar MP
	ad_ficha( 8, array( 'preapproval_id' => null, 'plan' => null ) ),         // sin sub -> fuera, sin consultar MP
	ad_ficha( 9 ),                                                            // MP caído para esta sub -> sin datos
	ad_ficha( 10, array( 'baja_en' => '2026-11-01 00:00:00' ) ),              // authorized con baja -> propondría revertir
);
$subs = array(
	ad_id( 1 )  => lt_mp( ad_id( 1 ), 'authorized' ),
	ad_id( 2 )  => lt_mp( ad_id( 2 ), 'paused' ),
	ad_id( 3 )  => lt_mp( ad_id( 3 ), 'paused' ),
	ad_id( 4 )  => lt_mp( ad_id( 4 ), 'cancelled' ),
	ad_id( 5 )  => lt_mp( ad_id( 5 ), 'paused' ),
	ad_id( 9 )  => null,
	ad_id( 10 ) => lt_mp( ad_id( 10 ), 'authorized' ),
);
$w = ad_preparar( $fichas, $subs, array( ad_id( 2 ) => 'reciente', ad_id( 3 ) => 'viejo', ad_id( 4 ) => 'viejo' ) );
$opts  = $GLOBALS['cn_opts'];
$trans = $GLOBALS['cn_transients'];
$res   = CN_Espejo_Auditoria::auditar( 0 );
t( 'ok, completa, 10 filas', $res['ok'] && ! $res['parcial'] && 10 === count( $res['filas'] ), json_encode( array( $res['ok'], $res['parcial'], count( $res['filas'] ) ) ) );
t( 'activa al día', 'al_dia' === ad_fila_de( $res, 301 )['bucket'] && 'authorized' === ad_fila_de( $res, 301 )['status_mp'] );
t( 'pausada con pago reciente: omitida (pago_reciente)', 'omitida' === ad_fila_de( $res, 302 )['bucket'] && false !== strpos( ad_fila_de( $res, 302 )['decision'], 'pago_reciente' ) );
$f3 = ad_fila_de( $res, 303 );
t( 'pausada sin pago reciente: propondría baja con fecha = fin del período + 7 días', 'baja' === $f3['bucket'] && '2027-01-17 00:00' === $f3['baja_prop'] && false !== strpos( $f3['decision'], 'propondría baja' ), json_encode( $f3 ) );
t( 'la baja propuesta NO corta el acceso vigente', false === $f3['corta_acceso'] );
t( 'sub cancelada sin pago reciente: propondría baja', 'baja' === ad_fila_de( $res, 304 )['bucket'] );
$f5 = ad_fila_de( $res, 305 );
t( 'ficha ya pausada: sin acción (estado no activo), con el estado de MP a la vista', 'fuera' === $f5['bucket'] && false !== strpos( $f5['decision'], 'estado pausado' ) && 'paused' === $f5['status_mp'] );
t( 'trial: fuera de alcance SIN consultar MP', 'fuera' === ad_fila_de( $res, 306 )['bucket'] && ! cn_http_calls( '#' . ad_id( 6 ) . '#' ) );
t( 'ficha cancelada: fuera de alcance sin consultar MP', 'fuera' === ad_fila_de( $res, 307 )['bucket'] && ! cn_http_calls( '#' . ad_id( 7 ) . '#' ) );
t( 'sin suscripción: fuera de alcance', 'fuera' === ad_fila_de( $res, 308 )['bucket'] && false !== strpos( ad_fila_de( $res, 308 )['decision'], 'sin suscripción' ) );
t( 'MP caído para una sub: fila "sin datos" y la auditoría sigue con las demás', 'sin_datos' === ad_fila_de( $res, 309 )['bucket'] && null !== ad_fila_de( $res, 310 ) );
t( 'authorized con baja programada: propondría revertir', 'revertir' === ad_fila_de( $res, 310 )['bucket'] );
t( 'conteos del resumen', array( 'baja' => 2, 'revertir' => 1, 'omitida' => 1, 'al_dia' => 1, 'fuera' => 4, 'sin_datos' => 1 ) === $res['conteos'], json_encode( $res['conteos'] ) );
t( 'cero escrituras: ni miembros, ni mp_log, ni opciones, ni transients, ni mails, ni Meta', ad_limpia( $w, $opts, $trans ) );
t( 'MP: solo GET', ad_solo_get() && count( $GLOBALS['cn_http_log'] ) > 0 );
t( 'el GET de cobros solo se hace cuando hace falta (no para la authorized al día)', ! cn_http_calls( '#authorized_payments.*' . ad_id( 1 ) . '#' ) && 1 === count( cn_http_calls( '#authorized_payments.*' . ad_id( 3 ) . '#' ) ) );

seccion( 'auditoría: columna "¿cortaría el acceso?"' );
t( 'baja antes del acceso vigente: marca', true === CN_Espejo_Auditoria::corta_acceso( strtotime( '2026-12-01 UTC' ), '2027-01-10 00:00:00' ) );
t( 'baja después del acceso: no marca', false === CN_Espejo_Auditoria::corta_acceso( strtotime( '2027-01-17 UTC' ), '2027-01-10 00:00:00' ) );
t( 'sin acceso previo: no marca', false === CN_Espejo_Auditoria::corta_acceso( strtotime( '2026-12-01 UTC' ), '' ) );

seccion( 'auditoría: parcial y continuar (por tandas, con cursor)' );
$muchas = array();
$subs   = array();
for ( $n = 1; $n <= 45; $n++ ) {
	$muchas[] = ad_ficha( $n );
	$subs[ ad_id( $n ) ] = lt_mp( ad_id( $n ), 'authorized' );
}
$w     = ad_preparar( $muchas, $subs );
$opts  = $GLOBALS['cn_opts'];
$trans = $GLOBALS['cn_transients'];
$res   = CN_Espejo_Auditoria::auditar( 0 );
t( 'primera tanda: 40 filas, parcial, cursor en la última auditada', $res['parcial'] && 40 === count( $res['filas'] ) && 340 === $res['cursor'] && 40 === $res['conteos']['al_dia'], json_encode( array( $res['parcial'], count( $res['filas'] ), $res['cursor'] ) ) );
$res2 = CN_Espejo_Auditoria::auditar( $res['cursor'] );
t( 'continuar: las 5 restantes y ya no es parcial', ! $res2['parcial'] && 5 === count( $res2['filas'] ) && 341 === $res2['filas'][0]['id'] && 345 === $res2['cursor'] );
t( 'las dos tandas tampoco escribieron nada', ad_limpia( $w, $opts, $trans ) && ad_solo_get() );

seccion( 'auditoría: sin token, permisos y nonce' );
cn_test_reset();
$res = CN_Espejo_Auditoria::auditar( 0 );
t( 'sin token: error claro y cero consultas', ! $res['ok'] && '' !== $res['error'] && ! $GLOBALS['cn_http_log'] );
$w = ad_preparar( array( ad_ficha( 1 ) ), array( ad_id( 1 ) => lt_mp( ad_id( 1 ) ) ) );
$GLOBALS['cn_caps'] = array();
$_POST = array( 'cn_au_accion' => 'auditar', 'cn_au_nonce' => 'x' );
ob_start();
CN_Espejo_Auditoria::pagina();
$html = ob_get_clean();
unset( $GLOBALS['cn_caps'] );
t( 'sin manage_options: no muestra nada ni consulta a MP', '' === $html && ! $GLOBALS['cn_http_log'] );
$GLOBALS['cn_nonce_ok'] = false;
$_POST = array( 'cn_au_accion' => 'auditar', 'cn_au_nonce' => 'x' );
ob_start();
CN_Espejo_Auditoria::pagina();
ob_end_clean();
unset( $GLOBALS['cn_nonce_ok'] );
t( 'nonce inválido: no consulta a MP', ! $GLOBALS['cn_http_log'] );
$_POST = array();
ob_start();
CN_Espejo_Auditoria::pagina();
$html = ob_get_clean();
t( 'cargar la pantalla (GET) no consulta a MP y solo muestra el botón', ! $GLOBALS['cn_http_log'] && false !== strpos( $html, 'Auditar ahora' ) && false === strpos( $html, 'cn-au-tabla' ) );

seccion( 'auditoría: la pantalla con resultados' );
$w = ad_preparar( array( ad_ficha( 3 ) ), array( ad_id( 3 ) => lt_mp( ad_id( 3 ), 'paused' ) ), array( ad_id( 3 ) => 'viejo' ) );
$_POST = array( 'cn_au_accion' => 'auditar', 'cn_au_nonce' => 'x' );
ob_start();
CN_Espejo_Auditoria::pagina();
$html = ob_get_clean();
t( 'la tabla muestra ficha, estados, decisión, filtro y resumen; sin celulares', false !== strpos( $html, '#303 Auditada 3' ) && false !== strpos( $html, 'paused' ) && false !== strpos( $html, 'propondría baja' ) && false !== strpos( $html, 'cn-au-filtro' ) && false !== strpos( $html, 'propondría baja: 1' ) && false === strpos( $html, '115555' ), $html );
t( 'con una sola tanda completa no ofrece Continuar', false === strpos( $html, 'value="continuar"' ) );
$muchas = array();
$subs   = array();
for ( $n = 1; $n <= 41; $n++ ) {
	$muchas[] = ad_ficha( $n );
	$subs[ ad_id( $n ) ] = lt_mp( ad_id( $n ), 'authorized' );
}
$w = ad_preparar( $muchas, $subs );
$_POST = array( 'cn_au_accion' => 'auditar', 'cn_au_nonce' => 'x' );
ob_start();
CN_Espejo_Auditoria::pagina();
$html = ob_get_clean();
t( 'tanda parcial: aviso y botón Continuar con el cursor', false !== strpos( $html, 'Tanda parcial' ) && false !== strpos( $html, 'value="continuar"' ) && false !== strpos( $html, 'name="cn_au_cursor" value="340"' ) );
$_POST = array( 'cn_au_accion' => 'continuar', 'cn_au_nonce' => 'x', 'cn_au_cursor' => '340' );
ob_start();
CN_Espejo_Auditoria::pagina();
$html = ob_get_clean();
t( 'Continuar audita desde el cursor', false !== strpos( $html, '#341 Auditada 41' ) && false === strpos( $html, '#301 ' ) );
