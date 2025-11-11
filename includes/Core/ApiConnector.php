<?php

declare(strict_types=1);

/**
 * Clase para manejar la conexión con la API externa.
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Core
 */

namespace MiIntegracionApi\Core;

// Cargar constantes cURL antes de usar la clase
if (!defined('CURLE_HTTP2_ERROR')) {
    require_once __DIR__ . '/../Constants/CurlConstants.php';
}

use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\SSL\CertificateCache;
use MiIntegracionApi\SSL\SSLTimeoutManager;
use MiIntegracionApi\SSL\SSLConfigManager;
use MiIntegracionApi\SSL\CertificateRotation;
use MiIntegracionApi\Core\RetryManager;
use MiIntegracionApi\ErrorHandling\Exceptions\SyncError;
use MiIntegracionApi\Cache\PriceCache;
use MiIntegracionApi\Constants\VerialTypes;
use MiIntegracionApi\Core\SSLAdvancedSystemsTrait;

// Sistema de manejo de errores unificado
use MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory;
use MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface;
use MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Conector principal para la API de Verial con funcionalidades avanzadas.
 *
 * Esta clase centraliza todas las operaciones de comunicación con la API de Verial,
 * proporcionando funcionalidades avanzadas como:
 * - Sistema de reintentos inteligente con backoff exponencial
 * - Cache inteligente de precios con TTL configurable
 * - Manejo robusto de errores y diagnósticos automáticos
 * - Soporte para paginación automática y lotes optimizados
 * - Configuración SSL avanzada y timeouts dinámicos
 * - Monitoreo de rendimiento y métricas detalladas
 * - Circuit breaker para prevenir cascadas de fallos
 *
 * @since 1.0.0
 * @since 1.4.1 Agregado sistema de reintentos y cache inteligente
 * @since 2.0.0 Refactorizado con arquitectura modular y métricas avanzadas
 * 
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Core
 * @author Christian
 * @version    2.0.0
 * 
 * @uses Logger Para logging unificado del sistema
 * @uses RetryManager Para gestión inteligente de reintentos
 * @uses PriceCache Para cache optimizado de precios
 * @uses MemoryManager Para monitoreo de recursos del sistema
 * @uses BatchSizeHelper Para optimización de lotes de datos
 */
class ApiConnector {
    // Usar sistemas SSL avanzados
    use SSLAdvancedSystemsTrait;
    
    /**
     * Instancia única para el patrón Singleton.
     * 
     * Garantiza que solo exista una instancia del conector API
     * para evitar conflictos de configuración y optimizar recursos.
     * 
     * @var ApiConnector|null
     * @since 1.0.0
     */
    private static ?ApiConnector $instance = null;

    /**
     * Número de sesión para Verial (sesionwcf).
     * 
     * Identificador único proporcionado por Verial que se incluye
     * en todas las solicitudes para autenticación y autorización.
     * Se obtiene de la configuración centralizada del sistema.
     * 
     * @var int
     * @since 1.0.0
     */
    private int $sesionwcf = 0;
    
    /**
     * Flag para indicar si la configuración ya ha sido cargada
     * 
     * @var bool
     * @since 1.4.1
     */
    private bool $config_loaded = false;

    /**
     * URL base para la API de Verial.
     * 
     * URL completa del servicio WCF de Verial incluyendo el path
     * WcfServiceLibraryVerial. Formato esperado:
     * http://dominio:puerto/WcfServiceLibraryVerial
     * 
     * @var string
     * @since 1.0.0
     */
    private string $api_url = '';

    /**
     * Clave de API para Verial (opcional, para compatibilidad futura).
     * 
     * Actualmente no se usa en la API de Verial, pero se mantiene
     * para compatibilidad con el sistema de validación.
     * 
     * @var string
     * @since 1.0.0
     */
    private string $api_key = '';

    /**
     * Instancia del sistema de logging unificado.
     * 
     * Proporciona logging estructurado con diferentes niveles
     * (debug, info, warning, error) y contexto detallado para
     * diagnóstico y monitoreo del sistema.
     * 
     * @var Logger
     * @since 1.0.0
     */
    private Logger $logger;

    /**
     * Gestor inteligente de reintentos con backoff exponencial.
     * 
     * Maneja automáticamente los reintentos de solicitudes fallidas
     * con estrategias configurables, jitter para evitar thundering herd,
     * y políticas específicas por tipo de error y operación.
     * 
     * @var RetryManager
     * @since 1.4.1
     */
    private RetryManager $retry_manager;

    /**
     * Path del servicio Verial (se obtiene de la configuración centralizada)
     * @var string
     */
    private string $service_path = '';

    /**
     * Última URL construida para la petición (para diagnóstico)
     * @var string|null
     */
    private ?string $last_request_url = null;
    
    /**
     * Timestamp de la última conexión exitosa
     * @var int|null
     */
    private ?int $last_connection_time = null;

    /**
     * Opciones para las solicitudes HTTP.
     *
     * @since 1.0.0
     * @access   protected
     * @var      array    $request_options    Opciones para solicitudes HTTP.
     */
    protected array $request_options = [];
    
    /**
     * Gestor de caché de certificados
     * @var \MiIntegracionApi\SSL\CertificateCache|null
     */
    private $cert_cache = null;
    
    /**
     * Gestor de timeouts SSL
     * @var \MiIntegracionApi\SSL\SSLTimeoutManager|null
     */
    private $timeout_manager = null;
    
    /**
     * Gestor de configuración SSL
     * @var \MiIntegracionApi\SSL\SSLConfigManager|null
     */
    private $ssl_config_manager = null;
    
    /**
     * Gestor de rotación de certificados
     * @var \MiIntegracionApi\SSL\CertificateRotation|null
     */
    private $cert_rotation = null;

    /**
     * Cache inteligente de precios
     * @var PriceCache|null
     */
    private ?PriceCache $price_cache = null;

    /**
     * Indica si se debe usar caché para las respuestas.
     *
     * @since 1.0.0
     * @access   protected
     * @var      boolean    $use_cache    Si se debe usar caché.
     */
    protected bool $use_cache = false;

    /**
     * Tiempo de vida predeterminado para caché en segundos.
     *
     * @since 1.0.0
     * @access   protected
     * @var      int    $default_cache_ttl    Tiempo de vida de caché.
     */
    protected int $default_cache_ttl = 3600;

    /**
     * @var int Timeout de las solicitudes en segundos
     */
    private int $timeout;
    
    /**
     * @var array Configuración de timeouts por método HTTP
     */
    private array $timeout_config = [];
    
    /**
     * @var array Configuración de timeouts dinámicos basados en la carga del servidor
     */
    private array $dynamic_timeout_config = [
        'base_multiplier' => 1.0,
        'load_threshold_low' => 0.3,
        'load_threshold_medium' => 0.6,
        'load_threshold_high' => 0.8,
        'multiplier_low' => 1.0,
        'multiplier_medium' => 1.5,
        'multiplier_high' => 2.0,
        'multiplier_critical' => 3.0,
        'max_timeout' => 300,  // 5 minutos máximo
        'min_timeout' => 10    // 10 segundos mínimo
    ];
    
    /**
     * Obtiene la instancia única del conector API (patrón Singleton).
     * 
     * Implementa el patrón Singleton para garantizar una única instancia
     * del conector, evitando conflictos de configuración y optimizando
     * el uso de recursos del sistema.
     * 
     * @param Logger|null $logger Instancia del logger (opcional, se creará uno por defecto)
     * @param int $max_retries Número máximo de reintentos por solicitud (opcional)
     * @param int $retry_delay Tiempo base entre reintentos en segundos (opcional)
     * @param int $timeout Timeout base para solicitudes HTTP en segundos (opcional)
     * 
     * @return ApiConnector Instancia única del conector API
     * 
     * @since 1.0.0
     * @since 1.4.1 Agregados parámetros de configuración de reintentos
     * 
     * @throws \Exception Si no se puede crear la instancia del logger
     * 
     * @example
     * ```php
     * $connector = ApiConnector::get_instance();
     * $connector = ApiConnector::get_instance($custom_logger, 5, 3, 60);
     * ```
     */
    public static function get_instance(?Logger $logger = null, int $max_retries = 3, int $retry_delay = 2, int $timeout = 30): self {
        if (self::$instance === null) {
            self::$instance = new self($logger, $max_retries, $retry_delay, $timeout);
        }
        
        return self::$instance;
    }

    /**
     * Constructor del conector API con configuración avanzada.
     * 
     * Inicializa el conector con todas las dependencias necesarias:
     * - Sistema de logging unificado
     * - Gestor de reintentos inteligente
     * - Configuración de timeouts dinámicos
     * - Carga automática de configuración desde fuentes centralizadas
     * 
     * @param Logger|null $logger Instancia del logger (opcional, se creará uno por defecto)
     * @param int $max_retries Número máximo de reintentos por solicitud
     * @param int $retry_delay Tiempo base entre reintentos en segundos
     * @param int $timeout Timeout base para solicitudes HTTP en segundos
     * 
     * @since 1.0.0
     * @since 1.4.1 Agregada inicialización de sistemas avanzados
     * 
     * @throws \Exception Si la clase Logger no está disponible y no se proporciona una instancia
     * 
     * @see Logger Para el sistema de logging
     * @see RetryManager Para gestión de reintentos
     * @see load_configuration() Para carga de configuración
     */
    public function __construct(?Logger $logger = null, int $max_retries = 3, int $retry_delay = 2, int $timeout = 30, array $config = []) {
        // Si no se proporciona un logger, crear uno por defecto
        if ($logger === null) {
            if (class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
                $logger = new \MiIntegracionApi\Helpers\Logger('api-connector');
            } else {
                // Fallback si la clase Logger no está disponible
                throw new \Exception('Logger es requerido pero la clase Logger no está disponible');
            }
        }
        
        $this->logger = $logger;
        $this->timeout = $timeout;
        $this->retry_manager = new RetryManager();
        
        // El cache de precios se inicializa de forma lazy cuando se necesite
        // (ver método getPriceCache())
        
        // CRÍTICO: Cargar configuración automáticamente
        if (!empty($config)) {
            $this->init_config($config);
        } else {
            // Cargar configuración desde fuentes centralizadas
            $this->load_configuration([]);
            $this->config_loaded = true;
        }
    }

    /**
     * Obtiene la instancia del logger del sistema.
     * 
     * Método de acceso centralizado al logger siguiendo el principio DRY.
     * Garantiza acceso consistente al sistema de logging en toda la clase.
     * 
     * @return Logger Instancia del logger del sistema
     * 
     * @since 1.0.0
     */
    private function getLogger(): ?\MiIntegracionApi\Logging\Interfaces\ILogger
    {
        // Si ya tenemos un logger, devolverlo
        if ($this->logger) {
            return $this->logger;
        }
        
        // Crear nuevo logger usando el sistema centralizado
        if (class_exists('\MiIntegracionApi\Logging\Core\LogManager')) {
            try {
                $logManager = \MiIntegracionApi\Logging\Core\LogManager::getInstance();
                return $logManager->getLogger('api-connector');
            } catch (\Throwable $e) {
                error_log('Error obteniendo logger en ApiConnector: ' . $e->getMessage());
                return null;
            }
        }
        
        return null;
    }

    /**
     * Variable para rastrear el origen de la configuración
     * @var string
     */
    private string $config_source = 'none';

    /**
     * Asegura que la configuración esté cargada (carga perezosa)
     * 
     * @return void
     * @since 1.4.1
     */
    private function ensure_config_loaded(): void {
        if (!$this->config_loaded) {
            $this->load_configuration([]);
            $this->config_loaded = true;
        }
    }
    
