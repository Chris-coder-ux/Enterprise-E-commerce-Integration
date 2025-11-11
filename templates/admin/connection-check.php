<?php
/**
 * Template para verificar la conexión con la API
 *
 * @package MiIntegracionApi
 * @subpackage Templates/Admin
 * @since 2.0.0
 */

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

// Obtener instancia del gestor de conexión
$connection_manager = MiIntegracionApi\Core\Connection_Manager::get_instance();
$test_results = [];
$is_connected = false;

// Probar conexión si se ha enviado el formulario
if (isset($_POST['test_connection']) && check_admin_referer('mi_integration_api_test_connection')) {
    $test_results = $connection_manager->test_connection();
    $is_connected = !empty($test_results['success']);
}
?>

<div class="wrap">
    <h1><?php echo esc_html__('Verificar Conexión', 'mi-integracion-api'); ?></h1>
    
    <div class="card">
        <h2><?php esc_html_e('Estado de la Conexión', 'mi-integracion-api'); ?></h2>
        
        <div class="connection-status">
            <?php if (!empty($test_results)) : ?>
                <?php if ($is_connected) : ?>
                    <div class="notice notice-success">
                        <p>
                            <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                            <strong><?php esc_html_e('¡Conexión exitosa!', 'mi-integracion-api'); ?></strong>
                        </p>
                    </div>
                    
                    <div class="connection-details">
                        <h3><?php esc_html_e('Detalles de la conexión:', 'mi-integracion-api'); ?></h3>
                        <table class="wp-list-table widefat striped">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php esc_html_e('URL de la API', 'mi-integracion-api'); ?></th>
                                    <td><code><?php echo esc_html($test_results['url'] ?? '-'); ?></code></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Tiempo de respuesta', 'mi-integracion-api'); ?></th>
                                    <td><?php echo esc_html(number_format(($test_results['response_time'] ?? 0) * 1000, 2)); ?> ms</td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Versión de la API', 'mi-integracion-api'); ?></th>
                                    <td><?php echo esc_html($test_results['version'] ?? '-'); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Límite de tasa', 'mi-integracion-api'); ?></th>
                                    <td>
                                        <?php 
                                        if (isset($test_results['rate_limit'])) {
                                            printf(
                                                '%d/%d peticiones por minuto',
                                                esc_html($test_results['rate_limit']['remaining'] ?? 0),
                                                esc_html($test_results['rate_limit']['limit'] ?? 0)
                                            );
                                        } else {
                                            echo '-'; 
                                        } 
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Tiempo de conexión', 'mi-integracion-api'); ?></th>
                                    <td>
                                        <?php 
                                        echo isset($test_results['timestamp']) 
                                            ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $test_results['timestamp']))
                                            : '-';
                                        ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <div class="notice notice-error">
                        <p>
                            <span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span>
                            <strong><?php esc_html_e('Error de conexión', 'mi-integracion-api'); ?></strong>
                        </p>
                        <?php if (!empty($test_results['error'])) : ?>
                            <p><?php echo esc_html($test_results['error']); ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($test_results['response_code'])) : ?>
                        <div class="connection-error-details">
                            <h3><?php esc_html_e('Detalles del error:', 'mi-integracion-api'); ?></h3>
                            <table class="wp-list-table widefat striped">
                                <tbody>
                                    <tr>
                                        <th scope="row"><?php esc_html_e('Código de estado HTTP', 'mi-integracion-api'); ?></th>
                                        <td><code><?php echo esc_html($test_results['response_code']); ?></code></td>
                                    </tr>
                                    <?php if (!empty($test_results['response_body'])) : ?>
                                        <tr>
                                            <th scope="row"><?php esc_html_e('Respuesta del servidor', 'mi-integracion-api'); ?></th>
                                            <td>
                                                <pre style="white-space: pre-wrap; max-height: 200px; overflow: auto; background: #f5f5f5; padding: 10px;">
