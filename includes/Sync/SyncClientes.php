<?php

declare(strict_types=1);

namespace MiIntegracionApi\Sync;

use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Helpers\Map_Customer;
use MiIntegracionApi\Helpers\Validation;
use MiIntegracionApi\Sync\MI_Sync_Lock;
use MiIntegracionApi\ErrorHandling\Exceptions\SyncError;
use MiIntegracionApi\Core\Validation\CustomerValidator;
use MiIntegracionApi\Core\BatchProcessor;
use MiIntegracionApi\Core\MemoryManager;
use MiIntegracionApi\Core\RetryManager;
use MiIntegracionApi\Core\TransactionManager;
use MiIntegracionApi\Core\ConfigManager;

/**
 * Clase para la sincronización de clientes
 *
 * Maneja la sincronización bidireccional de clientes entre WooCommerce
 * y el sistema Verial ERP, incluyendo validación, mapeo de datos,
 * reintentos automáticos y gestión de errores.
 * 
 * Características principales:
 * - Sincronización por lotes para optimizar rendimiento
 * - Validación de datos antes de sincronizar
 * - Sistema de reintentos con backoff exponencial
 * - Mapeo automático de campos entre sistemas
 * - Gestión de transacciones para consistencia de datos
 *
 * @package MiIntegracionApi
 * @subpackage Sync
 * @since 1.0.0
 * @author Mi Integración API
 */
class SyncClientes extends BatchProcessor {
	/**
	 * Validador de datos de clientes
	 * 
	 * @var CustomerValidator
	 * @since 1.0.0
	 */
	private CustomerValidator $validator;
	
	/**
	 * Gestor de memoria del sistema
	 * 
	 * @var MemoryManager
	 * @since 1.0.0
	 */
	private MemoryManager $memory;
	
	/**
	 * Gestor de reintentos automáticos
	 * 
	 * @var RetryManager
	 * @since 1.0.0
	 */
	private RetryManager $retry;
	
	/**
	 * Gestor de transacciones de base de datos
	 * 
	 * @var TransactionManager
	 * @since 1.0.0
	 */
	private TransactionManager $transaction;
	
	/**
	 * Logger para registro de eventos
	 * 
	 * @var Logger
	 * @since 1.0.0
	 */
	protected Logger $logger;

	/**
	 * Constructor de la clase SyncClientes
	 * 
	 * Inicializa todas las dependencias necesarias para la sincronización
	 * de clientes, siguiendo los principios SOLID de diseño.
	 * 
	 * @param \MiIntegracionApi\Core\ApiConnector|null $apiConnector Conector API opcional
	 * @since 1.0.0
	 */
	public function __construct(?\MiIntegracionApi\Core\ApiConnector $apiConnector = null) {
		// Inyección de dependencias siguiendo Dependency Inversion Principle
		$apiConnector = $apiConnector ?? $this->createDefaultApiConnector();
		
		// Llamar al constructor padre con el ApiConnector requerido (Liskov Substitution)
		parent::__construct($apiConnector);
		
		// Inicializar componentes siguiendo Single Responsibility Principle
		$this->validator = new CustomerValidator();
		        $this->memory = MemoryManager::getInstance();
		$this->retry = new RetryManager();
		$this->transaction = new TransactionManager();
		// FASE 1: OPTIMIZADO - Logger inicializado solo cuando sea necesario
		
		// Establecer nombre de entidad para BatchProcessor (Configuration over Convention)
		$this->entityName = 'clientes';
	}
	
	/**
	 * Obtiene la instancia del logger unificado
	 * 
	 * Implementa lazy loading del logger para optimizar el rendimiento.
	 * Aplica el principio DRY y Single Responsibility.
	 * 
	 * @return \MiIntegracionApi\Helpers\Logger Instancia del logger
	 * @since 1.0.0
	 */
	protected static function getLogger(): \MiIntegracionApi\Helpers\Logger
	{
		static $logger = null;
		if ($logger === null) {
			$logger = \MiIntegracionApi\Helpers\Logger::getInstance('sync-clientes');
		}
		return $logger;
	}

	/**
	 * Nombre de la opción para almacenar la cola de reintentos
	 * 
	 * @var string
	 * @since 1.0.0
	 */
	const RETRY_OPTION = 'mia_sync_clientes_retry';
	// REFACTORIZADO: MAX_RETRIES y RETRY_DELAY se obtienen del sistema unificado
	// const MAX_RETRIES  = 3;
	// const RETRY_DELAY  = 300; // segundos entre reintentos (5 min)

	/**
	 * Añade un cliente a la cola de reintentos
	 * 
	 * @param string $email     Email del cliente que falló
	 * @param string $error_msg Mensaje de error que causó el fallo
	 * @return void
	 * @since 1.0.0
	 */
	private static function add_to_retry_queue(string $email, string $error_msg): void {
		$queue = get_option( self::RETRY_OPTION, array() );
		if ( ! isset( $queue[ $email ] ) ) {
			$queue[ $email ] = array(
				'attempts'     => 1,
				'last_attempt' => time(),
				'error'        => $error_msg,
			);
		} else {
			++$queue[ $email ]['attempts'];
			$queue[ $email ]['last_attempt'] = time();
			$queue[ $email ]['error']        = $error_msg;
		}
		update_option( self::RETRY_OPTION, $queue, false );
	}
	/**
	 * Elimina un cliente de la cola de reintentos
	 * 
	 * @param string $email Email del cliente a eliminar
	 * @return void
	 * @since 1.0.0
	 */
	private static function remove_from_retry_queue(string $email): void {
		$queue = get_option( self::RETRY_OPTION, array() );
		if ( isset( $queue[ $email ] ) ) {
			unset( $queue[ $email ] );
			update_option( self::RETRY_OPTION, $queue, false );
		}
	}
	/**
	 * Obtiene el email para alertas del sistema
	 * 
	 * @return string Email configurado para alertas o email del administrador
	 * @since 1.0.0
	 */
	private static function get_alert_email(): string {
		$custom = get_option( 'mia_alert_email' );
		if ( $custom && is_email( $custom ) ) {
			return $custom;
		}
		return get_option( 'admin_email' );
	}
	
	/**
	 * Obtiene la configuración de reintentos del sistema unificado
	 * 
	 * @return array Configuración de reintentos
	 */
	protected function getRetryConfig(string $operationType = 'sync_customers'): array {
		if (class_exists('\\MiIntegracionApi\\Core\\RetryConfigurationManager') && 
			\MiIntegracionApi\Core\RetryConfigurationManager::isInitialized()) {
			return \MiIntegracionApi\Core\RetryConfigurationManager::getInstance()
				->getConfig('sync_customers');
		}
		
		// Fallback a configuración por defecto
		return [
			'max_attempts' => 3,
			'base_delay' => 2.0,
			'backoff_factor' => 2.0,
			'max_delay' => 30.0,
			'jitter_enabled' => true
		];
	}
	
	/**
	 * Obtiene el número máximo de reintentos para clientes
	 * 
	 * @return int Número máximo de reintentos
	 */
	protected function getMaxRetries(string $operationType = 'sync_customers'): int {
		$config = $this->getRetryConfig($operationType);
		return $config['max_attempts'] ?? 3;
	}
	
