<?php /** @noinspection Annotator */

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use Exception;
use InvalidArgumentException;
use MiIntegracionApi\CacheManager;
use MiIntegracionApi\Helpers\BatchSizeHelper;
use MiIntegracionApi\Helpers\IdGenerator;
use MiIntegracionApi\Helpers\IndexHelper;
use MiIntegracionApi\Logging\Core\LoggerBasic;
use MiIntegracionApi\Helpers\MapProduct;
use MiIntegracionApi\Helpers\SyncStatusHelper;
use MiIntegracionApi\Helpers\Utils;
use MiIntegracionApi\Helpers\WooCommerceHelper;
use MiIntegracionApi\Sync\WooCommerceLoader;
use MiIntegracionApi\Traits\ErrorHandler;
use MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory;
use MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface;
use MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes;
use MiIntegracionApi\Core\LogCleaner;
use Throwable;
use WC_Product;
use function mia_get_sync_transient;
use function mia_set_sync_transient;

/**
 * Procesador concreto para sincronización por lotes con funcionalidades avanzadas.
 *
 * Esta clase proporciona una infraestructura robusta para el procesamiento
 * de grandes volúmenes de datos en lotes optimizados, incluyendo:
 * - Sistema de configuración centralizada y dinámica de tamaños de lote
 * - Monitoreo en tiempo real de memoria y recursos del sistema
 * - Manejo unificado de errores con estrategias de recuperación automática
 * - Sistema de puntos de recuperación para reanudar procesos interrumpidos
 * - Ajuste dinámico de parámetros basado en condiciones del sistema
 * - Métricas detalladas y logging estructurado para monitoreo
 * - Limpieza inteligente de recursos entre lotes
 * - Soporte para transacciones y rollback automático
 * - Circuit breaker para prevenir cascadas de fallos
 * - Integración con sistemas de reintentos y cache
 * - Métodos de indexación para datos de referencia (categorías, fabricantes, etc.)
 * - Métodos auxiliares para obtención de datos por lotes de la API
 *
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 * @since 1.4.1 Agregado sistema de configuración centralizada
 * @since 2.0.0 Refactorizado con monitoreo en tiempo real y manejo avanzado de errores
 * @since 2.1.0 Convertido de abstract a clase concreta con implementación por defecto
 * @author Christian
 * @version 2.1.0
 * @uses Logger Para logging unificado del sistema
 * @uses ApiConnector Para comunicación con APIs externas
 * @example
 * ```php
 * $processor = new BatchProcessor($apiConnector);
 * // Uso directo con implementación por defecto
 * $result = $processor->process($items, $callback, 100);
 * // O uso con datos preparados para productos
 * $result = $processor->processProductsWithPreparedBatch(1, 100, $callback);
 * ```
 */
class BatchProcessor
{
    use ErrorHandler;
    /**
     * ID del batch actual para agregar a los productos
     * @var string|null
     */
    protected ?string $currentBatchId = null;
    
    /**
     * Sistema de limpieza de logs
     * @var LogCleaner|null
     */
    private ?LogCleaner $logCleaner = null;
    
    /**
     * Instancia del sistema de logging unificado.
     * Proporciona logging estructurado con diferentes niveles de severidad
     * y contexto detallado para monitoreo, debugging y auditoría del
     * procesamiento por lotes.
     * @var LoggerBasic
     * @since 1.0.0
     */
    protected LoggerBasic $logger;
    
    /**
     * Obtiene la instancia del logger del sistema.
     * Método de acceso centralizado al logger siguiendo el principio DRY.
     * Inicializa el logger de forma lazy si no está disponible, garantizando
     * que siempre haya una instancia válida para logging.
     * @return LoggerBasic Instancia del logger del sistema
     * @since 1.0.0
     */
    protected function getLogger(): LoggerBasic
    {
        if (!isset($this->logger)) {
            $this->logger = LoggerBasic::getInstance('batch-processor');
        }
        return $this->logger;
    }
    
    /**
     * Obtiene la instancia del limpiador de logs
     * @return LogCleaner
     */
    private function getLogCleaner(): LogCleaner
    {
        if ($this->logCleaner === null) {
            $this->logCleaner = new LogCleaner();
        }
        
        return $this->logCleaner;
    }
    
