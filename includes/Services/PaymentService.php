<?php
/**
 * Servicio para la gestión de pagos en Verial
 *
 * Este servicio proporciona métodos para interactuar con el sistema de pagos de Verial,
 * incluyendo la gestión de métodos de pago, procesamiento de pagos y cálculo de envíos.
 *
 * @package    MiIntegracionApi
 * @subpackage Services
 * @since      1.0.0
 * @version    1.1.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\Services;

use MiIntegracionApi\Services\VerialApiClient;
use MiIntegracionApi\Helpers\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase PaymentService
 *
 * Gestiona todas las operaciones relacionadas con pagos en el sistema Verial,
 * incluyendo la integración con WooCommerce y el mapeo de métodos de pago.
 *
 * @package MiIntegracionApi\Services
 * @since   1.0.0
 */
class PaymentService {
    /**
     * Cliente de la API de Verial
     *
     * @var VerialApiClient
     * @since 1.0.0
     */
    private VerialApiClient $api_client;

    /**
     * Instancia del logger para registro de eventos
     *
     * @var Logger
     * @since 1.0.0
     */
    private Logger $logger;

    /**
     * Cache interno para almacenar datos de pagos
     *
     * @var array<string, array>
     * @since 1.0.0
     */
    private array $cache = [];

    /**
     * Constructor del servicio de pagos
     *
     * @param VerialApiClient $api_client Cliente de la API de Verial
     * @param Logger|null $logger Instancia del logger (opcional)
     * @since 1.0.0
     */
    public function __construct(VerialApiClient $api_client, ?Logger $logger = null) {
        $this->api_client = $api_client;
        $this->logger = $logger ?: new Logger('payment_service');
    }

    /**
     * Obtiene los métodos de pago disponibles en Verial
     *
     * Los resultados se almacenan en caché para optimizar consultas posteriores.
     *
     * @return array<
     *     array{
     *         Id: int,
     *         Nombre: string,
     *         Activo: bool,
     *         PorDefecto: bool,
     *         Codigo: string
     *     }
     * > Lista de métodos de pago disponibles
     * @since 1.0.0
     */
    public function getMetodosPago(): array {
        if (isset($this->cache['metodos_pago'])) {
            return $this->cache['metodos_pago'];
        }

        $response = $this->api_client->get('GetMetodosPagoWS');
        
        if ($this->api_client->isSuccess($response)) {
            $this->cache['metodos_pago'] = $response['MetodosPago'] ?? [];
            $this->logger->info('Métodos de pago obtenidos', ['count' => count($this->cache['metodos_pago'])]);
            return $this->cache['metodos_pago'];
        }

        $this->logger->error('Error obteniendo métodos de pago', [
            'error' => $this->api_client->getErrorMessage($response)
        ]);
        return [];
    }

    /**
     * Busca un método de pago por su nombre
     *
     * La búsqueda es insensible a mayúsculas/minúsculas y puede encontrar coincidencias parciales.
     *
     * @param string $nombre Nombre o parte del nombre del método de pago
     * @return array{
     *     Id: int,
     *     Nombre: string,
     *     Activo: bool,
     *     PorDefecto: bool,
     *     Codigo: string
     * }|null Datos del método de pago o null si no se encuentra
     * @since 1.0.0
     */
    public function findMetodoPagoByName(string $nombre): ?array {
        $metodos = $this->getMetodosPago();
        
        foreach ($metodos as $metodo) {
            if (stripos($metodo['Nombre'] ?? '', $nombre) !== false) {
                return $metodo;
            }
        }

        return null;
    }

    /**
     * Mapea un método de pago de WooCommerce a un ID de método de pago de Verial
     *
     * @param string $wc_payment_method Identificador del método de pago de WooCommerce
     * @return int ID del método de pago en Verial
     * @since 1.0.0
     */
    public function mapWooCommercePaymentMethod(string $wc_payment_method): int {
        // Mapeo básico por defecto
        $basic_mapping = [
            'bacs' => 'Transferencia bancaria',
            'cheque' => 'Cheque',
            'cod' => 'Contra reembolso',
            'paypal' => 'PayPal',
            'stripe' => 'Tarjeta de crédito',
            'credit_card' => 'Tarjeta de crédito'
        ];

        $metodo_nombre = $basic_mapping[$wc_payment_method] ?? 'Tarjeta de crédito';
        
        // Buscar en Verial
        $metodo_verial = $this->findMetodoPagoByName($metodo_nombre);
        
        if ($metodo_verial) {
            return $metodo_verial['Id'];
        }

        // Fallback: devolver ID por defecto basado en mapeo estático
        $fallback_mapping = [
            'bacs' => 1,
            'cheque' => 2,
            'cod' => 3,
            'paypal' => 4,
            'stripe' => 5,
            'credit_card' => 5
        ];

        return $fallback_mapping[$wc_payment_method] ?? 5;
    }

