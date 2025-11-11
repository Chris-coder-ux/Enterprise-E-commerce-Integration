<?php

declare(strict_types=1);

/**
 * Gestor de Pedidos para la integración con Verial
 *
 * Maneja la sincronización y gestión de pedidos entre WooCommerce y Verial
 *
 * @package    MiIntegracionApi
 * @subpackage WooCommerce
 * @since 1.0.0
 */

namespace MiIntegracionApi\WooCommerce;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\DTOs\OrderDTO;
use MiIntegracionApi\Helpers\MapOrder;
use MiIntegracionApi\Core\RetryManager;
use MiIntegracionApi\Constants\VerialTypes;
use MiIntegracionApi\Core\DataSanitizer;

// Nuevos servicios modulares
use MiIntegracionApi\Services\VerialApiClient;
use MiIntegracionApi\Services\GeographicService;
use MiIntegracionApi\Services\ClientService;
use MiIntegracionApi\Services\PaymentService;
use MiIntegracionApi\Services\TrackingService;

/**
 * Clase OrderManager
 * 
 * Maneja la sincronización y gestión de pedidos entre WooCommerce y Verial
 */
class OrderManager {
    /**
     * Instancia única de esta clase (patrón Singleton)
     * 
     * @var OrderManager
     */
    private static $instance = null;
    
    /**
     * Conector de API para Verial
     * 
     * @var ApiConnector
     */
    private $api_connector;
    
    /**
     * Logger para registrar errores y eventos
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Gestor de reintentos para la sincronización
     * 
     * @var RetryManager
     */
    private $retry_manager;
    
    /**
     * Sanitizador para datos
     * 
     * @var DataSanitizer
     */
    private $sanitizer;
    
    /**
     * Cliente API de Verial
     * 
     * @var VerialApiClient
     */
    private $verial_api_client;
    
    /**
     * Servicio geográfico
     * 
     * @var GeographicService
     */
    private $geographic_service;
    
    /**
     * Servicio de clientes
     * 
     * @var ClientService
     */
    private $client_service;
    
    
    /**
     * Servicio de pagos
     * 
     * @var PaymentService
     */
    private $payment_service;
    
    /**
     * Servicio de seguimiento
     * 
     * @var TrackingService
     */
    private $tracking_service;
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->api_connector = ApiConnector::get_instance();
        $this->logger = new \MiIntegracionApi\Helpers\Logger('order-manager');
        $this->retry_manager = new RetryManager();
        $this->sanitizer = new DataSanitizer();
        
        // Inicializar servicios modulares
        $this->initializeServices();
        
        // Verificar que WooCommerce esté activo antes de registrar los hooks
        if (!\MiIntegracionApi\Hooks\HooksManager::is_woocommerce_active()) {
            $this->logger->warning('WooCommerce no está activo. No se registrarán los hooks de gestión de pedidos.');
            return;
        }
        
        // Registrar hooks para la gestión de pedidos de forma segura con prioridades estandarizadas
        \MiIntegracionApi\Hooks\HooksManager::add_wc_action(
            'woocommerce_order_status_changed', 
            array($this, 'handle_order_status_changed'),
            \MiIntegracionApi\Hooks\HookPriorities::get('WOOCOMMERCE', 'ORDER_STATUS_CHANGED'),
            3
        );
        
        \MiIntegracionApi\Hooks\HooksManager::add_wc_action(
            'woocommerce_checkout_order_processed', 
            array($this, 'handle_new_order'),
            \MiIntegracionApi\Hooks\HookPriorities::get('WOOCOMMERCE', 'CHECKOUT_PROCESSED'),
            3
        );
        
        \MiIntegracionApi\Hooks\HooksManager::add_wc_action(
            'woocommerce_api_create_order', 
            array($this, 'handle_api_order_creation'),
            \MiIntegracionApi\Hooks\HookPriorities::get('WOOCOMMERCE', 'API_CREATE_ORDER'),
            1
        );
        
