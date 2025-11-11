<?php declare(strict_types=1);
/**
 * Clase base abstracta para los endpoints de la API de Verial
 * 
 * Esta clase proporciona la funcionalidad común para todos los endpoints
 * de la API de Verial, incluyendo manejo de conexión, logging básico,
 * procesamiento de respuestas y gestión de caché.
 * 
 * @package MiIntegracionApi\Endpoints
 * @since 1.0.0
 * @author Mi Integración API
 */

namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Helpers\RestHelpers;
use MiIntegracionApi\Core\CacheConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Base {


	/**
	 * Instancia del conector de la API
	 * 
	 * @var ApiConnector
	 * @since 1.0.0
	 */
	protected ApiConnector $connector;

	/**
	 * Nombre del endpoint específico en la API de Verial
	 * 
	 * Debe ser definido por la subclase con el nombre exacto del endpoint.
	 * 
	 * @var string
	 * @since 1.0.0
	 */
	public const ENDPOINT_NAME = '';

	/**
	 * Prefijo para la clave de caché
	 * 
	 * Debe ser definido por la subclase si usa caché.
	 * 
	 * @var string
	 * @since 1.0.0
	 */
	public const CACHE_KEY_PREFIX = 'mia_endpoint_';

	/**
	 * Duración de la caché en segundos
	 * 
	 * Usa constantes centralizadas para mantener consistencia.
	 * 
	 * @var int
	 * @since 1.0.0
	 */
	public const CACHE_EXPIRATION = CacheConfig::CACHE_EXPIRATION_1_HOUR; // Default a 1 hora

	/**
	 * Constructor de la clase base
	 * 
	 * Inicializa el conector de API que será utilizado por todos los endpoints.
	 *
	 * @param ApiConnector $connector Instancia del conector de la API
	 * @since 1.0.0
	 */
	public function __construct( ApiConnector $connector ) {
		$this->connector = $connector;
	}

	/**
	 * Método estático para instanciar la clase.
	 *
	 * @param ApiConnector $connector Instancia del conector.
	 * @return static
	 * @phpstan-return static
	 */
	public static function make( ApiConnector $connector ): static {
		/** @phpstan-ignore-next-line new.static */
		return new static( $connector );
	}

	/**
	 * Método abstracto para registrar la ruta REST específica del endpoint.
	 * Debe ser implementado por cada subclase.
	 */
	abstract public function register_route(): void;

	/**
	 * Método abstracto para definir los argumentos del endpoint REST.
	 * Debe ser implementado por cada subclase.
	 *
	 * @param bool $is_update
	 * @return array<string, mixed>
	 */
	abstract public function get_endpoint_args( bool $is_update = false ): array;

	/**
	 * Método abstracto para ejecutar la lógica principal del endpoint.
	 * Debe ser implementado por cada subclase.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	abstract public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface;

	/**
	 * Verifica los permisos para acceder al endpoint.
	 * Puede ser sobrescrito por subclases si necesitan permisos diferentes.
	 *
	 * @param \WP_REST_Request $request Datos de la solicitud.
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface True si tiene permiso, SyncResponseInterface si no.
	 */
	public function permissions_check( \WP_REST_Request $request ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( ! current_user_can( 'manage_options' ) ) {
			$msg = esc_html__( 'No tienes permiso para realizar esta acción.', 'mi-integracion-api' );
			$msg = is_string( $msg ) ? $msg : 'No tienes permiso para realizar esta acción.';
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				[],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'rest_forbidden',
					$msg,
					['status' => RestHelpers::rest_authorization_required_code()]
				),
				RestHelpers::rest_authorization_required_code(),
				$msg,
				['endpoint' => 'permissions_check']
			);
		}
		return true;
	}

	/**
	 * Procesa la respuesta cruda de la API de Verial, verificando errores comunes.
	 *
	 * @param array|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface $verial_response La respuesta del ApiConnector.
	 * @param string $endpoint_context_for_log Contexto para el logging (ej. 'GetArticulosWS').
	 * @return array|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Los datos de la respuesta si es exitosa, o SyncResponseInterface.
	 */
	protected function process_verial_response( array|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface $verial_response, string $endpoint_context_for_log = '' ): array|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( $verial_response instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface && !$verial_response->isSuccess() ) {
			if ( class_exists( '\\MiIntegracionApi\\Helpers\\Logger' ) ) {
				$msg     = $verial_response->getMessage();
				$log_msg = sprintf( __( "[API Endpoint Error] %s - Error devuelto por ApiConnector: %s", 'mi-integracion-api' ), $endpoint_context_for_log, $msg );
				$logger = new \MiIntegracionApi\Helpers\Logger('endpoint-error');
				$logger->error(
					$log_msg,
					array( 'context' => 'mia-endpoint-' . strtolower( $endpoint_context_for_log ) )
				);
			}
			return $verial_response;
		}
		if ( ! is_array( $verial_response ) || ! isset( $verial_response['InfoError'] ) || ! is_array( $verial_response['InfoError'] ) ) {
			if ( class_exists( '\\MiIntegracionApi\\Helpers\\Logger' ) ) {
				$logger = new \MiIntegracionApi\Helpers\Logger('endpoint-error');
				$logger->error(
					'Respuesta inesperada de la API de Verial',
					array( 'context' => 'mia-endpoint-' . strtolower( $endpoint_context_for_log ) )
				);
			}
			// Asegurar que el mensaje es string
			$msg = __( 'Respuesta inesperada de la API de Verial.', 'mi-integracion-api' );
			$msg = is_string( $msg ) ? $msg : 'Respuesta inesperada de la API de Verial.';
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['verial_response' => $verial_response],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'verial_api_unexpected_response',
					$msg,
					['status' => 500, 'verial_response' => $verial_response]
				),
				500,
				$msg,
				['endpoint' => $endpoint_context_for_log]
			);
		}
		/** @var array{InfoError: array<string, mixed>} $verial_response */
		$info_error        = $verial_response['InfoError'];
		$codigo            = isset( $info_error['Codigo'] ) && ( is_string( $info_error['Codigo'] ) || is_int( $info_error['Codigo'] ) ) ? (int) $info_error['Codigo'] : -1;
		$error_code_verial = $codigo;
		$reflection        = new \ReflectionClass( get_called_class() );
		$constants         = $reflection->getConstants();
		$success_code      = isset( $constants['VERIAL_ERROR_SUCCESS'] ) ? $constants['VERIAL_ERROR_SUCCESS'] : 0;
		if ( $error_code_verial !== $success_code ) {
			$error_description = isset( $info_error['Descripcion'] ) ? $info_error['Descripcion'] : null;
			$error_message     = '';
			if ( is_string( $error_description ) ) {
				$error_message = $error_description;
			} elseif ( is_int( $error_description ) ) {
				$error_message = (string) $error_description;
			} else {
				$default_msg   = __( 'Error desconocido de Verial.', 'mi-integracion-api' );
				$error_message = is_string( $default_msg ) ? $default_msg : 'Error desconocido de Verial.';
			}
			if ( class_exists( '\\MiIntegracionApi\\Helpers\\Logger' ) ) {
				$logger = new \MiIntegracionApi\Helpers\Logger('endpoint-error');
				$logger->error(
					sprintf( __( "[API Endpoint Error] %s - Error Verial (Código: %s): %s", 'mi-integracion-api' ), $endpoint_context_for_log, $error_code_verial, $error_message ),
					array( 'context' => 'mia-endpoint-' . strtolower( $endpoint_context_for_log ) )
				);
			}
			$error_slug_name = array_search( $error_code_verial, $constants, true );
			$error_slug      = $error_slug_name ? strtolower( $error_slug_name ) : 'verial_error_' . $error_code_verial;
			$http_status     = 400;
			if ( defined( get_called_class() . '::VERIAL_ERROR_DOC_NOT_FOUND_FOR_MODIFICATION' ) &&
				$error_code_verial === constant( get_called_class() . '::VERIAL_ERROR_DOC_NOT_FOUND_FOR_MODIFICATION' )
			) {
				$http_status = 404;
			} elseif ( defined( get_called_class() . '::VERIAL_ERROR_MODIFICATION_NOT_ALLOWED' ) &&
				$error_code_verial === constant( get_called_class() . '::VERIAL_ERROR_MODIFICATION_NOT_ALLOWED' )
			) {
				$http_status = 403;
			}
			$sanitized_error     = sanitize_text_field( $error_message );
			$final_error_message = is_string( $sanitized_error ) ? $sanitized_error : 'Error en la API de Verial';
			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['verial_response' => $verial_response],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'verial_api_error_' . $error_slug,
					$final_error_message,
					['status' => $http_status, 'verial_response' => $verial_response]
				),
				$http_status,
				$final_error_message,
				['endpoint' => $endpoint_context_for_log, 'verial_error_code' => $error_code_verial]
			);
		}
		return $verial_response;
	}

	// Métodos de caché movidos a CacheableTrait

	/**
	 * Funciones de validación comunes que pueden ser usadas por las subclases en `get_endpoint_args`.
	 */
	/**
	 * @param string                  $value
	 * @param \WP_REST_Request<mixed> $request
	 * @param string                  $key
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public function validate_date_format_optional( string $value, \WP_REST_Request $request, string $key ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( $value === '' ) {
			return true;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$parts = explode( '-', $value );
			if ( count( $parts ) === 3 && checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
				return true;
			}
		}
		$error_template = esc_html__( '%s debe ser una fecha válida en formato YYYY-MM-DD.', 'mi-integracion-api' );
		$error_template = is_string( $error_template ) ? $error_template : '%s debe ser una fecha válida en formato YYYY-MM-DD.';
		$error_message = sprintf( $error_template, $key );
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

	/**
	 * @param string                  $value
	 * @param \WP_REST_Request<mixed> $request
	 * @param string                  $key
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public function validate_time_format_optional( string $value, \WP_REST_Request $request, string $key ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( $value === '' ) {
			return true;
		}
		if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $value ) ) {
			return true;
		}
		$error_template = esc_html__( '%s debe ser una hora válida en formato HH:MM.', 'mi-integracion-api' );
		$error_template = is_string( $error_template ) ? $error_template : '%s debe ser una hora válida en formato HH:MM.';
		$error_message = sprintf( $error_template, $key );
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
			['endpoint' => 'validate_time_format_optional']
		);
	}

	/**
	 * @param string                  $value
	 * @param \WP_REST_Request<mixed> $request
	 * @param string                  $key
	 * @return float|null
	 */
	public function sanitize_decimal_text_to_float( string $value, \WP_REST_Request $request, string $key ): ?float {
		return $value !== '' ? (float) str_replace( ',', '.', $value ) : null;
	}

	/**
	 * @param string                  $value
	 * @param \WP_REST_Request<mixed> $request
	 * @param string                  $key
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public function validate_positive_numeric_strict( string $value, \WP_REST_Request $request, string $key ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( is_numeric( $value ) && (float) $value > 0 ) {
			return true;
		}
		$error_msg = esc_html__( '%s debe ser un valor numérico estrictamente positivo.', 'mi-integracion-api' );
		$error_msg = is_string( $error_msg ) ? $error_msg : '%s debe ser un valor numérico estrictamente positivo.';
		$error_message = sprintf( $error_msg, $key );
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
			['endpoint' => 'validate_positive_numeric_strict']
		);
	}

	/**
	 * @param string                  $value
	 * @param \WP_REST_Request<mixed> $request
	 * @param string                  $key
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public function validate_email( string $value, \WP_REST_Request $request, string $key ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( empty( $value ) ) {
			return true;
		}
		if ( is_email( $value ) ) {
			return true;
		}
		$error_msg = esc_html__( '%s debe ser un correo electrónico válido.', 'mi-integracion-api' );
		$error_msg = is_string( $error_msg ) ? $error_msg : '%s debe ser un correo electrónico válido.';
		$error_message = sprintf( $error_msg, $key );
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
			['endpoint' => 'validate_email']
		);
	}

	/**
	 * @param string                  $value
	 * @param \WP_REST_Request<mixed> $request
	 * @param string                  $key
	 * @return bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public function validate_phone_number( string $value, \WP_REST_Request $request, string $key ): bool|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		if ( $value === '' ) {
			return true;
		}
		// Asegurar que es un string antes de preg_replace
		$cleanPhone = preg_replace( '/[\s\-\(\)\+]/', '', (string) $value ); // Forzar string
		// Comprobar que el resultado es un string (lo es siempre, pero PHPStan necesita la garantía)
		$cleanPhone = is_string( $cleanPhone ) ? $cleanPhone : '';
		if ( preg_match( '/^[\d\s\-\(\)\+]{9,}$/', $value ) && preg_match( '/\d{9,}/', $cleanPhone ) ) {
			return true;
		}
		$error_msg = esc_html__( '%s debe ser un número de teléfono válido.', 'mi-integracion-api' );
		$error_msg = is_string( $error_msg ) ? $error_msg : '%s debe ser un número de teléfono válido.';
		$error_message = sprintf( $error_msg, $key );
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
			['endpoint' => 'validate_phone_number']
		);
	}

	/**
	 * @param string                  $value
	 * @param \WP_REST_Request<mixed> $request
	 * @param string                  $key
	 * @return string
	 */
	public function sanitize_simple_html( string $value, \WP_REST_Request $request, string $key ): string {
		if ( empty( $value ) ) {
			return '';
		}
		$allowed_tags = array(
			'a'      => array(
				'href'   => array(),
				'title'  => array(),
				'target' => array(),
			),
			'br'     => array(),
			'p'      => array(),
			'b'      => array(),
			'strong' => array(),
			'i'      => array(),
			'em'     => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
		);
		$sanitized    = wp_kses( $value, $allowed_tags );
		return is_string( $sanitized ) ? $sanitized : '';
	}

	/**
	 * Valida los parámetros de la solicitud según reglas definidas
	 *
	 * @param \WP_REST_Request<array> $request La solicitud REST
	 * @param array                   $rules Las reglas de validación
	 * @return array|\MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface Array con los datos validados o SyncResponseInterface si hay error
	 */
	protected function validate_request_params( $request, $rules ) {
		$data = $request->get_params();

		// Usar la nueva clase unificada de validación
		$result = \MiIntegracionApi\Core\InputValidation::validate_data( $data, $rules );

		if ( ! $result['valid'] ) {
			$errors = array();

			foreach ( $result['errors'] as $field => $field_errors ) {
				if ( ! empty( $field_errors ) ) {
					$first_error = reset( $field_errors );
					$errors[]    = sprintf(
						/* translators: 1: nombre del campo, 2: mensaje de error */
						__( 'Campo %1$s: %2$s', 'mi-integracion-api' ),
						$field,
						$first_error['message']
					);
				}
			}

			$error_message = implode( ' ', $errors );

			return new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
				false,
				['errors' => $errors, 'data' => $data],
				new \MiIntegracionApi\ErrorHandling\Exceptions\SyncError(
					'invalid_parameters',
					$error_message,
					['status' => 400, 'errors' => $errors]
				),
				400,
				$error_message,
				['endpoint' => 'validate_request_params']
			);
		}

		return $result['sanitized'];
	}

	/**
	 * Sanitiza los parámetros de la solicitud
	 *
	 * @param array $params Los parámetros a sanitizar
	 * @param array $types Los tipos de cada parámetro
	 * @return array Los parámetros sanitizados
	 */
	protected function sanitize_params( $params, $types ) {
		$sanitized = array();

		foreach ( $params as $key => $value ) {
			$type              = isset( $types[ $key ] ) ? $types[ $key ] : 'text';
			$sanitized[ $key ] = \MiIntegracionApi\Core\InputValidation::sanitize( $value, $type );
		}

		return $sanitized;
	}

	/**
	 * Obtiene datos en caché si existen.
	 *
	 * @param array $key La clave de caché
	 * @return mixed|false Los datos en caché o false si no existen
	 */
	public function get_cached_data(array $key): mixed {
		$cached_data = get_transient(static::CACHE_KEY_PREFIX . $key);
		return $cached_data !== false ? $cached_data : null;
	}

	/**
	 * Guarda datos en caché.
	 *
	 * @param array $key La clave de caché
	 * @param mixed $data Los datos a guardar
	 * @param int|null $expiration Tiempo de expiración en segundos
	 * @return bool True si se guardó correctamente, false si no
	 */
	public function set_cached_data(array $key, $data, ?int $expiration = null): bool {
		$expiration = $expiration ?? $this->get_cache_expiration();
		return set_transient(static::CACHE_KEY_PREFIX . $key, $data, $expiration);
	}

	/**
	 * Establece el tiempo de expiración de la caché.
	 *
	 * @param int $seconds Tiempo en segundos.
	 * @return void
	 */
	protected $cache_expiration;

	public function set_cache_expiration(int $seconds) {
		$this->cache_expiration = $seconds;
	}

	public function get_cache_expiration(): int {
		// Si se ha establecido un TTL específico, usarlo
		if ($this->cache_expiration !== null) {
			return $this->cache_expiration;
		}
		
		// ✅ MEJORADO: Usar TTL configurado por endpoint desde CacheManager
		$endpoint_name = defined('static::ENDPOINT_NAME') ? static::ENDPOINT_NAME : '';
		if (!empty($endpoint_name)) {
			try {
				$cache_manager = \MiIntegracionApi\CacheManager::get_instance();
				$endpoint_ttl = $cache_manager->getEndpointTTL($endpoint_name);
				
				// Si retorna 0, significa que está deshabilitado, usar fallback
				if ($endpoint_ttl > 0) {
					return $endpoint_ttl;
				}
				// Si está deshabilitado (retorna 0), continuar con fallbacks
			} catch (\Exception $e) {
				// En caso de error, continuar con fallbacks
				if (class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
					$logger = new \MiIntegracionApi\Helpers\Logger('endpoint-cache');
					$logger->warning('Error obteniendo TTL por endpoint, usando fallback', [
						'endpoint' => $endpoint_name,
						'error' => $e->getMessage()
					]);
				}
			}
		}
		
		// Si la subclase tiene una constante CACHE_EXPIRATION, usarla
		if (defined('static::CACHE_EXPIRATION')) {
			return static::CACHE_EXPIRATION;
		}
		
		// Fallback: Usar el TTL recomendado para este endpoint basado en su nombre (método antiguo)
		if (!empty($endpoint_name)) {
			return CacheConfig::get_endpoint_cache_ttl($endpoint_name);
		}
		
		// Fallback final al default
		return self::CACHE_EXPIRATION;
	}

	/**
	 * Devuelve una respuesta estándar de éxito para endpoints.
	 *
	 * @param mixed $data Datos principales a devolver (array, objeto, etc.)
	 * @param array $extra (opcional) Datos extra a incluir en la respuesta raíz
	 * @return array Respuesta estándar: ['success' => true, 'data' => $data, ...$extra]
	 *
	 * @example
	 *   return $this->format_success_response($datos_formateados);
	 */
	protected function format_success_response($data, array $extra = []): array {
		return array_merge([
			'success' => true,
			'data'    => $data,
		], $extra);
	}
}
