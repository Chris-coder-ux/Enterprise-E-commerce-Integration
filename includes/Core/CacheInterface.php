<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

/**
 * Interfaz abstracta para el sistema de caché
 * 
 * Esta interfaz define los métodos esenciales que debe implementar
 * cualquier sistema de caché, independientemente de WordPress.
 * 
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 */
interface CacheInterface
{
    /**
     * Obtiene un valor del caché
     * 
     * @param string $key Clave del caché
     * @param mixed $default Valor por defecto si no se encuentra
     * @return mixed Valor del caché o valor por defecto
     */
    public function get(string $key, $default = null);

    /**
     * Establece un valor en el caché
     * 
     * @param string $key Clave del caché
     * @param mixed $value Valor a almacenar
     * @param int|null $ttl Tiempo de vida en segundos (null = sin expiración)
     * @return bool True si se almacenó correctamente
     */
    public function set(string $key, $value, ?int $ttl = null): bool;

    /**
     * Elimina un valor del caché
     * 
     * @param string $key Clave del caché
     * @return bool True si se eliminó correctamente
     */
    public function delete(string $key): bool;

    /**
     * Verifica si existe una clave en el caché
     * 
     * @param string $key Clave del caché
     * @return bool True si existe
     */
    public function has(string $key): bool;

    /**
     * Limpia todo el caché
     * 
     * @return bool True si se limpió correctamente
     */
    public function clear(): bool;

    /**
     * Obtiene estadísticas del caché
     * 
     * @return array Estadísticas del caché
     */
    public function getStats(): array;
}
