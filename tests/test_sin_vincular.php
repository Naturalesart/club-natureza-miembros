<?php
// Pantalla "Suscripciones sin vincular" (CN_Sin_Vincular): niveles con las reglas existentes, enmascarado, CSV completo,
// solo GET y cero escrituras, permisos y nonce, URL del botón por nivel, y el cartel sub_sin_vincular sigue igual.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
CN_Sin_Vincular::$terminar = false;
function sv_id( $n ) {
	return md5( 'sub' . $n ); // 32 hex, prefijos distintos
}
function sv_sub( $n, $email, $monto = 15500, $hace = 86400 ) {
	$s = array( 'id' => sv_id( $n ), 'status' => 'authorized', 'date_created' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - $hace ), 'next_payment_date' => '2026-11-10T00:00:00.000-03:00', 'auto_recurring' => array( 'transaction_amount' => $monto ) );
	if ( null !== $email ) {
		$s['payer_email'] = $email;
	}
	return $s;
}
function sv_ficha( $id, $nombre, $email, $cel, $extra = array() ) {
	return cn_fila( array_merge( array(
		'id' => $id, 'nombre_apellido' => $nombre, 'estado' => 'activo', 'preapproval_id' => null, 'plan' => null, 'acceso_hasta' => null, 'email' => $email,
		'fecha_fin_trial' => null, 'fecha_modificacion' => null, 'baja_en' => null, 'celular_hint' => substr( $cel, -4 ), 'celular_hash' => CN_Helpers::hash_celular( $cel ), 'celular_texto_plano' => $cel,
	), $extra ) );
}
function sv_preparar( $subs, $fichas ) {
	cn_test_reset();
	global $wpdb;
	unset( $GLOBALS['cn_caps'], $GLOBALS['cn_nonce_ok'] );
	$_POST = $_GET = array();
	update_option( 'cn_mp_access_token', 'APP_USR-test' );
	cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', array( 'results' => $subs, 'paging' => array( 'total' => count( $subs ) ) ) );
	cn_http_on( 'GET', '#/v1/payments/search#', array( 'results' => array(), 'paging' => array( 'total' => 0 ) ) );
	cn_http_on( 'GET', '#/authorized_payments/search#', array( 'results' => array() ) );
	cn_http_on( 'POST', '#club-trial-alerta#', array() );
	$wpdb->on( '/SELECT ' . preg_quote( CN_Vinculo_Manual::COLS, '/' ) . ' FROM wp_cn_miembros/', $fichas );
	return $wpdb;
}
function sv_pagina( $post ) {
	$_POST = $post;
	ob_start();
	CN_Sin_Vincular::pagina();
	$h = ob_get_clean();
	$_POST = array();
	return $h;
}
/** Simula el hook admin_init en la página con el POST del botón CSV; devuelve lo emitido. */
function sv_csv( $post = array( 'cn_sv_accion' => 'csv', 'cn_sv_nonce' => 'x' ), $page = 'cn-sin-vincular' ) {
	$_GET  = array( 'page' => $page );
	$_POST = $post;
	$GLOBALS['cn_nocache'] = 0;
	ob_start();
	$ret = CN_Sin_Vincular::descargar_csv();
	$h = ob_get_clean();
	$_POST = $_GET = array();
	return array( $h, $ret );
}
function sv_por_id( $r ) {
	$m = array();
	foreach ( $r['filas'] as $f ) {
		$m[ $f['id'] ] = $f;
	}
	return $m;
}
$fichas = array(
	sv_ficha( 1, 'Ana Lopez', 'ana@example.com', '1155510001' ),                 // fuerte para la sub 1
	sv_ficha( 2, 'Bea Gomez', 'bea@example.com', '1144420002' ),                 // email de la sub 2, pero tiene gemela por nombre (#3)
	sv_ficha( 3, 'Bea Gómez', 'otra@example.com', '1133330003' ),                // gemela de #2
	sv_ficha( 4, 'Cris Perez', 'cris@example.com', '1122240004', array( 'preapproval_id' => sv_id( 90 ) ) ), // ya tiene OTRA suscripción
	sv_ficha( 5, 'Dani Ruiz', 'dup@example.com', '1166650005' ),                 // dos fichas con el mismo email
	sv_ficha( 6, 'Dani Ruiz Otra', 'dup@example.com', '1177760006' ),
	sv_ficha( 7, 'Eva Sosa', 'eva@example.com', '', array( 'celular_hint' => '', 'celular_hash' => '' ) ), // email exacto pero sin celular válido
	sv_ficha( 8, 'Flor Vinculada', 'flor@example.com', '1188880008', array( 'preapproval_id' => sv_id( 8 ) ) ), // ya vinculada a la sub 8
);
$subs = array(
	sv_sub( 1, 'ana@example.com' ),             // fuerte
	sv_sub( 2, 'bea@example.com' ),             // medio: ficha parecida
	sv_sub( 3, 'cris@example.com' ),            // medio: la socia ya tiene otra suscripción
	sv_sub( 4, 'dup@example.com' ),             // medio: más de una socia
	sv_sub( 5, 'eva@example.com' ),             // medio: sin celular válido
	sv_sub( 6, 'nadie@example.com' ),           // sin match: ningún email coincide
	sv_sub( 7, null ),                          // sin match: sin email
	sv_sub( 8, 'flor@example.com' ),            // ya vinculada: no se lista
	sv_sub( 9, 'ana2@example.com', 9999 ),      // sin match: monto sin plan (y sin ficha)
);

