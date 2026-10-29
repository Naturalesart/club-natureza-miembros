<?php
/**
 * Plantilla de mails "carta" (BORRADOR — Fase 0, Parte 2).
 *
 * Plantilla NUEVA y SEPARADA de los mails actuales (día 0, día 5, vencimiento):
 * no reemplaza nada, no registra hooks y NO envía nada — solo arma el HTML y la
 * versión en texto plano. La clase no tiene ningún método de envío:
 * mandar el mail es siempre una decisión aparte, tomada fuera de acá.
 *
 * Diseño (pedido por Leandro):
 *  - formato carta: HTML mínimo, 600 px de ancho, sin banners ni GIF, una foto máximo
 *  - cuerpo 17 px Arial/Helvetica, interlineado 1,5, alineado a la izquierda
 *  - títulos en Georgia; fondo crema #FAF3E6, verde #2F4A3A, botón terracota #A9502E
 *  - un solo botón de ancho completo (mínimo 48 px de alto), en primera persona y con flecha
 *  - línea fija de "sin cargo automático" debajo del botón, firma del equipo (destacada) y baja en un clic
 *  - modo oscuro: meta color-scheme + overrides de prefers-color-scheme (y colores que
 *    no se invierten mal en Gmail, que ignora esos overrides y recalcula solo)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CN_Mail_Carta {

	const ANCHO     = 600;
	const CREMA     = '#FAF3E6'; // fondo
	const VERDE     = '#2F4A3A'; // títulos y acentos
	const TINTA     = '#33302A'; // cuerpo (alto contraste sobre crema)
	const TERRACOTA = '#A9502E'; // botón (texto blanco)
	const LINEA_SIN_CARGO = 'Nunca se te cobra nada de forma automática.';
	// Baja en un clic: mailto con asunto ya cargado (no hay página de baja todavía).
	const BAJA_MAILTO = 'mailto:naturalesartcursos@gmail.com?subject=BAJA%20de%20mails';

	/**
	 * Arma un mail en formato carta. Devuelve array( 'html' => ..., 'texto' => ... ).
	 *
	 * $args:
	 *  - titulo      (string, obligatorio)
	 *  - parrafos    (array de strings, texto plano; se escapa acá)
	 *  - destacado   (opcional: array de array( 'etiqueta' => ..., 'valor' => ... )) — caja de datos
	 *  - foto        (opcional: array( 'url' => ..., 'alt' => ... )) — una como máximo
	 *  - boton       (array( 'texto' => ..., 'url' => ... )) — en primera persona; la flecha se agrega sola
	 *  - posdata     (opcional, string) — párrafo corto después del botón
	 *  - preheader   (opcional, string) — texto de vista previa del cliente de mail
	 */
	public static function armar( $args ) {
		$titulo    = isset( $args['titulo'] ) ? (string) $args['titulo'] : '';
		$parrafos  = isset( $args['parrafos'] ) && is_array( $args['parrafos'] ) ? $args['parrafos'] : array();
		$destacado = isset( $args['destacado'] ) && is_array( $args['destacado'] ) ? $args['destacado'] : array();
		$foto      = isset( $args['foto'] ) && is_array( $args['foto'] ) ? $args['foto'] : null;
		$boton     = isset( $args['boton'] ) && is_array( $args['boton'] ) ? $args['boton'] : array( 'texto' => '', 'url' => '' );
		$posdata   = isset( $args['posdata'] ) ? (string) $args['posdata'] : '';
		$preheader = isset( $args['preheader'] ) ? (string) $args['preheader'] : '';
		$btn_texto = rtrim( (string) $boton['texto'] );
		if ( '' !== $btn_texto && false === strpos( $btn_texto, '→' ) ) {
			$btn_texto .= ' →';
		}
		$btn_url = (string) $boton['url'];

		$h  = '<!DOCTYPE html><html lang="es"><head>';
		$h .= '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		// Modo oscuro: declararlo y dar overrides. Gmail los ignora y recalcula solo,
		// por eso los colores base ya son "seguros" al invertirse (crema/verde/terracota).
		$h .= '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">';
		$h .= '<style>';
		$h .= ':root{color-scheme:light dark;supported-color-schemes:light dark;}';
		$h .= '@media (prefers-color-scheme: dark){';
		$h .= '.cn-fondo{background:#262219!important;}';
		$h .= '.cn-titulo,.cn-marca{color:#dde8d9!important;}';
		$h .= '.cn-texto,.cn-dato{color:#efe8d8!important;}';
		$h .= '.cn-caja{background:#332e24!important;border-color:#4a4435!important;}';
		$h .= '.cn-suave{color:#bdb49e!important;}';
		$h .= '}</style></head>';
		$h .= '<body class="cn-fondo" style="margin:0;padding:0;background:' . self::CREMA . ';">';
		if ( '' !== $preheader ) {
			$h .= '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">' . esc_html( $preheader ) . '</div>';
		}
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . self::CREMA . '" class="cn-fondo" style="background:' . self::CREMA . ';">';
		$h .= '<tr><td align="center" style="padding:32px 16px;">';
		$h .= '<table role="presentation" width="' . self::ANCHO . '" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:' . self::ANCHO . 'px;">';

		// Marca, chiquita y sin banner: una línea de texto.
		$h .= '<tr><td align="left" style="padding:0 0 26px;font-family:Georgia,\'Times New Roman\',serif;font-size:16px;letter-spacing:1px;color:' . self::VERDE . ';" class="cn-marca">🌿 Club Natureza</td></tr>';

		// Título.
		$h .= '<tr><td align="left" style="padding:0 0 14px;"><h1 class="cn-titulo" style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-weight:normal;font-size:27px;line-height:1.3;color:' . self::VERDE . ';">' . esc_html( $titulo ) . '</h1></td></tr>';

		// Foto (una como máximo).
		if ( $foto && ! empty( $foto['url'] ) ) {
			$h .= '<tr><td style="padding:4px 0 18px;"><img src="' . esc_url( $foto['url'] ) . '" alt="' . esc_html( isset( $foto['alt'] ) ? $foto['alt'] : '' ) . '" width="' . self::ANCHO . '" style="display:block;width:100%;max-width:' . self::ANCHO . 'px;height:auto;border-radius:12px;"></td></tr>';
		}

		// Cuerpo.
		foreach ( $parrafos as $p ) {
			$h .= '<tr><td align="left" class="cn-texto" style="padding:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:17px;line-height:1.5;color:' . self::TINTA . ';">' . esc_html( (string) $p ) . '</td></tr>';
		}

		// Caja de datos (usuario / celular).
		if ( $destacado ) {
			$h .= '<tr><td style="padding:4px 0 20px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="cn-caja" style="background:#FFFFFF;border:1px solid #E5D9C4;border-radius:12px;"><tr><td style="padding:18px 20px;">';
			$primero = true;
			foreach ( $destacado as $d ) {
				$h .= '<p class="cn-suave" style="margin:' . ( $primero ? '0' : '14px' ) . ' 0 3px;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:1px;color:#8A8270;">' . esc_html( mb_strtoupper( (string) $d['etiqueta'], 'UTF-8' ) ) . '</p>';
				$h .= '<p class="cn-dato" style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:bold;color:' . self::TINTA . ';">' . esc_html( (string) $d['valor'] ) . '</p>';
				$primero = false;
			}
			$h .= '</td></tr></table></td></tr>';
		}

		// Botón único, ancho completo, mínimo 48 px de alto (15+15 de padding + 18 de letra).
		if ( '' !== $btn_texto && '' !== $btn_url ) {
			$h .= '<tr><td style="padding:8px 0 10px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" bgcolor="' . self::TERRACOTA . '" style="background:' . self::TERRACOTA . ';border-radius:10px;">';
			$h .= '<a href="' . esc_url( $btn_url ) . '" style="display:block;padding:15px 20px;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:bold;line-height:1.2;color:#FFFFFF;text-decoration:none;">' . esc_html( $btn_texto ) . '</a>';
			$h .= '</td></tr></table></td></tr>';
			// Línea fija debajo del botón.
			$h .= '<tr><td align="center" class="cn-suave" style="padding:0 0 22px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6E6757;">' . esc_html( self::LINEA_SIN_CARGO ) . '</td></tr>';
		}

		if ( '' !== $posdata ) {
			$h .= '<tr><td align="left" class="cn-texto" style="padding:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:' . self::TINTA . ';">' . esc_html( $posdata ) . '</td></tr>';
		}

		// Firma del equipo, un poco más destacada que el cuerpo.
		$h .= '<tr><td align="left" style="padding:10px 0 2px;font-family:Georgia,\'Times New Roman\',serif;font-size:17px;line-height:1.5;color:' . self::VERDE . ';" class="cn-titulo">Con cariño,</td></tr>';
		$h .= '<tr><td align="left" style="padding:0 0 26px;font-family:Georgia,\'Times New Roman\',serif;font-size:21px;line-height:1.35;color:' . self::VERDE . ';" class="cn-titulo"><strong>El equipo de Naturales Art</strong><br>Club Natureza 🌿</td></tr>';

		// Baja en un clic.
		$h .= '<tr><td align="left" class="cn-suave" style="padding:14px 0 0;border-top:1px solid #E5D9C4;font-family:Arial,Helvetica,sans-serif;font-size:12.5px;line-height:1.5;color:#8A8270;">¿No querés recibir más estos mails? <a href="' . self::BAJA_MAILTO . '" style="color:#8A8270;text-decoration:underline;">Dejar de recibirlos</a> — un clic y listo.</td></tr>';

		$h .= '</table></td></tr></table></body></html>';

		// Versión en texto plano.
		$t   = array();
		$t[] = 'Club Natureza';
		$t[] = '';
		$t[] = $titulo;
		$t[] = '';
		foreach ( $parrafos as $p ) {
			$t[] = (string) $p;
			$t[] = '';
		}
		foreach ( $destacado as $d ) {
			$t[] = mb_strtoupper( (string) $d['etiqueta'], 'UTF-8' ) . ': ' . (string) $d['valor'];
		}
		if ( $destacado ) {
			$t[] = '';
		}
		if ( '' !== $btn_texto && '' !== $btn_url ) {
			$t[] = $btn_texto . ' ' . $btn_url;
			$t[] = self::LINEA_SIN_CARGO;
			$t[] = '';
		}
		if ( '' !== $posdata ) {
			$t[] = $posdata;
			$t[] = '';
		}
		$t[] = 'Con cariño,';
		$t[] = 'El equipo de Naturales Art · Club Natureza';
		$t[] = '';
		$t[] = 'Para dejar de recibir estos mails, respondé con el asunto BAJA y listo.';

		return array( 'html' => $h, 'texto' => implode( "\n", $t ) );
	}

	/* ---------------------------------------------------------------------
	 * BORRADORES (no se envían: solo devuelven asunto + html + texto)
	 * ------------------------------------------------------------------- */

	/** Día 0: bienvenida al trial con los datos de acceso. */
	public static function borrador_dia0( $nombre, $celular, $login_url ) {
		$m = self::armar( array(
			'titulo'    => '¡Bienvenida, ' . $nombre . '! Tu semana de pintura empieza hoy',
			'preheader' => 'Tus datos de acceso están acá adentro. Tu primera clase te espera.',
			'parrafos'  => array(
				'Qué lindo que estés acá. Ya tenés abierta tu semana de prueba en el Club: siete días para pintar a tu ritmo, sin apuro y sin compromiso.',
				'Para entrar solo necesitás estos dos datos (escribilos tal cual figuran acá):',
			),
			'destacado' => array(
				array( 'etiqueta' => 'Usuario (nombre y apellido)', 'valor' => $nombre ),
				array( 'etiqueta' => 'Contraseña (tu celular)', 'valor' => $celular ),
			),
			'boton'     => array( 'texto' => 'Entro a mi primera clase', 'url' => $login_url ),
			'posdata'   => 'Si algo no te sale o no podés entrar, respondé este mail o escribinos por WhatsApp y te ayudamos enseguida.',
		) );
		$m['asunto'] = 'Bienvenida al Club Natureza 🌿 Tu primera clase te espera';
		return $m;
	}

	/** Día 5: quedan 2 días de prueba (BORRADOR: hoy este aviso a la socia no existe y no se envía). */
	public static function borrador_dia5( $nombre, $club_url ) {
		$m = self::armar( array(
			'titulo'    => $nombre . ', te quedan 2 días de prueba',
			'preheader' => 'Tu semana de pintura termina pronto. Si te gustó, podés quedarte.',
			'parrafos'  => array(
				'¿Cómo venís con tus clases? Ojalá hayas encontrado un rato para vos y para pintar.',
				'Te escribo porque tu semana de prueba termina en dos días. Si el Club te gustó y querés seguir pintando con nosotras, podés sumarte a la suscripción mensual: se paga mes a mes y la cancelás cuando quieras.',
			),
			'boton'     => array( 'texto' => 'Quiero seguir pintando', 'url' => $club_url ),
			'posdata'   => 'Y si esta semana no pudiste pintar casi nada, contanos: a veces con un empujoncito alcanza.',
		) );
		$m['asunto'] = $nombre . ', te quedan 2 días de prueba en el Club 🌿';
		return $m;
	}

	/** Vencimiento: la prueba terminó. */
	public static function borrador_vencimiento( $nombre, $club_url ) {
		$m = self::armar( array(
			'titulo'    => $nombre . ', tu semana de prueba terminó (y esto no es una despedida)',
			'preheader' => 'Tu acceso quedó pausado. Si querés seguir pintando, la puerta está abierta.',
			'parrafos'  => array(
				'Tu semana de prueba en el Club terminó, así que tu acceso quedó pausado por ahora. Ojalá hayas podido asomarte a los cursos y disfrutar un poco de pintar.',
				'Si querés seguir con nosotras, podés sumarte a la suscripción mensual cuando quieras: se paga mes a mes, la cancelás cuando quieras, y retomás exactamente donde dejaste.',
			),
			'boton'     => array( 'texto' => 'Quiero seguir en el Club', 'url' => $club_url ),
			'posdata'   => 'Gracias por habernos probado. Cualquier duda, respondé este mail y te contesta Naty.',
		) );
		$m['asunto'] = 'Tu semana de prueba terminó — así podés seguir pintando 🌿';
		return $m;
	}
}
