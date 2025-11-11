<?php

declare(strict_types=1);

namespace MiIntegracionApi\ErrorHandling\Adapters;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analizador de cuellos de botella para adaptadores
 * 
 * Esta clase proporciona análisis profundo de rendimiento para identificar
 * cuellos de botella específicos en los adaptadores del sistema.
 * 
 * @package MiIntegracionApi\ErrorHandling\Adapters
 * @since 1.0.0
 */
class BottleneckAnalyzer
{
    /**
     * Resultados del benchmarking
     * 
     * @var array
     */
    private array $results;
    
    /**
     * Configuración de umbrales
     * 
     * @var array
     */
    private array $thresholds = [
        'time' => [
            'warning' => 0.005,  // 5ms
            'critical' => 0.01,  // 10ms
            'severe' => 0.05     // 50ms
        ],
        'memory' => [
            'warning' => 1024,   // 1KB
            'critical' => 5120,  // 5KB
            'severe' => 10240    // 10KB
        ],
        'efficiency' => [
            'warning' => 80,     // 80%
            'critical' => 60,    // 60%
            'severe' => 40       // 40%
        ],
        'stability' => [
            'warning' => 85,     // 85%
            'critical' => 70,    // 70%
            'severe' => 50       // 50%
        ]
    ];
    
    /**
     * Cuellos de botella identificados
     * 
     * @var array
     */
    private array $bottlenecks = [];
    
    /**
     * Métricas de referencia
     * 
     * @var array
     */
    private array $baselines = [];
    
    /**
     * Constructor
     * 
     * @param array $results Resultados del benchmarking
     * @param array $thresholds Umbrales personalizados
     */
    public function __construct(array $results = [], array $thresholds = [])
    {
        $this->results = $results;
        $this->thresholds = array_merge($this->thresholds, $thresholds);
        $this->analyzeBottlenecks();
    }
    
    /**
     * Analiza todos los cuellos de botella
     * 
     * @return void
     */
    private function analyzeBottlenecks(): void
    {
        $this->bottlenecks = [
            'time_bottlenecks' => $this->analyzeTimeBottlenecks(),
            'memory_bottlenecks' => $this->analyzeMemoryBottlenecks(),
            'efficiency_bottlenecks' => $this->analyzeEfficiencyBottlenecks(),
            'stability_bottlenecks' => $this->analyzeStabilityBottlenecks(),
            'scalability_bottlenecks' => $this->analyzeScalabilityBottlenecks(),
            'comparative_bottlenecks' => $this->analyzeComparativeBottlenecks()
        ];
        
        $this->establishBaselines();
    }
    
    /**
     * Analiza cuellos de botella de tiempo
     * 
     * @return array Cuellos de botella de tiempo
     */
    private function analyzeTimeBottlenecks(): array
    {
        $bottlenecks = [];
        
        foreach ($this->results as $category => $data) {
            if (is_array($data)) {
                foreach ($data as $method => $metrics) {
                    if (isset($metrics['time']['average'])) {
                        $avgTime = $metrics['time']['average'];
                        $severity = $this->getTimeSeverity($avgTime);
                        
                        if ($severity !== 'ok') {
                            $bottlenecks[] = [
                                'category' => $category,
                                'method' => $method,
                                'metric' => 'time',
                                'value' => $avgTime,
                                'severity' => $severity,
                                'threshold' => $this->thresholds['time'][$severity],
                                'description' => $this->getTimeDescription($avgTime, $severity),
                                'recommendations' => $this->getTimeRecommendations($method, $avgTime, $severity)
                            ];
                        }
                    }
                }
            }
        }
        
        return $bottlenecks;
    }
    
    /**
     * Analiza cuellos de botella de memoria
     * 
     * @return array Cuellos de botella de memoria
     */
    private function analyzeMemoryBottlenecks(): array
    {
        $bottlenecks = [];
        
        foreach ($this->results as $category => $data) {
            if (is_array($data)) {
                foreach ($data as $method => $metrics) {
                    if (isset($metrics['memory']['average'])) {
                        $avgMemory = $metrics['memory']['average'];
                        $severity = $this->getMemorySeverity($avgMemory);
                        
                        if ($severity !== 'ok') {
                            $bottlenecks[] = [
                                'category' => $category,
                                'method' => $method,
                                'metric' => 'memory',
                                'value' => $avgMemory,
                                'severity' => $severity,
                                'threshold' => $this->thresholds['memory'][$severity],
                                'description' => $this->getMemoryDescription($avgMemory, $severity),
                                'recommendations' => $this->getMemoryRecommendations($method, $avgMemory, $severity)
                            ];
                        }
                    }
                }
            }
        }
        
        return $bottlenecks;
    }
    
