<?php
/**
 * Template para la gestión de caché
 *
 * @package MiIntegracionApi
 * @subpackage Templates/Admin
 * @since 2.0.0
 */

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

// Obtener instancia del gestor de caché
$cache_manager = MiIntegracionApi\CacheManager::get_instance();
$cache_stats = $cache_manager->get_stats();
$cache_enabled = $cache_manager->is_enabled();
?>

<div class="wrap">
    <h1><?php echo esc_html__('Gestión de Caché', 'mi-integracion-api'); ?></h1>
    
    <div class="card">
        <h2><?php esc_html_e('Estado del Caché', 'mi-integracion-api'); ?></h2>
        
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Estado', 'mi-integracion-api'); ?></th>
                <td>
                    <span class="dashicons dashicons-<?php echo $cache_enabled ? 'yes-alt' : 'dismiss'; ?>" 
                          style="color: <?php echo $cache_enabled ? '#46b450' : '#dc3232'; ?>;">
                    </span>
                    <?php 
                    echo $cache_enabled 
                        ? esc_html__('Activado', 'mi-integracion-api') 
                        : esc_html__('Desactivado', 'mi-integracion-api'); 
                    ?>
                </td>
            </tr>
            
            <?php if ($cache_enabled && !empty($cache_stats)) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Tamaño total', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html(size_format($cache_stats['total_size'], 2)); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Número de entradas', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html(number_format_i18n($cache_stats['total_count'])); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Tiempo de vida', 'mi-integracion-api'); ?></th>
                    <td>
                        <?php 
                        $expiration = $cache_manager->get_expiration();
                        echo $expiration > 0 
                            ? esc_html(sprintf(
                                _n('%d hora', '%d horas', $expiration / HOUR_IN_SECONDS, 'mi-integracion-api'),
                                $expiration / HOUR_IN_SECONDS
                            ))
                            : esc_html__('No expira', 'mi-integracion-api');
                        ?>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
        
        <hr>
        
        <h2><?php esc_html_e('Acciones', 'mi-integracion-api'); ?></h2>
        
        <div class="cache-actions">
            <form method="post" action="" class="inline-block">
                <?php wp_nonce_field('mi_integracion_api_cache_actions', 'mi_integracion_api_nonce'); ?>
                <input type="hidden" name="action" value="clear_cache">
                <?php submit_button(
                    __('Vaciar caché', 'mi-integracion-api'), 
                    'primary', 
                    'submit', 
                    false, 
                    ['class' => 'button button-primary']
                ); ?>
                <span class="description"><?php esc_html_e('Elimina todas las entradas de la caché.', 'mi-integracion-api'); ?></span>
            </form>
            
            <form method="post" action="" class="inline-block" style="margin-left: 15px;">
                <?php wp_nonce_field('mi_integracion_api_cache_actions', 'mi_integracion_api_nonce'); ?>
                <input type="hidden" name="action" value="toggle_cache">
                <?php 
                submit_button(
                    $cache_enabled 
                        ? __('Desactivar caché', 'mi-integracion-api')
                        : __('Activar caché', 'mi-integracion-api'),
                    'secondary',
                    'submit',
                    false,
                    ['class' => 'button']
                ); 
                ?>
                <span class="description">
                    <?php 
                    echo $cache_enabled 
                        ? esc_html__('Desactiva temporalmente el sistema de caché.', 'mi-integracion-api')
                        : esc_html__('Activa el sistema de caché para mejorar el rendimiento.', 'mi-integracion-api');
                    ?>
                </span>
            </form>
        </div>
        
        <?php if (!empty($cache_stats['groups'])) : ?>
            <hr>
            
            <h2><?php esc_html_e('Grupos de caché', 'mi-integracion-api'); ?></h2>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Grupo', 'mi-integracion-api'); ?></th>
                        <th scope="col" class="text-right"><?php esc_html_e('Entradas', 'mi-integracion-api'); ?></th>
                        <th scope="col" class="text-right"><?php esc_html_e('Tamaño', 'mi-integracion-api'); ?></th>
                        <th scope="col" class="text-right"><?php esc_html_e('Acciones', 'mi-integracion-api'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cache_stats['groups'] as $group => $stats) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($group); ?></strong>
                                <?php if (!empty($stats['description'])) : ?>
                                    <p class="description"><?php echo esc_html($stats['description']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><?php echo esc_html(number_format_i18n($stats['count'])); ?></td>
                            <td class="text-right"><?php echo esc_html(size_format($stats['size'], 2)); ?></td>
                            <td class="text-right">
                                <form method="post" action="" class="inline-block" style="margin: 0;">
                                    <?php wp_nonce_field('mi_integracion_api_cache_actions', 'mi_integracion_api_nonce'); ?>
                                    <input type="hidden" name="action" value="clear_group">
                                    <input type="hidden" name="group" value="<?php echo esc_attr($group); ?>">
                                    <?php 
                                    submit_button(
                                        __('Vaciar', 'mi-integracion-api'),
                                        'small',
                                        'submit',
                                        false,
                                        ['class' => 'button button-small']
                                    ); 
                                    ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <div class="card">
        <h2><?php esc_html_e('Configuración avanzada', 'mi-integracion-api'); ?></h2>
        
        <form method="post" action="options.php">
            <?php 
            settings_fields('mi_integracion_api_cache_settings');
            do_settings_sections('mi_integracion_api_cache_settings');
            
            // Obtener opciones actuales
            $default_expiration = get_option('mi_integracion_api_cache_expiration', 24 * HOUR_IN_SECONDS);
            $default_expiration_hours = $default_expiration / HOUR_IN_SECONDS;
            ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="cache_expiration">
                            <?php esc_html_e('Tiempo de expiración predeterminado', 'mi-integracion-api'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="number" 
                               id="cache_expiration" 
                               name="mi_integracion_api_cache_expiration" 
                               value="<?php echo esc_attr($default_expiration_hours); ?>" 
                               min="1" 
                               step="1" 
                               class="small-text">
                        <span class="description">
                            <?php esc_html_e('horas (0 para no expirar nunca)', 'mi-integracion-api'); ?>
                        </span>
                    </td>
                </tr>
                
                <?php if (defined('WP_DEBUG') && constant('WP_DEBUG')) : ?>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Modo depuración', 'mi-integracion-api'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="mi_integracion_api_cache_debug" 
                                       value="1" 
                                    <?php checked(get_option('mi_integracion_api_cache_debug', false)); ?>>
                                <?php esc_html_e('Registrar operaciones de caché', 'mi-integracion-api'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Habilita el registro detallado de operaciones de caché para depuración.', 'mi-integracion-api'); ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
            
            <?php submit_button(__('Guardar configuración', 'mi-integracion-api')); ?>
        </form>
    </div>
</div>

