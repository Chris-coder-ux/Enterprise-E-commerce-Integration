<?php declare(strict_types=1);

namespace MiIntegracionApi\Endpoints;
/**
 * Clase para el endpoint GetPaisesWS de la API de Verial ERP.
 * Obtiene el listado de países, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas y revisión)
 * @package mi-integracion-api
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Constants\VerialTypes;
use MiIntegracionApi\Core\CacheConfig;

class GetPaisesWS extends \MiIntegracionApi\Endpoints\Base {

	use EndpointLogger;

	const ENDPOINT_NAME          = 'GetPaisesWS';
	// Usando constantes centralizadas de caché
	const CACHE_KEY_PREFIX = 'value';
	const VERIAL_ERROR_SUCCESS = 'value';
	// Límites de longitud - Usando constantes centralizadas de VerialTypes

	/**
	 * Constructor para el endpoint GetPaisesWS
	 *
	 * @param \MiIntegracionApi\Core\ApiConnector $connector Instancia del conector de la API
	 */
	public function __construct(\MiIntegracionApi\Core\ApiConnector $connector) {
		parent::__construct($connector);
		$this->init_logger();
	}	/**
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
		if ( class_exists( 'MiIntegracionApi\\Helpers\\AuthHelper' ) ) {
			$auth_result = \MiIntegracionApi\Helpers\AuthHelper::validate_rest_auth( $request );
			if ( $auth_result !== true ) {
				return $auth_result;
			}
		}
		return true;
	}

	/**
	 * Define los argumentos esperados por el endpoint (parámetros query).
	 *
	 * @return array
	 */
	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'x'     => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Formatea la respuesta de la API de Verial.
	 *
	 * @param array $verial_response La respuesta decodificada de Verial.
	 * @return \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	protected function format_verial_response( array $verial_response ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Validar estructura esperada
		if ( ! isset( $verial_response['Paises'] ) || ! is_array( $verial_response['Paises'] ) ) {
			$this->logger->error(
				'[GetPaisesWS] La respuesta de Verial no contiene la clave "Paises" esperada o no es un array.',
				array( 'verial_response' => $verial_response )
			);
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__( 'Los datos de países recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				500,
				[
					'endpoint' => self::ENDPOINT_NAME,
					'error_code' => 'verial_api_malformed_paises_data',
					'verial_response' => $verial_response
				]
			);
		}

		// Construir lista de países manteniendo los nombres de campo esperados por el Manual
		$paises_formateados = array();
		foreach ( $verial_response['Paises'] as $pais_verial ) {
			$paises_formateados[] = array(
				'Id'     => isset( $pais_verial['Id'] ) ? absint( $pais_verial['Id'] ) : null,
				'Nombre' => isset( $pais_verial['Nombre'] ) ? sanitize_text_field( $pais_verial['Nombre'] ) : null,
				'ISO2'   => isset( $pais_verial['ISO2'] ) ? sanitize_text_field( $pais_verial['ISO2'] ) : null,
				'ISO3'   => isset( $pais_verial['ISO3'] ) ? sanitize_text_field( $pais_verial['ISO3'] ) : null,
			);
		}

		// Devolver el formato completo requerido por el Manual de Verial: InfoError + Paises
		$formatted_data = array(
			'InfoError' => array(
				'Codigo'      => self::VERIAL_ERROR_SUCCESS,
				'Descripcion' => null,
			),
			'Paises'    => $paises_formateados,
		);

		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$formatted_data,
			__( 'Países obtenidos correctamente.', 'mi-integracion-api' ),
			[
				'endpoint' => self::ENDPOINT_NAME,
				'items_count' => count( $paises_formateados ),
				'timestamp' => time()
			]
		);
	}

	/**
	 * Helper para construir una respuesta de error conforme al Manual de Verial.
	 *
	 * @param int $codigo Código de error del Manual.
	 * @param string $descripcion Descripción del error.
	 * @return array
	 */
	protected function build_error_response( int $codigo, string $descripcion ): array {
		return array(
			'InfoError' => array(
				'Codigo'      => $codigo,
				'Descripcion' => $descripcion,
			),
			'Paises' => array(),
		);
	}

	/**
	 * Ejecuta la lógica del endpoint.
	 *
	 * @param WP_REST_Request $request Datos de la solicitud.
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'get_paises', $api_key );
		if ( $rate_limit instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$rate_limit->isSuccess() ) {
			$resp = $this->build_error_response( 20, __( 'Límite de peticiones alcanzado. Intente más tarde.', 'mi-integracion-api' ) );
			return new \WP_REST_Response( $resp, 429 );
		}

		$params        = $request->get_params();
		$sesionwcf     = $params['x'] ?? '';

		// Validación robusta del parámetro de sesión
		if ( empty( $sesionwcf ) || ! is_numeric( $sesionwcf ) || (int) $sesionwcf <= 0 ) {
			$this->logger->warning( 'GetPaisesWS: Parámetro de sesión inválido', [
				'provided_session' => $sesionwcf,
				'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
			] );

			$resp = $this->build_error_response( 10, __( 'Falta un dato requerido: número de sesión válido.', 'mi-integracion-api' ) );
			return new \WP_REST_Response( $resp, 400 );
		}

		$cache_params_for_key = [
			'endpoint' => self::ENDPOINT_NAME,
			'session' => (int) $sesionwcf,
			'version' => '1.0'
		];

		$cached_data = $this->get_cached_data( $cache_params_for_key );
		if ( false !== $cached_data ) {
			$this->logger->debug( 'GetPaisesWS: Datos servidos desde caché' );
			// Normalizar formato si la caché contiene solo la lista de países
			if ( isset( $cached_data['Paises'] ) && isset( $cached_data['InfoError'] ) ) {
				return new \WP_REST_Response( $cached_data, 200 );
			}
			// Si la caché almacena sólo la lista, envolverla
			if ( is_array( $cached_data ) ) {
				$cached_data = array(
					'InfoError' => array('Codigo' => self::VERIAL_ERROR_SUCCESS, 'Descripcion' => null),
					'Paises' => $cached_data,
				);
				return new \WP_REST_Response( $cached_data, 200 );
			}
			// Fallback: devolver error formateado
			$resp = $this->build_error_response( 4, __( 'Error componiendo el JSON de salida desde caché.', 'mi-integracion-api' ) );
			return new \WP_REST_Response( $resp, 500 );
		}

		$verial_api_params = [ 'x' => (int) $sesionwcf ];
		
		try {
			$result = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );
			
			// Verificar si la respuesta de Verial es exitosa
			if ( $result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$result->isSuccess() ) {
				$this->logger->error( 'GetPaisesWS: Error en conectividad con Verial', [
					'error_code' => $result->getErrorCode(),
					'error_message' => $result->getMessage(),
					'session' => $sesionwcf
				] );
				$resp = $this->build_error_response( 2, __( 'Error de conectividad con el servidor Verial.', 'mi-integracion-api' ) );
				return new \WP_REST_Response( $resp, 502 );
			}

			// Si es SyncResponseInterface exitoso, obtener los datos
			$verial_data = $result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface 
				? $result->getData() 
				: $result;

			// Validar estructura de respuesta
			if ( ! isset( $verial_data['Paises'] ) || ! is_array( $verial_data['Paises'] ) ) {
				$this->logger->error( 'GetPaisesWS: Respuesta de Verial con formato inválido', [
					'response_keys' => array_keys( $verial_data ?? [] ),
					'session' => $sesionwcf
				] );
				
				$resp = $this->build_error_response( 4, __( 'Error componiendo el JSON de salida.', 'mi-integracion-api' ) );
				return new \WP_REST_Response( $resp, 502 );
			}

			$formatted = $this->format_verial_response( $verial_data );

			// Convertir SyncResponseInterface a WP_REST_Response usando WordPressAdapter
			$wp_response = \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse( $formatted );

			// Si la respuesta es exitosa, guardar en caché y registrar auditoría
			if ( $formatted->isSuccess() ) {
				$formatted_data = $formatted->getData();
				$this->set_cached_data( $cache_params_for_key, $formatted_data );
				
				$this->logger->info( 'GetPaisesWS: Operación exitosa', [
					'countries_count' => count( $formatted_data['Paises'] ?? [] ),
					'session' => $sesionwcf,
				] );
			}

			return $wp_response;

		} catch ( \Exception $e ) {
			$this->logger->error( 'GetPaisesWS: Excepción no controlada', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
				'session' => $sesionwcf
			] );
			$resp = $this->build_error_response( 17, __( 'Error guardando el documento de cliente en la base de datos.', 'mi-integracion-api' ) );
			return new \WP_REST_Response( $resp, 500 );
		}
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

/*
 * Ejemplo de cómo se registraría la ruta en el archivo principal del plugin:
 *
 * add_action('rest_api_init', function () {
 * // Asumir que $api_connector es una instancia de ApiConnector pasada por inyección de dependencias.
 * if (class_exists('MI_Endpoint_GetPaisesWS')) {
 *     $paises_endpoint = MI_Endpoint_GetPaisesWS::make($api_connector);
 *     $paises_endpoint->register_route();
 * }
 * });
 */
