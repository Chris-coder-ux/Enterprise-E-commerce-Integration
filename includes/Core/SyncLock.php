<?php
/**
 * Sistema de Bloqueos para Sincronización
 * 
 * Este archivo implementa un sistema de bloqueos distribuidos para garantizar la ejecución
 * segura de procesos concurrentes en entornos WordPress, incluyendo soporte para WP-CLI y AJAX.
 *
 * @package    MiIntegracionApi
 * @subpackage Core
 * @since      1.0.0
 * @version    1.1.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use Exception;
use InvalidArgumentException;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Core\ContextDetector;
use MiIntegracionApi\Helpers\SyncStatusHelper;
use MiIntegracionApi\ErrorHandling\Exceptions\SyncError;

/**
 * Clase para la gestión de bloqueos distribuidos
 *
 * Implementa un sistema de bloqueos para prevenir condiciones de carrera durante
 * operaciones de sincronización. Incluye características avanzadas como:
 * - Heartbeats para detectar bloqueos huérfanos
 * - Timeouts configurables
 * - Soporte para múltiples intentos de adquisición
 * - Compatibilidad con WordPress CLI y AJAX
 * - Sistema de logging integrado
 *
 * @package MiIntegracionApi\Core
 * @since   1.0.0
 */
class SyncLock
{
    /**
     * Prefijo para las claves de bloqueo
     * @var string
     * @since 1.0.0
     */
    private const LOCK_PREFIX = 'mia_sync_lock_';
    
    /**
     * Obtiene una instancia centralizada de Logger
     * Aplica patrón Singleton para evitar instanciaciones duplicadas
     * @param string $category Categoría del logger
     * @return \MiIntegracionApi\Logging\Core\LoggerBasic
     * @since 1.1.0
     */
    private static function getCentralizedLogger(string $category): \MiIntegracionApi\Logging\Core\LoggerBasic {
        // Usar el plugin principal para obtener Logger centralizado
        if (class_exists('\MiIntegracionApi\Core\MiIntegracionApi')) {
            $mainPlugin = new \MiIntegracionApi\Core\MiIntegracionApi();
            if ($mainPlugin) {
                $centralizedLogger = $mainPlugin->getComponent('logger');
                if ($centralizedLogger) {
                    // Si es un LogManager, obtener la instancia del Logger
                    if ($centralizedLogger instanceof \MiIntegracionApi\Logging\Core\LogManager) {
                        return $centralizedLogger->getLogger('sync-lock');
                    }
                    // Si ya es un Logger, devolverlo directamente
                    return $centralizedLogger;
                }
            }
        }
        
        // Fallback: crear instancia directa solo si no hay plugin principal
        return new Logger($category);
    }
    
    /**
     * Tiempo de expiración por defecto del bloqueo (1 hora)
     * 
     * @var int
     * @since 1.0.0
     */
    private const DEFAULT_TIMEOUT = 3600;
    
    /**
     * Tiempo de espera entre reintentos (5 segundos)
     * 
     * @var int
     * @since 1.0.0
     */
    private const DEFAULT_RETRY_DELAY = 5;
    
    /**
     * Número máximo de reintentos para adquirir un bloqueo
     * 
     * @var int
     * @since 1.0.0
     */
    private const MAX_RETRIES = 3;
    
    /**
     * Intervalo de heartbeat en segundos (1 minuto)
     * 
     * @var int
     * @since 1.1.0
     */
    private const HEARTBEAT_INTERVAL = 60;
    
    /**
     * Tiempo máximo sin heartbeat antes de considerar el bloqueo como huérfano (5 minutos)
     * 
     * @var int
     * @since 1.1.0
     */
    private const HEARTBEAT_TIMEOUT = 300;

    /**
     * Tiempo máximo para detectar deadlocks (10 minutos)
     * 
     * @var int
     * @since 2.1.0
     */
    private const DEADLOCK_DETECTION_TIMEOUT = 600;
    
    /**
     * TTL del cache unificado de estado de locks (30 segundos)
     * 
     * @var int
     * @since 2.4.0
     */
    private const UNIFIED_LOCK_CACHE_TTL = 30;

    /**
     * Intenta adquirir un bloqueo para la entidad especificada
     *
     * Este método implementa un patrón de bloqueo optimista con reintentos.
     * Si el bloqueo está ocupado, espera un tiempo configurable antes de reintentar.
     *
     * @param string $entity Identificador único de la entidad a bloquear
     * @param int $timeout Tiempo máximo de bloqueo en segundos (predeterminado: 1 hora)
     * @param int $retries Número máximo de intentos (predeterminado: 3)
     * @param array $context Contexto adicional para el bloqueo (opcional)
     * @return bool true si se adquirió el bloqueo, false en caso contrario
     * @throws InvalidArgumentException Si los parámetros no son válidos
     * @since 1.0.0
     * @example
     * // Adquirir un bloqueo con configuración predeterminada
     * $locked = SyncLock::acquire('mi_entidad');
     * if ($locked) {
     *     try {
     *         // Código protegido por el bloqueo
     *     } finally {
     *         SyncLock::release('mi_entidad');
     *     }
     * }
     */
    public static function acquire(
        string $entity,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $retries = self::MAX_RETRIES,
        array $context = []
    ): bool {
        if (empty($entity)) {
            return false;
        }

        $key = self::getLockKey($entity);
        $attempt = 0;

        while ($attempt < $retries) {
            // Asegurar que la tabla de locks existe
            self::ensureLockTableExists();
            
            // MEJORADO: Verificar y limpiar integridad del sistema de locks
            if ($attempt === 0) {
                // Verificar si ya existe un lock en transients antes de intentar tabla
                $existing_transient = get_transient($key);
                if ($existing_transient !== false) {
                    $logger = self::getCentralizedLogger('sync-lock');
                    $logger->info(
                        "Lock existente encontrado en transients, migrando a tabla",
                        [
                            'entity' => $entity,
                            'transient_data' => $existing_transient,
                            'category' => "sync-lock-$entity"
                        ]
                    );
                }
                
                self::checkRealTimeDeadlocks($entity);
                self::detectAndResolveDeadlocks($entity);
                self::handleConcurrentLockSystems($entity);
            }
            
            // MEJORADO: Usar método atómico para evitar condiciones de carrera
            // Este método verifica y crea el lock en una sola operación atómica
            $lock_start_time = microtime(true);
            if (self::createLockInTableAtomic($entity, $timeout, $context)) {
                $lock_duration = microtime(true) - $lock_start_time;
                $wait_time = $attempt * self::DEFAULT_RETRY_DELAY;
                
                $logger = self::getCentralizedLogger('sync-lock');
                $logger->info(
                    "Bloqueo adquirido atómicamente en tabla",
                    [
                        'entity' => $entity,
                        'timeout_seconds' => $timeout,
                        'operation_duration_seconds' => round($lock_duration, 4),
                        'wait_time_seconds' => $wait_time,
                        'pid' => getmypid(),
                        'attempt' => $attempt + 1,
                        'category' => "sync-lock-$entity",
                        'memory_usage' => memory_get_usage(true),
                        'timestamp' => time()
                    ]
                );
                
                // MEJORA: También crear el lock en transients para compatibilidad
                $lockData = [
                    'entity' => $entity,
                    'timeout' => $timeout,
                    'timestamp' => time(),
                    'pid' => getmypid(),
                    'context' => $context,
                    'last_heartbeat' => time()
                ];
                set_transient($key, $lockData, $timeout);
                
                return true;
            }
            
            // Si no se pudo crear el lock, verificar por qué
            $existingLock = self::getLockFromTable($entity);
            
            if ($existingLock['active'] === true) {
                // Verificar si el bloqueo ha expirado
                if (time() > (strtotime($existingLock['expires_at']) ?: 0)) {
                    $logger = self::getCentralizedLogger('sync-lock');
                    $logger->info(
                        "Bloqueo expirado detectado, liberando",
                        [
                            'entity' => $entity,
                            'expires_at' => $existingLock['expires_at'],
                            'category' => "sync-lock-$entity"
                        ]
                    );
                    self::release($entity);
                    continue;
                }

                // Verificar si el proceso que creó el bloqueo sigue activo
                $pid = isset($existingLock['pid']) ? (int)$existingLock['pid'] : 0;
                $lock_age = time() - (strtotime($existingLock['acquired_at']) ?: time());
                $time_until_expiry = (strtotime($existingLock['expires_at']) ?: time()) - time();

                $logger = self::getCentralizedLogger('sync-lock');
                if ($pid > 0 && self::isProcessActive($pid)) {
                    $logger->warning(
                        "Bloqueo activo encontrado en tabla",
                        [
                            'entity' => $entity,
                            'owner' => $existingLock['lock_owner'],
                            'pid' => $pid,
                            'lock_age_seconds' => $lock_age,
                            'time_until_expiry_seconds' => $time_until_expiry,
                            'acquired_at' => $existingLock['acquired_at'],
                            'expires_at' => $existingLock['expires_at'],
                            'attempt' => $attempt + 1,
                            'category' => "sync-lock-$entity",
                            'wait_time_seconds' => $attempt * self::DEFAULT_RETRY_DELAY,
                            'memory_usage' => memory_get_usage(true)
                        ]
                    );
                } else {
                    // El proceso ya no está activo, liberar el bloqueo
                    $logger->info(
                        "Liberando bloqueo de proceso inactivo",
                        [
                            'entity' => $entity,
                            'owner' => $existingLock['lock_owner'],
                            'pid' => $pid,
                            'attempt' => $attempt + 1,
                            'category' => "sync-lock-$entity"
                        ]
                    );
                    self::release($entity);
                    continue;
                }
            } else {
                // No se pudo obtener información del lock existente
                $logger = self::getCentralizedLogger('sync-lock');
                $logger->warning(
                    "No se pudo obtener información del lock existente",
                    [
                        'entity' => $entity,
                        'attempt' => $attempt + 1,
                        'category' => "sync-lock-$entity"
                    ]
                );
            }

            $attempt++;
            if ($attempt < $retries) {
                // SISTEMA DE REINTENTOS CON BACKOFF EXPONENCIAL MEJORADO
                // Base delay: 1 segundo, multiplicador: 2, máximo: 30 segundos
                $base_delay = 1; // 1 segundo base
                $exponential_delay = $base_delay * pow(2, $attempt - 1);
                $jitter = rand(0, 1000) / 1000; // Jitter aleatorio de 0-1 segundo
                $delay = min($exponential_delay + $jitter, 30); // Máximo 30 segundos
                $delay_int = (int) round($delay); // Convertir a entero para sleep()
                
                $logger = self::getCentralizedLogger('sync-lock');
                $logger->info("Reintentando adquisición de lock con backoff exponencial", [
                    'entity' => $entity,
                    'attempt' => $attempt + 1,
                    'max_retries' => $retries,
                    'delay_seconds' => round($delay, 2),
                    'exponential_delay' => $exponential_delay,
                    'jitter' => $jitter,
                    'category' => "sync-lock-$entity"
                ]);
                
                sleep($delay_int);
            }
        }

        // Lanzar excepción de concurrencia después de agotar todos los reintentos
        throw SyncError::concurrencyError(
            'No se pudo adquirir el lock después de múltiples intentos',
            [
                'entity' => $entity,
                'timeout' => $timeout,
                'retries' => $retries,
                'attempts_made' => $attempt,
                'lock_key' => $key
            ]
        );
    }

