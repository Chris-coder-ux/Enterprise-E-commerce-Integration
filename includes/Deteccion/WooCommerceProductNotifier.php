<?php
declare(strict_types=1);

namespace MiIntegracionApi\Deteccion;

use MiIntegracionApi\Logging\Core\LoggerBasic;
use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Helpers\MapProduct;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Notificador de productos nuevos en WooCommerce
 * 
 * Esta clase se encarga de manejar las notificaciones cuando se crean
 * productos nuevos en WooCommerce, ya que la API de Verial no permite
 * crear productos directamente.
 * 
 * @package MiIntegracionApi\Deteccion
 * @since 2.0.0
 */
class WooCommerceProductNotifier
{
    private LoggerBasic $logger;
    private ?ApiConnector $api_connector;
    
    // Constantes para opciones de WordPress
    private const NOTIFICATION_ENABLED_OPTION = 'mia_wc_product_notifications_enabled';
    private const AUTO_CREATE_REQUESTS_OPTION = 'mia_auto_create_product_requests';
    private const NOTIFICATION_RETENTION_OPTION = 'mia_notification_retention_days';
    
    // Constantes para tipos de notificación
    private const NOTIFICATION_TYPE_NEW_PRODUCT = 'new_product';
    private const NOTIFICATION_TYPE_PRODUCT_UPDATE = 'product_update';
    private const NOTIFICATION_TYPE_PRODUCT_DELETE = 'product_delete';
    
    // Constantes para estados de notificación
    private const NOTIFICATION_STATUS_PENDING = 'pending';
    private const NOTIFICATION_STATUS_READ = 'read';
    private const NOTIFICATION_STATUS_ARCHIVED = 'archived';
    
    public function __construct(?ApiConnector $api_connector = null)
    {
        $this->logger = new LoggerBasic('wc_product_notifier');
        $this->api_connector = $api_connector;
        
        // Inicializar hooks si estamos en WordPress
        if (function_exists('add_action')) {
            $this->init_hooks();
        }
    }
    
    /**
     * Inicializa los hooks de WordPress
     */
    private function init_hooks(): void
    {
        // Hook para productos nuevos
        add_action('mi_integracion_api_wc_product_created', [$this, 'handle_new_product'], 10, 1);
        
        // Hook para productos actualizados
        add_action('mi_integracion_api_wc_product_updated', [$this, 'handle_product_update'], 10, 2);
        
        // Hook para productos eliminados
        add_action('mi_integracion_api_wc_product_deleted', [$this, 'handle_product_delete'], 10, 1);
        
        // Hook para limpieza de notificaciones antiguas
        add_action('mia_cleanup_old_notifications', [$this, 'cleanup_old_notifications']);
        
        // Programar limpieza diaria si no está programada
        if (!wp_next_scheduled('mia_cleanup_old_notifications')) {
            wp_schedule_event(time(), 'daily', 'mia_cleanup_old_notifications');
        }
    }
    
