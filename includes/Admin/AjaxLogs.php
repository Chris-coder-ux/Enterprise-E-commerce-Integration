<?php
/**
 * Módulo de gestión de logs vía AJAX
 *
 * Este archivo implementa el sistema de gestión de logs a través de peticiones AJAX
 * en el panel de administración de WordPress, permitiendo la consulta y filtrado
 * eficiente de registros de actividad del sistema.
 *
 * Características principales:
 * - Obtención de logs con múltiples filtros
 * - Paginación de resultados
 * - Validación de seguridad y permisos
 * - Respuestas JSON estandarizadas
 * - Integración con el sistema de logs de WordPress
 *
 * @package     MiIntegracionApi\Admin
 * @since       1.0.0
 * @version     1.2.1
 * @author      Your Name <your.email@example.com>
 * @copyright   Copyright (c) 2025, Your Company
 * @license     GPL-2.0+
 * @link        https://example.com/plugin-docs/logs
 */

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salir si se accede directamente
}

/**
 * Clase para el manejo de peticiones AJAX relacionadas con logs
 *
 * Esta clase proporciona una interfaz completa para la gestión de logs a través de AJAX,
 * incluyendo filtrado, paginación y control de acceso. Está diseñada para funcionar
 * de manera eficiente incluso con grandes volúmenes de registros.
 *
 * Características principales:
 * - Filtrado avanzado de logs por múltiples criterios
 * - Paginación con conteo total de resultados
 * - Validación de seguridad con nonces de WordPress
 * - Control de acceso basado en capacidades de usuario
 * - Manejo de errores con respuestas JSON estandarizadas
 *
 * @package     MiIntegracionApi\Admin
 * @since       1.0.0
 * @version     1.2.1
 * @see         \MiIntegracionApi\Core\QueryOptimizer Para la consulta optimizada de logs
 * @property    \wpdb $wpdb Instancia global de WordPress Database Access
 * @global      \wpdb $wpdb Objeto global de base de datos de WordPress
 */
class AjaxLogs {
    /**
     * Registra los manejadores de acciones AJAX para la gestión de logs
     *
     * Este método registra todos los hooks necesarios para manejar las peticiones AJAX
     * relacionadas con la obtención de logs. Se ejecuta durante la carga de plugins.
     *
     * Acciones registradas:
     * - wp_ajax_verial_logs_get: Para obtener logs con filtros
     *
     * @return void
     * @since 1.0.0
     * @hook plugins_loaded Se ejecuta cuando todos los plugins están cargados
     * @see get_logs_ajax() Manejador para la obtención de logs
     * @uses add_action() Para registrar los hooks de WordPress
     *
     * @example
     * ```php
     * // Registrar el manejador en el archivo principal del plugin
     * add_action('plugins_loaded', ['MiIntegracionApi\Admin\AjaxLogs', 'register_ajax_handler']);
     * ```
     */
    public static function register_ajax_handler() {
        add_action( 'wp_ajax_verial_logs_get', [self::class, 'get_logs_ajax'] );
    }

    /**
     * Maneja la petición AJAX para obtener logs con filtros
     *
     * Este método procesa la solicitud AJAX para obtener logs con los filtros
     * y la paginación proporcionados. Incluye validación de seguridad y permisos.
     *
     * Flujo de ejecución:
     * 1. Validar nonce de seguridad
     * 2. Verificar permisos del usuario
     * 3. Procesar y validar parámetros de entrada
     * 4. Aplicar filtros y obtener logs
     * 5. Devolver respuesta JSON con los resultados
     *
     * @return void Envía una respuesta JSON con la siguiente estructura:
     *              - En caso de éxito:
     *                {
     *                  "success": true,
     *                  "data": {
     *                    "logs": [array de logs],
     *                    "total": 100,
     *                    "total_paginas": 5,
     *                    "pagina": 1,
     *                    "por_pagina": 20
     *                  }
     *                }
     *              - En caso de error:
     *                {
     *                  "success": false,
     *                  "data": {
     *                    "message": "Mensaje de error",
     *                    "code": "código_de_error",
     *                    "trace": "Traza de error (solo en modo depuración)"
     *                  }
     *                }
     *
     * @since 1.0.0
     * @throws \Exception Si ocurre un error durante la obtención de logs
     * @hook wp_ajax_verial_logs_get
     * @security check_ajax_referer('verial_logs_nonce', 'nonce')
     * @permission manage_options Requiere capacidad de administrador
     * @global \wpdb $wpdb Objeto de base de datos de WordPress
     * @see filtrar_logs() Para el filtrado real de los logs
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
     *         action: 'verial_logs_get',
     *         nonce: verial_vars.nonce, // Nonce de seguridad
     *         filtros: {
     *             nivel: 'error',
     *             fecha_desde: '2023-01-01',
     *             fecha_hasta: '2023-12-31',
     *             mensaje: 'error de conexión',
     *             pagina: 1
     *         }
     *     },
     *     success: function(response) {
     *         console.log('Logs cargados:', response.data.logs);
     *         console.log('Total de registros:', response.data.total);
     *     },
     *     error: function(error) {
     *         console.error('Error al cargar logs:', error.responseJSON.data.message);
     *     }
     * });
     * ```
     */
    public static function get_logs_ajax() {
        // Verificar nonce de seguridad
        check_ajax_referer( 'verial_logs_nonce', 'nonce' );

        // Verificar permisos del usuario
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error([
                'message' => __( 'No tienes permisos suficientes para acceder a los logs.', 'mi-integracion-api' ),
                'code'    => 'insufficient_permissions'
            ], 403);
            return;
        }

