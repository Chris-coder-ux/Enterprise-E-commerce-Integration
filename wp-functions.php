<?php
/**
 * Implementación de funciones WordPress para entorno de pruebas
 * 
 * Este archivo implementa las funciones mínimas de WordPress necesarias
 * para que el ApiConnector y otras clases puedan funcionar en un entorno
 * de desarrollo sin WordPress.
 */

define('ABSPATH', __DIR__ . '/');

// Constantes para get_term_by()
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

// Definir constantes del plugin que podrían estar undefined
if (!defined('MiIntegracionApi_PLUGIN_URL')) {
    define('MiIntegracionApi_PLUGIN_URL', 'http://localhost/wp-content/plugins/mi-integracion-api/');
}

if (!defined('MiIntegracionApi_PLUGIN_DIR')) {
    define('MiIntegracionApi_PLUGIN_DIR', __DIR__ . '/');
}

if (!defined('MiIntegracionApi_PLUGIN_FILE')) {
    define('MiIntegracionApi_PLUGIN_FILE', __FILE__);
}

// Opciones simuladas
global $test_wp_options, $test_wp_hooks, $test_wp_filters;
$test_wp_options = [];
$test_wp_hooks = [];
$test_wp_filters = [];

// Sistema de hooks simulado
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        global $test_wp_hooks;
        if (!isset($test_wp_hooks[$hook])) {
            $test_wp_hooks[$hook] = [];
        }
        $test_wp_hooks[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'args' => $accepted_args
        ];
        return true;
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        global $test_wp_hooks;
        if (isset($test_wp_hooks[$hook])) {
            foreach ($test_wp_hooks[$hook] as $action) {
                if (is_callable($action['callback'])) {
                    call_user_func_array($action['callback'], $args);
                }
            }
        }
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        global $test_wp_filters;
        if (!isset($test_wp_filters[$hook])) {
            $test_wp_filters[$hook] = [];
        }
        $test_wp_filters[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'args' => $accepted_args
        ];
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        global $test_wp_filters;
        if (isset($test_wp_filters[$hook])) {
            foreach ($test_wp_filters[$hook] as $filter) {
                if (is_callable($filter['callback'])) {
                    $value = call_user_func($filter['callback'], $value, ...$args);
                }
            }
        }
        return $value;
    }
}

if (!function_exists('remove_action')) {
    function remove_action($hook, $callback, $priority = 10) {
        global $test_wp_hooks;
        if (isset($test_wp_hooks[$hook])) {
            foreach ($test_wp_hooks[$hook] as $key => $action) {
                if ($action['callback'] === $callback && $action['priority'] === $priority) {
                    unset($test_wp_hooks[$hook][$key]);
                    return true;
                }
            }
        }
        return false;
    }
}

// Verificar si un archivo es una opción
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return is_object($thing) && is_a($thing, 'WP_Error');
    }
}

// Clase WP_Error mínima
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = [];
        public $error_data = [];
        
        public function __construct($code = '', $message = '', $data = '') {
            if (!empty($code)) {
                $this->errors[$code] = [$message];
                $this->error_data[$code] = $data;
            }
        }
        
        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            if (isset($this->errors[$code]) && !empty($this->errors[$code])) {
                return $this->errors[$code][0];
            }
            return '';
        }
        
        public function get_error_code() {
            if (!empty($this->errors)) {
                $codes = array_keys($this->errors);
                return $codes[0];
            }
            return '';
        }
        
        public function get_error_data($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return isset($this->error_data[$code]) ? $this->error_data[$code] : null;
        }
    }
}

// Funciones de opciones
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $test_wp_options;
        $options_file = __DIR__ . '/test_options.json';
        
        // Cargar opciones del archivo si no están cargadas en memoria
        if (empty($test_wp_options) && file_exists($options_file)) {
            $test_wp_options = json_decode(file_get_contents($options_file), true);
        }
        
        return isset($test_wp_options[$option]) ? $test_wp_options[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        global $test_wp_options;
        $options_file = __DIR__ . '/test_options.json';
        
        // Cargar opciones del archivo si no están cargadas en memoria
        if (empty($test_wp_options) && file_exists($options_file)) {
            $test_wp_options = json_decode(file_get_contents($options_file), true);
        }
        
        // Actualizar opción
        $test_wp_options[$option] = $value;
        
        // Guardar en archivo
        file_put_contents($options_file, json_encode($test_wp_options, JSON_PRETTY_PRINT));
        
        return true;
    }
}

