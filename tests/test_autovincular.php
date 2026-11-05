<?php
// Auto-vinculación por candidata única (CN_Autovincular) + buscador de fichas del admin (CN_Admin::buscar_fichas).
// Regla de oro: nunca vincular a la persona equivocada; ante duda, no vincular y alertar (enmascarado).
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
function av_id( $n ) {
	return md5( 'av' . $n );
}
function av_hace( $seg ) {
	return gmdate( 'Y-m-d H:i:s', time() - $seg );
}
/** Suscripción authorized de MP pagada hace $hace segundos, con el nombre del pagador partido como lo manda MP. */
function av_item( $n, $first, $last, $hace = 7200, $monto = 15500, $extra = array() ) {
	return array_merge( array(
		'id'                => av_id( $n ),
		'status'            => 'authorized',
		'date_created'      => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - $hace ),
		'payer_first_name'  => $first,
		'payer_last_name'   => $last,
		'auto_recurring'    => array( 'transaction_amount' => $monto ),
		'next_payment_date' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() + 30 * 86400 ),
	), $extra );
}
/** Ficha con TODAS las columnas que leen el scan y el camino manual (VINC_COLUMNAS). Por defecto: trial activo, alta hace 10 días. */
function av_socia( $id, $nombre, $extra = array() ) {
	return cn_fila( array_merge( array(
		'id'                  => $id,
		'nombre_apellido'     => $nombre,
		'estado'              => 'activo',
		'preapproval_id'      => null,
		'plan'                => null,
		'acceso_hasta'        => null,
		'email'               => null,
		'celular_hint'        => '66' . str_pad( (string) $id, 2, '0', STR_PAD_LEFT ),
		'celular_texto_plano' => '11555566' . str_pad( (string) $id, 2, '0', STR_PAD_LEFT ),
		'fecha_alta'          => av_hace( 10 * 86400 ),
		'fecha_fin_trial'     => gmdate( 'Y-m-d H:i:s', time() + 2 * 86400 ),
		'fecha_modificacion'  => av_hace( 10 * 86400 ),
		'baja_en'             => null,
	), $extra ) );
}
/** Prepara MP (lista + cobros vacíos + el detalle de cada suscripción) y la base (socias + camino manual). */
function av_preparar( $items, $socias, $modo = 'on' ) {
	cn_test_reset();
	global $wpdb;
	$_GET = $_POST = array();
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	update_option( CN_Autovincular::OPT_MODO, $modo );
	cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => $items, 'paging' => array( 'total' => count( $items ) ) ) );
	cn_http_on( 'GET', '#/v1/payments/search#', array( 'results' => array(), 'paging' => array( 'total' => 0 ) ) );
	foreach ( $items as $it ) {
		cn_http_on( 'GET', '#/preapproval/' . $it['id'] . '$#', $it );
	}
	$wpdb->on( '/SELECT id, nombre_apellido, estado, preapproval_id, plan, acceso_hasta, email, celular_hint, fecha_alta, fecha_fin_trial, fecha_modificacion FROM wp_cn_miembros/', $socias );
	$wpdb->on( '/WHERE preapproval_id = /', array() );
	foreach ( $socias as $s ) {
		$wpdb->on( '/WHERE id = ' . (int) $s->id . ' FOR UPDATE/', array( $s ) );
	}
	return $wpdb;
}
function av_updates( $w ) {
	return array_values( array_filter( $w->escrituras( 'cn_miembros' ), function ( $e ) {
		return 'update' === $e[0];
	} ) );
}
function av_payload( $w, $tipo ) {
	$o = '';
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) && $tipo === $l[2]['tipo'] ) {
			$o .= (string) $l[2]['payload'] . "\n";
		}
	}
	return $o;
}

seccion( 'nombres: normalización por conjunto de palabras (mínimo 2 de cada lado)' );
t( 'mismo conjunto en otro orden y con tilde: coincide', CN_Autovincular::nombres_coinciden( 'Ana López', 'lopez ana' ) );
t( 'puntuación y espacios de más: coincide', CN_Autovincular::nombres_coinciden( '  ANA  lópez. ', 'Ana Lopez' ) );
t( 'subconjunto NO alcanza: "Ana López" vs "Ana María López"', ! CN_Autovincular::nombres_coinciden( 'Ana López', 'Ana María López' ) );
t( 'un solo nombre de pila NO alcanza (ni siquiera igual)', ! CN_Autovincular::nombres_coinciden( 'Ana', 'Ana' ) );
t( 'una palabra contra dos: no', ! CN_Autovincular::nombres_coinciden( 'Ana', 'Ana López' ) );
t( 'vacíos: no', ! CN_Autovincular::nombres_coinciden( '', 'Ana López' ) && ! CN_Autovincular::nombres_coinciden( '', '' ) );
t( 'máscara de nombre: iniciales + ***', 'A*** L***' === CN_Autovincular::mascara_nombre( 'Ana López' ) && '***' === CN_Autovincular::mascara_nombre( '' ) );
t( 'el modo por defecto es off y un valor raro cuenta como off', ( function () {
	cn_test_reset();
	$a = 'off' === CN_Autovincular::modo();
	update_option( CN_Autovincular::OPT_MODO, 'cualquiera' );
	return $a && 'off' === CN_Autovincular::modo();
} )() );

