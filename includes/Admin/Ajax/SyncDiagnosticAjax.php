<?php
/**
 * Controlador AJAX para diagnóstico y gestión de sincronización
 *
 * Este controlador maneja todas las peticiones AJAX relacionadas con el diagnóstico,
 * restauración y gestión de copias de seguridad del sistema de sincronización.
 *
 * Características principales:
 * - Diagnóstico del estado de sincronización
 * - Reparación automática de problemas detectados
 * - Gestión de copias de seguridad
 * - Restauración a puntos de recuperación anteriores
 *
 * @package MiIntegracionApi\Admin\Ajax
 * @since 1.0.0
 */

namespace MiIntegracionApi\Admin\Ajax;

use MiIntegracionApi\Core\Sync_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente
}

/**
 * Controla las peticiones AJAX para diagnóstico y gestión de sincronización
 *
 * Esta clase proporciona endpoints AJAX seguros para:
 * - Realizar diagnósticos del estado de sincronización
 * - Reparar automáticamente problemas detectados
 * - Gestionar copias de seguridad del estado de sincronización
 * - Restaurar estados anteriores desde backups
 *
 * Todas las peticiones requieren autenticación y verificación de nonce para seguridad.
 *
 * @see Sync_Manager Para la lógica de negocio de sincronización
 * @since 1.0.0
 */
class SyncDiagnosticAjax {

	/**
	 * Inicializa los hooks AJAX para el diagnóstico de sincronización
	 *
	 * Registra los siguientes manejadores AJAX:
	 * - verial_sync_diagnostic: Para diagnóstico y reparación
	 * - verial_sync_restore: Para restaurar desde backup
	 * - verial_sync_backup_list: Para listar backups disponibles
	 *
	 * @return void
	 * @hook init - Se registra durante la inicialización de WordPress
	 * @since 1.0.0
	 */
	public static function init() {
		// Acciones para usuarios autenticados
		add_action( 'wp_ajax_verial_sync_diagnostic', [ __CLASS__, 'handle_diagnostic_request' ] );
		add_action( 'wp_ajax_verial_sync_restore', [ __CLASS__, 'handle_restore_request' ] );
		add_action( 'wp_ajax_verial_sync_backup_list', [ __CLASS__, 'handle_backup_list_request' ] );
	}