if (!function_exists('add_option')) {
    function add_option($option, $value = '', $deprecated = '', $autoload = 'yes') {
        if (get_option($option) === false) {
            return update_option($option, $value);
        }
        return false;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        global $test_wp_options;
        $options_file = __DIR__ . '/test_options.json';
        
        // Cargar opciones del archivo si no están cargadas en memoria
        if (empty($test_wp_options) && file_exists($options_file)) {
            $test_wp_options = json_decode(file_get_contents($options_file), true);
        }
        
        // Eliminar opción
        if (isset($test_wp_options[$option])) {
            unset($test_wp_options[$option]);
            
            // Guardar en archivo
            file_put_contents($options_file, json_encode($test_wp_options, JSON_PRETTY_PRINT));
            
            return true;
        }
        
        return false;
    }
}

// Funciones de hooks (apply_filters y do_action)
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        // En pruebas, simplemente devolvemos el valor original
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action($tag, ...$args) {
        // En pruebas, no hacemos nada
        return;
    }
}

// Funciones de transients
if (!function_exists('get_transient')) {
    function get_transient($transient) {
        return get_option('_transient_' . $transient, false);
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        $transient_timeout = '_transient_timeout_' . $transient;
        $transient_option = '_transient_' . $transient;
        
        if ($expiration) {
            update_option($transient_timeout, time() + $expiration);
        }
        
        return update_option($transient_option, $value);
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        $option_timeout = '_transient_timeout_' . $transient;
        $option = '_transient_' . $transient;
        
        delete_option($option_timeout);
        return delete_option($option);
    }
}

// Funciones varias de WordPress que puedan necesitarse
if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = []) {
        if (is_object($args)) {
            $r = get_object_vars($args);
        } elseif (is_array($args)) {
            $r =& $args;
        } else {
            parse_str($args, $r);
        }
        
        return array_merge($defaults, $r);
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $key = strtolower($key);
        $key = preg_replace('/[^a-z0-9_\-]/', '', $key);
        return $key;
    }
}

// Funciones de traducción de WordPress
if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        // En pruebas, simplemente devolver el texto original
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e($text, $domain = 'default') {
        echo __($text, $domain);
    }
}

if (!function_exists('_x')) {
    function _x($text, $context, $domain = 'default') {
        return __($text, $domain);
    }
}

// Funciones de sanitización adicionales de WordPress
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($str) {
        return trim(strip_tags($str, '<p><br><strong><em>'));
    }
}

// Función para logging de errores
if (!function_exists('error_log')) {
    // error_log ya existe en PHP, no necesitamos redefinirla
}

