<?php declare(strict_types=1);
namespace MiIntegracionApi\Helpers;

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Core\Auth_Manager;
use MiIntegracionApi\Core\CryptoService;

/**
 * Clase de utilidades para acceso y gestión de servicios principales de la integración.
 *
 * Proporciona métodos estáticos para obtener instancias de conectores, gestores de configuración,
 * autenticación, criptografía y para registrar logs de error o información.
 *
 * @package MiIntegracionApi\Helpers
 */
class ApiHelpers {
		/**
		 * Obtiene una instancia centralizada de ApiConnector usando el patrón Singleton.
		 * Aplica optimización de memoria evitando instanciaciones duplicadas.
		 *
		 * @return ApiConnector|null Instancia centralizada de ApiConnector o null si falla la creación.
		 * @throws \Exception Si hay errores de configuración (capturados internamente).
		 */
		public static function get_connector(): ?ApiConnector {
			// Usar el plugin principal para obtener ApiConnector centralizado
			if (class_exists('\MiIntegracionApi\Core\MiIntegracionApi')) {
				try {
					$mainPlugin = new \MiIntegracionApi\Core\MiIntegracionApi();
					if ($mainPlugin) {
						$centralizedConnector = $mainPlugin->getComponent('api_connector');
						if ($centralizedConnector) {
							return $centralizedConnector;
						}
					}
				} catch (\Exception $e) {
					// Log del error pero continuar con fallback
					if (class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
						$error_logger = new \MiIntegracionApi\Helpers\Logger('apihelpers-error');
						$error_logger->error('Error obteniendo ApiConnector centralizado: ' . $e->getMessage());
					}
				}
			}
			
			// Fallback: usar singleton solo si no hay plugin principal
			if (class_exists(ApiConnector::class)) {
				try {
					$logger = new \MiIntegracionApi\Helpers\Logger('api-helpers');
					return ApiConnector::get_instance($logger);
				} catch (\Exception $e) {
					// En caso de error, log y retornar null
					if (class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
						$error_logger = new \MiIntegracionApi\Helpers\Logger('apihelpers-error');
						$error_logger->error('Error al obtener ApiConnector singleton: ' . $e->getMessage());
					}
					return null;
				}
			}
			
			return null;
		}

		/**
		 * Obtiene una instancia singleton del gestor de configuración del plugin.
		 *
		 * @return \MI_Settings_Manager|null Instancia del gestor de configuración o null si no existe.
		 */
		public static function get_settings(): ?\MI_Settings_Manager {
		if ( class_exists( 'MI_Settings_Manager' ) ) {
			return \MI_Settings_Manager::get_instance();
		}
		return null;
	}

		/**
		 * Obtiene una instancia singleton del gestor de autenticación.
		 *
		 * @return Auth_Manager|null Instancia de Auth_Manager o null si no existe.
		 */
		public static function get_auth_manager(): ?Auth_Manager {
		if ( class_exists( Auth_Manager::class ) ) {
			return Auth_Manager::get_instance();
		}
		return null;
	}

		/**
		 * Registra un mensaje de error en el log del plugin.
		 *
		 * @param string $message Mensaje de error a registrar.
		 * @param string $context Contexto o categoría del error (opcional).
		 * @return void
		 */
		public static function log_error( string $message, string $context = 'general' ): void {
		if ( class_exists( Logger::class ) ) {
			(new Logger)->error( $message, (array)$context);
		}
	}

		/**
		 * Registra un mensaje informativo en el log del plugin.
		 *
		 * @param string $message Mensaje informativo a registrar.
		 * @param string $context Contexto o categoría de la información (opcional).
		 * @return void
		 */
		public static function log_info( string $message, string $context = 'general' ): void {
		if ( class_exists( Logger::class ) ) {
			(new Logger)->info( $message, (array)$context);
		}
	}

		/**
		 * Obtiene una instancia singleton del servicio de criptografía.
		 *
		 * @return CryptoService|null Instancia de CryptoService o null si no existe.
		 */
		public static function get_crypto(): ?CryptoService {
		if ( class_exists( CryptoService::class ) ) {
			return CryptoService::get_instance();
		}
		return null;
	}
}
