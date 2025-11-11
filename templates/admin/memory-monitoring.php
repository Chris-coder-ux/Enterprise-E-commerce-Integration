<?php
/**
 * Template para el dashboard de monitoreo de memoria
 * 
 * @package MiIntegracionApi
 * @subpackage Admin
 */

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

// Obtener estadísticas de memoria
$memory_manager = \MiIntegracionApi\Core\MemoryManager::getInstance();
$memory_stats = $memory_manager->getAdvancedMemoryStats();
$memory_history = $memory_manager->getMemoryHistory();
$memory_alerts = $memory_manager->getAlerts();

// Obtener configuración
$monitoring_enabled = get_option('mia_memory_monitoring_enabled', true);
$warning_threshold = get_option('mia_memory_warning_threshold', 0.7) * 100;
$critical_threshold = get_option('mia_memory_critical_threshold', 0.9) * 100;
$cleanup_threshold = get_option('mia_memory_cleanup_threshold', 0.75) * 100;
$auto_cleanup_enabled = get_option('mia_memory_auto_cleanup_enabled', true);
$notifications_enabled = get_option('mia_memory_notifications_enabled', true);
$refresh_interval = get_option('mia_memory_dashboard_refresh_interval', 30);
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
                <li class="unified-nav-item">
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
                <li class="unified-nav-item active">
                    <a href="<?php echo admin_url('admin.php?page=mi-integracion-api-memory-monitoring'); ?>" class="unified-nav-link" data-page="memory">
                        <span class="nav-icon dashicons dashicons-performance"></span>
                        <span class="nav-text">Memoria</span>
                    </a>
                </li>
            </ul>
            
            <!-- Sección de Acciones Rápidas -->
            <div class="unified-actions-section">
                <h3><?php esc_html_e('Acciones Rápidas', 'mi-integracion-api'); ?></h3>
                <div class="unified-actions-grid">
                    <button class="unified-action-btn" data-action="cleanup-memory" title="<?php esc_attr_e('Limpiar memoria ahora', 'mi-integracion-api'); ?>">
                        <i class="fas fa-trash"></i>
                        <span><?php esc_html_e('Limpiar', 'mi-integracion-api'); ?></span>
                    </button>
                    <button class="unified-action-btn" data-action="refresh-stats" title="<?php esc_attr_e('Refrescar estadísticas', 'mi-integracion-api'); ?>">
                        <i class="fas fa-sync-alt"></i>
                        <span><?php esc_html_e('Refrescar', 'mi-integracion-api'); ?></span>
                    </button>
                    <button class="unified-action-btn" data-action="reset-history" title="<?php esc_attr_e('Resetear historial', 'mi-integracion-api'); ?>">
                        <i class="fas fa-history"></i>
                        <span><?php esc_html_e('Reset', 'mi-integracion-api'); ?></span>
                    </button>
                    <button class="unified-action-btn" data-action="export-stats" title="<?php esc_attr_e('Exportar estadísticas', 'mi-integracion-api'); ?>">
                        <i class="fas fa-download"></i>
                        <span><?php esc_html_e('Exportar', 'mi-integracion-api'); ?></span>
                    </button>
                </div>
            </div>
            
            <!-- Sección de Configuración -->
            <div class="unified-config-section">
                <h3><?php esc_html_e('Configuración', 'mi-integracion-api'); ?></h3>
                <div class="unified-config-grid">
                    <div class="unified-config-item">
                        <span class="config-label"><?php esc_html_e('Monitoreo:', 'mi-integracion-api'); ?></span>
                        <span class="config-value status-<?php echo $monitoring_enabled ? 'enabled' : 'disabled'; ?>">
                            <?php echo $monitoring_enabled ? esc_html__('Activado', 'mi-integracion-api') : esc_html__('Desactivado', 'mi-integracion-api'); ?>
                        </span>
                    </div>
                    <div class="unified-config-item">
                        <span class="config-label"><?php esc_html_e('Umbral Advertencia:', 'mi-integracion-api'); ?></span>
                        <span class="config-value"><?php echo esc_html($warning_threshold); ?>%</span>
                    </div>
                    <div class="unified-config-item">
                        <span class="config-label"><?php esc_html_e('Umbral Crítico:', 'mi-integracion-api'); ?></span>
                        <span class="config-value"><?php echo esc_html($critical_threshold); ?>%</span>
                    </div>
                    <div class="unified-config-item">
                        <span class="config-label"><?php esc_html_e('Limpieza Auto:', 'mi-integracion-api'); ?></span>
                        <span class="config-value status-<?php echo $auto_cleanup_enabled ? 'enabled' : 'disabled'; ?>">
                            <?php echo $auto_cleanup_enabled ? esc_html__('Activado', 'mi-integracion-api') : esc_html__('Desactivado', 'mi-integracion-api'); ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Búsqueda -->
            <div class="unified-search-section">
                <h3><?php esc_html_e('Búsqueda', 'mi-integracion-api'); ?></h3>
                <div class="unified-search-form">
                    <input type="text" placeholder="<?php esc_attr_e('Buscar en memoria...', 'mi-integracion-api'); ?>" class="unified-search-input">
                    <button type="button" class="unified-search-button">
                        <span class="dashicons dashicons-search"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Contenido principal -->
    <div class="mi-integracion-api-main-content">
        <!-- Banner principal -->
        <div class="mi-integracion-api-banner">
            <div class="banner-content">
                <div class="banner-icon">
                    <span class="dashicons dashicons-performance"></span>
                </div>
                <div class="banner-text">
                    <h1><?php echo esc_html__('Monitoreo de Memoria', 'mi-integracion-api'); ?></h1>
                    <p><?php echo esc_html__('Supervisa y optimiza el uso de memoria del sistema', 'mi-integracion-api'); ?></p>
                </div>
                <div class="banner-visual">
                    <div class="visual-animation">
                        <div class="memory-icon">
                            <span class="dashicons dashicons-performance"></span>
                        </div>
                        <div class="memory-indicators">
                            <div class="indicator usage"></div>
                            <div class="indicator peak"></div>
                            <div class="indicator available"></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Icono de Ayuda -->
            <div class="memory-monitoring-help">
                <a href="<?php echo esc_url(MiIntegracionApi_PLUGIN_URL . 'docs/manual-usuario/manual-monitoreo-memoria.html'); ?>"
                target="_blank" 
                class="help-link"
                title="<?php esc_attr_e('Abrir Manual de Monitoreo de Memoria', 'mi-integracion-api'); ?>">
                    <i class="fas fa-question-circle"></i>
                    <span><?php esc_html_e('Ayuda', 'mi-integracion-api'); ?></span>
                </a>
            </div>
        </div>
    
        <!-- Estado actual de memoria -->
        <div class="mi-integracion-api-card">
            <div class="card-header">
                <h2><?php _e('Estado Actual de Memoria', 'mi-integracion-api'); ?></h2>
            </div>
            <div class="card-content">
                <div class="memory-status-grid">
                    <div class="memory-status-card <?php echo esc_attr($memory_stats['status']); ?>">
                        <div class="status-icon">
                            <?php if ($memory_stats['status'] === 'critical'): ?>
                                <span class="dashicons dashicons-warning"></span>
                            <?php elseif ($memory_stats['status'] === 'warning'): ?>
                                <span class="dashicons dashicons-warning"></span>
                            <?php elseif ($memory_stats['status'] === 'attention'): ?>
                                <span class="dashicons dashicons-info"></span>
                            <?php else: ?>
                                <span class="dashicons dashicons-yes-alt"></span>
                            <?php endif; ?>
                        </div>
                        <div class="status-info">
                            <h3><?php echo esc_html(ucfirst($memory_stats['status'])); ?></h3>
                            <p class="status-description">
                                <?php 
                                switch ($memory_stats['status']) {
                                    case 'critical':
                                        _e('Uso de memoria crítico - Acción inmediata requerida', 'mi-integracion-api');
                                        break;
                                    case 'warning':
                                        _e('Uso de memoria alto - Monitorear de cerca', 'mi-integracion-api');
                                        break;
                                    case 'attention':
                                        _e('Uso de memoria moderado - Considerar limpieza', 'mi-integracion-api');
                                        break;
                                    default:
                                        _e('Uso de memoria saludable', 'mi-integracion-api');
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="memory-usage-card">
                        <h3><?php _e('Uso de Memoria', 'mi-integracion-api'); ?></h3>
                        <div class="usage-bar">
                            <div class="usage-fill" style="width: <?php echo esc_attr($memory_stats['usage_percentage']); ?>%; 
                                 background-color: <?php echo $memory_stats['status'] === 'critical' ? '#dc3232' : 
                                     ($memory_stats['status'] === 'warning' ? '#ffb900' : 
                                     ($memory_stats['status'] === 'attention' ? '#00a0d2' : '#46b450')); ?>;">
                            </div>
                        </div>
                        <div class="usage-text">
                            <strong><?php echo esc_html($memory_stats['usage_percentage']); ?>%</strong>
                            (<?php echo esc_html($memory_stats['current']); ?> MB / <?php echo esc_html($memory_stats['available']); ?> MB)
                        </div>
                    </div>
                    
                    <div class="memory-peak-card">
                        <h3><?php _e('Pico de Memoria', 'mi-integracion-api'); ?></h3>
                        <div class="peak-value">
                            <strong><?php echo esc_html($memory_stats['peak']); ?> MB</strong>
                        </div>
                        <p><?php _e('Uso máximo durante esta sesión', 'mi-integracion-api'); ?></p>
                    </div>
                </div>
            </div>
        </div>
    
        <!-- Configuración de monitoreo -->
        <div class="mi-integracion-api-card">
            <div class="card-header">
                <h2><?php _e('Configuración de Monitoreo', 'mi-integracion-api'); ?></h2>
            </div>
            <div class="card-content">
                <form method="post" action="options.php" class="memory-config-form">
                    <?php settings_fields('mia_memory_options'); ?>
                    <?php do_settings_sections('mia_memory_options'); ?>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="mia_memory_monitoring_enabled" class="form-label">
                                <?php _e('Habilitar Monitoreo', 'mi-integracion-api'); ?>
                            </label>
                            <div class="form-control">
                                <input type="checkbox" id="mia_memory_monitoring_enabled" 
                                       name="mia_memory_monitoring_enabled" value="1" 
                                       <?php checked($monitoring_enabled); ?> />
                                <span class="form-description">
                                    <?php _e('Habilita el monitoreo avanzado de memoria con alertas y recomendaciones', 'mi-integracion-api'); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="mia_memory_warning_threshold" class="form-label">
                                <?php _e('Umbral de Advertencia (%)', 'mi-integracion-api'); ?>
                            </label>
                            <div class="form-control">
                                <input type="number" id="mia_memory_warning_threshold" 
                                       name="mia_memory_warning_threshold" 
                                       value="<?php echo esc_attr($warning_threshold); ?>" 
                                       min="50" max="95" step="5" class="form-input" />
                                <span class="form-description">
                                    <?php _e('Porcentaje de uso de memoria para generar advertencias (recomendado: 70%)', 'mi-integracion-api'); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="mia_memory_critical_threshold" class="form-label">
                                <?php _e('Umbral Crítico (%)', 'mi-integracion-api'); ?>
                            </label>
                            <div class="form-control">
                                <input type="number" id="mia_memory_critical_threshold" 
                                       name="mia_memory_critical_threshold" 
                                       value="<?php echo esc_attr($critical_threshold); ?>" 
                                       min="70" max="98" step="5" class="form-input" />
                                <span class="form-description">
                                    <?php _e('Porcentaje de uso de memoria para alertas críticas (recomendado: 90%)', 'mi-integracion-api'); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="mia_memory_cleanup_threshold" class="form-label">
                                <?php _e('Umbral de Limpieza (%)', 'mi-integracion-api'); ?>
                            </label>
                            <div class="form-control">
                                <input type="number" id="mia_memory_cleanup_threshold" 
                                       name="mia_memory_cleanup_threshold" 
                                       value="<?php echo esc_attr($cleanup_threshold); ?>" 
                                       min="60" max="90" step="5" class="form-input" />
                                <span class="form-description">
                                    <?php _e('Porcentaje de uso de memoria para sugerir limpieza (recomendado: 75%)', 'mi-integracion-api'); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="mia_memory_auto_cleanup_enabled" class="form-label">
                                <?php _e('Limpieza Automática', 'mi-integracion-api'); ?>
                            </label>
                            <div class="form-control">
                                <input type="checkbox" id="mia_memory_auto_cleanup_enabled" 
                                       name="mia_memory_auto_cleanup_enabled" value="1" 
                                       <?php checked($auto_cleanup_enabled); ?> />
                                <span class="form-description">
                                    <?php _e('Ejecuta limpieza automática de memoria cuando se alcance el umbral crítico', 'mi-integracion-api'); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="mia_memory_notifications_enabled" class="form-label">
                                <?php _e('Notificaciones', 'mi-integracion-api'); ?>
                            </label>
                            <div class="form-control">
                                <input type="checkbox" id="mia_memory_notifications_enabled" 
                                       name="mia_memory_notifications_enabled" value="1" 
                                       <?php checked($notifications_enabled); ?> />
                                <span class="form-description">
                                    <?php _e('Habilita notificaciones en el dashboard y logs para alertas de memoria', 'mi-integracion-api'); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="mia_memory_dashboard_refresh_interval" class="form-label">
                                <?php _e('Intervalo de Refresco (segundos)', 'mi-integracion-api'); ?>
                            </label>
                            <div class="form-control">
                                <input type="number" id="mia_memory_dashboard_refresh_interval" 
                                       name="mia_memory_dashboard_refresh_interval" 
                                       value="<?php echo esc_attr($refresh_interval); ?>" 
                                       min="10" max="300" step="10" class="form-input" />
                                <span class="form-description">
                                    <?php _e('Intervalo para refrescar automáticamente las estadísticas del dashboard', 'mi-integracion-api'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <?php submit_button(__('Guardar Configuración', 'mi-integracion-api'), 'primary', 'submit', false, array('class' => 'mi-integracion-api-btn mi-integracion-api-btn-primary')); ?>
                    </div>
                </form>
            </div>
        </div>
    
        <!-- Acciones de memoria -->
        <div class="mi-integracion-api-card">
            <div class="card-header">
                <h2><?php _e('Acciones de Memoria', 'mi-integracion-api'); ?></h2>
            </div>
            <div class="card-content">
                <div class="action-buttons">
                    <button type="button" class="mi-integracion-api-btn mi-integracion-api-btn-primary" id="cleanup-memory">
                        <span class="dashicons dashicons-trash"></span>
                        <?php _e('Limpiar Memoria Ahora', 'mi-integracion-api'); ?>
                    </button>
                    
                    <button type="button" class="mi-integracion-api-btn mi-integracion-api-btn-secondary" id="refresh-stats">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Refrescar Estadísticas', 'mi-integracion-api'); ?>
                    </button>
                    
                    <button type="button" class="mi-integracion-api-btn mi-integracion-api-btn-secondary" id="reset-history">
                        <span class="dashicons dashicons-backup"></span>
                        <?php _e('Resetear Historial', 'mi-integracion-api'); ?>
                    </button>
                </div>
                
                <div id="action-results" class="action-results"></div>
            </div>
        </div>
        
        <!-- Recomendaciones -->
        <div class="mi-integracion-api-card">
            <div class="card-header">
                <h2><?php _e('Recomendaciones', 'mi-integracion-api'); ?></h2>
            </div>
            <div class="card-content">
                <div class="recommendations-list">
                    <?php if (!empty($memory_stats['recommendations'])): ?>
                        <?php foreach ($memory_stats['recommendations'] as $recommendation): ?>
                            <div class="recommendation-item">
                                <span class="dashicons dashicons-lightbulb"></span>
                                <span><?php echo esc_html($recommendation); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="no-recommendations"><?php _e('No hay recomendaciones específicas en este momento.', 'mi-integracion-api'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    
        <!-- Historial de memoria -->
        <div class="mi-integracion-api-card">
            <div class="card-header">
                <h2><?php _e('Historial de Uso de Memoria', 'mi-integracion-api'); ?></h2>
            </div>
            <div class="card-content">
                <?php if (!empty($memory_history)): ?>
                    <div class="history-chart">
                        <canvas id="memoryChart" width="400" height="200"></canvas>
                    </div>
                    
                    <div class="history-table">
                        <div class="table-responsive">
                            <table class="mi-integracion-api-table">
                                <thead>
                                    <tr>
                                        <th><?php _e('Timestamp', 'mi-integracion-api'); ?></th>
                                        <th><?php _e('Uso (MB)', 'mi-integracion-api'); ?></th>
                                        <th><?php _e('Disponible (MB)', 'mi-integracion-api'); ?></th>
                                        <th><?php _e('Porcentaje (%)', 'mi-integracion-api'); ?></th>
                                        <th><?php _e('Estado', 'mi-integracion-api'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($memory_history, -20) as $record): ?>
                                        <tr>
                                            <td><?php echo esc_html($record['timestamp']); ?></td>
                                            <td><?php echo esc_html($record['current_usage_mb']); ?></td>
                                            <td><?php echo esc_html($record['available_memory_mb']); ?></td>
                                            <td><?php echo esc_html(round($record['usage_percentage'] * 100, 2)); ?></td>
                                            <td>
                                                <?php 
                                                $percentage = $record['usage_percentage'] * 100;
                                                if ($percentage >= $critical_threshold) {
                                                    echo '<span class="status-critical">' . __('Crítico', 'mi-integracion-api') . '</span>';
                                                } elseif ($percentage >= $warning_threshold) {
                                                    echo '<span class="status-warning">' . __('Advertencia', 'mi-integracion-api') . '</span>';
                                                } elseif ($percentage >= $cleanup_threshold) {
                                                    echo '<span class="status-attention">' . __('Atención', 'mi-integracion-api') . '</span>';
                                                } else {
                                                    echo '<span class="status-healthy">' . __('Saludable', 'mi-integracion-api') . '</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="no-data"><?php _e('No hay historial de memoria disponible.', 'mi-integracion-api'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Alertas de memoria -->
        <div class="mi-integracion-api-card">
            <div class="card-header">
                <h2><?php _e('Alertas de Memoria', 'mi-integracion-api'); ?></h2>
            </div>
            <div class="card-content">
                <?php if (!empty($memory_alerts)): ?>
                    <div class="alerts-list">
                        <?php foreach (array_slice($memory_alerts, -10) as $alert): ?>
                            <div class="alert-item alert-<?php echo esc_attr($alert['level']); ?>">
                                <div class="alert-header">
                                    <span class="alert-level"><?php echo esc_html(ucfirst($alert['level'])); ?></span>
                                    <span class="alert-time"><?php echo esc_html($alert['timestamp']); ?></span>
                                </div>
                                <div class="alert-message">
                                    <?php echo esc_html($alert['message']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="no-data"><?php _e('No hay alertas de memoria activas.', 'mi-integracion-api'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
// Enqueue assets necesarios
wp_enqueue_style('dashicons');
wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css', array(), '6.0.0');
$version = '1.0.0';
wp_enqueue_style('mi-integracion-api-design-system', plugin_dir_url(__FILE__) . '../../assets/css/design-system.css', array(), $version);
wp_enqueue_style('mi-integracion-api-dashboard', plugin_dir_url(__FILE__) . '../../assets/css/dashboard.css', array(), $version);
wp_enqueue_style('mi-integracion-api-unified-sidebar', plugin_dir_url(__FILE__) . '../../assets/css/unified-sidebar.css', array(), $version);
wp_enqueue_style('mi-integracion-api-memory-monitoring', plugin_dir_url(__FILE__) . '../../assets/css/memory-monitoring.css', array(), $version);

wp_enqueue_script('mi-integracion-api-unified-sidebar', plugin_dir_url(__FILE__) . '../../assets/js/unified-sidebar.js', array('jquery'), $version, true);
wp_enqueue_script('mi-integracion-api-memory-monitoring', plugin_dir_url(__FILE__) . '../../assets/js/memory-monitoring.js', array('jquery'), $version, true);

// Localizar script para AJAX
wp_localize_script('mi-integracion-api-memory-monitoring', 'miIntegracionApiMemory', array(
    'ajaxurl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('mi_memory_nonce'),
    'loading' => __('Procesando...', 'mi-integracion-api'),
    'error' => __('Error al procesar la solicitud', 'mi-integracion-api'),
    'success' => __('Operación completada exitosamente', 'mi-integracion-api'),
    'confirmCleanup' => __('¿Está seguro de que desea limpiar la memoria?', 'mi-integracion-api'),
    'confirmReset' => __('¿Está seguro de que desea resetear el historial?', 'mi-integracion-api')
));
?>
