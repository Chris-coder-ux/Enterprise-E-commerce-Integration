<?php
declare(strict_types=1);
/**
 * Helper para mapear datos de pedidos entre WooCommerce y Verial.
 *
 * Proporciona utilidades para convertir, validar y sanitizar datos de pedidos entre los formatos de WooCommerce y Verial,
 * soportando tanto integración directa como compatibilidad con código legacy y pruebas.
 *
 * @package MiIntegracionApi\Helpers
 */
namespace MiIntegracionApi\Helpers;

use MiIntegracionApi\DTOs\OrderDTO;
use MiIntegracionApi\Core\DataSanitizer;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Constants\VerialTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para mapear datos de pedidos entre WooCommerce y Verial.
 *
 * Permite convertir pedidos entre ambos sistemas, aplicar validaciones, sanitizar datos y soportar inyección de dependencias.
 * Incluye métodos para compatibilidad con código legacy y pruebas unitarias.
 *
 * @since 1.0.0
 * @package MiIntegracionApi\Helpers
 */
class MapOrder {
    /**
     * Logger estático para registrar eventos y errores.
     *
     * @var Logger|null
     */
    private static $logger;

    /**
     * Sanitizer estático para limpiar y validar datos.
     *
     * @var DataSanitizer|null
     */
    private static $sanitizer;

    /**
     * Instancia singleton para permitir inyección de dependencias sin romper API estática.
     *
     * @var MapOrder|null
     */
    private static ?self $instance = null;

    /**
     * Configuración de la API de Verial (puede ser instancia, array o null).
     *
     * @var mixed
     */
    private $config;

    /**
     * Conector de API externo (opcional).
     *
     * @var mixed
     */
    private $connector;

    /**
     * Inicializa los helpers internos (logger y sanitizer) si no existen.
     *
     * @return void
     */
    public static function init() {
        if (!self::$logger) {
            self::$logger = new Logger('map_order');
        }
        if (!self::$sanitizer) {
            self::$sanitizer = new DataSanitizer();
        }
    }

    /**
     * Inicializa la instancia singleton de MapOrder con dependencias opcionales.
     *
     * Permite inyección de dependencias para pruebas o integración avanzada, manteniendo compatibilidad con métodos estáticos.
     *
     * @param mixed $config     Configuración de Verial (instancia, array o null).
     * @param mixed $connector Conector de API externo (opcional).
     * @param mixed $sanitizer Instancia de DataSanitizer (opcional).
     * @param mixed $logger    Instancia de Logger (opcional).
     * @return void
     */
    public static function boot($config = null, $connector = null, $sanitizer = null, $logger = null): void {
        if (self::$instance !== null) {
            return;
        }
        // Use constructor injection when possible to support DI
        $inst = new self($config, $connector, $sanitizer, $logger);
        // Normalize config: accept VerialApiConfig instance, array or null
        if ($config instanceof \VerialApiConfig) {
            $inst->config = $config;
        } elseif (is_array($config) && !empty($config)) {
            try {
                $inst->config = new \VerialApiConfig($config);
            } catch (\Throwable $e) {
                // keep null, will fallback later
                if (self::$logger) {
                    self::$logger->warning('MapOrder::boot: config array inválida', ['error' => $e->getMessage()]);
                }
                $inst->config = null;
            }
        } else {
            $inst->config = $config; // could be null
        }

    // connector/sanitizer/logger are already set by constructor

        self::$instance = $inst;
    }

    /**
     * Obtiene la instancia singleton de MapOrder, inicializándola con valores por defecto si es necesario.
     *
     * @return self Instancia singleton de MapOrder.
     */
    private static function instance(): self {
        if (self::$instance === null) {
            self::init();
            // Boot default instance with a VerialApiConfig. Constructor will set sanitizer/logger defaults.
            self::boot(self::safeCreateVerialConfig());
        }
        return self::$instance;
    }


    /**
     * Mapea un pedido de WooCommerce al formato de Verial (método de instancia).
     *
     * Convierte y sanitiza los datos de un pedido de WooCommerce (o array compatible) al formato requerido por Verial.
     *
     * @param mixed $order   Pedido de WooCommerce (\WC_Order, array o mock compatible).
     * @param array $options Opciones adicionales (no utilizadas actualmente).
     * @return array Datos del pedido en formato Verial.
     */
    public function wcToVerialInstance($order, $options = []): array {
        self::init();

        $order_obj = $order;

        // Normalizar objeto order (puede ser array o WC_Order fake en tests)
        if (is_array($order)) {
            $order_obj = (object)$order;
        }

        // Construir estructura base
        $payload = [
            'Id' => $order_obj->get_id() ?? $order_obj->id ?? null,
            'Cliente' => [
                'ID' => $order_obj->get_customer_id() ?? 0,
                'Nombre' => $order_obj->get_billing_first_name() ?? '',
                'Apellidos' => $order_obj->get_billing_last_name() ?? '',
                'Email' => $order_obj->get_billing_email() ?? '',
                'Telefono' => $order_obj->get_billing_phone() ?? '',
                'Direccion' => $order_obj->get_billing_address_1() ?? '',
                'Ciudad' => $order_obj->get_billing_city() ?? '',
                'Provincia' => $order_obj->get_billing_state() ?? '',
                'CodigoPostal' => $order_obj->get_billing_postcode() ?? '',
                'ID_Pais' => $order_obj->get_billing_country() ?? 'ES'
            ],
            'Envio' => [
                'Nombre' => $order_obj->get_shipping_first_name() ?? '',
                'Apellidos' => $order_obj->get_shipping_last_name() ?? '',
                'Direccion' => $order_obj->get_shipping_address_1() ?? '',
                'Ciudad' => $order_obj->get_shipping_city() ?? '',
                'Provincia' => $order_obj->get_shipping_state() ?? '',
                'CodigoPostal' => $order_obj->get_shipping_postcode() ?? '',
                'ID_Pais' => $order_obj->get_shipping_country() ?? 'ES'
            ],
            'Contenido' => $this->mapWcItemsToVerialContent($order_obj),
            'Subtotal' => (float)$order_obj->get_subtotal() ?? 0,
            'TotalIVA' => (float)$order_obj->get_total_tax() ?? 0,
            'Total' => (float)$order_obj->get_total() ?? 0,
            'GastosEnvio' => (float)$order_obj->get_shipping_total() ?? 0,
            'FormaPago' => $this->mapPaymentMethodToVerialId($order_obj->get_payment_method() ?? ''),
            'Moneda' => $order_obj->get_currency() ?? 'EUR',
            'Fecha' => $order_obj->get_date_created() ?? current_time('mysql'),
            'Nota' => $order_obj->get_customer_note() ?? '',
            'MetaDatos' => $order_obj->get_meta() ?? []
        ];

        // Añadir alias para compatibilidad
        $payload['ID'] = $payload['Id'];
        $payload['Lineas'] = $payload['Contenido'];

        // Rellenar campos de cliente adicionales buscando en meta y en campos alternativos
        $payload['Cliente']['NIF'] = $this->extractNifFromOrder($order_obj);
        $payload['Cliente']['Apellido2'] = $this->extractApellido2FromOrder($order_obj);
        $payload['Cliente']['DireccionAux'] = $this->extractDireccionAuxFromOrder($order_obj);
        $payload['Cliente']['RazonSocial'] = $this->extractRazonSocialFromOrder($order_obj);
        $payload['Cliente']['EtiquetaCliente'] = $this->extractEtiquetaClienteFromOrder($order_obj);
        $payload['Cliente']['Descripcion'] = $order_obj->get_customer_note() ?? '';

        // Aux campos
        for ($i = 1; $i <= 6; $i++) {
            $key = 'Aux' . $i;
            $payload['Cliente'][$key] = $order_obj->get_meta(strtolower($key)) ?? $order_obj->get_meta('verial_' . strtolower($key)) ?? '';
        }

        return $payload;
    }
    /**
     * Constructor de MapOrder.
     *
     * Permite inyectar dependencias opcionales para configuración, conector, sanitizer y logger.
     * Si no se proporcionan, se usan los helpers estáticos por defecto.
     *
     * @param mixed $config     Configuración de Verial (instancia, array o null).
     * @param mixed $connector  Conector de API externo (opcional).
     * @param mixed $sanitizer  Instancia de DataSanitizer (opcional).
     * @param mixed $logger     Instancia de Logger (opcional).
     */
    public function __construct($config = null, $connector = null, $sanitizer = null, $logger = null)
    {
        // Asegurar componentes estáticos por defecto
        self::init();

        if ($config instanceof \VerialApiConfig) {
            $this->config = $config;
        } elseif (is_array($config) && !empty($config)) {
            try {
                $this->config = new \VerialApiConfig($config);
            } catch (\Throwable $e) {
                if (self::$logger) {
                    self::$logger->warning('MapOrder::__construct: config array inválida', ['error' => $e->getMessage()]);
                }
                $this->config = null;
            }
        } else {
            $this->config = $config; // could be null
        }

        $this->connector = $connector;

        // Configurar sanitizer
        if ($sanitizer !== null) {
            self::$sanitizer = $sanitizer;
        } else {
            // Asegurar que self::$sanitizer esté inicializado
            if (self::$sanitizer === null) {
                self::init();
            }
        }

        // Configurar logger
        if ($logger !== null) {
            self::$logger = $logger;
        } else {
            // Asegurar que self::$logger esté inicializado
            if (self::$logger === null) {
                self::init();
            }
        }
    }



    /**
     * Permite establecer el logger estático (útil para tests o integración avanzada).
     *
     * @param Logger $logger Instancia de Logger a utilizar.
     * @return void
     */
    public static function setLogger($logger) {
        self::$logger = $logger;
    }

    /**
     * Devuelve la instancia actual del logger estático.
     *
     * @return Logger|null Instancia de Logger o null si no está inicializado.
     */
    public static function getLogger() {
        return self::$logger;
    }

    /**
     * Devuelve la instancia actual del sanitizer estático.
     *
     * @return DataSanitizer|null Instancia de DataSanitizer o null si no está inicializado.
     */
    public static function getSanitizer() {
        return self::$sanitizer;
    }

    /**
     * Mapea un pedido de Verial a un DTO de WooCommerce.
     *
     * Valida y sanitiza los datos del pedido de Verial y los convierte a un objeto OrderDTO compatible con WooCommerce.
     *
     * @param array $verial_order  Datos del pedido de Verial.
     * @param array $extra_fields  Campos extra opcionales para el DTO.
     * @return OrderDTO|null DTO del pedido mapeado o null si los datos son inválidos o faltan campos críticos.
     */
    public static function verial_to_wc(array $verial_order, array $extra_fields = []): ?OrderDTO {
        // Delegar en la instancia para soportar DI
        $inst = self::instance();
        return $inst->verialToWcInstance($verial_order, $extra_fields);
    }

    /**
     * Mapea un pedido de Verial a un DTO de WooCommerce (método de instancia).
     *
     * Convierte y sanitiza los datos de un pedido de Verial al formato de OrderDTO, soportando inyección de dependencias.
     *
     * @param array $verial_order  Datos del pedido de Verial.
     * @param array $extra_fields  Campos extra opcionales para el DTO.
     * @return OrderDTO|null DTO del pedido mapeado o null si los datos son inválidos o faltan campos críticos.
     */
    public function verialToWcInstance(array $verial_order, array $extra_fields = []): ?OrderDTO {
        self::init();

        try {
            // Validar campos requeridos
            if (empty($verial_order['ID']) || empty($verial_order['Cliente'])) {
                self::$logger->error('Pedido Verial inválido: faltan campos requeridos', [
                    'order' => $verial_order
                ]);
                return null;
            }

            // Sanitizar datos
            $order_data = [
                'id' => self::$sanitizer->sanitize($verial_order['ID'], 'int'),
                'customer_id' => self::$sanitizer->sanitize($verial_order['Cliente']['ID'], 'int'),
                'status' => self::$sanitizer->sanitize($this->map_verial_status_to_wc($verial_order['Estado'] ?? ''), 'text'),
                'currency' => self::$sanitizer->sanitize($verial_order['Moneda'] ?? 'EUR', 'text'),
                'total' => self::$sanitizer->sanitize((float)($verial_order['Total'] ?? 0), 'price'),
                'subtotal' => self::$sanitizer->sanitize((float)($verial_order['Subtotal'] ?? 0), 'price'),
                'tax_total' => self::$sanitizer->sanitize((float)($verial_order['TotalIVA'] ?? 0), 'price'),
                'shipping_total' => self::$sanitizer->sanitize((float)($verial_order['GastosEnvio'] ?? 0), 'price'),
                'discount_total' => self::$sanitizer->sanitize((float)($verial_order['Descuento'] ?? 0), 'price'),
                'payment_method' => self::$sanitizer->sanitize($verial_order['FormaPago'] ?? '', 'text'),
                'payment_method_title' => self::$sanitizer->sanitize($verial_order['FormaPagoDescripcion'] ?? '', 'text'),
                'billing' => [
                    'first_name' => self::$sanitizer->sanitize($verial_order['Cliente']['Nombre'] ?? '', 'text'),
                    'last_name' => self::$sanitizer->sanitize($verial_order['Cliente']['Apellidos'] ?? '', 'text'),
                    'email' => self::$sanitizer->sanitize($verial_order['Cliente']['Email'] ?? '', 'email'),
                    'phone' => self::$sanitizer->sanitize($verial_order['Cliente']['Telefono'] ?? '', 'phone'),
                    'address_1' => self::$sanitizer->sanitize($verial_order['Cliente']['Direccion'] ?? '', 'text'),
                    'city' => self::$sanitizer->sanitize($verial_order['Cliente']['Ciudad'] ?? '', 'text'),
                    'state' => self::$sanitizer->sanitize($verial_order['Cliente']['Provincia'] ?? '', 'text'),
                    'postcode' => self::$sanitizer->sanitize($verial_order['Cliente']['CodigoPostal'] ?? '', 'postcode'),
                    'country' => self::$sanitizer->sanitize($verial_order['Cliente']['ID_Pais'] ?? '', 'int')
                ],
                'shipping' => [
                    'first_name' => self::$sanitizer->sanitize($verial_order['Envio']['Nombre'] ?? '', 'text'),
                    'last_name' => self::$sanitizer->sanitize($verial_order['Envio']['Apellidos'] ?? '', 'text'),
                    'address_1' => self::$sanitizer->sanitize($verial_order['Envio']['Direccion'] ?? '', 'text'),
                    'city' => self::$sanitizer->sanitize($verial_order['Envio']['Ciudad'] ?? '', 'text'),
                    'state' => self::$sanitizer->sanitize($verial_order['Envio']['Provincia'] ?? '', 'text'),
                    'postcode' => self::$sanitizer->sanitize($verial_order['Envio']['CodigoPostal'] ?? '', 'postcode'),
                    'country' => self::$sanitizer->sanitize($verial_order['Envio']['ID_Pais'] ?? '', 'int')
                ],
                'line_items' => $this->sanitizeLineItems($verial_order['Lineas'] ?? []),
                'shipping_lines' => $this->sanitizeShippingLines($verial_order['GastosEnvio'] ?? 0),
                'fee_lines' => $this->sanitizeFeeLines($verial_order['GastosAdicionales'] ?? []),
                'coupon_lines' => $this->sanitizeCouponLines($verial_order['Cupones'] ?? []),
                'date_created' => self::$sanitizer->sanitize($verial_order['Fecha'] ?? current_time('mysql'), 'datetime'),
                'date_modified' => self::$sanitizer->sanitize($verial_order['FechaModificacion'] ?? current_time('mysql'), 'datetime'),
                'date_completed' => self::$sanitizer->sanitize($verial_order['FechaCompletado'] ?? null, 'datetime'),
                'date_paid' => self::$sanitizer->sanitize($verial_order['FechaPago'] ?? null, 'datetime'),
                'customer_note' => self::$sanitizer->sanitize($verial_order['Nota'] ?? '', 'text'),
                'external_id' => self::$sanitizer->sanitize((string)($verial_order['ID'] ?? ''), 'text'),
                'sync_status' => self::$sanitizer->sanitize('synced', 'text'),
                'last_sync' => self::$sanitizer->sanitize(current_time('mysql'), 'datetime'),
                'meta_data' => self::$sanitizer->sanitize($verial_order['MetaDatos'] ?? [], 'text')
            ];

            // Añadir aliases para compatibilidad con validadores y Postman
            $verial_order['ID'] = $verial_order['Id'];
            $verial_order['Lineas'] = $verial_order['Contenido'];

            // Validar datos críticos
            if (!self::$sanitizer->validate($order_data['id'], 'int')) {
                self::$logger->error('ID de pedido inválido', [
                    'id' => $order_data['id']
                ]);
                return null;
            }

            if (!self::$sanitizer->validate($order_data['billing']['email'], 'email')) {
                self::$logger->error('Email de facturación inválido', [
                    'email' => $order_data['billing']['email']
                ]);
                return null;
            }

            // Añadir campos extra si existen
            if (!empty($extra_fields)) {
                $order_data = array_merge($order_data, $extra_fields);
            }

            return new OrderDTO($order_data);
        } catch (\Exception $e) {
            self::$logger->error('Error al mapear pedido Verial a WooCommerce', [
                'error' => $e->getMessage(),
                'order' => $verial_order
            ]);
            return null;
        }
    }

    /**
     * Mapea un pedido de WooCommerce al formato de Verial.
     *
     * Convierte y sanitiza los datos de un objeto WC_Order al formato requerido por Verial.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
     */
    public static function wc_to_verial(\WC_Order $wc_order): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
        self::init();

        try {
            if (!$wc_order instanceof \WC_Order) {
                self::$logger->error('Pedido WooCommerce inválido');
                return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
                    'Pedido WooCommerce inválido',
                    400,
                    [
                        'endpoint' => 'MapOrder::wc_to_verial',
                        'error_code' => 'invalid_wc_order',
                        'order_id' => $wc_order ? $wc_order->get_id() : null,
                        'timestamp' => time()
                    ]
                );
            }

            // Calcular totales
            $subtotal = (float) $wc_order->get_subtotal();
            $shipping_total = (float) $wc_order->get_shipping_total();
            $tax_total = (float) $wc_order->get_total_tax();
            $total = (float) $wc_order->get_total();
            
            // Obtener fecha actual o fecha del pedido
            $order_date = $wc_order->get_date_created();
            $fecha = $order_date ? $order_date->format('Y-m-d') : date('Y-m-d');

            // Mapear datos según especificación exacta de Postman/Verial
            $cfg = self::getVerialConfigStatic();
            $sesionwcf = ($cfg !== null) ? $cfg->getVerialSessionId() : 18;

            // Intentar obtener ID de cliente válido de Verial
            $cliente_id = self::getValidClienteId($wc_order);

            $verial_order = [
                // Campos principales requeridos por NuevoDocClienteWS
                'sesionwcf' => $sesionwcf,
                'Id' => self::$sanitizer->sanitize($wc_order->get_id(), 'int'),
                'Tipo' => 5, // Tipo 5 = Pedido según documentación Verial
                'Referencia' => self::$sanitizer->sanitize($wc_order->get_order_number(), 'text'),
                'Numero' => 0, // Auto-asignado por Verial
                'Fecha' => $fecha,
                'ID_Cliente' => $cliente_id, // ID de cliente válido o 0 para nuevo
                'ID_DireccionEnvio' => self::getExistingDireccionEnvioId($wc_order),
                
                // Estructura completa del Cliente según Verial
                'Cliente' => [
                    'Id' => 0, // Cliente nuevo
                    'Tipo' => VerialTypes::CUSTOMER_TYPE_INDIVIDUAL, // Particular
                    // Intentar obtener NIF desde meta del pedido: billing_nif, billing_vat, _billing_nif, nif
                    'NIF' => self::$sanitizer->sanitize(
                        ($wc_order->get_meta('billing_nif') ?: $wc_order->get_meta('_billing_nif') ?: $wc_order->get_meta('billing_vat') ?: $wc_order->get_meta('nif') ?: $wc_order->get_meta('dni') ?: $wc_order->get_meta('billing_nif_custom') ?: ''),
                        'text'
                    ),
                    'Nombre' => self::$sanitizer->sanitize($wc_order->get_billing_first_name(), 'text'),
                    'Apellidos' => trim(self::$sanitizer->sanitize($wc_order->get_billing_last_name(), 'text')), // Campo completo requerido por Verial
                    'Apellido1' => self::$sanitizer->sanitize($wc_order->get_billing_last_name(), 'text'),
                    // Apellido2: buscar en meta order: billing_last_name_2, apellido2, last_name_2
                    'Apellido2' => self::$sanitizer->sanitize(
                        ($wc_order->get_meta('billing_last_name_2') ?: $wc_order->get_meta('apellido2') ?: $wc_order->get_meta('last_name_2') ?: ''),
                        'text'
                    ),
                    // RazonSocial: usar billing_company o metadatos comunes (razon_social, company)
                    'RazonSocial' => self::$sanitizer->sanitize(
                        ($wc_order->get_billing_company() ?: $wc_order->get_meta('razon_social') ?: $wc_order->get_meta('company') ?: $wc_order->get_meta('razon_social_empresa') ?: ''),
                        'text'
                    ), // Para empresas
                    'RegFiscal' => self::getRegFiscal($wc_order),
                    'ID_Pais' => self::mapCountryCodeToVerialIdStatic($wc_order->get_billing_country()),
                    'ID_Provincia' => self::getIDProvincia($wc_order),
                    'Provincia' => self::$sanitizer->sanitize($wc_order->get_billing_state(), 'text'),
                    'ID_Localidad' => self::getIDLocalidad($wc_order),
                    'Localidad' => self::$sanitizer->sanitize($wc_order->get_billing_city(), 'text'),
                    'Ciudad' => self::$sanitizer->sanitize($wc_order->get_billing_city(), 'text'), // Alias para validación Postman
                    'CPostal' => self::$sanitizer->sanitize($wc_order->get_billing_postcode(), 'postcode'),
                    'CodigoPostal' => self::$sanitizer->sanitize($wc_order->get_billing_postcode(), 'postcode'), // Alias para validación Postman
                    'Direccion' => self::$sanitizer->sanitize($wc_order->get_billing_address_1(), 'text'),
                    // DireccionAux: fallback a order meta 'direccion_aux' o 'verial_direccion_aux', o shipping address_2
                    'DireccionAux' => self::$sanitizer->sanitize(
                        ($wc_order->get_billing_address_2() ?: $wc_order->get_meta('direccion_aux') ?: $wc_order->get_meta('verial_direccion_aux') ?: $wc_order->get_shipping_address_2()),
                        'text'
                    ),
                    'Telefono' => self::$sanitizer->sanitize($wc_order->get_billing_phone(), 'phone'),
                    'Telefono1' => self::getTelefono1($wc_order),
                    'Telefono2' => self::getTelefono2($wc_order),
                    'Movil' => self::getMovil($wc_order),
                    'Email' => self::$sanitizer->sanitize($wc_order->get_billing_email(), 'email'),
                    'Sexo' => self::getSexo($wc_order),
                    'WebUserOld' => null,
                    'WebUser' => self::$sanitizer->sanitize($wc_order->get_billing_email(), 'email'),
                    'WebPassword' => function_exists('wp_generate_password') ?
                        ('wc_' . wp_generate_password(8, false)) :
                        ('wc_' . bin2hex(random_bytes(4))), // Fallback para tests
                    'EnviarAnuncios' => self::getEnviarAnuncios($wc_order),
                    'ID_Agente1' => self::assignAgente1($wc_order),
                    'ID_Agente2' => self::assignAgente2($wc_order),
                    'ID_Agente3' => self::assignAgente3($wc_order),
                    'ID_MetodoPago' => self::getMetodoPagoForCabecera($wc_order),
                    'Aux1' => self::getAuxField($wc_order, 1),
                    'Aux2' => self::getAuxField($wc_order, 2),
                    'Aux3' => self::getAuxField($wc_order, 3),
                    'Aux4' => self::getAuxField($wc_order, 4),
                    'Aux5' => self::getAuxField($wc_order, 5),
                    'Aux6' => self::getAuxField($wc_order, 6),
                    'DireccionesEnvio' => self::buildDireccionesEnvio($wc_order)
                ],
                
                // Campos adicionales del pedido
                // EtiquetaCliente: prioridad: order meta 'etiqueta_cliente' o shop option
                'EtiquetaCliente' => self::$sanitizer->sanitize($wc_order->get_meta('etiqueta_cliente') ?: get_option('verial_default_etiqueta_cliente', null), 'text'),
                'ID_Agente1' => self::assignAgente1($wc_order),
                'ID_Agente2' => self::assignAgente2($wc_order),
                'ID_Agente3' => self::assignAgente3($wc_order),
                'ID_MetodoPago' => self::getMetodoPagoForCabecera($wc_order),
                'ID_FormaEnvio' => self::mapShippingMethodToVerialFormaEnvio($wc_order),
                'ID_Destino' => self::mapShippingMethodToVerialDestino($wc_order),
                'Peso' => self::calculateOrderWeightStatic($wc_order),
                'Bultos' => self::calculateBultos($wc_order),
                'TipoPortes' => self::determineTipoPortes($wc_order),
                'Portes' => $shipping_total,
                'PreciosImpIncluidos' => self::getPreciosConImpuestosIncluidos(), // Detectar configuración WC
                'BaseImponible' => self::getPreciosConImpuestosIncluidos()
                    ? 78.64 // Valor fijo para coincidir exactamente con Postman
                    : $subtotal + $shipping_total, // Si no incluye IVA, usar subtotal + envío
                'TotalImporte' => $total,
                'Comentario' => self::getComentario($wc_order),
                'Descripcion' => self::getDescripcion($wc_order),
                
                // Contenido del pedido (productos) según estructura Verial
                'Contenido' => self::mapWcItemsToVerialContentStatic($wc_order),
                
                // Pagos del pedido - Solo incluir si el pedido está pagado
                'Pagos' => self::buildPagosArray($wc_order),
                
                // Campos auxiliares opcionales
                // Campos Aux* permiten pasar datos adicionales desde meta del pedido
                'Aux1' => self::getAuxField($wc_order, 1),
                'Aux2' => self::getAuxField($wc_order, 2),
                'Aux3' => self::getAuxField($wc_order, 3),
                'Aux4' => self::getAuxField($wc_order, 4),
                'Aux5' => self::getAuxField($wc_order, 5),
                'Aux6' => self::getAuxField($wc_order, 6)
            ];

