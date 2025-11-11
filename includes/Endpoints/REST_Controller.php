<?php declare(strict_types=1);
/**
 * Registro de rutas de la API REST de WordPress
 *
 * @package MiIntegracionApi\Endpoints
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente
}

/**
 * Clase para gestionar los callbacks y permisos de las rutas de la API REST
 * Nota: El registro de rutas se ha centralizado en REST_API_Handler.php
 */
class REST_Controller {

	/**
	 * Namespace para las rutas de la API
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'mi-integracion-api/v1';

	/**
	 * Inicializa el controlador REST
	 * Nota: Ya no registra rutas directamente, esto lo hace REST_API_Handler
	 */
	public static function init() {
		// El registro de rutas ahora está centralizado en REST_API_Handler
	}

	/**
	 * Este método ya no registra rutas directamente
	 * Se mantiene por compatibilidad, pero no realiza ninguna acción
	 *
	 * @deprecated Las rutas ahora se registran en REST_API_Handler
	 */
	public static function register_routes() {
		// Las rutas ahora se registran en REST_API_Handler
		// Este método se mantiene por compatibilidad
		return;
	}

	/**
	 * Verifica los permisos de administración
	 *
	 * @return bool Verdadero si el usuario tiene permisos
	 */
	public static function check_admin_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Verifica los permisos de autenticación (usuario y contraseña)
	 *
	 * @return bool True si el usuario tiene permisos
	 */
	public static function check_auth_permissions() {
		// Esta función permite el acceso al endpoint de autenticación
		// El endpoint validará las credenciales internamente
		return true;
	}

	/**
	 * Verifica los permisos mediante token JWT o permisos de administrador
	 *
	 * @param \WP_REST_Request $request Solicitud REST
	 * @return bool True si el usuario tiene permisos
	 */
	public static function check_auth_or_admin_permissions( $request ) {
		// Verificar si es administrador
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Verificar token JWT
		$auth_header = $request->get_header( 'Authorization' );
		if ( $auth_header && preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
			$jwt         = $matches[1];
			$jwt_manager = new \MiIntegracionApi\Core\JWT_Manager();
			$decoded     = $jwt_manager->validate_token( $jwt );
			return $decoded !== false;
		}

		return false;
	}

	/**
	 * Verifica los permisos mediante una función de callback personalizada
	 *
	 * @param callable $callback Función de verificación
	 * @return callable Función que verifica permisos
	 */
	public static function check_with_callback( $callback ) {
		return function ( $request ) use ( $callback ) {
			return call_user_func( $callback, $request );
		};
	}

