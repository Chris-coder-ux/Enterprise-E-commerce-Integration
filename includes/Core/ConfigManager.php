<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

/**
 * Gestor centralizado de configuración para la integración
 */
class ConfigManager
{
    private const DEFAULTS = [
        // Los valores de tamaño de lote ahora son gestionados por BatchSizeHelper
        // Otros parámetros por defecto...
        
        // CONFIGURACIÓN CENTRALIZADA DE LOGGING MEJORADA
        'logging' => [
            'default_level' => 'info', // Se detectará automáticamente en getLoggingConfig()
            'enable_structured_logging' => true,
            'enable_performance_logging' => true, // Se detectará automáticamente en getLoggingConfig()
            'enable_error_logging' => true,
            'log_retention_days' => 30,
            'max_log_size_mb' => 100,
            'max_context_size' => 1000,        // Máximo tamaño de contexto en bytes
            'max_message_length' => 500,       // Máximo tamaño de mensaje
            'batch_logging' => true,           // Habilitar logging por lotes
            'async_logging' => false,          // Logging asíncrono (para operaciones críticas)
            'context_filtering' => true,       // Filtrado automático de contexto
            'sensitive_data_protection' => true, // Protección de datos sensibles
            'critical_operations_minimal' => true, // Logging mínimo para operaciones críticas
            'auto_context_optimization' => true, // Optimización automática de contexto
            'centralized_config' => true,      // Configuración centralizada
            'singleton_mode' => true,          // Modo singleton para evitar duplicación
            'auto_category_detection' => true, // Detección automática de categoría
            'unified_error_handling' => true,  // Manejo unificado de errores
            'performance_monitoring' => true,  // Monitoreo de rendimiento
            'categories' => [
                'ajax-sync' => ['level' => 'info', 'enabled' => true, 'max_file_size' => 10485760, 'retention_days' => 14],
                'rest-api-handler' => ['level' => 'info', 'enabled' => true, 'max_file_size' => 5242880, 'retention_days' => 7],
                'batch-processor' => ['level' => 'info', 'enabled' => true, 'max_file_size' => 10485760, 'retention_days' => 14],
                'sync-clientes' => ['level' => 'info', 'enabled' => true, 'max_file_size' => 5242880, 'retention_days' => 7],
                'config-manager' => ['level' => 'info', 'enabled' => true, 'max_file_size' => 2097152, 'retention_days' => 7],
                'assets' => ['level' => 'warning', 'enabled' => true, 'max_file_size' => 2097152, 'retention_days' => 3],
                'admin-menu' => ['level' => 'warning', 'enabled' => true, 'max_file_size' => 2097152, 'retention_days' => 3],
                'sync-manager' => ['level' => 'info', 'enabled' => true, 'max_file_size' => 10485760, 'retention_days' => 14],
                'api-connector' => ['level' => 'warning', 'enabled' => true, 'max_file_size' => 5242880, 'retention_days' => 7],
                'woocommerce-hooks' => ['level' => 'info', 'enabled' => true, 'max_file_size' => 5242880, 'retention_days' => 7],
                'endpoints' => ['level' => 'warning', 'enabled' => true, 'max_file_size' => 2097152, 'retention_days' => 3],
                'admin' => ['level' => 'info', 'enabled' => true, 'max_file_size' => 2097152, 'retention_days' => 7]
            ]
        ]
    ];

    private const VALIDATORS = [
        // Los validadores de tamaño de lote ahora son gestionados por BatchSizeHelper
        // Otros validadores...
        
        // VALIDADORES PARA CONFIGURACIÓN DE LOGGING MEJORADA
        'logging' => [
            'default_level' => 'string',
            'enable_structured_logging' => 'bool',
            'enable_performance_logging' => 'bool',
            'enable_error_logging' => 'bool',
            'log_retention_days' => 'int',
            'max_log_size_mb' => 'int',
            'max_context_size' => 'int',
            'max_message_length' => 'int',
            'batch_logging' => 'bool',
            'async_logging' => 'bool',
            'context_filtering' => 'bool',
            'sensitive_data_protection' => 'bool',
            'critical_operations_minimal' => 'bool',
            'auto_context_optimization' => 'bool',
            'centralized_config' => 'bool',
            'singleton_mode' => 'bool',
            'auto_category_detection' => 'bool',
            'unified_error_handling' => 'bool',
            'performance_monitoring' => 'bool',
            'categories' => 'array'
        ]
    ];

