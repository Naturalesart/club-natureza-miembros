<?php
/**
 * Integración de Microsoft Clarity (análisis de comportamiento: heatmaps + grabaciones de sesión).
 * Project ID: yd8eet385y — proyecto "Club Natureza" en clarity.microsoft.com
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Seguridad: no acceso directo.
}
class CN_Clarity {
    const PROJECT_ID = 'yd8eet385y';
    public static function init() {
        add_action( 'wp_head', array( __CLASS__, 'inject_tracking_script' ) );
    }
    public static function inject_tracking_script() {
        ?>
        <script type="text/javascript">
            (function(c,l,a,r,i,t,y){
                c[a] = c[a] || function () { (c[a].q = c[a].q || []).push(arguments) };
                t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
                y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
            })(window, document, "clarity", "script", "<?php echo esc_js( self::PROJECT_ID ); ?>");
        </script>
        <?php
    }
}
CN_Clarity::init();