	/**
	 * Obtiene el tiempo de espera entre reintentos para clientes
	 * 
	 * @return int Tiempo de espera en segundos
	 */
	private static function getRetryDelay(): int {
		$config = self::getRetryConfig();
		return intval($config['base_delay'] * 60); // Convertir a minutos para compatibilidad
	}
	public static function process_retry_queue( \MiIntegracionApi\Core\ApiConnector $api_connector ) {
		$queue = get_option( self::RETRY_OPTION, array() );
		if ( empty( $queue ) ) {
			return;
		}
		foreach ( $queue as $email => $info ) {
			$maxRetries = self::getMaxRetries();
			$retryDelay = self::getRetryDelay();
			
			if ( $info['attempts'] >= $maxRetries ) {

				$msg = sprintf( __( 'Cliente %1$s falló tras %2$d reintentos: %3$s', 'mi-integracion-api' ), $email, $info['attempts'], $info['error'] );
				\MiIntegracionApi\Helpers\Logger::critical( $msg, array( 
					'category' => 'sync-clientes-retry',
					'email' => $email,
					'attempts' => $info['attempts'],
					'last_error' => $info['error'],
					'retry_queue_size' => count($queue),
					'max_retries' => $maxRetries,
					'retry_config' => self::getRetryConfig()
				));
				$alert_email = self::get_alert_email();
				wp_mail( $alert_email, __( 'Cliente no sincronizado tras reintentos', 'mi-integracion-api' ), $msg );
				// (Opcional) Registrar en tabla de incidencias si se implementa
				self::remove_from_retry_queue( $email );
				continue;
			}
			// REFACTORIZADO: Respetar RETRY_DELAY dinámico
			if ( time() - $info['last_attempt'] < $retryDelay ) {
				continue;
			}
			$user = get_user_by( 'email', $email );
			if ( ! $user ) {
				self::remove_from_retry_queue( $email );
				continue;
			}
			$payload_cliente_verial = \MiIntegracionApi\Helpers\MapCustomer::wc_to_verial( $user );
			$response_verial        = $api_connector->post( 'NuevoClienteWS', $payload_cliente_verial );
			if (is_array($response_verial) && isset($response_verial['success']) && $response_verial['success'] === false) {
                self::add_to_retry_queue( $email, $response_verial['message'] ?? 'Error desconocido' );
                continue;
            }
			if ( is_wp_error( $response_verial ) ) {
				self::add_to_retry_queue( $email, $response_verial->get_error_message() );
				continue;
			}
			if ( isset( $response_verial['InfoError']['Codigo'] ) && intval( $response_verial['InfoError']['Codigo'] ) === 0 ) {
				if ( isset( $response_verial['Id'] ) ) {
					update_user_meta( $user->ID, '_verial_cliente_id', intval( $response_verial['Id'] ) );
				}
				$msg = sprintf( __( 'Cliente %s sincronizado tras reintento.', 'mi-integracion-api' ), $email );
				\MiIntegracionApi\Helpers\Logger::info( $msg, array( 
					'category' => 'sync-clientes-retry',
					'email' => $email,
					'usuario_id' => $user->ID,
					'attempts' => $info['attempts'],
					'verial_id' => isset($response_verial['Id']) ? intval($response_verial['Id']) : null,
					'tiempo_total_ms' => round((time() - $info['last_attempt']) * 1000)
				));
				self::remove_from_retry_queue( $email );
			} else {
				$error_desc = isset( $response_verial['InfoError']['Descripcion'] ) ? $response_verial['InfoError']['Descripcion'] : __( 'Error desconocido de Verial', 'mi-integracion-api' );
				self::add_to_retry_queue( $email, $error_desc );
			}
		}
	}
	public static function sync(
		\MiIntegracionApi\Core\ApiConnector $api_connector,
		$batch_size = 50,
		$offset = 0,
		$fecha_desde = null
	) {
		if ( ! class_exists( 'MI_Sync_Lock' ) || ! MI_Sync_Lock::acquire() ) {
			return [
                'success' => false,
                'message' => __( 'Ya hay una sincronización en curso o falta MI_Sync_Lock.', 'mi-integracion-api' )
            ];
		}
		if ( ! class_exists( '\MiIntegracionApi\Helpers\Map_Customer' ) ) {
			return array(
				'success'   => false,
				'message'   => __( 'Clase Map_Customer no disponible.', 'mi-integracion-api' ),
				'processed' => 0,
				'errors'    => 1,
			);
		}
		if ( ! is_object( $api_connector ) ) {
			return array(
				'success'   => false,
				'message'   => __( 'ApiConnector no válido.', 'mi-integracion-api' ),
				'processed' => 0,
				'errors'    => 1,
			);
		}
		$processed = 0;
		$errors    = 0;
		$log       = array();
		try {
			$args = array(
				'role'    => 'customer',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'all',
				'number'  => $batch_size,
				'offset'  => $offset,
			);
			// --- Sincronización incremental: filtrar por fecha si se indica ---
			if ( $fecha_desde ) {
				$args['date_query'] = array(
					array(
						'column' => 'user_registered',
						'after'  => $fecha_desde,
					),
				);
			}
			$user_query  = new \WP_User_Query( $args );
			$clientes_wc = $user_query->get_results();
			if ( empty( $clientes_wc ) ) {
				\MiIntegracionApi\Helpers\Logger::info( 'No se encontraron clientes con rol "customer" en WooCommerce para sincronizar.', array( 
					'category' => 'sync-clientes',
					'batch_size' => $batch_size, 
					'offset' => $offset,
					'fecha_desde' => $fecha_desde
				));
				return array(
					'success'     => true,
					'message'     => __( 'No se encontraron clientes en WooCommerce para sincronizar.', 'mi-integracion-api' ),
					'processed'   => 0,
					'errors'      => 0,
					'log'         => array( __( 'No se encontraron clientes en WooCommerce para sincronizar.', 'mi-integracion-api' ) ),
					'next_offset' => $offset,
					'has_more'    => false,
				);
			}
			\MiIntegracionApi\Helpers\Logger::info( 'Iniciando sincronización de ' . count( $clientes_wc ) . ' clientes de WooCommerce a Verial.', array( 
				'category' => 'sync-clientes',
				'total_clientes' => count($clientes_wc),
				'batch_size' => $batch_size,
				'offset' => $offset,
				'fecha_desde' => $fecha_desde,
				'memory_start' => memory_get_usage(true)
			));
			foreach ( $clientes_wc as $cliente_wc ) {
							$payload_cliente_verial = \MiIntegracionApi\Helpers\MapCustomer::wc_to_verial( $cliente_wc );
			if ( empty( $payload_cliente_verial['Email'] ) || ! \MiIntegracionApi\Helpers\Utils::is_email( $payload_cliente_verial['Email'] ) ) {
					++$errors;
					$error_msg = sprintf( __( 'Cliente ID %s omitido: Email inválido o faltante.', 'mi-integracion-api' ), $cliente_wc->ID );
					$log[]     = $error_msg;
					\MiIntegracionApi\Helpers\Logger::warning( $error_msg, array( 
						'category' => 'sync-clientes',
						'usuario_id' => $cliente_wc->ID, 
						'email' => $payload_cliente_verial['Email'] ?? 'no_disponible',
						'problema' => empty($payload_cliente_verial['Email']) ? 'email_vacio' : 'formato_email_invalido'
					));
					continue;
				}
				// --- Control de duplicados en WooCommerce (email, _verial_cliente_id, NIF/DNI) ---
				$existing_user     = get_user_by( 'email', $payload_cliente_verial['Email'] );
				$id_externo_verial = isset( $payload_cliente_verial['Id'] ) ? $payload_cliente_verial['Id'] : null;
				$user_by_verial_id = null;
				if ( $id_externo_verial ) {
					$user_query = new \WP_User_Query(
						array(
							'meta_key'   => '_verial_cliente_id',
							'meta_value' => $id_externo_verial,
							'number'     => 1,
						)
					);
					$results    = $user_query->get_results();
					if ( ! empty( $results ) ) {
						$user_by_verial_id = $results[0];
					}
				}
				// Comprobar duplicados por NIF/DNI si existe
				$nif = isset( $payload_cliente_verial['NIF'] ) ? $payload_cliente_verial['NIF'] : '';
				if ( $nif ) {
					$user_query_nif = new \WP_User_Query(
						array(
							'meta_key'   => 'billing_nif',
							'meta_value' => $nif,
							'number'     => 1,
						)
					);
					$results_nif    = $user_query_nif->get_results();
					if ( ! empty( $results_nif ) && $results_nif[0]->ID != $cliente_wc->ID ) {

						$msg   = sprintf( __( 'Cliente duplicado detectado por NIF/DNI en WooCommerce (NIF: %1$s, ID: %2$d). Se omite la creación/actualización.', 'mi-integracion-api' ), $nif, $results_nif[0]->ID );
						$log[] = $msg;
						\MiIntegracionApi\Helpers\Logger::warning( $msg, array( 
							'category' => 'sync-clientes-duplicados',
							'nif' => $nif,
							'usuario_id_original' => $cliente_wc->ID,
							'usuario_id_duplicado' => $results_nif[0]->ID,
							'tipo_duplicado' => 'nif',
							'email' => $payload_cliente_verial['Email'] ?? 'no_disponible'
						));
						// Alerta proactiva por email al admin
						$alert_email = self::get_alert_email();
						wp_mail( $alert_email, '[Verial/WC] Duplicado crítico de cliente', $msg );
						continue;
					}
				}
				if ( ( $existing_user && $existing_user->ID != $cliente_wc->ID ) || ( $user_by_verial_id && $user_by_verial_id->ID != $cliente_wc->ID ) ) {

					$msg   = sprintf( __( 'Cliente duplicado detectado en WooCommerce (Email: %1$s, ID externo Verial: %2$s). Se omite la creación/actualización.', 'mi-integracion-api' ), $payload_cliente_verial['Email'], $id_externo_verial );
					$log[] = $msg;
					\MiIntegracionApi\Helpers\Logger::warning( $msg, array( 
						'category' => 'sync-clientes-duplicados',
						'email' => $payload_cliente_verial['Email'],
						'verial_id' => $id_externo_verial,
						'usuario_id_original' => $cliente_wc->ID,
						'usuario_id_duplicado' => $existing_user ? $existing_user->ID : ($user_by_verial_id ? $user_by_verial_id->ID : 'desconocido'),
						'tipo_duplicado' => $existing_user ? 'email' : 'verial_id'
					));
					// Alerta proactiva por email al admin
					$alert_email = self::get_alert_email();
					wp_mail( $alert_email, '[Verial/WC] Duplicado crítico de cliente', $msg );
					continue;
				}
				// --- Hash de sincronización: incluir campos clave y metadatos relevantes ---
				$hash_fields = array(
					$payload_cliente_verial['Email'],
					$payload_cliente_verial['Nombre'] ?? '',
					$payload_cliente_verial['Telefono'] ?? '',
					$payload_cliente_verial['Direccion'] ?? '',
					$payload_cliente_verial['NIF'] ?? '',
					isset( $payload_cliente_verial['meta_data'] ) ? wp_json_encode( $payload_cliente_verial['meta_data'] ) : '',
				);
				// Documentación: los campos incluidos en el hash son: email, nombre, teléfono, dirección, NIF/DNI y metadatos personalizados.
				$hash_actual   = md5( json_encode( $hash_fields ) );
				$hash_guardado = get_user_meta( $cliente_wc->ID, '_verial_sync_hash', true );
				if ( $hash_guardado && $hash_actual === $hash_guardado ) {
					$log[] = sprintf( __( 'Cliente %s omitido (hash sin cambios).', 'mi-integracion-api' ), $payload_cliente_verial['Email'] );
					continue;
				}
				// Si existe en Verial, actualizar; si no, crear
				if ( $cliente_verial && isset( $cliente_verial['Id'] ) ) {
					$payload_cliente_verial['Id'] = $cliente_verial['Id'];
					$response_verial              = $api_connector->post( 'ActualizarClienteWS', $payload_cliente_verial );
					$accion                       = 'actualizado';
				} else {
					$response_verial = $api_connector->post( 'NuevoClienteWS', $payload_cliente_verial );
					$accion          = 'creado';
				}
				if ( is_wp_error( $response_verial ) ) {
					// Solo reintentar en errores temporales (red, HTTP 5xx, timeout)
					$err = $response_verial->get_error_code();
					$error_message = $response_verial['message'] ?? 'Error desconocido';
					
					// Manejo específico para timeout
					if ( strpos( $err, 'timeout' ) !== false ) {
						$timeout_error = SyncError::timeoutError(
							'Timeout en sincronización de cliente',
							[
								'customer_email' => $payload_cliente_verial['Email'],
								'customer_id' => $cliente_wc->ID,
								'api_error_code' => $err,
								'retry_attempt' => $retry_count ?? 0
							]
						);
						self::add_to_retry_queue( $payload_cliente_verial['Email'], $timeout_error->getMessage() );
					} elseif ( strpos( $err, 'http_error_5' ) !== false || strpos( $err, 'connection' ) !== false ) {
						self::add_to_retry_queue( $payload_cliente_verial['Email'], $error_message );
					}
					++$errors;

					$error_msg = sprintf( __( 'Error al sincronizar cliente %1$s (ID: %2$d): %3$s', 'mi-integracion-api' ), $payload_cliente_verial['Email'], $cliente_wc->ID, $error_message );
					$log[]     = $error_msg;
					\MiIntegracionApi\Helpers\Logger::error( $error_msg, array( 
						'category' => 'sync-clientes',
						'email' => $payload_cliente_verial['Email'],
						'usuario_id' => $cliente_wc->ID,
						'error_code' => $response_verial['error'] ?? '',
						'error_message' => $response_verial['message'] ?? '',
						'retry_queued' => strpos($err, 'http_error_5') !== false || 
                                         strpos($err, 'connection') !== false || 
                                         strpos($err, 'timeout') !== false
					));
					continue;
				}
				if ( isset( $response_verial['InfoError']['Codigo'] ) && intval( $response_verial['InfoError']['Codigo'] ) === 0 ) {
					++$processed;

					$log_msg = sprintf( __( 'Cliente %1$s %2$s correctamente (ID WC: %3$d, ID Verial: %4$s)', 'mi-integracion-api' ), $payload_cliente_verial['Email'], $accion, $cliente_wc->ID, ( $response_verial['Id'] ?? 'N/A' ) );
					// Registrar operación de entidad usando el nuevo método especializado
					\MiIntegracionApi\Helpers\Logger::entity_operation('cliente', $accion == 'creado' ? 'create' : 'update', $cliente_wc->ID, [
						'email' => $payload_cliente_verial['Email'],
						'verial_id' => $response_verial['Id'] ?? null,
						'campos' => array_keys($payload_cliente_verial),
						'hash' => $hash_actual,
						'hash_anterior' => $hash_guardado
					]);
					$log[]   = $log_msg;
					if ( isset( $response_verial['Id'] ) ) {
						update_user_meta( $cliente_wc->ID, '_verial_cliente_id', intval( $response_verial['Id'] ) );
					}
					update_user_meta( $cliente_wc->ID, '_verial_sync_hash', $hash_actual );
					update_user_meta( $cliente_wc->ID, '_verial_sync_last', current_time( 'mysql' ) );
				} else {
					++$errors;
					$error_desc = isset( $response_verial['InfoError']['Descripcion'] ) ? $response_verial['InfoError']['Descripcion'] : __( 'Error desconocido de Verial', 'mi-integracion-api' );
					$error_msg  = sprintf( __( 'Error al sincronizar cliente %1$s (ID: %2$d) con Verial: %3$s', 'mi-integracion-api' ), $payload_cliente_verial['Email'], $cliente_wc->ID, $error_desc );
					$log[]      = $error_msg;
					\MiIntegracionApi\Helpers\Logger::error( $error_msg, array( 
						'category' => 'sync-clientes',
						'email' => $payload_cliente_verial['Email'],
						'usuario_id' => $cliente_wc->ID,
						'accion' => $accion,
						'error_code' => $response_verial['InfoError']['Codigo'] ?? 'desconocido',
						'error_desc' => $error_desc,
						'response' => $response_verial
					));
				}
			}
			$final_message = sprintf(
				__( 'Sincronización de clientes completada. Procesados: %1$d, Errores: %2$d.', 'mi-integracion-api' ),
				$processed,
				$errors
			);
			
			// Usar el nuevo método específico para operaciones de sincronización
			\MiIntegracionApi\Helpers\Logger::sync_operation('clientes', [
				'total' => count($clientes_wc),
				'procesados' => $processed,
				'errores' => $errors,
				'hash_omitidos' => count($clientes_wc) - $processed - $errors,
				'batch_size' => $batch_size,
				'offset' => $offset,
				'memory_pico' => size_format(memory_get_peak_usage(true), 2)
			], $errors > 0 ? 'partial' : 'success');
			return array(
				'success'     => $errors === 0,
				'message'     => $final_message,
				'processed'   => $processed,
				'errors'      => $errors,
				'log'         => $log,
				'next_offset' => $offset + $batch_size,
				'has_more'    => count( $clientes_wc ) === $batch_size,
			);
		} catch ( \Exception $e ) {

			$exception_msg = sprintf( __( 'Excepción durante la sincronización de clientes: %s', 'mi-integracion-api' ), $e->getMessage() );
			\MiIntegracionApi\Helpers\Logger::exception($e, $exception_msg, [
				'category' => 'sync-clientes',
				'procesados_antes_excepcion' => $processed,
				'batch_size' => $batch_size,
				'offset' => $offset
			]);
			$log[] = $exception_msg;
			return array(
				'success'     => false,
				'message'     => $exception_msg,
				'processed'   => $processed,
				'errors'      => $errors + 1,
				'log'         => $log,
				'next_offset' => $offset,
				'has_more'    => false,
			);
		} finally {
			if ( class_exists( 'MI_Sync_Lock' ) ) {
				MI_Sync_Lock::release();
			}
		}
	}

