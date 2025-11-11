<?php

declare(strict_types=1);

/**
 * Manejador centralizado de endpoints REST API para Mi Integración API
 *
 * Esta clase es responsable de registrar y gestionar todos los endpoints REST API
 * utilizados por el plugin. Sigue el patrón de diseño Singleton para garantizar
 * una única instancia en el ciclo de vida de la aplicación.
 *
 * CARACTERÍSTICAS PRINCIPALES:
 * - Registro centralizado de endpoints REST
 * - Gestión de autenticación y permisos
 * - Validación de parámetros
 * - Formato consistente de respuestas
 * - Documentación automática de la API
 *
 * IMPORTANTE: Esta es la ubicación centralizada para todos los endpoints REST API del plugin.
 * Todos los nuevos endpoints DEBEN ser agregados aquí y NO directamente en mi-integracion-api.php
 * para mantener la organización y facilitar el mantenimiento.
 *
 * @package    MiIntegracionApi\Core
 * @subpackage REST_API
 * @category   API
 * @author     Christian
 * @license    GPL-2.0+ http://www.gnu.org/licenses/gpl-2.0.txt
 * @link       https://www.verialerp.com
 * @since      1.0.0
 * @version    2.0.0
 */

namespace MiIntegracionApi\Core;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase principal para el manejo de la API REST del plugin
 *
 * Esta clase implementa el patrón Singleton y se encarga de registrar todos los
 * endpoints REST utilizados por el plugin. Proporciona métodos para manejar
 * autenticación, permisos, validación de datos y formateo de respuestas.
 *
 * Los endpoints están organizados por funcionalidad y siguen las mejores prácticas
 * de diseño de APIs RESTful.
 *
 * @category   API
 * @package    MiIntegracionApi\Core
 * @author     Christian
 * @since      1.0.0
 */
class REST_API_Handler {
    /**
     * Namespace base para todos los endpoints de la API
     *
     * Define el prefijo común para todas las rutas de la API.
     * Formato: {prefijo}/v{versión}
     *
     * @var string
     * @since 1.0.0
     */
    const API_NAMESPACE = 'mi-integracion-api/v1';

    /**
     * Instancia única de la clase (patrón Singleton)
     *
     * @var REST_API_Handler|null Instancia única de la clase
     * @since 1.0.0
     */
    private static $instance = null;

    /**
     * Constructor privado para implementar el patrón Singleton
     *
     * Inicializa el manejador de la API REST y registra el hook para
     * registrar las rutas cuando WordPress esté listo.
     *
     * @since 1.0.0
     */
    private function __construct() {
        // Registrar el hook para inicializar las rutas REST
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Obtiene la instancia única de la clase (Singleton)
     *
     * Este método implementa el patrón Singleton para garantizar que solo exista
     * una instancia de esta clase en el sistema.
     *
     * @static
     * @return self Instancia única de la clase
     * @since 1.0.0
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializa el manejador de la API REST
     *
     * Método de conveniencia para inicializar la API REST.
     * Simplemente obtiene la instancia única de la clase.
     *
     * @static
     * @return void
     * @since 1.0.0
     */
    public static function init() {
        self::get_instance();
    }

    /**
     * Registra todas las rutas de la API REST
     *
     * Este método es el punto central de registro de todos los endpoints de la API.
     * Se ejecuta durante el hook 'rest_api_init' de WordPress.
     *
     * Las rutas se organizan por funcionalidad y cada una puede tener sus propios
     * manejadores de permisos y validación de parámetros.
     *
     * @return void
     * @since 1.0.0
     * @action rest_api_init
     */
    public function register_routes() {
		// Inicializar el endpoint de logs (pero no registrarlo aquí)
		// La propia clase LogsEndpoint registra sus endpoints con su propio namespace
		if ( class_exists( '\MiIntegracionApi\Endpoints\LogsEndpoint' ) ) {
			new \MiIntegracionApi\Endpoints\LogsEndpoint();
		}

		// Endpoint para verificar estado de autenticación
		register_rest_route(
			self::API_NAMESPACE,
			'/auth/status',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
				'callback'            => array( $this, 'get_auth_status' ),
				'permission_callback' => function () {
					return true; // Permitir acceso público a este endpoint
				},
			)
		);

		// Endpoint para verificar estado de conexión
		register_rest_route(
			self::API_NAMESPACE,
			'/connection/status',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
				'callback'            => array( $this, 'get_connection_status' ),
				'permission_callback' => function ( $request ) {
					// Permitir acceso público a este endpoint
					return true;
				},
			)
		);

