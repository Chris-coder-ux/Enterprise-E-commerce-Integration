/**
 * Mi Integración API - Utilidades JavaScript modernas
 * 
 * Proporciona un conjunto completo de utilidades modernas para el plugin
 * Mi Integración API, incluyendo manejo de DOM, validación de formularios,
 * sistema de notificaciones, utilidades de fecha y más.
 * 
 * @fileoverview Utilidades JavaScript modernas para Mi Integración API
 * @version 1.0.0
 * @author Mi Integración API
 * @since 2025-01-31
 * @requires jQuery
 */

// Namespace seguro para nuestras utilidades
window.MiIntegracionAPI = window.MiIntegracionAPI || {};

// Módulo de utilidades
MiIntegracionAPI.utils = (function($) {
    'use strict';
    
    // Cache de selectores DOM
    const cache = {};
    
    // Configuración por defecto
    const config = {
        animationDuration: 300,
        debounceTime: 250,
        throttleTime: 100
    };
    
    /**
     * Obtiene y cachea elementos del DOM
     * 
     * Implementa un sistema de caché para elementos DOM frecuentemente
     * utilizados, mejorando el rendimiento al evitar búsquedas repetidas.
     * 
     * @function
     * @name getElement
     * @param {string} selector - Selector CSS para el elemento
     * @returns {jQuery} Elemento jQuery del DOM
     * @since 1.0.0
     * @example
     * const $button = getElement('#submit-button');
     */
    function getElement(selector) {
        if (!cache[selector]) {
            cache[selector] = $(selector);
        }
        return cache[selector];
    }
    
    /**
     * Limpia el caché de elementos
     * @param {string} [selector] - Selector opcional a limpiar
     */
    function clearCache(selector) {
        if (selector) {
            delete cache[selector];
        } else {
            for (const key in cache) {
                delete cache[key];
            }
        }
    }
    
    /**
     * Función debounce para limitar llamadas a funciones
     * 
     * Previene la ejecución excesiva de funciones durante eventos
     * frecuentes como scroll, resize o input. Útil para optimizar
     * el rendimiento en operaciones costosas.
     * 
     * @function
     * @name debounce
     * @param {Function} func - Función a ejecutar con debounce
     * @param {number} [wait=250] - Tiempo de espera en milisegundos
     * @returns {Function} Función envuelta con debounce aplicado
     * @since 1.0.0
     * @example
     * const debouncedSearch = debounce(searchFunction, 300);
     * input.addEventListener('input', debouncedSearch);
     */
    function debounce(func, wait = config.debounceTime) {
        let timeout;
        
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    /**
     * Función throttle para limitar frecuencia de llamadas
     * 
     * Limita la frecuencia de ejecución de una función a un máximo
     * de una vez por intervalo de tiempo especificado. Útil para
     * eventos como scroll o resize que pueden dispararse muy frecuentemente.
     * 
     * @function
     * @name throttle
     * @param {Function} func - Función a ejecutar con throttle
     * @param {number} [limit=100] - Límite de tiempo en milisegundos
     * @returns {Function} Función envuelta con throttle aplicado
     * @since 1.0.0
     * @example
     * const throttledScroll = throttle(handleScroll, 100);
     * window.addEventListener('scroll', throttledScroll);
     */
    function throttle(func, limit = config.throttleTime) {
        let inThrottle;
        
        return function executedFunction(...args) {
            if (!inThrottle) {
                func(...args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }
    
    /**
     * Sistema de notificaciones mejorado
     */
    const toast = {
        container: null,
        
        /**
         * Inicializa el contenedor de notificaciones
         */
        init: function() {
            if (!this.container) {
                this.container = $('<div class=\'mi-toast-container\'></div>');
                $('body').append(this.container);
                
                // Estilos CSS inline para no depender de archivos CSS
                const style = `
                    <style>
                        .mi-toast-container {
                            position: fixed;
                            top: 20px;
                            right: 20px;
                            z-index: 9999;
                            width: 300px;
                        }
                        .mi-toast {
                            padding: 12px 16px;
                            border-radius: 6px;
                            margin-bottom: 10px;
                            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                            display: flex;
                            align-items: center;
                            animation: mi-toast-in 0.3s ease forwards;
                            transform: translateX(100%);
                            opacity: 0;
                        }
                        .mi-toast-icon {
                            margin-right: 12px;
                            display: flex;
                        }
                        .mi-toast-content {
                            flex: 1;
                        }
                        .mi-toast-title {
                            font-weight: 600;
                            margin-bottom: 4px;
                        }
                        .mi-toast-message {
                            font-size: 14px;
                        }
                        .mi-toast-close {
                            cursor: pointer;
                            opacity: 0.7;
                        }
                        .mi-toast-close:hover {
                            opacity: 1;
                        }
                        .mi-toast-success {
                            background-color: #ecfdf5;
                            border-left: 4px solid #10b981;
                            color: #065f46;
                        }
                        .mi-toast-error {
                            background-color: #fef2f2;
                            border-left: 4px solid #ef4444;
                            color: #991b1b;
                        }
                        .mi-toast-warning {
                            background-color: #fffbeb;
                            border-left: 4px solid #f59e0b;
                            color: #92400e;
                        }
                        .mi-toast-info {
                            background-color: #eff6ff;
                            border-left: 4px solid #3b82f6;
                            color: #1e40af;
                        }
                        @keyframes mi-toast-in {
                            from {
                                transform: translateX(100%);
                                opacity: 0;
                            }
                            to {
                                transform: translateX(0);
                                opacity: 1;
                            }
                        }
                        @keyframes mi-toast-out {
                            from {
                                transform: translateX(0);
                                opacity: 1;
                            }
                            to {
                                transform: translateX(100%);
                                opacity: 0;
                            }
                        }
                    </style>
                `;
                
                // Agregar estilos si no existen
                if ($('head style:contains(\'mi-toast-container\')').length === 0) {
                    $('head').append(style);
                }
            }
        },
        
        /**
         * Muestra una notificación toast
         * @param {Object} options - Opciones de configuración
         */
        show: function(options = {}) {
            this.init();
            
            const defaults = {
                type: 'info',
                title: '',
                message: '',
                duration: 5000,
                closable: true,
                onClose: null
            };
            
            const settings = $.extend({}, defaults, options);
            
            const icons = {
                success: '<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'20\' height=\'20\' viewBox=\'0 0 20 20\' fill=\'currentColor\'><path fill-rule=\'evenodd\' d=\'M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z\' clip-rule=\'evenodd\'></path></svg>',
                error: '<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'20\' height=\'20\' viewBox=\'0 0 20 20\' fill=\'currentColor\'><path fill-rule=\'evenodd\' d=\'M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z\' clip-rule=\'evenodd\'></path></svg>',
                warning: '<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'20\' height=\'20\' viewBox=\'0 0 20 20\' fill=\'currentColor\'><path fill-rule=\'evenodd\' d=\'M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z\' clip-rule=\'evenodd\'></path></svg>',
                info: '<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'20\' height=\'20\' viewBox=\'0 0 20 20\' fill=\'currentColor\'><path fill-rule=\'evenodd\' d=\'M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z\' clip-rule=\'evenodd\'></path></svg>'
            };
            
            const closeIcon = '<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'16\' height=\'16\' viewBox=\'0 0 20 20\' fill=\'currentColor\'><path fill-rule=\'evenodd\' d=\'M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z\' clip-rule=\'evenodd\'></path></svg>';
            
            // Crear el elemento toast
            const $toast = $(`
                <div class='mi-toast mi-toast-${settings.type}'>
                                          <div class='mi-toast-icon'>${icons[settings.type]}</div>
                                          <div class='mi-toast-content'>
                                                  ${settings.title ? `<div class='mi-toast-title'>${settings.title}</div>` : ''}
                                                  ${settings.message ? `<div class='mi-toast-message'>${settings.message}</div>` : ''}
                    </div>
                                          ${settings.closable ? `<div class='mi-toast-close'>${closeIcon}</div>` : ''}
                </div>
            `);
            
            // Agregar al contenedor
            this.container.prepend($toast);
            
            // Manejar cierre manual
            $toast.find('.mi-toast-close').on('click', function() {
                closeToast($toast, settings.onClose);
            });
            
            // Auto cerrar después del tiempo especificado
            if (settings.duration > 0) {
                setTimeout(function() {
                    closeToast($toast, settings.onClose);
                }, settings.duration);
            }
            
            function closeToast($toastEl, callback) {
                $toastEl.css('animation', 'mi-toast-out 0.3s ease forwards');
                
                setTimeout(function() {
                    $toastEl.remove();
                    if (typeof callback === 'function') {
                        callback();
                    }
                }, 300);
            }
            
            // Devolver el elemento para encadenamiento
            return $toast;
        },
        
        /**
         * Muestra una notificación de éxito
         * @param {string|Object} message - Mensaje o configuración
         * @param {string} [title] - Título opcional
         * @param {number} [duration] - Duración opcional
         */
        success: function(message, title, duration) {
            const options = typeof message === 'object' ? message : {
                type: 'success',
                message: message,
                title: title || 'Éxito',
                duration: duration || 5000
            };
            
            options.type = 'success';
            return this.show(options);
        },
        
        /**
         * Muestra una notificación de error
         * @param {string|Object} message - Mensaje o configuración
         * @param {string} [title] - Título opcional
         * @param {number} [duration] - Duración opcional
         */
        error: function(message, title, duration) {
            const options = typeof message === 'object' ? message : {
                type: 'error',
                message: message,
                title: title || 'Error',
                duration: duration || 5000
            };
            
            options.type = 'error';
            return this.show(options);
        },
        
        /**
         * Muestra una notificación de advertencia
         * @param {string|Object} message - Mensaje o configuración
         * @param {string} [title] - Título opcional
         * @param {number} [duration] - Duración opcional
         */
        warning: function(message, title, duration) {
            const options = typeof message === 'object' ? message : {
                type: 'warning',
                message: message,
                title: title || 'Advertencia',
                duration: duration || 5000
            };
            
            options.type = 'warning';
            return this.show(options);
        },
        
        /**
         * Muestra una notificación informativa
         * @param {string|Object} message - Mensaje o configuración
         * @param {string} [title] - Título opcional
         * @param {number} [duration] - Duración opcional
         */
        info: function(message, title, duration) {
            const options = typeof message === 'object' ? message : {
                type: 'info',
                message: message,
                title: title || 'Información',
                duration: duration || 5000
            };
            
            options.type = 'info';
            return this.show(options);
        }
    };
    
    /**
     * Sistema moderno de validación de formularios
     */
    const validator = {
        /**
         * Valida un formulario completo con reglas avanzadas
         * @param {jQuery|HTMLElement} form - Formulario a validar
         * @param {Object} rules - Reglas de validación
         * @returns {boolean} - Si el formulario es válido
         */
        validateForm: function(form, rules) {
            const $form = form instanceof jQuery ? form : $(form);
            let isValid = true;
            
            // Limpiar errores anteriores
            $form.find('.mi-form-error').remove();
            $form.find('.is-invalid').removeClass('is-invalid');
            
            // Recorrer cada regla
            for (const fieldName in rules) {
                const $field = $form.find(`[name="${fieldName}"]`);
                const fieldRules = rules[fieldName];
                const fieldValue = $field.val();
                
                // Ignorar campos deshabilitados
                if ($field.prop('disabled')) {
                    continue;
                }
                
                // Aplicar cada validación al campo
                for (const rule in fieldRules) {
                    const params = fieldRules[rule];
                    const result = this.applyRule(rule, fieldValue, params);
                    
                    if (!result.isValid) {
                        isValid = false;
                        this.showFieldError($field, result.message);
                        break;
                    }
                }
            }
            
            return isValid;
        },
        
        /**
         * Aplica una regla de validación
         * @param {string} rule - Nombre de la regla
         * @param {string} value - Valor a validar
         * @param {*} params - Parámetros adicionales
         * @returns {Object} - Resultado de la validación
         */
        applyRule: function(rule, value, params) {
            const rules = {
                required: function(value) {
                    const isValid = value !== null && value !== undefined && value.toString().trim() !== '';
                    return {
                        isValid,
                        message: !isValid ? 'Este campo es obligatorio' : ''
                    };
                },
                email: function(value) {
                    const regex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
                    const isValid = value === '' || regex.test(value);
                    return {
                        isValid,
                        message: !isValid ? 'Introduzca un correo válido' : ''
                    };
                },
                minLength: function(value, min) {
                    const isValid = value === '' || value.length >= min;
                    return {
                        isValid,
                        message: !isValid ? `El mínimo de caracteres es ${min}` : ''
                    };
                },
                maxLength: function(value, max) {
                    const isValid = value === '' || value.length <= max;
                    return {
                        isValid,
                        message: !isValid ? `El máximo de caracteres es ${max}` : ''
                    };
                },
                numeric: function(value) {
                    const isValid = value === '' || /^[0-9]+$/.test(value);
                    return {
                        isValid,
                        message: !isValid ? 'Solo se permiten números' : ''
                    };
                },
                alpha: function(value) {
                    const isValid = value === '' || /^[a-zA-Z]+$/.test(value);
                    return {
                        isValid,
                        message: !isValid ? 'Solo se permiten letras' : ''
                    };
                },
                alphaNumeric: function(value) {
                    const isValid = value === '' || /^[a-zA-Z0-9]+$/.test(value);
                    return {
                        isValid,
                        message: !isValid ? 'Solo se permiten letras y números' : ''
                    };
                },
                pattern: function(value, pattern) {
                    const regex = new RegExp(pattern);
                    const isValid = value === '' || regex.test(value);
                    return {
                        isValid: isValid,
                        message: !isValid ? 'El formato ingresado no es válido' : ''
                    };
                },
                match: function(value, targetField, $form) {
                    const targetValue = $form.find(`[name="${targetField}"]`).val();
                    const isValid = value === targetValue;
                    return {
                        isValid: isValid,
                        message: !isValid ? 'Los valores no coinciden' : ''
                    };
                },
                min: function(value, min) {
                    const numValue = parseFloat(value);
                    const isValid = isNaN(numValue) || numValue >= min;
                    return {
                        isValid: isValid,
                        message: !isValid ? `El valor mínimo es ${min}` : ''
                    };
                },
                max: function(value, max) {
                    const numValue = parseFloat(value);
                    const isValid = isNaN(numValue) || numValue <= max;
                    return {
                        isValid,
                        message: !isValid ? `El valor máximo es ${max}` : ''
                    };
                },
                date: function(value) {
                    if (value === '') return { isValid: true };
                    const timestamp = Date.parse(value);
                    return {
                        isValid: !isNaN(timestamp),
                        message: isNaN(timestamp) ? 'Introduzca una fecha válida' : ''
                    };
                },
                url: function(value) {
                    try {
                        if (value === '') return { isValid: true };
                        new URL(value);
                        return { isValid: true };
                    } catch {
                        return {
                            isValid: false,
                            message: 'Introduzca una URL válida'
                        };
                    }
                }
            };
            
            if (typeof rules[rule] === 'function') {
                return rules[rule](value, params);
            }
            
            return { isValid: true };
        },
        
        /**
         * Muestra un error en un campo
         * @param {jQuery} $field - Campo con error
         * @param {string} message - Mensaje de error
         */
        showFieldError: function($field, message) {
            $field.addClass('is-invalid');
            const $errorMsg = $('<div class="mi-form-error"></div>')
                .text(message)
                .css({
                    color: '#dc2626',
                    fontSize: '0.75rem',
                    marginTop: '0.25rem'
                });
            
            $field.after($errorMsg);
        }
    };
    
    /**
     * Sistema mejorado de animaciones con facilidades
     */
    const animations = {
        /**
         * Anima un elemento con efecto de fade-in
         * @param {jQuery|HTMLElement} element - Elemento a animar
         * @param {number} duration - Duración en ms
         * @param {Function} callback - Función a ejecutar al finalizar
         */
        fadeIn: function(element, duration = 300, callback = null) {
            const $el = element instanceof jQuery ? element : $(element);
            $el.css('opacity', 0)
               .css('display', 'block')
               .animate({ opacity: 1 }, {
                   duration: duration,
                   complete: callback,
                   easing: 'easeOutCubic'
               });
        },
        
        /**
         * Anima un elemento con efecto de fade-out
         * @param {jQuery|HTMLElement} element - Elemento a animar
         * @param {number} duration - Duración en ms
         * @param {Function} callback - Función a ejecutar al finalizar
         */
        fadeOut: function(element, duration = 300, callback = null) {
            const $el = element instanceof jQuery ? element : $(element);
            $el.animate({ opacity: 0 }, {
                duration: duration,
                complete: function() {
                    $el.css('display', 'none');
                    if (typeof callback === 'function') {
                        callback();
                    }
                },
                easing: 'easeInCubic'
            });
        },
        
        /**
         * Anima un elemento con efecto de slide-down
         * @param {jQuery|HTMLElement} element - Elemento a animar
         * @param {number} duration - Duración en ms
         * @param {Function} callback - Función a ejecutar al finalizar
         */
        slideDown: function(element, duration = 300, callback = null) {
            const $el = element instanceof jQuery ? element : $(element);
            $el.css('display', 'block')
               .css('height', 'auto');
            
            const height = $el.outerHeight();
            $el.css('height', 0)
               .css('overflow', 'hidden')
               .animate({ height: height }, {
                   duration: duration,
                   complete: function() {
                       $el.css('height', 'auto');
                       if (typeof callback === 'function') {
                           callback();
                       }
                   },
                   easing: 'easeOutCubic'
               });
        },
        
        /**
         * Anima un elemento con efecto de slide-up
         * @param {jQuery|HTMLElement} element - Elemento a animar
         * @param {number} duration - Duración en ms
         * @param {Function} callback - Función a ejecutar al finalizar
         */
        slideUp: function(element, duration = 300, callback = null) {
            const $el = element instanceof jQuery ? element : $(element);
            $el.css('overflow', 'hidden')
               .animate({ height: 0 }, {
                   duration: duration,
                   complete: function() {
                       $el.css('display', 'none');
                       if (typeof callback === 'function') {
                           callback();
                       }
                   },
                   easing: 'easeInCubic'
               });
        },
        
        /**
         * Anima un elemento con efecto shake para indicar error
         * @param {jQuery|HTMLElement} element - Elemento a animar
         */
        shake: function(element) {
            const $el = element instanceof jQuery ? element : $(element);
            const originalPosition = $el.css('position');
            
            if (originalPosition === 'static') {
                $el.css('position', 'relative');
            }
            
            $el.animate({ left: -10 }, 100)
               .animate({ left: 10 }, 100)
               .animate({ left: -7 }, 100)
               .animate({ left: 7 }, 100)
               .animate({ left: 0 }, 100, function() {
                   if (originalPosition === 'static') {
                       $el.css('position', originalPosition);
                   }
               });
        },
        
        /**
         * Anima un elemento con efecto de pulso para destacarlo
         * @param {jQuery|HTMLElement} element - Elemento a animar
         */
        pulse: function(element) {
            const $el = element instanceof jQuery ? element : $(element);
            $el.animate({ opacity: 0.5 }, 100)
               .animate({ opacity: 1 }, 100);
        },
        
        /**
         * Aplica una animación de entrada personalizada
         * @param {jQuery|HTMLElement} element - Elemento a animar
         * @param {string} animationType - Tipo de animación
         * @param {number} duration - Duración en ms
         */
        animateIn: function(element, animationType = 'fadeIn', duration = 300) {
            const $el = element instanceof jQuery ? element : $(element);
            
            // Reestablecer estilos
            $el.css({
                transform: '',
                opacity: '',
                transition: ''
            });
            
            // Configurar estado inicial
            switch(animationType) {
                case 'fadeIn':
                    $el.css('opacity', 0);
                    break;
                case 'slideInDown':
                    $el.css({
                        opacity: 0,
                        transform: 'translateY(-20px)'
                    });
                    break;
                case 'slideInUp':
                    $el.css({
                        opacity: 0,
                        transform: 'translateY(20px)'
                    });
                    break;
                case 'slideInLeft':
                    $el.css({
                        opacity: 0,
                        transform: 'translateX(-20px)'
                    });
                    break;
                case 'slideInRight':
                    $el.css({
                        opacity: 0,
                        transform: 'translateX(20px)'
                    });
                    break;
                case 'zoomIn':
                    $el.css({
                        opacity: 0,
                        transform: 'scale(0.8)'
                    });
                    break;
            }
            
            // Forzar repintado
            $el[0].offsetHeight;
            
            // Aplicar transición
            $el.css({
                opacity: 1,
                transform: 'none',
                transition: `all ${duration}ms cubic-bezier(0.4, 0, 0.2, 1)`
            });
        }
    };
    
    /**
     * Event Bus/PubSub para comunicación desacoplada entre componentes
     */
    const eventBus = {
        events: {},
        
        /**
         * Suscribe a un evento
         * @param {string} eventName - Nombre del evento
         * @param {Function} callback - Función a ejecutar
         * @returns {Object} - Objeto para cancelar suscripción
         */
        subscribe: function(eventName, callback) {
            if (!this.events[eventName]) {
                this.events[eventName] = [];
            }
            
            const index = this.events[eventName].push(callback) - 1;
            
            // Objeto para cancelar suscripción
            return {
                unsubscribe: () => {
                    this.events[eventName].splice(index, 1);
                }
            };
        },
        
        /**
         * Publica un evento
         * @param {string} eventName - Nombre del evento
         * @param {*} data - Datos a enviar con el evento
         */
        publish: function(eventName, data) {
            if (!this.events[eventName]) {
                return;
            }
            
            this.events[eventName].forEach(callback => {
                callback(data);
            });
        },
        
        /**
         * Publica un evento una sola vez y luego remueve todos los suscriptores
         * @param {string} eventName - Nombre del evento
         * @param {*} data - Datos a enviar con el evento
         */
        publishOnce: function(eventName, data) {
            if (!this.events[eventName]) {
                return;
            }
            
            this.events[eventName].forEach(callback => {
                callback(data);
            });
            
            delete this.events[eventName];
        },
        
        /**
         * Elimina todos los suscriptores de un evento
         * @param {string} eventName - Nombre del evento
         */
        clear: function(eventName) {
            if (eventName) {
                delete this.events[eventName];
            } else {
                this.events = {};
            }
        }
    };
    
    /**
     * Cliente API mejorado con soporte para Fetch y fallback a jQuery
     */
    const apiClient = {
        /**
         * Realiza una solicitud HTTP usando Fetch API con fallback a jQuery
         * @param {Object} options - Opciones de configuración
         * @returns {Promise} - Promesa con la respuesta
         */
        request: function(options = {}) {
            const defaultOptions = {
                url: '',
                method: 'GET',
                data: null,
                headers: {
                    'Content-Type': 'application/json'
                },
                timeout: 30000,
                useJquery: false
            };
            
            const settings = Object.assign({}, defaultOptions, options);
            
            // Si es una petición a admin-ajax.php, añadir automáticamente el nonce
            if (settings.url && (settings.url.includes('admin-ajax.php') || settings.url === ajaxurl)) {
                // Convertir datos a formato x-www-form-urlencoded para admin-ajax.php
                if (settings.data && typeof settings.data === 'object') {
                    // Añadir nonce automáticamente si no está presente
                    if (!settings.data.nonce && window.miIntegracionApiDashboard && window.miIntegracionApiDashboard.nonce) {
                        settings.data.nonce = window.miIntegracionApiDashboard.nonce;
                    }
                    
                    // Convertir a formato form para admin-ajax.php
                    const formData = new FormData();
                    Object.keys(settings.data).forEach(key => {
                        formData.append(key, settings.data[key]);
                    });
                    settings.data = formData;
                    
                    // Cambiar content-type para form data
                    delete settings.headers['Content-Type']; // Let browser set it automatically
                }
            }
            
            // Usar Fetch API si está disponible y no se solicitó jQuery explícitamente
            if (typeof fetch === 'function' && !settings.useJquery) {
                const fetchOptions = {
                    method: settings.method,
                    headers: settings.headers,
                    credentials: 'same-origin'
                };
                
                // Agregar body para métodos que lo admiten
                if (['POST', 'PUT', 'PATCH'].includes(settings.method) && settings.data) {
                    fetchOptions.body = typeof settings.data === 'string' 
                        ? settings.data 
                        : JSON.stringify(settings.data);
                }
                
                // Crear controlador de aborto para timeout
                const controller = new AbortController();
                fetchOptions.signal = controller.signal;
                
                // Configurar timeout
                const timeoutId = setTimeout(() => controller.abort(), settings.timeout);
                
                return fetch(settings.url, fetchOptions)
                    .then(response => {
                        clearTimeout(timeoutId);
                        
                        // Convertir la respuesta al formato adecuado
                        const contentType = response.headers.get('Content-Type') || '';
                        if (contentType.includes('application/json')) {
                            return response.json().then(data => ({
                                data,
                                status: response.status,
                                headers: this._headersToObject(response.headers),
                                statusText: response.statusText,
                                ok: response.ok
                            }));
                        } else {
                            return response.text().then(text => ({
                                data: text,
                                status: response.status,
                                headers: this._headersToObject(response.headers),
                                statusText: response.statusText,
                                ok: response.ok
                            }));
                        }
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw {
                                status: response.status,
                                statusText: response.statusText,
                                data: response.data
                            };
                        }
                        return response.data;
                    })
                    .catch(error => {
                        clearTimeout(timeoutId);
                        
                        // Error específico de timeout
                        if (error.name === 'AbortError') {
                            throw {
                                status: 408,
                                statusText: 'Request Timeout',
                                message: 'La solicitud superó el tiempo límite'
                            };
                        }
                        
                        throw error;
                    });
            } 
            // Fallback a jQuery AJAX
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: settings.url,
                    type: settings.method,
                    data: settings.method === 'GET' ? settings.data : 
                         (typeof settings.data === 'string' ? settings.data : JSON.stringify(settings.data)),
                    headers: settings.headers,
                    timeout: settings.timeout,
                    contentType: settings.headers['Content-Type'],
                    success: function(data, textStatus, jqXHR) {
                        resolve(data);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        reject({
                            status: jqXHR.status,
                            statusText: textStatus,
                            message: errorThrown || 'Error en la solicitud',
                            data: jqXHR.responseJSON || jqXHR.responseText
                        });
                    }
                });
            });
        },
        
        /**
         * Convierte headers de Fetch API a un objeto simple
         * @param {Headers} headers - Objeto Headers de Fetch API
         * @returns {Object} - Objeto con los headers
         */
        _headersToObject: function(headers) {
            const result = {};
            headers.forEach((value, key) => {
                result[key] = value;
            });
            return result;
        },
        
        /**
         * Realiza una solicitud GET
         * @param {string} url - URL de la solicitud
         * @param {Object} params - Parámetros de la solicitud
         * @param {Object} options - Opciones adicionales
         * @returns {Promise} - Promesa con la respuesta
         */
        get: function(url, params = {}, options = {}) {
            // Convertir params a query string
            if (params && Object.keys(params).length) {
                const queryString = Object.keys(params)
                    .map(key => `${encodeURIComponent(key)}=${encodeURIComponent(params[key])}`)
                    .join('&');
                
                url = `${url}${url.includes('?') ? '&' : '?'}${queryString}`;
            }
            
            return this.request(Object.assign({}, options, {
                url,
                method: 'GET'
            }));
        },
        
        /**
         * Realiza una solicitud POST
         * @param {string} url - URL de la solicitud
         * @param {Object} data - Datos a enviar
         * @param {Object} options - Opciones adicionales
         * @returns {Promise} - Promesa con la respuesta
         */
        post: function(url, data = {}, options = {}) {
            return this.request(Object.assign({}, options, {
                url,
                method: 'POST',
                data
            }));
        },
        
        /**
         * Realiza una solicitud PUT
         * @param {string} url - URL de la solicitud
         * @param {Object} data - Datos a enviar
         * @param {Object} options - Opciones adicionales
         * @returns {Promise} - Promesa con la respuesta
         */
        put: function(url, data = {}, options = {}) {
            return this.request(Object.assign({}, options, {
                url,
                method: 'PUT',
                data
            }));
        },
        
        /**
         * Realiza una solicitud PATCH
         * @param {string} url - URL de la solicitud
         * @param {Object} data - Datos a enviar
         * @param {Object} options - Opciones adicionales
         * @returns {Promise} - Promesa con la respuesta
         */
        patch: function(url, data = {}, options = {}) {
            return this.request(Object.assign({}, options, {
                url,
                method: 'PATCH',
                data
            }));
        },
        
        /**
         * Realiza una solicitud DELETE
         * @param {string} url - URL de la solicitud
         * @param {Object} options - Opciones adicionales
         * @returns {Promise} - Promesa con la respuesta
         */
        delete: function(url, options = {}) {
            return this.request(Object.assign({}, options, {
                url,
                method: 'DELETE'
            }));
        }
    };
    
    /**
     * Sistema de carga diferida de scripts y estilos
     */
    const lazyLoader = {
        /**
         * Carga un script JS de forma diferida
         * @param {string} url - URL del script
         * @returns {Promise} - Promesa que se resuelve cuando el script se carga
         */
        loadScript: function(url) {
            return new Promise((resolve, reject) => {
                // Verificar si el script ya está cargado
                const existingScript = document.querySelector(`script[src="${url}"]`);
                if (existingScript) {
                    resolve();
                    return;
                }
                
                const script = document.createElement('script');
                script.src = url;
                script.async = true;
                
                script.onload = () => resolve();
                script.onerror = () => reject(new Error(`Error al cargar el script: ${url}`));
                
                document.body.appendChild(script);
            });
        },
        
        /**
         * Carga una hoja de estilos CSS de forma diferida
         * @param {string} url - URL del CSS
         * @returns {Promise} - Promesa que se resuelve cuando el CSS se carga
         */
        loadCSS: function(url) {
            return new Promise((resolve, reject) => {
                // Verificar si el CSS ya está cargado
                const existingLink = document.querySelector(`link[href="${url}"]`);
                if (existingLink) {
                    resolve();
                    return;
                }
                
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = url;
                
                link.onload = () => resolve();
                link.onerror = () => reject(new Error(`Error al cargar el CSS: ${url}`));
                
                document.head.appendChild(link);
            });
        },
        
        /**
         * Carga múltiples recursos en paralelo
         * @param {Array} resources - Array de objetos {type: 'script'|'css', url: string}
         * @returns {Promise} - Promesa que se resuelve cuando todos los recursos se cargan
         */
        loadResources: function(resources) {
            const promises = resources.map(resource => {
                if (resource.type === 'script') {
                    return this.loadScript(resource.url);
                } else if (resource.type === 'css') {
                    return this.loadCSS(resource.url);
                }
                return Promise.reject(new Error(`Tipo de recurso desconocido: ${resource.type}`));
            });
            
            return Promise.all(promises);
        },
        
        /**
         * Carga un componente cuando es visible en el viewport
         * @param {string} selector - Selector del contenedor
         * @param {Function} initCallback - Función para inicializar el componente
         * @param {number} threshold - Umbral de visibilidad (0 a 1)
         */
        initWhenVisible: function(selector, initCallback, threshold = 0.1) {
            const containers = document.querySelectorAll(selector);
            
            if (!containers.length) return;
            
            if (!('IntersectionObserver' in window)) {
                // Fallback si no hay IntersectionObserver
                containers.forEach(container => {
                    initCallback(container);
                });
                return;
            }
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        initCallback(entry.target);
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold });
            
            containers.forEach(container => {
                observer.observe(container);
            });
        }
    };
    
    // Funciones de formato para JSON y fechas
    const formatter = {
        /**
         * Formatea un objeto JSON para mostrarlo bonito
         * @param {Object} json - Objeto a formatear
         * @param {number} indent - Nivel de indentación
         * @returns {string} - JSON formateado como HTML
         */
        prettyJSON: function(json, indent = 2) {
            // Si no es un objeto, devolverlo como está
            if (typeof json !== 'object' || json === null) {
                return this.highlightValue(JSON.stringify(json));
            }
            
            try {
                // Convertir objeto a string con formato
                const jsonStr = JSON.stringify(json, null, indent);
                
                // Colorear sintaxis
                return this.highlightJSON(jsonStr);
            } catch (e) {
                return `<span style="color: #e53e3e;">Error al formatear JSON: ${e.message}</span>`;
            }
        },
        
        /**
         * Resalta la sintaxis de un string JSON
         * @param {string} jsonStr - String JSON
         * @returns {string} - HTML con sintaxis resaltada
         */
        highlightJSON: function(jsonStr) {
            return jsonStr
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, 
                function(match) {
                    let cls = 'number';
                    if (/^"/.test(match)) {
                        if (/:$/.test(match)) {
                            cls = 'key';
                        } else {
                            cls = 'string';
                        }
                    } else if (/true|false/.test(match)) {
                        cls = 'boolean';
                    } else if (/null/.test(match)) {
                        cls = 'null';
                    }
                    
                    // Asignar colores según el tipo
                    const colors = {
                        key: '#805ad5',    // morado
                        string: '#38a169', // verde
                        number: '#3182ce', // azul
                        boolean: '#dd6b20',// naranja
                        null: '#718096'     // gris
                    };
                    
                    return `<span style="color: ${colors[cls]}">${match}</span>`;
                });
        },
        
        /**
         * Resalta un valor individual según su tipo
         * @param {*} value - Valor a resaltar
         * @returns {string} - HTML con el valor resaltado
         */
        highlightValue: function(value) {
            if (typeof value === 'string') {
                return `<span style="color: #38a169;">${value}</span>`;
            } else if (typeof value === 'number') {
                return `<span style="color: #3182ce;">${value}</span>`;
            } else if (typeof value === 'boolean') {
                return `<span style="color: #dd6b20;">${value}</span>`;
            } else if (value === null) {
                return `<span style="color: #718096;">null</span>`;
            } else {
                return String(value);
            }
        },
        
        /**
         * Formatea una fecha ISO a formato legible
         * @param {string} isoDate - Fecha en formato ISO
         * @param {Object} options - Opciones de formato
         * @returns {string} - Fecha formateada
         */
        formatDate: function(isoDate, options = {}) {
            if (!isoDate) return '';
            
            const defaultOptions = {
                dateStyle: 'medium',
                timeStyle: 'short'
            };
            
            const settings = Object.assign({}, defaultOptions, options);
            
            try {
                const date = new Date(isoDate);
                return date.toLocaleString(undefined, settings);
            } catch (e) {
                return isoDate;
            }
        },
        
        /**
         * Formatea un número como moneda
         * @param {number} value - Valor a formatear
         * @param {string} currency - Código de moneda (MXN, USD, etc)
         * @param {string} locale - Localización (es-MX, en-US, etc)
         * @returns {string} - Número formateado como moneda
         */
        formatCurrency: function(value, currency = 'MXN', locale = 'es-MX') {
            try {
                return new Intl.NumberFormat(locale, {
                    style: 'currency',
                    currency: currency
                }).format(value);
            } catch (e) {
                return `${value} ${currency}`;
            }
        },
        
        /**
         * Formatea un número con separadores de miles
         * @param {number} value - Valor a formatear
         * @param {number} decimals - Cantidad de decimales
         * @param {string} locale - Localización (es-MX, en-US, etc)
         * @returns {string} - Número formateado
         */
        formatNumber: function(value, decimals = 2, locale = 'es-MX') {
            try {
                return new Intl.NumberFormat(locale, {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals
                }).format(value);
            } catch (e) {
                return value.toFixed(decimals);
            }
        }
    };
    
    // Exportar funcionalidades públicas
    return {
        getElement,
        clearCache,
        debounce,
        throttle,
        toast,
        formatter,
        animations,
        validator,
        api: apiClient,
        eventBus,
        
        /**
         * Inicializa las utilidades
         * @param {Object} [options] - Opciones de configuración
         */
        init: function(options = {}) {
            // Configurar opciones globales
            $.extend(config, options);
            
            // Compatibilidad con versiones anteriores
            window.verialToast = toast;
            
            console.info('MiIntegracionAPI Utilities v1.0.0 iniciado');
        }
    };
})(jQuery);

// Registrar para auto inicialización cuando el documento esté listo
jQuery(function() {
    MiIntegracionAPI.utils.init();
    console.info('MiIntegracionAPI Utilities inicializado automáticamente');
});
