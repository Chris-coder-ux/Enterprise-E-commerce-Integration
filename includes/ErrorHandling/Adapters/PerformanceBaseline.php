<?php

declare(strict_types=1);

namespace MiIntegracionApi\ErrorHandling\Adapters;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestor de métricas de referencia de rendimiento
 * 
 * Esta clase gestiona las métricas de referencia (baselines) para el rendimiento
 * del sistema, incluyendo límites aceptables, alertas automáticas y comparaciones.
 * 
 * @package MiIntegracionApi\ErrorHandling\Adapters
 * @since 1.0.0
 */
class PerformanceBaseline
{
    /**
     * Métricas de referencia por defecto
     * 
     * @var array
     */
    private array $defaultBaselines = [
        'time' => [
            'toArray' => ['warning' => 0.001, 'critical' => 0.005, 'severe' => 0.01],
            'toJson' => ['warning' => 0.002, 'critical' => 0.01, 'severe' => 0.02],
            'toWpError' => ['warning' => 0.001, 'critical' => 0.005, 'severe' => 0.01],
            'toWpRestResponse' => ['warning' => 0.01, 'critical' => 0.05, 'severe' => 0.1],
            'toRestApiFormat' => ['warning' => 0.002, 'critical' => 0.01, 'severe' => 0.02],
            'toAjaxFormat' => ['warning' => 0.001, 'critical' => 0.005, 'severe' => 0.01],
            'toCliFormat' => ['warning' => 0.001, 'critical' => 0.005, 'severe' => 0.01]
        ],
        'memory' => [
            'toArray' => ['warning' => 512, 'critical' => 1024, 'severe' => 2048],
            'toJson' => ['warning' => 1024, 'critical' => 2048, 'severe' => 4096],
            'toWpError' => ['warning' => 512, 'critical' => 1024, 'severe' => 2048],
            'toWpRestResponse' => ['warning' => 2048, 'critical' => 4096, 'severe' => 8192],
            'toRestApiFormat' => ['warning' => 1024, 'critical' => 2048, 'severe' => 4096],
            'toAjaxFormat' => ['warning' => 512, 'critical' => 1024, 'severe' => 2048],
            'toCliFormat' => ['warning' => 256, 'critical' => 512, 'severe' => 1024]
        ],
        'efficiency' => [],
        'stability' => []
    ];
    
    /**
     * Métricas de referencia actuales
     * 
     * @var array
     */
    private array $baselines;
    
    /**
     * Historial de métricas para actualización dinámica
     * 
     * @var array
     */
    private array $metricHistory;
    
    /**
     * Configuración de actualización automática
     * 
     * @var array
     */
    private array $updateConfig = [
        'enabled' => true,
        'min_samples' => 100,
        'update_interval' => 3600, // 1 hora
        'decay_factor' => 0.9,
        'max_history' => 1000
    ];
    
    /**
     * Alertas generadas
     * 
     * @var array
     */
    private array $alerts;
    
    /**
     * Constructor
     * 
     * @param array $customBaselines Métricas personalizadas
     * @param array $updateConfig Configuración de actualización
     */
    public function __construct(array $customBaselines = [], array $updateConfig = [])
    {
        // Generar umbrales comunes para efficiency y stability
        $this->defaultBaselines['efficiency'] = $this->generateCommonThresholds('efficiency');
        $this->defaultBaselines['stability'] = $this->generateCommonThresholds('stability');
        
        // Cargar baselines persistentes si existen
        $this->loadBaselines();
        
        // Si no hay baselines persistentes, usar los por defecto + personalizados
        if (empty($this->baselines)) {
            $this->baselines = array_merge_recursive($this->defaultBaselines, $customBaselines);
        } else {
            // Combinar baselines persistentes con personalizados
            $this->baselines = array_merge_recursive($this->baselines, $customBaselines);
        }
        
        $this->updateConfig = array_merge($this->updateConfig, $updateConfig);
    }
    
