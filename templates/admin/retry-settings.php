<?php
/**
 * Página de configuración del sistema de reintentos inteligente
 * 
 * @package MiIntegracionApi\Admin
 * @since 1.5.0
 */

// Verificar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Obtener configuración actual
$retry_system_enabled = get_option('mia_retry_system_enabled', true);
$default_max_attempts = get_option('mia_retry_default_max_attempts', 3);
$default_base_delay = get_option('mia_retry_default_base_delay', 2);
$max_delay = get_option('mia_retry_max_delay', 30);
$backoff_factor = get_option('mia_retry_backoff_factor', 2.0);
$jitter_enabled = get_option('mia_retry_jitter_enabled', true);
$jitter_max_ms = get_option('mia_retry_jitter_max_ms', 1000);

// Políticas por tipo de error
$policy_network = get_option('mia_retry_policy_network', 'aggressive');
$policy_server = get_option('mia_retry_policy_server', 'moderate');
$policy_client = get_option('mia_retry_policy_client', 'conservative');
$policy_validation = get_option('mia_retry_policy_validation', 'none');

// Configuración por tipo de operación
$sync_products_max_attempts = get_option('mia_retry_sync_products_max_attempts', 3);
$sync_orders_max_attempts = get_option('mia_retry_sync_orders_max_attempts', 4);
$sync_customers_max_attempts = get_option('mia_retry_sync_customers_max_attempts', 3);
$api_calls_max_attempts = get_option('mia_retry_api_calls_max_attempts', 5);
$ssl_operations_max_attempts = get_option('mia_retry_ssl_operations_max_attempts', 3);
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
                <li class="unified-nav-item active">
                    <a href="<?php echo admin_url('admin.php?page=mi-integracion-api-retry-settings'); ?>" class="unified-nav-link" data-page="retry">
                        <span class="nav-icon dashicons dashicons-update"></span>
                        <span class="nav-text">Reintentos</span>
                    </a>
                </li>
                <li class="unified-nav-item">
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
                    <button class="unified-action-btn" data-action="test-retry" title="<?php esc_attr_e('Probar sistema de reintentos', 'mi-integracion-api'); ?>">
                        <i class="fas fa-play"></i>
                        <span><?php esc_html_e('Probar', 'mi-integracion-api'); ?></span>
                    </button>
                    <button class="unified-action-btn" data-action="reset-settings" title="<?php esc_attr_e('Restablecer configuración', 'mi-integracion-api'); ?>">
                        <i class="fas fa-undo"></i>
                        <span><?php esc_html_e('Reset', 'mi-integracion-api'); ?></span>
                    </button>
                    <button class="unified-action-btn" data-action="view-logs" title="<?php esc_attr_e('Ver logs de reintentos', 'mi-integracion-api'); ?>">
                        <i class="fas fa-file-alt"></i>
                        <span><?php esc_html_e('Logs', 'mi-integracion-api'); ?></span>
                    </button>
                    <button class="unified-action-btn" data-action="export-config" title="<?php esc_attr_e('Exportar configuración', 'mi-integracion-api'); ?>">
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
                        <span class="config-label"><?php esc_html_e('Sistema:', 'mi-integracion-api'); ?></span>
                        <span class="config-value status-<?php echo $retry_system_enabled ? 'enabled' : 'disabled'; ?>">
                            <?php echo $retry_system_enabled ? esc_html__('Activado', 'mi-integracion-api') : esc_html__('Desactivado', 'mi-integracion-api'); ?>
                        </span>
                    </div>
                    <div class="unified-config-item">
                        <span class="config-label"><?php esc_html_e('Max Reintentos:', 'mi-integracion-api'); ?></span>
                        <span class="config-value"><?php echo esc_html($default_max_attempts); ?></span>
                    </div>
                    <div class="unified-config-item">
                        <span class="config-label"><?php esc_html_e('Retraso Base:', 'mi-integracion-api'); ?></span>
                        <span class="config-value"><?php echo esc_html($default_base_delay); ?>s</span>
                    </div>
                    <div class="unified-config-item">
                        <span class="config-label"><?php esc_html_e('Jitter:', 'mi-integracion-api'); ?></span>
                        <span class="config-value status-<?php echo $jitter_enabled ? 'enabled' : 'disabled'; ?>">
                            <?php echo $jitter_enabled ? esc_html__('Activado', 'mi-integracion-api') : esc_html__('Desactivado', 'mi-integracion-api'); ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Búsqueda -->
            <div class="unified-search-section">
                <h3><?php esc_html_e('Búsqueda', 'mi-integracion-api'); ?></h3>
                <div class="unified-search-form">
                    <input type="text" placeholder="<?php esc_attr_e('Buscar en configuración...', 'mi-integracion-api'); ?>" class="unified-search-input">
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
                    <span class="dashicons dashicons-update"></span>
                </div>
                <div class="banner-text">
                    <h1><?php echo esc_html__('Sistema de Reintentos Inteligente', 'mi-integracion-api'); ?></h1>
                    <p><?php echo esc_html__('Configura y optimiza las políticas de reintentos para todas las operaciones del plugin', 'mi-integracion-api'); ?></p>
                </div>
                <div class="banner-visual">
                    <div class="visual-animation">
                        <div class="retry-icon">
                            <span class="dashicons dashicons-update"></span>
                        </div>
                        <div class="retry-indicators">
                            <div class="indicator network"></div>
                            <div class="indicator server"></div>
                            <div class="indicator client"></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Icono de Ayuda -->
            <div class="retry-settings-help">
                <a href="<?php echo esc_url(MiIntegracionApi_PLUGIN_URL . 'docs/manual-usuario/manual-reintentos.html'); ?>"
                target="_blank" 
                class="help-link"
                title="<?php esc_attr_e('Abrir Manual de Reintentos', 'mi-integracion-api'); ?>">
                    <i class="fas fa-question-circle"></i>
                    <span><?php esc_html_e('Ayuda', 'mi-integracion-api'); ?></span>
                </a>
            </div>
        </div>

        <form method="post" action="options.php" class="retry-settings-form">
            <?php settings_fields('mia_retry_settings'); ?>
            <?php do_settings_sections('mia_retry_settings'); ?>

            <!-- Configuración General -->
            <div class="mi-integracion-api-card">
                <h2><?php echo esc_html__('Configuración General', 'mi-integracion-api'); ?></h2>
                
                <div class="retry-settings-grid">
                    <div class="retry-setting-item">
                        <div class="setting-header">
                            <label for="mia_retry_system_enabled" class="setting-label">
                                <?php echo esc_html__('Habilitar Sistema de Reintentos', 'mi-integracion-api'); ?>
                            </label>
                            <div class="setting-description">
                                <?php echo esc_html__('Activa el sistema inteligente de reintentos para todas las operaciones.', 'mi-integracion-api'); ?>
                            </div>
                        </div>
                        <div class="setting-control">
                            <label class="checkbox-label">
                                <input type="checkbox" id="mia_retry_system_enabled" 
                                       name="mia_retry_system_enabled" value="1" 
                                       <?php checked($retry_system_enabled); ?>
                                       class="mi-checkbox" />
                                <span class="checkmark"></span>
                            </label>
                        </div>
                    </div>

                    <div class="retry-setting-item">
                        <div class="setting-header">
                            <label for="mia_retry_default_max_attempts" class="setting-label">
                                <?php echo esc_html__('Máximo de Reintentos por Defecto', 'mi-integracion-api'); ?>
                            </label>
                            <div class="setting-description">
                                <?php echo esc_html__('Número máximo de reintentos para operaciones sin política específica.', 'mi-integracion-api'); ?>
                            </div>
                        </div>
                        <div class="setting-control">
                            <input type="number" id="mia_retry_default_max_attempts" 
                                   name="mia_retry_default_max_attempts" 
                                   value="<?php echo esc_attr($default_max_attempts); ?>" 
                                   min="0" max="10" 
                                   class="mi-integracion-api-input" />
                        </div>
                    </div>

                    <div class="retry-setting-item">
                        <div class="setting-header">
                            <label for="mia_retry_default_base_delay" class="setting-label">
                                <?php echo esc_html__('Retraso Base por Defecto (segundos)', 'mi-integracion-api'); ?>
                            </label>
                            <div class="setting-description">
                                <?php echo esc_html__('Retraso base antes del primer reintento.', 'mi-integracion-api'); ?>
                            </div>
                        </div>
                        <div class="setting-control">
                            <input type="number" id="mia_retry_default_base_delay" 
                                   name="mia_retry_default_base_delay" 
                                   value="<?php echo esc_attr($default_base_delay); ?>" 
                                   min="1" max="60" step="0.5" 
                                   class="mi-integracion-api-input" />
                        </div>
                    </div>

                    <div class="retry-setting-item">
                        <div class="setting-header">
                            <label for="mia_retry_max_delay" class="setting-label">
                                <?php echo esc_html__('Retraso Máximo (segundos)', 'mi-integracion-api'); ?>
                            </label>
                            <div class="setting-description">
                                <?php echo esc_html__('Retraso máximo permitido entre reintentos.', 'mi-integracion-api'); ?>
                            </div>
                        </div>
                        <div class="setting-control">
                            <input type="number" id="mia_retry_max_delay" 
                                   name="mia_retry_max_delay" 
                                   value="<?php echo esc_attr($max_delay); ?>" 
                                   min="5" max="300" 
                                   class="mi-integracion-api-input" />
                        </div>
                    </div>

                    <div class="retry-setting-item">
                        <div class="setting-header">
                            <label for="mia_retry_backoff_factor" class="setting-label">
                                <?php echo esc_html__('Factor de Backoff Exponencial', 'mi-integracion-api'); ?>
                            </label>
                            <div class="setting-description">
                                <?php echo esc_html__('Factor multiplicador para el backoff exponencial (ej: 2.0 = doble cada reintento).', 'mi-integracion-api'); ?>
                            </div>
                        </div>
                        <div class="setting-control">
                            <input type="number" id="mia_retry_backoff_factor" 
                                   name="mia_retry_backoff_factor" 
                                   value="<?php echo esc_attr($backoff_factor); ?>" 
                                   min="1.0" max="5.0" step="0.1" 
                                   class="mi-integracion-api-input" />
                        </div>
                    </div>

                    <div class="retry-setting-item">
                        <div class="setting-header">
                            <label for="mia_retry_jitter_enabled" class="setting-label">
                                <?php echo esc_html__('Habilitar Jitter', 'mi-integracion-api'); ?>
                            </label>
                            <div class="setting-description">
                                <?php echo esc_html__('Agrega variabilidad aleatoria para evitar el efecto "thundering herd".', 'mi-integracion-api'); ?>
                            </div>
                        </div>
                        <div class="setting-control">
                            <label class="checkbox-label">
                                <input type="checkbox" id="mia_retry_jitter_enabled" 
                                       name="mia_retry_jitter_enabled" value="1" 
                                       <?php checked($jitter_enabled); ?>
                                       class="mi-checkbox" />
                                <span class="checkmark"></span>
                            </label>
                        </div>
                    </div>

                    <div class="retry-setting-item">
                        <div class="setting-header">
                            <label for="mia_retry_jitter_max_ms" class="setting-label">
                                <?php echo esc_html__('Jitter Máximo (milisegundos)', 'mi-integracion-api'); ?>
                            </label>
                            <div class="setting-description">
                                <?php echo esc_html__('Variabilidad máxima en milisegundos para el jitter.', 'mi-integracion-api'); ?>
                            </div>
                        </div>
                        <div class="setting-control">
                            <input type="number" id="mia_retry_jitter_max_ms" 
                                   name="mia_retry_jitter_max_ms" 
                                   value="<?php echo esc_attr($jitter_max_ms); ?>" 
                                   min="0" max="5000" step="100" 
                                   class="mi-integracion-api-input" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Políticas por Tipo de Error -->
            <div class="mi-integracion-api-card">
                <h2><?php echo esc_html__('Políticas por Tipo de Error', 'mi-integracion-api'); ?></h2>
                
                <div class="retry-policies-grid">
                    <div class="retry-policy-item">
                        <div class="policy-header">
                            <h3><?php echo esc_html__('Errores de Red y Conectividad', 'mi-integracion-api'); ?></h3>
                            <p class="policy-description">
                                <?php echo esc_html__('Política para errores de conectividad, timeouts y SSL.', 'mi-integracion-api'); ?>
                            </p>
                        </div>
                        <div class="policy-control">
                            <select id="mia_retry_policy_network" name="mia_retry_policy_network" class="mi-integracion-api-select">
                                <option value="aggressive" <?php selected($policy_network, 'aggressive'); ?>>
                                    <?php echo esc_html__('Agresivo (5 reintentos, retraso 1s)', 'mi-integracion-api'); ?>
                                </option>
                                <option value="moderate" <?php selected($policy_network, 'moderate'); ?>>
                                    <?php echo esc_html__('Moderado (3 reintentos, retraso 2s)', 'mi-integracion-api'); ?>
                                </option>
                                <option value="conservative" <?php selected($policy_network, 'conservative'); ?>>
                                    <?php echo esc_html__('Conservador (2 reintentos, retraso 3s)', 'mi-integracion-api'); ?>
                                </option>
                                <option value="none" <?php selected($policy_network, 'none'); ?>>
                                    <?php echo esc_html__('Sin reintentos', 'mi-integracion-api'); ?>
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="retry-policy-item">
                        <div class="policy-header">
                            <h3><?php echo esc_html__('Errores del Servidor (5xx)', 'mi-integracion-api'); ?></h3>
                            <p class="policy-description">
                                <?php echo esc_html__('Política para errores 500, 502, 503, 504 del servidor.', 'mi-integracion-api'); ?>
                            </p>
                        </div>
                        <div class="policy-control">
                            <select id="mia_retry_policy_server" name="mia_retry_policy_server" class="mi-integracion-api-select">
                                <option value="aggressive" <?php selected($policy_server, 'aggressive'); ?>>
                                    <?php echo esc_html__('Agresivo (5 reintentos, retraso 1s)', 'mi-integracion-api'); ?>
                                </option>
                                <option value="moderate" <?php selected($policy_server, 'moderate'); ?>>
                                    <?php echo esc_html__('Moderado (3 reintentos, retraso 2s)', 'mi-integracion-api'); ?>
                                </option>
                                <option value="conservative" <?php selected($policy_server, 'conservative'); ?>>
                                    <?php echo esc_html__('Conservador (2 reintentos, retraso 3s)', 'mi-integracion-api'); ?>
                                </option>
                                <option value="none" <?php selected($policy_server, 'none'); ?>>
                                    <?php echo esc_html__('Sin reintentos', 'mi-integracion-api'); ?>
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="retry-policy-item">
                        <div class="policy-header">
                            <h3><?php echo esc_html__('Errores del Cliente (4xx)', 'mi-integracion-api'); ?></h3>
                            <p class="policy-description">
                                <?php echo esc_html__('Política para errores 400, 401, 403, 404 del cliente.', 'mi-integracion-api'); ?>
                            </p>
                        </div>
                        <div class="policy-control">
                            <select id="mia_retry_policy_client" name="mia_retry_policy_client" class="mi-integracion-api-select">
                                <option value="aggressive" <?php selected($policy_client, 'aggressive'); ?>>
                                    <?php echo esc_html__('Agresivo (5 reintentos, retraso 1s)', 'mi-integracion-api'); ?>
                                </option>
                                <option value="moderate" <?php selected($policy_client, 'moderate'); ?>>
                                    <?php echo esc_html__('Moderado (3 reintentos, retraso 2s)', 'mi-integracion-api'); ?>
                                </option>
                                <option value="conservative" <?php selected($policy_client, 'conservative'); ?>>
                                    <?php echo esc_html__('Conservador (2 reintentos, retraso 3s)', 'mi-integracion-api'); ?>
                                </option>
                                <option value="none" <?php selected($policy_client, 'none'); ?>>
                                    <?php echo esc_html__('Sin reintentos', 'mi-integracion-api'); ?>
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="retry-policy-item">
                        <div class="policy-header">
                            <h3><?php echo esc_html__('Errores de Validación', 'mi-integracion-api'); ?></h3>
                            <p class="policy-description">
                                <?php echo esc_html__('Política para errores de validación de datos (no reintentables por defecto).', 'mi-integracion-api'); ?>
                            </p>
                        </div>
                        <div class="policy-control">
                            <select id="mia_retry_policy_validation" name="mia_retry_policy_validation" class="mi-integracion-api-select">
                                <option value="aggressive" <?php selected($policy_validation, 'aggressive'); ?>>
                                    <?php echo esc_html__('Agresivo (5 reintentos, retraso 1s)', 'mi-integracion-api'); ?>
                                </option>
                                <option value="moderate" <?php selected($policy_validation, 'moderate'); ?>>
                                    <?php echo esc_html__('Moderado (3 reintentos, retraso 2s)', 'mi-integracion-api'); ?>
                                </option>
                                <option value="conservative" <?php selected($policy_validation, 'conservative'); ?>>
                                    <?php echo esc_html__('Conservador (2 reintentos, retraso 3s)', 'mi-integracion-api'); ?>
                                </option>
                                <option value="none" <?php selected($policy_validation, 'none'); ?>>
                                    <?php echo esc_html__('Sin reintentos (recomendado)', 'mi-integracion-api'); ?>
                                </option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Configuración por Tipo de Operación -->
            <div class="mi-integracion-api-card">
                <h2><?php echo esc_html__('Configuración por Tipo de Operación', 'mi-integracion-api'); ?></h2>
                
                <div class="retry-operations-grid">
                    <div class="retry-operation-item">
                        <div class="operation-header">
                            <h3><?php echo esc_html__('Sincronización de Productos', 'mi-integracion-api'); ?></h3>
                            <p class="operation-description">
                                <?php echo esc_html__('Máximo de reintentos para sincronización de productos (0 = usar configuración por defecto).', 'mi-integracion-api'); ?>
                            </p>
                        </div>
                        <div class="operation-control">
                            <input type="number" id="mia_retry_sync_products_max_attempts" 
                                   name="mia_retry_sync_products_max_attempts" 
                                   value="<?php echo esc_attr($sync_products_max_attempts); ?>" 
                                   min="0" max="10" 
                                   class="mi-integracion-api-input" />
                        </div>
                    </div>

                    <div class="retry-operation-item">
                        <div class="operation-header">
                            <h3><?php echo esc_html__('Sincronización de Pedidos', 'mi-integracion-api'); ?></h3>
                            <p class="operation-description">
                                <?php echo esc_html__('Máximo de reintentos para sincronización de pedidos (crítico, 0 = usar configuración por defecto).', 'mi-integracion-api'); ?>
                            </p>
                        </div>
                        <div class="operation-control">
                            <input type="number" id="mia_retry_sync_orders_max_attempts" 
                                   name="mia_retry_sync_orders_max_attempts" 
                                   value="<?php echo esc_attr($sync_orders_max_attempts); ?>" 
                                   min="0" max="10" 
                                   class="mi-integracion-api-input" />
                        </div>
                    </div>

                    <div class="retry-operation-item">
                        <div class="operation-header">
                            <h3><?php echo esc_html__('Sincronización de Clientes', 'mi-integracion-api'); ?></h3>
                            <p class="operation-description">
                                <?php echo esc_html__('Máximo de reintentos para sincronización de clientes (0 = usar configuración por defecto).', 'mi-integracion-api'); ?>
                            </p>
                        </div>
                        <div class="operation-control">
                            <input type="number" id="mia_retry_sync_customers_max_attempts" 
                                   name="mia_retry_sync_customers_max_attempts" 
                                   value="<?php echo esc_attr($sync_customers_max_attempts); ?>" 
                                   min="0" max="10" 
                                   class="mi-integracion-api-input" />
                        </div>
                    </div>

                    <div class="retry-operation-item">
                        <div class="operation-header">
                            <h3><?php echo esc_html__('Llamadas API Generales', 'mi-integracion-api'); ?></h3>
                            <p class="operation-description">
                                <?php echo esc_html__('Máximo de reintentos para llamadas API generales (0 = usar configuración por defecto).', 'mi-integracion-api'); ?>
                            </p>
                        </div>
                        <div class="operation-control">
                            <input type="number" id="mia_retry_api_calls_max_attempts" 
                                   name="mia_retry_api_calls_max_attempts" 
                                   value="<?php echo esc_attr($api_calls_max_attempts); ?>" 
                                   min="0" max="10" 
                                   class="mi-integracion-api-input" />
                        </div>
                    </div>

                    <div class="retry-operation-item">
                        <div class="operation-header">
                            <h3><?php echo esc_html__('Operaciones SSL', 'mi-integracion-api'); ?></h3>
                            <p class="operation-description">
                                <?php echo esc_html__('Máximo de reintentos para operaciones SSL (0 = usar configuración por defecto).', 'mi-integracion-api'); ?>
                            </p>
                        </div>
                        <div class="operation-control">
                            <input type="number" id="mia_retry_ssl_operations_max_attempts" 
                                   name="mia_retry_ssl_operations_max_attempts" 
                                   value="<?php echo esc_attr($ssl_operations_max_attempts); ?>" 
                                   min="0" max="10" 
                                   class="mi-integracion-api-input" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Botón de guardar -->
            <div class="form-actions">
                <button type="submit" class="mi-integracion-api-button primary">
                    <span class="dashicons dashicons-saved"></span>
                    <?php esc_html_e('Guardar Configuración de Reintentos', 'mi-integracion-api'); ?>
                </button>
            </div>
        </form>

        <!-- Información del Sistema -->
        <div class="mi-integracion-api-card">
            <h2><?php echo esc_html__('Información del Sistema de Reintentos', 'mi-integracion-api'); ?></h2>
            
            <div class="retry-info-grid">
                <div class="retry-info-item">
                    <h3><?php echo esc_html__('Características del Sistema', 'mi-integracion-api'); ?></h3>
                    <ul class="retry-features-list">
                        <li>
                            <span class="feature-icon dashicons dashicons-admin-tools"></span>
                            <div class="feature-content">
                                <strong><?php echo esc_html__('Centralizado:', 'mi-integracion-api'); ?></strong>
                                <?php echo esc_html__('Todas las políticas de reintentos en un solo lugar.', 'mi-integracion-api'); ?>
                            </div>
                        </li>
                        <li>
                            <span class="feature-icon dashicons dashicons-lightbulb"></span>
                            <div class="feature-content">
                                <strong><?php echo esc_html__('Inteligente:', 'mi-integracion-api'); ?></strong>
                                <?php echo esc_html__('Diferentes estrategias según el tipo de error y operación.', 'mi-integracion-api'); ?>
                            </div>
                        </li>
                        <li>
                            <span class="feature-icon dashicons dashicons-admin-settings"></span>
                            <div class="feature-content">
                                <strong><?php echo esc_html__('Configurable:', 'mi-integracion-api'); ?></strong>
                                <?php echo esc_html__('Políticas personalizables vía WordPress admin.', 'mi-integracion-api'); ?>
                            </div>
                        </li>
                        <li>
                            <span class="feature-icon dashicons dashicons-chart-line"></span>
                            <div class="feature-content">
                                <strong><?php echo esc_html__('Backoff Exponencial:', 'mi-integracion-api'); ?></strong>
                                <?php echo esc_html__('Retrasos inteligentes que aumentan progresivamente.', 'mi-integracion-api'); ?>
                            </div>
                        </li>
                        <li>
                            <span class="feature-icon dashicons dashicons-randomize"></span>
                            <div class="feature-content">
                                <strong><?php echo esc_html__('Jitter:', 'mi-integracion-api'); ?></strong>
                                <?php echo esc_html__('Variabilidad aleatoria para evitar sobrecarga del servidor.', 'mi-integracion-api'); ?>
                            </div>
                        </li>
                        <li>
                            <span class="feature-icon dashicons dashicons-text-page"></span>
                            <div class="feature-content">
                                <strong><?php echo esc_html__('Logging Avanzado:', 'mi-integracion-api'); ?></strong>
                                <?php echo esc_html__('Registro detallado de todos los reintentos y políticas.', 'mi-integracion-api'); ?>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="retry-info-item">
                    <h3><?php echo esc_html__('Beneficios de la Refactorización', 'mi-integracion-api'); ?></h3>
                    <ul class="retry-benefits-list">
                        <li>
                            <span class="benefit-icon dashicons dashicons-yes-alt"></span>
                            <div class="benefit-content">
                                <strong><?php echo esc_html__('Eliminación de Duplicaciones:', 'mi-integracion-api'); ?></strong>
                                <?php echo esc_html__('Un solo sistema para todos los reintentos.', 'mi-integracion-api'); ?>
                            </div>
                        </li>
                        <li>
                            <span class="benefit-icon dashicons dashicons-code-standards"></span>
                            <div class="benefit-content">
                                <strong><?php echo esc_html__('Sin Hardcodeos:', 'mi-integracion-api'); ?></strong>
                                <?php echo esc_html__('Toda la configuración es dinámica y personalizable.', 'mi-integracion-api'); ?>
                            </div>
                        </li>
                        <li>
                            <span class="benefit-icon dashicons dashicons-admin-tools"></span>
                            <div class="benefit-content">
                                <strong><?php echo esc_html__('Mantenibilidad:', 'mi-integracion-api'); ?></strong>
                                <?php echo esc_html__('Código más limpio y fácil de mantener.', 'mi-integracion-api'); ?>
                            </div>
                        </li>
                        <li>
                            <span class="benefit-icon dashicons dashicons-chart-area"></span>
                            <div class="benefit-content">
                                <strong><?php echo esc_html__('Escalabilidad:', 'mi-integracion-api'); ?></strong>
                                <?php echo esc_html__('Fácil agregar nuevos tipos de errores y operaciones.', 'mi-integracion-api'); ?>
                            </div>
                        </li>
                        <li>
                            <span class="benefit-icon dashicons dashicons-visibility"></span>
                            <div class="benefit-content">
                                <strong><?php echo esc_html__('Monitoreo:', 'mi-integracion-api'); ?></strong>
                                <?php echo esc_html__('Estadísticas detalladas de reintentos y rendimiento.', 'mi-integracion-api'); ?>
                            </div>
                        </li>
                    </ul>
                </div>
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
wp_enqueue_style('mi-integracion-api-retry-settings', plugin_dir_url(__FILE__) . '../../assets/css/retry-settings.css', array(), $version);

wp_enqueue_script('mi-integracion-api-unified-sidebar', plugin_dir_url(__FILE__) . '../../assets/js/unified-sidebar.js', array('jquery'), $version, true);
wp_enqueue_script('mi-integracion-api-retry-settings', plugin_dir_url(__FILE__) . '../../assets/js/retry-settings.js', array('jquery'), $version, true);

// Localizar script para AJAX
wp_localize_script('mi-integracion-api-retry-settings', 'miIntegracionApiRetry', array(
    'ajaxurl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('mi_retry_nonce'),
    'loading' => __('Procesando...', 'mi-integracion-api'),
    'error' => __('Error al procesar la solicitud', 'mi-integracion-api'),
    'success' => __('Operación completada exitosamente', 'mi-integracion-api'),
    'confirmReset' => __('¿Está seguro de que desea restablecer la configuración?', 'mi-integracion-api'),
    'confirmTest' => __('¿Está seguro de que desea probar el sistema de reintentos?', 'mi-integracion-api')
));
?>