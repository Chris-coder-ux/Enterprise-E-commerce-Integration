<?php
/**
 * Template para mostrar el historial de sincronizaciones
 *
 * @package MiIntegracionApi
 * @subpackage Templates/Admin
 * @since 2.0.0
 */

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

// Obtener instancia del Sync_Manager
$sync_manager = MiIntegracionApi\Core\Sync_Manager::get_instance();
$history_response = $sync_manager->get_sync_history(100); // Últimos 100 registros

// Extraer datos del SyncResponseInterface
$history = [];
if ($history_response->isSuccess()) {
    $history = $history_response->getData()['data'] ?? [];
}
?>

<div class="wrap">
    <h1><?php echo esc_html__('Historial de Sincronizaciones', 'mi-integracion-api'); ?></h1>
    
    <?php if (!$history_response->isSuccess()) : ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Error al cargar el historial de sincronización:', 'mi-integracion-api'); ?> <?php echo esc_html($history_response->getMessage()); ?></p>
        </div>
    <?php elseif (empty($history)) : ?>
        <div class="notice notice-info">
            <p><?php esc_html_e('No hay registros de sincronización aún.', 'mi-integracion-api'); ?></p>
        </div>
    <?php else : ?>
        <div class="tablenav top">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php 
                    printf(
                        _n('%s elemento', '%s elementos', count($history), 'mi-integracion-api'),
                        number_format_i18n(count($history))
                    );
                    ?>
                </span>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="column-primary"><?php esc_html_e('Fecha', 'mi-integracion-api'); ?></th>
                    <th scope="col"><?php esc_html_e('Entidad', 'mi-integracion-api'); ?></th>
                    <th scope="col"><?php esc_html_e('Dirección', 'mi-integracion-api'); ?></th>
                    <th scope="col"><?php esc_html_e('Estado', 'mi-integracion-api'); ?></th>
                    <th scope="col"><?php esc_html_e('Elementos', 'mi-integracion-api'); ?></th>
                    <th scope="col"><?php esc_html_e('Duración', 'mi-integracion-api'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $item) : ?>
                    <tr>
                        <td class="column-primary">
                            <?php 
                            $date = date_i18n(
                                get_option('date_format') . ' ' . get_option('time_format'), 
                                strtotime($item['end_time'] ?? $item['start_time'])
                            );
                            echo esc_html($date);
                            ?>
                            <button type="button" class="toggle-row">
                                <span class="screen-reader-text"><?php esc_html_e('Ver más detalles', 'mi-integracion-api'); ?></span>
                            </button>
                        </td>
                        <td><?php echo esc_html($item['entity'] ?? '-'); ?></td>
                        <td>
                            <?php 
                            $direction = $item['direction'] ?? '';
                            $direction_label = $direction === 'wc_to_verial' 
                                ? __('WooCommerce → Verial', 'mi-integracion-api')
                                : __('Verial → WooCommerce', 'mi-integracion-api');
                            echo esc_html($direction_label);
                            ?>
                        </td>
                        <td>
                            <?php
                            $status = $item['status'] ?? '';
                            $status_class = '';
                            
                            switch ($status) {
                                case 'completed':
                                    $status_class = 'success';
                                    $status_label = __('Completado', 'mi-integracion-api');
                                    break;
                                case 'failed':
                                    $status_class = 'error';
                                    $status_label = __('Fallido', 'mi-integracion-api');
                                    break;
                                case 'cancelled':
                                    $status_class = 'warning';
                                    $status_label = __('Cancelado', 'mi-integracion-api');
                                    break;
                                default:
                                    $status_label = $status;
                            }
                            
                            if ($status_class) {
                                printf(
                                    '<span class="dashicons dashicons-marker %s"></span> %s',
                                    esc_attr($status_class),
                                    esc_html($status_label)
                                );
                            } else {
                                echo esc_html($status_label);
                            }
                            
                            // Mostrar mensaje de error si existe
                            if (!empty($item['error'])) {
                                echo '<br><small class="error-message">' . esc_html($item['error']) . '</small>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            $processed = $item['items_synced'] ?? 0;
                            $total = $item['total_items'] ?? 0;
                            
                            if ($total > 0) {
                                $percentage = ($processed / $total) * 100;
                                echo sprintf(
                                    '%s / %s <small>(%d%%)</small>',
                                    number_format_i18n($processed),
                                    number_format_i18n($total),
                                    round($percentage)
                                );
                            } else {
                                echo number_format_i18n($processed);
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            if (!empty($item['duration'])) {
                                // duration ya está en segundos
                                echo esc_html(human_time_diff(0, $item['duration']));
                            } elseif (!empty($item['start_time']) && !empty($item['end_time'])) {
                                $start = strtotime($item['start_time']);
                                $end = strtotime($item['end_time']);
                                echo esc_html(human_time_diff($start, $end));
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr class="details-row" style="display: none;">
                        <td colspan="6">
                            <div class="sync-details">
                                <h4><?php esc_html_e('Detalles de la sincronización', 'mi-integracion-api'); ?></h4>
                                <div class="details-grid">
                                    <div class="detail-item">
                                        <strong><?php esc_html_e('ID de operación:', 'mi-integracion-api'); ?></strong>
                                        <code><?php echo esc_html($item['operation_id'] ?? '-'); ?></code>
                                    </div>
                                    <div class="detail-item">
                                        <strong><?php esc_html_e('Inicio:', 'mi-integracion-api'); ?></strong>
                                        <?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($item['start_time']))); ?>
                                    </div>
                                    <?php if (!empty($item['end_time'])) : ?>
                                        <div class="detail-item">
                                            <strong><?php esc_html_e('Fin:', 'mi-integracion-api'); ?></strong>
                                            <?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($item['end_time']))); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($item['metrics']['memory_usage'])) : ?>
                                        <div class="detail-item">
                                            <strong><?php esc_html_e('Uso de memoria:', 'mi-integracion-api'); ?></strong>
                                            <?php echo esc_html(size_format($item['metrics']['memory_usage'])); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($item['errors']) && $item['errors'] > 0) : ?>
                                        <div class="detail-item">
                                            <strong><?php esc_html_e('Errores:', 'mi-integracion-api'); ?></strong>
                                            <span class="error-count"><?php echo number_format_i18n($item['errors']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($item['metrics']) && is_array($item['metrics'])) : ?>
                                    <h4><?php esc_html_e('Métricas', 'mi-integracion-api'); ?></h4>
                                    <div class="details-grid">
                                        <?php foreach ($item['metrics'] as $metric_key => $metric_value) : ?>
                                            <div class="detail-item">
                                                <strong><?php echo esc_html(ucfirst(str_replace('_', ' ', $metric_key))); ?>:</strong>
                                                <?php 
                                                if (is_numeric($metric_value)) {
                                                    if (strpos($metric_key, 'time') !== false || strpos($metric_key, 'duration') !== false) {
                                                        echo esc_html(human_time_diff(0, $metric_value));
                                                    } elseif (strpos($metric_key, 'memory') !== false || strpos($metric_key, 'size') !== false) {
                                                        echo esc_html(size_format($metric_value));
                                                    } else {
                                                        echo number_format_i18n($metric_value);
                                                    }
                                                } else {
                                                    echo esc_html($metric_value);
                                                }
                                                ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($item['warnings']) && is_array($item['warnings'])) : ?>
                                    <h4><?php esc_html_e('Advertencias', 'mi-integracion-api'); ?></h4>
                                    <ul class="warning-list">
                                        <?php foreach ($item['warnings'] as $warning) : ?>
                                            <li><?php echo esc_html($warning); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>


<style>
    .error-count {
        color: #d63638;
        font-weight: bold;
    }
    .sync-details {
        padding: 15px;
        background: #f9f9f9;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    .details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 10px;
        margin: 10px 0;
    }
    .detail-item {
        padding: 5px 0;
    }
    .warning-list {
        margin: 10px 0;
        padding-left: 20px;
    }
    .warning-list li {
        color: #dba617;
    }
</style>

<script>
    jQuery(document).ready(function($) {
        // Alternar filas de detalles
        $('.toggle-row').on('click', function() {
            $(this).closest('tr').next('.details-row').toggle();
        });
    });
</script>
