<?php
/**
 * Servicio para la gestión de clientes en Verial
 *
 * Este servicio proporciona métodos para interactuar con los clientes de Verial,
 * incluyendo búsqueda, creación, actualización y gestión de direcciones.
 * Se integra con la API de Verial y utiliza servicios auxiliares para tareas
 * como el mapeo geográfico y el registro de eventos.
 *
 * @package    MiIntegracionApi
 * @subpackage Services
 * @since      1.0.0
 * @version    1.1.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\Services;

use MiIntegracionApi\Services\VerialApiClient;
use MiIntegracionApi\Services\GeographicService;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Constants\VerialTypes;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase ClientService
 *
 * Gestiona las operaciones relacionadas con clientes en el sistema Verial,
 * incluyendo la sincronización con WooCommerce y el manejo de direcciones.
 *
 * @package MiIntegracionApi\Services
 * @since   1.0.0
 */
class ClientService {
    /**
     * Cliente de la API de Verial
     *
     * @var VerialApiClient
     * @since 1.0.0
     */
    private VerialApiClient $api_client;

    /**
     * Servicio para gestión de geografía
     *
     * @var GeographicService
     * @since 1.0.0
     */
    private GeographicService $geographic_service;

    /**
     * Instancia del logger para registro de eventos
     *
     * @var Logger
     * @since 1.0.0
     */
    private Logger $logger;

    /**
     * Constructor del servicio de clientes
     *
     * @param VerialApiClient $api_client Cliente de la API de Verial
     * @param GeographicService $geographic_service Servicio de geografía
     * @param Logger|null $logger Instancia del logger (opcional)
     * @since 1.0.0
     */
    public function __construct(
        VerialApiClient $api_client, 
        GeographicService $geographic_service, 
        ?Logger $logger = null
    ) {
        $this->api_client = $api_client;
        $this->geographic_service = $geographic_service;
        $this->logger = $logger ?: new Logger('client_service');
    }

    /**
     * Obtiene clientes de la API de Verial con filtros opcionales
     *
     * @param int $id_cliente ID del cliente a buscar (0 para todos)
     * @param string $nif NIF del cliente a buscar (opcional)
     * @param string $fecha Fecha de filtro en formato YYYY-MM-DD (opcional)
     * @param string $hora Hora de filtro en formato HH:MM:SS (opcional)
     * @return array Lista de clientes encontrados o array vacío en caso de error
     * @since 1.0.0
     */
    public function getClientes(int $id_cliente = 0, string $nif = '', string $fecha = '', string $hora = ''): array {
        $params = ['id_cliente' => $id_cliente];
        
        if (!empty($nif)) {
            $params['nif'] = $nif;
        }
        if (!empty($fecha)) {
            $params['fecha'] = $fecha;
        }
        if (!empty($hora)) {
            $params['hora'] = $hora;
        }

        $response = $this->api_client->get('GetClientesWS', $params);
        
        if ($this->api_client->isSuccess($response)) {
            $clientes = $response['Clientes'] ?? [];
            $this->logger->info('Clientes obtenidos de Verial', ['count' => count($clientes)]);
            return $clientes;
        }

        $this->logger->error('Error obteniendo clientes', ['error' => $this->api_client->getErrorMessage($response)]);
        return [];
    }

    /**
     * Busca un cliente por su dirección de correo electrónico
     *
     * Realiza una búsqueda insensible a mayúsculas/minúsculas en el campo WebUser
     * de los clientes existentes.
     *
     * @param string $email Dirección de correo electrónico a buscar
     * @return array|null Datos del cliente encontrado o null si no se encuentra
     * @since 1.0.0
     */
    public function findClienteByEmail(string $email): ?array {
        $clientes = $this->getClientes();
        
        foreach ($clientes as $cliente) {
            if (strtolower($cliente['WebUser'] ?? '') === strtolower($email)) {
                return $cliente;
            }
        }

        return null;
    }

    /**
     * Busca un cliente por su NIF/CIF/NIE
     *
     * Realiza una búsqueda exacta del NIF en la API de Verial.
     *
     * @param string $nif Número de identificación fiscal a buscar
     * @return array|null Datos del cliente encontrado o null si no se encuentra
     * @since 1.0.0
     */
    public function findClienteByNIF(string $nif): ?array {
        if (empty($nif)) {
            return null;
        }

        $clientes = $this->getClientes(0, $nif);
        
        if (!empty($clientes)) {
            return $clientes[0]; // Devolver el primero encontrado
        }

        return null;
    }

