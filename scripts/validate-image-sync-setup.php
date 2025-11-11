<?php
/**
 * Script de validación para arquitectura de sincronización de imágenes en dos fases.
 *
 * Este script verifica que el entorno esté correctamente configurado para
 * la sincronización de imágenes:
 * - Metadatos de attachments pueden guardarse
 * - Permisos de escritura en directorios
 * - Estructura de base de datos
 *
 * Uso: wp eval-file scripts/validate-image-sync-setup.php
 *
 * @package     MiIntegracionApi
 * @version     1.5.0
 * @since       1.5.0
 */

declare(strict_types=1);

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    // Si se ejecuta directamente, intentar cargar WordPress
    $wp_load_paths = [
        dirname(__FILE__) . '/../../wp-load.php',
        dirname(__FILE__) . '/../../../wp-load.php',
        dirname(dirname(dirname(__DIR__))) . '/wp-load.php'
    ];
    $wp_loaded = false;
    foreach ($wp_load_paths as $wp_path) {
        if (file_exists($wp_path)) {
            require_once($wp_path);
            $wp_loaded = true;
            break;
        }
    }
    if (!$wp_loaded) {
        die('Error: No se pudo cargar WordPress. Ejecuta este script con: wp eval-file scripts/validate-image-sync-setup.php');
    }
}

/**
 * Valida la configuración para sincronización de imágenes.
 */
class ImageSyncSetupValidator
{
    private array $errors = [];
    private array $warnings = [];
    private array $success = [];

    /**
     * Ejecuta todas las validaciones.
     *
     * @return array Resultado de validaciones.
     */
    public function validate(): array
    {
        $this->validateDatabaseStructure();
        $this->validateMetadataStorage();
        $this->validateUploadPermissions();
        $this->validateTempDirectory();
        $this->validateImageSyncManager();

        return [
            'success' => empty($this->errors),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'success_messages' => $this->success
        ];
    }

    /**
     * Valida estructura de base de datos.
     */
    private function validateDatabaseStructure(): void
    {
        global $wpdb;

        // Verificar que wp_postmeta existe
        $table_name = $wpdb->postmeta;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");

        if (!$table_exists) {
            $this->errors[] = "Tabla wp_postmeta no existe en la base de datos";
            return;
        }

        $this->success[] = "Tabla wp_postmeta existe";

        // Verificar estructura de wp_postmeta
        $columns = $wpdb->get_results("DESCRIBE $table_name");
        $required_columns = ['meta_id', 'post_id', 'meta_key', 'meta_value'];

        foreach ($required_columns as $col) {
            $found = false;
            foreach ($columns as $column) {
                if ($column->Field === $col) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->errors[] = "Columna requerida '$col' no encontrada en wp_postmeta";
            }
        }

        if (empty($this->errors)) {
            $this->success[] = "Estructura de wp_postmeta es correcta";
        }
    }

    /**
     * Valida que los metadatos se pueden guardar.
     */
    private function validateMetadataStorage(): void
    {
        // Crear un attachment de prueba temporal
        $test_attachment_id = wp_insert_post([
            'post_title' => 'Test Image Sync Validation',
            'post_content' => '',
            'post_status' => 'private',
            'post_type' => 'attachment'
        ]);

        if (is_wp_error($test_attachment_id)) {
            $this->errors[] = "No se puede crear attachment de prueba: " . $test_attachment_id->get_error_message();
            return;
        }

        $test_article_id = 99999;
        $test_hash = 'test_hash_' . time();
        $test_order = 1;

        // Intentar guardar metadatos
        $meta_keys = [
            '_verial_article_id' => $test_article_id,
            '_verial_image_hash' => $test_hash,
            '_verial_image_order' => $test_order
        ];

        $all_success = true;
        foreach ($meta_keys as $key => $value) {
            $result = update_post_meta($test_attachment_id, $key, $value);
            if ($result === false) {
                $this->errors[] = "No se puede guardar metadato '$key'";
                $all_success = false;
            }
        }

        if ($all_success) {
            $this->success[] = "Metadatos se pueden guardar correctamente";

            // Verificar que se pueden leer
            $read_article_id = get_post_meta($test_attachment_id, '_verial_article_id', true);
            $read_hash = get_post_meta($test_attachment_id, '_verial_image_hash', true);
            $read_order = get_post_meta($test_attachment_id, '_verial_image_order', true);

            if ($read_article_id != $test_article_id || $read_hash != $test_hash || $read_order != $test_order) {
                $this->errors[] = "Metadatos no se leen correctamente después de guardar";
            } else {
                $this->success[] = "Metadatos se pueden leer correctamente";
            }
        }

        // Limpiar attachment de prueba
        wp_delete_post($test_attachment_id, true);
    }