    /**
     * Libera un bloqueo previamente adquirido
     *
     * Este método elimina el bloqueo asociado a la entidad especificada,
     * permitiendo que otros procesos puedan adquirirlo.
     *
     * @param string $entity Identificador único de la entidad a desbloquear
     * @return bool true si se liberó el bloqueo, false en caso de error
     * @throws InvalidArgumentException Si el nombre de la entidad está vacío
     * @since 1.0.0
     * @example
     * // Liberar un bloqueo
     * SyncLock::release('mi_entidad');
     */
    public static function release(string $entity): bool
    {
        if (empty($entity)) {
            return false;
        }

        // Asegurar que la tabla de locks existe
        self::ensureLockTableExists();
        
        // Liberar bloqueo de la tabla
        $release_start_time = microtime(true);
        $table_released = self::deleteLockFromTable($entity);
        
        // También liberar el transient para compatibilidad
        $key = self::getLockKey($entity);
        $transient_released = delete_transient($key);
        
        if ($table_released || $transient_released) {
            $release_duration = microtime(true) - $release_start_time;
            $logger = self::getCentralizedLogger('sync-lock');
            $logger->info(
                "Bloqueo liberado",
                [
                    'entity' => $entity,
                    'operation_duration_seconds' => round($release_duration, 4),
                    'pid' => getmypid(),
                    'category' => "sync-lock-$entity",
                    'memory_usage' => memory_get_usage(true),
                    'timestamp' => time(),
                    'table_released' => $table_released,
                    'transient_released' => $transient_released
                ]
            );
            return true;
        }

        return false;
    }

    /**
     * Verifica si existe un bloqueo activo para la entidad especificada
     *
     * Realiza comprobaciones adicionales para detectar bloqueos huérfanos:
     * - Verifica si el proceso que creó el bloqueo sigue activo
     * - Comprueba si el bloqueo ha expirado
     * - Valida el último heartbeat recibido
     *
     * @param string $entity Identificador único de la entidad a verificar
     * @return bool true si el bloqueo está activo, false en caso contrario
     * @throws InvalidArgumentException Si el nombre de la entidad está vacío
     * @since 1.0.0
     */
    public static function isLocked(string $entity): bool
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getLockKey($entity);
        $lockData = get_transient($key);

        if ($lockData === false) {
            return false;
        }

        // Verificar si el bloqueo ha expirado
        if (time() - $lockData['timestamp'] > $lockData['timeout']) {
            self::release($entity);
            return false;
        }

        // Verificar si el proceso que creó el bloqueo sigue activo
        // En CLI, ser más tolerante con la verificación de procesos
        if (!self::isProcessActive($lockData['pid'])) {
            // En contexto CLI, dar una segunda oportunidad si el heartbeat es reciente
            $is_cli = (php_sapi_name() === 'cli');
            $recent_heartbeat = isset($lockData['last_heartbeat']) && 
                                (time() - $lockData['last_heartbeat']) < 120; // 2 minutos
            
            if (!$is_cli || !$recent_heartbeat) {
                self::release($entity);
                return false;
            }
        }

        // Verificar heartbeat
    if (isset($lockData['last_heartbeat']) && 
        time() - $lockData['last_heartbeat'] > self::HEARTBEAT_TIMEOUT) {
            $logger = self::getCentralizedLogger('sync-lock');
            $logger->warning(
                "Bloqueo expirado por falta de heartbeat",
                [
                    'entity' => $entity,
                    'pid' => $lockData['pid'],
            'last_heartbeat' => $lockData['last_heartbeat'],
                    'category' => "sync-lock-$entity"
                ]
            );
            self::release($entity);
            return false;
        }

