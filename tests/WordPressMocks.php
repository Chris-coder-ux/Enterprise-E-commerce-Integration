<?php
/**
 * Mocks compartidos de WordPress para tests
 *
 * Este archivo contiene todos los mocks de funciones de WordPress
 * que se reutilizan en múltiples tests.
 *
 * @package MiIntegracionApi\Tests
 * @since 1.5.0
 */

namespace {
// Definir constantes necesarias si no existen
if (!defined('ABSPATH')) {
    // Intentar cargar WordPress
    $wp_load = dirname(__FILE__) . '/../../../../wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        // Si no está disponible, usar modo standalone
        define('ABSPATH', dirname(__FILE__) . '/../../../');
    }
}

// Definir constantes de WordPress que pueden no existir en modo standalone
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
}

// Variables globales para mocks de base de datos
global $wpdb, $mock_postmeta_storage;
if (!isset($GLOBALS['mock_postmeta_storage'])) {
    $GLOBALS['mock_postmeta_storage'] = [];
}
$mock_postmeta_storage = &$GLOBALS['mock_postmeta_storage'];

// Mock de funciones de WordPress en namespace GLOBAL
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        static $options = [];
        return $options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        static $options = [];
        $options[$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        static $options = [];
        unset($options[$option]);
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        static $transients = [];
        return $transients[$transient] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        static $transients = [];
        $transients[$transient] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        static $transients = [];
        unset($transients[$transient]);
        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        global $mock_postmeta_storage;
        if (!isset($mock_postmeta_storage[$post_id])) {
            return $single ? '' : [''];
        }
        
        if (empty($key)) {
            return $mock_postmeta_storage[$post_id];
        }
        
        $value = $mock_postmeta_storage[$post_id][$key] ?? '';
        return $single ? $value : [$value];
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value) {
        global $mock_postmeta_storage;
        if (!isset($mock_postmeta_storage[$post_id])) {
            $mock_postmeta_storage[$post_id] = [];
        }
        $mock_postmeta_storage[$post_id][$meta_key] = $meta_value;
        return true;
    }
}

if (!function_exists('wp_insert_attachment')) {
    function wp_insert_attachment($attachment, $file, $parent_id = 0) {
        static $attachment_id_counter = 1;
        return $attachment_id_counter++;
    }
}

if (!function_exists('wp_generate_attachment_metadata')) {
    function wp_generate_attachment_metadata($attachment_id, $file) {
        return [
            'width' => 800,
            'height' => 600,
            'file' => $file
        ];
    }
}

if (!function_exists('wp_update_attachment_metadata')) {
    function wp_update_attachment_metadata($attachment_id, $data) {
        return true;
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null) {
        return [
            'path' => sys_get_temp_dir() . '/wp-uploads',
            'url' => 'http://example.com/wp-content/uploads',
            'subdir' => '',
            'basedir' => sys_get_temp_dir() . '/wp-uploads',
            'baseurl' => 'http://example.com/wp-content/uploads',
            'error' => false
        ];
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return false; // Simplificado para tests
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($target) {
        $target = rtrim($target, '/');
        if (empty($target)) {
            $target = '/';
        }
        
        if (file_exists($target)) {
            return @is_dir($target);
        }
        
        if (@mkdir($target, 0755, true)) {
            return true;
        } elseif (is_dir(dirname($target))) {
            return false;
        }
        
        if ((dirname($target) != $target) && wp_mkdir_p(dirname($target))) {
            return wp_mkdir_p($target);
        }
        
        return false;
    }
}

// Mock de funciones helper del plugin
if (!function_exists('mi_integracion_api_upload_bits_safe')) {
    function mi_integracion_api_upload_bits_safe($name, $deprecated, $bits) {
        $upload_dir = wp_upload_dir();
        $filename = $upload_dir['basedir'] . '/' . $name;
        
        // Crear directorio si no existe
        wp_mkdir_p(dirname($filename));
        
        // Escribir archivo
        if (file_put_contents($filename, $bits) !== false) {
            return [
                'file' => $filename,
                'url' => $upload_dir['baseurl'] . '/' . $name,
                'error' => false
            ];
        }
        
        return false;
    }
}

if (!function_exists('mi_integracion_api_sanitize_file_name_safe')) {
    function mi_integracion_api_sanitize_file_name_safe($filename) {
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    }
}

if (!function_exists('get_posts')) {
    function get_posts($args = []) {
        global $mock_postmeta_storage;
        
        // Extraer article_id de meta_query
        $article_id = null;
        if (isset($args['meta_query']) && is_array($args['meta_query']) && !empty($args['meta_query'])) {
            $first_query = $args['meta_query'][0];
            if (isset($first_query['key']) && $first_query['key'] === '_verial_article_id') {
                $article_id = $first_query['value'] ?? null;
            }
        }
        
        if ($article_id === null) {
            return [];
        }
        
        $attachment_ids = [];
        
        // Buscar en mock_postmeta_storage
        foreach ($mock_postmeta_storage as $post_id => $meta) {
            if (isset($meta['_verial_article_id']) && $meta['_verial_article_id'] == $article_id) {
                $attachment_ids[] = $post_id;
            }
        }
        
        return $attachment_ids;
    }
}

if (!function_exists('absint')) {
    function absint($maybeint) {
        return abs((int) $maybeint);
    }
}

// Mock de $wpdb
if (!isset($wpdb)) {
    $wpdb = new class {
        public $postmeta = 'wp_postmeta';
        
        public function get_var($query) {
            global $mock_postmeta_storage;
            
            // Buscar en mock_postmeta_storage
            // El query debería buscar por meta_key = '_verial_image_hash' y meta_value
            if (preg_match("/meta_key = ['\"]_verial_image_hash['\"]/", $query) && 
                preg_match("/meta_value = ['\"]?([^'\"]+)['\"]?/", $query, $matches)) {
                $hash = $matches[1];
                
                // Buscar en mock_postmeta_storage
                foreach ($mock_postmeta_storage as $post_id => $meta) {
                    if (isset($meta['_verial_image_hash']) && $meta['_verial_image_hash'] === $hash) {
                        return (string)$post_id;
                    }
                }
            }
            
            return null;
        }
        
        public function prepare($query, ...$args) {
            // Reemplazar placeholders %s y %d con valores reales
            $prepared = $query;
            foreach ($args as $arg) {
                if (is_string($arg)) {
                    $prepared = preg_replace('/%s/', "'" . addslashes($arg) . "'", $prepared, 1);
                } elseif (is_int($arg)) {
                    $prepared = preg_replace('/%d/', (string)$arg, $prepared, 1);
                }
            }
            return $prepared;
        }
    };
}

// Cargar EmergencyLoader primero (para clases críticas)
$emergency_loader = dirname(__FILE__) . '/../includes/Core/EmergencyLoader.php';
if (file_exists($emergency_loader)) {
    require_once $emergency_loader;
    if (class_exists('MiIntegracionApi\Core\EmergencyLoader')) {
        \MiIntegracionApi\Core\EmergencyLoader::init();
    }
}

// Cargar autoloader de Composer después
$autoloader = dirname(__FILE__) . '/../vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}
}


