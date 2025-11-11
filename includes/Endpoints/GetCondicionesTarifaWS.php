<?php declare(strict_types=1);

/**
 * Clase para el endpoint GetCondicionesTarifaWS de la API de Verial ERP.
 * Obtiene las condiciones de tarifa para la venta, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\Helpers\rest_authorization_required_code;
use MiIntegracionApi\Helpers\AuthHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;

class GetCondicionesTarifaWS extends Base {

	use EndpointLogger;

	const ENDPOINT_NAME        = 'GetCondicionesTarifaWS';
	// Usando constantes centralizadas de caché
	const CACHE_KEY_PREFIX = 'value';
	const VERIAL_ERROR_SUCCESS = 'value';

	public function __construct( \MiIntegracionApi\Core\ApiConnector $connector ) {
		parent::__construct( $connector );
		$this->init_logger(); // Logger base, no específico de productos
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
			'x'             => array(
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
			'id_articulo'   => array(
				'description'       => __( 'ID del artículo (0 para todos los artículos).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 0;
				},
			),
			'id_cliente'    => array(
				'description'       => __( 'ID del cliente (0 para tarifa general web).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 0;
				},
			),
			'id_tarifa'     => array(
				'description'       => __( 'ID de la tarifa (si no se especifica, usa general web o del cliente).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 0;
				},
			),
			'fecha'         => array(
				'description'       => __( 'Fecha para calcular precios (si es distinta a la actual).', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_date_format_optional' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'art_fecha'     => array(
				'description'       => __( 'Filtrar artículos creados o modificados en una fecha igual o posterior a la especificada.', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_date_format_optional' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'art_hora'      => array(
				'description'       => __( 'Añadir la hora al parámetro de fecha para filtrar con más precisión.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'art_inicio'    => array(
				'description'       => __( 'Índice del primer artículo a recuperar (empieza desde 1).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 1;
				},
			),
			'art_fin'       => array(
				'description'       => __( 'Índice del último artículo a recuperar (empieza desde 1).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 1;
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
	 * Valida que un valor sea una fecha opcional en formato ISO
	 *
	 * @param mixed            $value Valor a validar
	 * @param \WP_REST_Request $request Petición
	 * @param string           $key Clave del parámetro
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface True si es válido, SyncResponseInterface si no
	 */
	public function validate_date_format_optional( string $value, \WP_REST_Request $request, string $key ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( empty( $value ) ) {
			return true;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$parts = explode( '-', $value );
			if ( checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
				return true;
			}
		}
		$error_message = sprintf( esc_html__( '%s debe ser una fecha válida en formato YYYY-MM-DD.', 'mi-integracion-api' ), $key );
		return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
			false,
			['field' => $key, 'value' => $value],
			new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
				'rest_invalid_param',
				$error_message,
				['status' => 400, 'field' => $key]
			),
			400,
			$error_message,
			['endpoint' => 'validate_date_format_optional']
		);
	}

	protected function format_verial_response( array $verial_response ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! isset( $verial_response['CondicionesTarifa'] ) || ! is_array( $verial_response['CondicionesTarifa'] ) ) {
			$this->logger->errorProducto(
				'[GetCondicionesTarifaWS] La respuesta de Verial no contiene la clave "CondicionesTarifa" esperada o no es un array.',
				array( 'verial_response' => $verial_response )
			);
			$error_message = __( 'Los datos de condiciones de tarifa recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['verial_response' => $verial_response],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'verial_api_malformed_condiciones_data',
					$error_message,
					['status' => 500]
				),
				500,
				$error_message,
				['endpoint' => 'format_verial_response']
			);
		}

		$condiciones = array();
		foreach ( $verial_response['CondicionesTarifa'] as $condicion_verial ) {
			$condiciones[] = array(
				'ID_Articulo'    => isset( $condicion_verial['ID_Articulo'] ) ? (int) $condicion_verial['ID_Articulo'] : null,
				'Precio'         => isset( $condicion_verial['Precio'] ) ? (float) $condicion_verial['Precio'] : null,
				'Dto'            => isset( $condicion_verial['Dto'] ) ? (float) $condicion_verial['Dto'] : null,
				'DtoEurosXUd'    => isset( $condicion_verial['DtoEurosXUd'] ) ? (float) $condicion_verial['DtoEurosXUd'] : null,
				'UdsMin'         => isset( $condicion_verial['UdsMin'] ) ? (float) $condicion_verial['UdsMin'] : null,
				'UdsRegalo'      => isset( $condicion_verial['UdsRegalo'] ) ? (float) $condicion_verial['UdsRegalo'] : null,
			);
		}
		
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$condiciones,
			__( 'Condiciones de tarifa obtenidas correctamente.', 'mi-integracion-api' ),
			[
				'endpoint' => self::ENDPOINT_NAME,
				'items_count' => count( $condiciones ),
				'timestamp' => time()
			]
		);
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'get_condiciones_tarifa', $api_key );
		if ( $rate_limit instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$rate_limit->isSuccess() ) {
			return $rate_limit;
		}

		$params        = $request->get_params();
		$x             = $params['x'] ?? null;
		$force_refresh = $params['force_refresh'] ?? false;
		
		// Validar que x esté presente
		if (empty($x)) {
			return new \WP_REST_Response([
				'error' => 'Parámetro x es requerido',
				'code' => 'missing_x_parameter'
			], 400);
		}

		$verial_api_params = array( 'x' => $x );
		if ( isset( $params['id_articulo'] ) ) {
			$verial_api_params['id_articulo'] = $params['id_articulo'];
		}
		if ( isset( $params['id_cliente'] ) ) {
			$verial_api_params['id_cliente'] = $params['id_cliente'];
		}
		if ( isset( $params['id_tarifa'] ) ) {
			$verial_api_params['id_tarifa'] = $params['id_tarifa'];
		}
		if ( isset( $params['fecha'] ) ) {
			$verial_api_params['fecha'] = $params['fecha'];
		}
		if ( isset( $params['art_fecha'] ) ) {
			$verial_api_params['art_fecha'] = $params['art_fecha'];
		}
		if ( isset( $params['art_hora'] ) ) {
			$verial_api_params['art_hora'] = $params['art_hora'];
		}
		if ( isset( $params['art_inicio'] ) ) {
			$verial_api_params['art_inicio'] = $params['art_inicio'];
		}
		if ( isset( $params['art_fin'] ) ) {
			$verial_api_params['art_fin'] = $params['art_fin'];
		}

		$cache_params_for_key = array(
			'x'           => $x,
			'id_articulo' => $params['id_articulo'] ?? 0,
			'id_cliente'  => $params['id_cliente'] ?? 0,
			'id_tarifa'   => $params['id_tarifa'] ?? null,
			'fecha'       => $params['fecha'] ?? null,
			'art_fecha'   => $params['art_fecha'] ?? null,
			'art_hora'    => $params['art_hora'] ?? null,
			'art_inicio'  => $params['art_inicio'] ?? null,
			'art_fin'     => $params['art_fin'] ?? null,
		);

		if ( ! $force_refresh ) {
			$cache_key = 'condiciones_tarifa_' . md5( json_encode( $cache_params_for_key ) );
			$cached_data = $this->get_cached_data( $cache_key );
			if ( false !== $cached_data ) {
				return new \WP_REST_Response( $cached_data, 200 );
			}
		}

		$response_verial = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );
		if ( $response_verial instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$response_verial->isSuccess() ) {
			return rest_ensure_response( $response_verial );
		}
		
		// Formatear la respuesta de Verial
		$formatted_response = $this->format_verial_response( $response_verial );
		if ( $formatted_response instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$formatted_response->isSuccess() ) {
			return rest_ensure_response( $formatted_response );
		}
		
		// Convertir SyncResponseInterface a WP_REST_Response usando WordPressAdapter
		$wp_response = \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse( $formatted_response );
		
		// Si la respuesta es exitosa, guardar en caché
		if ( $formatted_response->isSuccess() ) {
			$formatted_data = $formatted_response->getData();
			$cache_key = 'condiciones_tarifa_' . md5( json_encode( $cache_params_for_key ) );
			$this->set_cached_data( $cache_key, $formatted_data );
		}
		
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
			'/getcondicionestarifaws',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}
}

/**
 * Función para registrar las rutas (ejemplo).
 */
// add_action('rest_api_init', function () {
// $api_connector = new ApiConnector();
// $condiciones_tarifa_endpoint = new MI_Endpoint_GetCondicionesTarifaWS($api_connector);
// $condiciones_tarifa_endpoint->register_route();
// });
