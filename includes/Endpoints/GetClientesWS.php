<?php declare(strict_types=1);
/**
 * Clase para el endpoint GetClientesWS de la API de Verial ERP.
 * Obtiene el listado de clientes, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\helpers\Logger;
use MiIntegracionApi\Helpers\Utils;
use MiIntegracionApi\Helpers\EndpointArgs;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use MiIntegracionApi\Constants\VerialTypes;
use MiIntegracionApi\Core\CacheConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GetClientesWS extends Base {

	public const ENDPOINT_NAME        = 'GetClientesWS';
	public const CACHE_KEY_PREFIX     = 'mi_api_clientes_';
	// Usando constantes centralizadas de caché
	public const VERIAL_ERROR_SUCCESS = 0;

	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/getclientesws',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}
	// Límites de longitud - Usando constantes centralizadas de VerialTypes

	public function permissions_check( WP_REST_Request $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$error_message = esc_html__( 'No tienes permiso para ver la información de los clientes.', 'mi-integracion-api' );
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

	public function get_endpoint_args( bool $is_update = false ): array {
		$args = array(
			'sesionwcf'     => EndpointArgs::sesionwcf(),
			'force_refresh' => EndpointArgs::force_refresh(),
		);
		if ( $is_update ) {
			$args['id_cliente_verial'] = array(
				'description'       => __( 'ID del cliente en Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ): bool {
					return is_numeric( $param ) && $param > 0;
				},
			);
		} else {
			$args['id_cliente']  = array(
				'description'       => __( 'ID del cliente en Verial para filtrar (0 para todos).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			);
			$args['nif']         = array(
				'description'       => __( 'NIF/CIF del cliente para filtrar.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ): bool {
					return is_string( $param ) && mb_strlen( $param ) <= VerialTypes::MAX_LENGTH_NIF;
				},
			);
			$args['fecha_desde'] = array(
				'description'       => __( 'Filtrar clientes creados/modificados desde esta fecha (YYYY-MM-DD).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function($value) {
					if (Utils::is_valid_date_format_optional($value)) {
						return true;
					}
					$error_message = __('fecha_desde debe ser una fecha válida en formato YYYY-MM-DD.', 'mi-integracion-api');
					return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
						false,
						['field' => 'fecha_desde', 'value' => $value],
						new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
							'rest_invalid_param',
							$error_message,
							['status' => 400, 'field' => 'fecha_desde']
						),
						400,
						$error_message,
						['endpoint' => 'validate_callback']
					);
				}
			);
			$args['hora']        = array(
				'description'       => __( 'Filtrar clientes desde una hora específica (HH:MM o HH:MM:SS).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function($value) {
					if (Utils::is_valid_time_format_optional($value)) {
						return true;
					}
					$error_message = __('hora debe ser una hora válida en formato HH:MM o HH:MM:SS.', 'mi-integracion-api');
					return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
						false,
						['field' => 'hora', 'value' => $value],
						new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
							'rest_invalid_param',
							$error_message,
							['status' => 400, 'field' => 'hora']
						),
						400,
						$error_message,
						['endpoint' => 'validate_callback']
					);
				}
			);
		}
		$args['context'] = EndpointArgs::context();
		return $args;
	}

	/**
	 * @param array<string, mixed> $verial_response
	 * @return \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	protected function format_verial_response( array $verial_response ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! isset( $verial_response['Clientes'] ) || ! is_array( $verial_response['Clientes'] ) ) {
			Logger::error( '[GetClientesWS] La respuesta de Verial no contiene la clave "Clientes" esperada o no es un array.', array( 'verial_response' => $verial_response ) );
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__( 'Los datos de clientes recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				500,
				[
					'endpoint' => self::ENDPOINT_NAME,
					'error_code' => 'verial_api_malformed_clientes_data',
					'verial_response' => $verial_response
				]
			);
		}
		$clientes_formateados = array();
		foreach ( $verial_response['Clientes'] as $cliente ) {
			if ( ! is_array( $cliente ) ) {
				continue;
			}
			$id = null;
			if ( isset( $cliente['Id'] ) ) {
				$tmp = $cliente['Id'];
				/** @var int|string $tmp */
				if ( is_int( $tmp ) ) {
					$id = $tmp;
				} elseif ( is_string( $tmp ) && ctype_digit( $tmp ) ) {
					$id = (int) $tmp;
				}
			}
			$nombre = isset( $cliente['Nombre'] ) && is_string( $cliente['Nombre'] ) ? sanitize_text_field( $cliente['Nombre'] ) : null;
			$nif    = isset( $cliente['NIF'] ) && is_string( $cliente['NIF'] ) ? sanitize_text_field( $cliente['NIF'] ) : null;
			$email  = isset( $cliente['Email1'] ) && is_string( $cliente['Email1'] ) ? sanitize_email( $cliente['Email1'] ) : null;
			$tipo   = null;
			if ( isset( $cliente['Tipo'] ) ) {
				$tmp = $cliente['Tipo'];
				/** @var int|string $tmp */
				if ( is_int( $tmp ) ) {
					$tipo = $tmp;
				} elseif ( is_string( $tmp ) && ctype_digit( $tmp ) ) {
					$tipo = (int) $tmp;
				}
			}
			$clientes_formateados[] = array(
				'id'     => $id,
				'nombre' => $nombre,
				'nif'    => $nif,
				'email'  => $email,
				'tipo'   => $tipo,
			);
		}
		
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$clientes_formateados,
			__( 'Clientes obtenidos correctamente.', 'mi-integracion-api' ),
			[
				'endpoint' => self::ENDPOINT_NAME,
				'items_count' => count( $clientes_formateados ),
				'timestamp' => time()
			]
		);
	}

	/**
	 * El tipado y los chequeos aseguran la robustez y cumplimiento del contrato de retorno.
	 * Si se requiere un análisis 100% limpio, revisar la configuración de stubs y el flujo de tipado estricto.
	 */
	public function execute_restful( WP_REST_Request $request ): WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		$params_mixed = $request->get_params();
		if ( ! is_array( $params_mixed ) ) {
			$error_message = __( 'Los parámetros de la petición no son válidos.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['params' => $params_mixed],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'invalid_params',
					$error_message,
					['status' => 400]
				),
				400,
				$error_message,
				['endpoint' => 'execute_restful']
			);
		}
		$params    = $params_mixed;
		// Validación de fecha/hora usando Helpers\Utils (centralizado)
		if (!empty($params['fecha_desde']) && !Utils::is_valid_date_format_optional($params['fecha_desde'])) {
			$error_message = __('El formato de fecha debe ser YYYY-MM-DD', 'mi-integracion-api');
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['field' => 'fecha_desde', 'value' => $params['fecha_desde']],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_invalid_param',
					$error_message,
					['status' => 400, 'field' => 'fecha_desde']
				),
				400,
				$error_message,
				['endpoint' => 'execute_restful']
			);
		}
		if (!empty($params['hora']) && !Utils::is_valid_time_format_optional($params['hora'])) {
			$error_message = __('El formato de hora debe ser HH:MM o HH:MM:SS', 'mi-integracion-api');
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['field' => 'hora', 'value' => $params['hora']],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_invalid_param',
					$error_message,
					['status' => 400, 'field' => 'hora']
				),
				400,
				$error_message,
				['endpoint' => 'execute_restful']
			);
		}
		$params    = $params_mixed;
		$sesionwcf = null;
		if ( isset( $params['sesionwcf'] ) ) {
			$tmp = $params['sesionwcf'];
			/** @var int|string $tmp */
			if ( is_int( $tmp ) ) {
				$sesionwcf = $tmp;
			} elseif ( is_string( $tmp ) && ctype_digit( $tmp ) ) {
				$sesionwcf = (int) $tmp;
			}
		}
		$force_refresh = isset( $params['force_refresh'] ) ? (bool) $params['force_refresh'] : false;
		$id_cliente    = null;
		if ( isset( $params['id_cliente'] ) ) {
			$tmp = $params['id_cliente'];
			/** @var int|string $tmp */
			if ( is_int( $tmp ) ) {
				$id_cliente = $tmp;
			} elseif ( is_string( $tmp ) && ctype_digit( $tmp ) ) {
				$id_cliente = (int) $tmp;
			}
		}
		$nif               = isset( $params['nif'] ) && is_string( $params['nif'] ) ? $params['nif'] : null;
		$fecha_desde       = isset( $params['fecha_desde'] ) && is_string( $params['fecha_desde'] ) ? $params['fecha_desde'] : null;
		$hora              = isset( $params['hora'] ) && is_string( $params['hora'] ) ? $params['hora'] : null;
		$id_cliente_verial = null;
		if ( isset( $params['id_cliente_verial'] ) ) {
			$tmp = $params['id_cliente_verial'];
			/** @var int|string $tmp */
			if ( is_int( $tmp ) ) {
				$id_cliente_verial = $tmp;
			} elseif ( is_string( $tmp ) && ctype_digit( $tmp ) ) {
				$id_cliente_verial = (int) $tmp;
			}
		}
		$cache_params_for_key = array( 'sesionwcf' => $sesionwcf );
		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_params_for_key );
			if ( false !== $cached_data ) {
				return new WP_REST_Response( $cached_data, 200 );
			}
		}
		$verial_api_params = array( 'x' => $sesionwcf );
		if ( $id_cliente !== null ) {
			$verial_api_params['id_cliente'] = $id_cliente;
		}
		if ( $nif !== null ) {
			$verial_api_params['nif'] = sanitize_text_field( $nif );
		}
		if ( $fecha_desde !== null ) {
			$verial_api_params['fecha'] = sanitize_text_field( $fecha_desde );
		}
		if ( $hora !== null ) {
			$verial_api_params['hora'] = sanitize_text_field( $hora );
		}
		if ( $id_cliente_verial !== null ) {
			$verial_api_params['id_cliente'] = $id_cliente_verial;
		}
		$response_verial = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );
		if ( $response_verial instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$response_verial->isSuccess() ) {
			return rest_ensure_response( $response_verial );
		}
		
		$formatted_response = $this->format_verial_response( $response_verial );
		
		// Convertir SyncResponseInterface a WP_REST_Response usando WordPressAdapter
		$wp_response = \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse( $formatted_response );
		
		// Si la respuesta es exitosa, guardar en caché
		if ( $formatted_response->isSuccess() ) {
			$formatted_data = $formatted_response->getData();
			// Usar la función base para formatear la respuesta estándar
			$formatted_response_data = $this->format_success_response($formatted_data);
			$this->set_cached_data( $cache_params_for_key, $formatted_response_data );
		}
		
		return $wp_response;
	}
}

// Nota: Para toda validación de fecha/hora y formateo de respuesta, utilice siempre los métodos centralizados de Helpers\Utils y Base para mantener la coherencia y robustez.