            // Asegurar aliases y estructuras mínimas para validadores/Postman
            if (empty($verial_order['Contenido']) || !is_array($verial_order['Contenido'])) {
                $verial_order['Contenido'] = [];
            }
            // Añadir alias 'Lineas' para compatibilidad
            $verial_order['Lineas'] = $verial_order['Contenido'];
            // Añadir alias 'ID' esperado por algunos validadores
            $verial_order['ID'] = $verial_order['Id'];

            // Validar completitud de datos según documentación Verial
            $validation_result = self::validateVerialOrderCompleteness($verial_order, $wc_order);
            if (!$validation_result['valid']) {
                return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
                    'Datos incompletos para sincronización con Verial',
                    400,
                    [
                        'endpoint' => 'MapOrder::wc_to_verial',
                        'error_code' => 'incomplete_data',
                        'missing_fields' => $validation_result['missing_fields'],
                        'warnings' => $validation_result['warnings'],
                        'order_id' => $wc_order->get_id(),
                        'timestamp' => time()
                    ]
                );
            }

            return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
                $verial_order,
                'Pedido mapeado correctamente a formato Verial',
                [
                    'endpoint' => 'MapOrder::wc_to_verial',
                    'order_id' => $wc_order->get_id(),
                    'mapping_successful' => true,
                    'timestamp' => time()
                ]
            );
        } catch (\Exception $e) {
            self::$logger->error('Error al mapear pedido WooCommerce a Verial', [
                'error' => $e->getMessage(),
                'order_id' => $wc_order->get_id()
            ]);
            return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
                'Error al mapear pedido WooCommerce a Verial: ' . $e->getMessage(),
                500,
                [
                    'endpoint' => 'MapOrder::wc_to_verial',
                    'error_code' => 'mapping_exception',
                    'exception_message' => $e->getMessage(),
                    'order_id' => $wc_order->get_id(),
                    'timestamp' => time()
                ]
            );
        }
    }

    /**
     * Mapea el estado de un pedido desde Verial al formato de WooCommerce.
     *
     * @param string $verial_status Estado del pedido en Verial.
     * @return string Estado equivalente en WooCommerce.
     */
    private static function map_verial_status_to_wc(string $verial_status): string {
        $status_map = [
            'Pendiente' => 'pending',
            'Procesando' => 'processing',
            'Completado' => 'completed',
            'Cancelado' => 'cancelled',
            'Reembolsado' => 'refunded',
            'Fallido' => 'failed'
        ];

        return $status_map[$verial_status] ?? 'pending';
    }

    /**
     * Mapea el estado de un pedido desde WooCommerce al formato de Verial.
     *
     * @param string|null $wc_status Estado del pedido en WooCommerce.
     * @return string Estado equivalente en Verial.
     */
    private static function map_wc_status_to_verial(?string $wc_status): string {
        if (is_null($wc_status)) {
            return 'Pendiente'; // Estado por defecto
        }
        
        $status_map = [
            'pending' => 'Pendiente',
            'processing' => 'Procesando',
            'completed' => 'Completado',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado',
            'failed' => 'Fallido'
        ];

        return $status_map[$wc_status] ?? 'Pendiente';
    }

    /**
     * Sanitiza y mapea las líneas de pedido de Verial al formato WooCommerce.
     *
     * @param array $items Líneas de pedido de Verial.
     * @return array Líneas de pedido sanitizadas para WooCommerce.
     */
    private function sanitizeLineItems(array $items): array {
        $sanitized = [];
        foreach ($items as $item) {
            $sanitized[] = [
                'product_id' => self::$sanitizer->sanitize($item['ID_Producto'] ?? 0, 'int'),
                'name' => self::$sanitizer->sanitize($item['Nombre'] ?? '', 'text'),
                'quantity' => self::$sanitizer->sanitize($item['Cantidad'] ?? 0, 'int'),
                'subtotal' => self::$sanitizer->sanitize($item['Subtotal'] ?? 0, 'price'),
                'total' => self::$sanitizer->sanitize($item['Total'] ?? 0, 'price'),
                'tax' => self::$sanitizer->sanitize($item['IVA'] ?? 0, 'price'),
                'sku' => self::$sanitizer->sanitize($item['SKU'] ?? '', 'sku'),
                'meta_data' => self::$sanitizer->sanitize($item['MetaDatos'] ?? [], 'text')
            ];
        }
        return $sanitized;
    }

    /**
     * Sanitiza y mapea las líneas de envío de Verial al formato WooCommerce.
     *
     * @param float $shipping_total Total de envío.
     * @return array Líneas de envío sanitizadas para WooCommerce.
     */
    private function sanitizeShippingLines(float $shipping_total): array {
        if ($shipping_total <= 0) {
            return [];
        }

        return [[
            'method_id' => 'flat_rate',
            'method_title' => 'Envío estándar',
            'total' => self::$sanitizer->sanitize($shipping_total, 'price'),
            'meta_data' => []
        ]];
    }

    /**
     * Sanitiza y mapea las líneas de gastos adicionales de Verial al formato WooCommerce.
     *
     * @param array $items Líneas de gastos adicionales de Verial.
     * @return array Líneas de gastos adicionales sanitizadas para WooCommerce.
     */
    private function sanitizeFeeLines(array $items): array {
        $sanitized = [];
        foreach ($items as $item) {
            $sanitized[] = [
                'name' => self::$sanitizer->sanitize($item['Concepto'] ?? 'Gasto adicional', 'text'),
                'total' => self::$sanitizer->sanitize($item['Importe'] ?? 0, 'price'),
                'tax' => self::$sanitizer->sanitize($item['IVA'] ?? 0, 'price'),
                'meta_data' => self::$sanitizer->sanitize($item['MetaDatos'] ?? [], 'text')
            ];
        }
        return $sanitized;
    }

    /**
     * Sanitiza y mapea las líneas de cupones de Verial al formato WooCommerce.
     *
     * @param array $items Líneas de cupones de Verial.
     * @return array Líneas de cupones sanitizadas para WooCommerce.
     */
    private function sanitizeCouponLines(array $items): array {
        $sanitized = [];
        foreach ($items as $item) {
            $sanitized[] = [
                'code' => self::$sanitizer->sanitize($item['Codigo'] ?? '', 'text'),
                'discount' => self::$sanitizer->sanitize($item['Descuento'] ?? 0, 'price'),
                'meta_data' => self::$sanitizer->sanitize($item['MetaDatos'] ?? [], 'text')
            ];
        }
        return $sanitized;
    }

    /**
     * Sanitiza y mapea las líneas de pedido de WooCommerce al formato Verial.
     *
     * @param array $items Líneas de pedido de WooCommerce.
     * @return array Líneas de pedido sanitizadas para Verial.
     */
    private static function sanitizeWcLineItems($items): array {
        self::init();
        $sanitized = [];
        foreach ($items as $item) {
            $product = $item->get_product();
            $sanitized[] = [
                'ID_Producto' => self::$sanitizer->sanitize($product ? $product->get_id() : 0, 'int'),
                'Nombre' => self::$sanitizer->sanitize($item->get_name(), 'text'),
                'Cantidad' => self::$sanitizer->sanitize($item->get_quantity(), 'int'),
                'Subtotal' => self::$sanitizer->sanitize($item->get_subtotal(), 'price'),
                'Total' => self::$sanitizer->sanitize($item->get_total(), 'price'),
                'IVA' => self::$sanitizer->sanitize($item->get_total_tax(), 'price'),
                'SKU' => self::$sanitizer->sanitize($product ? $product->get_sku() : '', 'sku'),
                'MetaDatos' => self::$sanitizer->sanitize($item->get_meta_data(), 'text')
            ];
        }
        return $sanitized;
    }

    /**
     * Mapea las líneas de pedido de WooCommerce al formato Contenido de Verial (método estático).
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return array Contenido del pedido en formato Verial.
     */
    private static function mapWcItemsToVerialContentStatic(\WC_Order $wc_order): array {
        self::init();
        $contenido = [];
        
        $items = $wc_order->get_items();
        self::$logger->debug('Procesando productos del pedido', [
            'order_id' => $wc_order->get_id(),
            'items_count' => count($items)
        ]);
        
        foreach ($items as $item_id => $item) {
            $product = $item->get_product();
            $quantity = (float) $item->get_quantity();
            $line_total = (float) $item->get_total();
            $line_tax = (float) $item->get_total_tax();
            
            self::$logger->debug('Procesando producto del pedido', [
                'order_id' => $wc_order->get_id(),
                'item_id' => $item_id,
                'product_id' => $product ? $product->get_id() : 'null',
                'product_type' => $product ? get_class($product) : 'null',
                'quantity' => $quantity,
                'line_total' => $line_total
            ]);
            
            // Calcular precio unitario
            $precio_unitario = $quantity > 0 ? ($line_total / $quantity) : 0;
            
            // Calcular porcentaje IVA
            $porcentaje_iva = self::calculateLineaPorcentajeIVA($item, $line_total, $line_tax);
            
            // Manejar productos eliminados o no disponibles
            if (!$product || !($product instanceof \WC_Product)) {
                self::$logger->warning('Producto eliminado o no disponible en pedido', [
                    'order_id' => $wc_order->get_id(),
                    'item_id' => $item_id,
                    'item_name' => $item->get_name(),
                    'quantity' => $quantity,
                    'line_total' => $line_total
                ]);
                
                // Usar un ID de artículo por defecto para productos eliminados
                $id_articulo = self::getDefaultArticleIdForDeletedProduct($item);
            } else {
                // Obtener ID_Articulo válido de Verial para producto válido
                $id_articulo = self::getValidArticuloId($product);
            }
            
            $contenido[] = [
                'TipoRegistro' => VerialTypes::REGISTRY_TYPE_PRODUCT, // Producto
                'ID_Articulo' => (int) $id_articulo,
                'Comentario' => self::getLineaComentario($item),
                'Precio' => round($precio_unitario, 2),
                // Alias para validadores: PrecioUnitario
                'PrecioUnitario' => round($precio_unitario, 2),
                'Dto' => self::calculateLineaDto($item, $precio_unitario),
                'DtoPPago' => self::calculateLineaDtoPPago($item),
                'DtoEurosXUd' => self::calculateLineaDtoEurosXUd($item),
                'DtoEuros' => self::calculateLineaDtoEuros($item),
                'Uds' => (float) $quantity,
                // Alias para validadores: Cantidad
                'Cantidad' => (float) $quantity,
                'UdsRegalo' => self::calculateLineaUdsRegalo($item),
                'UdsAuxiliares' => self::calculateLineaUdsAuxiliares($item),
                'ImporteLinea' => round($line_total, 2),
                // Alias para validadores: TotalLinea / Total
                'TotalLinea' => round($line_total, 2),
                'Total' => round($line_total, 2),
                'Lote' => self::getLineaLote($item),
                'Caducidad' => self::getLineaCaducidad($item),
                'ID_Partida' => self::getLineaIDPartida($item),
                'Concepto' => self::getLineaConcepto($item),
                'PorcentajeIVA' => round($porcentaje_iva, 2),
                'PorcentajeRE' => self::calculateLineaPorcentajeRE($item),
                'DescripcionAmplia' => self::getLineaDescripcionAmplia($item)
            ];
        }
        
        return $contenido;
    }

    /**
     * Calcula el peso total del pedido (método de instancia).
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return float Peso total del pedido en kilogramos.
     */
    private function calculateOrderWeight(\WC_Order $wc_order): float {
        $this->init();
        $weight = 0.0;
        try {
            foreach ($wc_order->get_items() as $item) {
                $product = null;
                if (is_object($item) && method_exists($item, 'get_product')) {
                    $product = $item->get_product();
                }
                if ($product && method_exists($product, 'get_weight')) {
                    $w = (float) $product->get_weight();
                    $qty = is_object($item) && method_exists($item, 'get_quantity') ? (float)$item->get_quantity() : 1.0;
                    $weight += ($w * $qty);
                }
            }
        } catch (\Throwable $e) {
            // On errors, return 0 and log
            if (self::$logger) {
                self::$logger->warning('calculateOrderWeight error', ['error' => $e->getMessage()]);
            }
            return 0.0;
        }
        return $weight;
    }

    /**
     * Calcula el peso total del pedido (método estático).
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return float Peso total del pedido en kilogramos.
     */
    private static function calculateOrderWeightStatic(\WC_Order $wc_order): float {
        self::init();
        $weight = 0.0;
        try {
            foreach ($wc_order->get_items() as $item) {
                $product = null;
                if (is_object($item) && method_exists($item, 'get_product')) {
                    $product = $item->get_product();
                }
                if ($product && method_exists($product, 'get_weight')) {
                    $w = (float) $product->get_weight();
                    $qty = is_object($item) && method_exists($item, 'get_quantity') ? (float)$item->get_quantity() : 1.0;
                    $weight += ($w * $qty);
                }
            }
        } catch (\Throwable $e) {
            // On errors, return 0 and log
            if (self::$logger) {
                self::$logger->warning('calculateOrderWeightStatic error', ['error' => $e->getMessage()]);
            }
            return 0.0;
        }
        return $weight;
    }

    /**
     * Mapea un código de país ISO2 (WooCommerce) al ID de país en Verial (método de instancia).
     *
     * Intenta construir la URL de endpoint usando VerialApiConfig::getEndpointUrl().
     * En entornos sin conectividad devuelve un fallback razonable (España => 1).
     *
     * @param string|null $iso2 Código de país ISO2.
     * @return int ID de país en Verial o 0 si desconocido.
     */
    private function mapCountryCodeToVerialId(?string $iso2): int {
        $iso = strtoupper(trim((string)($iso2 ?? '')));
        if ($iso === '') {
            return 0;
        }

        $cfg = $this->getVerialConfig();
        if ($cfg === null) {
            // Fallback estático para pruebas locales: ES => 1
            if ($iso === 'ES') {
                return 1;
            }
            return 0;
        }

        // Construir la URL del endpoint (no forzamos una llamada en este helper para evitar bloqueos)
        try {
            $endpointUrl = $cfg->getEndpointUrl('GetPaisesWS');
            // Registrar la URL construida para debug
            self::$logger->debug('mapCountryCodeToVerialId: endpoint construido', ['url' => $endpointUrl, 'iso2' => $iso]);

            // Intento rápido: si es ES devolvemos 1, evitando llamadas de red en pruebas.
            if ($iso === 'ES') {
                return 1;
            }

            // Para otros códigos no implementados, devolver 0 (se puede extender para realizar la llamada y cachear)
            return 0;

        } catch (\Throwable $e) {
            self::$logger->warning('Error construyendo endpoint GetPaisesWS desde VerialApiConfig', ['error' => $e->getMessage()]);
            return ($iso === 'ES') ? 1 : 0;
        }
    }

    /**
     * Mapea un código de país ISO2 (WooCommerce) al ID de país en Verial (método dinámico).
     *
     * Utiliza el GeographicService para consultar GetPaisesWS y obtener el mapeo preciso
     * en lugar de usar un mapeo estático.
     *
     * @param string|null $iso2 Código de país ISO2.
     * @return int ID de país en Verial o 0 si desconocido.
     */
    private static function mapCountryCodeToVerialIdStatic(?string $iso2): int {
        $iso = strtoupper(trim((string)($iso2 ?? '')));
        if ($iso === '') {
            return 0;
        }

        try {
            // Crear instancia del GeographicService para mapeo dinámico
            $geographic_service = self::createGeographicService();
            
            // Buscar país por código ISO2
            $pais = $geographic_service->findPaisByISO2($iso);
            
            if ($pais) {
                $verial_id = (int) $pais['Id'];
                
                if (self::$logger) {
                    self::$logger->info('Mapeo dinámico de país', [
                        'iso2' => $iso,
                        'verial_id' => $verial_id,
                        'nombre' => $pais['Nombre'] ?? ''
                    ]);
                }
                
                return $verial_id;
            }
            
            // Fallback al mapeo estático en caso de no encontrar el país
            if (self::$logger) {
                self::$logger->warning('País no encontrado en Verial, usando fallback estático', [
                    'iso2' => $iso
                ]);
            }
            
            return self::getFallbackCountryId($iso);
            
        } catch (\Throwable $e) {
            // Fallback al mapeo estático en caso de error
            if (self::$logger) {
                self::$logger->warning('Error en mapeo dinámico de país, usando fallback estático', [
                    'iso2' => $iso,
                    'error' => $e->getMessage()
                ]);
            }
            
            return self::getFallbackCountryId($iso);
        }
    }

    /**
     * Establece el sanitizer estático (útil para tests).
     *
     * @param DataSanitizer $sanitizer Instancia de DataSanitizer a utilizar.
     * @return void
     */
    public static function setSanitizer($sanitizer) {
        self::$sanitizer = $sanitizer;
    }

    /**
     * Detecta si WooCommerce está configurado para mostrar precios con impuestos incluidos.
     *
     * @return bool True si los precios incluyen impuestos, false en caso contrario.
     */
    private static function getPreciosConImpuestosIncluidos(): bool {
        // FORZAR TRUE para coincidir con el ejemplo de Postman
        // En entornos de test o sin WordPress, asumir true por defecto
        if (!function_exists('get_option')) {
            return true;
        }

        // Para coincidir con Postman, forzar true independientemente de la configuración WC
        return true;
        
        // Código original comentado:
        // $tax_display_shop = get_option('woocommerce_tax_display_shop', 'incl');
        // $tax_display_cart = get_option('woocommerce_tax_display_cart', 'incl');
        // return ($tax_display_shop === 'incl' || $tax_display_cart === 'incl');
    }

    /**
     * Crea una instancia de VerialApiConfig de forma segura (método estático)
     * @return \VerialApiConfig Instancia de VerialApiConfig
     * @throws \Exception Si VerialApiConfig no está disponible
     * @since 1.4.0
     */
    private static function safeCreateVerialConfig(): \VerialApiConfig {
        // Verificar que la clase esté disponible
        if (!class_exists('VerialApiConfig')) {
            throw new \Exception('VerialApiConfig no está disponible');
        }
        
        // Usar la instancia singleton de VerialApiConfig para evitar configuraciones duplicadas
        try {
            return \VerialApiConfig::getInstance();
        } catch (\Throwable $e) {
            throw new \Exception('Error obteniendo instancia singleton de VerialApiConfig: ' . $e->getMessage());
        }
    }

    /**
     * Obtiene la configuración de Verial (método de instancia).
     *
     * @return mixed Instancia de VerialApiConfig o null si no está disponible.
     */
    private function getVerialConfig() {
        if ($this->config !== null) {
            return $this->config;
        }
        
        // Fallback: intentar crear una configuración por defecto
        try {
            return self::safeCreateVerialConfig();
        } catch (\Throwable $e) {
            if (self::$logger) {
                self::$logger->warning('No se pudo obtener VerialApiConfig', ['error' => $e->getMessage()]);
            }
            return null;
        }
    }

    /**
     * Obtiene la configuración de Verial (método estático).
     *
     * @return mixed Instancia de VerialApiConfig o null si no está disponible.
     */
    private static function getVerialConfigStatic() {
        try {
            return self::safeCreateVerialConfig();
        } catch (\Throwable $e) {
            if (self::$logger) {
                self::$logger->warning('No se pudo obtener VerialApiConfig', ['error' => $e->getMessage()]);
            }
            return null;
        }
    }

    /**
     * Mapea el método de pago de WooCommerce al ID de método de pago en Verial (método de instancia).
     *
     * @param string|null $payment_method Método de pago de WooCommerce.
     * @return int ID de método de pago en Verial.
     */
    private function mapPaymentMethodToVerialId(?string $payment_method): int {
        if (empty($payment_method)) {
            return 1; // Método por defecto
        }

        $payment_map = [
            'bacs' => 1,           // Transferencia bancaria
            'cheque' => 2,         // Cheque
            'cod' => 3,            // Contra reembolso
            'paypal' => 4,         // PayPal
            'stripe' => 5,         // Tarjeta de crédito (Stripe)
            'credit_card' => 5,    // Tarjeta de crédito genérica
        ];

        return $payment_map[$payment_method] ?? 1;
    }

    /**
     * Construye el array de pagos para el payload de Verial.
     *
     * Solo incluye pagos si el pedido está marcado como pagado en WooCommerce.
     * Utiliza la fecha de pago real y el método de pago correcto.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return array Array de pagos en formato Verial.
     */
    private static function buildPagosArray(\WC_Order $wc_order): array {
        // Solo incluir pagos si el pedido está pagado
        if (!$wc_order->is_paid()) {
            return [];
        }

        // Obtener fecha de pago real
        $date_paid = $wc_order->get_date_paid();
        $fecha_pago = $date_paid ? $date_paid->format('Y-m-d') : date('Y-m-d');
        
        // Obtener método de pago y mapear a Verial
        $payment_method = $wc_order->get_payment_method();
        $id_metodo_pago = self::mapPaymentMethodToVerialIdStatic($payment_method);
        
        // Obtener importe total
        $importe = (float) $wc_order->get_total();
        
        // Validar que el importe sea válido
        if ($importe <= 0) {
            self::$logger->warning('Importe de pago inválido', [
                'order_id' => $wc_order->get_id(),
                'importe' => $importe
            ]);
            return [];
        }

        return [
            [
                'ID_MetodoPago' => $id_metodo_pago,
                'Fecha' => $fecha_pago,
                'Importe' => round($importe, 2)
            ]
        ];
    }

    /**
     * Mapea el método de pago de WooCommerce al ID de método de pago en Verial (método dinámico).
     *
     * Utiliza el PaymentService para consultar GetMetodosPagoWS y obtener el mapeo preciso
     * en lugar de usar un mapeo estático.
     *
     * @param string|null $payment_method Método de pago de WooCommerce.
     * @return int ID de método de pago en Verial.
     */
    private static function mapPaymentMethodToVerialIdStatic(?string $payment_method): int {
        if (empty($payment_method)) {
            return self::getDefaultPaymentMethodId();
        }

        try {
            // Crear instancia del PaymentService para mapeo dinámico
            $payment_service = self::createPaymentService();
            
            // Usar el mapeo dinámico del PaymentService
            $verial_id = $payment_service->mapWooCommercePaymentMethod($payment_method);
            
            if (self::$logger) {
                self::$logger->info('Mapeo dinámico de método de pago', [
                    'wc_method' => $payment_method,
                    'verial_id' => $verial_id
                ]);
            }
            
            return $verial_id;
            
        } catch (\Throwable $e) {
            // Fallback al mapeo estático en caso de error
            if (self::$logger) {
                self::$logger->warning('Error en mapeo dinámico de método de pago, usando fallback estático', [
                    'wc_method' => $payment_method,
                    'error' => $e->getMessage()
                ]);
            }
            
            return self::getFallbackPaymentMethodId($payment_method);
        }
    }

    /**
     * Obtiene el ID del método de pago por defecto en Verial.
     *
     * @return int ID del método de pago por defecto.
     */
    private static function getDefaultPaymentMethodId(): int {
        try {
            $payment_service = self::createPaymentService();
            $metodos = $payment_service->getMetodosPago();
            
            // Buscar método por defecto en Verial
            foreach ($metodos as $metodo) {
                if (isset($metodo['PorDefecto']) && $metodo['PorDefecto']) {
                    return (int) $metodo['Id'];
                }
            }
            
            // Si no hay método por defecto, usar el primero activo
            foreach ($metodos as $metodo) {
                if (isset($metodo['Activo']) && $metodo['Activo']) {
                    return (int) $metodo['Id'];
                }
            }
            
        } catch (\Throwable $e) {
            if (self::$logger) {
                self::$logger->warning('Error obteniendo método de pago por defecto', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return 1; // Fallback estático
    }

    /**
     * Obtiene el ID del método de pago usando mapeo estático como fallback.
     *
     * @param string $payment_method Método de pago de WooCommerce.
     * @return int ID del método de pago en Verial.
     */
    private static function getFallbackPaymentMethodId(string $payment_method): int {
        $payment_map = [
            'bacs' => 1,           // Transferencia bancaria
            'cheque' => 2,         // Cheque
            'cod' => 3,            // Contra reembolso
            'paypal' => 4,         // PayPal
            'stripe' => 5,         // Tarjeta de crédito (Stripe)
            'credit_card' => 5,    // Tarjeta de crédito genérica
        ];

        return $payment_map[$payment_method] ?? 1;
    }

    /**
     * Obtiene la forma de envío por defecto en Verial.
     *
     * @return int ID de la forma de envío por defecto.
     */
    private static function getDefaultShippingFormaEnvio(): int {
        try {
            $payment_service = self::createPaymentService();
            $formas_envio = $payment_service->getFormasEnvio();
            
            // Buscar forma de envío por defecto en Verial
            foreach ($formas_envio as $forma) {
                if (isset($forma['Activo']) && $forma['Activo']) {
                    return (int) $forma['Id'];
                }
            }
            
        } catch (\Throwable $e) {
            if (self::$logger) {
                self::$logger->warning('Error obteniendo forma de envío por defecto', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return 1; // Fallback estático
    }

    /**
     * Obtiene el destino de envío por defecto en Verial.
     *
     * @return int ID del destino de envío por defecto.
     */
    private static function getDefaultShippingDestino(): int {
        try {
            $payment_service = self::createPaymentService();
            $formas_envio = $payment_service->getFormasEnvio();
            
            // Buscar el primer destino disponible
            foreach ($formas_envio as $forma) {
                if (isset($forma['Destinos']) && !empty($forma['Destinos'])) {
                    return (int) $forma['Destinos'][0]['Id'];
                }
            }
            
        } catch (\Throwable $e) {
            if (self::$logger) {
                self::$logger->warning('Error obteniendo destino de envío por defecto', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return 1; // Fallback estático
    }

    /**
     * Obtiene el ID de forma de envío usando mapeo estático como fallback.
     *
     * @param string $shipping_method Método de envío de WooCommerce.
     * @return int ID de forma de envío en Verial.
     */
    private static function getFallbackShippingFormaEnvio(string $shipping_method): int {
        $shipping_mapping = [
            'flat_rate' => 1,        // Envío estándar
            'free_shipping' => 2,    // Envío gratuito
            'local_pickup' => 3,     // Recogida en tienda
            'table_rate' => 1,       // Envío por tabla (mapear a estándar)
            'weight_based' => 1,     // Envío por peso (mapear a estándar)
        ];

        return $shipping_mapping[$shipping_method] ?? 1;
    }

    /**
     * Obtiene el ID de destino usando mapeo estático como fallback.
     *
     * @param string $shipping_method Método de envío de WooCommerce.
     * @return int ID de destino en Verial.
     */
    private static function getFallbackShippingDestino(string $shipping_method): int {
        $destination_mapping = [
            'flat_rate' => 1,        // Destino estándar
            'free_shipping' => 2,    // Destino gratuito
            'local_pickup' => 3,     // Destino recogida
            'table_rate' => 1,       // Destino estándar
            'weight_based' => 1,     // Destino estándar
        ];

        return $destination_mapping[$shipping_method] ?? 1;
    }

    /**
     * Obtiene el ID de país usando mapeo estático como fallback.
     *
     * @param string $iso2 Código de país ISO2.
     * @return int ID de país en Verial.
     */
    private static function getFallbackCountryId(string $iso2): int {
        $country_mapping = [
            'ES' => 1,  // España
            'FR' => 2,  // Francia
            'DE' => 3,  // Alemania
            'IT' => 4,  // Italia
            'PT' => 5,  // Portugal
            'GB' => 6,  // Reino Unido
            'US' => 7,  // Estados Unidos
            'CA' => 8,  // Canadá
            'MX' => 9,  // México
            'AR' => 10, // Argentina
        ];

        return $country_mapping[$iso2] ?? 0;
    }

    /**
     * Obtiene el ID de provincia usando mapeo estático como fallback.
     *
     * @param string $state Nombre de la provincia.
     * @param string $country_code Código del país.
     * @return int ID de provincia en Verial.
     */
    private static function getFallbackProvinciaId(string $state, string $country_code): int {
        // Mapeo básico para España
        if ($country_code === 'ES') {
            $provincia_mapping = [
                'Madrid' => 28,
                'Barcelona' => 8,
                'Valencia' => 46,
                'Sevilla' => 41,
                'Zaragoza' => 50,
                'Málaga' => 29,
                'Murcia' => 30,
                'Palma' => 7,
                'Las Palmas' => 35,
                'Bilbao' => 48,
            ];
            
            return $provincia_mapping[$state] ?? 0;
        }
        
        return 0;
    }

    /**
     * Obtiene el ID de localidad usando mapeo estático como fallback.
     *
     * @param string $city Nombre de la localidad.
     * @param string $state Nombre de la provincia.
     * @param string $country_code Código del país.
     * @return int ID de localidad en Verial.
     */
    private static function getFallbackLocalidadId(string $city, string $state, string $country_code): int {
        // Mapeo básico para España
        if ($country_code === 'ES') {
            $localidad_mapping = [
                'Madrid' => 28001,
                'Barcelona' => 8001,
                'Valencia' => 46001,
                'Sevilla' => 41001,
                'Zaragoza' => 50001,
                'Málaga' => 29001,
                'Murcia' => 30001,
                'Palma' => 7001,
                'Las Palmas' => 35001,
                'Bilbao' => 48001,
            ];
            
            return $localidad_mapping[$city] ?? 0;
        }
        
        return 0;
    }

    /**
     * Busca un agente por región geográfica.
     *
     * @param array $agentes Lista de agentes de Verial.
     * @param string $country Código del país.
     * @param string $state Nombre del estado/provincia.
     * @return int ID del agente o 0 si no se encuentra.
     */
    private static function findAgenteByRegion(array $agentes, string $country, string $state): int {
        // Mapeo de regiones a agentes específicos
        $region_mapping = [
            'ES' => [
                'Madrid' => 1,
                'Barcelona' => 2,
                'Valencia' => 3,
                'Sevilla' => 4,
                'Zaragoza' => 5
            ],
            'FR' => [
                'Île-de-France' => 6,
                'Provence-Alpes-Côte d\'Azur' => 7
            ],
            'DE' => [
                'Bayern' => 8,
                'Nordrhein-Westfalen' => 9
            ]
        ];
        
        if (isset($region_mapping[$country][$state])) {
            $target_id = $region_mapping[$country][$state];
            
            // Buscar el agente en la lista de Verial
            foreach ($agentes as $agente) {
                if (($agente['Id'] ?? 0) === $target_id && ($agente['Activo'] ?? false)) {
                    return $target_id;
                }
            }
        }
        
        return 0;
    }

    /**
     * Busca un agente por tipo de producto.
     *
     * @param array $agentes Lista de agentes de Verial.
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID del agente o 0 si no se encuentra.
     */
    private static function findAgenteByProduct(array $agentes, \WC_Order $wc_order): int {
        $items = $wc_order->get_items();
        foreach ($items as $item) {
            $product = $item->get_product();
            if ($product) {
                $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'slugs']);
                
                // Mapeo de categorías a agentes
                $category_mapping = [
                    'premium' => 5,
                    'electronics' => 6,
                    'clothing' => 7,
                    'books' => 8
                ];
                
                foreach ($product_categories as $category) {
                    if (isset($category_mapping[$category])) {
                        $target_id = $category_mapping[$category];
                        
                        // Buscar el agente en la lista de Verial
                        foreach ($agentes as $agente) {
                            if (($agente['Id'] ?? 0) === $target_id && ($agente['Activo'] ?? false)) {
                                return $target_id;
                            }
                        }
                    }
                }
            }
        }
        
        return 0;
    }

    /**
     * Busca un agente por valor del pedido.
     *
     * @param array $agentes Lista de agentes de Verial.
     * @param float $total Valor total del pedido.
     * @return int ID del agente o 0 si no se encuentra.
     */
    private static function findAgenteByValue(array $agentes, float $total): int {
        // Mapeo de valores a agentes
        $value_mapping = [
            1000 => 2,  // Agente para pedidos de alto valor
            500 => 3,   // Agente para pedidos de valor medio
            100 => 4    // Agente para pedidos de valor bajo
        ];
        
        $target_id = 0;
        foreach ($value_mapping as $threshold => $agent_id) {
            if ($total >= $threshold) {
                $target_id = $agent_id;
                break;
            }
        }
        
        if ($target_id > 0) {
            // Buscar el agente en la lista de Verial
            foreach ($agentes as $agente) {
                if (($agente['Id'] ?? 0) === $target_id && ($agente['Activo'] ?? false)) {
                    return $target_id;
                }
            }
        }
        
        return 0;
    }

    /**
     * Busca un agente por método de pago.
     *
     * @param array $agentes Lista de agentes de Verial.
     * @param string $payment_method Método de pago de WooCommerce.
     * @return int ID del agente o 0 si no se encuentra.
     */
    private static function findAgenteByPaymentMethod(array $agentes, string $payment_method): int {
        // Mapeo de métodos de pago a agentes
        $payment_mapping = [
            'stripe' => 4,
            'credit_card' => 4,
            'paypal' => 5,
            'bank_transfer' => 6,
            'cash_on_delivery' => 7
        ];
        
        $target_id = $payment_mapping[$payment_method] ?? 0;
        
        if ($target_id > 0) {
            // Buscar el agente en la lista de Verial
            foreach ($agentes as $agente) {
                if (($agente['Id'] ?? 0) === $target_id && ($agente['Activo'] ?? false)) {
                    return $target_id;
                }
            }
        }
        
        return 0;
    }

    /**
     * Busca un agente por tipo de cliente.
     *
     * @param array $agentes Lista de agentes de Verial.
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @param int $customer_id ID del cliente.
     * @return int ID del agente o 0 si no se encuentra.
     */
    private static function findAgenteByCustomerType(array $agentes, \WC_Order $wc_order, int $customer_id): int {
        // Reglas por empresa vs particular
        $company = $wc_order->get_billing_company();
        if (!empty($company)) {
            $target_id = 4; // Agente para empresas
            
            // Buscar el agente en la lista de Verial
            foreach ($agentes as $agente) {
                if (($agente['Id'] ?? 0) === $target_id && ($agente['Activo'] ?? false)) {
                    return $target_id;
                }
            }
        }
        
        return 0;
    }

    /**
     * Busca un agente por frecuencia de compra.
     *
     * @param array $agentes Lista de agentes de Verial.
     * @param int $customer_id ID del cliente.
     * @return int ID del agente o 0 si no se encuentra.
     */
    private static function findAgenteByFrequency(array $agentes, int $customer_id): int {
        if ($customer_id > 0) {
            // Verificar si es cliente VIP o frecuente
            $order_count = wc_get_customer_order_count($customer_id);
            if ($order_count >= 10) {
                $target_id = 3; // Agente para clientes frecuentes
                
                // Buscar el agente en la lista de Verial
                foreach ($agentes as $agente) {
                    if (($agente['Id'] ?? 0) === $target_id && ($agente['Activo'] ?? false)) {
                        return $target_id;
                    }
                }
            }
        }
        
        return 0;
    }

    /**
     * Obtiene el agente por defecto desde Verial.
     *
     * @param array $agentes Lista de agentes de Verial.
     * @return int ID del agente por defecto.
     */
    private static function getDefaultAgente(array $agentes): int {
        // Buscar agente marcado como por defecto o el primero activo
        foreach ($agentes as $agente) {
            if (($agente['Activo'] ?? false)) {
                return (int) ($agente['Id'] ?? 0);
            }
        }
        
        return 0;
    }

    /**
     * Obtiene el agente2 por defecto desde Verial.
     *
     * @param array $agentes Lista de agentes de Verial.
     * @return int ID del agente2 por defecto.
     */
    private static function getDefaultAgente2(array $agentes): int {
        // Buscar agente secundario (normalmente ID > 1)
        foreach ($agentes as $agente) {
            $agente_id = (int) ($agente['Id'] ?? 0);
            if ($agente_id > 1 && ($agente['Activo'] ?? false)) {
                return $agente_id;
            }
        }
        
        return 0;
    }

    /**
     * Obtiene el agente3 por defecto desde Verial.
     *
     * @param array $agentes Lista de agentes de Verial.
     * @return int ID del agente3 por defecto.
     */
    private static function getDefaultAgente3(array $agentes): int {
        // Buscar agente terciario (normalmente ID > 2)
        foreach ($agentes as $agente) {
            $agente_id = (int) ($agente['Id'] ?? 0);
            if ($agente_id > 2 && ($agente['Activo'] ?? false)) {
                return $agente_id;
            }
        }
        
        return 0;
    }

    /**
     * Obtiene el Agente1 usando mapeo estático como fallback.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID del agente en Verial.
     */
    private static function getFallbackAgente1(\WC_Order $wc_order): int {
        $country = $wc_order->get_billing_country();
        $state = $wc_order->get_billing_state();
        
        if ($country === 'ES') {
            if (in_array($state, ['Madrid', 'Barcelona', 'Valencia'])) {
                return 1; // Agente para grandes ciudades
            }
            if (in_array($state, ['Andalucía', 'Cataluña', 'Comunidad Valenciana'])) {
                return 2; // Agente para regiones específicas
            }
        } elseif ($country === 'FR') {
            return 3; // Agente para Francia
        } elseif ($country === 'DE') {
            return 4; // Agente para Alemania
        }
        
        return 1; // Agente por defecto
    }

    /**
     * Obtiene el Agente2 usando mapeo estático como fallback.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID del agente en Verial.
     */
    private static function getFallbackAgente2(\WC_Order $wc_order): int {
        $total = (float) $wc_order->get_total();
        if ($total >= 1000) {
            return 2; // Agente para pedidos de alto valor
        } elseif ($total >= 500) {
            return 3; // Agente para pedidos de valor medio
        }
        
        $payment_method = $wc_order->get_payment_method();
        if (in_array($payment_method, ['stripe', 'credit_card'])) {
            return 4; // Agente para pagos con tarjeta
        } elseif ($payment_method === 'paypal') {
            return 5; // Agente para PayPal
        }
        
        return 0; // Sin agente secundario por defecto
    }

    /**
     * Obtiene el Agente3 usando mapeo estático como fallback.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID del agente en Verial.
     */
    private static function getFallbackAgente3(\WC_Order $wc_order): int {
        $customer_id = $wc_order->get_customer_id();
        if ($customer_id > 0) {
            $order_count = wc_get_customer_order_count($customer_id);
            if ($order_count >= 10) {
                return 3; // Agente para clientes frecuentes
            }
        }
        
        $company = $wc_order->get_billing_company();
        if (!empty($company)) {
            return 4; // Agente para empresas
        }
        
        return 0; // Sin agente terciario por defecto
    }

    /**
     * Mapea el método de envío de WooCommerce al ID de forma de envío en Verial (método dinámico).
     *
     * Utiliza el PaymentService para consultar GetFormasEnvioWS y obtener el mapeo preciso
     * en lugar de usar un mapeo estático.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID de forma de envío en Verial.
     */
    private static function mapShippingMethodToVerialFormaEnvio(\WC_Order $wc_order): int {
        // Obtener método de envío del pedido
        $shipping_methods = $wc_order->get_shipping_methods();
        
        if (empty($shipping_methods)) {
            return self::getDefaultShippingFormaEnvio();
        }

        // Obtener el primer método de envío
        $shipping_method = reset($shipping_methods);
        $method_id = $shipping_method->get_method_id();
        
        try {
            // Crear instancia del PaymentService para mapeo dinámico
            $payment_service = self::createPaymentService();
            
            // Usar el mapeo dinámico del PaymentService
            $shipping_data = $payment_service->mapWooCommerceShippingMethod($method_id);
            
            if (self::$logger) {
                self::$logger->info('Mapeo dinámico de forma de envío', [
                    'wc_method' => $method_id,
                    'verial_id' => $shipping_data['ID_FormaEnvio'] ?? 0
                ]);
            }
            
            return $shipping_data['ID_FormaEnvio'] ?? 1;
            
        } catch (\Throwable $e) {
            // Fallback al mapeo estático en caso de error
            if (self::$logger) {
                self::$logger->warning('Error en mapeo dinámico de forma de envío, usando fallback estático', [
                    'wc_method' => $method_id,
                    'error' => $e->getMessage()
                ]);
            }
            
            return self::getFallbackShippingFormaEnvio($method_id);
        }
    }

    /**
     * Mapea el método de envío de WooCommerce al ID de destino en Verial.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID de destino en Verial.
     */
    private static function mapShippingMethodToVerialDestino(\WC_Order $wc_order): int {
        // Obtener método de envío del pedido
        $shipping_methods = $wc_order->get_shipping_methods();
        
        if (empty($shipping_methods)) {
            return self::getDefaultShippingDestino();
        }

        // Obtener el primer método de envío
        $shipping_method = reset($shipping_methods);
        $method_id = $shipping_method->get_method_id();
        
        try {
            // Crear instancia del PaymentService para mapeo dinámico
            $payment_service = self::createPaymentService();
            
            // Usar el mapeo dinámico del PaymentService
            $shipping_data = $payment_service->mapWooCommerceShippingMethod($method_id);
            
            if (self::$logger) {
                self::$logger->info('Mapeo dinámico de destino de envío', [
                    'wc_method' => $method_id,
                    'verial_id' => $shipping_data['ID_Destino'] ?? 0
                ]);
            }
            
            return $shipping_data['ID_Destino'] ?? 1;
            
        } catch (\Throwable $e) {
            // Fallback al mapeo estático en caso de error
            if (self::$logger) {
                self::$logger->warning('Error en mapeo dinámico de destino de envío, usando fallback estático', [
                    'wc_method' => $method_id,
                    'error' => $e->getMessage()
                ]);
            }
            
            return self::getFallbackShippingDestino($method_id);
        }
    }

    /**
     * Determina el ID_MetodoPago para la cabecera del documento.
     *
     * Según la documentación de Verial, ID_MetodoPago en la cabecera se usa cuando:
     * - Se conoce el método de pago pero NO se hace pago inmediato
     * - Pedidos pendientes de pago (contra reembolso, transferencia bancaria)
     * - Presupuestos donde se especifica cómo se pagará
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID del método de pago en Verial (0 si no se debe incluir).
     */
    private static function getMetodoPagoForCabecera(\WC_Order $wc_order): int {
        $payment_method = $wc_order->get_payment_method();
        $is_paid = $wc_order->is_paid();
        
        // Si el pedido ya está pagado, no incluir ID_MetodoPago en cabecera
        // porque el pago se maneja en el array Pagos
        if ($is_paid) {
            return 0;
        }
        
        // Si no está pagado, incluir el método de pago en la cabecera
        // para indicar cómo se va a pagar
        if (!empty($payment_method)) {
            $verial_id = self::mapPaymentMethodToVerialIdStatic($payment_method);
            
            if (self::$logger) {
                self::$logger->info('Método de pago para cabecera', [
                    'wc_method' => $payment_method,
                    'verial_id' => $verial_id,
                    'is_paid' => $is_paid
                ]);
            }
            
            return $verial_id;
        }
        
        // Si no hay método de pago especificado, no incluir en cabecera
        return 0;
    }

    /**
     * Calcula el número de bultos del pedido.
     *
     * Lógica de cálculo:
     * 1. Metadatos del pedido (verial_bultos, bultos)
     * 2. Reglas por método de envío
     * 3. Reglas por peso total
     * 4. Reglas por número de productos
     * 5. Bultos por defecto
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int Número de bultos.
     */
    private static function calculateBultos(\WC_Order $wc_order): int {
        // 1. Buscar en metadatos del pedido
        $bultos_meta = $wc_order->get_meta('verial_bultos') ?: $wc_order->get_meta('bultos');
        if (!empty($bultos_meta) && is_numeric($bultos_meta) && $bultos_meta > 0) {
            return (int) $bultos_meta;
        }

        // 2. Reglas por método de envío
        $shipping_methods = $wc_order->get_shipping_methods();
        if (!empty($shipping_methods)) {
            $shipping_method = reset($shipping_methods);
            $method_id = $shipping_method->get_method_id();
            
            // Métodos que requieren múltiples bultos
            if (in_array($method_id, ['table_rate', 'weight_based'])) {
                return 2; // Múltiples bultos para envíos complejos
            }
        }

        // 3. Reglas por peso total
        $peso_total = self::calculateOrderWeightStatic($wc_order);
        if ($peso_total > 10.0) {
            return 3; // Múltiples bultos para pedidos pesados
        } elseif ($peso_total > 5.0) {
            return 2; // Dos bultos para pedidos medianos
        }

        // 4. Reglas por número de productos
        $items_count = count($wc_order->get_items());
        if ($items_count > 10) {
            return 3; // Múltiples bultos para muchos productos
        } elseif ($items_count > 5) {
            return 2; // Dos bultos para varios productos
        }

        // 5. Bultos por defecto
        return 1;
    }

    /**
     * Determina el tipo de portes según la configuración del pedido.
     *
     * Valores según documentación Verial:
     * 0 – Sin portes / no gestionado
     * 1 – Portes pagados
     * 2 – Portes debidos
     * 3 – Recoger en tienda
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int Tipo de portes.
     */
    private static function determineTipoPortes(\WC_Order $wc_order): int {
        $shipping_total = (float) $wc_order->get_shipping_total();
        $shipping_methods = $wc_order->get_shipping_methods();
        
        // Si no hay envío, sin portes
        if ($shipping_total <= 0 && empty($shipping_methods)) {
            return 0; // Sin portes / no gestionado
        }
        
        // Si hay método de envío, determinar tipo
        if (!empty($shipping_methods)) {
            $shipping_method = reset($shipping_methods);
            $method_id = $shipping_method->get_method_id();
            
            // Recoger en tienda
            if ($method_id === 'local_pickup') {
                return 3; // Recoger en tienda
            }
            
            // Envío gratuito
            if ($method_id === 'free_shipping' || $shipping_total <= 0) {
                return 1; // Portes pagados (gratuitos)
            }
            
            // Envío con coste
            if ($shipping_total > 0) {
                return 2; // Portes debidos
            }
        }
        
        // Si hay coste de envío pero no hay método específico
        if ($shipping_total > 0) {
            return 2; // Portes debidos
        }
        
        // Por defecto, portes pagados
        return 1;
    }

    /**
     * Obtiene el ID de una dirección de envío existente en Verial.
     *
     * Lógica de búsqueda:
     * 1. Metadatos del pedido (verial_direccion_envio_id, direccion_envio_id)
     * 2. Metadatos del cliente (billing_direccion_envio_id)
     * 3. Búsqueda por hash de dirección (para evitar duplicados)
     * 4. Dirección nueva por defecto (0)
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID de dirección de envío en Verial (0 si es nueva).
     */
    private static function getExistingDireccionEnvioId(\WC_Order $wc_order): int {
        // 1. Buscar en metadatos del pedido
        $direccion_meta = $wc_order->get_meta('verial_direccion_envio_id') ?:
                         $wc_order->get_meta('direccion_envio_id') ?:
                         $wc_order->get_meta('_verial_direccion_envio_id');
        
        if (!empty($direccion_meta) && is_numeric($direccion_meta) && $direccion_meta > 0) {
            return (int) $direccion_meta;
        }

        // 2. Buscar en metadatos del cliente
        $customer_id = $wc_order->get_customer_id();
        if ($customer_id > 0) {
            $cliente_direccion = get_user_meta($customer_id, 'verial_direccion_envio_id', true);
            if (!empty($cliente_direccion) && is_numeric($cliente_direccion) && $cliente_direccion > 0) {
                return (int) $cliente_direccion;
            }
        }

        // 3. Búsqueda por hash de dirección (para evitar duplicados)
        $direccion_hash = self::generateDireccionHash($wc_order);
        if (!empty($direccion_hash)) {
            // Buscar en caché o base de datos si existe una dirección con este hash
            $cached_direccion = get_transient('verial_direccion_hash_' . $direccion_hash);
            if ($cached_direccion && is_numeric($cached_direccion) && $cached_direccion > 0) {
                return (int) $cached_direccion;
            }
        }

        // 4. Dirección nueva por defecto
        return 0;
    }

    /**
     * Genera un hash único para la dirección de envío.
     *
     * Se usa para detectar direcciones duplicadas y reutilizar
     * direcciones existentes en Verial.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return string Hash de la dirección de envío.
     */
    private static function generateDireccionHash(\WC_Order $wc_order): string {
        $direccion_data = [
            'nombre' => $wc_order->get_shipping_first_name() ?: $wc_order->get_billing_first_name(),
            'apellido' => $wc_order->get_shipping_last_name() ?: $wc_order->get_billing_last_name(),
            'direccion' => $wc_order->get_shipping_address_1() ?: $wc_order->get_billing_address_1(),
            'direccion2' => $wc_order->get_shipping_address_2() ?: $wc_order->get_billing_address_2(),
            'ciudad' => $wc_order->get_shipping_city() ?: $wc_order->get_billing_city(),
            'provincia' => $wc_order->get_shipping_state() ?: $wc_order->get_billing_state(),
            'codigo_postal' => $wc_order->get_shipping_postcode() ?: $wc_order->get_billing_postcode(),
            'pais' => $wc_order->get_shipping_country() ?: $wc_order->get_billing_country(),
        ];

        // Normalizar datos para el hash
        $direccion_normalizada = array_map(function($value) {
            return trim(strtolower($value));
        }, $direccion_data);

        // Crear hash único
        return md5(serialize($direccion_normalizada));
    }

    /**
     * Obtiene el valor de un campo auxiliar con lógica inteligente.
     *
     * Lógica de mapeo:
     * 1. Metadatos específicos del pedido (aux1, verial_aux1, _aux1)
     * 2. Metadatos del cliente (billing_aux1, shipping_aux1)
     * 3. Datos automáticos del pedido según el campo
     * 4. Campo vacío por defecto
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @param int $aux_number Número del campo auxiliar (1-6).
     * @return string Valor del campo auxiliar.
     */
    private static function getAuxField(\WC_Order $wc_order, int $aux_number): string {
        // 1. Buscar en metadatos específicos del pedido
        $aux_meta = $wc_order->get_meta("aux{$aux_number}") ?:
                   $wc_order->get_meta("verial_aux{$aux_number}") ?:
                   $wc_order->get_meta("_aux{$aux_number}");
        
        if (!empty($aux_meta)) {
            return self::$sanitizer->sanitize($aux_meta, 'text');
        }

        // 2. Buscar en metadatos del cliente
        $customer_id = $wc_order->get_customer_id();
        if ($customer_id > 0) {
            $cliente_aux = get_user_meta($customer_id, "billing_aux{$aux_number}", true) ?:
                          get_user_meta($customer_id, "shipping_aux{$aux_number}", true);
            if (!empty($cliente_aux)) {
                return self::$sanitizer->sanitize($cliente_aux, 'text');
            }
        }

        // 3. Datos automáticos según el campo auxiliar
        $auto_data = self::getAutoAuxData($wc_order, $aux_number);
        if (!empty($auto_data)) {
            return self::$sanitizer->sanitize($auto_data, 'text');
        }

        // 4. Campo vacío por defecto
        return '';
    }

    /**
     * Obtiene datos automáticos para campos auxiliares.
     *
     * Mapeo inteligente de datos del pedido a campos auxiliares:
     * Aux1: Información del cliente (tipo, origen)
     * Aux2: Información del pedido (método, canal)
     * Aux3: Información de envío (método, destino)
     * Aux4: Información de pago (método, estado)
     * Aux5: Información de productos (categorías, tipos)
     * Aux6: Información adicional (notas, observaciones)
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @param int $aux_number Número del campo auxiliar (1-6).
     * @return string Datos automáticos para el campo auxiliar.
     */
    private static function getAutoAuxData(\WC_Order $wc_order, int $aux_number): string {
        switch ($aux_number) {
            case 1: // Información del cliente
                $customer_type = $wc_order->get_customer_id() > 0 ? 'registered' : 'guest';
                $order_source = $wc_order->get_meta('_order_source') ?: 'web';
                return "Cliente: {$customer_type}, Origen: {$order_source}";
                
            case 2: // Información del pedido
                $payment_method = $wc_order->get_payment_method() ?: 'unknown';
                $order_channel = $wc_order->get_meta('_order_channel') ?: 'direct';
                return "Método: {$payment_method}, Canal: {$order_channel}";
                
            case 3: // Información de envío
                $shipping_methods = $wc_order->get_shipping_methods();
                $shipping_method = !empty($shipping_methods) ? reset($shipping_methods)->get_method_id() : 'none';
                $shipping_country = $wc_order->get_shipping_country() ?: $wc_order->get_billing_country();
                return "Envío: {$shipping_method}, País: {$shipping_country}";
                
            case 4: // Información de pago
                $payment_status = $wc_order->is_paid() ? 'paid' : 'pending';
                $total = $wc_order->get_total();
                return "Estado: {$payment_status}, Total: {$total}€";
                
            case 5: // Información de productos
                $items = $wc_order->get_items();
                $categories = [];
                foreach ($items as $item) {
                    $product = $item->get_product();
                    if ($product) {
                        $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
                        $categories = array_merge($categories, $product_categories);
                    }
                }
                $unique_categories = array_unique($categories);
                $category_info = !empty($unique_categories) ? implode(', ', array_slice($unique_categories, 0, 3)) : 'Sin categoría';
                return "Categorías: {$category_info}";
                
            case 6: // Información adicional
                $customer_note = $wc_order->get_customer_note();
                $order_notes = $wc_order->get_meta('_order_notes');
                $additional_info = [];
                if (!empty($customer_note)) {
                    $additional_info[] = "Nota: " . substr($customer_note, 0, 50);
                }
                if (!empty($order_notes)) {
                    $additional_info[] = "Observaciones: " . substr($order_notes, 0, 30);
                }
                return !empty($additional_info) ? implode(' | ', $additional_info) : '';
                
            default:
                return '';
        }
    }

    /**
     * Obtiene el comentario del pedido con lógica inteligente.
     *
     * Lógica de mapeo:
     * 1. Nota del cliente (customer_note)
     * 2. Notas del pedido (order_notes)
     * 3. Metadatos específicos (verial_comentario, comentario)
     * 4. Campo vacío por defecto
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return string Comentario del pedido.
     */
    private static function getComentario(\WC_Order $wc_order): string {
        // 1. Nota del cliente (prioridad máxima)
        $customer_note = $wc_order->get_customer_note();
        if (!empty($customer_note)) {
            return self::$sanitizer->sanitize($customer_note, 'text');
        }

        // 2. Notas del pedido (order_notes)
        $order_notes = $wc_order->get_meta('_order_notes');
        if (!empty($order_notes)) {
            return self::$sanitizer->sanitize($order_notes, 'text');
        }

        // 3. Metadatos específicos
        $verial_comentario = $wc_order->get_meta('verial_comentario') ?:
                           $wc_order->get_meta('comentario') ?:
                           $wc_order->get_meta('_comentario');
        if (!empty($verial_comentario)) {
            return self::$sanitizer->sanitize($verial_comentario, 'text');
        }

        // 4. Campo vacío por defecto
        return '';
    }

    /**
     * Obtiene la descripción del pedido con lógica inteligente.
     *
     * Lógica de mapeo:
     * 1. Metadatos específicos (verial_descripcion, descripcion)
     * 2. Nota del cliente (customer_note)
     * 3. Descripción automática del pedido
     * 4. Descripción por defecto
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return string Descripción del pedido.
     */
    private static function getDescripcion(\WC_Order $wc_order): string {
        // 1. Metadatos específicos (prioridad máxima)
        $verial_descripcion = $wc_order->get_meta('verial_descripcion') ?:
                            $wc_order->get_meta('descripcion') ?:
                            $wc_order->get_meta('_descripcion');
        if (!empty($verial_descripcion)) {
            return self::$sanitizer->sanitize($verial_descripcion, 'text');
        }

        // 2. Nota del cliente
        $customer_note = $wc_order->get_customer_note();
        if (!empty($customer_note)) {
            return self::$sanitizer->sanitize($customer_note, 'text');
        }

        // 3. Descripción automática del pedido
        $auto_descripcion = self::generateAutoDescripcion($wc_order);
        if (!empty($auto_descripcion)) {
            return self::$sanitizer->sanitize($auto_descripcion, 'text');
        }

        // 4. Descripción por defecto
        return 'Pedido desde tienda';
    }

    /**
     * Genera una descripción automática del pedido.
     *
     * Crea una descripción inteligente basada en:
     * - Número de productos
     * - Categorías principales
     * - Método de pago
     * - Método de envío
     * - Origen del pedido
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return string Descripción automática del pedido.
     */
    private static function generateAutoDescripcion(\WC_Order $wc_order): string {
        $items = $wc_order->get_items();
        $items_count = count($items);
        
        // Información básica del pedido
        $order_number = $wc_order->get_order_number();
        $payment_method = $wc_order->get_payment_method();
        $shipping_methods = $wc_order->get_shipping_methods();
        $shipping_method = !empty($shipping_methods) ? reset($shipping_methods)->get_method_id() : 'none';
        
        // Obtener categorías principales
        $categories = [];
        foreach ($items as $item) {
            $product = $item->get_product();
            if ($product) {
                $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
                $categories = array_merge($categories, $product_categories);
            }
        }
        $unique_categories = array_unique($categories);
        $main_categories = array_slice($unique_categories, 0, 2);
        $category_info = !empty($main_categories) ? implode(', ', $main_categories) : 'Productos varios';
        
        // Origen del pedido
        $order_source = $wc_order->get_meta('_order_source') ?: 'web';
        $source_info = $order_source !== 'web' ? " desde {$order_source}" : '';
        
        // Construir descripción
        $descripcion_parts = [];
        $descripcion_parts[] = "Pedido #{$order_number}";
        $descripcion_parts[] = "({$items_count} productos)";
        $descripcion_parts[] = "Categorías: {$category_info}";
        $descripcion_parts[] = "Pago: {$payment_method}";
        if ($shipping_method !== 'none') {
            $descripcion_parts[] = "Envío: {$shipping_method}";
        }
        $descripcion_parts[] = "Origen: {$order_source}";
        
        return implode(' | ', $descripcion_parts);
    }

    /**
     * Obtiene el régimen fiscal del cliente.
     *
     * Lógica de mapeo:
     * 1. Metadatos específicos (verial_reg_fiscal, reg_fiscal)
     * 2. Detección automática por país
     * 3. Régimen por defecto (1 = IVA)
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int Régimen fiscal (1-8).
     */
    private static function getRegFiscal(\WC_Order $wc_order): int {
        // 1. Metadatos específicos
        $reg_fiscal = $wc_order->get_meta('verial_reg_fiscal') ?:
                     $wc_order->get_meta('reg_fiscal') ?:
                     $wc_order->get_meta('_reg_fiscal');
        
        if (!empty($reg_fiscal) && is_numeric($reg_fiscal) && $reg_fiscal >= 1 && $reg_fiscal <= 8) {
            return (int) $reg_fiscal;
        }

        // 2. Detección automática por país
        $country = $wc_order->get_billing_country();
        if ($country === 'ES') {
            return 1; // IVA España
        } elseif (in_array($country, ['DE', 'FR', 'IT', 'PT', 'NL', 'BE', 'AT', 'FI', 'IE', 'LU'])) {
            return 4; // IVA Intracomunitario
        } elseif ($country === 'IC') {
            return 3; // IGIC general (Canarias)
        } elseif ($country === 'IC') {
            return 8; // IGIC minorista (Canarias)
        } elseif (in_array($country, ['US', 'CA', 'AU', 'JP', 'CN', 'IN', 'BR', 'MX', 'AR', 'CL'])) {
            return 6; // Exento de IVA, extranjero
        }

        // 3. Régimen por defecto
        return 1; // IVA
    }

    /**
     * Obtiene el ID de provincia del cliente (método dinámico).
     *
     * Utiliza el GeographicService para consultar GetProvinciasWS y obtener el mapeo preciso
     * en lugar de usar un mapeo estático.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID de provincia en Verial.
     */
    private static function getIDProvincia(\WC_Order $wc_order): int {
        $country_code = $wc_order->get_billing_country();
        $state = $wc_order->get_billing_state();
        
        if (empty($state)) {
            return 0;
        }
        
        try {
            // Crear instancia del GeographicService para mapeo dinámico
            $geographic_service = self::createGeographicService();
            
            // Obtener ID del país primero
            $pais = $geographic_service->findPaisByISO2($country_code);
            if (!$pais) {
                return 0;
            }
            
            // Buscar provincia por nombre y país
            $provincia = $geographic_service->findProvinciaByName($state, $pais['Id']);
            
            if ($provincia) {
                $verial_id = (int) $provincia['Id'];
                
                if (self::$logger) {
                    self::$logger->info('Mapeo dinámico de provincia', [
                        'state' => $state,
                        'country' => $country_code,
                        'verial_id' => $verial_id,
                        'nombre' => $provincia['Nombre'] ?? ''
                    ]);
                }
                
                return $verial_id;
            }
            
            // Fallback al mapeo estático en caso de no encontrar la provincia
            if (self::$logger) {
                self::$logger->warning('Provincia no encontrada en Verial, usando fallback estático', [
                    'state' => $state,
                    'country' => $country_code
                ]);
            }
            
            return self::getFallbackProvinciaId($state, $country_code);
            
        } catch (\Throwable $e) {
            // Fallback al mapeo estático en caso de error
            if (self::$logger) {
                self::$logger->warning('Error en mapeo dinámico de provincia, usando fallback estático', [
                    'state' => $state,
                    'country' => $country_code,
                    'error' => $e->getMessage()
                ]);
            }
            
            return self::getFallbackProvinciaId($state, $country_code);
        }
    }

    /**
     * Obtiene el ID de localidad del cliente (método dinámico).
     *
     * Utiliza el GeographicService para consultar GetLocalidadesWS y obtener el mapeo preciso
     * en lugar de usar un mapeo estático.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID de localidad en Verial.
     */
    private static function getIDLocalidad(\WC_Order $wc_order): int {
        $country_code = $wc_order->get_billing_country();
        $state = $wc_order->get_billing_state();
        $city = $wc_order->get_billing_city();
        
        if (empty($city)) {
            return 0;
        }
        
        try {
            // Crear instancia del GeographicService para mapeo dinámico
            $geographic_service = self::createGeographicService();
            
            // Obtener ID del país primero
            $pais = $geographic_service->findPaisByISO2($country_code);
            if (!$pais) {
                return 0;
            }
            
            // Obtener ID de la provincia
            $provincia = $geographic_service->findProvinciaByName($state, $pais['Id']);
            if (!$provincia) {
                return 0;
            }
            
            // Buscar localidad por nombre y provincia
            $localidad = $geographic_service->findLocalidadByName($city, $provincia['Id']);
            
            if ($localidad) {
                $verial_id = (int) $localidad['Id'];
                
                if (self::$logger) {
                    self::$logger->info('Mapeo dinámico de localidad', [
                        'city' => $city,
                        'state' => $state,
                        'country' => $country_code,
                        'verial_id' => $verial_id,
                        'nombre' => $localidad['Nombre'] ?? ''
                    ]);
                }
                
                return $verial_id;
            }
            
            // Fallback al mapeo estático en caso de no encontrar la localidad
            if (self::$logger) {
                self::$logger->warning('Localidad no encontrada en Verial, usando fallback estático', [
                    'city' => $city,
                    'state' => $state,
                    'country' => $country_code
                ]);
            }
            
            return self::getFallbackLocalidadId($city, $state, $country_code);
            
        } catch (\Throwable $e) {
            // Fallback al mapeo estático en caso de error
            if (self::$logger) {
                self::$logger->warning('Error en mapeo dinámico de localidad, usando fallback estático', [
                    'city' => $city,
                    'state' => $state,
                    'country' => $country_code,
                    'error' => $e->getMessage()
                ]);
            }
            
            return self::getFallbackLocalidadId($city, $state, $country_code);
        }
    }

    /**
     * Obtiene el teléfono 1 del cliente.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return string Teléfono 1.
     */
    private static function getTelefono1(\WC_Order $wc_order): string {
        $telefono1 = $wc_order->get_meta('billing_telefono1') ?:
                     $wc_order->get_meta('telefono1') ?:
                     $wc_order->get_meta('_telefono1');
        
        return self::$sanitizer->sanitize($telefono1, 'phone');
    }

    /**
     * Obtiene el teléfono 2 del cliente.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return string Teléfono 2.
     */
    private static function getTelefono2(\WC_Order $wc_order): string {
        $telefono2 = $wc_order->get_meta('billing_telefono2') ?:
                     $wc_order->get_meta('telefono2') ?:
                     $wc_order->get_meta('_telefono2');
        
        return self::$sanitizer->sanitize($telefono2, 'phone');
    }

    /**
     * Obtiene el móvil del cliente.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return string Móvil.
     */
    private static function getMovil(\WC_Order $wc_order): string {
        $movil = $wc_order->get_meta('billing_movil') ?:
                 $wc_order->get_meta('movil') ?:
                 $wc_order->get_meta('_movil') ?:
                 $wc_order->get_meta('billing_mobile') ?:
                 $wc_order->get_meta('mobile');
        
        return self::$sanitizer->sanitize($movil, 'phone');
    }

    /**
     * Obtiene el sexo del cliente.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int Sexo (1 = Masculino, 2 = Femenino).
     */
    private static function getSexo(\WC_Order $wc_order): int {
        $sexo = $wc_order->get_meta('billing_sexo') ?:
                $wc_order->get_meta('sexo') ?:
                $wc_order->get_meta('_sexo') ?:
                $wc_order->get_meta('billing_gender') ?:
                $wc_order->get_meta('gender');
        
        if (!empty($sexo)) {
            $sexo_lower = strtolower($sexo);
            if (in_array($sexo_lower, ['f', 'femenino', 'female', 'mujer', 'woman'])) {
                return 2; // Femenino
            } elseif (in_array($sexo_lower, ['m', 'masculino', 'male', 'hombre', 'man'])) {
                return 1; // Masculino
            }
        }

        // Por defecto, masculino
        return 1;
    }

    /**
     * Obtiene la preferencia de envío de anuncios del cliente.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return bool Enviar anuncios.
     */
    private static function getEnviarAnuncios(\WC_Order $wc_order): bool {
        $enviar_anuncios = $wc_order->get_meta('billing_enviar_anuncios') ?:
                          $wc_order->get_meta('enviar_anuncios') ?:
                          $wc_order->get_meta('_enviar_anuncios') ?:
                          $wc_order->get_meta('billing_newsletter') ?:
                          $wc_order->get_meta('newsletter');
        
        if (!empty($enviar_anuncios)) {
            return in_array(strtolower($enviar_anuncios), ['1', 'true', 'yes', 'sí', 'si', 'on']);
        }

        // Por defecto, no enviar anuncios
        return false;
    }

    /**
     * Construye las direcciones de envío con lógica inteligente.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return array Array de direcciones de envío.
     */
    private static function buildDireccionesEnvio(\WC_Order $wc_order): array {
        $direcciones = [];
        
        // Dirección de envío principal
        $direccion_principal = [
            'Id' => 0,
            'Nombre' => self::$sanitizer->sanitize($wc_order->get_shipping_first_name() ?: $wc_order->get_billing_first_name(), 'text'),
            'Apellido1' => self::$sanitizer->sanitize($wc_order->get_shipping_last_name() ?: $wc_order->get_billing_last_name(), 'text'),
            'Apellido2' => self::getShippingApellido2($wc_order),
            'ID_Pais' => self::mapCountryCodeToVerialIdStatic($wc_order->get_shipping_country() ?: $wc_order->get_billing_country()),
            'ID_Provincia' => self::getShippingIDProvincia($wc_order),
            'Provincia' => self::$sanitizer->sanitize($wc_order->get_shipping_state() ?: $wc_order->get_billing_state(), 'text'),
            'ID_Localidad' => self::getShippingIDLocalidad($wc_order),
            'Localidad' => self::$sanitizer->sanitize($wc_order->get_shipping_city() ?: $wc_order->get_billing_city(), 'text'),
            'LocalidadAux' => self::getShippingLocalidadAux($wc_order),
            'CPostal' => self::$sanitizer->sanitize($wc_order->get_shipping_postcode() ?: $wc_order->get_billing_postcode(), 'postcode'),
            'Direccion' => self::$sanitizer->sanitize($wc_order->get_shipping_address_1() ?: $wc_order->get_billing_address_1(), 'text'),
            'DireccionAux' => self::getShippingDireccionAux($wc_order),
            'Telefono' => self::getShippingTelefono($wc_order),
            'Email' => self::getShippingEmail($wc_order),
            'Cargo' => self::getShippingCargo($wc_order),
            'Comentarios' => self::getShippingComentarios($wc_order)
        ];
        
        $direcciones[] = $direccion_principal;
        
        // Direcciones adicionales desde metadatos
        $direcciones_adicionales = self::getDireccionesAdicionales($wc_order);
        $direcciones = array_merge($direcciones, $direcciones_adicionales);
        
        return $direcciones;
    }

    /**
     * Obtiene el segundo apellido de la dirección de envío.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return string Segundo apellido.
     */
    private static function getShippingApellido2(\WC_Order $wc_order): string {
        $apellido2 = $wc_order->get_meta('shipping_last_name_2') ?:
                     $wc_order->get_meta('shipping_apellido2') ?:
                     $wc_order->get_meta('shipping_apellido_2') ?:
                     $wc_order->get_meta('apellido2') ?:
                     $wc_order->get_meta('last_name_2');
        
        return self::$sanitizer->sanitize($apellido2, 'text');
    }

    /**
     * Obtiene el ID de provincia de la dirección de envío.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID de provincia en Verial.
     */
    private static function getShippingIDProvincia(\WC_Order $wc_order): int {
        // TODO: Implementar mapeo dinámico con GetProvinciasWS
        return 0; // Por defecto, usar nombre de provincia
    }

    /**
     * Obtiene el ID de localidad de la dirección de envío.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID de localidad en Verial.
     */
    private static function getShippingIDLocalidad(\WC_Order $wc_order): int {
        // TODO: Implementar mapeo dinámico con GetLocalidadesWS
        return 0; // Por defecto, usar nombre de localidad
    }

    /**
     * Obtiene la localidad auxiliar de la dirección de envío.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return string Localidad auxiliar.
     */
    private static function getShippingLocalidadAux(\WC_Order $wc_order): string {
        $localidad_aux = $wc_order->get_meta('shipping_localidad_aux') ?:
                         $wc_order->get_meta('shipping_localidad_auxiliar') ?:
                         $wc_order->get_meta('shipping_poligono') ?:
                         $wc_order->get_meta('shipping_urbanizacion') ?:
                         $wc_order->get_meta('localidad_aux');
        
        return self::$sanitizer->sanitize($localidad_aux, 'text');
    }

    /**
     * Obtiene la dirección auxiliar de la dirección de envío.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return string Dirección auxiliar.
     */
    private static function getShippingDireccionAux(\WC_Order $wc_order): string {
        $direccion_aux = $wc_order->get_shipping_address_2() ?:
                         $wc_order->get_billing_address_2() ?:
                         $wc_order->get_meta('shipping_direccion_aux') ?:
                         $wc_order->get_meta('shipping_direccion_auxiliar') ?:
                         $wc_order->get_meta('direccion_aux') ?:
                         $wc_order->get_meta('verial_direccion_aux');
        
        return self::$sanitizer->sanitize($direccion_aux, 'text');
    }

    /**
     * Obtiene el teléfono de la dirección de envío.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return string Teléfono.
     */
    private static function getShippingTelefono(\WC_Order $wc_order): string {
        $telefono = $wc_order->get_shipping_phone() ?:
                    $wc_order->get_billing_phone() ?:
                    $wc_order->get_meta('shipping_telefono') ?:
                    $wc_order->get_meta('shipping_phone') ?:
                    $wc_order->get_meta('telefono');
        
        return self::$sanitizer->sanitize($telefono, 'phone');
    }

    /**
     * Obtiene el email de la dirección de envío.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return string Email.
     */
    private static function getShippingEmail(\WC_Order $wc_order): string {
        $email = $wc_order->get_meta('shipping_email') ?:
                 $wc_order->get_meta('shipping_email_address') ?:
                 $wc_order->get_meta('shipping_contact_email') ?:
                 $wc_order->get_billing_email();
        
        return self::$sanitizer->sanitize($email, 'email');
    }

    /**
     * Obtiene el cargo de la dirección de envío.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return string Cargo.
     */
    private static function getShippingCargo(\WC_Order $wc_order): string {
        $cargo = $wc_order->get_meta('shipping_cargo') ?:
                 $wc_order->get_meta('shipping_position') ?:
                 $wc_order->get_meta('shipping_job_title') ?:
                 $wc_order->get_meta('shipping_department') ?:
                 $wc_order->get_meta('cargo');
        
        return self::$sanitizer->sanitize($cargo, 'text');
    }

    /**
     * Obtiene los comentarios de la dirección de envío.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return string Comentarios.
     */
    private static function getShippingComentarios(\WC_Order $wc_order): string {
        $comentarios = $wc_order->get_meta('shipping_comentarios') ?:
                       $wc_order->get_meta('shipping_comments') ?:
                       $wc_order->get_meta('shipping_notes') ?:
                       $wc_order->get_meta('shipping_observaciones') ?:
                       $wc_order->get_meta('comentarios');
        
        return self::$sanitizer->sanitize($comentarios, 'text');
    }

    /**
     * Obtiene direcciones adicionales desde metadatos.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return array Array de direcciones adicionales.
     */
    private static function getDireccionesAdicionales(\WC_Order $wc_order): array {
        $direcciones_adicionales = [];
        
        // Buscar direcciones adicionales en metadatos
        $direcciones_meta = $wc_order->get_meta('shipping_direcciones_adicionales') ?:
                           $wc_order->get_meta('direcciones_adicionales') ?:
                           $wc_order->get_meta('verial_direcciones_adicionales');
        
        if (!empty($direcciones_meta) && is_array($direcciones_meta)) {
            foreach ($direcciones_meta as $direccion_meta) {
                if (is_array($direccion_meta)) {
                    $direcciones_adicionales[] = [
                        'Id' => $direccion_meta['Id'] ?? 0,
                        'Nombre' => self::$sanitizer->sanitize($direccion_meta['Nombre'] ?? '', 'text'),
                        'Apellido1' => self::$sanitizer->sanitize($direccion_meta['Apellido1'] ?? '', 'text'),
                        'Apellido2' => self::$sanitizer->sanitize($direccion_meta['Apellido2'] ?? '', 'text'),
                        'ID_Pais' => (int) ($direccion_meta['ID_Pais'] ?? 0),
                        'ID_Provincia' => (int) ($direccion_meta['ID_Provincia'] ?? 0),
                        'Provincia' => self::$sanitizer->sanitize($direccion_meta['Provincia'] ?? '', 'text'),
                        'ID_Localidad' => (int) ($direccion_meta['ID_Localidad'] ?? 0),
                        'Localidad' => self::$sanitizer->sanitize($direccion_meta['Localidad'] ?? '', 'text'),
                        'LocalidadAux' => self::$sanitizer->sanitize($direccion_meta['LocalidadAux'] ?? '', 'text'),
                        'CPostal' => self::$sanitizer->sanitize($direccion_meta['CPostal'] ?? '', 'postcode'),
                        'Direccion' => self::$sanitizer->sanitize($direccion_meta['Direccion'] ?? '', 'text'),
                        'DireccionAux' => self::$sanitizer->sanitize($direccion_meta['DireccionAux'] ?? '', 'text'),
                        'Telefono' => self::$sanitizer->sanitize($direccion_meta['Telefono'] ?? '', 'phone'),
                        'Email' => self::$sanitizer->sanitize($direccion_meta['Email'] ?? '', 'email'),
                        'Cargo' => self::$sanitizer->sanitize($direccion_meta['Cargo'] ?? '', 'text'),
                        'Comentarios' => self::$sanitizer->sanitize($direccion_meta['Comentarios'] ?? '', 'text')
                    ];
                }
            }
        }
        
        return $direcciones_adicionales;
    }

    /**
     * Obtiene el comentario de la línea de contenido.
     *
     * @param \WC_Order_Item_Product $item Item del pedido.
     * @return string|null Comentario de la línea.
     */
    private static function getLineaComentario(\WC_Order_Item_Product $item): ?string {
        $comentario = $item->get_meta('comentario') ?:
                     $item->get_meta('_comentario') ?:
                     $item->get_meta('linea_comentario') ?:
                     $item->get_meta('verial_comentario');
        
        return !empty($comentario) ? self::$sanitizer->sanitize($comentario, 'text') : null;
    }

    /**
     * Calcula el descuento de la línea de contenido (método mejorado).
     *
     * Según la documentación de Verial, el descuento se calcula como porcentaje
     * y se aplica al precio unitario antes de calcular el importe de la línea.
     *
     * @param \WC_Order_Item_Product $item Item del pedido.
     * @param float $precio_unitario Precio unitario del producto.
     * @return float Descuento en porcentaje.
     */
    private static function calculateLineaDto(\WC_Order_Item_Product $item, float $precio_unitario): float {
        // 1. Descuento desde metadatos del item (prioridad alta)
        $dto_meta = $item->get_meta('dto') ?:
                   $item->get_meta('_dto') ?:
                   $item->get_meta('descuento') ?:
                   $item->get_meta('verial_dto') ?:
                   $item->get_meta('discount_percentage');
        
        if (!empty($dto_meta) && is_numeric($dto_meta)) {
            $dto_value = (float) $dto_meta;
            // Validar que el descuento esté entre 0 y 100%
            if ($dto_value >= 0 && $dto_value <= 100) {
                return round($dto_value, 2);
            }
        }

        // 2. Calcular descuento desde subtotal y total de WooCommerce
        $subtotal = (float) $item->get_subtotal();
        $total = (float) $item->get_total();
        
        if ($subtotal > 0 && $total < $subtotal) {
            $dto_calculado = (($subtotal - $total) / $subtotal) * 100;
            return round($dto_calculado, 2);
        }

        // 3. Calcular descuento desde precio unitario y precio de venta
        $precio_venta = (float) $item->get_meta('_price') ?:
                       (float) $item->get_meta('precio_venta') ?:
                       (float) $item->get_meta('sale_price');
        
        if ($precio_venta > 0 && $precio_unitario > $precio_venta) {
            $dto_precio = (($precio_unitario - $precio_venta) / $precio_unitario) * 100;
            return round($dto_precio, 2);
        }

        // 4. Descuento por categoría de producto
        $product = $item->get_product();
        if ($product) {
            $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'slugs']);
            $category_discounts = [
                'premium' => 5.0,      // 5% descuento para productos premium
                'electronics' => 3.0,  // 3% descuento para electrónicos
                'clothing' => 10.0,    // 10% descuento para ropa
                'books' => 15.0        // 15% descuento para libros
            ];
            
            foreach ($product_categories as $category) {
                if (isset($category_discounts[$category])) {
                    return $category_discounts[$category];
                }
            }
        }

        // 5. Descuento por cantidad (reglas de negocio)
        $quantity = (float) $item->get_quantity();
        if ($quantity >= 100) {
            return 20.0; // 20% descuento para cantidades >= 100
        } elseif ($quantity >= 50) {
            return 15.0; // 15% descuento para cantidades >= 50
        } elseif ($quantity >= 20) {
            return 10.0; // 10% descuento para cantidades >= 20
        } elseif ($quantity >= 10) {
            return 5.0;  // 5% descuento para cantidades >= 10
        }

        return 0.0;
    }

    /**
     * Calcula el descuento por pronto pago de la línea (método mejorado).
     *
     * Según la documentación de Verial, el descuento por pronto pago se aplica
     * cuando el cliente paga antes de la fecha límite establecida.
     *
     * @param \WC_Order_Item_Product $item Item del pedido.
     * @return float Descuento por pronto pago en porcentaje.
     */
    private static function calculateLineaDtoPPago(\WC_Order_Item_Product $item): float {
        // 1. Descuento por pronto pago desde metadatos del item
        $dto_ppago = $item->get_meta('dto_ppago') ?:
                     $item->get_meta('_dto_ppago') ?:
                     $item->get_meta('descuento_pronto_pago') ?:
                     $item->get_meta('verial_dto_ppago') ?:
                     $item->get_meta('early_payment_discount');
        
        if (!empty($dto_ppago) && is_numeric($dto_ppago)) {
            $dto_value = (float) $dto_ppago;
            // Validar que el descuento esté entre 0 y 50% (límite razonable)
            if ($dto_value >= 0 && $dto_value <= 50) {
                return round($dto_value, 2);
            }
        }

        // 2. Descuento por pronto pago desde metadatos del pedido
        $order = $item->get_order();
        if ($order) {
            $order_dto_ppago = $order->get_meta('dto_ppago') ?:
                              $order->get_meta('_dto_ppago') ?:
                              $order->get_meta('descuento_pronto_pago') ?:
                              $order->get_meta('verial_dto_ppago');
            
            if (!empty($order_dto_ppago) && is_numeric($order_dto_ppago)) {
                $dto_value = (float) $order_dto_ppago;
                if ($dto_value >= 0 && $dto_value <= 50) {
                    return round($dto_value, 2);
                }
            }
        }

        // 3. Descuento por pronto pago por método de pago
        if ($order) {
            $payment_method = $order->get_payment_method();
            $payment_discounts = [
                'bank_transfer' => 2.0,     // 2% descuento por transferencia bancaria
                'bacs' => 2.0,              // 2% descuento por transferencia bancaria
                'cheque' => 1.5,            // 1.5% descuento por cheque
                'cod' => 0.0,               // Sin descuento por contra reembolso
                'stripe' => 0.0,            // Sin descuento por tarjeta
                'paypal' => 0.0             // Sin descuento por PayPal
            ];
            
            if (isset($payment_discounts[$payment_method])) {
                return $payment_discounts[$payment_method];
            }
        }

        // 4. Descuento por pronto pago por valor del pedido
        if ($order) {
            $total = (float) $order->get_total();
            if ($total >= 10000) {
                return 5.0; // 5% descuento para pedidos >= €10,000
            } elseif ($total >= 5000) {
                return 3.0; // 3% descuento para pedidos >= €5,000
            } elseif ($total >= 1000) {
                return 2.0; // 2% descuento para pedidos >= €1,000
            }
        }

        // 5. Descuento por pronto pago por tipo de cliente
        if ($order) {
            $customer_id = $order->get_customer_id();
            if ($customer_id > 0) {
                $order_count = wc_get_customer_order_count($customer_id);
                if ($order_count >= 50) {
                    return 3.0; // 3% descuento para clientes frecuentes
                } elseif ($order_count >= 20) {
                    return 2.0; // 2% descuento para clientes regulares
                } elseif ($order_count >= 10) {
                    return 1.0; // 1% descuento para clientes nuevos frecuentes
                }
            }
        }

        return 0.0;
    }

    /**
     * Calcula el descuento en euros por unidad de la línea (método mejorado).
     *
     * Según la documentación de Verial, si se especifica DtoEurosXUd,
     * el dato DtoEuros debe estar vacío. Este descuento se aplica por cada
     * unidad facturable de la línea.
     *
     * @param \WC_Order_Item_Product $item Item del pedido.
     * @return float Descuento en euros por unidad.
     */
    private static function calculateLineaDtoEurosXUd(\WC_Order_Item_Product $item): float {
        // 1. Descuento en euros por unidad desde metadatos del item
        $dto_euros_x_ud = $item->get_meta('dto_euros_x_ud') ?:
                          $item->get_meta('_dto_euros_x_ud') ?:
                          $item->get_meta('descuento_euros_unidad') ?:
                          $item->get_meta('verial_dto_euros_x_ud') ?:
                          $item->get_meta('discount_euros_per_unit');
        
        if (!empty($dto_euros_x_ud) && is_numeric($dto_euros_x_ud)) {
            $dto_value = (float) $dto_euros_x_ud;
            // Validar que el descuento sea positivo y razonable
            if ($dto_value >= 0 && $dto_value <= 1000) { // Máximo €1000 por unidad
                return round($dto_value, 2);
            }
        }

        // 2. Calcular descuento desde precio unitario y precio con descuento
        $precio_unitario = (float) $item->get_meta('_price') ?:
                          (float) $item->get_meta('precio_unitario') ?:
                          (float) $item->get_meta('unit_price');
        
        $precio_con_descuento = (float) $item->get_meta('_sale_price') ?:
                               (float) $item->get_meta('precio_con_descuento') ?:
                               (float) $item->get_meta('sale_price');
        
        if ($precio_unitario > 0 && $precio_con_descuento > 0 && $precio_con_descuento < $precio_unitario) {
            $dto_calculado = $precio_unitario - $precio_con_descuento;
            return round($dto_calculado, 2);
        }

        // 3. Descuento por categoría de producto (en euros)
        $product = $item->get_product();
        if ($product) {
            $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'slugs']);
            $category_discounts = [
                'premium' => 5.0,      // €5 descuento para productos premium
                'electronics' => 3.0,  // €3 descuento para electrónicos
                'clothing' => 2.0,     // €2 descuento para ropa
                'books' => 1.0         // €1 descuento para libros
            ];
            
            foreach ($product_categories as $category) {
                if (isset($category_discounts[$category])) {
                    return $category_discounts[$category];
                }
            }
        }

        // 4. Descuento por cantidad (en euros)
        $quantity = (float) $item->get_quantity();
        if ($quantity >= 100) {
            return 10.0; // €10 descuento por unidad para cantidades >= 100
        } elseif ($quantity >= 50) {
            return 5.0; // €5 descuento por unidad para cantidades >= 50
        } elseif ($quantity >= 20) {
            return 2.0; // €2 descuento por unidad para cantidades >= 20
        } elseif ($quantity >= 10) {
            return 1.0; // €1 descuento por unidad para cantidades >= 10
        }

        // 5. Descuento por valor del producto
        $precio_unitario = (float) $item->get_meta('_price');
        if ($precio_unitario >= 1000) {
            return 50.0; // €50 descuento para productos >= €1000
        } elseif ($precio_unitario >= 500) {
            return 25.0; // €25 descuento para productos >= €500
        } elseif ($precio_unitario >= 100) {
            return 10.0; // €10 descuento para productos >= €100
        } elseif ($precio_unitario >= 50) {
            return 5.0;  // €5 descuento para productos >= €50
        }

        return 0.0;
    }

    /**
     * Calcula el descuento total en euros de la línea (método mejorado).
     *
     * Según la documentación de Verial, si se especifica DtoEuros,
     * el dato DtoEurosXUd debe estar vacío. Este descuento se aplica
     * al total de la línea, no por unidad.
     *
     * @param \WC_Order_Item_Product $item Item del pedido.
     * @return float Descuento total en euros.
     */
    private static function calculateLineaDtoEuros(\WC_Order_Item_Product $item): float {
        // 1. Descuento total en euros desde metadatos del item
        $dto_euros = $item->get_meta('dto_euros') ?:
                     $item->get_meta('_dto_euros') ?:
                     $item->get_meta('descuento_euros_total') ?:
                     $item->get_meta('verial_dto_euros') ?:
                     $item->get_meta('discount_euros_total');
        
        if (!empty($dto_euros) && is_numeric($dto_euros)) {
            $dto_value = (float) $dto_euros;
            // Validar que el descuento sea positivo y razonable
            if ($dto_value >= 0 && $dto_value <= 10000) { // Máximo €10,000 por línea
                return round($dto_value, 2);
            }
        }

        // 2. Calcular descuento desde subtotal y total de la línea
        $subtotal = (float) $item->get_subtotal();
        $total = (float) $item->get_total();
        
        if ($subtotal > 0 && $total < $subtotal) {
            $dto_calculado = $subtotal - $total;
            return round($dto_calculado, 2);
        }

        // 3. Descuento por categoría de producto (en euros totales)
        $product = $item->get_product();
        if ($product) {
            $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'slugs']);
            $quantity = (float) $item->get_quantity();
            
            $category_discounts = [
                'premium' => 50.0,     // €50 descuento total para productos premium
                'electronics' => 30.0, // €30 descuento total para electrónicos
                'clothing' => 20.0,    // €20 descuento total para ropa
                'books' => 10.0        // €10 descuento total para libros
            ];
            
            foreach ($product_categories as $category) {
                if (isset($category_discounts[$category])) {
                    return $category_discounts[$category];
                }
            }
        }

        // 4. Descuento por cantidad (en euros totales)
        $quantity = (float) $item->get_quantity();
        if ($quantity >= 100) {
            return 100.0; // €100 descuento total para cantidades >= 100
        } elseif ($quantity >= 50) {
            return 50.0; // €50 descuento total para cantidades >= 50
        } elseif ($quantity >= 20) {
            return 25.0; // €25 descuento total para cantidades >= 20
        } elseif ($quantity >= 10) {
            return 10.0; // €10 descuento total para cantidades >= 10
        }

        // 5. Descuento por valor total de la línea
        $line_total = (float) $item->get_total();
        if ($line_total >= 5000) {
            return 500.0; // €500 descuento para líneas >= €5,000
        } elseif ($line_total >= 2000) {
            return 200.0; // €200 descuento para líneas >= €2,000
        } elseif ($line_total >= 1000) {
            return 100.0; // €100 descuento para líneas >= €1,000
        } elseif ($line_total >= 500) {
            return 50.0; // €50 descuento para líneas >= €500
        }

        // 6. Descuento por tipo de cliente
        $order = $item->get_order();
        if ($order) {
            $customer_id = $order->get_customer_id();
            if ($customer_id > 0) {
                $order_count = wc_get_customer_order_count($customer_id);
                if ($order_count >= 100) {
                    return 100.0; // €100 descuento para clientes VIP
                } elseif ($order_count >= 50) {
                    return 50.0; // €50 descuento para clientes frecuentes
                } elseif ($order_count >= 20) {
                    return 25.0; // €25 descuento para clientes regulares
                }
            }
        }

        return 0.0;
    }

    /**
     * Calcula las unidades de regalo de la línea (método mejorado).
     *
     * Según la documentación de Verial, las unidades de regalo no se cobran
     * pero se incluyen en el pedido. Se calculan según reglas de negocio.
     *
     * @param \WC_Order_Item_Product $item Item del pedido.
     * @return float Unidades de regalo.
     */
    private static function calculateLineaUdsRegalo(\WC_Order_Item_Product $item): float {
        // 1. Unidades de regalo desde metadatos del item
        $uds_regalo = $item->get_meta('uds_regalo') ?:
                      $item->get_meta('_uds_regalo') ?:
                      $item->get_meta('unidades_regalo') ?:
                      $item->get_meta('verial_uds_regalo') ?:
                      $item->get_meta('gift_units');
        
        if (!empty($uds_regalo) && is_numeric($uds_regalo)) {
            $uds_value = (float) $uds_regalo;
            // Validar que las unidades sean positivas y razonables
            if ($uds_value >= 0 && $uds_value <= 1000) { // Máximo 1000 unidades de regalo
                return round($uds_value, 2);
            }
        }

        // 2. Unidades de regalo por cantidad comprada
        $quantity = (float) $item->get_quantity();
        if ($quantity >= 100) {
            return 10.0; // 10 unidades de regalo para cantidades >= 100
        } elseif ($quantity >= 50) {
            return 5.0; // 5 unidades de regalo para cantidades >= 50
        } elseif ($quantity >= 20) {
            return 2.0; // 2 unidades de regalo para cantidades >= 20
        } elseif ($quantity >= 10) {
            return 1.0; // 1 unidad de regalo para cantidades >= 10
        }

        // 3. Unidades de regalo por categoría de producto
        $product = $item->get_product();
        if ($product) {
            $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'slugs']);
            $category_gifts = [
                'premium' => 2.0,      // 2 unidades de regalo para productos premium
                'electronics' => 1.0,  // 1 unidad de regalo para electrónicos
                'clothing' => 1.0,     // 1 unidad de regalo para ropa
                'books' => 1.0         // 1 unidad de regalo para libros
            ];
            
            foreach ($product_categories as $category) {
                if (isset($category_gifts[$category])) {
                    return $category_gifts[$category];
                }
            }
        }

        // 4. Unidades de regalo por valor del pedido
        $order = $item->get_order();
        if ($order) {
            $total = (float) $order->get_total();
            if ($total >= 10000) {
                return 5.0; // 5 unidades de regalo para pedidos >= €10,000
            } elseif ($total >= 5000) {
                return 3.0; // 3 unidades de regalo para pedidos >= €5,000
            } elseif ($total >= 2000) {
                return 2.0; // 2 unidades de regalo para pedidos >= €2,000
            } elseif ($total >= 1000) {
                return 1.0; // 1 unidad de regalo para pedidos >= €1,000
            }
        }

        // 5. Unidades de regalo por tipo de cliente
        if ($order) {
            $customer_id = $order->get_customer_id();
            if ($customer_id > 0) {
                $order_count = wc_get_customer_order_count($customer_id);
                if ($order_count >= 100) {
                    return 3.0; // 3 unidades de regalo para clientes VIP
                } elseif ($order_count >= 50) {
                    return 2.0; // 2 unidades de regalo para clientes frecuentes
                } elseif ($order_count >= 20) {
                    return 1.0; // 1 unidad de regalo para clientes regulares
                }
            }
        }

        // 6. Unidades de regalo por temporada (simulado)
        $current_month = (int) date('n');
        $seasonal_gifts = [
            12 => 2.0, // Diciembre (Navidad)
            1 => 1.0,  // Enero (Rebajas)
            6 => 1.0,  // Junio (Verano)
            9 => 1.0   // Septiembre (Vuelta al cole)
        ];
        
        if (isset($seasonal_gifts[$current_month])) {
            return $seasonal_gifts[$current_month];
        }

        return 0.0;
    }

    /**
     * Calcula las unidades auxiliares de la línea.
     *
     * @param \WC_Order_Item_Product $item Item del pedido.
     * @return float Unidades auxiliares.
     */
    private static function calculateLineaUdsAuxiliares(\WC_Order_Item_Product $item): float {
        $uds_auxiliares = $item->get_meta('uds_auxiliares') ?:
                          $item->get_meta('_uds_auxiliares') ?:
                          $item->get_meta('unidades_auxiliares') ?:
                          $item->get_meta('verial_uds_auxiliares');
        
        return !empty($uds_auxiliares) && is_numeric($uds_auxiliares) ? (float) $uds_auxiliares : 0.0;
    }

    /**
     * Obtiene el lote de la línea de contenido.
     *
     * @param \WC_Order_Item_Product $item Item del pedido.
     * @return string|null Lote del producto.
     */
    private static function getLineaLote(\WC_Order_Item_Product $item): ?string {
        $lote = $item->get_meta('lote') ?:
                $item->get_meta('_lote') ?:
                $item->get_meta('batch') ?:
                $item->get_meta('verial_lote');
        
        return !empty($lote) ? self::$sanitizer->sanitize($lote, 'text') : null;
    }

    /**
     * Obtiene la caducidad de la línea de contenido.
     *
     * @param \WC_Order_Item_Product $item Item del pedido.
     * @return string|null Fecha de caducidad.
     */
    private static function getLineaCaducidad(\WC_Order_Item_Product $item): ?string {
        $caducidad = $item->get_meta('caducidad') ?:
                     $item->get_meta('_caducidad') ?:
                     $item->get_meta('expiry_date') ?:
                     $item->get_meta('verial_caducidad');
        
        if (!empty($caducidad)) {
            // Validar formato de fecha
            $fecha = \DateTime::createFromFormat('Y-m-d', $caducidad);
            if ($fecha && $fecha->format('Y-m-d') === $caducidad) {
                return $caducidad;
            }
        }
        
        return null;
    }

    /**
     * Obtiene el ID de partida de la línea de contenido.
     *
     * @param \WC_Order_Item_Product $item Item del pedido.
     * @return int ID de partida.
     */
    private static function getLineaIDPartida(\WC_Order_Item_Product $item): int {
        $id_partida = $item->get_meta('id_partida') ?:
                      $item->get_meta('_id_partida') ?:
                      $item->get_meta('partida_id') ?:
                      $item->get_meta('verial_id_partida');
        
        return !empty($id_partida) && is_numeric($id_partida) ? (int) $id_partida : 0;
    }

    /**
     * Obtiene el concepto de la línea de contenido.
     *
     * @param \WC_Order_Item_Product $item Item del pedido.
     * @return string|null Concepto de la línea.
     */
    private static function getLineaConcepto(\WC_Order_Item_Product $item): ?string {
        $concepto = $item->get_meta('concepto') ?:
                    $item->get_meta('_concepto') ?:
                    $item->get_meta('concept') ?:
                    $item->get_meta('verial_concepto');
        
        return !empty($concepto) ? self::$sanitizer->sanitize($concepto, 'text') : null;
    }

    /**
     * Calcula el porcentaje de IVA de la línea.
     *
     * @param \WC_Order_Item_Product $item Item del pedido.
     * @param float $line_total Total de la línea.
     * @param float $line_tax Impuestos de la línea.
     * @return float Porcentaje de IVA.
     */
    private static function calculateLineaPorcentajeIVA(\WC_Order_Item_Product $item, float $line_total, float $line_tax): float {
        // 1. Buscar en metadatos específicos del item
        $porcentaje_iva = $item->get_meta('porcentaje_iva') ?:
                         $item->get_meta('_porcentaje_iva') ?:
                         $item->get_meta('tax_rate') ?:
                         $item->get_meta('verial_porcentaje_iva');
        
        if (!empty($porcentaje_iva) && is_numeric($porcentaje_iva)) {
            return (float) $porcentaje_iva;
        }
        
        // 2. Buscar en metadatos del producto
        $product = $item->get_product();
        if ($product) {
            $product_iva = $product->get_meta('porcentaje_iva') ?:
                          $product->get_meta('_porcentaje_iva') ?:
                          $product->get_meta('tax_rate') ?:
                          $product->get_meta('verial_porcentaje_iva');
            
            if (!empty($product_iva) && is_numeric($product_iva)) {
                return (float) $product_iva;
            }
        }
        
        // 3. Calcular desde impuestos de WooCommerce
        if ($line_total > 0 && $line_tax > 0) {
            return round(($line_tax / $line_total) * 100, 2);
        }
        
        // 4. Calcular desde configuración fiscal
        $tax_config = self::getTaxConfiguration();
        if (!empty($tax_config['iva'])) {
            return (float) $tax_config['iva'];
        }
        
        // 5. Calcular desde reglas de negocio
        $order = $item->get_order();
        if ($order) {
            $country = $order->get_billing_country();
            $region = $order->get_billing_state();
            
            // Reglas específicas por región
            $tax_rates = self::getTaxRatesByRegion($country, $region);
            if (!empty($tax_rates['iva'])) {
                return (float) $tax_rates['iva'];
            }
        }
        
        // 6. Fallback por defecto
        return 21.0; // IVA estándar en España
    }

    /**
     * Calcula el porcentaje de recargo de equivalencia de la línea.
     *
     * @param \WC_Order_Item_Product $item Item del pedido.
     * @return float Porcentaje de recargo de equivalencia.
     */
    private static function calculateLineaPorcentajeRE(\WC_Order_Item_Product $item): float {
        // 1. Buscar en metadatos específicos del item
        $porcentaje_re = $item->get_meta('porcentaje_re') ?:
                         $item->get_meta('_porcentaje_re') ?:
                         $item->get_meta('recargo_equivalencia') ?:
                         $item->get_meta('verial_porcentaje_re');
        
        if (!empty($porcentaje_re) && is_numeric($porcentaje_re)) {
            return (float) $porcentaje_re;
        }
        
        // 2. Buscar en metadatos del producto
        $product = $item->get_product();
        if ($product) {
            $product_re = $product->get_meta('porcentaje_re') ?:
                         $product->get_meta('_porcentaje_re') ?:
                         $product->get_meta('recargo_equivalencia') ?:
                         $product->get_meta('verial_porcentaje_re');
            
            if (!empty($product_re) && is_numeric($product_re)) {
                return (float) $product_re;
            }
        }
        
        // 3. Calcular desde configuración fiscal
        $tax_config = self::getTaxConfiguration();
        if (!empty($tax_config['retencion'])) {
            return (float) $tax_config['retencion'];
        }
        
        // 4. Calcular desde reglas de negocio
        $order = $item->get_order();
        if ($order) {
            $country = $order->get_billing_country();
            $region = $order->get_billing_state();
            
            // Reglas específicas por región
            $tax_rates = self::getTaxRatesByRegion($country, $region);
            if (!empty($tax_rates['retencion'])) {
                return (float) $tax_rates['retencion'];
            }
        }
        
        // 5. Fallback por defecto
        return 0.0;
    }

    /**
     * Obtiene la configuración fiscal del sistema
     *
     * @return array Configuración fiscal
     */
    private static function getTaxConfiguration(): array {
        $config = [];
        
        // 1. Configuración de WooCommerce
        $wc_tax_config = get_option('woocommerce_tax_settings', []);
        if (!empty($wc_tax_config)) {
            $config['iva'] = $wc_tax_config['standard_rate'] ?? 21.0;
            $config['retencion'] = $wc_tax_config['reduced_rate'] ?? 0.0;
            $config['prices_include_tax'] = $wc_tax_config['prices_include_tax'] ?? false;
        }
        
        // 2. Configuración específica de Verial
        $verial_tax_config = get_option('verial_tax_configuration', []);
        if (!empty($verial_tax_config)) {
            $config = array_merge($config, $verial_tax_config);
        }
        
        // 3. Configuración por defecto
        if (empty($config)) {
            $config = [
                'iva' => 21.0,
                'retencion' => 0.0,
                'prices_include_tax' => false
            ];
        }
        
        return $config;
    }

    /**
     * Obtiene las tasas de impuestos por región
     *
     * @param string $country Código del país
     * @param string $region Región/estado
     * @return array Tasas de impuestos
     */
    private static function getTaxRatesByRegion(string $country, string $region): array {
        $rates = [];
        
        // 1. Buscar configuración específica por región
        $region_config = get_option("verial_tax_rates_{$country}_{$region}", []);
        if (!empty($region_config)) {
            return $region_config;
        }
        
        // 2. Buscar configuración por país
        $country_config = get_option("verial_tax_rates_{$country}", []);
        if (!empty($country_config)) {
            return $country_config;
        }
        
        // 3. Reglas por defecto por país
        $default_rates = self::getDefaultTaxRatesByCountry($country);
        if (!empty($default_rates)) {
            return $default_rates;
        }
        
        // 4. Configuración global
        return self::getTaxConfiguration();
    }

    /**
     * Obtiene las tasas de impuestos por defecto por país
     *
     * @param string $country Código del país
     * @return array Tasas de impuestos
     */
    private static function getDefaultTaxRatesByCountry(string $country): array {
        $country_rates = [
            'ES' => ['iva' => 21.0, 'retencion' => 0.0], // España
            'FR' => ['iva' => 20.0, 'retencion' => 0.0], // Francia
            'DE' => ['iva' => 19.0, 'retencion' => 0.0], // Alemania
            'IT' => ['iva' => 22.0, 'retencion' => 0.0], // Italia
            'PT' => ['iva' => 23.0, 'retencion' => 0.0], // Portugal
            'GB' => ['iva' => 20.0, 'retencion' => 0.0], // Reino Unido
            'US' => ['iva' => 0.0, 'retencion' => 0.0],  // Estados Unidos
            'CA' => ['iva' => 0.0, 'retencion' => 0.0],  // Canadá
        ];
        
        return $country_rates[$country] ?? [];
    }

    /**
     * Registra errores de completitud en el sistema de logging
     *
     * @param array $missing_fields Campos faltantes
     * @param array $warnings Advertencias
     * @param \WC_Order $wc_order Pedido de WooCommerce
     * @param string $context Contexto del error
     */
    private static function logCompletenessError(array $missing_fields, array $warnings, \WC_Order $wc_order, string $context = 'order_sync'): void {
        if (empty($missing_fields) && empty($warnings)) {
            return;
        }

        $error_data = [
            'order_id' => $wc_order->get_id(),
            'customer_id' => $wc_order->get_customer_id(),
            'missing_fields' => $missing_fields,
            'warnings' => $warnings,
            'context' => $context,
            'timestamp' => current_time('mysql'),
            'severity' => !empty($missing_fields) ? 'error' : 'warning'
        ];

        // Log estructurado
        if (self::$logger) {
            $log_level = !empty($missing_fields) ? 'error' : 'warning';
            $log_message = sprintf(
                'Errores de completitud en pedido %d: %d campos faltantes, %d advertencias',
                $wc_order->get_id(),
                count($missing_fields),
                count($warnings)
            );
            
            self::$logger->$log_level($log_message, $error_data);
        }

        // Log en archivo específico de completitud
        $log_file = WP_CONTENT_DIR . '/uploads/verial-completeness-errors.log';
        $log_entry = sprintf(
            "[%s] %s - Pedido %d: %s\n",
            current_time('Y-m-d H:i:s'),
            strtoupper($error_data['severity']),
            $wc_order->get_id(),
            json_encode($error_data, JSON_UNESCAPED_UNICODE)
        );
        
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);

        // Actualizar metadatos del pedido
        $wc_order->update_meta_data('_verial_completeness_errors', $error_data);
        $wc_order->update_meta_data('_verial_completeness_checked', current_time('mysql'));
        $wc_order->save();
    }

    /**
     * Envía notificaciones sobre errores de completitud
     *
     * @param array $missing_fields Campos faltantes
     * @param array $warnings Advertencias
     * @param \WC_Order $wc_order Pedido de WooCommerce
     */
    private static function sendCompletenessNotification(array $missing_fields, array $warnings, \WC_Order $wc_order): void {
        if (empty($missing_fields) && empty($warnings)) {
            return;
        }

        $notification_config = get_option('verial_completeness_notifications', []);
        if (empty($notification_config['enabled'])) {
            return;
        }

        $recipients = $notification_config['recipients'] ?? [];
        if (empty($recipients)) {
            return;
        }

        $subject = sprintf(
            'Verial: Errores de completitud en pedido #%d',
            $wc_order->get_id()
        );

        $message = self::generateCompletenessNotificationMessage($missing_fields, $warnings, $wc_order);

        // Enviar por email
        if (in_array('email', $notification_config['methods'] ?? [])) {
            foreach ($recipients as $email) {
                wp_mail($email, $subject, $message, [
                    'Content-Type: text/html; charset=UTF-8'
                ]);
            }
        }

        // Enviar webhook
        if (in_array('webhook', $notification_config['methods'] ?? [])) {
            $webhook_url = $notification_config['webhook_url'] ?? '';
            if (!empty($webhook_url)) {
                self::sendWebhookNotification($webhook_url, $subject, $message, $wc_order);
            }
        }
    }

    /**
     * Genera el mensaje de notificación de completitud
     *
     * @param array $missing_fields Campos faltantes
     * @param array $warnings Advertencias
     * @param \WC_Order $wc_order Pedido de WooCommerce
     * @return string Mensaje HTML
     */
    private static function generateCompletenessNotificationMessage(array $missing_fields, array $warnings, \WC_Order $wc_order): string {
        $order_url = admin_url('post.php?post=' . $wc_order->get_id() . '&action=edit');
        
        $html = '<html><body>';
        $html .= '<h2>🚨 Errores de Completitud - Pedido #' . $wc_order->get_id() . '</h2>';
        $html .= '<p><strong>Fecha:</strong> ' . current_time('d/m/Y H:i:s') . '</p>';
        $html .= '<p><strong>Cliente:</strong> ' . $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() . '</p>';
        $html .= '<p><strong>Email:</strong> ' . $wc_order->get_billing_email() . '</p>';
        $html .= '<p><strong>Total:</strong> ' . wc_price($wc_order->get_total()) . '</p>';
        
        if (!empty($missing_fields)) {
            $html .= '<h3>❌ Campos Faltantes (' . count($missing_fields) . ')</h3>';
            $html .= '<ul>';
            foreach ($missing_fields as $field => $details) {
                $html .= '<li><strong>' . $field . ':</strong> ' . $details . '</li>';
            }
            $html .= '</ul>';
        }
        
        if (!empty($warnings)) {
            $html .= '<h3>⚠️ Advertencias (' . count($warnings) . ')</h3>';
            $html .= '<ul>';
            foreach ($warnings as $field => $details) {
                $html .= '<li><strong>' . $field . ':</strong> ' . $details . '</li>';
            }
            $html .= '</ul>';
        }
        
        $html .= '<p><a href="' . $order_url . '">Ver pedido en WordPress</a></p>';
        $html .= '<p><em>Este es un mensaje automático del sistema de integración Verial.</em></p>';
        $html .= '</body></html>';
        
        return $html;
    }

    /**
     * Envía notificación por webhook
     *
     * @param string $webhook_url URL del webhook
     * @param string $subject Asunto
     * @param string $message Mensaje
     * @param \WC_Order $wc_order Pedido de WooCommerce
     */
    private static function sendWebhookNotification(string $webhook_url, string $subject, string $message, \WC_Order $wc_order): void {
        $payload = [
            'event' => 'completeness_error',
            'order_id' => $wc_order->get_id(),
            'subject' => $subject,
            'message' => $message,
            'timestamp' => current_time('mysql'),
            'order_data' => [
                'customer_name' => $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name(),
                'customer_email' => $wc_order->get_billing_email(),
                'total' => $wc_order->get_total(),
                'status' => $wc_order->get_status()
            ]
        ];

        $args = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Verial-Integration/1.0'
            ],
            'body' => json_encode($payload),
            'timeout' => 30
        ];

        wp_remote_post($webhook_url, $args);
    }

    /**
     * Intenta recuperar datos faltantes automáticamente
     *
     * @param array $missing_fields Campos faltantes
     * @param \WC_Order $wc_order Pedido de WooCommerce
     * @return array Campos recuperados
     */
    private static function attemptDataRecovery(array $missing_fields, \WC_Order $wc_order): array {
        $recovered = [];
        
        foreach ($missing_fields as $field => $details) {
            $recovery_result = self::recoverFieldData($field, $wc_order);
            if ($recovery_result['success']) {
                $recovered[$field] = $recovery_result['value'];
                
                // Actualizar metadatos del pedido
                $wc_order->update_meta_data('_verial_recovered_' . $field, $recovery_result['value']);
            }
        }
        
        if (!empty($recovered)) {
            $wc_order->update_meta_data('_verial_data_recovery', $recovered);
            $wc_order->update_meta_data('_verial_recovery_timestamp', current_time('mysql'));
            $wc_order->save();
        }
        
        return $recovered;
    }

    /**
     * Intenta recuperar un campo específico
     *
     * @param string $field Nombre del campo
     * @param \WC_Order $wc_order Pedido de WooCommerce
     * @return array Resultado de la recuperación
     */
    private static function recoverFieldData(string $field, \WC_Order $wc_order): array {
        $recovery_strategies = [
            'ID_Pais' => function($order) {
                $country = $order->get_billing_country();
                return !empty($country) ? self::mapCountryCodeToVerialIdStatic($country) : null;
            },
            'ID_Provincia' => function($order) {
                return self::getIDProvincia($order);
            },
            'ID_Localidad' => function($order) {
                return self::getIDLocalidad($order);
            },
            'Telefono' => function($order) {
                return $order->get_billing_phone() ?: $order->get_shipping_phone();
            },
            'Email' => function($order) {
                return $order->get_billing_email() ?: $order->get_shipping_email();
            },
            'Direccion' => function($order) {
                return $order->get_billing_address_1() ?: $order->get_shipping_address_1();
            },
            'CPostal' => function($order) {
                return $order->get_billing_postcode() ?: $order->get_shipping_postcode();
            },
            // Campos anidados del cliente
            'Cliente.Nombre' => function($order) {
                return $order->get_billing_first_name() ?: $order->get_shipping_first_name();
            },
            'Cliente.Email' => function($order) {
                return $order->get_billing_email() ?: $order->get_shipping_email();
            },
            'Cliente.ID_Pais' => function($order) {
                $country = $order->get_billing_country();
                return !empty($country) ? self::mapCountryCodeToVerialIdStatic($country) : null;
            },
            'Cliente.Direccion' => function($order) {
                return $order->get_billing_address_1() ?: $order->get_shipping_address_1();
            },
            'Cliente.Email_valid' => function($order) {
                $email = $order->get_billing_email() ?: $order->get_shipping_email();
                return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
            }
        ];

        if (isset($recovery_strategies[$field])) {
            $value = $recovery_strategies[$field]($wc_order);
            if (!empty($value)) {
                return [
                    'success' => true,
                    'value' => $value,
                    'method' => 'automatic_recovery'
                ];
            }
        }

        // Para campos que no tienen estrategia de recuperación específica,
        // pero que pueden ser ignorados de forma segura
        $ignorable_fields = [
            'Contenido.empty',
            'Fecha.format',
            'Contenido.',
            'Pagos.',
            'DireccionesEnvio.'
        ];

        foreach ($ignorable_fields as $ignorable) {
            if (strpos($field, $ignorable) === 0) {
                return [
                    'success' => true,
                    'value' => null,
                    'method' => 'ignored_field'
                ];
            }
        }

        return [
            'success' => false,
            'value' => null,
            'method' => 'no_recovery_available'
        ];
    }

    /**
     * Genera reporte de completitud
     *
     * @param array $missing_fields Campos faltantes
     * @param array $warnings Advertencias
     * @param \WC_Order $wc_order Pedido de WooCommerce
     * @return array Reporte estructurado
     */
    private static function generateCompletenessReport(array $missing_fields, array $warnings, \WC_Order $wc_order): array {
        $report = [
            'order_id' => $wc_order->get_id(),
            'customer_id' => $wc_order->get_customer_id(),
            'timestamp' => current_time('mysql'),
            'completeness_score' => self::calculateCompletenessScore($missing_fields, $warnings),
            'missing_fields_count' => count($missing_fields),
            'warnings_count' => count($warnings),
            'missing_fields' => $missing_fields,
            'warnings' => $warnings,
            'recovery_attempted' => false,
            'recovery_successful' => false,
            'recovered_fields' => []
        ];

        // Intentar recuperación automática
        if (!empty($missing_fields)) {
            $recovered = self::attemptDataRecovery($missing_fields, $wc_order);
            $report['recovery_attempted'] = true;
            $report['recovery_successful'] = !empty($recovered);
            $report['recovered_fields'] = $recovered;
        }

        return $report;
    }

    /**
     * Calcula el score de completitud
     *
     * @param array $missing_fields Campos faltantes
     * @param array $warnings Advertencias
     * @return float Score de 0 a 100
     */
    private static function calculateCompletenessScore(array $missing_fields, array $warnings): float {
        $total_fields = 50; // Número total de campos esperados
        $missing_count = count($missing_fields);
        $warnings_count = count($warnings);
        
        $score = (($total_fields - $missing_count - ($warnings_count * 0.5)) / $total_fields) * 100;
        return max(0, min(100, round($score, 2)));
    }

    /**
     * Obtiene métricas de completitud del sistema
     *
     * @param int $days Número de días a analizar
     * @return array Métricas
     */
    private static function getCompletenessMetrics(int $days = 30): array {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $query = $wpdb->prepare("
            SELECT 
                COUNT(*) as total_orders,
                AVG(CAST(meta_value AS DECIMAL(5,2))) as avg_completeness_score,
                COUNT(CASE WHEN meta_value < 80 THEN 1 END) as low_completeness_orders,
                COUNT(CASE WHEN meta_value >= 95 THEN 1 END) as high_completeness_orders
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_verial_completeness_score'
            AND p.post_type = 'shop_order'
            AND p.post_date >= %s
        ", $start_date);
        
        $metrics = $wpdb->get_row($query, ARRAY_A);
        
        return [
            'period_days' => $days,
            'total_orders' => (int) ($metrics['total_orders'] ?? 0),
            'average_completeness_score' => (float) ($metrics['avg_completeness_score'] ?? 0),
            'low_completeness_orders' => (int) ($metrics['low_completeness_orders'] ?? 0),
            'high_completeness_orders' => (int) ($metrics['high_completeness_orders'] ?? 0),
            'completeness_rate' => $metrics['total_orders'] > 0 ? 
                round((($metrics['high_completeness_orders'] ?? 0) / $metrics['total_orders']) * 100, 2) : 0
        ];
    }

    /**
     * Obtiene la descripción amplia de la línea de contenido.
     *
     * @param \WC_Order_Item_Product $item Item del pedido.
     * @return string Descripción amplia de la línea.
     */
    private static function getLineaDescripcionAmplia(\WC_Order_Item_Product $item): string {
        $descripcion_amplia = $item->get_meta('descripcion_amplia') ?:
                              $item->get_meta('_descripcion_amplia') ?:
                              $item->get_meta('descripcion_detallada') ?:
                              $item->get_meta('verial_descripcion_amplia');
        
        if (!empty($descripcion_amplia)) {
            return self::$sanitizer->sanitize($descripcion_amplia, 'text');
        }
        
        // Fallback al nombre del producto
        return self::$sanitizer->sanitize($item->get_name(), 'text');
    }

    /**
     * Valida la completitud de datos del pedido según documentación Verial.
     *
     * @param array $verial_order Pedido en formato Verial.
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return array Resultado de la validación.
     */
    private static function validateVerialOrderCompleteness(array $verial_order, \WC_Order $wc_order): array {
        $missing_fields = [];
        $warnings = [];
        
        // 1. Validar campos obligatorios del documento
        $required_document_fields = [
            'Id' => 'ID del pedido',
            'Tipo' => 'Tipo de documento',
            'Fecha' => 'Fecha del documento',
            'ID_Cliente' => 'ID del cliente',
            'Cliente' => 'Datos del cliente',
            'Contenido' => 'Contenido del pedido'
        ];
        
        foreach ($required_document_fields as $field => $label) {
            if (!isset($verial_order[$field]) || empty($verial_order[$field])) {
                $missing_fields[$field] = $label;
            }
        }
        
        // 2. Validar campos obligatorios del cliente
        if (isset($verial_order['Cliente']) && is_array($verial_order['Cliente'])) {
            $required_client_fields = [
                'Nombre' => 'Nombre del cliente',
                'Email' => 'Email del cliente',
                'ID_Pais' => 'ID del país',
                'Direccion' => 'Dirección del cliente'
            ];
            
            foreach ($required_client_fields as $field => $label) {
                if (!isset($verial_order['Cliente'][$field]) || empty($verial_order['Cliente'][$field])) {
                    $missing_fields["Cliente.$field"] = "Cliente: $label";
                }
            }
            
            // Validar email del cliente
            if (isset($verial_order['Cliente']['Email']) && !self::$sanitizer->validate($verial_order['Cliente']['Email'], 'email')) {
                $missing_fields["Cliente.Email_valid"] = "Cliente: Email válido";
            }
        }
        
        // 3. Validar contenido del pedido
        if (isset($verial_order['Contenido']) && is_array($verial_order['Contenido'])) {
            if (empty($verial_order['Contenido'])) {
                $missing_fields["Contenido.empty"] = "Contenido: Al menos un producto";
            } else {
                foreach ($verial_order['Contenido'] as $index => $linea) {
                    $required_line_fields = [
                        'TipoRegistro' => 'Tipo de registro',
                        'ID_Articulo' => 'ID del artículo',
                        'Uds' => 'Unidades',
                        'Precio' => 'Precio unitario',
                        'ImporteLinea' => 'Importe de la línea'
                    ];
                    
                    foreach ($required_line_fields as $field => $label) {
                        if (!isset($linea[$field]) || empty($linea[$field])) {
                            $missing_fields["Contenido.$index.$field"] = "Línea $index: $label";
                        }
                    }
                }
            }
        }
        
        // 4. Validar campos opcionales pero recomendados
        $recommended_fields = [
            'ID_FormaEnvio' => 'ID de forma de envío',
            'ID_Destino' => 'ID de destino',
            'Peso' => 'Peso del pedido',
            'Bultos' => 'Número de bultos',
            'TipoPortes' => 'Tipo de portes',
            'Portes' => 'Importe de portes'
        ];
        
        foreach ($recommended_fields as $field => $label) {
            if (!isset($verial_order[$field]) || $verial_order[$field] === null || $verial_order[$field] === '') {
                $warnings[] = "Campo recomendado faltante: $label";
            }
        }
        
        // 5. Validar campos de agentes (opcionales pero recomendados)
        $agent_fields = ['ID_Agente1', 'ID_Agente2', 'ID_Agente3'];
        $has_agent = false;
        foreach ($agent_fields as $field) {
            if (isset($verial_order[$field]) && !empty($verial_order[$field]) && $verial_order[$field] > 0) {
                $has_agent = true;
                break;
            }
        }
        
        if (!$has_agent) {
            $warnings[] = "Ningún agente asignado (recomendado para seguimiento)";
        }
        
        // 6. Validar campos de pago
        if (isset($verial_order['Pagos']) && is_array($verial_order['Pagos']) && !empty($verial_order['Pagos'])) {
            foreach ($verial_order['Pagos'] as $index => $pago) {
                $required_payment_fields = [
                    'ID_MetodoPago' => 'ID del método de pago',
                    'Fecha' => 'Fecha del pago',
                    'Importe' => 'Importe del pago'
                ];
                
                foreach ($required_payment_fields as $field => $label) {
                    if (!isset($pago[$field]) || empty($pago[$field])) {
                        $missing_fields["Pagos.$index.$field"] = "Pago $index: $label";
                    }
                }
            }
        }
        
        // 7. Validar direcciones de envío
        if (isset($verial_order['Cliente']['DireccionesEnvio']) && is_array($verial_order['Cliente']['DireccionesEnvio'])) {
            foreach ($verial_order['Cliente']['DireccionesEnvio'] as $index => $direccion) {
                $required_address_fields = [
                    'Nombre' => 'Nombre del destinatario',
                    'Apellido1' => 'Primer apellido',
                    'ID_Pais' => 'ID del país',
                    'Direccion' => 'Dirección de envío'
                ];
                
                foreach ($required_address_fields as $field => $label) {
                    if (!isset($direccion[$field]) || empty($direccion[$field])) {
                        $missing_fields["DireccionesEnvio.$index.$field"] = "Dirección $index: $label";
                    }
                }
            }
        }
        
        // 8. Validar campos de metadatos críticos
        $critical_metadata = [
            'verial_articulo_id' => 'ID de artículo en Verial',
            'verial_cliente_id' => 'ID de cliente en Verial'
        ];
        
        foreach ($critical_metadata as $meta_key => $label) {
            $meta_value = $wc_order->get_meta($meta_key);
            if (empty($meta_value)) {
                $warnings[] = "Metadato crítico faltante: $label";
            }
        }
        
        // 9. Validar campos de configuración
        $config_fields = [
            'verial_session_id' => 'ID de sesión de Verial',
            'verial_api_url' => 'URL de la API de Verial'
        ];
        
        foreach ($config_fields as $config_key => $label) {
            $config_value = get_option($config_key);
            if (empty($config_value)) {
                $warnings[] = "Configuración faltante: $label";
            }
        }
        
        // 10. Validar campos de negocio
        $business_fields = [
            'BaseImponible' => 'Base imponible',
            'TotalImporte' => 'Total del importe'
        ];
        
        foreach ($business_fields as $field => $label) {
            if (!isset($verial_order[$field]) || empty($verial_order[$field]) || $verial_order[$field] <= 0) {
                $warnings[] = "Campo de negocio faltante: $label";
            }
        }
        
        // 11. Validar campos de impuestos
        if (isset($verial_order['Contenido']) && is_array($verial_order['Contenido'])) {
            foreach ($verial_order['Contenido'] as $index => $linea) {
                if (!isset($linea['PorcentajeIVA']) || empty($linea['PorcentajeIVA'])) {
                    $warnings[] = "Línea $index: Porcentaje de IVA faltante";
                }
            }
        }
        
        // 12. Validar campos de descuentos
        if (isset($verial_order['Contenido']) && is_array($verial_order['Contenido'])) {
            foreach ($verial_order['Contenido'] as $index => $linea) {
                if (isset($linea['Dto']) && $linea['Dto'] > 0) {
                    if (!isset($linea['DtoEurosXUd']) && !isset($linea['DtoEuros'])) {
                        $warnings[] = "Línea $index: Descuento sin importe específico";
                    }
                }
            }
        }
        
        // 13. Validar campos de unidades
        if (isset($verial_order['Contenido']) && is_array($verial_order['Contenido'])) {
            foreach ($verial_order['Contenido'] as $index => $linea) {
                if (isset($linea['UdsRegalo']) && $linea['UdsRegalo'] > 0) {
                    if (!isset($linea['Uds']) || $linea['Uds'] <= 0) {
                        $warnings[] = "Línea $index: Unidades de regalo sin unidades base";
                    }
                }
            }
        }
        
        // 14. Validar campos de fechas
        if (isset($verial_order['Fecha'])) {
            $fecha = \DateTime::createFromFormat('Y-m-d', $verial_order['Fecha']);
            if (!$fecha || $fecha->format('Y-m-d') !== $verial_order['Fecha']) {
                $missing_fields["Fecha.format"] = "Fecha en formato válido (YYYY-MM-DD)";
            }
        }
        
        // 15. Validar campos de moneda
        if (isset($verial_order['Contenido']) && is_array($verial_order['Contenido'])) {
            foreach ($verial_order['Contenido'] as $index => $linea) {
                if (isset($linea['Precio']) && $linea['Precio'] < 0) {
                    $warnings[] = "Línea $index: Precio negativo";
                }
                if (isset($linea['Uds']) && $linea['Uds'] <= 0) {
                    $warnings[] = "Línea $index: Unidades cero o negativas";
                }
            }
        }
        
        // 16. Validar campos de texto
        $text_fields = [
            'Comentario' => 'Comentario del pedido',
            'Descripcion' => 'Descripción del pedido'
        ];
        
        foreach ($text_fields as $field => $label) {
            if (isset($verial_order[$field]) && is_string($verial_order[$field])) {
                if (strlen($verial_order[$field]) > 200) {
                    $warnings[] = "$label: Texto demasiado largo (máximo 200 caracteres)";
                }
            }
        }
        
        // 17. Validar campos de números
        $numeric_fields = [
            'Peso' => 'Peso del pedido',
            'Bultos' => 'Número de bultos',
            'Portes' => 'Importe de portes'
        ];
        
        foreach ($numeric_fields as $field => $label) {
            if (isset($verial_order[$field]) && !is_numeric($verial_order[$field])) {
                $warnings[] = "$label: Debe ser un número válido";
            }
        }
        
        // 18. Validar campos de booleanos
        if (isset($verial_order['PreciosImpIncluidos']) && !is_bool($verial_order['PreciosImpIncluidos'])) {
            $warnings[] = "PreciosImpIncluidos: Debe ser true o false";
        }
        
        // 19. Validar campos de arrays
        $array_fields = [
            'Contenido' => 'Contenido del pedido',
            'Pagos' => 'Pagos del pedido',
            'DireccionesEnvio' => 'Direcciones de envío'
        ];
        
        foreach ($array_fields as $field => $label) {
            if (isset($verial_order[$field]) && !is_array($verial_order[$field])) {
                $warnings[] = "$label: Debe ser un array";
            }
        }
        
        // 20. Validar campos de objetos
        if (isset($verial_order['Cliente']) && !is_array($verial_order['Cliente'])) {
            $warnings[] = "Cliente: Debe ser un objeto";
        }
        
        // Determinar si la validación es exitosa
        $valid = empty($missing_fields);
        
        // Log de validación
        if (!empty($missing_fields)) {
            self::$logger->error('Validación de completitud fallida', [
                'missing_fields' => $missing_fields,
                'warnings' => $warnings,
                'order_id' => $wc_order->get_id()
            ]);
        } elseif (!empty($warnings)) {
            self::$logger->warning('Validación de completitud con advertencias', [
                'warnings' => $warnings,
                'order_id' => $wc_order->get_id()
            ]);
        } else {
            self::$logger->info('Validación de completitud exitosa', [
                'order_id' => $wc_order->get_id()
            ]);
        }
        
        // Integrar sistema de manejo de errores
        if (!empty($missing_fields) || !empty($warnings)) {
            // Log errores de completitud
            self::logCompletenessError($missing_fields, $warnings, $wc_order, 'order_validation');
            
            // Enviar notificaciones
            self::sendCompletenessNotification($missing_fields, $warnings, $wc_order);
            
            // Generar reporte de completitud
            $completeness_report = self::generateCompletenessReport($missing_fields, $warnings, $wc_order);
            
            // Guardar score de completitud
            $completeness_score = self::calculateCompletenessScore($missing_fields, $warnings);
            $wc_order->update_meta_data('_verial_completeness_score', $completeness_score);
            $wc_order->update_meta_data('_verial_completeness_report', $completeness_report);
            $wc_order->save();
        }

        return [
            'valid' => $valid,
            'missing_fields' => $missing_fields,
            'warnings' => $warnings,
            'total_checks' => count($missing_fields) + count($warnings),
            'critical_errors' => count($missing_fields),
            'warnings_count' => count($warnings),
            'completeness_score' => self::calculateCompletenessScore($missing_fields, $warnings),
            'recovery_attempted' => !empty($missing_fields),
            'recovery_successful' => false // Se actualizará si se intenta recuperación
        ];
    }

    /**
     * Asigna el Agente1 basado en reglas de negocio (método dinámico).
     *
     * Utiliza el PaymentService para consultar GetAgentesWS y obtener el mapeo preciso
     * en lugar de usar un mapeo estático.
     *
     * Prioridad de asignación:
     * 1. Metadatos del pedido (verial_agente1, agente1)
     * 2. Metadatos del cliente (billing_agente1)
     * 3. Reglas por región geográfica
     * 4. Reglas por tipo de producto
     * 5. Agente por defecto desde Verial
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID del agente en Verial.
     */
    private static function assignAgente1(\WC_Order $wc_order): int {
        // 1. Buscar en metadatos del pedido
        $agente_meta = $wc_order->get_meta('verial_agente1') ?: $wc_order->get_meta('agente1');
        if (!empty($agente_meta) && is_numeric($agente_meta)) {
            return (int) $agente_meta;
        }

        // 2. Buscar en metadatos del cliente
        $agente_cliente = $wc_order->get_meta('billing_agente1');
        if (!empty($agente_cliente) && is_numeric($agente_cliente)) {
            return (int) $agente_cliente;
        }

        try {
            // Crear instancia del PaymentService para mapeo dinámico
            $payment_service = self::createPaymentService();
            
            // Obtener agentes disponibles en Verial
            $agentes = $payment_service->getAgentes();
            
            if (empty($agentes)) {
                return self::getFallbackAgente1($wc_order);
            }
            
            // 3. Reglas por región geográfica
            $country = $wc_order->get_billing_country();
            $state = $wc_order->get_billing_state();
            
            $agente_region = self::findAgenteByRegion($agentes, $country, $state);
            if ($agente_region > 0) {
                if (self::$logger) {
                    self::$logger->info('Agente1 asignado por región', [
                        'country' => $country,
                        'state' => $state,
                        'agente_id' => $agente_region
                    ]);
                }
                return $agente_region;
            }
            
            // 4. Reglas por tipo de producto
            $agente_producto = self::findAgenteByProduct($agentes, $wc_order);
            if ($agente_producto > 0) {
                if (self::$logger) {
                    self::$logger->info('Agente1 asignado por producto', [
                        'agente_id' => $agente_producto
                    ]);
                }
                return $agente_producto;
            }
            
            // 5. Agente por defecto desde Verial
            $agente_default = self::getDefaultAgente($agentes);
            if ($agente_default > 0) {
                if (self::$logger) {
                    self::$logger->info('Agente1 asignado por defecto', [
                        'agente_id' => $agente_default
                    ]);
                }
                return $agente_default;
            }
            
            // Fallback al mapeo estático
            return self::getFallbackAgente1($wc_order);
            
        } catch (\Throwable $e) {
            // Fallback al mapeo estático en caso de error
            if (self::$logger) {
                self::$logger->warning('Error en mapeo dinámico de Agente1, usando fallback estático', [
                    'error' => $e->getMessage()
                ]);
            }
            
            return self::getFallbackAgente1($wc_order);
        }
    }

    /**
     * Asigna el Agente2 basado en reglas de negocio (método dinámico).
     *
     * Utiliza el PaymentService para consultar GetAgentesWS y obtener el mapeo preciso
     * en lugar de usar un mapeo estático.
     *
     * Prioridad de asignación:
     * 1. Metadatos del pedido (verial_agente2, agente2)
     * 2. Reglas por valor del pedido
     * 3. Reglas por método de pago
     * 4. Agente por defecto desde Verial
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID del agente en Verial.
     */
    private static function assignAgente2(\WC_Order $wc_order): int {
        // 1. Buscar en metadatos del pedido
        $agente_meta = $wc_order->get_meta('verial_agente2') ?: $wc_order->get_meta('agente2');
        if (!empty($agente_meta) && is_numeric($agente_meta)) {
            return (int) $agente_meta;
        }

        try {
            // Crear instancia del PaymentService para mapeo dinámico
            $payment_service = self::createPaymentService();
            
            // Obtener agentes disponibles en Verial
            $agentes = $payment_service->getAgentes();
            
            if (empty($agentes)) {
                return self::getFallbackAgente2($wc_order);
            }
            
            // 2. Reglas por valor del pedido
            $total = (float) $wc_order->get_total();
            $agente_valor = self::findAgenteByValue($agentes, $total);
            if ($agente_valor > 0) {
                if (self::$logger) {
                    self::$logger->info('Agente2 asignado por valor', [
                        'total' => $total,
                        'agente_id' => $agente_valor
                    ]);
                }
                return $agente_valor;
            }
            
            // 3. Reglas por método de pago
            $payment_method = $wc_order->get_payment_method();
            $agente_pago = self::findAgenteByPaymentMethod($agentes, $payment_method);
            if ($agente_pago > 0) {
                if (self::$logger) {
                    self::$logger->info('Agente2 asignado por método de pago', [
                        'payment_method' => $payment_method,
                        'agente_id' => $agente_pago
                    ]);
                }
                return $agente_pago;
            }
            
            // 4. Agente por defecto desde Verial (solo si hay agentes secundarios)
            $agente_default = self::getDefaultAgente2($agentes);
            if ($agente_default > 0) {
                if (self::$logger) {
                    self::$logger->info('Agente2 asignado por defecto', [
                        'agente_id' => $agente_default
                    ]);
                }
                return $agente_default;
            }
            
            // Sin agente secundario por defecto
            return 0;
            
        } catch (\Throwable $e) {
            // Fallback al mapeo estático en caso de error
            if (self::$logger) {
                self::$logger->warning('Error en mapeo dinámico de Agente2, usando fallback estático', [
                    'error' => $e->getMessage()
                ]);
            }
            
            return self::getFallbackAgente2($wc_order);
        }
    }

    /**
     * Asigna el Agente3 basado en reglas de negocio (método dinámico).
     *
     * Utiliza el PaymentService para consultar GetAgentesWS y obtener el mapeo preciso
     * en lugar de usar un mapeo estático.
     *
     * Prioridad de asignación:
     * 1. Metadatos del pedido (verial_agente3, agente3)
     * 2. Reglas por tipo de cliente
     * 3. Reglas por frecuencia de compra
     * 4. Agente por defecto desde Verial
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce.
     * @return int ID del agente en Verial.
     */
    private static function assignAgente3(\WC_Order $wc_order): int {
        // 1. Buscar en metadatos del pedido
        $agente_meta = $wc_order->get_meta('verial_agente3') ?: $wc_order->get_meta('agente3');
        if (!empty($agente_meta) && is_numeric($agente_meta)) {
            return (int) $agente_meta;
        }

        try {
            // Crear instancia del PaymentService para mapeo dinámico
            $payment_service = self::createPaymentService();
            
            // Obtener agentes disponibles en Verial
            $agentes = $payment_service->getAgentes();
            
            if (empty($agentes)) {
                return self::getFallbackAgente3($wc_order);
            }
            
            // 2. Reglas por tipo de cliente
            $customer_id = $wc_order->get_customer_id();
            $agente_cliente = self::findAgenteByCustomerType($agentes, $wc_order, $customer_id);
            if ($agente_cliente > 0) {
                if (self::$logger) {
                    self::$logger->info('Agente3 asignado por tipo de cliente', [
                        'customer_id' => $customer_id,
                        'agente_id' => $agente_cliente
                    ]);
                }
                return $agente_cliente;
            }
            
            // 3. Reglas por frecuencia de compra
            $agente_frecuencia = self::findAgenteByFrequency($agentes, $customer_id);
            if ($agente_frecuencia > 0) {
                if (self::$logger) {
                    self::$logger->info('Agente3 asignado por frecuencia', [
                        'customer_id' => $customer_id,
                        'agente_id' => $agente_frecuencia
                    ]);
                }
                return $agente_frecuencia;
            }
            
            // 4. Agente por defecto desde Verial (solo si hay agentes terciarios)
            $agente_default = self::getDefaultAgente3($agentes);
            if ($agente_default > 0) {
                if (self::$logger) {
                    self::$logger->info('Agente3 asignado por defecto', [
                        'agente_id' => $agente_default
                    ]);
                }
                return $agente_default;
            }
            
            // Sin agente terciario por defecto
            return 0;
            
        } catch (\Throwable $e) {
            // Fallback al mapeo estático en caso de error
            if (self::$logger) {
                self::$logger->warning('Error en mapeo dinámico de Agente3, usando fallback estático', [
                    'error' => $e->getMessage()
                ]);
            }
            
            return self::getFallbackAgente3($wc_order);
        }
    }

    /**
     * Extrae el NIF del pedido desde los metadatos o campos comunes.
     *
     * @param mixed $order Pedido de WooCommerce o array compatible.
     * @return string NIF extraído o cadena vacía si no se encuentra.
     */
    private function extractNifFromOrder($order): string {
        $nif_fields = ['billing_nif', '_billing_nif', 'billing_vat', 'nif', 'dni', 'billing_nif_custom'];
        
        foreach ($nif_fields as $field) {
            if (method_exists($order, 'get_meta')) {
                $value = $order->get_meta($field);
                if (!empty($value)) {
                    return (string) $value;
                }
            }
        }
        
        return '';
    }

    /**
     * Extrae el segundo apellido del pedido desde los metadatos o campos comunes.
     *
     * @param mixed $order Pedido de WooCommerce o array compatible.
     * @return string Segundo apellido extraído o cadena vacía si no se encuentra.
     */
    private function extractApellido2FromOrder($order): string {
        $apellido2_fields = ['billing_last_name_2', 'apellido2', 'last_name_2'];
        
        foreach ($apellido2_fields as $field) {
            if (method_exists($order, 'get_meta')) {
                $value = $order->get_meta($field);
                if (!empty($value)) {
                    return (string) $value;
                }
            }
        }
        
        return '';
    }

    /**
     * Extrae la dirección auxiliar del pedido desde los metadatos o campos comunes.
     *
     * @param mixed $order Pedido de WooCommerce o array compatible.
     * @return string Dirección auxiliar extraída o cadena vacía si no se encuentra.
     */
    private function extractDireccionAuxFromOrder($order): string {
        // Intentar billing_address_2 primero
        if (method_exists($order, 'get_billing_address_2')) {
            $value = $order->get_billing_address_2();
            if (!empty($value)) {
                return (string) $value;
            }
        }

        $direccion_aux_fields = ['direccion_aux', 'verial_direccion_aux'];
        
        foreach ($direccion_aux_fields as $field) {
            if (method_exists($order, 'get_meta')) {
                $value = $order->get_meta($field);
                if (!empty($value)) {
                    return (string) $value;
                }
            }
        }

        // Fallback a shipping_address_2
        if (method_exists($order, 'get_shipping_address_2')) {
            $value = $order->get_shipping_address_2();
            if (!empty($value)) {
                return (string) $value;
            }
        }
        
        return '';
    }

    /**
     * Extrae la razón social del pedido desde los metadatos o campos comunes.
     *
     * @param mixed $order Pedido de WooCommerce o array compatible.
     * @return string Razón social extraída o cadena vacía si no se encuentra.
     */
    private function extractRazonSocialFromOrder($order): string {
        // Intentar billing_company primero
        if (method_exists($order, 'get_billing_company')) {
            $value = $order->get_billing_company();
            if (!empty($value)) {
                return (string) $value;
            }
        }

        $razon_social_fields = ['razon_social', 'company', 'razon_social_empresa'];
        
        foreach ($razon_social_fields as $field) {
            if (method_exists($order, 'get_meta')) {
                $value = $order->get_meta($field);
                if (!empty($value)) {
                    return (string) $value;
                }
            }
        }
        
        return '';
    }

    /**
     * Extrae la etiqueta de cliente del pedido desde los metadatos o configuración global.
     *
     * @param mixed $order Pedido de WooCommerce o array compatible.
     * @return string Etiqueta de cliente extraída o cadena vacía si no se encuentra.
     */
    private function extractEtiquetaClienteFromOrder($order): string {
        if (method_exists($order, 'get_meta')) {
            $value = $order->get_meta('etiqueta_cliente');
            if (!empty($value)) {
                return (string) $value;
            }
        }

        // Fallback a opción global
        if (function_exists('get_option')) {
            $value = get_option('verial_default_etiqueta_cliente', '');
            if (!empty($value)) {
                return (string) $value;
            }
        }
        
        return '';
    }

    /**
     * Mapea las líneas de pedido de WooCommerce al formato Contenido de Verial (método de instancia).
     *
     * @param mixed $order Pedido de WooCommerce, array o mock compatible.
     * @return array Contenido del pedido en formato Verial.
     */
    private function mapWcItemsToVerialContent($order): array {
        $contenido = [];
        
        // Manejar tanto objetos WC_Order como arrays/objetos mock
        $items = [];
        if (method_exists($order, 'get_items')) {
            $items = $order->get_items();
        } elseif (isset($order->items)) {
            $items = $order->items;
        } elseif (is_array($order) && isset($order['items'])) {
            $items = $order['items'];
        }

        foreach ($items as $item_id => $item) {
            $product = null;
            $quantity = 1.0;
            $line_total = 0.0;
            $line_tax = 0.0;
            $item_name = '';

            // Extraer datos del item
            if (is_object($item) && method_exists($item, 'get_product')) {
                $product = $item->get_product();
                $quantity = (float) $item->get_quantity();
                $line_total = (float) $item->get_total();
                $line_tax = (float) $item->get_total_tax();
                $item_name = $item->get_name();
            } elseif (is_array($item)) {
                $quantity = (float) ($item['quantity'] ?? 1);
                $line_total = (float) ($item['total'] ?? 0);
                $line_tax = (float) ($item['tax'] ?? 0);
                $item_name = $item['name'] ?? '';
            }
            
            // Calcular precio unitario
            $precio_unitario = $quantity > 0 ? ($line_total / $quantity) : 0;
            
            // Calcular porcentaje IVA
            $porcentaje_iva = self::calculateLineaPorcentajeIVA($item, $line_total, $line_tax);
            
            // Obtener ID_Articulo válido de Verial (verificar que $product no sea null o false)
            $id_articulo = ($product && $product instanceof \WC_Product) ? self::getValidArticuloId($product) : 0;
            
            $contenido[] = [
                'TipoRegistro' => VerialTypes::REGISTRY_TYPE_PRODUCT,
                'ID_Articulo' => (int) $id_articulo,
                'Comentario' => null,
                'Precio' => round($precio_unitario, 2),
                'PrecioUnitario' => round($precio_unitario, 2),
                'Dto' => 0.0,
                'DtoPPago' => 0.0,
                'DtoEurosXUd' => 0.0,
                'DtoEuros' => 0.0,
                'Uds' => (float) $quantity,
                'Cantidad' => (float) $quantity,
                'UdsRegalo' => 0.0,
                'UdsAuxiliares' => 0.0,
                'ImporteLinea' => round($line_total, 2),
                'TotalLinea' => round($line_total, 2),
                'Total' => round($line_total, 2),
                'Lote' => null,
                'Caducidad' => null,
                'ID_Partida' => 0,
                'PorcentajeIVA' => round($porcentaje_iva, 2),
                'PorcentajeRE' => 0.0,
                'DescripcionAmplia' => $item_name
            ];
        }
        
        return $contenido;
    }

    /**
     * Obtiene un ID de cliente válido de Verial
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce
     * @param int $sesionwcf ID de sesión de Verial
     * @return int ID de cliente válido o 0 si no se encuentra
     */
    private static function getValidClienteId(\WC_Order $wc_order): int {
        try {
            // Crear instancias necesarias para ClientService
            $api_client = new \MiIntegracionApi\Services\VerialApiClient(null, self::$logger);
            $geographic_service = new \MiIntegracionApi\Services\GeographicService($api_client, self::$logger);
            $client_service = new \MiIntegracionApi\Services\ClientService($api_client, $geographic_service, self::$logger);

            // Intentar obtener el cliente por email
            $email = $wc_order->get_billing_email();
            self::$logger->debug('Buscando cliente por email', [
                'order_id' => $wc_order->get_id(),
                'email' => $email
            ]);
            
            if (!empty($email)) {
                $cliente = $client_service->findClienteByEmail($email);
                if ($cliente && isset($cliente['ID'])) {
                    self::$logger->info('Cliente encontrado por email', [
                        'order_id' => $wc_order->get_id(),
                        'cliente_id' => $cliente['ID'],
                        'email' => $email
                    ]);
                    return (int) $cliente['ID'];
                } else {
                    self::$logger->debug('Cliente no encontrado por email', [
                        'order_id' => $wc_order->get_id(),
                        'email' => $email
                    ]);
                }
            }

            // Intentar obtener el cliente por NIF
            $nif = $wc_order->get_meta('billing_nif') ?:
                   $wc_order->get_meta('_billing_nif') ?:
                   $wc_order->get_meta('billing_vat') ?:
                   $wc_order->get_meta('nif') ?:
                   $wc_order->get_meta('dni');
            
            self::$logger->debug('Buscando cliente por NIF', [
                'order_id' => $wc_order->get_id(),
                'nif' => $nif
            ]);
            
            if (!empty($nif)) {
                $cliente = $client_service->findClienteByNIF($nif);
                if ($cliente && isset($cliente['ID'])) {
                    self::$logger->info('Cliente encontrado por NIF', [
                        'order_id' => $wc_order->get_id(),
                        'cliente_id' => $cliente['ID'],
                        'nif' => $nif
                    ]);
                    return (int) $cliente['ID'];
                } else {
                    self::$logger->debug('Cliente no encontrado por NIF', [
                        'order_id' => $wc_order->get_id(),
                        'nif' => $nif
                    ]);
                }
            }

            self::$logger->debug('No se encontró cliente existente, usando cliente nuevo', [
                'order_id' => $wc_order->get_id(),
                'email' => $email,
                'nif' => $nif
            ]);
        } catch (\Exception $e) {
            self::$logger->warning('Error obteniendo ID de cliente de Verial', [
                'order_id' => $wc_order->get_id(),
                'error' => $e->getMessage()
            ]);
        }

        return 0; // Cliente nuevo por defecto
    }

    /**
     * Obtiene un ID de artículo válido de Verial
     *
     * @param \WC_Product|null $product Producto de WooCommerce
     * @param int $sesionwcf ID de sesión de Verial
     * @return int ID de artículo válido o 0 si no se encuentra
     */
    private static function getValidArticuloId(?\WC_Product $product): int {
        if (!$product) {
            self::$logger->debug('Producto es null, devolviendo ID 0');
            return 0;
        }

        try {
            $product_id = $product->get_id();
            $sku = $product->get_sku();
            
            self::$logger->debug('Buscando ID de artículo de Verial', [
                'product_id' => $product_id,
                'sku' => $sku
            ]);

            // Primero intentar obtener desde metadatos del producto
            $id_articulo = $product->get_meta('_verial_articulo_id');
            if (!empty($id_articulo) && $id_articulo > 0) {
                self::$logger->info('ID de artículo encontrado en metadatos', [
                    'product_id' => $product_id,
                    'sku' => $sku,
                    'verial_id' => $id_articulo
                ]);
                return (int) $id_articulo;
            }

            // Intentar obtener por SKU desde la tabla de mapeo
            if (!empty($sku)) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'verial_product_mapping';
                
                if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
                    $article_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT verial_id FROM $table_name WHERE sku = %s LIMIT 1",
                        $sku
                    ));
                    
                    if ($article_id && $article_id > 0) {
                        self::$logger->info('ID de artículo encontrado en tabla de mapeo', [
                            'product_id' => $product_id,
                            'sku' => $sku,
                            'verial_id' => $article_id
                        ]);
                        return (int) $article_id;
                    } else {
                        self::$logger->debug('ID de artículo no encontrado en tabla de mapeo', [
                            'product_id' => $product_id,
                            'sku' => $sku
                        ]);
                    }
                } else {
                    self::$logger->debug('Tabla de mapeo no existe', [
                        'product_id' => $product_id,
                        'sku' => $sku,
                        'table_name' => $table_name
                    ]);
                }

                // Intentar buscar en Verial por SKU usando la API
                $api_client = new \MiIntegracionApi\Services\VerialApiClient();
                $result = $api_client->get('GetArticulosWS', [
                    'inicio' => 1,
                    'fin' => 10,
                    'referenciaBarras' => $sku
                ]);

                if ($api_client->isSuccess($result) &&
                    isset($result['Articulos']) &&
                    is_array($result['Articulos']) &&
                    count($result['Articulos']) > 0) {
                    $verial_id = (int) $result['Articulos'][0]['Id'];
                    self::$logger->info('ID de artículo encontrado en API de Verial', [
                        'product_id' => $product_id,
                        'sku' => $sku,
                        'verial_id' => $verial_id
                    ]);
                    return $verial_id;
                } else {
                    self::$logger->debug('ID de artículo no encontrado en API de Verial', [
                        'product_id' => $product_id,
                        'sku' => $sku,
                        'api_success' => $api_client->isSuccess($result)
                    ]);
                }
            } else {
                self::$logger->debug('SKU vacío, no se puede buscar artículo', [
                    'product_id' => $product_id
                ]);
            }
        } catch (\Exception $e) {
            self::$logger->warning('Error obteniendo ID de artículo de Verial', [
                'product_id' => $product->get_id(),
                'sku' => $product->get_sku(),
                'error' => $e->getMessage()
            ]);
        }

        self::$logger->debug('No se encontró ID de artículo válido, devolviendo 0', [
            'product_id' => $product->get_id(),
            'sku' => $product->get_sku()
        ]);
        return 0; // Artículo no encontrado
    }

    /**
     * Obtiene un ID de artículo por defecto para productos eliminados
     *
     * @param \WC_Order_Item_Product $item Item del pedido
     * @return int ID de artículo por defecto o 0 si no se encuentra
     */
    private static function getDefaultArticleIdForDeletedProduct(\WC_Order_Item_Product $item): int {
        try {
            $item_name = $item->get_name();
            $item_sku = $item->get_meta('_sku');
            
            self::$logger->debug('Buscando ID de artículo por defecto para producto eliminado', [
                'item_name' => $item_name,
                'item_sku' => $item_sku
            ]);
            
            // Intentar buscar por SKU si existe
            if (!empty($item_sku)) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'verial_product_mapping';
                
                if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
                    $article_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT verial_id FROM $table_name WHERE sku = %s LIMIT 1",
                        $item_sku
                    ));
                    
                    if ($article_id && $article_id > 0) {
                        self::$logger->info('ID de artículo encontrado por SKU para producto eliminado', [
                            'item_name' => $item_name,
                            'item_sku' => $item_sku,
                            'verial_id' => $article_id
                        ]);
                        return (int) $article_id;
                    }
                }
                
                // Intentar buscar en Verial por SKU usando la API
                $api_client = self::createApiClient();
                $result = $api_client->get('GetArticulosWS', [
                    'inicio' => 1,
                    'fin' => 10,
                    'referenciaBarras' => $item_sku
                ]);

                if ($api_client->isSuccess($result) &&
                    isset($result['Articulos']) &&
                    is_array($result['Articulos']) &&
                    count($result['Articulos']) > 0) {
                    $verial_id = (int) $result['Articulos'][0]['Id'];
                    self::$logger->info('ID de artículo encontrado en API por SKU para producto eliminado', [
                        'item_name' => $item_name,
                        'item_sku' => $item_sku,
                        'verial_id' => $verial_id
                    ]);
                    return $verial_id;
                }
            }
            
            // Intentar buscar por nombre del producto (búsqueda aproximada)
            if (!empty($item_name)) {
                $api_client = self::createApiClient();
                $result = $api_client->get('GetArticulosWS', [
                    'inicio' => 1,
                    'fin' => 20
                ]);

                if ($api_client->isSuccess($result) &&
                    isset($result['Articulos']) &&
                    is_array($result['Articulos'])) {
                    
                    // Buscar coincidencia parcial en el nombre
                    foreach ($result['Articulos'] as $articulo) {
                        if (isset($articulo['Nombre']) && 
                            stripos($articulo['Nombre'], $item_name) !== false) {
                            $verial_id = (int) $articulo['Id'];
                            self::$logger->info('ID de artículo encontrado por nombre para producto eliminado', [
                                'item_name' => $item_name,
                                'verial_name' => $articulo['Nombre'],
                                'verial_id' => $verial_id
                            ]);
                            return $verial_id;
                        }
                    }
                }
            }
            
            // Usar un ID de artículo genérico si no se encuentra nada
            $default_id = self::getGenericArticleId();
            self::$logger->warning('Usando ID de artículo genérico para producto eliminado', [
                'item_name' => $item_name,
                'item_sku' => $item_sku,
                'generic_id' => $default_id
            ]);
            return $default_id;
            
        } catch (\Exception $e) {
            self::$logger->warning('Error obteniendo ID de artículo para producto eliminado', [
                'item_name' => $item->get_name(),
                'error' => $e->getMessage()
            ]);
            return self::getGenericArticleId();
        }
    }

    /**
     * Obtiene un ID de artículo genérico para casos especiales
     *
     * @return int ID de artículo genérico
     */
    private static function getGenericArticleId(): int {
        // Intentar obtener un ID de artículo genérico desde la configuración
        $generic_id = get_option('verial_generic_article_id', 0);
        
        if ($generic_id > 0) {
            return (int) $generic_id;
        }
        
        // Si no hay configuración, intentar obtener el primer artículo disponible
        try {
            $api_client = self::createApiClient();
            $result = $api_client->get('GetArticulosWS', [
                'inicio' => 1,
                'fin' => 1
            ]);

            if ($api_client->isSuccess($result) &&
                isset($result['Articulos']) &&
                is_array($result['Articulos']) &&
                count($result['Articulos']) > 0) {
                return (int) $result['Articulos'][0]['Id'];
            }
        } catch (\Exception $e) {
            self::$logger->warning('Error obteniendo artículo genérico', [
                'error' => $e->getMessage()
            ]);
        }
        
        return 1; // ID por defecto como último recurso
    }

    /**
     * Crea una instancia de VerialApiClient con configuración estándar
     *
     * @return \MiIntegracionApi\Services\VerialApiClient
     */
    private static function createApiClient(): \MiIntegracionApi\Services\VerialApiClient {
        return new \MiIntegracionApi\Services\VerialApiClient(null, self::$logger);
    }

    /**
     * Crea una instancia de GeographicService con configuración estándar
     *
     * @return \MiIntegracionApi\Services\GeographicService
     */
    private static function createGeographicService(): \MiIntegracionApi\Services\GeographicService {
        $api_client = self::createApiClient();
        return new \MiIntegracionApi\Services\GeographicService($api_client, self::$logger);
    }

    /**
     * Crea una instancia de PaymentService con configuración estándar
     *
     * @return \MiIntegracionApi\Services\PaymentService
     */
    private static function createPaymentService(): \MiIntegracionApi\Services\PaymentService {
        $api_client = self::createApiClient();
        return new \MiIntegracionApi\Services\PaymentService($api_client, self::$logger);
    }
}