	public static function sync_batch( \MiIntegracionApi\Core\ApiConnector $api_connector, array $user_ids = array(), array $filters = array(), $batch_size = 50, $offset = 0 ) {
		if ( ! class_exists( 'MI_Sync_Lock' ) || ! MI_Sync_Lock::acquire() ) {
			return [
                'success' => false,
                'message' => __( 'Ya hay una sincronización en curso.', 'mi-integracion-api' )
            ];
		}
		$processed = 0;
		$errors    = 0;
		$log       = array();
		try {
			// Nuevo: usar helper como clase autoloaded
			$query_args         = $filters;
			$query_args['role'] = 'customer';
			if ( ! empty( $user_ids ) ) {
				$query_args['include'] = $user_ids;
			}
			$filtered_user_ids = \MiIntegracionApi\Helpers\FilterCustomers::advanced( $query_args );
			if ( empty( $filtered_user_ids ) ) {
				$log_msg = __( 'No se encontraron clientes con los filtros aplicados.', 'mi-integracion-api' );
				\MiIntegracionApi\helpers\Logger::info( $log_msg . ' Filtros: ' . wp_json_encode( $filters ), array( 'context' => 'sync-clientes-batch' ) );
				return array(
					'success'     => true,
					'message'     => $log_msg,
					'processed'   => 0,
					'errors'      => 0,
					'log'         => array( $log_msg ),
					'next_offset' => $offset,
					'has_more'    => false,
				);
			}
			// Procesar solo el lote actual
			$user_ids_batch = array_slice( $filtered_user_ids, $offset, $batch_size );
			if ( empty( $user_ids_batch ) ) {
				$log_msg = __( 'No hay más clientes para procesar en este lote.', 'mi-integracion-api' );
				return array(
					'success'     => true,
					'message'     => $log_msg,
					'processed'   => 0,
					'errors'      => 0,
					'log'         => array( $log_msg ),
					'next_offset' => $offset,
					'has_more'    => false,
				);
			}
			$rollback_snapshots = array();
			$rollback_ids       = array();
			$clientes_wc        = array_map(
				function ( $user_id ) {
					return get_userdata( $user_id );
				},
				$user_ids_batch
			);
			foreach ( $clientes_wc as $cliente_wc ) {
				// --- Captura snapshot antes de modificar ---
				$rollback_snapshots[ $cliente_wc->ID ] = array(
					'meta' => get_user_meta( $cliente_wc->ID ),
				);
				$payload_cliente_verial                = \MiIntegracionApi\Helpers\MapCustomer::wc_to_verial( $cliente_wc );
				// --- Hook para resolución de conflictos ---
				$payload_cliente_verial = apply_filters( 'mi_integracion_api_resolver_conflicto_cliente', $payload_cliente_verial, $cliente_wc );
				if ( empty( $payload_cliente_verial['Email'] ) || ! \MiIntegracionApi\Helpers\Utils::is_email( $payload_cliente_verial['Email'] ) ) {
					++$errors;
					$error_msg = sprintf( __( 'Cliente ID %s omitido: Email inválido o faltante.', 'mi-integracion-api' ), $cliente_wc->ID );
					$log[]     = $error_msg;
					\MiIntegracionApi\helpers\Logger::warning( $error_msg, array( 'context' => 'sync-clientes-batch' ) );
					continue;
				}
				try {
					$response_verial = $api_connector->post( 'NuevoClienteWS', $payload_cliente_verial );
					if (is_array($response_verial) && isset($response_verial['success']) && $response_verial['success'] === false) {
                        // Manejo del error
                        throw new \Exception($response_verial['message'] ?? 'Error desconocido');
                    }
					if ( is_wp_error( $response_verial ) ) {
						// Manejo del error
						throw new \Exception( $response_verial->get_error_message() );
					}
					if ( isset( $response_verial['InfoError']['Codigo'] ) && intval( $response_verial['InfoError']['Codigo'] ) === 0 ) {
						++$processed;

						$log_msg = sprintf( __( 'Cliente sincronizado: %1$s (ID WC: %2$d, ID Verial: %3$s)', 'mi-integracion-api' ), $payload_cliente_verial['Email'], $cliente_wc->ID, ( $response_verial['Id'] ?? 'N/A' ) );
						$log[]   = $log_msg;
						if ( isset( $response_verial['Id'] ) ) {
							update_user_meta( $cliente_wc->ID, '_verial_cliente_id', intval( $response_verial['Id'] ) );
						}
						$rollback_ids[] = $cliente_wc->ID;
					} else {
						++$errors;
						$error_desc = isset( $response_verial['InfoError']['Descripcion'] ) ? $response_verial['InfoError']['Descripcion'] : __( 'Error desconocido de Verial', 'mi-integracion-api' );
						$error_msg  = sprintf( __( 'Error al sincronizar cliente %1$s (ID: %2$d) con Verial: %3$s', 'mi-integracion-api' ), $payload_cliente_verial['Email'], $cliente_wc->ID, $error_desc );
						$log[]      = $error_msg;
						\MiIntegracionApi\helpers\Logger::error( $error_msg . ' Respuesta Verial: ' . wp_json_encode( $response_verial ), array( 'context' => 'sync-clientes-batch' ) );
					}
				} catch ( \Exception $e ) {

					$exception_msg = sprintf( __( 'Excepción durante la sincronización de cliente (ID: %1$d): %2$s', 'mi-integracion-api' ), $cliente_wc->ID, $e->getMessage() );
					\MiIntegracionApi\helpers\Logger::critical( $exception_msg, array( 'context' => 'sync-clientes-batch' ) );
					$log[] = $exception_msg;
					// --- Rollback inmediato si error crítico ---
					self::rollback_clientes( $rollback_snapshots, $rollback_ids );
					\MiIntegracionApi\helpers\Logger::critical( 'Rollback ejecutado para clientes afectados tras error crítico en lote.', array( 'context' => 'sync-clientes-batch' ) );
					break;
				}
			}
			$final_message = sprintf(
				__( 'Sincronización de clientes (batch) completada. Procesados: %1$d, Errores: %2$d.', 'mi-integracion-api' ),
				$processed,
				$errors
			);
			\MiIntegracionApi\helpers\Logger::info( $final_message, array( 'context' => 'sync-clientes-batch' ) );
			return array(
				'success'     => $errors === 0,
				'message'     => $final_message,
				'processed'   => $processed,
				'errors'      => $errors,
				'log'         => $log,
				'next_offset' => $offset + $batch_size,
				'has_more'    => ( $offset + $batch_size ) < count( $filtered_user_ids ),
			);
		} catch ( \Exception $e ) {

			$exception_msg = sprintf( __( 'Excepción durante la sincronización de clientes (batch): %s', 'mi-integracion-api' ), $e->getMessage() );
			\MiIntegracionApi\helpers\Logger::critical( $exception_msg, array( 'context' => 'sync-clientes-batch' ) );
			$log[] = $exception_msg;
			return array(
				'success'     => false,
				'message'     => $exception_msg,
				'processed'   => $processed,
				'errors'      => $errors + 1,
				'log'         => $log,
				'next_offset' => $offset,
				'has_more'    => false,
			);
		} finally {
			if ( class_exists( 'MI_Sync_Lock' ) ) {
				MI_Sync_Lock::release();
			}
		}
	}

