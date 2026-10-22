<?php
// Punto 12: panel de contenido para el celular ([cn_panel_contenido], CN_Panel_Contenido).
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
$CAP = array( CN_Colaboradora::CAP );
$EVIL = 'https://evil.com/x';

/** Corre ejecutar_post() con $caps y $_POST; $filas = array( regex => respuesta ) para el $wpdb falso. */
function pn_post( $caps, $post, $filas = array( '/FROM wp_cn_cursos WHERE id = 5\b/' => 1 ), $logueada = true ) {
	cn_test_reset();
	$GLOBALS['cn_caps']     = $caps;
	$GLOBALS['cn_logged']   = $logueada;
	$GLOBALS['cn_nonce_ok'] = true;
	global $wpdb;
	foreach ( $filas as $re => $resp ) {
		$wpdb->on( $re, $resp );
	}
	$_POST = $post;
	$r     = CN_Panel_Contenido::ejecutar_post();
	return array( $r, $wpdb );
}
/** Renderiza el shortcode con $caps y $_GET. */
function pn_ver( $caps, $get = array(), $filas = array(), $logueada = true ) {
	cn_test_reset();
	$GLOBALS['cn_caps']   = $caps;
	$GLOBALS['cn_logged'] = $logueada;
	global $wpdb;
	foreach ( $filas as $re => $resp ) {
		$wpdb->on( $re, $resp );
	}
	$_GET  = $get;
	$_POST = array();
	return array( CN_Panel_Contenido::shortcode(), $wpdb );
}
function pn_editar_post( $tabla, $id, $campos ) {
	return array_merge( array( 'cn_panel' => 'editar', 'cn_vista' => 'lista', 'cn_tabla' => $tabla, 'cn_id' => (string) $id ), $campos );
}
$FILA_MANUAL = cn_fila( array( 'id' => 7, 'curso_id' => 5, 'tipo' => 'pdf', 'titulo' => 'Guía vieja', 'link' => 'https://drive.google.com/file/d/1/view', 'duracion' => '', 'fuente' => 'manual', 'orden' => 2 ) );
$FILA_DRIVE  = cn_fila( array( 'id' => 8, 'curso_id' => 5, 'tipo' => 'video', 'titulo' => 'Clase 1', 'link' => 'https://drive.google.com/file/d/9/view', 'duracion' => '', 'fuente' => 'drive_auto', 'orden' => 0 ) );
$FILA_VIDEO  = cn_fila( array( 'id' => 3, 'titulo' => 'Video viejo', 'link_youtube' => 'https://youtu.be/viejo', 'curso_id' => 5, 'orden' => 1 ) );
$H_MANUAL    = array( '/FROM wp_cn_contenidos WHERE id = 7\b/' => $FILA_MANUAL, '/FROM wp_cn_cursos WHERE id = 5\b/' => 1 );
$H_DRIVE     = array( '/FROM wp_cn_contenidos WHERE id = 8\b/' => $FILA_DRIVE, '/FROM wp_cn_cursos WHERE id = 5\b/' => 1 );
$H_VIDEO     = array( '/FROM wp_cn_videos WHERE id = 3\b/' => $FILA_VIDEO, '/FROM wp_cn_cursos WHERE id = 5\b/' => 1 );

seccion( 'sin sesión: solo el login, cero datos del Club' );
list( $html, $w ) = pn_ver( $CAP, array(), array( '/^SELECT id, nombre, link_drive FROM wp_cn_cursos/' => array( cn_fila( array( 'id' => 5, 'nombre' => 'Peonía', 'link_drive' => 'https://drive.google.com/drive/folders/1' ) ) ) ), false );
t( 'muestra el formulario de login', false !== strpos( $html, 'id="loginform"' ) );
t( 'vuelve al panel después de entrar', false !== strpos( $html, 'data-redirect="https://naturalesart.com/panel-contenido/"' ) );
t( '"recordarme" viene tildado', false !== strpos( $html, 'data-remember="1"' ) );
t( 'tiene el link "¿Olvidaste tu contraseña?"', false !== strpos( $html, '¿Olvidaste tu contraseña?' ) && false !== strpos( $html, 'lostpassword' ) );
t( 'no consulta la base de datos', ! $w->consultas && ! $w->log );
t( 'no muestra cursos ni opciones del panel', false === strpos( $html, 'Peonía' ) && false === strpos( $html, 'Subir un video' ) );
$GLOBALS['cn_logged'] = true;

