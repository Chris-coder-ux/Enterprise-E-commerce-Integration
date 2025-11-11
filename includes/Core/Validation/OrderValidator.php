<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core\Validation;

/**
 * Validador para pedidos
 */
class OrderValidator extends SyncValidator
{
    private const REQUIRED_FIELDS = [
        'customer_id',
        'status',
        'billing',
        'shipping',
        'line_items'
    ];

    private const FIELD_TYPES = [
        'customer_id' => 'int',
        'status' => 'string',
        'billing' => 'array',
        'shipping' => 'array',
        'line_items' => 'array',
        'payment_method' => 'string',
        'payment_method_title' => 'string',
        'shipping_method' => 'string',
        'shipping_total' => 'float',
        'total' => 'float',
        'meta_data' => 'array'
    ];

    private const STATUS_VALUES = [
        'pending',
        'processing',
        'on-hold',
        'completed',
        'cancelled',
        'refunded',
        'failed'
    ];

    private const REQUIRED_BILLING_FIELDS = [
        'first_name',
        'last_name',
        'address_1',
        'city',
        'state',
        'postcode',
        'country',
        'email',
        'phone'
    ];

    private const REQUIRED_SHIPPING_FIELDS = [
        'first_name',
        'last_name',
        'address_1',
        'city',
        'state',
        'postcode',
        'country'
    ];

    /**
     * Valida la estructura básica de los datos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateStructure(array $data): void
    {

        // Validar que no haya campos desconocidos
        $allowedFields = array_merge(
            array_keys(self::FIELD_TYPES),
            ['meta_data', 'tax_data', 'coupon_lines', 'fee_lines']
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
        // Validar campos requeridos principales
        $this->validateRequiredFieldsList($data, self::REQUIRED_FIELDS);

        // Validar campos requeridos de facturación
        $this->validateNestedRequiredFields($data, 'billing', self::REQUIRED_BILLING_FIELDS);

        // Validar campos requeridos de envío
        $this->validateNestedRequiredFields($data, 'shipping', self::REQUIRED_SHIPPING_FIELDS);
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
        // Validar estado
        if (isset($data['status']) && !in_array($data['status'], self::STATUS_VALUES)) {
            $this->addError(
                'status',
                "Estado no válido",
                ['value' => $data['status'], 'allowed' => self::STATUS_VALUES]
            );
        }

        // Validar email y teléfono de facturación
        $this->validateNestedEmail($data, 'billing');
        $this->validateNestedPhone($data, 'billing');

        // Validar items del pedido
        if (isset($data['line_items']) && is_array($data['line_items'])) {
            foreach ($data['line_items'] as $index => $item) {
                if (!is_array($item)) {
                    $this->addError(
                        "line_items.$index",
                        "Item inválido",
                        ['value' => $item]
                    );
                    continue;
                }

                if (!isset($item['product_id']) || !isset($item['quantity'])) {
                    $this->addError(
                        "line_items.$index",
                        "Item incompleto",
                        ['value' => $item]
                    );
                }

                if (isset($item['quantity']) && $item['quantity'] <= 0) {
                    $this->addError(
                        "line_items.$index.quantity",
                        "La cantidad debe ser mayor que 0",
                        ['value' => $item['quantity']]
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
        // Validar que haya al menos un item en el pedido
        if (isset($data['line_items']) && empty($data['line_items'])) {
            $this->addError(
                'line_items',
                "El pedido debe tener al menos un item"
            );
        }

        // Validar que el total coincida con la suma de los items
        if (isset($data['total']) && isset($data['line_items'])) {
            $calculatedTotal = 0;
            foreach ($data['line_items'] as $item) {
                if (isset($item['total'])) {
                    $calculatedTotal += (float)$item['total'];
                }
            }

            if (isset($data['shipping_total'])) {
                $calculatedTotal += (float)$data['shipping_total'];
            }

            if (abs($calculatedTotal - (float)$data['total']) > 0.01) {
                $this->addError(
                    'total',
                    "El total no coincide con la suma de los items",
                    [
                        'calculated' => $calculatedTotal,
                        'provided' => $data['total']
                    ]
                );
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
        // Validar longitud de campos de dirección
        $this->validateAddressFieldsLimits($data);

        // Validar rangos numéricos
        if (isset($data['total'])) {
            $this->validateRange($data['total'], 0, 999999.99, 'total');
        }

        if (isset($data['shipping_total'])) {
            $this->validateRange($data['shipping_total'], 0, 999999.99, 'shipping_total');
        }
    }
} 