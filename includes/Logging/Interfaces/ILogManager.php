<?php declare(strict_types=1);

/**
 * Interfaz para gestión de instancias de Logger por categoría.
 *
 * Define los métodos para gestionar múltiples instancias de Logger
 * organizadas por categorías, implementando el patrón Singleton
 * por categoría para optimizar el rendimiento.
 *
 * @package MiIntegracionApi\Logging\Interfaces
 * @since 1.0.0
 * @version 1.1.0
 */

namespace MiIntegracionApi\Logging\Interfaces;

/**
 * Interfaz para gestión de instancias de Logger por categoría.
 *
 * Esta interfaz define los métodos necesarios para gestionar múltiples
 * instancias de Logger organizadas por categorías, permitiendo la
 * reutilización de instancias y la optimización de recursos.
 *
 * @package MiIntegracionApi\Logging\Interfaces
 * @since 1.0.0
 */
interface ILogManager
{
    /**
     * Obtiene una instancia de Logger para una categoría específica.
     *
     * Si la instancia ya existe para la categoría, la reutiliza.
     * Si no existe, crea una nueva instancia.
     *
     * @param string $category Categoría del logger (ej: 'sync-manager', 'api-connector').
     * @return ILogger Instancia de Logger para la categoría especificada.
     * @throws \InvalidArgumentException Si la categoría está vacía o es inválida.
     */
    public function getLogger(string $category): ILogger;

    /**
     * Obtiene una instancia de Logger para una categoría específica con configuración personalizada.
     *
     * @param string $category Categoría del logger.
     * @param array  $config   Configuración específica para esta instancia.
     * @return ILogger Instancia de Logger configurada.
     * @throws \InvalidArgumentException Si la categoría está vacía o la configuración es inválida.
     */
    public function getLoggerWithConfig(string $category, array $config): ILogger;

    /**
     * Verifica si existe una instancia de Logger para una categoría específica.
     *
     * @param string $category Categoría a verificar.
     * @return bool True si existe la instancia, false en caso contrario.
     */
    public function hasLogger(string $category): bool;

    /**
     * Obtiene todas las categorías de Logger activas.
     *
     * @return array Lista de categorías activas.
     */
    public function getActiveCategories(): array;

    /**
     * Limpia una instancia de Logger específica.
     *
     * @param string $category Categoría a limpiar.
     * @return bool True si se limpió correctamente, false si no existía.
     */
    public function clearLogger(string $category): bool;

    /**
     * Limpia todas las instancias de Logger.
     *
     * @return int Número de instancias limpiadas.
     */
    public function clearAllLoggers(): int;

    /**
     * Obtiene el número total de instancias de Logger activas.
     *
     * @return int Número de instancias activas.
     */
    public function getLoggerCount(): int;

    /**
     * Obtiene información de debug sobre las instancias de Logger.
     *
     * @return array Información detallada de todas las instancias.
     */
    public function getDebugInfo(): array;
}
