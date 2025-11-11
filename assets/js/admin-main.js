/**
 * Archivo JavaScript principal para el área de administración de Mi Integración API
 * 
 * Consolida la funcionalidad de admin.js y admin-script.js, utilizando las utilidades
 * de utils.js. Proporciona funcionalidades de administración como manejo de formularios,
 * botones de acción, tooltips, modales y componentes interactivos.
 * 
 * @fileoverview Funcionalidades principales de administración
 * @version 1.0.0
 * @author Mi Integración API
 * @since 2025-01-31
 */
jQuery(document).ready(function($) {
    'use strict';

    // Asegurarse de que miApiUtils y miApiModern estén disponibles
    const miApiUtils = window.miApiUtils || {};
    const miApiModern = window.miApiModern || {};

    // Selector compuesto para compatibilidad con las clases antiguas y nuevas
    const adminSelector = '.mi-integracion-api-admin, .verial-admin-wrap';
    const dashboardSelector = '.mi-integracion-api-dashboard, .verial-dashboard';
    const settingsSelector = '.mi-integracion-api-settings, .verial-settings';

    // Mostrar notificaciones automáticas existentes al cargar la página
    $(adminSelector + ' .notice').each(function() {
        const $notice = $(this);
        if ($notice.hasClass('notice-success') || $notice.hasClass('notice-info')) {
            setTimeout(function() { $notice.fadeOut(); }, 3500);
        }
    });

    /**
     * Manejador de envío de formularios principales
     * 
     * Gestiona el envío de formularios con validación, debounce para prevenir
     * envíos múltiples y manejo de respuestas AJAX. Utiliza las utilidades
     * modernas para una experiencia de usuario mejorada.
     * 
     * @function
     * @name handleFormSubmission
     * @param {Event} e - Evento de submit del formulario
     * @returns {void}
     * @since 1.0.0
     */
    $('.mi-integracion-api-form').on('submit', miApiModern.debounce(function(e) {
        e.preventDefault();
        const $form = $(this);
        const $submitButton = $form.find('button[type=\'submit\']');

        if (!miApiModern.validateForm($form)) {
            miApiModern.toast.error('Por favor, complete todos los campos requeridos y corrija los errores.');
            return;
        }

        miApiUtils.toggleLoading($submitButton, true);

        miApiModern.apiClient.post(
            $form.data('action'),
            {
                ...$form.serializeArray().reduce((obj, item) => {
                    obj[item.name] = item.value;
                    return obj;
                }, {})
            }
        )
        .then(response => {
            miApiModern.toast.success(response.message || 'Operación realizada con éxito');
            if (response.redirect) {
                window.location.href = response.redirect;
            } else if (response.reload) {
                window.location.reload();
            }
        })
        .catch(error => {
            miApiModern.toast.error(`Error: ${error.message || 'Ha ocurrido un error inesperado.'}`);
        })
        .finally(() => {
            miApiUtils.toggleLoading($submitButton, false);
        });
    }, 300)); // Previene múltiples envíos rápidos

    /**
     * Manejador de botones de acción genéricos
     * 
     * Gestiona clicks en botones de acción con confirmación, recopilación
     * de atributos data y ejecución de acciones AJAX. Proporciona feedback
     * visual y manejo de errores.
     * 
     * @function
     * @name handleActionButton
     * @param {Event} e - Evento de click del botón
     * @returns {void}
     * @since 1.0.0
     */
    $('.mi-integracion-api-action-button').on('click', function(e) {
        const $button = $(this);
        if (!miApiUtils.confirmAction(e)) {
            return;
        }

        const action = $button.data('action');
        if (!action) {
            // console.error('Botón de acción sin atributo data-action:', $button);
            return;
        }

        miApiUtils.toggleLoading($button, true);

        // Recopilar todos los data attributes como parámetros
        const dataToSend = { ...$button.data() };
        delete dataToSend.action; // No enviar la acción dos veces
        delete dataToSend.confirm; // No enviar el mensaje de confirmación

        miApiUtils.ajaxRequest(
            action,
            dataToSend,
            function(response) {
                miApiUtils.showNotification(response.message || 'Operación realizada con éxito');
                if (response.redirect) {
                    window.location.href = response.redirect;
                } else if (response.reload) {
                    window.location.reload();
                }
            },
            function(errorData) {
                miApiUtils.showNotification(errorData.message || 'Error al procesar la solicitud', 'error');
            }
        ).always(function() {
            miApiUtils.toggleLoading($button, false);
        });
    });

    // Toggle para mostrar/ocultar contraseña
    $(document).on('click', '.toggle-password', miApiUtils.togglePassword);

    // Manejador de tooltips
    $('.mi-integracion-api-tooltip').each(function() {
        const tooltip = $(this);
        const text = tooltip.data('tooltip');

        if (text) {
            tooltip.append('<span class=\'tooltip-text\'>' + text + '</span>');
        }
    });

    // Manejador de modales
    $('.mi-integracion-api-modal-trigger').on('click', function(e) {
        e.preventDefault();
        const modalId = $(this).data('modal');
        $('#' + modalId).fadeIn(300);
    });

    $('.mi-integracion-api-modal-close').on('click', function() {
        $(this).closest('.mi-integracion-api-modal').fadeOut(300);
    });

    $(window).on('click', function(e) {
        if ($(e.target).hasClass('mi-integracion-api-modal')) {
            $(e.target).fadeOut(300);
        }
    });

    /**
     * Inicializa componentes específicos del área de administración
     * 
     * Función para inicializar componentes adicionales específicos del admin
     * que no estén en utils.js, como datepickers, select2, etc.
     * 
     * @function
     * @name initComponents
     * @description Inicializa componentes específicos del admin
     * @returns {void}
     * @since 1.0.0
     */
    function initComponents() {
        // Aquí puedes inicializar componentes adicionales específicos del admin
        // que no estén en utils.js, como datepickers, select2, etc.
    }

    initComponents();
});