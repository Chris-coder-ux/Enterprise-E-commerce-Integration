<?php
declare(strict_types=1);

namespace MiIntegracionApi\Deteccion;

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Core\SyncLock;
use MiIntegracionApi\Core\Sync_Manager;
use MiIntegracionApi\Helpers\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detector automático de cambios de stock en Verial
 * 
 * Este detector funciona de forma simple y eficiente:
 * 1. Cada 5 minutos consulta Verial para detectar cambios
 * 2. Solo sincroniza productos que han cambiado
 * 3. Reutiliza toda la lógica existente de Sync_Manager
 * 
 * @package MiIntegracionApi\Deteccion
 * @since 2.0.0
 */
class StockDetector
{
    private ApiConnector $api_connector;
    private Logger $logger;
    private Sync_Manager $sync_manager;
    
    // Constantes para el sistema
    private const LOCK_ENTITY = 'automatic_stock_detection';
    private const LOCK_TIMEOUT = 300; // 5 minutos
    private const CRON_HOOK = 'mia_automatic_stock_detection';
    private const CRON_INTERVAL = 'mia_stock_detection_interval';
    
    // Opciones de WordPress
    private const ENABLED_OPTION = 'mia_automatic_stock_detection_enabled';
    private const LAST_SYNC_OPTION = 'mia_automatic_stock_last_sync';
    
    public function __construct(ApiConnector $api_connector, Sync_Manager $sync_manager)
    {
        $this->api_connector = $api_connector;
        $this->sync_manager = $sync_manager;
        $this->logger = new Logger('stock_detector');
        
        // Registrar intervalos de cron personalizados
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);
        
