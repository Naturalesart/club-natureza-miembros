<?php
// "Suscripciones sin vincular": titular de la tarjeta (solo GET, con tope y cache) y candidatas "medio (por nombre)".
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
CN_Sin_Vincular::$terminar = false;
function nm_id( $n ) {
	return md5( 'nom' . $n );
}
function nm_sub( $n, $email = null, $monto = 15500 ) {
	$s = array( 'id' => nm_id( $n ), 'status' => 'authorized', 'date_created' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 86400 - $n ), 'next_payment_date' => '2026-11-10T00:00:00.000-03:00', 'auto_recurring' => array( 'transaction_amount' => $monto ) );
	if ( null !== $email ) {
		$s['payer_email'] = $email;
	}
	return $s;
}
function nm_ficha( $id, $nombre, $cel, $extra = array() ) {
	return cn_fila( array_merge( array(
		'id' => $id, 'nombre_apellido' => $nombre, 'estado' => 'activo', 'preapproval_id' => null, 'plan' => null, 'acceso_hasta' => null, 'email' => 'f' . $id . '@example.com',
		'fecha_fin_trial' => null, 'fecha_modificacion' => null, 'baja_en' => null, 'celular_hint' => substr( $cel, -4 ), 'celular_hash' => CN_Helpers::hash_celular( $cel ), 'celular_texto_plano' => $cel,
	), $extra ) );
}
/** MP: lista de subs, cobro + titular por sub ($titulares: n => nombre | null sin cobro | false cobro sin titular | int código HTTP). */
function nm_preparar( $subs, $fichas, $titulares = array() ) {
	cn_test_reset();
	global $wpdb;
	unset( $GLOBALS['cn_caps'], $GLOBALS['cn_nonce_ok'] );
	$_POST = $_GET = array();
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => $subs, 'paging' => array( 'total' => count( $subs ) ) ) );
	cn_http_on( 'GET', '#/v1/payments/search#', array( 'results' => array(), 'paging' => array( 'total' => 0 ) ) );
	cn_http_on( 'GET', '#/authorized_payments/search#', array( 'results' => array() ) ); // por defecto: sin cobros
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	foreach ( $titulares as $n => $t ) {
		$pid = 7000000 + $n;
		if ( is_int( $t ) ) {
			cn_http_on( 'GET', '#/authorized_payments/search\?preapproval_id=' . nm_id( $n ) . '#', $t );
			continue;
		}
		if ( null === $t ) {
			continue;
		}
		cn_http_on( 'GET', '#/authorized_payments/search\?preapproval_id=' . nm_id( $n ) . '#', array( 'results' => array( array( 'id' => 1, 'payment' => array( 'id' => $pid, 'status' => 'approved' ) ) ) ) );
		cn_http_on( 'GET', '#/v1/payments/' . $pid . '$#', false === $t ? array( 'id' => $pid, 'status' => 'approved', 'payer' => array( 'id' => 1 ) ) : array( 'id' => $pid, 'status' => 'approved', 'card' => array( 'cardholder' => array( 'name' => $t ) ) ) );
	}
	$wpdb->on( '/SELECT ' . preg_quote( CN_Vinculo_Manual::COLS, '/' ) . ' FROM wp_cn_miembros/', $fichas );
	return $wpdb;
}
function nm_por_id( $r ) {
	$m = array();
	foreach ( $r['filas'] as $f ) {
		$m[ $f['id'] ] = $f;
	}
	return $m;
}
function nm_pagina( $post ) {
	$_POST = $post;
	ob_start();
	CN_Sin_Vincular::pagina();
	$h = ob_get_clean();
	$_POST = array();
	return $h;
}

