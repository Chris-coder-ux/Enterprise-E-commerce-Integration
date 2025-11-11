<?php
/**
 * ContextDetector - Sistema de detección de contexto para inicialización lazy
 * 
 * @package MiIntegracionApi\Core
 * @version 2.0.0
 */

namespace MiIntegracionApi\Core;

/**
 * Clase para detectar el contexto del request
 */
class ContextDetector 
{
    public const CONTEXT_ADMIN = 'admin';
    public const CONTEXT_AJAX = 'ajax';
    public const CONTEXT_REST = 'rest';
    public const CONTEXT_CRON = 'cron';
    public const CONTEXT_FRONTEND = 'frontend';
    public const CONTEXT_CLI = 'cli';
    public const CONTEXT_UNKNOWN = 'unknown';
    
    /**
     * @var ContextDetector|null Instancia singleton
     */
    private static $instance = null;
    
    /**
     * @var array Información del contexto
     */
    private $contextInfo = [];
    
    /**
     * Constructor privado para singleton
     */
    private function __construct() {}
    
    /**
     * Obtiene instancia singleton
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Detecta el contexto del request actual
     */
    public function detect(): string
    {
        // Si ya tenemos contexto cacheado, verificar si sigue siendo válido
        if (isset($this->contextInfo['context'])) {
            // Re-verificar contexto admin si is_admin() cambió
            if ($this->contextInfo['context'] === self::CONTEXT_FRONTEND && 
                function_exists('is_admin') && is_admin()) {
                // El contexto cambió de frontend a admin, limpiar cache
                $this->contextInfo = [];
            }
        }
        
        if (!isset($this->contextInfo['context'])) {
            $this->contextInfo = $this->gatherContextInfo();
        }
        
        return $this->contextInfo['context'];
    }
    
    /**
     * Obtiene información detallada del contexto
     */
    public function getInfo(): array
    {
        if (empty($this->contextInfo)) {
            $this->detect();
        }
        
        return $this->contextInfo;
    }
    
    /**
     * Recopila información del contexto
     */
    private function gatherContextInfo(): array
    {
        $contextDetector = new ContextDetectionStrategy();
        $context = $contextDetector->determineContext();
        
        return [
            'context' => $context,
            'is_admin' => $context === self::CONTEXT_ADMIN,
            'is_ajax' => $context === self::CONTEXT_AJAX,
            'is_rest' => $context === self::CONTEXT_REST,
            'is_cron' => $context === self::CONTEXT_CRON,
            'is_cli' => $context === self::CONTEXT_CLI,
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'timestamp' => current_time('mysql'),
            'memory_usage' => function_exists('memory_get_usage') ? memory_get_usage(true) : 0
        ];
    }
    
    /**
     * Limpia el estado (útil para testing)
     */
    public function clear(): void
    {
        $this->contextInfo = [];
    }
    
    /**
     * Factory method para testing
     */
    public static function createForTesting(array $mockInfo = []): self
    {
        $instance = new self();
        $instance->contextInfo = $mockInfo;
        
        return $instance;
    }
}
