<?php

declare(strict_types=1);

/**
 * Gestor del dashboard de monitoreo de memoria
 * 
 * @package MiIntegracionApi
 * @subpackage Admin
 */

namespace MiIntegracionApi\Admin;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

use MiIntegracionApi\Core\MemoryManager;
use MiIntegracionApi\Helpers\Logger;

/**
 * Clase para gestionar el dashboard de monitoreo de memoria
 */
class MemoryMonitoringManager
{
    private const OPTION_GROUP = 'mia_memory_options';
    private const OPTION_PAGE = 'mia_memory_options';
    
    private Logger $logger;
    private MemoryManager $memory_manager;

    public function __construct()
    {
        $this->logger = new Logger('memory-monitoring');
        $this->memory_manager = MemoryManager::getInstance();
        
        $this->init();
    }

    /**
     * Inicializa la clase
     */
    public function init(): void
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_mia_refresh_memory_stats', [$this, 'ajax_refresh_memory_stats']);
        add_action('wp_ajax_mia_cleanup_memory', [$this, 'ajax_cleanup_memory']);
        add_action('wp_ajax_mia_reset_memory_history', [$this, 'ajax_reset_memory_history']);
        
        // Cron job para limpieza automática de memoria
        add_action('mia_auto_memory_cleanup', [$this, 'auto_cleanup_memory']);
        
