<?php

declare(strict_types=1);

/**
 * Gestiona el menú de administración y las páginas del plugin.
 *
 * Esta clase se encarga de registrar y gestionar todos los elementos del menú
 * de administración de WordPress para el plugin, incluyendo páginas principales,
 * subpáginas y elementos relacionados con la interfaz de administración.
 *
 * @since       1.0.0
 * @package     MiIntegracionApi
 * @subpackage  MiIntegracionApi/Admin
 * @author      Your Name <your.email@example.com>
 * @copyright   Copyright (c) 2025, Your Company
 * @license     GPL-2.0+
 * @link        https://example.com/plugin-docs/admin
 */

namespace MiIntegracionApi\Admin;

// Si este archivo es llamado directamente, abortar.
if ( ! defined( "ABSPATH" ) ) {
    exit;
}

use MiIntegracionApi\Core\Module_Loader;
use MiIntegracionApi\Logging\Core\LoggerBasic;

/**
 * Clase para gestionar el menú de administración y las páginas del plugin.
 *
 * Esta clase maneja la creación y gestión de todos los elementos del menú de administración
 * de WordPress para el plugin, incluyendo páginas principales, subpáginas y elementos
 * relacionados con la interfaz de administración.
 *
 * @since       1.0.0
 * @package     MiIntegracionApi
 * @subpackage  MiIntegracionApi/Admin
 * @see         \MiIntegracionApi\Core\Module_Loader
 * @see         \MiIntegracionApi\Logging\Core\Logger
 */
class AdminMenu {

    /**
     * ID del menú principal del plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $menu_id    Identificador único del menú principal del plugin.
     *                                Se utiliza como prefijo para las páginas relacionadas.
     *                                Valor por defecto: 'mi-integracion-api'.
     */
    private string $menu_id;

    /**
     * URL base de los recursos estáticos del plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $assets_url    URL completa al directorio de recursos (CSS, JS, imágenes).
     *                                   Se construye automáticamente si no se proporciona.
     */
    private string $assets_url;

    /**
     * Directorio de plantillas del plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $templates_dir    Ruta absoluta al directorio de plantillas de administración.
     *                                      Se utiliza para cargar las vistas del panel de control.
     */
    private string $templates_dir;

    /**
     * Instancia del Logger para el registro de eventos.
     *
     * @since    1.0.0
     * @access   private
     * @var      LoggerBasic    $logger    Instancia del logger configurada para 'admin_menu'.
     *                               Se utiliza para registrar eventos y errores.
     */
    private LoggerBasic $logger;

    /**
     * Módulos disponibles para el plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      array<string, mixed>    $modules    Lista de módulos disponibles cargados a través de Module_Loader.
     *                                              Cada módulo debe seguir la estructura definida en Module_Loader.
     */
    private array $modules = [];

    /**
     * Inicializa una nueva instancia de la clase AdminMenu.
     *
     * @since 1.0.0
     * @param string $menu_id       Identificador único para el menú principal.
     *                             Si está vacío, se usará 'mi-integracion-api'.
     * @param string $assets_url    URL base para los recursos estáticos (CSS, JS, imágenes).
     *                             Si está vacío, se usará la ruta por defecto del plugin.
     * @param string $templates_dir Ruta absoluta al directorio de plantillas.
     *                             Si está vacío, se usará la ruta por defecto del plugin.
     *
     * @example
     * ```php
     * $admin_menu = new AdminMenu(
     *     'mi-plugin',
     *     'https://example.com/wp-content/plugins/mi-plugin/assets/',
     *     '/ruta/al/plugin/templates/admin/'
     * );
     * ```
     */
    public function __construct( string $menu_id = "", string $assets_url = "", string $templates_dir = "" ) {
        $this->menu_id      = $menu_id ? $menu_id : "mi-integracion-api";
        $this->assets_url   = $assets_url ? $assets_url : MiIntegracionApi_PLUGIN_URL . "assets/";
        $this->templates_dir = $templates_dir ? $templates_dir : MiIntegracionApi_PLUGIN_DIR . "templates/admin/";
        $this->logger       = new LoggerBasic("admin_menu");
        $this->modules      = Module_Loader::get_available_modules();
    }