seccion( 'niveles con las reglas existentes (proponer + candidatas_seguras)' );
$w = sv_preparar( $subs, $fichas );
$r = CN_Sin_Vincular::consultar( true );
$m = sv_por_id( $r );
t( 'consulta ok, 8 sin vincular (la ya vinculada no se lista)', $r['ok'] && 8 === count( $r['filas'] ) && ! isset( $m[ sv_id( 8 ) ] ) );
t( 'fuerte: email exacto, única ficha, sin otra suscripción ni ficha parecida', 'fuerte' === $m[ sv_id( 1 ) ]['nivel'] && 1 === count( $m[ sv_id( 1 ) ]['candidatas'] ) && 1 === (int) $m[ sv_id( 1 ) ]['candidatas'][0]->id );
t( 'medio: ficha parecida (gemela por nombre)', 'medio' === $m[ sv_id( 2 ) ]['nivel'] && 0 === strpos( $m[ sv_id( 2 ) ]['motivo'], 'ficha parecida #3' ) && 2 === (int) $m[ sv_id( 2 ) ]['candidatas'][0]->id );
t( 'medio: la socia ya tiene otra suscripción', 'medio' === $m[ sv_id( 3 ) ]['nivel'] && 'la socia ya tiene otra suscripción' === $m[ sv_id( 3 ) ]['motivo'] );
t( 'medio: más de una socia con ese email (muestra las dos)', 'medio' === $m[ sv_id( 4 ) ]['nivel'] && 2 === count( $m[ sv_id( 4 ) ]['candidatas'] ) );
t( 'medio: email exacto pero sin celular válido', 'medio' === $m[ sv_id( 5 ) ]['nivel'] && 'sin celular válido' === $m[ sv_id( 5 ) ]['motivo'] );
t( 'sin match: ningún email coincide (sin candidatas)', 'sin_match' === $m[ sv_id( 6 ) ]['nivel'] && array() === $m[ sv_id( 6 ) ]['candidatas'] );
t( 'sin match: sin email', 'sin_match' === $m[ sv_id( 7 ) ]['nivel'] && 'sin email' === $m[ sv_id( 7 ) ]['motivo'] );
t( 'sin match: monto sin plan conocido', 'sin_match' === $m[ sv_id( 9 ) ]['nivel'] && null === $m[ sv_id( 9 ) ]['plan'] );
t( 'ordenadas: fuerte primero, después medio, después sin match', array( 'fuerte', 'medio', 'medio', 'medio', 'medio', 'sin_match', 'sin_match', 'sin_match' ) === array_column( $r['filas'], 'nivel' ) );
t( 'plan, monto, próximo cobro y alta vienen de MP', 'basico' === $m[ sv_id( 1 ) ]['plan'] && 15500 === $m[ sv_id( 1 ) ]['monto'] && '2026-11-10' === $m[ sv_id( 1 ) ]['proximo_cobro'] && 10 === strlen( $m[ sv_id( 1 ) ]['alta'] ) );
// Misma socia en dos suscripciones: ambas pasan a medio.
$w = sv_preparar( array( sv_sub( 1, 'ana@example.com' ), sv_sub( 2, 'ana@example.com' ) ), array( $fichas[0] ) );
$r = CN_Sin_Vincular::consultar( true );
t( 'la misma socia para dos suscripciones: las dos quedan en medio', array( 'medio', 'medio' ) === array_column( $r['filas'], 'nivel' ) );
// Cache de 10 min.
$w = sv_preparar( $subs, $fichas );
CN_Sin_Vincular::consultar( true );
$n = count( $GLOBALS['cn_http_log'] );
$r2 = CN_Sin_Vincular::consultar();
t( 'la segunda consulta dentro de los 10 min sale del transient (sin llamadas nuevas)', count( $GLOBALS['cn_http_log'] ) === $n && 8 === count( $r2['filas'] ) );

