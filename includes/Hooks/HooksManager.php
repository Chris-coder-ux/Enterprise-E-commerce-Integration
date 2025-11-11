<?php

declare(strict_types=1);

/**
 * Gestor centralizado de hooks para WordPress
 *
 * Este archivo contiene la clase HooksManager que proporciona métodos seguros
 * para registrar acciones y filtros de WordPress, con verificación de callbacks
 * y manejo de errores integrado.
 *
 * @package    MiIntegracionApi
 * @subpackage Hooks
 * @category   Core
 * @since      1.0.0
 * @author     [Autor]
 * @link       [URL del plugin]
 */

namespace MiIntegracionApi\Hooks;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestor centralizado de hooks para WordPress
 *
 * Esta clase proporciona una capa de abstracción sobre las funciones nativas de WordPress
 * para el registro de acciones y filtros, añadiendo:
 * - Verificación de callbacks antes de su registro
 * - Manejo centralizado de errores
 * - Soporte condicional para plugins como WooCommerce
 * - Métodos de utilidad para verificar el estado de los plugins
 *
 * @see https://developer.wordpress.org/plugins/hooks/
 */
class HooksManager {
    /**
     * Registro de errores en los hooks
     * 
     * @var array
     */
    private static $errors = [];

    /**
     * Registra una acción de WordPress de forma segura
     *
     * Este método proporciona una capa de seguridad sobre add_action() de WordPress
     * verificando que el callback sea válido antes de registrarlo.
     *
     * @param string|array|callable $hook_name     Nombre del hook de WordPress o array de hooks
     * @param callable              $callback      Función/método a ejecutar
     * @param int                   $priority      Prioridad de ejecución (menor = antes)
     * @param int                   $accepted_args Número de argumentos que recibe el callback
     * @param bool                  $conditional   Si es false, no se registrará el hook
     * @return bool True si se registró correctamente, false en caso contrario
     * @see add_action()
     * @see self::is_valid_callback()
     */
    public static function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1, $conditional = true) {
        if (!$conditional) {
            return false;
        }
        
        if (!self::is_valid_callback($callback)) {
            self::$errors[] = sprintf('Intento de registrar acción %s con un callback inválido', $hook_name);
            return false;
        }
        
        add_action($hook_name, $callback, $priority, $accepted_args);
        return true;
    }

    /**
     * Registra un filtro de WordPress de forma segura
     *
     * Similar a add_action() pero para filtros. Verifica que el callback sea válido
     * antes de proceder con el registro.
     *
     * @param string|array|callable $hook_name     Nombre del filtro de WordPress o array de filtros
     * @param callable              $callback      Función/método a ejecutar
     * @param int                   $priority      Prioridad de ejecución (menor = antes)
     * @param int                   $accepted_args Número de argumentos que recibe el callback
     * @param bool                  $conditional   Si es false, no se registrará el filtro
     * @return bool True si se registró correctamente, false en caso contrario
     * @see add_filter()
     * @see self::is_valid_callback()
     */
    public static function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1, $conditional = true) {
        if (!$conditional) {
            return false;
        }
        
        if (!self::is_valid_callback($callback)) {
            self::$errors[] = sprintf('Intento de registrar filtro %s con un callback inválido', $hook_name);
            return false;
        }
        
        add_filter($hook_name, $callback, $priority, $accepted_args);
        return true;
    }

    /**
     * Registra una acción de WooCommerce de forma segura
     *
     * Registra un hook de acción solo si WooCommerce está activo.
     * Útil para funcionalidades que dependen de WooCommerce.
     *
     * @param string|array|callable $hook_name     Nombre del hook de WooCommerce
     * @param callable              $callback      Función/método a ejecutar
     * @param int                   $priority      Prioridad de ejecución (menor = antes)
     * @param int                   $accepted_args Número de argumentos que recibe el callback
     * @return bool True si se registró correctamente, false si WC no está activo o el callback es inválido
     * @see self::is_woocommerce_active()
     * @see self::add_action()
     */
    public static function add_wc_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
        return self::add_action($hook_name, $callback, $priority, $accepted_args, self::is_woocommerce_active());
    }

    /**
     * Registra un filtro de WooCommerce de forma segura
     *
     * Registra un hook de filtro solo si WooCommerce está activo.
     * Útil para modificar valores que dependen de WooCommerce.
     *
     * @param string|array|callable $hook_name     Nombre del filtro de WooCommerce
     * @param callable              $callback      Función/método a ejecutar
     * @param int                   $priority      Prioridad de ejecución (menor = antes)
     * @param int                   $accepted_args Número de argumentos que recibe el callback
     * @return bool True si se registró correctamente, false si WC no está activo o el callback es inválido
     * @see self::is_woocommerce_active()
     * @see self::add_filter()
     */
    public static function add_wc_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) {
        return self::add_filter($hook_name, $callback, $priority, $accepted_args, self::is_woocommerce_active());
    }

    /**
     * Verifica si un callback es válido y puede ser ejecutado
     *
     * Soporta múltiples formatos de callback:
     * - Funciones anónimas (closures)
     * - Nombres de funciones como string
     * - Arrays [objeto, método]
     * - Arrays [clase, método_estático]
     *
     * @param mixed $callback El callback a verificar
     * @return bool True si el callback es válido y puede ser ejecutado
     */
    private static function is_valid_callback($callback) {
        // Verificar función anónima
        if (is_callable($callback)) {
            return true;
        }
        
        // Verificar string (nombre de función)
        if (is_string($callback) && function_exists($callback)) {
            return true;
        }
        
        // Verificar array [objeto, método]
        if (is_array($callback) && count($callback) === 2) {
            list($object_or_class, $method) = $callback;
            
            // Caso 1: [objeto, método]
            if (is_object($object_or_class) && method_exists($object_or_class, $method)) {
                return true;
            }
            
            // Caso 2: [clase, método estático]
            if (is_string($object_or_class) && class_exists($object_or_class) && method_exists($object_or_class, $method)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Verifica si WooCommerce está activo y disponible
     *
     * Comprueba si WooCommerce está activo en el sitio, incluyendo
     * instalaciones en red (multisite).
     *
     * @return bool True si WooCommerce está activo y disponible para usar
     * @see function_exists('WC')
     */
    public static function is_woocommerce_active() {
        // Si la función está definida en el archivo principal, usarla
        if (function_exists('\MiIntegracionApi\check_woocommerce_active')) {
            return \MiIntegracionApi\check_woocommerce_active();
        }
        
        // Verificación interna
        $active_plugins = (array) get_option('active_plugins', []);
        $active = in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
        
        // Verificar si estamos en multisite
        if (!$active && is_multisite()) {
            $active_network_plugins = (array) get_site_option('active_sitewide_plugins', []);
            $active = in_array('woocommerce/woocommerce.php', $active_network_plugins) || isset($active_network_plugins['woocommerce/woocommerce.php']);
        }
        
        return $active;
    }

    /**
     * Obtiene los errores registrados durante el registro de hooks
     *
     * Útil para depuración, especialmente en desarrollo.
     * Los errores se almacenan en memoria hasta que se limpien con clear_errors().
     *
     * @return array Lista de mensajes de error
     * @see self::clear_errors()
     */
    public static function get_errors() {
        return self::$errors;
    }

    /**
     * Limpia la lista de errores registrados
     *
     * Elimina todos los mensajes de error almacenados en memoria.
     * Útil para reiniciar el estado de la clase entre pruebas o ejecuciones.
     *
     * @return void
     * @see self::get_errors()
     */
    public static function clear_errors() {
        self::$errors = [];
    }
}
