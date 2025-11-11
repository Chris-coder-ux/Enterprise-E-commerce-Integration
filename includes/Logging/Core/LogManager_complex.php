<?php declare(strict_types=1);

/**
 * LogManager - Sistema de gestión centralizada de instancias de Logger por categoría.
 *
 * Implementa el patrón Singleton por categoría para optimizar el rendimiento
 * y gestionar múltiples instancias de Logger de forma centralizada. Proporciona
 * una interfaz unificada para el registro de eventos en la aplicación.
 *
 * Características principales:
 * - Gestión centralizada de múltiples loggers por categoría
 * - Patrón Singleton para evitar duplicación de instancias
 * - Configuración flexible por categoría
 * - Filtrado de datos sensibles integrado
 * - Métodos de conveniencia para diferentes niveles de log
 * - Compatibilidad con PSR-3
 *
 * @package MiIntegracionApi\Logging\Core
 * @since 1.0.0
 * @version 1.1.0
 * @see ILogManager
 * @see ILogger
 * @see LogConfiguration
 *
 * @example
 * // Uso básico
 * $logManager = LogManager::getInstance();
 * $logger = $logManager->getLogger('mi-categoria');
 * $logger->info('Mensaje informativo');
 *
 * // Uso directo (métodos de conveniencia)
 * LogManager::getInstance()->error('Error crítico', ['error' => $e->getMessage()]);
 */

namespace MiIntegracionApi\Logging\Core;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

use MiIntegracionApi\Logging\Interfaces\ILogManager;
use MiIntegracionApi\Logging\Interfaces\ILogger;

/**
 * Clase para gestión de instancias de Logger por categoría.
 *
 * Implementa el patrón Singleton por categoría para reutilizar instancias
 * de Logger y optimizar el rendimiento del sistema de logging.
 *
 * @package MiIntegracionApi\Logging\Core
 * @since 1.0.0
 */
class LogManager implements ILogManager
{
    /**
     * Almacena las instancias de Logger por categoría.
     *
     * @var array<string, ILogger> Mapa de categorías a instancias de Logger.
     * @static
     */
    private static array $loggers = [];

    /**
     * Configuración del sistema de logging.
     *
     * @var array|null Configuración cargada del sistema de logging.
     * @static
     */
    private static ?array $configuration = null;

    /**
     * Instancia única del LogManager (implementación del patrón Singleton).
     *
     * @var self|null Instancia única de LogManager.
     * @static
     */
    private static ?self $instance = null;

