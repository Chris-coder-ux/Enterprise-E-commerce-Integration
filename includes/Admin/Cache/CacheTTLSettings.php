<?php
/**
 * Página de administración para configuración de caché por endpoint
 *
 * Este archivo maneja la interfaz de administración para configurar los tiempos de vida (TTL)
 * y la activación/desactivación de la caché para los diferentes endpoints de la API.
 *
 * Características principales:
 * - Configuración individual por endpoint
 * - Estadísticas de uso de caché
 * - Herramientas de mantenimiento (purga, restablecimiento)
 *
 * @package MiIntegracionApi\Admin\Cache
 * @since 1.0.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\Admin\Cache;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para la gestión de la configuración de caché de la API
 *
 * Esta clase proporciona una interfaz en el panel de administración de WordPress
 * para configurar los tiempos de vida de la caché para cada endpoint de la API.
 *
 * @package MiIntegracionApi\Admin\Cache
 * @since 1.0.0
 */
class CacheTTLSettings {
    
    /**
     * Inicializa la página de configuración de caché
     *
     * Registra los hooks necesarios para la página de administración.
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action('admin_init', [$this, 'registerSettings']);
    }    
    
    /**
     * Registra las opciones de configuración en la base de datos
     *
     * Este método registra la opción 'mi_integracion_api_cache_config' que almacena
     * la configuración de caché para todos los endpoints.
     *
     * @return void
     * @hook admin_init
     * @since 1.0.0
     * @see register_setting()
     */
    public function registerSettings() {
        register_setting('mi_integracion_api_cache_options', 'mi_integracion_api_cache_config');
    }
    
