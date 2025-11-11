<?php
declare(strict_types=1);

namespace MiIntegracionApi\Tools;

use MiIntegracionApi\Core\LogCleaner;
use WP_CLI;
use WP_CLI_Command;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Comando WP-CLI para gestión de limpieza de logs
 * 
 * @package MiIntegracionApi\Tools
 * @since 2.0.0
 */
class LogCleanupCommand extends WP_CLI_Command
{
    private LogCleaner $log_cleaner;
    
    public function __construct()
    {
        $this->log_cleaner = new LogCleaner();
    }
    
    /**
     * Muestra estadísticas de los archivos de log
     * 
     * ## EXAMPLES
     *   wp verial log stats
     *   wp verial log stats --format=table
     */
    public function stats(array $args, array $assoc_args): void
    {
        $stats = $this->log_cleaner->getLogStats();
        
        if (empty($stats['total_files'])) {
            WP_CLI::success('No se encontraron archivos de log.');
            return;
        }
        
        $format = $assoc_args['format'] ?? 'table';
        
        if ($format === 'table') {
            $this->displayStatsTable($stats);
        } else {
            WP_CLI::log(json_encode($stats, JSON_PRETTY_PRINT));
        }
    }
    
    /**
     * Ejecuta limpieza de logs
     * 
     * ## OPTIONS
     * [--force] Forzar limpieza inmediata
     * [--dry-run] Mostrar qué se eliminaría sin hacerlo
     * 
     * ## EXAMPLES
     *   wp verial log cleanup
     *   wp verial log cleanup --force
     *   wp verial log cleanup --dry-run
     */
    public function cleanup(array $args, array $assoc_args): void
    {
        $force = isset($assoc_args['force']);
        $dry_run = isset($assoc_args['dry-run']);
        
        if ($dry_run) {
            $this->showDryRun();
            return;
        }
        
        if ($force) {
            WP_CLI::log('Iniciando limpieza forzada de logs...');
            $results = $this->log_cleaner->forceCleanup();
        } else {
            WP_CLI::log('Iniciando limpieza de logs...');
            $results = $this->log_cleaner->cleanupLogs();
        }
        
        if ($results['files_deleted'] > 0) {
            WP_CLI::success(sprintf(
                'Limpieza completada: %d archivos eliminados, %.2f MB liberados',
                $results['files_deleted'],
                $results['space_freed_mb']
            ));
        } else {
            WP_CLI::success('No se encontraron archivos para eliminar.');
        }
        
        if ($results['errors'] > 0) {
            WP_CLI::warning(sprintf('%d errores durante la limpieza', $results['errors']));
        }
    }
    
    /**
     * Configura los parámetros de limpieza
     * 
     * ## OPTIONS
     * [--max-age-days=<days>] Días máximos de antigüedad (default: 7)
     * [--max-size-mb=<mb>] Tamaño máximo en MB (default: 100)
     * [--max-files=<files>] Número máximo de archivos (default: 10)
     * [--enabled=<true|false>] Habilitar/deshabilitar limpieza automática
     * 
     * ## EXAMPLES
     *   wp verial log config --max-age-days=14
     *   wp verial log config --max-size-mb=200 --max-files=20
     *   wp verial log config --enabled=false
     */
    public function config(array $args, array $assoc_args): void
    {
        $new_config = [];
        
        if (isset($assoc_args['max-age-days'])) {
            $new_config['max_age_days'] = (int)$assoc_args['max-age-days'];
        }
        
        if (isset($assoc_args['max-size-mb'])) {
            $new_config['max_size_mb'] = (int)$assoc_args['max-size-mb'];
        }
        
        if (isset($assoc_args['max-files'])) {
            $new_config['max_files'] = (int)$assoc_args['max-files'];
        }
        
        if (isset($assoc_args['enabled'])) {
            $new_config['enabled'] = $assoc_args['enabled'] === 'true';
        }
        
        if (empty($new_config)) {
            WP_CLI::error('Debe especificar al menos un parámetro de configuración.');
            return;
        }
        
        $this->log_cleaner->updateConfig($new_config);
        
        WP_CLI::success('Configuración actualizada exitosamente');
        
        // Mostrar configuración actual
        $config = $this->log_cleaner->getConfig();
        $this->displayConfigTable($config);
    }
    
    /**
     * Muestra la configuración actual
     * 
     * ## EXAMPLES
     *   wp verial log show-config
     */
    public function show_config(array $args, array $assoc_args): void
    {
        $config = $this->log_cleaner->getConfig();
        $this->displayConfigTable($config);
    }
    
