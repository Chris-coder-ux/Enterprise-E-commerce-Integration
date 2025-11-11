<?php declare(strict_types=1);

namespace MiIntegracionApi\Endpoints;
/**
 * Clase para el endpoint GetCategoriasWebWS de la API de Verial ERP.
 * Obtiene el listado de categorías web de artículos, según el manual v1.7.5.
 *
 * @author Christian (con mejoras)
 * @package mi-integracion-api
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Constants\VerialTypes;

class GetCategoriasWebWS extends \MiIntegracionApi\Endpoints\Base {

	use EndpointLogger;

	const ENDPOINT_NAME               = 'GetCategoriasWebWS';
	// Usando constantes centralizadas de caché
	const CACHE_KEY_PREFIX            = 'mi_api_categorias_web_';// 12 horas
	// Límites de longitud - Usando constantes centralizadas de VerialTypes
	const VERIAL_ERROR_SUCCESS        = 0;

	public function __construct( \MiIntegracionApi\Core\ApiConnector $connector ) {
		parent::__construct( $connector );
		$this->init_logger(); // Logger base, no específico de productos
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
					['status' => \MiIntegracionApi\Helpers\RestHelpers::rest_authorization_required_code()]
				),
				\MiIntegracionApi\Helpers\RestHelpers::rest_authorization_required_code(),
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
			'sesionwcf'        => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'force_refresh'    => array(
				'description'       => __( 'Forzar la actualización de la caché.', 'mi-integracion-api' ),
				'type'              => 'boolean',
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'nombre_categoria' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= VerialTypes::MAX_LENGTH_COMENTARIO;
				},
			),
			'context'          => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
	}

	protected function format_verial_response( array $verial_response ) {
		$data_key = '';
		if ( isset( $verial_response['CategoriasWeb'] ) && is_array( $verial_response['CategoriasWeb'] ) ) {
			$data_key = 'CategoriasWeb';
		} elseif ( isset( $verial_response['Categorias'] ) && is_array( $verial_response['Categorias'] ) ) {
			$this->logger->errorProducto(
				'[MI Integracion API] GetCategoriasWebWS: El array llegó como "Categorias" y no como "CategoriasWeb".',
				array( 'response' => $verial_response )
			);
			$data_key = 'Categorias';
		} else {
			$error_message = __( 'Los datos de categorías web recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['verial_response' => $verial_response],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'verial_api_malformed_categorias_web_data',
					$error_message,
					['status' => 500]
				),
				500,
				$error_message,
				['endpoint' => 'format_verial_response']
			);
		}

		$categorias_web = array();
		foreach ( $verial_response[ $data_key ] as $categoria_verial ) {
			$categorias_web[] = array(
				'id'               => isset( $categoria_verial['Id'] ) ? absint( $categoria_verial['Id'] ) : null,
				'id_padre'         => isset( $categoria_verial['ID_Padre'] ) ? absint( $categoria_verial['ID_Padre'] ) : null,
				'nombre'           => isset( $categoria_verial['Nombre'] ) ? sanitize_text_field( $categoria_verial['Nombre'] ) : null,
				'clave_ordenacion' => isset( $categoria_verial['Clave'] ) ? sanitize_text_field( $categoria_verial['Clave'] ) : null,
			);
		}
		return $categorias_web;
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'get_categorias_web', $api_key );
		if ( $rate_limit instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$rate_limit->isSuccess() ) {
			return rest_ensure_response( $rate_limit );
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
				return rest_ensure_response( $cached_data );
			}
		}

		$verial_api_params = array( 'x' => $sesionwcf );
		$result            = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );

		if ( !($result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$result->isSuccess()) && $this->use_cache() ) {
			$this->set_cached_data( $cache_params_for_key, $result );
		}

		return rest_ensure_response( $result );
	}
}