    /**
     * Intenta recuperación automática de errores
     * Implementa Graceful Degradation y Error Handling
     * @param string $errorType Tipo de error
     * @param array $errorInfo Información del error
     * @return array Resultado de la recuperación
     */
    protected function attemptErrorRecovery(string $errorType, array $errorInfo): array
    {
        // ✅ DELEGADO: Recovery tracking ahora en SyncMetrics
        $operationId = $this->currentBatchId ?? $this->generateConsistentBatchId(1);
        $syncMetrics = null; // Inicializar como null
        
        try {
            $syncMetrics = new SyncMetrics();
            $syncMetrics->recordError($operationId, 'recovery_attempt', ['message' => 'Intento de recuperación iniciado'], [
                'error_type' => $errorType,
                'component' => 'BatchProcessor'
            ]);
        } catch (Exception $e) {
            // ✅ REFACTORIZADO: Usar helper centralizado
            $this->logException($e, 'Error registrando métricas de recuperación', [
                'error_type' => $errorType
            ]);
            // Continuar sin métricas si falla
        }
        
        $this->getLogger()->info('Intentando recuperación automática de error', [
            'error_type' => $errorType,
            'error_info' => $errorInfo,
            'operation_id' => $operationId
        ]);
        
        // Determinar estrategia de recuperación basada en el tipo de error
        $recoveryStrategy = $this->determineRecoveryStrategy($errorType, $errorInfo);
        
        // ✅ LAZY INIT: Asegurar que el sistema esté inicializado
        $this->initializeErrorHandlingSystem();
        
        if ($recoveryStrategy && isset($this->errorRecoveryStrategies[$recoveryStrategy])) {
            try {
                // ✅ REFACTORIZADO: Llamada dinámica al método sin wrappers
                $methodName = $this->errorRecoveryStrategies[$recoveryStrategy];
                $result = $this->$methodName([
                    'error_type' => $errorType,
                    'error_info' => $errorInfo,
                    'timestamp' => time(),
                    'operation_id' => $operationId
                ]);
                
                if (isset($result['success']) && $result['success']) {
                    // ✅ DELEGADO: Recovery success en SyncMetrics
                    if ($syncMetrics !== null) {
                        try {
                            $syncMetrics->recordError($operationId, 'recovery_success', ['message' => 'Recuperación exitosa'], [
                                'strategy' => $recoveryStrategy,
                                'component' => 'BatchProcessor'
                            ]);
                        } catch (Exception $e) {
                            // ✅ REFACTORIZADO: Usar helper centralizado
                            $this->logException($e, 'Error registrando métricas de recuperación exitosa', [
                                'strategy' => $recoveryStrategy
                            ]);
                            // Continuar sin métricas si falla
                        }
                    }
                    
                    $this->getLogger()->info('Recuperación automática exitosa', [
                        'strategy' => $recoveryStrategy,
                        'result' => $result,
                        'operation_id' => $operationId
                    ]);
                }
                
                return [
                    'attempted' => true,
                    'successful' => $result['success'] ?? false,
                    'strategy' => $recoveryStrategy,
                    'result' => $result
                ];
                
            } catch (Throwable $e) {
                // ✅ REFACTORIZADO: Usar helper centralizado
                $this->logException($e, 'Error durante recuperación automática', [
                    'strategy' => $recoveryStrategy
                ]);
                
                return [
                    'attempted' => true,
                    'successful' => false,
                    'strategy' => $recoveryStrategy,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return [
            'attempted' => false,
            'successful' => false,
            'reason' => 'no_strategy_available'
        ];
    }
    
    /**
     * Determina la estrategia de recuperación apropiada
     * Implementa Error Handling y Graceful Degradation
     * @param string $errorType Tipo de error
     * @param array $errorInfo Información del error
     * @return string|null Estrategia de recuperación
     */
    protected function determineRecoveryStrategy(string $errorType, array $errorInfo): ?string
    {
        $severity = $errorInfo['severity'] ?? 'medium';

        return match ($errorType) {
            'api_error' => $severity === 'critical' ? 'graceful_degradation' : 'retry_with_backoff',
            'memory_error' => 'resource_cleanup',
            'validation_error' => null,
            default => 'retry_with_backoff',
        };
    }
    
    
    /**
     * Obtiene la configuración de reintentos del sistema unificado
     * @param string $operationType Tipo de operación
     * @return array Configuración de reintentos
     */
    protected function getRetryConfig(string $operationType = 'batch_operations'): array {
        if (class_exists('\\MiIntegracionApi\\Core\\RetryConfigurationManager') &&
            RetryConfigurationManager::isInitialized()) {
            return RetryConfigurationManager::getInstance()
                ->getConfig($operationType);
        }
        
        // Fallback a configuración por defecto
        return [
            'max_attempts' => 3,
            'base_delay' => 2.0,
            'backoff_factor' => 2.0,
            'max_delay' => 30.0,
            'jitter_enabled' => true
        ];
    }
    
    /**
     * Obtiene el número máximo de reintentos para la operación
     * @param string $operationType Tipo de operación
     * @return int Número máximo de reintentos
     */
    protected function getMaxRetries(string $operationType = 'batch_operations'): int {
        $config = $this->getRetryConfig($operationType);
        return $config['max_attempts'] ?? 3;
    }
    
    /**
     * Límite de memoria en MB para detener el procesamiento preventivamente.
     * @var int
     * @since 1.0.0
     */
    protected const MEMORY_LIMIT_MB = 256;

    /**
     * ✅ ELIMINADO: Sistema de transacciones delegado a TransactionManager
     * @deprecated 2.2.0 Use TransactionManager::getInstance() instead
     * @see TransactionManager Para manejo de transacciones
     */
    
    /**
     * Timeout máximo para procesamiento de lotes en segundos.
     * @var int
     * @since 1.0.0
     */
    protected const BATCH_TIMEOUT = 300; // 5 minutos

    /**
     * Tamaño mínimo permitido para los lotes.
     * @var int
     * @since 1.0.0
     */
    protected const MIN_BATCH_SIZE = 1;

    /**
     * Nombre de la entidad que se está procesando (productos, clientes, etc.).
     * @var string
     * @since 1.0.0
     */
    protected string $entityName = 'products';
    
    /**
     * Filtros aplicados en la sincronización actual.
     * @var array
     * @since 1.0.0
     */
    protected array $filters = [];
    
    /**
     * Estado de recuperación cargado desde puntos de checkpoint.
     * @var array
     * @since 1.0.0
     */
    protected array $recoveryState = [];
    
    /**
     * Indica si el procesamiento está reanudando desde un punto de recuperación.
     * @var bool
     * @since 1.0.0
     */
    protected bool $isResuming = false;
    
    /**
     * Contador de elementos procesados exitosamente.
     * @var int
     * @since 1.0.0
     */
    protected int $processedItems = 0;
    
    /**
     * Contador de errores encontrados durante el procesamiento.
     * @var int
     * @since 1.0.0
     */
    protected int $errorCount = 0;
    
    /**
     * Lista de elementos que fallaron durante el procesamiento.
     * @var array
     * @since 1.0.0
     */
    protected array $failedItems = [];
    
    /**
     * Timestamp de inicio del procesamiento para cálculo de duración.
     * @var float
     * @since 1.0.0
     */
    protected float $startTime;
    
    /**
     * Indica si el procesamiento ha sido cancelado externamente.
     * @var bool
     * @since 1.0.0
     */
    protected bool $isCancelled = false;
    
    // Implementa Error Handling, Monitoring y Graceful Degradation
    protected array $errorHandlers = [];
    protected array $errorRecoveryStrategies = [];
    

    /**
     * Siguiendo principios de diseño limpio, el constructor se enfoca únicamente
     * en la inyección de dependencias. La inicialización de sistemas complejos
     * se delega a métodos lazy que se ejecutan cuando son necesarios.
     * @param ApiConnector $apiConnector Conector API para comunicación externa
     * @since 1.0.0
     * @since 2.1.0 Simplificado para seguir Single Responsibility Principle
     */
    public function __construct(
        protected readonly ApiConnector $apiConnector
    ) {
        // ✅ PRINCIPIO: Constructor solo para inyección de dependencias
        // startTime se inicializa cuando comience el procesamiento real
        // Error handling se inicializa lazy cuando sea necesario
    }
    
    /**
     * Se ejecuta automáticamente la primera vez que se necesita el sistema
     * de error handling, siguiendo el principio de inicialización lazy.
     * @return void
     * @since 2.1.0 Convertido a lazy initialization
     */
    private function initializeErrorHandlingSystem(): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }
        
        $this->registerDefaultRecoveryStrategies();
        
        $initialized = true;
        
        if ($this->getLogger() !== null) {
            $this->getLogger()->debug('Sistema de error handling inicializado (lazy)', [
                'strategies_count' => count($this->errorRecoveryStrategies),
                'component' => 'BatchProcessor'
            ]);
        }
    }
    
    /**
     * Elimina indirección innecesaria. Las estrategias ahora apuntan directamente
     * a los nombres de métodos, siguiendo el principio DRY.
     */
    private function registerDefaultRecoveryStrategies(): void
    {
        // ✅ SIMPLIFICADO: Mapeo directo método => estrategia
        $this->errorRecoveryStrategies = [
            'retry_with_backoff' => 'executeRetryWithBackoff',
            'graceful_degradation' => 'executeGracefulDegradation',
            'state_recovery' => 'executeStateRecovery',
            'resource_cleanup' => 'executeResourceCleanup'
        ];
    }
    
    /**
     * Ejecuta estrategia de reintento con backoff exponencial
     * Implementa Performance Optimization y Graceful Degradation
     * @param array $context Contexto de la operación
     * @return array Resultado de la estrategia
     */
    protected function executeRetryWithBackoff(array $context = []): array
    {
        // Configuración centralizada
        $maxAttempts = $context['max_attempts'] ?? get_option('mia_retry_max_attempts', 3);
        $baseDelay = $context['base_delay'] ?? get_option('mia_retry_base_delay', 1.0);
        $currentAttempt = $context['current_attempt'] ?? 1;
        
        if ($currentAttempt > $maxAttempts) {
            return [
                'success' => false,
                'reason' => 'max_attempts_exceeded',
                'attempts_made' => $currentAttempt - 1
            ];
        }
        
        // Factor de backoff configurable
        $backoffFactor = get_option('mia_retry_backoff_factor', 2);
        $delay = $baseDelay * pow($backoffFactor, $currentAttempt - 1);
        
        // Jitter percentage configurable
        $jitterPercent = get_option('mia_retry_jitter_percent', 0.1);
        $jitter = $delay * $jitterPercent * (mt_rand() / mt_getrandmax());
        $finalDelay = $delay + $jitter;
        
        $this->getLogger()->info('Ejecutando reintento con backoff exponencial', [
            'attempt' => $currentAttempt,
            'delay' => $finalDelay,
            'context' => $context
        ]);
        
        // Simular delay (en producción esto sería sleep o similar)
        usleep((int) ($finalDelay * 1000000));
        
        return [
            'success' => true,
            'delay_applied' => $finalDelay,
            'next_attempt' => $currentAttempt + 1
        ];
    }
    
    /**
     * Ejecuta estrategia de degradación gradual
     * Implementa Graceful Degradation y Performance Optimization
     * @param array $context Contexto de la operación
     * @return array Resultado de la estrategia
     */
    protected function executeGracefulDegradation(array $context = []): array
    {
        $degradationLevel = $context['level'] ?? get_option('mia_default_degradation_level', 'moderate');
        
        $degradationConfig = $this->getDegradationConfiguration();
        
        if (!isset($degradationConfig[$degradationLevel])) {
            $this->getLogger()->warning('Nivel de degradación no válido, usando nivel por defecto', [
                'invalid_level' => $degradationLevel,
                'available_levels' => array_keys($degradationConfig)
            ]);
            $degradationLevel = get_option('mia_default_degradation_level', 'moderate');
        }
        
        $this->getLogger()->info('Ejecutando degradación gradual', [
            'level' => $degradationLevel,
            'context' => $context
        ]);
        
        $defaultSize = BatchSizeHelper::getBatchSize($this->entityName);
        
        $levelConfig = $degradationConfig[$degradationLevel];
        
        if ($degradationLevel === 'critical') {
            // Modo de procesamiento individual
            $targetSize = 1;
            $multiplier = 1.0;
        } else {
            // Aplicar multiplicador de degradación al tamaño por defecto
            $targetSize = max(1, (int) ($defaultSize * $levelConfig['multiplier']));
            $multiplier = $levelConfig['multiplier'];
        }
        
        // Aplicar ajuste adicional de memoria si es necesario
        $adjustedSize = $this->adjustBatchSizeIfNeeded($targetSize);
        
        return [
            'success' => true,
            'degradation_level' => $degradationLevel,
            'multiplier_applied' => $multiplier,
            'original_size' => $defaultSize,
            'target_size' => $targetSize,
            'final_adjusted_size' => $adjustedSize,
            'actions_taken' => ['batch_size_adjusted', 'memory_adjustment_applied', 'processing_mode_changed']
        ];
    }
    
    /**
     * @return array Configuración de niveles de degradación
     */
    private function getDegradationConfiguration(): array
    {
        // Configuración por defecto
        $defaultConfig = [
            'light' => [
                'multiplier' => get_option('mia_degradation_light_multiplier', 0.8),
                'description' => 'Reducción ligera'
            ],
            'moderate' => [
                'multiplier' => get_option('mia_degradation_moderate_multiplier', 0.6),
                'description' => 'Reducción moderada'
            ],
            'heavy' => [
                'multiplier' => get_option('mia_degradation_heavy_multiplier', 0.4),
                'description' => 'Reducción significativa'
            ],
            'critical' => ['multiplier' => 1.0, 'description' => 'Procesamiento individual']
        ];
        
        // Permitir personalización vía WordPress options
        $customConfig = get_option('mia_degradation_levels_config', []);
        
        // ✅ REFACTORIZADO: Usar helper centralizado para fusionar opciones
        return $this->mergeOptionsWithDefaults($defaultConfig, $customConfig);
    }
    
    /**
     * Ejecuta estrategia de recuperación de estado
     * Implementa Resource Management y Graceful Degradation
     * @param array $context Contexto de la operación
     * @return array Resultado de la estrategia
     */
    protected function executeStateRecovery(array $context = []): array
    {
        $this->getLogger()->info('Ejecutando recuperación de estado', [
            'context' => $context
        ]);
        
        // Verificar si existe punto de recuperación usando método existente
        $hasRecoveryPoint = $this->checkRecoveryPoint();
        
        if ($hasRecoveryPoint && !empty($this->recoveryState)) {
            // Restaurar estado desde punto de recuperación existente
            $this->getLogger()->info('Punto de recuperación encontrado, restaurando estado', [
                'recovery_point' => $this->recoveryState['last_batch'] ?? 0,
                'processed_items' => $this->recoveryState['processed'] ?? 0
            ]);
            
            return [
                'success' => true,
                'state_restored' => true,
                'recovery_point' => $this->recoveryState,
                'items_processed' => $this->recoveryState['processed'] ?? 0,
                'last_batch' => $this->recoveryState['last_batch'] ?? 0,
                'actions_taken' => ['state_restored_from_checkpoint']
            ];
        }
        
        // Crear nuevo punto de recuperación con estado actual
        $currentState = [
            'last_batch' => $this->currentBatch ?? 0,
            'processed' => $this->processedItems ?? 0,
            'errors' => $this->errorCount ?? 0,
            'entity' => $this->entityName,
            'filters' => $this->filters ?? [],
            'timestamp' => time(),
            'memory_usage' => memory_get_usage(true),
            'operation_id' => $context['operation_id'] ?? 'recovery_' . time()
        ];
        
        // Guardar estado usando método existente
        $this->saveRecoveryState($currentState);
        
        $this->getLogger()->info('Nuevo punto de recuperación creado', [
            'state' => $currentState
        ]);
        
        return [
            'success' => true,
            'state_restored' => false,
            'new_recovery_point' => $currentState,
            'fallback_mode' => 'new_recovery_point_created',
            'actions_taken' => ['new_checkpoint_created']
        ];
    }
    
    /**
     * Ejecuta estrategia de limpieza de recursos
     * Implementa Resource Management y Performance Optimization
     * @param array $context Contexto de la operación
     * @return array Resultado de la estrategia
     */
    protected function executeResourceCleanup(array $context = []): array
    {
        $reason = $context['reason'] ?? get_option('mia_default_cleanup_reason', 'scheduled');
        $cleanupLevel = $context['level'] ?? get_option('mia_cleanup_level', 'basic');
        
        $this->getLogger()->info('Ejecutando limpieza de recursos', [
            'reason' => $reason,
            'level' => $cleanupLevel,
            'context' => $context
        ]);
        
        $cleanupActions = [];
        $totalCleaned = 0;
        $success = true;
        
        try {
            // Limpieza de memoria básica (responsabilidad directa de BatchProcessor)
            $memoryBefore = memory_get_usage(true);
            
            if (function_exists('gc_collect_cycles')) {
                $collected = gc_collect_cycles();
                /** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */
                $cleanupActions[] = "garbage_collection:{$collected}";
            }
            
            $memoryAfter = memory_get_usage(true);
            $memoryFreed = max(0, $memoryBefore - $memoryAfter);
            
            // Delegar limpieza de transients a clase especializada
            if (class_exists('\MiIntegracionApi\Helpers\Utils')) {
                $maxAge = $context['transient_max_age'] ?? get_option('mia_transient_cleanup_hours', 24);
                $transientResult = Utils::cleanup_old_sync_transients($maxAge);
                $transientsCleaned = $transientResult['cleaned_count'] ?? 0;
                
            if ($transientsCleaned > 0) {
                $cleanupActions[] = "transients_cleaned:$transientsCleaned";
                    $totalCleaned += $transientsCleaned;
                }
            }
            
            // Delegar limpieza avanzada a SyncMetrics si es necesario
            if ($cleanupLevel === 'advanced' && class_exists('\MiIntegracionApi\Core\SyncMetrics')) {
                $operationId = $context['operation_id'] ?? 'cleanup_' . time();
                $syncMetrics = SyncMetrics::getInstance();
                $syncMetrics->cleanupMemory($operationId);
                $cleanupActions[] = 'syncmetrics_cleanup';
            }
            
            // Limpieza de caché de WordPress (responsabilidad directa por compatibilidad)
            if ($cleanupLevel !== 'minimal') {
                if (function_exists('wp_cache_flush')) {
                    $flush_result = wp_cache_flush();
                    $cleanupActions[] = $flush_result ? 'object_cache_flush:success' : 'object_cache_flush:failed';
                } else {
                    $cleanupActions[] = 'object_cache_flush:not_available';
                }
            }
            
            // ✅ NUEVO: Limpieza de cold cache si es nivel avanzado o crítico
            if (($cleanupLevel === 'advanced' || $cleanupLevel === 'critical') && class_exists('\\MiIntegracionApi\\CacheManager')) {
                try {
                    $cacheManager = \MiIntegracionApi\CacheManager::get_instance();
                    $coldCleaned = $cacheManager->cleanExpiredColdCache();
                    if ($coldCleaned > 0) {
                        $cleanupActions[] = "cold_cache_cleaned:$coldCleaned";
                        $totalCleaned += $coldCleaned;
                    }
                } catch (Exception $e) {
                    $cleanupActions[] = 'cold_cache_cleanup:error';
                    $this->getLogger()->warning('Error limpiando cold cache durante limpieza de recursos', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // ✅ NUEVO: Evicción LRU preventiva si es nivel crítico
            if ($cleanupLevel === 'critical' && class_exists('\\MiIntegracionApi\\CacheManager')) {
                try {
                    $cacheManager = \MiIntegracionApi\CacheManager::get_instance();
                    $currentSize = $cacheManager->getTotalCacheSize();
                    $maxSize = $cacheManager->getGlobalCacheSizeLimit();
                    
                    // Si estamos cerca del límite (80%), forzar evicción
                    if ($currentSize > ($maxSize * 0.8)) {
                        // ✅ CORRECCIÓN: Forzar evicción usando reflexión para acceder al método privado
                        try {
                            $reflection = new \ReflectionClass($cacheManager);
                            $evictMethod = $reflection->getMethod('evictLRU');
                            $evictMethod->setAccessible(true);
                            
                            // Calcular espacio a liberar (hasta llegar al 70% del límite en modo crítico)
                            $targetSize = $maxSize * 0.7;
                            $sizeToFree = $currentSize - $targetSize;
                            
                            if ($sizeToFree > 0) {
                                $evictResult = $evictMethod->invoke($cacheManager, $sizeToFree);
                                $cleanupActions[] = "lru_eviction:forced:{$evictResult['evicted_count']}";
                                $this->getLogger()->info('Evicción LRU forzada durante limpieza crítica', [
                                    'evicted_count' => $evictResult['evicted_count'],
                                    'space_freed_mb' => $evictResult['space_freed_mb']
                                ]);
                            } else {
                                $cleanupActions[] = 'lru_eviction:not_needed';
                            }
                        } catch (\ReflectionException $e) {
                            // Si falla la reflexión, al menos verificar
                            $cleanupActions[] = 'lru_eviction:check_failed';
                            $this->getLogger()->warning('No se pudo forzar evicción LRU durante limpieza crítica', [
                                'error' => $e->getMessage()
                            ]);
                        }
                    } else {
                        $cleanupActions[] = 'lru_eviction:not_needed';
                    }
                } catch (Exception $e) {
                    $cleanupActions[] = 'lru_eviction_check:error';
                    $this->getLogger()->warning('Error verificando evicción LRU durante limpieza crítica', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Limpieza de OPcache si está disponible y es nivel avanzado
            if ($cleanupLevel === 'advanced' && function_exists('opcache_reset')) {
                opcache_reset();
                $cleanupActions[] = 'opcache_reset';
            }
            
        } catch (Throwable $e) {
            $success = false;
            // ✅ REFACTORIZADO: Usar helper centralizado
            $this->logException($e, 'Error durante limpieza de recursos', [
                'reason' => $reason,
                'level' => $cleanupLevel
            ]);
        }
        
        return [
            'success' => $success,
            'actions_performed' => $cleanupActions,
            'total_items_cleaned' => $totalCleaned,
            'memory_freed_bytes' => $memoryFreed ?? 0,
            'cleanup_level' => $cleanupLevel,
            'reason' => $reason
        ];
    }

    /**
     * Orquestador de procesamiento por lotes con arquitectura limpia.
     * Motor principal que coordina el procesamiento masivo delegando responsabilidades
     * a clases especializadas siguiendo principios SOLID:
     * **DELEGACIONES ARQUITECTÓNICAS:**
     * - **TransactionManager**: Gestión de transacciones con rollback automático
     * - **SyncLoggingHelper**: Logging especializado y contextual
     * - **MemoryManager**: Gestión inteligente de memoria y limpieza
     * - **SyncMetrics**: Métricas centralizadas y persistentes
     * - **BatchSizeHelper**: Configuración dinámica de tamaños de lote
     *
     * **CARACTERÍSTICAS AVANZADAS:**
     * - **Monitoreo preventivo**: Detección temprana de problemas de recursos
     * - **Ajuste dinámico**: Modificación automática según condiciones del sistema
     * - **Recovery points**: Sistema de checkpoints para reanudar procesos interrumpidos
     * - **Pausas adaptativas**: Estabilización inteligente basada en carga
     * - **Configurabilidad total**: 15+ parámetros ajustables vía WordPress options
     * - **Transacciones reales**: Consistencia de datos con savepoints
     *
     * @param array $items Elementos a procesar (cualquier tipo de datos)
     * @param callable $processCallback Función con firma: function($item): array['success' => bool, ...]
     * @param int|null $batchSize Tamaño del lote (null = cálculo automático vía BatchSizeHelper)
     * @param bool $forceRestart True para ignorar recovery points y empezar desde cero
     *
     * @return array Resultado completo del procesamiento:
     *   - 'total' (int): Total de elementos a procesar
     *   - 'processed' (int): Elementos procesados exitosamente
     *   - 'errors' (int): Número de errores encontrados
     *   - 'duration' (float): Tiempo total en segundos (precisión configurable)
     *   - 'batches_processed' (int): Número de lotes procesados
     *   - 'memory_impact' (array): Estadísticas de memoria inicial/final/pico
     *   - 'log' (array): Log detallado de eventos durante el procesamiento
     *
     * @throws Throwable
     * @since 1.0.0
     * @since 2.0.0 Refactorizado con monitoreo en tiempo real y optimización dinámica
     * @since 2.2.0 Eliminadas duplicaciones masivas, delegación a clases especializadas
     *
     * @example
     * ```php
     * $processor = new BatchProcessor($apiConnector);
     * $result = $processor->process($products, function($product) {
     *     return $this->syncProduct($product);
     * });
     *
     * if ($result['errors'] === 0) {
     *     echo "Procesamiento exitoso: {$result['processed']} elementos";
     * } else {
     *     echo "Errores: {$result['errors']} de {$result['total']}";
     * }
     * ```
     *
     * @see checkRecoveryPoint() Para sistema de recuperación
     * @see TransactionManager::getInstance() Para gestión transaccional
     * @see MemoryManager::cleanup() Para limpieza especializada
     * @see SyncMetrics::recordBatchMetrics() Para métricas centralizadas
     * @see BatchSizeHelper::getBatchSize() Para configuración de lotes
     */
    public function process(array $items, callable $processCallback, ?int $batchSize = null, bool $forceRestart = false): array
    {
        // Aumentar tiempo de ejecución para evitar timeouts
        $current_time_limit = ini_get('max_execution_time');
        if ($current_time_limit > 0 && $current_time_limit < self::BATCH_TIMEOUT + 60) {
            // Aumentar a BATCH_TIMEOUT + 60 segundos de buffer
            @set_time_limit(self::BATCH_TIMEOUT + 60);
            $this->getLogger()->debug('Tiempo de ejecución aumentado para procesamiento de lotes', [
                'previous_limit' => $current_time_limit,
                'new_limit' => self::BATCH_TIMEOUT + 60,
                'entity' => $this->entityName
            ]);
        }
        
        // Inicializar startTime de la propiedad cuando comience el procesamiento real
        $this->startTime = microtime(true);
        
        $total = count($items);
        $processed = 0;
        $errors = 0;
        $skipped = 0; // Contador de productos saltados
        $log = [];
        $startTime = $this->startTime; // Usar la misma referencia
        
        // Cálculo automático del tamaño de lote si no se especifica
        if ($batchSize === null) {
            $batchSize = BatchSizeHelper::getBatchSize($this->entityName);
        }
        
        // Usar SyncLoggingHelper para logging especializado
        $initialMemoryStats = MemoryManager::getMemoryStats();
        $syncLogger = new SyncLoggingHelper('batch-processing');
        $syncLogger->logBatchStart([
            'entity' => $this->entityName,
            'direction' => 'sync',
            'offset' => 0,
            'batch_size' => $batchSize,
            'total_items' => $total,
            'estimated_batches' => ceil($total / $batchSize)
        ]);
        
        // Verificar punto de recuperación
        if (!$forceRestart && $this->checkRecoveryPoint()) {
            $processed = $this->recoveryState['processed'] ?? 0;
            $errors = $this->recoveryState['errors'] ?? 0;
            $log[] = sprintf(
                'Reanudando sincronización desde el lote #%d (%d elementos procesados)',
                $this->recoveryState['last_batch'] ?? 0,
                $processed
            );
        }

        // Dividir en lotes y procesar con monitoreo en tiempo real
        $batches = array_chunk($items, $batchSize);
        $totalBatches = count($batches);
        
        $this->getLogger()->debug('Iniciando procesamiento de lotes', [
            'total_items' => $total,
            'batch_size' => $batchSize,
            'total_batches' => $totalBatches,
            'callback_type' => gettype($processCallback),
            'callback_callable' => is_callable($processCallback)
        ]);
        
        foreach ($batches as $batchIndex => $batch) {
            $batchNum = $batchIndex + 1;
            
            // Obtener estadísticas de memoria antes del lote
            $preBatchMemoryStats = MemoryManager::getMemoryStats();
            
            // Verificar memoria con umbrales dinámicos
            if (MemoryManager::shouldStopForCriticalMemory($preBatchMemoryStats)) {
                $log[] = sprintf('Detención preventiva por uso de memoria en lote #%d', $batchNum);
                $this->getLogger()->warning('Detención preventiva por memoria crítica', [
                    'batch_num' => $batchNum,
                    'memory_stats' => $preBatchMemoryStats,
                    'usage_percentage' => $preBatchMemoryStats['usage_percentage'] ?? 0
                ]);
                break;
            }
            
            // Ajustar tamaño del lote dinámicamente si es necesario
            $adjustedBatchSize = $this->adjustBatchSizeIfNeeded($batchSize);
            if ($adjustedBatchSize !== $batchSize) {
                $batchSize = $adjustedBatchSize;
                $batches = array_chunk($items, $batchSize);
                $batch = $batches[$batchIndex];
                // Logging delegado a MemoryManager::adjustBatchSize()
            }
            
            $batchStartTime = microtime(true);
            $batchErrors = 0;
            $batchProcessed = 0;
            $shouldStopAfterBatch = false;
            
            // Iniciar transacción para garantizar consistencia
            $transactionManager = TransactionManager::getInstance();
            $operationId = $this->generateConsistentBatchId($batchNum);
            $transactionManager->beginTransaction("batch_processing", $operationId);
            
            // Calcular intervalo adaptativo para verificación de memoria
            $memoryCheckInterval = MemoryManager::calculateMemoryCheckInterval(count($batch));
            $log[] = sprintf('Usando intervalo adaptativo de verificación de memoria: cada %d elementos', $memoryCheckInterval);
            
            try {
                // Procesamiento con monitoreo de memoria en tiempo real
                foreach ($batch as $itemIndex => $item) {
                    try {
                        // Verificar timeout antes de procesar cada item
                        if ($this->isTimeoutExceeded()) {
                            $log[] = sprintf('Timeout excedido durante procesamiento del lote #%d (item %d/%d)', $batchNum, $itemIndex + 1, count($batch));
                            $transactionManager->rollback("batch_processing", $operationId);
                            break 2; // Salir de ambos bucles
                        }
                        
                        // Verificación de memoria adaptativa durante el procesamiento
                        if ($itemIndex % $memoryCheckInterval === 0) {
                            $currentMemoryStats = MemoryManager::getMemoryStats();
                            
                            // Verificar si debe detenerse inmediatamente (crítico)
                            if (MemoryManager::shouldStopForCriticalMemory($currentMemoryStats)) {
                                $log[] = sprintf('Detención crítica durante procesamiento del lote #%d por memoria (%.2f%%)', $batchNum, $currentMemoryStats['usage_percentage']);
                                $transactionManager->rollback("batch_processing", $operationId);
                                break 2; // Salir de ambos bucles
                            }
                            
                            // Verificar si debe detenerse gradualmente después del lote actual
                            if (MemoryManager::shouldStopGracefullyForMemory($currentMemoryStats)) {
                                $shouldStopAfterBatch = true;
                                $log[] = sprintf('Preparando parada gradual después del lote #%d por uso de memoria (%.2f%%)', $batchNum, $currentMemoryStats['usage_percentage']);
                            }
                        }
                        
                        // AGREGAR BATCH_ID AL PRODUCTO PARA ACCESO A CACHÉ
                        if (is_array($item)) {
                            $item['_batch_id'] = $this->generateConsistentBatchId($batchNum);
                        }
                        
                        $result = $processCallback($item);
                        
                        if (isset($result['success']) && $result['success']) {
                            // Distinguir entre productos procesados y saltados
                            if (isset($result['action']) && $result['action'] === 'skipped') {
                                $skipped++;
                            } else {
                                $batchProcessed++;
                            }
                        } else {
                            $batchErrors++;
                            $log[] = sprintf(
                                'Error procesando elemento en lote #%d: %s',
                                $batchNum,
                                $result['message'] ?? ($result['error'] ?? 'Error desconocido')
                            );
                        }
                    } catch (Exception $e) {
                        $batchErrors++;
                        $log[] = sprintf(
                            'Excepción procesando elemento en lote #%d: %s',
                            $batchNum,
                            $e->getMessage()
                        );
                        
                        // ✅ REFACTORIZADO: Usar helper centralizado con trace
                        $this->logException($e, 'Excepción en callback', [
                            'item_type' => gettype($item),
                            'item_keys' => is_array($item) ? array_keys($item) : 'not_array'
                        ], 'error', true);
                    }
                }
                
                // Confirmar transacción si el lote se completó exitosamente
                $transactionManager->commit("batch_processing", $operationId);
                
            } catch (Throwable $e) {
                // Revertir transacción en caso de error crítico
                $transactionManager->rollback("batch_processing", $operationId);
                
                // ✅ REFACTORIZADO: Usar helper centralizado con trace
                $this->logException($e, 'Excepción crítica durante procesamiento de batch', [
                    'batch_id' => $operationId
                ], 'error', true);
                
                throw $e; // Re-lanzar para manejo superior
            }
            
            $processed += $batchProcessed;
            $errors += $batchErrors;
            
            // ✅ MEJORADO: Limpieza de memoria entre lotes con integración hot/cold
            if (class_exists('\MiIntegracionApi\Core\MemoryManager')) {
                $memoryUsagePercent = $preBatchMemoryStats['usage_percentage'] ?? 0;
                $aggressive = $memoryUsagePercent > 80;
                MemoryManager::cleanup("batch_$batchNum", $aggressive);
                
                // ✅ NUEVO: Migración hot→cold si memoria > 75% durante sincronización
                if ($memoryUsagePercent > 75 && class_exists('\\MiIntegracionApi\\CacheManager')) {
                    try {
                        $cacheManager = \MiIntegracionApi\CacheManager::get_instance();
                        $autoMigrationEnabled = get_option('mia_enable_hot_cold_migration', true);
                        if ($autoMigrationEnabled) {
                            $migrationResult = $cacheManager->performHotToColdMigration();
                            if ($migrationResult['migrated_count'] > 0) {
                                $this->getLogger()->info('Migración hot→cold entre lotes', [
                                    'batch_num' => $batchNum,
                                    'memory_usage_percent' => round($memoryUsagePercent, 1),
                                    'migrated_count' => $migrationResult['migrated_count']
                                ]);
                            }
                        }
                    } catch (Exception $e) {
                        $this->getLogger()->warning('Error en migración hot→cold entre lotes', [
                            'error' => $e->getMessage(),
                            'batch_num' => $batchNum
                        ]);
                    }
                }
            }
            
            // Logging de finalización del lote
            $batchDuration = microtime(true) - $batchStartTime;
            $postBatchMemoryStats = MemoryManager::getMemoryStats();
            $syncLogger->logBatchEnd([
                'batch_num' => $batchNum,
                'total_batches' => $totalBatches,
                'batch_size' => count($batch),
                'processed' => $batchProcessed,
                'errors' => $batchErrors
            ], $batchStartTime);
            
            // Pausa inteligente entre lotes si es necesario
            if (class_exists('\MiIntegracionApi\Core\MemoryManager')) {
                $usagePercentage = $postBatchMemoryStats['usage_percentage'] ?? 0;
                if ($usagePercentage > get_option('mia_pause_memory_threshold', 75)) {
                    $maxPause = get_option('mia_max_pause_seconds', 5);
                    $pauseMultiplier = get_option('mia_pause_multiplier', 0.1);
                    $thresholdBase = get_option('mia_pause_memory_threshold', 75);
                    $pauseTime = min($maxPause, ($usagePercentage - $thresholdBase) * $pauseMultiplier);
                    usleep($pauseTime * 1000000);
                    
                    $this->getLogger()->info("Pausa inteligente entre lotes", [
                        'batch_num' => $batchNum,
                        'pause_time_seconds' => $pauseTime,
                        'memory_usage_percentage' => $usagePercentage,
                        'reason' => 'memory_pressure'
                    ]);
                }
            }
            
            // Detenerse gradualmente si se alcanzó el umbral de memoria
            if ($shouldStopAfterBatch) {
                $log[] = sprintf('Detención gradual completada después del lote #%d', $batchNum);
                break;
            }
        }
        
        $currentTime = microtime(true);
        $duration = $currentTime - $startTime;
        
        // Logging de finalización del procesamiento
        $finalMemoryStats = MemoryManager::getMemoryStats();
        $this->getLogger()->info('Procesamiento por lotes completado', [
            'total_items' => $total,
            'processed' => $processed,
            'skipped' => $skipped, // NUEVO: Incluir conteo de productos saltados
            'errors' => $errors,
            'duration' => round($duration, 2),
            'batches_processed' => $batchNum ?? 0,
            'initial_memory' => $initialMemoryStats,
            'final_memory' => $finalMemoryStats,
            'memory_improvement' => ($initialMemoryStats['current'] ?? 0) - ($finalMemoryStats['current'] ?? 0)
        ]);
        
        // Limpieza automática de logs después del procesamiento
        try {
            $this->getLogCleaner()->onSyncCompleted([
                'items_synced' => $processed,
                'total_items' => $total,
                'errors' => $errors
            ]);
        } catch (Exception $e) {
            // ✅ REFACTORIZADO: Usar helper centralizado (nivel warning)
            $this->logException($e, 'Error durante limpieza de logs', [
                'processed' => $processed
            ], 'warning');
        }
        
        // Registrar métricas en SyncMetrics para persistencia centralizada
        if (class_exists('\MiIntegracionApi\Core\SyncMetrics')) {
            $operationId = $this->generateConsistentBatchId();
            $syncMetrics = SyncMetrics::getInstance();
            
            $syncMetrics->recordBatchMetrics(
                $operationId,
                $processed,
                $duration,
                $errors
            );
        }
        
        return [
            'total' => $total,
            'processed' => $processed,
            'skipped' => $skipped, // Incluir conteo de productos saltados
            'errors' => $errors,
            'duration' => round($duration, (int) get_option('mia_duration_precision', 2)),
            'batches_processed' => $batchNum ?? 0,
            'memory_impact' => [
                'initial' => $initialMemoryStats,
                'final' => $finalMemoryStats,
                'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, (int) get_option('mia_memory_precision', 2))
            ],
            'log' => $log
        ];
    }

    /**
     * Genera un batch ID consistente y único usando IdGenerator centralizado.
     * Método refactorizado que delega la generación de IDs al helper centralizado,
     * manteniendo compatibilidad con el comportamiento anterior pero añadiendo:
     * - **Configurabilidad total**: Formatos ajustables vía WordPress options
     * - **Mayor unicidad**: Usa microtime para evitar colisiones temporales
     * - **Contexto enriquecido**: Incluye información de la entidad procesada
     * - **Validación**: IDs con formato consistente y validable
     * @param int $batchNumber Número del lote actual (opcional)
     * @return string ID único del lote
     * @example
     * ```php
     * $batchId = $this->generateConsistentBatchId(1);     // "batch_productos_1735689123456_001"
     * $batchId = $this->generateConsistentBatchId();      // "batch_productos_1735689123456"
     * ```
     * @see IdGenerator::generateBatchId Para configuración avanzada
     */
    private function generateConsistentBatchId(int $batchNumber = 0): string
    {
        // Si ya tenemos un batch ID establecido, usarlo (mantener compatibilidad)
        if (!empty($this->currentBatchId)) {
            return $this->currentBatchId;
        }
        
        // Usar IdGenerator centralizado con contexto de la entidad
        return IdGenerator::generateBatchId(
            $this->entityName, // Contexto basado en la entidad actual
            $batchNumber
        );
    }

    /**
     * Delegación pura a MemoryManager para ajuste de batch size
     *
     * Método simplificado que delega completamente a MemoryManager::adjustBatchSize(),
     * eliminando duplicación de lógica y hardcodeos. El MemoryManager maneja:
     *
     * - **Umbrales dinámicos**: Configurables vía WordPress options
     * - **Factores de ajuste**: Inteligentes según el nivel de memoria
     * - **Logging contextual**: Para debugging y monitoreo
     * - **Validación robusta**: Con límites mínimos y máximos
     *
     * @param int $currentBatchSize Tamaño actual del lote
     * @return int Tamaño ajustado del lote
     *
     * @see MemoryManager::adjustBatchSize Para la implementación completa
     * @since 2.2.0 Refactorizado para eliminar duplicación con MemoryManager
     */
    private function adjustBatchSizeIfNeeded(int $currentBatchSize): int
    {
        return MemoryManager::adjustBatchSize(
            $currentBatchSize,
            self::MIN_BATCH_SIZE,
            'batch-processing'
        );
    }

    /**
     * Verifica si se excedió el límite de memoria delegando a MemoryManager
     * Delega completamente a MemoryManager::isMemoryLimitExceeded() que maneja:
     * - Detección dinámica del límite de memoria del sistema
     * - Configuración adaptativa según el entorno (desarrollo/producción)
     * - Buffers inteligentes para prevenir problemas
     * - Configuración personalizable via WordPress options
     * @return bool True si se excedió el límite de memoria
     * @since 2.2.0 Refactorizado para delegar a MemoryManager especializado
     * @see MemoryManager::isMemoryLimitExceeded Para implementación completa
     */
    protected function isMemoryLimitExceeded(): bool
    {
        return MemoryManager::isMemoryLimitExceeded();
    }

    /**
     * Verifica si se excedió el timeout
     * Verifica tanto el timeout del batch como el límite de PHP
     */
    protected function isTimeoutExceeded(): bool
    {
        $elapsed = microtime(true) - $this->startTime;
        $php_time_limit = ini_get('max_execution_time');
        
        // Verificar timeout del batch
        if ($elapsed > self::BATCH_TIMEOUT) {
            return true;
        }
        
        // Verificar límite de PHP (con buffer de 5 segundos)
        if ($php_time_limit > 0 && $elapsed > ($php_time_limit - 5)) {
            $this->getLogger()->warning('Aproximándose al límite de tiempo de PHP', [
                'elapsed' => round($elapsed, 2),
                'php_limit' => $php_time_limit,
                'remaining' => round($php_time_limit - $elapsed, 2)
            ]);
            return true;
        }
        
        return false;
    }

    /**
     * Establece el nombre de la entidad para el sistema de recuperación
     * @param string $entityName Nombre de la entidad (productos, clientes, pedidos)
     * @return $this
     */
    public function setEntityName(string $entityName): self
    {
        $this->entityName = $entityName;
        return $this;
    }
    
    /**
     * Establece los filtros aplicados en la sincronización actual
     * @param array $filters Filtros aplicados
     * @return $this
     */
    public function setFilters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }

    /**
     * Verifica y carga un punto de recuperación para reanudar procesamiento interrumpido.
     * Sistema de puntos de recuperación que permite reanudar procesos de sincronización
     * que fueron interrumpidos por errores, timeouts o problemas de memoria. El sistema:
     * - Verifica la existencia de puntos de recuperación válidos
     * - Carga el estado guardado del procesamiento anterior
     * - Valida la consistencia de los datos de recuperación
     * - Configura el procesador para continuar desde el punto correcto
     * - Registra la reanudación en los logs del sistema
     * **Requisitos para la recuperación:**
     * - Debe estar configurado el nombre de entidad (`entityName`)
     * - Debe existir un punto de recuperación válido en el sistema
     * - Los filtros aplicados deben coincidir con la ejecución anterior
     * @return bool True si hay un punto de recuperación disponible y se cargó exitosamente
     * @since 1.0.0
     * @since 1.4.1 Integrado con Sync_Manager para gestión centralizada
     * @example
     * ```php
     * $processor->setEntityName('productos');
     * if ($processor->checkRecoveryPoint()) {
     *     echo "Reanudando desde lote: " . $processor->recoveryState['last_batch'];
     * }
     * ```
     * @see setEntityName() Para configurar la entidad
     * @see saveRecoveryState() Para guardar puntos de recuperación
     * @see Sync_Manager::canResumeSync() Para verificación de recuperación
     */
    public function checkRecoveryPoint(): bool
    {
        if (empty($this->entityName)) {
            return false;
        }
        
        // Usar SyncRecovery directamente para recovery
        $recovery_result = SyncRecovery::canResumeSync($this->entityName, $this->filters);
        $this->recoveryState = is_array($recovery_result) ? $recovery_result : [];
        $this->isResuming = !empty($this->recoveryState);
        
        if ($this->isResuming) {
            // Logging optimizado usando el logger unificado
            $logger = $this->getLogger();
            $logger->info("Reanudando sincronización de $this->entityName desde el lote #{$this->recoveryState['last_batch']}", [
                'processed' => $this->recoveryState['processed'] ?? 0,
                'total' => $this->recoveryState['total'] ?? 0,
                'category' => "sync-recovery-$this->entityName"
            ]);
        }
        
        return $this->isResuming;
    }

    /**
     * Guarda el estado actual del procesamiento para recuperación futura.
     * Crea un punto de recuperación que permite reanudar el procesamiento
     * desde el estado actual en caso de interrupciones. El estado guardado
     * incluye toda la información necesaria para continuar el proceso:
     * - Número del último lote procesado
     * - Cantidad de elementos procesados exitosamente
     * - Número de errores acumulados
     * - Filtros aplicados en la sincronización
     * - Timestamp del punto de recuperación
     * - Métricas de rendimiento hasta el momento
     * **Información guardada:**
     * - Progreso del procesamiento
     * - Estado de errores y reintentos
     * - Configuración de filtros
     * - Métricas de memoria y rendimiento
     * @param array $state Estado completo a guardar con estructura:
     *   - 'last_batch' (int): Último lote procesado
     *   - 'processed' (int): Elementos procesados exitosamente
     *   - 'errors' (int): Número de errores encontrados
     *   - 'total' (int): Total de elementos a procesar
     *   - 'filters' (array): Filtros aplicados
     *   - 'timestamp' (int): Timestamp del punto de recuperación
     * @return void
     * @since 1.0.0
     * @since 1.4.1 Integrado con Sync_Manager para persistencia centralizada
     * @see checkRecoveryPoint() Para verificar existencia de puntos de recuperación
     * @see clearRecoveryState() Para limpiar estado guardado
     * @see SyncRecovery::saveState() Para persistencia
     */
    protected function saveRecoveryState(array $state): void
    {
        if (empty($this->entityName)) {
            return;
        }
        
        // Usar SyncRecovery directamente
        SyncRecovery::saveState($this->entityName, $state);
    }

    /**
     * Limpia el estado de recuperación
     *
     * @return void
     */
    protected function clearRecoveryState(): void
    {
        if (empty($this->entityName)) {
            return;
        }
        
        // Usar SyncRecovery directamente
        SyncRecovery::clearState($this->entityName);
    }

    /**
     * Verifica si se ha solicitado cancelación externa
     *
     * @return bool Verdadero si se ha solicitado cancelación
     */
    protected function isExternallyCancelled(): bool
    {
        // CENTRALIZADO: Usar SyncStatusHelper para verificar cancelación
        return SyncStatusHelper::isCancellationRequested();
    }

    /**
     * Indexa categorías usando IndexHelper centralizado
     * Método refactorizado que delega al IndexHelper para eliminar duplicación.
     * Mantiene la misma interfaz pública pero usa implementación centralizada.
     * @param array $categorias Array de categorías con estructura [['ID' => 1, 'Nombre' => 'Categoría A'], ...]
     * @return array Array indexado por ID [1 => 'Categoría A', 2 => 'Categoría B', ...]
     * @since 2.2.0 Refactorizado para usar IndexHelper centralizado
     * @see IndexHelper::indexKnownEntity Para implementación
     */
    protected function index_categorias(array $categorias): array {
        return IndexHelper::indexKnownEntity(
            $categorias,
            'categorias',
            $this->getLogger()
        );
    }

    /**
     * ✅ REFACTORIZADO: Indexa fabricantes usando IndexHelper centralizado
     * @param array $fabricantes Array de fabricantes con estructura [['ID' => 1, 'Nombre' => 'Editorial ABC'], ...]
     * @return array Array indexado por ID [1 => 'Editorial ABC', 2 => 'Editorial XYZ', ...]
     * @since 2.2.0 Refactorizado para usar IndexHelper centralizado
     */
    protected function index_fabricantes(array $fabricantes): array {
        return IndexHelper::indexKnownEntity(
            $fabricantes,
            'fabricantes',
            $this->getLogger()
        );
    }

    /**
     * ✅ REFACTORIZADO: Indexa colecciones usando IndexHelper centralizado
     * @param array $colecciones Array de colecciones con estructura [['ID' => 1, 'Valor' => 'Colección A'], ...]
     * @return array Array indexado por ID [1 => 'Colección A', 2 => 'Colección B', ...]
     * @since 2.2.0 Refactorizado para usar IndexHelper centralizado
     */
    protected function index_colecciones(array $colecciones): array {
        return IndexHelper::indexKnownEntity(
            $colecciones,
            'colecciones',
            $this->getLogger()
        );
    }

    /**
     * ✅ REFACTORIZADO: Indexa cursos usando IndexHelper centralizado
     * @param array $cursos Array de cursos con estructura [['ID' => 1, 'Valor' => 'Primero'], ...]
     * @return array Array indexado por ID [1 => 'Primero', 2 => 'Segundo', ...]
     * @since 2.2.0 Refactorizado para usar IndexHelper centralizado
     */
    protected function index_cursos(array $cursos): array {
        return IndexHelper::indexKnownEntity(
            $cursos,
            'cursos',
            $this->getLogger()
        );
    }

    /**
     * ✅ REFACTORIZADO: Indexa asignaturas usando IndexHelper centralizado
     * @param array $asignaturas Array de asignaturas con estructura [['ID' => 1, 'Valor' => 'Matemáticas'], ...]
     * @return array Array indexado por ID [1 => 'Matemáticas', 2 => 'Lengua', ...]
     * @since 2.2.0 Refactorizado para usar IndexHelper centralizado
     */
    protected function index_asignaturas(array $asignaturas): array {
        return IndexHelper::indexKnownEntity(
            $asignaturas,
            'asignaturas',
            $this->getLogger()
        );
    }
    
    /**
     * ✅ HELPER: Valida y procesa respuestas de métodos batch
     * Centraliza la lógica de validación duplicada en los métodos batch.
     * Elimina ~123 líneas de código duplicado.
     * @param \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface $response   Respuesta de la API
     * @param string                                                             $method_name Nombre del método que llama (para contexto)
     * @param string                                                             $endpoint    Nombre del endpoint de la API
     * @param int                                                                $inicio      Índice de inicio del lote
     * @param int                                                                $fin         Índice de fin del lote
     * @param array                                                              $response_data Datos de la respuesta obtenidos
     * @param array                                                              $additional_context Contexto adicional para logs
     * @param callable|null                                                      $success_formatter Callback para formatear respuesta exitosa
     * @return \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta validada y procesada
     * @since 2.3.0 Centralizado para eliminar duplicación
     */
    protected function validateAndProcessBatchResponse(
        \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface $response,
        string $method_name,
        string $endpoint,
        int $inicio,
        int $fin,
        array $response_data,
        array $additional_context = [],
        ?callable $success_formatter = null
    ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
        // Verificar si la respuesta es exitosa
        if (!$response->isSuccess()) {
            return ResponseFactory::error(
                'Error en API: ' . $response->getMessage(),
                $response->getCode(),
                array_merge([
                    'method' => $method_name,
                    'endpoint' => $endpoint,
                    'inicio' => $inicio,
                    'fin' => $fin,
                    'session_number' => $this->apiConnector->get_session_number(),
                    'api_error_code' => $response->getCode(),
                    'api_error_message' => $response->getMessage()
                ], $additional_context)
            );
        }
        
        // ✅ REFACTORIZADO: Usar helper centralizado para validar array
        $arrayError = $this->validateArrayOrError(
            $response_data,
            'respuesta de la API',
            'batch_response_validation',
            array_merge([
                'method' => $method_name,
                'endpoint' => $endpoint,
                'inicio' => $inicio,
                'fin' => $fin,
            ], $additional_context)
        );
        if ($arrayError !== null) {
            // Personalizar mensaje de error específico para respuestas de API
            return ResponseFactory::error(
                'La respuesta de la API no tiene el formato esperado',
                422,
                array_merge([
                    'method' => $method_name,
                    'endpoint' => $endpoint,
                    'inicio' => $inicio,
                    'fin' => $fin,
                    'response_type' => gettype($response_data)
                ], $additional_context)
            );
        }
        
        // Verificar si hay errores en la respuesta de la API
        if (isset($response_data['InfoError']) && $response_data['InfoError']['Codigo'] != 0) {
            return ResponseFactory::error(
                $response_data['InfoError']['Descripcion'] ?? 'Error desconocido de la API',
                422,
                array_merge([
                    'method' => $method_name,
                    'endpoint' => $endpoint,
                    'inicio' => $inicio,
                    'fin' => $fin,
                    'api_error_code' => $response_data['InfoError']['Codigo'],
                    'api_error_description' => $response_data['InfoError']['Descripcion']
                ], $additional_context)
            );
        }
        
        // Si hay un formatter personalizado, usarlo
        if ($success_formatter !== null && is_callable($success_formatter)) {
            return $success_formatter($response_data, $method_name, $endpoint, $inicio, $fin, $additional_context);
        }
        
        // Respuesta exitosa por defecto
        return ResponseFactory::success(
            $response_data,
            "Lote de {$endpoint} obtenido exitosamente",
            array_merge([
                'method' => $method_name,
                'endpoint' => $endpoint,
                'inicio' => $inicio,
                'fin' => $fin
            ], $additional_context)
        );
    }
    
    /**
     * ✅ NUEVO: Valida InfoError en datos de respuesta de la API
     * Este método centraliza la validación de InfoError para evitar duplicación.
     * Lanza una excepción si hay un error, o retorna true si todo está bien.
     *
     * @param array $data Datos de la respuesta de la API
     * @param string $api_endpoint Nombre del endpoint para el mensaje de error (opcional)
     * @return bool True si no hay errores
     * @throws \Exception Si hay un error en InfoError
     */
    protected function validateInfoError(array $data, string $api_endpoint = 'API'): bool
    {
        if (isset($data['InfoError'])) {
            $error_code = $data['InfoError']['Codigo'] ?? -1;
            if ($error_code !== 0) {
                $error_desc = $data['InfoError']['Descripcion'] ?? 'Error desconocido';
                throw new \Exception("Error en API {$api_endpoint}: Código $error_code - $error_desc");
            }
        }
        return true;
    }
    
    /**
     * ✅ NUEVO: Maneja respuestas de API de forma consistente
     * Este método centraliza el manejo de respuestas para evitar duplicación.
     * Soporta diferentes comportamientos: throw exception, return empty array, o log warning.
     *
     * @param \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface $response Respuesta de la API
     * @param string $endpoint Nombre del endpoint para logging
     * @param string $behavior Comportamiento: 'throw' (lanzar excepción), 'empty' (retornar []), 'warn' (log warning y retornar [])
     * @param array $additional_context Contexto adicional para logging
     * @return array Datos de la respuesta o array vacío según el comportamiento
     * @throws \Exception Si behavior es 'throw' y la respuesta no es exitosa
     */
    protected function handleApiResponse(
        \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface $response,
        string $endpoint,
        string $behavior = 'throw',
        array $additional_context = []
    ): array {
        if ($response->isSuccess()) {
            return $response->getData();
        }
        
        $error_message = $response->getMessage();
        $error_code = $response->getCode();
        
        $context = array_merge([
            'endpoint' => $endpoint,
            'error' => $error_message,
            'code' => $error_code
        ], $additional_context);
        
        switch ($behavior) {
            case 'throw':
                throw new \Exception("Error obteniendo {$endpoint}: {$error_message}");
                
            case 'warn':
                $this->getLogger()->warning("⚠️ Error obteniendo {$endpoint}, usando valor por defecto", $context);
                return [];
                
            case 'empty':
            default:
                return [];
        }
    }
    
    /**
     * ✅ REFACTORIZADO: Construye una respuesta de error estándar como array
     * Este método centraliza la construcción de respuestas de error en formato array
     * para métodos que retornan arrays (como callbacks).
     * Usa ResponseFactory internamente para mantener consistencia.
     *
     * @param string $error_message Mensaje de error descriptivo
     * @param int $processed Cantidad de productos procesados (normalmente 0 para errores)
     * @param array $additional_data Campos adicionales opcionales (batch_id, errors, log, etc.)
     * @return array Respuesta de error estándar en formato array
     */
    protected function buildErrorResponse(string $error_message, int $processed = 0, array $additional_data = []): array
    {
        // Usar ResponseFactory para crear la respuesta y luego convertir a array para compatibilidad
        $response = ResponseFactory::error(
            $error_message,
            HttpStatusCodes::INTERNAL_SERVER_ERROR,
            $additional_data,
            ['processed' => $processed]
        );
        
        // Convertir SyncResponseInterface a array para compatibilidad con código existente
        return [
            'success' => false,
            'error' => $error_message,
            'processed' => $processed,
            'error_code' => $response->getCode(),
            'data' => $response->getData()
        ] + $additional_data;
    }
    
    /**
     * ✅ HELPER: Construye una respuesta de éxito estándar como array
     * Este método centraliza la construcción de respuestas de éxito en formato array
     * para métodos que retornan arrays (como callbacks), complementando `buildErrorResponse()`.
     * **VERIFICACIÓN DE INFRAESTRUCTURA EXISTENTE**:
     * - ✅ Revisado `buildErrorResponse()` - Existe pero es solo para errores
     * - ✅ Revisado `ResponseFactory::success()` - Existe pero retorna `SyncResponseInterface`, no array
     * - ✅ Revisado otros helpers - No hay helper específico para respuestas de éxito en formato array
     * **JUSTIFICACIÓN**: Este método centraliza el patrón común de construir respuestas de éxito
     * en formato array (`'success' => true`), que estaba duplicado en múltiples lugares. Complementa
     * `buildErrorResponse()` para mantener consistencia en el formato de respuestas.
     *
     * @param int $processed Cantidad de items procesados (default: 1)
     * @param string|null $message Mensaje de éxito opcional
     * @param array $additional_data Campos adicionales opcionales (product_id, action, batch_id, etc.)
     * @return array Respuesta de éxito estándar en formato array
     */
    protected function buildSuccessResponse(int $processed = 1, ?string $message = null, array $additional_data = []): array
    {
        $response = [
            'success' => true,
            'processed' => $processed,
        ];
        
        if ($message !== null) {
            $response['message'] = $message;
        }
        
        // Fusionar campos adicionales (pueden sobrescribir campos base si es necesario)
        return array_merge($response, $additional_data);
    }
    
    /**
     * Método refactorizado que delega al BatchApiHelper para eliminar duplicación.
     * Mantiene la misma interfaz pública pero usa implementación centralizada.
     * @param int $inicio Índice de inicio del lote (comienza en 1)
     * @param int $fin Índice de fin del lote
     * @return \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta con productos o error
     * @since 2.2.0 Refactorizado para usar BatchApiHelper centralizado
     * @since 2.3.0 Refactorizado para usar validateAndProcessBatchResponse
     * @see \MiIntegracionApi\Helpers\BatchApiHelper::callKnownBatchEndpoint() Para implementación
     */
    protected function get_articulos_batch(int $inicio, int $fin): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
        // Llamada directa a ApiConnector usando el endpoint GetArticulosWS
        $params = [
            'x' => $this->apiConnector->get_session_number(),
            'id_articulo' => 0, // 0 para todos los artículos
            'inicio' => $inicio,
            'fin' => $fin
        ];
        
        $response = $this->apiConnector->get('GetArticulosWS', $params);
        $response_data = $response->getData();
        
        return $this->validateAndProcessBatchResponse(
            $response,
            'get_articulos_batch',
            'GetArticulosWS',
            $inicio,
            $fin,
            $response_data,
            [],
            function($data, $method, $endpoint, $inicio, $fin, $context) {
                return ResponseFactory::success(
                    $data,
                    'Lote de productos obtenido exitosamente',
                    array_merge([
                        'method' => $method,
                        'endpoint' => $endpoint,
                        'inicio' => $inicio,
                        'fin' => $fin,
                        'productos_count' => count($data['Articulos'] ?? []),
                        'has_articulos_key' => isset($data['Articulos'])
                    ], $context)
                );
            }
        );
    }

    /**
     * ⚠️ MÉTODO COMENTADO: Obtención de imágenes por batch (ARQUITECTURA DOS FASES)
     * 
     * Este método se ha comentado porque las imágenes ahora se procesan en una fase
     * separada (Fase 1) antes de sincronizar productos. Las imágenes se buscan
     * desde la media library usando metadatos durante el mapeo.
     *
     * Para rollback, descomentar el cuerpo del método y comentar la nueva lógica.
     *
     * @param   int $inicio Índice de inicio del lote (comienza en 1).
     * @param   int $fin    Índice de fin del lote.
     * @return  \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta con imágenes o error.
     * @since   2.2.0 Refactorizado para usar BatchApiHelper centralizado
     * @since   1.5.0 Comentado en arquitectura dos fases
     */
    protected function get_imagenes_batch(int $inicio, int $fin): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
        // ⚠️ CÓDIGO LEGACY COMENTADO: Obtención de imágenes durante batch
        // Para rollback, descomentar este bloque.
        //
        // Fecha de comentario: 2025-11-04
        // Arquitectura: Dos Fases v1.0
        /*
        // Llamada directa a ApiConnector usando el endpoint GetImagenesArticulosWS
        $params = [
            'x' => $this->apiConnector->get_session_number(),
            'id_articulo' => 0, // 0 para todos los artículos
            'numpixelsladomenor' => 300, // Parámetro requerido para redimensionar imágenes
            'inicio' => $inicio,
            'fin' => $fin
        ];
        
        $response = $this->apiConnector->get('GetImagenesArticulosWS', $params);
        $response_data = $response->getData();
        
        return $this->validateAndProcessBatchResponse(
            $response,
            'get_imagenes_batch',
            'GetImagenesArticulosWS',
            $inicio,
            $fin,
            $response_data,
            [
                'id_articulo' => 0,
                'numpixelsladomenor' => 300
            ],
            function($data, $method, $endpoint, $inicio, $fin, $context) {
                return ResponseFactory::success(
                    $data,
                    'Lote de imágenes obtenido exitosamente',
                    array_merge([
                        'method' => $method,
                        'endpoint' => $endpoint,
                        'inicio' => $inicio,
                        'fin' => $fin,
                        'imagenes_count' => count($data['Imagenes'] ?? []),
                        'has_imagenes_key' => isset($data['Imagenes']),
                        'numpixelsladomenor' => 300
                    ], $context)
                );
            }
        );
        */
        
        // ✅ NUEVO: En arquitectura dos fases, retornar respuesta vacía
        // Las imágenes ya están procesadas y disponibles en la media library
        return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
            ['Imagenes' => []],
            'Método comentado en arquitectura dos fases - imágenes se obtienen desde media library',
            [
                'method' => 'get_imagenes_batch',
                'architecture' => 'two-phase',
                'note' => 'Images are retrieved from media library during mapping phase'
            ]
        );
    }

    /**
     * ⚠️ MÉTODO COMENTADO: Obtención de imágenes por producto (ARQUITECTURA DOS FASES)
     * 
     * Este método se ha comentado porque las imágenes ahora se procesan en una fase
     * separada (Fase 1) antes de sincronizar productos. Las imágenes se buscan
     * desde la media library usando metadatos durante el mapeo.
     *
     * Para rollback, descomentar el cuerpo del método y comentar la nueva lógica.
     *
     * @param   array $product_ids Array de IDs de productos.
     * @return  SyncResponseInterface Respuesta unificada del sistema.
     * @since   1.0.0
     * @since   1.5.0 Migrado a SyncResponseInterface y comentado en arquitectura dos fases
     */
    protected function get_imagenes_for_products(array $product_ids): SyncResponseInterface {
        // ⚠️ CÓDIGO LEGACY COMENTADO: Obtención de imágenes por producto
        // Para rollback, descomentar este bloque.
        //
        // Fecha de comentario: 2025-11-04
        // Arquitectura: Dos Fases v1.0
        /*
        $all_imagenes = [];
        $errors = [];
        
        foreach ($product_ids as $product_id) {
            $params = [
                'x' => $this->apiConnector->get_session_number(),
                'id_articulo' => $product_id, // ID específico del producto
                'numpixelsladomenor' => 300
            ];
            
            $response = $this->apiConnector->get('GetImagenesArticulosWS', $params);
            
            if ($response->isSuccess()) {
                $response_data = $response->getData();
                if (isset($response_data['Imagenes'])) {
                    $all_imagenes = array_merge($all_imagenes, $response_data['Imagenes']);
                }
            } else {
                $errors[] = "Error obteniendo imágenes para producto {$product_id}: " . $response->getMessage();
            }
        }
        
        if (!empty($errors)) {
            return ResponseFactory::error(
                'Errores obteniendo imágenes: ' . implode(', ', $errors),
                HttpStatusCodes::BAD_REQUEST,
                [
                    'endpoint' => 'BatchProcessor::get_imagenes_for_products',
                    'product_ids' => $product_ids,
                    'errors' => $errors,
                    'timestamp' => time()
                ]
            );
        }
        
        return ResponseFactory::success(
            ['Imagenes' => $all_imagenes],
            'Imágenes obtenidas correctamente',
            [
                'endpoint' => 'BatchProcessor::get_imagenes_for_products',
                'product_count' => count($product_ids),
                'image_count' => count($all_imagenes),
                'timestamp' => time()
            ]
        );
        */
        
        // ✅ NUEVO: En arquitectura dos fases, retornar respuesta vacía
        // Las imágenes ya están procesadas y disponibles en la media library
        return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
            ['Imagenes' => []],
            'Método comentado en arquitectura dos fases - imágenes se obtienen desde media library',
            [
                'endpoint' => 'BatchProcessor::get_imagenes_for_products',
                'architecture' => 'two-phase',
                'product_count' => count($product_ids),
                'note' => 'Images are retrieved from media library during mapping phase'
            ]
        );
    }

    /**
     * Obtiene imágenes para IDs específicos de productos (MÉTODO LEGACY)
     *
     * @param array $product_ids Array de IDs de productos
     * @return array Array con las imágenes o array vacío en caso de error
     * @deprecated Usar get_imagenes_for_products() que devuelve SyncResponseInterface
     */
    protected function get_imagenes_for_products_legacy(array $product_ids): array {
        $all_imagenes = [];
        $errors = [];
        
        foreach ($product_ids as $product_id) {
            $params = [
                'x' => $this->apiConnector->get_session_number(),
                'id_articulo' => $product_id, // ID específico del producto
                'numpixelsladomenor' => 300
            ];
            
            $response = $this->apiConnector->get('GetImagenesArticulosWS', $params);
            
            if ($response->isSuccess()) {
                $response_data = $response->getData();
                if (isset($response_data['Imagenes'])) {
                    $all_imagenes = array_merge($all_imagenes, $response_data['Imagenes']);
                }
            } else {
                $errors[] = "Error obteniendo imágenes para producto {$product_id}: " . $response->getMessage();
                $this->getLogger()->warning('Error en método legacy obteniendo imágenes', [
                    'product_id' => $product_id,
                    'error' => $response->getMessage(),
                    'error_code' => $response->getCode()
                ]);
            }
        }
        
        // Si hay errores, logearlos pero devolver array vacío para mantener compatibilidad
        if (!empty($errors)) {
            $this->getLogger()->warning('Errores en método legacy get_imagenes_for_products_legacy', [
                'errors' => $errors,
                'product_ids' => $product_ids
            ]);
        }
        
        return ['Imagenes' => $all_imagenes];
    }

    /**
     * ✅ REFACTORIZADO: Obtiene stock del lote usando BatchApiHelper centralizado
     * @param int $inicio Índice de inicio del lote (comienza en 1)
     * @param int $fin Índice de fin del lote
     * @return \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta con stock o error
     * @since 2.2.0 Refactorizado para usar BatchApiHelper centralizado
     */
    protected function get_stock_batch(int $inicio, int $fin): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
        // Llamada directa a ApiConnector usando el endpoint GetStockArticulosWS
        $params = [
            'x' => $this->apiConnector->get_session_number(),
            'id_articulo' => 0, // 0 para todos los artículos
            'art_inicio' => $inicio,
            'art_fin' => $fin
        ];
        
        $response = $this->apiConnector->get('GetStockArticulosWS', $params);
        $response_data = $response->getData();
        
        return $this->validateAndProcessBatchResponse(
            $response,
            'get_stock_batch',
            'GetStockArticulosWS',
            $inicio,
            $fin,
            $response_data,
            [
                'id_articulo' => 0,
                'art_inicio' => $inicio,
                'art_fin' => $fin
            ],
            function($data, $method, $endpoint, $inicio, $fin, $context) {
                return ResponseFactory::success(
                    $data,
                    'Lote de stock obtenido exitosamente',
                    array_merge([
                        'method' => $method,
                        'endpoint' => $endpoint,
                        'inicio' => $inicio,
                        'fin' => $fin,
                        'stock_count' => count($data['Stock'] ?? $data['StockArticulos'] ?? []),
                        'has_stock_key' => isset($data['Stock']) || isset($data['StockArticulos']),
                        'id_articulo' => 0
                    ], $context)
                );
            }
        );
    }

    /**
     * ✅ REFACTORIZADO: Obtiene condiciones de tarifa del lote usando BatchApiHelper centralizado
     * @param int $inicio Índice de inicio del lote (comienza en 1)
     * @param int $fin Índice de fin del lote
     * @return \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta con condiciones o error
     * @since 2.2.0 Refactorizado para usar BatchApiHelper centralizado
     */
    protected function get_condiciones_batch(int $inicio, int $fin): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
        // Llamada directa a ApiConnector usando el endpoint GetCondicionesTarifaWS
        $params = [
            'x' => $this->apiConnector->get_session_number(),
            'id_articulo' => 0, // 0 para todos los artículos
            'id_cliente' => 0, // 0 para tarifa general
            'art_inicio' => $inicio,
            'art_fin' => $fin
        ];
        
        $response = $this->apiConnector->get('GetCondicionesTarifaWS', $params);
        $response_data = $response->getData();
        
        return $this->validateAndProcessBatchResponse(
            $response,
            'get_condiciones_batch',
            'GetCondicionesTarifaWS',
            $inicio,
            $fin,
            $response_data,
            [
                'id_articulo' => 0,
                'id_cliente' => 0,
                'art_inicio' => $inicio,
                'art_fin' => $fin
            ],
            function($data, $method, $endpoint, $inicio, $fin, $context) {
                return ResponseFactory::success(
                    $data,
                    'Lote de condiciones de tarifa obtenido exitosamente',
                    array_merge([
                        'method' => $method,
                        'endpoint' => $endpoint,
                        'inicio' => $inicio,
                        'fin' => $fin,
                        'condiciones_count' => count($data['CondicionesTarifa'] ?? []),
                        'has_condiciones_key' => isset($data['CondicionesTarifa']),
                        'id_articulo' => 0,
                        'id_cliente' => 0
                    ], $context)
                );
            }
        );
    }
    
    /**
     * Convierte condiciones_tarifa a batch_prices para MapProduct
     * @param array $condiciones_tarifa Array de condiciones de tarifa
     * @return array Array indexado por ID_Articulo
     */
    protected function convert_condiciones_to_batch_prices(array $condiciones_tarifa): array {
        $batch_prices = [];
        
        foreach ($condiciones_tarifa as $condicion) {
            if (isset($condicion['ID_Articulo']) && is_numeric($condicion['ID_Articulo'])) {
                $id_articulo = (int)$condicion['ID_Articulo'];
                $batch_prices[$id_articulo] = $condicion;
            }
        }
        
        return $batch_prices;
    }