seccion( 'con sesión pero sin permiso: aviso, sin datos y sin escrituras' );
list( $html, $w ) = pn_ver( array( 'read' ), array( 'v' => 'lista' ) );
t( 'muestra "Tu usuario no tiene acceso a este panel"', false !== strpos( $html, 'Tu usuario no tiene acceso a este panel' ) );
t( 'ofrece cerrar sesión', false !== strpos( $html, 'action=logout' ) && false !== strpos( $html, 'Cerrar sesión' ) );
t( 'no consulta la base de datos', ! $w->consultas && ! $w->log );
list( $r, $w ) = pn_post( array( 'read' ), array( 'cn_panel' => 'colab', 'cn_vista' => 'link', 'cn_colab_link' => '1', 'cn_tipo_sel' => 'yt', 'cn_titulo' => 'x', 'cn_link' => 'https://youtu.be/abc' ) );
t( 'POST sin permiso: error y cero escrituras ni consultas', 'error' === $r['res']['tipo'] && ! $w->log && ! $w->consultas );
list( $r, $w ) = pn_post( array( 'read' ), pn_editar_post( 'contenido', 7, array( 'cn_titulo' => 'x', 'cn_link' => 'https://youtu.be/a', 'cn_tipo' => 'video', 'cn_curso_id' => '5', 'cn_orden' => '1' ) ), $H_MANUAL );
t( 'edición sin permiso: no escribe', 'error' === $r['res']['tipo'] && ! $w->log );
list( $r, $w ) = pn_post( $CAP, array( 'cn_panel' => 'colab', 'cn_colab_link' => '1', 'cn_tipo_sel' => 'yt', 'cn_titulo' => 'x', 'cn_link' => 'https://youtu.be/abc' ), array(), false );
t( 'sin sesión (aunque el stub diga que tiene permisos): no escribe', ! $w->log );

seccion( 'con permiso: el panel (inicio)' );
foreach ( array( $CAP, array( 'manage_options' ) ) as $caps ) {
	list( $html ) = pn_ver( $caps );
	t( 'inicio con las 3 tarjetas', false !== strpos( $html, 'Subir un video grabado con mi celu' ) && false !== strpos( $html, 'Pegar un link (YouTube, Drive, PDF, audio)' ) && false !== strpos( $html, 'Ver y editar lo cargado' ) );
}
t( 'inputs de 16px y botones de 48px', false !== strpos( $html, 'font-size:16px' ) && false !== strpos( $html, 'min-height:48px' ) );
list( $html ) = pn_ver( $CAP, array( 'v' => 'inexistente' ) );
t( 'una vista desconocida cae en el inicio', false !== strpos( $html, '¿Qué querés hacer?' ) );

seccion( 'nonce inválido: no escribe' );
$GLOBALS['cn_nonce_ok'] = false;
cn_test_reset();
$GLOBALS['cn_nonce_ok'] = false;
$GLOBALS['cn_caps']     = $CAP;
$wpdb->on( '/FROM wp_cn_cursos WHERE id = 5\b/', 1 );
$_POST = array( 'cn_panel' => 'colab', 'cn_vista' => 'link', 'cn_colab_link' => '1', 'cn_tipo_sel' => 'yt', 'cn_titulo' => 'x', 'cn_link' => 'https://youtu.be/abc' );
$r = CN_Panel_Contenido::ejecutar_post();
t( 'alta con nonce inválido: error y cero escrituras', 'error' === $r['res']['tipo'] && ! $wpdb->log );
cn_test_reset();
$GLOBALS['cn_nonce_ok'] = false;
$GLOBALS['cn_caps']     = $CAP;
$wpdb->on( '/FROM wp_cn_contenidos WHERE id = 7\b/', $FILA_MANUAL );
$_POST = pn_editar_post( 'contenido', 7, array( 'cn_titulo' => 'x', 'cn_link' => 'https://youtu.be/a', 'cn_tipo' => 'video', 'cn_curso_id' => '5', 'cn_orden' => '1' ) );
$r = CN_Panel_Contenido::ejecutar_post();
t( 'edición con nonce inválido: error, cero escrituras y ni siquiera lee la fila', 'error' === $r['res']['tipo'] && ! $wpdb->log && ! $wpdb->consultas );
$GLOBALS['cn_nonce_ok'] = true;

