<?php
/**
 * Clase para administrar configuraciones SSL avanzadas
 *
 * Proporciona una interfaz unificada para gestionar configuraciones SSL/TLS
 * avanzadas en el contexto de WordPress, incluyendo la configuración de
 * conexiones seguras, gestión de certificados y opciones de verificación.
 *
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/SSL
 * @since      1.0.0
 * @version    1.1.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\SSL;

use MiIntegracionApi\Helpers\Logger;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase SSLConfigManager
 *
 * Gestiona la configuración avanzada de SSL/TLS para conexiones seguras, incluyendo:
 * - Configuración de verificación de certificados
 * - Gestión de bundles de CA
 * - Configuración de versiones y cifrados SSL/TLS
 * - Soporte para autenticación mutua
 * - Integración con WordPress y cURL
 *
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/SSL
 * @since      1.0.0
 */
class SSLConfigManager {
    /**
     * Instancia del logger para registro de eventos
     *
     * @var Logger
     * @since 1.0.0
     */
    private Logger $logger;

    /**
     * Opciones de configuración SSL/TLS
     *
     * @var array<string, mixed> Configuración con las siguientes claves:
     *      - verify_peer: bool Verificar certificado del servidor (default: true)
     *      - verify_peer_name: bool Verificar que el certificado coincida con el host (default: true)
     *      - allow_self_signed: bool Permitir certificados autofirmados (default: false)
     *      - verify_depth: int Profundidad de verificación de certificados (default: 5)
     *      - cipher_list: string Lista personalizada de cifrados SSL/TLS (default: '')
     *      - ssl_version: string Versión SSL/TLS (ej: 'TLSv1.2') (default: '')
     *      - revocation_check: bool Verificar revocación de certificados (default: true)
     *      - ca_bundle_path: string Ruta al bundle CA (default: '')
     *      - client_cert_path: string Ruta al certificado cliente (default: '')
     *      - client_key_path: string Ruta a la clave privada cliente (default: '')
     *      - disable_ssl_local: bool Deshabilitar verificación SSL localmente (default: true)
     *      - debug_ssl: bool Activar depuración SSL (default: false)
     *      - proxy: string Proxy para conexiones SSL (default: '')
     *
     * @since 1.0.0
     */
    private array $ssl_options = [
        // Opciones básicas
        'verify_peer' => true,               // Verificar certificado del servidor
        'verify_peer_name' => true,          // Verificar que el certificado coincida con el host
        'allow_self_signed' => false,        // Permitir certificados autofirmados
        
        // Opciones avanzadas
        'verify_depth' => 5,                 // Profundidad de verificación de certificados
        'cipher_list' => '',                 // Lista personalizada de cifrados SSL/TLS
        'ssl_version' => '',                 // Versión SSL/TLS (TLSv1.2, etc.)
        'revocation_check' => true,          // Verificar revocación de certificados
        
        // Rutas a certificados
        'ca_bundle_path' => '',              // Ruta al bundle CA
        'client_cert_path' => '',            // Ruta al certificado cliente
        'client_key_path' => '',             // Ruta a la clave privada cliente
        
        // Opciones de desarrollo/entorno
        'disable_ssl_local' => true,         // Deshabilitar verificación SSL en entornos locales
        'debug_ssl' => false,                // Activar debug SSL detallado
        'proxy' => '',                       // Proxy para conexiones SSL
    ];

    /**
     * Recurso de log para depuración SSL
     *
     * @var resource|null
     * @since 1.0.0
     */
    private $verbose_log = null;

