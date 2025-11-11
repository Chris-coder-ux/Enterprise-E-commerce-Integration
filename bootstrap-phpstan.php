<?php
// Bootstrap para PHPStan: define stubs mínimos cuando no se ejecuta dentro de WordPress
if (!function_exists('get_option')) {
    function get_option($key, $default = false) { return $default; }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) { return true; }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $exp) { return true; }
}
if (!function_exists('get_transient')) {
    function get_transient($key) { return false; }
}
if (!function_exists('delete_transient')) {
    function delete_transient($key) { return true; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all') { return true; }
}
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false) { return true; }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') { return 'http://example.com/wp-admin/' . ltrim($path, '/'); }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) { return 'nonce'; }
}
if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $l10n) { return true; }
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { return true; }
}
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