    /**
     * Carga y valida la configuración desde fuentes centralizadas.
     * 
     * Implementa un sistema de configuración en cascada con fallbacks:
     * 1. Configuración centralizada (VerialApiConfig)
     * 2. Opciones de WordPress (get_option)
     * 3. Valores por defecto del sistema
     * 
     * Valida automáticamente el formato de URL y ajusta paths necesarios
     * para cumplir con los requisitos de la API de Verial.
     * 
     * @param array $config Configuración adicional pasada al constructor
     * 
     * @since 1.0.0
     * @since 1.4.1 Agregado sistema de configuración en cascada
     * 
     * @throws \Exception Si la configuración es inválida o no se puede cargar
     * 
     * @see VerialApiConfig Para configuración centralizada
     * @see validate_configuration() Para validación de configuración
     */
    private function load_configuration(array $config): void {
        // $this->logger->info('Cargando configuración centralizada de ApiConnector');
        
        try {
            // Asegurar que VerialApiConfig esté disponible
            $this->ensureVerialApiConfigLoaded();
            
            // Usar configuración centralizada desde verialconfig.php
            if (class_exists('VerialApiConfig')) {
                $verial_config = $this->safeCreateVerialConfig();
                $api_url = $verial_config->getVerialBaseUrl();
                $sesionwcf = $verial_config->getVerialSessionId();
                $this->config_source = 'centralized_config';
                
                // $this->logger->debug('Configuración cargada desde configuración centralizada', [
                //     'api_url' => $api_url,
                //     'sesion' => $sesionwcf
                // ];
            } else {
                // Fallback a configuración de WordPress si no está disponible VerialApiConfig
                if (function_exists('get_option')) {
                    $wp_options = get_option('mi_integracion_api_ajustes', []);
                    // Usar valores por defecto si VerialApiConfig no está disponible
                    $api_url = $wp_options['mia_url_base'] ?? 'http://x.verial.org:8000/WcfServiceLibraryVerial/';
                    $sesionwcf = (int) ($wp_options['mia_numero_sesion'] ?? 18);
                    $this->config_source = 'wordpress_options';
                } else {
                    // Último fallback a valores por defecto
                    $api_url = 'http://x.verial.org:8000/WcfServiceLibraryVerial/';
                    $sesionwcf = 18;
                    $this->config_source = 'fallback_defaults';
                }
            }
            
            // Configurar propiedades
            $this->api_url = rtrim($api_url, '/');
            
            // CRÍTICO: Asignar la sesión obtenida de la configuración
            $this->sesionwcf = (int)$sesionwcf;
            
            // Solo actualizar sesión si es diferente para evitar logs innecesarios
            $new_session = (int)$sesionwcf;
            if ($this->sesionwcf !== $new_session) {
                try {
                    $this->set_session_number($sesionwcf);
                } catch (\Exception $e) {
                    $this->logger->warning('Error validando número de sesión en load_configuration, usando asignación directa: ' . $e->getMessage());
                    $this->sesionwcf = $sesionwcf;
                }
            }
            
            // Verificar el formato correcto esperado por la API
            // Según Manual de Verial: http://[IP]:8000/WcfServiceLibraryVerial/
            if (stripos($this->api_url, 'WcfServiceLibraryVerial') === false) {
                $this->api_url = rtrim($this->api_url, '/') . '/WcfServiceLibraryVerial';
            }
            
            // Asegurar que la URL base termine con /WcfServiceLibraryVerial (sin barra final)
            // para que build_api_url pueda añadir correctamente el endpoint
            if (substr($this->api_url, -strlen('/WcfServiceLibraryVerial/')) === '/WcfServiceLibraryVerial/') {
                $this->api_url = rtrim($this->api_url, '/');
            }
            
            // $this->logger->info('Configuración cargada exitosamente', [
            //     'api_url' => $this->api_url,
            //     'sesion' => $this->sesionwcf,
            //     'source' => $this->config_source
            // ];
       } catch (\Exception $e) {
            $this->logger->error('Error al cargar configuración: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Obtiene el origen de la configuración actual cargada.
     * 
     * Útil para diagnóstico y debugging, permite identificar
     * desde qué fuente se cargó la configuración activa:
     * - 'centralized_config': Configuración centralizada
     * - 'wordpress_options': Opciones de WordPress
     * - 'fallback_defaults': Valores por defecto
     * 
     * @return string Origen de la configuración actual
     * 
     * @since 1.4.1
     */
    public function get_config_source(): string {
        return $this->config_source;
    }
    
    /**
     * Validación específica para NuevoClienteWS
     * Este endpoint requiere campos específicos y un formato concreto
     *
     * @param array $data Datos a enviar
     * @return void
     * @throws \Exception Si faltan campos obligatorios
     */
    private function validarDatosNuevoClienteWS(array &$data): void {
        
        // Campos obligatorios según la documentación de Verial
        $campos_obligatorios = ['sesionwcf', 'Tipo', 'Nombre'];
        $campos_faltantes = [];
        
        // Verificar campos obligatorios
        foreach ($campos_obligatorios as $campo) {
            if (!isset($data[$campo]) || $data[$campo] === '') {
                $campos_faltantes[] = $campo;
            }
        }
        
        if (!empty($campos_faltantes)) {
            $mensaje_error = 'Faltan campos obligatorios para NuevoClienteWS: ' . implode(', ', $campos_faltantes);
            $this->logger->error($mensaje_error, [
                'datos_proporcionados' => array_keys($data)
            ]);
            throw new \Exception($mensaje_error);
        }
        
        // Validar valores específicos
        if (isset($data['Tipo']) && !in_array($data['Tipo'], [VerialTypes::CUSTOMER_TYPE_INDIVIDUAL, VerialTypes::CUSTOMER_TYPE_COMPANY])) {
            $this->logger->warning('El campo Tipo debe ser 1 (Particular) o 2 (Empresa)', [
                'valor_actual' => $data['Tipo']
            ]);
            // Auto-corrección: Establecer un valor por defecto válido
            $data['Tipo'] = VerialTypes::CUSTOMER_TYPE_INDIVIDUAL; // Particular por defecto
        }
        
    }

    /**
     * Procesa la respuesta de NuevoClienteWS para extraer el ID del cliente
     *
     * @param array $response Respuesta de la API
     * @return int|array ID del cliente o error
     */
    protected function process_new_customer_response(array $response): int|array
    {
        // Verificar si hay error de cURL
        if (isset($response['success']) && $response['success'] === false) {
            return $response;
        }
        
        $body = $response['body'] ?? '';
        $data = json_decode($body, true);
        
        // Verificar si hay errores en la respuesta
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = $this->getResponseErrorMessage([
                'error' => 'invalid_json_response',
                'message' => 'La respuesta de NuevoClienteWS no contiene JSON válido: ' . $body
            ]);
            return [
                'success' => false,
                'error' => 'invalid_json_response',
                'message' => $error_message
            ];
        }
        
        // Formato original: { "d": "123" }
        if (isset($data['d']) && $data['d'] !== '') {
            return intval($data['d']);
        }
        
        // Formato alternativo: { "respuesta": { "Clientes": [{ "Id": 123, ... }] } }
        if (isset($data['respuesta']['Clientes']) && 
            is_array($data['respuesta']['Clientes']) && count($data['respuesta']['Clientes']) > 0) {
            $cliente = $data['respuesta']['Clientes'][0];
            if (isset($cliente['Id'])) {
                return intval($cliente['Id']);
            }
        }
        
        // Registrar la respuesta completa para diagnóstico
        $this->logger->error('Respuesta de NuevoClienteWS sin ID de cliente: ' . $body);
        
        return [
            'success' => false,
            'error' => 'missing_client_id',
            'message' => 'La respuesta de NuevoClienteWS no contiene el ID del cliente'
        ];
    }

    /**
     * Obtiene la URL base de la API configurada
     * @return string URL base de la API
     */
    public function get_api_base_url(): string {
        return $this->api_url;
    }

    /**
     * Obtiene el número de sesión utilizado para la conexión
     * @return int|string Número de sesión
     */
    public function get_numero_sesion(): int|string
    {
        $this->ensure_config_loaded();
        return $this->sesionwcf;
    }


    /**
     * Devuelve el número de sesión actual
     * 
     * @return string El valor de sesionwcf configurado en las constantes
     */
    public function getSesionWcf(): string {
        $this->ensure_config_loaded();
        return (string)$this->sesionwcf;
    }
    
    /**
     * Alias de getSesionWcf() para mantener compatibilidad con código existente
     * 
     * @return mixed El valor de sesionwcf o null si no está configurado
     */
    public function get_session_id(): mixed
    {
        $this->ensure_config_loaded();
        return $this->getSesionWcf();
    }
    

    /**
     * Inicializa el sistema de caché
     * 
     * @param array $config Configuración opcional
     */
    private function init_cache_system(array $config = []): void {
        try {
            // Configuración de caché fija (habilitado por defecto)
            $this->cache_enabled = (bool)($config['cache_enabled'] ?? true);
            
            if ($this->cache_enabled) {
                // Inicializar el CacheManager
                $this->cache_manager = \MiIntegracionApi\CacheManager::get_instance();
                
                // Configurar TTL específicos si se proporcionan
                if (isset($config['cache_ttl_config'])) {
                    $this->cache_ttl_config = array_merge($this->cache_ttl_config, $config['cache_ttl_config']);
                }
                
                $this->logger->info('[CACHE] Sistema de caché inicializado', [
                    'enabled' => $this->cache_enabled,
                    'ttl_config' => $this->cache_ttl_config
                ]);
            } else {
                $this->logger->info('[CACHE] Sistema de caché deshabilitado');
            }
        } catch (\Exception $e) {
            $this->logger->error('[CACHE] Error inicializando sistema de caché: ' . $e->getMessage());
            $this->cache_enabled = false;
        }
    }

    /**
     * Configura el sistema de caché inteligente del conector.
     * 
     * Permite habilitar/deshabilitar el sistema de caché y configurar
     * TTL (Time To Live) específicos por endpoint para optimizar el rendimiento:
     * - Cache de respuestas de API para reducir llamadas repetitivas
     * - TTL configurables por tipo de endpoint
     * - Invalidación automática de cache expirado
     * - Estadísticas de hit/miss ratio para monitoreo
     * 
     * @param bool $enabled True para habilitar caché, false para deshabilitar
     * @param array $ttl_config Configuración de TTL por endpoint en segundos:
     *   - Formato: ['endpoint_name' => ttl_seconds, 'default' => default_ttl]
     *   - Ejemplo: ['GetArticulosWS' => 3600, 'GetPaisesWS' => 86400, 'default' => 1800]
     * 
     * @return self Instancia actual para method chaining
     * 
     * @since 1.4.1
     * 
     * @example
     * ```php
     * // Habilitar caché con TTL personalizados
     * $connector->setCacheConfig(true, [
     *     'GetArticulosWS' => 1800,    // 30 minutos
     *     'GetCondicionesTarifaWS' => 3600, // 1 hora
     *     'GetPaisesWS' => 86400,      // 24 horas
     *     'default' => 1800            // 30 minutos por defecto
     * ]);
     * 
     * // Deshabilitar caché
     * $connector->setCacheConfig(false);
     * ```
     * 
     * @see getCacheStats() Para obtener estadísticas de caché
     * @see resetCacheStats() Para reiniciar estadísticas
     */
    public function setCacheConfig(bool $enabled, array $ttl_config = []): self {
        $this->cache_enabled = $enabled;
        
        if (!empty($ttl_config)) {
            $this->cache_ttl_config = array_merge($this->cache_ttl_config, $ttl_config);
        }
        
        if ($enabled && !$this->cache_manager) {
            $this->cache_manager = \MiIntegracionApi\CacheManager::get_instance();
        }
        
        $this->logger->info('[CACHE] Configuración de caché actualizada', [
            'enabled' => $this->cache_enabled,
            'ttl_config' => $this->cache_ttl_config
        ]);
        
        return $this;
    }

    /**
     * Obtiene el TTL configurado para un endpoint específico
     * 
     * @param string $endpoint Nombre del endpoint
     * @return int TTL en segundos
     */
    private function getCacheTtlForEndpoint(string $endpoint): int {
        return $this->cache_ttl_config[$endpoint] ?? $this->cache_ttl_config['default'];
    }

    /**
     * Genera una clave de caché única para una solicitud
     * 
     * @param string $method Método HTTP
     * @param string $endpoint Endpoint
     * @param array $data Datos de la solicitud
     * @param array $params Parámetros GET
     * @return string Clave de caché
     */
    private function generateCacheKey(string $method, string $endpoint, array $data = [], array $params = []): string {
        $key_parts = [
            'api_connector',
            strtolower($method),
            $endpoint,
            $this->sesionwcf
        ];
        
        if (!empty($params)) {
            $key_parts[] = md5(serialize($params));
        }
        
        if (!empty($data)) {
            $key_parts[] = md5(serialize($data));
        }
        
        return implode('_', $key_parts);
    }

    /**
     * Verifica si una respuesta debe ser cacheada
     * 
     * @param mixed $response Respuesta de la API
     * @param int $status_code Código de estado HTTP
     * @return bool True si debe ser cacheada
     */
    private function shouldCacheResponse(mixed $response, int $status_code): bool {
        // Solo cachear respuestas exitosas
        if ($status_code < 200 || $status_code >= 400) {
            return false;
        }
        
        // No cachear errores
        if ($this->isResponseError($response)) {
            return false;
        }
        
        // No cachear respuestas vacías
        if (empty($response)) {
            return false;
        }
        
        return true;
    }

    /**
     * ✅ ELIMINADO: Cache stats para API general no implementado completamente.
     * Las métricas de cache ahora se delegan a SyncMetrics cuando sea necesario.
     * 
     * @deprecated 2.1.0 Usar SyncMetrics para tracking de performance de cache
     * @return array Estadísticas de caché (status not_implemented)
     */
    public function getCacheStats(): array {
        // ✅ CÓDIGO MUERTO: Cache stats no se está usando para API general
        return [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'hit_ratio' => 0.0,
            'status' => 'not_implemented'
        ];
    }

    /**
     * ✅ ELIMINADO: Reset de cache stats no necesario ya que no se implementó tracking.
     * 
     * @deprecated 2.1.0 Usar SyncMetrics::clearMetrics() para limpiar métricas
     */
    public function resetCacheStats(): void {
        // ✅ CÓDIGO MUERTO: No-op ya que cache stats no se está usando
    }

    /**
     * Construye la URL completa para la API de Verial con manejo mejorado de URLs
     * 
     * Garantiza que la URL cumple con el formato exigido por Verial para evitar errores
     * como "No existe el fichero INI del servicio":
     * - Formato exacto: http://<dominio>:8000/WcfServiceLibraryVerial/<endpoint>
     * - Sin barra final antes del endpoint
     * 
     * @param string $endpoint
     * @return string
     * @throws \Exception Si VerialApiConfig no está disponible
     */
    private function build_api_url(string $endpoint): string {
        // Delegar construcción de URL a VerialApiConfig
        $verial_config = $this->safeCreateVerialConfig();
        $base = rtrim($verial_config->getVerialBaseUrl(), '/');
        
        
        // VALIDACIÓN CRÍTICA: Verificar que tenemos una URL base
        if (empty($base)) {
            $this->logger->error('URL base vacía o no configurada', [
                'endpoint' => $endpoint,
                'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
            ]);
        }
        
        // Parsear la URL base para validarla y mejorarla si es necesario
        $parsed_url = parse_url($base);
        if ($base === '' || !isset($parsed_url['host']) || $parsed_url['host'] === '') {
            // Si no hay URL base o no tiene host, usar la configuración centralizada
            $default_url = '';
            if (class_exists('VerialApiConfig')) {
                $verial_config = $this->safeCreateVerialConfig();
                $default_url = $verial_config->getVerialBaseUrl();
            } else {
                // Fallback a valores por defecto si no está disponible la configuración centralizada
                $verial_config = $this->safeCreateVerialConfig();
                $default_url = $verial_config->getVerialBaseUrl();
            }
            
            $this->logger->warning('URL base inválida o sin host. Usando configuración centralizada', [
                'base_original' => $base,
                'base_default' => $default_url,
                'parsed_url' => $parsed_url,
                'endpoint' => $endpoint,
                'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown'
            ]);
            $base = $default_url;
        } elseif (!isset($parsed_url['path']) || $parsed_url['path'] === '') {
            // Si la URL base no tiene path, añadir /WcfServiceLibraryVerial
            $base = rtrim($base, '/') . '/WcfServiceLibraryVerial';
        } 
        
        // Verificar si la URL tiene el protocolo correcto
        if (!isset($parsed_url['scheme']) || $parsed_url['scheme'] === '') {
            $base = 'http://' . ltrim($base, '/');
        }
        
        // VALIDACIÓN CRÍTICA: Verificar específicamente que estemos utilizando el formato de URL correcto 
        // para evitar el error "No existe el fichero INI del servicio"
        if (stripos($base, 'WcfServiceLibraryVerial') === false) {
            $this->logger->error('URL base no contiene WcfServiceLibraryVerial, lo que probablemente causará el error "No existe el fichero INI del servicio"', [
                'base_url' => $base,
                'api_url_original' => $this->api_url,
                'endpoint' => $endpoint
            ]);
            
            // Auto-corrección: Forzar el formato correcto para prevenir el error
            $base = rtrim(preg_replace('#/WcfServiceLibraryVerial.*#i', '', $base), '/') . '/WcfServiceLibraryVerial';
            $this->logger->info('URL base corregida automáticamente para incluir WcfServiceLibraryVerial', [
                'nueva_url_base' => $base
            ]);
        }
        
        // Limpiar el endpoint de espacios y barras iniciales/finales
        $endpoint = trim($endpoint);
        $endpoint = ltrim($endpoint, '/');
        
        // Si el endpoint está vacío, devolver solo la URL base
        if (empty($endpoint)) {
            $this->last_request_url = $base;
            return $base;
        }
        
        // Verificar si el endpoint ya contiene WcfServiceLibraryVerial para evitar duplicación
        if (stripos($endpoint, 'WcfServiceLibraryVerial') !== false && stripos($base, 'WcfServiceLibraryVerial') !== false) {
            // Evitar duplicar el path WcfServiceLibraryVerial
            $this->logger->warning('Posible duplicación de WcfServiceLibraryVerial en URL y endpoint', [
                'base' => $base,
                'endpoint' => $endpoint
            ]);
            
            // Eliminar WcfServiceLibraryVerial del endpoint si ya está en la base
            if (stripos($endpoint, 'WcfServiceLibraryVerial/') === 0) {
                $endpoint = substr($endpoint, strlen('WcfServiceLibraryVerial/'));
            }
        }
        
        // VALIDACIÓN CRÍTICA: Construir la URL de forma más robusta
        // Según Manual de Verial: http://[IP]:8000/WcfServiceLibraryVerial/Endpoint
        // Asegurar que siempre haya una barra entre la base y el endpoint
        $base = rtrim($base, '/');
        $url = $base . '/' . $endpoint;
        
        // VALIDACIÓN CRÍTICA: Eliminar dobles barras (causa común del error de fichero INI)
        // Preservar el protocolo (http:// o https://)
        $url = preg_replace('#(?<!:)//+#', '/', $url);
        
        // VALIDACIÓN CRÍTICA: Asegurarse que la URL no tiene doble WcfServiceLibraryVerial
        // Esto es otra causa común del error de fichero INI
        $has_duplicate = preg_match('#/WcfServiceLibraryVerial/.*WcfServiceLibraryVerial/#i', $url);
        if ($has_duplicate) {
            $this->logger->warning('Detectada duplicación de WcfServiceLibraryVerial en la URL', ['url' => $url]);
            $url = preg_replace('#(/WcfServiceLibraryVerial).*?(/WcfServiceLibraryVerial)/#i', '$1/', $url);
            $this->logger->info('URL corregida para eliminar duplicación', ['nueva_url' => $url]);
        }
        
        // VALIDACIÓN ADICIONAL: Verificar formato final específico para NuevoClienteWS 
        // Este endpoint es particularmente sensible al formato de URL
        if ($endpoint === 'NuevoClienteWS') {
            $expected_pattern = '#^https?://[^/]+(?::\d+)?/WcfServiceLibraryVerial/NuevoClienteWS$#i';
            if (!preg_match($expected_pattern, $url)) {
                $this->logger->warning('La URL para NuevoClienteWS no cumple con el formato esperado', [
                    'url_actual' => $url,
                    'formato_esperado' => 'http://dominio:puerto/WcfServiceLibraryVerial/NuevoClienteWS'
                ]);
            }
        }
        
        // Validar que la URL resultante sea válida
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->logger->error('URL construida inválida', [
                'base' => $base,
                'endpoint' => $endpoint,
                'resultado' => $url
            ]);
            
            // Intentar reconstruir una URL completa y válida
            if (!parse_url($base, PHP_URL_SCHEME)) {
                $base = 'http://' . ltrim($base, '/');
            }
            
            // Asegurar que haya un host
            if (!parse_url($base, PHP_URL_HOST)) {
                $this->logger->error('No se pudo construir una URL válida - sin host');
                // Usar la configuración centralizada como último recurso
                if (class_exists('VerialApiConfig')) {
                    $verial_config = $this->safeCreateVerialConfig();
                    $base = $verial_config->getVerialBaseUrl();
                } else {
                    $verial_config = $this->safeCreateVerialConfig();
                    $base = $verial_config->getVerialBaseUrl();
                }
            }
            
            $url = rtrim($base, '/') . '/' . $endpoint;
        }
        
        $this->last_request_url = $url;
        return $url;
    }

    /**
     * Devuelve la última URL usada en una petición
     * @return string|null
     */
    public function get_last_request_url(): ?string {
        return $this->last_request_url;
    }

    /**
     * Construye una URL completa para un endpoint con parámetros
     * @param string $endpoint Nombre del endpoint
     * @param array $params Parámetros opcionales 
     * @return string URL completa
     * @throws \Exception Si VerialApiConfig no está disponible
     */
    public function build_endpoint_url(string $endpoint, array $params = []): string {
        // Delegar construcción de URL a VerialApiConfig
        $verial_config = $this->safeCreateVerialConfig();
        $url = rtrim($verial_config->getVerialBaseUrl(), '/') . '/' . $endpoint;
        
        // Crear una copia de los parámetros para no modificar el array original
        $final_params = $params;
        
        // Añadir sesionwcf como 'x' si no está incluido en los parámetros
        if (!isset($final_params['x']) && !isset($final_params['sesionwcf'])) {
            $final_params['x'] = $this->sesionwcf;
        }
        
        // Añadir todos los parámetros a la URL
        if (!empty($final_params)) {
            $url = add_query_arg($final_params, $url);
        }
        
        
        return $url;
    }

    /**
     * Realiza una solicitud GET a la API
     *
     * @param string $endpoint Endpoint de la API
     * @param array $params Parámetros de consulta
     * @param array $options Opciones adicionales
     * @return mixed Resultado de la solicitud
     */
    public function get(string $endpoint, array $params = [], array $options = []): SyncResponseInterface {
        try {
            // Eliminar log debug de URL para GetNumArticulosWS
            $data = $this->retry_manager->executeWithRetry(function() use ($endpoint, $params, $options) {
                return $this->makeRequest('GET', $endpoint, [], $params, $options);
            }, 'GET_' . $endpoint);
            
            return ResponseFactory::success($data, 'Solicitud GET exitosa', [
                'endpoint' => $endpoint,
                'params' => $params
            ]);
        } catch (\Exception $e) {
            return ResponseFactory::error(
                'Error en solicitud GET: ' . $e->getMessage(),
                HttpStatusCodes::INTERNAL_SERVER_ERROR,
                [
                    'endpoint' => $endpoint,
                    'params' => $params,
                    'error' => $e->getMessage()
                ]
            );
        }
    }

    /**
     * Realiza una solicitud POST a la API
     *
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos para enviar
     * @param array $params Parámetros de consulta
     * @param array $options Opciones adicionales
     * @return mixed Resultado de la solicitud
     */
    public function post(string $endpoint, array $data = [], array $params = [], array $options = []): mixed {
        return $this->retry_manager->executeWithRetry(function() use ($endpoint, $data, $params, $options) {
            return $this->makeRequest('POST', $endpoint, $data, $params, $options);
        }, 'POST_' . $endpoint);
    }

    /**
     * Realiza una solicitud PUT a la API
     *
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos para enviar
     * @param array $params Parámetros de consulta
     * @param array $options Opciones adicionales
     * @return mixed Resultado de la solicitud
     */
    public function put(string $endpoint, array $data = [], array $params = [], array $options = []): mixed {
        return $this->retry_manager->executeWithRetry(function() use ($endpoint, $data, $params, $options) {
            return $this->makeRequest('PUT', $endpoint, $data, $params, $options);
        }, 'PUT_' . $endpoint);
    }

    /**
     * Realiza una solicitud DELETE a la API
     *
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos para enviar
     * @param array $params Parámetros de consulta
     * @param array $options Opciones adicionales
     * @return mixed Resultado de la solicitud
     */
    public function delete(string $endpoint, array $data = [], array $params = [], array $options = []): mixed {
        return $this->retry_manager->executeWithRetry(function() use ($endpoint, $data, $params, $options) {
            return $this->makeRequest('DELETE', $endpoint, $data, $params, $options);
        }, 'DELETE_' . $endpoint);
    }

    /**
     * Configura las opciones de retry para el ApiConnector
     *
     * @param array $retry_config Configuración de retry
     * @return self
     */
    public function setRetryConfig(array $retry_config): self {
        $this->retry_manager = new RetryManager();
        return $this;
    }

    /**
     * Obtiene la configuración actual de retry
     *
     * @return array Configuración de retry
     */
    public function getRetryConfig(): array {
        // Método getStats no existe en RetryManager, devolvemos array vacío por ahora
        return [];
    }
    
    /**
     * Realiza una solicitud HTTP a la API
     *
     * @param string $method Método HTTP (GET, POST, PUT, DELETE)
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos para enviar (cuerpo)
     * @param array $params Parámetros de consulta (URL)
     * @param array $options Opciones adicionales
     * @return mixed Resultado de la solicitud
     */
    /**
     * Realiza una solicitud HTTP a la API
     *
     * @param string $method Método HTTP (GET, POST, PUT, DELETE)
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos para enviar (cuerpo)
     * @param array $params Parámetros de consulta (URL)
     * @param array $options Opciones adicionales
     * @return mixed Resultado de la solicitud
     * @throws \Exception Si hay problemas críticos de configuración o VerialApiConfig no está disponible
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], array $params = [], array $options = []): mixed {
        // VALIDACIÓN CRÍTICA: Verificar el endpoint antes de construir la URL
        if (empty($endpoint)) {
            $this->logger->error('Endpoint vacío en makeRequest', array_merge([
                'method' => $method,
                'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown'
            ], $this->get_last_request_info()));
            throw new \Exception('No se puede realizar una solicitud sin especificar un endpoint.');
        }
        
        // Asegurar que la configuración esté cargada antes de hacer la solicitud
        $this->ensure_config_loaded();
        
        // VALIDACIÓN CRÍTICA: Verificar que tengamos un número de sesión válido ANTES de construir la URL
        $sesion = $this->getSesionWcf();
        if (empty($sesion)) {
            $this->logger->error('Número de sesión (sesionwcf) vacío o no configurado', array_merge([
                'endpoint' => $endpoint,
                'method' => $method
            ], $this->get_last_request_info()));
            throw new \Exception('El número de sesión (sesionwcf) no está configurado. Este valor es obligatorio para todas las llamadas a la API de Verial.');
        }
        
        // CORRECCIÓN CRÍTICA #1: Delegar construcción de URL a VerialApiConfig (sin parámetros automáticos)
        $verial_config = $this->safeCreateVerialConfig();
        $url = rtrim($verial_config->getVerialBaseUrl(), '/') . '/' . $endpoint;
        
        // CORRECCIÓN CRÍTICA #2: Preparar parámetros con sesión incluida desde el inicio
        $final_params = $params;
        
        // CORRECCIÓN CRÍTICA #3: Para métodos GET, asegurar que 'x' esté siempre presente
        if ($method === 'GET') {
            // Remover sesionwcf de los parámetros si existe (es inválido para GET)
            if (isset($final_params['sesionwcf'])) {
                unset($final_params['sesionwcf']);
            }
            
            // Añadir sesión como parámetro 'x' SIEMPRE para GET
            $final_params['x'] = $sesion;
        }
        
        // VALIDACIÓN ESPECIAL PARA POST: Asegurar que sesionwcf esté incluido en el JSON para POST
        if ($method === 'POST' && !empty($data)) {
            if (!isset($data['sesionwcf']) && $endpoint !== 'login') {
                // Auto-corrección: Añadir sesionwcf al payload JSON
                $data['sesionwcf'] = $sesion;
                $this->logger->warning('Se añadió automáticamente sesionwcf al payload JSON', [
                    'endpoint' => $endpoint,
                    'sesion_agregada' => $sesion
                ]);
            }
            
            // Para NuevoClienteWS, verificación adicional de campos obligatorios
            if ($endpoint === 'NuevoClienteWS' && $method === 'POST') {
                $this->validarDatosNuevoClienteWS($data);
            }
        }
        
        // CORRECCIÓN CRÍTICA #4: Añadir TODOS los parámetros a la URL (incluyendo el parámetro de sesión para GET)
        if (!empty($final_params)) {
            $query_string = http_build_query($final_params);
            $url .= (str_contains($url, '?') ? '&' : '?') . $query_string;
        }
        
        
        // Inicializar cURL
        $curl = curl_init();
        if (!$curl) {
            throw new \Exception('No se pudo inicializar cURL');
        }
        
        // Configurar opciones básicas de cURL
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $options['timeout'] ?? $this->getTimeoutForMethod($method),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => $options['redirection'] ?? 5,
            CURLOPT_SSL_VERIFYPEER => $options['sslverify'] ?? false,
            CURLOPT_SSL_VERIFYHOST => ($options['sslverify'] ?? false) ? 2 : 0,
            CURLOPT_USERAGENT => 'MiIntegracionAPI/1.0',
            CURLOPT_VERBOSE => false,
        ]);
        
        // Configurar headers por defecto
        $headers = [
            'Accept: */*',
            'User-Agent: MiIntegracionAPI/1.0',
            'Cache-Control: no-cache'
        ];
        
