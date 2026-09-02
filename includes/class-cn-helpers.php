<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class CN_Helpers {
		/**
	 * Normaliza un celular a un formato canónico (solo dígitos). Para números
		 * argentinos, saca +54 / 54 / 9 / 0 / 15. Para el resto de los países, se
		 * guarda tal cual aparece (solo dígitos, sin el "+") — sin aplicarle
		 	 * heurísticas pensadas para Argentina, que romperían números de otros países
			 	 * (importante de cara a la expansión a LATAM). Usar SIEMPRE esta función en
				 	 * alta, login y suscripción.
					 	 *
						 	 * @return array { normalizado: string, valido: bool }
							 	 */
	public static function normalizar_celular( $raw ) {
				$digits = preg_replace( '/\D+/', '', (string) $raw );
				$es_argentina = ( substr( $digits, 0, 2 ) === '54' );
				if ( $es_argentina ) {
								// Sacar +54 / 54 inicial (el + ya se pierde en el preg_replace anterior).
					$digits = substr( $digits, 2 );
								// Sacar el "9" de móvil que suele acompañar al código de país en formato internacional.
					if ( substr( $digits, 0, 1 ) === '9' ) {
										$digits = substr( $digits, 1 );
					}
								// Sacar 0 inicial (prefijo de larga distancia).
					if ( substr( $digits, 0, 1 ) === '0' ) {
										$digits = substr( $digits, 1 );
					}
								// Sacar "15" después del código de área (formato local: AREA + 15 + NUMERO).
					// Los códigos de área argentinos van de 2 a 4 dígitos, probamos en ese orden.
					foreach ( array( 2, 3, 4 ) as $pos ) {
										if ( substr( $digits, $pos, 2 ) === '15' ) {
																$digits = substr( $digits, 0, $pos ) . substr( $digits, $pos + 2 );
																break;
										}
					}
								// Un celular argentino normalizado (código de área + número, sin 9/0/15/54) tiene 10 dígitos.
					$valido = ( strlen( $digits ) === 10 && ctype_digit( $digits ) );
				} else {
								// Otros países: se guarda tal cual aparece (solo dígitos), sin tocar
					// nada — las heurísticas de arriba son específicas de Argentina y
					// aplicarlas acá recortaría mal números de otros países.
					$valido = ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 && ctype_digit( $digits ) );
				}
				return array(
								'normalizado' => $digits,
								'valido'      => $valido,
							);
	}
		public static function hint_celular( $celular_normalizado ) {
					return substr( $celular_normalizado, -4 );
		}
		public static function hash_celular( $celular_normalizado ) {
					return password_hash( $celular_normalizado, PASSWORD_DEFAULT );
		}
		public static function verificar_celular( $celular_normalizado, $hash ) {
					return password_verify( $celular_normalizado, $hash );
		}
		public static function generar_token() {
					return bin2hex( random_bytes( 32 ) ); // 64 caracteres hex.
		}
		public static function get_client_ip() {
					$headers = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' );
					foreach ( $headers as $header ) {
									if ( ! empty( $_SERVER[ $header ] ) ) {
														$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
														if ( strpos( $ip, ',' ) !== false ) {
																				$parts = explode( ',', $ip );
																				$ip    = trim( $parts[0] );
														}
														if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
																				return $ip;
														}
									}
					}
					return '0.0.0.0';
		}
		public static function extraer_youtube_id( $url ) {
					$url = trim( (string) $url );
					if ( preg_match( '/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/))([a-zA-Z0-9_-]{6,})/', $url, $m ) ) {
									return $m[1];
					}
					if ( preg_match( '/^[a-zA-Z0-9_-]{6,}$/', $url ) ) {
									return $url;
					}
					return '';
		}
		public static function youtube_thumbnail( $url ) {
					$id = self::extraer_youtube_id( $url );
					if ( ! $id ) {
									return '';
					}
					return 'https://img.youtube.com/vi/' . $id . '/hqdefault.jpg';
		}
		public static function es_youtube( $url ) {
					return (bool) preg_match( '/(youtu\.be\/|youtube\.com\/)/i', (string) $url );
		}
		public static function es_drive( $url ) {
					return (bool) preg_match( '/drive\.google\.com/i', (string) $url );
		}
		/**
	 * Extrae el ID de archivo de un link individual de Google Drive
		 * (formato /file/d/ID/... o ?id=ID). No confundir con un link de carpeta.
		 */
		public static function extraer_drive_file_id( $url ) {
					$url = trim( (string) $url );
					if ( preg_match( '/\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $m ) ) {
									return $m[1];
					}
					if ( preg_match( '/[?&]id=([a-zA-Z0-9_-]+)/', $url, $m ) ) {
									return $m[1];
					}
					return '';
		}
		/**
		 * Extrae el ID de carpeta de un link de carpeta de Google Drive.
		 */
		public static function extraer_drive_folder_id( $url ) {
					$url = trim( (string) $url );
					if ( preg_match( '/\/folders\/([a-zA-Z0-9_-]+)/', $url, $m ) ) {
									return $m[1];
					}
					return '';
		}
		/**
		 * URL de miniatura (thumbnail) de un video, sirva de YouTube o de Google Drive.
		 * - YouTube: usa la portada hqdefault.
		 * - Drive: usa el endpoint público /thumbnail (funciona con archivos compartidos
		 *   "cualquiera con el enlace"). sz=w640 pide un ancho de 640px.
		 * Devuelve '' si no se puede resolver (se cae a un placeholder en el front).
		 */
		public static function video_thumbnail_url( $link ) {
					if ( self::es_youtube( $link ) ) {
									return self::youtube_thumbnail( $link );
					}
					if ( self::es_drive( $link ) ) {
									$id = self::extraer_drive_file_id( $link );
									if ( $id ) {
														return 'https://drive.google.com/thumbnail?id=' . rawurlencode( $id ) . '&sz=w640';
									}
					}
					return '';
		}
		/**
		 * Link de descarga directa para un archivo de Drive (usado en PDFs).
		 * Si no es un link de Drive, devuelve el link tal cual.
		 */
		public static function drive_download_url( $link ) {
					$id = self::extraer_drive_file_id( $link );
					if ( ! $id ) {
									return $link;
					}
					return 'https://drive.google.com/uc?export=download&id=' . rawurlencode( $id );
		}
		/**
		 * HTML de un reproductor embebido (iframe) para un video, ya sea de
		 * YouTube o de Google Drive. Devuelve '' si no se puede embeber.
		 */
		public static function embed_video_html( $link, $titulo = '' ) {
			$titulo_attr = esc_attr( $titulo );
			if ( self::es_youtube( $link ) ) {
							$id = self::extraer_youtube_id( $link );
							if ( ! $id ) {
												return '';
							}
							$src = 'https://www.youtube.com/embed/' . rawurlencode( $id );
							return '<div class="cn-embed"><iframe src="' . esc_url( $src ) . '" title="' . $titulo_attr . '" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; fullscreen; gyroscope; picture-in-picture" allowfullscreen webkitallowfullscreen mozallowfullscreen></iframe></div>';
			}
			if ( self::es_drive( $link ) ) {
							$id = self::extraer_drive_file_id( $link );
							if ( ! $id ) {
												return '';
							}
							$src = 'https://drive.google.com/file/d/' . rawurlencode( $id ) . '/preview';
							return '<div class="cn-embed"><iframe src="' . esc_url( $src ) . '" title="' . $titulo_attr . '" loading="lazy" allow="autoplay; fullscreen" allowfullscreen webkitallowfullscreen mozallowfullscreen></iframe></div>';
			}
			return '';
}
		/**
		 * HTML de un reproductor embebido (iframe) para un audio alojado en Drive.
		 * Devuelve '' si el link no es de Drive (en ese caso se ofrece un botón externo).
		 */
		public static function embed_audio_html( $link, $titulo = '' ) {
					if ( ! self::es_drive( $link ) ) {
									return '';
					}
					$id = self::extraer_drive_file_id( $link );
					if ( ! $id ) {
									return '';
					}
					$src = 'https://drive.google.com/file/d/' . rawurlencode( $id ) . '/preview';
					return '<div class="cn-embed cn-embed--audio"><iframe src="' . esc_url( $src ) . '" title="' . esc_attr( $titulo ) . '" loading="lazy" allow="autoplay"></iframe></div>';
		}
		/**
		 * Paleta fija de colores de marca para asignar a los cursos, en orden.
		 * El mismo índice siempre da el mismo color (nunca aleatorio).
		 */
		public static function paleta_cursos() {
					return array( 'verde', 'terracota', 'rosa', 'dorado', 'sage' );
		}
		public static function color_por_indice( $indice ) {
			$paleta = self::paleta_cursos();
			return $paleta[ $indice % count( $paleta ) ];
}
		public static function iniciales( $nombre_completo ) {
			$partes = preg_split( '/\s+/', trim( (string) $nombre_completo ) );
			$partes = array_filter( $partes );
			if ( ! $partes ) {
							return '?';
			}
			$primera = mb_substr( reset( $partes ), 0, 1 );
			$ini = $primera;
			if ( count( $partes ) > 1 ) {
							$ini .= mb_substr( end( $partes ), 0, 1 );
			}
			return mb_strtoupper( $ini );
}
	}
