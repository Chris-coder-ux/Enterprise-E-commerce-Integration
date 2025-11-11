<?php declare(strict_types=1);
/**
 * Archivo de compatibilidad para DataSanitizer
 *
 * ATENCIÓN: Esta es una clase de compatibilidad. La implementación real se encuentra en:
 * /includes/Core/DataSanitizer.php
 * 
 * Este archivo existe para mantener la compatibilidad con código antiguo que
 * aún referencia DataSanitizer en el namespace Helpers en lugar de Core.
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.4.0
 */

namespace MiIntegracionApi\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase de compatibilidad para DataSanitizer.
 *
 * Extiende la implementación real de Core\DataSanitizer para proporcionar compatibilidad
 * con código antiguo que aún referencia DataSanitizer en el namespace Helpers.
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.4.0
 */
class DataSanitizer extends \MiIntegracionApi\Core\DataSanitizer {
     /**
      * Constructor de la clase de compatibilidad.
      *
      * Llama al constructor padre y, si está en modo debug, registra un log de uso de clase obsoleta.
      */
     public function __construct() {
        parent::__construct();
        
        // Opcionalmente registrar log de uso de clase obsoleta si está en modo debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logger = new Logger('compatibility');
            $logger->debug('Se está utilizando DataSanitizer desde el namespace Helpers (obsoleto). Por favor actualizar a MiIntegracionApi\Core\DataSanitizer');
        }
    }
}
