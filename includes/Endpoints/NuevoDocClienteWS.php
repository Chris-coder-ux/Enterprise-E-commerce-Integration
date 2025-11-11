<?php declare(strict_types=1);
/**
 * Clase para el endpoint NuevoDocClienteWS de la API de Verial ERP.
 */

namespace MiIntegracionApi\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Core\CacheConfig;
use MiIntegracionApi\Constants\VerialTypes;

class NuevoDocClienteWS extends Base {

	use EndpointLogger;

	const ENDPOINT_NAME    = 'NuevoDocClienteWS';
	const CACHE_KEY_PREFIX = 'mi_api_nuevo_doc_cliente_';
	// Usando constantes centralizadas de caché (no cachear escritura)

	// Tipos de documento (sección 25) - Usando constantes centralizadas
	// VerialTypes es una clase de constantes, no un trait

	/**
	 * Implementación requerida por la clase abstracta Base.
	 * El registro real de rutas ahora está centralizado en REST_API_Handler.php
	 */
	public function register_route(): void {
		// Esta implementación está vacía ya que el registro real
		// de rutas ahora se hace de forma centralizada en REST_API_Handler.php
	}

	public function __construct( \MiIntegracionApi\Core\ApiConnector $connector ) {
		parent::__construct( $connector );
		$this->init_logger( 'pedidos' );
	}

	// Eliminada la función register_route porque el registro de la ruta ahora es centralizado en REST_API_Handler.php

	public function permissions_check( \WP_REST_Request $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			$error_message = esc_html__( 'No tienes permiso para gestionar documentos.', 'mi-integracion-api' );
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
		$cliente_properties = array();
		// Obtener la definición de argumentos para el objeto Cliente de forma segura
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\NuevoClienteWS' ) && method_exists( 'MiIntegracionApi\\Endpoints\\NuevoClienteWS', 'get_cliente_properties_args' ) ) {
			$cliente_properties = \MiIntegracionApi\Endpoints\NuevoClienteWS::get_cliente_properties_args();
		} else {
			// Definir estructura mínima si no existe la clase
			$cliente_properties = array(
				'nombre' => array(
					'type'        => 'string',
					'description' => __( 'Nombre del cliente', 'mi-integracion-api' ),
					'required'    => true,
				),
				// ...otros campos mínimos...
			);
		}

