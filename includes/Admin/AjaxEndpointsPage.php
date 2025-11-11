<?php
/**
 * Manejo de endpoints AJAX para pruebas de API
 *
 * Este archivo contiene la clase AjaxEndpointsPage que gestiona todas las operaciones
 * relacionadas con las pruebas de endpoints de la API desde el panel de administración.
 *
 * Características principales:
 * - Sistema de prueba de endpoints unificado
 * - Validación de seguridad robusta
 * - Manejo de errores detallado
 * - Soporte para múltiples tipos de peticiones (GET/POST)
 * - Integración con el sistema de autenticación
 *
 * @package     MiIntegracionApi\Admin
 * @since       1.0.0
 * @version     1.0.0
 * @author      Your Name <your.email@example.com>
 * @copyright   Copyright (c) 2025, Your Company
 * @license     GPL-2.0+
 * @link        https://example.com/plugin-docs/endpoints
 */

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

// Seguridad: Salir si se accede directamente
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para el manejo de endpoints AJAX de prueba
 *
 * Esta clase proporciona métodos para probar y validar los endpoints de la API
 * desde el panel de administración. Incluye funcionalidades para:
 * - Probar diferentes tipos de endpoints (GET/POST)
 * - Validar parámetros de entrada
 * - Gestionar respuestas y errores
 * - Proporcionar retroalimentación detallada
 *
 * @package     MiIntegracionApi\Admin
 * @since       1.0.0
 * @version     1.0.0
 * @see         \MiIntegracionApi\Core\InputValidation Para la validación de entradas
 * @see         MI_Nonce_Manager Para la gestión de seguridad
 * @property    object $api_connector Instancia del conector de API
 */
class AjaxEndpointsPage {
    /**
     * Registra los manejadores de acciones AJAX
     *
     * Este método registra todos los hooks necesarios para manejar las peticiones AJAX
     * relacionadas con la prueba de endpoints. Los hooks registrados se ejecutarán
     * cuando se realicen peticiones a los endpoints definidos.
     *
     * Acciones registradas:
     * - wp_ajax_mi_test_endpoint: Maneja las pruebas de endpoints
     *
     * @return void
     * @since 1.0.0
     * @hook admin_init Debe llamarse durante la inicialización del administrador
     * @see handle_ajax() Manejador principal para las peticiones AJAX
     * @uses add_action() Para registrar los hooks de WordPress
     *
     * @example
     * ```php
     * // En el archivo principal del plugin
     * add_action('admin_init', ['MiIntegracionApi\Admin\AjaxEndpointsPage', 'register_ajax_handler']);
     * ```
     */
    public static function register_ajax_handler() {
		add_action('wp_ajax_mi_test_endpoint', [self::class, 'handle_ajax']);
	}