	/**
	 * Obtiene las credenciales de Verial (protegidas)
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function get_credentials( \WP_REST_Request $request ) {
		$auth_manager = \MiIntegracionApi\Core\Auth_Manager::get_instance();
		$credentials  = $auth_manager->get_credentials();

		if ( ! $credentials ) {
			return API_Response_Handler::error(
				'no_credentials',
				__('No hay credenciales guardadas.', 'mi-integracion-api'),
				[],
				404
			);
		}

		// Nunca devolver la contraseña por seguridad
		if ( isset( $credentials['password'] ) ) {
			$credentials['password'] = '';
		}

		return API_Response_Handler::success($credentials);
	}

	/**
	 * Guarda las credenciales de Verial
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function save_credentials( \WP_REST_Request $request ) {
		$params = $request->get_params();

		// Validar y sanitizar credenciales
		$validation_result = Credentials_Validator::validate_and_sanitize($params);
		
		if (!empty($validation_result['errors'])) {
			return API_Response_Handler::validation_error($validation_result['errors']);
		}

		// Obtener credenciales actuales para conservar la contraseña si no se proporciona una nueva
		$auth_manager = \MiIntegracionApi\Core\Auth_Manager::get_instance();
		$current_credentials = $auth_manager->get_credentials();

		$new_credentials = $validation_result['credentials'];

		// Si no se proporciona una nueva contraseña, mantener la actual
		if (!isset($new_credentials['password']) || empty($new_credentials['password'])) {
			$new_credentials['password'] = $current_credentials['password'] ?? '';
		}

		// Guardar credenciales
		$result = $auth_manager->save_credentials($new_credentials);

		if (!$result) {
			return API_Response_Handler::error(
				'save_failed',
				__('No se pudieron guardar las credenciales.', 'mi-integracion-api'),
				[],
				500
			);
		}

		return API_Response_Handler::success(null, [
			'message' => __('Credenciales guardadas correctamente.', 'mi-integracion-api')
		]);
	}

	/**
	 * Prueba la conexión con Verial ERP
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function test_connection( \WP_REST_Request $request ) {
		$params = $request->get_params();

		try {
			$api_connector = function_exists('mi_integracion_api_get_connector')
				? \MiIntegracionApi\Helpers\ApiHelpers::get_connector()
				: new \MiIntegracionApi\Core\ApiConnector();

			// Si se proporcionan credenciales temporales, usarlas para la prueba
			if (isset($params['api_url']) && isset($params['username'])) {
				$temp_credentials = [
					'api_url' => sanitize_text_field($params['api_url']),
					'username' => sanitize_text_field($params['username']),
					'password' => $params['password'] ?? '',
				];
				$api_connector->set_credentials($temp_credentials);
			}

			// Verificar que hay credenciales configuradas
			if (!$api_connector->has_valid_credentials()) {
				$response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					__('No hay credenciales válidas configuradas.', 'mi-integracion-api'),
					400,
					['endpoint' => 'test_connection']
				);
				return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($response);
			}

			// Realizar la prueba de conexión
			$result = $api_connector->test_connection();

			if ($result['success']) {
				$response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
					$result['data'] ?? [],
					$result['message'] ?? __('Conexión exitosa con Verial ERP', 'mi-integracion-api'),
					['endpoint' => 'test_connection', 'timestamp' => time()]
				);
				return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($response);
			} else {
				$response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					$result['message'] ?? __('Error al conectar con Verial ERP', 'mi-integracion-api'),
					500,
					['endpoint' => 'test_connection', 'connection_data' => $result['data'] ?? []]
				);
				return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($response);
			}
		} catch (\Exception $e) {
			$response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				$e->getMessage(),
				500,
				['endpoint' => 'test_connection', 'exception' => $e->getTraceAsString()]
			);
			return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($response);
		}
	}

	/**
	 * Obtiene el estado actual de la conexión
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function get_connection_status( \WP_REST_Request $request ) {
		$auth_manager = \MiIntegracionApi\Core\Auth_Manager::get_instance();

		return new \WP_REST_Response(
			array(
				'connected' => $auth_manager->has_credentials(),
			)
		);
	}

	/**
	 * Inicia un proceso de sincronización
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function start_sync( \WP_REST_Request $request ) {
		$params = $request->get_params();

		// Validar parámetros usando SyncEntityValidator unificado
		try {
			$validator = new \MiIntegracionApi\Core\Validation\SyncEntityValidator();
			$validator->validate([
				'entity' => $params['entity'] ?? '',
				'direction' => $params['direction'] ?? '',
				'filters' => $params['filters'] ?? []
			]);
		} catch ( \MiIntegracionApi\ErrorHandling\Exceptions\SyncError $e ) {
			$response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				$e->getMessage(),
				400,
				['endpoint' => 'start_sync', 'validation_error' => $e->getContext()]
			);
			return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($response);
		}

		$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();

		// Iniciar sincronización
		$result = $sync_manager->start_sync(
			$params['entity'],
			$params['direction'],
			isset( $params['filters'] ) ? $params['filters'] : array()
		);

		// Convertir SyncResponse a WP_REST_Response usando WordPressAdapter
		return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($result);
	}

	/**
	 * Procesa el siguiente lote de sincronización
	 *
	 * @deprecated Este endpoint ya no se usa - el backend procesa automáticamente todos los lotes
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function process_next_batch( \WP_REST_Request $request ) {
		// DEPRECATED: El backend ahora procesa automáticamente todos los lotes en background
		// Este endpoint se mantiene por compatibilidad pero no debe ser usado
		$response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
			'Este endpoint está deprecated - el backend procesa automáticamente todos los lotes',
			410,
			['endpoint' => 'process_next_batch', 'deprecated' => true]
		);
		return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($response);
	}

	/**
	 * Cancela la sincronización actual
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function cancel_sync( \WP_REST_Request $request ) {
		$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();

		$result = $sync_manager->cancel_sync();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Sincronización cancelada.', 'mi-integracion-api' ),
				'data'    => $result,
			)
		);
	}

	/**
	 * Reanuda una sincronización interrumpida
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function resume_sync( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		$params = $request->get_params();
		$offset = isset($params['offset']) ? intval($params['offset']) : null;
		$batch_size = isset($params['batch_size']) ? intval($params['batch_size']) : null;
		$entity = isset($params['entity']) ? sanitize_text_field($params['entity']) : null;

		$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
		$result = $sync_manager->resume_sync($offset, $batch_size, $entity);

		// Convertir SyncResponseInterface a WP_REST_Response usando WordPressAdapter
		return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($result);
	}

	/**
	 * Obtiene el estado actual de sincronización
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function getSyncStatus( \WP_REST_Request $request ): \WP_REST_Response {
		$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
		$status = $sync_manager->getSyncStatus();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'status'  => $status,
			)
		);
	}

	/**
	 * Obtiene el historial de sincronizaciones
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function get_sync_history( \WP_REST_Request $request ): \WP_REST_Response {
		$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
		$history = $sync_manager->get_sync_history();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $history,
			)
		);
	}

	/**
	 * Inicia un reintento de sincronización para un conjunto de errores.
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST.
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error.
	 */
	public static function retry_sync_errors( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		$params = $request->get_params();
		$error_ids = $params['error_ids'] ?? [];

		if ( empty( $error_ids ) || ! is_array( $error_ids ) ) {
			$response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__( 'No se especificaron errores para reintentar.', 'mi-integracion-api' ),
				400,
				['endpoint' => 'retry_sync_errors', 'error_ids' => $error_ids]
			);
			return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($response);
		}