seccion( 'normalización y coincidencia por nombre (puras)' );
t( 'tokens: minúsculas, sin tildes, solo letras, 2+ letras', array( 'maria', 'jose', 'gomez' ) === CN_Sin_Vincular::tokens_nombre( 'MARÍA José  Gómez 3' ) && array( 'ana', 'lopez' ) === CN_Sin_Vincular::tokens_nombre( "Ana\tLópez" ) );
t( 'tildes y mayúsculas no importan', CN_Sin_Vincular::coincide_nombre( 'MARIA JOSE GOMEZ', 'María José Gómez' ) );
t( 'orden invertido (apellido nombre) coincide', CN_Sin_Vincular::coincide_nombre( 'Gomez Maria Jose', 'María José Gómez' ) );
t( 'coincidencia total: todos los tokens de la ficha están en el titular (titular más largo)', CN_Sin_Vincular::coincide_nombre( 'Maria Jose Gomez Perez', 'Maria Gomez' ) );
t( 'coincidencia total al revés: todos los del titular están en la ficha', CN_Sin_Vincular::coincide_nombre( 'Maria Gomez', 'Maria Jose Gomez Perez' ) );
t( 'parcial (ninguno contiene al otro): NO coincide', ! CN_Sin_Vincular::coincide_nombre( 'Maria Jose Gomez', 'Maria Gomez Perez' ) );
t( '1 solo token en común no alcanza', ! CN_Sin_Vincular::coincide_nombre( 'Maria Gomez', 'Maria Perez' ) && ! CN_Sin_Vincular::coincide_nombre( 'Ana', 'Ana Lopez' ) );
t( 'nombres vacíos o de un token: no coinciden', ! CN_Sin_Vincular::coincide_nombre( '', 'Ana Lopez' ) && ! CN_Sin_Vincular::coincide_nombre( 'Ana Lopez', 'Lopez' ) );
t( 'la ñ y los guiones se normalizan', CN_Sin_Vincular::coincide_nombre( 'MUÑOZ-SOSA CARLA', 'Carla Munoz Sosa' ) );