seccion( 'solo GET y cero escrituras' );
$w = sv_preparar( $subs, $fichas );
CN_Sin_Vincular::consultar( true );
t( 'todas las llamadas a MP fueron GET', array( 'GET' ) === array_values( array_unique( array_column( $GLOBALS['cn_http_log'], 'metodo' ) ) ) );
t( 'cero escrituras en la base (ni miembros, ni mp_log, ni transacciones)', ! $w->log );
t( 'no toca options (solo el transient)', array( 'cn_mp_access_token' => 'APP_USR-test' ) === $GLOBALS['cn_opts'] && is_array( get_transient( CN_Sin_Vincular::TRANSIENT ) ) );
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-sin-vincular.php' );
t( 'el código no tiene escrituras: ni insert/update/delete/query, ni update_option, ni registrar_log_mp, ni POST a MP, ni vincular_suscripcion', ! preg_match( '/->insert\(|->update\(|->delete\(|->query\(|update_option|registrar_log_mp|wp_remote_post|wp_remote_request|vincular_suscripcion\(|confirmar\(/', $src ) );

seccion( 'MP falla o no hay token: no concluye nada' );
$w = sv_preparar( $subs, $fichas );
cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', 500 );
$r = CN_Sin_Vincular::consultar( true );
t( 'MP 500: ok=false, sin filas, sin transient', ! $r['ok'] && ! $r['filas'] && false === get_transient( CN_Sin_Vincular::TRANSIENT ) );
cn_test_reset();
t( 'sin token: avisa y no consulta', ! CN_Sin_Vincular::consultar( true )['ok'] && ! $GLOBALS['cn_http_log'] );