// Función para debug backtrace
if (!function_exists('wp_debug_backtrace_summary')) {
    function wp_debug_backtrace_summary($ignore_class = null, $skip_frames = 0, $pretty = true) {
        return 'Test backtrace';
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) {
        // Implementación simple usando cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'body' => $output,
            'response' => [
                'code' => $httpcode
            ]
        ];
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = []) {
        // Implementación simple usando cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        
        if (isset($args['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $args['body']);
        }
        
        if (isset($args['headers'])) {
            $headers = [];
            foreach ($args['headers'] as $key => $value) {
                $headers[] = "$key: $value";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'body' => $output,
            'response' => [
                'code' => $httpcode
            ]
        ];
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return isset($response['body']) ? $response['body'] : '';
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return isset($response['response']['code']) ? $response['response']['code'] : 0;
    }
}

// Función current_time para WordPress
if (!function_exists('get_posts')) {
    function get_posts($args = array()) {
        global $test_wc_products;
        
        // Versión simplificada para testing
        // En un entorno real buscaría posts de WordPress
        return array();
    }
}

if (!function_exists('update_term_meta')) {
    function update_term_meta($term_id, $meta_key, $meta_value, $prev_value = '') {
        // Versión simplificada para testing
        return true;
    }
}

if (!function_exists('get_terms')) {
    function get_terms($args = array()) {
        // Versión simplificada para testing
        return array();
    }
}

if (!function_exists('term_exists')) {
    function term_exists($term, $taxonomy = '', $parent = null) {
        // Versión simplificada para testing
        return false; // En un entorno real buscaría si el término existe
    }
}

if (!function_exists('wp_insert_term')) {
    function wp_insert_term($term, $taxonomy, $args = array()) {
        // Versión simplificada para testing
        static $term_counter = 1000;
        $term_counter++;
        
        return array(
            'term_id' => $term_counter,
            'term_taxonomy_id' => $term_counter
        );
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data) {
        // Versión simplificada para testing - permite HTML básico
        return strip_tags($data, '<p><br><strong><em><a><ul><ol><li>');
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        switch ($type) {
            case 'mysql':
                return $gmt ? gmdate('Y-m-d H:i:s') : date('Y-m-d H:i:s');
            case 'timestamp':
                return $gmt ? time() : time() + (get_option('gmt_offset', 0) * HOUR_IN_SECONDS);
            case 'U':
                return time();
            default:
                return $gmt ? gmdate($type) : date($type);
        }
    }
}

// Definir constantes necesarias de WordPress si no existen
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
if (!defined('WEEK_IN_SECONDS')) define('WEEK_IN_SECONDS', 604800);
if (!defined('MONTH_IN_SECONDS')) define('MONTH_IN_SECONDS', 2592000);
if (!defined('YEAR_IN_SECONDS')) define('YEAR_IN_SECONDS', 31536000);

// =============================================================================
// FUNCIONES ADICIONALES DE WORDPRESS PARA EL PLUGIN
// =============================================================================

// Funciones adicionales de WordPress para el plugin
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '') {
        echo "📝 Post meta actualizado: post_id=$post_id, key=$meta_key, value=" . substr(print_r($meta_value, true), 0, 50) . "...\n";
        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        // Versión simplificada para testing
        return $single ? '' : array();
    }
}

if (!function_exists('wp_set_object_terms')) {
    function wp_set_object_terms($object_id, $terms, $taxonomy, $append = false) {
        echo "🏷️  Términos asignados: post_id=$object_id, taxonomy=$taxonomy, terms=" . print_r($terms, true) . "\n";
        return array();
    }
}

if (!function_exists('wp_get_object_terms')) {
    function wp_get_object_terms($object_ids, $taxonomies, $args = array()) {
        // Versión simplificada para testing
        return array();
    }
}

if (!function_exists('wp_delete_post')) {
    function wp_delete_post($postid = 0, $force_delete = false) {
        echo "🗑️  Post eliminado: post_id=$postid\n";
        return true;
    }
}

// Clases WordPress/WooCommerce simuladas
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $params = [];
        private $method = 'GET';
        private $route = '';
        
        public function __construct($method = 'GET', $route = '') {
            $this->method = $method;
            $this->route = $route;
        }
        
        public function set_param($key, $value) {
            $this->params[$key] = $value;
        }
        
        public function get_param($key) {
            return $this->params[$key] ?? null;
        }
        
        public function get_params() {
            return $this->params;
        }
        
        public function get_method() {
            return $this->method;
        }
        
        public function get_route() {
            return $this->route;
        }
        
        public function get_header($name) {
            return null; // Simulado para testing
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        private $data;
        private $status;
        
        public function __construct($data = null, $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }
        
        public function get_data() {
            return $this->data;
        }
        
        public function get_status() {
            return $this->status;
        }
    }
}

if (!function_exists('rest_ensure_response')) {
    function rest_ensure_response($response) {
        if ($response instanceof WP_REST_Response) {
            return $response;
        }
        return new WP_REST_Response($response);
    }
}

