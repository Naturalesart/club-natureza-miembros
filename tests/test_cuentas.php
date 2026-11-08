<?php
// PASO B: CN_Cuentas (cuentas email + contraseña). Núcleo sin pantallas ni enganches.
// Base en memoria (CT_Db) que entiende SOLO las consultas de CN_Cuentas; el resto cae al $wpdb falso de siempre.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
foreach ( array( 'MINUTE_IN_SECONDS' => 60, 'COOKIEPATH' => '/', 'COOKIE_DOMAIN' => '' ) as $k => $v ) {
	if ( ! defined( $k ) ) {
		define( $k, $v );
	}
}

class CT_Db extends CN_Fake_Wpdb {
	public $t   = array( 'cuentas' => array(), 'cuenta_tokens' => array(), 'miembros' => array(), 'sesiones' => array() );
	public $ids = array( 'cuentas' => 0, 'cuenta_tokens' => 0, 'miembros' => 0, 'sesiones' => 0 );

	protected function nombre( $tabla ) {
		return 0 === strpos( $tabla, 'wp_cn_' ) ? substr( $tabla, 6 ) : $tabla;
	}
	protected function buscar( $tabla, $campo, $valor ) {
		foreach ( $this->t[ $tabla ] as $f ) {
			if ( (string) $f[ $campo ] === (string) $valor ) {
				return (object) $f;
			}
		}
		return null;
	}
	public function get_row( $sql ) {
		if ( preg_match( "/FROM wp_cn_miembros WHERE id = (\\d+)$/", $sql, $m ) ) {
			$this->consultas[] = $sql;
			return $this->buscar( 'miembros', 'id', $m[1] );
		}
		if ( preg_match( "/FROM wp_cn_cuentas WHERE (email|miembro_id|id) = '?([^']*)'?$/", $sql, $m ) ) {
			$this->consultas[] = $sql;
			return $this->buscar( 'cuentas', $m[1], stripslashes( $m[2] ) );
		}
		if ( preg_match( "/FROM wp_cn_cuenta_tokens WHERE token_hash = '([a-f0-9]+)'$/", $sql, $m ) ) {
			$this->consultas[] = $sql;
			return $this->buscar( 'cuenta_tokens', 'token_hash', $m[1] );
		}
		return parent::get_row( $sql );
	}
	public function get_col( $sql ) {
		if ( preg_match( "/FROM wp_cn_miembros WHERE LOWER\\(TRIM\\(email\\)\\) = '(.*)'$/", $sql, $m ) ) {
			$this->consultas[] = $sql;
			$out = array();
			foreach ( $this->t['miembros'] as $f ) {
				if ( strtolower( trim( (string) $f['email'] ) ) === stripslashes( $m[1] ) ) {
					$out[] = $f['id'];
				}
			}
			return $out;
		}
		return parent::get_col( $sql );
	}
	public function query( $sql ) {
		parent::query( $sql );
		$n = 0;
		if ( preg_match( '/^UPDATE wp_cn_cuenta_tokens SET usado = 1 WHERE id = (\d+) AND usado = 0$/', $sql, $m ) ) {
			foreach ( $this->t['cuenta_tokens'] as $i => $f ) {
				if ( (int) $f['id'] === (int) $m[1] && 0 === (int) $f['usado'] ) {
					$this->t['cuenta_tokens'][ $i ]['usado'] = 1;
					$n++;
				}
			}
		} elseif ( preg_match( "/^UPDATE wp_cn_cuenta_tokens SET usado = 1 WHERE cuenta_id = (\\d+) AND usado = 0(?: AND proposito = '(\\w+)')?$/", $sql, $m ) ) {
			foreach ( $this->t['cuenta_tokens'] as $i => $f ) {
				if ( (int) $f['cuenta_id'] === (int) $m[1] && 0 === (int) $f['usado'] && ( empty( $m[2] ) || $f['proposito'] === $m[2] ) ) {
					$this->t['cuenta_tokens'][ $i ]['usado'] = 1;
					$n++;
				}
			}
		} elseif ( preg_match( '/^UPDATE wp_cn_cuentas SET intentos = intentos \+ 1 WHERE id = (\d+)$/', $sql, $m ) ) {
			foreach ( $this->t['cuentas'] as $i => $f ) {
				if ( (int) $f['id'] === (int) $m[1] ) {
					$this->t['cuentas'][ $i ]['intentos']++;
					$n++;
				}
			}
		} elseif ( preg_match( "/^DELETE FROM wp_cn_sesiones WHERE miembro_id = (\\d+) AND token <> '(.*)'$/", $sql, $m ) ) {
			foreach ( $this->t['sesiones'] as $i => $f ) {
				if ( (int) $f['miembro_id'] === (int) $m[1] && $f['token'] !== $m[2] ) {
					unset( $this->t['sesiones'][ $i ] );
					$n++;
				}
			}
		} else {
			return 1;
		}
		return $n;
	}
	public function insert( $tabla, $data, $f = null ) {
		parent::insert( $tabla, $data, $f );
		$this->last_error = '';
		$n = $this->nombre( $tabla );
		if ( ! isset( $this->t[ $n ] ) ) {
			return 1;
		}
		$unicas = array( 'cuentas' => array( 'miembro_id', 'email' ), 'cuenta_tokens' => array( 'token_hash' ) );
		foreach ( $unicas[ $n ] ?? array() as $u ) {
			if ( $this->buscar( $n, $u, $data[ $u ] ) ) {
				$this->last_error = "Duplicate entry for key '{$u}'";
				return false;
			}
		}
		$this->ids[ $n ]++;
		$data['id']       = $this->ids[ $n ];
		$this->t[ $n ][]  = $data;
		$this->insert_id  = $data['id'];
		return 1;
	}
	public function update( $tabla, $data, $where, $f = null, $wf = null ) {
		parent::update( $tabla, $data, $where, $f, $wf );
		$n = $this->nombre( $tabla );
		if ( ! isset( $this->t[ $n ] ) ) {
			return 1;
		}
		$c = 0;
		foreach ( $this->t[ $n ] as $i => $fila ) {
			foreach ( $where as $k => $v ) {
				if ( (string) $fila[ $k ] !== (string) $v ) {
					continue 2;
				}
			}
			$this->t[ $n ][ $i ] = array_merge( $fila, $data );
			$c++;
		}
		return $c;
	}
	public function mp_payloads() {
		$out = array();
		foreach ( $this->log as $l ) {
			if ( 'insert' === $l[0] && false !== strpos( $l[1], 'mp_log' ) ) {
				$out[] = $l[2]['tipo'] . ' ' . $l[2]['payload'];
			}
		}
		return implode( "\n", $out );
	}
}