seccion( 'pantalla: enmascarado' );
$w = sv_preparar( $subs, $fichas );
$h = sv_pagina( array( 'cn_sv_accion' => 'consultar', 'cn_sv_nonce' => 'x' ) );
t( 'ids enmascarados (6 + ...) en la tabla; el id completo solo va dentro del enlace Vincular', false !== strpos( $h, '<code>' . substr( sv_id( 1 ), 0, 6 ) . '...</code>' ) && 1 === substr_count( $h, sv_id( 1 ) ) && 1 === preg_match( '#cn_vincular_id=' . sv_id( 1 ) . '#', $h ) && 0 === preg_match( '#<code>[a-f0-9]{32}</code>#', $h ) );
t( 'email del pagador enmascarado (2 letras + ***@dominio), nunca completo', false !== strpos( $h, 'an***@example.com' ) && false === strpos( $h, 'ana@example.com' ) && false === strpos( $h, 'nadie@example.com' ) );
t( 'candidata con id, nombre, estado y celular enmascarado (****hint)', false !== strpos( $h, '#1 Ana Lopez · activo · ****0001' ) && false === strpos( $h, '1155510001' ) );
t( 'contador por nivel y etiquetas', false !== strpos( $h, 'fuerte: 1' ) && false !== strpos( $h, 'medio: 4' ) && false !== strpos( $h, 'medio (por nombre): 0' ) && false !== strpos( $h, 'sin match: 3' ) && false !== strpos( $h, 'Medio (elegir ficha)' ) );
t( 'escapa el HTML del nombre', ( function () use ( $subs ) {
	$w = sv_preparar( array( sv_sub( 1, 'ana@example.com' ) ), array( sv_ficha( 1, '<b>Ana</b>', 'ana@example.com', '1155510001' ) ) );
	$h = sv_pagina( array( 'cn_sv_accion' => 'consultar', 'cn_sv_nonce' => 'x' ) );
	return false === strpos( $h, '<b>Ana</b>' ) && false !== strpos( $h, '&lt;b&gt;Ana' );
} )() );
$w = sv_preparar( $subs, $fichas );
$h = sv_pagina( array() );
t( 'sin consultar y sin cache: solo el formulario, sin llamadas a MP', ! $GLOBALS['cn_http_log'] && false !== strpos( $h, 'Consultar Mercado Pago' ) && false === strpos( $h, '<table' ) );
sv_pagina( array( 'cn_sv_accion' => 'consultar', 'cn_sv_nonce' => 'x' ) );
$n = count( $GLOBALS['cn_http_log'] );
$h = sv_pagina( array() );
t( 'con cache: muestra la tabla guardada sin volver a consultar', count( $GLOBALS['cn_http_log'] ) === $n && false !== strpos( $h, '<table' ) && false !== strpos( $h, 'se guarda 10 minutos' ) );

seccion( 'botón Vincular: solo un enlace al bloque existente, por nivel' );
$w = sv_preparar( $subs, $fichas );
$r = CN_Sin_Vincular::consultar( true );
$m = sv_por_id( $r );
t( 'fuerte: id + ficha precargados y ancla #cn-vincular-mp', 'https://naturalesart.com/wp-admin/admin.php?page=cn-socias&cn_vincular_id=' . sv_id( 1 ) . '&cn_vincular_socia=1#cn-vincular-mp' === CN_Sin_Vincular::url_vincular( $m[ sv_id( 1 ) ] ) );
t( 'medio con una sola ficha: id + ficha precargados (la persona igual verifica y confirma)', false !== strpos( CN_Sin_Vincular::url_vincular( $m[ sv_id( 2 ) ] ), 'cn_vincular_socia=2' ) );
t( 'medio con varias fichas: solo el id (elige la persona)', false === strpos( CN_Sin_Vincular::url_vincular( $m[ sv_id( 4 ) ] ), 'cn_vincular_socia' ) && false !== strpos( CN_Sin_Vincular::url_vincular( $m[ sv_id( 4 ) ] ), 'cn_vincular_id=' . sv_id( 4 ) ) );
t( 'sin match: solo el id', false === strpos( CN_Sin_Vincular::url_vincular( $m[ sv_id( 6 ) ] ), 'cn_vincular_socia' ) );
$h = sv_pagina( array( 'cn_sv_accion' => 'consultar', 'cn_sv_nonce' => 'x' ) );
t( 'la pantalla no tiene ningún formulario de vinculación (ni en lote): solo enlaces', 8 === substr_count( $h, '#cn-vincular-mp' ) && false === strpos( $h, 'cn_vm_accion' ) && false === strpos( $h, 'cn_vl_' ) && false === strpos( $h, 'cn_vm2_' ) );