        try {
            // Obtener y sanitizar filtros
            $filtros = [];
            if ( isset( $_POST['filtros'] ) && is_array( $_POST['filtros'] ) ) {
                $filtros = array_map( 'sanitize_text_field', $_POST['filtros'] );
            }

            // Configurar paginación
            $pagina = isset( $filtros['pagina'] ) ? absint( $filtros['pagina'] ) : 1;
            $por_pagina = apply_filters( 'mi_integracion_api_logs_per_page', 20 );

            // Obtener logs filtrados
            $total = 0;
            $logs = self::filtrar_logs( $filtros, $pagina, $por_pagina, $total );

            // Calcular total de páginas
            $total_paginas = $por_pagina > 0 ? ceil( $total / $por_pagina ) : 1;

            // Devolver datos
            wp_send_json_success([
                'logs'          => $logs,
                'total'         => (int) $total,
                'total_paginas' => (int) $total_paginas,
                'pagina'        => (int) $pagina,
                'por_pagina'    => (int) $por_pagina
            ]);

        } catch ( \Exception $e ) {
            // Registrar el error para depuración
            error_log( 'Error en get_logs_ajax: ' . $e->getMessage() );
            
            wp_send_json_error([
                'message' => __( 'Error al obtener los logs: ', 'mi-integracion-api' ) . $e->getMessage(),
                'code'    => 'logs_retrieval_error',
                'trace'   => ( defined( 'WP_DEBUG' ) && constant('WP_DEBUG') ) ? $e->getTraceAsString() : null
            ]);
        }
    }

    /**
     * Filtra los logs según los parámetros proporcionados
     *
     * Este método actúa como un wrapper para el método get_filtered_logs de QueryOptimizer,
     * proporcionando una capa de abstracción que facilita el mantenimiento y las pruebas.
     *
     * Filtros soportados:
     * - nivel: Nivel de log (error, warning, info, debug, etc.)
     * - fecha_desde: Fecha de inicio para filtrar logs (formato Y-m-d)
     * - fecha_hasta: Fecha de fin para filtrar logs (formato Y-m-d)
     * - mensaje: Texto a buscar en el mensaje del log
     * - origen: Origen del log (opcional)
     * - usuario_id: ID del usuario relacionado con el log (opcional)
     *
     * @param array<string, mixed> $filtros Array asociativo de filtros para aplicar a la consulta
     * @param int $pagina Número de página actual (por defecto: 1)
     * @param int $por_pagina Cantidad de registros por página (por defecto: 20)
     * @param int &$total Referencia para almacenar el total de registros encontrados
     * @return array<array<string, mixed>> Lista de logs que coinciden con los filtros, donde cada log es un array asociativo
     * @throws \Exception Si el QueryOptimizer no está disponible o hay un error en la consulta
     * @since 1.0.0
     * @see \MiIntegracionApi\Core\QueryOptimizer::get_filtered_logs() Para la implementación real del filtrado
     * @uses apply_filters('mi_integracion_api_logs_per_page', 20) Para modificar el número de logs por página
     *
     * @example
     * ```php
     * // Ejemplo de uso:
     * $filtros = [
     *     'nivel' => 'error',
     *     'fecha_desde' => '2023-01-01',
     *     'fecha_hasta' => '2023-12-31',
     *     'mensaje' => 'error de conexión'
     * ];
     * $total = 0;
     * $logs = AjaxLogs::filtrar_logs($filtros, 1, 20, $total);
     * echo "Se encontraron $total logs";
     * ```
     */
    public static function filtrar_logs(array $filtros = [], int $pagina = 1, int $por_pagina = 20, int &$total = 0): array {
        if ( ! class_exists( 'MiIntegracionApi\\Core\\QueryOptimizer' ) ) {
            throw new \Exception( __( 'El optimizador de consultas no está disponible.', 'mi-integracion-api' ) );
        }
        
        return \MiIntegracionApi\Core\QueryOptimizer::get_filtered_logs( $filtros, $pagina, $por_pagina, $total );
    }
}

// Registrar el manejador AJAX cuando el archivo se carga
// Solo se registrará si estamos en el área de administración o en una petición AJAX
if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
    add_action('plugins_loaded', ['MiIntegracionApi\Admin\AjaxLogs', 'register_ajax_handler']);
}
