<?php
/**
 * Clase para manejar el Dashboard de Detección Automática
 * 
 * @package MiIntegracionApi
 * @subpackage Admin
 */

namespace MiIntegracionApi\Admin;

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Core\Sync_Manager;
use MiIntegracionApi\Helpers\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class DetectionDashboard
{
    /**
     * Instancia única de la clase
     */
    private static $instance = null;
    
    /**
     * Logger para el dashboard
     */
    private $logger;
    
    /**
     * Constructor privado para patrón Singleton
     */
    private function __construct()
    {
        $this->logger = new Logger('detection_dashboard');
        
        // Solo inicializar si estamos en WordPress
        if (function_exists('add_action')) {
            $this->init();
        } else {
            $this->logger->warning('DetectionDashboard: No se puede inicializar fuera del entorno de WordPress');
        }
    }
    
    /**
     * Obtener instancia única
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Inicializar el dashboard
     */
    private function init(): void
    {
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('wp_ajax_mia_toggle_detection', [$this, 'handleToggleDetection']);
        add_action('wp_ajax_mia_execute_detection', [$this, 'handleExecuteDetection']);
        add_action('wp_ajax_mia_get_detection_status', [$this, 'handleGetStatus']);
        add_action('wp_ajax_mia_save_detection_config', [$this, 'handleSaveConfig']);
        add_action('wp_ajax_mia_get_detection_sync_progress', [$this, 'handleGetSyncProgress']);
        add_action('wp_ajax_mia_get_detection_stats', [$this, 'handleGetDetectionStats']);
        
        // Manejadores AJAX para notificaciones
        add_action('wp_ajax_mia_get_notifications', [$this, 'handleGetNotifications']);
        add_action('wp_ajax_mia_mark_notification_read', [$this, 'handleMarkNotificationRead']);
        add_action('wp_ajax_mia_archive_notification', [$this, 'handleArchiveNotification']);
        add_action('wp_ajax_mia_clear_all_notifications', [$this, 'handleClearAllNotifications']);
        add_action('wp_ajax_mia_get_notification_stats', [$this, 'handleGetNotificationStats']);
        
        // Manejadores AJAX para solicitudes de documentos
        add_action('wp_ajax_mia_get_document_requests', [$this, 'handleGetDocumentRequests']);
        add_action('wp_ajax_mia_create_document_request', [$this, 'handleCreateDocumentRequest']);
        add_action('wp_ajax_mia_update_document_status', [$this, 'handleUpdateDocumentStatus']);
        add_action('wp_ajax_mia_get_document_stats', [$this, 'handleGetDocumentStats']);
        add_action('wp_ajax_mia_cleanup_document_requests', [$this, 'handleCleanupDocumentRequests']);
        
        // Endpoints para solicitud de productos
        add_action('wp_ajax_mia_get_product_data', [$this, 'handleGetProductData']);
        add_action('wp_ajax_mia_get_verial_categories', [$this, 'handleGetVerialCategories']);
        add_action('wp_ajax_mia_get_verial_manufacturers', [$this, 'handleGetVerialManufacturers']);
        add_action('wp_ajax_mia_get_verial_configurable_fields', [$this, 'handleGetVerialConfigurableFields']);
        add_action('wp_ajax_mia_submit_product_request', [$this, 'handleSubmitProductRequest']);
        
        // Manejadores AJAX para configuración de notificaciones
        add_action('wp_ajax_mia_get_notification_config', [$this, 'handleGetNotificationConfig']);
        add_action('wp_ajax_mia_save_notification_config', [$this, 'handleSaveNotificationConfig']);
        add_action('wp_ajax_mia_reset_notification_config', [$this, 'handleResetNotificationConfig']);
        add_action('wp_ajax_mia_validate_notification_config', [$this, 'handleValidateNotificationConfig']);
        
        // Manejador AJAX para estado del sistema
        add_action('wp_ajax_mia_get_system_status', [$this, 'handleGetSystemStatus']);

        // Manejador AJAX para obtener productos
        add_action('wp_ajax_mia_get_detection_products', [$this, 'handleGetDetectionProducts']);
        
        // Hook para el cron job de detección automática
        add_action('mia_auto_detection_hook', [$this, 'executeAutoDetection']);
    }
    
    /**
     * Agregar menú de administración
     */
    public function addAdminMenu(): void
    {
        add_submenu_page(
            'mi-integracion-api',
            'Detección Automática',
            'Detección Automática',
            'manage_options',
            'mia-detection-dashboard',
            [$this, 'renderDashboard']
        );
    }
    
    /**
     * Cargar scripts y estilos
     */
public function enqueueScripts($hook): void
{
    if ($hook !== 'mi-integracion-api_page_mia-detection-dashboard') {
        return;
    }
    
    // Cargar Font Awesome para iconos
    wp_enqueue_style(
        'font-awesome',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css',
        array(),
        '6.0.0'
    );
    
    // Cargar design-system primero (variables CSS)
    wp_enqueue_style(
        'mi-integracion-api-design-system',
        plugin_dir_url(MiIntegracionApi_PLUGIN_FILE) . 'assets/css/design-system.css',
        array(),
        constant('MiIntegracionApi_VERSION')
    );
    
    // Cargar CSS específico del dashboard
    wp_enqueue_style(
        'mi-integracion-api-dashboard',
        plugin_dir_url(MiIntegracionApi_PLUGIN_FILE) . 'assets/css/dashboard.css',
        array('mi-integracion-api-design-system'),
        constant('MiIntegracionApi_VERSION')
    );

    // Cargar CSS del sidebar unificado
    wp_enqueue_style(
        'mi-integracion-api-unified-sidebar',
        plugin_dir_url(MiIntegracionApi_PLUGIN_FILE) . 'assets/css/unified-sidebar.css',
        array('mi-integracion-api-admin-dashboard'),
        constant('MiIntegracionApi_VERSION')
    );
    
    // Cargar CSS de componentes admin (incluye estilos de modales reutilizados)
    wp_enqueue_style(
        'mi-integracion-api-admin-dashboard',
        plugin_dir_url(MiIntegracionApi_PLUGIN_FILE) . 'assets/css/admin-dashboard.css',
        array('mi-integracion-api-dashboard'),
        constant('MiIntegracionApi_VERSION')
    );
    
    // CSS específico para detección automática (adicional)
    wp_enqueue_style(
        'mia-detection-dashboard-css',
        plugin_dir_url(MiIntegracionApi_PLUGIN_FILE) . 'assets/css/detection-dashboard.css',
        array('mi-integracion-api-unified-sidebar'),
        constant('MiIntegracionApi_VERSION')
    );
    
    // Cargar JavaScript principal del dashboard
    wp_enqueue_script(
        'mia-detection-dashboard-js',
        plugin_dir_url(MiIntegracionApi_PLUGIN_FILE) . 'assets/js/admin-dashboard.js',
        ['jquery'],
        constant('MiIntegracionApi_VERSION'),
        true
    );

    // Cargar JavaScript para la lista de productos
    wp_enqueue_script(
        'mia-detection-products-js',
        plugin_dir_url(MiIntegracionApi_PLUGIN_FILE) . 'assets/js/detection-products.js',
        ['jquery'],
        constant('MiIntegracionApi_VERSION'),
        true
    );
    
    // Cargar JavaScript del sidebar unificado
    wp_enqueue_script(
        'mi-integracion-api-unified-sidebar',
        plugin_dir_url(MiIntegracionApi_PLUGIN_FILE) . 'assets/js/unified-sidebar.js',
        array('jquery'),
        constant('MiIntegracionApi_VERSION'),
        true
    );
    
    // Datos comunes para todos los scripts
    $localization_vars = [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('mia_detection_nonce'),
        'isActive' => get_option('mia_automatic_stock_detection_enabled', false),
        'lastSync' => get_option('mia_automatic_stock_last_sync', 0),
        'stats' => get_option('mia_detection_stats', [
            'total_synced' => 0,
            'avg_time' => 0,
            'accuracy' => 0
        ]),
        'i18n' => [
            'loading' => __('Cargando...', 'mi-integracion-api'),
            'error_loading' => __('Error al cargar los productos', 'mi-integracion-api'),
            'no_products' => __('No se encontraron productos', 'mi-integracion-api')
        ]
    ];
    
    // Localizar los scripts con las variables
    wp_localize_script('mia-detection-dashboard-js', 'miaDetectionData', $localization_vars);
    wp_localize_script('mia-detection-products-js', 'miaDetectionData', $localization_vars);
}

    /**
 * Manejador AJAX para obtener la lista de productos
 */
public function handleGetDetectionProducts(): void
{
    // Verificar nonce
    check_ajax_referer('mia_detection_nonce', 'nonce');

    // Verificar permisos
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('No tienes permisos suficientes.', 'mi-integracion-api')], 403);
        return;
    }

    $filter = sanitize_text_field($_POST['filter'] ?? 'all');
    $page = absint($_POST['page'] ?? 1);
    $per_page = 10; // Número de productos por página

    $args = [
        'post_type' => 'product',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'post_status' => 'publish',
        'orderby' => 'date',
        'order' => 'DESC',
    ];

    // Aplicar filtros
    if ($filter === 'new') {
        $args['date_query'] = [
            'after' => '30 days ago',
            'inclusive' => true,
        ];
    } elseif ($filter === 'errors') {
        $args['meta_query'] = [
            [
                'key' => '_mia_sync_error',
                'compare' => 'EXISTS',
            ],
        ];
    }

    $query = new \WP_Query($args);
    $products = [];

    // Log para debug
    error_log('DetectionDashboard: Query args: ' . print_r($args, true));
    error_log('DetectionDashboard: Found posts: ' . $query->found_posts);

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $product = wc_get_product(get_the_ID());
            
            if (!$product) {
                error_log('DetectionDashboard: Product not found for ID: ' . get_the_ID());
                continue;
            }

            $products[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'price' => $product->get_price(),
                'stock_quantity' => $product->get_stock_quantity(),
                'stock_status' => $product->get_stock_status(),
                'in_stock' => $product->is_in_stock(),
                'edit_link' => get_edit_post_link($product->get_id()),
                'view_link' => get_permalink($product->get_id()),
                'image' => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src(),
                'last_synced' => get_post_meta($product->get_id(), '_mia_last_sync', true),
                'has_error' => (bool) get_post_meta($product->get_id(), '_mia_sync_error', true),
                'error_message' => get_post_meta($product->get_id(), '_mia_sync_error', true),
            ];
        }
        wp_reset_postdata();
    }

    error_log('DetectionDashboard: Products found: ' . count($products));

    wp_send_json_success([
        'products' => $products,
        'total' => $query->found_posts,
        'pages' => $query->max_num_pages,
        'current_page' => $page,
        'per_page' => $per_page,
    ]);
}
    /**
     * Renderizar el dashboard
     */
    public function renderDashboard(): void
    {
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para acceder a esta página.', 'mi-integracion-api'));
        }
        
        // Incluir template
        include plugin_dir_path(MiIntegracionApi_PLUGIN_FILE) . 'templates/admin/detection-dashboard.php';
    }
    
    /**
     * Manejar toggle de detección
     */
    public function handleToggleDetection(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos');
        }
        
        $activate = $_POST['activate'] ?? null;
        $enabled = ($activate === '1');
        
        // Actualizar opción de detección automática
        update_option('mia_automatic_stock_detection_enabled', $enabled);
        update_option('mia_detection_auto_active', $enabled);
        
        // Si se activa, programar cron job
        if ($enabled) {
            $this->scheduleDetectionCron();
        } else {
            $this->unscheduleDetectionCron();
        }
        
        $this->logger->info('Estado de detección automática cambiado', [
            'enabled' => $enabled,
            'user_id' => get_current_user_id()
        ]);
        
        wp_send_json_success([
            'enabled' => $enabled,
            'message' => $enabled ? 'Detección activada' : 'Detección desactivada'
        ]);
    }
    
    /**
     * Manejar ejecución manual de detección
     */
    public function handleExecuteDetection(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos');
        }
        
        try {
            // Obtener instancia del detector
            $detector = \MiIntegracionApi\Deteccion\StockDetectorIntegration::getDetector();
            
            if (!$detector) {
                wp_send_json_error('Detector no disponible');
            }
            
            // Ejecutar detección manual
            $result = $detector->executeManualDetection();
            
            $this->logger->info('Detección manual ejecutada', [
                'result' => $result,
                'user_id' => get_current_user_id()
            ]);
            
            wp_send_json_success($result);
            
        } catch (\Exception $e) {
            $this->logger->error('Error en detección manual', [
                'error' => $e->getMessage(),
                'user_id' => get_current_user_id()
            ]);
            
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Obtener estado de la detección
     */
    public function handleGetStatus(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            $detector = \MiIntegracionApi\Deteccion\StockDetectorIntegration::getDetector();
            
            if (!$detector) {
                wp_send_json_error('Detector no disponible');
            }
            
            $status = $detector->getStatus();
            
            wp_send_json_success($status);
            
        } catch (\Exception $e) {
            $this->logger->error('Error obteniendo estado', [
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Guardar configuración
     */
    public function handleSaveConfig(): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            $sync_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
                'Nonce inválido',
                403,
                [
                    'endpoint' => 'DetectionDashboard::handleSaveConfig',
                    'error_code' => 'invalid_nonce',
                    'config_operation' => true,
                    'timestamp' => time()
                ]
            );
            
            // Convertir a formato AJAX de WordPress
            $response = $sync_response->toArray();
            wp_send_json_error($response, 403);
            return $sync_response;
        }
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            $sync_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
                'Sin permisos',
                403,
                [
                    'endpoint' => 'DetectionDashboard::handleSaveConfig',
                    'error_code' => 'insufficient_permissions',
                    'config_operation' => true,
                    'timestamp' => time()
                ]
            );
            
            // Convertir a formato AJAX de WordPress
            $response = $sync_response->toArray();
            wp_send_json_error($response, 403);
            return $sync_response;
        }
        
        try {
            $config = $_POST['config'] ?? [];
            
            // Validar y guardar configuración
            $this->saveConfiguration($config);
            
            $this->logger->info('Configuración de detección guardada', [
                'config' => $config,
                'user_id' => get_current_user_id()
            ]);
            
            $sync_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
                [
                    'message' => 'Configuración guardada correctamente',
                    'config' => $config,
                    'user_id' => get_current_user_id()
                ],
                'Configuración guardada correctamente',
                [
                    'endpoint' => 'DetectionDashboard::handleSaveConfig',
                    'config_operation' => true,
                    'user_id' => get_current_user_id(),
                    'timestamp' => time()
                ]
            );
            
            // Convertir a formato AJAX de WordPress
            $response = $sync_response->toArray();
            wp_send_json_success($response);
            return $sync_response;
            
        } catch (\Exception $e) {
            $this->logger->error('Error guardando configuración', [
                'error' => $e->getMessage(),
                'user_id' => get_current_user_id()
            ]);
            
            $sync_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
                'Error: ' . $e->getMessage(),
                500,
                [
                    'endpoint' => 'DetectionDashboard::handleSaveConfig',
                    'error_code' => 'config_save_error',
                    'config_operation' => true,
                    'exception_message' => $e->getMessage(),
                    'user_id' => get_current_user_id(),
                    'timestamp' => time()
                ]
            );
            
            // Convertir a formato AJAX de WordPress
            $response = $sync_response->toArray();
            wp_send_json_error($response, 500);
            return $sync_response;
        }
    }
    
    /**
     * Guardar configuración en la base de datos
     */
    private function saveConfiguration(array $config): void
    {
        // Configuración de detección automática
        if (isset($config['detection_interval'])) {
            update_option('mia_detection_interval', (int) $config['detection_interval']);
        }
        
        if (isset($config['product_limit'])) {
            update_option('mia_detection_product_limit', (int) $config['product_limit']);
        }
        
        if (isset($config['start_time'])) {
            update_option('mia_detection_start_time', sanitize_text_field($config['start_time']));
        }
        
        if (isset($config['end_time'])) {
            update_option('mia_detection_end_time', sanitize_text_field($config['end_time']));
        }
        
        // Configuración de notificaciones
        if (isset($config['notification_type'])) {
            update_option('mia_detection_notification_type', sanitize_text_field($config['notification_type']));
        }
        
        if (isset($config['low_stock_threshold'])) {
            update_option('mia_detection_low_stock_threshold', (int) $config['low_stock_threshold']);
        }
        
        // Configuración de rendimiento
        if (isset($config['api_timeout'])) {
            update_option('mia_detection_api_timeout', (int) $config['api_timeout']);
        }
        
        if (isset($config['retry_attempts'])) {
            update_option('mia_detection_retry_attempts', (int) $config['retry_attempts']);
        }
        
        if (isset($config['product_filters'])) {
            update_option('mia_detection_product_filters', sanitize_text_field($config['product_filters']));
        }
        
        // Configuración de seguridad
        if (isset($config['validation_level'])) {
            update_option('mia_detection_validation_level', sanitize_text_field($config['validation_level']));
        }
        
        if (isset($config['auto_backup'])) {
            update_option('mia_detection_auto_backup', (bool) $config['auto_backup']);
        }
    }
    
    /**
     * Obtener estadísticas de detección
     */
    public function getDetectionStats(): array
    {
        return [
            'enabled' => get_option('mia_automatic_stock_detection_enabled', false),
            'last_sync' => get_option('mia_automatic_stock_last_sync', 0),
            'total_synced' => get_option('mia_detection_total_synced', 0),
            'avg_time' => get_option('mia_detection_avg_time', 0),
            'accuracy' => get_option('mia_detection_accuracy', 0),
            'errors_count' => get_option('mia_detection_errors_count', 0),
            'last_error' => get_option('mia_detection_last_error', '')
        ];
    }
    
    /**
     * Obtener configuración actual
     */
    public function getCurrentConfig(): array
    {
        return [
            'detection_interval' => get_option('mia_detection_interval', 300),
            'product_limit' => get_option('mia_detection_product_limit', 100),
            'start_time' => get_option('mia_detection_start_time', '08:00'),
            'end_time' => get_option('mia_detection_end_time', '22:00'),
            'notification_type' => get_option('mia_detection_notification_type', 'all'),
            'low_stock_threshold' => get_option('mia_detection_low_stock_threshold', 10),
            'api_timeout' => get_option('mia_detection_api_timeout', 30),
            'retry_attempts' => get_option('mia_detection_retry_attempts', 3),
            'product_filters' => get_option('mia_detection_product_filters', ''),
            'validation_level' => get_option('mia_detection_validation_level', 'strict'),
            'auto_backup' => get_option('mia_detection_auto_backup', true)
        ];
    }
    
    /**
     * Actualizar estadísticas
     */
    public function updateStats(array $stats): void
    {
        if (isset($stats['total_synced'])) {
            update_option('mia_detection_total_synced', (int) $stats['total_synced']);
        }
        
        if (isset($stats['avg_time'])) {
            update_option('mia_detection_avg_time', (float) $stats['avg_time']);
        }
        
        if (isset($stats['accuracy'])) {
            update_option('mia_detection_accuracy', (float) $stats['accuracy']);
        }
        
        if (isset($stats['errors_count'])) {
            update_option('mia_detection_errors_count', (int) $stats['errors_count']);
        }
        
        if (isset($stats['last_error'])) {
            update_option('mia_detection_last_error', sanitize_text_field($stats['last_error']));
        }
    }
    
    /**
     * Manejar petición de progreso de sincronización
     */
    public function handleGetSyncProgress(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            // USAR SYNCSTATUSHELPER - LÓGICA CENTRALIZADA
            $sync_info = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
            
            if (!$sync_info['in_progress']) {
                wp_send_json_success([
                    'in_progress' => false,
                    'percentage' => 0,
                    'processed' => 0,
                    'total' => 0,
                    'time_remaining' => 0
                ]);
                return;
            }
            
            // Calcular porcentaje
            $percentage = 0;
            if ($sync_info['total_items'] > 0) {
                $percentage = min(99.9, max(1, round(($sync_info['items_synced'] / $sync_info['total_items']) * 100, 1)));
            }
            
            // Calcular tiempo restante estimado
            $time_remaining = 0;
            if ($sync_info['start_time'] > 0 && $sync_info['items_synced'] > 0) {
                $elapsed = time() - $sync_info['start_time'];
                $rate = $sync_info['items_synced'] / $elapsed;
                $remaining_items = $sync_info['total_items'] - $sync_info['items_synced'];
                $time_remaining = $remaining_items / $rate;
            }
            
            wp_send_json_success([
                'in_progress' => true,
                'percentage' => $percentage,
                'processed' => $sync_info['items_synced'] ?? 0,
                'total' => $sync_info['total_items'] ?? 0,
                'time_remaining' => round($time_remaining / 60) // en minutos
            ]);
            
        } catch (\Throwable $e) {
            error_log('Error en handleGetSyncProgress: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Error al obtener progreso de sincronización']);
        }
    }
    
    /**
     * Manejar petición de estadísticas de detección
     */
    public function handleGetDetectionStats(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        // Obtener estadísticas reales
        $last_sync = get_option('mia_automatic_stock_last_sync', 0);
        $total_synced = get_option('mia_detection_total_synced', 0);
        $avg_time = get_option('mia_detection_avg_time', '0s');
        $accuracy = get_option('mia_detection_accuracy', 0);
        
        // Calcular tiempo desde última ejecución
        $time_since_last = 'Nunca';
        if ($last_sync > 0) {
            $time_diff = time() - $last_sync;
            if ($time_diff < 60) {
                $time_since_last = $time_diff . 's';
            } elseif ($time_diff < 3600) {
                $time_since_last = floor($time_diff / 60) . ' min';
            } elseif ($time_diff < 86400) {
                $time_since_last = floor($time_diff / 3600) . 'h';
            } else {
                $time_since_last = floor($time_diff / 86400) . 'd';
            }
        }
        
        $stats_data = [
            'last_execution' => $time_since_last,
            'total_synced' => (int) $total_synced,
            'avg_time' => $avg_time,
            'accuracy' => (float) $accuracy
        ];
        
        wp_send_json_success($stats_data);
    }
    
    /**
     * Manejador AJAX para obtener notificaciones
     * @return void
     */
    public function handleGetNotifications(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            // Verificar que el notificador esté disponible
            if (!class_exists('MiIntegracionApi\\Deteccion\\WooCommerceProductNotifier')) {
                wp_send_json_error('Sistema de notificaciones no disponible');
                return;
            }
            
            $notifier = new \MiIntegracionApi\Deteccion\WooCommerceProductNotifier();
            
            // Obtener parámetros de filtrado
            $status = sanitize_text_field($_POST['status'] ?? 'all'); // all, unread, read, archived
            $type = sanitize_text_field($_POST['type'] ?? 'all'); // all, created, updated, deleted
            $limit = (int) ($_POST['limit'] ?? 20);
            $offset = (int) ($_POST['offset'] ?? 0);
            
            // Obtener notificaciones de WooCommerce
            $wc_notifications = $notifier->get_pending_notifications();
            
            // Obtener notificaciones del sistema de detección
            $detection_notifications = get_option('mia_detection_notifications', []);
            
            // Combinar todas las notificaciones
            $all_notifications = array_merge($wc_notifications, $detection_notifications);
            
            // Ordenar por fecha de creación (más recientes primero)
            usort($all_notifications, function($a, $b) {
                $date_a = $a['created_at'] ?? $a['timestamp'] ?? '1970-01-01 00:00:00';
                $date_b = $b['created_at'] ?? $b['timestamp'] ?? '1970-01-01 00:00:00';
                return strtotime($date_b) - strtotime($date_a);
            });
            
            // Aplicar filtros
            $filtered_notifications = array_filter($all_notifications, function($notification) use ($status, $type) {
                // Filtrar por estado
                if ($status !== 'all') {
                    $is_read = $notification['read'] ?? false;
                    if ($status === 'unread' && $is_read) return false;
                    if ($status === 'read' && !$is_read) return false;
                    if ($status === 'archived' && !($notification['archived'] ?? false)) return false;
                }
                
                // Filtrar por tipo
                if ($type !== 'all') {
                    if ($notification['type'] !== $type) return false;
                }
                
                return true;
            });
            
            // Aplicar paginación
            $notifications = array_slice($filtered_notifications, $offset, $limit);
            
            // Formatear notificaciones para el frontend
            $formatted_notifications = array_map(function($notification) {
                // Normalizar campos de fecha
                $timestamp = $notification['created_at'] ?? $notification['timestamp'] ?? current_time('mysql');
                
                // Normalizar campos de estado
                $read = $notification['read'] ?? false;
                $archived = $notification['archived'] ?? false;
                
                // Obtener datos adicionales
                $data = $notification['data'] ?? [];
                
                return [
                    'id' => $notification['id'] ?? uniqid('notif_', true),
                    'type' => $notification['type'],
                    'title' => $notification['title'] ?? 'Notificación',
                    'message' => $notification['message'] ?? '',
                    'timestamp' => $timestamp,
                    'read' => $read,
                    'archived' => $archived,
                    'priority' => $notification['priority'] ?? 'info',
                    'product_id' => $data['product_id'] ?? $notification['product_id'] ?? null,
                    'verial_id' => $data['verial_id'] ?? $notification['verial_id'] ?? null,
                    'actions' => $notification['actions'] ?? [],
                    'data' => $data
                ];
            }, $notifications);
            
            wp_send_json_success([
                'notifications' => $formatted_notifications,
                'total' => count($filtered_notifications),
                'has_more' => ($offset + $limit) < count($filtered_notifications)
            ]);
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al obtener notificaciones: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para marcar notificación como leída
     * @return void
     */
    public function handleMarkNotificationRead(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        $notification_id = sanitize_text_field($_POST['notification_id'] ?? '');
        if (empty($notification_id)) {
            wp_send_json_error('ID de notificación requerido');
            return;
        }
        
        try {
            if (!class_exists('MiIntegracionApi\\Deteccion\\WooCommerceProductNotifier')) {
                wp_send_json_error('Sistema de notificaciones no disponible');
                return;
            }
            
            $notifier = new \MiIntegracionApi\Deteccion\WooCommerceProductNotifier();
            $success = $notifier->mark_notification_read($notification_id);
            
            if ($success) {
                wp_send_json_success('Notificación marcada como leída');
            } else {
                wp_send_json_error('No se pudo marcar la notificación como leída');
            }
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al marcar notificación: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para archivar notificación
     * @return void
     */
    public function handleArchiveNotification(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        $notification_id = sanitize_text_field($_POST['notification_id'] ?? '');
        if (empty($notification_id)) {
            wp_send_json_error('ID de notificación requerido');
            return;
        }
        
        try {
            if (!class_exists('MiIntegracionApi\\Deteccion\\WooCommerceProductNotifier')) {
                wp_send_json_error('Sistema de notificaciones no disponible');
                return;
            }
            
            $notifier = new \MiIntegracionApi\Deteccion\WooCommerceProductNotifier();
            $success = $notifier->archive_notification($notification_id);
            
            if ($success) {
                wp_send_json_success('Notificación archivada');
            } else {
                wp_send_json_error('No se pudo archivar la notificación');
            }
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al archivar notificación: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para limpiar todas las notificaciones
     * @return void
     */
    public function handleClearAllNotifications(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            if (!class_exists('MiIntegracionApi\\Deteccion\\WooCommerceProductNotifier')) {
                wp_send_json_error('Sistema de notificaciones no disponible');
                return;
            }
            
            $notifier = new \MiIntegracionApi\Deteccion\WooCommerceProductNotifier();
            $notifier->cleanup_old_notifications();
            
            wp_send_json_success('Todas las notificaciones han sido limpiadas');
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al limpiar notificaciones: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para obtener estadísticas de notificaciones
     * @return void
     */
    public function handleGetNotificationStats(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            if (!class_exists('MiIntegracionApi\\Deteccion\\WooCommerceProductNotifier')) {
                wp_send_json_error('Sistema de notificaciones no disponible');
                return;
            }
            
            $notifier = new \MiIntegracionApi\Deteccion\WooCommerceProductNotifier();
            $stats = $notifier->get_notification_stats();
            
            wp_send_json_success($stats);
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al obtener estadísticas: ' . $e->getMessage());
        }
    }
    
    // ===== MANEJADORES AJAX PARA SOLICITUDES DE DOCUMENTOS =====
    
    /**
     * Manejador AJAX para obtener solicitudes de documentos
     * @return void
     */
    public function handleGetDocumentRequests(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            if (!class_exists('MiIntegracionApi\\Deteccion\\WooCommerceProductNotifier')) {
                wp_send_json_error('Sistema de solicitudes de documentos no disponible');
                return;
            }
            
            $notifier = new \MiIntegracionApi\Deteccion\WooCommerceProductNotifier();
            $status_filter = sanitize_text_field($_POST['status'] ?? 'all');
            $limit = (int) ($_POST['limit'] ?? 20);
            $offset = (int) ($_POST['offset'] ?? 0);
            
            $all_requests = $notifier->get_pending_document_requests();
            
            // Filtrar por estado
            if ($status_filter !== 'all') {
                $all_requests = array_filter($all_requests, function($request) use ($status_filter) {
                    return ($request['status'] ?? 'pending') === $status_filter;
                });
            }
            
            // Aplicar paginación
            $requests = array_slice($all_requests, $offset, $limit);
            
            // Formatear para la respuesta
            $formatted_requests = array_map(function($request) {
                return [
                    'id' => $request['product_id'] . '_' . ($request['reference'] ?? 'unknown'),
                    'product_id' => $request['product_id'],
                    'reference' => $request['reference'] ?? 'N/A',
                    'document_id' => $request['document_id'] ?? null,
                    'status' => $request['status'] ?? 'pending',
                    'created_at' => $request['created_at'] ?? 'N/A',
                    'updated_at' => $request['updated_at'] ?? null,
                    'product_name' => $this->get_product_name($request['product_id']),
                    'product_sku' => $this->get_product_sku($request['product_id'])
                ];
            }, $requests);
            
            wp_send_json_success([
                'requests' => $formatted_requests,
                'total' => count($all_requests),
                'has_more' => ($offset + $limit) < count($all_requests)
            ]);
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al obtener solicitudes de documentos: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para crear solicitud de documento manual
     * @return void
     */
    public function handleCreateDocumentRequest(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            $product_id = (int) ($_POST['product_id'] ?? 0);
            
            if (!$product_id) {
                wp_send_json_error('ID de producto requerido');
                return;
            }
            
            if (!class_exists('MiIntegracionApi\\Deteccion\\WooCommerceProductNotifier')) {
                wp_send_json_error('Sistema de solicitudes de documentos no disponible');
                return;
            }
            
            $notifier = new \MiIntegracionApi\Deteccion\WooCommerceProductNotifier();
            $result = $notifier->create_manual_document_request($product_id);
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result['error'] ?? 'Error desconocido');
            }
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al crear solicitud de documento: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para obtener datos de producto de WooCommerce
     * @return void
     */
    public function handleGetProductData(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            $product_id = (int) ($_POST['product_id'] ?? 0);
            
            if (!$product_id) {
                wp_send_json_error('ID de producto requerido');
                return;
            }
            
            $product = wc_get_product($product_id);
            if (!$product) {
                wp_send_json_error('Producto no encontrado');
                return;
            }
            
            $product_data = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'price' => $product->get_price(),
                'stock_quantity' => $product->get_stock_quantity(),
                'description' => $product->get_description(),
                'short_description' => $product->get_short_description(),
                'images' => $this->getProductImages($product),
                'categories' => $this->getProductCategories($product),
                'attributes' => $this->getProductAttributes($product),
                'meta_data' => $this->getProductMetaData($product)
            ];
            
            wp_send_json_success($product_data);
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al obtener datos del producto: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para obtener categorías de Verial
     * @return void
     */
    public function handleGetVerialCategories(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            // Usar ApiConnector para obtener categorías reales
            if (!class_exists('MiIntegracionApi\\Core\\ApiConnector')) {
                wp_send_json_error('ApiConnector no disponible');
                return;
            }
            
            $apiConnector = new \MiIntegracionApi\Core\ApiConnector();
            $categorias_response = $apiConnector->get('GetCategoriasWS');
            
            if (is_wp_error($categorias_response)) {
                wp_send_json_error('Error obteniendo categorías: ' . $categorias_response->get_error_message());
                return;
            }
            
            // Procesar respuesta de la API
            $categorias = [];
            if (isset($categorias_response['Categorias']) && is_array($categorias_response['Categorias'])) {
                foreach ($categorias_response['Categorias'] as $categoria) {
                    $categorias[] = [
                        'id' => $categoria['Id'] ?? 0,
                        'name' => $categoria['Nombre'] ?? 'Sin nombre'
                    ];
                }
            }
            
            wp_send_json_success($categorias);
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al obtener categorías: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para obtener fabricantes de Verial
     * @return void
     */
    public function handleGetVerialManufacturers(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            // Usar ApiConnector para obtener fabricantes reales
            if (!class_exists('MiIntegracionApi\\Core\\ApiConnector')) {
                wp_send_json_error('ApiConnector no disponible');
                return;
            }
            
            $apiConnector = new \MiIntegracionApi\Core\ApiConnector();
            $fabricantes_response = $apiConnector->get('GetFabricantesWS');
            
            if (is_wp_error($fabricantes_response)) {
                wp_send_json_error('Error obteniendo fabricantes: ' . $fabricantes_response->get_error_message());
                return;
            }
            
            // Procesar respuesta de la API
            $fabricantes = [];
            if (isset($fabricantes_response['Fabricantes']) && is_array($fabricantes_response['Fabricantes'])) {
                foreach ($fabricantes_response['Fabricantes'] as $fabricante) {
                    $fabricantes[] = [
                        'id' => $fabricante['Id'] ?? 0,
                        'name' => $fabricante['Nombre'] ?? 'Sin nombre'
                    ];
                }
            }
            
            wp_send_json_success($fabricantes);
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al obtener fabricantes: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para obtener campos configurables de Verial
     * @return void
     */
    public function handleGetVerialConfigurableFields(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            // Usar ApiConnector para obtener campos configurables reales
            if (!class_exists('MiIntegracionApi\\Core\\ApiConnector')) {
                wp_send_json_error('ApiConnector no disponible');
                return;
            }
            
            $apiConnector = new \MiIntegracionApi\Core\ApiConnector();
            $campos_response = $apiConnector->get('GetCamposConfigurablesArticulosWS');
            
            if (is_wp_error($campos_response)) {
                wp_send_json_error('Error obteniendo campos configurables: ' . $campos_response->get_error_message());
                return;
            }
            
            // Procesar respuesta de la API
            $campos = [];
            if (isset($campos_response['Campos']) && is_array($campos_response['Campos'])) {
                foreach ($campos_response['Campos'] as $campo) {
                    $campos[] = [
                        'id' => $campo['Id'] ?? 0,
                        'descripcion' => $campo['Descripcion'] ?? 'Sin descripción',
                        'tipo_dato' => $campo['TipoDato'] ?? 1,
                        'valores' => null // Los valores se obtienen por separado si es necesario
                    ];
                }
            }
            
            wp_send_json_success($campos);
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al obtener campos configurables: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para enviar solicitud de producto
     * @return void
     */
    public function handleSubmitProductRequest(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            $product_data = $_POST['product_data'] ?? '';
            
            if (empty($product_data)) {
                wp_send_json_error('Datos del producto requeridos');
                return;
            }
            
            $json_data = json_decode($product_data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error('JSON de datos del producto inválido');
                return;
            }
            
            // Guardar solicitud en base de datos
            $request_id = $this->saveProductRequest($json_data);
            
            if ($request_id) {
                // Enviar email con la solicitud
                $email_sent = $this->sendProductRequestEmail($json_data, $request_id);
                
                wp_send_json_success([
                    'request_id' => $request_id,
                    'email_sent' => $email_sent,
                    'message' => 'Solicitud enviada exitosamente'
                ]);
            } else {
                wp_send_json_error('Error al guardar la solicitud');
            }
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al enviar solicitud: ' . $e->getMessage());
        }
    }
    
    /**
     * Obtener imágenes del producto
     * @param WC_Product $product
     * @return array
     */
    private function getProductImages($product): array
    {
        $images = [];
        $attachment_ids = $product->get_gallery_image_ids();
        
        // Añadir imagen principal
        if ($product->get_image_id()) {
            $attachment_ids = array_merge([$product->get_image_id()], $attachment_ids);
        }
        
        foreach ($attachment_ids as $attachment_id) {
            $image_url = wp_get_attachment_image_url($attachment_id, 'full');
            if ($image_url) {
                $images[] = [
                    'src' => $image_url,
                    'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true)
                ];
            }
        }
        
        return $images;
    }
    
    /**
     * Obtener categorías del producto
     * @param WC_Product $product
     * @return array
     */
    private function getProductCategories($product): array
    {
        $categories = [];
        $term_ids = $product->get_category_ids();
        
        foreach ($term_ids as $term_id) {
            $term = get_term($term_id);
            if ($term && !is_wp_error($term)) {
                $categories[] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug
                ];
            }
        }
        
        return $categories;
    }
    
    /**
     * Obtener atributos del producto
     * @param WC_Product $product
     * @return array
     */
    private function getProductAttributes($product): array
    {
        $attributes = [];
        $product_attributes = $product->get_attributes();
        
        foreach ($product_attributes as $attribute) {
            $attributes[] = [
                'name' => $attribute->get_name(),
                'options' => $attribute->get_options(),
                'visible' => $attribute->get_visible(),
                'variation' => $attribute->get_variation()
            ];
        }
        
        return $attributes;
    }
    
    /**
     * Obtener metadatos del producto
     * @param WC_Product $product
     * @return array
     */
    private function getProductMetaData($product): array
    {
        $meta_data = [];
        $all_meta = $product->get_meta_data();
        
        foreach ($all_meta as $meta) {
            $meta_data[$meta->key] = $meta->value;
        }
        
        return $meta_data;
    }
    
    /**
     * Guardar solicitud de producto en base de datos
     * @param array $json_data
     * @return int|false
     */
    private function saveProductRequest($json_data)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mia_product_requests';
        
        $result = $wpdb->insert(
            $table_name,
            [
                'reference' => $json_data['Referencia'] ?? '',
                'product_data' => json_encode($json_data),
                'status' => 'pending',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Enviar email con solicitud de producto
     * @param array $json_data
     * @param int $request_id
     * @return bool
     */
    private function sendProductRequestEmail($json_data, $request_id): bool
    {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $subject = sprintf('[%s] Solicitud de Creación de Producto en Verial - %s',
                          $site_name,
                          $json_data['Referencia'] ?? 'Sin referencia');
        $message = $this->generateEmailMessage($json_data, $request_id);
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>'
        ];
        
        return wp_mail($admin_email, $subject, $message, $headers);
    }
    
    /**
     * Generar mensaje de email
     * @param array $json_data
     * @param int $request_id
     * @return string
     */
    private function generateEmailMessage($json_data, $request_id): string
    {
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();
        
        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #667eea; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .section { margin-bottom: 20px; }
                .json-data { background: #f4f4f4; padding: 15px; border-radius: 5px; font-family: monospace; white-space: pre-wrap; }
                .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>🛍️ Solicitud de Creación de Producto en Verial</h1>
                <p>ID de Solicitud: #{$request_id}</p>
            </div>
            
            <div class='content'>
                <div class='section'>
                    <h2>📋 Información de la Solicitud</h2>
                    <p><strong>Referencia:</strong> {$json_data['Referencia']}</p>
                    <p><strong>Fecha:</strong> {$json_data['Fecha']}</p>
                    <p><strong>Sitio:</strong> {$site_name} ({$site_url})</p>
                </div>
                
                <div class='section'>
                    <h2>📦 Datos del Producto</h2>
                    <p>Se ha solicitado la creación de un nuevo producto en Verial con los siguientes datos:</p>
                    <div class='json-data'>" . json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</div>
                </div>
                
                <div class='section'>
                    <h2>📝 Comentarios</h2>
                    <p>{$json_data['Contenido'][0]['Comentario']}</p>
                </div>
            </div>
            
            <div class='footer'>
                <p>Este email fue generado automáticamente por el sistema de integración de {$site_name}.</p>
                <p>Fecha de envío: " . current_time('d/m/Y H:i:s') . "</p>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Manejador AJAX para actualizar estado de solicitud de documento
     * @return void
     */
    public function handleUpdateDocumentStatus(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            $product_id = (int) ($_POST['product_id'] ?? 0);
            $reference = sanitize_text_field($_POST['reference'] ?? '');
            $status = sanitize_text_field($_POST['status'] ?? '');
            
            if (!$product_id || !$reference || !$status) {
                wp_send_json_error('Parámetros requeridos: product_id, reference, status');
                return;
            }
            
            if (!class_exists('MiIntegracionApi\\Deteccion\\WooCommerceProductNotifier')) {
                wp_send_json_error('Sistema de solicitudes de documentos no disponible');
                return;
            }
            
            $notifier = new \MiIntegracionApi\Deteccion\WooCommerceProductNotifier();
            $result = $notifier->update_document_request_status($product_id, $reference, $status);
            
            if ($result) {
                wp_send_json_success(['message' => 'Estado actualizado correctamente']);
            } else {
                wp_send_json_error('Error al actualizar estado');
            }
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al actualizar estado: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para obtener estadísticas de solicitudes de documentos
     * @return void
     */
    public function handleGetDocumentStats(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            if (!class_exists('MiIntegracionApi\\Deteccion\\WooCommerceProductNotifier')) {
                wp_send_json_error('Sistema de solicitudes de documentos no disponible');
                return;
            }
            
            $notifier = new \MiIntegracionApi\Deteccion\WooCommerceProductNotifier();
            $stats = $notifier->get_document_request_stats();
            
            wp_send_json_success($stats);
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al obtener estadísticas: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para limpiar solicitudes de documentos antiguas
     * @return void
     */
    public function handleCleanupDocumentRequests(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            $days = (int) ($_POST['days'] ?? 90);
            
            if (!class_exists('MiIntegracionApi\\Deteccion\\WooCommerceProductNotifier')) {
                wp_send_json_error('Sistema de solicitudes de documentos no disponible');
                return;
            }
            
            $notifier = new \MiIntegracionApi\Deteccion\WooCommerceProductNotifier();
            $deleted_count = $notifier->cleanup_old_document_requests($days);
            
            wp_send_json_success([
                'message' => "Se eliminaron {$deleted_count} solicitudes antiguas",
                'deleted_count' => $deleted_count
            ]);
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al limpiar solicitudes: ' . $e->getMessage());
        }
    }
    
    /**
     * Obtiene el nombre de un producto por su ID
     * @param int $product_id ID del producto
     * @return string Nombre del producto
     */
    private function get_product_name(int $product_id): string
    {
        $product = wc_get_product($product_id);
        return $product ? $product->get_name() : 'Producto no encontrado';
    }
    
    /**
     * Obtiene el SKU de un producto por su ID
     * @param int $product_id ID del producto
     * @return string SKU del producto
     */
    private function get_product_sku(int $product_id): string
    {
        $product = wc_get_product($product_id);
        return $product ? $product->get_sku() : 'N/A';
    }
    
    // ===== MANEJADORES AJAX PARA CONFIGURACIÓN DE NOTIFICACIONES =====
    
    /**
     * Manejador AJAX para obtener configuración de notificaciones
     * @return void
     */
    public function handleGetNotificationConfig(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            if (!class_exists('MiIntegracionApi\\Admin\\NotificationConfig')) {
                wp_send_json_error('Sistema de configuración de notificaciones no disponible');
                return;
            }
            
            $config = \MiIntegracionApi\Admin\NotificationConfig::get_config();
            wp_send_json_success($config);
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al obtener configuración: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para guardar configuración de notificaciones
     * @return void
     */
    public function handleSaveNotificationConfig(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            if (!class_exists('MiIntegracionApi\\Admin\\NotificationConfig')) {
                wp_send_json_error('Sistema de configuración de notificaciones no disponible');
                return;
            }
            
            $config = $_POST['config'] ?? [];
            if (empty($config)) {
                wp_send_json_error('Configuración no proporcionada');
                return;
            }
            
            $success = true;
            $errors = [];
            
            // Guardar configuración general
            if (isset($config['notifications_enabled'])) {
                \MiIntegracionApi\Admin\NotificationConfig::set_notifications_enabled((bool) $config['notifications_enabled']);
            }
            
            if (isset($config['auto_document_requests'])) {
                \MiIntegracionApi\Admin\NotificationConfig::set_auto_document_requests_enabled((bool) $config['auto_document_requests']);
            }
            
            if (isset($config['notification_types'])) {
                \MiIntegracionApi\Admin\NotificationConfig::set_notification_types($config['notification_types']);
            }
            
            if (isset($config['notification_schedule'])) {
                \MiIntegracionApi\Admin\NotificationConfig::set_notification_schedule($config['notification_schedule']);
            }
            
            if (isset($config['notification_retention_days'])) {
                \MiIntegracionApi\Admin\NotificationConfig::set_notification_retention_days((int) $config['notification_retention_days']);
            }
            
            if (isset($config['notification_templates'])) {
                \MiIntegracionApi\Admin\NotificationConfig::set_notification_templates($config['notification_templates']);
            }
            
            if (isset($config['notification_emails'])) {
                \MiIntegracionApi\Admin\NotificationConfig::set_notification_emails($config['notification_emails']);
            }
            
            if (isset($config['notification_thresholds'])) {
                \MiIntegracionApi\Admin\NotificationConfig::set_notification_thresholds($config['notification_thresholds']);
            }
            
            if ($success) {
                wp_send_json_success(['message' => 'Configuración guardada correctamente']);
            } else {
                wp_send_json_error('Error al guardar configuración');
            }
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al guardar configuración: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para resetear configuración de notificaciones
     * @return void
     */
    public function handleResetNotificationConfig(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            if (!class_exists('MiIntegracionApi\\Admin\\NotificationConfig')) {
                wp_send_json_error('Sistema de configuración de notificaciones no disponible');
                return;
            }
            
            $success = \MiIntegracionApi\Admin\NotificationConfig::reset_to_defaults();
            
            if ($success) {
                wp_send_json_success(['message' => 'Configuración reseteada a valores por defecto']);
            } else {
                wp_send_json_error('Error al resetear configuración');
            }
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al resetear configuración: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para validar configuración de notificaciones
     * @return void
     */
    public function handleValidateNotificationConfig(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            if (!class_exists('MiIntegracionApi\\Admin\\NotificationConfig')) {
                wp_send_json_error('Sistema de configuración de notificaciones no disponible');
                return;
            }
            
            $validation = \MiIntegracionApi\Admin\NotificationConfig::validate_config();
            wp_send_json_success($validation);
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error al validar configuración: ' . $e->getMessage());
        }
    }
    
    /**
     * Manejador AJAX para obtener el estado del sistema de detección automática
     */
    public function handleGetSystemStatus(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_detection_nonce')) {
            wp_send_json_error('Nonce inválido');
        }
        
        try {
            // Verificar si el sistema de detección automática está activo
            $is_active = $this->isDetectionSystemActive();
            
            wp_send_json_success([
                'is_active' => $is_active,
                'timestamp' => current_time('mysql')
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->error('Error obteniendo estado del sistema', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            wp_send_json_error('Error al obtener estado del sistema: ' . $e->getMessage());
        }
    }
    
    /**
     * Verifica si el sistema de detección automática está activo
     * @return bool True si está activo, false en caso contrario
     */
    private function isDetectionSystemActive(): bool
    {
        // Verificar si hay un cron job activo para la detección automática
        $cron_hooks = [
            'mia_auto_detection_hook',
            'mia_stock_detection_hook',
            'mia_sync_detection_hook'
        ];
        
        foreach ($cron_hooks as $hook) {
            if (wp_next_scheduled($hook)) {
                return true;
            }
        }
        
        // Verificar si hay una opción específica que indique que está activo
        $detection_active = get_option('mia_detection_auto_active', false);
        if ($detection_active) {
            return true;
        }
        
        // Verificar si hay productos en cola de sincronización
        $sync_queue = get_option('mia_sync_queue', []);
        if (!empty($sync_queue)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Programa el cron job para la detección automática
     */
    private function scheduleDetectionCron(): void
    {
        // Desprogramar cron existente si existe
        $this->unscheduleDetectionCron();
        
        // Registrar intervalo personalizado si no existe
        add_filter('cron_schedules', [$this, 'add_custom_cron_intervals']);
        
        // Programar nuevo cron job cada 5 minutos
        if (!wp_next_scheduled('mia_auto_detection_hook')) {
            wp_schedule_event(time(), 'mia_every_5_minutes', 'mia_auto_detection_hook');
        }
        
        $this->logger->info('Cron job de detección automática programado');
    }
    
    /**
     * Agregar intervalos personalizados para cron
     */
    public function add_custom_cron_intervals($schedules): array
    {
        $schedules['mia_every_5_minutes'] = [
            'interval' => 300, // 5 minutos en segundos
            'display' => __('Cada 5 minutos', 'mi-integracion-api')
        ];
        
        return $schedules;
    }
    
    /**
     * Desprograma el cron job para la detección automática
     */
    private function unscheduleDetectionCron(): void
    {
        $timestamp = wp_next_scheduled('mia_auto_detection_hook');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'mia_auto_detection_hook');
        }
        
        // Limpiar todos los eventos de este hook
        wp_clear_scheduled_hook('mia_auto_detection_hook');
        
        $this->logger->info('Cron job de detección automática desprogramado');
    }
    
    /**
     * Ejecutar detección automática programada
     */
    public function executeAutoDetection(): void
    {
        // Verificar si la detección automática está habilitada
        if (!get_option('mia_automatic_stock_detection_enabled', false)) {
            $this->logger->info('Detección automática deshabilitada, saltando ejecución');
            return;
        }
        
        try {
            $this->logger->info('Iniciando detección automática programada');
            
            // Obtener instancia del detector
            $detector = \MiIntegracionApi\Deteccion\StockDetectorIntegration::getDetector();
            
            if (!$detector) {
                $this->logger->error('Detector no disponible para detección automática');
                return;
            }
            
            // Ejecutar detección automática
            $result = $detector->executeAutoDetection();
            
            $this->logger->info('Detección automática completada', [
                'result' => $result
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Error en detección automática', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
