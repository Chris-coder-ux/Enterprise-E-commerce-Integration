<?php
/**
 * Gestiona la conexión con la API externa
 *
 * @package MiIntegracionApi
 * @subpackage Core
 * @since 2.0.0
 */

namespace MiIntegracionApi\Core;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para gestionar la conexión con la API externa
 */
class Connection_Manager {
    /**
     * Instancia única de la clase
     *
     * @var Connection_Manager
     */
    private static $instance = null;

    /**
     * URL base de la API
     *
     * @var string
     */
    private $api_url = '';

    /**
     * Clave de API
     *
     * @var string
     */
    private $api_key = '';

    /**
     * Secreto de API
     *
     * @var string
     */
    private $api_secret = '';

    /**
     * Verificar certificados SSL
     *
     * @var bool
     */
    private $verify_ssl = true;

    /**
     * Tiempo de espera en segundos
     *
     * @var int
     */
    private $timeout = 30;

    /**
     * Constructor privado para evitar instanciación directa
     */
    private function __construct() {
        $this->load_settings();
    }

    /**
     * Obtiene la instancia única de la clase
     *
     * @return Connection_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Carga la configuración desde las opciones de WordPress
     */
    private function load_settings() {
        $this->api_url = trailingslashit(get_option('mi_integracion_api_url', ''));
        $this->api_key = get_option('mi_integracion_api_key', '');
        $this->api_secret = get_option('mi_integracion_api_secret', '');
        $this->verify_ssl = '1' === get_option('mi_integracion_api_verify_ssl', '1');
        $this->timeout = (int) get_option('mi_integracion_api_timeout', 30);
    }

    /**
     * Prueba la conexión con la API
     *
     * @return array Resultado de la prueba de conexión
     */
    public function test_connection() {
        $start_time = microtime(true);
        $result = [
            'success' => false,
            'url' => $this->api_url,
            'response_code' => 0,
            'response_body' => '',
            'error' => '',
            'curl_error' => '',
            'timestamp' => current_time('timestamp')
        ];

        // Verificar si la URL está vacía
        if (empty($this->api_url)) {
            $result['error'] = __('La URL de la API no está configurada.', 'mi-integracion-api');
            return $result;
        }

        // Verificar si cURL está disponible
        if (!function_exists('curl_init')) {
            $result['error'] = __('La extensión cURL no está instalada o no está habilitada en este servidor.', 'mi-integracion-api');
            return $result;
        }

        // Configurar la URL de prueba
        $test_url = $this->api_url . 'test-connection';
        
        // Inicializar cURL
        $ch = curl_init();
        
        // Configurar opciones de cURL
        $options = [
            CURLOPT_URL => $test_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_SSL_VERIFYPEER => $this->verify_ssl,
            CURLOPT_SSL_VERIFYHOST => $this->verify_ssl ? 2 : 0,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->api_key,
                'X-API-Secret: ' . $this->api_secret
            ],
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ];
        
        // Aplicar las opciones
        curl_setopt_array($ch, $options);
        
        // Ejecutar la solicitud
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        // Cerrar la conexión cURL
        curl_close($ch);
        
        // Calcular el tiempo de respuesta
        $end_time = microtime(true);
        $response_time = $end_time - $start_time;
        
        // Procesar la respuesta
        $result['response_time'] = $response_time;
        $result['response_code'] = $http_code;
        $result['curl_error'] = $error;
        
        // Verificar si hubo un error de cURL
        if (!empty($error)) {
            $result['error'] = sprintf(__('Error de conexión: %s', 'mi-integracion-api'), $error);
            return $result;
        }
        
        // Verificar el código de estado HTTP
        if ($http_code < 200 || $http_code >= 300) {
            $result['error'] = sprintf(
                __('La API devolvió un código de estado inesperado: %d', 'mi-integracion-api'),
                $http_code
            );
            $result['response_body'] = $response;
            return $result;
        }
        
        // Decodificar la respuesta JSON
        $response_data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $result['error'] = __('La respuesta de la API no es un JSON válido.', 'mi-integracion-api');
            $result['response_body'] = $response;
            return $result;
        }
        
        // La conexión fue exitosa
        $result['success'] = true;
        $result['version'] = $response_data['version'] ?? __('Desconocida', 'mi-integracion-api');
        $result['rate_limit'] = [
            'limit' => $response_data['rate_limit']['limit'] ?? 0,
            'remaining' => $response_data['rate_limit']['remaining'] ?? 0
        ];
        
        return $result;
    }

    /**
     * Realiza una petición a la API
     *
     * @param string $endpoint Endpoint de la API
     * @param string $method Método HTTP (GET, POST, PUT, DELETE)
     * @param array $data Datos a enviar
     * @return array Respuesta de la API
     */
    public function request($endpoint, $method = 'GET', $data = []) {
        $url = $this->api_url . ltrim($endpoint, '/');
        
        // Inicializar cURL
        $ch = curl_init();
        
        // Configurar opciones comunes
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_SSL_VERIFYPEER => $this->verify_ssl,
            CURLOPT_SSL_VERIFYHOST => $this->verify_ssl ? 2 : 0,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->api_key,
                'X-API-Secret: ' . $this->api_secret,
                'Accept: application/json'
            ],
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ];
        
        // Configurar el método HTTP
        switch (strtoupper($method)) {
            case 'POST':
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
                break;
                
            case 'PUT':
            case 'DELETE':
                $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
                break;
                
            case 'GET':
            default:
                if (!empty($data)) {
                    $options[CURLOPT_URL] .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($data);
                }
                break;
        }
        
        // Aplicar las opciones
        curl_setopt_array($ch, $options);
        
        // Ejecutar la solicitud
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        // Cerrar la conexión cURL
        curl_close($ch);
        
        // Procesar la respuesta
        $result = [
            'success' => ($http_code >= 200 && $http_code < 300) && empty($error),
            'status_code' => $http_code,
            'data' => null,
            'error' => $error,
            'raw_response' => $response
        ];
        
        // Decodificar la respuesta JSON si existe
        if (!empty($response)) {
            $json_response = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $result['data'] = $json_response;
            } else {
                $result['data'] = $response;
            }
        }
        
        return $result;
    }
    
    /**
     * Verifica si la conexión está configurada correctamente
     * 
     * @return bool
     */
    public function is_configured() {
        return !empty($this->api_url) && !empty($this->api_key) && !empty($this->api_secret);
    }
    
    /**
     * Obtiene la URL base de la API
     * 
     * @return string
     */
    public function get_api_url() {
        return $this->api_url;
    }
    
    /**
     * Obtiene la clave de API
     * 
     * @return string
     */
    public function get_api_key() {
        return $this->api_key;
    }
    
    /**
     * Obtiene el secreto de API
     * 
     * @return string
     */
    public function get_api_secret() {
        return $this->api_secret;
    }
    
    /**
     * Previene la clonación de la instancia
     */
    private function __clone() {}
    
    /**
     * Previene la deserialización de la instancia
     */
    public function __wakeup() {}
}
?>