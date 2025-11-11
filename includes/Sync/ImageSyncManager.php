<?php

declare(strict_types=1);

namespace MiIntegracionApi\Sync;

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Core\SyncMetrics;
use MiIntegracionApi\Helpers\SyncStatusHelper;
use Exception;

/**
 * Gestiona la sincronización masiva de imágenes en dos fases.
 *
 * Esta clase implementa la Fase 1 de la arquitectura en dos fases:
 * procesa todas las imágenes primero, antes de sincronizar productos.
 * Las imágenes se procesan en chunks para optimizar memoria y se guardan
 * en la media library de WordPress con metadatos para mapeo posterior.
 *
 * @package     MiIntegracionApi\Sync
 * @version     1.0.0
 * @since       1.5.0
 */
class ImageSyncManager
{
    /**
     * Instancia del conector de API.
     *
     * @var ApiConnector
     */
    private ApiConnector $apiConnector;

    /**
     * Instancia del logger.
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * Instancia del procesador de imágenes.
     *
     * @var ImageProcessorInterface
     */
    private ImageProcessorInterface $imageProcessor;

    /**
     * Instancia del throttler adaptativo.
     *
     * @var AdaptiveThrottler
     */
    private AdaptiveThrottler $throttler;

    /**
     * Configuración de sincronización de imágenes.
     *
     * @var ImageSyncConfig
     */
    private ImageSyncConfig $config;

    /**
     * Tamaño actual del batch (puede ajustarse dinámicamente).
     *
     * @var int
     */
    private int $currentBatchSize;

    /**
     * Constructor.
     *
     * @param   ApiConnector              $apiConnector   Instancia del conector de API.
     * @param   Logger                    $logger         Instancia del logger.
     * @param   ImageSyncConfig|null      $config         Configuración de sincronización (opcional, usa valores por defecto si es null).
     * @param   ImageProcessorInterface|null $imageProcessor Instancia del procesador de imágenes (opcional, crea una nueva si es null).
     */
    public function __construct(
        ApiConnector $apiConnector,
        Logger $logger,
        ?ImageSyncConfig $config = null,
        ?ImageProcessorInterface $imageProcessor = null
    ) {
        $this->apiConnector = $apiConnector;
        $this->logger = $logger;
        
        // ✅ REFACTORIZADO: Usar ImageSyncConfig para configuración centralizada
        // Permite inyección de dependencias para facilitar el testing y personalización
        $this->config = $config ?? new ImageSyncConfig();
        
        // ✅ REFACTORIZADO: Permitir inyección de ImageProcessorInterface
        // Permite usar implementaciones personalizadas o mocks para testing
        $this->imageProcessor = $imageProcessor ?? new ImageProcessor(
            $logger,
            $this->config->chunkSize
        );
        
        // ✅ REFACTORIZADO: Usar AdaptiveThrottler para encapsular lógica de throttling
        // Valor por defecto: 0.01 segundos (10ms) - optimizado para rendimiento
        // Valor mínimo recomendado: 0.05 segundos (50ms) para APIs con alta latencia
        // Valor máximo recomendado: 1.0 segundos para APIs muy lentas o con límites estrictos
        $configured_delay = (float)get_option('mia_images_sync_throttle_delay', $this->config->baseThrottleDelay);
        
        // ✅ VALIDACIÓN: El AdaptiveThrottler valida automáticamente el rango
        // Mínimo: 0 (sin throttling) - Máximo: maxThrottleDelay de la configuración
        $this->throttler = new AdaptiveThrottler($configured_delay, $logger);
        
        // ✅ LOGGING: Informar sobre la configuración de throttling
        if ($this->throttler->getBaseDelay() > 0) {
            $this->logger->debug('Throttling configurado para sincronización de imágenes', [
                'throttle_delay_seconds' => $this->throttler->getBaseDelay(),
                'throttle_delay_ms' => round($this->throttler->getBaseDelay() * 1000, 2),
                'max_throttle_delay_seconds' => $this->config->maxThrottleDelay,
                'note' => 'Puede ajustarse desde el panel de administración o mediante update_option("mia_images_sync_throttle_delay", valor)'
            ]);
        } else {
            $this->logger->debug('Throttling desactivado para sincronización de imágenes (delay = 0)');
        }
    }

