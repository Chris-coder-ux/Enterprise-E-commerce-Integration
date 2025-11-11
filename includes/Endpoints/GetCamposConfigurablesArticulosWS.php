<?php declare(strict_types=1);
/**
 * Clase para el endpoint GetCamposConfigurablesArticulosWS de la API de Verial ERP.
 * Obtiene la información de los campos configurables por el usuario, según el manual v1.8.4.
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
 * Clase para gestionar el endpoint GetCamposConfigurablesArticulosWS
 */
class GetCamposConfigurablesArticulosWS extends Base {

	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetCamposConfigurablesArticulosWS';
	// Usando constantes centralizadas de caché
	public const CACHE_KEY_PREFIX     = 'mi_api_get_campos_conf_';
	public const VERIAL_ERROR_SUCCESS = 0;

	/**
	 * Constructor para el endpoint GetCamposConfigurablesArticulosWS
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
			'/campos-configurables-articulos',
			array(
				'methods'             => 'GET',
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
			$error_message = esc_html__( 'No tienes permiso para ver los campos configurables de artículos.', 'mi-integracion-api' );
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
			'x'             => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			),
			'force_refresh' => array(
				'description'       => __( 'Forzar actualización de caché.', 'mi-integracion-api' ),
				'type'              => 'boolean',
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Ejecutar llamada al endpoint GetCamposConfigurablesArticulosWS
	 */
	public function execute_restful( WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		try {
			$this->log_info( 'Iniciando ejecución', array(
				'endpoint' => self::ENDPOINT_NAME,
				'params'   => $request->get_params()
			) );

			// Construir URL con parámetros
			$url_params = $this->build_url_params( $request );
			$url = $this->api_connector->get_api_url() . '/' . self::ENDPOINT_NAME . '?' . $url_params;

			// Verificar caché
			$cache_key = $this->generate_cache_key( $url_params );
			if ( ! $request->get_param( 'force_refresh' ) ) {
				$cached_result = $this->get_cached_data( $cache_key );
				if ( $cached_result !== false ) {
					$this->log_info( 'Datos obtenidos desde caché', array( 'cache_key' => $cache_key ) );
					return rest_ensure_response( $cached_result );
				}
			}

			// Ejecutar llamada HTTP
			$response = $this->api_connector->execute_get_request( $url );

			if ( $response instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$response->isSuccess() ) {
				$this->log_error( 'Error en request HTTP', array(
					'error' => $response->getMessage(),
					'url'   => $url
				) );
				return $response;
			}

			// Procesar respuesta
			$processed_response = $this->process_api_response( $response );
			
			if ( !($processed_response instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$processed_response->isSuccess()) ) {
				// Guardar en caché solo si es exitoso
				$this->cache_data( $cache_key, $processed_response );
				$this->log_info( 'Datos guardados en caché', array( 'cache_key' => $cache_key ) );
			}

			return rest_ensure_response( $processed_response );

		} catch ( \Throwable $e ) {
			$this->log_error( 'Error crítico en execute_restful', array(
				'message' => $e->getMessage(),
				'trace'   => $e->getTraceAsString()
			) );

			$error_message = __( 'Error interno del servidor al obtener campos configurables.', 'mi-integracion-api' );
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
	 * Construir parámetros URL según documentación oficial
	 */
	private function build_url_params( WP_REST_Request $request ): string {
		return http_build_query( array(
			'x' => $request->get_param( 'x' )
		) );
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
		if ( isset( $info_error['Codigo'] ) && $info_error['Codigo'] !== self::VERIAL_ERROR_SUCCESS ) {
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

		// Procesar campos según documentación oficial
		$campos = $decoded['Campos'] ?? array();
		$processed_campos = array();

		foreach ( $campos as $campo ) {
			// Validar estructura mínima requerida
			if ( ! isset( $campo['Id'] ) ) {
				continue;
			}

			$processed_campos[] = array(
				'Id'                             => (int) $campo['Id'],
				'ID_FamiliaCamposConfigurables'  => (int) ( $campo['ID_FamiliaCamposConfigurables'] ?? 0 ),
				'TipoDato'                       => (int) ( $campo['TipoDato'] ?? VerialTypes::DATA_TYPE_TEXT ),
				'Descripcion'                    => sanitize_text_field( $campo['Descripcion'] ?? '' ),
				'NumEnteros'                     => (int) ( $campo['NumEnteros'] ?? 0 ),
				'NumDecimales'                   => (int) ( $campo['NumDecimales'] ?? 0 ),
				'Validado'                       => (bool) ( $campo['Validado'] ?? false ),
				'TextoConFormato'                => (bool) ( $campo['TextoConFormato'] ?? false ),
				'Orden'                          => (int) ( $campo['Orden'] ?? 0 ),
				'Grupo'                          => sanitize_text_field( $campo['Grupo'] ?? '' ),
				// Información adicional para mejor comprensión
				'TipoDato_Descripcion'           => $this->get_tipo_dato_description( (int) ( $campo['TipoDato'] ?? VerialTypes::DATA_TYPE_TEXT ) ),
			);
		}

		// Ordenar por grupo y orden
		usort( $processed_campos, function( $a, $b ) {
			if ( $a['Grupo'] === $b['Grupo'] ) {
				return $a['Orden'] <=> $b['Orden'];
			}
			return $a['Grupo'] <=> $b['Grupo'];
		} );

		return array(
			'success'   => true,
			'data'      => array(
				'Campos'     => $processed_campos,
				'Total'      => count( $processed_campos ),
				'Grupos'     => array_unique( array_column( $processed_campos, 'Grupo' ) ),
				'InfoError'  => $info_error
			),
			'timestamp' => current_time( 'mysql' )
		);
	}

	/**
	 * Obtener descripción del tipo de dato según documentación
	 */
	private function get_tipo_dato_description( int $tipo_dato ): string {
		$tipos = array(
			VerialTypes::DATA_TYPE_TEXT => __( 'Alfanumérico (máx. 50 caracteres)', 'mi-integracion-api' ),
			VerialTypes::DATA_TYPE_NUMBER => __( 'Lógico (True/False)', 'mi-integracion-api' ),
			VerialTypes::DATA_TYPE_DECIMAL => __( 'Fecha', 'mi-integracion-api' ),
			VerialTypes::DATA_TYPE_DATE => __( 'Numérico entero', 'mi-integracion-api' ),
			VerialTypes::DATA_TYPE_BOOLEAN => __( 'Numérico decimal', 'mi-integracion-api' ),
			VerialTypes::DATA_TYPE_JSON => __( 'Alfanumérico (sin límite)', 'mi-integracion-api' ),
			VerialTypes::DATA_TYPE_ARRAY_JSON => __( 'Identificador de registro de árbol', 'mi-integracion-api' ),
		);

		return $tipos[ $tipo_dato ] ?? __( 'Tipo desconocido', 'mi-integracion-api' );
	}

	/**
	 * Generar clave de caché única
	 */
	private function generate_cache_key( string $url_params ): string {
		return self::CACHE_KEY_PREFIX . md5( $url_params );
	}

	/**
	 * Obtener datos de caché
	 */
	public function get_cached_data(array $key ): mixed {
		return wp_cache_get( $key, 'mi_integracion_api_campos_configurables' );
	}

	/**
	 * Guardar datos en caché
	 */
	private function cache_data( string $cache_key, $data ): void {
		wp_cache_set( $cache_key, $data, 'mi_integracion_api_campos_configurables', self::CACHE_EXPIRATION );
	}
}

// Ejemplo de uso (comentado para no ejecutar automáticamente)
/*
if ( class_exists( 'ApiConnector' ) && class_exists( 'MiIntegracionApi\\Endpoints\\GetCamposConfigurablesArticulosWS' ) ) {
	$api_connector = \MiIntegracionApi\Helpers\ApiHelpers::get_connector();
	$campos_endpoint = new MiIntegracionApi\Endpoints\GetCamposConfigurablesArticulosWS( $api_connector );
	// $resultado = $campos_endpoint->execute( array( 'x' => 18 ) );
}
*/
