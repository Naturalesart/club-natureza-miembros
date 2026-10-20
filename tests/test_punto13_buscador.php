<?php
// Punto 13: buscador del listado de socias (CN_Admin::ids_busqueda), solo lectura.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
function bs_run( $q ) {
	cn_test_reset();
	global $wpdb;
	$wpdb->on( '/SELECT id FROM/', array( '219', '5' ) );
	$ids = CN_Admin::ids_busqueda( $q );
	return array( $ids, $wpdb->consultas ? $wpdb->consultas[0] : '', $wpdb );
}
seccion( 'buscador: SQL por tipo de búsqueda' );
list( $ids, $sql, $w ) = bs_run( 'María Pérez' );
t( 'nombre con tilde: LIKE %q% sobre nombre/email/preapproval, sin id ni celular', false !== strpos( $sql, "nombre_apellido LIKE '%María Pérez%'" ) && false !== strpos( $sql, "email LIKE '%María Pérez%'" ) && false === strpos( $sql, 'id = ' ) && false === strpos( $sql, 'celular_' ), $sql );
t( 'devuelve ids como enteros y no escribe nada', array( 219, 5 ) === $ids && ! $w->log );
list( $ids, $sql ) = bs_run( 'ana@gmail.com' );
t( 'email: LIKE en email', false !== strpos( $sql, "email LIKE '%ana@gmail.com%'" ), $sql );
list( $ids, $sql ) = bs_run( '#219' );
t( '#219: id = 219 y no busca celular', false !== strpos( $sql, 'id = 219' ) && false === strpos( $sql, 'celular_' ), $sql );
list( $ids, $sql ) = bs_run( '219' );
t( '219: id = 219, 3 dígitos no buscan celular', false !== strpos( $sql, 'id = 219' ) && false === strpos( $sql, 'celular_' ), $sql );
list( $ids, $sql ) = bs_run( '1234' );
t( '4 dígitos: celular_hint = 1234 y texto_plano LIKE', false !== strpos( $sql, "celular_hint = '1234'" ) && false !== strpos( $sql, "celular_texto_plano LIKE '%1234%'" ), $sql );
list( $ids, $sql ) = bs_run( 'APR-1234abcd' );
t( 'preapproval parcial (8 chars): LIKE %q%', false !== strpos( $sql, "preapproval_id LIKE '%APR-1234abcd%'" ), $sql );
list( $ids, $sql ) = bs_run( '+54 9 11 5555-1234' );
t( 'celular largo: busca normalizado (1155551234) y crudo, hint solo si no hay texto plano', false !== strpos( $sql, "celular_texto_plano LIKE '%1155551234%'" ) && false !== strpos( $sql, "celular_texto_plano LIKE '%5491155551234%'" ) && false !== strpos( $sql, "celular_hint = '1234' AND (celular_texto_plano IS NULL" ), $sql );
list( $ids, $sql ) = bs_run( '011 15 5555 1234' );
t( 'celular local con 0 y 15: normaliza igual que el alta', false !== strpos( $sql, "celular_texto_plano LIKE '%1155551234%'" ), $sql );
list( $ids, $sql ) = bs_run( "50%_x" );
t( 'comodines escapados con esc_like', false !== strpos( $sql, '50' . chr( 92 ) ) && false === strpos( $sql, "LIKE '%50%_x%'" ), $sql );
t( 'no toca el hash', false === strpos( $sql, 'celular_hash' ) );
list( $ids ) = bs_run( '   ' );
t( 'q vacío: sin consulta', array() === $ids );
