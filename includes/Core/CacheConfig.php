<?php

declare(strict_types=1);

/**
 * Clase para manejar la configuración de caché del plugin
 * 
 * @package MiIntegracionApi\Core
 */

namespace MiIntegracionApi\Core;

if (!defined('ABSPATH')) {
    exit;
}

class CacheConfig {
    /**
     * Opciones de configuración de caché
     */
    const OPTIONS = [
        'enabled' => 'mi_integracion_api_cache_enabled',
        'default_ttl' => 'mi_integracion_api_cache_default_ttl',
        'storage_method' => 'mi_integracion_api_cache_storage_method',
        'entity_ttls' => 'mi_integracion_api_cache_entity_ttls'
    ];

    /**
     * TTLs predeterminados por entidad
     */
    const DEFAULT_ENTITY_TTLS = [
        'product' => 3600,    // 1 hora
        'order' => 1800,      // 30 minutos
        'customer' => 7200,   // 2 horas
        'category' => 86400,  // 24 horas
        'global' => 300       // 5 minutos
    ];

    /**
     * Constantes de tiempo de caché para endpoints de API
     * Centralizadas para evitar duplicación y facilitar mantenimiento
     */
    const CACHE_EXPIRATION_NONE = 0;                    // No cachear
    const CACHE_EXPIRATION_15_MINUTES = 15 * MINUTE_IN_SECONDS;  // 15 minutos
    const CACHE_EXPIRATION_1_HOUR = HOUR_IN_SECONDS;            // 1 hora
    const CACHE_EXPIRATION_6_HOURS = 6 * HOUR_IN_SECONDS;       // 6 horas
    const CACHE_EXPIRATION_12_HOURS = 12 * HOUR_IN_SECONDS;     // 12 horas
    const CACHE_EXPIRATION_24_HOURS = 24 * HOUR_IN_SECONDS;     // 24 horas
    const CACHE_EXPIRATION_7_DAYS = 7 * DAY_IN_SECONDS;         // 7 días

    /**
     * TTLs específicos para endpoints de Verial API
     */
    const VERIAL_API_CACHE_TTLS = [
        // Endpoints de escritura (no cachear)
        'write_operations' => self::CACHE_EXPIRATION_NONE,
        
        // Endpoints de consulta rápida (15 minutos)
        'quick_queries' => self::CACHE_EXPIRATION_15_MINUTES,
        
        // Endpoints de datos dinámicos (1 hora)
        'dynamic_data' => self::CACHE_EXPIRATION_1_HOUR,
        
        // Endpoints de datos semi-estáticos (6 horas)
        'semi_static_data' => self::CACHE_EXPIRATION_6_HOURS,
        
        // Endpoints de datos estáticos (12 horas)
        'static_data' => self::CACHE_EXPIRATION_12_HOURS,
        
        // Endpoints de datos muy estáticos (24 horas)
        'very_static_data' => self::CACHE_EXPIRATION_24_HOURS,
    ];

    /**
     * Obtiene el TTL configurado para una entidad específica
     * 
     * @param string $entity Nombre de la entidad
     * @return int TTL en segundos
     */
    public static function get_ttl_for_entity(string $entity): int {
        $entity_ttls = get_option(self::OPTIONS['entity_ttls'], self::DEFAULT_ENTITY_TTLS);
        return $entity_ttls[$entity] ?? self::DEFAULT_ENTITY_TTLS['global'];
    }

    /**
     * Establece el TTL para una entidad específica
     * 
     * @param string $entity Nombre de la entidad
     * @param int $ttl TTL en segundos
     * @return bool True si se actualizó correctamente
     */
    public static function set_ttl_for_entity(string $entity, int $ttl): bool {
        $entity_ttls = get_option(self::OPTIONS['entity_ttls'], self::DEFAULT_ENTITY_TTLS);
        $entity_ttls[$entity] = max(60, $ttl); // Mínimo 60 segundos
        return update_option(self::OPTIONS['entity_ttls'], $entity_ttls);
    }

    /**
     * Obtiene el TTL predeterminado global
     * 
     * @return int TTL en segundos
     */
    public static function get_default_ttl(): int {
        return (int) get_option(self::OPTIONS['default_ttl'], self::DEFAULT_ENTITY_TTLS['global']);
    }

    /**
     * Establece el TTL predeterminado global
     * 
     * @param int $ttl TTL en segundos
     * @return bool True si se actualizó correctamente
     */
    public static function set_default_ttl(int $ttl): bool {
        return update_option(self::OPTIONS['default_ttl'], max(60, $ttl));
    }

    /**
     * Verifica si la caché está habilitada
     * 
     * @return bool True si está habilitada
     */
    public static function is_enabled(): bool {
        return (bool) get_option(self::OPTIONS['enabled'], true);
    }

