<?php declare(strict_types=1);
/**
 * Clase para analizar y diagnosticar problemas en la sincronización por lotes
 * 
 * Este archivo es parte de las herramientas de diagnóstico para el sistema de sincronización.
 * 
 * @author Copilot
 * @date 2 de julio de 2025
 */

namespace MiIntegracionApi\Diagnostics;

/**
 * Clase que implementa métodos de diagnóstico para la sincronización por lotes
 */
class BatchDiagnostics {
    /**
     * @var string Ruta al directorio de logs
     */
    private $log_dir;
    
    /**
     * @var array Resultados de los diagnósticos
     */
    private $results = [];
    
    /**
     * @var array Métricas recopiladas durante las pruebas
     */
    private $metrics = [];
    
    /**
     * Constructor
     * 
     * @param string $log_dir Directorio para los logs de diagnóstico
     */
    public function __construct($log_dir = null) {
        $this->log_dir = $log_dir ?: __DIR__ . '/logs/batch-diagnostics';
        
        // Asegurar que el directorio existe
        if (!is_dir($this->log_dir)) {
            mkdir($this->log_dir, 0755, true);
        }
    }
    
    /**
     * Registra un evento durante el procesamiento por lotes
     * 
     * @param string $type Tipo de evento (start, end, error, memory, etc.)
     * @param int $batch_size Tamaño del lote
     * @param array $data Datos adicionales del evento
     */
    public function recordEvent($type, $batch_size, $data = []) {
        $event = [
            'timestamp' => microtime(true),
            'memory' => memory_get_usage(true),
            'type' => $type,
            'batch_size' => $batch_size,
            'data' => $data
        ];
        
        $this->results[] = $event;
        
        // Registrar métricas específicas
        if ($type === 'batch_complete') {
            if (!isset($this->metrics[$batch_size])) {
                $this->metrics[$batch_size] = [
                    'batches_processed' => 0,
                    'items_processed' => 0,
                    'errors' => 0,
                    'total_time' => 0,
                    'avg_time_per_batch' => 0,
                    'memory_peaks' => []
                ];
            }
            
            $this->metrics[$batch_size]['batches_processed']++;
            $this->metrics[$batch_size]['items_processed'] += $data['processed'] ?? 0;
            $this->metrics[$batch_size]['errors'] += $data['errors'] ?? 0;
            $this->metrics[$batch_size]['total_time'] += $data['time'] ?? 0;
            $this->metrics[$batch_size]['memory_peaks'][] = $data['memory_peak'] ?? 0;
            
            // Actualizar promedio
            $this->metrics[$batch_size]['avg_time_per_batch'] = 
                $this->metrics[$batch_size]['total_time'] / $this->metrics[$batch_size]['batches_processed'];
        }
    }
    
