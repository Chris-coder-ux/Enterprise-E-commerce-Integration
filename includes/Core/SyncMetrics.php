<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\WooCommerce\SyncHelper;
use MiIntegracionApi\Logging\Core\LogManager;

/**
 * Sistema de métricas y monitoreo para sincronizaciones
 */
class SyncMetrics
{
    private const METRICS_PREFIX = 'mia_sync_metrics_';
    private const DEFAULT_TTL = 604800; // 7 días
    private const METRICS_OPTION = 'mi_integracion_api_sync_metrics';
    private const MAX_HISTORY_DAYS = 30;
    private const MEMORY_THRESHOLD = 0.8; // 80% del límite de memoria
    private const CLEANUP_INTERVAL = 500; // ✅ OPTIMIZADO: Limpiar cada 500 items (reducir frecuencia)

    private array $currentMetrics = [];
    private array $startTimes = [];
    private array $memorySnapshots = [];
    private \MiIntegracionApi\Logging\Interfaces\ILogger $logger;
    private int $lastCleanupTime = 0;
    private int $itemsSinceLastCleanup = 0;
    private ?string $currentOperationId = null;
    
    // Logger estático para métodos estáticos
    private static ?\MiIntegracionApi\Logging\Interfaces\ILogger $staticLogger = null;
    
    // Instancia singleton
    private static ?self $instance = null;

    // Constantes para tipos de error
    private const ERROR_TYPE_VALIDATION = 'validation';
    private const ERROR_TYPE_API = 'api';
    private const ERROR_TYPE_CONCURRENCY = 'concurrency';
    private const ERROR_TYPE_MEMORY = 'memory';
    private const ERROR_TYPE_NETWORK = 'network';
    private const ERROR_TYPE_TIMEOUT = 'timeout';
    private const ERROR_TYPE_UNKNOWN = 'unknown';

    public function __construct()
    {
        $this->logger = LogManager::getInstance()->getLogger('sync_metrics');
        $this->loadMetrics();
    }
    
    /**
     * Obtiene la instancia única de SyncMetrics
     * 
     * @return self Instancia única
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Obtiene el logger estático para métodos estáticos
     */
    private static function getStaticLogger(): \MiIntegracionApi\Logging\Interfaces\ILogger
    {
        if (self::$staticLogger === null) {
            self::$staticLogger = LogManager::getInstance()->getLogger('sync_metrics');
        }
        return self::$staticLogger;
    }
    
    /**
     * ✅ CONFIGURACIÓN CENTRALIZADA: Obtiene la configuración de métricas (instancia)
     * Reemplaza hardcodeos con configuración externa
     */
    private function getConfig(): array
    {
        return self::getStaticConfig();
    }
    
    /**
     * ✅ CONFIGURACIÓN CENTRALIZADA: Obtiene la configuración de métricas (estática)
     * Para uso en métodos estáticos
     */
    private static function getStaticConfig(): array
    {
        return [
            'metrics_prefix' => get_option('mia_metrics_prefix', self::METRICS_PREFIX),
            'default_ttl' => (int) get_option('mia_metrics_ttl', self::DEFAULT_TTL),
            'metrics_option' => get_option('mia_metrics_option_name', self::METRICS_OPTION),
            'max_history_days' => (int) get_option('mia_metrics_history_days', self::MAX_HISTORY_DAYS),
            'memory_threshold' => (float) get_option('mia_memory_threshold', self::MEMORY_THRESHOLD),
            'cleanup_interval' => (int) get_option('mia_cleanup_interval', self::CLEANUP_INTERVAL),
            
            // Nuevas configuraciones para prevenir memory leaks
            'max_errors_per_operation' => (int) get_option('mia_max_errors_per_op', 1000),
            'max_memory_snapshots' => (int) get_option('mia_max_memory_snapshots', 100),
            'max_operations_active' => (int) get_option('mia_max_active_operations', 50), // ✅ OPTIMIZADO: Aumentar de 10 a 50
            
            // ✅ ELIMINAR HARDCODEOS: Configuraciones adicionales
            'cleanup_check_interval' => (int) get_option('mia_cleanup_check_interval', 900), // ✅ OPTIMIZADO: 15 minutos (era 5)
            'old_operation_threshold' => (int) get_option('mia_old_operation_threshold', 7200), // 2 horas
            'operations_warning_threshold' => (float) get_option('mia_operations_warning_threshold', 0.8), // 80%
            'old_operation_warning_threshold' => (int) get_option('mia_old_operation_warning', 3600), // 1 hora
            'memory_per_operation_threshold' => (int) get_option('mia_memory_per_op_threshold', 1048576) // 1MB
        ];
    }
    
    /**
     * ✅ FORMATO TEMPORAL UNIFICADO: Obtiene timestamp actual
     */
    private function getCurrentTimestamp(): int
    {
        return time();
    }
    
    /**
     * ✅ FORMATO TEMPORAL UNIFICADO: Obtiene timestamp actual (estático)
     */
    private static function getCurrentTimestampStatic(): int
    {
        return time();
    }
    
    /**
     * ✅ FORMATO TEMPORAL UNIFICADO: Convierte timestamp a string para display
     */
    private function formatTimestamp(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
    
    /**
     * ✅ FORMATO TEMPORAL UNIFICADO: Convierte timestamp a string para display (estático)
     */
    private static function formatTimestampStatic(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
    
    /**
     * ✅ FORMATO TEMPORAL UNIFICADO: Convierte string a timestamp (con validación)
     */
    private function parseTimestamp(string $timeString): int
    {
        $timestamp = strtotime($timeString);
        return $timestamp !== false ? $timestamp : time();
    }

    /**
     * Registra una métrica de sincronización
     * 
     * @param string $entity Nombre de la entidad
     * @param array<string, mixed> $metrics Métricas a registrar
     * @param int $ttl Tiempo de vida en segundos
     * @return bool Éxito de la operación
     */
    public static function recordMetrics(string $entity, array $metrics, ?int $ttl = null): bool
    {
        if (empty($entity)) {
            return false;
        }
        
        // ✅ CONFIGURACIÓN: Usar configuración centralizada en lugar de hardcodeos
        $config = self::getStaticConfig();
        $ttl = $ttl ?? $config['default_ttl'];

        $key = self::getMetricsKey($entity);
        $metrics['timestamp'] = time(); // ✅ UNIFICADO: Mantener timestamp Unix
        $metrics['entity'] = $entity;

        // INTERCEPTAR ERRORES DE SKU ANTES DE QUE SE PROPAGUEN
        if (isset($metrics['error']) && is_string($metrics['error'])) {
            $error_message = $metrics['error'];
            if (strpos($error_message, 'SKU') !== false || strpos($error_message, 'duplicado') !== false || strpos($error_message, 'válido') !== false) {
                self::getStaticLogger()->error('🚨 ERROR DE SKU INTERCEPTADO EN SYNC-METRICS', [
                    'entity' => $entity,
                    'error_message' => $error_message,
                    'metrics_keys' => array_keys($metrics),
                    'metrics_sample' => array_slice($metrics, 0, 5), // Solo primeros 5 elementos
                    'category' => 'sync-metrics-sku-error',
                    'timestamp' => self::getCurrentTimestampStatic(), // ✅ UNIFICADO: Unix timestamp
                    'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
                ]);
            }
        }

        self::getStaticLogger()->info(
            "Registrando métricas para {$entity}",
            [
                'metrics' => $metrics,
                'category' => "sync-metrics-{$entity}"
            ]
        );

        return set_transient($key, $metrics, $ttl);
    }

    /**
     * Obtiene las métricas de sincronización
     * 
     * @param string $entity Nombre de la entidad
     * @return array<string, mixed>|false Métricas o false si no existen
     */
    public static function getMetrics(string $entity): array|false
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getMetricsKey($entity);
        $metrics = get_transient($key);

        if ($metrics === false) {
            return false;
        }

        // ✅ CONFIGURACIÓN: Verificar si las métricas han expirado usando configuración
        $config = self::getStaticConfig();
        if (isset($metrics['timestamp']) && (time() - $metrics['timestamp']) > $config['default_ttl']) {
            self::clearMetrics($entity);
            return false;
        }

        return $metrics;
    }

    /**
     * Limpia las métricas de sincronización
     * 
     * @param string $entity Nombre de la entidad
     * @return bool Éxito de la operación
     */
    public static function clearMetrics(string $entity): bool
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getMetricsKey($entity);
        
        self::getStaticLogger()->info(
            "Limpiando métricas para {$entity}",
            ['category' => "sync-metrics-{$entity}"]
        );

        return delete_transient($key);
    }

    /**
     * Registra el uso de memoria
     * 
     * @param string $entity Nombre de la entidad
     * @return array<string, int> Métricas de memoria
     */
    public static function recordMemoryUsage(string $entity): array
    {
        $memoryStats = \MiIntegracionApi\Core\MemoryManager::getMemoryStats();
        
        $metrics = [
            'memory_usage' => $memoryStats['current'],
            'peak_memory_usage' => $memoryStats['peak'],
            'memory_limit' => $memoryStats['limit']
        ];

        self::recordMetrics($entity, $metrics);

        return $metrics;
    }