    /**
     * Muestra qué archivos se eliminarían en modo dry-run
     */
    private function showDryRun(): void
    {
        WP_CLI::log('Modo dry-run: Mostrando archivos que se eliminarían...');
        
        $stats = $this->log_cleaner->getLogStats();
        $config = $this->log_cleaner->getConfig();
        
        $max_age_timestamp = time() - ($config['max_age_days'] * 24 * 60 * 60);
        $max_files = $config['max_files'];
        
        $files_to_delete = [];
        
        foreach ($stats['directories'] as $dir_name => $dir_stats) {
            $dir_path = $this->getLogDirectoryPath($dir_name);
            
            if (!is_dir($dir_path)) {
                continue;
            }
            
            $files = glob($dir_path . '/*.log');
            $files = array_merge($files, glob($dir_path . '/*.txt'));
            
            if (empty($files)) {
                continue;
            }
            
            // Ordenar por fecha de modificación
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            $dir_files_to_delete = [];
            
            foreach ($files as $file) {
                $file_age = filemtime($file);
                $file_size = filesize($file);
                $file_date = date('Y-m-d H:i:s', $file_age);
                
                // Verificar por antigüedad
                if ($file_age < $max_age_timestamp) {
                    $dir_files_to_delete[] = [
                        'file' => basename($file),
                        'reason' => 'Antigüedad (' . $file_date . ')',
                        'size_mb' => round($file_size / 1024 / 1024, 2)
                    ];
                    continue;
                }
                
                // Verificar por número de archivos
                if (count($files) - count($dir_files_to_delete) > $max_files) {
                    $dir_files_to_delete[] = [
                        'file' => basename($file),
                        'reason' => 'Exceso de archivos',
                        'size_mb' => round($file_size / 1024 / 1024, 2)
                    ];
                }
            }
            
            if (!empty($dir_files_to_delete)) {
                $files_to_delete[$dir_name] = $dir_files_to_delete;
            }
        }
        
        if (empty($files_to_delete)) {
            WP_CLI::success('No se eliminaría ningún archivo.');
            return;
        }
        
        foreach ($files_to_delete as $dir_name => $files) {
            WP_CLI::log("\nDirectorio: $dir_name");
            WP_CLI::log(str_repeat('-', 50));
            
            foreach ($files as $file_info) {
                WP_CLI::log(sprintf(
                    '  %s (%s) - %s',
                    $file_info['file'],
                    $file_info['size_mb'] . ' MB',
                    $file_info['reason']
                ));
            }
        }
        
        $total_files = array_sum(array_map('count', $files_to_delete));
        $total_size = array_sum(array_map(function($files) {
            return array_sum(array_column($files, 'size_mb'));
        }, $files_to_delete));
        
        WP_CLI::log(sprintf(
            "\nTotal: %d archivos, %.2f MB",
            $total_files,
            $total_size
        ));
    }
    
    /**
     * Muestra estadísticas en formato tabla
     */
    private function displayStatsTable(array $stats): void
    {
        $table_data = [];
        
        foreach ($stats['directories'] as $dir_name => $dir_stats) {
            $table_data[] = [
                'Directorio' => $dir_name,
                'Archivos' => $dir_stats['files'],
                'Tamaño (MB)' => round($dir_stats['size_mb'], 2),
                'Más antiguo' => $dir_stats['oldest_file'] ? date('Y-m-d H:i:s', $dir_stats['oldest_file']) : 'N/A',
                'Más reciente' => $dir_stats['newest_file'] ? date('Y-m-d H:i:s', $dir_stats['newest_file']) : 'N/A'
            ];
        }
        
        $table_data[] = [
            'Directorio' => 'TOTAL',
            'Archivos' => $stats['total_files'],
            'Tamaño (MB)' => round($stats['total_size_mb'], 2),
            'Más antiguo' => $stats['oldest_file'] ? date('Y-m-d H:i:s', $stats['oldest_file']) : 'N/A',
            'Más reciente' => $stats['newest_file'] ? date('Y-m-d H:i:s', $stats['newest_file']) : 'N/A'
        ];
        
        WP_CLI\Utils\format_items('table', $table_data, [
            'Directorio', 'Archivos', 'Tamaño (MB)', 'Más antiguo', 'Más reciente'
        ]);
    }
    
    /**
     * Muestra configuración en formato tabla
     */
    private function displayConfigTable(array $config): void
    {
        $table_data = [
            ['Parámetro', 'Valor'],
            ['Habilitado', $config['enabled'] ? 'Sí' : 'No'],
            ['Máxima antigüedad (días)', $config['max_age_days']],
            ['Tamaño máximo (MB)', $config['max_size_mb']],
            ['Máximo archivos', $config['max_files']],
            ['Intervalo de limpieza (horas)', $config['cleanup_interval']],
            ['Intervalo por productos', $config['products_interval']]
        ];
        
        WP_CLI\Utils\format_items('table', $table_data, ['Parámetro', 'Valor']);
    }
    
    /**
     * Obtiene la ruta del directorio de logs
     */
    private function getLogDirectoryPath(string $directory): string
    {
        $base_path = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
        
        switch ($directory) {
            case 'api_connector':
                return plugin_dir_path(MiIntegracionApi_PLUGIN_FILE) . 'api_connector';
            case 'logs':
                return $base_path . '/logs';
            case 'cache':
                return $base_path . '/uploads/mi-integracion-api-cache';
            default:
                return plugin_dir_path(MiIntegracionApi_PLUGIN_FILE) . $directory;
        }
    }
}
