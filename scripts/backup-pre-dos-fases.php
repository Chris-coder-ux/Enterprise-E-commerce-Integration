<?php
/**
 * Script de backup antes de implementar arquitectura en dos fases.
 *
 * Este script crea backups completos de:
 * - Base de datos (tablas wp_postmeta relacionadas con attachments)
 * - Directorio wp-content/uploads/ (imágenes existentes)
 * - Opciones de WordPress relacionadas con sincronización
 *
 * Uso: wp eval-file scripts/backup-pre-dos-fases.php [--output-dir=/path/to/backup]
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
        die('Error: No se pudo cargar WordPress. Ejecuta este script con: wp eval-file scripts/backup-pre-dos-fases.php');
    }
}

/**
 * Crea backup completo antes de implementar arquitectura en dos fases.
 */
class PreDosFasesBackup
{
    private string $backup_dir;
    private array $errors = [];
    private array $warnings = [];
    private array $success = [];

    /**
     * Constructor.
     *
     * @param string|null $output_dir Directorio de salida (opcional).
     */
    public function __construct(?string $output_dir = null)
    {
        if ($output_dir === null) {
            // Usar directorio de logs del plugin por defecto
            $plugin_dir = dirname(dirname(__DIR__));
            $this->backup_dir = $plugin_dir . '/backups/pre-dos-fases-' . date('Ymd_His');
        } else {
            $this->backup_dir = rtrim($output_dir, '/');
        }

        // Crear directorio de backup si no existe
        if (!is_dir($this->backup_dir)) {
            if (!wp_mkdir_p($this->backup_dir)) {
                throw new Exception("No se puede crear directorio de backup: {$this->backup_dir}");
            }
        }
    }

    /**
     * Ejecuta el backup completo.
     *
     * @return array Resultado del backup.
     */
    public function execute(): array
    {
        $this->success[] = "Iniciando backup en: {$this->backup_dir}";

        // 1. Backup de base de datos (metadatos de attachments)
        $this->backupAttachmentMetadata();

        // 2. Backup de opciones de WordPress
        $this->backupWordPressOptions();

        // 3. Backup de directorio de uploads
        $this->backupUploadsDirectory();

        // 4. Crear archivo de información
        $this->createInfoFile();

        return [
            'success' => empty($this->errors),
            'backup_dir' => $this->backup_dir,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'success_messages' => $this->success
        ];
    }

