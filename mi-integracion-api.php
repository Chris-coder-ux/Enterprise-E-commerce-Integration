<?php
/**
 * Plugin Name: Enterprise E-commerce Integration
 * Plugin URI: https://www.verialerp.com
 * Description: Plugin para la integración con Verial ERP
 * Version: 2.0.0
 * Author: Christian
 * Author URI: https://www.verialerp.com
 * Text Domain: mi-integracion-api
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

namespace MiIntegracionApi;

// Prevención de acceso directo al archivo
if (! defined('ABSPATH')) {
    exit;
}

// Prevención de carga múltiple
if (defined('MiIntegracionApi_LOADED')) {
    // Plugin ya inicializado
    return;
}

// Marcador de inicialización
define('MiIntegracionApi_LOADED', true);

/**
 * Verifica si WooCommerce está activo y completamente funcional
 *
 * Realiza una verificación completa del estado de WooCommerce, incluyendo
 * verificación de plugins activos, soporte para multisite, detección de
 * modo mantenimiento y validación de clase. Esta función es crítica para
 * la inicialización segura del plugin.
 *
 * @function
 * @name check_woocommerce_active
 * @description Verifica el estado de WooCommerce para la inicialización del plugin
 * @return bool True si WooCommerce está activo y funcional, false en caso contrario
 * @since 1.0.0
 * @author Christian
 *
 * @uses get_option() Para obtener plugins activos
 * @uses is_multisite() Para detectar instalaciones multisite
 * @uses get_site_option() Para obtener plugins de red en multisite
 * @uses class_exists() Para verificar que la clase WooCommerce esté disponible
 * @uses function_exists() Para verificar funciones de WooCommerce
 * @uses wc_is_maintenance_mode() Para detectar modo mantenimiento
 * @uses add_action() Para mostrar notificaciones de error
 * @uses esc_html__() Para internacionalización de mensajes
 */
function check_woocommerce_active(): bool
{
    // Verificación de dependencias

    $active_plugins = (array) get_option('active_plugins', []);

    // Verificación de plugins activos para WooCommerce
    $woocommerce_active = in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);

    // Verificación de plugins de red para multisite
    if (! $woocommerce_active && is_multisite()) {
        $active_network_plugins = (array) get_site_option('active_sitewide_plugins', []);
        $woocommerce_active     = in_array('woocommerce/woocommerce.php', $active_network_plugins) || isset($active_network_plugins['woocommerce/woocommerce.php']);
    }

    // Verificación adicional: WooCommerce debe estar completamente cargado
    if ($woocommerce_active) {
        // Verificación de clase WooCommerce
        if (! class_exists('WooCommerce')) {
            $woocommerce_active = false;
        }

        // Verificación de modo mantenimiento
        if (function_exists('wc_is_maintenance_mode') && wc_is_maintenance_mode()) {
            $woocommerce_active = false;
        }
    }

    // Manejo de WooCommerce inactivo
    if (! $woocommerce_active) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . esc_html__('Mi Integración API requiere WooCommerce', 'mi-integracion-api') . '</strong><br>';
            echo esc_html__('Por favor, instale y active WooCommerce antes de usar este plugin.', 'mi-integracion-api');
            echo '</p></div>';
        });

        // Registro de error en logger
        if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
            $logger = new \MiIntegracionApi\Logging\Core\Logger('dependency_check');
            $logger->error('WooCommerce no está activo o funcional. El plugin no puede inicializarse.');
        }
    }

    return $woocommerce_active;
}

// Las constantes se definen más abajo, antes del sistema de autoloading

// Definir constantes del plugin ANTES del sistema de autoloading
if (! defined('MiIntegracionApi_VERSION')) {
    define('MiIntegracionApi_VERSION', '2.0.0');
}

