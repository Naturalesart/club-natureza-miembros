/* Club Natureza Miembros — interacciones del front (reproductor).
 * Sin dependencias. Se encola solo en las páginas del club. */
( function () {
	'use strict';

	// Tras un login exitoso el dashboard se renderiza en la respuesta del POST
	// (sin redirect, para no colgar la navegación en HTTP/3). Reemplazamos la
	// entrada de historial por un GET limpio de la misma URL, así un F5 no
	// dispara el aviso "¿reenviar formulario?" del navegador.
	try {
		if ( document.querySelector( '.cn-wrap[data-cn-fresh-login]' ) && window.history && history.replaceState ) {
			history.replaceState( null, document.title, window.location.pathname + window.location.search );
		}
	} catch ( err ) {}

	// Adapta el reproductor a la orientación real del video (vertical u horizontal),
	// detectándola desde la miniatura. Un video vertical de celular así llena el
	// player en lugar de quedar chiquito entre franjas negras (que obligaban a
	// abrirlo en otra ventana para verlo).
	try {
		var rep = document.getElementById( 'cn-reproductor' );
		var thumbUrl = rep && rep.getAttribute( 'data-cn-thumb' );
		var embed = rep && rep.querySelector( '.cn-embed' );
		if ( thumbUrl && embed ) {
			var probe = new Image();
			probe.onload = function () {
				var w = probe.naturalWidth, h = probe.naturalHeight;
				if ( ! w || ! h ) { return; }
				embed.style.setProperty( 'padding-top', '0', 'important' );
				embed.style.setProperty( 'aspect-ratio', w + ' / ' + h, 'important' );
				if ( h > w ) {
					// Vertical: se limita por alto y se centra.
					embed.style.setProperty( 'height', '78vh', 'important' );
					embed.style.setProperty( 'max-height', '640px', 'important' );
					embed.style.setProperty( 'width', 'auto', 'important' );
					embed.style.setProperty( 'margin', '0 auto', 'important' );
				} else {
					// Horizontal o cuadrado: ocupa el ancho disponible.
					embed.style.setProperty( 'width', '100%', 'important' );
					embed.style.setProperty( 'height', 'auto', 'important' );
				}
			};
			probe.src = thumbUrl;
		}
	} catch ( err2 ) {}

	function pedirFullscreen( el ) {
		if ( ! el ) {
			return false;
		}
		if ( el.requestFullscreen ) { el.requestFullscreen(); return true; }
		if ( el.webkitRequestFullscreen ) { el.webkitRequestFullscreen(); return true; }
		if ( el.msRequestFullscreen ) { el.msRequestFullscreen(); return true; }
		return false;
	}

	// Delegación: un solo listener para el botón "Pantalla completa".
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-cn-fs]' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();

		var rep = document.getElementById( 'cn-reproductor' );
		if ( ! rep ) {
			return;
		}

		var caja   = rep.querySelector( '.cn-embed' );
		var iframe = rep.querySelector( 'iframe' );

		// 1) Fullscreen del contenedor (funciona en Android y escritorio, mantiene
		//    el video llenando la pantalla). 2) Si el navegador no lo permite sobre
		//    el div (típico en iOS), probamos sobre el iframe. 3) Último recurso en
		//    iOS: abrir el video en una pestaña nueva a pantalla completa nativa.
		if ( pedirFullscreen( caja ) ) {
			return;
		}
		if ( pedirFullscreen( iframe ) ) {
			return;
		}
		if ( iframe && iframe.webkitEnterFullscreen ) {
			iframe.webkitEnterFullscreen();
			return;
		}
		if ( iframe && iframe.src ) {
			window.open( iframe.src, '_blank', 'noopener' );
		}
	}, false );
} )();