<?php echo esc_html(print_r($test_results['response_body'], true)); ?>
                                                </pre>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($test_results['curl_error'])) : ?>
                                        <tr>
                                            <th scope="row"><?php esc_html_e('Error cURL', 'mi-integracion-api'); ?></th>
                                            <td><?php echo esc_html($test_results['curl_error']); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <div class="connection-actions" style="margin-top: 20px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=mi-integracion-api-connection')); ?>" class="button">
                        <?php esc_html_e('Volver a intentar', 'mi-integracion-api'); ?>
                    </a>
                </div>
            <?php else : ?>
                <div class="notice notice-info">
                    <p><?php esc_html_e('Haz clic en el botón de abajo para probar la conexión con la API.', 'mi-integracion-api'); ?></p>
                </div>
                
                <form method="post" action="" style="margin-top: 20px;">
                    <?php wp_nonce_field('mi_integration_api_test_connection'); ?>
                    <input type="hidden" name="test_connection" value="1">
                    <?php 
                    submit_button(
                        __('Probar Conexión', 'mi-integracion-api'),
                        'primary',
                        'submit',
                        false
                    ); 
                    ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="card">
        <h2><?php esc_html_e('Información de la Conexión', 'mi-integracion-api'); ?></h2>
        
        <form method="post" action="options.php">
            <?php 
            settings_fields('mi_integracion_api_connection');
            do_settings_sections('mi_integracion_api_connection');
            
            // Obtener opciones actuales
            $api_url = get_option('mi_integracion_api_url', '');
            $api_key = get_option('mi_integracion_api_key', '');
            $api_secret = get_option('mi_integracion_api_secret', '');
            $verify_ssl = get_option('mi_integracion_api_verify_ssl', 'yes');
            $timeout = get_option('mi_integracion_api_timeout', 30);
            ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="api_url">
                            <?php esc_html_e('URL de la API', 'mi-integracion-api'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="url" 
                               id="api_url" 
                               name="mi_integracion_api_url" 
                               value="<?php echo esc_attr($api_url); ?>" 
                               class="regular-text"
                               placeholder="https://api.ejemplo.com/v1/">
                        <p class="description">
                            <?php esc_html_e('La URL base de la API (incluyendo la versión si es necesario).', 'mi-integracion-api'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="api_key">
                            <?php esc_html_e('Clave de API', 'mi-integracion-api'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="password" 
                               id="api_key" 
                               name="mi_integracion_api_key" 
                               value="<?php echo esc_attr($api_key); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('La clave de API para autenticación.', 'mi-integracion-api'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="api_secret">
                            <?php esc_html_e('Secreto de API', 'mi-integracion-api'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="password" 
                               id="api_secret" 
                               name="mi_integracion_api_secret" 
                               value="<?php echo esc_attr($api_secret); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('El secreto de API para autenticación.', 'mi-integracion-api'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Verificar SSL', 'mi-integracion-api'); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="mi_integracion_api_verify_ssl" 
                                   value="1" 
                                   <?php checked($verify_ssl, '1'); ?>>
                            <?php esc_html_e('Verificar certificados SSL', 'mi-integracion-api'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Desmarca esta opción solo si estás en un entorno de desarrollo con certificados autofirmados.', 'mi-integracion-api'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="api_timeout">
                            <?php esc_html_e('Tiempo de espera (segundos)', 'mi-integracion-api'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="number" 
                               id="api_timeout" 
                               name="mi_integracion_api_timeout" 
                               value="<?php echo esc_attr($timeout); ?>" 
                               min="5" 
                               step="1" 
                               class="small-text">
                        <p class="description">
                            <?php esc_html_e('Tiempo máximo de espera para las solicitudes a la API (en segundos).', 'mi-integracion-api'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(__('Guardar configuración', 'mi-integracion-api')); ?>
        </form>
    </div>
    
    <div class="card">
        <h2><?php esc_html_e('Solución de problemas', 'mi-integracion-api'); ?></h2>
        
        <div class="troubleshooting-steps">
            <h3><?php esc_html_e('Si la conexión falla, verifica lo siguiente:', 'mi-integracion-api'); ?></h3>
            
            <ol>
                <li>
                    <strong><?php esc_html_e('Verifica la URL de la API', 'mi-integracion-api'); ?></strong>
                    <p><?php esc_html_e('Asegúrate de que la URL sea correcta y esté completa, incluyendo https://', 'mi-integracion-api'); ?></p>
                </li>
                <li>
                    <strong><?php esc_html_e('Comprueba las credenciales', 'mi-integracion-api'); ?></strong>
                    <p><?php esc_html_e('Verifica que la clave de API y el secreto sean correctos y tengan los permisos necesarios.', 'mi-integracion-api'); ?></p>
                </li>
                <li>
                    <strong><?php esc_html_e('Verifica la conectividad del servidor', 'mi-integracion-api'); ?></strong>
                    <p>
                        <?php 
                        printf(
                            __('Asegúrate de que tu servidor pueda conectarse a la URL de la API. Puedes probarlo con %s o herramientas similares.', 'mi-integracion-api'),
                            '<a href="https://curl.se/" target="_blank">cURL</a>'
                        );
                        ?>
                    </p>
                </li>
                <li>
                    <strong><?php esc_html_e('Revisa los registros de errores', 'mi-integracion-api'); ?></strong>
                    <p><?php esc_html_e('Consulta los registros de errores de WordPress para obtener más detalles sobre el error.', 'mi-integracion-api'); ?></p>
                </li>
            </ol>
            
            <h3><?php esc_html_e('Información del sistema', 'mi-integracion-api'); ?></h3>
            
            <table class="wp-list-table widefat striped">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Versión de PHP', 'mi-integracion-api'); ?></th>
                        <td><?php echo esc_html(phpversion()); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Extensión cURL', 'mi-integracion-api'); ?></th>
                        <td>
                            <?php 
                            echo function_exists('curl_version') 
                                ? esc_html__('Disponible', 'mi-integracion-api') 
                                : '<span style="color: #dc3232;">' . esc_html__('No disponible', 'mi-integracion-api') . '</span>';
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('OpenSSL', 'mi-integracion-api'); ?></th>
                        <td>
                            <?php 
                            echo extension_loaded('openssl') 
                                ? esc_html__('Disponible', 'mi-integracion-api') 
                                : '<span style="color: #dc3232;">' . esc_html__('No disponible', 'mi-integracion-api') . '</span>';
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Permisos de sistema de archivos', 'mi-integracion-api'); ?></th>
                        <td>
                            <?php 
                            $upload_dir = wp_upload_dir();
                            $test_file = trailingslashit($upload_dir['basedir']) . 'test-write.txt';
                            $can_write = @file_put_contents($test_file, 'test');
                            
                            if ($can_write !== false) {
                                @unlink($test_file);
                                echo '<span style="color: #46b450;">' . esc_html__('Escritura permitida', 'mi-integracion-api') . '</span>';
                            } else {
                                echo '<span style="color: #dc3232;">' . esc_html__('Error de escritura', 'mi-integracion-api') . '</span>';
                            }
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

