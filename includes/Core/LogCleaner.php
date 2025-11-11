<?php
declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Logging\Core\LoggerBasic;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sistema de limpieza automática de archivos de log
 * 
 * Este sistema gestiona la limpieza de archivos de log para evitar
 * que se acumulen y ocupen espacio innecesario en el servidor.
 * 
 * @package MiIntegracionApi\Core
 * @since 2.0.0
 */
class LogCleaner
{
    private LoggerBasic $logger;
    private array $config;
    
    // Configuración por defecto
    private const DEFAULT_CONFIG = [
        'enabled' => true,
        'max_age_days' => 7,           // Eliminar logs más antiguos de 7 días
        'max_size_mb' => 100,          // Limpiar cuando supere 100MB
        'max_files' => 10,             // Mantener máximo 10 archivos
        'cleanup_interval' => 24,      // Verificar cada 24 horas
        'products_interval' => 1000,   // Limpiar cada 1000 productos sincronizados
        'log_directories' => [
            'api_connector',
            'logs',
            'cache'
        ]
    ];
    
    public function __construct(?array $config = null)
    {
        $this->logger = new LoggerBasic('log-cleaner');
        $this->config = array_merge(self::DEFAULT_CONFIG, $config ?? []);
        
        // Registrar hooks de WordPress
        add_action('mia_sync_completed', [$this, 'onSyncCompleted']);
        add_action('mia_batch_completed', [$this, 'onBatchCompleted']);
        add_action('mia_daily_cleanup', [$this, 'dailyCleanup']);
        
        // Programar limpieza diaria
        $this->scheduleDailyCleanup();
    }
    
    /**
     * Ejecuta limpieza basada en productos sincronizados
     */
    public function onSyncCompleted(array $data): void
    {
        if (!$this->config['enabled']) {
            return;
        }
        
        $products_synced = $data['items_synced'] ?? 0;
        
        if ($products_synced >= $this->config['products_interval']) {
            $this->logger->info('Iniciando limpieza por productos sincronizados', [
                'products_synced' => $products_synced,
                'interval' => $this->config['products_interval']
            ]);
            
            $this->cleanupLogs();
        }
    }
    
    /**
     * Ejecuta limpieza después de cada lote
     */
    public function onBatchCompleted(array $data): void
    {
        if (!$this->config['enabled']) {
            return;
        }
        
        // Verificar si es necesario limpiar por tamaño
        $this->checkSizeBasedCleanup();
    }
    
    /**
     * Limpieza diaria programada
     */
    public function dailyCleanup(): void
    {
        if (!$this->config['enabled']) {
            return;
        }
        
        $this->logger->info('Iniciando limpieza diaria de logs');
        $this->cleanupLogs();
    }
    
