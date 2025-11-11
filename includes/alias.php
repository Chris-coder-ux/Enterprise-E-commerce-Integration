<?php declare(strict_types=1);
/**
 * Definiciones de alias para mantener compatibilidad entre namespaces
 * 
 * Este archivo define alias de clases para mantener la compatibilidad
 * entre diferentes namespaces que pueden estar mezclados en el código.
 * 
 * @package MiIntegracionApi
 * @since 1.0.0
 */

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Crear alias de DataSanitizer para compatibilidad entre namespaces
if (!class_exists('MiIntegracionApi\Helpers\DataSanitizer') && class_exists('MiIntegracionApi\Core\DataSanitizer')) {
    class_alias('MiIntegracionApi\Core\DataSanitizer', 'MiIntegracionApi\Helpers\DataSanitizer');
}

// Otros alias pueden ser agregados aquí según sea necesario

// Alias para compatibilidad: Map_Order -> MapOrder
if (!class_exists('MiIntegracionApi\Helpers\Map_Order') && class_exists('MiIntegracionApi\Helpers\MapOrder')) {
    class_alias('MiIntegracionApi\\Helpers\\MapOrder', 'MiIntegracionApi\\Helpers\\Map_Order');
}
