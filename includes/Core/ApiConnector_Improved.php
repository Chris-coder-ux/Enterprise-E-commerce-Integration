<?php

declare(strict_types=1);

/**
 * ApiConnector mejorado con patrones de robustez y rendimiento
 *
 * Implementación mejorada de la clase ApiConnector que aborda los puntos
 * críticos identificados en el análisis de la integración:
 * - Manejo robusto de sesiones
 * - Sistema estructurado de errores
 * - Reintentos inteligentes
 * - Caché flexible
 * - Procesamiento por lotes dinámico
 *
 * @package mi-integracion-api
 * @subpackage Core
 */

namespace MiIntegracionApi\Core;

use MiIntegracionApi\CacheManager;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Traits\Singleton;
use MiIntegracionApi\DTOs\ApiResponse;
use MiIntegracionApi\DTOs\InfoError;

/**
 * Clase ApiConnector mejorada
 */
class ApiConnector_Improved {
    use Singleton;

    /**
     * URL base de la API de Verial
     * @var string
     */
    private $api_url;

    /**
     * Credenciales de la API
     * @var array
     */
    private $credentials;

    /**
     * Sesión WCF actual
     * @var string|null
     */
    private $session_wcf;

    /**
     * Manejador de caché
     * @var CacheManager
     */
    private $cache_manager;

    /**
     * Tiempo de inicio de sesión
     * @var int
     */
    private $session_start_time;

    /**
     * Configuración de reintentos
     * @var array
     */
    private $retry_config = [
        'max_attempts' => 3,
        'backoff_factor' => 1.5,
        'initial_delay' => 1, // segundos
        'jitter' => 0.2, // factor aleatorio para evitar tormentas de reconexión
    ];

    /**
     * Configuración de tamaño de lote dinámico
     * @var array
     */
    private $batch_size_config = [
        'default' => 50,
        'min' => 10,
        'max' => 100,
        'adjustment_factor' => 0.1, // Factor de ajuste basado en rendimiento
    ];

    /**
     * TTL de caché por tipo de datos
     * @var array
     */
    private $cache_ttl = [
        'getArticulosWS' => 3600, // 1 hora
        'getCategoriasArticulosWS' => 7200, // 2 horas
        'getClientesWS' => 86400, // 24 horas
        'getStockArticuloWS' => 900, // 15 minutos
        'getPrecioArticuloWS' => 1800, // 30 minutos
        'default' => 3600, // 1 hora (default)
    ];

    /**
     * Constructor
     */
    protected function __construct() {
        $this->api_url = get_option('mi_integracion_api_url', '');
        $this->credentials = [
            'user' => get_option('mi_integracion_api_user', ''),
            'password' => get_option('mi_integracion_api_password', ''),
        ];
        $this->cache_manager = CacheManager::get_instance();
        $this->session_wcf = null;
        $this->session_start_time = 0;
    }

