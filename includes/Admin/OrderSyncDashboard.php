<?php

declare(strict_types=1);

/**
 * Clase para gestionar el dashboard de sincronización de pedidos
 *
 * Proporciona la funcionalidad para monitorear y gestionar la sincronización
 * de pedidos de WooCommerce con Verial
 *
 * @package    MiIntegracionApi
 * @subpackage Admin
 * @since      1.2.0
 */

namespace MiIntegracionApi\Admin;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\WooCommerce\OrderManager;
use MiIntegracionApi\Core\Sync_Manager;

/**
 * Clase OrderSyncDashboard
 * Proporciona la funcionalidad para el dashboard de sincronización de pedidos
 */
class OrderSyncDashboard {
    /**
     * Instancia única de esta clase (patrón Singleton)
     * 
     * @var OrderSyncDashboard
     */
    private static $instance = null;

    /**
     * Logger para registrar errores y eventos
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Constructor
     */
    private function __construct() {
        // Inicializar logger solo si la clase existe
        if (class_exists('MiIntegracionApi\\Logger')) {
            $this->logger = new Logger('order-sync-dashboard');
        }
        
        // Registrar hooks AJAX
        add_action('wp_ajax_mi_integracion_get_orders_sync_status', array($this, 'ajax_get_orders_sync_status'));
        add_action('wp_ajax_mi_integracion_sync_single_order', array($this, 'ajax_sync_single_order'));
        add_action('wp_ajax_mi_integracion_fix_all_failed_orders', array($this, 'ajax_fix_all_failed_orders'));
        add_action('wp_ajax_mi_integracion_preview_order_json', array($this, 'ajax_preview_order_json'));
        
        // Endpoint de prueba
        add_action('wp_ajax_mi_integracion_test_ajax', array($this, 'ajax_test_connection'));
        
        // Registrar CSS y JS específico para el dashboard de órdenes
        add_action('admin_enqueue_scripts', array($this, 'register_assets'));
    }
    