		// Endpoint para obtener endpoints disponibles del sistema
		register_rest_route(
			self::API_NAMESPACE,
			'/endpoints/available',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
				'callback'            => array( $this, 'get_available_endpoints' ),
				'permission_callback' => function ( $request ) {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Endpoint para probar la conexión
		register_rest_route(
			self::API_NAMESPACE,
			'/connection/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Endpoint para obtener la configuración
		register_rest_route(
			self::API_NAMESPACE,
			'/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Endpoint para actualizar la configuración
		register_rest_route(
			self::API_NAMESPACE,
			'/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'api_url'    => array(
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
					'api_key'    => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'debug_mode' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		// --- Registro centralizado de endpoints de negocio ---
		// Usar la instancia singleton de ApiConnector para evitar configuraciones duplicadas
		$api_connector = \MiIntegracionApi\Core\ApiConnector::get_instance();

		// Endpoint: Clientes
		$clientes_endpoint = new \MiIntegracionApi\Endpoints\GetClientesWS( $api_connector );
		register_rest_route(
			self::API_NAMESPACE,
			'/clientes',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
				'callback'            => array( $clientes_endpoint, 'execute_restful' ),
				'permission_callback' => array( $clientes_endpoint, 'permissions_check' ),
				'args'                => $clientes_endpoint->get_endpoint_args( false ),
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/clientes/(?P<id_cliente_verial>[\\d]+)',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
				'callback'            => array( $clientes_endpoint, 'execute_restful' ),
				'permission_callback' => array( $clientes_endpoint, 'permissions_check' ),
				'args'                => $clientes_endpoint->get_endpoint_args( true ),
			)
		);

		// Endpoint: Mascotas
		$mascotas_endpoint = new \MiIntegracionApi\Endpoints\GetMascotasWS( $api_connector );
		register_rest_route(
			self::API_NAMESPACE,
			'/mascotas',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
				'callback'            => array( $mascotas_endpoint, 'execute_restful' ),
				'permission_callback' => array( $mascotas_endpoint, 'permissions_check' ),
				'args'                => $mascotas_endpoint->get_endpoint_args( false ),
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/clientes/(?P<id_cliente_param>[\\d]+)/mascotas',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
				'callback'            => array( $mascotas_endpoint, 'execute_restful' ),
				'permission_callback' => array( $mascotas_endpoint, 'permissions_check_cliente_mascotas' ),
				'args'                => $mascotas_endpoint->get_endpoint_args( true ),
			)
		);

		// Endpoint: Artículos
		$articulos_endpoint = new \MiIntegracionApi\Endpoints\GetArticulosWS( $api_connector );
		register_rest_route(
			self::API_NAMESPACE,
			'/articulos',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
				'callback'            => array( $articulos_endpoint, 'execute_restful' ),
				'permission_callback' => array( $articulos_endpoint, 'permissions_check' ),
				'args'                => $articulos_endpoint->get_endpoint_args(),
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/articulos/(?P<id_articulo_verial>[\\d]+)',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
				'callback'            => array( $articulos_endpoint, 'execute_restful' ),
				'permission_callback' => array( $articulos_endpoint, 'permissions_check' ),
				'args'                => $articulos_endpoint->get_endpoint_args( true ),
			)
		);

		// Endpoint: Provincias
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\ProvinciasWS' ) ) {
			$provincias_endpoint = new \MiIntegracionApi\Endpoints\ProvinciasWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/provincias',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $provincias_endpoint, 'execute_restful' ),
					'permission_callback' => array( $provincias_endpoint, 'permissions_check' ),
					'args'                => $provincias_endpoint->get_endpoint_args(),
				)
			);
		}

		// Endpoint: Países
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\GetPaisesWS' ) ) {
			$paises_endpoint = new \MiIntegracionApi\Endpoints\GetPaisesWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/paises',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $paises_endpoint, 'execute_restful' ),
					'permission_callback' => array( $paises_endpoint, 'permissions_check' ),
					'args'                => $paises_endpoint->get_endpoint_args(),
				)
			);
		}

		// Endpoint: Agentes
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\GetAgentesWS' ) ) {
			$agentes_endpoint = new \MiIntegracionApi\Endpoints\GetAgentesWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/agentes',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $agentes_endpoint, 'execute_restful' ),
					'permission_callback' => array( $agentes_endpoint, 'permissions_check' ),
					'args'                => $agentes_endpoint->get_endpoint_args(),
				)
			);
		}

		// Endpoint: Categorías
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\GetCategoriasWS' ) ) {
			$categorias_endpoint = new \MiIntegracionApi\Endpoints\GetCategoriasWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/categorias-articulos',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $categorias_endpoint, 'execute_restful' ),
					'permission_callback' => array( $categorias_endpoint, 'permissions_check' ),
					'args'                => $categorias_endpoint->get_endpoint_args(),
				)
			);
		}

		// Endpoint: Asignaturas
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\AsignaturasWS' ) ) {
			$asignaturas_endpoint = new \MiIntegracionApi\Endpoints\GetAsignaturasWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/asignaturas',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $asignaturas_endpoint, 'execute_restful' ),
					'permission_callback' => array( $asignaturas_endpoint, 'permissions_check' ),
					'args'                => $asignaturas_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Historial Pedidos
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\HistorialPedidosWS' ) ) {
			$hist_pedidos_endpoint = new \MiIntegracionApi\Endpoints\GetHistorialPedidosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/pedidos/historial',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $hist_pedidos_endpoint, 'execute_restful' ),
					'permission_callback' => array( $hist_pedidos_endpoint, 'permissions_check' ),
					'args'                => $hist_pedidos_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Colecciones
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\ColeccionesWS' ) ) {
			$colecciones_endpoint = new \MiIntegracionApi\Endpoints\GetColeccionesWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/colecciones',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $colecciones_endpoint, 'execute_restful' ),
					'permission_callback' => array( $colecciones_endpoint, 'permissions_check' ),
					'args'                => $colecciones_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Fabricantes
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\FabricantesWS' ) ) {
			$fabricantes_endpoint = new \MiIntegracionApi\Endpoints\GetFabricantesWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/fabricantes',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $fabricantes_endpoint, 'execute_restful' ),
					'permission_callback' => array( $fabricantes_endpoint, 'permissions_check' ),
					'args'                => $fabricantes_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Imágenes Artículos
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\GetImagenesArticulosWS' ) ) {
			$imagenes_endpoint = new \MiIntegracionApi\Endpoints\GetImagenesArticulosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/imagenes',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $imagenes_endpoint, 'execute_restful' ),
					'permission_callback' => array( $imagenes_endpoint, 'permissions_check' ),
					'args'                => $imagenes_endpoint->get_endpoint_args( false ),
				)
			);
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/(?P<id_articulo_verial>[\\d]+)/imagenes',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $imagenes_endpoint, 'execute_restful' ),
					'permission_callback' => array( $imagenes_endpoint, 'permissions_check' ),
					'args'                => $imagenes_endpoint->get_endpoint_args( true ),
				)
			);
		}
		// Endpoint: Localidades
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\LocalidadesWS' ) ) {
			$localidades_endpoint = new \MiIntegracionApi\Endpoints\GetLocalidadesWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/localidades',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $localidades_endpoint, 'execute_restful' ),
					'permission_callback' => array( $localidades_endpoint, 'permissions_check' ),
					'args'                => $localidades_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Árbol Campos Configurables Artículos
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\GetArbolCamposConfigurablesArticulosWS' ) ) {
			$arbol_campos_endpoint = new \MiIntegracionApi\Endpoints\GetArbolCamposConfigurablesArticulosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/campos-configurables/arbol',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $arbol_campos_endpoint, 'execute_restful' ),
					'permission_callback' => array( $arbol_campos_endpoint, 'permissions_check' ),
					'args'                => $arbol_campos_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Stock Artículos
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\GetStockArticulosWS' ) ) {
			$stock_endpoint = new \MiIntegracionApi\Endpoints\GetStockArticulosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/stock',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $stock_endpoint, 'execute_restful' ),
					'permission_callback' => array( $stock_endpoint, 'permissions_check' ),
					'args'                => $stock_endpoint->get_endpoint_args( false ),
				)
			);
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/(?P<id_articulo_verial>[\\d]+)/stock',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $stock_endpoint, 'execute_restful' ),
					'permission_callback' => array( $stock_endpoint, 'permissions_check' ),
					'args'                => $stock_endpoint->get_endpoint_args( true ),
				)
			);
		}
		// Endpoint: Valores Validados Campo Configurable Artículos
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\GetValoresValidadosCampoConfigurableArticulosWS' ) ) {
			$valores_endpoint = new \MiIntegracionApi\Endpoints\GetValoresValidadosCampoConfigurableArticulosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/campos-configurables/valores-validados',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $valores_endpoint, 'execute_restful' ),
					'permission_callback' => array( $valores_endpoint, 'permissions_check' ),
					'args'                => $valores_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Siguiente Número de Documento
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\NextNumDocsWS' ) ) {
			$nextnumdocs_endpoint = new \MiIntegracionApi\Endpoints\GetNextNumDocsWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/documentos/siguiente-numero',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $nextnumdocs_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nextnumdocs_endpoint, 'permissions_check' ),
					'args'                => $nextnumdocs_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Número de Artículos
		if ( class_exists( 'MiIntegracionApi\Endpoints\GetNumArticulosWS' ) ) {
			$num_articulos_endpoint = new \MiIntegracionApi\Endpoints\GetNumArticulosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/num',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $num_articulos_endpoint, 'execute_restful' ),
					'permission_callback' => array( $num_articulos_endpoint, 'permissions_check' ),
					'args'                => $num_articulos_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Versión del Servicio
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\VersionWS' ) ) {
			$version_endpoint = new \MiIntegracionApi\Endpoints\GetVersionWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/verial-service/version',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $version_endpoint, 'execute_restful' ),
					'permission_callback' => array( $version_endpoint, 'permissions_check' ),
					'args'                => $version_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Cursos
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\CursosWS' ) ) {
			$cursos_endpoint = new \MiIntegracionApi\Endpoints\GetCursosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/cursos',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $cursos_endpoint, 'execute_restful' ),
					'permission_callback' => array( $cursos_endpoint, 'permissions_check' ),
					'args'                => $cursos_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Formas de Envío
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\FormasEnvioWS' ) ) {
			$formas_envio_endpoint = new \MiIntegracionApi\Endpoints\FormasEnvioWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/formas-envio',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $formas_envio_endpoint, 'execute_restful' ),
					'permission_callback' => array( $formas_envio_endpoint, 'permissions_check' ),
					'args'                => $formas_envio_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Categorías Web
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\CategoriasWebWS' ) ) {
			$categorias_web_endpoint = new \MiIntegracionApi\Endpoints\GetCategoriasWebWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/categorias-web',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $categorias_web_endpoint, 'execute_restful' ),
					'permission_callback' => array( $categorias_web_endpoint, 'permissions_check' ),
					'args'                => $categorias_web_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Condiciones Tarifa
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\CondicionesTarifaWS' ) ) {
			$condiciones_tarifa_endpoint = new \MiIntegracionApi\Endpoints\GetCondicionesTarifaWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/condiciones-tarifa',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $condiciones_tarifa_endpoint, 'execute_restful' ),
					'permission_callback' => array( $condiciones_tarifa_endpoint, 'permissions_check' ),
					'args'                => $condiciones_tarifa_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Métodos de Pago
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\MetodosPagoWS' ) ) {
			$metodospagos_endpoint = new \MiIntegracionApi\Endpoints\GetMetodosPagoWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/metodos-pago',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $metodospagos_endpoint, 'execute_restful' ),
					'permission_callback' => array( $metodospagos_endpoint, 'permissions_check' ),
					'args'                => $metodospagos_endpoint->get_endpoint_args(),
				)
			);
		}
		// --- Endpoints POST/PUT centralizados ---
		// Nuevo Pago
		if ( class_exists( 'MI_Endpoint_NuevoPagoWS' ) ) {
			$nuevo_pago_endpoint = new \MI_Endpoint_NuevoPagoWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/documento/(?P<id_documento_verial>[\d]+)/pago',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
					'callback'            => array( $nuevo_pago_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nuevo_pago_endpoint, 'permissions_check' ),
					'args'                => $nuevo_pago_endpoint->get_endpoint_args(),
				)
			);
		}
		// Nuevo Cliente
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\NuevoClienteWS' ) ) {
			$nuevo_cliente_endpoint = new \MiIntegracionApi\Endpoints\NuevoClienteWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/cliente',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
					'callback'            => array( $nuevo_cliente_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nuevo_cliente_endpoint, 'permissions_check' ),
					'args'                => $nuevo_cliente_endpoint->get_endpoint_args( false ),
				)
			);
		}
		// Nueva Dirección de Envío
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\NuevaDireccionEnvioWS' ) ) {
			$nueva_direccion_endpoint = new \MiIntegracionApi\Endpoints\NuevaDireccionEnvioWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/cliente/(?P<id_cliente_verial>[\d]+)/direccion-envio',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
					'callback'            => array( $nueva_direccion_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nueva_direccion_endpoint, 'permissions_check' ),
					'args'                => $nueva_direccion_endpoint->get_endpoint_args( false ),
				)
			);
		}
		// Nueva Mascota (POST y PUT)
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\NuevaMascotaWS' ) ) {
			$nueva_mascota_endpoint = new \MiIntegracionApi\Endpoints\NuevaMascotaWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/cliente/(?P<id_cliente_verial>[\d]+)/mascota',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
					'callback'            => array( $nueva_mascota_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nueva_mascota_endpoint, 'permissions_check' ),
					'args'                => $nueva_mascota_endpoint->get_endpoint_args( false ),
				)
			);
			register_rest_route(
				self::API_NAMESPACE,
				'/cliente/(?P<id_cliente_verial>[\d]+)/mascota/(?P<id_mascota_verial>[\d]+)',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::EDITABLE : 'PUT',
					'callback'            => array( $nueva_mascota_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nueva_mascota_endpoint, 'permissions_check' ),
					'args'                => $nueva_mascota_endpoint->get_endpoint_args( true ),
				)
			);
		}
		// Nueva Localidad
		if ( class_exists( 'MI_Endpoint_NuevaLocalidadWS' ) ) {
			$nueva_localidad_endpoint = new \MI_Endpoint_NuevaLocalidadWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/localidad',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
					'callback'            => array( $nueva_localidad_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nueva_localidad_endpoint, 'permissions_check' ),
					'args'                => $nueva_localidad_endpoint->get_endpoint_args(),
				)
			);
		}
		// Nueva Provincia
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\NuevaProvinciaWS' ) ) {
			$nueva_provincia_endpoint = new \MiIntegracionApi\Endpoints\NuevaProvinciaWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/provincia',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
					'callback'            => array( $nueva_provincia_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nueva_provincia_endpoint, 'permissions_check' ),
					'args'                => $nueva_provincia_endpoint->get_endpoint_args(),
				)
			);
		}
		// Update Doc Cliente
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\UpdateDocClienteWS' ) ) {
			$update_doc_endpoint = new \MiIntegracionApi\Endpoints\UpdateDocClienteWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/documento/(?P<id_documento_verial>[\d]+)',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::EDITABLE : 'PUT',
					'callback'            => array( $update_doc_endpoint, 'execute_restful' ),
					'permission_callback' => array( $update_doc_endpoint, 'permissions_check' ),
					'args'                => $update_doc_endpoint->get_endpoint_args( true ),
				)
			);
		}
		// Nuevo Documento Cliente (POST y PUT)
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\NuevoDocClienteWS' ) ) {
			$nuevo_doc_cliente_endpoint = new \MiIntegracionApi\Endpoints\NuevoDocClienteWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/documento',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
					'callback'            => array( $nuevo_doc_cliente_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nuevo_doc_cliente_endpoint, 'permissions_check' ),
					'args'                => $nuevo_doc_cliente_endpoint->get_endpoint_args( false ),
				)
			);
			register_rest_route(
				self::API_NAMESPACE,
				'/documento/(?P<id_documento_verial>[\d]+)',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::EDITABLE : 'PUT',
					'callback'            => array( $nuevo_doc_cliente_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nuevo_doc_cliente_endpoint, 'permissions_check' ),
					'args'                => $nuevo_doc_cliente_endpoint->get_endpoint_args( true ),
				)
			);
		}
		// Borrar Mascota (DELETE)
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\BorrarMascotaWS' ) ) {
			$borrar_mascota_endpoint = new \MiIntegracionApi\Endpoints\BorrarMascotaWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/cliente/(?P<id_cliente_verial>[\d]+)/mascota/(?P<id_mascota_verial>[\d]+)',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::DELETABLE : 'DELETE',
					'callback'            => array( $borrar_mascota_endpoint, 'execute_restful' ),
					'permission_callback' => array( $borrar_mascota_endpoint, 'permissions_check' ),
					'args'                => $borrar_mascota_endpoint->get_endpoint_args(),
				)
			);
		}
		// Estado Pedidos (POST)
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\EstadoPedidosWS' ) ) {
			$estado_pedidos_endpoint = new \MiIntegracionApi\Endpoints\EstadoPedidosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/pedidos/estados',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
					'callback'            => array( $estado_pedidos_endpoint, 'execute_restful' ),
					'permission_callback' => array( $estado_pedidos_endpoint, 'permissions_check' ),
					'args'                => $estado_pedidos_endpoint->get_endpoint_args(),
				)
			);
		}
		// Pedido Modificable (GET)
		if ( class_exists( 'MI_Endpoint_PedidoModificableWS' ) ) {
			$pedido_modificable_endpoint = new \MI_Endpoint_PedidoModificableWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/pedido/(?P<id_pedido_verial>[\d]+)/modificable',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $pedido_modificable_endpoint, 'execute_restful' ),
					'permission_callback' => array( $pedido_modificable_endpoint, 'permissions_check' ),
					'args'                => $pedido_modificable_endpoint->get_endpoint_args(),
				)
			);
		}

		// === F4B: NUEVOS ENDPOINTS IMPLEMENTADOS ===
		
		// Campos Configurables Artículos (GET)
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\GetCamposConfigurablesArticulosWS' ) ) {
			$campos_configurables_endpoint = new \MiIntegracionApi\Endpoints\GetCamposConfigurablesArticulosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/campos-configurables',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $campos_configurables_endpoint, 'execute_restful' ),
					'permission_callback' => array( $campos_configurables_endpoint, 'permissions_check' ),
					'args'                => $campos_configurables_endpoint->get_endpoint_args(),
				)
			);
		}

		// Formas de Envío (GET)
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\GetFormasEnvioWS' ) ) {
			$formas_envio_endpoint = new \MiIntegracionApi\Endpoints\GetFormasEnvioWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/formas-envio',
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( $formas_envio_endpoint, 'execute_restful' ),
					'permission_callback' => array( $formas_envio_endpoint, 'permissions_check' ),
					'args'                => $formas_envio_endpoint->get_endpoint_args(),
				)
			);
		}

		// === FIN F4B ENDPOINTS ===

		// --- Rutas de autenticación y sincronización ---
		// Estas rutas fueron migradas desde REST_Controller.php para centralizar todas las rutas REST

		// Rutas de autenticación JWT
		register_rest_route(
			self::API_NAMESPACE,
			'/auth/token',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'generate_token' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_auth_permissions' ),
				'args'                => array(
					'username' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => function ( $param ) {
							return \MiIntegracionApi\Core\InputValidation::sanitize( $param, 'text' );
						},
					),
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/auth/validate',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'validate_token' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/auth/refresh',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'refresh_token' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/auth/revoke',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'revoke_token' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_auth_or_admin_permissions' ),
			)
		);

		// Rutas de autenticación para credenciales
		register_rest_route(
			self::API_NAMESPACE,
			'/auth/credentials',
			array(
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
					'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'get_credentials' ),
					'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
				),
				array(
					'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
					'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'save_credentials' ),
					'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
				),
			)
		);

		// Rutas de sincronización
		register_rest_route(
			self::API_NAMESPACE,
			'/sync/start',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'start_sync' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/sync/batch',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'process_next_batch' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/sync/cancel',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'cancel_sync' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/sync/resume',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'resume_sync' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/sync/status',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'getSyncStatus' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/sync/history',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'get_sync_history' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/sync/retry',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'retry_sync_errors' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		// Rutas para pruebas de API
		register_rest_route(
			self::API_NAMESPACE,
			'/api/test',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::CREATABLE : 'POST',
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'test_api' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		// --- ENDPOINT: Comprobar conexión Verial ---
		register_rest_route(
			self::API_NAMESPACE,
			'/verial/check',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
				'callback'            => array( $this, 'check_verial_connection' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// --- ENDPOINT: Comprobar conexión WooCommerce ---
		register_rest_route(
			self::API_NAMESPACE,
			'/woocommerce/check',
			array(
				'methods'             => defined('WP_REST_Server') ? constant('WP_REST_Server')::READABLE : 'GET',
				'callback'            => array( $this, 'check_woocommerce_connection' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// --- Endpoints de sincronización de imágenes ---
		
		// Endpoint para sincronizar todas las imágenes de productos
		register_rest_route(
			self::API_NAMESPACE,
			'/sync/images',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'sync_all_product_images' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'force' => array(
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'filters' => array(
						'required'          => false,
						'type'              => 'object',
						'default'           => array(),
					),
				),
			)
		);


		// Endpoint para obtener estadísticas de sincronización de imágenes
		register_rest_route(
			self::API_NAMESPACE,
			'/sync/images/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_images_sync_stats' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Endpoint para obtener productos pendientes de sincronización de imágenes
		register_rest_route(
			self::API_NAMESPACE,
			'/sync/images/pending',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_pending_images_products' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'limit' => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
					'filters' => array(
						'required'          => false,
						'type'              => 'object',
						'default'           => array(),
					),
				),
			)
		);

		// Endpoint: GetDocumentosClienteWS
		if (class_exists('Verial\\Endpoints\\GetDocumentosClienteWS')) {
			\Verial\Endpoints\GetDocumentosClienteWS::register();
		}

		// Endpoint: GetPDFDocClienteWS
		if (class_exists('Verial\\Endpoints\\GetPDFDocClienteWS')) {
			\Verial\Endpoints\GetPDFDocClienteWS::register();
		}
	}

    /**
     * Verifica si el usuario actual tiene permisos de administrador
     *
     * Este método se utiliza como callback de verificación de permisos para los
     * endpoints que requieren privilegios de administrador.
     *
     * @return bool True si el usuario actual tiene capacidades de administrador, false en caso contrario
     * @since 1.0.0
     */
    public function check_admin_permissions() {
		return current_user_can( 'manage_options' );
	}

    /**
     * Obtiene el estado de autenticación del usuario actual
     *
     * Este endpoint devuelve información sobre el estado de autenticación del
     * usuario actual, incluyendo si está autenticado y sus capacidades.
     *
     * @param \WP_REST_Request $request Objeto de petición REST que contiene los parámetros
     * @return \WP_REST_Response Respuesta con el estado de autenticación
     *
     * @endpoint GET /auth/status
     * @permission public
     * @since 1.0.0
     */
    public function get_auth_status(\WP_REST_Request $request) {
		// Si tenemos Auth_Manager, usar sus métodos
		if ( class_exists( '\MiIntegracionApi\Core\Auth_Manager' ) ) {
			$auth_manager = \MiIntegracionApi\Core\Auth_Manager::get_instance();
			$credentials  = $auth_manager->get_credentials();

			return rest_ensure_response(
				array(
					'authenticated' => ! empty( $credentials['api_url'] ) && ! empty( $credentials['api_key'] ),
					'timestamp'     => time(),
				)
			);
		}

		// Verificar configuración centralizada
		$verial_config = \VerialApiConfig::getInstance();
		$has_url = !empty($verial_config->getVerialBaseUrl());
		
		return rest_ensure_response(
			array(
				'authenticated' => $has_url,
				'timestamp'     => time(),
			)
		);
	}

    /**
     * Obtiene el estado de conexión con Verial ERP
     *
     * Verifica el estado de la conexión con el servicio de Verial ERP y devuelve
     * información detallada sobre el estado actual de la conexión.
     *
     * @param \WP_REST_Request $request Objeto de petición REST
     * @return \WP_REST_Response Respuesta con el estado de la conexión
     *
     * @endpoint GET /connection/status
     * @permission public
     * @since 1.0.0
     */
    public function get_connection_status(\WP_REST_Request $request) {
		// Verificar si hay credenciales guardadas
		$auth_connected = false;
		if (class_exists('\MiIntegracionApi\Core\Auth_Manager')) {
			$auth_manager = \MiIntegracionApi\Core\Auth_Manager::get_instance();
			if (method_exists($auth_manager, 'has_credentials')) {
				$auth_connected = $auth_manager->has_credentials();
			} elseif (method_exists($auth_manager, 'get_credentials')) {
				$credentials = $auth_manager->get_credentials();
				$auth_connected = !empty($credentials['api_url']) && !empty($credentials['api_key']);
			}
		}

		// Si no pudimos verificar con Auth_Manager, intentar con Config_Manager
		if (!$auth_connected) {
			$verial_config = \VerialApiConfig::getInstance();
			$api_url = $verial_config->getVerialBaseUrl();
			$auth_connected = !empty($api_url);
		}

		// Obtener el último status de conexión guardado
		$status = get_option(
			'mi_integracion_api_connection_status',
			[
				'status' => 'unknown',
				'timestamp' => 0,
				'message' => 'No se ha probado la conexión',
			]
		);

		// Obtener datos de sincronización si están disponibles
		$sync_status = get_option('mi_integracion_api_sync_status', null);

		// Asegurarse de que sync_status tiene la estructura esperada por el frontend
		if (empty($sync_status)) {
			$sync_status = [
				'last_sync' => null,
				'current_sync' => [
					'in_progress' => false,
					'entity' => null,
					'direction' => null,
					'total_items' => 0,
					'processed_items' => 0,
					'started_at' => null,
				],
			];
		}

		// Para el endpoint público, sólo devolver información básica
		$is_admin = current_user_can('manage_options');

		return rest_ensure_response([
			'connected' => $auth_connected,
			'status' => $status,
			'sync_status' => $sync_status,
			'is_admin' => $is_admin,
		]);
	}

    /**
     * Prueba la conexión con Verial ERP
     *
     * Realiza una prueba de conexión con el servicio de Verial ERP utilizando
     * las credenciales proporcionadas o las almacenadas en la configuración.
     *
     * @param \WP_REST_Request $request {
     *     Parámetros de la petición:
     *     @type string $api_url  URL de la API (opcional)
     *     @type string $api_key  Clave de API (opcional)
     * }
     * @return \WP_REST_Response Resultado de la prueba de conexión
     *
     * @endpoint POST /connection/test
     * @permission manage_options
     * @since 1.0.0
     */
    public function test_connection(\WP_REST_Request $request) {
		try {
			// Obtener configuración desde Config_Manager
		$verial_config = \VerialApiConfig::getInstance();
		$config = [
			'api_url' => $verial_config->getVerialBaseUrl(),
			'sesionwcf' => $verial_config->getVerialSessionId()
		];

			// Instanciar ApiConnector con la configuración
			$api = new \MiIntegracionApi\Core\ApiConnector($config);
			$test_result = $api->test_connection();

			if (is_wp_error($test_result)) {
				$status = [
					'status' => 'error',
					'timestamp' => time(),
					'message' => $test_result->get_error_message(),
				];
			} else {
				$status = [
					'status' => 'success',
					'timestamp' => time(),
					'message' => 'Conexión establecida correctamente',
					'data' => $test_result,
				];
			}

			// Guardar el estado para futuras referencias
			update_option('mi_integracion_api_connection_status', $status);

			return rest_ensure_response($status);
		} catch (\Exception $e) {
			$status = [
				'status' => 'error',
				'timestamp' => time(),
				'message' => $e->getMessage(),
			];

			// Guardar el estado para futuras referencias
			update_option('mi_integracion_api_connection_status', $status);

			return rest_ensure_response($status);
		}
	}

    /**
     * Obtiene la configuración actual del plugin
     *
     * Devuelve la configuración actual del plugin, incluyendo las credenciales
     * de la API, opciones de depuración y otras configuraciones relevantes.
     * Las credenciales sensibles se filtran antes de ser devueltas.
     *
     * @param \WP_REST_Request $request Objeto de petición REST
     * @return \WP_REST_Response Configuración actual del plugin
     *
     * @endpoint GET /settings
     * @permission manage_options
     * @since 1.0.0
     */
    public function get_settings(\WP_REST_Request $request) {
		// Usar Config_Manager para obtener la configuración
		$verial_config = \VerialApiConfig::getInstance();
		
		$api_url = $verial_config->getVerialBaseUrl();
		$api_key = $verial_config->getVerialSessionId(); // Usar sesión como clave
		$debug_mode = false; // Simplificado

		// Maskear la clave API por seguridad
		if (!empty($api_key)) {
			$api_key = '••••••••' . substr($api_key, -4);
		}

		return rest_ensure_response([
			'api_url' => $api_url,
			'api_key' => $api_key,
			'debug_mode' => $debug_mode === 'yes',
			'notification_settings' => [
				'enableToasts' => true,
				'enableSoundEffects' => false,
				'autoDismiss' => true,
				'autoDismissTimeout' => 5000,
				'position' => 'top-right',
			],
		]);
	}

    /**
     * Actualiza la configuración del plugin
     *
     * Permite actualizar la configuración del plugin, incluyendo credenciales
     * de la API y opciones de funcionamiento. Valida los datos antes de guardarlos.
     *
     * @param \WP_REST_Request $request {
     *     Parámetros configurables:
     *     @type string $api_url     URL de la API de Verial ERP
     *     @type string $api_key     Clave de API de Verial ERP
     *     @type bool   $debug_mode  Modo de depuración activado/desactivado
     *     @type array  $settings    Configuraciones adicionales
     * }
     * @return \WP_REST_Response Resultado de la operación
     *
     * @endpoint POST /settings
     * @permission manage_options
     * @since 1.0.0
     */
    public function update_settings(\WP_REST_Request $request) {
		// Usar Config_Manager para actualizar la configuración
		$config_manager = \MiIntegracionApi\Core\Config_Manager::get_instance();
		$params = $request->get_params();

		// Actualizar configuraciones (nota: VerialApiConfig es de solo lectura)
		// Las configuraciones se actualizan a través de WordPress options
		if (isset($params['api_url'])) {
			$options = get_option('mi_integracion_api_ajustes', []);
			$options['mia_url_base'] = $params['api_url'];
			update_option('mi_integracion_api_ajustes', $options);
		}
		if (isset($params['api_key'])) {
			$config_manager->update('mia_clave_api', $params['api_key']);
		}
		if (isset($params['debug_mode'])) {
			$config_manager->update('mia_debug_mode', $params['debug_mode'] ? 'yes' : 'no');
		}

		// Limpiar cualquier caché de autenticación
		if (class_exists('\MiIntegracionApi\Core\Auth_Manager')) {
			$auth_manager = \MiIntegracionApi\Core\Auth_Manager::get_instance();
			if (method_exists($auth_manager, 'refresh_credentials')) {
				$auth_manager->refresh_credentials();
			}
		}

		return rest_ensure_response([
			'success' => true,
			'message' => 'Configuración actualizada correctamente',
		]);
	}

	/**
	 * Callback para comprobar la conexión con Verial
	 */
	public function check_verial_connection( $request ) {
		// Obtener configuración centralizada
		$verial_config = \VerialApiConfig::getInstance();
		$api_url = $verial_config->getVerialBaseUrl();
		$sesionwcf = $verial_config->getVerialSessionId();
		$logger = class_exists('MiIntegracionApi\\Helpers\\Logger') ? new \MiIntegracionApi\Helpers\Logger('api_connector') : null;
		if ($logger) {
			$logger->info('[REST][verial/check] Intentando prueba de conexión', [
				'api_url' => $api_url,
				'sesionwcf' => $sesionwcf
			]);
		}
		if (empty($api_url)) {
			if ($logger) {
				$logger->error('[REST][verial/check] URL base de la API de Verial no configurada');
			}
			return rest_ensure_response([
				'success' => false,
				'message' => 'No se ha configurado la URL base de la API de Verial.',
				'api_url' => $api_url,
				'step' => 'config',
			]);
		}
		$config = [
			'api_url' => $api_url,
			'sesionwcf' => $sesionwcf
		];
		$api = new \MiIntegracionApi\Core\ApiConnector($config);
		$result = $api->test_connectivity();
		$last_url = method_exists($api, 'get_last_request_url') ? $api->get_last_request_url() : null;
		if ($result === true) {
			if ($logger) {
				$logger->info('[REST][verial/check] Conexión exitosa', ['url' => $last_url]);
			}
			return rest_ensure_response([
				'success' => true,
				'message' => 'Conexión exitosa con Verial.',
				'url' => $last_url,
			]);
		} else {
			if ($logger) {
				$logger->error('[REST][verial/check] Error de conexión', ['url' => $last_url, 'error' => $result]);
			}
			return rest_ensure_response([
				'success' => false,
				'message' => 'Error de conexión: ' . $result,
				'url' => $last_url,
			]);
		}
	}

	/**
	 * Callback para comprobar la conexión con WooCommerce
	 */
	public function check_woocommerce_connection( $request ) {
		if (!class_exists('WooCommerce')) {
			return new \WP_REST_Response([
				'success' => false,
				'message' => __( 'WooCommerce no está activo.', 'mi-integracion-api' )
			], 500);
		}
		// Prueba básica: obtener número de productos
		try {
			$count = \wc_get_product_count();
			return [
				'success' => true,
				'message' => sprintf( __( 'WooCommerce activo. Productos encontrados: %d', 'mi-integracion-api' ), $count )
			];
		} catch (\Throwable $e) {
			return new \WP_REST_Response([
				'success' => false,
				'message' => __( 'Error al consultar WooCommerce: ', 'mi-integracion-api' ) . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Sincroniza todas las imágenes de productos
	 * 
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function sync_all_product_images( $request ) {
		try {
			$force = $request->get_param( 'force' );
			$filters = $request->get_param( 'filters' ) ?: array();
			
			$coreRouting = defined('MIA_USE_CORE_SYNC') ? (bool) constant('MIA_USE_CORE_SYNC') : (bool) get_option('mia_use_core_sync', true);
			if (function_exists('apply_filters')) { $coreRouting = (bool) apply_filters('mia_use_core_sync_routing', $coreRouting); }
			if ($coreRouting && class_exists('MiIntegracionApi\\Core\\Sync_Manager')) {
				$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
				// TODO(F5-Imágenes): mover sync_product_images a adaptador desacoplado del legacy
			} else {
				// Fallback para compatibilidad - usar Core en lugar de legacy
				$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
			}
			$result = $sync_manager->sync_product_images( $filters, $force );
			
			if ( $result['status'] === 'success' ) {
				return new \WP_REST_Response( $result, 200 );
			} else {
				return new \WP_REST_Response( $result, 500 );
			}
		} catch ( \Exception $e ) {
			return new \WP_REST_Response( array(
				'status'  => 'error',
				'message' => $e->getMessage()
			), 500 );
		}
	}


	/**
	 * Obtiene estadísticas de sincronización de imágenes
	 * 
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_images_sync_stats( $request ) {
		try {
			$stats = array(
				'total_synced' => get_option( 'mia_total_images_synced', 0 ),
				'total_errors' => get_option( 'mia_total_images_errors', 0 ),
				'last_sync_time' => get_option( 'mia_last_images_sync_time', '' ),
				'avg_sync_time' => get_option( 'mia_avg_images_sync_time', 0 ),
				'last_metrics' => get_option( 'mia_last_images_sync_metrics', array() )
			);
			
			return new \WP_REST_Response( array(
				'status' => 'success',
				'data'   => $stats
			), 200 );
		} catch ( \Exception $e ) {
			return new \WP_REST_Response( array(
				'status'  => 'error',
				'message' => $e->getMessage()
			), 500 );
		}
	}

	/**
	 * Obtiene productos pendientes de sincronización de imágenes
	 * 
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_pending_images_products( $request ) {
		try {
			$limit = $request->get_param( 'limit' );
			$filters = $request->get_param( 'filters' ) ?: array();
			
			// Agregar límite a los filtros
			$filters['limite'] = $limit;
			
			$coreRouting = defined('MIA_USE_CORE_SYNC') ? (bool) constant('MIA_USE_CORE_SYNC') : (bool) get_option('mia_use_core_sync', true);
			if (function_exists('apply_filters')) { $coreRouting = (bool) apply_filters('mia_use_core_sync_routing', $coreRouting); }
			if ($coreRouting && class_exists('MiIntegracionApi\\Core\\Sync_Manager')) {
				$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
			} else {
				// Fallback para compatibilidad - usar Core en lugar de legacy
				$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
			}
			
			// COMENTADO: Las imágenes se procesan automáticamente en BatchProcessor
			// No se requiere consulta separada de productos pendientes
			$productos = array();
			
			return new \WP_REST_Response( array(
				'status' => 'success',
				'data'   => array(
					'productos' => $productos,
					'total'     => count( $productos ),
					'filters'   => $filters
				)
			), 200 );
		} catch ( \Exception $e ) {
			return new \WP_REST_Response( array(
				'status'  => 'error',
				'message' => $e->getMessage()
			), 500 );
		}
	}

    /**
     * Obtiene la lista de endpoints disponibles en el sistema
     *
     * Devuelve un listado de todos los endpoints REST disponibles en el sistema,
     * incluyendo sus métodos HTTP soportados, parámetros requeridos y permisos.
     * Útil para la documentación automática y descubrimiento de la API.
     *
     * @param \WP_REST_Request $request Objeto de petición REST
     * @return \WP_REST_Response Lista de endpoints disponibles
     *
     * @endpoint GET /endpoints/available
     * @permission manage_options
     * @since 1.0.0
     */
    public function get_available_endpoints(\WP_REST_Request $request) {
		try {
			$sync_manager = Sync_Manager::get_instance();
			$endpoints = $sync_manager->get_available_endpoints();

			return new \WP_REST_Response( array(
				'status' => 'success',
				'data' => $endpoints,
				'message' => sprintf(
					__( 'Se encontraron %d endpoints disponibles (%s%% cobertura)', 'mi-integracion-api' ),
					$endpoints['stats']['total_endpoints'],
					$endpoints['stats']['coverage_percentage']
				)
			), 200 );

		} catch ( \Exception $e ) {
			return new \WP_REST_Response( array(
				'status' => 'error',
				'message' => $e->getMessage()
			), 500 );
		}
	}
}
