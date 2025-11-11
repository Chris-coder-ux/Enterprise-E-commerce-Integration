<?php

declare(strict_types=1);

/**
 * Sistema de carga dinámica de módulos para Mi Integración API.
 *
 * Este archivo implementa un cargador modular que gestiona la inicialización
 * ordenada de componentes del plugin. Permite cargar módulos core (esenciales)
 * y módulos de características (features) de forma condicional y extensible.
 *
 * El sistema de carga soporta:
 * - Carga automática de módulos core (ApiConnector, Auth_Manager, Cache_Manager)
 * - Carga condicional de módulos de características (SyncManager, REST_API_Handler)
 * - Extensibilidad mediante filtros de WordPress
 * - Detección automática de archivos faltantes
 * - Registro de módulos disponibles con estados
 *
 * Arquitectura de módulos:
 * ```
 * includes/
 * ├── Core/              (Módulos core - siempre cargados)
 * │   ├── ApiConnector.php
 * │   ├── Auth_Manager.php
 * │   └── Cache_Manager.php
 * ├── Sync/              (Módulos de características)
 * │   └── SyncManager.php
 * └── Rest/
 *     └── REST_API_Handler.php
 * ```
 *
 * Ejemplo de uso:
 * ```php
 * // Cargar todos los módulos
 * Module_Loader::load_all();
 *
 * // Obtener módulos disponibles
 * $modules = Module_Loader::get_available_modules();
 * // Retorna:
 * // [
 * //     'core' => ['ApiConnector' => true, 'Auth_Manager' => true, ...],
 * //     'feature' => ['SyncManager' => true, 'REST_API_Handler' => true]
 * // ]
 *
 * // Extender módulos mediante filtro
 * add_filter('mi_integracion_api_available_modules', function($modules) {
 *     $modules['custom'] = ['MyModule' => true];
 *     return $modules;
 * });
 * ```
 *
 * @package     MiIntegracionApi
 * @subpackage  Core
 * @since       1.0.0
 * @version     1.0.0
 * @author      Mi Integración API Team
 *
 * @see         ApiConnector Para la conexión con la API de Verial
 * @see         Auth_Manager Para la gestión de autenticación
 * @see         Cache_Manager Para el sistema de caché
 */

namespace MiIntegracionApi\Core;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

/**
 * Clase Module_Loader
 *
 * Gestor estático para la carga dinámica y ordenada de módulos del plugin.
 * Implementa un sistema de carga modular que separa componentes core de
 * características opcionales, permitiendo extensibilidad mediante hooks.
 *
 * Esta clase NO debe instanciarse. Todos los métodos son estáticos para
 * permitir acceso global durante la inicialización del plugin.
 *
 * Tipos de módulos gestionados:
 * - **Core**: Módulos esenciales cargados siempre (ApiConnector, Auth_Manager, Cache_Manager)
 * - **Feature**: Módulos de características opcionales (SyncManager, REST_API_Handler)
 * - **Custom**: Módulos añadidos por extensiones mediante filtros
 *
 * Orden de carga:
 * 1. Módulos core (load_core_modules)
 * 2. Módulos de características (load_feature_modules)
 * 3. Módulos custom registrados mediante filtro
 *
 * Hooks disponibles:
 * - `mi_integracion_api_available_modules`: Filtro para añadir módulos personalizados
 *
 * @package     MiIntegracionApi\Core
 * @since       1.0.0
 * @version     1.0.0
 *
 * @example
 * ```php
 * // Uso básico: cargar todos los módulos
 * Module_Loader::load_all();
 *
 * // Obtener lista de módulos disponibles
 * $available = Module_Loader::get_available_modules();
 * if (isset($available['core']['ApiConnector'])) {
 *     // ApiConnector está disponible
 * }
 * ```
 */
class Module_Loader {
    
    /**
     * Obtiene la lista de módulos disponibles en el plugin.
     *
     * Retorna un array estructurado con todos los módulos registrados en el sistema,
     * agrupados por tipo (core, feature, custom). Cada módulo tiene un estado booleano
     * que indica si está habilitado (true) o deshabilitado (false).
     *
     * El array retornado puede ser extendido mediante el filtro de WordPress
     * 'mi_integracion_api_available_modules', permitiendo que extensiones o
     * temas añadan sus propios módulos al sistema.
     *
     * Estructura del array retornado:
     * ```php
     * [
     *     'core' => [
     *         'ApiConnector' => true,      // Módulo habilitado
     *         'Auth_Manager' => true,
     *         'Cache_Manager' => true
     *     ],
     *     'feature' => [
     *         'SyncManager' => true,
     *         'REST_API_Handler' => true
     *     ],
     *     'custom' => [                    // Añadido mediante filtro
     *         'MyCustomModule' => false    // Módulo deshabilitado
     *     ]
     * ]
     * ```
     *
     * @return  array<string, array<string, bool>>  Array asociativo de módulos agrupados por tipo.
     *                                               Cada grupo contiene módulos con su estado (bool).
     * @since   1.0.0
     *
     * @hook    mi_integracion_api_available_modules  Filtro para extender la lista de módulos.
     *
     * @example
     * ```php
     * // Obtener módulos disponibles
     * $modules = Module_Loader::get_available_modules();
     *
     * // Verificar si un módulo core está disponible
     * if (isset($modules['core']['ApiConnector']) && $modules['core']['ApiConnector']) {
     *     echo 'ApiConnector está habilitado';
     * }
     *
     * // Añadir módulos personalizados mediante filtro
     * add_filter('mi_integracion_api_available_modules', function($modules) {
     *     $modules['custom']['MyModule'] = true;
     *     return $modules;
     * });
     * ```
     */
    public static function get_available_modules(): array {
        $modules = [
            'core' => [
                'ApiConnector' => true,
                'Auth_Manager' => true,
                'Cache_Manager' => true,
            ],
            'feature' => [
                'SyncManager' => true,
                'REST_API_Handler' => true,
            ]
        ];
        
        // Filtro para permitir que otros componentes añadan módulos
        if (function_exists('apply_filters')) {
            $filtered_modules = apply_filters('mi_integracion_api_available_modules', $modules);
            // Asegurar que siempre devolvemos un array
            if (is_array($filtered_modules)) {
                $modules = $filtered_modules;
            }
        }
        
        return $modules;
    }
    
