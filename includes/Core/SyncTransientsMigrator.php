<?php
/**
 * Clase para migrar transients de sincronización a base de datos
 * 
 * Esta clase maneja la migración de transients críticos de sincronización
 * desde WordPress transients a la tabla personalizada mia_transients_migration.
 * 
 * @package     MiIntegracionApi
 * @subpackage  Core
 * @since       1.0.0
 * @version     1.0.0
 */

namespace MiIntegracionApi\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase SyncTransientsMigrator
 * 
 * Gestiona la migración de transients críticos de sincronización
 * a la tabla mia_transients_migration para eliminar dependencias
 * de WordPress transients.
 */
class SyncTransientsMigrator
{
    /**
     * Prefijo para transients de sincronización
     * @var string
     */
    const SYNC_TRANSIENT_PREFIX = 'mia_sync_';
    
    /**
     * TTL por defecto para transients de sincronización (6 horas)
     * @var int
     */
    const DEFAULT_TTL = 21600;
    
    /**
     * Tabla de migración de transients
     * @var string
     */
    private static $migration_table;
    
    /**
     * Logger para registrar operaciones de migración
     * @var \MiIntegracionApi\Helpers\Logger
     */
    private static $logger;
    
    /**
     * Inicializa la clase
     * 
     * @return void
     */
    public static function init(): void
    {
        global $wpdb;
        
        // Verificar que $wpdb existe y tiene la propiedad prefix
        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->prefix)) {
            // En contextos fuera de WordPress (tests, scripts standalone), usar prefijo por defecto
            $table_prefix = defined('MIA_TABLE_PREFIX') ? MIA_TABLE_PREFIX : 'wp_';
            self::$migration_table = $table_prefix . 'mia_transients_migration';
        } else {
            self::$migration_table = $wpdb->prefix . 'mia_transients_migration';
        }
        
        if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
            self::$logger = new \MiIntegracionApi\Helpers\Logger('sync-transients-migrator');
        }
        
        // Asegurar que la tabla existe (solo en contexto WordPress)
        if (isset($wpdb) && is_object($wpdb)) {
            self::ensureMigrationTableExists();
        }
    }
    
    /**
     * Asegura que la tabla de migración existe
     * 
     * @return bool True si la tabla existe o se creó correctamente
     */
    private static function ensureMigrationTableExists(): bool
    {
        if (class_exists('MiIntegracionApi\\Core\\Installer')) {
            return \MiIntegracionApi\Core\Installer::create_transients_migration_table();
        }
        
        self::log('error', 'Installer no disponible para crear tabla de migración');
        return false;
    }
    
    /**
     * Migra un transient específico de sincronización a base de datos
     * 
     * @param string $transientKey Clave del transient
     * @param mixed  $data        Datos a migrar
     * @param int    $ttl         TTL en segundos (por defecto 6 horas)
     * @return bool True si la migración fue exitosa
     */
    public static function migrateSyncTransient(string $transientKey, $data, int $ttl = self::DEFAULT_TTL): bool
    {
        try {            
            // Validar que sea un transient de sincronización
            if (!self::isSyncTransient($transientKey)) {
                self::log('warning', "Intento de migrar transient no válido: {$transientKey}");
                return false;
            }
            
            // Verificar si ya existe en la base de datos
            if (self::transientExistsInDatabase($transientKey)) {
                self::log('info', "Transient ya migrado: {$transientKey}");
                return self::updateTransientInDatabase($transientKey, $data, $ttl);
            }
            
            // Migrar a base de datos
            $migrationResult = self::insertTransientInDatabase($transientKey, $data, $ttl);
            
            if ($migrationResult) {
                self::log('info', "Transient migrado exitosamente: {$transientKey}");
                
                // Eliminar transient de WordPress después de migración exitosa
                delete_transient($transientKey);
                
                return true;
            }
            
            self::log('error', "Error migrando transient: {$transientKey}");
            return false;
            
        } catch (\Exception $e) {
            self::log('error', "Excepción migrando transient {$transientKey}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtiene un transient de sincronización desde la base de datos
     * 
     * @param string $transientKey Clave del transient
     * @return mixed Los datos del transient o false si no existe
     */
    public static function getSyncTransient(string $transientKey)
    {
        try {            
            // Verificar si existe en la base de datos
            if (!self::transientExistsInDatabase($transientKey)) {
                // Fallback: intentar obtener desde WordPress transient
                $wp_transient = get_transient($transientKey);
                if ($wp_transient !== false) {
                    // Migrar automáticamente si existe en WordPress
                    self::migrateSyncTransient($transientKey, $wp_transient);
                    return $wp_transient;
                }
                return false;
            }
            
            // Obtener desde base de datos
            $data = self::getTransientFromDatabase($transientKey);
            
            if ($data !== false) {
                // Actualizar contador de accesos y timestamp
                self::updateAccessMetrics($transientKey);
                return $data;
            }
            
            return false;
            
        } catch (\Exception $e) {
            self::log('error', "Error obteniendo transient {$transientKey}: " . $e->getMessage());
            
            // Fallback a WordPress transient en caso de error
            return get_transient($transientKey);
        }
    }
    
    /**
     * Elimina un transient de sincronización
     * 
     * @param string $transientKey Clave del transient
     * @return bool True si se eliminó correctamente
     */
    public static function deleteSyncTransient(string $transientKey): bool
    {
        try {
            $deleted_from_db = self::deleteTransientFromDatabase($transientKey);
            $deleted_from_wp = delete_transient($transientKey);
            
            // Considerar exitoso si se eliminó de al menos una ubicación
            $success = $deleted_from_db || $deleted_from_wp;
            
            if ($success) {
                self::log('info', "Transient eliminado: {$transientKey}");
            } else {
                self::log('warning', "Transient no encontrado para eliminar: {$transientKey}");
            }
            
            return $success;
            
        } catch (\Exception $e) {
            self::log('error', "Error eliminando transient {$transientKey}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Migra todos los transients críticos de sincronización
     * 
     * @return array Resultado de la migración masiva
     */
    public static function migrateAllCriticalTransients(): array
    {
        $results = [
            'total' => 0,
            'migrated' => 0,
            'errors' => 0,
            'details' => []
        ];
        
        // Lista de transients críticos identificados en el análisis
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
        
        foreach ($criticalTransients as $transientKey) {
            $results['total']++;
            
            try {
                // Verificar si existe en WordPress
                $wp_data = get_transient($transientKey);
                
                if ($wp_data !== false) {
                    // Migrar si existe
                    $migration_success = self::migrateSyncTransient($transientKey, $wp_data);
                    
                    if ($migration_success) {
                        $results['migrated']++;
                        $results['details'][] = [
                            'transient' => $transientKey,
                            'status' => 'migrated',
                            'size' => self::estimateDataSize($wp_data)
                        ];
                    } else {
                        $results['errors']++;
                        $results['details'][] = [
                            'transient' => $transientKey,
                            'status' => 'migration_failed',
                            'error' => 'Error durante migración'
                        ];
                    }
                } else {
                    // No existe, marcar como no encontrado
                    $results['details'][] = [
                        'transient' => $transientKey,
                        'status' => 'not_found'
                    ];
                }
                
            } catch (\Exception $e) {
                $results['errors']++;
                $results['details'][] = [
                    'transient' => $transientKey,
                    'status' => 'exception',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        self::log('info', "Migración masiva completada: {$results['migrated']}/{$results['total']} transients migrados, {$results['errors']} errores");
        
        return $results;
    }
    
    /**
     * Verifica si una clave corresponde a un transient de sincronización
     * 
     * @param string $transientKey Clave del transient
     * @return bool True si es un transient de sincronización
     */
    private static function isSyncTransient(string $transientKey): bool
    {
        return strpos($transientKey, self::SYNC_TRANSIENT_PREFIX) === 0;
    }
    
    /**
     * Verifica si un transient existe en la base de datos
     * 
     * @param string $transientKey Clave del transient
     * @return bool True si existe en la base de datos
     */
    	private static function transientExistsInDatabase(string $transientKey): bool
	{
		global $wpdb;
		
		$table = self::$migration_table;
		// Optimización: usar EXISTS en lugar de COUNT(*) para mejor rendimiento
		$exists = $wpdb->get_var(
			$wpdb->prepare("SELECT 1 FROM {$table} WHERE cache_key = %s LIMIT 1", $transientKey)
		);
		
		return $exists !== null;
	}
    
    /**
     * Inserta un transient en la base de datos
     * 
     * @param string $transientKey Clave del transient
     * @param mixed  $data        Datos a insertar
     * @param int    $ttl         TTL en segundos
     * @return bool True si se insertó correctamente
     */
    private static function insertTransientInDatabase(string $transientKey, $data, int $ttl): bool
    {
        global $wpdb;
        
        try {
            // Serializar datos
            $serializedData = serialize($data);
            $originalSize = strlen($serializedData);
            
            // Comprimir datos
            $compressedData = gzcompress($serializedData, 6);
            if ($compressedData === false) {
                self::log('error', "No se pudo comprimir datos para transient: {$transientKey}");
                return false;
            }
            
            $compressedSize = strlen($compressedData);
            $compressionRatio = $compressedSize / $originalSize;
            
            // Calcular fecha de expiración
            $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
            
            // Preparar datos para inserción
            $insertData = [
                'cache_key' => $transientKey,
                'data' => $compressedData,
                'original_size_bytes' => $originalSize,
                'compressed_size_bytes' => $compressedSize,
                'compression_ratio' => $compressionRatio,
                'migrated_at' => current_time('mysql'),
                'last_accessed' => current_time('mysql'),
                'access_count' => 0
            ];
            
            // Insertar en base de datos
            $inserted = $wpdb->insert(
                self::$migration_table,
                $insertData,
                ['%s', '%s', '%d', '%d', '%f', '%s', '%s', '%d']
            );
            
            if ($inserted === false) {
                self::log('error', "Error insertando transient en BD: {$wpdb->last_error}");
                return false;
            }
            
            return true;
            
        } catch (\Exception $e) {
            self::log('error', "Excepción insertando transient: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Actualiza un transient existente en la base de datos
     * 
     * @param string $transientKey Clave del transient
     * @param mixed  $data        Nuevos datos
     * @param int    $ttl         Nuevo TTL
     * @return bool True si se actualizó correctamente
     */
    private static function updateTransientInDatabase(string $transientKey, $data, int $ttl): bool
    {
        global $wpdb;
        
        try {
            // Serializar y comprimir nuevos datos
            $serializedData = serialize($data);
            $originalSize = strlen($serializedData);
            $compressedData = gzcompress($serializedData, 6);
            
            if ($compressedData === false) {
                return false;
            }
            
            $compressedSize = strlen($compressedData);
            $compressionRatio = $compressedSize / $originalSize;
            
            // Calcular nueva fecha de expiración
            $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
            
            // Actualizar en base de datos
            $updated = $wpdb->update(
                self::$migration_table,
                [
                    'data' => $compressedData,
                    'original_size_bytes' => $originalSize,
                    'compressed_size_bytes' => $compressedSize,
                    'compression_ratio' => $compressionRatio,
                    'last_accessed' => current_time('mysql')
                ],
                ['cache_key' => $transientKey],
                ['%s', '%d', '%d', '%f', '%s'],
                ['%s']
            );
            
            return $updated !== false;
            
        } catch (\Exception $e) {
            self::log('error', "Error actualizando transient: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtiene un transient desde la base de datos
     * 
     * @param string $transientKey Clave del transient
     * @return mixed Los datos del transient o false si no existe
     */
    private static function getTransientFromDatabase(string $transientKey)
    {
        global $wpdb;
        
        try {
            $table = self::$migration_table;
            		$row = $wpdb->get_row(
			$wpdb->prepare("SELECT data FROM {$table} WHERE cache_key = %s", $transientKey),
			'ARRAY_A'
		);
            
            if (!$row || !isset($row['data'])) {
                return false;
            }
            
            // Descomprimir datos
            $decompressedData = gzuncompress($row['data']);
            if ($decompressedData === false) {
                self::log('error', "Error descomprimiendo datos del transient: {$transientKey}");
                return false;
            }
            
            // Deserializar datos
            $data = unserialize($decompressedData);
            
            return $data;
            
        } catch (\Exception $e) {
            self::log('error', "Error obteniendo transient desde BD: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Elimina un transient de la base de datos
     * 
     * @param string $transientKey Clave del transient
     * @return bool True si se eliminó correctamente
     */
    private static function deleteTransientFromDatabase(string $transientKey): bool
    {
        global $wpdb;
        
        try {
            $deleted = $wpdb->delete(
                self::$migration_table,
                ['cache_key' => $transientKey],
                ['%s']
            );
            
            return $deleted !== false;
            
        } catch (\Exception $e) {
            self::log('error', "Error eliminando transient de BD: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Actualiza métricas de acceso a un transient
     * 
     * @param string $transientKey Clave del transient
     * @return void
     */
    	private static function updateAccessMetrics(string $transientKey): void
	{
		global $wpdb;
		
		try {
			// Optimización: usar consulta preparada para mejor rendimiento y seguridad
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE " . self::$migration_table . " 
					 SET last_accessed = %s, access_count = access_count + 1 
					 WHERE cache_key = %s",
					current_time('mysql'),
					$transientKey
				)
			);
			
			// Verificar si la actualización fue exitosa
			if ($wpdb->rows_affected === 0) {
				self::log('warning', "No se pudo actualizar métricas para transient: {$transientKey}");
			}
		} catch (\Exception $e) {
			self::log('warning', "Error actualizando métricas de acceso: " . $e->getMessage());
		}
	}
    
    	/**
	 * Estima el tamaño de los datos
	 * 
	 * @param mixed $data Datos a medir
	 * @return int Tamaño estimado en bytes
	 */
	private static function estimateDataSize($data): int
	{
		return strlen(serialize($data));
	}

	/**
	 * Limpia transients de WordPress que ya han sido migrados
	 * 
	 * @return array Resultado de la limpieza
	 */
	public static function cleanupWordPressTransients(): array
	{
		$results = [
			'cleaned' => 0,
			'errors' => 0,
			'details' => []
		];

		// Lista de transients críticos que deben ser limpiados
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

		foreach ($criticalTransients as $transientKey) {
			try {
				// Verificar si existe en WordPress
				$wp_transient = get_transient($transientKey);
				
				if ($wp_transient !== false) {
					// Verificar si ya está migrado a base de datos
					if (self::transientExistsInDatabase($transientKey)) {
						// Eliminar de WordPress ya que está en BD
						$deleted = delete_transient($transientKey);
						
						if ($deleted) {
							$results['cleaned']++;
							$results['details'][] = [
								'transient' => $transientKey,
								'status' => 'cleaned',
								'location' => 'wordpress'
							];
						} else {
							$results['errors']++;
							$results['details'][] = [
								'transient' => $transientKey,
								'status' => 'cleanup_failed',
								'error' => 'Error eliminando de WordPress'
							];
						}
					} else {
						// No está migrado, mantener en WordPress por ahora
						$results['details'][] = [
							'transient' => $transientKey,
							'status' => 'not_migrated',
							'location' => 'wordpress_only'
						];
					}
				} else {
					// No existe en WordPress
					$results['details'][] = [
						'transient' => $transientKey,
						'status' => 'not_found',
						'location' => 'none'
					];
				}
				
			} catch (\Exception $e) {
				$results['errors']++;
				$results['details'][] = [
					'transient' => $transientKey,
					'status' => 'exception',
					'error' => $e->getMessage()
				];
			}
		}

		self::log('info', "Limpieza de transients de WordPress completada: {$results['cleaned']} limpiados, {$results['errors']} errores");
		
		return $results;
	}
    
    /**
     * Registra un mensaje en el log
     * 
     * @param string $level   Nivel del log (info, warning, error)
     * @param string $message Mensaje a registrar
     * @return void
     */
    private static function log(string $level, string $message): void
    {
        if (self::$logger) {
            switch ($level) {
                case 'info':
                    self::$logger->info($message);
                    break;
                case 'warning':
                    self::$logger->warning($message);
                    break;
                case 'error':
                    self::$logger->error($message);
                    break;
                default:
                    self::$logger->info($message);
            }
        }
    }
}
