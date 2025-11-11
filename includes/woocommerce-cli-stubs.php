<?php
/**
 * Simulación de funciones de WooCommerce para uso en CLI
 */
declare(strict_types=1);

// Solo definir funciones si no existen (evitar conflictos si WP está cargado)
if (!function_exists('wc_format_decimal')) {
    function wc_format_decimal($number, $dp = null) {
        if ($dp === null) {
            $dp = wc_get_price_decimals();
        }
        return number_format((float)$number, $dp, '.', '');
    }
}

if (!function_exists('wc_stock_amount')) {
    function wc_stock_amount($amount) {
        return intval($amount);
    }
}

if (!function_exists('wc_get_price_decimals')) {
    function wc_get_price_decimals() {
        return 2; // Por defecto 2 decimales
    }
}

if (!function_exists('current_time')) {
    function current_time($type = 'timestamp', $gmt = 0) {
        switch ($type) {
            case 'mysql':
                return gmdate('Y-m-d H:i:s');
            case 'timestamp':
                return time();
            default:
                return gmdate('Y-m-d H:i:s');
        }
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data) {
        return strip_tags($data, '<p><br><strong><em><ul><ol><li><a><img>');
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string) {
        return strip_tags($string);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value = null, ...$args) {
        return $value; // En CLI simplemente retornamos el valor sin filtrar
    }
}

if (!function_exists('do_action')) {
    function do_action($tag, ...$args) {
        // En CLI no hacemos nada con las acciones
        return null;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($tag, $function_to_add, $priority = 10, $accepted_args = 1) {
        // En CLI no registramos filtros
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action($tag, $function_to_add, $priority = 10, $accepted_args = 1) {
        // En CLI no registramos acciones
        return true;
    }
}

if (!function_exists('get_terms')) {
    function get_terms($args = array()) {
        // En CLI retornamos array vacío para las categorías
        return array();
    }
}

if (!function_exists('wp_insert_term')) {
    function wp_insert_term($term, $taxonomy, $args = array()) {
        // En CLI simulamos la creación de términos
        return array(
            'term_id' => rand(1000, 9999),
            'term_taxonomy_id' => rand(1000, 9999)
        );
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return false; // En CLI no hay errores WP
    }
}

if (!function_exists('update_term_meta')) {
    function update_term_meta($term_id, $meta_key, $meta_value, $prev_value = '') {
        // En CLI no hacemos nada real, solo simulamos éxito
        return true;
    }
}

if (!function_exists('wc_format_decimal')) {
    function wc_format_decimal($decimal, $dp = false, $trim_zeros = false) {
        // Simular formateo decimal de WooCommerce
        return (float) $decimal;
    }
}

if (!function_exists('wc_stock_amount')) {
    function wc_stock_amount($stock_amount) {
        // Simular cantidad de stock de WooCommerce
        return (int) $stock_amount;
    }
}

// =============================================================================
// FUNCIONES AVANZADAS DE WOOCOMMERCE PARA TESTING
// =============================================================================

// Base de datos simulada para productos
global $test_wc_products;
$test_wc_products = [];

// Función para obtener ID de producto por SKU
if (!function_exists('wc_get_product_id_by_sku')) {
    function wc_get_product_id_by_sku($sku) {
        global $test_wc_products;
        foreach ($test_wc_products as $id => $product) {
            if (isset($product['sku']) && $product['sku'] === $sku) {
                return $id;
            }
        }
        return 0;
    }
}

// Función para obtener producto por ID
if (!function_exists('wc_get_product')) {
    function wc_get_product($product_id) {
        global $test_wc_products;
        if (isset($test_wc_products[$product_id])) {
            return new WC_Product($product_id, $test_wc_products[$product_id]);
        }
        return false;
    }
}

// Clase simulada WC_Product
if (!class_exists('WC_Product')) {
    class WC_Product {
        private $id;
        private $data;
        
        public function __construct($id = 0, $data = []) {
            $this->id = $id;
            $this->data = $data ?: [
                'name' => '',
                'description' => '',
                'short_description' => '',
                'sku' => '',
                'price' => 0,
                'regular_price' => 0,
                'sale_price' => 0,
                'stock_quantity' => 0,
                'status' => 'publish'
            ];
        }
        
        public function get_id() {
            return $this->id;
        }
        
        public function set_name($name) {
            $this->data['name'] = $name;
        }
        
        public function set_description($description) {
            $this->data['description'] = $description;
        }
        
        public function set_short_description($short_description) {
            $this->data['short_description'] = $short_description;
        }
        
        public function set_sku($sku) {
            $this->data['sku'] = $sku;
        }
        
        public function set_regular_price($price) {
            $this->data['regular_price'] = $price;
        }
        
        public function set_sale_price($price) {
            $this->data['sale_price'] = $price;
        }
        
        public function set_price($price) {
            $this->data['price'] = $price;
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
        
        public function set_weight($weight) {
            $this->data['weight'] = $weight;
        }
        
        public function set_dimensions($dimensions) {
            $this->data['dimensions'] = $dimensions;
        }
        
        public function set_category_ids($category_ids) {
            $this->data['category_ids'] = $category_ids;
        }
        
        public function set_status($status) {
            $this->data['status'] = $status;
        }
        
        public function update_meta_data($key, $value) {
            // Simular meta data storage
            if (!isset($this->data['meta_data'])) {
                $this->data['meta_data'] = [];
            }
            $this->data['meta_data'][$key] = $value;
        }
        
        public function save() {
            global $test_wc_products;
            
            // Si no tiene ID, crear uno nuevo
            if (empty($this->id)) {
                $this->id = count($test_wc_products) + 1;
            }
            
            // Guardar en la base de datos simulada
            $test_wc_products[$this->id] = $this->data;
            
            echo "        ✅ Producto simulado guardado/actualizado: ID={$this->id}, SKU={$this->data['sku']}, Nombre={$this->data['name']}\n";
            
            return $this->id;
        }
    }
}

// Función para crear nuevo producto
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($product_args) {
        global $test_wc_products;
        
        $new_id = count($test_wc_products) + 1000; // IDs altos para nuevos productos
        
        $test_wc_products[$new_id] = [
            'name' => $product_args['name'] ?? '',
            'description' => $product_args['description'] ?? '',
            'short_description' => $product_args['short_description'] ?? '',
            'status' => $product_args['status'] ?? 'publish'
        ];
        
        echo "        ✅ Nuevo producto simulado creado: ID={$new_id}\n";
        
        return $new_id;
    }
}

// Funciones de WordPress para CLI
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) {
        return substr(md5($action . 'test_salt_' . time()), 0, 10);
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) {
        return true; // En testing, todos los nonces son válidos
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = '_ajax_nonce', $die = true) {
        return true;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability, $object_id = null) {
        return true; // En testing, el usuario siempre tiene permisos
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return 1; // Usuario administrador de testing
    }
}