    /**
     * Registra el tiempo de ejecución
     * 
     * @param string $entity Nombre de la entidad
     * @param float $startTime Tiempo de inicio
     * @return array<string, float> Métricas de tiempo
     */
    public static function recordExecutionTime(string $entity, float $startTime): array
    {
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        $metrics = [
            'execution_time' => $executionTime,
            'start_time' => $startTime,
            'end_time' => $endTime
        ];

        self::recordMetrics($entity, $metrics);

        return $metrics;
    }

    /**
     * Registra estadísticas de procesamiento
     * 
     * @param string $entity Nombre de la entidad
     * @param int $processedItems Elementos procesados
     * @param int $totalItems Total de elementos
     * @param int $errorCount Contador de errores
     * @return array<string, mixed> Métricas de procesamiento
     */
    public static function recordProcessingStats(
        string $entity,
        int $processedItems,
        int $totalItems,
        int $errorCount
    ): array {
        $metrics = [
            'processed_items' => $processedItems,
            'total_items' => $totalItems,
            'error_count' => $errorCount,
            'success_rate' => $totalItems > 0 ? (($totalItems - $errorCount) / $totalItems) * 100 : 0
        ];

        self::recordMetrics($entity, $metrics);

        return $metrics;
    }

    /**
     * Obtiene la clave de métricas
     * 
     * @param string $entity Nombre de la entidad
     * @return string Clave de métricas
     */
    private static function getMetricsKey(string $entity): string
    {
        // ✅ CONFIGURACIÓN: Usar configuración centralizada en lugar de hardcodeo
        $config = self::getStaticConfig();
        return $config['metrics_prefix'] . sanitize_key($entity);
    }

    /**
     * Registra métricas de un lote
     * 
     * @param int|float $batchNumber Número del lote
     * @param int $processedItems Elementos procesados
     * @param float $duration Duración del procesamiento
     * @param int $errors Errores encontrados
     * @param int $retryProcessed Elementos procesados en reintentos
     * @param int $retryErrors Errores en reintentos
     * @return void
     */
    public function recordBatchMetrics(
        $batchNumber,
        int $processedItems,
        float $duration,
        int $errors,
        int $retryProcessed = 0,
        int $retryErrors = 0
    ): void {
        // Obtener el operation_id actual
        $operationId = $this->currentOperationId ?? 'default_operation';
        
        // Inicializar las métricas si no existen
        if (!isset($this->currentMetrics[$operationId])) {
            $this->currentMetrics[$operationId] = [
                'entity' => 'unknown',
                'direction' => 'unknown',
                'start_time' => $this->getCurrentTimestamp(), // ✅ UNIFICADO: Unix timestamp
                'status' => 'in_progress',
                'items_processed' => 0,
                'items_succeeded' => 0,
                'items_failed' => 0,
                'errors' => [],
                'memory_usage' => [],
                'performance' => [],
                'error_types' => [],
                'total' => [
                    'processed' => 0,
                    'errors' => 0,
                    'retry_processed' => 0,
                    'retry_errors' => 0,
                    'duration' => 0
                ],
                'batches' => [],
                // ✅ OPTIMIZACIÓN: Running totals para O(1) performance
                'running_totals' => [
                    'memory_peak' => 0,
                    'memory_sum' => 0,
                    'memory_count' => 0,
                    'duration_sum' => 0,
                    'duration_count' => 0
                ]
            ];
        }
        
        // Inicializar las métricas totales si no existen
        if (!isset($this->currentMetrics[$operationId]['total'])) {
            $this->currentMetrics[$operationId]['total'] = [
                'processed' => 0,
                'errors' => 0,
                'retry_processed' => 0,
                'retry_errors' => 0,
                'duration' => 0
            ];
        }
        
        // Inicializar el array de lotes si no existe
        if (!isset($this->currentMetrics[$operationId]['batches'])) {
            $this->currentMetrics[$operationId]['batches'] = [];
        }
        
        // Registrar métricas del lote y asegurar que el índice sea un entero
        $batchKey = (int)$batchNumber;
        $this->currentMetrics[$operationId]['batches'][$batchKey] = [
            'processed' => $processedItems,
            'duration' => $duration,
            'errors' => $errors,
            'retry_processed' => $retryProcessed,
            'retry_errors' => $retryErrors,
            'timestamp' => time()
        ];
        
        // Actualizar métricas totales
        $this->currentMetrics[$operationId]['total']['processed'] += $processedItems;
        $this->currentMetrics[$operationId]['total']['errors'] += $errors;
        $this->currentMetrics[$operationId]['total']['retry_processed'] += $retryProcessed;
        $this->currentMetrics[$operationId]['total']['retry_errors'] += $retryErrors;
        $this->currentMetrics[$operationId]['total']['duration'] += $duration;
        
        // ✅ OPTIMIZACIÓN: Actualizar running totals para duraciones
        if (!isset($this->currentMetrics[$operationId]['running_totals'])) {
            $this->currentMetrics[$operationId]['running_totals'] = [
                'memory_peak' => 0,
                'memory_sum' => 0,
                'memory_count' => 0,
                'duration_sum' => 0,
                'duration_count' => 0
            ];
        }
        
        $runningTotals = &$this->currentMetrics[$operationId]['running_totals'];
        $runningTotals['duration_sum'] += $duration;
        $runningTotals['duration_count']++;
        
        // Guardar las métricas
        $this->saveMetrics($operationId);
    }


    /**
     * Obtiene estadísticas de reintentos
     * 
     * @return array<string, mixed> Estadísticas de reintentos
     */
    public function getRetryStats(): array
    {
        $stats = [
            'total_retries' => 0,
            'successful_retries' => 0,
            'failed_retries' => 0,
            'avg_retry_delay' => 0,
            'retry_by_batch' => []
        ];

        foreach ($this->metrics['batches'] as $batchNumber => $batch) {
            $stats['total_retries'] += $batch['retry_processed'] + $batch['retry_errors'];
            $stats['successful_retries'] += $batch['retry_processed'];
            $stats['failed_retries'] += $batch['retry_errors'];
            
            $stats['retry_by_batch'][$batchNumber] = [
                'processed' => $batch['retry_processed'],
                'errors' => $batch['retry_errors'],
                'success_rate' => $batch['retry_processed'] > 0 
                    ? ($batch['retry_processed'] / ($batch['retry_processed'] + $batch['retry_errors'])) * 100 
                    : 0
            ];
        }

        if ($stats['total_retries'] > 0) {
            $stats['avg_retry_delay'] = $this->metrics['total']['duration'] / $stats['total_retries'];
        }

        return $stats;
    }

    /**
     * Inicia el seguimiento de una operación
     */
    public function startOperation(string $operationId, string $entity, string $direction): void
    {
        // ✅ MEMORY LEAK PREVENTION: Limitar operaciones activas
        $config = $this->getConfig();
        $maxActiveOperations = $config['max_operations_active'];
        
        if (count($this->currentMetrics) >= $maxActiveOperations) {
            $this->logger->warning("Límite de operaciones activas alcanzado, limpiando operaciones más antiguas", [
                'current_operations' => count($this->currentMetrics),
                'max_operations' => $maxActiveOperations,
                'new_operation_id' => $operationId
            ]);
            
            // Limpiar las operaciones más antiguas (por timestamp de inicio)
            $this->cleanupOldestOperations($maxActiveOperations - 1);
        }
        
        // Establecer el ID de operación actual
        $this->currentOperationId = $operationId;
        
        $this->startTimes[$operationId] = microtime(true);
        $this->memorySnapshots[$operationId] = [
            'start' => \MiIntegracionApi\Core\MemoryManager::getMemoryStats()['current'],
            'peak' => \MiIntegracionApi\Core\MemoryManager::getMemoryStats()['peak']
        ];

        $this->currentMetrics[$operationId] = [
            'entity' => $entity,
            'direction' => $direction,
            'start_time' => $this->getCurrentTimestamp(), // ✅ UNIFICADO: Unix timestamp
            'status' => 'in_progress',
            'items_processed' => 0,
            'items_succeeded' => 0,
            'items_failed' => 0,
            'errors' => [],
            'memory_usage' => [],
            'performance' => [],
            'error_types' => [],
            'total' => [
                'processed' => 0,
                'errors' => 0,
                'retry_processed' => 0,
                'retry_errors' => 0,
                'duration' => 0
            ],
            'batches' => [],
            // ✅ OPTIMIZACIÓN: Running totals para O(1) performance
            'running_totals' => [
                'memory_peak' => 0,
                'memory_sum' => 0,
                'memory_count' => 0,
                'duration_sum' => 0,
                'duration_count' => 0
            ]
        ];

        $this->logger->info("Iniciando operación", [
            'operation_id' => $operationId,
            'entity' => $entity,
            'direction' => $direction
        ]);
    }

