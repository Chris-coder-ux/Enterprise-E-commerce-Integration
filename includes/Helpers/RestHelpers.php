<?php declare(strict_types=1);
/**
 * Funciones de ayuda para la gestión de respuestas y autorización en la REST API de WordPress.
 *
 * Proporciona utilidades para devolver códigos de estado HTTP apropiados según el contexto de autorización,
 * facilitando la implementación de endpoints seguros y consistentes.
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.0.0
 */

namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase de utilidades para la gestión de respuestas y autorización en la REST API.
 *
 * Incluye métodos estáticos para determinar el código de estado HTTP adecuado en errores de autorización,
 * diferenciando entre usuarios autenticados y no autenticados.
 *
 * @since 1.0.0
 */
class RestHelpers {
    /**
     * Devuelve el código de estado HTTP apropiado para errores de autorización en la REST API.
     *
     * Si el usuario está autenticado pero no tiene permisos suficientes, devuelve 403 (Forbidden).
     * Si el usuario no está autenticado, devuelve 401 (Unauthorized).
     *
     * @return int Código de estado HTTP (401 o 403) según el contexto de autenticación.
     */
    public static function rest_authorization_required_code(): int {
        return is_user_logged_in() ? 403 : 401;
    }
}
