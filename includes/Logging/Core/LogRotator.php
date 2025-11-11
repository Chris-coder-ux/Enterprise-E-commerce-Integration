<?php declare(strict_types=1);

/**
 * Sistema de rotación de logs.
 *
 * Esta clase se encarga de la rotación automática de archivos de log
 * cuando alcanzan un tamaño máximo, incluyendo limpieza de logs antiguos.
 *
 * @package MiIntegracionApi\Logging\Core
 * @since 1.0.0
 * @version 1.1.0
 */

namespace MiIntegracionApi\Logging\Core;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

use MiIntegracionApi\Logging\Configuration\EnvironmentDetector;

/**
 * Clase para rotación y limpieza de archivos de log.
 *
 * Maneja la rotación automática de logs cuando alcanzan un tamaño máximo,
 * la limpieza de logs antiguos según la configuración del entorno,
 * y la gestión del espacio en disco.
 *
 * @package MiIntegracionApi\Logging\Core
 * @since 1.0.0
 */
class LogRotator
{
    /**
     * Tamaño máximo del archivo de log en bytes (5MB por defecto).
     *
     * @var int
     */
    private int $maxLogSize;

    /**
     * Directorio base de logs.
     *
     * @var string
     */
    private string $logDirectory;

    /**
     * Constructor del LogRotator.
     *
     * @param int    $maxLogSize   Tamaño máximo del archivo de log en bytes.
     * @param string $logDirectory Directorio base de logs.
     */
    public function __construct(int $maxLogSize = 5242880, string $logDirectory = '')
    {
        $this->maxLogSize = $maxLogSize;
        $this->logDirectory = $logDirectory ?: $this->getDefaultLogDirectory();
    }

    /**
     * Verifica si un archivo de log necesita rotación.
     *
     * @param string $logFile Ruta del archivo de log.
     * @return bool True si necesita rotación.
     */
    public function needsRotation(string $logFile): bool
    {
        if (!file_exists($logFile)) {
            return false;
        }

        return filesize($logFile) > $this->maxLogSize;
    }

    /**
     * Ejecuta la rotación de un archivo de log.
     *
     * @param string $logFile Ruta del archivo de log a rotar.
     * @return bool True si la rotación fue exitosa.
     */
    public function rotateLog(string $logFile): bool
    {
        if (!$this->needsRotation($logFile)) {
            return true;
        }

        try {
            $this->createBackup($logFile);
            $this->cleanOldLogs();
            $this->createNewLogFile($logFile);
            
            return true;
        } catch (\Throwable $e) {
            error_log("Error en rotación de log: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Crea un backup del archivo de log actual.
     *
     * @param string $logFile Ruta del archivo de log.
     * @return void
     */
    private function createBackup(string $logFile): void
    {
        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = $logFile . '.bak.' . $timestamp;
        
        if (file_exists($logFile)) {
            copy($logFile, $backupFile);
        }
    }

    /**
     * Crea un nuevo archivo de log vacío.
     *
     * @param string $logFile Ruta del archivo de log.
     * @return void
     */
    private function createNewLogFile(string $logFile): void
    {
        $logDir = dirname($logFile);
        
        // Crear directorio si no existe
        if (!is_dir($logDir)) {
            wp_mkdir_p($logDir);
        }
        
        // Crear archivo vacío
        touch($logFile);
    }

    /**
     * Limpia logs antiguos según la configuración del entorno.
     *
     * @return int Número de archivos eliminados.
     */
    public function cleanOldLogs(): int
    {
        $environment = EnvironmentDetector::detectEnvironment();
        $retentionDays = EnvironmentDetector::getRetentionDays($environment);
        $cutoffTime = time() - ($retentionDays * 86400);
        
        $deletedCount = 0;
        $totalSizeFreed = 0;
        
        // Buscar archivos de backup antiguos
        $oldFiles = glob($this->logDirectory . '/*.bak*');
        
        foreach ($oldFiles as $file) {
            if (filemtime($file) < $cutoffTime) {
                $fileSize = filesize($file);
                if (unlink($file)) {
                    $deletedCount++;
                    $totalSizeFreed += $fileSize;
                }
            }
        }
        
        // Log de limpieza solo si no es producción
        if (!$environment === EnvironmentDetector::ENVIRONMENT_PRODUCTION && 
            ($deletedCount > 0 || $totalSizeFreed > 0)) {
            error_log("Limpieza de logs: {$deletedCount} archivos eliminados, " . 
                     round($totalSizeFreed / 1024 / 1024, 2) . "MB liberados");
        }
        
        return $deletedCount;
    }

    /**
     * Ejecuta limpieza programada de logs.
     *
     * Verifica si es el momento apropiado según el entorno y ejecuta la limpieza.
     *
     * @return bool True si se ejecutó la limpieza.
     */
    public function executeScheduledCleanup(): bool
    {
        try {
            $environment = EnvironmentDetector::detectEnvironment();
            
            // Verificar si es el momento apropiado
            if (!EnvironmentDetector::shouldRunCleanup($environment)) {
                return false;
            }
            
            // Ejecutar limpieza
            $deletedCount = $this->cleanOldLogs();
            
            // Log de ejecución programada
            if (EnvironmentDetector::isDevelopment($environment)) {
                error_log("Limpieza programada de logs ejecutada a las " . date('H:i:s'));
            }
            
            return $deletedCount > 0;
            
        } catch (\Throwable $e) {
            error_log("Error en limpieza programada de logs: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtiene el tamaño total de todos los archivos de log.
     *
     * @return int Tamaño total en bytes.
     */
    public function getTotalLogSize(): int
    {
        $totalSize = 0;
        $logFiles = glob($this->logDirectory . '/*.log*');
        
        foreach ($logFiles as $file) {
            if (file_exists($file)) {
                $totalSize += filesize($file);
            }
        }
        
        return $totalSize;
    }

    /**
     * Obtiene el número de archivos de log.
     *
     * @return int Número de archivos de log.
     */
    public function getLogFileCount(): int
    {
        $logFiles = glob($this->logDirectory . '/*.log*');
        return count($logFiles);
    }

    /**
     * Obtiene información de debug sobre los archivos de log.
     *
     * @return array Información de debug.
     */
    public function getDebugInfo(): array
    {
        $environment = EnvironmentDetector::detectEnvironment();
        
        return [
            'environment' => $environment,
            'max_log_size' => $this->maxLogSize,
            'log_directory' => $this->logDirectory,
            'total_size' => $this->getTotalLogSize(),
            'file_count' => $this->getLogFileCount(),
            'retention_days' => EnvironmentDetector::getRetentionDays($environment),
            'cleanup_hours' => EnvironmentDetector::getCleanupHours($environment),
            'should_run_cleanup' => EnvironmentDetector::shouldRunCleanup($environment),
        ];
    }

    /**
     * Establece el tamaño máximo del archivo de log.
     *
     * @param int $maxLogSize Nuevo tamaño máximo en bytes.
     * @return void
     */
    public function setMaxLogSize(int $maxLogSize): void
    {
        $this->maxLogSize = $maxLogSize;
    }

    /**
     * Establece el directorio de logs.
     *
     * @param string $logDirectory Nuevo directorio de logs.
     * @return void
     */
    public function setLogDirectory(string $logDirectory): void
    {
        $this->logDirectory = $logDirectory;
    }

    /**
     * Obtiene el directorio por defecto de logs.
     *
     * @return string Directorio por defecto.
     */
    private function getDefaultLogDirectory(): string
    {
        $uploadDir = wp_upload_dir();
        return $uploadDir['basedir'] . '/mi-integracion-api-logs';
    }
}