seccion( 'candidatas(): las 4 condiciones, cada una por separado' );
$pago_ts = time() - 7200;
$item    = av_item( 1, 'Marcela', 'Palomo' );
$base    = av_socia( 5, 'Marcela Palomo' );
t( 'ficha trial sin suscripción con nombre igual: candidata por nombre', ( function () use ( $item, $base, $pago_ts ) {
	$c = CN_Autovincular::candidatas( $item, '', array( $base ), $pago_ts );
	return 1 === count( $c ) && 5 === (int) $c[0]['socia']->id && 'nombre' === $c[0]['por'];
} )() );
t( 'ficha YA SUSCRIPTA (preapproval cargado): jamás candidata', array() === CN_Autovincular::candidatas( $item, '', array( av_socia( 5, 'Marcela Palomo', array( 'preapproval_id' => 'OTRA00000000000000000000000000aa' ) ) ), $pago_ts ) );
t( 'ficha creada DESPUÉS del pago: jamás candidata', array() === CN_Autovincular::candidatas( $item, '', array( av_socia( 5, 'Marcela Palomo', array( 'fecha_alta' => av_hace( 600 ), 'fecha_modificacion' => av_hace( 600 ) ) ) ), $pago_ts ) );
t( 'sin actividad en los 30 días previos al pago: no', array() === CN_Autovincular::candidatas( $item, '', array( av_socia( 5, 'Marcela Palomo', array( 'fecha_alta' => av_hace( 40 * 86400 ), 'fecha_modificacion' => av_hace( 40 * 86400 ) ) ) ), $pago_ts ) );
t( 'alta vieja pero modificación dentro de la ventana (y antes del pago): sí', 1 === count( CN_Autovincular::candidatas( $item, '', array( av_socia( 5, 'Marcela Palomo', array( 'fecha_alta' => av_hace( 40 * 86400 ), 'fecha_modificacion' => av_hace( 5 * 86400 ) ) ) ), $pago_ts ) ) );
t( 'modificación POSTERIOR al pago no cuenta como actividad (alta vieja): no', array() === CN_Autovincular::candidatas( $item, '', array( av_socia( 5, 'Marcela Palomo', array( 'fecha_alta' => av_hace( 40 * 86400 ), 'fecha_modificacion' => av_hace( 60 ) ) ) ), $pago_ts ) );
t( 'nombre de UNA palabra en MP: no (aunque la ficha tenga ese nombre)', array() === CN_Autovincular::candidatas( av_item( 1, 'Marcela', '' ), '', array( av_socia( 5, 'Marcela' ) ), $pago_ts ) );
t( 'email exacto es coincidencia fuerte: candidata aunque el nombre no dé (una palabra)', ( function () use ( $pago_ts ) {
	$c = CN_Autovincular::candidatas( av_item( 1, 'Marce', '' ), 'marce@gmail.com', array( av_socia( 5, 'Marcela Palomo', array( 'email' => 'Marce@Gmail.com' ) ) ), $pago_ts );
	return 1 === count( $c ) && 'email' === $c[0]['por'];
} )() );
t( 'nombre Y email: candidata única con motivo doble', 'nombre y email' === CN_Autovincular::candidatas( av_item( 1, 'Marcela', 'Palomo' ), 'marce@gmail.com', array( av_socia( 5, 'Marcela Palomo', array( 'email' => 'marce@gmail.com' ) ) ), $pago_ts )[0]['por'] );
t( 'homónimas (dos "Ana López" elegibles): DOS candidatas, nunca una', 2 === count( CN_Autovincular::candidatas( av_item( 1, 'Ana', 'López' ), '', array( av_socia( 5, 'Ana Lopez' ), av_socia( 6, 'ana lópez' ) ), $pago_ts ) ) );

seccion( 'modo on: candidata única se vincula por el MISMO camino manual (sin mail, sin Meta)' );
$w = av_preparar( array( av_item( 1, 'Marcela', 'Palomo' ) ), array( av_socia( 5, 'Marcela Palomo' ) ) );
$r = CN_Autovincular::procesar();
$u = av_updates( $w );
t( 'vincula: 1 evaluada, 1 vinculada', 1 === $r['evaluadas'] && 1 === $r['vinculadas'], json_encode( $r ) );
t( 'UN update en miembros: estado activo, preapproval, plan basico, fecha_fin_trial = null', 1 === count( $u ) && 'activo' === $u[0][2]['estado'] && av_id( 1 ) === $u[0][2]['preapproval_id'] && 'basico' === $u[0][2]['plan'] && array_key_exists( 'fecha_fin_trial', $u[0][2] ) && null === $u[0][2]['fecha_fin_trial'] );
t( 'CERO inserts en miembros (no duplica ficha) y cero deletes', ! array_filter( $w->escrituras( 'cn_miembros' ), function ( $e ) {
	return 'update' !== $e[0];
} ) );
t( 'reusa el camino manual: queda el rastro sub_vinculada_admin de vincular_suscripcion', in_array( 'sub_vinculada_admin', $w->log_mp_tipos(), true ) );
t( 'rastro autovincular_vinculada con el ESTADO PREVIO para revertir', ( function () use ( $w ) {
	$p = av_payload( $w, 'autovincular_vinculada' );
	return false !== strpos( $p, '"ficha_id":5' ) && false !== strpos( $p, '"previo"' ) && false !== strpos( $p, '"estado":"activo"' ) && false !== strpos( $p, '"fecha_fin_trial"' ) && false !== strpos( $p, '"match":"nombre"' );
} )() );
t( 'sin mail, sin evento Meta, solo GET a MP', ! $GLOBALS['cn_mails'] && ! $GLOBALS['cn_capi'] && array( 'GET' ) === array_values( array_unique( array_column( array_filter( $GLOBALS['cn_http_log'], function ( $c ) {
	return false !== strpos( $c['url'], 'mercadopago' );
} ), 'metodo' ) ) ) );
t( 'el log no lleva el nombre completo ni el celular', ( function () use ( $w ) {
	$p = av_payload( $w, 'autovincular_vinculada' );
	return false === stripos( $p, 'Marcela Palomo' ) && false === strpos( $p, '1155556605' ) && false !== strpos( $p, 'M*** P***' );
} )() );

