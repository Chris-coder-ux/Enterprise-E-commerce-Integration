<?php declare(strict_types=1);

namespace MiIntegracionApi\Endpoints;
/**
 * Clase para el endpoint GetNextNumDocsWS de la API de Verial ERP.
 * Devuelve el siguiente número de documento por tipo, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;

class GetNextNumDocsWS extends \MiIntegracionApi\Endpoints\Base {

	use EndpointLogger;

	const ENDPOINT_NAME    = 'GetNextNumDocsWS';
	// Usando constantes centralizadas de caché
	const CACHE_KEY_PREFIX = 'mi_api_next_num_docs_';// Tipos de Documento y códigos de error de Verial - Usando constantes centralizadas
	use MiIntegracionApi\Constants\VerialTypes;

	public function __construct( \MiIntegracionApi\Core\ApiConnector $connector ) {
		parent::__construct( $connector );
		$this->init_logger(); // Logger base, no específico de productos
	}

	public function permissions_check( \WP_REST_Request $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! current_user_can( 'manage_options' ) ) {
			$error_message = esc_html__( 'No tienes permiso para ver esta información.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				[],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_forbidden',
					$error_message,
					['status' => \MiIntegracionApi\Helpers\RestHelpers::rest_authorization_required_code()]
				),
				\MiIntegracionApi\Helpers\RestHelpers::rest_authorization_required_code(),
				$error_message,
				['endpoint' => 'permissions_check']
			);
		}
		return true;
	}

	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'sesionwcf'     => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'force_refresh' => array(
				'description'       => __( 'Forzar la actualización de la caché.', 'mi-integracion-api' ),
				'type'              => 'boolean',
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'context'       => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
	}

	private function get_tipo_documento_descripcion( ?int $tipo_doc_code ): ?string {
		if ( $tipo_doc_code === null ) {
			return null;
		}
		switch ( $tipo_doc_code ) {
			case VerialTypes::DOCUMENT_TYPE_INVOICE:
				return __( 'Factura', 'mi-integracion-api' );
			case VerialTypes::DOCUMENT_TYPE_DELIVERY_NOTE:
				return __( 'Albarán de venta', 'mi-integracion-api' );
			case VerialTypes::DOCUMENT_TYPE_SIMPLIFIED_INVOICE:
				return __( 'Factura simplificada', 'mi-integracion-api' );
			case VerialTypes::DOCUMENT_TYPE_ORDER:
				return __( 'Pedido', 'mi-integracion-api' );
			case VerialTypes::DOCUMENT_TYPE_QUOTE:
				return __( 'Presupuesto', 'mi-integracion-api' );
			default:
				return __( 'Tipo de documento desconocido', 'mi-integracion-api' ) . ' (' . $tipo_doc_code . ')';
		}
	}

	protected function format_verial_response( array $verial_response ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! isset( $verial_response['Numeros'] ) || ! is_array( $verial_response['Numeros'] ) ) {
			$this->logger->errorProducto(
				'[MI Integracion API] La respuesta de Verial no contiene la clave "Numeros" esperada o no es un array.',
				array( 'verial_response' => $verial_response )
			);
			$error_message = __( 'Los datos de siguientes números de documento recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['verial_response' => $verial_response],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'verial_api_malformed_next_num_data',
					$error_message,
					['status' => 500]
				),
				500,
				$error_message,
				['endpoint' => 'format_verial_response']
			);
		}

		$siguientes_numeros = array();
		foreach ( $verial_response['Numeros'] as $item_verial ) {
			$tipo_code            = isset( $item_verial['Tipo'] ) ? (int) $item_verial['Tipo'] : null;
			$siguientes_numeros[] = array(
				'tipo_codigo'      => $tipo_code,
				'tipo_descripcion' => $this->get_tipo_documento_descripcion( $tipo_code ),
				'siguiente_numero' => isset( $item_verial['Numero'] ) ? (int) $item_verial['Numero'] : null,
			);
		}
		
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$siguientes_numeros,
			__( 'Siguientes números de documento obtenidos correctamente.', 'mi-integracion-api' ),
			[
				'endpoint' => self::ENDPOINT_NAME,
				'items_count' => count( $siguientes_numeros ),
				'timestamp' => time()
			]
		);
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'get_next_num_docs', $api_key );
		if ( $rate_limit instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$rate_limit->isSuccess() ) {
			return $rate_limit;
		}

		$params        = $request->get_params();
		$sesionwcf     = $params['sesionwcf'];
		$force_refresh = $params['force_refresh'] ?? false;

		$cache_params_for_key = array(
			'sesionwcf' => $sesionwcf,
		);

		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_params_for_key );
			if ( false !== $cached_data ) {
				return new \WP_REST_Response( $cached_data, 200 );
			}
		}

		$verial_api_params = array( 'x' => $sesionwcf );
		$response_verial   = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );
		
		// Verificar si la respuesta de Verial es exitosa
		if ( $response_verial instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$response_verial->isSuccess() ) {
			return rest_ensure_response( $response_verial );
		}

		// Formatear la respuesta de Verial
		$formatted_response = $this->format_verial_response( $response_verial );
		
		// Convertir SyncResponseInterface a WP_REST_Response usando WordPressAdapter
		$wp_response = \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse( $formatted_response );
		
		// Si la respuesta es exitosa, guardar en caché
		if ( $formatted_response->isSuccess() ) {
			$formatted_data = $formatted_response->getData();
			// Usar la función base para formatear la respuesta estándar
			$formatted_response_data = $this->format_success_response($formatted_data);
			$this->set_cached_data( $cache_params_for_key, $formatted_response_data );
			
			require_once dirname( __DIR__ ) . '/../helpers/LoggerAuditoria.php';
			\LoggerAuditoria::log(
				'Acceso a GetNextNumDocsWS',
				array(
					'params'    => $verial_api_params,
					'usuario'   => get_current_user_id(),
					'resultado' => 'OK',
				)
			);
		}

		return $wp_response;
	}

	/**
	 * Implementación requerida por la clase abstracta Base.
	 * El registro real de rutas ahora está centralizado en REST_API_Handler.php
	 */
	public function register_route(): void {
		// Esta implementación está vacía ya que el registro real
		// de rutas ahora se hace de forma centralizada en REST_API_Handler.php
	}
}

/**
 * Función para registrar las rutas (ejemplo).
 * Debería estar en tu archivo principal del plugin, dentro de la acción 'rest_api_init'.
 */
// add_action('rest_api_init', function () {
// Asumimos que $api_connector es una instancia de ApiConnector
// if (class_exists('ApiConnector') && class_exists('MI_Endpoint_GetNextNumDocsWS')) {
// $api_connector = new ApiConnector(); // O cómo obtengas tu instancia global/singleton
// $next_num_docs_endpoint = new MI_Endpoint_GetNextNumDocsWS($api_connector);
// $next_num_docs_endpoint->register_route();
// }
// });
