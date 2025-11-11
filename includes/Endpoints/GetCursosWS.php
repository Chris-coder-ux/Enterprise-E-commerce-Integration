<?php declare(strict_types=1);

namespace MiIntegracionApi\Endpoints;
/**
 * Clase para el endpoint GetCursosWS de la API de Verial ERP.
 * Obtiene el listado de cursos, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;

class GetCursosWS extends \MiIntegracionApi\Endpoints\Base {

	use EndpointLogger;

	const ENDPOINT_NAME        = 'GetCursosWS';
	// Usando constantes centralizadas de caché
	const CACHE_KEY_PREFIX = 'value';
	const VERIAL_ERROR_SUCCESS = 'value';

	/**
	 * Constructor
	 */
	public function __construct( \MiIntegracionApi\Core\ApiConnector $connector ) {
		parent::__construct( $connector );
		$this->init_logger();
	}

	/**
	 * Implementación requerida por la clase abstracta Base.
	 * El registro real de rutas ahora está centralizado en REST_API_Handler.php
	 */
	public function register_route(): void {
		// Esta implementación está vacía ya que el registro real
		// de rutas ahora se hace de forma centralizada en REST_API_Handler.php
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
					['status' => rest_authorization_required_code()]
				),
				rest_authorization_required_code(),
				$error_message,
				['endpoint' => 'permissions_check']
			);
		}
		$auth_result = \MiIntegracionApi\Helpers\AuthHelper::validate_rest_auth( $request );
		if ( $auth_result !== true ) {
			return $auth_result;
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
		);
	}

	protected function format_verial_response( array $verial_response ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		$data_key = '';
		if ( isset( $verial_response['Valores'] ) && is_array( $verial_response['Valores'] ) ) {
			$data_key = 'Valores';
		} elseif ( isset( $verial_response['Cursos'] ) && is_array( $verial_response['Cursos'] ) ) {
			if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
				\MiIntegracionApi\Helpers\Logger::error( __( '[MI Integracion API] GetCursosWS: Verial devolvió "Cursos" en lugar de "Valores" como indica el manual.', 'mi-integracion-api' ), array( 'response' => $verial_response ), 'endpoint-getcursos' );
			}
			$data_key = 'Cursos';
		} else {
			\MiIntegracionApi\Helpers\Logger::error(
				__( '[MI Integracion API] Respuesta inesperada de Verial para GetCursosWS', 'mi-integracion-api' ),
				array( 'response' => $verial_response ),
				'endpoint-getcursos'
			);
			$error_message = __( 'Los datos de cursos recibidos de Verial no tienen el formato esperado (se esperaba la clave "Valores").', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['verial_response' => $verial_response],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'verial_api_malformed_cursos_data',
					$error_message,
					['status' => 500]
				),
				500,
				$error_message,
				['endpoint' => 'format_verial_response']
			);
		}

		$cursos = array();
		foreach ( $verial_response[ $data_key ] as $curso_verial ) {
			$cursos[] = array(
				'id'     => isset( $curso_verial['Id'] ) ? (int) $curso_verial['Id'] : null,
				'nombre' => isset( $curso_verial['Valor'] ) ? sanitize_text_field( $curso_verial['Valor'] ) : null,
			);
		}
		
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$cursos,
			__( 'Cursos obtenidos correctamente.', 'mi-integracion-api' ),
			[
				'endpoint' => self::ENDPOINT_NAME,
				'items_count' => count( $cursos ),
				'timestamp' => time()
			]
		);
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		$params        = $request->get_params();
		$sesionwcf     = $params['sesionwcf'];
		$force_refresh = $params['force_refresh'];

		$cache_params_for_key = array(
			'sesionwcf' => $sesionwcf,
		);

		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_params_for_key );
			if ( false !== $cached_data ) {
				return rest_ensure_response( $cached_data );
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
		if ( $formatted_response->isSuccess() && $this->use_cache() ) {
			$formatted_data = $formatted_response->getData();
			// Usar la función base para formatear la respuesta estándar
			$formatted_response_data = $this->format_success_response($formatted_data);
			$this->set_cached_data( $cache_params_for_key, $formatted_response_data );
		}
		
		return $wp_response;
	}
}

/**
 * Función para registrar las rutas (ejemplo).
 */
// add_action('rest_api_init', function () {
// $api_connector = new ApiConnector();
// $cursos_endpoint = new MI_Endpoint_GetCursosWS($api_connector);
// $cursos_endpoint->register_route();
// });
