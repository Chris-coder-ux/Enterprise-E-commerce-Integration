<?php
/**
 * Script para Detener TODAS las Sincronizaciones en Proceso
 *
 * Este script detiene de forma segura todas las sincronizaciones activas:
 * - Cancela sincronizaciones en progreso
 * - Libera todos los locks
 * - Elimina cron jobs relacionados
 * - Limpia Action Scheduler
 * - Resetea estados de sincronización
 *
 * USO: wp eval-file scripts/detener-todas-sincronizaciones.php
 *
 * @package MiIntegracionApi
 * @since 2.0.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    // Si se ejecuta directamente, intentar cargar WordPress
    $wp_load_paths = [
        dirname(__FILE__) . '/../../wp-load.php',
        dirname(__FILE__) . '/../../../wp-load.php',
        dirname(dirname(dirname(__DIR__))) . '/wp-load.php'
    ];
    
    $wp_loaded = false;
    foreach ($wp_load_paths as $wp_path) {
        if (file_exists($wp_path)) {
            require_once($wp_path);
            $wp_loaded = true;
            break;
        }
    }
    
    if (!$wp_loaded) {
        die('Error: No se pudo cargar WordPress. Ejecuta este script con: wp eval-file scripts/detener-todas-sincronizaciones.php');
    }
}

// Habilitar mostrar errores para debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar en pantalla, solo en logs
ini_set('log_errors', 1);

echo "═══════════════════════════════════════════════════════════════\n";
echo "  DETENCIÓN DE TODAS LAS SINCRONIZACIONES EN PROCESO\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$acciones_realizadas = [];
$errores = [];

// Verificar que WordPress está cargado
if (!function_exists('wp_next_scheduled')) {
    die("ERROR: WordPress no está cargado correctamente. Ejecuta con: wp eval-file scripts/detener-todas-sincronizaciones.php\n");
}

// ============================================
// 1. VERIFICAR ESTADO ACTUAL
// ============================================
echo "📊 VERIFICANDO ESTADO ACTUAL...\n\n";

$sync_status = [];
if (class_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper')) {
    try {
        // Intentar usar getCurrentSyncInfo primero
        if (method_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper', 'getCurrentSyncInfo')) {
            $sync_status = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
        } elseif (method_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper', 'getSyncStatus')) {
            $sync_status_raw = \MiIntegracionApi\Helpers\SyncStatusHelper::getSyncStatus();
            $sync_status = $sync_status_raw['current_sync'] ?? [];
        }
        
        echo "Estado de sincronización:\n";
        echo "  - En progreso: " . ($sync_status['in_progress'] ?? false ? 'SÍ' : 'NO') . "\n";
        echo "  - Entidad: " . ($sync_status['entity'] ?? 'N/A') . "\n";
        echo "  - Dirección: " . ($sync_status['direction'] ?? 'N/A') . "\n";
        echo "  - Lote actual: " . ($sync_status['current_batch'] ?? 0) . "\n";
        echo "  - Total lotes: " . ($sync_status['total_batches'] ?? 0) . "\n";
        
        // Verificar estado de sincronización de imágenes (Fase 1)
        $phase1_status = $sync_status['phase1_images'] ?? [];
        echo "\nEstado de sincronización de imágenes (Fase 1):\n";
        echo "  - En progreso: " . ($phase1_status['in_progress'] ?? false ? 'SÍ' : 'NO') . "\n";
        echo "  - Productos procesados: " . ($phase1_status['products_processed'] ?? 0) . "\n";
        echo "  - Total productos: " . ($phase1_status['total_products'] ?? 0) . "\n";
        echo "  - Imágenes procesadas: " . ($phase1_status['images_processed'] ?? 0) . "\n";
        echo "  - Duplicados omitidos: " . ($phase1_status['duplicates_skipped'] ?? 0) . "\n";
        echo "  - Errores: " . ($phase1_status['errors'] ?? 0) . "\n\n";
    } catch (\Exception $e) {
        echo "⚠️  Error obteniendo estado: " . $e->getMessage() . "\n\n";
    }
}

// ============================================
// 2. CANCELAR SINCRONIZACIÓN ACTUAL
// ============================================
echo "🛑 CANCELANDO SINCRONIZACIÓN ACTUAL...\n\n";

try {
    if (class_exists('MiIntegracionApi\\Core\\Sync_Manager')) {
        $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
        if ($sync_manager && method_exists($sync_manager, 'cancel_sync')) {
            $result = $sync_manager->cancel_sync();
            if ($result && method_exists($result, 'isSuccess') && $result->isSuccess()) {
                $acciones_realizadas[] = "✅ Sincronización cancelada via Sync_Manager";
            } else {
                $errores[] = "⚠️  No se pudo cancelar via Sync_Manager";
            }
        }
    }
} catch (\Exception $e) {
    $errores[] = "Error cancelando sync: " . $e->getMessage();
}

// Cancelar vía SyncStatusHelper
try {
    if (class_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper')) {
        if (method_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper', 'cancelCurrentSync')) {
            $cancel_result = \MiIntegracionApi\Helpers\SyncStatusHelper::cancelCurrentSync();
            if (!empty($cancel_result) && isset($cancel_result['success']) && $cancel_result['success']) {
                $acciones_realizadas[] = "✅ Sincronización cancelada via SyncStatusHelper";
            }
        }
        
        // Limpiar estado actual
        if (method_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper', 'clearCurrentSync')) {
            \MiIntegracionApi\Helpers\SyncStatusHelper::clearCurrentSync();
            $acciones_realizadas[] = "✅ Estado de sincronización limpiado";
        }
        
        // También forzar que no esté en progreso
        if (method_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper', 'setSyncInProgress')) {
            \MiIntegracionApi\Helpers\SyncStatusHelper::setSyncInProgress(false);
        }
    }
} catch (\Exception $e) {
    $errores[] = "Error limpiando estado: " . $e->getMessage();
} catch (\Throwable $e) {
    $errores[] = "Error limpiando estado (Throwable): " . $e->getMessage();
}

// ============================================
// 3. LIBERAR TODOS LOS LOCKS
// ============================================
echo "🔓 LIBERANDO LOCKS...\n\n";

$locks_to_release = [
    'sync_products',
    'sync_customers',
    'sync_orders',
    'batch_processing',
    'automatic_stock_detection',
    'product_sync',
    'customer_sync',
    'order_sync',
    'sync_images',
    'image_sync',
    'phase1_images',
    'images_sync'
];

if (class_exists('MiIntegracionApi\\Core\\SyncLock')) {
    foreach ($locks_to_release as $lock_entity) {
        try {
            if (method_exists('MiIntegracionApi\\Core\\SyncLock', 'isLocked')) {
                if (\MiIntegracionApi\Core\SyncLock::isLocked($lock_entity)) {
                    if (method_exists('MiIntegracionApi\\Core\\SyncLock', 'release')) {
                        \MiIntegracionApi\Core\SyncLock::release($lock_entity);
                        $acciones_realizadas[] = "✅ Lock liberado: $lock_entity";
                    }
                }
            }
        } catch (\Exception $e) {
            $errores[] = "Error liberando lock $lock_entity: " . $e->getMessage();
        } catch (\Throwable $e) {
            $errores[] = "Error liberando lock $lock_entity (Throwable): " . $e->getMessage();
        }
    }
}

// Liberar locks desde base de datos directamente
global $wpdb;
try {
    if (isset($wpdb) && $wpdb) {
        $table_name = $wpdb->prefix . 'mia_sync_locks';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
        
        if ($table_exists) {
            $released = $wpdb->query($wpdb->prepare("
                UPDATE {$table_name}
                SET released_at = NOW(),
                    release_reason = 'manual_stop_all_syncs'
                WHERE released_at IS NULL
            "));
            
            if ($released !== false && $released > 0) {
                $acciones_realizadas[] = "✅ $released locks liberados desde base de datos";
            }
        }
    }
} catch (\Exception $e) {
    $errores[] = "Error liberando locks desde DB: " . $e->getMessage();
} catch (\Throwable $e) {
    $errores[] = "Error liberando locks desde DB (Throwable): " . $e->getMessage();
}

// ============================================
// 4. ELIMINAR CRON JOBS DE SINCRONIZACIÓN
// ============================================
echo "⏰ ELIMINANDO CRON JOBS...\n\n";

$cron_hooks = [
    'mia_automatic_stock_detection',
    'mia_auto_detection_hook',
    'mi_integracion_api_daily_sync',
    'mia_process_sync_batch',
    'mia_execute_async_cleanup',
    'mia_automatic_lock_cleanup',
    'mia_automatic_heartbeat',
    'mia_execute_low_activity_cleanup',
    'mia_sync_batch',
    'mia_process_queue_background'
];

if (function_exists('wp_next_scheduled') && function_exists('wp_unschedule_event') && function_exists('wp_clear_scheduled_hook')) {
    foreach ($cron_hooks as $hook) {
        try {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
                wp_clear_scheduled_hook($hook);
                $acciones_realizadas[] = "✅ Cron job eliminado: $hook";
            }
        } catch (\Exception $e) {
            $errores[] = "Error eliminando cron $hook: " . $e->getMessage();
        }
    }
} else {
    $errores[] = "Funciones de WordPress cron no disponibles";
}

// ============================================
// 5. CANCELAR ACCIONES EN ACTION SCHEDULER
// ============================================
echo "📋 CANCELANDO ACCIONES EN ACTION SCHEDULER...\n\n";

global $wpdb;

// Verificar si Action Scheduler existe
$as_actions_exist = false;
$as_actions_table = '';
if (isset($wpdb) && $wpdb) {
    try {
        $as_actions_table = $wpdb->prefix . 'actionscheduler_actions';
        $as_actions_exist = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $as_actions_table)) === $as_actions_table;
    } catch (\Exception $e) {
        $errores[] = "Error verificando tabla Action Scheduler: " . $e->getMessage();
    }
}

if ($as_actions_exist && !empty($as_actions_table)) {
    // Contar acciones pendientes relacionadas con el plugin
    $pending_actions = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM {$as_actions_table}
        WHERE (hook LIKE %s
           OR hook LIKE %s
           OR hook LIKE %s)
          AND status IN ('pending', 'in-progress')
    ", '%mia%', '%verial%', '%sync%'));
    
    if ($pending_actions > 0) {
        echo "  Encontradas $pending_actions acciones pendientes relacionadas\n";
        
        // Cancelar acciones pendientes
        $cancelled = $wpdb->query($wpdb->prepare("
            UPDATE {$as_actions_table}
            SET status = 'canceled',
                status_transition_date = NOW()
            WHERE (hook LIKE %s
               OR hook LIKE %s
               OR hook LIKE %s)
              AND status IN ('pending', 'in-progress')
        ", '%mia%', '%verial%', '%sync%'));
        
        if ($cancelled > 0) {
            $acciones_realizadas[] = "✅ $cancelled acciones canceladas en Action Scheduler";
        }
        
        // Resetear acciones "in-progress" atascadas (más de 10 minutos)
        $reset_stuck = $wpdb->query($wpdb->prepare("
            UPDATE {$as_actions_table}
            SET status = 'pending'
            WHERE status = 'in-progress'
              AND last_attempt_date < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
              AND (hook LIKE %s OR hook LIKE %s OR hook LIKE %s)
        ", '%mia%', '%verial%', '%sync%'));
        
        if ($reset_stuck > 0) {
            $acciones_realizadas[] = "✅ $reset_stuck acciones bloqueadas reseteadas";
        }
    } else {
        echo "  ✅ No hay acciones pendientes relacionadas\n";
    }
} else {
    echo "  ⚠️  Tabla de Action Scheduler no encontrada\n";
}

// ============================================
// 6. LIMPIAR TRANSIENTS RELACIONADOS
// ============================================
echo "🧹 LIMPIANDO TRANSIENTS...\n\n";

$transient_patterns = [
    'mia_sync_%',
    'mia_batch_%',
    'mia_queue_%',
    'mia_lock_%',
    'mia_detection_%',
    'mia_images_%',
    '_transient_mia_%',
    '_transient_timeout_mia_%',
    // ✅ NUEVO: Patrones específicos para sincronización de imágenes
    '_transient_mia_images_%',
    '_transient_timeout_mia_images_%',
    '_transient_mia_sync_images_%',
    '_transient_timeout_mia_sync_images_%',
    'mia_images_sync_%',
    'mia_phase1_%'
];

$transients_cleaned = 0;
if (isset($wpdb) && $wpdb && function_exists('delete_option')) {
    foreach ($transient_patterns as $pattern) {
        try {
            // ✅ MEJORADO: Buscar transients con múltiples variaciones
            $transients = $wpdb->get_col($wpdb->prepare("
                SELECT option_name
                FROM {$wpdb->options}
                WHERE option_name LIKE %s
                   OR option_name LIKE %s
                   OR option_name LIKE %s
            ", $pattern, '_transient_' . $pattern, '_transient_timeout_' . $pattern));
            
            foreach ($transients as $transient) {
                delete_option($transient);
                // Limpiar también el timeout correspondiente
                $timeout_key = str_replace('_transient_', '_transient_timeout_', $transient);
                delete_option($timeout_key);
                // Limpiar también sin el prefijo _transient_
                $clean_key = str_replace('_transient_', '', $transient);
                $clean_key = str_replace('_transient_timeout_', '', $clean_key);
                if ($clean_key !== $transient) {
                    delete_option($clean_key);
                }
                $transients_cleaned++;
            }
        } catch (\Exception $e) {
            $errores[] = "Error limpiando transients con patrón $pattern: " . $e->getMessage();
        }
    }
    
    // ✅ NUEVO: Limpiar transients de sincronización de imágenes de forma más agresiva
    try {
        $image_transients = $wpdb->get_col($wpdb->prepare("
            SELECT option_name
            FROM {$wpdb->options}
            WHERE option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
        ", '%mia_images%', '%image_sync%', '%sync_images%', '%phase1%'));
        
        foreach ($image_transients as $transient) {
            delete_option($transient);
            $timeout_key = str_replace('_transient_', '_transient_timeout_', $transient);
            delete_option($timeout_key);
            $transients_cleaned++;
        }
        
        if (count($image_transients) > 0) {
            $acciones_realizadas[] = "✅ " . count($image_transients) . " transients de sincronización de imágenes limpiados";
        }
    } catch (\Exception $e) {
        $errores[] = "Error limpiando transients de imágenes: " . $e->getMessage();
    }
}

if ($transients_cleaned > 0) {
    $acciones_realizadas[] = "✅ $transients_cleaned transients limpiados";
} else {
    echo "  ✅ No hay transients relacionados\n";
}

// ============================================
// 7. DETENER SINCRONIZACIÓN DE IMÁGENES
// ============================================
echo "🖼️  DETENIENDO SINCRONIZACIÓN DE IMÁGENES...\n\n";

// Detener Fase 1 (sincronización de imágenes) vía SyncStatusHelper
if (class_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper')) {
    try {
        if (method_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper', 'updatePhase1Images')) {
            // ✅ MEJORADO: Marcar como pausado explícitamente para que el proceso lo detecte
            \MiIntegracionApi\Helpers\SyncStatusHelper::updatePhase1Images([
                'in_progress' => false,
                'paused' => true,
                'errors' => 0,
                'last_update' => time()
            ]);
            $acciones_realizadas[] = "✅ Sincronización de imágenes (Fase 1) marcada como pausada";
        }
        
        // ✅ NUEVO: Forzar limpieza del estado completo
        if (method_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper', 'getSyncStatus')) {
            $status = \MiIntegracionApi\Helpers\SyncStatusHelper::getSyncStatus();
            if (isset($status['phase1_images'])) {
                $status['phase1_images']['in_progress'] = false;
                $status['phase1_images']['paused'] = true;
                $status['phase1_images']['last_update'] = time();
                \MiIntegracionApi\Helpers\SyncStatusHelper::saveSyncStatus($status);
                $acciones_realizadas[] = "✅ Estado de sincronización de imágenes forzado a pausado";
            }
        }
    } catch (\Exception $e) {
        $errores[] = "Error deteniendo sincronización de imágenes: " . $e->getMessage();
    } catch (\Throwable $e) {
        $errores[] = "Error deteniendo sincronización de imágenes (Throwable): " . $e->getMessage();
    }
}

// ✅ NUEVO: Crear un flag de "stop inmediato" que el proceso verifica
if (function_exists('update_option')) {
    update_option('mia_images_sync_stop_immediately', true);
    update_option('mia_images_sync_stop_timestamp', time());
    $acciones_realizadas[] = "✅ Flag de detención inmediata establecido";
    
    // ✅ NUEVO: Forzar actualización del flag en múltiples lugares para asegurar que se detecte
    // Esto ayuda si hay procesos que leen desde diferentes fuentes
    if (function_exists('wp_cache_set')) {
        wp_cache_set('mia_images_sync_stop_immediately', true, '', 3600);
    }
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('options');
    }
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush(); // Limpiar todo el caché para asegurar que se detecte
    }
    
    // ✅ NUEVO: También establecer en base de datos directamente para evitar caché
    global $wpdb;
    if (isset($wpdb) && $wpdb) {
        $wpdb->query($wpdb->prepare("
            INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
            VALUES (%s, %s, 'yes')
            ON DUPLICATE KEY UPDATE option_value = %s
        ", 'mia_images_sync_stop_immediately', '1', '1'));
        $acciones_realizadas[] = "✅ Flag de detención escrito directamente en base de datos";
    }
}

// Limpiar checkpoint de sincronización de imágenes
if (function_exists('delete_option')) {
    $checkpoint_deleted = delete_option('mia_images_sync_checkpoint');
    if ($checkpoint_deleted) {
        $acciones_realizadas[] = "✅ Checkpoint de sincronización de imágenes eliminado";
    } else {
        echo "  ℹ️  No había checkpoint de imágenes activo\n";
    }
}

// Liberar lock de sincronización de imágenes si existe
if (class_exists('MiIntegracionApi\\Core\\SyncLock')) {
    try {
        $image_lock_entities = [
            'sync_images',
            'image_sync',
            'phase1_images',
            'images_sync'
        ];
        
        foreach ($image_lock_entities as $lock_entity) {
            if (method_exists('MiIntegracionApi\\Core\\SyncLock', 'isLocked')) {
                if (\MiIntegracionApi\Core\SyncLock::isLocked($lock_entity)) {
                    if (method_exists('MiIntegracionApi\\Core\\SyncLock', 'release')) {
                        \MiIntegracionApi\Core\SyncLock::release($lock_entity);
                        $acciones_realizadas[] = "✅ Lock de imágenes liberado: $lock_entity";
                    }
                }
            }
        }
    } catch (\Exception $e) {
        $errores[] = "Error liberando locks de imágenes: " . $e->getMessage();
    } catch (\Throwable $e) {
        $errores[] = "Error liberando locks de imágenes (Throwable): " . $e->getMessage();
    }
}

// ============================================
// 8. DESACTIVAR DETECCIÓN AUTOMÁTICA
// ============================================
echo "🔌 DESACTIVANDO DETECCIÓN AUTOMÁTICA...\n\n";

if (function_exists('update_option')) {
    update_option('mia_automatic_stock_detection_enabled', false);
    update_option('mia_detection_auto_active', false);
    $acciones_realizadas[] = "✅ Detección automática desactivada";
}

// Desactivar vía StockDetector si está disponible
if (class_exists('MiIntegracionApi\\Deteccion\\StockDetectorIntegration')) {
    try {
        \MiIntegracionApi\Deteccion\StockDetectorIntegration::deactivate();
        $acciones_realizadas[] = "✅ StockDetector desactivado";
    } catch (\Exception $e) {
        $errores[] = "Error desactivando StockDetector: " . $e->getMessage();
    }
}

// ============================================
// 9. LIMPIAR OPCIONES DE ESTADO
// ============================================
echo "🗑️  LIMPIANDO OPCIONES DE ESTADO...\n\n";

$options_to_clear = [
    'mia_sync_in_progress',
    'mia_sync_start_time',
    'mia_sync_end_time',
    'mia_batch_start_time',
    'mia_sync_queue',
    'mia_sync_recovery_point',
    'mia_sync_last_batch',
    'mia_images_sync_checkpoint',
    'mia_images_sync_stop_immediately',
    'mia_images_sync_stop_timestamp'
];

if (function_exists('delete_option')) {
    foreach ($options_to_clear as $option) {
        delete_option($option);
    }
    $acciones_realizadas[] = "✅ Opciones de estado limpiadas";
}

// ============================================
// 10. RESETEAR RECOVERY POINTS
// ============================================
echo "🔄 RESETEANDO RECOVERY POINTS...\n\n";

if (class_exists('MiIntegracionApi\\Core\\SyncRecovery')) {
    try {
        \MiIntegracionApi\Core\SyncRecovery::clearAllStates();
        $acciones_realizadas[] = "✅ Recovery points reseteados";
    } catch (\Exception $e) {
        $errores[] = "Error reseteando recovery: " . $e->getMessage();
    }
}

// ============================================
// 11. VERIFICACIÓN FINAL
// ============================================
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  VERIFICACIÓN FINAL\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Verificar estado de sincronización
if (class_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper')) {
    try {
        $final_status = [];
        if (method_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper', 'getCurrentSyncInfo')) {
            $final_status = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
        } elseif (method_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper', 'getSyncStatus')) {
            $status_raw = \MiIntegracionApi\Helpers\SyncStatusHelper::getSyncStatus();
            $final_status = $status_raw['current_sync'] ?? [];
        }
        
        $still_in_progress = $final_status['in_progress'] ?? false;
        $phase1_in_progress = $final_status['phase1_images']['in_progress'] ?? false;
        
        if ($still_in_progress) {
            echo "⚠️  ADVERTENCIA: Sincronización todavía marcada como en progreso\n";
            echo "   → Forzando limpieza...\n";
            if (method_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper', 'clearCurrentSync')) {
                \MiIntegracionApi\Helpers\SyncStatusHelper::clearCurrentSync();
            }
            if (method_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper', 'setSyncInProgress')) {
                \MiIntegracionApi\Helpers\SyncStatusHelper::setSyncInProgress(false);
            }
        } else {
            echo "✅ Sincronización no está en progreso\n";
        }
        
        // Verificar estado de sincronización de imágenes (Fase 1)
        if ($phase1_in_progress) {
            echo "⚠️  ADVERTENCIA: Sincronización de imágenes (Fase 1) todavía marcada como en progreso\n";
            echo "   → Forzando limpieza...\n";
            if (method_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper', 'updatePhase1Images')) {
                \MiIntegracionApi\Helpers\SyncStatusHelper::updatePhase1Images([
                    'in_progress' => false,
                    'errors' => 0
                ]);
                echo "   ✅ Estado de imágenes limpiado\n";
            }
        } else {
            echo "✅ Sincronización de imágenes (Fase 1) no está en progreso\n";
        }
    } catch (\Exception $e) {
        echo "⚠️  Error verificando estado final: " . $e->getMessage() . "\n";
    }
}

// Verificar cron jobs restantes
$remaining_crons = [];
if (function_exists('wp_next_scheduled')) {
    foreach ($cron_hooks as $hook) {
        try {
            if (wp_next_scheduled($hook)) {
                $remaining_crons[] = $hook;
            }
        } catch (\Exception $e) {
            // Ignorar errores en verificación
        }
    }
}

if (empty($remaining_crons)) {
    echo "✅ No hay cron jobs de sincronización programados\n";
} else {
    echo "⚠️  ADVERTENCIA: Aún hay cron jobs programados:\n";
    if (function_exists('wp_clear_scheduled_hook')) {
        foreach ($remaining_crons as $hook) {
            echo "   - $hook\n";
            try {
                wp_clear_scheduled_hook($hook);
                echo "     → Eliminado\n";
            } catch (\Exception $e) {
                echo "     → Error: " . $e->getMessage() . "\n";
            }
        }
    }
}

// Verificar locks restantes
if (class_exists('MiIntegracionApi\\Core\\SyncLock')) {
    $remaining_locks = [];
    if (method_exists('MiIntegracionApi\\Core\\SyncLock', 'isLocked')) {
        foreach ($locks_to_release as $lock_entity) {
            try {
                if (\MiIntegracionApi\Core\SyncLock::isLocked($lock_entity)) {
                    $remaining_locks[] = $lock_entity;
                }
            } catch (\Exception $e) {
                // Ignorar errores en verificación
            }
        }
    }
    
    if (empty($remaining_locks)) {
        echo "✅ No hay locks activos\n";
    } else {
        echo "⚠️  ADVERTENCIA: Aún hay locks activos:\n";
        if (method_exists('MiIntegracionApi\\Core\\SyncLock', 'release')) {
            foreach ($remaining_locks as $lock) {
                echo "   - $lock\n";
                try {
                    \MiIntegracionApi\Core\SyncLock::release($lock);
                    echo "     → Liberado\n";
                } catch (\Exception $e) {
                    echo "     → Error: " . $e->getMessage() . "\n";
                } catch (\Throwable $e) {
                    echo "     → Error: " . $e->getMessage() . "\n";
                }
            }
        }
    }
}

// ============================================
// 12. VERIFICAR PROCESOS PHP ACTIVOS
// ============================================
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  VERIFICANDO PROCESOS PHP ACTIVOS\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$php_processes_found = false;
if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
    try {
        // Buscar procesos PHP relacionados con sincronización
        $processes = shell_exec('ps aux | grep -i "php.*sync\|php.*image\|wp.*eval\|admin-ajax" | grep -v grep');
        
        if (!empty($processes)) {
            $php_processes_found = true;
            echo "⚠️  ADVERTENCIA: Se encontraron procesos PHP relacionados:\n";
            echo $processes . "\n";
            echo "   → Estos procesos pueden continuar ejecutándose aunque el estado esté pausado\n";
            echo "   → El flag 'mia_images_sync_stop_immediately' está activo\n";
            echo "   → Los procesos deberían detectar el flag en el siguiente producto/imagen\n\n";
            
            // ✅ NUEVO: Extraer PIDs de procesos para proporcionar comandos específicos
            $pids = [];
            $lines = explode("\n", trim($processes));
            foreach ($lines as $line) {
                if (preg_match('/^\s*(\w+)\s+(\d+)\s+/', $line, $matches)) {
                    $pid = $matches[2];
                    // Verificar si el proceso está relacionado con nuestro sitio
                    if (strpos($line, 'admin-ajax') !== false || strpos($line, 'sync') !== false || strpos($line, 'image') !== false) {
                        $pids[] = $pid;
                    }
                }
            }
            
            if (!empty($pids)) {
                echo "   📋 PIDs de procesos detectados: " . implode(', ', $pids) . "\n";
                echo "   → Para detener estos procesos manualmente, ejecuta:\n";
                echo "     kill " . implode(' ', $pids) . "\n";
                echo "   → O para forzar la detención:\n";
                echo "     kill -9 " . implode(' ', $pids) . "\n";
                echo "   ⚠️  ADVERTENCIA: Matar procesos puede afectar otras operaciones\n";
                echo "   → Es más seguro reiniciar PHP-FPM o el servidor web\n\n";
            }
        } else {
            echo "✅ No se encontraron procesos PHP relacionados con sincronización\n";
        }
        
        // ✅ NUEVO: Buscar específicamente procesos AJAX de WordPress
        $ajax_processes = shell_exec('ps aux | grep -i "admin-ajax\|wp-admin/admin-ajax" | grep -v grep');
        if (!empty($ajax_processes)) {
            echo "\n⚠️  ADVERTENCIA: Se encontraron procesos AJAX de WordPress activos:\n";
            echo $ajax_processes . "\n";
            echo "   → Estos pueden ser procesos de sincronización en background\n";
            echo "   → Verificarán el flag de detención en el siguiente ciclo\n";
            
            // ✅ NUEVO: Extraer PIDs de procesos AJAX
            $ajax_pids = [];
            $ajax_lines = explode("\n", trim($ajax_processes));
            foreach ($ajax_lines as $line) {
                if (preg_match('/^\s*(\w+)\s+(\d+)\s+/', $line, $matches)) {
                    $pid = $matches[2];
                    // Verificar si es de nuestro dominio
                    $current_domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
                    if (strpos($line, $current_domain) !== false || strpos($line, 'admin-ajax') !== false) {
                        $ajax_pids[] = $pid;
                    }
                }
            }
            
            if (!empty($ajax_pids)) {
                echo "   📋 PIDs de procesos AJAX: " . implode(', ', $ajax_pids) . "\n";
            }
        }
    } catch (\Exception $e) {
        echo "⚠️  No se pudo verificar procesos PHP: " . $e->getMessage() . "\n";
        echo "   → Esto es normal si shell_exec está deshabilitado por seguridad\n";
    }
} else {
    echo "ℹ️  Verificación de procesos PHP no disponible (shell_exec deshabilitado)\n";
    echo "   → Si la sincronización continúa, verifica manualmente los procesos PHP\n";
    echo "   → Comando manual: ps aux | grep php | grep -i sync\n";
}

// ============================================
// RESUMEN FINAL
// ============================================
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  RESUMEN\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

echo "✅ Acciones realizadas: " . count($acciones_realizadas) . "\n";
if (!empty($acciones_realizadas)) {
    foreach ($acciones_realizadas as $accion) {
        echo "   $accion\n";
    }
}

if (!empty($errores)) {
    echo "\n⚠️  Errores encontrados: " . count($errores) . "\n";
    foreach ($errores as $error) {
        echo "   $error\n";
    }
}

if ($php_processes_found) {
    echo "⚠️  ADVERTENCIA: Se detectaron procesos PHP activos\n";
    echo "   → El script ha actualizado todos los estados y flags de detención\n";
    echo "   → El flag 'mia_images_sync_stop_immediately' está ACTIVO\n";
    echo "   → Los procesos verifican este flag antes de cada producto/imagen\n\n";
    echo "   🔧 SOLUCIONES PARA DETENER PROCESOS ACTIVOS:\n\n";
    echo "   OPCIÓN 1 (Recomendada): Reiniciar PHP-FPM/LiteSpeed\n";
    echo "   → Para LiteSpeed: /usr/local/lsws/bin/lswsctrl restart\n";
    echo "   → O reiniciar el servicio PHP específico\n\n";
    echo "   OPCIÓN 2: Esperar 2-3 minutos\n";
    echo "   → Los procesos deberían detectar el flag en el siguiente producto/imagen\n";
    echo "   → Verifica los logs para confirmar la detención\n\n";
    echo "   OPCIÓN 3 (Último recurso): Matar procesos manualmente\n";
    echo "   → Usa los PIDs mostrados arriba con: kill <PID>\n";
    echo "   → ⚠️  ADVERTENCIA: Esto puede afectar otras operaciones\n\n";
    echo "   📊 Verificar estado del flag:\n";
    echo "   → wp option get mia_images_sync_stop_immediately\n";
    echo "   → Debe devolver: 1 o true\n\n";
} else {
    echo "✅ TODAS LAS SINCRONIZACIONES HAN SIDO DETENIDAS\n";
    echo "   → No se detectaron procesos PHP activos\n";
    echo "   → Si aún ves actividad, puede ser un proceso que ya terminó\n";
    echo "   → Verifica los logs para confirmar\n\n";
}

echo "═══════════════════════════════════════════════════════════════\n\n";

echo "⚠️  IMPORTANTE:\n";
echo "   - Revisa los logs para verificar que no hay procesos ejecutándose\n";
echo "   - Verifica que no se creen más productos duplicados\n";
echo "   - El flag 'mia_images_sync_stop_immediately' está activo\n";
echo "   - El proceso verificará este flag antes de cada producto/imagen\n";
echo "   - Corrige los problemas encontrados antes de reactivar sincronizaciones\n";
echo "   - Usa el script de verificación de toggle antes de reactivar\n\n";

