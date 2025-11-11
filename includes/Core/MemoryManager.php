<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

/**
 * Gestor centralizado de métricas del sistema
 * CORRECCIÓN #10+ - Expansión para centralizar todas las métricas del sistema
 */
class MemoryManager
{
    // SINGLETON: Instancia única
    private static ?self $instance = null;
    
    // CACHE: Cache para configuración dinámica
    private static ?array $configCache = null;
    
    // REFACTORIZADO: Configuración dinámica sin hardcodeos
    private const DEFAULT_MEMORY_LIMIT = 256; // MB - Valor por defecto si no se puede detectar
    
    // REFACTORIZADO: Configuración adaptativa según entorno (porcentajes del límite disponible)
    private const MEMORY_BUFFER_RATIOS = [
        'development' => 0.6,  // 60% en desarrollo (más conservador)
        'staging' => 0.7,      // 70% en staging
        'production' => 0.8    // 80% en producción
    ];
    
    private const WARNING_THRESHOLD_RATIOS = [
        'development' => 0.5,  // 50% en desarrollo
        'staging' => 0.6,      // 60% en staging
        'production' => 0.7    // 70% en producción
    ];
    
    private const CRITICAL_THRESHOLD_RATIOS = [
        'development' => 0.7,  // 70% en desarrollo
        'staging' => 0.8,      // 80% en staging
        'production' => 0.9    // 90% en producción
    ];
    
    private const CLEANUP_THRESHOLD_RATIOS = [
        'development' => 0.6,  // 60% en desarrollo
        'staging' => 0.7,      // 70% en staging
        'production' => 0.75   // 75% en producción
    ];
    
    // REFACTORIZADO: Límites inteligentes para historial (porcentajes del total disponible)
    private const MAX_HISTORY_RECORDS = 100; // Máximo de registros en historial
    private const MAX_ALERT_RECORDS = 50; // Máximo de alertas
    private const HISTORY_CLEANUP_RATIO = 0.8; // Limpiar historial cuando use 80% de memoria
    private const ALERT_CLEANUP_RATIO = 0.7; // Limpiar alertas cuando use 70% de memoria

    /**
     * Obtiene la instancia del logger (DRY Principle)
     */
    private static function getLogger(): \MiIntegracionApi\Helpers\Logger
    {
        return new \MiIntegracionApi\Helpers\Logger('memory-manager');
    }

    /**
     * REFACTORIZADO: Verifica si se ha excedido el límite de memoria con configuración dinámica
     * 
     * @param int|null $memoryLimit Límite de memoria en MB (null para auto-detección)
     * @return bool True si se ha excedido el límite
     */
    public static function isMemoryLimitExceeded(?int $memoryLimit = null): bool
    {
        $currentUsage = memory_get_usage(true) / 1024 / 1024;
        $limit = ($memoryLimit ?? self::getDynamicMemoryLimit()) * self::getDynamicMemoryBuffer();
        
        return $currentUsage > $limit;
    }
    
    /**
     * REFACTORIZADO: Obtiene el límite de memoria de forma dinámica
     * 
     * @return int Límite de memoria en MB
     */
    public static function getDynamicMemoryLimit(): int
    {
        $memoryLimit = ini_get('memory_limit');
        
        if ($memoryLimit === '-1') {
            // Memoria ilimitada - usar valor por defecto conservador
            return self::DEFAULT_MEMORY_LIMIT;
        }
        
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;
        
        switch ($unit) {
            case 'g':
                $value *= 1024;
                break;
            case 'm':
                break;
            case 'k':
                $value /= 1024;
                break;
        }
        
        // Validar que el valor sea razonable
        if ($value < 64) {
            return self::DEFAULT_MEMORY_LIMIT; // Mínimo 64MB
        }
        
        return $value;
    }
    
    /**
     * REFACTORIZADO: Obtiene el buffer de memoria según el entorno
     * 
     * @return float Buffer de memoria (porcentaje del límite)
     */
    public static function getDynamicMemoryBuffer(): float
    {
        $environment = self::detectEnvironment();
        return self::MEMORY_BUFFER_RATIOS[$environment] ?? 0.7;
    }
    
    /**
     * OPTIMIZADO: Obtiene la configuración dinámica con cache
     * 
     * @return array<string, mixed> Configuración dinámica
     */
    private static function getDynamicConfig(): array
    {
        if (self::$configCache === null) {
            $environment = self::detectEnvironment();
            
            self::$configCache = [
                'environment' => $environment,
                'memory_limit' => self::getDynamicMemoryLimit(),
                'memory_buffer' => self::getDynamicMemoryBuffer(),
                'warning_threshold' => self::getDynamicWarningThreshold(),
                'critical_threshold' => self::getDynamicCriticalThreshold(),
                'cleanup_threshold' => self::getDynamicCleanupThreshold(),
                'effective_limit' => self::getDynamicMemoryLimit() * self::getDynamicMemoryBuffer()
            ];
        }
        
        return self::$configCache;
    }
    
    /**
     * REFACTORIZADO: Detecta el entorno actual de forma inteligente
     * 
     * @return string Entorno detectado
     */
    private static function detectEnvironment(): string
    {
        // Prioridad 1: Constante WordPress
        if (defined('WP_ENVIRONMENT_TYPE')) {
            $wpEnv = constant('WP_ENVIRONMENT_TYPE');
            if (in_array($wpEnv, ['development', 'staging', 'production'])) {
                return $wpEnv;
            }
        }
        
        // Prioridad 2: Variable de entorno
        $envVar = getenv('WP_ENVIRONMENT_TYPE');
        if ($envVar && in_array($envVar, ['development', 'staging', 'production'])) {
            return $envVar;
        }
        
        // Prioridad 3: WP_DEBUG
        if (defined('WP_DEBUG') && constant('WP_DEBUG')) {
            return 'development';
        }
        
        // Prioridad 4: Detección por hostname
        $hostname = gethostname();
        if (strpos($hostname, 'dev') !== false || strpos($hostname, 'local') !== false) {
            return 'development';
        } elseif (strpos($hostname, 'staging') !== false || strpos($hostname, 'test') !== false) {
            return 'staging';
        }
        
        // Fallback a producción por seguridad
        return 'production';
    }
    
    /**
     * REFACTORIZADO: Detecta si estamos en entorno de desarrollo (compatibilidad)
     * 
     * @return bool True si es entorno de desarrollo
     */
    private static function isDevelopmentEnvironment(): bool
    {
        return self::detectEnvironment() === 'development';
    }