seccion( 'candidatas por nombre: nivel medio (por nombre), nunca fuerte' );
$fichas = array(
	nm_ficha( 1, 'María José Gómez', '1155510001' ),
	nm_ficha( 2, 'Laura Diaz', '1144420002' ),
	nm_ficha( 3, 'Diaz Laura', '1133330003' ),
	nm_ficha( 4, 'Carla Muñoz', '1122240004', array( 'preapproval_id' => nm_id( 99 ) ) ), // con suscripción: excluida
	nm_ficha( 5, 'Pedro Paz', '1166650005' ),
);
$subs = array(
	nm_sub( 1, 'otro1@example.com' ), // titular MARIA JOSE GOMEZ -> ficha 1 (única)
	nm_sub( 2, 'otro2@example.com' ), // titular LAURA DIAZ -> fichas 2 y 3 (varias)
	nm_sub( 3, 'otro3@example.com' ), // titular CARLA MUÑOZ -> solo la 4, que tiene suscripción -> sin match
	nm_sub( 4, null ),                 // sin email; titular NADIE CONOCIDO -> sin match
	nm_sub( 5, 'otro5@example.com' ), // sin cobro aprobado
	nm_sub( 6, 'otro6@example.com' ), // cobro sin titular
	nm_sub( 7, 'otro7@example.com' ), // MP 500 en authorized_payments
	nm_sub( 8, 'f5@example.com' ),    // candidata por EMAIL (ficha 5): el titular NO se consulta
	nm_sub( 9, 'otro9@example.com', 9999 ), // monto sin plan: titular se muestra pero no sube de nivel
);
$tit = array( 1 => 'MARIA JOSE GOMEZ', 2 => 'LAURA DIAZ', 3 => 'CARLA MUÑOZ', 4 => 'NADIE CONOCIDO', 5 => null, 6 => false, 7 => 500, 8 => 'PEDRO PAZ', 9 => 'PEDRO PAZ' );
$w = nm_preparar( $subs, $fichas, $tit );
$r = CN_Sin_Vincular::consultar( true );
$m = nm_por_id( $r );
t( 'una sola ficha coincide: medio (por nombre) con esa candidata y el motivo', 'medio_nombre' === $m[ nm_id( 1 ) ]['nivel'] && 1 === count( $m[ nm_id( 1 ) ]['candidatas'] ) && 1 === (int) $m[ nm_id( 1 ) ]['candidatas'][0]->id && 'nombre del titular coincide' === $m[ nm_id( 1 ) ]['motivo'] && 'MARIA JOSE GOMEZ' === $m[ nm_id( 1 ) ]['titular'] );
t( 'nunca fuerte por nombre: solo es fuerte la que tiene candidata por EMAIL (sub 8); las de nombre quedan en medio', array( nm_id( 8 ) ) === array_column( array_filter( $r['filas'], function ( $f ) {
	return 'fuerte' === $f['nivel'];
} ), 'id' ) && ! array_filter( $r['filas'], function ( $f ) {
	return 'fuerte' === $f['nivel'] && CN_Sin_Vincular::MOTIVO_NOMBRE === $f['motivo'];
} ) );
t( 'varias fichas coinciden: medio (por nombre) con todas', 'medio_nombre' === $m[ nm_id( 2 ) ]['nivel'] && array( 2, 3 ) === array_map( function ( $c ) {
	return (int) $c->id;
}, $m[ nm_id( 2 ) ]['candidatas'] ) );
t( 'la ficha con preapproval_id queda excluida: sin match aunque el nombre coincida', 'sin_match' === $m[ nm_id( 3 ) ]['nivel'] && array() === $m[ nm_id( 3 ) ]['candidatas'] && 'CARLA MUÑOZ' === $m[ nm_id( 3 ) ]['titular'] );
t( 'titular que no coincide con nadie: sin match, con el titular visible', 'sin_match' === $m[ nm_id( 4 ) ]['nivel'] && 'NADIE CONOCIDO' === $m[ nm_id( 4 ) ]['titular'] );
t( 'sin cobro aprobado: "sin titular (sin cobro aprobado)"', 'sin_cobro' === $m[ nm_id( 5 ) ]['titular_estado'] && 'sin titular (sin cobro aprobado)' === CN_Sin_Vincular::titular_texto( $m[ nm_id( 5 ) ] ) );
t( 'cobro sin cardholder: "sin titular (el cobro no lo trae)"', 'sin_titular' === $m[ nm_id( 6 ) ]['titular_estado'] && false !== strpos( CN_Sin_Vincular::titular_texto( $m[ nm_id( 6 ) ] ), 'sin titular' ) );
t( 'MP caído en una suscripción: no rompe, "sin titular (MP no respondió)" y las demás siguen', 'error' === $m[ nm_id( 7 ) ]['titular_estado'] && 'sin titular (MP no respondió)' === CN_Sin_Vincular::titular_texto( $m[ nm_id( 7 ) ] ) && 9 === count( $r['filas'] ) );
t( 'con candidata por email no se consulta el titular', '' === $m[ nm_id( 8 ) ]['titular'] && '' === $m[ nm_id( 8 ) ]['titular_estado'] && ! cn_http_calls( '#preapproval_id=' . nm_id( 8 ) . '#' ) && 'fuerte' === $m[ nm_id( 8 ) ]['nivel'] );
t( 'monto sin plan: se muestra el titular pero sigue sin match (no se puede vincular)', 'PEDRO PAZ' === $m[ nm_id( 9 ) ]['titular'] && 'sin_match' === $m[ nm_id( 9 ) ]['nivel'] && array() === $m[ nm_id( 9 ) ]['candidatas'] );
t( 'orden: fuerte, medio (por nombre), sin match', array( 'fuerte', 'medio_nombre', 'medio_nombre', 'sin_match', 'sin_match', 'sin_match', 'sin_match', 'sin_match', 'sin_match' ) === array_column( $r['filas'], 'nivel' ) );
t( 'solo GET a MP', array( 'GET' ) === array_values( array_unique( array_column( $GLOBALS['cn_http_log'], 'metodo' ) ) ) );
t( 'cero escrituras en miembros y ninguna opción nueva (solo transients); en mp_log solo el mp_api_error del 500, que deja CN_MP como siempre', ! $w->escrituras( 'cn_miembros' ) && ! $w->transaccion() && array( 'mp_api_error', 'aviso_error' ) === array_values( array_unique( $w->log_mp_tipos() ) ) && array( 'cn_mp_access_token' => 'APP_USR-test' ) === $GLOBALS['cn_opts'] );