    /**
     * Carga todos los módulos disponibles del plugin.
     *
     * Ejecuta la carga secuencial de módulos en el siguiente orden:
     * 1. Módulos core (esenciales para el funcionamiento básico)
     * 2. Módulos de características (funcionalidades adicionales)
     *
     * Este método debe llamarse durante la inicialización del plugin,
     * típicamente en el hook 'plugins_loaded' de WordPress.
     *
     * SIDE EFFECTS:
     * - Ejecuta require_once en múltiples archivos PHP
     * - Inicializa clases y registra hooks de los módulos cargados
     * - Puede generar errores fatales si archivos no existen
     *
     * @return  void
     * @since   1.0.0
     *
     * @example
     * ```php
     * // En el archivo principal del plugin
     * add_action('plugins_loaded', function() {
     *     Module_Loader::load_all();
     * });
     *
     * // O durante la activación
     * register_activation_hook(__FILE__, function() {
     *     Module_Loader::load_all();
     *     // ... resto de lógica de activación
     * });
     * ```
     */
    public static function load_all() {
        self::load_core_modules();
        self::load_feature_modules();
    }
    
    /**
     * Carga los módulos centrales del plugin.
     *
     * Carga los componentes esenciales necesarios para el funcionamiento básico
     * del plugin. Estos módulos se cargan SIEMPRE y en el orden especificado
     * para garantizar las dependencias correctas.
     *
     * Módulos core cargados:
     * - ApiConnector: Cliente de conexión con la API de Verial
     * - Auth_Manager: Gestor de autenticación y sesiones
     * - Cache_Manager: Sistema de caché para optimización
     *
     * SIDE EFFECTS:
     * - Ejecuta require_once en archivos de includes/Core/
     * - Depende de la constante global MiIntegracionApi_PLUGIN_DIR
     * - Verifica existencia de archivos antes de cargar (file_exists)
     * - No lanza excepciones si archivos faltan (fallo silencioso)
     *
     * @return  void
     * @since   1.0.0
     *
     * @global  string  $MiIntegracionApi_PLUGIN_DIR  Ruta absoluta al directorio del plugin.
     *
     * @example
     * ```php
     * // Uso interno (llamado por load_all)
     * Module_Loader::load_core_modules();
     *
     * // Los módulos se cargan desde:
     * // - includes/Core/ApiConnector.php
     * // - includes/Core/Auth_Manager.php
     * // - includes/Core/Cache_Manager.php
     * ```
     */
    private static function load_core_modules() {
        $core_modules = [
            'ApiConnector',
            'Auth_Manager',
            'Cache_Manager',
        ];
        
        foreach ($core_modules as $module) {
            $module_path = MiIntegracionApi_PLUGIN_DIR . 'includes/Core/' . $module . '.php';
            if (file_exists($module_path)) {
                require_once $module_path;
            }
        }
    }
    
    /**
     * Carga módulos de características específicas del plugin.
     *
     * Carga componentes opcionales que proporcionan funcionalidades adicionales
     * al plugin. Estos módulos se cargan después de los módulos core y pueden
     * depender de ellos.
     *
     * Módulos de características cargados:
     * - Sync/SyncManager: Gestor de sincronización bidireccional con Verial
     * - Rest/REST_API_Handler: Manejador de endpoints REST API personalizados
     *
     * A diferencia de los módulos core, estos módulos podrían hacerse opcionales
     * en futuras versiones mediante configuración o filtros.
     *
     * SIDE EFFECTS:
     * - Ejecuta require_once en archivos de includes/Sync/ e includes/Rest/
     * - Depende de la constante global MiIntegracionApi_PLUGIN_DIR
     * - Verifica existencia de archivos antes de cargar (file_exists)
     * - No lanza excepciones si archivos faltan (fallo silencioso)
     * - Puede registrar hooks y filtros de WordPress
     *
     * @return  void
     * @since   1.0.0
     *
     * @global  string  $MiIntegracionApi_PLUGIN_DIR  Ruta absoluta al directorio del plugin.
     *
     * @example
     * ```php
     * // Uso interno (llamado por load_all)
     * Module_Loader::load_feature_modules();
     *
     * // Los módulos se cargan desde:
     * // - includes/Sync/SyncManager.php
     * // - includes/Rest/REST_API_Handler.php
     * ```
     */
    private static function load_feature_modules() {
        $feature_modules = [
            'Sync/SyncManager',
            'Rest/REST_API_Handler',
        ];
        
        foreach ($feature_modules as $module) {
            $module_path = MiIntegracionApi_PLUGIN_DIR . 'includes/' . $module . '.php';
            if (file_exists($module_path)) {
                require_once $module_path;
            }
        }
    }
}