/** Simula la carrera: la primera búsqueda por miembro_id no ve la cuenta que otra alta acaba de crear. */
class CT_Db_Carrera extends CT_Db {
	public $ocultar = 1;
	public function get_row( $sql ) {
		if ( $this->ocultar > 0 && preg_match( '/FROM wp_cn_cuentas WHERE miembro_id = /', $sql ) ) {
			$this->ocultar--;
			return null;
		}
		return parent::get_row( $sql );
	}
}

const CT_DESDE_TXT = '2026-10-01 00:00:00';
const CT_EMAIL     = 'nueva.socia@example.com';
const CT_CEL       = '1155550001';
const CT_FUTURO    = '2099-01-01 00:00:00';
const CT_PASADO    = '2026-01-01 00:00:00';

function ct_ficha( $id, $extra = array() ) {
	return array_merge( array(
		'id' => $id, 'nombre_apellido' => 'Socia Prueba ' . $id, 'estado' => 'activo', 'fecha_fin_trial' => CT_FUTURO,
		'preapproval_id' => null, 'email' => '  Nueva.Socia@Example.com ', 'celular_texto_plano' => CT_CEL,
		'fecha_alta' => '2026-10-13 14:00:00', 'celular_hash' => CN_Helpers::hash_celular( CT_CEL ),
	), $extra );
}
/** Deja todo limpio. $fichas = array de ct_ficha(). */
function ct_preparar( $modo = 'on', $fichas = null, $con_desde = true ) {
	cn_test_reset();
	$db = new CT_Db();
	$GLOBALS['wpdb'] = $db;
	foreach ( ( null === $fichas ? array( ct_ficha( 10 ) ) : $fichas ) as $f ) {
		$db->t['miembros'][] = $f;
	}
	if ( null !== $modo ) {
		update_option( 'cn_cuentas_modo', $modo );
	}
	if ( $con_desde ) {
		update_option( 'cn_cuentas_desde', strtotime( CT_DESDE_TXT . ' UTC' ) );
	}
	$GLOBALS['cn_now']       = '2026-10-13 15:00:00';
	$_SERVER['REMOTE_ADDR']  = '9.9.9.9';
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	$_COOKIE                 = array();
	$GLOBALS['ct_avisos']    = array();
	CN_Cuentas::$notificador = function ( $cuenta, $ficha, $proposito, $token ) {
		$GLOBALS['ct_avisos'][] = array( 'cuenta_id' => (int) $cuenta->id, 'proposito' => $proposito, 'token' => $token );
	};
	return $db;
}
function ct_ultimo_token( $proposito ) {
	$t = '';
	foreach ( $GLOBALS['ct_avisos'] as $a ) {
		if ( $a['proposito'] === $proposito ) {
			$t = $a['token'];
		}
	}
	return $t;
}
/** Cuenta creada y con contraseña elegida; deja la base lista para probar login. */
function ct_con_password( $pass = 'pinturas-del-sur', $fichas = null ) {
	$db = ct_preparar( 'on', $fichas );
	CN_Cuentas::provisionar( 10, 'trial' );
	ob_start();
	$r = CN_Cuentas::elegir_password( ct_ultimo_token( 'crear' ), 'crear', $pass, $pass );
	ob_end_clean();
	$_COOKIE            = array();
	$db->t['sesiones']  = array();
	$GLOBALS['ct_avisos'] = array();
	return array( $db, $r );
}
function ct_q( $fn ) { // corre algo que puede llamar a setcookie sin ensuciar la salida.
	ob_start();
	$r = $fn();
	ob_end_clean();
	return $r;
}
function ct_sesiones_de( $db, $miembro_id ) {
	return array_values( array_filter( $db->t['sesiones'], function ( $s ) use ( $miembro_id ) {
		return (int) $s['miembro_id'] === (int) $miembro_id;
	} ) );
}