seccion( 'pegar un link: mismas validaciones que la colaboradora' );
list( $r, $w ) = pn_post( $CAP, array( 'cn_panel' => 'colab', 'cn_vista' => 'link', 'cn_colab_link' => '1', 'cn_tipo_sel' => 'yt', 'cn_titulo' => ' Clase ', 'cn_link' => 'https://youtu.be/abc123', 'cn_curso_id' => '0' ) );
$esc = $w->escrituras( 'cn_videos' );
t( 'YouTube suelto: inserta en videos', 'success' === $r['res']['tipo'] && 1 === count( $esc ) && 'Clase' === $esc[0][2]['titulo'] && null === $esc[0][2]['curso_id'] );
t( 'vuelve a la misma vista (post-redirect-get)', 'link' === $r['vista'] );
list( $r, $w ) = pn_post( $CAP, array( 'cn_panel' => 'colab', 'cn_vista' => 'link', 'cn_colab_link' => '1', 'cn_tipo_sel' => 'pdf', 'cn_titulo' => 'Guía', 'cn_link' => 'https://drive.google.com/file/d/1abc/view', 'cn_curso_id' => '5' ) );
$esc = $w->escrituras( 'cn_contenidos' );
t( 'PDF de Drive con curso: inserta en contenidos como manual', 'success' === $r['res']['tipo'] && 1 === count( $esc ) && 'pdf' === $esc[0][2]['tipo'] && 'manual' === $esc[0][2]['fuente'] );
foreach ( array( $EVIL, 'https://youtube.com.evil.com/x', 'javascript:alert(1)', 'https://drive.google.com/file/d/1/view' ) as $mal ) {
	list( $r, $w ) = pn_post( $CAP, array( 'cn_panel' => 'colab', 'cn_colab_link' => '1', 'cn_tipo_sel' => 'yt', 'cn_titulo' => 'x', 'cn_link' => $mal ) );
	t( 'YouTube: rechaza ' . $mal, 'error' === $r['res']['tipo'] && ! $w->log );
}
list( $r, $w ) = pn_post( $CAP, array( 'cn_panel' => 'colab', 'cn_colab_link' => '1', 'cn_tipo_sel' => 'pdf', 'cn_titulo' => 'x', 'cn_link' => 'https://mega.nz/file/x', 'cn_curso_id' => '5' ) );
t( 'PDF: rechaza otro sitio', 'error' === $r['res']['tipo'] && ! $w->log );
list( $r, $w ) = pn_post( $CAP, array( 'cn_panel' => 'colab', 'cn_colab_link' => '1', 'cn_tipo_sel' => 'exe', 'cn_titulo' => 'x', 'cn_link' => 'https://youtu.be/a', 'cn_curso_id' => '5' ) );
t( 'tipo inventado: rechaza', 'error' === $r['res']['tipo'] && ! $w->log );
list( $r, $w ) = pn_post( $CAP, array( 'cn_panel' => 'colab', 'cn_colab_link' => '1', 'cn_tipo_sel' => 'pdf', 'cn_titulo' => 'x', 'cn_link' => 'https://drive.google.com/x', 'cn_curso_id' => '77' ) );
t( 'curso inexistente: rechaza', 'error' === $r['res']['tipo'] && ! $w->log );

seccion( 'crear curso y traer desde Drive' );
list( $r, $w ) = pn_post( $CAP, array( 'cn_panel' => 'colab', 'cn_vista' => 'curso', 'cn_colab_curso' => '1', 'cn_nombre' => 'Orquídea', 'cn_link' => 'https://drive.google.com/drive/folders/1AbC' ) );
t( 'curso con carpeta de Drive: lo crea', 'success' === $r['res']['tipo'] && 1 === count( $w->escrituras( 'cn_cursos' ) ) );
list( $r, $w ) = pn_post( $CAP, array( 'cn_panel' => 'colab', 'cn_vista' => 'curso', 'cn_colab_curso' => '1', 'cn_nombre' => 'Orquídea', 'cn_link' => 'https://youtu.be/x' ) );
t( 'curso con link que no es de Drive: rechaza', 'error' === $r['res']['tipo'] && ! $w->log );
list( $r, $w ) = pn_post( $CAP, array( 'cn_panel' => 'colab', 'cn_vista' => 'video', 'cn_volver_curso' => '77', 'cn_colab_sync' => '1', 'cn_curso_id' => '77' ) );
t( 'traer de Drive con curso inexistente: rechaza sin llamar a Drive', 'error' === $r['res']['tipo'] && ! $GLOBALS['cn_http_log'] && 'video' === $r['vista'] );
list( $r, $w ) = pn_post( $CAP, array( 'cn_panel' => 'colab', 'cn_vista' => 'hack<script>', 'cn_colab_curso' => '1', 'cn_nombre' => 'A', 'cn_link' => 'https://youtu.be/x' ) );
t( 'vista de retorno desconocida: vuelve al inicio', 'inicio' === $r['vista'] );
list( $r, $w ) = pn_post( $CAP, array( 'cn_panel' => 'otra', 'cn_titulo' => 'x' ) );
t( 'acción desconocida: no escribe', 'error' === $r['res']['tipo'] && ! $w->log );

