<?php declare(strict_types=1);

namespace MiIntegracionApi\Endpoints;

/**
 * Clase para el endpoint GetFabricantesWS de la API de Verial ERP.
 * Obtiene el listado de fabricantes y editores, según el manual v1.7.5.
 *
 * @package MiIntegracionApi
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Traits\EndpointLogger;

class GetFabricantesWS extends Base {

    use EndpointLogger;

    const ENDPOINT_NAME = 'GetFabricantesWS';
	// Usando constantes centralizadas de caché
    const CACHE_KEY_PREFIX = 'mi_api_fabricantes_';/**
     * Constructor
     */
    public function __construct( \MiIntegracionApi\Core\ApiConnector $connector ) {
        parent::__construct( $connector );
        $this->init_logger();
    }

    /**
     * Obtiene el listado de fabricantes
     *
     * @param array $args Argumentos para la petición
     * @return array Respuesta procesada con los fabricantes
     */
    public function get_fabricantes($args = []) {
        // Iniciar la medición del tiempo
        $start_time = microtime(true);
        
        // Ver si hay datos en caché
        $cache_key = self::CACHE_KEY_PREFIX . md5(serialize($args));
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            $this->log_debug("Datos recuperados de caché para fabricantes");
            return $cached_data;
        }
        
        // Preparar la solicitud a la API
        $api_connector = \MiIntegracionApi\Helpers\ApiHelpers::get_connector();
        $response = $api_connector->make_api_call(self::ENDPOINT_NAME, $args);
        
        // Si hay error en la respuesta, registrarlo y retornar la respuesta tal cual
        if (!isset($response['success']) || $response['success'] !== true) {
            $error_message = isset($response['error']) ? $response['error'] : 'Error desconocido';
            $this->log_error("Error al obtener fabricantes: {$error_message}");
            return $response;
        }
        
        // Procesar los resultados
        $fabricantes = [];
        
        if (isset($response['data']['result'])) {
            $fabricantes = $this->process_fabricantes($response['data']['result']);
        }
        
        $result = $this->format_success_response($fabricantes);
        
        // Guardar en caché
        set_transient($cache_key, $result, self::CACHE_EXPIRATION);
        
        // Registrar tiempo de ejecución
        $execution_time = microtime(true) - $start_time;
        $this->log_debug("Fabricantes obtenidos en {$execution_time} segundos");
        
        return $result;
    }
    
    /**
     * Procesa los datos de fabricantes de la respuesta de la API
     *
     * @param array $data Datos de la API
     * @return array Datos procesados
     */
    private function process_fabricantes($data) {
        $fabricantes = [];
        
        if (is_array($data)) {
            foreach ($data as $item) {
                // Adaptamos para diferentes formatos del campo de ID
                $id = null;
                if (isset($item['ID_Fabricante'])) {
                    $id = $item['ID_Fabricante'];
                } elseif (isset($item['Id'])) {
                    $id = $item['Id'];
                } elseif (isset($item['Codigo'])) {
                    $id = $item['Codigo'];
                }
                
                if ($id !== null && isset($item['Nombre'])) {
                    $fabricantes[] = [
                        'id' => sanitize_text_field($id),
                        'nombre' => sanitize_text_field($item['Nombre'])
                    ];
                }
            }
        }
        
        return $fabricantes;
    }
    
    /**
     * Implementación requerida por la clase abstracta Base.
     * El registro real de rutas ahora está centralizado en REST_API_Handler.php
     */
    public function register_route(): void {
        // Esta implementación está vacía ya que el registro real
        // de rutas ahora se hace de forma centralizada en REST_API_Handler.php
    }

    /**
     * Formatea la respuesta de Verial para el endpoint de fabricantes
     *
     * @param array $verial_response Respuesta de la API de Verial
     * @return \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
     */
    protected function format_verial_response(array $verial_response): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
        if (!isset($verial_response['Fabricantes']) || !is_array($verial_response['Fabricantes'])) {
            $this->logger->errorProducto(
                '[GetFabricantesWS] La respuesta de Verial no contiene la clave "Fabricantes" esperada o no es un array.',
                array('verial_response' => $verial_response)
            );
            $error_message = __('Los datos de fabricantes recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api');
            return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
                false,
                ['verial_response' => $verial_response],
                new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
                    'verial_api_malformed_fabricantes_data',
                    $error_message,
                    ['status' => 500]
                ),
                500,
                $error_message,
                ['endpoint' => 'format_verial_response']
            );
        }

        $fabricantes = [];
        foreach ($verial_response['Fabricantes'] as $fabricante_verial) {
            $fabricantes[] = [
                'id' => isset($fabricante_verial['Id']) ? (int) $fabricante_verial['Id'] : null,
                'nombre' => isset($fabricante_verial['Nombre']) ? sanitize_text_field($fabricante_verial['Nombre']) : null,
            ];
        }
        
        return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
            $fabricantes,
            __('Fabricantes obtenidos correctamente.', 'mi-integracion-api'),
            [
                'endpoint' => self::ENDPOINT_NAME,
                'items_count' => count($fabricantes),
                'timestamp' => time()
            ]
        );
    }

    /**
     * Implementación requerida por la clase abstracta Base.
     * Ejecuta la lógica principal del endpoint REST.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
     */
    public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
        $params = $request->get_params();
        $sesionwcf = $params['sesionwcf'] ?? null;
        $force_refresh = $params['force_refresh'] ?? false;

        if (empty($sesionwcf)) {
            $error_message = __('El parámetro sesionwcf es requerido.', 'mi-integracion-api');
            return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
                false,
                ['field' => 'sesionwcf', 'value' => $sesionwcf],
                new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
                    'rest_missing_param',
                    $error_message,
                    ['status' => 400, 'field' => 'sesionwcf']
                ),
                400,
                $error_message,
                ['endpoint' => 'execute_restful']
            );
        }

        $cache_params_for_key = array(
            'sesionwcf' => $sesionwcf,
        );

        if (!$force_refresh) {
            $cached_data = $this->get_cached_data($cache_params_for_key);
            if (false !== $cached_data) {
                return rest_ensure_response($cached_data);
            }
        }

        $verial_api_params = array('x' => $sesionwcf);
        $response_verial = $this->connector->get(self::ENDPOINT_NAME, $verial_api_params);

        // Verificar si la respuesta de Verial es exitosa
        if ($response_verial instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$response_verial->isSuccess()) {
            return rest_ensure_response($response_verial);
        }

        // Formatear la respuesta de Verial
        $formatted_response = $this->format_verial_response($response_verial);
        
        // Convertir SyncResponseInterface a WP_REST_Response usando WordPressAdapter
        $wp_response = \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($formatted_response);
        
        // Si la respuesta es exitosa, guardar en caché
        if ($formatted_response->isSuccess() && $this->use_cache()) {
            $formatted_data = $formatted_response->getData();
            // Usar la función base para formatear la respuesta estándar
            $formatted_response_data = $this->format_success_response($formatted_data);
            $this->set_cached_data($cache_params_for_key, $formatted_response_data);
        }
        
        return $wp_response;
    }
}
