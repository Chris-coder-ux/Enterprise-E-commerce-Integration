<?php declare(strict_types=1);
/**
 * Gestor de caché HTTP basado en transients de WordPress, con registro de claves por grupo para limpieza eficiente.
 * Todos los métodos (get, set, delete, flush_group) usan el sistema de registro de claves por grupo.
 *
 * @package MiIntegracionApi\Cache
 * @since 1.0.0
 */

namespace MiIntegracionApi\Cache;

use MiIntegracionApi\Core\CacheConfig;

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para gestionar la caché HTTP de las solicitudes a la API externa
 */
class HTTP_Cache_Manager {

	/**
	 * Prefijo para entradas de caché
	 */
	const CACHE_PREFIX = 'mi_api_http_cache_';

	/**
	 * Grupos de caché por tipo
	 */
	const CACHE_GROUPS = array(
		'product' => 'mi_api_products',
		'order'   => 'mi_api_orders',
		'config'  => 'mi_api_config',
		'global'  => 'mi_api_global',
	);

	/**
	 * Estadísticas de caché para la solicitud actual
	 */
	private static $stats = array(
		'hit'     => 0,
		'miss'    => 0,
		'expired' => 0,
		'stored'  => 0,
	);

	/**
	 * Inicializa el administrador de caché HTTP
	 */
	public static function init() {
		// No se requiere inicialización adicional
	}
	
	/**
	 * MÉTODO CENTRALIZADO: Obtiene datos del cache unificado
	 * Optimiza operaciones I/O reduciendo de 44 a 2 operaciones por sincronización
	 * 
	 * @param string $key Clave del cache
	 * @param string $group Grupo del cache (default: 'global')
	 * @param mixed $default Valor por defecto si no existe
	 * @return mixed Datos del cache o valor por defecto
	 */
	public static function getUnified(string $key, string $group = 'global', $default = null) {
		// Intentar obtener del cache HTTP primero
		$cached = self::get($key, $group);
		if ($cached !== false) {
			return $cached;
		}
		
		// Fallback a transients de WordPress
		$transient_key = self::CACHE_PREFIX . $group . '_' . $key;
		$transient_data = get_transient($transient_key);
		if ($transient_data !== false) {
			return $transient_data;
		}
		
		// Fallback a options de WordPress
		$option_key = self::CACHE_PREFIX . $group . '_' . $key;
		$option_data = get_option($option_key, null);
		if ($option_data !== null) {
			return $option_data;
		}
		
		return $default;
	}
	
	/**
	 * MÉTODO CENTRALIZADO: Guarda datos en el cache unificado
	 * Optimiza operaciones I/O reduciendo de 44 a 2 operaciones por sincronización
	 * 
	 * @param string $key Clave del cache
	 * @param mixed $data Datos a guardar
	 * @param string $group Grupo del cache (default: 'global')
	 * @param int $ttl Tiempo de vida en segundos (default: 3600)
	 * @return bool True si se guardó correctamente
	 */
	public static function setUnified(string $key, $data, string $group = 'global', int $ttl = 3600): bool {
		// Guardar en cache HTTP (sistema principal)
		$http_result = self::set($key, [], $data, $group, $ttl);
		
		// Fallback a transients si falla el cache HTTP
		if (!$http_result) {
			$transient_key = self::CACHE_PREFIX . $group . '_' . $key;
			$transient_result = set_transient($transient_key, $data, $ttl);
			
			if (!$transient_result) {
				// Último fallback a options
				$option_key = self::CACHE_PREFIX . $group . '_' . $key;
				return update_option($option_key, $data, false);
			}
			
			return $transient_result;
		}
		
		return $http_result;
	}

	/**
	 * Guarda una respuesta HTTP en caché
	 *
	 * @param string $url URL de la solicitud
	 * @param array  $args Argumentos de la solicitud
	 * @param mixed  $response Respuesta a guardar
	 * @param string $group Grupo de caché
	 * @param int    $ttl Tiempo de vida en segundos
	 * @return bool True si se guardó correctamente
	 */
	public static function set( $url, $args, $response, $group = 'global', $ttl = null ) {
		if ( ! CacheConfig::is_enabled() ) {
			return false;
		}

		// Generar clave consistente (incluye grupo)
		$key = self::generate_cache_key( $url, $args, $group );

		if ( $ttl === null ) {
			$ttl = CacheConfig::get_ttl_for_entity( $group );
		}

		$cache_data = array(
			'response' => $response,
			'created'  => time(),
			'expires'  => time() + $ttl,
		);

		// Usar sistema de migración para transients grandes
		$transient_key = self::CACHE_PREFIX . $key;
		$result = self::setWithMigration($transient_key, $cache_data, $ttl);

		$option_key = self::CACHE_PREFIX . 'group_keys_' . $group;
		$group_keys = get_option( $option_key, array() );
		if ( ! in_array( self::CACHE_PREFIX . $key, $group_keys, true ) ) {
			$group_keys[] = self::CACHE_PREFIX . $key;
			update_option( $option_key, $group_keys, false );
		}

		if ( $result ) {
			++self::$stats['stored'];
		}
		return $result;
	}