seccion( 'pantalla "subir video del celu"' );
$cursos = array( '/^SELECT id, nombre, link_drive FROM wp_cn_cursos/' => array(
	cn_fila( array( 'id' => 5, 'nombre' => 'Peonía', 'link_drive' => 'https://drive.google.com/drive/folders/1AbC' ) ),
	cn_fila( array( 'id' => 6, 'nombre' => 'Raro', 'link_drive' => 'https://evil.com/x' ) ),
) );
list( $html ) = pn_ver( $CAP, array( 'v' => 'video' ), $cursos );
t( 'primero se elige el curso', false !== strpos( $html, 'Peonía' ) && false === strpos( $html, 'Abrir carpeta de Drive' ) );
list( $html ) = pn_ver( $CAP, array( 'v' => 'video', 'curso' => '5' ), $cursos );
t( 'con curso: botón a la carpeta de Drive, 3 pasos y botón de traerlo', false !== strpos( $html, 'href="https://drive.google.com/drive/folders/1AbC"' ) && false !== strpos( $html, 'Abrir carpeta de Drive' ) && 3 === substr_count( $html, '<li>' ) && false !== strpos( $html, 'Ya lo subí: traerlo a la web' ) && false !== strpos( $html, 'name="cn_colab_sync"' ) );
list( $html ) = pn_ver( $CAP, array( 'v' => 'video', 'curso' => '6' ), $cursos );
t( 'curso con carpeta que no es de Drive: no se ofrece el link', false === strpos( $html, 'evil.com' ) && false !== strpos( $html, 'no tiene una carpeta de Drive válida' ) );
t( 'los formularios de alta traen nonce', false !== strpos( $html, 'name="cn_colab_nonce"' ) );

seccion( 'editar contenido manual' );
list( $r, $w ) = pn_post( $CAP, pn_editar_post( 'contenido', 7, array( 'cn_titulo' => ' Guía nueva ', 'cn_link' => 'https://drive.google.com/file/d/2/view', 'cn_tipo' => 'audio', 'cn_curso_id' => '5', 'cn_orden' => '4', 'cn_duracion' => '10:30' ) ), $H_MANUAL );
$upd = $w->escrituras( 'cn_contenidos' );
$bak = $w->escrituras( 'mp_log' );
t( 'edición válida: guarda y avisa', 'success' === $r['res']['tipo'] && 'lista' === $r['vista'] );
t( 'actualiza solo la fila pedida con los valores limpios', 1 === count( $upd ) && 'update' === $upd[0][0] && array( 'id' => 7 ) === $upd[0][3] && 'Guía nueva' === $upd[0][2]['titulo'] && 'audio' === $upd[0][2]['tipo'] && 4 === $upd[0][2]['orden'] && '10:30' === $upd[0][2]['duracion'] );
t( 'guarda un backup contenido_backup ANTES de escribir', 1 === count( $bak ) && 'contenido_backup' === $bak[0][2]['tipo'] && $w->log[0] === $bak[0] );
t( 'el backup trae los valores anteriores', false !== strpos( $bak[0][2]['payload'], 'vieja' ) && false !== strpos( $bak[0][2]['payload'], 'fila_previa' ) );
list( $r, $w ) = pn_post( $CAP, pn_editar_post( 'contenido', 7, array( 'cn_titulo' => 'Guía vieja', 'cn_link' => 'https://drive.google.com/file/d/1/view', 'cn_tipo' => 'pdf', 'cn_curso_id' => '5', 'cn_orden' => '2', 'cn_duracion' => '' ) ), $H_MANUAL );
t( 'sin cambios: no escribe nada (ni backup)', 'success' === $r['res']['tipo'] && ! $w->log );
$base = array( 'cn_titulo' => 'T', 'cn_link' => 'https://youtu.be/ok', 'cn_tipo' => 'video', 'cn_curso_id' => '5', 'cn_orden' => '1' );
foreach ( array(
	'link de otro sitio'     => array( 'cn_link' => $EVIL ),
	'link javascript:'       => array( 'cn_link' => 'javascript:alert(1)' ),
	'link con host parecido' => array( 'cn_link' => 'https://youtube.com.evil.com/x' ),
	'título vacío'           => array( 'cn_titulo' => '   ' ),
	'tipo inválido'          => array( 'cn_tipo' => 'exe' ),
	'curso inexistente'      => array( 'cn_curso_id' => '77' ),
	'curso vacío'            => array( 'cn_curso_id' => '0' ),
	'orden no numérico'      => array( 'cn_orden' => 'abc' ),
	'orden negativo'         => array( 'cn_orden' => '-3' ),
	'orden enorme'           => array( 'cn_orden' => '99999' ),
	'link larguísimo'        => array( 'cn_link' => 'https://youtu.be/' . str_repeat( 'a', 600 ) ),
) as $caso => $pisa ) {
	list( $r, $w ) = pn_post( $CAP, pn_editar_post( 'contenido', 7, array_merge( $base, $pisa ) ), $H_MANUAL );
	t( "edición inválida ($caso): error y cero escrituras", 'error' === $r['res']['tipo'] && ! $w->log );
}
list( $r, $w ) = pn_post( $CAP, pn_editar_post( 'contenido', 99, $base ), $H_MANUAL );
t( 'fila inexistente: error y cero escrituras', 'error' === $r['res']['tipo'] && ! $w->log );
list( $r, $w ) = pn_post( $CAP, pn_editar_post( 'usuarios', 7, $base ), $H_MANUAL );
t( 'tabla inventada: error y cero escrituras', 'error' === $r['res']['tipo'] && ! $w->log );
$largo = str_repeat( 'a', 300 );
list( $r, $w ) = pn_post( $CAP, pn_editar_post( 'contenido', 7, array_merge( $base, array( 'cn_titulo' => $largo, 'cn_duracion' => str_repeat( '9', 50 ) ) ) ), $H_MANUAL );
$upd = $w->escrituras( 'cn_contenidos' );
t( 'topes de largo: título 191 y duración 20', 1 === count( $upd ) && 191 === strlen( $upd[0][2]['titulo'] ) && 20 === strlen( $upd[0][2]['duracion'] ) );