    /**
     * Analiza cuellos de botella de eficiencia
     * 
     * @return array Cuellos de botella de eficiencia
     */
    private function analyzeEfficiencyBottlenecks(): array
    {
        $bottlenecks = [];
        
        foreach ($this->results as $category => $data) {
            if (is_array($data)) {
                foreach ($data as $method => $metrics) {
                    $efficiency = $this->calculateEfficiency($metrics);
                    $severity = $this->getEfficiencySeverity($efficiency);
                    
                    if ($severity !== 'ok') {
                        $bottlenecks[] = [
                            'category' => $category,
                            'method' => $method,
                            'metric' => 'efficiency',
                            'value' => $efficiency,
                            'severity' => $severity,
                            'threshold' => $this->thresholds['efficiency'][$severity],
                            'description' => $this->getEfficiencyDescription($efficiency, $severity),
                            'recommendations' => $this->getEfficiencyRecommendations($method, $efficiency, $severity)
                        ];
                    }
                }
            }
        }
        
        return $bottlenecks;
    }
    
    /**
     * Analiza cuellos de botella de estabilidad
     * 
     * @return array Cuellos de botella de estabilidad
     */
    private function analyzeStabilityBottlenecks(): array
    {
        $bottlenecks = [];
        
        foreach ($this->results as $category => $data) {
            if (is_array($data)) {
                foreach ($data as $method => $metrics) {
                    if (isset($metrics['time']['std_dev']) && isset($metrics['time']['average'])) {
                        $cv = $metrics['time']['std_dev'] / $metrics['time']['average'];
                        $stability = 100 - ($cv * 100);
                        $severity = $this->getStabilitySeverity($stability);
                        
                        if ($severity !== 'ok') {
                            $bottlenecks[] = [
                                'category' => $category,
                                'method' => $method,
                                'metric' => 'stability',
                                'value' => $stability,
                                'severity' => $severity,
                                'threshold' => $this->thresholds['stability'][$severity],
                                'description' => $this->getStabilityDescription($stability, $severity),
                                'recommendations' => $this->getStabilityRecommendations($method, $stability, $severity)
                            ];
                        }
                    }
                }
            }
        }
        
        return $bottlenecks;
    }
    
    /**
     * Analiza cuellos de botella de escalabilidad
     * 
     * @return array Cuellos de botella de escalabilidad
     */
    private function analyzeScalabilityBottlenecks(): array
    {
        $bottlenecks = [];
        
        if (isset($this->results['DataSizes'])) {
            $sizes = ['small', 'medium', 'large', 'complex'];
            $timeGrowth = [];
            $memoryGrowth = [];
            
            foreach ($sizes as $size) {
                if (isset($this->results['DataSizes'][$size])) {
                    $data = $this->results['DataSizes'][$size];
                    foreach ($data as $method => $metrics) {
                        if (isset($metrics['time']['average'])) {
                            $timeGrowth[$method][] = $metrics['time']['average'];
                        }
                        if (isset($metrics['memory']['average'])) {
                            $memoryGrowth[$method][] = $metrics['memory']['average'];
                        }
                    }
                }
            }
            
            foreach ($timeGrowth as $method => $times) {
                if (count($times) >= 2) {
                    $growthRate = $this->calculateGrowthRate($times);
                    if ($growthRate > 2.0) { // Crecimiento exponencial
                        $bottlenecks[] = [
                            'category' => 'DataSizes',
                            'method' => $method,
                            'metric' => 'scalability_time',
                            'value' => $growthRate,
                            'severity' => 'critical',
                            'threshold' => 2.0,
                            'description' => "Crecimiento exponencial de tiempo: {$growthRate}x",
                            'recommendations' => $this->getScalabilityRecommendations($method, 'time', $growthRate)
                        ];
                    }
                }
            }
            
            foreach ($memoryGrowth as $method => $memories) {
                if (count($memories) >= 2) {
                    $growthRate = $this->calculateGrowthRate($memories);
                    if ($growthRate > 1.5) { // Crecimiento de memoria
                        $bottlenecks[] = [
                            'category' => 'DataSizes',
                            'method' => $method,
                            'metric' => 'scalability_memory',
                            'value' => $growthRate,
                            'severity' => 'warning',
                            'threshold' => 1.5,
                            'description' => "Crecimiento de memoria: {$growthRate}x",
                            'recommendations' => $this->getScalabilityRecommendations($method, 'memory', $growthRate)
                        ];
                    }
                }
            }
        }
        
        return $bottlenecks;
    }
    
