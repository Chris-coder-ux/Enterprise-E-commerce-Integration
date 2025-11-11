<?php 

declare(strict_types=1);

namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Helpers\Utils;
use MiIntegracionApi\Helpers\EndpointArgs;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para gestionar el endpoint GetArticulosWS
 */
class GetArticulosWS extends Base {

	use EndpointLogger;

	public const ENDPOINT_NAME = 'GetArticulosWS';

	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'sesionwcf'     => EndpointArgs::sesionwcf(),
			'force_refresh' => EndpointArgs::force_refresh(),
			'fecha_desde'   => array(
				'validate_callback' => function($value) {
					// Validación centralizada de fecha opcional
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
			),
			'hora_desde'    => array(
				'validate_callback' => function($value) {
					// Validación centralizada de hora opcional
					if (Utils::is_valid_time_format_optional($value)) {
						return true;
					}
					$error_message = __('hora_desde debe ser una hora válida en formato HH:MM o HH:MM:SS.', 'mi-integracion-api');
					return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
						false,
						['field' => 'hora_desde', 'value' => $value],
						new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
							'rest_invalid_param',
							$error_message,
							['status' => 400, 'field' => 'hora_desde']
						),
						400,
						$error_message,
						['endpoint' => 'validate_callback']
					);
				}
			),
			'inicio'        => array(
				'validate_callback' => function($value) {
					// Validación de parámetro inicio para paginación
					if (empty($value)) return true; // Opcional
					if (is_numeric($value) && (int)$value >= 1) {
						return true;
					}
					$error_message = __('inicio debe ser un número entero mayor o igual a 1.', 'mi-integracion-api');
					return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
						false,
						['field' => 'inicio', 'value' => $value],
						new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
							'rest_invalid_param',
							$error_message,
							['status' => 400, 'field' => 'inicio']
						),
						400,
						$error_message,
						['endpoint' => 'validate_callback']
					);
				}
			),
			'fin'           => array(
				'validate_callback' => function($value) {
					// Validación de parámetro fin para paginación
					if (empty($value)) return true; // Opcional
					if (is_numeric($value) && (int)$value >= 1) {
						return true;
					}
					$error_message = __('fin debe ser un número entero mayor o igual a 1.', 'mi-integracion-api');
					return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
						false,
						['field' => 'fin', 'value' => $value],
						new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
							'rest_invalid_param',
							$error_message,
							['status' => 400, 'field' => 'fin']
						),
						400,
						$error_message,
						['endpoint' => 'validate_callback']
					);
				}
			),
			'context'       => EndpointArgs::context(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $verial_data_success
	 * @return array<int, array<string, mixed>>
	 */
	protected function format_specific_data( array $verial_data_success ): array {
		$formatted_articulos = array();
		foreach ( $verial_data_success as $articulo_verial ) {
			if ( is_array( $articulo_verial ) ) {
				$formatted_articulos[] = array(
					// Propiedades básicas según manual de Verial
					'id_verial'             => $articulo_verial['Id'] ?? null,
					'nombre'                => $articulo_verial['Nombre'] ?? '',
					'descripcion'           => $articulo_verial['Descripcion'] ?? '',
					'referencia_barras'     => $articulo_verial['ReferenciaBarras'] ?? '',
					'tipo'                  => $articulo_verial['Tipo'] ?? 1,
					
					// Fechas
					'fecha_disponibilidad'  => $articulo_verial['FechaDisponibilidad'] ?? null,
					'fecha_inicio_venta'    => $articulo_verial['FechaInicioVenta'] ?? null,
					'fecha_inactivo'        => $articulo_verial['FechaInactivo'] ?? null,
					
					// Impuestos
					'porcentaje_iva'        => $articulo_verial['PorcentajeIVA'] ?? 0.0,
					'porcentaje_re'         => $articulo_verial['PorcentajeRE'] ?? 0.0,
					
					// Categorías
					'id_categoria'          => $articulo_verial['ID_Categoria'] ?? null,
					'id_categoria_web1'     => $articulo_verial['ID_CategoriaWeb1'] ?? null,
					'id_categoria_web2'     => $articulo_verial['ID_CategoriaWeb2'] ?? null,
					'id_categoria_web3'     => $articulo_verial['ID_CategoriaWeb3'] ?? null,
					'id_categoria_web4'     => $articulo_verial['ID_CategoriaWeb4'] ?? null,
					
					// Fabricante
					'id_fabricante'         => $articulo_verial['ID_Fabricante'] ?? null,
					
					// Campos auxiliares
					'aux1'                  => $articulo_verial['Aux1'] ?? '',
					'aux2'                  => $articulo_verial['Aux2'] ?? '',
					'aux3'                  => $articulo_verial['Aux3'] ?? '',
					'aux4'                  => $articulo_verial['Aux4'] ?? '',
					'aux5'                  => $articulo_verial['Aux5'] ?? '',
					'aux6'                  => $articulo_verial['Aux6'] ?? '',
					
					// Unidades
					'nombre_uds'            => $articulo_verial['NombreUds'] ?? '',
					'nombre_uds_aux'        => $articulo_verial['NombreUdsAux'] ?? '',
					'nombre_uds_ocu'        => $articulo_verial['NombreUdsOCU'] ?? '',
					'relacion_uds_aux'      => $articulo_verial['RelacionUdsAux'] ?? 0,
					'relacion_uds_ocu'      => $articulo_verial['RelacionUdsOCU'] ?? 0,
					'vender_uds_aux'        => $articulo_verial['VenderUdsAux'] ?? false,
					'dec_uds_ventas'        => $articulo_verial['DecUdsVentas'] ?? 0,
					'dec_precio_ventas'     => $articulo_verial['DecPrecioVentas'] ?? 0,
					
					// Dimensiones
					'num_dimensiones'       => $articulo_verial['NumDimensiones'] ?? 0,
					'ancho'                 => $articulo_verial['Ancho'] ?? 0,
					'alto'                  => $articulo_verial['Alto'] ?? 0,
					'grueso'                => $articulo_verial['Grueso'] ?? 0,
					'peso'                  => $articulo_verial['Peso'] ?? 0,
					
					// Otros
					'nexo'                  => $articulo_verial['Nexo'] ?? '',
					'id_articulo_ecotasas'  => $articulo_verial['ID_ArticuloEcotasas'] ?? null,
					'precio_ecotasas'       => $articulo_verial['PrecioEcotasas'] ?? 0.0,
					'campos_configurables'  => $articulo_verial['CamposConfigurables'] ?? null,
					
					// Datos específicos de libros
					'autores'               => $articulo_verial['Autores'] ?? null,
					'obra_completa'         => $articulo_verial['ObraCompleta'] ?? '',
					'subtitulo'             => $articulo_verial['Subtitulo'] ?? '',
					'menciones'             => $articulo_verial['Menciones'] ?? '',
					'id_pais_publicacion'   => $articulo_verial['ID_PaisPublicacion'] ?? null,
					'edicion'               => $articulo_verial['Edicion'] ?? '',
					'fecha_edicion'         => $articulo_verial['FechaEdicion'] ?? null,
					'paginas'               => $articulo_verial['Paginas'] ?? 0,
					'volumenes'             => $articulo_verial['Volumenes'] ?? 0,
					'numero_volumen'        => $articulo_verial['NumeroVolumen'] ?? '',
				);
			}
		}
		return $formatted_articulos;
	}

	/**
	 * Ejecuta la lógica del endpoint.
	 *
	 * @param \WP_REST_Request $request Datos de la solicitud.
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		try {
			$params = $request->get_params();
			// Validación de fecha y hora usando Helpers\Utils (centralizado)
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
			if (!empty($params['hora_desde']) && !Utils::is_valid_time_format_optional($params['hora_desde'])) {
				$error_message = __('El formato de hora debe ser HH:MM o HH:MM:SS', 'mi-integracion-api');
				return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
					false,
					['field' => 'hora_desde', 'value' => $params['hora_desde']],
					new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
						'rest_invalid_param',
						$error_message,
						['status' => 400, 'field' => 'hora_desde']
					),
					400,
					$error_message,
					['endpoint' => 'execute_restful']
				);
			}

			// Validar parámetros requeridos
			if ( empty( $params['sesionwcf'] ) ) {
				$error_message = __( 'Falta el parámetro sesionwcf', 'mi-integracion-api' );
				return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
					false,
					['params' => $params],
					new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
						'rest_missing_callback_param',
						$error_message,
						['status' => 400]
					),
					400,
					$error_message,
					['endpoint' => 'execute_restful']
				);
			}

			$force_refresh = ! empty( $params['force_refresh'] );
			$sesion_wcf = absint( $params['sesionwcf'] );
			
			// Preparar parámetros para la API (también usados para caché)
			$api_params = array(
				'x' => $sesion_wcf,
			);
			if ( ! empty( $params['fecha_desde'] ) ) {
				$api_params['fecha'] = $params['fecha_desde'];
			}
			if ( ! empty( $params['hora_desde'] ) ) {
				$api_params['hora'] = $params['hora_desde'];
			}
			if ( ! empty( $params['inicio'] ) ) {
				$api_params['inicio'] = (int)$params['inicio'];
			}
			if ( ! empty( $params['fin'] ) ) {
				$api_params['fin'] = (int)$params['fin'];
			}

			// Los parámetros ya están preparados arriba

			// Obtener datos de la API usando el connector configurado
			$response = $this->connector->get( self::ENDPOINT_NAME, $api_params );

			// Procesar respuesta
			$processed_response = $this->process_verial_response( $response, self::ENDPOINT_NAME );
			if ( $processed_response instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$processed_response->isSuccess() ) {
				return $processed_response;
			}

			// Formatear datos usando la función base estandarizada
			$formatted_data = $this->format_success_response(
				$this->format_specific_data( $processed_response )
			);

			return new \WP_REST_Response( $formatted_data, 200 );

		} catch ( \Exception $e ) {
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['exception' => $e->getMessage()],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_error',
					$e->getMessage(),
					['status' => 500]
				),
				500,
				$e->getMessage(),
				['endpoint' => 'execute_restful']
			);
		}
	}

	/**
	 * Registra la ruta del endpoint.
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/articulos',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'execute_restful' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'               => $this->get_endpoint_args(),
				),
			)
		);
	}
}

// Nota: Para toda validación de fecha/hora y formateo de respuesta, utilice siempre los métodos centralizados de Helpers\Utils y Base para mantener la coherencia y robustez.