seccion( 'modo solo_alertar: loguea "vincularía", NO escribe' );
$w = av_preparar( array( av_item( 1, 'Marcela', 'Palomo' ) ), array( av_socia( 5, 'Marcela Palomo' ) ), 'solo_alertar' );
$r = CN_Autovincular::procesar();
t( 'simulada: 1, vinculadas: 0, cero escrituras en miembros', 1 === $r['simuladas'] && 0 === $r['vinculadas'] && ! $w->escrituras( 'cn_miembros' ) );
t( 'rastro autovincular_simulada con la ficha y el estado previo', false !== strpos( av_payload( $w, 'autovincular_simulada' ), '"ficha_id":5' ) && false !== strpos( av_payload( $w, 'autovincular_simulada' ), '"previo"' ) );
t( 'no consulta el detalle del preapproval (no llega a vincular)', ! cn_http_calls( '#/preapproval/' . av_id( 1 ) . '$#' ) );

seccion( 'modo off: no hace nada de nada' );
$w = av_preparar( array( av_item( 1, 'Marcela', 'Palomo' ) ), array( av_socia( 5, 'Marcela Palomo' ) ), 'off' );
t( 'correr() con off: ni una consulta, ni log, ni options nuevas', null === CN_Autovincular::correr() && ! $GLOBALS['cn_http_log'] && ! $w->log );
$r = CN_Autovincular::procesar();
t( 'procesar() directo con off tampoco actúa (defensa en profundidad)', 'off' === $r['motivo'] && ! $GLOBALS['cn_http_log'] && ! $w->escrituras( 'cn_miembros' ) );

seccion( '0 y 2+ candidatas: no vincula y alerta enmascarado' );
$w = av_preparar( array( av_item( 1, 'Beatriz', 'Quintero' ) ), array( av_socia( 5, 'Marcela Palomo' ) ) );
$r = CN_Autovincular::procesar();
t( '0 candidatas: nada escrito, alerta autovincular_sin_candidata', 1 === $r['sin_candidata'] && ! $w->escrituras( 'cn_miembros' ) && in_array( 'autovincular_sin_candidata', $w->log_mp_tipos(), true ) );
$w = av_preparar( array( av_item( 1, 'Ana', 'López' ) ), array( av_socia( 5, 'Ana Lopez' ), av_socia( 6, 'ana lópez' ) ) );
$r = CN_Autovincular::procesar();
t( 'homónimas: ambigua, nada escrito', 1 === $r['ambiguas'] && 0 === $r['vinculadas'] && ! $w->escrituras( 'cn_miembros' ) );
t( 'la alerta lista las candidatas ENMASCARADAS (ids 5 y 6, A*** L***, ****hint), sin nombre ni celular completos', ( function () use ( $w ) {
	$p = av_payload( $w, 'autovincular_ambigua' );
	return false !== strpos( $p, '"ficha_id":5' ) && false !== strpos( $p, '"ficha_id":6' ) && false !== strpos( $p, 'A*** L***' )
		&& false !== strpos( $p, '****6605' ) && false === stripos( $p, 'Ana Lopez' ) && false === strpos( $p, '11555566' );
} )() );
$n_avisos = substr_count( av_payload( $w, 'aviso_error' ), 'autovincular_ambigua' );
CN_Autovincular::procesar();
t( 'repetir la corrida el mismo día: NO repite la alerta (1 por día por caso)', $n_avisos >= 1 && substr_count( av_payload( $w, 'aviso_error' ), 'autovincular_ambigua' ) === $n_avisos );

seccion( 'lo que ni se evalúa: muy nueva, monto raro, ya vinculada, no autorizada' );
$ya = av_socia( 9, 'Otra Socia', array( 'preapproval_id' => av_id( 3 ) ) );
$w  = av_preparar( array(
	av_item( 2, 'Marcela', 'Palomo', 600 ),                                        // pagada hace 10 min: que la resuelva el alta normal
	av_item( 3, 'Otra', 'Socia' ),                                                 // ya vinculada a la ficha 9
	av_item( 4, 'Marcela', 'Palomo', 7200, 7000 ),                                 // monto desconocido: humano
	av_item( 5, 'Marcela', 'Palomo', 7200, 15500, array( 'status' => 'paused' ) ), // no autorizada
), array( av_socia( 5, 'Marcela Palomo' ), $ya ) );
$r = CN_Autovincular::procesar();
t( 'ninguna se evalúa ni se escribe nada', 0 === $r['evaluadas'] && ! $w->escrituras( 'cn_miembros' ) && ! in_array( 'sub_vinculada_admin', $w->log_mp_tipos(), true ) );

