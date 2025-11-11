<?php declare(strict_types=1);

/**
 * Archivo de compatibilidad para LoggerAuditoria.
 *
 * Este archivo mantiene la compatibilidad hacia atrás mientras
 * migramos el sistema de logging a la nueva estructura centralizada.
 *
 * @package MiIntegracionApi\Helpers
 * @deprecated 1.1.0 Use MiIntegracionApi\Logging\Core\Logger::log() instead
 * @since 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Redirigir a la nueva ubicación
require_once __DIR__ . '/../Logging/Core/Logger.php';

/**
 * Clase de compatibilidad para LoggerAuditoria.
 * 
 * @deprecated 1.1.0 Use MiIntegracionApi\Logging\Core\Logger::log() instead
 */
class LoggerAuditoria
{
    /**
     * Método estático para compatibilidad con LoggerAuditoria.
     * 
     * @param string|array|object|null $msg     Mensaje a registrar.
     * @param string                   $level   Nivel del log (info, warning, error, etc.).
     * @param array                    $context Contexto adicional para el mensaje.
     * @return void
     * @deprecated 1.1.0 Use MiIntegracionApi\Logging\Core\Logger::log() instead
     */
    public static function log($msg, $level = 'info', $context = []): void
    {
        // Delegar al nuevo sistema de logging
        \MiIntegracionApi\Logging\Core\Logger::logMessage($msg, $level, $context);
    }
}