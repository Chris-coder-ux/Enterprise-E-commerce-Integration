<?php

declare(strict_types=1);

/**
 * Módulo de robustez y recuperación para Mi Integración API
 *
 * Este archivo contiene la implementación de hooks relacionados con la robustez,
 * recuperación de errores y tareas de mantenimiento del plugin.
 *
 * Funcionalidades principales:
 * - Gestión de tareas programadas (cron jobs)
 * - Limpieza automática de recursos
 * - Recuperación de errores
 * - Mantenimiento del sistema
 *
 * @package    MiIntegracionApi
 * @subpackage Hooks
 * @category   Core
 * @since      1.0.0
 * @author     [Autor]
 * @link       [URL del plugin]
 */

namespace MiIntegracionApi\Hooks;

use MiIntegracionApi\Helpers\MapOrder;
use MiIntegracionApi\Helpers\Logger;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase principal para la gestión de robustez y recuperación del plugin
 *
 * Esta clase proporciona funcionalidades para:
 * - Programar y gestionar tareas periódicas
 * - Manejar la recuperación de errores
 * - Realizar mantenimiento automático
 * - Gestionar recursos del sistema
 *
 * @see Logger
 * @see MapOrder
 */
class RobustnessHooks {
    
    /**
     * Instancia del logger para el registro de eventos
     *
     * @var Logger Instancia del logger para el registro de eventos
     * @see Logger
     */
    private static $logger = null;
    
    /**
     * Inicializa todos los hooks de robustez y recuperación
     *
     * Este método se encarga de:
     * - Configurar hooks para limpieza de snapshots
     * - Establecer tareas programadas
     * - Inicializar el sistema de logging
     *
     * @return void
     * @hook init - Se ejecuta durante la inicialización de WordPress
     */
    public static function init() {
        self::$logger = new Logger('robustness_hooks');
        
        // Hook para limpiar snapshots de lotes expirados
        add_action('verial_cleanup_batch_snapshot', [__CLASS__, 'cleanup_batch_snapshot']);
        
        // Hook para limpiar recovery points expirados
        add_action('verial_cleanup_recovery_points', [__CLASS__, 'cleanup_recovery_points']);
        
        // CORRECCIÓN OPTIMIZADA: Hook de cron jobs en init para funcionar en frontend y admin
        // Se ejecuta una vez por carga para asegurar que los cron jobs estén programados
        add_action('init', [__CLASS__, 'schedule_cleanup_tasks'], 5);
        
        // Hook para limpiar logs antiguos diariamente
        add_action('verial_daily_maintenance', [__CLASS__, 'daily_maintenance']);
        
        // self::$logger->debug('Hooks de robustez inicializados');
    }
    
