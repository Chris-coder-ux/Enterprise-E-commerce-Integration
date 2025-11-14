<?php
declare(strict_types=1);

/**
 * Módulo de sincronización vía AJAX
 *
 * Este archivo maneja todas las operaciones de sincronización asíncrona
 * entre WordPress y sistemas externos a través de peticiones AJAX.
 *
 * Características principales:
 * - Sincronización en segundo plano
 * - Manejo de lotes (batch processing)
 * - Seguridad mejorada
 * - Logging detallado
 * - Monitoreo de progreso
 *
 * @package     MiIntegracionApi\Admin
 * @since       1.0.0
 * @version     2.1.0
 * @author      Christian <crisito29@hotmail.com>
 * @copyright   Copyright (c) 2025, Your Company
 * @license     GPL-2.0+
 * @link        https://example.com/plugin-docs/sync
 */

namespace MiIntegracionApi\Admin;

use MiIntegracionApi\Logging\Core\LoggerBasic;
use MiIntegracionApi\Helpers\ResponseProcessor;
use MiIntegracionApi\Core\Sync_Manager;
use MiIntegracionApi\Core\SyncLock;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase principal para el manejo de sincronización vía AJAX
 *
 * Esta clase proporciona funcionalidad completa para la sincronización
 * asíncrona de datos entre WordPress y sistemas externos, incluyendo:
 * - Procesamiento por lotes
 * - Manejo de errores
 * - Seguridad mejorada
 * - Monitoreo de progreso
 *
 * @package     MiIntegracionApi\Admin
 * @since       1.0.0
 * @version     2.1.0
 * @see         \MiIntegracionApi\Core\Sync_Manager
 * @see         \MiIntegracionApi\Core\SyncLock
 * @property    \wpdb $wpdb Instancia global de WordPress Database Access
 * @global      \wpdb $wpdb Objeto global de base de datos de WordPress
 */
class AjaxSync {
	/**
	 * Tiempo máximo permitido para una sincronización en segundos
	 *
	 * Este valor define el tiempo máximo que puede durar una sincronización
	 * antes de ser marcada como fallida por tiempo de espera.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const MAX_ELAPSED_TIME = 7 * 24 * 3600; // 7 días en segundos
	
	/**
	 * Inicializa el sistema de sincronización AJAX
	 *
	 * Este método estático se encarga de registrar todos los manejadores AJAX
	 * necesarios para el funcionamiento del sistema de sincronización.
	 *
	 * @return void
	 * @since 1.0.0
	 * @see register_ajax_handlers() Método que registra los manejadores AJAX
	 * @uses register_ajax_handlers() Para el registro de hooks AJAX
	 *
	 * @example
	 * ```php
	 * // En el archivo principal del plugin
	 * add_action('plugins_loaded', ['MiIntegracionApi\Admin\AjaxSync', 'init']);
	 * ```
	 */
	public static function init(): void {
		self::register_ajax_handlers();
	}
	

	/**
	 * Registra todos los manejadores AJAX necesarios para la sincronización
	 *
	 * Este método registra los hooks de WordPress para todos los endpoints AJAX
	 * utilizados en el proceso de sincronización. Incluye endpoints para:
	 * - Monitoreo de sincronización (heartbeat, progreso, cancelación)
	 * - Procesamiento por lotes (batch processing)
	 * - Diagnóstico y resolución de problemas
	 * - Gestión de caché y configuración
	 *
	 * @return void
	 * @since 1.0.0
	 * @hook plugins_loaded Se ejecuta cuando WordPress ha terminado de cargar los plugins
	 * @uses add_action() Para registrar los hooks de WordPress
	 * @uses apply_filters() Para permitir la modificación del comportamiento
	 *
	 * @example
	 * ```php
	 * // Ejemplo de cómo se registran los manejadores
	 * add_action('wp_ajax_mia_sync_heartbeat', [AjaxSync::class, 'sync_heartbeat_callback']);
	 * ```
	 *
	 * @note Este método incluye código comentado para el manejo de registro único,
	 *       que puede ser útil en entornos con carga múltiple de plugins.
	 */
	public static function register_ajax_handlers(): void {
		$option_key = 'mia_ajax_handlers_registered';
		
		// Código comentado para manejo de registro único (deshabilitado por problemas)
		// static $option_cleaned = false;
		// if (!$option_cleaned && get_option($option_key) === true) {
		//     error_log('[MIA DEBUG] AjaxSync::register_ajax_handlers() - Limpiando opción para forzar registro');
		//     delete_option($option_key);
		//     $option_cleaned = true;
		// }
		
		// Código comentado para evitar registro múltiple (deshabilitado por problemas)
		// if (get_option($option_key)) {
		//     error_log('[MIA DEBUG] AjaxSync::register_ajax_handlers() - Endpoints ya registrados, saltando');
		//     return;
		// }
		
		// Feature flag para enrutamiento Core
		$flag = (bool) apply_filters('mia_use_core_sync_routing', 
			defined('MIA_USE_CORE_SYNC') ? constant('MIA_USE_CORE_SYNC') : get_option('mia_use_core_sync_routing', true)
		);
		
		// Verificar que la función add_action esté disponible
		if (!function_exists('add_action')) {
			return;
		}
		
		// ===== ENDPOINTS DE SINCRONIZACIÓN =====
		
		// Monitoreo de sincronización
		add_action('wp_ajax_mia_sync_heartbeat', [self::class, 'sync_heartbeat_callback']);
		add_action('wp_ajax_mia_sync_cancel', [self::class, 'sync_cancel_callback']);
		add_action('wp_ajax_mia_diagnose_sync', [self::class, 'diagnose_sync_callback']);
		add_action('wp_ajax_mia_get_sync_progress', [self::class, 'get_sync_progress_callback']);
		
		// Procesamiento por lotes
		add_action('mia_process_sync_batch', [self::class, 'process_sync_batch_cron']);
		add_action('wp_ajax_mi_integracion_api_sync_products_batch', [self::class, 'sync_products_batch']);
		add_action('wp_ajax_mia_process_next_batch', [self::class, 'process_next_batch']);
		add_action('wp_ajax_mi_integracion_api_sync_clients_job_batch', [self::class, 'sync_clients_job_batch']);
		
		// Sistema de cola en background
		add_action('wp_ajax_mia_process_queue_background', [self::class, 'process_queue_background_callback']);
		add_action('wp_ajax_nopriv_mia_process_queue_background', [self::class, 'process_queue_background_callback']);
		
		// Diagnóstico y resolución
		add_action('wp_ajax_mi_integracion_api_validate_filters', [self::class, 'validate_filters']);
		add_action('wp_ajax_mi_integracion_api_test_api', [self::class, 'test_api']);
		add_action('wp_ajax_mi_integracion_api_diagnostico_ini', [self::class, 'diagnostico_ini']);
		add_action('wp_ajax_mi_integracion_api_resolver_ini', [self::class, 'resolver_error_ini']);
		
		// Gestión de caché y configuración
		add_action('wp_ajax_mi_integracion_api_clear_cache', [self::class, 'clear_cache']);
		add_action('wp_ajax_mia_load_filter_options', [self::class, 'load_filter_options']);
		add_action('wp_ajax_mi_integracion_api_save_batch_size', [self::class, 'save_batch_size']);
		add_action('wp_ajax_mia_update_throttle_delay', [self::class, 'update_throttle_delay']);
		add_action('wp_ajax_mia_update_auto_retry', [self::class, 'update_auto_retry']);
		// ✅ NUEVO: Optimización de índices de base de datos
		add_action('wp_ajax_mia_optimize_image_duplicates_indexes', [self::class, 'optimize_image_duplicates_indexes']);
		add_action('wp_ajax_mia_benchmark_duplicates_search', [self::class, 'benchmark_duplicates_search']);
		// ✅ NUEVO: Migración Hot→Cold Cache
		add_action('wp_ajax_mia_perform_hot_cold_migration', [self::class, 'perform_hot_cold_migration_callback']);
		
		// Sincronización de imágenes (Arquitectura dos fases - Fase 1)
		add_action('wp_ajax_mia_sync_images', [self::class, 'sync_images_callback']);
		add_action('wp_ajax_mia_cancel_images_sync', [self::class, 'cancel_images_sync_callback']);
		
		// Endpoints públicos (accesibles sin autenticación)
		add_action('wp_ajax_mi_integracion_api_get_products_batch', [self::class, 'get_products_batch']);
		add_action('wp_ajax_nopriv_mi_integracion_api_get_products_batch', [self::class, 'get_products_batch']);
	}
	
	/**
	 * Registra un mensaje de nivel INFO en el log
	 *
	 * Este método proporciona una interfaz simplificada para registrar mensajes
	 * informativos en el sistema de logging del plugin. Los mensajes de INFO
	 * son útiles para rastrear el flujo normal de ejecución.
	 *
	 * @param string $message Mensaje descriptivo del evento a registrar
	 * @param array<string, mixed> $context Datos adicionales para incluir en el log
     * @param string|null $category Categoría específica para agrupar logs relacionados
     *                             (por defecto: 'ajax-sync')
     * @return void
     * @since 1.0.0
     * @see Logger Para más detalles sobre el sistema de logging
     *
     * @example
     * ```php
     * // Ejemplo de uso básico
     * self::logInfo('Iniciando proceso de sincronización');
     *
     * // Ejemplo con contexto
     * self::logInfo('Producto sincronizado', [
     *     'product_id' => 123,
     *     'status' => 'success'
     * ]);
     *
     * // Ejemplo con categoría personalizada
     * self::logInfo('Conexión establecida', [], 'api-connection');
     * ```
     */
    public static function logInfo(string $message, array $context = [], ?string $category = null): void
    {
        $logger = new LoggerBasic($category ?? 'ajax-sync');
        $logger->info($message, $context);
    }
    
    /**
     * Registra un mensaje de nivel WARNING en el log
     *
     * Este método se utiliza para registrar situaciones inusuales que no son
     * necesariamente errores, pero que podrían indicar problemas potenciales.
     *
     * @param string $message Mensaje de advertencia a registrar
     * @param array<string, mixed> $context Datos adicionales para incluir en el log
     * @param string|null $category Categoría específica (por defecto: 'ajax-sync')
     * @return void
     * @since 1.0.0
     * @see Logger Para más detalles sobre el sistema de logging
     */
    public static function logWarning(string $message, array $context = [], ?string $category = null): void
    {
        $logger = new LoggerBasic($category ?? 'ajax-sync');
        $logger->warning($message, $context);
    }
    
    /**
     * Registra un mensaje de nivel ERROR en el log
     *
     * Este método se utiliza para registrar errores que afectan la funcionalidad
     * pero permiten que la aplicación continúe ejecutándose.
     *
     * @param string $message Descripción del error
     * @param array<string, mixed> $context Datos adicionales sobre el error
     * @param string|null $category Categoría específica (por defecto: 'ajax-sync')
     * @return void
     * @since 1.0.0
     * @see Logger Para más detalles sobre el sistema de logging
     */
    public static function logError(string $message, array $context = [], ?string $category = null): void
    {
        $logger = new LoggerBasic($category ?? 'ajax-sync');
        $logger->error($message, $context);
    }
    
    /**
     * Registra un mensaje de nivel CRITICAL en el log
     *
     * Este método se utiliza para registrar errores críticos que requieren
     * atención inmediata, como fallos que impiden que la aplicación funcione.
     *
     * @param string $message Descripción del error crítico
     * @param array<string, mixed> $context Datos adicionales sobre el error
     * @param string|null $category Categoría específica (por defecto: 'ajax-sync')
     * @return void
     * @since 1.0.0
     * @see Logger Para más detalles sobre el sistema de logging
     */
    public static function logCritical(string $message, array $context = [], ?string $category = null): void
    {
        $logger = new LoggerBasic($category ?? 'ajax-sync');
        $logger->critical($message, $context);
    }
	