    /**
     * Registra un error durante el procesamiento por lotes
     * 
     * @param int $batch_size Tamaño del lote
     * @param string $message Mensaje de error
     * @param string $context Contexto donde ocurrió el error
     * @param \Exception|null $exception Excepción si está disponible
     */
    public function recordError($batch_size, $message, $context = '', $exception = null) {
        $error_data = [
            'message' => $message,
            'context' => $context
        ];
        
        if ($exception) {
            $error_data['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ];
        }
        
        $this->recordEvent('error', $batch_size, $error_data);
    }
    
    /**
     * Analiza los resultados para identificar patrones y problemas
     * 
     * @return array Problemas identificados con posibles soluciones
     */
    public function analyzeResults() {
        $issues = [];
        
        // Analizar métricas por tamaño de lote
        foreach ($this->metrics as $batch_size => $metrics) {
            // Verificar si el número de elementos procesados es menor que el esperado
            $expected_items = $batch_size * $metrics['batches_processed'];
            if ($metrics['items_processed'] < $expected_items * 0.9) {
                $issues[] = [
                    'type' => 'incomplete_processing',
                    'batch_size' => $batch_size,
                    'description' => "El lote de tamaño {$batch_size} procesó menos elementos de lo esperado ({$metrics['items_processed']} de {$expected_items})",
                    'possible_cause' => 'Posible problema de timeout o memoria durante el procesamiento',
                    'suggestion' => 'Reducir el tamaño del lote o aumentar los límites de tiempo y memoria'
                ];
            }
            
            // Verificar tasa de error alta
            $error_rate = ($metrics['errors'] / max(1, $metrics['items_processed'])) * 100;
            if ($error_rate > 10) {
                $issues[] = [
                    'type' => 'high_error_rate',
                    'batch_size' => $batch_size,
                    'description' => "Tasa de error alta ({$error_rate}%) para lotes de tamaño {$batch_size}",
                    'possible_cause' => 'Errores de validación o problemas en la conexión API',
                    'suggestion' => 'Revisar los errores específicos en los logs para identificar patrones'
                ];
            }
            
            // Verificar tiempos de procesamiento excesivos
            if ($metrics['avg_time_per_batch'] > 10 && $batch_size <= 100) {
                $issues[] = [
                    'type' => 'slow_processing',
                    'batch_size' => $batch_size,
                    'description' => "Tiempo de procesamiento excesivo para lotes de tamaño {$batch_size} (promedio: {$metrics['avg_time_per_batch']} segundos)",
                    'possible_cause' => 'Posible cuello de botella en la API o el procesamiento',
                    'suggestion' => 'Implementar caché o optimizar las llamadas a la API'
                ];
            }
            
            // Verificar picos de memoria
            $avg_memory = array_sum($metrics['memory_peaks']) / count($metrics['memory_peaks']);
            $max_memory = max($metrics['memory_peaks']);
            if ($max_memory > $avg_memory * 1.5) {
                $issues[] = [
                    'type' => 'memory_spikes',
                    'batch_size' => $batch_size,
                    'description' => "Picos de memoria detectados para lotes de tamaño {$batch_size} (máximo: " . round($max_memory / 1024 / 1024, 2) . " MB)",
                    'possible_cause' => 'Posible fuga de memoria o acumulación de objetos',
                    'suggestion' => 'Revisar la liberación de recursos y el manejo de objetos grandes'
                ];
            }
        }
        
        // Comparar entre tamaños de lote
        if (count($this->metrics) > 1) {
            $sizes = array_keys($this->metrics);
            $efficiencies = [];
            
            foreach ($sizes as $size) {
                if ($this->metrics[$size]['batches_processed'] > 0) {
                    $items_per_second = $this->metrics[$size]['items_processed'] / max(1, $this->metrics[$size]['total_time']);
                    $efficiencies[$size] = $items_per_second;
                }
            }
            
            // Encontrar el tamaño más eficiente
            $most_efficient_size = array_keys($efficiencies, max($efficiencies))[0];
            
            foreach ($this->metrics as $size => $metrics) {
                if ($size != $most_efficient_size && isset($efficiencies[$size]) && $efficiencies[$most_efficient_size] > $efficiencies[$size] * 1.2) {
                    $issues[] = [
                        'type' => 'suboptimal_batch_size',
                        'batch_size' => $size,
                        'description' => "El tamaño de lote {$size} es menos eficiente que {$most_efficient_size} (diferencia: " . round(($efficiencies[$most_efficient_size] / $efficiencies[$size] - 1) * 100, 2) . "%)",
                        'possible_cause' => 'El tamaño del lote no está optimizado para el sistema actual',
                        'suggestion' => "Considerar cambiar el tamaño de lote predeterminado a {$most_efficient_size}"
                    ];
                }
            }
        }
        
        return $issues;
    }
    
    /**
     * Guarda los resultados y análisis en un archivo de log
     * 
     * @return string Ruta al archivo de log
     */
    public function saveReport() {
        $issues = $this->analyzeResults();
        
        $filename = $this->log_dir . '/batch-diagnostics-' . date('YmdHis') . '.log';
        
        $report = "=== INFORME DE DIAGNÓSTICO DE SINCRONIZACIÓN POR LOTES ===\n";
        $report .= "Fecha: " . date('Y-m-d H:i:s') . "\n\n";
        
        $report .= "== MÉTRICAS POR TAMAÑO DE LOTE ==\n";
        foreach ($this->metrics as $batch_size => $metrics) {
            $report .= "Tamaño de lote: {$batch_size}\n";
            $report .= "  - Lotes procesados: {$metrics['batches_processed']}\n";
            $report .= "  - Elementos procesados: {$metrics['items_processed']}\n";
            $report .= "  - Errores: {$metrics['errors']}\n";
            $report .= "  - Tiempo total: {$metrics['total_time']} segundos\n";
            $report .= "  - Tiempo promedio por lote: {$metrics['avg_time_per_batch']} segundos\n";
            $report .= "  - Memoria pico promedio: " . round(array_sum($metrics['memory_peaks']) / count($metrics['memory_peaks']) / 1024 / 1024, 2) . " MB\n";
            $report .= "\n";
        }
        
        if (!empty($issues)) {
            $report .= "== PROBLEMAS IDENTIFICADOS ==\n";
            foreach ($issues as $index => $issue) {
                $report .= ($index + 1) . ". {$issue['description']}\n";
                $report .= "   Posible causa: {$issue['possible_cause']}\n";
                $report .= "   Sugerencia: {$issue['suggestion']}\n\n";
            }
        } else {
            $report .= "== ANÁLISIS ==\n";
            $report .= "No se han detectado problemas significativos en el procesamiento por lotes.\n\n";
        }
        
        // Añadir recomendación general
        if (count($this->metrics) > 0) {
            // Encontrar el tamaño más eficiente basado en elementos por segundo
            $efficiencies = [];
            foreach ($this->metrics as $size => $metrics) {
                if ($metrics['batches_processed'] > 0) {
                    $items_per_second = $metrics['items_processed'] / max(1, $metrics['total_time']);
                    $efficiencies[$size] = $items_per_second;
                }
            }
            
            if (!empty($efficiencies)) {
                $most_efficient_size = array_keys($efficiencies, max($efficiencies))[0];
                $report .= "== RECOMENDACIÓN ==\n";
                $report .= "Basado en las pruebas, el tamaño de lote más eficiente parece ser: {$most_efficient_size}\n";
                $report .= "Tasa de procesamiento: " . round($efficiencies[$most_efficient_size], 2) . " elementos por segundo\n\n";
            }
        }
        
        // Guardar el reporte
        file_put_contents($filename, $report);
        
        return $filename;
    }
}