    /**
     * Crea un nuevo cliente o actualiza uno existente en Verial
     *
     * @param array $cliente_data Datos del cliente en formato array asociativo
     * @return array|null Respuesta de la API o null en caso de error
     * @since 1.0.0
     * @see mapClienteGeography() Para el mapeo de datos geográficos
     */
    public function createOrUpdateCliente(array $cliente_data): ?array {
        // Mapear direcciones geográficas
        $cliente_data = $this->mapClienteGeography($cliente_data);

        $response = $this->api_client->post('NuevoClienteWS', $cliente_data);
        
        // Log detallado de la respuesta
        $this->logger->info('Respuesta de NuevoClienteWS', [
            'response_keys' => array_keys($response),
            'info_error' => $response['InfoError'] ?? 'No InfoError',
            'has_id' => isset($response['Id']),
            'cliente_data_keys' => array_keys($cliente_data)
        ]);
        
        if ($this->api_client->isSuccess($response)) {
            $this->logger->info('Cliente creado/actualizado', [
                'id' => $response['Id'] ?? 'nuevo',
                'email' => $cliente_data['Email'] ?? '',
                'nif' => $cliente_data['NIF'] ?? ''
            ]);
            return $response;
        }

        $this->logger->error('Error creando/actualizando cliente', [
            'error' => $this->api_client->getErrorMessage($response),
            'response' => $response,
            'data' => array_keys($cliente_data)
        ]);
        return null;
    }

    /**
     * Crea una nueva dirección de envío para un cliente existente
     *
     * @param int $id_cliente ID del cliente al que asociar la dirección
     * @param array $direccion_data Datos de la dirección en formato array asociativo
     * @return array|null Respuesta de la API o null en caso de error
     * @since 1.0.0
     * @see mapDireccionGeography() Para el mapeo de datos geográficos
     */
    public function createDireccionEnvio(int $id_cliente, array $direccion_data): ?array {
        $direccion_data['ID_Cliente'] = $id_cliente;
        
        // Mapear geografía
        $direccion_data = $this->mapDireccionGeography($direccion_data);

        $response = $this->api_client->post('NuevaDireccionEnvioWS', $direccion_data);
        
        if ($this->api_client->isSuccess($response)) {
            $this->logger->info('Dirección de envío creada', [
                'id_cliente' => $id_cliente,
                'direccion_id' => $response['Id'] ?? 'nueva'
            ]);
            return $response;
        }

        $this->logger->error('Error creando dirección de envío', [
            'id_cliente' => $id_cliente,
            'error' => $this->api_client->getErrorMessage($response)
        ]);
        return null;
    }

    /**
     * Mapea los datos geográficos de un cliente a los IDs de Verial
     *
     * Procesa el país, provincia y localidad del cliente, actualizando los campos
     * con los IDs correspondientes según la base de datos de Verial.
     *
     * @param array $cliente_data Datos del cliente
     * @return array Datos del cliente con los IDs geográficos actualizados
     * @since 1.0.0
     */
    private function mapClienteGeography(array $cliente_data): array {
        // Mapear país/provincia/localidad si están definidos como texto
        if (!empty($cliente_data['Country']) && empty($cliente_data['ID_Pais'])) {
            $geography = $this->geographic_service->mapAddress(
                $cliente_data['Country'],
                $cliente_data['Provincia'] ?? '',
                $cliente_data['Localidad'] ?? ''
            );

            $cliente_data['ID_Pais'] = $geography['ID_Pais'];
            $cliente_data['ID_Provincia'] = $geography['ID_Provincia'];
            $cliente_data['ID_Localidad'] = $geography['ID_Localidad'];
            
            // Actualizar nombres con los oficiales de Verial
            if (!empty($geography['Provincia'])) {
                $cliente_data['Provincia'] = $geography['Provincia'];
            }
            if (!empty($geography['Localidad'])) {
                $cliente_data['Localidad'] = $geography['Localidad'];
            }
        }

        // Mapear direcciones de envío
        if (!empty($cliente_data['DireccionesEnvio'])) {
            foreach ($cliente_data['DireccionesEnvio'] as &$direccion) {
                $direccion = $this->mapDireccionGeography($direccion);
            }
        }

        return $cliente_data;
    }

