<?php
/**
 * Políticas de inicialización basadas en contexto - Separación de responsabilidades
 * 
 * @package MiIntegracionApi\Core
 */

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Core\ContextDetector;

class ContextInitializationPolicy
{
    /**
     * Contextos que requieren inicialización completa
     */
    private const FULL_INIT_CONTEXTS = [
        ContextDetector::CONTEXT_ADMIN,
        ContextDetector::CONTEXT_AJAX,
        ContextDetector::CONTEXT_REST
    ];
    
    /**
     * Contextos que requieren inicialización mínima
     */
    private const MINIMAL_INIT_CONTEXTS = [
        ContextDetector::CONTEXT_FRONTEND,
        ContextDetector::CONTEXT_CRON,
        ContextDetector::CONTEXT_CLI
    ];
    
    /**
     * Prioridades por contexto
     */
    private const PRIORITIES = [
        ContextDetector::CONTEXT_ADMIN => 5,
        ContextDetector::CONTEXT_AJAX => 10,
        ContextDetector::CONTEXT_REST => 15,
        ContextDetector::CONTEXT_CRON => 20,
        ContextDetector::CONTEXT_FRONTEND => 25,
        ContextDetector::CONTEXT_CLI => 30
    ];
    
    /**
     * Contextos críticos para rendimiento
     */
    private const PERFORMANCE_CRITICAL_CONTEXTS = [
        ContextDetector::CONTEXT_AJAX,
        ContextDetector::CONTEXT_REST,
        ContextDetector::CONTEXT_CRON
    ];
    
    private ContextDetector $contextDetector;
    
    public function __construct(ContextDetector $contextDetector)
    {
        $this->contextDetector = $contextDetector;
    }
    
    public function requiresFullInitialization(): bool
    {
        $context = $this->contextDetector->detect();
        return in_array($context, self::FULL_INIT_CONTEXTS, true);
    }
    
    public function requiresMinimalInitialization(): bool
    {
        $context = $this->contextDetector->detect();
        return in_array($context, self::MINIMAL_INIT_CONTEXTS, true);
    }
    
    public function getInitializationPriority(): int
    {
        $context = $this->contextDetector->detect();
        return self::PRIORITIES[$context] ?? 25;
    }
    
    public function isPerformanceCritical(): bool
    {
        $context = $this->contextDetector->detect();
        return in_array($context, self::PERFORMANCE_CRITICAL_CONTEXTS, true);
    }
}
