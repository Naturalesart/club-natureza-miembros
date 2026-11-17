<?php
// Cartel de suscripción en la portada del club (CN_Cartel_Trial): a quién se le muestra, modos off/log/on, conteo por
// motivo, texto, links firmados (plan + origen por botón), caché, solo lectura y cero datos personales / WhatsApp.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) {
		return mt_rand( (int) $min, $max ? (int) $max : mt_getrandmax() );
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $p = '' ) {
		return 'https://naturalesart.com/wp-json/' . ltrim( $p, '/' );
	}
}
const CTT_SECRETO = 'cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12';
const CTT_CEL     = '1155550088';
const CTT_EMAIL   = 'socia.cartel@gmail.com';
const CTT_NOMBRE  = 'Socia Cartel';

/** Lo que devuelve CN_Auth::get_miembro_actual() para una trial a la que le quedan $horas. */
function ctt_miembro( $horas = 30, $extra = array() ) {
	return cn_fila( $extra + array(
		'id'              => 77,
		'nombre_apellido' => CTT_NOMBRE,
		'estado'          => 'activo',
		'fecha_fin_trial' => gmdate( 'Y-m-d H:i:s', time() + (int) round( $horas * 3600 ) ),
		'preapproval_id'  => null,
	) );
}
function ctt_ficha( $extra = array() ) {
	return cn_fila( $extra + array(
		'id'                  => 77,
		'nombre_apellido'     => CTT_NOMBRE,
		'estado'              => 'activo',
		'preapproval_id'      => null,
		'baja_en'             => null,
		'email'               => CTT_EMAIL,
		'celular_texto_plano' => '011 15 5555-0088',
		'celular_hash'        => CN_Helpers::hash_celular( CTT_CEL ),
	) );
}
/** Estado limpio: cartel en $modo, link firmado on + secreto, ficha 77 con email/celular, N videos abiertos. */
function ctt_preparar( $modo = 'on', $ficha = null, $vistos = 4, $suscribir = 'on', $secreto = CTT_SECRETO ) {
	cn_test_reset();
	global $wpdb;
	$_GET = $_POST = $_COOKIE = array();
	$_SERVER['REMOTE_ADDR']     = '190.1.2.4';
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (test)';
	CN_Cartel_Trial::$reloj     = null;
	CN_Cartel_Trial::$sin_cache = true;
	update_option( 'cn_mp_access_token', 'TOKEN-SUB' );
	update_option( CN_Cartel_Trial::OPT_MODO, $modo );
	update_option( CN_Suscribir::OPT_MODO, $suscribir );
	update_option( CN_Suscribir::OPT_SECRETO, $secreto );
	cn_http_on( 'POST', '#/preapproval$#', array( 'id' => 'pre_77', 'init_point' => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=pre_77' ) );
	$ficha = null === $ficha ? ctt_ficha() : $ficha;
	if ( false !== $ficha ) {
		$wpdb->on( '/FROM wp_cn_miembros WHERE id = 77\b/', array( $ficha ) );
	}
	$wpdb->on( '/SELECT COUNT\(\*\) FROM wp_cn_vistos v INNER JOIN wp_cn_contenidos c/', (string) (int) $vistos );
	return $wpdb;
}
function ctt_tipos( $w ) {
	return $w->log_mp_tipos();
}
function ctt_payloads( $w ) {
	$o = '';
	foreach ( $w->log as $l ) {
		if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) ) {
			$o .= (string) $l[2]['payload'] . "\n";
		}
	}
	return $o;
}
function ctt_sin_datos( $texto ) {
	return false === strpos( $texto, CTT_EMAIL ) && false === strpos( $texto, CTT_CEL ) && false === strpos( $texto, '5555-0088' )
		&& false === stripos( $texto, CTT_NOMBRE ) && false === stripos( $texto, 'whatsapp' ) && false === stripos( $texto, 'wa.me' );
}
function ctt_hrefs( $html ) {
	preg_match_all( '/href="([^"]+)"/', $html, $m );
	return array_map( 'html_entity_decode', $m[1] );
}
function ctt_token_de( $url ) {
	$q = array();
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
	return isset( $q['cn_suscribir'] ) ? (string) $q['cn_suscribir'] : '';
}
/** Ninguna escritura sobre miembros/vistos/sesiones ni UPDATE/DELETE por query(). */
function ctt_solo_lectura( $w ) {
	foreach ( $w->log as $l ) {
		if ( in_array( $l[0], array( 'update', 'delete' ), true ) ) {
			return false;
		}
		if ( 'insert' === $l[0] && false === strpos( $l[1], 'mp_log' ) && false === strpos( $l[1], 'suscripciones_pendientes' ) ) {
			return false;
		}
		if ( 'q' === $l[0] && preg_match( '/\b(UPDATE|DELETE|INSERT|ALTER|DROP)\b/i', $l[1] ) ) {
			return false;
		}
	}
	return true;
}