// =====================================================================
seccion( 'flag off = idéntico a hoy (sin lecturas ni escrituras)' );
foreach ( array( 'off', null, 'cualquier-cosa' ) as $modo ) {
	$db = ct_preparar( $modo );
	$r  = CN_Cuentas::provisionar( 10, 'trial' );
	$et = var_export( $modo, true );
	t( "off ({$et}): accion off", 'off' === $r['accion'] );
	t( "off ({$et}): ninguna consulta", array() === $db->consultas );
	t( "off ({$et}): ninguna escritura (ni mp_log)", array() === $db->log );
	t( "off ({$et}): sin aviso", array() === $GLOBALS['ct_avisos'] );
}

seccion( 'modo log: solo registra, no crea ni manda' );
$db = ct_preparar( 'log' );
$r  = CN_Cuentas::provisionar( 10, 'sub' );
t( 'log: accion log', 'log' === $r['accion'] );
t( 'log: no crea cuenta', array() === $db->t['cuentas'] && array() === $db->escrituras( 'cuentas' ) );
t( 'log: no crea tokens', array() === $db->escrituras( 'cuenta_tokens' ) );
t( 'log: no avisa', array() === $GLOBALS['ct_avisos'] );
t( 'log: deja cuenta_provisionar_log', in_array( 'cuenta_provisionar_log', $db->log_mp_tipos(), true ) );
t( 'log: email enmascarado', false !== strpos( $db->mp_payloads(), 'nu***@example.com' ) && false === stripos( $db->mp_payloads(), CT_EMAIL ) );

seccion( 'modo on: crear cuenta nueva' );
$db = ct_preparar( 'on' );
$r  = CN_Cuentas::provisionar( 10, 'trial' );
t( 'on: accion creada', 'creada' === $r['accion'], json_encode( $r ) );
t( 'on: 1 cuenta', 1 === count( $db->t['cuentas'] ) );
t( 'on: email normalizado (minúsculas, sin espacios)', CT_EMAIL === $db->t['cuentas'][0]['email'] );
t( 'on: sin contraseña todavía', null === $db->t['cuentas'][0]['pass_hash'] );
t( 'on: 1 token de crear', 1 === count( $db->t['cuenta_tokens'] ) && 'crear' === $db->t['cuenta_tokens'][0]['proposito'] );
t( 'on: el token vence en 7 días', '2026-10-20 15:00:00' === $db->t['cuenta_tokens'][0]['expira'] );
t( 'on: 1 aviso de crear', 1 === count( $GLOBALS['ct_avisos'] ) && 'crear' === $GLOBALS['ct_avisos'][0]['proposito'] );
$tok = ct_ultimo_token( 'crear' );
t( 'on: en la base solo el sha256 del token', hash( 'sha256', $tok ) === $db->t['cuenta_tokens'][0]['token_hash'] && $tok !== $db->t['cuenta_tokens'][0]['token_hash'] );
t( 'on: el token no aparece en mp_log', false === strpos( $db->mp_payloads(), $tok ) && false === strpos( $db->mp_payloads(), hash( 'sha256', $tok ) ) );
t( 'on: el email completo no aparece en mp_log', false === stripos( $db->mp_payloads(), CT_EMAIL ) );
t( 'on: nunca escribe en wp_cn_miembros', array() === $db->escrituras( 'miembros' ) );

seccion( 'provisionar es idempotente (2 llamadas = 1 cuenta, 1 mail)' );
$r2 = CN_Cuentas::provisionar( 10, 'sub' );
t( 'idempotente: segunda vez ya_tiene', 'ya_tiene' === $r2['accion'] );
t( 'idempotente: sigue 1 cuenta', 1 === count( $db->t['cuentas'] ) );
t( 'idempotente: sigue 1 aviso', 1 === count( $GLOBALS['ct_avisos'] ) );
t( 'idempotente: sigue 1 token', 1 === count( $db->t['cuenta_tokens'] ) );

seccion( 'email duplicado: no mezcla ni pisa' );
$db = ct_preparar( 'on', array( ct_ficha( 10 ), ct_ficha( 11, array( 'email' => 'NUEVA.socia@example.com' ) ) ) );
$r  = CN_Cuentas::provisionar( 10, 'trial' );
t( 'dup: otra ficha con el mismo email -> email_en_uso', 'email_en_uso' === $r['accion'], json_encode( $r ) );
t( 'dup: no crea cuenta', array() === $db->t['cuentas'] );
t( 'dup: registra cuenta_email_en_uso enmascarado', in_array( 'cuenta_email_en_uso', $db->log_mp_tipos(), true ) && false === stripos( $db->mp_payloads(), CT_EMAIL ) );
t( 'dup: no avisa', array() === $GLOBALS['ct_avisos'] );
$db = ct_preparar( 'on', array( ct_ficha( 10 ), ct_ficha( 11, array( 'email' => 'otra@example.com' ) ) ) );
$db->t['cuentas'][] = array( 'id' => 50, 'miembro_id' => 11, 'email' => CT_EMAIL, 'pass_hash' => null, 'creada' => '2026-10-02 00:00:00', 'ultimo_login' => null, 'intentos' => 0 );
$r = CN_Cuentas::provisionar( 10, 'trial' );
t( 'dup: email de la cuenta de OTRA ficha -> email_en_uso', 'email_en_uso' === $r['accion'] );
t( 'dup: la cuenta ajena queda igual', 1 === count( $db->t['cuentas'] ) && 11 === (int) $db->t['cuentas'][0]['miembro_id'] );
// Carrera: otra alta de la MISMA ficha creó la cuenta entre la lectura y el insert. La UNIQUE frena el segundo.
$base = ct_preparar( 'on' );
CN_Cuentas::provisionar( 10, 'trial' );
$db = new CT_Db_Carrera();
$db->t = $base->t;
$db->ids = $base->ids;
$GLOBALS['wpdb'] = $db;
$GLOBALS['ct_avisos'] = array();
$r = CN_Cuentas::provisionar( 10, 'sub' );
t( 'carrera: el insert repetido no duplica', 'ya_tiene' === $r['accion'] && 1 === count( $db->t['cuentas'] ), json_encode( $r ) );
t( 'carrera: no manda otro mail', array() === $GLOBALS['ct_avisos'] );

