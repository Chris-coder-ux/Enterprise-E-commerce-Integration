<?php

declare(strict_types=1);

/**
 * Cargador y validador de entorno WooCommerce
 * 
 * Esta clase centraliza la validación y carga de funciones WooCommerce necesarias 
 * para la integración con Verial. Migrado desde SyncManager legacy.
 * 
 * @package MiIntegracionApi\Sync
 * @since 1.0.0
 */

namespace MiIntegracionApi\Sync;

use MiIntegracionApi\Helpers\Logger;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

class WooCommerceLoader {
    
    /**
     * Instancia del logger
     *
     * @var \MiIntegracionApi\Helpers\Logger
     */
    private static $logger;
    
    /**
     * Cache del resultado de validación (cache estático para la misma ejecución)
     *
     * @var array|null
     */
    private static $validation_cache = null;
    
    /**
     * Clave del cache persistente en transients
     *
     * @var string
     */
    private static $cache_key = 'mia_wc_validation_cache';
    
    /**
     * TTL del cache en segundos (1 hora)
     *
     * @var int
     */
    private static $cache_ttl = 3600;
    
    /**
     * Inicializa la instancia de logger si no existe
     */
    private static function get_logger() {
        if (!self::$logger) {
            self::$logger = new Logger('WooCommerceLoader');
        }
        return self::$logger;
    }
    
    /**
     * Obtiene el cache persistente de validación
     * 
     * @return array|null Cache de validación o null si no existe
     */
    private static function get_persistent_cache(): ?array {
        if (!function_exists('get_transient')) {
            return null;
        }
        
        $cached = get_transient(self::$cache_key);
        return $cached ?: null;
    }
    
    /**
     * Guarda el cache persistente de validación con TTL dinámico
     * 
     * @param array $validation_result Resultado de validación a cachear
     * @return void
     */
    private static function set_persistent_cache(array $validation_result): void {
        if (!function_exists('set_transient')) {
            return;
        }
        
        // Obtener TTL dinámico basado en contexto
        $context = self::detectCurrentContext();
        $dynamic_ttl = self::getDynamicTTL($context);
        
        set_transient(self::$cache_key, [
            'data' => $validation_result,
            'timestamp' => time(),
            'dynamic_ttl' => $dynamic_ttl,
            'verification_type' => 'woocommerce',
            'context' => $context
        ], $dynamic_ttl);
    }
    
    /**
     * Detecta automáticamente el contexto de ejecución actual
     * 
     * @return string Contexto detectado ('admin', 'ajax', 'cron', 'frontend', 'cli', 'general')
     * @since 2.5.0
     */
    private static function detectCurrentContext(): string {
        // Verificar si WordPress está completamente cargado
        if (!self::isWordPressFullyLoaded()) {
            // WordPress no está completamente cargado, usar detección básica
            return self::detectContextEarly();
        }
        
        // Detectar contexto de WordPress (funciones disponibles)
        if (is_admin()) {
            return 'admin';
        }
        
        if (wp_doing_ajax()) {
            return 'ajax';
        }
        
        if (wp_doing_cron()) {
            return 'cron';
        }
        
        if (wp_doing_rest()) {
            return 'rest';
        }
        
        // Detectar contexto de CLI
        if (defined('WP_CLI') && WP_CLI) {
            return 'cli';
        }
        
        // Detectar contexto de frontend
        if (is_frontend()) {
            return 'frontend';
        }
        
        // Contexto por defecto
        return 'general';
    }
    
    /**
     * Verifica si WordPress está completamente cargado
     * 
     * @return bool True si WordPress está completamente cargado
     * @since 2.5.0
     */
    private static function isWordPressFullyLoaded(): bool {
        // Verificar funciones básicas de WordPress
        $required_functions = [
            'is_admin',
            'wp_doing_ajax',
            'wp_doing_cron',
            'wp_doing_rest',
            'is_frontend',
            'is_plugin_active',
            'get_bloginfo',
            'is_multisite',
            'get_transient',
            'set_transient'
        ];
        
        foreach ($required_functions as $function) {
            if (!function_exists($function)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Detecta el contexto cuando WordPress no está completamente cargado
     * 
     * @return string Contexto detectado
     * @since 2.5.0
     */
    private static function detectContextEarly(): string {
        // Detectar contexto CLI
        if (defined('WP_CLI') && WP_CLI) {
            return 'cli';
        }
        
        if (php_sapi_name() === 'cli') {
            return 'cli';
        }
        
        // Detectar contexto AJAX por constantes de WordPress
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return 'ajax';
        }
        
        // Detectar contexto CRON por constantes de WordPress
        if (defined('DOING_CRON') && DOING_CRON) {
            return 'cron';
        }
        
        // Detectar contexto admin por URL
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-admin/') !== false) {
            return 'admin';
        }
        
        // Detectar contexto AJAX por headers HTTP
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return 'ajax';
        }
        
        // Detectar contexto AJAX por acción específica
        if (isset($_POST['action']) || isset($_GET['action'])) {
            return 'ajax';
        }
        
        // Por defecto, asumir general
        return 'general';
    }
    