    /**
     * FASE 2: PATRÓN SINGLETON UNIFICADO Y CONSISTENTE
     * Aplica buenas prácticas: DRY, Single Responsibility, Consistency
     */
    private static ?ConfigManager $instance = null;
    private static bool $initialized = false;

    /**
     * Constructor privado para implementar Singleton pattern
     * Aplica buenas prácticas: Single Responsibility, Fail Fast
     */
    private function __construct() {
        // FASE 2: Inicialización consistente del Singleton
        if (self::$initialized) {
            throw new \RuntimeException('ConfigManager ya ha sido inicializado');
        }
        
        // Marcar como inicializado ANTES de continuar
        self::$initialized = true;
    }

    /**
     * Obtiene la instancia única del ConfigManager
     * Aplica buenas prácticas: DRY, Consistency, Resource Management
     * 
     * @return self Instancia única del ConfigManager
     * @throws \RuntimeException Si hay error durante la inicialización
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            try {
                self::$instance = new self();
            } catch (\Throwable $e) {
                // FASE 2: Fail Fast con logging de error
                error_log("Error al crear instancia de ConfigManager: " . $e->getMessage());
                throw new \RuntimeException('Error al inicializar ConfigManager', 0, $e);
            }
        }
        
        return self::$instance;
    }

    /**
     * Verifica si la instancia está inicializada
     * Aplica buenas prácticas: Fail Fast, Monitoring
     * 
     * @return bool True si está inicializado
     */
    public static function isInitialized(): bool
    {
        return self::$initialized && self::$instance !== null;
    }

    /**
     * Reinicia la instancia (útil para testing)
     * Aplica buenas prácticas: Testing, Resource Management
     * 
     * @return bool True si se reinició correctamente
     */
    public static function resetInstance(): bool
    {
        if (self::$initialized) {
            self::$instance = null;
            self::$initialized = false;
            return true;
        }
        
        return false;
    }

    /**
     * Obtiene la instancia del logger (DRY Principle)
     */
    private function getLogger(): ?\MiIntegracionApi\Logging\Interfaces\ILogger
    {
        // Usar el nuevo sistema de LogManager
        if (class_exists('\MiIntegracionApi\Logging\Core\LogManager')) {
            try {
                $logManager = \MiIntegracionApi\Logging\Core\LogManager::getInstance();
                return $logManager->getLogger('config-manager');
            } catch (\Throwable $e) {
                error_log('Error obteniendo logger en ConfigManager: ' . $e->getMessage());
                return null;
            }
        }
        
        // Fallback: crear logger local
        if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
            return new \MiIntegracionApi\Helpers\Logger('config-manager');
        }
        