seccion( 'flag cn_cartel_trial_modo: off por defecto' );
cn_test_reset();
t( 'sin opción: off', 'off' === CN_Cartel_Trial::modo() );
update_option( CN_Cartel_Trial::OPT_MODO, 'cualquiera' );
t( 'valor desconocido: off', 'off' === CN_Cartel_Trial::modo() );
update_option( CN_Cartel_Trial::OPT_MODO, 'log' );
t( 'log', 'log' === CN_Cartel_Trial::modo() );
update_option( CN_Cartel_Trial::OPT_MODO, 'on' );
t( 'on', 'on' === CN_Cartel_Trial::modo() );

seccion( 'off: no hace nada (ni lee la base)' );
$w = ctt_preparar( 'off' );
$h = CN_Cartel_Trial::html( ctt_miembro( 30 ) );
t( 'off: html vacío', '' === $h );
t( 'off: cero consultas a la base', array() === $w->consultas );
t( 'off: cero escrituras y cero conteo', array() === $w->log && array() === CN_Cartel_Trial::conteo() );

seccion( 'a quién: solo trial activo con 48 h o menos' );
cn_test_reset();
foreach ( array(
	'trial con 47 h: sí'                 => array( ctt_miembro( 47 ), true ),
	'trial con 1 h: sí'                  => array( ctt_miembro( 1 ), true ),
	'trial con preapproval_id vacío: sí' => array( ctt_miembro( 10, array( 'preapproval_id' => '' ) ), true ),
	'trial con 49 h: no'                 => array( ctt_miembro( 49 ), false ),
	'trial vencido hace 1 h: no'         => array( ctt_miembro( -1 ), false ),
	'suscripta (preapproval_id): no'     => array( ctt_miembro( 10, array( 'preapproval_id' => 'pre_1' ) ), false ),
	"preapproval_id '0' cuenta como valor: no" => array( ctt_miembro( 10, array( 'preapproval_id' => '0' ) ), false ),
	'manual (sin fecha_fin_trial): no'   => array( ctt_miembro( 10, array( 'fecha_fin_trial' => null ) ), false ),
	'manual (fecha 0000): no'            => array( ctt_miembro( 10, array( 'fecha_fin_trial' => '0000-00-00 00:00:00' ) ), false ),
	'pausada: no'                        => array( ctt_miembro( 10, array( 'estado' => 'pausado' ) ), false ),
	'cancelada: no'                      => array( ctt_miembro( 10, array( 'estado' => 'cancelada' ) ), false ),
	'sin miembro: no'                    => array( null, false ),
) as $nombre => $caso ) {
	t( "elegible: {$nombre}", $caso[1] === ( null !== CN_Cartel_Trial::fin_trial_si_elegible( $caso[0] ) ) );
}
$w = ctt_preparar( 'on' );
foreach ( array( ctt_miembro( 49 ), ctt_miembro( -1 ), ctt_miembro( 10, array( 'preapproval_id' => 'pre_1' ) ), ctt_miembro( 10, array( 'fecha_fin_trial' => null ) ) ) as $m ) {
	t( 'no elegible: html vacío', '' === CN_Cartel_Trial::html( $m ) );
}
t( 'no elegibles: no se cuentan ni se loguean', array() === CN_Cartel_Trial::conteo() && array() === ctt_tipos( $w ) );