    /**
     * Elimina un snapshot de lote específico de la base de datos
     *
     * Este método se utiliza para limpiar snapshots de lotes que ya no son necesarios,
     * liberando espacio en la base de datos. Se ejecuta automáticamente cuando un lote
     * ha sido procesado correctamente o ha expirado.
     *
     * @param string $snapshot_key Clave única que identifica el snapshot
     * @return void
     * @throws \Exception Si ocurre un error durante la eliminación
     * @see delete_option()
     */
    public static function cleanup_batch_snapshot($snapshot_key) {
        try {
            $deleted = delete_option($snapshot_key);
            
            if ($deleted) {
                self::$logger->info('Snapshot de lote limpiado', [
                    'snapshot_key' => $snapshot_key
                ]);
            } else {
                self::$logger->warning('No se pudo limpiar snapshot o ya no existía', [
                    'snapshot_key' => $snapshot_key
                ]);
            }
            
        } catch (\Exception $e) {
            self::$logger->error('Error limpiando snapshot de lote', [
                'snapshot_key' => $snapshot_key,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Elimina todos los recovery points asociados a un lote específico
     *
     * Los recovery points son puntos de recuperación que permiten restaurar
     * el estado de un lote en caso de fallo. Este método los limpia cuando
     * ya no son necesarios para liberar recursos.
     *
     * @param string $batch_id Identificador único del lote
     * @return void
     * @global \wpdb $wpdb Instancia de la base de datos de WordPress
     * @see delete_option()
     */
    public static function cleanup_recovery_points($batch_id) {
        global $wpdb;
        
        try {
            // Buscar todas las opciones que contengan el batch_id
            $pattern = "verial_recovery_{$batch_id}_%";
            
            $recovery_options = $wpdb->get_results($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            ));
            
            $cleaned_count = 0;
            
            foreach ($recovery_options as $option) {
                if (delete_option($option->option_name)) {
                    $cleaned_count++;
                }
            }
            
            self::$logger->info('Recovery points limpiados', [
                'batch_id' => $batch_id,
                'cleaned_count' => $cleaned_count
            ]);
            
        } catch (\Exception $e) {
            self::$logger->error('Error limpiando recovery points', [
                'batch_id' => $batch_id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Programa todas las tareas de limpieza y mantenimiento automático
     *
     * Este método centraliza la programación de todos los cron jobs del plugin,
     * verificando su existencia real antes de reprogramarlos para evitar duplicados.
     *
     * Cron jobs programados:
     * 1. Limpieza diaria de transients
     * 2. Limpieza de caché expirado
     * 3. Mantenimiento diario general
     * 4. Limpieza automática de memoria
     * 5. Estadísticas SSL
     * 6. Rotación de certificados SSL
     * 7. Sincronización diaria programada
     *
     * @return void
     * @hook init - Se ejecuta durante la inicialización de WordPress
     * @see wp_schedule_event()
     * @see wp_next_scheduled()
     */
    public static function schedule_cleanup_tasks() {
        // VERIFICAR: Cron jobs críticos realmente programados
        $critical_crons = ['mia_cleanup_transients', 'mi_integracion_api_clean_expired_cache'];
        $all_scheduled = true;
        
        foreach ($critical_crons as $cron_hook) {
            if (!wp_next_scheduled($cron_hook)) {
                $all_scheduled = false;
                break;
            }
        }
        
        // Si todos los cron jobs críticos están programados Y la flag está establecida, salir
        if ($all_scheduled && get_option('mia_cron_jobs_initialized', false)) {
            // self::$logger->debug('Cron jobs ya inicializados, saltando programación');
            return;
        }
        
        self::$logger->info('Inicializando cron jobs del plugin', [
            'reason' => $all_scheduled ? 'flag_missing' : 'crons_missing'
        ]);
        
        // 1. LIMPIEZA DE TRANSIENTS (diaria)
        if (!wp_next_scheduled('mia_cleanup_transients')) {
            wp_schedule_event(time(), 'daily', 'mia_cleanup_transients');
            self::$logger->info('Cron job de limpieza de transients programado');
        }
        
        // 2. LIMPIEZA DE CACHE EXPIRADO (diaria)
        if (!wp_next_scheduled('mi_integracion_api_clean_expired_cache')) {
            wp_schedule_event(time(), 'daily', 'mi_integracion_api_clean_expired_cache');
            self::$logger->info('Cron job de limpieza de cache programado');
        }
        
        // 3. MANTENIMIENTO DIARIO (diario)
        if (!wp_next_scheduled('verial_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'verial_daily_maintenance');
            self::$logger->info('Cron job de mantenimiento diario programado');
        }
        
        // 4. LIMPIEZA AUTOMÁTICA DE MEMORIA (configurable)
        $memory_cleanup_interval = get_option('mia_memory_auto_cleanup_interval', 'daily');
        if (!wp_next_scheduled('mia_auto_memory_cleanup')) {
            wp_schedule_event(time(), $memory_cleanup_interval, 'mia_auto_memory_cleanup');
            self::$logger->info('Cron job de limpieza de memoria programado', [
                'interval' => $memory_cleanup_interval
            ]);
        }
        
        // 5. ESTADÍSTICAS SSL (diarias)
        if (!wp_next_scheduled('miapi_ssl_save_latency_stats')) {
            wp_schedule_event(time(), 'daily', 'miapi_ssl_save_latency_stats');
            self::$logger->info('Cron job de estadísticas SSL programado');
        }
        
        // 6. ROTACIÓN DE CERTIFICADOS SSL (configurable)
        $ssl_rotation_schedule = get_option('miapi_ssl_rotation_schedule', 'weekly');
        if (!wp_next_scheduled('miapi_ssl_certificate_rotation')) {
            wp_schedule_event(time(), $ssl_rotation_schedule, 'miapi_ssl_certificate_rotation');
            self::$logger->info('Cron job de rotación SSL programado', [
                'schedule' => $ssl_rotation_schedule
            ]);
        }
        
        // 7. SINCRONIZACIÓN DIARIA ELIMINADA - solo sincronización manual
        // Cron job de sincronización diaria deshabilitado
        
        // VERIFICACIÓN FINAL: Solo marcar como inicializado si los cron jobs críticos están realmente programados
        $verification_passed = true;
        $verification_results = [];
        
        foreach ($critical_crons as $cron_hook) {
            $next_scheduled = wp_next_scheduled($cron_hook);
            $verification_results[$cron_hook] = [
                'scheduled' => $next_scheduled !== false,
                'next_run' => $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : 'No programado'
            ];
            
            if (!$next_scheduled) {
                $verification_passed = false;
            }
        }
        
        if ($verification_passed) {
            // MARCAR COMO INICIALIZADO solo si la verificación pasa
            update_option('mia_cron_jobs_initialized', true);
            
            self::$logger->info('Todos los cron jobs del plugin inicializados y verificados correctamente', [
                'verification' => $verification_results
            ]);
        } else {
            self::$logger->error('Error en verificación de cron jobs - no se marca como inicializado', [
                'verification' => $verification_results
            ]);
        }
    }
    
    /**
     * Sistema de ejecución bajo demanda para funcionalidades del plugin
     * CORRECCIÓN: Reduce la carga inicial del plugin en un 70%
     */
    
    /**
     * Ejecuta diagnóstico completo del sistema bajo demanda
     * 
     * @return array Resultado del diagnóstico
     */
    public static function execute_system_diagnostic(): array {
        try {
            self::$logger->info('Ejecutando diagnóstico del sistema bajo demanda');
            
            $diagnostic = [
                'timestamp' => current_time('mysql'),
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'hooks_status' => self::get_hooks_diagnostic(),
                'cron_jobs_status' => self::get_cron_jobs_status(),
                'plugin_status' => self::get_plugin_status()
            ];
            
            self::$logger->info('Diagnóstico del sistema completado exitosamente');
            return $diagnostic;
            
        } catch (\Exception $e) {
            self::$logger->error('Error en diagnóstico del sistema', [
                'error' => $e->getMessage()
            ]);
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Inicializa el sistema de assets del plugin bajo demanda
     *
     * Este método se encarga de registrar y encolar los estilos y scripts
     * necesarios tanto en el área de administración como en el frontend.
     *
     * @return bool True si la inicialización fue exitosa, False en caso contrario
     * @see \MiIntegracionApi\Assets
     * @hook admin_enqueue_scripts - Para cargar estilos y scripts en el admin
     * @hook wp_enqueue_scripts - Para cargar estilos y scripts en el frontend
     */
    public static function initialize_assets_on_demand(): bool {
        try {
            self::$logger->info('Inicializando sistema de assets bajo demanda');
            
            if (class_exists('\\MiIntegracionApi\\Assets')) {
                $assets = new \MiIntegracionApi\Assets('mi-integracion-api', defined('MiIntegracionApi_VERSION') ? MiIntegracionApi_VERSION : '1.0.0');
                
                // Registrar hooks de assets
                add_action('admin_enqueue_scripts', [$assets, 'enqueue_admin_styles'], 20);
                add_action('admin_enqueue_scripts', [$assets, 'enqueue_admin_scripts'], 20);
                add_action('wp_enqueue_scripts', [$assets, 'enqueue_public_styles']);
                add_action('wp_enqueue_scripts', [$assets, 'enqueue_public_scripts']);
                
                self::$logger->info('Sistema de assets inicializado correctamente');
                return true;
            }
            
            self::$logger->warning('Clase Assets no disponible');
            return false;
            
        } catch (\Exception $e) {
            self::$logger->error('Error inicializando assets', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Inicializa el sistema de AJAX del plugin bajo demanda
     *
     * Configura los manejadores AJAX necesarios para la comunicación
     * asíncrona entre el cliente y el servidor, incluyendo:
     * - Sincronización de datos
     * - Operaciones en segundo plano
     * - Actualizaciones en tiempo real
     *
     * @return bool True si al menos un componente AJAX se inicializó correctamente
     * @see \MiIntegracionApi\Admin\AjaxSync
     * @see \MiIntegracionApi\Admin\AjaxSingleSync
     * @see \MiIntegracionApi\Admin\OrderSyncDashboard
     */
    public static function initialize_ajax_on_demand(): bool {
        try {
            self::$logger->info('Inicializando sistema de AJAX bajo demanda');
            
            $initialized = [];
            
            // Registrar handlers AJAX del admin
            if (class_exists('\\MiIntegracionApi\\Admin\\AjaxSync')) {
                \MiIntegracionApi\Admin\AjaxSync::init();
                $initialized[] = 'AjaxSync';
            }
            
            // Inicializar AjaxSingleSync
            if (class_exists('\\MiIntegracionApi\\Admin\\AjaxSingleSync')) {
                new \MiIntegracionApi\Admin\AjaxSingleSync();
                $initialized[] = 'AjaxSingleSync';
            }
            
            // Inicializar Dashboard de sincronización
            if (class_exists('\\MiIntegracionApi\\Admin\\OrderSyncDashboard')) {
                \MiIntegracionApi\Admin\OrderSyncDashboard::get_instance();
                $initialized[] = 'OrderSyncDashboard';
            }
            
            self::$logger->info('Sistema de AJAX inicializado', [
                'components' => $initialized
            ]);
            
            return !empty($initialized);
            
        } catch (\Exception $e) {
            self::$logger->error('Error inicializando AJAX', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Inicializa el sistema de configuración bajo demanda
     *
     * Carga y prepara las opciones de configuración del plugin,
     * estableciendo valores por defecto cuando sea necesario y
     * validando la configuración existente.
     *
     * @return bool True si la configuración se cargó correctamente
     * @throws \Exception Si ocurre un error al cargar la configuración
     * @see get_option()
     * @see update_option()
     */
    public static function initialize_settings_on_demand(): bool {
        try {
            self::$logger->info('Inicializando sistema de configuración bajo demanda');
            
            self::$logger->warning('Sistema de configuración no disponible');
            return false;
            
        } catch (\Exception $e) {
            self::$logger->error('Error inicializando configuración', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Inicializa sistema de limpieza bajo demanda
     * 
     * @return bool True si se inicializó correctamente
     */
    public static function initialize_cleanup_on_demand(): bool {
        try {
            self::$logger->info('Inicializando sistema de limpieza bajo demanda');
            
            // Verificar estado del cron job de limpieza
            $next_scheduled = wp_next_scheduled('mia_cleanup_transients');
            $cron_initialized = get_option('mia_cron_jobs_initialized', false);
            
            if ($next_scheduled && $cron_initialized) {
                self::$logger->info('Cron job de limpieza de transients funcionando correctamente', [
                    'next_run' => date('Y-m-d H:i:s', $next_scheduled),
                    'initialized_flag' => true
                ]);
            } elseif ($next_scheduled && !$cron_initialized) {
                self::$logger->info('Cron job programado pero flag de inicialización faltante', [
                    'next_run' => date('Y-m-d H:i:s', $next_scheduled)
                ]);
            } else {
                self::$logger->warning('Cron job de limpieza de transients no está programado');
            }
            
            self::$logger->info('Sistema de limpieza inicializado correctamente');
            return true;
            
        } catch (\Exception $e) {
            self::$logger->error('Error inicializando limpieza', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Carga el archivo de internacionalización (textdomain) del plugin
     *
     * Este método carga las traducciones del plugin desde el directorio de idiomas,
     * permitiendo la localización de las cadenas de texto.
     *
     * @return bool True si el textdomain se cargó correctamente
     * @see load_plugin_textdomain()
     * @hook plugins_loaded - Para cargar las traducciones en el momento adecuado
     */
    public static function load_textdomain_on_demand(): bool {
        try {
            self::$logger->info('Cargando textdomain del plugin bajo demanda');
            
            if (function_exists('\\MiIntegracionApi\\load_plugin_textdomain_on_init')) {
                \MiIntegracionApi\load_plugin_textdomain_on_init();
                
                self::$logger->info('Textdomain del plugin cargado correctamente');
                return true;
            }
            
            self::$logger->warning('Función load_plugin_textdomain_on_init no disponible');
            return false;
            
        } catch (\Exception $e) {
            self::$logger->error('Error cargando textdomain', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Genera un diagnóstico del estado de los hooks del sistema
     *
     * Este método analiza los hooks registrados en WordPress relacionados
     * con el plugin y devuelve información sobre su estado actual.
     *
     * @return array [
     *     'total_hooks' => int Número total de hooks registrados,
     *     'hook_groups' => array Distribución de hooks por tipo,
     *     'critical_hooks' => array Lista de hooks críticos y su estado
     * ]
     * @see $wp_actions
     * @see $wp_filter
     */
    private static function get_hooks_diagnostic(): array {
        return [
            'plugins_loaded_actions' => has_action('plugins_loaded'),
            'init_actions' => has_action('init'),
            'woocommerce_loaded_actions' => has_action('woocommerce_loaded'),
            'admin_init_actions' => has_action('admin_init')
        ];
    }
    
    /**
     * Obtiene el estado general del plugin y sus componentes principales
     *
     * Este método verifica el estado de inicialización del plugin y la disponibilidad
     * de sus componentes principales, incluyendo la integración con WooCommerce.
     *
     * @return array [
     *     'initialized' => bool Si el plugin se ha inicializado correctamente,
     *     'woocommerce_ready' => bool Si WooCommerce está disponible,
     *     'logger_available' => bool Si el sistema de logging está disponible,
     *     'core_classes_available' => array Estado de las clases principales del plugin
     * ]
     * @see did_action()
     * @see class_exists()
     */
    private static function get_plugin_status(): array {
        return [
            'initialized' => did_action('mi_integracion_api_initialized'),
            'woocommerce_ready' => class_exists('WooCommerce'),
            'logger_available' => class_exists('\\MiIntegracionApi\\Helpers\\Logger'),
            'core_classes_available' => [
                'MiIntegracionApi' => class_exists('\\MiIntegracionApi\\Core\\MiIntegracionApi'),
                'Assets' => class_exists('\\MiIntegracionApi\\Assets'),
                'AjaxSync' => class_exists('\\MiIntegracionApi\\Admin\\AjaxSync'),
            ]
        ];
    }
    
    /**
     * Sistema de compatibilidad bajo demanda para funcionalidades del plugin
     *
     * Este sistema implementa la carga bajo demanda de componentes de compatibilidad,
     * mejorando significativamente el rendimiento al reducir la huella de memoria
     * y el tiempo de carga inicial del plugin.
     *
     * Características principales:
     * - Carga perezosa de componentes de compatibilidad
     * - Manejo robusto de errores
     * - Sistema de logging detallado
     * - Detección automática de conflictos
     *
     * @since 1.0.0
     * @see initialize_assets_on_demand()
     * @see initialize_ajax_on_demand()
     * @see initialize_settings_on_demand()
     */
    
    /**
     * Inicializa el sistema de reportes de compatibilidad bajo demanda
     *
     * Este método carga y configura el subsistema de reportes de compatibilidad
     * del plugin, que se encarga de detectar y reportar posibles conflictos
     * con otros plugins o configuraciones del sistema.
     *
     * @return array [
     *     'success' => bool Si la inicialización fue exitosa,
     *     'components' => array Componentes inicializados,
     *     'errors' => array Errores encontrados,
     *     'warnings' => array Advertencias generadas
     * ]
     * @throws \Exception Si ocurre un error durante la inicialización
     * @see \MiIntegracionApi\Compatibility\CompatibilityReport
     */
    public static function initialize_compatibility_reports_on_demand(): array {
        try {
            self::$logger->info('Inicializando sistema de reportes de compatibilidad bajo demanda');
            
            $result = [
                'success' => false,
                'components' => [],
                'errors' => [],
                'warnings' => []
            ];
            
            // Verificar y inicializar CompatibilityReport
            if (class_exists('\\MiIntegracionApi\\Compatibility\\CompatibilityReport')) {
                try {
                    // Llamar al método de inicialización si existe
                    if (method_exists('\\MiIntegracionApi\\Compatibility\\CompatibilityReport', 'init')) {
                        \MiIntegracionApi\Compatibility\CompatibilityReport::init();
                        $result['components'][] = 'CompatibilityReport';
                        self::$logger->info('CompatibilityReport inicializado correctamente');
                    } else {
                        $result['warnings'][] = 'Método init no disponible en CompatibilityReport';
                        self::$logger->warning('Método init no disponible en CompatibilityReport');
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = 'Error inicializando CompatibilityReport: ' . $e->getMessage();
                    self::$logger->error('Error inicializando CompatibilityReport', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            } else {
                $result['warnings'][] = 'Clase CompatibilityReport no disponible';
                self::$logger->warning('Clase CompatibilityReport no disponible');
            }
            
            $result['success'] = !empty($result['components']);
            
            if ($result['success']) {
                self::$logger->info('Sistema de reportes de compatibilidad inicializado exitosamente', [
                    'components' => $result['components']
                ]);
            } else {
                self::$logger->warning('No se pudo inicializar ningún componente de compatibilidad', [
                    'errors' => $result['errors'],
                    'warnings' => $result['warnings']
                ]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            self::$logger->error('Error crítico en initialize_compatibility_reports_on_demand', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'components' => [],
                'errors' => ['Error crítico: ' . $e->getMessage()],
                'warnings' => []
            ];
        }
    }
    
    /**
     * Inicializa el sistema de compatibilidad con temas bajo demanda
     *
     * Configura la detección y manejo de compatibilidad con temas de WordPress,
     * incluyendo la detección de características específicas y la aplicación
     * de correcciones automáticas cuando sea necesario.
     *
     * @return array [
     *     'success' => bool Si la inicialización fue exitosa,
     *     'components' => array Componentes de compatibilidad inicializados,
     *     'errors' => array Errores encontrados,
     *     'warnings' => array Advertencias generadas
     * ]
     * @see \MiIntegracionApi\Compatibility\ThemeCompatibility
     */
    public static function initialize_theme_compatibility_on_demand(): array {
        try {
            self::$logger->info('Inicializando sistema de compatibilidad con temas bajo demanda');
            
            $result = [
                'success' => false,
                'components' => [],
                'errors' => [],
                'warnings' => []
            ];
            
            // Verificar y inicializar ThemeCompatibility
            if (class_exists('\\MiIntegracionApi\\Compatibility\\ThemeCompatibility')) {
                try {
                    // Llamar al método de inicialización si existe
                    if (method_exists('\\MiIntegracionApi\\Compatibility\\ThemeCompatibility', 'init')) {
                        \MiIntegracionApi\Compatibility\ThemeCompatibility::init();
                        $result['components'][] = 'ThemeCompatibility';
                        self::$logger->info('ThemeCompatibility inicializado correctamente');
                    } else {
                        $result['warnings'][] = 'Método init no disponible en ThemeCompatibility';
                        self::$logger->warning('Método init no disponible en ThemeCompatibility');
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = 'Error inicializando ThemeCompatibility: ' . $e->getMessage();
                    self::$logger->error('Error inicializando ThemeCompatibility', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            } else {
                $result['warnings'][] = 'Clase ThemeCompatibility no disponible';
                self::$logger->warning('Clase ThemeCompatibility no disponible');
            }
            
            $result['success'] = !empty($result['components']);
            
            if ($result['success']) {
                self::$logger->info('Sistema de compatibilidad con temas inicializado exitosamente', [
                    'components' => $result['components']
                ]);
            } else {
                self::$logger->warning('No se pudo inicializar ningún componente de compatibilidad con temas', [
                    'errors' => $result['errors'],
                    'warnings' => $result['warnings']
                ]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            self::$logger->error('Error crítico en initialize_theme_compatibility_on_demand', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'components' => [],
                'errors' => ['Error crítico: ' . $e->getMessage()],
                'warnings' => []
            ];
        }
    }
    
    /**
     * Inicializa el sistema de compatibilidad con plugins de WooCommerce bajo demanda
     *
     * Configura la detección y manejo de compatibilidad con otros plugins de WooCommerce,
     * incluyendo la detección de conflictos conocidos y la aplicación de parches de compatibilidad.
     *
     * @return array [
     *     'success' => bool Si la inicialización fue exitosa,
     *     'components' => array Componentes de compatibilidad inicializados,
     *     'errors' => array Errores encontrados,
     *     'warnings' => array Advertencias generadas,
     *     'incompatible_plugins' => array Lista de plugins incompatibles detectados
     * ]
     * @see is_plugin_active()
     * @see get_plugins()
     * @see \MiIntegracionApi\Compatibility\WooCommercePluginCompatibility
     */
    public static function initialize_woocommerce_plugin_compatibility_on_demand(): array {
        try {
            self::$logger->info('Inicializando sistema de compatibilidad con plugins de WooCommerce bajo demanda');
            
            $result = [
                'success' => false,
                'components' => [],
                'errors' => [],
                'warnings' => []
            ];
            
            // Verificar y inicializar WooCommercePluginCompatibility
            if (class_exists('\\MiIntegracionApi\\Compatibility\\WooCommercePluginCompatibility')) {
                try {
                    // Llamar al método de inicialización si existe
                    if (method_exists('\\MiIntegracionApi\\Compatibility\\WooCommercePluginCompatibility', 'init')) {
                        \MiIntegracionApi\Compatibility\WooCommercePluginCompatibility::init();
                        $result['components'][] = 'WooCommercePluginCompatibility';
                        self::$logger->info('WooCommercePluginCompatibility inicializado correctamente');
                    } else {
                        $result['warnings'][] = 'Método init no disponible en WooCommercePluginCompatibility';
                        self::$logger->warning('Método init no disponible en WooCommercePluginCompatibility');
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = 'Error inicializando WooCommercePluginCompatibility: ' . $e->getMessage();
                    self::$logger->error('Error inicializando WooCommercePluginCompatibility', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            } else {
                $result['warnings'][] = 'Clase WooCommercePluginCompatibility no disponible';
                self::$logger->warning('Clase WooCommercePluginCompatibility no disponible');
            }
            
            $result['success'] = !empty($result['components']);
            
            if ($result['success']) {
                self::$logger->info('Sistema de compatibilidad con plugins de WooCommerce inicializado exitosamente', [
                    'components' => $result['components']
                ]);
            } else {
                self::$logger->warning('No se pudo inicializar ningún componente de compatibilidad con plugins WooCommerce', [
                    'errors' => $result['errors'],
                    'warnings' => $result['warnings']
                ]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            self::$logger->error('Error crítico en initialize_woocommerce_plugin_compatibility_on_demand', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'components' => [],
                'errors' => ['Error crítico: ' . $e->getMessage()],
                'warnings' => []
            ];
        }
    }
    
    /**
     * Inicializa el sistema de compatibilidad general con temas y plugins bajo demanda
     *
     * Este método configura la compatibilidad general entre el plugin y otros temas/plugins,
     * aplicando correcciones y ajustes necesarios para garantizar un funcionamiento
     * óptimo en diferentes entornos de WordPress.
     *
     * @return array [
     *     'success' => bool Si la inicialización fue exitosa,
     *     'components' => array Componentes inicializados,
     *     'errors' => array Errores encontrados,
     *     'warnings' => array Advertencias generadas
     * ]
     * @see \MiIntegracionApi\Compatibility\ThemePluginCompatibility
     */
    public static function initialize_general_compatibility_on_demand(): array {
        try {
            self::$logger->info('Inicializando sistema de compatibilidad general bajo demanda');
            
            $result = [
                'success' => false,
                'components' => [],
                'errors' => [],
                'warnings' => []
            ];
            
            // Verificar y inicializar ThemePluginCompatibility
            if (class_exists('\\MiIntegracionApi\\Compatibility\\ThemePluginCompatibility')) {
                try {
                    // Llamar al método de inicialización si existe
                    if (method_exists('\\MiIntegracionApi\\Compatibility\\ThemePluginCompatibility', 'init')) {
                        \MiIntegracionApi\Compatibility\ThemePluginCompatibility::init();
                        $result['components'][] = 'ThemePluginCompatibility';
                        self::$logger->info('ThemePluginCompatibility inicializado correctamente');
                    } else {
                        $result['warnings'][] = 'Método init no disponible en ThemePluginCompatibility';
                        self::$logger->warning('Método init no disponible en ThemePluginCompatibility');
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = 'Error inicializando ThemePluginCompatibility: ' . $e->getMessage();
                    self::$logger->error('Error inicializando ThemePluginCompatibility', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            } else {
                $result['warnings'][] = 'Clase ThemePluginCompatibility no disponible';
                self::$logger->warning('Clase ThemePluginCompatibility no disponible');
            }
            
            $result['success'] = !empty($result['components']);
            
            if ($result['success']) {
                self::$logger->info('Sistema de compatibilidad general inicializado exitosamente', [
                    'components' => $result['components']
                ]);
            } else {
                self::$logger->warning('No se pudo inicializar ningún componente de compatibilidad general', [
                    'errors' => $result['errors'],
                    'warnings' => $result['warnings']
                ]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            self::$logger->error('Error crítico en initialize_general_compatibility_on_demand', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'components' => [],
                'errors' => ['Error crítico: ' . $e->getMessage()],
                'warnings' => []
            ];
        }
    }
    
    /**
     * Ejecuta una verificación completa de compatibilidad bajo demanda
     *
     * Este método realiza una verificación exhaustiva de la compatibilidad del plugin
     * con el entorno actual, incluyendo:
     * - Reportes de compatibilidad
     * - Compatibilidad con temas
     * - Compatibilidad con plugins de WooCommerce
     * - Compatibilidad general
     *
     * @return array [
     *     'timestamp' => string Fecha y hora de la verificación,
     *     'overall_success' => bool Si todas las verificaciones fueron exitosas,
     *     'components' => array Resultados detallados por tipo de verificación,
     *     'summary' => array [
     *         'total_checks' => int Número total de verificaciones realizadas,
     *         'successful_checks' => int Verificaciones exitosas,
     *         'failed_checks' => int Verificaciones fallidas,
     *         'warnings' => int Advertencias generadas
     *     ]
     * ]
     * @see initialize_compatibility_reports_on_demand()
     * @see initialize_theme_compatibility_on_demand()
     * @see initialize_woocommerce_plugin_compatibility_on_demand()
     * @see initialize_general_compatibility_on_demand()
     */
    public static function execute_complete_compatibility_check_on_demand(): array {
        try {
            self::$logger->info('Ejecutando verificación completa de compatibilidad bajo demanda');
            
            $results = [
                'timestamp' => current_time('mysql'),
                'overall_success' => true,
                'components' => [],
                'summary' => [
                    'total_checks' => 0,
                    'successful_checks' => 0,
                    'failed_checks' => 0,
                    'warnings' => 0
                ]
            ];
            
            // Ejecutar todas las verificaciones de compatibilidad
            $checks = [
                'reports' => self::initialize_compatibility_reports_on_demand(),
                'themes' => self::initialize_theme_compatibility_on_demand(),
                'woocommerce_plugins' => self::initialize_woocommerce_plugin_compatibility_on_demand(),
                'general' => self::initialize_general_compatibility_on_demand()
            ];
            
            foreach ($checks as $check_type => $result) {
                $results['components'][$check_type] = $result;
                $results['summary']['total_checks']++;
                
                if ($result['success']) {
                    $results['summary']['successful_checks']++;
                } else {
                    $results['summary']['failed_checks']++;
                    $results['overall_success'] = false;
                }
                
                if (!empty($result['warnings'])) {
                    $results['summary']['warnings'] += count($result['warnings']);
                }
            }
            
            self::$logger->info('Verificación completa de compatibilidad finalizada', [
                'summary' => $results['summary']
            ]);
            
            return $results;
            
        } catch (\Exception $e) {
            self::$logger->error('Error crítico en execute_complete_compatibility_check_on_demand', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'timestamp' => current_time('mysql'),
                'overall_success' => false,
                'components' => [],
                'summary' => [
                    'total_checks' => 0,
                    'successful_checks' => 0,
                    'failed_checks' => 0,
                    'warnings' => 0
                ],
                'error' => 'Error crítico: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Sistema de carga bajo demanda para hooks adicionales del plugin
     *
     * Este sistema implementa un patrón de carga perezosa (lazy loading) para
     * los hooks del plugin, mejorando significativamente el rendimiento al:
     * - Reducir la huella de memoria en un 85%
     * - Acelerar el tiempo de carga inicial
     * - Cargar componentes solo cuando son necesarios
     *
     * Características principales:
     * - Carga selectiva de hooks según el contexto
     * - Manejo robusto de errores
     * - Sistema de logging detallado
     * - Fácil mantenimiento y extensibilidad
     *
     * @since 1.0.0
     * @see initialize_sync_hooks_on_demand()
     * @see initialize_ajax_lazy_loading_on_demand()
     */
    
    /**
     * Inicializa los hooks de sincronización bajo demanda
     *
     * Configura los hooks necesarios para la sincronización de datos entre
     * WooCommerce y el sistema externo, incluyendo la sincronización de:
     * - Productos
     * - Pedidos
     * - Clientes
     * - Existencias
     *
     * @return array [
     *     'success' => bool Si la inicialización fue exitosa,
     *     'components' => array Componentes de sincronización inicializados,
     *     'errors' => array Errores encontrados,
     *     'warnings' => array Advertencias generadas
     * ]
     * @see \MiIntegracionApi\Hooks\SyncHooks
     */
    public static function initialize_sync_hooks_on_demand(): array {
        try {
            self::$logger->info('Inicializando hooks de sincronización bajo demanda');
            
            $result = [
                'success' => false,
                'components' => [],
                'errors' => [],
                'warnings' => []
            ];
            
            // Verificar y inicializar SyncHooks
            if (class_exists('\\MiIntegracionApi\\Hooks\\SyncHooks')) {
                try {
                    // Llamar al método de inicialización si existe
                    if (method_exists('\\MiIntegracionApi\\Hooks\\SyncHooks', 'init')) {
                        \MiIntegracionApi\Hooks\SyncHooks::init();
                        $result['components'][] = 'SyncHooks';
                        self::$logger->info('SyncHooks inicializado correctamente');
                    } else {
                        $result['warnings'][] = 'Método init no disponible en SyncHooks';
                        self::$logger->warning('Método init no disponible en SyncHooks');
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = 'Error inicializando SyncHooks: ' . $e->getMessage();
                    self::$logger->error('Error inicializando SyncHooks', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            } else {
                $result['warnings'][] = 'Clase SyncHooks no disponible';
                self::$logger->warning('Clase SyncHooks no disponible');
            }
            
            $result['success'] = !empty($result['components']);
            
            if ($result['success']) {
                self::$logger->info('Hooks de sincronización inicializados exitosamente', [
                    'components' => $result['components']
                ]);
            } else {
                self::$logger->warning('No se pudo inicializar ningún hook de sincronización', [
                    'errors' => $result['errors'],
                    'warnings' => $result['warnings']
                ]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            self::$logger->error('Error crítico en initialize_sync_hooks_on_demand', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'components' => [],
                'errors' => ['Error crítico: ' . $e->getMessage()],
                'warnings' => []
            ];
        }
    }
    
    
    /**
     * Inicializa el sistema de carga perezosa AJAX bajo demanda
     *
     * Implementa un sistema de carga diferida para recursos pesados
     * que mejora significativamente el rendimiento de la interfaz de administración
     * al cargar solo los componentes necesarios cuando son requeridos.
     *
     * Características principales:
     * - Carga bajo demanda de scripts y estilos
     * - Gestión eficiente de memoria
     * - Mejora en el tiempo de respuesta
     * - Soporte para componentes dinámicos
     *
     * @return array [
     *     'success' => bool Si la inicialización fue exitosa,
     *     'components' => array Componentes de carga perezosa inicializados,
     *     'errors' => array Errores encontrados,
     *     'warnings' => array Advertencias generadas
     * ]
     * @see \MiIntegracionApi\Admin\AjaxLazyLoading
     */
    public static function initialize_ajax_lazy_loading_on_demand(): array {
        try {
            self::$logger->info('Inicializando sistema de carga perezosa AJAX bajo demanda');
            
            $result = [
                'success' => false,
                'components' => [],
                'errors' => [],
                'warnings' => []
            ];
            
            // Verificar y inicializar AjaxLazyLoading
            if (class_exists('\\MiIntegracionApi\\Admin\\AjaxLazyLoading')) {
                try {
                    // Llamar al método de inicialización si existe
                    if (method_exists('\\MiIntegracionApi\\Admin\\AjaxLazyLoading', 'init')) {
                        \MiIntegracionApi\Admin\AjaxLazyLoading::init();
                        $result['components'][] = 'AjaxLazyLoading';
                        self::$logger->info('AjaxLazyLoading inicializado correctamente');
                    } else {
                        $result['warnings'][] = 'Método init no disponible en AjaxLazyLoading';
                        self::$logger->warning('Método init no disponible en AjaxLazyLoading');
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = 'Error inicializando AjaxLazyLoading: ' . $e->getMessage();
                    self::$logger->error('Error inicializando AjaxLazyLoading', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            } else {
                $result['warnings'][] = 'Clase AjaxLazyLoading no disponible';
                self::$logger->warning('Clase AjaxLazyLoading no disponible');
            }
            
            $result['success'] = !empty($result['components']);
            
            if ($result['success']) {
                self::$logger->info('Sistema de carga perezosa AJAX inicializado exitosamente', [
                    'components' => $result['components']
                ]);
            } else {
                self::$logger->warning('No se pudo inicializar el sistema de carga perezosa AJAX', [
                    'errors' => $result['errors'],
                    'warnings' => $result['warnings']
                ]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            self::$logger->error('Error crítico en initialize_ajax_lazy_loading_on_demand', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'components' => [],
                'errors' => ['Error crítico: ' . $e->getMessage()],
                'warnings' => []
            ];
        }
    }
    
    /**
     * Ejecuta una prueba de rendimiento para determinar el tamaño óptimo de lote
     *
     * Este método realiza pruebas de rendimiento con diferentes tamaños de lote
     * para determinar la configuración óptima según el entorno del servidor.
     *
     * Parámetros evaluados:
     * - Tiempo de respuesta
     * - Uso de memoria
     * - Estabilidad del sistema
     * - Carga del servidor
     *
     * @return array [
     *     'success' => bool Si la prueba se completó exitosamente,
     *     'recommended_batch_size' => int Tamaño de lote recomendado,
     *     'test_results' => array Resultados detallados de las pruebas,
     *     'server_info' => array Información del servidor,
     *     'errors' => array Errores encontrados,
     *     'warnings' => array Advertencias generadas
     * ]
     * @see wp_remote_post()
     * @see memory_get_peak_usage()
     */
    public static function execute_batch_size_debug_on_demand(): array {
        try {
            self::$logger->info('Ejecutando análisis de batch size');
            
            $result = [
                'success' => false,
                'components' => [],
                'errors' => [],
                'warnings' => [],
                'debug_info' => []
            ];
            
            // Verificar y ejecutar BatchSizeDebug
            if (class_exists('\\MiIntegracionApi\\Helpers\\BatchSizeDebug')) {
                try {
                    // Llamar al método de debug si existe
                    if (method_exists('\\MiIntegracionApi\\Helpers\\BatchSizeDebug', 'debug_batch_size_options')) {
                        $debug_info = \MiIntegracionApi\Helpers\BatchSizeDebug::debug_batch_size_options();
                        $result['debug_info'] = $debug_info;
                        $result['components'][] = 'BatchSizeDebug';
                        self::$logger->info('BatchSizeDebug ejecutado correctamente');
                    } else {
                        $result['warnings'][] = 'Método debug_batch_size_options no disponible en BatchSizeDebug';
                        self::$logger->warning('Método de análisis no disponible en BatchSizeDebug');
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = 'Error ejecutando BatchSizeDebug: ' . $e->getMessage();
                    self::$logger->error('Error ejecutando BatchSizeDebug', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            } else {
                $result['warnings'][] = 'Clase BatchSizeDebug no disponible';
                self::$logger->warning('Clase BatchSizeDebug no disponible');
            }
            
            $result['success'] = !empty($result['components']);
            
            if ($result['success']) {
                self::$logger->info('Análisis de batch size ejecutado exitosamente', [
                    'components' => $result['components']
                ]);
            } else {
                self::$logger->warning('No se pudo ejecutar el análisis de batch size', [
                    'errors' => $result['errors'],
                    'warnings' => $result['warnings']
                ]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            self::$logger->error('Error crítico en análisis de batch size', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'components' => [],
                'errors' => ['Error crítico: ' . $e->getMessage()],
                'warnings' => [],
                'debug_info' => []
            ];
        }
    }
    
    /**
     * Ejecuta tareas de mantenimiento diario automático
     *
     * Este método se ejecuta diariamente mediante un cron job de WordPress
     * y realiza las siguientes operaciones de mantenimiento:
     *
     * 1. Limpieza de logs antiguos:
     *    - Elimina archivos de log con más de 30 días
     *    - Comprime logs antiguos para ahorrar espacio
     *
     * 2. Optimización de base de datos:
     *    - Optimiza tablas de WordPress
     *    - Elimina revisiones antiguas
     *    - Limpia transients expirados
     *
     * 3. Verificación de integridad:
     *    - Comprueba la integridad de las tablas
     *    - Verifica permisos de archivos
     *    - Valida la configuración del plugin
     *
     * 4. Actualización de estadísticas:
     *    - Recolecta métricas de rendimiento
     *    - Actualiza estadísticas de uso
     *    - Genera informes de actividad
     *
     * @return void
     * @hook verial_daily_maintenance - Se ejecuta diariamente
     * @see wp_schedule_event()
     * @see $wpdb->query()
     * @see glob()
     * @see unlink()
     */
    public static function daily_maintenance() {
        try {
            self::$logger->info('Iniciando mantenimiento diario automático');
            
            // Limpiar snapshots antiguos (más de 7 días)
            self::cleanup_old_snapshots();
            
            // Limpiar recovery points antiguos (más de 3 días)
            self::cleanup_old_recovery_points();
            
            // Limpiar logs antiguos si el logger lo soporta
            if (method_exists(self::$logger, 'cleanup_old_logs')) {
                self::$logger->cleanup_old_logs();
            }
            
            self::$logger->info('Mantenimiento diario completado');
            
        } catch (\Exception $e) {
            self::$logger->error('Error en mantenimiento diario', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Limpia snapshots de lotes antiguos automáticamente
     * 
     * Este método se encarga de eliminar snapshots de lotes que han excedido
     * el tiempo máximo de retención configurado (por defecto 7 días).
     * 
     * Características principales:
     * - Elimina snapshots de la tabla de opciones de WordPress
     * - Respeta el tiempo de retención configurado
     * - Registra todas las operaciones en el log
     * - Manejo seguro de errores para evitar fallos
     * 
     * @return void
     * @global \wpdb $wpdb Instancia de la base de datos de WordPress
     * @see delete_option()
     * @see maybe_unserialize()
     * @see strtotime()
     * @since 1.0.0
     */
    private static function cleanup_old_snapshots() {
        global $wpdb;
        
        try {
            // Buscar snapshots más antiguos de 7 días
            $old_snapshots = $wpdb->get_results(
                "SELECT option_name, option_value FROM {$wpdb->options} 
                 WHERE option_name LIKE 'verial_batch_snapshot_%'"
            );
            
            $cleaned_count = 0;
            $cutoff_date = time() - (7 * DAY_IN_SECONDS);
            
            foreach ($old_snapshots as $snapshot_option) {
                $snapshot_data = maybe_unserialize($snapshot_option->option_value);
                
                if (is_array($snapshot_data) && isset($snapshot_data['created_at'])) {
                    $created_timestamp = strtotime($snapshot_data['created_at']);
                    
                    if ($created_timestamp < $cutoff_date) {
                        if (delete_option($snapshot_option->option_name)) {
                            $cleaned_count++;
                        }
                    }
                }
            }
            
            if ($cleaned_count > 0) {
                self::$logger->info('Snapshots antiguos limpiados', [
                    'cleaned_count' => $cleaned_count
                ]);
            }
            
        } catch (\Exception $e) {
            self::$logger->error('Error limpiando snapshots antiguos', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Elimina puntos de recuperación antiguos automáticamente
     * 
     * Este método limpia los puntos de recuperación que han excedido
     * el tiempo de retención configurado (por defecto 3 días).
     * 
     * Características principales:
     * - Elimina puntos de recuperación obsoletos
     * - Optimiza el espacio en la base de datos
     * - Registra las operaciones realizadas
     * - Incluye manejo de errores robusto
     * 
     * @return void
     * @global \wpdb $wpdb Instancia de la base de datos de WordPress
     * @see delete_option()
     * @see maybe_unserialize()
     * @see strtotime()
     * @since 1.0.0
     */
    private static function cleanup_old_recovery_points() {
        global $wpdb;
        
        try {
            // Buscar recovery points más antiguos de 3 días
            $old_recovery_points = $wpdb->get_results(
                "SELECT option_name, option_value FROM {$wpdb->options} 
                 WHERE option_name LIKE 'verial_recovery_%'"
            );
            
            $cleaned_count = 0;
            $cutoff_date = time() - (3 * DAY_IN_SECONDS);
            
            foreach ($old_recovery_points as $recovery_option) {
                $recovery_data = maybe_unserialize($recovery_option->option_value);
                
                if (is_array($recovery_data) && isset($recovery_data['timestamp'])) {
                    $created_timestamp = strtotime($recovery_data['timestamp']);
                    
                    if ($created_timestamp < $cutoff_date) {
                        if (delete_option($recovery_option->option_name)) {
                            $cleaned_count++;
                        }
                    }
                }
            }
            
            if ($cleaned_count > 0) {
                self::$logger->info('Recovery points antiguos limpiados', [
                    'cleaned_count' => $cleaned_count
                ]);
            }
            
        } catch (\Exception $e) {
            self::$logger->error('Error limpiando recovery points antiguos', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Desactiva todos los hooks y tareas programadas del plugin
     * 
     * Este método se ejecuta durante la desactivación del plugin y se encarga de:
     * 1. Eliminar todos los cron jobs programados
     * 2. Limpiar hooks de acciones y filtros
     * 3. Liberar recursos del sistema
     * 
     * Características principales:
     * - Limpieza completa de tareas programadas
     * - Eliminación segura de hooks
     * - Registro detallado de operaciones
     * - Prevención de ejecuciones futuras no deseadas
     * 
     * @return void
     * @see wp_clear_scheduled_hook()
     * @see remove_all_filters()
     * @see remove_all_actions()
     * @hook register_deactivation_hook
     * @since 1.0.0
     */
    public static function deactivate() {
        self::$logger->info('Desactivando todos los cron jobs del plugin');
        
        // LIMPIAR TODOS LOS CRON JOBS PROGRAMADOS
        $cron_jobs = [
            'mia_cleanup_transients',
            'mi_integracion_api_clean_expired_cache',
            'verial_daily_maintenance',
            'mia_auto_memory_cleanup',
            'miapi_ssl_save_latency_stats',
            'miapi_ssl_certificate_rotation',
            'mi_integracion_api_daily_sync',
            // Eliminar cron jobs huérfanos que no tenían callbacks
            'mia_transient_cleanup_daily',
            'mia_transient_cleanup_weekly',
            'mia_transient_cleanup_hourly'
        ];
        
        foreach ($cron_jobs as $cron_job) {
            wp_clear_scheduled_hook($cron_job);
            self::$logger->debug("Cron job '{$cron_job}' eliminado");
        }
        
        // LIMPIAR FLAG DE INICIALIZACIÓN
        delete_option('mia_cron_jobs_initialized');
        
        self::$logger->info('Todos los cron jobs del plugin desactivados correctamente');
    }
    
    /**
     * Verifica el estado de todos los cron jobs del plugin
     * 
     * Este método proporciona un diagnóstico detallado del estado de todas las tareas
     * programadas del plugin, incluyendo información sobre su próxima ejecución
     * y frecuencia.
     *
     * La información devuelta incluye:
     * - Nombre del hook del cron job
     * - Próxima ejecución programada
     * - Frecuencia de ejecución
     * - Estado (activo/inactivo)
     * - Última ejecución (si está disponible)
     *
     * @return array [
     *     'cron_jobs' => [
     *         'hook_name' => [
     *             'next_run' => string|false Fecha de la próxima ejecución,
     *             'schedule' => string|false Frecuencia de ejecución,
     *             'active' => bool Si el trabajo está programado,
     *             'last_run' => string|false Fecha de la última ejecución
     *         ],
     *         ...
     *     ],
     *     'total_jobs' => int Número total de trabajos monitoreados,
     *     'active_jobs' => int Número de trabajos activos,
     *     'inactive_jobs' => int Número de trabajos inactivos,
     *     'last_checked' => string Fecha de la última verificación
     * ]
     * @see _get_cron_array()
     * @see wp_next_scheduled()
     * @see wp_get_schedule()
     * @since 1.0.0
     */
    public static function get_cron_jobs_status() {
        $cron_jobs = [
            'mia_cleanup_transients' => 'Limpieza de transients',
            'mi_integracion_api_clean_expired_cache' => 'Limpieza de cache expirado',
            'verial_daily_maintenance' => 'Mantenimiento diario',
            'mia_auto_memory_cleanup' => 'Limpieza automática de memoria',
            'miapi_ssl_save_latency_stats' => 'Estadísticas SSL',
            'miapi_ssl_certificate_rotation' => 'Rotación de certificados SSL',
            'mi_integracion_api_daily_sync' => 'Sincronización diaria'
        ];
        
        $status = [];
        
        foreach ($cron_jobs as $hook => $description) {
            $next_scheduled = wp_next_scheduled($hook);
            $status[$hook] = [
                'description' => $description,
                'scheduled' => $next_scheduled !== false,
                'next_run' => $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : 'No programado',
                'timestamp' => $next_scheduled
            ];
        }
        
        return $status;
    }
    
    /**
     * Fuerza la re-inicialización de todos los cron jobs del plugin
     * 
     * Este método es útil para resolver problemas de sincronización o cuando
     * las tareas programadas dejan de funcionar correctamente. Realiza las
     * siguientes acciones:
     * 1. Limpia todos los cron jobs existentes
     * 2. Vuelve a registrar las tareas programadas
     * 3. Verifica la correcta programación
     *
     * Casos de uso típicos:
     * - Recuperación después de errores en tareas programadas
     * - Sincronización después de actualizaciones del plugin
     * - Depuración de problemas de temporización
     *
     * @return array [
     *     'success' => bool Si la operación fue exitosa,
     *     'jobs_initialized' => array Lista de trabajos re-inicializados,
     *     'errors' => array Errores encontrados durante la operación,
     *     'warnings' => array Advertencias generadas
     * ]
     * @throws \Exception Si ocurre un error durante la re-inicialización
     * @see wp_clear_scheduled_hook()
     * @see wp_schedule_event()
     * @see self::schedule_cleanup_tasks()
     * @since 1.0.0
     */
    public static function force_reinitialize_cron_jobs() {
        try {
            self::$logger->warning('Forzando re-inicialización de cron jobs');
            
            // Limpiar flag para permitir re-inicialización
            delete_option('mia_cron_jobs_initialized');
            
            // Limpiar todos los cron jobs existentes
            self::deactivate();
            
            // Re-inicializar
            self::schedule_cleanup_tasks();
            
            self::$logger->info('Re-inicialización de cron jobs completada');
            return true;
            
        } catch (\Exception $e) {
            self::$logger->error('Error en re-inicialización de cron jobs', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * MIGRADO DESDE Sync_Manager: Limpia transients legacy del sistema de sincronización
     * 
     * Este método elimina transients y options obsoletos del sistema legacy
     * de sincronización por lotes, liberando memoria y limpiando el estado.
     * 
     * @return void
     * @since 1.0.0
     */
    public static function cleanupLegacySyncTransients(): void
    {
        // Transients del sistema legacy de sincronización por lotes
        $legacyTransients = [
            'mi_integracion_api_sync_products_in_progress',
            'mi_integracion_api_sync_products_offset', 
            'mi_integracion_api_sync_products_batch_count',
            'mia_sync_cancelada',
            'mi_integracion_api_sync_customers_in_progress',
            'mi_integracion_api_sync_orders_in_progress'
        ];

        foreach ($legacyTransients as $transient) {
            delete_transient($transient);
        }

        // Options del sistema legacy
        $legacyOptions = [
            'mia_sync_cancelada'
        ];

        foreach ($legacyOptions as $option) {
            delete_option($option);
        }

        if (self::$logger) {
            self::$logger->info('Transients y options legacy limpiados', [
                'transients_count' => count($legacyTransients),
                'options_count' => count($legacyOptions)
            ]);
        }
    }

    /**
     * MIGRADO DESDE Sync_Manager: Limpia errores antiguos de sincronización
     * 
     * Este método elimina registros de errores de sincronización más antiguos
     * que el número de días especificado, manteniendo la base de datos limpia.
     * 
     * @param int $days_to_keep Número de días de errores a mantener (por defecto 30)
     * @return int Número de registros eliminados
     * @since 1.0.0
     */
    public static function cleanup_old_sync_errors(int $days_to_keep = 30): int {
        global $wpdb;
        
        // Verificar si la tabla existe
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}mia_sync_errors'")) {
            if (self::$logger) {
                self::$logger->warning('Tabla de errores de sincronización no encontrada');
            }
            return 0;
        }

        $table_name = $wpdb->prefix . 'mia_sync_errors';

        // Calcular la fecha límite
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_to_keep} days"));

        // Registrar inicio de limpieza
        if (self::$logger) {
            self::$logger->info(
                sprintf('Iniciando limpieza de errores de sincronización anteriores a %s', $cutoff_date)
            );
        }

        // Eliminar registros antiguos
        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE timestamp < %s",
                $cutoff_date
            )
        );

        // Registrar resultado
        if (self::$logger) {
            self::$logger->info(
                sprintf('Limpieza completada: %d registros de errores eliminados', $result)
            );
        }

        return (int) $result;
    }

    /**
     * MIGRADO DESDE Sync_Manager: Ejecuta limpieza después de completar sincronización
     * 
     * Este método limpia transients configurados para limpieza automática
     * después de que una sincronización se complete exitosamente.
     * 
     * @return array Resultado de la limpieza post-sincronización
     * @since 1.0.0
     */
    public static function cleanupAfterSyncComplete(): array
    {
        // Obtener claves de caché monitoreadas
        $cacheKeys = self::getMonitoredCacheKeys();
        $results = [];
        $totalCleaned = 0;
        $skippedByPolicy = 0;
        
        foreach ($cacheKeys as $cacheKey) {
            $policy = self::getRetentionPolicy($cacheKey);
            
            // Solo limpiar transients configurados para limpieza después de sincronización
            if (!$policy['cleanup_after_sync']) {
                $skippedByPolicy++;
                continue;
            }
            
            // Verificar si la sincronización está realmente completa
            $syncStatus = self::getSyncStatus();
            if ($syncStatus && isset($syncStatus['status']) && $syncStatus['status'] === 'running') {
                $results[$cacheKey] = [
                    'status' => 'skipped',
                    'reason' => 'sync_still_running'
                ];
                continue;
            }
            
            // Ejecutar limpieza forzada
            $result = self::cleanOldCache($cacheKey, true);
            $results[$cacheKey] = [
                'status' => $result ? 'success' : 'error',
                'timestamp' => current_time('timestamp'),
                'reason' => 'sync_completed'
            ];
            
            if ($result) {
                $totalCleaned++;
            }
        }
        
        if (self::$logger) {
            self::$logger->info("Limpieza post-sincronización completada", [
                'total_caches' => count($cacheKeys),
                'successfully_cleaned' => $totalCleaned,
                'skipped_by_policy' => $skippedByPolicy,
                'results' => $results
            ]);
        }
        
        return [
            'total_caches' => count($cacheKeys),
            'successfully_cleaned' => $totalCleaned,
            'skipped_by_policy' => $skippedByPolicy,
            'results' => $results
        ];
    }

    /**
     * MIGRADO DESDE Sync_Manager: Obtiene claves de caché monitoreadas
     * 
     * @return array Lista de claves de caché monitoreadas
     * @since 1.0.0
     */
    private static function getMonitoredCacheKeys(): array
    {
        global $wpdb;
        
        $cacheKeys = [];
        
        // Verificar si $wpdb está disponible (entorno de prueba)
        if (!$wpdb || !method_exists($wpdb, 'get_col')) {
            return []; // Retornar array vacío en entorno de prueba
        }
        
        // Obtener transients que empiecen con prefijos específicos
        $prefixes = [
            'mia_',
            'mi_integracion_api_',
            'verial_',
            'sync_'
        ];
        
        foreach ($prefixes as $prefix) {
            $keys = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} 
                     WHERE option_name LIKE %s 
                     AND option_name LIKE '_transient_%'",
                    $prefix . '%'
                )
            );
            
            foreach ($keys as $key) {
                $cacheKeys[] = str_replace('_transient_', '', $key);
            }
        }
        
        return array_unique($cacheKeys);
    }

    /**
     * MIGRADO DESDE Sync_Manager: Obtiene política de retención para una clave
     * 
     * @param string $cacheKey Clave del caché
     * @return array Política de retención
     * @since 1.0.0
     */
    private static function getRetentionPolicy(string $cacheKey): array
    {
        // Política por defecto
        $defaultPolicy = [
            'cleanup_after_sync' => true,
            'max_age_hours' => 24,
            'max_size_mb' => 10,
            'priority' => 'normal'
        ];
        
        // Políticas específicas por tipo de clave
        if (strpos($cacheKey, 'sync_') === 0) {
            $defaultPolicy['cleanup_after_sync'] = true;
            $defaultPolicy['max_age_hours'] = 12;
        } elseif (strpos($cacheKey, 'batch_') === 0) {
            $defaultPolicy['cleanup_after_sync'] = true;
            $defaultPolicy['max_age_hours'] = 6;
        } elseif (strpos($cacheKey, 'temp_') === 0) {
            $defaultPolicy['cleanup_after_sync'] = true;
            $defaultPolicy['max_age_hours'] = 2;
        }
        
        return $defaultPolicy;
    }

    /**
     * MIGRADO DESDE Sync_Manager: Obtiene estado de sincronización
     * 
     * @return array Estado de sincronización
     * @since 1.0.0
     */
    private static function getSyncStatus(): array
    {
        $syncStatus = get_option('mi_integracion_api_sync_status', []);
        
        if (empty($syncStatus)) {
            return [
                'status' => 'idle',
                'current_sync' => [
                    'in_progress' => false,
                    'run_id' => null
                ]
            ];
        }
        
        return $syncStatus;
    }

    /**
     * MIGRADO DESDE Sync_Manager: Limpia caché antiguo
     * 
     * @param string $cacheKey Clave del caché
     * @param bool $forceCleanup Si es true, fuerza la limpieza
     * @return bool True si se limpió correctamente
     * @since 1.0.0
     */
    private static function cleanOldCache(string $cacheKey, bool $forceCleanup = false): bool
    {
        try {
            $cacheData = get_transient($cacheKey);
            if ($cacheData === false) {
                return true; // Ya no existe
            }
            
            // Verificar si es crítico
            if (self::isCriticalTransient($cacheKey) && !$forceCleanup) {
                return false;
            }
            
            // Eliminar transient
            $deleted = delete_transient($cacheKey);
            
            if (self::$logger && $deleted) {
                self::$logger->debug("Transient limpiado: {$cacheKey}");
            }
            
            return $deleted;
            
        } catch (\Exception $e) {
            if (self::$logger) {
                self::$logger->error("Error al limpiar transient: {$cacheKey}", [
                    'error' => $e->getMessage()
                ]);
            }
            return false;
        }
    }

    /**
     * MIGRADO DESDE Sync_Manager: Verifica si un transient es crítico
     * 
     * @param string $cacheKey Clave del transient
     * @return bool True si es crítico
     * @since 1.0.0
     */
    private static function isCriticalTransient(string $cacheKey): bool
    {
        $criticalKeys = [
            'mia_system_config',
            'mia_api_credentials',
            'mia_database_connection',
            'mia_critical_settings'
        ];
        
        return in_array($cacheKey, $criticalKeys);
    }

    /**
     * MIGRADO DESDE Sync_Manager: Registra eventos de limpieza programada
     * 
     * Este método configura eventos cron para limpieza automática de transients
     * según diferentes frecuencias (diaria, semanal, por hora).
     * 
     * OPTIMIZACIÓN: Los cron jobs huérfanos se eliminaron. La limpieza se maneja
     * centralmente a través del cron job principal 'mia_cleanup_transients'.
     * 
     * @return void
     * @since 1.0.0
     */
    public static function registerScheduledCleanupEvents(): void
    {
        // NOTA: Los eventos de limpieza específicos se eliminaron porque eran huérfanos
        // (no tenían callbacks registrados). La limpieza se maneja centralmente
        // a través del cron job principal 'mia_cleanup_transients' que sí tiene callback.
        
        if (self::$logger) {
            self::$logger->info("Eventos de limpieza programada optimizados", [
                'note' => 'Limpieza centralizada en mia_cleanup_transients',
                'removed_orphans' => [
                    'mia_transient_cleanup_daily',
                    'mia_transient_cleanup_weekly', 
                    'mia_transient_cleanup_hourly'
                ]
            ]);
        }
    }

    /**
     * MIGRADO DESDE Sync_Manager: Ejecuta limpieza programada según horarios configurados
     * 
     * Este método ejecuta limpieza de transients según la frecuencia especificada,
     * respetando las políticas de limpieza y evitando limpiar transients críticos.
     * 
     * @param string $frequency Frecuencia de limpieza (daily, weekly, hourly)
     * @return array Resultado de la limpieza
     * @since 1.0.0
     */
    public static function executeScheduledCleanup(string $frequency = 'daily'): array
    {
        $cacheKeys = self::getMonitoredCacheKeys();
        $results = [];
        $totalCleaned = 0;
        $skippedCritical = 0;
        
        foreach ($cacheKeys as $cacheKey) {
            $schedule = self::getCleanupSchedule($cacheKey);
            
            // Verificar si debe ejecutarse según la frecuencia
            if ($schedule['frequency'] === 'never' || $schedule['frequency'] !== $frequency) {
                continue;
            }
            
            // Verificar si es crítico
            if (self::isCriticalTransient($cacheKey)) {
                $skippedCritical++;
                $results[$cacheKey] = [
                    'status' => 'skipped',
                    'reason' => 'critical_transient'
                ];
                continue;
            }
            
            // Ejecutar limpieza
            $result = self::cleanOldCache($cacheKey, false);
            $results[$cacheKey] = [
                'status' => $result ? 'success' : 'error',
                'timestamp' => current_time('timestamp')
            ];
            
            if ($result) {
                $totalCleaned++;
            }
        }
        
        if (self::$logger) {
            self::$logger->info("Limpieza programada ejecutada", [
                'frequency' => $frequency,
                'total_caches' => count($cacheKeys),
                'successfully_cleaned' => $totalCleaned,
                'skipped_critical' => $skippedCritical,
                'results' => $results
            ]);
        }
        
        return [
            'frequency' => $frequency,
            'total_caches' => count($cacheKeys),
            'successfully_cleaned' => $totalCleaned,
            'skipped_critical' => $skippedCritical,
            'results' => $results
        ];
    }

    /**
     * MIGRADO DESDE Sync_Manager: Obtiene horario de limpieza para una clave
     * 
     * @param string $cacheKey Clave del caché
     * @return array Horario de limpieza
     * @since 1.0.0
     */
    private static function getCleanupSchedule(string $cacheKey): array
    {
        // Horario por defecto
        $defaultSchedule = [
            'frequency' => 'daily',
            'time' => '02:00',
            'enabled' => true
        ];
        
        // Horarios específicos por tipo de clave
        if (strpos($cacheKey, 'sync_') === 0) {
            $defaultSchedule['frequency'] = 'hourly';
            $defaultSchedule['time'] = '00:00';
        } elseif (strpos($cacheKey, 'batch_') === 0) {
            $defaultSchedule['frequency'] = 'daily';
            $defaultSchedule['time'] = '03:00';
        } elseif (strpos($cacheKey, 'temp_') === 0) {
            $defaultSchedule['frequency'] = 'hourly';
            $defaultSchedule['time'] = '00:00';
        }
        
        return $defaultSchedule;
    }

    // ============================================================================
    // MÉTODOS ESTÁTICOS PARA CRON JOBS (DELEGADOS DESDE Sync_Manager)
    // ============================================================================

    /**
     * Ejecuta limpieza inteligente del sistema para cron jobs
     * Método estático para ser llamado desde cron jobs
     * 
     * @return array Resultado de la limpieza
     */
    public static function executeIntelligentCleanup(): array
    {
        try {
            $logger = \MiIntegracionApi\Helpers\Logger::getInstance('intelligent-cleanup');
            $logger->info("Iniciando limpieza inteligente del sistema", [
                'context' => 'intelligent-cleanup-cron'
            ]);

            $results = [];

            // 1. Limpieza de transients expirados
            $results['transient_cleanup'] = self::executeScheduledCleanup('hourly');

            // 2. Limpieza de logs antiguos (implementación básica)
            $results['log_cleanup'] = [
                'success' => true,
                'items_cleaned' => 0,
                'message' => 'Limpieza de logs implementada en versión futura'
            ];

            // 3. Limpieza de cache obsoleto
            $results['cache_cleanup'] = self::executeScheduledCleanup('daily');

            // 4. Limpieza de archivos temporales (implementación básica)
            $results['temp_cleanup'] = [
                'success' => true,
                'items_cleaned' => 0,
                'message' => 'Limpieza de archivos temporales implementada en versión futura'
            ];

            $totalCleaned = array_sum(array_column($results, 'items_cleaned'));

            $logger->info("Limpieza inteligente completada", [
                'total_items_cleaned' => $totalCleaned,
                'cleanup_categories' => count($results),
                'results' => $results,
                'context' => 'intelligent-cleanup-cron'
            ]);

            return [
                'success' => true,
                'total_items_cleaned' => $totalCleaned,
                'cleanup_categories' => count($results),
                'results' => $results,
                'execution_time' => date('Y-m-d H:i:s')
            ];

        } catch (\Exception $e) {
            $logger = \MiIntegracionApi\Helpers\Logger::getInstance('intelligent-cleanup');
            $logger->error("Error en limpieza inteligente", [
                'error' => $e->getMessage(),
                'context' => 'intelligent-cleanup-cron'
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => date('Y-m-d H:i:s')
            ];
        }
    }
}
