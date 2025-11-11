<?php declare(strict_types=1);
/**
 * Funciones globales para Mi Integración API - VERSIÓN SEGURA
 *
 * Este archivo contiene funciones de utilidad que no pertenecen
 * a ninguna clase específica pero son necesarias para el plugin.
 * VERSIÓN SEGURA: Sin dependencias circulares
 *
 * @package MiIntegracionApi
 * @since 1.0.0
 */

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Las siguientes funciones ya están definidas en functions.php y se cargan automáticamente por Composer:
// - mi_integracion_api_get_crypto()
// - mi_integracion_api_log()
// - mi_integracion_api_feature_enabled()
// - mi_integracion_api_getSyncStatus()
// - mi_integracion_api_is_sync_in_progress()

/**
 * Obtiene los términos de un post de forma segura
 *
 * @param int $post_id ID del post
 * @param string $taxonomy Taxonomía a obtener
 * @param array $args Argumentos adicionales
 * @return array|false Array de términos o false si hay error
 * @since 1.0.0
 */
function mi_integracion_api_get_post_terms_safe($post_id, $taxonomy = 'post_tag', $args = []) {
    if (!function_exists('get_the_terms')) {
        mi_integracion_api_log('get_the_terms no está disponible', 'wordpress_functions', 'warning');
        return false;
    }
    
    $terms = get_the_terms($post_id, $taxonomy);
    
    if (is_wp_error($terms)) {
        mi_integracion_api_log('Error obteniendo términos del post', [
            'post_id' => $post_id,
            'taxonomy' => $taxonomy,
            'error' => $terms->get_error_message()
        ], 'warning');
        return false;
    }
    
    return $terms ?: [];
}

/**
 * Establece la imagen destacada de un post de forma segura
 *
 * @param int $post_id ID del post
 * @param int $thumbnail_id ID de la imagen
 * @return bool True si se estableció correctamente, false si hay error
 * @since 1.0.0
 */
function mi_integracion_api_set_post_thumbnail_safe($post_id, $thumbnail_id) {
    if (!function_exists('set_post_thumbnail')) {
        mi_integracion_api_log('set_post_thumbnail no está disponible', 'wordpress_functions', 'warning');
        return false;
    }
    
    $result = set_post_thumbnail($post_id, $thumbnail_id);
    
    if (is_wp_error($result)) {
        mi_integracion_api_log('Error estableciendo imagen destacada', [
            'post_id' => $post_id,
            'thumbnail_id' => $thumbnail_id,
            'error' => $result->get_error_message()
        ], 'warning');
        return false;
    }
    
    return $result;
}

/**
 * Sube un archivo a WordPress de forma segura
 *
 * @param string $name Nombre del archivo
 * @param mixed $deprecated Parámetro deprecated
 * @param string $bits Contenido del archivo
 * @param string|null $time Timestamp opcional
 * @return array|false Array con información del upload o false si hay error
 * @since 1.0.0
 */
function mi_integracion_api_upload_bits_safe($name, $deprecated, $bits, $time = null) {
    if (!function_exists('wp_upload_bits')) {
        mi_integracion_api_log('wp_upload_bits no está disponible', 'wordpress_functions', 'warning');
        return false;
    }
    
    $upload = wp_upload_bits($name, $deprecated, $bits, $time);
    
    // wp_upload_bits puede devolver WP_Error o un array con 'error'
    if (is_wp_error($upload)) {
        mi_integracion_api_log('Error subiendo archivo (WP_Error)', [
            'filename' => $name,
            'error' => $upload->get_error_message(),
            'function' => 'wp_upload_bits'
        ], 'warning');
        return false;
    }
    
    if (is_array($upload) && isset($upload['error']) && $upload['error'] !== false) {
        mi_integracion_api_log('Error subiendo archivo (array error)', [
            'filename' => $name,
            'error' => $upload['error'],
            'function' => 'wp_upload_bits'
        ], 'warning');
        return false;
    }
    
    return $upload;
}

/**
 * Sanitiza el nombre de un archivo de forma segura
 *
 * @param string $filename Nombre del archivo
 * @return string Nombre sanitizado
 * @since 1.0.0
 */
function mi_integracion_api_sanitize_file_name_safe($filename) {
    if (!function_exists('sanitize_file_name')) {
        mi_integracion_api_log('sanitize_file_name no está disponible', 'wordpress_functions', 'warning');
        // Fallback básico
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    }
    
    return sanitize_file_name($filename);
}
