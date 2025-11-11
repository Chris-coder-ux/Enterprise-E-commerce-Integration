<?php
/**
 * Cliente principal para la API de Verial
 *
 * Este componente maneja todas las comunicaciones con la API de Verial,
 * incluyendo autenticación, gestión de sesiones, y manejo de errores.
 *
 * @package    MiIntegracionApi
 * @subpackage Services
 * @since      1.0.0
 * @version    1.1.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\Services;

use MiIntegracionApi\Helpers\Logger;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase VerialApiClient
 *
 * Gestiona la comunicación con la API de Verial proporcionando métodos
 * para realizar peticiones HTTP y procesar respuestas de forma consistente.
 *
 * @package MiIntegracionApi\Services
 * @since   1.0.0
 */
class VerialApiClient {
    /**
     * Configuración del cliente
     *
     * @var object|null
     * @since 1.0.0
     */
    private $config;

    /**
     * Instancia del logger para registro de eventos
     *
     * @var Logger
     * @since 1.0.0
     */
    private Logger $logger;

    /**
     * URL base de la API de Verial
     *
     * @var string
     * @since 1.0.0
     */
    private string $base_url;

    /**
     * ID de sesión para autenticación con la API
     *
     * @var int
     * @since 1.0.0
     */
    private int $session_id;

    /**
     * Constructor del cliente de la API
     *
     * @param object|null $config Configuración del cliente (opcional)
     * @param Logger|null $logger Instancia del logger (opcional)
     * @since 1.0.0
     */
    public function __construct($config = null, ?Logger $logger = null) {
        $this->config = $config;
        $this->logger = $logger ?: new Logger('verial_api_client');
        
        // Configurar URL base y sesión con valores por defecto si no se proporcionan
        $this->base_url = $this->config ? $this->config->getBaseUrl() : 'http://x.verial.org:8000/WcfServiceLibraryVerial';
        $this->session_id = $this->config ? $this->config->getVerialSessionId() : 18;
    }

    /**
     * Realiza una petición GET a la API de Verial
     *
     * @param string $endpoint Punto final de la API (sin la URL base)
     * @param array<string, mixed> $params Parámetros de la consulta (se añadirán a la URL)
     * @return array<string, mixed> Respuesta de la API decodificada como array
     * @since 1.0.0
     */
    public function get(string $endpoint, array $params = []): array {
        try {
            // Añadir sesión a los parámetros
            $params['x'] = $this->session_id;
            
            $url = $this->base_url . '/' . $endpoint . '?' . http_build_query($params);
            
            $this->logger->info("Verial API GET: {$endpoint}", ['url' => $url, 'params' => $params]);
            
            // Realizar petición HTTP
            $response = $this->makeHttpRequest('GET', $url);
            
            return $this->processResponse($response, $endpoint);
            
        } catch (\Exception $e) {
            $this->logger->error("Error en GET {$endpoint}", [
                'error' => $e->getMessage(),
                'params' => $params
            ]);
            return ['InfoError' => ['Codigo' => -1, 'Descripcion' => $e->getMessage()]];
        }
    }

    /**
     * Realiza una petición POST a la API de Verial
     *
     * @param string $endpoint Punto final de la API (sin la URL base)
     * @param array<string, mixed> $data Datos a enviar en el cuerpo de la petición
     * @return array<string, mixed> Respuesta de la API decodificada como array
     * @since 1.0.0
     */
    public function post(string $endpoint, array $data = []): array {
        try {
            // Añadir sesión a los datos
            $data['sesionwcf'] = $this->session_id;
            
            $url = $this->base_url . '/' . $endpoint;
            $json_data = json_encode($data, JSON_UNESCAPED_UNICODE);
            
            $this->logger->info("Verial API POST: {$endpoint}", ['url' => $url, 'data_size' => strlen($json_data)]);
            
            // Realizar petición HTTP
            $response = $this->makeHttpRequest('POST', $url, $json_data);
            
            return $this->processResponse($response, $endpoint);
            
        } catch (\Exception $e) {
            $this->logger->error("Error en POST {$endpoint}", [
                'error' => $e->getMessage(),
                'data_keys' => array_keys($data)
            ]);
            return ['InfoError' => ['Codigo' => -1, 'Descripcion' => $e->getMessage()]];
        }
    }

