<?php
/**
 * Maneja la activación y desactivación del plugin
 * 
 * @package MiIntegracionApi
 * @since 2.0.0
 */

namespace MiIntegracionApi\Core;

defined('ABSPATH') || exit;

/**
 * Gestiona los procesos de activación y desactivación del plugin.
 *
 * Esta clase centraliza todas las operaciones necesarias durante el ciclo de vida
 * del plugin, incluyendo creación de tablas, programación de eventos cron,
 * configuración de opciones por defecto y limpieza de recursos.
 *
 * @package MiIntegracionApi\Core
 * @since   2.0.0
 * @author  Mi Integración API Team
 * @version 2.0.0
 */
class Activator
{
    /**
     * Ejecuta todas las tareas necesarias durante la activación del plugin.
     *
     * Proceso de activación completo que incluye:
     * - Creación de tablas de base de datos
     * - Programación de eventos cron automáticos
     * - Configuración de opciones por defecto del sistema
     *
     * Este método es llamado automáticamente por WordPress cuando
     * el plugin es activado desde el panel de administración.
     *
     * @since 2.0.0
     * @return void
     * 
     * @see register_activation_hook() Hook de WordPress para activación
     * @see self::create_tables() Para creación de estructura de BD
     * @see self::schedule_events() Para programación de tareas cron
     * @see self::set_default_options() Para configuración inicial
     */
    public static function activate(): void
    {
        self::create_tables();
        self::schedule_events();
        self::set_default_options();
    }
    
    /**
     * Ejecuta las tareas de limpieza durante la desactivación del plugin.
     *
     * Proceso de desactivación que incluye:
     * - Limpieza de eventos cron programados
     * - Eliminación de transients temporales
     * - Preservación de datos importantes del usuario
     *
     * NOTA: Este método NO elimina las tablas de base de datos ni
     * las opciones de configuración para permitir reactivación
     * sin pérdida de datos.
     *
     * @since 2.0.0
     * @return void
     * 
     * @see register_deactivation_hook() Hook de WordPress para desactivación
     * @see self::clear_scheduled_events() Para limpieza de eventos cron
     * @see self::clean_transients() Para limpieza de caché temporal
     */
    public static function deactivate(): void
    {
        self::clear_scheduled_events();
        self::clean_transients();
    }
    
    /**
     * Crea las tablas de base de datos necesarias para el plugin.
     *
     * Delega la creación de tablas al DatabaseManager o Installer
     * para mantener la separación de responsabilidades. Este método
     * actúa como un punto de entrada centralizado durante la activación.
     *
     * @since 2.0.0
     * @return void
     * 
     * @todo Implementar la lógica de creación o delegar a DatabaseManager
     * @see DatabaseManager Para gestión avanzada de base de datos
     * @see Installer::activate() Para lógica de instalación existente
     */
    private static function create_tables(): void
    {
        // Implementación de creación de tablas
        // (mover aquí la lógica de DatabaseManager si es apropiado)
    }
    
    /**
     * Programa los eventos cron necesarios para el funcionamiento automático.
     *
     * Configura las tareas programadas del sistema:
     * - mia_daily_cleanup: Limpieza diaria de logs y caché expirado
     * - mia_hourly_sync_check: Verificación horaria del estado de sincronización
     *
     * Solo programa eventos que no estén ya programados para evitar duplicados.
     *
     * @since 2.0.0
     * @return void
     * 
     * @global WP_Cron $wp_cron Sistema de cron de WordPress
     * @see wp_schedule_event() Para programar eventos recurrentes
     * @see wp_next_scheduled() Para verificar eventos existentes
     */
    private static function schedule_events(): void
    {
        if (!wp_next_scheduled('mia_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'mia_daily_cleanup');
        }
        
        // Cron job de verificación horaria eliminado - solo sincronización manual
    }
    
    /**
     * Limpia todos los eventos cron programados por el plugin.
     *
     * Elimina de forma segura todos los eventos cron asociados al plugin:
     * - mia_daily_cleanup: Limpieza diaria
     * - mia_hourly_sync_check: Verificación horaria
     *
     * Esta limpieza previene la ejecución de tareas después de la
     * desactivación del plugin y libera recursos del sistema cron.
     *
     * @since 2.0.0
     * @return void
     * 
     * @see wp_clear_scheduled_hook() Para eliminar eventos programados
     */
    private static function clear_scheduled_events(): void
    {
        wp_clear_scheduled_hook('mia_daily_cleanup');
        wp_clear_scheduled_hook('mia_hourly_sync_check');
    }
    
    /**
     * Establece las opciones de configuración por defecto del plugin.
     *
     * Configura los valores iniciales del sistema:
     * - Versión del plugin y timestamp de activación
     * - Estado de activación para tracking interno
     * - Configuración básica de API y sincronización
     * - Modo debug deshabilitado por defecto
     *
     * Solo establece opciones que no existan previamente para
     * preservar configuraciones personalizadas del usuario.
     *
     * @since 2.0.0
     * @return void
     * 
     * @global string MIA_VERSION Constante con la versión del plugin
     * @see get_option() Para verificar opciones existentes
     * @see update_option() Para establecer nuevas opciones
     */
    private static function set_default_options(): void
    {
        $defaults = [
            'mia_version' => MiIntegracionApi_VERSION,
            'mia_activated' => true,
            'mia_activation_time' => time(),
            'mia_ajustes' => [
                'api_key' => '',
                'sync_frequency' => 'hourly',
                'debug_mode' => false
            ]
        ];
        
        foreach ($defaults as $key => $value) {
            if (!get_option($key)) {
                update_option($key, $value);
            }
        }
    }
    
    /**
     * Limpia todos los transients y caché temporal del plugin.
     *
     * Elimina de forma segura:
     * - Transients específicos del plugin (mia_sync_status, mia_cache_status)
     * - Todos los transients con prefijo 'mia_' de la base de datos
     * - Transients de timeout asociados
     *
     * Esta limpieza es importante para evitar datos obsoletos después
     * de la desactivación y para liberar espacio en la base de datos.
     *
     * @since 2.0.0
     * @return void
     * 
     * @global wpdb $wpdb WordPress database abstraction object
     * @see delete_transient() Para eliminar transients específicos
     * @see wpdb::query() Para limpieza masiva de transients
     * 
     * @warning Esta operación elimina permanentemente los transients
     */
    private static function clean_transients(): void
    {
        delete_transient('mia_sync_status');
        delete_transient('mia_cache_status');
        
        // Limpiar transients específicos del plugin
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_mia_%' 
             OR option_name LIKE '_transient_timeout_mia_%'"
        );
    }
}
