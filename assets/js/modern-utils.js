/**
 * Utilidades modernas para Mi Integración API
 * Este archivo contiene funciones modernas utilizadas en todo el plugin
 */
(function($) {
    'use strict';

    // Objeto principal para funciones modernas
    const miApiModern = {
        /**
         * Función debounce para evitar múltiples ejecuciones de una función en un tiempo determinado
         * 
         * @param {Function} func La función a ejecutar
         * @param {Number} wait Tiempo de espera en milisegundos
         * @returns {Function} Función con debounce
         */
        debounce: function(func, wait) {
            let timeout;
            return function() {
                const context = this;
                const args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(context, args), wait);
            };
        },

        /**
         * Valida un formulario HTML con validaciones completas
         * 
         * @param {jQuery} $form Elemento jQuery del formulario
         * @returns {Boolean} True si el formulario es válido
         */
        validateForm: function($form) {
            if (!$form || !$form.length) {
                return false;
            }

            let isValid = true;
            
            // Limpiar errores previos (ambos tipos de contenedores)
            $form.find('.mi-api-field-error').remove();
            $form.find('.error-message').remove();
            $form.find('.error').removeClass('error');
            
            // Obtener o crear contenedor de errores
            let $errorContainer = $form.find('.form-errors');
            if (!$errorContainer.length) {
                $errorContainer = $('<div class="form-errors"></div>');
                $form.prepend($errorContainer);
            }
            $errorContainer.empty();

            // Validar campos requeridos (soporte para ambos tipos)
            $form.find('[required], [data-required="true"]').each(function() {
                const $field = $(this);
                const value = $field.val();
                const fieldName = $field.attr('name') || $field.attr('id') || 'Campo';
                
                if (!value || value.trim() === '') {
                    isValid = false;
                    
                    // Validación nativa
                    if (this.setCustomValidity) {
                        this.setCustomValidity('Este campo es requerido');
                    }
                    
                    // Mostrar error visual
                    $field.addClass('error');
                    
                    // Mensaje personalizable
                    const errorMsg = $field.data('error-message') || `${fieldName} es requerido`;
                    
                    // Mostrar error en contenedor y después del campo
                    $errorContainer.append(`<div class="error-message">${errorMsg}</div>`);
                    $field.after(`<div class="mi-api-field-error">${errorMsg}</div>`);
                } else {
                    if (this.setCustomValidity) {
                        this.setCustomValidity('');
                    }
                    $field.removeClass('error');
                }
            });

            // Validar campos de email
            $form.find('input[type="email"]').each(function() {
                const $field = $(this);
                const value = $field.val();
                
                if (value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                    isValid = false;
                    
                    // Validación nativa
                    if (this.setCustomValidity) {
                        this.setCustomValidity('Formato de email inválido');
                    }
                    
                    $field.addClass('error');
                    
                    // Mensaje personalizable
                    const errorMsg = $field.data('email-error') || 'Formato de email inválido';
                    
                    // Mostrar error en contenedor y después del campo
                    $errorContainer.append(`<div class="error-message">${errorMsg}</div>`);
                    $field.after(`<div class="mi-api-field-error">${errorMsg}</div>`);
                } else {
                    if (this.setCustomValidity) {
                        this.setCustomValidity('');
                    }
                    $field.removeClass('error');
                }
            });

            // Validar campos numéricos
            $form.find('input[type="number"]').each(function() {
                const $field = $(this);
                const value = $field.val();
                const min = $field.attr('min');
                const max = $field.attr('max');
                
                if (value !== '') {
                    const numValue = parseFloat(value);
                    let errorMsg = '';
                    
                    if (isNaN(numValue)) {
                        isValid = false;
                        errorMsg = 'Debe ser un número válido';
                    } else if (min && numValue < parseFloat(min)) {
                        isValid = false;
                        errorMsg = `El valor mínimo es ${min}`;
                    } else if (max && numValue > parseFloat(max)) {
                        isValid = false;
                        errorMsg = `El valor máximo es ${max}`;
                    }
                    
                    if (errorMsg) {
                        // Validación nativa
                        if (this.setCustomValidity) {
                            this.setCustomValidity(errorMsg);
                        }
                        
                        $field.addClass('error');
                        
                        // Mostrar error en contenedor y después del campo
                        $errorContainer.append(`<div class="error-message">${errorMsg}</div>`);
                        $field.after(`<div class="mi-api-field-error">${errorMsg}</div>`);
                    } else {
                        if (this.setCustomValidity) {
                            this.setCustomValidity('');
                        }
                        $field.removeClass('error');
                    }
                }
            });

            return isValid;
        },

        /**
         * Cliente API para peticiones AJAX modernas usando Promesas
         */
        apiClient: {
            /**
             * Realiza una petición POST
             * 
             * @param {String} endpoint URL o acción AJAX
             * @param {Object} data Datos a enviar
             * @returns {Promise} Promesa con la respuesta
             */
            post: function(endpoint, data = {}) {
                return new Promise((resolve, reject) => {
                    // Determinar si es una acción AJAX o una URL completa
                    const url = endpoint.includes('http') ? endpoint : ajaxurl;
                    
                    // Si es una acción AJAX, añadir la acción a los datos
                    if (!endpoint.includes('http')) {
                        data.action = endpoint;
                    }
                    
                    // Añadir nonce si está disponible
                    if (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard.nonce) {
                        data.nonce = miIntegracionApiDashboard.nonce;
                    }
                    
                    $.ajax({
                        url: url,
                        type: 'POST',
                        data: data,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                resolve(response.data);
                            } else {
                                reject({
                                    message: response.data?.message || 'Error desconocido',
                                    code: response.data?.code || 'unknown_error',
                                    data: response.data
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            let errorMessage = 'Error de conexión';
                            let errorData = {};
                            
                            try {
                                if (xhr.responseJSON) {
                                    errorMessage = xhr.responseJSON.message || error;
                                    errorData = xhr.responseJSON.data || {};
                                } else if (xhr.responseText) {
                                    const parsedResponse = JSON.parse(xhr.responseText);
                                    errorMessage = parsedResponse.message || error;
                                    errorData = parsedResponse.data || {};
                                }
                            } catch (e) {
                                console.warn('Error al parsear respuesta de error:', e);
                            }
                            
                            reject({
                                message: errorMessage,
                                code: xhr.status,
                                data: errorData,
                                xhr: xhr
                            });
                        }
                    });
                });
            },
            
            /**
             * Realiza una petición GET
             * 
             * @param {String} endpoint URL o acción AJAX
             * @param {Object} params Parámetros de la consulta
             * @returns {Promise} Promesa con la respuesta
             */
            get: function(endpoint, params = {}) {
                return new Promise((resolve, reject) => {
                    const url = endpoint.includes('http') ? endpoint : ajaxurl;
                    
                    if (!endpoint.includes('http')) {
                        params.action = endpoint;
                    }
                    
                    if (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard.nonce) {
                        params.nonce = miIntegracionApiDashboard.nonce;
                    }
                    
                    $.ajax({
                        url: url,
                        type: 'GET',
                        data: params,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                resolve(response.data);
                            } else {
                                reject({
                                    message: response.data?.message || 'Error desconocido',
                                    code: response.data?.code || 'unknown_error',
                                    data: response.data
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            reject({
                                message: error || 'Error de conexión',
                                code: xhr.status,
                                xhr: xhr
                            });
                        }
                    });
                });
            }
        },
        
        
        /**
         * Sistema de notificaciones toast moderno
         */
        toast: {
            /**
             * Muestra una notificación toast de éxito
             * 
             * @param {String} message Mensaje a mostrar
             */
            success: function(message) {
                this.show({
                    type: 'success',
                    title: 'Éxito',
                    message: message
                });
            },
            
            /**
             * Muestra una notificación toast de error
             * 
             * @param {String} message Mensaje a mostrar
             */
            error: function(message) {
                this.show({
                    type: 'error',
                    title: 'Error',
                    message: message
                });
            },
            
            /**
             * Muestra una notificación toast de advertencia
             * 
             * @param {String} message Mensaje a mostrar
             */
            warning: function(message) {
                this.show({
                    type: 'warning',
                    title: 'Advertencia',
                    message: message
                });
            },
            
            /**
             * Muestra una notificación toast de información
             * 
             * @param {String} message Mensaje a mostrar
             */
            info: function(message) {
                this.show({
                    type: 'info',
                    title: 'Información',
                    message: message
                });
            },
            
            /**
             * Muestra una notificación toast personalizada
             * 
             * @param {Object} options Opciones de la notificación
             */
            show: function(options) {
                const defaults = {
                    type: 'info',
                    title: '',
                    message: '',
                    duration: 4000,
                    position: 'top-right'
                };
                
                const settings = $.extend({}, defaults, options);
                
                // Colores según tipo
                const colors = {
                    success: '#22c55e',
                    error: '#ef4444',
                    warning: '#f59e0b',
                    info: '#3b82f6'
                };
                
                // Iconos según tipo
                const icons = {
                    success: '✅',
                    error: '❌',
                    warning: '⚠️',
                    info: 'ℹ️'
                };
                
                // Crear elemento toast
                const $toast = $(`
                    <div class="mi-api-toast mi-api-toast-${settings.type}" style="
                        position: fixed;
                        ${settings.position.includes('top') ? 'top: 32px;' : 'bottom: 32px;'}
                        ${settings.position.includes('right') ? 'right: 20px;' : 'left: 20px;'}
                        background: white;
                        padding: 1rem 1.5rem;
                        border-radius: 0.5rem;
                        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
                        border-left: 4px solid ${colors[settings.type]};
                        z-index: 9999;
                        max-width: 400px;
                        transform: translateX(${settings.position.includes('right') ? '100%' : '-100%'});
                        transition: transform 0.3s ease;
                    ">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <span class="mi-api-toast-icon" style="font-size: 1.25rem;">
                                ${icons[settings.type]}
                            </span>
                            <div class="mi-api-toast-content">
                                ${settings.title ? `<div class="mi-api-toast-title" style="font-weight: 600; margin-bottom: 0.25rem;">${settings.title}</div>` : ''}
                                <div class="mi-api-toast-message">${settings.message}</div>
                            </div>
                            <button class="mi-api-toast-close" style="
                                background: none;
                                border: none;
                                font-size: 1.25rem;
                                cursor: pointer;
                                color: #9ca3af;
                                margin-left: auto;
                            ">×</button>
                        </div>
                    </div>
                `);
                
                // Añadir al DOM
                $('body').append($toast);
                
                // Animar entrada
                setTimeout(() => {
                    $toast.css('transform', 'translateX(0)');
                }, 10);
                
                // Auto cerrar
                if (settings.duration) {
                    setTimeout(() => {
                        $toast.css('transform', `translateX(${settings.position.includes('right') ? '100%' : '-100%'})`);
                        setTimeout(() => $toast.remove(), 300);
                    }, settings.duration);
                }
                
                // Cerrar al hacer clic
                $toast.find('.mi-api-toast-close').on('click', () => {
                    $toast.css('transform', `translateX(${settings.position.includes('right') ? '100%' : '-100%'})`);
                    setTimeout(() => $toast.remove(), 300);
                });
            }
        },
        
        /**
         * Utilidad para formatear JSON para visualización
         * 
         * @param {Object} obj Objeto a formatear
         * @returns {String} HTML con el JSON formateado
         */
        formatJSON: function(obj) {
            if (!obj) return '';
            
            try {
                const json = JSON.stringify(obj, null, 2);
                return '<pre>' + json.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</pre>';
            } catch (e) {
                return 'Error al formatear JSON: ' + e.message;
            }
        }
    };
    
    // Exponer en el ámbito global
    window.miApiModern = miApiModern;

})(jQuery);