    /**
     * Renderiza la página de configuración de caché
     *
     * Muestra el formulario de configuración con las siguientes secciones:
     * 1. Configuración de TTL por endpoint
     * 2. Estadísticas de caché
     * 3. Herramientas de mantenimiento
     *
     * @return void
     * @since 1.0.0
     * @see get_option()
     * @see wp_nonce_field()
     * @see check_admin_referer()
     */
    public function renderPage() {
        // Obtener configuración actual
        $cacheConfig = get_option('mi_integracion_api_cache_config', []);
        
        // Endpoints disponibles
        $endpoints = [
            'GetArticulosWS' => 'Artículos',
            'GetCategoriasArticulosWS' => 'Categorías',
            'GetClientesWS' => 'Clientes',
            'GetPreciosArticulosWS' => 'Precios',
            'GetStockArticulosWS' => 'Stock'
        ];
        
        // Valores predeterminados
        $defaultTTL = apply_filters('mi_integracion_api_default_cache_ttl', 3600);
        
        // Procesar formulario
        if (isset($_POST['submit']) && check_admin_referer('mi_integracion_api_cache_options')) {
            $newConfig = [];
            
            foreach ($endpoints as $endpoint => $label) {
                $enabled = isset($_POST['cache_enabled'][$endpoint]) ? 1 : 0;
                $ttl = isset($_POST['cache_ttl'][$endpoint]) ? intval($_POST['cache_ttl'][$endpoint]) : $defaultTTL;
                
                $newConfig[$endpoint] = [
                    'enabled' => $enabled,
                    'ttl' => $ttl
                ];
            }
            
            update_option('mi_integracion_api_cache_config', $newConfig);
            echo '<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>';
            $cacheConfig = $newConfig;
        }
        
        ?>
        <div class="wrap">
            <h1>Configuración de Caché del API Connector</h1>
            
            <p>Configure los tiempos de caché para cada endpoint del API. Un valor de 0 desactiva la caché para ese endpoint.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('mi_integracion_api_cache_options'); ?>
                
                <table class="form-table">
                    <thead>
                        <tr>
                            <th>Endpoint</th>
                            <th>Habilitado</th>
                            <th>TTL (segundos)</th>
                            <th>Descripción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($endpoints as $endpoint => $label): ?>
                            <?php 
                            $enabled = isset($cacheConfig[$endpoint]['enabled']) ? $cacheConfig[$endpoint]['enabled'] : 1;
                            $ttl = isset($cacheConfig[$endpoint]['ttl']) ? $cacheConfig[$endpoint]['ttl'] : $defaultTTL;
                            ?>
                            <tr>
                                <td><?php echo esc_html($label); ?></td>
                                <td>
                                    <input 
                                        type="checkbox" 
                                        name="cache_enabled[<?php echo esc_attr($endpoint); ?>]" 
                                        value="1" 
                                        <?php checked($enabled); ?>
                                    >
                                </td>
                                <td>
                                    <input 
                                        type="number" 
                                        name="cache_ttl[<?php echo esc_attr($endpoint); ?>]" 
                                        value="<?php echo esc_attr($ttl); ?>" 
                                        class="small-text"
                                        min="0"
                                        step="1"
                                    >
                                </td>
                                <td>
                                    <?php 
                                    switch($endpoint) {
                                        case 'GetArticulosWS':
                                            echo 'Datos de artículos (recomendado: 3600)';
                                            break;
                                        case 'GetCategoriasArticulosWS':
                                            echo 'Categorías de artículos (recomendado: 7200)';
                                            break;
                                        case 'GetClientesWS':
                                            echo 'Datos de clientes (recomendado: 3600)';
                                            break;
                                        case 'GetPreciosArticulosWS':
                                            echo 'Precios de artículos (recomendado: 1800)';
                                            break;
                                        case 'GetStockArticulosWS':
                                            echo 'Niveles de stock (recomendado: 300)';
                                            break;
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <p>
                    <input type="submit" name="submit" class="button button-primary" value="Guardar cambios">
                    <input type="button" name="reset" class="button" value="Restablecer valores predeterminados" onclick="resetToDefaults()">
                </p>
            </form>
            
            <h2>Estadísticas de caché</h2>
            <p>A continuación se muestran las estadísticas de uso de caché:</p>
            
            <?php
            // Estadísticas básicas de transients
            global $wpdb;
            $total_transients = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE '%mi_integracion_api_cache%'");
            ?>
            
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Métrica</th>
                        <th>Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Total de elementos en caché:</td>
                        <td><?php echo intval($total_transients); ?></td>
                    </tr>
                </tbody>
            </table>
            
            <p>
                <button class="button" onclick="purgeAllCache()">Purgar toda la caché</button>
            </p>
        </div>
        
        <script type="text/javascript">
        function resetToDefaults() {
            if (confirm('¿Está seguro de que desea restablecer todos los valores a los predeterminados?')) {
                const defaultValues = {
                    'GetArticulosWS': 3600,
                    'GetCategoriasArticulosWS': 7200,
                    'GetClientesWS': 3600,
                    'GetPreciosArticulosWS': 1800,
                    'GetStockArticulosWS': 300
                };
                
                // Establecer valores predeterminados en el formulario
                for (const endpoint in defaultValues) {
                    const ttlInput = document.querySelector(`input[name="cache_ttl[${endpoint}]"]`);
                    const enabledInput = document.querySelector(`input[name="cache_enabled[${endpoint}]"]`);
                    
                    if (ttlInput) {
                        ttlInput.value = defaultValues[endpoint];
                    }
                    
                    if (enabledInput) {
                        enabledInput.checked = true;
                    }
                }
            }
        }
        
        function purgeAllCache() {
            if (confirm('¿Está seguro de que desea purgar toda la caché? Esto puede afectar temporalmente al rendimiento.')) {
                // Enviar solicitud AJAX para purgar caché
                const xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxurl);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            alert('La caché ha sido purgada correctamente.');
                            location.reload();
                        } else {
                            alert('Error al purgar la caché.');
                        }
                    }
                };
                xhr.send('action=mi_integracion_api_purge_cache&nonce=' + '<?php echo wp_create_nonce('mi_integracion_api_purge_cache'); ?>');
            }
        }
        </script>
        <?php
    }
}

// Inicializar la página de configuración de caché
add_action('plugins_loaded', function() {
    new CacheTTLSettings();
});