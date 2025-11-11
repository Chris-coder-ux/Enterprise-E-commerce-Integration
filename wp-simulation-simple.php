<?php
/**
 * Simulación simple de funciones de WordPress para tests de sincronización
 */

// Simular funciones básicas de WordPress
if (!function_exists('get_option')) {
    function get_option($option_name, $default = false) {
        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option_name, $value, $autoload = true) {
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option_name) {
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        return false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration) {
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time($type = 'mysql') {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        return [
            'path' => 'uploads/',
            'url' => 'uploads/',
            'subdir' => '',
            'basedir' => 'uploads/',
            'baseurl' => 'uploads/',
            'error' => false
        ];
    }
}

if (!function_exists('dbDelta')) {
    function dbDelta($queries) {
        return true;
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($target) {
        if (is_dir($target)) {
            return true;
        }
        return mkdir($target, 0755, true);
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('remove_action')) {
    function remove_action($hook, $callback, $priority = 10) {
        return true;
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter($hook, $callback, $priority = 10) {
        return true;
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        return $value;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {
        return true;
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {
        return true;
    }
}

if (!function_exists('register_uninstall_hook')) {
    function register_uninstall_hook($file, $callback) {
        return true;
    }
}

// Simular funciones de WooCommerce
if (!function_exists('wc_format_decimal')) {
    function wc_format_decimal($number, $decimals = 2) {
        return number_format((float)$number, $decimals, '.', '');
    }
}

if (!function_exists('wc_stock_amount')) {
    function wc_stock_amount($amount) {
        return (int)$amount;
    }
}

if (!function_exists('get_term')) {
    function get_term($term_id, $taxonomy) {
        return (object)[
            'term_id' => $term_id,
            'name' => 'Categoría Test',
            'slug' => 'categoria-test'
        ];
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return false;
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post($postarr, $wp_error = false) {
        return 12345; // ID simulado
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post($postarr, $wp_error = false) {
        return 12345; // ID simulado
    }
}

if (!function_exists('get_post')) {
    function get_post($post = null, $output = OBJECT, $filter = 'raw') {
        return (object)[
            'ID' => 12345,
            'post_title' => 'Producto Test',
            'post_status' => 'publish',
            'post_type' => 'product'
        ];
    }
}

if (!function_exists('wp_set_post_terms')) {
    function wp_set_post_terms($post_id, $terms, $taxonomy, $append = false) {
        return true;
    }
}

if (!function_exists('wp_set_object_terms')) {
    function wp_set_object_terms($object_id, $terms, $taxonomy, $append = false) {
        return true;
    }
}

if (!function_exists('get_terms')) {
    function get_terms($args = []) {
        return [];
    }
}

if (!function_exists('term_exists')) {
    function term_exists($term, $taxonomy = '', $parent = null) {
        return false;
    }
}

if (!function_exists('wp_insert_term')) {
    function wp_insert_term($term, $taxonomy, $args = []) {
        return ['term_id' => 12345, 'term_taxonomy_id' => 12345];
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '') {
        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        return $single ? '' : [];
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $meta_key, $meta_value = '') {
        return true;
    }
}

// Simular clase WC_Product
if (!class_exists('WC_Product')) {
    class WC_Product {
        private $id;
        private $data = [];
        
        public function __construct($product = 0) {
            $this->id = $product;
        }
        
        public function set_name($name) {
            $this->data['name'] = $name;
        }
        
        public function set_sku($sku) {
            $this->data['sku'] = $sku;
        }
        
        public function set_price($price) {
            $this->data['price'] = $price;
        }
        
        public function set_regular_price($price) {
            $this->data['regular_price'] = $price;
        }
        
        public function set_sale_price($price) {
            $this->data['sale_price'] = $price;
        }
        
        public function set_stock_quantity($quantity) {
            $this->data['stock_quantity'] = $quantity;
        }
        
        public function set_manage_stock($manage) {
            $this->data['manage_stock'] = $manage;
        }
        
        public function set_stock_status($status) {
            $this->data['stock_status'] = $status;
        }
        
        public function set_catalog_visibility($visibility) {
            $this->data['catalog_visibility'] = $visibility;
        }
        
        public function set_featured($featured) {
            $this->data['featured'] = $featured;
        }
        
        public function set_status($status) {
            $this->data['status'] = $status;
        }
        
        public function set_description($description) {
            $this->data['description'] = $description;
        }
        
        public function set_short_description($description) {
            $this->data['short_description'] = $description;
        }
        
        public function set_weight($weight) {
            $this->data['weight'] = $weight;
        }
        
        public function set_length($length) {
            $this->data['length'] = $length;
        }
        
        public function set_width($width) {
            $this->data['width'] = $width;
        }
        
        public function set_height($height) {
            $this->data['height'] = $height;
        }
        
        public function set_category_ids($ids) {
            $this->data['category_ids'] = $ids;
        }
        
        public function set_image_id($id) {
            $this->data['image_id'] = $id;
        }
        
        public function set_gallery_image_ids($ids) {
            $this->data['gallery_image_ids'] = $ids;
        }
        
        public function save() {
            return $this->id;
        }
        
        public function get_id() {
            return $this->id;
        }
        
        public function get_name() {
            return $this->data['name'] ?? '';
        }
        
        public function get_sku() {
            return $this->data['sku'] ?? '';
        }
        
        public function get_price() {
            return $this->data['price'] ?? '';
        }
        
        public function get_regular_price() {
            return $this->data['regular_price'] ?? '';
        }
        
        public function get_sale_price() {
            return $this->data['sale_price'] ?? '';
        }
        
        public function get_stock_quantity() {
            return $this->data['stock_quantity'] ?? null;
        }
        
        public function get_manage_stock() {
            return $this->data['manage_stock'] ?? false;
        }
        
        public function get_stock_status() {
            return $this->data['stock_status'] ?? 'instock';
        }
        
        public function get_catalog_visibility() {
            return $this->data['catalog_visibility'] ?? 'visible';
        }
        
        public function get_featured() {
            return $this->data['featured'] ?? false;
        }
        
        public function get_status() {
            return $this->data['status'] ?? 'publish';
        }
        
        public function get_description() {
            return $this->data['description'] ?? '';
        }
        
        public function get_short_description() {
            return $this->data['short_description'] ?? '';
        }
        
        public function get_weight() {
            return $this->data['weight'] ?? '';
        }
        
        public function get_length() {
            return $this->data['length'] ?? '';
        }
        
        public function get_width() {
            return $this->data['width'] ?? '';
        }
        
        public function get_height() {
            return $this->data['height'] ?? '';
        }
        
        public function get_category_ids() {
            return $this->data['category_ids'] ?? [];
        }
        
        public function get_image_id() {
            return $this->data['image_id'] ?? '';
        }
        
        public function get_gallery_image_ids() {
            return $this->data['gallery_image_ids'] ?? [];
        }
    }
}

// Simular clase WP_Error
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $errors = [];
        private $error_data = [];
        
        public function __construct($code = '', $message = '', $data = '') {
            if (!empty($code)) {
                $this->errors[$code][] = $message;
                if (!empty($data)) {
                    $this->error_data[$code] = $data;
                }
            }
        }
        
        public function get_error_code() {
            return !empty($this->errors) ? array_keys($this->errors)[0] : '';
        }
        
        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return isset($this->errors[$code]) ? $this->errors[$code][0] : '';
        }
        
        public function get_error_data($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return isset($this->error_data[$code]) ? $this->error_data[$code] : '';
        }
        
        public function add($code, $message, $data = '') {
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }
    }
}

// Definir constantes necesarias
if (!defined('ABSPATH')) {
    define('ABSPATH', '/fake/wordpress/path/');
}

if (!defined('MiIntegracionApi_OPTION_PREFIX')) {
    define('MiIntegracionApi_OPTION_PREFIX', 'mia_');
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}

if (!defined('MONTH_IN_SECONDS')) {
    define('MONTH_IN_SECONDS', 2592000);
}

if (!defined('YEAR_IN_SECONDS')) {
    define('YEAR_IN_SECONDS', 31536000);
}

if (!defined('MiIntegracionApi_PLUGIN_DIR')) {
    define('MiIntegracionApi_PLUGIN_DIR', __DIR__);
}

if (!defined('MiIntegracionApi_PLUGIN_FILE')) {
    define('MiIntegracionApi_PLUGIN_FILE', 'mi-integracion-api.php');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}

// Crear directorio de caché
if (!is_dir('cache/')) {
    mkdir('cache/', 0755, true);
}