        // Configurar método HTTP específico
        switch (strtoupper($method)) {
            case 'GET':
                // GET es el método por defecto en cURL, no necesita configuración especial
                break;
                
            case 'POST':
                curl_setopt($curl, CURLOPT_POST, true);
                
                if (!empty($data)) {
                    $json_body = json_encode($data, 
                        (defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0) | 
                        (defined('JSON_UNESCAPED_SLASHES') ? JSON_UNESCAPED_SLASHES : 0)
                    );
                    
                    // Verificar si hubo error en la codificación JSON
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $json_error = json_last_error_msg();
                        $this->logger->error('Error al codificar datos en JSON', [
                            'error' => $json_error,
                        ]);
                        curl_close($curl);
                        throw new \Exception('Error al codificar datos JSON: ' . $json_error);
                    }
                    
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $json_body);
                    
                    // MODIFICACIÓN CRÍTICA: Ajustes específicos para endpoints POST de Verial
                    if ($endpoint === 'NuevoClienteWS' || $endpoint === 'NuevaDireccionEnvioWS' || $endpoint === 'NuevoDocClienteWS') {
                        $headers[] = 'Content-Type: text/plain';
                        $this->logger->info('Usando Content-Type: text/plain para endpoint crítico ' . $endpoint);
                    } else {
                        $headers[] = 'Content-Type: application/json';
                    }
                    
