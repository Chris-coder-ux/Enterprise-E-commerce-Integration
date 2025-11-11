<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

/**
 * Sistema de recuperación para sincronizaciones
 */
class SyncRecovery
{
    private const RECOVERY_PREFIX = 'sync_recovery_'; // CAMBIADO para compatibilidad
    private const DEFAULT_TTL = 86400; // 24 horas

    /**
     * Guarda el estado de recuperación
     * 
     * @param string $entity Nombre de la entidad
     * @param array<string, mixed> $state Estado de la sincronización
     * @param int $ttl Tiempo de vida en segundos
     * @return bool Éxito de la operación
     */
    public static function saveState(string $entity, array $state, int $ttl = self::DEFAULT_TTL): bool
    {
        if (empty($entity) || empty($state)) {
            return false;
        }

        $logger = new \MiIntegracionApi\Helpers\Logger('sync-recovery');
        $key = self::getRecoveryKey($entity);
        
        // AGREGAR CAMPOS COMPATIBLES CON Sync_Manager
        $state['timestamp'] = time();
        $state['version'] = '2.0'; // Marcar como versión mejorada
        $state['entity'] = $entity;

        $logger->info(
            "Guardando estado de recuperación para {$entity}",
            [
                'entity' => $entity,
                'processed' => $state['processed'] ?? $state['processed_items'] ?? 0,
                'total' => $state['total'] ?? $state['total_items'] ?? 0,
                'last_batch' => $state['last_batch'] ?? 0,
                'category' => "sync-recovery-{$entity}"
            ]
        );

        return set_transient($key, $state, $ttl);
    }

    /**
     * Obtiene el estado de recuperación
     * 
     * @param string $entity Nombre de la entidad
     * @return array<string, mixed>|false Estado de recuperación o false si no existe
     */
    public static function getState(string $entity): array|false
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getRecoveryKey($entity);
        $state = get_transient($key);

        if ($state === false) {
            return false;
        }

        // Verificar si el estado ha expirado
        if (isset($state['timestamp']) && (time() - $state['timestamp']) > self::DEFAULT_TTL) {
            self::clearState($entity);
            return false;
        }

        return $state;
    }

    /**
     * Limpia el estado de recuperación
     * 
     * @param string $entity Nombre de la entidad
     * @return bool Éxito de la operación
     */
    public static function clearState(string $entity): bool
    {
        if (empty($entity)) {
            return false;
        }

        $logger = new \MiIntegracionApi\Helpers\Logger('sync-recovery');
        $key = self::getRecoveryKey($entity);
        
        $logger->info(
            "Limpiando estado de recuperación para {$entity}",
            ['category' => "sync-recovery-{$entity}"]
        );

        return delete_transient($key);
    }

    /**
     * Verifica si existe un punto de recuperación
     * 
     * @param string $entity Nombre de la entidad
     * @return bool True si existe un punto de recuperación
     */
    public static function hasRecoveryPoint(string $entity): bool
    {
        return self::getState($entity) !== false;
    }

    /**
     * Obtiene el mensaje de recuperación
     * 
     * @param string $entity Nombre de la entidad
     * @return string Mensaje de recuperación
     */
    public static function getRecoveryMessage(string $entity): string
    {
        $state = self::getState($entity);
        
        if ($state === false) {
            return '';
        }

        $lastBatch = $state['last_batch'] ?? 0;
        $processed = $state['processed'] ?? $state['processed_items'] ?? 0;
        $total = $state['total'] ?? $state['total_items'] ?? 0;
        $timestamp = $state['timestamp'] ?? 0;

        return sprintf(
            'Se encontró un punto de recuperación del %s. Último lote procesado: %d. Elementos procesados: %d/%d.',
            date('Y-m-d H:i:s', $timestamp),
            $lastBatch,
            $processed,
            $total
        );
    }

    /**
     * Obtiene la clave de recuperación
     * 
     * @param string $entity Nombre de la entidad
     * @return string Clave de recuperación
     */
    private static function getRecoveryKey(string $entity): string
    {
        return self::RECOVERY_PREFIX . sanitize_key($entity);
    }

    /**
     * Limpia todos los estados de recuperación
     * 
     * @return int Número de estados eliminados
     */
    public static function clearAllStates(): int
    {
        $entities = ['products', 'clients', 'orders'];
        $cleared = 0;
        
        foreach ($entities as $entity) {
            if (self::clearState($entity)) {
                $cleared++;
            }
        }
        
        if ($cleared > 0) {
            $logger = new \MiIntegracionApi\Helpers\Logger('sync-recovery');
            $logger->info("Limpieza global: {$cleared} recovery states eliminados", [
                'entities_cleared' => $cleared,
                'category' => 'sync-recovery-cleanup'
            ]);
        }
        
        return $cleared;
    }

    /**
     * Verifica si puede reanudar la sincronización
     * 
     * @param string $entity Nombre de la entidad
     * @param array $current_filters Filtros actuales
     * @return array|false Estado de recuperación o false si no puede reanudar
     */
    public static function canResumeSync(string $entity, array $current_filters = []): array|false
    {
        $state = self::getState($entity);
        
        if (!$state || empty($state['filters']) || empty($state['last_batch'])) {
            return false;
        }
        
        // Verificar compatibilidad de filtros si se proporcionan
        if (!empty($current_filters)) {
            // Verificar filtros fundamentales (fecha, categoría, etc.)
            $critical_filters = ['fecha', 'categoria', 'marca'];
            foreach ($critical_filters as $filter) {
                if (isset($current_filters[$filter]) && isset($state['filters'][$filter]) &&
                    $current_filters[$filter] !== $state['filters'][$filter]) {
                    return false;
                }
            }
        }
        
        return $state;
    }

    /**
     * Calcula el progreso de la sincronización basado en el estado de recuperación
     * 
     * @param string $entity Nombre de la entidad
     * @return array Información de progreso
     */
    public static function getRecoveryProgress(string $entity): array
    {
        $state = self::getState($entity);
        
        if (!$state) {
            return [
                'exists' => false,
                'percentage' => 0,
                'processed' => 0,
                'total' => 0,
                'date' => null,
            ];
        }
        
        // COMPATIBILIDAD CON AMBAS CLAVES
        $total = $state['total'] ?? $state['total_items'] ?? 0;
        $processed = $state['processed'] ?? $state['processed_items'] ?? 0;
        $percentage = $total > 0 ? round(($processed / $total) * 100, 1) : 0;
        
        return [
            'exists' => true,
            'percentage' => $percentage,
            'processed' => $processed,
            'total' => $total,
            'date' => $state['timestamp'] ?? null,
            'last_batch' => $state['last_batch'] ?? 0,
            'errors' => $state['errors'] ?? 0,
        ];
    }

    /**
     * MÉTODOS DE COMPATIBILIDAD - ALIAS PARA MANTENER COMPATIBILIDAD
     */

    /**
     * Alias para saveState - compatibilidad con Sync_Manager
     */
    public static function saveRecoveryState(string $entity, array $state): bool
    {
        return self::saveState($entity, $state);
    }

    /**
     * Alias para getState - compatibilidad con Sync_Manager
     */
    public static function getRecoveryState(string $entity): array|false
    {
        return self::getState($entity);
    }

    /**
     * Alias para clearState - compatibilidad con Sync_Manager
     */
    public static function clearRecoveryState(string $entity): bool
    {
        return self::clearState($entity);
    }

    /**
     * Alias para hasRecoveryPoint - compatibilidad con Sync_Manager
     */
    public static function hasRecoveryState(string $entity): bool
    {
        return self::hasRecoveryPoint($entity);
    }
} 