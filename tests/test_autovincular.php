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
