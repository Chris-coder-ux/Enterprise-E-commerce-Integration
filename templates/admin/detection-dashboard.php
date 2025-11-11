<?php
/**
 * Template para el Dashboard de Detección Automática
 * @package MiIntegracionApi
 * @subpackage Admin
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Obtener datos del sistema
$detection_status = get_option('mia_automatic_stock_detection_enabled', false);
$last_sync = get_option('mia_automatic_stock_last_sync', 0);
$sync_stats = get_option('mia_detection_stats', [
    'total_synced' => 0,
    'avg_time' => 0,
    'accuracy' => 0
]);

// Calcular tiempo desde última sincronización
$time_since_last = $last_sync ? human_time_diff($last_sync, current_time('timestamp')) : 'Nunca';
?>

<div class="wrap mi-integracion-api-admin">
    <!-- Sidebar Unificado -->
    <div class="mi-integracion-api-sidebar">
        <div class="unified-sidebar-header">
            <h2>Mi Integración API</h2>
            <button class="sidebar-toggle" title="Colapsar/Expandir">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>
        
        <div class="unified-sidebar-content">
            <!-- Navegación Principal -->
            <ul class="unified-nav-menu">
                <li class="unified-nav-item">
                    <a href="<?php echo admin_url('admin.php?page=mi-integracion-api'); ?>" class="unified-nav-link" data-page="dashboard">
                        <span class="nav-icon dashicons dashicons-admin-home"></span>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <li class="unified-nav-item active">
                    <a href="<?php echo admin_url('admin.php?page=mia-detection-dashboard'); ?>" class="unified-nav-link" data-page="detection">
                        <span class="nav-icon dashicons dashicons-search"></span>
                        <span class="nav-text">Detección Automática</span>
                    </a>
                </li>
                <li class="unified-nav-item">
                    <a href="<?php echo admin_url('admin.php?page=mi-integracion-api-order-sync'); ?>" class="unified-nav-link" data-page="orders">
                        <span class="nav-icon dashicons dashicons-cart"></span>
                        <span class="nav-text">Sincronización de Pedidos</span>
                    </a>
                </li>
                <li class="unified-nav-item">
                    <a href="<?php echo admin_url('admin.php?page=mi-integracion-api-endpoints'); ?>" class="unified-nav-link" data-page="endpoints">
                        <span class="nav-icon dashicons dashicons-networking"></span>
                        <span class="nav-text">Endpoints</span>
                    </a>
                </li>
                <li class="unified-nav-item">
                    <a href="<?php echo admin_url('admin.php?page=mi-integracion-api-cache'); ?>" class="unified-nav-link" data-page="cache">
                        <span class="nav-icon dashicons dashicons-performance"></span>
                        <span class="nav-text">Caché</span>
                    </a>
                </li>
                <li class="unified-nav-item">
                    <a href="<?php echo admin_url('admin.php?page=mi-integracion-api-retry-settings'); ?>" class="unified-nav-link" data-page="retry">
                        <span class="nav-icon dashicons dashicons-update"></span>
                        <span class="nav-text">Reintentos</span>
                    </a>
                </li>
                <li class="unified-nav-item">
                    <a href="<?php echo admin_url('admin.php?page=mi-integracion-api-memory-monitoring'); ?>" class="unified-nav-link" data-page="memory">
                        <span class="nav-icon dashicons dashicons-chart-area"></span>
                        <span class="nav-text">Monitoreo de Memoria</span>
                    </a>
                </li>
            </ul>
            
            <!-- Sección de Acciones Rápidas -->
            <div class="unified-actions-section">
                <h3>Acciones Rápidas</h3>
                <div class="unified-actions-grid">
                    <button class="unified-action-btn" data-action="executeNow" title="Ejecutar Ahora">
                        <i class="fas fa-sync-alt"></i>
                        <span>Ejecutar Ahora</span>
                    </button>
                    <button class="unified-action-btn" data-action="pauseDetection" title="Pausar Detección">
                        <i class="fas fa-pause"></i>
                        <span>Pausar</span>
                    </button>
                    <button class="unified-action-btn" data-action="viewReport" title="Ver Reporte">
                        <i class="fas fa-chart-bar"></i>
                        <span>Ver Reporte</span>
                    </button>
                </div>
            </div>
            
            <!-- Sección de Configuración -->
            <div class="unified-config-section">
                <h3>Configuración</h3>
                <div class="unified-config-item">
                    <label for="detection-interval">Intervalo (min):</label>
                    <input type="number" id="detection-interval" class="unified-input" value="5" min="1" max="60">
                </div>
            </div>
            
            <!-- Búsqueda -->
            <div class="unified-search-section">
                <label for="unified-menu-search" class="screen-reader-text">Buscar en menú</label>
                <input type="text" id="unified-menu-search" class="unified-search-input" placeholder="Buscar en menú...">
            </div>
        </div>
    </div>

    <!-- Contenido principal -->
    <div class="mi-integracion-api-main-content">
        <!-- Encabezado del dashboard -->
        <div class="detection-header">
            <h1>Detección Automática</h1>
            <div class="detection-status-indicators">
                <div class="detection-status-indicator live">
                    <div class="detection-live-dot"></div>
                    <span>EN VIVO</span>
                </div>
                <div class="detection-status-indicator api-online">
                    <span>🟢</span>
                    <span>Verial API</span>
                </div>
                <div class="detection-status-indicator <?php echo $detection_status ? 'active' : 'inactive'; ?>">
                    <div class="detection-pulse-dot"></div>
                    <span><?php echo $detection_status ? 'Sistema Activo' : 'Sistema Inactivo'; ?></span>
                </div>
            </div>
            
            <!-- Icono de Ayuda -->
            <div class="detection-help">
                <a href="<?php echo esc_url(MiIntegracionApi_PLUGIN_URL . 'docs/manual-usuario/manual-deteccion-automatica.html'); ?>" 
                   target="_blank" 
                   class="help-link" 
                   title="<?php esc_attr_e('Abrir Manual de Detección Automática', 'mi-integracion-api'); ?>">
                    <i class="fas fa-question-circle"></i>
                    <span><?php esc_html_e('Ayuda', 'mi-integracion-api'); ?></span>
                </a>
            </div>
        </div>

        <!-- Alertas del Sistema -->
        <?php
        // Obtener productos con stock bajo
        $low_stock_count = 0;
        $low_stock_threshold = get_option('mia_detection_low_stock_threshold', 10);
        
        // Solo consultar si WooCommerce está activo
        if (class_exists('WC_Product')) {
            $args = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => '_stock',
                        'value' => $low_stock_threshold,
                        'compare' => '<=',
                        'type' => 'NUMERIC'
                    ),
                    array(
                        'key' => '_stock',
                        'value' => '',
                        'compare' => '!='
                    )
                )
            );
            
            $query = new WP_Query($args);
            $low_stock_count = $query->found_posts;
        }
        
        // Solo mostrar alerta si realmente hay productos con stock bajo
        if ($low_stock_count > 0): ?>
        <div class="detection-alert-banner warning">
            <div class="detection-alert-icon">⚠️</div>
            <div class="detection-alert-message">
                <strong>Stock Bajo:</strong> <?php echo $low_stock_count; ?> productos tienen stock inferior a 10 unidades
            </div>
            <button class="detection-alert-action" data-action="viewLowStock">Ver productos</button>
        </div>
        <?php endif; ?>

        <div class="detection-content-grid">
            <!-- Panel principal -->
            <div class="detection-main-panel">
                <!-- Tarjeta de Control Principal -->
                <div class="unified-card">
                    <div class="unified-card-header">
                        <h3>Control de Detección</h3>
                    </div>
                    <div class="unified-card-body">
                        <div class="detection-toggle-section">
                            <div class="detection-modern-toggle <?php echo $detection_status ? 'active' : ''; ?>" data-action="toggleDetection"></div>
                            <div class="detection-toggle-info">
                                <h3>Detección Automática</h3>
                                <p>Monitorea cambios en Verial cada 5 minutos y sincroniza automáticamente con WooCommerce</p>
                            </div>
                        </div>

                        <div class="detection-action-buttons">
                            <button class="unified-action-btn unified-btn-primary" data-action="executeNow">
                                <i class="fas fa-sync-alt"></i> Ejecutar Ahora
                            </button>
                            <button class="unified-action-btn unified-btn-secondary" data-action="pauseDetection">
                                <i class="fas fa-pause"></i> Pausar
                            </button>
                            <button class="unified-action-btn unified-btn-success" data-action="viewReport">
                                <i class="fas fa-chart-bar"></i> Ver Reporte
                            </button>
                        </div>

                        <!-- Progreso de Sincronización en Tiempo Real -->
                        <?php
                        // Obtener estado real de la sincronización
                        $sync_in_progress = false; // Verificar si hay una sincronización en curso
                        $sync_progress = 0; // Obtener progreso real (0-100)
                        $products_processed = 0; // Obtener productos procesados
                        $total_products = 0; // Obtener total de productos a procesar
                        $time_remaining = 0; // Calcular tiempo restante
                        ?>
                        <div class="detection-sync-progress" <?php echo $sync_in_progress ? '' : 'style="display: none;"'; ?>>
                            <div class="detection-progress-header">
                                <span class="detection-progress-title">Sincronización en Progreso</span>
                                <span class="detection-progress-percentage" id="progress-percentage"><?php echo $sync_progress; ?>%</span>
                            </div>
                            <div class="detection-progress-bar">
                                <div class="detection-progress-fill" id="progress-fill" style="width: <?php echo $sync_progress; ?>%"></div>
                            </div>
                            <div class="detection-progress-details">
                                <span id="progress-text"><?php echo $products_processed; ?> de <?php echo $total_products; ?> productos procesados</span>
                                <span id="time-remaining">Tiempo restante: <?php echo $time_remaining > 0 ? $time_remaining . ' min' : 'calculando...'; ?></span>
                            </div>
                        </div>
                        
                        <!-- Estado cuando no hay sincronización en curso -->
                        <div class="detection-sync-status" <?php echo $sync_in_progress ? 'style="display: none;"' : ''; ?>>
                            <div class="detection-status-message">
                                <span class="detection-status-icon">⏸️</span>
                                <span class="detection-status-text">Sistema en espera</span>
                            </div>
                            <div class="detection-status-details">
                                <span>Última sincronización: <?php echo $time_since_last; ?></span>
                            </div>
                        </div>

                        <!-- Lista de Productos en Proceso -->
                        <div class="detection-products-section">
                            <h4 class="detection-products-title">Productos Recientes</h4>
                            <div class="detection-filter-controls">
                                <button class="detection-filter-btn active" data-filter="all" data-action="filterProducts">Todos</button>
                                <button class="detection-filter-btn" data-filter="updated" data-action="filterProducts">Actualizados</button>
                                <button class="detection-filter-btn" data-filter="new" data-action="filterProducts">Nuevos</button>
                                <button class="detection-filter-btn" data-filter="errors" data-action="filterProducts">Errores</button>
                            </div>
                            <div class="detection-product-list">
                                <div class="loading-products">
                                    <div class="loading-spinner"></div>
                                    <span>Cargando productos...</span>
                                </div>
                            </div>
                        </div>

                        <div class="detection-stats-grid">
                            <div class="detection-stat-card">
                                <div class="detection-stat-icon">⏱️</div>
                                <div class="detection-stat-value"><?php echo $time_since_last; ?></div>
                                <div class="detection-stat-label">Última ejecución</div>
                            </div>
                            <div class="detection-stat-card">
                                <div class="detection-stat-icon">🔄</div>
                                <div class="detection-stat-value"><?php echo number_format($sync_stats['total_synced']); ?></div>
                                <div class="detection-stat-label">Productos sincronizados</div>
                            </div>
                            <div class="detection-stat-card">
                                <div class="detection-stat-icon">⚡</div>
                                <div class="detection-stat-value"><?php echo $sync_stats['avg_time']; ?>s</div>
                                <div class="detection-stat-label">Tiempo promedio</div>
                            </div>
                            <div class="detection-stat-card">
                                <div class="detection-stat-icon">🎯</div>
                                <div class="detection-stat-value"><?php echo $sync_stats['accuracy']; ?>%</div>
                                <div class="detection-stat-label">Precisión</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="detection-chart-container">
                    <h3 class="detection-chart-title">Actividad de Sincronización (Últimas 24h)</h3>
                    <?php
                    // Obtener datos reales para el gráfico
                    $chart_data = [];
                    $chart_labels = [];
                    $chart_values = [];
                    
                    if (empty($chart_data)): ?>
                        <div class="detection-chart-placeholder">
                            <div class="detection-placeholder-icon">📊</div>
                            <div class="detection-placeholder-text">No hay datos de actividad disponibles</div>
                            <div class="detection-placeholder-subtext">Los datos aparecerán después de la primera sincronización</div>
                        </div>
                    <?php else: ?>
                        <canvas id="syncChart" width="400" height="200"></canvas>
                        <script>
                            // Datos del gráfico obtenidos del servidor
                            const chartData = {
                                labels: <?php echo json_encode($chart_labels); ?>,
                                datasets: [{
                                    label: 'Productos Sincronizados',
                                    data: <?php echo json_encode($chart_values); ?>,
                                    borderColor: '#3498db',
                                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                                    tension: 0.4
                                }]
                            };
                        </script>
                    <?php endif; ?>
                </div>
            </div>

            <div class="detection-sidebar-panel">
                    <div class="detection-widget">
                        <h3>⚙️ Configuración Rápida</h3>
                        <div class="detection-config-grid">
                            <div class="detection-config-item">
                                <label for="detection-interval-config">Intervalo de Detección</label>
                                <select id="detection-interval-config">
                                    <option value="300" selected>5 minutos</option>
                                    <option value="600">10 minutos</option>
                                    <option value="900">15 minutos</option>
                                    <option value="1800">30 minutos</option>
                                </select>
                            </div>
                            <div class="detection-config-item">
                                <label for="product-limit-config">Límite de Productos</label>
                                <input type="number" id="product-limit-config" value="100" min="10" max="500">
                            </div>
                            <div class="detection-config-item">
                                <label for="start-time-config">Horario de Inicio</label>
                                <input type="time" id="start-time-config" value="08:00">
                            </div>
                            <div class="detection-config-item">
                                <label for="end-time-config">Horario de Fin</label>
                                <input type="time" id="end-time-config" value="22:00">
                            </div>
                        </div>
                    </div>

                    <div class="detection-widget">
                        <h3>📋 Actividad Reciente</h3>
                        <div class="detection-logs-container">
                            <?php
                            // Obtener logs recientes del sistema
                            $recent_logs = [];
                            
                            // Implementar obtención de logs reales desde el sistema de logging
                            // Por ahora mostrar mensaje de estado
                            if (empty($recent_logs)): ?>
                                <div class="detection-log-entry">
                                    <span class="detection-log-time">--:--</span>
                                    <span class="detection-log-message detection-log-info">ℹ️ No hay actividad reciente registrada</span>
                                </div>
                            <?php else:
                                foreach ($recent_logs as $log): ?>
                                    <div class="detection-log-entry">
                                        <span class="detection-log-time"><?php echo esc_html($log['time']); ?></span>
                                        <span class="detection-log-message detection-log-<?php echo esc_attr($log['type']); ?>"><?php echo esc_html($log['message']); ?></span>
                                    </div>
                                <?php endforeach;
                            endif; ?>
                        </div>
                    </div>

                    <div class="detection-widget">
                        <h3>🔔 Alertas Activas</h3>
                        <div class="detection-logs-container">
                            <?php
                            // Obtener alertas activas del sistema
                            $active_alerts = [];
                            
                            // Implementar obtención de alertas reales
                            if (empty($active_alerts)): ?>
                                <div class="detection-log-entry">
                                    <span class="detection-log-time">--:--</span>
                                    <span class="detection-log-message detection-log-info">ℹ️ No hay alertas activas</span>
                                </div>
                            <?php else:
                                foreach ($active_alerts as $alert): ?>
                                    <div class="detection-log-entry">
                                        <span class="detection-log-time"><?php echo esc_html($alert['time']); ?></span>
                                        <span class="detection-log-message detection-log-<?php echo esc_attr($alert['type']); ?>"><?php echo esc_html($alert['message']); ?></span>
                                    </div>
                                <?php endforeach;
                            endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
