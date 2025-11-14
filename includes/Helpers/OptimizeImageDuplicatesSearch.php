<?php

declare(strict_types=1);

namespace MiIntegracionApi\Helpers;

use MiIntegracionApi\Helpers\Logger;

/**
 * Optimizador de búsqueda de duplicados de imágenes.
 *
 * Crea índices compuestos en wp_postmeta para acelerar la búsqueda de duplicados
 * a medida que crece la base de datos.
 *
 * @package     MiIntegracionApi\Helpers
 * @version     1.0.0
 * @since       2.0.0
 */
class OptimizeImageDuplicatesSearch
{
    /**
     * Logger para registrar operaciones.
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * Constructor.
     *
     * @param   Logger|null $logger Instancia del logger (opcional).
     */
    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger('optimize-duplicates');
    }

    /**
     * Crea índices compuestos en wp_postmeta para optimizar búsqueda de duplicados.
     *
     * Índices creados:
     * - (meta_key, meta_value) para búsquedas rápidas por hash
     * - (meta_key, meta_value(32)) para búsquedas optimizadas de hashes MD5
     *
     * @return  array Resultado de la operación con estadísticas.
     */
    public function createOptimizedIndexes(): array
    {
        global $wpdb;

        $results = [
            'success' => true,
            'indexes_created' => [],
            'indexes_existing' => [],
            'errors' => []
        ];

        try {
            $table_name = $wpdb->postmeta;
            
            // Verificar si la tabla existe
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
            if (!$table_exists) {
                $results['success'] = false;
                $results['errors'][] = "La tabla {$table_name} no existe";
                return $results;
            }

            // Obtener índices existentes
            $existing_indexes = $this->getExistingIndexes($table_name);
            
            // Índice compuesto para búsqueda rápida por meta_key y meta_value
            $index_name_1 = 'idx_verial_meta_key_value';
            if (!isset($existing_indexes[$index_name_1])) {
                $this->logger->info('Creando índice compuesto para optimizar búsqueda de duplicados', [
                    'index_name' => $index_name_1,
                    'columns' => ['meta_key', 'meta_value(191)']
                ]);
                
                // Usar meta_value(191) para evitar problemas con índices en campos LONGTEXT
                // 191 es el máximo para índices en MySQL/MariaDB
                $sql = "CREATE INDEX {$index_name_1} ON {$table_name} (meta_key, meta_value(191))";
                $result = $wpdb->query($sql);
                
                if ($result === false) {
                    $error = $wpdb->last_error;
                    $results['errors'][] = "Error creando índice {$index_name_1}: {$error}";
                    $this->logger->error('Error creando índice compuesto', [
                        'index_name' => $index_name_1,
                        'error' => $error
                    ]);
                } else {
                    $results['indexes_created'][] = $index_name_1;
                    $this->logger->info('Índice compuesto creado exitosamente', [
                        'index_name' => $index_name_1
                    ]);
                }
            } else {
                $results['indexes_existing'][] = $index_name_1;
                $this->logger->debug('Índice ya existe, omitiendo creación', [
                    'index_name' => $index_name_1
                ]);
            }

            // Índice específico para _verial_image_hash (hash MD5 de 32 caracteres)
            $index_name_2 = 'idx_verial_image_hash';
            if (!isset($existing_indexes[$index_name_2])) {
                $this->logger->info('Creando índice específico para _verial_image_hash', [
                    'index_name' => $index_name_2,
                    'columns' => ['meta_key', 'meta_value(32)']
                ]);
                
                // Índice específico para hashes MD5 (32 caracteres)
                $sql = "CREATE INDEX {$index_name_2} ON {$table_name} (meta_key, meta_value(32)) 
                        WHERE meta_key = '_verial_image_hash'";
                
                // MySQL no soporta índices parciales con WHERE en todas las versiones
                // Usar índice completo pero optimizado para 32 caracteres
                $sql = "CREATE INDEX {$index_name_2} ON {$table_name} (meta_key, meta_value(32))";
                $result = $wpdb->query($sql);
                
                if ($result === false) {
                    $error = $wpdb->last_error;
                    // Si falla, intentar con el índice general
                    $sql = "CREATE INDEX {$index_name_2} ON {$table_name} (meta_key, meta_value(191))";
                    $result = $wpdb->query($sql);
                    
                    if ($result === false) {
                        $error = $wpdb->last_error;
                        $results['errors'][] = "Error creando índice {$index_name_2}: {$error}";
                        $this->logger->error('Error creando índice específico para hash', [
                            'index_name' => $index_name_2,
                            'error' => $error
                        ]);
                    } else {
                        $results['indexes_created'][] = $index_name_2;
                        $this->logger->info('Índice específico creado exitosamente (con fallback)', [
                            'index_name' => $index_name_2
                        ]);
                    }
                } else {
                    $results['indexes_created'][] = $index_name_2;
                    $this->logger->info('Índice específico creado exitosamente', [
                        'index_name' => $index_name_2
                    ]);
                }
            } else {
                $results['indexes_existing'][] = $index_name_2;
                $this->logger->debug('Índice ya existe, omitiendo creación', [
                    'index_name' => $index_name_2
                ]);
            }

            // Actualizar estadísticas de la tabla para optimizar el plan de ejecución
            $this->analyzeTable($table_name);

            $results['success'] = count($results['errors']) === 0;
            
            $this->logger->info('Optimización de índices completada', [
                'indexes_created' => count($results['indexes_created']),
                'indexes_existing' => count($results['indexes_existing']),
                'errors' => count($results['errors'])
            ]);

        } catch (\Exception $e) {
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
            $this->logger->error('Excepción durante optimización de índices', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $results;
    }

    /**
     * Obtiene los índices existentes en una tabla.
     *
     * @param   string $table_name Nombre de la tabla.
     * @return  array Array asociativo con nombre de índice como clave.
     */
    private function getExistingIndexes(string $table_name): array
    {
        global $wpdb;

        $indexes = [];
        $results = $wpdb->get_results("SHOW INDEX FROM {$table_name}", ARRAY_A);

        foreach ($results as $row) {
            $index_name = $row['Key_name'];
            if ($index_name !== 'PRIMARY') {
                $indexes[$index_name] = true;
            }
        }

        return $indexes;
    }

    /**
     * Analiza la tabla para actualizar estadísticas del optimizador de consultas.
     *
     * @param   string $table_name Nombre de la tabla.
     * @return  void
     */
    private function analyzeTable(string $table_name): void
    {
        global $wpdb;

        try {
            $wpdb->query("ANALYZE TABLE {$table_name}");
            $this->logger->debug('Estadísticas de tabla actualizadas', [
                'table' => $table_name
            ]);
        } catch (\Exception $e) {
            // No crítico, solo logging
            $this->logger->debug('No se pudo analizar la tabla (no crítico)', [
                'table' => $table_name,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Verifica el rendimiento de la búsqueda de duplicados.
     *
     * Ejecuta una consulta de prueba y mide el tiempo de ejecución.
     *
     * @return  array Estadísticas de rendimiento.
     */
    public function benchmarkSearchPerformance(): array
    {
        global $wpdb;

        $results = [
            'test_queries' => [],
            'average_time_ms' => 0,
            'total_hashes' => 0
        ];

        try {
            // Contar total de hashes
            $total_hashes = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_verial_image_hash'"
            );
            $results['total_hashes'] = (int)$total_hashes;

            if ($total_hashes === 0) {
                $results['message'] = 'No hay hashes en la base de datos para probar';
                return $results;
            }

            // Obtener algunos hashes aleatorios para probar
            $test_hashes = $wpdb->get_col(
                "SELECT meta_value FROM {$wpdb->postmeta} 
                 WHERE meta_key = '_verial_image_hash' 
                 ORDER BY RAND() 
                 LIMIT 10"
            );

            $query_times = [];
            foreach ($test_hashes as $hash) {
                $start_time = microtime(true);
                
                $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} 
                     WHERE meta_key = %s AND meta_value = %s 
                     LIMIT 1",
                    '_verial_image_hash',
                    $hash
                ));
                
                $end_time = microtime(true);
                $query_time_ms = ($end_time - $start_time) * 1000;
                $query_times[] = $query_time_ms;
                
                $results['test_queries'][] = [
                    'hash' => substr($hash, 0, 8) . '...',
                    'time_ms' => round($query_time_ms, 2)
                ];
            }

            if (count($query_times) > 0) {
                $results['average_time_ms'] = round(array_sum($query_times) / count($query_times), 2);
                $results['min_time_ms'] = round(min($query_times), 2);
                $results['max_time_ms'] = round(max($query_times), 2);
            }

            $this->logger->info('Benchmark de búsqueda de duplicados completado', $results);

        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
            $this->logger->error('Error en benchmark de búsqueda', [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }
}

