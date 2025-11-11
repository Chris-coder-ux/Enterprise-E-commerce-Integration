<?php

declare(strict_types=1);

namespace MiIntegracionApi\Hooks;

use MiIntegracionApi\Core\PluginActivator;
use MiIntegracionApi\Core\HeartbeatWorker;

/**
 * 🚀 HOOKS PARA INTEGRAR EL SISTEMA UNIFICADO CON WORDPRESS
 * Esta clase registra todos los hooks necesarios para que el sistema
 * unificado funcione automáticamente con WordPress
 * @package MiIntegracionApi\Hooks
 * @since 1.4.0
 */
class UnifiedSystemHooks
{
    /**
     * Registra todos los hooks del sistema unificado
     */
    public static function register(): void
    {
        // SOLUCIÓN: Solo registrar hooks de eventos, NO de inicialización
        // La inicialización se maneja desde maybe_initialize_plugin()
        
        // Hook para iniciar HeartbeatWorker
        add_action('mia_start_heartbeat_worker', [self::class, 'startHeartbeatWorker']);
        
        // Hook para limpieza automática de locks
        add_action('mia_automatic_lock_cleanup', [self::class, 'executeAutomaticCleanup']);
        
        // Hook para detener sistema cuando se desactiva el plugin
        add_action('deactivate_mi-integracion-api/mi-integracion-api.php', [self::class, 'onPluginDeactivation']);
        
        // Hook para limpiar cuando se desinstala el plugin
        add_action('uninstall_mi-integracion-api/mi-integracion-api.php', [self::class, 'onPluginUninstall']);
        
        // Hook para heartbeat manual (mantener compatibilidad)
        add_action('wp_ajax_mia_sync_heartbeat', [self::class, 'manualHeartbeat']);
        
        // Hook para verificar estado del sistema
        add_action('wp_ajax_mia_system_status', [self::class, 'getSystemStatus']);
        
        // Hook para renovar nonce
        add_action('wp_ajax_mia_renew_nonce', [self::class, 'renewNonce']);
    }
    
    /**
     * Inicializa el sistema unificado
     * Este método es llamado desde maybe_initialize_plugin() para centralizar
     * toda la inicialización en un solo lugar.
     * @return void
     */
    public static function initializeUnifiedSystem(): void
    {
        try {
            $activator = PluginActivator::getInstance();
            $activator->initializeUnifiedSystem();
        } catch (\Exception $e) {
            // Log del error pero no fallar la inicialización de WordPress
            error_log('Error al inicializar sistema unificado: ' . $e->getMessage());
        }
    }
    
    /**
     * Hook ejecutado cuando se cargan los plugins
     */
    public static function onPluginsLoaded(): void
    {
        // SOLUCIÓN: Evitar ejecución en wp-cron y requests no principales
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }
        
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        
        // Verificar que la función is_plugin_active esté disponible
        if (!function_exists('is_plugin_active')) {
            // Si no está disponible, verificar de otra manera
            if (!defined('ABSPATH') || !file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
                return;
            }
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        // Verificar que el plugin esté activo
        if (!is_plugin_active('mi-integracion-api/mi-integracion-api.php')) {
            return;
        }
        
        // PREVENCIÓN: Evitar inicialización múltiple
        static $unified_system_initialized = false;
        if ($unified_system_initialized) {
            return;
        }
        
        // Inicializar sistema si no se ha hecho ya
        $activator = PluginActivator::getInstance();
        if (!$activator->getSystemStatus()['system_initialized']) {
            $activator->initializeUnifiedSystem();
            $unified_system_initialized = true;
        }
    }
    
    /**
     * Inicia el HeartbeatWorker
     */
    public static function startHeartbeatWorker(): void
    {
        try {
            $heartbeatWorker = new HeartbeatWorker();
            $heartbeatWorker->start();
        } catch (\Exception $e) {
            error_log('Error al iniciar HeartbeatWorker: ' . $e->getMessage());
        }
    }
    
    /**
     * Ejecuta la limpieza automática de locks
     */
    public static function executeAutomaticCleanup(): void
    {
        try {
            $heartbeatWorker = new HeartbeatWorker();
            $heartbeatWorker->executeUnifiedCycle();
            
            // Reprogramar siguiente ejecución
            $cleanup_interval = (int) get_option('mia_lock_cleanup_interval', 300);
            wp_schedule_single_event(time() + $cleanup_interval, 'mia_automatic_lock_cleanup');
            
        } catch (\Exception $e) {
            error_log('Error en limpieza automática: ' . $e->getMessage());
        }
    }
    
