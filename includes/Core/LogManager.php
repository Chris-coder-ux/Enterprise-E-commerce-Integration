<?php declare(strict_types=1);

/**
 * Archivo de compatibilidad para LogManager.
 *
 * Este archivo mantiene la compatibilidad hacia atrás mientras
 * migramos el sistema de logging a la nueva estructura.
 *
 * @package MiIntegracionApi\Core
 * @deprecated 1.1.0 Use MiIntegracionApi\Logging\Core\LogManager instead
 * @since 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Redirigir a la nueva ubicación
require_once __DIR__ . '/../Logging/Core/LogManager.php';

// Crear alias para compatibilidad hacia atrás
class_alias(
    \MiIntegracionApi\Logging\Core\LogManager::class,
    \MiIntegracionApi\Core\LogManager::class
);
