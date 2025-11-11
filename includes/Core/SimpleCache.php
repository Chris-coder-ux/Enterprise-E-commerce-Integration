<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

/**
 * Implementación simple de caché en memoria que implementa CacheInterface
 * 
 * Esta clase proporciona funcionalidad básica de caché usando arrays en memoria,
 * ideal para testing y entornos sin WordPress. Maneja el almacenamiento de datos
 * temporales con soporte para tiempo de vida (TTL) de las entradas.
 * 
 * Características principales:
 * - Almacenamiento en memoria con arrays asociativos
 * - Soporte para tiempo de expiración (TTL) por entrada
 * - Estadísticas de uso del caché
 * - Limpieza automática de entradas expiradas
 * 
 * @package     MiIntegracionApi\Core
 * @implements  CacheInterface
 * @since       1.0.0
 * @see         CacheInterface
 */
class SimpleCache implements CacheInterface
{
    /**
     * Almacenamiento en memoria de los datos en caché
     * 
     * @var array<string, mixed> Array asociativo que almacena los valores en caché
     *                           donde la clave es el identificador y el valor es el dato almacenado
     */
    private array $storage = [];

    /**
     * Control de tiempos de expiración de las entradas en caché
     * 
     * @var array<string, int> Array asociativo donde las claves son los identificadores
     *                         y los valores son timestamps de expiración en segundos
     */
    private array $expirations = [];

    /**
     * Estadísticas de uso del caché
     * 
     * @var array{
     *     hits: int,     // Número de aciertos en el caché
     *     misses: int,   // Número de fallos en el caché
     *     sets: int,     // Número de inserciones/actualizaciones
     *     deletes: int,  // Número de eliminaciones
     *     clears: int    // Número de limpiezas completas
     * }

    /**
     * Obtiene un valor del caché por su clave
     * 
     * @param string        $key     Clave del elemento a recuperar
     * @param mixed|null    $default Valor por defecto a devolver si la clave no existe
     * @return mixed El valor almacenado o $default si no existe o ha expirado
     * @throws \Psr\SimpleCache\InvalidArgumentException Si la clave no es válida
     * @since 1.0.0
     */
    public function get(string $key, $default = null)
    {
        if ($this->has($key)) {
            $this->stats['hits']++;
            return $this->storage[$key];
        }

        $this->stats['misses']++;
        return $default;
    }

    /**
     * Almacena un valor en el caché con la clave especificada
     * 
     * @param string                 $key   Clave bajo la que se almacenará el valor
     * @param mixed                  $value Valor a almacenar
     * @param int|\DateInterval|null $ttl   Tiempo de vida opcional del valor en segundos
     * @return bool True en caso de éxito, false en caso de error
     * @throws \Psr\SimpleCache\InvalidArgumentException Si la clave no es válida
     * @since 1.0.0
     */
    public function set(string $key, $value, $ttl = null): bool
    {
        $this->storage[$key] = $value;
        
        if ($ttl !== null) {
            $this->expirations[$key] = time() + $ttl;
        } else {
            unset($this->expirations[$key]);
        }

        $this->stats['sets']++;
        return true;
    }

    /**
     * Elimina un elemento del caché por su clave
     * 
     * @param string $key Clave del elemento a eliminar
     * @return bool True si el elemento existía y fue eliminado, false en caso contrario
     * @throws \Psr\SimpleCache\InvalidArgumentException Si la clave no es válida
     * @since 1.0.0
     */
    public function delete(string $key): bool
    {
        $deleted = isset($this->storage[$key]);
        
        unset($this->storage[$key]);
        unset($this->expirations[$key]);

        if ($deleted) {
            $this->stats['deletes']++;
        }

        return $deleted;
    }

    /**
     * Comprueba si una clave existe en el caché y no ha expirado
     * 
     * @param string $key Clave a verificar
     * @return bool True si la clave existe y es válida, false en caso contrario
     * @throws \Psr\SimpleCache\InvalidArgumentException Si la clave no es válida
     * @since 1.0.0
     */
    public function has(string $key): bool
    {
        if (!isset($this->storage[$key])) {
            return false;
        }

        // Verificar expiración
        if (isset($this->expirations[$key]) && time() > $this->expirations[$key]) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    /**
     * Limpia todas las entradas del caché
     * 
     * @return bool Siempre devuelve true
     * @since 1.0.0
     */
    public function clear(): bool
    {
        $this->storage = [];
        $this->expirations = [];
        $this->stats['clears']++;
        return true;
    }

    /**
     * Obtiene estadísticas de uso del caché
     * 
     * @return array{
     *     hits: int,
     *     misses: int,
     *     sets: int,
     *     deletes: int,
     *     clears: int,
     *     size: int,
     *     expired_keys: int
     * } Array con las estadísticas del caché
     * @since 1.0.0
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'size' => count($this->storage),
            'expired_keys' => count($this->expirations)
        ]);
    }

    /**
     * Limpia todas las entradas expiradas del caché
     * 
     * Este método recorre todas las entradas con tiempo de expiración
     * y elimina aquellas que ya han caducado.
     * 
     * @return int Número de entradas expiradas que fueron eliminadas
     * @since 1.0.0
     */
    public function cleanupExpired(): int
    {
        $cleaned = 0;
        $now = time();

        foreach ($this->expirations as $key => $expiration) {
            if ($now > $expiration) {
                $this->delete($key);
                $cleaned++;
            }
        }

        return $cleaned;
    }
}