seccion( 'texto: real, sin urgencia falsa' );
$ts = gmmktime( 15, 0, 0, 11, 19, 2026 ); // 19/11/2026 15:00 UTC = 12:00 en Argentina (jueves)
t( 'día y fecha en hora de Argentina: "jueves 19/11"', 'jueves 19/11' === CN_Cartel_Trial::dia_fin( $ts ), CN_Cartel_Trial::dia_fin( $ts ) );
t( '02:00 UTC del 20/11 es todavía el jueves 19/11 en Argentina', 'jueves 19/11' === CN_Cartel_Trial::dia_fin( gmmktime( 2, 0, 0, 11, 20, 2026 ) ) );
t( 'N=5: "Entraste a 5 clases del club. Tu prueba termina el jueves 19/11."', 'Entraste a 5 clases del club. Tu prueba termina el jueves 19/11.' === CN_Cartel_Trial::texto( 5, $ts ) );
t( 'N=1: singular', 'Entraste a 1 clase del club. Tu prueba termina el jueves 19/11.' === CN_Cartel_Trial::texto( 1, $ts ) );
t( 'N=0: solo la fecha', 'Tu prueba termina el jueves 19/11.' === CN_Cartel_Trial::texto( 0, $ts ) );
t( 'sin palabras de urgencia ni descuentos', 0 === preg_match( '/últim|apurate|ya mismo|descuento|gratis|oferta|%/iu', CN_Cartel_Trial::texto( 5, $ts ) ) );

seccion( 'precio en los botones: sale de CN_MP::PLANES' );
t( 'precio Full = CN_MP::PLANES[full]', '$' . number_format( CN_MP::PLANES['full']['precio'], 0, ',', '.' ) === CN_Cartel_Trial::precio( 'full' ) && '$32.000' === CN_Cartel_Trial::precio( 'full' ) );
t( 'precio Club = CN_MP::PLANES[basico]', '$' . number_format( CN_MP::PLANES['basico']['precio'], 0, ',', '.' ) === CN_Cartel_Trial::precio( 'basico' ) && '$15.500' === CN_Cartel_Trial::precio( 'basico' ) );
t( 'etiqueta Full', 'Quiero el Full · $32.000/mes →' === CN_Cartel_Trial::etiqueta( 'full' ) );
t( 'etiqueta Club', 'Seguir con el Plan Club · $15.500/mes →' === CN_Cartel_Trial::etiqueta( 'basico' ) );
t( 'plan desconocido: sin precio inventado', '' === CN_Cartel_Trial::precio( 'premium' ) );
$clase_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-cartel-trial.php' );
t( 'los precios no están escritos a mano en la clase', false === strpos( $clase_src, '32.000' ) && false === strpos( $clase_src, '32000' ) && false === strpos( $clase_src, '15.500' ) && false === strpos( $clase_src, '15500' ) );