seccion( 'CSV: hook admin_init, datos completos, BOM, solo manage_options + nonce' );
t( 'el hook admin_init -> descargar_csv está registrado (y admin_menu -> menu)', in_array( array( 'CN_Sin_Vincular', 'descargar_csv' ), $GLOBALS['cn_hooks']['admin_init'] ?? array(), true ) && in_array( array( 'CN_Sin_Vincular', 'menu' ), $GLOBALS['cn_hooks']['admin_menu'] ?? array(), true ) );
$w = sv_preparar( $subs, $fichas );
list( $csv, $ret ) = sv_csv();
t( 'con permisos y nonce: emite, llama a nocache_headers y devuelve true', true === $ret && 1 === $GLOBALS['cn_nocache'] && '' !== $csv );
t( 'empieza con BOM UTF-8 y la cabecera', 0 === strpos( $csv, "\xEF\xBB\xBF" . 'preapproval_id,email_pagador,plan,monto,proximo_cobro,alta_mp,nivel,motivo,candidata_id,candidata_nombre,candidata_estado,titular_tarjeta,candidata_celular_ultimos4' ) );
t( 'trae el preapproval_id y el email del pagador COMPLETOS', false !== strpos( $csv, sv_id( 1 ) . ',ana@example.com,basico,15500,2026-11-10,' ) && false !== strpos( $csv, sv_id( 6 ) . ',nadie@example.com,' ) );
t( 'candidata con id, nombre y estado; varias separadas con |', false !== strpos( $csv, ',fuerte,' ) && false !== strpos( $csv, ',1,"Ana Lopez",activo' ) && false !== strpos( $csv, '"5 | 6","Dani Ruiz | Dani Ruiz Otra","activo | activo"' ) );
t( 'sin celular completo (solo últimos 4 en su columna)', false === strpos( $csv, '1155510001' ) && false === strpos( $csv, '****' ) );
t( 'una fila por suscripción sin vincular (8) + cabecera', 9 === count( array_filter( explode( "\n", trim( $csv ) ) ) ) );
t( 'el CSV no escribe nada ni hace POST', ! $w->log && array( 'GET' ) === array_values( array_unique( array_column( $GLOBALS['cn_http_log'], 'metodo' ) ) ) );
t( 'el CSV sale del transient si ya se consultó (sin llamadas nuevas)', ( function () use ( $subs, $fichas ) {
	$w = sv_preparar( $subs, $fichas );
	CN_Sin_Vincular::consultar( true );
	$n = count( $GLOBALS['cn_http_log'] );
	list( $csv ) = sv_csv();
	return count( $GLOBALS['cn_http_log'] ) === $n && false !== strpos( $csv, sv_id( 1 ) );
} )() );
// Dónde NO actúa.
$w = sv_preparar( $subs, $fichas );
list( $h, $ret ) = sv_csv( array( 'cn_sv_accion' => 'csv', 'cn_sv_nonce' => 'x' ), 'cn-socias' );
t( 'en otra página de wp-admin no hace nada (ni consulta)', '' === $h && false === $ret && ! $GLOBALS['cn_http_log'] && 0 === $GLOBALS['cn_nocache'] );
list( $h, $ret ) = sv_csv( array( 'cn_sv_accion' => 'consultar', 'cn_sv_nonce' => 'x' ) );
t( 'con el botón Consultar (no csv) no emite nada', '' === $h && false === $ret && ! $GLOBALS['cn_http_log'] );
list( $h, $ret ) = sv_csv( array() );
t( 'sin POST no hace nada', '' === $h && false === $ret );
$w = sv_preparar( $subs, $fichas );
$GLOBALS['cn_caps'] = array( 'edit_posts' );
list( $h, $ret ) = sv_csv();
t( 'sin manage_options: no emite nada, no consulta, no toca headers', '' === $h && false === $ret && ! $GLOBALS['cn_http_log'] && 0 === $GLOBALS['cn_nocache'] );
$h = sv_pagina( array( 'cn_sv_accion' => 'consultar', 'cn_sv_nonce' => 'x' ) );
t( 'sin manage_options: tampoco la pantalla', '' === $h && ! $GLOBALS['cn_http_log'] );
$w = sv_preparar( $subs, $fichas );
$GLOBALS['cn_nonce_ok'] = false;
list( $h, $ret ) = sv_csv();
t( 'nonce inválido: no emite nada ni consulta', '' === $h && false === $ret && ! $GLOBALS['cn_http_log'] && 0 === $GLOBALS['cn_nocache'] );
$h = sv_pagina( array( 'cn_sv_accion' => 'consultar', 'cn_sv_nonce' => 'x' ) );
t( 'nonce inválido: no consulta', ! $GLOBALS['cn_http_log'] && false === strpos( $h, '<table' ) );
$w = sv_preparar( $subs, $fichas );
cn_http_on( 'GET', '#/preapproval/search\?status=authorized#', 500 );
list( $h, $ret ) = sv_csv();
t( 'MP caído: no emite CSV ni headers; pagina() muestra el error', '' === $h && false === $ret && 0 === $GLOBALS['cn_nocache'] && false !== strpos( sv_pagina( array( 'cn_sv_accion' => 'csv', 'cn_sv_nonce' => 'x' ) ), 'No se cambió nada' ) );
cn_test_reset();
list( $h, $ret ) = sv_csv();
t( 'sin token: no emite nada', '' === $h && false === $ret );
t( 'pagina() ya no contiene código de CSV (ni csv(), ni headers, ni BOM): todo vive en descargar_csv()', ( function () use ( $src ) {
	$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-sin-vincular.php' );
	preg_match( '/public static function pagina\(\).*$/s', $src, $m );
	$pag = $m[0] ?? '';
	return '' !== $pag && false === strpos( $pag, 'self::csv(' ) && false === strpos( $pag, 'header(' ) && false === strpos( $pag, 'nocache_headers' ) && false === strpos( $pag, 'Content-Disposition' ) && false === strpos( $pag, 'cn_csv' )
		&& 1 === substr_count( $src, 'echo self::csv(' ) && 1 === preg_match( '/function descargar_csv\(.*?echo self::csv\(/s', $src );
} )() );
t( 'el formulario de la pantalla sigue teniendo el botón CSV como POST con nonce', ( function () use ( $subs, $fichas ) {
	sv_preparar( $subs, $fichas );
	$h = sv_pagina( array() );
	return false !== strpos( $h, 'name="cn_sv_accion" value="csv"' ) && false !== strpos( $h, 'cn_sv_nonce' ) && false !== strpos( $h, '<form method="post"' );
} )() );
t( 'el submenú exige manage_options', 1 === preg_match( "/add_submenu_page\( 'cn-socias', 'Suscripciones sin vincular', 'Suscripciones sin vincular', 'manage_options'/", $src ) );
t( 'una sola línea de require en el archivo principal', 1 === substr_count( file_get_contents( dirname( __DIR__ ) . '/club-natureza-miembros.php' ), 'class-cn-sin-vincular.php' ) );

seccion( 'el cartel sub_sin_vincular (CN_Sync) no cambia' );
$w = sv_preparar( array( sv_sub( 6, 'nadie@example.com', 15500, 2 * 3600 ) ), $fichas );
$w->on( '/SELECT id, nombre_apellido, estado, email, celular_hint, preapproval_id, fecha_fin_trial FROM wp_cn_miembros/', $fichas );
CN_Sin_Vincular::consultar( true );
sv_csv();
t( 'usar la pantalla y el CSV no toca cn_sync_alertados ni deja sub_sin_vincular en mp_log', false === get_option( 'cn_sync_alertados' ) && ! $w->log );
$r = CN_Sync::alertar_sin_vincular();
$tipos = $w->log_mp_tipos();
t( 'el sync sigue avisando igual que antes: 1 avisada, aviso_error + sub_sin_vincular_avisada, sin n8n', 1 === $r['avisadas'] && in_array( 'sub_sin_vincular_avisada', $tipos, true ) && in_array( CN_Webhook::TIPO_AVISO, $tipos, true ) && ! cn_http_calls( '#club-trial-alerta#' ) );