seccion( 'cache de titulares (6 hs) y de la consulta (10 min)' );
$w = nm_preparar( $subs, $fichas, $tit );
CN_Sin_Vincular::consultar( true );
$cache = get_transient( CN_Sin_Vincular::TRANSIENT_TITULARES );
t( 'el transient de titulares guarda ok, sin_cobro y sin_titular, pero NO los errores', isset( $cache[ nm_id( 1 ) ], $cache[ nm_id( 5 ) ], $cache[ nm_id( 6 ) ] ) && ! isset( $cache[ nm_id( 7 ) ] ) && ! isset( $cache[ nm_id( 8 ) ] ) && 'MARIA JOSE GOMEZ' === $cache[ nm_id( 1 ) ]['nombre'] );
$n1 = count( cn_http_calls( '#preapproval_id=' . nm_id( 1 ) . '#' ) );
$n7 = count( cn_http_calls( '#preapproval_id=' . nm_id( 7 ) . '#' ) );
delete_transient( CN_Sin_Vincular::TRANSIENT );
$r = CN_Sin_Vincular::consultar( true );
$m = nm_por_id( $r );
t( 'una consulta nueva reutiliza los titulares cacheados (la sub 1 no se vuelve a pedir) y reintenta solo el que falló (sub 7)', count( cn_http_calls( '#preapproval_id=' . nm_id( 1 ) . '#' ) ) === $n1 && count( cn_http_calls( '#preapproval_id=' . nm_id( 7 ) . '#' ) ) === $n7 + 1 && 'medio_nombre' === $m[ nm_id( 1 ) ]['nivel'] && 'MARIA JOSE GOMEZ' === $m[ nm_id( 1 ) ]['titular'] );
$n_total = count( $GLOBALS['cn_http_log'] );
$r2 = CN_Sin_Vincular::consultar();
t( 'dentro de los 10 min la consulta sale del transient con titulares y niveles incluidos', count( $GLOBALS['cn_http_log'] ) === $n_total && 'MARIA JOSE GOMEZ' === nm_por_id( $r2 )[ nm_id( 1 ) ]['titular'] );

seccion( 'tope de pedidos: 90 por consulta, el resto queda "tope" sin romper' );
$muchas = array();
$tits   = array();
for ( $i = 1; $i <= 60; $i++ ) { // 60 subs sin candidata = 120 pedidos > 90
	$muchas[]   = nm_sub( 100 + $i, 'x' . $i . '@example.com' );
	$tits[ 100 + $i ] = 'TITULAR ' . $i . ' APELLIDO';
}
$w = nm_preparar( $muchas, $fichas, $tits );
$r = CN_Sin_Vincular::consultar( true );
$pedidos = count( cn_http_calls( '#authorized_payments/search#' ) ) + count( cn_http_calls( '#/v1/payments/\d+$#' ) );
$estados = array_count_values( array_column( $r['filas'], 'titular_estado' ) );
t( 'nunca más de 90 pedidos de titular por consulta', $pedidos <= 90 && $pedidos >= 88, (string) $pedidos );
t( 'los que entraron tienen titular; los demás quedan en "tope" (no rompe, 60 filas)', 60 === count( $r['filas'] ) && ( $estados['ok'] ?? 0 ) >= 44 && ( $estados['tope'] ?? 0 ) >= 15 && false !== strpos( CN_Sin_Vincular::titular_texto( end( $r['filas'] ) ), 'tope' ), json_encode( $estados ) );
t( 'los "tope" no se cachean: la próxima consulta los reintenta', ! isset( get_transient( CN_Sin_Vincular::TRANSIENT_TITULARES )[ nm_id( 160 ) ] ) );
t( 'el presupuesto es el pedido: 90 pedidos, 20 s, 60 s', 90 === CN_Sin_Vincular::TITULAR_REQUESTS && 20 === CN_Sin_Vincular::TITULAR_ESPERA_SEG && 60 === CN_Sin_Vincular::TITULAR_TIEMPO_SEG );
$w = nm_preparar( array( nm_sub( 1, 'otro1@example.com' ) ), $fichas, array( 1 => 'MARIA JOSE GOMEZ' ) );
cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', 500 );
$r = CN_Sin_Vincular::consultar( true );
t( 'MP caído del todo: la consulta falla limpia, sin titulares ni filas', ! $r['ok'] && ! $r['filas'] && ! cn_http_calls( '#authorized_payments#' ) );