        // CORRECCIÓN: Los cron jobs se manejan centralmente en RobustnessHooks
        // para evitar múltiples cargas del plugin
        // La programación se hace automáticamente desde RobustnessHooks
    }

    /**
     * Registra las opciones de configuración
     */
    public function register_settings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            'mia_memory_monitoring_enabled',
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_boolean']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_memory_warning_threshold',
            [
                'type' => 'number',
                'default' => 0.7,
                'sanitize_callback' => [$this, 'sanitize_threshold']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_memory_critical_threshold',
            [
                'type' => 'number',
                'default' => 0.9,
                'sanitize_callback' => [$this, 'sanitize_threshold']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_memory_cleanup_threshold',
            [
                'type' => 'number',
                'default' => 0.75,
                'sanitize_callback' => [$this, 'sanitize_threshold']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_memory_history_max_records',
            [
                'type' => 'integer',
                'default' => 100,
                'sanitize_callback' => [$this, 'sanitize_max_records']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_memory_alerts_max_records',
            [
                'type' => 'integer',
                'default' => 50,
                'sanitize_callback' => [$this, 'sanitize_max_records']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_memory_auto_cleanup_enabled',
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_boolean']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_memory_auto_cleanup_interval',
            [
                'type' => 'integer',
                'default' => 300,
                'sanitize_callback' => [$this, 'sanitize_cleanup_interval']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_memory_notifications_enabled',
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_boolean']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_memory_dashboard_refresh_interval',
            [
                'type' => 'integer',
                'default' => 30,
                'sanitize_callback' => [$this, 'sanitize_refresh_interval']
            ]
        );

        // Registrar intervalo personalizado para cron
        add_filter('cron_schedules', [$this, 'add_cron_interval']);
    }

    /**
     * Añade intervalo personalizado para cron
     */
    public function add_cron_interval($schedules): array
    {
        $interval = get_option('mia_memory_auto_cleanup_interval', 300);
        $schedules['mia_memory_cleanup_interval'] = [
            'interval' => $interval,
            'display' => sprintf(__('Cada %d segundos', 'mi-integracion-api'), $interval)
        ];
        return $schedules;
    }

    /**
     * Renderiza la página de configuración
     */
    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para acceder a esta página.', 'mi-integracion-api'));
        }

        include_once plugin_dir_path(__FILE__) . '../../templates/admin/memory-monitoring.php';
    }

    /**
     * AJAX: Refrescar estadísticas de memoria
     */
    public function ajax_refresh_memory_stats(): void
    {
        check_ajax_referer('mia_memory_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permisos insuficientes.', 'mi-integracion-api'));
        }

        try {
            $stats = $this->memory_manager->getAdvancedMemoryStats();
            wp_send_json_success($stats);
        } catch (\Exception $e) {
            $this->logger->error('Error al refrescar estadísticas de memoria', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * AJAX: Limpiar memoria
     */
    public function ajax_cleanup_memory(): void
    {
        check_ajax_referer('mia_memory_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permisos insuficientes.', 'mi-integracion-api'));
        }

        try {
            $result = $this->memory_manager->performCleanup('manual_cleanup');
            
            $this->logger->info('Limpieza manual de memoria ejecutada', [
                'reduction_mb' => $result['after']['reduction_mb'] ?? 0,
                'before' => $result['before'],
                'after' => $result['after']
            ]);

            wp_send_json_success([
                'reduction_mb' => $result['after']['reduction_mb'] ?? 0,
                'message' => __('Memoria limpiada exitosamente.', 'mi-integracion-api')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error al limpiar memoria', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * AJAX: Resetear historial de memoria
     */
    public function ajax_reset_memory_history(): void
    {
        check_ajax_referer('mia_memory_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permisos insuficientes.', 'mi-integracion-api'));
        }

        try {
            $this->memory_manager->reset();
            
            $this->logger->info('Historial de memoria reseteado manualmente');
            
            wp_send_json_success(__('Historial de memoria reseteado exitosamente.', 'mi-integracion-api'));
        } catch (\Exception $e) {
            $this->logger->error('Error al resetear historial de memoria', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Limpieza automática de memoria (cron job)
     */
    public function auto_cleanup_memory(): void
    {
        if (!get_option('mia_memory_auto_cleanup_enabled', true)) {
            return;
        }

        try {
            $stats = $this->memory_manager->getAdvancedMemoryStats();
            $usage_percentage = $stats['usage_percentage'] / 100;
            $critical_threshold = get_option('mia_memory_critical_threshold', 0.9);

            // Solo limpiar si se alcanza el umbral crítico
            if ($usage_percentage >= $critical_threshold) {
                $result = $this->memory_manager->performCleanup('auto_cleanup');
                
                $this->logger->warning('Limpieza automática de memoria ejecutada', [
                    'usage_percentage' => $stats['usage_percentage'],
                    'critical_threshold' => $critical_threshold * 100,
                    'reduction_mb' => $result['after']['reduction_mb'] ?? 0
                ]);

                // Notificar si está habilitado
                if (get_option('mia_memory_notifications_enabled', true)) {
                    $this->send_memory_alert('auto_cleanup', $stats, $result);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error en limpieza automática de memoria', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Envía alerta de memoria
     */
    private function send_memory_alert(string $type, array $stats, array $cleanup_result = []): void
    {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $subject = sprintf('[%s] Alerta de Memoria - %s', $site_name, ucfirst($type));
        
        $message = sprintf(
            "Se ha ejecutado una acción de memoria en %s:\n\n" .
            "Tipo: %s\n" .
            "Uso actual: %.2f%% (%s MB)\n" .
            "Estado: %s\n" .
            "Timestamp: %s\n\n",
            $site_name,
            ucfirst($type),
            $stats['usage_percentage'],
            $stats['current'],
            ucfirst($stats['status']),
            current_time('mysql')
        );

        if (!empty($cleanup_result)) {
            $message .= sprintf(
                "Limpieza ejecutada:\n" .
                "Reducción: %.2f MB\n" .
                "Antes: %.2f MB\n" .
                "Después: %.2f MB\n",
                $cleanup_result['after']['reduction_mb'] ?? 0,
                $cleanup_result['before']['current'],
                $cleanup_result['after']['current']
            );
        }

        $message .= "\nRevisa el dashboard de monitoreo de memoria para más detalles.";

        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Obtiene la configuración de memoria
     */
    public function get_memory_config(): array
    {
        return [
            'monitoring_enabled' => get_option('mia_memory_monitoring_enabled', true),
            'warning_threshold' => get_option('mia_memory_warning_threshold', 0.7),
            'critical_threshold' => get_option('mia_memory_critical_threshold', 0.9),
            'cleanup_threshold' => get_option('mia_memory_cleanup_threshold', 0.75),
            'history_max_records' => get_option('mia_memory_history_max_records', 100),
            'alerts_max_records' => get_option('mia_memory_alerts_max_records', 50),
            'auto_cleanup_enabled' => get_option('mia_memory_auto_cleanup_enabled', true),
            'auto_cleanup_interval' => get_option('mia_memory_auto_cleanup_interval', 300),
            'notifications_enabled' => get_option('mia_memory_notifications_enabled', true),
            'dashboard_refresh_interval' => get_option('mia_memory_dashboard_refresh_interval', 30)
        ];
    }

    /**
     * Valida la configuración de memoria
     */
    public function validate_config(): array
    {
        $config = $this->get_memory_config();
        $errors = [];

        if ($config['warning_threshold'] >= $config['critical_threshold']) {
            $errors[] = __('El umbral de advertencia debe ser menor que el umbral crítico.', 'mi-integracion-api');
        }

        if ($config['cleanup_threshold'] >= $config['critical_threshold']) {
            $errors[] = __('El umbral de limpieza debe ser menor que el umbral crítico.', 'mi-integracion-api');
        }

        if ($config['warning_threshold'] <= 0 || $config['warning_threshold'] >= 1) {
            $errors[] = __('El umbral de advertencia debe estar entre 0 y 1.', 'mi-integracion-api');
        }

        if ($config['critical_threshold'] <= 0 || $config['critical_threshold'] >= 1) {
            $errors[] = __('El umbral crítico debe estar entre 0 y 1.', 'mi-integracion-api');
        }

        if ($config['cleanup_threshold'] <= 0 || $config['cleanup_threshold'] >= 1) {
            $errors[] = __('El umbral de limpieza debe estar entre 0 y 1.', 'mi-integracion-api');
        }

        return $errors;
    }

    /**
     * Resetea la configuración a los valores por defecto
     */
    public function reset_to_defaults(): void
    {
        $defaults = [
            'mia_memory_monitoring_enabled' => true,
            'mia_memory_warning_threshold' => 0.7,
            'mia_memory_critical_threshold' => 0.9,
            'mia_memory_cleanup_threshold' => 0.75,
            'mia_memory_history_max_records' => 100,
            'mia_memory_alerts_max_records' => 50,
            'mia_memory_auto_cleanup_enabled' => true,
            'mia_memory_auto_cleanup_interval' => 300,
            'mia_memory_notifications_enabled' => true,
            'mia_memory_dashboard_refresh_interval' => 30
        ];

        foreach ($defaults as $option => $value) {
            update_option($option, $value);
        }

        $this->logger->info('Configuración de memoria reseteada a valores por defecto');
    }

    // MÉTODOS DE SANITIZACIÓN

    public function sanitize_boolean($value): bool
    {
        return (bool) $value;
    }

    public function sanitize_threshold($value): float
    {
        $value = (float) $value;
        return max(0.1, min(0.98, $value)); // Entre 10% y 98%
    }

    public function sanitize_max_records($value): int
    {
        $value = (int) $value;
        return max(10, min(1000, $value)); // Entre 10 y 1000
    }

    public function sanitize_cleanup_interval($value): int
    {
        $value = (int) $value;
        return max(60, min(3600, $value)); // Entre 1 minuto y 1 hora
    }

    public function sanitize_refresh_interval($value): int
    {
        $value = (int) $value;
        return max(10, min(300, $value)); // Entre 10 segundos y 5 minutos
    }
}
