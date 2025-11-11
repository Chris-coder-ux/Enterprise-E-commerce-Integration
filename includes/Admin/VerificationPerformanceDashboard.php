<?php

declare(strict_types=1);

/**
 * Dashboard de rendimiento de verificaciones
 * 
 * Este dashboard proporciona una interfaz para monitorear el rendimiento
 * de las verificaciones del sistema y identificar cuellos de botella.
 * 
 * @package MiIntegracionApi\Admin
 * @since 2.4.0
 */

namespace MiIntegracionApi\Admin;

use MiIntegracionApi\Helpers\VerificationPerformanceTracker;
use MiIntegracionApi\Helpers\WooCommerceHelper;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

class VerificationPerformanceDashboard {
    
    /**
     * Inicializa el dashboard
     * 
     * @return void
     */
    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_admin_menu']);
        add_action('wp_ajax_mia_get_verification_metrics', [self::class, 'ajax_get_metrics']);
        add_action('wp_ajax_mia_reset_verification_metrics', [self::class, 'ajax_reset_metrics']);
    }
    
    /**
     * Añade el menú del dashboard al admin de WordPress
     * 
     * @return void
     */
    public static function add_admin_menu(): void {
        add_submenu_page(
            'mi-integracion-api',
            'Rendimiento de Verificaciones',
            'Rendimiento',
            'manage_options',
            'mia-verification-performance',
            [self::class, 'render_dashboard']
        );
    }
    
    /**
     * Renderiza el dashboard de rendimiento
     * 
     * @return void
     */
    public static function render_dashboard(): void {
        $metrics = VerificationPerformanceTracker::getAllMetrics();
        $realtime_metrics = VerificationPerformanceTracker::getRealTimeMetrics();
        $cache_stats = WooCommerceHelper::getCacheStats();
        
        ?>
        <div class="wrap">
            <h1>Rendimiento de Verificaciones</h1>
            
            <div class="mia-performance-dashboard">
                <!-- Métricas en tiempo real -->
                <div class="mia-metrics-section">
                    <h2>Métricas en Tiempo Real</h2>
                    <div class="mia-metrics-grid">
                        <div class="mia-metric-card">
                            <h3>Verificaciones Totales</h3>
                            <div class="mia-metric-value"><?php echo esc_html($realtime_metrics['total_verifications']); ?></div>
                        </div>
                        <div class="mia-metric-card">
                            <h3>Tasa de Éxito</h3>
                            <div class="mia-metric-value"><?php echo esc_html(round($realtime_metrics['success_rate'], 1)); ?>%</div>
                        </div>
                        <div class="mia-metric-card">
                            <h3>Duración Promedio</h3>
                            <div class="mia-metric-value"><?php echo esc_html($realtime_metrics['avg_duration_ms']); ?>ms</div>
                        </div>
                        <div class="mia-metric-card">
                            <h3>Memoria Promedio</h3>
                            <div class="mia-metric-value"><?php echo esc_html($realtime_metrics['avg_memory_kb']); ?>KB</div>
                        </div>
                    </div>
                </div>
                
                <!-- Estadísticas detalladas -->
                <div class="mia-metrics-section">
                    <h2>Estadísticas Detalladas</h2>
                    <div class="mia-metrics-grid">
                        <div class="mia-metric-card">
                            <h3>Verificaciones Exitosas</h3>
                            <div class="mia-metric-value"><?php echo esc_html($realtime_metrics['successful_verifications']); ?></div>
                        </div>
                        <div class="mia-metric-card">
                            <h3>Verificaciones Fallidas</h3>
                            <div class="mia-metric-value"><?php echo esc_html($realtime_metrics['failed_verifications']); ?></div>
                        </div>
                        <div class="mia-metric-card">
                            <h3>Verificaciones por Segundo</h3>
                            <div class="mia-metric-value"><?php echo esc_html($realtime_metrics['verifications_per_second']); ?></div>
                        </div>
                        <div class="mia-metric-card">
                            <h3>Duración de Sesión</h3>
                            <div class="mia-metric-value"><?php echo esc_html(round($realtime_metrics['session_duration'] / 60, 1)); ?>min</div>
                        </div>
                    </div>
                </div>
                
                <!-- Estadísticas de rendimiento -->
                <div class="mia-metrics-section">
                    <h2>Rendimiento</h2>
                    <div class="mia-metrics-grid">
                        <div class="mia-metric-card">
                            <h3>Duración Mínima</h3>
                            <div class="mia-metric-value"><?php echo esc_html($metrics['min_duration_ms']); ?>ms</div>
                        </div>
                        <div class="mia-metric-card">
                            <h3>Duración Máxima</h3>
                            <div class="mia-metric-value"><?php echo esc_html($metrics['max_duration_ms']); ?>ms</div>
                        </div>
                        <div class="mia-metric-card">
                            <h3>Memoria Mínima</h3>
                            <div class="mia-metric-value"><?php echo esc_html($metrics['min_memory_kb']); ?>KB</div>
                        </div>
                        <div class="mia-metric-card">
                            <h3>Memoria Máxima</h3>
                            <div class="mia-metric-value"><?php echo esc_html($metrics['max_memory_kb']); ?>KB</div>
                        </div>
                    </div>
                </div>
                
                <!-- Estadísticas de cache -->
                <div class="mia-metrics-section">
                    <h2>Cache de WooCommerce</h2>
                    <div class="mia-metrics-grid">
                        <div class="mia-metric-card">
                            <h3>Funciones en Cache</h3>
                            <div class="mia-metric-value"><?php echo esc_html($cache_stats['function_cache_size']); ?></div>
                        </div>
                        <div class="mia-metric-card">
                            <h3>Clases en Cache</h3>
                            <div class="mia-metric-value"><?php echo esc_html($cache_stats['class_cache_size']); ?></div>
                        </div>
                        <div class="mia-metric-card">
                            <h3>Hooks en Cache</h3>
                            <div class="mia-metric-value"><?php echo esc_html($cache_stats['hook_cache_size']); ?></div>
                        </div>
                        <div class="mia-metric-card">
                            <h3>Edad del Cache</h3>
                            <div class="mia-metric-value"><?php echo esc_html(round($cache_stats['cache_age'] / 60, 1)); ?>min</div>
                        </div>
                    </div>
                </div>
                
                <!-- Acciones -->
                <div class="mia-metrics-section">
                    <h2>Acciones</h2>
                    <div class="mia-actions">
                        <button type="button" class="button button-primary" id="refresh-metrics">
                            Actualizar Métricas
                        </button>
                        <button type="button" class="button button-secondary" id="reset-metrics">
                            Resetear Métricas
                        </button>
                        <button type="button" class="button button-secondary" id="export-metrics">
                            Exportar Métricas
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .mia-performance-dashboard {
            max-width: 1200px;
        }
        
        .mia-metrics-section {
            margin-bottom: 30px;
            padding: 20px;
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
        }
        
        .mia-metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .mia-metric-card {
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            text-align: center;
        }
        
        .mia-metric-card h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #666;
        }
        
        .mia-metric-value {
            font-size: 24px;
            font-weight: bold;
            color: #0073aa;
        }
        
        .mia-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#refresh-metrics').on('click', function() {
                location.reload();
            });
            
            $('#reset-metrics').on('click', function() {
                if (confirm('¿Estás seguro de que deseas resetear todas las métricas?')) {
                    $.post(ajaxurl, {
                        action: 'mia_reset_verification_metrics',
                        nonce: '<?php echo wp_create_nonce('mia_verification_metrics'); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error al resetear las métricas: ' + response.data);
                        }
                    });
                }
            });
            
            $('#export-metrics').on('click', function() {
                $.post(ajaxurl, {
                    action: 'mia_get_verification_metrics',
                    nonce: '<?php echo wp_create_nonce('mia_verification_metrics'); ?>'
                }, function(response) {
                    if (response.success) {
                        const data = JSON.stringify(response.data, null, 2);
                        const blob = new Blob([data], { type: 'application/json' });
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = 'verification-metrics-' + new Date().toISOString().slice(0, 19) + '.json';
                        a.click();
                        URL.revokeObjectURL(url);
                    } else {
                        alert('Error al exportar las métricas: ' + response.data);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX handler para obtener métricas
     * 
     * @return void
     */
    public static function ajax_get_metrics(): void {
        check_ajax_referer('mia_verification_metrics', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para acceder a esta información');
        }
        
        $metrics = VerificationPerformanceTracker::exportMetrics();
        wp_send_json_success($metrics);
    }
    
    /**
     * AJAX handler para resetear métricas
     * 
     * @return void
     */
    public static function ajax_reset_metrics(): void {
        check_ajax_referer('mia_verification_metrics', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta acción');
        }
        
        VerificationPerformanceTracker::reset();
        WooCommerceHelper::clearCache();
        
        wp_send_json_success('Métricas reseteadas correctamente');
    }
}
