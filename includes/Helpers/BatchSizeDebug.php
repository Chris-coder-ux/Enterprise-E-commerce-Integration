<?php declare(strict_types=1);
/**
 * Archivo para debug del tamaño de lote
 * Este archivo se incluye temporalmente para monitorear las opciones de batch_size
 * 
 * @package MiIntegracionApi
 * @subpackage Helpers
 */

namespace MiIntegracionApi\Helpers;

defined('ABSPATH') || exit;

/**
 * Clase para debug y monitoreo del tamaño de lote en la integración.
 *
 * Permite activar el diagnóstico de problemas relacionados con el batch size (tamaño de lote) de la integración,
 * tanto mediante constantes, opciones en la base de datos o el panel de administración de WordPress.
 *
 * Para activar el modo debug en producción, añade en wp-config.php:
 *   define('MIA_DEBUG_BATCH_SIZE', true);
 *
 * @package MiIntegracionApi
 * @subpackage Helpers
 */
class BatchSizeDebug {
     /**
      * Comprueba si el modo de debug de batch size está activado.
      *
      * El modo debug puede activarse si:
      * - WP_DEBUG está activo
      * - La constante MIA_DEBUG_BATCH_SIZE está definida y activa
      * - La opción 'mi_integracion_api_debug_batch_size' está habilitada en la base de datos
      *
      * @return bool True si el modo debug está activo, false en caso contrario.
      */
     public static function is_debug_mode() {
        // Activar si WP_DEBUG está activado
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }
        
        // Activar si la constante específica está definida
        if (defined('MIA_DEBUG_BATCH_SIZE') && MIA_DEBUG_BATCH_SIZE) {
            return true;
        }
        
