<?php
/**
 * Servicio para seguimiento de pedidos en Verial
 *
 * Este servicio proporciona métodos para realizar el seguimiento de pedidos,
 * consultar estados, obtener historiales y documentos relacionados con pedidos
 * en el sistema Verial.
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
 * Clase TrackingService
 *
 * Gestiona todas las operaciones relacionadas con el seguimiento de pedidos
 * en el sistema Verial, incluyendo consulta de estados, historiales y documentos.
 *
 * @package MiIntegracionApi\Services
 * @since   1.0.0
 */
class TrackingService {
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
     * Constructor del servicio de seguimiento
     *
     * @param VerialApiClient $api_client Cliente de la API de Verial
     * @param Logger|null $logger Instancia del logger (opcional)
     * @since 1.0.0
     */
    public function __construct(VerialApiClient $api_client, ?Logger $logger = null) {
        $this->api_client = $api_client;
        $this->logger = $logger ?: new Logger('tracking_service');
    }

    /**
     * Consulta el estado de múltiples pedidos
     *
     * @param array<array{Id?: int, Referencia?: string}> $pedidos Array de pedidos a consultar.
     *        Cada elemento debe contener al menos uno de estos campos:
     *        - Id: ID numérico del pedido
     *        - Referencia: Referencia alfanumérica del pedido
     * @return array<array{
     *     Id: int,
     *     Referencia: string,
     *     Estado: int,
     *     EstadoTexto: string,
     *     Fecha: string,
     *     UrlSeguimiento: ?string,
     *     Mensaje: ?string
     * }> Lista de estados de pedidos con sus detalles
     * @since 1.0.0
     */
    public function getEstadoPedidos(array $pedidos): array {
        $data = ['Pedidos' => $pedidos];

        $response = $this->api_client->post('EstadoPedidosWS', $data);
        
        if ($this->api_client->isSuccess($response)) {
            $estados = $response['Pedidos'] ?? [];
            $this->logger->info('Estados de pedidos consultados', ['count' => count($estados)]);
            return $estados;
        }

        $this->logger->error('Error consultando estados de pedidos', [
            'error' => $this->api_client->getErrorMessage($response),
            'pedidos_count' => count($pedidos)
        ]);
        return [];
    }

    /**
     * Consulta el estado de un pedido individual por ID o referencia
     *
     * @param int|null $id_pedido ID numérico del pedido (opcional si se proporciona referencia)
     * @param string $referencia Referencia alfanumérica del pedido (opcional si se proporciona ID)
     * @return array{
     *     Id: int,
     *     Referencia: string,
     *     Estado: int,
     *     EstadoTexto: string,
     *     Fecha: string,
     *     UrlSeguimiento: ?string,
     *     Mensaje: ?string
     * }|null Datos del estado del pedido o null si no se encuentra
     * @throws \InvalidArgumentException Si no se proporciona ni ID ni referencia
     * @since 1.0.0
     */
    public function getEstadoPedido($id_pedido = null, string $referencia = ''): ?array {
        $pedido_data = [];
        
        if ($id_pedido !== null) {
            $pedido_data['Id'] = $id_pedido;
        }
        if (!empty($referencia)) {
            $pedido_data['Referencia'] = $referencia;
        }

        if (empty($pedido_data)) {
            $this->logger->error('Debe proporcionar ID o referencia del pedido');
            return null;
        }

        $estados = $this->getEstadoPedidos([$pedido_data]);
        
        return !empty($estados) ? $estados[0] : null;
    }