seccion( 'editar video suelto de YouTube' );
list( $r, $w ) = pn_post( $CAP, pn_editar_post( 'video', 3, array( 'cn_titulo' => 'Video nuevo', 'cn_link' => 'https://www.youtube.com/watch?v=zzz', 'cn_curso_id' => '0', 'cn_orden' => '5' ) ), $H_VIDEO );
$upd = $w->escrituras( 'cn_videos' );
t( 'video: guarda título, link, sin curso (null) y orden, con backup', 'success' === $r['res']['tipo'] && 1 === count( $upd ) && 'Video nuevo' === $upd[0][2]['titulo'] && array_key_exists( 'curso_id', $upd[0][2] ) && null === $upd[0][2]['curso_id'] && 5 === $upd[0][2]['orden'] && 1 === count( $w->escrituras( 'mp_log' ) ) );
list( $r, $w ) = pn_post( $CAP, pn_editar_post( 'video', 3, array( 'cn_titulo' => 'V', 'cn_link' => 'https://drive.google.com/file/d/1/view', 'cn_curso_id' => '5', 'cn_orden' => '1' ) ), $H_VIDEO );
t( 'video: un link de Drive no vale (solo YouTube)', 'error' === $r['res']['tipo'] && ! $w->log );

seccion( 'ítems de Drive (drive_auto): la sincronización pisa título, link, tipo y curso' );
list( $r, $w ) = pn_post( $CAP, pn_editar_post( 'contenido', 8, array( 'cn_titulo' => 'Hackeado', 'cn_link' => $EVIL, 'cn_tipo' => 'pdf', 'cn_curso_id' => '9', 'cn_orden' => '3', 'cn_duracion' => '15:00' ) ), $H_DRIVE );
$upd = $w->escrituras( 'cn_contenidos' );
t( 'solo cambia orden y duración, aunque el POST traiga otros campos', 'success' === $r['res']['tipo'] && 1 === count( $upd ) && array( 'orden', 'duracion' ) === array_keys( $upd[0][2] ) && 3 === $upd[0][2]['orden'] );
t( 'tampoco se cuela un link ajeno ni un título nuevo', false === strpos( json_encode( $upd ), 'Hackeado' ) && false === strpos( json_encode( $upd ), 'evil.com' ) );
t( 'guarda backup', 1 === count( $w->escrituras( 'mp_log' ) ) );
list( $html ) = pn_ver( $CAP, array( 'v' => 'editar', 'que' => 'contenido', 'id' => '8' ), $H_DRIVE );
t( 'la pantalla avisa que viene de Drive y no ofrece editar título/link/tipo/curso', false !== strpos( $html, 'viene de Drive' ) && false === strpos( $html, 'name="cn_titulo"' ) && false === strpos( $html, 'name="cn_link"' ) && false === strpos( $html, 'name="cn_tipo"' ) && false === strpos( $html, 'name="cn_curso_id"' ) );
t( 'sí ofrece orden y duración', false !== strpos( $html, 'name="cn_orden"' ) && false !== strpos( $html, 'name="cn_duracion"' ) );
list( $html ) = pn_ver( $CAP, array( 'v' => 'editar', 'que' => 'contenido', 'id' => '7' ), $H_MANUAL );
t( 'un ítem manual sí se edita completo, con nonce', false !== strpos( $html, 'name="cn_titulo"' ) && false !== strpos( $html, 'name="cn_link"' ) && false !== strpos( $html, 'name="cn_panel_nonce"' ) && false === strpos( $html, 'viene de Drive' ) );