seccion( 'quién NO recibe cuenta' );
$db = ct_preparar( 'on' );
t( 'excluido: lote', 'excluido' === CN_Cuentas::provisionar( 10, 'vinculo_lote' )['accion'] );
t( 'excluido: origen desconocido', 'excluido' === CN_Cuentas::provisionar( 10, 'otro' )['accion'] );
t( 'excluido: ficha 0', 'excluido' === CN_Cuentas::provisionar( 0, 'trial' )['accion'] );
t( 'excluido: nada creado', array() === $db->t['cuentas'] && array() === $GLOBALS['ct_avisos'] );
$db = ct_preparar( 'on', array( ct_ficha( 10, array( 'fecha_alta' => '2025-05-01 10:00:00' ) ) ) );
t( 're-trial: ficha anterior a cn_cuentas_desde no recibe cuenta', 're_trial' === CN_Cuentas::provisionar( 10, 'trial' )['accion'] && array() === $db->t['cuentas'] );
t( 're-trial: la misma ficha que se convierte a suscripción SÍ', 'creada' === CN_Cuentas::provisionar( 10, 'sub' )['accion'] );
$db = ct_preparar( 'on', null, false );
t( 'sin cn_cuentas_desde: no crea', 'sin_desde' === CN_Cuentas::provisionar( 10, 'trial' )['accion'] && array() === $db->t['cuentas'] );
$db = ct_preparar( 'on', array( ct_ficha( 10, array( 'email' => '' ) ) ) );
t( 'sin email: no crea', 'sin_email' === CN_Cuentas::provisionar( 10, 'trial' )['accion'] && array() === $db->t['cuentas'] );
$db = ct_preparar( 'on' );
t( 'ficha inexistente', 'sin_ficha' === CN_Cuentas::provisionar( 99, 'trial' )['accion'] );

seccion( 'al_cambiar_modo guarda cn_cuentas_desde una sola vez' );
ct_preparar( 'off', null, false );
CN_Cuentas::al_cambiar_modo( 'off', 'log' );
$d1 = get_option( 'cn_cuentas_desde' );
t( 'desde: se guarda al salir de off', (int) $d1 === strtotime( '2026-10-13 15:00:00 UTC' ) );
$GLOBALS['cn_now'] = '2026-10-14 15:00:00';
CN_Cuentas::al_cambiar_modo( 'log', 'on' );
CN_Cuentas::al_cambiar_modo( 'off', 'on' );
t( 'desde: no se pisa', get_option( 'cn_cuentas_desde' ) === $d1 );

seccion( 'tokens: elegir contraseña, reuso, vencido, alterado, otro propósito' );
$db  = ct_preparar( 'on' );
CN_Cuentas::provisionar( 10, 'trial' );
$tok = ct_ultimo_token( 'crear' );
$r   = ct_q( function () use ( $tok ) {
	return CN_Cuentas::elegir_password( $tok, 'crear', 'pinturas-del-sur', 'pinturas-del-sur' );
} );
t( 'crear: ok', $r['ok'], json_encode( $r ) );
t( 'crear: guarda el hash', password_verify( 'pinturas-del-sur', (string) $db->t['cuentas'][0]['pass_hash'] ) );
t( 'crear: abre sesión (misma tabla cn_sesiones)', 1 === count( ct_sesiones_de( $db, 10 ) ) );
t( 'crear: cookie cn_session seteada', isset( $_COOKIE['cn_session'] ) && preg_match( '/^[a-f0-9]{64}$/', $_COOKIE['cn_session'] ) );
t( 'crear: token gastado', 1 === (int) $db->t['cuenta_tokens'][0]['usado'] );
$r = ct_q( function () use ( $tok ) {
	return CN_Cuentas::elegir_password( $tok, 'crear', 'otra-clave-larga', 'otra-clave-larga' );
} );
t( 'reuso: el mismo token no sirve dos veces', ! $r['ok'] && 'token' === $r['motivo'] && CN_Cuentas::MSG_TOKEN_INVALIDO === $r['mensaje'] );
t( 'reuso: la contraseña no cambió', password_verify( 'pinturas-del-sur', (string) $db->t['cuentas'][0]['pass_hash'] ) );
t( 'reuso: motivo usado', 'usado' === CN_Cuentas::revisar_token( $tok, 'crear' )['motivo'] );

