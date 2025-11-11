<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core\Validation;

/**
 * Validador para productos
 *
 * Esta clase se encarga de validar la estructura y contenido de los datos de productos
 * antes de ser procesados o almacenados. Extiende de SyncValidator para heredar
 * funcionalidad común de validación.
 *
 * Las validaciones incluyen:
 * - Estructura básica de datos
 * - Campos requeridos
 * - Tipos de datos
 * - Reglas específicas (formato SKU, valores de estado, etc.)
 * - Relaciones entre datos
 * - Límites y restricciones de campos
 *
 * @package MiIntegracionApi\Core\Validation
 * @version 1.0.0
 * @see SyncValidator
 */
class ProductValidator extends SyncValidator
{
    private const REQUIRED_FIELDS = [
        'sku',
        'name',
        'price',
        'stock'
    ];

    private const FIELD_TYPES = [
        'sku' => 'string',
        'name' => 'string',
        'description' => 'string',
        'price' => 'float',
        'stock' => 'int',
        'categories' => 'array',
        'images' => 'array',
        'attributes' => 'array',
        'status' => 'string'
    ];

    private const FIELD_LIMITS = [
        'name' => ['min' => 3, 'max' => 255],
        'description' => ['min' => 0, 'max' => 10000],
        'price' => ['min' => 0, 'max' => 999999.99],
        'stock' => ['min' => 0, 'max' => 999999]
    ];

    private const SKU_PATTERN = '/^[A-Z0-9-_]+$/';
    private const STATUS_VALUES = ['publish', 'draft', 'private'];

    /**
     * Valida la estructura básica de los datos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateStructure(array $data): void {

        // Validar que no haya campos desconocidos
        $allowedFields = array_merge(
            array_keys(self::FIELD_TYPES),
            ['meta_data', 'tax_data']
        );

        foreach ($data as $field => $value) {
            if (!in_array($field, $allowedFields)) {
                $this->addWarning(
                    $field,
                    "Campo desconocido",
                    ['value' => $value]
                );
            }
        }
    }

    /**
     * Valida los campos requeridos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateRequiredFields(array $data): void
    {
        $this->validateRequiredFieldsList($data, self::REQUIRED_FIELDS);
    }

    /**
     * Valida los tipos de datos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateDataTypes(array $data): void
    {
        foreach (self::FIELD_TYPES as $field => $type) {
            if (isset($data[$field])) {
                $this->validateType($data[$field], $type, $field);
            }
        }
    }

    /**
     * Valida reglas específicas
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateSpecificRules(array $data): void
    {
        // Validar SKU
        if (isset($data['sku'])) {
            $this->validatePattern($data['sku'], self::SKU_PATTERN, 'sku');
        }

        // Validar estado
        if (isset($data['status']) && !in_array($data['status'], self::STATUS_VALUES)) {
            $this->addError(
                'status',
                "Estado no válido",
                ['value' => $data['status'], 'allowed' => self::STATUS_VALUES]
            );
        }

        // Validar categorías
        if (isset($data['categories']) && is_array($data['categories'])) {
            foreach ($data['categories'] as $index => $category) {
                if (!is_array($category) || !isset($category['id'])) {
                    $this->addError(
                        "categories.$index",
                        "Categoría inválida",
                        ['value' => $category]
                    );
                }
            }
        }

        // Validar imágenes
        if (isset($data['images']) && is_array($data['images'])) {
            foreach ($data['images'] as $index => $image) {
                if (!is_array($image) || !isset($image['src'])) {
                    $this->addError(
                        "images.$index",
                        "Imagen inválida",
                        ['value' => $image]
                    );
                }
            }
        }
    }

    /**
     * Valida relaciones entre datos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateRelationships(array $data): void
    {
        // Validar que el producto tenga al menos una categoría
        if (isset($data['categories']) && empty($data['categories'])) {
            $this->addWarning(
                'categories',
                "El producto no tiene categorías asignadas"
            );
        }

        // Validar que el producto tenga al menos una imagen
        if (isset($data['images']) && empty($data['images'])) {
            $this->addWarning(
                'images',
                "El producto no tiene imágenes"
            );
        }

        // Validar precio usando el validador unificado
        if (isset($data['price'])) {
            if (!\MiIntegracionApi\Core\InputValidation::validate_precio($data['price'], [
                'status' => $data['status'] ?? 'draft',
                'product_type' => 'normal'
            ])) {
                $errors = \MiIntegracionApi\Core\InputValidation::get_errors();
                foreach ($errors as $error) {
                    $this->addError('price', $error);
                }
            }
        }
    }

    /**
     * Valida límites y restricciones
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateLimits(array $data): void
    {
        foreach (self::FIELD_LIMITS as $field => $limits) {
            if (isset($data[$field])) {
                if (is_string($data[$field])) {
                    $this->validateRange(
                        strlen($data[$field]),
                        $limits['min'],
                        $limits['max'],
                        $field
                    );
                } else {
                    $this->validateRange(
                        $data[$field],
                        $limits['min'],
                        $limits['max'],
                        $field
                    );
                }
            }
        }
    }
} 