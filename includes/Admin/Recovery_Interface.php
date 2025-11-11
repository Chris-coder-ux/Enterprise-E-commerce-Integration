<?php

declare(strict_types=1);

/**
 * Ejemplo de integración del sistema de recuperación en la interfaz administrativa
 * 
 * Este archivo ilustra cómo se puede integrar el sistema de recuperación
 * en la interfaz administrativa de WordPress.
 * 
 * @package MiIntegracionApi\Admin\Examples
 */

namespace MiIntegracionApi\Admin;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

/**
 * Clase de ejemplo para la interfaz administrativa de recuperación
 * 
 * Esta clase proporciona una interfaz para gestionar puntos de recuperación
 * y reanudar sincronizaciones interrumpidas en el sistema de integración.
 * 
 * @package MiIntegracionApi\Admin
 * @since 1.0.0
 */
class Recovery_Interface {
    /**
     * Constructor de la clase
     * 
     * Inicializa los hooks de WordPress necesarios para la funcionalidad
     * de recuperación de sincronizaciones.
     * 
     * @since 1.0.0
     * @return void
     */
    public function __construct() {
        // Añadir mensaje de recuperación en el panel principal
        add_action('mi_integracion_api_admin_before_sync', array($this, 'mostrar_mensaje_recuperacion'));
        
        // Añadir opciones de recuperación en el panel de herramientas
        add_action('mi_integracion_api_admin_tools', array($this, 'mostrar_opciones_recuperacion'));
        
        // Procesar los formularios
        add_action('admin_init', array($this, 'procesar_formularios'));
    }
    
    /**
     * Muestra mensaje de recuperación si hay una sincronización pendiente
     * 
     * Verifica si existe una sincronización interrumpida y muestra un mensaje
     * con opciones para reanudar o reiniciar la sincronización.
     * 
     * @since 1.0.0
     * @return void
     */
    public function mostrar_mensaje_recuperacion(): void {
        // Verificar si hay una sincronización pendiente
    $coreRouting = defined('MIA_USE_CORE_SYNC') ? (bool) constant('MIA_USE_CORE_SYNC') : (bool) get_option('mia_use_core_sync', true);
        if (function_exists('apply_filters')) { $coreRouting = (bool) apply_filters('mia_use_core_sync_routing', $coreRouting); }
        if ($coreRouting && class_exists('MiIntegracionApi\\Core\\Sync_Manager')) {
            $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
            $pending_sync = method_exists($sync_manager, 'check_pending_sync_legacy') ? $sync_manager->check_pending_sync_legacy() : $sync_manager->getSyncStatus();
            // Adaptar si usamos getSyncStatus en fallback
            if ($pending_sync && isset($pending_sync['current_sync']) && ($pending_sync['current_sync']['in_progress'] ?? false)) {
                // Construir estructura legacy mínima
                $pending_sync = [
                    'entity' => $pending_sync['current_sync']['entity'] ?? 'productos',
                    'message' => __('Sincronización en curso', 'mi-integracion-api'),
                    'progress' => [
                        'percentage' => $pending_sync['current_sync']['progress'] ?? 0,
                        'processed' => $pending_sync['current_sync']['items_synced'] ?? 0,
                        'total' => $pending_sync['current_sync']['total_items'] ?? 0,
                        'last_batch' => $pending_sync['current_sync']['current_batch'] ?? 0,
                        'date' => date_i18n('Y-m-d H:i:s')
                    ]
                ];
            }
        } else {
            $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
            $pending_sync = $sync_manager->check_pending_sync();
        }
        
        if (!$pending_sync) {
            return; // No hay sincronización pendiente
        }
        
        $entity_labels = [
            'productos' => __('productos', 'mi-integracion-api'),
            'clientes' => __('clientes', 'mi-integracion-api'),
            'pedidos' => __('pedidos', 'mi-integracion-api'),
        ];
        
        $entity_label = (is_array($pending_sync) && isset($pending_sync['entity']) && isset($entity_labels[$pending_sync['entity']])) ? 
                        $entity_labels[$pending_sync['entity']] : 
                        (is_array($pending_sync) && isset($pending_sync['entity']) ? $pending_sync['entity'] : 'desconocido');
        
        ?>
        <div class="notice notice-info">
            <p><strong><?php _e('Sincronización pendiente de reanudar', 'mi-integracion-api'); ?></strong></p>
            <p><?php echo esc_html(is_array($pending_sync) && isset($pending_sync['message']) ? $pending_sync['message'] : __('Información no disponible', 'mi-integracion-api')); ?></p>
            
            <form method="post" style="margin-top: 15px;">
                <?php wp_nonce_field('mi_integracion_api_resume_sync', 'resume_sync_nonce'); ?>
                <input type="hidden" name="action" value="mi_integracion_api_resume_sync">
                <input type="hidden" name="entity" value="<?php echo esc_attr(is_array($pending_sync) && isset($pending_sync['entity']) ? $pending_sync['entity'] : 'productos'); ?>">
                
                <button type="submit" name="resume" class="button button-primary">
                    <?php printf(__('Reanudar sincronización de %s', 'mi-integracion-api'), $entity_label); ?>
                </button>
                
                <button type="submit" name="restart" class="button">
                    <?php printf(__('Iniciar nueva sincronización de %s', 'mi-integracion-api'), $entity_label); ?>
                </button>
            </form>
        </div>
        <?php
    }
    