seccion( 'tope: máximo 3 por corrida; la 4ª queda alertada sin vincular' );
$items  = array();
$socias = array();
foreach ( array( 1 => 'Alba Uno', 2 => 'Bela Dos', 3 => 'Cora Tres', 4 => 'Dina Cuatro' ) as $n => $nombre ) {
	$partes   = explode( ' ', $nombre );
	$items[]  = av_item( $n, $partes[0], $partes[1], 7200 + $n ); // la más vieja primero tras el orden interno
	$socias[] = av_socia( 10 + $n, $nombre );
}
$w = av_preparar( $items, $socias );
$r = CN_Autovincular::procesar();
t( '4 con candidata única: 3 vinculadas y 1 al tope', 3 === $r['vinculadas'] && 1 === $r['tope'], json_encode( $r ) );
t( 'exactamente 3 updates en miembros', 3 === count( av_updates( $w ) ) );
t( 'se vincula de la más vieja a la más nueva: la más nueva (Alba, ficha 11) queda al tope, alertada con su candidata', in_array( 'autovincular_tope', $w->log_mp_tipos(), true ) && false !== strpos( av_payload( $w, 'autovincular_tope' ), '"ficha_id":11' ) && false === strpos( av_payload( $w, 'autovincular_tope' ), '"ficha_id":14' ) );

seccion( 'carrera: el camino manual revalida con FOR UPDATE y rechaza (conflicto) sin escribir' );
$w = av_preparar( array( av_item( 1, 'Marcela', 'Palomo' ) ), array( av_socia( 5, 'Marcela Palomo' ) ) );
$w->on( '/WHERE id = 5 FOR UPDATE/', array( av_socia( 5, 'Marcela Palomo', array( 'preapproval_id' => 'GANADA00000000000000000000000000' ) ) ) ); // otro proceso la vinculó en el medio
$r = CN_Autovincular::procesar();
t( 'conflicto: cero updates, rastro autovincular_rechazada (y sub_conflicto del camino manual)', 0 === $r['vinculadas'] && ! av_updates( $w ) && in_array( 'autovincular_rechazada', $w->log_mp_tipos(), true ) && in_array( 'sub_conflicto', $w->log_mp_tipos(), true ) );

seccion( 'correr(): lock, espaciado y enganche' );
$w = av_preparar( array(), array() );
set_transient( CN_Autovincular::LOCK, 1 );
t( 'con el lock puesto no consulta nada', null === CN_Autovincular::correr() && ! $GLOBALS['cn_http_log'] );
delete_transient( CN_Autovincular::LOCK );
$r1 = CN_Autovincular::correr();
$r2 = CN_Autovincular::correr();
t( 'corre una vez y la siguiente (dentro del espaciado de 25 min) no: convive con el sync de 5 min del PR 36', is_array( $r1 ) && null === $r2 && false === get_transient( CN_Autovincular::LOCK ) );
cn_test_reset();
$av_hooks_antes      = $GLOBALS['cn_hooks'] ?? array(); // los hooks del bootstrap se restauran al final (otros tests los verifican).
$GLOBALS['cn_hooks'] = array();
CN_Autovincular::registrar();
t( 'registrar() engancha correr al hook del sync (cn_cron_sync_mp)', array( array( 'CN_Autovincular', 'correr' ) ) === ( $GLOBALS['cn_hooks']['cn_cron_sync_mp'] ?? array() ) );
$GLOBALS['cn_hooks'] = $av_hooks_antes;
$main = file_get_contents( dirname( __DIR__ ) . '/club-natureza-miembros.php' );
t( 'el plugin carga la clase con guard (deploy archivo por archivo) y la registra', false !== strpos( $main, "class-cn-autovincular.php" ) && false !== strpos( $main, 'CN_Autovincular::registrar();' ) );

seccion( 'buscador de fichas: consulta por nombre o por últimos 4, tope 21, sin datos de más' );
cn_test_reset();
global $wpdb;
$wpdb->on( '/celular_hint = .1234./', array( av_socia( 5, 'Marcela Palomo', array( 'celular_hint' => '1234' ) ) ) );
$wpdb->on( '/nombre_apellido LIKE/', array( av_socia( 6, 'Marcela Paez' ), av_socia( 7, 'Marcela Palomo', array( 'preapproval_id' => av_id( 9 ) ) ) ) );
$f = CN_Admin::buscar_fichas( '1234' );
t( '4 dígitos exactos: busca por celular_hint y trae la ficha', 1 === count( $f ) && 5 === (int) $f[0]->id && ( function () use ( $wpdb ) {
	foreach ( $wpdb->consultas as $q ) {
		if ( false !== strpos( $q, "celular_hint = '1234'" ) ) {
			return false !== strpos( $q, 'LIMIT 21' ) && false === strpos( $q, 'celular_texto_plano' ) && false === strpos( $q, 'email' );
		}
	}
	return false;
} )() );
$f = CN_Admin::buscar_fichas( ' Marcela ' );
t( 'texto: busca por nombre con LIKE y tope 21', 2 === count( $f ) && ( function () use ( $wpdb ) {
	foreach ( $wpdb->consultas as $q ) {
		if ( false !== strpos( $q, 'nombre_apellido LIKE' ) ) {
			return false !== strpos( $q, '%Marcela%' ) && false !== strpos( $q, 'LIMIT 21' );
		}
	}
	return false;
} )() );
t( 'término corto (2 letras) o vacío: ni consulta', ( function () use ( $wpdb ) {
	$antes = count( $wpdb->consultas );
	$a     = CN_Admin::buscar_fichas( 'Ma' );
	$b     = CN_Admin::buscar_fichas( '' );
	return array() === $a && array() === $b && count( $wpdb->consultas ) === $antes;
} )() );
t( '5 dígitos no es hint: va por nombre (LIKE), nunca por celular', ( function () use ( $wpdb ) {
	CN_Admin::buscar_fichas( '12345' );
	$like = false;
	foreach ( $wpdb->consultas as $q ) {
		if ( false !== strpos( $q, "celular_hint = '12345'" ) ) {
			return false;
		}
		if ( false !== strpos( $q, "LIKE '%12345%'" ) ) {
			$like = true;
		}
	}
	return $like;
} )() );