	/**
	 * Registra métricas de rendimiento para operaciones de sincronización
	 *
	 * Este método está obsoleto y se mantiene solo por compatibilidad con versiones anteriores.
	 * La funcionalidad ha sido trasladada a la clase `SyncMetrics` para un mejor manejo
	 * de métricas y estadísticas de rendimiento.
	 *
	 * @deprecated 2.1.0 Este método será eliminado en una versión futura.
	 *             Usar `SyncMetrics::recordBatchMetrics()` en su lugar.
	 *
	 * @param string $operation Nombre de la operación que se está midiendo
     * @param float $startTime Tiempo de inicio de la operación (timestamp con microsegundos)
     * @param array<string, mixed> $context Datos adicionales sobre la operación que deben incluir:
     *   - batch_number: Número del lote procesado (opcional, por defecto: 1)
     *   - processed_items: Número de elementos procesados (opcional, por defecto: 0)
     *   - errors: Número de errores encontrados (opcional, por defecto: 0)
     *   - retry_processed: Número de reintentos procesados (opcional, por defecto: 0)
     *   - retry_errors: Número de errores en reintentos (opcional, por defecto: 0)
     *
     * @return void
     * @since 1.5.0
     * @see \MiIntegracionApi\Core\SyncMetrics::recordBatchMetrics() Método de reemplazo
     *
     * @example
     * ```php
     * // Uso obsoleto (no usar en código nuevo)
     * $start = microtime(true);
     * // ... operación de sincronización ...
     * self::logPerformance('sincronizacion_productos', $start, [
     *     'batch_number' => 1,
     *     'processed_items' => 50,
     *     'errors' => 2
     * ]);
     *
     * // Uso recomendado
     * $start = microtime(true);
     * // ... operación de sincronización ...
     * $metrics = new \MiIntegracionApi\Core\SyncMetrics();
     * $metrics->recordBatchMetrics(
     *     1,                    // batch_number
     *     50,                   // processed_items
     *     microtime(true) - $start, // duration
     *     2,                    // errors
     *     0,                    // retry_processed
     *     0                     // retry_errors
     * );
     * ```
     *
     * @throws \RuntimeException Si ocurre un error al registrar las métricas
     */
	public static function logPerformance(string $operation, float $startTime, array $context = []): void
	{
		// Delegación simple a SyncMetrics
		if (class_exists('\\MiIntegracionApi\\Core\\SyncMetrics')) {
			try {
				$duration = microtime(true) - $startTime;
				$syncMetrics = new \MiIntegracionApi\Core\SyncMetrics();
				
				// CORRECCIÓN: Llamada con parámetros correctos según la declaración
				$syncMetrics->recordBatchMetrics(
					$context['batch_number'] ?? 1,                    // $batchNumber (int)
					$context['processed_items'] ?? 0,                 // $processedItems (int)
					$duration,                                        // $duration (float)
					$context['errors'] ?? 0,                          // $errors (int)
					$context['retry_processed'] ?? 0,                 // $retryProcessed (int)
					$context['retry_errors'] ?? 0                     // $retryErrors (int)
				);
			} catch (\Exception $e) {
				self::logError("Performance tracking falló: " . $e->getMessage(), ['exception' => $e]);
			}
		}
	}	

	/**
	 * Valida la seguridad de una petición AJAX
	 *
	 * Este método centraliza la validación de seguridad para todas las peticiones AJAX
	 * del plugin, incluyendo verificación de nonce, permisos de usuario y capacidades.
	 *
	 * @param string $nonce_param Nombre del parámetro que contiene el nonce en la petición
     * @param string $action Acción del nonce (debe coincidir con el usado al generarlo)
     * @param string $capability Capacidad de WordPress requerida para la acción
     *
     * @return bool
     *   - `true` si la validación es exitosa
     *   - `false` si la validación falla (también envía una respuesta JSON de error)
     *
     * @since 1.0.0
     * @see wp_verify_nonce() Para la verificación de nonces
     * @see current_user_can() Para la verificación de capacidades
     * @see wp_send_json_error() Para el manejo de errores
     *
     * @example
     * ```php
     * // Validación básica
     * if (!self::validateAjaxSecurity('security', 'mi_accion_personalizada')) {
     *     return; // La validación falló, ya se envió la respuesta de error
     * }
     *
     * // Con capacidad personalizada
     * if (!self::validateAjaxSecurity('security', 'editar_productos', 'edit_products')) {
     *     return; // Usuario sin permisos suficientes
     * }
     * ```
     *
     * @throws \Exception Si ocurre un error durante la validación
     *
     * @security Verifica nonce, permisos y capacidades del usuario actual
     * @permission Requiere que el usuario tenga la capacidad especificada
     */
	private static function validateAjaxSecurity($nonce_param = 'nonce', $action = 'mi_integracion_api_nonce_dashboard', $capability = 'manage_options'): bool {
		$security_validator_exists = class_exists('\MiIntegracionApi\Helpers\SecurityValidator');
		
		// Delegación simple a SecurityValidator
		if ($security_validator_exists) {
			try {
				$nonce = $_REQUEST[$nonce_param] ?? '';
				$validation = \MiIntegracionApi\Helpers\SecurityValidator::validateAjaxRequest($nonce, $action, $capability);
				
				if (!$validation['valid']) {
					\MiIntegracionApi\Helpers\SecurityValidator::sendAjaxError(
						$validation['message'],
						$validation['code'],
						$validation['error'] ?? 'general'
					);
					return false;
				}
				
				return true;
			} catch (\Exception $e) {
				self::logError("Validación de seguridad falló: " . $e->getMessage(), ['exception' => $e]);
				wp_send_json_error(['message' => 'Error de seguridad', 'code' => 'security_error'], 500);
				return false;
			}
		}
		
		// Fallback simple
		wp_send_json_error(['message' => 'Sistema de seguridad no disponible', 'code' => 'security_unavailable'], 500);
		return false;
	}		
	
	/**
     * Manejo centralizado de excepciones para operaciones AJAX
     *
     * Este método proporciona un manejo unificado de excepciones en toda la aplicación,
     * registrando errores detallados y devolviendo respuestas JSON estandarizadas.
     * Es utilizado como punto central para capturar y procesar cualquier error no manejado.
     *
     * @param \Throwable $e Excepción capturada que se va a manejar. Puede ser cualquier
     *                     objeto que implemente la interfaz Throwable (incluyendo Exception).
     * @param string $context Contexto donde ocurrió la excepción, típicamente el nombre
     *                      del método o funcionalidad que generó el error.
     * @param string|null $custom_message Mensaje personalizado opcional que reemplazará
     *                                  al mensaje de error predeterminado.
     *
     * @return void
     * @since 1.0.0
     * @see \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error() Para la creación de la respuesta de error
     * @uses self::logError() Para registrar el error en el log
     * @uses wp_send_json_error() Para enviar la respuesta JSON de error
     *
     * @example
     * ```php
     * try {
     *     // Código que puede fallar
     * } catch (\Throwable $e) {
     *     self::handleException($e, 'mi_operacion', 'Error personalizado opcional');
     *     return; // Importante: prevenir ejecución adicional
     * }
     * ```
     *
     * @example
     * ```json
     * // Ejemplo de respuesta generada
     * {
     *     "success": false,
     *     "data": {
     *         "message": "Error interno en mi_operacion: Mensaje de la excepción",
     *         "code": "ajax_exception",
     *         "endpoint": "AjaxSync::handleException",
     *         "error_code": "ajax_exception",
     *         "exception_class": "RuntimeException",
     *         "context": "mi_operacion",
     *         "file": "AjaxSync.php",
     *         "line": 123,
     *         "ajax_operation": true,
     *         "timestamp": 1672500000
     *     }
     * }
     * ```
     *
     * @note Este método termina la ejecución del script después de enviar la respuesta.
     * @see sendError() Para enviar respuestas de error sin lanzar excepciones
     */
	private static function handleException($e, $context = '', $custom_message = null): void {
		// Logging de la excepción
		self::logError('Excepción en AJAX routing', [
			'context' => $context,
			'exception_class' => get_class($e),
			'exception_message' => $e->getMessage(),
			'file' => $e->getFile(),
			'line' => $e->getLine(),
			'trace' => $e->getTraceAsString()
		]);
		
		// Crear SyncResponse usando ResponseFactory
		$message = $custom_message ?? sprintf(__('Error interno en %s: %s', 'mi-integracion-api'), $context, $e->getMessage());
		$sync_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
			$message,
			500,
			[
				'endpoint' => 'AjaxSync::handleException',
				'error_code' => 'ajax_exception',
				'exception_class' => get_class($e),
				'context' => $context,
				'file' => basename($e->getFile()),
				'line' => $e->getLine(),
				'trace' => $e->getTraceAsString(),
				'timestamp' => time()
			]
		);
		