    /**
     * Genera umbrales comunes para efficiency y stability
     * 
     * @param string $type Tipo de métrica (efficiency o stability)
     * @return array Umbrales generados
     */
    private function generateCommonThresholds(string $type): array
    {
        // Configuración base común
        $baseThresholds = [
            'toArray' => ['warning' => 90, 'critical' => 80, 'severe' => 70],
            'toJson' => ['warning' => 85, 'critical' => 75, 'severe' => 65],
            'toWpError' => ['warning' => 90, 'critical' => 80, 'severe' => 70],
            'toWpRestResponse' => ['warning' => 80, 'critical' => 70, 'severe' => 60],
            'toRestApiFormat' => ['warning' => 85, 'critical' => 75, 'severe' => 65],
            'toAjaxFormat' => ['warning' => 90, 'critical' => 80, 'severe' => 70],
            'toCliFormat' => ['warning' => 95, 'critical' => 85, 'severe' => 75]
        ];
        
        // Ajustar umbrales según el tipo de métrica
        if ($type === 'efficiency') {
            // Efficiency puede ser más estricta - requiere mejor rendimiento
            foreach ($baseThresholds as $method => $thresholds) {
                $baseThresholds[$method] = [
                    'warning' => min(95, $thresholds['warning'] + 5),
                    'critical' => min(90, $thresholds['critical'] + 5),
                    'severe' => min(85, $thresholds['severe'] + 5)
                ];
            }
        } elseif ($type === 'stability') {
            // Stability puede ser más permisiva - acepta más variabilidad
            foreach ($baseThresholds as $method => $thresholds) {
                $baseThresholds[$method] = [
                    'warning' => max(70, $thresholds['warning'] - 5),
                    'critical' => max(60, $thresholds['critical'] - 5),
                    'severe' => max(50, $thresholds['severe'] - 5)
                ];
            }
        }
        
        return $baseThresholds;
    }
    
    /**
     * Establece métricas de referencia basadas en datos históricos
     * 
     * @param array $results Resultados del benchmarking
     * @return void
     */
    public function establishBaselines(array $results): void
    {
        if (empty($results)) {
            // Si no hay resultados, mantener las métricas por defecto
            return;
        }
        
        if (!empty($calculatedBaselines = $this->calculateBaselinesFromResults($results))) {
            $this->baselines = $calculatedBaselines;
        }
        $this->saveBaselines();
    }
    
    /**
     * Calcula métricas de referencia desde resultados
     * 
     * @param array $results Resultados del benchmarking
     * @return array Métricas de referencia calculadas
     */
    private function calculateBaselinesFromResults(array $results): array
    {
        $baselines = [];
        
        foreach ($results as $category => $data) {
            if (is_array($data)) {
                foreach ($data as $method => $metrics) {
                    if (isset($metrics['time']['average']) && isset($metrics['memory']['average'])) {
                        // Usar categoría.método como clave única para evitar conflictos
                        $key = $category . '.' . $method;
                        $baselines['time'][$key] = $this->calculateThresholds($metrics['time']['average'], 'time');
                        $baselines['memory'][$key] = $this->calculateThresholds($metrics['memory']['average'], 'memory');
                        $baselines['efficiency'][$key] = $this->calculateThresholds($this->calculateEfficiency($metrics), 'efficiency');
                        $baselines['stability'][$key] = $this->calculateThresholds($this->calculateStability($metrics), 'stability');
                    }
                }
            }
        }
        
        return $baselines;
    }
    
    /**
     * Calcula umbrales basados en métricas
     * 
     * @param float $value Valor de la métrica
     * @param string $type Tipo de métrica
     * @return array Umbrales calculados
     */
    private function calculateThresholds(float $value, string $type): array
    {
        return match ($type) {
            'memory' => [
                'warning' => $value * 2,
                'critical' => $value * 4,
                'severe' => $value * 8
            ],
            'efficiency', 'stability' => [
                'warning' => max(0, $value - 10),
                'critical' => max(0, $value - 20),
                'severe' => max(0, $value - 30)
            ],
            default => [
                'warning' => $value * 2,
                'critical' => $value * 5,
                'severe' => $value * 10
            ]
        };
    }
    