    /**
     * Inicializa la clase y registra los hooks con WordPress.
     *
     * Registra las acciones necesarias para el menú de administración:
     * - Agrega las páginas del menú
     * - Registra los scripts y estilos del administrador
     * - Añade enlaces de acción en la lista de plugins
     *
     * @since 1.0.0
     * @return void
     * @hook admin_menu
     * @hook admin_enqueue_scripts
     * @hook plugin_action_links_{$plugin_file}
     *
     * @example
     * ```php
     * $admin_menu = new AdminMenu();
     * $admin_menu->init();
     * ```
     */
    public function init(): void {
        add_action( "admin_menu", array( $this, "add_menu_pages" ) );
        add_action( "admin_enqueue_scripts", array( $this, "enqueue_admin_scripts" ) );
        add_filter( "plugin_action_links_mi-integracion-api/mi-integracion-api.php", array( $this, "add_plugin_links" ) );
    }

    /**
     * Añade enlaces adicionales en la página de plugins de WordPress.
     *
     * Este método se ejecuta a través del filtro 'plugin_action_links_{$plugin_file}'
     * y agrega un enlace directo a la página de configuración del plugin.
     *
     * @since 1.0.0
     * @param array $links Array de enlaces actuales en la lista de plugins.
     *
     * @return array Array de enlaces actualizados para mostrar en la lista de plugins.
     *
     * @filter plugin_action_links_{$plugin_file}
     *
     * @example
     * ```php
     * // Filtro que llama a este método
     * add_filter('plugin_action_links_mi-plugin/mi-plugin.php', [$adminMenu, 'add_plugin_links']);
     * ```
     */
    public function add_plugin_links( array $links ): array {
        try {
            $plugin_links = array(
                "<a href=\"" . admin_url( "admin.php?page={$this->menu_id}" ) . "\">" . __( "Configuración", "mi-integracion-api" ) . "</a>",
            );
            $merged_links = array_merge( $plugin_links, $links );
            
            // Log para debugging
            $this->logger->debug( 'Enlaces de plugin procesados correctamente', [
                'original_count' => count($links),
                'final_count' => count($merged_links),
                'menu_id' => $this->menu_id
            ]);
            
            return $merged_links;
        } catch ( \Exception $e ) {
            $this->logger->error( 'Error procesando enlaces de plugin: ' . $e->getMessage() );
            
            // En caso de error, devolver los enlaces originales
            return $links;
        }
    }