		// Enviar respuesta usando SecurityValidator
		\MiIntegracionApi\Helpers\SecurityValidator::sendSyncResponse($sync_response);
	}
	
	/**
	 * Envía una respuesta de éxito estandarizada en formato JSON
     *
     * Este método centraliza el envío de respuestas exitosas en la API, asegurando
     * que todas sigan un formato consistente que incluye los datos de respuesta,
     * un mensaje descriptivo y metadatos adicionales para facilitar el seguimiento.
     *
     * @param mixed $data Datos a incluir en la respuesta. Puede ser cualquier tipo de dato
     *                   válido en JSON (array, objeto, string, número, booleano, null).
     * @param string $message Mensaje descriptivo del éxito. Si se omite, se usará 'Operación exitosa'.
     * @param int $status_code Código de estado HTTP a devolver. Por defecto: 200 (OK).
     *
     * @return void
     * @since 1.0.0
     * @see \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success() Para la creación de la respuesta exitosa
     * @uses wp_send_json_success() Para enviar la respuesta JSON al cliente
     *
     * @example
     * ```php
     * // Ejemplo de uso básico
     * self::sendSuccess(['id' => 123, 'name' => 'Ejemplo']);
     *
     * // Ejemplo con mensaje personalizado
     * self::sendSuccess(
     *     ['total' => 42, 'items' => [...]],
     *     'Datos cargados correctamente',
     *     201 // Código 201 para recurso creado
     * );
     * ```
     *
     * @example
     * ```json
     * // Ejemplo de respuesta generada
     * {
     *     "success": true,
     *     "data": {
     *         "data": {"id": 123, "name": "Ejemplo"},
     *         "message": "Operación exitosa",
     *         "endpoint": "AjaxSync::sendSuccess",
     *         "ajax_operation": true,
     *         "timestamp": 1672500000
     *     }
     * }
     * ```
     *
     * @note Este método termina la ejecución del script después de enviar la respuesta.
     * @see sendError() Para enviar respuestas de error estandarizadas
     */
	private static function sendSuccess($data, $message = '', $status_code = 200): void {
		// Crear SyncResponse usando ResponseFactory
		$sync_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$data,
			$message ?: 'Operación exitosa',
			[
				'endpoint' => 'AjaxSync::sendSuccess',
				'ajax_operation' => true,
				'timestamp' => time()
			]
		);
		
		// Convertir a formato AJAX de WordPress
		$response = $sync_response->toArray();
		wp_send_json_success($response, $status_code);
	}
	
	/**
	 * Envía una respuesta de error estandarizada en formato JSON
     *
     * Este método centraliza el manejo de errores de la API, asegurando que todas las respuestas
     * de error sigan un formato consistente que incluye un mensaje descriptivo, un código de error
     * y metadatos adicionales para facilitar el diagnóstico de problemas.
     *
     * @param string $message Mensaje descriptivo del error, pensado para ser mostrado al usuario final.
     * @param string $code Código de error único que identifica el tipo de error. Por defecto: 'error'.
     * @param int $status_code Código de estado HTTP a devolver. Por defecto: 400 (Bad Request).
     * @param array<string, mixed> $additional_data Datos adicionales para incluir en la respuesta.
     *        Estos datos se combinarán con los metadatos estándar del error.
     *
     * @return void
     * @since 1.0.0
     * @see \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error() Para la creación de la respuesta de error
     * @uses wp_send_json_error() Para enviar la respuesta JSON al cliente
     *
     * @example
     * ```php
     * // Ejemplo de uso básico
     * self::sendError('El recurso solicitado no existe', 'resource_not_found', 404);
     *
     * // Ejemplo con datos adicionales
     * self::sendError(
     *     'Error de validación',
     *     'validation_error',
     *     422,
     *     [
     *         'fields' => [
     *             'email' => 'El formato del correo electrónico no es válido',
     *             'password' => 'La contraseña debe tener al menos 8 caracteres'
     *         ]
     *     ]
     * );
     * ```
     *
     * @example
     * ```json
     * // Ejemplo de respuesta generada
     * {
     *     "success": false,
     *     "data": {
     *         "message": "El recurso solicitado no existe",
     *         "code": "resource_not_found",
     *         "endpoint": "AjaxSync::sendError",
     *         "error_code": "resource_not_found",
     *         "ajax_operation": true,
     *         "timestamp": 1672500000
     *     }
     * }
     * ```
     *
     * @note Este método termina la ejecución del script después de enviar la respuesta.
     */
	private static function sendError($message, $code = 'error', $status_code = 400, $additional_data = []): void {
		// Crear SyncResponse usando ResponseFactory
		$sync_response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
			$message,
			$status_code,
			array_merge([
				'endpoint' => 'AjaxSync::sendError',
				'error_code' => $code,
				'ajax_operation' => true,
				'timestamp' => time()
			], $additional_data)
		);
		
		// Convertir a formato AJAX de WordPress
		$response = $sync_response->toArray();
		wp_send_json_error($response, $status_code);
	}	


	/**
	 * Maneja las peticiones de latido (heartbeat) para el seguimiento de sincronización
	 *
	 * Este endpoint es llamado periódicamente por el cliente para verificar el estado
	 * actual de la sincronización. Proporciona información sobre el progreso,
	 * errores y estado general de la operación en curso.
	 *
	 * @return void
	 * @since 1.3.0
	 * @see \MiIntegracionApi\Helpers\SyncStatusHelper::getHeartbeatData()
	 * @see wp_send_json_success() Para el formato de respuesta exitosa
	 * @see wp_send_json_error() Para el formato de respuesta de error
	 *
	 * @example
	 * ```javascript
	 * // Ejemplo de solicitud AJAX
	 * jQuery.ajax({
	 *     url: ajaxurl,
	 *     type: 'POST',
	 *     data: {
	 *         action: 'mia_sync_heartbeat',
	 *         nonce: mi_vars.nonce
	 *     },
	 *     success: function(response) {
	 *         console.log('Estado de sincronización:', response.data);
	 *     }
	 * });
	 * ```
	 *
	 * @example
	 * ```json
	 * // Ejemplo de respuesta exitosa
	 * {
	 *     "success": true,
	 *     "data": {
	 *         "in_progress": true,
	 *         "porcentaje": 42.5,
	 *         "mensaje": "Sincronizando...",
	 *         "estadisticas": {
	 *             "procesados": 85,
	 *             "total": 200,
	 *             "errores": 2
	 *         },
	 *         "current_batch": 3,
	 *         "total_batches": 5,
	 *         "operation_id": "sync_123456789"
	 *     }
	 * }
	 * ```
	 *
	 * @security Verifica el nonce de seguridad y los permisos del usuario
	 * @permission Requiere la capacidad 'manage_options' por defecto
	 */
	public static function sync_heartbeat_callback(): void {
		// Validar seguridad de la petición
		if (!self::validateAjaxSecurity('nonce', 'mia_heartbeat_nonce')) {
			return; // validateAjaxSecurity ya envía la respuesta de error
		}
		
		try {
			// Delegar a SyncStatusHelper para obtener los datos de latido
			$heartbeat_data = \MiIntegracionApi\Helpers\SyncStatusHelper::getHeartbeatData();
			
			// Enviar respuesta exitosa con los datos de latido
			wp_send_json_success($heartbeat_data);
		} catch (\Throwable $e) {
			// Manejo unificado de excepciones
			self::handleException($e, 'sync_heartbeat_callback');
		}
	}

	/**
	 * Obtiene el progreso actual de la sincronización vía AJAX
     *
     * Este método proporciona información detallada sobre el estado actual de la sincronización,
     * incluyendo el porcentaje completado, número de elementos procesados y estadísticas de errores.
     * La información es obtenida a través de SyncStatusHelper y formateada para su uso en la interfaz de usuario.
     *
     * @return void
     * @since 1.0.0
     * @see \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo() Para obtener la información de sincronización
     * @uses round() Para redondear el porcentaje a 2 decimales
     * 
     * @example
     * ```javascript
     * // Ejemplo de llamada AJAX para obtener el progreso
     * jQuery.ajax({
     *     url: ajaxurl,
     *     type: 'POST',
     *     data: {
     *         action: 'mia_get_sync_progress',
     *         nonce: 'nonce_seguro'
     *     },
     *     success: function(response) {
     *         console.log('Progreso de sincronización:', response.data);
     *     }
     * });
     * ```
     *
     * @example
     * ```json
     * // Ejemplo de respuesta exitosa
     * {
     *     "success": true,
     *     "data": {
     *         "in_progress": true,
     *         "porcentaje": 42.5,
     *         "mensaje": "Sincronizando...",
     *         "estadisticas": {
     *             "procesados": 85,
     *             "total": 200,
     *             "errores": 2
     *         }
     *     }
     * }
     * ```
     *
     * @security Verifica el nonce de seguridad y los permisos del usuario
     * @permission Requiere la capacidad 'manage_options' por defecto
     * @throws \Throwable Captura cualquier excepción y la maneja apropiadamente
     */
	public static function get_sync_progress_callback(): void {
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return; // validateAjaxSecurity already sends the error response
		}
		
		try {
			// Delegar a SyncStatusHelper
			$sync_info = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
			
		// ✅ REMOVIDO: Debug innecesario que se ejecuta en cada petición (cada 2 segundos)
			
			// ✅ CORRECCIÓN: Si no hay sincronización activa, resetear todos los valores a 0
			$in_progress = !empty($sync_info['in_progress']) && $sync_info['in_progress'] === true;
			
			// Calcular porcentaje de progreso
			$porcentaje = 0.0;
			$items_synced = $in_progress ? ((float) ($sync_info['items_synced'] ?? 0)) : 0;
			$total_items = $in_progress ? ((float) ($sync_info['total_items'] ?? 0)) : 0;
			
			// ✅ CORRECCIÓN: Solo calcular porcentaje si hay sincronización activa y total_items > 0
			if ($in_progress && $total_items > 0) {
				$porcentaje = ($items_synced / $total_items) * 100;
			}
			
			// Asegurar que el porcentaje es un número válido
			$porcentaje = is_numeric($porcentaje) ? (float) $porcentaje : 0.0;
			
			// ✅ CORRECCIÓN: Solo aplicar mínimo 2% si hay sincronización activa
			$porcentaje_visual = $in_progress ? max(2.0, $porcentaje) : 0.0;
			
			// ✅ NUEVO: Calcular progreso de Fase 1 (imágenes)
			$phase1_info = $sync_info['phase1_images'] ?? [];
			
			// ✅ CORRECCIÓN: Determinar si Fase 1 está realmente en progreso
			$phase1_in_progress = !empty($phase1_info['in_progress']) && $phase1_info['in_progress'] === true;
			
			// ✅ NUEVO: Detectar procesos huérfanos (procesos que se quedaron estancados)
			// Si el proceso está marcado como "in_progress" pero no se ha actualizado en más de 5 minutos,
			// probablemente el proceso se detuvo y el estado quedó marcado incorrectamente
			if ($phase1_in_progress) {
				$last_update = (int) ($phase1_info['last_update'] ?? 0);
				$current_time = time();
				$time_since_update = $current_time - $last_update;
				$stale_threshold = 300; // 5 minutos
				
				if ($last_update > 0 && $time_since_update > $stale_threshold) {
					// Proceso huérfano detectado - limpiar estado pero NO marcar como completado
					error_log('[MIA WARNING] Proceso huérfano de Fase 1 detectado - limpiando estado. Tiempo sin actualizar: ' . $time_since_update . ' segundos');
					\MiIntegracionApi\Helpers\SyncStatusHelper::updatePhase1Images([
						'in_progress' => false,
						'completed' => false, // ✅ IMPORTANTE: NO marcar como completado
						'errors' => ($phase1_info['errors'] ?? 0) + 1,
						'stale_detected' => true // Marcar que se detectó como huérfano
					]);
					$phase1_info['in_progress'] = false;
					$phase1_info['completed'] = false; // ✅ Asegurar que no se marque como completado
					$phase1_info['stale_detected'] = true;
				}
			}
			
		// ✅ REMOVIDO: Debug innecesario que se ejecuta en cada petición (cada 2 segundos)
			
			$phase1_porcentaje = 0.0;
			// ✅ CORRECCIÓN: Usar valores reales siempre que existan, incluso si está pausada o cancelada
			// Esto permite mostrar el progreso real cuando la sincronización se detiene
			$phase1_products_processed = (float) ($phase1_info['products_processed'] ?? 0);
			$phase1_total_products = (float) ($phase1_info['total_products'] ?? 0);
			
			// ✅ CORRECCIÓN: Calcular porcentaje siempre que haya total_products > 0, incluso si está pausada o cancelada
			// Esto permite mostrar el progreso real cuando la sincronización se detiene
			if ($phase1_total_products > 0) {
				$phase1_porcentaje = ($phase1_products_processed / $phase1_total_products) * 100;
			}
			$phase1_porcentaje = is_numeric($phase1_porcentaje) ? (float) $phase1_porcentaje : 0.0;
			// ✅ CORRECCIÓN: Aplicar mínimo 2% solo si está en progreso, pero mostrar porcentaje real si está pausada/cancelada
			$phase1_porcentaje_visual = $phase1_in_progress ? max(2.0, $phase1_porcentaje) : $phase1_porcentaje;
			
			// ✅ CORRECCIÓN: Calcular si Fase 1 está realmente completada
			// Solo marcar como completado si realmente se procesaron todos los productos
			$phase1_is_completed = false;
			if (!$phase1_in_progress) {
				// Solo considerar completado si:
				// 1. No está en progreso
				// 2. Tiene total_products > 0
				// 3. Ha procesado todos los productos (products_processed === total_products)
				// 4. NO es un proceso huérfano detectado
				$is_stale = !empty($phase1_info['stale_detected']) && $phase1_info['stale_detected'] === true;
				$has_total = $phase1_total_products > 0;
				$all_processed = $has_total && $phase1_products_processed > 0 && 
				                 $phase1_products_processed === $phase1_total_products;
				
				$phase1_is_completed = !$is_stale && $all_processed;
			}
			
			// ✅ CORRECCIÓN: Si no hay sincronización activa, resetear errores también
			$errors = $in_progress ? ((int) ($sync_info['errors'] ?? 0)) : 0;
			
			// Preparar respuesta con la estructura que espera el JavaScript
			$response = [
				'in_progress' => $in_progress,
				'porcentaje' => round($porcentaje, 2),
				'porcentaje_visual' => round($porcentaje_visual, 2),
				'progress_width' => $porcentaje_visual . '%',
				'mensaje' => $in_progress ? 'Sincronizando...' : 'Pendiente',
				'estadisticas' => [
					'procesados' => $items_synced,
					'total' => $total_items,
					'errores' => $errors
				],
				'current_batch' => $sync_info['current_batch'] ?? 0,
				'total_batches' => $sync_info['total_batches'] ?? 0,
				'operation_id' => $sync_info['operation_id'] ?? null,
				// ✅ CORRECCIÓN: Solo marcar como completado si realmente se completó (total_items > 0 y items_synced >= total_items)
				'is_completed' => !$in_progress && $total_items > 0 && $items_synced > 0 && $items_synced >= $total_items,
				// ✅ NUEVO: Información de Fase 1 (imágenes)
				'phase1_images' => [
					'in_progress' => $phase1_in_progress,
					'paused' => !empty($phase1_info['paused']) && $phase1_info['paused'] === true,
					'completed' => $phase1_is_completed,
					'stale_detected' => !empty($phase1_info['stale_detected']) ? true : false,
					'porcentaje' => round($phase1_porcentaje, 2),
					'porcentaje_visual' => round($phase1_porcentaje_visual, 2),
					'progress_width' => $phase1_porcentaje_visual . '%',
					'mensaje' => (!empty($phase1_info['paused']) && $phase1_info['paused'] === true) ? 'Sincronización pausada' : ($phase1_in_progress ? 'Sincronizando imágenes...' : ($phase1_is_completed ? 'Imágenes completadas' : 'Pendiente de sincronización')),
					'products_processed' => $phase1_products_processed,
					'total_products' => $phase1_total_products,
					// ✅ CORRECCIÓN: Usar valores reales siempre que existan, incluso si está pausada o cancelada
					// Esto permite mostrar el progreso real cuando la sincronización se detiene
					'images_processed' => (int) ($phase1_info['images_processed'] ?? 0),
					'duplicates_skipped' => (int) ($phase1_info['duplicates_skipped'] ?? 0),
					'errors' => (int) ($phase1_info['errors'] ?? 0), // ✅ CORRECCIÓN: Usar 'errors' para consistencia con frontend
					'errores' => (int) ($phase1_info['errors'] ?? 0), // ✅ MANTENER: Compatibilidad con código que espera 'errores'
					'is_completed' => $phase1_is_completed,
					// ✅ NUEVO: Información del último producto procesado para mostrar en consola
					'last_processed_id' => (int) ($phase1_info['last_processed_id'] ?? 0),
					'last_product_images' => (int) ($phase1_info['last_product_images'] ?? 0),
					'last_product_duplicates' => (int) ($phase1_info['last_product_duplicates'] ?? 0),
					'last_product_errors' => (int) ($phase1_info['last_product_errors'] ?? 0),
					// ✅ NUEVO: Métricas de limpieza de caché
					'last_cleanup_metrics' => $phase1_info['last_cleanup_metrics'] ?? null,
					// ✅ NUEVO: Información de limpieza inicial de caché
					'initial_cache_cleared' => !empty($phase1_info['initial_cache_cleared']) ? true : false,
					'initial_cache_cleared_count' => (int) ($phase1_info['initial_cache_cleared_count'] ?? 0),
					'initial_cache_cleared_timestamp' => (int) ($phase1_info['initial_cache_cleared_timestamp'] ?? 0),
					// ✅ NUEVO: Información de checkpoint cargado (reanudación)
					'checkpoint_loaded' => !empty($phase1_info['checkpoint_loaded']) ? true : false,
					'checkpoint_loaded_from_id' => (int) ($phase1_info['checkpoint_loaded_from_id'] ?? 0),
					'checkpoint_loaded_timestamp' => (int) ($phase1_info['checkpoint_loaded_timestamp'] ?? 0),
					'checkpoint_loaded_products_processed' => (int) ($phase1_info['checkpoint_loaded_products_processed'] ?? 0),
					// ✅ NUEVO: Información de último checkpoint guardado
					'last_checkpoint_saved_id' => (int) ($phase1_info['last_checkpoint_saved_id'] ?? 0),
					'last_checkpoint_saved_timestamp' => (int) ($phase1_info['last_checkpoint_saved_timestamp'] ?? 0),
					// ✅ NUEVO: Información de tiempo de inicio para calcular velocidad
					'start_time' => (int) ($phase1_info['start_time'] ?? 0),
					// ✅ NUEVO: Información técnica (thumbnails, memoria)
					'thumbnails_disabled' => !empty($phase1_info['thumbnails_disabled']) ? true : false,
					'memory_limit_increased' => !empty($phase1_info['memory_limit_increased']) ? true : false,
					'memory_limit_original' => !empty($phase1_info['memory_limit_original']) ? $phase1_info['memory_limit_original'] : null,
					'memory_limit_new' => !empty($phase1_info['memory_limit_new']) ? $phase1_info['memory_limit_new'] : null
				],
				// ✅ NUEVO: Métricas de limpieza de caché para Fase 2
				'last_cleanup_metrics' => $sync_info['current_sync']['last_cleanup_metrics'] ?? null
			];
			
		// ✅ REMOVIDO: Debug innecesario que se ejecuta en cada petición (cada 2 segundos)
			
			wp_send_json_success($response);
		} catch (\Throwable $e) {
			// Manejo unificado de excepciones
			self::handleException($e, 'get_sync_progress_callback');
		}
	}

	/**
	 * Maneja la solicitud de cancelación de sincronización vía AJAX
     *
     * Este método procesa las solicitudes de cancelación de operaciones de sincronización
     * en curso, delegando la lógica principal a la clase Sync_Manager.
     *
     * @return void
     * @since 1.0.0
     * @see \MiIntegracionApi\Core\Sync_Manager::cancel_sync() Para la implementación real de la cancelación
     * @uses wp_send_json_success() Para enviar la respuesta exitosa
     * 
     * @example
     * ```javascript
     * // Ejemplo de llamada AJAX para cancelar sincronización
     * jQuery.ajax({
     *     url: ajaxurl,
     *     type: 'POST',
     *     data: {
     *         action: 'mia_sync_cancel',
     *         nonce: 'nonce_seguro'
     *     },
     *     success: function(response) {
     *         console.log('Sincronización cancelada:', response.data);
     *     }
     * });
     * ```
     *
     * @security Verifica el nonce de seguridad y los permisos del usuario
     * @permission Requiere la capacidad 'manage_options' por defecto
     * @throws \Throwable Captura cualquier excepción y la maneja apropiadamente
     */
	public static function sync_cancel_callback(): void {
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return; // validateAjaxSecurity already sends the error response
		}
		
		try {
			// Delegar a Sync_Manager
			$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
			$result = $sync_manager->cancel_sync();
			
			wp_send_json_success($result);
		} catch (\Throwable $e) {
			// Manejo unificado de excepciones
			self::handleException($e, 'sync_cancel_callback');
		}
	}

	public static function test_api(): void {
		// Logging completo de la llamada para identificar el origen
		$debug_info = [
			'timestamp' => date('Y-m-d H:i:s'),
			'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
			'request_uri' => $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN',
			'referer' => $_SERVER['HTTP_REFERER'] ?? 'UNKNOWN',
			'post_data' => $_POST ?? [],
			'is_ajax' => wp_doing_ajax(),
			'current_user_id' => get_current_user_id(),
			'stack_trace' => wp_debug_backtrace_summary()
		];
		
		// Log detallado para depuración
		$logger = new \MiIntegracionApi\Helpers\Logger('api-test-debug');
		$logger->info('Test API ejecutado', $debug_info);
		
		// También log de error para que aparezca en los logs principales
		// ✅ REMOVIDO: Debug innecesario (la información ya se registra en el logger)
		
		// Use the unified security validation instead of check_ajax_referer
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return; // validateAjaxSecurity already sends the error response
		}
		
		// Verificar si es una llamada de diagnóstico automático (no autorizada)
		$is_diagnostic = isset($_POST['diagnostic']) && $_POST['diagnostic'];
		if ($is_diagnostic) {
			$logger->info('Llamada de diagnóstico automático detectada y bloqueada');
			// Las llamadas de diagnóstico automático no deben ejecutar pruebas de conexión reales
			wp_send_json_success([
				'message' => 'Diagnóstico deshabilitado para evitar errores automáticos',
				'resultado' => [
					'estado' => 'skipped',
					'mensaje' => 'Prueba de conexión automática deshabilitada',
					'detalles' => ['razon' => 'Evitar errores HTTP 405 en carga de página']
				]
			]);
			return;
		}
		
		// Usar logger para diagnósticos
		$logger = new \MiIntegracionApi\Helpers\Logger('api-test');
		$logger->info('Iniciando prueba de API desde AJAX');
		
		// Obtener tipo de prueba (si es diagnóstico específico para error de INI)
		$diagnostico_ini = isset($_POST['diagnostico_ini']) ? (bool)$_POST['diagnostico_ini'] : false;
		
		try {
			// Cargar el conector de API
			$connector = \MiIntegracionApi\Core\ApiConnector::get_instance();
			
			if ($diagnostico_ini) {
				// Realizar diagnóstico específico para problema de fichero INI
				$logger->info('Realizando diagnóstico específico para error de fichero INI');
				$resultado = $connector->diagnosticar_error_ini_servicio();
				wp_send_json_success([
					'message' => 'Diagnóstico completado',
					'resultado' => $resultado
				]);
			} else {
				// Prueba estándar de conexión
				$logger->info('Realizando diagnóstico de conexión estándar');
				$resultado = $connector->diagnosticarConexion();
				
				if ($resultado['estado'] === 'success') {
					wp_send_json_success([
						'message' => __('Conexión OK', 'mi-integracion-api'),
						'resultado' => $resultado
					]);
				} else {
					wp_send_json_error([
						'message' => $resultado['mensaje'] ?: __('No se pudo conectar con la API de Verial', 'mi-integracion-api'),
						'resultado' => $resultado
					]);
				}
			}
		} catch (\Exception $e) {
			$logger->error('Excepción en prueba de API: ' . $e->getMessage(), [
				'exception' => get_class($e),
				'trace' => $e->getTraceAsString()
			]);
			
			wp_send_json_error([
				'message' => 'Error: ' . $e->getMessage(),
				'error_type' => get_class($e)
			]);
		}
	}

	public static function clear_cache(): void {
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return; // validateAjaxSecurity already sends the error response
		}
		
		// Verificar que la función wp_cache_flush existe antes de usarla
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}
		
		self::sendSuccess([], __('Caché limpiada correctamente.', 'mi-integracion-api'));
	}
	
	/**
     * Realiza un diagnóstico avanzado para el error de configuración de servicio INI
     *
     * Este método está diseñado específicamente para diagnosticar y solucionar problemas
     * relacionados con la configuración del servicio cuando se presenta el error
     * "No existe el fichero INI del servicio". Realiza verificaciones exhaustivas
     * y devuelve información detallada para facilitar la resolución de problemas.
     *
     * @return void Este método no devuelve ningún valor directamente, sino que envía
     *             una respuesta JSON con el resultado del diagnóstico.
     *
     * @since 1.0.0
     * @uses \MiIntegracionApi\Helpers\Logger Para el registro detallado de eventos
     * @uses \MiIntegracionApi\Core\ApiConnector Para la conexión con la API y diagnóstico
     * @uses self::validateAjaxSecurity() Para verificación de seguridad AJAX
     * @uses current_user_can() Para verificación de permisos de usuario
     * @uses wp_send_json_success() Para enviar respuesta exitosa
     *
     * @example
     * ```javascript
     * // Ejemplo de llamada AJAX
     * jQuery.ajax({
     *     url: ajaxurl,
     *     type: 'POST',
     *     data: {
     *         action: 'diagnostico_ini',
     *         nonce: mi_vars.nonce
     *     },
     *     success: function(response) {
     *         console.log('Resultado del diagnóstico:', response.data.resultado);
     *     }
     * });
     * ```
     *
     * @security check_admin_referer() Validación de nonce de seguridad
     * @permission manage_options Se requiere capacidad de administrador
     *
     * @todo Considerar añadir límite de frecuencia para prevenir uso excesivo
     * @todo Implementar caché para resultados de diagnóstico recurrentes
     */
	public static function diagnostico_ini(): void {
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return; // validateAjaxSecurity already sends the error response
		}
		
		if (!current_user_can('manage_options')) {
			self::sendError(__('No tienes permisos para realizar esta acción', 'mi-integracion-api'), 'insufficient_permissions', 403);
			return;
		}
		
		$logger = new \MiIntegracionApi\Helpers\Logger('diagnostico-ini');
		$logger->info('Iniciando diagnóstico especializado para error de INI');
		
		try {
			// Cargar el conector de API con versión de URL específica para diagnóstico
			$connector = \MiIntegracionApi\Core\ApiConnector::get_instance();
			$resultado = $connector->diagnosticar_error_ini_servicio();
			
			$logger->info('Diagnóstico completado', ['resultado' => $resultado]);
			
			wp_send_json_success([
				'message' => 'Diagnóstico completado',
				'resultado' => $resultado
			]);
		} catch (\Exception $e) {
			$logger->error('Error durante diagnóstico INI: ' . $e->getMessage(), [
				'exception' => get_class($e),
				'trace' => $e->getTraceAsString()
			]);
			
			wp_send_json_error([
				'message' => 'Error durante el diagnóstico: ' . $e->getMessage(),
				'error_type' => get_class($e)
			]);
		}
	}
	
	/**
     * Herramienta de resolución automática para el error de fichero INI del servicio
     *
     * Este método implementa un sistema de auto-reparación que prueba múltiples variantes
     * de configuración para resolver automáticamente el error "No existe el fichero INI".
     * Realiza pruebas con diferentes formatos de URL y parámetros hasta encontrar una
     * configuración funcional, actualizando automáticamente la configuración si tiene éxito.
     *
     * @return void Este método no devuelve ningún valor directamente, sino que envía
     *             una respuesta JSON con el resultado de la operación.
     *
     * @since 1.0.0
     * @uses \MiIntegracionApi\Helpers\Logger Para el registro detallado de eventos
     * @uses \MiIntegracionApi\Core\ApiConnector Para la conexión con la API y resolución de errores
     * @uses self::validateAjaxSecurity() Para verificación de seguridad AJAX
     * @uses current_user_can() Para verificación de permisos de usuario
     * @uses wp_send_json_success() Para enviar respuesta exitosa
     * @uses wp_send_json_error() Para enviar respuestas de error
     *
     * @example
     * ```javascript
     * // Ejemplo de llamada AJAX
     * jQuery.ajax({
     *     url: ajaxurl,
     *     type: 'POST',
     *     data: {
     *         action: 'resolver_error_ini',
     *         nonce: mi_vars.nonce
     *     },
     *     success: function(response) {
     *         console.log('Resolución completada:', response.data);
     *     }
     * });
     * ```
     *
     * @security check_admin_referer() Validación de nonce de seguridad
     * @permission manage_options Se requiere capacidad de administrador
     *
     * @todo Implementar rollback automático si la nueva configuración falla
     * @todo Añadir límite de intentos para prevenir bucles infinitos
     * @todo Considerar añadir un modo "solo prueba" que no guarde cambios
     *
     * @see self::diagnostico_ini() Para diagnóstico previo sin realizar cambios
     */
	public static function resolver_error_ini(): void {
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return; // validateAjaxSecurity already sends the error response
		}
		
		if (!current_user_can('manage_options')) {
			self::sendError(__('No tienes permisos para realizar esta acción', 'mi-integracion-api'), 'insufficient_permissions', 403);
			return;
		}
		
		$logger = new \MiIntegracionApi\Helpers\Logger('resolver-error-ini');
		$logger->info('Iniciando herramienta de resolución para error de fichero INI');
		
		try {
			// Cargar el conector de API
			$connector = \MiIntegracionApi\Core\ApiConnector::get_instance();
			
			// Obtener configuración actual
			$url_actual = $connector->get_api_base_url();
			$sesion = $connector->getSesionWcf();
			
			$logger->info('Configuración actual', [
				'url' => $url_actual,
				'sesion' => $sesion
			]);
			
			// Ejecutar pruebas exhaustivas
			$resultados_pruebas = $connector->probar_diferentes_url_ini();
			
			// Buscar una variación exitosa
			$url_exitosa = null;
			$variacion_exitosa = null;
			
			foreach ($resultados_pruebas['variaciones'] as $tipo => $prueba) {
				if ($prueba['resultado'] === 'éxito') {
					$url_exitosa = $prueba['url'];
					$variacion_exitosa = $tipo;
					break;
				}
			}
			
			// Si encontramos una URL exitosa, informar (ya no se guarda en WordPress)
			if ($url_exitosa) {
				$logger->info('Encontrada URL exitosa', [
					'tipo' => $variacion_exitosa,
					'url' => $url_exitosa
				]);
				
				// Realizar una prueba final para verificar
				$connector = \MiIntegracionApi\Core\ApiConnector::get_instance(); // Reiniciar para cargar nueva config
				$test_final = $connector->test_connectivity();
				
				wp_send_json_success([
					'message' => '¡Problema identificado! Se ha encontrado una URL que funciona, pero la configuración está fija en el código.',
					'url_original' => $url_actual,
					'url_exitosa' => $url_exitosa,
					'resultados_pruebas' => $resultados_pruebas,
					'prueba_final' => $test_final === true ? 'exitosa' : $test_final
				]);
			} else {
				// Si no se encontró solución automática
				$logger->warning('No se encontró ninguna variación de URL exitosa');
				
				// Intentar el método estándar de corrección
				$correcto = $connector->intentar_corregir_url_ini();
				
				if ($correcto) {
					$nueva_url = $connector->get_api_base_url();
					
					wp_send_json_success([
						'message' => 'Se ha corregido la URL mediante el método estándar (configuración fija)',
						'url_original' => $url_actual,
						'url_corregida' => $nueva_url,
						'resultados_pruebas' => $resultados_pruebas
					]);
				} else {
					wp_send_json_error([
						'message' => 'No se pudo resolver automáticamente el problema. La configuración está fija en el código.',
						'sugerencias' => [
							'La configuración actual usa valores fijos: URL=' . (new \VerialApiConfig())->getVerialBaseUrl() . ' y Sesión=' . (new \VerialApiConfig())->getVerialSessionId(),
							'Si necesita usar otros valores, contacte con el desarrollador del plugin.',
							'Confirme que el servidor de test de Verial esté funcionando correctamente.'
						],
						'resultados_pruebas' => $resultados_pruebas
					]);
				}
			}
		} catch (\Exception $e) {
			$logger->error('Error durante la resolución: ' . $e->getMessage(), [
				'exception' => get_class($e),
				'trace' => $e->getTraceAsString()
			]);
			
			wp_send_json_error([
				'message' => 'Error durante la resolución: ' . $e->getMessage(),
				'error_type' => get_class($e)
			]);
		}
	}

	/**
     * Carga las opciones de filtro para la interfaz de usuario
     *
     * Este método obtiene las categorías y fabricantes disponibles desde la API externa
     * para poblar los filtros en la interfaz de administración. Los datos se devuelven
     * en un formato estandarizado para su uso en componentes de selección.
     *
     * @return void Este método no devuelve ningún valor directamente, sino que envía
     *             una respuesta JSON con la estructura:
     *             {
     *                 'categories': [
     *                     {id: string, nombre: string},
     *                     ...
     *                 ],
     *                 'manufacturers': [
     *                     {id: string, nombre: string},
     *                     ...
     *                 ]
     *             }
     *
     * @since 1.0.0
     * @uses \MiIntegracionApi\Core\ApiConnector Para la conexión con la API externa
     * @uses MI_Endpoint_GetCategoriasWS Para obtener las categorías
     * @uses MI_Endpoint_GetFabricantesWS Para obtener los fabricantes
     * @uses self::validateAjaxSecurity() Para validación de seguridad AJAX
     * @uses current_user_can() Para verificación de permisos
     *
     * @example
     * ```javascript
     * // Ejemplo de llamada AJAX
     * jQuery.ajax({
     *     url: ajaxurl,
     *     type: 'POST',
     *     data: {
     *         action: 'load_filter_options',
     *         nonce: mi_vars.nonce
     *     },
     *     success: function(response) {
     *         console.log('Opciones de filtro:', response.data);
     *     }
     * });
     * ```
     *
     * @security check_admin_referer() Validación de nonce de seguridad
     * @permission manage_woocommerce Se requieren permisos de administrador
     *
     * @todo Implementar caché para los resultados de la API
     * @todo Añadir manejo de errores más detallado
     * @todo Añadir filtros para modificar la estructura de respuesta
     *
     * @see MI_Endpoint_GetCategoriasWS Para la estructura de respuesta de categorías
     * @see MI_Endpoint_GetFabricantesWS Para la estructura de respuesta de fabricantes
     */
	public static function load_filter_options(): void {
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return; // validateAjaxSecurity already sends the error response
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			self::sendError(__('No tienes permisos suficientes.', 'mi-integracion-api'), 'insufficient_permissions', 403);
			return;
		}
		if ( ! class_exists( 'MiIntegracionApi\\Core\\ApiConnector' ) ) {
			require_once dirname( __DIR__ ) . '/ApiConnector.php';
		}
		if ( ! class_exists( 'MI_Endpoint_GetCategoriasWS' ) ) {
			require_once dirname( __DIR__ ) . '/endpoints/GetCategoriasWS.php';
		}
		if ( ! class_exists( 'MI_Endpoint_GetFabricantesWS' ) ) {
			require_once dirname( __DIR__ ) . '/endpoints/GetFabricantesWS.php';
		}
		$connector     = new \MiIntegracionApi\Core\ApiConnector();
		$sesion        = get_option( 'mi_integracion_api_numero_sesion' );
		$categories    = array();
		$manufacturers = array();
		if ( class_exists( 'MI_Endpoint_GetCategoriasWS' ) ) {
			$endpoint = new \MI_Endpoint_GetCategoriasWS( $connector );
			$response = $endpoint->execute_restful( (object) array( 'sesionwcf' => $sesion ) );
			if ( $response instanceof \WP_REST_Response ) {
				$data = $response->get_data();
				if ( is_array( $data ) ) {
					foreach ( $data as $cat ) {
						$categories[] = array(
							'id'     => $cat['IdCategoria'] ?? $cat['id'] ?? $cat['id_categoria'] ?? '',
							'nombre' => $cat['Nombre'] ?? $cat['nombre'] ?? '',
						);
					}
				}
			}
		}
		if ( class_exists( 'MI_Endpoint_GetFabricantesWS' ) ) {
			$endpoint = new \MI_Endpoint_GetFabricantesWS( $connector );
			$response = $endpoint->execute_restful( (object) array( 'sesionwcf' => $sesion ) );
			if ( $response instanceof \WP_REST_Response ) {
				$data = $response->get_data();
				if ( is_array( $data ) ) {
					foreach ( $data as $fab ) {
						$manufacturers[] = array(
							'id'     => $fab['id'] ?? $fab['Id'] ?? '',
							'nombre' => $fab['nombre'] ?? $fab['Nombre'] ?? '',
						);
					}
				}
			}
		}
		self::sendSuccess([
			'categories'    => $categories,
			'manufacturers' => $manufacturers,
		]);
	}	
	
	/**
     * Formatea un intervalo de tiempo en segundos a un formato legible
     *
     * Este método convierte un número de segundos en una cadena legible que representa
     * el intervalo de tiempo en horas, minutos y segundos, utilizando las funciones
     * de traducción de WordPress para soporte multilingüe.
     *
     * @param int $seconds Número de segundos a formatear. Valores negativos se tratan como 0,
     *                    y valores superiores a MAX_ELAPSED_TIME se limitan a este valor.
     *
     * @return string Cadena formateada que representa el tiempo transcurrido en el formato:
     *              - Menos de 1 minuto: "X segundos"
     *              - Menos de 1 hora: "X minutos, Y segundos"
     *              - 1 hora o más: "X horas, Y minutos"
     *
     * @since 1.0.0
     * @uses _n() Para la correcta pluralización de las unidades de tiempo
     * @see self::MAX_ELAPSED_TIME Para el límite máximo de tiempo manejado
     *
     * @example
     * ```php
     * echo self::format_elapsed_time(45);
     * // Devuelve: "45 segundos"
     *
     * echo self::format_elapsed_time(125);
     * // Devuelve: "2 minutos, 5 segundos"
     *
     * echo self::format_elapsed_time(3725);
     * // Devuelve: "1 hora, 2 minutos"
     * ```
     *
     * @note Este método está optimizado para mostrar tiempos razonables en interfaces de usuario.
     *       Para mediciones precisas de tiempo, considere usar unidades más pequeñas.
     *
     * @todo Añadir soporte para días y semanas
     * @todo Considerar añadir un parámetro para personalizar el formato de salida
     */
	private static function format_elapsed_time($seconds): string {
		// CORRECCIÓN: Validar y limitar valores de entrada para evitar conversiones problemáticas
		$seconds = (int) $seconds;
		
		// Limitar a valores razonables usando constante
		if ($seconds < 0) {
			$seconds = 0;
		} elseif ($seconds > self::MAX_ELAPSED_TIME) {
			$seconds = self::MAX_ELAPSED_TIME;
		}
		
		if ($seconds < 60) {
			return sprintf(_n('%s segundo', '%s segundos', $seconds, 'mi-integracion-api'), $seconds);
		} elseif ($seconds < 3600) {
			$minutes = (int) floor($seconds / 60);
			$secs = $seconds % 60;
			return sprintf(
				_n('%s minuto', '%s minutos', $minutes, 'mi-integracion-api') . ', ' .
				_n('%s segundo', '%s segundos', $secs, 'mi-integracion-api'),
				$minutes, $secs
			);
		} else {
			$hours = (int) floor($seconds / 3600);
			$minutes = (int) floor(($seconds % 3600) / 60);
			return sprintf(
				_n('%s hora', '%s horas', $hours, 'mi-integracion-api') . ', ' .
				_n('%s minuto', '%s minutos', $minutes, 'mi-integracion-api'),
				$hours, $minutes
			);
		}
	}

    /**
     * Maneja la sincronización de productos por lotes a través de AJAX
     *
     * Este método actúa como punto de entrada para las solicitudes de sincronización de productos.
     * Se encarga de la validación de seguridad, el enrutamiento de la solicitud y el manejo
     * de respuestas, delegando la lógica de negocio a la clase Sync_Manager.
     *
     * @return void Este método no devuelve ningún valor directamente, sino que envía
     *             una respuesta JSON con el resultado de la operación.
     *
     * @since 1.0.0
     * @uses \MiIntegracionApi\Core\Sync_Manager Para la lógica de sincronización
     * @uses \MiIntegracionApi\Helpers\SecurityValidator Para el manejo seguro de respuestas
     * @uses self::validateAjaxSecurity() Para validación de seguridad AJAX
     *
     * @example
     * ```javascript
     * // Ejemplo de llamada AJAX
     * jQuery.ajax({
     *     url: ajaxurl,
     *     type: 'POST',
     *     data: {
     *         action: 'sync_products_batch',
     *         nonce: mi_vars.nonce,
     *         filters: {
     *             // Filtros opcionales para la sincronización
     *             'category': 'electronics',
     *             'status': 'publish'
     *         }
     *     },
     *     success: function(response) {
     *         console.log('Sincronización completada:', response.data);
     *     }
     * });
     * ```
     *
     * @security check_admin_referer() Validación de nonce de seguridad
     * @permission manage_woocommerce Se requieren permisos de WooCommerce
     *
     * @todo Añadir soporte para cancelación de sincronización en curso
     * @todo Implementar sistema de cola para grandes volúmenes de productos
     * @todo Añadir métricas de rendimiento
     *
     * @see \MiIntegracionApi\Core\Sync_Manager::handle_sync_request() Para la lógica de sincronización
     * @see \MiIntegracionApi\Helpers\SecurityValidator Para el manejo seguro de respuestas
     *
     * @throws \Exception En caso de errores inesperados durante el proceso
     */
    public static function sync_products_batch(): void {
        
        // Validación básica de seguridad
        if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
            // ✅ REMOVIDO: Debug innecesario
            return;
        }

        // ✅ REMOVIDO: Debug innecesario

        try {
            // Extraer parámetros
            $filters = $_REQUEST['filters'] ?? [];
            
            // ✅ REMOVIDO: Debug innecesario
            
            // Delegar toda la lógica de producción a Sync_Manager
            $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
            $result = $sync_manager->handle_sync_request($filters);
            
            // ✅ REMOVIDO: Debug innecesario
            
            // Enviar respuesta usando SecurityValidator (con adaptador inteligente)
            if (is_wp_error($result)) {
                \MiIntegracionApi\Helpers\SecurityValidator::sendWpError($result);
            } else {
                \MiIntegracionApi\Helpers\SecurityValidator::sendAjaxSuccess($result);
            }

        } catch (\Throwable $e) {
            // ✅ REMOVIDO: Debug innecesario (el error ya se registra en el logger)
            \MiIntegracionApi\Helpers\SecurityValidator::sendAjaxError('Error interno: ' . $e->getMessage());
        }
    }

    /**
     * Procesa el siguiente lote de sincronización (Obsoleto)
     *
     * @deprecated 2.0.0 Este endpoint ha sido reemplazado por un sistema de procesamiento automático.
     *             El backend ahora procesa todos los lotes secuencialmente sin necesidad de llamadas AJAX.
     *             Se mantiene únicamente por compatibilidad con versiones anteriores.
     *
     * @return void Este método no devuelve ningún valor directamente, sino que envía
     *             una respuesta JSON indicando que el endpoint está obsoleto.
     *
     * @since 1.0.0
     * @deprecated 2.0.0 Usar el sistema de procesamiento automático de lotes
     *
     * @example
     * ```javascript
     * // NO USAR - Este endpoint está obsoleto
     * jQuery.ajax({
     *     url: ajaxurl,
     *     type: 'POST',
     *     data: {
     *         action: 'process_next_batch',
     *         nonce: mi_vars.nonce
     *     },
     *     success: function(response) {
     *         console.log('Respuesta:', response);
     *     }
     * });
     * ```
     *
     * @see self::process_sync_batch_cron() Para el nuevo sistema de procesamiento automático
     * @see wp_schedule_single_event() Para programar tareas de procesamiento
     *
     * @todo Eliminar este método en la versión 3.0.0
     * @todo Actualizar cualquier referencia a este método en el código cliente
     */
    public static function process_next_batch(): void {
        // Este endpoint se mantiene por compatibilidad pero no debe ser usado
        wp_send_json_error([
            'message' => 'Este endpoint está deprecated - el backend procesa automáticamente todos los lotes',
            'deprecated' => true
        ]);
    }

	/**
     * Maneja la actualización del tamaño de lote para operaciones de sincronización
     *
     * Este método procesa las solicitudes AJAX para actualizar el tamaño de los lotes
     * utilizados en las operaciones de sincronización. Valida los permisos, procesa
     * los parámetros de entrada y actualiza la configuración del sistema.
     *
     * @return void Este método no devuelve ningún valor directamente, sino que envía
     *             una respuesta JSON con el resultado de la operación.
     *
     * @since 1.0.0
     * @uses \MiIntegracionApi\Helpers\BatchSizeHelper Para la gestión centralizada de tamaños de lote
     * @uses \MiIntegracionApi\Helpers\Logger Para el registro de actividades
     * @uses self::validateAjaxSecurity() Para verificación de seguridad AJAX
     * @uses current_user_can() Para verificación de permisos de usuario
     * @uses sanitize_text_field() Para limpieza de entrada de datos
     * @uses self::sendSuccess() Para enviar respuesta exitosa
     *
     * @example
     * ```javascript
     * // Ejemplo de llamada AJAX
     * jQuery.ajax({
     *     url: ajaxurl,
     *     type: 'POST',
     *     data: {
     *         action: 'save_batch_size',
     *         entity: 'productos',
     *         batch_size: 50,
     *         nonce: mi_vars.nonce
     *     },
     *     success: function(response) {
     *         console.log('Tamaño de lote actualizado:', response.data);
     *     }
     * });
     * ```
     *
     * @security check_admin_referer() Validación de nonce de seguridad
     * @permission manage_options Se requiere capacidad de administrador
     *
     * @todo Añadir validación de entidades permitidas
     * @todo Implementar límites configurables para el tamaño de lote
     * @todo Añadir filtros para personalizar el comportamiento
     *
     * @see BatchSizeHelper::validateBatchSize() Para las reglas de validación
     * @see BatchSizeHelper::setBatchSize() Para el almacenamiento de la configuración
     */
	public static function save_batch_size(): void {
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return; // validateAjaxSecurity already sends the error response
		}
		
		if (!current_user_can('manage_options')) {
			self::sendError(__('Permisos insuficientes', 'mi-integracion-api'), 'insufficient_permissions', 403);
			return;
		}
		
		$entity = isset($_POST['entity']) ? sanitize_text_field($_POST['entity']) : 'productos';
		$batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 100;
		
		// REFACTORIZADO: Usar BatchSizeHelper directamente - elimina duplicación
		// Validar el valor usando la configuración centralizada
		$batch_size = \MiIntegracionApi\Helpers\BatchSizeHelper::validateBatchSize($entity, $batch_size);
		
		// Usar BatchSizeHelper para establecer el tamaño de lote
		\MiIntegracionApi\Helpers\BatchSizeHelper::setBatchSize($entity, $batch_size);
		
		// Registrar la acción
		$logger = new \MiIntegracionApi\Helpers\Logger('batch-size');
		$logger->info("Tamaño de lote actualizado para {$entity}", [
			'old_value' => get_option('mi_integracion_api_batch_size_' . $entity, 'no_set'),
			'new_value' => $batch_size,
			'user_id' => get_current_user_id()
		]);
		
		self::sendSuccess([
			'batch_size' => $batch_size
		], sprintf(__('Tamaño de lote actualizado a %d para %s', 'mi-integracion-api'), $batch_size, $entity));
	}

	/**
	 * Actualiza el delay de throttling para la sincronización de imágenes
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function update_throttle_delay(): void {
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return; // validateAjaxSecurity already sends the error response
		}

		if (!current_user_can('manage_options')) {
			self::sendError(__('Permisos insuficientes', 'mi-integracion-api'), 'insufficient_permissions', 403);
			return;
		}

		$delay = isset($_POST['delay']) ? (float)$_POST['delay'] : 0.01;

		// Validar rango: 0 (sin throttling) a 5.0 segundos (5000ms)
		if ($delay < 0 || $delay > 5.0) {
			self::sendError(
				__('El delay de throttling debe estar entre 0 y 5.0 segundos', 'mi-integracion-api'),
				'invalid_delay',
				400
			);
			return;
		}

		// Guardar en la opción
		$result = update_option('mia_images_sync_throttle_delay', $delay);

		if ($result) {
			// Registrar la acción
			$logger = new \MiIntegracionApi\Helpers\Logger('throttle-delay');
			$logger->info('Delay de throttling actualizado', [
				'old_value' => get_option('mia_images_sync_throttle_delay', 'no_set'),
				'new_value' => $delay,
				'new_value_ms' => round($delay * 1000, 0),
				'user_id' => get_current_user_id()
			]);

			self::sendSuccess([
				'delay' => $delay,
				'delay_ms' => round($delay * 1000, 0)
			], sprintf(__('Delay de throttling actualizado a %.2f segundos (%d ms)', 'mi-integracion-api'), $delay, round($delay * 1000, 0)));
		} else {
			self::sendError(__('Error al actualizar el delay de throttling', 'mi-integracion-api'), 'update_failed', 500);
		}
	}

	/**
	 * Actualiza la configuración de reintento automático
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function update_auto_retry(): void {
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return; // validateAjaxSecurity already sends the error response
		}

		if (!current_user_can('manage_options')) {
			self::sendError(__('Permisos insuficientes', 'mi-integracion-api'), 'insufficient_permissions', 403);
			return;
		}

		$auto_retry = isset($_POST['auto_retry']) && ($_POST['auto_retry'] === 'true' || $_POST['auto_retry'] === true || $_POST['auto_retry'] === '1');

		// Guardar en la opción
		$result = update_option('mia_sync_auto_retry', $auto_retry);

		if ($result) {
			// Registrar la acción
			$logger = new \MiIntegracionApi\Helpers\Logger('auto-retry');
			$logger->info('Configuración de reintento automático actualizada', [
				'old_value' => get_option('mia_sync_auto_retry', 'no_set'),
				'new_value' => $auto_retry,
				'user_id' => get_current_user_id()
			]);

			self::sendSuccess([
				'auto_retry' => $auto_retry
			], $auto_retry 
				? __('Reintento automático activado', 'mi-integracion-api')
				: __('Reintento automático desactivado', 'mi-integracion-api'));
		} else {
			self::sendError(__('Error al actualizar la configuración de reintento automático', 'mi-integracion-api'), 'update_failed', 500);
		}
	}

	/**
	 * Optimiza los índices de base de datos para búsqueda de duplicados de imágenes.
	 *
	 * Crea índices compuestos en wp_postmeta para acelerar la búsqueda de duplicados
	 * a medida que crece la base de datos. Esto mejora significativamente el rendimiento
	 * de la Fase 1 cuando se procesan grandes volúmenes de imágenes.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public static function optimize_image_duplicates_indexes(): void
	{
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return; // validateAjaxSecurity already sends the error response
		}

		if (!current_user_can('manage_options')) {
			self::sendError(__('Permisos insuficientes', 'mi-integracion-api'), 'insufficient_permissions', 403);
			return;
		}

		try {
			$optimizer = new \MiIntegracionApi\Helpers\OptimizeImageDuplicatesSearch();
			$result = $optimizer->createOptimizedIndexes();

			if ($result['success']) {
				$message = sprintf(
					__('Optimización completada: %d índices creados, %d ya existían', 'mi-integracion-api'),
					count($result['indexes_created']),
					count($result['indexes_existing'])
				);

				self::sendSuccess([
					'indexes_created' => $result['indexes_created'],
					'indexes_existing' => $result['indexes_existing'],
					'total_indexes' => count($result['indexes_created']) + count($result['indexes_existing'])
				], $message);
			} else {
				$error_message = !empty($result['errors'])
					? implode(', ', $result['errors'])
					: __('Error desconocido al optimizar índices', 'mi-integracion-api');

				self::sendError(
					$error_message,
					'optimization_failed',
					500,
					['errors' => $result['errors']]
				);
			}
		} catch (\Exception $e) {
			self::sendError(
				sprintf(__('Excepción durante optimización: %s', 'mi-integracion-api'), $e->getMessage()),
				'exception_during_optimization',
				500
			);
		}
	}

	/**
	 * Ejecuta un benchmark de rendimiento de búsqueda de duplicados.
	 *
	 * Mide el tiempo promedio de búsqueda de duplicados para evaluar el rendimiento
	 * antes y después de crear los índices optimizados.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public static function benchmark_duplicates_search(): void
	{
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return; // validateAjaxSecurity already sends the error response
		}

		if (!current_user_can('manage_options')) {
			self::sendError(__('Permisos insuficientes', 'mi-integracion-api'), 'insufficient_permissions', 403);
			return;
		}

		try {
			$optimizer = new \MiIntegracionApi\Helpers\OptimizeImageDuplicatesSearch();
			$benchmark = $optimizer->benchmarkSearchPerformance();

			if (isset($benchmark['error'])) {
				self::sendError(
					$benchmark['error'],
					'benchmark_error',
					500
				);
				return;
			}

			if (isset($benchmark['message'])) {
				self::sendSuccess([
					'message' => $benchmark['message'],
					'total_hashes' => $benchmark['total_hashes']
				], $benchmark['message']);
				return;
			}

			$message = sprintf(
				__('Benchmark completado: Tiempo promedio %s ms (min: %s ms, max: %s ms) para %d hashes', 'mi-integracion-api'),
				$benchmark['average_time_ms'],
				$benchmark['min_time_ms'] ?? 'N/A',
				$benchmark['max_time_ms'] ?? 'N/A',
				$benchmark['total_hashes']
			);

			self::sendSuccess($benchmark, $message);
		} catch (\Exception $e) {
			self::sendError(
				sprintf(__('Excepción durante benchmark: %s', 'mi-integracion-api'), $e->getMessage()),
				'exception_during_benchmark',
				500
			);
		}
	}
	
	/**
     * Procesa un lote de sincronización programado por WordPress Cron
     *
     * Este método es el manejador principal para la ejecución programada de tareas de sincronización
     * a través del sistema de WP-Cron. Se encarga de procesar lotes de elementos pendientes de
     * sincronización de manera eficiente, con manejo de errores y registro de actividad.
     *
     * @param mixed $args Argumentos del cron job. Puede ser:
     *                   - Un array asociativo con parámetros de configuración
     *                   - Un string que será decodificado como JSON
     *                   - Un valor nulo o vacío para usar valores por defecto
     *
     * @return void Este método no devuelve ningún valor directamente, pero puede generar
     *             registros de depuración y actualizar el estado de sincronización.
     *
     * @since 1.0.0
     * @uses \MiIntegracionApi\Helpers\Logger Para el registro detallado de eventos
     * @uses wp_next_scheduled() Para verificar la programación del siguiente lote
     * @uses wp_schedule_single_event() Para programar el siguiente lote
     * @uses delete_transient() Para limpiar cachés temporales
     *
     * @example
     * ```php
     * // Ejemplo de programación manual
     * wp_schedule_single_event(time(), 'mi_integracion_process_sync_batch', [
     *     'batch_size' => 50,
     *     'force' => false,
     *     'user_id' => get_current_user_id()
     * ]);
     * ```
     *
     * @throws \Exception En caso de errores críticos durante el procesamiento
     *
     * @todo Implementar reintentos automáticos para fallos transitorios
     * @todo Añadir métricas de rendimiento para monitoreo
     * @todo Considerar implementar un sistema de cola más robusto
     *
     * @see wp_schedule_event() Para programar la ejecución recurrente
     * @see add_action('mi_integracion_process_sync_batch', [__CLASS__, 'process_sync_batch_cron'])
     */
	public static function process_sync_batch_cron($args = []): void
	{
		// Normalizar argumentos - WordPress Cron a veces pasa strings
		if (is_string($args)) {
			$args = json_decode($args, true) ?: [];
		} elseif (!is_array($args)) {
			$args = [];
		}
		
		// Configurar timeout centralizado para operaciones de sincronización
		$timeout_seconds = self::getSyncTimeout();
		set_time_limit($timeout_seconds);
		
		// Obtener instancia del logger
		$logger = \MiIntegracionApi\Helpers\Logger::getInstance();
		
		$logger->info('Iniciando procesamiento de lote programado por WordPress Cron', [
			'args' => $args,
			'args_type' => gettype($args),
			'timeout_seconds' => $timeout_seconds,
			'timestamp' => time()
		]);
		
		try {
			// Obtener instancia del Sync_Manager
			$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
			
			// Verificar si hay una sincronización activa
			$sync_info = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
			
			if (empty($sync_info) || !isset($sync_info['in_progress']) || !$sync_info['in_progress']) {
				$logger->info('No hay sincronización activa, cancelando procesamiento de lote', [
					'sync_info' => $sync_info,
					'in_progress' => $sync_info['in_progress'] ?? 'no_in_progress'
				]);
				return;
			}
			
			// Verificar cancelación
			if (\MiIntegracionApi\Helpers\SyncStatusHelper::isCancellationRequested('products')) {
				$logger->info('Cancelación detectada, deteniendo procesamiento de lote');
				$sync_manager->cancel_sync('Cancelación solicitada durante procesamiento programado');
				return;
			}
			
			// Procesar siguiente lote
			$logger->info('Procesando lote de sincronización', [
				'timestamp' => time()
			]);
			
			try {
				$batch_result = $sync_manager->process_all_batches_sync(true);
				
			} catch (\Throwable $e) {
				$logger->error('Error en process_all_batches_sync()', [
					'error' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
					'timestamp' => time()
				]);
				
				$batch_result = [
					'success' => false,
					'error' => $e->getMessage(),
					'completed' => false,
					'status' => 'error',
					'total_processed' => 0
				];
			}
			
			if (is_wp_error($batch_result)) {
				$logger->error('Error en lote programado', [
					'error' => $batch_result->get_error_message(),
					'operation_id' => $sync_info['operation_id'] ?? null
				]);
				
				// Cancelar sincronización en caso de error
				$sync_manager->cancel_sync('Error en lote programado: ' . $batch_result->get_error_message());
				return;
			}
			
			// Extraer datos del SyncResponse o array
			if ($batch_result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface) {
				$batch_data = $batch_result->getData();
			} else {
				$batch_data = $batch_result; // Ya es array
			}
			$completed = $batch_data['completed'] ?? false;
			$status = $batch_data['status'] ?? 'unknown';
			
			$logger->info('Lote programado procesado exitosamente', [
				'completed' => $completed,
				'status' => $status,
				'processed' => $batch_data['total_processed'] ?? 0,
				'operation_id' => $sync_info['operation_id'] ?? null
			]);
			
			// Si no está completado, programar siguiente lote
			if (!$completed) {
				$sync_manager->schedule_next_batch();
			} else {
				// Finalizar sincronización
				$sync_manager->finish_sync();
			}
			
		} catch (\Throwable $e) {
			$logger->error('Error crítico en procesamiento de lote programado', [
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTraceAsString(),
				'timestamp' => time()
			]);
		}
	}
	
	/**
	 * Procesa la cola de sincronización en background
	 * 
	 * Este método maneja las peticiones AJAX para procesar la cola de sincronización
	 * en segundo plano, proporcionando un fallback cuando WordPress Cron no está disponible
	 * o tiene problemas de ejecución.
	 * 
	 * @return void
	 */
	public static function process_queue_background_callback(): void
	{
		// Verificar nonce para seguridad
		// ✅ MEJORADO: Aceptar tanto el nonce específico de la cola como el nonce del dashboard
		$nonce = $_POST['nonce'] ?? '';
		$valid_nonce = wp_verify_nonce($nonce, 'mia_queue_nonce') || 
		               wp_verify_nonce($nonce, 'mi_integracion_api_nonce_dashboard');
		
		if (!$valid_nonce) {
			wp_die('Nonce verification failed', 'Security Error', ['response' => 403]);
		}
		
		// Configurar timeout para operaciones de background
		set_time_limit(300); // 5 minutos
		
		// Obtener logger
		$logger = \MiIntegracionApi\Helpers\Logger::getInstance();
		
		$logger->info('Procesando cola de sincronización en background', [
			'timestamp' => time(),
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
		]);
		
		try {
			// Obtener instancia del Sync_Manager
			$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
			
			// Verificar si hay sincronización activa
			$sync_info = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
			
			if (empty($sync_info) || !isset($sync_info['in_progress']) || !$sync_info['in_progress']) {
				$logger->info('No hay sincronización activa para procesar en background', [
					'sync_info' => $sync_info
				]);
				wp_die('No active sync to process', 'No Sync', ['response' => 200]);
			}
			
			// Verificar cancelación
			if (\MiIntegracionApi\Helpers\SyncStatusHelper::isCancellationRequested('products')) {
				$logger->info('Cancelación detectada en procesamiento de cola background');
				$sync_manager->cancel_sync('Cancelación solicitada durante procesamiento de cola');
				wp_die('Sync cancelled', 'Cancelled', ['response' => 200]);
			}
			
			// Procesar siguiente lote
			$logger->info('Procesando lote desde cola background', [
				'operation_id' => $sync_info['operation_id'] ?? null,
				'current_batch' => $sync_info['current_batch'] ?? 0
			]);
			
			$batch_result = $sync_manager->process_all_batches_sync(true);
			
			// Extraer datos del resultado
			if ($batch_result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface) {
				$batch_data = $batch_result->getData();
			} else {
				$batch_data = $batch_result;
			}
			
			$completed = $batch_data['completed'] ?? false;
			
			$logger->info('Lote procesado desde cola background', [
				'completed' => $completed,
				'processed' => $batch_data['total_processed'] ?? 0,
				'operation_id' => $sync_info['operation_id'] ?? null
			]);
			
			// Si no está completado, programar siguiente lote
			if (!$completed) {
				$sync_manager->schedule_next_batch();
			} else {
				// Finalizar sincronización
				$sync_manager->finish_sync();
			}
			
			// Respuesta exitosa
			wp_die('Queue processed successfully', 'Success', ['response' => 200]);
			
		} catch (\Throwable $e) {
			$logger->error('Error crítico en procesamiento de cola background', [
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTraceAsString(),
				'timestamp' => time()
			]);
			
			wp_die('Queue processing failed: ' . $e->getMessage(), 'Error', ['response' => 500]);
		}
	}
	
	/**
	 * Callback AJAX para sincronización de imágenes (Fase 1 de arquitectura dos fases).
	 *
	 * Este endpoint permite ejecutar la sincronización de imágenes desde el dashboard
	 * de WordPress. Procesa todas las imágenes de productos desde Verial API y las
	 * guarda en la media library con metadatos asociados.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function sync_images_callback(): void {
		// Verificar nonce y permisos usando el método unificado
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return; // validateAjaxSecurity ya envía la respuesta de error
		}

		try {
			// Validar parámetros y clase ImageSyncManager
			$params = self::validateSyncImagesParams();
			if ($params === null) {
				return; // Ya se envió respuesta de error
			}

			$resume = $params['resume'];
			$batch_size = $params['batch_size'];

			// Limpiar flags si es nueva sincronización
			if (!$resume) {
				self::cleanupPhase1FlagsForNewSync();
			}

			// Verificar si ya hay sincronización en progreso
			if (self::checkPhase1InProgress()) {
				return; // Ya se envió respuesta
			}

			// Validar condiciones para resume
			if ($resume && !self::validateResumeConditions()) {
				return; // Ya se envió respuesta de error
			}

			// Inicializar sincronización
			$imageSyncManager = self::initializePhase1Sync($batch_size);

			// Ejecutar sincronización
			$result = $imageSyncManager->syncAllImages($resume, $batch_size);

			// Enviar respuesta exitosa
			self::sendSyncImagesSuccess($result);

		} catch (\Exception $e) {
			self::handleSyncImagesError($e);
		}
	}

	/**
	 * Valida los parámetros de sincronización de imágenes
	 *
	 * @return array<string, mixed>|null Array con 'resume' y 'batch_size' si es válido, null si hay error
	 * @since 1.5.0
	 */
	private static function validateSyncImagesParams(): ?array {
		// Validar que ImageSyncManager esté disponible
		if (!class_exists('\\MiIntegracionApi\\Sync\\ImageSyncManager')) {
			wp_send_json_error([
				'message' => 'ImageSyncManager no está disponible. Verifica que el autoloader esté actualizado.'
			]);
			return null;
		}

		// Obtener parámetros
		$resume = isset($_POST['resume']) && $_POST['resume'] === 'true';
		$batch_size = isset($_POST['batch_size']) ? (int)$_POST['batch_size'] : 10;

		// Validar batch_size
		if ($batch_size < 1 || $batch_size > 100) {
			wp_send_json_error([
				'message' => 'Tamaño de lote inválido. Debe estar entre 1 y 100.'
			]);
			return null;
		}

		return [
			'resume' => $resume,
			'batch_size' => $batch_size
		];
	}

	/**
	 * Limpia los flags de pausa/cancelación y caché para iniciar una nueva sincronización
	 *
	 * ✅ MEJORADO: Añadida limpieza completa del caché del sistema para evitar
	 * datos obsoletos e inconsistencias, consistente con Fase 2.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function cleanupPhase1FlagsForNewSync(): void {
		// Limpiar flag de detención inmediata
		delete_option('mia_images_sync_stop_immediately');
		delete_option('mia_images_sync_stop_timestamp');

		// Limpiar estado de pausa y cancelación
		\MiIntegracionApi\Helpers\SyncStatusHelper::updatePhase1Images([
			'paused' => false,
			'cancelled' => false
		]);

		// ✅ NUEVO: Limpiar caché completo del sistema solo en nuevas sincronizaciones
		// Esto asegura que empezamos con caché limpia y evitamos datos obsoletos
		// No afecta reanudaciones (resume) porque usan checkpoint de BD, no caché
		if (class_exists('\MiIntegracionApi\CacheManager')) {
			$cache_manager = \MiIntegracionApi\CacheManager::get_instance();
			$result = $cache_manager->clear_all_cache();
			
			self::logInfo('🧹 Caché completamente limpiada al inicio de Fase 1', [
				'cleared_count' => $result,
				'reason' => 'fresh_start_for_phase1',
				'stage' => 'initial_cleanup',
				'user_id' => get_current_user_id()
			]);
			
			// ✅ NUEVO: Guardar información de limpieza inicial en estado para mostrar en consola
			\MiIntegracionApi\Helpers\SyncStatusHelper::updatePhase1Images([
				'initial_cache_cleared' => true,
				'initial_cache_cleared_count' => $result,
				'initial_cache_cleared_timestamp' => time()
			]);
		}

		// Limpiar caché de WordPress para asegurar que los cambios se reflejen
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}

		self::logInfo('Flags de pausa/cancelación y caché limpiados para nueva sincronización', [
			'user_id' => get_current_user_id()
		]);
	}

	/**
	 * Verifica si ya hay una sincronización de imágenes en progreso
	 *
	 * @return bool True si hay sincronización en progreso (y ya se envió respuesta), false si no
	 * @since 1.5.0
	 */
	private static function checkPhase1InProgress(): bool {
		$phase1_status = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
		$phase1_images = $phase1_status['phase1_images'] ?? [];

		$is_in_progress = isset($phase1_images['in_progress']) && $phase1_images['in_progress'] === true;
		if (!$is_in_progress) {
			return false;
		}

		// Enviar respuesta de sincronización en progreso
		$products_processed = isset($phase1_images['products_processed']) ? (int)$phase1_images['products_processed'] : 0;
		$total_products = isset($phase1_images['total_products']) ? (int)$phase1_images['total_products'] : 0;

		wp_send_json_success([
			'message' => 'Sincronización de imágenes ya en progreso',
			'in_progress' => true,
			'data' => [
				'products_processed' => $products_processed,
				'total_products' => $total_products
			]
		]);
		return true;
	}

	/**
	 * Valida las condiciones para reanudar una sincronización
	 *
	 * @return bool True si las condiciones son válidas, false si hay error (y ya se envió respuesta)
	 * @since 1.5.0
	 */
	private static function validateResumeConditions(): bool {
		// Verificar flag de detención inmediata
		$stop_immediately = get_option('mia_images_sync_stop_immediately', false);
		if ($stop_immediately) {
			wp_send_json_error([
				'message' => 'Sincronización de imágenes detenida por flag de detención inmediata',
				'stopped' => true
			]);
			return false;
		}

		// Verificar estado de cancelación
		$phase1_status_check = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
		$phase1_images_check = $phase1_status_check['phase1_images'] ?? [];
		if (!empty($phase1_images_check['cancelled']) && $phase1_images_check['cancelled'] === true) {
			wp_send_json_error([
				'message' => 'Sincronización de imágenes fue cancelada. Por favor, inicia una nueva sincronización.',
				'cancelled' => true
			]);
			return false;
		}

		return true;
	}

	/**
	 * Inicializa la sincronización de imágenes y configura el estado inicial
	 *
	 * @param int $batch_size Tamaño de lote para la sincronización
	 * @return \MiIntegracionApi\Sync\ImageSyncManager Instancia del ImageSyncManager
	 * @since 1.5.0
	 */
	private static function initializePhase1Sync(int $batch_size): \MiIntegracionApi\Sync\ImageSyncManager {
		// Obtener instancias necesarias
		$apiConnector = \MiIntegracionApi\Core\ApiConnector::get_instance();
		$logger = new \MiIntegracionApi\Helpers\Logger('image-sync');

		// Log de inicio
		self::logInfo('Iniciando sincronización de imágenes vía AJAX (modo incremental)', [
			'resume' => isset($_POST['resume']) && $_POST['resume'] === 'true',
			'batch_size' => $batch_size,
			'user_id' => get_current_user_id(),
			'note' => 'Las imágenes se procesarán de forma incremental mientras se obtienen los IDs'
		]);

		// Crear instancia de ImageSyncManager
		$imageSyncManager = new \MiIntegracionApi\Sync\ImageSyncManager($apiConnector, $logger);

		// Actualizar estado a "in_progress" INMEDIATAMENTE
		\MiIntegracionApi\Helpers\SyncStatusHelper::updatePhase1Images([
			'in_progress' => true,
			'paused' => false,
			'cancelled' => false,
			'total_products' => 0, // Se actualizará cuando se obtengan los IDs
			'products_processed' => 0,
			'images_processed' => 0,
			'duplicates_skipped' => 0,
			'errors' => 0,
			'last_processed_id' => 0
		]);

		// Limpiar caché para asegurar que el estado se refleje inmediatamente
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}

		// Configurar timeout para proceso largo
		set_time_limit(0);
		ini_set('max_execution_time', '0');
		ignore_user_abort(true);

		return $imageSyncManager;
	}

	/**
	 * Prepara y envía la respuesta exitosa de sincronización de imágenes
	 *
	 * @param array<string, mixed> $result Resultado de la sincronización
	 * @return void
	 * @since 1.5.0
	 */
	private static function sendSyncImagesSuccess(array $result): void {
		$response_data = self::buildSyncImagesResponseData($result);
		$response = [
			'success' => true,
			'message' => 'Sincronización de imágenes completada',
			'data' => $response_data
		];

		// Log de éxito
		self::logInfo('Sincronización de imágenes completada vía AJAX', [
			'result' => $response_data,
			'user_id' => get_current_user_id()
		]);

		wp_send_json_success($response);
	}

	/**
	 * Construye los datos de respuesta para sincronización de imágenes
	 *
	 * @param array<string, mixed> $result Resultado de la sincronización
	 * @return array<string, mixed> Datos de respuesta formateados
	 * @since 1.5.0
	 */
	private static function buildSyncImagesResponseData(array $result): array {
		$total_processed = isset($result['total_processed']) ? (int)$result['total_processed'] : 0;
		$total_attachments = isset($result['total_attachments']) ? (int)$result['total_attachments'] : 0;
		$total_errors = isset($result['errors']) ? (int)$result['errors'] : 0;
		$duplicates_skipped = isset($result['duplicates_skipped']) ? (int)$result['duplicates_skipped'] : 0;
		$checkpoint_saved = isset($result['checkpoint_saved']) ? (bool)$result['checkpoint_saved'] : false;
		$completed = isset($result['completed']) ? (bool)$result['completed'] : false;

		return [
			'total_processed' => $total_processed,
			'total_attachments' => $total_attachments,
			'total_errors' => $total_errors,
			'duplicates_skipped' => $duplicates_skipped,
			'checkpoint_saved' => $checkpoint_saved,
			'completed' => $completed
		];
	}

	/**
	 * Maneja errores durante la sincronización de imágenes
	 *
	 * @param \Exception $e Excepción capturada
	 * @return void
	 * @since 1.5.0
	 */
	private static function handleSyncImagesError(\Exception $e): void {
		self::logError('Error durante sincronización de imágenes vía AJAX', [
			'error' => $e->getMessage(),
			'file' => $e->getFile(),
			'line' => $e->getLine(),
			'trace' => $e->getTraceAsString(),
			'user_id' => get_current_user_id()
		]);

		wp_send_json_error([
			'message' => 'Error durante sincronización de imágenes: ' . $e->getMessage()
		]);
	}

	/**
	 * Cancela la sincronización de imágenes (Fase 1)
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function cancel_images_sync_callback(): void {
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return;
		}

		try {
			self::processCancelPhase1Images();
		} catch (\Exception $e) {
			self::handleCancelPhase1Error($e);
		}
	}

	/**
	 * Procesa la cancelación de sincronización de Fase 1
	 *
	 * @return void
	 * @throws \Exception Si ocurre un error durante el proceso
	 * @since 1.5.0
	 */
	private static function processCancelPhase1Images(): void {
		$phase1_images = self::getPhase1ImagesStatus();
		
		if (!self::isPhase1InProgress($phase1_images)) {
			wp_send_json_error([
				'message' => 'No hay sincronización de imágenes en progreso'
			]);
			return;
		}

		// Establecer flags de detención y limpiar caché
		self::setStopFlagsAndClearCache();
		
		// Actualizar estado a cancelado
		$updated_status = self::markPhase1AsCancelled($phase1_images);
		
		// Eliminar checkpoint
		delete_option('mia_images_sync_checkpoint');
		
		// Registrar evento
		self::logPhase1Cancel($updated_status);
		
		wp_send_json_success([
			'message' => 'Sincronización de imágenes cancelada',
			'phase1_images' => $updated_status
		]);
	}

	/**
	 * Verifica si la sincronización de Fase 1 está en progreso
	 *
	 * @param array<string, mixed> $phase1_images Estado de phase1_images
	 * @return bool True si está en progreso, false en caso contrario
	 * @since 1.5.0
	 */
	private static function isPhase1InProgress(array $phase1_images): bool {
		return !empty($phase1_images['in_progress']) && $phase1_images['in_progress'] === true;
	}

	/**
	 * Establece los flags de detención inmediata y limpia la caché
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function setStopFlagsAndClearCache(): void {
		// Establecer flag de detención inmediata
		update_option('mia_images_sync_stop_immediately', true);
		update_option('mia_images_sync_stop_timestamp', time());
		
		// Limpiar caché para asegurar que el flag se detecte
		self::flushCacheIfAvailable();
		
		// Escribir directamente en base de datos
		self::writeStopFlagToDatabase();
	}

	/**
	 * Limpia la caché si está disponible
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function flushCacheIfAvailable(): void {
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}
	}

	/**
	 * Escribe el flag de detención directamente en la base de datos
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function writeStopFlagToDatabase(): void {
		global $wpdb;
		if (!isset($wpdb) || !$wpdb) {
			return;
		}
		
		$wpdb->query($wpdb->prepare("
			INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
			VALUES (%s, %s, 'yes')
			ON DUPLICATE KEY UPDATE option_value = %s
		", 'mia_images_sync_stop_immediately', '1', '1'));
	}

	/**
	 * Marca la sincronización de Fase 1 como cancelada
	 *
	 * @param array<string, mixed> $phase1_images Estado actual de phase1_images
	 * @return array<string, mixed> Estado actualizado de phase1_images
	 * @since 1.5.0
	 */
	private static function markPhase1AsCancelled(array $phase1_images): array {
		$phase1_images['in_progress'] = false;
		$phase1_images['paused'] = false;
		$phase1_images['cancelled'] = true;
		$phase1_images['last_update'] = time();

		$status = \MiIntegracionApi\Helpers\SyncStatusHelper::getSyncStatus();
		$status['phase1_images'] = $phase1_images;
		\MiIntegracionApi\Helpers\SyncStatusHelper::saveSyncStatus($status);

		return $phase1_images;
	}

	/**
	 * Registra el evento de cancelación de sincronización de Fase 1
	 *
	 * @param array<string, mixed> $phase1_images Estado de phase1_images
	 * @return void
	 * @since 1.5.0
	 */
	private static function logPhase1Cancel(array $phase1_images): void {
		$logger = new \MiIntegracionApi\Helpers\Logger('image-sync');
		$logger->info('Sincronización de imágenes cancelada', [
			'products_processed' => $phase1_images['products_processed'] ?? 0,
			'total_products' => $phase1_images['total_products'] ?? 0,
			'user_id' => get_current_user_id()
		]);
	}

	/**
	 * Maneja errores durante la cancelación de sincronización de Fase 1
	 *
	 * @param \Exception $e Excepción capturada
	 * @return void
	 * @since 1.5.0
	 */
	private static function handleCancelPhase1Error(\Exception $e): void {
		self::logError('Error al cancelar sincronización de imágenes', [
			'error' => $e->getMessage(),
			'user_id' => get_current_user_id()
		]);

		wp_send_json_error([
			'message' => 'Error al cancelar sincronización: ' . $e->getMessage()
		]);
	}

	/**
	 * Reanuda la sincronización de imágenes (Fase 1)
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function resume_images_sync_callback(): void {
		if (!self::validateAjaxSecurity('nonce', 'mi_integracion_api_nonce_dashboard')) {
			return;
		}

		try {
			self::processResumePhase1Images();
		} catch (\Exception $e) {
			self::handleResumePhase1Error($e);
		}
	}

	/**
	 * Procesa la reanudación de sincronización de Fase 1
	 *
	 * @return void
	 * @throws \Exception Si ocurre un error durante el proceso
	 * @since 1.5.0
	 */
	private static function processResumePhase1Images(): void {
		$phase1_images = self::getPhase1ImagesStatus();
		
		if (!self::isPhase1Paused($phase1_images)) {
			wp_send_json_error([
				'message' => 'La sincronización de imágenes no está pausada'
			]);
			return;
		}

		$batch_size = self::normalizeBatchSize($_POST['batch_size'] ?? null);
		$updated_status = self::resumePhase1ImagesStatus($phase1_images);
		
		self::logPhase1Resume($updated_status, $batch_size);
		
		wp_send_json_success([
			'message' => 'Sincronización de imágenes reanudada',
			'phase1_images' => $updated_status
		]);
	}

	/**
	 * Obtiene el estado actual de Fase 1 (imágenes)
	 *
	 * @return array<string, mixed> Estado de phase1_images
	 * @since 1.5.0
	 */
	private static function getPhase1ImagesStatus(): array {
		$status = \MiIntegracionApi\Helpers\SyncStatusHelper::getSyncStatus();
		return $status['phase1_images'] ?? [];
	}

	/**
	 * Verifica si la sincronización de Fase 1 está pausada
	 *
	 * @param array<string, mixed> $phase1_images Estado de phase1_images
	 * @return bool True si está pausada, false en caso contrario
	 * @since 1.5.0
	 */
	private static function isPhase1Paused(array $phase1_images): bool {
		return !empty($phase1_images['paused']) && $phase1_images['paused'] === true;
	}

	/**
	 * Normaliza el tamaño de lote a un valor válido
	 *
	 * @param mixed $batch_size Valor del batch_size a normalizar
	 * @return int Tamaño de lote normalizado (entre 1 y 100, por defecto 50)
	 * @since 1.5.0
	 */
	private static function normalizeBatchSize($batch_size): int {
		$default_batch_size = 50;
		$min_batch_size = 1;
		$max_batch_size = 100;
		
		if ($batch_size === null) {
			return $default_batch_size;
		}
		
		$batch_size = (int) $batch_size;
		
		if ($batch_size < $min_batch_size || $batch_size > $max_batch_size) {
			return $default_batch_size;
		}
		
		return $batch_size;
	}

	/**
	 * Actualiza el estado de Fase 1 para reanudar la sincronización
	 *
	 * @param array<string, mixed> $phase1_images Estado actual de phase1_images
	 * @return array<string, mixed> Estado actualizado de phase1_images
	 * @since 1.5.0
	 */
	private static function resumePhase1ImagesStatus(array $phase1_images): array {
		$phase1_images['in_progress'] = true;
		$phase1_images['paused'] = false;
		$phase1_images['last_update'] = time();

		$status = \MiIntegracionApi\Helpers\SyncStatusHelper::getSyncStatus();
		$status['phase1_images'] = $phase1_images;
		\MiIntegracionApi\Helpers\SyncStatusHelper::saveSyncStatus($status);

		return $phase1_images;
	}

	/**
	 * Registra el evento de reanudación de sincronización de Fase 1
	 *
	 * @param array<string, mixed> $phase1_images Estado de phase1_images
	 * @param int                  $batch_size     Tamaño de lote utilizado
	 * @return void
	 * @since 1.5.0
	 */
	private static function logPhase1Resume(array $phase1_images, int $batch_size): void {
		$logger = new \MiIntegracionApi\Helpers\Logger('image-sync');
		$logger->info('Sincronización de imágenes reanudada', [
			'products_processed' => $phase1_images['products_processed'] ?? 0,
			'total_products' => $phase1_images['total_products'] ?? 0,
			'batch_size' => $batch_size,
			'user_id' => get_current_user_id()
		]);
	}

	/**
	 * Maneja errores durante la reanudación de sincronización de Fase 1
	 *
	 * @param \Exception $e Excepción capturada
	 * @return void
	 * @since 1.5.0
	 */
	private static function handleResumePhase1Error(\Exception $e): void {
		self::logError('Error al reanudar sincronización de imágenes', [
			'error' => $e->getMessage(),
			'user_id' => get_current_user_id()
		]);

		wp_send_json_error([
			'message' => 'Error al reanudar sincronización: ' . $e->getMessage()
		]);
	}

	/**
	 * Limpia transients antiguos de sincronización
	 * 
	 * Este método elimina transients de sincronización que han expirado
	 * o que son más antiguos que el tiempo especificado.
	 * 
	 * @param int $max_age_hours Edad máxima en horas (por defecto 24)
	 * @return array Estadísticas de limpieza
	 * @since 1.0.0
	 */
	public static function cleanup_old_sync_transients(int $max_age_hours = 24): array
	{
		// Delegar a la clase Utils que ya tiene la implementación
		return \MiIntegracionApi\Helpers\Utils::cleanup_old_sync_transients($max_age_hours);
	}
	
	/**
	 * Obtiene timeout centralizado para operaciones de sincronización
	 * 
	 * @return int Timeout en segundos
	 */
	private static function getSyncTimeout(): int
	{
		// Timeout base para sincronización
		$base_timeout = 300; // 5 minutos
		
		// Permitir configuración vía filtro
		$timeout = apply_filters('mia_sync_timeout_seconds', $base_timeout);
		
		// Validar rango (60-1800 segundos = 1-30 minutos)
		$validated_timeout = max(60, min(1800, (int) $timeout));
		
		// Verificar límite del servidor
		$server_limit = (int) ini_get('max_execution_time');
		if ($server_limit > 0 && $validated_timeout > $server_limit) {
			// Si el timeout solicitado excede el límite del servidor, usar el límite del servidor
			$validated_timeout = $server_limit;
		}
		
		return $validated_timeout;
	}

	/**
	 * ✅ NUEVO: Callback AJAX para ejecutar migración Hot→Cold Cache
	 * 
	 * @return void
	 * @since 1.0.0
	 */
	public static function perform_hot_cold_migration_callback(): void {
		// Verificar nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mia_hot_cold_migration')) {
			wp_send_json_error([
				'message' => __('Error de seguridad: nonce inválido.', 'mi-integracion-api')
			]);
			return;
		}

		// Verificar permisos
		if (!current_user_can('manage_options')) {
			wp_send_json_error([
				'message' => __('No tienes permisos para realizar esta acción.', 'mi-integracion-api')
			]);
			return;
		}

		try {
			$cacheManager = \MiIntegracionApi\CacheManager::get_instance();
			$result = $cacheManager->performHotToColdMigration();

			wp_send_json_success([
				'migrated_count' => $result['migrated_count'],
				'skipped_count' => $result['skipped_count'],
				'error_count' => count($result['errors']),
				'errors' => $result['errors'],
				'message' => sprintf(
					__('Migración completada: %d elementos migrados, %d omitidos.', 'mi-integracion-api'),
					$result['migrated_count'],
					$result['skipped_count']
				)
			]);
		} catch (\Exception $e) {
			wp_send_json_error([
				'message' => __('Error durante la migración: ', 'mi-integracion-api') . $e->getMessage()
			]);
		}
	}
}