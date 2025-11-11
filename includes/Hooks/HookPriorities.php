<?php

declare(strict_types=1);

/**
 * Configuración centralizada para prioridades de hooks
 *
 * Este archivo define las prioridades estándar para todos los hooks utilizados en el plugin.
 * Centralizar estas prioridades ayuda a prevenir conflictos con otros plugins y facilita
 * el mantenimiento del código al tener un único punto de referencia para las prioridades.
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
 * Gestor centralizado de prioridades de hooks
 * 
 * Esta clase proporciona una forma estructurada de gestionar las prioridades de todos
 * los hooks utilizados en el plugin. Al centralizar estas prioridades, se logra:
 * 
 * - Evitar conflictos entre diferentes partes del plugin
 * - Facilitar el mantenimiento al tener un único punto de referencia
 * - Mejorar la consistencia en la ejecución de hooks
 * - Simplificar la depuración de problemas de prioridad
 * 
 * Las prioridades están organizadas en constantes agrupadas por funcionalidad
 * (INIT, WOOCOMMERCE, ADMIN, etc.) para facilitar su uso y comprensión.
 * 
 * @category   WordPress
 * @package    MiIntegracionApi
 * @subpackage Hooks
 * @see        https://developer.wordpress.org/plugins/hooks/actions/
 */
class HookPriorities {
    
    /**
     * Prioridades para hooks de inicialización del plugin
     * 
     * @var array {
     *     @type int DEFAULT     Prioridad estándar (10)
     *     @type int EARLY       Para ejecutar antes que la mayoría de los hooks (5)
     *     @type int LATE        Para ejecutar después de la prioridad estándar (15)
     *     @type int VERY_LATE   Para ejecutar al final de todo (999)
     * }
     */
    const INIT = [
        'DEFAULT' => 10,
        'EARLY' => 5,
        'LATE' => 15,
        'VERY_LATE' => 999,
    ];
    
    /**
     * Prioridades para hooks específicos de WooCommerce
     * 
     * @var array {
     *     // Prioridades para hooks relacionados con pedidos
     *     @type int ORDER_STATUS_CHANGED  Para ejecutar después de que otros plugins hayan procesado el cambio de estado (20)
     *     @type int CHECKOUT_PROCESSED    Prioridad estándar para el procesamiento del checkout (10)
     *     @type int API_CREATE_ORDER      Prioridad estándar para la creación de pedidos vía API (10)
     *     @type int BEFORE_ORDER_ITEMMETA Para ejecutar antes que otros plugins modifiquen los metadatos (5)
     *     @type int ORDER_ITEM_META       Prioridad estándar para metadatos de ítems de pedido (10)
     *     
     *     // Prioridades para hooks de productos
     *     @type int PRODUCT_SAVE          Para ejecutar después de que otros plugins hayan guardado el producto (20)
     *     @type int PRODUCT_UPDATE        Para ejecutar después de actualizaciones estándar (15)
     *     @type int VARIATION_SAVE        Similar a PRODUCT_SAVE pero para variaciones (20)
     *     
     *     // Prioridades para carrito y checkout
     *     @type int CART_UPDATED          Prioridad estándar para actualizaciones del carrito (10)
     *     @type int BEFORE_CHECKOUT        Para ejecutar al inicio del proceso de checkout (5)
     *     @type int AFTER_CHECKOUT         Para ejecutar al final del proceso de checkout (25)
     * }
     */
    const WOOCOMMERCE = [
        // Pedidos
        'ORDER_STATUS_CHANGED' => 20,
        'CHECKOUT_PROCESSED' => 10,
        'API_CREATE_ORDER' => 10,
        'BEFORE_ORDER_ITEMMETA' => 5,
        'ORDER_ITEM_META' => 10,
        
        // Productos
        'PRODUCT_SAVE' => 20,
        'PRODUCT_UPDATE' => 15,
        'VARIATION_SAVE' => 20,
        
        // Carrito y checkout
        'CART_UPDATED' => 10,
        'BEFORE_CHECKOUT' => 5,
        'AFTER_CHECKOUT' => 25,
    ];
    
    /**
     * Prioridades para hooks del panel de administración de WordPress
     * 
     * @var array {
     *     @type int ENQUEUE_SCRIPTS     Para cargar scripts después de que otros plugins los hayan registrado (20)
     *     @type int ADMIN_INIT          Prioridad estándar para la inicialización del admin (10)
     *     @type int ADMIN_MENU          Prioridad estándar para la creación de menús (10)
     *     @type int ADMIN_NOTICES       Prioridad estándar para mostrar notificaciones (10)
     *     @type int PLUGIN_ACTION_LINKS Prioridad para modificar enlaces de acción del plugin (10)
     * }
     */
    const ADMIN = [
        'ENQUEUE_SCRIPTS' => 20,
        'ADMIN_INIT' => 10,
        'ADMIN_MENU' => 10,
        'ADMIN_NOTICES' => 10,
        'PLUGIN_ACTION_LINKS' => 10,
    ];
    
    /**
     * Prioridades para hooks de sincronización de datos
     * 
     * @var array {
     *     @type int BEFORE_SYNC   Para ejecutar antes de iniciar la sincronización (5)
     *     @type int PROCESS_ITEM  Para el procesamiento individual de ítems (10)
     *     @type int AFTER_SYNC    Para ejecutar después de completar la sincronización (15)
     * }
     */
    const SYNC = [
        'BEFORE_SYNC' => 5,
        'AFTER_SYNC' => 15,
        'PROCESS_ITEM' => 10,
    ];
    
    /**
     * Prioridades para hooks de la API REST de WordPress
     * 
     * @var array {
     *     @type int REGISTER_ROUTES   Prioridad estándar para registrar rutas (10)
     *     @type int AUTHENTICATE      Alta prioridad para autenticación (90)
     *     @type int PRE_SERVE_REQUEST Baja prioridad para ejecutar antes de servir la petición (5)
     * }
     */
    const REST_API = [
        'REGISTER_ROUTES' => 10,
        'AUTHENTICATE' => 90,
        'PRE_SERVE_REQUEST' => 5,
    ];
    
    /**
     * Obtiene la prioridad recomendada para un hook específico
     * 
     * Este método busca la prioridad configurada para un hook específico dentro de las constantes
     * de la clase. Si no se encuentra la prioridad específica, devuelve el valor por defecto (10).
     * 
     * @param string $hook_type El tipo de hook (debe ser una de las constantes de clase como 'INIT', 'WOOCOMMERCE', etc.)
     * @param string $hook_name El nombre específico del hook dentro del tipo (ej: 'DEFAULT', 'EARLY', 'PRODUCT_SAVE')
     * @return int La prioridad recomendada o 10 (prioridad estándar de WordPress) si no se encuentra
     * 
     * @example
     * // Obtener prioridad para un hook de inicialización temprana
     * $priority = HookPriorities::get('INIT', 'EARLY'); // Devuelve 5
     * 
     * // Uso con add_action o add_filter
     * add_action('init', 'mi_callback', HookPriorities::get('INIT', 'LATE'));
     * 
     * @see https://developer.wordpress.org/reference/functions/add_action/
     * @see https://developer.wordpress.org/reference/functions/add_filter/
     */
    public static function get($hook_type, $hook_name) {
        $const = "self::{$hook_type}";
        
        if (defined($const) && isset(constant($const)[$hook_name])) {
            return constant($const)[$hook_name];
        }
        
        return 10; // Prioridad predeterminada de WordPress
    }
}
