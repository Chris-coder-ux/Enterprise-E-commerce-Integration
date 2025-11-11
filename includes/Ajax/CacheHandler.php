<?php declare(strict_types=1);
/**
 * Manejador AJAX para operaciones de caché
 */

namespace MiIntegracionApi\Ajax;

if (!defined('ABSPATH')) {
    exit;
}

class CacheHandler {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_mi_integracion_api_purge_cache', [$this, 'purgeAllCache']);
    }

    /**
     * Purga toda la caché relacionada con el API Connector
     */
    public function purgeAllCache() {
        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_purge_cache')) {
            wp_send_json_error('Acceso no autorizado', 403);
            return;
        }

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tiene permisos para realizar esta acción', 403);
            return;
        }

        global $wpdb;

        // Eliminar todos los transients relacionados con mi_integracion_api
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
                '%mi_integracion_api_cache%',
                '_transient_%mi_integracion_api_cache%'
            )
        );

        // Registrar en log
        if (class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
            \MiIntegracionApi\Helpers\Logger::info('Caché del API Connector purgada manualmente', [
                'user_id' => get_current_user_id(),
                'timestamp' => current_time('mysql')
            ], 'cache-handler');
        }

        wp_send_json_success(['message' => 'Caché purgada correctamente']);
    }
}

// Inicializar la clase
new CacheHandler();