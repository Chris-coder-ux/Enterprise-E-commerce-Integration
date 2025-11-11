<?php declare(strict_types=1);

namespace MiIntegracionApi\Endpoints;
/**
 * Clase para el endpoint GetMascotasWS de la API de Verial ERP.
 * Obtiene el listado de mascotas, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Helpers\AuthHelper;
use MiIntegracionApi\Helpers\Logger;

class GetMascotasWS extends \MiIntegracionApi\Endpoints\Base {

	use EndpointLogger;

	const ENDPOINT_NAME        = 'GetMascotasWS';
	// Usando constantes centralizadas de caché
	const CACHE_KEY_PREFIX = 'value';
	const VERIAL_ERROR_SUCCESS = 'value';

	public function __construct( \MiIntegracionApi\Core\ApiConnector $connector ) {
		parent::__construct( $connector );
		$this->init_logger( 'clientes' );
	}

	/**
	 * Comprueba los permisos para acceder al endpoint.
	 * Requiere manage_woocommerce y autenticación adicional (API key/JWT).
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public function permissions_check( \WP_REST_Request $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Usar JWT para autenticación primero
		if ( class_exists( 'MiIntegracionApi\Helpers\JwtAuthHelper' ) ) {
			// Verificar JWT con los permisos necesarios (manage_woocommerce)
			$jwt_callback = \MiIntegracionApi\Helpers\JwtAuthHelper::get_jwt_auth_callback( array( 'manage_woocommerce' ) );
			$jwt_result   = $jwt_callback( $request );
			if ( $jwt_result === true ) {
				return true;
			}
		}

		// Fallback a la autenticación tradicional
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

		// Verificar API key como último recurso
		$auth_result = AuthHelper::validate_rest_auth( $request );
		if ( $auth_result !== true ) {
			return $auth_result;
		}

		return true;
	}

	public function permissions_check_cliente_mascotas( \WP_REST_Request $request ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface|bool {
		// Reforzar: exigir manage_woocommerce + autenticación adicional (API key/JWT)
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$error_message = esc_html__( 'No tienes permiso para ver las mascotas de este cliente.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				[],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_forbidden_cliente_mascotas',
					$error_message,
					['status' => rest_authorization_required_code()]
				),
				rest_authorization_required_code(),
				$error_message,
				['endpoint' => 'permissions_check_cliente_mascotas']
			);
		}
		$auth_result = AuthHelper::validate_rest_auth( $request );
		if ( $auth_result !== true ) {
			return $auth_result;
		}
		return true;
	}

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
			'fecha_desde'   => array(
				'description'       => __( 'Fecha (YYYY-MM-DD) para filtrar mascotas creadas/modificadas desde esta fecha.', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_date_format_optional' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'context'       => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);

		if ( $is_update ) {
			$args['id_cliente_param'] = array(
				'description'       => __( 'ID del cliente en Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			);
		} else {
			$args['id_cliente'] = array(
				'description'       => __( 'ID del cliente en Verial para filtrar (0 para todas las mascotas de todos los clientes).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 0;
				},
			);
		}
		return $args;
	}

	public function validate_date_format_optional( string $value, \WP_REST_Request $request, string $key ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( $value === '' ) {
			return true;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$parts = explode( '-', $value );
			if ( count( $parts ) === 3 && checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
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

	/**
	 * Refuerza la validación del parámetro id_cliente_verial/id_cliente.
	 * - Debe ser numérico, mayor que cero y, opcionalmente, corresponder a un cliente válido.
	 * - Si el valor no es válido, retorna un error.
	 */
	private function validate_id_cliente($id_cliente): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if (!is_numeric($id_cliente) || (int) $id_cliente <= 0) {
			$error_message = esc_html__('El parámetro id_cliente_verial debe ser un número positivo.', 'mi-integracion-api');
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['field' => 'id_cliente', 'value' => $id_cliente],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_invalid_param',
					$error_message,
					['status' => 400, 'field' => 'id_cliente']
				),
				400,
				$error_message,
				['endpoint' => 'validate_id_cliente']
			);
		}
		// Aquí se podría agregar una validación extra para comprobar si el cliente existe en la base de datos.
		return true;
	}

	protected function format_verial_response( array $verial_response ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! isset( $verial_response['Mascotas'] ) || ! is_array( $verial_response['Mascotas'] ) ) {
			$this->logger->errorCliente(
				'[MI Integracion API] La respuesta de Verial no contiene la clave "Mascotas" esperada o no es un array.',
				array( 'verial_response' => $verial_response )
			);
			$error_message = __( 'Los datos de mascotas recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['verial_response' => $verial_response],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'verial_api_malformed_mascotas_data',
					$error_message,
					['status' => 500]
				),
				500,
				$error_message,
				['endpoint' => 'format_verial_response']
			);
		}

		$mascotas = array();
		foreach ( $verial_response['Mascotas'] as $mascota_verial ) {
			$mascotas[] = array(
				'id_verial'                      => isset( $mascota_verial['Id'] ) ? (int) $mascota_verial['Id'] : null,
				'id_cliente_verial'              => isset( $mascota_verial['ID_Cliente'] ) ? (int) $mascota_verial['ID_Cliente'] : null,
				'nombre'                         => isset( $mascota_verial['Nombre'] ) ? sanitize_text_field( $mascota_verial['Nombre'] ) : null,
				'tipo_animal'                    => isset( $mascota_verial['TipoAnimal'] ) ? sanitize_text_field( $mascota_verial['TipoAnimal'] ) : null,
				'raza'                           => isset( $mascota_verial['Raza'] ) ? sanitize_text_field( $mascota_verial['Raza'] ) : null,
				'fecha_nacimiento'               => isset( $mascota_verial['FechaNacimiento'] ) ? sanitize_text_field( $mascota_verial['FechaNacimiento'] ) : null,
				'peso_kg'                        => isset( $mascota_verial['Peso'] ) ? (float) $mascota_verial['Peso'] : null,
				'situacion_peso'                 => isset( $mascota_verial['SituacionPeso'] ) ? (int) $mascota_verial['SituacionPeso'] : null,
				'actividad'                      => isset( $mascota_verial['Actividad'] ) ? (int) $mascota_verial['Actividad'] : null,
				'tiene_patologias'               => isset( $mascota_verial['HayPatologias'] ) ? rest_sanitize_boolean( $mascota_verial['HayPatologias'] ) : null,
				'patologias_descripcion'         => isset( $mascota_verial['Patologias'] ) ? sanitize_textarea_field( $mascota_verial['Patologias'] ) : null,
				'alimentacion_tipo'              => isset( $mascota_verial['Alimentacion'] ) ? (int) $mascota_verial['Alimentacion'] : null,
				'alimentacion_otros_descripcion' => isset( $mascota_verial['AlimentacionOtros'] ) ? sanitize_textarea_field( $mascota_verial['AlimentacionOtros'] ) : null,
			);
		}
		
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$mascotas,
			__( 'Mascotas obtenidas correctamente.', 'mi-integracion-api' ),
			[
				'endpoint' => self::ENDPOINT_NAME,
				'items_count' => count( $mascotas ),
				'timestamp' => time()
			]
		);
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'get_mascotas', $api_key );
		if ( $rate_limit instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$rate_limit->isSuccess() ) {
			return $rate_limit;
		}

		$params        = $request->get_params();
		$sesionwcf     = $params['sesionwcf'];
		$force_refresh = $params['force_refresh'] ?? false;

		$id_cliente_filter = 0;
		if ( isset( $request['id_cliente_param'] ) ) {
			$id_cliente_filter = absint( $request['id_cliente_param'] );
		} elseif ( isset( $params['id_cliente'] ) ) {
			$id_cliente_filter = absint( $params['id_cliente'] );
		}

		// Validación reforzada
		$validacion = $this->validate_id_cliente($id_cliente_filter);
		if ( $validacion instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$validacion->isSuccess() ) {
			return rest_ensure_response($validacion);
		}

		$fecha_desde_filter = $params['fecha_desde'] ?? null;

		$cache_params_for_key = array(
			'sesionwcf'   => $sesionwcf,
			'id_cliente'  => $id_cliente_filter,
			'fecha_desde' => $fecha_desde_filter,
		);

		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_params_for_key );
			if ( false !== $cached_data ) {
				return new \WP_REST_Response( $cached_data, 200 );
			}
		}

		$verial_api_params = array(
			'x'          => $sesionwcf,
			'id_cliente' => $id_cliente_filter,
			'fecha_desde' => $fecha_desde_filter,
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
		
		// Si la respuesta es exitosa, guardar en caché
		if ( $formatted_response->isSuccess() && $this->use_cache() ) {
			$formatted_data = $formatted_response->getData();
			// Usar la función base para formatear la respuesta estándar
			$formatted_response_data = $this->format_success_response($formatted_data);
			$this->set_cached_data( $cache_params_for_key, $formatted_response_data );
			
			// Log de auditoría si llegamos hasta aquí con éxito
			if ( class_exists( 'MiIntegracionApi\\Helpers\\Logger' ) ) {
				\MiIntegracionApi\Helpers\Logger::log(
					'Acceso a GetMascotasWS',
					\MiIntegracionApi\Helpers\Logger::LEVEL_INFO,
					array(
						'params'  => $verial_api_params,
						'usuario' => get_current_user_id(),
					),
					'auditoria'
				);
			}
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
 */
// add_action('rest_api_init', function () {
// $api_connector = new ApiConnector();
// $mascotas_endpoint = new MI_Endpoint_GetMascotasWS($api_connector);
// $mascotas_endpoint->register_route();
// });