    /**
     * Hook ejecutado cuando se desactiva el plugin
     */
    public static function onPluginDeactivation(): void
    {
        try {
            $activator = PluginActivator::getInstance();
            $activator->stopUnifiedSystem();
        } catch (\Exception $e) {
            error_log('Error al desactivar sistema unificado: ' . $e->getMessage());
        }
    }
    
    /**
     * Hook ejecutado cuando se desinstala el plugin
     */
    public static function onPluginUninstall(): void
    {
        try {
            // Limpiar todas las opciones del sistema unificado
            $unified_options = [
                'mia_global_lock_timeout',
                'mia_batch_lock_timeout',
                'mia_heartbeat_interval',
                'mia_heartbeat_timeout',
                'mia_lock_cleanup_interval',
                'mia_process_dead_timeout',
                'mia_unified_system_enabled',
                'mia_automatic_heartbeat',
                'mia_dead_process_detection',
                'mia_proactive_cleanup',
                'mia_heartbeat_logging',
                'mia_cleanup_logging',
                'mia_dead_process_logging',
                'mia_max_cleanup_retries',
                'mia_cleanup_retry_delay',
                'mia_heartbeat_failure_threshold'
            ];
            
            foreach ($unified_options as $option) {
                delete_option($option);
            }
            
            // Limpiar eventos programados
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook('mia_start_heartbeat_worker');
                wp_clear_scheduled_hook('mia_automatic_lock_cleanup');
            }
            
        } catch (\Exception $e) {
            error_log('Error al desinstalar sistema unificado: ' . $e->getMessage());
        }
    }
    
    /**
     * Heartbeat manual para compatibilidad con AJAX existente
     */
    public static function manualHeartbeat(): void
    {
        try {
            // Verificar nonce para seguridad
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_heartbeat_nonce')) {
                wp_die('Nonce inválido');
            }
            
            $heartbeatWorker = new HeartbeatWorker();
            $heartbeatWorker->executeUnifiedCycle();
            
            wp_send_json_success([
                'message' => 'Heartbeat manual ejecutado',
                'timestamp' => current_time('mysql')
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'Error en heartbeat manual: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Endpoint AJAX para obtener estado del sistema
     */
    public static function getSystemStatus(): void
    {
        try {
            // Verificar nonce para seguridad
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mi_integracion_api_nonce_dashboard')) {
                wp_die('Nonce inválido');
            }
            
            $activator = PluginActivator::getInstance();
            $status = $activator->getSystemStatus();
            
            wp_send_json_success($status);
            
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'Error al obtener estado: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Endpoint AJAX para renovar nonce
     */
    public static function renewNonce(): void
    {
        try {
            // Verificar que el usuario tenga permisos
            if (!current_user_can('manage_options')) {
                wp_send_json_error([
                    'message' => 'Permisos insuficientes para renovar nonce',
                    'code' => 'insufficient_permissions'
                ], 403);
                return;
            }
            
            // Verificar que WordPress esté completamente cargado
            if (!did_action('wp_loaded')) {
                wp_send_json_error([
                    'message' => 'WordPress no está completamente cargado',
                    'code' => 'wp_not_loaded'
                ], 500);
                return;
            }
            
            // Generar nuevo nonce
            $fresh_nonce = wp_create_nonce('mi_integracion_api_nonce_dashboard');
            
            if (!$fresh_nonce) {
                wp_send_json_error([
                    'message' => 'No se pudo generar el nonce',
                    'code' => 'nonce_generation_failed'
                ], 500);
                return;
            }
            
            wp_send_json_success([
                'nonce' => $fresh_nonce,
                'timestamp' => time(),
                'message' => 'Nonce renovado correctamente'
            ]);
            
        } catch (\Exception $e) {
            // Log del error para debugging
            if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
                $logger = new \MiIntegracionApi\Logging\Core\Logger('nonce_renewal');
                $logger->error('Error al renovar nonce: ' . $e->getMessage(), [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            
            wp_send_json_error([
                'message' => 'Error interno al renovar nonce: ' . $e->getMessage(),
                'code' => 'internal_error'
            ], 500);
        }
    }
}
