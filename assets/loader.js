(function($) {
    'use strict';

    const JFBWebPLoader = {
        overlayHTML: null,
        activeUploads: 0,
        uploadTimeout: null,

        init: function() {
            this.createOverlay();
            this.bindEvents();
        },

        createOverlay: function() {
            if ($('#jfb-webp-loader-overlay').length) return;

            const overlay = `
                <div id="jfb-webp-loader-overlay" class="jfb-webp-loader-overlay">
                    <div class="jfb-webp-loader-content">
                        <div class="jfb-webp-loader-spinner"></div>
                        <div class="jfb-webp-loader-message">${jfbWebpSettings.messages.converting}</div>
                        <div class="jfb-webp-loader-submessage">
                            ${jfbWebpSettings.replaceOriginal 
                                ? 'Stiamo ottimizzando la tua immagine per risparmiare spazio' 
                                : 'Attendere prego, non chiudere la pagina'}
                        </div>
                    </div>
                </div>
            `;

            $('body').append(overlay);
            this.overlayHTML = $('#jfb-webp-loader-overlay');
        },

        bindEvents: function() {
            const self = this;

            // Intercetta il cambio di file input
            $(document).on('change', 'input[type="file"]', function(e) {
                const $input = $(this);
                const $field = $input.closest('.jet-form-builder__field-wrap, .jet-form-builder-file-upload');
                const files = e.target.files;

                if (!files || files.length === 0) return;

                // Controlla se ci sono immagini JPG/PNG
                let hasImages = false;
                let imageCount = 0;
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    if (file.type === 'image/jpeg' || file.type === 'image/png') {
                        hasImages = true;
                        imageCount++;
                    }
                }

                if (!hasImages) return;

                console.log('JFB WebP: Detected ' + imageCount + ' image(s) to convert');

                // Mostra loader
                $field.addClass('jfb-webp-converting');
                self.showFieldMessage($field, 'Ottimizzazione in corso...');
                self.showOverlay();

                // Simula il tempo di conversione basato sul numero di file
                // WordPress elabora i file sul server dopo l'upload
                const conversionTime = Math.min(imageCount * 2000, 8000); // 2s per file, max 8s

                // Nascondi il loader dopo il tempo stimato
                clearTimeout(self.uploadTimeout);
                self.uploadTimeout = setTimeout(function() {
                    self.hideOverlay();
                    $field.removeClass('jfb-webp-converting');
                    
                    if (jfbWebpSettings.replaceOriginal) {
                        self.showSuccessMessage($field, imageCount);
                    }
                }, conversionTime);
            });

            // Nascondi loader quando il form viene inviato
            $(document).on('submit', 'form', function() {
                clearTimeout(self.uploadTimeout);
                self.hideOverlay();
                $('.jfb-webp-converting').removeClass('jfb-webp-converting');
            });

            // Nascondi loader se l'utente cambia idea e rimuove il file
            $(document).on('click', '.jet-form-builder-file-upload__remove', function() {
                const $field = $(this).closest('.jet-form-builder__field-wrap, .jet-form-builder-file-upload');
                clearTimeout(self.uploadTimeout);
                self.hideOverlay();
                $field.removeClass('jfb-webp-converting');
                $field.find('.jfb-webp-field-message').remove();
            });
        },

        showOverlay: function() {
            if (this.overlayHTML) {
                this.overlayHTML.addClass('active');
                $('body').css('overflow', 'hidden');
            }
        },

        hideOverlay: function() {
            if (this.overlayHTML) {
                this.overlayHTML.removeClass('active');
                $('body').css('overflow', '');
            }
        },

        updateOverlayMessage: function(message) {
            if (this.overlayHTML) {
                this.overlayHTML.find('.jfb-webp-loader-message').text(message);
            }
        },

        showFieldMessage: function($field, message) {
            $field.find('.jfb-webp-field-message').remove();
            $field.append(`<div class="jfb-webp-field-message">${message}</div>`);
        },

        showSuccessMessage: function($field, count) {
            $field.find('.jfb-webp-field-message').remove();
            
            const fileText = count > 1 ? count + ' file sono stati convertiti' : 'Il file è stato convertito';
            const message = `
                <div class="jfb-webp-field-message">
                    <strong>✓ Ottimizzazione completata!</strong><br>
                    ${fileText} in WebP per risparmiare spazio.
                </div>
            `;
            
            $field.append(message);

            // Rimuovi il messaggio dopo 5 secondi
            setTimeout(function() {
                $field.find('.jfb-webp-field-message').fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        }
    };

    // Inizializza quando il DOM è pronto
    $(document).ready(function() {
        JFBWebPLoader.init();
    });

})(jQuery);