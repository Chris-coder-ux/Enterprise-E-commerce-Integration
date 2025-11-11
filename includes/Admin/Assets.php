<?php

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

use MiIntegracionApi\Logging\Core\LoggerBasic;

/**
 * Gestiona el registro y carga de recursos estáticos (CSS/JS) para el panel de administración y frontend
 *
 * Esta clase se encarga de registrar, encolar y gestionar todos los recursos estáticos
 * (estilos y scripts) necesarios para el funcionamiento del plugin en el área de administración
 * y en el frontend. Incluye manejo de dependencias, localización de scripts y optimizaciones.
 *
 * @package MiIntegracionApi\Admin
 * @since 1.0.0
 */
class Assets {
    /**
     * Instancia del logger para registrar eventos
     *
     * @var Logger
     * @since 1.0.0
     */
    private $logger;

    /**
     * Constructor de la clase
     *
     * Inicializa el logger para el registro de eventos relacionados con los assets.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->logger = new LoggerBasic('assets');
    }

    /**
     * Inicializa los hooks de WordPress para el registro de assets
     *
     * Registra los hooks necesarios para cargar los recursos estáticos
     * tanto en el panel de administración como en el frontend.
     *
     * @return void
     * @since 1.0.0
     * @uses add_action() Para registrar los hooks de WordPress
     */
    public function init(): void {
        // Registrar scripts y estilos
        add_action('admin_enqueue_scripts', [$this, 'register_assets'], 20);
        add_action('wp_enqueue_scripts', [$this, 'register_frontend_assets'], 20);
        
        // $this->logger->info('Assets hooks registered');
    }