    /**
     * Obtiene el TTL dinámico recomendado para WooCommerce
     * 
     * @param string $context Contexto de ejecución
     * @return int TTL recomendado en segundos
     * @since 2.5.0
     */
    private static function getDynamicTTL(string $context): int {
        // TTL base para WooCommerce
        $base_ttl = 1800; // 30 minutos base
        
        // Ajuste por contexto
        $context_multipliers = [
            'admin' => 0.8, // Contextos críticos - TTL más corto
            'ajax' => 0.6,
            'cron' => 1.5, // Contextos automáticos - TTL más largo
            'frontend' => 2.0,
            'general' => 1.0
        ];
        
        $multiplier = $context_multipliers[$context] ?? 1.0;
        $adjusted_ttl = $base_ttl * $multiplier;
        
        // Límites de TTL (5 minutos a 2 horas)
        $adjusted_ttl = max(300, min(7200, $adjusted_ttl));
        
        return (int) $adjusted_ttl;
    }
    
    /**
     * Verifica si el cache persistente es válido
     * 
     * @return bool true si el cache es válido y reciente
     */
    private static function is_persistent_cache_valid(): bool {
        if (!function_exists('get_transient')) {
            return false;
        }
        
        $cached = get_transient(self::$cache_key);
        if (!$cached || !isset($cached['timestamp'])) {
            return false;
        }
        
        $age = time() - $cached['timestamp'];
        return $age < self::$cache_ttl;
    }
    
    /**
     * Invalida el cache persistente
     * 
     * @return void
     */
    private static function invalidate_persistent_cache(): void {
        delete_transient(self::$cache_key);
    }
    
    /**
     * Valida que WooCommerce esté correctamente configurado para la sincronización
     * 
     * @param bool $force_refresh Forzar nueva validación sin cache
     * @return array Resultado con claves 'status', 'message', 'details'
     */
    public static function validate_environment(bool $force_refresh = false): array {
        // Usar cache estático si existe y no se fuerza refresh
        if (!$force_refresh && self::$validation_cache !== null) {
            return self::$validation_cache;
        }
        
        // Usar cache persistente si es válido y no se fuerza refresh
        if (!$force_refresh && self::is_persistent_cache_valid()) {
            $cached = self::get_persistent_cache();
            if ($cached && isset($cached['data'])) {
                // Actualizar cache estático para la misma ejecución
                self::$validation_cache = $cached['data'];
                return $cached['data'];
            }
        }
        
        $diagnosis = [
            'status' => 'unknown',
            'message' => '',
            'details' => [
                'wordpress' => [
                    'version' => function_exists('get_bloginfo') ? \get_bloginfo('version') : 'no disponible',
                    'abspath' => defined('ABSPATH') ? ABSPATH : 'no definido',
                    'wp_plugin_dir' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : 'no definido',
                    'is_multisite' => function_exists('is_multisite') ? \is_multisite() : false,
                ],
                'woocommerce' => [
                    'wc_class_exists' => class_exists('WooCommerce'),
                    'wc_version' => class_exists('WooCommerce') && defined('WC_VERSION') ? WC_VERSION : 'no detectado',
                    'wc_active' => false,
                    'wc_path' => 'no detectado',
                    'wc_plugin_file' => defined('WC_PLUGIN_FILE') ? WC_PLUGIN_FILE : 'no definido',
                    'wc_abspath' => defined('WC_ABSPATH') ? WC_ABSPATH : 'no definido',
                ],
                'product_functions' => [
                    'wc_create_product' => function_exists('wc_create_product'),
                    'wc_update_product' => function_exists('wc_update_product'),
                    'wc_get_product' => function_exists('wc_get_product'),
                    'wc_get_products' => function_exists('wc_get_products'),
                ],
                'classes' => [
                    'WC_Product' => class_exists('WC_Product'),
                    'WC_Product_Simple' => class_exists('WC_Product_Simple'),
                    'WC_Product_Variable' => class_exists('WC_Product_Variable'),
                    'WC_Product_Data_Store_CPT' => class_exists('WC_Product_Data_Store_CPT'),
                ],
                'load_attempts' => [],
                'suggestions' => []
            ]
        ];

        // Verificar si el plugin está activo
        if (!function_exists('is_plugin_active')) {
            if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                $diagnosis['details']['load_attempts'][] = "Cargado wp-admin/includes/plugin.php para verificar plugins activos";
            } else {
                $diagnosis['details']['load_attempts'][] = "No se pudo cargar wp-admin/includes/plugin.php - ABSPATH no definido o archivo no existe";
            }
        }

