<?php
/**
 * Servicio de Criptografía Segura
 * 
 * Este archivo contiene la implementación del servicio de criptografía utilizado
 * para proteger datos sensibles como credenciales, claves de API y otra información
 * confidencial dentro del plugin Mi Integración API.
 *
 * @package    MiIntegracionApi
 * @subpackage Core
 * @since      1.0.0
 * @version    1.1.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salir si se accede directamente
}

/**
 * Clase para el manejo seguro de operaciones criptográficas
 *
 * Implementa un servicio de cifrado simétrico utilizando AES-256-CBC por defecto,
 * con soporte para verificación de integridad mediante HMAC. La clase sigue el
 * patrón Singleton para garantizar una única instancia en toda la aplicación.
 *
 * Características principales:
 * - Cifrado AES-256-CBC con vector de inicialización (IV) aleatorio
 * - Verificación de integridad mediante HMAC-SHA256
 * - Gestión segura de claves de cifrado
 * - Fallbacks seguros para entornos con limitaciones
 * - Compatibilidad con versiones antiguas de PHP
 *
 * @package MiIntegracionApi\Core
 * @since   1.0.0
 */
class CryptoService {
	/**
	 * Instancia única de la clase (patrón Singleton)
	 *
	 * @var CryptoService|null Instancia única de la clase
	 * @since 1.0.0
	 */
	private static $instance = null;

	/**
	 * Algoritmo de cifrado a utilizar
	 *
     * @var string Algoritmo de cifrado (por defecto: AES-256-CBC)
     * @since 1.0.0
     */
    private string $cipher = 'AES-256-CBC';
    
    /**
     * Longitud de la clave de cifrado en bytes
     * 
     * @var int Longitud de la clave en bytes (32 para AES-256)
     * @since 1.1.0
     */
    private const KEY_LENGTH = 32;
    
    /**
     * Longitud del vector de inicialización (IV) en bytes
     * 
     * @var int Longitud del IV en bytes
     * @since 1.1.0
     */
    private int $iv_length;
    
    /**
     * Longitud del HMAC en bytes
     * 
     * @var int Longitud del HMAC en bytes (32 para SHA-256)
     * @since 1.1.0
     */
    private const HMAC_LENGTH = 32;

	/**
     * Constructor privado para implementar el patrón Singleton
     * 
     * Inicializa el servicio de criptografía verificando la disponibilidad
     * de los algoritmos necesarios y configurando los parámetros por defecto.
     *
     * @throws \RuntimeException Si no hay métodos de cifrado seguros disponibles
     * @since 1.0.0
     */
    private function __construct() {
        // Verificar que la extensión OpenSSL esté cargada
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException(
                __('La extensión OpenSSL no está disponible. Se requiere para el funcionamiento seguro del plugin.', 'mi-integracion-api')
            );
        }
        
        // Verificar que el cifrado seleccionado esté disponible
        $available_ciphers = openssl_get_cipher_methods();
        
        if (!in_array($this->cipher, $available_ciphers, true)) {
            // Intentar con versión en minúsculas
            $this->cipher = strtolower($this->cipher);
            
            if (!in_array($this->cipher, $available_ciphers, true)) {
                // Fallback a AES-128-CBC si está disponible
                $this->cipher = 'AES-128-CBC';
                
                if (!in_array($this->cipher, $available_ciphers, true)) {
                    // Último intento con versión en minúsculas
                    $this->cipher = 'aes-128-cbc';
                    
                    if (!in_array($this->cipher, $available_ciphers, true)) {
                        throw new \RuntimeException(
                            __('No se encontraron algoritmos de cifrado seguros disponibles.', 'mi-integracion-api')
                        );
                    }
                }
            }
        }
        
        // Obtener la longitud del IV para el algoritmo seleccionado
        $iv_length = openssl_cipher_iv_length($this->cipher);
        
        if ($iv_length === false) {
            throw new \RuntimeException(
                __('No se pudo determinar la longitud del vector de inicialización (IV).', 'mi-integracion-api')
            );
        }
        
