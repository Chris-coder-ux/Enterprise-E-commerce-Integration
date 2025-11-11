<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

/**
 * Gestor central de configuración del sistema de reintentos
 *
 * Esta clase implementa un gestor centralizado para la configuración del sistema de reintentos,
 * siguiendo el patrón Singleton. Proporciona un punto único de acceso para obtener configuraciones,
 * estrategias y políticas de reintento, asegurando consistencia en todo el sistema.
 *
 * CARACTERÍSTICAS PRINCIPALES:
 * - Patrón Singleton para acceso global
 * - Configuración jerárquica mediante CascadingRetryConfig
 * - Fábrica de estrategias de reintento
 * - Gestión centralizada de políticas
 * - Sistema de logging integrado
 *
 * @package    MiIntegracionApi\Core
 * @subpackage Retry
 * @category   Configuration
 * @author     Equipo de Desarrollo <soporte@verialerp.com>
 * @license    GPL-2.0+ http://www.gnu.org/licenses/gpl-2.0.txt
 * @link       https://www.verialerp.com
 * @since      1.6.0
 * @version    1.6.0
 */
final class RetryConfigurationManager
{
    /**
     * Instancia del logger para registrar eventos del sistema
     *
     * @var Logger
     * @since 1.6.0
     */
    private Logger $logger;

    /**
     * Configuración jerárquica de reintentos
     *
     * @var CascadingRetryConfig
     * @since 1.6.0
     */
    private CascadingRetryConfig $cascadingConfig;

    /**
     * Fábrica de estrategias de reintento
     *
     * @var RetryStrategyFactory
     * @since 1.6.0
     */
    private RetryStrategyFactory $strategyFactory;

    /**
     * Gestor de políticas de reintento
     *
     * @var RetryPolicyManager
     * @since 1.6.0
     */
    private RetryPolicyManager $policyManager;
    
    /**
     * Instancia única de la clase (patrón Singleton)
     *
     * @var self|null
     * @since 1.6.0
     */
    private static ?self $instance = null;
    
    /**
     * Bandera de inicialización del sistema
     *
     * @var bool
     * @since 1.6.0
     */
    private static bool $initialized = false;
    
    /**
     * Constructor privado para implementar el patrón Singleton
     *
     * Inicializa las dependencias principales del sistema de reintentos.
     * Este método es privado para forzar el uso de getInstance().
     *
     * @throws \RuntimeException Si alguna dependencia no está disponible
     * @since 1.6.0
     */
    private function __construct()
    {
        $this->logger = new Logger('retry-config-manager');
        $this->cascadingConfig = new CascadingRetryConfig();
        $this->strategyFactory = RetryStrategyFactory::getInstance();
        $this->policyManager = new RetryPolicyManager();
        
        $this->logger->info('RetryConfigurationManager inicializado');
    }
    
    /**
     * Obtiene la instancia única del gestor de configuración
     *
     * Implementa el patrón Singleton para garantizar una única instancia en toda la aplicación.
     * Si la instancia no existe, la crea y la inicializa con todas sus dependencias.
     *
     * @static
     * @return self Instancia única de RetryConfigurationManager
     * @throws \RuntimeException Si ocurre un error durante la creación de la instancia
     * @since 1.6.0
     *
     * @example
     * // Obtener la instancia del gestor de configuración
     * $configManager = RetryConfigurationManager::getInstance();
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            try {
                self::$instance = new self();
            } catch (\Throwable $e) {
                // FASE 2: Fail Fast con logging de error
                error_log("Error al crear instancia de RetryConfigurationManager: " . $e->getMessage());
                throw new \RuntimeException('Error al inicializar RetryConfigurationManager', 0, $e);
            }
        }
        
        return self::$instance;
    }

    /**
     * Verifica si el sistema de reintentos está inicializado
     *
     * Este método comprueba tanto la bandera de inicialización como la existencia
     * de una instancia válida del gestor de configuración.
     *
     * @static
     * @return bool true si el sistema está correctamente inicializado, false en caso contrario
     * @since 1.6.0
     *
     * @example
     * // Verificar si el sistema está listo para su uso
     * if (RetryConfigurationManager::isInitialized()) {
     *     // Realizar operaciones que requieren el sistema de reintentos
     * }
     */
    public static function isInitialized(): bool
    {
        return self::$initialized && self::$instance !== null;
    }