        if (function_exists('is_plugin_active')) {
            $diagnosis['details']['woocommerce']['wc_active'] = self::is_plugin_active('woocommerce/woocommerce.php');
            
            // Verificar si está inactivo pero instalado
            if (!$diagnosis['details']['woocommerce']['wc_active']) {
                if (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php')) {
                    $diagnosis['details']['suggestions'][] = "WooCommerce está instalado pero no activado. Activa el plugin desde el panel de administración.";
                } else {
                    $diagnosis['details']['suggestions'][] = "WooCommerce no está instalado. Instala y activa WooCommerce antes de intentar sincronizar productos.";
                }
            }
        } else {
            // Si is_plugin_active no está disponible, usar verificación alternativa
            $diagnosis['details']['woocommerce']['wc_active'] = false;
            $diagnosis['details']['load_attempts'][] = "is_plugin_active no disponible - usando verificación alternativa";
            
            // Verificación alternativa: verificar si la clase WooCommerce existe
            if (class_exists('WooCommerce')) {
                $diagnosis['details']['woocommerce']['wc_active'] = true;
                $diagnosis['details']['load_attempts'][] = "WooCommerce detectado por clase existente";
            }
        }

        // Intentar determinar la ruta de WooCommerce
        if (function_exists('WC')) {
            try {
                $wc = self::WC();
                if ($wc !== null && method_exists($wc, 'plugin_path')) {
                    $diagnosis['details']['woocommerce']['wc_path'] = $wc->plugin_path();
                }
            } catch (\Throwable $e) {
                $diagnosis['details']['load_attempts'][] = "Error al obtener WC()->plugin_path(): " . $e->getMessage();
            }
        }

        // Si no pudimos obtener la ruta vía WC(), usar alternativas
        if ($diagnosis['details']['woocommerce']['wc_path'] === 'no detectado') {
            if (defined('WC_PLUGIN_FILE')) {
                $diagnosis['details']['woocommerce']['wc_path'] = self::plugin_dir_path(WC_PLUGIN_FILE);
            } elseif (defined('WC_ABSPATH')) {
                $diagnosis['details']['woocommerce']['wc_path'] = WC_ABSPATH;
            } elseif (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php')) {
                $diagnosis['details']['woocommerce']['wc_path'] = WP_PLUGIN_DIR . '/woocommerce/';
            }
        }

        // Verificar archivos críticos de WooCommerce
        if ($diagnosis['details']['woocommerce']['wc_path'] !== 'no detectado') {
            $critical_files = [
                'woocommerce.php',
                'includes/wc-core-functions.php',
                'includes/wc-product-functions.php',
                'includes/abstracts/abstract-wc-product.php',
                'includes/class-wc-product-factory.php'
            ];

            $diagnosis['details']['files'] = [];
            foreach ($critical_files as $file) {
                $full_path = $diagnosis['details']['woocommerce']['wc_path'] . '/' . $file;
                $diagnosis['details']['files'][$file] = [
                    'exists' => file_exists($full_path),
                    'readable' => is_readable($full_path),
                    'size' => file_exists($full_path) ? filesize($full_path) : 0,
                ];
                
                if (!file_exists($full_path)) {
                    $diagnosis['details']['suggestions'][] = "Archivo crítico de WooCommerce no encontrado: $file. La instalación podría estar corrupta.";
                } elseif (!is_readable($full_path)) {
                    $diagnosis['details']['suggestions'][] = "Archivo crítico de WooCommerce no es legible: $file. Verifica los permisos.";
                }
            }
        }