        $this->iv_length = $iv_length;
    }

	/**
     * Obtiene la instancia única de la clase (Singleton)
     *
     * Este método implementa el patrón Singleton para garantizar que solo exista
     * una instancia de la clase CryptoService en toda la aplicación.
     *
     * @return self Instancia única de la clase
     * @throws \RuntimeException Si no se puede inicializar el servicio de criptografía
     * @since 1.0.0
     */
    public static function get_instance(): self {
        if (null === self::$instance) {
            try {
                self::$instance = new self();
            } catch (\Exception $e) {
                error_log('Error al inicializar CryptoService: ' . $e->getMessage());
                throw new \RuntimeException(
                    __('No se pudo inicializar el servicio de criptografía.', 'mi-integracion-api'),
                    0,
                    $e
                );
            }
        }
        return self::$instance;
    }

	/**
     * Obtiene la clave de cifrado segura para operaciones criptográficas
     *
     * La clave se obtiene siguiendo este orden de prioridad:
     * 1. Clave personalizada definida en VERIAL_ENCRYPTION_KEY en wp-config.php
     * 2. Clave derivada de AUTH_KEY de WordPress
     * 3. Clave derivada de la sal de autenticación de WordPress
     *
     * @return string Clave de cifrado binaria de 32 bytes (256 bits)
     * @throws \RuntimeException Si no se puede obtener una clave de cifrado válida
     * @since 1.0.0
     */
    private function get_encryption_key(): string {
        // 1. Verificar si la constante está definida en wp-config.php
        if (defined('VERIAL_ENCRYPTION_KEY')) {
            $key = constant('VERIAL_ENCRYPTION_KEY');
            
            // Validar la longitud de la clave
            if (!empty($key) && $key !== 'clave-segura-defecto') {
                // Asegurar que la clave tenga la longitud correcta
                return $this->normalize_key($key);
            }
        }

        // 2. Usar AUTH_KEY de WordPress como alternativa
        if (defined('AUTH_KEY') && !empty(constant('AUTH_KEY'))) {
            return $this->normalize_key(constant('AUTH_KEY'));
        }

        // 3. Usar una derivación de wp_salt como último recurso
        if (function_exists('wp_salt')) {
            return $this->normalize_key(wp_salt('auth'));
        }

        // Si no hay ninguna clave disponible, lanzar una excepción
        throw new \RuntimeException(
            __('No se pudo obtener una clave de cifrado válida. Por favor, defina VERIAL_ENCRYPTION_KEY en su archivo wp-config.php', 'mi-integracion-api')
        );
    }
    
    /**
     * Normaliza una clave para asegurar que tenga la longitud correcta
     * 
     * @param string $key Clave a normalizar
     * @return string Clave binaria de 32 bytes
     * @since 1.1.0
     */
    private function normalize_key(string $key): string {
        // Si la clave es más larga de lo necesario, truncarla
        if (strlen($key) > self::KEY_LENGTH) {
            $key = substr($key, 0, self::KEY_LENGTH);
        }
        // Si es más corta, usar hash para extenderla
        elseif (strlen($key) < self::KEY_LENGTH) {
            $key = hash('sha256', $key, true);
        }
        
        return $key;
    }

	/**
     * Cifra un texto plano de forma segura
     *
     * El formato del texto cifrado es: IV (16 bytes) + HMAC (32 bytes) + texto cifrado
     * Todo codificado en base64 para facilitar su almacenamiento.
     *
     * @param string $plaintext Texto plano a cifrar
     * @param string|null $custom_key Clave personalizada opcional (no recomendado para uso general)
     * @return string Texto cifrado en base64 o cadena vacía en caso de error
     * @throws \InvalidArgumentException Si el texto plano no es una cadena
     * @since 1.0.0
     */
    public function encrypt(string $plaintext, ?string $custom_key = null): string {
        // Validar entrada
        if (!is_string($plaintext)) {
            throw new \InvalidArgumentException(
                __('El texto a cifrar debe ser una cadena de texto.', 'mi-integracion-api')
            );
        }
        
        if (empty($plaintext)) {
            return '';
        }

        try {
            // Obtener la clave de cifrado
            $key = $custom_key ? $this->normalize_key($custom_key) : $this->get_encryption_key();
            
            // Generar un IV aleatorio seguro
            $iv = random_bytes($this->iv_length);
            
            // Cifrar el texto
            $ciphertext_raw = openssl_encrypt(
                $plaintext,
                $this->cipher,
                $key,
                defined('OPENSSL_RAW_DATA') ? OPENSSL_RAW_DATA : 1,
                $iv
            );
            
            if ($ciphertext_raw === false) {
                throw new \RuntimeException(
                    __('Error al cifrar los datos.', 'mi-integracion-api')
                );
            }
            
            // Calcular HMAC para verificación de integridad
            $hmac = hash_hmac('sha256', $ciphertext_raw, $key, true);
            
            // Combinar IV + HMAC + texto cifrado y codificar en base64
            return base64_encode($iv . $hmac . $ciphertext_raw);
            
        } catch (\Exception $e) {
            // Registrar el error pero no exponer detalles sensibles
            error_log('Error en CryptoService::encrypt: ' . $e->getMessage());
            return '';
        }
    }

	/**
     * Descifra un texto cifrado previamente con encrypt()
     *
     * @param string $ciphertext Texto cifrado en base64
     * @param string|null $custom_key Clave personalizada opcional (debe coincidir con la usada para cifrar)
     * @return string|false El texto descifrado o false en caso de error
     * @throws \InvalidArgumentException Si el texto cifrado no es válido
     * @since 1.0.0
     */
    public function decrypt(string $ciphertext, ?string $custom_key = null) {
        // Validar entrada
        if (empty($ciphertext)) {
            return false;
        }
        
        try {
            // Obtener la clave de cifrado
            $key = $custom_key ? $this->normalize_key($custom_key) : $this->get_encryption_key();
            
            // Decodificar el texto cifrado
            $decoded = base64_decode($ciphertext, true);
            if ($decoded === false) {
                throw new \InvalidArgumentException(
                    __('El texto cifrado no es un formato base64 válido.', 'mi-integracion-api')
                );
            }
            
            // Verificar longitud mínima (IV + HMAC)
            $min_length = $this->iv_length + self::HMAC_LENGTH;
            if (strlen($decoded) <= $min_length) {
                throw new \InvalidArgumentException(
                    __('El texto cifrado es demasiado corto.', 'mi-integracion-api')
                );
            }
            
            // Extraer componentes
            $iv = substr($decoded, 0, $this->iv_length);
            $hmac = substr($decoded, $this->iv_length, self::HMAC_LENGTH);
            $ciphertext_raw = substr($decoded, $this->iv_length + self::HMAC_LENGTH);
            
            // Verificar integridad con HMAC
            $calcmac = hash_hmac('sha256', $ciphertext_raw, $key, true);
            
            // Comparación segura en tiempo constante
            if (!hash_equals($hmac, $calcmac)) {
                // Posible intento de manipulación
                error_log('Error de verificación HMAC en CryptoService::decrypt');
                return false;
            }
            
            // Descifrar el contenido
            $plaintext = openssl_decrypt(
                $ciphertext_raw,
                $this->cipher,
                $key,
                defined('OPENSSL_RAW_DATA') ? OPENSSL_RAW_DATA : 1,
                $iv
            );
            
            if ($plaintext === false) {
                throw new \RuntimeException(
                    __('Error al descifrar los datos.', 'mi-integracion-api')
                );
            }
            
            return $plaintext;
            
        } catch (\Exception $e) {
            // Registrar el error pero no exponer detalles sensibles
            error_log('Error en CryptoService::decrypt: ' . $e->getMessage());
            return false;
        }
    }

	/**
     * Genera una clave criptográficamente segura
     *
     * @param int $length Longitud de la clave en bytes (por defecto: 32 bytes = 256 bits)
     * @return string Clave generada en formato hexadecimal
     * @throws \RuntimeException Si no se puede generar una clave segura
     * @since 1.0.0
     */
    public function generate_random_key(int $length = 32): string {
        // Validar longitud mínima
        if ($length < 16) {
            $length = 32; // Forzar longitud mínima segura
        }
        
        try {
            // Intentar con random_bytes() (PHP 7+)
            if (function_exists('random_bytes')) {
                return bin2hex(random_bytes($length));
            }
            
            // Alternativa con openssl_random_pseudo_bytes()
            if (function_exists('openssl_random_pseudo_bytes')) {
                $crypto_strong = false;
                $bytes = openssl_random_pseudo_bytes($length, $crypto_strong);
                
                if ($crypto_strong === true) {
                    return bin2hex($bytes);
                }
            }
            
            // Si llegamos aquí, no hay fuentes criptográficas disponibles
            throw new \RuntimeException(
                __('No se pudo generar una clave segura. No hay fuentes criptográficas disponibles.', 'mi-integracion-api')
            );
            
        } catch (\Exception $e) {
            error_log('Error al generar clave aleatoria: ' . $e->getMessage());
            throw new \RuntimeException(
                __('Error al generar una clave segura.', 'mi-integracion-api'),
                0,
                $e
            );
        }
    }

	/**
     * Cifra un array de credenciales de forma segura
     *
     * Este método recibe un array asociativo con credenciales y devuelve un nuevo array
     * con los mismos valores pero cifrados. Las claves del array se mantienen igual.
     *
     * @param array $credentials Array asociativo con las credenciales a cifrar
     * @return array Array con las credenciales cifradas
     * @throws \InvalidArgumentException Si el parámetro no es un array
     * @since 1.0.0
     */
    public function encrypt_credentials(array $credentials): array {
        if (!is_array($credentials)) {
            throw new \InvalidArgumentException(
                __('El parámetro debe ser un array de credenciales.', 'mi-integracion-api')
            );
        }

        $encrypted = $credentials;

        // Cifrar solo los campos sensibles
        $sensitive_fields = ['password', 'api_key', 'api_secret', 'token', 'secret', 'private_key'];

        foreach ($sensitive_fields as $field) {
            if (isset($credentials[$field]) && $credentials[$field] !== '') {
                $encrypted[$field] = $this->encrypt((string)$credentials[$field]);
            }
        }

        return $encrypted;
    }

	/**
     * Descifra un array de credenciales previamente cifradas
     *
     * Este método recibe un array asociativo con credenciales cifradas y devuelve
     * un nuevo array con los valores descifrados. Las claves del array se mantienen igual.
     *
     * @param array $encrypted_credentials Array asociativo con credenciales cifradas
     * @return array Array con las credenciales descifradas o array vacío en caso de error
     * @throws \InvalidArgumentException Si el parámetro no es un array
     * @since 1.0.0
     */
    public function decrypt_credentials(array $encrypted_credentials): array {
        if (!is_array($encrypted_credentials)) {
            throw new \InvalidArgumentException(
                __('El parámetro debe ser un array de credenciales cifradas.', 'mi-integracion-api')
            );
        }

        $decrypted = $encrypted_credentials;

        // Descifrar solo los campos sensibles
        $sensitive_fields = ['password', 'api_key', 'api_secret', 'token', 'secret', 'private_key'];

        foreach ($sensitive_fields as $field) {
            if (isset($encrypted_credentials[$field]) && $encrypted_credentials[$field] !== '') {
                $decrypted_value = $this->decrypt((string)$encrypted_credentials[$field]);
                if ($decrypted_value !== false) {
                    $decrypted[$field] = $decrypted_value;
                }
            }
        }

        return $decrypted;
    }
    
    /**
     * Previene la clonación del objeto (Singleton)
     *
     * @return void
     * @throws \LogicException Si se intenta clonar la instancia
     * @since 1.0.0
     */
    public function __clone() {
        throw new \LogicException(
            __('No se puede clonar una instancia de CryptoService. Utilice get_instance() en su lugar.', 'mi-integracion-api')
        );
    }
    
    /**
     * Previene la deserialización del objeto (Singleton)
     *
     * @return void
     * @throws \LogicException Si se intenta deserializar la instancia
     * @since 1.0.0
     */
    public function __wakeup() {
        throw new \LogicException(
            __('No se puede deserializar una instancia de CryptoService. Utilice get_instance() en su lugar.', 'mi-integracion-api')
        );
    }
}

/**
 * No se detecta uso de Logger::log, solo error_log estándar.
 * 
 * @todo Considerar implementar un sistema de logging más robusto en futuras versiones
 *       para un mejor seguimiento de errores y auditoría de seguridad.
 */