seccion( 'buscador: nonce, capability y render enmascarado' );
cn_test_reset();
$_POST = array( 'cn_bf_buscar' => '1', 'cn_bf_termino' => 'Marcela', 'cn_bf_pre' => av_id( 1 ) );
$GLOBALS['cn_caps'] = array();
$r = cn_llamar( 'CN_Admin', 'procesar_buscar_ficha' );
t( 'sin manage_options: error y ni una consulta', '' !== $r['error'] && array() === $r['filas'] && ! $GLOBALS['wpdb']->consultas );
unset( $GLOBALS['cn_caps'] );
$GLOBALS['cn_nonce_ok'] = false;
$r = cn_llamar( 'CN_Admin', 'procesar_buscar_ficha' );
t( 'nonce inválido: error y ni una consulta', '' !== $r['error'] && array() === $r['filas'] && ! $GLOBALS['wpdb']->consultas );
$GLOBALS['cn_nonce_ok'] = true;
$GLOBALS['wpdb']->on( '/nombre_apellido LIKE/', array( av_socia( 6, 'Marcela Paez' ), av_socia( 7, 'Marcela Palomo', array( 'preapproval_id' => av_id( 9 ) ) ) ) );
$r = cn_llamar( 'CN_Admin', 'procesar_buscar_ficha' );
t( 'con nonce y permisos: trae filas y conserva el preapproval válido', 2 === count( $r['filas'] ) && av_id( 1 ) === $r['pre'] && 'Marcela' === $r['termino'] );
$_POST['cn_bf_pre'] = 'no-valido!';
$r2 = cn_llamar( 'CN_Admin', 'procesar_buscar_ficha' );
t( 'un preapproval con formato inválido se descarta', '' === $r2['pre'] );
ob_start();
cn_llamar( 'CN_Admin', 'bloque_buscar_ficha', $r );
$html = ob_get_clean();
t( 'render: nombre y últimos 4 enmascarados, nunca el celular completo ni un email', false !== strpos( $html, 'Marcela Paez' ) && false !== strpos( $html, '****6606' ) && false === strpos( $html, '11555566' ) && false === strpos( $html, '@' ) );
t( 'render: "Elegir" precarga el bloque de vincular (cn_vincular_socia + cn_vincular_id) y la ya vinculada no se puede elegir', false !== strpos( $html, 'cn_vincular_socia=6' ) && false !== strpos( $html, 'cn_vincular_id=' . av_id( 1 ) ) && false !== strpos( $html, '#cn-vincular-mp' ) && false === strpos( $html, 'cn_vincular_socia=7' ) && false !== strpos( $html, 'ya tiene suscripción vinculada' ) );
t( 'render: el término NO viaja en ninguna URL (va por POST)', false === strpos( $html, 'cn_bf_termino=' ) );
$_POST = array();

seccion( 'cero menciones prohibidas en lo nuevo' );
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-autovincular.php' );
t( 'class-cn-autovincular.php: sin whatsapp/wa.me, sin wp_remote_* propios, sin INSERT/UPDATE/DELETE directos en miembros', false === stripos( $src, 'whatsapp' ) && false === stripos( $src, 'wa.me' ) && false === strpos( $src, 'wp_remote_' ) && false === strpos( $src, '->update(' ) && false === strpos( $src, '->insert(' ) && false === strpos( $src, '->delete(' ) );
t( 'la única vía de escritura es CN_Webhook::vincular_suscripcion (está la llamada y ningún SQL propio de escritura)', false !== strpos( $src, 'CN_Webhook::vincular_suscripcion( $id, (int) $c->id, false )' ) && false === stripos( $src, 'INSERT INTO' ) && false === stripos( $src, 'UPDATE wp_' ) && false === stripos( $src, 'DELETE FROM' ) );

// =====================================================================================================================
// REVISIÓN DE #38 — punto 1: fichas manuales / con plan NO son candidatas (el comentario ahora coincide con el código)
// =====================================================================================================================
seccion( 'punto 1: solo las fichas de TRIAL sin preapproval ni plan pueden ser candidatas' );
$pago_ts = time() - 7200;
$item    = av_item( 1, 'Marcela', 'Palomo' );
$cand_n  = function ( $extra ) use ( $item, $pago_ts ) {
	return count( CN_Autovincular::candidatas( $item, '', array( av_socia( 5, 'Marcela Palomo', $extra ) ), $pago_ts ) );
};
t( 'trial vigente: candidata', 1 === $cand_n( array() ) );
t( 'trial vencido y cancelada (pagó por link directo después): candidata', 1 === $cand_n( array( 'estado' => 'cancelada', 'fecha_fin_trial' => av_hace( 3 * 86400 ) ) ) );
t( 'trial vencido y pausada: candidata', 1 === $cand_n( array( 'estado' => 'pausado', 'fecha_fin_trial' => av_hace( 3 * 86400 ) ) ) );
t( 'MANUAL pura (activa, sin trial, sin plan): NO es candidata', 0 === $cand_n( array( 'fecha_fin_trial' => null ) ) );
t( 'MANUAL con fecha_fin_trial vacía como texto: NO es candidata', 0 === $cand_n( array( 'fecha_fin_trial' => '' ) ) );
t( 'MANUAL "suscripta" (con plan y acceso_hasta pero SIN preapproval): NO es candidata', 0 === $cand_n( array( 'fecha_fin_trial' => null, 'plan' => 'basico', 'acceso_hasta' => gmdate( 'Y-m-d H:i:s', time() + 20 * 86400 ) ) ) );
t( 'trial que conserva el plan de una suscripción anterior (reasignada/cancelada): NO es candidata', 0 === $cand_n( array( 'plan' => 'full' ) ) );
t( 'con preapproval cargado sigue sin ser candidata', 0 === $cand_n( array( 'preapproval_id' => 'OTRA00000000000000000000000000aa' ) ) );
// De punta a punta: una manual con el nombre exacto NO se vincula; se alerta y no se escribe nada.
$w = av_preparar( array( av_item( 1, 'Marcela', 'Palomo' ) ), array( av_socia( 5, 'Marcela Palomo', array( 'fecha_fin_trial' => null, 'plan' => 'basico' ) ) ) );
$r = CN_Autovincular::procesar();
t( 'manual con el nombre exacto: sin candidata, cero escrituras, alerta (decide una persona)', 1 === $r['sin_candidata'] && 0 === $r['vinculadas'] && ! $w->escrituras( 'cn_miembros' ) && in_array( 'autovincular_sin_candidata', $w->log_mp_tipos(), true ) );