    /**
     * Muestra opciones de recuperación en el panel de herramientas
     * 
     * Renderiza la interfaz de gestión de puntos de recuperación,
     * mostrando información sobre sincronizaciones pendientes y opciones
     * para gestionarlas.
     * 
     * @since 1.0.0
     * @return void
     */
    public function mostrar_opciones_recuperacion(): void {
        ?>
        <div class="mi-integracion-api-admin-section">
            <h3><?php _e('Gestionar Puntos de Recuperación', 'mi-integracion-api'); ?></h3>
            
            <p><?php _e('Los puntos de recuperación permiten reanudar sincronizaciones interrumpidas. Utilice estas opciones con precaución.', 'mi-integracion-api'); ?></p>
            
            <?php
            // Verificar si hay alguna sincronización pendiente
            $coreRouting = defined('MIA_USE_CORE_SYNC') ? (bool) constant('MIA_USE_CORE_SYNC') : (bool) get_option('mia_use_core_sync', true);
            if (function_exists('apply_filters')) { $coreRouting = (bool) apply_filters('mia_use_core_sync_routing', $coreRouting); }
            if ($coreRouting && class_exists('MiIntegracionApi\\Core\\Sync_Manager')) {
                $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
                $pending_sync = method_exists($sync_manager, 'check_pending_sync_legacy') ? $sync_manager->check_pending_sync_legacy() : $sync_manager->getSyncStatus();
                if ($pending_sync && isset($pending_sync['current_sync']) && ($pending_sync['current_sync']['in_progress'] ?? false)) {
                    $pending_sync = [
                        'entity' => $pending_sync['current_sync']['entity'] ?? 'productos',
                        'message' => __('Sincronización en curso', 'mi-integracion-api'),
                        'progress' => [
                            'percentage' => $pending_sync['current_sync']['progress'] ?? 0,
                            'processed' => $pending_sync['current_sync']['items_synced'] ?? 0,
                            'total' => $pending_sync['current_sync']['total_items'] ?? 0,
                            'last_batch' => $pending_sync['current_sync']['current_batch'] ?? 0,
                            'date' => date_i18n('Y-m-d H:i:s')
                        ]
                    ];
                }
            } else {
                $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
                $pending_sync = $sync_manager->check_pending_sync();
            }
            
            if ($pending_sync && is_array($pending_sync)) {
                $progress = isset($pending_sync['progress']) && is_array($pending_sync['progress']) ? $pending_sync['progress'] : [];
                ?>
                <div class="mi-integracion-api-recovery-info">
                    <h4><?php printf(__('Sincronización de %s pendiente', 'mi-integracion-api'), esc_html(isset($pending_sync['entity']) ? $pending_sync['entity'] : 'desconocido')); ?></h4>
                    
                    <ul>
                        <li><?php printf(__('Progreso: %s%%', 'mi-integracion-api'), esc_html(isset($progress['percentage']) ? $progress['percentage'] : '0')); ?></li>
                        <li><?php printf(__('Procesados: %s de %s', 'mi-integracion-api'), esc_html(isset($progress['processed']) ? $progress['processed'] : '0'), esc_html(isset($progress['total']) ? $progress['total'] : '0')); ?></li>
                        <li><?php printf(__('Último lote: #%s', 'mi-integracion-api'), esc_html(isset($progress['last_batch']) ? $progress['last_batch'] : '0')); ?></li>
                        <li><?php printf(__('Fecha: %s', 'mi-integracion-api'), esc_html(isset($progress['date']) ? $progress['date'] : __('No disponible', 'mi-integracion-api'))); ?></li>
                    </ul>
                </div>
                <?php
            } else {
                ?>
                <div class="mi-integracion-api-recovery-info">
                    <p><?php _e('No hay puntos de recuperación activos.', 'mi-integracion-api'); ?></p>
                </div>
                <?php
            }
            ?>
            
            <form method="post">
                <?php wp_nonce_field('mi_integracion_api_clear_recovery', 'clear_recovery_nonce'); ?>
                <input type="hidden" name="action" value="mi_integracion_api_clear_recovery">
                
                <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e('¿Está seguro de que desea eliminar todos los puntos de recuperación?', 'mi-integracion-api'); ?>')">
                    <?php _e('Eliminar todos los puntos de recuperación', 'mi-integracion-api'); ?>
                </button>
            </form>
        </div>
        <?php
    }
    