    /**
     * Procesa todas las imágenes de todos los productos.
     *
     * Obtiene todos los IDs de productos, descarga sus imágenes,
     * las procesa en chunks y las guarda en la media library.
     * Soporta recuperación desde checkpoint para reanudar sincronizaciones interrumpidas.
     *
     * @param   bool $resume Si es true, reanuda desde el último checkpoint guardado.
     * @param   int  $batch_size Tamaño del lote para procesamiento (por defecto 50 para optimizar memoria).
     * @return  array Estadísticas del procesamiento.
     * @throws  Exception Si hay un error crítico en el proceso.
     */
    /**
     * Verifica el flag de detención inmediata leyendo directamente de la base de datos
     * para evitar problemas de caché
     *
     * @return bool True si el flag está activo
     */
    private function checkStopImmediatelyFlag(): bool
    {
        global $wpdb;
        if (!isset($wpdb) || !$wpdb) {
            // Fallback a get_option si wpdb no está disponible
            return (bool) get_option('mia_images_sync_stop_immediately', false);
        }
        
        // Leer directamente de la base de datos sin caché
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'mia_images_sync_stop_immediately'
        ));
        
        // El valor puede ser '1', 'true', 1, o true
        return !empty($value) && ($value === '1' || $value === 1 || $value === 'true' || $value === true);
    }
    
    public function syncAllImages(bool $resume = false, int $batch_size = 50): array
    {
        // ✅ CRÍTICO: Aumentar límites de PHP temporalmente para grandes volúmenes
        $original_memory_limit = ini_get('memory_limit');
        $original_max_execution_time = ini_get('max_execution_time');
        
        // Aumentar memoria a 512M si es posible (o mantener el actual si es mayor)
        $current_memory_bytes = $this->parseMemoryLimit($original_memory_limit);
        $target_memory_bytes = 512 * 1024 * 1024; // 512MB
        if ($current_memory_bytes < $target_memory_bytes) {
            ini_set('memory_limit', '512M');
            $this->logger->info('Límite de memoria aumentado temporalmente para sincronización masiva', [
                'original' => $original_memory_limit,
                'new' => '512M'
            ]);
        }
        
        // Aumentar tiempo de ejecución a 1 hora por batch
        set_time_limit(3600);
        
        // ✅ CRÍTICO: Desactivar generación de thumbnails al inicio
        $this->disableThumbnailGeneration();
        
        try {
            // ✅ MEJORADO: Verificar flag de detención inmediata ANTES de empezar (sin caché)
            if ($this->checkStopImmediatelyFlag()) {
                $this->logger->info('Sincronización de imágenes detenida antes de iniciar (flag de detención activo)');
                return [
                    'total_processed' => 0,
                    'total_attachments' => 0,
                    'duplicates_skipped' => 0,
                    'errors' => 0,
                    'last_processed_id' => 0,
                    'completed' => false,
                    'stopped' => true
                ];
            }
            
            // ✅ NUEVO: Verificar estado de pausa ANTES de empezar
            $phase1_status = SyncStatusHelper::getCurrentSyncInfo();
            $phase1_images = $phase1_status['phase1_images'] ?? [];
            if (!empty($phase1_images['paused']) && $phase1_images['paused'] === true) {
                $this->logger->info('Sincronización de imágenes pausada antes de iniciar');
                return [
                    'total_processed' => 0,
                    'total_attachments' => 0,
                    'duplicates_skipped' => 0,
                    'errors' => 0,
                    'last_processed_id' => 0,
                    'completed' => false,
                    'paused' => true
                ];
            }
            
            // Iniciar métricas de tiempo y memoria
            $start_time = microtime(true);
            $start_memory = memory_get_usage(true);
            $peak_memory_start = memory_get_peak_usage(true);

            $stats = [
                'total_processed' => 0,
                'total_attachments' => 0,
                'duplicates_skipped' => 0,
                'errors' => 0,
                'last_processed_id' => 0,
                'completed' => false,
                'metrics' => [
                    'start_time' => $start_time,
                    'start_memory_bytes' => $start_memory,
                    'peak_memory_start_bytes' => $peak_memory_start
                ]
            ];

            // 1. Cargar checkpoint si se solicita reanudar
            $resume_from_product_id = null;
            if ($resume) {
                $checkpoint = $this->loadCheckpoint();
                if ($checkpoint !== null) {
                    $resume_from_product_id = $checkpoint['last_processed_id'] ?? null;
                    $checkpoint_stats = $checkpoint['stats'] ?? [];
                    
                    // Restaurar estadísticas desde checkpoint
                    if (!empty($checkpoint_stats)) {
                        $stats['total_processed'] = $checkpoint_stats['total_processed'] ?? 0;
                        $stats['total_attachments'] = $checkpoint_stats['total_attachments'] ?? 0;
                        $stats['duplicates_skipped'] = $checkpoint_stats['duplicates_skipped'] ?? 0;
                        $stats['errors'] = $checkpoint_stats['errors'] ?? 0;
                    }
                    
                    $this->logger->info('Reanudando sincronización desde checkpoint', [
                        'checkpoint_timestamp' => $checkpoint['timestamp'] ?? 0,
                        'last_processed_id' => $resume_from_product_id,
                        'stats' => $stats
                    ]);
                } else {
                    $this->logger->warning('Se solicitó reanudar pero no se encontró checkpoint, iniciando desde el principio');
                }
            }

            // 2. Obtener todos los IDs de productos
            $product_ids = $this->getAllProductIds();
            $total_products = count($product_ids);

            // ✅ NUEVO: Actualizar estado de Fase 1 en SyncStatusHelper
            SyncStatusHelper::updatePhase1Images([
                'in_progress' => true,
                'total_products' => $total_products,
                'products_processed' => 0,
                'images_processed' => 0,
                'duplicates_skipped' => 0,
                'errors' => 0,
                'last_processed_id' => 0
            ]);

            $this->logger->info('Iniciando sincronización masiva de imágenes', [
                'total_products' => $total_products,
                'resume_from' => $resume_from_product_id,
                'resume_mode' => $resume
            ]);

            // 3. Procesar imágenes por producto
            $start_index = $resume_from_product_id
                ? array_search($resume_from_product_id, $product_ids, true)
                : 0;

            if ($start_index === false) {
                $this->logger->warning('ID de producto del checkpoint no encontrado en lista, iniciando desde el principio', [
                    'checkpoint_product_id' => $resume_from_product_id
                ]);
                $start_index = 0;
            } else {
                // Continuar desde el siguiente producto
                $start_index++;
            }

            // 4. Procesar imágenes por lotes (batch processing)
            // ✅ CRÍTICO: Ajustar batch_size automáticamente para grandes volúmenes
            // Para 4900 imágenes, usar batch_size más pequeño para evitar saturación
            $estimated_total_images = $total_products * 5; // Estimación: ~5 imágenes por producto
            if ($estimated_total_images > 2000) {
                // Para más de 2000 imágenes, usar batch_size muy pequeño
                $batch_size = max(1, min($batch_size, 5)); // Máximo 5 productos por batch
                $this->logger->info('Batch size reducido automáticamente para grandes volúmenes', [
                    'estimated_images' => $estimated_total_images,
                    'adjusted_batch_size' => $batch_size,
                    'reason' => 'Prevenir saturación de WordPress con grandes volúmenes de imágenes'
                ]);
            } else {
                // Validar y ajustar batch_size para optimizar memoria
                $batch_size = max(1, min($batch_size, 100)); // Entre 1 y 100 productos por batch
            }
            $this->currentBatchSize = $batch_size; // Inicializar batch size actual
            
            $this->logger->info('Configuración de procesamiento por lotes', [
                'batch_size' => $batch_size,
                'total_products' => $total_products,
                'estimated_batches' => ceil(($total_products - $start_index) / $batch_size),
                'adaptive_batch_enabled' => true
            ]);

            $batch_number = 0;
            $batch_start_time = microtime(true);
            $batch_start_memory = memory_get_usage(true);
            $batch_processed = 0;

            // ✅ OPTIMIZADO: Procesar en lotes para reducir uso de memoria
            for ($i = $start_index; $i < count($product_ids); $i += $this->currentBatchSize) {
                $batch_end = min($i + $this->currentBatchSize, count($product_ids));
                $current_batch = array_slice($product_ids, $i, $batch_end - $i);
                
                $this->logger->debug('Procesando lote de productos', [
                    'batch_number' => $batch_number,
                    'batch_start_index' => $i,
                    'batch_end_index' => $batch_end - 1,
                    'batch_size' => count($current_batch),
                    'total_processed' => $stats['total_processed']
                ]);

                // Procesar cada producto del lote actual
                foreach ($current_batch as $product_id) {
                // ✅ MEJORADO: Verificar múltiples señales de detención antes de procesar cada producto
                // 1. Verificar flag de detención inmediata (establecido por script de detención) - SIN CACHÉ
                if ($this->checkStopImmediatelyFlag()) {
                    $this->logger->info('Sincronización de imágenes detenida por flag de detención inmediata', [
                        'products_processed' => $stats['total_processed'],
                        'total_products' => $total_products,
                        'last_processed_id' => $stats['last_processed_id'] ?? 0
                    ]);
                    
                    // Limpiar flag
                    delete_option('mia_images_sync_stop_immediately');
                    delete_option('mia_images_sync_stop_timestamp');
                    
                    // Guardar checkpoint antes de detenerse
                    $this->saveCheckpoint($stats);
                    
                    // Actualizar estado para reflejar la detención
                    SyncStatusHelper::updatePhase1Images([
                        'in_progress' => false,
                        'paused' => true,
                        'products_processed' => $stats['total_processed'],
                        'images_processed' => $stats['total_attachments'],
                        'duplicates_skipped' => $stats['duplicates_skipped'],
                        'errors' => $stats['errors'],
                        'last_processed_id' => $stats['last_processed_id'] ?? 0
                    ]);
                    
                    // Salir de ambos bucles (for y foreach)
                    break 2;
                }
                
                // 2. Verificar si la sincronización está pausada en el estado
                $phase1_status = SyncStatusHelper::getCurrentSyncInfo();
                $phase1_images = $phase1_status['phase1_images'] ?? [];
                if (!empty($phase1_images['paused']) && $phase1_images['paused'] === true) {
                    $this->logger->info('Sincronización de imágenes pausada por el usuario', [
                        'products_processed' => $stats['total_processed'],
                        'total_products' => $total_products,
                        'last_processed_id' => $stats['last_processed_id'] ?? 0
                    ]);
                    
                    // Guardar checkpoint antes de detenerse para poder reanudar después
                    $this->saveCheckpoint($stats);
                    
                    // Actualizar estado para reflejar la pausa
                    SyncStatusHelper::updatePhase1Images([
                        'in_progress' => false,
                        'paused' => true,
                        'products_processed' => $stats['total_processed'],
                        'images_processed' => $stats['total_attachments'],
                        'duplicates_skipped' => $stats['duplicates_skipped'],
                        'errors' => $stats['errors'],
                        'last_processed_id' => $stats['last_processed_id'] ?? 0
                    ]);
                    
                    // Salir de ambos bucles (for y foreach)
                    break 2;
                }
                $result = $this->processProductImages($product_id);

                $stats['total_processed']++;
                $stats['total_attachments'] += $result['attachments'];
                $stats['duplicates_skipped'] += $result['duplicates'];
                $stats['errors'] += $result['errors'];
                $stats['last_processed_id'] = $product_id;
                $batch_processed++;
                
                // ✅ MEJORADO: Limpiar cache después de cada producto si tiene muchas imágenes
                // Esto ayuda a prevenir acumulación de memoria cuando hay productos con muchas imágenes
                if ($result['attachments'] > 5 || $result['duplicates'] > 5) {
                    // Producto con muchas imágenes - limpiar cache inmediatamente
                    $this->clearProductCache($product_id);
                }
                
                // ✅ NUEVO: Actualizar estado después de cada producto para feedback en tiempo real
                // Esto permite que la consola muestre el progreso imagen por imagen
                SyncStatusHelper::updatePhase1Images([
                    'products_processed' => $stats['total_processed'],
                    'images_processed' => $stats['total_attachments'],
                    'duplicates_skipped' => $stats['duplicates_skipped'],
                    'errors' => $stats['errors'],
                    'last_processed_id' => $product_id,
                    'last_product_images' => $result['attachments'],
                    'last_product_duplicates' => $result['duplicates'],
                    'last_product_errors' => $result['errors']
                ]);
                }
                
                // ✅ OPTIMIZADO: Ajustar batch size y throttling basándose en rendimiento
                $this->adjustBatchSizeBasedOnPerformance($stats, $start_time);
                
                // ✅ CRÍTICO: Delay entre batches para evitar saturación de WordPress
                // Aumentar delay si hay muchas imágenes procesadas
                $delay_between_batches = 5; // Delay base: 5 segundos
                if ($stats['total_processed'] > 100) {
                    $delay_between_batches = 10; // 10 segundos si ya llevamos muchas
                }
                if ($stats['total_processed'] > 500) {
                    $delay_between_batches = 15; // 15 segundos para volúmenes muy grandes
                }
                
                // Solo hacer delay si no es el último batch
                if ($i + $this->currentBatchSize < count($product_ids)) {
                    $this->logger->debug('Pausa entre batches para evitar saturación', [
                        'delay_seconds' => $delay_between_batches,
                        'products_processed' => $stats['total_processed'],
                        'total_products' => $total_products
                    ]);
                    sleep($delay_between_batches);
                    
                    // ✅ CRÍTICO: Limpiar memoria agresivamente después del delay
                    gc_collect_cycles();
                }
                
                // ✅ MEJORADO: Coordinación de limpiezas para evitar duplicación
                // clearMemoryPeriodically() ya incluye limpieza adaptativa completa
                // Solo ejecutar clearBatchCache() si no se ejecutó limpieza en clearMemoryPeriodically
                $memoryStats = $this->getMemoryStats();
                $memoryUsagePercent = $memoryStats['usage_percentage'] ?? 0;
                $cleanupInterval = $this->getAdaptiveCleanupInterval($memoryUsagePercent);
                $shouldRunBatchCache = ($stats['total_processed'] % $cleanupInterval !== 0);
                
                // ✅ OPTIMIZADO: Limpiar memoria después de cada lote (con frecuencia adaptativa)
                $this->clearMemoryPeriodically($stats['total_processed']);
                
                // ✅ MEJORADO: Limpieza adicional solo si no se ejecutó limpieza periódica
                // Esto evita duplicación de operaciones
                if ($shouldRunBatchCache) {
                    $this->clearBatchCache();
                }

                // Registrar métricas cada N productos
                if ($stats['total_processed'] % $this->config->metricsInterval === 0) {
                    $batch_duration = microtime(true) - $batch_start_time;
                    $batch_memory_used = memory_get_usage(true) - $batch_start_memory;
                    $this->recordMetrics($batch_number, $stats, $batch_duration, $batch_memory_used);
                    
                    // Reiniciar contadores del batch
                    $batch_number++;
                    $batch_start_time = microtime(true);
                    $batch_start_memory = memory_get_usage(true);
                    $batch_processed = 0;
                }

                // Guardar checkpoint cada N productos
                if ($this->shouldSaveCheckpoint($stats['total_processed'])) {
                    // Calcular porcentaje de progreso
                    $progress_percentage = $this->calculateProgressPercentage($stats['total_processed'], $total_products);
                    $stats['progress_percentage'] = $progress_percentage;
                    $stats['total_products'] = $total_products;
                    
                    $current_peak_memory = memory_get_peak_usage(true);
                    $this->saveCheckpoint($stats);
                    $this->logger->debug('Checkpoint guardado', [
                        'total_processed' => $stats['total_processed'],
                        'last_processed_id' => $stats['last_processed_id'],
                        'progress_percentage' => $progress_percentage,
                        'current_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                        'peak_memory_mb' => round($current_peak_memory / 1024 / 1024, 2)
                    ]);
                }

                // ✅ OPTIMIZADO: Logging de progreso del batch
                $this->logger->debug('Lote procesado', [
                    'batch_number' => $batch_number,
                    'batch_size' => count($current_batch),
                    'total_processed' => $stats['total_processed'],
                    'memory_after_batch_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
                ]);
            }

            // Registrar métricas finales del último batch incompleto
            if ($batch_processed > 0) {
                $batch_duration = microtime(true) - $batch_start_time;
                $batch_memory_used = memory_get_usage(true) - $batch_start_memory;
                $this->recordMetrics($batch_number, $stats, $batch_duration, $batch_memory_used);
                
                // ✅ MEJORADO: Actualizar estado al final del último batch incompleto
                SyncStatusHelper::updatePhase1Images([
                    'products_processed' => $stats['total_processed'],
                    'images_processed' => $stats['total_attachments'],
                    'duplicates_skipped' => $stats['duplicates_skipped'],
                    'errors' => $stats['errors'],
                    'last_processed_id' => $stats['last_processed_id']
                ]);
            }

            // 5. Calcular métricas finales
            $end_time = microtime(true);
            $end_memory = memory_get_usage(true);
            $peak_memory_end = memory_get_peak_usage(true);

            $total_duration = $end_time - $start_time;
            $total_memory_used = $end_memory - $start_memory;
            $peak_memory_increase = $peak_memory_end - $peak_memory_start;

            $stats['metrics']['end_time'] = $end_time;
            $stats['metrics']['total_duration_seconds'] = round($total_duration, 2);
            $stats['metrics']['end_memory_bytes'] = $end_memory;
            $stats['metrics']['total_memory_used_bytes'] = $total_memory_used;
            $stats['metrics']['peak_memory_end_bytes'] = $peak_memory_end;
            $stats['metrics']['peak_memory_increase_bytes'] = $peak_memory_increase;
            $stats['metrics']['peak_memory_mb'] = round($peak_memory_end / 1024 / 1024, 2);
            $stats['metrics']['total_memory_used_mb'] = round($total_memory_used / 1024 / 1024, 2);
            $stats['metrics']['products_per_second'] = $stats['total_processed'] > 0
                ? round($stats['total_processed'] / $total_duration, 2)
                : 0;

            // 6. Marcar como completado y limpiar checkpoint
            $stats['completed'] = true;
            $this->clearCheckpoint();
            
            // ✅ NUEVO: Marcar Fase 1 como completada en SyncStatusHelper
            SyncStatusHelper::updatePhase1Images([
                'in_progress' => false,
                'products_processed' => $stats['total_processed'],
                'images_processed' => $stats['total_attachments'],
                'duplicates_skipped' => $stats['duplicates_skipped'],
                'errors' => $stats['errors']
            ]);

            // Registrar métricas finales
            if (class_exists('\\MiIntegracionApi\\Core\\SyncMetrics')) {
                try {
                    $syncMetrics = SyncMetrics::getInstance();
                    $syncMetrics->recordBatchMetrics(
                        'final',
                        $stats['total_processed'],
                        $total_duration,
                        $stats['errors'],
                        0,
                        0
                    );
                } catch (Exception $e) {
                    $this->logger->warning('Error registrando métricas finales', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->logger->info('Sincronización masiva de imágenes completada', [
                'stats' => $stats,
                'duration_seconds' => $stats['metrics']['total_duration_seconds'],
                'peak_memory_mb' => $stats['metrics']['peak_memory_mb'],
                'products_per_second' => $stats['metrics']['products_per_second']
            ]);

            return $stats;
        } catch (Exception $e) {
            // ✅ NUEVO: Marcar Fase 1 como error en SyncStatusHelper
            SyncStatusHelper::updatePhase1Images([
                'in_progress' => false,
                'errors' => ($stats['errors'] ?? 0) + 1
            ]);
            
            $this->logger->error('Error en sincronización masiva de imágenes', [
                'error' => $e->getMessage(),
                'stats' => $stats
            ]);
            throw $e;
        } finally {
            // ✅ OPTIMIZADO: Reactivar generación de thumbnails al finalizar (éxito o error)
            $this->enableThumbnailGeneration();
            
            // ✅ CRÍTICO: Restaurar límites de PHP a valores originales
            if (isset($original_memory_limit)) {
                ini_set('memory_limit', $original_memory_limit);
                $this->logger->debug('Límite de memoria restaurado', [
                    'restored_to' => $original_memory_limit
                ]);
            }
            if (isset($original_max_execution_time)) {
                set_time_limit((int)$original_max_execution_time);
            }
        }
    }

    /**
     * Obtiene todos los IDs de productos desde la API.
     *
     * Implementa throttling para evitar sobrecarga de la API.
     *
     * @return  array Array de IDs de productos.
     */
    public function getAllProductIds(): array
    {
        $product_ids = [];
        $page_size = $this->config->pageSize;
        $inicio = 1;
        $page_count = 0;

        while (true) {
            // ✅ REFACTORIZADO: Usar AdaptiveThrottler para delay ajustado dinámicamente
            $delay_to_use = $this->throttler->getDelay();
            if ($page_count > 0 && $delay_to_use > 0) {
                usleep((int)($delay_to_use * 1000000)); // Convertir a microsegundos
            }

            $fin = $inicio + $page_size - 1;

            $params = [
                'x' => $this->apiConnector->get_session_number(),
                'id_articulo' => 0, // 0 para todos los artículos (igual que BatchProcessor)
                'inicio' => $inicio,
                'fin' => $fin
            ];

            $response = $this->apiConnector->get('GetArticulosWS', $params);

            if (!$response->isSuccess()) {
                $this->logger->warning('Error obteniendo productos', [
                    'inicio' => $inicio,
                    'fin' => $fin,
                    'error' => $response->getMessage()
                ]);
                break;
            }

            $data = $response->getData();
            
            // Logging detallado para diagnóstico
            $this->logger->debug('Respuesta GetArticulosWS', [
                'inicio' => $inicio,
                'fin' => $fin,
                'data_type' => gettype($data),
                'data_keys' => is_array($data) ? array_keys($data) : 'not_array',
                'has_articulos' => isset($data['Articulos']),
                'has_articulos_lowercase' => isset($data['articulos'])
            ]);
            
            // Intentar diferentes formatos de respuesta
            $articulos = null;
            if (isset($data['Articulos'])) {
                $articulos = $data['Articulos'];
            } elseif (isset($data['articulos'])) {
                $articulos = $data['articulos'];
            } elseif (isset($data['body'])) {
                // Si viene en body, decodificar JSON
                $json_data = json_decode($data['body'], true);
                if ($json_data && isset($json_data['Articulos'])) {
                    $articulos = $json_data['Articulos'];
                } elseif ($json_data && isset($json_data['articulos'])) {
                    $articulos = $json_data['articulos'];
                }
            }
            
            $articulos = is_array($articulos) ? $articulos : [];

            if (empty($articulos)) {
                $this->logger->info('No se encontraron artículos en esta página', [
                    'inicio' => $inicio,
                    'fin' => $fin,
                    'page_count' => $page_count
                ]);
                break;
            }

            foreach ($articulos as $articulo) {
                if (!empty($articulo['Id'])) {
                    $product_ids[] = (int)$articulo['Id'];
                }
            }

            // Si obtenemos menos productos de los esperados, es la última página
            if (count($articulos) < $page_size) {
                break;
            }

            $inicio = $fin + 1;
            $page_count++;
        }

        $this->logger->debug('IDs de productos obtenidos desde API', [
            'total_pages' => $page_count + 1,
            'total_product_ids' => count($product_ids),
            'unique_product_ids' => count(array_unique($product_ids))
        ]);

        return array_unique($product_ids);
    }

    /**
     * Procesa todas las imágenes de un producto específico.
     *
     * Implementa throttling para evitar sobrecarga de la API.
     *
     * @param   int $product_id ID del producto.
     * @return  array Estadísticas del procesamiento.
     */
    public function processProductImages(int $product_id): array
    {
        $stats = [
            'attachments' => 0,
            'duplicates' => 0,
            'errors' => 0
        ];

        try {
            // ✅ REFACTORIZADO: Usar AdaptiveThrottler para delay ajustado dinámicamente
            $delay_to_use = $this->throttler->getDelay();
            if ($delay_to_use > 0) {
                usleep((int)($delay_to_use * 1000000)); // Convertir a microsegundos
            }

            // Obtener imágenes del producto
            $params = [
                'x' => $this->apiConnector->get_session_number(),
                'id_articulo' => $product_id,
                'numpixelsladomenor' => 300
            ];

            $response = $this->apiConnector->get('GetImagenesArticulosWS', $params);

            if (!$response->isSuccess()) {
                // ✅ REFACTORIZADO: Usar AdaptiveThrottler para manejar errores
                $this->throttler->onError();
                
                $this->logger->warning('Error obteniendo imágenes del producto', [
                    'product_id' => $product_id,
                    'error' => $response->getMessage(),
                    'consecutive_errors' => $this->throttler->getConsecutiveErrors(),
                    'current_throttle_delay_ms' => round($this->throttler->getDelay() * 1000, 2),
                    'suggestion' => $this->throttler->shouldShowSuggestion() ? 'Considera aumentar el delay de throttling si los errores persisten' : null
                ]);
                $stats['errors']++;
                return $stats;
            }
            
            // ✅ REFACTORIZADO: Usar AdaptiveThrottler para manejar éxito
            $this->throttler->onSuccess();

            $data = $response->getData();
            $imagenes = $data['Imagenes'] ?? [];

            if (empty($imagenes)) {
                return $stats;
            }

            // Procesar cada imagen
            foreach ($imagenes as $index => $imagen_data) {
                // ✅ MEJORADO: Verificar flag de detención antes de procesar cada imagen (SIN CACHÉ)
                if ($this->checkStopImmediatelyFlag()) {
                    $this->logger->info('Sincronización de imágenes detenida durante procesamiento de imágenes', [
                        'product_id' => $product_id,
                        'image_index' => $index,
                        'total_images' => count($imagenes)
                    ]);
                    // Retornar stats actuales sin procesar más imágenes
                    return $stats;
                }
                
                // ✅ NUEVO: Verificar estado de pausa antes de procesar cada imagen
                $phase1_status = SyncStatusHelper::getCurrentSyncInfo();
                $phase1_images = $phase1_status['phase1_images'] ?? [];
                if (!empty($phase1_images['paused']) && $phase1_images['paused'] === true) {
                    $this->logger->info('Sincronización de imágenes pausada durante procesamiento de imágenes', [
                        'product_id' => $product_id,
                        'image_index' => $index,
                        'total_images' => count($imagenes)
                    ]);
                    // Retornar stats actuales sin procesar más imágenes
                    return $stats;
                }
                
                if (empty($imagen_data['Imagen'])) {
                    // ✅ MEJORADO: Limpiar variable vacía
                    unset($imagen_data);
                    continue;
                }

                $base64_image = 'data:image/jpeg;base64,' . $imagen_data['Imagen'];

                // ✅ REFACTORIZADO: Usar ImageProcessor para procesar la imagen
                $attachment_id = $this->imageProcessor->processImageFromBase64(
                    $base64_image,
                    $product_id,
                    $index
                );

                if ($attachment_id === false) {
                    $stats['errors']++;
                } elseif ($attachment_id === ImageProcessor::DUPLICATE) {
                    $stats['duplicates']++;
                } else {
                    $stats['attachments']++;
                }
                
                // ✅ MEJORADO: Limpiar variables grandes después de procesar cada imagen
                // Esto libera memoria inmediatamente y previene acumulación
                unset($base64_image, $imagen_data, $attachment_id);
                
                // ✅ MEJORADO: Forzar recolección de basura cada 5 imágenes para productos con muchas imágenes
                if (($index + 1) % 5 === 0) {
                    gc_collect_cycles();
                }
            }
            
            // ✅ MEJORADO: Limpiar array completo de imágenes después de procesarlas
            unset($imagenes, $data, $response);

            return $stats;
        } catch (Exception $e) {
            $this->logger->error('Error procesando imágenes del producto', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            $stats['errors']++;
            return $stats;
        }
    }

    /**
     * Desactiva temporalmente la generación de thumbnails durante sincronización masiva.
     *
     * Esto optimiza significativamente el rendimiento y reduce el uso de memoria
     * durante la sincronización masiva de imágenes. Los thumbnails se generarán
     * automáticamente cuando se necesiten después de la sincronización.
     *
     * @return  void
     */
    private function disableThumbnailGeneration(): void
    {
        // Agregar filtro para desactivar generación de thumbnails
        // Usa prioridad alta (999) para asegurar que se ejecute después de otros filtros
        add_filter('intermediate_image_sizes_advanced', '__return_empty_array', 999);
        
        $this->logger->debug('Generación de thumbnails desactivada durante sincronización masiva', [
            'note' => 'Los thumbnails se generarán automáticamente cuando se necesiten después de la sincronización'
        ]);
    }

    /**
     * Reactiva la generación de thumbnails después de sincronización masiva.
     *
     * Restaura el comportamiento normal de WordPress para generar thumbnails.
     * Los thumbnails se generarán automáticamente cuando se necesiten.
     *
     * @return  void
     */
    private function enableThumbnailGeneration(): void
    {
        // Remover filtro para reactivar generación de thumbnails
        remove_filter('intermediate_image_sizes_advanced', '__return_empty_array', 999);
        
        $this->logger->debug('Generación de thumbnails reactivada', [
            'note' => 'Los thumbnails se generarán automáticamente cuando se necesiten'
        ]);
    }

    /**
     * Ajusta el tamaño del batch y el throttling basándose en el rendimiento actual.
     *
     * Monitorea la velocidad de procesamiento y ajusta dinámicamente:
     * - Reduce batch_size si el rendimiento es bajo
     * - Aumenta throttling si hay problemas de rendimiento
     * - Optimiza para mantener un balance entre velocidad y estabilidad
     *
     * @param   array $stats      Estadísticas actuales del procesamiento.
     * @param   float $start_time Tiempo de inicio de la sincronización.
     * @return  void
     */
    private function adjustBatchSizeBasedOnPerformance(array $stats, float $start_time): void
    {
        // Solo ajustar si se han procesado suficientes productos para tener datos significativos
        if ($stats['total_processed'] < 10) {
            return;
        }

        $elapsed_time = microtime(true) - $start_time;
        if ($elapsed_time <= 0) {
            return;
        }

        // Calcular velocidad actual (productos por segundo)
        $current_speed = $stats['total_processed'] / $elapsed_time;
        
        // Umbrales de rendimiento
        $slow_threshold = 1.0; // Menos de 1 producto/segundo = lento
        $very_slow_threshold = 0.5; // Menos de 0.5 productos/segundo = muy lento
        $fast_threshold = 5.0; // Más de 5 productos/segundo = rápido

        $original_batch_size = $this->currentBatchSize;
        $original_throttle_delay = $this->throttler->getDelay();
        $adjustments_made = [];

        // Si el rendimiento es muy lento
        if ($current_speed < $very_slow_threshold) {
            // Reducir batch size significativamente
            $this->currentBatchSize = max(1, (int)($this->currentBatchSize * 0.5));
            // Aumentar throttling para reducir carga
            $new_delay = min($this->throttler->getBaseDelay() * 3.0, $this->config->maxThrottleDelay);
            if ($new_delay > $original_throttle_delay) {
                // Usar el método onError del throttler para aumentar el delay
                $this->throttler->onError();
                $this->throttler->onError(); // Llamar dos veces para aumentar más
            }
            $adjustments_made[] = 'batch_reduced_50_percent';
            $adjustments_made[] = 'throttling_increased';
        }
        // Si el rendimiento es lento
        elseif ($current_speed < $slow_threshold) {
            // Reducir batch size moderadamente
            $this->currentBatchSize = max(1, (int)($this->currentBatchSize * 0.75));
            // Aumentar throttling ligeramente
            $this->throttler->onError();
            $adjustments_made[] = 'batch_reduced_25_percent';
            $adjustments_made[] = 'throttling_increased';
        }
        // Si el rendimiento es bueno y hay margen
        elseif ($current_speed > $fast_threshold && $this->currentBatchSize < 100) {
            // Aumentar batch size gradualmente (máximo 10% por ajuste)
            $this->currentBatchSize = min(100, (int)($this->currentBatchSize * 1.1));
            $adjustments_made[] = 'batch_increased_10_percent';
        }

        // Logging si se hicieron ajustes
        if (!empty($adjustments_made)) {
            // Determinar categoría de rendimiento
            $performance_category = 'normal';
            if ($current_speed < $very_slow_threshold) {
                $performance_category = 'very_slow';
            } elseif ($current_speed < $slow_threshold) {
                $performance_category = 'slow';
            }
            
            $this->logger->info('Ajuste adaptativo de batch processing', [
                'current_speed_products_per_second' => round($current_speed, 2),
                'original_batch_size' => $original_batch_size,
                'new_batch_size' => $this->currentBatchSize,
                'original_throttle_delay_ms' => round($original_throttle_delay * 1000, 2),
                'new_throttle_delay_ms' => round($this->throttler->getDelay() * 1000, 2),
                'total_processed' => $stats['total_processed'],
                'elapsed_seconds' => round($elapsed_time, 2),
                'adjustments' => $adjustments_made,
                'performance_category' => $performance_category
            ]);
        }
    }

    /**
     * Limpia memoria periódicamente para evitar acumulación durante procesamiento largo.
     *
     * Fuerza la recolección de basura de PHP y limpia el cache de WordPress
     * cada cierto número de productos procesados para optimizar el uso de memoria.
     *
     * @param   int $processedCount Número de productos procesados.
     * @return  void
     */
    private function clearMemoryPeriodically(int $processedCount): void
    {
        // ✅ MEJORADO: Frecuencia adaptativa basada en uso de memoria
        $memoryStats = $this->getMemoryStats();
        $memoryUsagePercent = $memoryStats['usage_percentage'] ?? 0;
        
        // Determinar frecuencia y nivel de limpieza según uso de memoria
        $cleanupInterval = $this->getAdaptiveCleanupInterval($memoryUsagePercent);
        $cleanupLevel = $this->getAdaptiveCleanupLevel($memoryUsagePercent);
        
        // Ejecutar limpieza según intervalo adaptativo
        if ($processedCount > 0 && $processedCount % $cleanupInterval === 0) {
            $memory_before = memory_get_usage(true);
            
            // Ejecutar limpieza según nivel
            $cleanupResult = $this->executeAdaptiveCleanup($cleanupLevel, $processedCount);
            
            $memory_after = memory_get_usage(true);
            $memory_freed = max(0, $memory_before - $memory_after);
            
            // ✅ NUEVO: Capturar métricas de limpieza para mostrar en consola
            $cleanupMetrics = [
                'timestamp' => time(),
                'processed_count' => $processedCount,
                'cleanup_level' => $cleanupLevel,
                'cleanup_interval' => $cleanupInterval,
                'memory_usage_percent' => round($memoryUsagePercent, 1),
                'memory_before_mb' => round($memory_before / 1024 / 1024, 2),
                'memory_after_mb' => round($memory_after / 1024 / 1024, 2),
                'memory_freed_mb' => round($memory_freed / 1024 / 1024, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'gc_cycles_collected' => $cleanupResult['gc_cycles'] ?? 0,
                'cache_flushed' => $cleanupResult['cache_flushed'] ?? false,
                'cold_cache_cleaned' => $cleanupResult['cold_cache_cleaned'] ?? 0,
                'hot_cold_migrated' => $cleanupResult['hot_cold_migrated'] ?? 0
            ];
            
            // Actualizar estado con métricas de limpieza
            SyncStatusHelper::updatePhase1Images([
                'last_cleanup_metrics' => $cleanupMetrics
            ]);
            
            $this->logger->debug('Memoria limpiada periódicamente (adaptativa)', $cleanupMetrics);
        }
    }

    /**
     * ✅ NUEVO: Obtiene estadísticas de memoria.
     * 
     * @return array Estadísticas de memoria
     */
    private function getMemoryStats(): array
    {
        if (class_exists('\\MiIntegracionApi\\Core\\MemoryManager')) {
            return \MiIntegracionApi\Core\MemoryManager::getMemoryStats();
        }
        
        // Fallback básico
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = ini_get('memory_limit');
        $limitBytes = $this->parseMemoryLimit($limit);
        
        return [
            'current' => $current,
            'peak' => $peak,
            'limit' => $limitBytes,
            'usage_percentage' => $limitBytes > 0 ? ($current / $limitBytes) * 100 : 0
        ];
    }

    /**
     * ✅ NUEVO: Convierte límite de memoria a bytes.
     * 
     * @param string $limit Límite en formato PHP (ej: "256M")
     * @return int Límite en bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;
        
        // ✅ CORRECCIÓN: Agregar breaks para evitar cascada en switch
        switch ($last) {
            case 'g':
                $value *= 1024;
                // fallthrough intencional: GB -> MB -> KB
            case 'm':
                $value *= 1024;
                // fallthrough intencional: MB -> KB
            case 'k':
                $value *= 1024;
                break;
            default:
                // Si no tiene sufijo, ya está en bytes
                break;
        }
        
        return $value;
    }

    /**
     * ✅ NUEVO: Obtiene intervalo de limpieza adaptativo según uso de memoria.
     * 
     * @param float $memoryUsagePercent Porcentaje de uso de memoria
     * @return int Intervalo en número de productos
     */
    private function getAdaptiveCleanupInterval(float $memoryUsagePercent): int
    {
        if ($memoryUsagePercent >= 90) {
            return 1; // Cada producto (crítico)
        } elseif ($memoryUsagePercent >= 80) {
            return 5; // Cada 5 productos (alto)
        } elseif ($memoryUsagePercent >= 60) {
            return 10; // Cada 10 productos (moderado)
        } else {
            return 20; // Cada 20 productos (bajo)
        }
    }

    /**
     * ✅ NUEVO: Obtiene nivel de limpieza adaptativo según uso de memoria.
     * 
     * @param float $memoryUsagePercent Porcentaje de uso de memoria
     * @return string Nivel de limpieza ('light', 'moderate', 'aggressive', 'critical')
     */
    private function getAdaptiveCleanupLevel(float $memoryUsagePercent): string
    {
        if ($memoryUsagePercent >= 90) {
            return 'critical';
        } elseif ($memoryUsagePercent >= 80) {
            return 'aggressive';
        } elseif ($memoryUsagePercent >= 60) {
            return 'moderate';
        } else {
            return 'light';
        }
    }

    /**
     * ✅ NUEVO: Ejecuta limpieza adaptativa según nivel.
     * 
     * @param string $level Nivel de limpieza
     * @param int $processedCount Número de productos procesados
     * @return array Resultado de la limpieza con métricas
     */
    private function executeAdaptiveCleanup(string $level, int $processedCount): array
    {
        $result = [
            'gc_cycles' => 0,
            'cache_flushed' => false,
            'cold_cache_cleaned' => 0,
            'hot_cold_migrated' => 0
        ];
        
        // Nivel ligero: Solo garbage collection
        if ($level === 'light') {
            $result['gc_cycles'] = gc_collect_cycles();
            return $result;
        }
        
        // Nivel moderado: GC + wp_cache_flush
        if ($level === 'moderate') {
            $result['gc_cycles'] = gc_collect_cycles();
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
                $result['cache_flushed'] = true;
            }
            return $result;
        }
        
        // Nivel agresivo: GC + cache flush + migración hot→cold
        if ($level === 'aggressive') {
            $result['gc_cycles'] = gc_collect_cycles();
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
                $result['cache_flushed'] = true;
            }
            
            // ✅ NUEVO: Migración hot→cold cada 50 productos en modo agresivo
            if ($processedCount % 50 === 0 && class_exists('\\MiIntegracionApi\\CacheManager')) {
                try {
                    $cacheManager = \MiIntegracionApi\CacheManager::get_instance();
                    $autoMigrationEnabled = get_option('mia_enable_hot_cold_migration', true);
                    if ($autoMigrationEnabled) {
                        $migrationResult = $cacheManager->performHotToColdMigration();
                        if ($migrationResult['migrated_count'] > 0) {
                            $result['hot_cold_migrated'] = $migrationResult['migrated_count'];
                            $this->logger->debug('Migración hot→cold durante limpieza agresiva', [
                                'migrated_count' => $migrationResult['migrated_count']
                            ]);
                        }
                    }
                } catch (Exception $e) {
                    $this->logger->debug('Error en migración hot→cold durante limpieza agresiva', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            return $result;
        }
        
        // Nivel crítico: Todo + evicción LRU + limpieza cold cache
        if ($level === 'critical') {
            $result['gc_cycles'] = gc_collect_cycles();
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
                $result['cache_flushed'] = true;
            }
            
            // Migración hot→cold inmediata
            if (class_exists('\\MiIntegracionApi\\CacheManager')) {
                try {
                    $cacheManager = \MiIntegracionApi\CacheManager::get_instance();
                    $autoMigrationEnabled = get_option('mia_enable_hot_cold_migration', true);
                    if ($autoMigrationEnabled) {
                        $migrationResult = $cacheManager->performHotToColdMigration();
                        if ($migrationResult['migrated_count'] > 0) {
                            $result['hot_cold_migrated'] = $migrationResult['migrated_count'];
                            $this->logger->warning('Migración hot→cold durante limpieza crítica', [
                                'migrated_count' => $migrationResult['migrated_count']
                            ]);
                        }
                    }
                    
                    // Limpiar cold cache expirado
                    $coldCleaned = $cacheManager->cleanExpiredColdCache();
                    if ($coldCleaned > 0) {
                        $result['cold_cache_cleaned'] = $coldCleaned;
                    }
                    
                    // ✅ NUEVO: Verificar y evictar si es necesario
                    $currentSize = $cacheManager->getTotalCacheSize();
                    $maxSize = $cacheManager->getGlobalCacheSizeLimit();
                    if ($currentSize > ($maxSize * 0.8)) {
                        // ✅ CORRECCIÓN: Forzar evicción LRU si estamos cerca del límite
                        // Usar reflexión para acceder al método privado evictLRU
                        try {
                            $reflection = new \ReflectionClass($cacheManager);
                            $evictMethod = $reflection->getMethod('evictLRU');
                            $evictMethod->setAccessible(true);
                            
                            // Calcular espacio a liberar (hasta llegar al 70% del límite en modo crítico)
                            $targetSize = $maxSize * 0.7;
                            $sizeToFree = $currentSize - $targetSize;
                            
                            if ($sizeToFree > 0) {
                                $evictResult = $evictMethod->invoke($cacheManager, $sizeToFree);
                                $this->logger->warning('Memoria crítica: evicción LRU forzada', [
                                    'current_size_mb' => $currentSize,
                                    'max_size_mb' => $maxSize,
                                    'size_freed_mb' => $evictResult['space_freed_mb'] ?? 0,
                                    'evicted_count' => $evictResult['evicted_count'] ?? 0
                                ]);
                            }
                        } catch (\ReflectionException $e) {
                            // Si falla la reflexión, al menos loguear el warning
                            $this->logger->warning('Memoria crítica: no se pudo forzar evicción LRU', [
                                'current_size_mb' => $currentSize,
                                'max_size_mb' => $maxSize,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                } catch (Exception $e) {
                    $this->logger->error('Error en limpieza crítica', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Limpiar transients antiguos
            if (class_exists('\\MiIntegracionApi\\Helpers\\Utils')) {
                try {
                    \MiIntegracionApi\Helpers\Utils::cleanup_old_sync_transients(12); // Más agresivo: 12 horas
                } catch (Exception $e) {
                    $this->logger->debug('Error limpiando transients antiguos', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        return $result;
    }

    /**
     * Limpia el cache de un producto específico y sus attachments.
     *
     * @param   int $product_id ID del producto.
     * @return  void
     */
    private function clearProductCache(int $product_id): void
    {
        // Limpiar cache del producto
        if (function_exists('clean_post_cache')) {
            clean_post_cache($product_id);
        }
        
        // Limpiar cache de metadatos del producto
        if (function_exists('clean_postmeta_cache')) {
            clean_postmeta_cache($product_id);
        }
        
        // Limpiar cache de términos asociados al producto
        $terms = wp_get_object_terms($product_id, ['product_cat', 'product_tag'], ['fields' => 'ids']);
        if (!empty($terms) && function_exists('clean_term_cache')) {
            clean_term_cache($terms);
        }
    }

    /**
     * Limpia el cache después de procesar un batch completo.
     *
     * Realiza una limpieza más agresiva del cache después de cada batch
     * para prevenir acumulación de memoria durante procesamiento largo.
     *
     * @return  void
     */
    private function clearBatchCache(): void
    {
        $memory_before = memory_get_usage(true);
        
        // Forzar recolección de basura de PHP
        $gc_cycles = gc_collect_cycles();
        
        // Limpiar cache general de WordPress
        $cache_flushed = false;
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $cache_flushed = true;
        }
        
        $cold_cache_cleaned = 0;
        // ✅ MEJORADO: Limpiar cold cache expirado después de cada batch
        if (class_exists('\\MiIntegracionApi\\CacheManager')) {
            try {
                $cacheManager = \MiIntegracionApi\CacheManager::get_instance();
                $coldCleaned = $cacheManager->cleanExpiredColdCache();
                if ($coldCleaned > 0) {
                    $cold_cache_cleaned = $coldCleaned;
                    $this->logger->debug('Cold cache expirado limpiado después de batch', [
                        'cleaned_count' => $coldCleaned
                    ]);
                }
            } catch (Exception $e) {
                $this->logger->debug('Error limpiando cold cache después de batch', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $memory_after = memory_get_usage(true);
        $memory_freed = max(0, $memory_before - $memory_after);
        
        // ✅ NUEVO: Capturar métricas de limpieza de batch para mostrar en consola
        $cleanupMetrics = [
            'timestamp' => time(),
            'type' => 'batch_cleanup',
            'memory_before_mb' => round($memory_before / 1024 / 1024, 2),
            'memory_after_mb' => round($memory_after / 1024 / 1024, 2),
            'memory_freed_mb' => round($memory_freed / 1024 / 1024, 2),
            'gc_cycles_collected' => $gc_cycles,
            'cache_flushed' => $cache_flushed,
            'cold_cache_cleaned' => $cold_cache_cleaned
        ];
        
        // Actualizar estado con métricas de limpieza
        SyncStatusHelper::updatePhase1Images([
            'last_cleanup_metrics' => $cleanupMetrics
        ]);
        
        // Limpiar cache de transients antiguos (opcional, solo si hay muchos)
        // Esto se puede hacer periódicamente, no en cada batch
    }

    /**
     * Determina si se debe guardar un checkpoint basado en el número de productos procesados.
     *
     * @param   int $processedCount Número de productos procesados.
     * @return  bool true si se debe guardar el checkpoint, false si no.
     */
    private function shouldSaveCheckpoint(int $processedCount): bool
    {
        return $processedCount > 0 && $processedCount % $this->config->checkpointInterval === 0;
    }

    /**
     * Calcula el porcentaje de progreso de la sincronización.
     *
     * @param   int $processed Número de productos procesados.
     * @param   int $total     Número total de productos.
     * @return  float Porcentaje de progreso (0-100), redondeado a 2 decimales.
     */
    private function calculateProgressPercentage(int $processed, int $total): float
    {
        return $total > 0 ? round(($processed / $total) * 100, 2) : 0;
    }

    /**
     * Guarda un checkpoint del progreso.
     *
     * Guarda el estado actual de la sincronización para permitir
     * reanudar desde este punto en caso de interrupción.
     * El checkpoint se guarda en la opción de WordPress 'mia_images_sync_checkpoint'.
     *
     * @param   array $stats Estadísticas actuales con las claves:
     *                       - last_processed_id: ID del último producto procesado
     *                       - total_processed: Total de productos procesados
     *                       - total_attachments: Total de attachments creados
     *                       - duplicates_skipped: Total de duplicados omitidos
     *                       - errors: Total de errores encontrados
     *                       - progress_percentage: Porcentaje de progreso (calculado automáticamente)
     *                       - total_products: Total de productos a procesar
     * @return  void
     */
    private function saveCheckpoint(array $stats): void
    {
        $checkpoint = [
            'last_processed_id' => $stats['last_processed_id'] ?? 0,
            'stats' => $stats,
            'timestamp' => time(),
            'total_products' => $stats['total_products'] ?? null,
            'progress_percentage' => $stats['progress_percentage'] ?? null
        ];
        
        update_option('mia_images_sync_checkpoint', $checkpoint);
        
        $this->logger->debug('Checkpoint guardado', [
            'last_processed_id' => $checkpoint['last_processed_id'],
            'timestamp' => $checkpoint['timestamp']
        ]);
    }

    /**
     * Carga un checkpoint guardado.
     *
     * Retorna el checkpoint si existe y es válido, o null si no existe.
     * Valida la estructura del checkpoint y su antigüedad (máximo 7 días).
     * Si el checkpoint es inválido o muy antiguo, lo limpia automáticamente.
     *
     * @return  array|null Checkpoint guardado con las claves:
     *                     - last_processed_id: ID del último producto procesado
     *                     - stats: Estadísticas del procesamiento
     *                     - timestamp: Timestamp de cuando se guardó
     *                     O null si no existe o es inválido.
     */
    private function loadCheckpoint(): ?array
    {
        $checkpoint = get_option('mia_images_sync_checkpoint', null);
        
        if ($checkpoint === null || !is_array($checkpoint)) {
            return null;
        }
        
        // Validar que el checkpoint tiene la estructura correcta
        if (empty($checkpoint['last_processed_id']) && $checkpoint['last_processed_id'] !== 0) {
            $this->logger->warning('Checkpoint encontrado pero con estructura inválida', [
                'checkpoint' => $checkpoint
            ]);
            return null;
        }
        
        // Verificar que el checkpoint no sea muy antiguo (más de 7 días)
        $checkpoint_age = time() - ($checkpoint['timestamp'] ?? 0);
        $max_age = 7 * 24 * 60 * 60; // 7 días
        
        if ($checkpoint_age > $max_age) {
            $this->logger->warning('Checkpoint encontrado pero es muy antiguo, se ignorará', [
                'checkpoint_age_days' => round($checkpoint_age / 86400, 2),
                'max_age_days' => $max_age / 86400
            ]);
            $this->clearCheckpoint();
            return null;
        }
        
        return $checkpoint;
    }


    /**
     * Limpia el checkpoint guardado.
     *
     * Se llama cuando la sincronización se completa exitosamente
     * para eliminar el checkpoint y permitir una nueva sincronización
     * desde el principio.
     *
     * @return  void
     */
    private function clearCheckpoint(): void
    {
        delete_option('mia_images_sync_checkpoint');
        $this->logger->debug('Checkpoint limpiado (sincronización completada)');
    }

    /**
     * Registra métricas de procesamiento.
     *
     * Registra métricas de tiempo, memoria y errores para monitoreo
     * y análisis de rendimiento.
     *
     * @param   int   $batch_number     Número del lote.
     * @param   array $stats            Estadísticas actuales.
     * @param   float $batch_duration   Duración del batch en segundos.
     * @param   int   $batch_memory_used Memoria usada en el batch en bytes.
     * @return  void
     */
    private function recordMetrics(int $batch_number, array $stats, float $batch_duration, int $batch_memory_used): void
    {
        if (!class_exists('\\MiIntegracionApi\\Core\\SyncMetrics')) {
            return;
        }

        try {
            $syncMetrics = SyncMetrics::getInstance();
            $syncMetrics->recordBatchMetrics(
                $batch_number,
                $stats['total_processed'],
                $batch_duration,
                $stats['errors'],
                0,
                0
            );

            // Registrar métricas de memoria si están disponibles
            $current_memory = memory_get_usage(true);
            $peak_memory = memory_get_peak_usage(true);

            $this->logger->debug('Métricas registradas', [
                'batch_number' => $batch_number,
                'duration_seconds' => round($batch_duration, 3),
                'memory_used_mb' => round($batch_memory_used / 1024 / 1024, 2),
                'current_memory_mb' => round($current_memory / 1024 / 1024, 2),
                'peak_memory_mb' => round($peak_memory / 1024 / 1024, 2)
            ]);
        } catch (Exception $e) {
            $this->logger->warning('Error registrando métricas', [
                'error' => $e->getMessage(),
                'batch_number' => $batch_number
            ]);
        }
    }
}

