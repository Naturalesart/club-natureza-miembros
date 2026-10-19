<?php
// Punto 11: rol "Colaboradora Club" (CN_Colaboradora): acceso acotado para cargar contenido.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
function cb_run( $caps, $post, $cursos_existentes = array( 5 ) ) {
	cn_test_reset();
	$GLOBALS['cn_caps'] = $caps;
	global $wpdb;
	foreach ( $cursos_existentes as $id ) {
		$wpdb->on( '/FROM wp_cn_cursos WHERE id = ' . $id . '\b/', 1 );
	}
	$_POST = $post;
	$r     = CN_Colaboradora::procesar();
	return array( $r, $wpdb );
}
$CAP = array( CN_Colaboradora::CAP );

seccion( 'rol: solo lectura + permiso propio, nunca administrador' );
$GLOBALS['cn_roles'] = array();
CN_Colaboradora::registrar_rol();
$rol = $GLOBALS['cn_roles'][ CN_Colaboradora::ROL ] ?? null;
t( 'crea el rol', null !== $rol );
t( 'tiene read y el permiso propio', ! empty( $rol['caps']['read'] ) && ! empty( $rol['caps'][ CN_Colaboradora::CAP ] ) );
t( 'NO tiene manage_options ni ningún otro permiso', array( 'read', CN_Colaboradora::CAP ) === array_keys( $rol['caps'] ) );
$GLOBALS['cn_roles'][ CN_Colaboradora::ROL ]['marca'] = 1;
CN_Colaboradora::registrar_rol();
t( 'segunda llamada no vuelve a crear el rol (no pisa nada)', 1 === ( $GLOBALS['cn_roles'][ CN_Colaboradora::ROL ]['marca'] ?? 0 ) );

seccion( 'menú y redirección al iniciar sesión' );
$GLOBALS['cn_menus'] = array();
$GLOBALS['cn_caps']  = $CAP;
CN_Colaboradora::registrar_menu();
t( 'la colaboradora ve el menú', 1 === count( $GLOBALS['cn_menus'] ) && CN_Colaboradora::PAGINA === $GLOBALS['cn_menus'][0][0] );
$GLOBALS['cn_menus'] = array();
$GLOBALS['cn_caps']  = array( 'manage_options' );
CN_Colaboradora::registrar_menu();
t( 'un administrador también (para previsualizar)', 1 === count( $GLOBALS['cn_menus'] ) );
$GLOBALS['cn_menus'] = array();
$GLOBALS['cn_caps']  = array( 'read' );
CN_Colaboradora::registrar_menu();
t( 'una socia/suscriptora común no lo ve', 0 === count( $GLOBALS['cn_menus'] ) );
$u_col = (object) array( 'caps' => array( CN_Colaboradora::CAP, 'read' ) );
$u_adm = (object) array( 'caps' => array( 'manage_options', CN_Colaboradora::CAP ) );
$u_otr = (object) array( 'caps' => array( 'read' ) );
t( 'login: la colaboradora cae en su pantalla', false !== strpos( CN_Colaboradora::redirigir_login( 'X', '', $u_col ), 'page=' . CN_Colaboradora::PAGINA ) );
t( 'login: administrador y otros usuarios no cambian de destino', 'X' === CN_Colaboradora::redirigir_login( 'X', '', $u_adm ) && 'X' === CN_Colaboradora::redirigir_login( 'X', '', $u_otr ) );
t( 'login: error de login no rompe', 'X' === CN_Colaboradora::redirigir_login( 'X', '', new WP_Error( 'e', 'm' ) ) );

seccion( 'la colaboradora NO entra a las pantallas de administración' );
$GLOBALS['cn_caps'] = $CAP;
foreach ( array( 'pagina_socias', 'pagina_config', 'pagina_cruce_mp', 'pagina_cursos', 'pagina_contenido', 'pagina_videos' ) as $m ) {
	ob_start();
	CN_Admin::$m();
	$salida = ob_get_clean();
	t( "CN_Admin::$m no muestra nada", '' === $salida );
}

seccion( 'sin permisos no escribe nada' );
list( $r, $w ) = cb_run( array( 'read' ), array( 'cn_colab_video' => '1', 'cn_titulo' => 'x', 'cn_link' => 'https://youtu.be/abc' ) );
t( 'usuario sin permiso: error y cero escrituras', 'error' === $r['tipo'] && ! $w->log );
list( $r, $w ) = cb_run( $CAP, array() );
t( 'sin formulario enviado: no hace nada', null === $r && ! $w->log );

