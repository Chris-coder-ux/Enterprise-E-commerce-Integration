<?php
/**
 * Funciones de compatibilidad para transients de sincronización
 * 
 * Este archivo proporciona funciones que reemplazan las funciones
 * nativas de WordPress transients para transients críticos de
 * sincronización, redirigiendo a la tabla personalizada.
 * 
 * @package     MiIntegracionApi
 * @subpackage  Helpers
 * @since       1.0.0
 * @version     1.0.0
 */


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reemplaza set_transient para transients críticos de sincronización
 * 
 * @param string $key   Clave del transient
 * @param mixed  $value Valor a almacenar
 * @param int    $ttl   TTL en segundos (por defecto 6 horas)
 * @return bool True si se almacenó correctamente
 */
function mia_set_sync_transient(string $key, $value, int $ttl = 21600): bool
{
	// Verificar si es un transient crítico de sincronización
	if (isSyncTransient($key)) {
		// Usar el migrador para almacenar en base de datos
		if (class_exists('\MiIntegracionApi\Core\SyncTransientsMigrator')) {
			return \MiIntegracionApi\Core\SyncTransientsMigrator::migrateSyncTransient($key, $value, $ttl);
		}
		// Si no está disponible el migrador, usar opciones de WordPress como fallback
		$option_key = 'mia_sync_transient_' . sanitize_key($key);
		return update_option($option_key, $value, false);
	}
	
	// Para transients no críticos, usar WordPress transients
	return set_transient($key, $value, $ttl);
}

/**
 * Reemplaza get_transient para transients críticos de sincronización
 * 
 * @param string $key Clave del transient
 * @return mixed Los datos del transient o false si no existe
 */
function mia_get_sync_transient(string $key)
{
	// Verificar si es un transient crítico de sincronización
	if (isSyncTransient($key)) {
		// Usar el migrador para obtener desde base de datos
		if (class_exists('\MiIntegracionApi\Core\SyncTransientsMigrator')) {
			return \MiIntegracionApi\Core\SyncTransientsMigrator::getSyncTransient($key);
		}
		// Si no está disponible el migrador, usar opciones de WordPress como fallback
		$option_key = 'mia_sync_transient_' . sanitize_key($key);
		return get_option($option_key, false);
	}
	
	// Para transients no críticos, usar WordPress transients
	return get_transient($key);
}

/**
 * Reemplaza delete_transient para transients críticos de sincronización
 * 
 * @param string $key Clave del transient
 * @return bool True si se eliminó correctamente
 */
function mia_delete_sync_transient(string $key): bool
{
	// Verificar si es un transient crítico de sincronización
	if (isSyncTransient($key)) {
		// Usar el migrador para eliminar desde base de datos
		if (class_exists('\MiIntegracionApi\Core\SyncTransientsMigrator')) {
			return \MiIntegracionApi\Core\SyncTransientsMigrator::deleteSyncTransient($key);
		}
		// Si no está disponible el migrador, eliminar opción de WordPress
		$option_key = 'mia_sync_transient_' . sanitize_key($key);
		return delete_option($option_key);
	}
	
	// Para transients no críticos, usar WordPress transients
	return delete_transient($key);
}

/**
 * Verifica si una clave corresponde a un transient crítico de sincronización
 * 
 * @param string $key Clave del transient
 * @return bool True si es un transient crítico
 */
function isSyncTransient(string $key): bool
{
    $criticalTransients = [
        'mia_sync_progress',
        'mia_sync_start_time',
        'mia_sync_current_batch_offset',
        'mia_sync_current_batch_limit',
        'mia_sync_current_batch_time',
        'mia_sync_batch_start_time',
        'mia_sync_current_product_sku',
        'mia_sync_current_product_name',
        'mia_last_product',
        'mia_sync_last_product_time',
        'mia_sync_processed_skus',
        'mia_sync_completed_batches',
        'mia_sync_batch_times',
        'mia_last_client_name',
        'mia_last_client_email',
        'mia_last_order_ref',
        'mia_last_order_client'
    ];
    
    return in_array($key, $criticalTransients, true);
}

/**
 * Inicializa el sistema de compatibilidad de transients
 * 
 * @return void
 */
function initTransientCompatibility(): void
{
    // Inicializar el migrador si está disponible
    if (class_exists('\MiIntegracionApi\Core\SyncTransientsMigrator')) {
        \MiIntegracionApi\Core\SyncTransientsMigrator::init();
    }
}

// Inicializar automáticamente cuando se incluye este archivo
initTransientCompatibility();