    /**
     * Valida permisos de escritura en directorio de uploads.
     */
    private function validateUploadPermissions(): void
    {
        $upload_dir = wp_upload_dir();

        if ($upload_dir['error']) {
            $this->errors[] = "Error obteniendo directorio de uploads: " . $upload_dir['error'];
            return;
        }

        $upload_path = $upload_dir['basedir'];

        if (!is_dir($upload_path)) {
            $this->errors[] = "Directorio de uploads no existe: $upload_path";
            return;
        }

        if (!is_writable($upload_path)) {
            $this->errors[] = "Directorio de uploads no tiene permisos de escritura: $upload_path";
            return;
        }

        $this->success[] = "Directorio de uploads tiene permisos de escritura: $upload_path";

        // Verificar subdirectorio de año/mes actual
        $year_month_dir = $upload_dir['path'];
        if (!is_dir($year_month_dir)) {
            if (!wp_mkdir_p($year_month_dir)) {
                $this->warnings[] = "No se puede crear subdirectorio de año/mes: $year_month_dir";
            } else {
                $this->success[] = "Subdirectorio de año/mes creado: $year_month_dir";
            }
        } else {
            if (!is_writable($year_month_dir)) {
                $this->errors[] = "Subdirectorio de año/mes no tiene permisos de escritura: $year_month_dir";
            } else {
                $this->success[] = "Subdirectorio de año/mes tiene permisos de escritura: $year_month_dir";
            }
        }
    }

    /**
     * Valida directorio temporal.
     */
    private function validateTempDirectory(): void
    {
        $temp_dir = sys_get_temp_dir();

        if (empty($temp_dir)) {
            $this->errors[] = "No se puede determinar directorio temporal";
            return;
        }

        if (!is_dir($temp_dir)) {
            $this->errors[] = "Directorio temporal no existe: $temp_dir";
            return;
        }

        if (!is_writable($temp_dir)) {
            $this->errors[] = "Directorio temporal no tiene permisos de escritura: $temp_dir";
            return;
        }

        $this->success[] = "Directorio temporal tiene permisos de escritura: $temp_dir";

        // Intentar crear archivo temporal de prueba
        $test_file = tempnam($temp_dir, 'mia_test_');
        if ($test_file === false) {
            $this->errors[] = "No se puede crear archivo temporal de prueba en: $temp_dir";
        } else {
            if (unlink($test_file)) {
                $this->success[] = "Archivo temporal de prueba creado y eliminado correctamente";
            } else {
                $this->warnings[] = "Archivo temporal de prueba creado pero no eliminado: $test_file";
            }
        }
    }

    /**
     * Valida que ImageSyncManager esté disponible.
     */
    private function validateImageSyncManager(): void
    {
        if (!class_exists('\\MiIntegracionApi\\Sync\\ImageSyncManager')) {
            $this->errors[] = "Clase ImageSyncManager no está disponible. Ejecuta: composer dump-autoload";
            return;
        }

        $this->success[] = "Clase ImageSyncManager está disponible";

        // Verificar dependencias
        if (!class_exists('\\MiIntegracionApi\\Core\\ApiConnector')) {
            $this->errors[] = "Clase ApiConnector no está disponible";
        } else {
            $this->success[] = "Clase ApiConnector está disponible";
        }

        if (!class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
            $this->errors[] = "Clase Logger no está disponible";
        } else {
            $this->success[] = "Clase Logger está disponible";
        }

        // Verificar funciones helper
        if (!function_exists('mi_integracion_api_upload_bits_safe')) {
            $this->warnings[] = "Función mi_integracion_api_upload_bits_safe no está disponible";
        } else {
            $this->success[] = "Función mi_integracion_api_upload_bits_safe está disponible";
        }

        if (!function_exists('mi_integracion_api_sanitize_file_name_safe')) {
            $this->warnings[] = "Función mi_integracion_api_sanitize_file_name_safe no está disponible";
        } else {
            $this->success[] = "Función mi_integracion_api_sanitize_file_name_safe está disponible";
        }
    }

    /**
     * Imprime resultados formateados.
     *
     * @param array $result Resultado de validaciones.
     * @return void
     */
    public function printResults(array $result): void
    {
        echo "\n";
        echo "=== VALIDACIÓN DE CONFIGURACIÓN PARA SINCRONIZACIÓN DE IMÁGENES ===\n";
        echo "\n";

        if (!empty($result['success_messages'])) {
            echo "✅ ÉXITOS:\n";
            foreach ($result['success_messages'] as $msg) {
                echo "   ✓ $msg\n";
            }
            echo "\n";
        }

        if (!empty($result['warnings'])) {
            echo "⚠️  ADVERTENCIAS:\n";
            foreach ($result['warnings'] as $msg) {
                echo "   ⚠ $msg\n";
            }
            echo "\n";
        }

        if (!empty($result['errors'])) {
            echo "❌ ERRORES:\n";
            foreach ($result['errors'] as $msg) {
                echo "   ✗ $msg\n";
            }
            echo "\n";
        }

        if ($result['success']) {
            echo "✅ VALIDACIÓN COMPLETA: El entorno está listo para sincronización de imágenes\n";
        } else {
            echo "❌ VALIDACIÓN FALLIDA: Corrige los errores antes de continuar\n";
        }

        echo "\n";
    }
}

// Ejecutar validación
if (php_sapi_name() === 'cli' || defined('WP_CLI')) {
    $validator = new ImageSyncSetupValidator();
    $result = $validator->validate();
    $validator->printResults($result);

    // Exit code para scripts
    exit($result['success'] ? 0 : 1);
} else {
    // Si se ejecuta vía web, mostrar JSON
    header('Content-Type: application/json');
    $validator = new ImageSyncSetupValidator();
    $result = $validator->validate();
    echo json_encode($result, JSON_PRETTY_PRINT);
}



