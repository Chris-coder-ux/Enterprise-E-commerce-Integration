<?php

declare(strict_types=1);

namespace MiIntegracionApi\Hooks;

use MiIntegracionApi\Admin\AdminMenu;
use MiIntegracionApi\Admin\TemplateRenderer;
use MiIntegracionApi\Core\ContextDetector;
use MiIntegracionApi\Core\DependencyContainer;
use Psr\Log\LoggerInterface;

/**
 * Gestiona los hooks de administración de WordPress y el registro de menús para el plugin MiIntegracionApi.
 *
 * Esta clase es responsable de inicializar y gestionar toda la funcionalidad relacionada con la administración,
 * incluyendo el registro de menús, carga de recursos y configuración de páginas de administración. Implementa
 * la interfaz HookRegistryInterface para proporcionar una forma consistente de registrar hooks de administración.
 *
 * @package MiIntegracionApi\Hooks
 * @see HookRegistryInterface
 */
class AdminHookRegistry implements HookRegistryInterface
{
    /**
     * Instancia del contenedor de inyección de dependencias.
     *
     * @var DependencyContainer
     */
    private DependencyContainer $container;
    
    /**
     * Constructor de la clase AdminHookRegistry.
     *
     * @param DependencyContainer $container Contenedor de inyección de dependencias.
     */
    public function __construct(DependencyContainer $container)
    {
        $this->container = $container;
    }
    
    /**
     * Registra todos los hooks de administración e inicializa la interfaz de administración.
     *
     * Este método configura el menú de administración y la funcionalidad relacionada.
     * Utiliza carga diferida (lazy loading) para el menú de administración para evitar problemas de dependencias.
     *
     * @return void
     * @throws \Exception Si hay un error al inicializar el menú de administración.
     */
    public function register(): void
    {
        error_log('MIA - AdminHookRegistry::register() called');
        error_log('MIA - Current is_admin(): ' . (function_exists('is_admin') && is_admin() ? 'true' : 'false'));
        
        // Hook para admin_menu - lazy loading para evitar problemas de dependencias
        add_action('admin_menu', function() {
            error_log('MIA - admin_menu hook callback executing...');
            error_log('MIA - is_admin() in callback: ' . (function_exists('is_admin') && is_admin() ? 'true' : 'false'));
            error_log('MIA - Current user can manage_options: ' . (function_exists('current_user_can') && current_user_can('manage_options') ? 'true' : 'false'));
            error_log('MIA - WordPress version: ' . (function_exists('get_bloginfo') ? get_bloginfo('version') : 'unknown'));
            
            try {
                error_log('MIA - Creating AdminMenu instance...');
                $adminMenu = new \MiIntegracionApi\Admin\AdminMenu(
                    $this->container->get(\Psr\Log\LoggerInterface::class),
                    $this->container->get(\MiIntegracionApi\Admin\TemplateRenderer::class)
                );
                error_log('MIA - AdminMenu instance created, calling initialize...');
                $adminMenu->initialize();
                error_log('MIA - AdminMenu initialized successfully');
                
                // VERIFICAR SI EL MENÚ SE CREÓ REALMENTE
                global $menu, $submenu;
                error_log('MIA - Global $menu count: ' . (isset($menu) ? count($menu) : 'not set'));
                error_log('MIA - Global $submenu count: ' . (isset($submenu) ? count($submenu) : 'not set'));
                
                // Buscar nuestro menú específico
                if (isset($menu) && is_array($menu)) {
                    foreach ($menu as $index => $item) {
                        if (is_array($item) && isset($item[2]) && $item[2] === 'mi-integracion-api') {
                            error_log('MIA - Found our menu at index ' . $index . ': ' . json_encode($item));
                            break;
                        }
                    }
                }
                
            } catch (\Exception $e) {
                error_log('MIA - Error iniciializando AdminMenu: ' . $e->getMessage());
                error_log('MIA - Stack trace: ' . $e->getTraceAsString());
            }
        }, 10, 0);
        
        error_log('MIA - admin_menu hook registered');
        
        // Inicializar otras páginas de administración
        
        error_log('MIA - AdminHookRegistry::register() completed');
    }
    
    /**
     * Registra únicamente los hooks de administración esenciales.
     *
     * Esta es una versión mínima de register() que solo inicializa
     * la funcionalidad de administración más crítica.
     *
     * @return void
     */
    public function registerMinimal(): void
    {
        // Solo hooks esenciales de admin
    }
    
    /**
     * Verifica si este registro soporta el contexto dado.
     *
     * @param string $context El contexto a verificar.
     * @return bool True si este registro soporta el contexto, false en caso contrario.
     */
    public function supportsContext(string $context): bool
    {
        return $context === ContextDetector::CONTEXT_ADMIN;
    }
    
    /**
     * Verifica si este registro soporta el contexto dado en modo mínimo.
     *
     * @param string $context El contexto a verificar.
     * @return bool True si este registro soporta el modo mínimo en el contexto, false en caso contrario.
     * @see registerMinimal()
     */
    public function supportsMinimalContext(string $context): bool
    {
        return $context === ContextDetector::CONTEXT_ADMIN;
    }
}