seccion( 'listado: paginado, más nuevo primero, filtro por curso' );
$filas20 = array();
for ( $i = 0; $i < 20; $i++ ) {
	$filas20[] = cn_fila( array( 'id' => 100 - $i, 'titulo' => 'Item ' . $i, 'tipo' => 'pdf', 'fuente' => $i ? 'manual' : 'drive_auto', 'orden' => $i, 'curso_nombre' => 'Peonía' ) );
}
$listado = array( '/SELECT COUNT\(\*\) FROM wp_cn_contenidos x/' => 45, '/^SELECT x\.id, x\.titulo, x\.tipo/' => $filas20, '/^SELECT id, nombre, link_drive FROM wp_cn_cursos/' => array( cn_fila( array( 'id' => 5, 'nombre' => 'Peonía', 'link_drive' => '' ) ) ) );
list( $html, $w ) = pn_ver( $CAP, array( 'v' => 'lista' ), $listado );
$q = implode( "\n", $w->consultas );
t( 'ordena por id descendente, 20 por página, desde 0', false !== strpos( $q, 'ORDER BY x.id DESC LIMIT 20 OFFSET 0' ) );
t( 'indica la página y que hay más', false !== strpos( $html, 'Página 1 de 3' ) && false !== strpos( $html, 'Siguientes' ) && false === strpos( $html, 'Anteriores' ) );
t( 'cada fila tiene su botón Editar', 20 === substr_count( $html, '>Editar</a>' ) && false !== strpos( $html, 'de Drive' ) );
list( $html, $w ) = pn_ver( $CAP, array( 'v' => 'lista', 'pag' => '2', 'filtro' => '5' ), $listado );
$q = implode( "\n", $w->consultas );
t( 'página 2: OFFSET 20 y filtro de curso en el WHERE', false !== strpos( $q, 'LIMIT 20 OFFSET 20' ) && false !== strpos( $q, 'x.curso_id = 5' ) && false !== strpos( $html, 'Anteriores' ) );
list( $html, $w ) = pn_ver( $CAP, array( 'v' => 'lista', 'pag' => '-4; DROP TABLE x', 'filtro' => "5 OR 1=1" ), $listado );
$q = implode( "\n", $w->consultas );
t( 'parámetros maliciosos del GET no llegan al SQL', false === stripos( $q, 'DROP' ) && false === strpos( $q, '1=1' ) );
list( $html, $w ) = pn_ver( $CAP, array( 'v' => 'lista', 'que' => 'videos' ), array( '/SELECT COUNT\(\*\) FROM wp_cn_videos x/' => 3, '/^SELECT x\.id, x\.titulo, x\.link_youtube/' => array( cn_fila( array( 'id' => 3, 'titulo' => 'V', 'link' => 'https://youtu.be/a', 'orden' => 1, 'curso_nombre' => null ) ) ) ) );
t( 'también lista los videos de YouTube', false !== strpos( $html, 'FROM wp_cn_videos' ) || false !== strpos( implode( "\n", $w->consultas ), 'FROM wp_cn_videos x' ) );