if (! defined('MiIntegracionApi_PLUGIN_DIR')) {
    define('MiIntegracionApi_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (! defined('MiIntegracionApi_PLUGIN_URL')) {
    define('MiIntegracionApi_PLUGIN_URL', plugin_dir_url(__FILE__));
}

if (! defined('MiIntegracionApi_PATH')) {
    define('MiIntegracionApi_PATH', plugin_dir_path(__FILE__)); // Añadida esta constante para compatibilidad
}

if (! defined('MiIntegracionApi_OPTION_PREFIX')) {
    define('MiIntegracionApi_OPTION_PREFIX', 'mi_integracion_api_');
}

if (! defined('MiIntegracionApi_DB_VERSION')) {
    define('MiIntegracionApi_DB_VERSION', '2.0.0');
}

if (! defined('MiIntegracionApi_CACHE_VERSION')) {
    define('MiIntegracionApi_CACHE_VERSION', '2.0.0');
}

if (! defined('MiIntegracionApi_MIN_PHP_VERSION')) {
    define('MiIntegracionApi_MIN_PHP_VERSION', '8.0');
}

if (! defined('MiIntegracionApi_MIN_WP_VERSION')) {
    define('MiIntegracionApi_MIN_WP_VERSION', '6.0');
}

if (! defined('MiIntegracionApi_MIN_WC_VERSION')) {
    define('MiIntegracionApi_MIN_WC_VERSION', '7.0');
}

if (! defined('MiIntegracionApi_TEXT_DOMAIN')) {
    define('MiIntegracionApi_TEXT_DOMAIN', 'mi-integracion-api');
}

if (! defined('MiIntegracionApi_PLUGIN_BASENAME')) {
    define('MiIntegracionApi_PLUGIN_BASENAME', plugin_basename(__FILE__));
}

if (! defined('MiIntegracionApi_NONCE_PREFIX')) {
    define('MiIntegracionApi_NONCE_PREFIX', 'mi_integracion_api_nonce_'); // Constante para prefijo de nonces
}

if (! defined('MiIntegracionApi_PLUGIN_FILE')) {
    define('MiIntegracionApi_PLUGIN_FILE', __FILE__); // Constante para el archivo principal del plugin
}

if (! defined('MiIntegracionApi_INIT_TIMESTAMP')) {
    define('MiIntegracionApi_INIT_TIMESTAMP', time()); // Timestamp de carga del plugin para debugging
}

/**
 * Sistema de carga de autoloaders - ARQUITECTURA DE 3 NIVELES
 *
 * Nivel 1: ComposerAutoloader (Primario - Optimizado)
 * Nivel 2: SmartAutoloader (Respaldo - Inteligente con cache)
 * Nivel 3: EmergencyLoader (Crítico - Mínimo para errores)
 */
static $autoloaders_loaded = false;

if (! $autoloaders_loaded) {
    try {
        // Cargar el AutoloaderManager que coordina todos los autoloaders
        require_once __DIR__ . '/includes/Core/AutoloaderManager.php';

        // Inicializar el sistema de autoloaders con arquitectura de 3 niveles
        \MiIntegracionApi\Core\AutoloaderManager::init();

        // Cargar funciones globales de compatibilidad de transients
        require_once __DIR__ . '/includes/Helpers/TransientCompatibility.php';

        // Cargar funciones globales seguras del plugin
        require_once __DIR__ . '/includes/functions_safe.php';

        $autoloaders_loaded = true;

    } catch (\Throwable $e) {
        // Log del error crítico
        if (function_exists('error_log')) {
            error_log('[MiIntegracionApi] ERROR CRÍTICO AutoloaderManager: ' . $e->getMessage());
        }

        // Mostrar notificación de error crítico
        add_action('admin_notices', function () use ($e) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>Mi Integración API - Error Crítico:</strong> ';
            echo esc_html($e->getMessage());
            echo '</p>';
            echo '<p><small>';
            echo esc_html__('Contacte al soporte técnico inmediatamente.', 'mi-integracion-api');
            echo '</small></p>';
            echo '</div>';
        });

        $autoloaders_loaded = true; // Marcar como cargado para evitar bucles
    }

    // CORRECCIÓN CRÍTICA: Inicializar EmergencyLoader SIEMPRE como respaldo
    // Esto garantiza que VerialApiConfig esté disponible incluso si AutoloaderManager falla
    if (file_exists(__DIR__ . '/includes/Core/EmergencyLoader.php')) {
        require_once __DIR__ . '/includes/Core/EmergencyLoader.php';
        \MiIntegracionApi\Core\EmergencyLoader::init();
    }
}

/**
 * Sistema de inicialización del plugin con verificación de dependencias
 *
 * Maneja la inicialización principal del plugin con verificación de dependencias,
 * prevención de inicialización duplicada y manejo robusto de errores. Esta función
 * es el punto de entrada principal para la inicialización del plugin.
 *
 * @function
 * @name init_plugin
 * @description Inicialización principal del plugin con verificación de dependencias
 * @return void
 * @since 1.0.0
 * @author Christian
 *
 * @uses check_woocommerce_active() Para verificar dependencia de WooCommerce
 * @uses class_exists() Para verificar que WooCommerce esté cargado
 * @uses did_action() Para verificar que WooCommerce esté completamente inicializado
 * @uses add_action() Para programar inicialización tardía si es necesario
 * @uses init_plugin_core() Para inicializar el núcleo del plugin
 * @uses handle_plugin_init_error() Para manejo de errores de inicialización
 *
 * @throws \Throwable Captura cualquier error durante la inicialización
 */