    /**
     * Inicializa la conexión y obtiene una sesión
     *
     * @return bool Éxito de la inicialización
     * @throws \Exception Si hay un problema crítico de conexión
     */
    public function init() {
        try {
            return $this->create_session();
        } catch (\Exception $e) {
            (new \MiIntegracionApi\Helpers\Logger)->error('Error al inicializar ApiConnector: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Inicializa la configuración desde un array
     *
     * @param array $config Configuración para inicializar
     * @return bool Éxito de la inicialización
     */
    public function init_config($config = []) {
        if (!empty($config['api_url'])) {
            $this->api_url = $config['api_url'];
        }
        
        if (!empty($config['api_key'])) {
            $this->session_wcf = $config['api_key'];
        }
        
        if (!empty($config['test_mode'])) {
            // Usar función global para log en modo test
            // ...
            // Configuraciones específicas para modo de prueba
        }
        
        return true;
    }

    /**
     * Crea una nueva sesión con Verial
     *
     * @return bool Éxito de la creación de sesión
     * @throws \Exception Si hay un problema crítico con las credenciales
     */
    public function create_session() {
        // ...

        if (empty($this->api_url) || empty($this->credentials['user']) || empty($this->credentials['password'])) {
            (new \MiIntegracionApi\Helpers\Logger)->error('Credenciales de API incompletas');
            throw new \Exception('Credenciales de API incompletas. Por favor, configura la conexión en la página de administración.');
        }

        $endpoint = $this->api_url . '/Api/Login';
        $args = [
            'timeout' => 30,
            'httpversion' => '1.1',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode([
                'usuario' => $this->credentials['user'],
                'clave' => $this->credentials['password'],
            ]),
            'sslverify' => apply_filters('mi_integracion_api_ssl_verify', true),
        ];

        $response = wp_remote_post($endpoint, $args);
        
        if (is_wp_error($response)) {
            (new \MiIntegracionApi\Helpers\Logger)->error('Error en la solicitud de sesión: ' . $response->get_error_message());
            throw new \Exception('Error de conexión: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !isset($data['sesionwcf'])) {
            (new \MiIntegracionApi\Helpers\Logger)->error('Respuesta de sesión inválida: ' . $body);
            throw new \Exception('Respuesta de autenticación inválida. Verifica las credenciales y la conectividad.');
        }

        $this->session_wcf = $data['sesionwcf'];
        $this->session_start_time = time();
        (new \MiIntegracionApi\Helpers\Logger)->info('Sesión creada exitosamente');

        // Almacenar la sesión para uso transversal
        update_option('mi_integracion_api_session', $this->session_wcf, false);
        update_option('mi_integracion_api_session_time', $this->session_start_time, false);

        return true;
    }

    /**
     * Verifica si la sesión actual es válida y la renueva si es necesario
     *
     * @return bool Estado de la sesión
     */
    public function check_session() {
        // Si no hay sesión, crear una nueva
        if (empty($this->session_wcf)) {
            $this->session_wcf = get_option('mi_integracion_api_session', null);
            $this->session_start_time = (int)get_option('mi_integracion_api_session_time', 0);
        }

        // Si sigue sin sesión o ha pasado más de 20 minutos, renovar
        $session_age = time() - $this->session_start_time;
        if (empty($this->session_wcf) || $session_age > (20 * 60)) { // 20 minutos
            (new \MiIntegracionApi\Helpers\Logger)->info('Renovando sesión (edad: ' . $session_age . ' segundos)');
            return $this->create_session();
        }

        return true;
    }

    /**
     * Realiza una solicitud GET a la API de Verial con reintentos inteligentes
     *
     * @param string $endpoint Endpoint de la API
     * @param array  $params   Parámetros de la solicitud
     * @param array  $options  Opciones adicionales (caché, reintentos, etc)
     * @return ApiResponse     Objeto de respuesta
     * @throws \Exception      Si hay un error irrecuperable después de los reintentos
     */
    public function get($endpoint, $params = [], $options = []) {
        // Opciones por defecto
        $default_options = [
            'use_cache' => true,
            'cache_ttl' => null, // Usar el definido por tipo
            'force_refresh' => false,
            'retries' => $this->retry_config['max_attempts'],
            'batch_size' => $this->get_optimal_batch_size($endpoint)
        ];
        
        $options = wp_parse_args($options, $default_options);
        
        // Clave de caché basada en los parámetros
        $cache_key = 'verial_' . $endpoint . '_' . md5(serialize($params));
        
        // Verificar caché si está habilitado y no se fuerza refresco
        if ($options['use_cache'] && !$options['force_refresh']) {
            $cached_data = $this->cache_manager->get($cache_key);
            if ($cached_data !== false) {
                // ...
                return $cached_data;
            }
        }

        // Preparar solicitud con reintentos
        $attempt = 0;
        $last_exception = null;
        $delay = $this->retry_config['initial_delay'];

        while ($attempt < $options['retries']) {
            try {
                $attempt++;
                // ...
                
                // Verificar sesión antes de cada intento
                $this->check_session();
                
                // Ejecutar la solicitud
                $response = $this->execute_request($endpoint, $params);
                
                // Verificar errores en la respuesta
                if (!$this->validate_response($response)) {
                    // Si es un error de sesión, intentamos renovar
                    if ($this->is_session_error($response)) {
                        (new \MiIntegracionApi\Helpers\Logger)->warning("Error de sesión detectado, renovando sesión");
                        $this->create_session();
                        continue; // Reintentar con nueva sesión
                    }

                    // Otros errores, lanzar excepción
                    throw new \Exception(
                        "Error en respuesta de API: " . 
                        ($response->info_error ? json_encode($response->info_error) : 'Error desconocido')
                    );
                }
                
                // Si llegamos aquí, la respuesta es exitosa
                
                // Guardar en caché si corresponde
                if ($options['use_cache']) {
                    $ttl = $options['cache_ttl'] ?: $this->get_cache_ttl($endpoint);
                    $this->cache_manager->set($cache_key, $response, $ttl);
                }
                
                // Ajustar tamaño de lote según rendimiento
                $this->adjust_batch_size($endpoint, $response->execution_time);
                
                return $response;
                
            } catch (\Exception $e) {
                $last_exception = $e;
                (new \MiIntegracionApi\Helpers\Logger)->error("Error en intento $attempt para $endpoint: " . $e->getMessage());
                
                // No reintentar si es el último intento
                if ($attempt >= $options['retries']) {
                    break;
                }
                
                // Calcular retraso con backoff exponencial y jitter
                $jitter = $delay * $this->retry_config['jitter'] * (mt_rand(0, 1000) / 1000 - 0.5);
                $sleep_time = $delay + $jitter;
                // ...
                
                // Esperar antes del siguiente intento
                usleep((int)($sleep_time * 1000000));
                
                // Incrementar el retraso para el siguiente intento
                $delay *= $this->retry_config['backoff_factor'];
            }
        }
        
        // Si llegamos aquí, todos los intentos fallaron
        $message = $last_exception ? $last_exception->getMessage() : "Error desconocido";
        (new \MiIntegracionApi\Helpers\Logger)->error("Error irrecuperable después de $options[retries] intentos para $endpoint: $message");
        throw new \Exception("Error después de $options[retries] intentos: $message");
    }

    /**
     * Ejecuta la solicitud HTTP real a la API
     *
     * @param string $endpoint Endpoint de la API
     * @param array  $params   Parámetros de la solicitud
     * @return ApiResponse     Objeto de respuesta
     * @throws \Exception      Si hay un error en la solicitud
     */
    private function execute_request($endpoint, $params) {
        $start_time = microtime(true);
        
        // Asegurar que siempre enviamos sesionwcf
        $params['sesionwcf'] = $this->session_wcf;
        
        // Construir URL con parámetros
        $url = $this->api_url . '/Api/' . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $args = [
            'timeout' => 30,
            'httpversion' => '1.1',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'sslverify' => apply_filters('mi_integracion_api_ssl_verify', true),
        ];
        
        // ...
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            throw new \Exception('Error de conexión: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $execution_time = microtime(true) - $start_time;
        
        (new \MiIntegracionApi\Helpers\Logger)->debug("Respuesta HTTP $status_code recibida en $execution_time segundos");
        
        // Crear objeto de respuesta
        $api_response = new ApiResponse();
        $api_response->status_code = $status_code;
        $api_response->raw_body = $body;
        $api_response->data = $data;
        $api_response->execution_time = $execution_time;
        
        // Extraer InfoError si existe
        if (is_array($data) && isset($data['InfoError'])) {
            $info_error = new InfoError();
            $info_error->ErrorCode = $data['InfoError']['ErrorCode'] ?? 0;
            $info_error->ErrorMessage = $data['InfoError']['ErrorMessage'] ?? '';
            $info_error->ErrorType = $data['InfoError']['ErrorType'] ?? '';
            $api_response->info_error = $info_error;
        }
        
        return $api_response;
    }

    /**
     * Valida si una respuesta de la API es correcta
     *
     * @param ApiResponse $response Respuesta a validar
     * @return bool Si la respuesta es válida
     */
    private function validate_response($response) {
        // Verificar código de estado HTTP
        if ($response->status_code < 200 || $response->status_code >= 300) {
            (new \MiIntegracionApi\Helpers\Logger)->error("Error HTTP: " . $response->status_code);
            return false;
        }
        
        // Verificar si hay InfoError con error
        if ($response->info_error && $response->info_error->ErrorCode !== 0) {
            (new \MiIntegracionApi\Helpers\Logger)->error("InfoError: " . $response->info_error->ErrorCode . " - " . $response->info_error->ErrorMessage);
            return false;
        }
        
        // Verificar si la respuesta es vacía cuando no debería
        if (empty($response->data) && $response->status_code !== 204) {
            (new \MiIntegracionApi\Helpers\Logger)->warning("Respuesta vacía con código " . $response->status_code);
            return false;
        }
        
        return true;
    }

    /**
     * Determina si un error es relacionado con la sesión
     *
     * @param ApiResponse $response Respuesta a analizar
     * @return bool Si el error es de sesión
     */
    private function is_session_error($response) {
        // Errores conocidos de sesión en Verial
        $session_error_codes = [401, 403];
        $session_error_messages = ['sesion', 'session', 'autenticacion', 'credenciales'];
        
        // Verificar código HTTP
        if (in_array($response->status_code, $session_error_codes)) {
            return true;
        }
        
        // Verificar mensaje de error
        if ($response->info_error && $response->info_error->ErrorMessage) {
            $message = strtolower($response->info_error->ErrorMessage);
            foreach ($session_error_messages as $error_term) {
                if (strpos($message, $error_term) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Obtiene el TTL de caché apropiado según el tipo de datos
     *
     * @param string $endpoint El endpoint solicitado
     * @return int TTL en segundos
     */
    private function get_cache_ttl($endpoint) {
        $endpoint = strtolower($endpoint);
        
        foreach ($this->cache_ttl as $key => $ttl) {
            if (strtolower($key) === $endpoint) {
                return $ttl;
            }
        }
        
        return $this->cache_ttl['default'];
    }

    /**
     * Obtiene el tamaño de lote óptimo para un endpoint
     *
     * @param string $endpoint El endpoint solicitado
     * @return int Tamaño de lote recomendado
     */
    private function get_optimal_batch_size($endpoint) {
        $endpoint = strtolower($endpoint);
        
        // Intentar obtener el tamaño óptimo almacenado
        $optimal_sizes = get_option('mi_integracion_api_optimal_batch_sizes', []);
        
        if (isset($optimal_sizes[$endpoint])) {
            return $optimal_sizes[$endpoint];
        }
        
        // Si no hay uno almacenado, usar el valor por defecto
        return $this->batch_size_config['default'];
    }

    /**
     * Ajusta el tamaño de lote óptimo según el rendimiento
     *
     * @param string $endpoint El endpoint
     * @param float  $execution_time Tiempo de ejecución de la última solicitud
     */
    private function adjust_batch_size($endpoint, $execution_time) {
        // Solo ajustamos para endpoints que suelen usarse con paginación
        $batch_endpoints = ['getarticulosws', 'getclientesws'];
        
        if (!in_array(strtolower($endpoint), $batch_endpoints)) {
            return;
        }
        
        $optimal_sizes = get_option('mi_integracion_api_optimal_batch_sizes', []);
        $current_size = $optimal_sizes[strtolower($endpoint)] ?? $this->batch_size_config['default'];
        
        // Ajuste basado en tiempo de ejecución
        // - Si es muy rápido (< 2 segundos), aumentar
        // - Si es muy lento (> 10 segundos), disminuir
        $new_size = $current_size;
        
        if ($execution_time < 2 && $current_size < $this->batch_size_config['max']) {
            // Aumentar 10% si es rápido
            $new_size = min(
                $this->batch_size_config['max'],
                ceil($current_size * (1 + $this->batch_size_config['adjustment_factor']))
            );
        } elseif ($execution_time > 10 && $current_size > $this->batch_size_config['min']) {
            // Reducir 10% si es lento
            $new_size = max(
                $this->batch_size_config['min'], 
                floor($current_size * (1 - $this->batch_size_config['adjustment_factor']))
            );
        }
        
        // Solo actualizar si cambió
        if ($new_size !== $current_size) {
            (new \MiIntegracionApi\Helpers\Logger)->info("Ajustando tamaño de lote para $endpoint: $current_size → $new_size (tiempo: {$execution_time}s)");
            $optimal_sizes[strtolower($endpoint)] = $new_size;
            update_option('mi_integracion_api_optimal_batch_sizes', $optimal_sizes);
        }
    }

    /**
     * Ejecuta una operación en lotes con tamaño dinámico
     *
     * @param string   $endpoint   El endpoint a consultar
     * @param callable $processor  Función para procesar cada lote
     * @param array    $query_args Argumentos base de la consulta
     * @param array    $options    Opciones adicionales
     * @return array Estadísticas de la operación
     */
    public function batch_operation($endpoint, $processor, $query_args = [], $options = []) {
        // Opciones por defecto
        $default_options = [
            'start_index' => 0,
            'max_items' => 0, // 0 = sin límite
            'continue_on_error' => false,
            'report_progress' => true,
            'sleep_between_batches' => 0.2, // segundos
        ];
        
        $options = wp_parse_args($options, $default_options);
        $stats = [
            'total_processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'batches' => 0,
            'execution_time' => 0,
            'start_time' => microtime(true),
        ];
        
        $offset = $options['start_index'];
        $batch_size = $this->get_optimal_batch_size($endpoint);
        $continue = true;
        
        (new \MiIntegracionApi\Helpers\Logger)->info("Iniciando operación en lotes para $endpoint (tamaño: $batch_size)");
        
        // Bucle principal de procesamiento por lotes
        while ($continue) {
            // Verificar límite máximo si está configurado
            if ($options['max_items'] > 0 && $stats['total_processed'] >= $options['max_items']) {
                (new \MiIntegracionApi\Helpers\Logger)->info("Se alcanzó el límite máximo de elementos: " . $options['max_items']);
                break;
            }
            
            // Crear argumentos para este lote
            $args = array_merge($query_args, [
                'inicio' => $offset,
                'fin' => $offset + $batch_size - 1
            ]);
            
            try {
                // Obtener datos
                $start_batch = microtime(true);
                $response = $this->get($endpoint, $args);
                
                if (!$this->validate_response($response)) {
                    throw new \Exception("Error en respuesta: " . json_encode($response->info_error));
                }
                
                // Procesar lote con la función callback
                $batch_results = $processor($response);
                
                // Actualizar estadísticas
                $items_in_batch = $batch_results['processed'] ?? 0;
                $stats['total_processed'] += $items_in_batch;
                $stats['successful'] += $batch_results['successful'] ?? $items_in_batch;
                $stats['failed'] += $batch_results['failed'] ?? 0;
                $stats['batches']++;
                
                // Liberar memoria explícitamente
                unset($response);
                unset($batch_results);
                
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                
                // Si no hay resultados o menos de los solicitados, terminar
                if ($items_in_batch === 0 || $items_in_batch < $batch_size) {
                    (new \MiIntegracionApi\Helpers\Logger)->info("Lote final alcanzado ($items_in_batch elementos)");
                    $continue = false;
                }
                
                // Avanzar offset
                $offset += $batch_size;
                
                // Reportar progreso
                if ($options['report_progress']) {
                    (new \MiIntegracionApi\Helpers\Logger)->info(
                        "Lote completado ($items_in_batch procesados, total: $stats[total_processed]) " . 
                        "- Tiempo: " . round(microtime(true) - $start_batch, 2) . "s"
                    );
                }
                
                // Pequeña pausa entre lotes para reducir carga
                if ($options['sleep_between_batches'] > 0 && $continue) {
                    usleep((int)($options['sleep_between_batches'] * 1000000));
                }
            } catch (\Exception $e) {
                (new \MiIntegracionApi\Helpers\Logger)->error("Error en lote (offset $offset): " . $e->getMessage());
                $stats['failed']++;
                
                if (!$options['continue_on_error']) {
                    throw $e; // Re-lanzar la excepción
                }
                
                // Si continuamos a pesar del error, avanzamos al siguiente lote
                $offset += $batch_size;
            }
        }
        
        // Completar estadísticas
        $stats['execution_time'] = microtime(true) - $stats['start_time'];
        
        (new \MiIntegracionApi\Helpers\Logger)->info(sprintf(
            "Operación en lotes completada en %.2f segundos. Procesados: %d, Exitosos: %d, Fallidos: %d, Lotes: %d",
            $stats['execution_time'],
            $stats['total_processed'],
            $stats['successful'],
            $stats['failed'],
            $stats['batches']
        ));
        
        return $stats;
    }

    /**
     * Invalida selectivamente la caché por patrones
     *
     * @param string|array $patterns Patrones de claves a invalidar
     * @return int Número de claves invalidadas
     */
    public function invalidate_cache($patterns) {
        return $this->cache_manager->delete_by_patterns($patterns);
    }
}