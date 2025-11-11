<?php

declare(strict_types=1);

/**
 * Helper centralizado para verificaciones comunes de WooCommerce
 * 
 * Este helper consolida todas las verificaciones repetitivas de WooCommerce
 * en métodos optimizados con cache inteligente para mejorar el rendimiento.
 * 
 * @package MiIntegracionApi\Helpers
 * @since 2.4.0
 */

namespace MiIntegracionApi\Helpers;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

// Importar VerificationPerformanceTracker para monitoreo de rendimiento
use MiIntegracionApi\Helpers\VerificationPerformanceTracker;

class WooCommerceHelper {
    
    /**
     * Cache estático para verificaciones de funciones
     *
     * @var array
     */
    private static $function_cache = [];
    
    /**
     * Cache estático para verificaciones de clases
     *
     * @var array
     */
    private static $class_cache = [];
    
    /**
     * Cache estático para verificaciones de hooks
     *
     * @var array
     */
    private static $hook_cache = [];
    
    /**
     * TTL del cache en segundos (5 minutos)
     *
     * @var int
     */
    private const CACHE_TTL = 300;
    
    /**
     * Timestamp de la última limpieza de cache
     *
     * @var int
     */
    private static $last_cache_cleanup = 0;
    
    /**
     * Verifica si una función de WooCommerce está disponible (con cache)
     * 
     * @param string $function_name Nombre de la función a verificar
     * @param bool $force_refresh Forzar nueva verificación sin cache
     * @return bool True si la función está disponible
     */
    public static function isFunctionAvailable(string $function_name, bool $force_refresh = false): bool {
        $tracking_id = VerificationPerformanceTracker::startTracking('function_check', $function_name);
        
        try {
            self::cleanupCacheIfNeeded();
            
            if (!$force_refresh && isset(self::$function_cache[$function_name])) {
                VerificationPerformanceTracker::endTracking($tracking_id, true);
                return self::$function_cache[$function_name];
            }
            
            $available = function_exists($function_name);
            self::$function_cache[$function_name] = $available;
            
            VerificationPerformanceTracker::endTracking($tracking_id, true);
            return $available;
        } catch (\Exception $e) {
            VerificationPerformanceTracker::endTracking($tracking_id, false, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verifica si una clase de WooCommerce está disponible (con cache)
     * 
     * @param string $class_name Nombre de la clase a verificar
     * @param bool $force_refresh Forzar nueva verificación sin cache
     * @return bool True si la clase está disponible
     */
    public static function isClassAvailable(string $class_name, bool $force_refresh = false): bool {
        self::cleanupCacheIfNeeded();
        
        if (!$force_refresh && isset(self::$class_cache[$class_name])) {
            return self::$class_cache[$class_name];
        }
        
        $available = class_exists($class_name);
        self::$class_cache[$class_name] = $available;
        
        return $available;
    }
    
    /**
     * Verifica si WooCommerce está activo (con cache)
     * 
     * @param bool $force_refresh Forzar nueva verificación sin cache
     * @return bool True si WooCommerce está activo
     */
    public static function isWooCommerceActive(bool $force_refresh = false): bool {
        return self::isClassAvailable('WooCommerce', $force_refresh);
    }
    
    /**
     * Verifica si las funciones básicas de productos están disponibles (con cache)
     * 
     * @param bool $force_refresh Forzar nueva verificación sin cache
     * @return bool True si las funciones básicas están disponibles
     */
    public static function areProductFunctionsAvailable(bool $force_refresh = false): bool {
        $required_functions = [
            'wc_get_product',
            'wc_create_product',
            'wc_update_product',
            'wc_get_products'
        ];
        
        foreach ($required_functions as $function) {
            if (!self::isFunctionAvailable($function, $force_refresh)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Verifica si las funciones de atributos están disponibles (con cache)
     * 
     * @param bool $force_refresh Forzar nueva verificación sin cache
     * @return bool True si las funciones de atributos están disponibles
     */
    public static function areAttributeFunctionsAvailable(bool $force_refresh = false): bool {
        $required_functions = [
            'wc_get_attribute',
            'wc_create_attribute',
            'wc_get_attribute_taxonomy_by_name'
        ];
        
        foreach ($required_functions as $function) {
            if (!self::isFunctionAvailable($function, $force_refresh)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Verifica si las funciones de SKU están disponibles (con cache)
     * 
     * @param bool $force_refresh Forzar nueva verificación sin cache
     * @return bool True si las funciones de SKU están disponibles
     */
    public static function areSkuFunctionsAvailable(bool $force_refresh = false): bool {
        return self::isFunctionAvailable('wc_get_product_id_by_sku', $force_refresh);
    }
    
    /**
     * Verifica si las clases de productos están disponibles (con cache)
     * 
     * @param bool $force_refresh Forzar nueva verificación sin cache
     * @return bool True si las clases de productos están disponibles
     */
    public static function areProductClassesAvailable(bool $force_refresh = false): bool {
        $required_classes = [
            'WC_Product',
            'WC_Product_Simple',
            'WC_Product_Variable'
        ];
        
        foreach ($required_classes as $class) {
            if (!self::isClassAvailable($class, $force_refresh)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Verifica si un hook de WooCommerce está disponible (con cache)
     * 
     * @param string $hook_name Nombre del hook a verificar
     * @param bool $force_refresh Forzar nueva verificación sin cache
     * @return bool True si el hook está disponible
     */
    public static function isHookAvailable(string $hook_name, bool $force_refresh = false): bool {
        self::cleanupCacheIfNeeded();
        
        if (!$force_refresh && isset(self::$hook_cache[$hook_name])) {
            return self::$hook_cache[$hook_name];
        }
        
        $available = has_action($hook_name) !== false || has_filter($hook_name) !== false;
        self::$hook_cache[$hook_name] = $available;
        
        return $available;
    }
    
    /**
     * Obtiene la versión de WooCommerce (con cache)
     * 
     * @param bool $force_refresh Forzar nueva verificación sin cache
     * @return string|null Versión de WooCommerce o null si no está disponible
     */
    public static function getWooCommerceVersion(bool $force_refresh = false): ?string {
        if (!self::isWooCommerceActive($force_refresh)) {
            return null;
        }
        
        if (defined('WC_VERSION')) {
            return WC_VERSION;
        }
        
        if (function_exists('WC') && WC() !== null) {
            return WC()->version ?? null;
        }
        
        return null;
    }
    
    /**
     * Verifica si WooCommerce está listo para sincronización (con cache)
     * 
     * @param bool $force_refresh Forzar nueva verificación sin cache
     * @return bool True si WooCommerce está listo para sincronización
     */
    public static function isReadyForSync(bool $force_refresh = false): bool {
        return self::isWooCommerceActive($force_refresh) && 
               self::areProductFunctionsAvailable($force_refresh) && 
               self::areProductClassesAvailable($force_refresh);
    }
    
    /**
     * Obtiene el estado completo de WooCommerce (con cache)
     * 
     * @param bool $force_refresh Forzar nueva verificación sin cache
     * @return array Estado completo de WooCommerce
     */
    public static function getCompleteStatus(bool $force_refresh = false): array {
        return [
            'active' => self::isWooCommerceActive($force_refresh),
            'version' => self::getWooCommerceVersion($force_refresh),
            'product_functions' => self::areProductFunctionsAvailable($force_refresh),
            'attribute_functions' => self::areAttributeFunctionsAvailable($force_refresh),
            'sku_functions' => self::areSkuFunctionsAvailable($force_refresh),
            'product_classes' => self::areProductClassesAvailable($force_refresh),
            'ready_for_sync' => self::isReadyForSync($force_refresh),
            'timestamp' => time()
        ];
    }
    
    /**
     * Verificación lazy de WooCommerce (solo cuando necesaria)
     * 
     * @param string $context Contexto de la verificación
     * @return bool True si WooCommerce está disponible para el contexto
     */
    public static function lazyVerifyWooCommerce(string $context = 'general'): bool {
        switch ($context) {
            case 'products':
                return self::isReadyForSync();
            case 'attributes':
                return self::isWooCommerceActive() && self::areAttributeFunctionsAvailable();
            case 'sku':
                return self::isWooCommerceActive() && self::areSkuFunctionsAvailable();
            case 'hooks':
                return self::isWooCommerceActive();
            default:
                return self::isWooCommerceActive();
        }
    }
    
    /**
     * Limpia el cache si es necesario
     * 
     * @return void
     */
    private static function cleanupCacheIfNeeded(): void {
        $now = time();
        if ($now - self::$last_cache_cleanup > self::CACHE_TTL) {
            self::$function_cache = [];
            self::$class_cache = [];
            self::$hook_cache = [];
            self::$last_cache_cleanup = $now;
        }
    }
    
    /**
     * Limpia todo el cache manualmente
     * 
     * @return void
     */
    public static function clearCache(): void {
        self::$function_cache = [];
        self::$class_cache = [];
        self::$hook_cache = [];
        self::$last_cache_cleanup = time();
    }
    
    /**
     * Obtiene estadísticas del cache
     * 
     * @return array Estadísticas del cache
     */
    public static function getCacheStats(): array {
        return [
            'function_cache_size' => count(self::$function_cache),
            'class_cache_size' => count(self::$class_cache),
            'hook_cache_size' => count(self::$hook_cache),
            'last_cleanup' => self::$last_cache_cleanup,
            'cache_age' => time() - self::$last_cache_cleanup
        ];
    }
}