    /**
     * Maneja la creación de un nuevo producto
     * 
     * @param int $product_id ID del producto creado
     */
    public function handle_new_product(int $product_id): void
    {
        if (!$this->is_notification_enabled()) {
            return;
        }
        
        try {
            $product = wc_get_product($product_id);
            if (!$product) {
                $this->logger->warning('Producto no encontrado al procesar notificación', [
                    'product_id' => $product_id
                ]);
                return;
            }
            
            // 1. Registrar el evento en logs
            $this->log_product_creation($product);
            
            // 2. Crear notificación para administrador
            $notification_id = $this->create_admin_notification(
                self::NOTIFICATION_TYPE_NEW_PRODUCT,
                $product_id,
                $product
            );
            
            // 3. Opcional: Crear documento de solicitud en Verial
            if ($this->should_create_product_request()) {
                $this->create_product_request_document($product);
            }
            
            // 4. Disparar hook personalizado para otros sistemas
            do_action('mia_wc_product_notification_created', $notification_id, $product_id, $product);
            
            $this->logger->info('Notificación de producto nuevo procesada exitosamente', [
                'product_id' => $product_id,
                'notification_id' => $notification_id,
                'sku' => $product->get_sku(),
                'name' => $product->get_name()
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->error('Error procesando notificación de producto nuevo', [
                'product_id' => $product_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Maneja la actualización de un producto
     * 
     * @param int $product_id ID del producto actualizado
     * @param int|null $verial_id ID de Verial si existe
     */
    public function handle_product_update(int $product_id, ?int $verial_id = null): void
    {
        if (!$this->is_notification_enabled()) {
            return;
        }
        
        try {
            $product = wc_get_product($product_id);
            if (!$product) {
                return;
            }
            
            // Solo crear notificación si el producto no está sincronizado con Verial
            if (!$verial_id && !$this->is_product_synced($product_id)) {
                $this->log_product_update($product);
                
                $notification_id = $this->create_admin_notification(
                    self::NOTIFICATION_TYPE_PRODUCT_UPDATE,
                    $product_id,
                    $product
                );
                
                $this->logger->info('Notificación de producto actualizado procesada', [
                    'product_id' => $product_id,
                    'notification_id' => $notification_id,
                    'sku' => $product->get_sku()
                ]);
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('Error procesando notificación de producto actualizado', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Maneja la eliminación de un producto
     * 
     * @param int $product_id ID del producto eliminado
     */
    public function handle_product_delete(int $product_id): void
    {
        if (!$this->is_notification_enabled()) {
            return;
        }
        
        try {
            // Obtener datos del producto antes de eliminarlo
            $product_data = $this->get_product_data_before_deletion($product_id);
            
            if ($product_data) {
                $this->log_product_deletion($product_data);
                
                $notification_id = $this->create_admin_notification(
                    self::NOTIFICATION_TYPE_PRODUCT_DELETE,
                    $product_id,
                    null,
                    $product_data
                );
                
                $this->logger->info('Notificación de producto eliminado procesada', [
                    'product_id' => $product_id,
                    'notification_id' => $notification_id,
                    'sku' => $product_data['sku'] ?? 'N/A'
                ]);
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('Error procesando notificación de producto eliminado', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Registra la creación de un producto en los logs
     */
    private function log_product_creation(\WC_Product $product): void
    {
        $this->logger->info('Nuevo producto creado en WooCommerce', [
            'product_id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'name' => $product->get_name(),
            'price' => $product->get_price(),
            'status' => $product->get_status(),
            'type' => $product->get_type(),
            'date_created' => $product->get_date_created() ? $product->get_date_created()->format('Y-m-d H:i:s') : null,
            'timestamp' => current_time('mysql')
        ]);
    }
    
    /**
     * Registra la actualización de un producto en los logs
     */
    private function log_product_update(\WC_Product $product): void
    {
        $this->logger->info('Producto actualizado en WooCommerce', [
            'product_id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'name' => $product->get_name(),
            'price' => $product->get_price(),
            'status' => $product->get_status(),
            'date_modified' => $product->get_date_modified() ? $product->get_date_modified()->format('Y-m-d H:i:s') : null,
            'timestamp' => current_time('mysql')
        ]);
    }
    
    /**
     * Registra la eliminación de un producto en los logs
     */
    private function log_product_deletion(array $product_data): void
    {
        $this->logger->info('Producto eliminado de WooCommerce', [
            'product_id' => $product_data['id'] ?? 'N/A',
            'sku' => $product_data['sku'] ?? 'N/A',
            'name' => $product_data['name'] ?? 'N/A',
            'price' => $product_data['price'] ?? 'N/A',
            'status' => $product_data['status'] ?? 'N/A',
            'timestamp' => current_time('mysql')
        ]);
    }
    
    /**
     * Crea una notificación para el administrador
     */
    private function create_admin_notification(
        string $type,
        int $product_id,
        ?\WC_Product $product = null,
        ?array $product_data = null
    ): string {
        $notification = [
            'id' => $this->generate_notification_id(),
            'type' => $type,
            'product_id' => $product_id,
            'status' => self::NOTIFICATION_STATUS_PENDING,
            'created_at' => current_time('mysql'),
            'timestamp' => time(),
            'requires_action' => true,
            'data' => $this->prepare_notification_data($product, $product_data)
        ];
        
        $notifications = get_option('mia_pending_notifications', []);
        $notifications[] = $notification;
        update_option('mia_pending_notifications', $notifications);
        
        return $notification['id'];
    }
    
    /**
     * Prepara los datos para la notificación
     */
    private function prepare_notification_data(?\WC_Product $product, ?array $product_data): array
    {
        if ($product) {
            return [
                'sku' => $product->get_sku(),
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'status' => $product->get_status(),
                'type' => $product->get_type(),
                'permalink' => $product->get_permalink(),
                'edit_link' => get_edit_post_link($product->get_id())
            ];
        }
        
        return $product_data ?? [];
    }
    
    /**
     * Crea un documento de solicitud en Verial (opcional)
     * 
     * @param \WC_Product $product Producto de WooCommerce
     * @return bool True si se creó exitosamente, false en caso contrario
     */
    private function create_product_request_document(\WC_Product $product): bool
    {
        if (!$this->api_connector) {
            $this->logger->warning('ApiConnector no disponible para crear documento de solicitud');
            return false;
        }
        
        try {
            $session_id = $this->get_verial_session_id();
            if (!$session_id) {
                $this->logger->warning('No se pudo obtener sesión de Verial para crear documento');
                return false;
            }
            
            // Preparar datos del documento
            $document_data = $this->prepare_product_request_data($product, $session_id);
            
            // Intentar crear el documento en Verial
            $result = $this->send_document_to_verial($document_data);
            
            if ($result['success']) {
                $this->logger->info('Documento de solicitud creado exitosamente en Verial', [
                    'product_id' => $product->get_id(),
                    'sku' => $product->get_sku(),
                    'verial_document_id' => $result['document_id'] ?? 'N/A',
                    'reference' => $document_data['Referencia']
                ]);
                
                // Guardar referencia del documento en metadatos del producto
                $this->save_document_reference($product, $result);
                
                return true;
            } else {
                $this->logger->error('Error creando documento de solicitud en Verial', [
                    'product_id' => $product->get_id(),
                    'error' => $result['error'] ?? 'Error desconocido'
                ]);
                return false;
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('Excepción creando documento de solicitud', [
                'product_id' => $product->get_id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Prepara los datos del documento de solicitud
     * 
     * @param \WC_Product $product Producto de WooCommerce
     * @param int $session_id ID de sesión de Verial
     * @return array Datos del documento
     */
    private function prepare_product_request_data(\WC_Product $product, int $session_id): array
    {
        $reference = 'SOLICITUD_PRODUCTO_' . $product->get_id() . '_' . time();
        
        // Obtener información del sitio
        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email', 'admin@example.com');
        
        // Preparar descripción del producto
        $description = $this->build_product_description($product);
        
        return [
            'sesionwcf' => $session_id,
            'Tipo' => 6, // Presupuesto (tipo 6 según documentación Verial)
            'Referencia' => $reference,
            'Fecha' => date('Y-m-d'),
            'Cliente' => [
                'Tipo' => 1, // Particular
                'Nombre' => 'Sistema WooCommerce - ' . $site_name,
                'Email' => $admin_email,
                'Telefono' => get_option('admin_phone', ''),
                'Direccion' => get_option('admin_address', ''),
                'CPostal' => get_option('admin_postal_code', ''),
                'Localidad' => get_option('admin_city', ''),
                'Provincia' => get_option('admin_state', ''),
                'ID_Pais' => 1 // España por defecto
            ],
            'BaseImponible' => 0.00,
            'TotalImporte' => 0.00,
            'PreciosImpIncluidos' => true,
            'Contenido' => [
                [
                    'TipoRegistro' => 2, // Comentario
                    'Comentario' => $description
                ]
            ],
            'Pagos' => [],
            'Aux1' => 'SOLICITUD_AUTOMATICA_WOOCOMMERCE',
            'Aux2' => 'Producto ID: ' . $product->get_id(),
            'Aux3' => 'SKU: ' . $product->get_sku(),
            'Aux4' => 'Fecha creación: ' . date('Y-m-d H:i:s'),
            'Aux5' => 'Sitio: ' . get_site_url(),
            'Aux6' => 'Plugin: Mi Integración API v' . (defined('MiIntegracionApi_VERSION') ? MiIntegracionApi_VERSION : '2.0.0')
        ];
    }
    
    /**
     * Construye la descripción del producto para el documento
     * 
     * @param \WC_Product $product Producto de WooCommerce
     * @return string Descripción formateada
     */
    private function build_product_description(\WC_Product $product): string
    {
        $description = "SOLICITUD DE CREACIÓN DE PRODUCTO EN VERIAL\n\n";
        $description .= "INFORMACIÓN DEL PRODUCTO:\n";
        $description .= "• Nombre: " . $product->get_name() . "\n";
        $description .= "• SKU: " . $product->get_sku() . "\n";
        $description .= "• Precio: " . wc_price($product->get_price()) . "\n";
        $description .= "• Tipo: " . $product->get_type() . "\n";
        $description .= "• Estado: " . $product->get_status() . "\n";
        
        if ($product->get_short_description()) {
            $description .= "• Descripción corta: " . $product->get_short_description() . "\n";
        }
        
        if ($product->get_description()) {
            $description .= "• Descripción: " . wp_strip_all_tags($product->get_description()) . "\n";
        }
        
        // Información de stock
        if ($product->managing_stock()) {
            $description .= "• Stock: " . $product->get_stock_quantity() . " unidades\n";
        }
        
        // Categorías
        $categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
        if (!empty($categories)) {
            $description .= "• Categorías: " . implode(', ', $categories) . "\n";
        }
        
        // Etiquetas
        $tags = wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']);
        if (!empty($tags)) {
            $description .= "• Etiquetas: " . implode(', ', $tags) . "\n";
        }
        
        $description .= "\nINFORMACIÓN TÉCNICA:\n";
        $description .= "• ID WooCommerce: " . $product->get_id() . "\n";
        $description .= "• Enlace de edición: " . get_edit_post_link($product->get_id()) . "\n";
        $description .= "• Enlace público: " . $product->get_permalink() . "\n";
        $description .= "• Fecha de creación: " . $product->get_date_created()->format('Y-m-d H:i:s') . "\n";
        
        $description .= "\nSOLICITUD:\n";
        $description .= "Por favor, cree este producto en el sistema Verial con la información proporcionada.\n";
        $description .= "Una vez creado, el sistema de sincronización automática se encargará de mantenerlo actualizado.\n";
        
        return $description;
    }
    
    /**
     * Envía el documento a Verial usando el ApiConnector
     * 
     * @param array $document_data Datos del documento
     * @return array Resultado de la operación
     */
    private function send_document_to_verial(array $document_data): array
    {
        try {
            // Usar el endpoint NuevoDocClienteWS de Verial
            $response = $this->api_connector->make_request('NuevoDocClienteWS', $document_data, 'POST');
            
            if ($response && isset($response['InfoError'])) {
                if ($response['InfoError']['Codigo'] === 0) {
                    return [
                        'success' => true,
                        'document_id' => $response['Id'] ?? null,
                        'reference' => $response['Referencia'] ?? $document_data['Referencia'],
                        'response' => $response
                    ];
                } else {
                    return [
                        'success' => false,
                        'error' => $response['InfoError']['Descripcion'] ?? 'Error desconocido de Verial',
                        'code' => $response['InfoError']['Codigo'] ?? 'N/A'
                    ];
                }
            }
            
            return [
                'success' => false,
                'error' => 'Respuesta inválida de Verial'
            ];
            
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Excepción en comunicación con Verial: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Guarda la referencia del documento en los metadatos del producto
     * 
     * @param \WC_Product $product Producto de WooCommerce
     * @param array $result Resultado de la creación del documento
     */
    private function save_document_reference(\WC_Product $product, array $result): void
    {
        $document_refs = get_post_meta($product->get_id(), '_mia_verial_document_requests', true) ?: [];
        
        $document_refs[] = [
            'document_id' => $result['document_id'] ?? null,
            'reference' => $result['reference'] ?? null,
            'created_at' => current_time('mysql'),
            'timestamp' => time(),
            'type' => 'product_request',
            'status' => 'pending'
        ];
        
        update_post_meta($product->get_id(), '_mia_verial_document_requests', $document_refs);
        
        // También guardar en una opción global para seguimiento
        $global_requests = get_option('mia_verial_document_requests', []);
        $global_requests[] = [
            'product_id' => $product->get_id(),
            'document_id' => $result['document_id'] ?? null,
            'reference' => $result['reference'] ?? null,
            'created_at' => current_time('mysql'),
            'status' => 'pending'
        ];
        update_option('mia_verial_document_requests', $global_requests);
    }
    
    /**
     * Obtiene los datos del producto antes de eliminarlo
     */
    private function get_product_data_before_deletion(int $product_id): ?array
    {
        // Intentar obtener datos del producto desde la papelera
        $product = wc_get_product($product_id);
        if ($product) {
            return [
                'id' => $product->get_id(),
                'sku' => $product->get_sku(),
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'status' => $product->get_status(),
                'type' => $product->get_type()
            ];
        }
        
        return null;
    }
    
    /**
     * Verifica si un producto está sincronizado con Verial
     */
    private function is_product_synced(int $product_id): bool
    {
        if (class_exists('MiIntegracionApi\\Helpers\\MapProduct')) {
            $verial_id = MapProduct::get_verial_id_by_wc_id($product_id);
            return !empty($verial_id);
        }
        
        return false;
    }
    
    /**
     * Verifica si las notificaciones están habilitadas
     */
    private function is_notification_enabled(): bool
    {
        return (bool) get_option(self::NOTIFICATION_ENABLED_OPTION, true);
    }
    
    /**
     * Verifica si se deben crear solicitudes de producto automáticamente
     */
    private function should_create_product_request(): bool
    {
        return (bool) get_option(self::AUTO_CREATE_REQUESTS_OPTION, false);
    }
    
    /**
     * Obtiene el ID de sesión de Verial
     */
    private function get_verial_session_id(): ?int
    {
        if ($this->api_connector && method_exists($this->api_connector, 'get_session_id')) {
            return $this->api_connector->get_session_id();
        }
        
        // Fallback usando configuración centralizada
        try {
            if (class_exists('VerialApiConfig')) {
                $verial_config = \VerialApiConfig::getInstance();
                return (int) $verial_config->getVerialSessionId();
            }
        } catch (\Exception $e) {
            $this->logger->warning('Error obteniendo sesión desde VerialApiConfig: ' . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Genera un ID único para la notificación
     */
    private function generate_notification_id(): string
    {
        return 'notif_' . uniqid() . '_' . time();
    }
    
    /**
     * Obtiene todas las solicitudes de documentos pendientes
     * 
     * @return array Lista de solicitudes de documentos
     */
    public function get_pending_document_requests(): array
    {
        return get_option('mia_verial_document_requests', []);
    }
    
    /**
     * Obtiene las solicitudes de documentos de un producto específico
     * 
     * @param int $product_id ID del producto
     * @return array Lista de solicitudes del producto
     */
    public function get_product_document_requests(int $product_id): array
    {
        return get_post_meta($product_id, '_mia_verial_document_requests', true) ?: [];
    }
    
    /**
     * Actualiza el estado de una solicitud de documento
     * 
     * @param int $product_id ID del producto
     * @param string $reference Referencia del documento
     * @param string $status Nuevo estado
     * @return bool True si se actualizó exitosamente
     */
    public function update_document_request_status(int $product_id, string $reference, string $status): bool
    {
        try {
            // Actualizar en metadatos del producto
            $product_requests = $this->get_product_document_requests($product_id);
            foreach ($product_requests as &$request) {
                if ($request['reference'] === $reference) {
                    $request['status'] = $status;
                    $request['updated_at'] = current_time('mysql');
                    break;
                }
            }
            update_post_meta($product_id, '_mia_verial_document_requests', $product_requests);
            
            // Actualizar en lista global
            $global_requests = $this->get_pending_document_requests();
            foreach ($global_requests as &$request) {
                if ($request['product_id'] === $product_id && $request['reference'] === $reference) {
                    $request['status'] = $status;
                    $request['updated_at'] = current_time('mysql');
                    break;
                }
            }
            update_option('mia_verial_document_requests', $global_requests);
            
            $this->logger->info('Estado de solicitud de documento actualizado', [
                'product_id' => $product_id,
                'reference' => $reference,
                'status' => $status
            ]);
            
            return true;
            
        } catch (\Throwable $e) {
            $this->logger->error('Error actualizando estado de solicitud de documento', [
                'product_id' => $product_id,
                'reference' => $reference,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Crea manualmente una solicitud de documento para un producto
     * 
     * @param int $product_id ID del producto
     * @return array Resultado de la operación
     */
    public function create_manual_document_request(int $product_id): array
    {
        try {
            $product = wc_get_product($product_id);
            if (!$product) {
                return [
                    'success' => false,
                    'error' => 'Producto no encontrado'
                ];
            }
            
            $result = $this->create_product_request_document($product);
            
            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Solicitud de documento creada exitosamente'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Error creando solicitud de documento'
                ];
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('Error creando solicitud manual de documento', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => 'Excepción: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtiene estadísticas de solicitudes de documentos
     * 
     * @return array Estadísticas
     */
    public function get_document_request_stats(): array
    {
        $requests = $this->get_pending_document_requests();
        
        $stats = [
            'total' => count($requests),
            'pending' => 0,
            'completed' => 0,
            'failed' => 0,
            'today' => 0
        ];
        
        $today = date('Y-m-d');
        
        foreach ($requests as $request) {
            switch ($request['status'] ?? 'pending') {
                case 'pending':
                    $stats['pending']++;
                    break;
                case 'completed':
                    $stats['completed']++;
                    break;
                case 'failed':
                    $stats['failed']++;
                    break;
            }
            
            if (isset($request['created_at']) && strpos($request['created_at'], $today) === 0) {
                $stats['today']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * Limpia solicitudes de documentos antiguas
     * 
     * @param int $days Días de retención (por defecto 90)
     * @return int Número de solicitudes eliminadas
     */
    public function cleanup_old_document_requests(int $days = 90): int
    {
        $cutoff_time = time() - ($days * DAY_IN_SECONDS);
        $requests = $this->get_pending_document_requests();
        $original_count = count($requests);
        
        $requests = array_filter($requests, function($request) use ($cutoff_time) {
            $created_time = isset($request['created_at']) ? strtotime($request['created_at']) : 0;
            return $created_time > $cutoff_time;
        });
        
        update_option('mia_verial_document_requests', array_values($requests));
        
        $deleted_count = $original_count - count($requests);
        
        if ($deleted_count > 0) {
            $this->logger->info('Solicitudes de documentos antiguas eliminadas', [
                'deleted_count' => $deleted_count,
                'retention_days' => $days
            ]);
        }
        
        return $deleted_count;
    }
    
    /**
     * Limpia notificaciones antiguas
     */
    public function cleanup_old_notifications(): void
    {
        $retention_days = (int) get_option(self::NOTIFICATION_RETENTION_OPTION, 30);
        $cutoff_time = time() - ($retention_days * DAY_IN_SECONDS);
        
        $notifications = get_option('mia_pending_notifications', []);
        $original_count = count($notifications);
        
        $notifications = array_filter($notifications, function($notification) use ($cutoff_time) {
            return ($notification['timestamp'] ?? 0) > $cutoff_time;
        });
        
        if (count($notifications) !== $original_count) {
            update_option('mia_pending_notifications', array_values($notifications));
            
            $this->logger->info('Notificaciones antiguas limpiadas', [
                'original_count' => $original_count,
                'remaining_count' => count($notifications),
                'retention_days' => $retention_days
            ]);
        }
    }
    
    /**
     * Obtiene todas las notificaciones pendientes
     */
    public function get_pending_notifications(): array
    {
        return get_option('mia_pending_notifications', []);
    }
    
    /**
     * Marca una notificación como leída
     */
    public function mark_notification_read(string $notification_id): bool
    {
        $notifications = get_option('mia_pending_notifications', []);
        
        foreach ($notifications as &$notification) {
            if ($notification['id'] === $notification_id) {
                $notification['status'] = self::NOTIFICATION_STATUS_READ;
                $notification['read_at'] = current_time('mysql');
                update_option('mia_pending_notifications', $notifications);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Archiva una notificación
     */
    public function archive_notification(string $notification_id): bool
    {
        $notifications = get_option('mia_pending_notifications', []);
        
        foreach ($notifications as $index => $notification) {
            if ($notification['id'] === $notification_id) {
                unset($notifications[$index]);
                update_option('mia_pending_notifications', array_values($notifications));
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Obtiene estadísticas de notificaciones
     */
    public function get_notification_stats(): array
    {
        $notifications = $this->get_pending_notifications();
        
        $stats = [
            'total' => count($notifications),
            'pending' => 0,
            'read' => 0,
            'by_type' => []
        ];
        
        foreach ($notifications as $notification) {
            $status = $notification['status'] ?? self::NOTIFICATION_STATUS_PENDING;
            $type = $notification['type'] ?? 'unknown';
            
            if ($status === self::NOTIFICATION_STATUS_PENDING) {
                $stats['pending']++;
            } elseif ($status === self::NOTIFICATION_STATUS_READ) {
                $stats['read']++;
            }
            
            if (!isset($stats['by_type'][$type])) {
                $stats['by_type'][$type] = 0;
            }
            $stats['by_type'][$type]++;
        }
        
        return $stats;
    }
}
