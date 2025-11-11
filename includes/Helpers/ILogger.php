<?php declare(strict_types=1);

/**
 * Archivo de compatibilidad para ILogger.
 *
 * Este archivo mantiene la compatibilidad hacia atrás mientras
 * migramos el sistema de logging a la nueva estructura.
 *
 * @package MiIntegracionApi\Helpers
 * @deprecated 1.1.0 Use MiIntegracionApi\Logging\Interfaces\ILogger instead
 * @since 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Redirigir a la nueva ubicación
require_once __DIR__ . '/../Logging/Interfaces/ILogger.php';

// Crear alias para compatibilidad hacia atrás
class_alias(
    \MiIntegracionApi\Logging\Interfaces\ILogger::class,
    \MiIntegracionApi\Helpers\ILogger::class
);