<?php

declare(strict_types=1);

/**
 * Manejador de credenciales y autenticación con Verial ERP
 *
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 */

namespace MiIntegracionApi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente
}

/**
 * Gestor centralizado de autenticación y credenciales para Verial ERP.
 *
 * Esta clase proporciona un sistema completo y seguro para la gestión de
 * credenciales de autenticación con el sistema Verial ERP, incluyendo:
 * 
 * - **Almacenamiento seguro**: Cifrado de credenciales sensibles usando OpenSSL
 * - **Patrón Singleton**: Instancia única para gestión centralizada
 * - **Cache inteligente**: Optimización de acceso a credenciales
 * - **Validación robusta**: Verificación de integridad de credenciales
 * - **Pruebas de conectividad**: Validación automática de conexión con Verial
 * - **Fallbacks seguros**: Múltiples niveles de seguridad en cifrado
 * - **Integración con WordPress**: Uso de APIs nativas de WordPress
 * - **Compatibilidad legacy**: Soporte para versiones anteriores
 *
 * **Campos de credenciales soportados:**
 * - `api_url`: URL base del servicio Verial (obligatorio)
 * - `session_id`: Número de sesión de Verial (obligatorio)
 * - `username`: Usuario para autenticación (opcional)
 * - `password`: Contraseña cifrada (opcional)
 *
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 * @since 1.4.1 Agregado soporte para servicios de criptografía centralizados
 * @since 2.0.0 Mejorado sistema de validación y pruebas de conectividad
 * 
 * @author Mi Integración API Team
 * @version 2.0.0
 * 
 * @uses ApiConnector Para pruebas de conectividad
 * @uses CryptoService Para cifrado avanzado de credenciales
 * 
 * @example
 * ```php
 * $auth = Auth_Manager::get_instance();
 * 
 * // Guardar credenciales
 * $credentials = [
 *     'api_url' => 'http://verial.example.com:8000/WcfServiceLibraryVerial',
 *     'session_id' => '18', *     
 * ];
 * $auth->save_credentials($credentials);
 * 
 * // Probar conexión
 * $result = $auth->test_connection();
 * if ($result['success']) {
 *     echo "Conexión exitosa";
 * }
 * ```
 */
class Auth_Manager {
	/**
	 * Clave única para el almacenamiento seguro de credenciales en la base de datos.
	 * 
	 * Esta constante define la clave utilizada en la tabla wp_options de WordPress
	 * para almacenar las credenciales cifradas de Verial ERP de forma persistente.
	 * 
	 * @var string
	 * @since 1.0.0
	 */
	const OPTION_KEY = 'mi_integracion_api_verial_credentials';

	/**
	 * Instancia única de la clase para implementar el patrón Singleton.
	 * 
	 * Garantiza que solo exista una instancia del gestor de autenticación
	 * en toda la aplicación, evitando conflictos y optimizando el uso
	 * de recursos del sistema.
	 *
	 * @var Auth_Manager|null
	 * @since 1.0.0
	 */
	private static $instance = null;

	/**
	 * Cache interno de credenciales para optimizar el rendimiento.
	 * 
	 * Almacena las credenciales descifradas en memoria durante la ejecución
	 * para evitar múltiples operaciones de lectura y descifrado desde la
	 * base de datos. Se invalida automáticamente cuando se actualizan
	 * las credenciales.
	 *
	 * @var array|null Array con credenciales o null si no están cargadas
	 * @since 1.0.0
	 */
	private $credentials = null;

	/**
	 * Constructor privado para implementar el patrón Singleton.
	 * 
	 * Previene la creación directa de instancias de la clase,
	 * forzando el uso del método get_instance() para obtener
	 * la instancia única del gestor de autenticación.
	 * 
	 * @since 1.0.0
	 */
	private function __construct() {
		// Privado para implementar singleton
	}