if (!function_exists('rest_authorization_required_code')) {
    function rest_authorization_required_code() {
        return 401;
    }
}

if (!function_exists('wp_hash')) {
    function wp_hash($data, $scheme = 'auth') {
        return hash('sha256', $data . 'test_salt_' . $scheme);
    }
}

if (!function_exists('absint')) {
    function absint($maybeint) {
        return abs((int) $maybeint);
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($title, $fallback_title = '', $context = 'save') {
        $title = strip_tags($title);
        // Eliminar acentos
        $title = remove_accents($title);
        // Convertir a minúsculas y reemplazar espacios con guiones
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9\-_]/', '-', $title);
        $title = preg_replace('/-+/', '-', $title);
        $title = trim($title, '-');
        
        if (empty($title) && !empty($fallback_title)) {
            return sanitize_title($fallback_title, '', $context);
        }
        
        return $title ?: 'untitled';
    }
}

if (!function_exists('remove_accents')) {
    function remove_accents($string) {
        if (!preg_match('/[\x80-\xff]/', $string)) {
            return $string;
        }
        
        $chars = array(
            // Decompositions for Latin-1 Supplement
            'ª' => 'a', 'º' => 'o',
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'Æ' => 'AE','Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ð' => 'D', 'Ñ' => 'N',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ù' => 'U',
            'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Þ' => 'TH','ß' => 's',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'æ' => 'ae','ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ð' => 'd', 'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'þ' => 'th',
            'ÿ' => 'y'
        );
        
        return strtr($string, $chars);
    }
}

if (!function_exists('get_term_by')) {
    function get_term_by($field, $value, $taxonomy = '', $output = OBJECT, $filter = 'raw') {
        // Para el contexto de testing, devolvemos null (término no encontrado)
        // En WordPress real, esto buscaría términos en la base de datos
        return null;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        // Función de WordPress para codificar JSON
        // Maneja casos especiales de WordPress como caracteres especiales
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = 'default') {
        // Función de WordPress para pluralización
        // En entorno de test, simplemente elegimos entre singular/plural
        return ($number == 1) ? $single : $plural;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        // Función de WordPress para traducción
        // En entorno de test, devolvemos el texto tal como está
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e($text, $domain = 'default') {
        // Función de WordPress para traducción con echo
        // En entorno de test, simplemente imprimimos el texto
        echo $text;
    }
}

if (!function_exists('_x')) {
    function _x($text, $context, $domain = 'default') {
        // Función de WordPress para traducción con contexto
        // En entorno de test, devolvemos el texto tal como está
        return $text;
    }
}

// Simulación de $wpdb para entorno de pruebas
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public $posts = 'wp_posts';
        public $postmeta = 'wp_postmeta';
        public $termmeta = 'wp_termmeta';
        public $options = 'wp_options';
        public $prefix = 'wp_';
        
        public function prepare($query, ...$args) {
            // Simulación básica de prepare - en un entorno real esto evitaría SQL injection
            $prepared = $query;
            foreach ($args as $arg) {
                if (is_array($arg)) {
                    $arg = implode(',', array_map('addslashes', $arg));
                } else {
                    $arg = addslashes($arg);
                }
                $prepared = preg_replace('/\%[sd]/', "'" . $arg . "'", $prepared, 1);
            }
            return $prepared;
        }
        
        public function get_results($query, $output = OBJECT) {
            // Para pruebas devolvemos array vacío
            return [];
        }
        
        public function get_var($query) {
            // Para pruebas devolvemos null
            return null;
        }
        
        public function query($query) {
            // Para pruebas devolvemos 0 (no hay filas afectadas)
            return 0;
        }
        
        public function esc_like($text) {
            // Escapa caracteres especiales para LIKE
            return addcslashes($text, '_%\\');
        }
        
        public function get_col($query, $column = 0) {
            // Para pruebas devolvemos array vacío
            return [];
        }
    };
}

// Función de sanitización de email
if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
}

// Función de sanitización de texto  
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

// Función para sanitizar claves
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

// Función para escapar HTML
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

// Función para escapar atributos
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