    /**
     * Analiza cuellos de botella comparativos
     * 
     * @return array Cuellos de botella comparativos
     */
    private function analyzeComparativeBottlenecks(): array
    {
        $bottlenecks = [];
        
        // Comparar métodos similares entre adaptadores
        $methodGroups = [
            'toArray' => ['WordPressAdapter', 'SyncResponse'],
            'toJson' => ['WordPressAdapter', 'SyncResponse'],
            'toWpError' => ['WordPressAdapter', 'SyncResponse'],
            'toWpRestResponse' => ['WordPressAdapter', 'SyncResponse']
        ];
        
        foreach ($methodGroups as $methodName => $adapters) {
            $times = [];
            foreach ($adapters as $adapter) {
                if (isset($this->results[$adapter][$methodName]['time']['average'])) {
                    $times[$adapter] = $this->results[$adapter][$methodName]['time']['average'];
                }
            }
            
            if (count($times) >= 2) {
                $maxTime = max($times);
                $minTime = min($times);
                $ratio = $maxTime / $minTime;
                
                if ($ratio > 3.0) { // Diferencia significativa
                    $slowest = array_search($maxTime, $times);
                    $fastest = array_search($minTime, $times);
                    
                    $bottlenecks[] = [
                        'category' => 'Comparative',
                        'method' => $methodName,
                        'metric' => 'performance_ratio',
                        'value' => $ratio,
                        'severity' => 'warning',
                        'threshold' => 3.0,
                        'description' => "$slowest es {$ratio}x más lento que $fastest",
                        'recommendations' => $this->getComparativeRecommendations($methodName, $slowest, $fastest, $ratio)
                    ];
                }
            }
        }
        
        return $bottlenecks;
    }
    
    /**
     * Establece métricas de referencia
     * 
     * @return void
     */
    private function establishBaselines(): void
    {
        $allTimes = [];
        $allMemory = [];
        $allEfficiency = [];
        $categoryMetrics = [];
        
        foreach ($this->results as $category => $data) {
            if (is_array($data)) {
                // Inicializar métricas de categoría si no existen
                if (!isset($categoryMetrics[$category])) {
                    $categoryMetrics[$category] = [
                        'times' => [],
                        'memory' => [],
                        'efficiency' => [],
                        'methods' => []
                    ];
                }
                
                foreach ($data as $method => $metrics) {
                    $methodName = $this->getMethodDisplayName($method);
                    
                    // Inicializar métricas del método si no existen
                    if (!isset($categoryMetrics[$category]['methods'][$methodName])) {
                        $categoryMetrics[$category]['methods'][$methodName] = [
                            'times' => [],
                            'memory' => [],
                            'efficiency' => 0,
                            'count' => 0
                        ];
                    }
                    
                    if (isset($metrics['time']['average'])) {
                        $time = $metrics['time']['average'];
                        $allTimes[] = $time;
                        $categoryMetrics[$category]['times'][] = $time;
                        $categoryMetrics[$category]['methods'][$methodName]['times'][] = $time;
                    }
                    
                    if (isset($metrics['memory']['average'])) {
                        $memory = $metrics['memory']['average'];
                        $allMemory[] = $memory;
                        $categoryMetrics[$category]['memory'][] = $memory;
                        $categoryMetrics[$category]['methods'][$methodName]['memory'][] = $memory;
                    }
                    
                    $efficiency = $this->calculateEfficiency($metrics);
                    if ($efficiency > 0) {
                        $allEfficiency[] = $efficiency;
                        $categoryMetrics[$category]['efficiency'][] = $efficiency;
                        // Promedio de eficiencia por método
                        $currentMethod = &$categoryMetrics[$category]['methods'][$methodName];
                        $currentMethod['efficiency'] = 
                            ($currentMethod['efficiency'] * $currentMethod['count'] + $efficiency) / 
                            ($currentMethod['count'] + 1);
                        $currentMethod['count']++;
                    }
                }
            }
        }
        
        $this->baselines = [
            'time' => [
                'average' => !empty($allTimes) ? array_sum($allTimes) / count($allTimes) : 0,
                'median' => !empty($allTimes) ? $this->calculateMedian($allTimes) : 0,
                'percentile_90' => !empty($allTimes) ? $this->calculatePercentile90($allTimes) : 0
            ],
            'memory' => [
                'average' => !empty($allMemory) ? array_sum($allMemory) / count($allMemory) : 0,
                'median' => !empty($allMemory) ? $this->calculateMedian($allMemory) : 0,
                'percentile_90' => !empty($allMemory) ? $this->calculatePercentile90($allMemory) : 0
            ],
            'efficiency' => [
                'average' => !empty($allEfficiency) ? array_sum($allEfficiency) / count($allEfficiency) : 0,
                'median' => !empty($allEfficiency) ? $this->calculateMedian($allEfficiency) : 0,
                'percentile_90' => !empty($allEfficiency) ? $this->calculatePercentile90($allEfficiency) : 0
            ]
        ];
    }
    