    /**
     * REFACTORIZADO: Obtiene estadísticas de uso de memoria con configuración dinámica
     * 
     * @return array<string, mixed> Estadísticas de memoria
     */
    public static function getMemoryStats(): array
    {
        $currentUsage = round(memory_get_usage(true) / 1024 / 1024, 2);
        $peakUsage = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $dynamicLimit = self::getDynamicMemoryLimit();
        $dynamicBuffer = self::getDynamicMemoryBuffer();
        $effectiveLimit = $dynamicLimit * $dynamicBuffer;
        $availableMemory = self::getAvailableMemory();
        $usagePercentage = $availableMemory > 0 ? ($currentUsage / $availableMemory) * 100 : 0;
        
        return [
            'current' => $currentUsage,
            'peak' => $peakUsage,
            'limit' => ini_get('memory_limit'),
            'dynamic_limit' => $dynamicLimit,
            'dynamic_buffer' => $dynamicBuffer,
            'effective_limit' => round($effectiveLimit, 2),
            'available' => $availableMemory,
            'usage_percentage' => round($usagePercentage, 2),
            'environment' => self::isDevelopmentEnvironment() ? 'development' : 'production',
            'thresholds' => [
                'warning' => self::getDynamicWarningThreshold(),
                'critical' => self::getDynamicCriticalThreshold(),
                'cleanup' => self::getDynamicCleanupThreshold()
            ]
        ];
    }
    
    /**
     * REFACTORIZADO: Obtiene el umbral de advertencia según el entorno
     * 
     * @return float Umbral de advertencia
     */
    public static function getDynamicWarningThreshold(): float
    {
        $environment = self::detectEnvironment();
        return self::WARNING_THRESHOLD_RATIOS[$environment] ?? 0.6;
    }
    
    /**
     * REFACTORIZADO: Obtiene el umbral crítico según el entorno
     * 
     * @return float Umbral crítico
     */
    public static function getDynamicCriticalThreshold(): float
    {
        $environment = self::detectEnvironment();
        return self::CRITICAL_THRESHOLD_RATIOS[$environment] ?? 0.8;
    }
    
    /**
     * REFACTORIZADO: Obtiene el umbral de limpieza según el entorno
     * 
     * @return float Umbral de limpieza
     */
    public static function getDynamicCleanupThreshold(): float
    {
        $environment = self::detectEnvironment();
        return self::CLEANUP_THRESHOLD_RATIOS[$environment] ?? 0.7;
    }

    /**
     * REFACTORIZADO: Limpia la memoria y fuerza la recolección de basura con configuración dinámica
     * 
     * @param string $context Contexto de la operación para logging
     * @param bool $aggressive Limpieza agresiva (para situaciones críticas)
     * @return array<string, mixed> Estadísticas de memoria antes y después de la limpieza
     */
    public static function cleanup(string $context = '', bool $aggressive = false): array
    {
        $before = self::getMemoryStats();
        $logger = self::getLogger();
        
        try {
            // REFACTORIZADO: Limpieza básica siempre
            self::performBasicCleanup();
            
            // REFACTORIZADO: Limpieza agresiva si es necesario
            if ($aggressive || $before['usage_percentage'] > self::getDynamicCriticalThreshold() * 100) {
                self::performAggressiveCleanup();
                $logger->warning("Limpieza agresiva de memoria ejecutada", [
                    'context' => $context,
                    'usage_percentage' => $before['usage_percentage']
                ]);
            }
            
            // REFACTORIZADO: Limpieza inteligente basada en umbrales
            if ($before['usage_percentage'] > self::getDynamicCleanupThreshold() * 100) {
                self::performIntelligentCleanup();
            }
            
        } catch (\Throwable $e) {
            $logger->error("Error durante la limpieza de memoria", [
                'context' => $context,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        $after = self::getMemoryStats();
        $reduction = round($before['current'] - $after['current'], 2);
        
        if (!empty($context)) {
            $logger->info(
                "Limpieza de memoria completada",
                [
                    'context' => $context,
                    'before' => $before,
                    'after' => $after,
                    'reduction' => $reduction,
                    'aggressive' => $aggressive,
                    'thresholds' => [
                        'cleanup' => self::getDynamicCleanupThreshold(),
                        'critical' => self::getDynamicCriticalThreshold()
                    ]
                ]
            );
        }
        
        return [
            'before' => $before,
            'after' => $after,
            'reduction' => $reduction,
            'aggressive' => $aggressive
        ];
    }
    
    /**
     * REFACTORIZADO: Realiza limpieza básica de memoria
     */
    private static function performBasicCleanup(): void
    {
        // Liberar variables globales
        global $wpdb;
        if (isset($wpdb)) {
            $wpdb->flush();
        }
        
        // Forzar recolección de basura
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        // Limpiar caché de WordPress
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }
    
    /**
     * REFACTORIZADO: Realiza limpieza agresiva de memoria
     */
    private static function performAggressiveCleanup(): void
    {
        // Limpieza básica
        self::performBasicCleanup();
        
        // Limpiar transients antiguos
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('delete_expired_transients');
        }
        
        // Forzar recolección de basura múltiples veces
        if (function_exists('gc_collect_cycles')) {
            for ($i = 0; $i < 3; $i++) {
                gc_collect_cycles();
            }
        }
        
        // Limpiar caché de objetos
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('default');
        }
    }
    
    /**
     * REFACTORIZADO: Realiza limpieza inteligente basada en umbrales
     */
    private static function performIntelligentCleanup(): void
    {
        $stats = self::getMemoryStats();
        
        // Si el uso es alto, limpiar más agresivamente
        if ($stats['usage_percentage'] > 80) {
            self::performAggressiveCleanup();
        } else {
            self::performBasicCleanup();
        }
    }

    /**
     * Calcula la memoria disponible en MB
     * 
     * @return float Memoria disponible en MB
     */
    private static function getAvailableMemory(): float
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return PHP_FLOAT_MAX;
        }
        
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;
        
        switch ($unit) {
            case 'g':
                $value *= 1024;
                break;
            case 'm':
                break;
            case 'k':
                $value /= 1024;
                break;
        }
        