$db = ct_preparar( 'on' );
CN_Cuentas::provisionar( 10, 'trial' );
$tok = ct_ultimo_token( 'crear' );
$GLOBALS['cn_now'] = '2026-10-20 14:59:59';
t( 'vencimiento crear: vale hasta el día 7', CN_Cuentas::revisar_token( $tok, 'crear' )['ok'] );
$GLOBALS['cn_now'] = '2026-10-20 15:00:00';
t( 'vencimiento crear: vencido a los 7 días', 'vencido' === CN_Cuentas::revisar_token( $tok, 'crear' )['motivo'] );
$r = ct_q( function () use ( $tok ) {
	return CN_Cuentas::elegir_password( $tok, 'crear', 'pinturas-del-sur', 'pinturas-del-sur' );
} );
t( 'vencido: no deja elegir contraseña', ! $r['ok'] && null === $db->t['cuentas'][0]['pass_hash'] );
$GLOBALS['cn_now'] = '2026-10-13 15:00:00';
$alterado = substr( $tok, 0, -1 ) . ( 'a' === substr( $tok, -1 ) ? 'b' : 'a' );
t( 'alterado: no existe', 'no_existe' === CN_Cuentas::revisar_token( $alterado, 'crear' )['motivo'] );
t( 'formato: corto', 'formato' === CN_Cuentas::revisar_token( 'abc', 'crear' )['motivo'] );
t( 'formato: con caracteres raros', 'formato' === CN_Cuentas::revisar_token( str_repeat( 'z', 64 ), 'crear' )['motivo'] );
t( 'formato: propósito inventado', 'formato' === CN_Cuentas::revisar_token( $tok, 'admin' )['motivo'] );
t( 'otro propósito: el de crear no sirve para recuperar', 'proposito' === CN_Cuentas::revisar_token( $tok, 'recuperar' )['motivo'] );
$r = ct_q( function () use ( $tok ) {
	return CN_Cuentas::entrar_con_link( $tok );
} );
t( 'otro propósito: el de crear no sirve para entrar con link', ! $r['ok'] && array() === $db->t['sesiones'] );
t( 'otro propósito: el token sigue sin gastar', 0 === (int) $db->t['cuenta_tokens'][0]['usado'] );

seccion( 'pedir uno nuevo anula los abiertos del mismo propósito' );
list( $db ) = ct_con_password();
CN_Cuentas::pedir_link( CT_EMAIL, 'recuperar' );
$t1 = ct_ultimo_token( 'recuperar' );
CN_Cuentas::pedir_link( CT_EMAIL, 'entrar' );
$te = ct_ultimo_token( 'entrar' );
CN_Cuentas::pedir_link( CT_EMAIL, 'recuperar' );
$t2 = ct_ultimo_token( 'recuperar' );
t( 'nuevo: el recuperar anterior quedó anulado', 'usado' === CN_Cuentas::revisar_token( $t1, 'recuperar' )['motivo'] );
t( 'nuevo: el último recuperar vale', CN_Cuentas::revisar_token( $t2, 'recuperar' )['ok'] );
t( 'nuevo: el de entrar (otro propósito) sigue valiendo', CN_Cuentas::revisar_token( $te, 'entrar' )['ok'] );
$GLOBALS['cn_now'] = '2026-10-13 15:15:00';
t( 'vencimiento entrar: 15 min', 'vencido' === CN_Cuentas::revisar_token( $te, 'entrar' )['motivo'] );
$GLOBALS['cn_now'] = '2026-10-13 15:59:59';
t( 'vencimiento recuperar: vale antes de 60 min', CN_Cuentas::revisar_token( $t2, 'recuperar' )['ok'] );
$GLOBALS['cn_now'] = '2026-10-13 16:00:00';
t( 'vencimiento recuperar: vencido a los 60 min', 'vencido' === CN_Cuentas::revisar_token( $t2, 'recuperar' )['motivo'] );

seccion( 'contraseña débil (y el token no se gasta)' );
$db = ct_preparar( 'on' );
CN_Cuentas::provisionar( 10, 'trial' );
$tok   = ct_ultimo_token( 'crear' );
$casos = array(
	'corta'                 => array( 'corta12', 'corta12', 'debil' ),
	'igual al email'        => array( 'Nueva.Socia@Example.com', 'Nueva.Socia@Example.com', 'debil' ),
	'igual al celular'      => array( '1155550001', '1155550001', 'debil' ),
	'celular con formato'   => array( '11 5555-0001', '11 5555-0001', 'debil' ),
	'celular internacional' => array( '+54 9 11 5555 0001', '+54 9 11 5555 0001', 'debil' ),
	'celular con 15'        => array( '11 15 5555 0001', '11 15 5555 0001', 'debil' ),
	'más de 128'            => array( str_repeat( 'x', 129 ), str_repeat( 'x', 129 ), 'debil' ),
	'no coinciden'          => array( 'pinturas-del-sur', 'pinturas-del-sur2', 'distintas' ),
);
foreach ( $casos as $nombre => $c ) {
	$r = ct_q( function () use ( $tok, $c ) {
		return CN_Cuentas::elegir_password( $tok, 'crear', $c[0], $c[1] );
	} );
	t( "débil ({$nombre}): rechazada", ! $r['ok'] && $c[2] === $r['motivo'], json_encode( $r ) );
}
t( 'débil: el token NO se gastó', CN_Cuentas::revisar_token( $tok, 'crear' )['ok'] );
t( 'débil: sin contraseña guardada ni sesión', null === $db->t['cuentas'][0]['pass_hash'] && array() === $db->t['sesiones'] );
t( 'largo: 64 caracteres sirve', '' === CN_Cuentas::validar_password( str_repeat( 'ab', 32 ), CT_EMAIL, CT_CEL ) );
t( 'largo: 128 caracteres sirve', '' === CN_Cuentas::validar_password( str_repeat( 'ab', 64 ), CT_EMAIL, CT_CEL ) );
t( 'número que no es el celular sirve', '' === CN_Cuentas::validar_password( '1122334455', CT_EMAIL, CT_CEL ) );

