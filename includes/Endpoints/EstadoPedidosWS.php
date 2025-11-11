<?php declare(strict_types=1);
/**
 * Clase para el endpoint EstadoPedidosWS de la API de Verial ERP.
 * Consulta el estado de múltiples pedidos y devuelve la respuesta en formato JSON,
 * según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Traits\CacheableTrait;
use MiIntegracionApi\Endpoints\Base;
use MiIntegracionApi\Core\ApiConnector;
use WP_REST_Request;

/**
 * Clase para gestionar el endpoint de estadopedidos
 */
class EstadoPedidosWS extends Base {
	use EndpointLogger;
	use CacheableTrait;

	use EndpointLogger;
	use CacheableTrait;

	public const ENDPOINT_NAME    = 'EstadoPedidosWS';
	// Usando constantes centralizadas de caché
	public const CACHE_KEY_PREFIX = 'mia_estado_pedidos_';
	// Estados de pedido según el manual
	public const ESTADO_NO_EXISTE      = 0;
	public const ESTADO_RECIBIDO       = 1;
	public const ESTADO_EN_PREPARACION = 2;
	public const ESTADO_PREPARADO      = 3;
	public const ESTADO_ENVIADO        = 4;

	// Códigos de error de Verial
	public const VERIAL_ERROR_SUCCESS               = 0;

	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/estadopedidosws',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}
	public const VERIAL_ERROR_INVALID_SESSION       = 1;
	public const VERIAL_ERROR_INVALID_JSON_INPUT    = 3;
	public const VERIAL_ERROR_MISSING_REQUIRED_DATA = 10;

	/**
	 * Constructor para el endpoint EstadoPedidosWS
	 *
	 * @param ApiConnector|null $connector Inyección del conector API opcional
	 */
	public function __construct( ?ApiConnector $connector = null ) {
		parent::__construct( $connector );
		$this->init_logger( 'pedidos' );
	}

	/**
	 * Verifica los permisos necesarios para este endpoint
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public function permissions_check( \WP_REST_Request $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! current_user_can( 'view_shop_orders' ) ) {
			$error_message = esc_html__( 'No tienes permiso para consultar el estado de los pedidos.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				[],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_forbidden',
					$error_message,
					['status' => rest_authorization_required_code()]
				),
				rest_authorization_required_code(),
				$error_message,
				['endpoint' => 'permissions_check']
			);
		}
		return true;
	}

	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'sesionwcf' => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'Pedidos'   => array(
				'description'       => __( 'Lista de pedidos para consultar su estado.', 'mi-integracion-api' ),
				'type'              => 'array',
				'required'          => true,
				'validate_callback' => array( $this, 'validate_pedidos_array' ),
				'items'             => array(
					'type'              => 'object',
					'properties'        => array(
						'Id'         => array(
							'description'       => __( 'ID del pedido en Verial.', 'mi-integracion-api' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'Referencia' => array(
							'description'       => __( 'Referencia del pedido generada por la web.', 'mi-integracion-api' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
							'maxLength'         => 40,
						),
					),
					'validate_callback' => array( $this, 'validate_pedido_item' ),
				),
			),
			'context'   => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
	}

	public function validate_pedidos_array( $pedidos, $request, $key ) {
		if ( empty( $pedidos ) || ! is_array( $pedidos ) ) {
			$error_message = sprintf( esc_html__( 'El campo %s debe ser un array no vacío de pedidos.', 'mi-integracion-api' ), $key );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['field' => $key, 'value' => $pedidos],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_invalid_param',
					$error_message,
					['status' => 400, 'field' => $key]
				),
				400,
				$error_message,
				['endpoint' => 'validate_pedidos_array']
			);
		}
		return true;
	}

	public function validate_pedido_item( $pedido_item, $request, $key ) {
		$has_id         = isset( $pedido_item['Id'] ) && is_numeric( $pedido_item['Id'] ) && (int) $pedido_item['Id'] >= 0;
		$has_referencia = isset( $pedido_item['Referencia'] ) && is_string( $pedido_item['Referencia'] ) && ! empty( trim( $pedido_item['Referencia'] ) );

		if ( ! $has_id && ! $has_referencia ) {
			$error_message = __( 'Cada pedido debe tener un "Id" numérico o una "Referencia" no vacía.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['field' => $key, 'value' => $pedido_item],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_invalid_pedido_item',
					$error_message,
					['status' => 400, 'field' => $key]
				),
				400,
				$error_message,
				['endpoint' => 'validate_pedido_item']
			);
		}
		if ( $has_id && (int) $pedido_item['Id'] === 0 && ! $has_referencia ) {
			$error_message = __( 'Si el "Id" del pedido es 0, se requiere una "Referencia".', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['field' => $key, 'value' => $pedido_item],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_invalid_pedido_item',
					$error_message,
					['status' => 400, 'field' => $key]
				),
				400,
				$error_message,
				['endpoint' => 'validate_pedido_item']
			);
		}
		return true;
	}

	private function get_estado_descripcion( int $estado_code ): string {
		switch ( $estado_code ) {
			case self::ESTADO_NO_EXISTE:
				return __( 'El pedido no existe en Verial', 'mi-integracion-api' );
			case self::ESTADO_RECIBIDO:
				return __( 'Recibido', 'mi-integracion-api' );
			case self::ESTADO_EN_PREPARACION:
				return __( 'En preparación', 'mi-integracion-api' );
			case self::ESTADO_PREPARADO:
				return __( 'Preparado', 'mi-integracion-api' );
			case self::ESTADO_ENVIADO:
				return __( 'Enviado', 'mi-integracion-api' );
			default:
				return __( 'Estado desconocido', 'mi-integracion-api' );
		}
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'estado_pedidos', $api_key );
		if ( $rate_limit instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$rate_limit->isSuccess() ) {
			return rest_ensure_response( $rate_limit );
		}

		$params = $request->get_params();

		$verial_payload_pedidos = array();
		foreach ( $params['Pedidos'] as $pedido_input ) {
			$item_payload = array();
			if ( isset( $pedido_input['Id'] ) ) {
				$item_payload['Id'] = (int) $pedido_input['Id'];
			}
			if ( isset( $pedido_input['Referencia'] ) && ! empty( trim( $pedido_input['Referencia'] ) ) ) {
				$item_payload['Referencia'] = trim( $pedido_input['Referencia'] );
			}
			$verial_payload_pedidos[] = $item_payload;
		}

		$verial_payload = array(
			'sesionwcf' => $params['sesionwcf'],
			'Pedidos'   => $verial_payload_pedidos,
		);

		$result = $this->connector->post( self::ENDPOINT_NAME, $verial_payload );
		return rest_ensure_response( $result );
	}
}