    /**
     * Registra los menús y submenús en el área de administración de WordPress.
     *
     * Este método se ejecuta a través del hook 'admin_menu' y es responsable de:
     * 1. Registrar el menú principal del plugin
     * 2. Registrar todas las subpáginas relacionadas
     * 3. Configurar los permisos y capacidades necesarias
     *
     * @since 1.0.0
     * @return void
     * @hook admin_menu
     * @see add_menu_page()
     * @see add_submenu_page()
     * @see current_user_can()
     *
     * @example
     * ```php
     * // En el constructor o método init()
     * add_action('admin_menu', [$this, 'add_menu_pages']);
     * ```
     */
    public function add_menu_pages(): void {
        // Menú principal
        add_menu_page(
            __( "Mi Integración API", "mi-integracion-api" ),
            __( "Mi Integración API", "mi-integracion-api" ),
            "manage_options",
            $this->menu_id,
            array( $this, "display_dashboard_page" ),
            plugin_dir_url( __FILE__ ) . '../../assets/images/logo-16.png',
            100
        );

        // COMENTADO: Menú de Logs
        /*
        // Menú de Logs
        add_submenu_page(
            $this->menu_id,
            __( "Logs", "mi-integracion-api" ),
            __( "Logs", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-logs",
            array( $this, "display_logs_page" )
        );
        */

        // Menú de Endpoints
        add_submenu_page(
            $this->menu_id,
            __( "Endpoints", "mi-integracion-api" ),
            __( "Endpoints", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-endpoints",
            array( $this, "display_endpoints_page" )
        );

        // Menú de Tests de Desarrollo
        add_submenu_page(
            $this->menu_id,
            __( "Tests de Desarrollo", "mi-integracion-api" ),
            __( "Tests de Desarrollo", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-tests",
            array( $this, "display_test_page" )
        );

        // Menú de Caché
        add_submenu_page(
            $this->menu_id,
            __( "Caché", "mi-integracion-api" ),
            __( "Caché", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-cache",
            array( $this, "display_cache_page" )
        );

        // COMENTADO: Menú de Historial de Sincronización
        /*
        add_submenu_page(
            $this->menu_id,
            __( "Historial de Sincronización", "mi-integracion-api" ),
            __( "Historial de Sincronización", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-sync-history",
            array( $this, "display_sync_history_page" )
        );
        */

        // Añadir herramienta para actualizar nombres de categorías
        // COMENTADO: Menú de Actualizar Nombres Categorías
        /*
        add_submenu_page(
            $this->menu_id,
            __( "Actualizar Nombres Categorías", "mi-integracion-api" ),
            __( "Actualizar Nombres Categorías", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-update-category-names",
            array( $this, "display_update_category_names_page" )
        );
        */

        // COMENTADO: Menú de Informe de Compatibilidad
        /*
        add_submenu_page(
            $this->menu_id,
            __( "Informe de Compatibilidad", "mi-integracion-api" ),
            __( "Informe de Compatibilidad", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-compatibility-report",
            array( $this, "display_compatibility_report_page" )
        );
        */

        // Panel de configuración de reintentos
        add_submenu_page(
            $this->menu_id,
            __( "Configuración de Reintentos", "mi-integracion-api" ),
            __( "Reintentos", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-retry-settings",
            array( $this, "display_retry_settings_page" )
        );

        // Panel de monitoreo de memoria
        add_submenu_page(
            $this->menu_id,
            __( "Monitoreo de Memoria", "mi-integracion-api" ),
            __( "Memoria", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-memory-monitoring",
            array( $this, "display_memory_monitoring_page" )
        );

        // Panel de sincronización de pedidos (submenú del plugin principal)
        add_submenu_page(
            $this->menu_id,
            __( "Sincronización de Pedidos", "mi-integracion-api" ),
            __( "Pedidos", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-order-sync",
            array( $this, "display_order_sync_page" )
        );

        // COMENTADO: Menú de Prueba HPOS
        /*
        add_submenu_page(
            $this->menu_id,
            __( "Prueba HPOS", "mi-integracion-api" ),
            __( "Prueba HPOS", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-hpos-test",
            array( $this, "display_hpos_test_page" )
        );
        */

        // COMENTADO: Menú de Mapeo
        /*
        add_submenu_page(
            $this->menu_id,
            __( "Mapeo", "mi-integracion-api" ),
            __( "Mapeo", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-mapping",
            array( $this, "display_mapping_page" )
           );
        */
         
        
        // Menú de Registro de Errores
        // COMENTADO: Menú de Registro de Errores
        /*
        add_submenu_page(
            $this->menu_id,
            __( 'Registro de Errores', 'mi-integracion-api' ),
            __( 'Registro de Errores', 'mi-integracion-api' ),
            'manage_options',
            "{$this->menu_id}-sync-errors",
            array( $this, 'display_sync_errors_page' )
           );        
        */
    }

    /**
     * Muestra la página de dashboard.
     *
     * @since 1.0.0
     */
    public function display_dashboard_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\DashboardPageView" ) ) {
            \MiIntegracionApi\Admin\DashboardPageView::render_dashboard();
        } elseif ( class_exists( "MiIntegracionApi\\Admin\\DashboardPage" ) ) {
            $dashboard = new \MiIntegracionApi\Admin\DashboardPage();
            $dashboard->render();
        } else {
            $this->render_page( "dashboard" );
        }
    }

    /**
     * Muestra la página de configuración.
     *
     * @since 1.0.0
     */


    /**
     * Muestra la página de logs.
     *
     * @since 1.0.0
     */
    public function display_logs_page() {
    	if ( class_exists( "MiIntegracionApi\\Admin\\LogsPage" ) ) {
    		\MiIntegracionApi\Admin\LogsPage::render();
    	} else {
    		// Fallback por si la clase no existe, aunque no debería ocurrir.
    		$this->render_page( "logs" );
    	}
    }

    /**
     * Página de diagnóstico API eliminada - funcionalidad duplicada innecesaria
     * La configuración y conectividad se valida directamente desde ApiConnector
     * 
     * @since 1.0.0
     * @deprecated Eliminado en v2.0 - funcionalidad integrada en ApiConnector
     */

    /**
     * Muestra la página de endpoints.
     *
     * @since 1.0.0
     */
    public function display_endpoints_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\EndpointsPage" ) ) {
            \MiIntegracionApi\Admin\EndpointsPage::render();
        } else {
            $this->render_page( "endpoints" );
        }
    }

    /**
     * Muestra la página de tests de desarrollo.
     *
     * @since 1.5.0
     */
    public function display_test_page(): void {
        if (class_exists("MiIntegracionApi\\Admin\\TestPage")) {
            \MiIntegracionApi\Admin\TestPage::render();
        } else {
            echo '<div class="wrap"><h1>Tests de Desarrollo</h1><p>La clase TestPage no está disponible.</p></div>';
        }
    }

    /**
     * Muestra la página de caché.
     *
     * @since 1.0.0
     */
    public function display_cache_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\CachePageView" ) ) {
            \MiIntegracionApi\Admin\CachePageView::render_cache();
        } else {
            $this->render_page( "cache" );
        }
    }