    /**
     * Procesa los formularios de recuperación
     * 
     * Maneja las acciones de reanudación y limpieza de puntos de recuperación
     * enviadas desde los formularios de la interfaz administrativa.
     * 
     * @since 1.0.0
     * @return void
     */
    public function procesar_formularios(): void {
        // Procesar formulario de reanudación
        if (isset($_POST['action']) && $_POST['action'] === 'mi_integracion_api_resume_sync') {
            if (!isset($_POST['resume_sync_nonce']) || !wp_verify_nonce($_POST['resume_sync_nonce'], 'mi_integracion_api_resume_sync')) {
                wp_die(__('Verificación de seguridad fallida', 'mi-integracion-api'));
            }
            
            if (!isset($_POST['entity'])) {
                return;
            }
            
            $entity = sanitize_text_field($_POST['entity']);
            $force_restart = isset($_POST['restart']);
            
            $coreRouting = defined('MIA_USE_CORE_SYNC') ? (bool) constant('MIA_USE_CORE_SYNC') : (bool) get_option('mia_use_core_sync', true);
            if (function_exists('apply_filters')) { $coreRouting = (bool) apply_filters('mia_use_core_sync_routing', $coreRouting); }
            if ($coreRouting && class_exists('MiIntegracionApi\\Core\\Sync_Manager')) {
                $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
                // Usar método moderno con parámetros apropiados
                $result = $sync_manager->resume_sync(null, null, $entity);
            } else {
                $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
                $result = $sync_manager->resume_sync(null, null, $entity);
            }
            
            // Configurar mensaje para mostrar en la siguiente carga de página
            if ($result->isSuccess()) {
                add_action('admin_notices', function() use ($result) {
                    // SEGURIDAD: Validar y sanitizar el mensaje antes de mostrarlo
                    $message = sanitize_text_field($result->getMessage());
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
                });
            } else {
                add_action('admin_notices', function() use ($result) {
                    // SEGURIDAD: Validar y sanitizar el mensaje antes de mostrarlo
                    $message = sanitize_text_field($result->getMessage());
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
                });
            }
            
            return;
        }
        
        // Procesar formulario de limpieza de puntos de recuperación
        if (isset($_POST['action']) && $_POST['action'] === 'mi_integracion_api_clear_recovery') {
            if (!isset($_POST['clear_recovery_nonce']) || !wp_verify_nonce($_POST['clear_recovery_nonce'], 'mi_integracion_api_clear_recovery')) {
                wp_die(__('Verificación de seguridad fallida', 'mi-integracion-api'));
            }
            
            $coreRouting = defined('MIA_USE_CORE_SYNC') ? (bool) constant('MIA_USE_CORE_SYNC') : (bool) get_option('mia_use_core_sync', true);
            if (function_exists('apply_filters')) { $coreRouting = (bool) apply_filters('mia_use_core_sync_routing', $coreRouting); }
            if ($coreRouting && class_exists('MiIntegracionApi\\Core\\Sync_Manager')) {
                $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
                // Si Core aún no implementa clear_all_recovery_states, usar método core equivalente
                if (!method_exists($sync_manager, 'clear_all_recovery_states')) {
                    $result = $sync_manager->clearAllRecoveryStates(); // Método core equivalente
                } else {
                    $result = $sync_manager->clear_all_recovery_states();
                }
            } else {
                $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
                $result = $sync_manager->clear_all_recovery_states();
            }
            
            // Configurar mensaje para mostrar en la siguiente carga de página
            if ($result) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible"><p>' . 
                         __('Todos los puntos de recuperación han sido eliminados correctamente.', 'mi-integracion-api') . 
                         '</p></div>';
                });
            } else {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error is-dismissible"><p>' . 
                         __('Error al eliminar los puntos de recuperación.', 'mi-integracion-api') . 
                         '</p></div>';
                });
            }
            
            return;
        }
    }
}
