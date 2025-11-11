<?php declare(strict_types=1);
/**
 * Helper para autenticación JWT en endpoints REST
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.0.0
 */

namespace MiIntegracionApi\Helpers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface;
use MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente
}

/**
 * Clase auxiliar para autenticar endpoints de API mediante JWT.
 *
 * Proporciona métodos para verificar la validez de tokens JWT en solicitudes REST,
 * validar roles/capacidades de usuario y generar callbacks de autenticación para endpoints.
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.0.0
 */
class JwtAuthHelper {
	 /**
		* Verifica si una solicitud REST contiene un JWT válido en el encabezado Authorization.
		*
		* Si el token es válido, almacena el usuario y los datos decodificados en la solicitud.
		*
		* @param \WP_REST_Request $request Objeto de solicitud REST.
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface True si el token es válido, SyncResponseInterface si no lo es.
		*/
	 public static function verify_jwt_auth( $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Obtener token del encabezado Authorization
		$auth_header = $request->get_header( 'Authorization' );

		if ( ! $auth_header || ! preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
			return ResponseFactory::error(
				__( 'No se proporcionó un token de autenticación', 'mi-integracion-api' ),
				401,
				['endpoint' => 'verify_jwt_auth', 'error_type' => 'jwt_auth_no_token']
			);
		}

		$token = $matches[1];

		// Validar el token
		try {
			$jwt_manager = new \MiIntegracionApi\Core\JWT_Manager();
			$decoded     = $jwt_manager->validate_token( $token );

			if ( ! $decoded ) {
				return ResponseFactory::error(
					__( 'Token de autenticación inválido', 'mi-integracion-api' ),
					401,
					['endpoint' => 'verify_jwt_auth', 'error_type' => 'jwt_auth_invalid_token']
				);
			}

			// Verificar si el token está revocado
			if ( $jwt_manager->is_token_revoked( $token ) ) {
				return ResponseFactory::error(
					__( 'Token de autenticación revocado', 'mi-integracion-api' ),
					401,
					['endpoint' => 'verify_jwt_auth', 'error_type' => 'jwt_auth_revoked_token']
				);
			}

			// Obtener el usuario asociado al token
			$user_id = $decoded->data->user_id;
			$user    = get_user_by( 'id', $user_id );

			if ( ! $user ) {
				return ResponseFactory::error(
					__( 'El usuario asociado al token no existe', 'mi-integracion-api' ),
					401,
					['endpoint' => 'verify_jwt_auth', 'error_type' => 'jwt_auth_user_not_found']
				);
			}

			// Almacenar el usuario y los datos del token para usarlos posteriormente
			$request->set_param( 'jwt_user', $user );
			$request->set_param( 'jwt_decoded', $decoded );

			// JWT válido
			return true;

		} catch ( ExpiredException $e ) {
			return ResponseFactory::error(
				__( 'Token de autenticación expirado', 'mi-integracion-api' ),
				401,
				['endpoint' => 'verify_jwt_auth', 'error_type' => 'jwt_auth_token_expired']
			);
		} catch ( SignatureInvalidException $e ) {
			return ResponseFactory::error(
				__( 'Firma del token inválida', 'mi-integracion-api' ),
				401,
				['endpoint' => 'verify_jwt_auth', 'error_type' => 'jwt_auth_invalid_signature']
			);
		} catch ( \Exception $e ) {
			return ResponseFactory::error(
				__( 'Error de autenticación: ', 'mi-integracion-api' ) . $e->getMessage(),
				401,
				['endpoint' => 'verify_jwt_auth', 'error_type' => 'jwt_auth_unknown_error', 'exception' => $e->getMessage()]
			);
		}
	}

		/**
		 * Valida que el usuario tenga al menos uno de los roles o capacidades especificados.
		 *
		 * @param \WP_User     $user           Usuario a validar.
		 * @param string|array $roles_or_caps  Rol(es) o capacidad(es) a verificar.
		 * @return bool True si el usuario tiene al menos uno de los roles/capacidades, false en caso contrario.
		 */
		public static function has_role_or_capability( $user, $roles_or_caps ) {
		if ( ! $user || ! ( $user instanceof \WP_User ) ) {
			return false;
		}

		if ( is_string( $roles_or_caps ) ) {
			$roles_or_caps = array( $roles_or_caps );
		}

		foreach ( $roles_or_caps as $role_or_cap ) {
			if ( $user->has_cap( $role_or_cap ) || in_array( $role_or_cap, $user->roles ) ) {
				return true;
			}
		}

		return false;
	}

		/**
		 * Genera un callback para verificar JWT y capacidades requeridas en endpoints REST.
		 *
		 * El callback valida el JWT y, si se especifican, verifica que el usuario tenga las capacidades requeridas.
		 *
		 * @param array $required_capabilities Capacidades o roles requeridos (opcional).
		 * @return callable Callback de verificación para usar en endpoints REST.
		 */
		public static function get_jwt_auth_callback( $required_capabilities = array() ) {
		return function ( $request ) use ( $required_capabilities ) {
			// Verificar JWT
			$jwt_result = self::verify_jwt_auth( $request );

			if ( $jwt_result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$jwt_result->isSuccess() ) {
				return $jwt_result;
			}

			// Si se requieren capacidades específicas, verificarlas
			if ( ! empty( $required_capabilities ) ) {
				$user = $request->get_param( 'jwt_user' );

				if ( ! self::has_role_or_capability( $user, $required_capabilities ) ) {
					return ResponseFactory::error(
						__( 'El usuario no tiene los permisos necesarios', 'mi-integracion-api' ),
						403,
						['endpoint' => 'jwt_auth_callback', 'error_type' => 'jwt_auth_insufficient_permissions', 'required_capabilities' => $required_capabilities]
					);
				}
			}

			return true;
		};
	}
}