    /**
     * Calcula la eficiencia de un método
     * 
     * @param array $metrics Métricas del método
     * @return float Eficiencia en porcentaje
     */
    private function calculateEfficiency(array $metrics): float
    {
        if (!isset($metrics['time']['average']) || !isset($metrics['memory']['average'])) {
            return 0.0;
        }
        
        return (max(0, 100 - ($metrics['time']['average'] * 10000)) + max(0, 100 - ($metrics['memory']['average'] / 1024))) / 2;
    }
    
    /**
     * Calcula la estabilidad de un método
     * 
     * @param array $metrics Métricas del método
     * @return float Estabilidad en porcentaje
     */
    private function calculateStability(array $metrics): float
    {
        if (!isset($metrics['time']['std_dev']) || !isset($metrics['time']['average'])) {
            return 100.0;
        }
        
        return max(0, 100 - (($metrics['time']['std_dev'] / $metrics['time']['average']) * 100));
    }
    
    /**
     * Actualiza métricas de referencia con nuevos datos
     * 
     * @param array $newResults Nuevos resultados
     * @return void
     */
    public function updateBaselines(array $newResults): void
    {
        if (!$this->updateConfig['enabled']) {
            return;
        }
        
        $this->addToHistory($newResults);
        
        if (count($this->metricHistory) >= $this->updateConfig['min_samples']) {
            $this->baselines = $this->calculateBaselinesFromHistory();
            $this->saveBaselines();
        }
    }
    
    /**
     * Añade nuevos datos al historial
     * 
     * @param array $results Resultados del benchmarking
     * @return void
     */
    private function addToHistory(array $results): void
    {
        foreach ($results as $category => $data) {
            if (is_array($data)) {
                foreach ($data as $method => $metrics) {
                    if (isset($metrics['time']['average']) && isset($metrics['memory']['average'])) {
                        $this->metricHistory[] = [
                            'timestamp' => time(),
                            'category' => $category,
                            'method' => $method,
                            'time' => $metrics['time']['average'],
                            'memory' => $metrics['memory']['average'],
                            'efficiency' => $this->calculateEfficiency($metrics),
                            'stability' => $this->calculateStability($metrics)
                        ];
                    }
                }
            }
        }
        
        // Limitar el tamaño del historial
        if (count($this->metricHistory) > $this->updateConfig['max_history']) {
            $this->metricHistory = array_slice($this->metricHistory, -$this->updateConfig['max_history']);
        }
    }
    
    /**
     * Calcula métricas de referencia desde el historial
     * 
     * @return array Métricas de referencia calculadas
     */
    private function calculateBaselinesFromHistory(): array
    {
        $baselines = [];
        $methodGroups = [];
        
        // Agrupar por método
        foreach ($this->metricHistory as $entry) {
            if (!isset($methodGroups[$key = $entry['category'] . '.' . $entry['method']])) {
                $methodGroups[$key] = [];
            }
            $methodGroups[$key][] = $entry;
        }
        
        // Calcular métricas para cada método
        foreach ($methodGroups as $key => $entries) {
            $times = array_column($entries, 'time');
            $memories = array_column($entries, 'memory');
            $efficiencies = array_column($entries, 'efficiency');
            $stabilities = array_column($entries, 'stability');
            
            $baselines['time'][$key] = $this->calculateThresholds($this->calculateMedian($times), 'time');
            $baselines['memory'][$key] = $this->calculateThresholds($this->calculateMedian($memories), 'memory');
            $baselines['efficiency'][$key] = $this->calculateThresholds($this->calculateMedian($efficiencies), 'efficiency');
            $baselines['stability'][$key] = $this->calculateThresholds($this->calculateMedian($stabilities), 'stability');
        }
        
        return $baselines;
    }
    
