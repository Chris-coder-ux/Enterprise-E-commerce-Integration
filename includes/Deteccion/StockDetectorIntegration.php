<?php
declare(strict_types=1);

namespace MiIntegracionApi\Deteccion;

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Core\Sync_Manager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Integración del StockDetector con el sistema existente
 * 
 * Este archivo se encarga de:
 * 1. Inicializar el StockDetector con las dependencias correctas
 * 2. Proporcionar métodos de control desde el admin
 * 3. Integrar con el sistema de hooks existente
 * 
 * @package MiIntegracionApi\Deteccion
 * @since 2.0.0
 */
class StockDetectorIntegration
{
    private static ?StockDetector $detector = null;
    
    /**
     * Inicializa el StockDetector
     */
    public static function init(): void
    {
        // Solo inicializar si las clases necesarias están disponibles
        if (!class_exists('MiIntegracionApi\\Core\\ApiConnector') || 
            !class_exists('MiIntegracionApi\\Core\\Sync_Manager')) {
            return;
        }
        
        // Obtener instancias existentes
        $api_connector = ApiConnector::getInstance();
        $sync_manager = Sync_Manager::getInstance();
        
        if (!$api_connector || !$sync_manager) {
            return;
        }
        
        // Crear instancia del detector
        self::$detector = new StockDetector($api_connector, $sync_manager);
        
        // Registrar hooks de WordPress
        add_action('wp_ajax_mia_toggle_stock_detection', [self::class, 'handleToggleRequest']);
        add_action('wp_ajax_mia_get_stock_detection_status', [self::class, 'handleStatusRequest']);
        add_action('wp_ajax_mia_execute_manual_detection', [self::class, 'handleManualDetectionRequest']);
        
        // Registrar intervalos de cron personalizados
        add_filter('cron_schedules', [self::class, 'add_cron_intervals']);
        
        // Programar cron job de detección automática
        add_action('mia_automatic_stock_detection', [self::class, 'execute_detection_callback']);
    }
    
    /**
     * Obtiene la instancia del detector
     */
    public static function getDetector(): ?StockDetector
    {
        return self::$detector;
    }
    
    /**
     * Activa la detección automática
     */
    public static function activate(): bool
    {
        if (!self::$detector) {
            return false;
        }
        
        return self::$detector->activate();
    }
    
    /**
     * Desactiva la detección automática
     */
    public static function deactivate(): bool
    {
        if (!self::$detector) {
            return false;
        }
        
        return self::$detector->deactivate();
    }
    
    /**
     * Obtiene el estado de la detección
     */
    public static function getStatus(): array
    {
        if (!self::$detector) {
            return [
                'enabled' => false,
                'error' => 'Detector no inicializado'
            ];
        }
        
        return self::$detector->getStatus();
    }
    
    /**
     * Obtiene el estado de la detección (alias para compatibilidad)
     */
    public static function getDetectionStatus(): array
    {
        return self::getStatus();
    }
    
    /**
     * Maneja peticiones AJAX para activar/desactivar
     */
    public static function handleToggleRequest(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_stock_detection_toggle')) {
            wp_die('Nonce inválido');
        }
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        $action = $_POST['action_type'] ?? '';
        $result = false;
        
        switch ($action) {
            case 'activate':
                $result = self::activate();
                break;
            case 'deactivate':
                $result = self::deactivate();
                break;
        }
        
        wp_send_json([
            'success' => $result,
            'message' => $result ? 'Operación exitosa' : 'Error en la operación',
            'status' => self::getStatus()
        ]);
    }
    
    /**
     * Maneja peticiones AJAX para obtener estado
     */
    public static function handleStatusRequest(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_GET['nonce'] ?? '', 'mia_stock_detection_status')) {
            wp_die('Nonce inválido');
        }
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        wp_send_json([
            'success' => true,
            'status' => self::getStatus()
        ]);
    }
    
    /**
     * Maneja peticiones AJAX para ejecución manual
     */
    public static function handleManualDetectionRequest(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mia_manual_detection')) {
            wp_die('Nonce inválido');
        }
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        if (!self::$detector) {
            wp_send_json([
                'success' => false,
                'message' => 'Detector no inicializado'
            ]);
        }
        
        $result = self::$detector->executeManualDetection();
        wp_send_json($result);
    }
    
    /**
     * Limpia recursos al desactivar el plugin
     */
    public static function cleanup(): void
    {
        if (self::$detector) {
            self::$detector->deactivate();
        }
    }
    
    /**
     * Agrega intervalos de cron personalizados
     */
    public static function add_cron_intervals($schedules): array
    {
        $schedules['mia_detection_interval'] = [
            'interval' => 300, // 5 minutos
            'display' => __('Cada 5 minutos (Detección Automática)', 'mi-integracion-api')
        ];
        
        return $schedules;
    }
    
    /**
     * Callback para ejecutar la detección automática
     */
    public static function execute_detection_callback(): void
    {
        if (self::$detector) {
            self::$detector->execute_detection();
        }
    }
    
    /**
     * Programa el cron job de detección automática
     */
    public static function schedule_detection(): bool
    {
        if (!wp_next_scheduled('mia_automatic_stock_detection')) {
            return wp_schedule_event(time(), 'mia_detection_interval', 'mia_automatic_stock_detection');
        }
        return true;
    }
    
    /**
     * Desprograma el cron job de detección automática
     */
    public static function unschedule_detection(): bool
    {
        $timestamp = wp_next_scheduled('mia_automatic_stock_detection');
        if ($timestamp) {
            return wp_unschedule_event($timestamp, 'mia_automatic_stock_detection');
        }
        return true;
    }
}
