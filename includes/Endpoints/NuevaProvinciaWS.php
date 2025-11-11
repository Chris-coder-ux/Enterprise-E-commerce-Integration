<?php declare(strict_types=1);
/**
 * Clase para el endpoint NuevaProvinciaWS de la API de Verial ERP.
 * Da de alta una nueva provincia según el manual v1.7.5.
 *
 * @author Christian
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;
use WP_REST_Request;
use MiIntegracionApi\Helpers\rest_authorization_required_code;
use MiIntegracionApi\Constants\VerialTypes;

/**
 * Clase para gestionar el endpoint de nuevaprovincias
 */
class NuevaProvinciaWS extends Base {
	use EndpointLogger;

	public const ENDPOINT_NAME        = 'NuevaProvinciaWS';
	// Usando constantes centralizadas de caché
	public const CACHE_KEY_PREFIX     = 'mi_api_nueva_provincia_';

	/**
	 * Implementación requerida por la clase abstracta Base.
	 * El registro real de rutas ahora está centralizado en REST_API_Handler.php
	 */
	public function register_route(): void {
    // Esta implementación está vacía ya que el registro real
    // de rutas ahora se hace de forma centralizada en REST_API_Handler.php
}

	// Longitudes máximas - Usando constantes centralizadas de VerialTypes

	// Eliminada la función register_route porque el registro de la ruta ahora es centralizado en REST_API_Handler.php

	public function permissions_check( \WP_REST_Request $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! current_user_can( 'manage_options' ) ) {
			$error_message = esc_html__( 'No tienes permiso para crear provincias.', 'mi-integracion-api' );
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
		return true;
	}

	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'sesionwcf'  => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'nombre'     => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= VerialTypes::MAX_LENGTH_NOMBRE;
				},
			),
			'ID_Pais'    => array(
				'description'       => __( 'ID del país al que pertenece la provincia (numérico, de Verial).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			),
			'CodigoNUTS' => array(
				'description'       => __( 'Código NUTS (Nomenclatura de las Unidades Territoriales Estadísticas).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= VerialTypes::MAX_LENGTH_CODIGO_NUTS;
				},
			),
			'context'    => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'nueva_provincia', $api_key );
		if ( $rate_limit instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$rate_limit->isSuccess() ) {
			return rest_ensure_response( $rate_limit );
		}

		$params = $request->get_params();

		$verial_payload = array(
			'sesionwcf'  => $params['sesionwcf'],
			'Nombre'     => $params['Nombre'],
			'ID_Pais'    => $params['ID_Pais'],
			'CodigoNUTS' => isset( $params['CodigoNUTS'] ) ? $params['CodigoNUTS'] : '',
		);

		$result = $this->connector->post( self::ENDPOINT_NAME, $verial_payload );

		// Registrar logs adicionales si hay clases de log disponibles
		if ( $result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$result->isSuccess() && class_exists( 'MiIntegracionApi\Helpers\Logger' ) ) {
			\MiIntegracionApi\Helpers\Logger::error(
				'[NuevaProvinciaWS] Error en conector: ' . $result->getMessage(),
				'mia-endpoint'
			);
		}

		return rest_ensure_response( $result );
	}
}
