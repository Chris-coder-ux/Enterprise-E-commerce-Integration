<?php
/**
 * Módulo de exportación de datos de sincronización
 *
 * Este archivo contiene la clase AjaxExport que gestiona todas las operaciones
 * relacionadas con la exportación de datos de sincronización a través de AJAX.
 *
 * Características principales:
 * - Exportación segura de registros de sincronización
 * - Soporte para múltiples formatos (JSON, CSV, etc.)
 * - Validación de permisos y seguridad
 * - Manejo robusto de errores
 * - Integración con el sistema de logs
 *
 * @package     MiIntegracionApi\Admin
 * @since       1.2.0
 * @version     1.2.1
 * @author      Your Name <your.email@example.com>
 * @copyright   Copyright (c) 2025, Your Company
 * @license     GPL-2.0+
 * @link        https://example.com/plugin-docs/export
 */

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salir si se accede directamente.
}

if ( ! defined( 'MiIntegracionApi_VERSION' ) ) {
    define( 'MiIntegracionApi_VERSION', '1.2.1' );
}

/**
 * Clase para manejar las exportaciones de datos vía AJAX
 *
 * Esta clase proporciona métodos para exportar datos de sincronización
 * en diferentes formatos a través de peticiones AJAX seguras. Incluye:
 * - Exportación de registros de sincronización en formato JSON
 * - Validación de permisos y seguridad
 * - Manejo de errores y respuestas estandarizadas
 * - Generación de archivos de exportación descargables
 *
 * @package     MiIntegracionApi\Admin
 * @since       1.2.0
 * @version     1.2.1
 * @see         \wpdb Para el acceso a la base de datos
 * @property    \wpdb $wpdb Instancia global de WordPress Database Access
 * @global      \wpdb $wpdb Objeto global de base de datos de WordPress
 */
class AjaxExport {
    /**
     * Registra los manejadores de acciones AJAX para exportaciones
     *
     * Este método registra todos los hooks necesarios para manejar las peticiones AJAX
     * relacionadas con la exportación de datos de sincronización.
     *
     * Acciones registradas:
     * - wp_ajax_mia_export_sync_json: Maneja la exportación en formato JSON
     *
     * @return void
     * @since 1.2.0
     * @hook plugins_loaded Se registra cuando WordPress carga los plugins
     * @see export_sync_json_callback() Manejador para la exportación JSON
     * @uses add_action() Para registrar los hooks de WordPress
     *
     * @example
     * ```php
     * // Registrar el manejador en el archivo principal del plugin
     * add_action('plugins_loaded', ['MiIntegracionApi\Admin\AjaxExport', 'register_ajax_handler']);
     * ```
     */
    public static function register_ajax_handler() {
        add_action( 'wp_ajax_mia_export_sync_json', [self::class, 'export_sync_json_callback'] );
    }

    /**
     * Maneja la exportación de datos de sincronización en formato JSON
     *
     * Este método procesa la solicitud AJAX para exportar los datos de sincronización
     * en formato JSON. Incluye validación de seguridad y manejo de errores.
     *
     * Flujo de ejecución:
     * 1. Verificación de seguridad (nonce y permisos)
     * 2. Validación del ID de sincronización
     * 3. Obtención de datos desde la base de datos
     * 4. Configuración de cabeceras HTTP para descarga
     * 5. Envío de la respuesta JSON formateada
     *
     * @return void Envía una respuesta HTTP con los siguientes formatos posibles:
     *              - Archivo JSON descargable en caso de éxito
     *              - Respuesta JSON de error en caso de fallo
     * @since 1.2.0
     * @throws \Exception Si ocurre un error durante la exportación
     * @hook wp_ajax_mia_export_sync_json
     * @security check_ajax_referer('mia_export_sync_json', 'nonce')
     * @permission manage_options Requiere capacidad de administrador
     * @global \wpdb $wpdb Objeto de base de datos de WordPress
     * @see get_sync_data() Para la obtención de datos de sincronización
     * @see wp_send_json_error() Para el envío de errores JSON
     *
     * @example
     * ```javascript
     * // Ejemplo de llamada AJAX desde JavaScript
     * jQuery.ajax({
     *     url: ajaxurl,
     *     type: 'POST',
     *     data: {
     *         action: 'mia_export_sync_json',
     *         sync_id: 123,
     *         nonce: mia_vars.nonce
     *     },
     *     success: function(response) {
     *         console.log('Exportación exitosa');
     *     },
     *     error: function(error) {
     *         console.error('Error en la exportación', error);
     *     }
     * });
     * ```
     */
    public static function export_sync_json_callback() {
        // Verificar nonce de seguridad
        check_ajax_referer( 'mia_export_sync_json', 'nonce' );

        // Verificar permisos de usuario
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error([
                'mensaje' => __( 'No tienes permisos para realizar esta acción.', 'mi-integracion-api' ),
                'code'    => 'insufficient_permissions'
            ]);
            return;
        }

        // Obtener y validar el ID de sincronización
        $sync_id = isset( $_POST['sync_id'] ) ? intval( $_POST['sync_id'] ) : 0;

