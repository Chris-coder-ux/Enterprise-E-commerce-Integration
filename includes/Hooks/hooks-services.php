<?php
/**
 * Configuración del contenedor de inyección de dependencias para el sistema de Hooks
 * 
 * Este archivo define las dependencias y servicios relacionados con el sistema de hooks
 * del plugin. Utiliza un contenedor de inyección de dependencias para gestionar
 * las instancias de los diferentes registros de hooks.
 *
 * @package    MiIntegracionApi
 * @subpackage Hooks
 * @category   Configuration
 * @since      1.0.0
 */

declare(strict_types=1);

use MiIntegracionApi\Hooks\HooksInit;
use MiIntegracionApi\Hooks\CoreHookRegistry;
use MiIntegracionApi\Hooks\AdminHookRegistry;
use MiIntegracionApi\Hooks\WooCommerceHookRegistry;
use MiIntegracionApi\Core\ContextDetector;
use Psr\Log\LoggerInterface;

return [
    /**
     * Configuración del servicio principal de inicialización de hooks
     * 
     * @param \Psr\Container\ContainerInterface $container El contenedor de dependencias
     * @return HooksInit Instancia configurada del inicializador de hooks
     */
    HooksInit::class => function($container) {
        return new HooksInit(
            $container->get(LoggerInterface::class),
            $container->get(ContextDetector::class),
            [
                $container->get(CoreHookRegistry::class),
                $container->get(AdminHookRegistry::class),
                $container->get(WooCommerceHookRegistry::class),
                // Aquí se pueden agregar más registros de hooks según sea necesario
            ]
        );
    },
    
    /**
     * Configuración del registro de hooks principales
     * 
     * @param \Psr\Container\ContainerInterface $container El contenedor de dependencias
     * @return CoreHookRegistry Instancia configurada del registro de hooks principales
     */
    CoreHookRegistry::class => function($container) {
        return new CoreHookRegistry(
            $container->get(LoggerInterface::class)
        );
    },
    
    /**
     * Configuración del registro de hooks de administración
     * 
     * @return AdminHookRegistry Instancia del registro de hooks de administración
     */
    AdminHookRegistry::class => function() {
        return new AdminHookRegistry();
    },
    
    /**
     * Configuración del registro de hooks de WooCommerce
     * 
     * @return WooCommerceHookRegistry Instancia del registro de hooks de WooCommerce
     */
    WooCommerceHookRegistry::class => function() {
        return new WooCommerceHookRegistry();
    }
];