if (! function_exists(__NAMESPACE__ . '\\init_plugin')) {
    function init_plugin()
    {
        // Evitar inicialización duplicada
        static $initialization_attempted = false;

        if ($initialization_attempted) {
            return;
        }

        $initialization_attempted = true;

        try {
            // ✅ NUEVO: Registrar schedules de cron ANTES de verificar dependencias
            // Esto evita el error "invalid_schedule" para wp_background_process_cron
            add_filter('cron_schedules', __NAMESPACE__ . '\\add_background_process_cron_schedules');

            // PHASE 1: Verificación de dependencias críticas
            if (! check_woocommerce_active()) {
                return;
            }

            // PHASE 2: Verificación de que WooCommerce esté completamente cargado
            if (! class_exists('WooCommerce') || ! did_action('woocommerce_loaded')) {
                // Programación de inicialización tardía
                add_action('woocommerce_loaded', __NAMESPACE__ . '\\init_plugin_after_woocommerce');
                return;
            }

            // PHASE 3: Inicialización del plugin (WooCommerce ya está listo)
            init_plugin_core();

        } catch (\Throwable $e) {
            handle_plugin_init_error($e);
        }
    }
}

/**
 * Inicialización del núcleo del plugin después de que WooCommerce esté listo
 *
 * Esta función se ejecuta cuando WooCommerce está completamente cargado,
 * garantizando que todas las dependencias estén disponibles antes de
 * inicializar el plugin. Incluye prevención de inicialización duplicada
 * y manejo robusto de errores.
 *
 * @function
 * @name init_plugin_after_woocommerce
 * @description Inicialización del plugin después de que WooCommerce esté listo
 * @return void
 * @since 1.0.0
 * @author Christian
 *
 * @uses init_plugin_core() Para inicializar el núcleo del plugin
 * @uses do_action() Para marcar el plugin como inicializado
 * @uses handle_plugin_init_error() Para manejo de errores de inicialización
 * @uses did_action() Para verificar si ya se ha inicializado
 *
 * @throws \Throwable Captura cualquier error durante la inicialización
 */
if (! function_exists(__NAMESPACE__ . '\\init_plugin_after_woocommerce')) {
    function init_plugin_after_woocommerce()
    {
        // PREVENCIÓN CRÍTICA: Evitar inicialización duplicada
        static $woocommerce_init_attempted = false;

        if ($woocommerce_init_attempted) {
            return;
        }

        $woocommerce_init_attempted = true;

        // Prevención de inicialización múltiple
        if (did_action('mi_integracion_api_initialized')) {
            return;
        }

        // Inicialización del núcleo del plugin

        try {
            init_plugin_core();

            // Marcar como inicializado
            do_action('mi_integracion_api_initialized');

        } catch (\Throwable $e) {
            handle_plugin_init_error($e);
        }
    }
}

/**
 * Inicialización del núcleo del plugin con todas las funcionalidades
 *
 * Esta función es responsable de inicializar todos los componentes
 * principales del plugin, incluyendo el logger, la clase principal,
 * handlers AJAX, y configuraciones de reintentos. Es el punto central
 * de inicialización del plugin.
 *
 * @function
 * @name init_plugin_core
 * @description Inicialización del núcleo del plugin con todas las funcionalidades
 * @return void
 * @since 1.0.0
 * @author Christian
 *
 * @uses \MiIntegracionApi\Logging\Core\Logger Para el sistema de logging
 * @uses class_exists() Para verificar que las clases requeridas estén disponibles
 * @uses \MiIntegracionApi\Core\MiIntegracionApi::get_instance() Para obtener la instancia del plugin
 * @uses \MiIntegracionApi\Admin\AjaxDashboard::init() Para inicializar el dashboard AJAX
 * @uses \MiIntegracionApi\Admin\AjaxSync::init() Para inicializar la sincronización AJAX
 * @uses \MiIntegracionApi\Helpers\MapProduct::register_auto_update_hooks() Para registrar hooks de actualización
 * @uses \MiIntegracionApi\Admin\RetrySettingsManager Para gestionar configuraciones de reintentos
 *
 * @throws \Exception Si la clase principal del plugin no está disponible
 */