    /**
     * Obtiene árboles de campos configurables usando GetArbolCamposConfigurablesArticulosWS
     * NOTA: Implementado según manual de Verial. Los endpoints pueden devolver arrays vacíos
     * pero deben mantenerse ya que son requeridos por la especificación de la API.
     * @return array Array de árboles indexado por id_campo (puede estar vacío)
     */
    protected function get_arboles_campos_batch(): array {
        try {
            // Obtener campos configurables ya disponibles en batch_data para evitar doble llamada
            $campos_configurables = $this->batch_data['campos_configurables'] ?? null;
            
            // Si no están disponibles, obtenerlos directamente
            if (!$campos_configurables) {
                $campos_configurables_response = $this->apiConnector->get('GetCamposConfigurablesArticulosWS');
                if (!$campos_configurables_response->isSuccess()) {
                    $this->getLogger()->debug('Error obteniendo campos configurables para árboles', [
                        'error' => $campos_configurables_response->getMessage(),
                        'code' => $campos_configurables_response->getCode()
                    ]);
                    return [];
                }
                $campos_configurables = $campos_configurables_response->getData();
            }
            
            // ✅ REFACTORIZADO: Usar helper centralizado para verificar si está vacío
            if ($this->isEmptyArrayValue($campos_configurables, 'Campos')) {
                $this->getLogger()->debug('No hay campos configurables disponibles para obtener árboles');
                return [];
            }

            $arboles_por_campo = [];
            $campos = $campos_configurables['Campos'];
            
            // Obtener árbol para cada campo configurable (según especificación API)
            foreach ($campos as $campo) {
                $id_campo = $campo['Id'] ?? null;
                $id_familia = $campo['ID_FamiliaCamposConfigurables'] ?? 0;
                
                if (!$id_campo) {
                    continue;
                }

                $response = $this->apiConnector->get('GetArbolCamposConfigurablesArticulosWS', [
                    'id_campo' => $id_campo,
                    'id_familiacamposconfigurables' => $id_familia
                ]);
                
                if ($response->isSuccess()) {
                    $response_data = $response->getData();
                    if (isset($response_data['RamasArbol'])) {
                        $arboles_por_campo[$id_campo] = $response_data['RamasArbol'];
                        
                        if (!empty($response_data['RamasArbol'])) {
                            $this->getLogger()->debug('Árbol obtenido para campo configurable', [
                                'id_campo' => $id_campo,
                                'ramas_count' => count($response_data['RamasArbol'])
                            ]);
                        }
                    }
                } else {
                    $this->getLogger()->warning('Error obteniendo árbol para campo configurable', [
                        'id_campo' => $id_campo,
                        'error' => $response->getMessage(),
                        'error_code' => $response->getCode()
                    ]);
                    // Mantener entrada vacía para consistencia
                    $arboles_por_campo[$id_campo] = [];
                }
            }

            return $arboles_por_campo;

        } catch (Exception $e) {
            $this->getLogger()->warning('Error obteniendo árboles de campos configurables', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Obtiene valores validados usando GetValoresValidadosCampoConfigurableArticulosWS
     * NOTA: Implementado según manual de Verial. Los endpoints pueden devolver arrays vacíos
     * pero deben mantenerse ya que son requeridos por la especificación de la API.
     * @return array Array de valores validados indexado por id_campo (puede estar vacío)
     */
    protected function get_valores_validados_batch(): array {
        try {
            // Obtener campos configurables ya disponibles en batch_data para evitar doble llamada
            $campos_configurables = $this->batch_data['campos_configurables'] ?? null;
            
            // Si no están disponibles, obtenerlos directamente
            if (!$campos_configurables) {
                $campos_configurables_response = $this->apiConnector->get('GetCamposConfigurablesArticulosWS');
                if (!$campos_configurables_response->isSuccess()) {
                    $this->getLogger()->debug('Error obteniendo campos configurables para valores validados', [
                        'error' => $campos_configurables_response->getMessage(),
                        'code' => $campos_configurables_response->getCode()
                    ]);
                    return [];
                }
                $campos_configurables = $campos_configurables_response->getData();
            }
            
            // ✅ REFACTORIZADO: Usar helper centralizado para verificar si está vacío
            if ($this->isEmptyArrayValue($campos_configurables, 'Campos')) {
                $this->getLogger()->debug('No hay campos configurables disponibles para obtener valores validados');
                return [];
            }

            $valores_por_campo = [];
            $campos = $campos_configurables['Campos'];
            
            // Obtener valores validados para cada campo configurable (según especificación API)
            foreach ($campos as $campo) {
                $id_campo = $campo['Id'] ?? null;
                $id_familia = $campo['ID_FamiliaCamposConfigurables'] ?? 0;
                $validado = $campo['Validado'] ?? false;
                
                if (!$id_campo || !$validado) {
                    // Solo procesar campos que están marcados como validados
                    continue;
                }

                $response = $this->apiConnector->get('GetValoresValidadosCampoConfigurableArticulosWS', [
                    'id_campo' => $id_campo,
                    'id_familiacamposconfigurables' => $id_familia
                ]);
                
                if ($response->isSuccess()) {
                    $response_data = $response->getData();
                    if (isset($response_data['Valores'])) {
                        $valores_por_campo[$id_campo] = $response_data['Valores'];
                        
                        if (!empty($response_data['Valores'])) {
                            $this->getLogger()->debug('Valores validados obtenidos para campo configurable', [
                                'id_campo' => $id_campo,
                                'valores_count' => count($response_data['Valores'])
                            ]);
                        }
                    }
                } else {
                    $this->getLogger()->warning('Error obteniendo valores para campo configurable', [
                        'id_campo' => $id_campo,
                        'error' => $response->getMessage(),
                        'error_code' => $response->getCode()
                    ]);
                    // Mantener entrada vacía para consistencia
                    $valores_por_campo[$id_campo] = [];
                }
            }

            return $valores_por_campo;

        } catch (Exception $e) {
            $this->getLogger()->warning('Error obteniendo valores validados de campos configurables', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Prepara datos completos del batch para sincronización de productos
     * @param int $inicio Índice de inicio del lote (comienza en 1)
     * @param int $fin Índice de fin del lote
     * @return array Datos completos del batch preparados
     */
    protected function prepare_complete_batch_data(int $inicio, int $fin): array {
        // ✅ OPTIMIZACIÓN 1: Cache Key Determinístico (NO microtime)
        // Generar ID determinístico basado en hora actual para caché efectivo
        $time_bucket = date('Y-m-d-H'); // Agrupar por hora
        $batch_id = "batch_data_{$inicio}_{$fin}_$time_bucket";
        $rango = ['inicio' => $inicio, 'fin' => $fin];
        
        // Verificar si ya existe en caché usando CacheManager existente
        $cache_manager = CacheManager::get_instance();
        $cached_batch = $cache_manager->get_batch_data($batch_id);
        
        if ($cached_batch !== false && is_array($cached_batch)) {
            // ✅ VERIFICACIÓN: Comprobar si las imágenes del caché están distribuidas correctamente
            // Si todas las imágenes son de un solo producto, necesitamos regenerar con fallback
            $imagenes_cached = $cached_batch['imagenes_productos'] ?? [];
            if (!empty($imagenes_cached) && is_array($imagenes_cached)) {
                // ✅ REFACTORIZADO: Usar helper centralizado para validación de imágenes
                $productos_en_lote = array_column($cached_batch['productos'] ?? [], 'Id');
                $validation = $this->validateImagePaginationResult(
                    $imagenes_cached,
                    $productos_en_lote,
                    [
                        'min_unique_products' => 3,
                        'strict_mode' => true
                    ]
                );

                if (!$validation['is_valid']) {
                    $this->getLogger()->warning('Caché de imágenes inválido (pocos productos únicos), regenerando con fallback', [
                        'batch_id' => $batch_id,
                        'productos_unicos_en_cache' => $validation['productos_unicos_count'],
                        'productos_en_lote' => count($productos_en_lote),
                        'total_imagenes_cache' => count($imagenes_cached),
                        'reason' => $validation['reason']
                    ]);
                    
                    // Invalidar este batch del caché para forzar regeneración
                    $cache_manager->delete_batch_data($batch_id);
                    $cached_batch = false; // Continuar con la generación
                } else {
                    // El caché es válido
                    $this->logBatchCacheHit($batch_id, $inicio, $fin, $rango, [
                        'imagenes_productos_unicos' => $validation['productos_unicos_count']
                    ]);
                    return $cached_batch;
                }
            } else {
                // No hay imágenes en caché, es válido
                $this->logBatchCacheHit($batch_id, $inicio, $fin, $rango);
                return $cached_batch;
            }
        }
        
        // Inicializar estructura de datos del batch
        $batch_data = [
            'batch_id' => $batch_id,
            'rango' => $rango,
            'timestamp' => time(),
            'status' => 'preparing'
        ];

        // Registrar inicio de preparación del batch
        $this->getLogger()->info(
            sprintf('🚀 Iniciando preparación de batch completo (inicio=%d, fin=%d)', $inicio, $fin),
            [
                'batch_id' => $batch_data['batch_id'],
                'rango' => $batch_data['rango'],
                'timestamp' => $batch_data['timestamp']
            ]
        );

        try {
            // === PASO 1: OBTENER DATOS CRÍTICOS ===
            
            // 1.1 GetNumArticulosWS - CANTIDAD TOTAL (CRÍTICO) ✅ CON CACHÉ
            $total_productos_data = $this->getCachedGlobalData('total_productos', function() {
                $response = $this->apiConnector->get('GetNumArticulosWS');
                // ✅ REFACTORIZADO: Usar método helper para manejo consistente
                return $this->handleApiResponse($response, 'GetNumArticulosWS', 'throw');
            }, $this->getGlobalDataTTL('total_productos'));
            
            // Verificar si hubo error crítico: getCachedGlobalData retornaría [] si el callback lanzó Exception
            // Si está vacío o no contiene datos válidos de NumArticulos, lanzar Exception
            if (empty($total_productos_data) || (!isset($total_productos_data['Numero']) && !isset($total_productos_data['NumArticulos']) && !isset($total_productos_data['num_articulos']))) {
                throw new Exception('Error crítico obteniendo cantidad total de productos: No se pudo obtener el número de productos desde la API');
            }
            
            $batch_data['total_productos'] = $total_productos_data;

            // 1.2 GetStockArticulosWS - STOCK SIMPLIFICADO CON CACHÉ
            // ✅ OPTIMIZACIÓN: Usar sistema de caché existente del ApiConnector
            $stock_result = $this->apiConnector->get('GetStockArticulosWS', ['id_articulo' => 0], [
                'use_cache' => true,
                'cache_ttl' => 3600, // 1 hora TTL (según CacheConfig)
                'force_refresh' => false
            ]);
            // ✅ REFACTORIZADO: Usar método helper para manejo consistente
            $batch_data['stock_productos'] = $this->handleApiResponse($stock_result, 'GetStockArticulosWS', 'throw');
            
            // Stock procesado
            
            // Logging simplificado - contar artículos con stock > 0
            $stock_count = 0;
            $total_stock_items = 0;
            
            if (isset($batch_data['stock_productos']['Stock']) && is_array($batch_data['stock_productos']['Stock'])) {
                $total_stock_items = count($batch_data['stock_productos']['Stock']);
                // Contar solo artículos con stock > 0
                $stock_count = count(array_filter($batch_data['stock_productos']['Stock'], function($item) {
                    return isset($item['Stock']) && $item['Stock'] > 0;
                }));
            } elseif (isset($batch_data['stock_productos']['StockArticulos']) && is_array($batch_data['stock_productos']['StockArticulos'])) {
                $total_stock_items = count($batch_data['stock_productos']['StockArticulos']);
                // Contar solo artículos con stock > 0
                $stock_count = count(array_filter($batch_data['stock_productos']['StockArticulos'], function($item) {
                    return isset($item['Stock']) && $item['Stock'] > 0;
                }));
            }
            
            $this->getLogger()->info(
                sprintf('✅ Stock obtenido con caché: %d items con stock > 0 (de %d total)', $stock_count, $total_stock_items),
                [
                    'batch_id' => $batch_id,
                    'rango' => $rango,
                    'cache_optimization' => 'enabled',
                    'cache_ttl' => '3600s'
                ]
            );

            // 1.3 GetArticulosWS - DATOS COMPLETOS de productos del lote específico
            $articulos_response = $this->get_articulos_batch($inicio, $fin);
            if (!$articulos_response->isSuccess()) {
                throw new Exception('Error obteniendo productos del lote: ' . $articulos_response->getMessage());
            }
            
            // Respuesta de API procesada
            $articulos_data = $articulos_response->getData();
            
            // Verificar que la respuesta tiene la estructura correcta
            if (is_array($articulos_data)) {
                // Si la respuesta tiene 'body' (formato ApiConnector), decodificar el JSON
                if (isset($articulos_data['body'])) {
                    $json_data = json_decode($articulos_data['body'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new Exception('Error decodificando JSON de productos: ' . json_last_error_msg());
                    }
                    
                    // ✅ REFACTORIZADO: Usar helper centralizado para validar array (con excepción)
                    $this->validateArrayOrError(
                        $json_data ?? [],
                        'JSON decodificado de body',
                        'json_decoding',
                        ['source' => 'body'],
                        true // Lanzar excepción
                    );
                    
                    // Logging detallado del JSON decodificado
                    $this->logger->debug('JSON decodificado de body', [
                        'tipo' => gettype($json_data),
                        'es_array' => is_array($json_data),
                        'keys' => is_array($json_data) ? array_keys($json_data) : 'no_array',
                        'contenido_completo' => $json_data
                    ]);
                    
                    $articulos_data = $json_data;
                }
                // Si la respuesta tiene 'contenido_body' (formato legacy), decodificar el JSON
                elseif (isset($articulos_data['contenido_body'])) {
                    $json_data = json_decode($articulos_data['contenido_body'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new Exception('Error decodificando JSON de productos: ' . json_last_error_msg());
                    }
                    
                    // ✅ REFACTORIZADO: Usar helper centralizado para validar array (con excepción)
                    $this->validateArrayOrError(
                        $json_data ?? [],
                        'JSON decodificado',
                        'json_decoding',
                        ['source' => 'contenido_body'],
                        true // Lanzar excepción
                    );
                    
                    // Logging detallado del JSON decodificado
                    $this->logger->debug('JSON decodificado de contenido_body', [
                        'tipo' => gettype($json_data),
                        'es_array' => is_array($json_data),
                        'keys' => is_array($json_data) ? array_keys($json_data) : 'no_array',
                        'contenido_completo' => $json_data
                    ]);
                    
                    $articulos_data = $json_data;
                }
                
                // Ahora verificar que tenemos los productos - buscar en diferentes ubicaciones posibles
                $productos = null;
                
                // ✅ REFACTORIZADO: Validar InfoError usando método helper
                $this->validateInfoError($articulos_data, 'GetArticulosWS');
                
                // Buscar en diferentes claves posibles según la documentación de Verial
                if (isset($articulos_data['Articulos'])) {
                    $productos = $articulos_data['Articulos'];
                } elseif (isset($articulos_data['articulos'])) {
                    $productos = $articulos_data['articulos'];
                } elseif (isset($articulos_data['data'])) {
                    $productos = $articulos_data['data'];
                } elseif (is_array($articulos_data) && !empty($articulos_data)) {
                    // Si la respuesta es directamente un array de productos
                    $productos = $articulos_data;
                }
                
                if (is_array($productos ?? [])) {
                    $batch_data['productos'] = $productos;
                    // Productos extraídos correctamente
                } else {
                    $this->logger->error('No se encontraron productos en respuesta decodificada', [
                        'keys_disponibles' => array_keys($articulos_data),
                        'tipo_respuesta' => gettype($articulos_data),
                        'contenido_respuesta' => $articulos_data
                    ]);
                    throw new Exception('Respuesta de API inválida para productos: no se encontraron productos en ninguna ubicación esperada');
                }
            } else {
                $this->logger->error('Respuesta de API inválida para productos', [
                    'tipo' => gettype($articulos_data),
                    'contenido' => $articulos_data
                ]);
                throw new Exception('Respuesta de API inválida para productos: no es un array');
            }

            // ⚠️ CÓDIGO COMENTADO: Obtención de imágenes durante batch (ARQUITECTURA DOS FASES)
            // Este código se ha comentado porque las imágenes ahora se procesan en una fase
            // separada (Fase 1) antes de sincronizar productos. Las imágenes se buscan
            // desde la media library usando metadatos durante el mapeo.
            //
            // Para rollback, descomentar este bloque y comentar la nueva lógica.
            //
            // Fecha de comentario: 2025-11-04
            // Arquitectura: Dos Fases v1.0
            /*
            // 1.4 GetImagenesArticulosWS - IMÁGENES de productos del lote específico (usar paginación por rango)
            $imagenes_response = $this->get_imagenes_batch($inicio, $fin);
            // ✅ REFACTORIZADO: Verificar respuesta usando método helper (pero con comportamiento especial de fallback)
            if (!$imagenes_response->isSuccess()) {
                // Fallback: si falla la paginación, hacer por producto para no bloquear el lote
                $this->getLogger()->warning('Fallo en paginación de imágenes, aplicando fallback por producto', [
                    'inicio' => $inicio,
                    'fin' => $fin,
                    'error' => $imagenes_response->getMessage(),
                ]);
                $product_ids = array_column($batch_data['productos'], 'Id');
                $imagenes_fallback = $this->get_imagenes_for_products($product_ids);
                if (!$imagenes_fallback->isSuccess()) {
                    $this->getLogger()->error('Error obteniendo imágenes con fallback por producto', [
                        'error' => $imagenes_fallback->getMessage(),
                        'product_ids_count' => count($product_ids),
                    ]);
                    $batch_data['imagenes_productos'] = [];
                } else {
                    $imagenes_data = $imagenes_fallback->getData();
                    $batch_data['imagenes_productos'] = $imagenes_data['Imagenes'] ?? [];
                    $this->getLogger()->info('Imágenes obtenidas con fallback por producto', [
                        'product_ids_count' => count($product_ids),
                        'total_imagenes' => count($batch_data['imagenes_productos'])
                    ]);
                }
            } else {
                $imagenes_data = $imagenes_response->getData();
                $imagenes_array = $imagenes_data['Imagenes'] ?? [];
                
                // ✅ REFACTORIZADO: Usar helper centralizado para validación de paginación
                if (!empty($imagenes_array)) {
                    $productos_en_lote = array_column($batch_data['productos'], 'Id');
                    
                    $validation = $this->validateImagePaginationResult(
                        $imagenes_array,
                        $productos_en_lote,
                        [
                            'min_unique_products' => max(2, count($productos_en_lote) / 10),
                            'strict_mode' => true
                        ]
                    );

                    if ($validation['needs_fallback']) {
                        // Determinar mensaje de log según la razón
                        if ($validation['reason'] === 'single_product_detected') {
                            $this->getLogger()->warning('Paginación devolvió imágenes de un solo producto, usando fallback', [
                                'inicio' => $inicio,
                                'fin' => $fin,
                                'producto_unico' => reset($validation['productos_unicos']),
                                'total_imagenes' => count($imagenes_array)
                            ]);
                        } else {
                            $this->getLogger()->warning('Paginación de imágenes devolvió pocos productos únicos, usando fallback', [
                                'inicio' => $inicio,
                                'fin' => $fin,
                                'total_imagenes' => count($imagenes_array),
                                'productos_unicos_en_imagenes' => $validation['productos_unicos_count'],
                                'productos_en_lote' => count($productos_en_lote),
                                'productos_coincidentes' => $validation['coincident_products'] ?? 0,
                                'reason' => $validation['reason']
                            ]);
                        }
                        
                        // Usar fallback para obtener imágenes por producto
                        $product_ids = array_column($batch_data['productos'], 'Id');
                        $imagenes_fallback = $this->get_imagenes_for_products($product_ids);
                        if ($imagenes_fallback->isSuccess()) {
                            $imagenes_fallback_data = $imagenes_fallback->getData();
                            $batch_data['imagenes_productos'] = $imagenes_fallback_data['Imagenes'] ?? [];
                            $this->getLogger()->info('Imágenes obtenidas con fallback por producto (paginación incompleta)', [
                                'product_ids_count' => count($product_ids),
                                'total_imagenes' => count($batch_data['imagenes_productos'])
                            ]);
                        } else {
                            // Si el fallback falla, usar las imágenes obtenidas de la paginación aunque sean limitadas
                            $batch_data['imagenes_productos'] = $imagenes_array;
                            $this->getLogger()->warning('Fallback falló, usando imágenes limitadas de paginación', [
                                'error' => $imagenes_fallback->getMessage(),
                                'imagenes_obtenidas' => count($imagenes_array)
                            ]);
                        }
                    } else {
                        // La paginación funcionó correctamente
                        $batch_data['imagenes_productos'] = $imagenes_array;
                        $this->getLogger()->info('Imágenes obtenidas de la API con paginación', [
                            'inicio' => $inicio,
                            'fin' => $fin,
                            'total_imagenes' => count($batch_data['imagenes_productos']),
                            'productos_unicos' => $validation['productos_unicos_count'],
                            'productos_en_lote' => count($productos_en_lote)
                        ]);
                    }
                } else {
                    // No hay imágenes en la respuesta
                    $batch_data['imagenes_productos'] = [];
                    $this->getLogger()->info('No se obtuvieron imágenes del lote', [
                        'inicio' => $inicio,
                        'fin' => $fin
                    ]);
                }
            }
            */

            // ✅ NUEVO: Arquitectura en dos fases
            // Las imágenes ya están procesadas en la Fase 1 y disponibles en la media library.
            // No es necesario obtenerlas aquí. Se buscarán durante el mapeo usando metadatos.
            $this->logger->debug('Sincronización en dos fases: imágenes omitidas en batch', [
                'inicio' => $inicio,
                'fin' => $fin,
                'nota' => 'Imágenes se buscarán desde media library durante mapeo'
            ]);
            
            // Inicializar array vacío para compatibilidad con código existente
            $batch_data['imagenes_productos'] = [];

            // 1.5 GetCondicionesTarifaWS - CONDICIONES de productos del lote específico
            $condiciones_response = $this->get_condiciones_batch($inicio, $fin);
            
            // DEBUG: Log detallado para diagnosticar condiciones de tarifa
            $this->logger->debug('Verificando respuesta de GetCondicionesTarifaWS', [
                'inicio' => $inicio,
                'fin' => $fin,
                'is_success' => $condiciones_response->isSuccess(),
                'error_message' => $condiciones_response->isSuccess() ? 'N/A' : $condiciones_response->getMessage(),
                'is_array' => is_array($condiciones_response->getData()),
                'count' => is_array($condiciones_response->getData()) ? count($condiciones_response->getData()) : 'N/A',
                'first_item' => is_array($condiciones_response->getData()) && !empty($condiciones_response->getData()) ? array_keys($condiciones_response->getData())[0] : 'N/A'
            ]);
            
            // ✅ REFACTORIZADO: Usar método helper con comportamiento 'warn' (log warning y retornar [])
            $batch_data['condiciones_tarifa'] = $this->handleApiResponse(
                $condiciones_response,
                'GetCondicionesTarifaWS',
                'warn',
                ['inicio' => $inicio, 'fin' => $fin]
            );
            
            // Convertir condiciones_tarifa a batch_prices para MapProduct
            // Extraer solo el array de CondicionesTarifa de la respuesta de la API
            $condiciones_array = $batch_data['condiciones_tarifa']['CondicionesTarifa'] ?? $batch_data['condiciones_tarifa'];
            $batch_data['batch_prices'] = $this->convert_condiciones_to_batch_prices($condiciones_array);

            // ✅ OPTIMIZACIÓN 4: Sistema de Caché Diferenciado para Datos Globales
            // === PASO 2: OBTENER DATOS GLOBALES CON CACHÉ DIFERENCIADO ===
            
            // Obtener datos globales directamente (simplificado)
            $batch_data['categorias'] = $this->getCachedGlobalData('categorias', function() {
                $categorias_response = $this->apiConnector->get('GetCategoriasWS');
                // ✅ REFACTORIZADO: Usar método helper con logging automático
                return $this->handleApiResponse($categorias_response, 'GetCategoriasWS', 'empty');
            }, $this->getGlobalDataTTL('categorias'));
            
            // ✅ REFACTORIZADO: Usar método helper para todas las respuestas de datos globales
            $batch_data['fabricantes'] = $this->getCachedGlobalData('fabricantes', function() {
                return $this->handleApiResponse(
                    $this->apiConnector->get('GetFabricantesWS'),
                    'GetFabricantesWS',
                    'empty'
                );
            }, $this->getGlobalDataTTL('fabricantes'));
            
            $batch_data['colecciones'] = $this->getCachedGlobalData('colecciones', function() {
                return $this->handleApiResponse(
                    $this->apiConnector->get('GetColeccionesWS'),
                    'GetColeccionesWS',
                    'empty'
                );
            }, $this->getGlobalDataTTL('colecciones'));
            
            $batch_data['cursos'] = $this->getCachedGlobalData('cursos', function() {
                return $this->handleApiResponse(
                    $this->apiConnector->get('GetCursosWS'),
                    'GetCursosWS',
                    'empty'
                );
            }, $this->getGlobalDataTTL('cursos'));
            
            $batch_data['asignaturas'] = $this->getCachedGlobalData('asignaturas', function() {
                return $this->handleApiResponse(
                    $this->apiConnector->get('GetAsignaturasWS'),
                    'GetAsignaturasWS',
                    'empty'
                );
            }, $this->getGlobalDataTTL('asignaturas'));
            
            // Obtener datos globales adicionales
            $batch_data['categorias_web'] = $this->getCachedGlobalData('categorias_web', function() {
                $categorias_web_response = $this->apiConnector->get('GetCategoriasWebWS');
                // ✅ REFACTORIZADO: Usar método helper con logging automático
                $response_data = $this->handleApiResponse($categorias_web_response, 'GetCategoriasWebWS', 'empty');
                
                if (!empty($response_data)) {
                    $this->getLogger()->info('Categorías web obtenidas de API GetCategoriasWebWS', [
                        'response_type' => gettype($response_data),
                        'has_categorias_key' => isset($response_data['Categorias']),
                        'categorias_web_count' => count($response_data['Categorias'] ?? []),
                        'response_keys' => array_keys($response_data),
                        'first_categoria_web' => $response_data['Categorias'][0] ?? null
                    ]);
                    return $response_data;
                }
            }, $this->getGlobalDataTTL('categorias_web'));
            
            // ✅ REFACTORIZADO: Usar método helper
            $batch_data['campos_configurables'] = $this->getCachedGlobalData('campos_configurables', function() {
                return $this->handleApiResponse(
                    $this->apiConnector->get('GetCamposConfigurablesArticulosWS'),
                    'GetCamposConfigurablesArticulosWS',
                    'empty'
                );
            }, $this->getGlobalDataTTL('campos_configurables'));

            // === PASO 3: OBTENER DATOS AUXILIARES (2 endpoints) ===
            
            // 3.1 GetArbolCamposConfigurablesArticulosWS - Árboles de campos configurables
            $arboles_campos_response = $this->get_arboles_campos_batch();
            if (is_wp_error($arboles_campos_response)) {
                $batch_data['arboles_campos'] = [];
            } else {
                $batch_data['arboles_campos'] = $arboles_campos_response;
            }

            // 3.2 GetValoresValidadosCampoConfigurableArticulosWS - Valores validados
            $valores_validados_response = $this->get_valores_validados_batch();
            if (is_wp_error($valores_validados_response)) {
                $batch_data['valores_validados'] = [];
            } else {
                $batch_data['valores_validados'] = $valores_validados_response;
            }

            // === PASO 4: PROCESAR Y INDEXAR DATOS ===
            
            // Indexar categorías por ID para acceso rápido (formato simple: id => nombre)
            $batch_data['categorias_indexed'] = $this->index_categorias($batch_data['categorias']);
            $batch_data['categorias_web_indexed'] = $this->index_categorias($batch_data['categorias_web']);
            
            // Indexar fabricantes por ID
            $batch_data['fabricantes_indexed'] = $this->index_fabricantes($batch_data['fabricantes']);
            
            // Indexar colecciones por ID
            $batch_data['colecciones_indexed'] = $this->index_colecciones($batch_data['colecciones']);
            
            // Indexar cursos por ID
            $batch_data['cursos_indexed'] = $this->index_cursos($batch_data['cursos']);
            
            // Indexar asignaturas por ID
            $batch_data['asignaturas_indexed'] = $this->index_asignaturas($batch_data['asignaturas']);

            // Marcar batch como completado exitosamente
            $batch_data['status'] = 'completed';
            $batch_data['completion_time'] = microtime(true);

            // Guardar batch en caché persistente
            $cache_manager = CacheManager::get_instance();
            $cache_saved = $cache_manager->store_batch_data($batch_data['batch_id'], $batch_data, $rango);


            return $batch_data;

        } catch (Exception $e) {
            // Marcar batch como fallido
            $batch_data['status'] = 'failed';
            $batch_data['error'] = $e->getMessage();
            $batch_data['error_time'] = microtime(true);

            // Registrar error de preparación del batch
            $this->getLogger()->error(
                sprintf('❌ Error preparando batch completo (inicio=%d, fin=%d): %s', $inicio, $fin, $e->getMessage()),
                [
                    'batch_id' => $batch_data['batch_id'],
                    'error' => $e->getMessage(),
                    'error_time' => $batch_data['error_time'],
                    'trace' => $e->getTraceAsString()
                ]
            );

            return $batch_data;
        }
    }


    /**
     * ✅ MÉTODO HELPER: Obtiene datos globales con caché diferenciado
     */
    private function getCachedGlobalData(string $data_type, callable $fetch_callback, int $ttl = 3600): array
    {
        $cache_manager = CacheManager::get_instance();
        
        // Cache key determinístico para datos globales
        $time_bucket = intval(time() / $ttl) * $ttl;
        $cache_key = "global_{$data_type}_$time_bucket";
        
        // Intentar obtener de caché
        $cached_data = $cache_manager->get($cache_key);
        
        if ($cached_data !== false && is_array($cached_data)) {
            return $cached_data;
        }
        
        // Cache miss: obtener datos frescos
        try {
            $fresh_data = $fetch_callback();
            
            if (!is_wp_error($fresh_data) && is_array($fresh_data)) {
                // ✅ GUARDAR EN CACHÉ para futuras consultas
                $cache_manager->set($cache_key, $fresh_data, $ttl);
                return $fresh_data;
            }
            
            // Manejo de errores según criticidad
            if (is_wp_error($fresh_data)) {
                $this->getLogger()->warning(
                    "Datos globales $data_type con error: " . $fresh_data->get_error_message(),
                    [
                        'data_type' => $data_type,
                        'cache_key' => $cache_key,
                        'error_code' => $fresh_data->get_error_code(),
                        'error_data' => $fresh_data->get_error_data()
                    ]
                );
            }
            
            return [];
            
        } catch (Exception $e) {
            $this->getLogger()->warning(
                "Error obteniendo datos globales $data_type: " . $e->getMessage(),
                ['data_type' => $data_type, 'cache_key' => $cache_key]
            );
            return [];
        }
    }

    /**
     * ✅ MÉTODO HELPER: Obtiene TTL según tipo de dato global
     * 
     * ✅ MEJORADO: Usa CacheManager::getEndpointTTL() para obtener TTL configurado por endpoint
     */
    private function getGlobalDataTTL(string $data_type): int
    {
        // ✅ MEJORADO: Mapeo de tipos de dato a endpoints para usar TTL por endpoint
        $endpoint_mapping = [
            'total_productos' => 'GetNumArticulosWS',
            'categorias' => 'GetCategoriasWS',
            'fabricantes' => 'GetFabricantesWS',
            'articulos' => 'GetArticulosWS',
            'imagenes' => 'GetImagenesArticulosWS',
            'condiciones_tarifa' => 'GetCondicionesTarifaWS'
        ];
        
        // Si el tipo de dato mapea a un endpoint, usar TTL del endpoint
        if (isset($endpoint_mapping[$data_type])) {
            try {
                $cache_manager = CacheManager::get_instance();
                $endpoint_ttl = $cache_manager->getEndpointTTL($endpoint_mapping[$data_type]);
                
                // Si retorna 0, significa que está deshabilitado, usar fallback
                if ($endpoint_ttl > 0) {
                    return $endpoint_ttl;
                }
            } catch (\Exception $e) {
                // En caso de error, continuar con fallbacks
                $this->getLogger()->warning('Error obteniendo TTL por endpoint en BatchProcessor, usando fallback', [
                    'data_type' => $data_type,
                    'endpoint' => $endpoint_mapping[$data_type] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Fallback: TTLs hardcodeados por tipo de dato
        $ttl_config = [
            'total_productos' => \MiIntegracionApi\Core\CacheConfig::get_endpoint_cache_ttl('GetNumArticulosWS'), // ✅ Consulta CacheConfig para respetar TTL configurado (ej: 1800 segundos)
            'categorias' => 3600,    // 1 hora - cambia poco
            'fabricantes' => 7200,   // 2 horas - casi estático
            'colecciones' => 7200,   // 2 horas - casi estático
            'cursos' => 14400,       // 4 horas - muy estático
            'asignaturas' => 14400,  // 4 horas - muy estático
            'campos_configurables' => 14400, // 4 horas - muy estático
            'categorias_web' => 3600 // 1 hora - cambia poco
        ];
        
        return $ttl_config[$data_type] ?? \MiIntegracionApi\Core\CacheConfig::get_default_ttl(); // ✅ Default desde CacheConfig
    }
    
    // === MÉTODO DE INTEGRACIÓN CON SISTEMA EXISTENTE ===
    
    /**
     * Procesa productos usando el sistema de lotes existente con datos preparados optimizados.
     * OPTIMIZACIONES INCLUIDAS:
     * - Cache determinístico para datos del batch
     * - Stock global cacheado con filtrado por lote
     * - Cache diferenciado para datos globales vs específicos del lote
     * - Asignación eficiente de batch_data a productos
     * - Métricas de eficiencia de caché
     * @param int $inicio Índice de inicio del lote (1-based de la API Verial)
     * @param int $fin Índice de fin del lote
     * @param callable $processCallback Callback para procesar cada producto individual
     * @param int|null $batchSize Tamaño del lote para procesamiento interno (opcional)
     * @return array Resultado del procesamiento con métricas completas y datos de eficiencia
     */

    /**
     * @throws Throwable
     */
    public function processProductsWithPreparedBatch(int $inicio, int $fin, callable $processCallback, ?int $batchSize = null): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
    {
        // PASO 1: Preparar datos completos del batch usando nuestro método migrado
        $batch_data = $this->prepare_complete_batch_data($inicio, $fin);
        
        // ASIGNAR BATCH_ID ACTUAL PARA AGREGAR A PRODUCTOS
        $this->currentBatchId = $batch_data['batch_id'] ?? 'batch_' . time();
        
        // Verificar si la preparación fue exitosa
        if ($batch_data['status'] === 'failed') {
            return ResponseFactory::error(
                $batch_data['error'] ?? 'Error desconocido preparando batch',
                HttpStatusCodes::BAD_REQUEST,
                [
                    'batch_id' => $batch_data['batch_id'] ?? 'unknown',
                    'errors' => 1,
                    'log' => ['Error en preparación de batch: ' . ($batch_data['error'] ?? 'Error desconocido')]
                ],
                [
                    'processed' => 0,
                    'batch_id' => $batch_data['batch_id'] ?? 'unknown'
                ]
            );
        }
        
        // PASO 2: ✅ NUEVO - Validar categorías en batch
        $batch_data = $this->validate_categories_in_batch($batch_data);
        
        // Verificar si la validación de categorías fue exitosa
        if ($batch_data['category_validation']['status'] !== 'completed') {
            return ResponseFactory::error(
                'Error en validación de categorías',
                HttpStatusCodes::BAD_REQUEST,
                [
                    'batch_id' => $batch_data['batch_id'] ?? 'unknown',
                    'errors' => 1,
                    'category_errors' => $batch_data['category_validation']['errors'] ?? [],
                    'log' => ['Error en validación de categorías: ' . json_encode($batch_data['category_validation']['errors'] ?? [])]
                ],
                [
                    'processed' => 0,
                    'batch_id' => $batch_data['batch_id'] ?? 'unknown',
                    'category_errors' => $batch_data['category_validation']['errors'] ?? []
                ]
            );
        }
        
        // ✅ NUEVO: Guardar batch actualizado con categorías validadas en caché
        $cache_manager = CacheManager::get_instance();
        $rango = ['inicio' => $inicio, 'fin' => $fin]; // ✅ CORREGIDO: Definir $rango aquí
        $cache_saved = $cache_manager->store_batch_data($batch_data['batch_id'], $batch_data, $rango);
        
        if ($cache_saved) {
            $this->getLogger()->debug('Batch actualizado guardado en caché con categorías validadas', [
                'batch_id' => $batch_data['batch_id'],
                'category_mappings_count' => count($batch_data['category_validation']['category_mappings'] ?? []),
                'products_with_categories' => count(array_filter($batch_data['productos'] ?? [], function($p) {
                    return !empty($p['category_ids']);
                }))
            ]);
        } else {
            $this->getLogger()->warning('No se pudo guardar batch actualizado en caché', [
                'batch_id' => $batch_data['batch_id']
            ]);
        }
        
        // PASO 3: Extraer productos del batch preparado
        $productos = $batch_data['productos'] ?? [];
        
        // Logging detallado para diagnóstico
        $this->logger->debug('Verificando productos en processProductsWithPreparedBatch', [
            'tipo_productos' => gettype($productos),
            'es_array' => is_array($productos),
            'contenido_productos' => is_string($productos) ? substr($productos, 0, 200) : (is_array($productos) ? count($productos) : 'no_array'),
            'batch_data_keys' => array_keys($batch_data),
            'batch_data_productos_tipo' => isset($batch_data['productos']) ? gettype($batch_data['productos']) : 'no_existe',
            'primer_producto_tipo' => is_array($productos) && !empty($productos) && isset($productos[0]) ? gettype($productos[0]) : 'no_disponible',
            'primer_producto_es_array' => is_array($productos) && !empty($productos) && isset($productos[0]) && is_array($productos[0])
        ]);
        
        // ✅ REFACTORIZADO: Usar helper centralizado para validar array
        $arrayError = $this->validateArrayOrError(
            $productos,
            'productos',
            'batch_processing',
            [
                'batch_data' => $batch_data,
                'batch_data_productos' => $batch_data['productos'] ?? 'no_existe',
                'batch_id' => $batch_data['batch_id'] ?? 'unknown'
            ]
        );
        if ($arrayError !== null) {
            // Agregar campos adicionales específicos para este contexto
            $errorData = $arrayError->getData();
            return ResponseFactory::error(
                $arrayError->getMessage(),
                $arrayError->getCode(),
                $errorData,
                [
                    'processed' => 0,
                    'batch_id' => $batch_data['batch_id'] ?? 'unknown'
                ]
            );
        }
        
        if (empty($productos)) {
            // ✅ REFACTORIZADO: Usar helper centralizado para respuesta de éxito
            return $this->buildSuccessResponse(0, null, [
                'errors' => 0,
                'log' => ['No hay productos para procesar en este rango'],
                'batch_id' => $batch_data['batch_id']
            ]);
        }
        
        // ✅ OPTIMIZACIÓN: Asignación eficiente de batch_data usando array_map 
        // Validar que todos los elementos de productos sean arrays
        $productos_validos = array_filter($productos, function($producto) {
            return is_array($producto);
        });
        
        if (count($productos_validos) !== count($productos)) {
            $this->logger->warning('Algunos productos no son arrays válidos', [
                'total_productos' => count($productos),
                'productos_validos' => count($productos_validos),
                'productos_invalidos' => count($productos) - count($productos_validos)
            ]);
        }
        
        $productos_con_batch_data = array_map(function($producto) use ($batch_data) {
            $producto['_batch_cache'] = $batch_data;
            return $producto;
        }, $productos_validos);
        
        // Logging después del array_map para verificar que se completó
        $this->logger->debug('Array_map completado exitosamente', [
            'productos_originales' => count($productos),
            'productos_validos' => count($productos_validos),
            'productos_con_batch_data' => count($productos_con_batch_data),
            'batch_id' => $batch_data['batch_id']
        ]);
        
        // ✅ OPTIMIZACIÓN: Logging simplificado y actualizado
        $this->getLogger()->info('✅ Batch data preparado para procesamiento', [
            'batch_id' => $batch_data['batch_id'],
            'productos_count' => count($productos_con_batch_data),
            'categorias_count' => count($batch_data['categorias_indexed'] ?? []),
            'fabricantes_count' => count($batch_data['fabricantes_indexed'] ?? []),
            'stock_lote_count' => count($batch_data['stock_lote']['Stock'] ?? []),
            'imagenes_count' => count($batch_data['imagenes_productos'] ?? []),
            'cache_hit' => isset($batch_data['_from_cache']) ? 'yes' : 'no'
        ]);
        
        // PASO 3: Usar el sistema de procesamiento existente con los productos enriquecidos
        $this->getLogger()->debug('Iniciando procesamiento con callback', [
            'productos_count' => count($productos_con_batch_data),
            'callback_type' => gettype($processCallback),
            'callback_callable' => is_callable($processCallback),
            'batch_id' => $batch_data['batch_id']
        ]);
        
        $process_result = $this->process($productos_con_batch_data, $processCallback, $batchSize);
        
        // ✅ OPTIMIZACIÓN: Enriquecer resultado con métricas actualizadas
        $process_result['batch_id'] = $batch_data['batch_id'];
        
        // ✅ NUEVO: Actualizar estado de sincronización para barra de progreso
        try {
            $current_sync = SyncStatusHelper::getCurrentSyncInfo();
            $total_items = $current_sync['total_items'] ?? 0;
            $items_synced = $current_sync['items_synced'] ?? 0;
            $current_batch = $current_sync['current_batch'] ?? 0;
            $total_batches = $current_sync['total_batches'] ?? 0;
            
            // Calcular progreso actualizado
            $processed_in_batch = $process_result['processed'] ?? 0;
            $new_items_synced = $items_synced + $processed_in_batch;
            $new_current_batch = $current_batch + 1;
            
            // Actualizar estado de sincronización
            SyncStatusHelper::updateCurrentSync([
                'items_synced' => $new_items_synced,
                'current_batch' => $new_current_batch,
                'last_update' => time(),
                'errors' => ($current_sync['errors'] ?? 0) + ($process_result['errors'] ?? 0)
            ]);
            
            $this->getLogger()->debug('Estado de sincronización actualizado', [
                'batch_id' => $batch_data['batch_id'],
                'processed_in_batch' => $processed_in_batch,
                'total_items_synced' => $new_items_synced,
                'current_batch' => $new_current_batch,
                'total_batches' => $total_batches,
                'progress_percentage' => $total_items > 0 ? round(($new_items_synced / $total_items) * 100, 2) : 0,
                'items_synced_after_update' => $new_items_synced
            ]);
            
        } catch (Exception $e) {
            $this->getLogger()->warning('Error actualizando estado de sincronización', [
                'error' => $e->getMessage(),
                'batch_id' => $batch_data['batch_id']
            ]);
        }
        
        // Envolver el resultado en un SyncResponseInterface
        $result = ResponseFactory::success($process_result, 'Procesamiento de productos completado', [
            'batch_id' => $batch_data['batch_id'],
            'inicio' => $inicio,
            'fin' => $fin,
            'batch_data_summary' => [
                'total_productos_disponibles' => $batch_data['total_productos'] ?? 0,
                'productos_en_lote' => count($productos),
                'categorias_disponibles' => count($batch_data['categorias_indexed'] ?? []),
                'fabricantes_disponibles' => count($batch_data['fabricantes_indexed'] ?? []),
                'stock_lote_disponible' => count($batch_data['stock_lote']['Stock'] ?? []),
                'imagenes_disponibles' => count($batch_data['imagenes_productos'] ?? []),
                'condiciones_disponibles' => count($batch_data['condiciones_tarifa'] ?? []),
                'cache_efficiency' => [
                    'global_data_cached' => !empty($batch_data['categorias_indexed']) && !empty($batch_data['fabricantes_indexed']),
                    'stock_optimized' => isset($batch_data['stock_lote']) && !isset($batch_data['stock_completo']),
                    'batch_cached' => isset($batch_data['_from_cache'])
                ]
            ]
        ]);
        
        return $result;
    }

    /**
     * ✅ MÉTODO HELPER: Callback por defecto optimizado para procesamiento de productos
     * @param BatchProcessor $processor Instancia del BatchProcessor
     * @return callable Callback optimizado
     */
    public static function getDefaultProductCallback(BatchProcessor $processor): callable
    {
        return [$processor, 'processSingleProductFromBatch'];
    }

    /**
     * ✅ MÉTODO HELPER: Procesa lote de productos con configuración por defecto
     *
     * @param int $inicio Índice de inicio del lote
     * @param int $fin Índice de fin del lote
     * @param int|null $batchSize Tamaño del lote (opcional)
     * @return array Resultado del procesamiento
     * @throws Throwable
     */
    public function processProductBatch(int $inicio, int $fin, ?int $batchSize = null): array
    {
        $result = $this->processProductsWithPreparedBatch(
            $inicio,
            $fin,
            self::getDefaultProductCallback($this),
            $batchSize
        );
        
        // Extraer el array del SyncResponseInterface
        if ($result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface) {
            return $result->getData();
        }
        
        return $result;
    }
    
    /**
     * Procesa un producto individual desde un batch preparado
     * Este método implementa la lógica robusta de procesamiento de productos
     * reutilizando la lógica existente de Sync_Manager pero optimizada para lotes.
     * @param array $verial_product Datos del producto de Verial
     * @param array|null $batch_data Datos del batch (caché) - opcional, se extrae del producto si no se proporciona
     * @return array Resultado del procesamiento
     */
    public function processSingleProductFromBatch(array $verial_product, ?array $batch_data = null): array
    {
        try {
            // ✅ CRÍTICO: Verificar timeout antes de procesar cada producto
            if ($this->isTimeoutExceeded()) {
                $sku = $verial_product['ReferenciaBarras'] ?? 'N/A';
                $this->getLogger()->warning('Timeout excedido antes de procesar producto', [
                    'sku' => $sku,
                    'elapsed_time' => round(microtime(true) - ($this->startTime ?? microtime(true)), 2)
                ]);
                return $this->buildErrorResponse('Timeout excedido durante procesamiento', 0);
            }
            
            // Obtener batch_data
            if ($batch_data === null) {
                $batch_data = $verial_product['_batch_cache'] ?? [];
            }
            
            // ✅ VERIFICACIÓN CRÍTICA: Validar datos mínimos del producto
            if (empty($verial_product['ReferenciaBarras']) && empty($verial_product['Id'])) {
                $this->getLogger()->error('Producto sin SKU o ID válido', [
                    'product_data' => array_keys($verial_product)
                ]);
                return $this->buildErrorResponse('Producto sin SKU o ID válido', 0);
            }
            
            $sku = $verial_product['ReferenciaBarras'] ?? 'ID_' . ($verial_product['Id'] ?? 'unknown');
            
            // ✅ CORREGIDO: Mapeo correcto del producto con batch_cache
            $wc_product = MapProduct::verial_to_wc($verial_product, [], $batch_data);
            
            // ✅ VERIFICACIÓN: Asegurar que el mapeo fue exitoso
            if ($wc_product === null) {
                $this->getLogger()->error('Error al mapear producto de Verial a WooCommerce', [
                    'sku' => $sku,
                    'verial_id' => $verial_product['Id'] ?? 'N/A',
                    'product_keys' => array_keys($verial_product)
                ]);
                return $this->buildErrorResponse('Error al mapear producto de Verial a WooCommerce', 0);
            }
            
            // Convertir DTO a array
            $wc_product_data = $wc_product->toArray();
            
            // ✅ VERIFICAR Y CORREGIR ESTADO DEL PRODUCTO
            if (!isset($wc_product_data['status']) || empty($wc_product_data['status'])) {
                $wc_product_data['status'] = 'publish'; // Estado por defecto
                $this->getLogger()->warning('Estado del producto no definido, estableciendo a "publish"', [
                    'sku' => $sku
                ]);
            }
            
            // ✅ VERIFICAR PRECIO
            if (!isset($wc_product_data['regular_price']) || empty($wc_product_data['regular_price'])) {
                $wc_product_data['regular_price'] = 0;
                $wc_product_data['price'] = 0;
                $this->getLogger()->warning('Precio no definido, estableciendo a 0', [
                    'sku' => $sku
                ]);
            }
            
            $existing_product_id = null;
            $action = 'created';
            $final_product_id = null;
            
            // ✅ MEJORADO: Buscar producto existente con múltiples métodos
            // 1. Normalizar SKU (trim, eliminar espacios, convertir a string)
            $normalized_sku = !empty($sku) ? trim((string)$sku) : '';
            
            // 2. Buscar por SKU normalizado
            if (!empty($normalized_sku) && function_exists('wc_get_product_id_by_sku')) {
                $existing_product_id = wc_get_product_id_by_sku($normalized_sku);
                
                // Si no se encuentra con el SKU normalizado, intentar con el SKU original
                if (!$existing_product_id && $normalized_sku !== $sku) {
                    $existing_product_id = wc_get_product_id_by_sku($sku);
                }
            }
            
            // 3. Si no se encontró por SKU, buscar por ID de Verial
            if (!$existing_product_id && !empty($verial_product['Id'])) {
                $verial_id = (int)$verial_product['Id'];
                
                // Buscar en la tabla de mapeo
                global $wpdb;
                $table_name = $wpdb->prefix . 'verial_product_mapping';
                if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
                    $wc_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT wc_id FROM $table_name WHERE verial_id = %d LIMIT 1",
                        $verial_id
                    ));
                    if ($wc_id) {
                        $existing_product_id = (int)$wc_id;
                        $this->getLogger()->info("Producto encontrado por ID de Verial en tabla de mapeo", [
                            'verial_id' => $verial_id,
                            'wc_id' => $existing_product_id,
                            'sku' => $normalized_sku
                        ]);
                    }
                }
                
                // Si no se encontró en la tabla, buscar en metadatos
                if (!$existing_product_id) {
                    $args = [
                        'post_type' => 'product',
                        'posts_per_page' => 1,
                        'meta_query' => [
                            [
                                'key' => '_verial_product_id',
                                'value' => $verial_id,
                                'compare' => '='
                            ]
                        ],
                        'fields' => 'ids'
                    ];
                    $products = get_posts($args);
                    if (!empty($products)) {
                        $existing_product_id = (int)$products[0];
                        $this->getLogger()->info("Producto encontrado por ID de Verial en metadatos", [
                            'verial_id' => $verial_id,
                            'wc_id' => $existing_product_id,
                            'sku' => $normalized_sku
                        ]);
                    }
                }
            }
            
            if ($existing_product_id) {
                $existing_product = wc_get_product($existing_product_id);
                
                if ($existing_product) {
                    // ✅ ACTUALIZAR PRODUCTO EXISTENTE
                    $this->updateExistingProduct($existing_product, $wc_product_data, $verial_product);
                    $action = 'updated';
                    $final_product_id = $existing_product_id;
                    
                    $this->getLogger()->info("Producto existente actualizado", [
                        'sku' => $sku,
                        'product_id' => $existing_product_id,
                        'action' => $action
                    ]);
                } else {
                    // ✅ CREAR NUEVO PRODUCTO (existente no encontrado)
                    $new_product = $this->createNewWooCommerceProduct($wc_product_data, $verial_product);
                    if ($new_product) {
                        $action = 'created';
                        $final_product_id = $new_product->get_id();
                        
                        $this->getLogger()->warning("Producto existente no encontrado, creando nuevo", [
                            'sku' => $sku,
                            'expected_id' => $existing_product_id,
                            'new_id' => $final_product_id
                        ]);
                    } else {
                        return $this->buildErrorResponse('Error creando producto (existente no encontrado)', 0);
                    }
                }
            } else {
                // ✅ CREAR NUEVO PRODUCTO
                $new_product = $this->createNewWooCommerceProduct($wc_product_data, $verial_product);
                if ($new_product) {
                    $action = 'created';
                    $final_product_id = $new_product->get_id();
                    
                    // ✅ OPTIMIZADO: Log eliminado - se genera demasiado volumen por producto
                    // Los logs se consolidan a nivel de batch
                } else {
                    return $this->buildErrorResponse('Error creando nuevo producto', 0);
                }
            }
            
            // ✅ VERIFICACIÓN FINAL: Confirmar que el producto existe y es visible
            if ($final_product_id) {
                $verified_product = wc_get_product($final_product_id);
                if ($verified_product) {
                    // ✅ OPTIMIZADO: Log eliminado - se genera demasiado volumen por producto
                    // Los logs se consolidan a nivel de batch
                    
                    // ✅ REFACTORIZADO: Usar helper centralizado para respuesta de éxito
                    return $this->buildSuccessResponse(1, "Producto {$action} exitosamente", [
                        'product_id' => $final_product_id,
                        'action' => $action
                    ]);
                } else {
                    $this->getLogger()->error("Producto creado pero no se puede verificar", [
                        'product_id' => $final_product_id,
                        'sku' => $sku
                    ]);
                }
            }
            
            return $this->buildErrorResponse('Error desconocido - producto no creado/actualizado', 0);
            
        } catch (Exception $e) {
            $this->getLogger()->error('Error procesando producto individual', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'product_sku' => $verial_product['ReferenciaBarras'] ?? 'N/A'
            ]);
            
            return $this->buildErrorResponse($e->getMessage(), 0);
        }
    }
    
    /**
     * Actualiza el progreso visual durante el procesamiento de productos
     * @param array $verial_product Datos del producto de Verial
     * @param array $product_result Resultado del procesamiento
     */
    private function updateProgressDuringProcessing(array $verial_product, array $product_result): void
    {
        if (class_exists('\MiIntegracionApi\Admin\AjaxSync')) {
            $sku = $verial_product['ReferenciaBarras'] ?? $verial_product['Id'] ?? 'unknown';
            $name = $verial_product['Nombre'] ?? 'Producto sin nombre';
            
            // Asegurar que no sean null
            $sku = $sku !== null ? (string)$sku : 'unknown';
            $name = $name !== null ? (string)$name : 'Producto sin nombre';
            
            // CENTRALIZADO: Usar SyncStatusHelper para obtener estado actual
            $current_sync = SyncStatusHelper::getCurrentSyncInfo();
            
            $total_items = $current_sync['total_items'] ?? 0;
            $items_processed = $current_sync['items_synced'] ?? 0;
            $current_batch = $current_sync['current_batch'] ?? 0;
            $total_batches = $current_sync['total_batches'] ?? 0;
            $batch_size = $current_sync['batch_size'] ?? 20;
            
            // Calcular progreso más preciso basándose en el lote actual
            $items_in_current_batch = min($batch_size, $total_items - ($current_batch - 1) * $batch_size);
            $items_processed_in_batch = ($current_batch - 1) * $batch_size + $items_processed;
            
            // Calcular porcentaje basándose en el progreso real
            $percentage = 0;
            if ($total_items > 0) {
                $percentage = min(99.9, max(1, round(($items_processed_in_batch / $total_items) * 100, 1)));
            }
            
            $success_format = __('Procesando: %s (%s) - Lote %d/%d', 'mi-integracion-api') ?: 'Procesando: %s (%s) - Lote %d/%d';
            $error_format = __('Error procesando: %s (%s) - Lote %d/%d', 'mi-integracion-api') ?: 'Error procesando: %s (%s) - Lote %d/%d';
            
            $message = $product_result['success']
                ? sprintf($success_format, $name, $sku, $current_batch, $total_batches)
                : sprintf($error_format, $name, $sku, $current_batch, $total_batches);
            
            // Actualizar progreso usando la función existente
            $progress_data = [
                'porcentaje' => $percentage,
                'mensaje' => $message,
                'estadisticas' => [
                    'procesados' => $items_processed_in_batch,
                    'total' => $total_items,
                    'errores' => $current_sync['errors'] ?? 0
                ],
                'articulo_actual' => $name,
                'sku' => $sku,
                'current_batch' => $current_batch,
                'total_batches' => $total_batches,
                'actualizado' => time()
            ];
            
            if (function_exists('mia_set_sync_transient')) {
                mia_set_sync_transient('mia_sync_progress', $progress_data, 6 * HOUR_IN_SECONDS);
            }
        }
    }
    
    /**
     * Verifica si un producto tiene cambios comparado con los datos de Verial
     * @param WC_Product $existing_product Producto existente en WooCommerce
     * @param array $new_data Datos nuevos de Verial
     * @return bool True si hay cambios que requieren actualización
     */
    private function hasProductChanges(WC_Product $existing_product, array $new_data): bool
    {
        try {
            // Comparar campos principales
            $fields_to_compare = [
                'name' => 'get_name',
                'description' => 'get_description',
                'short_description' => 'get_short_description',
                'price' => 'get_price',
                'regular_price' => 'get_regular_price',
                'sale_price' => 'get_sale_price',
                'stock_quantity' => 'get_stock_quantity',
                'manage_stock' => 'get_manage_stock',
                'stock_status' => 'get_stock_status',
                'weight' => 'get_weight',
                'length' => 'get_length',
                'width' => 'get_width',
                'height' => 'get_height',
                'status' => 'get_status'
            ];
            
            foreach ($fields_to_compare as $field => $method) {
                if (!isset($new_data[$field])) {
                    continue;
                }
                
                $existing_value = $existing_product->$method();
                $new_value = $new_data[$field];
                
                // Normalizar valores para comparación
                $existing_value = $this->normalizeValueForComparison($existing_value);
                $new_value = $this->normalizeValueForComparison($new_value);
                
                if ($existing_value !== $new_value) {
                    return true;
                }
            }
            
            // Comparar categorías
            if (isset($new_data['categories']) && is_array($new_data['categories'])) {
                $existing_categories = mi_integracion_api_get_post_terms_safe($existing_product->get_id(), 'product_cat', ['fields' => 'ids']);
                $new_categories = array_column($new_data['categories'], 'id');
                
                sort($existing_categories);
                sort($new_categories);
                
                if ($existing_categories !== $new_categories) {
                    return true;
                }
            }
            
            // Comparar atributos
            if (isset($new_data['attributes']) && is_array($new_data['attributes'])) {
                $existing_attributes = $existing_product->get_attributes();
                $new_attributes = $new_data['attributes'];
                
                if ($this->attributesHaveChanged($existing_attributes, $new_attributes)) {
                    return true;
                }
            }
            
            return false;
            
        } catch (Exception $e) {
            $this->getLogger()->error('Error comparando cambios del producto', [
                'error' => $e->getMessage(),
                'sku' => $existing_product->get_sku()
            ]);
            // En caso de error, asumir que hay cambios para ser conservador
            return true;
        }
    }
    
    /**
     * Normaliza valores para comparación
     * @param mixed $value Valor a normalizar
     * @return mixed Valor normalizado
     */
    private function normalizeValueForComparison(mixed $value): mixed
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        
        if (is_string($value)) {
            return trim($value);
        }
        
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        
        return $value;
    }
    
    /**
     * Actualiza un producto existente con los nuevos datos
     * @param WC_Product $existing_product Producto existente en WooCommerce
     * @param array      $new_data         Datos nuevos de Verial
     * @param array      $verial_product    Datos originales de Verial (opcional, pero recomendado)
     * @return void
     */
    private function updateExistingProduct(WC_Product $existing_product, array $new_data, array $verial_product = []): void
    {
        try {
            // Actualizar campos básicos
            if (isset($new_data['name'])) {
                $existing_product->set_name($new_data['name']);
            }
            
            if (isset($new_data['description'])) {
                $existing_product->set_description($new_data['description']);
            }
            
            if (isset($new_data['short_description'])) {
                $existing_product->set_short_description($new_data['short_description']);
            }
            
            // Actualizar precios
            if (isset($new_data['regular_price'])) {
                $existing_product->set_regular_price($new_data['regular_price']);
            }
            
            if (isset($new_data['sale_price'])) {
                $existing_product->set_sale_price($new_data['sale_price']);
            }
            
            // Actualizar stock
            if (isset($new_data['stock_quantity'])) {
                $existing_product->set_stock_quantity($new_data['stock_quantity']);
                $existing_product->set_manage_stock(true);
                $existing_product->set_stock_status($new_data['stock_quantity'] > 0 ? 'instock' : 'outofstock');
            }
            
            // Actualizar peso y dimensiones
            if (isset($new_data['weight'])) {
                $existing_product->set_weight($new_data['weight']);
            }
            
            if (isset($new_data['length'])) {
                $existing_product->set_length($new_data['length']);
            }
            
            if (isset($new_data['width'])) {
                $existing_product->set_width($new_data['width']);
            }
            
            if (isset($new_data['height'])) {
                $existing_product->set_height($new_data['height']);
            }
            
            // Actualizar estado
            if (isset($new_data['status'])) {
                $existing_product->set_status($new_data['status']);
            }
            
            // Guardar cambios
            $existing_product->save();
            
        // ✅ NUEVO: Procesar operaciones post-guardado (imágenes, metadatos, etc.)
        $this->handlePostSaveOperations(
            $existing_product->get_id(),
            $new_data,
            $verial_product, // ✅ CORRECTO - Datos originales de Verial
            $new_data // Usar batch_data del producto
        );

            $this->getLogger()->info("✅ Producto existente actualizado exitosamente", [
                'sku' => $existing_product->get_sku(),
                'product_id' => $existing_product->get_id(),
                'updated_fields' => array_keys($new_data)
            ]);
            
        } catch (Exception $e) {
            $this->getLogger()->error('Error actualizando producto existente', [
                'error' => $e->getMessage(),
                'sku' => $existing_product->get_sku(),
                'product_id' => $existing_product->get_id(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    /**
     * ✅ NUEVO: Crea un nuevo producto en WooCommerce
     * @param array $wc_product_data Datos del producto en formato WooCommerce
     * @return WC_Product|null Producto creado o null si falla
     */
    private function createNewWooCommerceProduct(array $wc_product_data, array $verial_product = []): ?WC_Product
    {
        try {
            // ✅ VALIDACIONES CRÍTICAS ANTES DE CREAR
            if (empty($wc_product_data['name'])) {
                $this->getLogger()->error('No se puede crear producto sin nombre', [
                    'sku' => $wc_product_data['sku'] ?? 'N/A'
                ]);
                return null;
            }
            
            // ✅ ASEGURAR ESTADO VÁLIDO
            if (!in_array($wc_product_data['status'] ?? '', ['publish', 'draft', 'pending', 'private'])) {
                $wc_product_data['status'] = 'publish';
                $this->getLogger()->warning('Estado inválido, estableciendo a "publish"', [
                    'sku' => $wc_product_data['sku'] ?? 'N/A',
                    'original_status' => $wc_product_data['status'] ?? 'NOT_SET'
                ]);
            }
            
            // ✅ ASEGURAR TIPO DE PRODUCTO
            if (empty($wc_product_data['type'])) {
                $wc_product_data['type'] = 'simple';
            }
            
            // Crear nuevo producto
            $product = new WC_Product();
            
            // Aplicar propiedades
            $this->applyWooCommerceProductProperties($product, $wc_product_data);
            
            // ✅ GUARDAR PRODUCTO
            $product_id = $product->save();
            
            if (!$product_id || is_wp_error($product_id)) {
                $error_message = is_wp_error($product_id) ? $product_id->get_error_message() : 'Error desconocido';
                $this->getLogger()->error('Error guardando producto en WooCommerce', [
                    'sku' => $wc_product_data['sku'] ?? 'N/A',
                    'error' => $error_message
                ]);
                return null;
            }
            
            // ✅ VERIFICAR QUE EL PRODUCTO SE CREÓ CORRECTAMENTE
            $saved_product = wc_get_product($product_id);
            if (!$saved_product) {
                $this->getLogger()->error('Producto creado pero no se puede recuperar', [
                    'product_id' => $product_id,
                    'sku' => $wc_product_data['sku'] ?? 'N/A'
                ]);
                return null;
            }
            
            // ✅ OPTIMIZADO: Log eliminado - se genera demasiado volumen por producto
            // Los logs se consolidan a nivel de batch
            
            // ✅ NUEVO: Procesar operaciones post-guardado (imágenes, metadatos, etc.)
            $this->handlePostSaveOperations(
                $saved_product->get_id(),
                $wc_product_data,
                $verial_product, // Datos originales de Verial
                $wc_product_data // Usar batch_data del producto
            );
            
            return $saved_product;
            
        } catch (Exception $e) {
            $this->getLogger()->error('Excepción creando nuevo producto en WooCommerce', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'sku' => $wc_product_data['sku'] ?? 'N/A'
            ]);
            return null;
        }
    }
    
    /**
     * Compara si los atributos han cambiado
     * @param array $existing_attributes Atributos existentes
     * @param array $new_attributes Atributos nuevos
     * @return bool True si han cambiado
     */
    private function attributesHaveChanged(array $existing_attributes, array $new_attributes): bool
    {
        // Implementación simplificada - se puede expandir según necesidades
        return count($existing_attributes) !== count($new_attributes);
    }
    
    /**
     * Aplica propiedades básicas del producto WooCommerce (simplificado)
     * @deprecated La lógica de cálculo de precios se ha movido a MapProduct::processProductPricing()
     */
    private function applyWooCommerceProductProperties(WC_Product $product, array $wc_product_data): void
    {
        // Propiedades básicas
        $basic_props = ['sku', 'name', 'description', 'short_description', 'status', 'type'];
        foreach ($basic_props as $prop) {
            if (isset($wc_product_data[$prop])) {
                $method = "set_$prop";
                if (method_exists($product, $method)) {
                    $product->$method($wc_product_data[$prop]);
                }
            }
        }
        
        // Aplicar precios ya calculados por MapProduct::processProductPricing()
        if (isset($wc_product_data['regular_price']) && is_numeric($wc_product_data['regular_price'])) {
            $product->set_regular_price($wc_product_data['regular_price']);
        }
        if (isset($wc_product_data['sale_price']) && is_numeric($wc_product_data['sale_price']) && $wc_product_data['sale_price'] > 0) {
            $product->set_sale_price($wc_product_data['sale_price']);
        } else {
            $product->set_sale_price('');
        }
        if (isset($wc_product_data['price']) && is_numeric($wc_product_data['price'])) {
            $product->set_price($wc_product_data['price']);
        }
        
        // Aplicar stock ya calculado por BatchProcessor::get_product_stock_from_batch()
        if (isset($wc_product_data['stock_quantity']) && is_numeric($wc_product_data['stock_quantity'])) {
            $product->set_stock_quantity($wc_product_data['stock_quantity']);
            $product->set_manage_stock(true);
            $product->set_stock_status($wc_product_data['stock_quantity'] > 0 ? 'instock' : 'outofstock');
        } elseif (isset($wc_product_data['stock_status'])) {
            $product->set_stock_status($wc_product_data['stock_status']);
        }
        
        // Dimensiones físicas
        $physical_props = ['weight', 'length', 'width', 'height'];
        foreach ($physical_props as $prop) {
            if (isset($wc_product_data[$prop]) && is_numeric($wc_product_data[$prop])) {
                $method = "set_$prop";
                if (method_exists($product, $method)) {
                    $product->$method($wc_product_data[$prop]);
                }
            }
        }
        
        // Categorías
        if (isset($wc_product_data['category_ids']) && is_array($wc_product_data['category_ids'])) {
            $product->set_category_ids($wc_product_data['category_ids']);
        }
        
        // Etiquetas
        if (isset($wc_product_data['tag_ids']) && is_array($wc_product_data['tag_ids'])) {
            $product->set_tag_ids($wc_product_data['tag_ids']);
        }
        
        // Visibilidad - WooCommerce usa metadatos específicos
        if (isset($wc_product_data['visibility'])) {
            // Establecer visibilidad en catálogo y búsqueda
            $product->update_meta_data('_visibility', $wc_product_data['visibility']);
            $product->set_featured(false); // Usar setter dedicado para _featured
        } else {
            // Valores por defecto para visibilidad completa
            $product->update_meta_data('_visibility', 'visible');
            $product->set_featured(false); // Usar setter dedicado para _featured
        }
        
        // Metadatos
        if (isset($wc_product_data['meta_data']) && is_array($wc_product_data['meta_data'])) {
            foreach ($wc_product_data['meta_data'] as $meta) {
                if (isset($meta['key']) && isset($meta['value'])) {
                    $product->update_meta_data($meta['key'], $meta['value']);
                }
            }
        }
    }
    
    /**
     * Guarda metadatos específicos de campos de libros
     * @param WC_Product $product Producto de WooCommerce
     * @param array $verial_product Datos originales de Verial
     */
    private function saveBookFieldsMetadata(WC_Product $product, array $verial_product): array
    {
        try {
            // Campos específicos de libros
            $book_fields = [
                'autores' => $verial_product['Autores'] ?? [],
                'obra_completa' => $verial_product['ObraCompleta'] ?? '',
                'subtitulo' => $verial_product['Subtitulo'] ?? '',
                'menciones' => $verial_product['Menciones'] ?? '',
                'id_pais_publicacion' => $verial_product['ID_PaisPublicacion'] ?? 0,
                'edicion' => $verial_product['Edicion'] ?? '',
                'fecha_edicion' => $verial_product['FechaEdicion'] ?? '',
                'paginas' => $verial_product['Paginas'] ?? 0,
                'volumenes' => $verial_product['Volumenes'] ?? 0,
                'numero_volumen' => $verial_product['NumeroVolumen'] ?? '',
                'id_coleccion' => $verial_product['ID_Coleccion'] ?? 0,
                'numero_coleccion' => $verial_product['NumeroColeccion'] ?? '',
                'id_curso' => $verial_product['ID_Curso'] ?? 0,
                'id_asignatura' => $verial_product['ID_Asignatura'] ?? 0,
                'idioma_original' => $verial_product['IdiomaOriginal'] ?? '',
                'idioma_publicacion' => $verial_product['IdiomaPublicacion'] ?? '',
                'indice' => $verial_product['Indice'] ?? '',
                'resumen' => $verial_product['Resumen'] ?? ''
            ];
            
            $fields_saved = 0;
            foreach ($book_fields as $field_key => $field_value) {
                if (!empty($field_value) && $field_value !== 0) {
                    $meta_key = '_verial_book_' . $field_key;
                    $product->update_meta_data($meta_key, $field_value);
                    $fields_saved++;
                }
            }
            
            // Guardar metadato de que es un libro
            $product->update_meta_data('_verial_is_book', true);
            $product->update_meta_data('_verial_book_fields_count', $fields_saved);
            
            // ✅ NUEVO: Guardar campos adicionales importantes para todos los productos
            $additional_fields_saved = $this->saveAdditionalFieldsMetadata($product, $verial_product, true);
            
            // ✅ OPTIMIZADO: Log eliminado - información consolidada en log final
            return [
                'book_fields_saved' => $fields_saved,
                'additional_fields_saved' => $additional_fields_saved,
                'book_fields' => array_keys(array_filter($book_fields, function($v) { return !empty($v) && $v !== 0; }))
            ];
            
        } catch (Exception $e) {
            $this->getLogger()->warning('Error guardando metadatos de libro', [
                'product_id' => $product->get_id(),
                'error' => $e->getMessage()
            ]);
            return ['book_fields_saved' => 0, 'additional_fields_saved' => 0, 'book_fields' => []];
        }
    }

    /**
     * Guarda metadatos adicionales importantes para todos los productos
     * @param WC_Product $product Producto de WooCommerce
     * @param array $verial_product Datos originales de Verial
     */
    private function saveAdditionalFieldsMetadata(WC_Product $product, array $verial_product, bool $return_count = false): int
    {
        try {
            // Campos adicionales importantes
            $additional_fields = [
                'fecha_disponibilidad' => $verial_product['FechaDisponibilidad'] ?? '',
                'fecha_inicio_venta' => $verial_product['FechaInicioVenta'] ?? '',
                'fecha_inactivo' => $verial_product['FechaInactivo'] ?? '',
                'porcentaje_iva' => $verial_product['PorcentajeIVA'] ?? 0,
                'porcentaje_re' => $verial_product['PorcentajeRE'] ?? 0,
                'nexo' => $verial_product['Nexo'] ?? '',
                'id_articulo_ecotasas' => $verial_product['ID_ArticuloEcotasas'] ?? 0,
                'precio_ecotasas' => $verial_product['PrecioEcotasas'] ?? 0,
                'aux1' => $verial_product['Aux1'] ?? '',
                'aux2' => $verial_product['Aux2'] ?? '',
                'aux3' => $verial_product['Aux3'] ?? '',
                'aux4' => $verial_product['Aux4'] ?? '',
                'aux5' => $verial_product['Aux5'] ?? '',
                'aux6' => $verial_product['Aux6'] ?? ''
            ];
            
            $fields_saved = 0;
            foreach ($additional_fields as $field_key => $field_value) {
                if (!empty($field_value) && $field_value !== 0) {
                    $meta_key = '_verial_' . $field_key;
                    $product->update_meta_data($meta_key, $field_value);
                    $fields_saved++;
                }
            }
            
            // Guardar metadato de campos adicionales guardados
            $product->update_meta_data('_verial_additional_fields_count', $fields_saved);
            
            // ✅ OPTIMIZADO: Log eliminado - información consolidada en log final
            return $fields_saved;
            
        } catch (Exception $e) {
            $this->getLogger()->warning('Error guardando metadatos adicionales', [
                'product_id' => $product->get_id(),
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Aplica lógica de visibilidad basada en fechas importantes
     * @param WC_Product $product Producto de WooCommerce
     * @param array $verial_product Datos originales de Verial
     */
    private function applyDateBasedVisibility(WC_Product $product, array $verial_product, bool $return_info = false): array
    {
        try {
            $current_date = current_time('Y-m-d');
            $visibility_changed = false;
            $visibility_reason = 'no_restrictions';
            
            // Verificar FechaDisponibilidad
            if (!empty($verial_product['FechaDisponibilidad'])) {
                $fecha_disponibilidad = $verial_product['FechaDisponibilidad'];
                if ($current_date < $fecha_disponibilidad) {
                    // Producto aún no disponible
                    $product->set_status('draft');
                    $product->update_meta_data('_verial_visibility_reason', 'not_available_yet');
                    $product->update_meta_data('_verial_available_date', $fecha_disponibilidad);
                    $visibility_changed = true;
                    $visibility_reason = 'not_available_yet';
                }
            }
            
            // Verificar FechaInicioVenta
            if (!empty($verial_product['FechaInicioVenta'])) {
                $fecha_inicio_venta = $verial_product['FechaInicioVenta'];
                if ($current_date < $fecha_inicio_venta) {
                    // Producto no está en venta aún
                    $product->set_status('draft');
                    $product->update_meta_data('_verial_visibility_reason', 'not_on_sale_yet');
                    $product->update_meta_data('_verial_sale_start_date', $fecha_inicio_venta);
                    $visibility_changed = true;
                    $visibility_reason = 'not_on_sale_yet';
                }
            }
            
            // Verificar FechaInactivo
            if (!empty($verial_product['FechaInactivo'])) {
                $fecha_inactivo = $verial_product['FechaInactivo'];
                if ($current_date >= $fecha_inactivo) {
                    // Producto descatalogado
                    $product->set_status('draft');
                    $product->update_meta_data('_verial_visibility_reason', 'discontinued');
                    $product->update_meta_data('_verial_discontinued_date', $fecha_inactivo);
                    $visibility_changed = true;
                    $visibility_reason = 'discontinued';
                }
            }
            
            // Si no hay restricciones de fecha, asegurar que esté publicado
            if (!$visibility_changed) {
                $product->set_status('publish');
                $product->update_meta_data('_verial_visibility_reason', 'date_restrictions_met');
                $visibility_reason = 'no_restrictions';
            }
            
            return $return_info ? ['changed' => $visibility_changed, 'reason' => $visibility_reason] : [];
            
        } catch (Exception $e) {
            $this->getLogger()->warning('Error aplicando visibilidad basada en fechas', [
                'product_id' => $product->get_id(),
                'error' => $e->getMessage()
            ]);
            return $return_info ? ['changed' => false, 'reason' => 'error'] : [];
        }
    }

    /**
     * Crea atributos dinámicos de WooCommerce basados en campos auxiliares
     * @param WC_Product $product Producto de WooCommerce
     * @param array $verial_product Datos originales de Verial
     */
    private function createDynamicAttributesFromAuxFields(WC_Product $product, array $verial_product): void
    {
        try {
            $aux_fields = [
                'Aux1' => 'Característica 1',
                'Aux2' => 'Característica 2',
                'Aux3' => 'Característica 3',
                'Aux4' => 'Característica 4',
                'Aux5' => 'Característica 5',
                'Aux6' => 'Característica 6'
            ];
            
            $attributes_created = 0;
            
            foreach ($aux_fields as $aux_field => $attribute_name) {
                if (!empty($verial_product[$aux_field])) {
                    $attribute_slug = 'pa_' . strtolower(str_replace('Aux', 'aux', $aux_field));
                    $attribute_value = $verial_product[$aux_field];
                    
                    // Crear o obtener atributo
                    $attribute_id = $this->create_or_get_product_attribute($attribute_name, $attribute_slug);
                    if (!$attribute_id) {
                        continue;
                    }
                    
                    // Crear o obtener término del atributo
                    $term_id = $this->create_or_get_attribute_term($attribute_id, $attribute_value, $attribute_value);
                    if (!$term_id) {
                        continue;
                    }
                    
                    // Asignar término al producto
                    wp_set_object_terms($product->get_id(), $term_id, $attribute_slug, true);
                    $attributes_created++;
                    
                    $this->getLogger()->debug('Atributo dinámico creado', [
                        'product_id' => $product->get_id(),
                        'attribute_name' => $attribute_name,
                        'attribute_slug' => $attribute_slug,
                        'attribute_value' => $attribute_value
                    ]);
                }
            }
            
            if ($attributes_created > 0) {
                $product->update_meta_data('_verial_dynamic_attributes_count', $attributes_created);
                
                $this->getLogger()->info('Atributos dinámicos creados exitosamente', [
                    'product_id' => $product->get_id(),
                    'attributes_created' => $attributes_created
                ]);
            }
            
        } catch (Exception $e) {
            $this->getLogger()->warning('Error creando atributos dinámicos', [
                'product_id' => $product->get_id(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Gestiona clases de impuestos dinámicas basadas en datos de Verial
     * 
     * @param WC_Product $product Producto de WooCommerce
     * @param array $verial_product Datos originales de Verial
     */
    private function manageDynamicTaxClasses(WC_Product $product, array $verial_product, bool $return_info = false): array
    {
        try {
            // Verificar que WooCommerce esté activo y el sistema de impuestos habilitado
            if (!function_exists('wc_get_tax_classes') || !wc_tax_enabled()) {
                // ✅ OPTIMIZADO: Log eliminado - información consolidada en log final
                return $return_info ? ['available' => false] : [];
            }
            
            $iva_percentage = $verial_product['PorcentajeIVA'] ?? 0;
            $re_percentage = $verial_product['PorcentajeRE'] ?? 0;
            
            // Determinar clase de impuestos basada en los porcentajes
            $tax_class = $this->determineTaxClass($iva_percentage, $re_percentage);
            
            if ($tax_class) {
                // Aplicar clase de impuestos al producto
                $product->set_tax_class($tax_class);
                
                // Guardar metadatos de impuestos
                $product->update_meta_data('_verial_iva_percentage', $iva_percentage);
                $product->update_meta_data('_verial_re_percentage', $re_percentage);
                $product->update_meta_data('_verial_tax_class', $tax_class);
                
                // ✅ OPTIMIZADO: Log eliminado - información consolidada en log final
            }
            
            return $return_info ? ['available' => true, 'tax_class_applied' => $tax_class ?? null] : [];
            
        } catch (Exception $e) {
            $this->getLogger()->warning('Error gestionando clases de impuestos', [
                'product_id' => $product->get_id(),
                'error' => $e->getMessage()
            ]);
            return $return_info ? ['available' => false, 'tax_class_applied' => null] : [];
        }
    }
    
    /**
     * Determina la clase de impuestos basada en los porcentajes de Verial
     * @param float $iva_percentage Porcentaje de IVA
     * @param float $re_percentage Porcentaje de RE
     * @return string|null Clase de impuestos o null si no aplica
     */
    private function determineTaxClass(float $iva_percentage, float $re_percentage): ?string
    {
        // Producto exento de impuestos
        if ($iva_percentage == 0 && $re_percentage == 0) {
            return 'zero-rate';
        }
        
        // Crear nombre de clase basado en los porcentajes
        $class_parts = [];
        
        if ($iva_percentage > 0) {
            $class_parts[] = 'iva-' . str_replace('.', '', number_format($iva_percentage, 1));
        }
        
        if ($re_percentage > 0) {
            $class_parts[] = 're-' . str_replace('.', '', number_format($re_percentage, 1));
        }
        
        if (empty($class_parts)) {
            return null;
        }
        
        $tax_class = 'verial-' . implode('-', $class_parts);
        
        // Crear la clase de impuestos si no existe
        $this->createTaxClassIfNotExists($tax_class, $iva_percentage, $re_percentage);
        
        return $tax_class;
    }
    
    /**
     * Crea una clase de impuestos si no existe
     * 
     * @param string $tax_class Nombre de la clase de impuestos
     * @param float $iva_percentage Porcentaje de IVA
     * @param float $re_percentage Porcentaje de RE
     */
    private function createTaxClassIfNotExists(string $tax_class, float $iva_percentage, float $re_percentage): void
    {
        try {
            // Verificar si la clase ya existe
            $existing_classes = wc_get_tax_classes();
            if (in_array($tax_class, $existing_classes)) {
                return;
            }
            
            // Crear la clase de impuestos
            $result = wc_create_tax_class($tax_class);
            
            if ($result) {
                $this->getLogger()->info('Clase de impuestos creada', [
                    'tax_class' => $tax_class,
                    'iva_percentage' => $iva_percentage,
                    're_percentage' => $re_percentage
                ]);
                
                // Configurar tasas de impuestos para la clase
                $this->configureTaxRatesForClass($tax_class, $iva_percentage, $re_percentage);
            }
            
        } catch (Exception $e) {
            $this->getLogger()->warning('Error creando clase de impuestos', [
                'tax_class' => $tax_class,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Configura las tasas de impuestos para una clase específica
     * 
     * @param string $tax_class Nombre de la clase de impuestos
     * @param float $iva_percentage Porcentaje de IVA
     * @param float $re_percentage Porcentaje de RE
     */
    private function configureTaxRatesForClass(string $tax_class, float $iva_percentage, float $re_percentage): void
    {
        try {
            // Obtener país por defecto de WooCommerce
            $default_country = wc_get_base_location()['country'] ?? 'ES';
            
            // Configurar tasa de IVA
            if ($iva_percentage > 0) {
                $iva_rate = [
                    'tax_rate_country' => $default_country,
                    'tax_rate_state' => '',
                    'tax_rate' => $iva_percentage,
                    'tax_rate_name' => 'IVA ' . $iva_percentage . '%',
                    'tax_rate_priority' => 1,
                    'tax_rate_compound' => 0,
                    'tax_rate_shipping' => 1,
                    'tax_rate_class' => $tax_class
                ];
                
                wc_insert_tax_rate($iva_rate);
            }
            
            // Configurar tasa de RE
            if ($re_percentage > 0) {
                $re_rate = [
                    'tax_rate_country' => $default_country,
                    'tax_rate_state' => '',
                    'tax_rate' => $re_percentage,
                    'tax_rate_name' => 'RE ' . $re_percentage . '%',
                    'tax_rate_priority' => 2,
                    'tax_rate_compound' => 1, // RE se aplica sobre el total con IVA
                    'tax_rate_shipping' => 1,
                    'tax_rate_class' => $tax_class
                ];
                
                wc_insert_tax_rate($re_rate);
            }
            
            $this->getLogger()->debug('Tasas de impuestos configuradas', [
                'tax_class' => $tax_class,
                'iva_percentage' => $iva_percentage,
                're_percentage' => $re_percentage,
                'country' => $default_country
            ]);
            
        } catch (Exception $e) {
            $this->getLogger()->warning('Error configurando tasas de impuestos', [
                'tax_class' => $tax_class,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Gestiona unidades dinámicas basadas en datos de Verial
     * 
     * @param WC_Product $product Producto de WooCommerce
     * @param array $verial_product Datos originales de Verial
     */
    private function manageDynamicUnits(WC_Product $product, array $verial_product, bool $return_info = false): array
    {
        try {
            // Verificar que WooCommerce esté activo
            if (!function_exists('wc_get_product')) {
                // ✅ OPTIMIZADO: Log eliminado - información consolidada en log final
                return $return_info ? ['applied' => false] : [];
            }
            
            $units_data = $this->extractUnitsData($verial_product);
            
            if (!empty($units_data)) {
                // Aplicar configuración de unidades al producto
                $this->applyUnitsConfiguration($product, $units_data);
                
                // Guardar metadatos de unidades
                $this->saveUnitsMetadata($product, $units_data);
                
                // ✅ OPTIMIZADO: Log eliminado - información consolidada en log final
                return $return_info ? ['applied' => true] : [];
            }
            
            return $return_info ? ['applied' => false] : [];
            
        } catch (Exception $e) {
            $this->getLogger()->warning('Error gestionando unidades', [
                'product_id' => $product->get_id(),
                'error' => $e->getMessage()
            ]);
            return $return_info ? ['applied' => false] : [];
        }
    }
    
    /**
     * Extrae datos de unidades del producto de Verial
     * 
     * @param array $verial_product Datos del producto de Verial
     * @return array Datos de unidades procesados
     */
    private function extractUnitsData(array $verial_product): array
    {
        $units_data = [
            'main_unit' => $verial_product['NombreUds'] ?? '',
            'auxiliary_unit' => $verial_product['NombreUdsAux'] ?? '',
            'standard_unit' => $verial_product['NombreUdsOCU'] ?? '',
            'auxiliary_relation' => (float)($verial_product['RelacionUdsAux'] ?? 0),
            'standard_relation' => (float)($verial_product['RelacionUdsOCU'] ?? 0),
            'sell_auxiliary' => (bool)($verial_product['VenderUdsAux'] ?? false),
            'units_decimals' => (int)($verial_product['DecUdsVentas'] ?? 2),
            'price_decimals' => (int)($verial_product['DecPrecioVentas'] ?? 2)
        ];
        
        // Filtrar datos vacíos
        return array_filter($units_data, function($value) {
            return !empty($value) && $value !== 0;
        });
    }
    
    /**
     * Aplica configuración de unidades al producto
     * 
     * @param WC_Product $product Producto de WooCommerce
     * @param array $units_data Datos de unidades
     */
    private function applyUnitsConfiguration(WC_Product $product, array $units_data): void
    {
        // Configurar unidad principal
        if (!empty($units_data['main_unit'])) {
            $product->update_meta_data('_unit_of_measure', $units_data['main_unit']);
        }
        
        // Configurar precisión decimal
        if (isset($units_data['units_decimals'])) {
            $product->update_meta_data('_units_decimal_precision', $units_data['units_decimals']);
        }
        
        if (isset($units_data['price_decimals'])) {
            $product->update_meta_data('_price_decimal_precision', $units_data['price_decimals']);
        }
        
        // Configurar método de venta
        if (isset($units_data['sell_auxiliary'])) {
            $product->update_meta_data('_sell_by_auxiliary_unit', $units_data['sell_auxiliary']);
        }
    }
    
    /**
     * Guarda metadatos de unidades
     * 
     * @param WC_Product $product Producto de WooCommerce
     * @param array $units_data Datos de unidades
     */
    private function saveUnitsMetadata(WC_Product $product, array $units_data): void
    {
        $metadata_fields = [
            'main_unit' => '_verial_nombre_uds',
            'auxiliary_unit' => '_verial_nombre_uds_aux',
            'standard_unit' => '_verial_nombre_uds_ocu',
            'auxiliary_relation' => '_verial_relacion_uds_aux',
            'standard_relation' => '_verial_relacion_uds_ocu',
            'sell_auxiliary' => '_verial_vender_uds_aux',
            'units_decimals' => '_verial_dec_uds_ventas',
            'price_decimals' => '_verial_dec_precio_ventas'
        ];
        
        foreach ($metadata_fields as $field => $meta_key) {
            if (isset($units_data[$field])) {
                $product->update_meta_data($meta_key, $units_data[$field]);
            }
        }
        
        // Guardar metadato de configuración de unidades
        $product->update_meta_data('_verial_units_configured', true);
        $product->update_meta_data('_verial_units_data', $units_data);
    }
    
    /**
     * Crea atributos de unidades para el producto
     * 
     * @param WC_Product $product Producto de WooCommerce
     * @param array $units_data Datos de unidades
     */
    private function createUnitsAttributes(WC_Product $product, array $units_data): void
    {
        try {
            $attributes_created = 0;
            
            // Atributo de unidad principal
            if (!empty($units_data['main_unit'])) {
                $this->createUnitAttribute($product, 'Unidad Principal', 'pa_unidad_principal', $units_data['main_unit']);
                $attributes_created++;
            }
            
            // Atributo de unidad auxiliar
            if (!empty($units_data['auxiliary_unit'])) {
                $this->createUnitAttribute($product, 'Unidad Auxiliar', 'pa_unidad_auxiliar', $units_data['auxiliary_unit']);
                $attributes_created++;
            }
            
            // Atributo de unidad estándar
            if (!empty($units_data['standard_unit'])) {
                $this->createUnitAttribute($product, 'Unidad Estándar', 'pa_unidad_estandar', $units_data['standard_unit']);
                $attributes_created++;
            }
            
            // Atributo de factor de conversión
            if (!empty($units_data['auxiliary_relation'])) {
                $this->createUnitAttribute($product, 'Factor de Conversión', 'pa_factor_conversion', $units_data['auxiliary_relation']);
                $attributes_created++;
            }
            
            if ($attributes_created > 0) {
                $product->update_meta_data('_verial_units_attributes_count', $attributes_created);
                
                $this->getLogger()->debug('Atributos de unidades creados', [
                    'product_id' => $product->get_id(),
                    'attributes_created' => $attributes_created
                ]);
            }
            
        } catch (Exception $e) {
            $this->getLogger()->warning('Error creando atributos de unidades', [
                'product_id' => $product->get_id(),
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Crea un atributo de unidad específico
     * 
     * @param WC_Product $product Producto de WooCommerce
     * @param string $attribute_name Nombre del atributo
     * @param string $attribute_slug Slug del atributo
     * @param mixed $value Valor del atributo
     */
    private function createUnitAttribute(WC_Product $product, string $attribute_name, string $attribute_slug, $value): void
    {
        // Crear o obtener atributo
        $attribute_id = $this->create_or_get_product_attribute($attribute_name, $attribute_slug);
        if (!$attribute_id) {
            return;
        }
        
        // Crear o obtener término del atributo
        $term_id = $this->create_or_get_attribute_term($attribute_id, $value, $value);
        if (!$term_id) {
            return;
        }
        
        // Asignar término al producto
        wp_set_object_terms($product->get_id(), $term_id, $attribute_slug, true);
    }

    /**
     * Gestiona campos otros (Nexo, Ecotasas) basados en datos de Verial
     * 
     * @param WC_Product $product Producto de WooCommerce
     * @param array $verial_product Datos originales de Verial
     */
    private function manageOtherFields(WC_Product $product, array $verial_product): void
    {
        try {
            // Verificar que WooCommerce esté activo
            if (!function_exists('wc_get_product')) {
                $this->getLogger()->debug('WooCommerce no disponible para gestión de campos otros', [
                    'product_id' => $product->get_id()
                ]);
                return;
            }
            
            $other_fields_data = $this->extractOtherFieldsData($verial_product);
            
            if (!empty($other_fields_data)) {
                // Aplicar configuración de campos otros al producto
                $this->applyOtherFieldsConfiguration($product, $other_fields_data);
                
                // Guardar metadatos de campos otros
                $this->saveOtherFieldsMetadata($product, $other_fields_data);
                
                // Gestionar productos relacionados basados en Nexo
                $this->manageRelatedProductsByNexo($product, $other_fields_data);
                
                // Gestionar ecotasas
                $this->manageEcotaxHandling($product, $other_fields_data);
                
                $this->getLogger()->debug('Configuración de campos otros aplicada', [
                    'product_id' => $product->get_id(),
                    'other_fields_data' => $other_fields_data
                ]);
            }
            
        } catch (Exception $e) {
            $this->getLogger()->warning('Error gestionando campos otros', [
                'product_id' => $product->get_id(),
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Extrae datos de campos otros del producto de Verial
     * 
     * @param array $verial_product Datos del producto de Verial
     * @return array Datos de campos otros procesados
     */
    private function extractOtherFieldsData(array $verial_product): array
    {
        $other_fields_data = [
            'nexo' => $verial_product['Nexo'] ?? '',
            'id_articulo_ecotasas' => (int)($verial_product['ID_ArticuloEcotasas'] ?? 0),
            'precio_ecotasas' => (float)($verial_product['PrecioEcotasas'] ?? 0)
        ];
        
        // Filtrar datos vacíos
        return array_filter($other_fields_data, function($value) {
            return !empty($value) && $value !== 0;
        });
    }
    
    /**
     * Aplica configuración de campos otros al producto
     * 
     * @param WC_Product $product Producto de WooCommerce
     * @param array $other_fields_data Datos de campos otros
     */
    private function applyOtherFieldsConfiguration(WC_Product $product, array $other_fields_data): void
    {
        // Configurar Nexo para productos relacionados
        if (!empty($other_fields_data['nexo'])) {
            $product->update_meta_data('_verial_nexo', $other_fields_data['nexo']);
            $product->update_meta_data('_verial_has_nexo', true);
        }
        
        // Configurar ecotasas
        if (!empty($other_fields_data['id_articulo_ecotasas'])) {
            $product->update_meta_data('_verial_ecotax_article_id', $other_fields_data['id_articulo_ecotasas']);
            $product->update_meta_data('_verial_has_ecotax', true);
        }
        
        if (!empty($other_fields_data['precio_ecotasas'])) {
            $product->update_meta_data('_verial_ecotax_price', $other_fields_data['precio_ecotasas']);
            
            // Calcular precio final con ecotasas
            $current_price = $product->get_regular_price();
            if ($current_price > 0) {
                $final_price = $current_price + $other_fields_data['precio_ecotasas'];
                $product->set_regular_price($final_price);
                $product->set_price($final_price);
                
                $this->getLogger()->debug('Precio actualizado con ecotasas', [
                    'product_id' => $product->get_id(),
                    'original_price' => $current_price,
                    'ecotax_price' => $other_fields_data['precio_ecotasas'],
                    'final_price' => $final_price
                ]);
            }
        }
    }
    
    /**
     * Guarda metadatos de campos otros
     * 
     * @param WC_Product $product Producto de WooCommerce
     * @param array $other_fields_data Datos de campos otros
     */
    private function saveOtherFieldsMetadata(WC_Product $product, array $other_fields_data): void
    {
        $metadata_fields = [
            'nexo' => '_verial_nexo',
            'id_articulo_ecotasas' => '_verial_id_articulo_ecotasas',
            'precio_ecotasas' => '_verial_precio_ecotasas'
        ];
        
        foreach ($metadata_fields as $field => $meta_key) {
            if (isset($other_fields_data[$field])) {
                $product->update_meta_data($meta_key, $other_fields_data[$field]);
            }
        }
        
        // Guardar metadato de configuración de campos otros
        $product->update_meta_data('_verial_other_fields_configured', true);
        $product->update_meta_data('_verial_other_fields_data', $other_fields_data);
    }
    
    /**
     * Gestiona productos relacionados basados en Nexo
     * 
     * @param WC_Product $product Producto de WooCommerce
     * @param array $other_fields_data Datos de campos otros
     */
    private function manageRelatedProductsByNexo(WC_Product $product, array $other_fields_data): void
    {
        try {
            if (empty($other_fields_data['nexo'])) {
                return;
            }
            
            $nexo = $other_fields_data['nexo'];
            $product_id = $product->get_id();
            
            // Buscar productos con el mismo Nexo
            $related_products = $this->findProductsByNexo($nexo, $product_id);
            
            if (!empty($related_products)) {
                // Establecer productos relacionados
                $this->setRelatedProducts($product, $related_products);
                
                // Crear agrupación si hay múltiples productos
                if (count($related_products) > 1) {
                    $this->createProductGroup($nexo, array_merge([$product_id], $related_products));
                }
                
                $this->getLogger()->debug('Productos relacionados gestionados por Nexo', [
                    'product_id' => $product_id,
                    'nexo' => $nexo,
                    'related_products' => $related_products
                ]);
            }
            
        } catch (Exception $e) {
            $this->getLogger()->warning('Error gestionando productos relacionados por Nexo', [
                'product_id' => $product->get_id(),
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Busca productos con el mismo Nexo
     * 
     * @param string $nexo Valor del Nexo
     * @param int $exclude_product_id ID del producto a excluir
     * @return array IDs de productos relacionados
     */
    private function findProductsByNexo(string $nexo, int $exclude_product_id): array
    {
        global $wpdb;
        
        $related_products = $wpdb->get_col($wpdb->prepare("
            SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_verial_nexo' 
            AND meta_value = %s 
            AND post_id != %d
        ", $nexo, $exclude_product_id));
        
        return array_map('intval', $related_products);
    }
    
    /**
     * Establece productos relacionados
     * 
     * @param WC_Product $product Producto de WooCommerce
     * @param array $related_product_ids IDs de productos relacionados
     */
    private function setRelatedProducts(WC_Product $product, array $related_product_ids): void
    {
        // Establecer productos relacionados en WooCommerce
        $product->update_meta_data('_verial_related_products', $related_product_ids);
        
        // También establecer en el sistema nativo de WooCommerce si está disponible
        if (function_exists('wc_set_related_products')) {
            wc_set_related_products($product->get_id(), $related_product_ids);
        }
    }
    
    /**
     * Crea una agrupación de productos
     * 
     * @param string $nexo Valor del Nexo
     * @param array $product_ids IDs de productos a agrupar
     */
    private function createProductGroup(string $nexo, array $product_ids): void
    {
        // Crear término de agrupación
        $group_term = wp_insert_term(
            "Grupo Nexo: $nexo",
            'product_group',
            [
                'description' => "Agrupación automática basada en Nexo: $nexo",
                'slug' => 'nexo-' . sanitize_title($nexo)
            ]
        );
        
        if (!is_wp_error($group_term)) {
            $group_term_id = $group_term['term_id'];
            
            // Asignar productos a la agrupación
            foreach ($product_ids as $product_id) {
                wp_set_object_terms($product_id, $group_term_id, 'product_group', true);
            }
            
            $this->getLogger()->info('Agrupación de productos creada', [
                'nexo' => $nexo,
                'group_term_id' => $group_term_id,
                'product_ids' => $product_ids
            ]);
        }
    }
    
    /**
     * Gestiona el manejo de ecotasas
     * 
     * @param WC_Product $product Producto de WooCommerce
     * @param array $other_fields_data Datos de campos otros
     */
    private function manageEcotaxHandling(WC_Product $product, array $other_fields_data): void
    {
        try {
            if (empty($other_fields_data['id_articulo_ecotasas']) || empty($other_fields_data['precio_ecotasas'])) {
                return;
            }
            
            $ecotax_article_id = $other_fields_data['id_articulo_ecotasas'];
            $ecotax_price = $other_fields_data['precio_ecotasas'];
            
            // Crear atributo de ecotasa
            $this->createEcotaxAttribute($product, $ecotax_article_id, $ecotax_price);
            
            // Configurar cumplimiento ambiental
            $this->configureEnvironmentalCompliance($product, $ecotax_article_id, $ecotax_price);
            
            $this->getLogger()->debug('Ecotasas gestionadas', [
                'product_id' => $product->get_id(),
                'ecotax_article_id' => $ecotax_article_id,
                'ecotax_price' => $ecotax_price
            ]);
            
        } catch (Exception $e) {
            $this->getLogger()->warning('Error gestionando ecotasas', [
                'product_id' => $product->get_id(),
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Crea atributo de ecotasa
     * 
     * @param WC_Product $product Producto de WooCommerce
     * @param int $ecotax_article_id ID del artículo de ecotasa
     * @param float $ecotax_price Precio de la ecotasa
     */
    private function createEcotaxAttribute(WC_Product $product, int $ecotax_article_id, float $ecotax_price): void
    {
        // Crear o obtener atributo de ecotasa
        $attribute_id = $this->create_or_get_product_attribute('Ecotasa', 'pa_ecotax');
        if (!$attribute_id) {
            return;
        }
        
        // Crear término con información de ecotasa
        $term_name = "Ecotasa ID: $ecotax_article_id (+{$ecotax_price}€)";
        $term_id = $this->create_or_get_attribute_term($attribute_id, $term_name, $term_name);
        if (!$term_id) {
            return;
        }
        
        // Asignar término al producto
        wp_set_object_terms($product->get_id(), $term_id, 'pa_ecotax', true);
    }
    
    /**
     * Configura cumplimiento ambiental
     * 
     * @param WC_Product $product Producto de WooCommerce
     * @param int $ecotax_article_id ID del artículo de ecotasa
     * @param float $ecotax_price Precio de la ecotasa
     */
    private function configureEnvironmentalCompliance(WC_Product $product, int $ecotax_article_id, float $ecotax_price): void
    {
        // Metadatos de cumplimiento ambiental
        $product->update_meta_data('_verial_environmental_compliance', true);
        $product->update_meta_data('_verial_ecotax_article_id', $ecotax_article_id);
        $product->update_meta_data('_verial_ecotax_price_per_unit', $ecotax_price);
        $product->update_meta_data('_verial_ecotax_compliance_date', current_time('Y-m-d H:i:s'));
        
        // Configurar etiqueta ambiental
        $product->update_meta_data('_verial_environmental_label', 'Ecotasa aplicada');
    }

    /**
     * Maneja operaciones después del guardado
     */
    private function handlePostSaveOperations(int $product_id, array $wc_product_data, array $verial_product, array $batch_data): void
    {
        // Operaciones post-guardado iniciadas
        
        // Imágenes principales
        // ✅ REFACTORIZADO: Usar helper centralizado (con negación)
        if (!$this->isEmptyArrayValue($wc_product_data, 'images')) {
            $this->setProductImages($product_id, $wc_product_data['images']);
        }
        
        // Galería de imágenes
        // ✅ REFACTORIZADO: Usar helper centralizado (con negación)
        if (!$this->isEmptyArrayValue($wc_product_data, 'gallery')) {
            $this->setProductGallery($product_id, $wc_product_data['gallery']);
        }
        
        // Metadatos de Verial (legacy compatibility)
        $this->updateVerialProductMetadata($product_id, $verial_product, $batch_data);
        
        // Mapeo de productos (para tracking)
        if (!empty($verial_product['Id'])) {
            MapProduct::upsert_product_mapping(
                $product_id, 
                (int)$verial_product['Id'], 
                $wc_product_data['sku']
            );
        }
    }
    
    /**
     * ✅ OPTIMIZADO: Método desactivado - generaba demasiado volumen de logs por producto
     * Los logs se consolidan a nivel de batch en lugar de por producto individual
     * 
     * @deprecated Este método ya no genera logs para evitar archivos de log demasiado grandes
     */
    private function logProductSuccess(int $product_id, string $sku, array $wc_product_data, string $action): void
    {
        // Método desactivado - no genera logs por producto individual
        // Los logs se consolidan a nivel de batch
    }

    /**
     * ✅ HELPER: Procesa una imagen individual y retorna el attachment_id
     * 
     * En la arquitectura de dos fases, las imágenes ya están procesadas
     * y se pasan como attachment_ids directamente. Este método ahora
     * acepta tanto attachment_ids como Base64 (para compatibilidad).
     * 
     * @param   mixed  $image       Imagen a procesar (ID numérico, Base64 o URL).
     * @param   int    $product_id  ID del producto asociado.
     * @param   string $context     Contexto para logging ('main_image' o 'gallery').
     * @return  int|false ID del attachment o false si no se pudo procesar.
     */
    private function processImageItem($image, int $product_id, string $context = 'image'): int|false
    {
        try {
            // ✅ NUEVO: Si es un ID numérico, retornar directamente (arquitectura dos fases)
            if (is_numeric($image)) {
                $attachment_id = (int)$image;
                
                // Verificar que el attachment existe
                $attachment = get_post($attachment_id);
                if ($attachment && get_post_type($attachment_id) === 'attachment') {
                    $this->getLogger()->debug("Imagen procesada desde attachment ID ({$context})", [
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id
                    ]);
                    return $attachment_id;
                } else {
                    $this->getLogger()->warning("Attachment ID no válido", [
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id
                    ]);
                    return false;
                }
            }
            
            // ⚠️ CÓDIGO LEGACY COMENTADO: Procesamiento Base64
            // Este código se ha comentado porque en la arquitectura de dos fases
            // las imágenes ya están procesadas. Solo se mantiene para rollback.
            //
            // Para rollback, descomentar este bloque.
            //
            // Fecha de comentario: 2025-11-04
            // Arquitectura: Dos Fases v1.0
            /*
            elseif (is_string($image) && str_starts_with($image, 'data:image/')) {
                // Es una imagen Base64, crear attachment
                $attachment_id = $this->createAttachmentFromBase64($image, $product_id);
                if ($attachment_id) {
                    $this->getLogger()->debug("Imagen procesada desde Base64 ({$context})", [
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id
                    ]);
                    return $attachment_id;
                } else {
                    $this->getLogger()->error("Error creando attachment desde Base64 ({$context})", [
                        'product_id' => $product_id
                    ]);
                    return false;
                }
            }
            */
            
            elseif (is_string($image)) {
                // Es una URL externa (por ahora solo logueamos)
                $this->getLogger()->info("Imagen detectada como URL externa ({$context})", [
                    'product_id' => $product_id,
                    'image_url' => substr($image, 0, 50) . '...'
                ]);
                return false;
            } else {
                // Formato no reconocido
                $this->getLogger()->warning("Formato de imagen no reconocido ({$context})", [
                    'product_id' => $product_id,
                    'image_type' => gettype($image),
                    'image_preview' => is_string($image) ? substr($image, 0, 50) . '...' : 'N/A'
                ]);
                return false;
            }
        } catch (Exception $e) {
            $this->getLogger()->error("Excepción procesando imagen ({$context})", [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Establece las imágenes principales del producto
     *
     * @param int $product_id ID del producto
     * @param array $images Array de URLs o IDs de imágenes
     * @return void
     */
    private function setProductImages(int $product_id, array $images): void
    {
        try {
            if (empty($images)) {
                // No hay imágenes para procesar
                return;
            }

            // ✅ REFACTORIZADO: Usar helper centralizado para procesar imagen
            // Tomar la primera imagen como imagen principal
            $main_image = $images[0];
            $attachment_id = $this->processImageItem($main_image, $product_id, 'main_image');
            
            if ($attachment_id) {
                $thumbnail_result = mi_integracion_api_set_post_thumbnail_safe($product_id, $attachment_id);
                if ($thumbnail_result) {
                    $this->getLogger()->debug('Imagen principal establecida', [
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id
                    ]);
                }
            }
            
        } catch (Exception $e) {
            $this->getLogger()->error('Error estableciendo imagen principal', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Establece la galería de imágenes del producto
     *
     * @param int $product_id ID del producto
     * @param array $gallery Array de URLs o IDs de imágenes
     * @return void
     */
    private function setProductGallery(int $product_id, array $gallery): void
    {
        try {
            if (empty($gallery)) {
                return;
            }

            $gallery_ids = [];
            
            // ✅ REFACTORIZADO: Usar helper centralizado para procesar cada imagen
            foreach ($gallery as $image) {
                $attachment_id = $this->processImageItem($image, $product_id, 'gallery');
                if ($attachment_id) {
                    $gallery_ids[] = $attachment_id;
                }
            }
            
            if (!empty($gallery_ids)) {
                update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
            }
            
        } catch (Exception $e) {
            $this->getLogger()->error('Error estableciendo galería de imágenes', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Crea un attachment de WordPress desde una imagen Base64
     *
     * @param string $base64_image Imagen en formato Base64 (data:image/...)
     * @param int $product_id ID del producto asociado
     * @return int|false ID del attachment creado o false en caso de error
     */
    private function createAttachmentFromBase64(string $base64_image, int $product_id): int|false
    {
        try {
            // Iniciando creación de attachment desde Base64
            
            // Extraer el tipo de imagen y los datos Base64
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64_image, $matches)) {
                $image_type = $matches[1];
                $image_data = base64_decode($matches[2]);
                
                // Imagen Base64 decodificada
                
                if ($image_data === false) {
                    $this->getLogger()->error('Error decodificando imagen Base64', [
                        'product_id' => $product_id
                    ]);
                    return false;
                }
                
                // Generar nombre único para el archivo
                $filename = 'verial-image-' . $product_id . '-' . uniqid() . '.' . $image_type;
                
                // Subiendo archivo a WordPress
                
                // Subir archivo a WordPress
                $upload = mi_integracion_api_upload_bits_safe($filename, null, $image_data);
                
                if ($upload === false) {
                    $this->getLogger()->error('Error subiendo imagen Base64', [
                        'product_id' => $product_id,
                        'filename' => $filename
                    ]);
                    return false;
                }
                
                // Crear attachment
                if (!function_exists('wp_insert_attachment')) {
                    $this->getLogger()->warning('wp_insert_attachment no disponible fuera de WordPress');
                    return false;
                }
                
                $attachment = [
                    'post_mime_type' => 'image/' . $image_type,
                    'post_title' => mi_integracion_api_sanitize_file_name_safe($filename),
                    'post_content' => '',
                    'post_status' => 'inherit'
                ];
                
                $attachment_id = call_user_func('wp_insert_attachment', $attachment, $upload['file'], $product_id);
                
                if (is_wp_error($attachment_id)) {
                    $this->getLogger()->error('Error creando attachment', [
                        'product_id' => $product_id,
                        'error' => $attachment_id->get_error_message(),
                        'error_code' => $attachment_id->get_error_code(),
                        'error_data' => $attachment_id->get_error_data()
                    ]);
                    return false;
                }
                
                // Generar metadatos del attachment
                if (defined('ABSPATH')) {
                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                    $attachment_data = call_user_func('wp_generate_attachment_metadata', $attachment_id, $upload['file']);
                    call_user_func('wp_update_attachment_metadata', $attachment_id, $attachment_data);
                }
                
                $this->getLogger()->info('Imagen Base64 procesada exitosamente', [
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id,
                    'filename' => $filename
                ]);
                
                return $attachment_id;
                
            } else {
                $this->getLogger()->error('Formato de imagen Base64 inválido', [
                    'product_id' => $product_id,
                    'image_format' => substr($base64_image, 0, 50) . '...'
                ]);
                return false;
            }
            
        } catch (Exception $e) {
            $this->getLogger()->error('Excepción creando attachment desde Base64', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Actualiza metadatos de Verial en el producto de WooCommerce
     * 
     * @param int $product_id ID del producto en WooCommerce
     * @param array $verial_product Datos originales de Verial
     * @param array $batch_data Datos del batch (opcional)
     */
    private function updateVerialProductMetadata(int $product_id, array $verial_product, array $batch_data = []): void
    {
        try {
            $product = wc_get_product($product_id);
            if (!$product) {
                $this->getLogger()->error("❌ No se pudo obtener el producto", [
                    'product_id' => $product_id
                ]);
                return;
            }
            
            // Guardar ID de Verial
            if (!empty($verial_product['Id'])) {
                $product->update_meta_data('_verial_id', (int)$verial_product['Id']);
            } else {
                $this->getLogger()->warning("⚠️ Verial ID vacío", [
                    'product_id' => $product_id,
                    'verial_product_keys' => array_keys($verial_product)
                ]);
            }
            
            // Guardar batch ID si está disponible
            if (!empty($batch_data['batch_id'])) {
                $product->update_meta_data('_verial_creation_batch', $batch_data['batch_id']);
            }
            
            // Guardar datos adicionales de Verial
            $verial_metadata = [
                'verial_nombre' => $verial_product['Nombre'] ?? '',
                'verial_referencia' => $verial_product['ReferenciaBarras'] ?? '',
                'verial_categoria' => $verial_product['ID_Categoria'] ?? 0,
                'verial_fabricante' => $verial_product['ID_Fabricante'] ?? 0,
                'verial_tipo' => $verial_product['Tipo'] ?? 0
            ];
            
            foreach ($verial_metadata as $key => $value) {
                // Guardar todos los valores, incluyendo 0 (que es válido para IDs)
                if ($value !== '' && $value !== null) {
                    $meta_key = '_' . $key;
                    $product->update_meta_data($meta_key, $value);
                }
            }
            
            // ✅ Recopilar información de metadatos para log consolidado
            $metadata_info = [];
            
            // ✅ NUEVO: Guardar campos específicos de libros si es Tipo = 2
            if (isset($verial_product['Tipo']) && $verial_product['Tipo'] == 2) {
                $book_info = $this->saveBookFieldsMetadata($product, $verial_product);
                $metadata_info['book_fields_saved'] = $book_info['book_fields_saved'];
                $metadata_info['additional_fields_saved'] = $book_info['additional_fields_saved'];
                $metadata_info['is_book'] = true;
            } else {
                // Para productos que no son libros, guardar solo campos adicionales
                $additional_fields_saved = $this->saveAdditionalFieldsMetadata($product, $verial_product, true);
                $metadata_info['additional_fields_saved'] = $additional_fields_saved;
                $metadata_info['is_book'] = false;
            }
            
            // ✅ NUEVO: Aplicar lógica de visibilidad basada en fechas
            $visibility_info = $this->applyDateBasedVisibility($product, $verial_product, true);
            $metadata_info['visibility_changed'] = $visibility_info['changed'] ?? false;
            $metadata_info['visibility_reason'] = $visibility_info['reason'] ?? 'no_restrictions';
            
            // ✅ NUEVO: Crear atributos dinámicos de campos auxiliares
            $this->createDynamicAttributesFromAuxFields($product, $verial_product);
            
            // ✅ NUEVO: Gestionar clases de impuestos dinámicas
            $tax_info = $this->manageDynamicTaxClasses($product, $verial_product, true);
            $metadata_info['tax_available'] = $tax_info['available'] ?? false;
            
            // ✅ NUEVO: Gestionar unidades dinámicas
            $units_info = $this->manageDynamicUnits($product, $verial_product, true);
            $metadata_info['units_applied'] = $units_info['applied'] ?? false;
            
            // ✅ NUEVO: Gestionar campos otros (Nexo, Ecotasas)
            $this->manageOtherFields($product, $verial_product);
            
            // ✅ OPTIMIZADO: Log consolidado con toda la información
            $save_result = $product->save();
            
            if ($save_result) {
                $this->getLogger()->info("✅ Metadatos de Verial guardados exitosamente", [
                    'product_id' => $product_id,
                    'verial_id' => $verial_product['Id'] ?? 'N/A',
                    'is_book' => $metadata_info['is_book'],
                    'book_fields_saved' => $metadata_info['book_fields_saved'] ?? 0,
                    'additional_fields_saved' => $metadata_info['additional_fields_saved'] ?? 0,
                    'visibility_changed' => $metadata_info['visibility_changed'],
                    'visibility_reason' => $metadata_info['visibility_reason'],
                    'tax_available' => $metadata_info['tax_available'],
                    'units_applied' => $metadata_info['units_applied']
                ]);
            } else {
                $this->getLogger()->error("❌ Error guardando metadatos de Verial", [
                    'product_id' => $product_id,
                    'verial_id' => $verial_product['Id'] ?? 'N/A'
                ]);
            }
            
        } catch (Exception $e) {
            $this->getLogger()->error('❌ Excepción actualizando metadatos de Verial', [
                'product_id' => $product_id,
                'verial_id' => $verial_product['Id'] ?? 'N/A',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Procesa un lote de sincronización específico
     *
     * @param string $entity Entidad a sincronizar (products, categories, orders, etc.)
     * @param string $direction Dirección de sincronización (wc_to_verial, verial_to_wc)
     * @param int $offset Offset del lote
     * @param int $batch_size Tamaño del lote
     * @return array Resultado del procesamiento
     * @throws Throwable
     */
    public function process_sync_batch(string $entity, string $direction, int $offset, int $batch_size): array
    {
        // Registrar el inicio del procesamiento
        $this->getLogger()->info("Procesando lote de sincronización", [
            'entity' => $entity,
            'direction' => $direction,
            'offset' => $offset,
            'batch_size' => $batch_size
        ]);

        // Determinar qué método específico llamar según entidad y dirección
        if ($direction === 'wc_to_verial') {
            // TODO: Implementar sincronización de WooCommerce a Verial
            // ✅ REFACTORIZADO: Usar helper centralizado para respuesta de éxito
            $result = $this->buildSuccessResponse(0, "Sincronización de $entity de WooCommerce a Verial no implementada", [
                'errors' => []
            ]);
        } elseif ($entity === 'products') {
            // Sincronizar productos de Verial a WooCommerce usando nuestro sistema integrado
            // CORRECCIÓN: Los índices de la API de Verial comienzan en 1, no en 0
            $inicio = $offset + 1;  // Convertir offset 0-based a 1-based
            $fin = $offset + $batch_size;
            
            // ✅ OPTIMIZACIÓN: Usar método simplificado con configuración por defecto
            $result = $this->processProductBatch($inicio, $fin, $batch_size);
        } elseif ($entity === 'orders') {
            // Sincronizar órdenes de Verial a WooCommerce
            // ✅ REFACTORIZADO: Usar helper centralizado para respuesta de éxito
            $result = $this->buildSuccessResponse(0, 'Sincronización de órdenes no implementada', [
                'errors' => []
            ]);
        } elseif ($entity === 'categories') {
            // Sincronización de categorías
            // ✅ REFACTORIZADO: Usar helper centralizado para respuesta de éxito
            $result = $this->buildSuccessResponse(0, 'Sincronización de categorías delegada a Sync_Manager', [
                'errors' => []
            ]);
        } elseif ($entity === 'geo') {
            // Sincronización de datos geográficos
            // ✅ REFACTORIZADO: Usar helper centralizado para respuesta de éxito
            $result = $this->buildSuccessResponse(0, 'Sincronización de datos geográficos no implementada', [
                'errors' => []
            ]);
        } elseif ($entity === 'config') {
            // Sincronización de configuración
            // ✅ REFACTORIZADO: Usar helper centralizado para respuesta de éxito
            $result = $this->buildSuccessResponse(0, 'Sincronización de configuración no implementada', [
                'errors' => []
            ]);
        } else {
            return [
                'success' => false,
                'error' => "Entidad desconocida: $entity",
                'error_code' => 404
            ];
        }

        // Transformar el resultado al formato esperado
        if (is_wp_error($result)) {
            $errorCode = $result->get_error_code();
            $errorCode = is_numeric($errorCode) ? intval($errorCode) : 0;
            
            return [
                'success' => false,
                'error' => $result->get_error_message(),
                'error_code' => $errorCode,
                'error_data' => $result->get_error_data()
            ];
        }

        // Si el resultado es un array pero no tiene 'success', asumimos éxito
        if (!isset($result['success'])) {
            return [
                'success' => true,
                'processed' => $result['count'] ?? count($result),
                'errors' => $result['errors'] ?? []
            ];
        }

        return $result;
    }

    /**
     * MIGRADO DESDE Sync_Manager: Limpia transients específicos de batch después de completar
     * 
     * @return array Resultado de la limpieza
     */
    public function cleanupBatchTransients(): array
    {
        $batchTransients = [
            'mia_sync_current_batch_offset',
            'mia_sync_current_batch_limit',
            'mia_sync_current_batch_time'
        ];
        
        $results = [];
        $totalCleaned = 0;
        
        foreach ($batchTransients as $cacheKey) {
            $policy = $this->getRetentionPolicy($cacheKey);
            
            // Solo limpiar si no es crítico y la sincronización no está en progreso
            if ($policy['keep_always']) {
                $results[$cacheKey] = [
                    'status' => 'skipped',
                    'reason' => 'critical_transient'
                ];
                continue;
            }
            
            // CENTRALIZADO: Usar SyncStatusHelper para obtener estado
            $syncStatus = SyncStatusHelper::getCurrentSyncInfo();
            if ($syncStatus && isset($syncStatus['status']) && $syncStatus['status'] === 'running') {
                $results[$cacheKey] = [
                    'status' => 'skipped',
                    'reason' => 'sync_still_running'
                ];
                continue;
            }
            
            // Limpiar transient de batch
            $result = delete_transient($cacheKey);
            $results[$cacheKey] = [
                'status' => $result ? 'success' : 'error',
                'timestamp' => current_time('timestamp'),
                'reason' => 'batch_completed'
            ];
            
            if ($result) {
                $totalCleaned++;
            }
        }

        $this->logger->info("Limpieza de transients de batch completada", [
            'total_batch_transients' => count($batchTransients),
            'successfully_cleaned' => $totalCleaned,
            'results' => $results
        ]);
        
        return [
            'total_batch_transients' => count($batchTransients),
            'successfully_cleaned' => $totalCleaned,
            'results' => $results
        ];
    }

    /**
     * MIGRADO DESDE Sync_Manager: Obtiene la política de retención para una clave de caché
     * 
     * @param string $cacheKey Clave del caché
     * @return array Política de retención
     */
    private function getRetentionPolicy(string $cacheKey): array
    {
        $config = $this->getCustomTTLConfiguration($cacheKey);
        
        // ✅ REFACTORIZADO: Usar helper centralizado para política por defecto
        $policy = $config['retention_policy'] ?? $this->getDefaultRetentionPolicy();
        
        // Validar y normalizar la política
        $validTypes = ['critical', 'sync', 'cache', 'state', 'temporary'];
        $validStrategies = ['keep_always', 'sync_complete', 'age_based', 'inactivity_based', 'immediate_cleanup'];
        
        if (!in_array($policy['type'], $validTypes)) {
            $policy['type'] = 'temporary';
        }
        
        if (!in_array($policy['strategy'], $validStrategies)) {
            $policy['strategy'] = 'immediate_cleanup';
        }
        
        // Asegurar valores booleanos
        $policy['keep_always'] = (bool) ($policy['keep_always'] ?? false);
        $policy['cleanup_after_sync'] = (bool) ($policy['cleanup_after_sync'] ?? false);
        $policy['cleanup_by_age'] = (bool) ($policy['cleanup_by_age'] ?? false);
        $policy['cleanup_by_inactivity'] = (bool) ($policy['cleanup_by_inactivity'] ?? false);
        $policy['cleanup_immediate'] = (bool) ($policy['cleanup_immediate'] ?? false);
        
        // Asegurar valores numéricos
        $policy['max_age_hours'] = (int) ($policy['max_age_hours'] ?? 1);
        $policy['inactivity_threshold_hours'] = (int) ($policy['inactivity_threshold_hours'] ?? 2);
        
        return $policy;
    }

    /**
     * ✅ HELPER: Crea una política de retención estándar para claves de sincronización
     * 
     * Este método centraliza la configuración de políticas de retención para claves
     * relacionadas con sincronización (batch_offset, batch_limit, batch_time).
     * Elimina duplicación de código y facilita el mantenimiento.
     * 
     * @return array Política de retención para sincronización
     */
    private function createSyncRetentionPolicy(): array
    {
        return [
            'type' => 'sync',
            'strategy' => 'sync_complete',
            'keep_always' => false,
            'cleanup_after_sync' => true,
            'cleanup_by_age' => true,
            'cleanup_by_inactivity' => false,
            'cleanup_immediate' => false,
            'max_age_hours' => 24,
            'inactivity_threshold_hours' => 2
        ];
    }

    /**
     * ✅ HELPER: Obtiene la política de retención por defecto
     * 
     * Este método centraliza la política de retención por defecto para evitar
     * duplicación entre getRetentionPolicy() y getCustomTTLConfiguration().
     * 
     * @return array Política de retención por defecto
     */
    private function getDefaultRetentionPolicy(): array
    {
        return [
            'type' => 'temporary',
            'strategy' => 'immediate_cleanup',
            'keep_always' => false,
            'cleanup_after_sync' => false,
            'cleanup_by_age' => false,
            'cleanup_by_inactivity' => false,
            'cleanup_immediate' => true,
            'max_age_hours' => 1,
            'inactivity_threshold_hours' => 2
        ];
    }

    /**
     * ✅ HELPER: Valida si el resultado de paginación de imágenes es válido
     * 
     * Detecta si la paginación devolvió imágenes de pocos productos, lo que indica
     * que la paginación no funcionó correctamente. Centraliza la lógica de validación
     * que estaba duplicada en validación de caché y validación de paginación.
     * 
     * @param array $imagenes_array Array de imágenes con ID_Articulo
     * @param array $productos_lote Array de IDs de productos esperados en el lote
     * @param array $options Opciones de validación:
     *   - 'min_unique_products': Mínimo de productos únicos esperados (default: 3)
     *   - 'strict_mode': Si es true, fallback con 1 producto único (default: true)
     * @return array Resultado de validación con:
     *   - 'is_valid': bool - Si el resultado es válido
     *   - 'needs_fallback': bool - Si necesita usar fallback
     *   - 'productos_unicos': array - IDs de productos únicos encontrados
     *   - 'productos_unicos_count': int - Cantidad de productos únicos
     *   - 'reason': string - Razón por la que es inválido (si aplica)
     */
    private function validateImagePaginationResult(
        array $imagenes_array,
        array $productos_lote,
        array $options = []
    ): array {
        // Extraer productos únicos de las imágenes
        $productos_en_imagenes = [];
        foreach ($imagenes_array as $imagen) {
            if (isset($imagen['ID_Articulo'])) {
                $productos_en_imagenes[] = (int)$imagen['ID_Articulo'];
            }
        }
        $productos_unicos = array_unique($productos_en_imagenes);
        $productos_unicos_count = count($productos_unicos);

        // Normalizar productos del lote
        $productos_lote_normalizados = array_map('intval', $productos_lote);

        // Opciones
        $min_unique = $options['min_unique_products'] ?? 3;
        $strict_mode = $options['strict_mode'] ?? true;

        // Validación estricta: si solo hay 1 producto único, siempre fallback
        if ($strict_mode && $productos_unicos_count === 1) {
            return [
                'is_valid' => false,
                'needs_fallback' => true,
                'productos_unicos' => $productos_unicos,
                'productos_unicos_count' => $productos_unicos_count,
                'reason' => 'single_product_detected'
            ];
        }

        // Validación por umbral mínimo
        $threshold = min($min_unique, count($productos_lote_normalizados));
        if ($productos_unicos_count < $threshold) {
            // Verificar si los productos únicos coinciden con el lote
            $productos_coincidentes = array_intersect($productos_unicos, $productos_lote_normalizados);
            $coincidencia_count = count($productos_coincidentes);

            // Si hay menos coincidencias que productos únicos, el resultado es inválido
            if ($coincidencia_count < $productos_unicos_count) {
                return [
                    'is_valid' => false,
                    'needs_fallback' => true,
                    'productos_unicos' => $productos_unicos,
                    'productos_unicos_count' => $productos_unicos_count,
                    'reason' => 'insufficient_unique_products',
                    'threshold' => $threshold,
                    'coincident_products' => $coincidencia_count
                ];
            }
        }

        // Resultado válido
        return [
            'is_valid' => true,
            'needs_fallback' => false,
            'productos_unicos' => $productos_unicos,
            'productos_unicos_count' => $productos_unicos_count
        ];
    }

    /**
     * MIGRADO DESDE Sync_Manager: Obtiene la configuración personalizada de TTL
     * 
     * @param string $cacheKey Clave del caché
     * @return array Configuración de TTL
     */
    private function getCustomTTLConfiguration(string $cacheKey): array
    {
        // ✅ REFACTORIZADO: Claves que comparten la misma política de sincronización
        // Elimina duplicación de 3 configuraciones idénticas
        $sync_cache_keys = [
            'mia_sync_current_batch_offset',
            'mia_sync_current_batch_limit',
            'mia_sync_current_batch_time'
        ];

        // ✅ REFACTORIZADO: Si es una clave de sincronización, usar política compartida
        if (in_array($cacheKey, $sync_cache_keys)) {
            return [
                'retention_policy' => $this->createSyncRetentionPolicy()
            ];
        }

        // ✅ REFACTORIZADO: Configuración por defecto usando helper centralizado
        return [
            'retention_policy' => $this->getDefaultRetentionPolicy()
        ];
    }

	/**
	 * Ejecuta limpieza por lotes con control de tiempo
	 * 
	 * @param int $batchSize Tamaño del lote (1-100)
	 * @param int $maxExecutionTime Tiempo máximo de ejecución en segundos (1-300)
	 * @return array Resultados de la limpieza por lotes
	 */
	public function executeBatchCleanup(int $batchSize = 10, int $maxExecutionTime = 30): array
	{
		// VALIDACIÓN CRÍTICA - Protección contra DoS y valores maliciosos
		if ($batchSize < 1 || $batchSize > 100) {
			throw new InvalidArgumentException(
				'batchSize debe estar entre 1 y 100. Valor recibido: ' . $batchSize
			);
		}
		
		if ($maxExecutionTime < 1 || $maxExecutionTime > 300) {
			throw new InvalidArgumentException(
				'maxExecutionTime debe estar entre 1 y 300 segundos. Valor recibido: ' . $maxExecutionTime
			);
		}
		
		$startTime = time();
		$results = [
			'batches_processed' => 0,
			'total_transients_cleaned' => 0,
			'total_space_freed_mb' => 0,
			'execution_time_seconds' => 0,
			'batches' => [],
			'errors' => []
		];
		
		try {
			$cacheKeys = $this->getMonitoredCacheKeys();
			$largeTransients = $this->identifyLargeTransients($cacheKeys, 5 * 1024 * 1024); // >5MB
			
			if (empty($largeTransients)) {
				return $results;
			}
			
			// Dividir en lotes
			$batches = array_chunk($largeTransients, $batchSize);
			
			foreach ($batches as $batchIndex => $batch) {
				// Verificar tiempo de ejecución
				if ((time() - $startTime) > $maxExecutionTime) {
					$results['errors'][] = 'Tiempo de ejecución excedido';
					break;
				}
				
				$batchResults = $this->processCleanupBatch($batch, $batchIndex);
				$results['batches'][] = $batchResults;
				$results['batches_processed']++;
				$results['total_transients_cleaned'] += $batchResults['transients_cleaned'];
				$results['total_space_freed_mb'] += $batchResults['space_freed_mb'];
				
				// Pausa entre lotes para no sobrecargar el sistema
				if ($batchIndex < count($batches) - 1) {
					usleep(100000); // 0.1 segundos
				}
			}
			
		} catch (Exception $e) {
			$results['errors'][] = 'Error en limpieza por lotes: ' . $e->getMessage();
		}
		
		$results['execution_time_seconds'] = time() - $startTime;
		
		// Registrar en historial
		$this->recordBatchCleanupHistory($results);
		
		return $results;
	}

	/**
	 * 
	 * @return array Claves de caché para monitorear
	 */
	private function getMonitoredCacheKeys(): array
	{
		return [
			'mia_category_names_cache',
			'mia_sync_batch_times',
			'mia_sync_completed_batches',
			'mia_sync_current_batch_offset',
			'mia_sync_current_batch_limit',
			'mia_sync_current_batch_time',
			'mia_sync_batch_start_time',
			'mia_sync_current_product_sku',
			'mia_sync_current_product_name',
			'mia_sync_last_product',
			'mia_sync_last_product_time',
			'mia_sync_processed_skus',
			'mia_current_sync_operation_id'
		];
	}

	/**
     * Identifica transients grandes para limpieza
	 * 
	 * @param array $cacheKeys Claves de caché a evaluar
	 * @param int $sizeThreshold Umbral de tamaño en bytes
	 * @return array Transients que exceden el umbral
	 */
	private function identifyLargeTransients(array $cacheKeys, int $sizeThreshold): array
	{
		$largeTransients = [];
		
		foreach ($cacheKeys as $cacheKey) {
			// ✅ REFACTORIZADO: Usar helper centralizado para verificar existencia
			$data = $this->getTransientIfExists($cacheKey);
			if ($data !== null) {
				// ✅ REFACTORIZADO: Usar helper centralizado para calcular tamaño
				$sizeInfo = $this->calculateDataSize($data);
				if ($sizeInfo['bytes'] > $sizeThreshold) {
					$largeTransients[] = [
						'key' => $cacheKey,
						'size_bytes' => $sizeInfo['bytes'],
						'size_mb' => $sizeInfo['mb']
					];
				}
			}
		}
		
		// Ordenar por tamaño (más grandes primero)
		usort($largeTransients, function($a, $b) {
			return $b['size_bytes'] - $a['size_bytes'];
		});
		
		return $largeTransients;
	}

	/**
	 * Procesa un lote específico de limpieza
	 * 
	 * @param array $batch Lote de transients a procesar
	 * @param int $batchIndex Índice del lote
	 * @return array Resultados del lote
	 */
	private function processCleanupBatch(array $batch, int $batchIndex): array
	{
		$batchResults = [
			'batch_index' => $batchIndex,
			'batch_size' => count($batch),
			'transients_cleaned' => 0,
			'space_freed_mb' => 0,
			'errors' => [],
			'start_time' => time()
		];
		
		foreach ($batch as $transient) {
			try {
				$cacheKey = is_array($transient) ? $transient['key'] : $transient;
				$cleanupResult = $this->cleanupSingleTransient($cacheKey);
				
				if ($cleanupResult['success']) {
					$batchResults['transients_cleaned']++;
					$batchResults['space_freed_mb'] += $cleanupResult['space_freed_mb'];
				} else {
					$batchResults['errors'][] = "Error en $cacheKey: " . $cleanupResult['error'];
				}
				
			} catch (Exception $e) {
				$cacheKey = is_array($transient) ? $transient['key'] : $transient;
				$batchResults['errors'][] = "Excepción en $cacheKey: " . $e->getMessage();
			}
		}
		
		$batchResults['end_time'] = time();
		$batchResults['execution_time'] = $batchResults['end_time'] - $batchResults['start_time'];
		
		return $batchResults;
	}

	/**
	 * ✅ HELPER: Ejecuta limpieza de transients con filtrado configurable
	 * 
	 * Este método centraliza la lógica de limpieza de transients que estaba
	 * duplicada en executeEmergencyCleanup(), executeMaintenanceCleanup() y cleanOldCache().
	 * Elimina ~90 líneas de código duplicado.
	 * 
	 * @param array $options Opciones de filtrado:
	 *   - 'age_threshold': Edad mínima en segundos para limpiar (null = sin filtro de edad)
	 *   - 'error_context': Contexto para mensajes de error (ej: 'emergencia', 'mantenimiento')
	 * @return array Resultado de limpieza con:
	 *   - 'success': bool - Si la operación fue exitosa
	 *   - 'cleaned_count': int - Cantidad de transients limpiados
	 *   - 'space_freed_mb': float - Espacio liberado en MB
	 *   - 'errors': array - Array de errores encontrados
	 */
	private function executeTransientCleanup(array $options = []): array
	{
		$result = [
			'success' => false,
			'cleaned_count' => 0,
			'space_freed_mb' => 0,
			'errors' => []
		];

		$ageThreshold = $options['age_threshold'] ?? null;
		$errorContext = $options['error_context'] ?? 'limpieza';

		try {
			$cacheKeys = $this->getMonitoredCacheKeys();
			$cleanedCount = 0;
			$spaceFreed = 0;

			foreach ($cacheKeys as $cacheKey) {
				// Filtrar por edad si se especifica
				if ($ageThreshold !== null) {
					$transientAge = $this->getTransientAge($cacheKey);
					if ($transientAge <= $ageThreshold) {
						continue; // Saltar este transient (no cumple el umbral de edad)
					}
				}

				// Limpiar el transient
				$cleanupResult = $this->cleanupSingleTransient($cacheKey);
				if ($cleanupResult['success']) {
					$cleanedCount++;
					$spaceFreed += $cleanupResult['space_freed_mb'];
				}
			}

			$result['success'] = true;
			$result['cleaned_count'] = $cleanedCount;
			$result['space_freed_mb'] = $spaceFreed;

		} catch (Exception $e) {
			$result['errors'][] = "Error en limpieza de {$errorContext}: " . $e->getMessage();
		}

		return $result;
	}

	private function cleanupSingleTransient(string $cacheKey): array
	{
		$result = [
			'success' => false,
			'space_freed_mb' => 0,
			'error' => null
		];
		
		try {
			// Obtener el tamaño antes de eliminar
			// ✅ REFACTORIZADO: Usar helper centralizado para verificar existencia
			$data = $this->getTransientIfExists($cacheKey);
			if ($data !== null) {
				// ✅ REFACTORIZADO: Usar helper centralizado para calcular tamaño
				$sizeInfo = $this->calculateDataSize($data);
				
				// Eliminar el transient
				if (delete_transient($cacheKey)) {
					$result['success'] = true;
					$result['space_freed_mb'] = $sizeInfo['mb'];
					
					// Registrar la limpieza
					$this->recordTransientCleanup($cacheKey, $sizeInfo['bytes'], 'batch');
				} else {
					$result['error'] = 'No se pudo eliminar el transient';
				}
			} else {
				$result['error'] = 'Transient no existe o ya expiró';
			}
			
		} catch (Exception $e) {
			$result['error'] = $e->getMessage();
		}
		
		return $result;
	}

	/**
	 * ✅ HELPER: Añade un elemento a una opción de WordPress que es un array
	 * 
	 * Este método generaliza el patrón común de:
	 * 1. Obtener array de opción con get_option()
	 * 2. Agregar elemento al array
	 * 3. Guardar con update_option()
	 * 
	 * Se usa tanto para historiales (con límite de registros) como para colas (sin límite).
	 * 
	 * **VERIFICACIÓN DE INFRAESTRUCTURA EXISTENTE**:
	 * - ✅ Revisado helpers de WordPress - No hay helper nativo para esto
	 * - ✅ Revisado helpers del plugin - No hay helper general para arrays de opciones
	 * - ✅ `recordHistoryEntry()` ya existe pero es específico para historiales
	 * 
	 * **JUSTIFICACIÓN**: Este método generaliza `recordHistoryEntry()` para servir también a colas,
	 * eliminando el patrón duplicado en `executeAsyncCleanup()`.
	 * 
	 * @param string $optionKey Clave de la opción de WordPress
	 * @param mixed  $item Elemento a agregar al array
	 * @param int|null $maxRecords Cantidad máxima de registros (null = sin límite, útil para colas)
	 * @param bool  $addTimestamp Si añadir timestamp automáticamente (solo si $item es array)
	 * @param bool  $addDate Si añadir date automáticamente (solo si $item es array)
	 * @return void
	 */
	private function appendToOptionArray(string $optionKey, mixed $item, ?int $maxRecords = 100, bool $addTimestamp = false, bool $addDate = false): void
	{
		// Si $item es array y se requiere, añadir timestamp y date automáticamente
		if (is_array($item)) {
			if ($addTimestamp && !isset($item['timestamp'])) {
				$item['timestamp'] = time();
			}
			if ($addDate && !isset($item['date'])) {
				// ✅ REFACTORIZADO: Usar helper centralizado para formatear fecha
				$item['date'] = $this->formatCurrentDateTime();
			}
		}

		// Obtener array existente
		$array = get_option($optionKey, []);
		
		// Validar que sea array
		if (!is_array($array)) {
			$array = [];
		}
		
		// Agregar nuevo elemento
		$array[] = $item;

		// Mantener solo los últimos N registros (solo si se especifica límite)
		if ($maxRecords !== null && count($array) > $maxRecords) {
			$array = array_slice($array, -$maxRecords);
		}

		// Guardar array actualizado
		update_option($optionKey, $array);
	}

	/**
	 * ✅ HELPER: Registra un registro de historial con patrón común
	 * 
	 * Este método centraliza la lógica de registro de historial que estaba
	 * duplicada en recordTransientCleanup(), recordBatchCleanupHistory() y recordCronSchedulingHistory().
	 * Elimina ~60 líneas de código duplicado.
	 * 
	 * **REFACTORIZADO**: Ahora usa `appendToOptionArray()` internamente para eliminar duplicación adicional.
	 * 
	 * @param string $historyKey Clave de la opción de WordPress para el historial
	 * @param array  $recordData Datos del registro a guardar (se añade automáticamente timestamp y date)
	 * @param int    $maxRecords Cantidad máxima de registros a mantener (default: 100)
	 * @return void
	 */
	private function recordHistoryEntry(string $historyKey, array $recordData, int $maxRecords = 100): void
	{
		// ✅ REFACTORIZADO: Usar helper generalizado
		$this->appendToOptionArray($historyKey, $recordData, $maxRecords, true, true);
	}

	/**
	 * Registra la limpieza de un transient individual
	 * 
	 * ✅ REFACTORIZADO: Usa helper centralizado recordHistoryEntry()
	 * 
	 * @param string $cacheKey Clave del transient
	 * @param int $sizeBytes Tamaño en bytes
	 * @param string $cleanupType Tipo de limpieza
	 */
	private function recordTransientCleanup(string $cacheKey, int $sizeBytes, string $cleanupType): void
	{
		// Convertir bytes a MB (este método recibe bytes ya calculados)
		$sizeMB = round($sizeBytes / 1024 / 1024, 2);
		
		$cleanupRecord = [
			'cache_key' => $cacheKey,
			'size_bytes' => $sizeBytes,
			'size_mb' => $sizeMB,
			'cleanup_type' => $cleanupType
		];

		$this->recordHistoryEntry('mia_transient_cleanup_history', $cleanupRecord, 100);
	}

	/**
	 * Registra el historial de limpieza por lotes
	 * 
	 * ✅ REFACTORIZADO: Usa helper centralizado recordHistoryEntry()
	 * 
	 * @param array $results Resultados de la limpieza
	 */
	private function recordBatchCleanupHistory(array $results): void
	{
		// ✅ REFACTORIZADO: Usar helper centralizado
		$historyRecord = [
			'batches_processed' => $results['batches_processed'],
			'total_transients_cleaned' => $results['total_transients_cleaned'],
			'total_space_freed_mb' => $results['total_space_freed_mb'],
			'execution_time_seconds' => $results['execution_time_seconds'],
			'errors_count' => count($results['errors']),
			'success_rate' => $results['total_transients_cleaned'] > 0 ? 
				round((1 - count($results['errors']) / $results['total_transients_cleaned']) * 100, 2) : 0
		];

		$this->recordHistoryEntry('mia_batch_cleanup_history', $historyRecord, 50);
	}

	/**
	* Ejecuta limpieza asíncrona
	 * 
	 * @param string $cleanupType Tipo de limpieza
	 * @param array $options Opciones de configuración
	 * @return array Resultado de la programación
	 */
	public function executeAsyncCleanup(string $cleanupType = 'batch', array $options = []): array
	{
		// VALIDACIÓN CRÍTICA - Protección contra valores maliciosos
		$validCleanupTypes = ['batch', 'progressive', 'selective', 'full'];
		if (!in_array($cleanupType, $validCleanupTypes)) {
			throw new InvalidArgumentException(
				'cleanupType debe ser uno de: ' . implode(', ', $validCleanupTypes) . '. Valor recibido: ' . $cleanupType
			);
		}
		
		if (!is_array($options)) {
			throw new InvalidArgumentException(
				'options debe ser un array. Tipo recibido: ' . gettype($options)
			);
		}
		
		$result = [
			'success' => false,
			'job_id' => null,
			'message' => '',
			'scheduled_time' => null
		];
		
		try {
			// ✅ REFACTORIZADO: Usar helper centralizado para generar job ID
			$jobId = $this->generateJobId('cleanup', $cleanupType);
			
			// Configurar opciones por defecto
			$defaultOptions = [
				'batch_size' => 10,
				'max_execution_time' => 30,
				'priority' => 'normal',
				'delay_seconds' => 0
			];
			// ✅ REFACTORIZADO: Usar helper centralizado para fusionar opciones
			$options = $this->mergeOptionsWithDefaults($defaultOptions, $options);
			
			// Crear trabajo de limpieza
			$cleanupJob = [
				'id' => $jobId,
				'type' => $cleanupType,
				'options' => $options,
				'status' => 'pending',
				'created_at' => time(),
				'started_at' => null,
				'completed_at' => null,
				'results' => null,
				'errors' => []
			];
			
			// ✅ REFACTORIZADO: Usar helper centralizado para agregar a cola
			$this->appendToOptionArray('mia_cleanup_queue', $cleanupJob, null, false, false);
			
			// Programar ejecución
			$delaySeconds = $options['delay_seconds'];
			$scheduledTime = time() + $delaySeconds;
			
			call_user_func('wp_schedule_single_event', $scheduledTime, 'mia_execute_async_cleanup', [$jobId]);
			
			$result['success'] = true;
			$result['job_id'] = $jobId;
			$result['message'] = "Limpieza asíncrona programada para " . date('H:i:s', $scheduledTime);
			$result['scheduled_time'] = $scheduledTime;
			
		} catch (Exception $e) {
			$result['message'] = 'Error al programar limpieza asíncrona: ' . $e->getMessage();
		}
		
		return $result;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Programa limpieza durante horas de baja actividad
	 * 
	 * @return array Resultado de la programación
	 */
	public function scheduleLowActivityCleanup(): array
	{
		$result = [
			'success' => false,
			'scheduled_time' => null,
			'job_id' => null,
			'message' => '',
			'errors' => []
		];
		
		try {
			// Detectar horas de baja actividad
			$lowActivityHours = $this->detectLowActivityHours();
			$targetHour = $lowActivityHours['best_hour'] ?? 2; // 2 AM por defecto
			
			// Calcular delay hasta la hora objetivo
			$delay = $this->calculateDelayToHour($targetHour);
			
			// Programar limpieza
			// ✅ REFACTORIZADO: Usar helper centralizado para generar job ID
			$jobId = $this->generateJobId('low_activity_cleanup');
			$scheduled = call_user_func('wp_schedule_single_event', time() + $delay, 'mia_execute_low_activity_cleanup', [$jobId]);
			
			if ($scheduled) {
				$result['success'] = true;
				$result['scheduled_time'] = date('Y-m-d H:i:s', time() + $delay);
				$result['job_id'] = $jobId;
				$result['message'] = "Limpieza programada para las $targetHour:00";
				
				// Registrar en historial de programación
				$this->recordCronSchedulingHistory($jobId, 'low_activity_cleanup', $delay, $targetHour);
			} else {
				$result['errors'][] = 'No se pudo programar la limpieza';
			}
			
		} catch (Exception $e) {
			$result['errors'][] = 'Error al programar limpieza: ' . $e->getMessage();
		}
		
		return $result;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Detecta horarios de baja actividad basado en métricas
	 * 
	 * @return array Horas de baja actividad (0-23)
	 */
	private function detectLowActivityHours(): array
	{
		// Obtener métricas de actividad del sistema
		$activityMetrics = get_option('mia_system_activity_metrics', []);
		
		// Si no hay métricas, usar horarios por defecto
		if (empty($activityMetrics)) {
			return [2, 3, 4, 5]; // 2:00 AM - 5:00 AM
		}
		
		// Analizar patrones de actividad por hora
		$hourlyActivity = array_fill(0, 24, 0);
		
		// Calcular actividad promedio por hora
		foreach ($activityMetrics as $metric) {
			$hour = (int)date('G', $metric['timestamp']);
			$hourlyActivity[$hour] += $metric['activity_score'];
		}
		
		// Identificar horas con menor actividad (por debajo del 25% del promedio)
		$totalActivity = array_sum($hourlyActivity);
		$averageActivity = $totalActivity / 24;
		$lowActivityThreshold = $averageActivity * 0.25;
		
		$lowActivityHours = [];
		foreach ($hourlyActivity as $hour => $activity) {
			if ($activity <= $lowActivityThreshold) {
				$lowActivityHours[] = $hour;
			}
		}
		
		// Si no se detectaron horas de baja actividad, usar horarios por defecto
		if (empty($lowActivityHours)) {
			$lowActivityHours = [2, 3, 4, 5];
		}
		
		return $lowActivityHours;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Calcula el delay hasta una hora específica
	 * 
	 * @param int $targetHour Hora objetivo (0-23)
	 * @return int Delay en segundos
	 */
	private function calculateDelayToHour(int $targetHour): int
	{
		$currentHour = (int)date('G');
		$currentMinute = (int)date('i');
		
		// Calcular segundos hasta la próxima hora objetivo
		$delay = 0;
		
		if ($currentHour < $targetHour) {
			// Hoy mismo
			$delay = ($targetHour - $currentHour) * 3600 - ($currentMinute * 60);
		} else {
			// Mañana
			$delay = (24 - $currentHour + $targetHour) * 3600 - ($currentMinute * 60);
		}
		
		// Asegurar que el delay sea positivo
		return max(0, $delay);
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Registra el historial de programación de cron
	 * 
	 * ✅ REFACTORIZADO: Usa helper centralizado recordHistoryEntry()
	 * 
	 * @param string $jobId ID del trabajo
	 * @param string $jobType Tipo de trabajo
	 * @param int $delay Delay en segundos
	 * @param int $targetHour Hora objetivo
	 */
	private function recordCronSchedulingHistory(string $jobId, string $jobType, int $delay, int $targetHour): void
	{
		// ✅ REFACTORIZADO: Usar helper centralizado
		// Incluir timestamp y date explícitamente para mantener compatibilidad con campos existentes
		$historyRecord = [
			'job_id' => $jobId,
			'job_type' => $jobType,
			'delay_seconds' => $delay,
			'target_hour' => $targetHour,
			'timestamp' => time(), // Mantener para compatibilidad
			'date' => $this->formatCurrentDateTime(), // ✅ REFACTORIZADO: Usar helper centralizado
			'scheduled_at' => time(),
			'scheduled_date' => $this->formatCurrentDateTime(), // ✅ REFACTORIZADO: Usar helper centralizado
			'execution_time' => $this->formatCurrentDateTime(time() + $delay) // ✅ REFACTORIZADO: Usar helper centralizado
		];

		$this->recordHistoryEntry('mia_cron_scheduling_history', $historyRecord, 100);
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Ejecuta limpieza coordinada en multisite
	 *
     * @return array Resultado de la limpieza coordinada
	 */
	public function executeCoordinatedCleanup(): array
	{
		$result = [
			'success' => false,
			'sites_processed' => 0,
			'total_cleaned' => 0,
			'errors' => []
		];
		
		try {
			if (!function_exists('is_multisite') || !is_multisite()) {
				$result['errors'][] = 'No es un sitio multisite o función no disponible';
				return $result;
			}
			
			if (!function_exists('get_sites')) {
				$result['errors'][] = 'get_sites no disponible fuera de WordPress';
				return $result;
			}
			
			$sites = call_user_func('get_sites', ['number' => 1000]);
			
			foreach ($sites as $site) {
				$siteResult = $this->executeCleanupOnSite($site->blog_id);
				
				if ($siteResult['success']) {
					$result['sites_processed']++;
					$result['total_cleaned'] += $siteResult['cleaned_count'];
				} else {
					$result['errors'][] = "Sitio $site->blog_id: " . implode(', ', $siteResult['errors']);
				}
			}
			
			$result['success'] = true;
			
		} catch (Exception $e) {
			$result['errors'][] = 'Error en limpieza coordinada: ' . $e->getMessage();
		}
		
		return $result;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Ejecuta limpieza en un sitio específico
	 * 
	 * @param int $siteId ID del sitio
	 * @return array Resultado de la limpieza
	 */
	private function executeCleanupOnSite(int $siteId): array
	{
		$result = [
			'success' => false,
			'cleaned_count' => 0,
			'errors' => []
		];
		
		try {
			// Cambiar al sitio específico
			switch_to_blog($siteId);
			
			// Ejecutar limpieza
			$cleanupResult = $this->executeConditionalCleanup();
			
			if (isset($cleanupResult['success']) && $cleanupResult['success']) {
				$result['success'] = true;
				$result['cleaned_count'] = $cleanupResult['cleaned_count'] ?? 0;
			} else {
				$result['errors'] = $cleanupResult['errors'] ?? [];
			}
			
			// Restaurar al sitio original
			restore_current_blog();
			
		} catch (Exception $e) {
			$result['errors'][] = 'Error al limpiar sitio: ' . $e->getMessage();
		}
		
		return $result;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Ejecuta limpieza condicional basada en métricas
	 * 
	 * @return array Resultado de la limpieza condicional
	 */
	public function executeConditionalCleanup(): array
	{
		$result = [
			'cleanup_needed' => false,
			'cleanup_executed' => false,
			'reason' => '',
			'results' => null,
			'metrics' => []
		];
		
		try {
			// Evaluar condiciones para determinar si la limpieza es necesaria
			$cleanupConditions = $this->evaluateCleanupConditions();
			$result['metrics'] = $cleanupConditions;
			
			// Determinar si se necesita limpieza
			$result['cleanup_needed'] = $this->shouldExecuteCleanup($cleanupConditions);
			
			if ($result['cleanup_needed']) {
				$result['reason'] = $this->getCleanupReason($cleanupConditions);
				
				// Ejecutar limpieza apropiada
				$cleanupType = $this->determineCleanupType($cleanupConditions);
				$result['results'] = $this->executeAppropriateCleanup($cleanupType);
				$result['cleanup_executed'] = true;
			} else {
				$result['reason'] = 'No se requiere limpieza en este momento';
			}
			
		} catch (Exception $e) {
			$result['reason'] = 'Error en evaluación condicional: ' . $e->getMessage();
		}
		
		return $result;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Evalúa condiciones para determinar si se necesita limpieza
	 * 
	 * @return array Métricas de evaluación
	 */
	private function evaluateCleanupConditions(): array
	{
		// Obtener métricas de memoria
		$memoryUsage = $this->getMemoryUsage();
		
		// Obtener métricas de transients
		$cacheKeys = $this->getMonitoredCacheKeys();
		
		$totalSizeBytes = 0;
		$oldTransientsCount = 0;
		$currentTime = time();
		
		foreach ($cacheKeys as $cacheKey) {
			// ✅ REFACTORIZADO: Usar helper centralizado para verificar existencia
			$cacheData = $this->getTransientIfExists($cacheKey);
			if ($cacheData !== null) {
				// ✅ REFACTORIZADO: Usar helper centralizado para calcular tamaño
				$sizeInfo = $this->calculateDataSize($cacheData);
				$totalSizeBytes += $sizeInfo['bytes'];
				
				// Contar transients antiguos (>24 horas)
				$transientAge = $this->getTransientAge($cacheKey);
				if ($transientAge > 86400) {
					$oldTransientsCount++;
				}
			}
		}
		
		// Obtener métricas de limpieza
		$cleanupHistory = get_option('mia_transient_cleanup_history', []);
		$cleanupFrequency = count($cleanupHistory);
		
		$lastCleanup = end($cleanupHistory);
		$lastCleanupAgeHours = $lastCleanup ? round(($currentTime - $lastCleanup['timestamp']) / 3600, 1) : 0;
		
		// Construir array de métricas directamente
		$metrics = [
			'memory_usage_percent' => $memoryUsage['usage_percent'],
			'transient_count' => count($cacheKeys),
			'total_size_mb' => round($totalSizeBytes / (1024 * 1024), 2),
			'old_transients_count' => $oldTransientsCount,
			'cleanup_frequency' => $cleanupFrequency,
			'last_cleanup_age_hours' => $lastCleanupAgeHours,
			'performance_score' => 0 // Se calculará después
		];
		
		// Calcular score de rendimiento
		$metrics['performance_score'] = $this->calculateCleanupPerformanceScore($metrics);
		
		return $metrics;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene el uso de memoria del sistema
	 * 
	 * @return array Métricas de memoria
	 */
	private function getMemoryUsage(): array
	{
		$memoryLimit = ini_get('memory_limit');
		$memoryLimitBytes = $this->convertMemoryToBytes($memoryLimit);
		$currentMemoryUsage = memory_get_usage(true);
		$peakMemoryUsage = memory_get_peak_usage(true);
		
		return [
			'limit_bytes' => $memoryLimitBytes,
			'current_bytes' => $currentMemoryUsage,
			'peak_bytes' => $peakMemoryUsage,
			'usage_percent' => round(($currentMemoryUsage / $memoryLimitBytes) * 100, 2),
			'peak_percent' => round(($peakMemoryUsage / $memoryLimitBytes) * 100, 2)
		];
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Convierte límite de memoria a bytes
	 * 
	 * @param string $memoryLimit Límite de memoria (ej: '256M', '1G')
	 * @return int Límite en bytes
	 */
	private function convertMemoryToBytes(string $memoryLimit): int
	{
		$unit = strtolower(substr($memoryLimit, -1));
		$value = (int)substr($memoryLimit, 0, -1);

        return match ($unit) {
            'k' => $value * 1024,
            'm' => $value * 1024 * 1024,
            'g' => $value * 1024 * 1024 * 1024,
            default => $value,
        };
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene la edad de un transient
	 * 
	 * @param string $cacheKey Clave del transient
	 * @return int Edad en segundos
	 */
	private function getTransientAge(string $cacheKey): int
	{
		// ✅ REFACTORIZADO: Usar helper centralizado para verificar existencia
		$transientData = $this->getTransientIfExists($cacheKey);
		if ($transientData === null) {
			return 0;
		}
		
		// Intentar extraer timestamp del transient
		if (isset($transientData['created_at'])) {
			return time() - strtotime($transientData['created_at']);
		}
		
		// Si no hay timestamp, usar tiempo de expiración por defecto
		return 3600; // 1 hora por defecto
	}

	/**
	 * ✅ HELPER: Obtiene un transient si existe, retornando null si no existe
	 * 
	 * Este método centraliza la verificación de existencia de transients que estaba
	 * duplicada en múltiples métodos. Elimina el patrón repetitivo de `get_transient()`
	 * seguido de `if ($data !== false)` o `if ($data === false)`.
	 * 
	 * **VERIFICACIÓN DE INFRAESTRUCTURA EXISTENTE**:
	 * - ✅ Revisado `mia_get_sync_transient()` - Específico para transients críticos de sincronización
	 * - ✅ Revisado helpers de WordPress - No hay helper nativo para verificar existencia con defaults
	 * - ✅ Revisado otros helpers - No hay método general para esto
	 * 
	 * **JUSTIFICACIÓN**: Este método simplifica el código eliminando el patrón repetitivo de
	 * verificación de `false` después de `get_transient()`, haciendo el código más legible.
	 * 
	 * @param string $cacheKey Clave del transient
	 * @return mixed|null Datos del transient o null si no existe
	 */
	private function getTransientIfExists(string $cacheKey): mixed
	{
		$data = get_transient($cacheKey);
		return ($data !== false) ? $data : null;
	}

	/**
	 * ✅ HELPER: Calcula el tamaño de datos serializados y convierte a bytes y MB
	 * 
	 * Este método centraliza el cálculo de tamaños de datos que estaba duplicado
	 * en múltiples métodos. Elimina el patrón repetitivo de `strlen(serialize($data))`
	 * seguido de conversión a MB con `round($size / 1024 / 1024, 2)`.
	 * 
	 * **VERIFICACIÓN DE INFRAESTRUCTURA EXISTENTE**:
	 * - ✅ Revisado `Utils` - No hay helper específico para calcular tamaños de datos serializados
	 * - ✅ Revisado otros helpers - No hay método similar
	 * - ✅ Revisado helpers de WordPress - No hay función nativa para esto
	 * 
	 * **JUSTIFICACIÓN**: Este método centraliza el patrón común de calcular tamaños de datos
	 * serializados (útil para medir el tamaño de transients, opciones, etc.) y su conversión
	 * a MB, facilitando el mantenimiento y asegurando consistencia en el redondeo.
	 * 
	 * @param mixed $data Datos a calcular el tamaño
	 * @param int $precision Precisión decimal para conversión a MB (default: 2)
	 * @return array Array con 'bytes' (int) y 'mb' (float)
	 */
	private function calculateDataSize(mixed $data, int $precision = 2): array
	{
		$bytes = strlen(serialize($data));
		$mb = round($bytes / 1024 / 1024, $precision);
		
		return [
			'bytes' => $bytes,
			'mb' => $mb
		];
	}

	/**
	 * ✅ HELPER: Formatea la fecha y hora actual en formato estándar MySQL/WordPress
	 * 
	 * Este método centraliza el formato de fechas que estaba duplicado
	 * usando `date('Y-m-d H:i:s')` en múltiples lugares. Usa `current_time('mysql')`
	 * cuando está disponible (recomendado por WordPress) o `date('Y-m-d H:i:s')` como fallback.
	 * 
	 * **VERIFICACIÓN DE INFRAESTRUCTURA EXISTENTE**:
	 * - ✅ Revisado helpers de WordPress - `current_time('mysql')` es la función recomendada
	 * - ✅ Revisado `Utils` - No hay helper específico para formatear fechas con formato estándar
	 * - ✅ Revisado otros helpers - No hay método similar
	 * 
	 * **JUSTIFICACIÓN**: Este método centraliza el patrón común de formatear fechas en formato
	 * MySQL/WordPress estándar ('Y-m-d H:i:s'), asegurando consistencia y facilitando futuras
	 * mejoras (ej: respetar zona horaria de WordPress con `current_time('mysql')` o `wp_date()`).
	 * 
	 * @param int|null $timestamp Timestamp opcional (null = tiempo actual)
	 * @return string Fecha formateada en formato 'Y-m-d H:i:s'
	 */
	private function formatCurrentDateTime(?int $timestamp = null): string
	{
		// Usar current_time('mysql') cuando esté disponible (recomendado por WordPress)
		if (function_exists('current_time')) {
			// Si se proporciona un timestamp, calcular la diferencia con el tiempo actual
			if ($timestamp !== null) {
				$current_timestamp = current_time('timestamp');
				$offset = $timestamp - time();
				$adjusted_timestamp = $current_timestamp + $offset;
				return date('Y-m-d H:i:s', $adjusted_timestamp);
			}
			return current_time('mysql');
		}
		
		// Fallback a date() si current_time no está disponible
		$ts = $timestamp ?? time();
		return date('Y-m-d H:i:s', $ts);
	}

	/**
	 * ✅ HELPER: Verifica si un valor de array está vacío usando null coalescing
	 * 
	 * Este método centraliza el patrón común `empty($array['key'] ?? [])` que estaba
	 * duplicado en múltiples métodos. Simplifica la verificación de si un valor de array
	 * está vacío, usando un valor por defecto cuando la clave no existe.
	 * 
	 * **VERIFICACIÓN DE INFRAESTRUCTURA EXISTENTE**:
	 * - ✅ Revisado `Utils` - No hay helper específico para esta combinación de `empty()` y `??`
	 * - ✅ Revisado otros helpers - No hay método similar
	 * - ✅ Revisado `get_batch_value()` - Existe pero retorna el valor, no verifica si está vacío
	 * 
	 * **JUSTIFICACIÓN**: Este método centraliza el patrón común de verificar si un valor
	 * de array está vacío usando null coalescing (`??`), evitando repetición del patrón
	 * `empty($array['key'] ?? [])` en múltiples lugares.
	 * 
	 * @param array $array Array a verificar
	 * @param string $key Clave del array a verificar
	 * @param mixed $default Valor por defecto si la clave no existe (default: [])
	 * @return bool true si el valor está vacío o no existe, false si tiene contenido
	 */
	private function isEmptyArrayValue(array $array, string $key, mixed $default = []): bool
	{
		return empty($array[$key] ?? $default);
	}

	/**
	 * ✅ HELPER: Valida que un valor sea un array y retorna error o lanza excepción si falla
	 * 
	 * Este método centraliza la validación de arrays que estaba duplicada en múltiples lugares,
	 * seguida de logging de error y retorno de respuesta de error o excepción.
	 * 
	 * **VERIFICACIÓN DE INFRAESTRUCTURA EXISTENTE**:
	 * - ✅ Revisado `DataSanitizer` - No hay método público para validar arrays con respuesta de error
	 * - ✅ Revisado `Utils` - No hay helper específico para esta validación con respuesta
	 * - ✅ Revisado otros helpers - No hay método similar
	 * 
	 * **JUSTIFICACIÓN**: Este método centraliza el patrón común de validar que un valor sea array,
	 * seguido de logging contextual y retorno de respuesta de error o lanzamiento de excepción.
	 * Elimina duplicación en validaciones de `response_data`, `productos`, `json_data`, etc.
	 * 
	 * @param mixed $data Datos a validar
	 * @param string $dataName Nombre descriptivo de los datos para mensajes de error (ej: 'productos', 'response_data')
	 * @param string $context Contexto de la validación para logging (ej: 'batch_processing', 'api_response')
	 * @param array $additionalContext Contexto adicional para logging
	 * @param bool $throwException Si true, lanza excepción en lugar de retornar error
	 * @return \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface|null Retorna respuesta de error si no es array y $throwException=false, null si es válido
	 * @throws \Exception Si $throwException=true y los datos no son array
	 */
	private function validateArrayOrError(
		mixed $data, 
		string $dataName, 
		string $context = 'validation',
		array $additionalContext = [],
		bool $throwException = false
	): ?\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if (is_array($data)) {
			return null; // Datos válidos
		}
		
		$errorMessage = "Error: {$dataName} no es un array válido";
		$logContext = array_merge([
			'context' => $context,
			'data_name' => $dataName,
			'data_type' => gettype($data),
			'data_preview' => is_string($data) ? substr($data, 0, 500) : (is_object($data) ? get_class($data) : $data),
		], $additionalContext);
		
		// Logging del error
		$this->getLogger()->error($errorMessage, $logContext);
		
		if ($throwException) {
			throw new \Exception($errorMessage . ' (tipo: ' . gettype($data) . ')');
		}
		
		// Retornar respuesta de error usando ResponseFactory
		return ResponseFactory::error(
			$errorMessage,
			HttpStatusCodes::BAD_REQUEST,
			array_merge([
				'errors' => 1,
				'log' => [$errorMessage, 'tipo' => gettype($data)],
			], $additionalContext)
		);
	}

	/**
	 * ✅ HELPER: Registra un cache hit de batch con formato estandarizado
	 * Este método centraliza el logging de cache hits de batch que estaba duplicado
	 * en prepare_complete_batch_data(). Elimina duplicación de mensajes de log idénticos.
	 * **VERIFICACIÓN DE CÓDIGO EXISTENTE**:
	 * - ✅ Revisado helpers de logging - No hay helper específico para cache hits de batch
	 * - ✅ Revisado otros helpers - No hay método similar para este caso específico
	 * **JUSTIFICACIÓN**: Este método centraliza el logging de cache hits de batch, eliminando
	 * duplicación de mensajes idénticos y asegurando consistencia en el formato de logs.
	 * @param string $batch_id ID del batch
	 * @param int $inicio Índice de inicio del lote
	 * @param int $fin Índice de fin del lote
	 * @param array $rango Array con 'inicio' y 'fin'
	 * @param array $additionalContext Contexto adicional opcional para el log
	 * @return void
	 */
	private function logBatchCacheHit(string $batch_id, int $inicio, int $fin, array $rango, array $additionalContext = []): void
	{
		$this->getLogger()->info(
			sprintf('✅ Batch recuperado desde caché determinístico (inicio=%d, fin=%d)', $inicio, $fin),
			array_merge([
				'batch_id' => $batch_id,
				'cache_hit' => true,
				'rango' => $rango,
				'cache_type' => 'deterministic',
			], $additionalContext)
		);
	}

	/**
	 * ✅ HELPER: Fusiona opciones proporcionadas con valores por defecto
	 * Este método centraliza el patrón de fusión de opciones por defecto que estaba duplicado
	 * en getDegradationConfiguration() y executeAsyncCleanup(). Elimina duplicación de código.
	 * **VERIFICACIÓN DE CÓDIGO EXISTENTE**:
	 * - ✅ Revisado `Utils` - No hay helper específico para fusionar opciones con defaults
	 * - ✅ Revisado otros helpers - No hay método similar
	 * - ✅ Justificación: PHP tiene `array_merge()` nativo, pero este helper centraliza el patrón
	 *   común de fusionar defaults con opciones personalizadas para mantener consistencia y facilitar
	 *   futuras extensiones (ej: validación de tipos, merge recursivo profundo, etc.)
	 * 
	 * @param array $defaults Valores por defecto
	 * @param array $options  Opciones proporcionadas (sobrescriben defaults)
	 * @return array Array fusionado con defaults como base y options sobrescribiendo
	 */
	private function mergeOptionsWithDefaults(array $defaults, array $options): array
	{
		return array_merge($defaults, $options);
	}

	/**
	 * ✅ HELPER: Genera un ID único para trabajos (jobs) usando IdGenerator cuando es posible
	 * 
	 * Este método centraliza la generación de IDs de trabajos que estaba duplicada
	 * en múltiples métodos de programación de cron. Elimina duplicación en generación de IDs.
	 * 
	 * **VERIFICACIÓN DE INFRAESTRUCTURA EXISTENTE**:
	 * - ✅ Revisado `IdGenerator::generateOperationId()` - Puede usarse para jobs con contexto
	 * - ✅ Revisado otros helpers - No hay helper específico para job IDs
	 * 
	 * **JUSTIFICACIÓN**: Este método usa `IdGenerator::generateOperationId()` cuando es posible,
	 * con fallback a generación manual para mantener compatibilidad con formatos existentes.
	 * 
	 * @param string $jobType Tipo de trabajo (ej: 'cleanup', 'low_activity_cleanup')
	 * @param string|null $subtype Subtipo opcional (ej: 'batch', 'progressive' para cleanup)
	 * @return string ID único del trabajo
	 */
	private function generateJobId(string $jobType, ?string $subtype = null): string
	{
		// ✅ Usar IdGenerator si está disponible (ya importado con 'use')
		if (class_exists(IdGenerator::class)) {
			// Construir prefijo basado en tipo y subtipo
			$prefix = 'mia';
			if (!empty($jobType)) {
				$prefix .= '_' . str_replace('_', '', $jobType);
			}
			if (!empty($subtype)) {
				$prefix .= '_' . str_replace('_', '', $subtype);
			}
			
			// Usar generateOperationId con contexto del job
			$context = !empty($subtype) ? ['job_type' => $jobType, 'subtype' => $subtype] : ['job_type' => $jobType];
			$operationId = IdGenerator::generateOperationId($jobType, $context);
			
			// Ajustar formato para mantener compatibilidad: reemplazar 'op_' con el prefijo construido
			// Ejemplo: 'op_cleanup_1234567890_a1b2c3d4' -> 'mia_cleanup_1234567890_a1b2c3d4'
			if (str_starts_with($operationId, 'op_')) {
				return $prefix . substr($operationId, 2); // Quitar 'op' y añadir prefijo personalizado
			}
			
			// Si no tiene el prefijo 'op_', usar tal cual (caso edge)
			return $prefix . '_' . $operationId;
		}
		
		// Fallback: Generar ID manualmente (compatible con código existente)
		$prefix = 'mia';
		if (!empty($jobType)) {
			$prefix .= '_' . str_replace('_', '', $jobType);
		}
		if (!empty($subtype)) {
			$prefix .= '_' . str_replace('_', '', $subtype);
		}
		
		$password_suffix = function_exists('wp_generate_password') 
			? wp_generate_password(8, false) 
			: substr(md5(uniqid()), 0, 8);
		
		return $prefix . '_' . time() . '_' . $password_suffix;
	}

	/**
	 * ✅ HELPER: Loggea una excepción con formato estandarizado
	 * 
	 * Este método centraliza el logging de excepciones que estaba duplicado
	 * en múltiples bloques catch. Elimina ~80 líneas de código duplicado.
	 * 
	 * NOTA: Se verificó que no existe un helper general reutilizable en el plugin:
	 * - `logMainPluginError()` en MainPluginAccessor es privado y específico de ese trait
	 * - No hay métodos en Logger/Utils para logging estructurado de excepciones
	 * - Este método es privado de BatchProcessor y usa la infraestructura existente (LoggerBasic)
	 * 
	 * @param \Throwable|\Exception $exception Excepción a loggear
	 * @param string $message Mensaje principal del log
	 * @param array $additionalContext Contexto adicional a incluir en el log
	 * @param string $logLevel Nivel de log ('error', 'warning', 'debug')
	 * @param bool $includeTrace Si incluir el trace completo (útil para debugging)
	 * @return void
	 */
	private function logException(
		\Throwable|\Exception $exception,
		string $message,
		array $additionalContext = [],
		string $logLevel = 'error',
		bool $includeTrace = false
	): void {
		// Construir contexto base de la excepción
		$context = [
			'error' => $exception->getMessage(),
			'file' => $exception->getFile(),
			'line' => $exception->getLine(),
		];

		// Incluir código de error si existe
		if (method_exists($exception, 'getCode') && $exception->getCode() !== 0) {
			$context['error_code'] = $exception->getCode();
		}

		// Incluir trace completo si se solicita
		if ($includeTrace && method_exists($exception, 'getTraceAsString')) {
			$context['exception_trace'] = $exception->getTraceAsString();
		}

		// Combinar con contexto adicional
		$context = array_merge($context, $additionalContext);

		// Loggear según el nivel
		match ($logLevel) {
			'warning' => $this->getLogger()->warning($message, $context),
			'debug' => $this->getLogger()->debug($message, $context),
			default => $this->getLogger()->error($message, $context),
		};
	}

	/**
	 * Evalúa las reglas de condiciones de limpieza y retorna resultado estructurado
	 * Este método centraliza la lógica de evaluación de condiciones que estaba
	 * duplicada en shouldExecuteCleanup(), getCleanupReason() y determineCleanupType().
	 * Elimina ~55 líneas de código duplicado.
	 * @param array $conditions Condiciones evaluadas
	 * @return array Resultado estructurado con:
	 *   - 'should_execute': bool - Si se debe ejecutar limpieza
	 *   - 'reason': string - Razón de la limpieza
	 *   - 'type': string - Tipo de limpieza recomendado
	 *   - 'triggered_condition': string|null - Nombre de la condición que se activó
	 */
	private function evaluateCleanupConditionsRules(array $conditions): array
	{
		// Evaluar condiciones en orden de prioridad
		// 1. Uso de memoria crítico
		if ($conditions['memory_usage_percent'] > 80) {
			return [
				'should_execute' => true,
				'reason' => 'Uso de memoria crítico (' . $conditions['memory_usage_percent'] . '%)',
				'type' => 'emergency',
				'triggered_condition' => 'memory_critical'
			];
		}
		
		// 2. Tamaño total excesivo
		if ($conditions['total_size_mb'] > 100) {
			return [
				'should_execute' => true,
				'reason' => 'Tamaño total de transients excesivo (' . $conditions['total_size_mb'] . 'MB)',
				'type' => 'batch',
				'triggered_condition' => 'size_excessive'
			];
		}
		
		// 3. Alto porcentaje de transients antiguos (>30%)
		if ($conditions['old_transients_count'] > ($conditions['transient_count'] * 0.3)) {
			return [
				'should_execute' => true,
				'reason' => 'Alto porcentaje de transients antiguos (' . $conditions['old_transients_count'] . '/' . $conditions['transient_count'] . ')',
				'type' => 'time_based',
				'triggered_condition' => 'old_transients_high'
			];
		}
		
		// 4. Última limpieza hace más de 24 horas
		if ($conditions['last_cleanup_age_hours'] > 24) {
			return [
				'should_execute' => true,
				'reason' => 'Limpieza programada (última hace ' . $conditions['last_cleanup_age_hours'] . ' horas)',
				'type' => 'maintenance',
				'triggered_condition' => 'scheduled_cleanup'
			];
		}
		
		// 5. Score de rendimiento bajo
		if ($conditions['performance_score'] < 60) {
			return [
				'should_execute' => true,
				'reason' => 'Score de rendimiento bajo (' . $conditions['performance_score'] . ')',
				'type' => 'maintenance',
				'triggered_condition' => 'performance_low'
			];
		}
		
		// No se necesita limpieza
		return [
			'should_execute' => false,
			'reason' => 'No se requiere limpieza en este momento',
			'type' => 'maintenance',
			'triggered_condition' => null
		];
	}

	/**
	 * Determina si se debe ejecutar limpieza basado en condiciones
	 * @param array $conditions Condiciones evaluadas
	 * @return bool True si se debe ejecutar limpieza
	 */
	private function shouldExecuteCleanup(array $conditions): bool
	{
		// Usar helper centralizado
		$evaluation = $this->evaluateCleanupConditionsRules($conditions);
		return $evaluation['should_execute'];
	}

	/**
	 * Obtiene la razón para ejecutar limpieza
	 * @param array $conditions Condiciones evaluadas
	 * @return string Razón de la limpieza
	 */
	private function getCleanupReason(array $conditions): string
	{
		// Usar helper centralizado
		$evaluation = $this->evaluateCleanupConditionsRules($conditions);
		return $evaluation['reason'];
	}

    /**
     * Determina el tipo de limpieza apropiado
     *
     * @param array $conditions Condiciones evaluadas
     * @return string Tipo de limpieza
     */
	private function determineCleanupType(array $conditions): string
    {
		// Usar helper centralizado
		$evaluation = $this->evaluateCleanupConditionsRules($conditions);
		return $evaluation['type'];
	}

	/**
	 * Ejecuta el tipo de limpieza apropiado
	 * 
	 * @param string $cleanupType Tipo de limpieza
	 * @return array Resultados de la limpieza
	 */
	private function executeAppropriateCleanup(string $cleanupType): array
	{
        return match ($cleanupType) {
            'emergency' => $this->executeEmergencyCleanup(),
            'batch' => $this->executeBatchCleanup(15, 45),
            'time_based' => $this->cleanOldCache(true),
            default => $this->executeMaintenanceCleanup(),
        };
	}

	/**
	 * Ejecuta limpieza de emergencia
	 * 
	 * Usa helper centralizado executeTransientCleanup()
	 * 
	 * @return array Resultados de la limpieza de emergencia
	 */
	private function executeEmergencyCleanup(): array
	{
		// Usar helper centralizado sin filtro de edad (limpia todo)
		return $this->executeTransientCleanup([
			'age_threshold' => null, // Sin filtro de edad - limpiar todo
			'error_context' => 'emergencia'
		]);
	}

	/**
	 * Ejecuta limpieza de mantenimiento
	 * 
	 * Usa helper centralizado executeTransientCleanup()
	 * 
	 * @return array Resultados de la limpieza de mantenimiento
	 */
	private function executeMaintenanceCleanup(): array
	{
		// Usar helper centralizado con filtro de edad > 24 horas
		return $this->executeTransientCleanup([
			'age_threshold' => 86400, // 24 horas
			'error_context' => 'mantenimiento'
		]);
	}

	/**
	 * Limpia caché antiguo
	 * 
	 * Usa helper centralizado executeTransientCleanup()
	 * 
	 * @param bool $aggressive Si es true, limpia más agresivamente (1 hora vs 24 horas)
	 * @return array Resultados de la limpieza
	 */
	private function cleanOldCache(bool $aggressive = false): array
	{
		// Usar helper centralizado con umbral de edad configurable
		$ageThreshold = $aggressive ? 3600 : 86400; // 1 hora vs 24 horas
		
		return $this->executeTransientCleanup([
			'age_threshold' => $ageThreshold,
			'error_context' => 'caché antiguo'
		]);
	}

	/**
	 * Calcula el score de rendimiento de limpieza
	 * 
	 * @param array $metrics Métricas del sistema
	 * @return float Score de rendimiento (0-100)
	 */
	private function calculateCleanupPerformanceScore(array $metrics): float
	{
		$score = 0;
		
		// Score basado en uso de memoria (40%)
		$memoryScore = max(0, 100 - $metrics['memory_usage_percent']);
		$score += $memoryScore * 0.4;
		
		// Score basado en tamaño de transients (30%)
		$sizeScore = max(0, 100 - min(100, $metrics['total_size_mb']));
		$score += $sizeScore * 0.3;
		
		// Score basado en frecuencia de limpieza (20%)
		$frequencyScore = min(100, $metrics['cleanup_frequency'] * 10);
		$score += $frequencyScore * 0.2;
		
		// Score basado en edad de última limpieza (10%)
		$ageScore = max(0, 100 - min(100, $metrics['last_cleanup_age_hours']));
		$score += $ageScore * 0.1;
		
		return round($score, 2);
	}

	// ============================================================================
	// MÉTODOS ESTÁTICOS PARA CRON JOBS
	// ============================================================================

	/**
	 * Ejecuta sincronización por lotes para cron jobs
	 * Método estático para ser llamado desde cron jobs
	 * 
	 * @return array Resultado de la sincronización
	 */
	public static function executeBatchSync(): array
	{
		try {
			$logger = LoggerBasic::getInstance('batch-sync');
			$logger->info("Iniciando sincronización por lotes", [
				'context' => 'batch-sync-cron'
			]);

			// Crear instancia del sync manager
			$syncManager = Sync_Manager::get_instance();

			// Usar SyncStatusHelper para verificar sincronización en progreso
			$syncStatus = SyncStatusHelper::getCurrentSyncInfo();
			if ($syncStatus && isset($syncStatus['in_progress']) && $syncStatus['in_progress']) {
				$logger->warning("Sincronización por lotes omitida - hay sincronización en progreso", [
					'context' => 'batch-sync-cron'
				]);

				return [
					'success' => false,
					'message' => 'Sincronización en progreso, omitiendo batch sync',
					'execution_time' => $this->formatCurrentDateTime() // ✅ REFACTORIZADO: Usar helper centralizado
				];
			}

			// Ejecutar sincronización de productos pendientes
			$productSync = $syncManager->start_sync('products', 'woocommerce_to_verial', ['batch_mode' => true]);
			
			// Ejecutar sincronización de clientes pendientes
			$customerSync = $syncManager->start_sync('customers', 'woocommerce_to_verial', ['batch_mode' => true]);

			$logger->info("Sincronización por lotes completada", [
				'product_sync_success' => $productSync['success'],
				'customer_sync_success' => $customerSync['success'],
				'context' => 'batch-sync-cron'
			]);

			return [
				'success' => true,
				'product_sync' => $productSync,
				'customer_sync' => $customerSync,
				'execution_time' => date('Y-m-d H:i:s')
			];

		} catch (Exception $e) {
			$logger = LoggerBasic::getInstance('batch-sync');
			$logger->error("Error en sincronización por lotes", [
				'error' => $e->getMessage(),
				'context' => 'batch-sync-cron'
			]);

			return [
				'success' => false,
				'error' => $e->getMessage(),
				'execution_time' => date('Y-m-d H:i:s')
			];
		}
	}

	/**
	 * generate_unique_sku_from_batch
	 * 
	 * Este método generaba SKUs únicos incorrectamente.
	 * La lógica correcta es actualizar productos existentes o saltarlos.
	 */
	public function generate_unique_sku_from_batch_DEPRECATED(string $base_sku, array $batch_data): string
	{
		// Obtener SKUs existentes desde batch si están disponibles
		$existing_skus = $this->get_batch_value($batch_data, 'existing_skus_indexed', [], 'sku_generation');
		
		// Si no hay SKUs en batch, usar el método original como fallback
		if (empty($existing_skus)) {
            $this->logger->info("ℹ️ No hay SKUs en batch, usando método original", [
                'base_sku' => $base_sku,
                'fallback_method' => 'wc_get_product_id_by_sku'
            ]);
			return $this->generate_unique_sku_suffix($base_sku);
		}

		// REFACTORIZADO: Generar SKU único usando datos del batch
		$counter = 1;
		$new_sku = $base_sku;
		
		while (isset($existing_skus[$new_sku])) {
			$new_sku = $base_sku . '-DUP-' . $counter;
			$counter++;
			
			// Prevenir bucles infinitos
			if ($counter > 999) {
				// Usar IdGenerator para SKU único como último recurso
				$unique_suffix = IdGenerator::generateHash(
					['base_sku' => $base_sku, 'timestamp' => microtime(true)],
					'md5',
					8
				);
				$new_sku = $base_sku . '-' . $unique_suffix;
				break;
			}
		}

        // ✅ OPTIMIZADO: Log eliminado - se genera demasiado volumen por cada SKU duplicado
        // Solo se registran errores críticos de SKU

		return $new_sku;
	}

	/**
	 * Genera sufijo único para SKU duplicado
	 * 
	 * Este método genera un sufijo único para SKUs duplicados usando
	 * timestamp y random para garantizar unicidad.
	 * 
	 * @param string $base_sku SKU base
	 * @return string SKU con sufijo único
	 */
	public function generate_unique_sku_suffix(string $base_sku): string
	{
		// Generar sufijo único usando timestamp y random
		$timestamp = time();
		$random = mt_rand(1000, 9999);
		$unique_suffix = $timestamp . '-' . $random;
		
		$new_sku = $base_sku . '-' . $unique_suffix;

        // ✅ OPTIMIZADO: Log eliminado - se genera demasiado volumen por cada SKU duplicado
        // Solo se registran errores críticos de SKU
		
		return $new_sku;
	}

	/**
	 * validateAndFixSkusInBatch
	 * 
	 * Este método generaba SKUs únicos incorrectamente.
	 * La lógica correcta es actualizar productos existentes o saltarlos.
	 */
	public function validateAndFixSkusInBatch_DEPRECATED(array $batch_data): array
	{
        $this->logger->info("🔍 Iniciando validación y corrección de SKUs en batch", [
            'batch_id' => $batch_data['batch_id'] ?? 'unknown',
            'total_products' => count($batch_data['productos'] ?? [])
        ]);

		// Obtener SKUs existentes desde batch
		$existing_skus = $this->get_batch_value($batch_data, 'existing_skus_indexed', [], 'sku_validation');
		
		// Obtener productos del batch
		$productos = $batch_data['productos'] ?? [];
		$validated_products = [];
		$fixed_skus = [];
		$errors = [];

		foreach ($productos as $index => $producto) {
			$original_sku = $producto['sku'] ?? $producto['ReferenciaBarras'] ?? $producto['CodigoArticulo'] ?? '';
			
			if (empty($original_sku)) {
				$errors[] = "Producto en índice $index no tiene SKU válido";
				continue;
			}

			// Verificar si el SKU ya existe
			$existing_product = $this->productAlreadyExistsFromBatch($original_sku, $batch_data);
			
			if ($existing_product) {
				// Generar SKU único para este producto
				$unique_sku = $this->generate_unique_sku_from_batch($original_sku, $batch_data);
				$producto['sku'] = $unique_sku;
				$fixed_skus[] = [
					'original' => $original_sku,
					'new' => $unique_sku,
					'product_index' => $index
				];

                $this->logger->info("✅ SKU duplicado corregido", [
                    'original_sku' => $original_sku,
                    'new_sku' => $unique_sku,
                    'product_index' => $index
                ]);
			} else {
				// SKU es único, mantener original
				$producto['sku'] = $original_sku;
			}

			$validated_products[] = $producto;
		}

		// Actualizar batch data con productos validados
		$batch_data['productos'] = $validated_products;
		$batch_data['sku_validation'] = [
			'status' => 'completed',
			'total_products' => count($validated_products),
			'fixed_skus' => $fixed_skus,
			'errors' => $errors,
			'validation_time' => microtime(true)
		];

        $this->logger->info("✅ Validación de SKUs en batch completada", [
            'batch_id' => $batch_data['batch_id'] ?? 'unknown',
            'total_products' => count($validated_products),
            'fixed_skus_count' => count($fixed_skus),
            'errors_count' => count($errors)
        ]);

		return $batch_data;
	}

	/**
	 * generateUniqueSkuForBatch
	 * 
	 * Este método generaba SKUs únicos incorrectamente.
	 * La lógica correcta es actualizar productos existentes o saltarlos.
	 */
	public function generateUniqueSkuForBatch_DEPRECATED(array $batch_data): array
	{
        $this->logger->info("🏷️ Iniciando generación masiva de SKUs únicos para batch", [
            'batch_id' => $batch_data['batch_id'] ?? 'unknown',
            'total_products' => count($batch_data['productos'] ?? [])
        ]);

		// Obtener SKUs existentes desde batch
		$existing_skus = $this->get_batch_value($batch_data, 'existing_skus_indexed', [], 'mass_sku_generation');
		
		// Obtener productos del batch
		$productos = $batch_data['productos'] ?? [];
		$sku_mapping = [];
		$generated_count = 0;

		foreach ($productos as $producto) {
			$original_sku = $producto['sku'] ?? $producto['ReferenciaBarras'] ?? $producto['CodigoArticulo'] ?? '';
			
			if (empty($original_sku)) {
				continue;
			}

			// Verificar si el SKU ya existe
			if (isset($existing_skus[$original_sku])) {
				// REFACTORIZADO: Generar SKU único
				$unique_sku = $this->generate_unique_sku_from_batch($original_sku, $batch_data);
				$sku_mapping[$original_sku] = $unique_sku;
				$generated_count++;				
			} else {
				// SKU es único, mantener original
				$sku_mapping[$original_sku] = $original_sku;
			}
		}

        $this->logger->info("✅ Generación masiva de SKUs únicos completada", [
            'batch_id' => $batch_data['batch_id'] ?? 'unknown',
            'total_skus' => count($sku_mapping),
            'generated_unique_skus' => $generated_count,
            'unchanged_skus' => count($sku_mapping) - $generated_count
        ]);

		return $sku_mapping;
	}

	/**
	 * Obtiene un valor específico del batch_data usando Utils helper
	 * 
	 * Wrapper para el método estático Utils::get_array_value() que centraliza
	 * la lógica de acceso seguro a arrays con logging contextual.
	 * 
	 * @param array $batch_data Datos completos del batch
	 * @param string $key Clave a obtener del batch
	 * @param mixed|null $default Valor por defecto si no existe la clave
	 * @param string $context Contexto para logging (debugging)
	 * @return mixed Valor encontrado o valor por defecto
	 */
	private function get_batch_value(array $batch_data, string $key, mixed $default = null, string $context = 'unknown'): mixed
	{
		return Utils::get_array_value(
			$batch_data, 
			$key, 
			$default, 
			$context, 
			$this->getLogger()
		);
	}

	/**
	 * Versión estática de get_batch_value para uso en métodos estáticos
	 */
	private static function get_batch_value_static(array $batch_data, string $key, $default = null, string $context = 'unknown'): mixed
	{
		return Utils::get_array_value(
			$batch_data, 
			$key, 
			$default, 
			$context // No logger en versión estática
		);
	}

	/**
	 * Verifica si un producto ya existe usando datos del batch
	 * 
	 * Este método verifica la existencia de productos usando el caché del batch,
	 * evitando consultas individuales a WooCommerce para mejorar el rendimiento.
	 * 
	 * @param string $sku SKU del producto a verificar
	 * @param array $batch_data Datos del batch completo
	 * @return array|false Datos del producto existente o false si no existe
	 */
	public function productAlreadyExistsFromBatch(string $sku, array $batch_data): array|false
	{
		// Obtener productos existentes desde batch
		$existing_products = $this->get_batch_value($batch_data, 'existing_products_indexed', [], 'product_existence_check');
		
		// Verificar si el SKU existe en productos del batch
		if (isset($existing_products[$sku])) {
			return $existing_products[$sku];
		}

		// Verificar en SKUs indexados del batch
		$existing_skus = $this->get_batch_value($batch_data, 'existing_skus_indexed', [], 'product_existence_check');
		
		if (isset($existing_skus[$sku])) {
			return ['sku' => $sku, 'exists' => true];
		}
		return false;
	}

	/**
	 * Valida categorías del producto contra datos del batch
	 * 
	 * @param array $product_data Datos del producto
	 * @param array $batch_data Datos del batch
	 * @return array Array de categorías válidas
	 */
	public static function validate_categories_from_batch(array $product_data, array $batch_data): array {
		$categories = $product_data['category_ids'] ?? [];
		if (empty($categories) || !is_array($categories)) {
			return [];
		}

		// Obtener categorías disponibles desde batch
		$available_categories = array_keys(self::get_batch_value_static($batch_data, 'categorias_indexed', [], 'category_validation'));
		$available_web_categories = array_keys(self::get_batch_value_static($batch_data, 'categorias_web_indexed', [], 'category_validation'));

		// Validar categorías contra datos disponibles
		$valid_categories = array_intersect($categories, array_merge($available_categories, $available_web_categories));

		if (count($valid_categories) !== count($categories)) {
			$invalid_categories = array_diff($categories, $valid_categories);
			
			// Usar logger estático si está disponible
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = LoggerBasic::getInstance();
				$logger->warning("⚠️ Categorías inválidas detectadas", [
					'sku' => $product_data['sku'] ?? 'N/A',
					'invalid_categories' => $invalid_categories,
					'valid_categories' => $valid_categories,
					'available_categories_count' => count($available_categories) + count($available_web_categories)
				]);
			}
		}

		// Usar logger estático si está disponible
		if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
			$logger = LoggerBasic::getInstance();
			$logger->debug('✅ Categorías validadas contra batch', [
				'original_categories' => $categories,
				'valid_categories' => $valid_categories,
				'available_categories_count' => count($available_categories) + count($available_web_categories)
			]);
		}

		return $valid_categories;
	}

	/**
	 * Obtiene stock del producto desde caché batch
	 * @param int $verial_id ID del producto en Verial
	 * @param array $batch_data Datos del batch
	 * @param int|null $default_stock Stock por defecto si no se encuentra
	 * @return int Stock del producto
	 */
	public static function get_product_stock_from_batch(int $verial_id, array $batch_data, ?int $default_stock = null): int {
		$stock_productos = self::get_batch_value_static($batch_data, 'stock_productos', [], 'product_stock');
		
		if (empty($stock_productos)) {
			// Usar logger estático si está disponible
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = LoggerBasic::getInstance();
				$logger->info("ℹ️ No hay datos de stock en batch, usando valor por defecto", [
					'verial_id' => $verial_id,
					'default_stock' => $default_stock
				]);
			}
			return $default_stock ?? 0;
		}

		// Buscar stock para este producto específico
		foreach ($stock_productos as $stock_data) {
			if (isset($stock_data['ID_Articulo']) && (int)$stock_data['ID_Articulo'] === $verial_id) {
				$stock = isset($stock_data['Stock']) ? (int)$stock_data['Stock'] : 0;
				
				// Usar logger estático si está disponible
				if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
					$logger = LoggerBasic::getInstance();
					$logger->info("📦 Stock obtenido desde batch", [
						'verial_id' => $verial_id,
						'stock' => $stock,
						'source' => 'batch_cache'
					]);
				}
				
				return $stock;
			}
		}

		// Usar logger estático si está disponible
		if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
			$logger = LoggerBasic::getInstance();
			$logger->info("ℹ️ Stock no encontrado en batch para producto", [
				'verial_id' => $verial_id,
				'default_stock' => $default_stock
			]);
		}
		
		return $default_stock ?? 0;
	}

	/**
	 * Procesa y asigna atributos del producto desde datos del batch
	 * @param WC_Product $product Producto de WooCommerce
	 * @param array $product_data Datos del producto de Verial
	 * @param array $batch_data Datos del batch
	 * @return bool True si se procesaron atributos, false en caso contrario
	 */
	public function process_product_attributes_from_batch(WC_Product $product, array $product_data, array $batch_data): bool {
		$verial_id = self::extract_verial_id($product_data);
		if (!$verial_id) {
			$this->logger->warning('⚠️ No se puede procesar atributos - Verial ID no disponible', [
				'sku' => $product_data['sku'] ?? 'N/A'
			]);
			return false;
		}

		$attributes_processed = [];
		$total_attributes = 0;

		try {
			// === 1. PROCESAR FABRICANTES ===
			$fabricante_processed = $this->process_fabricante_attribute($product, $product_data, $batch_data);
			if ($fabricante_processed) {
				$attributes_processed[] = 'fabricante';
				$total_attributes++;
			}

			// === 2. PROCESAR COLECCIONES ===
			$coleccion_processed = $this->process_coleccion_attribute($product, $product_data, $batch_data);
			if ($coleccion_processed) {
				$attributes_processed[] = 'coleccion';
				$total_attributes++;
			}

			// === 3. PROCESAR CURSOS ===
			$curso_processed = $this->process_curso_attribute($product, $product_data, $batch_data);
			if ($curso_processed) {
				$attributes_processed[] = 'curso';
				$total_attributes++;
			}

			// === 4. PROCESAR ASIGNATURAS ===
			$asignatura_processed = $this->process_asignatura_attribute($product, $product_data, $batch_data);
			if ($asignatura_processed) {
				$attributes_processed[] = 'asignatura';
				$total_attributes++;
			}

			// === 5. PROCESAR CAMPOS CONFIGURABLES ===
			$campos_processed = $this->process_campos_configurables($product, $product_data, $batch_data);
			if ($campos_processed) {
				$attributes_processed[] = 'campos_configurables';
				$total_attributes++;
			}

			// === 6. GUARDAR ATRIBUTOS EN EL PRODUCTO ===
			if ($total_attributes > 0) {
				$product->save();
				
				// ✅ OPTIMIZADO: Log eliminado - se genera demasiado volumen por cada producto
				// Solo se registran errores de atributos
				
				return true;
			} else {
				// ✅ OPTIMIZADO: Log eliminado - se genera demasiado volumen por cada producto
				// Solo se registran errores de atributos
				return false;
			}

		} catch (Exception $e) {
			$this->logger->error('🚨 Error procesando atributos del producto desde batch', [
				'product_id' => $product->get_id(),
				'verial_id' => $verial_id,
				'error' => $e->getMessage(),
				'attributes_attempted' => $attributes_processed
			]);
			return false;
		}
	}

	/**
	 * Procesa el atributo fabricante del producto
	 * @param WC_Product $product Producto de WooCommerce
	 * @param array $product_data Datos del producto de Verial
	 * @param array $batch_data Datos del batch
	 * @return bool True si se procesó, false en caso contrario
	 */
	public function process_fabricante_attribute(WC_Product $product, array $product_data, array $batch_data): bool {
		// Buscar ID de fabricante en los datos del producto
		$fabricante_id = null;
		$fabricante_fields = ['ID_Fabricante', 'FabricanteID', 'EditorID'];
		
		foreach ($fabricante_fields as $field) {
			if (!empty($product_data[$field]) && $product_data[$field] > 0) {
				$fabricante_id = (int)$product_data[$field];
				break;
			}
		}

		if (!$fabricante_id) {
			return false;
		}

		// Obtener nombre del fabricante desde batch
		$fabricantes_indexed = $this->get_batch_value($batch_data, 'fabricantes_indexed', [], 'fabricante_attribute');
		if (empty($fabricantes_indexed) || !isset($fabricantes_indexed[$fabricante_id])) {
			$this->logger->debug('ℹ️ Fabricante no encontrado en batch', [
				'fabricante_id' => $fabricante_id,
				'available_fabricantes' => array_keys($fabricantes_indexed)
			]);
			return false;
		}

		$fabricante_name = $fabricantes_indexed[$fabricante_id];
		
		// Crear o obtener atributo fabricante
		$attribute_name = 'Fabricante';
		$attribute_slug = 'pa_fabricante';
		
		$attribute_id = $this->create_or_get_product_attribute($attribute_name, $attribute_slug);
		if (!$attribute_id) {
			return false;
		}

		// Crear o obtener término del atributo
		$term_id = $this->create_or_get_attribute_term($attribute_id, $fabricante_name, $fabricante_name);
		if (!$term_id) {
			return false;
		}

		// Asignar término al producto
		wp_set_object_terms($product->get_id(), $term_id, $attribute_slug, true);
		
		// Guardar metadato del fabricante
		$product->update_meta_data('_fabricante_verial_id', $fabricante_id);
		$product->update_meta_data('_fabricante_name', $fabricante_name);

		$this->logger->debug('✅ Atributo fabricante procesado', [
			'fabricante_id' => $fabricante_id,
			'fabricante_name' => $fabricante_name,
			'term_id' => $term_id
		]);

		return true;
	}

	/**
	 * Procesa el atributo colección del producto
	 * @param WC_Product $product Producto de WooCommerce
	 * @param array $product_data Datos del producto de Verial
	 * @param array $batch_data Datos del batch
	 * @return bool True si se procesó, false en caso contrario
	 */
	public function process_coleccion_attribute(WC_Product $product, array $product_data, array $batch_data): bool {
		// Buscar ID de colección en los datos del producto
		$coleccion_id = null;
		$coleccion_fields = ['ID_Coleccion', 'ColeccionID', 'SerieID'];
		
		foreach ($coleccion_fields as $field) {
			if (!empty($product_data[$field]) && $product_data[$field] > 0) {
				$coleccion_id = (int)$product_data[$field];
				break;
			}
		}

		if (!$coleccion_id) {
			return false;
		}

		// Obtener nombre de la colección desde batch
		$colecciones_indexed = $this->get_batch_value($batch_data, 'colecciones_indexed', [], 'coleccion_attribute');
		if (empty($colecciones_indexed) || !isset($colecciones_indexed[$coleccion_id])) {
			return false;
		}

		$coleccion_name = $colecciones_indexed[$coleccion_id];
		
		// Crear o obtener atributo colección
		$attribute_name = 'Colección';
		$attribute_slug = 'pa_coleccion';
		
		$attribute_id = $this->create_or_get_product_attribute($attribute_name, $attribute_slug);
		if (!$attribute_id) {
			return false;
		}

		// Crear o obtener término del atributo
		$term_id = $this->create_or_get_attribute_term($attribute_id, $coleccion_name, $coleccion_name);
		if (!$term_id) {
			return false;
		}

		// Asignar término al producto
		wp_set_object_terms($product->get_id(), $term_id, $attribute_slug, true);
		
		// Guardar metadato de la colección
		$product->update_meta_data('_coleccion_verial_id', $coleccion_id);
		$product->update_meta_data('_coleccion_name', $coleccion_name);

		return true;
	}

	/**
	 * Procesa el atributo curso del producto
	 * @param WC_Product $product Producto de WooCommerce
	 * @param array $product_data Datos del producto de Verial
	 * @param array $batch_data Datos del batch
	 * @return bool True si se procesó, false en caso contrario
	 */
	public function process_curso_attribute(WC_Product $product, array $product_data, array $batch_data): bool {
		// Buscar ID de curso en los datos del producto
		$curso_id = null;
		$curso_fields = ['ID_Curso', 'CursoID', 'NivelID'];
		
		foreach ($curso_fields as $field) {
			if (!empty($product_data[$field]) && $product_data[$field] > 0) {
				$curso_id = (int)$product_data[$field];
				break;
			}
		}

		if (!$curso_id) {
			return false;
		}

		// Obtener nombre del curso desde batch
		$cursos_indexed = $this->get_batch_value($batch_data, 'cursos_indexed', [], 'curso_attribute');
		if (empty($cursos_indexed) || !isset($cursos_indexed[$curso_id])) {
			return false;
		}

		$curso_name = $cursos_indexed[$curso_id];
		
		// Crear o obtener atributo curso
		$attribute_name = 'Curso';
		$attribute_slug = 'pa_curso';
		
		$attribute_id = $this->create_or_get_product_attribute($attribute_name, $attribute_slug);
		if (!$attribute_id) {
			return false;
		}

		// Crear o obtener término del atributo
		$term_id = $this->create_or_get_attribute_term($attribute_id, $curso_name, $curso_name);
		if (!$term_id) {
			return false;
		}

		// Asignar término al producto
		wp_set_object_terms($product->get_id(), $term_id, $attribute_slug, true);
		
		// Guardar metadato del curso
		$product->update_meta_data('_curso_verial_id', $curso_id);
		$product->update_meta_data('_curso_name', $curso_name);

		return true;
	}

	/**
	 * Procesa el atributo asignatura del producto
	 * @param WC_Product $product Producto de WooCommerce
	 * @param array $product_data Datos del producto de Verial
	 * @param array $batch_data Datos del batch
	 * @return bool True si se procesó, false en caso contrario
	 */
	public function process_asignatura_attribute(WC_Product $product, array $product_data, array $batch_data): bool {
		// Buscar ID de asignatura en los datos del producto
		$asignatura_id = null;
		$asignatura_fields = ['ID_Asignatura', 'AsignaturaID', 'MateriaID'];
		
		foreach ($asignatura_fields as $field) {
			if (!empty($product_data[$field]) && $product_data[$field] > 0) {
				$asignatura_id = (int)$product_data[$field];
				break;
			}
		}

		if (!$asignatura_id) {
			return false;
		}

		// Obtener nombre de la asignatura desde batch
		$asignaturas_indexed = $this->get_batch_value($batch_data, 'asignaturas_indexed', [], 'asignatura_attribute');
		if (empty($asignaturas_indexed) || !isset($asignaturas_indexed[$asignatura_id])) {
			return false;
		}

		$asignatura_name = $asignaturas_indexed[$asignatura_id];
		
		// Crear o obtener atributo asignatura
		$attribute_name = 'Asignatura';
		$attribute_slug = 'pa_asignatura';
		
		$attribute_id = $this->create_or_get_product_attribute($attribute_name, $attribute_slug);
		if (!$attribute_id) {
			return false;
		}

		// Crear o obtener término del atributo
		$term_id = $this->create_or_get_attribute_term($attribute_id, $asignatura_name, $asignatura_name);
		if (!$term_id) {
			return false;
		}

		// Asignar término al producto
		wp_set_object_terms($product->get_id(), $term_id, $attribute_slug, true);
		
		// Guardar metadato de la asignatura
		$product->update_meta_data('_asignatura_verial_id', $asignatura_id);
		$product->update_meta_data('_asignatura_name', $asignatura_name);

		return true;
	}

	/**
	 * Procesa campos configurables del producto
	 * @param WC_Product $product Producto de WooCommerce
	 * @param array $product_data Datos del producto de Verial
	 * @param array $batch_data Datos del batch
	 * @return bool True si se procesaron, false en caso contrario
	 */
	public function process_campos_configurables(WC_Product $product, array $product_data, array $batch_data): bool {
		$verial_id = self::extract_verial_id($product_data);
		if (!$verial_id) {
			return false;
		}

		// Obtener campos configurables desde batch
		$campos_configurables = $this->get_batch_value($batch_data, 'campos_configurables', [], 'campos_configurables');
		$arboles_campos = $this->get_batch_value($batch_data, 'arboles_campos', [], 'arboles_campos');
		$valores_validados = $this->get_batch_value($batch_data, 'valores_validados', [], 'valores_validados');

		if (empty($campos_configurables)) {
			return false;
		}

		$campos_procesados = 0;

		// Buscar campos configurables para este producto específico
		foreach ($campos_configurables as $campo) {
			if (!isset($campo['ID_Articulo']) || (int)$campo['ID_Articulo'] !== $verial_id) {
				continue;
			}

			$campo_id = $campo['ID_Campo'] ?? null;
			$campo_nombre = $campo['Nombre'] ?? null;
			$campo_valor = $campo['Valor'] ?? null;

			if (!$campo_id || !$campo_nombre) {
				continue;
			}

			// Crear atributo personalizado para el campo configurable
			$attribute_slug = 'pa_campo_' . sanitize_title($campo_nombre);
			$attribute_name = $campo_nombre;

			$attribute_id = $this->create_or_get_product_attribute($attribute_name, $attribute_slug);
			if (!$attribute_id) {
				continue;
			}

			// Si hay valor, crear término y asignarlo
			if ($campo_valor) {
				$term_id = $this->create_or_get_attribute_term($attribute_id, $campo_valor, $campo_valor);
				if ($term_id) {
					wp_set_object_terms($product->get_id(), $term_id, $attribute_slug, true);
					$campos_procesados++;
				}
			}

			// Guardar metadato del campo configurable
			$product->update_meta_data("_campo_configurable_{$campo_id}_nombre", $campo_nombre);
			$product->update_meta_data("_campo_configurable_{$campo_id}_valor", $campo_valor);
		}

		if ($campos_procesados > 0) {
			$this->logger->debug('✅ Campos configurables procesados', [
				'product_id' => $product->get_id(),
				'verial_id' => $verial_id,
				'campos_procesados' => $campos_procesados
			]);
			return true;
		}

		return false;
	}

	/**
	 * Optimiza datos de batch para procesamiento de atributos
	 * @param array $batch_data Datos del batch
	 * @param string $attribute_type Tipo de atributo a optimizar
	 * @return array Datos optimizados para el tipo de atributo
	 */
	public function optimize_batch_data_for_attributes(array $batch_data, string $attribute_type): array {
		$optimized_data = [];
		
		switch ($attribute_type) {
			case 'fabricante':
				$optimized_data = $this->get_batch_value($batch_data, 'fabricantes_indexed', [], 'fabricante_optimization');
				break;
				
			case 'coleccion':
				$optimized_data = $this->get_batch_value($batch_data, 'colecciones_indexed', [], 'coleccion_optimization');
				break;
				
			case 'curso':
				$optimized_data = $this->get_batch_value($batch_data, 'cursos_indexed', [], 'curso_optimization');
				break;
				
			case 'asignatura':
				$optimized_data = $this->get_batch_value($batch_data, 'asignaturas_indexed', [], 'asignatura_optimization');
				break;
				
			case 'campos_configurables':
				$optimized_data = [
					'campos' => $this->get_batch_value($batch_data, 'campos_configurables', [], 'campos_optimization'),
					'arboles' => $this->get_batch_value($batch_data, 'arboles_campos', [], 'arboles_optimization'),
					'valores' => $this->get_batch_value($batch_data, 'valores_validados', [], 'valores_optimization')
				];
				break;
				
			default:
				$this->logger->warning('⚠️ Tipo de atributo no reconocido para optimización', [
					'attribute_type' => $attribute_type,
					'available_types' => ['fabricante', 'coleccion', 'curso', 'asignatura', 'campos_configurables']
				]);
				break;
		}
		
		return $optimized_data;
	}

	/**
	 * Valida que todos los datos necesarios del batch estén disponibles
	 * 
	 * @param array $batch_data Datos del batch
	 * @return array Resultado de validación con detalles
	 */
	public function validate_batch_data_completeness(array $batch_data): array {
		$required_keys = [
			'productos',
			'imagenes_productos', 
			'stock_productos',
			'condiciones_tarifa',
			'categorias_indexed',
			'categorias_web_indexed',
			'fabricantes_indexed',
			'colecciones_indexed',
			'cursos_indexed',
			'asignaturas_indexed',
			'campos_configurables',
			'arboles_campos',
			'valores_validados'
		];
		
		$missing_keys = [];
		$available_keys = [];
		
		foreach ($required_keys as $key) {
			if (array_key_exists($key, $batch_data)) {
				$available_keys[] = $key;
			} else {
				$missing_keys[] = $key;
			}
		}
		
		$completeness_percentage = (count($available_keys) / count($required_keys)) * 100;
		
		return [
			'is_complete' => empty($missing_keys),
			'completeness_percentage' => $completeness_percentage,
			'available_keys' => $available_keys,
			'missing_keys' => $missing_keys,
			'total_required' => count($required_keys),
			'total_available' => count($available_keys)
		];
	}

	/**
	 * Valida la estructura del caché de batch
	 * 
	 * @param array $batch_cache Caché del batch a validar
	 * @return bool True si la estructura es válida, false en caso contrario
	 */
	public function validate_batch_cache_structure(array $batch_cache): bool {
		// Si está vacío, es válido (se usará fallback)
		if (empty($batch_cache)) {
			return true;
		}

		// Verificar que tenga la estructura básica esperada
		$required_keys = [
			'batch_id',
			'status',
			'productos',
			'categorias_indexed',
			'fabricantes_indexed',
			'colecciones_indexed'
		];

		foreach ($required_keys as $key) {
			if (!isset($batch_cache[$key])) {
				return false;
			}
		}

		// Verificar que el status sea 'completed'
		if ($batch_cache['status'] !== 'completed') {
			return false;
		}

		// Verificar que los arrays indexados no estén vacíos
		$indexed_arrays = [
			'categorias_indexed',
			'fabricantes_indexed',
			'colecciones_indexed'
		];

		foreach ($indexed_arrays as $key) {
			if (!is_array($batch_cache[$key]) || empty($batch_cache[$key])) {
				return false;
			}
		}

		// Verificar que los productos existan
		if (!is_array($batch_cache['productos']) || empty($batch_cache['productos'])) {
			return false;
		}

		return true;
	}

	/**
	 * Valida todas las categorías del batch antes del procesamiento
	 * Este método valida que todas las categorías de los productos estén disponibles
	 * en el batch y crea los mapeos necesarios para WooCommerce.
	 * @param array $batch_data Datos del batch a validar
	 * @return array Batch data con categorías validadas y mapeos creados
	 */
	public function validate_categories_in_batch(array $batch_data): array
	{
		$this->logger->info("🔍 Iniciando validación de categorías en batch", [
			'batch_id' => $batch_data['batch_id'] ?? 'unknown',
			'total_products' => count($batch_data['productos'] ?? [])
		]);

		// Obtener categorías disponibles del batch
		$available_categories = array_keys($batch_data['categorias_indexed'] ?? []);
		$available_web_categories = array_keys($batch_data['categorias_web_indexed'] ?? []);
		$all_available_categories = array_merge($available_categories, $available_web_categories);

		$validated_products = [];
		$category_errors = [];
		$category_mappings = [];

		foreach ($batch_data['productos'] as $index => $producto) {
			$verial_category_ids = $this->extractVerialCategoryIds($producto);
			$valid_category_ids = [];

			foreach ($verial_category_ids as $verial_id) {
				// Verificar si la categoría está disponible en el batch
				if (in_array($verial_id, $all_available_categories)) {
					// Obtener o crear mapeo de categoría
					$wc_category_id = $this->getOrCreateCategoryMapping($verial_id, $batch_data);
					if ($wc_category_id) {
						$valid_category_ids[] = $wc_category_id;
						$category_mappings[$verial_id] = $wc_category_id;
					}
				} else {
					$category_errors[] = [
						'product_index' => $index,
						'verial_category_id' => $verial_id,
						'error' => 'Categoría no disponible en batch'
					];
				}
			}

			// Actualizar producto con categorías validadas
			$producto['category_ids'] = $valid_category_ids;
			$validated_products[] = $producto;
		}

		// Actualizar batch con productos validados
		$batch_data['productos'] = $validated_products;
		$batch_data['category_validation'] = [
			'status' => 'completed',
			'total_products' => count($validated_products),
			'category_errors' => count($category_errors),
			'category_mappings' => $category_mappings,
			'errors' => $category_errors
		];

		$this->logger->info("✅ Validación de categorías completada", [
			'total_products' => count($validated_products),
			'category_errors' => count($category_errors),
			'mappings_created' => count($category_mappings)
		]);

		return $batch_data;
	}

	/**
	 * Extrae todos los IDs de categorías de Verial del producto
	 * Según el manual de Verial, las categorías vienen en:
	 * 1. Campos individuales: ID_Categoria, ID_CategoriaWeb1-4
	 * 2. CamposConfigurables con Tipo='categoria'
	 * 3. ArbolesCampos con Tipo='categoria'
	 * @param array $producto Datos del producto de Verial
	 * @return array Array de IDs de categorías únicos
	 */
	private function extractVerialCategoryIds(array $producto): array
	{
		$verial_category_ids = [];
		
		// 1. CAMPOS INDIVIDUALES DE CATEGORÍAS (según manual de Verial)
		$individual_fields = ['ID_Categoria', 'ID_CategoriaWeb1', 'ID_CategoriaWeb2', 'ID_CategoriaWeb3', 'ID_CategoriaWeb4'];
		foreach ($individual_fields as $field) {
			if (!empty($producto[$field]) && $producto[$field] > 0) {
				$cat_id = (int)$producto[$field];
				if (!in_array($cat_id, $verial_category_ids)) {
					$verial_category_ids[] = $cat_id;
				}
			}
		}
		
		// 2. CAMPOS CONFIGURABLES CON TIPO 'CATEGORIA' (según manual de Verial)
		if (!empty($producto['CamposConfigurables']) && is_array($producto['CamposConfigurables'])) {
			foreach ($producto['CamposConfigurables'] as $campo) {
				if (isset($campo['Tipo']) && $campo['Tipo'] === 'categoria' && !empty($campo['Valor'])) {
					$cat_id = (int)$campo['Valor'];
					if (!in_array($cat_id, $verial_category_ids)) {
						$verial_category_ids[] = $cat_id;
					}
				}
			}
		}
		
		// 3. ÁRBOLES DE CAMPOS CON TIPO 'CATEGORIA' (según manual de Verial)
		if (!empty($producto['ArbolesCampos']) && is_array($producto['ArbolesCampos'])) {
			foreach ($producto['ArbolesCampos'] as $arbol) {
				if (isset($arbol['Tipo']) && $arbol['Tipo'] === 'categoria' && !empty($arbol['Valor'])) {
					$cat_id = (int)$arbol['Valor'];
					if (!in_array($cat_id, $verial_category_ids)) {
						$verial_category_ids[] = $cat_id;
					}
				}
			}
		}
		// Eliminar duplicados y devolver
		return array_unique($verial_category_ids);
	}

	/**
	 * Obtiene o crea el mapeo de una categoría de Verial a WooCommerce
	 * @param int $verial_id ID de la categoría en Verial
	 * @param array $batch_data Datos del batch
	 * @return int|null ID de la categoría en WooCommerce
	 */
	private function getOrCreateCategoryMapping(int $verial_id, array $batch_data): ?int
	{
		// 1. Verificar si ya existe mapeo en el batch
		if (!empty($batch_data['category_mappings'][$verial_id])) {
			return (int)$batch_data['category_mappings'][$verial_id];
		}
		
		// 2. Buscar en la base de datos por metadatos
		$existing_id = $this->findCategoryByVerialId($verial_id);
		if ($existing_id) {
			// Guardar en mapeos del batch para futuras referencias
			$batch_data['category_mappings'][$verial_id] = $existing_id;
			return $existing_id;
		}
		
		// 3. Crear nueva categoría
		$category_name = $this->getCategoryNameFromBatch($verial_id, $batch_data);
		$new_id = $this->createNewCategory($verial_id, $category_name);
		
		if ($new_id) {
			// Guardar en mapeos del batch
			$batch_data['category_mappings'][$verial_id] = $new_id;
		}
		
		return $new_id;
	}

	/**
	 * Busca una categoría existente por ID de Verial
	 * @param int $verial_id ID de la categoría en Verial
	 * @return int|null ID de la categoría en WooCommerce
	 */
	private function findCategoryByVerialId(int $verial_id): ?int
	{
		$terms = get_terms([
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
			'meta_query' => [
				[
					'key' => '_verial_category_id',
					'value' => $verial_id,
					'compare' => '='
				]
			],
			'fields' => 'ids'
		]);
		
		return !empty($terms) && !is_wp_error($terms) ? (int)$terms[0] : null;
	}

	/**
	 * Obtiene el nombre de una categoría desde el batch
	 * @param int $verial_id ID de la categoría en Verial
	 * @param array $batch_data Datos del batch
	 * @return string Nombre de la categoría
	 */
	private function getCategoryNameFromBatch(int $verial_id, array $batch_data): string
	{
		// Buscar en categorías normales
		if (!empty($batch_data['categorias_indexed'][$verial_id])) {
			return $batch_data['categorias_indexed'][$verial_id];
		}
		
		// Buscar en categorías web
		if (!empty($batch_data['categorias_web_indexed'][$verial_id])) {
			return $batch_data['categorias_web_indexed'][$verial_id];
		}
		
		// Nombre genérico si no se encuentra
		return "Categoría Verial #{$verial_id}";
	}

	/**
	 * Crea una nueva categoría en WooCommerce o obtiene la existente
	 * @param int $verial_id ID de la categoría en Verial
	 * @param string $category_name Nombre de la categoría
	 * @return int|null ID de la categoría creada o existente
	 */
	private function createNewCategory(int $verial_id, string $category_name): ?int
	{
		// Verificar si la categoría ya existe por nombre
		$existing_term = get_term_by('name', $category_name, 'product_cat');
		if ($existing_term && !is_wp_error($existing_term)) {
			// La categoría ya existe, actualizar metadatos si es necesario
			$existing_id = $existing_term->term_id;
			update_term_meta($existing_id, '_verial_category_id', $verial_id);
			
			$this->logger->debug('Categoría existente encontrada y actualizada', [
				'verial_id' => $verial_id,
				'category_name' => $category_name,
				'category_id' => $existing_id
			]);
			
			return $existing_id;
		}
		
		// Intentar crear nueva categoría
		$new_term_data = wp_insert_term(
			sanitize_text_field($category_name),
			'product_cat',
			['slug' => 'categoria-verial-' . $verial_id]
		);
		
		if (!is_wp_error($new_term_data) && is_array($new_term_data) && isset($new_term_data['term_id'])) {
			update_term_meta((int)$new_term_data['term_id'], '_verial_category_id', $verial_id);
			
			$this->logger->debug('Nueva categoría creada exitosamente', [
				'verial_id' => $verial_id,
				'category_name' => $category_name,
				'category_id' => $new_term_data['term_id']
			]);
			
			return (int)$new_term_data['term_id'];
		}
		
		// Si falla por duplicado, intentar obtener la existente
		if (is_wp_error($new_term_data) && $new_term_data->get_error_code() === 'term_exists') {
			$existing_term = get_term_by('name', $category_name, 'product_cat');
			if ($existing_term && !is_wp_error($existing_term)) {
				update_term_meta($existing_term->term_id, '_verial_category_id', $verial_id);
				
				$this->logger->debug('Categoría duplicada encontrada y actualizada', [
					'verial_id' => $verial_id,
					'category_name' => $category_name,
					'category_id' => $existing_term->term_id
				]);
				
				return $existing_term->term_id;
			}
		}
		
		$this->logger->error('Error creando categoría de WooCommerce', [
			'verial_id' => $verial_id,
			'category_name' => $category_name,
			'error' => is_wp_error($new_term_data) ? $new_term_data->get_error_message() : 'Error desconocido'
		]);
		
		return null;
	}

	/**
	 * Genera métricas de rendimiento para comparar batch vs fallback
	 * @param array $batch_cache Caché del batch
	 * @return array Métricas de rendimiento
	 */
	public function generate_performance_metrics(array $batch_cache): array {
		$metrics = [
			'fallback_methods_used' => [],
			'data_sources' => [],
			'timestamp' => current_time('mysql')
		];

		if (!empty($batch_cache)) {
			$metrics['optimization_level'] = 'high';
			$metrics['batch_cache_utilization'] = 100;
			
			// Identificar qué datos se obtuvieron del batch
		$batch_data_sources = [];
		if (!empty($batch_cache['productos'] ?? null)) {
			$batch_data_sources[] = 'productos';
		}
		if (!empty($batch_cache['imagenes_productos'] ?? null)) {
			$batch_data_sources[] = 'imagenes';
		}
		if (!empty($batch_cache['stock_productos'] ?? null)) {
			$batch_data_sources[] = 'stock';
		}
		if (!empty($batch_cache['categorias_indexed'] ?? null)) {
			$batch_data_sources[] = 'categorias';
		}
		if (!empty($batch_cache['fabricantes_indexed'] ?? null)) {
			$batch_data_sources[] = 'fabricantes';
		}
		if (!empty($batch_cache['colecciones_indexed'] ?? null)) {
			$batch_data_sources[] = 'colecciones';
		}
			
			$metrics['data_sources'] = $batch_data_sources;
			$metrics['batch_cache_keys_available'] = array_keys($batch_cache);
		} else {
			$metrics['optimization_level'] = 'low';
			$metrics['fallback_methods_used'] = [
				'stock_validation' => 'wc_get_product_id_by_sku',
				'image_sync' => 'sync_images_after_product_save',
				'attributes' => 'manual_processing'
			];
		}

		return $metrics;
	}

	/**
	 * Guarda el estado actual del batch de sincronización
	 * @param int $offset Offset actual del batch
	 * @param int $limit Límite del batch
	 * @return void
	 */
	public function save_sync_batch_state(int $offset, int $limit): void {
		mia_set_sync_transient('mia_sync_current_batch_offset', $offset, 6 * HOUR_IN_SECONDS);
		mia_set_sync_transient('mia_sync_current_batch_limit', $limit, 6 * HOUR_IN_SECONDS);
		mia_set_sync_transient('mia_sync_current_batch_time', time(), 6 * HOUR_IN_SECONDS);
		mia_set_sync_transient('mia_sync_batch_start_time', microtime(true), 6 * HOUR_IN_SECONDS);
	}

	/**
	 * Extrae el ID de Verial del producto de forma optimizada
	 * @param array $product_data Datos del producto
	 * @return int|null ID de Verial o null si no se encuentra
	 */
	public static function extract_verial_id(array $product_data): ?int {
		// Prioridad 1: Campo directo 'verial_id'
		if (!empty($product_data['verial_id'] ?? null)) {
			return (int)$product_data['verial_id'];
		}

		// Prioridad 2: Campo 'Id' (formato Verial)
		if (!empty($product_data['Id'] ?? null)) {
			return (int)$product_data['Id'];
		}

		// Prioridad 3: Buscar en meta_data (solo si es necesario)
		if (is_array($product_data['meta_data'] ?? null)) {
			foreach ($product_data['meta_data'] as $meta) {
				if (($meta['key'] ?? '') === '_verial_id' && !empty($meta['value'] ?? null)) {
					return (int)$meta['value'];
				}
			}
		}

		return null;
	}


	/**
	 * Obtiene imágenes del producto desde el caché batch
	 * @param int $verial_id ID del producto en Verial
	 * @param array $batch_data Datos del batch
	 * @return array Array de imágenes o array vacío si no hay
	 */
	public static function get_product_images_from_batch(int $verial_id, array $batch_data): array {
		$imagenes_productos = self::get_batch_value_static($batch_data, 'imagenes_productos', [], 'product_images');
		
		if (empty($imagenes_productos)) {
			// Usar logger estático si está disponible
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = LoggerBasic::getInstance();
				// ✅ OPTIMIZADO: Log eliminado - se genera demasiado volumen por cada producto
				// Solo se registran errores de imágenes
			}
			return [];
		}

		// Filtrar imágenes para este producto específico
		$product_images = [];
		foreach ($imagenes_productos as $imagen) {
			if (isset($imagen['ID_Articulo']) && (int)$imagen['ID_Articulo'] === $verial_id) {
				$product_images[] = $imagen;
			}
		}

		// Usar logger estático si está disponible
		if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
			$logger = LoggerBasic::getInstance();
			// ✅ OPTIMIZADO: Log eliminado - se genera demasiado volumen por cada producto
			// Solo se registran errores de imágenes
		}

		return $product_images;
	}


	/**
	 * Marca un batch como completado
	 * @param int $offset Offset del batch completado
	 * @param int $limit Límite del batch completado
	 * @return void
	 */
	public function mark_batch_completed(int $offset, int $limit): void {
		// Obtener tiempo de inicio del lote si está disponible
		$batch_start_time = mia_get_sync_transient('mia_sync_batch_start_time');
		
		// Delegar a SyncMetrics
		SyncMetrics::markBatchCompleted($offset, $limit, $batch_start_time);
	}

	/**
	 * Crea o obtiene un atributo de producto
	 * @param string $attribute_name Nombre del atributo
	 * @param string $attribute_slug Slug del atributo
	 * @return int|false ID del atributo o false si falla
	 */
	public function create_or_get_product_attribute(string $attribute_name, string $attribute_slug): int|false {
		// ✅ REFACTORIZADO: Usar helper centralizado para verificar funciones
		if (!WooCommerceHelper::isFunctionAvailable('wc_get_attribute_taxonomy_by_name')) {
			$this->getLogger()->error('Función de WooCommerce no disponible', [
				'function' => 'wc_get_attribute_taxonomy_by_name',
				'attribute_name' => $attribute_name
			]);
			return false;
		}
		
		// Verificar si el atributo ya existe
		$existing_attribute = wc_get_attribute_taxonomy_by_name($attribute_name);
		if ($existing_attribute) {
			return $existing_attribute->attribute_id;
		}

		// ✅ REFACTORIZADO: Usar helper centralizado para verificar funciones
		if (!WooCommerceHelper::isFunctionAvailable('wc_create_attribute')) {
			$this->getLogger()->error('Función de creación de atributos no disponible', [
				'function' => 'wc_create_attribute',
				'attribute_name' => $attribute_name
			]);
			return false;
		}
		
		// Crear nuevo atributo
		$attribute_data = [
			'attribute_label' => $attribute_name,
			'attribute_name' => $attribute_slug,
			'attribute_type' => 'select',
			'attribute_orderby' => 'menu_order',
			'attribute_public' => 1
		];

		$attribute_id = wc_create_attribute($attribute_data);
		if (is_wp_error($attribute_id)) {
			$this->logger->error('🚨 Error creando atributo de producto', [
				'attribute_name' => $attribute_name,
				'attribute_slug' => $attribute_slug,
				'error' => $attribute_id->get_error_message(),
				'error_code' => $attribute_id->get_error_code(),
				'error_data' => $attribute_id->get_error_data()
			]);
			return false;
		}

		// Registrar el atributo en WooCommerce
		wc_register_attribute_taxonomy($attribute_slug);

		$this->logger->debug('✅ Atributo de producto creado', [
			'attribute_name' => $attribute_name,
			'attribute_slug' => $attribute_slug,
			'attribute_id' => $attribute_id
		]);

		return $attribute_id;
	}

	/**
	 * Crea o obtiene un término de atributo
	 * @param int $attribute_id ID del atributo
	 * @param string $term_name Nombre del término
	 * @param string $term_slug Slug del término
	 * @return int|false ID del término o false si falla
	 */
	public function create_or_get_attribute_term(int $attribute_id, string $term_name, string $term_slug): int|false {
		// Obtener la taxonomía del atributo
		$attribute = wc_get_attribute($attribute_id);
		if (!$attribute) {
			return false;
		}

		$taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);

		// Verificar si el término ya existe
		$existing_term = get_term_by('slug', $term_slug, $taxonomy);
		if ($existing_term && !is_wp_error($existing_term)) {
			return $existing_term->term_id;
		}

		// Crear nuevo término
		$term_data = wp_insert_term($term_name, $taxonomy, [
			'slug' => $term_slug
		]);

		if (is_wp_error($term_data)) {
			$this->logger->error('🚨 Error creando término de atributo', [
				'attribute_id' => $attribute_id,
				'term_name' => $term_name,
				'term_slug' => $term_slug,
				'error' => $term_data->get_error_message(),
				'error_code' => $term_data->get_error_code(),
				'error_data' => $term_data->get_error_data()
			]);
			return false;
		}

		return $term_data['term_id'];
	}

	/**
	 * Debug de visibilidad de productos en WooCommerce
	 * Analiza los productos recientes en la base de datos para identificar
	 * problemas de visibilidad, datos faltantes y estados incorrectos.
	 *
	 * @return array Array con estadísticas de productos y problemas encontrados
	 */
	public function debugProductVisibility(): array
	{
		global $wpdb;
		
		$results = [
			'total_products' => 0,
			'published' => 0,
			'draft' => 0,
			'private' => 0,
			'publish' => 0, // Agregar también 'publish' por si acaso
			'missing_data' => [],
			'visibility_issues' => [],
			'recent_products' => []
		];
		
		// Consultar productos directamente en la base de datos
		$products = $wpdb->get_results("
			SELECT ID, post_status, post_title, post_type, post_date
			FROM {$wpdb->posts}
			WHERE post_type = 'product'
			ORDER BY ID DESC
			LIMIT 50
		");
		
		$results['total_products'] = count($products);
		
		foreach ($products as $product) {
			// Contar por estado
			$status = $product->post_status;
			if (isset($results[$status])) {
				$results[$status]++;
			} else {
				$results[$status] = 1;
			}
			
			// Agregar a productos recientes
			$results['recent_products'][] = [
				'id' => $product->ID,
				'title' => $product->post_title,
				'status' => $product->post_status,
				'date' => $product->post_date
			];
			
			// Verificar datos esenciales
			$wc_product = wc_get_product($product->ID);
			if ($wc_product) {
				// Verificar nombre
				if (empty($wc_product->get_name())) {
					$results['missing_data'][] = [
						'id' => $product->ID,
						'issue' => 'Sin nombre',
						'status' => $product->post_status,
						'title' => $product->post_title
					];
				}
				
				// Verificar SKU
				if (empty($wc_product->get_sku())) {
					$results['missing_data'][] = [
						'id' => $product->ID,
						'issue' => 'Sin SKU',
						'status' => $product->post_status,
						'title' => $product->post_title
					];
				}
				
				// Verificar visibilidad
				$visibility = $wc_product->get_catalog_visibility();
				if ($visibility !== 'visible') {
					$results['visibility_issues'][] = [
						'id' => $product->ID,
						'issue' => 'Visibilidad no visible',
						'status' => $product->post_status,
						'visibility' => $visibility,
						'title' => $product->post_title
					];
				}
				
				// Verificar si está en papelera
				if ($product->post_status === 'trash') {
					$results['visibility_issues'][] = [
						'id' => $product->ID,
						'issue' => 'Producto en papelera',
						'status' => $product->post_status,
						'title' => $product->post_title
					];
				}
			} else {
				$results['missing_data'][] = [
					'id' => $product->ID,
					'issue' => 'No se puede cargar como WC_Product',
					'status' => $product->post_status,
					'title' => $product->post_title
				];
			}
		}
		
		// Log de resultados para debugging
		$this->logger->info('🔍 Debug de visibilidad de productos completado', [
			'total_products' => $results['total_products'],
			'published' => $results['published'] ?? 0,
			'draft' => $results['draft'] ?? 0,
			'private' => $results['private'] ?? 0,
			'missing_data_count' => count($results['missing_data']),
			'visibility_issues_count' => count($results['visibility_issues'])
		]);
		
		return $results;
	}

	/**
	 * Diagnóstico rápido del sistema de sincronización
	 * Proporciona información inmediata sobre el estado del sistema,
	 * productos en la base de datos, configuración y errores recientes.
	 *
	 * @return array Array con información de diagnóstico
	 */
	public function quickDiagnosis(): array
	{
		$diagnosis = [];
		
		// 1. Verificar productos en la base de datos
		global $wpdb;
		$products = $wpdb->get_results("
			SELECT ID, post_status, post_title, post_type 
			FROM {$wpdb->posts} 
			WHERE post_type IN ('product', 'product_variation')
			ORDER BY post_date DESC 
			LIMIT 10
		");
		
		$diagnosis['database_products'] = $products;
		$diagnosis['total_products'] = count($products);
		
		// 2. Verificar últimos productos sincronizados
		$last_sync_transient = get_transient('mia_sync_progress');
		$diagnosis['last_sync'] = $last_sync_transient;
		
		// 3. Verificar configuración WooCommerce
		$diagnosis['woocommerce_settings'] = [
			'hide_out_of_stock' => get_option('woocommerce_hide_out_of_stock_items'),
			'currency' => get_option('woocommerce_currency'),
			'price_display_suffix' => get_option('woocommerce_price_display_suffix')
		];
		
		// 4. Verificar errores recientes en logs
		$diagnosis['recent_errors'] = $this->getRecentErrors();
		
		// 5. Verificar estado de la primera sincronización
		$diagnosis['first_sync_completed'] = get_option('mia_first_sync_completed', false);
		
		// 6. Verificar caché de sincronización
		$diagnosis['sync_cache'] = [
			'batch_cache_exists' => !empty(get_transient('mia_batch_cache')),
			'product_cache_exists' => !empty(get_transient('mia_product_cache')),
			'sync_progress_exists' => !empty(get_transient('mia_sync_progress'))
		];
		
		// 7. Verificar configuración del plugin
		$diagnosis['plugin_settings'] = [
			'api_url' => get_option('mia_api_url', 'No configurado'),
			'api_key' => get_option('mia_api_key', 'No configurado') ? 'Configurado' : 'No configurado',
			'batch_size' => get_option('mia_batch_size', 'No configurado'),
			'auto_sync' => get_option('mia_auto_sync', false)
		];
		
		// 8. Verificar memoria y límites del servidor
		$diagnosis['server_limits'] = [
			'memory_limit' => ini_get('memory_limit'),
			'max_execution_time' => ini_get('max_execution_time'),
			'post_max_size' => ini_get('post_max_size'),
			'upload_max_filesize' => ini_get('upload_max_filesize')
		];
		
		// 9. Verificar estado de WooCommerce
		$diagnosis['woocommerce_status'] = [
			'is_active' => class_exists('WooCommerce'),
			'version' => defined('WC_VERSION') ? WC_VERSION : 'No disponible',
			'is_shop_page' => is_shop(),
			'is_product_page' => is_product()
		];
		
		// 10. Verificar productos con problemas específicos
		$problematic_products = $wpdb->get_results("
			SELECT ID, post_status, post_title, post_type 
			FROM {$wpdb->posts} 
			WHERE post_type = 'product' 
			AND (post_status = 'trash' OR post_title = '' OR post_title IS NULL)
			ORDER BY post_date DESC 
			LIMIT 5
		");
		
		$diagnosis['problematic_products'] = $problematic_products;
		
		// Log del diagnóstico
		$this->logger->info('🔍 Diagnóstico rápido ejecutado', [
			'total_products' => $diagnosis['total_products'],
			'first_sync_completed' => $diagnosis['first_sync_completed'],
			'woocommerce_active' => $diagnosis['woocommerce_status']['is_active'],
			'problematic_products_count' => count($problematic_products)
		]);
		
		return $diagnosis;
	}

	/**
	 * Obtiene errores recientes del sistema de logging
	 *
	 * @return array Array con los últimos errores encontrados
	 */
	private function getRecentErrors(): array
	{
		// Buscar errores recientes en logs (ajusta según tu sistema de logging)
		$recent_errors = [];
		
		// Ejemplo: buscar en opciones de WordPress
		$sync_errors = get_option('mia_sync_errors', []);
		if (is_array($sync_errors)) {
			$recent_errors = array_slice($sync_errors, -5); // Últimos 5 errores
		}
		
		// También buscar en logs del sistema si están disponibles
		$log_file = WP_CONTENT_DIR . '/debug.log';
		if (file_exists($log_file)) {
			$log_content = file_get_contents($log_file);
			$log_lines = explode("\n", $log_content);
			$error_lines = array_filter($log_lines, function($line) {
				return stripos($line, 'error') !== false || stripos($line, 'fatal') !== false;
			});
			$recent_errors = array_merge($recent_errors, array_slice($error_lines, -3));
		}
		
		return $recent_errors;
	}
}
