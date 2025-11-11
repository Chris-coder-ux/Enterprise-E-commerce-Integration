<?php
declare(strict_types=1);

/**
 * Helper para añadir logs detallados en el proceso de sincronización
 *
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 */

namespace MiIntegracionApi\Core;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

/**
 * Clase para proporcionar funciones de logging detallado para el proceso de sincronización
 */
class SyncLoggingHelper {
    /**
     * Instancia del logger
     * 
     * @var \MiIntegracionApi\Helpers\Logger|\MiIntegracionApi\Core\LogManager
     */
    private $logger;

    /**
     * Constructor
     * 
     * @param string $context Contexto para el logger
     */
    public function __construct(string $context = 'sync-detail') {
        if (class_exists('\MiIntegracionApi\Logging\Core\LogManager')) {
            $this->logger = \MiIntegracionApi\Logging\Core\LogManager::getInstance()->getLogger($context);
        } elseif (class_exists('\MiIntegracionApi\Logging\Core\LoggerBasic')) {
            $this->logger = \MiIntegracionApi\Logging\Core\LoggerBasic::getInstance($context);
        }
    }

    /**
     * Log del inicio de un batch
     * 
     * @param array $params Parámetros del batch
     */
    public function logBatchStart(array $params) {
        if (!$this->logger) return;
        
        $this->logger->info("Iniciando procesamiento de batch", [
            'recovery_mode' => $params['recovery_mode'] ?? false,
            'entity' => $params['entity'] ?? 'unknown',
            'direction' => $params['direction'] ?? 'unknown',
            'offset' => $params['offset'] ?? 0,
            'batch_size' => $params['batch_size'] ?? 0,
            'memoria_inicial' => $this->getMemoryUsage(),
            'timestamp' => $this->getCurrentTimestamp()
        ]);
    }
    
    /**
     * Log del fin de un batch
     * 
     * @param array $params Parámetros del batch
     * @param float $startTime Tiempo de inicio
     */
    public function logBatchEnd(array $params, float $startTime) {
        if (!$this->logger) return;
        
        $duration = microtime(true) - $startTime;
        
        $this->logger->info("Finalizando procesamiento de batch", [
            'entity' => $params['entity'] ?? 'unknown',
            'direction' => $params['direction'] ?? 'unknown',
            'procesados' => $params['processed'] ?? 0,
            'errores' => $params['errors'] ?? 0,
            'duration' => number_format($duration, 2) . ' segundos',
            'memoria_final' => $this->getMemoryUsage(),
            'pico_memoria' => $this->getPeakMemoryUsage(),
            'timestamp' => $this->getCurrentTimestamp()
        ]);
    }
    
    /**
     * Log de la llamada a API
     * 
     * @param array $params Parámetros de la llamada
     * @param mixed $response Respuesta de la API
     */
    public function logApiCall(array $params, $response) {
        if (!$this->logger) return;
        
        $this->logger->info("Respuesta API obtenida", [
            'inicio' => $params['inicio'] ?? 0,
            'fin' => $params['fin'] ?? 0,
            'depth' => $params['depth'] ?? 0,
            'estado' => is_wp_error($response) ? 'error' : 'éxito',
            'error_message' => is_wp_error($response) ? $response->get_error_message() : null,
            'memoria_despues_api' => $this->getMemoryUsage(),
            'timestamp' => $this->getCurrentTimestamp()
        ]);
    }
    
    /**
     * Log de procesamiento de artículo
     * 
     * @param array $articulo Datos del artículo
     * @param int $indice Índice de procesamiento
     */
    public function logArticuloStart($articulo, int $indice) {
        if (!$this->logger) return;
        
        $this->logger->debug("Procesando artículo", [
            'indice' => $indice,
            'codigo' => $articulo['Codigo'] ?? ($articulo['codigo'] ?? 'desconocido'),
            'descripcion' => $articulo['Descripcion'] ?? ($articulo['descripcion'] ?? 'desconocida'),
            'memoria_antes' => $this->getMemoryUsage(),
            'timestamp' => $this->getCurrentTimestamp()
        ]);
        
        return microtime(true);
    }
    
    /**
     * Log de finalización de procesamiento de artículo
     * 
     * @param array $articulo Datos del artículo
     * @param float $startTime Tiempo de inicio
     * @param bool $success Éxito de la operación
     * @param string $error Error si lo hay
     */
    public function logArticuloEnd($articulo, float $startTime, bool $success = true, string $error = '') {
        if (!$this->logger) return;
        
        $duration = microtime(true) - $startTime;
        
        $this->logger->debug("Artículo procesado", [
            'codigo' => $articulo['Codigo'] ?? ($articulo['codigo'] ?? 'desconocido'),
            'resultado' => $success ? 'éxito' : 'error',
            'error' => $error,
            'tiempo' => number_format($duration, 4) . ' segundos',
            'memoria_despues' => $this->getMemoryUsage(),
            'timestamp' => $this->getCurrentTimestamp()
        ]);
    }
    
    /**
     * Log de resumen de memoria
     */
    public function logMemoriaSummary() {
        if (!$this->logger) return;
        
        $this->logger->info("Resumen de memoria", [
            'memoria_actual' => $this->getMemoryUsage(),
            'pico_memoria' => $this->getPeakMemoryUsage(),
            'timestamp' => $this->getCurrentTimestamp()
        ]);
    }
    
    /**
     * Obtener uso de memoria formateado
     * 
     * @return string
     */
    private function getMemoryUsage() {
        return \MiIntegracionApi\Core\MemoryManager::getMemoryStats()['current'] . ' MB';
    }
    
    /**
     * Obtener pico de memoria formateado
     * 
     * @return string
     */
    private function getPeakMemoryUsage() {
        return \MiIntegracionApi\Core\MemoryManager::getMemoryStats()['peak'] . ' MB';
    }
    
    /**
     * Obtener timestamp actual
     * 
     * @return string
     */
    private function getCurrentTimestamp() {
        return date('Y-m-d H:i:s');
    }
}