        return $value;
    }

    /**
     * REFACTORIZADO: Ajusta el tamaño del lote basado en el uso de memoria con configuración dinámica
     * 
     * @param int $currentBatchSize Tamaño actual del lote
     * @param int $minBatchSize Tamaño mínimo del lote
     * @param string $context Contexto de la operación para logging
     * @return int Tamaño ajustado del lote
     */
    public static function adjustBatchSize(int $currentBatchSize, int $minBatchSize = 10, string $context = ''): int
    {
        $stats = self::getMemoryStats();
        $availableMemory = $stats['available'];
        $currentUsage = $stats['current'];
        $usagePercentage = $stats['usage_percentage'];
        $warningThreshold = self::getDynamicWarningThreshold() * 100;
        $cleanupThreshold = self::getDynamicCleanupThreshold() * 100;
        
        // REFACTORIZADO: Lógica de ajuste inteligente basada en umbrales dinámicos
        $adjustmentFactor = 1.0;
        $reason = '';
        
        if ($usagePercentage > $cleanupThreshold) {
            // Uso crítico - reducir drásticamente
            $adjustmentFactor = 0.3;
            $reason = 'uso crítico de memoria';
        } elseif ($usagePercentage > $warningThreshold) {
            // Uso alto - reducir moderadamente
            $adjustmentFactor = 0.5;
            $reason = 'uso alto de memoria';
        } elseif ($usagePercentage > ($warningThreshold * self::WARNING_THRESHOLD_RATIOS['staging'])) {
            // Uso moderado - reducir ligeramente
            $adjustmentFactor = self::WARNING_THRESHOLD_RATIOS['development'];
            $reason = 'uso moderado de memoria';
        }
        
        if ($adjustmentFactor < 1.0) {
            $newBatchSize = max($minBatchSize, (int) ($currentBatchSize * $adjustmentFactor));
            
            // REFACTORIZADO: Logging inteligente solo cuando es necesario
            if (!empty($context)) {
                self::getLogger()->warning(
                    "Ajustando tamaño de lote por {$reason}",
                    [
                        'context' => $context,
                        'current_batch_size' => $currentBatchSize,
                        'new_batch_size' => $newBatchSize,
                        'adjustment_factor' => $adjustmentFactor,
                        'memory_usage_mb' => $currentUsage,
                        'memory_available_mb' => $availableMemory,
                        'usage_percentage' => $usagePercentage,
                        'thresholds' => [
                            'warning' => $warningThreshold,
                            'cleanup' => $cleanupThreshold
                        ]
                    ]
                );
            }
            
            return $newBatchSize;
        }
        
        return $currentBatchSize;
    }

    /**
     * Calcula el intervalo adaptativo para verificación de memoria.
     * 
     * Ajusta dinámicamente la frecuencia de verificación de memoria basándose
     * en el tamaño del lote para optimizar el rendimiento:
     * 
     * - Lotes pequeños (≤10): Verifica cada elemento (máxima seguridad)
     * - Lotes medianos (11-500): Verifica cada batch_size/10 elementos
     * - Lotes grandes (>500): Verifica cada 50 elementos máximo (rendimiento)
     * 
     * @param int $batchSize Tamaño del lote actual
     * @return int Intervalo de verificación (número de elementos entre verificaciones)
     * 
     * @example
     * ```php
     * $interval = MemoryManager::calculateMemoryCheckInterval(5);   // Returns: 1
     * $interval = MemoryManager::calculateMemoryCheckInterval(50);  // Returns: 5  
     * $interval = MemoryManager::calculateMemoryCheckInterval(500); // Returns: 50
     * $interval = MemoryManager::calculateMemoryCheckInterval(1000);// Returns: 50 (max)
     * ```
     */
    public static function calculateMemoryCheckInterval(int $batchSize): int
    {
        $minInterval = 1;
        $maxInterval = 50;
        $factor = 10.0;
        
        // Para lotes muy pequeños, verificar cada elemento
        if ($batchSize <= 10) {
            return $minInterval;
        }
        
        // Calcular intervalo basado en tamaño del lote
        $calculatedInterval = max(
            $minInterval,
            min(
                $maxInterval,
                intval($batchSize / $factor)
            )
        );
        
        return $calculatedInterval;
    }

    /**
     * Verifica si debe detenerse por razones críticas de memoria.
     * 
     * @param array $memoryStats Estadísticas de memoria (opcional, se obtendrán automáticamente)
     * @return bool True si debe detenerse inmediatamente
     */
    public static function shouldStopForCriticalMemory(?array $memoryStats = null): bool
    {
        $stats = $memoryStats ?? self::getMemoryStats();
        $usagePercentage = $stats['usage_percentage'] ?? 0;
        $criticalThreshold = self::getDynamicCriticalThreshold() * 100;
        
        // Usar umbral dinámico en lugar de valores hardcodeados
        return $usagePercentage > $criticalThreshold;
    }

    /**
     * Verifica si debe detenerse gradualmente por uso alto de memoria.
     * 
     * @param array $memoryStats Estadísticas de memoria (opcional, se obtendrán automáticamente)
     * @return bool True si debe prepararse para parada gradual
     */
    public static function shouldStopGracefullyForMemory(?array $memoryStats = null): bool
    {
        $stats = $memoryStats ?? self::getMemoryStats();
        $usagePercentage = $stats['usage_percentage'] ?? 0;
        $warningThreshold = self::getDynamicWarningThreshold() * 100;
        
        // Usar umbral dinámico en lugar de valores hardcodeados
        return $usagePercentage > $warningThreshold;
    }

    // MÉTODOS DE INSTANCIA PARA COMPATIBILIDAD Y FUNCIONALIDADES AVANZADAS
    
    private Logger $logger;
    private array $memory_history = [];
    private array $alerts = [];
    private int $memory_limit_mb;
    private float $warning_threshold;
    private float $critical_threshold;
    private float $cleanup_threshold;

    private function __construct(?int $memory_limit_mb = null)
    {
        $this->logger = new Logger('memory-manager');
        
        // REFACTORIZADO: Usar configuración dinámica si no se especifica
        $this->memory_limit_mb = $memory_limit_mb ?? self::getDynamicMemoryLimit();
        
        // REFACTORIZADO: Obtener configuración dinámica desde WordPress options con fallbacks inteligentes
        $this->warning_threshold = (float) get_option('mia_memory_warning_threshold', self::getDynamicWarningThreshold());
        $this->critical_threshold = (float) get_option('mia_memory_critical_threshold', self::getDynamicCriticalThreshold());
        $this->cleanup_threshold = (float) get_option('mia_memory_cleanup_threshold', self::getDynamicCleanupThreshold());
        }

    /**
     * SINGLETON: Obtiene la instancia única del MemoryManager
     * 
     * @param int|null $memory_limit_mb Límite de memoria opcional
     * @return self Instancia única
     */
    public static function getInstance(?int $memory_limit_mb = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($memory_limit_mb);
        }
        return self::$instance;
    }

    /**
     * SINGLETON: Previene la clonación de la instancia
     */
    private function __clone()
    {
        // Prevenir clonación
    }

    /**
     * SINGLETON: Previene la deserialización de la instancia
     */
    public function __wakeup()
    {
        throw new \Exception('No se puede deserializar un Singleton');
    }
    
    /**
     * OPTIMIZADO: Limpia el cache de configuración dinámica
     * Útil cuando cambian las configuraciones del entorno
     */
    public static function clearConfigCache(): void
    {
        self::$configCache = null;
    }

    /**
     * Verifica si hay memoria disponible (método de compatibilidad)
     * 
     * @return bool True si hay memoria suficiente
     */
    public function checkMemory(): bool
    {
        $stats = self::getMemoryStats();
        $current_usage_mb = $stats['current'];
        $available_memory_mb = $stats['available'];
        
        // Calcular porcentaje de uso
        $usage_percentage = $current_usage_mb / $available_memory_mb;
        
        // Registrar en historial
        $this->recordMemoryUsage($current_usage_mb, $available_memory_mb, $usage_percentage);
        
        // Verificar umbrales y generar alertas
        $this->checkThresholds($current_usage_mb, $available_memory_mb, $usage_percentage);
        
        // Retornar true si estamos por debajo del umbral crítico
        return $usage_percentage < $this->critical_threshold;
    }

    /**
     * REFACTORIZADO: Registra el uso de memoria en el historial con límites inteligentes
     * 
     * @param float $current_usage_mb Uso actual en MB
     * @param float $available_memory_mb Memoria disponible en MB
     * @param float $usage_percentage Porcentaje de uso
     */
    private function recordMemoryUsage(float $current_usage_mb, float $available_memory_mb, float $usage_percentage): void
    {
        $record = [
            'timestamp' => current_time('mysql'),
            'current_usage_mb' => $current_usage_mb,
            'available_memory_mb' => $available_memory_mb,
            'usage_percentage' => $usage_percentage,
            'memory_limit_mb' => $this->memory_limit_mb,
            'environment' => self::isDevelopmentEnvironment() ? 'development' : 'production'
        ];
        
        $this->memory_history[] = $record;
        
        // REFACTORIZADO: Límites inteligentes basados en memoria disponible
        $maxHistoryRecords = self::calculateIntelligentHistoryLimit();
        
        if (count($this->memory_history) > $maxHistoryRecords) {
            // Limpiar registros antiguos de forma inteligente
            $this->cleanupHistoryIntelligently();
        }
    }
    
    /**
     * REFACTORIZADO: Calcula el límite inteligente de registros en historial
     * 
     * @return int Número máximo de registros a mantener
     */
    private function calculateIntelligentHistoryLimit(): int
    {
        $stats = self::getMemoryStats();
        $usagePercentage = $stats['usage_percentage'];
        
        // REFACTORIZADO: Ajustar límite según uso de memoria
        if ($usagePercentage > self::getDynamicCriticalThreshold() * 100) {
            return (int) (self::MAX_HISTORY_RECORDS * self::CRITICAL_THRESHOLD_RATIOS['development']); // Solo 30% en situación crítica
        } elseif ($usagePercentage > self::getDynamicWarningThreshold() * 100) {
            return (int) (self::MAX_HISTORY_RECORDS * self::WARNING_THRESHOLD_RATIOS['development']); // Solo 50% en situación de advertencia
        } elseif ($usagePercentage > self::getDynamicCleanupThreshold() * 100) {
            return (int) (self::MAX_HISTORY_RECORDS * self::HISTORY_CLEANUP_RATIO); // Solo 80% en situación de limpieza
        }
        
        return self::MAX_HISTORY_RECORDS; // 100% en situación normal
    }
    
    /**
     * REFACTORIZADO: Limpia el historial de forma inteligente
     */
    private function cleanupHistoryIntelligently(): void
    {
        $stats = self::getMemoryStats();
        $usagePercentage = $stats['usage_percentage'];
        
        if ($usagePercentage > self::getDynamicCriticalThreshold() * 100) {
            // Situación crítica - limpiar drásticamente
            $this->memory_history = array_slice($this->memory_history, -10);
            $this->logger->warning('Historial de memoria limpiado drásticamente por uso crítico', [
                'usage_percentage' => $usagePercentage,
                'remaining_records' => count($this->memory_history)
            ]);
        } elseif ($usagePercentage > self::getDynamicWarningThreshold() * 100) {
            // Situación de advertencia - limpiar moderadamente
            $this->memory_history = array_slice($this->memory_history, -30);
            $this->logger->info('Historial de memoria limpiado moderadamente por uso alto', [
                'usage_percentage' => $usagePercentage,
                'remaining_records' => count($this->memory_history)
            ]);
        } else {
            // Situación normal - limpiar solo lo necesario
            $this->memory_history = array_slice($this->memory_history, -self::MAX_HISTORY_RECORDS);
        }
    }

    /**
     * Verifica umbrales de memoria y genera alertas
     * 
     * @param float $current_usage_mb Uso actual en MB
     * @param float $available_memory_mb Memoria disponible en MB
     * @param float $usage_percentage Porcentaje de uso
     */
    private function checkThresholds(float $current_usage_mb, float $available_memory_mb, float $usage_percentage): void
    {
        $alert_level = 'info';
        $message = '';
        
        if ($usage_percentage >= $this->critical_threshold) {
            $alert_level = 'critical';
            $message = "Uso de memoria crítico: {$usage_percentage}% ({$current_usage_mb}MB / {$available_memory_mb}MB)";
        } elseif ($usage_percentage >= $this->warning_threshold) {
            $alert_level = 'warning';
            $message = "Uso de memoria alto: {$usage_percentage}% ({$current_usage_mb}MB / {$available_memory_mb}MB)";
        } elseif ($usage_percentage >= $this->cleanup_threshold) {
            $alert_level = 'info';
            $message = "Considerar limpieza de memoria: {$usage_percentage}% ({$current_usage_mb}MB / {$available_memory_mb}MB)";
        }
        
        if ($message) {
            $this->addAlert($alert_level, $message, [
                'current_usage_mb' => $current_usage_mb,
                'available_memory_mb' => $available_memory_mb,
                'usage_percentage' => $usage_percentage,
                'thresholds' => [
                    'warning' => $this->warning_threshold,
                    'critical' => $this->critical_threshold,
                    'cleanup' => $this->cleanup_threshold
                ]
            ]);
        }
    }

    /**
     * REFACTORIZADO: Agrega una alerta de memoria con límites inteligentes
     * 
     * @param string $level Nivel de alerta (info, warning, critical)
     * @param string $message Mensaje de la alerta
     * @param array $context Contexto adicional
     */
    private function addAlert(string $level, string $message, array $context = []): void
    {
        $alert = [
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'environment' => self::isDevelopmentEnvironment() ? 'development' : 'production'
        ];
        
        $this->alerts[] = $alert;
        
        // REFACTORIZADO: Límites inteligentes basados en memoria disponible
        $maxAlertRecords = self::calculateIntelligentAlertLimit();
        
        if (count($this->alerts) > $maxAlertRecords) {
            // Limpiar alertas antiguas de forma inteligente
            $this->cleanupAlertsIntelligently();
        }
        
        // REFACTORIZADO: Logging inteligente solo para alertas importantes
        if ($level === 'critical' || $level === 'warning' || self::isDevelopmentEnvironment()) {
            switch ($level) {
                case 'critical':
                    $this->logger->error($message, $context);
                    break;
                case 'warning':
                    $this->logger->warning($message, $context);
                    break;
                default:
                    $this->logger->info($message, $context);
            }
        }
    }
    
    /**
     * REFACTORIZADO: Calcula el límite inteligente de alertas
     * 
     * @return int Número máximo de alertas a mantener
     */
    private function calculateIntelligentAlertLimit(): int
    {
        $stats = self::getMemoryStats();
        $usagePercentage = $stats['usage_percentage'];
        
        // REFACTORIZADO: Ajustar límite según uso de memoria
        if ($usagePercentage > self::getDynamicCriticalThreshold() * 100) {
            return (int) (self::MAX_ALERT_RECORDS * 0.2); // Solo 20% en situación crítica
        } elseif ($usagePercentage > self::getDynamicWarningThreshold() * 100) {
            return (int) (self::MAX_ALERT_RECORDS * 0.4); // Solo 40% en situación de advertencia
        } elseif ($usagePercentage > self::getDynamicCleanupThreshold() * 100) {
            return (int) (self::MAX_ALERT_RECORDS * self::ALERT_CLEANUP_RATIO); // Solo 70% en situación de limpieza
        }
        
        return self::MAX_ALERT_RECORDS; // 100% en situación normal
    }
    
    /**
     * REFACTORIZADO: Limpia las alertas de forma inteligente
     */
    private function cleanupAlertsIntelligently(): void
    {
        $stats = self::getMemoryStats();
        $usagePercentage = $stats['usage_percentage'];
        
        if ($usagePercentage > self::getDynamicCriticalThreshold() * 100) {
            // Situación crítica - limpiar drásticamente
            $this->alerts = array_slice($this->alerts, -5);
            $this->logger->warning('Alertas de memoria limpiadas drásticamente por uso crítico', [
                'usage_percentage' => $usagePercentage,
                'remaining_alerts' => count($this->alerts)
            ]);
        } elseif ($usagePercentage > self::getDynamicWarningThreshold() * 100) {
            // Situación de advertencia - limpiar moderadamente
            $this->alerts = array_slice($this->alerts, -15);
            $this->logger->info('Alertas de memoria limpiadas moderadamente por uso alto', [
                'usage_percentage' => $usagePercentage,
                'remaining_alerts' => count($this->alerts)
            ]);
        } else {
            // Situación normal - limpiar solo lo necesario
            $this->alerts = array_slice($this->alerts, -self::MAX_ALERT_RECORDS);
        }
    }

    /**
     * Obtiene el historial de uso de memoria
     * 
     * @return array Historial de uso de memoria
     */
    public function getMemoryHistory(): array
    {
        return $this->memory_history;
    }

    /**
     * Obtiene las alertas de memoria
     * 
     * @return array Alertas de memoria
     */
    public function getAlerts(): array
    {
        return $this->alerts;
    }

    /**
     * Obtiene estadísticas avanzadas de memoria
     * 
     * @return array Estadísticas avanzadas
     */
    public function getAdvancedMemoryStats(): array
    {
        $basic_stats = self::getMemoryStats();
        $current_usage_mb = $basic_stats['current'];
        $available_memory_mb = $basic_stats['available'];
        $usage_percentage = $current_usage_mb / $available_memory_mb;
        
        return array_merge($basic_stats, [
            'usage_percentage' => round($usage_percentage * 100, 2),
            'status' => $this->getMemoryStatus($usage_percentage),
            'recommendations' => $this->getMemoryRecommendations($usage_percentage, $current_usage_mb),
            'thresholds' => [
                'warning' => $this->warning_threshold * 100,
                'critical' => $this->critical_threshold * 100,
                'cleanup' => $this->cleanup_threshold * 100
            ],
            'history_count' => count($this->memory_history),
            'alerts_count' => count($this->alerts)
        ]);
    }

    /**
     * Obtiene el estado de la memoria
     * 
     * @param float $usage_percentage Porcentaje de uso
     * @return string Estado de la memoria
     */
    private function getMemoryStatus(float $usage_percentage): string
    {
        if ($usage_percentage >= $this->critical_threshold) {
            return 'critical';
        } elseif ($usage_percentage >= $this->warning_threshold) {
            return 'warning';
        } else {
            return 'healthy';
        }
    }

    /**
     * Obtiene recomendaciones basadas en el uso de memoria
     * 
     * @param float $usage_percentage Porcentaje de uso
     * @param float $current_usage_mb Uso actual en MB
     * @return array Recomendaciones
     */
    private function getMemoryRecommendations(float $usage_percentage, float $current_usage_mb): array
    {
        $recommendations = [];
        
        if ($usage_percentage >= $this->critical_threshold) {
            $recommendations[] = 'Memoria crítica - Considerar detener operaciones pesadas';
            $recommendations[] = 'Ejecutar limpieza de memoria inmediatamente';
            $recommendations[] = 'Revisar configuración de límites de memoria';
        } elseif ($usage_percentage >= $this->warning_threshold) {
            $recommendations[] = 'Memoria alta - Considerar limpieza preventiva';
            $recommendations[] = 'Reducir tamaño de lotes si es posible';
            $recommendations[] = 'Monitorear tendencia de uso de memoria';
        } elseif ($usage_percentage >= $this->cleanup_threshold) {
            $recommendations[] = 'Memoria moderada - Ejecutar limpieza programada';
            $recommendations[] = 'Optimizar consultas de base de datos';
        } else {
            $recommendations[] = 'Memoria saludable - Continuar operaciones normalmente';
        }
        
        // Recomendaciones específicas basadas en uso absoluto
        if ($current_usage_mb > 512) {
            $recommendations[] = 'Uso absoluto alto - Considerar aumentar límite de memoria PHP';
        }
        
        return $recommendations;
    }

    /**
     * Limpia la memoria y registra la operación
     * 
     * @param string $context Contexto de la limpieza
     * @return array Resultado de la limpieza
     */
    public function performCleanup(string $context = ''): array
    {
        $before_stats = self::getMemoryStats();
        $result = self::cleanup($context);
        $after_stats = self::getMemoryStats();
        
        // Registrar limpieza en historial
        $this->recordMemoryUsage(
            $after_stats['current'],
            $after_stats['available'],
            $after_stats['current'] / $after_stats['available']
        );
        
        // Agregar alerta de limpieza
        $this->addAlert('info', "Limpieza de memoria ejecutada", [
            'context' => $context,
            'before' => $before_stats,
            'after' => $after_stats,
            'reduction_mb' => round($before_stats['current'] - $after_stats['current'], 2)
        ]);
        
        return $result;
    }

    /**
     * Resetea el historial y alertas (útil para testing)
     */
    public function reset(): void
    {
        $this->memory_history = [];
        $this->alerts = [];
        $this->logger->info('Historial y alertas de memoria reseteados');
    }

    // CORRECCIÓN #10+ - MÉTODOS CENTRALIZADOS PARA TODAS LAS MÉTRICAS DEL SISTEMA

    /**
     * Obtiene métricas completas del sistema (método centralizado)
     * 
     * @return array Métricas completas del sistema
     */
    public static function getSystemMetrics(): array
    {
        return [
            'memory' => self::getMemoryStats(),
            'retry' => self::getRetryMetrics(),
            'sync' => self::getSyncMetrics(),
            'api' => self::getApiMetrics(),
            'database' => self::getDatabaseMetrics(),
            'filesystem' => self::getFilesystemMetrics(),
            'performance' => self::getPerformanceMetrics(),
            'timestamp' => current_time('mysql')
            // EVITAR DEPENDENCIA CIRCULAR: No llamar a getOverallSystemHealth() aquí
        ];
    }

    /**
     * Obtiene métricas de reintentos del sistema
     * 
     * @return array Métricas de reintentos
     */
    public static function getRetryMetrics(): array
    {
        $total_attempts = get_option('mia_retry_total_attempts', 0);
        $successful_attempts = get_option('mia_retry_successful_attempts', 0);
        $failed_attempts = get_option('mia_retry_failed_attempts', 0);
        
        if ($total_attempts === 0) {
            return [
                'status' => 'no_data',
                'success_rate' => 100,
                'total_attempts' => 0,
                'successful_attempts' => 0,
                'failed_attempts' => 0,
                'message' => 'Sin datos de reintentos disponibles'
            ];
        }
        
        $success_rate = $total_attempts > 0 ? round(($successful_attempts / $total_attempts) * 100, 1) : 0;
        
        $status = match(true) {
            $success_rate >= 95 => 'excellent',
            $success_rate >= 80 => 'good',
            $success_rate >= 60 => 'fair',
            default => 'poor'
        };
        
        return [
            'status' => $status,
            'success_rate' => $success_rate,
            'total_attempts' => $total_attempts,
            'successful_attempts' => $successful_attempts,
            'failed_attempts' => $failed_attempts,
            'message' => self::getRetryStatusMessage($status, $success_rate)
        ];
    }

    /**
     * Obtiene métricas de sincronización
     * 
     * @return array Métricas de sincronización
     */
    public static function getSyncMetrics(): array
    {
        // EVITAR DEPENDENCIA CIRCULAR: No llamar a Sync_Manager desde MemoryManager
        // en métodos estáticos que pueden ser llamados por Sync_Manager
        
        try {
            // Obtener métricas básicas sin depender de Sync_Manager
            $last_sync_time = get_option('mia_last_sync_time', 0);
            $last_sync_errors = get_option('mia_last_sync_errors', 0);
            
            // Simular estado básico
            $status = 'unknown';
            $progress = 0;
            $total_items = 0;
            $processed_items = 0;
            
            $status_text = 'Estado desconocido (métricas básicas)';
            $progress_message = 'No hay información de sincronización disponible';
            
            // Determinar estado crítico básico
            $critical_status = 'healthy';
            if ($last_sync_errors > 0) {
                $critical_status = 'warning';
            }
            
            return [
                'status' => $critical_status,
                'status_text' => $status_text,
                'progress' => $progress,
                'total_items' => $total_items,
                'processed_items' => $processed_items,
                'progress_message' => $progress_message,
                'last_sync_time' => $last_sync_time,
                'errors_count' => $last_sync_errors,
                'note' => 'Métricas básicas (sin dependencia de Sync_Manager)'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error al obtener métricas básicas: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene métricas de la API
     * 
     * @return array Métricas de la API
     */
    public static function getApiMetrics(): array
    {
        try {
            return [
                'connection_status' => 'Activa', // TODO: Implementar verificación real
                'last_check' => current_time('mysql'),
                'api_url' => get_option('mia_api_base_url', 'No configurada'),
                'api_key_configured' => !empty(get_option('mia_api_key', '')),
                'ssl_verified' => get_option('mia_ssl_verify', true),
                'timeout' => get_option('mia_api_timeout', 30),
                'retry_attempts' => get_option('mia_api_retry_attempts', 3)
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error al verificar API: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene métricas de la base de datos
     * 
     * @return array Métricas de la base de datos
     */
    public static function getDatabaseMetrics(): array
    {
        global $wpdb;
        
        try {
            if (!$wpdb) {
                return [
                    'status' => 'unavailable',
                    'message' => 'Conexión a base de datos no disponible'
                ];
            }
            
            // Obtener estadísticas de la base de datos
            $total_posts = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
            $total_products = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'");
            $total_orders = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'");
            
            // Verificar tamaño de tablas importantes
            $table_sizes = [];
            $important_tables = ['posts', 'postmeta', 'options', 'term_relationships'];
            
            foreach ($important_tables as $table) {
                $table_name = $wpdb->prefix . $table;
                $size_query = $wpdb->get_row("
                    SELECT 
                        ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'size_mb'
                    FROM information_schema.tables 
                    WHERE table_schema = DATABASE() 
                    AND table_name = '{$table_name}'
                ");
                
                if ($size_query) {
                    $table_sizes[$table] = $size_query->size_mb ?? 0;
                }
            }
            
            return [
                'status' => 'healthy',
                'total_posts' => (int) $total_posts,
                'total_products' => (int) $total_products,
                'total_orders' => (int) $total_orders,
                'table_sizes' => $table_sizes,
                'database_name' => defined('DB_NAME') ? constant('DB_NAME') : 'unknown',
                'last_check' => current_time('mysql')
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error al obtener métricas de base de datos: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene métricas del sistema de archivos
     * 
     * @return array Métricas del sistema de archivos
     */
    public static function getFilesystemMetrics(): array
    {
        try {
            $upload_dir = wp_upload_dir();
            $plugin_dir = defined('MiIntegracionApi_PLUGIN_FILE') ? plugin_dir_path(constant('MiIntegracionApi_PLUGIN_FILE')) : ABSPATH . 'wp-content/plugins/mi-integracion-api/';
            
            // Verificar que las funciones retornen valores válidos
            if (!$upload_dir || !is_array($upload_dir) || !isset($upload_dir['basedir'])) {
                $upload_dir = ['basedir' => ABSPATH . 'wp-content/uploads'];
            }
            
            if (!$plugin_dir) {
                $plugin_dir = ABSPATH . 'wp-content/plugins/mi-integracion-api/';
            }
            
            // Verificar espacio en disco
            $disk_free_space = disk_free_space(ABSPATH);
            $disk_total_space = disk_total_space(ABSPATH);
            $disk_used_space = $disk_total_space - $disk_free_space;
            $disk_usage_percentage = ($disk_total_space > 0) ? ($disk_used_space / $disk_total_space) * 100 : 0;
            
            // Verificar directorios importantes
            $directories = [
                'uploads' => $upload_dir['basedir'],
                'plugin' => $plugin_dir,
                'wordpress' => ABSPATH,
                'logs' => $upload_dir['basedir'] . '/mi-integracion-api/logs'
            ];
            
            $directory_sizes = [];
            foreach ($directories as $name => $path) {
                if (is_dir($path)) {
                    $size = self::getDirectorySize($path);
                    $directory_sizes[$name] = [
                        'path' => $path,
                        'size_mb' => round($size / 1024 / 1024, 2),
                        'exists' => true
                    ];
                } else {
                    $directory_sizes[$name] = [
                        'path' => $path,
                        'size_mb' => 0,
                        'exists' => false
                    ];
                }
            }
            
            return [
                'status' => $disk_usage_percentage > 90 ? 'critical' : ($disk_usage_percentage > 80 ? 'warning' : 'healthy'),
                'disk_total_gb' => round($disk_total_space / 1024 / 1024 / 1024, 2),
                'disk_used_gb' => round($disk_used_space / 1024 / 1024 / 1024, 2),
                'disk_free_gb' => round($disk_free_space / 1024 / 1024 / 1024, 2),
                'disk_usage_percentage' => round($disk_usage_percentage, 1),
                'directory_sizes' => $directory_sizes,
                'last_check' => current_time('mysql')
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error al obtener métricas del sistema de archivos: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene métricas de rendimiento del sistema
     * 
     * @return array Métricas de rendimiento
     */
    public static function getPerformanceMetrics(): array
    {
        try {
            // Obtener información del sistema
            $php_version = PHP_VERSION;
            $wp_version = get_bloginfo('version');
            $plugin_version = defined('MiIntegracionApi_VERSION') ? constant('MiIntegracionApi_VERSION') : 'Unknown';
            
            // Verificar límites del sistema
            $max_execution_time = ini_get('max_execution_time');
            $memory_limit = ini_get('memory_limit');
            $upload_max_filesize = ini_get('upload_max_filesize');
            $post_max_size = ini_get('post_max_size');
            
            // Verificar estado de cron de WordPress
            $cron_status = _get_cron_array();
            $scheduled_tasks = is_array($cron_status) ? count($cron_status) : 0;
            
            // Verificar estado de transients
            $transients_count = self::getTransientsCount();
            
            return [
                'status' => 'healthy',
                'php_version' => $php_version,
                'wordpress_version' => $wp_version,
                'plugin_version' => $plugin_version,
                'max_execution_time' => $max_execution_time,
                'memory_limit' => $memory_limit,
                'upload_max_filesize' => $upload_max_filesize,
                'post_max_size' => $post_max_size,
                'scheduled_tasks' => $scheduled_tasks,
                'transients_count' => $transients_count,
                'last_check' => current_time('mysql')
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error al obtener métricas de rendimiento: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene el estado general de salud del sistema
     * 
     * @return array Estado general de salud
     */
    public static function getOverallSystemHealth(): array
    {
        $metrics = self::getSystemMetrics();
        $issues = [];
        $overall_status = 'healthy';
        
        // Verificar memoria
        if (isset($metrics['memory']['current']) && isset($metrics['memory']['available'])) {
            $memory_usage = $metrics['memory']['current'] / $metrics['memory']['available'];
            if ($memory_usage > 0.9) {
                $issues[] = 'Memoria crítica';
                $overall_status = 'critical';
            } elseif ($memory_usage > self::WARNING_THRESHOLD_RATIOS['production']) {
                $issues[] = 'Memoria alta';
                if ($overall_status !== 'critical') $overall_status = 'warning';
            }
        }
        
        // Verificar reintentos
        if (isset($metrics['retry']['status']) && $metrics['retry']['status'] === 'poor') {
            $issues[] = 'Sistema de reintentos problemático';
            if ($overall_status !== 'critical') $overall_status = 'warning';
        }
        
        // Verificar sincronización
        if (isset($metrics['sync']['status']) && $metrics['sync']['status'] === 'critical') {
            $issues[] = 'Sincronización crítica';
            $overall_status = 'critical';
        }
        
        // Verificar sistema de archivos
        if (isset($metrics['filesystem']['status']) && $metrics['filesystem']['status'] === 'critical') {
            $issues[] = 'Espacio en disco crítico';
            $overall_status = 'critical';
        }
        
        $overall_message = match($overall_status) {
            'critical' => 'El sistema tiene problemas críticos que requieren atención inmediata.',
            'warning' => 'El sistema tiene algunos problemas que requieren monitoreo.',
            default => 'El sistema está funcionando correctamente.'
        };
        
        return [
            'overall_status' => $overall_status,
            'overall_message' => $overall_message,
            'issues' => $issues,
            'last_check' => current_time('mysql')
        ];
    }

    // ============================================================================
    // FASE 3: MÉTODOS PARA CRON JOBS DE MONITORING
    // ============================================================================

    /**
     * Ejecuta monitoreo de memoria para cron jobs
     * Método estático para ser llamado desde cron jobs
     * 
     * @return array Resultado del monitoreo
     */
    public static function executeMemoryMonitoring(): array
    {
        $startTime = microtime(true);
        
        try {
            // FASE 3: Usar logger unificado con logging de rendimiento
            $logger = \MiIntegracionApi\Helpers\Logger::getInstance('memory-monitoring');
            
            // Crear instancia del memory manager
            $memoryManager = new self();
            
            // Obtener métricas de memoria
            $memoryStats = self::getMemoryStats();
            $memoryUsage = $memoryStats['current'] / $memoryStats['available'];
            
            // Verificar memoria
            $memoryCheck = $memoryManager->checkMemory();
            
            // Obtener historial y alertas
            $history = $memoryManager->getMemoryHistory();
            $alerts = $memoryManager->getAlerts();
            
            // FASE 3: Logging optimizado con contexto reducido
            $logger->info("Monitoreo de memoria ejecutado", [
                'usage_percentage' => round($memoryUsage * 100, 2),
                'memory_status' => $memoryCheck ? 'healthy' : 'critical',
                'active_alerts' => count($alerts)
            ]);

            // FASE 1: Alertas solo cuando son críticas
            if (!$memoryCheck) {
                $logger->critical("Memoria crítica detectada", [
                    'usage_percentage' => round($memoryUsage * 100, 2),
                    'current_usage_mb' => $memoryStats['current']
                ]);
            } elseif ($memoryUsage > 0.8) { // FASE 1: Umbral más alto para warnings
                $logger->warning("Uso de memoria alto", [
                    'usage_percentage' => round($memoryUsage * 100, 2)
                ]);
            }

            return [
                'success' => true,
                'memory_status' => $memoryCheck ? 'healthy' : 'critical',
                'memory_stats' => $memoryStats,
                'usage_percentage' => round($memoryUsage * 100, 2),
                'active_alerts' => count($alerts),
                'execution_time' => date('Y-m-d H:i:s')
            ];

        } catch (\Exception $e) {
            // FASE 2: Logging unificado - solo error esencial
            $logger = \MiIntegracionApi\Helpers\Logger::getInstance('memory-monitoring');
            $logger->error("Error en monitoreo de memoria", [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => date('Y-m-d H:i:s')
            ];
        } finally {
            // ✅ DELEGADO: Performance tracking centralizado en SyncMetrics
            if (class_exists('\\MiIntegracionApi\\Core\\SyncMetrics')) {
                try {
                    $duration = microtime(true) - $startTime;
                    $operationId = get_option('mia_operation_id_prefix', 'memory_') . 'monitoring_' . time();
                    
                    $syncMetrics = new \MiIntegracionApi\Core\SyncMetrics();
                    // CORRECCIÓN: Llamada con parámetros correctos según la declaración
                    $syncMetrics->recordBatchMetrics(
                        1,                                                      // $batchNumber (int)
                        0,                                                      // $processedItems (int) - monitoring no procesa items
                        $duration,                                             // $duration (float)
                        0,                                                      // $errors (int) - monitoring no tiene errores
                        0,                                                      // $retryProcessed (int)
                        0                                                       // $retryErrors (int)
                    );
                } catch (\Exception $e) {
                    error_log("MemoryManager performance tracking falló: " . $e->getMessage());
                }
            }
        }
    }

    // ============================================================================
    // MÉTODOS DE INTEGRACIÓN CON HERRAMIENTAS DE MONITOREO
    // ============================================================================

    /**
     * Obtiene mensaje de estado de reintentos
     */
    private static function getRetryStatusMessage(string $status, float $success_rate): string
    {
        return match($status) {
            'excellent' => "Excelente ({$success_rate}%) - Funcionando perfectamente",
            'good' => "Bueno ({$success_rate}%) - Funcionando correctamente",
            'fair' => "Regular ({$success_rate}%) - Considerar ajustes",
            'poor' => "Pobre ({$success_rate}%) - Revisar configuración",
            default => "Desconocido ({$success_rate}%)"
        };
    }

    /**
     * Calcula el tamaño de un directorio en bytes
     */
    private static function getDirectorySize(string $path): int
    {
        $size = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        
        return $size;
    }

    /**
     * Obtiene el conteo de transients de WordPress
     */
    private static function getTransientsCount(): int
    {
        global $wpdb;
        
        try {
            if (!$wpdb || !is_object($wpdb)) {
                return 0;
            }
            
            $count = $wpdb->get_var("
                SELECT COUNT(*) 
                FROM {$wpdb->options} 
                WHERE option_name LIKE '_transient_%'
            ");
            
            return (int) $count;
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * REFACTORIZADO: Método de prueba para verificar la configuración dinámica
     * 
     * @return array Información de configuración actual
     */
    public function getConfigurationInfo(): array
    {
        return [
            'static_config' => [
                'default_memory_limit' => self::DEFAULT_MEMORY_LIMIT,
                'memory_buffer_ratios' => self::MEMORY_BUFFER_RATIOS,
                'warning_threshold_ratios' => self::WARNING_THRESHOLD_RATIOS,
                'critical_threshold_ratios' => self::CRITICAL_THRESHOLD_RATIOS,
                'cleanup_threshold_ratios' => self::CLEANUP_THRESHOLD_RATIOS,
                'max_history_records' => self::MAX_HISTORY_RECORDS,
                'max_alert_records' => self::MAX_ALERT_RECORDS
            ],
            'dynamic_config' => [
                'detected_memory_limit' => self::getDynamicMemoryLimit(),
                'detected_memory_buffer' => self::getDynamicMemoryBuffer(),
                'detected_warning_threshold' => self::getDynamicWarningThreshold(),
                'detected_critical_threshold' => self::getDynamicCriticalThreshold(),
                'detected_cleanup_threshold' => self::getDynamicCleanupThreshold(),
                'environment' => self::isDevelopmentEnvironment() ? 'development' : 'production'
            ],
            'instance_config' => [
                'memory_limit_mb' => $this->memory_limit_mb,
                'warning_threshold' => $this->warning_threshold,
                'critical_threshold' => $this->critical_threshold,
                'cleanup_threshold' => $this->cleanup_threshold
            ],
            'current_stats' => self::getMemoryStats()
        ];
    }

    // ============================================================================
    // MÉTODOS ESTÁTICOS PARA CRON JOBS (DELEGADOS DESDE Sync_Manager)
    // ============================================================================

    /**
     * Ejecuta monitoreo de salud del sistema para cron jobs
     * Método estático para ser llamado desde cron jobs
     * 
     * @return array Resultado del monitoreo
     */
    public static function executeSystemHealthMonitoring(): array
    {
        try {
            $logger = \MiIntegracionApi\Helpers\Logger::getInstance('system-health');
            
            // Obtener métricas del sistema
            $systemMetrics = self::getSystemMetrics();
            $overallHealth = self::getOverallSystemHealth();
            
            // Registrar métricas
            $logger->info("Monitoreo de salud del sistema ejecutado", [
                'overall_status' => $overallHealth['overall_status'],
                'memory_usage' => $systemMetrics['memory']['current'] . 'MB',
                'available_memory' => $systemMetrics['memory']['available'] . 'MB',
                'sync_status' => $systemMetrics['sync']['status'] ?? 'unknown',
                'context' => 'system-health-cron'
            ]);

            // Generar alertas si es necesario
            if ($overallHealth['overall_status'] === 'critical') {
                $logger->critical("Sistema en estado crítico", [
                    'issues' => $overallHealth['issues'] ?? [],
                    'context' => 'system-health-cron'
                ]);
            } elseif ($overallHealth['overall_status'] === 'warning') {
                $logger->warning("Sistema requiere atención", [
                    'issues' => $overallHealth['issues'] ?? [],
                    'context' => 'system-health-cron'
                ]);
            }

            return [
                'success' => true,
                'health_status' => $overallHealth['overall_status'],
                'metrics' => $systemMetrics,
                'execution_time' => date('Y-m-d H:i:s')
            ];

        } catch (\Exception $e) {
            $logger = \MiIntegracionApi\Helpers\Logger::getInstance('system-health');
            $logger->error("Error en monitoreo de salud del sistema", [
                'error' => $e->getMessage(),
                'context' => 'system-health-cron'
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => date('Y-m-d H:i:s')
            ];
        }
    }

    /**
     * Ejecuta optimización adaptativa del sistema para cron jobs
     * Método estático para ser llamado desde cron jobs
     * 
     * @return array Resultado de la optimización
     */
    public static function executeAdaptiveOptimization(): array
    {
        try {
            $logger = \MiIntegracionApi\Helpers\Logger::getInstance('adaptive-optimization');
            
            $logger->info("Iniciando optimización adaptativa del sistema", [
                'context' => 'adaptive-optimization-cron'
            ]);

            $results = [];
            
            // 1. Verificar estado de memoria
            $memoryStats = self::getMemoryStats();
            $memoryUsage = $memoryStats['current'] / $memoryStats['available'];
            
            if ($memoryUsage > 0.7) {
                $cleanupResult = self::cleanup('adaptive-optimization');
                $results['memory_cleanup'] = $cleanupResult;
                $logger->info("Limpieza de memoria ejecutada", [
                    'memory_usage_before' => $memoryUsage,
                    'cleanup_result' => $cleanupResult['success'] ?? false,
                    'context' => 'adaptive-optimization-cron'
                ]);
            }

            $logger->info("Optimización adaptativa completada", [
                'optimizations_performed' => count($results),
                'results' => $results,
                'context' => 'adaptive-optimization-cron'
            ]);

            return [
                'success' => true,
                'optimizations_performed' => count($results),
                'results' => $results,
                'execution_time' => date('Y-m-d H:i:s')
            ];

        } catch (\Exception $e) {
            $logger = \MiIntegracionApi\Helpers\Logger::getInstance('adaptive-optimization');
            $logger->error("Error en optimización adaptativa", [
                'error' => $e->getMessage(),
                'context' => 'adaptive-optimization-cron'
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => date('Y-m-d H:i:s')
            ];
        }
    }
} 