seccion( 'pantalla: columna Titular (MP), candidatas por nombre enmascaradas, botón' );
$w = nm_preparar( $subs, $fichas, $tit );
$h = nm_pagina( array( 'cn_sv_accion' => 'consultar', 'cn_sv_nonce' => 'x' ) );
t( 'columna "Titular (MP)" con el nombre completo del titular', false !== strpos( $h, '<th>Titular (MP)</th>' ) && false !== strpos( $h, '<td>MARIA JOSE GOMEZ</td>' ) && false !== strpos( $h, 'sin titular (MP no respondió)' ) );
t( 'candidata por nombre: id, nombre, estado y celular enmascarado (****hint), con el motivo', false !== strpos( $h, '#1 María José Gómez · activo · ****0001' ) && false !== strpos( $h, 'nombre del titular coincide' ) && false === strpos( $h, '1155510001' ) );
t( 'etiqueta y contador "Medio (por nombre)"', false !== strpos( $h, 'Medio (por nombre)' ) && false !== strpos( $h, 'medio (por nombre): 2' ) );
$r = CN_Sin_Vincular::consultar();
$m = nm_por_id( $r );
t( 'botón: una sola candidata por nombre -> cn_vincular_id + cn_vincular_socia', false !== strpos( CN_Sin_Vincular::url_vincular( $m[ nm_id( 1 ) ] ), 'cn_vincular_id=' . nm_id( 1 ) . '&cn_vincular_socia=1#cn-vincular-mp' ) );
t( 'botón: varias candidatas por nombre -> solo cn_vincular_id', false === strpos( CN_Sin_Vincular::url_vincular( $m[ nm_id( 2 ) ] ), 'cn_vincular_socia' ) );
t( 'la pantalla sigue sin formularios de vinculación (solo enlaces de a una)', false === strpos( $h, 'cn_vm_accion' ) && false === strpos( $h, 'cn_vl_' ) && 9 === substr_count( $h, '#cn-vincular-mp' ) );
t( 'el código sigue sin escrituras ni POST', ! preg_match( '/->insert\(|->update\(|->delete\(|->query\(|update_option|registrar_log_mp|wp_remote_post|wp_remote_request|vincular_suscripcion\(|confirmar\(/', file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-sin-vincular.php' ) ) );

seccion( 'CSV: titular_tarjeta y candidata_celular_ultimos4' );
$w = nm_preparar( $subs, $fichas, $tit );
$_GET  = array( 'page' => 'cn-sin-vincular' );
$_POST = array( 'cn_sv_accion' => 'csv', 'cn_sv_nonce' => 'x' );
ob_start();
CN_Sin_Vincular::descargar_csv();
$csv = ob_get_clean();
$_GET = $_POST = array();
t( 'cabecera con las columnas nuevas al final', false !== strpos( $csv, 'candidata_estado,titular_tarjeta,candidata_celular_ultimos4' ) );
t( 'fila medio (por nombre): titular completo y últimos 4 del celular de la candidata', false !== strpos( $csv, nm_id( 1 ) . ',otro1@example.com,basico,15500,2026-11-10,' ) && 1 === preg_match( '/' . nm_id( 1 ) . '.*,medio_nombre,"nombre del titular coincide",1,"María José Gómez",activo,"MARIA JOSE GOMEZ",0001/u', $csv ) );
t( 'varias candidatas: últimos 4 separados con |', 1 === preg_match( '/' . nm_id( 2 ) . '.*"LAURA DIAZ","0002 \| 0003"/', $csv ) );
t( 'nunca el celular completo', false === strpos( $csv, '1155510001' ) && false === strpos( $csv, '1144420002' ) && false === strpos( $csv, '****' ) );
t( 'sin titular: columna vacía', 1 === preg_match( '/' . nm_id( 7 ) . '[^\n]*,,\n/', $csv ) || 1 === preg_match( '/' . nm_id( 7 ) . '[^\n]*,,$/m', $csv ) );