    /**
     * Crea un nuevo pago en Verial para un documento de cliente
     *
     * @param int $id_doc_cliente ID del documento de cliente en Verial
     * @param int $id_metodo_pago ID del método de pago en Verial
     * @param float $importe Importe del pago
     * @param string $fecha Fecha del pago en formato Y-m-d (opcional, por defecto hoy)
     * @return bool true si el pago se creó correctamente, false en caso contrario
     * @since 1.0.0
     */
    public function createPago(int $id_doc_cliente, int $id_metodo_pago, float $importe, string $fecha = ''): bool {
        if (empty($fecha)) {
            $fecha = date('Y-m-d');
        }

        $data = [
            'ID_DocCli' => $id_doc_cliente,
            'ID_MetodoPago' => $id_metodo_pago,
            'Fecha' => $fecha,
            'Importe' => $importe
        ];

        $response = $this->api_client->post('NuevoPagoWS', $data);
        
        if ($this->api_client->isSuccess($response)) {
            $this->logger->info('Pago creado exitosamente', [
                'id_documento' => $id_doc_cliente,
                'importe' => $importe,
                'metodo_pago' => $id_metodo_pago
            ]);
            return true;
        }

        $this->logger->error('Error creando pago', [
            'id_documento' => $id_doc_cliente,
            'error' => $this->api_client->getErrorMessage($response)
        ]);
        return false;
    }

    /**
     * Procesa un pago de WooCommerce en Verial
     *
     * @param \WC_Order $wc_order Instancia del pedido de WooCommerce
     * @param int $verial_doc_id ID del documento en Verial
     * @return bool true si el pago se procesó correctamente, false en caso contrario
     * @since 1.0.0
     */
    public function processWooCommercePayment(\WC_Order $wc_order, int $verial_doc_id): bool {
        // Obtener información del pago
        $payment_method = $wc_order->get_payment_method();
        $total = $wc_order->get_total();
        $date_paid = $wc_order->get_date_paid();
        
        if (!$date_paid) {
            $this->logger->warning('Pedido no está marcado como pagado en WooCommerce', [
                'order_id' => $wc_order->get_id()
            ]);
            return false;
        }

        // Mapear método de pago
        $verial_payment_method = $this->mapWooCommercePaymentMethod($payment_method);
        
        // Crear pago en Verial
        return $this->createPago(
            $verial_doc_id,
            $verial_payment_method,
            (float) $total,
            $date_paid->format('Y-m-d')
        );
    }

    /**
     * Obtiene la lista de agentes comerciales de Verial
     *
     * Los resultados se almacenan en caché para optimizar consultas posteriores.
     *
     * @return array<
     *     array{
     *         Id: int,
     *         Nombre: string,
     *         Email: string,
     *         Activo: bool
     *     }
     * > Lista de agentes comerciales
     * @since 1.0.0
     */
    public function getAgentes(): array {
        if (isset($this->cache['agentes'])) {
            return $this->cache['agentes'];
        }

        $response = $this->api_client->get('GetAgentesWS');
        
        if ($this->api_client->isSuccess($response)) {
            $this->cache['agentes'] = $response['Agentes'] ?? [];
            $this->logger->info('Agentes obtenidos', ['count' => count($this->cache['agentes'])]);
            return $this->cache['agentes'];
        }

        return [];
    }