    /**
     * Habilita o deshabilita la caché
     * 
     * @param bool $enabled Estado deseado
     * @return bool True si se actualizó correctamente
     */
    public static function set_enabled(bool $enabled): bool {
        return update_option(self::OPTIONS['enabled'], $enabled);
    }

    /**
     * Obtiene el método de almacenamiento configurado
     * 
     * @return string Método de almacenamiento
     */
    public static function get_storage_method(): string {
        return get_option(self::OPTIONS['storage_method'], 'transient');
    }

    /**
     * Establece el método de almacenamiento
     * 
     * @param string $method Método de almacenamiento
     * @return bool True si se actualizó correctamente
     */
    public static function set_storage_method(string $method): bool {
        $valid_methods = ['transient', 'file', 'apcu'];
        if (!in_array($method, $valid_methods)) {
            return false;
        }
        return update_option(self::OPTIONS['storage_method'], $method);
    }

    /**
     * Obtiene el TTL recomendado para un endpoint específico
     * 
     * @param string $endpoint_name Nombre del endpoint
     * @return int TTL en segundos
     */
    public static function get_endpoint_cache_ttl(string $endpoint_name): int {
        // Mapeo de endpoints a categorías de caché
        $endpoint_mapping = [
            // Endpoints de escritura (no cachear)
            'NuevoClienteWS' => 'write_operations',
            'NuevoDocClienteWS' => 'write_operations',
            'NuevoPagoWS' => 'write_operations',
            'NuevaMascotaWS' => 'write_operations',
            'NuevaDireccionEnvioWS' => 'write_operations',
            'NuevaProvinciaWS' => 'write_operations',
            'NuevaLocalidadWS' => 'write_operations',
            'UpdateDocClienteWS' => 'write_operations',
            'BorrarMascotaWS' => 'write_operations',
            'GetPDFDocClienteWS' => 'write_operations',
            'PedidoModificableWS' => 'write_operations',
            
            // Endpoints de consulta rápida (15 minutos)
            'GetNextNumDocsWS' => 'quick_queries',
            
            // Endpoints de datos dinámicos (1 hora)
            'GetClientesWS' => 'dynamic_data',
            'GetStockArticulosWS' => 'dynamic_data',
            'GetNumArticulosWS' => 'semi_static_data',
            'GetCondicionesTarifaWS' => 'dynamic_data',
            'EstadoPedidosWS' => 'dynamic_data',
            'GetMascotasWS' => 'dynamic_data',
            'GetHistorialPedidosWS' => 'dynamic_data',
            'GetDocumentosClienteWS' => 'dynamic_data',
            
            // Endpoints de datos semi-estáticos (6 horas)
            'GetImagenesArticulosWS' => 'semi_static_data',
            
            // Endpoints de datos estáticos (12 horas)
            'GetPaisesWS' => 'static_data',
            'GetProvinciasWS' => 'static_data',
            'GetLocalidadesWS' => 'static_data',
            'GetCategoriasWS' => 'static_data',
            'GetCategoriasWebWS' => 'static_data',
            'GetAgentesWS' => 'static_data',
            'GetFabricantesWS' => 'static_data',
            'GetCursosWS' => 'static_data',
            'GetAsignaturasWS' => 'static_data',
            'GetColeccionesWS' => 'static_data',
            'GetMetodosPagoWS' => 'static_data',
            'GetFormasEnvioWS' => 'static_data',
            'GetVersionWS' => 'static_data',
            
            // Endpoints de datos muy estáticos (24 horas)
            'GetArbolCamposConfigurablesArticulosWS' => 'very_static_data',
            'GetCamposConfigurablesArticulosWS' => 'very_static_data',
            'GetValoresValidadosCampoConfigurableArticulosWS' => 'very_static_data',
        ];

        $category = $endpoint_mapping[$endpoint_name] ?? 'static_data';
        return self::VERIAL_API_CACHE_TTLS[$category] ?? self::CACHE_EXPIRATION_12_HOURS;
    }

    /**
     * Obtiene todas las constantes de tiempo de caché disponibles
     * 
     * @return array Array asociativo con las constantes de caché
     */
    public static function get_cache_constants(): array {
        return [
            'CACHE_EXPIRATION_NONE' => self::CACHE_EXPIRATION_NONE,
            'CACHE_EXPIRATION_15_MINUTES' => self::CACHE_EXPIRATION_15_MINUTES,
            'CACHE_EXPIRATION_1_HOUR' => self::CACHE_EXPIRATION_1_HOUR,
            'CACHE_EXPIRATION_6_HOURS' => self::CACHE_EXPIRATION_6_HOURS,
            'CACHE_EXPIRATION_12_HOURS' => self::CACHE_EXPIRATION_12_HOURS,
            'CACHE_EXPIRATION_24_HOURS' => self::CACHE_EXPIRATION_24_HOURS,
            'CACHE_EXPIRATION_7_DAYS' => self::CACHE_EXPIRATION_7_DAYS,
        ];
    }
} 