<?php
declare(strict_types=1);

/**
 * SmartAutoloader - Sistema de autoloading inteligente con caché
 *
 * Implementa un cargador automático de clases PSR-4 compatible con WordPress que:
 * - Utiliza caché para mejorar el rendimiento
 * - Escanea directorios automáticamente
 * - Funciona como respaldo cuando Composer no está disponible
 * - Incluye herramientas de diagnóstico y depuración
 *
 * Características principales:
 * - Caché de mapa de clases para máximo rendimiento
 * - Detección automática de clases en directorios configurados
 * - Sistema de logging integrado
 * - Métodos para diagnóstico y mantenimiento
 * - Compatible con PSR-4
 *
 * @package     MiIntegracionApi\Core
 * @since       1.0.0
 * @version     2.0.0
 * @see         https://www.php-fig.org/psr/psr-4/ PSR-4: Autoloader
 */

namespace MiIntegracionApi\Core;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase SmartAutoloader
 * 
 * Sistema de autoloading inteligente que actúa como respaldo cuando
 * Composer no está disponible o en modo de desarrollo.
 */
class SmartAutoloader {
    /**
     * Estado de inicialización del autoloader
     *
     * @var bool Indica si el autoloader ha sido inicializado
     * @since 1.0.0
     */
    private static bool $initialized = false;

    /**
     * Cache del mapa de clases
     *
     * @var array<string, string>|null Array asociativo donde la clave es el nombre completo
     *                                de la clase y el valor es la ruta al archivo
     * @since 1.0.0
     */
    private static ?array $class_map_cache = null;

    /**
     * Clave de caché para el mapa de clases
     *
     * @var string Clave única utilizada para almacenar/recuperar el mapa de clases
     * @since 1.0.0
     */
    private static string $cache_key = 'mi_api_smart_class_map_v2';

    /**
     * Tiempo de vida del caché en segundos
     *
     * @var int|null Tiempo en segundos que el caché es válido.
     *               Por defecto es 1 hora (3600 segundos)
     * @since 1.0.0
     */
    private static ?int $cache_ttl = null;

    /**
     * Directorio base del plugin
     *
     * @var string|null Ruta absoluta al directorio base del plugin
     * @since 1.0.0
     */
    private static ?string $base_dir = null;

    /**
     * Mapeo de directorios a namespaces
     *
     * @var array<string, string> Array asociativo donde las claves son los directorios
     *                           relativos al directorio base y los valores son los
     *                           namespaces PHP correspondientes
     * @since 1.0.0
     */
    private static array $directory_mapping = [
        'Core/' => 'MiIntegracionApi\\Core\\',
        'Admin/' => 'MiIntegracionApi\\Admin\\',
        'Helpers/' => 'MiIntegracionApi\\Helpers\\',
        'WooCommerce/' => 'MiIntegracionApi\\WooCommerce\\',
        'Sync/' => 'MiIntegracionApi\\Sync\\',
        'Endpoints/' => 'MiIntegracionApi\\Endpoints\\',
        'DTOs/' => 'MiIntegracionApi\\DTOs\\',
        'SSL/' => 'MiIntegracionApi\\SSL\\',
        'Cache/' => 'MiIntegracionApi\\Cache\\',
        'Hooks/' => 'MiIntegracionApi\\Hooks\\',
        'Validation/' => 'MiIntegracionApi\\Validation\\',
        'REST/' => 'MiIntegracionApi\\REST\\',
        'Constants/' => 'MiIntegracionApi\\Constants\\'
    ];

    /**
     * Inicializa el SmartAutoloader
     *
     * Configura el autoloader y lo registra en el stack de autoloading de PHP.
     * Solo se ejecuta una vez, incluso si se llama múltiples veces.
     *
     * @return void
     * @since 1.0.0
     */
    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        // Configurar cache TTL
        self::$cache_ttl = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;