    /**
     * Registra el procesamiento de un item
     */
    public function recordItemProcessed(string $operationId, bool $success, ?string $error = null): void
    {
        // ✅ ROBUSTEZ: Auto-inicializar operación si no existe para evitar pérdida de datos
        if (!isset($this->currentMetrics[$operationId])) {
            // Auto-inicializar con valores por defecto (sin logging)
            $this->startOperation($operationId, 'auto-created', 'unknown');
        }

        $this->currentMetrics[$operationId]['items_processed']++;
        
        if ($success) {
            $this->currentMetrics[$operationId]['items_succeeded']++;
        } else {
            $this->currentMetrics[$operationId]['items_failed']++;
            if ($error) {
                // ✅ MEMORY LEAK PREVENTION: Limitar errores por operación
                $config = $this->getConfig();
                $maxErrors = $config['max_errors_per_operation'];
                
                if (count($this->currentMetrics[$operationId]['errors']) >= $maxErrors) {
                    // Remover el error más antiguo para hacer espacio
                    array_shift($this->currentMetrics[$operationId]['errors']);
                }
                
                // ✅ ELIMINAR DUPLICIDAD: Una sola llamada a getCurrentTimestamp()
                $currentTime = $this->getCurrentTimestamp();
                $this->currentMetrics[$operationId]['errors'][] = [
                    'time' => $currentTime, // ✅ UNIFICADO: Unix timestamp
                    'time_formatted' => $this->formatTimestamp($currentTime), // Para display  
                    'message' => $error
                ];
            }
        }

        $this->incrementItemCount($operationId);
        $this->checkMemoryUsage($operationId);
    }

    /**
     * Verifica y gestiona el uso de memoria
     */
    public function checkMemoryUsage(string $operationId): bool
    {
        $memoryStats = \MiIntegracionApi\Core\MemoryManager::getMemoryStats();
        $currentMemory = $memoryStats['current'];
        $memoryLimit = $this->getMemoryLimit();
        $memoryUsage = $currentMemory / $memoryLimit;

        // ✅ CONFIGURACIÓN: Obtener configuración
        $config = $this->getConfig();
        
        // ✅ MEMORY LEAK PREVENTION: Limitar snapshots de memoria
        $maxSnapshots = $config['max_memory_snapshots'];
        if (count($this->currentMetrics[$operationId]['memory_usage']) >= $maxSnapshots) {
            // Remover el snapshot más antiguo para hacer espacio
            array_shift($this->currentMetrics[$operationId]['memory_usage']);
            
            $this->logger->debug("Límite de snapshots de memoria alcanzado, eliminando snapshot más antiguo", [
                'operation_id' => $operationId,
                'max_snapshots' => $maxSnapshots
            ]);
        }
        
        // ✅ ELIMINAR DUPLICIDAD: Una sola llamada a getCurrentTimestamp()
        $currentTime = $this->getCurrentTimestamp();
        $memoryRecord = [
            'time' => $currentTime, // ✅ UNIFICADO: Unix timestamp
            'time_formatted' => $this->formatTimestamp($currentTime), // Para display
            'current' => $currentMemory,
            'peak' => $memoryStats['peak'],
            'limit' => $memoryLimit,
            'usage_percentage' => round($memoryUsage * 100, 2)
        ];
        
        $this->currentMetrics[$operationId]['memory_usage'][] = $memoryRecord;
        
        // ✅ OPTIMIZACIÓN: Actualizar running totals para O(1) performance
        if (!isset($this->currentMetrics[$operationId]['running_totals'])) {
            $this->currentMetrics[$operationId]['running_totals'] = [
                'memory_peak' => 0,
                'memory_sum' => 0,
                'memory_count' => 0,
                'duration_sum' => 0,
                'duration_count' => 0
            ];
        }
        
        $runningTotals = &$this->currentMetrics[$operationId]['running_totals'];
        $runningTotals['memory_peak'] = max($runningTotals['memory_peak'], $memoryStats['peak']);
        $runningTotals['memory_sum'] += $currentMemory;
        $runningTotals['memory_count']++;

        // Si el uso de memoria supera el umbral, intentar limpiar
        if ($memoryUsage > $config['memory_threshold']) {
            $this->cleanupMemory($operationId);
            return false;
        }

        return true;
    }

    /**
     * Obtiene el límite de memoria en bytes
     */
    private function getMemoryLimit(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;

        return match($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value
        };
    }

    /**
     * Limpia la memoria y recursos
     */
    public function cleanupMemory(string $operationId): void
    {
        $this->logger->info("Iniciando limpieza de memoria", [
            'operation_id' => $operationId,
            'memory_before' => \MiIntegracionApi\Core\MemoryManager::getMemoryStats()['current']
        ]);

        // Limpiar métricas antiguas
        $this->cleanupOldMetrics();

        // Forzar recolección de basura
        $collected = gc_collect_cycles();
        
        // Limpiar caché de OPcache si está disponible
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        // Limpiar caché de transients
        $this->cleanupTransients();

        $this->logger->info("Limpieza de memoria completada", [
            'operation_id' => $operationId,
            'memory_after' => \MiIntegracionApi\Core\MemoryManager::getMemoryStats()['current'],
            'cycles_collected' => $collected
        ]);

        $this->lastCleanupTime = time();
        $this->itemsSinceLastCleanup = 0;
    }

    /**
     * Limpia métricas antiguas
     */
    private function cleanupOldMetrics(): void
    {
        // ✅ CONFIGURACIÓN: Usar configuración para días de historial
        $config = $this->getConfig();
        // ✅ OPTIMIZACIÓN: Cálculo directo sin strtotime() 
        $cutoffTimestamp = $this->getCurrentTimestamp() - ($config['max_history_days'] * 86400); // 86400 = segundos por día
        $this->currentMetrics = array_filter(
            $this->currentMetrics,
            fn($metrics) => ($metrics['start_time'] ?? 0) >= $cutoffTimestamp
        );
    }