seccion( 'zona horaria: fecha_fin_trial en UTC, misma lógica que CN_Auth' );
$tz_antes = date_default_timezone_get();
date_default_timezone_set( 'America/Argentina/Buenos_Aires' ); // la zona del servidor PHP no tiene que cambiar nada
$fin_utc = '2026-11-19 02:00:00'; // 23:00 del 18/11 en Argentina
$ts_utc  = gmmktime( 2, 0, 0, 11, 19, 2026 );
CN_Cartel_Trial::$reloj = $ts_utc - 3600;
t( 'se lee como UTC: 1 h antes del fin (UTC) es elegible', $ts_utc === CN_Cartel_Trial::fin_trial_si_elegible( ctt_miembro( 0, array( 'fecha_fin_trial' => $fin_utc ) ) ) );
CN_Cartel_Trial::$reloj = $ts_utc + 90 * 60;
t( 'se lee como UTC: 1:30 h después del fin (UTC) ya no (si se leyera en hora argentina seguiría vigente)', null === CN_Cartel_Trial::fin_trial_si_elegible( ctt_miembro( 0, array( 'fecha_fin_trial' => $fin_utc ) ) ) );
CN_Cartel_Trial::$reloj = $ts_utc;
t( 'en el segundo exacto del fin: elegible (CN_Auth todavía deja entrar: vencido solo si fin < ahora)', $ts_utc === CN_Cartel_Trial::fin_trial_si_elegible( ctt_miembro( 0, array( 'fecha_fin_trial' => $fin_utc ) ) ) );
CN_Cartel_Trial::$reloj = $ts_utc + 1;
t( 'un segundo después: no', null === CN_Cartel_Trial::fin_trial_si_elegible( ctt_miembro( 0, array( 'fecha_fin_trial' => $fin_utc ) ) ) );
CN_Cartel_Trial::$reloj = $ts_utc - 172800;
t( 'a 48 h justas: elegible', null !== CN_Cartel_Trial::fin_trial_si_elegible( ctt_miembro( 0, array( 'fecha_fin_trial' => $fin_utc ) ) ) );
CN_Cartel_Trial::$reloj = $ts_utc - 172801;
t( 'a 48 h + 1 s: no', null === CN_Cartel_Trial::fin_trial_si_elegible( ctt_miembro( 0, array( 'fecha_fin_trial' => $fin_utc ) ) ) );
CN_Cartel_Trial::$reloj = null;
$paridad = true;
foreach ( array( -86400, -3600, -60, 60, 3600, 47 * 3600 ) as $off ) { // reloj real, como CN_Auth
	$f       = gmdate( 'Y-m-d H:i:s', time() + $off );
	$vencido = cn_llamar( 'CN_Auth', 'trial_vencido', $f );
	$eleg    = null !== CN_Cartel_Trial::fin_trial_si_elegible( ctt_miembro( 0, array( 'fecha_fin_trial' => $f ) ) );
	if ( $vencido === $eleg ) { // vencido para CN_Auth <=> nunca elegible; vigente con <= 48 h <=> elegible
		$paridad = false;
	}
}
t( 'paridad con CN_Auth::trial_vencido para fechas vencidas y vigentes', $paridad );
foreach ( array( null, '', '0000-00-00 00:00:00' ) as $vacia ) {
	t( 'fecha vacía (' . var_export( $vacia, true ) . '): CN_Auth no la vence y el cartel la trata como manual', false === cn_llamar( 'CN_Auth', 'trial_vencido', $vacia ) && null === CN_Cartel_Trial::fin_trial_si_elegible( ctt_miembro( 0, array( 'fecha_fin_trial' => $vacia ) ) ) );
}
date_default_timezone_set( $tz_antes );

seccion( 'clases vistas: solo lectura de wp_cn_vistos, solo videos' );
$w = ctt_preparar( 'on', null, 7 );
t( 'cuenta lo que devuelve la base', 7 === CN_Cartel_Trial::clases_vistas( 77 ) );
$sql = (string) end( $w->consultas );
t( 'SQL: COUNT sobre wp_cn_vistos unido a contenidos, solo videos, de esa socia', false !== strpos( $sql, 'FROM wp_cn_vistos v INNER JOIN wp_cn_contenidos c ON c.id = v.contenido_id' ) && false !== strpos( $sql, "c.tipo = 'video'" ) && false !== strpos( $sql, 'v.miembro_id = 77' ), $sql );
t( 'sin resultados: 0', ( function () {
	$w = ctt_preparar( 'on', null, 0 );
	return 0 === CN_Cartel_Trial::clases_vistas( 77 );
} )() );

