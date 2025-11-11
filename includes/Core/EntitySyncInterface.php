<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

/**
 * Interfaz común para servicios de sincronización de entidades
 *
 * Define el contrato estándar que deben cumplir todos los servicios
 * de sincronización de entidades en el sistema multi-entidad.
 *
 * @package MiIntegracionApi\Core
 * @since 1.5.0
 */
interface EntitySyncInterface
{
    /**
     * Obtiene el conteo total de elementos a sincronizar
     *
     * Puede hacer llamada a endpoint específico si existe,
     * o realizar estimación paginada incremental.
     *
     * @param array $filters Filtros opcionales para el conteo
     * @return int Número total de elementos
     * @throws SyncError Si hay error al obtener el conteo
     */
    public function pre_count(array $filters = []): int;

    /**
     * Obtiene un lote de elementos de la fuente remota
     *
     * @param int $page Página del lote (base 1)
     * @param int $size Tamaño del lote
     * @param array $filters Filtros opcionales
     * @return array Array de elementos del lote
     * @throws SyncError Si hay error al obtener el lote
     */
    public function fetch_batch(int $page, int $size, array $filters = []): array;

    /**
     * Crea o actualiza un lote de elementos en el destino local
     *
     * @param array $items Array de elementos a procesar
     * @param array $context Contexto adicional (batch_cache, etc.)
     * @return SyncResult Resultado del procesamiento
     * @throws SyncError Si hay error crítico en el procesamiento
     */
    public function upsert_batch(array $items, array $context = []): SyncResult;

    /**
     * Obtiene el nombre de la entidad
     *
     * @return string Nombre único de la entidad (ej: 'categories', 'clients', 'geo')
     */
    public function get_entity_name(): string;

    /**
     * Obtiene las dependencias de la entidad
     *
     * @return array Array de nombres de entidades que deben sincronizarse antes
     */
    public function get_dependencies(): array;

    /**
     * Valida si la entidad está lista para sincronización
     *
     * @return bool True si puede proceder con la sincronización
     * @throws SyncError Si la validación falla
     */
    public function validate_prerequisites(): bool;

    /**
     * Obtiene configuración específica de la entidad
     *
     * @return array Configuración específica (batch_size, timeout, etc.)
     */
    public function get_sync_config(): array;

    /**
     * Limpia recursos y estado después de completar sincronización
     *
     * @param bool $success Si la sincronización fue exitosa
     * @return void
     */
    public function cleanup(bool $success): void;
}
