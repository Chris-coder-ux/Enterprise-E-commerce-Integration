<?php declare(strict_types=1);

namespace MiIntegracionApi\Endpoints;
/**
 * Clase para el endpoint GetHistorialPedidosWS de la API de Verial ERP.
 * Obtiene el historial de pedidos, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;

class GetHistorialPedidosWS extends \MiIntegracionApi\Endpoints\Base {

	use EndpointLogger;

	const ENDPOINT_NAME        = 'GetHistorialPedidosWS';
	// Usando constantes centralizadas de caché
	const CACHE_KEY_PREFIX = 'value';
	const VERIAL_ERROR_SUCCESS = 'value';

	public function __construct( \MiIntegracionApi\Core\ApiConnector $connector ) {
		parent::__construct( $connector );
		$this->init_logger( 'pedidos' );
	}

	/**
	 * Comprueba los permisos para acceder al endpoint.
	 * Requiere manage_woocommerce y autenticación adicional (API key/JWT).
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public function permissions_check( \WP_REST_Request $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$error_message = esc_html__( 'No tienes permiso para ver el historial de pedidos.', 'mi-integracion-api' );
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				[],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_forbidden',
					$error_message,
					['status' => \MiIntegracionApi\Helpers\RestHelpers::rest_authorization_required_code()]
				),
				\MiIntegracionApi\Helpers\RestHelpers::rest_authorization_required_code(),
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
			'id_cliente'    => array(
				'description'       => __( 'ID del cliente para filtrar el historial (opcional).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 0;
				},
			),
			'fechadesde'    => array(
				'description'       => __( 'Fecha desde (YYYY-MM-DD) para filtrar el historial (opcional).', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_date_format_optional' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'fechahasta'    => array(
				'description'       => __( 'Fecha hasta (YYYY-MM-DD) para filtrar el historial (opcional).', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_date_format_optional' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'allareasventa' => array(
				'description'       => __( 'Incluir pedidos de todas las áreas de venta (true/false, opcional).', 'mi-integracion-api' ),
				'type'              => 'boolean',
				'required'          => false,
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

	/**
	 * Valida que un valor sea una fecha opcional en formato ISO
	 *
	 * @param mixed            $value Valor a validar
	 * @param \WP_REST_Request $request Petición
	 * @param string           $key Clave del parámetro
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface True si es válido, SyncResponseInterface si no
	 */
	public function validate_date_format_optional( string $value, \WP_REST_Request $request, string $key ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( empty( $value ) ) {
			return true;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$parts = explode( '-', $value );
			if ( checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
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

	private function sanitize_decimal_text( $value ): ?string {
		return ! is_null( $value ) && $value !== '' ? str_replace( ',', '.', $value ) : null;
	}

	protected function format_documento_data( array $documento_verial ): array {
		$doc                          = array();
		$doc['id_verial']             = isset( $documento_verial['Id'] ) ? (int) $documento_verial['Id'] : null;
		$doc['tipo_documento_codigo'] = isset( $documento_verial['Tipo'] ) ? (int) $documento_verial['Tipo'] : null;
		$doc['referencia']            = isset( $documento_verial['Referencia'] ) ? sanitize_text_field( $documento_verial['Referencia'] ) : null;
		$doc['numero_documento']      = isset( $documento_verial['Numero'] ) ? (int) $documento_verial['Numero'] : null;
		$doc['fecha_documento']       = isset( $documento_verial['Fecha'] ) ? sanitize_text_field( $documento_verial['Fecha'] ) : null;
		$doc['id_cliente_verial']     = isset( $documento_verial['ID_Cliente'] ) ? (int) $documento_verial['ID_Cliente'] : null;

		if ( isset( $documento_verial['Cliente'] ) && is_array( $documento_verial['Cliente'] ) ) {
			$cliente_data        = $documento_verial['Cliente'];
			$doc['cliente_info'] = array(
				'id_verial' => isset( $cliente_data['Id'] ) ? (int) $cliente_data['Id'] : null,
				'nombre'    => isset( $cliente_data['Nombre'] ) ? sanitize_text_field( $cliente_data['Nombre'] ) : null,
				'nif'       => isset( $cliente_data['NIF'] ) ? sanitize_text_field( $cliente_data['NIF'] ) : null,
			);
		}
		$doc['etiqueta_cliente']   = isset( $documento_verial['EtiquetaCliente'] ) ? sanitize_textarea_field( $documento_verial['EtiquetaCliente'] ) : null;
		$doc['id_direccion_envio'] = isset( $documento_verial['ID_DireccionEnvio'] ) ? (int) $documento_verial['ID_DireccionEnvio'] : null;
		$doc['id_agente1']         = isset( $documento_verial['ID_Agente1'] ) ? (int) $documento_verial['ID_Agente1'] : null;
		$doc['id_metodo_pago']     = isset( $documento_verial['ID_MetodoPago'] ) ? (int) $documento_verial['ID_MetodoPago'] : null;
		$doc['id_forma_envio']     = isset( $documento_verial['ID_FormaEnvio'] ) ? (int) $documento_verial['ID_FormaEnvio'] : null;
		$doc['id_destino']         = isset( $documento_verial['ID_Destino'] ) ? (int) $documento_verial['ID_Destino'] : null;

		$doc['peso_kg']                     = isset( $documento_verial['Peso'] ) ? (float) $this->sanitize_decimal_text( $documento_verial['Peso'] ) : null;
		$doc['bultos']                      = isset( $documento_verial['Bultos'] ) ? (int) $documento_verial['Bultos'] : null;
		// ...puedes añadir más campos según sea necesario...
		return $doc;
	}

    /**
     * Implementación requerida por la clase abstracta Base.
     * El registro real de rutas ahora está centralizado en REST_API_Handler.php
     */
    public function register_route(): void {
        // Esta implementación está vacía ya que el registro real
        // de rutas ahora se hace de forma centralizada en REST_API_Handler.php
    }

    /**
     * Formatea la respuesta de Verial para el endpoint de historial de pedidos
     *
     * @param array $verial_response Respuesta de la API de Verial
     * @return \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
     */
    protected function format_verial_response(array $verial_response): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
        if (!isset($verial_response['HistorialPedidos']) || !is_array($verial_response['HistorialPedidos'])) {
            $this->logger->errorProducto(
                '[GetHistorialPedidosWS] La respuesta de Verial no contiene la clave "HistorialPedidos" esperada o no es un array.',
                array('verial_response' => $verial_response)
            );
            $error_message = __('Los datos de historial de pedidos recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api');
            return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
                false,
                ['verial_response' => $verial_response],
                new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
                    'verial_api_malformed_historial_pedidos_data',
                    $error_message,
                    ['status' => 500]
                ),
                500,
                $error_message,
                ['endpoint' => 'format_verial_response']
            );
        }

        $historial_pedidos = [];
        foreach ($verial_response['HistorialPedidos'] as $pedido_verial) {
            $historial_pedidos[] = $this->format_documento_data($pedido_verial);
        }
        
        return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
            $historial_pedidos,
            __('Historial de pedidos obtenido correctamente.', 'mi-integracion-api'),
            [
                'endpoint' => self::ENDPOINT_NAME,
                'items_count' => count($historial_pedidos),
                'timestamp' => time()
            ]
        );
    }

    /**
     * Implementación requerida por la clase abstracta Base.
     * Ejecuta la lógica principal del endpoint REST.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
     */
    public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
        $params = $request->get_params();
        $sesionwcf = $params['sesionwcf'] ?? null;
        $force_refresh = $params['force_refresh'] ?? false;
        $id_cliente = $params['id_cliente'] ?? null;
        $fechadesde = $params['fechadesde'] ?? null;
        $fechahasta = $params['fechahasta'] ?? null;
        $allareasventa = $params['allareasventa'] ?? null;

        if (empty($sesionwcf)) {
            $error_message = __('El parámetro sesionwcf es requerido.', 'mi-integracion-api');
            return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
                false,
                ['field' => 'sesionwcf', 'value' => $sesionwcf],
                new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
                    'rest_missing_param',
                    $error_message,
                    ['status' => 400, 'field' => 'sesionwcf']
                ),
                400,
                $error_message,
                ['endpoint' => 'execute_restful']
            );
        }

        $cache_params_for_key = array(
            'sesionwcf' => $sesionwcf,
            'id_cliente' => $id_cliente,
            'fechadesde' => $fechadesde,
            'fechahasta' => $fechahasta,
            'allareasventa' => $allareasventa,
        );

        if (!$force_refresh) {
            $cached_data = $this->get_cached_data($cache_params_for_key);
            if (false !== $cached_data) {
                return rest_ensure_response($cached_data);
            }
        }

        $verial_api_params = array('x' => $sesionwcf);
        
        if ($id_cliente) {
            $verial_api_params['id_cliente'] = $id_cliente;
        }
        if ($fechadesde) {
            $verial_api_params['fecha'] = $fechadesde;
        }
        if ($fechahasta) {
            $verial_api_params['hora'] = $fechahasta;
        }
        if ($allareasventa !== null) {
            $verial_api_params['allareasventa'] = $allareasventa;
        }

        $response_verial = $this->connector->get(self::ENDPOINT_NAME, $verial_api_params);

        // Verificar si la respuesta de Verial es exitosa
        if ($response_verial instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$response_verial->isSuccess()) {
            return rest_ensure_response($response_verial);
        }

        // Formatear la respuesta de Verial
        $formatted_response = $this->format_verial_response($response_verial);
        
        // Convertir SyncResponseInterface a WP_REST_Response usando WordPressAdapter
        $wp_response = \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($formatted_response);
        
        // Si la respuesta es exitosa, guardar en caché
        if ($formatted_response->isSuccess() && $this->use_cache()) {
            $formatted_data = $formatted_response->getData();
            // Usar la función base para formatear la respuesta estándar
            $formatted_response_data = $this->format_success_response($formatted_data);
            $this->set_cached_data($cache_params_for_key, $formatted_response_data);
        }
        
        return $wp_response;
    }

} // cierre de la clase