    /**
     * Obtiene el historial de pedidos con opciones de filtrado
     *
     * @param int $id_cliente ID del cliente (0 para todos los clientes)
     * @param string $fechadesde Fecha de inicio del rango (formato YYYY-MM-DD)
     * @param string $fechahasta Fecha de fin del rango (formato YYYY-MM-DD)
     * @param bool $allareasventa Si es true, incluye pedidos de todas las áreas de venta
     * @return array<array{
     *     Id: int,
     *     Referencia: string,
     *     Fecha: string,
     *     Estado: int,
     *     EstadoTexto: string,
     *     ImporteTotal: float,
     *     Moneda: string,
     *     UrlDetalle: ?string
     * }> Lista de documentos/pedidos que coinciden con los criterios
     * @since 1.0.0
     */
    public function getHistorialPedidos(int $id_cliente = 0, string $fechadesde = '', string $fechahasta = '', bool $allareasventa = false): array {
        $params = [];
        
        if ($id_cliente > 0) {
            $params['id_cliente'] = $id_cliente;
        }
        if (!empty($fechadesde)) {
            $params['fechadesde'] = $fechadesde;
        }
        if (!empty($fechahasta)) {
            $params['fechahasta'] = $fechahasta;
        }
        if ($allareasventa) {
            $params['allareasventa'] = 'true';
        }

        $response = $this->api_client->get('GetHistorialPedidosWS', $params);
        
        if ($this->api_client->isSuccess($response)) {
            $documentos = $response['Documentos'] ?? [];
            $this->logger->info('Historial de pedidos obtenido', ['count' => count($documentos)]);
            return $documentos;
        }

        $this->logger->error('Error obteniendo historial de pedidos', [
            'error' => $this->api_client->getErrorMessage($response),
            'params' => $params
        ]);
        return [];
    }

    /**
     * Obtiene los documentos asociados a un cliente en un rango de fechas
     *
     * @param int $id_cliente ID del cliente
     * @param string $fechainicio Fecha de inicio (formato YYYY-MM-DD)
     * @param string $fechafin Fecha de fin (formato YYYY-MM-DD)
     * @return array<array{
     *     Id: int,
     *     Tipo: string,
     *     Referencia: string,
     *     Fecha: string,
     *     Estado: int,
     *     ImporteTotal: float,
     *     Moneda: string,
     *     UrlPDF: ?string,
     *     EsPedido: bool,
     *     EsFactura: bool,
     *     EsAlbaran: bool
     * }> Lista de documentos del cliente
     * @since 1.0.0
     */
    public function getDocumentosCliente(int $id_cliente, string $fechainicio = '', string $fechafin = ''): array {
        $params = ['id_cliente' => $id_cliente];
        
        if (!empty($fechainicio)) {
            $params['fechainicio'] = $fechainicio;
        }
        if (!empty($fechafin)) {
            $params['fechafin'] = $fechafin;
        }

        $response = $this->api_client->get('GetDocumentosClienteWS', $params);
        
        if ($this->api_client->isSuccess($response)) {
            $documentos = $response['Documentos'] ?? [];
            $this->logger->info('Documentos de cliente obtenidos', [
                'id_cliente' => $id_cliente,
                'count' => count($documentos)
            ]);
            return $documentos;
        }

        $this->logger->error('Error obteniendo documentos de cliente', [
            'id_cliente' => $id_cliente,
            'error' => $this->api_client->getErrorMessage($response)
        ]);
        return [];
    }

    /**
     * Obtiene el PDF de un documento en formato base64
     *
     * @param int $id_documento ID del documento
     * @return string|null Contenido del PDF en base64 o null si hay error
     * @since 1.0.0
     */
    public function getPDFDocumento(int $id_documento): ?string {
        $params = ['id_documento' => $id_documento];

        $response = $this->api_client->get('GetPDFDocClienteWS', $params);
        
        if ($this->api_client->isSuccess($response)) {
            $pdf_base64 = $response['Documento'] ?? '';
            if (!empty($pdf_base64)) {
                $this->logger->info('PDF de documento obtenido', ['id_documento' => $id_documento]);
                return $pdf_base64;
            }
        }

        $this->logger->error('Error obteniendo PDF de documento', [
            'id_documento' => $id_documento,
            'error' => $this->api_client->getErrorMessage($response)
        ]);
        return null;
    }

    /**
     * Verifica si un pedido puede ser modificado
     *
     * @param int $id_pedido ID del pedido a verificar
     * @return bool true si el pedido puede ser modificado, false en caso contrario
     * @since 1.0.0
     */
    public function isPedidoModificable(int $id_pedido): bool {
        $params = ['id_pedido' => $id_pedido];

        $response = $this->api_client->get('PedidoModificableWS', $params);
        
        if ($this->api_client->isSuccess($response)) {
            return $response['Modificable'] ?? false;
        }

        return false;
    }