        return true;
    }

    /**
     * Obtiene información detallada sobre un bloqueo existente
     *
     * La información devuelta incluye:
     * - Entidad bloqueada
     * - Marca de tiempo de adquisición
     * - Tiempo restante del bloqueo
     * - PID del proceso propietario
     * - Estado de actividad del proceso
     * - Último heartbeat recibido
     * - Contexto adicional
     *
     * @param string $entity Identificador único de la entidad
     * @return array<string, mixed> Array con la información del bloqueo
     * @throws InvalidArgumentException Si el nombre de la entidad está vacío
     * @since 1.0.0
     */
    public static function getLockInfo(string $entity): array
    {
        if (empty($entity)) {
            return [
                'active' => false,
                'lock_key' => null,
                'acquired_at' => null,
                'expires_at' => null,
                'entity' => $entity
            ];
        }

        // CORRECCIÓN: Migrar de transients a base de datos
        $lock_data = self::getLockFromTable($entity);
        
        // CORRECCIÓN CRÍTICA: Siempre devolver array, nunca false
        if ($lock_data['active'] !== true) {
            return [
                'active' => false,
                'lock_key' => null,
                'acquired_at' => null,
                'expires_at' => null,
                'entity' => $entity
            ];
        }
        
        return $lock_data;
    }

    /**
     * Verifica si un proceso está activo
     *
     * Implementa lógica especial para entornos WordPress:
     * - En modo CLI o AJAX, es más permisivo con la verificación de PIDs
     * - En otros entornos, realiza una verificación estricta del proceso
     *
     * @param int $pid ID del proceso a verificar
     * @return bool true si el proceso está activo, false en caso contrario
     * @since 1.0.0
     */
    public static function isProcessActive(int $pid): bool
    {
        // CORRECCIÓN: En contexto WordPress/AJAX, ser más flexible con la verificación de PID
        // porque cada petición AJAX crea un nuevo proceso PHP
        
        // En contexto CLI de WordPress, permitir continuidad entre diferentes PIDs
        $is_wp_context = self::isWordPressContext();
        $is_cli = defined('WP_CLI') && constant('WP_CLI');
        $is_ajax = defined('DOING_AJAX') && constant('DOING_AJAX');
        
        if ($is_wp_context && ($is_cli || $is_ajax)) {
            // En WordPress CLI/AJAX, verificar de manera más inteligente
            if ($pid <= 0) {
                return false;
            }
            
            // MEJORA: En lugar de siempre retornar true, verificar si el PID
            // es razonablemente reciente (menos de 1 hora de diferencia)
            $current_pid = getmypid();
            $pid_difference = abs($current_pid - $pid);
            
            // Si la diferencia es muy grande, probablemente es un PID obsoleto
            if ($pid_difference > 10000) {
                return false;
            }
            
            // En WordPress, ser más permisivo pero no completamente
            return true;
        }
        
        // Verificación estricta solo en contextos no-WordPress
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output);
            return count($output) > 1;
        }

        return file_exists("/proc/$pid");
    }

    /**
     * Verifica si estamos en contexto WordPress
     *
     * @return bool true si estamos en contexto WordPress, false en caso contrario
     * @since 1.2.0
     */
    private static function isWordPressContext(): bool
    {
        $contextDetector = ContextDetector::getInstance();
        $context = $contextDetector->detect();
        return $context !== ContextDetector::CONTEXT_UNKNOWN;
    }

    /**
     * Sistema de reintentos con backoff exponencial para operaciones de lock
     * 
     * Este método implementa un patrón robusto de reintentos que:
     * - Usa backoff exponencial para reducir contención
     * - Incluye jitter aleatorio para evitar "thundering herd"
     * - Tiene límites máximos para evitar esperas excesivas
     * - Registra cada intento para debugging
     * 
     * @param callable $operation Función a ejecutar (debe retornar bool)
     * @param string $entity Identificador de la entidad (para logging)
     * @param int $max_retries Número máximo de reintentos (default: 3)
     * @param int $base_delay Delay base en segundos (default: 1)
     * @param int $max_delay Delay máximo en segundos (default: 30)
     * @return bool true si la operación fue exitosa, false si falló después de todos los reintentos
     * @since 2.4.0
     */
    public static function retryWithExponentialBackoff(
        callable $operation,
        string $entity,
        int $max_retries = 3,
        int $base_delay = 1,
        int $max_delay = 30
    ): bool {
        $logger = self::getCentralizedLogger('sync-lock-retry');
        
        for ($attempt = 0; $attempt < $max_retries; $attempt++) {
            // Intentar la operación
            $result = $operation();
            
            if ($result === true) {
                if ($attempt > 0) {
                    $logger->info("Operación exitosa después de reintentos", [
                        'entity' => $entity,
                        'attempts' => $attempt + 1,
                        'max_retries' => $max_retries,
                        'category' => "sync-lock-retry-$entity"
                    ]);
                }
                return true;
            }
            
            // Si no es el último intento, calcular delay y esperar
            if ($attempt < $max_retries - 1) {
                $exponential_delay = $base_delay * pow(2, $attempt);
                $jitter = rand(0, 1000) / 1000; // Jitter aleatorio de 0-1 segundo
                $delay = min($exponential_delay + $jitter, $max_delay);
                $delay_int = (int) round($delay); // Convertir a entero para sleep()
                
                $logger->info("Reintentando operación con backoff exponencial", [
                    'entity' => $entity,
                    'attempt' => $attempt + 1,
                    'max_retries' => $max_retries,
                    'delay_seconds' => round($delay, 2),
                    'exponential_delay' => $exponential_delay,
                    'jitter' => $jitter,
                    'category' => "sync-lock-retry-$entity"
                ]);
                
                sleep($delay_int);
            }
        }
        
        $logger->warning("Operación falló después de todos los reintentos", [
            'entity' => $entity,
            'attempts' => $max_retries,
            'category' => "sync-lock-retry-$entity"
        ]);
        
        return false;
    }

    /**
     * Actualiza los timestamps de un bloqueo
     * 
     * Centraliza la lógica de actualización de timestamps que estaba
     * duplicada en updateHeartbeat() y extendLock().
     *
     * @param array $lockData Datos del bloqueo (se modifica por referencia)
     * @since 1.2.0
     */
    private static function updateLockTimestamps(array &$lockData): void
    {
        $now = time();
        $lockData['last_heartbeat'] = $now;
        $lockData['timestamp'] = $now;
    }

    /**
     * Actualiza el PID si es necesario según el contexto de ejecución
     *
     * Este método centraliza la lógica de verificación y actualización de PID
     * que estaba duplicada en updateHeartbeat() y extendLock(). Utiliza
     * ContextDetector para determinar el contexto de ejecución de manera robusta.
     *
     * @param array $lockData Datos del bloqueo (se modifica por referencia)
     * @param string $entity Identificador de la entidad
     * @return bool true si el proceso puede continuar, false si debe liberar el bloqueo
     * @since 1.2.0
     */
    private static function updatePidIfNeeded(array &$lockData, string $entity): bool
    {
        $current_pid = getmypid();
        
        // Usar ContextDetector para verificación robusta del contexto
        $contextDetector = ContextDetector::getInstance();
        $context = $contextDetector->detect();
        $is_wp_context = $context !== ContextDetector::CONTEXT_UNKNOWN;
        
        if ($is_wp_context && $lockData['pid'] !== $current_pid) {
            // En contexto WordPress (AJAX/CLI), actualizar PID
            $lockData['pid'] = $current_pid;
            return true;
        }
        
        if (!$is_wp_context && !self::isProcessActive($lockData['pid'])) {
            // En contexto no-WordPress, verificar si el proceso sigue activo
            self::release($entity);
            return false;
        }
        
        return true;
    }

    /**
     * Genera una clave única para identificar el bloqueo
     *
     * La clave se genera aplicando sanitización al nombre de la entidad
     * y añadiendo un prefijo para evitar colisiones.
     *
     * @param string $entity Identificador de la entidad
     * @return string Clave única para el bloqueo
     * @throws InvalidArgumentException Si el nombre de la entidad está vacío
     * @since 1.0.0
     */
    private static function getLockKey(string $entity): string
    {
        return self::LOCK_PREFIX . sanitize_key($entity);
    }

    /**
     * Actualiza el heartbeat de un bloqueo existente
     *
     * Este método debe llamarse periódicamente para indicar que el bloqueo
     * sigue en uso. También puede extender la duración del bloqueo si es necesario.
     *
     * @param string $entity Identificador de la entidad
     * @param int $extend_timeout Tiempo adicional para extender el bloqueo (opcional)
     * @return bool true si se actualizó el heartbeat, false en caso de error
     * @throws InvalidArgumentException Si el nombre de la entidad está vacío
     * @since 1.1.0
     */
    public static function updateHeartbeat(string $entity, int $extend_timeout = 0): bool
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getLockKey($entity);
        $lockData = get_transient($key);

        if ($lockData === false) {
            return false;
        }

        // REFACTORIZADO: Usar método centralizado para verificación y actualización de PID
        if (!self::updatePidIfNeeded($lockData, $entity)) {
            return false;
        }

        // Actualizar timestamp y extender el bloqueo
        self::updateLockTimestamps($lockData);
        
        // Extender timeout si se especifica (útil para operaciones largas)
        $timeout = max($lockData['timeout'], $extend_timeout);
        $lockData['timeout'] = $timeout;

        // Uso correcto: método no estático sobre instancia
        $logger = self::getCentralizedLogger('sync-lock');
        // Logger siempre tiene debug() - sin fallback
        $logger->debug(
            "Heartbeat actualizado" . ($extend_timeout > 0 ? " y extendido" : ""),
            [
                'entity' => $entity,
                'pid' => $lockData['pid'],
                'lock_age_seconds' => isset($lockData['acquired_at']) ? (time() - $lockData['acquired_at']) : 0,
                'timeout_seconds' => $timeout,
                'extended_seconds' => max(0, $extend_timeout),
                'category' => "sync-lock-$entity"
            ]
        );

        return set_transient($key, $lockData, $timeout);
    }

    /**
     * Inicia el proceso de heartbeat periódico para un bloqueo
     *
     * Este método configura el timestamp inicial del heartbeat
     * que será actualizado periódicamente por updateHeartbeat().
     *
     * @param string $entity Identificador de la entidad
     * @return bool true si se inició el heartbeat, false en caso de error
     * @throws InvalidArgumentException Si el nombre de la entidad está vacío
     * @since 1.1.0
     */
    public static function startHeartbeat(string $entity): bool
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getLockKey($entity);
        $lockData = get_transient($key);

        if ($lockData === false) {
            return false;
        }

        // Agregar timestamp de heartbeat
        $lockData['last_heartbeat'] = time();

        $logger = self::getCentralizedLogger('sync-lock');
        $logger->info(
            "Iniciando heartbeat",
            [
                'entity' => $entity,
                'pid' => $lockData['pid'],
                'interval' => self::HEARTBEAT_INTERVAL,
                'category' => "sync-lock-$entity"
            ]
        );

        // OPTIMIZACIÓN: Iniciar proceso automático de heartbeat si está disponible
        $heartbeat_started = self::startHeartbeatProcess($entity);
        if ($heartbeat_started) {
            $logger->info(
                "Proceso automático de heartbeat iniciado",
                [
                    'entity' => $entity,
                    'category' => "sync-lock-$entity"
                ]
            );
        } else {
            $logger->warning(
                "No se pudo iniciar proceso automático de heartbeat, usando heartbeat manual",
                [
                    'entity' => $entity,
                    'category' => "sync-lock-$entity"
                ]
            );
        }

        return set_transient($key, $lockData, $lockData['timeout']);
    }

    /**
     * Inicia el proceso automático de heartbeat para una entidad
     * 
     * @param string $entity Identificador de la entidad
     * @return bool true si se inició el proceso, false en caso contrario
     * @since 1.2.0
     */
    private static function startHeartbeatProcess(string $entity): bool
    {
        try {
            // Verificar si HeartbeatProcess está disponible
            if (!class_exists('\MiIntegracionApi\Core\HeartbeatProcess')) {
                return false;
            }

            // Verificar si WP_Background_Process está disponible
            if (!class_exists('\WP_Background_Process')) {
                return false;
            }

            // Crear e iniciar el proceso de heartbeat
            $heartbeat_process = new HeartbeatProcess($entity);
            $heartbeat_process->start();

            return true;

        } catch (Exception $e) {
            $logger = self::getCentralizedLogger('sync-lock');
            $logger->warning(
                "Error al iniciar proceso de heartbeat",
                [
                    'entity' => $entity,
                    'error' => $e->getMessage(),
                    'category' => "sync-lock-$entity"
                ]
            );
            return false;
        }
    }

    /**
     * Extiende un bloqueo existente para operaciones largas
     * 
     * @param string $entity Nombre de la entidad
     * @param int $additional_time Tiempo adicional en segundos
     * @return bool Éxito de la operación
     */
    public static function extendLock(string $entity, int $additional_time = 3600): bool
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getLockKey($entity);
        $lockData = get_transient($key);

        if ($lockData === false) {
            return false;
        }

        // REFACTORIZADO: Usar método centralizado para verificación y actualización de PID
        if (!self::updatePidIfNeeded($lockData, $entity)) {
            return false;
        }

        // Extender el timeout
        self::updateLockTimestamps($lockData);
        $lockData['timeout'] = $lockData['timeout'] + $additional_time;

        $logger = self::getCentralizedLogger('sync-lock');
        $logger->info(
            "Lock extendido para operación larga",
            [
                'entity' => $entity,
                'pid' => $lockData['pid'],
                'additional_time_seconds' => $additional_time,
                'new_timeout_seconds' => $lockData['timeout'],
                'category' => "sync-lock-$entity"
            ]
        );

        return set_transient($key, $lockData, $lockData['timeout']);
    }

    
    /**
     * Asegura que la tabla de locks existe
     */
    private static function ensureLockTableExists(): void
    {
        if (class_exists('MiIntegracionApi\\Core\\Installer')) {
            Installer::create_sync_lock_table();
        }
    }
    
    /**
     * Verifica si la tabla tiene la columna pid
     * 
     * @param string $table_name Nombre de la tabla
     * @return bool True si tiene la columna pid
     * @since 1.4.0
     */
    private static function tableHasPidColumn(string $table_name): bool {
        global $wpdb;
        
        if (!$wpdb) {
            return false;
        }
        
        // Verificar si la columna pid existe
        $column_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '$table_name' 
             AND COLUMN_NAME = 'pid'"
        );
        
        return $column_exists > 0;
    }
    
    /**
     * Migra la tabla si no tiene la columna pid
     * 
     * @return void
     * @since 1.4.0
     */
    private static function migrateTableIfNeeded(): void {
        global $wpdb;
        
        if (!$wpdb) {
            return;
        }
        
        $table_name = $wpdb->prefix . 'mia_sync_lock';
        
        // Verificar si la tabla existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return; // Tabla no existe, se creará con la estructura correcta
        }
        
        // Verificar si ya tiene la columna pid
        if (self::tableHasPidColumn($table_name)) {
            return; // Ya tiene la columna
        }
        
        // Migrar la tabla agregando la columna pid
        try {
            $result = $wpdb->query(
                "ALTER TABLE $table_name 
                 ADD COLUMN pid int UNSIGNED DEFAULT NULL,
                 ADD KEY pid (pid)"
            );
            
            if ($result !== false) {
                $logger = self::getCentralizedLogger('sync-lock-migration');
                $logger->info('Tabla mia_sync_lock migrada exitosamente, columna pid agregada', [
                    'table_name' => $table_name
                ]);
            }
        } catch (Exception $e) {
            $logger = self::getCentralizedLogger('sync-lock-migration');
            $logger->error('Error migrando tabla mia_sync_lock', [
                'table_name' => $table_name,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Obtiene un lock de la tabla
     */
    private static function getLockFromTable(string $entity): array
    {
        global $wpdb;
        
        // CORRECCIÓN 1: Verificar entorno WordPress antes de continuar
        if (!function_exists('get_transient')) {
            return [
                'active' => false,
                'lock_key' => null,
                'acquired_at' => null,
                'expires_at' => null,
                'entity' => $entity
            ];
        }
        
        // CORRECCIÓN 2: Verificación de wpdb más permisiva
        if (!$wpdb) {
            return [
                'active' => false,
                'lock_key' => null,
                'acquired_at' => null,
                'expires_at' => null,
                'entity' => $entity
            ];
        }
        
        // CORRECCIÓN 3: Asegurar que la tabla existe antes de consultar
        self::ensureLockTableExists();
        
        // CORRECCIÓN 4: Migrar tabla si no tiene la columna pid
        self::migrateTableIfNeeded();
        
        $table_name = $wpdb->prefix . 'mia_sync_lock';
        
        // Verificar que la tabla existe después de intentar crearla
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        if (!$table_exists) {
            // CORRECCIÓN 4: Eliminar transients - solo tabla de BD
            return [
                'active' => false,
                'lock_key' => null,
                'acquired_at' => null,
                'expires_at' => null,
                'entity' => $entity
            ];
        }
        
        $lock = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE lock_key = %s",
                $entity
            ),
            'ARRAY_A'
        );
        
        // CORRECCIÓN: Eliminar sistema dual - solo tabla de BD
        if (!$lock) {
            return [
                'active' => false,
                'lock_key' => null,
                'acquired_at' => null,
                'expires_at' => null,
                'entity' => $entity
            ];
        }
        
        // CORRECCIÓN: Asegurar que el lock tiene la estructura correcta
        return [
            'active' => true,
            'lock_key' => $lock['lock_key'] ?? $entity,
            'acquired_at' => $lock['acquired_at'] ?? null,
            'expires_at' => $lock['expires_at'] ?? null,
            'entity' => $entity,
            'pid' => $lock['pid'] ?? null,
            'context' => $lock['context'] ?? []
        ];
    }


    /**
     * Crea un lock en la tabla de manera atómica con verificación de unicidad
     * Método alternativo que maneja mejor las condiciones de carrera
     */
    private static function createLockInTableAtomic(string $entity, int $timeout, array $context = []): bool
    {
        global $wpdb;
        
        // Verificar si $wpdb está disponible (entorno de prueba)
        if (!$wpdb || !method_exists($wpdb, 'insert')) {
            return false; // Retornar false en entorno de prueba
        }
        
        $table_name = $wpdb->prefix . 'mia_sync_lock';
        
        // CORRECCIÓN: Migrar tabla si no tiene la columna pid
        self::migrateTableIfNeeded();
        
        $now = current_time('mysql');
        $expiresAt = date('Y-m-d H:i:s', time() + $timeout);
        
        // MEJORADO: Usar transacción para operación atómica
        $wpdb->query('START TRANSACTION');
        
        try {
            // Verificar si ya existe un lock activo
            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name 
                     WHERE lock_key = %s AND expires_at > NOW()",
                    $entity
                )
            );
            
            if ($existing > 0) {
                $wpdb->query('ROLLBACK');
                // Lanzar excepción de concurrencia cuando ya existe un lock activo
                throw SyncError::concurrencyError(
                    'Lock ya adquirido por otro proceso',
                    [
                        'entity' => $entity,
                        'lock_key' => $entity,
                        'existing_locks' => $existing,
                        'table_name' => $table_name
                    ]
                );
            }
            
            // Verificar si la tabla tiene la columna pid
            $has_pid_column = self::tableHasPidColumn($table_name);
            
            // Preparar datos para insertar
            $insert_data = [
                'lock_key' => $entity,
                'lock_type' => 'sync',
                'lock_data' => json_encode($context),
                'acquired_at' => $now,
                'expires_at' => $expiresAt,
                'lock_owner' => (string)getmypid()
            ];
            
            $insert_format = ['%s', '%s', '%s', '%s', '%s', '%s'];
            
            // Agregar pid solo si la columna existe
            if ($has_pid_column) {
                $insert_data['pid'] = getmypid();
                $insert_format[] = '%d';
            }
            
            // Crear el nuevo lock
            $result = $wpdb->insert(
                $table_name,
                $insert_data,
                $insert_format
            );
            
            if ($result === false) {
                $wpdb->query('ROLLBACK');
                return false;
            }
            
            $wpdb->query('COMMIT');
            return true;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $logger = self::getCentralizedLogger('sync-lock');
            $logger->error('Error en transacción de lock', [
                'entity' => $entity,
                'error' => $e->getMessage(),
                'category' => "sync-lock-$entity"
            ]);
            return false;
        }
    }
    
    /**
     * Elimina un lock de la tabla
     */
    private static function deleteLockFromTable(string $entity): bool
    {
        global $wpdb;
        
        // Verificar si $wpdb está disponible (entorno de prueba)
        if (!$wpdb || !method_exists($wpdb, 'delete')) {
            return false; // Retornar false en entorno de prueba
        }
        
        $table_name = $wpdb->prefix . 'mia_sync_lock';
        
        $result = $wpdb->delete(
            $table_name,
            ['lock_key' => $entity],
            ['%s']
        );
        
        return $result !== false;
    }

    /**
     * Verifica y limpia locks huérfanos antes de intentar adquirir uno nuevo
     * 
     * Este método centraliza toda la lógica de limpieza de locks para evitar
     * que locks de sincronizaciones fallidas bloqueen nuevas sincronizaciones.
     * 
     * @param string $entity Identificador único de la entidad
     * @param int $maxLockAge Tiempo máximo en segundos para considerar un lock como huérfano (default: 2 horas)
     * @return array Resultado de la verificación:
     *   - 'can_proceed' (bool): Si se puede proceder con la adquisición del lock
     *   - 'lock_cleaned' (bool): Si se limpió un lock huérfano
     *   - 'lock_info' (array|false): Información del lock si existe
     *   - 'reason' (string): Razón por la que no se puede proceder (si aplica)
     * 
     * @since 1.1.0
     */
    public static function checkAndCleanOrphanedLock(string $entity, int $maxLockAge = 7200): array
    {
        if (empty($entity)) {
            return [
                'can_proceed' => false,
                'lock_cleaned' => false,
                'lock_info' => false,
                'reason' => 'Entity name is empty'
            ];
        }

        $logger = self::getCentralizedLogger('sync-lock-cleanup');
        
        // Verificar si existe un lock
        if (!self::isLocked($entity)) {
            return [
                'can_proceed' => true,
                'lock_cleaned' => false,
                'lock_info' => false,
                'reason' => 'No lock exists'
            ];
        }

        // Obtener información del lock existente
        $lockInfo = self::getLockInfo($entity);
        if (!$lockInfo) {
            // Lock existe pero no se puede obtener info, liberarlo por seguridad
            $logger->warning('Lock existe pero no se puede obtener información, liberando por seguridad', [
                'entity' => $entity
            ]);
            self::release($entity);
            return [
                'can_proceed' => true,
                'lock_cleaned' => true,
                'lock_info' => false,
                'reason' => 'Lock existed but info was invalid, cleaned'
            ];
        }

        // Verificar si el lock es huérfano basado en la edad
        $lockAge = time() - (strtotime($lockInfo['acquired_at']) ?: time());
        $isOrphaned = $lockAge > $maxLockAge;

        if ($isOrphaned) {
            $logger->info('Lock huérfano detectado, liberando', [
                'entity' => $entity,
                'lock_age_seconds' => $lockAge,
                'max_age_seconds' => $maxLockAge,
                'lock_info' => $lockInfo
            ]);
            
            self::release($entity);
            
            return [
                'can_proceed' => true,
                'lock_cleaned' => true,
                'lock_info' => $lockInfo,
                'reason' => 'Orphaned lock cleaned'
            ];
        }

        // El lock es válido y reciente
        $logger->info('Lock válido detectado, no se puede proceder', [
            'entity' => $entity,
            'lock_age_seconds' => $lockAge,
            'lock_info' => $lockInfo
        ]);

        return [
            'can_proceed' => false,
            'lock_cleaned' => false,
            'lock_info' => $lockInfo,
            'reason' => 'Valid lock exists'
        ];
    }

    /**
     * Detecta y resuelve deadlocks en el sistema de locks
     * 
     * @param string $entity Entidad para la cual detectar deadlocks
     * @return array Información sobre deadlocks detectados y resueltos
     * @since 2.1.0
     */
    public static function detectAndResolveDeadlocks(string $entity): array
    {
        global $wpdb;
        
        if (!$wpdb || !method_exists($wpdb, 'get_results')) {
            return ['deadlocks_detected' => 0, 'deadlocks_resolved' => 0];
        }
        
        $table_name = $wpdb->prefix . 'mia_sync_lock';
        $logger = self::getCentralizedLogger('sync-lock-deadlock');
        
        // Buscar locks que han estado activos por más tiempo del permitido
        $deadlocks = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name 
                 WHERE lock_key = %s 
                 AND expires_at > NOW() 
                 AND acquired_at < DATE_SUB(NOW(), INTERVAL %d SECOND)",
                $entity,
                self::DEADLOCK_DETECTION_TIMEOUT
            ),
            ARRAY_A
        );
        
        $resolved = 0;
        
        foreach ($deadlocks as $deadlock) {
            $pid = isset($deadlock['pid']) ? (int)$deadlock['pid'] : 0;
            
            // Verificar si el proceso sigue activo
            if ($pid > 0 && !self::isProcessActive($pid)) {
                $logger->warning('Deadlock detectado y resuelto - proceso inactivo', [
                    'entity' => $entity,
                    'pid' => $pid,
                    'acquired_at' => $deadlock['acquired_at'],
                    'age_seconds' => time() - (strtotime($deadlock['acquired_at']) ?: time())
                ]);
                
                self::release($entity);
                $resolved++;
            }
        }
        
        return [
            'deadlocks_detected' => count($deadlocks),
            'deadlocks_resolved' => $resolved,
            'entity' => $entity
        ];
    }


    /**
     * Maneja sistemas de locks concurrentes (transients vs tabla)
     * 
     * @param string $entity Entidad para la cual manejar concurrencia
     * @return array Información sobre el manejo de concurrencia
     * @since 2.1.0
     */
    public static function handleConcurrentLockSystems(string $entity): array
    {
        $logger = self::getCentralizedLogger('sync-lock-concurrency');
        
        // Verificar si hay locks en transients (sistema anterior)
        $transient_key = self::getLockKey($entity);
        $transient_lock = get_transient($transient_key);
        
        // Verificar si hay locks en tabla (sistema actual)
        $table_lock = self::getLockFromTable($entity);
        
        $actions_taken = [];
        
        // Si hay lock en transient pero no en tabla, migrar
        if ($transient_lock !== false && $table_lock['active'] !== true) {
            $logger->info('Migrando lock de transient a tabla', [
                'entity' => $entity,
                'transient_data' => $transient_lock
            ]);
            
            // Crear lock en tabla con datos del transient
            $timeout = $transient_lock['timeout'] ?? self::DEFAULT_TIMEOUT;
            $context = $transient_lock['context'] ?? [];
            
            if (self::createLockInTableAtomic($entity, $timeout, $context)) {
                // Eliminar lock del transient
                delete_transient($transient_key);
                $actions_taken[] = 'migrated_from_transient';
            }
        }
        
        // Si hay locks en ambos sistemas, resolver conflicto
        if ($transient_lock !== false && $table_lock['active'] === true) {
            $logger->warning('Conflicto detectado: locks en transient y tabla', [
                'entity' => $entity,
                'transient_data' => $transient_lock,
                'table_data' => $table_lock
            ]);
            
            // Priorizar el lock más reciente
            $transient_time = $transient_lock['timestamp'] ?? 0;
            $table_time = strtotime($table_lock['acquired_at'] ?? '1970-01-01') ?: 0;
            
            if ($transient_time > $table_time) {
                // Transient es más reciente, eliminar lock de tabla
                self::release($entity);
                $actions_taken[] = 'resolved_conflict_kept_transient';
            } else {
                // Tabla es más reciente, eliminar lock de transient
                delete_transient($transient_key);
                $actions_taken[] = 'resolved_conflict_kept_table';
            }
        }
        
        return [
            'entity' => $entity,
            'transient_lock_exists' => $transient_lock !== false,
            'table_lock_exists' => $table_lock['active'] === true,
            'actions_taken' => $actions_taken,
            'conflict_resolved' => !empty($actions_taken)
        ];
    }

    /**
     * Obtiene todos los locks activos del sistema
     * 
     * @return array Lista de locks activos con su información
     * @since 2.2.0
     */
    public static function getActiveLocks(): array
    {
        global $wpdb;
        
        $active_locks = [];
        
        try {
            // Obtener locks de la tabla
            $table_name = $wpdb->prefix . 'mia_sync_lock';
            $locks = $wpdb->get_results(
                "SELECT lock_key, lock_type, lock_data, acquired_at, expires_at, lock_owner FROM $table_name 
                 WHERE expires_at > NOW() AND released_at IS NULL
                 ORDER BY acquired_at DESC",
                ARRAY_A
            );
            
            foreach ($locks as $lock) {
                $active_locks[] = [
                    'entity' => $lock['lock_key'],
                    'acquired_at' => $lock['acquired_at'],
                    'expires_at' => $lock['expires_at'],
                    'context' => json_decode($lock['lock_data'], true) ?: [],
                    'pid' => $lock['lock_owner'],
                    'source' => 'table'
                ];
            }
            
            // CORRECCIÓN: Eliminar sistema dual - solo tabla de BD
            
        } catch (Exception $e) {
            if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
                $logger = self::getCentralizedLogger('sync-lock');
                $logger->error('Error obteniendo locks activos', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        return $active_locks;
    }


    /**
     * Verifica deadlocks en tiempo real usando sistema de reintentos con backoff exponencial
     * 
     * Reemplaza el sistema anterior que usaba SHOW ENGINE INNODB STATUS (requiere privilegios PROCESS)
     * por un sistema más robusto basado en reintentos y detección de locks huérfanos.
     * 
     * @param string|null $entity Entidad específica a verificar (opcional)
     * @return array Información sobre deadlocks detectados y limpieza realizada
     * @since 2.3.0
     */
    public static function checkRealTimeDeadlocks(?string $entity = null): array
    {
        global $wpdb;
        
        $logger = self::getCentralizedLogger('sync-lock-deadlock-check');
        $deadlocks = [];
        $warnings = [];
        $cleaned = 0;
        $expired_cleaned = 0;
        $orphan_cleaned = 0;
        
        try {
            $table_name = $wpdb->prefix . 'mia_sync_lock';
            
            // 1. LIMPIEZA: Eliminar locks expirados
            if ($entity) {
                // Limpiar solo para entidad específica
                $expired_locks = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM $table_name 
                         WHERE lock_key = %s 
                         AND expires_at <= NOW()",
                        $entity
                    ),
                    ARRAY_A
                );
            } else {
                // Limpiar todos los locks expirados
                $expired_locks = $wpdb->get_results(
                    "SELECT * FROM $table_name 
                     WHERE expires_at <= NOW()",
                    ARRAY_A
                );
            }
            
            foreach ($expired_locks as $expired_lock) {
                $logger->info('Limpiando lock expirado', [
                    'entity' => $expired_lock['lock_key'],
                    'expires_at' => $expired_lock['expires_at'],
                    'acquired_at' => $expired_lock['acquired_at']
                ]);
                
                self::release($expired_lock['lock_key']);
                $expired_cleaned++;
                $cleaned++;
            }
            
            // 2. DETECCIÓN DE DEADLOCKS: Sistema de reintentos con backoff exponencial
            // En lugar de usar SHOW ENGINE INNODB STATUS, detectamos deadlocks por:
            // - Locks que han estado activos demasiado tiempo
            // - Locks huérfanos (proceso ya no existe)
            // - Locks concurrentes problemáticos
            
            // 2.1. Verificar locks huérfanos (sin proceso activo)
            $orphaned_locks = $wpdb->get_results(
                "SELECT * FROM $table_name 
                 WHERE expires_at > NOW() 
                 AND acquired_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                ARRAY_A
            );
            
            foreach ($orphaned_locks as $lock) {
                $pid = isset($lock['pid']) ? (int)$lock['pid'] : 0;
                if ($pid > 0 && !self::isProcessActive($pid)) {
                    $deadlocks[] = [
                        'type' => 'orphaned_lock',
                        'severity' => 'high',
                        'entity' => $lock['lock_key'],
                        'pid' => $pid,
                        'lock_age_hours' => round((time() - (strtotime($lock['acquired_at']) ?: time())) / 3600, 2),
                        'timestamp' => time()
                    ];
                    
                    // Limpiar lock huérfano
                    $logger->warning('Limpiando lock huérfano', [
                        'entity' => $lock['lock_key'],
                        'pid' => $pid,
                        'acquired_at' => $lock['acquired_at']
                    ]);
                    
                    self::release($lock['lock_key']);
                    $orphan_cleaned++;
                    $cleaned++;
                }
            }
            
            // 2.2. Verificar locks que han estado activos demasiado tiempo (posible deadlock)
            $long_running_locks = $wpdb->get_results(
                "SELECT * FROM $table_name 
                 WHERE expires_at > NOW() 
                 AND acquired_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)",
                ARRAY_A
            );
            
            foreach ($long_running_locks as $lock) {
                $lock_age_hours = round((time() - (strtotime($lock['acquired_at']) ?: time())) / 3600, 2);
                
                // Si el lock lleva más de 2 horas, considerarlo posible deadlock
                if ($lock_age_hours > 2) {
                    $warnings[] = [
                        'type' => 'long_running_lock',
                        'severity' => 'warning',
                        'entity' => $lock['lock_key'],
                        'lock_age_hours' => $lock_age_hours,
                        'message' => 'Lock activo por más de 2 horas - posible deadlock',
                        'timestamp' => time()
                    ];
                }
            }
            
            // 2.3. Verificar locks concurrentes con el mismo PID (con manejo de errores)
            $concurrent_locks = [];
            try {
                // Verificar si la tabla tiene la columna pid
                $has_pid_column = self::tableHasPidColumn($table_name);
                
                // Verificar locks concurrentes por pid (columna siempre disponible)
                $concurrent_locks = $wpdb->get_results(
                    "SELECT pid, COUNT(*) as lock_count, GROUP_CONCAT(lock_key) as entities
                     FROM $table_name 
                     WHERE expires_at > NOW() 
                     GROUP BY pid 
                     HAVING lock_count > 1",
                    ARRAY_A
                );
            } catch (Exception $e) {
                $logger->warning('Error verificando locks concurrentes, continuando sin verificación', [
                    'entity' => $entity,
                    'error' => $e->getMessage()
                ]);
                // Continuar sin verificación de locks concurrentes
            }
            
            foreach ($concurrent_locks as $concurrent) {
                $warnings[] = [
                    'type' => 'concurrent_locks',
                    'severity' => 'warning',
                    'pid' => $concurrent['pid'],
                    'lock_count' => $concurrent['lock_count'],
                    'entities' => $concurrent['entities'],
                    'timestamp' => time()
                ];
            }
            
            // 3. LOGGING: Reportar resultados
            if (!empty($deadlocks) || !empty($warnings) || $cleaned > 0) {
                $logger->info('Verificación y limpieza de locks completada (sistema de reintentos)', [
                    'deadlocks' => $deadlocks,
                    'warnings' => $warnings,
                    'cleaned_total' => $cleaned,
                    'expired_cleaned' => $expired_cleaned,
                    'orphan_cleaned' => $orphan_cleaned,
                    'entity' => $entity,
                    'detection_method' => 'retry_with_backoff'
                ]);
            }
            
        } catch (Exception $e) {
            $logger->error('Error verificando deadlocks en tiempo real', [
                'error' => $e->getMessage(),
                'entity' => $entity
            ]);
        }
        
        return [
            'deadlocks' => $deadlocks,
            'warnings' => $warnings,
            'cleaned' => $cleaned,
            'expired_cleaned' => $expired_cleaned,
            'orphan_cleaned' => $orphan_cleaned,
            'verified' => true,
            'detection_method' => 'retry_with_backoff',
            'timestamp' => time()
        ];
    }

    /**
     * Verifica y limpia el estado del sistema antes de iniciar una sincronización
     * 
     * @return array Resultado de la verificación y limpieza
     * @since 2.2.0
     */
    public static function verifyAndCleanSystemState(): array
    {
        $logger = class_exists('\MiIntegracionApi\Helpers\Logger') 
            ? self::getCentralizedLogger('sync-lock-cleanup')
            : null;
        $cleanup_actions = [];
        $issues_found = [];
        
        try {
            // 1. Obtener locks activos
            $active_locks = self::getActiveLocks();
            
            // 2. Verificar estado de sincronización en base de datos
            $sync_status = get_option('mi_integracion_api_sync_status', []);
            $db_in_progress = $sync_status['current_sync']['in_progress'] ?? false;
            $last_update = $sync_status['current_sync']['last_update'] ?? 0;
            
            // 3. Verificar transients de WordPress (para detectar migración incompleta)
            $wp_in_progress = self::isSyncInProgress('wp');
            
            // 4. Verificar sistema de migración de transients (base de datos)
            $migrated_in_progress = self::isSyncInProgress('migrated');
            
            // 5. DETECCIÓN CRÍTICA: Si hay transients sin migrar, detener sincronización
            if ($wp_in_progress && !$migrated_in_progress) {
                $logger?->critical('DETECCIÓN CRÍTICA: Hay transients sin migrar a BD - deteniendo sincronización', [
                    'wp_in_progress' => true,
                    'migrated_in_progress' => false,
                    'action' => 'STOP_SYNC_REQUIRED'
                ]);
                
                // Detener cualquier sincronización en progreso
                if ($db_in_progress) {
                    $sync_status['current_sync']['in_progress'] = false;
                    update_option('mi_integracion_api_sync_status', $sync_status, true);
                }
                
                return [
                    'success' => false,
                    'inconsistencies_found' => 1,
                    'critical_error' => 'transients_sin_migrar',
                    'message' => 'Sistema de migración de transients no está funcionando correctamente',
                    'action_required' => 'STOP_SYNC'
                ];
            }
            
            // 6. Detectar inconsistencias
            $inconsistencies = [];
            
            // Inconsistencia: Base de datos dice que no hay sync, pero transients de WordPress dicen que sí
            // NOTA: Los transients del sistema migrado son válidos y no se consideran obsoletos
            if (!$db_in_progress && $wp_in_progress) {
                $inconsistencies[] = 'transients_obsoletos';
                $issues_found[] = 'Transients de WordPress contienen datos de sincronización obsoletos';
            }
            
            // Inconsistencia: Base de datos dice que hay sync, pero transients están vacíos
            if ($db_in_progress && !$wp_in_progress && !$migrated_in_progress) {
                $inconsistencies[] = 'transients_faltantes';
                $issues_found[] = 'Estado de sincronización en BD pero transients vacíos';
            }
            
            // Inconsistencia: Sync estancada (más de 1 hora sin actualización)
            if ($db_in_progress && (time() - $last_update) > 3600) {
                $inconsistencies[] = 'sync_estancada';
                $issues_found[] = 'Sincronización estancada por más de 1 hora';
            }
            
            // Inconsistencia: Locks activos pero no hay sync en progreso
            if (!$db_in_progress && !empty($active_locks)) {
                $inconsistencies[] = 'locks_huérfanos';
                $issues_found[] = 'Locks activos sin sincronización en progreso';
            }
            
            // 6. Aplicar correcciones
            if (!empty($inconsistencies)) {
                $logger?->warning('Inconsistencias detectadas en el estado del sistema', [
                        'inconsistencies' => $inconsistencies,
                        'issues' => $issues_found,
                        'db_in_progress' => $db_in_progress,
                        'wp_in_progress' => $wp_in_progress,
                        'migrated_in_progress' => $migrated_in_progress,
                        'active_locks' => count($active_locks)
                    ]);
                
                // Limpiar transients obsoletos (SOLO WordPress, preservar sistema migrado)
                if (in_array('transients_obsoletos', $inconsistencies)) {
                    // Solo eliminar transients de WordPress (obsoletos)
                    delete_transient('mia_sync_progress');
                    delete_transient('mia_sync_start_time');
                    
                    // NO eliminar transients del sistema migrado (son los datos actuales)
                    // Los transients del sistema migrado se preservan porque son los datos válidos
                    
                    $cleanup_actions[] = 'transients_wordpress_obsoletos_limpiados';
                }
                
                // REFACTORIZADO: Usar SyncStatusHelper para limpiar sync estancada
                if (in_array('sync_estancada', $inconsistencies)) {
                    SyncStatusHelper::clearCurrentSync();
                    $cleanup_actions[] = 'sync_estancada_limpiada';
                }
                
                // Limpiar locks huérfanos
                if (in_array('locks_huérfanos', $inconsistencies)) {
                    foreach ($active_locks as $lock) {
                        self::release($lock['entity']);
                    }
                    $cleanup_actions[] = 'locks_huérfanos_limpiados';
                }
            }
            
            return [
                'success' => true,
                'inconsistencies_found' => count($inconsistencies),
                'inconsistencies' => $inconsistencies,
                'issues' => $issues_found,
                'cleanup_actions' => $cleanup_actions,
                'state_before' => [
                    'db_in_progress' => $db_in_progress,
                    'wp_in_progress' => $wp_in_progress,
                    'migrated_in_progress' => $migrated_in_progress,
                    'active_locks' => count($active_locks)
                ]
            ];
            
        } catch (Exception $e) {
            $logger?->error('Error al verificar estado del sistema', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'cleanup_actions' => []
            ];
        }
    }

    /**
     * Verifica si hay una sincronización en progreso desde diferentes fuentes
     * 
     * @param string $source Fuente de verificación ('wp', 'migrated', 'auto')
     * @return bool True si hay sincronización en progreso
     * @since 2.2.0
     */
    private static function isSyncInProgress(string $source = 'auto'): bool
    {
        return match ($source) {
            'wp' => self::checkWordPressSyncStatus(),
            'migrated' => self::checkMigratedSyncStatus(),
            default => self::checkAutoSyncStatus()
        };
    }

    /**
     * Verifica el estado de sincronización desde WordPress transients
     * 
     * @return bool True si hay sincronización en progreso en WordPress transients
     * @since 2.2.0
     */
    private static function checkWordPressSyncStatus(): bool
    {
        $wp_progress = get_transient('mia_sync_progress');
        return ($wp_progress !== false && is_array($wp_progress) === true);
    }

    /**
     * Verifica el estado de sincronización desde el sistema migrado
     * 
     * @return bool True si hay sincronización en progreso en el sistema migrado
     * @since 2.2.0
     */
    private static function checkMigratedSyncStatus(): bool
    {
        if (!function_exists('mia_get_sync_transient')) {
            return false;
        }
        
        $migrated_progress = mia_get_sync_transient('mia_sync_progress');
        return ($migrated_progress !== false && is_array($migrated_progress) === true);
    }

    /**
     * Verificación automática del estado de sincronización
     * Sistema migrado es obligatorio - sin fallback
     * 
     * @return bool True si hay sincronización en progreso
     * @since 2.2.0
     */
    private static function checkAutoSyncStatus(): bool
    {
        // Sistema migrado es obligatorio - sin fallback
        if (!function_exists('mia_get_sync_transient')) {
            throw new \Exception('Sistema migrado de sincronización no está disponible');
        }
        
        return self::checkMigratedSyncStatus();
    }
    
    /**
     * Determina si se deben verificar los locks según el contexto
     * 
     * @param string $context Contexto de ejecución ('admin', 'ajax', 'cron', 'frontend', 'cli')
     * @param string $entity Entidad específica a verificar (opcional)
     * @return bool True si se debe verificar, false si se puede usar cache
     * @since 2.5.0
     */
    public static function shouldVerifyLocks(string $context = 'general', string $entity = ''): bool {
        // Contextos que requieren verificación inmediata de locks
        $lock_critical_contexts = ['admin', 'ajax', 'cron'];
        
        // Contextos que pueden usar cache más agresivamente para locks
        $lock_cache_friendly_contexts = ['frontend', 'general'];
        
        // En contextos críticos, verificar locks más frecuentemente
        if (in_array($context, $lock_critical_contexts)) {
            // Verificar si hay actividad reciente de locks (últimos 30 segundos)
            $last_lock_activity = get_option('mia_last_lock_activity', 0);
            $recent_lock_activity = (time() - $last_lock_activity) < 30;
            
            // Verificar si hay sincronización en progreso
            $sync_in_progress = \MiIntegracionApi\Helpers\SyncStatusHelper::isSyncInProgress();
            
            return $recent_lock_activity || $sync_in_progress;
        }
        
        // En contextos cache-friendly, usar cache más agresivamente
        if (in_array($context, $lock_cache_friendly_contexts)) {
            // Solo verificar si hay actividad reciente (últimos 2 minutos)
            $last_lock_activity = get_option('mia_last_lock_activity', 0);
            $recent_lock_activity = (time() - $last_lock_activity) < 120;
            
            return $recent_lock_activity;
        }
        
        // Contexto desconocido - verificar por seguridad
        return true;
    }
    
    /**
     * Verificación lazy de locks basada en contexto
     * 
     * @param string $context Contexto de ejecución ('admin', 'ajax', 'cron', 'frontend', 'cli')
     * @param string $entity Entidad específica a verificar (opcional)
     * @return array Estado de locks o cache si no es necesario verificar
     * @since 2.5.0
     */
    public static function lazyVerifyLocks(string $context = 'general', string $entity = ''): array {
        $start_time = microtime(true);
        
        // Auto-detectar contexto si no se especifica
        if ($context === 'general') {
            $context = self::detectCurrentContext();
        }
        
        // Verificar si necesitamos hacer verificación real
        if (!self::shouldVerifyLocks($context, $entity)) {
            // Usar cache existente si está disponible
            $cache_key = 'mia_unified_lock_status_' . ($entity ?: 'global');
            $cached = get_transient($cache_key);
            
            if ($cached !== false && isset($cached['data'])) {
                // Marcar como verificación lazy exitosa
                $cached['data']['lazy_verification'] = true;
                $cached['data']['context'] = $context;
                
                // Track verificación lazy
                $duration = microtime(true) - $start_time;
                self::trackContextVerification('locks', $context, true, $duration);
                
                return $cached['data'];
            }
        }
        
        // Realizar verificación completa
        $result = self::getUnifiedLockStatus($entity, false);
        $result['lazy_verification'] = false;
        $result['context'] = $context;
        
        // Track verificación completa
        $duration = microtime(true) - $start_time;
        self::trackContextVerification('locks', $context, false, $duration);
        
        return $result;
    }
    
    /**
     * Detecta automáticamente el contexto de ejecución actual
     * 
     * @return string Contexto detectado ('admin', 'ajax', 'cron', 'frontend', 'cli', 'general')
     * @since 2.5.0
     */
    private static function detectCurrentContext(): string {
        // Detectar contexto de WordPress
        if (function_exists('is_admin') && is_admin()) {
            return 'admin';
        }
        
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return 'ajax';
        }
        
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return 'cron';
        }
        
        if (function_exists('wp_doing_rest') && wp_doing_rest()) {
            return 'rest';
        }
        
        // Detectar contexto de CLI
        if (defined('WP_CLI') && WP_CLI) {
            return 'cli';
        }
        
        // Detectar contexto de frontend
        if (function_exists('is_frontend') && is_frontend()) {
            return 'frontend';
        }
        
        // Contexto por defecto
        return 'general';
    }
    
    /**
     * Tracking de verificaciones por contexto para métricas
     * 
     * @var array
     */
    private static array $context_verification_tracking = [];
    
    /**
     * Registra una verificación en el tracking de contexto
     * 
     * @param string $verification_type Tipo de verificación ('system_state', 'woocommerce', 'locks')
     * @param string $context Contexto de ejecución
     * @param bool $was_lazy Si fue una verificación lazy
     * @param float $duration Duración en segundos
     * @since 2.5.0
     */
    private static function trackContextVerification(string $verification_type, string $context, bool $was_lazy, float $duration): void {
        $key = $verification_type . '_' . $context;
        
        if (!isset(self::$context_verification_tracking[$key])) {
            self::$context_verification_tracking[$key] = [
                'total_calls' => 0,
                'lazy_calls' => 0,
                'total_duration' => 0.0,
                'average_duration' => 0.0,
                'last_verification' => time()
            ];
        }
        
        self::$context_verification_tracking[$key]['total_calls']++;
        if ($was_lazy) {
            self::$context_verification_tracking[$key]['lazy_calls']++;
        }
        self::$context_verification_tracking[$key]['total_duration'] += $duration;
        self::$context_verification_tracking[$key]['average_duration'] = 
            self::$context_verification_tracking[$key]['total_duration'] / self::$context_verification_tracking[$key]['total_calls'];
        self::$context_verification_tracking[$key]['last_verification'] = time();
    }
    
    /**
     * Obtiene métricas de verificaciones por contexto
     * 
     * @return array Métricas de verificaciones por contexto
     * @since 2.5.0
     */
    public static function getContextVerificationMetrics(): array {
        return self::$context_verification_tracking;
    }
    
    /**
     * Limpia el tracking de verificaciones por contexto
     * 
     * @since 2.5.0
     */
    public static function clearContextVerificationTracking(): void {
        self::$context_verification_tracking = [];
    }
    
    /**
     * Obtiene el TTL dinámico recomendado para locks
     * 
     * @param string $context Contexto de ejecución
     * @return int TTL recomendado en segundos
     * @since 2.5.0
     */
    public static function getDynamicTTL(string $context): int {
        // TTL base para locks
        $base_ttl = 30; // 30 segundos base
        
        // Ajuste por contexto
        $context_multipliers = [
            'admin' => 0.8, // Contextos críticos - TTL más corto
            'ajax' => 0.6,
            'cron' => 1.5, // Contextos automáticos - TTL más largo
            'frontend' => 2.0,
            'general' => 1.0
        ];
        
        $multiplier = $context_multipliers[$context] ?? 1.0;
        $adjusted_ttl = $base_ttl * $multiplier;
        
        // Límites de TTL (15 segundos a 5 minutos)
        $adjusted_ttl = max(15, min(300, $adjusted_ttl));
        
        return (int) $adjusted_ttl;
    }
    
    /**
     * Obtiene el estado unificado de todos los locks con cache inteligente
     * 
     * Este método consolida múltiples verificaciones de locks en una sola operación
     * con cache de 30 segundos para mejorar el rendimiento.
     * 
     * @param string $entity Entidad específica a verificar (opcional)
     * @param bool $force_refresh Forzar nueva verificación sin cache
     * @return array Estado unificado con información de locks, heartbeat y estado del sistema
     * @since 2.4.0
     */
    public static function getUnifiedLockStatus(string $entity = '', bool $force_refresh = false): array {
        $cache_key = 'mia_unified_lock_status_' . ($entity ?: 'global');
        
        // Usar cache si no se fuerza refresh
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false && isset($cached['timestamp'])) {
                $age = time() - $cached['timestamp'];
                if ($age < self::UNIFIED_LOCK_CACHE_TTL) {
                    return $cached['data'];
                }
            }
        }
        
        $logger = class_exists('\MiIntegracionApi\Helpers\Logger') 
            ? self::getCentralizedLogger('sync-lock-unified')
            : null;
        
        try {
            // 1. Obtener locks activos
            $active_locks = self::getActiveLocks();
            
            // 2. Verificar estado de sincronización
            $sync_in_progress = \MiIntegracionApi\Helpers\SyncStatusHelper::isSyncInProgress();
            
            // 3. Verificar heartbeat si se especifica entidad
            $heartbeat_healthy = true;
            if (!empty($entity)) {
                $lock_info = self::getLockInfo($entity);
                if ($lock_info && isset($lock_info['heartbeat'])) {
                    $heartbeat_age = time() - $lock_info['heartbeat'];
                    $heartbeat_healthy = $heartbeat_age <= self::HEARTBEAT_TIMEOUT;
                }
            }
            
            // 4. Detectar inconsistencias
            $inconsistencies = [];
            if ($sync_in_progress && empty($active_locks)) {
                $inconsistencies[] = 'sync_sin_locks';
            }
            if (!$sync_in_progress && !empty($active_locks)) {
                $inconsistencies[] = 'locks_sin_sync';
            }
            if (!empty($entity) && !$heartbeat_healthy) {
                $inconsistencies[] = 'heartbeat_obsoleto';
            }
            
            // 5. Determinar estado general
            $can_proceed = empty($active_locks) && !$sync_in_progress;
            $needs_cleanup = !empty($inconsistencies);
            
            $result = [
                'success' => true,
                'can_proceed' => $can_proceed,
                'needs_cleanup' => $needs_cleanup,
                'active_locks' => $active_locks,
                'sync_in_progress' => $sync_in_progress,
                'heartbeat_healthy' => $heartbeat_healthy,
                'inconsistencies' => $inconsistencies,
                'entity_specific' => !empty($entity),
                'entity' => $entity,
                'timestamp' => time()
            ];
            
        // Cachear resultado con TTL dinámico
        $context = self::detectCurrentContext();
        $dynamic_ttl = self::getDynamicTTL('locks', $context);
        
        set_transient($cache_key, [
            'data' => $result,
            'timestamp' => time(),
            'dynamic_ttl' => $dynamic_ttl,
            'verification_type' => 'locks',
            'context' => $context
        ], $dynamic_ttl);
            
            return $result;
            
        } catch (Exception $e) {
            $logger?->error('Error al obtener estado unificado de locks', [
                'error' => $e->getMessage(),
                'entity' => $entity
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'can_proceed' => false,
                'needs_cleanup' => true,
                'active_locks' => [],
                'sync_in_progress' => false,
                'heartbeat_healthy' => false,
                'inconsistencies' => ['error_verificacion'],
                'entity_specific' => !empty($entity),
                'entity' => $entity,
                'timestamp' => time()
            ];
        }
    }
    
    /**
     * Consolida todas las verificaciones de limpieza de locks en una sola operación
     * 
     * Este método unifica checkAndCleanOrphanedLock, checkRealTimeDeadlocks,
     * handleConcurrentLockSystems y verifyAndCleanSystemState en una sola operación
     * con cache de 1 minuto para mejorar el rendimiento.
     * 
     * @param string $entity Entidad específica a verificar (opcional)
     * @param bool $force_refresh Forzar nueva verificación sin cache
     * @return array Resultado consolidado de todas las verificaciones y limpiezas
     * @since 2.4.0
     */
    public static function consolidateLockCleanup(string $entity = '', bool $force_refresh = false): array {
        $cache_key = 'mia_consolidated_lock_cleanup_' . ($entity ?: 'global');
        
        // Usar cache si no se fuerza refresh
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false && isset($cached['timestamp'])) {
                $age = time() - $cached['timestamp'];
                if ($age < 60) { // 1 minuto TTL
                    return $cached['data'];
                }
            }
        }
        
        $logger = class_exists('\MiIntegracionApi\Helpers\Logger') 
            ? self::getCentralizedLogger('sync-lock-consolidated')
            : null;
        
        try {
            $cleanup_actions = [];
            $issues_found = [];
            $inconsistencies = [];
            
            // 1. Verificar estado unificado de locks
            $unified_status = self::getUnifiedLockStatus($entity, true);
            
            // 2. Si hay inconsistencias, ejecutar limpieza específica
            if ($unified_status['needs_cleanup']) {
                $inconsistencies = $unified_status['inconsistencies'];
                
                // Limpiar locks huérfanos si es necesario
                if (in_array('locks_sin_sync', $inconsistencies)) {
                    foreach ($unified_status['active_locks'] as $lock) {
                        self::release($lock['entity']);
                        $cleanup_actions[] = "lock_huérfano_liberado: {$lock['entity']}";
                    }
                }
                
                // Limpiar sync estancada si es necesario
                if (in_array('sync_sin_locks', $inconsistencies)) {
                    \MiIntegracionApi\Helpers\SyncStatusHelper::clearCurrentSync();
                    $cleanup_actions[] = 'sync_estancada_limpiada';
                }
                
                // Limpiar heartbeat obsoleto si es necesario
                if (in_array('heartbeat_obsoleto', $inconsistencies) && !empty($entity)) {
                    self::release($entity);
                    $cleanup_actions[] = "heartbeat_obsoleto_limpiado: {$entity}";
                }
            }
            
            // 3. Ejecutar verificaciones específicas de la entidad
            if (!empty($entity)) {
                // Verificar locks huérfanos específicos
                $orphan_check = self::checkAndCleanOrphanedLock($entity, 7200);
                if (!$orphan_check['can_proceed']) {
                    $issues_found[] = "Lock huérfano detectado para entidad: {$entity}";
                    $cleanup_actions[] = "lock_huérfano_específico_limpiado: {$entity}";
                }
                
                // Verificar deadlocks en tiempo real
                $deadlock_check = self::checkRealTimeDeadlocks($entity);
                if (!empty($deadlock_check['deadlocks_found'])) {
                    $issues_found[] = "Deadlocks detectados para entidad: {$entity}";
                    $cleanup_actions[] = "deadlocks_limpiados: {$entity}";
                }
                
                // Manejar sistemas de locks concurrentes
                $concurrency_check = self::handleConcurrentLockSystems($entity);
                if (!empty($concurrency_check['conflicts_resolved'])) {
                    $issues_found[] = "Conflictos de concurrencia resueltos para entidad: {$entity}";
                    $cleanup_actions[] = "conflictos_concurrencia_resueltos: {$entity}";
                }
            }
            
            // 4. Ejecutar verificación general del sistema si no hay entidad específica
            if (empty($entity)) {
                $system_check = self::verifyAndCleanSystemState();
                if (!$system_check['success']) {
                    $issues_found[] = "Error en verificación del sistema: " . ($system_check['error'] ?? 'desconocido');
                } else {
                    $cleanup_actions = array_merge($cleanup_actions, $system_check['cleanup_actions'] ?? []);
                    $inconsistencies = array_merge($inconsistencies, $system_check['inconsistencies'] ?? []);
                }
            }
            
            $result = [
                'success' => true,
                'can_proceed' => $unified_status['can_proceed'] && empty($issues_found),
                'needs_cleanup' => $unified_status['needs_cleanup'] || !empty($issues_found),
                'cleanup_actions' => $cleanup_actions,
                'issues_found' => $issues_found,
                'inconsistencies' => $inconsistencies,
                'unified_status' => $unified_status,
                'entity' => $entity,
                'timestamp' => time()
            ];
            
            // Cachear resultado
            set_transient($cache_key, [
                'data' => $result,
                'timestamp' => time()
            ], 60); // 1 minuto TTL
            
            return $result;
            
        } catch (Exception $e) {
            $logger?->error('Error en limpieza consolidada de locks', [
                'error' => $e->getMessage(),
                'entity' => $entity
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'can_proceed' => false,
                'needs_cleanup' => true,
                'cleanup_actions' => [],
                'issues_found' => ['error_limpieza'],
                'inconsistencies' => ['error_verificacion'],
                'unified_status' => null,
                'entity' => $entity,
                'timestamp' => time()
            ];
        }
    }
} 