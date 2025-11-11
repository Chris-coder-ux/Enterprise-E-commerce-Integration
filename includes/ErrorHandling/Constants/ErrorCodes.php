<?php

declare(strict_types=1);

namespace MiIntegracionApi\ErrorHandling\Constants;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

use ReflectionClass;

/**
 * Constantes centralizadas para códigos de error del sistema
 * 
 * Esta clase centraliza todos los códigos de error utilizados en el sistema,
 * proporcionando una referencia única y consistente para el manejo de errores.
 * 
 * @package MiIntegracionApi\ErrorHandling\Constants
 * @since 1.0.0
 */
class ErrorCodes
{
    // ===================================================================
    // CÓDIGOS DE ERROR DE SINCRONIZACIÓN
    // ===================================================================

    /**
     * Error de validación de datos
     */
    public const VALIDATION_ERROR = 'validation_error';

    /**
     * Error de API externa
     */
    public const API_ERROR = 'api_error';

    /**
     * Error de concurrencia (locks, etc.)
     */
    public const CONCURRENCY_ERROR = 'concurrency_error';

    /**
     * Error de memoria insuficiente
     */
    public const MEMORY_ERROR = 'memory_error';

    /**
     * Error de red/conectividad
     */
    public const NETWORK_ERROR = 'network_error';

    /**
     * Error de timeout
     */
    public const TIMEOUT_ERROR = 'timeout_error';

    /**
     * Error reintentable
     */
    public const RETRYABLE_ERROR = 'retryable_error';

    // ===================================================================
    // CÓDIGOS DE ERROR DE VALIDACIÓN
    // ===================================================================

    /**
     * Campo requerido faltante
     */
    public const REQUIRED_FIELD_MISSING = 'required_field_missing';

    /**
     * Formato de campo inválido
     */
    public const INVALID_FIELD_FORMAT = 'invalid_field_format';

    /**
     * Valor fuera de rango permitido
     */
    public const VALUE_OUT_OF_RANGE = 'value_out_of_range';

    /**
     * Longitud de campo excedida
     */
    public const FIELD_LENGTH_EXCEEDED = 'field_length_exceeded';

    /**
     * Email inválido
     */
    public const INVALID_EMAIL = 'invalid_email';

    /**
     * Teléfono inválido
     */
    public const INVALID_PHONE = 'invalid_phone';

    /**
     * Fecha inválida
     */
    public const INVALID_DATE = 'invalid_date';

    /**
     * URL inválida
     */
    public const INVALID_URL = 'invalid_url';

    // ===================================================================
    // CÓDIGOS DE ERROR DE API
    // ===================================================================

    /**
     * Error de autenticación
     */
    public const AUTHENTICATION_ERROR = 'authentication_error';

    /**
     * Error de autorización
     */
    public const AUTHORIZATION_ERROR = 'authorization_error';

    /**
     * Endpoint no encontrado
     */
    public const ENDPOINT_NOT_FOUND = 'endpoint_not_found';

    /**
     * Método HTTP no permitido
     */
    public const METHOD_NOT_ALLOWED = 'method_not_allowed';

    /**
     * Rate limit excedido
     */
    public const RATE_LIMIT_EXCEEDED = 'rate_limit_exceeded';

    /**
     * Servicio no disponible
     */
    public const SERVICE_UNAVAILABLE = 'service_unavailable';

    /**
     * Error interno del servidor
     */
    public const INTERNAL_SERVER_ERROR = 'internal_server_error';

    // ===================================================================
    // CÓDIGOS DE ERROR DE CONCURRENCIA
    // ===================================================================

    /**
     * Lock ya adquirido
     */
    public const LOCK_ALREADY_ACQUIRED = 'lock_already_acquired';

    /**
     * Lock expirado
     */
    public const LOCK_EXPIRED = 'lock_expired';

    /**
     * Lock no encontrado
     */
    public const LOCK_NOT_FOUND = 'lock_not_found';

    /**
     * Operación en progreso
     */
    public const OPERATION_IN_PROGRESS = 'operation_in_progress';

    // ===================================================================
    // CÓDIGOS DE ERROR DE DATOS
    // ===================================================================

    /**
     * Entidad no encontrada
     */
    public const ENTITY_NOT_FOUND = 'entity_not_found';

    /**
     * Entidad duplicada
     */
    public const ENTITY_DUPLICATED = 'entity_duplicated';

    /**
     * Entidad no válida
     */
    public const ENTITY_INVALID = 'entity_invalid';

    /**
     * Relación no encontrada
     */
    public const RELATIONSHIP_NOT_FOUND = 'relationship_not_found';

    /**
     * Relación inválida
     */
    public const RELATIONSHIP_INVALID = 'relationship_invalid';

