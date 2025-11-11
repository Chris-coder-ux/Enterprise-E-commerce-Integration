<?php
/**
 * Controlador AJAX para gestión de métricas de referencia de rendimiento
 *
 * Este controlador maneja todas las peticiones AJAX relacionadas con la gestión
 * de métricas de referencia de rendimiento (baselines).
 *
 * Características principales:
 * - Reset de métricas de referencia
 * - Recarga de baselines desde persistencia
 * - Gestión de configuración de baselines
 *
 * @package MiIntegracionApi\Admin\Ajax
 * @since 1.0.0
 */

namespace MiIntegracionApi\Admin\Ajax;

use MiIntegracionApi\ErrorHandling\Adapters\AdapterBenchmark;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

/**
 * Controla las peticiones AJAX para gestión de métricas de referencia de rendimiento
 *
 * Esta clase proporciona endpoints AJAX seguros para:
 * - Resetear métricas de referencia de rendimiento
 * - Recargar baselines desde persistencia
 * - Obtener estado actual de baselines
 */
class PerformanceBaselineAjax
{
    /**
     * Constructor
     */
    public function __construct()
    {
        add_action('wp_ajax_mia_reset_performance_baselines', [self::class, 'ajax_reset_baselines']);
        add_action('wp_ajax_mia_reload_performance_baselines', [self::class, 'ajax_reload_baselines']);
        add_action('wp_ajax_mia_get_performance_baselines_status', [self::class, 'ajax_get_baselines_status']);
    }

    /**
     * Resetea las métricas de referencia de rendimiento
     *
     * @return void
     */
    public static function ajax_reset_baselines(): void
    {
        check_ajax_referer('mia_performance_baselines', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta acción');
        }

        try {
            $benchmark = new AdapterBenchmark();
            $success = $benchmark->resetPerformanceBaselines();

            if ($success) {
                wp_send_json_success([
                    'message' => 'Métricas de referencia reseteadas correctamente',
                    'timestamp' => current_time('mysql')
                ]);
            } else {
                wp_send_json_error('Error al resetear las métricas de referencia');
            }
        } catch (\Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Recarga las métricas de referencia desde persistencia
     *
     * @return void
     */
    public static function ajax_reload_baselines(): void
    {
        check_ajax_referer('mia_performance_baselines', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta acción');
        }

        try {
            $benchmark = new AdapterBenchmark();
            $benchmark->reloadPerformanceBaselines();

            wp_send_json_success([
                'message' => 'Métricas de referencia recargadas correctamente',
                'timestamp' => current_time('mysql')
            ]);
        } catch (\Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Obtiene el estado actual de las métricas de referencia
     *
     * @return void
     */
    public static function ajax_get_baselines_status(): void
    {
        check_ajax_referer('mia_performance_baselines', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta acción');
        }

        try {
            $benchmark = new AdapterBenchmark();
            $baselines = $benchmark->getPerformanceBaselines();
            $hasPersistentBaselines = !empty(get_option('mia_performance_baselines', []));

            wp_send_json_success([
                'has_persistent_baselines' => $hasPersistentBaselines,
                'baselines_count' => count($baselines),
                'baselines' => $baselines,
                'timestamp' => current_time('mysql')
            ]);
        } catch (\Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
}

// Inicializar el controlador AJAX
new PerformanceBaselineAjax();
