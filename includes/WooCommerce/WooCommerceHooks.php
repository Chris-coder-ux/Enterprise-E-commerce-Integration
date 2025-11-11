<?php

declare(strict_types=1);

/**
 * Clase WooCommerceHooks
 * 
 * Gestiona los hooks y eventos de WooCommerce para la sincronización con la API externa.
 * 
 * Hooks disponibles:
 * 
 * Acciones:
 * - mi_integracion_api_before_product_sync: Se ejecuta antes de sincronizar un producto
 *   Parámetros: (int) $product_id, (array) $product_data
 * 
 * - mi_integracion_api_after_product_sync: Se ejecuta después de sincronizar un producto
 *   Parámetros: (int) $product_id, (bool) $success, (array) $result
 * 
 * - mi_integracion_api_before_order_sync: Se ejecuta antes de sincronizar un pedido
 *   Parámetros: (int) $order_id, (array) $order_data
 * 
 * - mi_integracion_api_after_order_sync: Se ejecuta después de sincronizar un pedido
 *   Parámetros: (int) $order_id, (bool) $success, (array) $result
 * 
 * - mi_integracion_api_before_customer_sync: Se ejecuta antes de sincronizar un cliente
 *   Parámetros: (int) $customer_id, (array) $customer_data
 * 
 * - mi_integracion_api_after_customer_sync: Se ejecuta después de sincronizar un cliente
 *   Parámetros: (int) $customer_id, (bool) $success, (array) $result
 * 
 * Filtros:
 * - mi_integracion_api_product_data: Filtra los datos del producto antes de la sincronización
 *   Parámetros: (array) $product_data, (int) $product_id
 * 
 * - mi_integracion_api_order_data: Filtra los datos del pedido antes de la sincronización
 *   Parámetros: (array) $order_data, (int) $order_id
 * 
 * - mi_integracion_api_customer_data: Filtra los datos del cliente antes de la sincronización
 *   Parámetros: (array) $customer_data, (int) $customer_id
 * 
 * @package MiIntegracionApi
 * @since 1.0.0
 */

namespace MiIntegracionApi\WooCommerce;

use MiIntegracionApi\DTOs\ProductDTO;
use MiIntegracionApi\DTOs\OrderDTO;
use MiIntegracionApi\DTOs\CustomerDTO;
use MiIntegracionApi\Helpers\MapProduct;
use MiIntegracionApi\Helpers\MapOrder;
use MiIntegracionApi\Helpers\MapCustomer;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Core\DataSanitizer;
use MiIntegracionApi\Core\RetryManager;
use MiIntegracionApi\CacheManager;
use MiIntegracionApi\Core\ErrorHandler;
use MiIntegracionApi\Traits\MainPluginAccessor;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salir si se accede directamente.
}

class WooCommerceHooks {
    use MainPluginAccessor;

    private $logger;
    private $sanitizer;
    private $retry_manager;
    private $api_connector;
    private $cache_manager;
    private $error_handler;
    private $batch_size = 50;
    private $cache_ttl = 3600; // 1 hora
    // REFACTORIZADO: Usar sistema de reintentos inteligente en lugar de valores hardcodeados
    private $max_retries = 0; // Se obtiene dinámicamente del RetryPolicyManager
    private $retry_delay = 0; // Se calcula dinámicamente del RetryPolicyManager
    private $min_wc_version = '5.0.0';
    private $min_php_version = '7.4.0';

    /**
     * Constructor.
     * FASE 1 CORREGIDA: Usa componentes centralizados cuando están disponibles
     * Aplica buenas prácticas: Dependency Inversion, Resource Management, Fail Fast
     */
    public function __construct() {
        // FASE 1 CORREGIDA: Intentar usar componentes centralizados primero
        $mainPlugin = $this->getMainPlugin();
        
        if ($mainPlugin) {
            // Usar componentes del plugin principal
            $this->logger = $mainPlugin->getComponent('logger') ?? new Logger('woocommerce_hooks');
            $this->api_connector = $mainPlugin->getComponent('api_connector') ?? new \MiIntegracionApi\Core\ApiConnector();
            $this->error_handler = $mainPlugin->getComponent('logger') ?? new \MiIntegracionApi\Helpers\Logger('woocommerce_hooks');
        } else {
            // Fallback a componentes locales si no hay plugin principal
            $this->logger = new Logger('woocommerce_hooks');
            $this->api_connector = new \MiIntegracionApi\Core\ApiConnector();
            $this->error_handler = new \MiIntegracionApi\Helpers\Logger('woocommerce_hooks');
        }
        
        // Componentes que siempre se crean localmente
        $this->sanitizer = new DataSanitizer();
        $this->retry_manager = new RetryManager();
        $this->cache_manager = \MiIntegracionApi\CacheManager::get_instance();
        
        if (!$this->check_requirements()) {
            return;
        }
        
        $this->init_hooks();
    }