    /**
     * Limpia transients antiguos
     */
    private function cleanupTransients(): void
    {
        global $wpdb;
        
        // Verificar si $wpdb está disponible (entorno de prueba)
        if (!$wpdb || !method_exists($wpdb, 'query')) {
            return; // Salir silenciosamente en entorno de prueba
        }
        
        // ✅ CONFIGURACIÓN: Usar configuración para TTL
        $config = $this->getConfig();
        $cutoffTime = time() - $config['default_ttl'];
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE %s 
                AND option_name NOT LIKE %s 
                AND autoload = 'no'",
                $wpdb->esc_like('_transient_') . '%',
                $wpdb->esc_like('_transient_timeout_') . '%'
            )
        );
    }

    /**
     * Verifica si es necesario realizar limpieza
     */
    public function shouldCleanup(): bool
    {
        // ✅ CONFIGURACIÓN: Usar configuración para intervalo de limpieza
        $config = $this->getConfig();
        // ✅ ELIMINAR HARDCODEO: Usar configuración para intervalo de verificación
        return $this->itemsSinceLastCleanup >= $config['cleanup_interval'] ||
               (time() - $this->lastCleanupTime) >= $config['cleanup_check_interval'];
    }

    /**
     * Incrementa el contador de items y verifica limpieza
     */
    public function incrementItemCount(string $operationId): void
    {
        $this->itemsSinceLastCleanup++;

        if ($this->shouldCleanup()) {
            $this->cleanupMemory($operationId);
        }
        
        // ✅ MEMORY LEAK PREVENTION: Limpieza proactiva adicional
        $this->proactiveMemoryCleanup();
    }
    
    /**
     * ✅ MEMORY LEAK PREVENTION: Limpieza proactiva para prevenir acumulación
     */
    private function proactiveMemoryCleanup(): void
    {
        $config = $this->getConfig();
        
        // Verificar si tenemos demasiadas operaciones activas
        if (count($this->currentMetrics) > $config['max_operations_active']) {
            $this->logger->warning("Límite de operaciones excedido, ejecutando limpieza de emergencia", [
                'active_operations' => count($this->currentMetrics),
                'max_allowed' => $config['max_operations_active']
            ]);
            
            $this->cleanupOldestOperations($config['max_operations_active']);
        }
        
        // ✅ ELIMINAR HARDCODEO: Verificar operaciones muy antiguas usando configuración
        $cutoffTime = time() - $config['old_operation_threshold'];
        $operationsToCleanup = [];
        
        foreach ($this->startTimes as $operationId => $startTime) {
            if ($startTime < $cutoffTime) {
                $operationsToCleanup[] = $operationId;
            }
        }
        
        if (!empty($operationsToCleanup)) {
            $this->logger->info("Limpiando operaciones muy antiguas", [
                'operations_to_cleanup' => count($operationsToCleanup),
                'cutoff_hours' => 2
            ]);
            
            foreach ($operationsToCleanup as $operationId) {
                $this->saveMetrics($operationId);
                $this->cleanupOperation($operationId);
            }
        }
    }

    /**
     * Obtiene estadísticas de memoria
     */
    public function getMemoryStats(string $operationId): array
    {
        if (!isset($this->currentMetrics[$operationId])) {
            return [];
        }

        $memoryUsage = $this->currentMetrics[$operationId]['memory_usage'] ?? [];
        if (empty($memoryUsage)) {
            return [];
        }

        $latest = end($memoryUsage);
        
        // ✅ OPTIMIZACIÓN: Usar running totals O(1) en lugar de max(array_column()) O(n)
        $runningTotals = $this->currentMetrics[$operationId]['running_totals'] ?? [];
        $peak = $runningTotals['memory_peak'] ?? max(array_column($memoryUsage, 'peak')); // fallback

        return [
            'current' => $latest['current'],
            'peak' => $peak,
            'limit' => $latest['limit'],
            'usage_percentage' => $latest['usage_percentage'],
            'average' => $runningTotals['memory_count'] > 0 
                ? round($runningTotals['memory_sum'] / $runningTotals['memory_count'], 2) 
                : 0,
            'history' => $memoryUsage
        ];
    }

    /**
     * Finaliza una operación
     */
    public function endOperation(string $operationId): array
    {
        if (!isset($this->currentMetrics[$operationId])) {
            $this->logger->warning("Intento de finalizar operación no iniciada", [
                'operation_id' => $operationId
            ]);
            
            // Devolver métricas por defecto para evitar errores downstream
            return [
                'entity' => 'unknown',
                'direction' => 'unknown',
                'start_time' => date('Y-m-d H:i:s', strtotime('-1 minute')),
                'end_time' => date('Y-m-d H:i:s'),
                'status' => 'error',
                'items_processed' => 0,
                'items_succeeded' => 0,
                'items_failed' => 0,
                'duration' => 0,
                'errors' => [],
                'memory_usage' => []
            ];
        }

        $duration = (float) (microtime(true) - $this->startTimes[$operationId]);
        $memoryStats = \MiIntegracionApi\Core\MemoryManager::getMemoryStats();
        $memoryDiff = $memoryStats['current'] - $this->memorySnapshots[$operationId]['start'];

        $this->currentMetrics[$operationId]['end_time'] = $this->getCurrentTimestamp(); // ✅ UNIFICADO: Unix timestamp
        $this->currentMetrics[$operationId]['duration'] = (float) round($duration, 2);
        $this->currentMetrics[$operationId]['status'] = 'completed';
        $this->currentMetrics[$operationId]['memory_final'] = [
            'current' => $memoryStats['current'],
            'peak' => $memoryStats['peak'],
            'diff' => $memoryDiff
        ];

        $this->saveMetrics($operationId);
        
        // Creamos una estructura de métricas por defecto en caso de que no exista
        $defaultMetrics = [
            'entity' => 'unknown',
            'direction' => 'unknown',
            'start_time' => date('Y-m-d H:i:s', strtotime('-1 minute')),
            'end_time' => date('Y-m-d H:i:s'),
            'status' => 'completed',
            'items_processed' => 0,
            'items_succeeded' => 0,
            'items_failed' => 0,
            'duration' => 0,
            'errors' => [],
            'memory_usage' => []
        ];
        
        // Guardamos una copia de las métricas antes de limpiar
        $metrics = isset($this->currentMetrics[$operationId]) ? $this->currentMetrics[$operationId] : $defaultMetrics;
        $itemsProcessed = $metrics['items_processed'] ?? 0;
        $successRate = $this->calculateSuccessRate($operationId);
        
        $this->logger->info("Operación finalizada", [
            'operation_id' => $operationId,
            'duration' => $duration,
            'items_processed' => $itemsProcessed,
            'success_rate' => $successRate
        ]);
        
        // Limpiamos después de usar los datos
        $this->cleanupOperation($operationId);

        return $metrics;
    }

    /**
     * Calcula la tasa de éxito
     */
    private function calculateSuccessRate(string $operationId): float
    {
        if (!isset($this->currentMetrics[$operationId])) {
            return 0.0;
        }

        $total = $this->currentMetrics[$operationId]['items_processed'];
        if ($total === 0) {
            return 0.0;
        }

        return round(
            ($this->currentMetrics[$operationId]['items_succeeded'] / $total) * 100,
            2
        );
    }

    /**
     * Obtiene métricas de una operación
     */
    public function getOperationMetrics(string $operationId): ?array
    {
        // ✅ CONFIGURACIÓN: Usar configuración para option name
        $config = $this->getConfig();
        $allMetrics = get_option($config['metrics_option'], []);
        return $allMetrics[$operationId] ?? null;
    }

    /**
     * Obtiene métricas resumidas
     */
    public function getSummaryMetrics(int $days = 7): array
    {
        // ✅ CONFIGURACIÓN: Usar configuración para option name
        $config = $this->getConfig();
        $allMetrics = get_option($config['metrics_option'], []);
        // ✅ ELIMINAR DUPLICIDAD: Cálculo directo de timestamp sin strtotime()
        $cutoffTimestamp = $this->getCurrentTimestamp() - ($days * 86400); // 86400 = segundos por día
        
        $summary = [
            'total_operations' => 0,
            'total_items' => 0,
            'success_rate' => 0,
            'avg_duration' => 0,
            'avg_memory_usage' => 0,
            'error_count' => 0,
            'by_entity' => []
        ];

        foreach ($allMetrics as $operation) {
            // ✅ OPTIMIZACIÓN: Comparación directa de timestamps
            $operationStartTime = is_numeric($operation['start_time']) 
                ? $operation['start_time'] 
                : $this->parseTimestamp($operation['start_time']);
            
            if ($operationStartTime < $cutoffTimestamp) {
                continue;
            }

            $summary['total_operations']++;
            $summary['total_items'] += $operation['items_processed'];
            $summary['error_count'] += count($operation['errors']);
            
            $entity = $operation['entity'];
            if (!isset($summary['by_entity'][$entity])) {
                $summary['by_entity'][$entity] = [
                    'total_operations' => 0,
                    'total_items' => 0,
                    'success_rate' => 0
                ];
            }
            
            $summary['by_entity'][$entity]['total_operations']++;
            $summary['by_entity'][$entity]['total_items'] += $operation['items_processed'];
        }

        // ✅ OPTIMIZACIÓN: Calcular promedios usando running totals acumulados durante el bucle
        $totalDuration = 0;
        $totalMemoryPeak = 0;
        $operationsWithDuration = 0;
        $operationsWithMemory = 0;
        
        // Re-iterar para calcular totales (más eficiente que array_sum/array_map)
        foreach ($allMetrics as $operation) {
            // ✅ OPTIMIZACIÓN: Reutilizar cálculo de timestamp anterior  
            $operationStartTime = is_numeric($operation['start_time']) 
                ? $operation['start_time'] 
                : $this->parseTimestamp($operation['start_time']);
            
            if ($operationStartTime < $cutoffTimestamp) {
                continue;
            }
            
            // Duración
            if (isset($operation['duration']) && $operation['duration'] > 0) {
                $totalDuration += $operation['duration'];
                $operationsWithDuration++;
            }
            
            // Memoria peak
            $memoryPeak = $operation['memory_final']['peak'] ?? 0;
            if ($memoryPeak > 0) {
                $totalMemoryPeak += $memoryPeak;
                $operationsWithMemory++;
            }
        }
        
        // Calcular promedios
        if ($operationsWithDuration > 0) {
            $summary['avg_duration'] = round($totalDuration / $operationsWithDuration, 2);
        }
        
        if ($operationsWithMemory > 0) {
            $summary['avg_memory_usage'] = round($totalMemoryPeak / $operationsWithMemory, 2);
        }

        return $summary;
    }

    /**
     * Guarda las métricas en la base de datos
     */
    private function saveMetrics(string $operationId): void
    {
        // Verificar que operationId existe en currentMetrics
        if (!isset($this->currentMetrics[$operationId])) {
            $this->logger->warning("Intento de guardar métricas para una operación no iniciada", [
                'operation_id' => $operationId
            ]);
            return;
        }
        
        // Asegurar que la tabla de métricas existe
        $this->ensureMetricsTableExists();
        
        // Preparar datos para la tabla
        $metrics = $this->currentMetrics[$operationId];
        $totalProcessed = $metrics['total']['processed'] ?? 0;
        $totalErrors = $metrics['total']['errors'] ?? 0;
        $totalRetryProcessed = $metrics['total']['retry_processed'] ?? 0;
        $totalRetryErrors = $metrics['total']['retry_errors'] ?? 0;
        $totalDuration = $metrics['total']['duration'] ?? 0;
        
        // ✅ OPTIMIZACIÓN: Usar running totals O(1) en lugar de array_sum/max O(n)
        $runningTotals = $metrics['running_totals'] ?? [];
        $avgMemory = 0;
        $peakMemory = 0;
        
        if (!empty($runningTotals) && $runningTotals['memory_count'] > 0) {
            // Usar running totals optimizados
            $avgMemory = $runningTotals['memory_sum'] / $runningTotals['memory_count'];
            $peakMemory = $runningTotals['memory_peak'];
        } else {
            // Fallback a cálculo O(n) si no hay running totals
            $memoryUsage = $metrics['memory_usage'] ?? [];
            if (!empty($memoryUsage)) {
                $avgMemory = array_sum(array_column($memoryUsage, 'current')) / count($memoryUsage);
                $peakMemory = max(array_column($memoryUsage, 'peak'));
            }
        }
        
        // Insertar en la tabla de métricas
        global $wpdb;
        $table_name = $wpdb->prefix . 'mia_sync_metrics';
        
        $wpdb->insert(
            $table_name,
            [
                'sync_type' => $metrics['entity'] ?? 'unknown',
                'total_items' => $totalProcessed + $totalErrors,
                'processed_items' => $totalProcessed,
                'successful_items' => $totalProcessed - $totalErrors,
                'error_items' => $totalErrors,
                'processing_time' => $totalDuration,
                'memory_used' => $avgMemory,
                'memory_peak' => $peakMemory,
                'created_at' => current_time('mysql')
            ],
            ['%s', '%d', '%d', '%d', '%d', '%f', '%f', '%f', '%s']
        );
        
        // ✅ CONFIGURACIÓN: También mantener compatibilidad con opciones para operaciones en curso
        $config = $this->getConfig();
        $allMetrics = get_option($config['metrics_option'], []);
        $allMetrics[$operationId] = $this->currentMetrics[$operationId];
        
        // ✅ ELIMINAR DUPLICIDAD: Limpiar métricas antiguas usando configuración optimizada
        $cutoffTimestamp = $this->getCurrentTimestamp() - ($config['max_history_days'] * 86400);
        $allMetrics = array_filter($allMetrics, function($metrics) use ($cutoffTimestamp) {
            $startTime = is_numeric($metrics['start_time']) 
                ? $metrics['start_time'] 
                : (isset($metrics['start_time']) ? strtotime($metrics['start_time']) : 0);
            return $startTime >= $cutoffTimestamp;
        });

        update_option($config['metrics_option'], $allMetrics, true);
        
        // Métricas guardadas silenciosamente
    }
    
    /**
     * Asegura que la tabla de métricas existe
     */
    private function ensureMetricsTableExists(): void
    {
        if (class_exists('MiIntegracionApi\\Core\\Installer')) {
            \MiIntegracionApi\Core\Installer::create_sync_metrics_table();
        }
    }

    /**
     * Limpia los datos de una operación
     */
    private function cleanupOperation(string $operationId): void
    {
        unset($this->startTimes[$operationId]);
        unset($this->memorySnapshots[$operationId]);
        unset($this->currentMetrics[$operationId]);
    }
    
    /**
     * ✅ MEMORY LEAK PREVENTION: Limpia las operaciones más antiguas para liberar memoria
     */
    private function cleanupOldestOperations(int $maxToKeep): void
    {
        if (count($this->currentMetrics) <= $maxToKeep) {
            return; // No hay nada que limpiar
        }
        
        // Ordenar operaciones por timestamp de inicio (más antiguas primero)
        $operationsByAge = [];
        foreach ($this->currentMetrics as $operationId => $metrics) {
            $startTime = $this->startTimes[$operationId] ?? time();
            $operationsByAge[$operationId] = $startTime;
        }
        
        // Ordenar por timestamp (más antiguas primero)
        asort($operationsByAge);
        
        // Mantener solo las más recientes
        $operationsToRemove = array_slice(array_keys($operationsByAge), 0, -$maxToKeep, true);
        
        // ✅ OPTIMIZADO: Log agregado en lugar de individual para reducir verbosity
        if (!empty($operationsToRemove)) {
            $this->logger->debug("Limpiando operaciones antiguas para liberar memoria", [
                'operations_count' => count($operationsToRemove),
                'oldest_operation' => reset($operationsToRemove),
                'newest_operation' => end($operationsToRemove)
            ]);
        }
        
        foreach ($operationsToRemove as $operationId) {
            // Guardar métricas antes de limpiar
            $this->saveMetrics($operationId);
            
            // Limpiar de memoria
            $this->cleanupOperation($operationId);
        }
        
        $this->logger->info("Limpieza de operaciones antiguas completada", [
            'operations_removed' => count($operationsToRemove),
            'operations_remaining' => count($this->currentMetrics),
            'max_to_keep' => $maxToKeep
        ]);
    }
    
    /**
     * ✅ MEMORY LEAK DETECTION: Monitorea y reporta el estado de memoria
     */
    public function getMemoryLeakReport(): array
    {
        $config = $this->getConfig();
        $totalOperations = count($this->currentMetrics);
        $totalErrors = 0;
        $totalMemorySnapshots = 0;
        $oldestOperation = null;
        $oldestTime = time();
        
        foreach ($this->currentMetrics as $operationId => $metrics) {
            $totalErrors += count($metrics['errors'] ?? []);
            $totalMemorySnapshots += count($metrics['memory_usage'] ?? []);
            
            $startTime = $this->startTimes[$operationId] ?? time();
            if ($startTime < $oldestTime) {
                $oldestTime = $startTime;
                $oldestOperation = $operationId;
            }
        }
        
        $memoryStats = \MiIntegracionApi\Core\MemoryManager::getMemoryStats();
        $estimatedMemoryPerOp = $totalOperations > 0 ? $memoryStats['current'] / $totalOperations : 0;
        
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'active_operations' => $totalOperations,
            'max_operations_allowed' => $config['max_operations_active'],
            'total_errors_stored' => $totalErrors,
            'max_errors_per_operation' => $config['max_errors_per_operation'],
            'total_memory_snapshots' => $totalMemorySnapshots,
            'max_snapshots_per_operation' => $config['max_memory_snapshots'],
            'current_memory_usage' => $memoryStats['current'],
            'estimated_memory_per_operation' => $estimatedMemoryPerOp,
            'oldest_operation' => $oldestOperation,
            'oldest_operation_age_seconds' => $oldestOperation ? (time() - $oldestTime) : 0,
            'potential_issues' => []
        ];
        
        // ✅ ELIMINAR HARDCODEOS: Detectar posibles problemas usando configuración
        if ($totalOperations >= $config['max_operations_active'] * $config['operations_warning_threshold']) {
            $report['potential_issues'][] = 'Acercándose al límite de operaciones activas';
        }
        
        if ($report['oldest_operation_age_seconds'] > $config['old_operation_warning_threshold']) {
            $thresholdHours = round($config['old_operation_warning_threshold'] / 3600, 1);
            $report['potential_issues'][] = "Operación muy antigua detectada (>{$thresholdHours} horas)";
        }
        
        if ($estimatedMemoryPerOp > $config['memory_per_operation_threshold']) {
            $thresholdMB = round($config['memory_per_operation_threshold'] / 1048576, 1);
            $report['potential_issues'][] = "Alto uso de memoria por operación (>{$thresholdMB}MB)";
        }
        
        return $report;
    }
    
    /**
     * ✅ PERFORMANCE REPORT: Genera reporte de optimizaciones de algoritmos
     */
    public function getPerformanceOptimizationReport(): array
    {
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'optimizations_active' => [],
            'performance_improvements' => [],
            'algorithm_complexity' => []
        ];
        
        $totalOperations = count($this->currentMetrics);
        $optimizedOperations = 0;
        $unoptimizedOperations = 0;
        
        foreach ($this->currentMetrics as $operationId => $metrics) {
            if (isset($metrics['running_totals'])) {
                $optimizedOperations++;
            } else {
                $unoptimizedOperations++;
            }
        }
        
        // Calcular mejoras de rendimiento
        $report['optimizations_active'] = [
            'running_totals_enabled' => true,
            'memory_calculations' => 'O(1) instead of O(n)',
            'duration_calculations' => 'O(1) instead of O(n)',
            'peak_memory_tracking' => 'O(1) instead of O(n)'
        ];
        
        $report['performance_improvements'] = [
            'operations_with_optimization' => $optimizedOperations,
            'operations_without_optimization' => $unoptimizedOperations,
            'optimization_coverage' => $totalOperations > 0 
                ? round(($optimizedOperations / $totalOperations) * 100, 2) . '%'
                : '0%'
        ];
        
        $report['algorithm_complexity'] = [
            'before_optimization' => [
                'getMemoryStats' => 'O(n) - max(array_column())',
                'saveMetrics' => 'O(n) - array_sum(array_column())',
                'getSummaryMetrics' => 'O(n²) - multiple array operations'
            ],
            'after_optimization' => [
                'getMemoryStats' => 'O(1) - direct access to running_totals',
                'saveMetrics' => 'O(1) - direct access to running_totals',
                'getSummaryMetrics' => 'O(n) - single pass accumulation'
            ]
        ];
        
        return $report;
    }

    /**
     * Carga las métricas existentes
     */
    private function loadMetrics(): void
    {
        // ✅ CONFIGURACIÓN: Usar configuración para option name
        $config = $this->getConfig();
        $metrics = get_option($config['metrics_option'], []);
        $this->currentMetrics = is_array($metrics) ? $metrics : [];
    }

    /**
     * Registra un error con su tipo y contexto
     */
    public function recordError(
        string $operationId,
        string $errorType,
        array  $message,
        array  $context = [],
        ?int   $code = null
    ): void {
        // ✅ ROBUSTEZ: Auto-inicializar operación si no existe para evitar pérdida de datos
        if (!isset($this->currentMetrics[$operationId])) {
            $this->logger->warning("Auto-inicializando operación para registrar error", [
                'operation_id' => $operationId,
                'error_type' => $errorType,
                'message' => $message
            ]);
            
            // Auto-inicializar con valores por defecto
            $this->startOperation($operationId, 'auto-created', 'unknown');
        }

        // ✅ ELIMINAR DUPLICIDAD: Una sola llamada a getCurrentTimestamp()
        $currentTime = $this->getCurrentTimestamp();
        $error = [
            'time' => $currentTime, // ✅ UNIFICADO: Unix timestamp  
            'time_formatted' => $this->formatTimestamp($currentTime), // Para display
            'type' => $errorType,
            'message' => $message,
            'code' => $code,
            'context' => $context
        ];

        // ✅ MEMORY LEAK PREVENTION: Limitar errores por operación
        $config = $this->getConfig();
        $maxErrors = $config['max_errors_per_operation'];
        
        if (count($this->currentMetrics[$operationId]['errors']) >= $maxErrors) {
            // Remover el error más antiguo para hacer espacio
            array_shift($this->currentMetrics[$operationId]['errors']);
            
            $this->logger->warning("Límite de errores alcanzado, eliminando error más antiguo", [
                'operation_id' => $operationId,
                'max_errors' => $maxErrors,
                'current_errors' => count($this->currentMetrics[$operationId]['errors'])
            ]);
        }
        
        $this->currentMetrics[$operationId]['errors'][] = $error;
        $this->currentMetrics[$operationId]['error_types'][$errorType] = 
            ($this->currentMetrics[$operationId]['error_types'][$errorType] ?? 0) + 1;

        $this->logger->error("Error registrado en operación", [
            'operation_id' => $operationId,
            'error_type' => $errorType,
            'message' => $message,
            'code' => $code
        ]);
    }

    /**
     * Obtiene estadísticas de errores
     */
    public function getErrorStats(string $operationId): array
    {
        if (!isset($this->currentMetrics[$operationId])) {
            return [];
        }

        $metrics = $this->currentMetrics[$operationId];
        $totalErrors = count($metrics['errors']);
        $errorTypes = $metrics['error_types'] ?? [];

        return [
            'total_errors' => $totalErrors,
            'error_types' => $errorTypes,
            'error_distribution' => $totalErrors > 0 
                ? array_map(
                    fn($count) => round(($count / $totalErrors) * 100, 2),
                    $errorTypes
                )
                : [],
            'errors' => $metrics['errors']
        ];
    }

    /**
     * Obtiene estadísticas de errores por tipo
     */
    public function getErrorTypeStats(int $days = 7): array
    {
        // ✅ CONFIGURACIÓN: Usar configuración para option name
        $config = $this->getConfig();
        $allMetrics = get_option($config['metrics_option'], []);
        // ✅ ELIMINAR DUPLICIDAD: Cálculo directo sin strtotime()
        $cutoffTimestamp = $this->getCurrentTimestamp() - ($days * 86400);
        
        $stats = [
            'total_errors' => 0,
            'by_type' => [],
            'by_entity' => [],
            'trend' => []
        ];

        foreach ($allMetrics as $operation) {
            // ✅ OPTIMIZACIÓN: Comparación directa de timestamps
            $operationStartTime = is_numeric($operation['start_time']) 
                ? $operation['start_time'] 
                : $this->parseTimestamp($operation['start_time']);
            
            if ($operationStartTime < $cutoffTimestamp) {
                continue;
            }

            $entity = $operation['entity'];
            $errorTypes = $operation['error_types'] ?? [];

            foreach ($errorTypes as $type => $count) {
                // Estadísticas por tipo
                $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + $count;
                
                // Estadísticas por entidad
                if (!isset($stats['by_entity'][$entity])) {
                    $stats['by_entity'][$entity] = [
                        'total' => 0,
                        'by_type' => []
                    ];
                }
                $stats['by_entity'][$entity]['total'] += $count;
                $stats['by_entity'][$entity]['by_type'][$type] = 
                    ($stats['by_entity'][$entity]['by_type'][$type] ?? 0) + $count;

                // ✅ OPTIMIZACIÓN: Tendencia temporal usando timestamp optimizado
                $date = date('Y-m-d', $operationStartTime);
                if (!isset($stats['trend'][$date])) {
                    $stats['trend'][$date] = [
                        'total' => 0,
                        'by_type' => []
                    ];
                }
                $stats['trend'][$date]['total'] += $count;
                $stats['trend'][$date]['by_type'][$type] = 
                    ($stats['trend'][$date]['by_type'][$type] ?? 0) + $count;
            }

            $stats['total_errors'] += array_sum($errorTypes);
        }

        return $stats;
    }

    /**
     * Registra un error de validación
     */
    public function recordValidationError(
        string $operationId,
        string $message,
        array $context = [],
        ?int $code = null
    ): void {
        $this->recordError(
            $operationId,
            self::ERROR_TYPE_VALIDATION,
            $message,
            $context,
            $code
        );
    }

    /**
     * Registra un error de API
     */
    public function recordApiError(
        string $operationId,
        string $message,
        array $context = [],
        ?int $code = null
    ): void {
        $this->recordError(
            $operationId,
            self::ERROR_TYPE_API,
            $message,
            $context,
            $code
        );
    }

    /**
     * Registra un error de concurrencia
     */
    public function recordConcurrencyError(
        string $operationId,
        string $message,
        array $context = [],
        ?int $code = null
    ): void {
        $this->recordError(
            $operationId,
            self::ERROR_TYPE_CONCURRENCY,
            $message,
            $context,
            $code
        );
    }

    /**
     * Registra un error de memoria
     */
    public function recordMemoryError(
        string $operationId,
        string $message,
        array $context = [],
        ?int $code = null
    ): void {
        $this->recordError(
            $operationId,
            self::ERROR_TYPE_MEMORY,
            $message,
            $context,
            $code
        );
    }

    /**
     * Registra un error de red
     */
    public function recordNetworkError(
        string $operationId,
        string $message,
        array $context = [],
        ?int $code = null
    ): void {
        $this->recordError(
            $operationId,
            self::ERROR_TYPE_NETWORK,
            $message,
            $context,
            $code
        );
    }

    /**
     * Registra un error de timeout
     */
    public function recordTimeoutError(
        string $operationId,
        string $message,
        array $context = [],
        ?int $code = null
    ): void {
        $this->recordError(
            $operationId,
            self::ERROR_TYPE_TIMEOUT,
            $message,
            $context,
            $code
        );
    }

    /**
     * Obtiene métricas de rendimiento de la sincronización actual o más reciente.
     * Método movido desde Sync_Manager.php para centralizar métricas
     *
     * @param string|null $run_id ID específico de ejecución (opcional)
     * @param array $sync_status Estado de sincronización
     * @param array $sync_history Historial de sincronizaciones
     * @return array Métricas de rendimiento
     */
    public static function getSyncPerformanceMetrics(?string $run_id = null, array $sync_status = [], array $sync_history = []): array {
        // Recopilar métricas del estado actual o historial
        $current_metrics = [];
        
        // Si hay una sincronización en progreso, usar sus métricas
        if (!empty($sync_status['current_sync']['in_progress'])) {
            $sync_data = $sync_status['current_sync'];
            
            // Si se especificó un run_id diferente, buscarlo en el historial
            if ($run_id && $run_id !== $sync_data['run_id']) {
                foreach ($sync_history as $entry) {
                    if (isset($entry['run_id']) && $entry['run_id'] === $run_id) {
                        $sync_data = $entry;
                        break;
                    }
                }
            }
            
            // Calcular duración hasta ahora
            $duration = time() - $sync_data['start_time'];
            $items_per_second = $duration > 0 ? $sync_data['items_synced'] / $duration : 0;
            $estimated_total_time = $items_per_second > 0 ? $sync_data['total_items'] / $items_per_second : 0;
            $estimated_remaining = $estimated_total_time - $duration;
            
            $current_metrics = [
                'run_id' => $sync_data['run_id'] ?? null,
                'entity' => $sync_data['entity'] ?? '',
                'direction' => $sync_data['direction'] ?? '',
                'batch_size' => $sync_data['batch_size'] ?? 0,
                'items_synced' => $sync_data['items_synced'] ?? 0,
                'total_items' => $sync_data['total_items'] ?? 0,
                'current_batch' => $sync_data['current_batch'] ?? 0,
                'total_batches' => $sync_data['total_batches'] ?? 0,
                'errors' => $sync_data['errors'] ?? 0,
                'duration_seconds' => $duration,
                'duration_formatted' => sprintf(
                    '%02d:%02d:%02d',
                    floor($duration / 3600),
                    floor(($duration % 3600) / 60),
                    $duration % 60
                ),
                'items_per_second' => round($items_per_second, 2),
                'items_per_minute' => round($items_per_second * 60, 2),
                'estimated_total_time' => $estimated_total_time,
                'estimated_total_formatted' => sprintf(
                    '%02d:%02d:%02d',
                    floor($estimated_total_time / 3600),
                    floor(($estimated_total_time % 3600) / 60),
                    $estimated_total_time % 60
                ),
                'estimated_remaining' => $estimated_remaining,
                'estimated_remaining_formatted' => sprintf(
                    '%02d:%02d:%02d',
                    floor($estimated_remaining / 3600),
                    floor(($estimated_remaining % 3600) / 60),
                    $estimated_remaining % 60
                ),
                'percent_complete' => $sync_data['total_items'] > 0
                    ? round(($sync_data['items_synced'] / $sync_data['total_items']) * 100, 2)
                    : 0,
                'in_progress' => $sync_status['current_sync']['in_progress'],
                'error_rate' => $sync_data['items_synced'] > 0
                    ? round(($sync_data['errors'] / $sync_data['items_synced']) * 100, 2)
                    : 0,
            ];
        } else {
            // No hay sincronización activa, buscar en el historial
            $found_entry = null;
            
            if ($run_id) {
                // Buscar entrada específica por run_id
                foreach ($sync_history as $entry) {
                    if (isset($entry['run_id']) && $entry['run_id'] === $run_id) {
                        $found_entry = $entry;
                        break;
                    }
                }
            } else if (!empty($sync_history)) {
                // Usar la entrada más reciente
                $found_entry = $sync_history[count($sync_history) - 1];
            }
            
            if ($found_entry) {
                $duration = $found_entry['duration'] ?? ($found_entry['end_time'] - $found_entry['start_time']);
                $items_per_second = $duration > 0 ? $found_entry['items_synced'] / $duration : 0;
                
                $current_metrics = [
                    'run_id' => $found_entry['run_id'] ?? null,
                    'entity' => $found_entry['entity'] ?? '',
                    'direction' => $found_entry['run_id'] ?? '',
                    'batch_size' => $found_entry['batch_size'] ?? 0,
                    'items_synced' => $found_entry['items_synced'] ?? 0,
                    'total_items' => $found_entry['total_items'] ?? 0,
                    'errors' => $found_entry['errors'] ?? 0,
                    'duration_seconds' => $duration,
                    'duration_formatted' => sprintf(
                        '%02d:%02d:%02d',
                        floor($duration / 3600),
                        floor(($duration % 3600) / 60),
                        $duration % 60
                    ),
                    'items_per_second' => round($items_per_second, 2),
                    'items_per_minute' => round($items_per_second * 60, 2),
                    'percent_complete' => $found_entry['total_items'] > 0
                        ? round(($found_entry['items_synced'] / $found_entry['total_items']) * 100, 2)
                        : 0,
                    'in_progress' => false,
                    'completed' => true,
                    'status' => $found_entry['status'] ?? 'completed',
                    'error_rate' => $found_entry['items_synced'] > 0
                        ? round(($found_entry['errors'] / $found_entry['items_synced']) * 100, 2)
                        : 0,
                ];
            } else {
                $current_metrics = [
                    'in_progress' => false,
                    'completed' => false,
                    'message' => __('No se encontraron datos de sincronización.', 'mi-integracion-api')
                ];
            }
        }
        
        return $current_metrics;
    }

    /**
     * Marca un lote como completado y registra sus métricas de rendimiento
     * 
     * @param int $offset Offset del lote
     * @param int $limit Tamaño del lote
     * @param float|null $batch_start_time Tiempo de inicio del lote (opcional)
     * @return void
     */
    public static function markBatchCompleted(int $offset, int $limit, ?float $batch_start_time = null): void
    {
        $logger = self::getStaticLogger();
        
        // Obtener lotes completados existentes
        $completed_batches = \mia_get_sync_transient('mia_sync_completed_batches') ?: [];
        $batch_key = $offset . '-' . ($offset + $limit);
        $completed_batches[$batch_key] = time();
        
        // Registrar tiempo de procesamiento si se proporciona
        if ($batch_start_time !== null) {
            $batch_duration = microtime(true) - $batch_start_time;
            
            // Obtener historial de tiempos existente
            $batch_times = \mia_get_sync_transient('mia_sync_batch_times') ?: [];
            $batch_times[$batch_key] = [
                'start' => $batch_start_time,
                'end' => microtime(true),
                'duration' => $batch_duration,
                'offset' => $offset,
                'limit' => $limit,
                'items' => $limit,
                'timestamp' => time()
            ];
            
            // Limitar el tamaño del historial para evitar transients demasiado grandes
            $max_batch_times = 1000; // Límite razonable
            if (count($batch_times) > $max_batch_times) {
                // Mantener solo los más recientes
                $batch_times = array_slice($batch_times, -$max_batch_times, null, true);
            }
            
            // Guardar historial de tiempos
            \mia_set_sync_transient('mia_sync_batch_times', $batch_times, 24 * HOUR_IN_SECONDS);
            
            $logger->debug('Lote completado con métricas de tiempo', [
                'batch_key' => $batch_key,
                'duration' => $batch_duration,
                'offset' => $offset,
                'limit' => $limit
            ]);
        }
        
        // Limitar el tamaño de lotes completados
        $max_completed_batches = 500; // Límite razonable
        if (count($completed_batches) > $max_completed_batches) {
            // Mantener solo los más recientes
            $completed_batches = array_slice($completed_batches, -$max_completed_batches, null, true);
        }
        
        // Guardar lotes completados
        \mia_set_sync_transient('mia_sync_completed_batches', $completed_batches, 24 * HOUR_IN_SECONDS);
        
        $logger->info('Lote marcado como completado', [
            'batch_key' => $batch_key,
            'offset' => $offset,
            'limit' => $limit,
            'total_completed' => count($completed_batches)
        ]);
    }

    /**
     * Obtiene estadísticas de errores de sincronización.
     * Método movido desde Sync_Manager.php para centralizar métricas
     *
     * @param string|null $run_id ID específico de ejecución (opcional)
     * @param int $limit Límite de resultados por tipo de error
     * @return array Estadísticas de errores
     */
    public static function getSyncErrorStats(?string $run_id = null, int $limit = 10): array {
        global $wpdb;
        
        // Verificar si la tabla existe
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}mia_sync_errors'")) {
            return [
                'total_errors' => 0,
                'error_distribution' => [],
                'recent_errors' => [],
                'problem_skus' => [],
                'run_id' => $run_id,
                'generated_at' => current_time('mysql'),
                'error' => 'Tabla de errores no encontrada'
            ];
        }

        $table_name = $wpdb->prefix . 'mia_sync_errors';

        // Base de la consulta
        $sql_base = "FROM {$table_name}";
        $where = [];
        $params = [];

        // Filtrar por run_id si se proporciona
        if ($run_id) {
            $where[] = "sync_run_id = %s";
            $params[] = $run_id;
        }

        // Construir cláusula WHERE
        $where_clause = '';
        if (!empty($where)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where);
        }

        // Obtener recuento total de errores
        $total_errors = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) {$sql_base} {$where_clause}",
                $params
            )
        );

        // Obtener distribución por código de error
        $error_distribution = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT error_code, COUNT(*) as count
                {$sql_base}
                {$where_clause}
                GROUP BY error_code
                ORDER BY count DESC
                LIMIT %d",
                array_merge($params, [$limit])
            ),
            ARRAY_A
        );

        // Obtener errores más recientes
        $recent_errors = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, sync_run_id, item_sku, error_code, error_message, timestamp
                {$sql_base}
                {$where_clause}
                ORDER BY timestamp DESC
                LIMIT %d",
                array_merge($params, [$limit])
            ),
            ARRAY_A
        );

        // Obtener SKUs con más errores
        $problem_skus = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT item_sku, COUNT(*) as error_count
                {$sql_base}
                {$where_clause}
                GROUP BY item_sku
                ORDER BY error_count DESC
                LIMIT %d",
                array_merge($params, [$limit])
            ),
            ARRAY_A
        );

        return [
            'total_errors' => (int) $total_errors,
            'error_distribution' => $error_distribution,
            'recent_errors' => $recent_errors,
            'problem_skus' => $problem_skus,
            'run_id' => $run_id,
            'generated_at' => current_time('mysql')
        ];
    }

    	/**
	 * NUEVO: Registra métricas de compresión
	 * 
	 * @param int $originalSize Tamaño original en bytes
	 * @param array $compressionResult Resultado de la compresión
	 * @return void
	 */
	public function recordCompressionMetrics(int $originalSize, array $compressionResult): void
	{
		$history = get_option('mia_compression_metrics', []);
		
		$historyEntry = [
			'timestamp' => time(),
			'algorithm' => $compressionResult['algorithm'],
			'original_size_bytes' => $originalSize,
			'original_size_mb' => $this->safe_round($originalSize / (1024 * 1024), 2),
			'compression_ratio' => $compressionResult['compression_ratio'],
			'space_saved_mb' => $compressionResult['space_saved_mb'],
			'compression_time' => $this->safe_round($compressionResult['compression_time'], 4)
		];
		
		$history[] = $historyEntry;
		
		// Mantener solo los últimos 100 registros
		if (count($history) > 100) {
			$history = array_slice($history, -100);
		}
		
		update_option('mia_compression_metrics', $history);
	}	

    	/**
	 * NUEVO: Registra métricas de fragmentación
	 * 
	 * @param int $originalSize Tamaño original en bytes
	 * @param array $fragmentationResult Resultado de la fragmentación
	 * @return void
	 */
	public function recordFragmentationMetrics(int $originalSize, array $fragmentationResult): void
	{
		$history = get_option('mia_fragmentation_metrics', []);
		
		$historyEntry = [
			'timestamp' => time(),
			'original_size_bytes' => $originalSize,
			'original_size_mb' => $this->safe_round($originalSize / (1024 * 1024), 2),
			'fragments_created' => $fragmentationResult['chunks_created'],
			'total_fragments_size_bytes' => $fragmentationResult['total_size_bytes'],
			'efficiency_score' => $fragmentationResult['efficiency_score'],
			'fragmentation_type' => $fragmentationResult['fragmentation_type']
		];
		
		$history[] = $historyEntry;
		
		// Mantener solo los últimos 100 registros
		if (count($history) > 100) {
			$history = array_slice($history, -100);
		}
		
		update_option('mia_fragmentation_metrics', $history);
	}

    	/**
	 * NUEVO: Registra historial de migraciones
	 * 
	 * @param string $cacheKey Clave del transient
	 * @param int $originalSize Tamaño original en bytes
	 * @param string $storageType Tipo de almacenamiento
	 * @return void
	 */
	public function recordMigrationHistory(string $cacheKey, int $originalSize, string $storageType): void
	{
		$history = get_option('mia_migration_history', []);
		
		$historyEntry = [
			'timestamp' => time(),
			'cache_key' => $cacheKey,
			'original_size_bytes' => $originalSize,
			'original_size_mb' => $this->safe_round($originalSize / (1024 * 1024), 2),
			'storage_type' => $storageType,
			'migration_type' => 'to_database'
		];
		
		$history[] = $historyEntry;
		
		// Mantener solo los últimos 100 registros
		if (count($history) > 100) {
			$history = array_slice($history, -100);
		}
		
		update_option('mia_migration_history', $history);
	}

	/**
	 * NUEVO: Registra entrada en el historial de sincronización
	 * Reemplaza add_to_history de Sync_Manager
	 * 
	 * @param array $syncData Datos de la sincronización
	 * @return void
	 */
	public function addSyncHistory(array $syncData): void
	{
		$config = $this->getConfig();
		$history = get_option($config['metrics_option'] . '_history', []);
		
		// Validar datos requeridos
		$requiredFields = ['entity', 'direction', 'operation_id', 'start_time', 'end_time', 'status'];
		foreach ($requiredFields as $field) {
			if (!isset($syncData[$field])) {
				$this->logger->warning("Campo requerido faltante en historial de sincronización", [
					'field' => $field,
					'available_fields' => array_keys($syncData)
				]);
				return;
			}
		}
		
		// Preparar entrada de historial
		$historyEntry = [
			'timestamp' => time(),
			'entity' => $syncData['entity'],
			'direction' => $syncData['direction'],
			'operation_id' => $syncData['operation_id'],
			'start_time' => $syncData['start_time'],
			'end_time' => $syncData['end_time'],
			'status' => $syncData['status'],
			'items_synced' => $syncData['items_synced'] ?? 0,
			'total_items' => $syncData['total_items'] ?? 0,
			'errors' => $syncData['errors'] ?? 0,
			'duration' => $syncData['end_time'] - $syncData['start_time'],
			'metrics' => $syncData['metrics'] ?? [],
			'created_at' => current_time('mysql')
		];
		
		$history[] = $historyEntry;
		
		// Limitar el tamaño del historial a 100 registros
		if (count($history) > 100) {
			$history = array_slice($history, -100);
		}
		
		update_option($config['metrics_option'] . '_history', $history, true);
		
		$this->logger->info("Entrada agregada al historial de sincronización", [
			'entity' => $syncData['entity'],
			'direction' => $syncData['direction'],
			'operation_id' => $syncData['operation_id'],
			'status' => $syncData['status']
		]);
	}

	/**
	 * NUEVO: Obtiene el historial de sincronización
	 * Reemplaza get_sync_history de Sync_Manager
	 * 
	 * @param int $limit Número máximo de registros a devolver
	 * @return array Historial de sincronización
	 */
	public function getSyncHistory(int $limit = 100): array
	{
		$config = $this->getConfig();
		$history = get_option($config['metrics_option'] . '_history', []);
		
		// Validar límite
		$limit = max(1, min(1000, $limit));
		
		// Ordenar por timestamp descendente (más recientes primero)
		usort($history, function($a, $b) {
			return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
		});
		
		// Aplicar límite
		$history = array_slice($history, 0, $limit);
		
		// Formatear timestamps para display
		foreach ($history as &$entry) {
			if (isset($entry['timestamp'])) {
				$entry['timestamp_formatted'] = $this->formatTimestamp($entry['timestamp']);
			}
			if (isset($entry['start_time'])) {
				$entry['start_time_formatted'] = $this->formatTimestamp($entry['start_time']);
			}
			if (isset($entry['end_time'])) {
				$entry['end_time_formatted'] = $this->formatTimestamp($entry['end_time']);
			}
		}
		
		$this->logger->debug("Historial de sincronización obtenido", [
			'total_entries' => count($history),
			'limit' => $limit
		]);
		
		return $history;
	}

	/**
	 * NUEVO: Obtiene el historial de sincronización con filtros
	 * 
	 * @param array $filters Filtros a aplicar
	 * @param int $limit Número máximo de registros
	 * @return array Historial filtrado
	 */
	public function getSyncHistoryFiltered(array $filters = [], int $limit = 100): array
	{
		$history = $this->getSyncHistory(1000); // Obtener más registros para filtrar
		
		// Aplicar filtros
		if (!empty($filters['entity'])) {
			$history = array_filter($history, function($entry) use ($filters) {
				return $entry['entity'] === $filters['entity'];
			});
		}
		
		if (!empty($filters['direction'])) {
			$history = array_filter($history, function($entry) use ($filters) {
				return $entry['direction'] === $filters['direction'];
			});
		}
		
		if (!empty($filters['status'])) {
			$history = array_filter($history, function($entry) use ($filters) {
				return $entry['status'] === $filters['status'];
			});
		}
		
		if (!empty($filters['date_from'])) {
			$dateFrom = is_numeric($filters['date_from']) ? $filters['date_from'] : strtotime($filters['date_from']);
			$history = array_filter($history, function($entry) use ($dateFrom) {
				return ($entry['timestamp'] ?? 0) >= $dateFrom;
			});
		}
		
		if (!empty($filters['date_to'])) {
			$dateTo = is_numeric($filters['date_to']) ? $filters['date_to'] : strtotime($filters['date_to']);
			$history = array_filter($history, function($entry) use ($dateTo) {
				return ($entry['timestamp'] ?? 0) <= $dateTo;
			});
		}
		
		// Aplicar límite final
		$history = array_slice($history, 0, $limit);
		
		return $history;
	}

	/**
	 * NUEVO: Limpia el historial de sincronización
	 * 
	 * @param int $daysToKeep Días de historial a mantener
	 * @return int Número de entradas eliminadas
	 */
	public function cleanSyncHistory(int $daysToKeep = 30): int
	{
		$config = $this->getConfig();
		$history = get_option($config['metrics_option'] . '_history', []);
		$originalCount = count($history);
		
		$cutoffTimestamp = $this->getCurrentTimestamp() - ($daysToKeep * 86400);
		
		$history = array_filter($history, function($entry) use ($cutoffTimestamp) {
			return ($entry['timestamp'] ?? 0) >= $cutoffTimestamp;
		});
		
		update_option($config['metrics_option'] . '_history', $history, true);
		
		$deletedCount = $originalCount - count($history);
		
		$this->logger->info("Historial de sincronización limpiado", [
			'days_kept' => $daysToKeep,
			'entries_deleted' => $deletedCount,
			'entries_remaining' => count($history)
		]);
		
		return $deletedCount;
	}
} 