    /**
     * Constructor privado para implementar el patrón Singleton.
     *
     * Inicializa la configuración del sistema de logging si no está ya inicializada.
     * Este método es privado para forzar el uso de getInstance().
     *
     * @throws \RuntimeException Si no se puede inicializar la configuración.
     * @see getInstance()
     */
    private function __construct()
    {
        // Inicializar configuración si no está inicializada
        if (self::$configuration === null) {
            try {
                self::$configuration = new LogConfiguration();
                self::$configuration->initialize();
            } catch (\Exception $e) {
                throw new \RuntimeException('No se pudo inicializar la configuración del logger: ' . $e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * Obtiene la instancia única del LogManager (implementación del patrón Singleton).
     *
     * Este método garantiza que solo exista una instancia de LogManager en toda la aplicación,
     * proporcionando un punto de acceso global al sistema de logging.
     *
     * @return self Instancia única del LogManager.
     * @throws \RuntimeException Si no se puede crear la instancia.
     *
     * @example
     * // Obtener la instancia del LogManager
     * $logManager = LogManager::getInstance();
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            try {
                self::$instance = new self();
            } catch (\Exception $e) {
                throw new \RuntimeException('No se pudo crear la instancia de LogManager: ' . $e->getMessage(), 0, $e);
            }
        }

        return self::$instance;
    }

    /**
     * Obtiene una instancia de Logger para una categoría específica.
     *
     * Si ya existe una instancia para la categoría solicitada, la reutiliza.
     * Si no existe, crea una nueva instancia configurada según la categoría.
     *
     * @param string $category Categoría del logger (ej: 'sync-manager', 'api-connector').
     *                        Debe ser un string no vacío.
     * @return ILogger Instancia de Logger configurada para la categoría especificada.
     * @throws \InvalidArgumentException Si la categoría está vacía o solo contiene espacios en blanco.
     *
     * @example
     * // Obtener un logger para una categoría específica
     * $logger = $logManager->getLogger('api-connector');
     * $logger->info('Iniciando conexión con la API');
     */
    public function getLogger(string $category): ILogger
    {
        if (empty(trim($category))) {
            throw new \InvalidArgumentException('La categoría del logger no puede estar vacía');
        }

        // Normalizar categoría
        $category = strtolower(trim($category));

        // Verificar si ya existe la instancia
        if (isset(self::$loggers[$category])) {
            return self::$loggers[$category];
        }

        // Crear nueva instancia
        $logger = $this->createLogger($category);
        self::$loggers[$category] = $logger;

        return $logger;
    }

    /**
     * Obtiene una instancia de Logger con configuración personalizada para una categoría.
     *
     * Permite sobrescribir la configuración por defecto para una categoría específica.
     * Útil cuando necesitas configuraciones particulares para ciertos componentes.
     *
     * @param string $category Categoría del logger. No puede estar vacía.
     * @param array<string, mixed> $config Configuración específica para esta instancia.
     *        Opciones comunes:
     *        - min_level: Nivel mínimo de log (ej: 'debug', 'info', 'warning', etc.)
     *        - handlers: Array de manejadores personalizados
     *        - processors: Array de procesadores personalizados
     * @return ILogger Instancia de Logger configurada con las opciones proporcionadas.
     * @throws \InvalidArgumentException Si la categoría está vacía o la configuración es inválida.
     *
     * @example
     * // Obtener un logger con configuración personalizada
     * $config = [
     *     'min_level' => 'debug',
     *     'handlers' => [new StreamHandler('path/to/custom.log')]
     * ];
     * $logger = $logManager->getLoggerWithConfig('custom-module', $config);
     */
    public function getLoggerWithConfig(string $category, array $config): ILogger
    {
        if (empty(trim($category))) {
            throw new \InvalidArgumentException('La categoría del logger no puede estar vacía');
        }

        // Normalizar categoría
        $category = strtolower(trim($category));

        // Crear clave única para esta configuración
        $configKey = $category . '_' . md5(serialize($config));

        // Verificar si ya existe la instancia con esta configuración
        if (isset(self::$loggers[$configKey])) {
            return self::$loggers[$configKey];
        }

        // Crear nueva instancia con configuración personalizada
        $logger = $this->createLogger($category, $config);
        self::$loggers[$configKey] = $logger;

        return $logger;
    }

    /**
     * Verifica si existe una instancia de Logger para una categoría específica.
     *
     * Útil para determinar si ya se ha creado un logger para una categoría determinada
     * sin necesidad de crearlo.
     *
     * @param string $category Categoría a verificar.
     * @return bool True si existe una instancia para la categoría, false en caso contrario.
     *
     * @example
     * if (!$logManager->hasLogger('mi-modulo')) {
     *     // Configuración especial para este módulo
     *     $logger = $logManager->getLogger('mi-modulo');
     * }
     */
    public function hasLogger(string $category): bool
    {
        $category = strtolower(trim($category));
        return isset(self::$loggers[$category]);
    }

    /**
     * Obtiene todas las categorías de Logger que tienen instancias activas.
     *
     * @return array<string> Lista de categorías para las que existe al menos una instancia de Logger.
     *
     * @example
     * // Obtener todas las categorías activas
     * $categorias = $logManager->getActiveCategories();
     * // ['api', 'database', 'security', ...]
     */
    public function getActiveCategories(): array
    {
        return array_keys(self::$loggers);
    }

    /**
     * Elimina una instancia de Logger específica del registro.
     *
     * Útil para liberar recursos cuando un logger ya no es necesario
     * o para forzar la recreación de un logger con una configuración actualizada.
     *
     * @param string $category Categoría del logger a eliminar.
     * @return bool True si se eliminó correctamente, false si no existía.
     *
     * @example
     * // Eliminar un logger específico
     * if ($logManager->clearLogger('modulo-obsoleto')) {
     *     echo 'Logger para módulo obsoleto eliminado';
     * }
     */
    public function clearLogger(string $category): bool
    {
        $category = strtolower(trim($category));
        
        if (isset(self::$loggers[$category])) {
            unset(self::$loggers[$category]);
            return true;
        }

        return false;
    }

    /**
     * Elimina todas las instancias de Logger registradas.
     *
     * Útil en situaciones como pruebas unitarias o reinicio del sistema de logging.
     * Todas las futuras solicitudes de loggers crearán nuevas instancias.
     *
     * @return int Número de instancias eliminadas.
     *
     * @example
     * // Reiniciar todo el sistema de logging
     * $count = $logManager->clearAllLoggers();
     * echo "Se eliminaron $count loggers";
     */
    public function clearAllLoggers(): int
    {
        $count = count(self::$loggers);
        self::$loggers = [];
        return $count;
    }

    /**
     * Obtiene el número total de instancias de Logger activas.
     *
     * Útil para propósitos de monitoreo y depuración.
     *
     * @return int Número de instancias de Logger actualmente registradas.
     *
     * @example
     * // Monitorear el número de loggers activos
     * $count = $logManager->getLoggerCount();
     * if ($count > 100) {
     *     // Tomar acción si hay demasiados loggers
     * }
     */
    public function getLoggerCount(): int
    {
        return count(self::$loggers);
    }

    /**
     * Obtiene información detallada de diagnóstico sobre el estado del sistema de logging.
     *
     * Esta información es útil para depuración y monitoreo, incluyendo:
     * - Entorno de ejecución
     * - Estadísticas de uso de memoria
     * - Configuración actual
     * - Categorías activas
     *
     * @return array<string, mixed> Array asociativo con información de diagnóstico:
     *         - environment: Entorno de ejecución detectado
     *         - total_loggers: Número total de loggers activos
     *         - active_categories: Lista de categorías activas
     *         - configuration: Configuración actual del logger
     *         - memory_usage: Uso actual de memoria en bytes
     *         - memory_peak: Pico de uso de memoria en bytes
     *
     * @example
     * // Obtener información de diagnóstico
     * $debugInfo = $logManager->getDebugInfo();
     * echo json_encode($debugInfo, JSON_PRETTY_PRINT);
     */
    public function getDebugInfo(): array
    {
        $environment = EnvironmentDetector::detectEnvironment();
        $config = self::$configuration->getDebugInfo();

        return [
            'environment' => $environment,
            'total_loggers' => $this->getLoggerCount(),
            'active_categories' => $this->getActiveCategories(),
            'configuration' => $config,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Crea una nueva instancia de Logger para una categoría específica.
     *
     * Este método es responsable de la creación real de las instancias de Logger,
     * aplicando la configuración por defecto y cualquier configuración personalizada.
     *
     * @param string $category Categoría para el nuevo logger.
     * @param array<string, mixed> $config Configuración personalizada que sobrescribe la configuración por defecto.
     * @return ILogger Nueva instancia de Logger configurada.
     * @throws \RuntimeException Si no se puede crear la instancia del logger.
     *
     * @see Logger
     */
    private function createLogger(string $category, array $config = []): ILogger
    {
        // Obtener configuración combinada
        $combinedConfig = self::$configuration->getCombinedConfig($category);
        
        // Aplicar configuración personalizada si se proporciona
        if (!empty($config)) {
            $combinedConfig = array_merge($combinedConfig, $config);
        }

        // Obtener nivel mínimo de log
        $minLevel = $combinedConfig['min_level'] ?? 'info';

        // Crear instancia de Logger
        $logger = new Logger($category, $minLevel);

        // Configurar nivel mínimo si es diferente al por defecto
        if (isset($combinedConfig['min_level'])) {
            $logger->setMinLevel($combinedConfig['min_level']);
        }

        return $logger;
    }

    /**
     * Método de compatibilidad para código heredado.
     *
     * Este método se mantiene únicamente para compatibilidad con versiones anteriores.
     * Nuevo código debe usar getLogger() en su lugar.
     *
     * @param string $category Categoría del logger. Por defecto: 'default'.
     * @return ILogger Instancia de Logger para la categoría especificada.
     * @throws \InvalidArgumentException Si la categoría es inválida.
     * @deprecated 1.1.0 Use getLogger() en su lugar. Este método será eliminado en una versión futura.
     * @codeCoverageIgnore
     *
     * @example
     * // Uso obsoleto (no recomendado)
     * $logger = $logManager->get_logger_instance('legacy');
     *
     * // Uso recomendado
     * $logger = $logManager->getLogger('nuevo-modulo');
     */
    public function get_logger_instance(string $category = 'default'): ILogger
    {
        return $this->getLogger($category);
    }

    /**
     * Métodos de conveniencia para logging directo (compatibilidad hacia atrás).
     *
     * Estos métodos permiten usar LogManager directamente para logging
     * sin necesidad de obtener una instancia de Logger.
     */

    /**
     * Registra un mensaje de debug.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional.
     * @param string $category Categoría del logger (opcional).
     * @return void
     */
    public function debug(string $message, array $context = [], string $category = 'default'): void
    {
        $this->log('debug', $message, $context, $category);
    }

    /**
     * Registra un mensaje de info.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional.
     * @param string $category Categoría del logger (opcional).
     * @return void
     */
    public function info(string $message, array $context = [], string $category = 'default'): void
    {
        $this->log('info', $message, $context, $category);
    }

    /**
     * Registra un mensaje de notice.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional.
     * @param string $category Categoría del logger (opcional).
     * @return void
     */
    public function notice(string $message, array $context = [], string $category = 'default'): void
    {
        $this->log('notice', $message, $context, $category);
    }

    /**
     * Registra un mensaje de warning.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional.
     * @param string $category Categoría del logger (opcional).
     * @return void
     */
    public function warning(string $message, array $context = [], string $category = 'default'): void
    {
        $this->log('warning', $message, $context, $category);
    }

    /**
     * Registra un mensaje de error.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional.
     * @param string $category Categoría del logger (opcional).
     * @return void
     */
    public function error(string $message, array $context = [], string $category = 'default'): void
    {
        $this->log('error', $message, $context, $category);
    }

    /**
     * Registra un mensaje de critical.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional.
     * @param string $category Categoría del logger (opcional).
     * @return void
     */
    public function critical(string $message, array $context = [], string $category = 'default'): void
    {
        $this->log('critical', $message, $context, $category);
    }

    /**
     * Registra un mensaje de alert.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional.
     * @param string $category Categoría del logger (opcional).
     * @return void
     */
    public function alert(string $message, array $context = [], string $category = 'default'): void
    {
        $this->log('alert', $message, $context, $category);
    }

    /**
     * Registra un mensaje de emergency.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional.
     * @param string $category Categoría del logger (opcional).
     * @return void
     */
    public function emergency(string $message, array $context = [], string $category = 'default'): void
    {
        $this->log('emergency', $message, $context, $category);
    }

    /**
     * Registra un mensaje con el nivel de log especificado.
     *
     * Este es el método central para el registro de mensajes, utilizado internamente
     * por todos los métodos de conveniencia (debug, info, error, etc.).
     *
     * @param string $level Nivel de log (debug, info, notice, warning, error, critical, alert, emergency).
     * @param string $message Mensaje a registrar. Puede contener placeholders {key} que serán reemplazados con valores del contexto.
     * @param array<string, mixed> $context Array asociativo con datos adicionales para incluir en el log.
     * @param string $category Categoría del logger. Por defecto: 'default'.
     * @return void
     *
     * @throws \Psr\Log\InvalidArgumentException Si el nivel de log no es válido.
     * @throws \RuntimeException Si no se puede escribir en el log.
     *
     * @example
     * // Uso directo del método log
     * $logManager->log('error', 'Error al procesar la solicitud', [
     *     'user_id' => 123,
     *     'error' => $e->getMessage(),
     *     'trace' => $e->getTraceAsString()
     * ], 'api');
     *
     * // Los siguientes usos son equivalentes:
     * $logManager->error('Error crítico', $context, 'app');
     * $logManager->log('error', 'Error crítico', $context, 'app');
     */
    public function log(string $level, string $message, array $context = [], string $category = 'default'): void
    {
        try {
            // Obtener logger para la categoría
            $logger = $this->getLogger($category);

            // Aplicar filtrado de datos sensibles si está habilitado
            $securityConfig = self::$configuration->getSecurityConfig();
            if ($securityConfig['sensitive_data_protection'] ?? true) {
                $context = SensitiveDataFilter::filterContext($context);
            }

            // Registrar el mensaje
            $logger->log($level, $message, $context);

        } catch (\Throwable $e) {
            // Fallback: usar error_log nativo
            error_log("[FALLBACK_LOG] [{$level}] [{$category}] {$message} | " . json_encode($context));
        }
    }
}