	/**
	 * Maneja la solicitud de diagnóstico de sincronización
	 *
	 * Procesa la solicitud AJAX para diagnosticar el estado actual de sincronización
	 * y opcionalmente intentar reparar problemas detectados.
	 *
	 * Parámetros esperados (POST):
	 * - _wpnonce: Nonce de seguridad
	 * - repair: (opcional) 'true' para intentar reparar automáticamente
	 *
	 * @return void Envía respuesta JSON con el resultado
	 * @throws \Exception Si ocurre un error durante el diagnóstico
	 * @see Sync_Manager::diagnostico_y_reparacion_estado()
	 * @since 1.0.0
	 */
	public static function handle_diagnostic_request() {
		self::verify_nonce( 'verial_sync_diagnostic_nonce' );
		self::verify_capability( 'manage_woocommerce' );

		$reparar = ! empty( $_POST['repair'] ) && 'true' === $_POST['repair'];
		$manager = Sync_Manager::get_instance();
		
		try {
			$resultado = $manager->diagnostico_y_reparacion_estado( $reparar );
			self::send_json_response( $resultado );
		} catch ( \Exception $e ) {
			self::send_json_error( 
				'Error durante el diagnóstico: ' . $e->getMessage(),
				[ 'exception' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Maneja la solicitud de restauración desde un backup
	 *
	 * Restaura el estado de sincronización desde un backup específico o el más reciente.
	 *
	 * Parámetros esperados (POST):
	 * - _wpnonce: Nonce de seguridad
	 * - backup_key: (opcional) Clave del backup a restaurar. Si no se especifica,
	 *               se restaurará el backup más reciente
	 *
	 * @return void Envía respuesta JSON con el resultado de la operación
	 * @throws \Exception Si ocurre un error durante la restauración
	 * @see Sync_Manager::restore_sync_status()
	 * @see Sync_Manager::restore_latest_backup()
	 * @since 1.0.0
	 */
	public static function handle_restore_request() {
		self::verify_nonce( 'verial_sync_restore_nonce' );
		self::verify_capability( 'manage_woocommerce' );

		$backup_key = isset( $_POST['backup_key'] ) ? sanitize_text_field( $_POST['backup_key'] ) : null;
		$manager    = Sync_Manager::get_instance();

		try {
			if ( $backup_key ) {
				$resultado = $manager->restore_sync_status( $backup_key );
			} else {
				$resultado = $manager->restore_latest_backup();
			}

			if ( $resultado ) {
				self::send_json_response( [
					'success' => true,
					'message' => $backup_key 
						? sprintf( 'Backup %s restaurado correctamente', $backup_key )
						: 'Último backup restaurado correctamente',
					'status'  => $manager->getSyncStatus()
				] );
			} else {
				self::send_json_error( 
					'No se pudo restaurar el backup. Verifica que la clave sea correcta.',
					[ 'backup_key' => $backup_key ]
				);
			}
		} catch ( \Exception $e ) {
			self::send_json_error( 
				'Error durante la restauración: ' . $e->getMessage(),
				[ 'exception' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Obtiene la lista de backups de sincronización disponibles
	 *
	 * Devuelve un listado de todos los puntos de restauración disponibles
	 * con información formateada para su visualización en la interfaz.
	 *
	 * Parámetros esperados (POST):
	 * - _wpnonce: Nonce de seguridad
	 *
	 * @return void Envía respuesta JSON con la lista de backups
	 * @throws \Exception Si ocurre un error al obtener la lista
	 * @see Sync_Manager::get_available_backups()
	 * @since 1.0.0
	 */
	public static function handle_backup_list_request() {
		self::verify_nonce( 'verial_sync_backup_list_nonce' );
		self::verify_capability( 'manage_woocommerce' );

		try {
			$manager = Sync_Manager::get_instance();
			$backups = $manager->get_available_backups();
			
			$formatted_backups = [];
			foreach ( $backups as $key => $timestamp ) {
				$formatted_backups[] = [
					'key'       => $key,
					'timestamp' => $timestamp,
					'date'      => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ),
					'human'     => human_time_diff( $timestamp, current_time( 'timestamp' ) ) . ' ' . __( 'atrás', 'mi-integracion-api' )
				];
			}
			
			self::send_json_response( [
				'success' => true,
				'backups' => $formatted_backups,
				'count'   => count( $formatted_backups )
			] );
		} catch ( \Exception $e ) {
			self::send_json_error( 
				'Error al obtener la lista de backups: ' . $e->getMessage(),
				[ 'exception' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Verifica el nonce de seguridad de la petición AJAX
	 *
	 * @param string $action Acción del nonce a verificar
	 * @return void
	 * @throws \Exception Si el nonce no es válido o no está presente
	 * @see wp_verify_nonce()
	 * @since 1.0.0
	 */
	private static function verify_nonce( $action ) {
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], $action ) ) {
			status_header( 403 );
			self::send_json_error( 'Acceso no autorizado. Token de seguridad inválido.' );
		}
	}

	/**
	 * Verifica que el usuario actual tenga los permisos necesarios
	 *
	 * @param string $capability Capacidad de WordPress requerida
	 * @return void
	 * @throws \Exception Si el usuario no tiene los permisos necesarios
	 * @see current_user_can()
	 * @since 1.0.0
	 */
	private static function verify_capability( $capability ) {
		if ( ! current_user_can( $capability ) ) {
			status_header( 403 );
			self::send_json_error( 'No tienes permisos suficientes para realizar esta acción.' );
		}
	}

	/**
	 * Envía una respuesta JSON exitosa al cliente
	 *
	 * @param array $data Datos a incluir en la respuesta
	 * @return void Finaliza la ejecución del script
	 * @see wp_json_encode()
	 * @since 1.0.0
	 */
	private static function send_json_response( $data = [] ) {
		// Crear SyncResponse usando ResponseFactory
		$sync_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$data,
			'Diagnóstico completado correctamente',
			[
				'endpoint' => 'SyncDiagnosticAjax::send_json_response',
				'diagnostic_operation' => true,
				'timestamp' => time()
			]
		);
		
		// Convertir a formato JSON de WordPress
		$response = $sync_response->toArray();
		header( 'Content-Type: application/json' );
		echo wp_json_encode( $response );
		die();
	}

	/**
	 * Envía una respuesta de error en formato JSON
	 *
	 * @param string $message Mensaje de error descriptivo
	 * @param array  $data Datos adicionales para depuración
	 * @param int    $status_code Código de estado HTTP (por defecto: 400)
	 * @return void Finaliza la ejecución del script
	 * @see wp_json_encode()
	 * @since 1.0.0
	 */
	private static function send_json_error( $message, $data = [], $status_code = 400 ) {
		// Crear SyncResponse usando ResponseFactory
		$sync_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
			$message,
			$status_code,
			array_merge([
				'endpoint' => 'SyncDiagnosticAjax::send_json_error',
				'error_code' => 'diagnostic_error',
				'diagnostic_operation' => true,
				'timestamp' => time()
			], $data)
		);
		
		// Convertir a formato JSON de WordPress
		$response = $sync_response->toArray();
		header( 'Content-Type: application/json' );
		header( 'X-WP-Error', $message, true, $status_code );
		echo wp_json_encode( $response );
		die();
	}
}

// Inicializar el controlador AJAX cuando WordPress cargue
add_action( 'init', [ 'MiIntegracionApi\\Admin\\Ajax\\SyncDiagnosticAjax', 'init' ] );
