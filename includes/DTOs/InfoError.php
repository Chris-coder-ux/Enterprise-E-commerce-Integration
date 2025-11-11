<?php declare(strict_types=1);
/**
 * DTO para información de error
 * 
 * @package MiIntegracionApi\DTOs
 */

namespace MiIntegracionApi\DTOs;

class InfoError {
    /**
     * Código de error
     * 
     * @var int
     */
    public $Codigo = 0;
    
    /**
     * Descripción del error
     * 
     * @var string|null
     */
    public $Descripcion = null;
    
    /**
     * Constructor
     */
    public function __construct($code = 0, $description = null) {
        $this->Codigo = $code;
        $this->Descripcion = $description;
    }
    
    /**
     * Crea un objeto de error
     */
    public static function create($code, $description = null) {
        return new self($code, $description);
    }
    
    /**
     * Determina si hay un error
     */
    public function hasError() {
        return $this->Codigo !== 0;
    }
}
