<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

/**
 * WORKER UNIFICADO DE HEARTBEAT Y LIMPIEZA AUTOMÁTICA
 * 
 * Este worker es completamente independiente de WordPress y maneja:
 * - Heartbeat automático de locks activos
 * - Detección de procesos muertos
 * - Limpieza automática de locks huérfanos y expirados
 * - Sistema de cron independiente usando archivos de estado
 * 
 * @package MiIntegracionApi\Core
 * @since 1.4.0
 */
class HeartbeatWorker
{
    private Logger $logger;
    private array $config;
    private bool $is_running = false;
    private int $last_cleanup_time = 0;
    private array $active_locks = [];
    
    // Archivos de estado para cron independiente
    private string $cron_state_file;
    private string $lock_cleanup_state_file;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = new Logger('heartbeat-worker-unified');
        $this->config = $this->loadConfiguration();
        
        // Archivos de estado para el cron independiente
        $this->cron_state_file = sys_get_temp_dir() . '/mia_unified_heartbeat_cron.state';
        $this->lock_cleanup_state_file = sys_get_temp_dir() . '/mia_unified_lock_cleanup.state';
        
        $this->logger->info('HeartbeatWorker Unificado inicializado', [
            'heartbeat_interval' => $this->config['heartbeat_interval'],
            'heartbeat_timeout' => $this->config['heartbeat_timeout'],
            'lock_cleanup_interval' => $this->config['lock_cleanup_interval'],
            'cron_state_file' => $this->cron_state_file,
            'lock_cleanup_state_file' => $this->lock_cleanup_state_file
        ]);
    }
    
    /**
     * Carga la configuración desde DependencyContainer
     */
    private function loadConfiguration(): array
    {
        try {
            if (class_exists('MiIntegracionApi\\Core\\DependencyContainer')) {
                $container = \MiIntegracionApi\Core\DependencyContainer::getInstance();
                return $container->get('config');
            }
        } catch (\Exception $e) {
            $this->logger->warning('No se pudo cargar DependencyContainer, usando configuración por defecto');
        }
        
        // Configuración por defecto si no hay DependencyContainer
        return [
            'heartbeat_interval' => 60,
            'heartbeat_timeout' => 300,
            'lock_cleanup_interval' => 300,
            'process_dead_timeout' => 180,
            'max_cleanup_retries' => 3
        ];
    }
    
    /**
     * Inicia el worker unificado
     */
    public function start(): void
    {
        if ($this->is_running) {
            $this->logger->warning('HeartbeatWorker ya está ejecutándose');
            return;
        }
        
        $this->is_running = true;
        $this->logger->info('HeartbeatWorker Unificado iniciado');
        
        // Ejecutar ciclo inicial
        $this->executeUnifiedCycle();
        
        // NO usar sleep() - en su lugar, programar siguiente ejecución en WordPress cron
        $this->scheduleNextExecution();
        
        // Marcar como no ejecutándose para permitir futuras llamadas
        $this->is_running = false;
        $this->logger->info('HeartbeatWorker Unificado completado y programado para siguiente ejecución');
    }
    
    /**
     * Detiene el worker
     */
    public function stop(): void
    {
        $this->is_running = false;
        $this->logger->info('HeartbeatWorker Unificado detenido');
    }
    
    /**
     * Ejecuta un ciclo unificado completo
     */
    public function executeUnifiedCycle(): void
    {
        if (!$this->is_running) {
            return;
        }
        
        try {
            $this->logger->debug('Ejecutando ciclo unificado de heartbeat y limpieza');
            
            // 1. Actualizar heartbeat de locks activos
            $this->updateActiveLocksHeartbeat();
            
            // 2. Detectar procesos muertos
            $this->detectDeadProcesses();
            
            // 3. Limpiar locks huérfanos y expirados (si es necesario)
            $this->executeLockCleanupIfNeeded();
            
            // 4. MÉTODO PRINCIPAL: Programar limpieza automática en base de datos
            $db_scheduled = $this->scheduleAutomaticCleanupInDatabase();
            
            if (!$db_scheduled) {
                // FALLBACK: WordPress cron solo si falla la base de datos
                $this->logger->warning('Fallback a WordPress cron para limpieza automática');
                $this->scheduleAutomaticCleanupInWordPress();
            }
            
            $this->logger->debug('Ciclo unificado completado');
            
        } catch (\Exception $e) {
            $this->logger->error('Error en ciclo unificado', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
    
    /**
     * Actualiza el heartbeat de todos los locks activos
     */
    private function updateActiveLocksHeartbeat(): void
    {
        try {
            global $wpdb;
            
            if (!$wpdb) {
                $this->logger->warning('No hay conexión a base de datos disponible');
                return;
            }
            
            $table_name = $wpdb->prefix . 'mia_sync_lock';
            
            // Obtener todos los locks activos
            $active_locks = $wpdb->get_results(
                "SELECT * FROM {$table_name} WHERE released_at IS NULL AND expires_at > NOW()",
                ARRAY_A
            );
            
            if (empty($active_locks)) {
                $this->logger->debug('No hay locks activos para actualizar heartbeat');
                return;
            }
            
            $updated_count = 0;
            $now = date('Y-m-d H:i:s');
            
            foreach ($active_locks as $lock) {
                // Verificar si el lock necesita heartbeat
                $last_heartbeat = strtotime($lock['last_heartbeat'] ?? '1970-01-01 00:00:00');
                $heartbeat_interval = $this->config['heartbeat_interval'];
                
                if ((time() - $last_heartbeat) >= $heartbeat_interval) {
                    // Actualizar heartbeat
                    $result = $wpdb->update(
                        $table_name,
                        ['last_heartbeat' => $now],
                        ['id' => $lock['id']],
                        ['%s'],
                        ['%d']
                    );
                    
                    if ($result !== false) {
                        $updated_count++;
                        $this->logger->debug('Heartbeat actualizado para lock', [
                            'lock_key' => $lock['lock_key'],
                            'lock_owner' => $lock['lock_owner'] ?? 'N/A'
                        ]);
                    }
                }
            }
            
            if ($updated_count > 0) {
                $this->logger->info('Heartbeat actualizado para locks activos', [
                    'locks_actualizados' => $updated_count,
                    'total_locks' => count($active_locks)
                ]);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error al actualizar heartbeat de locks activos', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Detecta procesos muertos por falta de heartbeat
     */
    private function detectDeadProcesses(): void
    {
        try {
            global $wpdb;
            
            if (!$wpdb) {
                return;
            }
            
            $table_name = $wpdb->prefix . 'mia_sync_lock';
            $heartbeat_timeout = $this->config['heartbeat_timeout'];
            $dead_process_threshold = time() - $heartbeat_timeout;
            
            // Buscar locks con heartbeat muy antiguo
            $dead_locks = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} 
                     WHERE released_at IS NULL 
                     AND expires_at > NOW() 
                     AND last_heartbeat < %s",
                    date('Y-m-d H:i:s', $dead_process_threshold)
                ),
                ARRAY_A
            );
            
            if (empty($dead_locks)) {
                return;
            }
            
            $this->logger->warning('Procesos muertos detectados', [
                'locks_muertos' => count($dead_locks),
                'threshold' => $heartbeat_timeout . ' segundos'
            ]);
            
            // Marcar locks como muertos para limpieza posterior
            foreach ($dead_locks as $lock) {
                $this->logger->warning('Lock de proceso muerto detectado', [
                    'lock_key' => $lock['lock_key'],
                    'lock_owner' => $lock['lock_owner'] ?? 'N/A',
                    'last_heartbeat' => $lock['last_heartbeat'],
                    'tiempo_sin_heartbeat' => time() - strtotime($lock['last_heartbeat'])
                ]);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error al detectar procesos muertos', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Ejecuta la limpieza de locks si es necesaria
     */
    private function executeLockCleanupIfNeeded(): void
    {
        if (!$this->shouldExecuteLockCleanup()) {
            $this->logger->debug('Limpieza de locks no es necesaria en este momento');
            return;
        }
        
        $this->logger->info('Ejecutando limpieza automática de locks');
        
        try {
            // 1. Limpiar locks expirados
            $expired_cleaned = $this->cleanupExpiredLocks();
            
            // 2. Limpiar locks de procesos muertos
            $dead_cleaned = $this->cleanupDeadProcessLocks();
            
            // 3. Limpiar locks huérfanos
            $orphan_cleaned = $this->cleanupOrphanedLocks();
            
            // 4. Actualizar estado de limpieza
            $this->updateCleanupState();
            
            $total_cleaned = $expired_cleaned + $dead_cleaned + $orphan_cleaned;
            
            if ($total_cleaned > 0) {
                $this->logger->info('Limpieza automática de locks completada', [
                    'locks_expirados_limpiados' => $expired_cleaned,
                    'locks_procesos_muertos_limpiados' => $dead_cleaned,
                    'locks_huérfanos_limpiados' => $orphan_cleaned,
                    'total_limpiados' => $total_cleaned
                ]);
            } else {
                $this->logger->info('Limpieza automática completada - no se encontraron locks para limpiar');
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error durante limpieza automática de locks', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
    
    /**
     * Verifica si se debe ejecutar la limpieza de locks
     */
    private function shouldExecuteLockCleanup(): bool
    {
        try {
            if (!file_exists($this->lock_cleanup_state_file)) {
                return true; // Primera ejecución
            }
            
            $file_content = file_get_contents($this->lock_cleanup_state_file);
            if ($file_content === false) {
                $this->logger->warning('No se pudo leer el archivo de estado de limpieza', [
                    'file' => $this->lock_cleanup_state_file
                ]);
                return true; // Ejecutar limpieza por seguridad
            }
            
            $state_data = json_decode($file_content, true);
            if (!$state_data || !isset($state_data['last_execution'])) {
                return true;
            }
            
            $last_execution = $state_data['last_execution'];
            if (!is_numeric($last_execution)) {
                return true; // Ejecutar limpieza por seguridad
            }
            
            $interval = $this->config['lock_cleanup_interval'];
            
            return (time() - (int) $last_execution) >= $interval;
            
        } catch (\Exception $e) {
            $this->logger->error('Error al verificar estado de limpieza', [
                'error' => $e->getMessage(),
                'file' => $this->lock_cleanup_state_file
            ]);
            return true; // Ejecutar limpieza por seguridad
        }
    }
    
    /**
     * Limpia locks expirados
     */
    private function cleanupExpiredLocks(): int
    {
        try {
            global $wpdb;
            
            if (!$wpdb) {
                $this->logger->warning('No hay conexión a base de datos disponible');
                return 0;
            }
            
            $table_name = $wpdb->prefix . 'mia_sync_lock';
            
            // Buscar locks expirados
            $expired_locks = $wpdb->get_results(
                "SELECT * FROM {$table_name} WHERE expires_at <= NOW() AND released_at IS NULL",
                ARRAY_A
            );
            
            if (empty($expired_locks)) {
                return 0;
            }
            
            $cleaned_count = 0;
            $now = date('Y-m-d H:i:s');
            
            foreach ($expired_locks as $lock) {
                $result = $wpdb->update(
                    $table_name,
                    [
                        'released_at' => $now,
                        'release_reason' => 'expired_auto_cleanup'
                    ],
                    ['id' => $lock['id']],
                    ['%s', '%s'],
                    ['%d']
                );
                
                if ($result !== false) {
                    $cleaned_count++;
                    $this->logger->debug('Lock expirado limpiado automáticamente', [
                        'lock_key' => $lock['lock_key'],
                        'expired_at' => $lock['expires_at']
                    ]);
                }
            }
            
            return $cleaned_count;
            
        } catch (\Exception $e) {
            $this->logger->error('Error al limpiar locks expirados', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Limpia locks de procesos muertos
     */
    private function cleanupDeadProcessLocks(): int
    {
        try {
            global $wpdb;
            
            if (!$wpdb) {
                return 0;
            }
            
            $table_name = $wpdb->prefix . 'mia_sync_lock';
            $heartbeat_timeout = $this->config['heartbeat_timeout'];
            $dead_process_threshold = time() - $heartbeat_timeout;
            
            // Buscar locks con heartbeat muy antiguo
            $dead_locks = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} 
                     WHERE released_at IS NULL 
                     AND expires_at > NOW() 
                     AND last_heartbeat < %s",
                    date('Y-m-d H:i:s', $dead_process_threshold)
                ),
                ARRAY_A
            );
            
            if (empty($dead_locks)) {
                return 0;
            }
            
            $cleaned_count = 0;
            $now = date('Y-m-d H:i:s');
            
            foreach ($dead_locks as $lock) {
                $result = $wpdb->update(
                    $table_name,
                    [
                        'released_at' => $now,
                        'release_reason' => 'dead_process_auto_cleanup'
                    ],
                    ['id' => $lock['id']],
                    ['%s', '%s'],
                    ['%d']
                );
                
                if ($result !== false) {
                    $cleaned_count++;
                    $this->logger->debug('Lock de proceso muerto limpiado automáticamente', [
                        'lock_key' => $lock['lock_key'],
                        'lock_owner' => $lock['lock_owner'] ?? 'N/A',
                        'last_heartbeat' => $lock['last_heartbeat']
                    ]);
                }
            }
            
            return $cleaned_count;
            
        } catch (\Exception $e) {
            $this->logger->error('Error al limpiar locks de procesos muertos', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Limpia locks huérfanos (sin owner o con owner inválido)
     */
    private function cleanupOrphanedLocks(): int
    {
        try {
            global $wpdb;
            
            if (!$wpdb) {
                return 0;
            }
            
            $table_name = $wpdb->prefix . 'mia_sync_lock';
            
            // Buscar locks huérfanos (sin owner o con owner muy antiguo)
            $orphan_locks = $wpdb->get_results(
                "SELECT * FROM {$table_name} 
                 WHERE released_at IS NULL 
                 AND (lock_owner IS NULL OR lock_owner = '' OR lock_owner = 'unknown')",
                ARRAY_A
            );
            
            if (empty($orphan_locks)) {
                return 0;
            }
            
            $cleaned_count = 0;
            $now = date('Y-m-d H:i:s');
            
            foreach ($orphan_locks as $lock) {
                $result = $wpdb->update(
                    $table_name,
                    [
                        'released_at' => $now,
                        'release_reason' => 'orphan_auto_cleanup'
                    ],
                    ['id' => $lock['id']],
                    ['%s', '%s'],
                    ['%d']
                );
                
                if ($result !== false) {
                    $cleaned_count++;
                    $this->logger->debug('Lock huérfano limpiado automáticamente', [
                        'lock_key' => $lock['lock_key'],
                        'lock_owner' => $lock['lock_owner'] ?? 'N/A'
                    ]);
                }
            }
            
            return $cleaned_count;
            
        } catch (\Exception $e) {
            $this->logger->error('Error al limpiar locks huérfanos', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Actualiza el estado de limpieza
     */
    private function updateCleanupState(): void
    {
        try {
            $state_data = [
                'last_execution' => time(),
                'execution_count' => $this->getExecutionCount() + 1,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            $result = file_put_contents($this->lock_cleanup_state_file, json_encode($state_data));
            
            if ($result === false) {
                $this->logger->warning('No se pudo escribir el archivo de estado de limpieza', [
                    'file' => $this->lock_cleanup_state_file
                ]);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error al actualizar estado de limpieza', [
                'error' => $e->getMessage(),
                'file' => $this->lock_cleanup_state_file
            ]);
        }
    }
    
    /**
     *  Obtiene el número de ejecuciones
     */
    private function getExecutionCount(): int
    {
        try {
            if (!file_exists($this->lock_cleanup_state_file)) {
                return 0;
            }
            
            $file_content = file_get_contents($this->lock_cleanup_state_file);
            if ($file_content === false) {
                return 0;
            }
            
            $state_data = json_decode($file_content, true);
            $count = $state_data['execution_count'] ?? 0;
            return is_numeric($count) ? (int) $count : 0;
            
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Programa la siguiente ejecución del worker
     */
    private function scheduleNextExecution(): void
    {
        if (!$this->is_running) {
            return;
        }
        
        $interval = $this->config['heartbeat_interval'];
        
        // Asegurar que el intervalo sea un entero válido
        if (!is_numeric($interval) || $interval < 1) {
            $this->logger->warning('Intervalo de heartbeat inválido, usando valor por defecto', [
                'interval' => $interval,
                'default' => 60
            ]);
            $interval = 60; // Valor por defecto seguro
        }
        
        // Convertir a entero
        $interval = (int) $interval;
        
        // MÉTODO PRINCIPAL: Programar en base de datos
        $db_scheduled = $this->scheduleNextExecutionInDatabase($interval);
        
        if ($db_scheduled) {
            $this->logger->info('Próxima ejecución programada en base de datos', [
                'interval' => $interval,
                'next_execution' => date('Y-m-d H:i:s', time() + $interval)
            ]);
        } else {
            // FALLBACK: WordPress cron solo si falla la base de datos
            $this->logger->warning('Fallback a WordPress cron - base de datos falló');
            if (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time() + $interval, 'mia_start_heartbeat_worker');
                $this->logger->info('Próxima ejecución programada en WordPress cron (fallback)', [
                    'interval' => $interval,
                    'next_execution' => date('Y-m-d H:i:s', time() + $interval)
                ]);
            } else {
                $this->logger->error('Ni base de datos ni WordPress cron disponibles');
            }
        }
        
        // Terminar este ciclo (no bloquear)
        $this->is_running = false;
    }
    
    /**
     * Programa la siguiente ejecución en la base de datos como fallback
     */
    private function scheduleNextExecutionInDatabase(int $interval): bool
    {
        try {
            global $wpdb;
            
            if (!$wpdb) {
                $this->logger->error('No hay conexión a base de datos para programar ejecución');
                return false;
            }
            
            $table_name = $wpdb->prefix . 'mia_sync_heartbeat';
            
            // Verificar si la tabla existe
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
            if (!$table_exists) {
                $this->logger->error('Tabla mia_sync_heartbeat no existe para programar ejecución');
                return false;
            }
            
            // Programar próxima ejecución en la base de datos
            $next_execution = date('Y-m-d H:i:s', time() + $interval);
            
            $result = $wpdb->insert(
                $table_name,
                [
                    'sync_id' => 'heartbeat_worker_' . time(),
                    'status' => 'scheduled',
                    'last_heartbeat' => current_time('mysql'),
                    'next_execution' => $next_execution,
                    'entity' => 'heartbeat_worker',
                    'direction' => 'system_maintenance',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                [
                    '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
                ]
            );
            
            if ($result !== false) {
                $this->logger->info('Próxima ejecución programada en base de datos', [
                    'interval' => $interval,
                    'next_execution' => $next_execution
                ]);
                return true;
            } else {
                $this->logger->error('Error al programar ejecución en base de datos');
                return false;
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error al programar ejecución en base de datos', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Programa la limpieza automática en WordPress cron
     */
    private function scheduleAutomaticCleanupInWordPress(): void
    {
        try {
            if (!function_exists('wp_schedule_single_event')) {
                $this->logger->warning('WordPress cron no disponible para programar limpieza automática');
                return;
            }
            
            // Obtener intervalo de limpieza desde configuración
            $cleanup_interval = $this->config['lock_cleanup_interval'] ?? 300;
            
            // Limpiar eventos existentes para evitar duplicados
            wp_clear_scheduled_hook('mia_automatic_lock_cleanup');
            
            // Programar limpieza automática
            $next_cleanup = wp_schedule_single_event(
                time() + $cleanup_interval, 
                'mia_automatic_lock_cleanup'
            );
            
            if ($next_cleanup) {
                $this->logger->info('Limpieza automática programada en WordPress cron', [
                    'interval' => $cleanup_interval,
                    'next_execution' => date('Y-m-d H:i:s', time() + $cleanup_interval)
                ]);
            } else {
                $this->logger->warning('No se pudo programar limpieza automática en WordPress cron');
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error al programar limpieza automática en WordPress cron', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Programa la limpieza automática en la base de datos
     */
    private function scheduleAutomaticCleanupInDatabase(): bool
    {
        try {
            global $wpdb;
            
            if (!$wpdb) {
                $this->logger->error('No hay conexión a base de datos para programar limpieza automática');
                return false;
            }
            
            $table_name = $wpdb->prefix . 'mia_sync_heartbeat';
            
            // Verificar si la tabla existe
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
            if (!$table_exists) {
                $this->logger->error('Tabla mia_sync_heartbeat no existe para programar limpieza automática');
                return false;
            }

            // Obtener el último heartbeat registrado para la limpieza
            $last_heartbeat = $wpdb->get_var("SELECT last_heartbeat FROM {$table_name} ORDER BY last_heartbeat DESC LIMIT 1");
            
            // Si no hay registros previos, usar el tiempo actual
            if ($last_heartbeat === null) {
                $last_heartbeat = current_time('mysql');
                $this->logger->info('No hay registros previos, usando tiempo actual para programar limpieza');
            }
            
            // Calcular el tiempo de la próxima limpieza
            $next_cleanup_time = strtotime($last_heartbeat) + $this->config['lock_cleanup_interval'];

            // Programar la próxima limpieza en la base de datos
            $next_execution = date('Y-m-d H:i:s', $next_cleanup_time);

            // Insertar o actualizar el registro de limpieza
            $result = $wpdb->replace(
                $table_name,
                [
                    'sync_id' => 'lock_cleanup_' . time(),
                    'status' => 'scheduled',
                    'last_heartbeat' => current_time('mysql'),
                    'next_execution' => $next_execution,
                    'entity' => 'lock_cleanup',
                    'direction' => 'system_maintenance',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                [
                    '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
                ]
            );

            if ($result !== false) {
                $this->logger->info('Limpieza automática programada en base de datos', [
                    'next_execution' => $next_execution
                ]);
                return true;
            } else {
                $this->logger->error('Error al programar limpieza automática en base de datos');
                return false;
            }

        } catch (\Exception $e) {
            $this->logger->error('Error al programar limpieza automática en base de datos', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Obtiene estadísticas del worker unificado
     */
    public function getStats(): array
    {
        $last_execution = $this->getLastExecutionTime();
        
        return [
            'is_running' => $this->is_running,
            'last_cleanup_time' => $this->last_cleanup_time,
            'config' => $this->config,
            'active_locks_count' => count($this->active_locks),
            'cron_state_file' => $this->cron_state_file,
            'lock_cleanup_state_file' => $this->lock_cleanup_state_file,
            'execution_count' => $this->getExecutionCount(),
            'last_execution' => $last_execution
        ];
    }
    
    /**
     * Obtiene el tiempo de la última ejecución
     */
    private function getLastExecutionTime(): ?int
    {
        try {
            if (!file_exists($this->lock_cleanup_state_file)) {
                return null;
            }
            
            $file_content = file_get_contents($this->lock_cleanup_state_file);
            if ($file_content === false) {
                return null;
            }
            
            $state_data = json_decode($file_content, true);
            $last_execution = $state_data['last_execution'] ?? null;
            
            if ($last_execution === null || !is_numeric($last_execution)) {
                return null;
            }
            
            return (int) $last_execution;
            
        } catch (\Exception $e) {
            return null;
        }
    }
}