    /**
     * Registra y encola los recursos estáticos del panel de administración
     *
     * Este método se encarga de registrar y encolar todos los estilos y scripts necesarios
     * para el correcto funcionamiento del panel de administración del plugin.
     * Incluye manejo condicional de recursos según la página actual.
     *
     * @return void
     * @since 1.0.0
     * @uses wp_register_style() Para registrar hojas de estilo
     * @uses wp_enqueue_style() Para encolar hojas de estilo
     * @uses wp_register_script() Para registrar scripts
     * @uses wp_enqueue_script() Para encolar scripts
     * @uses wp_localize_script() Para pasar datos de PHP a JavaScript
     * @uses get_current_screen() Para determinar la página actual
     *
     * @todo Implementar sistema de caché para los recursos estáticos
     * @todo Añadir soporte para cargar versiones minificadas en producción
     */
    public function register_assets(): void {
        try {
            // Verificar si estamos en una página del plugin
            if (!$this->is_plugin_admin_page()) {
                return; // ✅ ELIMINADO: Log innecesario, solo retornar
            }

            // Registrar design-system primero (variables CSS)
            $design_system_css_url = MiIntegracionApi_PLUGIN_URL . 'assets/css/design-system.css';
            wp_register_style(
                'mi-integracion-api-design-system',
                $design_system_css_url,
                [],
                constant('MiIntegracionApi_VERSION')
            );
            wp_enqueue_style('mi-integracion-api-design-system');

            // Registrar y encolar estilos
            $admin_css_url = MiIntegracionApi_PLUGIN_URL . 'assets/css/admin.css';
            // ✅ ELIMINADO: Log de cada URL registrada es innecesario
            
            wp_register_style(
                'mi-integracion-api-admin',
                $admin_css_url,
                ['mi-integracion-api-design-system'],
                constant('MiIntegracionApi_VERSION')
            );
            wp_enqueue_style('mi-integracion-api-admin');

            // Registrar CSS de endpoints (solo en páginas de endpoints)
            if (isset($_GET['page']) && strpos($_GET['page'], 'endpoints') !== false) {
                $endpoints_css_url = MiIntegracionApi_PLUGIN_URL . 'assets/css/endpoints.css';
                wp_register_style(
                    'mi-integracion-api-endpoints',
                    $endpoints_css_url,
                    ['mi-integracion-api-design-system'],
                    constant('MiIntegracionApi_VERSION')
                );
                wp_enqueue_style('mi-integracion-api-endpoints');
            }

            // Registrar script de utilidades (necesario para admin-main.js y logs-viewer-main.js)
            $utils_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/utils.js';
            wp_register_script(
                'mi-integracion-api-utils',
                $utils_js_url,
                ['jquery'],
                constant('MiIntegracionApi_VERSION'),
                true
            );

            // Registrar script de utilidades modernas (necesario para admin-main.js)
            $modern_utils_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/modern-utils.js';
            wp_register_script(
                'mi-integracion-api-modern-utils',
                $modern_utils_js_url,
                ['jquery'],
                constant('MiIntegracionApi_VERSION'),
                true
            );

            // Registrar y encolar script principal del admin
            $admin_main_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/admin-main.js';
            
            wp_register_script(
                'mi-integracion-api-admin-main',
                $admin_main_js_url,
                ['jquery', 'mi-integracion-api-utils', 'mi-integracion-api-modern-utils'],
                constant('MiIntegracionApi_VERSION'),
                true
            );
            wp_enqueue_script('mi-integracion-api-admin-main');

            // Localizar el script principal del admin
            wp_localize_script('mi-integracion-api-admin-main', 'miIntegracionApi', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mi_integracion_api_nonce'),
                'restUrl' => rest_url('mi-integracion-api/v1/'), // Necesario para logs-viewer
                'restNonce' => wp_create_nonce('wp_rest'), // Nonce específico para REST API
                'i18n' => [
                    'confirmDelete' => __('¿Estás seguro de que deseas eliminar este elemento?', 'mi-integracion-api'),
                    'error' => __('Ha ocurrido un error', 'mi-integracion-api'),
                    'success' => __('Operación realizada con éxito', 'mi-integracion-api'),
                    'confirmClearLogs' => __('¿Estás seguro de que deseas borrar todos los logs? Esta acción es irreversible.', 'mi-integracion-api'),
                    'genericError' => __('Ha ocurrido un error inesperado.', 'mi-integracion-api'),
                    'noLogsFound' => __('No se encontraron logs.', 'mi-integracion-api'),
                    'logId' => __('ID', 'mi-integracion-api'),
                    'logType' => __('Tipo', 'mi-integracion-api'),
                    'logDate' => __('Fecha', 'mi-integracion-api'),
                    'logMessage' => __('Mensaje', 'mi-integracion-api'),
                    'logContext' => __('Contexto', 'mi-integracion-api'),
                    'exportNotImplemented' => __('La función de exportar aún no está implementada.', 'mi-integracion-api'),
                    'clearLogsError' => __('Error al borrar los logs.', 'mi-integracion-api'),
                ]
            ]);

            // CORRECCIÓN CRÍTICA: Cargar dashboard.js en la página principal del plugin y en la página de sincronización de pedidos
            $screen = get_current_screen();
            if ($screen && ($screen->id === 'toplevel_page_mi-integracion-api' || $screen->id === 'mi-integracion-api_page_mi-integracion-api-order-sync')) {
                // Registrar constants.js primero (no tiene dependencias)
                $constants_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/config/constants.js';
                wp_register_script(
                    'mi-integracion-api-constants',
                    $constants_js_url,
                    [],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar messages.js (depende de constants, opcional)
                $messages_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/config/messages.js';
                wp_register_script(
                    'mi-integracion-api-messages',
                    $messages_js_url,
                    ['mi-integracion-api-constants'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar dashboard-config.js (depende de constants y messages)
                $dashboard_config_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/config/dashboard-config.js';
                wp_register_script(
                    'mi-integracion-api-dashboard-config',
                    $dashboard_config_js_url,
                    ['jquery', 'mi-integracion-api-constants', 'mi-integracion-api-messages'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar DomUtils.js (depende de dashboard-config y constants)
                $dom_utils_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/utils/DomUtils.js';
                wp_register_script(
                    'mi-integracion-api-dom-utils',
                    $dom_utils_js_url,
                    ['jquery', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar PollingManager.js (depende de constants y dashboard-config)
                $polling_manager_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/managers/PollingManager.js';
                wp_register_script(
                    'mi-integracion-api-polling-manager',
                    $polling_manager_js_url,
                    ['jquery', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar SyncStateManager.js (depende de polling-manager)
                $sync_state_manager_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/managers/SyncStateManager.js';
                wp_register_script(
                    'mi-integracion-api-sync-state-manager',
                    $sync_state_manager_js_url,
                    ['jquery', 'mi-integracion-api-polling-manager', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar NonceManager.js (depende de jquery)
                $nonce_manager_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/managers/NonceManager.js';
                wp_register_script(
                    'mi-integracion-api-nonce-manager',
                    $nonce_manager_js_url,
                    ['jquery'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar ToastManager.js (depende de jquery y dashboard-config)
                $toast_manager_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/components/ToastManager.js';
                wp_register_script(
                    'mi-integracion-api-toast-manager',
                    $toast_manager_js_url,
                    ['jquery', 'mi-integracion-api-dashboard-config'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar ProgressBar.js (depende de jquery y dom-utils)
                $progress_bar_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/components/ProgressBar.js';
                wp_register_script(
                    'mi-integracion-api-progress-bar',
                    $progress_bar_js_url,
                    ['jquery', 'mi-integracion-api-dom-utils'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar ConsoleManager.js (depende de jquery)
                $console_manager_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/components/ConsoleManager.js';
                wp_register_script(
                    'mi-integracion-api-console-manager',
                    $console_manager_js_url,
                    ['jquery'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar SyncProgress.js (depende de múltiples módulos)
                $sync_progress_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/sync/SyncProgress.js';
                wp_register_script(
                    'mi-integracion-api-sync-progress',
                    $sync_progress_js_url,
                    [
                        'jquery',
                        'mi-integracion-api-constants',
                        'mi-integracion-api-dashboard-config',
                        'mi-integracion-api-dom-utils',
                        'mi-integracion-api-polling-manager',
                        'mi-integracion-api-sync-state-manager',
                        'mi-integracion-api-console-manager',
                        'mi-integracion-api-error-handler',
                        'mi-integracion-api-ajax-manager'
                    ],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar Phase1Manager.js (depende de múltiples módulos)
                $phase1_manager_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/sync/Phase1Manager.js';
                wp_register_script(
                    'mi-integracion-api-phase1-manager',
                    $phase1_manager_js_url,
                    [
                        'jquery',
                        'mi-integracion-api-constants',
                        'mi-integracion-api-dashboard-config',
                        'mi-integracion-api-dom-utils',
                        'mi-integracion-api-polling-manager',
                        'mi-integracion-api-sync-state-manager',
                        'mi-integracion-api-error-handler'
                    ],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar Phase2Manager.js (depende de múltiples módulos)
                $phase2_manager_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/sync/Phase2Manager.js';
                wp_register_script(
                    'mi-integracion-api-phase2-manager',
                    $phase2_manager_js_url,
                    [
                        'jquery',
                        'mi-integracion-api-constants',
                        'mi-integracion-api-dashboard-config',
                        'mi-integracion-api-dom-utils',
                        'mi-integracion-api-polling-manager',
                        'mi-integracion-api-sync-state-manager',
                        'mi-integracion-api-error-handler',
                        'mi-integracion-api-sync-progress'
                    ],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar SyncController.js (depende de múltiples módulos)
                $sync_controller_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/sync/SyncController.js';
                wp_register_script(
                    'mi-integracion-api-sync-controller',
                    $sync_controller_js_url,
                    [
                        'jquery',
                        'mi-integracion-api-constants',
                        'mi-integracion-api-dashboard-config',
                        'mi-integracion-api-dom-utils',
                        'mi-integracion-api-phase1-manager',
                        'mi-integracion-api-sync-state-manager',
                        'mi-integracion-api-error-handler'
                    ],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar SyncDashboard.js (depende de múltiples módulos)
                $sync_dashboard_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/components/SyncDashboard.js';
                wp_register_script(
                    'mi-integracion-api-sync-dashboard',
                    $sync_dashboard_js_url,
                    [
                        'jquery',
                        'mi-integracion-api-constants',
                        'mi-integracion-api-dashboard-config',
                        'mi-integracion-api-ajax-manager',
                        'mi-integracion-api-polling-manager',
                        'mi-integracion-api-console-manager'
                    ],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar UnifiedDashboard.js (depende de múltiples módulos)
                $unified_dashboard_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/components/UnifiedDashboard.js';
                wp_register_script(
                    'mi-integracion-api-unified-dashboard',
                    $unified_dashboard_js_url,
                    [
                        'jquery',
                        'mi-integracion-api-constants',
                        'mi-integracion-api-ajax-manager',
                        'mi-integracion-api-error-handler',
                        'mi-integracion-api-polling-manager'
                    ],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar ResponsiveLayout.js
                $responsive_layout_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/ui/ResponsiveLayout.js';
                wp_register_script(
                    'mi-integracion-api-responsive-layout',
                    $responsive_layout_js_url,
                    ['jquery'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar CardManager.js (depende de SELECTORS y ToastManager)
                $card_manager_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/ui/CardManager.js';
                wp_register_script(
                    'mi-integracion-api-card-manager',
                    $card_manager_js_url,
                    [
                        'jquery',
                        'mi-integracion-api-constants',
                        'mi-integracion-api-toast-manager'
                    ],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar UnifiedDashboardController.js (depende de múltiples módulos)
                $unified_dashboard_controller_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/controllers/UnifiedDashboardController.js';
                wp_register_script(
                    'mi-integracion-api-unified-dashboard-controller',
                    $unified_dashboard_controller_js_url,
                    [
                        'jquery',
                        'mi-integracion-api-unified-dashboard',
                        'mi-integracion-api-sync-dashboard',
                        'mi-integracion-api-responsive-layout',
                        'mi-integracion-api-event-manager'
                    ],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar ErrorHandler.js como dependencia
                $error_handler_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/core/ErrorHandler.js';
                wp_register_script(
                    'mi-integracion-api-error-handler',
                    $error_handler_js_url,
                    ['jquery', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar AjaxManager.js como dependencia (depende de ErrorHandler)
                $ajax_manager_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/core/AjaxManager.js';
                wp_register_script(
                    'mi-integracion-api-ajax-manager',
                    $ajax_manager_js_url,
                    ['jquery', 'mi-integracion-api-error-handler', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                // Registrar EventManager.js como dependencia (depende de ErrorHandler y AjaxManager)
                $event_manager_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/core/EventManager.js';
                wp_register_script(
                    'mi-integracion-api-event-manager',
                    $event_manager_js_url,
                    ['jquery', 'mi-integracion-api-error-handler', 'mi-integracion-api-ajax-manager', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
                
                $dashboard_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/dashboard.js';
                
                wp_register_script(
                    'mi-integracion-api-dashboard',
                    $dashboard_js_url,
                    ['jquery', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config', 'mi-integracion-api-error-handler', 'mi-integracion-api-ajax-manager', 'mi-integracion-api-event-manager'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
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
                wp_enqueue_script('mi-integracion-api-sync-progress');
                wp_enqueue_script('mi-integracion-api-phase1-manager');
                wp_enqueue_script('mi-integracion-api-phase2-manager');
                wp_enqueue_script('mi-integracion-api-sync-controller');
                wp_enqueue_script('mi-integracion-api-sync-dashboard');
                wp_enqueue_script('mi-integracion-api-unified-dashboard');
                wp_enqueue_script('mi-integracion-api-responsive-layout');
                wp_enqueue_script('mi-integracion-api-card-manager');
                wp_enqueue_script('mi-integracion-api-unified-dashboard-controller');
                wp_enqueue_script('mi-integracion-api-ajax-manager');
                wp_enqueue_script('mi-integracion-api-event-manager');
                wp_enqueue_script('mi-integracion-api-dashboard');

                // CORRECCIÓN CRÍTICA: Localizar específicamente para dashboard.js con nonce unificado
                wp_localize_script('mi-integracion-api-dashboard', 'miIntegracionApiDashboard', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mi_integracion_api_nonce_dashboard'), // Nonce unificado
                'restUrl' => rest_url('mi-integracion-api/v1/'),
                'confirmSync' => __('¿Iniciar sincronización de productos? Esta acción puede tomar varios minutos.', 'mi-integracion-api'),
                'confirmCancel' => __('¿Seguro que deseas cancelar la sincronización?', 'mi-integracion-api'),
                'debug' => defined('WP_DEBUG') && constant('WP_DEBUG'), // CORRECCIÓN #6: Flag de debug
                // CENTRALIZADO: Configuración de polling desde Sync_Manager
                'pollingConfig' => apply_filters('mia_polling_config', 
                    \MiIntegracionApi\Core\Sync_Manager::get_instance()->getPollingConfiguration()
                ),
                // CENTRALIZADO: Configuración de timeouts desde Sync_Manager
                'timeoutConfig' => \MiIntegracionApi\Core\Sync_Manager::get_instance()->getTimeoutConfiguration()->getData(),
                // CENTRALIZADO: Configuración de límites desde Sync_Manager
                'limitsConfig' => \MiIntegracionApi\Core\Sync_Manager::get_instance()->getLimitsConfiguration()->getData(),
                // CENTRALIZADO: Configuración de UI desde Sync_Manager
                'uiConfig' => \MiIntegracionApi\Core\Sync_Manager::get_instance()->getUIConfiguration()->getData(),
                'i18n' => [
                    'error' => __('Ha ocurrido un error', 'mi-integracion-api'),
                    'success' => __('Operación realizada con éxito', 'mi-integracion-api'),
                    'syncInProgress' => __('Sincronización en progreso...', 'mi-integracion-api'),
                    'syncCompleted' => __('¡Sincronización completada!', 'mi-integracion-api'),
                    'syncCancelled' => __('Sincronización cancelada', 'mi-integracion-api'),
                ]
            ]);
            } // Cerrar el bloque condicional para dashboard.js

            // Opcional: registrar logs-viewer-main.js si es necesario en todas las páginas de admin
            // o se puede cargar condicionalmente en la página de logs. Por ahora, lo registraré.
            $logs_viewer_main_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/logs-viewer-main.js';
            wp_register_script(
                'mi-integracion-api-logs-viewer-main',
                $logs_viewer_main_js_url,
                ['jquery', 'mi-integracion-api-utils'],
                constant('MiIntegracionApi_VERSION'),
                true
            );
            // No encolar aquí, se encolará solo en la página de logs si es necesario
        } catch (\Exception $e) {
            $this->logger->error('Error registering admin assets: ' . $e->getMessage());
        }
    }

    /**
     * Registra y encola los recursos estáticos para el frontend
     *
     * Este método se encarga de registrar y encolar todos los estilos y scripts necesarios
     * para el correcto funcionamiento del plugin en el frontend del sitio.
     *
     * @return void
     * @since 1.0.0
     * @uses wp_register_style() Para registrar hojas de estilo
     * @uses wp_enqueue_style() Para encolar hojas de estilo
     * @uses wp_register_script() Para registrar scripts
     * @uses wp_enqueue_script() Para encolar scripts
     */
    public function register_frontend_assets(): void {
        try {
            // Registrar y encolar estilos frontend
            $frontend_css_url = MiIntegracionApi_PLUGIN_URL . 'assets/css/frontend.css';
            
            wp_register_style(
                'mi-integracion-api-frontend',
                $frontend_css_url,
                [],
                constant('MiIntegracionApi_VERSION')
            );
            wp_enqueue_style('mi-integracion-api-frontend');

            // Registrar script de utilidades (ya registrado en admin, pero asegurar para frontend si se carga solo)
            $utils_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/utils.js';
            if (!wp_script_is('mi-integracion-api-utils', 'registered')) {
                wp_register_script(
                    'mi-integracion-api-utils',
                    $utils_js_url,
                    ['jquery'],
                    constant('MiIntegracionApi_VERSION'),
                    true
                );
            }
            
            // Registrar y encolar script principal del frontend
            $frontend_main_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/frontend-main.js';
            
            wp_register_script(
                'mi-integracion-api-frontend-main',
                $frontend_main_js_url,
                ['jquery', 'mi-integracion-api-utils'],
                constant('MiIntegracionApi_VERSION'),
                true
            );
            wp_enqueue_script('mi-integracion-api-frontend-main');

            // Localizar el script frontend
            wp_localize_script('mi-integracion-api-frontend-main', 'miIntegracionApi', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mi_integracion_api_nonce'),
                'i18n' => [
                    'error' => __('Ha ocurrido un error', 'mi-integracion-api'),
                    'success' => __('Operación realizada con éxito', 'mi-integracion-api')
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error registering frontend assets: ' . $e->getMessage());
        }
    }

    /**
     * Verifica si la página actual es una página de administración del plugin
     *
     * @return bool True si es una página de administración del plugin, false en caso contrario
     * @since 1.0.0
     * @uses get_current_screen() Para obtener información sobre la pantalla actual
     */
    private function is_plugin_admin_page(): bool {
        try {
            $screen = get_current_screen();
            if (!$screen) {
                return false; // ✅ ELIMINADO: Log innecesario
            }

            // Verificar si estamos en una página del plugin
            return strpos($screen->id, 'mi-integracion') !== false;
        } catch (\Exception $e) {
            $this->logger->error('Error checking plugin admin page: ' . $e->getMessage());
            return false;
        }
    }
} 