        // Solo registrar si Composer falla o en desarrollo
        if (!self::isComposerHealthy() || self::shouldLoadInDevelopment()) {
            self::$base_dir = self::getBaseDirectory();
            self::register();
            self::$initialized = true;
        }
    }

    /**
     * Verifica si Composer está funcionando correctamente
     *
     * Comprueba si la clase ComposerAutoloader existe y está funcionando.
     *
     * @return bool True si Composer está disponible y funcionando correctamente
     * @since 1.0.0
     */
    private static function isComposerHealthy(): bool {
        if (class_exists('MiIntegracionApi\\Core\\ComposerAutoloader')) {
            return \MiIntegracionApi\Core\ComposerAutoloader::isHealthy();
        }
        return false;
    }

    /**
     * Determina si debe cargar en modo desarrollo
     *
     * El autoloader se carga en modo desarrollo (WP_DEBUG = true) para
     * facilitar la depuración y asegurar que los cambios en las clases
     * se reflejen inmediatamente.
     *
     * @return bool True si se está ejecutando en modo desarrollo
     * @since 1.0.0
     */
    private static function shouldLoadInDevelopment(): bool {
        return defined('WP_DEBUG') && constant('WP_DEBUG');
    }

    /**
     * Obtiene el directorio base del plugin
     *
     * @return string Ruta absoluta al directorio base del plugin
     * @throws \RuntimeException Si la constante MiIntegracionApi_PLUGIN_DIR no está definida
     * @since 1.0.0
     */
    private static function getBaseDirectory(): string {
        if (!defined('MiIntegracionApi_PLUGIN_DIR')) {
            throw new \RuntimeException('Constante MiIntegracionApi_PLUGIN_DIR no definida');
        }

        return MiIntegracionApi_PLUGIN_DIR . 'includes/';
    }

    /**
     * Registra el autoloader en el stack de autoloading de PHP
     *
     * Utiliza spl_autoload_register para registrar el método loadClass
     * como un autoloader PSR-4.
     *
     * @return void
     * @since 1.0.0
     */
    private static function register(): void {
        spl_autoload_register([self::class, 'loadClass'], true, true);
    }

    /**
     * Carga una clase específica
     *
     * Método principal del autoloader que se encarga de cargar la clase
     * solicitada si existe en el mapa de clases.
     *
     * @param string $class Nombre completo de la clase (con namespace) a cargar
     * @return void
     * @since 1.0.0
     */
    public static function loadClass(string $class): void {
        // Verificar si la clase ya está cargada
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return;
        }

        $class_map = self::getCachedClassMap();
        
        if (isset($class_map[$class])) {
            $file_path = $class_map[$class];
            
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                self::logMissingFile($class, $file_path);
            }
        }
    }

    /**
     * Obtiene el mapa de clases desde caché o lo construye si es necesario
     *
     * Si el mapa está en caché y es válido, lo devuelve. Si no existe o ha expirado,
     * construye un nuevo mapa de clases y lo guarda en caché.
     *
     * @return array<string, string> Mapa de clases donde la clave es el nombre de la clase
     *                              y el valor es la ruta al archivo
     * @since 1.0.0
     */
    private static function getCachedClassMap(): array {
        if (self::$class_map_cache === null) {
            self::$class_map_cache = self::loadClassMapFromCache();
            
            if (self::$class_map_cache === null) {
                self::$class_map_cache = self::buildClassMap();
                self::saveClassMapToCache(self::$class_map_cache);
            }
        }
        
        return self::$class_map_cache;
    }

    /**
     * Carga el mapa de clases desde la caché de WordPress
     *
     * Intenta cargar el mapa de clases desde la caché transitoria de WordPress.
     * Verifica la validez y la integridad de los datos en caché.
     *
     * @return array<string, string>|null Mapa de clases si existe y es válido, null en caso contrario
     * @since 1.0.0
     */
    private static function loadClassMapFromCache(): ?array {
        // Verificar si las funciones de WordPress están disponibles
        if (!function_exists('get_transient')) {
            return null;
        }

        $cached = get_transient(self::$cache_key);
        
        if ($cached === false || !is_array($cached)) {
            return null;
        }

        // Verificar que el cache no esté corrupto
        if (!isset($cached['timestamp']) || !isset($cached['class_map'])) {
            return null;
        }

        // Verificar que el cache no esté expirado
        if (time() - $cached['timestamp'] > self::$cache_ttl) {
            return null;
        }

        return $cached['class_map'];
    }

    /**
     * Guarda el mapa de clases en la caché de WordPress
     *
     * Almacena el mapa de clases en la caché transitoria de WordPress
     * junto con metadatos como la marca de tiempo y la versión.
     *
     * @param array<string, string> $class_map Mapa de clases a guardar
     * @return void
     * @since 1.0.0
     */
    private static function saveClassMapToCache(array $class_map): void {
        // Verificar si las funciones de WordPress están disponibles
        if (!function_exists('set_transient')) {
            return;
        }

        $cache_data = [
            'timestamp' => time(),
            'class_map' => $class_map,
            'version' => '2.0'
        ];

        set_transient(self::$cache_key, $cache_data, self::$cache_ttl);
    }

    /**
     * Construye el mapa de clases escaneando los directorios configurados
     *
     * Itera sobre el mapeo de directorios definido y construye un mapa
     * de todas las clases disponibles.
     *
     * @return array<string, string> Mapa de clases donde la clave es el nombre de la clase
     *                              y el valor es la ruta al archivo
     * @since 1.0.0
     */
    private static function buildClassMap(): array {
        $class_map = [];
        
        foreach (self::$directory_mapping as $dir => $namespace) {
            $full_dir = self::$base_dir . $dir;
            
            if (is_dir($full_dir)) {
                $class_map = array_merge($class_map, self::scanDirectory($full_dir, $namespace));
            }
        }
        
        return $class_map;
    }

    /**
     * Escanea un directorio en busca de archivos PHP y los mapea a clases
     *
     * Busca archivos .php en el directorio especificado y los asocia
     * con el namespace proporcionado para crear el mapa de clases.
     *
     * @param string $directory Ruta absoluta al directorio a escanear
     * @param string $namespace Namespace base para las clases en este directorio
     * @return array<string, string> Mapa de clases donde la clave es el nombre completo
     *                              de la clase y el valor es la ruta al archivo
     * @since 1.0.0
     */
    private static function scanDirectory(string $directory, string $namespace): array {
        $class_map = [];
        
        try {
            $files = glob($directory . '/*.php');
            
            if ($files === false) {
                return $class_map;
            }
            
            foreach ($files as $file) {
                $class_name = basename($file, '.php');
                $full_class = $namespace . $class_name;
                $class_map[$full_class] = $file;
            }
        } catch (\Throwable $e) {
            self::logDirectoryScanError($directory, $e);
        }
        
        return $class_map;
    }

    /**
     * Registra un mensaje de advertencia cuando no se encuentra un archivo de clase
     *
     * @param string $class Nombre completo de la clase que no se pudo cargar
     * @param string $file_path Ruta del archivo que se intentó cargar
     * @return void
     * @since 1.0.0
     */
    private static function logMissingFile(string $class, string $file_path): void {
        if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
            $logger = new \MiIntegracionApi\Helpers\Logger('smart_autoloader');
            $logger->warning('Archivo no encontrado para clase', [
                'class' => $class,
                'expected_path' => $file_path
            ]);
        }
    }

    /**
     * Registra un error que ocurrió al escanear un directorio
     *
     * @param string $directory Ruta del directorio que se estaba escaneando
     * @param \Throwable $e Excepción que se produjo durante el escaneo
     * @return void
     * @since 1.0.0
     */
    private static function logDirectoryScanError(string $directory, \Throwable $e): void {
        if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
            $logger = new \MiIntegracionApi\Helpers\Logger('smart_autoloader');
            $logger->error('Error al escanear directorio', [
                'directory' => $directory,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Limpia la caché del mapa de clases
     *
     * Elimina el mapa de clases de la caché de WordPress y de la memoria.
     * Útil después de actualizaciones o cambios en la estructura de directorios.
     *
     * @return void
     * @since 1.0.0
     */
    public static function clearCache(): void {
        // Verificar si las funciones de WordPress están disponibles
        if (function_exists('delete_transient')) {
            delete_transient(self::$cache_key);
        }
        
        self::$class_map_cache = null;
    }

    /**
     * Obtiene información de diagnóstico del autoloader
     *
     * Proporciona información detallada sobre el estado actual del autoloader,
     * útil para depuración y monitoreo.
     *
     * @return array{
     *     initialized: bool,
     *     base_dir: string,
     *     cache_loaded: bool,
     *     cache_key: string,
     *     cache_ttl: int|string,
     *     directory_mapping_count: int,
     *     class_map_size: int
     * } Array con información de diagnóstico
     * @since 1.0.0
     */
    public static function getDiagnosticInfo(): array {
        return [
            'initialized' => self::$initialized,
            'base_dir' => self::$base_dir ?? 'No definido',
            'cache_loaded' => self::$class_map_cache !== null,
            'cache_key' => self::$cache_key,
            'cache_ttl' => self::$cache_ttl ?? 'No configurado',
            'directory_mapping_count' => count(self::$directory_mapping),
            'class_map_size' => self::$class_map_cache ? count(self::$class_map_cache) : 0
        ];
    }

    /**
     * Verifica si el autoloader ha sido inicializado
     *
     * @return bool True si el autoloader está inicializado y registrado
     * @since 1.0.0
     */
    public static function isInitialized(): bool {
        return self::$initialized;
    }

    /**
     * Fuerza la reconstrucción del mapa de clases
     *
     * Limpia la caché existente y vuelve a generar el mapa de clases
     * escaneando todos los directorios configurados.
     *
     * @return void
     * @since 1.0.0
     */
    public static function rebuildClassMap(): void {
        self::clearCache();
        self::$class_map_cache = self::buildClassMap();
        self::saveClassMapToCache(self::$class_map_cache);
    }
}
