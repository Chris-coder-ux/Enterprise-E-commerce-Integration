<?php declare(strict_types=1);
/**
 * Clase para el endpoint GetPDFDocClienteWS de la API de Verial ERP.
 * Obtiene un documento de cliente en formato PDF, según el manual v1.7.5.
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
use MiIntegracionApi\Helpers\AuthHelper;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Clase para gestionar el endpoint de PDF de documentos de cliente
 */
class GetPDFDocClienteWS extends Base {

	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetPDFDocClienteWS';
	// Usando constantes centralizadas de caché
	public const CACHE_KEY_PREFIX     = 'mi_api_pdf_doc_cliente_';

	public function __construct( \MiIntegracionApi\Core\ApiConnector $connector ) {
		parent::__construct( $connector );
		$this->init_logger();
	}

	/**
	 * Comprueba los permisos para acceder al endpoint.
	 * Requiere manage_woocommerce y autenticación adicional (API key/JWT).
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public function permissions_check( \WP_REST_Request $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$error_message = esc_html__( 'No tienes permiso para ver esta información.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				[],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_forbidden',
					$error_message,
					['status' => 403]
				),
				403,
				$error_message,
				['endpoint' => 'permissions_check']
			);
		}
		$auth_result = AuthHelper::validate_rest_auth( $request );
		if ( $auth_result !== true ) {
			return $auth_result;
		}
		return true;
	}

	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'sesionwcf'    => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'id_documento' => array(
				'description'       => __( 'ID del documento en Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			),
			'context'      => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
	}

	protected function format_verial_response( array $verial_response ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! isset( $verial_response['Documento'] ) ) {
			$this->logger->error(
				'[GetPDFDocClienteWS] La respuesta de Verial no contiene la clave "Documento" esperada.',
				array( 'verial_response' => $verial_response )
			);
			$error_message = __( 'Los datos del PDF recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['verial_response' => $verial_response],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'verial_api_malformed_pdf_data',
					$error_message,
					['status' => 500]
				),
				500,
				$error_message,
				['endpoint' => 'format_verial_response']
			);
		}

		$pdf_data = array(
			'pdf_base64' => $verial_response['Documento'],
			'size'       => isset( $verial_response['Documento'] ) ? strlen( base64_decode( $verial_response['Documento'] ) ) : 0,
			'mime_type'  => 'application/pdf',
		);

		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$pdf_data,
			__( 'PDF del documento obtenido correctamente.', 'mi-integracion-api' ),
			[
				'endpoint' => self::ENDPOINT_NAME,
				'pdf_size' => $pdf_data['size'],
				'timestamp' => time()
			]
		);
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = AuthHelper::check_rate_limit( 'get_pdf_doc_cliente', $api_key );
		if ( $rate_limit instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$rate_limit->isSuccess() ) {
			return rest_ensure_response( $rate_limit );
		}

		$params = $request->get_params();
		$sesionwcf = $params['sesionwcf'];
		$id_documento = $params['id_documento'];

		$verial_api_params = array( 
			'x' => $sesionwcf,
			'id_documento' => $id_documento
		);

		$response_verial = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );
		
		// Verificar si la respuesta de Verial es exitosa
		if ( $response_verial instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$response_verial->isSuccess() ) {
			return rest_ensure_response( $response_verial );
		}

		// Formatear la respuesta de Verial
		$formatted_response = $this->format_verial_response( $response_verial );
		
		// Convertir SyncResponseInterface a WP_REST_Response usando WordPressAdapter
		$wp_response = \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse( $formatted_response );
		
		// Para PDFs, establecer headers adecuados
		$wp_response->header( 'Content-Type', 'application/json' );
		
		return $wp_response;
	}

	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/getpdfdocclientews',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $this->get_endpoint_args(),
			)
		);
	}
}