    /**
     * Realiza la petición HTTP real usando cURL
     *
     * @param string $method Método HTTP (GET, POST, etc.)
     * @param string $url URL completa de la petición
     * @param string|null $data Datos a enviar en el cuerpo (para POST)
     * @return string Respuesta HTTP en bruto
     * @throws Exception Si hay un error en la petición cURL o el código de estado no es 200
     * @since 1.0.0
     */
    private function makeHttpRequest(string $method, string $url, string $data = null): string {
        // En entorno de test, simular respuestas
        if (defined('VERIAL_TEST_MODE') && VERIAL_TEST_MODE) {
            return $this->getMockResponse($method, $url, $data);
        }

        // Usar cURL para peticiones reales
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'MiIntegracionApi/1.0',
        ]);

        if ($method === 'POST' && $data) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $data,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($data)
                ]
            ]);
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("cURL Error: {$error}");
        }

        if ($http_code !== 200) {
            throw new \Exception("HTTP Error: {$http_code}");
        }

        return $response;
    }

    /**
     * Procesa la respuesta JSON de la API
     *
     * @param string $response Respuesta HTTP en formato JSON
     * @param string $endpoint Punto final de la API para propósitos de registro
     * @return array<string, mixed> Datos de la respuesta decodificados
     * @throws Exception Si hay un error al decodificar el JSON
     * @since 1.0.0
     */
    private function processResponse(string $response, string $endpoint): array {
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("JSON Error: " . json_last_error_msg());
        }

        // Verificar errores de Verial
        if (isset($data['InfoError']) && $data['InfoError']['Codigo'] !== 0) {
            $this->logger->warning("Verial API Error in {$endpoint}", $data['InfoError']);
        }

        return $data;
    }

    /**
     * Genera respuestas simuladas para pruebas unitarias
     *
     * Este método solo se utiliza cuando la constante VERIAL_TEST_MODE está definida como true.
     * Proporciona respuestas predefinidas para puntos finales específicos de la API.
     *
     * @param string $method Método HTTP (GET, POST, etc.)
     * @param string $url URL de la petición
     * @param string|null $data Datos de la petición (opcional)
     * @return string Respuesta simulada en formato JSON
     * @since 1.0.0
     */
    private function getMockResponse(string $method, string $url, string $data = null): string {
        // Respuestas simuladas para testing
        if (strpos($url, 'GetPaisesWS') !== false) {
            return json_encode([
                'InfoError' => ['Codigo' => 0, 'Descripcion' => null],
                'Paises' => [
                    ['Id' => 1, 'Nombre' => 'España', 'ISO2' => 'ES', 'ISO3' => 'ESP']
                ]
            ]);
        }

        if (strpos($url, 'GetProvinciasWS') !== false) {
            return json_encode([
                'InfoError' => ['Codigo' => 0, 'Descripcion' => null],
                'Provincias' => [
                    ['Id' => 37, 'Nombre' => 'Salamanca', 'ID_Pais' => 1, 'CodigoNUTS' => 'ES413', 'CodigoProvinciaEsp' => '37']
                ]
            ]);
        }

        if (strpos($url, 'GetLocalidadesWS') !== false) {
            return json_encode([
                'InfoError' => ['Codigo' => 0, 'Descripcion' => null],
                'Localidades' => [
                    ['Id' => 3701, 'Nombre' => 'Salamanca', 'ID_Provincia' => 37, 'ID_Pais' => 1, 'CodigoNUTS' => 'ES413', 'CodigoMunicipioINE' => '37274']
                ]
            ]);
        }

        // Respuesta genérica de éxito
        return json_encode([
            'InfoError' => ['Codigo' => 0, 'Descripcion' => null],
            'Result' => 'Mock response for testing'
        ]);
    }

    /**
     * Verifica si una respuesta de la API indica éxito
     *
     * @param array<string, mixed> $response Respuesta de la API
     * @return bool true si la respuesta indica éxito (código de error 0), false en caso contrario
     * @since 1.0.0
     */
    public function isSuccess(array $response): bool {
        return isset($response['InfoError']) && $response['InfoError']['Codigo'] === 0;
    }

    /**
     * Obtiene el mensaje de error de una respuesta de la API
     *
     * @param array<string, mixed> $response Respuesta de la API
     * @return string Mensaje de error o cadena vacía si no hay error
     * @since 1.0.0
     */
    public function getErrorMessage(array $response): string {
        if (isset($response['InfoError']) && $response['InfoError']['Codigo'] !== 0) {
            return $response['InfoError']['Descripcion'] ?? 'Error desconocido';
        }
        return '';
    }
}