        // Si las funciones de productos no existen, intentar cargarlas
        if (!$diagnosis['details']['product_functions']['wc_create_product'] || !$diagnosis['details']['product_functions']['wc_update_product']) {
            $diagnosis['details']['load_attempts'][] = "Intentando cargar las funciones de WooCommerce con load_functions()";
            $load_success = self::load_woocommerce_functions();
            $diagnosis['details']['load_attempts'][] = $load_success ? "Carga exitosa" : "Carga fallida";
            
            // Actualizar el estado después de intentar la carga
            $diagnosis['details']['product_functions'] = [
                'wc_create_product' => function_exists('wc_create_product'),
                'wc_update_product' => function_exists('wc_update_product'),
                'wc_get_product' => function_exists('wc_get_product'),
                'wc_get_products' => function_exists('wc_get_products'),
            ];
        }

        // Determinar el estado final
        
        if (!$diagnosis['details']['woocommerce']['wc_active']) {
            $diagnosis['status'] = 'error';
            $diagnosis['message'] = 'WooCommerce no está activo. Activa el plugin antes de sincronizar productos.';
        } elseif (!$diagnosis['details']['product_functions']['wc_create_product'] || !$diagnosis['details']['product_functions']['wc_update_product']) {
            // CORRECCIÓN: No fallar si las funciones específicas no existen, verificar alternativas
            $has_alternative_methods = $diagnosis['details']['classes']['WC_Product'] && 
                                     $diagnosis['details']['classes']['WC_Product_Simple'] &&
                                     $diagnosis['details']['product_functions']['wc_get_product'];
            
            if ($has_alternative_methods) {
                $diagnosis['status'] = 'ok';
                $diagnosis['message'] = 'WooCommerce está listo para sincronización usando métodos alternativos.';
            } else {
                $diagnosis['status'] = 'error';
                $diagnosis['message'] = 'Las funciones de WooCommerce para crear/actualizar productos no están disponibles.';
                $diagnosis['details']['suggestions'][] = "Reinstala WooCommerce o contacta al soporte técnico del hosting para verificar la configuración de PHP.";
            }
        } elseif (!$diagnosis['details']['classes']['WC_Product']) {
            $diagnosis['status'] = 'error';
            $diagnosis['message'] = 'La clase WC_Product no está disponible, lo que indica un problema con la instalación de WooCommerce.';
        } else {
            $diagnosis['status'] = 'ok';
            $diagnosis['message'] = 'WooCommerce parece estar configurado correctamente para la sincronización de productos.';
        }

        // Cachear resultado en cache estático
        self::$validation_cache = $diagnosis;
        
        // Cachear resultado en cache persistente
        self::set_persistent_cache($diagnosis);
        
