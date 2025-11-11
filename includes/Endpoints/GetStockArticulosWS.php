<?php
declare(strict_types=1);

namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Helpers\AuthHelper;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Clase para el endpoint GetStockArticulosWS de la API de Verial ERP.
 * Obtiene el stock de artículos, según el manual v1.7.5 y el JSON de Postman.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

/**
 * Endpoint REST para obtener el stock de artículos desde Verial.
 */
class GetStockArticulosWS extends Base {

	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetStockArticulosWS';
	// Usando constantes centralizadas de caché
	public const CACHE_KEY_PREFIX     = 'mi_api_stock_art_';

	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/getstockarticulosws',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Constructor. Inicializa el logger y el conector.
	 *
	 * @param ApiConnector $connector
	 */
	public function __construct( \MiIntegracionApi\Core\ApiConnector $connector ) {
		parent::__construct( $connector );
		$this->init_logger();
	}

	/**
	 * Comprueba permisos para acceder al endpoint.
	 * Requiere manage_woocommerce y autenticación adicional (API key/JWT).
	 *
	 * @param WP_REST_Request $request
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public function permissions_check( WP_REST_Request $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$error_message = esc_html__( 'No tienes permiso para ver esta información de stock.', 'mi-integracion-api' );
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
		$auth_result = AuthHelper::validate_rest_auth( $request );
		if ( $auth_result !== true ) {
			return $auth_result;
		}
		return true;
	}

	/**
	 * Define los argumentos del endpoint.
	 *
	 * @param bool $is_update Si es endpoint de un solo artículo.
	 * @return array<string, array<string, mixed>>
	 */
	public function get_endpoint_args( bool $is_update = false ): array {
		$args = array(
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
		);
		if ( $is_update ) {
			$args['id_articulo_verial'] = array(
				'description'       => __( 'ID del artículo en Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			);
		}
		$args['context'] = array(
			'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
			'type'        => 'string',
			'enum'        => array( 'view', 'embed', 'edit' ),
			'default'     => 'view',
		);
		return $args;
	}

	/**
	 * Formatea la respuesta de Verial según el manual y el JSON de Postman.
	 *
	 * @param array<string, mixed> $verial_response
	 * @return \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	protected function format_verial_response( array $verial_response ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		$data_key = '';
		if ( isset( $verial_response['StockArticulos'] ) && is_array( $verial_response['StockArticulos'] ) ) {
			$data_key = 'StockArticulos';
			if ( method_exists( $this->logger, 'error' ) ) {
				$this->logger->error(
					'[MI Integracion API] GetStockArticulosWS: Verial devolvió "StockArticulos" en lugar de "Stock" como indica el manual.',
					array( 'response' => $verial_response )
				);
			}
		} elseif ( isset( $verial_response['Stock'] ) && is_array( $verial_response['Stock'] ) ) {
			$data_key = 'Stock';
		} else {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__( 'Los datos de stock recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				500,
				[
					'endpoint' => self::ENDPOINT_NAME,
					'error_code' => 'verial_api_malformed_stock_data',
					'verial_response' => $verial_response
				]
			);
		}

		$stock_articulos = array();
		if ( isset( $verial_response[ $data_key ] ) && is_iterable( $verial_response[ $data_key ] ) ) {
			foreach ( $verial_response[ $data_key ] as $stock_item_verial ) {
				if ( ! is_array( $stock_item_verial ) ) {
					continue;
				}
				$id_articulo               = isset( $stock_item_verial['ID_Articulo'] ) && ( is_int( $stock_item_verial['ID_Articulo'] ) || is_string( $stock_item_verial['ID_Articulo'] ) ) ? (int) $stock_item_verial['ID_Articulo'] : null;
				$stock_unidades            = isset( $stock_item_verial['Stock'] ) && ( is_int( $stock_item_verial['Stock'] ) || is_float( $stock_item_verial['Stock'] ) || is_string( $stock_item_verial['Stock'] ) ) ? (float) $stock_item_verial['Stock'] : null;
				$stock_unidades_auxiliares = isset( $stock_item_verial['StockAux'] ) && ( is_int( $stock_item_verial['StockAux'] ) || is_float( $stock_item_verial['StockAux'] ) || is_string( $stock_item_verial['StockAux'] ) ) ? (float) $stock_item_verial['StockAux'] : null;

				$stock_articulos[] = array(
					'id_articulo'               => $id_articulo,
					'stock_unidades'            => $stock_unidades,
					'stock_unidades_auxiliares' => $stock_unidades_auxiliares,
				);
			}
		}
		
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$stock_articulos,
			__( 'Stock de artículos obtenido correctamente.', 'mi-integracion-api' ),
			[
				'endpoint' => self::ENDPOINT_NAME,
				'data_key' => $data_key,
				'items_count' => count( $stock_articulos ),
				'timestamp' => time()
			]
		);
	}

	/**
	 * Ejecuta la lógica principal del endpoint REST.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = AuthHelper::check_rate_limit( 'get_stock_articulos', $api_key );
		if ( $rate_limit instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$rate_limit->isSuccess() ) {
			return $rate_limit;
		}

		$params = $request->get_params();
		if ( ! is_array( $params ) ) {
			$error_message = __( 'Los parámetros de la petición no son válidos.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['params' => $params],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'invalid_request_params',
					$error_message,
					['status' => 400]
				),
				400,
				$error_message,
				['endpoint' => 'execute_restful']
			);
		}
		if ( ! isset( $params['sesionwcf'] ) || ! is_numeric( $params['sesionwcf'] ) ) {
			$error_message = __( 'El parámetro sesionwcf es obligatorio y debe ser numérico.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['params' => $params],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'missing_sesionwcf',
					$error_message,
					['status' => 400]
				),
				400,
				$error_message,
				['endpoint' => 'execute_restful']
			);
		}
		$sesionwcf         = (int) $params['sesionwcf'];
		$force_refresh     = isset( $params['force_refresh'] ) ? (bool) $params['force_refresh'] : false;
		$id_articulo_param = 0;
		if ( isset( $params['id_articulo_verial'] ) && is_numeric( $params['id_articulo_verial'] ) ) {
			$id_articulo_param = absint( $params['id_articulo_verial'] );
		}

		$cache_params_for_key = array(
			'sesionwcf'   => $sesionwcf,
			'id_articulo' => $id_articulo_param,
		);

		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_params_for_key );
			if ( false !== $cached_data ) {
				return new WP_REST_Response( $cached_data, 200 );
			}
		}

		$verial_api_params = array(
			'x'           => $sesionwcf,
			'id_articulo' => $id_articulo_param,
		);

		$response_verial = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );
		
		// Verificar si la respuesta de Verial es exitosa
		if ( $response_verial instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$response_verial->isSuccess() ) {
			return rest_ensure_response( $response_verial );
		}

		// Si es SyncResponseInterface exitoso, obtener los datos
		$verial_data = $response_verial instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface 
			? $response_verial->getData() 
			: $response_verial;
		
		$formatted_response = $this->format_verial_response( $verial_data );
		
		// Convertir SyncResponseInterface a WP_REST_Response usando WordPressAdapter
		$wp_response = \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse( $formatted_response );
		
		// Si la respuesta es exitosa, guardar en caché y log
		if ( $formatted_response->isSuccess() ) {
			$formatted_data = $formatted_response->getData();
			$this->set_cached_data( $cache_params_for_key, $formatted_data );
			
			require_once dirname( __DIR__ ) . '/../helpers/LoggerAuditoria.php';
			\LoggerAuditoria::log(
				'Acceso a GetStockArticulosWS',
				array(
					'params'    => $verial_api_params,
					'usuario'   => get_current_user_id(),
					'resultado' => 'OK',
					'items_count' => count( $formatted_data ),
				)
			);
		}
		
		return $wp_response;
	}
}

/**
 * Función para registrar las rutas (ejemplo).
 */
// add_action('rest_api_init', function () {
// $api_connector = new \MiIntegracionApi\Core\ApiConnector(/* ...configuración... */);
// $stock_articulos_endpoint = new \MiIntegracionApi\Endpoints\GetStockArticulosWS($api_connector);
// $stock_articulos_endpoint->register_route();
// });
