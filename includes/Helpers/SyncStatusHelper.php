<?php
/**
 * Helper para la Gestión Centralizada del Estado de Sincronización
 * 
 * Este archivo centraliza toda la lógica de modificación del estado de sincronización,
 * eliminando las 71 modificaciones directas dispersas en Sync_Manager.php.
 *
 * @package    MiIntegracionApi
 * @subpackage Helpers
 * @since      1.0.0
 * @version    1.0.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\Helpers;

// use MiIntegracionApi\Helpers\BatchSizeHelper; // Comentado para evitar dependencia circular
use MiIntegracionApi\Logging\Core\LoggerBasic;

/**
 * Helper para la gestión centralizada del estado de sincronización
 *
 * Centraliza todas las modificaciones del estado de sincronización para:
 * - Eliminar modificaciones directas dispersas
 * - Garantizar consistencia del estado
 * - Facilitar mantenimiento y debugging
 * - Implementar validación centralizada
 *
 * @package MiIntegracionApi\Helpers
 * @since   1.0.0
 */
class SyncStatusHelper
{
    /**
     * Clave de opción para almacenar el estado de sincronización
     * 
     * @var string
     * @since 1.0.0
     */
    private const SYNC_STATUS_OPTION = 'mi_integracion_api_sync_status';

    /**
     * Instancia del logger
     * 
     * @var Logger
     * @since 1.0.0
     */
    private static $logger = null;

    /**
     * Obtiene la instancia del logger
     * 
     * @return Logger
     * @since 1.0.0
     */
    private static function getLogger(): Logger
    {
        if (self::$logger === null) {
            self::$logger = new LoggerBasic('sync-status-helper');
        }
        return self::$logger;
    }

    /**
     * Obtiene el estado actual de sincronización
     * 
     * @return array Estado de sincronización
     * @since 1.0.0
     */
    public static function getSyncStatus(): array
    {
        // Verificar que las funciones de WordPress estén disponibles
        if (!function_exists('get_option')) {
            self::getLogger()->error('WordPress no está cargado - get_option no disponible');
            return [];
        }
        
        $status = get_option(self::SYNC_STATUS_OPTION, []);
        
        // Estructura por defecto si no existe
        if (empty($status)) {
            $status = self::getDefaultSyncStatus();
            self::saveSyncStatus($status);
        }
        
        return $status;
    }

    /**
     * Guarda el estado de sincronización
     * 
     * @param array $status Estado a guardar
     * @return bool True si se guardó correctamente
     * @since 1.0.0
     */
    public static function saveSyncStatus(array $status): bool
    {
        // Verificar que las funciones de WordPress estén disponibles
        if (!function_exists('update_option')) {
            self::getLogger()->error('WordPress no está cargado - update_option no disponible');
            return false;
        }
        
        // ✅ REMOVIDO: Debug innecesario que se ejecuta constantemente en cada actualización de estado
        
        // Intentar guardar con reintentos
        $max_retries = 3;
        $retry_count = 0;
        $result = false;
        
        while ($retry_count < $max_retries && !$result) {
            $result = update_option(self::SYNC_STATUS_OPTION, $status, true);
            
            if (!$result) {
                $retry_count++;
                self::getLogger()->warning('Error al guardar estado de sincronización, reintentando', [
                    'attempt' => $retry_count,
                    'max_retries' => $max_retries,
                    'option_name' => self::SYNC_STATUS_OPTION
                ]);
                
                // Pequeña pausa antes del reintento
                if ($retry_count < $max_retries) {
                    usleep(100000); // 100ms
                }
            }
        }
        
        // Limpiar caché de WordPress para asegurar que la actualización se vea inmediatamente
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Log del resultado final
        if (!$result) {
            self::getLogger()->error('Error al guardar estado de sincronización después de todos los reintentos', [
                'option_name' => self::SYNC_STATUS_OPTION,
                'retry_count' => $retry_count,
                'status_size' => strlen(serialize($status))
            ]);
        }
        // ✅ REMOVIDO: Debug innecesario que se ejecuta constantemente en cada actualización exitosa
        
        return $result;
    }

    /**
     * Obtiene la estructura por defecto del estado
     * 
     * @return array Estructura por defecto
     * @since 1.0.0
     */
    private static function getDefaultSyncStatus(): array
    {
        return [
            'last_sync' => [
                'products' => [
                    'wc_to_verial' => 0,
                    'verial_to_wc' => 0,
                ],
                'orders' => [
                    'wc_to_verial' => 0,
                    'verial_to_wc' => 0,
                ],
            ],
            'current_sync' => [
                'in_progress' => false,
                'entity' => '',
                'direction' => '',
                'batch_size' => class_exists('\MiIntegracionApi\Helpers\BatchSizeHelper') 
                    ? \MiIntegracionApi\Helpers\BatchSizeHelper::getBatchSize('productos') 
                    : 50, // Valor por defecto si BatchSizeHelper no está disponible
                'current_batch' => 0,
                'total_batches' => 0,
                'items_synced' => 0,
                'total_items' => 0,
                'errors' => 0,
                'start_time' => 0,
                'last_update' => 0,
                'operation_id' => '',
            ],
            // ✅ NUEVO: Estado para Fase 1 (sincronización de imágenes)
            'phase1_images' => [
                'in_progress' => false,
                'products_processed' => 0,
                'total_products' => 0,
                'images_processed' => 0,
                'duplicates_skipped' => 0,
                'errors' => 0,
                'start_time' => 0,
                'last_update' => 0,
                'last_processed_id' => 0,
            ],
        ];
    }