    /**
     * Reinicia la instancia del gestor de configuración
     *
     * Este método es principalmente útil para propósitos de prueba, permitiendo
     * reiniciar el estado del Singleton entre diferentes casos de prueba.
     *
     * @static
     * @return bool true si la instancia se reinició correctamente, false si no estaba inicializada
     * @since 1.6.0
     *
     * @example
     * // En una prueba unitaria
     * public function testSomething() {
     *     // Configurar el estado inicial
     *     // ...
     *     
     *     // Ejecutar la prueba
     *     // ...
     *     
     *     // Limpiar para la siguiente prueba
     *     RetryConfigurationManager::resetInstance();
     * }
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
     * Inicializa el sistema de configuración de reintentos
     *
     * Realiza la inicialización completa del sistema, incluyendo la validación
     * de componentes y la configuración inicial. Este método debe llamarse
     * antes de usar cualquier otra funcionalidad de la clase.
     *
     * @static
     * @return bool true si la inicialización fue exitosa, false si ya estaba inicializado
     * @throws \RuntimeException Si ocurre un error durante la inicialización
     * @since 1.6.0
     *
     * @example
     * // Inicializar el sistema de reintentos
     * try {
     *     $initialized = RetryConfigurationManager::initialize();
     *     if ($initialized) {
     *         echo 'Sistema de reintentos inicializado correctamente';
     *     }
     * } catch (\RuntimeException $e) {
     *     echo 'Error al inicializar el sistema de reintentos: ' . $e->getMessage();
     * }
     */
    public static function initialize(): bool
    {
        if (self::$initialized) {
            return false;
        }
        
        try {
            $instance = self::getInstance();
            $instance->logger->info('Inicializando sistema de configuración de reintentos');
            
            // Verificar que todos los componentes estén disponibles
            $instance->validateComponents();
            
            self::$initialized = true;
            $instance->logger->info('Sistema de configuración de reintentos inicializado correctamente');
            
            do_action('mi_integracion_api_retry_config_initialized');
            
            return true;
        } catch (\Throwable $e) {
            self::$initialized = false;
            if (isset($instance)) {
                $instance->logger->error('Error durante la inicialización del sistema de reintentos', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            throw $e;
        }
    }
    
    /**
     * Valida que todos los componentes necesarios estén disponibles
     *
     * Este método verifica que todas las clases e interfaces requeridas por el sistema
     * de reintentos estén disponibles. Si falta algún componente, registra un error
     * y lanza una excepción.
     *
     * @return void
     * @throws \RuntimeException Si falta algún componente requerido
     * @since 1.6.0
     * @access private
     */
    private function validateComponents(): void
    {
        $requiredClasses = [
            'MiIntegracionApi\Core\RetryStrategyFactory',
            'MiIntegracionApi\Core\CascadingRetryConfig',
            'MiIntegracionApi\Core\RetryPolicyManager'
        ];
        
        foreach ($requiredClasses as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException("Clase requerida no encontrada: {$class}");
            }
        }
        
        $this->logger->debug('Todos los componentes del sistema de reintentos están disponibles');
    }
    
    /**
     * Obtiene la configuración de reintentos para una operación específica
     *
     * Este método devuelve la configuración de reintentos aplicable para el tipo de operación
     * y error especificados, aplicando la jerarquía de configuración definida.
     * Si el sistema no está inicializado, devuelve la configuración por defecto.
     *
     * @param string $operationType Tipo de operación (ej: 'api_call', 'database_operation')
     * @param string|null $errorType Tipo de error (opcional, ej: 'timeout', 'connection_error')
     * @param array $context Contexto adicional para la búsqueda de configuración
     * @return array Configuración de reintentos con las siguientes claves:
     *               - max_attempts: Número máximo de intentos
     *               - base_delay: Retardo base en segundos
     *               - backoff_factor: Factor de incremento del retardo
     *               - max_delay: Retardo máximo en segundos
     *               - jitter_enabled: Si se aplica jitter al retardo
     * @since 1.6.0
     *
     * @example
     * // Obtener configuración para una operación de API con error de timeout
     * $config = $retryManager->getConfig('api_call', 'timeout');
     */
    public function getConfig(string $operationType, ?string $errorType = null, array $context = []): array
    {
        if (!self::$initialized) {
            $this->logger->warning('Sistema de reintentos no inicializado, usando configuración por defecto');
            return $this->getDefaultConfig();
        }
        
        try {
            $config = $this->cascadingConfig->getConfig($operationType, $errorType, $context);
            
            $this->logger->debug("Configuración obtenida para '{$operationType}'", [
                'operation_type' => $operationType,
                'error_type' => $errorType,
                'config' => $config
            ]);
            
            return $config;
        } catch (\Throwable $e) {
            $this->logger->error("Error al obtener configuración para '{$operationType}'", [
                'error' => $e->getMessage(),
                'operation_type' => $operationType,
                'error_type' => $errorType
            ]);
            
            return $this->getDefaultConfig();
        }
    }
    
    /**
     * Obtiene una estrategia de reintento por su tipo
     *
     * @param string $type Tipo de estrategia a obtener (ej: 'exponential', 'fixed', 'linear')
     * @return RetryStrategyInterface Instancia de la estrategia solicitada
     * @throws \RuntimeException Si el sistema no está inicializado
     * @throws \InvalidArgumentException Si el tipo de estrategia no existe
     * @since 1.6.0
     *
     * @example
     * // Obtener una estrategia de reintento exponencial
     * $strategy = $retryManager->getStrategy('exponential');
     */
    public function getStrategy(string $type): RetryStrategyInterface
    {
        if (!self::$initialized) {
            throw new \RuntimeException('Sistema de reintentos no inicializado');
        }
        
        return $this->strategyFactory->getStrategy($type);
    }
    
    /**
     * Verifica si existe una estrategia para el tipo especificado
     *
     * @param string $type Tipo de estrategia a verificar
     * @return bool true si la estrategia existe, false en caso contrario o si el sistema no está inicializado
     * @since 1.6.0
     *
     * @example
     * // Verificar si existe una estrategia específica
     * if ($retryManager->hasStrategy('exponential')) {
     *     // La estrategia existe
     * }
     */
    public function hasStrategy(string $type): bool
    {
        if (!self::$initialized) {
            return false;
        }
        
        return $this->strategyFactory->hasStrategy($type);
    }
    
    /**
     * Obtiene la configuración por defecto para los reintentos
     *
     * Esta configuración se utiliza cuando el sistema no está inicializado o cuando
     * no se encuentra una configuración específica para una operación/error.
     *
     * @return array Configuración por defecto con los siguientes elementos:
     *               - max_attempts: Número máximo de intentos (3)
     *               - base_delay: Retardo base en segundos (2.0)
     *               - backoff_factor: Factor de incremento del retardo (2.0)
     *               - max_delay: Retardo máximo en segundos (30.0)
     *               - jitter_enabled: Si se aplica jitter al retardo (true)
     *               - description: Descripción de la configuración
     *               - priority: Prioridad de la configuración ('medium')
     *               - source: Origen de la configuración ('default')
     * @since 1.6.0
     */
    public function getDefaultConfig(): array
    {
        return [
            'max_attempts' => 3,
            'base_delay' => 2.0,
            'backoff_factor' => 2.0,
            'max_delay' => 30.0,
            'jitter_enabled' => true,
            'description' => 'Configuración por defecto del sistema',
            'priority' => 'medium',
            'source' => 'default'
        ];
    }
    
    /**
     * Obtiene estadísticas detalladas del sistema de reintentos
     *
     * Este método proporciona información detallada sobre el estado actual del sistema,
     * incluyendo estadísticas de estrategias, uso de memoria y estado general.
     *
     * @return array Array asociativo con las siguientes claves:
     *               - status: Estado del sistema ('initialized', 'not_initialized', 'error')
     *               - strategies: Estadísticas de las estrategias disponibles
     *               - cascade: Estadísticas del sistema de configuración en cascada
     *               - timestamp: Marca de tiempo de la generación del informe
     *               - memory_usage: Uso actual de memoria en bytes
     *               - peak_memory: Uso máximo de memoria en bytes
     * @since 1.6.0
     *
     * @example
     * // Obtener estadísticas del sistema
     * $stats = $retryManager->getStats();
     * echo 'Uso de memoria: ' . $stats['memory_usage'] . ' bytes';
     */
    public function getStats(): array
    {
        if (!self::$initialized) {
            return ['status' => 'not_initialized'];
        }
        
        try {
            $strategyStats = $this->strategyFactory->getStrategiesStats();
            $cascadeStats = $this->cascadingConfig->getStats();
            
            return [
                'status' => 'initialized',
                'strategies' => $strategyStats,
                'cascade' => $cascadeStats,
                'timestamp' => time(),
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true)
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Error al obtener estadísticas del sistema', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtiene todas las estrategias de reintento disponibles
     *
     * @return array Lista de nombres de estrategias disponibles
     * @since 1.6.0
     *
     * @example
     * // Obtener todas las estrategias disponibles
     * $strategies = $retryManager->getAllStrategies();
     * // Ejemplo de retorno: ['exponential', 'fixed', 'linear']
     */
    public function getAllStrategies(): array
    {
        if (!self::$initialized) {
            return [];
        }
        
        return $this->strategyFactory->getAllStrategies();
    }
    
    /**
     * Obtiene los tipos de estrategias de reintento disponibles
     *
     * Este método devuelve un array con todos los tipos de estrategias de reintento
     * que están registradas en el sistema. Cada tipo puede ser utilizado con getStrategy().
     *
     * @return array Lista de tipos de estrategias disponibles (ej: ['exponential', 'fixed', 'linear'])
     * @since 1.6.0
     *
     * @example
     * // Obtener todos los tipos de estrategias disponibles
     * $tipos = $retryManager->getAvailableTypes();
     * // Ejemplo de retorno: ['exponential', 'fixed', 'linear']
     */
    public function getAvailableTypes(): array
    {
        if (!self::$initialized) {
            return [];
        }
        
        return $this->strategyFactory->getAvailableTypes();
    }
    
    /**
     * Obtiene estrategias de reintento filtradas por prioridad
     *
     * Este método devuelve un array con las estrategias que tienen la prioridad especificada.
     * Las prioridades disponibles son: 'low', 'medium', 'high', 'critical'.
     *
     * @param string $priority Nivel de prioridad a filtrar ('low', 'medium', 'high', 'critical')
     * @return array Lista de estrategias que coinciden con la prioridad especificada
     * @throws \InvalidArgumentException Si la prioridad especificada no es válida
     * @since 1.6.0
     *
     * @example
     * // Obtener todas las estrategias de alta prioridad
     * $estrategiasAltas = $retryManager->getStrategiesByPriority('high');
     */
    public function getStrategiesByPriority(string $priority): array
    {
        if (!self::$initialized) {
            return [];
        }
        
        return $this->strategyFactory->getStrategiesByPriority($priority);
    }
    
    /**
     * Resetea completamente el sistema de reintentos
     *
     * Este método es principalmente útil para propósitos de prueba, permitiendo
     * restaurar el sistema a su estado inicial. Reinicia todas las instancias internas
     * y el estado del Singleton.
     *
     * ADVERTENCIA: Este método no debe usarse en producción ya que puede afectar
     * a todas las instancias que estén utilizando el sistema de reintentos.
     *
     * @static
     * @return void
     * @since 1.6.0
     *
     * @example
     * // En una prueba unitaria
     * public function testAlgo() {
     *     // Configurar el estado inicial
     *     // ...
     *     
     *     // Ejecutar la prueba
     *     // ...
     *     
     *     // Limpiar para la siguiente prueba
     *     RetryConfigurationManager::reset();
     * }
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$initialized = false;
    }
}
