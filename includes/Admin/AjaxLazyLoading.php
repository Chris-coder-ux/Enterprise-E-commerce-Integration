<?php
/**
 * Módulo de carga perezosa de componentes vía AJAX
 *
 * Este archivo implementa el sistema de carga bajo demanda de componentes
 * en el panel de administración de WordPress, mejorando significativamente
 * el rendimiento al cargar solo los componentes necesarios.
 *
 * Características principales:
 * - Carga asíncrona de componentes bajo demanda
 * - Validación de seguridad con nonces de WordPress
 * - Control de acceso basado en capacidades de usuario
 * - Manejo de errores y respuestas estandarizadas
 * - Integración con el sistema de logs
 *
 * @package     MiIntegracionApi\Admin
 * @since       1.0.0
 * @version     1.2.1
 * @author      Your Name <your.email@example.com>
 * @copyright   Copyright (c) 2025, Your Company
 * @license     GPL-2.0+
 * @link        https://example.com/plugin-docs/lazy-loading
 */

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para la gestión de carga perezosa de componentes vía AJAX
 *
 * Esta clase proporciona una interfaz para cargar componentes de forma asíncrona
 * en el panel de administración, mejorando el rendimiento al cargar solo los
 * componentes necesarios cuando son requeridos.
 *
 * Características principales:
 * - Registro automático de manejadores AJAX
 * - Validación de seguridad con nonces de WordPress
 * - Control de acceso basado en capacidades
 * - Manejo de errores con respuestas JSON estandarizadas
 * - Integración con el sistema de logs
 *
 * @package     MiIntegracionApi\Admin
 * @since       1.0.0
 * @version     1.2.1
 * @see         \MiIntegracionApi\Core\LazyLoader Para la carga real de componentes
 * @property    \wpdb $wpdb Instancia global de WordPress Database Access
 * @global      \wpdb $wpdb Objeto global de base de datos de WordPress
 */
class AjaxLazyLoading {
    /**
     * Registra los manejadores de acciones AJAX para la carga perezosa
     *
     * Este método registra el hook de inicialización que configurará el manejador AJAX
     * para la carga perezosa de componentes. Debe llamarse durante la inicialización
     * de WordPress.
     *
     * Acciones registradas:
     * - init: Para inicializar el manejador AJAX
     *
     * @return void
     * @since 1.0.0
     * @hook init Se ejecuta durante la inicialización de WordPress
     * @see init_lazyload_ajax() Método que inicializa el manejador AJAX
     * @uses add_action() Para registrar el hook de inicialización
     *
     * @example
     * ```php
     * // En el archivo principal del plugin
     * add_action('plugins_loaded', ['MiIntegracionApi\Admin\AjaxLazyLoading', 'register_ajax_handler']);
     * ```
     */
    public static function register_ajax_handler() {
        add_action( 'init', [self::class, 'init_lazyload_ajax'] );
    }

    /**
     * Inicializa el manejador AJAX para la carga perezosa
     *
     * Registra el callback que manejará las peticiones AJAX de carga perezosa.
     * Este método solo registra el manejador si estamos en el área de administración
     * de WordPress y para peticiones AJAX.
     *
     * @return void
     * @since 1.0.0
     * @hook init Se ejecuta durante la inicialización de WordPress
     * @see handle_lazyload() Manejador de la petición AJAX
     * @uses add_action() Para registrar el manejador AJAX
     * @uses wp_doing_ajax() Para verificar si es una petición AJAX
     * @uses is_admin() Para verificar si estamos en el área de administración
     *
     * @example
     * ```php
     * // Ejemplo de cómo se registra el manejador
     * add_action('wp_ajax_mi_integracion_api_lazyload', ['MiIntegracionApi\Admin\AjaxLazyLoading', 'handle_lazyload']);
     * ```
     */
    public static function init_lazyload_ajax() {
        add_action( 'wp_ajax_mi_integracion_api_lazyload', [self::class, 'handle_lazyload'] );
    }