    /**
     * Obtiene la severidad del tiempo
     * 
     * @param float $time Tiempo en segundos
     * @return string Severidad
     */
    private function getTimeSeverity(float $time): string
    {
        if ($time >= $this->thresholds['time']['severe']) {
            return 'severe';
        } elseif ($time >= $this->thresholds['time']['critical']) {
            return 'critical';
        } elseif ($time >= $this->thresholds['time']['warning']) {
            return 'warning';
        }
        return 'ok';
    }
    
    /**
     * Obtiene la severidad de la memoria
     * 
     * @param float $memory Memoria en bytes
     * @return string Severidad
     */
    private function getMemorySeverity(float $memory): string
    {
        if ($memory >= $this->thresholds['memory']['severe']) {
            return 'severe';
        } elseif ($memory >= $this->thresholds['memory']['critical']) {
            return 'critical';
        } elseif ($memory >= $this->thresholds['memory']['warning']) {
            return 'warning';
        }
        return 'ok';
    }
    
    /**
     * Obtiene la severidad de la eficiencia
     * 
     * @param float $efficiency Eficiencia en porcentaje
     * @return string Severidad
     */
    private function getEfficiencySeverity(float $efficiency): string
    {
        if ($efficiency <= $this->thresholds['efficiency']['severe']) {
            return 'severe';
        } elseif ($efficiency <= $this->thresholds['efficiency']['critical']) {
            return 'critical';
        } elseif ($efficiency <= $this->thresholds['efficiency']['warning']) {
            return 'warning';
        }
        return 'ok';
    }
    
    /**
     * Obtiene la severidad de la estabilidad
     * 
     * @param float $stability Estabilidad en porcentaje
     * @return string Severidad
     */
    private function getStabilitySeverity(float $stability): string
    {
        if ($stability <= $this->thresholds['stability']['severe']) {
            return 'severe';
        } elseif ($stability <= $this->thresholds['stability']['critical']) {
            return 'critical';
        } elseif ($stability <= $this->thresholds['stability']['warning']) {
            return 'warning';
        }
        return 'ok';
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
        
        $timeScore = max(0, 100 - ($metrics['time']['average'] * 10000));
        $memoryScore = max(0, 100 - ($metrics['memory']['average'] / 1024));
        
        return ($timeScore + $memoryScore) / 2;
    }
    
    /**
     * Calcula la tasa de crecimiento
     * 
     * @param array $values Valores ordenados
     * @return float Tasa de crecimiento
     */
    private function calculateGrowthRate(array $values): float
    {
        if (count($values) < 2) {
            return 1.0;
        }
        
        $first = $values[0];
        $last = end($values);
        
        if ($first == 0) {
            return 1.0;
        }
        
        return $last / $first;
    }
    
    /**
     * Calcula la mediana
     * 
     * @param array $values Valores
     * @return float Mediana
     */
    private function calculateMedian(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = floor($count / 2);
        
        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }
        
