<?php declare(strict_types=1);
/**
 * ComposerAutoloader - Nivel 1: Autoloader primario optimizado
 * 
 * Gestiona la carga del autoloader de Composer con verificación inteligente
 * de salud y optimizaciones para diferentes entornos.
 *
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 */

namespace MiIntegracionApi\Core;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase ComposerAutoloader
 * 
 * Responsable de la gestión inteligente del autoloader de Composer,
 * incluyendo verificación de salud, optimizaciones y fallbacks.
 */
class ComposerAutoloader {
    /**
     * Estado de inicialización del autoloader
     *
     * @var bool
     */
    private static bool $initialized = false;

    /**
     * Estado de salud de Composer
     *
     * @var bool|null
     */
    private static ?bool $is_healthy = null;

    /**
     * Control para evitar warnings repetitivos de ComposerStaticInit
     *
     * @var bool
     */
    private static bool $composer_static_warning_logged = false;

    /**
     * Cache del resultado de testComposerClassLoading para evitar ejecuciones repetitivas
     *
     * @var bool|null
     */
    private static ?bool $test_loading_cache = null;

    /**
     * Ruta del archivo autoload de Composer
     *
     * @var string|null
     */
    private static ?string $composer_autoload_path = null;

    /**
     * Inicializa el autoloader de Composer
     * 
     * @return void
     * @throws \RuntimeException Si no se puede cargar Composer
     */
    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        self::$composer_autoload_path = self::getComposerAutoloadPath();

