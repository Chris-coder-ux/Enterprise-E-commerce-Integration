<?php declare(strict_types=1);
/**
 * DTO para respuestas de API
 * 
 * @package MiIntegracionApi\DTOs
 */

namespace MiIntegracionApi\DTOs;

class ApiResponse {
    /**
     * Objeto de información de error
     * 
     * @var InfoError
     */
    public $InfoError;
    
    /**
     * Datos de la respuesta
     * 
     * @var mixed
     */
    public $data;
    
    /**
     * Constructor
     */
    public function __construct($data = null, $error = null) {
        $this->data = $data;
        
        if ($error instanceof InfoError) {
            $this->InfoError = $error;
        } else {
            // Crear InfoError por defecto
            $this->InfoError = new InfoError();
        }
    }
    
    /**
     * Crea una respuesta exitosa
     */
    public static function success($data = null) {
        return new self($data);
    }
    
    /**
     * Crea una respuesta con error
     */
    public static function error($code, $message = '', $data = null) {
        $error = new InfoError($code, $message);
        return new self($data, $error);
    }
    
    /**
     * Verifica si la respuesta tiene error
     */
    public function hasError() {
        return $this->InfoError && $this->InfoError->Codigo != 0;
    }
}
