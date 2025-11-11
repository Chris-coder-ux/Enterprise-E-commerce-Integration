<?php declare(strict_types=1);
/**
 * Clase para el endpoint GetNumArticulosWS de la API de Verial ERP.
 * Devuelve el número total de artículos disponibles, según el manual v1.7.5/1.8.
 *
 * @author Christian (basado en plantilla estándar)
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Helpers\AuthHelper;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GetNumArticulosWS extends Base {

	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetNumArticulosWS';
	// Usando constantes centralizadas de caché
	public const CACHE_KEY_PREFIX     = 'mia_num_articulos_';

	// Eliminada la función register_route porque el registro de la ruta ahora es centralizado en REST_API_Handler.php

	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'sesionwcf'     => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'fecha'         => array(
				'description'       => __( 'Filtrar artículos creados/modificados desde esta fecha (YYYY-MM-DD).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_date_format_optional' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'hora'          => array(
				'description'       => __( 'Filtrar artículos desde una hora específica (HH:MM:SS).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_time_format_optional' ),
				'sanitize_callback' => 'sanitize_text_field',
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

	public function validate_date_format_optional( string $value, \WP_REST_Request $request, string $key ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( empty( $value ) ) {
			return true;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$parts = explode( '-', $value );
			if ( checkdate( $parts[1], $parts[2], $parts[0] ) ) {
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

	public function validate_time_format_optional( string $value, \WP_REST_Request $request, string $key ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( $value === '' ) {
			return true;
		}
		// Permite HH:MM o HH:MM:SS
		if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value ) ) {
			return true;
		}
		$error_template = esc_html__( '%s debe ser una hora válida en formato HH:MM o HH:MM:SS.', 'mi-integracion-api' );
		$error_template = is_string( $error_template ) ? $error_template : '%s debe ser una hora válida en formato HH:MM o HH:MM:SS.';
		$error_message = sprintf( $error_template, $key );
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
			['endpoint' => 'validate_time_format_optional']
		);
	}

	public function permissions_check( WP_REST_Request $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
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
		$auth_result = AuthHelper::validate_rest_auth( $request );
		if ( $auth_result !== true ) {
			return $auth_result;
		}
		return true;
	}

	public function execute_restful( WP_REST_Request $request ): WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = AuthHelper::check_rate_limit( 'get_num_articulos', $api_key );
		if ( $rate_limit instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$rate_limit->isSuccess() ) {
			return $rate_limit;
		}

		$params        = $request->get_params();
		$sesionwcf     = $params['sesionwcf'];
		$fecha         = $params['fecha'] ?? null;
		$hora          = $params['hora'] ?? null;
		$force_refresh = $params['force_refresh'] ?? false;

		$cache_params_for_key = array(
			'sesionwcf' => $sesionwcf,
			'fecha'     => $fecha,
			'hora'      => $hora,
		);
		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_params_for_key );
			if ( false !== $cached_data ) {
				return new WP_REST_Response( $cached_data, 200 );
			}
		}
		$verial_api_params = array( 'x' => $sesionwcf );
		if ( $fecha !== null ) {
			$verial_api_params['fecha'] = $fecha;
		}
		if ( $hora !== null ) {
			$verial_api_params['hora'] = $hora;
		}
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
		if ( $formatted_response->isSuccess() ) {
			$formatted_data = $formatted_response->getData();
			// Usar la función base para formatear la respuesta estándar
			$formatted_response_data = $this->format_success_response($formatted_data);
			$this->set_cached_data( $cache_params_for_key, $formatted_response_data );
		}

		return $wp_response;
	}

	/**
	 * Formatea la respuesta de Verial para el endpoint GetNumArticulosWS.
	 *
	 * @param array $verial_response Respuesta de la API de Verial.
	 * @return \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	protected function format_verial_response( array $verial_response ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! isset( $verial_response['NumArticulos'] ) || ! is_numeric( $verial_response['NumArticulos'] ) ) {
			$this->logger->errorProducto(
				'[GetNumArticulosWS] La respuesta de Verial no contiene la clave "NumArticulos" esperada o no es numérica.',
				array( 'verial_response' => $verial_response )
			);
			$error_message = __( 'Los datos de número de artículos recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['verial_response' => $verial_response],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'verial_api_malformed_num_articulos_data',
					$error_message,
					['status' => 500]
				),
				500,
				$error_message,
				['endpoint' => 'format_verial_response']
			);
		}

		$num_articulos = (int) $verial_response['NumArticulos'];
		
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			['num_articulos' => $num_articulos],
			__( 'Número de artículos obtenido correctamente.', 'mi-integracion-api' ),
			[
				'endpoint' => self::ENDPOINT_NAME,
				'num_articulos' => $num_articulos,
				'timestamp' => time()
			]
		);
	}

	public function register_route(): void {
		// El registro de la ruta se realiza en REST_API_Handler.php
	}
}