    /**
     * Maneja las peticiones AJAX para probar endpoints
     *
     * Este método centralizado procesa todas las peticiones AJAX para probar endpoints
     * de la API. Maneja diferentes tipos de operaciones (GET/POST) y proporciona
     * respuestas estandarizadas en formato JSON.
     *
     * Flujo de ejecución:
     * 1. Verificación de seguridad (nonce y permisos)
     * 2. Validación y saneamiento de parámetros
     * 3. Enrutamiento al manejador específico del endpoint
     * 4. Procesamiento de la respuesta
     * 5. Envío de la respuesta JSON
     *
     * @return void Envía una respuesta JSON con la siguiente estructura:
     *              {
     *                  "success": boolean,
     *                  "data": mixed,    // Datos de respuesta
     *                  "message": string // Mensaje descriptivo
     *              }
     * @since 1.0.0
     * @throws \Exception Si ocurre un error durante el procesamiento
     * @security check_ajax_referer() Verificado por MI_Nonce_Manager
     * @permission manage_woocommerce
     * @hook wp_ajax_mi_test_endpoint
     * @see MI_Nonce_Manager::verify_ajax_request() Para la verificación de seguridad
     * @see \MiIntegracionApi\Core\InputValidation Para la validación de entradas
     * @global object $GLOBALS['mi_api_connector'] Instancia del conector de API
     *
     * @example
     * ```javascript
     * // Ejemplo de llamada AJAX desde JavaScript
     * jQuery.ajax({
     *     url: ajaxurl,
     *     type: 'POST',
     *     data: {
     *         action: 'mi_test_endpoint',
     *         endpoint: 'get_articulos',
     *         _ajax_nonce: mi_vars.nonce
     *     },
     *     success: function(response) {
     *         console.log(response);
     *     }
     * });
     * ```
     */
    public static function handle_ajax() {
        // Verificar nonce y permisos en una sola llamada usando nuestra clase centralizada
        \MI_Nonce_Manager::verify_ajax_request( 'test_endpoint', 'manage_woocommerce' );
        
        // Usar nuestra nueva clase centralizada de validación
        $endpoint = \MiIntegracionApi\Core\InputValidation::get_post_var( 'endpoint', 'key', '' );
        $param    = \MiIntegracionApi\Core\InputValidation::get_post_var( 'param', 'text', '' );
        
        // Obtener el conector de la API desde la variable global
        $api_connector = isset( $GLOBALS['mi_api_connector'] ) ? $GLOBALS['mi_api_connector'] : null;
        // Validar que tengamos tanto el conector como el endpoint
        if ( ! $api_connector || ! $endpoint ) {
            wp_send_json_error( [
                'message' => __( 'Endpoint o conector no válido.', 'mi-integracion-api' ),
                'code'    => 'invalid_endpoint_or_connector'
            ] );
        }
        
        // No usar caché para operaciones POST (son modificaciones)
        try {
            $result = null;
            
            // Decodificar los parámetros JSON si existen
            $params = !empty($param) ? json_decode($param, true) : [];
            
            // Verificar si la decodificación JSON fue exitosa cuando hay parámetros
			if (!empty($param) && $params === null) {
				wp_send_json_error( array( 'message' => __( 'Error en el formato JSON de los parámetros.', 'mi-integracion-api' ) ) );
				return;
			}
			
            // Manejar diferentes tipos de endpoints
            // Cada caso representa un endpoint específico de la API
            switch ( $endpoint ) {
                /**
                 * Crea un nuevo pedido en el sistema externo
                 * @param array $params Debe contener los datos del pedido
                 * @return array Respuesta del servidor con los datos del pedido creado
                 */
                case 'crear_pedido':
                    $result = $api_connector->post('pedidos/crear', $params);
                    break;
                /**
                 * Actualiza el stock de un artículo en el sistema externo
                 * @param array $params Debe contener 'articulo_id' y 'cantidad'
                 * @return array Respuesta del servidor con el stock actualizado
                 */
                case 'actualizar_stock':
                    $result = $api_connector->post('articulos/actualizar_stock', $params);
                    break;
                    
                /**
                 * Crea un nuevo cliente en el sistema externo
                 * @param array $params Debe contener los datos del cliente
                 * @return array Respuesta del servidor con los datos del cliente creado
                 */
                case 'crear_cliente':
                    $result = $api_connector->post('clientes/crear', $params);
                    break;
                    
                /**
                 * Actualiza los precios de los artículos en el sistema externo
                 * @param array $params Debe contener un array de artículos con sus nuevos precios
                 * @return array Respuesta del servidor con el resultado de la actualización
                 */
                case 'actualizar_precios':
                    $result = $api_connector->post('articulos/actualizar_precios', $params);
                    break;
                    
                /**
                 * Crea un nuevo artículo en el sistema externo
                 * @param array $params Debe contener los datos del artículo
                 * @return array Respuesta del servidor con los datos del artículo creado
                 */
                case 'crear_articulo':
					$result = $api_connector->post('articulos/crear', $params);
					break;
				// Endpoints GET para consulta de datos
				/**
				 * Obtiene la lista de artículos del sistema externo
				 * @return array Lista de artículos con sus datos completos
				 */
				case 'get_articulos':
					$result = $api_connector->get('GetArticulosWS');
					break;
				/**
				 * Obtiene la lista de clientes del sistema externo
				 * @return array Lista de clientes con sus datos completos
				 */
				case 'get_clientes':
					$result = $api_connector->get_clientes();
					break;
				/**
				 * Obtiene la lista de pedidos del sistema externo
				 * @return array Lista de pedidos con sus datos completos
				 */
				case 'get_pedidos':
					$result = $api_connector->get_pedidos();
					break;
                /**
                 * Obtiene el stock de artículos específicos del sistema externo
                 * @param string $param ID del artículo (opcional, si no se proporciona devuelve todos)
                 * @return array Lista de artículos con su stock actualizado
                 * @since 1.0.0
                 */
                case 'get_stock':
                    $result = $api_connector->get_stock_articulos( $param ? array( $param ) : array() );
                    break;
                    
                /**
                 * Obtiene las condiciones de tarifa vigentes
                 * @param string $param Código de tarifa específica (opcional)
                 * @return array Lista de condiciones de tarifa con sus detalles
                 * @since 1.0.0
                 */
                case 'get_condiciones_tarifa':
                    $result = $api_connector->get_condiciones_tarifa( $param, 0, null, date( 'Y-m-d' ) );
                    break;
                    
                // Si el endpoint no coincide con ninguno de los definidos
                default:
                    wp_send_json_error( [
                        'message' => __( 'Endpoint no soportado.', 'mi-integracion-api' ),
                        'code'    => 'unsupported_endpoint',
                        'endpoint' => $endpoint
                    ] );
            }

            // Manejar errores de WordPress
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [
                    'message' => $result->get_error_message(),
                    'code'    => $result->get_error_code(),
                    'data'    => $result->get_error_data()
                ] );
            }

            // Limitar el tamaño de la respuesta para listas grandes
            if ( is_array( $result ) ) {
                // Para listas, devolver solo los primeros 10 elementos para evitar sobrecarga
                if ( count( $result ) > 10 ) {
                    $result = array_slice( $result, 0, 10 );
                    // Añadir metadatos indicando que se truncó la respuesta
                    $result['_metadata'] = [
                        'total_items' => count($result),
                        'items_returned' => 10,
                        'message' => 'Se muestran solo los primeros 10 elementos. Use parámetros de paginación para obtener más resultados.'
                    ];
                }
            }
            
            // Almacenar en caché el resultado por 5 minutos
            if (!empty($cache_key)) {
                set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
            }
            
            // Enviar respuesta exitosa con los datos
            wp_send_json_success( $result );
            
        } catch ( \Exception $e ) {
            // Registrar el error para depuración
            error_log('Error en handle_ajax: ' . $e->getMessage());
            
            // Enviar respuesta de error con información detallada
            wp_send_json_error([
                'message' => 'Error al procesar la solicitud: ' . $e->getMessage(),
                'code' => 'api_error',
                'endpoint' => $endpoint,
                'trace' => (defined('WP_DEBUG') && constant('WP_DEBUG')) ? $e->getTraceAsString() : null
            ]);
        }
    }
}

// Registrar el manejador AJAX cuando el archivo se carga
add_action('plugins_loaded', function() {
    if (defined('DOING_AJAX') && DOING_AJAX) {
        AjaxEndpointsPage::register_ajax_handler();
    }
});