seccion( 'video de YouTube' );
list( $r, $w ) = cb_run( $CAP, array( 'cn_colab_video' => '1', 'cn_titulo' => ' Clase 1 ', 'cn_link' => 'https://youtu.be/abc123', 'cn_curso_id' => '5' ) );
$esc = $w->escrituras( 'cn_videos' );
t( 'link YouTube válido: inserta en videos con título limpio y curso', 'success' === $r['tipo'] && 1 === count( $esc ) && 'Clase 1' === $esc[0][2]['titulo'] && 5 === $esc[0][2]['curso_id'] );
list( $r, $w ) = cb_run( $CAP, array( 'cn_colab_video' => '1', 'cn_titulo' => 'x', 'cn_link' => 'https://youtu.be/abc', 'cn_curso_id' => '0' ) );
$esc = $w->escrituras( 'cn_videos' );
t( 'sin curso: curso_id queda null', 'success' === $r['tipo'] && null === $esc[0][2]['curso_id'] );
foreach ( array( 'https://evil.com/x', 'https://youtube.com.evil.com/x', 'javascript:alert(1)', 'https://drive.google.com/file/d/1/view', '' ) as $mal ) {
	list( $r, $w ) = cb_run( $CAP, array( 'cn_colab_video' => '1', 'cn_titulo' => 'x', 'cn_link' => $mal ) );
	t( 'video: rechaza ' . ( '' === $mal ? '(vacío)' : $mal ), 'error' === $r['tipo'] && ! $w->escrituras( 'cn_videos' ) );
}
list( $r, $w ) = cb_run( $CAP, array( 'cn_colab_video' => '1', 'cn_titulo' => '', 'cn_link' => 'https://youtu.be/abc' ) );
t( 'video: rechaza título vacío', 'error' === $r['tipo'] && ! $w->log );
list( $r, $w ) = cb_run( $CAP, array( 'cn_colab_video' => '1', 'cn_titulo' => 'x', 'cn_link' => 'https://youtu.be/abc', 'cn_curso_id' => '77' ) );
t( 'video: rechaza curso inexistente', 'error' === $r['tipo'] && ! $w->log );

seccion( 'material de un curso (video / pdf / audio)' );
list( $r, $w ) = cb_run( $CAP, array( 'cn_colab_contenido' => '1', 'cn_curso_id' => '5', 'cn_tipo' => 'pdf', 'cn_titulo' => 'Guía', 'cn_link' => 'https://drive.google.com/file/d/1abc/view' ) );
$esc = $w->escrituras( 'cn_contenidos' );
t( 'PDF de Drive: inserta con fuente manual', 'success' === $r['tipo'] && 1 === count( $esc ) && 'manual' === $esc[0][2]['fuente'] && 'pdf' === $esc[0][2]['tipo'] && 5 === $esc[0][2]['curso_id'] );
list( $r, $w ) = cb_run( $CAP, array( 'cn_colab_contenido' => '1', 'cn_curso_id' => '5', 'cn_tipo' => 'video', 'cn_titulo' => 'V', 'cn_link' => 'https://www.youtube.com/watch?v=abc' ) );
t( 'video de YouTube: ok', 'success' === $r['tipo'] );
list( $r, $w ) = cb_run( $CAP, array( 'cn_colab_contenido' => '1', 'cn_curso_id' => '5', 'cn_tipo' => 'exe', 'cn_titulo' => 'V', 'cn_link' => 'https://youtu.be/a' ) );
t( 'tipo inválido: rechaza', 'error' === $r['tipo'] && ! $w->log );
list( $r, $w ) = cb_run( $CAP, array( 'cn_colab_contenido' => '1', 'cn_curso_id' => '77', 'cn_tipo' => 'pdf', 'cn_titulo' => 'V', 'cn_link' => 'https://drive.google.com/x' ) );
t( 'curso inexistente: rechaza', 'error' === $r['tipo'] && ! $w->log );
list( $r, $w ) = cb_run( $CAP, array( 'cn_colab_contenido' => '1', 'cn_curso_id' => '5', 'cn_tipo' => 'pdf', 'cn_titulo' => 'V', 'cn_link' => 'https://mega.nz/file/x' ) );
t( 'link de otro sitio: rechaza', 'error' === $r['tipo'] && ! $w->log );

seccion( 'curso nuevo' );
list( $r, $w ) = cb_run( $CAP, array( 'cn_colab_curso' => '1', 'cn_nombre' => 'Orquídea', 'cn_link' => 'https://drive.google.com/drive/folders/1AbC' ) );
$esc = $w->escrituras( 'cn_cursos' );
t( 'carpeta de Drive válida: crea el curso', 'success' === $r['tipo'] && 1 === count( $esc ) && 'Orquídea' === $esc[0][2]['nombre'] );
list( $r, $w ) = cb_run( $CAP, array( 'cn_colab_curso' => '1', 'cn_nombre' => 'Orquídea', 'cn_link' => 'https://youtu.be/a' ) );
t( 'curso con link que no es Drive: rechaza', 'error' === $r['tipo'] && ! $w->log );

seccion( 'sincronizar con Drive' );
list( $r, $w ) = cb_run( $CAP, array( 'cn_colab_sync' => '1', 'cn_curso_id' => '77' ) );
t( 'curso inexistente: rechaza sin llamar a Drive', 'error' === $r['tipo'] && ! $GLOBALS['cn_http_log'] );

unset( $GLOBALS['cn_caps'] ); // el resto de los tests asume permisos de administrador (stub por defecto).
