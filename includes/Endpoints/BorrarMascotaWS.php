<?php declare(strict_types=1);
/**
 * Clase para el endpoint BorrarMascotaWS de la API de Verial ERP.
 */

namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\Helpers\EndpointArgs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

if ( ! class_exists( 'MiIntegracionApi\\Endpoints\\BorrarMascotaWS' ) && class_exists( 'MiIntegracionApi\\Endpoints\\Base' ) ) {
	class BorrarMascotaWS extends Base {

		const ENDPOINT_NAME        = 'BorrarMascotaWS';
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
			// Ejemplo: Solo usuarios que pueden editar clientes/pedidos. Ajustar según necesidad.
			if ( ! current_user_can( 'edit_shop_orders' ) ) {
				$error_message = esc_html__( 'No tienes permiso para borrar mascotas.', 'mi-integracion-api' );
				$error_message = is_string( $error_message ) ? $error_message : 'No tienes permiso para borrar mascotas.';

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
				'id_cliente_verial' => array(
					'description' => __( 'ID del cliente en Verial.', 'mi-integracion-api' ),
					'type'        => 'integer',
					'required'    => true,
				),
				'id_mascota_verial' => array(
					'description' => __( 'ID de la mascota a borrar.', 'mi-integracion-api' ),
					'type'        => 'integer',
					'required'    => true,
				),
				'sesionwcf'         => EndpointArgs::sesionwcf(),
				'context'           => EndpointArgs::context(),
			);
		}

	/**
	 * Devuelve una respuesta estándar de éxito para endpoints.
	 *
	 * @param mixed $data Datos principales a devolver (array, objeto, etc.)
	 * @param array $extra (opcional) Datos extra a incluir en la respuesta raíz
	 * @return array Respuesta estándar: ['success' => true, 'data' => $data, ...$extra]
	 */
	protected function format_success_response($data, array $extra = []): array {
		return array_merge([
			'success' => true,
			'data'    => $data,
		], $extra);
	}

		public function execute_restful(\WP_REST_Request $request): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
			// Los parámetros de la URL son la fuente principal para los IDs.
			$id_cliente_verial = absint( $request['id_cliente_verial'] );
			$id_mascota_verial = absint( $request['id_mascota_verial'] );

			// El parámetro 'sesionwcf' viene del cuerpo JSON y ya está validado/sanitizado por 'args'.
			$sesionwcf = $request->get_param( 'sesionwcf' );

			if ( empty( $id_cliente_verial ) || empty( $id_mascota_verial ) ) {
				\LoggerAuditoria::log(
					'Error: IDs vacíos en URL para BorrarMascotaWS',
					array(
						'request' => $request->get_params(),
						'usuario' => get_current_user_id(),
					)
				);
				$error_message = __( 'Los IDs del cliente y de la mascota son requeridos en la URL.', 'mi-integracion-api' );
				return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
					false,
					['request' => $request->get_params()],
					new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
						'rest_invalid_ids',
						$error_message,
						['status' => 400]
					),
					400,
					$error_message,
					['endpoint' => 'execute_restful']
				);
			}
			if ( is_null( $sesionwcf ) ) {
				\LoggerAuditoria::log(
					'Error: sesionwcf vacío en BorrarMascotaWS',
					array(
						'request' => $request->get_params(),
						'usuario' => get_current_user_id(),
					)
				);
				$error_message = __( 'El parámetro sesionwcf es requerido en el cuerpo de la solicitud.', 'mi-integracion-api' );
				return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
					false,
					['request' => $request->get_params()],
					new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
						'rest_missing_sesionwcf',
						$error_message,
						['status' => 400]
					),
					400,
					$error_message,
					['endpoint' => 'execute_restful']
				);
			}

			$verial_payload = array(
				'sesionwcf'  => $sesionwcf,
				'ID_Cliente' => $id_cliente_verial,
				'Id'         => $id_mascota_verial, // 'Id' es el identificador de la mascota en Verial
			);

			// Llamada POST a Verial aunque la ruta sea DELETE en la API REST local
			$result = $this->connector->post( self::ENDPOINT_NAME, $verial_payload );

			// Log de auditoría si hay un error de conexión
			if ( $result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$result->isSuccess() ) {
				\LoggerAuditoria::log(
					'Baja fallida de mascota (conexión)',
					array(
						'id_cliente_verial'  => isset( $id_cliente_verial ) ? $id_cliente_verial : null,
						'id_mascota_borrada' => isset( $id_mascota_verial ) ? $id_mascota_verial : null,
						'payload'            => $verial_payload,
						'error'              => $result->getMessage(),
						'respuesta'          => null,
					)
				);
			}

			// La validación de la respuesta se maneja en el conector API
			// Solo registramos la auditoría en caso de error o éxito
			if ( !($result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$result->isSuccess()) && isset( $result['InfoError'] ) && isset( $result['InfoError']['Codigo'] ) ) {
				$info_error        = $result['InfoError'];
				$error_code_verial = (int) $info_error['Codigo'];

				if ( $error_code_verial !== self::VERIAL_ERROR_SUCCESS ) {
					$error_message = isset( $info_error['Descripcion'] ) ? $info_error['Descripcion'] : __( 'Error desconocido de Verial al borrar mascota.', 'mi-integracion-api' );

					// Registro de auditoría en caso de error en la respuesta
					\LoggerAuditoria::log(
						'Baja fallida de mascota',
						array(
							'id_cliente_verial'  => isset( $id_cliente_verial ) ? $id_cliente_verial : null,
							'id_mascota_borrada' => isset( $id_mascota_verial ) ? $id_mascota_verial : null,
							'payload'            => $verial_payload,
							'error'              => $error_message,
							'respuesta'          => $result,
						)
					);
				}
			}

			// Si no es un error, registramos el éxito en la auditoría
			if ( !($result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$result->isSuccess()) ) {
				// Registro de auditoría para operación exitosa
				\LoggerAuditoria::log(
					'Baja de mascota',
					array(
						'id_cliente_verial'  => $id_cliente_verial,
						'id_mascota_borrada' => $id_mascota_verial,
						'payload'            => $verial_payload,
						'respuesta'          => $result,
					)
				);

				// Formatear la respuesta para el cliente
				$formatted_result = $this->format_success_response(null, [
					'message'            => __( 'Mascota borrada en Verial con éxito.', 'mi-integracion-api' ),
					'id_cliente_verial'  => $id_cliente_verial,
					'id_mascota_borrada' => $id_mascota_verial,
				]);

				return rest_ensure_response( $formatted_result );
			}

			// Si llegamos aquí, devolvemos la respuesta (que debería ser un SyncResponseInterface)
			return rest_ensure_response( $result );
		}
	}
}

// add_action('rest_api_init', function () {
// });
