<?php

declare(strict_types=1);

/**
 * Inicializador centralizado de hooks para el plugin
 *
 * Este archivo contiene la clase HooksInit que se encarga de registrar y gestionar
 * todos los hooks principales del plugin, incluyendo la inicialización de assets
 * y la configuración de hooks específicos para el área de administración.
 *
 * @package    MiIntegracionApi
 * @subpackage Hooks
 * @category   Core
 * @since      1.0.0
 * @author     [Autor]
 * @link       [URL del plugin]
 */

namespace MiIntegracionApi\Hooks;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase principal para la inicialización y gestión de hooks
 *
 * Esta clase proporciona métodos estáticos para registrar diferentes tipos de hooks
 * en el sistema, optimizando la carga según el contexto (admin, frontend, etc.).
 * Utiliza HooksManager para un registro centralizado de hooks.
 *
 * @see HooksManager
 */
class HooksInit {
    /**
     * Inicializa los hooks principales del plugin
     *
     * Este método registra los hooks esenciales que deben cargarse en todos los contextos.
     * Incluye la carga de traducciones y la visualización de notificaciones de activación.
     *
     * Optimización: Este método se ejecuta en el hook 'init' en lugar de 'plugins_loaded'
     * para reducir la carga inicial del sitio.
     *
     * @return void
     * @hook init - Se ejecuta cuando WordPress termina de cargar
     * @see \MiIntegracionApi\load_plugin_textdomain_on_init()
     * @see \MiIntegracionApi\display_activation_errors()
     */
    public static function init() {
        // Solo inicializar si HooksManager existe
        if (!class_exists('\MiIntegracionApi\Hooks\HooksManager')) {
            return;
        }
        
        // Cargar textdomain
        HooksManager::add_action(
            'init',
            '\MiIntegracionApi\load_plugin_textdomain_on_init',
            HookPriorities::get('INIT', 'DEFAULT')
        );
        
        // Mostrar errores de activación
        HooksManager::add_action(
            'admin_notices',
            '\MiIntegracionApi\display_activation_errors',
            HookPriorities::get('ADMIN', 'ADMIN_NOTICES')
        );
        
        // Inicializar sistema de assets
        self::init_assets_hooks();
    }
    
    /**
     * Inicializa hooks específicos para el área de administración
     *
     * Este método se encarga de registrar hooks que solo son necesarios en el panel
     * de administración de WordPress, mejorando el rendimiento al no cargar estos
     * recursos en el frontend.
     *
     * Optimización: Se ejecuta solo cuando se carga el panel de administración.
     *
     * @return void
     * @see self::init_assets_hooks()
     */
    public static function init_admin_hooks() {
        // Solo inicializar si HooksManager existe
        if (!class_exists('\MiIntegracionApi\Hooks\HooksManager')) {
            return;
        }
        
        // Inicializar sistema de assets solo cuando se necesite en admin
        self::init_assets_hooks();
    }
    
    /**
     * Inicializa los hooks para la carga de recursos (CSS/JS)
     *
     * Registra los hooks necesarios para cargar estilos y scripts tanto en el
     * área de administración como en el frontend del sitio. Utiliza la clase
     * Assets para gestionar el registro y la cola de recursos.
     *
     * @return void
     * @hook admin_enqueue_scripts - Para cargar recursos en el admin
     * @hook wp_enqueue_scripts   - Para cargar recursos en el frontend
     * @see \MiIntegracionApi\Assets
     */
    private static function init_assets_hooks() {
        HooksManager::add_action('plugins_loaded', function() {
            if (class_exists('\MiIntegracionApi\Assets')) {
                $assets = new \MiIntegracionApi\Assets('mi-integracion-api', MiIntegracionApi_VERSION);
                
                // Admin scripts y estilos
                HooksManager::add_action(
                    'admin_enqueue_scripts',
                    [$assets, 'enqueue_admin_styles'],
                    HookPriorities::get('ADMIN', 'ENQUEUE_SCRIPTS')
                );
                
                HooksManager::add_action(
                    'admin_enqueue_scripts',
                    [$assets, 'enqueue_admin_scripts'],
                    HookPriorities::get('ADMIN', 'ENQUEUE_SCRIPTS')
                );
                
                // Frontend scripts y estilos
                HooksManager::add_action(
                    'wp_enqueue_scripts',
                    [$assets, 'enqueue_public_styles']
                );
                
                HooksManager::add_action(
                    'wp_enqueue_scripts',
                    [$assets, 'enqueue_public_scripts']
                );
            }
        });
    }
}
