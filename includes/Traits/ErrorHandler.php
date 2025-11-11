<?php

declare(strict_types=1);

namespace MiIntegracionApi\Traits;

/**
 * Trait para proporcionar métodos de gestión de errores unificados.
 * 
 * @package MiIntegracionApi\Traits
 * @since 1.0.0
 */
trait ErrorHandler {

	/**
	 * Crea un objeto WP_Error con los parámetros adecuados.
	 * Esta función está anotada para evitar advertencias de PHPStan.
	 *
	 * @param string               $code Código de error
	 * @param string               $message Mensaje de error
	 * @param array<string, mixed> $data Datos adicionales para el error
	 * @return \WP_Error
	 */
	protected function create_wp_error( string $code, string $message, array $data = array() ): \WP_Error {
		// @phpstan-ignore-next-line
		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * Crea un error de respuesta REST.
	 *
	 * @param string               $code Código de error
	 * @param string               $message Mensaje de error
	 * @param int                  $status_code Código de estado HTTP
	 * @param array<string, mixed> $additional_data Datos adicionales para el error
	 * @return \WP_Error
	 */
	protected function create_rest_error( string $code, string $message, int $status_code = 400, array $additional_data = array() ): \WP_Error {
		$data = array_merge( array( 'status' => $status_code ), $additional_data );
		return $this->create_wp_error( $code, $message, $data );
	}

	/**
	 * Crea un error de validación para parámetros REST.
	 *
	 * @param string $param_name Nombre del parámetro inválido
	 * @param string $message Mensaje de error
	 * @param mixed  $param_value Valor recibido del parámetro
	 * @return \WP_Error
	 */
	protected function create_validation_error( string $param_name, string $message, $param_value = null ): \WP_Error {
		$data = array(
			'status'     => 400,
			'param'      => $param_name,
			'value'      => $param_value,
		);
		return $this->create_wp_error( 'rest_invalid_param', $message, $data );
	}

	/**
	 * Crea un error de autenticación REST.
	 *
	 * @param string               $message Mensaje de error
	 * @param array<string, mixed> $additional_data Datos adicionales para el error
	 * @return \WP_Error
	 */
	protected function create_auth_error( string $message, array $additional_data = array() ): \WP_Error {
		$status_code = 401;
		$data        = array_merge( array( 'status' => $status_code ), $additional_data );
		return $this->create_wp_error( 'rest_forbidden', $message, $data );
	}

	/**
	 * Crea un error de recurso no encontrado.
	 *
	 * @param string               $message Mensaje de error
	 * @param array<string, mixed> $additional_data Datos adicionales para el error
	 * @return \WP_Error
	 */
	protected function create_not_found_error( string $message, array $additional_data = array() ): \WP_Error {
		$status_code = 404;
		$data        = array_merge( array( 'status' => $status_code ), $additional_data );
		return $this->create_wp_error( 'rest_not_found', $message, $data );
	}

	/**
	 * Crea un error de servidor interno.
	 *
	 * @param string               $message Mensaje de error
	 * @param array<string, mixed> $additional_data Datos adicionales para el error
	 * @return \WP_Error
	 */
	protected function create_server_error( string $message, array $additional_data = array() ): \WP_Error {
		$status_code = 500;
		$data        = array_merge( array( 'status' => $status_code ), $additional_data );
		return $this->create_wp_error( 'rest_server_error', $message, $data );
	}

	// ========================================================================
	// SISTEMA UNIFICADO DE MANEJO DE ERRORES
	// ========================================================================

	/**
	 * Configuración centralizada para tipos de errores.
	 * 
	 * @return array<string, array<string, mixed>> Configuración de errores
	 */
	private function getErrorConfiguration(): array {
		return [
			'api_error' => [
				'severity' => 'high',
				'log_level' => 'error',
				'log_message' => 'Error de API detectado',
				'attempt_recovery' => true,
				'default_action' => 'retry'
			],
			'validation_error' => [
				'severity' => 'medium',
				'log_level' => 'warning',
				'log_message' => 'Error de validación detectado',
				'attempt_recovery' => false,
				'default_action' => 'skip_item'
			],
			'memory_error' => [
				'severity' => 'critical',
				'log_level' => 'error',
				'log_message' => 'Error crítico de memoria detectado',
				'attempt_recovery' => true,
				'default_action' => 'cleanup_and_retry'
			],
			'timeout_error' => [
				'severity' => 'high',
				'log_level' => 'warning',
				'log_message' => 'Error de timeout detectado',
				'attempt_recovery' => true,
				'default_action' => 'retry_with_backoff'
			],
			'generic_error' => [
				'severity' => 'medium',
				'log_level' => 'error',
				'log_message' => 'Error genérico detectado',
				'attempt_recovery' => true,
				'default_action' => 'retry'
			]
		];
	}

	/**
	 * Maneja errores de procesamiento por lotes de forma unificada.
	 * 
	 * Sistema centralizado que reemplaza los métodos duplicados en BatchProcessor:
	 * - Configuración centralizada (elimina hardcodeos)
	 * - Logging estructurado 
	 * - Métricas automáticas
	 * - Estrategias de recuperación
	 * 
	 * @param string $errorType Tipo de error ('api_error', 'validation_error', etc.)
	 * @param \Throwable $error Excepción capturada
	 * @param array<string, mixed> $context Contexto adicional del error
	 * @return array<string, mixed> Resultado estructurado del manejo
	 * 
	 * @since 2.1.0 Sistema unificado de manejo de errores
	 */
	protected function handle_batch_error( string $errorType, \Throwable $error, array $context = array() ): array {
		$config = $this->getErrorConfiguration()[$errorType] ?? $this->getErrorConfiguration()['generic_error'];
		
		// ✅ MIGRADO: Usar SyncMetrics directamente en lugar de método deprecated
		if (class_exists('\\MiIntegracionApi\\Core\\SyncMetrics')) {
			try {
				$syncMetrics = new \MiIntegracionApi\Core\SyncMetrics();
				$syncMetrics->recordError($errorType, $config['severity'], $context);
			} catch (\Exception $e) {
				// Fallback silencioso si SyncMetrics falla
				error_log("SyncMetrics error: " . $e->getMessage());
			}
		}
		
		// Crear información estructurada del error
		$errorInfo = [
			'type' => $errorType,
			'message' => $error->getMessage(),
			'code' => $error->getCode(),
			'file' => $error->getFile(),
			'line' => $error->getLine(),
			'context' => $context,
			'timestamp' => time(),
			'severity' => $config['severity']
		];
		
		// Logging estructurado
		$this->log_structured_error($config['log_level'], $config['log_message'], $errorInfo);
		
		// Intentar recuperación si está configurada
		$recoveryResult = $config['attempt_recovery'] && method_exists($this, 'attemptErrorRecovery')
			? $this->attemptErrorRecovery($errorType, $errorInfo)
			: [
				'attempted' => false, 
				'successful' => false, 
				'next_action' => $config['default_action']
			];
		
		return [
			'success' => false,
			'error' => $errorInfo,
			'recovery_attempted' => $recoveryResult['attempted'],
			'recovery_successful' => $recoveryResult['successful'],
			'next_action' => $recoveryResult['next_action']
		];
	}

	/**
	 * Registra errores en el sistema de logging con formato estructurado.
	 * 
	 * @param string $level Nivel de log ('error', 'warning', 'info')
	 * @param string $message Mensaje principal
	 * @param array<string, mixed> $context Contexto del error
	 * @return void
	 */
	protected function log_structured_error( string $level, string $message, array $context = array() ): void {
		try {
			// Intentar usar el nuevo sistema de LogManager primero
			if (class_exists('\\MiIntegracionApi\\Logging\\Core\\LogManager')) {
				$logManager = \MiIntegracionApi\Logging\Core\LogManager::getInstance();
				$channel = $this->getLoggerChannel();
				$logger = $logManager->getLogger($channel);
				
				// Usar el método estático para compatibilidad
				\MiIntegracionApi\Logging\Core\Logger::logMessage($message, $level, $context);
				return;
			}
			
			// Fallback: usar el sistema anterior
			if (class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
				// Determinar el canal basado en el contexto de la clase
				$channel = $this->getLoggerChannel();
				
				switch ($level) {
					case 'error':
						\MiIntegracionApi\Helpers\Logger::error($message, $context, $channel);
						break;
					case 'warning':
						\MiIntegracionApi\Helpers\Logger::warning($message, $context, $channel);
						break;
					case 'info':
						\MiIntegracionApi\Helpers\Logger::info($message, $context, $channel);
						break;
					default:
						\MiIntegracionApi\Helpers\Logger::error($message, $context, $channel);
				}
			} else {
				// Fallback final a error_log
				error_log("[MiIntegracionApi] $level: $message");
			}
		} catch (\Throwable $e) {
			// Fallback a error_log si hay problemas con el Logger
			error_log("[MiIntegracionApi] $level: $message");
		}
	}

	/**
	 * Determina el canal de logging basado en el contexto.
	 * 
	 * @return string Canal de logging
	 */
	private function getLoggerChannel(): string {
		$className = get_class($this);
		
		if (str_contains($className, 'BatchProcessor')) {
			return 'batch-processor';
		} elseif (str_contains($className, 'Endpoint')) {
			return 'endpoints';
		} elseif (str_contains($className, 'WooCommerce')) {
			return 'woocommerce';
		}
		
		return 'error-handler';
	}

	/**
	 * Lanza una excepción con logging automático.
	 * 
	 * @param string $message Mensaje de error
	 * @param int $code Código de error
	 * @param array<string, mixed> $context Contexto adicional
	 * @throws \Exception
	 */
	protected function throw_logged_error( string $message, int $code = 0, array $context = array() ): void {
		$this->log_structured_error('error', $message, array_merge($context, [
			'exception_code' => $code,
			'throw_location' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? []
		]));
		
		throw new \Exception($message, $code);
	}

	/**
	 * Muestra un aviso de error en el admin de WordPress con logging.
	 * 
	 * @param string $message Mensaje de error
	 * @param array<string, mixed> $context Contexto adicional
	 * @return void
	 */
	protected function show_admin_error( string $message, array $context = array() ): void {
		// Log del error
		$this->log_structured_error('warning', 'Admin notice shown: ' . $message, $context);
		
		// Mostrar aviso solo en admin
		if (is_admin()) {
			add_action('admin_notices', function() use ($message) {
				echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
			});
		}
	}

	/**
	 * Obtiene el último error de PHP con información adicional.
	 * 
	 * @return array<string, mixed>|null Información del último error o null
	 */
	protected function get_last_php_error(): ?array {
		$lastError = error_get_last();
		
		if ($lastError) {
			$this->log_structured_error('error', 'PHP Error detected', [
				'php_error' => $lastError,
				'detected_at' => time()
			]);
		}
		
		return $lastError;
	}
}
