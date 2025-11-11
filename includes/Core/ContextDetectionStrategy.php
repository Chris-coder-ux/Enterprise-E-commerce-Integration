<?php
/**
 * Estrategia para detección de contexto - Separación de responsabilidades
 * 
 * @package MiIntegracionApi\Core
 */

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Core\ContextDetector;

class ContextDetectionStrategy
{
    /**
     * Determina el contexto actual
     */
    public function determineContext(): string
    {
        if ($this->isCliContext()) {
            return ContextDetector::CONTEXT_CLI;
        }
        
        if ($this->isAjaxContext()) {
            return ContextDetector::CONTEXT_AJAX;
        }
        
        if ($this->isRestContext()) {
            return ContextDetector::CONTEXT_REST;
        }
        
        if ($this->isCronContext()) {
            return ContextDetector::CONTEXT_CRON;
        }
        
        if ($this->isAdminContext()) {
            return ContextDetector::CONTEXT_ADMIN;
        }
        
        return ContextDetector::CONTEXT_FRONTEND;
    }
    
    private function isCliContext(): bool
    {
        return defined('WP_CLI') && constant('WP_CLI');
    }
    
    private function isAjaxContext(): bool
    {
        return defined('DOING_AJAX') && constant('DOING_AJAX');
    }
    
    private function isRestContext(): bool
    {
        return defined('REST_REQUEST') && constant('REST_REQUEST');
    }
    
    private function isCronContext(): bool
    {
        return (defined('DOING_CRON') && constant('DOING_CRON')) || 
               (function_exists('wp_doing_cron') && wp_doing_cron());
    }
    
    private function isAdminContext(): bool
    {
        // Verificar múltiples indicadores de contexto admin
        if (function_exists('is_admin') && is_admin()) {
            return true;
        }
        
        // Verificar constante WP_ADMIN
        if (defined('WP_ADMIN') && constant('WP_ADMIN')) {
            return true;
        }
        
        // Verificar REQUEST_URI para rutas de admin
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-admin/') !== false) {
            return true;
        }
        
        // Verificar SCRIPT_NAME para archivos de admin
        if (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/wp-admin/') !== false) {
            return true;
        }
        
        return false;
    }
}
