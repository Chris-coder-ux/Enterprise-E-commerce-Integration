<?php

declare(strict_types=1);

/**
 * Módulo de integración con WooCommerce
 *
 * Este archivo contiene la implementación del registro de hooks específicos
 * para la integración con WooCommerce, incluyendo:
 * - Registro de hooks de actualización automática de productos
 * - Manejo de eventos de WooCommerce
 * - Soporte para diferentes contextos de ejecución
 *
 * @package    MiIntegracionApi
 * @subpackage Hooks
 * @category   Integration
 * @since      1.0.0
 * @author     [Autor]
 * @link       [URL del plugin]
 */

namespace MiIntegracionApi\Hooks;

use MiIntegracionApi\Helpers\MapProduct;

/**
 * Registro de hooks específicos para WooCommerce
 *
 * Esta clase implementa la interfaz HookRegistryInterface para proporcionar
 * un punto centralizado de registro de todos los hooks relacionados con WooCommerce.
 *
 * @see HookRegistryInterface
 * @see MapProduct
 */
class WooCommerceHookRegistry implements HookRegistryInterface
{
    /**
     * Registra todos los hooks de WooCommerce
     *
     * Este método se encarga de registrar todos los hooks necesarios
     * para la integración con WooCommerce, incluyendo:
     * - Hooks para actualización automática de productos
     * - Filtros para datos de productos
     * - Acciones para eventos de pedidos
     *
     * @return void
     */
    public function register(): void
    {
        // Registrar hooks para actualización automática de productos
        MapProduct::register_auto_update_hooks();
        
        // Aquí se pueden registrar hooks adicionales de WooCommerce
    }
    
    /**
     * Registra solo los hooks mínimos necesarios para WooCommerce
     *
     * Este método está pensado para contextos donde no se requiere
     * toda la funcionalidad completa de la integración.
     *
     * @return void
     */
    public function registerMinimal(): void
    {
        // Hooks mínimos de WooCommerce
        // Se pueden registrar aquí hooks esenciales para el funcionamiento básico
    }
    
    /**
     * Verifica si el registro soporta un contexto específico
     *
     * @param string $context Contexto a verificar (ej: 'frontend', 'admin', 'cron')
     * @return bool Siempre devuelve true ya que los hooks de WooCommerce
     *              están disponibles en todos los contextos
     */
    public function supportsContext(string $context): bool
    {
        // Hooks de WooCommerce aplican en todos los contextos
        return true;
    }
    
    /**
     * Verifica si el registro mínimo soporta un contexto específico
     *
     * @param string $context Contexto a verificar (ej: 'frontend', 'admin', 'cron')
     * @return bool Siempre devuelve true ya que los hooks mínimos de WooCommerce
     *              están disponibles en todos los contextos
     */
    public function supportsMinimalContext(string $context): bool
    {
        // Hooks mínimos de WooCommerce aplican en todos los contextos
        return true;
    }
}
