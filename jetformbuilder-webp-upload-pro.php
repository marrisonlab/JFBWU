<?php
/**
 * Plugin Name: JetFormBuilder WebP Upload PRO
 * Plugin URI: https://marrisonlab.com
 * Description: Converte automaticamente PNG/JPG in WebP negli allegati email di JetFormBuilder
 * Version: 4.1.0
 * Author: Angelo Marra
 * Text Domain: jfb-webp-upload-pro
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class JFB_WebP_Converter {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Abilita il supporto WebP in WordPress
        add_filter( 'mime_types', [$this, 'enable_webp_mime'] );
        add_filter( 'upload_mimes', [$this, 'enable_webp_mime'] );
        
        // Intercetta wp_mail per convertire gli allegati
        add_filter( 'wp_mail', [$this, 'convert_mail_attachments'], 999 );
    }

    /**
     * Abilita il supporto WebP in WordPress
     */
    public function enable_webp_mime( $mimes ) {
        $mimes['webp'] = 'image/webp';
        return $mimes;
    }

    /**
     * Intercetta wp_mail e converte gli allegati JPG/PNG in WebP
     */
    public function convert_mail_attachments( $args ) {
        if ( empty( $args['attachments'] ) ) {
            return $args;
        }

        $new_attachments = [];
        
        foreach ( $args['attachments'] as $attachment ) {
            $converted = $this->maybe_convert_file( $attachment );
            $new_attachments[] = $converted ? $converted : $attachment;
        }
        
        $args['attachments'] = $new_attachments;
        return $args;
    }

    /**
     * Converte un singolo file se è JPG/PNG
     */
    private function maybe_convert_file( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return false;
        }

        $mime_type = mime_content_type( $file_path );
        
        // Converti solo JPG e PNG
        if ( ! in_array( $mime_type, ['image/jpeg', 'image/png'], true ) ) {
            return false;
        }

        // Converti il file
        return $this->do_conversion( $file_path, $mime_type );
    }

    /**
     * Esegue la conversione vera e propria
     */
    private function do_conversion( $source_path, $mime_type ) {
        if ( ! file_exists( $source_path ) || ! is_readable( $source_path ) ) {
            return false;
        }

        // Prova prima con Imagick
        if ( extension_loaded( 'imagick' ) ) {
            $result = $this->convert_with_imagick( $source_path );
            if ( $result ) {
                return $result;
            }
        }
        
        // Fallback su GD
        if ( function_exists( 'imagewebp' ) ) {
            return $this->convert_with_gd( $source_path, $mime_type );
        }

        return false;
    }

    /**
     * Conversione con Imagick
     */
    private function convert_with_imagick( $file_path ) {
        try {
            $im = new Imagick( $file_path );
            $im->setImageFormat( 'webp' );
            $im->setImageCompressionQuality( 85 );
            
            $new_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file_path );
            
            if ( $im->writeImage( $new_path ) ) {
                $im->clear();
                $im->destroy();
                
                if ( file_exists( $new_path ) ) {
                    return $new_path;
                }
            }
            
            $im->clear();
            $im->destroy();
        } catch ( Exception $e ) {
            // Silenzioso in produzione
        }

        return false;
    }

    /**
     * Conversione con GD
     */
    private function convert_with_gd( $file_path, $mime_type ) {
        $img = false;

        if ( $mime_type === 'image/jpeg' ) {
            $img = @imagecreatefromjpeg( $file_path );
        } elseif ( $mime_type === 'image/png' ) {
            $img = @imagecreatefrompng( $file_path );
            if ( $img ) {
                imagealphablending( $img, false );
                imagesavealpha( $img, true );
            }
        }

        if ( ! $img ) {
            return false;
        }

        $new_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file_path );
        $result = imagewebp( $img, $new_path, 85 );
        imagedestroy( $img );

        return ( $result && file_exists( $new_path ) ) ? $new_path : false;
    }
}

// Avvia il plugin
add_action( 'plugins_loaded', function() {
    if ( defined( 'JET_FORM_BUILDER_VERSION' ) ) {
        JFB_WebP_Converter::instance();
    }
}, 20 );