    /**
     * Convierte un código de estado numérico a su representación en texto
     *
     * @param int $estado Código numérico del estado
     * @return string Texto descriptivo del estado
     * @since 1.0.0
     */
    public function mapEstadoToText(int $estado): string {
        $estados = [
            0 => 'No existe',
            1 => 'Recibido',
            2 => 'En preparación',
            3 => 'Preparado',
            4 => 'Enviado'
        ];

        return $estados[$estado] ?? 'Estado desconocido';
    }

    /**
     * Obtiene un resumen de los estados de múltiples pedidos
     *
     * @param int[] $pedidos_ids Array de IDs de pedidos a consultar
     * @return array<array{
     *     Id: int,
     *     Referencia: string,
     *     Estado: int,
     *     EstadoTexto: string,
     *     Fecha: string,
     *     UrlSeguimiento: ?string
     * }> Resumen de estados de los pedidos solicitados
     * @since 1.0.0
     */
    public function getResumenEstados(array $pedidos_ids): array {
        $pedidos_data = [];
        foreach ($pedidos_ids as $id) {
            $pedidos_data[] = ['Id' => $id];
        }

        $estados = $this->getEstadoPedidos($pedidos_data);
        
        $resumen = [
            'total' => count($pedidos_ids),
            'consultados' => count($estados),
            'por_estado' => [],
            'detalles' => []
        ];

        foreach ($estados as $estado) {
            $estado_num = $estado['Estado'] ?? 0;
            $estado_text = $this->mapEstadoToText($estado_num);
            
            if (!isset($resumen['por_estado'][$estado_text])) {
                $resumen['por_estado'][$estado_text] = 0;
            }
            $resumen['por_estado'][$estado_text]++;
            
            $resumen['detalles'][] = [
                'id' => $estado['Id'] ?? 0,
                'referencia' => $estado['Referencia'] ?? '',
                'estado_num' => $estado_num,
                'estado_text' => $estado_text
            ];
        }

        return $resumen;
    }

    /**
     * Sincronizar estados de pedidos con WooCommerce
     */
    public function syncOrderStatesWithWooCommerce(array $wc_order_ids): array {
        $sync_results = [
            'success' => 0,
            'errors' => 0,
            'updated' => [],
            'not_found' => []
        ];

        foreach ($wc_order_ids as $wc_order_id) {
            try {
                // Obtener pedido de WooCommerce
                $wc_order = wc_get_order($wc_order_id);
                if (!$wc_order) {
                    $sync_results['not_found'][] = $wc_order_id;
                    $sync_results['errors']++;
                    continue;
                }

                // Obtener referencia o ID de Verial
                $verial_ref = $wc_order->get_meta('_verial_referencia');
                $verial_id = $wc_order->get_meta('_verial_id');

                if (empty($verial_ref) && empty($verial_id)) {
                    $sync_results['not_found'][] = $wc_order_id;
                    $sync_results['errors']++;
                    continue;
                }

                // Consultar estado en Verial
                $estado_verial = $this->getEstadoPedido($verial_id, $verial_ref);
                
                if ($estado_verial) {
                    $estado_wc = $this->mapVerialStateToWooCommerce($estado_verial['Estado'] ?? 0);
                    
                    if ($wc_order->get_status() !== $estado_wc) {
                        $wc_order->update_status($estado_wc, 'Estado actualizado desde Verial');
                        $sync_results['updated'][] = [
                            'wc_id' => $wc_order_id,
                            'verial_id' => $verial_id,
                            'estado_anterior' => $wc_order->get_status(),
                            'estado_nuevo' => $estado_wc
                        ];
                    }
                    
                    $sync_results['success']++;
                } else {
                    $sync_results['not_found'][] = $wc_order_id;
                    $sync_results['errors']++;
                }

            } catch (\Exception $e) {
                $this->logger->error('Error sincronizando pedido', [
                    'wc_order_id' => $wc_order_id,
                    'error' => $e->getMessage()
                ]);
                $sync_results['errors']++;
            }
        }

        return $sync_results;
    }

    /**
     * Mapear estado de Verial a estado de WooCommerce
     */
    private function mapVerialStateToWooCommerce(int $verial_state): string {
        $mapping = [
            0 => 'failed',      // No existe
            1 => 'processing',  // Recibido
            2 => 'processing',  // En preparación
            3 => 'processing',  // Preparado
            4 => 'completed'    // Enviado
        ];

        return $mapping[$verial_state] ?? 'pending';
    }
}