        return $diagnosis;
    }
    
    /**
     * Carga las funciones de WooCommerce necesarias para la sincronización
     * 
     * @return bool true si las funciones se cargaron exitosamente
     */
    public static function load_woocommerce_functions(): bool {
        $initial_functions = [
            'wc_create_product' => function_exists('wc_create_product'),
            'wc_update_product' => function_exists('wc_update_product'),
            'wc_get_product' => function_exists('wc_get_product'),
            'wc_get_products' => function_exists('wc_get_products'),
            'wc_format_decimal' => function_exists('wc_format_decimal'),
            'wc_stock_amount' => function_exists('wc_stock_amount'),
        ];
        
        // Log de debug eliminado para evitar spam
        
        // Método 1: Usar WC_ABSPATH si está definido
        if (defined('WC_ABSPATH')) {
            // Primero cargar las funciones básicas que pueden ser necesarias
            if (file_exists(WC_ABSPATH . 'includes/wc-core-functions.php')) {
                require_once WC_ABSPATH . 'includes/wc-core-functions.php';
                // Log de debug eliminado para evitar spam
            }
            
            // Luego cargar las funciones de productos
            if (file_exists(WC_ABSPATH . 'includes/wc-product-functions.php')) {
                require_once WC_ABSPATH . 'includes/wc-product-functions.php';
                // Log de debug eliminado para evitar spam
            }
            
            // CORRECCIÓN: Cargar funciones de formato y stock específicas
            if (file_exists(WC_ABSPATH . 'includes/wc-formatting-functions.php')) {
                require_once WC_ABSPATH . 'includes/wc-formatting-functions.php';
            }
        } else {
        }
        
        // Método 2: Usar WC() si está disponible
        if ((!function_exists('wc_create_product') || !function_exists('wc_update_product')) && function_exists('WC')) {
            $wc_instance = self::WC();
            if ($wc_instance !== null) {
                $plugin_path = $wc_instance->plugin_path();
                
                // Cargar funciones core primero
                if (file_exists($plugin_path . '/includes/wc-core-functions.php')) {
                    require_once $plugin_path . '/includes/wc-core-functions.php';
                    // Log de debug eliminado para evitar spam
                }
                
                // Luego cargar funciones de producto
                if (file_exists($plugin_path . '/includes/wc-product-functions.php')) {
                    require_once $plugin_path . '/includes/wc-product-functions.php';
                    // Log de debug eliminado para evitar spam
                }
                
                // CORRECCIÓN: Cargar funciones de formato y stock específicas
                if (file_exists($plugin_path . '/includes/wc-formatting-functions.php')) {
                    require_once $plugin_path . '/includes/wc-formatting-functions.php';
                }
            }
        }
        
        // Método 3: Intentar con la ruta predeterminada de plugins
        if (!function_exists('wc_create_product') || !function_exists('wc_update_product')) {
            // Asumimos que WP_PLUGIN_DIR está definido
            if (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/woocommerce/includes/wc-product-functions.php')) {
                require_once WP_PLUGIN_DIR . '/woocommerce/includes/wc-product-functions.php';
                // Log de debug eliminado para evitar spam
            }
            
            // CORRECCIÓN: Cargar funciones de formato y stock específicas
            if (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/woocommerce/includes/wc-formatting-functions.php')) {
                require_once WP_PLUGIN_DIR . '/woocommerce/includes/wc-formatting-functions.php';
            }
        }
        
        $final_functions = [
            'wc_create_product' => function_exists('wc_create_product'),
            'wc_update_product' => function_exists('wc_update_product'),
            'wc_get_product' => function_exists('wc_get_product'),
            'wc_get_products' => function_exists('wc_get_products'),
            'wc_format_decimal' => function_exists('wc_format_decimal'),
            'wc_stock_amount' => function_exists('wc_stock_amount'),
        ];
        
        $success = $final_functions['wc_create_product'] && $final_functions['wc_update_product'] && 
                   $final_functions['wc_format_decimal'] && $final_functions['wc_stock_amount'];
        
        
        // Log de debug eliminado para evitar spam
        
        return $success;
    }
    
    /**
     * Verifica si WooCommerce está listo para sincronización
     * 
     * @return bool true si WooCommerce está listo
     */
    public static function is_ready(): bool {
        $validation = self::validate_environment();
        return isset($validation['status']) && $validation['status'] === 'ok';
    }
    
    /**
     * Verificación rápida de WooCommerce (usa cache cuando es posible)
     * 
     * @return bool true si WooCommerce está listo para sincronización
     */
    public static function isWooCommerceReady(): bool {
        // Intentar usar cache persistente primero
        if (self::is_persistent_cache_valid()) {
            $cached = self::get_persistent_cache();
            if ($cached && isset($cached['data']['status'])) {
                return $cached['data']['status'] === 'ok';
            }
        }
        
        // Si no hay cache válido, usar cache estático
        if (self::$validation_cache !== null) {
            return isset(self::$validation_cache['status']) && self::$validation_cache['status'] === 'ok';
        }
        
        // Si no hay cache, hacer validación completa
        return self::is_ready();
    }
    
    /**
     * Obtiene el estado cacheado de WooCommerce
     * 
     * @return array Estado de WooCommerce con información básica
     */
    public static function getCachedWooCommerceStatus(): array {
        // Intentar usar cache persistente primero
        if (self::is_persistent_cache_valid()) {
            $cached = self::get_persistent_cache();
            if ($cached && isset($cached['data']) && isset($cached['data']['status'])) {
                return [
                    'status' => $cached['data']['status'],
                    'message' => $cached['data']['message'] ?? 'Cache válido',
                    'cached' => true,
                    'cache_age' => time() - $cached['timestamp']
                ];
            }
        }
        
        // Si no hay cache válido, usar cache estático
        if (self::$validation_cache !== null) {
            return [
                'status' => self::$validation_cache['status'] ?? 'unknown',
                'message' => self::$validation_cache['message'] ?? 'Cache sin mensaje',
                'cached' => true,
                'cache_age' => 0
            ];
        }
        
        // Si no hay cache, hacer validación completa
        $validation = self::validate_environment();
        return [
            'status' => $validation['status'] ?? 'unknown',
            'message' => $validation['message'] ?? 'Validación fallida',
            'cached' => false,
            'cache_age' => 0
        ];
    }
    
    /**
     * Asegura que WooCommerce esté cargado y listo para uso
     * 
     * @throws \Exception Si WooCommerce no puede ser preparado
     * @return void
     */
    public static function ensure_ready(): void {
        $validation = self::validate_environment();
        
        // CORRECCIÓN: Verificar que el array tenga las claves esperadas
        $status = $validation['status'] ?? 'unknown';
        $message = $validation['message'] ?? 'No se pudo determinar el estado de WooCommerce';
        
        if ($status !== 'ok') {
            throw new \Exception(
                'WooCommerce no está listo para sincronización: ' . $message
            );
        }
    }
    
    /**
     * Limpia el cache de validación (estático y persistente)
     * 
     * @return void
     */
    public static function clear_validation_cache(): void {
        self::$validation_cache = null;
        self::invalidate_persistent_cache();
    }
    
    /**
     * Obtiene información resumida del estado de WooCommerce
     * 
     * @return array Estado resumido con claves básicas
     */
    public static function get_status_summary(): array {
        $validation = self::validate_environment();
        
        return [
            'status' => $validation['status'],
            'message' => $validation['message'],
            'wc_active' => $validation['details']['woocommerce']['wc_active'] ?? false,
            'wc_version' => $validation['details']['woocommerce']['wc_version'] ?? 'no detectado',
            'functions_available' => [
                'wc_create_product' => $validation['details']['product_functions']['wc_create_product'] ?? false,
                'wc_update_product' => $validation['details']['product_functions']['wc_update_product'] ?? false,
            ],
            'suggestions_count' => count($validation['details']['suggestions'] ?? [])
        ];
    }

    /**
     * Wrapper seguro para is_plugin_active de WordPress
     * 
     * @param string $plugin Ruta del plugin a verificar
     * @return bool True si el plugin está activo, false en caso contrario
     * @since 2.5.0
     */
    private static function is_plugin_active(string $plugin): bool
    {
        // Verificar si la función de WordPress está disponible
        if (!function_exists('is_plugin_active')) {
            // Intentar cargar la función si no está disponible
            if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
        }
        
        // Si la función está disponible, usarla
        if (function_exists('is_plugin_active')) {
            return is_plugin_active($plugin);
        }
        
        // Fallback: verificar si la clase del plugin existe
        // Para WooCommerce, verificar si la clase WooCommerce existe
        if ($plugin === 'woocommerce/woocommerce.php') {
            return class_exists('WooCommerce');
        }
        
        // Para otros plugins, no podemos determinar si están activos sin la función de WordPress
        return false;
    }
    
    /**
     * Wrapper seguro para plugin_dir_path de WordPress
     * 
     * @param string $file Ruta del archivo del plugin
     * @return string Directorio del plugin con barra final
     * @since 2.5.0
     */
    private static function plugin_dir_path(string $file): string
    {
        // Verificar si la función de WordPress está disponible
        if (function_exists('plugin_dir_path')) {
            return plugin_dir_path($file);
        }
        
        // Fallback: extraer el directorio del archivo manualmente
        $dir = dirname($file);
        
        // Asegurar que termine con barra
        if (substr($dir, -1) !== '/') {
            $dir .= '/';
        }
        
        return $dir;
    }
    
    /**
     * Wrapper seguro para WC() de WooCommerce
     * 
     * @return \WooCommerce|null Instancia de WooCommerce o null si no está disponible
     * @since 2.5.0
     */
    private static function WC(): ?\WooCommerce
    {
        // Verificar si la función de WordPress está disponible
        if (function_exists('WC')) {
            return WC();
        }
        
        // Fallback: verificar si la clase WooCommerce existe y crear instancia
        if (class_exists('WooCommerce')) {
            try {
                return new \WooCommerce();
            } catch (\Throwable $e) {
                // Si no se puede crear la instancia, retornar null
                return null;
            }
        }
        
        return null;
    }
}