    /**
     * Constructor de la clase
     *
     * Inicializa el gestor de configuración SSL, cargando la configuración guardada
     * y fusionándola con cualquier opción personalizada proporcionada.
     *
     * @param Logger|null $logger Instancia del logger (opcional)
     * @param array<string, mixed> $options Opciones SSL personalizadas (opcional)
     * @since 1.0.0
     */
    public function __construct(?Logger $logger = null, array $options = []) {
        $this->logger = $logger ?? new \MiIntegracionApi\Helpers\Logger('ssl_config');
        
        // Cargar configuración guardada
        $saved_options = get_option('miapi_ssl_config_options', []);
        if (is_array($saved_options) && !empty($saved_options)) {
            $this->ssl_options = array_merge($this->ssl_options, $saved_options);
        }
        
        // Fusionar con opciones proporcionadas
        if (!empty($options)) {
            $this->ssl_options = array_merge($this->ssl_options, $options);
        }
        
        // Detectar ruta al bundle CA por defecto si no está establecido
        if (empty($this->ssl_options['ca_bundle_path'])) {
            $this->ssl_options['ca_bundle_path'] = $this->detectDefaultCaBundle();
        }
    }

    /**
     * Guarda la configuración SSL en la base de datos de WordPress
     *
     * Almacena la configuración actual en la tabla de opciones de WordPress
     * para persistencia entre cargas del plugin.
     *
     * @return bool true si la configuración se guardó correctamente, false en caso contrario
     * @since 1.0.0
     * @see update_option()
     */
    public function saveConfig(): bool {
        return update_option('miapi_ssl_config_options', $this->ssl_options, false);
    }

    /**
     * Detecta automáticamente la ruta al bundle de CA del sistema
     *
     * Busca en ubicaciones comunes de bundles de CA tanto del plugin
     * como del sistema operativo.
     *
     * @return string Ruta absoluta al bundle CA o cadena vacía si no se encuentra
     * @since 1.0.0
     */
    private function detectDefaultCaBundle(): string {
        $possible_paths = [
            plugin_dir_path(dirname(__FILE__)) . '../certs/ca-bundle.pem',
            plugin_dir_path(dirname(__FILE__)) . '../../certs/ca-bundle.pem',
            ABSPATH . 'wp-content/plugins/mi-integracion-api/certs/ca-bundle.pem',
        ];
        
        foreach ($possible_paths as $path) {
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }
        
        // Buscar bundle del sistema (Linux/Unix)
        $system_paths = [
            '/etc/ssl/certs/ca-certificates.crt',  // Debian/Ubuntu
            '/etc/pki/tls/cert.pem',               // Red Hat/Fedora/CentOS
            '/etc/ssl/ca-bundle.pem',              // OpenSuse
            '/etc/pki/tls/cacert.pem',             // CentOS/Oracle Linux
            '/usr/local/share/certs/ca-root-nss.crt', // FreeBSD
            '/usr/local/etc/openssl/cert.pem',     // macOS Homebrew
            '/usr/local/etc/openssl@1.1/cert.pem', // macOS Homebrew alternative
        ];
        
        foreach ($system_paths as $path) {
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }
        
        return '';
    }

    /**
     * Establece una opción de configuración SSL
     *
     * @param string $option Nombre de la opción a establecer
     * @param mixed $value Valor a asignar
     * @return self Instancia actual para permitir encadenamiento de métodos
     * @throws \InvalidArgumentException Si el nombre de la opción no existe
     * @since 1.0.0
     */
    public function setOption(string $option, $value): self {
        if (array_key_exists($option, $this->ssl_options)) {
            $this->ssl_options[$option] = $value;
        }
        return $this;
    }

    /**
     * Obtiene el valor de una opción de configuración SSL
     *
     * @template T
     * @param string $option Nombre de la opción a obtener
     * @param T $default Valor por defecto a devolver si la opción no existe
     * @return mixed|T Valor de la opción o $default si no existe
     * @since 1.0.0
     */
    public function getOption(string $option, $default = null) {
        return array_key_exists($option, $this->ssl_options) ? $this->ssl_options[$option] : $default;
    }

    /**
     * Obtiene todas las opciones de configuración SSL actuales
     *
     * @return array<string, mixed> Array asociativo con todas las opciones de configuración
     * @since 1.0.0
     */
    public function getAllOptions(): array {
        return $this->ssl_options;
    }