        // Registrar callback del cron job
        add_action(self::CRON_HOOK, [$this, 'execute_detection']);
    }
    
    /**
     * Añade intervalos de cron personalizados
     */
    public function add_cron_intervals(array $schedules): array
    {
        $schedules[self::CRON_INTERVAL] = [
            'interval' => 300, // 5 minutos
            'display' => __('Cada 5 minutos', 'mi-integracion-api')
        ];
        
        return $schedules;
    }
    
    /**
     * Activa la detección automática de stock
     */
    public function activate(): bool
    {
        try {
            // Verificar que no hay sincronización manual en curso
            if ($this->isManualSyncInProgress()) {
                $this->logger->warning('No se puede activar detección automática: sincronización manual en curso');
                return false;
            }
            
            // Programar cron job si no está programado
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
                $this->logger->info('Cron job de detección automática programado');
            }
            
            // Marcar como activado
            update_option(self::ENABLED_OPTION, true);
            update_option(self::LAST_SYNC_OPTION, 0); // Inicializar timestamp
            
            $this->logger->info('Detección automática de stock activada');
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Error activando detección automática', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Desactiva la detección automática de stock
     */
    public function deactivate(): bool
    {
        try {
            // Eliminar cron job
            wp_clear_scheduled_hook(self::CRON_HOOK);
            
            // Marcar como desactivado
            update_option(self::ENABLED_OPTION, false);
            
            $this->logger->info('Detección automática de stock desactivada');
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Error desactivando detección automática', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Verifica si la detección automática está activa
     */
    public function isEnabled(): bool
    {
        return (bool) get_option(self::ENABLED_OPTION, false);
    }
    
    /**
     * Obtiene el estado de la detección automática
     */
    public function getStatus(): array
    {
        $last_sync = get_option(self::LAST_SYNC_OPTION, 0);
        $next_run = wp_next_scheduled(self::CRON_HOOK);
        
        return [
            'enabled' => $this->isEnabled(),
            'last_sync' => $last_sync ? date('Y-m-d H:i:s', $last_sync) : 'Nunca',
            'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : 'No programado',
            'is_manual_sync_active' => $this->isManualSyncInProgress(),
            'lock_active' => SyncLock::isLocked(self::LOCK_ENTITY)
        ];
    }
    
    /**
     * Ejecuta la detección automática de cambios
     * Este método es llamado por el cron job
     */
    public function execute_detection(): void
    {
        // Verificar que está activado
        if (!$this->isEnabled()) {
            $this->logger->debug('Detección automática desactivada, saltando ejecución');
            return;
        }
        
        // Verificar que no hay sincronización manual en curso
        if ($this->isManualSyncInProgress()) {
            $this->logger->debug('Sincronización manual en curso, saltando detección automática');
            return;
        }
        
        // Adquirir lock para evitar ejecuciones simultáneas
        if (!SyncLock::acquire(self::LOCK_ENTITY, self::LOCK_TIMEOUT)) {
            $this->logger->warning('No se pudo adquirir lock para detección automática');
            return;
        }
        
        try {
            $this->logger->info('Iniciando detección automática de cambios de stock');
            
            // 1. Obtener timestamp de última sincronización
            $last_sync = get_option(self::LAST_SYNC_OPTION, 0);
            $current_time = time();
            
            // 2. Detectar cambios en Verial
            $changes_detected = $this->detectChangesInVerial($last_sync);
            
            if ($changes_detected['count'] > 0) {
                $this->logger->info('Cambios detectados en Verial', [
                    'count' => $changes_detected['count'],
                    'since' => $last_sync ? date('Y-m-d H:i:s', $last_sync) : 'Nunca'
                ]);
                
                // 3. Sincronizar productos modificados
                $sync_result = $this->syncChangedProducts($changes_detected);
                
                if ($sync_result['success']) {
                    $this->logger->info('Sincronización automática completada', [
                        'products_synced' => $sync_result['synced_count'],
                        'errors' => $sync_result['error_count']
                    ]);
                    
                    // Generar notificaciones para productos sincronizados
                    $this->generateSyncNotifications($sync_result);
                } else {
                    $this->logger->error('Error en sincronización automática', [
                        'error' => $sync_result['error']
                    ]);
                    
                    // Generar notificación de error
                    $this->generateErrorNotification($sync_result['error']);
                }
            } else {
                $this->logger->debug('No se detectaron cambios en Verial');
            }
            
            // 4. Actualizar timestamp de última sincronización
            update_option(self::LAST_SYNC_OPTION, $current_time);
            
        } catch (\Exception $e) {
            $this->logger->error('Error en detección automática', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        } finally {
            // Liberar lock
            SyncLock::release(self::LOCK_ENTITY);
        }
    }
    
    /**
     * Detecta cambios en Verial usando el sistema de caché existente
     */
    private function detectChangesInVerial(int $last_sync): array
    {
        try {
            // OPTIMIZACIÓN: Usar caché existente en lugar de llamadas directas a API
            $cache_manager = \MiIntegracionApi\CacheManager::get_instance();
            
            // 1. Verificar si hay caché reciente disponible
            $current_hour = date('Y-m-d-H');
            $cache_key = "stock_detection_{$current_hour}";
            
            $cached_data = $cache_manager->get($cache_key, 'mi_api_global');
            
            if ($cached_data !== false) {
                $this->logger->debug('Usando caché existente para detección de cambios', [
                    'cache_key' => $cache_key,
                    'last_sync' => $last_sync ? date('Y-m-d H:i:s', $last_sync) : 'Nunca'
                ]);
                
                return $cached_data;
            }
            
            // 2. Si no hay caché, hacer consulta a la API
            $params = [];
            
            if ($last_sync > 0) {
                $params['fecha'] = date('Y-m-d', $last_sync);
                $params['hora'] = date('H:i:s', $last_sync);
            }
            
            $this->logger->debug('Consultando cambios en Verial (sin caché)', [
                'params' => $params,
                'last_sync' => $last_sync ? date('Y-m-d H:i:s', $last_sync) : 'Nunca'
            ]);
            
            // Llamar a GetNumArticulosWS para contar cambios
            $response = $this->api_connector->get('GetNumArticulosWS', $params);
            
            if (is_wp_error($response)) {
                throw new \Exception('Error consultando cambios en Verial: ' . $response->get_error_message());
            }
            
            // Procesar respuesta
            $data = $this->processApiResponse($response);
            $count = $data['Numero'] ?? 0;
            
            $result = [
                'count' => (int) $count,
                'params' => $params,
                'response_data' => $data,
                'cache_used' => false
            ];
            
            // ✅ OPTIMIZACIÓN: No guardar datos de detección en cache
            // Los datos ya están disponibles en BatchProcessor cache
            // Evitar duplicación innecesaria de información
            
            $this->logger->debug('Datos de detección obtenidos (sin cache)', [
                'cache_key' => $cache_key,
                'count' => $count
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error('Error detectando cambios en Verial', [
                'error' => $e->getMessage(),
                'last_sync' => $last_sync
            ]);
            
            return [
                'count' => 0,
                'error' => $e->getMessage(),
                'cache_used' => false
            ];
        }
    }
    
    /**
     * Sincroniza productos que han cambiado usando la caché del BatchProcessor
     */
    private function syncChangedProducts(array $changes_data): array
    {
        try {
            if ($changes_data['count'] === 0) {
                return [
                    'success' => true,
                    'synced_count' => 0,
                    'error_count' => 0,
                    'cache_used' => $changes_data['cache_used'] ?? false
                ];
            }
            
            $this->logger->info('Iniciando sincronización de productos modificados', [
                'total_changes' => $changes_data['count'],
                'cache_used' => $changes_data['cache_used'] ?? false
            ]);
            
            // OPTIMIZACIÓN: Usar BatchProcessor directamente para aprovechar su caché
            $batch_processor = new \MiIntegracionApi\Core\BatchProcessor($this->api_connector);
            
            // Calcular rango para procesar (máximo 100 productos por detección automática)
            $max_products = min($changes_data['count'], 100);
            $inicio = 1;
            $fin = $max_products;
            
            $this->logger->debug('Procesando productos con BatchProcessor', [
                'inicio' => $inicio,
                'fin' => $fin,
                'total_changes' => $changes_data['count']
            ]);
            
            // Usar el método optimizado del BatchProcessor que aprovecha la caché
            $result = $batch_processor->processProductsWithPreparedBatch(
                $inicio, 
                $fin, 
                function($product) use ($batch_processor) {
                    // Procesar producto individual usando la lógica optimizada para lotes
                    // processSingleProductFromBatch extrae automáticamente el batch_cache del producto
                    return $batch_processor->processSingleProductFromBatch($product);
                }, 
                $max_products
            );
            
            // Extraer información del resultado
            $synced_count = $result['processed'] ?? 0;
            $error_count = $result['errors'] ?? 0;
            $success = $result['success'] ?? false;
            
            // Detectar productos con stock bajo
            $low_stock_products = $this->detectLowStockProducts($result);
            
            $this->logger->info('Sincronización completada con BatchProcessor', [
                'synced_count' => $synced_count,
                'error_count' => $error_count,
                'success' => $success,
                'low_stock_count' => count($low_stock_products),
                'cache_used' => $changes_data['cache_used'] ?? false
            ]);
            
            return [
                'success' => $success,
                'synced_count' => $synced_count,
                'error_count' => $error_count,
                'cache_used' => $changes_data['cache_used'] ?? false,
                'low_stock_products' => $low_stock_products,
                'details' => $result
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Error sincronizando productos modificados', [
                'error' => $e->getMessage(),
                'changes_data' => $changes_data
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'synced_count' => 0,
                'error_count' => 1,
                'cache_used' => $changes_data['cache_used'] ?? false
            ];
        }
    }
    
    /**
     * Procesa la respuesta de la API de Verial
     */
    private function processApiResponse($response): array
    {
        if (is_array($response)) {
            return $response;
        }
        
        // Si es una respuesta HTTP, extraer el cuerpo
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Error decodificando respuesta JSON: ' . json_last_error_msg());
        }
        
        return $data;
    }
    
    /**
     * Verifica si hay sincronización manual en curso
     */
    private function isManualSyncInProgress(): bool
    {
        // Verificar locks de sincronización manual
        $manual_locks = ['sync_global', 'api_verial'];
        
        foreach ($manual_locks as $lock_entity) {
            if (SyncLock::isLocked($lock_entity)) {
                $this->logger->debug('Sincronización manual detectada', [
                    'lock_entity' => $lock_entity
                ]);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Actualiza el timestamp de la última sincronización
     */
    private function updateLastSyncTimestamp(): void
    {
        $current_time = time();
        update_option(self::LAST_SYNC_OPTION, $current_time);
        
        $this->logger->debug('Timestamp de última sincronización actualizado', [
            'timestamp' => $current_time,
            'fecha' => date('Y-m-d H:i:s', $current_time)
        ]);
    }
    
    /**
     * Ejecuta una detección manual (para testing o ejecución inmediata)
     */
    public function executeManualDetection(): array
    {
        if (!$this->isEnabled()) {
            return [
                'success' => false,
                'message' => 'Detección automática no está activada'
            ];
        }
        
        if ($this->isManualSyncInProgress()) {
            return [
                'success' => false,
                'message' => 'Sincronización manual en curso'
            ];
        }
        
        // Ejecutar detección inmediatamente
        $this->execute_detection();
        
        return [
            'success' => true,
            'message' => 'Detección manual ejecutada'
        ];
    }
    
    /**
     * Detecta productos con stock bajo en el resultado de sincronización
     * 
     * @param array $sync_result Resultado de la sincronización
     * @return array Lista de productos con stock bajo
     */
    private function detectLowStockProducts(array $sync_result): array
    {
        try {
            $low_stock_products = [];
            
            // Obtener umbral de stock bajo desde la configuración
            $stock_threshold = 5; // Valor por defecto
            if (class_exists('MiIntegracionApi\\Admin\\NotificationConfig')) {
                $config = \MiIntegracionApi\Admin\NotificationConfig::get_config();
                $stock_threshold = $config['notification_thresholds']['stock_low_quantity'] ?? 5;
            }
            
            // Verificar si hay productos procesados en el resultado
            if (isset($sync_result['products']) && is_array($sync_result['products'])) {
                foreach ($sync_result['products'] as $product_data) {
                    if (isset($product_data['stock']) && $product_data['stock'] <= $stock_threshold) {
                        $low_stock_products[] = [
                            'id' => $product_data['id'] ?? null,
                            'name' => $product_data['name'] ?? 'Producto desconocido',
                            'sku' => $product_data['sku'] ?? '',
                            'stock' => $product_data['stock'] ?? 0,
                            'threshold' => $stock_threshold
                        ];
                    }
                }
            }
            
            return $low_stock_products;
            
        } catch (\Throwable $e) {
            $this->logger->error('Error detectando productos con stock bajo', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Genera notificaciones para productos sincronizados desde Verial
     * 
     * @param array $sync_result Resultado de la sincronización
     * @return void
     */
    private function generateSyncNotifications(array $sync_result): void
    {
        try {
            // Verificar si las notificaciones están habilitadas
            if (!class_exists('MiIntegracionApi\\Admin\\NotificationConfig')) {
                return;
            }
            
            $config = \MiIntegracionApi\Admin\NotificationConfig::get_config();
            if (!$config['notifications_enabled']) {
                return;
            }
            
            // Solo generar notificación si hay productos sincronizados
            if ($sync_result['synced_count'] > 0) {
                $this->createSyncNotification($sync_result);
            }
            
            // Generar notificación de stock bajo si es necesario
            if (isset($sync_result['low_stock_products']) && count($sync_result['low_stock_products']) > 0) {
                $this->createLowStockNotifications($sync_result['low_stock_products']);
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('Error generando notificaciones de sincronización', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Crea notificación de sincronización exitosa
     * 
     * @param array $sync_result Resultado de la sincronización
     * @return void
     */
    private function createSyncNotification(array $sync_result): void
    {
        try {
            $notification_data = [
                'type' => 'sync_success',
                'title' => 'Sincronización Automática Completada',
                'message' => sprintf(
                    'Se sincronizaron %d productos desde Verial automáticamente.',
                    $sync_result['synced_count']
                ),
                'data' => [
                    'synced_count' => $sync_result['synced_count'],
                    'error_count' => $sync_result['error_count'] ?? 0,
                    'cache_used' => $sync_result['cache_used'] ?? false,
                    'timestamp' => current_time('mysql')
                ],
                'priority' => 'info',
                'read' => false,
                'archived' => false,
                'created_at' => current_time('mysql')
            ];
            
            // Guardar notificación en la base de datos
            $this->saveNotification($notification_data);
            
            $this->logger->info('Notificación de sincronización creada', [
                'synced_count' => $sync_result['synced_count']
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->error('Error creando notificación de sincronización', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Crea notificaciones de stock bajo
     * 
     * @param array $low_stock_products Productos con stock bajo
     * @return void
     */
    private function createLowStockNotifications(array $low_stock_products): void
    {
        try {
            foreach ($low_stock_products as $product_data) {
                $notification_data = [
                    'type' => 'stock_low',
                    'title' => 'Stock Bajo Detectado',
                    'message' => sprintf(
                        'El producto "%s" tiene stock bajo: %d unidades',
                        $product_data['name'] ?? 'Producto desconocido',
                        $product_data['stock'] ?? 0
                    ),
                    'data' => [
                        'product_id' => $product_data['id'] ?? null,
                        'product_name' => $product_data['name'] ?? 'Producto desconocido',
                        'current_stock' => $product_data['stock'] ?? 0,
                        'sku' => $product_data['sku'] ?? '',
                        'timestamp' => current_time('mysql')
                    ],
                    'priority' => 'warning',
                    'read' => false,
                    'archived' => false,
                    'created_at' => current_time('mysql')
                ];
                
                $this->saveNotification($notification_data);
            }
            
            $this->logger->info('Notificaciones de stock bajo creadas', [
                'count' => count($low_stock_products)
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->error('Error creando notificaciones de stock bajo', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Crea notificación de error de sincronización
     * 
     * @param string $error_message Mensaje de error
     * @return void
     */
    private function generateErrorNotification(string $error_message): void
    {
        try {
            $notification_data = [
                'type' => 'sync_error',
                'title' => 'Error en Sincronización Automática',
                'message' => sprintf(
                    'Se produjo un error durante la sincronización automática: %s',
                    $error_message
                ),
                'data' => [
                    'error_message' => $error_message,
                    'timestamp' => current_time('mysql'),
                    'detection_type' => 'automatic'
                ],
                'priority' => 'error',
                'read' => false,
                'archived' => false,
                'created_at' => current_time('mysql')
            ];
            
            $this->saveNotification($notification_data);
            
            $this->logger->info('Notificación de error de sincronización creada', [
                'error' => $error_message
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->error('Error creando notificación de error', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Guarda una notificación en la base de datos
     * 
     * @param array $notification_data Datos de la notificación
     * @return bool True si se guardó correctamente
     */
    private function saveNotification(array $notification_data): bool
    {
        try {
            // Obtener notificaciones existentes
            $notifications = get_option('mia_detection_notifications', []);
            
            // Generar ID único
            $notification_id = uniqid('notif_', true);
            $notification_data['id'] = $notification_id;
            
            // Agregar a la lista
            $notifications[] = $notification_data;
            
            // Mantener solo las últimas 100 notificaciones
            if (count($notifications) > 100) {
                $notifications = array_slice($notifications, -100);
            }
            
            // Guardar en la base de datos
            update_option('mia_detection_notifications', $notifications);
            
            return true;
            
        } catch (\Throwable $e) {
            $this->logger->error('Error guardando notificación', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