		$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
		$result = $sync_manager->retry_sync_errors( array_map( 'intval', $error_ids ) );

		// Convertir SyncResponseInterface a WP_REST_Response usando WordPressAdapter
		return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($result);
	}

	/**
	 * Realiza una prueba de API con Verial
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function test_api( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		try {
			$response = wp_remote_get('https://api.example.com/test');
			
			if (is_wp_error($response)) {
				$error_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					$response->get_error_message(),
					500,
					['endpoint' => 'test_api', 'wp_error_code' => $response->get_error_code()]
				);
				return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($error_response);
			}

			$status_code = wp_remote_retrieve_response_code($response);
			if ($status_code !== 200) {
				$error_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					__('Error al conectar con el servidor.', 'mi-integracion-api'),
					$status_code,
					['endpoint' => 'test_api', 'status_code' => $status_code]
				);
				return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($error_response);
			}

			$body = wp_remote_retrieve_body($response);
			if (empty($body)) {
				$error_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					__('Respuesta vacía del servidor.', 'mi-integracion-api'),
					500,
					['endpoint' => 'test_api', 'body_length' => strlen($body)]
				);
				return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($error_response);
			}

			$data = json_decode($body, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				$error_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					__('Respuesta incorrecta del servidor. No es un formato JSON válido.', 'mi-integracion-api'),
					500,
					['endpoint' => 'test_api', 'json_error' => json_last_error_msg()]
				);
				return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($error_response);
			}

			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => __('Conexión exitosa con Verial ERP.', 'mi-integracion-api'),
					'data'    => $data,
				)
			);
		} catch (\Exception $e) {
			$error_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				$e->getMessage(),
				500,
				['endpoint' => 'test_api', 'exception' => $e->getTraceAsString()]
			);
			return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($error_response);
		}
	}

	/**
	 * Genera un token JWT para autenticación
	 *
	 * @param \WP_REST_Request $request Solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function generate_token( \WP_REST_Request $request ) {
		$params   = $request->get_params();
		$username = $params['username'] ?? '';
		$password = $params['password'] ?? '';

		// Autenticar con WordPress
		$user = wp_authenticate( $username, $password );

		if ( is_wp_error( $user ) ) {
			$error_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__( 'Credenciales inválidas', 'mi-integracion-api' ),
				401,
				['endpoint' => 'generate_token', 'wp_error_code' => $user->get_error_code()]
			);
			return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($error_response);
		}

		// Verificar capacidades (mínimo debe ser autor)
		if ( ! user_can( $user->ID, 'edit_posts' ) && ! user_can( $user->ID, 'manage_woocommerce' ) ) {
			$error_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__( 'Este usuario no tiene permisos suficientes para usar la API', 'mi-integracion-api' ),
				403,
				['endpoint' => 'generate_token', 'user_id' => $user->ID]
			);
			return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($error_response);
		}

		// Generar token JWT
		$jwt_manager = new \MiIntegracionApi\Core\JWT_Manager();

		$token = $jwt_manager->generate_token(
			$user->ID,
			array(
				'username'     => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
				'roles'        => $user->roles,
			)
		);

		if ( ! $token ) {
			$error_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__( 'Error al generar el token de autenticación', 'mi-integracion-api' ),
				500,
				['endpoint' => 'generate_token', 'user_id' => $user->ID]
			);
			return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($error_response);
		}

		return new \WP_REST_Response(
			array(
				'success'           => true,
				'token'             => $token,
				'user_id'           => $user->ID,
				'user_display_name' => $user->display_name,
				'user_email'        => $user->user_email,
			)
		);
	}

	/**
	 * Valida un token JWT
	 *
	 * @param \WP_REST_Request $request Solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function validate_token( \WP_REST_Request $request ) {
		$params = $request->get_params();
		$token  = $params['token'] ?? '';

		if ( empty( $token ) ) {
			// Intentar obtener el token del encabezado Authorization
			$auth_header = $request->get_header( 'Authorization' );
			if ( $auth_header && preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
				$token = $matches[1];
			}
		}

		if ( empty( $token ) ) {
			$error_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__( 'Token no proporcionado', 'mi-integracion-api' ),
				400,
				['endpoint' => 'validate_token']
			);
			return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($error_response);
		}

		$jwt_manager = new \MiIntegracionApi\Core\JWT_Manager();
		$decoded     = $jwt_manager->validate_token( $token );

		if ( ! $decoded ) {
			$error_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__( 'Token inválido o expirado', 'mi-integracion-api' ),
				401,
				['endpoint' => 'validate_token']
			);
			return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($error_response);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'valid'   => true,
				'user_id' => $decoded->data->user_id,
				'expires' => $decoded->exp,
			)
		);
	}

	/**
	 * Renueva un token JWT
	 *
	 * @param \WP_REST_Request $request Solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function refresh_token( \WP_REST_Request $request ) {
		$params = $request->get_params();
		$token  = $params['token'] ?? '';

		if ( empty( $token ) ) {
			// Intentar obtener el token del encabezado Authorization
			$auth_header = $request->get_header( 'Authorization' );
			if ( $auth_header && preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
				$token = $matches[1];
			}
		}

		if ( empty( $token ) ) {
			$error_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__( 'Token no proporcionado', 'mi-integracion-api' ),
				400,
				['endpoint' => 'refresh_token']
			);
			return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($error_response);
		}

		$jwt_manager = new \MiIntegracionApi\Core\JWT_Manager();
		$new_token   = $jwt_manager->renew_token( $token );

		if ( ! $new_token ) {
			$error_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__( 'Error al renovar el token', 'mi-integracion-api' ),
				401,
				['endpoint' => 'refresh_token']
			);
			return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($error_response);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'token'   => $new_token,
			)
		);
	}

	/**
	 * Revoca un token JWT
	 *
	 * @param \WP_REST_Request $request Solicitud REST
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Respuesta o error
	 */
	public static function revoke_token( \WP_REST_Request $request ) {
		$params = $request->get_params();
		$token  = $params['token'] ?? '';

		if ( empty( $token ) ) {
			// Intentar obtener el token del encabezado Authorization
			$auth_header = $request->get_header( 'Authorization' );
			if ( $auth_header && preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
				$token = $matches[1];
			}
		}

		if ( empty( $token ) ) {
			$error_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__( 'Token no proporcionado', 'mi-integracion-api' ),
				400,
				['endpoint' => 'revoke_token']
			);
			return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($error_response);
		}

		$jwt_manager = new \MiIntegracionApi\Core\JWT_Manager();
		$success     = $jwt_manager->revoke_token( $token );

		if ( ! $success ) {
			$error_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__( 'Error al revocar el token', 'mi_integracion_api' ),
				400,
				['endpoint' => 'revoke_token']
			);
			return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($error_response);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Token revocado correctamente', 'mi_integracion_api' ),
			)
		);
	}

	/**
	 * Devuelve una respuesta estándar de éxito para endpoints REST estáticos.
	 *
	 * @param array|mixed $data Datos principales a devolver (array, objeto, etc.)
	 * @param array $extra (opcional) Datos extra a incluir en la respuesta raíz
	 * @return array Respuesta estándar: ['success' => true, 'data' => $data, ...$extra]
	 */
	private static function format_success_response($data = null, array $extra = []) {
		return array_merge([
			'success' => true,
			'data'    => $data,
		], $extra);
	}
}