if (! function_exists(__NAMESPACE__ . '\\init_plugin_core')) {
    function init_plugin_core()
    {
        // PREVENCIÓN CRÍTICA: Evitar inicialización duplicada del núcleo
        static $core_initialization_attempted = false;
        if ($core_initialization_attempted) {
            return; // Ya se inicializó el núcleo
        }
        $core_initialization_attempted = true;

        // El Logger se instancia directamente cuando se necesita, no requiere inicialización previa

        // Verificación de clase principal
        if (! class_exists('\\MiIntegracionApi\\Core\\MiIntegracionApi')) {
            throw new \Exception(__('Clase principal del plugin no encontrada. El plugin no puede inicializarse.', 'mi-integracion-api'));
        }

        // Usar el patrón singleton para obtener la instancia única del plugin
        \MiIntegracionApi\Core\MiIntegracionApi::get_instance();

        // Inicializar handlers AJAX para el dashboard
        if (class_exists('MiIntegracionApi\\Admin\\AjaxDashboard')) {
            \MiIntegracionApi\Admin\AjaxDashboard::init();
        }

        // Inicializar handlers AJAX principales (sincronización)
        if (class_exists('MiIntegracionApi\\Admin\\AjaxSync')) {
            \MiIntegracionApi\Admin\AjaxSync::init();
        } else {
            if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
                $logger = new \MiIntegracionApi\Logging\Core\Logger('plugin_init');
                $logger->error('Clase AjaxSync no encontrada. Los endpoints AJAX de sincronización no estarán disponibles.');
            }
        }

        // Inicializar Dashboard de sincronización de pedidos
        if (class_exists('MiIntegracionApi\\Admin\\OrderSyncDashboard')) {
            \MiIntegracionApi\Admin\OrderSyncDashboard::get_instance();
        }

        // Ejecutar migraciones de base de datos
        if (class_exists('MiIntegracionApi\\Admin\\DatabaseMigration')) {
            \MiIntegracionApi\Admin\DatabaseMigration::runMigrations();
        }

        // Inicializar Dashboard de Detección Automática
        if (class_exists('MiIntegracionApi\\Admin\\DetectionDashboard')) {
            \MiIntegracionApi\Admin\DetectionDashboard::getInstance();
        } else {
            // Intentar cargar manualmente si el autoloader no lo hizo
            $detection_dashboard_file = __DIR__ . '/includes/Admin/DetectionDashboard.php';
            if (file_exists($detection_dashboard_file)) {
                require_once $detection_dashboard_file;
                if (class_exists('MiIntegracionApi\\Admin\\DetectionDashboard')) {
                    \MiIntegracionApi\Admin\DetectionDashboard::getInstance();
                } else {
                    if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
                        $logger = new \MiIntegracionApi\Logging\Core\Logger('plugin_init');
                        $logger->error('Error cargando DetectionDashboard: clase no encontrada después de require_once');
                    }
                }
            } else {
                if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
                    $logger = new \MiIntegracionApi\Logging\Core\Logger('plugin_init');
                    $logger->warning('Archivo DetectionDashboard.php no encontrado. El dashboard de detección automática no estará disponible.');
                }
            }
        }

        // Registrar hooks para actualización automática de nombres de categorías
        if (class_exists('MiIntegracionApi\\Helpers\\MapProduct')) {
            \MiIntegracionApi\Helpers\MapProduct::register_auto_update_hooks();
        }

        // Registrar hooks del sistema unificado (incluye renovación de nonces)
        if (class_exists('MiIntegracionApi\\Hooks\\UnifiedSystemHooks')) {
            \MiIntegracionApi\Hooks\UnifiedSystemHooks::register();
        } else {
            if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
                $logger = new \MiIntegracionApi\Logging\Core\Logger('plugin_init');
                $logger->warning('Clase UnifiedSystemHooks no encontrada. Los hooks del sistema unificado no estarán disponibles.');
            }
        }

        // Inicializar gestor de configuración de reintentos inteligente
        if (class_exists('\\MiIntegracionApi\\Admin\\RetrySettingsManager')) {
            $retry_settings = new \MiIntegracionApi\Admin\RetrySettingsManager();
            $retry_settings->init();
        } else {
            if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
                $logger = new \MiIntegracionApi\Logging\Core\Logger('plugin_init');
                $logger->warning('Clase RetrySettingsManager no encontrada. La configuración de reintentos no estará disponible.');
            }
        }

        // Inicializar gestor de monitoreo de memoria mejorado
        if (class_exists('\\MiIntegracionApi\\Admin\\MemoryMonitoringManager')) {
            $memory_monitoring = new \MiIntegracionApi\Admin\MemoryMonitoringManager();
            $memory_monitoring->init();
        } else {
            if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
                $logger = new \MiIntegracionApi\Logging\Core\Logger('plugin_init');
                $logger->warning('Clase MemoryMonitoringManager no encontrada. El monitoreo de memoria no estará disponible.');
            }
        }

        // Agregar favicon del plugin
        if (class_exists('MiIntegracionApi\\Helpers\\FaviconHelper')) {
            \MiIntegracionApi\Helpers\FaviconHelper::add_favicon();
        }

        // Confirmación de inicialización exitosa
        if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
            $logger = new \MiIntegracionApi\Logging\Core\Logger('plugin_init');
            // $logger->info('Plugin Mi Integración API inicializado correctamente después de WooCommerce');
        }
    }
}