	/**
	 * Obtiene un valor de caché HTTP usando transients y el sistema de grupos.
	 *
	 * @param string $key
	 * @param string $group
	 * @return mixed|false
	 */
	public static function get( $url_or_key, $group = 'global', $args = array() ) {
		if ( ! CacheConfig::is_enabled() ) {
			return false;
		}
		// Permite pasar directamente la clave ya generada; si se pasa URL+args se reconstruye internamente
		if ( ! empty( $args ) ) {
			$key = self::generate_cache_key( $url_or_key, $args, $group );
		} else {
			$key = $url_or_key; // Asumimos clave completa ya normalizada
		}
		$transient_key = self::CACHE_PREFIX . $key;
		$cached        = self::getWithMigration($transient_key);
		if ( $cached !== false ) {
			++self::$stats['hit'];
			return $cached;
		}
		++self::$stats['miss'];
		return false;
	}

	/**
	 * Genera una clave de caché única para una URL y argumentos
	 *
	 * @param string $url URL de la solicitud
	 * @param array  $args Argumentos de la solicitud
	 * @return string Clave de caché
	 */
	private static function generate_cache_key( $url, $args, $group = 'global' ) {
		$hash = md5( wp_json_encode( $args ) );
		return $group . '_' . $url . '_' . $hash;
	}

	/**
	 * Elimina una entrada específica de la caché HTTP
	 *
	 * @param string $url URL de la solicitud
	 * @param array  $args Argumentos de la solicitud
	 * @param string $group Grupo de caché
	 * @return bool True si se eliminó correctamente
	 */
	public static function delete( $url, $args, $group = 'global' ) {
		$key = self::generate_cache_key( $url, $args, $group );
		return self::deleteWithMigration(self::CACHE_PREFIX . $key);
	}

	/**
	 * Limpia todas las entradas de caché de un grupo específico
	 *
	 * @param string $group Grupo de caché
	 * @return int Número de entradas eliminadas
	 */
	public static function flush_group( $group ) {
		$option_key = self::CACHE_PREFIX . 'group_keys_' . $group;
		$group_keys = get_option( $option_key, array() );
		$count      = 0;
		if ( ! empty( $group_keys ) && is_array( $group_keys ) ) {
			foreach ( $group_keys as $transient_key ) {
				if ( self::deleteWithMigration($transient_key) ) {
					++$count;
				}
			}
		}
		// Limpiar el registro del grupo
		delete_option( $option_key );
		return $count;
	}