    /**
     * Maneja la petición AJAX de carga perezosa de componentes
     *
     * Este método es el punto de entrada para todas las solicitudes de carga perezosa.
     * Se encarga de validar la seguridad, verificar permisos, cargar el componente
     * solicitado y devolver una respuesta JSON estandarizada.
     *
     * Flujo de ejecución:
     * 1. Validar nonce de seguridad
     * 2. Verificar permisos del usuario
     * 3. Validar el componente solicitado
     * 4. Cargar el componente mediante LazyLoader
     * 5. Manejar la respuesta o errores
     *
     * @return void Envía una respuesta JSON con la siguiente estructura:
     *              - En caso de éxito:
     *                {
     *                  "success": true,
     *                  "data": {
     *                    "message": "Componente cargado correctamente",
     *                    "component": "nombre-del-componente"
     *                  }
     *                }
     *              - En caso de error:
     *                {
     *                  "success": false,
     *                  "data": {
     *                    "message": "Mensaje de error",
     *                    "code": "código_de_error",
     *                    "component": "nombre-del-componente",
     *                    "trace": "Traza de error (solo en modo depuración)"
     *                  }
     *                }
     *
     * @since 1.0.0
     * @throws \Exception Si ocurre un error durante la carga del componente
     * @hook wp_ajax_mi_integracion_api_lazyload
     * @security check_ajax_referer('wp_rest')
     * @permission manage_options Requiere capacidad de administrador
     * @global \wpdb $wpdb Objeto de base de datos de WordPress
     * @see \MiIntegracionApi\Core\LazyLoader Para la carga real de componentes
     * @uses wp_send_json_success() Para enviar respuestas exitosas
     * @uses wp_send_json_error() Para enviar respuestas de error
     * @uses check_ajax_referer() Para validar el nonce de seguridad
     * @uses current_user_can() Para verificar permisos del usuario
     *
     * @example
     * ```javascript
     * // Ejemplo de llamada AJAX desde JavaScript
     * jQuery.ajax({
     *     url: ajaxurl,
     *     type: 'POST',
     *     data: {
     *         action: 'mi_integracion_api_lazyload',
     *         component: 'mi-componente',
     *         _ajax_nonce: mi_vars.nonce // Nonce de WordPress REST API
     *     },
     *     success: function(response) {
     *         console.log('Componente cargado:', response.data.component);
     *     },
     *     error: function(error) {
     *         console.error('Error al cargar componente:', error.responseJSON.data.message);
     *     }
     * });
     * ```
     */
    public static function handle_lazyload() {
        // Verificar nonce de seguridad
        $nonce_valid = check_ajax_referer( 'wp_rest', false, false );
        if ( ! $nonce_valid ) {
            wp_send_json_error([
                'message' => __( 'Token de seguridad no válido.', 'mi-integracion-api' ),
                'code'    => 'invalid_nonce'
            ]);
            exit;
        }

        // Verificar permisos del usuario
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error([
                'message' => __( 'No tienes permisos suficientes para realizar esta acción.', 'mi-integracion-api' ),
                'code'    => 'insufficient_permissions'
            ]);
            exit;
        }

        // Obtener y validar el componente solicitado
        $component = isset( $_POST['component'] ) ? sanitize_text_field( $_POST['component'] ) : '';

        if ( empty( $component ) ) {
            wp_send_json_error([
                'message' => __( 'No se ha especificado el componente a cargar.', 'mi-integracion-api' ),
                'code'    => 'missing_component'
            ]);
            exit;
        }

        try {
            // Verificar que el LazyLoader esté disponible
            if ( ! class_exists( 'MiIntegracionApi\\Core\\LazyLoader' ) ) {
                throw new \Exception( __( 'El cargador de componentes no está disponible.', 'mi-integracion-api' ) );
            }

            // Cargar el componente mediante el LazyLoader
            $result = \MiIntegracionApi\Core\LazyLoader::execute_observer( $component );

            if ( $result ) {
                // Registrar en el log de depuración si está habilitado
                if ( defined( 'WP_DEBUG' ) && constant('WP_DEBUG') ) {
                    error_log( sprintf(
                        /* translators: %s: Nombre del componente */
                        __( 'MiIntegracionApi: Componente cargado exitosamente vía AJAX: %s', 'mi-integracion-api' ),
                        $component
                    ) );
                }

                wp_send_json_success([
                    'message'  => __( 'Componente cargado correctamente.', 'mi-integracion-api' ),
                    'component' => $component
                ]);
            } else {
                throw new \Exception( __( 'El componente solicitado no pudo ser cargado.', 'mi-integracion-api' ) );
            }
        } catch ( \Exception $e ) {
            // Registrar el error para depuración
            error_log( 'Error en handle_lazyload: ' . $e->getMessage() );
            
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code'    => 'component_load_error',
                'component' => $component,
                'trace'   => ( defined( 'WP_DEBUG' ) && constant('WP_DEBUG') ) ? $e->getTraceAsString() : null
            ]);
        }

        exit;
    }
}

// Registrar el manejador AJAX cuando el archivo se carga
// Solo se registrará si estamos en el área de administración o en una petición AJAX
if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
    add_action('plugins_loaded', ['MiIntegracionApi\Admin\AjaxLazyLoading', 'register_ajax_handler']);
}