seccion( 'cambiar contraseña revoca las otras sesiones' );
list( $db ) = ct_con_password();
$db->t['sesiones'] = array(
	array( 'id' => 1, 'miembro_id' => 10, 'token' => str_repeat( 'a', 64 ), 'expira' => CT_FUTURO ),
	array( 'id' => 2, 'miembro_id' => 10, 'token' => str_repeat( 'b', 64 ), 'expira' => CT_FUTURO ),
	array( 'id' => 3, 'miembro_id' => 77, 'token' => str_repeat( 'c', 64 ), 'expira' => CT_FUTURO ),
);
CN_Cuentas::pedir_link( CT_EMAIL, 'recuperar' );
CN_Cuentas::pedir_link( CT_EMAIL, 'entrar' );
$tr = ct_ultimo_token( 'recuperar' );
$te = ct_ultimo_token( 'entrar' );
$r  = ct_q( function () use ( $tr ) {
	return CN_Cuentas::elegir_password( $tr, 'recuperar', 'acuarela-nueva-1', 'acuarela-nueva-1' );
} );
$mias = ct_sesiones_de( $db, 10 );
t( 'recuperar: ok', $r['ok'], json_encode( $r ) );
t( 'recuperar: las sesiones viejas de la socia se cerraron', 1 === count( $mias ) && ! in_array( $mias[0]['token'], array( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) ), true ) );
t( 'recuperar: queda solo la nueva (la de este dispositivo)', $mias[0]['token'] === $_COOKIE['cn_session'] );
t( 'recuperar: la sesión de OTRA socia no se toca', 1 === count( ct_sesiones_de( $db, 77 ) ) );
t( 'recuperar: anula TODOS los tokens (también el de entrar)', 'usado' === CN_Cuentas::revisar_token( $te, 'entrar' )['motivo'] );
$actual = $_COOKIE['cn_session'];
$db->t['sesiones'][] = array( 'id' => 9, 'miembro_id' => 10, 'token' => str_repeat( 'd', 64 ), 'expira' => CT_FUTURO );
$r = CN_Cuentas::cambiar_password( 10, 'acuarela-nueva-1', 'oleo-y-pinceles', 'oleo-y-pinceles' );
$mias = ct_sesiones_de( $db, 10 );
t( 'cambio: ok', $r['ok'], json_encode( $r ) );
t( 'cambio: queda solo la sesión actual', 1 === count( $mias ) && $actual === $mias[0]['token'] );
t( 'cambio: la contraseña nueva vale', password_verify( 'oleo-y-pinceles', (string) $db->t['cuentas'][0]['pass_hash'] ) );
$r = CN_Cuentas::cambiar_password( 10, 'mal', 'otra-clave-buena', 'otra-clave-buena' );
t( 'cambio: con la actual incorrecta no cambia', ! $r['ok'] && password_verify( 'oleo-y-pinceles', (string) $db->t['cuentas'][0]['pass_hash'] ) );
t( 'cambio/recuperar: nunca escribe en wp_cn_miembros', array() === $db->escrituras( 'miembros' ) );

seccion( 'login: respuesta idéntica exista o no el email' );
list( $db ) = ct_con_password();
$ok = ct_q( function () {
	return CN_Cuentas::login( '  NUEVA.socia@example.com', 'pinturas-del-sur' );
} );
t( 'login: correcto entra', $ok['ok'] && 1 === count( ct_sesiones_de( $db, 10 ) ), json_encode( $ok ) );
t( 'login: anota ultimo_login', '2026-10-13 15:00:00' === $db->t['cuentas'][0]['ultimo_login'] );
$_SERVER['REMOTE_ADDR'] = '9.9.9.1';
$mal = CN_Cuentas::login( CT_EMAIL, 'equivocada-123' );
$_SERVER['REMOTE_ADDR'] = '9.9.9.2';
$no  = CN_Cuentas::login( 'nadie@example.com', 'equivocada-123' );
$_SERVER['REMOTE_ADDR'] = '9.9.9.3';
$inv = CN_Cuentas::login( 'esto-no-es-un-email', 'equivocada-123' );
t( 'login: contraseña mala e email inexistente responden igual', $mal === $no, json_encode( array( $mal, $no ) ) );
t( 'login: email inválido responde igual', $mal === $inv );
t( 'login: mensaje neutro', CN_Cuentas::MSG_LOGIN_INVALIDO === $mal['mensaje'] );
t( 'login: el hash de relleno se crea una vez y se reutiliza', '' !== (string) get_option( 'cn_cuentas_hash_relleno' ) );
$db2 = ct_preparar( 'on' );
CN_Cuentas::provisionar( 10, 'trial' ); // cuenta SIN contraseña
$sin = CN_Cuentas::login( CT_EMAIL, 'cualquier-cosa' );
t( 'login: cuenta sin contraseña responde igual', $sin === $mal && array() === $db2->t['sesiones'] );