    /**
     * Muestra la página de historial de sincronización.
     *
     * @since 1.0.0
     */
    public function display_sync_history_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\SyncHistoryPageView" ) ) {
            \MiIntegracionApi\Admin\SyncHistoryPageView::render_sync_history();
        } else {
            $this->render_page( "sync-history" );
        }
    }

    /**
     * Muestra la página de informe de compatibilidad.
     *
     * @since 1.0.0
     */
    public function display_compatibility_report_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\CompatibilityReportPageView" ) ) {
            \MiIntegracionApi\Admin\CompatibilityReportPageView::render_report();
        } else {
            $this->render_page( "compatibility-report" );
        }
    }

    /**
     * Muestra la página de prueba HPOS.
     *
     * @since 1.0.0
     */
    public function display_hpos_test_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\HposTestPageView" ) ) {
            \MiIntegracionApi\Admin\HposTestPageView::render_test();
        } else {
            $this->render_page( "hpos-test" );
        }
    }

    /**
     * Muestra la página de mapeo.
     *
     * @since 1.0.0
     */
    public function display_mapping_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\MappingPageView" ) ) {
            \MiIntegracionApi\Admin\MappingPageView::render_mapping();
        } else {
            $this->render_page( "mapping" );
        }
    }

    /**
     * Muestra la página de configuración de reintentos.
     *
     * @since 1.0.0
     */
    public function display_retry_settings_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\RetrySettingsManager" ) ) {
            $retry_settings = new \MiIntegracionApi\Admin\RetrySettingsManager();
            $retry_settings->render_settings_page();
        } else {
            $this->render_page( "retry-settings" );
        }
    }

    /**
     * Muestra la página de monitoreo de memoria.
     *
     * @since 1.0.0
     */
    public function display_memory_monitoring_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\MemoryMonitoringManager" ) ) {
            $memory_monitoring = new \MiIntegracionApi\Admin\MemoryMonitoringManager();
            $memory_monitoring->render_settings_page();
        } else {
            $this->render_page( "memory-monitoring" );
        }
    }
   
   
    /**
     * Muestra la página de registro de errores de sincronización.
     *
     * @since 1.1.0
     */
    public function display_sync_errors_page() {
    	if ( class_exists( "MiIntegracionApi\\Admin\\SyncErrorsPage" ) ) {
    		\MiIntegracionApi\Admin\SyncErrorsPage::render();
    	} else {
    		$this->render_page( "sync-errors" );
    	}
    }

    /**
     * Muestra la página de sincronización de pedidos.
     *
     * @since 1.2.0
     */
    public function display_order_sync_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\OrderSyncDashboard" ) ) {
            $order_sync_dashboard = \MiIntegracionApi\Admin\OrderSyncDashboard::get_instance();
            $order_sync_dashboard->render_order_sync_page();
        } else {
            $this->render_page( "order-sync" );
        }
    }
   
   
    /**
     * Renderiza una plantilla de página de administración.
     *
     * @since 1.0.0
     * @param    string    $template_name    Nombre de la plantilla a renderizar.
     * @return   \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
     */
    public function render_page( string $template_name = "dashboard" ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
        $template_path = $this->templates_dir . $template_name . '.php';
        if ( file_exists( $template_path ) ) {
            // Renderizar la plantilla
            ob_start();
            include $template_path;
            $content = ob_get_clean();
            
            return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
                [
                    'template_name' => $template_name,
                    'template_path' => $template_path,
                    'content' => $content,
                    'rendered' => true
                ],
                'Plantilla renderizada correctamente',
                [
                    'endpoint' => 'AdminMenu::render_page',
                    'template_name' => $template_name,
                    'template_path' => $template_path,
                    'timestamp' => time()
                ]
            );
        } else {
            $this->logger->error( 'Template not found: ' . $template_path );
            $error_message = esc_html__( 'Error: No se encontró la plantilla de la página.', 'mi-integracion-api' );
            
            return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
                $error_message,
                404,
                [
                    'endpoint' => 'AdminMenu::render_page',
                    'error_code' => 'template_not_found',
                    'template_name' => $template_name,
                    'template_path' => $template_path,
                    'timestamp' => time()
                ]
            );
        }
    }

    /**
     * Renderiza la cabecera de la página de administración.
     *
     * @since 1.0.0
     */
    private function render_header(): void {
        include $this->templates_dir . 'header.php';
    }

    /**
     * Renderiza el pie de página de la administración.
     *
     * @since 1.0.0
     */
    private function render_footer(): void {
        include $this->templates_dir . 'footer.php';
    }

    /**
     * Obtiene el título de la página actual.
     *
     * @since 1.0.0
     * @return   string    Título de la página.
     */
    private function get_current_page_title(): string {
        $page_titles = array(
            $this->menu_id                                => __( 'Dashboard', 'mi-integracion-api' ),

            "{$this->menu_id}-logs"                      => __( 'Logs', 'mi-integracion-api' ),
            // "{$this->menu_id}-api-diagnostic"            => __( 'Diagnóstico API', 'mi-integracion-api' ), // ELIMINADO - funcionalidad duplicada
            "{$this->menu_id}-endpoints"                 => __( 'Endpoints', 'mi-integracion-api' ),
            "{$this->menu_id}-cache"                     => __( 'Caché', 'mi-integracion-api' ),
            "{$this->menu_id}-sync-history"              => __( 'Historial de Sincronización', 'mi-integracion-api' ),
            "{$this->menu_id}-compatibility-report"      => __( 'Informe de Compatibilidad', 'mi-integracion-api' ),

            "{$this->menu_id}-hpos-test"                 => __( 'Prueba HPOS', 'mi-integracion-api' ),
            "{$this->menu_id}-mapping"                   => __( 'Mapeo', 'mi-integracion-api' ),
        );

        // SEGURIDAD: Usar SecurityValidator para validar parámetro GET page
        $current_page = \MiIntegracionApi\Helpers\SecurityValidator::validateGetParam( 'page', 'text', $this->menu_id );
        return $page_titles[ $current_page ] ?? __( 'Mi Integración API', 'mi-integracion-api' );
    }

    /**
     * Encola los scripts y estilos de administración condicionalmente.
     *
     * @since 1.0.0
     * @param    string    $hook_suffix    El hook de la página actual.
     */
    public function enqueue_admin_scripts( string $hook_suffix ): void {
        // NO cargar dashboard.js aquí - se carga condicionalmente desde Assets.php
        // Solo se carga en la página principal del plugin (toplevel_page_mi-integracion-api)



        // Script para la página de logs
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-logs' ) {
            wp_enqueue_script(
                'mi-integracion-api-logs-viewer-main',
                $this->assets_url . 'js/logs-viewer-main.js',
                array( 'jquery', 'mi-integracion-api-utils' ),
                constant('MiIntegracionApi_VERSION'),
                true
            );
            // ✅ ELIMINADO: Log innecesario de enqueue individual
        }

        // ELIMINADO: Script para la página de diagnóstico API - funcionalidad duplicada innecesaria
        // if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-api-diagnostic' ) {
        //     wp_enqueue_script(
        //         'mi-integracion-api-api-diagnostic',
        //         $this->assets_url . 'js/api-diagnostic.js',
        //         array( 'jquery', 'mi-integracion-api-admin-main' ),
        //         constant('MiIntegracionApi_VERSION'),
        //         true
        //     );
        //     $this->logger->info('Enqueued api-diagnostic.js');
        // }

        // Script para la página de endpoints
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-endpoints' ) {
            wp_enqueue_script(
                'mi-integracion-api-endpoints',
                $this->assets_url . 'js/endpoints-page.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                constant('MiIntegracionApi_VERSION'),
                true
            );
            
            // Select2 no es necesario - el select nativo tiene estilos modernos
            
            // ✅ ELIMINADO: Log innecesario de enqueue individual
        }

        // Script y estilos para la página de caché
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-cache' ) {
            wp_enqueue_style(
                'mi-integracion-api-cache-admin',
                $this->assets_url . 'css/cache-admin.css',
                array(),
                MiIntegracionApi_VERSION
            );
            
            wp_enqueue_script(
                'mi-integracion-api-cache-admin',
                $this->assets_url . 'js/cache-admin.js',
                array( 'jquery' ),
                constant('MiIntegracionApi_VERSION'),
                true
            );
        }

        // Script y estilos para la página de reintentos
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-retry-settings' ) {
            wp_enqueue_style(
                'mi-integracion-api-retry-settings',
                $this->assets_url . 'css/retry-settings.css',
                array(),
                MiIntegracionApi_VERSION
            );
            
            wp_enqueue_script(
                'mi-integracion-api-retry-settings',
                $this->assets_url . 'js/retry-settings.js',
                array( 'jquery' ),
                constant('MiIntegracionApi_VERSION'),
                true
            );
        }

        // Script y estilos para la página de monitoreo de memoria
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-memory-monitoring' ) {
            wp_enqueue_style(
                'mi-integracion-api-memory-monitoring',
                $this->assets_url . 'css/memory-monitoring.css',
                array(),
                MiIntegracionApi_VERSION
            );
            
            wp_enqueue_script(
                'mi-integracion-api-memory-monitoring',
                $this->assets_url . 'js/memory-monitoring.js',
                array( 'jquery' ),
                constant('MiIntegracionApi_VERSION'),
                true
            );
            
            // Localizar variables para JavaScript
            wp_localize_script(
                'mi-integracion-api-memory-monitoring',
                'miIntegracionApiMemory',
                array(
                    'ajaxurl' => admin_url( 'admin-ajax.php' ),
                    'nonce' => wp_create_nonce( 'mia_memory_nonce' ),
                    'refreshInterval' => get_option( 'mia_memory_dashboard_refresh_interval', 30 )
                )
            );
        }

        // Script para la página de informe de compatibilidad
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-compatibility-report' ) {
            wp_enqueue_script(
                'mi-integracion-api-compatibility-report',
                $this->assets_url . 'js/compatibility-report.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                constant('MiIntegracionApi_VERSION'),
                true
            );
            // ✅ ELIMINADO: Log innecesario de enqueue individual
        }



        // Script para la página de prueba HPOS
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-hpos-test' ) {
            wp_enqueue_script(
                'mi-integracion-api-hpos-test',
                $this->assets_url . 'js/hpos-test.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                constant('MiIntegracionApi_VERSION'),
                true
            );
            // ✅ ELIMINADO: Log innecesario de enqueue individual
        }


        // Scripts que se aplican a todas las páginas de administración del plugin
        if ( strpos( $hook_suffix, 'mi-integracion-api' ) !== false ) {
            // ✅ ELIMINADO: Log innecesario de enqueue individual

            // Script para lazy-components.js
            wp_enqueue_script(
                'mi-integracion-api-lazy-components',
                $this->assets_url . 'js/lazy-components.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                constant('MiIntegracionApi_VERSION'),
                true
            );
            // ✅ ELIMINADO: Log innecesario de enqueue individual
        }
    }
}
