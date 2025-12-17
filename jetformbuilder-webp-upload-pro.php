<?php
/**
 * Plugin Name: JetFormBuilder WebP Upload PRO
 * Plugin URI: https://marrisonlab.com
 * Description: Automatically converts JPG/PNG to WebP for JetFormBuilder email attachments and Media Library uploads. Includes visual loader.
 * Version: 6.0.0
 * Author: Angelo Marra
 * Text Domain: jfb-webp-upload-pro
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class JFB_WebP_Converter {

    private static $instance = null;
    private $option_name = 'jfb_webp_settings';

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Core Logic
        add_filter( 'mime_types', [$this, 'enable_webp_mime'] );
        add_filter( 'upload_mimes', [$this, 'enable_webp_mime'] );
        add_filter( 'wp_mail', [$this, 'convert_mail_attachments'], 999 );
        add_filter( 'wp_handle_upload', [$this, 'handle_upload_conversion'] );

        // Admin UI
        add_action( 'admin_menu', [$this, 'add_admin_menu'] );
        add_action( 'admin_init', [$this, 'register_settings'] );
        add_action( 'admin_head', [$this, 'admin_styles'] );
        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link'] );

        // Frontend Loader (Inline JS/CSS to guarantee visibility)
        add_action( 'wp_footer', [$this, 'inject_frontend_loader'], 100 );
    }

    /* =====================================================
     * FRONTEND LOADER (CSS & JS INLINE)
     * ===================================================== */
    public function inject_frontend_loader() {
        $settings = $this->get_settings();
        // Solo se abilitato
        if( ! $settings['enabled'] ) return;

        $replace_txt = $settings['replace_original'] ? 'Optimizing your image to save space.' : 'Processing your image...';
        ?>
        <style>
            /* LOADER CSS */
            #jfb-webp-loader-overlay {
                display: none !important; /* Hidden by default */
                position: fixed !important;
                top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(255, 255, 255, 0.95) !important; /* Quasi opaco */
                z-index: 2147483647 !important; /* Max Z-Index possibile */
                align-items: center;
                justify-content: center;
                flex-direction: column;
            }
            #jfb-webp-loader-overlay.active {
                display: flex !important; /* Force flex on active */
            }
            .jfb-webp-spinner {
                width: 50px; height: 50px; margin-bottom: 20px;
                border: 5px solid #f3f3f3; border-top: 5px solid #2271b1; border-radius: 50%;
                animation: jfb-spin 1s linear infinite;
            }
            @keyframes jfb-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            .jfb-loader-text { font-family: sans-serif; font-size: 18px; color: #333; font-weight: 600; }
            .jfb-loader-sub { font-family: sans-serif; font-size: 14px; color: #666; margin-top: 5px; }
            
            /* FIELD MESSAGE */
            .jfb-webp-msg {
                margin-top: 5px; padding: 8px 12px; font-size: 13px; border-radius: 4px; font-family: sans-serif;
            }
            .jfb-webp-msg.converting { background: #e7f5ff; color: #0c5460; border: 1px solid #b8daff; }
            .jfb-webp-msg.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        </style>

        <div id="jfb-webp-loader-overlay">
            <div class="jfb-webp-spinner"></div>
            <div class="jfb-loader-text">Please wait...</div>
            <div class="jfb-loader-sub"><?php echo esc_html($replace_txt); ?></div>
        </div>

        <script>
        (function($) {
            $(document).ready(function() {
                const overlay = $('#jfb-webp-loader-overlay');
                let uploadTimeout = null;

                function showLoader(field) {
                    overlay.addClass('active');
                    if(field) {
                        field.find('.jfb-webp-msg').remove();
                        field.append('<div class="jfb-webp-msg converting">Optimizing image...</div>');
                    }
                }

                function hideLoader(field, success = false) {
                    overlay.removeClass('active');
                    if(field) {
                        field.find('.jfb-webp-msg').remove();
                        if(success) {
                            field.append('<div class="jfb-webp-msg success">✓ Image optimized (WebP)</div>');
                            setTimeout(function(){ field.find('.jfb-webp-msg').fadeOut(); }, 5000);
                        }
                    }
                }

                // 1. INPUT FILE CHANGE
                $(document).on('change', 'input[type="file"]', function(e) {
                    const files = e.target.files;
                    let hasImg = false;
                    if(files && files.length) {
                        for(let i=0; i<files.length; i++) {
                            if(files[i].type.match('image/jpeg') || files[i].type.match('image/png')) hasImg = true;
                        }
                    }
                    if(!hasImg) return;

                    const field = $(this).closest('.jet-form-builder__field-wrap, .jet-form-builder-file-upload');
                    showLoader(field);

                    // Timeout simulato per nascondere il loader (fallback)
                    clearTimeout(uploadTimeout);
                    uploadTimeout = setTimeout(function() {
                        hideLoader(field, true);
                    }, 4000); // 4 secondi fallback
                });

                // 2. NETWORK PATCH (XHR) per catturare la fine vera dell'upload
                const origSend = XMLHttpRequest.prototype.send;
                XMLHttpRequest.prototype.send = function() {
                    this.addEventListener('loadend', function() {
                        // Se il loader è attivo, lo chiudiamo
                        if(overlay.hasClass('active')) {
                             // Ritardo minimo per fluidità
                             setTimeout(function() { 
                                 overlay.removeClass('active');
                                 $('.jfb-webp-msg.converting').removeClass('converting').addClass('success').text('✓ Image optimized');
                             }, 500);
                        }
                    });
                    return origSend.apply(this, arguments);
                };

                // 3. FORM SUBMIT
                $(document).on('submit', 'form', function() {
                    // Nascondi loader se l'utente invia
                    setTimeout(function(){ overlay.removeClass('active'); }, 2000);
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /* =====================================================
     * CORE LOGIC (Server Side)
     * ===================================================== */

    private function get_settings() {
        $defaults = ['enabled' => true, 'replace_original' => false, 'quality' => 85, 'max_width' => 1920];
        return wp_parse_args( get_option( $this->option_name, [] ), $defaults );
    }

    public function register_settings() {
        register_setting( 'jfb_webp_settings_group', $this->option_name );
    }

    public function enable_webp_mime( $mimes ) {
        $mimes['webp'] = 'image/webp';
        return $mimes;
    }

    // Email Attachment Logic
    public function convert_mail_attachments( $args ) {
        $settings = $this->get_settings();
        if ( ! $settings['enabled'] || empty( $args['attachments'] ) ) return $args;

        $new = [];
        foreach ( $args['attachments'] as $file ) {
            $converted = $this->maybe_convert_file( $file, $settings );
            $new[] = $converted ?: $file;
        }
        $args['attachments'] = $new;
        return $args;
    }

    // Media Upload Logic
    public function handle_upload_conversion( $file ) {
        if ( isset( $file['error'] ) && ! empty( $file['error'] ) ) return $file;
        $settings = $this->get_settings();

        if ( ! $settings['enabled'] || ! $settings['replace_original'] ) return $file;
        if ( ! in_array( $file['type'], ['image/jpeg', 'image/png'], true ) ) return $file;
        if ( strpos( $file['file'], '.webp' ) !== false ) return $file;

        $new_path = $this->do_conversion( $file['file'], $file['type'], $settings );

        if ( $new_path && file_exists( $new_path ) ) {
            @unlink( $file['file'] );
            $file['file'] = $new_path;
            $file['url']  = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file['url'] );
            $file['type'] = 'image/webp';
        }
        return $file;
    }

    private function maybe_convert_file( $file, $settings ) {
        if ( ! file_exists( $file ) ) return false;
        $mime = mime_content_type( $file );
        if ( ! in_array( $mime, ['image/jpeg', 'image/png'], true ) ) return false;
        return $this->do_conversion( $file, $mime, $settings );
    }

    private function do_conversion( $file, $mime, $settings ) {
        if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
            return $this->convert_imagick( $file, $settings );
        }
        if ( function_exists( 'imagewebp' ) ) {
            return $this->convert_gd( $file, $mime, $settings );
        }
        return false;
    }

    private function convert_imagick( $file, $settings ) {
        try {
            $im = new Imagick( $file );
            if ( $settings['max_width'] > 0 ) {
                $w = $im->getImageWidth();
                if ( $w > $settings['max_width'] ) {
                    $im->resizeImage( $settings['max_width'], 0, Imagick::FILTER_LANCZOS, 1 );
                }
            }
            $im->setImageFormat( 'webp' );
            $im->setImageCompressionQuality( $settings['quality'] );
            $new = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file );
            $im->writeImage( $new );
            $im->clear(); $im->destroy();
            return file_exists( $new ) ? $new : false;
        } catch ( Exception $e ) { return false; }
    }

    private function convert_gd( $file, $mime, $settings ) {
        $img = $mime === 'image/png' ? imagecreatefrompng( $file ) : imagecreatefromjpeg( $file );
        if ( ! $img ) return false;
        if ( $mime === 'image/png' ) {
            imagepalettetotruecolor( $img ); imagealphablending( $img, true ); imagesavealpha( $img, true );
        }
        if ( $settings['max_width'] > 0 ) {
            $w = imagesx( $img );
            if ( $w > $settings['max_width'] ) {
                $ratio = $settings['max_width'] / $w;
                $h = imagesy( $img );
                $new_img = imagecreatetruecolor( $settings['max_width'], intval( $h * $ratio ) );
                if ( $mime === 'image/png' ) {
                     imagealphablending($new_img, false); imagesavealpha($new_img, true);
                }
                imagecopyresampled( $new_img, $img, 0, 0, 0, 0, imagesx( $new_img ), imagesy( $new_img ), $w, $h );
                imagedestroy( $img ); $img = $new_img;
            }
        }
        $new = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file );
        imagewebp( $img, $new, $settings['quality'] );
        imagedestroy( $img );
        return file_exists( $new ) ? $new : false;
    }

    /* =====================================================
     * ADMIN UI (ENGLISH)
     * ===================================================== */

    public function add_admin_menu() {
        add_options_page( 'JetFormBuilder WebP PRO', 'JFB WebP PRO', 'manage_options', 'jfb-webp-settings', [$this, 'render_settings_page'] );
    }

    public function add_settings_link( $links ) {
        array_unshift( $links, '<a href="options-general.php?page=jfb-webp-settings">Settings</a>' );
        return $links;
    }

    public function admin_styles() {
        ?>
        <style>
            .jfb-webp-wrap { max-width: 750px; margin-top: 30px; }
            .jfb-card { background: #fff; padding: 25px; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-radius: 4px; margin-bottom: 25px; }
            .jfb-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px; }
            .jfb-header h1 { margin: 0; font-size: 24px; display: flex; align-items: center; gap: 10px; }
            .jfb-status { font-weight: 600; padding: 4px 10px; border-radius: 4px; font-size: 11px; text-transform: uppercase; }
            .jfb-ok { background: #edfaef; color: #124c16; border: 1px solid #c3e6cb; }
            .jfb-ko { background: #fbeaea; color: #d63638; border: 1px solid #ebccd1; }
            .jfb-footer { text-align: center; margin-top: 30px; font-size: 13px; color: #666; border-top: 1px solid #ddd; padding-top: 20px; }
            .dashicons-format-image { font-size: 28px; width: 28px; height: 28px; color: #2271b1; }
        </style>
        <?php
    }

    public function render_settings_page() {
        $s = $this->get_settings();
        $imagick = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
        $gd = function_exists( 'imagewebp' );
        ?>
        <div class="wrap jfb-webp-wrap">
            <div class="jfb-card">
                <div class="jfb-header">
                    <h1><span class="dashicons dashicons-format-image"></span> JetFormBuilder WebP PRO</h1>
                    <div>
                        <?php if($imagick): ?><span class="jfb-status jfb-ok">Imagick Active</span>
                        <?php elseif($gd): ?><span class="jfb-status jfb-ok">GD Active</span>
                        <?php else: ?><span class="jfb-status jfb-ko">No Library Found</span><?php endif; ?>
                    </div>
                </div>

                <form method="post" action="options.php">
                    <?php settings_fields( 'jfb_webp_settings_group' ); ?>
                    <table class="form-table">
                        <tr>
                            <th>Enable Plugin</th>
                            <td>
                                <label><input type="checkbox" name="<?= $this->option_name ?>[enabled]" value="1" <?= checked( $s['enabled'], true, false ) ?>> Enable conversion</label>
                            </td>
                        </tr>
                        <tr>
                            <th>Media Library</th>
                            <td>
                                <label><input type="checkbox" name="<?= $this->option_name ?>[replace_original]" value="1" <?= checked( $s['replace_original'], true, false ) ?>> Replace original file</label>
                                <p class="description">If checked, original JPG/PNG files in Media Library will be deleted and replaced with WebP to save space.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>WebP Quality (1-100)</th>
                            <td><input type="number" name="<?= $this->option_name ?>[quality]" value="<?= esc_attr( $s['quality'] ) ?>" min="1" max="100" class="small-text"></td>
                        </tr>
                        <tr>
                            <th>Max Width (px)</th>
                            <td>
                                <input type="number" name="<?= $this->option_name ?>[max_width]" value="<?= esc_attr( $s['max_width'] ) ?>" class="regular-text">
                                <p class="description">Set to 0 to disable resizing.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'Save Settings', 'primary large' ); ?>
                </form>
            </div>

            <div class="jfb-footer">
                <p>Do you find this plugin useful?</p>
                <form action="https://www.paypal.com/donate" method="post" target="_blank">
                    <input type="hidden" name="business" value="angelomarra80@gmail.com">
                    <input type="hidden" name="no_recurring" value="0">
                    <input type="hidden" name="currency_code" value="EUR">
                    <input type="submit" class="button button-primary" value="Buy me a coffee with PayPal ☕">
                </form>
            </div>
        </div>
        <?php
    }
}

add_action( 'plugins_loaded', function() { JFB_WebP_Converter::instance(); }, 20 );