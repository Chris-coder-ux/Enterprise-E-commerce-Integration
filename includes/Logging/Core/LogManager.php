<?php declare(strict_types=1);

/**
 * Clase simplificada para gestión de instancias de Logger por categoría.
 *
 * Implementa el patrón Singleton por categoría para reutilizar instancias
 * de Logger y optimizar el rendimiento del sistema de logging.
 *
 * @package MiIntegracionApi\Logging\Core
 * @since 1.0.0
 * @version 1.2.0
 */

namespace MiIntegracionApi\Logging\Core;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar las clases necesarias
require_once __DIR__ . '/Logger_complex.php';

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
 * @version 1.2.0
 */
class LogManager implements ILogManager
{
    /**
     * Instancias de Logger organizadas por categoría.
     *
     * @var array<string, ILogger> Mapa de categorías a instancias de Logger.
     * @static
     */
    private static array $loggers = [];

    /**
     * Instancia única del LogManager (implementación del patrón Singleton).
     *
     * @var self|null Instancia única de LogManager.
     * @static
     */
    private static ?LogManager $instance = null;

    /**
     * Constructor privado para implementar el patrón Singleton.
     */
    private function __construct()
    {
        // Constructor privado para Singleton
    }

    /**
     * Obtiene la instancia única del LogManager.
     *
     * @return LogManager Instancia única del LogManager.
     * @since 1.0.0
     */
    public static function getInstance(): LogManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtiene un Logger para la categoría especificada.
     *
     * @param string $category Categoría del logger.
     * @return ILogger Instancia del Logger para la categoría.
     * @since 1.0.0
     */
    public function getLogger(string $category): ILogger
    {
        if (!isset(self::$loggers[$category])) {
            // Usar LoggerComplex que sí escribe a archivos
            self::$loggers[$category] = new \MiIntegracionApi\Logging\Core\LoggerComplex($category);
        }
        return self::$loggers[$category];
    }

    /**
     * Obtiene un Logger con configuración específica.
     *
     * @param string $category Categoría del logger.
     * @param array $config Configuración específica.
     * @return ILogger Instancia del Logger con configuración.
     * @since 1.0.0
     */
    public function getLoggerWithConfig(string $category, array $config): ILogger
    {
        // Por simplicidad, devolvemos un logger normal
        return $this->getLogger($category);
    }

    /**
     * Verifica si existe un Logger para la categoría especificada.
     *
     * @param string $category Categoría del logger.
     * @return bool True si existe, false en caso contrario.
     * @since 1.0.0
     */
    public function hasLogger(string $category): bool
    {
        return isset(self::$loggers[$category]);
    }

    /**
     * Obtiene las categorías activas.
     *
     * @return array<string> Lista de categorías activas.
     * @since 1.0.0
     */
    public function getActiveCategories(): array
    {
        return array_keys(self::$loggers);
    }

    /**
     * Limpia un Logger específico.
     *
     * @param string $category Categoría del logger a limpiar.
     * @return bool True si se limpió correctamente.
     * @since 1.0.0
     */
    public function clearLogger(string $category): bool
    {
        if (isset(self::$loggers[$category])) {
            unset(self::$loggers[$category]);
            return true;
        }
        return false;
    }

    /**
     * Limpia todos los Loggers.
     *
     * @return int Número de Loggers limpiados.
     * @since 1.0.0
     */
    public function clearAllLoggers(): int
    {
        $count = count(self::$loggers);
        self::$loggers = [];
        return $count;
    }

    /**
     * Obtiene el número de Loggers activos.
     *
     * @return int Número de Loggers activos.
     * @since 1.0.0
     */
    public function getLoggerCount(): int
    {
        return count(self::$loggers);
    }

    /**
     * Obtiene información de debug del LogManager.
     *
     * @return array Información de debug.
     * @since 1.0.0
     */
    public function getDebugInfo(): array
    {
        return [
            'active_categories' => $this->getActiveCategories(),
            'logger_count' => $this->getLoggerCount(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }

    /**
     * Método de compatibilidad para código existente.
     *
     * @param string $category Categoría del logger.
     * @return ILogger Instancia del Logger.
     * @deprecated 1.1.0 Use getLogger() instead
     * @since 1.0.0
     */
    public function get_logger_instance(string $category = 'default'): ILogger
    {
        return $this->getLogger($category);
    }
}