	private static function rollback_clientes( array $snapshots, array $ids ): void {
		foreach ( $ids as $id ) {
			if ( ! isset( $snapshots[ $id ] ) ) {
				continue;
			}
			foreach ( $snapshots[ $id ]['meta'] as $meta_key => $meta_values ) {
				delete_user_meta( $id, $meta_key );
				foreach ( $meta_values as $meta_value ) {
					add_user_meta( $id, $meta_key, $meta_value );
				}
			}
			\MiIntegracionApi\helpers\Logger::info( 'Cliente restaurado tras rollback (ID: ' . $id . ')', array( 'context' => 'sync-clientes-batch' ) );
		}
	}

	/**
	 * Sincroniza un cliente con la API externa
	 * 
	 * @param array $cliente Datos del cliente a sincronizar
	 * @return array Resultado de la operación
	 */
	public function sync_cliente($cliente) {
		// ✅ MIGRADO: Usar IdGenerator para operation ID de cliente
		$operation_id = \MiIntegracionApi\Helpers\IdGenerator::generateOperationId('cliente_sync');
		$this->metrics->startOperation($operation_id, 'clientes', 'push');
		
		try {
			if (empty($cliente['dni'])) {
				throw new SyncError('DNI del cliente no proporcionado', 400);
			}

			// Verificar memoria antes de procesar
			if (!$this->metrics->checkMemoryUsage($operation_id)) {
				throw new SyncError('Umbral de memoria alcanzado', 500);
			}

			// Ejecutar la sincronización dentro de una transacción
			$result = TransactionManager::getInstance()->executeInTransaction(
				function() use ($cliente, $operation_id) {
					return $this->retryOperation(
						function() use ($cliente) {
							return $this->sincronizarCliente($cliente);
						},
						[
							'operation_id' => $operation_id,
							'dni' => $cliente['dni'],
							'cliente_id' => $cliente['id'] ?? null
						]
					);
				},
				'clientes',
				$operation_id
			);

			$this->metrics->recordItemProcessed($operation_id, true);
			return [
				'success' => true,
				'message' => 'Cliente sincronizado correctamente',
				'data' => $result
			];

		} catch (SyncError $e) {
			$this->metrics->recordError(
				$operation_id,
				'sync_error',
				$e->getMessage(),
				['dni' => $cliente['dni'] ?? 'unknown'],
				$e->getCode()
			);
			$this->metrics->recordItemProcessed($operation_id, false, $e->getMessage());
			
			$this->logger->error("Error sincronizando cliente", [
				'dni' => $cliente['dni'] ?? 'unknown',
				'error' => $e->getMessage()
			]);
			
			return [
				'success' => false,
				'error' => $e->getMessage(),
				'error_code' => $e->getCode()
			];
		} catch (\Exception $e) {
			$this->metrics->recordError(
				$operation_id,
				'unexpected_error',
				$e->getMessage(),
				['dni' => $cliente['dni'] ?? 'unknown'],
				$e->getCode()
			);
			$this->metrics->recordItemProcessed($operation_id, false, $e->getMessage());
			
			$this->logger->error("Error inesperado sincronizando cliente", [
				'dni' => $cliente['dni'] ?? 'unknown',
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			
			return [
				'success' => false,
				'error' => 'Error inesperado: ' . $e->getMessage(),
				'error_code' => $e->getCode()
			];
		}
	}

	/**
	 * Ejecuta una operación con reintentos automáticos
	 * 
	 * @param callable $operation Operación a ejecutar
	 * @param array $context Contexto de la operación
	 * @param int $maxRetries Número máximo de reintentos
	 * @return mixed Resultado de la operación
	 * @throws \Exception Si la operación falla después de todos los reintentos
	 */
	private function retryOperation(callable $operation, array $context = [], int $maxRetries = 3) {
		// REFACTORIZADO: Usar sistema centralizado de reintentos
		if (class_exists('\\MiIntegracionApi\\Core\\RetryManager')) {
			$retry_manager = new \MiIntegracionApi\Core\RetryManager();
			
			try {
				return $retry_manager->executeWithRetry(
					$operation,
					$context['operation_id'] ?? 'sync_clientes_operation',
					$context,
					'sync_customers' // Tipo de operación para política específica
				);
			} catch (\MiIntegracionApi\ErrorHandling\Exceptions\SyncError $e) {
				// El RetryManager ya maneja los reintentos y logging
				$this->metrics->recordError(
					$context['operation_id'] ?? 'unknown',
					'max_retries_exceeded',
					$e->getMessage(),
					array_merge($context, [
						'error_code' => $e->getCode(),
						'retry_system' => 'centralized'
					]),
					$e->getCode()
				);
				
				throw $e;
			}
		}
		
		// FALLBACK: Implementación legacy si RetryManager no está disponible
		$attempts = 0;
		$lastError = null;
		
		while ($attempts < $maxRetries) {
			try {
				$result = $operation();
				
				if (is_array($result) && isset($result['success']) && !$result['success']) {
					throw new SyncError(
						$result['error'] ?? 'Error desconocido',
						$result['error_code'] ?? 0,
						$context
					);
				}
				
				return $result;
				
			} catch (SyncError $e) {
				$lastError = $e;
				$attempts++;
				
				$this->metrics->recordError(
					$context['operation_id'] ?? 'unknown',
					'retry_attempt',
					$e->getMessage(),
					array_merge($context, [
						'attempt' => $attempts,
						'max_retries' => $maxRetries,
						'error_code' => $lastError->getCode()
					]),
					$lastError->getCode()
				);
				
				$this->logger->warning("Reintentando operación (legacy)", [
					'attempt' => $attempts,
					'max_retries' => $maxRetries,
					'error' => $e->getMessage(),
					'context' => $context
				]);
				
				if ($attempts >= $maxRetries) {
					break;
				}
				
				$delay = pow(2, $attempts) + rand(0, 1000) / 1000;
				usleep($delay * 1000000);
			}
		}
		
		$this->metrics->recordError(
			$context['operation_id'] ?? 'unknown',
			'max_retries_exceeded',
			$lastError ? $lastError->getMessage() : 'Error desconocido',
			array_merge($context, [
				'attempts' => $attempts,
				'max_retries' => $maxRetries
			]),
			$lastError ? $lastError->getCode() : 0
		);
		
		throw $lastError ?? new SyncError(
			'Error después de ' . $maxRetries . ' reintentos',
			0,
			$context
		);
	}

	/**
	 * Procesa un lote de clientes usando la implementación base
	 * 
	 * @param array<int, array<string, mixed>> $batch Lote de clientes
	 * @return array<string, mixed> Resultado del procesamiento
	 */
	protected function processBatch(array $batch): array
	{
		// Usar la implementación base de BatchProcessor
		return parent::processBatch($batch);
	}

	/**
	 * Procesa un cliente individual siguiendo las buenas prácticas de programación
	 * 
	 * @param mixed $cliente Datos del cliente a procesar
	 * @return array<string, mixed> Resultado del procesamiento con estructura consistente
	 * @throws SyncError Si ocurre un error durante el procesamiento
	 */
	protected function processItem($cliente): array
	{
		try {
			// VALIDACIÓN DE ENTRADA (Fail Fast Principle)
			if (empty($cliente) || !is_array($cliente)) {
				throw SyncError::validationError(
					'Datos del cliente inválidos o vacíos',
					['cliente' => $cliente]
				);
			}

			// Validar campos requeridos
			if (empty($cliente['email'])) {
				throw SyncError::validationError(
					'Email del cliente es requerido',
					['cliente_id' => $cliente['ID'] ?? 'unknown']
				);
			}

			// VALIDACIÓN DE EMAIL (Type Safety)
			if (!\MiIntegracionApi\Helpers\Utils::is_email($cliente['email'])) {
				throw SyncError::validationError(
					'Formato de email inválido',
					['email' => $cliente['email']]
				);
			}

			// VALIDACIÓN DE APICONNECTOR (Fail Fast + Graceful Degradation)
			try {
				$apiConnector = $this->getApiConnector();
			} catch (\RuntimeException $e) {
				// Logging detallado para debugging (Monitoring Principle)
				$this->logger->error('ApiConnector no disponible en processItem', [
					'email' => $cliente['email'],
					'error' => $e->getMessage(),
					'context' => 'sync-clientes-processItem'
				]);
				
				// Graceful Degradation: Retornar error estructurado
				return [
					'success' => false,
					'email' => $cliente['email'],
					'error' => 'Servicio de sincronización temporalmente no disponible',
					'code' => 'api_connector_unavailable',
					'action' => 'failed',
					'retryable' => true
				];
			}

			// MAPEO DE DATOS (Single Responsibility)
			$payload_cliente_verial = $this->mapCustomerToVerial($cliente);
			
			if (empty($payload_cliente_verial)) {
				throw SyncError::validationError(
					'Error en el mapeo de datos del cliente',
					['email' => $cliente['email']]
				);
			}

			// VERIFICACIÓN DE DUPLICADOS (Business Logic)
			$duplicateCheck = $this->checkCustomerDuplicates($payload_cliente_verial);
			if (!$duplicateCheck['can_proceed']) {
				return [
					'success' => false,
					'email' => $cliente['email'],
					'error' => $duplicateCheck['reason'],
					'code' => 'duplicate_detected',
					'action' => 'skipped'
				];
			}

			// VERIFICACIÓN DE HASH (Performance Optimization)
			$hashCheck = $this->checkSyncHash($cliente, $payload_cliente_verial);
			if ($hashCheck['hash_unchanged']) {
				return [
					'success' => true,
					'email' => $cliente['email'],
					'action' => 'skipped_hash_unchanged',
					'hash' => $hashCheck['current_hash']
				];
			}

			// SINCRONIZACIÓN CON VERIAL (API Integration)
			$syncResult = $this->syncCustomerWithVerial($payload_cliente_verial, $cliente);
			
			if ($syncResult['success']) {
				// ACTUALIZACIÓN DE METADATOS (Data Persistence)
				$this->updateCustomerMetadata($cliente, $syncResult);
				
				// LOGGING (Monitoring)
				$this->logger->info('Cliente sincronizado exitosamente', [
					'email' => $cliente['email'],
					'action' => $syncResult['action'],
					'verial_id' => $syncResult['verial_id'] ?? null
				]);

				return [
					'success' => true,
					'email' => $cliente['email'],
					'action' => $syncResult['action'],
					'verial_id' => $syncResult['verial_id'] ?? null,
					'hash' => $hashCheck['current_hash']
				];
			} else {
				// MANEJO DE ERRORES (Error Handling)
				$this->handleSyncError($cliente, $syncResult);
				
				return [
					'success' => false,
					'email' => $cliente['email'],
					'error' => $syncResult['error'],
					'code' => $syncResult['error_code'] ?? 'unknown_error',
					'action' => 'failed'
				];
			}

		} catch (SyncError $e) {
			// LOGGING DE ERRORES (Observability)
			$this->logger->error('Error de sincronización en processItem', [
				'email' => $cliente['email'] ?? 'unknown',
				'error' => $e->getMessage(),
				'code' => $e->getCode(),
				'context' => $e->getContext()
			]);

			return [
				'success' => false,
				'email' => $cliente['email'] ?? 'unknown',
				'error' => $e->getMessage(),
				'code' => $e->getCode(),
				'action' => 'error'
			];
		} catch (\Exception $e) {
			// LOGGING DE EXCEPCIONES INESPERADAS (Fail Fast)
			$this->logger->error('Excepción inesperada en processItem', [
				'email' => $cliente['email'] ?? 'unknown',
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			]);

			return [
				'success' => false,
				'email' => $cliente['email'] ?? 'unknown',
				'error' => 'Error interno del sistema: ' . $e->getMessage(),
				'code' => 'internal_error',
				'action' => 'error'
			];
		}
	}

	/**
	 * Mapea un cliente de WooCommerce al formato de Verial
	 * 
	 * @param array<string, mixed> $cliente Datos del cliente de WooCommerce
	 * @return array<string, mixed> Datos del cliente en formato Verial
	 */
	private function mapCustomerToVerial(array $cliente): array
	{
		try {
			// Intentar usar MapCustomer si está disponible
			if (class_exists('\MiIntegracionApi\Helpers\MapCustomer')) {
				// Crear un objeto WC_Customer temporal para el mapeo
				$wcCustomer = $this->createTemporaryWCCustomer($cliente);
				if ($wcCustomer) {
					return \MiIntegracionApi\Helpers\MapCustomer::wc_to_verial($wcCustomer);
				}
			}

			// Fallback a mapeo manual si MapCustomer no está disponible
			return $this->manualCustomerMapping($cliente);

		} catch (\Exception $e) {
			$this->logger->error('Error en mapeo de cliente', [
				'email' => $cliente['email'] ?? 'unknown',
				'error' => $e->getMessage()
			]);
			return [];
		}
	}

	/**
	 * Crea un objeto WC_Customer temporal para el mapeo
	 * 
	 * @param array<string, mixed> $cliente Datos del cliente
	 * @return \WC_Customer|null Cliente temporal o null si falla
	 */
	private function createTemporaryWCCustomer(array $cliente): ?\WC_Customer
	{
		try {
			if (!class_exists('\WC_Customer')) {
				return null;
			}

			$wcCustomer = new \WC_Customer();
			$wcCustomer->set_email($cliente['email'] ?? '');
			$wcCustomer->set_first_name($cliente['first_name'] ?? '');
			$wcCustomer->set_last_name($cliente['last_name'] ?? '');
			$wcCustomer->set_billing_phone($cliente['billing']['phone'] ?? '');
			$wcCustomer->set_billing_address_1($cliente['billing']['address_1'] ?? '');
			$wcCustomer->set_billing_city($cliente['billing']['city'] ?? '');
			$wcCustomer->set_billing_state($cliente['billing']['state'] ?? '');
			$wcCustomer->set_billing_postcode($cliente['billing']['postcode'] ?? '');
			$wcCustomer->set_billing_country($cliente['billing']['country'] ?? '');

			return $wcCustomer;

		} catch (\Exception $e) {
			$this->logger->warning('No se pudo crear WC_Customer temporal', [
				'error' => $e->getMessage()
			]);
			return null;
		}
	}

	/**
	 * Mapeo manual de cliente como fallback
	 * 
	 * @param array<string, mixed> $cliente Datos del cliente
	 * @return array<string, mixed> Datos mapeados
	 */
	private function manualCustomerMapping(array $cliente): array
	{
		return [
			'Email' => $cliente['email'] ?? '',
			'Nombre' => $cliente['first_name'] ?? '',
			'Apellidos' => $cliente['last_name'] ?? '',
			'Telefono' => $cliente['billing']['phone'] ?? '',
			'Direccion' => $cliente['billing']['address_1'] ?? '',
			'Ciudad' => $cliente['billing']['city'] ?? '',
			'Provincia' => $cliente['billing']['state'] ?? '',
			'CodigoPostal' => $cliente['billing']['postcode'] ?? '',
			'Pais' => $cliente['billing']['country'] ?? '',
			'NIF' => $cliente['billing']['nif'] ?? '',
			'MetaDatos' => $cliente['meta_data'] ?? []
		];
	}

	/**
	 * Verifica duplicados del cliente
	 * 
	 * @param array<string, mixed> $payload_cliente_verial Datos del cliente en formato Verial
	 * @return array<string, mixed> Resultado de la verificación
	 */
	private function checkCustomerDuplicates(array $payload_cliente_verial): array
	{
		$email = $payload_cliente_verial['Email'] ?? '';
		
		if (empty($email)) {
			return ['can_proceed' => false, 'reason' => 'Email vacío'];
		}

		// Verificar duplicados por email
		$existing_user = get_user_by('email', $email);
		if ($existing_user && $existing_user->ID != ($payload_cliente_verial['ID'] ?? 0)) {
			return [
				'can_proceed' => false, 
				'reason' => 'Cliente duplicado por email',
				'duplicate_id' => $existing_user->ID
			];
		}

		// Verificar duplicados por NIF/DNI
		$nif = $payload_cliente_verial['NIF'] ?? '';
		if ($nif) {
			$user_query = new \WP_User_Query([
				'meta_key' => 'billing_nif',
				'meta_value' => $nif,
				'number' => 1,
				'exclude' => [$payload_cliente_verial['ID'] ?? 0]
			]);
			$results = $user_query->get_results();
			
			if (!empty($results)) {
				return [
					'can_proceed' => false,
					'reason' => 'Cliente duplicado por NIF/DNI',
					'duplicate_id' => $results[0]->ID
				];
			}
		}

		return ['can_proceed' => true];
	}

	/**
	 * Verifica el hash de sincronización
	 * 
	 * @param array<string, mixed> $cliente Datos del cliente
	 * @param array<string, mixed> $payload_cliente_verial Datos en formato Verial
	 * @return array<string, mixed> Resultado de la verificación
	 */
	private function checkSyncHash(array $cliente, array $payload_cliente_verial): array
	{
		$hash_fields = [
			$payload_cliente_verial['Email'] ?? '',
			$payload_cliente_verial['Nombre'] ?? '',
			$payload_cliente_verial['Telefono'] ?? '',
			$payload_cliente_verial['Direccion'] ?? '',
			$payload_cliente_verial['NIF'] ?? '',
			isset($payload_cliente_verial['MetaDatos']) ? wp_json_encode($payload_cliente_verial['MetaDatos']) : ''
		];

		$current_hash = md5(json_encode($hash_fields));
		$stored_hash = get_user_meta($cliente['ID'] ?? 0, '_verial_sync_hash', true);

		return [
			'current_hash' => $current_hash,
			'stored_hash' => $stored_hash,
			'hash_unchanged' => $stored_hash && $current_hash === $stored_hash
		];
	}

	/**
	 * Sincroniza el cliente con Verial
	 * Implementa Dependency Inversion Principle y Error Handling
	 * 
	 * @param array<string, mixed> $payload_cliente_verial Datos del cliente
	 * @param array<string, mixed> $cliente Datos originales del cliente
	 * @return array<string, mixed> Resultado de la sincronización
	 */
	private function syncCustomerWithVerial(array $payload_cliente_verial, array $cliente): array
	{
		try {
			// Obtener ApiConnector usando inyección de dependencias
			$apiConnector = $this->getApiConnector();
			
			// Determinar si crear o actualizar (Business Logic)
			$verial_id = get_user_meta($cliente['ID'] ?? 0, '_verial_cliente_id', true);
			
			if ($verial_id) {
				$payload_cliente_verial['Id'] = $verial_id;
				$response = $apiConnector->post('ActualizarClienteWS', $payload_cliente_verial);
				$action = 'actualizado';
			} else {
				$response = $apiConnector->post('NuevoClienteWS', $payload_cliente_verial);
				$action = 'creado';
			}

			// Procesar respuesta con validación robusta (Type Safety)
			if (is_wp_error($response)) {
				return [
					'success' => false,
					'error' => $response->get_error_message(),
					'error_code' => $response->get_error_code()
				];
			}

			// Verificar respuesta de Verial (Fail Fast Principle)
			if (isset($response['InfoError']['Codigo']) && intval($response['InfoError']['Codigo']) === 0) {
				// Logging de éxito (Monitoring Principle)
				$this->logger->info('Cliente sincronizado exitosamente con Verial', [
					'email' => $cliente['email'] ?? 'unknown',
					'action' => $action,
					'verial_id' => $response['Id'] ?? null,
					'response_code' => $response['InfoError']['Codigo']
				]);
				
				return [
					'success' => true,
					'action' => $action,
					'verial_id' => $response['Id'] ?? null
				];
			} else {
				$error_desc = $response['InfoError']['Descripcion'] ?? 'Error desconocido de Verial';
				
				// Logging de error (Error Handling)
				$this->logger->error('Error de Verial durante sincronización', [
					'email' => $cliente['email'] ?? 'unknown',
					'error_code' => $response['InfoError']['Codigo'] ?? 'unknown',
					'error_desc' => $error_desc,
					'action' => $action,
					'response' => $response
				]);
				
				return [
					'success' => false,
					'error' => $error_desc,
					'error_code' => $response['InfoError']['Codigo'] ?? 'unknown'
				];
			}

		} catch (\Exception $e) {
			// Logging de excepción (Error Handling + Monitoring)
			$this->logger->error('Excepción durante sincronización con Verial', [
				'email' => $cliente['email'] ?? 'unknown',
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'payload' => $payload_cliente_verial
			]);
			
			return [
				'success' => false,
				'error' => 'Excepción durante sincronización: ' . $e->getMessage(),
				'error_code' => 'sync_exception'
			];
		}
	}

	/**
	 * Actualiza los metadatos del cliente de manera segura
	 * Implementa Resource Management y Error Handling
	 * 
	 * @param array<string, mixed> $cliente Datos del cliente
	 * @param array<string, mixed> $syncResult Resultado de la sincronización
	 */
	private function updateCustomerMetadata(array $cliente, array $syncResult): void
	{
		$user_id = $cliente['ID'] ?? 0;
		
		if (!$user_id) {
			$this->logger->warning('No se puede actualizar metadatos: ID de usuario no válido', [
				'cliente' => $cliente,
				'context' => 'sync-clientes-updateCustomerMetadata'
			]);
			return;
		}
		
		try {
			// Actualizar ID de Verial si está disponible
			if ($syncResult['verial_id']) {
				$update_result = update_user_meta($user_id, '_verial_cliente_id', intval($syncResult['verial_id']));
				
				if ($update_result === false) {
					$this->logger->warning('Error al actualizar ID de Verial', [
						'user_id' => $user_id,
						'verial_id' => $syncResult['verial_id'],
						'context' => 'sync-clientes-updateCustomerMetadata'
					]);
				}
			}

			// Calcular y actualizar hash de sincronización (Performance Optimization)
			$hash_fields = $this->getHashFields($cliente);
			$new_hash = md5(json_encode($hash_fields));
			
			$hash_update_result = update_user_meta($user_id, '_verial_sync_hash', $new_hash);
			if ($hash_update_result === false) {
				$this->logger->warning('Error al actualizar hash de sincronización', [
					'user_id' => $user_id,
					'hash' => $new_hash,
					'context' => 'sync-clientes-updateCustomerMetadata'
				]);
			}
			
			// Actualizar timestamp de última sincronización
			$timestamp_update_result = update_user_meta($user_id, '_verial_sync_last', current_time('mysql'));
			if ($timestamp_update_result === false) {
				$this->logger->warning('Error al actualizar timestamp de sincronización', [
					'user_id' => $user_id,
					'timestamp' => current_time('mysql'),
					'context' => 'sync-clientes-updateCustomerMetadata'
				]);
			}
			
			// Logging de éxito (Monitoring Principle)
			$this->logger->info('Metadatos del cliente actualizados exitosamente', [
				'user_id' => $user_id,
				'verial_id' => $syncResult['verial_id'] ?? null,
				'hash' => $new_hash,
				'context' => 'sync-clientes-updateCustomerMetadata'
			]);

		} catch (\Exception $e) {
			// Logging detallado del error (Error Handling + Monitoring)
			$this->logger->error('Error al actualizar metadatos del cliente', [
				'user_id' => $user_id,
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'context' => 'sync-clientes-updateCustomerMetadata'
			]);
			
			// No relanzar la excepción para evitar fallar toda la sincronización
			// (Graceful Degradation Principle)
		}
	}
	
	/**
	 * Obtiene los campos para calcular el hash de sincronización
	 * Implementa Single Responsibility Principle
	 * 
	 * @param array<string, mixed> $cliente Datos del cliente
	 * @return array<string, mixed> Campos para el hash
	 */
	private function getHashFields(array $cliente): array
	{
		return [
			'email' => $cliente['email'] ?? '',
			'first_name' => $cliente['first_name'] ?? '',
			'last_name' => $cliente['last_name'] ?? '',
			'phone' => $cliente['billing']['phone'] ?? '',
			'address_1' => $cliente['billing']['address_1'] ?? '',
			'city' => $cliente['billing']['city'] ?? '',
			'state' => $cliente['billing']['state'] ?? '',
			'postcode' => $cliente['billing']['postcode'] ?? '',
			'country' => $cliente['billing']['country'] ?? '',
			'nif' => $cliente['billing']['nif'] ?? ''
		];
	}

	/**
	 * Maneja errores de sincronización de manera inteligente
	 * Implementa Error Handling y Resource Management
	 * 
	 * @param array<string, mixed> $cliente Datos del cliente
	 * @param array<string, mixed> $syncResult Resultado de la sincronización
	 */
	private function handleSyncError(array $cliente, array $syncResult): void
	{
		$error_code = $syncResult['error_code'] ?? '';
		$email = $cliente['email'] ?? 'unknown';
		
		// Determinar si el error es reintentable (Business Logic)
		$isRetryable = $this->isErrorRetryable($error_code);
		
		// Agregar a cola de reintentos si es apropiado (Resource Management)
		if ($isRetryable) {
			try {
				self::add_to_retry_queue($email, $syncResult['error']);
				
				$this->logger->info('Cliente agregado a cola de reintentos', [
					'email' => $email,
					'error_code' => $error_code,
					'retryable' => true
				]);
			} catch (\Exception $e) {
				// Logging de error en gestión de reintentos
				$this->logger->warning('Error al agregar cliente a cola de reintentos', [
					'email' => $email,
					'error' => $e->getMessage(),
					'original_error' => $syncResult['error']
				]);
			}
		}
		
		// Logging estructurado del error (Monitoring Principle)
		$this->logger->error('Error de sincronización del cliente', [
			'email' => $email,
			'error' => $syncResult['error'],
			'code' => $error_code,
			'retryable' => $isRetryable,
			'context' => 'sync-clientes-handleSyncError'
		]);
	}
	
	/**
	 * Determina si un error es reintentable basándose en su código
	 * Implementa Business Logic y Configuration over Convention
	 * 
	 * @param string $error_code Código de error
	 * @return bool True si el error es reintentable
	 */
	private function isErrorRetryable(string $error_code): bool
	{
		// Errores de red y servidor son reintentables
		$retryable_patterns = [
			'http_error_5',      // Errores 5xx del servidor
			'connection',        // Problemas de conexión
			'timeout',           // Timeouts
			'rate_limit',        // Límites de tasa
			'temporary',         // Errores temporales
			'network',           // Problemas de red
			'server_unavailable' // Servidor no disponible
		];
		
		foreach ($retryable_patterns as $pattern) {
			if (strpos($error_code, $pattern) !== false) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Obtiene el ApiConnector para la sincronización
	 * Implementa Single Responsibility Principle y Type Safety
	 * 
	 * @return \MiIntegracionApi\Core\ApiConnector
	 * @throws \RuntimeException Si el ApiConnector no está disponible
	 */
	private function getApiConnector(): \MiIntegracionApi\Core\ApiConnector
	{
		// Validación de tipo estricto (Type Safety)
		if (!isset($this->apiConnector) || !$this->apiConnector instanceof \MiIntegracionApi\Core\ApiConnector) {
			// Logging para debugging (Monitoring Principle)
			$this->logger->error('ApiConnector no disponible o inválido', [
				'apiConnector_set' => isset($this->apiConnector),
				'apiConnector_type' => $this->apiConnector ? get_class($this->apiConnector) : 'null',
				'context' => 'sync-clientes-getApiConnector'
			]);
			
			// Fail Fast: Lanzar excepción inmediatamente
			throw new \RuntimeException(
				'ApiConnector no disponible para sincronización. Verificar configuración.',
				500
			);
		}
		
		return $this->apiConnector;
	}

	/**
	 * Crea una instancia por defecto del ApiConnector siguiendo Graceful Degradation
	 * 
	 * @return \MiIntegracionApi\Core\ApiConnector
	 * @throws \RuntimeException Si no se puede crear el ApiConnector
	 */
	private function createDefaultApiConnector(): \MiIntegracionApi\Core\ApiConnector
	{
		try {
			// Intentar crear ApiConnector con configuración por defecto
			$apiConnector = new \MiIntegracionApi\Core\ApiConnector();
			
			// Validar que la instancia sea válida (Fail Fast Principle)
			if (!$apiConnector instanceof \MiIntegracionApi\Core\ApiConnector) {
				throw new \RuntimeException('ApiConnector creado no es del tipo esperado');
			}
			
			$this->logger->info('ApiConnector por defecto creado exitosamente', [
				'class' => get_class($apiConnector),
				'context' => 'sync-clientes-constructor'
			]);
			
			return $apiConnector;
			
		} catch (\Exception $e) {
			// Logging detallado para debugging (Monitoring Principle)
			$this->logger->critical('Error crítico creando ApiConnector por defecto', [
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTraceAsString(),
				'context' => 'sync-clientes-constructor'
			]);
			
			// Fail Fast: Lanzar excepción inmediatamente
			throw new \RuntimeException(
				'No se pudo crear ApiConnector por defecto: ' . $e->getMessage(),
				0,
				$e
			);
		}
	}

	/**
	 * Método principal unificado para sincronización de clientes
	 * Implementa Single Responsibility Principle y elimina duplicación (DRY)
	 * 
	 * @param \MiIntegracionApi\Core\ApiConnector $api_connector
	 * @param int $batch_size
	 * @param int $offset
	 * @param array $filters
	 * @return array
	 */
	public static function sync_unified(
		\MiIntegracionApi\Core\ApiConnector $api_connector,
		$batch_size = 50,
		$offset = 0,
		array $filters = []
	): array {
		try {
			// Crear instancia siguiendo Dependency Inversion Principle
			$syncClientes = new self($api_connector);
			
			// Obtener clientes a sincronizar (Separation of Concerns)
			$clientes = $syncClientes->getClientesToSync($filters, $batch_size, $offset);
			
			if (empty($clientes)) {
				return [
					'success' => true,
					'message' => __('No se encontraron clientes para sincronizar.', 'mi-integracion-api'),
					'processed' => 0,
					'errors' => 0,
					'next_offset' => $offset,
					'has_more' => false
				];
			}
			
			// Usar BatchProcessor para procesamiento moderno (Open/Closed Principle)
			$result = $syncClientes->process(
				$clientes,
				$batch_size,
				fn($cliente) => $syncClientes->processItem($cliente)
			);
			
			// Formatear resultado para compatibilidad (Graceful Degradation)
			return [
				'success' => $result['success'],
				'message' => sprintf(
					__('Sincronización completada. Procesados: %d, Errores: %d.', 'mi-integracion-api'),
					$result['processed'],
					$result['errors']
				),
				'processed' => $result['processed'],
				'errors' => $result['errors'],
				'next_offset' => $offset + $batch_size,
				'has_more' => count($clientes) === $batch_size,
				'details' => $result['details'] ?? []
			];
			
		} catch (\Exception $e) {
			$logger = self::getLogger();
			$logger->error('Error en sincronización unificada de clientes', [
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			]);
			
			return [
				'success' => false,
				'message' => sprintf(
					__('Error durante la sincronización: %s', 'mi-integracion-api'),
					$e->getMessage()
				),
				'processed' => 0,
				'errors' => 1,
				'next_offset' => $offset,
				'has_more' => false
			];
		}
	}
	
	/**
	 * Obtiene clientes para sincronización siguiendo Separation of Concerns
	 * 
	 * @param array $filters
	 * @param int $batch_size
	 * @param int $offset
	 * @return array
	 */
	private function getClientesToSync(array $filters, int $batch_size, int $offset): array
	{
		// Usar helper avanzado si está disponible (Graceful Degradation)
		if (class_exists('\MiIntegracionApi\Helpers\FilterCustomers')) {
			$query_args = array_merge($filters, [
				'role' => 'customer',
				'number' => $batch_size,
				'offset' => $offset
			]);
			
			$filtered_user_ids = \MiIntegracionApi\Helpers\FilterCustomers::advanced($query_args);
			
			if (!empty($filtered_user_ids)) {
				return array_map(function($user_id) {
					return get_userdata($user_id);
				}, $filtered_user_ids);
			}
		}
		
		// Fallback a consulta estándar (Fail Fast + Graceful Degradation)
		$args = [
			'role' => 'customer',
			'orderby' => 'ID',
			'order' => 'ASC',
			'fields' => 'all',
			'number' => $batch_size,
			'offset' => $offset
		];
		
		$user_query = new \WP_User_Query($args);
		return $user_query->get_results();
	}

	/**
	 * Método de migración gradual que permite elegir entre flujos
	 * Implementa Graceful Degradation y Configuration over Convention
	 * 
	 * @param \MiIntegracionApi\Core\ApiConnector $api_connector
	 * @param int $batch_size
	 * @param int $offset
	 * @param array $options
	 * @return array
	 */
	public static function sync_adaptive(
		\MiIntegracionApi\Core\ApiConnector $api_connector,
		$batch_size = 50,
		$offset = 0,
		array $options = []
	): array {
		// Configuración flexible para elegir flujo (Configuration over Convention)
		$use_new_system = $options['use_new_system'] ?? get_option('mia_use_new_sync_system', false);
		$fallback_to_legacy = $options['fallback_to_legacy'] ?? true;
		$force_legacy = $options['force_legacy'] ?? false;
		
		// Si se fuerza el sistema legacy, usar directamente
		if ($force_legacy) {
			return self::sync($api_connector, $batch_size, $offset);
		}
		
		// Intentar sistema nuevo si está habilitado
		if ($use_new_system) {
			try {
				$result = self::sync_unified($api_connector, $batch_size, $offset, $options['filters'] ?? []);
				
				$logger = self::getLogger();
				$logger->info('Sincronización exitosa usando nuevo sistema', [
					'system' => 'new',
					'processed' => $result['processed'],
					'errors' => $result['errors']
				]);
				
				return $result;
				
			} catch (\Exception $e) {
				$logger = self::getLogger();
				$logger->warning('Nuevo sistema falló, intentando legacy', [
					'error' => $e->getMessage(),
					'fallback' => $fallback_to_legacy
				]);
				
				// Graceful Degradation: Fallback al sistema legacy
				if ($fallback_to_legacy) {
					return self::sync($api_connector, $batch_size, $offset);
				} else {
					// Re-lanzar excepción si no se permite fallback
					throw $e;
				}
			}
		}
		
		// Sistema nuevo no habilitado, usar legacy
		return self::sync($api_connector, $batch_size, $offset);
	}
	
	/**
	 * Habilita el nuevo sistema de sincronización
	 * Implementa Configuration over Convention
	 * 
	 * @return bool
	 */
	public static function enableNewSystem(): bool
	{
		$result = update_option('mia_use_new_sync_system', true);
		
		if ($result) {
			$logger = self::getLogger();
			$logger->info('Nuevo sistema de sincronización habilitado');
			$logger->info('Nuevo sistema de sincronización habilitado');
		}
		
		return $result;
	}
	
	/**
	 * Deshabilita el nuevo sistema de sincronización
	 * Implementa Configuration over Convention
	 * 
	 * @return bool
	 */
	public static function disableNewSystem(): bool
	{
		$result = update_option('mia_use_new_sync_system', false);
		
		if ($result) {
			$logger = self::getLogger();
			$logger->info('Nuevo sistema de sincronización deshabilitado');
			$logger->info('Nuevo sistema de sincronización deshabilitado');
		}
		
		return $result;
	}
	
	/**
	 * Obtiene el estado del sistema de sincronización
	 * Implementa Monitoring y Configuration over Convention
	 * 
	 * @return array
	 */
	public static function getSystemStatus(): array
	{
		$new_system_enabled = get_option('mia_use_new_sync_system', false);
		$legacy_system_available = method_exists(self::class, 'sync');
		$new_system_available = method_exists(self::class, 'sync_unified');
		
		return [
			'new_system' => [
				'enabled' => $new_system_enabled,
				'available' => $new_system_available,
				'status' => $new_system_available ? 'ready' : 'not_implemented'
			],
			'legacy_system' => [
				'enabled' => !$new_system_enabled,
				'available' => $legacy_system_available,
				'status' => $legacy_system_available ? 'ready' : 'not_implemented'
			],
			'recommendation' => $new_system_available ? 'migrate_to_new' : 'keep_legacy'
		];
	}
}
// Fin de la clase MI_Sync_Clientes