seccion( 'topes de login (5 fallos / 15 min por email y por IP)' );
list( $db ) = ct_con_password();
for ( $i = 0; $i < 5; $i++ ) {
	$_SERVER['REMOTE_ADDR'] = '10.0.0.' . $i; // IP distinta cada vez: salta el tope por email.
	CN_Cuentas::login( CT_EMAIL, 'equivocada-' . $i );
}
$_SERVER['REMOTE_ADDR'] = '10.0.0.99';
$r = ct_q( function () {
	return CN_Cuentas::login( CT_EMAIL, 'pinturas-del-sur' );
} );
t( 'tope email: con la contraseña correcta igual frena', ! $r['ok'] && 'demasiados' === $r['motivo'] && array() === $db->t['sesiones'] );
t( 'tope email: cuenta los intentos en la cuenta', 5 === (int) $db->t['cuentas'][0]['intentos'] );
list( $db ) = ct_con_password();
$_SERVER['REMOTE_ADDR'] = '10.1.1.1';
for ( $i = 0; $i < 5; $i++ ) {
	CN_Cuentas::login( 'otra' . $i . '@example.com', 'x-x-x-x-x' ); // emails distintos (inexistentes): salta el tope por IP.
}
$r_ip = ct_q( function () {
	return CN_Cuentas::login( CT_EMAIL, 'pinturas-del-sur' );
} );
t( 'tope IP: frena aunque el email y la contraseña sean correctos', ! $r_ip['ok'] && 'demasiados' === $r_ip['motivo'] );
t( 'tope: mismo mensaje sea cual sea el tope', $r['mensaje'] === $r_ip['mensaje'] && CN_Cuentas::MSG_DEMASIADOS === $r['mensaje'] );
list( $db ) = ct_con_password();
$_SERVER['REMOTE_ADDR'] = '10.2.2.2';
for ( $i = 0; $i < 4; $i++ ) {
	CN_Cuentas::login( CT_EMAIL, 'equivocada' );
}
$r = ct_q( function () {
	return CN_Cuentas::login( CT_EMAIL, 'pinturas-del-sur' );
} );
t( 'tope: con 4 fallos todavía entra', $r['ok'] );
t( 'tope: al entrar se limpia el contador del email', false === get_transient( 'cn_cta_login_em_' . md5( CT_EMAIL ) ) );
t( 'tope: el de IP no se limpia', 4 === (int) get_transient( 'cn_cta_login_ip_' . md5( '10.2.2.2' ) ) );

seccion( 'pedir link: neutro y con topes (3/h por email, 10/h por IP)' );
list( $db ) = ct_con_password();
$a = CN_Cuentas::pedir_link( CT_EMAIL, 'recuperar' );
$b = CN_Cuentas::pedir_link( 'nadie@example.com', 'recuperar' );
$c = CN_Cuentas::pedir_link( 'no-es-email', 'entrar' );
$d = CN_Cuentas::pedir_link( CT_EMAIL, 'crear' ); // propósito no permitido desde afuera
t( 'link: existente e inexistente responden igual', $a === $b && $b === $c && $c === $d );
t( 'link: mensaje neutro', CN_Cuentas::MSG_LINK_NEUTRO === $a['mensaje'] );
t( 'link: solo avisa a la cuenta existente', 1 === count( $GLOBALS['ct_avisos'] ) && 'recuperar' === $GLOBALS['ct_avisos'][0]['proposito'] );
CN_Cuentas::pedir_link( CT_EMAIL, 'entrar' );
CN_Cuentas::pedir_link( CT_EMAIL, 'entrar' );
$e = CN_Cuentas::pedir_link( CT_EMAIL, 'entrar' );
t( 'link: el 4º pedido de la hora no se manda', 3 === count( $GLOBALS['ct_avisos'] ) && $e === $a );
list( $db ) = ct_con_password();
$_SERVER['REMOTE_ADDR'] = '10.3.3.3';
for ( $i = 0; $i < 10; $i++ ) {
	CN_Cuentas::pedir_link( 'x' . $i . '@example.com', 'recuperar' );
}
$f = CN_Cuentas::pedir_link( CT_EMAIL, 'recuperar' );
t( 'link: con 10 pedidos de la misma IP no se manda', array() === $GLOBALS['ct_avisos'] && $f === $a );

seccion( 'entrar con link' );
list( $db ) = ct_con_password();
CN_Cuentas::pedir_link( CT_EMAIL, 'entrar' );
$te = ct_ultimo_token( 'entrar' );
$r  = ct_q( function () use ( $te ) {
	return CN_Cuentas::entrar_con_link( $te );
} );
t( 'link entrar: abre sesión', $r['ok'] && 1 === count( ct_sesiones_de( $db, 10 ) ) );
$r = ct_q( function () use ( $te ) {
	return CN_Cuentas::entrar_con_link( $te );
} );
t( 'link entrar: un solo uso', ! $r['ok'] && 1 === count( ct_sesiones_de( $db, 10 ) ) );
t( 'link entrar: rastro sin token', in_array( 'cuenta_entrar_link', $db->log_mp_tipos(), true ) && false === strpos( $db->mp_payloads(), $te ) );

