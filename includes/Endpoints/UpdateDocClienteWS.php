<?php declare(strict_types=1);
/**
 * Clase para el endpoint UpdateDocClienteWS de la API de Verial ERP.
 * Modifica ciertos datos de un documento de cliente existente según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas y corrección de constantes)
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;
use WP_REST_Request;
use MiIntegracionApi\Helpers\RestHelpers;
use MiIntegracionApi\Constants\VerialTypes;

/**
 * Clase para gestionar el endpoint de updatedocclientes
 */
class UpdateDocClienteWS extends Base {
	use EndpointLogger;

	// Nombre del endpoint en la API de Verial
	public const ENDPOINT_NAME = 'UpdateDocClienteWS';
	// Usando constantes centralizadas de caché

	// Prefijo para claves de caché (si se usan en el futuro)
	public const CACHE_KEY_PREFIX = 'mi_api_update_doc_cliente_';

	// Expiración de caché (0 para no cachear, ya que es una operación de escritura)

	/**
	 * Constructor.
	 *
	 * @param ApiConnector $connector Instancia del conector de la API.
	 */
	public function __construct( \MiIntegracionApi\Core\ApiConnector $connector ) {
		parent::__construct( $connector );
		$this->init_logger( 'pedidos' );
	}

	/**
	 * Método estático para instanciar la clase.
	 *
	 * @param ApiConnector $connector Instancia del conector.
	 * @return static
	 */
	public static function make( \MiIntegracionApi\Core\ApiConnector $connector ): static {
		return new static( $connector );
	}

	/**
	 * Registra la ruta REST para el endpoint.
	 * 
	 * @return void
	 */
	public function register_route(): void {
		// Este endpoint no requiere registro de ruta REST específica
		// ya que se usa internamente por otros componentes del sistema
	}

	/**
	 * Comprueba los permisos para acceder al endpoint.
	 *
	 * @param WP_REST_Request $request Datos de la solicitud.
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface True si tiene permiso, SyncResponseInterface si no.
	 */
	public function permissions_check( \WP_REST_Request $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! current_user_can( 'edit_shop_orders' ) ) { // O una capacidad más específica
			$error_message = esc_html__( 'No tienes permiso para modificar documentos de cliente.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				[],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_forbidden',
					$error_message,
					['status' => RestHelpers::rest_authorization_required_code()]
				),
				RestHelpers::rest_authorization_required_code(),
				$error_message,
				['endpoint' => 'permissions_check']
			);
		}
		return true;
	}

	/**
	 * Define los argumentos esperados por el endpoint.
	 *
	 * @return array
	 */
	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'sesionwcf'     => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'Referencia'    => array(
				'description'       => __( 'Referencia del pedido (si se desea modificar por referencia en lugar de ID y es un pedido).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= VerialTypes::MAX_LENGTH_REFERENCIA;
				},
			),
			'Aux1'          => array(
				'description'       => __( 'Campo auxiliar 1.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= VerialTypes::MAX_LENGTH_AUX;
				},
			),
			'Aux2'          => array(
				'description'       => __( 'Campo auxiliar 2.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= VerialTypes::MAX_LENGTH_AUX;
				},
			),
			'Aux3'          => array(
				'description'       => __( 'Campo auxiliar 3.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= VerialTypes::MAX_LENGTH_AUX;
				},
			),
			'Aux4'          => array(
				'description'       => __( 'Campo auxiliar 4.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= VerialTypes::MAX_LENGTH_AUX;
				},
			),
			'Aux5'          => array(
				'description'       => __( 'Campo auxiliar 5.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= VerialTypes::MAX_LENGTH_AUX;
				},
			),
			'Aux6'          => array(
				'description'       => __( 'Campo auxiliar 6.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= VerialTypes::MAX_LENGTH_AUX;
				},
			),
			'observaciones' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= VerialTypes::MAX_LENGTH_OBSERVACIONES;
				},
			),
			'Estado'        => array(
				'description'       => __( 'Estado del documento (numérico según manual Verial: 0-4).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 0 && $param <= 4;
				},
			),
			'context'       => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
	}

	/**
	 * Ejecuta la lógica del endpoint para actualizar un documento de cliente.
	 *
	 * @param WP_REST_Request $request Datos de la solicitud.
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'update_doc', $api_key );
		if ( $rate_limit instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$rate_limit->isSuccess() ) {
			return rest_ensure_response( $rate_limit );
		}

		$params                  = $request->get_params();
		$id_documento_verial_url = absint( $request['id_documento_verial'] );

		if ( empty( $id_documento_verial_url ) ) {
			$error_message = __( 'El ID del documento es requerido en la URL.', 'mi-integracion-api' );
			$response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				$error_message,
				400,
				['endpoint' => 'execute_restful', 'field' => 'id_documento_verial']
			);
			return rest_ensure_response( $response );
		}

		// Validar existencia del documento en WooCommerce (si aplica)
		$order_id = wc_get_order_id_by_order_key( $id_documento_verial_url );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			$error_message = __( 'El documento (pedido) no existe en WooCommerce.', 'mi-integracion-api' );
			$response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				$error_message,
				404,
				['endpoint' => 'execute_restful', 'document_id' => $id_documento_verial_url]
			);
			return rest_ensure_response( $response );
		}
		// Validar pertenencia del pedido al usuario actual (si aplica)
		if ( ! current_user_can( 'manage_woocommerce' ) && $order->get_user_id() !== get_current_user_id() ) {
			$error_message = __( 'No tienes permiso para modificar este documento.', 'mi-integracion-api' );
			$response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				$error_message,
				403,
				['endpoint' => 'execute_restful', 'document_id' => $id_documento_verial_url, 'user_id' => get_current_user_id()]
			);
			return rest_ensure_response( $response );
		}

		$verial_payload = array(
			'sesionwcf' => $params['sesionwcf'],
			'Id'        => $id_documento_verial_url, // ID del documento a modificar
		);

		$updatable_fields = array( 'Referencia', 'Aux1', 'Aux2', 'Aux3', 'Aux4', 'Aux5', 'Aux6', 'observaciones', 'Estado' );
		foreach ( $updatable_fields as $field_key ) {
			if ( isset( $params[ $field_key ] ) ) {
				$verial_payload[ $field_key ] = $params[ $field_key ];
			}
		}

		$result = $this->connector->post( self::ENDPOINT_NAME, $verial_payload );
		return rest_ensure_response( $result );
	}
}