seccion( 'punto 1: homónimas de cualquier tipo frenan la vinculación por nombre' );
$w = av_preparar( array( av_item( 1, 'Ana', 'López' ) ), array( av_socia( 5, 'Ana Lopez' ), av_socia( 6, 'Ana López', array( 'fecha_fin_trial' => null ) ) ) );
$r = CN_Autovincular::procesar();
t( 'trial "Ana López" + MANUAL "Ana López": ambigua, no vincula (podría ser la manual)', 1 === $r['ambiguas'] && 0 === $r['vinculadas'] && ! $w->escrituras( 'cn_miembros' ) );
t( 'la alerta lista las dos fichas enmascaradas, la homónima marcada como tal', false !== strpos( av_payload( $w, 'autovincular_ambigua' ), '"ficha_id":5' ) && false !== strpos( av_payload( $w, 'autovincular_ambigua' ), '"ficha_id":6' ) && false !== strpos( av_payload( $w, 'autovincular_ambigua' ), 'homonima' ) && false === stripos( av_payload( $w, 'autovincular_ambigua' ), 'Ana Lopez' ) );
$w = av_preparar( array( av_item( 1, 'Ana', 'López' ) ), array( av_socia( 5, 'Ana Lopez' ), av_socia( 6, 'Ana López', array( 'preapproval_id' => av_id( 77 ), 'plan' => 'basico' ) ) ) );
$r = CN_Autovincular::procesar();
t( 'trial + homónima YA SUSCRIPTA: tampoco vincula (podría ser un cobro de la ya suscripta)', 1 === $r['ambiguas'] && ! $w->escrituras( 'cn_miembros' ) );
$w = av_preparar( array( av_item( 1, 'Ana', 'López', 7200, 15500, array( 'payer_email' => 'ana@gmail.com' ) ) ), array( av_socia( 5, 'Ana Lopez', array( 'email' => 'ana@gmail.com' ) ), av_socia( 6, 'Ana López', array( 'fecha_fin_trial' => null ) ) ) );
$r = CN_Autovincular::procesar();
t( 'con email EXACTO la homónima no frena (el email identifica): vincula a la del email', 1 === $r['vinculadas'] && 5 === (int) av_updates( $w )[0][3]['id'] );
t( 'un nombre de una sola palabra jamás genera homónimas (ni frena ni vincula)', array() === CN_Autovincular::homonimas( av_item( 1, 'Ana', '' ), array( av_socia( 5, 'Ana' ), av_socia( 6, 'Ana López' ) ), 99 ) );