    /**
     * Calcula la mediana
     * 
     * @param array $values Valores
     * @return float Mediana
     */
    private function calculateMedian(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        
        sort($values);
        $count = count($values);
        $middle = floor($count / 2);
        
        return $count % 2 === 0 
            ? ($values[$middle - 1] + $values[$middle]) / 2 
            : $values[$middle];
    }
    
    /**
     * Evalúa el rendimiento contra las métricas de referencia
     * 
     * @param string $method Método a evaluar
     * @param array $metrics Métricas del método
     * @return array Resultado de la evaluación
     */
    public function evaluatePerformance(string $method, array $metrics): array
    {
        $evaluation = [
            'method' => $method,
            'status' => 'ok',
            'alerts' => [],
            'deviations' => []
        ];
        
        // Definir métricas a evaluar
        $metricsToEvaluate = [
            'time' => $metrics['time']['average'] ?? 0,
            'memory' => $metrics['memory']['average'] ?? 0,
            'efficiency' => $this->calculateEfficiency($metrics),
            'stability' => $this->calculateStability($metrics)
        ];
        
        // Evaluar cada métrica
        foreach ($metricsToEvaluate as $metricType => $value) {
            if (($status = $this->evaluateMetric($metricType, $method, $value))['status'] !== 'ok') {
                $evaluation['status'] = $status['status'];
                $evaluation['alerts'][] = $status['alert'];
                $evaluation['deviations'][$metricType] = $status['deviation'];
                
                // Almacenar alerta global
                $this->alerts[] = [
                    'timestamp' => time(),
                    'method' => $method,
                    'metric_type' => $metricType,
                    'severity' => $status['status'],
                    'message' => $status['alert'],
                    'deviation' => $status['deviation']
                ];
            }
        }
        
        return $evaluation;
    }
    
    /**
     * Evalúa una métrica específica
     * 
     * @param string $metricType Tipo de métrica
     * @param string $method Método
     * @param float $value Valor actual
     * @return array Resultado de la evaluación
     */
    private function evaluateMetric(string $metricType, string $method, float $value): array
    {
        if (empty($baseline = $this->getBaselineForMethod($metricType, $method))) {
            return ['status' => 'ok', 'alert' => null, 'deviation' => 0];
        }
        
        $deviation = $this->calculateDeviation($value, $baseline, $metricType);
        
        if ($value >= $baseline['severe']) {
            return [
                'status' => 'severe',
                'alert' => "$metricType severo: {$this->formatValue($value, $metricType)} (baseline: {$this->formatValue($baseline['severe'], $metricType)})",
                'deviation' => $deviation
            ];
        } elseif ($value >= $baseline['critical']) {
            return [
                'status' => 'critical',
                'alert' => "$metricType crítico: {$this->formatValue($value, $metricType)} (baseline: {$this->formatValue($baseline['critical'], $metricType)})",
                'deviation' => $deviation
            ];
        } elseif ($value >= $baseline['warning']) {
            return [
                'status' => 'warning',
                'alert' => "$metricType advertencia: {$this->formatValue($value, $metricType)} (baseline: {$this->formatValue($baseline['warning'], $metricType)})",
                'deviation' => $deviation
            ];
        }
        
        return ['status' => 'ok', 'alert' => null, 'deviation' => $deviation];
    }
    
    /**
     * Obtiene la métrica de referencia para un método
     * 
     * @param string $metricType Tipo de métrica
     * @param string $method Método
     * @return array Métrica de referencia
     */
    private function getBaselineForMethod(string $metricType, string $method): array
    {
        // Buscar en métricas específicas del método
        if (isset($this->baselines[$metricType][$method])) {
            return $this->baselines[$metricType][$method];
        }
        
        // Buscar en métricas por defecto
        if (isset($this->defaultBaselines[$metricType][$method])) {
            return $this->defaultBaselines[$metricType][$method];
        }
        
        return [];
    }
    
