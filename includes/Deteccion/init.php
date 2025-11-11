<?php
declare(strict_types=1);

/**
 * Inicialización del sistema de detección automática de stock
 * 
 * Este archivo se encarga de inicializar el StockDetector
 * cuando el plugin está cargado y las dependencias están disponibles.
 * 
 * @package MiIntegracionApi\Deteccion
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Inicializar el sistema de detección automática
add_action('init', function() {
    // Verificar que las clases necesarias están disponibles
    if (class_exists('MiIntegracionApi\\Deteccion\\StockDetectorIntegration')) {
        \MiIntegracionApi\Deteccion\StockDetectorIntegration::init();
    }
    
    // Inicializar el notificador de productos de WooCommerce
    if (class_exists('MiIntegracionApi\\Deteccion\\WooCommerceProductNotifier')) {
        // Obtener instancia del ApiConnector si está disponible
        $api_connector = null;
        if (class_exists('MiIntegracionApi\\Core\\ApiConnector')) {
            $api_connector = \MiIntegracionApi\Core\ApiConnector::getInstance();
        }
        
        // Crear instancia del notificador
        new \MiIntegracionApi\Deteccion\WooCommerceProductNotifier($api_connector);
    }
}, 20); // Prioridad 20 para asegurar que las dependencias estén cargadas

// Limpiar recursos al desactivar el plugin
register_deactivation_hook(MiIntegracionApi_PLUGIN_FILE, function() {
    if (class_exists('MiIntegracionApi\\Deteccion\\StockDetectorIntegration')) {
        \MiIntegracionApi\Deteccion\StockDetectorIntegration::cleanup();
    }
});