        return $values[$middle];
    }
    
    /**
     * Calcula el percentil 90
     * 
     * @param array $values Valores
     * @return float Valor del percentil 90
     */
    private function calculatePercentile90(array $values): float
    {
        sort($values);
        $count = count($values);
        $index = 0.9 * ($count - 1);
        
        if (floor($index) == $index) {
            return $values[$index];
        }
        
        $lower = $values[floor($index)];
        $upper = $values[ceil($index)];
        $fraction = $index - floor($index);
        
        return $lower + ($upper - $lower) * $fraction;
    }
    
    /**
     * Obtiene descripción del tiempo
     * 
     * @param float $time Tiempo en segundos
     * @param string $severity Severidad
     * @return string Descripción
     */
    private function getTimeDescription(float $time, string $severity): string
    {
        $formattedTime = $this->formatTime($time);
        
        return match ($severity) {
            'severe' => "Tiempo extremadamente lento: $formattedTime",
            'critical' => "Tiempo crítico: $formattedTime",
            'warning' => "Tiempo lento: $formattedTime",
            default => "Tiempo normal: $formattedTime",
        };
    }
    
    /**
     * Obtiene descripción de la memoria
     * 
     * @param float $memory Memoria en bytes
     * @param string $severity Severidad
     * @return string Descripción
     */
    private function getMemoryDescription(float $memory, string $severity): string
    {
        $formattedMemory = $this->formatMemory($memory);
        
        return match ($severity) {
            'severe' => "Uso excesivo de memoria: $formattedMemory",
            'critical' => "Uso alto de memoria: $formattedMemory",
            'warning' => "Uso moderado de memoria: $formattedMemory",
            default => "Uso normal de memoria: $formattedMemory",
        };
    }
    
    /**
     * Obtiene descripción de la eficiencia
     * 
     * @param float $efficiency Eficiencia en porcentaje
     * @param string $severity Severidad
     * @return string Descripción
     */
    private function getEfficiencyDescription(float $efficiency, string $severity): string
    {
        return match ($severity) {
            'severe' => "Eficiencia muy baja: $efficiency%",
            'critical' => "Eficiencia baja: $efficiency%",
            'warning' => "Eficiencia moderada: $efficiency%",
            default => "Eficiencia normal: $efficiency%",
        };
    }
    
    /**
     * Obtiene descripción de la estabilidad
     * 
     * @param float $stability Estabilidad en porcentaje
     * @param string $severity Severidad
     * @return string Descripción
     */
    private function getStabilityDescription(float $stability, string $severity): string
    {
        return match ($severity) {
            'severe' => "Estabilidad muy baja: $stability%",
            'critical' => "Estabilidad baja: $stability%",
            'warning' => "Estabilidad moderada: $stability%",
            default => "Estabilidad normal: $stability%",
        };
    }
    
    /**
     * Obtiene recomendaciones de tiempo
     * 
     * @param string $method Método
     * @param float $time Tiempo en segundos
     * @param string $severity Severidad
     * @return array Recomendaciones
     */
    private function getTimeRecommendations(string $method, float $time, string $severity): array
    {
        $recommendations = [];
        
        // Recomendaciones basadas en el tiempo específico
        if ($time > 1.0) {
            $recommendations[] = "Tiempo extremadamente alto (>1s) - Revisar implementación completa";
        } elseif ($time > 0.1) {
            $recommendations[] = "Tiempo alto (>100ms) - Considerar optimizaciones agresivas";
        } elseif ($time > 0.01) {
            $recommendations[] = "Tiempo moderado (>10ms) - Aplicar optimizaciones básicas";
        }
        
        // Recomendaciones específicas por tipo de método
        if (str_contains($method, 'toJson')) {
            $recommendations[] = "Optimizar serialización JSON";
            if ($time > 0.05) {
                $recommendations[] = "Considerar compresión de datos JSON";
                $recommendations[] = "Evaluar uso de json_encode con flags optimizados";
            }
        }
        
        if (str_contains($method, 'toWpRestResponse')) {
            $recommendations[] = "Optimizar creación de objetos WordPress";
            if ($time > 0.05) {
                $recommendations[] = "Considerar caching de respuestas REST";
                $recommendations[] = "Revisar creación de objetos WP_REST_Response";
            }
        }
        
        if (str_contains($method, 'toArray')) {
            $recommendations[] = "Optimizar conversión a array";
            if ($time > 0.05) {
                $recommendations[] = "Considerar lazy loading de propiedades";
                $recommendations[] = "Evaluar uso de array_map vs foreach";
            }
        }
        
        // Recomendaciones basadas en severidad
        if ($severity === 'severe') {
            $recommendations[] = "Revisar algoritmo de implementación";
            $recommendations[] = "Considerar refactorización completa";
        }
        
        return $recommendations;
    }
    
    /**
     * Obtiene recomendaciones de memoria
     * 
     * @param string $method Método
     * @param float $memory Memoria
     * @param string $severity Severidad
     * @return array Recomendaciones
     */
    private function getMemoryRecommendations(string $method, float $memory, string $severity): array
    {
        $recommendations = [];
        
        // Recomendaciones basadas en el uso de memoria específico
        if ($memory > 10 * 1024 * 1024) { // > 10MB
            $recommendations[] = "Uso excesivo de memoria (>10MB) - Revisar gestión de memoria";
            $recommendations[] = "Considerar implementar paginación o streaming";
        } elseif ($memory > 1024 * 1024) { // > 1MB
            $recommendations[] = "Uso alto de memoria (>1MB) - Optimizar estructuras de datos";
            $recommendations[] = "Considerar liberación temprana de objetos";
        } elseif ($memory > 100 * 1024) { // > 100KB
            $recommendations[] = "Uso moderado de memoria (>100KB) - Aplicar optimizaciones básicas";
        }
        
        // Recomendaciones generales de memoria
        $recommendations[] = "Implementar liberación de memoria";
        $recommendations[] = "Considerar pool de objetos";
        
        // Recomendaciones específicas por tipo de método
        if (str_contains($method, 'toJson')) {
            $recommendations[] = "Optimizar serialización JSON";
            if ($memory > 1024 * 1024) {
                $recommendations[] = "Considerar streaming de datos JSON";
                $recommendations[] = "Evaluar compresión de datos antes de serializar";
            }
        }
        
        if (str_contains($method, 'toWpRestResponse')) {
            $recommendations[] = "Optimizar creación de objetos WordPress";
            if ($memory > 1024 * 1024) {
                $recommendations[] = "Considerar reutilización de objetos WP_REST_Response";
                $recommendations[] = "Evaluar lazy loading de propiedades";
            }
        }
        
        if (str_contains($method, 'toArray')) {
            $recommendations[] = "Optimizar conversión a array";
            if ($memory > 1024 * 1024) {
                $recommendations[] = "Considerar arrays asociativos vs objetos";
                $recommendations[] = "Evaluar serialización parcial de datos";
            }
        }
        
        // Recomendaciones basadas en severidad
        if ($severity === 'severe') {
            $recommendations[] = "Revisar gestión de memoria";
            $recommendations[] = "Considerar garbage collection manual";
            $recommendations[] = "Evaluar uso de weak references";
        }
        
        return $recommendations;
    }
    
    /**
     * Obtiene recomendaciones de eficiencia
     * 
     * @param string $method Método
     * @param float $efficiency Eficiencia
     * @param string $severity Severidad
     * @return array Recomendaciones
     */
    private function getEfficiencyRecommendations(string $method, float $efficiency, string $severity): array
    {
        $recommendations = [];
        
        // Recomendaciones basadas en el nivel de eficiencia específico
        if ($efficiency < 20) {
            $recommendations[] = "Eficiencia muy baja (<20%) - Revisar implementación completa";
            $recommendations[] = "Considerar refactorización total del algoritmo";
        } elseif ($efficiency < 50) {
            $recommendations[] = "Eficiencia baja (<50%) - Optimizar operaciones principales";
            $recommendations[] = "Reducir complejidad computacional";
        } elseif ($efficiency < 80) {
            $recommendations[] = "Eficiencia moderada (<80%) - Aplicar optimizaciones básicas";
        }
        
        // Recomendaciones generales de eficiencia
        $recommendations[] = "Optimizar algoritmo de implementación";
        $recommendations[] = "Reducir operaciones redundantes";
        
        // Recomendaciones específicas por tipo de método
        if (str_contains($method, 'toJson')) {
            $recommendations[] = "Optimizar serialización JSON";
            if ($efficiency < 50) {
                $recommendations[] = "Considerar streaming de datos JSON";
                $recommendations[] = "Evaluar uso de json_encode con flags optimizados";
                $recommendations[] = "Implementar caché de objetos serializados";
            }
        }
        
        if (str_contains($method, 'toWpRestResponse')) {
            $recommendations[] = "Optimizar creación de objetos WordPress";
            if ($efficiency < 50) {
                $recommendations[] = "Considerar reutilización de objetos WP_REST_Response";
                $recommendations[] = "Implementar factory pattern para objetos";
                $recommendations[] = "Evaluar lazy loading de propiedades";
            }
        }
        
        if (str_contains($method, 'toArray')) {
            $recommendations[] = "Optimizar conversión a array";
            if ($efficiency < 50) {
                $recommendations[] = "Considerar arrays asociativos vs objetos";
                $recommendations[] = "Evaluar uso de array_map vs foreach";
                $recommendations[] = "Implementar conversión incremental";
            }
        }
        
        // Recomendaciones basadas en severidad
        if ($severity === 'severe') {
            $recommendations[] = "Considerar refactorización completa";
            $recommendations[] = "Implementar algoritmos más eficientes";
            $recommendations[] = "Evaluar uso de estructuras de datos alternativas";
        }
        
        return $recommendations;
    }
    
    /**
     * Obtiene recomendaciones de estabilidad
     * 
     * @param string $method Nombre completo del método (incluyendo la clase)
     * @param float $stability Porcentaje de estabilidad (0-100%)
     * @param string $severity Nivel de severidad (ok, low, medium, high, critical)
     * @return array Recomendaciones específicas para mejorar la estabilidad
     */
    private function getStabilityRecommendations(string $method, float $stability, string $severity): array
    {
        $recommendations = [];
        $methodName = $this->getMethodDisplayName($method);
        
        // Recomendaciones generales basadas en el método
        if (str_contains($method, '::')) {
            list($className, $methodName) = explode('::', $method, 2);
            $recommendations[] = "Revisar la implementación de $methodName en $className para reducir la variabilidad";
            
            // Recomendaciones específicas por tipo de método
            if (str_starts_with($methodName, 'get') || str_starts_with($methodName, 'find')) {
                $recommendations[] = "Considerar implementar caché para $methodName si los datos no cambian frecuentemente";
                $recommendations[] = "Optimizar consultas en $methodName para reducir tiempos de respuesta variables";
            } elseif (str_starts_with($methodName, 'save') || str_starts_with($methodName, 'update')) {
                $recommendations[] = "Revisar transacciones en $methodName para asegurar consistencia";
                $recommendations[] = "Evaluar bloqueos y condiciones de carrera en $methodName";
            } elseif (str_starts_with($methodName, 'process') || str_starts_with($methodName, 'handle')) {
                $recommendations[] = "Implementar manejo de errores robusto en $methodName";
                $recommendations[] = "Considerar implementar un patrón de reintento con retroceso exponencial en $methodName";
            }
        } else {
            $recommendations[] = "Reducir variabilidad en el rendimiento de $method";
        }
        
        // Recomendaciones basadas en la severidad
        if ($severity === 'high' || $severity === 'critical') {
            $recommendations[] = "Revisar dependencias externas utilizadas por $methodName";
            $recommendations[] = "Implementar timeouts y lógica de reintento en $methodName";
            $recommendations[] = "Considerar implementar circuit breakers para operaciones inestables en $methodName";
        } elseif ($severity === 'medium') {
            $recommendations[] = "Monitorear $methodName para identificar patrones de inestabilidad";
            $recommendations[] = "Revisar logs de $methodName en busca de errores intermitentes";
        }
        
        // Recomendaciones basadas en el nivel de estabilidad
        if ($stability < 50) {
            $recommendations[] = "Realizar un análisis profundo de rendimiento en $methodName";
            $recommendations[] = "Considerar reescribir $methodName para mejorar su estabilidad";
        }
        
        // Recomendación final común
        $recommendations[] = "Documentar el comportamiento esperado y los casos límite de $methodName";
        
        return array_unique($recommendations);
    }
    
    /**
     * Obtiene un nombre de método legible
     * 
     * @param string $method Nombre completo del método
     * @return string Nombre de método formateado
     */
    private function getMethodDisplayName(string $method): string
    {
        if (str_contains($method, '::')) {
            list($class, $method) = explode('::', $method, 2);
            $shortClass = substr(strrchr($class, '\\'), 1);
            return "$shortClass::$method";
        }
        return $method;
    }
    
    /**
     * Obtiene recomendaciones de escalabilidad
     * 
     * @param string $method Método
     * @param string $type Tipo de escalabilidad
     * @param float $growthRate Tasa de crecimiento
     * @return array Recomendaciones
     */
    private function getScalabilityRecommendations(string $method, string $type, float $growthRate): array
    {
        $recommendations = [];
        
        $methodName = $this->getMethodDisplayName($method);
        $recommendations[] = "Revisar la implementación de $methodName para mejorar la escalabilidad";
        $recommendations[] = "Implementar algoritmos O(n) o mejor en $methodName";
        $recommendations[] = "Considerar paginación en $methodName para conjuntos de datos grandes";
        
        if ($type === 'time') {
            $recommendations[] = "Optimizar complejidad temporal en $methodName";
            $recommendations[] = "Implementar caching inteligente en $methodName";
        } else {
            $recommendations[] = "Optimizar uso de memoria en $methodName";
            $recommendations[] = "Implementar streaming de datos en $methodName";
        }
        
        // Recomendaciones basadas en la tasa de crecimiento
        if ($growthRate > 1.5) {
            $recommendations[] = "Revisar $methodName: La alta tasa de crecimiento (" . number_format($growthRate, 2) . ") sugiere problemas de escalabilidad severos";
        }
        
        return $recommendations;
    }
    
    /**
     * Obtiene recomendaciones comparativas
     * 
     * @param string $method Método
     * @param string $slowest Adaptador más lento
     * @param string $fastest Adaptador más rápido
     * @param float $ratio Ratio de rendimiento
     * @return array Recomendaciones
     */
    private function getComparativeRecommendations(string $method, string $slowest, string $fastest, float $ratio): array
    {
        $recommendations = [];
        $methodName = $this->getMethodDisplayName($method);
        
        $recommendations[] = "En $methodName, analizar implementación de $slowest (más lento)";
        $recommendations[] = "Aplicar optimizaciones de $fastest (más rápido) a la implementación de $slowest en $methodName";
        $recommendations[] = "En $methodName, considerar unificar implementaciones para mejorar el rendimiento";
        
        if ($ratio > 2.0) {
            $recommendations[] = "Atención en $methodName: La diferencia de rendimiento es significativa (x" . number_format($ratio, 1) . " veces más lento que $fastest)";
        }
        
        return $recommendations;
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
     * Obtiene todos los cuellos de botella
     * 
     * @return array Cuellos de botella
     */
    public function getBottlenecks(): array
    {
        return $this->bottlenecks;
    }
    
    /**
     * Obtiene cuellos de botella por severidad
     * 
     * @param string $severity Severidad
     * @return array Cuellos de botella
     */
    public function getBottlenecksBySeverity(string $severity): array
    {
        $filtered = [];
        
        foreach ($this->bottlenecks as $type => $bottlenecks) {
            foreach ($bottlenecks as $bottleneck) {
                if ($bottleneck['severity'] === $severity) {
                    // Incluir el tipo de métrica en el resultado
                    $bottleneck['metric_type'] = $type;
                    $filtered[] = $bottleneck;
                }
            }
        }
        
        // Ordenar por tipo de métrica para mejor organización
        usort($filtered, function($a, $b) {
            return strcmp($a['metric_type'] ?? '', $b['metric_type'] ?? '');
        });
        
        return $filtered;
    }
    
    /**
     * Obtiene cuellos de botella por métrica
     * 
     * @param string $metric Métrica
     * @return array Cuellos de botella
     */
    public function getBottlenecksByMetric(string $metric): array
    {
        $filtered = [];
        
        foreach ($this->bottlenecks as $type => $bottlenecks) {
            foreach ($bottlenecks as $bottleneck) {
                if ($bottleneck['metric'] === $metric) {
                    // Incluir el tipo de métrica en el resultado
                    $bottleneck['metric_type'] = $type;
                    $filtered[] = $bottleneck;
                }
            }
        }
        
        // Ordenar por tipo de métrica y luego por severidad
        usort($filtered, function($a, $b) {
            $typeCompare = strcmp($a['metric_type'] ?? '', $b['metric_type'] ?? '');
            if ($typeCompare !== 0) {
                return $typeCompare;
            }
            return strcmp($a['severity'] ?? '', $b['severity'] ?? '');
        });
        
        return $filtered;
    }
    
    /**
     * Obtiene métricas de referencia
     * 
     * @return array Métricas de referencia
     */
    public function getBaselines(): array
    {
        return $this->baselines;
    }
    
    /**
     * Obtiene resumen de cuellos de botella
     * 
     * @return array Resumen
     */
    public function getSummary(): array
    {
        $summary = [
            'total_bottlenecks' => 0,
            'by_severity' => [
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0,
                'warning' => 0
            ],
            'by_metric' => [
                'time' => 0,
                'memory' => 0,
                'efficiency' => 0,
                'stability' => 0,
                'scalability' => 0,
                'comparative' => 0
            ],
            'by_type' => [],  // Nuevo: Estadísticas por tipo de métrica
            'by_category' => []
        ];
        
        foreach ($this->bottlenecks as $type => $bottlenecks) {
            $bottleneckCount = count($bottlenecks);
            $summary['total_bottlenecks'] += $bottleneckCount;
            
            // Inicializar contador para este tipo si no existe
            if (!isset($summary['by_type'][$type])) {
                $summary['by_type'][$type] = [
                    'total' => 0,
                    'by_severity' => [
                        'critical' => 0,
                        'high' => 0,
                        'medium' => 0,
                        'low' => 0,
                        'warning' => 0
                    ],
                    'by_metric' => [
                        'time' => 0,
                        'memory' => 0,
                        'efficiency' => 0,
                        'stability' => 0,
                        'scalability' => 0,
                        'comparative' => 0
                    ]
                ];
            }
            
            $summary['by_type'][$type]['total'] += $bottleneckCount;
            
            foreach ($bottlenecks as $bottleneck) {
                $severity = $bottleneck['severity'];
                $metric = $bottleneck['metric'];
                $category = $bottleneck['category'];
                
                // Actualizar contadores generales
                $summary['by_severity'][$severity]++;
                $summary['by_metric'][$metric]++;
                
                // Actualizar contadores por tipo
                $summary['by_type'][$type]['by_severity'][$severity]++;
                $summary['by_type'][$type]['by_metric'][$metric]++;
                
                // Actualizar contadores por categoría
                if (!isset($summary['by_category'][$category])) {
                    $summary['by_category'][$category] = 0;
                }
                $summary['by_category'][$category]++;
            }
        }
        
        return $summary;
    }
    /**
     * Genera reporte de cuellos de botella
     * 
     * @return string Reporte
     */
    public function generateReport(): string
    {
        $report = "🔍 REPORTE DE CUELLOS DE BOTELLA\n";
        $report .= "================================\n\n";
        
        $summary = $this->getSummary();
        
        $report .= "📊 RESUMEN GENERAL\n";
        $report .= "==================\n";
        $report .= "Total de cuellos de botella: {$summary['total_bottlenecks']}\n";
        $report .= "Severos: {$summary['by_severity']['severe']}\n";
        $report .= "Críticos: {$summary['by_severity']['critical']}\n";
        $report .= "Advertencias: {$summary['by_severity']['warning']}\n\n";
        
        foreach ($this->bottlenecks as $type => $bottlenecks) {
            if (!empty($bottlenecks)) {
                $report .= "🔧 " . strtoupper(str_replace('_', ' ', $type)) . "\n";
                $report .= str_repeat("=", strlen($type) + 3) . "\n";
                
                foreach ($bottlenecks as $bottleneck) {
                    $report .= "\n📋 {$bottleneck['method']} ({$bottleneck['category']})\n";
                    $report .= "  Severidad: {$bottleneck['severity']}\n";
                    $report .= "  Descripción: {$bottleneck['description']}\n";
                    $report .= "  Recomendaciones:\n";
                    foreach ($bottleneck['recommendations'] as $rec) {
                        $report .= "    • $rec\n";
                    }
                }
                $report .= "\n";
            }
        }
        
        return $report;
    }
}