        if ( ! $sync_id ) {
            wp_send_json_error([
                'mensaje' => __( 'ID de sincronización no válido.', 'mi-integracion-api' ),
                'code'    => 'invalid_sync_id'
            ]);
            return;
        }

        try {
            // Obtener datos de sincronización de la base de datos
            $sync_data = self::get_sync_data( $sync_id );

            if ( empty( $sync_data ) ) {
                wp_send_json_error([
                    'mensaje' => __( 'No se encontraron datos de sincronización para el ID proporcionado.', 'mi-integracion-api' ),
                    'code'    => 'sync_data_not_found',
                    'sync_id' => $sync_id
                ]);
                return;
            }

            // Configurar cabeceras para la descarga del archivo
            header( 'Content-Type: application/json' );
            header( 'Content-Disposition: attachment; filename="sync_export_' . $sync_id . '_' . date( 'Y-m-d' ) . '.json"' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );

            // Enviar datos formateados para mejor legibilidad
            echo wp_json_encode( $sync_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            
            // Terminar la ejecución para evitar salida adicional
            exit;

        } catch ( \Exception $e ) {
            // Registrar el error para depuración
            error_log( 'Error en export_sync_json_callback: ' . $e->getMessage() );
            
            // Enviar respuesta de error detallada
            wp_send_json_error([
                'mensaje' => __( 'Error al exportar los datos de sincronización: ', 'mi-integracion-api' ) . $e->getMessage(),
                'code'    => 'export_error',
                'trace'   => (defined('WP_DEBUG') && constant('WP_DEBUG')) ? $e->getTraceAsString() : null
            ]);
        }
    }

    /**
     * Obtiene los datos de sincronización de la base de datos
     *
     * Este método consulta la base de datos para obtener los detalles de un registro
     * de sincronización específico, incluyendo metadatos adicionales y datos relacionados.
     *
     * Estructura de retorno:
     * ```php
     * [
     *     'id'             => int,      // ID del registro de sincronización
     *     'tipo'           => string,   // Tipo de sincronización
     *     'usuario_id'     => int,      // ID del usuario que realizó la sincronización
     *     'usuario_nombre' => string,   // Nombre del usuario
     *     'fecha'          => string,   // Fecha de la sincronización
     *     'duracion'       => float,    // Duración en segundos
     *     'status'         => string,   // Estado de la sincronización
     *     'datos'          => array,    // Datos de entrada de la sincronización
     *     'resultado'      => array,    // Resultado de la sincronización
     *     'metadatos'      => [         // Metadatos adicionales
     *         'exportado_el'   => string,   // Fecha de exportación
     *         'exportado_por'  => int,      // ID del usuario que exportó
     *         'version_plugin' => string    // Versión del plugin
     *     ]
     * ]
     * ```
     *
     * @param int $sync_id ID del registro de sincronización a exportar
     * @return array<string, mixed>|null Datos de sincronización o null si no se encuentra
     * @throws \Exception Si ocurre un error en la consulta a la base de datos
     * @since 1.2.0
     * @global \wpdb $wpdb Objeto de base de datos de WordPress
     * @see wpdb::prepare() Para consultas preparadas seguras
     * @see get_userdata() Para obtener información del usuario
     * @uses error_log() Para registrar errores en el log de PHP
     */
    protected static function get_sync_data(int $sync_id): ?array {
        global $wpdb;
        
        try {
            // Obtener el registro de sincronización de la base de datos
            $table_name = $wpdb->prefix . 'mia_sync_logs';
            $query = $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE id = %d",
                $sync_id
            );
            
            $registro = $wpdb->get_row( $query, ARRAY_A );
            
            if ( empty( $registro ) ) {
                return null;
            }
            
            // Preparar los datos para la exportación
            $datos_exportacion = [
                'id'             => (int) $registro['id'],
                'tipo'           => sanitize_text_field( $registro['tipo'] ),
                'usuario_id'     => (int) $registro['usuario_id'],
                'usuario_nombre' => get_userdata( $registro['usuario_id'] ) ? 
                    sanitize_text_field( get_userdata( $registro['usuario_id'] )->display_name ) : '',
                'fecha'          => sanitize_text_field( $registro['fecha'] ),
                'duracion'       => (float) $registro['duracion'],
                'status'         => sanitize_text_field( $registro['status'] ),
                'datos'          => json_decode( $registro['datos'], true ),
                'resultado'      => json_decode( $registro['resultado'], true ),
                'metadatos'      => [
                    'exportado_el'   => current_time( 'mysql' ),
                    'exportado_por'  => get_current_user_id(),
                    'version_plugin' => defined( 'MiIntegracionApi_VERSION' ) ? 
                        sanitize_text_field( constant('MiIntegracionApi_VERSION') ) : '1.0.0',
                ]
            ];
            
            return $datos_exportacion;
            
        } catch ( \Exception $e ) {
            error_log( 'Error en get_sync_data: ' . $e->getMessage() );
            throw new \Exception( __( 'Error al obtener los datos de sincronización.', 'mi-integracion-api' ) );
        }
    }
}

// Registrar el manejador AJAX cuando el archivo se carga
add_action('plugins_loaded', function() {
    if (defined('DOING_AJAX') && DOING_AJAX) {
        AjaxExport::register_ajax_handler();
    }
});