/**
 * Manejo robusto de errores de inicialización
 *
 * Esta función proporciona un sistema centralizado para manejar errores
 * durante la inicialización del plugin. Registra errores en el logger,
 * muestra notificaciones de administración y mantiene un registro
 * detallado de excepciones para debugging.
 *
 * @function
 * @name handle_plugin_init_error
 * @description Manejo centralizado de errores de inicialización del plugin
 * @param \Throwable $e Excepción o error que ocurrió durante la inicialización
 * @return void
 * @since 1.0.0
 * @author Christian
 *
 * @uses \MiIntegracionApi\Helpers\Logger Para registrar errores en el sistema de logging
 * @uses is_admin() Para verificar si estamos en el área de administración
 * @uses add_action() Para mostrar notificaciones de error en el admin
 * @uses esc_html() Para escapar el mensaje de error de forma segura
 *
 * @throws \Throwable No lanza excepciones, solo las maneja
 */
if (! function_exists(__NAMESPACE__ . '\\handle_plugin_init_error')) {
    function handle_plugin_init_error(\Throwable $e)
    {
        // Registrar el error de forma segura
        if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
            $logger = new \MiIntegracionApi\Logging\Core\Logger('plugin_init');
            $logger->error('Error al inicializar Mi Integración API: ' . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
        }

        // Notificación de administración
        if (is_admin()) {
            add_action('admin_notices', function () use ($e) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Error en Mi Integración API:</strong> ' . esc_html($e->getMessage());
                echo '</p></div>';
            });
        }
    }
}

/**
 * Función de respaldo para inicialización tardía
 *
 * Esta función proporciona un mecanismo de respaldo para inicializar
 * el plugin cuando WooCommerce se carga muy tarde en el ciclo de vida
 * de WordPress. Garantiza que el plugin se inicialice incluso en
 * situaciones de carga tardía de dependencias.
 *
 * @function
 * @name init_plugin_fallback
 * @description Inicialización de respaldo para casos de carga tardía de WooCommerce
 * @return void
 * @since 1.0.0
 * @author Christian
 *
 * @uses class_exists() Para verificar que WooCommerce esté disponible
 * @uses did_action() Para verificar si ya se ha inicializado el plugin
 * @uses init_plugin_core() Para inicializar el núcleo del plugin
 * @uses do_action() Para marcar el plugin como inicializado
 * @uses \MiIntegracionApi\Helpers\Logger Para registrar la inicialización tardía
 * @uses handle_plugin_init_error() Para manejo de errores durante la inicialización
 *
 * @throws \Throwable Captura cualquier error durante la inicialización tardía
 */
if (! function_exists(__NAMESPACE__ . '\\init_plugin_fallback')) {
    function init_plugin_fallback()
    {
        // PREVENCIÓN CRÍTICA: Evitar inicialización duplicada
        static $fallback_attempted = false;

        if ($fallback_attempted) {
            return;
        }

        $fallback_attempted = true;

        // Verificación de estado de inicialización
        if (did_action('mi_integracion_api_initialized')) {
            return;
        }

        // Verificación de disponibilidad de WooCommerce
        if (class_exists('WooCommerce')) {
            try {
                // Inicializar el plugin de forma tardía
                init_plugin_core();

                // Marcar como inicializado
                do_action('mi_integracion_api_initialized');

                // Confirmación de inicialización tardía
                if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
                    $logger = new \MiIntegracionApi\Logging\Core\Logger('plugin_init');
                    // $logger->info('Plugin inicializado mediante fallback tardío en hook init');
                }

            } catch (\Throwable $e) {
                handle_plugin_init_error($e);
            }
        }
    }
}

/**
 * Sistema robusto de carga de traducciones
 *
 * Esta función carga las traducciones del plugin en el momento correcto
 * del ciclo de vida de WordPress para evitar warnings y asegurar que
 * las traducciones estén disponibles cuando se necesiten.
 *
 * @function
 * @name load_plugin_textdomain_on_init
 * @description Carga las traducciones del plugin en el momento correcto
 * @return void
 * @since 1.0.0
 * @author Christian
 *
 * @uses load_plugin_textdomain() Para cargar las traducciones del plugin
 * @uses plugin_basename() Para obtener la ruta base del plugin
 * @uses dirname() Para obtener el directorio del plugin
 *
 * @throws \Exception No lanza excepciones
 */