    /**
     * Programa la limpieza diaria
     */
    private function scheduleDailyCleanup(): void
    {
        if (!wp_next_scheduled('mia_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'mia_daily_cleanup');
        }
    }
    
    /**
     * Verifica si es necesario limpiar por tamaño
     */
    private function checkSizeBasedCleanup(): void
    {
        $total_size = $this->getTotalLogSize();
        $max_size_bytes = $this->config['max_size_mb'] * 1024 * 1024;
        
        if ($total_size > $max_size_bytes) {
            $this->logger->warning('Tamaño de logs excedido, iniciando limpieza', [
                'current_size_mb' => round($total_size / 1024 / 1024, 2),
                'max_size_mb' => $this->config['max_size_mb']
            ]);
            
            $this->cleanupLogs();
        }
    }
    
    /**
     * Ejecuta la limpieza de logs
     */
    public function cleanupLogs(): array
    {
        $results = [
            'files_deleted' => 0,
            'space_freed_mb' => 0,
            'errors' => 0,
            'directories_processed' => 0
        ];
        
        foreach ($this->config['log_directories'] as $directory) {
            $dir_path = $this->getLogDirectoryPath($directory);
            
            if (!is_dir($dir_path)) {
                continue;
            }
            
            $results['directories_processed']++;
            $dir_results = $this->cleanupDirectory($dir_path);
            
            $results['files_deleted'] += $dir_results['files_deleted'];
            $results['space_freed_mb'] += $dir_results['space_freed_mb'];
            $results['errors'] += $dir_results['errors'];
        }
        
        $this->logger->info('Limpieza de logs completada', $results);
        
        return $results;
    }
    
    /**
     * Limpia un directorio específico
     */
    private function cleanupDirectory(string $directory): array
    {
        $results = [
            'files_deleted' => 0,
            'space_freed_mb' => 0,
            'errors' => 0
        ];
        
        $files = glob($directory . '/*.log');
        $files = array_merge($files, glob($directory . '/*.txt'));
        
        if (empty($files)) {
            return $results;
        }
        
        // Ordenar por fecha de modificación (más antiguos primero)
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        $max_age_timestamp = time() - ($this->config['max_age_days'] * 24 * 60 * 60);
        $files_to_keep = $this->config['max_files'];
        $files_to_delete = [];
        
        foreach ($files as $file) {
            $file_age = filemtime($file);
            $file_size = filesize($file);
            
            // Eliminar por antigüedad
            if ($file_age < $max_age_timestamp) {
                $files_to_delete[] = $file;
                continue;
            }
            
            // Mantener solo los archivos más recientes
            if (count($files) - count($files_to_delete) > $files_to_keep) {
                $files_to_delete[] = $file;
            }
        }
        
        // Eliminar archivos seleccionados
        foreach ($files_to_delete as $file) {
            if (file_exists($file)) {
                $file_size = filesize($file);
                
                if (unlink($file)) {
                    $results['files_deleted']++;
                    $results['space_freed_mb'] += $file_size / 1024 / 1024;
                } else {
                    $results['errors']++;
                    $this->logger->warning('No se pudo eliminar archivo de log', [
                        'file' => $file
                    ]);
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Obtiene el tamaño total de todos los logs
     */
    private function getTotalLogSize(): int
    {
        $total_size = 0;
        
        foreach ($this->config['log_directories'] as $directory) {
            $dir_path = $this->getLogDirectoryPath($directory);
            
            if (is_dir($dir_path)) {
                $files = glob($dir_path . '/*.log');
                $files = array_merge($files, glob($dir_path . '/*.txt'));
                
                foreach ($files as $file) {
                    if (file_exists($file)) {
                        $total_size += filesize($file);
                    }
                }
            }
        }
        
        return $total_size;
    }
    
    /**
     * Obtiene la ruta completa del directorio de logs
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
    
    /**
     * Obtiene estadísticas de los logs
     */
    public function getLogStats(): array
    {
        $stats = [
            'total_files' => 0,
            'total_size_mb' => 0,
            'oldest_file' => null,
            'newest_file' => null,
            'directories' => []
        ];
        
        foreach ($this->config['log_directories'] as $directory) {
            $dir_path = $this->getLogDirectoryPath($directory);
            $dir_stats = $this->getDirectoryStats($dir_path);
            
            $stats['total_files'] += $dir_stats['files'];
            $stats['total_size_mb'] += $dir_stats['size_mb'];
            $stats['directories'][$directory] = $dir_stats;
            
            if ($dir_stats['oldest_file'] && (!$stats['oldest_file'] || $dir_stats['oldest_file'] < $stats['oldest_file'])) {
                $stats['oldest_file'] = $dir_stats['oldest_file'];
            }
            
            if ($dir_stats['newest_file'] && (!$stats['newest_file'] || $dir_stats['newest_file'] > $stats['newest_file'])) {
                $stats['newest_file'] = $dir_stats['newest_file'];
            }
        }
        
        return $stats;
    }
    
    /**
     * Obtiene estadísticas de un directorio específico
     */
    private function getDirectoryStats(string $directory): array
    {
        $stats = [
            'files' => 0,
            'size_mb' => 0,
            'oldest_file' => null,
            'newest_file' => null
        ];
        
        if (!is_dir($directory)) {
            return $stats;
        }
        
        $files = glob($directory . '/*.log');
        $files = array_merge($files, glob($directory . '/*.txt'));
        
        $stats['files'] = count($files);
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                $file_size = filesize($file);
                $file_time = filemtime($file);
                
                $stats['size_mb'] += $file_size / 1024 / 1024;
                
                if (!$stats['oldest_file'] || $file_time < $stats['oldest_file']) {
                    $stats['oldest_file'] = $file_time;
                }
                
                if (!$stats['newest_file'] || $file_time > $stats['newest_file']) {
                    $stats['newest_file'] = $file_time;
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * Fuerza la limpieza inmediata
     */
    public function forceCleanup(): array
    {
        $this->logger->info('Iniciando limpieza forzada de logs');
        return $this->cleanupLogs();
    }
    
    /**
     * Actualiza la configuración
     */
    public function updateConfig(array $new_config): void
    {
        $this->config = array_merge($this->config, $new_config);
        
        $this->logger->info('Configuración de limpieza actualizada', [
            'new_config' => $new_config
        ]);
    }
    
    /**
     * Obtiene la configuración actual
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