    /**
     * Obtiene las formas de envío disponibles en Verial
     *
     * Los resultados se almacenan en caché para optimizar consultas posteriores.
     *
     * @return array<
     *     array{
     *         Id: int,
     *         Nombre: string,
     *         Activo: bool,
     *         Destinos: array<
     *             array{
     *                 Id: int,
     *                 Nombre: string,
     *                 Fijo: float,
     *                 PorUnidad: float,
     *                 PorPeso: float,
     *                 Minimo: float,
     *                 Gratis: float
     *             }
     *         >
     *     }
     * > Lista de formas de envío con sus destinos y tarifas
     * @since 1.0.0
     */
    public function getFormasEnvio(): array {
        if (isset($this->cache['formas_envio'])) {
            return $this->cache['formas_envio'];
        }

        $response = $this->api_client->get('GetFormasEnvioWS');
        
        if ($this->api_client->isSuccess($response)) {
            $this->cache['formas_envio'] = $response['FormasEnvio'] ?? [];
            $this->logger->info('Formas de envío obtenidas', ['count' => count($this->cache['formas_envio'])]);
            return $this->cache['formas_envio'];
        }

        return [];
    }

    /**
     * Mapea un método de envío de WooCommerce a Verial
     *
     * @param string $wc_shipping_method Identificador del método de envío de WooCommerce
     * @return array{
     *     ID_FormaEnvio: int,
     *     ID_Destino: int
     } Datos del método de envío en Verial
     * @since 1.0.0
     */
    public function mapWooCommerceShippingMethod(string $wc_shipping_method): array {
        $formas_envio = $this->getFormasEnvio();
        
        // Mapeo básico
        $shipping_mapping = [
            'flat_rate' => 'Envío estándar',
            'free_shipping' => 'Envío gratuito',
            'local_pickup' => 'Recogida en tienda'
        ];

        $forma_nombre = $shipping_mapping[$wc_shipping_method] ?? 'Envío estándar';
        
        // Buscar en Verial
        foreach ($formas_envio as $forma) {
            if (stripos($forma['Nombre'] ?? '', $forma_nombre) !== false) {
                return [
                    'ID_FormaEnvio' => $forma['Id'],
                    'ID_Destino' => !empty($forma['Destinos']) ? $forma['Destinos'][0]['Id'] : 0
                ];
            }
        }

        // Fallback
        return [
            'ID_FormaEnvio' => 1,
            'ID_Destino' => 1
        ];
    }

    /**
     * Calcula los gastos de envío según las reglas de Verial
     *
     * @param int $id_forma_envio ID de la forma de envío en Verial
     * @param int $id_destino ID del destino de envío
     * @param float $peso Peso total del pedido en kg
     * @param int $unidades Número total de unidades del pedido
     * @param float $importe_bruto Importe bruto del pedido (sin envío)
     * @return float Coste total del envío
     * @since 1.0.0
     */
    public function calculateShippingCost(int $id_forma_envio, int $id_destino, float $peso, int $unidades, float $importe_bruto): float {
        $formas_envio = $this->getFormasEnvio();
        
        foreach ($formas_envio as $forma) {
            if (($forma['Id'] ?? 0) === $id_forma_envio) {
                foreach ($forma['Destinos'] ?? [] as $destino) {
                    if (($destino['Id'] ?? 0) === $id_destino) {
                        // Calcular según las reglas de Verial
                        $costo = $destino['Fijo'] ?? 0;
                        $costo += ($destino['PorUnidad'] ?? 0) * $unidades;
                        $costo += ($destino['PorPeso'] ?? 0) * $peso;
                        
                        // Aplicar mínimo
                        $minimo = $destino['Minimo'] ?? 0;
                        if ($costo < $minimo) {
                            $costo = $minimo;
                        }
                        
                        // Verificar si es gratis por importe
                        $gratis_desde = $destino['Gratis'] ?? 0;
                        if ($gratis_desde > 0 && $importe_bruto >= $gratis_desde) {
                            $costo = 0;
                        }
                        
                        return $costo;
                    }
                }
            }
        }

        return 0;
    }

    /**
     * Obtiene los siguientes números de documento disponibles en Verial
     *
     * @return array{
     *     Albaran: int,
     *     Factura: int,
     *     Pedido: int,
     *     Presupuesto: int
     *     } Números de documento disponibles
     * @since 1.0.0
     */
    public function getNextNumDocs(): array {
        $response = $this->api_client->get('GetNextNumDocsWS');
        
        if ($this->api_client->isSuccess($response)) {
            return $response['Numeros'] ?? [];
        }

        return [];
    }

    /**
     * Limpia la caché interna del servicio de pagos
     *
     * Útil para forzar la actualización de datos desde la API en la próxima petición.
     *
     * @return void
     * @since 1.0.0
     */
    public function clearCache(): void {
        $this->cache = [];
        $this->logger->info('Cache de pagos limpiado');
    }
}