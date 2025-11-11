<?php declare(strict_types=1);
/**
 * Clase para el endpoint NuevoPagoWS de la API de Verial ERP.
 * Da de alta un nuevo pago para un documento de cliente según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use WP_REST_Request;
use MiIntegracionApi\Constants\VerialTypes;

/**
 * Clase para gestionar el endpoint de nuevopagos
 */
class NuevoPagoWS extends Base {
	public const ENDPOINT_NAME               = 'NuevoPagoWS';
	// Usando constantes centralizadas de caché
	public const CACHE_KEY_PREFIX            = 'mi_api_nuevo_pago_';

	/**
	 * Constructor
	 */
	public function __construct( \MiIntegracionApi\Core\ApiConnector $connector ) {
		parent::__construct( $connector );
	}

	/**
	 * Implementación requerida por la clase abstracta Base.
	 * El registro real de rutas ahora está centralizado en REST_API_Handler.php
	 */
	public function register_route(): void {
		// Esta implementación está vacía ya que el registro real
		// de rutas ahora se hace de forma centralizada en REST_API_Handler.php
	}

	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'sesionwcf'     => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'ID_MetodoPago' => array(
				'description'       => __( 'ID del método de pago (numérico, de Verial).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'Fecha'         => array(
				'description'       => __( 'Fecha del pago (YYYY-MM-DD).', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'required'          => true,
				'validate_callback' => array( $this, 'validate_date_format' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'Importe'       => array(
				'description'       => __( 'Importe del pago (decimal, ej: 10.50).', 'mi-integracion-api' ),
				'type'              => 'number',
				'required'          => true,
				'sanitize_callback' => array( $this, 'sanitize_decimal' ),
				'validate_callback' => array( $this, 'validate_positive_numeric_strict' ),
			),
			'Observaciones' => array(
				'description'       => __( 'Observaciones adicionales para el pago.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_OBSERVACIONES,
			),
		);
	}

	public function validate_date_format( $value, $request, $key ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface|bool {
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
			['endpoint' => 'validate_date_format']
		);
	}

	public function sanitize_decimal( $value, $request, $key ) {
		return ! empty( $value ) ? (float) str_replace( ',', '.', $value ) : null;
	}

	public function validate_positive_numeric_strict( $value, $request, $key ): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface|bool {
		if ( empty( $value ) && $value !== 0 && $value !== '0' ) {
			return true;
		}
		if ( is_numeric( $value ) && (float) $value > 0 ) {
			return true;
		}
		$error_message = sprintf( esc_html__( '%s debe ser un valor numérico estrictamente positivo.', 'mi-integracion-api' ), $key );
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
			['endpoint' => 'validate_positive_numeric_strict']
		);
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'nuevo_pago', $api_key );
		if ( $rate_limit instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$rate_limit->isSuccess() ) {
			return rest_ensure_response( $rate_limit );
		}

		$params              = $request->get_params();
		$id_documento_verial = absint( $request['id_documento_verial'] );

		if ( empty( $id_documento_verial ) ) {
			$error_message = __( 'El ID del documento es requerido en la URL.', 'mi-integracion-api' );
			return rest_ensure_response( new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['id_documento_verial' => $id_documento_verial],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_invalid_document_id',
					$error_message,
					['status' => 400]
				),
				400,
				$error_message,
				['endpoint' => 'execute_restful']
			) );
		}

		$verial_payload = array(
			'sesionwcf'     => $params['sesionwcf'],
			'ID_DocCli'     => $id_documento_verial,
			'ID_MetodoPago' => $params['ID_MetodoPago'],
			'Fecha'         => $params['Fecha'],
			'Importe'       => round( (float) $params['Importe'], 2 ),
		);

		if ( isset( $params['Observaciones'] ) && ! empty( $params['Observaciones'] ) ) {
			$verial_payload['Observaciones'] = $params['Observaciones'];
		}

		$result = $this->connector->post( self::ENDPOINT_NAME, $verial_payload );

		// Log de auditoría si hay un error de conexión
		if ( $result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$result->isSuccess() ) {
			\MiIntegracionApi\Helpers\LoggerAuditoria::log(
				'Alta fallida de pago (conexión)',
				array(
					'payload'   => $verial_payload ?? null,
					'error'     => $result->getMessage(),
					'respuesta' => null,
				)
			);
		}

		// La validación de la respuesta se maneja internamente en el conector
		// Solo registramos la auditoría si hay un error devuelto o es un éxito
		if ( ! ( $result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$result->isSuccess() ) && isset( $result['InfoError'] ) && isset( $result['InfoError']['Codigo'] ) ) {
			$info_error        = $result['InfoError'];
			$error_code_verial = (int) $info_error['Codigo'];

			if ( $error_code_verial !== VerialTypes::VERIAL_ERROR_SUCCESS ) {
				$error_message = $info_error['Descripcion'] ?? __( 'Error desconocido de Verial al crear el pago.', 'mi-integracion-api' );

				// Registro de auditoría en caso de error en la respuesta
				\MiIntegracionApi\Helpers\LoggerAuditoria::log(
					'Alta fallida de pago',
					array(
						'payload'   => $verial_payload ?? null,
						'error'     => $error_message ?? 'Error desconocido',
						'respuesta' => $result ?? null,
					)
				);
			}
		}

		// Si no es un error, registramos el éxito en la auditoría
		if ( ! ( $result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$result->isSuccess() ) ) {
			$id_pago_verial = isset( $result['Id'] ) ? (int) $result['Id'] : null;

			// Registro de auditoría para operación exitosa
			\MiIntegracionApi\Helpers\LoggerAuditoria::log(
				'Alta de pago',
				array(
					'id_documento_verial' => $id_documento_verial,
					'id_pago_verial'      => $id_pago_verial,
					'payload'             => $verial_payload,
					'respuesta'           => $result,
				)
			);

			// Formateamos la respuesta para el cliente manteniendo el código 201
			$formatted_result = $this->format_success_response(
				$result,
				[
					'message'             => __( 'Pago creado en Verial con éxito.', 'mi-integracion-api' ),
					'id_documento_verial' => $id_documento_verial,
					'id_pago_verial'      => $id_pago_verial,
				]
			);

			return rest_ensure_response( $formatted_result );
		}

		// Si llegamos aquí, devolvemos la respuesta directamente (que ya debería ser un SyncResponseInterface)
		return rest_ensure_response( $result );
	}
}
