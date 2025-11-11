<?php

declare(strict_types=1);

/**
 * Funciones AJAX para sincronización
 *
 * @package MiIntegracionApi
 * @subpackage Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salir si se accede directamente
}

/**
 * Crea la tabla de historial de sincronización si no existe
 * 
 * @return void
 */
function mia_crear_tabla_historial() {
    // Usar el Installer centralizado para crear la tabla
    if (class_exists('MiIntegracionApi\\Core\\Installer')) {
        $result = \MiIntegracionApi\Core\Installer::create_sync_history_table();
        if (!$result) {
            // Log de error si falla
            if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
                $logger = new \MiIntegracionApi\Helpers\Logger('ajax_sync');
                $logger->error('Error al crear tabla de historial de sincronización usando Installer centralizado');
            }
        }
    } else {
        // Si el Installer no está disponible, registrar error
        if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
            $logger = new \MiIntegracionApi\Helpers\Logger('ajax_sync');
            $logger->error('Clase Installer no encontrada, no se puede crear la tabla de historial de sincronización');
        }
    }
}

/**
 * Muestra los detalles de una sincronización específica
 * 
 * @param int $sync_id ID de la sincronización
 * @return void
 */
function mia_mostrar_detalles_sincronizacion($sync_id) {
    global $wpdb;
    
    $tabla = $wpdb->prefix . 'mi_integracion_api_logs';
    $registro = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabla WHERE id = %d", $sync_id));
    
    if (!$registro) {
        echo '<div class="wrap"><div class="notice notice-error"><p>';
        echo esc_html__('No se encontró el registro solicitado.', 'mi-integracion-api');
        echo '</p></div></div>';
        return;
    }
    
    $detalles = json_decode($registro->details, true);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Detalles de la Sincronización', 'mi-integracion-api'); ?> #<?php echo esc_html($sync_id); ?></h1>
        
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=mi-integracion-api-sync-history')); ?>" class="button">
                <?php esc_html_e('← Volver al historial', 'mi-integracion-api'); ?>
            </a>
        </p>
        
        <div class="mi-integracion-api-sync-details">
            <table class="widefat">
                <tr>
                    <th><?php esc_html_e('Fecha/Hora', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html($registro->timestamp); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Tipo', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html($registro->tipo); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Mensaje', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html($registro->message); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Estado', 'mi-integracion-api'); ?></th>
                    <td>
                        <?php 
                        switch($registro->status) {
                            case 'complete':
                                echo '<span class="sync-status sync-complete">' . esc_html__('Completado', 'mi-integracion-api') . '</span>'; 
                                break;
                            case 'error':
                                echo '<span class="sync-status sync-error">' . esc_html__('Error', 'mi-integracion-api') . '</span>'; 
                                break;
                            case 'partial':
                                echo '<span class="sync-status sync-partial">' . esc_html__('Parcial', 'mi-integracion-api') . '</span>'; 
                                break;
                            default:
                                echo esc_html($registro->status);
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Tiempo transcurrido', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html(number_format($registro->elapsed_time, 2)); ?> <?php esc_html_e('segundos', 'mi-integracion-api'); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Items procesados', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html($registro->items_processed); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Items correctos', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html($registro->items_success); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Items con error', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html($registro->items_error); ?></td>
                </tr>
            </table>
            
            <?php if (!empty($detalles) && is_array($detalles)): ?>
                <h2><?php esc_html_e('Detalles', 'mi-integracion-api'); ?></h2>
                
                <?php if (isset($detalles['items']) && is_array($detalles['items'])): ?>
                    <h3><?php esc_html_e('Items procesados', 'mi-integracion-api'); ?></h3>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('ID', 'mi-integracion-api'); ?></th>
                                <th><?php esc_html_e('Estado', 'mi-integracion-api'); ?></th>
                                <th><?php esc_html_e('Mensaje', 'mi-integracion-api'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($detalles['items'] as $item): ?>
                                <tr>
                                    <td><?php echo esc_html($item['id'] ?? ''); ?></td>
                                    <td>
                                        <?php 
                                        $status = $item['status'] ?? '';
                                        switch($status) {
                                            case 'success':
                                                echo '<span class="sync-status sync-complete">' . esc_html__('Correcto', 'mi-integracion-api') . '</span>'; 
                                                break;
                                            case 'error':
                                                echo '<span class="sync-status sync-error">' . esc_html__('Error', 'mi-integracion-api') . '</span>'; 
                                                break;
                                            default:
                                                echo esc_html($status);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo esc_html($item['message'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <?php if (isset($detalles['errors']) && is_array($detalles['errors'])): ?>
                    <h3><?php esc_html_e('Errores', 'mi-integracion-api'); ?></h3>
                    <div class="mi-integracion-api-error-list">
                        <ul>
                            <?php foreach ($detalles['errors'] as $error): ?>
                                <li><?php echo esc_html($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($detalles['raw']) && !empty($detalles['raw'])): ?>
                    <h3><?php esc_html_e('Datos crudos', 'mi-integracion-api'); ?></h3>
                    <div class="mi-integracion-api-raw-data">
                    </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
    </div>
    <?php
}