seccion( 'on: se muestra con los dos botones firmados' );
$w = ctt_preparar( 'on', null, 4 );
$m = ctt_miembro( 30 );
$h = CN_Cartel_Trial::html( $m );
t( 'on: hay cartel', '' !== $h && false !== strpos( $h, 'cn-cartel-trial' ) );
t( 'texto con N y la fecha', false !== strpos( $h, 'Entraste a 4 clases del club. Tu prueba termina el ' ) );
$pos_full = strpos( $h, '>Quiero el Full · $32.000/mes →</a>' );
$pos_club = strpos( $h, '>Seguir con el Plan Club · $15.500/mes →</a>' );
t( 'los dos botones con su precio por mes, Full arriba', false !== $pos_full && false !== $pos_club && $pos_full < $pos_club );
$hrefs = ctt_hrefs( $h );
t( 'exactamente 2 links, los dos al endpoint ?cn_suscribir=', 2 === count( $hrefs ) && 0 === strpos( $hrefs[0], 'https://naturalesart.com/?cn_suscribir=' ) && 0 === strpos( $hrefs[1], 'https://naturalesart.com/?cn_suscribir=' ), implode( ' | ', $hrefs ) );
$v_full = CN_Suscribir::verificar( ctt_token_de( $hrefs[0] ) );
$v_club = CN_Suscribir::verificar( ctt_token_de( $hrefs[1] ) );
t( 'Full: token válido de ESA ficha, plan full, origen club_cartel_full', $v_full['ok'] && 77 === $v_full['ficha_id'] && 'full' === $v_full['plan'] && 'club_cartel_full' === $v_full['origen'], $v_full['motivo'] );
t( 'Club: token válido de ESA ficha, plan basico, origen club_cartel_club', $v_club['ok'] && 77 === $v_club['ficha_id'] && 'basico' === $v_club['plan'] && 'club_cartel_club' === $v_club['origen'], $v_club['motivo'] );
$fin = strtotime( $m->fecha_fin_trial . ' UTC' );
t( 'el token vence a las 6 h o al fin del trial, lo que pase primero', $v_full['vence'] <= time() + 6 * 3600 + 2 && $v_full['vence'] <= $fin && $v_full['vence'] > time() );
$v_corto = ( function () {
	ctt_preparar( 'on' );
	$m = ctt_miembro( 2 );
	$h = CN_Cartel_Trial::html( $m );
	$v = CN_Suscribir::verificar( ctt_token_de( ctt_hrefs( $h )[0] ) );
	return $v['ok'] && $v['vence'] === strtotime( $m->fecha_fin_trial . ' UTC' );
} )();
t( 'trial con 2 h: el token vence justo al fin del trial', $v_corto );
t( 'el cartel no lleva nombre, email, celular ni WhatsApp', ctt_sin_datos( $h ) );
t( 'anti doble clic en los dos botones', 2 === substr_count( $h, 'onclick=' ) && false !== strpos( $h, '5000' ) );
t( 'nada fijo ni encima del contenido (sin position:fixed/sticky)', 0 === preg_match( '/position\s*:\s*(fixed|sticky)/i', $h ) );
t( 'on: cartel_trial_mostrado en mp_log, una vez', array( 'cartel_trial_mostrado' ) === ctt_tipos( $w ) );
t( 'mp_log solo con ficha_id, horas y vistos (sin datos personales)', ctt_sin_datos( ctt_payloads( $w ) ) && 1 === preg_match( '/^\{"ficha_id":77,"horas":\d+,"vistos":4\}$/', trim( ctt_payloads( $w ) ) ), ctt_payloads( $w ) );
CN_Cartel_Trial::html( $m );
CN_Cartel_Trial::html( $m );
t( 'tres vistas el mismo día: un solo registro y conteo mostrado = 1', array( 'cartel_trial_mostrado' ) === ctt_tipos( $w ) && array( 'mostrado' => 1 ) === reset( $GLOBALS['cn_opts'][ CN_Cartel_Trial::OPT_CONTEO ] ) );
t( 'on: solo lectura (cero escrituras a miembros/vistos/sesiones)', ctt_solo_lectura( $w ) );
t( 'on: el cartel no llama a Mercado Pago (eso lo hace el endpoint al hacer clic)', array() === $GLOBALS['cn_http_log'] );
$w = ctt_preparar( 'on', null, 0 );
t( 'on con N=0: solo la fecha', false === strpos( CN_Cartel_Trial::html( ctt_miembro( 30 ) ), 'Entraste' ) );

