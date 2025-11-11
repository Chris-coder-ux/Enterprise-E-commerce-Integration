<?php declare(strict_types=1);
/**
 * AutoloaderManager - Coordinador principal de autoloaders
 * 
 * Gestiona la inicialización y coordinación de todos los autoloaders
 * del plugin siguiendo la arquitectura de 3 niveles.
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
 * Clase AutoloaderManager
 * 
 * Coordinador principal que gestiona la inicialización de todos los
 * autoloaders del plugin en el orden correcto y con la configuración
 * apropiada para cada entorno.
 */
class AutoloaderManager {
    /**
     * Estado de inicialización del manager
     *
     * @var bool
     */
    private static bool $initialized = false;

    /**
     * Estado de los autoloaders individuales
     *
     * @var array
     */
    private static array $autoloader_status = [
        'composer' => false,
        'smart' => false,
        'backup' => false,
        'emergency' => false
    ];

    /**
     * Configuración del entorno
     *
     * @var array
     */
    private static array $environment_config = [
        'development' => false,
        'production' => false,
        'debug_mode' => false
    ];

    /**
     * Inicializa el AutoloaderManager y todos los autoloaders
     * 
     * @return void
     * @throws \RuntimeException Si hay errores críticos de inicialización
     */
    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        try {
            // Configurar entorno
            self::configureEnvironment();
            
            // Inicializar autoloaders en orden de prioridad
            self::initializeAutoloaders();
            
            // Verificar estado de inicialización
            self::verifyInitialization();
            
            // Mostrar advertencias si es necesario
            self::showWarningsIfNeeded();
            
            self::$initialized = true;
            
            self::logSuccessfulInitialization();
            
        } catch (\Throwable $e) {
            self::handleInitializationError($e);
            throw new \RuntimeException(
                'Error crítico al inicializar AutoloaderManager: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Configura el entorno de ejecución
     * 
     * @return void
     */
    private static function configureEnvironment(): void {
        self::$environment_config = [
            'development' => defined('WP_DEBUG') && constant('WP_DEBUG'),
            'production' => !defined('WP_DEBUG') || !constant('WP_DEBUG'),
            'debug_mode' => defined('WP_DEBUG') && constant('WP_DEBUG')
        ];
    }

    /**
     * Inicializa todos los autoloaders en el orden correcto
     * 
     * @return void
     */
    private static function initializeAutoloaders(): void {
        // PRIMERO: Cargar EmergencyLoader directamente para evitar dependencias circulares
        self::loadEmergencyLoader();
        
        // Nivel 1: ComposerAutoloader (Primario) - CON TIMEOUT
        if (class_exists('MiIntegracionApi\\Core\\ComposerAutoloader')) {
            try {
                // Usar timeout para evitar bucles infinitos
                $start_time = microtime(true);
                \MiIntegracionApi\Core\ComposerAutoloader::init();
                
                // Verificar que no haya tardado demasiado (más de 5 segundos)
                $duration = microtime(true) - $start_time;
                if ($duration > 5.0) {
                    self::logAutoloaderError('ComposerAutoloader', new \RuntimeException('ComposerAutoloader tardó demasiado: ' . $duration . ' segundos'));
                    self::$autoloader_status['composer'] = false;
                } else {
                    self::$autoloader_status['composer'] = \MiIntegracionApi\Core\ComposerAutoloader::isInitialized();
                }
            } catch (\Throwable $e) {
                self::logAutoloaderError('ComposerAutoloader', $e);
                self::$autoloader_status['composer'] = false;
            }
        } else {
            self::$autoloader_status['composer'] = false;
        }

        // Nivel 2: SmartAutoloader (Respaldo)
        if (class_exists('MiIntegracionApi\\Core\\SmartAutoloader')) {
            try {
                \MiIntegracionApi\Core\SmartAutoloader::init();
                self::$autoloader_status['smart'] = \MiIntegracionApi\Core\SmartAutoloader::isInitialized();
            } catch (\Throwable $e) {
                self::logAutoloaderError('SmartAutoloader', $e);
                self::$autoloader_status['smart'] = false;
            }
        } else {
            self::$autoloader_status['smart'] = false;
        }

        // Nivel 3: BackupAutoloader (Último recurso)
        if (class_exists('MiIntegracionApi\\BackupAutoloader')) {
            try {
                \MiIntegracionApi\BackupAutoloader::init();
                self::$autoloader_status['backup'] = true;
            } catch (\Throwable $e) {
                self::logAutoloaderError('BackupAutoloader', $e);
                self::$autoloader_status['backup'] = false;
            }
        } else {
            self::$autoloader_status['backup'] = false;
        }
        
        // GARANTIZAR que EmergencyLoader esté funcionando si otros fallan
        if (!self::$autoloader_status['composer'] && !self::$autoloader_status['smart'] && !self::$autoloader_status['backup']) {
            // Si todos los otros autoloaders fallan, asegurar que EmergencyLoader esté activo
            if (!self::$autoloader_status['emergency']) {
                self::loadEmergencyLoader();
            }
        }
    }
    
    /**
     * Carga el EmergencyLoader directamente
     * 
     * @return void
     */
    private static function loadEmergencyLoader(): void {
        // Cargar EmergencyLoader directamente si no está disponible
        if (!class_exists('MiIntegracionApi\\Core\\EmergencyLoader')) {
            $emergency_loader_path = self::getEmergencyLoaderPath();
            if (file_exists($emergency_loader_path)) {
                require_once $emergency_loader_path;
            }
        }
        
        // Inicializar EmergencyLoader
        if (class_exists('MiIntegracionApi\\Core\\EmergencyLoader')) {
            try {
                \MiIntegracionApi\Core\EmergencyLoader::init();
                self::$autoloader_status['emergency'] = \MiIntegracionApi\Core\EmergencyLoader::isInitialized();
            } catch (\Throwable $e) {
                self::logAutoloaderError('EmergencyLoader', $e);
                // Para EmergencyLoader, intentar verificar el estado después del error
                try {
                    self::$autoloader_status['emergency'] = \MiIntegracionApi\Core\EmergencyLoader::isInitialized();
                } catch (\Throwable $e2) {
                    // Si incluso la verificación falla, marcar como no funcionando
                    self::$autoloader_status['emergency'] = false;
                    self::logAutoloaderError('EmergencyLoader::isInitialized', $e2);
                }
            }
        } else {
            // Si EmergencyLoader no está disponible, esto es un error crítico
            self::$autoloader_status['emergency'] = false;
        }
    }
    
    /**
     * Obtiene la ruta del EmergencyLoader
     * 
     * @return string Ruta del archivo
     */
    private static function getEmergencyLoaderPath(): string {
        // Intentar usar la constante si está definida
        if (defined('MiIntegracionApi_PLUGIN_DIR')) {
            return MiIntegracionApi_PLUGIN_DIR . 'includes/Core/EmergencyLoader.php';
        }
        
        // Fallback: usar el directorio del archivo actual
        $current_file = __FILE__;
        $base_dir = dirname($current_file) . '/';
        return $base_dir . 'EmergencyLoader.php';
    }

    /**
     * Verifica que la inicialización fue exitosa
     * 
     * @return void
     * @throws \RuntimeException Si la inicialización falló críticamente
     */
    private static function verifyInitialization(): void {
        // Al menos el EmergencyLoader debe estar funcionando
        if (!self::$autoloader_status['emergency']) {
            throw new \RuntimeException('EmergencyLoader no pudo inicializarse - Error crítico');
        }

        // En producción, preferiblemente Composer debería estar funcionando
        if (self::$environment_config['production'] && !self::$autoloader_status['composer']) {
            self::logProductionWarning();
        }
    }

    /**
     * Muestra advertencias si es necesario
     * 
     * @return void
     */
    private static function showWarningsIfNeeded(): void {
        // Mostrar advertencia si Composer no está disponible
        if (!self::$autoloader_status['composer']) {
            self::showComposerWarning();
        }

        // Mostrar advertencia en desarrollo si SmartAutoloader no está funcionando
        if (self::$environment_config['development'] && !self::$autoloader_status['smart']) {
            self::showDevelopmentWarning();
        }
    }

    /**
     * Muestra advertencia de Composer no disponible
     * 
     * @return void
     */
    private static function showComposerWarning(): void {
        // Solo ejecutar si estamos en WordPress
        if (!function_exists('add_action')) {
            return;
        }
        
        add_action('admin_notices', function() {
            // Verificar si Composer está realmente funcionando
            $composer_working = false;
            if (class_exists('MiIntegracionApi\\Core\\ComposerAutoloader')) {
                $composer_working = \MiIntegracionApi\Core\ComposerAutoloader::isHealthy();
            }
            
            // Solo mostrar advertencia si Composer realmente no está funcionando
            if (!$composer_working) {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>Mi Integración API:</strong> ';
                echo esc_html__('El plugin está funcionando en modo limitado porque Composer no está disponible. Algunas funcionalidades podrían estar desactivadas.', 'mi-integracion-api');
                echo '</p>';
                echo '<p><small>';
                echo esc_html__('Para resolver este problema, ejecute: composer install', 'mi-integracion-api');
                echo '</small></p>';
                echo '</div>';
            } else {
                // Mostrar mensaje de éxito si Composer está funcionando
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p><strong>Mi Integración API:</strong> ';
                echo esc_html__('Composer está funcionando correctamente. Todas las funcionalidades están disponibles.', 'mi-integracion-api');
                echo '</p>';
                echo '</div>';
            }
        });
    }

    /**
     * Muestra advertencia de desarrollo
     * 
     * @return void
     */
    private static function showDevelopmentWarning(): void {
        // Solo ejecutar si estamos en WordPress
        if (!function_exists('add_action')) {
            return;
        }
        
        if (self::$environment_config['debug_mode']) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-info">';
                echo '<p><strong>Mi Integración API (Desarrollo):</strong> ';
                echo esc_html__('SmartAutoloader no está funcionando. Verifique la configuración.', 'mi-integracion-api');
                echo '</p>';
                echo '</div>';
            });
        }
    }

    /**
     * Determina si se deben generar logs
     * 
     * @return bool True si se deben generar logs
     */
    private static function shouldLog(): bool {
        // No logear en producción real
        if (self::isProductionEnvironment()) {
            return false;
        }
        
        // Solo logear si WP_DEBUG está habilitado Y estamos en desarrollo
        return defined('WP_DEBUG') && constant('WP_DEBUG') && self::isDevelopmentEnvironment();
    }
    
    /**
     * Detecta si estamos en un entorno de producción real
     * 
     * @return bool True si es producción
     */
    private static function isProductionEnvironment(): bool {
        // Verificar si estamos en un servidor de producción
        $server_name = $_SERVER['SERVER_NAME'] ?? '';
        $http_host = $_SERVER['HTTP_HOST'] ?? '';
        
        // Detectar dominios de producción
        $production_indicators = [
            'verialshoperp.impulsadixital.com',
            'verial.org',
            'produccion',
            'production'
        ];
        
        foreach ($production_indicators as $indicator) {
            if (strpos($server_name, $indicator) !== false || strpos($http_host, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detecta si estamos en un entorno de desarrollo
     * 
     * @return bool True si es desarrollo
     */
    private static function isDevelopmentEnvironment(): bool {
        // Verificar indicadores de desarrollo
        $server_name = $_SERVER['SERVER_NAME'] ?? '';
        $http_host = $_SERVER['HTTP_HOST'] ?? '';
        
        $development_indicators = [
            'localhost',
            '127.0.0.1',
            'dev.',
            'test.',
            'staging.',
            'local'
        ];
        
        foreach ($development_indicators as $indicator) {
            if (strpos($server_name, $indicator) !== false || strpos($http_host, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Registra un log de inicialización exitosa
     * 
     * @return void
     */
    private static function logSuccessfulInitialization(): void {
        // Solo log en desarrollo real, no en producción
        if (self::shouldLog() && class_exists('MiIntegracionApi\\Helpers\\Logger')) {
            $logger = new \MiIntegracionApi\Helpers\Logger('autoloader_manager');
            $logger->info('AutoloaderManager inicializado exitosamente', [
                'autoloader_status' => self::$autoloader_status,
                'environment_config' => self::$environment_config,
                'composer_healthy' => class_exists('MiIntegracionApi\\Core\\ComposerAutoloader') ? \MiIntegracionApi\Core\ComposerAutoloader::isHealthy() : false
            ]);
        }
    }

    /**
     * Registra un log de error de autoloader
     * 
     * @param string $autoloader_name Nombre del autoloader
     * @param \Throwable $e Excepción
     * @return void
     */
    private static function logAutoloaderError(string $autoloader_name, \Throwable $e): void {
        // Usar error_log directamente para evitar dependencias circulares
        $message = "[MiIntegracionApi] ERROR AutoloaderManager: Error al inicializar {$autoloader_name} - {$e->getMessage()}";
        
        if (function_exists('error_log')) {
            error_log($message);
        }
        
        // Si el Logger está disponible, usarlo también
        if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
            try {
                $logger = new \MiIntegracionApi\Helpers\Logger('autoloader_manager');
                $logger->error("Error al inicializar {$autoloader_name}", [
                    'autoloader' => $autoloader_name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            } catch (\Throwable $logger_error) {
                // Si el Logger falla, solo usar error_log
                error_log("[MiIntegracionApi] ERROR Logger no disponible: " . $logger_error->getMessage());
            }
        }
    }

    /**
     * Registra un log de advertencia de producción
     * 
     * @return void
     */
    private static function logProductionWarning(): void {
        if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
            $logger = new \MiIntegracionApi\Helpers\Logger('autoloader_manager');
            $logger->warning('Composer no disponible en producción - usando autoloaders de respaldo', [
                'environment' => 'production',
                'composer_available' => false
            ]);
        }
    }

    /**
     * Maneja errores críticos de inicialización
     * 
     * @param \Throwable $e Excepción
     * @return void
     */
    private static function handleInitializationError(\Throwable $e): void {
        // Intentar usar error_log como último recurso
        if (function_exists('error_log')) {
            error_log('[MiIntegracionApi] ERROR CRÍTICO AutoloaderManager: ' . $e->getMessage());
        }

        // Mostrar notificación de error crítico solo si estamos en WordPress
        if (function_exists('add_action')) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error">';
                echo '<p><strong>Mi Integración API - Error Crítico:</strong> ';
                echo esc_html($e->getMessage());
                echo '</p>';
                echo '<p><small>';
                echo esc_html__('Contacte al soporte técnico inmediatamente.', 'mi-integracion-api');
                echo '</small></p>';
                echo '</div>';
            });
        }
    }

    /**
     * Obtiene el estado de todos los autoloaders
     * 
     * @return array Estado de los autoloaders
     */
    public static function getAutoloaderStatus(): array {
        return self::$autoloader_status;
    }

    /**
     * Obtiene la configuración del entorno
     * 
     * @return array Configuración del entorno
     */
    public static function getEnvironmentConfig(): array {
        return self::$environment_config;
    }

    /**
     * Obtiene información de diagnóstico completa
     * 
     * @return array Información de diagnóstico
     */
    public static function getDiagnosticInfo(): array {
        $diagnostic = [
            'manager_initialized' => self::$initialized,
            'autoloader_status' => self::$autoloader_status,
            'environment_config' => self::$environment_config
        ];

        // Añadir diagnósticos específicos si las clases están disponibles
        if (class_exists('MiIntegracionApi\\Core\\ComposerAutoloader')) {
            $diagnostic['composer_diagnostic'] = \MiIntegracionApi\Core\ComposerAutoloader::getDiagnosticInfo();
        }

        if (class_exists('MiIntegracionApi\\Core\\SmartAutoloader')) {
            $diagnostic['smart_diagnostic'] = \MiIntegracionApi\Core\SmartAutoloader::getDiagnosticInfo();
        }

        if (class_exists('MiIntegracionApi\\Core\\EmergencyLoader')) {
            $diagnostic['emergency_diagnostic'] = \MiIntegracionApi\Core\EmergencyLoader::getDiagnosticInfo();
        }

        return $diagnostic;
    }

    /**
     * Verifica si el manager está inicializado
     * 
     * @return bool True si está inicializado
     */
    public static function isInitialized(): bool {
        return self::$initialized;
    }

    /**
     * Verifica si un autoloader específico está funcionando
     * 
     * @param string $autoloader Nombre del autoloader
     * @return bool True si está funcionando
     */
    public static function isAutoloaderWorking(string $autoloader): bool {
        return isset(self::$autoloader_status[$autoloader]) && 
               self::$autoloader_status[$autoloader];
    }

    /**
     * Fuerza la reconstrucción del cache de SmartAutoloader
     * 
     * @return void
     */
    public static function rebuildSmartAutoloaderCache(): void {
        if (self::$autoloader_status['smart'] && class_exists('MiIntegracionApi\\Core\\SmartAutoloader')) {
            \MiIntegracionApi\Core\SmartAutoloader::rebuildClassMap();
        }
    }

    /**
     * Limpia todos los caches de autoloaders
     * 
     * @return void
     */
    public static function clearAllCaches(): void {
        if (self::$autoloader_status['smart'] && class_exists('MiIntegracionApi\\Core\\SmartAutoloader')) {
            \MiIntegracionApi\Core\SmartAutoloader::clearCache();
        }
        
        if (self::$autoloader_status['emergency'] && class_exists('MiIntegracionApi\\Core\\EmergencyLoader')) {
            \MiIntegracionApi\Core\EmergencyLoader::resetLoadAttempts();
        }
    }

    /**
     * Obtiene estadísticas de rendimiento de los autoloaders
     * 
     * @return array Estadísticas de rendimiento
     */
    public static function getPerformanceStats(): array {
        $stats = [
            'total_autoloaders' => count(self::$autoloader_status),
            'working_autoloaders' => array_sum(self::$autoloader_status),
            'composer_healthy' => class_exists('MiIntegracionApi\\Core\\ComposerAutoloader') ? \MiIntegracionApi\Core\ComposerAutoloader::isHealthy() : false,
            'environment' => self::$environment_config['development'] ? 'development' : 'production'
        ];

        // Añadir estadísticas específicas si están disponibles
        if (self::$autoloader_status['smart'] && class_exists('MiIntegracionApi\\Core\\SmartAutoloader')) {
            $smart_info = \MiIntegracionApi\Core\SmartAutoloader::getDiagnosticInfo();
            $stats['smart_class_map_size'] = $smart_info['class_map_size'] ?? 0;
        }

        return $stats;
    }
}