seccion( 'escape de HTML en toda la salida' );
$xss = '<script>alert(1)</script>';
$fila_xss = cn_fila( array( 'id' => 7, 'curso_id' => 5, 'tipo' => 'pdf', 'titulo' => $xss, 'link' => 'https://drive.google.com/file/d/1/view?a="><script>x</script>', 'duracion' => '"><b>', 'fuente' => 'manual', 'orden' => 2 ) );
$cursos_xss = array( '/^SELECT id, nombre, link_drive FROM wp_cn_cursos/' => array( cn_fila( array( 'id' => 5, 'nombre' => $xss, 'link_drive' => 'https://drive.google.com/drive/folders/1' ) ) ) );
list( $html ) = pn_ver( $CAP, array( 'v' => 'lista' ), array_merge( $cursos_xss, array( '/SELECT COUNT\(\*\) FROM wp_cn_contenidos x/' => 1, '/^SELECT x\.id, x\.titulo, x\.tipo/' => array( cn_fila( array( 'id' => 7, 'titulo' => $xss, 'tipo' => 'pdf', 'fuente' => 'manual', 'orden' => 1, 'curso_nombre' => $xss ) ) ) ) ) );
t( 'listado: el título y el curso salen escapados', false === strpos( $html, '<script>alert' ) && false !== strpos( $html, '&lt;script&gt;alert(1)&lt;/script&gt;' ) );
list( $html ) = pn_ver( $CAP, array( 'v' => 'editar', 'que' => 'contenido', 'id' => '7' ), array_merge( $cursos_xss, array( '/FROM wp_cn_contenidos WHERE id = 7\b/' => $fila_xss ) ) );
t( 'edición: título, link y duración van escapados en los value=""', false === strpos( $html, '<script>' ) && false === strpos( $html, '"><b>' ) && false !== strpos( $html, '&lt;script&gt;' ) );
list( $html ) = pn_ver( $CAP, array( 'v' => 'video', 'curso' => '5' ), $cursos_xss );
t( 'subir video: el nombre del curso sale escapado', false === strpos( $html, '<script>' ) );
list( $html ) = pn_ver( $CAP, array( 'v' => 'link' ), $cursos_xss );
t( 'pegar link: las opciones de curso salen escapadas', false === strpos( $html, '<script>' ) );
list( $r, $w ) = pn_post( $CAP, array( 'cn_panel' => 'colab', 'cn_colab_link' => '1', 'cn_tipo_sel' => 'yt', 'cn_titulo' => 'Hola <script>alert(1)</script>', 'cn_link' => 'https://youtu.be/a' ) );
$esc = $w->escrituras( 'cn_videos' );
t( 'un título con <script> se guarda sin etiquetas', 1 === count( $esc ) && false === strpos( $esc[0][2]['titulo'], '<' ) );

seccion( 'no hay forma de borrar ni de ocultar' );
$fuente = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-panel-contenido.php' );
t( 'el código no borra (sin delete / DELETE / TRUNCATE / DROP / query directa)', ! preg_match( '/->delete\s*\(|\bDELETE\b|TRUNCATE|DROP\s+TABLE|->query\s*\(/i', $fuente ) );
$todo = '';
foreach ( array( array(), array( 'v' => 'video', 'curso' => '5' ), array( 'v' => 'link' ), array( 'v' => 'curso' ), array( 'v' => 'lista' ) ) as $get ) {
	list( $h ) = pn_ver( $CAP, $get, $cursos );
	$todo     .= $h;
}
list( $h ) = pn_ver( $CAP, array( 'v' => 'editar', 'que' => 'contenido', 'id' => '7' ), $H_MANUAL );
$todo .= $h;
t( 'ninguna pantalla ofrece borrar, eliminar ni ocultar', ! preg_match( '/borrar|eliminar|ocultar|quitar|papelera|trash/i', $todo ) );
list( $r, $w ) = pn_post( $CAP, pn_editar_post( 'contenido', 7, array_merge( $base, array( 'cn_borrar' => '1', 'cn_accion' => 'delete', 'cn_delete' => '1' ) ) ), $H_MANUAL );
t( 'un POST con pedidos de borrado no genera ningún delete', ! array_filter( $w->log, function ( $l ) {
	return 'delete' === $l[0] || ( 'q' === $l[0] ) || ( 'update' === $l[0] && isset( $l[2]['estado'] ) );
} ) );

