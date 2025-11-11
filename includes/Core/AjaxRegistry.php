<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

/**
 * Registro centralizado de endpoints AJAX
 *
 * Esta clase centraliza el registro de todos los endpoints AJAX del plugin,
 * eliminando la dispersión de responsabilidades y violaciones del principio SRP.
 * 
 * Responsabilidades:
 * - Registrar todos los endpoints AJAX del plugin
 * - Organizar endpoints por categorías funcionales
 * - Proporcionar métodos específicos para cada categoría
 * - Mantener un registro centralizado y organizado
 *
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

class AjaxRegistry
{
    /**
     * Registra todos los endpoints AJAX del plugin
     * 
     * @return void
     * @since 1.0.0
     */
    public static function register_all_endpoints(): void
    {
        self::register_sync_endpoints();
        self::register_dashboard_endpoints();
        self::register_api_endpoints();
        self::register_monitoring_endpoints();
        self::register_diagnostic_endpoints();
    }

    /**
     * Registra endpoints de sincronización
     * 
     * @return void
     * @since 1.0.0
     */
    public static function register_sync_endpoints(): void
    {
        // TODO: Implementar registro de endpoints de sincronización
    }

    /**
     * Registra endpoints del dashboard
     * 
     * @return void
     * @since 1.0.0
     */
    public static function register_dashboard_endpoints(): void
    {
        // TODO: Implementar registro de endpoints del dashboard
    }

    /**
     * Registra endpoints de API y utilidades
     * 
     * @return void
     * @since 1.0.0
     */
    public static function register_api_endpoints(): void
    {
        // TODO: Implementar registro de endpoints de API
    }

    /**
     * Registra endpoints de monitoreo y cache
     * 
     * @return void
     * @since 1.0.0
     */
    public static function register_monitoring_endpoints(): void
    {
        // TODO: Implementar registro de endpoints de monitoreo
    }

    /**
     * Registra endpoints de diagnóstico y migración
     * 
     * @return void
     * @since 1.0.0
     */
    public static function register_diagnostic_endpoints(): void
    {
        // TODO: Implementar registro de endpoints de diagnóstico
    }
}