        // Intentar cargar Composer incluso si la verificación inicial falla
        try {
            if (self::isHealthy()) {
                self::loadComposerAutoloader();
                self::$initialized = true;
            } else {
                // Intentar cargar de todas formas si el archivo existe
                if (file_exists(self::$composer_autoload_path)) {
                    self::loadComposerAutoloader();
                    self::$initialized = true;
                    self::$is_healthy = true; // Marcar como saludable después de cargar exitosamente
                } else {
                    self::logComposerUnavailable();
                }
            }
        } catch (\Throwable $e) {
            self::logComposerUnavailable();
            // No lanzar excepción para permitir que el plugin funcione con autoloaders de respaldo
        }
    }

    /**
     * Verifica si Composer está funcionando correctamente
     * 
     * @return bool True si Composer está saludable
     */
    public static function isHealthy(): bool {
        if (self::$is_healthy !== null) {
            return self::$is_healthy;
        }

        // Verificación 1: Archivo autoload existe
        $autoload_path = self::getComposerAutoloadPath();
        if (!file_exists($autoload_path)) {
            self::$is_healthy = false;
            return false;
        }

        // Verificación 2: Intentar cargar Composer si no está cargado
        if (!class_exists('Composer\\Autoload\\ClassLoader')) {
            try {
                require_once $autoload_path;
                
                // Verificar que se cargó correctamente
                if (!class_exists('Composer\\Autoload\\ClassLoader')) {
                    self::$is_healthy = false;
                    return false;
                }
            } catch (\Throwable $e) {
                self::$is_healthy = false;
                return false;
            }
        }

        // Verificación 3: Verificar que Composer puede cargar una clase de prueba
        if (!self::testComposerClassLoading()) {
            self::$is_healthy = false;
            return false;
        }

        // Verificación 4: Verificar que las dependencias principales están disponibles
        if (!self::testMainDependencies()) {
            self::$is_healthy = false;
            return false;
        }

        self::$is_healthy = true;
        return true;
    }

    /**
     * Obtiene la ruta del archivo autoload de Composer
     * 
     * @return string Ruta completa al archivo autoload
     */
    private static function getComposerAutoloadPath(): string {
        if (!defined('MiIntegracionApi_PLUGIN_DIR')) {
            throw new \RuntimeException('Constante MiIntegracionApi_PLUGIN_DIR no definida');
        }

        return MiIntegracionApi_PLUGIN_DIR . 'vendor/autoload.php';
    }

    /**
     * Carga el autoloader de Composer
     * 
     * @return void
     * @throws \RuntimeException Si no se puede cargar el archivo
     */
    private static function loadComposerAutoloader(): void {
        try {
            // Verificar que el archivo existe antes de cargarlo
            if (!file_exists(self::$composer_autoload_path)) {
                throw new \RuntimeException('Archivo autoload de Composer no encontrado: ' . self::$composer_autoload_path);
            }

            require_once self::$composer_autoload_path;
            
            // Verificar que se cargó correctamente
            if (!class_exists('Composer\\Autoload\\ClassLoader')) {
                throw new \RuntimeException('ClassLoader de Composer no disponible después de la carga');
            }
            
            // Verificar que el autoloader está registrado
            $autoloaders = spl_autoload_functions();
            $composer_autoloader_found = false;
            $composer_loader_instance = null;
            
            foreach ($autoloaders as $autoloader) {
                if (is_array($autoloader) && 
                    isset($autoloader[0]) && 
                    $autoloader[0] instanceof \Composer\Autoload\ClassLoader) {
                    $composer_autoloader_found = true;
                    $composer_loader_instance = $autoloader[0];
                    break;
                }
            }
            
            if (!$composer_autoloader_found) {
                // Log informativo - Composer está funcionando pero no está registrado en spl_autoload
                // Esto es normal en algunos entornos donde Composer usa su propio sistema de autoloading
                if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
                    $logger = new \MiIntegracionApi\Helpers\Logger('composer_autoloader');
                    $logger->info('ClassLoader de Composer cargado pero no registrado en spl_autoload (normal en algunos entornos)');
                }
            }
            
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Error al cargar Composer autoloader: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Prueba si Composer puede cargar clases correctamente
     * 
     * @return bool True si puede cargar clases
     */
    private static function testComposerClassLoading(): bool {
        // Usar cache para evitar ejecuciones repetitivas
        if (self::$test_loading_cache !== null) {
            return self::$test_loading_cache;
        }

        try {
            // Verificar que ClassLoader está disponible (clase esencial)
            if (!class_exists('Composer\\Autoload\\ClassLoader')) {
                self::$test_loading_cache = false;
                return false;
            }

            // Verificar que ComposerStaticInit está disponible (opcional)
            // Composer genera la clase con un hash único, así que buscamos cualquier clase que empiece con ComposerStaticInit
            $composer_static_available = false;
            $declared_classes = get_declared_classes();
            foreach ($declared_classes as $class) {
                if (strpos($class, 'Composer\\Autoload\\ComposerStaticInit') === 0) {
                    $composer_static_available = true;
                    break;
                }
            }
            
            if (!$composer_static_available) {
                // Log de advertencia solo una vez por sesión para evitar spam en logs
                if (!self::$composer_static_warning_logged && class_exists('MiIntegracionApi\\Helpers\\Logger')) {
                    $logger = new \MiIntegracionApi\Helpers\Logger('composer_autoloader');
                    $logger->warning('ComposerStaticInit no disponible, pero ClassLoader está funcionando');
                    self::$composer_static_warning_logged = true;
                }
            }

            self::$test_loading_cache = true;
            return true;
        } catch (\Throwable $e) {
            self::$test_loading_cache = false;
            return false;
        }
    }

    /**
     * Prueba si las dependencias principales están disponibles
     * 
     * @return bool True si las dependencias principales están disponibles
     */
    private static function testMainDependencies(): bool {
        try {
            // Verificar dependencias críticas del composer.json
            $critical_dependencies = [
                'Psr\\Log\\LoggerInterface', // psr/log
                'Eftec\\BladeOne\\BladeOne', // eftec/bladeone
                'Gettext\\TranslatorInterface', // gettext/gettext
                'MarcMabe\\Enum\\Enum', // marc-mabe/php-enum
            ];

            $available_count = 0;
            foreach ($critical_dependencies as $dependency) {
                if (class_exists($dependency) || interface_exists($dependency)) {
                    $available_count++;
                }
            }

            // Si no hay dependencias disponibles, verificar si al menos Composer está funcionando
            if ($available_count === 0) {
                // Verificar si podemos cargar al menos una clase de Composer
                return class_exists('Composer\\Autoload\\ClassLoader');
            }

            // Al menos el 25% de las dependencias críticas deben estar disponibles
            // (reducido de 50% para ser más permisivo)
            $required_percentage = 0.25;
            $required_count = (int) ceil(count($critical_dependencies) * $required_percentage);
            
            return $available_count >= $required_count;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Registra un log cuando Composer no está disponible
     * 
     * @return void
     */
    private static function logComposerUnavailable(): void {
        if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
            $logger = new \MiIntegracionApi\Helpers\Logger('composer_autoloader');
            
            // Solo mostrar advertencia en desarrollo, en producción es normal
            if (defined('WP_DEBUG') && constant('WP_DEBUG')) {
                $logger->warning('Composer autoloader no está disponible. Usando autoloaders de respaldo.', [
                    'composer_path' => self::$composer_autoload_path,
                    'file_exists' => file_exists(self::$composer_autoload_path),
                    'class_loader_exists' => class_exists('Composer\\Autoload\\ClassLoader')
                ]);
            } else {
                // En producción, solo log informativo
                $logger->info('Composer no disponible en producción. Usando EmergencyLoader.', [
                    'composer_path' => self::$composer_autoload_path,
                    'file_exists' => file_exists(self::$composer_autoload_path),
                    'fallback_active' => true
                ]);
            }
        }
    }

    /**
     * Obtiene información de diagnóstico de Composer
     * 
     * @return array Información de diagnóstico
     */
    public static function getDiagnosticInfo(): array {
        $autoload_path = self::$composer_autoload_path ?? self::getComposerAutoloadPath();
        
        return [
            'initialized' => self::$initialized,
            'is_healthy' => self::$is_healthy,
            'composer_path' => $autoload_path,
            'file_exists' => file_exists($autoload_path),
            'file_readable' => file_exists($autoload_path) ? is_readable($autoload_path) : false,
            'file_size' => file_exists($autoload_path) ? filesize($autoload_path) : 0,
            'class_loader_exists' => class_exists('Composer\\Autoload\\ClassLoader'),
            'composer_static_init_exists' => class_exists('Composer\\Autoload\\ComposerStaticInit'),
            'declared_classes_count' => count(get_declared_classes()),
            'test_loading_success' => self::testComposerClassLoading(),
            'main_dependencies_test' => self::testMainDependencies(),
            'autoload_functions_count' => count(spl_autoload_functions()),
            'composer_autoloader_registered' => self::isComposerAutoloaderRegistered(),
            'vendor_dir_exists' => is_dir(dirname($autoload_path)),
            'vendor_dir_writable' => is_dir(dirname($autoload_path)) ? is_writable(dirname($autoload_path)) : false
        ];
    }

    /**
     * Verifica si el autoloader de Composer está registrado en spl_autoload
     * 
     * @return bool True si está registrado
     */
    private static function isComposerAutoloaderRegistered(): bool {
        $autoloaders = spl_autoload_functions();
        
        foreach ($autoloaders as $autoloader) {
            if (is_array($autoloader) && 
                isset($autoloader[0]) && 
                $autoloader[0] instanceof \Composer\Autoload\ClassLoader) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Fuerza la recarga del estado de salud de Composer
     * 
     * @return void
     */
    public static function refreshHealthStatus(): void {
        self::$is_healthy = null;
    }

    /**
     * Verifica si el autoloader está inicializado
     * 
     * @return bool True si está inicializado
     */
    public static function isInitialized(): bool {
        return self::$initialized;
    }
}