    /**
     * Verifica los requisitos del sistema
     * 
     * @return bool
     */
    private function check_requirements(): bool {
        // Verificar versión de PHP
        if (version_compare(PHP_VERSION, $this->min_php_version, '<')) {
            $this->log_error('php_version_error', 'Versión de PHP insuficiente', [
                'required' => $this->min_php_version,
                'current' => PHP_VERSION
            ]);
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>' . esc_html__('Mi Integración API:', 'mi-integracion-api') . '</strong> ';
                printf(
                    esc_html__('Requiere PHP %s o superior. Versión actual: %s', 'mi-integracion-api'),
                    $this->min_php_version,
                    PHP_VERSION
                );
                echo '</p></div>';
            });
            return false;
        }

        // Verificar WooCommerce
        if (!$this->is_woocommerce_active()) {
            $this->log_error('woocommerce_inactive', 'WooCommerce no está activo', [
                'context' => 'initialization'
            ]);
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>' . esc_html__('Mi Integración API:', 'mi-integracion-api') . '</strong> ';
                echo esc_html__('Requiere WooCommerce activo para funcionar correctamente.', 'mi-integracion-api');
                echo '</p></div>';
            });
            return false;
        }

        // Verificar versión de WooCommerce
        if (!$this->check_woocommerce_version()) {
            $this->log_error('wc_version_error', 'Versión de WooCommerce insuficiente', [
                'required' => $this->min_wc_version,
                'current' => WC()->version
            ]);
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>' . esc_html__('Mi Integración API:', 'mi-integracion-api') . '</strong> ';
                printf(
                    esc_html__('Requiere WooCommerce %s o superior. Versión actual: %s', 'mi-integracion-api'),
                    $this->min_wc_version,
                    WC()->version
                );
                echo '</p></div>';
            });
            return false;
        }

        return true;
    }


    /**
     * Verifica si WooCommerce está activo
     * 
     * @return bool
     */
    private function is_woocommerce_active(): bool {
        return class_exists('WooCommerce');
    }

    /**
     * Verifica la versión de WooCommerce
     * 
     * @return bool
     */
    private function check_woocommerce_version(): bool {
        // Si estamos en modo test o WooCommerce no está realmente instalado
        $wc_version = null;
        if (function_exists('WC') && WC() && isset(WC()->version)) {
            $wc_version = WC()->version;
        } elseif (defined('WC_VERSION')) {
            $wc_version = WC_VERSION;
        }
        
        // Si no podemos obtener la versión, asumir que es compatible (modo test)
        if (is_null($wc_version)) {
            return true;
        }
        
        return version_compare($wc_version, $this->min_wc_version, '>=');
    }

    /**
     * Inicializa los hooks de WooCommerce con verificaciones adicionales de seguridad.
     */
    public function init_hooks() {
        // Verificación adicional de seguridad antes de registrar hooks
        if (!$this->is_woocommerce_active()) {
            error_log('Mi Integración API: Intento de inicializar hooks sin WooCommerce activo');
            return;
        }
        
        // Verificar que el ApiConnector esté disponible
        if (!$this->api_connector || !is_object($this->api_connector)) {
            error_log('Mi Integración API: ApiConnector no está disponible para los hooks de WooCommerce');
            return;
        }
        
        // Verificar que el ApiConnector tenga configuración válida
        if (method_exists($this->api_connector, 'get_api_base_url')) {
            $api_url = $this->api_connector->get_api_base_url();
            if (empty($api_url)) {
                error_log('Mi Integración API: URL base de la API no configurada');
                return;
            }
        }
        
        // Productos
        add_action('woocommerce_new_product', [$this, 'on_product_created']);
        add_action('woocommerce_update_product', [$this, 'on_product_updated']);
        add_action('woocommerce_trash_product', [$this, 'on_product_deleted']);

        // Pedidos
        add_action('woocommerce_new_order', [$this, 'on_order_created']);
        add_action('woocommerce_update_order', [$this, 'on_order_updated']);
        add_action('woocommerce_trash_order', [$this, 'on_order_deleted']);

        // Clientes
        add_action('woocommerce_created_customer', [$this, 'on_customer_created']);
        add_action('woocommerce_update_customer', [$this, 'on_customer_updated']);
        add_action('woocommerce_delete_customer', [$this, 'on_customer_deleted']);
        
        // Log de inicialización exitosa
        if (class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
            \MiIntegracionApi\Helpers\Logger::info('Hooks de WooCommerce inicializados correctamente', 'woocommerce_hooks');
        }

        // Hooks personalizados
        add_action('mi_integracion_api_before_sync', [$this, 'before_sync']);
        add_action('mi_integracion_api_after_sync', [$this, 'after_sync']);
        
        // Registrar filtros para WooCommerce
        self::register_woocommerce_filters();
        
        // Filtros
        add_filter('mi_integracion_api_product_data', [$this, 'filter_product_data'], 10, 2);
        add_filter('mi_integracion_api_order_data', [$this, 'filter_order_data'], 10, 2);
        add_filter('mi_integracion_api_customer_data', [$this, 'filter_customer_data'], 10, 2);

        // Asegurar que el límite de productos relacionados sea siempre un entero
        // Esto corrige el error "Invalid limit type passed to wc_get_related_products".
        add_filter('woocommerce_related_products_args', [self::class, 'ensure_related_products_limit_is_integer'], 99);
    }

    public function on_product_created($product_id) {
        $context = [
            'product_id' => $product_id,
            'action' => 'product_created',
            'timestamp' => current_time('mysql')
        ];

        try {
            $this->log_info('product_sync_start', 'Iniciando sincronización de producto', $context);
            
            $cache_key = "product_{$product_id}";
            $product_data = $this->cache_manager->get($cache_key);
            
            if (!$product_data) {
                $product = wc_get_product($product_id);
                if (!$product) {
                    throw new \Exception("Producto no encontrado");
                }

                $product_data = $this->prepare_product_data($product);
                $product_data = $this->sanitizer->sanitize($product_data, 'text');
                $this->cache_manager->set($cache_key, $product_data, $this->cache_ttl);
            }

            $product_dto = MapProduct::wc_to_verial($product_data);
            if (!$product_dto) {
                throw new \Exception("Error al mapear producto");
            }

            // REFACTORIZADO: Usar sistema de reintentos inteligente del RetryManager
            try {
                $result = $this->retry_manager->executeWithRetry(
                    function() use ($product_dto) {
                        $result = $this->api_connector->sync_product($product_dto);
                        if (!$result) {
                            throw new \Exception("Error en la sincronización");
                        }
                        return $result;
                    },
                    "product_sync_{$product_dto->sku}",
                    $context,
                    'sync_products' // Tipo de operación para política específica
                );
                
                $this->log_info('product_sync_success', 'Producto sincronizado exitosamente', $context);
                
            } catch (\Exception $e) {
                // El RetryManager ya maneja los reintentos y logging
                throw new \Exception("Producto no sincronizado tras reintentos: " . $e->getMessage());
            }

        } catch (\Exception $e) {
            $this->handle_error($e, 'product_sync_error', $context);
        }
    }

    public function on_order_created($order_id) {
        $context = [
            'order_id' => $order_id,
            'action' => 'order_created',
            'timestamp' => current_time('mysql')
        ];

        try {
            $this->log_info('order_sync_start', 'Iniciando sincronización de pedido', $context);
            
            $cache_key = "order_{$order_id}";
            $order_data = $this->cache_manager->get($cache_key);
            
            if (!$order_data) {
                $order = wc_get_order($order_id);
                if (!$order) {
                    throw new \Exception("Pedido no encontrado");
                }

                $order_data = $this->prepare_order_data($order);
                $order_data = $this->sanitizer->sanitize($order_data, 'text');
                $this->cache_manager->set($cache_key, $order_data, $this->cache_ttl);
            }

            $order_dto = MapOrder::wc_to_verial($order_data);
            if (!$order_dto) {
                throw new \Exception("Error al mapear pedido");
            }

            // REFACTORIZADO: Usar sistema de reintentos inteligente del RetryManager
            try {
                $result = $this->retry_manager->executeWithRetry(
                    function() use ($order_dto) {
                        $result = $this->api_connector->sync_order($order_dto);
                        if (!$result) {
                            throw new \Exception("Error en la sincronización");
                        }
                        return $result;
                    },
                    "order_sync_{$order_dto->order_ref}",
                    $context,
                    'sync_orders' // Tipo de operación para política específica (crítico)
                );
                
                $this->log_info('order_sync_success', 'Pedido sincronizado exitosamente', $context);
                
            } catch (\Exception $e) {
                // El RetryManager ya maneja los reintentos y logging
                throw new \Exception("Pedido no sincronizado tras reintentos: " . $e->getMessage());
            }

        } catch (\Exception $e) {
            $this->handle_error($e, 'order_sync_error', $context);
        }
    }

    public function on_customer_created($customer_id) {
        $context = [
            'customer_id' => $customer_id,
            'action' => 'customer_created',
            'timestamp' => current_time('mysql')
        ];

        try {
            $this->log_info('customer_sync_start', 'Iniciando sincronización de cliente', $context);
            
            $cache_key = "customer_{$customer_id}";
            $customer_data = $this->cache_manager->get($cache_key);
            
            if (!$customer_data) {
                $customer = new \WC_Customer($customer_id);
                if (!$customer) {
                    throw new \Exception("Cliente no encontrado");
                }

                $customer_data = $this->prepare_customer_data($customer);
                $customer_data = $this->sanitizer->sanitize($customer_data, 'text');
                $this->cache_manager->set($cache_key, $customer_data, $this->cache_ttl);
            }

            $customer_dto = MapCustomer::wc_to_verial($customer_data);
            if (!$customer_dto) {
                throw new \Exception("Error al mapear cliente");
            }

            // REFACTORIZADO: Usar sistema de reintentos inteligente del RetryManager
            try {
                $result = $this->retry_manager->executeWithRetry(
                    function() use ($customer_dto) {
                        $result = $this->api_connector->sync_customer($customer_dto);
                        if (!$result) {
                            throw new \Exception("Error en la sincronización");
                        }
                        return $result;
                    },
                    "customer_sync_{$customer_dto->email}",
                    $context,
                    'sync_customers' // Tipo de operación para política específica
                );
                
                $this->log_info('customer_sync_success', 'Cliente sincronizado exitosamente', $context);
                
            } catch (\Exception $e) {
                // El RetryManager ya maneja los reintentos y logging
                throw new \Exception("Cliente no sincronizado tras reintentos: " . $e->getMessage());
            }

        } catch (\Exception $e) {
            $this->handle_error($e, 'customer_sync_error', $context);
        }
    }

    private function prepare_product_data($product): array {
        $cache_key = "product_data_{$product->get_id()}";
        $cached_data = $this->cache_manager->get($cache_key);
        
        if ($cached_data) {
            return $cached_data;
        }

        $data = [
            'id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'name' => $product->get_name(),
            'description' => $product->get_description(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'categories' => wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']),
            'tags' => wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']),
            'attributes' => $product->get_attributes(),
            'images' => $this->get_product_images($product),
            'meta_data' => $product->get_meta_data()
        ];

        $this->cache_manager->set($cache_key, $data, $this->cache_ttl);
        return $data;
    }

    private function prepare_order_data($order): array {
        $cache_key = "order_data_{$order->get_id()}";
        $cached_data = $this->cache_manager->get($cache_key);
        
        if ($cached_data) {
            return $cached_data;
        }

        $data = [
            'id' => $order->get_id(),
            'status' => $order->get_status(),
            'currency' => $order->get_currency(),
            'date_created' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'total' => $order->get_total(),
            'customer_id' => $order->get_customer_id(),
            'billing' => [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country()
            ],
            'shipping' => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country()
            ],
            'line_items' => $this->get_order_line_items($order),
            'shipping_lines' => $this->get_order_shipping_lines($order),
            'fee_lines' => $this->get_order_fee_lines($order),
            'coupon_lines' => $this->get_order_coupon_lines($order)
        ];

        $this->cache_manager->set($cache_key, $data, $this->cache_ttl);
        return $data;
    }

    private function prepare_customer_data($customer): array {
        $cache_key = "customer_data_{$customer->get_id()}";
        $cached_data = $this->cache_manager->get($cache_key);
        
        if ($cached_data) {
            return $cached_data;
        }

        $data = [
            'id' => $customer->get_id(),
            'email' => $customer->get_email(),
            'first_name' => $customer->get_first_name(),
            'last_name' => $customer->get_last_name(),
            'username' => $customer->get_username(),
            'billing' => [
                'first_name' => $customer->get_billing_first_name(),
                'last_name' => $customer->get_billing_last_name(),
                'email' => $customer->get_billing_email(),
                'phone' => $customer->get_billing_phone(),
                'address_1' => $customer->get_billing_address_1(),
                'address_2' => $customer->get_billing_address_2(),
                'city' => $customer->get_billing_city(),
                'state' => $customer->get_billing_state(),
                'postcode' => $customer->get_billing_postcode(),
                'country' => $customer->get_billing_country()
            ],
            'shipping' => [
                'first_name' => $customer->get_shipping_first_name(),
                'last_name' => $customer->get_shipping_last_name(),
                'address_1' => $customer->get_shipping_address_1(),
                'address_2' => $customer->get_shipping_address_2(),
                'city' => $customer->get_shipping_city(),
                'state' => $customer->get_shipping_state(),
                'postcode' => $customer->get_shipping_postcode(),
                'country' => $customer->get_shipping_country()
            ],
            'meta_data' => $customer->get_meta_data()
        ];

        $this->cache_manager->set($cache_key, $data, $this->cache_ttl);
        return $data;
    }

    private function get_product_images($product): array {
        $cache_key = "product_images_{$product->get_id()}";
        $cached_images = $this->cache_manager->get($cache_key);
        
        if ($cached_images) {
            return $cached_images;
        }

        $images = [];
        $attachment_ids = $product->get_gallery_image_ids();
        array_unshift($attachment_ids, $product->get_image_id());

        foreach ($attachment_ids as $attachment_id) {
            $image_url = wp_get_attachment_url($attachment_id);
            if ($image_url) {
                $images[] = $image_url;
            }
        }

        $this->cache_manager->set($cache_key, $images, $this->cache_ttl);
        return $images;
    }

    private function get_order_line_items($order): array {
        $cache_key = "order_items_{$order->get_id()}";
        $cached_items = $this->cache_manager->get($cache_key);
        
        if ($cached_items) {
            return $cached_items;
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = [
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'quantity' => $item->get_quantity(),
                'total' => $item->get_total(),
                'subtotal' => $item->get_subtotal(),
                'name' => $item->get_name(),
                'sku' => $item->get_product()->get_sku()
            ];
        }

        $this->cache_manager->set($cache_key, $items, $this->cache_ttl);
        return $items;
    }

    private function get_order_shipping_lines($order): array {
        $cache_key = "order_shipping_{$order->get_id()}";
        $cached_shipping = $this->cache_manager->get($cache_key);
        
        if ($cached_shipping) {
            return $cached_shipping;
        }

        $shipping_lines = [];
        foreach ($order->get_shipping_methods() as $shipping) {
            $shipping_lines[] = [
                'method_id' => $shipping->get_method_id(),
                'method_title' => $shipping->get_method_title(),
                'total' => $shipping->get_total()
            ];
        }

        $this->cache_manager->set($cache_key, $shipping_lines, $this->cache_ttl);
        return $shipping_lines;
    }

    private function get_order_fee_lines($order): array {
        $cache_key = "order_fees_{$order->get_id()}";
        $cached_fees = $this->cache_manager->get($cache_key);
        
        if ($cached_fees) {
            return $cached_fees;
        }

        $fee_lines = [];
        foreach ($order->get_fees() as $fee) {
            $fee_lines[] = [
                'name' => $fee->get_name(),
                'total' => $fee->get_total(),
                'tax_total' => $fee->get_total_tax()
            ];
        }

        $this->cache_manager->set($cache_key, $fee_lines, $this->cache_ttl);
        return $fee_lines;
    }

    private function get_order_coupon_lines($order): array {
        $cache_key = "order_coupons_{$order->get_id()}";
        $cached_coupons = $this->cache_manager->get($cache_key);
        
        if ($cached_coupons) {
            return $cached_coupons;
        }

        $coupon_lines = [];
        foreach ($order->get_coupons() as $coupon) {
            $coupon_lines[] = [
                'code' => $coupon->get_code(),
                'discount' => $coupon->get_discount(),
                'discount_tax' => $coupon->get_discount_tax()
            ];
        }

        $this->cache_manager->set($cache_key, $coupon_lines, $this->cache_ttl);
        return $coupon_lines;
    }

    public function handle_new_user_registration($user_id) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        if ( ! $user_id ) {
            return;
        }

        $user_data = get_userdata( $user_id );
        if ( ! $user_data instanceof \WP_User ) {
            \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] No se pudieron obtener datos válidos para el usuario ID: %d', 'mi-integracion-api' ), $user_id ), 'mia-hooks' );
            return;
        }

        $sesion_verial = $this->api_connector->get_numero_sesion();
        if ( is_wp_error( $sesion_verial ) ) {
            \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] Error al obtener número de sesión de Verial: %s', 'mi-integracion-api' ), $sesion_verial->get_error_message() ), 'mia-hooks' );
            return;
        }
        if ( empty( $sesion_verial ) && $sesion_verial !== '0' ) {
            \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] Sincronización de nuevo usuario (%d) fallida: Número de sesión de Verial no configurado.', 'mi-integracion-api' ), $user_id ), 'mia-hooks' );
            return;
        }

        $cliente_payload_base = array();
        // Intentar usar el helper de mapeo centralizado
        if ( class_exists( '\MiIntegracionApi\Helpers\Map_Customer' ) && method_exists( '\MiIntegracionApi\Helpers\Map_Customer', 'wc_user_to_verial_payload' ) ) {
            $cliente_payload_base = \MiIntegracionApi\Helpers\Map_Customer::wc_user_to_verial_payload( $user_data, $this ); // Pasar $this si Map_Customer necesita llamar a get_verial_country_id_from_wc_code
        } else {
            // Fallback a mapeo manual si Map_Customer no está disponible
            \MiIntegracionApi\Helpers\Logger::warning( '[MI Hooks] Usando mapeo manual para NuevoClienteWS (user_register). Considerar implementar \MiIntegracionApi\Helpers\Map_Customer::wc_user_to_verial_payload.', 'mia-hooks' );
            $cliente_payload_base = array(
                'Tipo'           => 1,
                'NIF'            => get_meta_safe( $user_id, 'billing_vat_id', 'user', '' ) ?: get_meta_safe( $user_id, 'vat_number', 'user', '' ),
                'Nombre'         => $user_data->first_name ?: $user_data->display_name,
                'Apellido1'      => $user_data->last_name ?: '',
                'Apellido2'      => '', // WP no tiene segundo apellido por defecto
                'RazonSocial'    => '', // Vacío si es particular
                'RegFiscal'      => 1, // Asumir IVA general, ajustar si es necesario
                'ID_Pais'        => $this->get_verial_country_id_from_wc_code( get_meta_safe( $user_id, 'billing_country', 'user', '' ) ),
                'Provincia'      => get_meta_safe( $user_id, 'billing_state', 'user', '' ) ?: '',
                'Localidad'      => get_meta_safe( $user_id, 'billing_city', 'user', '' ) ?: '',
                'CPostal'        => get_meta_safe( $user_id, 'billing_postcode', 'user', '' ) ?: '',
                'Direccion'      => trim( get_meta_safe( $user_id, 'billing_address_1', 'user', '' ) . ' ' . get_meta_safe( $user_id, 'billing_address_2', 'user', '' ) ),
                'Telefono'       => get_meta_safe( $user_id, 'billing_phone', 'user', '' ) ?: '', // O Telefono1 según Verial
                'Email'          => $user_data->user_email,
                'WebUser'        => $user_data->user_login, // O user_email
                'EnviarAnuncios' => false, // Ajustar según la lógica de consentimiento
                // 'Sexo' => 0, // Si se recopila
            );
        }

        // Validaciones críticas antes de enviar
        if ( empty( $cliente_payload_base['Email'] ) ) {
            \MiIntegracionApi\Helpers\Logger::error( '[MI Hooks] Sincronización de nuevo usuario (' . $user_id . ') fallida: Email es requerido.', 'mia-hooks' );
            return;
        }
        // Añadir más validaciones aquí si son necesarias para Verial (ej. NIF para particulares)

        $cliente_payload = array_merge(
            array(
                'sesionwcf' => intval( $sesion_verial ),
                'Id'        => 0,
            ), // Id 0 para nuevo cliente
            $cliente_payload_base
        );

        // Llamada directa al conector para enviar los datos a Verial
        $verial_response = $this->api_connector->post( 'NuevoClienteWS', $cliente_payload );

        // Procesar respuesta de Verial
        if ( is_wp_error( $verial_response ) ) {
            \MiIntegracionApi\Helpers\Logger::error( '[MI Hooks] Error al sincronizar nuevo usuario ID ' . $user_id . ' con Verial (ApiConnector): ' . $verial_response->get_error_message(), 'mia-hooks' );
        } elseif ( isset( $verial_response['InfoError']['Codigo'] ) && intval( $verial_response['InfoError']['Codigo'] ) === \MiIntegracionApi\Endpoints\MI_Endpoint_NuevoClienteWS::VERIAL_ERROR_SUCCESS ) { // Usar constante de la clase endpoint
            $id_cliente_verial = $verial_response['Id'] ?? null;
            if ( $id_cliente_verial ) {
                update_user_meta( $user_id, '_verial_cliente_id', intval( $id_cliente_verial ) );
                \MiIntegracionApi\Helpers\Logger::info( sprintf( __( '[MI Hooks] Nuevo usuario ID %d sincronizado con Verial. ID Verial: %d', 'mi-integracion-api' ), $user_id, $id_cliente_verial ), 'mia-hooks' );
            } else {
                \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] Nuevo usuario ID %d sincronizado con Verial pero no se recibió ID de cliente de Verial.', 'mi-integracion-api' ), $user_id ), 'mia-hooks' );
            }
        } else {
            $error_msg = $verial_response['InfoError']['Descripcion'] ?? __( 'Error desconocido de Verial', 'mi-integracion-api' );
            \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] Fallo al sincronizar nuevo usuario ID %d con Verial: %s. Payload: %s', 'mi-integracion-api' ), $user_id, $error_msg, wp_json_encode( $cliente_payload ) ), 'mia-hooks' );
        }
    }

    /**
     * Maneja la creación de un nuevo pedido procesado.
     * Envía los datos del pedido a Verial.
     *
     * @param int   $order_id ID del pedido.
     * @param array $posted_data Datos del formulario de checkout (puede no ser necesario si se usa el objeto $order).
     */
    public function handle_new_order_processed( int $order_id, array $posted_data ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        if ( ! $order_id ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof \WC_Order ) {
            \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] No se pudo obtener el objeto WC_Order para el ID de pedido: %d', 'mi-integracion-api' ), $order_id ), 'mia-hooks' );
            return;
        }

        $sesion_verial = $this->api_connector->get_numero_sesion();
        if ( is_wp_error( $sesion_verial ) ) {
            \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] Error al obtener número de sesión de Verial: %s', 'mi-integracion-api' ), $sesion_verial->get_error_message() ), 'mia-hooks' );
            $order->add_order_note( __( 'Error: No se pudo sincronizar el pedido con Verial (error de sesión).', 'mi-integracion-api' ) );
            return;
        }
        if ( empty( $sesion_verial ) && $sesion_verial !== '0' ) {
            \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] Sincronización de pedido (%d) fallida: Número de sesión de Verial no configurado.', 'mi-integracion-api' ), $order_id ), 'mia-hooks' );
            $order->add_order_note( __( 'Error: No se pudo sincronizar el pedido con Verial (sesión no configurada).', 'mi-integracion-api' ) );
            wp_mail(
                get_option( 'admin_email' ),
                __( 'Error de sincronización con Verial', 'mi-integracion-api' ),
                /* translators: %1$d: ID del pedido */
                sprintf( __( 'El pedido #%1$d no se pudo sincronizar con Verial porque la sesión no está configurada.', 'mi-integracion-api' ), $order_id )
            );
            return;
        }

        $documento_payload_base = array();
        // Usar MapOrder como fuente única de verdad para mapeo de pedidos
        if ( class_exists( '\MiIntegracionApi\Helpers\MapOrder' ) && method_exists( '\MiIntegracionApi\Helpers\MapOrder', 'wc_order_to_verial_nuevo_doc_payload' ) ) {
            $documento_payload_base = \MiIntegracionApi\Helpers\MapOrder::wc_order_to_verial_nuevo_doc_payload( $order, array(), $this );
        } else {
            // Error crítico - MapOrder debe estar disponible
            \MiIntegracionApi\Helpers\Logger::error( '[MI Hooks] CRÍTICO: MapOrder no disponible para pedido ' . $order_id . '. Verifique autoloader.', 'mia-hooks' );
            $order->add_order_note( __( 'Error crítico: No se pudo mapear el pedido a Verial (MapOrder no disponible).', 'mi-integracion-api' ) );
            wp_mail(
                get_option( 'admin_email' ),
                __( 'Error crítico en sincronización con Verial', 'mi-integracion-api' ),
                sprintf( __( 'MapOrder no está disponible para el pedido #%1$d. Verifique la instalación del plugin.', 'mi-integracion-api' ), $order_id )
            );
            return;
        }


        // Validar que después del mapeo (centralizado o fallback) haya contenido
        if ( empty( $documento_payload_base['Contenido'] ) ) {
            \MiIntegracionApi\Helpers\Logger::error( '[MI Hooks] Pedido ID ' . $order_id . ' procesado sin líneas de contenido válidas para Verial (después del mapeo).', 'mia-hooks' );
            $order->add_order_note( __( 'Error: Pedido sin líneas válidas para sincronizar con Verial (después del mapeo).', 'mi-integracion-api' ) );
            return;
        }

        $documento_payload_final = array_merge(
            array( 'sesionwcf' => intval( $sesion_verial ) ),
            $documento_payload_base
        );

        // Llamada directa al conector
        $verial_response = $this->api_connector->nuevo_doc_cliente( $documento_payload_final );
        if ( is_wp_error( $verial_response ) ) {
            $order->add_order_note(/* translators: %s: mensaje de error */
                sprintf( __( 'Error al sincronizar pedido con Verial: %s', 'mi-integracion-api' ), $verial_response->get_error_message() )
            );
            wp_mail(
                get_option( 'admin_email' ),
                __( 'Error de sincronización con Verial', 'mi-integracion-api' ),
                /* translators: %1$d: ID del pedido, %2$s: mensaje de error */
                sprintf( __( 'El pedido #%1$d no se pudo sincronizar con Verial. Mensaje: %2$s', 'mi-integracion-api' ), $order_id, $verial_response->get_error_message() )
            );
            // Usar métodos compatibles con HPOS para actualizar metadatos
            MiIntegracionApi\WooCommerce\HposCompatibility::manage_order_meta( 'update', $order_id, '_verial_sync_status', 'incompleto' );
            return;
        } elseif ( isset( $verial_response['InfoError']['Codigo'] ) && intval( $verial_response['InfoError']['Codigo'] ) === \MiIntegracionApi\Endpoints\MI_Endpoint_NuevoDocClienteWS::VERIAL_ERROR_SUCCESS ) { // Usar constante de la clase endpoint
            $id_documento_verial     = $verial_response['Id'] ?? null;
            $numero_documento_verial = $verial_response['Numero'] ?? null;
            if ( $id_documento_verial ) {
                MiIntegracionApi\WooCommerce\HposCompatibility::manage_order_meta( 'update', $order_id, '_verial_documento_id', intval( $id_documento_verial ) );
                if ( $numero_documento_verial ) {
                    MiIntegracionApi\WooCommerce\HposCompatibility::manage_order_meta( 'update', $order_id, '_verial_documento_numero', sanitize_text_field( $numero_documento_verial ) );
                }
                $order->add_order_note(/* translators: %1$s: ID documento Verial, %2$s: número Verial */
                    sprintf( __( 'Pedido sincronizado con Verial. ID Documento Verial: %1$s, Número Verial: %2$s', 'mi-integracion-api' ), $id_documento_verial, ( $numero_documento_verial ?? 'N/A' ) )
                );
                \MiIntegracionApi\Helpers\Logger::info( '[MI Hooks] Pedido ID ' . $order_id . ' sincronizado con Verial. ID Verial: ' . $id_documento_verial, 'mia-hooks' );
            } else {
                \MiIntegracionApi\Helpers\Logger::error( '[MI Hooks] Pedido ID ' . $order_id . ' sincronizado con Verial pero no se recibió ID de documento de Verial.', 'mia-hooks' );
            }
        } else {
            $error_msg = $verial_response['InfoError']['Descripcion'] ?? __( 'Error desconocido de Verial', 'mi-integracion-api' );
            \MiIntegracionApi\Helpers\Logger::error( '[MI Hooks] Fallo al sincronizar pedido ID ' . $order_id . ' con Verial: ' . $error_msg . '. Payload Keys: ' . implode( ', ', array_keys( $documento_payload_final ) ), 'mia-hooks' );
            $order->add_order_note(/* translators: %s: mensaje de error */
                sprintf( __( 'Error al sincronizar pedido con Verial: %s', 'mi-integracion-api' ), $error_msg )
            );
            wp_mail(
                get_option( 'admin_email' ),
                __( 'Error de sincronización con Verial', 'mi-integracion-api' ),
                /* translators: %1$d: ID del pedido, %2$s: mensaje de error */
                sprintf( __( 'El pedido #%1$d no se pudo sincronizar con Verial. Mensaje: %2$s', 'mi-integracion-api' ), $order_id, $error_msg )
            );
            // Usar métodos compatibles con HPOS
            MiIntegracionApi\WooCommerce\HposCompatibility::manage_order_meta( 'update', $order_id, '_verial_sync_status', 'incompleto' );
        }
    }

    /**
     * Obtiene el ID de país de Verial a partir del código ISO de país de WooCommerce.
     *
     * @param string|null $billing_country_code Código ISO del país (ej. 'ES').
     * @return int ID del país en Verial (ej. 1 para España).
     */
    /**
     * Método centralizado para obtener el ID de país de Verial a partir del código ISO (WC)
     *
     * - Usa caché de GetPaisesWS
     * - Permite mapeo manual por opciones
     * - Fallback a España (ID 1)
     *
     * @param string|null $billing_country_code Código ISO del país (ej. 'ES').
     * @return int ID del país en Verial (ej. 1 para España).
     */
    public function get_verial_country_id_from_wc_code( ?string $billing_country_code ): int {
        if ( empty( $billing_country_code ) ) {
            return 1; // Default a España (ID 1 en Verial, ajustar si es diferente)
        }

        // Priorizar mapeo configurado por el administrador
        $country_mapping_options = get_option( 'mia_country_mapping_options', array() ); // Suponiendo una opción para mapeos de país
        $country_code_upper      = strtoupper( $billing_country_code );

        if ( ! empty( $country_mapping_options[ $country_code_upper ] ) ) {
            return intval( $country_mapping_options[ $country_code_upper ] );
        }

        // Fallback: intentar buscar por código ISO2 en la lista de países de Verial (si está cacheada)
        $api_connector           = new \MiIntegracionApi\Core\ApiConnector();
        $num_sesion              = $api_connector->get_numero_sesion();
        $paises_verial_cache_key = defined( 'MiIntegracionApi\\Endpoints\\MI_Endpoint_GetPaisesWS::CACHE_KEY_PREFIX' ) ? \MiIntegracionApi\Endpoints\MI_Endpoint_GetPaisesWS::CACHE_KEY_PREFIX . md5( $num_sesion ) : 'mia_paises_cached_list';
        $paises_verial           = get_transient( $paises_verial_cache_key );

        if ( $paises_verial && is_array( $paises_verial ) ) {
            foreach ( $paises_verial as $pais_verial ) {
                if ( isset( $pais_verial['iso2'] ) && strtoupper( $pais_verial['iso2'] ) === $country_code_upper && isset( $pais_verial['id'] ) ) {
                    return intval( $pais_verial['id'] );
                }
            }
        }

        // Si no se encuentra, loguear y devolver un default
        $this->logger->warning( "[MI Hooks] No se encontró mapeo de país para el código WC '{$billing_country_code}'. Usando ID por defecto 1 (España). Considera configurar el mapeo de países.", ['context' => 'mia-hooks'] );
        return 1; // Default si no se encuentra mapeo
    }

    /**
     * Obtiene el ID de método de pago de Verial a partir del slug de WooCommerce.
     * Lee el mapeo desde las opciones del plugin.
     *
     * @param string $wc_payment_method_slug Slug del método de pago de WooCommerce.
     * @return int ID del método de pago en Verial, o 0 si no hay mapeo.
     */
    private function get_verial_payment_method_id( string $wc_payment_method_slug ): int {
        $mapping = get_option( 'mia_payment_method_mapping', array() ); // Usar el prefijo 'mia_' consistente
        if ( isset( $mapping[ $wc_payment_method_slug ] ) && $mapping[ $wc_payment_method_slug ] !== '' ) { // Asegurar que no sea una cadena vacía
            return intval( $mapping[ $wc_payment_method_slug ] );
        }
        return 0; // Devuelve 0 si no hay mapeo o si el mapeo es explícitamente vacío
    }

    /**
     * Obtiene la tasa de impuesto de un ítem de pedido.
     *
     * @param \WC_Order_Item_Product $item Ítem del pedido.
     * @return float Tasa total de impuesto (ej: 21.00 para 21%).
     */
    public function get_item_tax_rate( \WC_Order_Item_Product $item ): float {
        $taxes          = $item->get_taxes(); // Esto devuelve un array de arrays de impuestos, ej. ['total' => [tax_rate_id => amount]]
        $total_tax_rate = 0.0;

        if ( ! empty( $taxes['total'] ) && is_array( $taxes['total'] ) ) {
            foreach ( array_keys( $taxes['total'] ) as $tax_rate_id ) {
                // WC_Tax::get_rate_percent_value() devuelve la tasa como un string ej. "21.0000"
                // Es mejor usar WC_Tax::get_rate_percent() que devuelve un float.
                $rate_details = \WC_Tax::get_rate( $tax_rate_id ); // Obtener detalles de la tasa
                if ( $rate_details && isset( $rate_details['tax_rate'] ) ) {
                    $total_tax_rate += floatval( $rate_details['tax_rate'] );
                }
            }
        }
        return round( $total_tax_rate, 4 ); // Devolver con 4 decimales como lo hace WC internamente
    }

    private function handle_error(\Exception $e, string $error_code, array $context) {
        $this->log_error($error_code, $e->getMessage(), $context);
        $this->error_handler->handle($e, $error_code, $context);
        
        // Notificar al administrador si es un error crítico
        if ($this->is_critical_error($error_code)) {
            $this->notify_admin($error_code, $e->getMessage(), $context);
        }
    }

    private function is_critical_error(string $error_code): bool {
        $critical_errors = [
            'api_connection_error',
            'database_error',
            'security_violation'
        ];
        return in_array($error_code, $critical_errors);
    }

    private function notify_admin(string $error_code, string $message, array $context) {
        $admin_email = get_option('admin_email');
        $subject = sprintf(
            '[%s] Error crítico en la sincronización',
            get_bloginfo('name')
        );
        
        $body = sprintf(
            "Se ha producido un error crítico en la sincronización:\n\n" .
            "Código: %s\n" .
            "Mensaje: %s\n" .
            "Contexto: %s\n\n" .
            "Por favor, revise los logs para más detalles.",
            $error_code,
            $message,
            json_encode($context, JSON_PRETTY_PRINT)
        );
        
        wp_mail($admin_email, $subject, $body);
    }

    private function log_info(string $code, string $message, array $context = []) {
        $this->logger->info($message, array_merge($context, [
            'code' => $code,
            'level' => 'info'
        ]));
    }

    private function log_warning(string $code, string $message, array $context = []) {
        $this->logger->warning($message, array_merge($context, [
            'code' => $code,
            'level' => 'warning'
        ]));
    }

    private function log_error(string $code, string $message, array $context = []) {
        $this->logger->error($message, array_merge($context, [
            'code' => $code,
            'level' => 'error'
        ]));
    }

    /**
     * Hook ejecutado antes de cualquier sincronización
     * 
     * @param string $type Tipo de sincronización (product|order|customer)
     * @param int $id ID del elemento a sincronizar
     */
    public function before_sync(string $type, int $id) {
        do_action("mi_integracion_api_before_{$type}_sync", $id);
    }

    /**
     * Hook ejecutado después de cualquier sincronización
     * 
     * @param string $type Tipo de sincronización (product|order|customer)
     * @param int $id ID del elemento sincronizado
     * @param bool $success Resultado de la sincronización
     * @param array $result Datos adicionales del resultado
     */
    public function after_sync(string $type, int $id, bool $success, array $result = []) {
        do_action("mi_integracion_api_after_{$type}_sync", $id, $success, $result);
    }

    /**
     * Filtra los datos del producto antes de la sincronización
     * 
     * @param array $product_data Datos del producto
     * @param int $product_id ID del producto
     * @return array
     */
    public function filter_product_data(array $product_data, int $product_id): array {
        return apply_filters('mi_integracion_api_product_data', $product_data, $product_id);
    }

    /**
     * Filtra los datos del pedido antes de la sincronización
     * 
     * @param array $order_data Datos del pedido
     * @param int $order_id ID del pedido
     * @return array
     */
    public function filter_order_data(array $order_data, int $order_id): array {
        return apply_filters('mi_integracion_api_order_data', $order_data, $order_id);
    }

    /**
     * Filtra los datos del cliente antes de la sincronización
     * 
     * @param array $customer_data Datos del cliente
     * @param int $customer_id ID del cliente
     * @return array
     */
    public function filter_customer_data(array $customer_data, int $customer_id): array {
        return apply_filters('mi_integracion_api_customer_data', $customer_data, $customer_id);
    }

    /**
	 * Asegurar que el límite de productos relacionados sea siempre un entero
	 * Esto corrige el error "Invalid limit type passed to wc_get_related_products".
	 *
	 * @since 1.3.1
	 * @param array $args Los argumentos para los productos relacionados.
	 * @return array Los argumentos filtrados.
	 */
	public static function ensure_related_products_limit_is_integer($args) {
		if (isset($args['posts_per_page']) && !is_int($args['posts_per_page'])) {
			$args['posts_per_page'] = intval($args['posts_per_page']);
		}
		
		return $args;
	}

	/**
	 * Registrar filtros para WooCommerce
	 *
	 * @since 1.3.1
	 */
	public static function register_woocommerce_filters() {
		add_filter('woocommerce_related_products_args', [self::class, 'ensure_related_products_limit_is_integer'], 99);
	}
}

// Registrar los handlers de Action Scheduler fuera de la clase:
if ( function_exists( 'add_action' ) ) {
    add_action(
        'mi_integracion_api_sync_user_to_verial',
        function ( $user_id ) {
            $hooks = new \MiIntegracionApi\WooCommerce\WooCommerceHooks();
            $hooks->on_customer_created($user_id);
        }
    );
    add_action(
        'mi_integracion_api_sync_order_to_verial',
        function ( $order_id ) {
            $hooks = new \MiIntegracionApi\WooCommerce\WooCommerceHooks();
            $hooks->on_order_created($order_id);
        }
    );
}

// No se detecta uso de Logger::log, solo error_log estándar.
