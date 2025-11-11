<?php

declare(strict_types=1);

/**
 * Trait Singleton para patrones de diseño
 * 
 * @package MiIntegracionApi\Traits
 */

namespace MiIntegracionApi\Traits;

trait Singleton {
    /**
     * Instancia única
     * @var self|null
     */
    private static $instance = null;
    
    /**
     * Constructor protegido para evitar instanciación directa
     */
    protected function __construct() {}
    
    /**
     * Clonar está prohibido
     */
    private function __clone() {}
    
    /**
     * Obtener o crear la instancia única
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }
}
