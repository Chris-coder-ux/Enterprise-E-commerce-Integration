<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core\Validation;

/**
 * Validador para clientes
 */
class CustomerValidator extends SyncValidator
{
    private const REQUIRED_FIELDS = [
        'email',
        'first_name',
        'last_name'
    ];

    private const FIELD_TYPES = [
        'email' => 'string',
        'first_name' => 'string',
        'last_name' => 'string',
        'username' => 'string',
        'password' => 'string',
        'billing' => 'array',
        'shipping' => 'array',
        'meta_data' => 'array'
    ];

    private const FIELD_LIMITS = [
        'first_name' => ['min' => 2, 'max' => 50],
        'last_name' => ['min' => 2, 'max' => 50],
        'username' => ['min' => 3, 'max' => 60],
        'password' => ['min' => 8, 'max' => 100]
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
            ['meta_data']
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
        // Validar email
        if (isset($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $this->addError(
                    'email',
                    "Email inválido",
                    ['value' => $data['email']]
                );
            }
        }

        // Validar username
        if (isset($data['username'])) {
            if (!preg_match('/^[a-zA-Z0-9._-]+$/', $data['username'])) {
                $this->addError(
                    'username',
                    "Username inválido",
                    ['value' => $data['username']]
                );
            }
        }

        // Validar password
        if (isset($data['password'])) {
            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $data['password'])) {
                $this->addError(
                    'password',
                    "La contraseña debe contener al menos una letra mayúscula, una minúscula y un número"
                );
            }
        }

        // Validar email y teléfono de facturación
        $this->validateNestedEmail($data, 'billing');
        $this->validateNestedPhone($data, 'billing');
    }

    /**
     * Valida relaciones entre datos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateRelationships(array $data): void
    {
        // Validar que el email de facturación coincida con el email principal
        if (isset($data['email']) && isset($data['billing']['email']) && 
            $data['email'] !== $data['billing']['email']) {
            $this->addError(
                'billing.email',
                "El email de facturación debe coincidir con el email principal",
                [
                    'main_email' => $data['email'],
                    'billing_email' => $data['billing']['email']
                ]
            );
        }

        // Validar que el nombre de facturación coincida con el nombre principal
        if (isset($data['first_name']) && isset($data['billing']['first_name']) && 
            $data['first_name'] !== $data['billing']['first_name']) {
            $this->addWarning(
                'billing.first_name',
                "El nombre de facturación no coincide con el nombre principal",
                [
                    'main_name' => $data['first_name'],
                    'billing_name' => $data['billing']['first_name']
                ]
            );
        }

        if (isset($data['last_name']) && isset($data['billing']['last_name']) && 
            $data['last_name'] !== $data['billing']['last_name']) {
            $this->addWarning(
                'billing.last_name',
                "El apellido de facturación no coincide con el apellido principal",
                [
                    'main_lastname' => $data['last_name'],
                    'billing_lastname' => $data['billing']['last_name']
                ]
            );
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
        // Validar longitud de campos de texto
        foreach (self::FIELD_LIMITS as $field => $limits) {
            if (isset($data[$field])) {
                $this->validateRange(
                    strlen($data[$field]),
                    $limits['min'],
                    $limits['max'],
                    $field
                );
            }
        }

        // Validar longitud de campos de dirección
        $this->validateAddressFieldsLimits($data);
    }
} 