    /**
     * Obtiene la instancia única de la clase (patrón Singleton)
     *
     * @return OrderSyncDashboard
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Renderiza la página completa de sincronización de pedidos
     * 
     * @return void
     */
    public function render_order_sync_page() {
        // Verificar si WooCommerce está activo
        if (!class_exists('WooCommerce')) {
            echo '<div class="wrap"><h1>' . esc_html__('Sincronización de Pedidos', 'mi-integracion-api') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('WooCommerce no está activo. Esta funcionalidad requiere WooCommerce.', 'mi-integracion-api') . '</p></div></div>';
            return;
        }
        
        // Obtener datos iniciales para mostrar (solo totales)
        $stats = $this->get_order_sync_stats();
        ?>
        <div class="wrap mi-order-sync-admin">
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
                        <li class="unified-nav-item active">
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
                            <button class="unified-action-btn" data-action="sync-orders" title="Sincronizar Pedidos">
                                <i class="fas fa-sync-alt"></i>
                                <span>Sincronizar</span>
                            </button>
                            <button class="unified-action-btn" id="refresh-orders" title="Actualizar Lista de Pedidos">
                                <i class="fas fa-refresh"></i>
                                <span>Actualizar</span>
                            </button>
                            <button class="unified-action-btn" data-action="export-orders" title="Exportar Datos de Pedidos">
                                <i class="fas fa-download"></i>
                                <span>Exportar</span>
                            </button>
                            <button class="unified-action-btn" data-action="retry-failed" title="Reintentar Pedidos Fallidos">
                                <i class="fas fa-redo"></i>
                                <span>Reintentar</span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Sección de Configuración -->
                    <div class="unified-config-section">
                        <h3>Configuración</h3>
                        <div class="unified-config-item">
                            <label for="theme-switcher">Tema:</label>
                            <select id="theme-switcher" class="theme-switcher">
                                <option value="default">Por Defecto</option>
                                <option value="dark">Oscuro</option>
                                <option value="light">Claro</option>
                            </select>
                        </div>
                        <div class="unified-config-item">
                            <label for="orders-per-page">Pedidos por página:</label>
                            <select id="orders-per-page" class="unified-input">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Búsqueda -->
                    <div class="unified-search-section">
                        <label for="unified-menu-search" class="screen-reader-text"><?php esc_html_e('Buscar en menú', 'mi-integracion-api'); ?></label>
                        <input type="text" id="unified-menu-search" class="unified-search-input" placeholder="<?php esc_attr_e('Buscar en menú...', 'mi-integracion-api'); ?>">
                    </div>
                </div>
            </div>

            <!-- Contenido principal -->
            <div class="mi-integracion-api-main-content">
                <?php $this->render_order_sync_dashboard(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renderiza la sección del dashboard para sincronización de pedidos
     * 
     * @return void
     */
    public function render_order_sync_dashboard() {
        // Verificar si WooCommerce está activo
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Obtener datos iniciales para mostrar (solo totales)
        $stats = $this->get_order_sync_stats();
        
        // Incluir la plantilla
        ?>
        <!-- Banner principal -->
        <div class="mi-order-sync-banner">
            <div class="banner-content">
                <div class="banner-text">
                    <h1>🛒 Sincronización de Pedidos</h1>
                    <p>Monitorea y gestiona la sincronización de pedidos entre WooCommerce y Verial en tiempo real.</p>
                    <div class="banner-highlight">
                        <span class="woo">WooCommerce</span>
                        <span class="sync">🔄</span>
                        <span class="shop">Verial</span>
                    </div>
                </div>
                <div class="banner-visual">
                    <div class="order-box box-1">
                        <div class="order-label">Pedido #1234</div>
                        <div class="order-status">Sincronizado</div>
                    </div>
                    <div class="sync-animation">
                        <div class="sync-circle"></div>
                    </div>
                    <div class="arrows">
                        <div class="arrow">→</div>
                        <div class="arrow">←</div>
                    </div>
                    <div class="order-box box-2">
                        <div class="order-label">Pedido #5678</div>
                        <div class="order-status">Pendiente</div>
                    </div>
                    <div class="database">
                        <div class="db-line"></div>
                        <div class="db-line"></div>
                        <div class="db-line"></div>
                    </div>
                </div>
                <!-- Icono de Ayuda -->
                <div class="order-sync-help">
                    <a href="<?php echo esc_url(MiIntegracionApi_PLUGIN_URL . 'docs/manual-usuario/manual-sincronizacion-pedidos.html'); ?>" 
                    target="_blank" 
                    class="help-link"
                    title="<?php esc_attr_e('Abrir Manual de Sincronización de Pedidos', 'mi-integracion-api'); ?>">
                        <i class="fas fa-question-circle"></i>
                        <span><?php esc_html_e('Ayuda', 'mi-integracion-api'); ?></span>
                    </a>
                </div>
            </div>
        </div>



        <!-- Grid de estadísticas -->
        <div class="mi-order-sync-stats-grid">
            <div class="mi-order-sync-stat-card">
                <div class="mi-order-sync-stat-icon">📋</div>
                <div class="mi-order-sync-stat-value"><?php echo $stats['total']; ?></div>
                <div class="mi-order-sync-stat-label">Total de Pedidos</div>
            </div>
            <div class="mi-order-sync-stat-card sync-success">
                <div class="mi-order-sync-stat-icon">✅</div>
                <div class="mi-order-sync-stat-value"><?php echo $stats['synced']; ?></div>
                <div class="mi-order-sync-stat-label">Sincronizados</div>
            </div>
            <div class="mi-order-sync-stat-card sync-error">
                <div class="mi-order-sync-stat-icon">❌</div>
                <div class="mi-order-sync-stat-value"><?php echo $stats['failed']; ?></div>
                <div class="mi-order-sync-stat-label">Con Errores</div>
            </div>
            <div class="mi-order-sync-stat-card sync-pending">
                <div class="mi-order-sync-stat-icon">⏳</div>
                <div class="mi-order-sync-stat-value"><?php echo $stats['pending']; ?></div>
                <div class="mi-order-sync-stat-label">Pendientes</div>
            </div>
        </div>

        <div id="mi-order-sync-monitor" class="dashboard-card">
            <div class="order-sync-header">
                <div class="order-sync-title">
                    <span class="dashicons dashicons-cart"></span>
                    <h2>Monitor de Sincronización de Pedidos</h2>
                </div>
                <div class="order-sync-info">
                    <span class="sync-api-status" data-tooltip="Estado de la conexión con Verial">
                        <span class="sync-api-indicator online"></span> API Conectada
                    </span>
                    <small id="last-sync-check-time">Última verificación: <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format')); ?></small>
                </div>
            </div>

            <!-- Barra de herramientas -->
            <div class="mi-order-sync-toolbar">
                <div class="mi-order-sync-filters">
                    <div class="mi-order-sync-filter-group">
                        <label for="order-status-filter">Estado:</label>
                        <select id="order-status-filter" class="mi-order-sync-select">
                            <option value="">Todos</option>
                            <option value="synced">Sincronizados</option>
                            <option value="failed">Con Errores</option>
                            <option value="pending">Pendientes</option>
                        </select>
                    </div>
                    <div class="mi-order-sync-filter-group">
                        <label for="order-date-filter">Fecha:</label>
                        <select id="order-date-filter" class="mi-order-sync-select">
                            <option value="">Todas</option>
                            <option value="today">Hoy</option>
                            <option value="week">Esta semana</option>
                            <option value="month">Este mes</option>
                        </select>
                    </div>
                    <div class="mi-order-sync-search-box">
                        <span class="search-icon dashicons dashicons-search"></span>
                        <input type="text" id="order-search" class="mi-order-sync-search" placeholder="Buscar por ID o cliente...">
                    </div>
                </div>
                <div class="mi-order-sync-actions">
                    <button class="button" id="refresh-orders">
                        <span class="dashicons dashicons-update"></span>
                        Actualizar
                    </button>
                    <button class="button" id="sync-all-orders">
                        <span class="dashicons dashicons-update"></span>
                        Sincronizar Todos
                    </button>
                </div>
            </div>
            
            <!-- Tabla de pedidos -->
            <div class="mi-order-sync-table-container">
                <div id="order-sync-loading" class="loading-overlay">
                    <div class="sync-loader">
                        <div class="sync-spinner"></div>
                        <p>Cargando pedidos...</p>
                    </div>
                </div>
                
                <table id="order-sync-status-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Cliente</th>
                            <th>Total</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                            <th>Última Sincronización</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="loading-row">
                            <td colspan="7">Cargando pedidos...</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div class="mi-order-sync-pagination-container">
                <div class="mi-order-sync-pagination" id="order-sync-pagination">
                    <!-- La paginación se generará dinámicamente -->
                </div>
            </div>
            
            <?php 
            /* <!-- Sección de métricas y gráficas -->
            <div class="order-sync-metrics">
                <h3 class="metrics-title">
                    <span class="dashicons dashicons-chart-line"></span> 
                    Métricas y Tendencias de Sincronización
                </h3>
                
                <?php
                // Obtener datos de gráficas
                $chart_data = $this->get_sync_chart_data();
                $avg_time = $chart_data['avg_sync_time'];
                
                // Formatear tiempo promedio
                $avg_time_formatted = '';
                if ($avg_time > 3600) {
                    $hours = floor($avg_time / 3600);
                    $minutes = floor(($avg_time % 3600) / 60);
                    $avg_time_formatted = $hours . 'h ' . $minutes . 'm';
                } elseif ($avg_time > 60) {
                    $minutes = floor($avg_time / 60);
                    $seconds = $avg_time % 60;
                    $avg_time_formatted = $minutes . 'm ' . $seconds . 's';
                } else {
                    $avg_time_formatted = $avg_time . 's';
                }
                ?>
                
                <div class="metrics-grid">
                    <div class="chart-card" id="sync-status-chart-container">
                        <h4 class="chart-title">Distribución de estados</h4>
                        <div class="chart-container">
                            <canvas id="sync-status-chart"></canvas>
                        </div>
                    </div>
                    
                    <div class="chart-card" id="sync-time-stats">
                        <h4 class="chart-title">Tiempo Promedio de Sincronización</h4>
                        <div class="chart-container time-metric">
                            <div class="time-icon">
                                <span class="dashicons dashicons-clock"></span>
                            </div>
                            <div class="time-content">
                                <span class="time-value"><?php echo $avg_time_formatted; ?></span>
                                <span class="time-label">Desde creación hasta sincronización</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="chart-card" id="sync-trend-chart-container">
                        <h4 class="chart-title">Tendencias de los últimos 7 días</h4>
                        <div class="chart-container">
                            <canvas id="sync-trend-chart"></canvas>
                        </div>
                    </div>
                </div>
                
                <script>
                // Inicialización de gráficas cuando el DOM está listo
                jQuery(document).ready(function($) {
                    // ========================================
                    // COORDINACIÓN CON SISTEMA CONSOLIDADO
                    // ========================================
                    // Variables para almacenar referencias de gráficos
                    let statusChart = null;
                    let trendChart = null;
                    let chartsInitialized = false;
                    
                    // Función para inicializar gráficas de forma coordinada
                    function initializeCharts() {
                        if (chartsInitialized) {
                            return;
                        }
                        
                        if (typeof Chart !== 'undefined') {
                            // Destruir gráficos existentes para evitar conflictos
                            const existingCharts = Chart.getChart('sync-status-chart');
                            if (existingCharts) {
                                existingCharts.destroy();
                            }
                            
                            const existingTrendChart = Chart.getChart('sync-trend-chart');
                            if (existingTrendChart) {
                                existingTrendChart.destroy();
                            }
                            
                            // Datos para las gráficas
                            const syncStatusData = {
                                labels: <?php echo json_encode($chart_data['sync_by_status']['labels']); ?>,
                                datasets: [{
                                    data: <?php echo json_encode($chart_data['sync_by_status']['data']); ?>,
                                    backgroundColor: [
                                        'rgba(34, 197, 94, 0.7)',
                                        'rgba(239, 68, 68, 0.7)',
                                        'rgba(245, 158, 11, 0.7)'
                                    ],
                                    borderColor: [
                                        'rgba(34, 197, 94, 1)',
                                        'rgba(239, 68, 68, 1)',
                                        'rgba(245, 158, 11, 1)'
                                    ],
                                    borderWidth: 1
                                }]
                            };
                            
                            const syncTrendData = {
                                labels: <?php echo json_encode($chart_data['sync_by_day']['labels']); ?>,
                                datasets: [
                                    {
                                        label: 'Sincronizados',
                                        data: <?php echo json_encode($chart_data['sync_by_day']['datasets'][0]['data']); ?>,
                                        backgroundColor: 'rgba(34, 197, 94, 0.2)',
                                        borderColor: 'rgba(34, 197, 94, 1)',
                                        tension: 0.3,
                                        borderWidth: 2,
                                        pointBackgroundColor: 'rgba(34, 197, 94, 1)',
                                        fill: true
                                    },
                                    {
                                        label: 'Errores',
                                        data: <?php echo json_encode($chart_data['sync_by_day']['datasets'][1]['data']); ?>,
                                        backgroundColor: 'rgba(239, 68, 68, 0.2)',
                                        borderColor: 'rgba(239, 68, 68, 1)',
                                        tension: 0.3,
                                        borderWidth: 2,
                                        pointBackgroundColor: 'rgba(239, 68, 68, 1)',
                                        fill: true
                                    }
                                ]
                        };
                        
                        // Opciones comunes
                        const commonOptions = {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        boxWidth: 12,
                                        usePointStyle: true
                                    }
                                },
                                tooltip: {
                                    backgroundColor: 'rgba(15, 23, 42, 0.8)',
                                    padding: 12,
                                    bodyFont: {
                                        size: 13
                                    },
                                    titleFont: {
                                        size: 14
                                    }
                                }
                            }
                        };
                        
                        // Gráfica de distribución de estados (doughnut)
                        statusChart = new Chart(
                            document.getElementById('sync-status-chart'),
                            {
                                type: 'doughnut',
                                data: syncStatusData,
                                options: {
                                    ...commonOptions,
                                    cutout: '65%',
                                    plugins: {
                                        ...commonOptions.plugins,
                                        legend: {
                                            ...commonOptions.plugins.legend,
                                            position: 'bottom'
                                        }
                                    }
                                }
                            }
                        );
                        
                        // Gráfica de tendencias (line)
                        trendChart = new Chart(
                            document.getElementById('sync-trend-chart'),
                            {
                                type: 'line',
                                data: syncTrendData,
                                options: {
                                    ...commonOptions,
                                    scales: {
                                        y: {
                                            beginAtZero: true,
                                            grid: {
                                                drawBorder: false
                                            },
                                            ticks: {
                                                precision: 0
                                            }
                                        },
                                        x: {
                                            grid: {
                                                display: false
                                            }
                                        }
                                    }
                                }
                            }
                        );
                        
                            chartsInitialized = true;
                        } else {
                            console.warn('[Order Sync Dashboard] Chart.js no está disponible. Las gráficas no se mostrarán.');
                            $('.chart-container canvas').each(function() {
                                $(this).after('<div class="chart-error">No se pueden mostrar las gráficas. Falta la biblioteca Chart.js</div>');
                            });
                        }
                    }
                
                // ========================================
                // COORDINACIÓN CON SISTEMA CONSOLIDADO
                // ========================================
                // ========================================
                // SISTEMA DE CONTROL DE ORDEN DE EJECUCIÓN
                // ========================================
                
                // Función de inicialización del sistema de gráficas
                function initializeOrderSyncDashboard() {
                    // Inicializar gráficas (coordinado o directo)
                    initializeCharts();
                }
                
                // Verificar si el sistema de eventos está disponible
                if (typeof window.SystemEventManager !== 'undefined') {
                    // Registrar el sistema de gráficas
                    window.SystemEventManager.registerSystem('order-sync-dashboard', ['UnifiedDashboard'], function() {
                        initializeOrderSyncDashboard();
                    });
                    
                    // Intentar inicializar inmediatamente si las dependencias ya están disponibles
                    if (window.SystemEventManager.checkDependencies('order-sync-dashboard')) {
                        initializeOrderSyncDashboard();
                    }
                } else {
                    // Fallback: inicializar directamente si el sistema de eventos no está disponible
                    initializeOrderSyncDashboard();
                }
                
                // Listener para el evento de UnifiedDashboard listo
                window.addEventListener('mi-unified-dashboard-ready', function(event) {
                    
                    if (typeof window.SystemEventManager !== 'undefined') {
                        window.SystemEventManager.initializeSystem('order-sync-dashboard');
                    }
                });
                
                }); // Cierre del jQuery(document).ready
                </script>
            </div>
            */ ?>
        </div>
        
        <!-- Modal de Vista Previa del Pedido -->
        <div id="order-preview-modal" class="mi-modal" style="display: none;">
            <div class="mi-modal-overlay"></div>
            <div class="mi-modal-content mi-modal-large">
                <div class="mi-modal-header">
                    <h2>
                        <span class="dashicons dashicons-visibility"></span>
                        Vista Previa del Pedido
                    </h2>
                    <button class="mi-modal-close" id="close-preview-modal">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
                
                <div class="mi-modal-body">
                    <!-- Información del Pedido -->
                    <div class="order-preview-info">
                        <div class="info-grid">
                            <div class="info-item">
                                <strong>Pedido:</strong>
                                <span id="preview-order-number">-</span>
                            </div>
                            <div class="info-item">
                                <strong>Cliente:</strong>
                                <span id="preview-customer">-</span>
                            </div>
                            <div class="info-item">
                                <strong>Total:</strong>
                                <span id="preview-total">-</span>
                            </div>
                            <div class="info-item">
                                <strong>Estado:</strong>
                                <span id="preview-status">-</span>
                            </div>
                            <div class="info-item">
                                <strong>Fecha:</strong>
                                <span id="preview-date">-</span>
                            </div>
                            <div class="info-item">
                                <strong>Completitud:</strong>
                                <span id="preview-completeness">-</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tabs -->
                    <div class="preview-tabs">
                        <button class="preview-tab active" data-tab="json">
                            <span class="dashicons dashicons-media-code"></span>
                            JSON Formateado
                        </button>
                        <button class="preview-tab" data-tab="structure">
                            <span class="dashicons dashicons-list-view"></span>
                            Estructura
                        </button>
                        <button class="preview-tab" data-tab="validation">
                            <span class="dashicons dashicons-yes-alt"></span>
                            Validación
                        </button>
                    </div>
                    
                    <!-- Contenido de los tabs -->
                    <div class="preview-tab-content">
                        <!-- Tab JSON -->
                        <div class="preview-tab-panel active" id="tab-json">
                            <div class="json-toolbar">
                                <button class="button button-small" id="copy-json">
                                    <span class="dashicons dashicons-clipboard"></span>
                                    Copiar JSON
                                </button>
                                <button class="button button-small" id="download-json">
                                    <span class="dashicons dashicons-download"></span>
                                    Descargar JSON
                                </button>
                                <span class="json-size">Tamaño: <span id="json-size">0</span> bytes</span>
                            </div>
                            <pre id="json-preview" class="json-code"><code></code></pre>
                        </div>
                        
                        <!-- Tab Estructura -->
                        <div class="preview-tab-panel" id="tab-structure">
                            <div id="structure-preview" class="structure-tree"></div>
                        </div>
                        
                        <!-- Tab Validación -->
                        <div class="preview-tab-panel" id="tab-validation">
                            <div id="validation-preview" class="validation-results"></div>
                        </div>
                    </div>
                </div>
                
                <div class="mi-modal-footer">
                    <button class="button" id="close-preview-btn">Cerrar</button>
                    <button class="button button-primary" id="sync-from-preview">
                        <span class="dashicons dashicons-update"></span>
                        Sincronizar Ahora
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * @return array Estadísticas de sincronización
     */
    private function get_order_sync_stats() {
        // ✅ DELEGADO: Intentar obtener stats desde SyncMetrics
        if (class_exists('\\MiIntegracionApi\\Core\\SyncMetrics')) {
            try {
                $syncMetrics = new \MiIntegracionApi\Core\SyncMetrics();
                $summaryMetrics = $syncMetrics->getSummaryMetrics();
                
                if (!empty($summaryMetrics)) {
                    return [
                        'total' => $summaryMetrics['totalOperations'] ?? 0,
                        'synced' => $summaryMetrics['successfulOperations'] ?? 0,
                        'failed' => $summaryMetrics['failedOperations'] ?? 0,
                        'pending' => max(0, ($summaryMetrics['totalOperations'] ?? 0) - ($summaryMetrics['successfulOperations'] ?? 0) - ($summaryMetrics['failedOperations'] ?? 0))
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->warning('Error obteniendo stats desde SyncMetrics, usando fallback', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // ✅ FALLBACK: Cálculo directo solo si SyncMetrics falla
        $stats = [
            'total' => 0,
            'synced' => 0,
            'failed' => 0,
            'pending' => 0
        ];
        
        if (!class_exists('WooCommerce')) {
            return $stats;
        }
        
        try {
            // ✅ ELIMINADO HARDCODEO: Límite configurable
            $limit = get_option('mia_dashboard_orders_limit', 100);
            
            $orders = wc_get_orders([
                'limit' => $limit, 
                'status' => get_option('mia_dashboard_order_statuses', ['processing', 'completed']),
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
            
            $stats['total'] = count($orders);
            
            foreach ($orders as $order) {
                if ($order->get_meta('_verial_documento_id') && $order->get_meta('_verial_sync_timestamp')) {
                    $stats['synced']++;
                } elseif ($order->get_meta('_verial_sync_error')) {
                    $stats['failed']++;
                } else {
                    $stats['pending']++;
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error al obtener estadísticas de sincronización', [
                'error' => $e->getMessage(),
                'component' => 'OrderSyncDashboard'
            ]);
        }
        
        return $stats;
    }
    
    /**
     * ✅ CENTRALIZADO: Calcula porcentaje de éxito de forma consistente.
     * 
     * @param int $numerator Numerador
     * @param int $denominator Denominador
     * @return int Porcentaje redondeado como entero (0-100)
     */
    private function calculateSuccessRate(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            return 0;
        }
        
        $precision = (int) get_option('mia_dashboard_percentage_precision', 0);
        return (int) $this->safe_round(($numerator / $denominator) * 100, $precision);
    }
    
    /**
     * Método helper para redondeo seguro usando Sync_Manager
     * 
     * @param float|int|null $num Número a redondear
     * @param int $precision Precisión decimal
     * @return float Número redondeado de forma segura
     */
    private function safe_round(float|int|null $num, int $precision = 0): float
    {
        $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
        return $sync_manager->safe_round($num, $precision);
    }
    
    /**
     * Obtiene datos para gráficas de estadísticas de sincronización
     * 
     * @return array Datos para gráficas
     */
    private function get_sync_chart_data() {
        // Valores predeterminados
        $chart_data = [
            'sync_by_day' => [],
            'sync_by_status' => [
                'synced' => 0,
                'failed' => 0,
                'pending' => 0
            ],
            'avg_sync_time' => 0
        ];
        
        // Verificar si WooCommerce está activo
        if (!class_exists('WooCommerce')) {
            return $chart_data;
        }
        
        try {
            // Obtener los últimos 7 días para la gráfica
            $days = 7;
            $days_labels = [];
            $days_data = [
                'synced' => [],
                'failed' => []
            ];
            
            // Generar etiquetas de días
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $days_labels[] = date_i18n('d M', strtotime($date));
                $days_data['synced'][] = 0;
                $days_data['failed'][] = 0;
            }
            
            // Inicializar contadores totales
            $total_synced = 0;
            $total_failed = 0;
            $total_pending = 0;
            $total_sync_time = 0;
            $sync_count = 0;
            
            // Obtener pedidos recientes
            $orders = wc_get_orders([
                'limit' => 100,
                'status' => ['processing', 'completed'],
                'orderby' => 'date',
                'order' => 'DESC',
                'date_created' => '>' . date('Y-m-d', strtotime("-$days days"))
            ]);
            
            // Procesar datos de los pedidos
            foreach ($orders as $order) {
                $is_synced = $order->get_meta('_verial_documento_id') && $order->get_meta('_verial_sync_timestamp');
                $has_error = $order->get_meta('_verial_sync_error');
                
                if ($is_synced) {
                    $total_synced++;
                    
                    // Calcular tiempo de sincronización si tenemos ambos timestamps
                    $created_timestamp = $order->get_date_created()->getTimestamp();
                    $sync_timestamp = (int)$order->get_meta('_verial_sync_timestamp');
                    
                    if ($sync_timestamp && $created_timestamp) {
                        $sync_time = $sync_timestamp - $created_timestamp;
                        if ($sync_time > 0 && $sync_time < 86400) { // Ignorar valores negativos o más de 24h
                            $total_sync_time += $sync_time;
                            $sync_count++;
                        }
                    }
                    
                    // Agrupar por día
                    $sync_date = date('Y-m-d', $sync_timestamp);
                    $days_ago = (strtotime('today') - strtotime($sync_date)) / DAY_IN_SECONDS;
                    
                    if ($days_ago >= 0 && $days_ago < $days) {
                        $index = $days - 1 - intval($days_ago);
                        if (isset($days_data['synced'][$index])) {
                            $days_data['synced'][$index]++;
                        }
                    }
                    
                } elseif ($has_error) {
                    $total_failed++;
                    
                    // Agrupar errores por día
                    $order_date = $order->get_date_created()->date('Y-m-d');
                    $days_ago = (strtotime('today') - strtotime($order_date)) / DAY_IN_SECONDS;
                    
                    if ($days_ago >= 0 && $days_ago < $days) {
                        $index = $days - 1 - intval($days_ago);
                        if (isset($days_data['failed'][$index])) {
                            $days_data['failed'][$index]++;
                        }
                    }
                    
                } else {
                    $total_pending++;
                }
            }
            
            // Calcular tiempo promedio de sincronización
            $avg_sync_time = $sync_count > 0 ? $this->safe_round($total_sync_time / $sync_count) : 0;
            
            // Preparar datos para la gráfica
            $chart_data = [
                'sync_by_day' => [
                    'labels' => $days_labels,
                    'datasets' => [
                        [
                            'label' => 'Sincronizados',
                            'data' => $days_data['synced']
                        ],
                        [
                            'label' => 'Errores',
                            'data' => $days_data['failed']
                        ]
                    ]
                ],
                'sync_by_status' => [
                    'labels' => ['Sincronizados', 'Con Errores', 'Pendientes'],
                    'data' => [$total_synced, $total_failed, $total_pending]
                ],
                'avg_sync_time' => $avg_sync_time
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Error al generar datos de gráficas: ' . $e->getMessage());
        }
        
        return $chart_data;
    }
    
    /**
     * Manejador AJAX para obtener el estado de sincronización de pedidos
     * con soporte para paginación, filtros y búsqueda
     * 
     * @return void Envía respuesta JSON
     */
    public function ajax_get_orders_sync_status() {
        
        // Verificar que WooCommerce esté disponible
        if (!class_exists('WooCommerce') || !function_exists('wc_get_orders')) {
            wp_send_json_error(['message' => 'WooCommerce no está disponible']);
            return;
        }
        
        // Verificar nonce y capacidades
        $nonce_value = isset($_POST['nonce']) ? $_POST['nonce'] : 'NO_ENVIADO';
        $nonce_action = defined('MiIntegracionApi_NONCE_PREFIX') ? MiIntegracionApi_NONCE_PREFIX . 'dashboard' : 'mi_integracion_api_nonce_dashboard';
        $nonce_valid = wp_verify_nonce($nonce_value, $nonce_action);
        $user_can = current_user_can('manage_options');
        
        
        if (!$nonce_valid || !$user_can) {
            wp_send_json_error(['message' => 'No tienes permisos para realizar esta acción']);
            return;
        }
        
        try {
            // Obtener parámetros de la solicitud
            $filter = isset($_POST['filter']) ? sanitize_text_field($_POST['filter']) : 'all';
            $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
            $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
            $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 10;
            
            // Validar parámetros
            $page = max(1, $page);
            $per_page = max(5, min(50, $per_page));
            
            // Calcular offset para paginación
            $offset = ($page - 1) * $per_page;
            
            // Parámetros base de consulta
            $query_args = [
                'limit' => $per_page,
                'offset' => $offset,
                'status' => ['processing', 'completed'],
                'orderby' => 'date',
                'order' => 'DESC',
            ];
            
            // Aplicar búsqueda si existe
            if (!empty($search)) {
                if (is_numeric($search)) {
                    // Si es numérico, buscar por ID de pedido
                    $query_args['p'] = absint($search);
                } else {
                    // Si no, buscar por nombre de cliente
                    $query_args['customer'] = $search;
                }
            }
            
            // Para obtener el conteo total necesitamos una consulta separada
            $count_args = [
                'limit' => -1,
                'status' => ['processing', 'completed'],
                'return' => 'ids',
            ];
            
            if (!empty($search)) {
                if (is_numeric($search)) {
                    $count_args['p'] = absint($search);
                } else {
                    $count_args['customer'] = $search;
                }
            }
            
            // Obtener total de pedidos para la paginación
            $all_order_ids = wc_get_orders($count_args);
            $total_orders = count($all_order_ids);
            
            // Calcular total de páginas
            $total_pages = ceil($total_orders / $per_page);
            
            // Obtener pedidos para la página actual
            $orders = wc_get_orders($query_args);
            
            // Inicializar contadores
            $data = [
                'total' => 0,
                'synced' => 0,
                'failed' => 0,
                'pending' => 0,
                'orders' => [],
                'page' => $page,
                'per_page' => $per_page,
                'total_orders' => $total_orders,
                'total_pages' => $total_pages
            ];
            
            // Array para almacenar IDs de pedidos a filtrar por estado de sincronización
            $filtered_order_ids = [];
            
            // Preparar datos para la tabla y calcular estadísticas
            foreach ($orders as $order) {
                $order_id = $order->get_id();
                $is_synced = $order->get_meta('_verial_documento_id') && $order->get_meta('_verial_sync_timestamp');
                $has_error = $order->get_meta('_verial_sync_error');
                
                $sync_status = $is_synced ? 'synced' : ($has_error ? 'failed' : 'pending');
                
                // Aplicar filtro por estado si no es 'all'
                if ($filter !== 'all' && $sync_status !== $filter) {
                    continue;
                }
                
                // Actualizar contadores
                $data['total']++;
                if ($is_synced) {
                    $data['synced']++;
                } elseif ($has_error) {
                    $data['failed']++;
                } else {
                    $data['pending']++;
                }
                
                // Datos del pedido para la tabla
                $order_data = [
                    'order_id' => $order_id,
                    'edit_url' => admin_url('post.php?post=' . $order_id . '&action=edit'),
                    'customer_name' => $order->get_formatted_billing_full_name(),
                    'date_created' => $order->get_date_created()->date_i18n(get_option('date_format') . ' ' . get_option('time_format')),
                    'sync_status' => $sync_status,
                    'error_message' => esc_html($order->get_meta('_verial_sync_error')),
                    'verial_id' => $order->get_meta('_verial_documento_id'),
                    'sync_timestamp' => $order->get_meta('_verial_sync_timestamp'),
                ];
                
                $data['orders'][] = $order_data;
                $filtered_order_ids[] = $order_id;
            }
            
            // Si estamos filtrando, necesitamos recalcular las estadísticas generales para los filtros
            if ($filter !== 'all' || !empty($search)) {
                // Obtener estadísticas globales de todos los pedidos
                $global_stats = $this->get_order_sync_stats();
                
                // Mantener los conteos globales pero actualizar el total filtrado
                $data['all_total'] = $global_stats['total'];
                $data['all_synced'] = $global_stats['synced'];
                $data['all_failed'] = $global_stats['failed'];
                $data['all_pending'] = $global_stats['pending'];
            }
            
            wp_send_json_success($data);
            
        } catch (\Exception $e) {
            
            // Usar logger solo si está disponible
            if ($this->logger) {
                $this->logger->error('Error al obtener datos de sincronización: ' . $e->getMessage());
            }
            
            wp_send_json_error(['message' => 'Error al obtener datos de sincronización: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Manejador AJAX para sincronizar un pedido individual
     * 
     * @return void Envía respuesta JSON
     */
    public function ajax_sync_single_order() {
        // Verificar nonce y capacidades
        $nonce_value = isset($_POST['nonce']) ? $_POST['nonce'] : 'NO_ENVIADO';
        $nonce_action = defined('MiIntegracionApi_NONCE_PREFIX') ? MiIntegracionApi_NONCE_PREFIX . 'dashboard' : 'mi_integracion_api_nonce_dashboard';
        $nonce_valid = wp_verify_nonce($nonce_value, $nonce_action);
        $user_can = current_user_can('manage_options');
        
        if (!$nonce_valid || !$user_can) {
            wp_send_json_error(['message' => 'No tienes permisos para realizar esta acción']);
            return;
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(['message' => 'ID de pedido no válido']);
            return;
        }
        
        try {
            $order = wc_get_order($order_id);
            
            if (!$order) {
                wp_send_json_error(['message' => 'Pedido no encontrado']);
                return;
            }
            
            // Limpiar errores anteriores
            $order->delete_meta_data('_verial_sync_error');
            $order->save();
            
            // Obtener instancia del OrderManager y sincronizar el pedido
            $order_manager = OrderManager::get_instance();
            $result = $order_manager->sync_order_to_verial($order);
            
            if ($result) {
                // Devolver datos actualizados del pedido
                wp_send_json_success([
                    'order_id' => $order_id,
                    'sync_status' => 'synced',
                    'verial_id' => $order->get_meta('_verial_documento_id'),
                    'sync_timestamp' => $order->get_meta('_verial_sync_timestamp'),
                ]);
            } else {
                wp_send_json_success([
                    'order_id' => $order_id,
                    'sync_status' => 'failed',
                    'error_message' => esc_html($order->get_meta('_verial_sync_error') ?: 'Error desconocido al sincronizar el pedido')
                ]);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error al sincronizar pedido #' . $order_id . ': ' . $e->getMessage());
            wp_send_json_error(['message' => 'Error al sincronizar pedido: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Manejador AJAX para sincronizar todos los pedidos
     * 
     * @return void Envía respuesta JSON
     */
    public function ajax_sync_all_orders() {
        // Verificar nonce y capacidades
        if (!wp_verify_nonce($_POST['nonce'], MiIntegracionApi_NONCE_PREFIX . 'dashboard') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No tienes permisos para realizar esta acción']);
            return;
        }
        
        try {
            // Obtener todos los pedidos pendientes de sincronización
            $orders = wc_get_orders([
                'limit' => -1,
                'status' => ['wc-processing', 'wc-completed'],
                'meta_query' => [
                    [
                        'key' => '_verial_sync_status',
                        'value' => 'pending',
                        'compare' => '='
                    ]
                ]
            ]);
            
            $synced_count = 0;
            $failed_count = 0;
            $errors = [];
            
            foreach ($orders as $order) {
                try {
                    $order_manager = OrderManager::get_instance();
                    $result = $order_manager->sync_order_to_verial($order);
                    
                    if ($result) {
                        $synced_count++;
                    } else {
                        $failed_count++;
                        $errors[] = 'Pedido #' . $order->get_id() . ': ' . ($order->get_meta('_verial_sync_error') ?: 'Error desconocido');
                    }
                } catch (\Exception $e) {
                    $failed_count++;
                    $errors[] = 'Pedido #' . $order->get_id() . ': ' . $e->getMessage();
                }
            }
            
            wp_send_json_success([
                'message' => "Sincronización completada. Exitosos: {$synced_count}, Fallidos: {$failed_count}",
                'synced_count' => $synced_count,
                'failed_count' => $failed_count,
                'errors' => $errors
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Error en sincronización masiva: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Error en sincronización masiva: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Manejador AJAX para reparar todos los pedidos con errores
     * 
     * @return void Envía respuesta JSON
     */
    public function ajax_fix_all_failed_orders() {
        // Verificar nonce y capacidades
        if (!wp_verify_nonce($_POST['nonce'], MiIntegracionApi_NONCE_PREFIX . 'dashboard') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No tienes permisos para realizar esta acción']);
            return;
        }
        
        // Iniciar proceso en segundo plano
        $sync_manager = Sync_Manager::get_instance();
        
        // Obtener IDs de pedidos con errores
        $failed_order_ids = $this->get_failed_order_ids();
        
        if (empty($failed_order_ids)) {
            wp_send_json_success(['message' => 'No hay pedidos con errores para reparar']);
            return;
        }
        
        $result = $sync_manager->retry_sync_errors($failed_order_ids);
        
        // Usar WordPressAdapter para enviar respuesta AJAX unificada
        \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::sendAjaxResponse($result);
    }
    
    /**
     * Registra los assets CSS y JS específicos para el dashboard de órdenes
     * 
     * @param string $hook Página actual
     * @return void
     */
    public function register_assets($hook) {
        // Solo cargar en la página de sincronización de pedidos
        if (strpos($hook, 'page_mi-integracion-api-order-sync') === false) {
            return;
        }

        // Cargar Font Awesome para los iconos
        wp_enqueue_style(
            'font-awesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css',
            array(),
            '6.0.0'
        );

        // Cargar el CSS del sidebar unificado primero
        wp_enqueue_style(
            'mi-integracion-api-unified-sidebar',
            MiIntegracionApi_PLUGIN_URL . 'assets/css/unified-sidebar.css',
            array('font-awesome'),
            constant('MiIntegracionApi_VERSION')
        );
        
        // Cargar el CSS moderno
        wp_enqueue_style(
            'mi-order-sync-dashboard-modern',
            MiIntegracionApi_PLUGIN_URL . 'assets/css/order-sync-dashboard-modern.css',
            array('mi-integracion-api-unified-sidebar'),
            constant('MiIntegracionApi_VERSION')
        );
        
        // Cargar CSS del modal de vista previa
        wp_enqueue_style(
            'mi-order-preview-modal-css',
            MiIntegracionApi_PLUGIN_URL . 'assets/css/order-preview-modal.css',
            array('mi-order-sync-dashboard-modern'),
            constant('MiIntegracionApi_VERSION') . '-preview'
        );
        
        // CSS adicional no necesario - el CSS principal ya se carga correctamente
        
        // Registrar utilidades modernas
        wp_enqueue_script(
            'mi-integracion-api-modern-utils',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/modern-utils.js',
            array('jquery'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar Chart.js para las gráficas
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
            array('jquery'),
            '3.9.1',
            true
        );
        
        // Registrar jQuery UI Dialog para los modales
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');
        
        // Cargar JavaScript del sidebar unificado para funcionalidad de temas
        wp_enqueue_script(
            'mi-integracion-api-unified-sidebar',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/unified-sidebar.js',
            array('jquery'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar constants.js primero (no tiene dependencias)
        wp_register_script(
            'mi-integracion-api-constants',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/config/constants.js',
            array(),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar messages.js (depende de constants, opcional)
        wp_register_script(
            'mi-integracion-api-messages',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/config/messages.js',
            array('mi-integracion-api-constants'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar dashboard-config.js (depende de constants y messages)
        wp_register_script(
            'mi-integracion-api-dashboard-config',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/config/dashboard-config.js',
            array('jquery', 'mi-integracion-api-constants', 'mi-integracion-api-messages'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar DomUtils.js (depende de dashboard-config y constants)
        wp_register_script(
            'mi-integracion-api-dom-utils',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/utils/DomUtils.js',
            array('jquery', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar PollingManager.js (depende de constants y dashboard-config)
        wp_register_script(
            'mi-integracion-api-polling-manager',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/managers/PollingManager.js',
            array('jquery', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar SyncStateManager.js (depende de polling-manager)
        wp_register_script(
            'mi-integracion-api-sync-state-manager',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/managers/SyncStateManager.js',
            array('jquery', 'mi-integracion-api-polling-manager', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar NonceManager.js (depende de jquery)
        wp_register_script(
            'mi-integracion-api-nonce-manager',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/managers/NonceManager.js',
            array('jquery'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar ToastManager.js (depende de jquery y dashboard-config)
        wp_register_script(
            'mi-integracion-api-toast-manager',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/components/ToastManager.js',
            array('jquery', 'mi-integracion-api-dashboard-config'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar ProgressBar.js (depende de jquery y dom-utils)
        wp_register_script(
            'mi-integracion-api-progress-bar',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/components/ProgressBar.js',
            array('jquery', 'mi-integracion-api-dom-utils'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar ConsoleManager.js (depende de jquery)
        wp_register_script(
            'mi-integracion-api-console-manager',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/components/ConsoleManager.js',
            array('jquery'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar ErrorHandler.js como dependencia
        wp_register_script(
            'mi-integracion-api-error-handler',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/core/ErrorHandler.js',
            array('jquery', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar AjaxManager.js como dependencia (depende de ErrorHandler)
        wp_register_script(
            'mi-integracion-api-ajax-manager',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/core/AjaxManager.js',
            array('jquery', 'mi-integracion-api-error-handler', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar EventManager.js como dependencia (depende de ErrorHandler y AjaxManager)
        wp_register_script(
            'mi-integracion-api-event-manager',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/core/EventManager.js',
            array('jquery', 'mi-integracion-api-error-handler', 'mi-integracion-api-ajax-manager', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Registrar dashboard.js refactorizado
        wp_register_script(
            'mi-integracion-api-dashboard',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/dashboard.js',
            array('jquery', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config', 'mi-integracion-api-error-handler', 'mi-integracion-api-ajax-manager', 'mi-integracion-api-event-manager'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Encolar todos los módulos del dashboard
        wp_enqueue_script('mi-integracion-api-constants');
        wp_enqueue_script('mi-integracion-api-messages');
        wp_enqueue_script('mi-integracion-api-dashboard-config');
        wp_enqueue_script('mi-integracion-api-dom-utils');
        wp_enqueue_script('mi-integracion-api-polling-manager');
        wp_enqueue_script('mi-integracion-api-sync-state-manager');
        wp_enqueue_script('mi-integracion-api-nonce-manager');
        wp_enqueue_script('mi-integracion-api-toast-manager');
        wp_enqueue_script('mi-integracion-api-progress-bar');
        wp_enqueue_script('mi-integracion-api-console-manager');
        wp_enqueue_script('mi-integracion-api-error-handler');
        wp_enqueue_script('mi-integracion-api-ajax-manager');
        wp_enqueue_script('mi-integracion-api-event-manager');
        wp_enqueue_script('mi-integracion-api-dashboard');
        
        // Localizar script con datos de WordPress para el dashboard
        wp_localize_script('mi-integracion-api-dashboard', 'miIntegracionApiDashboard', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mi_integracion_api_nonce_dashboard'),
            'restUrl' => rest_url('mi-integracion-api/v1/'),
            'confirmSync' => __('¿Iniciar sincronización de productos? Esta acción puede tomar varios minutos.', 'mi-integracion-api'),
            'confirmCancel' => __('¿Seguro que deseas cancelar la sincronización?', 'mi-integracion-api'),
        ));
        
        // Cargar JavaScript específico para la página de sincronización de pedidos
        wp_enqueue_script(
            'mi-order-sync-dashboard-js',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/order-sync-dashboard.js',
            array('jquery', 'mi-integracion-api-dashboard'),
            constant('MiIntegracionApi_VERSION'),
            true
        );
        
        // Cargar JavaScript del modal de vista previa
        wp_enqueue_script(
            'mi-order-preview-modal-js',
            MiIntegracionApi_PLUGIN_URL . 'assets/js/order-preview-modal.js',
            array('jquery', 'mi-order-sync-dashboard-js'),
            constant('MiIntegracionApi_VERSION') . '-preview',
            true
        );
        
        // Localizar variables JavaScript para dashboard.js
        wp_localize_script('mi-integracion-api-dashboard', 'miIntegracionApiDashboard', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mi_integracion_api_nonce_dashboard'),
            'restUrl' => rest_url('mi-integracion-api/v1/'),
            'timeoutConfig' => [
                'ui' => [
                    'default' => 2000,
                    'long' => 5000,
                    'short' => 1000,
                    'ajax' => 60000,
                    'connection' => 60000
                ]
            ],
            'limitsConfig' => [
                'ui' => [
                    'historyLimit' => 10,
                    'progressMilestones' => [25, 50, 75, 100]
                ]
            ],
            'pollingConfig' => [
                'intervals' => [
                    'normal' => 30000,
                    'active' => 5000,
                    'fast' => 2000
                ],
                'thresholds' => [
                    'toSlow' => 3,
                    'maxErrors' => 5
                ]
            ],
            'confirmSync' => __('¿Iniciar sincronización de productos? Esta acción puede tomar varios minutos.', 'mi-integracion-api'),
            'confirmCancel' => __('¿Seguro que deseas cancelar la sincronización?', 'mi-integracion-api'),
            'debug' => false
        ]);
        
        // Localizar variables JavaScript para la página de sincronización de pedidos
        wp_localize_script('mi-order-sync-dashboard-js', 'miIntegracionApiDashboard', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mi_integracion_api_nonce_dashboard'),
            'restUrl' => rest_url('mi-integracion-api/v1/'),
            'i18n' => [
                'error' => __('Ha ocurrido un error', 'mi-integracion-api'),
                'success' => __('Operación realizada con éxito', 'mi-integracion-api'),
                'loading' => __('Cargando...', 'mi-integracion-api'),
                'noOrders' => __('No se encontraron pedidos', 'mi-integracion-api'),
                'syncConfirm' => __('¿Estás seguro de que deseas sincronizar todos los pedidos?', 'mi-integracion-api'),
                'syncSuccess' => __('Pedido sincronizado correctamente', 'mi-integracion-api'),
                'syncError' => __('Error al sincronizar pedido', 'mi-integracion-api'),
            ]
        ]);
    }
    
    // Métodos get_order_dashboard_css() y get_order_dashboard_js() eliminados
    // porque no eran necesarios - el CSS y JS principales ya se cargan correctamente
    
    /**
     * Obtiene los IDs de pedidos que tienen errores de sincronización
     * 
     * @return array Array de IDs de pedidos con errores
     */
    private function get_failed_order_ids() {
        $failed_orders = [];
        
        if (!class_exists('WooCommerce')) {
            return $failed_orders;
        }
        
        try {
            $orders = wc_get_orders([
                'limit' => 100,
                'status' => ['processing', 'completed'],
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
            
            foreach ($orders as $order) {
                $has_error = $order->get_meta('_verial_sync_error');
                $is_synced = $order->get_meta('_verial_documento_id') && $order->get_meta('_verial_sync_timestamp');
                
                if ($has_error && !$is_synced) {
                    $failed_orders[] = $order->get_id();
                }
            }
            
        } catch (\Exception $e) {
        }
        
        return $failed_orders;
    }
    
    /**
     * Genera vista previa del JSON que se enviará a Verial para un pedido
     * 
     * @return void
     */
    public function ajax_preview_order_json() {
        // Habilitar logging de errores
        error_log('DEBUG: ajax_preview_order_json iniciado');
        
        try {
            // Verificar nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
                error_log('ERROR: Nonce inválido en ajax_preview_order_json');
                wp_send_json_error(['message' => 'Nonce inválido']);
                return;
            }
            
            // Verificar permisos
            if (!current_user_can('manage_woocommerce')) {
                error_log('ERROR: Usuario sin permisos en ajax_preview_order_json');
                wp_send_json_error(['message' => 'No tienes permisos para realizar esta acción']);
                return;
            }
            
            // Obtener ID del pedido
            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
            error_log('DEBUG: Order ID recibido: ' . $order_id);
            
            if (!$order_id) {
                error_log('ERROR: ID de pedido no válido');
                wp_send_json_error(['message' => 'ID de pedido no válido']);
                return;
            }
            
            // Verificar que WooCommerce esté disponible
            if (!function_exists('wc_get_order')) {
                error_log('ERROR: WooCommerce no está disponible');
                wp_send_json_error(['message' => 'WooCommerce no está disponible']);
                return;
            }
            
            // Obtener el pedido de WooCommerce
            $wc_order = wc_get_order($order_id);
            error_log('DEBUG: Pedido obtenido: ' . ($wc_order ? 'SI' : 'NO'));
            
            if (!$wc_order) {
                error_log('ERROR: Pedido no encontrado - ID: ' . $order_id);
                wp_send_json_error(['message' => 'Pedido no encontrado']);
                return;
            }
            
            // Verificar que MapOrder esté disponible
            if (!class_exists('\\MiIntegracionApi\\Helpers\\MapOrder')) {
                error_log('ERROR: Clase MapOrder no encontrada');
                wp_send_json_error(['message' => 'Clase MapOrder no disponible']);
                return;
            }
            
            // Generar el payload de Verial usando MapOrder
            error_log('DEBUG: Generando payload con MapOrder::wc_to_verial()');
            $verial_response = \MiIntegracionApi\Helpers\MapOrder::wc_to_verial($wc_order);
            error_log('DEBUG: Respuesta generada: ' . ($verial_response instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface ? 'SyncResponse' : 'Otro tipo'));
            
            // Si hay error en la generación
            if (!$verial_response->isSuccess()) {
                $error = $verial_response->getError();
                error_log('ERROR: Error en payload - ' . ($error ? $error->getMessage() : 'Error desconocido'));
                wp_send_json_error([
                    'message' => 'Error al generar el payload',
                    'error' => $error ? $error->getMessage() : 'Error desconocido',
                    'error_code' => $verial_response->getErrorCode(),
                    'details' => $verial_response->toArray()
                ]);
                return;
            }
            
            // Obtener los datos del payload
            $verial_payload = $verial_response->getData();
            
            // Obtener información adicional del pedido
            $order_info = [
                'id' => $wc_order->get_id(),
                'number' => $wc_order->get_order_number(),
                'status' => $wc_order->get_status(),
                'date' => $wc_order->get_date_created()->format('Y-m-d H:i:s'),
                'customer' => $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name(),
                'email' => $wc_order->get_billing_email(),
                'total' => $wc_order->get_total(),
                'currency' => $wc_order->get_currency(),
                'items_count' => $wc_order->get_item_count(),
                'payment_method' => $wc_order->get_payment_method_title(),
                'shipping_method' => $wc_order->get_shipping_method()
            ];
            
            // Obtener información de sincronización si existe
            $sync_info = [
                'synced' => (bool) $wc_order->get_meta('_verial_sync_timestamp'),
                'verial_id' => $wc_order->get_meta('_verial_documento_id'),
                'sync_timestamp' => $wc_order->get_meta('_verial_sync_timestamp'),
                'sync_error' => $wc_order->get_meta('_verial_sync_error'),
                'completeness_score' => $wc_order->get_meta('_verial_completeness_score')
            ];
            
            error_log('DEBUG: Enviando respuesta exitosa');
            wp_send_json_success([
                'order_info' => $order_info,
                'sync_info' => $sync_info,
                'verial_payload' => $verial_payload,
                'json_pretty' => json_encode($verial_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                'json_size' => strlen(json_encode($verial_payload))
            ]);
            
        } catch (\Throwable $e) {
            error_log('ERROR CRÍTICO en ajax_preview_order_json: ' . $e->getMessage());
            error_log('ERROR Trace: ' . $e->getTraceAsString());
            wp_send_json_error([
                'message' => 'Error al generar vista previa',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Endpoint de prueba para verificar que AJAX funciona
     */
    public function ajax_test_connection() {
        
        // Verificar nonce básico
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], MiIntegracionApi_NONCE_PREFIX . 'dashboard')) {
            wp_send_json_error(['message' => 'Nonce inválido']);
            return;
        }
        
        wp_send_json_success([
            'message' => 'Conexión AJAX funcionando correctamente',
            'timestamp' => current_time('mysql'),
            'woocommerce_available' => class_exists('WooCommerce'),
            'user_can_manage_woocommerce' => current_user_can('manage_options')
        ]);
    }
}

// Inicializar
OrderSyncDashboard::get_instance();