    // ===================================================================
    // MÉTODOS DE ESTADO PRINCIPAL
    // ===================================================================

    /**
     * Establece si la sincronización está en progreso
     * 
     * @param bool $inProgress Si está en progreso
     * @param string|null $entity Entidad (opcional)
     * @param string|null $direction Dirección (opcional)
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function setSyncInProgress(bool $inProgress, ?string $entity = null, ?string $direction = null): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['in_progress'] = $inProgress;
        
        if ($inProgress && $entity && $direction) {
            $status['current_sync']['entity'] = $entity;
            $status['current_sync']['direction'] = $direction;
        }
        
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    /**
     * Establece el lote actual
     * 
     * @param int $batch Número de lote
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function setCurrentBatch(int $batch): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['current_batch'] = $batch;
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    /**
     * Establece el total de lotes
     * 
     * @param int $totalBatches Total de lotes
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function setTotalBatches(int $totalBatches): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['total_batches'] = $totalBatches;
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    /**
     * Establece el tamaño del lote
     * 
     * @param int $batchSize Tamaño del lote
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function setBatchSize(int $batchSize): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['batch_size'] = $batchSize;
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    /**
     * Establece el total de elementos
     * 
     * @param int $totalItems Total de elementos
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function setTotalItems(int $totalItems): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['total_items'] = $totalItems;
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    /**
     * Establece el estado de progreso de la sincronización
     * 
     * @param bool $inProgress True si está en progreso, false si no
     * @param array $additionalData Datos adicionales para actualizar
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function setInProgress(bool $inProgress, array $additionalData = []): bool
    {
        // ✅ REMOVIDO: Debug innecesario
        $status = self::getSyncStatus();
        $status['current_sync']['in_progress'] = $inProgress;
        $status['current_sync']['last_update'] = time();
        
        // Actualizar datos adicionales si se proporcionan
        if (!empty($additionalData)) {
            foreach ($additionalData as $key => $value) {
                $status['current_sync'][$key] = $value;
            }
        }
        
        // Si se está iniciando, establecer timestamp de inicio
        if ($inProgress && !isset($status['current_sync']['start_time'])) {
            $status['current_sync']['start_time'] = time();
        }
        
        // Si se está completando, establecer timestamp de finalización
        if (!$inProgress && isset($status['current_sync']['start_time'])) {
            $status['current_sync']['end_time'] = time();
            $status['current_sync']['duration'] = time() - $status['current_sync']['start_time'];
        }
        
        return self::saveSyncStatus($status);
    }

    /**
     * Actualiza el estado actual de sincronización con datos específicos
     * 
     * @param array $data Datos a actualizar en el estado actual
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function updateCurrentSync(array $data): bool
    {
        $status = self::getSyncStatus();
        
        // ✅ CORRECCIÓN: Preservar valores críticos que no deben perderse
        $critical_fields = ['total_batches', 'total_items', 'operation_id', 'in_progress'];
        $preserved_values = [];
        
        foreach ($critical_fields as $field) {
            if (isset($status['current_sync'][$field]) && !isset($data[$field])) {
                $preserved_values[$field] = $status['current_sync'][$field];
            }
        }
        
        // Actualizar datos en current_sync
        foreach ($data as $key => $value) {
            $status['current_sync'][$key] = $value;
        }
        
        // Restaurar valores críticos si no se proporcionaron en los datos
        foreach ($preserved_values as $field => $value) {
            if (!isset($data[$field])) {
                $status['current_sync'][$field] = $value;
            }
        }
        
        // Actualizar timestamp de última modificación
        $status['current_sync']['last_update'] = time();
        
        // ✅ REMOVIDO: Debug innecesario que se ejecuta frecuentemente
        
        return self::saveSyncStatus($status);
    }

    /**
     * Actualiza elementos sincronizados (incrementa)
     * 
     * @param int $items Elementos a incrementar
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function incrementItemsSynced(int $items): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['items_synced'] += $items;
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    /**
     * Establece elementos sincronizados (valor absoluto)
     * 
     * @param int $items Elementos sincronizados
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function setItemsSynced(int $items): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['items_synced'] = $items;
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    /**
     * Actualiza errores (incrementa)
     * 
     * @param int $errors Errores a incrementar
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function incrementErrors(int $errors): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['errors'] += $errors;
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    /**
     * Establece errores (valor absoluto)
     * 
     * @param int $errors Número de errores
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function setErrors(int $errors): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['errors'] = $errors;
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    /**
     * Establece el ID de operación
     * 
     * @param string $operationId ID de operación
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function setOperationId(string $operationId): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['operation_id'] = $operationId;
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    /**
     * Establece el tiempo de inicio
     * 
     * @param int $timestamp Timestamp de inicio
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function setStartTime(int $timestamp): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['start_time'] = $timestamp;
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    /**
     * Actualiza el timestamp de última actualización
     * 
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function updateLastUpdate(): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    // ===================================================================
    // MÉTODOS DE ESTADO COMPUESTO
    // ===================================================================

    /**
     * Establece la sincronización como completada
     * 
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function setSyncCompleted(): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['in_progress'] = false;
        $status['current_sync']['completed_at'] = current_time('mysql');
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    /**
     * Establece la sincronización como cancelada
     * 
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function setSyncCancelled(): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['in_progress'] = false;
        $status['current_sync']['cancelled_at'] = current_time('mysql');
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    /**
     * Limpia el estado de sincronización actual
     * 
     * @return bool True si se limpió correctamente
     * @since 1.0.0
     */
    public static function clearCurrentSync(): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync'] = [
            'in_progress' => false,
            'entity' => '',
            'direction' => '',
            'batch_size' => BatchSizeHelper::getBatchSize('productos'),
            'current_batch' => 0,
            'total_batches' => 0,
            'items_synced' => 0,
            'total_items' => 0,
            'errors' => 0,
            'start_time' => 0,
            'last_update' => 0,
            'operation_id' => ''
        ];
        
        return self::saveSyncStatus($status);
    }

    /**
     * Actualiza el último sync para una entidad/dirección
     * 
     * @param string $entity Entidad
     * @param string $direction Dirección
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function updateLastSync(string $entity, string $direction): bool
    {
        $status = self::getSyncStatus();
        $status['last_sync'][$entity][$direction] = time();
        
        return self::saveSyncStatus($status);
    }

    // ===================================================================
    // MÉTODOS COMPUESTOS (MÚLTIPLES CAMBIOS ATOMICOS)
    // ===================================================================

    /**
     * Inicializa una nueva sincronización
     * 
     * @param string $entity Entidad
     * @param string $direction Dirección
     * @param int $batchSize Tamaño del lote
     * @param string $operationId ID de operación
     * @return bool True si se inicializó correctamente
     * @since 1.0.0
     */
    public static function initializeSync(string $entity, string $direction, int $batchSize, string $operationId): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync'] = [
            'in_progress' => true,
            'entity' => $entity,
            'direction' => $direction,
            'batch_size' => $batchSize,
            'current_batch' => 0,
            'total_batches' => 0,
            'items_synced' => 0,
            'total_items' => 0,
            'errors' => 0,
            'start_time' => time(),
            'last_update' => time(),
            'operation_id' => $operationId
        ];
        
        return self::saveSyncStatus($status);
    }

    /**
     * Actualiza progreso de lote
     * 
     * @param int $currentBatch Lote actual
     * @param int $totalProcessed Total procesado
     * @param int $errors Errores (opcional)
     * @return bool True si se actualizó correctamente
     * @since 1.0.0
     */
    public static function updateBatchProgress(int $currentBatch, int $totalProcessed, int $errors = 0): bool
    {
        $status = self::getSyncStatus();
        $status['current_sync']['current_batch'] = $currentBatch;
        $status['current_sync']['items_synced'] = $totalProcessed;
        $status['current_sync']['errors'] += $errors;
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    /**
     * Finaliza sincronización exitosamente
     * 
     * @param string $entity Entidad
     * @param string $direction Dirección
     * @return bool True si se finalizó correctamente
     * @since 1.0.0
     */
    public static function finalizeSyncSuccess(string $entity, string $direction): bool
    {
        $status = self::getSyncStatus();
        $status['last_sync'][$entity][$direction] = time();
        $status['current_sync']['in_progress'] = false;
        $status['current_sync']['completed_at'] = current_time('mysql');
        $status['current_sync']['last_update'] = time();
        
        return self::saveSyncStatus($status);
    }

    // ===================================================================
    // MÉTODOS DE VALIDACIÓN
    // ===================================================================

    /**
     * Valida la consistencia del estado de sincronización
     * 
     * @return array Errores encontrados
     * @since 1.0.0
     */
    public static function validateSyncState(): array
    {
        $errors = [];
        $status = self::getSyncStatus();
        $current_sync = $status['current_sync'] ?? [];
        
        if ($current_sync['in_progress']) {
            if (empty($current_sync['operation_id'])) {
                $errors[] = 'Operation ID missing for active sync';
            }
            
            if ($current_sync['current_batch'] > $current_sync['total_batches']) {
                $errors[] = 'Current batch exceeds total batches';
            }
            
            if ($current_sync['items_synced'] > $current_sync['total_items']) {
                $errors[] = 'Items synced exceeds total items';
            }
            
            if ($current_sync['batch_size'] <= 0) {
                $errors[] = 'Invalid batch size';
            }
        }
        
        return $errors;
    }

    /**
     * Verifica si hay una sincronización en progreso
     * 
     * @return bool True si hay sincronización activa
     * @since 1.0.0
     */
    public static function isSyncInProgress(): bool
    {
        $status = self::getSyncStatus();
        return $status['current_sync']['in_progress'] ?? false;
    }

    /**
     * Obtiene información del estado actual
     * 
     * @return array Información del estado
     * @since 1.0.0
     */
    public static function getCurrentSyncInfo(): array
    {
        // FORZAR LECTURA DIRECTA DE LA BASE DE DATOS (sin caché)
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::SYNC_STATUS_OPTION, 'options');
        }
        
        $status = self::getSyncStatus();
        $current_sync = $status['current_sync'] ?? [];
        
        // ✅ NUEVO: Incluir información de Fase 1 (imágenes) en el nivel superior
        $phase1_images = $status['phase1_images'] ?? [
            'in_progress' => false,
            'products_processed' => 0,
            'total_products' => 0,
            'images_processed' => 0,
            'duplicates_skipped' => 0,
            'errors' => 0,
            'start_time' => 0,
            'last_update' => 0,
            'last_processed_id' => 0,
            'completed' => false,
        ];
        
        // ✅ CORRECCIÓN: Devolver estructura con phase1_images en el nivel superior
        $result = array_merge($current_sync, [
            'phase1_images' => $phase1_images
        ]);
        
        // ✅ REMOVIDO: Debug innecesario que se ejecuta constantemente (cada 2 segundos durante polling)
        
        return $result;
    }
    
    /**
     * Actualiza el estado de Fase 1 (sincronización de imágenes)
     * 
     * @param array $data Datos a actualizar
     * @return bool True si se actualizó correctamente
     * @since 1.5.0
     */
    public static function updatePhase1Images(array $data): bool
    {
        $status = self::getSyncStatus();
        
        if (!isset($status['phase1_images'])) {
            $status['phase1_images'] = [
                'in_progress' => false,
                'products_processed' => 0,
                'total_products' => 0,
                'images_processed' => 0,
                'duplicates_skipped' => 0,
                'errors' => 0,
                'start_time' => 0,
                'last_update' => 0,
                'last_processed_id' => 0,
            ];
        }
        
        // Actualizar datos
        foreach ($data as $key => $value) {
            $status['phase1_images'][$key] = $value;
        }
        
        // Actualizar timestamp
        $status['phase1_images']['last_update'] = time();
        
        // Si se está iniciando, establecer timestamp de inicio
        if (isset($data['in_progress']) && $data['in_progress'] && !isset($status['phase1_images']['start_time'])) {
            $status['phase1_images']['start_time'] = time();
        }
        
        return self::saveSyncStatus($status);
    }

    // ===================================================================
    // MÉTODOS DE HISTORIAL
    // ===================================================================

    /**
     * Agrega un registro al historial de sincronización
     * 
     * @param array $syncData Datos de la sincronización
     * @return bool True si se agregó correctamente
     * @since 1.0.0
     */
    public static function addToHistory(array $syncData): bool
    {
        try {
            $metrics = \MiIntegracionApi\Core\SyncMetrics::getInstance();
            $metrics->addSyncHistory($syncData);
            return true;
        } catch (\Exception $e) {
            self::getLogger()->error('Error al agregar entrada al historial', [
                'error' => $e->getMessage(),
                'sync_data' => $syncData
            ]);
            return false;
        }
    }

    /**
     * Obtiene el historial de sincronización
     * 
     * @param int $limit Límite de registros
     * @return array Historial de sincronización
     * @since 1.0.0
     */
    public static function getSyncHistory(int $limit = 50): array
    {
        try {
            $metrics = \MiIntegracionApi\Core\SyncMetrics::getInstance();
            return $metrics->getSyncHistory($limit);
        } catch (\Exception $e) {
            self::getLogger()->error('Error al obtener historial de sincronización', [
                'error' => $e->getMessage(),
                'limit' => $limit
            ]);
            return [];
        }
    }

    /**
     * Obtiene datos de heartbeat para el frontend
     * 
     * @return array Datos de heartbeat
     * @since 1.0.0
     */
    public static function getHeartbeatData(): array
    {
        $sync_info = self::getCurrentSyncInfo();
        $is_active = $sync_info['in_progress'] ?? false;
        
        return [
            'active' => $is_active,
            'timestamp' => time(),
            'sync_info' => $is_active ? $sync_info : false
        ];
    }

    // ===================================================================
    // MÉTODOS DE LIMPIEZA
    // ===================================================================

    /**
     * Limpia el estado de sincronización
     * 
     * @return bool True si se limpió correctamente
     * @since 1.0.0
     */
    public static function clearSyncStatus(): bool
    {
        $result = delete_option(self::SYNC_STATUS_OPTION);
        
        if ($result) {
            self::getLogger()->info('Estado de sincronización limpiado');
        } else {
            self::getLogger()->error('Error al limpiar estado de sincronización');
        }
        
        return $result;
    }

    /**
     * Limpia el historial de sincronización
     *
     * @return bool True si se limpió correctamente
     * @since 1.0.0
     */
    public static function clearSyncHistory(): bool
    {
        try {
            $metrics = \MiIntegracionApi\Core\SyncMetrics::getInstance();
            $deletedCount = $metrics->cleanSyncHistory(0); // Limpiar todo
            
            self::getLogger()->info('Historial de sincronización limpiado', [
                'entries_deleted' => $deletedCount
            ]);
            
            return true;
        } catch (\Exception $e) {
            self::getLogger()->error('Error al limpiar historial de sincronización', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Valida la consistencia del estado de sincronización
     *
     * @return array Resultado de la validación con inconsistencias encontradas
     * @since 1.0.0
     */
    public static function validateStateConsistency(): array
    {
        $inconsistencies = [];
        $sync_info = self::getCurrentSyncInfo();
        
        // 1. Validar estructura básica
        $required_fields = ['in_progress', 'entity', 'direction', 'batch_size', 'current_batch', 'total_batches'];
        foreach ($required_fields as $field) {
            if (!array_key_exists($field, $sync_info)) {
                $inconsistencies[] = [
                    'type' => 'missing_field',
                    'field' => $field,
                    'severity' => 'critical'
                ];
            }
        }
        
        // 2. Validar tipos de datos
        if (isset($sync_info['in_progress']) && !is_bool($sync_info['in_progress'])) {
            $inconsistencies[] = [
                'type' => 'invalid_type',
                'field' => 'in_progress',
                'expected' => 'boolean',
                'actual' => gettype($sync_info['in_progress']),
                'severity' => 'critical'
            ];
        }
        
        if (isset($sync_info['batch_size']) && (!is_numeric($sync_info['batch_size']) || $sync_info['batch_size'] <= 0)) {
            $inconsistencies[] = [
                'type' => 'invalid_value',
                'field' => 'batch_size',
                'value' => $sync_info['batch_size'],
                'severity' => 'high'
            ];
        }
        
        if (isset($sync_info['current_batch']) && (!is_numeric($sync_info['current_batch']) || $sync_info['current_batch'] < 0)) {
            $inconsistencies[] = [
                'type' => 'invalid_value',
                'field' => 'current_batch',
                'value' => $sync_info['current_batch'],
                'severity' => 'high'
            ];
        }
        
        if (isset($sync_info['total_batches']) && (!is_numeric($sync_info['total_batches']) || $sync_info['total_batches'] < 0)) {
            $inconsistencies[] = [
                'type' => 'invalid_value',
                'field' => 'total_batches',
                'value' => $sync_info['total_batches'],
                'severity' => 'high'
            ];
        }
        
        // 3. Validar lógica de negocio
        if (isset($sync_info['current_batch']) && isset($sync_info['total_batches'])) {
            if ($sync_info['current_batch'] > $sync_info['total_batches']) {
                $inconsistencies[] = [
                    'type' => 'logic_error',
                    'field' => 'current_batch_vs_total',
                    'current_batch' => $sync_info['current_batch'],
                    'total_batches' => $sync_info['total_batches'],
                    'severity' => 'high'
                ];
            }
        }
        
        if (isset($sync_info['items_synced']) && isset($sync_info['total_items'])) {
            if ($sync_info['items_synced'] > $sync_info['total_items']) {
                $inconsistencies[] = [
                    'type' => 'logic_error',
                    'field' => 'items_synced_vs_total',
                    'items_synced' => $sync_info['items_synced'],
                    'total_items' => $sync_info['total_items'],
                    'severity' => 'high'
                ];
            }
        }
        
        // 4. Validar timestamps
        $current_time = time();
        if (isset($sync_info['start_time']) && $sync_info['start_time'] > $current_time) {
            $inconsistencies[] = [
                'type' => 'invalid_timestamp',
                'field' => 'start_time',
                'value' => $sync_info['start_time'],
                'current_time' => $current_time,
                'severity' => 'medium'
            ];
        }
        
        if (isset($sync_info['last_update']) && $sync_info['last_update'] > $current_time) {
            $inconsistencies[] = [
                'type' => 'invalid_timestamp',
                'field' => 'last_update',
                'value' => $sync_info['last_update'],
                'current_time' => $current_time,
                'severity' => 'medium'
            ];
        }
        
        // 5. Validar estado huérfano
        if (isset($sync_info['in_progress']) && $sync_info['in_progress']) {
            $last_update = $sync_info['last_update'] ?? 0;
            $time_since_update = $current_time - $last_update;
            $max_stale_time = 3600; // 1 hora
            
            if ($time_since_update > $max_stale_time) {
                $inconsistencies[] = [
                    'type' => 'stale_state',
                    'field' => 'in_progress',
                    'time_since_update' => $time_since_update,
                    'max_stale_time' => $max_stale_time,
                    'severity' => 'critical'
                ];
            }
        }
        
        return [
            'is_consistent' => empty($inconsistencies),
            'inconsistencies' => $inconsistencies,
            'total_inconsistencies' => count($inconsistencies),
            'critical_count' => count(array_filter($inconsistencies, fn($i) => $i['severity'] === 'critical')),
            'high_count' => count(array_filter($inconsistencies, fn($i) => $i['severity'] === 'high')),
            'medium_count' => count(array_filter($inconsistencies, fn($i) => $i['severity'] === 'medium'))
        ];
    }

    /**
     * Corrige inconsistencias automáticamente cuando es posible
     *
     * @return array Resultado de la corrección
     * @since 1.0.0
     */
    public static function autoFixInconsistencies(): array
    {
        $validation = self::validateStateConsistency();
        $fixes_applied = [];
        $fixes_failed = [];
        
        if ($validation['is_consistent']) {
            return [
                'success' => true,
                'message' => 'No se encontraron inconsistencias',
                'fixes_applied' => [],
                'fixes_failed' => []
            ];
        }
        
        foreach ($validation['inconsistencies'] as $inconsistency) {
            $fix_result = self::applyFix($inconsistency);
            
            if ($fix_result['success']) {
                $fixes_applied[] = $fix_result;
            } else {
                $fixes_failed[] = $fix_result;
            }
        }
        
        return [
            'success' => empty($fixes_failed),
            'message' => sprintf(
                'Corrección completada: %d aplicadas, %d fallidas',
                count($fixes_applied),
                count($fixes_failed)
            ),
            'fixes_applied' => $fixes_applied,
            'fixes_failed' => $fixes_failed
        ];
    }

    /**
     * Aplica una corrección específica para una inconsistencia
     *
     * @param array $inconsistency Inconsistencia a corregir
     * @return array Resultado de la corrección
     * @since 1.0.0
     */
    private static function applyFix(array $inconsistency): array
    {
        switch ($inconsistency['type']) {
            case 'missing_field':
                return self::fixMissingField($inconsistency);
                
            case 'invalid_type':
                return self::fixInvalidType($inconsistency);
                
            case 'invalid_value':
                return self::fixInvalidValue($inconsistency);
                
            case 'logic_error':
                return self::fixLogicError($inconsistency);
                
            case 'invalid_timestamp':
                return self::fixInvalidTimestamp($inconsistency);
                
            case 'stale_state':
                return self::fixStaleState($inconsistency);
                
            default:
                return [
                    'success' => false,
                    'message' => 'Tipo de inconsistencia no soportado para corrección automática',
                    'inconsistency' => $inconsistency
                ];
        }
    }

    /**
     * Corrige campos faltantes
     */
    private static function fixMissingField(array $inconsistency): array
    {
        $field = $inconsistency['field'];
        $default_values = [
            'in_progress' => false,
            'entity' => '',
            'direction' => '',
            'batch_size' => 50,
            'current_batch' => 0,
            'total_batches' => 0,
            'items_synced' => 0,
            'total_items' => 0,
            'errors' => 0,
            'start_time' => time(),
            'last_update' => time(),
            'operation_id' => ''
        ];
        
        if (isset($default_values[$field])) {
            self::updateCurrentSync([$field => $default_values[$field]]);
            return [
                'success' => true,
                'message' => "Campo faltante '{$field}' corregido con valor por defecto",
                'field' => $field,
                'value' => $default_values[$field]
            ];
        }
        
        return [
            'success' => false,
            'message' => "No se puede corregir campo faltante '{$field}' - sin valor por defecto",
            'field' => $field
        ];
    }

    /**
     * Corrige tipos de datos inválidos
     */
    private static function fixInvalidType(array $inconsistency): array
    {
        $field = $inconsistency['field'];
        $expected_type = $inconsistency['expected'];
        
        if ($expected_type === 'boolean') {
            self::updateCurrentSync([$field => false]);
            return [
                'success' => true,
                'message' => "Tipo inválido en '{$field}' corregido a boolean",
                'field' => $field,
                'corrected_value' => false
            ];
        }
        
        return [
            'success' => false,
            'message' => "No se puede corregir tipo inválido en '{$field}' - tipo '{$expected_type}' no soportado",
            'field' => $field
        ];
    }

    /**
     * Corrige valores inválidos
     */
    private static function fixInvalidValue(array $inconsistency): array
    {
        $field = $inconsistency['field'];
        $value = $inconsistency['value'];
        
        $corrections = [
            'batch_size' => 50,
            'current_batch' => 0,
            'total_batches' => 0,
            'items_synced' => 0,
            'total_items' => 0,
            'errors' => 0
        ];
        
        if (isset($corrections[$field])) {
            self::updateCurrentSync([$field => $corrections[$field]]);
            return [
                'success' => true,
                'message' => "Valor inválido en '{$field}' corregido",
                'field' => $field,
                'old_value' => $value,
                'new_value' => $corrections[$field]
            ];
        }
        
        return [
            'success' => false,
            'message' => "No se puede corregir valor inválido en '{$field}'",
            'field' => $field
        ];
    }

    /**
     * Corrige errores de lógica
     */
    private static function fixLogicError(array $inconsistency): array
    {
        $field = $inconsistency['field'];
        
        // DEBUG: Log detallado de la corrección
        self::getLogger()->debug('Aplicando corrección de error de lógica', [
            'field' => $field,
            'inconsistency' => $inconsistency
        ]);
        
        if ($field === 'current_batch_vs_total') {
            $sync_info = self::getCurrentSyncInfo();
            $corrected_batch = min($sync_info['current_batch'], $sync_info['total_batches']);
            
            self::getLogger()->debug('Corrigiendo current_batch', [
                'old_value' => $sync_info['current_batch'],
                'total_batches' => $sync_info['total_batches'],
                'corrected_value' => $corrected_batch
            ]);
            
            self::updateCurrentSync(['current_batch' => $corrected_batch]);
            
            return [
                'success' => true,
                'message' => 'Error de lógica current_batch vs total_batches corregido',
                'field' => 'current_batch',
                'old_value' => $sync_info['current_batch'],
                'new_value' => $corrected_batch
            ];
        }
        
        if ($field === 'items_synced_vs_total') {
            $sync_info = self::getCurrentSyncInfo();
            $corrected_items = min($sync_info['items_synced'], $sync_info['total_items']);
            
            self::getLogger()->debug('Corrigiendo items_synced', [
                'old_value' => $sync_info['items_synced'],
                'total_items' => $sync_info['total_items'],
                'corrected_value' => $corrected_items
            ]);
            
            self::updateCurrentSync(['items_synced' => $corrected_items]);
            
            return [
                'success' => true,
                'message' => 'Error de lógica items_synced vs total_items corregido',
                'field' => 'items_synced',
                'old_value' => $sync_info['items_synced'],
                'new_value' => $corrected_items
            ];
        }
        
        return [
            'success' => false,
            'message' => "No se puede corregir error de lógica en '{$field}'",
            'field' => $field
        ];
    }

    /**
     * Corrige timestamps inválidos
     */
    private static function fixInvalidTimestamp(array $inconsistency): array
    {
        $field = $inconsistency['field'];
        $current_time = time();
        
        self::updateCurrentSync([$field => $current_time]);
        
        return [
            'success' => true,
            'message' => "Timestamp inválido en '{$field}' corregido",
            'field' => $field,
            'corrected_value' => $current_time
        ];
    }

    /**
     * Corrige estado huérfano
     */
    private static function fixStaleState(array $inconsistency): array
    {
        self::clearCurrentSync();
        
        return [
            'success' => true,
            'message' => 'Estado huérfano limpiado',
            'action' => 'clear_current_sync'
        ];
    }

    /**
     * Establece la señal de cancelación de sincronización
     *
     * @return bool True si se estableció correctamente
     * @since 1.0.0
     */
    public static function setCancellationSignal(): bool
    {
        $result = \update_option('mia_sync_cancelada', true);
        \set_transient('mia_sync_cancelada', true, 300); // 5 minutos
        
        if ($result) {
            self::getLogger()->info('Señal de cancelación establecida');
        } else {
            self::getLogger()->error('Error al establecer señal de cancelación');
        }
        
        return $result;
    }

    /**
     * Verifica si se ha solicitado cancelación
     *
     * @return bool True si se ha solicitado cancelación
     * @since 1.0.0
     */
    public static function isCancellationRequested(): bool
    {
        return \get_option('mia_sync_cancelada', false) || \get_transient('mia_sync_cancelada');
    }

    /**
     * Limpia la señal de cancelación
     *
     * @return bool True si se limpió correctamente
     * @since 1.0.0
     */
    public static function clearCancellationSignal(): bool
    {
        $result1 = \delete_option('mia_sync_cancelada');
        $result2 = \delete_transient('mia_sync_cancelada');
        
        $success = $result1 && $result2;
        
        if ($success) {
            self::getLogger()->info('Señal de cancelación limpiada');
        } else {
            self::getLogger()->error('Error al limpiar señal de cancelación');
        }
        
        return $success;
    }

    /**
     * Cancela la sincronización actual de forma segura
     *
     * @return array Resultado de la cancelación
     * @since 1.0.0
     */
    public static function cancelCurrentSync(): array
    {
        $sync_info = self::getCurrentSyncInfo();
        
        if (!$sync_info['in_progress']) {
            return [
                'success' => false,
                'message' => 'No hay sincronización en progreso',
                'status' => 'no_sync'
            ];
        }

        // Establecer señal de cancelación
        self::setCancellationSignal();

        // Crear entrada de historial
        $history_entry = [
            'entity' => $sync_info['entity'],
            'direction' => $sync_info['direction'],
            'operation_id' => $sync_info['run_id'] ?? 'unknown',
            'items_synced' => $sync_info['items_synced'],
            'total_items' => $sync_info['total_items'],
            'errors' => $sync_info['errors'],
            'start_time' => $sync_info['start_time'],
            'end_time' => time(),
            'duration' => time() - $sync_info['start_time'],
            'status' => 'cancelled',
            'run_id' => $sync_info['run_id'] ?? 'unknown'
        ];

        // Limpiar estado actual
        self::clearCurrentSync();

        // Agregar al historial
        self::addToHistory($history_entry);

        return [
            'success' => true,
            'message' => 'Sincronización cancelada correctamente',
            'status' => 'cancelled',
            'history_entry' => $history_entry
        ];
    }

    /**
     * Mantiene el heartbeat de un lock de forma segura
     *
     * @param string $lock_entity Entidad del lock
     * @param int $extend_threshold Umbral para extender el lock (segundos)
     * @return array Resultado del mantenimiento
     * @since 1.0.0
     */
    public static function maintainHeartbeat(string $lock_entity, int $extend_threshold = 300): array
    {
        try {
            // Verificar si el lock existe y está activo
            $lock_info = \MiIntegracionApi\Core\SyncLock::getLockInfo($lock_entity);
            
            // CORRECCIÓN: Verificar que lock_info es array y tiene la clave 'active' antes de acceder
            if (!is_array($lock_info) || !isset($lock_info['active']) || !$lock_info['active']) {
                return [
                    'success' => false,
                    'message' => 'Lock no está activo',
                    'lock_entity' => $lock_entity
                ];
            }

            // Actualizar heartbeat
            $heartbeat_updated = \MiIntegracionApi\Core\SyncLock::updateHeartbeat($lock_entity);
            
            if (!$heartbeat_updated) {
                return [
                    'success' => false,
                    'message' => 'Error al actualizar heartbeat',
                    'lock_entity' => $lock_entity
                ];
            }

            // Verificar si necesita extensión
            $time_remaining = $lock_info['expires_at'] - time();
            
            if ($time_remaining < $extend_threshold) {
                $extend_result = \MiIntegracionApi\Core\SyncLock::extendLock($lock_entity, 3600); // Extender 1 hora
                
                if (!$extend_result) {
                    self::getLogger()->warning('No se pudo extender lock', [
                        'lock_entity' => $lock_entity,
                        'time_remaining' => $time_remaining
                    ]);
                } else {
                    self::getLogger()->info('Lock extendido exitosamente', [
                        'lock_entity' => $lock_entity,
                        'time_remaining' => $time_remaining,
                        'extended_by' => 3600
                    ]);
                }
            }

            return [
                'success' => true,
                'message' => 'Heartbeat mantenido correctamente',
                'lock_entity' => $lock_entity,
                'time_remaining' => $time_remaining,
                'extended' => $time_remaining < $extend_threshold
            ];

        } catch (\Exception $e) {
            self::getLogger()->error('Error al mantener heartbeat', [
                'lock_entity' => $lock_entity,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Error al mantener heartbeat: ' . $e->getMessage(),
                'lock_entity' => $lock_entity
            ];
        }
    }

    /**
     * Obtiene información de diagnóstico de locks
     *
     * @return array Diagnóstico de locks
     * @since 1.0.0
     */
    public static function getLockDiagnostics(): array
    {
        $active_locks = \MiIntegracionApi\Core\SyncLock::getActiveLocks();
        $global_lock = \MiIntegracionApi\Core\SyncLock::getLockInfo('sync_global');
        
        return [
            'active_locks_count' => count($active_locks),
            'has_active_locks' => !empty($active_locks),
            'global_lock' => $global_lock,
            'locks' => $active_locks,
            'timestamp' => time()
        ];
    }

    /**
     * Verifica si la primera sincronización ha sido completada
     *
     * @return bool True si la primera sincronización está completada
     * @since 1.0.0
     */
    public static function isFirstSyncCompleted(): bool
    {
        return get_option('mia_first_sync_completed', false) === true;
    }

    /**
     * Marca la primera sincronización como completada
     *
     * @return bool True si se marcó correctamente
     * @since 1.0.0
     */
    public static function markFirstSyncCompleted(): bool
    {
        // DEBUG: Log para confirmar que se está ejecutando la versión corregida
        self::getLogger()->info('🔧 EJECUTANDO VERSIÓN CORREGIDA de markFirstSyncCompleted()');
        
        // Verificar si ya está marcado como completado
        $already_completed = get_option('mia_first_sync_completed', false);
        
        // Normalizar valores verdaderos de WP ('1', 1, 'true')
        $already_completed_normalized = ($already_completed === true || $already_completed === 1 || $already_completed === '1' || $already_completed === 'true');

        if ($already_completed_normalized) {
            self::getLogger()->info('Primera sincronización ya estaba marcada como completada');
            return true;
        }
        
        $result = update_option('mia_first_sync_completed', true);
        if ($result) {
            self::getLogger()->info('Primera sincronización marcada como completada');
        } else {
            // CORRECCIÓN: update_option puede devolver false si el valor ya existe
            // Verificar si realmente se actualizó
            $current_value = get_option('mia_first_sync_completed', false);
            $current_value_normalized = ($current_value === true || $current_value === 1 || $current_value === '1' || $current_value === 'true');
            if ($current_value_normalized) {
                self::getLogger()->info('Primera sincronización ya estaba completada (update_option devolvió false)');
                return true;
            } else {
                self::getLogger()->error('Error al marcar primera sincronización como completada', [
                    'update_option_result' => $result,
                    'current_value_after' => $current_value,
                    'expected_value' => true
                ]);
                return false;
            }
        }
        
        return $result;
    }
}