seccion( 'on: motivos para no mostrarlo (y se cuentan)' );
foreach ( array(
	'suscribir_off' => function () { return ctt_preparar( 'on', null, 4, 'off' ); },
	'sin_secreto'   => function () { return ctt_preparar( 'on', null, 4, 'on', '' ); },
	'sin_email'     => function () { return ctt_preparar( 'on', ctt_ficha( array( 'email' => '' ) ) ); },
	'sin_celular'   => function () { return ctt_preparar( 'on', ctt_ficha( array( 'celular_texto_plano' => '' ) ) ); },
	'con_cache'     => function () { $w = ctt_preparar( 'on' ); CN_Cartel_Trial::$sin_cache = false; return $w; },
) as $motivo => $prep ) {
	$w = $prep();
	$h = CN_Cartel_Trial::html( ctt_miembro( 30 ) );
	t( "on/{$motivo}: no se muestra", '' === $h );
	t( "on/{$motivo}: conteo {$motivo} = 1, sin mp_log", array( $motivo => 1 ) === reset( $GLOBALS['cn_opts'][ CN_Cartel_Trial::OPT_CONTEO ] ) && array() === ctt_tipos( $w ) );
}
$w = ctt_preparar( 'on', ctt_ficha( array( 'celular_texto_plano' => '011 15 5555-0099' ) ) );
t( 'on: celular que no coincide con el hash = sin_celular', '' === CN_Cartel_Trial::html( ctt_miembro( 30 ) ) && isset( reset( $GLOBALS['cn_opts'][ CN_Cartel_Trial::OPT_CONTEO ] )['sin_celular'] ) );

seccion( 'log: no muestra; registra a quién y cuenta por motivo' );
$w = ctt_preparar( 'log', null, 3 );
$h = CN_Cartel_Trial::html( ctt_miembro( 20 ) );
t( 'log: no se muestra nada', '' === $h );
t( 'log: cartel_trial_elegible en mp_log', array( 'cartel_trial_elegible' ) === ctt_tipos( $w ) );
t( 'log: payload ficha_id + horas + vistos, sin datos personales', 1 === preg_match( '/^\{"ficha_id":77,"horas":(19|20),"vistos":3\}$/', trim( ctt_payloads( $w ) ) ) && ctt_sin_datos( ctt_payloads( $w ) ), ctt_payloads( $w ) );
t( 'log: conteo se_mostraria = 1', array( 'se_mostraria' => 1 ) === reset( $GLOBALS['cn_opts'][ CN_Cartel_Trial::OPT_CONTEO ] ) );
CN_Cartel_Trial::html( ctt_miembro( 20 ) );
t( 'log: misma ficha el mismo día no se repite', array( 'cartel_trial_elegible' ) === ctt_tipos( $w ) && array( 'se_mostraria' => 1 ) === reset( $GLOBALS['cn_opts'][ CN_Cartel_Trial::OPT_CONTEO ] ) );
t( 'log: solo lectura y sin Mercado Pago', ctt_solo_lectura( $w ) && array() === $GLOBALS['cn_http_log'] );
foreach ( array(
	'suscribir_off' => function () { return ctt_preparar( 'log', null, 4, 'off' ); },
	'sin_secreto'   => function () { return ctt_preparar( 'log', null, 4, 'on', '' ); },
	'sin_email'     => function () { return ctt_preparar( 'log', ctt_ficha( array( 'email' => 'no-es-email' ) ) ); },
	'sin_celular'   => function () { return ctt_preparar( 'log', ctt_ficha( array( 'celular_texto_plano' => '' ) ) ); },
) as $motivo => $prep ) {
	$w = $prep();
	$h = CN_Cartel_Trial::html( ctt_miembro( 30 ) );
	$c = reset( $GLOBALS['cn_opts'][ CN_Cartel_Trial::OPT_CONTEO ] );
	t( "log/{$motivo}: no se muestra, conteo {$motivo} = 1, sin fila en mp_log (solo conteo)", '' === $h && array( $motivo => 1 ) === $c && array() === ctt_tipos( $w ) );
}
t( 'log: el conteo guarda solo números por motivo (sin id de ficha)', ( function () {
	ctt_preparar( 'log', ctt_ficha( array( 'email' => '' ) ) );
	CN_Cartel_Trial::html( ctt_miembro( 30 ) );
	CN_Cartel_Trial::html( ctt_miembro( 30, array( 'id' => 78 ) ) ); // otra ficha, sin fila en la base => sin_email
	$c = CN_Cartel_Trial::conteo();
	$j = json_encode( $c );
	return array( 'sin_email' => 2 ) === reset( $c ) && false === strpos( $j, '77' ) && false === strpos( $j, '78' );
} )() );
t( 'conteo: se guarda a lo sumo 30 días', ( function () {
	cn_test_reset();
	$viejo = array();
	for ( $i = 1; $i <= 40; $i++ ) {
		$viejo[ gmdate( 'Y-m-d', time() - $i * 86400 ) ] = array( 'se_mostraria' => 1 );
	}
	update_option( CN_Cartel_Trial::OPT_CONTEO, $viejo );
	$c = new ReflectionMethod( 'CN_Cartel_Trial', 'contar' );
	$c->invoke( null, 'mostrado' );
	$k = array_keys( CN_Cartel_Trial::conteo() );
	return 30 === count( $k ) && gmdate( 'Y-m-d' ) === $k[0];
} )() );