seccion( 'la ficha decide: pausada / cancelada / trial vencido no entran aunque la contraseña sea correcta' );
$fichas_no = array(
	'pausada'                    => array( array( 'estado' => 'pausado' ), 'pausado' ),
	'cancelada'                  => array( array( 'estado' => 'cancelada' ), 'vencido' ),
	'trial vencido sin sub'      => array( array( 'fecha_fin_trial' => CT_PASADO ), 'vencido' ),
	'cancelada con sub'          => array( array( 'estado' => 'cancelada', 'preapproval_id' => 'abc', 'fecha_fin_trial' => null ), 'vencido' ),
);
foreach ( $fichas_no as $nombre => $c ) {
	list( $db ) = ct_con_password();
	$db->t['miembros'][0] = array_merge( $db->t['miembros'][0], $c[0] );
	$r = ct_q( function () {
		return CN_Cuentas::login( CT_EMAIL, 'pinturas-del-sur' );
	} );
	t( "ficha {$nombre}: no entra", ! $r['ok'] && $c[1] === $r['motivo'], json_encode( $r ) );
	t( "ficha {$nombre}: sin sesión", array() === $db->t['sesiones'] );
	t( "ficha {$nombre}: mensaje con hola@ y sin WhatsApp", false !== strpos( $r['mensaje'], 'hola@naturalesart.com' ) && false === stripos( $r['mensaje'], 'whatsapp' ) && false === strpos( $r['mensaje'], 'wa.me' ) );
	t( "ficha {$nombre}: no escribe la ficha (no la marca cancelada)", array() === $db->escrituras( 'miembros' ) );
	CN_Cuentas::pedir_link( CT_EMAIL, 'entrar' );
	$te = ct_ultimo_token( 'entrar' );
	$r  = ct_q( function () use ( $te ) {
		return CN_Cuentas::entrar_con_link( $te );
	} );
	t( "ficha {$nombre}: tampoco entra con link", ! $r['ok'] && array() === $db->t['sesiones'] );
}
list( $db ) = ct_con_password();
$db->t['miembros'][0] = array_merge( $db->t['miembros'][0], array( 'fecha_fin_trial' => CT_PASADO, 'preapproval_id' => 'PRE123' ) );
$r = ct_q( function () {
	return CN_Cuentas::login( CT_EMAIL, 'pinturas-del-sur' );
} );
t( 'trial vencido CON suscripción: entra (igual que CN_Auth)', $r['ok'] );
list( $db ) = ct_con_password();
$db->t['miembros'][0]['estado'] = 'pausado';
CN_Cuentas::pedir_link( CT_EMAIL, 'recuperar' );
$tr = ct_ultimo_token( 'recuperar' );
$r  = ct_q( function () use ( $tr ) {
	return CN_Cuentas::elegir_password( $tr, 'recuperar', 'clave-nueva-larga', 'clave-nueva-larga' );
} );
t( 'pausada: puede cambiar la contraseña pero no se abre sesión', ! $r['ok'] && 'pausado' === $r['motivo'] && array() === $db->t['sesiones'] && password_verify( 'clave-nueva-larga', (string) $db->t['cuentas'][0]['pass_hash'] ) );

seccion( 'sin WhatsApp en ningún texto nuevo' );
$rc    = new ReflectionClass( 'CN_Cuentas' );
$todo  = implode( ' ', array_filter( $rc->getConstants(), 'is_string' ) ) . ' ' . CN_Cuentas::mensaje_sin_acceso( 'pausado' ) . ' ' . CN_Cuentas::mensaje_sin_acceso( 'vencido' );
$fuente = file_get_contents( dirname( __DIR__ ) . '/includes/class-cn-cuentas.php' );
t( 'textos: no mencionan WhatsApp', false === stripos( $todo, 'whatsapp' ) && false === strpos( $todo, 'wa.me' ) );
t( 'código: no usa CN_WA_SOPORTE ni wa.me', false === strpos( $fuente, 'CN_WA_SOPORTE' ) && false === strpos( $fuente, 'wa.me' ) );
t( 'código: los únicos hooks son los dos de la opción cn_cuentas_modo (PASO E; en el PASO B no había ninguno)', 2 === preg_match_all( '/\badd_(action|filter|shortcode)\s*\(/', $fuente ) && 2 === preg_match_all( "/add_action\( '(update|add)_option_' \. self::OPT_MODO/", $fuente ) );

seccion( 'regresión: login clásico nombre + celular sigue igual con el flag prendido' );
foreach ( array( 'off', 'on' ) as $modo ) {
	cn_test_reset();
	update_option( 'cn_cuentas_modo', $modo );
	global $wpdb;
	$_SERVER['REMOTE_ADDR'] = '8.8.4.4';
	$_COOKIE = array();
	$wpdb->on( '/FROM wp_cn_miembros WHERE LOWER\(TRIM/', array( (object) ct_ficha( 10 ) ) );
	$r = ct_q( function () {
		return CN_Auth::login( 'Socia Prueba 10', '11 5555-0001' );
	} );
	t( "clásico ({$modo}): entra", $r['ok'] && 'ok' === $r['motivo'] );
	t( "clásico ({$modo}): misma sesión de siempre", 1 === count( $wpdb->escrituras( 'sesiones' ) ) && isset( $_COOKIE['cn_session'] ) );
	t( "clásico ({$modo}): no toca tablas de cuentas", array() === $wpdb->escrituras( 'cuentas' ) && array() === $wpdb->escrituras( 'cuenta_tokens' ) );
	$r = ct_q( function () {
		return CN_Auth::login( 'Socia Prueba 10', '11 5555-9999' );
	} );
	t( "clásico ({$modo}): celular mal sigue fallando igual", ! $r['ok'] && 'invalido' === $r['motivo'] );
}
CN_Cuentas::$notificador = null;