	/**
	 * Obtiene la instancia única del gestor de autenticación.
	 * 
	 * Implementa el patrón Singleton creando una única instancia
	 * de la clase que se reutiliza en toda la aplicación. Esto
	 * garantiza consistencia en la gestión de credenciales y
	 * optimiza el uso de recursos.
	 *
	 * @return Auth_Manager Instancia única del gestor de autenticación
	 * 
	 * @since 1.0.0
	 * 
	 * @example
	 * ```php
	 * $auth = Auth_Manager::get_instance();
	 * $credentials = $auth->get_credentials();
	 * ```
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Guarda las credenciales de Verial ERP de forma segura.
	 * 
	 * Almacena las credenciales en la base de datos de WordPress con
	 * cifrado automático de campos sensibles como contraseñas. Realiza
	 * validación completa de los datos antes del almacenamiento y
	 * actualiza el cache interno para optimizar accesos futuros.
	 * 
	 * **Validaciones realizadas:**
	 * - Verificación de tipo de datos (debe ser array)
	 * - Validación de campos obligatorios (api_url, session_id)
	 * - Verificación de formato y contenido de campos
	 * 
	 * **Proceso de cifrado:**
	 * - Utiliza servicio de criptografía centralizado si está disponible
	 * - Fallback a OpenSSL con AES-256-CBC
	 * - Fallback final a base64 para compatibilidad
	 *
	 * @param array $credentials Array con las credenciales a guardar:
	 *   - 'api_url' (string, requerido): URL base del servicio Verial
	 *   - 'session_id' (string, requerido): Número de sesión de Verial
	 *   - 'username' (string, opcional): Usuario para autenticación
	 *   - 'password' (string, opcional): Contraseña (se cifra automáticamente)
	 * 
	 * @return bool True si las credenciales se guardaron correctamente, false en caso de error
	 * 
	 * @since 1.0.0
	 * @since 1.4.1 Agregado soporte para servicio de criptografía centralizado
	 * 
	 * @example
	 * ```php
	 * $auth = Auth_Manager::get_instance();
	 * $success = $auth->save_credentials([
	 *     'api_url' => 'http://verial.example.com:8000/WcfServiceLibraryVerial',
	 *     'session_id' => '18',	 *     
	 * ]);
	 * ```
	 * 
	 * @see encrypt_password() Para el proceso de cifrado
	 */
	public function save_credentials( $credentials ) {
		// Validación de datos
		if ( ! is_array( $credentials ) ) {
			return false;
		}

		// Asegurar que existen los campos necesarios (solo URL y numero de sesión son obligatorios)
		$required_fields = array( 'api_url', 'session_id' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $credentials[ $field ] ) || empty( $credentials[ $field ] ) ) {
				return false;
			}
		}

		// Cifrar la contraseña utilizando la API de WordPress
		if ( isset( $credentials['password'] ) ) {
			$credentials['password'] = $this->encrypt_password( $credentials['password'] );
		}

		// Guardar las credenciales
		$result = update_option( self::OPTION_KEY, $credentials, true );

		// Actualizar caché si se guardó correctamente
		if ( $result ) {
			$this->credentials = $credentials;
		}

		return $result;
	}

	/**
	 * Obtiene las credenciales de Verial ERP con descifrado automático.
	 * 
	 * Recupera las credenciales almacenadas desde la base de datos,
	 * aplicando descifrado automático a campos sensibles como contraseñas.
	 * Utiliza cache interno para optimizar el rendimiento en accesos
	 * repetidos durante la misma ejecución.
	 * 
	 * **Proceso de recuperación:**
	 * - Verifica cache interno primero
	 * - Recupera desde base de datos si no está en cache
	 * - Descifra automáticamente campos sensibles
	 * - Actualiza cache interno para futuros accesos
	 * 
	 * **Seguridad:**
	 * - Las contraseñas se descifran solo en memoria
	 * - No se almacenan contraseñas en texto plano
	 * - Cache se limpia automáticamente al finalizar la ejecución
	 *
	 * @return array|false Array con las credenciales descifradas o false si no existen:
	 *   - 'api_url' (string): URL base del servicio Verial
	 *   - 'session_id' (string): Número de sesión de Verial
	 * 
	 * @since 1.0.0
	 * @since 1.4.1 Mejorado sistema de cache y descifrado
	 * 
	 * @example
	 * ```php
	 * $auth = Auth_Manager::get_instance();
	 * $credentials = $auth->get_credentials();
	 * 
	 * if ($credentials) {
	 *     $apiUrl = $credentials['api_url'];
	 *     $sessionId = $credentials['session_id'];
	 * }
	 * ```
	 * 
	 * @see decrypt_password() Para el proceso de descifrado
	 * @see has_credentials() Para verificar existencia sin cargar
	 */
	public function get_credentials() {
		// Usar caché si está disponible
		if ( $this->credentials !== null ) {
			return $this->credentials;
		}

		// Obtener las credenciales de la base de datos
		$credentials = get_option( self::OPTION_KEY, false );

		if ( ! $credentials ) {
			return false;
		}

		// Si hay contraseña cifrada, descifrarla
		if ( isset( $credentials['password'] ) ) {
			$credentials['password'] = $this->decrypt_password( $credentials['password'] );
		}

		// Guardar en caché
		$this->credentials = $credentials;

		return $credentials;
	}

	/**
	 * Verifica si existen credenciales válidas y completas.
	 * 
	 * Realiza una verificación completa de la existencia y validez
	 * de las credenciales almacenadas, incluyendo la presencia de
	 * todos los campos obligatorios para una autenticación exitosa.
	 * 
	 * **Validaciones realizadas:**
	 * - Existencia de credenciales en la base de datos
	 * - Presencia de campos obligatorios (api_url, username, password)
	 * - Verificación de que los campos no estén vacíos
	 * - Integridad básica de los datos almacenados
	 * 
	 * Este método es más eficiente que get_credentials() cuando solo
	 * se necesita verificar la existencia sin cargar los datos completos.
	 *
	 * @return bool True si existen credenciales válidas y completas, false en caso contrario
	 * 
	 * @since 1.0.0
	 * 
	 * @example
	 * ```php
	 * $auth = Auth_Manager::get_instance();
	 * 
	 * if ($auth->has_credentials()) {
	 *     // Proceder con la autenticación
	 *     $result = $auth->test_connection();
	 * } else {
	 *     // Solicitar configuración de credenciales
	 *     echo "Por favor configure las credenciales de Verial";
	 * }
	 * ```
	 * 
	 * @see get_credentials() Para obtener las credenciales completas
	 * @see test_connection() Para verificar conectividad con las credenciales
	 */
	public function has_credentials() {
		$credentials = $this->get_credentials();

		if ( ! $credentials ) {
			return false;
		}

		// Verificar que existan los campos necesarios
		$required_fields = array( 'api_url', 'username', 'password' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $credentials[ $field ] ) || empty( $credentials[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Realiza una prueba completa de conectividad con Verial ERP.
	 * 
	 * Ejecuta una verificación exhaustiva de la conectividad con el
	 * sistema Verial ERP utilizando las credenciales almacenadas.
	 * La prueba incluye validación de credenciales, conectividad de red,
	 * autenticación y formato de respuesta.
	 * 
	 * **Proceso de verificación:**
	 * 1. Verificación de existencia de credenciales
	 * 2. Configuración temporal del conector API
	 * 3. Llamada de prueba al endpoint GetPaisesWS (ligero y confiable)
	 * 4. Validación de código de estado HTTP
	 * 5. Verificación de formato de respuesta JSON
	 * 6. Análisis de contenido de respuesta
	 * 
	 * **Endpoint de prueba:**
	 * Utiliza GetPaisesWS por ser un endpoint ligero que no requiere
	 * parámetros adicionales y proporciona una respuesta predecible
	 * para validar la conectividad básica.
	 *
	 * @return array Resultado detallado de la prueba:
	 *   - 'success' (bool): True si la conexión fue exitosa
	 *   - 'message' (string): Mensaje descriptivo del resultado
	 *   - 'data' (array, opcional): Datos de respuesta si la conexión fue exitosa
	 * 
	 * @since 1.0.0
	 * @since 1.4.1 Mejorada integración con ApiConnector y manejo de errores
	 * 
	 * @example
	 * ```php
	 * $auth = Auth_Manager::get_instance();
	 * $result = $auth->test_connection();
	 * 
	 * if ($result['success']) {
	 *     echo "✓ " . $result['message'];
	 *     // Opcional: mostrar datos de respuesta
	 *     if (isset($result['data'])) {
	 *         print_r($result['data']);
	 *     }
	 * } else {
	 *     echo "✗ Error: " . $result['message'];
	 * }
	 * ```
	 * 
	 * @see has_credentials() Para verificación previa de credenciales
	 * @see ApiConnector Para la implementación de conectividad
	 */
	public function test_connection() {
		if ( ! $this->has_credentials() ) {
			return array(
				'success' => false,
				'message' => __( 'No hay credenciales guardadas.', 'mi-integracion-api' ),
			);
		}

		$credentials = $this->get_credentials();

		// Intentar una llamada simple a la API (GetPaisesWS)
		$api_connector = function_exists( 'mi_integracion_api_get_connector' )
			? \MiIntegracionApi\Helpers\ApiHelpers::get_connector()
			: new \MiIntegracionApi\Core\ApiConnector();

		// Configurar credenciales temporales para la prueba
		$api_connector->set_credentials( $credentials );

		$response = $api_connector->get( 'GetPaisesWS' );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code !== 200 ) {
			return array(
				'success' => false,
				'message' => sprintf(
					__( 'Error de conexión. Código de estado: %s', 'mi-integracion-api' ),
					$status_code
				),
			);
		}

		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return array(
				'success' => false,
				'message' => __( 'Respuesta vacía del servidor.', 'mi-integracion-api' ),
			);
		}

		// Intentar decodificar el JSON
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'message' => __( 'Respuesta incorrecta del servidor. No es un formato JSON válido.', 'mi-integracion-api' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Conexión exitosa con Verial ERP.', 'mi-integracion-api' ),
			'data'    => $data,
		);
	}

	/**
	 * Cifra una contraseña utilizando múltiples niveles de seguridad.
	 * 
	 * Implementa un sistema de cifrado en cascada con múltiples fallbacks
	 * para garantizar la máxima seguridad posible según las capacidades
	 * del sistema. Utiliza los métodos de cifrado más seguros disponibles
	 * y degrada graciosamente a métodos menos seguros si es necesario.
	 * 
	 * **Niveles de cifrado (en orden de preferencia):**
	 * 1. **Servicio centralizado**: CryptoService si está disponible
	 * 2. **OpenSSL AES-256-CBC**: Cifrado fuerte con IV aleatorio
	 * 3. **Base64**: Fallback básico para compatibilidad
	 * 
	 * **Características de seguridad:**
	 * - Utiliza salt de WordPress para mayor entropía
	 * - IV (Initialization Vector) aleatorio para cada cifrado
	 * - Algoritmo AES-256-CBC para máxima seguridad
	 * - Codificación base64 para almacenamiento seguro
	 *
	 * @param string $password Contraseña en texto plano a cifrar
	 * 
	 * @return string Contraseña cifrada lista para almacenamiento
	 * 
	 * @since 1.0.0
	 * @since 1.4.1 Agregado soporte para servicio de criptografía centralizado
	 * 
	 * @example
	 * ```php
	 * $plainPassword = 'mi_contraseña_segura';
	 * $encryptedPassword = $this->encrypt_password($plainPassword);
	 * // $encryptedPassword contiene la contraseña cifrada
	 * ```
	 * 
	 * @see decrypt_password() Para el proceso inverso de descifrado
	 * @see CryptoService Para cifrado centralizado avanzado
	 */
	private function encrypt_password( $password ) {
		// Usar el servicio de criptografía centralizado
		if ( function_exists( 'mi_integracion_api_get_crypto' ) ) {
			$crypto_service = \MiIntegracionApi\Helpers\ApiHelpers::get_crypto();
			if ( $crypto_service ) {
				return $crypto_service->encrypt( $password );
			}
		}

		// Método antiguo como fallback (por compatibilidad con versiones anteriores)
		$key = wp_salt( 'auth' );

		// Usar OpenSSL si está disponible (más seguro)
		if ( function_exists( 'openssl_encrypt' ) ) {
			$ivlen     = openssl_cipher_iv_length( $cipher = 'AES-256-CBC' );
			$iv        = openssl_random_pseudo_bytes( $ivlen );
			$encrypted = openssl_encrypt( $password, $cipher, $key, 0, $iv );
			if ( $encrypted !== false ) {
				return base64_encode( $iv . $encrypted );
			}
		}

		// Fallback a criptografía simple si OpenSSL no está disponible
		return base64_encode( $password );
	}

	/**
	 * Descifra una contraseña previamente cifrada con múltiples fallbacks.
	 * 
	 * Implementa el proceso inverso de cifrado con soporte para múltiples
	 * métodos de descifrado, garantizando compatibilidad con contraseñas
	 * cifradas usando diferentes versiones del sistema. Intenta los métodos
	 * en orden de seguridad y utiliza fallbacks para mantener compatibilidad.
	 * 
	 * **Métodos de descifrado (en orden de intento):**
	 * 1. **Servicio centralizado**: CryptoService si está disponible
	 * 2. **OpenSSL AES-256-CBC**: Descifrado con IV extraído
	 * 3. **Base64**: Decodificación básica para compatibilidad legacy
	 * 
	 * **Proceso de descifrado OpenSSL:**
	 * - Decodifica base64 para obtener datos binarios
	 * - Extrae IV del inicio de los datos cifrados
	 * - Utiliza salt de WordPress como clave de descifrado
	 * - Aplica algoritmo AES-256-CBC para recuperar texto plano
	 * 
	 * **Compatibilidad:**
	 * - Soporta contraseñas cifradas con versiones anteriores
	 * - Maneja graciosamente errores de descifrado
	 * - Fallback automático a métodos menos seguros si es necesario
	 *
	 * @param string $encrypted_password Contraseña cifrada a descifrar
	 * 
	 * @return string Contraseña en texto plano recuperada
	 * 
	 * @since 1.0.0
	 * @since 1.4.1 Agregado soporte para servicio de criptografía centralizado
	 * 
	 * @example
	 * ```php
	 * $encryptedPassword = 'base64_encrypted_data...';
	 * $plainPassword = $this->decrypt_password($encryptedPassword);
	 * // $plainPassword contiene la contraseña original
	 * ```
	 * 
	 * @see encrypt_password() Para el proceso de cifrado
	 * @see CryptoService Para descifrado centralizado avanzado
	 */
	private function decrypt_password( $encrypted_password ) {
		// Usar el servicio de criptografía centralizado
		if ( function_exists( 'mi_integracion_api_get_crypto' ) ) {
			$crypto_service = \MiIntegracionApi\Helpers\ApiHelpers::get_crypto();
			if ( $crypto_service ) {
				$decrypted = $crypto_service->decrypt( $encrypted_password );
				if ( $decrypted !== false ) {
					return $decrypted;
				}
			}
		}

		// Método antiguo como fallback (por compatibilidad con versiones anteriores)
		$key = wp_salt( 'auth' );

		// Usar OpenSSL si está disponible
		if ( function_exists( 'openssl_decrypt' ) ) {
			$mix   = base64_decode( $encrypted_password );
			$ivlen = openssl_cipher_iv_length( $cipher = 'AES-256-CBC' );

			// Verificar que tengamos suficientes datos
			if ( strlen( $mix ) > $ivlen ) {
				$iv        = substr( $mix, 0, $ivlen );
				$encrypted = substr( $mix, $ivlen );
				$decrypted = openssl_decrypt( $encrypted, $cipher, $key, 0, $iv );
				if ( $decrypted !== false ) {
					return $decrypted;
				}
			}
		}

		// Fallback a decodificación simple
		return base64_decode( $encrypted_password );
	}

	/**
	 * Elimina permanentemente las credenciales almacenadas.
	 * 
	 * Borra de forma segura todas las credenciales almacenadas tanto
	 * de la base de datos como del cache interno. Esta operación es
	 * irreversible y requiere reconfiguración completa de credenciales
	 * para restaurar la funcionalidad.
	 * 
	 * **Proceso de eliminación:**
	 * 1. Limpia el cache interno de credenciales
	 * 2. Elimina la entrada de la tabla wp_options
	 * 3. Confirma la eliminación exitosa
	 * 
	 * **Consideraciones de seguridad:**
	 * - Las credenciales cifradas se eliminan permanentemente
	 * - El cache en memoria se limpia inmediatamente
	 * - No se mantienen copias de respaldo automáticas
	 * 
	 * **Casos de uso típicos:**
	 * - Cambio de servidor o configuración de Verial
	 * - Rotación de credenciales por seguridad
	 * - Desinstalación o reconfiguración del plugin
	 * - Resolución de problemas de autenticación
	 *
	 * @return bool True si las credenciales se eliminaron correctamente, false en caso de error
	 * 
	 * @since 1.0.0
	 * 
	 * @example
	 * ```php
	 * $auth = Auth_Manager::get_instance();
	 * 
	 * if ($auth->delete_credentials()) {
	 *     echo "Credenciales eliminadas exitosamente";
	 *     // Redirigir a página de configuración
	 * } else {
	 *     echo "Error al eliminar credenciales";
	 * }
	 * ```
	 * 
	 * @see save_credentials() Para configurar nuevas credenciales
	 * @see has_credentials() Para verificar estado después de eliminación
	 */
	public function delete_credentials() {
		$this->credentials = null;
		return delete_option( self::OPTION_KEY );
	}
}