        // Activar si la opción en la base de datos está habilitada
        if (get_option('mi_integracion_api_debug_batch_size', false)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Registra la opción de configuración para activar/desactivar el debug desde el panel de administración.
     *
     * Añade un campo de checkbox en la sección avanzada de la configuración del plugin.
     * Solo accesible para administradores.
     *
     * @return void
     */
    public static function register_debug_settings() {
        // Solo para administradores
        if (!current_user_can('manage_options')) {
            return;
        }
        
        register_setting(
            'mi_integracion_api_settings',
            'mi_integracion_api_debug_batch_size',
            [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => function($value) {
                    return (bool) $value;
                }
            ]
        );
        
        // Añadir campo a la sección de configuración avanzada
        add_settings_field(
            'mi_integracion_api_debug_batch_size',
            __('Diagnóstico de tamaño de lote', 'mi-integracion-api'),
            function() {
                $debug_active = get_option('mi_integracion_api_debug_batch_size', false);
                echo '<input type="checkbox" name="mi_integracion_api_debug_batch_size" value="1" ' . checked(1, $debug_active, false) . ' id="mi_integracion_api_debug_batch_size" />';
                echo '<label for="mi_integracion_api_debug_batch_size">' . __('Activar monitoreo y diagnóstico del tamaño de lote', 'mi-integracion-api') . '</label>';
                echo '<p class="description">' . __('Activa el diagnóstico para solucionar problemas de sincronización. Puede afectar ligeramente al rendimiento.', 'mi-integracion-api') . '</p>';
            },
            'mi_integracion_api_settings',
            'mi_integracion_api_advanced_section' // Asegúrate de que esta sección exista
        );
    }
    
    /**
     * Inicializa los hooks y acciones necesarios para el debug del batch size.
     *
     * Registra los hooks para monitorear cambios, registrar logs y verificar consistencia de las opciones.
     *
     * @return void
     */
    public static function init() {
        // Registrar siempre la configuración en el panel de administración
        add_action('admin_init', [self::class, 'register_debug_settings']);
        
        // Solo activar el monitoreo si el modo debug está habilitado
        if (!self::is_debug_mode()) {
            return;
        }
        
        // Añadir evento para monitorear opciones de batch_size
        add_action('updated_option', [self::class, 'monitor_batch_size_option'], 10, 3);
        
        // Hook para verificar las solicitudes POST con batch_size
        add_action('admin_init', [self::class, 'check_post_data']);
        
        // Registrar el estado inicial al inicio del plugin
        add_action('plugins_loaded', [self::class, 'log_all_batch_size_options'], 999);
        
        // Verificar consistencia al cargar el plugin
        add_action('plugins_loaded', [self::class, 'verify_batch_size_consistency'], 1000);
    }
    
    /**
     * Monitorea los cambios en las opciones relacionadas con el batch size.
     *
     * Si se detecta un cambio en alguna de las opciones relevantes, registra el evento y verifica la consistencia.
     *
     * @param string $option     Nombre de la opción modificada.
     * @param mixed  $old_value  Valor anterior de la opción.
     * @param mixed  $new_value  Nuevo valor de la opción.
     * @return void
     */
    public static function monitor_batch_size_option($option, $old_value, $new_value) {
        // Verificar si estamos en modo debug completo
        $full_debug = defined('MIA_DEBUG_BATCH_SIZE') && MIA_DEBUG_BATCH_SIZE;
        
        // Lista de opciones a monitorear
        $batch_size_options = [
            'mi_integracion_api_batch_size_productos',
            'mi_integracion_api_batch_size_products',
            'mi_integracion_api_batch_size',
            'mi_integracion_api_optimal_batch_size',
            'mia_current_batch_size_products'
        ];
        
        if (in_array($option, $batch_size_options)) {
            // Log detallado del cambio (solo incluir información extendida en modo debug completo)
            $debug_info = [
                'option' => $option,
                'old_value' => $old_value,
                'new_value' => $new_value,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            // Añadir información extendida solo en modo debug completo
            if ($full_debug) {
                $debug_info['backtrace'] = self::get_limited_backtrace();
                $debug_info['request'] = [
                    'ajax' => defined('DOING_AJAX') && DOING_AJAX,
                    'endpoint' => $_SERVER['REQUEST_URI'] ?? 'N/A',
                    'post_data' => isset($_POST['batch_size']) ? ['batch_size' => $_POST['batch_size']] : []
                ];
            }
            
            // Usar Logger si está disponible o error_log como fallback
            if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
                $logger = new Logger('batch-size-debug');
                $logger->info(sprintf('📊 Cambio en opción %s: %s → %s', $option, $old_value, $new_value), $debug_info);
            } else {
                // ...
            }
            
            // Verificar la consistencia después del cambio
            self::verify_batch_size_consistency();
        }
    }
    
    /**
     * Verifica y registra los datos POST relacionados con batch size enviados desde la interfaz de usuario.
     *
     * Permite detectar cambios manuales o automáticos en el batch size desde el panel de administración.
     *
     * @return void
     */
    public static function check_post_data() {
        // Verificar si estamos en modo debug detallado
        $detailed_logging = defined('MIA_DEBUG_BATCH_SIZE') && MIA_DEBUG_BATCH_SIZE;
        
        if (isset($_POST) && !empty($_POST)) {
            if (isset($_POST['batch_size']) || isset($_POST['mi_integracion_api_batch_size'])) {
                // Solo loguear información detallada en modo debug
                if ($detailed_logging) {
                    // ...
                    
                    foreach ($_POST as $key => $value) {
                        if (strpos($key, 'batch_size') !== false || strpos($key, 'lote') !== false) {
                            // ...
                        }
                    }
                }
                
                // Forzar sincronización si se detecta un cambio (esto siempre se ejecuta)
                if (isset($_POST['batch_size'])) {
                    $batch_size = (int)$_POST['batch_size'];
                    
                    if ($detailed_logging) {
                        // ...
                    }
                    
                    // Esta función siempre se ejecuta para mantener la consistencia
                    // DESACTIVADO PARA PERMITIR VALORES PERSONALIZADOS: BatchSizeHelper::syncAllBatchSizeOptions('productos', $batch_size);
                }
            }
        }
    }
    
    /**
     * Verifica y corrige la consistencia entre todas las opciones de batch size.
     *
     * Compara los valores de todas las opciones relevantes y, si detecta discrepancias,
     * sincroniza todas con el valor correcto (priorizando el helper_value).
     *
     * @return void
     */
    public static function verify_batch_size_consistency() {
        // Verificar si estamos en modo debug completo
        $detailed_logging = defined('MIA_DEBUG_BATCH_SIZE') && MIA_DEBUG_BATCH_SIZE;
        
        $options = [
            'mi_integracion_api_batch_size_productos' => get_option('mi_integracion_api_batch_size_productos'),
            'mi_integracion_api_batch_size_products' => get_option('mi_integracion_api_batch_size_products'),
            'mi_integracion_api_batch_size' => get_option('mi_integracion_api_batch_size'),
            'mi_integracion_api_optimal_batch_size' => get_option('mi_integracion_api_optimal_batch_size'),
            'mia_current_batch_size_products' => get_option('mia_current_batch_size_products'),
            'helper_value' => BatchSizeHelper::getBatchSize('productos')
        ];
        
        // Verificar si todos los valores son iguales
        $first_value = reset($options);
        $all_equal = true;
        $non_matching = [];
        
        foreach ($options as $key => $value) {
            if ($value != $first_value) {
                $all_equal = false;
                $non_matching[$key] = $value;
            }
        }
        
        // Si hay inconsistencia, intentar corregirla automáticamente
        if (!$all_equal) {
            // Determinar el valor correcto a usar (priorizar helper_value)
            $correct_value = $options['helper_value'] ?: $first_value;
            
            // Sincronizar todas las opciones con el valor correcto
            BatchSizeHelper::syncAllBatchSizeOptions('productos', $correct_value);
            
            if ($detailed_logging) {
                // ...
                    // ...
            }
        }
        
        // Log del resultado solo si estamos en modo debug o hay inconsistencias
        if ($detailed_logging || !$all_equal) {
            if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
                $logger = new Logger('batch-size-debug');
                $logger->info(
                    $all_equal 
                        ? '✅ Todas las opciones de batch_size están sincronizadas: ' . $first_value 
                        : '❌ Inconsistencia en opciones de batch_size (corrigiendo)', 
                    [
                        'options' => $options, 
                        'non_matching' => $non_matching,
                        'correcting_to' => $correct_value
                    ]
                );
            } else {
                // ...
                    // ...
                
                if ($detailed_logging) {
                    // ...
                }
            }
        }
    }
    