    /**
     * Mapea los datos geográficos de una dirección a los IDs de Verial
     *
     * Similar a mapClienteGeography() pero para direcciones individuales.
     *
     * @param array $direccion_data Datos de la dirección
     * @return array Datos de la dirección con los IDs geográficos actualizados
     * @since 1.0.0
     */
    private function mapDireccionGeography(array $direccion_data): array {
        if (!empty($direccion_data['Country']) && empty($direccion_data['ID_Pais'])) {
            $geography = $this->geographic_service->mapAddress(
                $direccion_data['Country'],
                $direccion_data['Provincia'] ?? '',
                $direccion_data['Localidad'] ?? ''
            );

            $direccion_data['ID_Pais'] = $geography['ID_Pais'];
            $direccion_data['ID_Provincia'] = $geography['ID_Provincia'];
            $direccion_data['ID_Localidad'] = $geography['ID_Localidad'];
            
            // Actualizar nombres con los oficiales de Verial
            if (!empty($geography['Provincia'])) {
                $direccion_data['Provincia'] = $geography['Provincia'];
            }
            if (!empty($geography['Localidad'])) {
                $direccion_data['Localidad'] = $geography['Localidad'];
            }
        }

        return $direccion_data;
    }

    /**
     * Convierte un pedido de WooCommerce al formato de cliente de Verial
     *
     * Mapea los campos de facturación y envío de un pedido de WooCommerce
     * al formato esperado por la API de Verial.
     *
     * @param WC_Order $wc_order Instancia del pedido de WooCommerce
     * @return array Datos del cliente en formato Verial
     * @since 1.0.0
     */
    public function convertWooCommerceCustomer(\WC_Order $wc_order): array {
        return [
            'Tipo' => !empty($wc_order->get_billing_company()) ? VerialTypes::CUSTOMER_TYPE_COMPANY : VerialTypes::CUSTOMER_TYPE_INDIVIDUAL, // Particular o Empresa
            'NIF' => $this->extractNIF($wc_order),
            'Nombre' => $wc_order->get_billing_first_name(),
            'Apellido1' => $wc_order->get_billing_last_name(),
            'Apellido2' => $wc_order->get_meta('billing_last_name_2') ?: '',
            'RazonSocial' => $wc_order->get_billing_company(),
            'RegFiscal' => 1, // IVA por defecto
            'Country' => $wc_order->get_billing_country(),
            'Provincia' => $wc_order->get_billing_state(),
            'Localidad' => $wc_order->get_billing_city(),
            'CPostal' => $wc_order->get_billing_postcode(),
            'Direccion' => $wc_order->get_billing_address_1(),
            'Telefono' => $wc_order->get_billing_phone(),
            'Email' => $wc_order->get_billing_email(),
            'Sexo' => 1, // Masculino por defecto
            'WebUser' => $wc_order->get_billing_email(),
            'WebPassword' => 'wc_' . wp_generate_password(8, false),
            'DireccionesEnvio' => [
                [
                    'Nombre' => $wc_order->get_shipping_first_name() ?: $wc_order->get_billing_first_name(),
                    'Apellido1' => $wc_order->get_shipping_last_name() ?: $wc_order->get_billing_last_name(),
                    'Country' => $wc_order->get_shipping_country() ?: $wc_order->get_billing_country(),
                    'Provincia' => $wc_order->get_shipping_state() ?: $wc_order->get_billing_state(),
                    'Localidad' => $wc_order->get_shipping_city() ?: $wc_order->get_billing_city(),
                    'CPostal' => $wc_order->get_shipping_postcode() ?: $wc_order->get_billing_postcode(),
                    'Direccion' => $wc_order->get_shipping_address_1() ?: $wc_order->get_billing_address_1(),
                    'Telefono' => $wc_order->get_billing_phone()
                ]
            ]
        ];
    }

    /**
     * Extrae el NIF de un pedido de WooCommerce
     *
     * Busca en varios campos personalizados comunes donde podría estar almacenado el NIF.
     *
     * @param WC_Order $wc_order Instancia del pedido de WooCommerce
     * @return string NIF encontrado o cadena vacía si no se encuentra
     * @since 1.0.0
     */
    private function extractNIF(\WC_Order $wc_order): string {
        $nif_fields = ['billing_nif', '_billing_nif', 'billing_vat', 'nif', 'dni'];
        
        foreach ($nif_fields as $field) {
            $value = $wc_order->get_meta($field);
            if (!empty($value)) {
                return (string) $value;
            }
        }
        
        return '';
    }
}