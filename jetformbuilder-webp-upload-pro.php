<?php
/**
 * Plugin Name: JetFormBuilder WebP Upload PRO
 * Plugin URI: https://marrisonlab.com
 * Description: Converte automaticamente PNG/JPG in WebP negli allegati email di JetFormBuilder
 * Version: 5.0.0
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
        // Abilita il supporto WebP in WordPress
        add_filter( 'mime_types', [$this, 'enable_webp_mime'] );
        add_filter( 'upload_mimes', [$this, 'enable_webp_mime'] );
        
        // Intercetta wp_mail per convertire gli allegati
        add_filter( 'wp_mail', [$this, 'convert_mail_attachments'], 999 );
        
        // Converti il file quando viene caricato nella Media Library
        add_action( 'add_attachment', [$this, 'convert_media_attachment'], 10, 1 );
        
        // Aggiungi menu amministrazione
        add_action( 'admin_menu', [$this, 'add_admin_menu'] );
        add_action( 'admin_init', [$this, 'register_settings'] );
        
        // Aggiungi link nelle impostazioni del plugin
        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link'] );
        
        // Aggiungi script frontend per il loader
        add_action( 'wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts'] );
    }

    /**
     * Ottieni le impostazioni con valori di default
     */
    private function get_settings() {
        $defaults = [
            'quality' => 85,
            'max_width' => 1920,
            'enabled' => true,
            'replace_original' => false
        ];
        
        $settings = get_option( $this->option_name, $defaults );
        return wp_parse_args( $settings, $defaults );
    }

    /**
     * Abilita il supporto WebP in WordPress
     */
    public function enable_webp_mime( $mimes ) {
        $mimes['webp'] = 'image/webp';
        return $mimes;
    }

    /**
     * Carica script frontend per il loader
     */
    public function enqueue_frontend_scripts() {
        $settings = $this->get_settings();
        
        // Carica solo se la conversione è abilitata
        if ( ! $settings['enabled'] && ! $settings['replace_original'] ) {
            return;
        }

        wp_enqueue_style( 
            'jfb-webp-loader', 
            plugin_dir_url( __FILE__ ) . 'assets/loader.css',
            [],
            '5.0.0'
        );

        wp_enqueue_script( 
            'jfb-webp-loader', 
            plugin_dir_url( __FILE__ ) . 'assets/loader.js',
            ['jquery'],
            '5.0.0',
            true
        );

        wp_localize_script( 'jfb-webp-loader', 'jfbWebpSettings', [
            'replaceOriginal' => $settings['replace_original'],
            'messages' => [
                'converting' => __( 'Ottimizzazione immagine in corso...', 'jfb-webp-upload-pro' ),
                'uploading' => __( 'Caricamento...', 'jfb-webp-upload-pro' )
            ]
        ]);
    }

    /**
     * Converti l'attachment nella Media Library quando viene caricato
     */
    public function convert_media_attachment( $attachment_id ) {
        $settings = $this->get_settings();
        
        // Se "sostituisci originale" è disabilitato, non fare nulla
        if ( ! $settings['replace_original'] ) {
            return;
        }

        // Verifica se è un'immagine JPG/PNG
        $mime_type = get_post_mime_type( $attachment_id );
        if ( ! in_array( $mime_type, ['image/jpeg', 'image/png'], true ) ) {
            return;
        }

        // Ottieni il path del file
        $file_path = get_attached_file( $attachment_id );
        if ( ! file_exists( $file_path ) ) {
            return;
        }

        // Salva le dimensioni originali per statistiche
        $original_size = filesize( $file_path );

        // Converti il file
        $webp_path = $this->do_conversion( $file_path, $mime_type, $settings );
        
        if ( ! $webp_path || ! file_exists( $webp_path ) ) {
            return;
        }

        // Dimensione del file convertito
        $webp_size = filesize( $webp_path );
        $saved_space = $original_size - $webp_size;
        $percentage = round( ( $saved_space / $original_size ) * 100, 1 );

        // Aggiorna l'attachment con il nuovo file WebP
        update_attached_file( $attachment_id, $webp_path );
        
        // Aggiorna il mime type
        wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => 'image/webp'
        ]);

        // Rigenera i metadata per le miniature
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata( $attachment_id, $webp_path );
        wp_update_attachment_metadata( $attachment_id, $attach_data );

        // Elimina il file originale
        @unlink( $file_path );

        // Salva statistiche nel metadata
        update_post_meta( $attachment_id, '_webp_conversion_stats', [
            'original_size' => $original_size,
            'webp_size' => $webp_size,
            'saved_space' => $saved_space,
            'percentage' => $percentage,
            'converted_at' => current_time( 'mysql' )
        ]);
    }

    /**
     * Aggiungi link "Impostazioni" nella lista plugin
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="options-general.php?page=jfb-webp-settings">Impostazioni</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Aggiungi menu amministrazione
     */
    public function add_admin_menu() {
        add_options_page(
            'JetFormBuilder WebP PRO',
            'JFB WebP PRO',
            'manage_options',
            'jfb-webp-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Registra le impostazioni
     */
    public function register_settings() {
        register_setting( 'jfb_webp_settings_group', $this->option_name, [$this, 'sanitize_settings'] );
    }

    /**
     * Sanitizza le impostazioni
     */
    public function sanitize_settings( $input ) {
        $sanitized = [];
        
        // Qualità: da 1 a 100
        $sanitized['quality'] = isset( $input['quality'] ) ? absint( $input['quality'] ) : 85;
        $sanitized['quality'] = max( 1, min( 100, $sanitized['quality'] ) );
        
        // Larghezza massima: da 100 a 10000
        $sanitized['max_width'] = isset( $input['max_width'] ) ? absint( $input['max_width'] ) : 1920;
        $sanitized['max_width'] = max( 100, min( 10000, $sanitized['max_width'] ) );
        
        // Abilitato
        $sanitized['enabled'] = isset( $input['enabled'] ) && $input['enabled'] === '1';
        
        // Sostituisci originale
        $sanitized['replace_original'] = isset( $input['replace_original'] ) && $input['replace_original'] === '1';
        
        return $sanitized;
    }

    /**
     * Renderizza la pagina delle impostazioni
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->get_settings();
        $imagick = extension_loaded( 'imagick' );
        $gd = function_exists( 'imagewebp' );
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            
            <div class="notice notice-info" style="margin: 20px 0;">
                <p><strong>ℹ️ Librerie disponibili:</strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li>Imagick: <?php echo $imagick ? '✅ Disponibile' : '❌ Non disponibile'; ?></li>
                    <li>GD: <?php echo $gd ? '✅ Disponibile' : '❌ Non disponibile'; ?></li>
                </ul>
                <?php if ( ! $imagick && ! $gd ): ?>
                    <p style="color: #d63638;"><strong>⚠️ ATTENZIONE:</strong> Nessuna libreria di conversione disponibile! Il plugin non funzionerà.</p>
                <?php endif; ?>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'jfb_webp_settings_group' ); ?>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="enabled">Abilita conversione</label>
                            </th>
                            <td>
                                <label>
                                    <input 
                                        type="checkbox" 
                                        id="enabled" 
                                        name="<?php echo esc_attr( $this->option_name ); ?>[enabled]" 
                                        value="1"
                                        <?php checked( $settings['enabled'], true ); ?>
                                    />
                                    Converti automaticamente JPG/PNG in WebP negli allegati email
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="replace_original">Sostituisci file originale</label>
                            </th>
                            <td>
                                <label>
                                    <input 
                                        type="checkbox" 
                                        id="replace_original" 
                                        name="<?php echo esc_attr( $this->option_name ); ?>[replace_original]" 
                                        value="1"
                                        <?php checked( $settings['replace_original'], true ); ?>
                                    />
                                    Sostituisci il file originale nella Media Library con la versione WebP ottimizzata
                                </label>
                                <p class="description" style="color: #d63638;">
                                    ⚠️ <strong>ATTENZIONE:</strong> Se attivato, il file JPG/PNG originale verrà <strong>eliminato definitivamente</strong> e sostituito con WebP.<br>
                                    ✅ <strong>Vantaggio:</strong> Risparmio massiccio di spazio (fino al 80-90% per file di grandi dimensioni).<br>
                                    📊 Esempio: un file da 10MB diventerà circa 1-2MB.
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="quality">Qualità WebP</label>
                            </th>
                            <td>
                                <input 
                                    type="number" 
                                    id="quality" 
                                    name="<?php echo esc_attr( $this->option_name ); ?>[quality]" 
                                    value="<?php echo esc_attr( $settings['quality'] ); ?>" 
                                    min="1" 
                                    max="100" 
                                    class="small-text"
                                />
                                <p class="description">
                                    Valore da 1 a 100. Consigliato: 85 per un buon bilanciamento qualità/dimensione.<br>
                                    Più alto = qualità migliore ma file più grandi.
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="max_width">Larghezza massima (px)</label>
                            </th>
                            <td>
                                <input 
                                    type="number" 
                                    id="max_width" 
                                    name="<?php echo esc_attr( $this->option_name ); ?>[max_width]" 
                                    value="<?php echo esc_attr( $settings['max_width'] ); ?>" 
                                    min="100" 
                                    max="10000" 
                                    class="small-text"
                                />
                                <p class="description">
                                    Le immagini più larghe verranno ridimensionate mantenendo le proporzioni.<br>
                                    Valore 0 = nessun ridimensionamento. Consigliato: 1920px per email.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <?php submit_button( 'Salva impostazioni' ); ?>
            </form>

            <hr style="margin: 40px 0;">

            <h2>ℹ️ Informazioni</h2>
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 4px;">
                <h3>Come funziona questo plugin:</h3>
                <ol style="line-height: 1.8;">
                    <li>Quando un utente carica un'immagine JPG o PNG tramite JetFormBuilder</li>
                    <li><strong>Se "Sostituisci file originale" è DISABILITATO:</strong>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <li>Il file originale viene salvato nella Media Library</li>
                            <li>La conversione avviene solo per gli allegati email</li>
                            <li>Mantieni entrambe le versioni (originale + WebP per email)</li>
                        </ul>
                    </li>
                    <li><strong>Se "Sostituisci file originale" è ABILITATO:</strong>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <li>Il file viene immediatamente convertito in WebP e ridimensionato</li>
                            <li>Il file originale JPG/PNG viene eliminato</li>
                            <li>Nella Media Library rimane solo la versione WebP ottimizzata</li>
                            <li>⚠️ <strong>RISPARMIO SPAZIO:</strong> Riduzione fino al 90% per ogni file</li>
                        </ul>
                    </li>
                </ol>
                
                <h3 style="margin-top: 30px;">Vantaggi della sostituzione file originali:</h3>
                <ul style="list-style: disc; margin-left: 20px; line-height: 1.8;">
                    <li>💾 <strong>Risparmio enorme di spazio su disco</strong> - file da 10MB diventano 1-2MB</li>
                    <li>⚡ <strong>Caricamento più veloce</strong> della Media Library</li>
                    <li>📧 <strong>Email più leggere</strong> automaticamente</li>
                    <li>💰 <strong>Minori costi di hosting</strong> per lo storage</li>
                    <li>🌍 <strong>Ridotto impatto ambientale</strong> (meno dati = meno energia)</li>
                    <li>📊 <strong>Statistiche di conversione</strong> salvate per ogni file</li>
                </ul>

                <h3 style="margin-top: 30px;">Quando abilitare "Sostituisci file originale":</h3>
                <ul style="list-style: disc; margin-left: 20px; line-height: 1.8;">
                    <li>✅ Form pubblici aperti a molti utenti</li>
                    <li>✅ Upload di foto ad alta risoluzione (>5MB)</li>
                    <li>✅ Server con spazio limitato</li>
                    <li>✅ Non hai bisogno del file originale in alta qualità</li>
                    <li>❌ Evita se hai bisogno di stampare le foto in alta risoluzione</li>
                    <li>❌ Evita se potrebbero servirti i file originali in futuro</li>
                </ul>
            </div>
        </div>
        
        <style>
            .wrap h3 {
                margin-top: 20px;
                margin-bottom: 10px;
            }
        </style>
        <?php
    }

    /**
     * Intercetta wp_mail e converte gli allegati JPG/PNG in WebP
     */
    public function convert_mail_attachments( $args ) {
        $settings = $this->get_settings();
        
        // Se la conversione è disabilitata, passa oltre
        if ( ! $settings['enabled'] ) {
            return $args;
        }

        if ( empty( $args['attachments'] ) ) {
            return $args;
        }

        $new_attachments = [];
        
        foreach ( $args['attachments'] as $attachment ) {
            $converted = $this->maybe_convert_file( $attachment, $settings );
            $new_attachments[] = $converted ? $converted : $attachment;
        }
        
        $args['attachments'] = $new_attachments;
        return $args;
    }

    /**
     * Converte un singolo file se è JPG/PNG
     */
    private function maybe_convert_file( $file_path, $settings ) {
        if ( ! file_exists( $file_path ) ) {
            return false;
        }

        $mime_type = mime_content_type( $file_path );
        
        // Converti solo JPG e PNG
        if ( ! in_array( $mime_type, ['image/jpeg', 'image/png'], true ) ) {
            return false;
        }

        // Converti il file
        return $this->do_conversion( $file_path, $mime_type, $settings );
    }

    /**
     * Esegue la conversione vera e propria
     */
    private function do_conversion( $source_path, $mime_type, $settings ) {
        if ( ! file_exists( $source_path ) || ! is_readable( $source_path ) ) {
            return false;
        }

        // Prova prima con Imagick
        if ( extension_loaded( 'imagick' ) ) {
            $result = $this->convert_with_imagick( $source_path, $settings );
            if ( $result ) {
                return $result;
            }
        }
        
        // Fallback su GD
        if ( function_exists( 'imagewebp' ) ) {
            return $this->convert_with_gd( $source_path, $mime_type, $settings );
        }

        return false;
    }

    /**
     * Conversione con Imagick
     */
    private function convert_with_imagick( $file_path, $settings ) {
        try {
            $im = new Imagick( $file_path );
            
            // Ridimensiona se necessario
            if ( $settings['max_width'] > 0 ) {
                $width = $im->getImageWidth();
                $height = $im->getImageHeight();
                
                if ( $width > $settings['max_width'] ) {
                    $new_height = intval( ( $settings['max_width'] / $width ) * $height );
                    $im->resizeImage( $settings['max_width'], $new_height, Imagick::FILTER_LANCZOS, 1 );
                }
            }
            
            $im->setImageFormat( 'webp' );
            $im->setImageCompressionQuality( $settings['quality'] );
            
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
    private function convert_with_gd( $file_path, $mime_type, $settings ) {
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

        // Ridimensiona se necessario
        if ( $settings['max_width'] > 0 ) {
            $width = imagesx( $img );
            $height = imagesy( $img );
            
            if ( $width > $settings['max_width'] ) {
                $new_height = intval( ( $settings['max_width'] / $width ) * $height );
                $new_img = imagecreatetruecolor( $settings['max_width'], $new_height );
                
                // Preserva la trasparenza per PNG
                if ( $mime_type === 'image/png' ) {
                    imagealphablending( $new_img, false );
                    imagesavealpha( $new_img, true );
                    $transparent = imagecolorallocatealpha( $new_img, 0, 0, 0, 127 );
                    imagefill( $new_img, 0, 0, $transparent );
                }
                
                imagecopyresampled( $new_img, $img, 0, 0, 0, 0, $settings['max_width'], $new_height, $width, $height );
                imagedestroy( $img );
                $img = $new_img;
            }
        }

        $new_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file_path );
        $result = imagewebp( $img, $new_path, $settings['quality'] );
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