    /**
     * Crea backup de metadatos de attachments.
     */
    private function backupAttachmentMetadata(): void
    {
        global $wpdb;

        try {
            $this->success[] = "Creando backup de metadatos de attachments...";

            // Obtener todos los metadatos relacionados con Verial
            $meta_keys = ['_verial_article_id', '_verial_image_hash', '_verial_image_order'];
            
            $query = $wpdb->prepare(
                "SELECT pm.*, p.post_type, p.post_mime_type
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE pm.meta_key IN (" . implode(',', array_fill(0, count($meta_keys), '%s')) . ")
                AND p.post_type = 'attachment'
                AND p.post_mime_type LIKE 'image/%'",
                ...$meta_keys
            );

            $results = $wpdb->get_results($query, ARRAY_A);

            $backup_file = $this->backup_dir . '/attachment_metadata.json';
            $backup_data = [
                'timestamp' => time(),
                'date' => date('Y-m-d H:i:s'),
                'total_records' => count($results),
                'meta_keys' => $meta_keys,
                'data' => $results
            ];

            if (file_put_contents($backup_file, json_encode($backup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
                $this->errors[] = "Error escribiendo archivo de backup de metadatos: $backup_file";
            } else {
                $this->success[] = "Backup de metadatos creado: {$backup_file} ({$backup_data['total_records']} registros)";
            }

            // También crear SQL de backup
            $sql_file = $this->backup_dir . '/attachment_metadata.sql';
            $sql_content = "-- Backup de metadatos de attachments antes de arquitectura dos fases\n";
            $sql_content .= "-- Fecha: " . date('Y-m-d H:i:s') . "\n\n";

            foreach ($results as $row) {
                $meta_id = (int)$row['meta_id'];
                $post_id = (int)$row['post_id'];
                $meta_key = $wpdb->_escape($row['meta_key']);
                $meta_value = $wpdb->_escape($row['meta_value']);

                $sql_content .= "INSERT INTO {$wpdb->postmeta} (meta_id, post_id, meta_key, meta_value) VALUES ";
                $sql_content .= "({$meta_id}, {$post_id}, '{$meta_key}', '{$meta_value}') ";
                $sql_content .= "ON DUPLICATE KEY UPDATE meta_value = '{$meta_value}';\n";
            }

            if (file_put_contents($sql_file, $sql_content) === false) {
                $this->warnings[] = "No se pudo crear archivo SQL de backup: $sql_file";
            } else {
                $this->success[] = "Backup SQL creado: {$sql_file}";
            }

        } catch (\Exception $e) {
            $this->errors[] = "Error creando backup de metadatos: " . $e->getMessage();
        }
    }

    /**
     * Crea backup de opciones de WordPress relacionadas.
     */
    private function backupWordPressOptions(): void
    {
        try {
            $this->success[] = "Creando backup de opciones de WordPress...";

            $options_to_backup = [
                'mia_images_sync_checkpoint',
                'mia_automatic_stock_detection_enabled',
                'mia_detection_auto_active',
                'mia_use_core_sync_routing'
            ];

            $backup_data = [
                'timestamp' => time(),
                'date' => date('Y-m-d H:i:s'),
                'options' => []
            ];

            foreach ($options_to_backup as $option_name) {
                $value = get_option($option_name);
                if ($value !== false) {
                    $backup_data['options'][$option_name] = $value;
                }
            }

            $backup_file = $this->backup_dir . '/wordpress_options.json';
            if (file_put_contents($backup_file, json_encode($backup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
                $this->errors[] = "Error escribiendo archivo de backup de opciones: $backup_file";
            } else {
                $this->success[] = "Backup de opciones creado: {$backup_file} (" . count($backup_data['options']) . " opciones)";
            }

        } catch (\Exception $e) {
            $this->errors[] = "Error creando backup de opciones: " . $e->getMessage();
        }
    }

    /**
     * Crea backup del directorio de uploads.
     */
    private function backupUploadsDirectory(): void
    {
        try {
            $upload_dir = wp_upload_dir();

            if ($upload_dir['error']) {
                $this->errors[] = "Error obteniendo directorio de uploads: " . $upload_dir['error'];
                return;
            }

            $uploads_path = $upload_dir['basedir'];
            $backup_uploads_dir = $this->backup_dir . '/uploads';

            $this->success[] = "Creando backup de directorio de uploads...";

            // Crear directorio de backup
            if (!is_dir($backup_uploads_dir)) {
                if (!wp_mkdir_p($backup_uploads_dir)) {
                    $this->errors[] = "No se puede crear directorio de backup de uploads: $backup_uploads_dir";
                    return;
                }
            }

            // Copiar archivos usando rsync si está disponible, si no, PHP
            if (function_exists('exec') && !empty(exec('which rsync'))) {
                // Usar rsync para copia eficiente
                $rsync_cmd = sprintf(
                    'rsync -av --progress %s/ %s/ 2>&1',
                    escapeshellarg($uploads_path),
                    escapeshellarg($backup_uploads_dir)
                );
                exec($rsync_cmd, $output, $return_code);

                if ($return_code === 0) {
                    $this->success[] = "Backup de uploads creado usando rsync: {$backup_uploads_dir}";
                } else {
                    $this->warnings[] = "Rsync falló, intentando copia manual...";
                    $this->copyDirectoryRecursive($uploads_path, $backup_uploads_dir);
                }
            } else {
                // Copia manual usando PHP
                $this->copyDirectoryRecursive($uploads_path, $backup_uploads_dir);
            }

        } catch (\Exception $e) {
            $this->errors[] = "Error creando backup de uploads: " . $e->getMessage();
        }
    }

    /**
     * Copia directorio recursivamente.
     *
     * @param string $source Directorio origen.
     * @param string $dest   Directorio destino.
     * @return void
     */
    private function copyDirectoryRecursive(string $source, string $dest): void
    {
        if (!is_dir($source)) {
            return;
        }

        if (!is_dir($dest)) {
            wp_mkdir_p($dest);
        }

        $dir = opendir($source);
        if ($dir === false) {
            $this->warnings[] = "No se puede abrir directorio: $source";
            return;
        }

        $copied = 0;
        $skipped = 0;

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $source_file = $source . '/' . $file;
            $dest_file = $dest . '/' . $file;

            if (is_dir($source_file)) {
                // Recursión para subdirectorios
                $this->copyDirectoryRecursive($source_file, $dest_file);
            } else {
                // Copiar archivo
                if (copy($source_file, $dest_file)) {
                    $copied++;
                } else {
                    $skipped++;
                }
            }
        }

        closedir($dir);

        if ($copied > 0 || $skipped > 0) {
            $this->success[] = "Backup de uploads creado: {$dest} ({$copied} archivos copiados, {$skipped} omitidos)";
        }
    }

    /**
     * Crea archivo de información del backup.
     */
    private function createInfoFile(): void
    {
        $info = [
            'backup_date' => date('Y-m-d H:i:s'),
            'backup_timestamp' => time(),
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugin_version' => '1.5.0',
            'backup_type' => 'pre-dos-fases-implementation',
            'backup_location' => $this->backup_dir,
            'description' => 'Backup completo antes de implementar arquitectura de sincronización en dos fases',
            'files' => [
                'attachment_metadata.json' => 'Metadatos de attachments relacionados con Verial',
                'attachment_metadata.sql' => 'SQL de backup de metadatos',
                'wordpress_options.json' => 'Opciones de WordPress relacionadas',
                'uploads/' => 'Directorio completo de wp-content/uploads/'
            ],
            'restore_instructions' => [
                '1. Detener todas las sincronizaciones en curso',
                '2. Restaurar metadatos desde attachment_metadata.sql si es necesario',
                '3. Restaurar opciones desde wordpress_options.json si es necesario',
                '4. Restaurar uploads desde directorio uploads/ si es necesario',
                '5. Verificar que todo funciona correctamente'
            ]
        ];

        $info_file = $this->backup_dir . '/backup-info.json';
        if (file_put_contents($info_file, json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            $this->errors[] = "Error escribiendo archivo de información: $info_file";
        } else {
            $this->success[] = "Archivo de información creado: {$info_file}";
        }
    }

    /**
     * Imprime resultados formateados.
     *
     * @param array $result Resultado del backup.
     * @return void
     */
    public function printResults(array $result): void
    {
        echo "\n";
        echo "=== BACKUP ANTES DE IMPLEMENTACIÓN DE ARQUITECTURA DOS FASES ===\n";
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
            echo "✅ BACKUP COMPLETADO: {$result['backup_dir']}\n";
            echo "\n";
            echo "📋 Archivos de backup:\n";
            echo "   - attachment_metadata.json (metadatos en JSON)\n";
            echo "   - attachment_metadata.sql (metadatos en SQL)\n";
            echo "   - wordpress_options.json (opciones de WordPress)\n";
            echo "   - uploads/ (directorio completo de uploads)\n";
            echo "   - backup-info.json (información del backup)\n";
            echo "\n";
            echo "💾 Guarda este directorio en un lugar seguro antes de continuar.\n";
        } else {
            echo "❌ BACKUP FALLIDO: Corrige los errores antes de continuar\n";
        }

        echo "\n";
    }
}

// Ejecutar backup
if (php_sapi_name() === 'cli' || defined('WP_CLI')) {
    // Obtener directorio de salida desde argumentos si existe
    $output_dir = null;
    if (isset($GLOBALS['argv'])) {
        foreach ($GLOBALS['argv'] as $arg) {
            if (strpos($arg, '--output-dir=') === 0) {
                $output_dir = substr($arg, strlen('--output-dir='));
                break;
            }
        }
    }

    try {
        $backup = new PreDosFasesBackup($output_dir);
        $result = $backup->execute();
        $backup->printResults($result);

        // Exit code para scripts
        exit($result['success'] ? 0 : 1);
    } catch (\Exception $e) {
        echo "❌ ERROR CRÍTICO: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    // Si se ejecuta vía web, mostrar JSON
    header('Content-Type: application/json');
    try {
        $output_dir = isset($_GET['output_dir']) ? sanitize_text_field($_GET['output_dir']) : null;
        $backup = new PreDosFasesBackup($output_dir);
        $result = $backup->execute();
        echo json_encode($result, JSON_PRETTY_PRINT);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
    }
}



