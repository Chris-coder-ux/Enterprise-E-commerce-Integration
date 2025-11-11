<?php

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

use MiIntegracionApi\Helpers\Logger;

/**
 * Gestor de configuración del sistema de reintentos inteligente
 * 
 * @package MiIntegracionApi\Admin
 * @since 1.5.0
 */
class RetrySettingsManager
{
    private const OPTION_GROUP = 'mia_retry_settings';
    private const OPTION_PAGE = 'mia_retry_settings';
    
    private Logger $logger;

    public function __construct()
    {
        $this->logger = new Logger('retry-settings');
    }

    /**
     * Inicializa el gestor de configuración
     */
    public function init(): void
    {
        add_action('admin_init', [$this, 'register_settings']);
        // El menú se maneja desde AdminMenu.php para mantener consistencia
    }

    /**
     * Registra las opciones de configuración
     */
    public function register_settings(): void
    {
        // Grupo de opciones
        register_setting(
            self::OPTION_GROUP,
            'mia_retry_system_enabled',
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_boolean']
            ]
        );

        // Configuración general
        register_setting(
            self::OPTION_GROUP,
            'mia_retry_default_max_attempts',
            [
                'type' => 'integer',
                'default' => 3,
                'sanitize_callback' => [$this, 'sanitize_max_attempts']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_retry_default_base_delay',
            [
                'type' => 'number',
                'default' => 2.0,
                'sanitize_callback' => [$this, 'sanitize_base_delay']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_retry_max_delay',
            [
                'type' => 'integer',
                'default' => 30,
                'sanitize_callback' => [$this, 'sanitize_max_delay']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_retry_backoff_factor',
            [
                'type' => 'number',
                'default' => 2.0,
                'sanitize_callback' => [$this, 'sanitize_backoff_factor']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_retry_jitter_enabled',
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [$this, 'sanitize_boolean']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_retry_jitter_max_ms',
            [
                'type' => 'integer',
                'default' => 1000,
                'sanitize_callback' => [$this, 'sanitize_jitter_max_ms']
            ]
        );

        // Políticas por tipo de error
        register_setting(
            self::OPTION_GROUP,
            'mia_retry_policy_network',
            [
                'type' => 'string',
                'default' => 'aggressive',
                'sanitize_callback' => [$this, 'sanitize_policy_level']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_retry_policy_server',
            [
                'type' => 'string',
                'default' => 'moderate',
                'sanitize_callback' => [$this, 'sanitize_policy_level']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_retry_policy_client',
            [
                'type' => 'string',
                'default' => 'conservative',
                'sanitize_callback' => [$this, 'sanitize_policy_level']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_retry_policy_validation',
            [
                'type' => 'string',
                'default' => 'none',
                'sanitize_callback' => [$this, 'sanitize_policy_level']
            ]
        );

        // Configuración por tipo de operación
        register_setting(
            self::OPTION_GROUP,
            'mia_retry_sync_products_max_attempts',
            [
                'type' => 'integer',
                'default' => 3,
                'sanitize_callback' => [$this, 'sanitize_operation_max_attempts']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_retry_sync_orders_max_attempts',
            [
                'type' => 'integer',
                'default' => 4,
                'sanitize_callback' => [$this, 'sanitize_operation_max_attempts']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_retry_sync_customers_max_attempts',
            [
                'type' => 'integer',
                'default' => 3,
                'sanitize_callback' => [$this, 'sanitize_operation_max_attempts']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_retry_api_calls_max_attempts',
            [
                'type' => 'integer',
                'default' => 5,
                'sanitize_callback' => [$this, 'sanitize_operation_max_attempts']
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'mia_retry_ssl_operations_max_attempts',
            [
                'type' => 'integer',
                'default' => 3,
                'sanitize_callback' => [$this, 'sanitize_operation_max_attempts']
            ]
        );

        // $this->logger->info('Opciones de configuración del sistema de reintentos registradas');
    }

    /**
     * Renderiza la página de configuración
     */
    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para acceder a esta página.', 'mi-integracion-api'));
        }

        // Incluir el template
        include_once plugin_dir_path(__FILE__) . '../../templates/admin/retry-settings.php';
    }

    /**
     * Sanitiza valores booleanos
     */
    public function sanitize_boolean($value): bool
    {
        return (bool) $value;
    }

    /**
     * Sanitiza el número máximo de reintentos
     */
    public function sanitize_max_attempts($value): int
    {
        $value = (int) $value;
        return max(0, min(10, $value));
    }

    /**
     * Sanitiza el retraso base
     */
    public function sanitize_base_delay($value): float
    {
        $value = (float) $value;
        return max(0.5, min(60.0, $value));
    }

    /**
     * Sanitiza el retraso máximo
     */
    public function sanitize_max_delay($value): int
    {
        $value = (int) $value;
        return max(5, min(300, $value));
    }

    /**
     * Sanitiza el factor de backoff
     */
    public function sanitize_backoff_factor($value): float
    {
        $value = (float) $value;
        return max(1.0, min(5.0, $value));
    }

    /**
     * Sanitiza el jitter máximo en milisegundos
     */
    public function sanitize_jitter_max_ms($value): int
    {
        $value = (int) $value;
        return max(0, min(5000, $value));
    }

    /**
     * Sanitiza el nivel de política
     */
    public function sanitize_policy_level($value): string
    {
        $allowed_levels = ['aggressive', 'moderate', 'conservative', 'none'];
        return in_array($value, $allowed_levels) ? $value : 'moderate';
    }

    /**
     * Sanitiza el máximo de reintentos por operación
     */
    public function sanitize_operation_max_attempts($value): int
    {
        $value = (int) $value;
        return max(0, min(10, $value));
    }

    /**
     * Obtiene la configuración completa del sistema de reintentos
     */
    public function get_retry_config(): array
    {
        return [
            'system_enabled' => get_option('mia_retry_system_enabled', true),
            'default_max_attempts' => get_option('mia_retry_default_max_attempts', 3),
            'default_base_delay' => get_option('mia_retry_default_base_delay', 2.0),
            'max_delay' => get_option('mia_retry_max_delay', 30),
            'backoff_factor' => get_option('mia_retry_backoff_factor', 2.0),
            'jitter_enabled' => get_option('mia_retry_jitter_enabled', true),
            'jitter_max_ms' => get_option('mia_retry_jitter_max_ms', 1000),
            
            'policies' => [
                'network' => get_option('mia_retry_policy_network', 'aggressive'),
                'server' => get_option('mia_retry_policy_server', 'moderate'),
                'client' => get_option('mia_retry_policy_client', 'conservative'),
                'validation' => get_option('mia_retry_policy_validation', 'none')
            ],
            
            'operations' => [
                'sync_products' => get_option('mia_retry_sync_products_max_attempts', 3),
                'sync_orders' => get_option('mia_retry_sync_orders_max_attempts', 4),
                'sync_customers' => get_option('mia_retry_sync_customers_max_attempts', 3),
                'api_calls' => get_option('mia_retry_api_calls_max_attempts', 5),
                'ssl_operations' => get_option('mia_retry_ssl_operations_max_attempts', 3)
            ]
        ];
    }

    /**
     * Valida la configuración completa
     */
    public function validate_config(): array
    {
        $config = $this->get_retry_config();
        $errors = [];
        $warnings = [];

        // Validaciones críticas
        if ($config['default_max_attempts'] > $config['max_delay']) {
            $errors[] = 'El máximo de reintentos no puede ser mayor que el retraso máximo';
        }

        if ($config['default_base_delay'] > $config['max_delay']) {
            $errors[] = 'El retraso base no puede ser mayor que el retraso máximo';
        }

        // Validaciones de políticas
        if ($config['policies']['validation'] !== 'none') {
            $warnings[] = 'Se recomienda no reintentar errores de validación';
        }

        if ($config['policies']['network'] === 'none') {
            $warnings[] = 'Deshabilitar reintentos para errores de red puede causar fallos en operaciones críticas';
        }

        // Validaciones de operaciones
        if ($config['operations']['sync_orders'] < $config['operations']['sync_products']) {
            $warnings[] = 'Los pedidos son más críticos que los productos, considera aumentar sus reintentos';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'config' => $config
        ];
    }

    /**
     * Resetea la configuración a los valores por defecto
     */
    public function reset_to_defaults(): bool
    {
        $default_options = [
            'mia_retry_system_enabled' => true,
            'mia_retry_default_max_attempts' => 3,
            'mia_retry_default_base_delay' => 2.0,
            'mia_retry_max_delay' => 30,
            'mia_retry_backoff_factor' => 2.0,
            'mia_retry_jitter_enabled' => true,
            'mia_retry_jitter_max_ms' => 1000,
            'mia_retry_policy_network' => 'aggressive',
            'mia_retry_policy_server' => 'moderate',
            'mia_retry_policy_client' => 'conservative',
            'mia_retry_policy_validation' => 'none',
            'mia_retry_sync_products_max_attempts' => 3,
            'mia_retry_sync_orders_max_attempts' => 4,
            'mia_retry_sync_customers_max_attempts' => 3,
            'mia_retry_api_calls_max_attempts' => 5,
            'mia_retry_ssl_operations_max_attempts' => 3
        ];

        foreach ($default_options as $option_name => $default_value) {
            update_option($option_name, $default_value);
        }

        $this->logger->info('Configuración del sistema de reintentos reseteada a valores por defecto');
        return true;
    }
}