        return null;
    }

    /**
     * Obtiene un parámetro de configuración validado
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $option = get_option('mi_integracion_api_' . $key, self::DEFAULTS[$key] ?? $default);
        return $this->validate($key, $option);
    }

    /**
     * Obtiene el batch size para una entidad
     *
     * Delega la responsabilidad a BatchSizeHelper, que es la fuente única de verdad
     * para el tamaño de lote.
     *
     * @param string $entity
     * @return int
     */
    public function getBatchSize(string $entity): int
    {
        // Agregar log para depuración
        if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
            $logger = $this->getLogger();
            $logger->debug("ConfigManager::getBatchSize delegando a BatchSizeHelper para entidad '{$entity}'");
        }
        
        // Delegar a BatchSizeHelper
        return \MiIntegracionApi\Helpers\BatchSizeHelper::getBatchSize($entity);
    }

    /**
     * Establece el batch size para una entidad
     *
     * Delega la responsabilidad a BatchSizeHelper, que es la fuente única de verdad
     * para el tamaño de lote.
     *
     * @param string $entity
     * @param int $batch_size
     * @return bool
     */
    public function setBatchSize(string $entity, int $batch_size): bool
    {
        // Agregar log para depuración
        if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
            $logger = $this->getLogger();
            $logger->debug("ConfigManager::setBatchSize delegando a BatchSizeHelper para entidad '{$entity}'", [
                'batch_size' => $batch_size
            ]);
        }
        
        // Delegar a BatchSizeHelper
        return \MiIntegracionApi\Helpers\BatchSizeHelper::setBatchSize($entity, $batch_size);
    }
    
    /**
     * REFACTORIZADO: Configuración centralizada de logging
     * Implementa Configuration over Convention y Monitoring
     */
    
    /**
     * Obtiene la configuración de logging con detección inteligente
     * 
     * @param string|null $category Categoría específica
     * @return array Configuración de logging
     */
    public function getLoggingConfig(?string $category = null): array
    {
        $loggingConfig = $this->get('logging', self::DEFAULTS['logging']);
        
        // Aplicar detección inteligente en tiempo de ejecución
        $loggingConfig['default_level'] = self::getIntelligentLogLevel();
        $loggingConfig['enable_performance_logging'] = self::isDevelopmentEnvironment();
        
        // Aplicar niveles inteligentes a las categorías
        foreach ($loggingConfig['categories'] as $cat => &$config) {
            if ($config['level'] === 'info') {
                $config['level'] = self::getIntelligentLogLevel();
            }
        }
        
        if ($category && isset($loggingConfig['categories'][$category])) {
            return array_merge($loggingConfig, $loggingConfig['categories'][$category]);
        }
        
        return $loggingConfig;
    }

    /**
     * Obtiene configuración específica para una categoría con detección automática de patrones
     * 
     * @param string $category Categoría del logger
     * @return array Configuración de la categoría
     */
    public function getCategoryLoggingConfig(string $category): array
    {
        $loggingConfig = $this->get('logging', self::DEFAULTS['logging']);
        
        // Si existe configuración específica, usarla
        if (isset($loggingConfig['categories'][$category])) {
            return $loggingConfig['categories'][$category];
        }
        
        // Detección automática por patrones
        return $this->getDefaultCategoryConfig($category);
    }

    /**
     * Obtiene configuración por defecto para una categoría basada en patrones
     * 
     * @param string $category Categoría del logger
     * @return array Configuración por defecto
     */
    private function getDefaultCategoryConfig(string $category): array
    {
        // Patrones de categorías y sus configuraciones por defecto
        $patterns = [
            'sync-*' => [
                'level' => 'info',
                'enabled' => true,
                'max_file_size' => 10485760, // 10MB
                'retention_days' => 14,
                'performance_logging' => true,
                'detailed_context' => true,
            ],
            'api-*' => [
                'level' => 'warning',
                'enabled' => true,
                'max_file_size' => 5242880, // 5MB
                'retention_days' => 7,
                'performance_logging' => false,
                'detailed_context' => false,
            ],
            'woocommerce-*' => [
                'level' => 'info',
                'enabled' => true,
                'max_file_size' => 5242880, // 5MB
                'retention_days' => 7,
                'performance_logging' => false,
                'detailed_context' => false,
            ],
            'endpoint*' => [
                'level' => 'warning',
                'enabled' => true,
                'max_file_size' => 2097152, // 2MB
                'retention_days' => 3,
                'performance_logging' => false,
                'detailed_context' => false,
            ],
            'admin*' => [
                'level' => 'info',
                'enabled' => true,
                'max_file_size' => 2097152, // 2MB
                'retention_days' => 7,
                'performance_logging' => false,
                'detailed_context' => false,
            ],
        ];

        // Buscar patrón coincidente
        foreach ($patterns as $pattern => $config) {
            if (fnmatch($pattern, $category)) {
                return $config;
            }
        }

        // Configuración por defecto genérica
        return [
            'level' => 'info',
            'enabled' => true,
            'max_file_size' => 2097152, // 2MB
            'retention_days' => 7,
            'performance_logging' => false,
            'detailed_context' => false,
        ];
    }
    
    /**
     * Obtiene el nivel de log para una categoría
     * 
     * @param string $category Categoría del logger
     * @return string Nivel de log
     */
    public function getLogLevel(string $category): string
    {
        $config = $this->getLoggingConfig($category);
        return $config['default_level'] ?? 'info';
    }
    
    /**
     * Verifica si el logging está habilitado para una categoría
     * 
     * @param string $category Categoría del logger
     * @return bool True si habilitado
     */
    public function isLoggingEnabled(string $category): bool
    {
        $config = $this->getLoggingConfig($category);
        return $config['categories'][$category]['enabled'] ?? true;
    }
    
    /**
     * Verifica si el logging estructurado está habilitado
     * 
     * @return bool True si habilitado
     */
    public function isStructuredLoggingEnabled(): bool
    {
        $config = $this->get('logging', self::DEFAULTS['logging']);
        return $config['enable_structured_logging'] ?? true;
    }
    
    /**
     * Verifica si el logging de rendimiento está habilitado
     * 
     * @return bool True si habilitado
     */
    public function isPerformanceLoggingEnabled(): bool
    {
        $config = $this->get('logging', self::DEFAULTS['logging']);
        return $config['enable_performance_logging'] ?? true;
    }
    
    /**
     * Actualiza la configuración de logging
     * 
     * @param array $config Nueva configuración
     * @return bool True si se actualizó correctamente
     */
    public function updateLoggingConfig(array $config): bool
    {
        $currentConfig = $this->get('logging', self::DEFAULTS['logging']);
        $newConfig = array_merge($currentConfig, $config);
        
        // Validar configuración
        if (!$this->validateLoggingConfig($newConfig)) {
            return false;
        }
        
        // Actualizar configuración
        $result = update_option('mi_integracion_api_logging', $newConfig);
        
        if ($result) {
            $this->getLogger()->info('Configuración de logging actualizada', [
                'config' => $newConfig
            ]);
        }
        
        return $result;
    }

    /**
     * Obtiene configuración de optimización de logging
     * 
     * @return array Configuración de optimización
     */
    public function getLoggingOptimizationConfig(): array
    {
        $loggingConfig = $this->get('logging', self::DEFAULTS['logging']);
        
        return [
            'max_context_size' => $loggingConfig['max_context_size'] ?? 1000,
            'max_message_length' => $loggingConfig['max_message_length'] ?? 500,
            'batch_logging' => $loggingConfig['batch_logging'] ?? true,
            'async_logging' => $loggingConfig['async_logging'] ?? false,
            'context_filtering' => $loggingConfig['context_filtering'] ?? true,
            'sensitive_data_protection' => $loggingConfig['sensitive_data_protection'] ?? true,
            'critical_operations_minimal' => $loggingConfig['critical_operations_minimal'] ?? true,
            'auto_context_optimization' => $loggingConfig['auto_context_optimization'] ?? true,
        ];
    }

    /**
     * Obtiene configuración de unificación de logging
     * 
     * @return array Configuración de unificación
     */
    public function getLoggingUnificationConfig(): array
    {
        $loggingConfig = $this->get('logging', self::DEFAULTS['logging']);
        
        return [
            'centralized_config' => $loggingConfig['centralized_config'] ?? true,
            'singleton_mode' => $loggingConfig['singleton_mode'] ?? true,
            'auto_category_detection' => $loggingConfig['auto_category_detection'] ?? true,
            'unified_error_handling' => $loggingConfig['unified_error_handling'] ?? true,
            'performance_monitoring' => $loggingConfig['performance_monitoring'] ?? true,
        ];
    }

    /**
     * Obtiene estadísticas de categorías de logging
     * 
     * @return array Estadísticas de categorías
     */
    public function getLoggingCategoryStats(): array
    {
        $loggingConfig = $this->get('logging', self::DEFAULTS['logging']);
        $categories = $loggingConfig['categories'] ?? [];
        
        $stats = [
            'total_configured' => count($categories),
            'by_pattern' => [],
            'enabled_count' => 0,
            'disabled_count' => 0,
        ];

        // Contar por patrones
        $patterns = ['sync-*', 'api-*', 'woocommerce-*', 'endpoint*', 'admin*'];
        foreach ($patterns as $pattern) {
            $count = 0;
            foreach (array_keys($categories) as $category) {
                if (fnmatch($pattern, $category)) {
                    $count++;
                }
            }
            $stats['by_pattern'][$pattern] = $count;
        }

        // Contar habilitadas/deshabilitadas
        foreach ($categories as $config) {
            if ($config['enabled'] ?? true) {
                $stats['enabled_count']++;
            } else {
                $stats['disabled_count']++;
            }
        }

        return $stats;
    }
    
    /**
     * Valida la configuración de logging
     * 
     * @param array $config Configuración a validar
     * @return bool True si válida
     */
    private function validateLoggingConfig(array $config): bool
    {
        $requiredKeys = ['default_level', 'enable_structured_logging', 'enable_performance_logging', 'enable_error_logging'];
        
        foreach ($requiredKeys as $key) {
            if (!isset($config[$key])) {
                return false;
            }
        }
        
        // Validar nivel de log
        $validLevels = ['debug', 'info', 'warning', 'error', 'critical'];
        if (!in_array($config['default_level'], $validLevels)) {
            return false;
        }
        
        // Validar valores booleanos
        $booleanKeys = ['enable_structured_logging', 'enable_performance_logging', 'enable_error_logging'];
        foreach ($booleanKeys as $key) {
            if (!is_bool($config[$key])) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Detecta inteligentemente el nivel de log apropiado
     * 
     * @return string Nivel de log recomendado
     */
    private static function getIntelligentLogLevel(): string
    {
        // En desarrollo: más verboso
        if (self::isDevelopmentEnvironment()) {
            return 'debug';
        }
        
        // En producción: menos verboso
        return 'info';
    }
    
    /**
     * Detecta si estamos en un entorno de desarrollo
     * 
     * @return bool True si es desarrollo
     */
    private static function isDevelopmentEnvironment(): bool
    {
        // Verificar constantes de WordPress
        if (defined('WP_DEBUG') && constant('WP_DEBUG')) {
            return true;
        }
        
        // Verificar si estamos en localhost
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (in_array($host, ['localhost', '127.0.0.1', '::1'])) {
            return true;
        }
        
        // Verificar si la URL contiene indicadores de desarrollo
        $url = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($url, '/wp-content/plugins/') !== false && strpos($url, 'localhost') !== false) {
            return true;
        }
        
        // Verificar si hay archivos de desarrollo presentes
        if (file_exists(ABSPATH . 'wp-config-local.php') || 
            file_exists(ABSPATH . '.env.local') ||
            file_exists(ABSPATH . 'wp-content/debug.log')) {
            return true;
        }
        
        return false;
    }

    /**
     * Valida un parámetro según las reglas
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    private function validate(string $key, $value)
    {
        if (!isset(self::VALIDATORS[$key])) {
            return $value;
        }
        $rules = self::VALIDATORS[$key];
        if ($rules['type'] === 'int') {
            $value = (int) $value;
            if (isset($rules['min']) && $value < $rules['min']) {
                $value = $rules['min'];
            }
            if (isset($rules['max']) && $value > $rules['max']) {
                $value = $rules['max'];
            }
        }
        // Otros tipos y validaciones...
        return $value;
    }

    /**
     * Permite actualizar un parámetro de configuración validado
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function set(string $key, $value): bool
    {
        $value = $this->validate($key, $value);
        return update_option('mi_integracion_api_' . $key, $value, true);
    }
}