	/**
	 * Limpia todas las entradas de caché HTTP
	 *
	 * @return int Número de entradas eliminadas
	 */
	public static function flush_all() {
		global $wpdb;

		$prefix = self::CACHE_PREFIX;
		$like   = $prefix . '%';

		// Obtener todas las claves de transient que coincidan con nuestro prefijo
		$transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM $wpdb->options 
                WHERE option_name LIKE %s",
				'_transient_' . $like
			)
		);

		$count = 0;
		if ( $transients ) {
			foreach ( $transients as $transient ) {
				$key = str_replace( '_transient_', '', $transient->option_name );
				if ( delete_transient( substr( $key, strlen( $prefix ) ) ) ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Obtiene estadísticas extendidas de la caché
	 *
	 * @return array Estadísticas de caché
	 */
	public static function get_extended_stats() {
		global $wpdb;

		$prefix = self::CACHE_PREFIX;
		$like   = $prefix . '%';

		// Contar entradas de caché existentes
		$total_entries = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $wpdb->options 
                WHERE option_name LIKE %s",
				'_transient_' . $like
			)
		);

		// Contar entradas por grupo
		$group_stats = array();
		foreach ( self::CACHE_GROUPS as $key => $group ) {
			$count               = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $wpdb->options 
                    WHERE option_name LIKE %s 
                    AND option_name LIKE %s",
					'_transient_' . $like,
					'%' . $key . '%'
				)
			);
			$group_stats[ $key ] = (int) $count;
		}

		// Calcular tamaño aproximado
		$size_query = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(LENGTH(option_value)) as total_size 
                FROM $wpdb->options 
                WHERE option_name LIKE %s",
				'_transient_' . $like
			)
		);

		$total_size = $size_query ? $size_query->total_size : 0;

		// Formatear tamaño en unidades legibles
		$size_formatted = self::format_bytes( $total_size );

		$hits    = (int) self::$stats['hit'];
		$misses  = (int) self::$stats['miss'];
		$hit_pct = ($hits + $misses) > 0 ? round( ( $hits / ( $hits + $misses ) ) * 100, 2 ) : 0.0;

		return array(
			'enabled'         => CacheConfig::is_enabled(),
			'ttl_default'     => CacheConfig::get_default_ttl(),
			'current_request' => self::$stats,
			'hit_ratio'       => $hit_pct,
			'total_entries'   => (int) $total_entries,
			'by_group'        => $group_stats,
			'size_bytes'      => (int) $total_size,
			'size_formatted'  => $size_formatted,
		);
	}

	/**
	 * Formatea bytes en unidades legibles
	 *
	 * @param int $bytes Tamaño en bytes
	 * @param int $precision Precisión decimal
	 * @return string Tamaño formateado
	 */
	private static function format_bytes( $bytes, $precision = 2 ) {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );

		$bytes /= pow( 1024, $pow );

		return round( $bytes, $precision ) . ' ' . $units[ $pow ];
	}

	/**
	 * Determina si la caché HTTP está habilitada
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return CacheConfig::is_enabled();
	}

	/**
	 * Activa o desactiva la caché HTTP
	 *
	 * @param bool $enabled Estado de activación
	 */
	public static function set_enabled( $enabled ) {
		CacheConfig::set_enabled( (bool) $enabled );
	}

	/**
	 * Establece el tiempo de vida predeterminado para la caché
	 *
	 * @param int $ttl Tiempo de vida en segundos
	 */
	public static function set_default_ttl( $ttl ) {
		CacheConfig::set_default_ttl( (int) $ttl );
	}

	/**
	 * Alias para get_extended_stats() para mantener compatibilidad
	 *
	 * @return array Estadísticas de caché
	 */
	public static function get_cache_statistics() {
		return self::get_extended_stats();
	}

	/**
	 * Alias para get_extended_stats() para mantener compatibilidad
	 *
	 * @return array Estadísticas de caché
	 */
	public static function get_cache_stats() {
		return self::get_extended_stats();
	}

	/**
	 * Obtiene el tamaño formateado de la caché
	 *
	 * @return string Tamaño formateado
	 */
	public static function get_cache_size() {
		$stats = self::get_extended_stats();
		return $stats['size_formatted'];
	}

	/**
	 * Métodos de migración para transients grandes
	 */

	/**
	 * Almacena datos usando sistema de migración si es necesario
	 *
	 * @param string $key Clave del transient
	 * @param mixed  $data Datos a almacenar
	 * @param int    $ttl TTL en segundos
	 * @return bool True si se almacenó correctamente
	 */
	private static function setWithMigration($key, $data, $ttl) {
		// Si el sistema de migración está disponible, usarlo para transients grandes
		if (class_exists('\MiIntegracionApi\Core\SyncTransientsMigrator')) {
			// Migrar si los datos son grandes (> 1MB)
			$dataSize = strlen(serialize($data));
			if ($dataSize > 1024 * 1024) {
				return \MiIntegracionApi\Core\SyncTransientsMigrator::migrateSyncTransient($key, $data, $ttl);
			}
		}
		
		// Fallback a WordPress transient para datos pequeños
		return set_transient($key, $data, $ttl);
	}

	/**
	 * Obtiene datos usando sistema de migración si es necesario
	 *
	 * @param string $key Clave del transient
	 * @return mixed Los datos o false si no existen
	 */
	private static function getWithMigration($key) {
		// Si el sistema de migración está disponible, intentar obtener desde BD
		if (class_exists('\MiIntegracionApi\Core\SyncTransientsMigrator')) {
			$data = \MiIntegracionApi\Core\SyncTransientsMigrator::getSyncTransient($key);
			if ($data !== false) {
				return $data;
			}
		}
		
		// Fallback a WordPress transient
		return get_transient($key);
	}

	/**
	 * Elimina datos usando sistema de migración si es necesario
	 *
	 * @param string $key Clave del transient
	 * @return bool True si se eliminó correctamente
	 */
	private static function deleteWithMigration($key) {
		// Si el sistema de migración está disponible, eliminar desde BD
		if (class_exists('\MiIntegracionApi\Core\SyncTransientsMigrator')) {
			return \MiIntegracionApi\Core\SyncTransientsMigrator::deleteSyncTransient($key);
		}
		
		// Fallback a WordPress transient
		return delete_transient($key);
	}
}