// =====================================================================================================================
// punto 2: zona horaria de fecha_alta / fecha_modificacion
// =====================================================================================================================
seccion( 'punto 2: el plugin guarda fecha_alta y fecha_modificacion en UTC y se leen como UTC' );
$raiz_inc = dirname( __DIR__ ) . '/includes/';
$locales  = array();
foreach ( glob( $raiz_inc . '*.php' ) as $archivo_php ) {
	$codigo = file_get_contents( $archivo_php );
	if ( preg_match( "/current_time\\(\\s*'mysql'\\s*\\)|current_time\\(\\s*\"mysql\"\\s*\\)|current_time\\(\\s*'mysql'\\s*,\\s*(false|0)\\s*\\)|\\bNOW\\(\\)|(?<![A-Za-z_])date\\(\\s*'Y-m-d H:i:s'/", $codigo ) ) {
		$locales[] = basename( $archivo_php );
	}
}
t( 'ningún archivo del plugin escribe fechas con hora LOCAL (current_time sin gmt, NOW(), date()): contrato UTC fijado', array() === $locales, implode( ',', $locales ) );
$fuentes_alta = file_get_contents( $raiz_inc . 'class-cn-webhook.php' ) . file_get_contents( $raiz_inc . 'class-cn-admin.php' ) . file_get_contents( $raiz_inc . 'class-cn-vinculo-manual.php' ) . file_get_contents( $raiz_inc . 'class-cn-colaboradora.php' );
t( 'las altas de fichas (webhook, trial, admin, vinculación manual, colaboradora) toman la hora de current_time(mysql, true) o de un $ahora definido así', 6 <= preg_match_all( "/'fecha_alta'\\s*=>\\s*(current_time\\(\\s*'mysql'\\s*,\\s*true\\s*\\)|\\\$ahora)/", $fuentes_alta, $m_alta ) );
$tz_antes         = date_default_timezone_get();
$resultado_por_tz = array();
foreach ( array( 'UTC', 'America/Argentina/Buenos_Aires', 'Pacific/Auckland' ) as $tz ) {
	date_default_timezone_set( $tz );
	$pago_tz = 1793887000; // instante fijo
	$mk      = function ( $delta ) use ( $pago_tz ) {
		$f = gmdate( 'Y-m-d H:i:s', $pago_tz + $delta );
		return av_socia( 5, 'Marcela Palomo', array( 'fecha_alta' => $f, 'fecha_modificacion' => $f ) );
	};
	$it = av_item( 1, 'Marcela', 'Palomo' );
	$resultado_por_tz[ $tz ] = array(
		count( CN_Autovincular::candidatas( $it, '', array( $mk( -60 ) ), $pago_tz ) ),
		count( CN_Autovincular::candidatas( $it, '', array( $mk( 60 ) ), $pago_tz ) ),
		count( CN_Autovincular::candidatas( $it, '', array( $mk( -30 * 86400 - 60 ) ), $pago_tz ) ),
		count( CN_Autovincular::candidatas( $it, '', array( $mk( -30 * 86400 + 60 ) ), $pago_tz ) ),
	);
}
date_default_timezone_set( $tz_antes );
t( 'el resultado NO depende de la zona horaria de PHP (UTC, Buenos Aires y Auckland dan lo mismo)', $resultado_por_tz['UTC'] === $resultado_por_tz['America/Argentina/Buenos_Aires'] && $resultado_por_tz['UTC'] === $resultado_por_tz['Pacific/Auckland'] );
t( 'bordes exactos: 1 min antes del pago = candidata; 1 min después = no; 30 días + 1 min atrás = no; 30 días - 1 min atrás = sí', array( 1, 0, 0, 1 ) === $resultado_por_tz['UTC'], json_encode( $resultado_por_tz ) );
t( 'el date_created de MP (con offset -04:00) se lee como el instante real: 09:08:32-04:00 = 13:08:32 UTC = 10:08 en Argentina', gmmktime( 13, 8, 32, 11, 5, 2026 ) === strtotime( '2026-11-05T09:08:32.000-04:00' ) );

// =====================================================================================================================
// punto 3: mail de acceso a la socia vinculada, SIN evento Meta
// =====================================================================================================================
seccion( 'punto 3: mail de acceso al email REGISTRADO de la ficha, sin evento Meta, una sola vez' );
$mail_celular = '1155556605';
$fila_mail    = function ( $celular ) {
	return array( cn_fila( array( 'nombre_apellido' => 'Marcela Palomo', 'celular_texto_plano' => $celular ) ) );
};
$w = av_preparar( array( av_item( 1, 'Marcela', 'Palomo', 7200, 15500, array( 'payer_email' => 'otro.mail@hotmail.com' ) ) ), array( av_socia( 5, 'Marcela Palomo', array( 'email' => 'marcela@gmail.com' ) ) ) );
$w->on( '/SELECT nombre_apellido, celular_texto_plano FROM wp_cn_miembros WHERE id = 5/', $fila_mail( $mail_celular ) );
$r    = CN_Autovincular::procesar();
$mail = $GLOBALS['cn_mails'][0] ?? array();
t( 'vincula y manda UN mail', 1 === $r['vinculadas'] && 1 === $r['mails'] && 1 === count( $GLOBALS['cn_mails'] ) );
t( 'va al email REGISTRADO en la ficha, NO al del pagador de MP (cuando difieren)', 'marcela@gmail.com' === ( $mail['to'] ?? '' ) && false === stripos( json_encode( $GLOBALS['cn_mails'] ), 'hotmail' ) );
t( 'es el mail de bienvenida de la suscripción (datos de acceso: nombre y celular de la ficha, plan, link al Club)', false !== strpos( (string) ( $mail['asunto'] ?? '' ), 'datos de acceso' ) && false !== strpos( (string) ( $mail['cuerpo'] ?? '' ), 'Marcela Palomo' ) && false !== strpos( (string) ( $mail['cuerpo'] ?? '' ), $mail_celular ) && false !== strpos( (string) ( $mail['cuerpo'] ?? '' ), '/club-natureza-miembros/' ) );
t( 'CERO eventos Meta (no hay Subscribe duplicado)', array() === $GLOBALS['cn_capi'] );
t( 'el camino manual sigue con notificar=false (el rastro sub_vinculada_admin lo dice)', false !== strpos( av_payload( $w, 'sub_vinculada_admin' ), '"notificar":false' ) );
t( 'rastro autovincular_mail: destino ENMASCARADO, pagador conocido, no coincide, Meta no enviado; sin celular ni email completos', ( function () use ( $w, $mail_celular ) {
	$p = av_payload( $w, 'autovincular_mail' );
	return false !== strpos( $p, 'ma***@gmail.com' ) && false !== strpos( $p, '"pagador_conocido":true' ) && false !== strpos( $p, '"coincide_pagador":false' ) && false !== strpos( $p, '"meta":"no_enviado"' )
		&& false === strpos( $p, 'marcela@gmail.com' ) && false === strpos( $p, $mail_celular ) && false === strpos( $p, 'hotmail' );
} )() );
$w2 = av_preparar( array( av_item( 1, 'Marcela', 'Palomo' ) ), array( av_socia( 5, 'Marcela Palomo', array( 'email' => 'marcela@gmail.com', 'preapproval_id' => av_id( 1 ), 'plan' => 'basico', 'fecha_fin_trial' => null ) ) ) );
$r2 = CN_Autovincular::procesar();
t( 'la corrida siguiente (la ficha ya figura vinculada) no evalúa, no vincula y no manda: UN solo mail por vinculación', 0 === $r2['evaluadas'] && 0 === $r2['vinculadas'] && ! $GLOBALS['cn_mails'] && ! $w2->escrituras( 'cn_miembros' ) );