		$args = array(
			'sesionwcf'           => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'Id'                  => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param, $request, $key ) {
					return is_numeric( $param ) && $param >= 0;
				},
			),
			'Tipo'                => array(
				'description' => __( 'Tipo de documento (1:Factura, 3:Albarán, 4:Factura Simpl., 5:Pedido, 6:Presupuesto).', 'mi-integracion-api' ),
				'type'        => 'integer',
				'required'    => true,
				'enum'        => array( 
					VerialTypes::DOCUMENT_TYPE_INVOICE, 
					VerialTypes::DOCUMENT_TYPE_DELIVERY_NOTE, 
					VerialTypes::DOCUMENT_TYPE_SIMPLIFIED_INVOICE, 
					VerialTypes::DOCUMENT_TYPE_ORDER, 
					VerialTypes::DOCUMENT_TYPE_QUOTE 
				),
			),
			'Referencia'          => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_REFERENCIA,
			),
			'Numero'              => array(
				'description'       => __( 'Número de documento (si la web lleva numeración propia).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'Fecha'               => array(
				'description'       => __( 'Fecha del documento (YYYY-MM-DD).', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'required'          => true,
				'validate_callback' => array( $this, 'validate_date_format' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'ID_Cliente'          => array(
				'description'       => __( 'ID del cliente en Verial (si ya existe y no se modifica).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'Cliente'             => array(
				'description'       => __( 'Datos del cliente para crear o modificar (estructura de NuevoClienteWS).', 'mi-integracion-api' ),
				'type'              => 'object',
				'required'          => false,
				'properties'        => $cliente_properties,
				'validate_callback' => array( $this, 'validate_cliente_object' ),
			),
			'EtiquetaCliente'     => array(
				'description'       => __( 'Nombre y dirección del cliente en modo etiqueta (para presupuestos sin crear cliente).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_ETIQUETA_CLIENTE,
			),
			'ID_DireccionEnvio'   => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'ID_Agente1'          => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'ID_Agente2'          => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'ID_Agente3'          => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'ID_MetodoPago'       => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'ID_FormaEnvio'       => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'ID_Destino'          => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'Peso'                => array(
				'type'              => 'number',
				'required'          => false,
				'sanitize_callback' => array( $this, 'sanitize_decimal_text_to_float' ),
			),
			'Bultos'              => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'TipoPortes'          => array(
				'type'              => 'integer',
				'required'          => false,
				'enum'              => array( 0, 1, 2, 3 ),
				'sanitize_callback' => 'absint',
			),
			'PreciosImpIncluidos' => array(
				'type'              => 'boolean',
				'required'          => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'BaseImponible'       => array(
				'type'              => 'number',
				'required'          => false,
				'sanitize_callback' => array( $this, 'sanitize_decimal_text_to_float' ),
			),
			'TotalImporte'        => array(
				'type'              => 'number',
				'required'          => false,
				'sanitize_callback' => array( $this, 'sanitize_decimal_text_to_float' ),
			),
			'Portes'              => array(
				'type'              => 'number',
				'required'          => false,
				'sanitize_callback' => array( $this, 'sanitize_decimal_text_to_float' ),
			),
			'Comentario'          => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_COMENTARIO,
			),
			'Descripcion'         => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_DESCRIPCION_DOC,
			),
			'Aux1'                => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_AUX,
			),
			'Aux2'                => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_AUX,
			),
			'Aux3'                => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_AUX,
			),
			'Aux4'                => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_AUX,
			),
			'Aux5'                => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_AUX,
			),
			'Aux6'                => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => VerialTypes::MAX_LENGTH_AUX,
			),
			'Contenido'           => array(
				'description'       => __( 'Líneas de contenido del documento.', 'mi-integracion-api' ),
				'type'              => 'array',
				'required'          => true,
				'validate_callback' => array( $this, 'validate_contenido_array' ),
				'items'             => array(
					'type'       => 'object',
					'properties' => array(
						'TipoRegistro'      => array(
							'type'     => 'integer',
							'required' => true,
							'enum'     => array( 1, 2 ),
						),
						'ID_Articulo'       => array(
							'type'              => 'integer',
							'required'          => function ( $item_params, $request, $key ) {
								return isset( $item_params['TipoRegistro'] ) && $item_params['TipoRegistro'] === VerialTypes::REGISTRY_TYPE_PRODUCT;
							},
							'sanitize_callback' => 'absint',
						),
						'Comentario'        => array(
							'type'              => 'string',
							'required'          => function ( $item_params, $request, $key ) {
								return isset( $item_params['TipoRegistro'] ) && $item_params['TipoRegistro'] === VerialTypes::REGISTRY_TYPE_COMMENT;
							},
							'sanitize_callback' => 'sanitize_text_field',
							'maxLength'         => VerialTypes::MAX_LENGTH_COMENTARIO_LINEA,
						),
						'Uds'               => array(
							'type'              => 'number',
							'required'          => function ( $item_params, $request, $key ) {
								return isset( $item_params['TipoRegistro'] ) && $item_params['TipoRegistro'] === VerialTypes::REGISTRY_TYPE_PRODUCT;
							},
							'sanitize_callback' => array( $this, 'sanitize_decimal_text_to_float' ),
						),
						'Precio'            => array(
							'type'              => 'number',
							'required'          => function ( $item_params, $request, $key ) {
								return isset( $item_params['TipoRegistro'] ) && $item_params['TipoRegistro'] === VerialTypes::REGISTRY_TYPE_PRODUCT;
							},
							'sanitize_callback' => array( $this, 'sanitize_decimal_text_to_float' ),
						),
						'Dto'               => array(
							'type'              => 'number',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_decimal_text_to_float' ),
						),
						'DtoEurosXUd'       => array(
							'type'              => 'number',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_decimal_text_to_float' ),
						),
						'DtoEuros'          => array(
							'type'              => 'number',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_decimal_text_to_float' ),
						),
						'ImporteLinea'      => array(
							'type'              => 'number',
							'required'          => function ( $item_params, $request, $key ) {
								return isset( $item_params['TipoRegistro'] ) && $item_params['TipoRegistro'] === VerialTypes::REGISTRY_TYPE_PRODUCT;
							},
							'sanitize_callback' => array( $this, 'sanitize_decimal_text_to_float' ),
						),
						'Lote'              => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
							'maxLength'         => 30,
						),
						'Caducidad'         => array(
							'type'              => 'string',
							'format'            => 'date',
							'required'          => false,
							'validate_callback' => array( $this, 'validate_date_format_optional' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'ID_Partida'        => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'PorcentajeIVA'     => array(
							'type'              => 'number',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_decimal_text_to_float' ),
						),
						'PorcentajeRE'      => array(
							'type'              => 'number',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_decimal_text_to_float' ),
						),
						'DescripcionAmplia' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_textarea_field',
							'maxLength'         => VerialTypes::MAX_LENGTH_DESCRIPCION_AMPLIA_LINEA,
						),
						'Concepto'          => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
							'maxLength'         => VerialTypes::MAX_LENGTH_CONCEPTO_LINEA,
						),
					),
				),
			),
		);
		return $args;
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'nuevo_doc', $api_key );
		if ( $rate_limit instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$rate_limit->isSuccess() ) {
			return rest_ensure_response( $rate_limit );
		}

		$params                  = $request->get_params(); // Parámetros ya validados y sanitizados por WP REST API
		$is_update               = isset( $request['id_documento_verial'] );
		$id_documento_verial_url = $is_update ? absint( $request['id_documento_verial'] ) : 0;

		if ( $is_update && empty( $id_documento_verial_url ) ) {
			$error_message = __( 'El ID del documento es requerido en la URL para actualizaciones.', 'mi-integracion-api' );
			return rest_ensure_response( new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['id_documento_verial' => $id_documento_verial_url],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_invalid_document_id_update',
					$error_message,
					['status' => 400]
				),
				400,
				$error_message,
				['endpoint' => 'execute_restful']
			) );
		}

		$verial_payload = array();

		// Campos directamente del cuerpo de la solicitud (ya sanitizados por 'args')
		$direct_fields = array(
			'sesionwcf',
			'Tipo',
			'Referencia',
			'Numero',
			'Fecha',
			'ID_DireccionEnvio',
			'ID_Agente1',
			'ID_Agente2',
			'ID_Agente3',
			'ID_MetodoPago',
			'ID_FormaEnvio',
			'ID_Destino',
			'Peso',
			'Bultos',
			'TipoPortes',
			'PreciosImpIncluidos',
			'BaseImponible',
			'TotalImporte',
			'Portes',
			'Comentario',
			'Descripcion',
			'Aux1',
			'Aux2',
			'Aux3',
			'Aux4',
			'Aux5',
			'Aux6',
		);

		foreach ( $direct_fields as $field_key ) {
			if ( isset( $params[ $field_key ] ) ) {
				// Asegurar tipos correctos para Verial
				if ( in_array( $field_key, array( 'sesionwcf', 'Tipo', 'Numero', 'ID_DireccionEnvio', 'ID_Agente1', 'ID_Agente2', 'ID_Agente3', 'ID_MetodoPago', 'ID_FormaEnvio', 'ID_Destino', 'Bultos', 'TipoPortes' ) ) ) {
					$verial_payload[ $field_key ] = (int) $params[ $field_key ];
				} elseif ( $field_key === 'PreciosImpIncluidos' ) {
					$verial_payload[ $field_key ] = (bool) $params[ $field_key ]; // Verial espera true/false
				} elseif ( in_array( $field_key, array( 'Peso', 'BaseImponible', 'TotalImporte', 'Portes' ) ) ) {
					// El callback sanitize_decimal_text_to_float ya devuelve float o null
					$verial_payload[ $field_key ] = $params[ $field_key ];
				} else {
					$verial_payload[ $field_key ] = $params[ $field_key ];
				}
			}
		}

		// Manejar ID del documento
		if ( $is_update ) {
			$verial_payload['Id'] = $id_documento_verial_url;
		} elseif ( isset( $params['Id'] ) ) {
			$verial_payload['Id'] = (int) $params['Id']; // Usualmente 0 para creación
		} else {
			$verial_payload['Id'] = 0; // Default para creación
		}

		// Datos del cliente
		$cliente_data_for_payload = $this->build_cliente_payload( $params );
		if ( ! empty( $cliente_data_for_payload ) ) {
			$verial_payload = array_merge( $verial_payload, $cliente_data_for_payload );
		} elseif ( empty( $verial_payload['ID_Cliente'] ) && empty( $verial_payload['EtiquetaCliente'] ) ) {
			// Si después de build_cliente_payload no hay ID_Cliente ni Cliente ni EtiquetaCliente, es un error.
			// Esta validación es crucial.
			$error_message = __( 'Se debe proporcionar ID_Cliente, datos de Cliente o EtiquetaCliente.', 'mi-integracion-api' );
			return rest_ensure_response( new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['verial_payload' => $verial_payload],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_missing_client_data',
					$error_message,
					['status' => 400]
				),
				400,
				$error_message,
				['endpoint' => 'execute_restful']
			) );
		}

		// Líneas de contenido (Contenido)
		if ( isset( $params['Contenido'] ) && is_array( $params['Contenido'] ) ) {
			$verial_payload['Contenido'] = array_map(
				function ( $linea ) {
					$clean_linea = array();
					if ( isset( $linea['TipoRegistro'] ) ) {
						$clean_linea['TipoRegistro'] = (int) $linea['TipoRegistro'];
					}
					if ( isset( $linea['ID_Articulo'] ) ) {
						$clean_linea['ID_Articulo'] = (int) $linea['ID_Articulo'];
					}
					if ( isset( $linea['Comentario'] ) ) {
						$clean_linea['Comentario'] = sanitize_text_field( $linea['Comentario'] );
					}
					// sanitize_decimal_text_to_float ya devuelve float o null. Verial espera números.
					if ( isset( $linea['Uds'] ) ) {
						$clean_linea['Uds'] = $linea['Uds'];
					}
					if ( isset( $linea['Precio'] ) ) {
						$clean_linea['Precio'] = $linea['Precio'];
					}
					if ( isset( $linea['Dto'] ) ) {
						$clean_linea['Dto'] = $linea['Dto'];
					}
					if ( isset( $linea['DtoEurosXUd'] ) ) {
						$clean_linea['DtoEurosXUd'] = $linea['DtoEurosXUd'];
					}
					if ( isset( $linea['DtoEuros'] ) ) {
						$clean_linea['DtoEuros'] = $linea['DtoEuros'];
					}
					if ( isset( $linea['ImporteLinea'] ) ) {
						$clean_linea['ImporteLinea'] = $linea['ImporteLinea'];
					}
					if ( isset( $linea['Lote'] ) ) {
						$clean_linea['Lote'] = sanitize_text_field( $linea['Lote'] );
					}
					if ( isset( $linea['Caducidad'] ) ) {
						$clean_linea['Caducidad'] = sanitize_text_field( $linea['Caducidad'] );
					}
					if ( isset( $linea['ID_Partida'] ) ) {
						$clean_linea['ID_Partida'] = (int) $linea['ID_Partida'];
					}
					if ( isset( $linea['PorcentajeIVA'] ) ) {
						$clean_linea['PorcentajeIVA'] = $linea['PorcentajeIVA'];
					}
					if ( isset( $linea['PorcentajeRE'] ) ) {
						$clean_linea['PorcentajeRE'] = $linea['PorcentajeRE'];
					}
					if ( isset( $linea['DescripcionAmplia'] ) ) {
						$clean_linea['DescripcionAmplia'] = sanitize_text_field( $linea['DescripcionAmplia'] );
					}
					if ( isset( $linea['Concepto'] ) ) {
						$clean_linea['Concepto'] = sanitize_text_field( $linea['Concepto'] );
					}
					return $clean_linea;
				},
				$params['Contenido']
			);
		}

		// Aquí se puede agregar lógica adicional antes de enviar a Verial, si es necesario.

		// Envío a Verial (simulado aquí como un registro)
		$this->log_request( $verial_payload, 'verial_payload' );

		// Respuesta simulada de Verial
		$response = array(
			'status'  => 'success',
			'message' => __( 'Documento procesado correctamente.', 'mi-integracion-api' ),
			'data'    => $verial_payload,
		);

		return rest_ensure_response( $response );
	}

	// ... Resto de la clase sin cambios ...
}