seccion( 'endpoint: los orígenes nuevos llegan a la pendiente y van directo a MP' );
foreach ( array( 'full' => 'club_cartel_full', 'basico' => 'club_cartel_club' ) as $plan => $origen ) {
	$w = ctt_preparar( 'on' );
	$tok = CN_Suscribir::crear_token( 77, $plan, $origen, time() + 3600, CTT_SECRETO );
	$r   = CN_Suscribir::procesar( $tok );
	$ins = array_values( array_filter( $w->escrituras( 'suscripciones_pendientes' ), function ( $l ) { return 'insert' === $l[0]; } ) );
	t( "{$origen}: creada y redirige al init_point de MP", 'creada' === $r['caso'] && 0 === strpos( $r['redirect'], 'https://www.mercadopago.com.ar/' ), $r['caso'] );
	t( "{$origen}: pendiente con plan {$plan} y origen {$origen}", 1 === count( $ins ) && $plan === $ins[0][2]['plan'] && $origen === $ins[0][2]['origen'] );
	t( "{$origen}: un solo POST a /preapproval y ninguna escritura en miembros", 1 === count( cn_http_calls( '#/preapproval$#' ) ) && array() === $w->escrituras( 'miembros' ) );
}
$w = ctt_preparar( 'on' );
$w->on( '/SELECT external_reference, init_point FROM wp_cn_suscripciones_pendientes/', array( cn_fila( array( 'external_reference' => 'sub_x', 'init_point' => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=pre_previa' ) ) ) );
$r = CN_Suscribir::procesar( CN_Suscribir::crear_token( 77, 'full', 'club_cartel_full', time() + 3600, CTT_SECRETO ) );
t( 'doble clic (pendiente de 24 h): reusa el mismo checkout, no crea otra suscripción', 'reusada' === $r['caso'] && false !== strpos( $r['redirect'], 'pre_previa' ) && array() === cn_http_calls( '#/preapproval$#' ) );
$w = ctt_preparar( 'on', ctt_ficha( array( 'preapproval_id' => 'pre_1' ) ) );
$r = CN_Suscribir::procesar( CN_Suscribir::crear_token( 77, 'full', 'club_cartel_full', time() + 3600, CTT_SECRETO ) );
t( 'si entre medio se suscribió: ya_activa, no se crea otra', 'ya_activa' === $r['caso'] && array() === cn_http_calls( '#/preapproval$#' ) );
cn_test_reset();
t( 'crear_suscripcion_link acepta los dos orígenes nuevos (y sigue rechazando uno inventado)', in_array( 'club_cartel_full', CN_MP::ORIGENES_SUSCRIPCION, true ) && in_array( 'club_cartel_club', CN_MP::ORIGENES_SUSCRIPCION, true ) && is_wp_error( CN_MP::crear_suscripcion_link( 'basico', 'X', CTT_CEL, CTT_EMAIL, 'club_cartel_otro' ) ) );
t( 'los orígenes nuevos entran en la columna origen varchar(20)', strlen( 'club_cartel_full' ) <= 20 && strlen( 'club_cartel_club' ) <= 20 );
t( 'un origen inventado con el mismo prefijo no pasa verificar()', ( function () {
	update_option( CN_Suscribir::OPT_SECRETO, CTT_SECRETO );
	$p = '77.full.club_cartel_otro.' . ( time() + 3600 );
	return 'formato' === CN_Suscribir::verificar( $p . '.' . hash_hmac( 'sha256', $p, CTT_SECRETO ) )['motivo'];
} )() );

seccion( 'inspección: dónde se engancha y qué no toca' );
$raiz  = dirname( __DIR__ ) . '/';
$short = file_get_contents( $raiz . 'includes/class-cn-shortcodes.php' );
$main  = file_get_contents( $raiz . 'club-natureza-miembros.php' );
$clase = ''; // solo código: sin comentarios (la cabecera explica qué NO toca y nombra esas cosas)
foreach ( token_get_all( file_get_contents( $raiz . 'includes/class-cn-cartel-trial.php' ) ) as $tk ) {
	if ( is_array( $tk ) && in_array( $tk[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}
	$clase .= is_array( $tk ) ? $tk[1] : $tk;
}
t( 'shortcodes: se llama una sola vez', 1 === substr_count( $short, 'CN_Cartel_Trial::html(' ) );
t( 'shortcodes: solo en la portada (rama de render_home, no en render_curso)', 1 === preg_match( '/\}\s*else\s*\{[^{}]*if \( class_exists\( \'CN_Cartel_Trial\' \) \) \{\s*echo CN_Cartel_Trial::html\( \$miembro \);[^\n]*\n\s*\}\s*self::render_home\( \$miembro \);/', $short ) );
$pos_auth = strpos( $short, '$miembro = CN_Auth::get_miembro_actual();' );
t( 'shortcodes: va después de decidir el acceso (CN_Auth)', false !== $pos_auth && $pos_auth < strpos( $short, 'CN_Cartel_Trial::html(' ) );
t( 'carga con guard file_exists (deploy por SFTP)', 1 === preg_match( "/if \( file_exists\( CN_PATH \. 'includes\/class-cn-cartel-trial\.php' \) \) \{[^
]*
\s*require_once CN_PATH \. 'includes\/class-cn-cartel-trial\.php';/", $main ) );
t( 'clase: sin UPDATE/DELETE/INSERT directos', 0 === preg_match( '/\b(UPDATE|DELETE FROM|INSERT INTO)\b|->(update|delete|insert|query)\(/', $clase ) );
t( 'clase: no toca login, sesiones, webhook de MP ni sync (de CN_MP solo lee get_planes)', 0 === preg_match( '/CN_Auth::|CN_Sync::|CN_MP::(?!get_planes\(\))|crear_sesion|logout|wp_remote_|wp_mail\(/', $clase ) );
t( 'clase: sin WhatsApp', false === stripos( $clase, 'whatsapp' ) && false === stripos( $clase, 'wa.me' ) );
t( 'clase: el modo por defecto es off', false !== strpos( $clase, "get_option( self::OPT_MODO, 'off' )" ) );
CN_Cartel_Trial::$reloj     = null;
CN_Cartel_Trial::$sin_cache = null;
