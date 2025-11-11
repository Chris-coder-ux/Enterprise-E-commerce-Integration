<?php

declare(strict_types=1);

namespace MiIntegracionApi\Hooks;

/**
 * Interface para el registro centralizado de hooks de WordPress
 * 
 * Esta interfaz define el contrato que deben implementar todos los registros de hooks
 * del sistema, asegurando consistencia en el registro de acciones y filtros.
 * 
 * @category   WordPress
 * @package    MiIntegracionApi
 * @subpackage Hooks
 * @since      1.0.0
 */
interface HookRegistryInterface
{
    /**
     * Registra todos los hooks del registro
     * 
     * Este método debe ser implementado para registrar todos los hooks (acciones y filtros)
     * necesarios para la funcionalidad completa del componente.
     * 
     * @return void
     * @throws \RuntimeException Si ocurre un error durante el registro
     */
    public function register(): void;
    
    /**
     * Registra solo los hooks mínimos necesarios
     * 
     * Implementa este método para registrar solo los hooks esenciales necesarios
     * para la funcionalidad básica, útil en contextos de mantenimiento o AJAX.
     * 
     * @return void
     */
    public function registerMinimal(): void;
    
    /**
     * Verifica si el registro soporta un contexto específico
     * 
     * @param string $context Nombre del contexto a verificar
     * @return bool True si el registro soporta el contexto, false en caso contrario
     */
    public function supportsContext(string $context): bool;
    
    /**
     * Verifica si el registro soporta un contexto mínimo específico
     * 
     * @param string $context Nombre del contexto a verificar
     * @return bool True si el registro soporta el contexto mínimo, false en caso contrario
     */
    public function supportsMinimalContext(string $context): bool;
}