    /**
     * Calcula la desviación de la métrica
     * 
     * @param float $value Valor actual
     * @param array $baseline Métrica de referencia
     * @param string $metricType Tipo de métrica
     * @return float Desviación en porcentaje
     */
    private function calculateDeviation(float $value, array $baseline, string $metricType): float
    {
        if (($reference = $baseline['warning']) == 0) {
            return 0;
        }
        
        $deviation = (($value - $reference) / $reference) * 100;
        
        // Para eficiencia y estabilidad, invertir la lógica
        return in_array($metricType, ['efficiency', 'stability']) ? -$deviation : $deviation;
    }
    
    /**
     * Formatea un valor para mostrar
     * 
     * @param float $value Valor
     * @param string $metricType Tipo de métrica
     * @return string Valor formateado
     */
    private function formatValue(float $value, string $metricType): string
    {
        return match ($metricType) {
            'time' => $this->formatTime($value),
            'memory' => $this->formatMemory($value),
            'efficiency', 'stability' => number_format($value, 1) . '%',
            default => number_format($value, 4)
        };
    }
    
    /**
     * Formatea tiempo para mostrar
     * 
     * @param float $time Tiempo en segundos
     * @return string Tiempo formateado
     */
    private function formatTime(float $time): string
    {
        if ($time < 0.001) {
            return number_format($time * 1000000, 2) . ' μs';
        } elseif ($time < 1) {
            return number_format($time * 1000, 2) . ' ms';
        } else {
            return number_format($time, 4) . ' s';
        }
    }
    
    /**
     * Formatea memoria para mostrar
     * 
     * @param float $memory Memoria en bytes
     * @return string Memoria formateada
     */
    private function formatMemory(float $memory): string
    {
        if ($memory < 1024) {
            return number_format($memory) . ' B';
        } elseif ($memory < 1024 * 1024) {
            return number_format($memory / 1024, 2) . ' KB';
        } else {
            return number_format($memory / (1024 * 1024), 2) . ' MB';
        }
    }
    
    /**
     * Guarda las métricas de referencia en wp_options
     * 
     * @return void
     */
    private function saveBaselines(): void
    {
        update_option('mia_performance_baselines', $this->baselines);
    }
    
    /**
     * Carga las métricas de referencia desde wp_options
     * 
     * @return void
     */
    private function loadBaselines(): void
    {
        $savedBaselines = get_option('mia_performance_baselines', []);
        if (!empty($savedBaselines)) {
            $this->baselines = $savedBaselines;
        }
    }
    
    /**
     * Limpia las métricas de referencia persistentes
     * 
     * @return bool True si se eliminaron correctamente
     */
    public function clearPersistentBaselines(): bool
    {
        return delete_option('mia_performance_baselines');
    }
    
    /**
     * Fuerza la recarga de baselines desde la persistencia
     * 
     * @return void
     */
    public function reloadBaselines(): void
    {
        $this->loadBaselines();
    }
    
    /**
     * Obtiene todas las métricas de referencia
     * 
     * @return array Métricas de referencia
     */
    public function getBaselines(): array
    {
        return $this->baselines;
    }
    
    /**
     * Obtiene las métricas de referencia para un tipo específico
     * 
     * @param string $type Tipo de métrica
     * @return array Métricas de referencia
     */
    public function getBaselinesByType(string $type): array
    {
        return $this->baselines[$type] ?? [];
    }
    
    /**
     * Obtiene las métricas de referencia para un método específico
     * 
     * @param string $method Método
     * @return array Métricas de referencia
     */
    public function getBaselinesForMethod(string $method): array
    {
        $methodBaselines = [];
        
        foreach ($this->baselines as $type => $methods) {
            if (isset($methods[$method])) {
                $methodBaselines[$type] = $methods[$method];
            }
        }
        
        return $methodBaselines;
    }
    
    /**
     * Obtiene el historial de métricas
     * 
     * @return array Historial de métricas
     */
    public function getMetricHistory(): array
    {
        return $this->metricHistory;
    }
    
    /**
     * Obtiene las alertas generadas
     * 
     * @return array Alertas
     */
    public function getAlerts(): array
    {
        return $this->alerts;
    }
    