    // ===================================================================
    // CÓDIGOS DE ERROR DE CONFIGURACIÓN
    // ===================================================================

    /**
     * Configuración faltante
     */
    public const CONFIGURATION_MISSING = 'configuration_missing';

    /**
     * Configuración inválida
     */
    public const CONFIGURATION_INVALID = 'configuration_invalid';

    /**
     * Permisos insuficientes
     */
    public const INSUFFICIENT_PERMISSIONS = 'insufficient_permissions';

    /**
     * Recurso no disponible
     */
    public const RESOURCE_UNAVAILABLE = 'resource_unavailable';

    // ===================================================================
    // MÉTODOS DE UTILIDAD
    // ===================================================================

    /**
     * Obtiene todos los códigos de error disponibles
     * 
     * @return array Array con todos los códigos de error
     */
    public static function getAllCodes(): array
    {
        $reflection = new ReflectionClass(self::class);
        return array_values($reflection->getConstants());
    }

    /**
     * Obtiene códigos de error por categoría
     * 
     * @param string $category Categoría de error (validation, api, concurrency, etc.)
     * @return array Array con códigos de la categoría
     */
    public static function getCodesByCategory(string $category): array
    {
        $reflection = new ReflectionClass(self::class);
        $constants = $reflection->getConstants();
        
        $categoryPrefix = strtoupper($category) . '_';
        $codes = [];
        
        foreach ($constants as $name => $value) {
            if (str_starts_with($name, $categoryPrefix)) {
                $codes[] = $value;
            }
        }
        
        return $codes;
    }

    /**
     * Verifica si un código de error es válido
     * 
     * @param string $code Código a verificar
     * @return bool True si es válido, false en caso contrario
     */
    public static function isValidCode(string $code): bool
    {
        $reflection = new ReflectionClass(self::class);
        return in_array($code, $reflection->getConstants(), true);
    }

    /**
     * Obtiene la descripción de un código de error
     * 
     * @param string $code Código de error
     * @return string Descripción del error
     */
    public static function getDescription(string $code): string
    {
        $descriptions = [
            self::VALIDATION_ERROR => 'Error de validación de datos',
            self::API_ERROR => 'Error de API externa',
            self::CONCURRENCY_ERROR => 'Error de concurrencia',
            self::MEMORY_ERROR => 'Error de memoria insuficiente',
            self::NETWORK_ERROR => 'Error de red/conectividad',
            self::TIMEOUT_ERROR => 'Error de timeout',
            self::RETRYABLE_ERROR => 'Error reintentable',
            self::REQUIRED_FIELD_MISSING => 'Campo requerido faltante',
            self::INVALID_FIELD_FORMAT => 'Formato de campo inválido',
            self::VALUE_OUT_OF_RANGE => 'Valor fuera de rango permitido',
            self::FIELD_LENGTH_EXCEEDED => 'Longitud de campo excedida',
            self::INVALID_EMAIL => 'Email inválido',
            self::INVALID_PHONE => 'Teléfono inválido',
            self::INVALID_DATE => 'Fecha inválida',
            self::INVALID_URL => 'URL inválida',
            self::AUTHENTICATION_ERROR => 'Error de autenticación',
            self::AUTHORIZATION_ERROR => 'Error de autorización',
            self::ENDPOINT_NOT_FOUND => 'Endpoint no encontrado',
            self::METHOD_NOT_ALLOWED => 'Método HTTP no permitido',
            self::RATE_LIMIT_EXCEEDED => 'Rate limit excedido',
            self::SERVICE_UNAVAILABLE => 'Servicio no disponible',
            self::INTERNAL_SERVER_ERROR => 'Error interno del servidor',
            self::LOCK_ALREADY_ACQUIRED => 'Lock ya adquirido',
            self::LOCK_EXPIRED => 'Lock expirado',
            self::LOCK_NOT_FOUND => 'Lock no encontrado',
            self::OPERATION_IN_PROGRESS => 'Operación en progreso',
            self::ENTITY_NOT_FOUND => 'Entidad no encontrada',
            self::ENTITY_DUPLICATED => 'Entidad duplicada',
            self::ENTITY_INVALID => 'Entidad no válida',
            self::RELATIONSHIP_NOT_FOUND => 'Relación no encontrada',
            self::RELATIONSHIP_INVALID => 'Relación inválida',
            self::CONFIGURATION_MISSING => 'Configuración faltante',
            self::CONFIGURATION_INVALID => 'Configuración inválida',
            self::INSUFFICIENT_PERMISSIONS => 'Permisos insuficientes',
            self::RESOURCE_UNAVAILABLE => 'Recurso no disponible',
        ];

        return $descriptions[$code] ?? 'Error desconocido';
    }
}
