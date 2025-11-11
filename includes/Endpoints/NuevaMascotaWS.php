<?php declare(strict_types=1);
/**
 * Clase para el endpoint NuevaMascotaWS de la API de Verial ERP.
 * Da de alta o modifica los datos de un registro de mascota, según el manual v1.8.4.
 *
 * @author Christian (F4B - endpoint faltante)
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Constants\VerialTypes;
use WP_REST_Request;

/**
 * Clase para gestionar el endpoint NuevaMascotaWS
 */
class NuevaMascotaWS extends Base {

	use EndpointLogger;

	public const ENDPOINT_NAME = 'NuevaMascotaWS';
	// Usando constantes centralizadas de caché

	/**
	 * Constructor para el endpoint NuevaMascotaWS
	 */
	public function __construct( \MiIntegracionApi\Core\ApiConnector $api_connector ) {
		parent::__construct( $api_connector );
		$this->init_logger();
	}

	/**
	 * Registra la ruta REST WP para este endpoint
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/nueva-mascota',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $this->get_endpoint_args(),
			)
		);
	}

	/**
	 * Verificar permisos para acceso al endpoint
	 */
	public function permissions_check( WP_REST_Request $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$error_message = esc_html__( 'No tienes permiso para crear/modificar mascotas.', 'mi-integracion-api' );
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
	 * Obtener argumentos del endpoint según documentación oficial v1.8.4
	 */
	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'sesionwcf'         => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			),
			'ID_Cliente'        => array(
				'description'       => __( 'Identificador del cliente al que pertenece la mascota.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			),
			'Id'                => array(
				'description'       => __( 'ID de mascota existente (solo para modificar, omitir para crear nueva).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'Nombre'            => array(
				'description'       => __( 'Nombre de la mascota.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => function ( $param ) {
					return ! empty( trim( $param ) ) && strlen( $param ) <= 50;
				},
			),
			'TipoAnimal'        => array(
				'description'       => __( 'Tipo de animal.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => function ( $param ) {
					return empty( $param ) || strlen( $param ) <= 50;
				},
			),
			'Raza'              => array(
				'description'       => __( 'Raza del animal.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => function ( $param ) {
					return empty( $param ) || strlen( $param ) <= 50;
				},
			),
			'FechaNacimiento'   => array(
				'description'       => __( 'Fecha de nacimiento (YYYY-MM-DD).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => function ( $param ) {
					if ( empty( $param ) ) {
						return true;
					}
					return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $param );
				},
			),
			'Peso'              => array(
				'description'       => __( 'Peso de la mascota (decimal).', 'mi-integracion-api' ),
				'type'              => 'number',
				'required'          => false,
				'validate_callback' => function ( $param ) {
					return empty( $param ) || ( is_numeric( $param ) && $param >= 0 );
				},
			),
			'SituacionPeso'     => array(
				'description'       => __( 'Situación del peso: 0=No especificada, 1=Por debajo, 2=En peso, 3=Por encima.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'validate_callback' => function ( $param ) {
					return in_array( (int) $param, array( 0, 1, 2, 3 ), true );
				},
			),
			'Actividad'         => array(
				'description'       => __( 'Actividad: 0=No especificada, 1=Activo, 2=Normal, 3=Poco activo.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'validate_callback' => function ( $param ) {
					return in_array( (int) $param, array( 0, 1, 2, 3 ), true );
				},
			),
			'HayPatologias'     => array(
				'description'       => __( 'Indica si tiene patologías/alergias.', 'mi-integracion-api' ),
				'type'              => 'boolean',
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'Patologias'        => array(
				'description'       => __( 'Descripción de patologías/alergias.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
			),
			'Alimentacion'      => array(
				'description'       => __( 'Alimentación: 0=No especificada, 1=Barf, 2=Pienso, 3=Otros.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'validate_callback' => function ( $param ) {
					return in_array( (int) $param, array( 0, 1, 2, 3 ), true );
				},
			),
			'AlimentacionOtros' => array(
				'description'       => __( 'Descripción cuando alimentación es "Otros".', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
			),
		);
	}

	/**
	 * Ejecutar llamada al endpoint NuevaMascotaWS
	 */
	public function execute_restful( WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		try {
			$this->log_info( 'Iniciando ejecución', array(
				'endpoint' => self::ENDPOINT_NAME,
				'params'   => $this->sanitize_params_for_log( $request->get_params() )
			) );

			// Preparar datos para envío
			$post_data = $this->prepare_post_data( $request );
			$url = $this->api_connector->get_api_url() . '/' . self::ENDPOINT_NAME;

			// Ejecutar llamada HTTP POST
			$response = $this->api_connector->execute_post_request( $url, $post_data );

			if ( $response instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$response->isSuccess() ) {
				$this->log_error( 'Error en request HTTP', array(
					'error' => $response->getMessage(),
					'url'   => $url
				) );
				return $response;
			}

			// Procesar respuesta
			$processed_response = $this->process_api_response( $response );

			return rest_ensure_response( $processed_response );

		} catch ( \Throwable $e ) {
			$this->log_error( 'Error crítico en execute_restful', array(
				'message' => $e->getMessage(),
				'trace'   => $e->getTraceAsString()
			) );

			$error_message = __( 'Error interno del servidor al crear/modificar mascota.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['exception' => $e->getMessage()],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'internal_error',
					$error_message,
					['status' => 500]
				),
				500,
				$error_message,
				['endpoint' => 'execute_restful']
			);
		}
	}

	/**
	 * Preparar datos para envío POST según documentación oficial
	 */
	private function prepare_post_data( WP_REST_Request $request ): array {
		$data = array(
			'sesionwcf'  => $request->get_param( 'sesionwcf' ),
			'ID_Cliente' => $request->get_param( 'ID_Cliente' ),
			'Nombre'     => sanitize_text_field( $request->get_param( 'Nombre' ) ),
		);

		// Campos opcionales
		$optional_fields = array(
			'Id', 'TipoAnimal', 'Raza', 'FechaNacimiento', 'Peso',
			'SituacionPeso', 'Actividad', 'HayPatologias', 'Patologias',
			'Alimentacion', 'AlimentacionOtros'
		);

		foreach ( $optional_fields as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null && $value !== '' ) {
				if ( in_array( $field, array( 'TipoAnimal', 'Raza', 'Patologias', 'AlimentacionOtros' ), true ) ) {
					$data[ $field ] = sanitize_textarea_field( $value );
				} elseif ( $field === 'HayPatologias' ) {
					$data[ $field ] = rest_sanitize_boolean( $value );
				} else {
					$data[ $field ] = $value;
				}
			}
		}

		return $data;
	}

	/**
	 * Procesar respuesta de la API según estructura oficial
	 */
	private function process_api_response( $response ) {
		if ( empty( $response ) || ! is_string( $response ) ) {
			$error_message = __( 'Respuesta vacía o inválida del servidor Verial.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['response' => $response],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'invalid_response',
					$error_message,
					['status' => 502]
				),
				502,
				$error_message,
				['endpoint' => 'process_api_response']
			);
		}

		$decoded = json_decode( $response, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->log_error( 'Error decodificando JSON', array(
				'json_error' => json_last_error_msg(),
				'response'   => substr( $response, 0, 500 )
			) );

			$error_message = __( 'Error al decodificar respuesta JSON de Verial.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['json_error' => json_last_error_msg(), 'response' => substr( $response, 0, 500 )],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'json_decode_error',
					$error_message,
					['status' => 502]
				),
				502,
				$error_message,
				['endpoint' => 'process_api_response']
			);
		}

