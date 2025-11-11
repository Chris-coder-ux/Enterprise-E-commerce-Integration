<?php

declare(strict_types=1);

namespace MiIntegracionApi\Diagnostics;

/**
 * Diagnóstico automático de problemas AJAX
 * 
 * Esta clase se encarga de detectar, registrar y diagnosticar problemas
 * que ocurren durante las peticiones AJAX, especialmente errores HTTP 400.
 * Proporciona funcionalidades de logging, auto-reparación y debugging
 * para mejorar la estabilidad del sistema.
 * 
 * @package MiIntegracionApi\Diagnostics
 * @since 1.0.0
 * @author Christian
 */
class AjaxDiagnostic {
    
    /**
     * Ruta al archivo de log de diagnóstico
     * 
     * @var string|null
     * @since 1.0.0
     */
    private static $logFile = null;
    
    /**
     * Inicializa el sistema de diagnóstico AJAX
     * 
     * Configura el directorio de logs, crea el archivo de log diario
     * y registra los hooks necesarios para capturar errores AJAX.
     * 
     * @return void
     * @since 1.0.0
     */
    public static function init(): void {
        // Crear directorio de logs si no existe
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/mi-integracion-api/logs/';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        self::$logFile = $log_dir . 'ajax-diagnostic-' . date('Y-m-d') . '.log';
        
        // Añadir hooks para capturar errores
        add_action('wp_ajax_mi_integracion_api_reload_metrics', [self::class, 'preAjaxCheck'], 1);
        add_action('wp_ajax_nopriv_mi_integracion_api_reload_metrics', [self::class, 'handleNoprivError']);
    }
    
    /**
     * Verificación previa a la ejecución de AJAX
     * Delega la verificación de seguridad en AjaxDashboard para evitar
     * duplicidad de código y mantener consistencia en las validaciones.
     * @return void
     * @since 1.0.0
     */
    public static function preAjaxCheck(): void {
        // Delegar en AjaxDashboard para evitar duplicidad
        \MiIntegracionApi\Admin\AjaxDashboard::preAjaxCheck();
    }
    
    
    
    /**
     * Maneja errores cuando se llama AJAX sin privilegios de usuario
     * Se ejecuta cuando un usuario no autenticado intenta acceder
     * a endpoints AJAX que requieren autenticación. Registra el error
     * y devuelve una respuesta JSON apropiada.
     * @return void
     * @since 1.0.0
     */
    public static function handleNoprivError(): void {
        self::log("ERROR: AJAX called without privileges");
        self::log("This suggests the user is not logged in or session expired");
        
        wp_send_json_error([
            'message' => 'Session expired. Please refresh the page and try again.',
            'code' => 'session_expired',
            'diagnostic' => 'User not authenticated'
        ], 401);
    }
    
    /**
     * Función de auto-reparación para problemas comunes
     * 
     * Intenta reparar automáticamente problemas comunes que pueden
     * causar errores AJAX, como handlers no registrados o constantes
     * no definidas.
     * 
     * @return void
     * @since 1.0.0
     */
    public static function autoFixCommonIssues(): void {
        self::log("--- AUTO-FIX ATTEMPT ---");
        
        // 1. Re-registrar handlers si no están
        if (!has_action('wp_ajax_mi_integracion_api_reload_metrics')) {
            add_action('wp_ajax_mi_integracion_api_reload_metrics', ['MiIntegracionApi\Admin\AjaxDashboard', 'reloadMetrics']);
            self::log("Re-registered reloadMetrics handler");
        }
        
        // 2. Verificar que las constantes estén definidas
        if (!defined('MiIntegracionApi_NONCE_PREFIX')) {
            self::log("ERROR: MiIntegracionApi_NONCE_PREFIX not defined");
        }
        
        self::log("--- AUTO-FIX COMPLETE ---");
    }
    
    /**
     * Escribe un mensaje al archivo de log interno
     * 
     * @param string $message Mensaje a registrar en el log
     * @return void
     * @since 1.0.0
     */
    private static function log(string $message): void {
        if (self::$logFile) {
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[$timestamp] $message" . PHP_EOL;
            file_put_contents(self::$logFile, $log_entry, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Obtiene las últimas líneas del log para debugging
     * 
     * @param int $lines Número de líneas a recuperar (por defecto 50)
     * @return array Array con las últimas líneas del log
     * @since 1.0.0
     */
    public static function getRecentLogs(int $lines = 50): array {
        if (!self::$logFile || !file_exists(self::$logFile)) {
            return ['No diagnostic log found'];
        }
        
        $content = file_get_contents(self::$logFile);
        $log_lines = explode(PHP_EOL, $content);
        return array_slice($log_lines, -$lines);
    }
}