                    // Log JSON solo en modo debug específico
                    if (defined('WP_DEBUG_LOG') && constant('WP_DEBUG_LOG')) {
                        $this->logger->debug('JSON generado para API Verial', [
                            'endpoint' => $endpoint,
                            'json' => $json_body
                        ]);
                    }
                }
                break;
                
            case 'PUT':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
                if (!empty($data)) {
                    $json_body = json_encode($data, 
                        (defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0) | 
                        (defined('JSON_UNESCAPED_SLASHES') ? JSON_UNESCAPED_SLASHES : 0)
                    );
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $json_body);
                    $headers[] = 'Content-Type: application/json';
                }
                break;
                
            case 'DELETE':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
                
            default:
                curl_close($curl);
                throw new \Exception('Método HTTP no soportado: ' . $method);
        }
        
        // Añadir headers personalizados si los hay
        if (!empty($options['headers'])) {
            foreach ($options['headers'] as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
        }
        
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        
        // Registrar tiempo de inicio para medir latencia
        $start_time = microtime(true);
        
        // Ejecutar la solicitud
        $response_body = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error_code = curl_errno($curl) ?: 0;
        $curl_error_message = curl_error($curl);
        $curl_info = curl_getinfo($curl);
        
        // Calcular tiempo de respuesta
        $response_time_ms = (microtime(true) - $start_time) * 1000;
        
        curl_close($curl);
        
        // Manejar errores de cURL
        if ($response_body === false || $curl_error_code !== 0) {
            $context = [
                'error_code' => $curl_error_code,
                'error_message' => $curl_error_message,
                'url' => $url,
                'method' => $method,
                'endpoint' => $endpoint,
                'response_time_ms' => $response_time_ms
            ];
            
            $this->logger->error('Error de cURL', $context);
            
            // Determinar si el error es reintentable
            if ($this->isCurlErrorRetryable($curl_error_code)) {
                // CORRECCIÓN: Convertir código de error cURL a mensaje string
                $error_message = $this->getCurlErrorMessage($curl_error_code);
                
                // Manejo específico para timeout
                if ($curl_error_code === CURLE_OPERATION_TIMEOUTED) {
                    throw SyncError::timeoutError($error_message, array_merge($context, [
                        'timeout_seconds' => $this->timeout,
                        'curl_error_code' => $curl_error_code
                    ]));
                }
                
                throw SyncError::networkError($error_message, $context);
            } else {
                // CORRECCIÓN: Convertir código de error cURL a mensaje string
                $error_message = $this->getCurlErrorMessage($curl_error_code);
                throw SyncError::apiError($error_message, $context);
            }
        }        
        
        // Manejar códigos de estado HTTP de error
        if ($http_code >= 400) {
            $context = [
                'url' => $url,
                'method' => $method,
                'endpoint' => $endpoint,
                'http_code' => $http_code,
                'response_body' => substr($response_body, 0, 500), // Primeros 500 caracteres
                'response_time_ms' => $response_time_ms
            ];
            
            $status_message = $this->getHttpStatusMessage($http_code);
            
            // Determinar si el error HTTP es reintentable
            if ($http_code === 429) {
                throw SyncError::retryableError("Rate limit alcanzado: {$status_message}", $context, 30);
            } elseif ($http_code >= 500) {
                throw SyncError::networkError("Error de servidor: {$status_message}", $context);
            } elseif (in_array($http_code, [408, 502, 503, 504, 522, 524])) {
                throw SyncError::retryableError("Error HTTP reintentable: {$status_message}", $context);
            } else {
                // Logging específico para error 405 Method Not Allowed
                if ($http_code === 405) {
                    $this->logger->error("Error 405 - Método HTTP no permitido", [
                        'endpoint' => $context['endpoint'] ?? 'unknown',
                        'method' => $context['method'] ?? 'unknown',
                        'url' => $context['url'] ?? 'unknown',
                        'suggestion' => 'Verificar si el endpoint acepta el método HTTP usado',
                        'possible_causes' => [
                            'Endpoint espera GET en lugar de POST',
                            'URL del endpoint incorrecta',
                            'Configuración del servidor web'
                        ]
                    ]);
                }
                throw SyncError::apiError("Error HTTP no reintentable: {$status_message}", $context);
            }
        }
        
        // Parsear JSON y devolver solo los datos
        $json_data = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = $this->getResponseErrorMessage([
                'error' => 'invalid_json',
                'message' => 'Respuesta de la API no es JSON válido: ' . json_last_error_msg()
            ]);
            $this->logger->error('Error decodificando JSON de la API', [
                'endpoint' => $endpoint,
                'json_error' => json_last_error_msg(),
                'response_body' => substr($response_body, 0, 500),
                'error_message' => $error_message
            ]);
            throw SyncError::apiError($error_message, [
                'endpoint' => $endpoint,
                'json_error' => json_last_error_msg()
            ]);
        }
        
        // Procesamiento específico para NuevoClienteWS
        if ($endpoint === 'NuevoClienteWS') {
            $response_data = [
                'body' => $response_body,
                'success' => true
            ];
            return $this->process_new_customer_response($response_data);
        }
        
        return $json_data;
    }

    /**
     * Configura timeouts específicos por método HTTP
     * 
     * @param array $timeouts Timeouts por método (ej: ['GET' => 30, 'POST' => 60])
     * @return self Para method chaining
     */
    public function setTimeoutConfig(array $timeouts): self {
        $this->timeout_config = array_merge($this->timeout_config, $timeouts);
        $this->logger->info('[CONFIG] Configuración de timeouts actualizada', $this->timeout_config);
        return $this;
    }

    /**
     * Obtiene el timeout configurado para un método HTTP específico
     * 
     * @param string $method Método HTTP
     * @return int Timeout en segundos
     */
    public function getTimeoutForMethod(string $method): int {
        // Por ahora, devolver el timeout por defecto
        // TODO: Implementar configuración específica por método si es necesario
        return $this->timeout;
    }

    /**
     * Obtiene el mensaje de estado HTTP para un código dado
     * 
     * @param int $code Código de estado HTTP
     * @return string Mensaje de estado
     */
    private function getHttpStatusMessage(int $code): string {
        $status_messages = [
            200 => 'OK',
            201 => 'Created',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout'
        ];
        
        return $status_messages[$code] ?? 'Unknown Status';
    }

    /**
     * Verifica si una respuesta cURL contiene un error
     * 
     * @param mixed $response Respuesta de cURL
     * @return bool True si hay error, false si está bien
     */
    private function isResponseError(mixed $response): bool {
        // Si es un array con success=false, es un error
        if (is_array($response) && isset($response['success']) && $response['success'] === false) {
            return true;
        }
        
        // Si es un array con código de error HTTP
        if (is_array($response) && isset($response['response']['code'])) {
            $code = $response['response']['code'];
            return $code < 200 || $code >= 400;
        }
        
        return false;
    }

    /**
     * Obtiene el mensaje de error de una respuesta
     * 
     * @param mixed $response Respuesta de cURL
     * @return string Mensaje de error
     */
    private function getResponseErrorMessage($response): string {
        if (is_array($response)) {
            if (isset($response['error'])) {
                return $response['error'];
            }
            if (isset($response['message'])) {
                return $response['message'];
            }
            if (isset($response['response']['message'])) {
                return $response['response']['message'];
            }
        }
        
        return 'Error desconocido';
    }

    /**
     * Configura una política de reintentos predefinida para diferentes escenarios.
     * 
     * Aplica configuraciones optimizadas de reintentos según el tipo de operación:
     * - 'critical': Para operaciones críticas (5 reintentos, backoff agresivo)
     * - 'standard': Para operaciones normales (3 reintentos, backoff estándar)
     * - 'background': Para operaciones en segundo plano (7 reintentos, backoff suave)
     * - 'realtime': Para operaciones en tiempo real (2 reintentos, backoff rápido)
     * 
     * Las políticas se obtienen del sistema unificado de configuración de reintentos
     * si está disponible, con fallback a configuraciones por defecto.
     * 
     * @param string $policy_name Nombre de la política a aplicar
     * 
     * @return self Instancia actual para method chaining
     * 
     * @since 1.4.1
     * 
     * @throws \InvalidArgumentException Si la política especificada no existe
     * 
     * @example
     * ```php
     * // Configurar para operaciones críticas
     * $connector->setRetryPolicy('critical')
     *           ->call('NuevoClienteWS', 'POST', $data);
     * 
     * // Configurar para operaciones en segundo plano
     * $connector->setRetryPolicy('background')
     *           ->get_articulos(['inicio' => 1, 'fin' => 1000]);
     * ```
     * 
     * @see getRetryPolicies() Para obtener políticas disponibles
     * @see RetryConfigurationManager Para configuración unificada
     */
    public function setRetryPolicy(string $policy_name): self {
        $policies = $this->getRetryPolicies();
        
        if (!isset($policies[$policy_name])) {
            throw new \InvalidArgumentException("Retry policy '{$policy_name}' no existe. Políticas disponibles: " . implode(', ', array_keys($policies)));
        }

        $this->setRetryConfig($policies[$policy_name]);
        $this->logger->info("[CONFIG] Retry policy '{$policy_name}' aplicada", $policies[$policy_name]);
        return $this;
    }

    /**
     * Configura el circuit breaker
     * 
     * @param array $config Configuración del circuit breaker
     * @return self Para method chaining
     */
    public function setCircuitBreakerConfig(array $config): self {
        $this->circuit_breaker = array_merge($this->circuit_breaker, $config);
        $this->logger->info('[CONFIG] Circuit breaker configurado', $this->circuit_breaker);
        return $this;
    }

    /**
     * Verifica el estado del circuit breaker antes de hacer una solicitud
     * 
     * @return bool True si la solicitud puede proceder, false si debe ser bloqueada
     */
    private function checkCircuitBreaker(): bool {
        if (!$this->circuit_breaker['enabled']) {
            return true;
        }

        $current_time = time();

        switch ($this->circuit_breaker['state']) {
            case 'open':
                if ($current_time - $this->circuit_breaker['last_failure_time'] >= $this->circuit_breaker['recovery_timeout']) {
                    $this->circuit_breaker['state'] = 'half-open';
                    $this->circuit_breaker['current_failures'] = 0;
                    $this->logger->info('[CIRCUIT-BREAKER] Estado cambiado a half-open, permitiendo solicitudes de prueba');
                    return true;
                }
                return false;

            case 'half-open':
                return $this->circuit_breaker['current_failures'] < $this->circuit_breaker['half_open_max_calls'];

            default:
                return true;
        }
    }

    /**
     * Registra el resultado de una solicitud en el circuit breaker
     * 
     * @param bool $success Si la solicitud fue exitosa
     */
    private function recordCircuitBreakerResult(bool $success): void {
        if (!$this->circuit_breaker['enabled']) {
            return;
        }

        if ($success) {
            if ($this->circuit_breaker['state'] === 'half-open') {
                $this->circuit_breaker['state'] = 'closed';
                $this->circuit_breaker['current_failures'] = 0;
                $this->logger->info('[CIRCUIT-BREAKER] Recuperación exitosa, estado cambiado a closed');
            }
        } else {
            $this->circuit_breaker['current_failures']++;
            $this->circuit_breaker['last_failure_time'] = time();

            if ($this->circuit_breaker['current_failures'] >= $this->circuit_breaker['failure_threshold']) {
                $this->circuit_breaker['state'] = 'open';
                $this->logger->warning('[CIRCUIT-BREAKER] Límite de fallos alcanzado, estado cambiado a open', [
                    'failures' => $this->circuit_breaker['current_failures'],
                    'threshold' => $this->circuit_breaker['failure_threshold']
                ]);
            }
        }
    }

    /**
     * Calcula el delay usando la estrategia configurada
     * 
     * @param int $attempt Número del intento actual
     * @param array $retry_config Configuración de retry
     * @return float Delay en segundos
     */
    private function calculateRetryDelay(int $attempt, array $retry_config): float {
        $base_delay = $retry_config['base_delay'] ?? 1;
        $max_delay = $retry_config['max_delay'] ?? 60;
        $backoff_multiplier = $retry_config['backoff_multiplier'] ?? 2;
        $strategy = $retry_config['strategy'] ?? 'exponential';
        $jitter = $retry_config['jitter'] ?? true;

        switch ($strategy) {
            case 'linear':
                $delay = $base_delay + ($attempt * $base_delay);
                break;

            case 'exponential':
            default:
                $delay = $base_delay * pow($backoff_multiplier, $attempt);
                break;

            case 'fixed':
                $delay = $base_delay;
                break;

            case 'custom':
                // Para estrategias personalizadas, permitir callback
                if (isset($retry_config['custom_delay_function']) && is_callable($retry_config['custom_delay_function'])) {
                    $delay = call_user_func($retry_config['custom_delay_function'], $attempt, $retry_config);
                } else {
                    $delay = $base_delay * pow($backoff_multiplier, $attempt);
                }
                break;
        }

        // Aplicar límite máximo
        $delay = min($delay, $max_delay);

        // Agregar jitter si está habilitado
        if ($jitter) {
            $jitter_range = $delay * 0.1; // 10% de variación
            // Generar jitter entre -jitter_range y +jitter_range
            $jitter_amount = (mt_rand(0, 2000) - 1000) * $jitter_range / 1000;
            $delay = max(0.1, $delay + $jitter_amount);
        }

        return $delay;
    }

    /**
     * Actualiza las estadísticas de reintentos
     * 
     * @param int $retry_count Número de reintentos utilizados
     * @param bool $success Si la solicitud fue exitosa
     * @param int $status_code Código de estado HTTP
     */
    private function updateRetryStats(int $retry_count, bool $success, int $status_code = 0): void {
        // Inicializar el array si no existe
        if (!isset($this->retry_stats) || !is_array($this->retry_stats)) {
            $this->retry_stats = [
                'total_requests' => 0,
                'total_retries' => 0,
                'success_after_retry' => 0,
                'failed_after_retries' => 0,
                'avg_retry_count' => 0,
                'status_codes' => []
            ];
        }
        
        $this->retry_stats['total_requests']++;
        $this->retry_stats['total_retries'] += $retry_count;

        if ($success && $retry_count > 0) {
            $this->retry_stats['success_after_retry']++;
        } elseif (!$success) {
            $this->retry_stats['failed_after_retries']++;
        }

        // Actualizar promedio de reintentos
        $this->retry_stats['avg_retry_count'] = $this->retry_stats['total_retries'] / $this->retry_stats['total_requests'];

        // Estadísticas por código de estado
        if ($status_code > 0 && $retry_count > 0) {
            if (!isset($this->retry_stats['retry_by_status_code'][$status_code])) {
                $this->retry_stats['retry_by_status_code'][$status_code] = 0;
            }
            $this->retry_stats['retry_by_status_code'][$status_code]++;
        }
    }

    /**
     * Obtiene las estadísticas detalladas de reintentos
     * 
     * @return array Estadísticas de reintentos
     */
    public function getRetryStats(): array {
        return $this->retry_manager->getStats();
    }

    /**
     * ✅ REFACTORIZADO: getSystemStats ahora puede delegar a SyncMetrics.
     * 
     * @return array Estadísticas completas del sistema
     */
    public function getSystemStats(): array {
        $retry_stats = $this->getRetryStats();
        // ✅ DELEGADO: Obtener métricas de caché desde SyncMetrics en lugar del método deprecated
        $cache_stats = [];
        if (class_exists('\\MiIntegracionApi\\Core\\SyncMetrics')) {
            try {
                $syncMetricsInstance = new \MiIntegracionApi\Core\SyncMetrics();
                $syncMetrics = $syncMetricsInstance->getSummaryMetrics();
                $cache_stats = $syncMetrics['cache'] ?? [
                    'hits' => 0,
                    'misses' => 0,
                    'hit_ratio' => 0.0,
                    'status' => 'sync_metrics_unavailable'
                ];
            } catch (\Exception $e) {
                $cache_stats = [
                    'hits' => 0,
                    'misses' => 0,
                    'hit_ratio' => 0.0,
                    'status' => 'sync_metrics_error'
                ];
            }
        } else {
            $cache_stats = [
                'hits' => 0,
                'misses' => 0,
                'hit_ratio' => 0.0,
                'status' => 'sync_metrics_not_available'
            ];
        }
        
        // ✅ DELEGADO: Intentar obtener métricas adicionales desde SyncMetrics
        $syncMetrics = [];
        if (class_exists('\\MiIntegracionApi\\Core\\SyncMetrics')) {
            try {
                $syncMetricsInstance = new \MiIntegracionApi\Core\SyncMetrics();
                $syncMetrics = $syncMetricsInstance->getSummaryMetrics();
            } catch (\Exception $e) {
                // Continuar sin métricas de SyncMetrics
            }
        }
        
        return [
            'retry' => $retry_stats,
            'cache' => [
                'enabled' => $this->cache_enabled,
                'stats' => $cache_stats,
                'ttl_config' => $this->cache_ttl_config
            ],
            'performance' => [
                'total_requests' => $retry_stats['total_requests'],
                'cache_hit_ratio' => $cache_stats['hit_ratio'],
                'avg_retry_count' => $retry_stats['avg_retry_count'] ?? 0,
                'circuit_breaker_state' => $retry_stats['circuit_breaker_state']
            ],
            // ✅ DELEGADO: Métricas adicionales desde SyncMetrics
            'sync_metrics' => $syncMetrics
        ];
    }

    /**
     * Reinicia las estadísticas de reintentos
     * 
     * @return self Para method chaining
     */
    public function resetRetryStats(): self {
        $this->retry_manager->resetStats();
        return $this;
    }

    /**
     * Variable para rastrear el estado del circuito
     * @var array
     */
    private array $circuit_breaker = [
        'enabled' => false,
        'failure_threshold' => 5,
        'recovery_timeout' => 300, // 5 minutos
        'half_open_max_calls' => 3,
        'current_failures' => 0,
        'state' => 'closed', // closed, open, half-open
        'last_failure_time' => 0
    ];

    /**
     * REFACTORIZADO: Retry policies se obtienen del sistema unificado
     * @var array
     */
    private array $retry_policies = [];

    /**
     * Obtiene las políticas de reintentos del sistema unificado
     * 
     * @return array Políticas de reintentos
     */
    private function getRetryPolicies(): array {
        if (empty($this->retry_policies)) {
            if (class_exists('\\MiIntegracionApi\\Core\\RetryConfigurationManager') && 
                \MiIntegracionApi\Core\RetryConfigurationManager::isInitialized()) {
                
                $configManager = \MiIntegracionApi\Core\RetryConfigurationManager::getInstance();
                
                $this->retry_policies = [
                    'critical' => $configManager->getConfig('api_call', 'server_error'),
                    'standard' => $configManager->getConfig('api_call'),
                    'background' => $configManager->getConfig('batch_operations'),
                    'realtime' => $configManager->getConfig('api_call', 'timeout')
                ];
            } else {
                // Fallback a configuración por defecto si el sistema unificado no está disponible
                $this->retry_policies = [
                    'critical' => ['max_attempts' => 5, 'base_delay' => 2, 'backoff_factor' => 2.5],
                    'standard' => ['max_attempts' => 3, 'base_delay' => 1, 'backoff_factor' => 2.0],
                    'background' => ['max_attempts' => 7, 'base_delay' => 5, 'backoff_factor' => 1.5],
                    'realtime' => ['max_attempts' => 2, 'base_delay' => 0.5, 'backoff_factor' => 2.0]
                ];
            }
        }
        
        return $this->retry_policies;
    }

    /**
     * Realiza una solicitud HTTP con reintentos
     *
     * @param string $method Método HTTP
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos para enviar
     * @param array $params Parámetros de consulta
     * @param array $options Opciones adicionales
     * @return mixed Resultado de la solicitud
     */
    private function makeRequestWithRetry(string $method, string $endpoint, array $data = [], array $params = [], array $options = []): mixed {
        return $this->retry_manager->executeWithRetry(function() use ($method, $endpoint, $data, $params, $options) {
            return $this->makeRequest($method, $endpoint, $data, $params, $options);
        }, $method . '_' . $endpoint);
    }


    /**
     * Valida la configuración completa del conector API (MÉTODO PRINCIPAL).
     * 
     * Realiza una validación exhaustiva de todos los aspectos de la configuración:
     * - Formato y validez de la URL base de Verial
     * - Validación del número de sesión (rango y formato)
     * - Verificación del origen de la configuración
     * - Detección de configuraciones de fallback de error
     * 
     * @return SyncResponseInterface Respuesta unificada del sistema
     * 
     * @since 1.0.0
     * @since 1.4.1 Agregada validación de origen de configuración
     * @since 1.5.0 Migrado a SyncResponseInterface
     * 
     * @example
     * ```php
     * $validation = $connector->validate_configuration();
     * if ($validation->isSuccess()) {
     *     echo "Configuración válida";
     * } else {
     *     echo "Error: " . $validation->getMessage();
     * }
     * ```
     * 
     * @see validate_session_number() Para validación específica de sesión
     * @see get_config_source() Para obtener origen de configuración
     */
    public function validate_configuration(): SyncResponseInterface {
        $errors = [];
        $config = [
            'api_url' => $this->api_url,
            'sesionwcf' => $this->sesionwcf,
            'source' => $this->config_source
        ];
        
        // Validar URL base
        if (empty($this->api_url)) {
            $errors[] = 'URL base de Verial no configurada';
        } elseif (!filter_var($this->api_url, FILTER_VALIDATE_URL)) {
            $errors[] = 'URL base de Verial tiene formato inválido: ' . $this->api_url;
        }
        
        // Validar número de sesión usando método dedicado
        $session_validation = self::validate_session_number($this->sesionwcf);
        if (!$session_validation->isSuccess()) {
            $errors[] = $session_validation->getMessage();
        }
        
        // Validar que la fuente de configuración no sea de error
        if ($this->config_source === 'error_fallback') {
            $errors[] = 'Configuración cargada desde fallback de error';
        }
        
        if (empty($errors)) {
            return ResponseFactory::success(
                $config,
                'Configuración válida',
                [
                    'endpoint' => 'ApiConnector::validate_configuration',
                    'config_source' => $this->config_source,
                    'timestamp' => time()
                ]
            );
        }
        
        return ResponseFactory::error(
            'Configuración inválida: ' . implode(', ', $errors),
            HttpStatusCodes::BAD_REQUEST,
            [
                'endpoint' => 'ApiConnector::validate_configuration',
                'errors' => $errors,
                'config' => $config,
                'timestamp' => time()
            ]
        );
    }

    /**
     * Valida la configuración completa del conector API (MÉTODO LEGACY).
     * 
     * @return array{is_valid: bool, errors: string[], config: array} Resultado de validación
     * @deprecated Usar validate_configuration() que devuelve SyncResponseInterface
     */
    public function validate_configuration_legacy(): array {
        $errors = [];
        $config = [
            'api_url' => $this->api_url,
            'sesionwcf' => $this->sesionwcf,
            'source' => $this->config_source
        ];
        
        // Validar URL base
        if (empty($this->api_url)) {
            $errors[] = 'URL base de Verial no configurada';
        } elseif (!filter_var($this->api_url, FILTER_VALIDATE_URL)) {
            $errors[] = 'URL base de Verial tiene formato inválido: ' . $this->api_url;
        }
        
        // Validar número de sesión usando método dedicado
        $session_validation = self::validate_session_number($this->sesionwcf);
        if (!$session_validation->isSuccess()) {
            $errors[] = $session_validation->getMessage();
        }
        
        // Validar que la fuente de configuración no sea de error
        if ($this->config_source === 'error_fallback') {
            $errors[] = 'Configuración cargada desde fallback de error';
        }
        
        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'config' => $config
        ];
    }

    /**
     * Valida si un número de sesión cumple con los requisitos de Verial (MÉTODO PRINCIPAL).
     * 
     * Realiza validaciones específicas para el número de sesión de Verial:
     * - Verificación de que no esté vacío o nulo
     * - Validación de formato numérico
     * - Verificación de rango válido (1-9999)
     * - Conversión y normalización automática
     * 
     * @param mixed $sesionwcf Número de sesión a validar (puede ser string, int, etc.)
     * 
     * @return SyncResponseInterface Respuesta unificada del sistema
     * 
     * @since 1.0.0
     * @since 1.4.1 Agregada validación de rango específico para Verial
     * @since 1.5.0 Migrado a SyncResponseInterface
     * 
     * @example
     * ```php
     * $validation = ApiConnector::validate_session_number(18);
     * if ($validation->isSuccess()) {
     *     echo "Número de sesión válido";
     * } else {
     *     echo "Error: " . $validation->getMessage();
     * }
     * ```
     * 
     * @static
     */
    public static function validate_session_number($sesionwcf): SyncResponseInterface {
        // Verificar que no esté vacío
        if ($sesionwcf === null || $sesionwcf === '') {
            return ResponseFactory::error(
                'El número de sesión no puede estar vacío',
                HttpStatusCodes::BAD_REQUEST,
                [
                    'endpoint' => 'ApiConnector::validate_session_number',
                    'error_code' => 'empty_session',
                    'received_value' => $sesionwcf,
                    'timestamp' => time()
                ]
            );
        }
        
        // Verificar que sea numérico
        if (!is_numeric($sesionwcf)) {
            return ResponseFactory::error(
                'El número de sesión debe ser numérico, recibido: ' . gettype($sesionwcf),
                HttpStatusCodes::BAD_REQUEST,
                [
                    'endpoint' => 'ApiConnector::validate_session_number',
                    'error_code' => 'invalid_type',
                    'received_value' => $sesionwcf,
                    'expected_type' => 'numeric',
                    'actual_type' => gettype($sesionwcf),
                    'timestamp' => time()
                ]
            );
        }
        
        // Convertir a entero para validaciones adicionales
        $sesion_int = (int)$sesionwcf;
        
        // Verificar rango válido
        if ($sesion_int <= 0) {
            return ResponseFactory::error(
                'El número de sesión debe ser mayor que 0, recibido: ' . $sesion_int,
                HttpStatusCodes::BAD_REQUEST,
                [
                    'endpoint' => 'ApiConnector::validate_session_number',
                    'error_code' => 'invalid_range',
                    'received_value' => $sesion_int,
                    'min_value' => 1,
                    'timestamp' => time()
                ]
            );
        }
        
        if ($sesion_int > 9999) {
            return ResponseFactory::error(
                'El número de sesión debe ser menor que 10000, recibido: ' . $sesion_int,
                HttpStatusCodes::BAD_REQUEST,
                [
                    'endpoint' => 'ApiConnector::validate_session_number',
                    'error_code' => 'invalid_range',
                    'received_value' => $sesion_int,
                    'max_value' => 9999,
                    'timestamp' => time()
                ]
            );
        }
        
        // Si llegamos aquí, es válido
        return ResponseFactory::success(
            ['session_number' => $sesion_int],
            'Número de sesión válido',
            [
                'endpoint' => 'ApiConnector::validate_session_number',
                'session_number' => $sesion_int,
                'timestamp' => time()
            ]
        );
    }

    /**
     * Valida si un número de sesión cumple con los requisitos de Verial (MÉTODO LEGACY).
     * 
     * @param mixed $sesionwcf Número de sesión a validar
     * @return array{is_valid: bool, error: string} Resultado de validación
     * @deprecated Usar validate_session_number() que devuelve SyncResponseInterface
     * @static
     */
    public static function validate_session_number_legacy($sesionwcf): array {
        // Verificar que no esté vacío
        if ($sesionwcf === null || $sesionwcf === '') {
            return [
                'is_valid' => false,
                'error' => 'El número de sesión no puede estar vacío'
            ];
        }
        
        // Verificar que sea numérico
        if (!is_numeric($sesionwcf)) {
            return [
                'is_valid' => false,
                'error' => 'El número de sesión debe ser numérico, recibido: ' . gettype($sesionwcf)
            ];
        }
        
        // Convertir a entero para validaciones adicionales
        $sesion_int = (int)$sesionwcf;
        
        // Verificar rango válido
        if ($sesion_int <= 0) {
            return [
                'is_valid' => false,
                'error' => 'El número de sesión debe ser mayor que 0, recibido: ' . $sesion_int
            ];
        }
        
        if ($sesion_int > 9999) {
            return [
                'is_valid' => false,
                'error' => 'El número de sesión debe ser menor que 10000, recibido: ' . $sesion_int
            ];
        }
        
        // Si llegamos aquí, es válido
        return [
            'is_valid' => true,
            'error' => ''
        ];
    }

    /**
     * Obtiene el número de sesión actual
     * 
     * @return int
     */
    public function get_session_number(): int {
        return $this->sesionwcf;
    }

    /**
     * Establece un nuevo número de sesión (con validación)
     * 
     * @param mixed $sesionwcf Nuevo número de sesión
     * @throws \Exception Si el número de sesión es inválido
     */
    public function set_session_number($sesionwcf): void {
        // Solo actualizar si el valor es diferente para evitar logs innecesarios
        $new_session = (int)$sesionwcf;
        if ($this->sesionwcf === $new_session) {
            return; // No hacer nada si el valor es el mismo
        }
        
        $validation = self::validate_session_number($sesionwcf);
        
        if (!$validation->isSuccess()) {
            throw new \Exception('Número de sesión inválido: ' . $validation->getMessage());
        }
        
        $this->sesionwcf = $new_session;
        $this->logger->info('Número de sesión actualizado', ['new_session' => $this->sesionwcf]);
    }

    /**
     * Establece la URL base de la API
     * 
     * @param string $url URL base de la API
     * @return void
     */
    public function set_api_url(string $url): void {
        if (!empty($url)) {
            $this->api_url = $url;
            // $this->logger->debug("URL de API configurada: " . $url);
        }
    }

    /**
     * Establece el número de sesión para Verial
     * 
     * @param string|int $sesion Número de sesión
     * @return void
     */
    public function set_sesion_wcf($sesion): void {
        if (!empty($sesion)) {
            $this->sesionwcf = intval($sesion);
            // $this->logger->debug("Número de sesión configurado: " . $this->sesionwcf);
        }
    }

    /**
     * Recarga la configuración desde WordPress
     * @throws \Exception Si la configuración es inválida
     */
    public function reload_configuration(): void {
        $this->load_configuration([]);
    }
    
    /**
     * Diagnostica específicamente el problema "No existe el fichero INI del servicio"
     * Este error suele estar relacionado con problemas en la formación de la URL o el número de sesión
     * 
     * @return array Resultado del diagnóstico con información detallada
     */
    /**
     * Intenta corregir automáticamente la URL de la API cuando se detecta el error de fichero INI
     * Este método realizará varias pruebas para intentar encontrar una URL válida
     * 
     * @return bool True si se logró corregir la URL, false en caso contrario
     */
    public function intentar_corregir_url_ini(): bool {
        $this->logger->info('Intentando corregir URL automáticamente para error de fichero INI');
        
        // Control para evitar bucles infinitos - Limitar intentos de corrección
        static $intentos_correccion = 0;
        
        // Si ya se ha intentado corregir más de 2 veces, detener para evitar bucles infinitos
        if ($intentos_correccion >= 2) {
            $this->logger->warning('Se ha alcanzado el límite de intentos de corrección de URL para evitar bucles infinitos');
            return false;
        }
        
        // Incrementar contador de intentos
        $intentos_correccion++;
        
        // Guardar la URL original
        $url_original = $this->api_url;
        $this->logger->debug('URL original: ' . $url_original);
        
        // Verificar si el último endpoint llamado fue NuevoClienteWS, que requiere manejo especial
        $last_request_url = $this->get_last_request_url() ?? '';
        $special_endpoints = ['NuevoClienteWS', 'NuevaDireccionEnvioWS', 'NuevoDocClienteWS'];
        $es_endpoint_especial = false;
        
        foreach ($special_endpoints as $endpoint) {
            if (stripos($last_request_url, $endpoint) !== false) {
                $es_endpoint_especial = true;
                $this->logger->info('Detectado endpoint especial que requiere Content-Type específico: ' . $endpoint);
                break;
            }
        }
        
        // Verificar si la URL ya termina en /WcfServiceLibraryVerial
        if (stripos($url_original, 'WcfServiceLibraryVerial') !== false) {
            $this->logger->info('URL ya contiene WcfServiceLibraryVerial, probando otras correcciones');
            
            // Priorizar la prueba sin barra final, ya que es un caso común de error
            $sin_barra = rtrim($url_original, '/');
            $this->logger->info('Probando URL sin barra al final', ['url' => $sin_barra]);
            
            $this->api_url = $sin_barra;
            try {
                // Usar un endpoint sencillo para pruebas, evitando el que causó el error original
                $result = $this->get('GetPaisesWS');
                if (!isset($result['InfoError']) || $result['InfoError']['Codigo'] == 0) {
                    $this->logger->info('¡Corrección exitosa! URL sin barra al final funciona', ['url' => $sin_barra]);
                    
                    // Resetear contador de intentos al tener éxito
                    $intentos_correccion = 0;
                    return true;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Error al probar sin barra final: ' . $e->getMessage());
            }
            
            // Si la URL contiene el path pero igual falla, probemos con variaciones más detalladas
            $variaciones = [
                'con_slash_final' => rtrim($url_original, '/') . '/',
                'sin_slash_intermedio' => str_replace('/WcfServiceLibraryVerial/', '/WcfServiceLibraryVerial', $url_original),
                'http_sin_slash' => preg_replace('#^https://#', 'http://', rtrim($url_original, '/')),
                'http_con_slash' => preg_replace('#^https://#', 'http://', rtrim($url_original, '/') . '/'),
                'https_sin_slash' => preg_replace('#^http://#', 'https://', rtrim($url_original, '/')),
                'https_con_slash' => preg_replace('#^http://#', 'https://', rtrim($url_original, '/') . '/')
            ];
            
            foreach ($variaciones as $tipo => $url_test) {
                $this->api_url = $url_test;
                $this->logger->info('Probando variación: ' . $tipo, ['url' => $url_test]);
                
                // Intento de conexión básica
                try {
                    $result = $this->get('GetPaisesWS');
                    if (!isset($result['InfoError']) || $result['InfoError']['Codigo'] == 0) {
                        $this->logger->info('¡Corrección exitosa! URL modificada funciona', ['url' => $url_test]);
                        
                        // Resetear contador de intentos al tener éxito
                        $intentos_correccion = 0;
                        return true;
                    } elseif (isset($result['InfoError']) && stripos($result['InfoError']['Descripcion'] ?? '', 'No existe el fichero INI') === false) {
                        $this->logger->info('La URL produce un error diferente, podría ser progreso', [
                            'url' => $url_test,
                            'error' => $result['InfoError']['Descripcion'] ?? 'Sin descripción'
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Error al probar variación: ' . $e->getMessage());
                }
            }
            
            // Si es un endpoint especial, verificar también si el problema podría ser el Content-Type
            if ($es_endpoint_especial) {
                $this->logger->info('El endpoint requiere Content-Type especial. Ya se aplicó "text/plain" automáticamente en makeRequest().');
            }
            
            // Si llegamos aquí, ninguna variación funcionó
            $this->api_url = $url_original;
            return false;
        } else {
            // Si la URL no tiene el path correcto, añadirlo
            $url_corregida = rtrim($url_original, '/') . '/WcfServiceLibraryVerial';
            $this->api_url = $url_corregida;
            $this->logger->info('Añadido WcfServiceLibraryVerial a la URL', ['nueva_url' => $url_corregida]);
            
            // Probar si funciona
            try {
                $result = $this->get('GetPaisesWS');
                if (!isset($result['InfoError']) || $result['InfoError']['Codigo'] == 0) {
                    $this->logger->info('¡Corrección exitosa! URL modificada funciona', ['url' => $url_corregida]);
                    
                    // Resetear contador de intentos al tener éxito
                    $intentos_correccion = 0;
                    return true;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Error al probar URL corregida: ' . $e->getMessage());
                // Restaurar la URL original
                $this->api_url = $url_original;
            }
        }
        
        // Si llegamos aquí, no se pudo corregir
        $this->api_url = $url_original;
        // Resetear contador para futuros intentos
        $intentos_correccion = 0;
        return false;
    }

    /**
     * Prueba específicamente el error "No existe el fichero INI del servicio" con diferentes
     * variaciones de la URL para encontrar la configuración correcta
     * 
     * @return array Resultado de las pruebas con detalles
     */
    public function probar_diferentes_url_ini(): array {
        $resultados = [
            'original' => [
                'url' => $this->api_url,
                'resultado' => 'no probado',
                'error' => null
            ],
            'variaciones' => []
        ];
        
        // Guardar URL original para restaurarla al final
        $url_original = $this->api_url;
        
        // Prueba 1: URL original
        try {
            $resultado = $this->get('GetPaisesWS');
            $resultados['original']['resultado'] = isset($resultado['InfoError']) ? 
                ($resultado['InfoError']['Codigo'] == 0 ? 'éxito' : 'error') : 'éxito';
            $resultados['original']['error'] = isset($resultado['InfoError']) ? 
                $resultado['InfoError']['Descripcion'] ?? null : null;
        } catch (\Exception $e) {
            $resultados['original']['resultado'] = 'excepción';
            $resultados['original']['error'] = $e->getMessage();
        }
        
        // Generar variaciones para probar
        $variaciones = [];
        
        // 1. Sin barra al final
        $variaciones['sin_barra'] = rtrim($url_original, '/');
        
        // 2. Con barra al final
        $variaciones['con_barra'] = rtrim($url_original, '/') . '/';
        
        // 3. Sin WcfServiceLibraryVerial
        $variaciones['sin_wcf'] = preg_replace('#/WcfServiceLibraryVerial/?$#i', '', $url_original);
        
        // 4. Con doble WcfServiceLibraryVerial
        $variaciones['doble_wcf'] = rtrim($url_original, '/') . '/WcfServiceLibraryVerial';
        
        // 5. Cambiar http por https y viceversa
        if (strpos($url_original, 'https://') === 0) {
            $variaciones['http'] = preg_replace('#^https://#', 'http://', $url_original);
        } else {
            $variaciones['https'] = preg_replace('#^http://#', 'https://', $url_original);
        }
        
        // Probar cada variación
        foreach ($variaciones as $tipo => $url_test) {
            $this->api_url = $url_test;
            $resultado_prueba = [
                'url' => $url_test,
                'resultado' => 'no probado',
                'error' => null
            ];
            
            try {
                $this->logger->info('Probando variación de URL para INI', ['tipo' => $tipo, 'url' => $url_test]);
                $resultado = $this->get('GetPaisesWS');
                $resultado_prueba['resultado'] = isset($resultado['InfoError']) ? 
                    ($resultado['InfoError']['Codigo'] == 0 ? 'éxito' : 'error') : 'éxito';
                $resultado_prueba['error'] = isset($resultado['InfoError']) ? 
                    $resultado['InfoError']['Descripcion'] ?? null : null;
            } catch (\Exception $e) {
                $resultado_prueba['resultado'] = 'excepción';
                $resultado_prueba['error'] = $e->getMessage();
            }
            
            $resultados['variaciones'][$tipo] = $resultado_prueba;
        }
        
        // Restaurar URL original
        $this->api_url = $url_original;
        
        return $resultados;
    }

    /**
     * Diagnóstico detallado específico para el error "No existe el fichero INI del servicio"
     * Analiza todas las posibles causas comunes y proporciona información detallada
     *
     * @param string $endpoint Endpoint que generó el error
     * @param string $method Método HTTP utilizado (GET/POST)
     * @param array $data Datos enviados (para POST)
     * @return array Información de diagnóstico detallada
     */
    private function diagnosticar_error_ini_detallado(string $endpoint, string $method, array $data = []): array {
        $diagnostico = [
            'url_base' => $this->api_url,
            'url_completa' => $this->get_last_request_url(),
            'sesion_wcf' => $this->sesionwcf,
            'metodo_http' => $method,
            'endpoint' => $endpoint,
            'problemas_detectados' => [],
            'recomendaciones' => []
        ];
        
        // 1. Verificar presencia de WcfServiceLibraryVerial en la URL
        if (stripos($this->api_url, 'WcfServiceLibraryVerial') === false) {
            $diagnostico['problemas_detectados'][] = 'La URL base no contiene el componente obligatorio WcfServiceLibraryVerial';
            $diagnostico['recomendaciones'][] = 'Modificar la URL base a: ' . rtrim(preg_replace('#/WcfServiceLibraryVerial.*#i', '', $this->api_url), '/') . '/WcfServiceLibraryVerial';
        }
        
        // 2. Verificar barras finales incorrectas
        if (substr($this->api_url, -1) === '/') {
            $diagnostico['problemas_detectados'][] = 'La URL base termina con barra (/), lo que puede causar problemas';
            $diagnostico['recomendaciones'][] = 'Eliminar la barra final de la URL: ' . rtrim($this->api_url, '/');
        }
        
        // 3. Verificar duplicación de WcfServiceLibraryVerial
        if (substr_count(strtolower($this->api_url), 'wcfservicelibrary') > 1) {
            $diagnostico['problemas_detectados'][] = 'La URL contiene múltiples instancias de WcfServiceLibraryVerial';
            $diagnostico['recomendaciones'][] = 'Simplificar la URL a un único WcfServiceLibraryVerial';
        }
        
        // 4. Verificar el número de sesión
        if (empty($this->sesionwcf)) {
            $diagnostico['problemas_detectados'][] = 'Número de sesión (sesionwcf) vacío';
            $diagnostico['recomendaciones'][] = 'Configurar el número de sesión a 18 (para pruebas) o al valor correcto proporcionado por Verial';
        }
        
        // 5. Para POST, verificar sesionwcf en el payload
        if ($method === 'POST') {
            if (!isset($data['sesionwcf'])) {
                $diagnostico['problemas_detectados'][] = 'Para método POST, falta la propiedad sesionwcf en el JSON enviado';
                $diagnostico['recomendaciones'][] = 'Añadir {"sesionwcf": ' . $this->sesionwcf . '} al JSON del cuerpo de la solicitud';
            } elseif ($data['sesionwcf'] != $this->sesionwcf) {
                $diagnostico['problemas_detectados'][] = 'Inconsistencia en número de sesión: ' . $data['sesionwcf'] . ' en payload vs ' . $this->sesionwcf . ' en configuración';
                $diagnostico['recomendaciones'][] = 'Unificar el número de sesión en la configuración y el payload JSON';
            }
            
            // 6. Verificar método correcto según documentación
            $endpoints_post = ['NuevoClienteWS', 'NuevaDireccionEnvioWS', 'NuevoDocClienteWS', 'UpdateDocClienteWS', 'NuevoPagoWS', 'NuevaMascotaWS', 'BorrarMascotaWS'];
            $endpoints_get = ['GetPaisesWS', 'GetProvinciasWS', 'GetLocalidadesWS', 'GetClientesWS', 'GetArticulosWS', 'GetStockArticulosWS', 'GetCategoriasWS'];
            
            if (in_array($endpoint, $endpoints_get) && $method !== 'GET') {
                $diagnostico['problemas_detectados'][] = 'Método HTTP incorrecto para ' . $endpoint . '. Se está usando ' . $method . ' cuando debería ser GET';
                $diagnostico['recomendaciones'][] = 'Cambiar a método GET para este endpoint';
            }
            
            if (in_array($endpoint, $endpoints_post) && $method !== 'POST') {
                $diagnostico['problemas_detectados'][] = 'Método HTTP incorrecto para ' . $endpoint . '. Se está usando ' . $method . ' cuando debería ser POST';
                $diagnostico['recomendaciones'][] = 'Cambiar a método POST para este endpoint';
            }
        }
        
        // Añadir información general sobre el error para referencia
        $diagnostico['info_general'] = 'El error "No existe el fichero INI del servicio" generalmente indica un problema de formato en la URL o en el número de sesión proporcionado. Este fichero INI es una configuración en el servidor de Verial que se asocia con cada número de sesión.';
        
        return $diagnostico;
    }

    /**
     * Diagnóstico completo para errores de API, especialmente el error de fichero INI
     * Ejecuta pruebas con diferentes variaciones para intentar identificar la causa
     * 
     * @return array Resultado detallado del diagnóstico
     * @throws \Exception Si VerialApiConfig no está disponible
     */
    public function diagnosticar_error_ini_servicio(): array {
        $resultado = [
            'estado' => 'error',
            'mensaje' => 'Iniciando diagnóstico específico para error de fichero INI',
            'detalles' => [],
            'pruebas' => [],
            'sugerencias' => []
        ];
        
        // 1. Recolectar información básica
        // Delegar construcción de URL a VerialApiConfig
        $verial_config = $this->safeCreateVerialConfig();
        $url_base = rtrim($verial_config->getVerialBaseUrl(), '/');
        $sesion = $this->getSesionWcf();
        $api_url_original = $this->api_url;
        
        $resultado['detalles']['api_url_original'] = $api_url_original;
        $resultado['detalles']['api_url_procesada'] = $url_base;
        $resultado['detalles']['sesion'] = $sesion;
        
        // Realizar pruebas con diferentes variaciones de URL
        $resultado['pruebas_url'] = $this->probar_diferentes_url_ini();
        
        // Analizar resultados de pruebas
        $url_exitosa = null;
        foreach ($resultado['pruebas_url']['variaciones'] as $tipo => $prueba) {
            if ($prueba['resultado'] === 'éxito') {
                $url_exitosa = $prueba['url'];
                $resultado['mensaje'] = "¡Se encontró una URL que funciona correctamente!";
                $resultado['estado'] = 'success';
                break;
            }
        }
        
        // Si hay una URL exitosa, intentar usarla
        if ($url_exitosa) {
            $this->api_url = $url_exitosa;
            $resultado['detalles']['url_corregida'] = $url_exitosa;
            $resultado['mensaje'] .= " La configuración ha sido actualizada.";
        } else {
            // Intentar corrección estándar
            $intentos_correccion = $this->intentar_corregir_url_ini();
            $resultado['detalles']['intento_correccion'] = $intentos_correccion ? 'exitoso' : 'fallido';
            if ($intentos_correccion) {
                $resultado['mensaje'] = '¡URL corregida automáticamente!';
                $resultado['detalles']['nueva_url'] = $this->api_url;
                $resultado['estado'] = 'success';
            }
        }
        
        // Añadir sugerencias basadas en los resultados
        if ($resultado['estado'] === 'error') {
            $resultado['sugerencias'][] = 'Verifica que el número de sesión (' . $sesion . ') sea correcto y esté activo en el sistema de Verial';
            $resultado['sugerencias'][] = 'Contacta con el soporte técnico de Verial para verificar si el archivo INI asociado a tu número de sesión está correctamente configurado';
            $resultado['sugerencias'][] = 'Asegúrate de que la URL base proporcionada por Verial es correcta y no tiene caracteres adicionales';
            $resultado['sugerencias'][] = 'Si cambias manualmente la URL en la configuración del plugin, prueba tanto con como sin barra diagonal al final';
        } else {
            $resultado['sugerencias'][] = 'La URL ha sido corregida y guardada. Verifica que las operaciones de sincronización funcionen correctamente';
            $resultado['sugerencias'][] = 'Si el problema persiste, es probable que el número de sesión sea incorrecto o esté inactivo';
        }
        
        return $resultado;
        
        // 2. Analizar la URL para detectar problemas comunes
        $parsed_url = parse_url($url_base);
        $resultado['detalles']['parsed_url'] = $parsed_url;
        
        // 2.1 Verificar componentes obligatorios
        $prueba_estructura_url = [
            'nombre' => 'Estructura de URL',
            'estado' => 'error',
            'detalles' => []
        ];
        
        if (!isset($parsed_url['scheme'])) {
            $prueba_estructura_url['detalles'][] = 'Falta el protocolo (http:// o https://)';
        }
        
        if (!isset($parsed_url['host']) || empty($parsed_url['host'])) {
            $prueba_estructura_url['detalles'][] = 'Falta el host (dominio del servidor)';
        }
        
        if (!isset($parsed_url['path']) || stripos($parsed_url['path'], 'WcfServiceLibraryVerial') === false) {
            $prueba_estructura_url['detalles'][] = 'Path incorrecto (debe contener WcfServiceLibraryVerial)';
        }
        
        if (empty($prueba_estructura_url['detalles'])) {
            $prueba_estructura_url['estado'] = 'success';
            $prueba_estructura_url['detalles'][] = 'Estructura básica de URL correcta';
        }
        
        $resultado['pruebas'][] = $prueba_estructura_url;
        
        // 2.2 Probar formato de sesión
        $prueba_sesion = [
            'nombre' => 'Número de sesión',
            'estado' => 'error',
            'detalles' => []
        ];
        
        if (empty($sesion)) {
            $prueba_sesion['detalles'][] = 'El número de sesión está vacío';
        } elseif (!is_numeric($sesion)) {
            $prueba_sesion['detalles'][] = 'El número de sesión no es numérico: ' . $sesion;
        } else {
            $sesion_int = (int)$sesion;
            if ($sesion_int <= 0) {
                $prueba_sesion['detalles'][] = 'El número de sesión debe ser mayor que 0';
            } else {
                $prueba_sesion['estado'] = 'success';
                $prueba_sesion['detalles'][] = 'Número de sesión válido: ' . $sesion;
            }
        }
        
        $resultado['pruebas'][] = $prueba_sesion;
        
        // 3. Intentar llamar a GetPaisesWS con diferentes formatos de URL para diagnóstico
        if (!empty($prueba_estructura_url['detalles']) || !empty($prueba_sesion['detalles'])) {
            $resultado['mensaje'] = 'Se encontraron problemas en la configuración básica';
        } else {
            // La configuración básica parece correcta, intentar detectar otros problemas
            $resultado['mensaje'] = 'Configuración básica correcta, realizando pruebas adicionales';
            
            // Sugerencias generales para este error
            $resultado['sugerencias'][] = 'El error "No existe el fichero INI del servicio" generalmente indica un problema con el número de sesión.';
            $resultado['sugerencias'][] = 'Verifique que el número de sesión proporcionado por Verial sea correcto.';
            $resultado['sugerencias'][] = 'Compruebe que la URL base esté configurada exactamente como la proporcionó Verial.';
            $resultado['sugerencias'][] = 'Contacte al soporte técnico de Verial para confirmar la configuración correcta.';
        }
        
        return $resultado;
    }

    /**
     * Método de prueba para validar el sistema de reintentos robusto.
     * 
     * Ejecuta una batería completa de pruebas para validar el funcionamiento
     * del sistema de reintentos, incluyendo:
     * - Pruebas de solicitudes GET con reintentos
     * - Pruebas de solicitudes POST con reintentos
     * - Comparación con métodos legacy sin reintentos
     * - Medición de tiempos de ejecución y rendimiento
     * - Recopilación de estadísticas detalladas
     * - Validación de configuración de reintentos
     * 
     * Útil para validar el sistema después de cambios de configuración
     * o para diagnóstico de problemas de conectividad.
     * 
     * @param string $test_endpoint Endpoint a utilizar para las pruebas (por defecto 'test-connection')
     * 
     * @return array Resultados completos de la prueba:
     *   - 'get_test' (array): Resultados de prueba GET
     *   - 'post_test' (array): Resultados de prueba POST  
     *   - 'legacy_comparison' (array): Comparación con método legacy
     *   - 'summary' (array): Resumen general de las pruebas
     * 
     * @since 1.4.1
     * 
     * @example
     * ```php
     * $results = $connector->testRetrySystem();
     * echo "Pruebas completadas en: " . $results['summary']['total_time'] . "s";
     * echo "Configuración de reintentos: " . json_encode($results['summary']['retry_config']);
     * ```
     * 
     * @see RetryManager Para el sistema de reintentos
     * @see getRetryStats() Para estadísticas detalladas
     */
    public function testRetrySystem(string $test_endpoint = 'test-connection'): array {
        $this->logger->info('[TEST] Iniciando prueba del sistema de retry robusto');
        
        $test_results = [];
        
        // Prueba 1: Solicitud GET con retry robusto
        $this->logger->info('[TEST] Prueba 1: GET con retry robusto');
        $start_time = microtime(true);
        $get_result = $this->makeRequestWithRetry('GET', $test_endpoint, [], ['test' => 'retry_system']);
        $get_time = microtime(true) - $start_time;
        
        $test_results['get_test'] = [
            'success' => !$this->isResponseError($get_result),
            'execution_time' => round($get_time, 3),
            'retry_count' => $this->getLastRetryCount(),
            'result' => $this->isResponseError($get_result) ? $get_result->get_error_message() : 'Success'
        ];
        
        // Prueba 2: Solicitud POST con retry robusto
        $this->logger->info('[TEST] Prueba 2: POST con retry robusto');
        $start_time = microtime(true);
        $post_result = $this->makeRequestWithRetry('POST', $test_endpoint, ['test_data' => 'retry_system_post']);
        $post_time = microtime(true) - $start_time;
        
        $test_results['post_test'] = [
            'success' => !$this->isResponseError($post_result),
            'execution_time' => round($post_time, 3),
            'retry_count' => $this->getLastRetryCount(),
            'result' => $this->isResponseError($post_result) ? $post_result->get_error_message() : 'Success'
        ];
        
        // Prueba 3: Comparación con método legacy
        $this->logger->info('[TEST] Prueba 3: Comparación con método legacy');
        $start_time = microtime(true);
        $legacy_result = $this->makeRequest('GET', $test_endpoint);
        $legacy_time = microtime(true) - $start_time;
        
        $test_results['legacy_comparison'] = [
            'success' => !$this->isResponseError($legacy_result),
            'execution_time' => round($legacy_time, 3),
            'retry_count' => $this->getLastRetryCount(),
            'result' => $this->isResponseError($legacy_result) ? $legacy_result->get_error_message() : 'Success'
        ];
        
        // Resumen de la prueba
        $test_results['summary'] = [
            'total_tests' => 3,
            'total_time' => round($get_time + $post_time + $legacy_time, 3),
            'retry_config' => $this->getRetryConfig(),
            'api_url' => $this->getApiUrl(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $this->logger->info('[TEST] Prueba del sistema de retry completada', $test_results['summary']);
        
        return $test_results;
    }

    /**
     * Realiza un diagnóstico completo y detallado de la conexión a la API.
     * 
     * Sistema de diagnóstico avanzado que analiza todos los aspectos de la conectividad:
     * - Validación de configuración básica (URL, sesión)
     * - Verificación de formato y estructura de URLs
     * - Detección de problemas comunes de configuración
     * - Logging optimizado para diferentes entornos (desarrollo/producción)
     * - Prevención de errores HTTP 405 en verificaciones automáticas
     * - Sugerencias específicas para resolución de problemas
     * 
     * El diagnóstico está optimizado para evitar llamadas HTTP innecesarias
     * que puedan causar errores en verificaciones automáticas.
     * 
     * @return array Resultado detallado del diagnóstico:
     *   - 'estado' (string): 'success', 'warning', o 'error'
     *   - 'mensaje' (string): Descripción del resultado
     *   - 'detalles' (array): Información técnica detallada
     *   - 'sugerencias' (array): Recomendaciones para resolver problemas
     * 
     * @since 1.0.0
     * @since 1.4.1 Agregado diagnóstico avanzado sin llamadas HTTP
     * @since 2.0.0 Optimizado para diferentes entornos de ejecución
     * 
     * @example
     * ```php
     * $diagnostico = $connector->diagnosticarConexion();
     * echo "Estado: " . $diagnostico['estado'];
     * echo "Mensaje: " . $diagnostico['mensaje'];
     * 
     * if (!empty($diagnostico['sugerencias'])) {
     *     foreach ($diagnostico['sugerencias'] as $sugerencia) {
     *         echo "- " . $sugerencia . "\n";
     *     }
     * }
     * ```
     * 
     * @see test_connectivity() Para prueba real de conectividad
     * @see validate_configuration() Para validación de configuración
     * @see shouldLogDebug() Para control de logging optimizado
     * @throws \Exception Si VerialApiConfig no está disponible
     */
    public function diagnosticarConexion(): array
    {
        // FASE 1: OPTIMIZADO - Logging optimizado y condicional
        // Solo debug cuando es necesario para diagnóstico real
        if ($this->shouldLogDebug()) {
            $debug_trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3); // Solo 3 niveles
            $caller_info = [];
            foreach ($debug_trace as $i => $trace) {
                if ($i > 0) { // Saltar el primer nivel (este método)
                    $caller_info[] = sprintf(
                        '#%d %s:%d %s%s%s()',
                        $i,
                        basename($trace['file'] ?? 'unknown'), // Solo nombre del archivo
                        $trace['line'] ?? 0,
                        $trace['class'] ?? '',
                        $trace['type'] ?? '',
                        $trace['function'] ?? 'unknown'
                    );
                }
            }
            
            // FASE 2: Usar logger unificado
            $logger = \MiIntegracionApi\Helpers\Logger::getInstance('api-connector');
            $logger->info('diagnosticarConexion() llamado', [
                'user_id' => get_current_user_id(),
                'caller' => $caller_info[0] ?? 'unknown'
            ]);
        }
        
        $resultado = [
            'estado' => 'error',
            'detalles' => [],
            'sugerencias' => []
        ];
        
        // Delegar construcción de URL a VerialApiConfig
        $verial_config = $this->safeCreateVerialConfig();
        $url_base = rtrim($verial_config->getVerialBaseUrl(), '/');
        $sesion = $this->getSesionWcf();
        
        // Detección detallada de errores de configuración
        $request_info = $this->get_last_request_info();
        $resultado['detalles'] = array_merge($resultado['detalles'], $request_info, [
            'api_url_procesada' => $url_base
        ]);
        
        if (empty($url_base)) {
            $resultado['mensaje'] = 'No se ha configurado la URL base de la API de Verial';
            $resultado['sugerencias'][] = 'Configure la URL base en Ajustes > Mi Integración API';
            $resultado['sugerencias'][] = 'Formato esperado: http://x.verial.org:8000/WcfServiceLibraryVerial';
            return $resultado;
        }
        
        // Verificar formato de URL
        if (parse_url($url_base, PHP_URL_HOST) === null) {
            $resultado['mensaje'] = 'La URL base tiene un formato inválido';
            $resultado['sugerencias'][] = 'La URL debe incluir el protocolo (http:// o https://)';
            $resultado['sugerencias'][] = 'Ejemplo correcto: http://x.verial.org:8000/WcfServiceLibraryVerial';
            return $resultado;
        }
        
        if (empty($sesion)) {
            $resultado['mensaje'] = 'No se ha configurado el número de sesión (sesionwcf)';
            $resultado['sugerencias'][] = 'Configure el número de sesión en Ajustes > Mi Integración API';
            return $resultado;
        }
        
        // 2. TEMPORALMENTE DESHABILITADO - Evitar conexión básica que causa error 405
        // $test_url = rtrim($url_base, '/') . '/testConexion?x=' . $sesion;
        
        // Verificación básica sin hacer peticiones HTTP reales
        $resultado['estado'] = 'warning';
        $resultado['mensaje'] = 'Configuración básica verificada - prueba de conectividad deshabilitada temporalmente';
        $resultado['detalles'] = array_merge($resultado['detalles'], [
            'verification_skipped' => true,
            'reason' => 'Prevenir errores HTTP 405 en verificaciones automáticas',
            'config_loaded_from' => $this->config_source
        ]);
        $resultado['sugerencias'][] = 'Para probar la conectividad real, use la herramienta de diagnóstico manual';
        $resultado['sugerencias'][] = 'Las sincronizaciones manuales funcionarán normalmente';
        
        return $resultado;
    }
    
    /**
     * FASE 1: Determina si se debe hacer logging de debug
     * Aplica buenas prácticas: DRY, Single Responsibility
     *
     * @return bool True si se debe hacer debug logging
     */
    private function shouldLogDebug(): bool {
        // Solo debug en desarrollo o si está explícitamente habilitado
        $environment = $this->detectEnvironment();
        $explicitDebug = defined('MIA_DEBUG_LOGGING') && constant('MIA_DEBUG_LOGGING');
        $wpDebug = defined('WP_DEBUG') && constant('WP_DEBUG');
        
        // FASE 1: Validación temprana (Fail Fast)
        if ($environment === 'production' && !$explicitDebug) {
            return false; // Nunca debug en producción sin configuración explícita
        }
        
        return $explicitDebug || ($wpDebug && $environment !== 'production');
    }
    
    /**
     * FASE 1: Detecta el entorno actual de forma inteligente
     * Aplica buenas prácticas: Configuration over Convention
     *
     * @return string Entorno detectado
     */
    private function detectEnvironment(): string {
        // Prioridad 1: Constante explícita
        if (defined('MIA_ENVIRONMENT')) {
            return constant('MIA_ENVIRONMENT');
        }
        
        // Prioridad 2: Constante de WordPress
        if (defined('WP_ENVIRONMENT_TYPE')) {
            return constant('WP_ENVIRONMENT_TYPE');
        }
        
        // Prioridad 3: Detección automática
        if (defined('WP_DEBUG') && constant('WP_DEBUG')) {
            return 'development';
        }
        
        // Prioridad 4: Fallback seguro
        return 'production';
    }
    
        /*
        // CÓDIGO ORIGINAL - TEMPORALMENTE COMENTADO
        try {
            $response = wp_remote_get($test_url, [
                'timeout' => 15,
                'sslverify' => false
            ]);
            
            if ($this->isResponseError($response)) {
                $resultado['mensaje'] = 'Error al conectar con la API: ' . $response->get_error_message();
                $resultado['sugerencias'][] = 'Verifique que la URL base sea correcta';
                $resultado['sugerencias'][] = 'Compruebe que su servidor puede conectarse a Internet';
                $resultado['detalles']['error'] = $response->get_error_message();
                return $resultado;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            $resultado['detalles']['status_code'] = $status_code;
            $resultado['detalles']['response_size'] = strlen($body);
            
            if ($status_code === 404) {
                $resultado['mensaje'] = 'La URL de la API no existe (404)';
                $resultado['sugerencias'][] = 'Verifique que la URL base sea correcta';
                $resultado['sugerencias'][] = 'Confirme que la API de Verial está accesible desde su servidor';
                return $resultado;
            }
            
            if ($status_code >=  400) {
                $resultado['mensaje'] = "Error HTTP $status_code al conectar con la API";
                $resultado['sugerencias'][] = 'Verifique que la URL y sesión sean correctas';
                $resultado['detalles']['response'] = substr($body, 0, 500); // Primeros 500 caracteres
                return $resultado;
            }
            
            // 3. Intentar la conexión parecida a los endpoints normales
            $endpoint = 'GetPaisesWS'; // Endpoint que sabemos que funciona si la conexión es correcta
            $this->logger->info('[DIAGNÓSTICO] Probando endpoint GetPaisesWS');
            $response_api = $this->get($endpoint);
            
            if ($this->isResponseError($response_api)) {
                $resultado['mensaje'] = 'Error al probar un endpoint de API: ' . $response_api->get_error_message();
                $resultado['detalles']['endpoint_error'] = $response_api->get_error_message();
                $resultado['sugerencias'][] = 'Verifique que el número de sesión sea correcto';
                $resultado['detalles']['last_url'] = $this->get_last_request_url();
                return $resultado;
            }
            
            // Detectar error específico "No existe el fichero INI del servicio"
            if (isset($response_api['InfoError']) && 
                isset($response_api['InfoError']['Descripcion']) && 
                stripos($response_api['InfoError']['Descripcion'], 'No existe el fichero INI del servicio') !== false) {
                
                $resultado['mensaje'] = 'Error crítico: ' . $response_api['InfoError']['Descripcion'];
                $resultado['detalles']['codigo_error'] = $response_api['InfoError']['Codigo'];
                $resultado['detalles']['url_usada'] = $this->get_last_request_url();
                
                // Sugerencias específicas para este error
                $resultado['sugerencias'][] = 'Este error indica que el servicio no puede encontrar el archivo INI de configuración.';
                $resultado['sugerencias'][] = 'Verifique que la URL base no tenga caracteres adicionales o errores de formato.';
                $resultado['sugerencias'][] = 'Confirme que el número de sesión es correcto (debe ser proporcionado por Verial).';
                $resultado['sugerencias'][] = 'Contacte a soporte de Verial para validar la configuración de su cuenta.';
                
                $this->logger->error('[DIAGNÓSTICO] Detectado error de fichero INI', [
                    'url' => $this->get_last_request_url(),
                    'sesion' => $this->getSesionWcf(),
                    'error_descripcion' => $response_api['InfoError']['Descripcion'],
                    'error_codigo' => $response_api['InfoError']['Codigo']
                ]);
                
                return $resultado;
            }
            
            // Verificar si hay otros errores de API
            if (isset($response_api['InfoError']) && $response_api['InfoError']['Codigo'] != 0) {
                $error_message = $this->getResponseErrorMessage([
                    'error' => 'api_error',
                    'message' => $response_api['InfoError']['Descripcion'] ?? 'Error desconocido'
                ]);
                $resultado['mensaje'] = 'Error de API: ' . $error_message;
                $resultado['detalles']['codigo_error'] = $response_api['InfoError']['Codigo'];
                $resultado['detalles']['url_usada'] = $this->get_last_request_url();
                $resultado['sugerencias'][] = 'Verifique que el número de sesión sea correcto';
                return $resultado;
            }
            
            // Si llegamos aquí, la conexión parece funcionar
            $resultado['estado'] = 'success';
            $resultado['mensaje'] = 'Conexión establecida correctamente';
            $resultado['detalles']['url_final'] = $this->get_last_request_url();
            
            return $resultado;
        } catch (\Exception $e) {
            $resultado['mensaje'] = 'Error inesperado al diagnosticar conexión: ' . $e->getMessage();
            $resultado['detalles']['exception'] = $e->getMessage();
            $resultado['detalles']['trace'] = $e->getTraceAsString();
            return $resultado;
        }
        */
    

    /**
     * Devuelve información de la última solicitud para diagnósticos
     * 
     * @return array Información de la última solicitud
     */
    public function get_last_request_info(): array
    {
        return [
            'api_url' => $this->api_url ?? 'no configurada',
            'sesionwcf' => $this->sesionwcf ?? 'no configurada',
            'config_source' => $this->config_source ?? 'desconocido',
            'cache_enabled' => $this->cache_enabled ?? false,
            'cache_stats' => $this->cache_stats ?? [],
            'ssl_settings' => [
                'ssl_enabled' => isset($this->ssl_verify) ? ($this->ssl_verify ? 'habilitado' : 'deshabilitado') : 'no configurado',
                'cert_path' => $this->cert_path ?? 'no configurado',
                'ca_path' => $this->ca_path ?? 'no configurado',
            ],
            'last_error' => $this->last_error ?? 'ninguno'
        ];
    }

    /**
     * Inicializa la configuración de la API
     *
     * @param array $config Configuración de la API
     * @return bool
     */
    public function init_config($config): bool
    {
        // Validar solo la URL de la API, ya que api_key no es requerido para Verial
        if (!isset($config['api_url']) || empty($config['api_url'])) {
            $this->logger->error('Configuración de API inválida: api_url requerida');
            return false;
        }

        if (!filter_var($config['api_url'], FILTER_VALIDATE_URL)) {
            $this->logger->error('Configuración de API inválida: api_url no es una URL válida');
            return false;
        }

        $this->api_key = $config['api_key'] ?? ''; // Opcional
        $this->api_url = $config['api_url'];
        
        // Si se proporciona sesionwcf, también actualizarlo (solo si es diferente)
        if (isset($config['sesionwcf'])) {
            $new_session = (int) $config['sesionwcf'];
            if ($this->sesionwcf !== $new_session) {
                try {
                    $this->set_session_number($config['sesionwcf']);
                } catch (\Exception $e) {
                    $this->logger->warning('Error validando número de sesión en init_config, usando asignación directa: ' . $e->getMessage());
                    $this->sesionwcf = $new_session;
                }
            }
        }
        
        $this->logger->info('Configuración de API actualizada', [
            'api_url' => $this->api_url,
            'sesionwcf' => $this->sesionwcf,
            'config_source' => 'manual_init'
        ]);
        
        return true;
    }
    
    /**
     * Reconfigura el ApiConnector con nueva configuración
     *
     * @param array $config Nueva configuración
     * @return bool Éxito de la reconfiguración
     */
    public function reconfigure(array $config): bool
    {
        return $this->init_config($config);
    }

    /**
     * Realiza una petición a la API
     *
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos a enviar
     * @param string $method Método HTTP
     * @return array|false
     * @throws \Exception Si VerialApiConfig no está disponible
     */
    public function make_request($endpoint, $data = [], $method = 'GET'): bool|array
    {
        // Validar solo la URL de la API, ya que api_key no es requerido para Verial
        if (empty($this->api_url)) {
            $this->logger->error('Configuración de API inválida: api_url no configurada');
            return false;
        }

        try {
            // Validar endpoint
            if (empty($endpoint)) {
                $this->logger->error('Endpoint vacío en make_request');
                return false;
            }

            // Construir URL completa delegando a VerialApiConfig
            $verial_config = $this->safeCreateVerialConfig();
            $url = rtrim($verial_config->getVerialBaseUrl(), '/') . '/' . ltrim($endpoint, '/');
            
            // Configurar headers
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ];
            
            // Solo agregar Authorization si api_key está configurado
            if (!empty($this->api_key)) {
                $headers['Authorization'] = 'Bearer ' . $this->api_key;
            }

            // Configurar opciones de cURL
            $curl_options = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true
            ];

            // Configurar método HTTP
            if ($method === 'POST') {
                $curl_options[CURLOPT_POST] = true;
                if (!empty($data)) {
                    $curl_options[CURLOPT_POSTFIELDS] = json_encode($data);
                }
            } elseif ($method === 'PUT') {
                $curl_options[CURLOPT_CUSTOMREQUEST] = 'PUT';
                if (!empty($data)) {
                    $curl_options[CURLOPT_POSTFIELDS] = json_encode($data);
                }
            } elseif ($method === 'DELETE') {
                $curl_options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
            }

            // Ejecutar petición
            $ch = curl_init();
            curl_setopt_array($ch, $curl_options);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);

            // Verificar errores de cURL
            if ($error) {
                $this->logger->error('Error de cURL en make_request', [
                    'endpoint' => $endpoint,
                    'error' => $error
                ]);
                return false;
            }

            // Verificar código de respuesta HTTP
            if ($http_code < 200 || $http_code >= 300) {
                $this->logger->error('Respuesta HTTP no exitosa en make_request', [
                    'endpoint' => $endpoint,
                    'http_code' => $http_code,
                    'response' => $response
                ]);
                return false;
            }

            // Decodificar respuesta JSON
            $decoded_response = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error_message = $this->getResponseErrorMessage(['error' => 'invalid_json', 'message' => json_last_error_msg()]);
                $this->logger->error('Error al decodificar respuesta JSON en make_request', [
                    'endpoint' => $endpoint,
                    'json_error' => json_last_error_msg(),
                    'error_message' => $error_message
                ]);
                return false;
            }

            return $decoded_response;

        } catch (\Exception $e) {
            $this->logger->error('Excepción en make_request', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Verifica si la conexión con la API está activa y responde correctamente
     * Si la conexión está inactiva, intenta reiniciarla
     *
     * @return bool True si la conexión está activa o se reinició correctamente
     */
    public function check_and_restart_connection(): bool
    {
        if (!$this->is_connected()) {
            if ($this->logger) {
                $this->logger->info('La conexión con la API no está activa, intentando reiniciarla');
            }
            
            // Forzar reconexión
            // Las propiedades de sesión se manejan a través de $this->sesionwcf
            
            // Intentar conectar nuevamente
            $result = $this->init_api_connection();
            
            if ($result) {
                // Actualizar timestamp de última conexión exitosa
                $this->last_connection_time = time();
                
                if ($this->logger) {
                    $this->logger->info('Conexión reiniciada con éxito', [
                        'timestamp' => date('Y-m-d H:i:s', $this->last_connection_time),
                        'session' => $this->sesionwcf ?? 'No disponible'
                    ]);
                }
            }
            
            return $result;
        }
        
        // La conexión está activa, actualizar timestamp
        $this->last_connection_time = time();
        return true;
    }
    
    /**
     * Inicializa la conexión con la API
     * 
     * @return bool True si la conexión se estableció correctamente
     */
    private function init_api_connection(): bool
    {
        // Por ahora solo actualizamos el timestamp
        // En el futuro podríamos implementar una verdadera autenticación
        $this->last_connection_time = time();
        
        if ($this->sesionwcf <= 0) {
            $this->sesionwcf = 18; // Valor predeterminado si no hay sesión
        }
        
        return true;
    }
    
    /**
     * Verifica si las credenciales son válidas y la conexión está activa
     *
     * @return bool True si las credenciales son válidas y la conexión está activa
     */
    public function has_valid_credentials(): bool {
        // Verificar primero la configuración básica
        $config_validation = $this->validate_configuration();
        if (!$config_validation->isSuccess()) {
            if ($this->logger) {
                $this->logger->warning('Configuración inválida en has_valid_credentials', [
                    'message' => $config_validation->getMessage(),
                    'errors' => $config_validation->getData()['errors'] ?? []
                ]);
            }
            return false;
        }
        
        // Verificar si ya hay una conexión activa
        if ($this->is_connected()) {
            return true;
        }
        
        // Intentar reiniciar la conexión si no está activa
        return $this->check_and_restart_connection();
    }
    
    /**
     * Determina si la conexión está activa según los datos de sesión
     *
     * @return bool
     */
    public function is_connected(): bool
    {
        // Si no hay número de sesión, definitivamente no está conectado
        if (empty($this->sesionwcf)) {
            return false;
        }
        
        // Si la última conexión fue hace más de 20 minutos, considerarla caducada
        if (!empty($this->last_connection_time) && 
            time() - $this->last_connection_time > 1200) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Determina si un error de cURL es reintentable
     *
     * @param int $curl_error Código de error de cURL
     * @return bool true si el error es reintentable, false en caso contrario
     */
    private function isCurlErrorRetryable(int $curl_error): bool {
        // Errores de cURL que son reintentables
        $retryable_errors = [
            CURLE_COULDNT_CONNECT,        // 7 - No se pudo conectar al servidor
            CURLE_COULDNT_RESOLVE_HOST,   // 6 - No se pudo resolver el host
            CURLE_OPERATION_TIMEOUTED,    // 28 - Timeout de operación
            CURLE_SSL_CONNECT_ERROR,      // 35 - Error de conexión SSL
            CURLE_RECV_ERROR,             // 56 - Error de recepción
            CURLE_SEND_ERROR,             // 55 - Error de envío
            ...(defined('CURLE_HTTP2_ERROR') ? [CURLE_HTTP2_ERROR] : []), // 16 - Error HTTP/2
            CURLE_PARTIAL_FILE,           // 18 - Archivo parcial
            CURLE_READ_ERROR,             // 26 - Error de lectura
            CURLE_WRITE_ERROR,            // 23 - Error de escritura
        ];
        
        return in_array($curl_error, $retryable_errors, true);
    }

    /**
     * Convierte un código de error cURL a un mensaje descriptivo
     *
     * @param int $curl_error Código de error de cURL
     * @return string Mensaje descriptivo del error
     */
    private function getCurlErrorMessage(int $curl_error): string {
        $error_messages = [
            CURLE_OK => 'No hay error',
            CURLE_UNSUPPORTED_PROTOCOL => 'Protocolo no soportado',
            CURLE_FAILED_INIT => 'Falló la inicialización de cURL',
            ...(defined('CURLE_URL_MALFORMED') ? [CURLE_URL_MALFORMED => 'URL malformada'] : []),
            ...(defined('CURLE_NOT_BUILT_IN') ? [CURLE_NOT_BUILT_IN => 'Funcionalidad no incluida en la compilación'] : []),
            CURLE_COULDNT_RESOLVE_PROXY => 'No se pudo resolver el proxy',
            CURLE_COULDNT_RESOLVE_HOST => 'No se pudo resolver el host',
            CURLE_COULDNT_CONNECT => 'No se pudo conectar al servidor',
            ...(defined('CURLE_WEIRD_SERVER_REPLY') ? [CURLE_WEIRD_SERVER_REPLY => 'Respuesta extraña del servidor'] : []),
            ...(defined('CURLE_REMOTE_ACCESS_DENIED') ? [CURLE_REMOTE_ACCESS_DENIED => 'Acceso remoto denegado'] : []),
            CURLE_OPERATION_TIMEOUTED => 'Timeout de operación',
            CURLE_SSL_CONNECT_ERROR => 'Error de conexión SSL',
            ...(defined('CURLE_BAD_DOWNLOAD_RESUME') ? [CURLE_BAD_DOWNLOAD_RESUME => 'Error al reanudar descarga'] : []),
            ...(defined('CURLE_FILE_COULDNT_READ_FILE') ? [CURLE_FILE_COULDNT_READ_FILE => 'No se pudo leer el archivo'] : []),
            ...(defined('CURLE_LDAP_CANNOT_BIND') ? [CURLE_LDAP_CANNOT_BIND => 'No se pudo hacer bind LDAP'] : []),
            ...(defined('CURLE_LDAP_SEARCH_FAILED') ? [CURLE_LDAP_SEARCH_FAILED => 'Búsqueda LDAP falló'] : []),
            ...(defined('CURLE_FUNCTION_NOT_FOUND') ? [CURLE_FUNCTION_NOT_FOUND => 'Función no encontrada'] : []),
            ...(defined('CURLE_ABORTED_BY_CALLBACK') ? [CURLE_ABORTED_BY_CALLBACK => 'Abortado por callback'] : []),
            ...(defined('CURLE_BAD_FUNCTION_ARGUMENT') ? [CURLE_BAD_FUNCTION_ARGUMENT => 'Argumento de función incorrecto'] : []),
            ...(defined('CURLE_INTERFACE_FAILED') ? [CURLE_INTERFACE_FAILED => 'Fallo de interfaz'] : []),
            CURLE_TOO_MANY_REDIRECTS => 'Demasiadas redirecciones',
            ...(defined('CURLE_UNKNOWN_OPTION') ? [CURLE_UNKNOWN_OPTION => 'Opción desconocida'] : []),
            ...(defined('CURLE_TELNET_OPTION_SYNTAX') ? [CURLE_TELNET_OPTION_SYNTAX => 'Sintaxis de opción telnet incorrecta'] : []),
            ...(defined('CURLE_PEER_FAILED_VERIFICATION') ? [CURLE_PEER_FAILED_VERIFICATION => 'Verificación de peer falló'] : []),
            ...(defined('CURLE_GOT_NOTHING') ? [CURLE_GOT_NOTHING => 'No se recibió nada'] : []),
            ...(defined('CURLE_SSL_ENGINE_NOTFOUND') ? [CURLE_SSL_ENGINE_NOTFOUND => 'Motor SSL no encontrado'] : []),
            ...(defined('CURLE_SSL_ENGINE_SETFAILED') ? [CURLE_SSL_ENGINE_SETFAILED => 'Configuración de motor SSL falló'] : []),
            CURLE_SEND_ERROR => 'Error de envío',
            CURLE_RECV_ERROR => 'Error de recepción',
            ...(defined('CURLE_SSL_CERTPROBLEM') ? [CURLE_SSL_CERTPROBLEM => 'Problema con certificado SSL'] : []),
            ...(defined('CURLE_SSL_CIPHER') ? [CURLE_SSL_CIPHER => 'Problema con cifrado SSL'] : []),
            ...(defined('CURLE_SSL_CACERT') ? [CURLE_SSL_CACERT => 'Problema con CA SSL'] : []),
            ...(defined('CURLE_BAD_CONTENT_ENCODING') ? [CURLE_BAD_CONTENT_ENCODING => 'Codificación de contenido incorrecta'] : []),
            ...(defined('CURLE_LDAP_INVALID_URL') ? [CURLE_LDAP_INVALID_URL => 'URL LDAP inválida'] : []),
            ...(defined('CURLE_FILESIZE_EXCEEDED') ? [CURLE_FILESIZE_EXCEEDED => 'Tamaño de archivo excedido'] : []),
            ...(defined('CURLE_USE_SSL_FAILED') ? [CURLE_USE_SSL_FAILED => 'Uso de SSL falló'] : []),
            ...(defined('CURLE_SEND_FAIL_REWIND') ? [CURLE_SEND_FAIL_REWIND => 'Fallo de envío al rebobinar'] : []),
            ...(defined('CURLE_SSL_ENGINE_INITFAILED') ? [CURLE_SSL_ENGINE_INITFAILED => 'Inicialización de motor SSL falló'] : []),
            ...(defined('CURLE_LOGIN_DENIED') ? [CURLE_LOGIN_DENIED => 'Login denegado'] : []),
            ...(defined('CURLE_TFTP_NOTFOUND') ? [CURLE_TFTP_NOTFOUND => 'Archivo TFTP no encontrado'] : []),
            ...(defined('CURLE_TFTP_PERM') ? [CURLE_TFTP_PERM => 'Permiso TFTP denegado'] : []),
            ...(defined('CURLE_REMOTE_DISK_FULL') ? [CURLE_REMOTE_DISK_FULL => 'Disco remoto lleno'] : []),
            ...(defined('CURLE_TFTP_ILLEGAL') ? [CURLE_TFTP_ILLEGAL => 'Operación TFTP ilegal'] : []),
            ...(defined('CURLE_TFTP_UNKNOWNID') ? [CURLE_TFTP_UNKNOWNID => 'ID TFTP desconocido'] : []),
            ...(defined('CURLE_REMOTE_FILE_EXISTS') ? [CURLE_REMOTE_FILE_EXISTS => 'Archivo remoto ya existe'] : []),
            ...(defined('CURLE_TFTP_NOSUCHUSER') ? [CURLE_TFTP_NOSUCHUSER => 'Usuario TFTP no existe'] : []),
            ...(defined('CURLE_CONV_FAILED') ? [CURLE_CONV_FAILED => 'Conversión falló'] : []),
            ...(defined('CURLE_CONV_REQD') ? [CURLE_CONV_REQD => 'Conversión requerida'] : []),
            ...(defined('CURLE_SSL_CACERT_BADFILE') ? [CURLE_SSL_CACERT_BADFILE => 'Archivo CA SSL incorrecto'] : []),
            ...(defined('CURLE_REMOTE_FILE_NOT_FOUND') ? [CURLE_REMOTE_FILE_NOT_FOUND => 'Archivo remoto no encontrado'] : []),
            ...(defined('CURLE_SSH') ? [CURLE_SSH => 'Error SSH'] : []),
            ...(defined('CURLE_SSL_SHUTDOWN_FAILED') ? [CURLE_SSL_SHUTDOWN_FAILED => 'Cierre SSL falló'] : []),
            ...(defined('CURLE_AGAIN') ? [CURLE_AGAIN => 'Inténtalo de nuevo'] : []),
            ...(defined('CURLE_SSL_CRL_BADFILE') ? [CURLE_SSL_CRL_BADFILE => 'Archivo CRL SSL incorrecto'] : []),
            ...(defined('CURLE_SSL_ISSUER_ERROR') ? [CURLE_SSL_ISSUER_ERROR => 'Error de emisor SSL'] : []),
            ...(defined('CURLE_FTP_PRET_FAILED') ? [CURLE_FTP_PRET_FAILED => 'Comando PRET FTP falló'] : []),
            ...(defined('CURLE_RTSP_CSEQ_ERROR') ? [CURLE_RTSP_CSEQ_ERROR => 'Error de secuencia RTSP'] : []),
            ...(defined('CURLE_RTSP_SESSION_ERROR') ? [CURLE_RTSP_SESSION_ERROR => 'Error de sesión RTSP'] : []),
            ...(defined('CURLE_FTP_BAD_FILE_LIST') ? [CURLE_FTP_BAD_FILE_LIST => 'Lista de archivos FTP incorrecta'] : []),
            ...(defined('CURLE_CHUNK_FAILED') ? [CURLE_CHUNK_FAILED => 'Chunk falló'] : []),
            ...(defined('CURLE_NO_CONNECTION_AVAILABLE') ? [CURLE_NO_CONNECTION_AVAILABLE => 'No hay conexión disponible'] : []),
            ...(defined('CURLE_SSL_PINNEDPUBKEYNOTMATCH') ? [CURLE_SSL_PINNEDPUBKEYNOTMATCH => 'Clave pública SSL no coincide'] : []),
            ...(defined('CURLE_SSL_INVALIDCERTSTATUS') ? [CURLE_SSL_INVALIDCERTSTATUS => 'Estado de certificado SSL inválido'] : []),
            ...(defined('CURLE_HTTP2_STREAM') ? [CURLE_HTTP2_STREAM => 'Error de stream HTTP/2'] : []),
            ...(defined('CURLE_RECURSIVE_API_CALL') ? [CURLE_RECURSIVE_API_CALL => 'Llamada API recursiva'] : []),
            ...(defined('CURLE_AUTH_ERROR') ? [CURLE_AUTH_ERROR => 'Error de autenticación'] : []),
            // CURLE_HTTP3 => 'Error HTTP/3', // No disponible en todas las versiones de cURL
            // CURLE_QUIC_CONNECT_ERROR => 'Error de conexión QUIC', // No disponible en todas las versiones de cURL
            //CURLE_SSL_CLIENTCERT => 'Error de certificado cliente SSL',
            // CURLE_UNRECOVERABLE_PROTOCOL => 'Protocolo irrecuperable',
            ...(defined('CURLE_FTP_ACCOUNT') ? [CURLE_FTP_ACCOUNT => 'Cuenta FTP requerida'] : []),
            ...(defined('CURLE_SSL_ENGINE_INITFAILED') ? [CURLE_SSL_ENGINE_INITFAILED => 'Inicialización de motor SSL falló'] : []),
            ...(defined('CURLE_FTP_ACCEPT_FAILED') ? [CURLE_FTP_ACCEPT_FAILED => 'Aceptación FTP falló'] : []),
            ...(defined('CURLE_FTP_WEIRD_PASS_REPLY') ? [CURLE_FTP_WEIRD_PASS_REPLY => 'Respuesta de contraseña FTP extraña'] : []),
            ...(defined('CURLE_FTP_ACCEPT_TIMEOUT') ? [CURLE_FTP_ACCEPT_TIMEOUT => 'Timeout de aceptación FTP'] : []),
            ...(defined('CURLE_FTP_PRET_FAILED') ? [CURLE_FTP_PRET_FAILED => 'Comando PRET FTP falló'] : []),
            ...(defined('CURLE_FTP_WEIRD_PASV_REPLY') ? [CURLE_FTP_WEIRD_PASV_REPLY => 'Respuesta PASV FTP extraña'] : []),
            ...(defined('CURLE_FTP_WEIRD_227_FORMAT') ? [CURLE_FTP_WEIRD_227_FORMAT => 'Formato 227 FTP extraño'] : []),
            ...(defined('CURLE_FTP_CANT_GET_HOST') ? [CURLE_FTP_CANT_GET_HOST => 'No se pudo obtener host FTP'] : []),
            ...(defined('CURLE_HTTP2') ? [CURLE_HTTP2 => 'Error HTTP/2'] : []),
            ...(defined('CURLE_FTP_COULDNT_SET_TYPE') ? [CURLE_FTP_COULDNT_SET_TYPE => 'No se pudo establecer tipo FTP'] : []),
            CURLE_PARTIAL_FILE => 'Archivo parcial',
            ...(defined('CURLE_FTP_COULDNT_RETR_FILE') ? [CURLE_FTP_COULDNT_RETR_FILE => 'No se pudo recuperar archivo FTP'] : []),
            ...(defined('CURLE_QUOTE_ERROR') ? [CURLE_QUOTE_ERROR => 'Error de comando'] : []),
            ...(defined('CURLE_HTTP_RETURNED_ERROR') ? [CURLE_HTTP_RETURNED_ERROR => 'HTTP devolvió error'] : []),
            CURLE_WRITE_ERROR => 'Error de escritura',
            ...(defined('CURLE_UPLOAD_FAILED') ? [CURLE_UPLOAD_FAILED => 'Subida falló'] : []),
            CURLE_READ_ERROR => 'Error de lectura',
            ...(defined('CURLE_OUT_OF_MEMORY') ? [CURLE_OUT_OF_MEMORY => 'Memoria insuficiente'] : []),
            ...(defined('CURLE_OPERATION_TIMEDOUT') ? [CURLE_OPERATION_TIMEDOUT => 'Operación con timeout'] : []),
            ...(defined('CURLE_FTP_PORT_FAILED') ? [CURLE_FTP_PORT_FAILED => 'Puerto FTP falló'] : []),
            ...(defined('CURLE_FTP_COULDNT_USE_REST') ? [CURLE_FTP_COULDNT_USE_REST => 'No se pudo usar REST FTP'] : []),
            ...(defined('CURLE_RANGE_ERROR') ? [CURLE_RANGE_ERROR => 'Error de rango'] : []),
            ...(defined('CURLE_HTTP_POST_ERROR') ? [CURLE_HTTP_POST_ERROR => 'Error de POST HTTP'] : [])
        ];

        return $error_messages[$curl_error] ?? "Error de cURL desconocido (código: $curl_error)";
    }

    /**
     * Crea una instancia de VerialApiConfig de forma segura
     * 
     * Este método garantiza que VerialApiConfig esté disponible y la instancia de forma segura.
     * Incluye verificación de clase y manejo de errores.
     * 
     * @return \VerialApiConfig Instancia de VerialApiConfig
     * @throws \Exception Si VerialApiConfig no está disponible
     * @since 2.0.0
     */
    private function safeCreateVerialConfig(): \VerialApiConfig {
        // Asegurar que VerialApiConfig esté cargada
        $this->ensureVerialApiConfigLoaded();
        
        // Verificar que esté disponible
        if (!class_exists('VerialApiConfig')) {
            throw new \Exception('VerialApiConfig no está disponible después de intentar cargarla');
        }
        
        // Usar la instancia singleton de VerialApiConfig para evitar configuraciones duplicadas
        try {
            return \VerialApiConfig::getInstance();
        } catch (\Throwable $e) {
            throw new \Exception('Error obteniendo instancia singleton de VerialApiConfig: ' . $e->getMessage());
        }
    }

    /**
     * Asegura que la clase VerialApiConfig esté cargada
     * 
     * Este método garantiza que VerialApiConfig esté disponible antes de intentar usarla.
     * Utiliza múltiples estrategias de carga para asegurar la disponibilidad.
     * 
     * @return void
     * @since 2.0.0
     */
    private function ensureVerialApiConfigLoaded(): void {
        // Si ya está cargada, no hacer nada
        if (class_exists('VerialApiConfig')) {
            return;
        }

        // Estrategia 1: Intentar cargar desde EmergencyLoader si está disponible
        if (class_exists('MiIntegracionApi\\Core\\EmergencyLoader')) {
            try {
                \MiIntegracionApi\Core\EmergencyLoader::init();
                if (class_exists('VerialApiConfig')) {
                    return;
                }
            } catch (\Throwable $e) {
                // Continuar con otras estrategias si EmergencyLoader falla
            }
        }

        // Estrategia 2: Cargar directamente desde verialconfig.php
        $verialconfig_path = defined('MiIntegracionApi_PLUGIN_DIR') 
            ? MiIntegracionApi_PLUGIN_DIR . 'verialconfig.php'
            : __DIR__ . '/../../verialconfig.php';

        if (file_exists($verialconfig_path)) {
            try {
                // Definir constante para evitar el return del array
                if (!defined('LOADING_VERIALCONFIG_CLASS_ONLY')) {
                    define('LOADING_VERIALCONFIG_CLASS_ONLY', true);
                }
                require_once $verialconfig_path;
                
                if (class_exists('VerialApiConfig')) {
                    return;
                }
            } catch (\Throwable $e) {
                // Continuar con la siguiente estrategia si falla
            }
        }

        // Estrategia 3: Intentar cargar desde el autoloader de Composer
        $composer_autoload_path = defined('MiIntegracionApi_PLUGIN_DIR') 
            ? MiIntegracionApi_PLUGIN_DIR . 'vendor/autoload.php'
            : __DIR__ . '/../../vendor/autoload.php';

        if (file_exists($composer_autoload_path)) {
            try {
                require_once $composer_autoload_path;
                if (class_exists('VerialApiConfig')) {
                    return;
                }
            } catch (\Throwable $e) {
                // Si falla, continuar sin VerialApiConfig
            }
        }

        // Si llegamos aquí, VerialApiConfig no se pudo cargar
        // El código que llama a este método debe manejar el caso donde class_exists('VerialApiConfig') retorna false
    }
}