if (! function_exists(__NAMESPACE__ . '\\load_plugin_textdomain_on_init')) {
    function load_plugin_textdomain_on_init()
    {
        load_plugin_textdomain('mi-integracion-api', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
}

/**
 * Hook principal de inicialización del plugin
 * Usar plugins_loaded con prioridad alta para verificar dependencias temprano
 * pero no inicializar hasta que WooCommerce esté completamente cargado
 *
 * PRIORIDAD 5: Verificación temprana de dependencias
 * PRIORIDAD 10: Inicialización después de WooCommerce
 * PREVENCIÓN: Evitar registro múltiple de hooks principales
 */
static $main_hooks_registered = false;

if (! $main_hooks_registered) {
    add_action('plugins_loaded', __NAMESPACE__ . '\\init_plugin', 5);
    add_action('init', __NAMESPACE__ . '\\init_plugin_fallback', 5);

    $main_hooks_registered = true;

}

/**
 * Función global para obtener el servicio de criptografía
 *
 * Esta función proporciona una manera fácil y consistente de acceder
 * al servicio centralizado de criptografía desde cualquier parte del plugin.
 * Utiliza el patrón singleton para evitar instanciación múltiple.
 *
 * @function
 * @name mi_integracion_api_get_crypto
 * @description Obtiene el servicio de criptografía del plugin
 * @return \MiIntegracionApi\Core\CryptoService|null Instancia del servicio o null si no está disponible
 * @since 1.0.0
 * @author Christian
 *
 * @uses \MiIntegracionApi\Helpers\ApiHelpers::get_crypto() Para obtener la instancia del servicio
 *
 * @throws \Exception No lanza excepciones, puede retornar null
 */
function mi_integracion_api_get_crypto()
{
    return \MiIntegracionApi\Helpers\ApiHelpers::get_crypto();
}

/**
 * Función de activación para inicializar el plugin
 *
 * Esta función se ejecuta cuando el plugin se activa en WordPress.
 * Se encarga de crear tablas necesarias, verificar archivos críticos,
 * validar dependencias y registrar cualquier error de activación
 * para su posterior visualización.
 *
 * @function
 * @name plugin_activation
 * @description Inicialización del plugin durante la activación
 * @return void
 * @since 1.0.0
 * @author Christian
 *
 * @uses class_exists() Para verificar que las clases requeridas estén disponibles
 * @uses \MiIntegracionApi\Core\Installer::activate() Para activar el plugin usando el instalador
 * @uses \MiIntegracionApi\Core\Installer::create_product_mapping_table() Para crear tabla de mapeo
 * @uses global $wpdb Para acceso a la base de datos de WordPress
 * @uses file_exists() Para verificar que los archivos críticos estén presentes
 * @uses is_plugin_active() Para verificar que WooCommerce esté activo
 * @uses update_option() Para registrar errores de activación
 * @uses delete_option() Para limpiar errores de activación previos
 *
 * @throws \Throwable Captura cualquier error durante la activación
 */
function plugin_activation()
{
    // Inicializar EmergencyLoader para asegurar que las clases críticas estén disponibles
    if (class_exists('MiIntegracionApi\\Core\\EmergencyLoader')) {
        \MiIntegracionApi\Core\EmergencyLoader::init();
    }

    // Usar el instalador para configurar todo correctamente
    if (class_exists('MiIntegracionApi\\Core\\Installer')) {
        \MiIntegracionApi\Core\Installer::activate();
    } else {
        // Fallback para compatibilidad con versiones antiguas
        global $wpdb;
        $activation_errors = [];

        // 1. Verificar y crear tablas necesarias usando el Installer
        try {
            if (class_exists('MiIntegracionApi\\Core\\Installer')) {
                // Usar el Installer para crear la tabla de mapeo
                if (! \MiIntegracionApi\Core\Installer::create_product_mapping_table()) {
                    $activation_errors[] = 'No se pudo crear la tabla de mapeo Verial-WooCommerce.';
                }
            } else {
                $activation_errors[] = 'Clase Installer no encontrada. No se pueden crear las tablas necesarias.';
            }
        } catch (\Throwable $e) {
            $activation_errors[] = 'Error al crear la tabla de mapeo: ' . $e->getMessage();
        }
    }

    // 2. Verificar archivos críticos
    $critical_files = [
        'Module_Loader.php'    => __DIR__ . '/includes/Core/Module_Loader.php',
        'ApiConnector.php'     => __DIR__ . '/includes/Core/ApiConnector.php',
        'WooCommerceHooks.php' => __DIR__ . '/includes/WooCommerce/WooCommerceHooks.php',
        'Custom_Fields.php'    => __DIR__ . '/includes/WooCommerce/Custom_Fields.php',
    ];

    foreach ($critical_files as $name => $path) {
        if (! file_exists($path)) {
            $activation_errors[] = "Archivo crítico no encontrado: $name";
        }
    }

    // 3. Verificar dependencias
    if (! class_exists('WooCommerce') && function_exists('is_plugin_active')) {
        if (! is_plugin_active('woocommerce/woocommerce.php')) {
            $activation_errors[] = 'WooCommerce no está activo. Este plugin requiere WooCommerce para funcionar correctamente.';
        }
    }

    // Si hay errores, registrarlos y mostrarlos al activar
    if (! empty($activation_errors)) {
        update_option('mi_integracion_api_activation_errors', $activation_errors);
    } else {
        delete_option('mi_integracion_api_activation_errors');
    }
}

// Registrar el gancho de activación
register_activation_hook(__FILE__, __NAMESPACE__ . '\\plugin_activation');

/**
 * Mostrar errores de activación si existen
 *
 * Esta función se ejecuta en el área de administración para mostrar
 * cualquier error que haya ocurrido durante la activación del plugin.
 * Los errores se muestran como notificaciones de WordPress para
 * que el administrador pueda corregirlos.
 *
 * @function
 * @name display_activation_errors
 * @description Muestra errores de activación en el área de administración
 * @return void
 * @since 1.0.0
 * @author Christian
 *
 * @uses get_option() Para obtener los errores de activación registrados
 * @uses is_array() Para verificar que los errores sean un array válido
 * @uses esc_html() Para escapar el contenido de los errores de forma segura
 * @uses add_action() Para registrar la función en el hook admin_notices
 *
 * @throws \Exception No lanza excepciones
 */
function display_activation_errors()
{
    $activation_errors = get_option('mi_integracion_api_activation_errors');
    if (! empty($activation_errors) && is_array($activation_errors)) {
        echo '<div class="notice notice-error is-dismissible">';
        echo '<p><strong>Mi Integración API - Errores de activación:</strong></p>';
        echo '<ul>';
        foreach ($activation_errors as $error) {
            echo '<li>' . esc_html($error) . '</li>';
        }
        echo '</ul>';
        echo '<p>Por favor, corrige estos errores para usar el plugin correctamente.</p>';
        echo '</div>';
    }
}
add_action('admin_notices', __NAMESPACE__ . '\\display_activation_errors');

// Los hooks se inicializan automáticamente por el AutoloaderManager

// Los handlers AJAX, formularios y limpieza se manejan automáticamente por el AutoloaderManager

/**
 * Hook unificado para ejecutar limpieza automática de transients
 * CORRECCIÓN #7: Ejecución con logging robusto y manejo de errores
 * PREVENCIÓN: Evitar registro múltiple de hooks de limpieza automática
 */
static $cleanup_auto_hooks_registered = false;

if (! $cleanup_auto_hooks_registered) {
    add_action('mia_cleanup_transients', function () {
        try {
            // Usar la lógica centralizada de limpieza de RobustnessHooks
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                // Ejecutar limpieza diaria básica
                $daily_result = \MiIntegracionApi\Hooks\RobustnessHooks::executeScheduledCleanup('daily');

                // Ejecutar limpieza semanal profunda solo los domingos
                if (date('w') == 0) {
                    $weekly_result = \MiIntegracionApi\Hooks\RobustnessHooks::executeScheduledCleanup('weekly');
                }

                // Ejecutar limpieza por hora solo si es necesario (transients críticos)
                $current_hour = (int) date('H');
                if ($current_hour % 6 == 0) { // Cada 6 horas
                    $hourly_result = \MiIntegracionApi\Hooks\RobustnessHooks::executeScheduledCleanup('hourly');
                }

                // Logging centralizado
                if (class_exists('\\MiIntegracionApi\\Logging\\Core\\Logger')) {
                    $logger = new \MiIntegracionApi\Logging\Core\Logger('transient_cleanup');
                    $logger->info('Limpieza automática centralizada ejecutada', [
                        'daily_cleaned'  => $daily_result['items_cleaned'] ?? 0,
                        'weekly_cleaned' => isset($weekly_result) ? ($weekly_result['items_cleaned'] ?? 0) : 0,
                        'hourly_cleaned' => isset($hourly_result) ? ($hourly_result['items_cleaned'] ?? 0) : 0,
                        'execution_time' => date('Y-m-d H:i:s'),
                    ]);
                }
            } else {
                // Fallback al método anterior si RobustnessHooks no está disponible
                if (class_exists('\\MiIntegracionApi\\Helpers\\Utils')) {
                    $cleanup_stats = \MiIntegracionApi\Helpers\Utils::cleanup_old_sync_transients(24);

                    if (class_exists('\\MiIntegracionApi\\Logging\\Core\\Logger')) {
                        $logger = new \MiIntegracionApi\Logging\Core\Logger('transient_cleanup');
                        $logger->info('Limpieza automática ejecutada (fallback)', [
                            'stats' => $cleanup_stats,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Manejo robusto de errores
            if (class_exists('\\MiIntegracionApi\\Logging\\Core\\Logger')) {
                $logger = new \MiIntegracionApi\Logging\Core\Logger('transient_cleanup');
                $logger->error('Error durante limpieza automática de transients: ' . $e->getMessage(), [
                    'exception' => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]);
            }
        }
    });

    $cleanup_auto_hooks_registered = true;
}

/**
 * Hook de desactivación robusto para limpiar cron jobs
 * CORRECCIÓN #7: Limpieza completa con logging y manejo de errores
 * PREVENCIÓN: Evitar registro múltiple de hooks de desactivación
 */
static $deactivation_hooks_registered = false;

if (! $deactivation_hooks_registered) {
    register_deactivation_hook(__FILE__, function () {
        try {
            // Limpiar cron jobs de limpieza de transients
            wp_clear_scheduled_hook('mia_cleanup_transients');
            wp_clear_scheduled_hook('mia_cleanup_old_transients'); // Limpiar obsoleto también

            // Confirmación de limpieza exitosa
            if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
                $logger = new \MiIntegracionApi\Logging\Core\Logger('transient_cleanup');
                $logger->info('Cron jobs de limpieza eliminados durante desactivación del plugin');
            }

            // Limpiar transients del plugin si es posible
            if (class_exists('\\MiIntegracionApi\\Admin\\AjaxSync')) {
                try {
                    \MiIntegracionApi\Admin\AjaxSync::cleanup_old_sync_transients();

                    if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
                        $logger = new \MiIntegracionApi\Logging\Core\Logger('transient_cleanup');
                        $logger->info('Transients del plugin limpiados durante desactivación');
                    }
                } catch (\Throwable $e) {
                    // Manejo de error sin fallar desactivación
                    if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
                        $logger = new \MiIntegracionApi\Logging\Core\Logger('transient_cleanup');
                        $logger->warning('No se pudieron limpiar transients durante desactivación: ' . $e->getMessage());
                    }
                }
            }

        } catch (\Throwable $e) {
            // Manejo de error general sin fallar desactivación
            if (class_exists('MiIntegracionApi\\Logging\\Core\\Logger')) {
                $logger = new \MiIntegracionApi\Logging\Core\Logger('transient_cleanup');
                $logger->error('Error durante desactivación del plugin: ' . $e->getMessage());
            }
        }
    });

    $deactivation_hooks_registered = true;
}

// El sistema de hooks se maneja automáticamente por el AutoloaderManager

/**
 * ✅ NUEVO: Registra schedules de cron personalizados para WP_Background_Process
 *
 * Esta función soluciona el error "invalid_schedule" que ocurre cuando
 * WP_Background_Process intenta usar 'wp_background_process_cron_interval'
 * sin que esté registrado.
 *
 * @param array $schedules Schedules existentes de WordPress
 * @return array Schedules con los nuevos agregados
 * @since 1.4.1
 */
if (! function_exists(__NAMESPACE__ . '\\add_background_process_cron_schedules')) {
    function add_background_process_cron_schedules(array $schedules): array
    {
        // Schedule requerido por WP_Background_Process
        if (! isset($schedules['wp_background_process_cron_interval'])) {
            $schedules['wp_background_process_cron_interval'] = [
                'interval' => 300, // 5 minutos
                'display'  => __('Background Process Interval (5 minutes)', 'mi-integracion-api'),
            ];
        }

        // Schedule adicional para heartbeat process
        if (! isset($schedules['mia_heartbeat_interval'])) {
            $schedules['mia_heartbeat_interval'] = [
                'interval' => 60, // 1 minuto
                'display'  => __('Heartbeat Process Interval (1 minute)', 'mi-integracion-api'),
            ];
        }

        return $schedules;
    }
}