    /**
     * Obtiene alertas filtradas por severidad
     * 
     * @param string|null $severity Severidad a filtrar (opcional)
     * @return array Alertas filtradas
     */
    public function getAlertsBySeverity(?string $severity = null): array
    {
        if ($severity === null) {
            return $this->alerts;
        }
        
        return array_filter($this->alerts, function($alert) use ($severity) {
            return $alert['severity'] === $severity;
        });
    }
    
    /**
     * Limpia las alertas almacenadas
     * 
     * @return void
     */
    public function clearAlerts(): void
    {
        $this->alerts = [];
    }
    
    /**
     * Obtiene el número de alertas por severidad
     * 
     * @return array Conteo de alertas por severidad
     */
    public function getAlertCounts(): array
    {
        $counts = [
            'total' => count($this->alerts),
            'warning' => 0,
            'critical' => 0,
            'severe' => 0
        ];
        
        foreach ($this->alerts as $alert) {
            if (isset($counts[$alert['severity']])) {
                $counts[$alert['severity']]++;
            }
        }
        
        return $counts;
    }
    
    /**
     * Genera reporte de métricas de referencia
     * 
     * @return string Reporte
     */
    public function generateReport(): string
    {
        $report = "📊 REPORTE DE MÉTRICAS DE REFERENCIA\n";
        $report .= "===================================\n\n";
        
        $report .= "🔧 CONFIGURACIÓN DE ACTUALIZACIÓN\n";
        $report .= "==================================\n";
        $report .= "Actualización automática: " . ($this->updateConfig['enabled'] ? 'Habilitada' : 'Deshabilitada') . "\n";
        $report .= "Mínimo de muestras: {$this->updateConfig['min_samples']}\n";
        $report .= "Intervalo de actualización: {$this->updateConfig['update_interval']}s\n";
        $report .= "Factor de decaimiento: {$this->updateConfig['decay_factor']}\n";
        $report .= "Máximo historial: {$this->updateConfig['max_history']}\n\n";
        
        $report .= "📈 MÉTRICAS DE REFERENCIA POR MÉTODO\n";
        $report .= "====================================\n";
        
        foreach ($this->baselines as $type => $methods) {
            $report .= "\n🔧 $type:\n";
            foreach ($methods as $method => $thresholds) {
                $report .= "  $method:\n";
                $report .= "    Warning: {$this->formatValue($thresholds['warning'], $type)}\n";
                $report .= "    Critical: {$this->formatValue($thresholds['critical'], $type)}\n";
                $report .= "    Severe: {$this->formatValue($thresholds['severe'], $type)}\n";
            }
        }
        
        $report .= "\n📊 ESTADÍSTICAS DEL HISTORIAL\n";
        $report .= "=============================\n";
        $report .= "Total de entradas: " . count($this->metricHistory) . "\n";
        
        if (!empty($this->metricHistory)) {
            $report .= "Primera entrada: " . date('Y-m-d H:i:s', $this->metricHistory[0]['timestamp']) . "\n";
            $report .= "Última entrada: " . date('Y-m-d H:i:s', end($this->metricHistory)['timestamp']) . "\n";
        }
        
        $report .= "\n🚨 ALERTAS GENERADAS\n";
        $report .= "===================\n";
        $alertCounts = $this->getAlertCounts();
        $report .= "Total de alertas: {$alertCounts['total']}\n";
        $report .= "Advertencias: {$alertCounts['warning']}\n";
        $report .= "Críticas: {$alertCounts['critical']}\n";
        $report .= "Severas: {$alertCounts['severe']}\n";
        
        if (!empty($this->alerts)) {
            $report .= "\nÚltimas 5 alertas:\n";
            $recentAlerts = array_slice($this->alerts, -5);
            foreach ($recentAlerts as $alert) {
                $report .= "  [{$alert['severity']}] {$alert['method']} - {$alert['message']}\n";
            }
        }
        
        return $report;
    }
}