		// Verificar estructura de respuesta según documentación
		if ( ! isset( $decoded['InfoError'] ) ) {
			$error_message = __( 'Estructura de respuesta inválida - falta InfoError.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['decoded' => $decoded],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'invalid_structure',
					$error_message,
					['status' => 502]
				),
				502,
				$error_message,
				['endpoint' => 'process_api_response']
			);
		}

		$info_error = $decoded['InfoError'];
		if ( isset( $info_error['Codigo'] ) && $info_error['Codigo'] !== VerialTypes::VERIAL_ERROR_SUCCESS ) {
			$this->log_error( 'Error reportado por Verial', array(
				'codigo'      => $info_error['Codigo'],
				'descripcion' => $info_error['Descripcion'] ?? 'Sin descripción'
			) );

			$error_message = sprintf(
				__( 'Error de Verial (%d): %s', 'mi-integracion-api' ),
				$info_error['Codigo'],
				$info_error['Descripcion'] ?? __( 'Sin descripción', 'mi-integracion-api' )
			);
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['info_error' => $info_error],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'verial_api_error',
					$error_message,
					['status' => 400]
				),
				400,
				$error_message,
				['endpoint' => 'process_api_response']
			);
		}

		// Procesar datos de mascota según documentación oficial
		$mascota_data = array();
		$required_fields = array( 'Id', 'ID_Cliente', 'Nombre' );
		
		foreach ( $required_fields as $field ) {
			if ( isset( $decoded[ $field ] ) ) {
				$mascota_data[ $field ] = $decoded[ $field ];
			}
		}

		// Campos opcionales con valores predeterminados
		$optional_fields = array(
			'TipoAnimal', 'Raza', 'FechaNacimiento', 'Peso',
			'SituacionPeso', 'Actividad', 'HayPatologias', 'Patologias',
			'Alimentacion', 'AlimentacionOtros'
		);

		foreach ( $optional_fields as $field ) {
			if ( isset( $decoded[ $field ] ) ) {
				$mascota_data[ $field ] = $decoded[ $field ];
			}
		}

		// Añadir descripciones legibles
		$mascota_data['SituacionPeso_Descripcion'] = $this->get_situacion_peso_description( (int) ( $mascota_data['SituacionPeso'] ?? 0 ) );
		$mascota_data['Actividad_Descripcion'] = $this->get_actividad_description( (int) ( $mascota_data['Actividad'] ?? 0 ) );
		$mascota_data['Alimentacion_Descripcion'] = $this->get_alimentacion_description( (int) ( $mascota_data['Alimentacion'] ?? 0 ) );

		return array(
			'success'   => true,
			'data'      => array(
				'mascota'   => $mascota_data,
				'InfoError' => $info_error
			),
			'timestamp' => current_time( 'mysql' )
		);
	}

	/**
	 * Obtener descripción de situación de peso
	 */
	private function get_situacion_peso_description( int $situacion ): string {
		$situaciones = array(
			0 => __( 'No especificada', 'mi-integracion-api' ),
			1 => __( 'Por debajo', 'mi-integracion-api' ),
			2 => __( 'En peso', 'mi-integracion-api' ),
			3 => __( 'Por encima', 'mi-integracion-api' ),
		);

		return $situaciones[ $situacion ] ?? __( 'Desconocido', 'mi-integracion-api' );
	}

	/**
	 * Obtener descripción de actividad
	 */
	private function get_actividad_description( int $actividad ): string {
		$actividades = array(
			0 => __( 'No especificada', 'mi-integracion-api' ),
			1 => __( 'Activo', 'mi-integracion-api' ),
			2 => __( 'Normal', 'mi-integracion-api' ),
			3 => __( 'Poco activo', 'mi-integracion-api' ),
		);

		return $actividades[ $actividad ] ?? __( 'Desconocido', 'mi-integracion-api' );
	}

	/**
	 * Obtener descripción de alimentación
	 */
	private function get_alimentacion_description( int $alimentacion ): string {
		$alimentaciones = array(
			0 => __( 'No especificada', 'mi-integracion-api' ),
			1 => __( 'Barf', 'mi-integracion-api' ),
			2 => __( 'Pienso', 'mi-integracion-api' ),
			3 => __( 'Otros', 'mi-integracion-api' ),
		);

		return $alimentaciones[ $alimentacion ] ?? __( 'Desconocido', 'mi-integracion-api' );
	}

	/**
	 * Sanitizar parámetros para logging (ocultar datos sensibles)
	 */
	private function sanitize_params_for_log( array $params ): array {
		$safe_params = $params;
		if ( isset( $safe_params['sesionwcf'] ) ) {
			$safe_params['sesionwcf'] = '***';
		}
		return $safe_params;
	}
}

// Ejemplo de uso (comentado para no ejecutar automáticamente)
/*
if ( class_exists( 'ApiConnector' ) && class_exists( 'MiIntegracionApi\\Endpoints\\NuevaMascotaWS' ) ) {
	$api_connector = \MiIntegracionApi\Helpers\ApiHelpers::get_connector();
	$mascota_endpoint = new MiIntegracionApi\Endpoints\NuevaMascotaWS( $api_connector );
	// $resultado = $mascota_endpoint->execute( array( 'sesionwcf' => 18, 'ID_Cliente' => 123, 'Nombre' => 'Rex' ) );
}
*/