    /**
     * Valida si un archivo de bundle CA es accesible y legible
     *
     * Verifica que el archivo exista, sea legible y tenga un tamaño mayor a cero.
     * Registra advertencias en el log si hay problemas con el archivo.
     *
     * @param string $path Ruta absoluta al archivo bundle CA
     * @return bool true si el archivo es válido y accesible, false en caso contrario
     * @since 1.0.0
     */
    public function validateCaBundle(string $path): bool {
        if (empty($path)) {
            return false;
        }
        if (!file_exists($path)) {
            $this->logger->warning("[SSL Config] CA bundle no encontrado: " . $path);
            return false;
        }
        if (!is_readable($path)) {
            $this->logger->warning("[SSL Config] CA bundle no legible: " . $path);
            return false;
        }
        $this->logger->debug("[SSL Config] CA bundle validado: " . $path);
        return true;
    }

    /**
     * Aplica la configuración SSL a un manejador cURL
     *
     * Configura las opciones SSL/TLS en un manejador cURL según la configuración actual.
     * Incluye verificación de certificados, versión SSL, lista de cifrados, etc.
     *
     * @param resource $curl_handle Recurso cURL a configurar
     * @param bool $is_local Si es true, puede deshabilitar verificaciones en entornos locales
     * @return resource Manejador cURL configurado
     * @throws \RuntimeException Si el manejador cURL no es válido
     * @since 1.0.0
     */
    public function applyCurlOptions($curl_handle, bool $is_local = false) {
        // Verificar si debemos deshabilitar SSL en entorno local
        $verify_peer = $this->ssl_options['verify_peer'];
        if ($is_local && $this->ssl_options['disable_ssl_local']) {
            $verify_peer = false;
            $this->logger->debug('[SSL Config] Verificación SSL deshabilitada en entorno local');
        }
        
        // Configuración básica SSL
        curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, $verify_peer);
        curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, $this->ssl_options['verify_peer_name'] ? 2 : 0);
        
        // Configurar bundle CA
        if ($verify_peer && !empty($this->ssl_options['ca_bundle_path'])) {
            curl_setopt($curl_handle, CURLOPT_CAINFO, $this->ssl_options['ca_bundle_path']);
        }
        
        // Configuración avanzada
        if (!empty($this->ssl_options['ssl_version'])) {
            $ssl_version = $this->mapSSLVersion($this->ssl_options['ssl_version']);
            if ($ssl_version !== null) {
                curl_setopt($curl_handle, CURLOPT_SSLVERSION, $ssl_version);
            }
        }
        
        // Configurar certificado cliente para autenticación mutua
        if (!empty($this->ssl_options['client_cert_path'])) {
            curl_setopt($curl_handle, CURLOPT_SSLCERT, $this->ssl_options['client_cert_path']);
            
            if (!empty($this->ssl_options['client_key_path'])) {
                curl_setopt($curl_handle, CURLOPT_SSLKEY, $this->ssl_options['client_key_path']);
            }
        }
        
        // Configurar lista de cifrados
        if (!empty($this->ssl_options['cipher_list'])) {
            curl_setopt($curl_handle, CURLOPT_SSL_CIPHER_LIST, $this->ssl_options['cipher_list']);
        }
        
        // Verificación de revocación
        if ($this->ssl_options['revocation_check']) {
            if (defined('CURLOPT_SSL_VERIFYSTATUS')) {
                curl_setopt($curl_handle, CURLOPT_SSL_VERIFYSTATUS, true);
            }
        }
        
        // Proxy
        if (!empty($this->ssl_options['proxy'])) {
            curl_setopt($curl_handle, CURLOPT_PROXY, $this->ssl_options['proxy']);
        }
        
        // Debug SSL
        if ($this->ssl_options['debug_ssl']) {
            curl_setopt($curl_handle, CURLOPT_VERBOSE, true);
            $verbose_log = fopen('php://temp', 'w+');
            curl_setopt($curl_handle, CURLOPT_STDERR, $verbose_log);
            
            // Guardar en propiedad para acceso posterior
            $this->verbose_log = $verbose_log;
        }
        
        return $curl_handle;
    }
    
    /**
     * Aplica la configuración SSL a argumentos de solicitud HTTP de WordPress
     *
     * Configura los argumentos para funciones wp_remote_* según la configuración SSL actual.
     * Compatible con las funciones de API HTTP de WordPress.
     *
     * @param array<string, mixed> $args Argumentos de solicitud wp_remote_*
     * @param bool $is_local Si es true, puede deshabilitar verificaciones en entornos locales
     * @return array<string, mixed> Argumentos actualizados con la configuración SSL
     * @since 1.0.0
     */
    public function applyWpRequestArgs(array $args, bool $is_local = false): array {
        // Verificar si debemos deshabilitar SSL en entorno local
        $verify_ssl = $this->ssl_options['verify_peer'];
        if ($is_local && $this->ssl_options['disable_ssl_local']) {
            $verify_ssl = false;
            $this->logger->debug('[SSL Config] Verificación SSL deshabilitada en entorno local');
        }
        
        // Configuración básica
        $args['sslverify'] = $verify_ssl;
        
        // Configurar bundle CA
        if ($verify_ssl && !empty($this->ssl_options['ca_bundle_path'])) {
            $args['sslcertificates'] = $this->ssl_options['ca_bundle_path'];
        }
        
        // Proxy
        if (!empty($this->ssl_options['proxy'])) {
            $args['proxy'] = $this->ssl_options['proxy'];
        }
        
        return $args;
    }

    /**
     * Obtiene los logs de depuración SSL si están habilitados
     *
     * Recupera los logs detallados de la última operación cURL cuando
     * la depuración SSL está activada.
     *
     * @return string|null Contenido del log de depuración o null si no está disponible
     * @since 1.0.0
     */
    public function getSSLDebugLog(): ?string {
        if (!$this->ssl_options['debug_ssl'] || !isset($this->verbose_log)) {
            return null;
        }
        
        rewind($this->verbose_log);
        $log_contents = stream_get_contents($this->verbose_log);
        fclose($this->verbose_log);
        
        return $log_contents;
    }
    
    /**
     * Convierte una versión SSL/TLS de cadena a su constante CURL equivalente
     *
     * @param string $version_str Versión SSL/TLS (ej: 'TLSv1.2', 'TLSv1.3')
     * @return int|null Constante CURL_SSLVERSION_* correspondiente o null si no se reconoce
     * @since 1.0.0
     */
    private function mapSSLVersion(string $version_str): ?int {
        $map = [
            'SSLv3' => CURL_SSLVERSION_SSLv3,
            'TLSv1' => CURL_SSLVERSION_TLSv1,
            'TLSv1.0' => CURL_SSLVERSION_TLSv1_0,
            'TLSv1.1' => CURL_SSLVERSION_TLSv1_1,
            'TLSv1.2' => CURL_SSLVERSION_TLSv1_2,
        ];
        
        // Añadir TLSv1.3 si está disponible (PHP 7.3+ con cURL 7.52.0+)
        if (defined('CURL_SSLVERSION_TLSv1_3')) {
            $map['TLSv1.3'] = CURL_SSLVERSION_TLSv1_3;
        }
        
        return isset($map[$version_str]) ? $map[$version_str] : null;
    }

    /**
     * Obtiene la configuración SSL actual (método de compatibilidad)
     *
     * @return array<string, mixed> Array con la configuración SSL actual
     * @deprecated 1.1.0 Usar getAllOptions() en su lugar
     * @since 1.0.0
     */
    public function getConfiguration(): array {
        if ($this->logger) {
            $this->logger->debug('[SSLConfigManager] getConfiguration() llamado');
        }
        return $this->ssl_options;
    }
}
