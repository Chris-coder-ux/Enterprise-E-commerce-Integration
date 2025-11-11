<?php declare(strict_types=1);
/**
 * Clase para el endpoint NuevaDireccionEnvioWS de la API de Verial ERP.
 */

namespace MiIntegracionApi\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Traits\ErrorHandler;
use MiIntegracionApi\Constants\VerialTypes;

class NuevaDireccionEnvioWS extends Base {

	use EndpointLogger;
	use ErrorHandler;

	/**
	 * Implementación requerida por la clase abstracta Base.
	 * El registro real de rutas ahora está centralizado en REST_API_Handler.php
	 */
	public function register_route(): void {
		// Esta implementación está vacía ya que el registro real
		// de rutas ahora se hace de forma centralizada en REST_API_Handler.php
	}

	protected function process_verial_response( array|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface $verial_response, string $endpoint_context_for_log = '' ): array|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( $verial_response instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$verial_response->isSuccess() ) {
			return $verial_response;
		}

		if ( ! isset( $verial_response['InfoError'] ) ) {
			$error_message = __( 'La respuesta de Verial no tiene el formato esperado (falta InfoError)', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['raw_response' => $verial_response],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'verial_api_formato_incorrecto',
					$error_message,
					['status' => 500]
				),
				500,
				$error_message,
				['endpoint' => 'process_verial_response']
			);
		}

		return $verial_response;
	}

	// Definir constantes específicas del endpoint
	const ENDPOINT_NAME = 'NuevaDireccionEnvioWS';
	// Usando constantes centralizadas de caché // Nombre del endpoint en la API de Verial
	// CACHE_KEY_PREFIX y CACHE_EXPIRATION se heredan, pero CACHE_EXPIRATION = 0 es bueno para escrituras.

	// Longitudes máximas - Usando constantes centralizadas de VerialTypes

	// El constructor se hereda de MI_Endpoint_Base
	// public function __construct(\MiIntegracionApi\Core\ApiConnector $connector) {
	// parent::__construct($connector);
	// }

	// El método make() se hereda de MI_Endpoint_Base

	// Eliminada la función register_route porque el registro de la ruta ahora es centralizado en REST_API_Handler.php

	public function permissions_check( \WP_REST_Request $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Ejemplo: Solo usuarios que pueden editar clientes/pedidos.
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // Ajustar capacidad según sea necesario
			$error_message = esc_html__( 'No tienes permiso para gestionar direcciones de envío.', 'mi-integracion-api' );
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
		// Los parámetros de la URL (id_cliente_verial, id_direccion_verial) se definen en register_rest_route
		// y estarán disponibles en $request['id_cliente_verial'], etc.
		// Aquí definimos los argumentos que se esperan en el CUERPO de la solicitud.
		$args = array(
			'sesionwcf'          => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'Id'                 => array( // Id de la dirección en Verial para modificaciones (opcional en body si viene de URL)
				'description'       => __( 'ID de la dirección de envío en Verial. Para creación, enviar 0 o no enviar si Verial lo autogenera.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false, // No es requerido en el body si se toma de la URL para updates, o es 0 para create.
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param, $request, $key ) {
					return is_numeric( $param ) && (int) $param >= 0;
				},
			),
			'Nombre'             => array(
				'description'       => __( 'Nombre de contacto para la dirección de envío.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_NOMBRE,
			),
			'Apellido1'          => array(
				'description'       => __( 'Primer apellido.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_APELLIDO,
			),
			'Apellido2'          => array(
				'description'       => __( 'Segundo apellido.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_APELLIDO,
			),
			'ID_Pais'            => array(
				'description'       => __( 'ID del país (numérico, de Verial).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true, // Usualmente requerido por Verial
				'sanitize_callback' => 'absint',
			),
			'ID_Provincia'       => array(
				'description'       => __( 'ID de la provincia (numérico, de Verial). Opcional si se envía Provincia.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'Provincia'          => array(
				'description'       => __( 'Nombre de la provincia (si no se usa ID_Provincia).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => function ( $params ) {
					return empty( $params['ID_Provincia'] );
				}, // Requerido si ID_Provincia no se envía
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_PROVINCIA_MANUAL,
			),
			'ID_Localidad'       => array(
				'description'       => __( 'ID de la localidad (numérico, de Verial). Opcional si se envía Localidad.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'Localidad'          => array(
				'description'       => __( 'Nombre de la localidad (si no se usa ID_Localidad).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => function ( $params ) {
					return empty( $params['ID_Localidad'] );
				}, // Requerido si ID_Localidad no se envía
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_LOCALIDAD_MANUAL,
			),
			'LocalidadAux'       => array(
				'description'       => __( 'Información adicional de la localidad (ej: polígono, urbanización).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_LOCALIDAD_AUX,
			),
			'CPostal'            => array(
				'description'       => __( 'Código postal.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false, // El manual no lo marca como X, pero suele ser importante
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_CP,
				'validate_callback' => function ( $param, $request, $key ) {
					// Permitir vacío o un formato de CP razonable
					return empty( $param ) || preg_match( '/^[0-9A-Za-z\s-]{3,' . VerialTypes::MAX_LENGTH_CP . '}$/', $param );
				},
			),
			'Direccion'          => array(
				'description'       => __( 'Dirección completa.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false, // El manual no lo marca como X
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_DIRECCION,
			),
			'Telefono'           => array(
				'description'       => __( 'Número de teléfono.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_TELEFONO,
			),
			'Telefono2'          => array(
				'description'       => __( 'Segundo número de teléfono.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_TELEFONO,
			),
			'Email'              => array(
				'description'       => __( 'Dirección de correo electrónico.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'format'            => 'email', // WP_REST_Request valida el formato email
				'sanitize_callback' => 'sanitize_email',
				'maxLength'         => VerialTypes::MAX_LENGTH_EMAIL,
			),
			'Cargo'              => array(
				'description'       => __( 'Cargo en la empresa.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_CARGO,
			),
			'Comentario'         => array(
				'description'       => __( 'Comentario adicional para la dirección.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_COMENTARIO,
			),
			'CodigoMunicipioINE' => array(
				'description'       => __( 'Código de municipio INE.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_CODIGO_MUNICIPIO_INE,
			),
		);
		return $args;
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'nueva_direccion_envio', $api_key );
		if ( $rate_limit instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$rate_limit->isSuccess() ) {
			return rest_ensure_response( $rate_limit );
		}

		$params_body = $request->get_json_params();
		if ( empty( $params_body ) ) {
			$params_body = $request->get_body_params();
		}

		$id_cliente_verial_url = absint( $request['id_cliente_verial'] );

		if ( empty( $id_cliente_verial_url ) ) {
			$error_message = __( 'El ID del cliente es requerido en la URL.', 'mi-integracion-api' );
			return rest_ensure_response(
				new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
					false,
					['id_cliente_verial_url' => $id_cliente_verial_url],
					new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
						'rest_invalid_client_id',
						$error_message,
						['status' => 400]
					),
					400,
					$error_message,
					['endpoint' => 'execute_restful']
				)
			);
		}

		// Validar existencia del cliente en WooCommerce (si aplica)
		$user_query = new \WP_User_Query(
			array(
				'meta_key'   => '_verial_cliente_id',
				'meta_value' => $id_cliente_verial_url,
				'number'     => 1,
			)
		);
		$users      = $user_query->get_results();
		if ( empty( $users ) ) {
			$error_message = __( 'El cliente no existe en WooCommerce.', 'mi-integracion-api' );
			return rest_ensure_response(
				new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
					false,
					['id_cliente_verial_url' => $id_cliente_verial_url],
					new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
						'rest_client_not_found',
						$error_message,
						['status' => 404]
					),
					404,
					$error_message,
					['endpoint' => 'execute_restful']
				)
			);
		}

		$result = $this->connector->post( self::ENDPOINT_NAME, $params_body );
		return rest_ensure_response( $result );
	}
}
// El add_action para registrar la ruta se hace en el archivo principal del plugin.
