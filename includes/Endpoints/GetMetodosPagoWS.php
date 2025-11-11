<?php declare(strict_types=1);

namespace MiIntegracionApi\Endpoints;
/**
 * Clase para el endpoint GetMetodosPagoWS de la API de Verial ERP.
 * Obtiene el listado de métodos de pago, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;

class GetMetodosPagoWS extends \MiIntegracionApi\Endpoints\Base {

	use EndpointLogger;

	const ENDPOINT_NAME        = 'GetMetodosPagoWS';
	// Usando constantes centralizadas de caché
	const CACHE_KEY_PREFIX = 'value';
	const VERIAL_ERROR_SUCCESS = 'value';

	/**
	 * Implementación requerida por la clase abstracta Base.
	 * El registro real de rutas ahora está centralizado en REST_API_Handler.php
	 */
	public function register_route(): void {
		// Esta implementación está vacía ya que el registro real
		// de rutas ahora se hace de forma centralizada en REST_API_Handler.php
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
			'context'       => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
	}

	protected function format_verial_response( array $verial_response ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! isset( $verial_response['MetodosPago'] ) || ! is_array( $verial_response['MetodosPago'] ) ) {
			$this->logger->errorProducto(
				'[GetMetodosPagoWS] La respuesta de Verial no contiene la clave "MetodosPago" esperada o no es un array.',
				array( 'verial_response' => $verial_response )
			);
			$error_message = __( 'Los datos de métodos de pago recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['verial_response' => $verial_response],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'verial_api_malformed_metodos_pago_data',
					$error_message,
					['status' => 500]
				),
				500,
				$error_message,
				['endpoint' => 'format_verial_response']
			);
		}

		$metodos_pago = array();
		foreach ( $verial_response['MetodosPago'] as $metodo_verial ) {
			$metodos_pago[] = array(
				'id'     => isset( $metodo_verial['Id'] ) ? (int) $metodo_verial['Id'] : null,
				'nombre' => isset( $metodo_verial['Nombre'] ) ? sanitize_text_field( $metodo_verial['Nombre'] ) : null,
			);
		}
		
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$metodos_pago,
			__( 'Métodos de pago obtenidos correctamente.', 'mi-integracion-api' ),
			[
				'endpoint' => self::ENDPOINT_NAME,
				'items_count' => count( $metodos_pago ),
				'timestamp' => time()
			]
		);
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		$params        = $request->get_params();
		$sesionwcf     = $params['sesionwcf'] ?? null;
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

			// Log de auditoría si llegamos hasta aquí con éxito
			if ( class_exists( 'LoggerAuditoria' ) ) {
				\LoggerAuditoria::log(
					'Acceso a GetMetodosPagoWS',
					array(
						'params'  => $verial_api_params,
						'usuario' => get_current_user_id(),
					)
				);
			}
		}

		return $wp_response;
	}
}
// Fin de la clase