if (!function_exists('get_userdata')) {
    function get_userdata($user_id) {
        $user = new stdClass();
        $user->ID = $user_id;
        $user->user_login = 'admin_test';
        $user->user_email = 'admin@test.com';
        $user->allcaps = ['manage_options' => true];
        return $user;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '', $scheme = 'admin') {
        return 'http://localhost/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null) {
        $response = ['success' => true];
        if ($data !== null) {
            $response['data'] = $data;
        }
        echo json_encode($response);
        exit;
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null) {
        $response = ['success' => false];
        if ($data !== null) {
            $response['data'] = $data;
        }
        echo json_encode($response);
        exit;
    }
}

if (!function_exists('wp_send_json')) {
    function wp_send_json($response, $status_code = null) {
        echo json_encode($response);
        exit;
    }
}

// Simular objeto global $wpdb para testing
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public $options = 'wp_options';
        
        public function prepare($query, ...$args) {
            // Simular prepare devolviendo una query simple para testing
            return "SELECT * FROM wp_options WHERE 1=0"; // Query que no devuelve nada
        }
        
        public function query($query) {
            // Simular ejecución de query
            return 0; // 0 filas afectadas
        }
        
        public function esc_like($text) {
            // Simular escape de caracteres LIKE
            return str_replace(['%', '_'], ['\\%', '\\_'], $text);
        }
        
        public function get_col($query) {
            // Simular que no hay transients que limpiar
            return [];
        }
        
        public function delete($table, $where, $where_format = null) {
            // Simular borrado exitoso
            return 1;
        }
        
        public function get_var($query) {
            // Simular que no hay resultados
            return null;
        }
        
        public function get_results($query) {
            // Simular que no hay resultados
            return [];
        }
        
        public function insert($table, $data, $format = null) {
            // Simular inserción exitosa
            return 1;
        }
        
        public function update($table, $data, $where, $format = null, $where_format = null) {
            // Simular actualización exitosa
            return 1;
        }
    };
}

echo "✅ Funciones de WooCommerce simuladas para CLI\n";
