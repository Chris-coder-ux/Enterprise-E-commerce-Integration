<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core\Validation;

/**
 * Implementación concreta de SyncValidator para poder instanciarla
 * 
 * Esta clase extiende SyncValidator abstracto y proporciona implementaciones
 * concretas de todos los métodos abstractos.
 * 
 * @package MiIntegracionApi\Core\Validation
 * @since 2.0.0
 */
class ConcreteSyncValidator extends SyncValidator
{
    /**
     * Implementación concreta del método abstracto validateStructure
     */
    protected function validateStructure(array $data): void
    {
        // Validación básica de estructura
        if (empty($data)) {
            $this->errors[] = 'Los datos no pueden estar vacíos';
        }
    }

    /**
     * Implementación concreta del método abstracto validateRequiredFields
     */
    protected function validateRequiredFields(array $data): void
    {
        // Validación básica de campos requeridos
        // Se puede personalizar según necesidades específicas
    }

    /**
     * Implementación concreta del método abstracto validateDataTypes
     */
    protected function validateDataTypes(array $data): void
    {
        // Validación básica de tipos de datos
        // Se puede personalizar según necesidades específicas
    }

    /**
     * Implementación concreta del método abstracto validateSpecificRules
     */
    protected function validateSpecificRules(array $data): void
    {
        // Validación de reglas específicas
        // Se puede personalizar según necesidades específicas
    }

    /**
     * Implementación concreta del método abstracto validateRelationships
     */
    protected function validateRelationships(array $data): void
    {
        // Validación de relaciones
        // Se puede personalizar según necesidades específicas
    }

    /**
     * Implementación concreta del método abstracto validateLimits
     */
    protected function validateLimits(array $data): void
    {
        // Validación de límites
        // Se puede personalizar según necesidades específicas
    }

    /**
     * Implementación concreta del método abstracto processWarnings
     */
    protected function processWarnings(): void
    {
        // Procesamiento de advertencias
        // Se puede personalizar según necesidades específicas
    }
}