$w = av_preparar( array( av_item( 1, 'Marcela', 'Palomo', 7200, 15500, array( 'payer_email' => 'pagadora@hotmail.com' ) ) ), array( av_socia( 5, 'Marcela Palomo', array( 'email' => null ) ) ) );
$w->on( '/SELECT nombre_apellido, celular_texto_plano FROM wp_cn_miembros WHERE id = 5/', $fila_mail( $mail_celular ) );
$r = CN_Autovincular::procesar();
t( 'ficha SIN email aunque el email del pagador sea conocido: vincula pero NO manda (el mail lleva el celular completo; no se lo damos a un email sin verificar)', 1 === $r['vinculadas'] && 0 === $r['mails'] && ! $GLOBALS['cn_mails'] && array() === $GLOBALS['cn_capi'] );
t( 'queda el rastro autovincular_mail_omitido (ficha_sin_email), con pagador_conocido true y sin ningún email', ( function () use ( $w ) {
	$p = av_payload( $w, 'autovincular_mail_omitido' );
	return false !== strpos( $p, '"motivo":"ficha_sin_email"' ) && false !== strpos( $p, '"pagador_conocido":true' ) && false === strpos( $p, '@' );
} )() );

$w = av_preparar( array( av_item( 1, 'Marcela', 'Palomo' ) ), array( av_socia( 5, 'Marcela Palomo', array( 'email' => 'marcela@gmail.com' ) ) ) );
$w->on( '/SELECT nombre_apellido, celular_texto_plano FROM wp_cn_miembros WHERE id = 5/', $fila_mail( '' ) );
$r = CN_Autovincular::procesar();
t( 'ficha con email pero SIN celular guardado: vincula, no manda (saldría sin la clave) y lo deja anotado', 1 === $r['vinculadas'] && ! $GLOBALS['cn_mails'] && false !== strpos( av_payload( $w, 'autovincular_mail_omitido' ), '"motivo":"ficha_sin_celular"' ) );

$w = av_preparar( array( av_item( 1, 'Marcela', 'Palomo' ) ), array( av_socia( 5, 'Marcela Palomo', array( 'email' => 'marcela@gmail.com' ) ) ), 'solo_alertar' );
CN_Autovincular::procesar();
t( 'solo_alertar: ni mail ni Meta ni escritura', ! $GLOBALS['cn_mails'] && array() === $GLOBALS['cn_capi'] && ! $w->escrituras( 'cn_miembros' ) );

$w = av_preparar( array( av_item( 1, 'Marcela', 'Palomo' ) ), array( av_socia( 5, 'Marcela Palomo', array( 'email' => 'marcela@gmail.com' ) ) ) );
$w->on( '/WHERE id = 5 FOR UPDATE/', array( av_socia( 5, 'Marcela Palomo', array( 'email' => 'marcela@gmail.com', 'preapproval_id' => 'GANADA00000000000000000000000000' ) ) ) );
CN_Autovincular::procesar();
t( 'si el camino manual rechaza (conflicto/carrera): no se manda ningún mail', ! $GLOBALS['cn_mails'] && array() === $GLOBALS['cn_capi'] );

$w = av_preparar( array( av_item( 1, 'Marcela', 'Palomo' ) ), array( av_socia( 5, 'Marcela Palomo', array( 'email' => 'marcela@gmail.com' ) ) ) );
$w->on( '/SELECT nombre_apellido, celular_texto_plano FROM wp_cn_miembros WHERE id = 5/', $fila_mail( $mail_celular ) );
$GLOBALS['cn_mail_falla'] = true;
$r = CN_Autovincular::procesar();
t( 'si wp_mail falla, la vinculación ya hecha NO se toca (el mail es accesorio)', 1 === $r['vinculadas'] && 1 === count( av_updates( $w ) ) );
$GLOBALS['cn_mail_falla'] = false;

t( 'el wrapper público del webhook solo manda el mail: no llama a sub_post_alta (que es quien manda el Subscribe)', ( function () {
	$src    = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-webhook.php' );
	$ini    = strpos( $src, 'public static function enviar_mail_acceso_sub' );
	$fin    = strpos( $src, 'protected static function enviar_mail_bienvenida_suscripcion' );
	$cuerpo = ( false !== $ini && false !== $fin ) ? substr( $src, $ini, $fin - $ini ) : 'x';
	return false !== strpos( $cuerpo, 'self::enviar_mail_bienvenida_suscripcion(' ) && false === strpos( $cuerpo, 'sub_post_alta' ) && false === strpos( $cuerpo, 'CN_Meta_Capi' );
} )() );
t( 'class-cn-autovincular.php sigue sin SQL de escritura propio, sin sub_post_alta ni CN_Meta_Capi', ( function () {
	$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-autovincular.php' );
	return false === strpos( $src, '->update(' ) && false === strpos( $src, '->insert(' ) && false === strpos( $src, '->delete(' ) && false === strpos( $src, 'sub_post_alta(' ) && false === strpos( $src, 'CN_Meta_Capi::' );
} )() );