        // Registrar cualquier error que pueda haber ocurrido
        $errors = \MiIntegracionApi\Hooks\HooksManager::get_errors();
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->logger->error($error);
            }
            \MiIntegracionApi\Hooks\HooksManager::clear_errors();
        }
    }
    
    /**
     * Inicializar servicios modulares
     */
    private function initializeServices() {
        try {
            // Habilitar modo test para desarrollo
            if (defined('WP_DEBUG') && constant('WP_DEBUG')) {
                define('VERIAL_TEST_MODE', true);
            }
            
            // Cliente API principal
            $this->verial_api_client = new VerialApiClient(null, $this->logger);
            
            // Servicios especializados
            $this->geographic_service = new GeographicService($this->verial_api_client, $this->logger);
            $this->client_service = new ClientService($this->verial_api_client, $this->geographic_service, $this->logger);
            $this->payment_service = new PaymentService($this->verial_api_client, $this->logger);
            $this->tracking_service = new TrackingService($this->verial_api_client, $this->logger);
            
            $this->logger->log('Servicios modulares inicializados correctamente', \MiIntegracionApi\Helpers\Logger::LEVEL_INFO);
        } catch (\Exception $e) {
            $this->logger->log('Error inicializando servicios modulares: ' . $e->getMessage(), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR);
        }
    }
    
    /**
     * Obtener la instancia única de esta clase (patrón Singleton)
     * 
     * @return OrderManager
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Maneja el cambio de estado de un pedido en WooCommerce
     * 
     * @param int    $order_id     ID del pedido
     * @param string $old_status   Estado anterior del pedido
     * @param string $new_status   Nuevo estado del pedido
     */
    public function handle_order_status_changed($order_id, $old_status, $new_status) {
        $this->logger->log( sprintf( __( "Cambio de estado del pedido #%d: %s -> %s", 'mi-integracion-api' ), $order_id, $old_status, $new_status ), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
        
        try {
            $order = wc_get_order($order_id);
            
            if (!$order) {
                $this->logger->log( sprintf( __( "No se pudo obtener el pedido #%d", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
                return;
            }
            
            // Verificar si el pedido ya está sincronizado con Verial
            $verial_doc_id = $this->get_verial_doc_id($order);
            
            if (!$verial_doc_id && $new_status === 'processing') {
                // Si el pedido no está sincronizado y pasa a 'processing', lo sincronizamos
                $this->sync_order_to_verial($order);
            } elseif ($verial_doc_id) {
                // Si ya está sincronizado, actualizamos su estado en Verial
                $this->update_order_status_in_verial($order, $verial_doc_id, $new_status);
            }
        } catch (\Exception $e) {
            $this->logger->log( sprintf( __( "Error al manejar cambio de estado del pedido #%d: %s", 'mi-integracion-api' ), $order_id, $e->getMessage() ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
        }
    }
    
    /**
     * Maneja la creación de un nuevo pedido en WooCommerce
     * 
     * @param int   $order_id ID del pedido
     * @param array $posted_data Datos del formulario de checkout
     * @param object $order Objeto del pedido
     */
    public function handle_new_order($order_id, $posted_data, $order) {
        $this->logger->log( sprintf( __( "Nuevo pedido creado #%d", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
        
        try {
            // Por defecto, no sincronizamos automáticamente los nuevos pedidos
            // Esperamos a que pasen al estado 'processing'
            // Para cambiar este comportamiento, descomentar la siguiente línea:
            // $this->sync_order_to_verial($order);
        } catch (\Exception $e) {
            $this->logger->log( sprintf( __( "Error al manejar nuevo pedido #%d: %s", 'mi-integracion-api' ), $order_id, $e->getMessage() ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
        }
    }
    
    /**
     * Maneja la creación de pedidos a través de la API de WooCommerce
     * 
     * @param object $order Objeto del pedido
     */
    public function handle_api_order_creation($order) {
        $order_id = $order->get_id();
        $this->logger->log( sprintf( __( "Nuevo pedido creado a través de API #%d", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
        
        try {
            // Sincronización automática para pedidos creados por API
            $this->sync_order_to_verial($order);
        } catch (\Exception $e) {
            $this->logger->log( sprintf( __( "Error al manejar pedido de API #%d: %s", 'mi-integracion-api' ), $order_id, $e->getMessage() ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
        }
    }
    
    /**
     * Sincroniza un cliente con Verial usando ClientService
     *
     * @param \WC_Order $order Pedido de WooCommerce
     * @return int|false ID del cliente en Verial o false si hay error
     */
    protected function sync_customer($order) {
        try {
            // Verificar si el cliente ya existe por email
            $existing_client = $this->client_service->findClienteByEmail($order->get_billing_email());
            
            if ($existing_client) {
                $client_id = $existing_client['Id'];
                $this->logger->log(sprintf(__('Cliente existente encontrado con ID: %s para pedido #%d', 'mi-integracion-api'), $client_id, $order->get_id()), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO);
                
                $order->update_meta_data('_verial_customer_id', $client_id);
                $order->save();
                
                return $client_id;
            }
            
            // Verificar por NIF si existe
            $nif = $this->extractNIF($order);
            if (!empty($nif)) {
                $existing_client_nif = $this->client_service->findClienteByNIF($nif);
                if ($existing_client_nif) {
                    $client_id = $existing_client_nif['Id'];
                    $this->logger->log(sprintf(__('Cliente existente encontrado por NIF con ID: %s para pedido #%d', 'mi-integracion-api'), $client_id, $order->get_id()), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO);
                    
                    $order->update_meta_data('_verial_customer_id', $client_id);
                    $order->save();
                    
                    return $client_id;
                }
            }
            
            // Crear nuevo cliente usando ClientService
            $customer_data = $this->client_service->convertWooCommerceCustomer($order);
            $result = $this->client_service->createOrUpdateCliente($customer_data);
            
            if ($result && isset($result['Id'])) {
                $client_id = $result['Id'];
                $this->logger->log(sprintf(__('Nuevo cliente creado con ID: %s para pedido #%d', 'mi-integracion-api'), $client_id, $order->get_id()), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO);
                
                $order->update_meta_data('_verial_customer_id', $client_id);
                $order->save();
                
                return $client_id;
            }
            
            // Manejar respuesta de prueba/mock
            if ($result && isset($result['Result']) && $result['Result'] === 'Mock response for testing') {
                $this->logger->log('API devolvió respuesta de prueba - usando ID temporal', \MiIntegracionApi\Helpers\Logger::LEVEL_WARNING);
                
                // Generar ID temporal para pruebas
                $temp_client_id = 'TEMP_' . time() . '_' . $order->get_id();
                $order->update_meta_data('_verial_customer_id', $temp_client_id);
                $order->update_meta_data('_verial_mock_response', true);
                $order->save();
                
                return $temp_client_id;
            }
            
            // Log detallado del error
            $this->logger->log('Error al crear cliente: respuesta inválida del servicio', \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR, [
                'result_type' => gettype($result),
                'result_value' => $result,
                'customer_data_keys' => array_keys($customer_data),
                'billing_email' => $order->get_billing_email(),
                'order_id' => $order->get_id()
            ]);
            return false;
            
        } catch (\Exception $e) {
            $this->logger->log(sprintf(__('Error al sincronizar cliente para pedido #%d: %s', 'mi-integracion-api'), $order->get_id(), $e->getMessage()), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR);
            return false;
        }
    }
    
    /**
     * Extraer NIF de un pedido de WooCommerce
     */
    private function extractNIF(\WC_Order $order): string {
        $nif_fields = ['billing_nif', '_billing_nif', 'billing_vat', 'nif', 'dni'];
        
        foreach ($nif_fields as $field) {
            $value = $order->get_meta($field);
            if (!empty($value)) {
                return (string) $value;
            }
        }
        
        return '';
    }
    /**
     * Sincroniza un pedido de WooCommerce a Verial usando servicios modulares
     * 
     * @param object $order Objeto del pedido WooCommerce
     * @return bool true si fue exitoso, false si falló
     */
    public function sync_order_to_verial($order) {
        $order_id = $order->get_id();
        $this->logger->log( sprintf( __( "Iniciando sincronización completa de pedido #%d a Verial (UID: %s)", 'mi-integracion-api' ), $order_id, $order->get_order_key() ), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
        
        try {
            // 1. VALIDAR PRODUCTOS usando ProductService
            $validation_result = $this->validateOrderProducts($order);
            if (!$validation_result['valid']) {
                $this->logger->log( sprintf( __( "Validación de productos falló para pedido #%d", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR, [
                    'errors' => $validation_result['errors'],
                    'warnings' => $validation_result['warnings']
                ]);
                // Continuar con advertencias, pero fallar con errores críticos
                if (!empty($validation_result['errors'])) {
                    return false;
                }
            }
            
            // 2. SINCRONIZAR CLIENTE usando ClientService
            $client_id = $this->sync_customer($order);
            
            if (!$client_id) {
                $this->logger->log( sprintf( __( "No se pudo sincronizar el cliente para el pedido #%d", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
                return false;
            }
            
            // 3. GENERAR PAYLOAD usando MapOrder (fuente única de verdad)
            $order_data = MapOrder::wc_to_verial($order);
            
            if (empty($order_data)) {
                $this->logger->log( sprintf( __( "MapOrder no pudo generar datos para pedido #%d", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
                return false;
            }
            
            // Asegurar que el cliente esté asignado
            $order_data['ID_Cliente'] = $client_id;
            
            $this->logger->log( sprintf( __( "Datos de pedido #%d preparados usando MapOrder", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_DEBUG );
            
            // 4. CREAR PEDIDO EN VERIAL usando VerialApiClient
            $this->logger->log( sprintf( __( "Enviando pedido #%d a Verial mediante NuevoDocClienteWS", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_DEBUG );
            
            $result = $this->verial_api_client->post('NuevoDocClienteWS', $order_data);
            
            if (!$this->verial_api_client->isSuccess($result)) {
                $error_msg = $this->verial_api_client->getErrorMessage($result);
                $this->logger->log( sprintf( __( "Error al crear pedido en Verial: %s", 'mi-integracion-api' ), $error_msg ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
                
                // Guardar error en metadatos del pedido
                $order->update_meta_data('_verial_sync_error', $error_msg);
                $order->save();
                
                // Si es un error de total incorrecto, registrar más información para diagnóstico
                if (isset($result['InfoError']['Codigo']) && $result['InfoError']['Codigo'] == 16) {
                    $this->registrar_error_total_incorrecto($order, $order_data, $error_msg);
                }
                
                return false;
            }
            
            $verial_doc_id = $result['Id'] ?? null;
            
            // Verificar que el ID de Verial sea válido
            if (!$verial_doc_id || !is_numeric($verial_doc_id)) {
                $this->logger->log( sprintf( __( "Error: ID de Verial inválido para pedido #%d: %s", 'mi-integracion-api' ), $order_id, $verial_doc_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
                return false;
            }
            
            $verial_doc_id = (int) $verial_doc_id;
            $this->logger->log( sprintf( __( "¡ÉXITO! Pedido #%d sincronizado con ID de Verial: %s", 'mi-integracion-api' ), $order_id, $verial_doc_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
            
            // 5. PROCESAR PAGO SI ESTÁ PAGADO usando PaymentService
            if ($order->is_paid()) {
                $payment_result = $this->payment_service->processWooCommercePayment($order, $verial_doc_id);
                if ($payment_result) {
                    $this->logger->log( sprintf( __( "Pago procesado para pedido #%d", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
                } else {
                    $this->logger->log( sprintf( __( "Advertencia: No se pudo procesar el pago para pedido #%d", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_WARNING );
                }
            }
            
            // 6. GUARDAR METADATOS
            $order->update_meta_data('_verial_documento_id', $verial_doc_id);
            $order->update_meta_data('_verial_customer_id', $client_id);
            $order->update_meta_data('_verial_sync_timestamp', current_time('mysql'));
            $order->update_meta_data('_verial_sync_version', '2.0');
            $order->save();
            
            $this->logger->log( sprintf( __( "Metadatos de sincronización guardados para pedido #%d", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_DEBUG );
            
            return true;
            
        } catch (\Exception $e) {
            $error_msg = sprintf(__("ERROR CRÍTICO al sincronizar pedido #%d: %s", 'mi-integracion-api'), $order_id, $e->getMessage());
            $this->logger->log($error_msg, \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR, [
                'trace' => $e->getTraceAsString()
            ]);
            
            // Guardar error en metadatos del pedido
            $order->update_meta_data('_verial_sync_error', $error_msg);
            $order->save();
            
            return false;
        }
    }
    
    /**
     * Validar productos del pedido (versión simplificada sin ProductService)
     */
    private function validateOrderProducts(\WC_Order $order): array {
        try {
            $line_items = [];
            $warnings = [];
            
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if (!$product) {
                    $warnings[] = "Producto no encontrado: " . $item->get_name();
                    continue;
                }
                
                $line_items[] = [
                    'ID_Articulo' => $product->get_id(),
                    'Cantidad' => $item->get_quantity(),
                    'Nombre' => $item->get_name(),
                    'Precio' => $product->get_price()
                ];
            }
            
            return [
                'valid' => true,
                'errors' => [],
                'warnings' => $warnings,
                'products' => $line_items
            ];
            
        } catch (\Exception $e) {
            $this->logger->log('Error validando productos: ' . $e->getMessage(), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR);
            return [
                'valid' => false,
                'errors' => ['Error validando productos: ' . $e->getMessage()],
                'warnings' => [],
                'products' => []
            ];
        }
    }
    
    /**
     * Actualiza el estado de un pedido en Verial usando TrackingService
     * 
     * @param object $order Objeto del pedido WooCommerce
     * @param int $verial_doc_id ID del documento en Verial
     * @param string $new_status Nuevo estado del pedido
     * @return bool true si fue exitoso, false si falló
     */
    public function update_order_status_in_verial($order, $verial_doc_id, $new_status) {
        $order_id = $order->get_id();
        $this->logger->log("Actualizando estado del pedido #$order_id (Verial ID: $verial_doc_id) a: $new_status", \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
        
        try {
            // Verificar si el pedido es modificable usando TrackingService
            if (!$this->tracking_service->isPedidoModificable($verial_doc_id)) {
                $this->logger->log("Pedido #$order_id no es modificable en Verial", \MiIntegracionApi\Helpers\Logger::LEVEL_WARNING );
                return false;
            }
            
            // Mapeo de estados de WooCommerce a Verial
            $verial_status = $this->map_order_status_to_verial($new_status);
            
            // Preparar datos para actualizar el pedido
            $update_data = [
                'Id' => $verial_doc_id,
                'Estado' => $verial_status
            ];
            
            // Actualizar el pedido en Verial utilizando UpdateDocClienteWS a través del cliente API
            $result = $this->verial_api_client->post('UpdateDocClienteWS', $update_data);
            
            if (!$this->verial_api_client->isSuccess($result)) {
                $error_msg = $this->verial_api_client->getErrorMessage($result);
                $this->logger->log("Error al actualizar pedido en Verial: $error_msg", \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
                return false;
            }
            
            // Actualizar metadatos del pedido
            $order->update_meta_data('_verial_last_status_update', current_time('mysql'));
            $order->update_meta_data('_verial_current_status', $verial_status);
            $order->save();
            
            $this->logger->log("Estado del pedido actualizado correctamente en Verial", \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
            return true;
            
        } catch (\Exception $e) {
            $this->logger->log("Error al actualizar estado del pedido #$order_id: " . $e->getMessage(), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
            return false;
        }
    }
    
    /**
     * Obtiene el ID de documento de Verial para un pedido de WooCommerce
     * 
     * @param object $order Objeto del pedido WooCommerce
     * @return int|bool ID del documento en Verial o false si no existe
     */
    public function get_verial_doc_id($order) {
        $verial_doc_id = $order->get_meta('_verial_documento_id', true);
        
        return $verial_doc_id ? $verial_doc_id : false;
    }
    
    /**
     * Prepara los datos del cliente para Verial
     * 
     * @param object $order Objeto del pedido WooCommerce
     * @return array Datos del cliente formateados para Verial
     */
    private function prepare_customer_data($order) {
        // Datos del cliente
        $customer_data = [
            'sesionwcf' => $this->api_connector->get_session_id(),
            'Tipo' => VerialTypes::CUSTOMER_TYPE_INDIVIDUAL, // Particular
            'Nombre' => $order->get_billing_first_name(),
            'Apellido1' => $order->get_billing_last_name(),
            'NIF' => $order->get_meta('_billing_nif', true) ?: $order->get_billing_company(),
            'Email' => $order->get_billing_email(),
            'Telefono1' => $order->get_billing_phone(),
            'CPostal' => $order->get_billing_postcode(),
            'Direccion' => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
            'Localidad' => $order->get_billing_city(),
            'Provincia' => $order->get_billing_state(),
            'RegFiscal' => $this->get_reg_fiscal($order), // Campo obligatorio según el manual de Verial
            'ID_Pais' => $this->get_verial_country_id($order), // Campo obligatorio según el manual de Verial
        ];
        
        // Si es una empresa, cambiamos el tipo
        if ($order->get_billing_company()) {
            $customer_data['Tipo'] = VerialTypes::CUSTOMER_TYPE_COMPANY; // Empresa
            $customer_data['RazonSocial'] = $order->get_billing_company();
        }
        
        return $customer_data;
    }
    
    /**
     * Prepara los datos del pedido para Verial
     * 
     * @param object $order Objeto del pedido WooCommerce
    /**
     * Obtiene o crea una dirección de envío en Verial para un pedido
     * 
     * @param object $order Objeto del pedido WooCommerce
     * @param int $client_id ID del cliente en Verial
     * @return int|null ID de la dirección de envío en Verial
     */
    private function get_verial_shipping_address_id($order, $client_id) {
        try {
            // Datos básicos de la dirección de envío
            $shipping_data = [
                'sesionwcf' => $this->api_connector->get_session_id(),
                'ID_Cliente' => $client_id,
                'Nombre' => $order->get_shipping_first_name(),
                'Apellido1' => $order->get_shipping_last_name(),
                'Apellido2' => '',
                'Direccion' => trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2()),
                'CPostal' => $order->get_shipping_postcode(),
                'Localidad' => $order->get_shipping_city(),
                'Provincia' => $order->get_shipping_state(),
                'Telefono' => $order->get_billing_phone()
            ];
            
            // Opciones específicas para NuevaDireccionEnvioWS
            $options = [
                'headers' => [
                    'Content-Type' => 'text/plain', // NuevaDireccionEnvioWS requiere text/plain
                    'Accept' => '*/*'
                ]
            ];
            
            $result = $this->api_connector->call('NuevaDireccionEnvioWS', 'POST', $shipping_data, [], $options);
            
            if (!$result || isset($result['InfoError']['Codigo']) && $result['InfoError']['Codigo'] != 0) {
                $error_msg = isset($result['InfoError']['Descripcion']) ? $result['InfoError']['Descripcion'] : __('Error desconocido', 'mi-integracion-api');
                $this->logger->log( sprintf( __( "Error al crear dirección de envío en Verial: %s", 'mi-integracion-api' ), $error_msg ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
                return null;
            }
            
            return isset($result['Id']) ? $result['Id'] : null;
        } catch (\Exception $e) {
            $this->logger->log( sprintf( __( "Error al procesar dirección de envío: %s", 'mi-integracion-api' ), $e->getMessage() ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
            return null;
        }
    }
    
    /**
     * Mapea un estado de pedido de WooCommerce a un estado de Verial
     * 
     * @param string $wc_status Estado del pedido en WooCommerce
     * @return int Estado equivalente en Verial
     */
    private function map_order_status_to_verial($wc_status) {
        $status_map = [
            'pending' => 1,    // Pendiente
            'processing' => 2, // En proceso
            'on-hold' => 3,    // En espera
            'completed' => 4,  // Completado
            'cancelled' => 5,  // Cancelado
            'refunded' => 6,   // Reembolsado
            'failed' => 7      // Fallido
        ];
        
        return isset($status_map[$wc_status]) ? $status_map[$wc_status] : 1;
    }
    
    /**
     * Mapea un método de pago de WooCommerce a un ID de forma de pago en Verial
     * 
     * @param string $payment_method Método de pago en WooCommerce
     * @return int ID del método de pago en Verial
     */
    private function map_payment_method_to_verial($payment_method) {
        $payment_map = [
            'bacs' => 1,        // Transferencia bancaria
            'cheque' => 2,      // Cheque
            'cod' => 3,         // Contra reembolso
            'stripe' => 4,      // Tarjeta de crédito
            'paypal' => 5,      // PayPal
            'redsys' => 6,      // Redsys
            'bizum' => 7,       // Bizum
            'default' => 1      // Por defecto
        ];
        
        return isset($payment_map[$payment_method]) ? $payment_map[$payment_method] : $payment_map['default'];
    }
    
    /**
     * Obtiene el ID de artículo en Verial a partir del SKU
     * 
     * @param string $sku SKU del producto
     * @return int|null ID del artículo en Verial o null si no se encuentra
     */
    private function get_verial_article_id($sku) {
        if (empty($sku)) {
            return null;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'verial_product_mapping';
        
        // Intentar obtener el ID desde la tabla de mapeo
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            $article_id = $wpdb->get_var($wpdb->prepare(
                "SELECT verial_id FROM $table_name WHERE sku = %s LIMIT 1",
                $sku
            ));
            
            if ($article_id) {
                return $article_id;
            }
        }
        
        // Si no se encontró en la tabla de mapeo, intentar buscarlo en Verial por referencia
        try {
            $result = $this->api_connector->call('GetArticulosWS?inicio=1&fin=10&referenciaBarras=' . urlencode($sku), 'GET');
            
            if ($result && isset($result['Articulos']) && is_array($result['Articulos']) && count($result['Articulos']) > 0) {
                return $result['Articulos'][0]['Id'];
            }
        } catch (\Exception $e) {
            $this->logger->log("Error al buscar artículo por SKU '$sku': " . $e->getMessage(), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
        }
        
        return null;
    }
    
    /**
     * Obtiene el porcentaje de IVA para un producto
     * 
     * @param object $product Objeto del producto WooCommerce
     * @param object $order Objeto del pedido WooCommerce
     * @return float Porcentaje de IVA
     */
    private function get_product_tax_rate($product, $order) {
        if (!$product) {
            return 0;
        }
        
        // Verificar si el producto es gravable
        $tax_status = $product->get_tax_status();
        if ($tax_status === 'none' || $tax_status === 'zero-rate') {
            return 0;
        }

        // Buscar la línea del pedido que corresponde a este producto
        foreach ($order->get_items() as $item) {
            if ($item->get_product_id() === $product->get_id()) {
                $taxes = $item->get_taxes();
                
                // Verificar si tenemos impuestos para esta línea
                if (!empty($taxes['total'])) {
                    $total_line = $item->get_total();
                    $total_tax_line = 0;
                    
                    // Sumar todos los impuestos de esta línea
                    foreach ($taxes['total'] as $tax_id => $tax_amount) {
                        $total_tax_line += (float)$tax_amount;
                    }
                    
                    // Calcular el porcentaje si tenemos impuestos y subtotal
                    if ($total_line > 0 && $total_tax_line > 0) {
                        return round(($total_tax_line / $total_line) * 100, 2);
                    }
                }
                
                break;
            }
        }
        
        // Si no encontramos información específica, buscar en las tasas de impuestos generales
        $tax_items = $order->get_items('tax');
        
        if (!empty($tax_items)) {
            $tax_item = reset($tax_items);
            return round($tax_item->get_rate_percent(), 2);
        }
        
        // Si no se puede obtener del pedido, usar un valor predeterminado
        return 21.0; // 21% es el IVA estándar en España
    }
    
    /**
     * Obtiene el porcentaje de IVA para los gastos de envío
     * 
     * @param object $order Objeto del pedido WooCommerce
     * @return float Porcentaje de IVA
     */
    private function get_shipping_tax_rate($order) {
        if ($order->get_shipping_tax() > 0) {
            // Calcular el porcentaje de impuesto
            $shipping_total = $order->get_shipping_total();
            $shipping_tax = $order->get_shipping_tax();
            
            if ($shipping_total > 0) {
                return ($shipping_tax / $shipping_total) * 100;
            }
        }
        
        // Valor por defecto
        return 21.0; // 21% es el IVA estándar en España
    }

    /**
     * Verifica el estado de sincronización de un pedido
     *
     * @param int $order_id ID del pedido
     * @return string|null Estado de sincronización
     */
    public function check_sync_status(int $order_id): ?string {
        try {
            // Sanitizar ID del pedido
            $order_id = $this->sanitizer->sanitize($order_id, 'int');

            // Verificar estado de sincronización
            $status = $this->api_connector->get_order_sync_status($order_id);
            if ($status) {
                return $this->sanitizer->sanitize($status, 'text');
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Error al verificar estado de sincronización', [
                'error' => $e->getMessage(),
                'pedido_id' => $order_id
            ]);
            return null;
        }
    }

    /**
     * Reintenta la sincronización de un pedido fallido
     *
     * @param int $order_id ID del pedido
     * @return bool Resultado del reintento
     */
    public function retry_sync(int $order_id): bool {
        try {
            // Sanitizar ID del pedido
            $order_id = $this->sanitizer->sanitize($order_id, 'int');

            // Verificar si se puede reintentar
            if (!$this->retry_manager->can_retry('order', $order_id)) {
                $this->logger->warning('No se puede reintentar la sincronización', [
                    'pedido_id' => $order_id
                ]);
                return false;
            }

            // Obtener datos del pedido
            $order = wc_get_order($order_id);
            if (!$order) {
                $this->logger->error('No se pudieron obtener los datos del pedido', [
                    'pedido_id' => $order_id
                ]);
                return false;
            }

            // Intentar sincronización
            $result = $this->sync_order($order);
            if ($result) {
                $this->retry_manager->mark_success('order', $order_id);
                return true;
            }

            $this->retry_manager->mark_failure('order', $order_id);
            return false;

        } catch (\Exception $e) {
            $this->logger->error('Error al reintentar sincronización', [
                'error' => $e->getMessage(),
                'pedido_id' => $order_id
            ]);
            return false;
        }
    }
    
    /**
     * Obtiene el régimen fiscal adecuado para Verial según los datos del pedido
     * 
     * Según el manual de Verial, RegFiscal es un campo obligatorio con valores:
     * 1: Régimen General
     * 2: Exportaciones
     * 3: Intracomunitario
     * 4: Agencias de viajes
     * 5: Recargo de equivalencia
     * 6: Régimen agrario, ganadero o pesquero
     * 7: Criterio de caja
     * 8: Sin impuestos
     * 
     * @param object $order Objeto del pedido WooCommerce
     * @return int Código de régimen fiscal para Verial
     */
    private function get_reg_fiscal($order) {
        // Intentar obtener régimen fiscal de los metadatos si se ha configurado manualmente
        $reg_fiscal = $order->get_meta('_verial_reg_fiscal', true);
        if ($reg_fiscal && is_numeric($reg_fiscal) && $reg_fiscal >= 1 && $reg_fiscal <= 8) {
            return (int)$reg_fiscal;
        }
        
        // Determinar el país de facturación
        $billing_country = $order->get_billing_country();
        
        // Para España, usar régimen general por defecto (1)
        if ($billing_country === 'ES') {
            return 1; // Régimen General
        }
        
        // Para países de la UE, usar régimen intracomunitario
        $eu_countries = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 
            'PL', 'PT', 'RO', 'SK', 'SI', 'SE'];
        
        if (in_array($billing_country, $eu_countries)) {
            return 3; // Intracomunitario
        }
        
        // Para el resto de países, usar exportaciones
        return 2; // Exportaciones
    }
    
    /**
     * Obtiene el ID del país para Verial según el país del pedido
     * 
     * @param object $order Objeto del pedido WooCommerce
     * @return int ID del país en Verial
     */
    private function get_verial_country_id($order) {
        // Intentar obtener ID de país de los metadatos si se ha configurado manualmente
        $country_id = $order->get_meta('_verial_country_id', true);
        if ($country_id && is_numeric($country_id) && $country_id > 0) {
            return (int)$country_id;
        }

        // Usar el método centralizado de WooCommerceHooks
        if (class_exists('MiIntegracionApi\\WooCommerce\\WooCommerceHooks')) {
            $hooks = new \MiIntegracionApi\WooCommerce\WooCommerceHooks();
            return $hooks->get_verial_country_id_from_wc_code($order->get_billing_country());
        }

        // Fallback si no existe la clase (no debería ocurrir)
        \MiIntegracionApi\Helpers\Logger::warning('No se pudo encontrar WooCommerceHooks para obtener el ID de país. Usando España (1) por defecto.', 'order-manager');
        return 1;
    }
    
    /**
     * Determina el formato de la respuesta de la API para propósitos de diagnóstico
     *
     * @param array $respuesta La respuesta de la API
     * @return string Descripción del formato de respuesta detectado
     */
    private function determinar_formato_respuesta($respuesta) {
        if (!is_array($respuesta)) {
            return "No es un array";
        }
        
        if (isset($respuesta['d'])) {
            return "Formato d: " . json_encode($respuesta['d']);
        }
        
        if (isset($respuesta['Id'])) {
            return "Formato Id directo: " . json_encode($respuesta['Id']);
        }
        
        if (isset($respuesta['respuesta']) && isset($respuesta['respuesta']['Clientes'])) {
            $clientes = $respuesta['respuesta']['Clientes'];
            if (is_array($clientes) && !empty($clientes)) {
                if (isset($clientes[0]['Id'])) {
                    return "Formato respuesta.Clientes[0].Id: " . json_encode($clientes[0]['Id']);
                }
                return "Formato respuesta.Clientes sin Id: " . json_encode(array_keys($clientes[0]));
            }
            return "Formato respuesta.Clientes vacío";
        }
        
        // Si no es ninguno de los formatos esperados, devolver las claves de primer nivel
        return "Formato desconocido con claves: " . json_encode(array_keys($respuesta));
    }

    /**
     * Busca recursivamente el ID del cliente en una estructura de respuesta anidada
     *
     * @param array $array La estructura de respuesta para buscar
     * @param array $keys_buscadas Las claves donde se esperaría encontrar el ID, por defecto 'Id'
     * @return int|null El ID del cliente si se encuentra, o null si no
     */
    private function buscar_id_cliente_recursivo($array, $keys_buscadas = ['Id', 'id', 'ID']) {
        // Caso base: si no es un array, no hay nada que buscar
        if (!is_array($array)) {
            return null;
        }
        
        // Primero buscamos directamente en las claves del array actual
        foreach ($keys_buscadas as $key) {
            if (isset($array[$key]) && !empty($array[$key])) {
                // Verificar que sea un valor numérico positivo
                if (is_numeric($array[$key]) && intval($array[$key]) > 0) {
                    return intval($array[$key]);
                }
            }
        }
        
        // Si hay una clave "Clientes" o "clientes", priorizamos la búsqueda ahí
        $clientes_keys = ['Clientes', 'clientes', 'CLIENTES'];
        foreach ($clientes_keys as $clientes_key) {
            if (isset($array[$clientes_key]) && is_array($array[$clientes_key]) && !empty($array[$clientes_key])) {
                // Si es un array de clientes, tomamos el primero
                if (isset($array[$clientes_key][0]) && is_array($array[$clientes_key][0])) {
                    $primer_cliente = $array[$clientes_key][0];
                    // Buscamos el ID en el primer cliente
                    foreach ($keys_buscadas as $key) {
                        if (isset($primer_cliente[$key]) && !empty($primer_cliente[$key])) {
                            if (is_numeric($primer_cliente[$key]) && intval($primer_cliente[$key]) > 0) {
                                return intval($primer_cliente[$key]);
                            }
                        }
                    }
                }
            }
        }
        
        // Si no se encontró, buscamos recursivamente en cada elemento del array
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result = $this->buscar_id_cliente_recursivo($value, $keys_buscadas);
                if ($result !== null) {
                    return $result;
                }
            }
        }
        
        // Si llegamos aquí, no se encontró el ID
        return null;
    }

    /**
     * Valida los datos del cliente antes de enviarlos a Verial
     * 
     * @param array $customer_data Datos del cliente
     * @param int $order_id ID del pedido (para registro de errores)
     * @return true|\WP_Error True si la validación es exitosa, WP_Error si hay errores
     */
    /**
     * Registra información detallada cuando hay un error de total incorrecto
     * 
     * @param \WC_Order $order Objeto del pedido
     * @param array $order_data Datos del pedido enviados a Verial
     * @param string $error_msg Mensaje de error devuelto por Verial
     */
    private function registrar_error_total_incorrecto($order, $order_data, $error_msg) {
        // Extraer los valores del mensaje de error (si tiene el formato esperado)
        $valor_incorrecto = null;
        $valor_correcto = null;
        
        if (preg_match('/Valor incorrecto: ([0-9.]+) - Valor correcto ([0-9.]+)/', $error_msg, $matches)) {
            $valor_incorrecto = floatval($matches[1]);
            $valor_correcto = floatval($matches[2]);
        }
        
        // Recalcular totales para diagnóstico
        $total_lineas = 0;
        $total_base = 0;
        $total_impuestos = 0;
        
        foreach ($order_data['Contenido'] as $linea) {
            $subtotal_linea = $linea['Precio'] * $linea['Uds'] * (1 - ($linea['Dto'] / 100));
            $impuesto_linea = $subtotal_linea * ($linea['PorcentajeIVA'] / 100);
            
            $total_base += $subtotal_linea;
            $total_impuestos += $impuesto_linea;
            $total_lineas += ($subtotal_linea + $impuesto_linea);
        }
        
        // Registrar toda la información diagnóstica
        $this->logger->log("Error de total incorrecto en pedido: #" . $order->get_id(), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR, [
            'error_completo' => $error_msg,
            'valor_enviado' => $order_data['TotalImporte'],
            'valor_esperado_verial' => $valor_correcto,
            'recalculo_total_lineas' => round($total_lineas, 2),
            'recalculo_base' => round($total_base, 2),
            'recalculo_impuestos' => round($total_impuestos, 2),
            'wc_total' => $order->get_total(),
            'wc_subtotal' => $order->get_subtotal(),
            'wc_tax' => $order->get_total_tax(),
            'diferencia' => $valor_incorrecto - $valor_correcto,
            'lineas' => $order_data['Contenido']
        ]);
    }
    
    private function validate_customer_data($customer_data, $order_id): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
        // Lista de campos obligatorios según el manual de Verial
        $required_fields = [
            'sesionwcf' => __('Número de sesión', 'mi-integracion-api'),
            'Tipo' => __('Tipo de cliente', 'mi-integracion-api'),
            'Nombre' => __('Nombre', 'mi-integracion-api'),
            'RegFiscal' => __('Régimen fiscal', 'mi-integracion-api'),
            'ID_Pais' => __('ID del país', 'mi-integracion-api'),
        ];
        
        $missing_fields = [];
        
        // Verificar que todos los campos obligatorios estén presentes y tengan valor
        foreach ($required_fields as $field => $label) {
            if (!isset($customer_data[$field]) || $customer_data[$field] === '' || $customer_data[$field] === null) {
                $missing_fields[] = $label;
            }
        }
        
        // Si faltan campos, devolver error
        if (!empty($missing_fields)) {
            return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
                sprintf(__('Faltan campos obligatorios para el cliente: %s', 'mi-integracion-api'), 
                    implode(', ', $missing_fields)
                ),
                400,
                [
                    'endpoint' => 'OrderManager::validate_customer_data',
                    'error_code' => 'missing_fields',
                    'missing_fields' => $missing_fields,
                    'order_id' => $order_id,
                    'customer_data' => $customer_data,
                    'timestamp' => time()
                ]
            );
        }
        
        // Validar el formato de RegFiscal (debe ser número entre 1 y 8)
        if (!is_numeric($customer_data['RegFiscal']) || 
            $customer_data['RegFiscal'] < 1 || 
            $customer_data['RegFiscal'] > 8) {
            return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
                sprintf(__('El régimen fiscal debe ser un número entre 1 y 8. Valor actual: %s', 'mi-integracion-api'), 
                    $customer_data['RegFiscal']
                ),
                400,
                [
                    'endpoint' => 'OrderManager::validate_customer_data',
                    'error_code' => 'invalid_reg_fiscal',
                    'reg_fiscal_value' => $customer_data['RegFiscal'],
                    'order_id' => $order_id,
                    'customer_data' => $customer_data,
                    'timestamp' => time()
                ]
            );
        }
        
        // Validar que ID_Pais sea un número positivo
        if (!is_numeric($customer_data['ID_Pais']) || $customer_data['ID_Pais'] <= 0) {
            return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
                sprintf(__('El ID del país debe ser un número positivo. Valor actual: %s', 'mi-integracion-api'), 
                    $customer_data['ID_Pais']
                ),
                400,
                [
                    'endpoint' => 'OrderManager::validate_customer_data',
                    'error_code' => 'invalid_country_id',
                    'country_id_value' => $customer_data['ID_Pais'],
                    'order_id' => $order_id,
                    'customer_data' => $customer_data,
                    'timestamp' => time()
                ]
            );
        }
        
        // Validar tipo de cliente (debe ser 1 para particular o 2 para empresa)
        if (!in_array($customer_data['Tipo'], [VerialTypes::CUSTOMER_TYPE_INDIVIDUAL, VerialTypes::CUSTOMER_TYPE_COMPANY])) {
            return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
                sprintf(__('El tipo de cliente debe ser 1 (particular) o 2 (empresa). Valor actual: %s', 'mi-integracion-api'), 
                    $customer_data['Tipo']
                ),
                400,
                [
                    'endpoint' => 'OrderManager::validate_customer_data',
                    'error_code' => 'invalid_customer_type',
                    'customer_type_value' => $customer_data['Tipo'],
                    'order_id' => $order_id,
                    'customer_data' => $customer_data,
                    'timestamp' => time()
                ]
            );
        }
        
        // Si es una empresa (Tipo=2), verificar que tenga razón social
        if ($customer_data['Tipo'] == VerialTypes::CUSTOMER_TYPE_COMPANY && empty($customer_data['RazonSocial'])) {
            $this->logger->warning(
                __('Cliente de tipo empresa sin razón social definida', 'mi-integracion-api'),
                [
                    'order_id' => $order_id,
                    'cliente' => $customer_data['Nombre']
                ]
            );
            
            // No bloqueamos por esto, pero dejamos registro
        }
        
        // Todas las validaciones pasaron
        return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
            $customer_data,
            'Datos del cliente válidos',
            [
                'endpoint' => 'OrderManager::validate_customer_data',
                'order_id' => $order_id,
                'customer_type' => $customer_data['Tipo'],
                'validation_passed' => true,
                'timestamp' => time()
            ]
        );
    }
    
    /**
     * Obtener estado de pedido desde Verial usando TrackingService
     */
    public function get_order_status_from_verial($order_id): ?array {
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                return null;
            }
            
            $verial_doc_id = $this->get_verial_doc_id($order);
            if (!$verial_doc_id) {
                return null;
            }
            
            return $this->tracking_service->getEstadoPedido($verial_doc_id);
            
        } catch (\Exception $e) {
            $this->logger->log("Error obteniendo estado de Verial para pedido #$order_id: " . $e->getMessage(), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR);
            return null;
        }
    }
    
    /**
     * Sincronizar estados de múltiples pedidos con Verial
     */
    public function sync_multiple_order_states(array $order_ids): array {
        try {
            return $this->tracking_service->syncOrderStatesWithWooCommerce($order_ids);
        } catch (\Exception $e) {
            $this->logger->log("Error sincronizando estados múltiples: " . $e->getMessage(), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR);
            return [
                'success' => 0,
                'errors' => count($order_ids),
                'updated' => [],
                'not_found' => $order_ids
            ];
        }
    }
    
    /**
     * Obtener resumen de servicios cargados
     */
    public function get_services_status(): array {
        return [
            'services_loaded' => [
                'verial_api_client' => $this->verial_api_client !== null,
                'geographic_service' => $this->geographic_service !== null,
                'client_service' => $this->client_service !== null,
                'payment_service' => $this->payment_service !== null,
                'tracking_service' => $this->tracking_service !== null
            ],
            'legacy_components' => [
                'api_connector' => $this->api_connector !== null,
                'retry_manager' => $this->retry_manager !== null,
                'sanitizer' => $this->sanitizer !== null
            ],
            'version' => '2.0.0',
            'architecture' => 'hybrid_modular'
        ];
    }
    
    /**
     * Limpiar caches de todos los servicios
     */
    public function clear_all_caches(): void {
        try {
            if ($this->geographic_service) {
                $this->geographic_service->clearCache();
            }
            if ($this->payment_service) {
                $this->payment_service->clearCache();
            }
            
            $this->logger->log('Caches de servicios limpiados', \MiIntegracionApi\Helpers\Logger::LEVEL_INFO);
        } catch (\Exception $e) {
            $this->logger->log('Error limpiando caches: ' . $e->getMessage(), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR);
        }
    }
}
// Todas las llamadas a log usan Logger::LEVEL_INFO o Logger::LEVEL_ERROR (corregido previamente).