    /**
     * Registra y verifica los valores de todas las opciones de batch size al iniciar el plugin.
     *
     * Si detecta inconsistencias, las corrige automáticamente y registra el estado inicial.
     *
     * @global \wpdb $wpdb
     * @return void
     */
    public static function log_all_batch_size_options() {
        global $wpdb;
        
        // Verificar si estamos en modo debug detallado
        $detailed_logging = defined('MIA_DEBUG_BATCH_SIZE') && MIA_DEBUG_BATCH_SIZE;
        
        $options = [
            'mi_integracion_api_batch_size_productos' => get_option('mi_integracion_api_batch_size_productos'),
            'mi_integracion_api_batch_size_products' => get_option('mi_integracion_api_batch_size_products'),
            'mi_integracion_api_batch_size' => get_option('mi_integracion_api_batch_size'),
            'mi_integracion_api_optimal_batch_size' => get_option('mi_integracion_api_optimal_batch_size'),
            'mia_current_batch_size_products' => get_option('mia_current_batch_size_products'),
            'helper_value' => BatchSizeHelper::getBatchSize('productos')
        ];
        
        // Verificar consistencia y corregir al iniciar
        $first_value = reset($options);
        $all_equal = true;
        
        foreach ($options as $value) {
            if ($value != $first_value) {
                $all_equal = false;
                break;
            }
        }
        
        // Si hay inconsistencia, corregirla inmediatamente
        if (!$all_equal) {
            // Determinar el valor correcto a usar (priorizar helper_value o el primero no nulo)
            $correct_value = $options['helper_value'] ?: null;
            
            if (!$correct_value) {
                foreach ($options as $value) {
                    if ($value) {
                        $correct_value = $value;
                        break;
                    }
                }
            }
            
            // Si no se encontró ningún valor, usar el valor por defecto
            if (!$correct_value) {
                $correct_value = BatchSizeHelper::DEFAULT_BATCH_SIZES['productos'];
            }
            
            // Sincronizar todas las opciones con el valor correcto
            BatchSizeHelper::syncAllBatchSizeOptions('productos', $correct_value);
            
            if ($detailed_logging || !defined('WP_DEBUG') || WP_DEBUG) {
                // ...
                    // ...
            }
            
            // Actualizar el array de opciones con los nuevos valores sincronizados
            foreach ($options as $key => &$value) {
                if ($key !== 'helper_value') {
                    $value = $correct_value;
                }
            }
        }
        
        // Log del resultado solo si estamos en modo debug
        if ($detailed_logging) {
            if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
                $logger = new Logger('batch-size-debug');
                $logger->info('🔍 Estado inicial de opciones de batch_size', [
                    'options' => $options,
                    'consistency_check' => $all_equal ? 'Consistente' : 'Inconsistencia corregida'
                ]);
            } else {
                // ...
            }
            
            // Consulta directa a la base de datos solo en modo debug detallado
            $db_options = $wpdb->get_results(
                "SELECT * FROM {$wpdb->options} WHERE option_name LIKE '%batch_size%' OR option_name LIKE '%lote%'"
            );
            
            if ($db_options && class_exists('\MiIntegracionApi\Helpers\Logger')) {
                $logger = new Logger('batch-size-debug');
                $logger->info('🔍 Todas las opciones relacionadas con batch_size en la base de datos', ['db_options' => $db_options]);
            }
        }
    }
    
    /**
     * Obtiene una versión limitada del backtrace para propósitos de debugging.
     *
     * Devuelve un array con información básica de las últimas llamadas relevantes.
     *
     * @return array[] Array de arrays con información de archivo, línea, función y clase.
     */
    private static function get_limited_backtrace() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        $filtered_trace = [];
        
        foreach ($trace as $item) {
            $filtered_trace[] = [
                'file' => isset($item['file']) ? basename($item['file']) : 'N/A',
                'line' => $item['line'] ?? 'N/A',
                'function' => $item['function'] ?? 'N/A',
                'class' => $item['class'] ?? 'N/A'
            ];
        }
        
        return $filtered_trace;
    }
}

// Inicializar los hooks de debug
BatchSizeDebug::init();