seccion( 'ganchos: noindex, barra de admin, redirecciones' );
cn_test_reset();
$robots = CN_Panel_Contenido::filtrar_robots( array( 'max-image-preview' => 'large' ) );
t( 'wp_robots: noindex + nofollow', ! empty( $robots['noindex'] ) && ! empty( $robots['nofollow'] ) );
t( 'la página del panel se reconoce por el shortcode', CN_Panel_Contenido::es_pagina_panel() );
$GLOBALS['cn_post'] = (object) array( 'ID' => 9, 'post_content' => 'Hola [cn_login]' );
t( 'otra página no se confunde con el panel', ! CN_Panel_Contenido::es_pagina_panel() );
unset( $GLOBALS['cn_post'] );
$GLOBALS['cn_caps'] = $CAP;
t( 'la colaboradora no ve la barra de admin', false === CN_Panel_Contenido::filtrar_barra_admin( true ) );
$GLOBALS['cn_caps'] = array( 'manage_options', CN_Colaboradora::CAP );
t( 'la administradora conserva la barra', true === CN_Panel_Contenido::filtrar_barra_admin( true ) );
$GLOBALS['cn_caps'] = array( 'read' );
t( 'una socia/otro usuario conserva la barra', true === CN_Panel_Contenido::filtrar_barra_admin( true ) );
$u_col = (object) array( 'caps' => array( CN_Colaboradora::CAP, 'read' ) );
$u_adm = (object) array( 'caps' => array( 'manage_options', CN_Colaboradora::CAP ) );
$u_otr = (object) array( 'caps' => array( 'read' ) );
cn_test_reset();
$wpdb->on( '/post_content LIKE/', 50 );
t( 'login: la colaboradora va al panel cuando existe la página', 'https://naturalesart.com/panel-contenido/' === CN_Panel_Contenido::redirigir_login( 'X', '', $u_col ) );
t( 'login: administradora, otros y errores de login no cambian de destino', 'X' === CN_Panel_Contenido::redirigir_login( 'X', '', $u_adm ) && 'X' === CN_Panel_Contenido::redirigir_login( 'X', '', $u_otr ) && 'X' === CN_Panel_Contenido::redirigir_login( 'X', '', new WP_Error( 'e', 'm' ) ) );
$q = implode( "\n", $wpdb->consultas );
t( 'la página se busca por contenido (LIKE parametrizado), sin crear nada', false !== strpos( $q, "post_content LIKE '%[cn" ) && false !== strpos( $q, "post_type = 'page' AND post_status = 'publish'" ) && ! $wpdb->log );
cn_test_reset();
t( 'login: sin página con el shortcode no inventa destino', 'X' === CN_Panel_Contenido::redirigir_login( 'X', '', $u_col ) );
// sacar_de_wp_admin: los casos que NO redirigen (los que redirigen terminan con exit).
$GLOBALS['cn_caps']   = $CAP;
$GLOBALS['cn_redirect'] = null;
CN_Panel_Contenido::sacar_de_wp_admin();
t( 'wp-admin sin página del panel: no redirige (evita quedar sin salida)', null === $GLOBALS['cn_redirect'] );
$wpdb->on( '/post_content LIKE/', 50 );
$_SERVER['PHP_SELF'] = '/wp-admin/admin-ajax.php';
CN_Panel_Contenido::sacar_de_wp_admin();
t( 'admin-ajax.php no se redirige', null === $GLOBALS['cn_redirect'] );
$_SERVER['PHP_SELF'] = '/wp-admin/admin-post.php';
CN_Panel_Contenido::sacar_de_wp_admin();
t( 'admin-post.php no se redirige', null === $GLOBALS['cn_redirect'] );
$GLOBALS['cn_caps'] = array( 'manage_options' );
$_SERVER['PHP_SELF'] = '/wp-admin/index.php';
CN_Panel_Contenido::sacar_de_wp_admin();
t( 'una administradora entra a wp-admin sin problemas', null === $GLOBALS['cn_redirect'] );
unset( $_SERVER['PHP_SELF'] );
t( 'url_vista: vistas conocidas con parámetros, desconocidas al inicio', false !== strpos( CN_Panel_Contenido::url_vista( 'lista', array( 'que' => 'videos', 'pag' => 2 ) ), 'v=lista' ) && 'https://naturalesart.com/panel-contenido/' === CN_Panel_Contenido::url_vista( 'cualquiera' ) );

seccion( 'el panel no toca nada del cobro ni del área de socias' );
foreach ( array( 'CN_Webhook', 'CN_Mp', 'CN_Auth', 'CN_Vinculo', 'CN_Sync', 'miembros', 'preapproval', 'mercadopago' ) as $palabra ) {
	t( "el código del panel no menciona $palabra", false === stripos( $fuente, $palabra ) );
}

unset( $GLOBALS['cn_caps'], $GLOBALS['cn_post'] ); // el resto de los tests asume permisos de administrador (stub por defecto).
$GLOBALS['cn_logged']   = true;
$GLOBALS['cn_nonce_ok'] = true;
$_GET  = array();
$_POST = array();
