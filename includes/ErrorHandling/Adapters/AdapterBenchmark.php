<?php

declare(strict_types=1);

namespace MiIntegracionApi\ErrorHandling\Adapters;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

use MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory;
use MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface;
use Throwable;

/**
 * Clase para benchmarking de adaptadores de respuesta
 * 
 * Esta clase proporciona métodos para medir el rendimiento de los adaptadores
 * de respuesta del sistema, incluyendo tiempo de ejecución, uso de memoria
 * y escalabilidad con diferentes tamaños de datos.
 * 
 * @package MiIntegracionApi\ErrorHandling\Adapters
 * @since 1.0.0
 */
class AdapterBenchmark
{
    /**
     * Resultados del benchmarking
     * 
     * @var array
     */
    private array $results = [];
    
    /**
     * Métricas de referencia de rendimiento
     * 
     * @var PerformanceBaseline|null
     */
    private ?PerformanceBaseline $performanceBaseline = null;
    
    /**
     * Número de iteraciones por defecto
     * 
     * @var int
     */
    private int $defaultIterations;
    
    /**
     * Datos de prueba generados
     * 
     * @var array
     */
    private array $testData = [];
    
    /**
     * Configuración del benchmarking
     * 
     * @var array
     */
    private array $config = [
        'warmup_iterations' => 10,
        'precision' => 6, // Decimales de precisión para microtime
        'memory_units' => 'bytes',
        'time_units' => 'seconds'
    ];
    
    /**
     * Constructor
     * 
     * @param int $defaultIterations Número de iteraciones por defecto
     */
    public function __construct(int $defaultIterations = 1000)
    {
        $this->defaultIterations = $defaultIterations;
        $this->initializeTestData();
    }
    
    /**
     * Inicializa los datos de prueba
     * 
     * @return void
     */
    private function initializeTestData(): void
    {
        $this->testData = [
            'small' => $this->createSmallData(),
            'medium' => $this->createMediumData(),
            'large' => $this->createLargeData(),
            'complex' => $this->createComplexData(),
            'error' => $this->createErrorData()
        ];
    }
    
    /**
     * Crea datos de prueba pequeños (1-10 elementos)
     * 
     * @return array
     */
    private function createSmallData(): array
    {
        return [
            'config' => [
                'detection_interval' => 300,
                'auto_detect' => true,
                'notifications' => false
            ],
            'user_id' => 1,
            'timestamp' => time()
        ];
    }
    
    /**
     * Crea datos de prueba medianos (100-1000 elementos)
     * 
     * @return array
     */
    private function createMediumData(): array
    {
        $products = [];
        for ($i = 1; $i <= 500; $i++) {
            $products[] = [
                'id' => $i,
                'name' => "Producto $i",
                'price' => rand(100, 10000) / 100,
                'stock' => rand(0, 100),
                'category' => "Categoría " . ($i % 10),
                'active' => $i % 3 !== 0
            ];
        }
        
        return [
            'products' => $products,
            'total' => count($products),
            'filters' => [
                'category' => 'all',
                'active_only' => true,
                'min_price' => 0
            ]
        ];
    }
    
    /**
     * Crea datos de prueba grandes (10,000+ elementos)
     * 
     * @return array
     */
    private function createLargeData(): array
    {
        $orders = [];
        for ($i = 1; $i <= 10000; $i++) {
            $orders[] = [
                'id' => $i,
                'customer_id' => rand(1, 1000),
                'total' => rand(1000, 50000) / 100,
                'status' => ['pending', 'processing', 'completed', 'cancelled'][rand(0, 3)],
                'items' => array_fill(0, rand(1, 10), [
                    'product_id' => rand(1, 5000),
                    'quantity' => rand(1, 5),
                    'price' => rand(100, 1000) / 100
                ]),
                'created_at' => date('Y-m-d H:i:s', time() - rand(0, 86400 * 30)),
                'metadata' => [
                    'source' => 'web',
                    'payment_method' => 'credit_card',
                    'shipping_method' => 'standard'
                ]
            ];
        }
        
        return [
            'orders' => $orders,
            'total' => count($orders),
            'summary' => [
                'total_value' => array_sum(array_column($orders, 'total')),
                'status_counts' => array_count_values(array_column($orders, 'status')),
                'date_range' => [
                    'from' => min(array_column($orders, 'created_at')),
                    'to' => max(array_column($orders, 'created_at'))
                ]
            ]
        ];
    }
    
    /**
     * Crea datos de prueba complejos con estructuras anidadas
     * 
     * @return array
     */
    private function createComplexData(): array
    {
        return [
            'sync_session' => [
                'id' => uniqid('sync_', true),
                'started_at' => date('Y-m-d H:i:s'),
                'entity' => 'products',
                'direction' => 'import',
                'filters' => [
                    'date_from' => '2024-01-01',
                    'date_to' => '2024-12-31',
                    'categories' => [1, 2, 3, 4, 5],
                    'status' => 'active'
                ],
                'progress' => [
                    'total_items' => 5000,
                    'processed' => 2500,
                    'successful' => 2400,
                    'failed' => 100,
                    'percentage' => 50.0
                ],
                'errors' => [
                    [
                        'code' => 'API_TIMEOUT',
                        'message' => 'Timeout en llamada a API',
                        'count' => 5,
                        'last_occurrence' => date('Y-m-d H:i:s', time() - 300)
                    ],
                    [
                        'code' => 'INVALID_DATA',
                        'message' => 'Datos inválidos recibidos',
                        'count' => 2,
                        'last_occurrence' => date('Y-m-d H:i:s', time() - 600)
                    ]
                ],
                'metadata' => [
                    'php_version' => PHP_VERSION,
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time'),
                    'user_agent' => 'MiIntegracionApi/1.0.0',
                    'request_id' => uniqid('req_', true)
                ]
            ]
        ];
    }
    
    /**
     * Crea datos de prueba para respuestas de error
     * 
     * @return array
     */
    private function createErrorData(): array
    {
        return [
            'error' => [
                'code' => 'SYNC_FAILED',
                'message' => 'Error en sincronización de productos',
                'details' => [
                    'failed_items' => 15,
                    'successful_items' => 485,
                    'error_rate' => 3.0
                ],
                'context' => [
                    'endpoint' => 'GetArticulosWS',
                    'timestamp' => time(),
                    'user_id' => 1,
                    'session_id' => uniqid('sess_', true)
                ],
                'retryable' => true,
                'retry_delay' => 300,
                'suggestions' => [
                    'Verificar conexión a la API',
                    'Reintentar en 5 minutos',
                    'Contactar soporte si persiste'
                ]
            ]
        ];
    }

    /**
     * Ejecuta benchmarking completo de todos los adaptadores
     *
     * @param int|null $iterations Número de iteraciones
     * @return array Resultados del benchmarking
     */
    public function benchmarkAllAdapters(int $iterations = null): array
    {
        $iterations = $iterations ?? $this->defaultIterations;
        $this->results = [];
        
        // Benchmark de WordPressAdapter
        $this->results['WordPressAdapter'] = $this->benchmarkWordPressAdapter($iterations);
        
        // Benchmark de SyncResponse
        $this->results['SyncResponse'] = $this->benchmarkSyncResponse($iterations);
        
        // Benchmark por tamaño de datos
        $this->results['DataSizes'] = $this->benchmarkDataSizes($iterations);
        
        // Benchmark por tipo de respuesta
        $this->results['ResponseTypes'] = $this->benchmarkResponseTypes($iterations);
        
        return $this->results;
    }

    /**
     * Benchmark de métodos de WordPressAdapter
     *
     * @param int|null $iterations Número de iteraciones
     * @return array Resultados del benchmarking
     */
    public function benchmarkWordPressAdapter(int $iterations = null): array
    {
        $iterations = $iterations ?? $this->defaultIterations;
        $results = [];
        
        // Crear respuestas de prueba
        $successResponse = ResponseFactory::success($this->testData['medium']);
        $errorResponse = ResponseFactory::error('Error de prueba', 500, ['test' => true]);
        
        // Métodos a benchmarkear
        $methods = [
            'toWpError' => fn($response) => WordPressAdapter::toWpError($response),
            'toWpRestResponse' => fn($response) => WordPressAdapter::toWpRestResponse($response),
            'toRestApiFormat' => fn($response) => WordPressAdapter::toRestApiFormat($response),
            'toAjaxFormat' => fn($response) => WordPressAdapter::toAjaxFormat($response),
            'toCliFormat' => fn($response) => WordPressAdapter::toCliFormat($response)
        ];
        
        foreach ($methods as $methodName => $method) {
            $results[$methodName] = [
                'success' => $this->measurePerformance($method, $successResponse, $iterations),
                'error' => $this->measurePerformance($method, $errorResponse, $iterations)
            ];
        }
        
        return $results;
    }

    /**
     * Benchmark de métodos de SyncResponse
     *
     * @param int|null $iterations Número de iteraciones
     * @return array Resultados del benchmarking
     */
    public function benchmarkSyncResponse(int $iterations = null): array
    {
        $iterations = $iterations ?? $this->defaultIterations;
        $results = [];
        
        // Crear respuestas de prueba
        $successResponse = ResponseFactory::success($this->testData['medium']);
        $errorResponse = ResponseFactory::error('Error de prueba', 500, ['test' => true]);
        
        // Métodos a benchmarkear
        $methods = [
            'toArray' => fn($response) => $response->toArray(),
            'toJson' => fn($response) => $response->toJson(),
            'toWpError' => fn($response) => $response->toWpError(),
            'toWpRestResponse' => fn($response) => $response->toWpRestResponse()
        ];
        
        foreach ($methods as $methodName => $method) {
            $results[$methodName] = [
                'success' => $this->measurePerformance($method, $successResponse, $iterations),
                'error' => $this->measurePerformance($method, $errorResponse, $iterations)
            ];
        }
        
        return $results;
    }

    /**
     * Benchmark con diferentes tamaños de datos
     *
     * @param int|null $iterations Número de iteraciones
     * @return array Resultados del benchmarking
     */
    public function benchmarkDataSizes(int $iterations = null): array
    {
        $iterations = $iterations ?? $this->defaultIterations;
        $results = [];
        
        $sizes = ['small', 'medium', 'large', 'complex'];
        
        foreach ($sizes as $size) {
            $response = ResponseFactory::success($this->testData[$size]);
            
            $results[$size] = [
                'toArray' => $this->measurePerformance(fn($r) => $r->toArray(), $response, $iterations),
                'toJson' => $this->measurePerformance(fn($r) => $r->toJson(), $response, $iterations),
                'toWpRestResponse' => $this->measurePerformance(fn($r) => WordPressAdapter::toWpRestResponse($r), $response, $iterations),
                'toAjaxFormat' => $this->measurePerformance(fn($r) => WordPressAdapter::toAjaxFormat($r), $response, $iterations)
            ];
        }
        
        return $results;
    }

    /**
     * Benchmark con diferentes tipos de respuesta
     *
     * @param int|null $iterations Número de iteraciones
     * @return array Resultados del benchmarking
     */
    public function benchmarkResponseTypes(int $iterations = null): array
    {
        $iterations = $iterations ?? $this->defaultIterations;
        $results = [];
        
        // Respuesta exitosa simple
        $simpleSuccess = ResponseFactory::success(['status' => 'ok']);
        
        // Respuesta exitosa con metadatos
        $metadataSuccess = ResponseFactory::success(
            ['data' => 'test'],
            'Operación exitosa',
            ['endpoint' => 'test', 'timestamp' => time(), 'user_id' => 1]
        );
        
        // Respuesta de error simple
        $simpleError = ResponseFactory::error('Error simple', 400);
        
        // Respuesta de error con contexto
        $contextError = ResponseFactory::error(
            'Error con contexto',
            500,
            ['endpoint' => 'test', 'error_code' => 'test_error', 'timestamp' => time()]
        );
        
        $responseTypes = [
            'simple_success' => $simpleSuccess,
            'metadata_success' => $metadataSuccess,
            'simple_error' => $simpleError,
            'context_error' => $contextError
        ];
        
        foreach ($responseTypes as $typeName => $response) {
            $results[$typeName] = [
                'toArray' => $this->measurePerformance(fn($r) => $r->toArray(), $response, $iterations),
                'toJson' => $this->measurePerformance(fn($r) => $r->toJson(), $response, $iterations),
                'toWpError' => $this->measurePerformance(fn($r) => WordPressAdapter::toWpError($r), $response, $iterations),
                'toWpRestResponse' => $this->measurePerformance(fn($r) => WordPressAdapter::toWpRestResponse($r), $response, $iterations)
            ];
        }
        
        return $results;
    }
    
    /**
     * Mide el rendimiento de una operación
     * 
     * @param callable $operation Operación a medir
     * @param SyncResponseInterface $response Respuesta para la operación
     * @param int $iterations Número de iteraciones
     * @return array Métricas de rendimiento
     */
    private function measurePerformance(callable $operation, SyncResponseInterface $response, int $iterations): array
    {
        $times = [];
        $memory = [];
        $errors = [];
        
        // Warmup
        for ($i = 0; $i < $this->config['warmup_iterations']; $i++) {
            try {
                $operation($response);
            } catch (Throwable) {
                // Ignorar errores en warmup - solo necesitamos "calentar" el sistema
            }
        }
        
        // Medición real
        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            $memoryBefore = memory_get_usage();
            
            try {
                $operation($response);
                $endTime = microtime(true);
                $memoryAfter = memory_get_usage();
                
                $times[] = $endTime - $startTime;
                $memory[] = $memoryAfter - $memoryBefore;
            } catch (Throwable $e) {
                $endTime = microtime(true);
                $memoryAfter = memory_get_usage();
                
                // Registrar el error pero continuar con la medición
                $errors[] = [
                    'iteration' => $i,
                    'error' => $e->getMessage(),
                    'time' => $endTime - $startTime,
                    'memory' => $memoryAfter - $memoryBefore
                ];
                
                // Incluir tiempo y memoria del error en las métricas
                $times[] = $endTime - $startTime;
                $memory[] = $memoryAfter - $memoryBefore;
            }
        }
        
        $metrics = $this->calculateMetrics($times, $memory);
        
        // Agregar información de errores si los hay
        if (!empty($errors)) {
            $metrics['errors'] = [
                'count' => count($errors),
                'rate' => count($errors) / $iterations,
                'details' => $errors
            ];
        }
        
        return $metrics;
    }
    
    /**
     * Calcula métricas estadísticas
     * 
     * @param array $times Tiempos de ejecución
     * @param array $memory Uso de memoria
     * @return array Métricas calculadas
     */
    private function calculateMetrics(array $times, array $memory): array
    {
        $precision = $this->config['precision'];
        
        return [
            'time' => [
                'average' => $this->safe_round(array_sum($times) / count($times), $precision),
                'min' => $this->safe_round(min($times), $precision),
                'max' => $this->safe_round(max($times), $precision),
                'median' => $this->safe_round($this->calculateMedian($times), $precision),
                'std_dev' => $this->safe_round($this->calculateStandardDeviation($times), $precision),
                'total' => $this->safe_round(array_sum($times), $precision)
            ],
            'memory' => [
                'average' => $this->safe_round(array_sum($memory) / count($memory), 2),
                'min' => min($memory),
                'max' => max($memory),
                'median' => $this->safe_round($this->calculateMedian($memory), 2),
                'std_dev' => $this->safe_round($this->calculateStandardDeviation($memory), 2),
                'total' => array_sum($memory),
                'peak' => memory_get_peak_usage()
            ],
            'iterations' => count($times),
            'units' => [
                'time' => $this->config['time_units'],
                'memory' => $this->config['memory_units']
            ]
        ];
    }
    
    /**
     * Calcula la mediana de un array
     * 
     * @param array $values Valores para calcular la mediana
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
     * Calcula la desviación estándar
     * 
     * @param array $values Valores para calcular la desviación
     * @return float Desviación estándar
     */
    private function calculateStandardDeviation(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / count($values);
        
        return sqrt($variance);
    }
    
    /**
     * Genera un reporte de rendimiento avanzado
     * 
     * @param string $format Formato del reporte (text, html, json, csv)
     * @param bool $includeCharts Incluir gráficos ASCII
     * @param bool $includeRecommendations Incluir recomendaciones de optimización
     * @return string Reporte formateado
     */
    public function generateReport(string $format = 'text', bool $includeCharts = true, bool $includeRecommendations = true): string
    {
        if (empty($this->results)) {
            return "No hay resultados de benchmarking disponibles. Ejecute benchmarkAllAdapters() primero.\n";
        }
        
        return match ($format) {
            'html' => $this->generateHtmlReport($includeCharts, $includeRecommendations),
            'json' => $this->generateJsonReport(),
            'csv' => $this->generateCsvReport(),
            default => $this->generateTextReport($includeCharts, $includeRecommendations),
        };
    }
    
    /**
     * Genera reporte en formato texto
     * 
     * @param bool $includeCharts Incluir gráficos ASCII
     * @param bool $includeRecommendations Incluir recomendaciones
     * @return string Reporte en texto
     */
    private function generateTextReport(bool $includeCharts = true, bool $includeRecommendations = true): string
    {
        $report = "📊 REPORTE AVANZADO DE BENCHMARKING DE ADAPTADORES\n";
        $report .= "================================================\n\n";
        
        // Información del sistema
        $report .= $this->generateSystemInfo();
        
        // Resumen ejecutivo
        $report .= $this->generateExecutiveSummary();
        
        // Reporte detallado por adaptador
        $report .= $this->generateAdapterDetails();
        
        // Análisis de rendimiento
        $report .= $this->generatePerformanceAnalysis();
        
        // Gráficos ASCII (si se solicitan)
        if ($includeCharts) {
            $report .= $this->generateAsciiCharts();
        }
        
        // Recomendaciones (si se solicitan)
        if ($includeRecommendations) {
            $report .= $this->generateRecommendations();
        }
        
        // Métricas de calidad
        $report .= $this->generateQualityMetrics();
        
        return $report;
    }
    
    /**
     * Genera información del sistema
     * 
     * @return string Información del sistema
     */
    private function generateSystemInfo(): string
    {
        $info = "🖥️  INFORMACIÓN DEL SISTEMA\n";
        $info .= "==========================\n";
        $info .= "PHP Version: " . PHP_VERSION . "\n";
        $info .= "Memory Limit: " . ini_get('memory_limit') . "\n";
        $info .= "Max Execution Time: " . ini_get('max_execution_time') . "s\n";
        $info .= "Peak Memory Usage: " . $this->formatMemory(memory_get_peak_usage()) . "\n";
        $info .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
        $info .= "Iterations: " . $this->defaultIterations . "\n\n";
        
        return $info;
    }
    
    /**
     * Genera resumen ejecutivo
     * 
     * @return string Resumen ejecutivo
     */
    private function generateExecutiveSummary(): string
    {
        $summary = "📈 RESUMEN EJECUTIVO\n";
        $summary .= "===================\n";
        
        // Calcular métricas generales
        $metrics = $this->extractAllMetrics();
        $allTimes = $metrics['times'];
        $allMemory = $metrics['memory'];
        
        if (!empty($allTimes)) {
            $avgTime = array_sum($allTimes) / count($allTimes);
            $maxTime = max($allTimes);
            $minTime = min($allTimes);
            
            $summary .= "Tiempo promedio: " . $this->formatTime($avgTime) . "\n";
            $summary .= "Tiempo máximo: " . $this->formatTime($maxTime) . "\n";
            $summary .= "Tiempo mínimo: " . $this->formatTime($minTime) . "\n";
        }
        
        if (!empty($allMemory)) {
            $avgMemory = array_sum($allMemory) / count($allMemory);
            $maxMemory = max($allMemory);
            $minMemory = min($allMemory);
            
            $summary .= "Memoria promedio: " . $this->formatMemory($avgMemory) . "\n";
            $summary .= "Memoria máxima: " . $this->formatMemory($maxMemory) . "\n";
            $summary .= "Memoria mínima: " . $this->formatMemory($minMemory) . "\n";
        }
        
        $summary .= "\n";
        return $summary;
    }
    
    /**
     * Genera detalles por adaptador
     * 
     * @return string Detalles por adaptador
     */
    private function generateAdapterDetails(): string
    {
        $details = "";
        
        // WordPressAdapter
        if (isset($this->results['WordPressAdapter'])) {
            $details .= $this->formatAdapterDetails('WORDPRESS ADAPTER', '🔧', $this->results['WordPressAdapter']);
        }
        
        // SyncResponse
        if (isset($this->results['SyncResponse'])) {
            $details .= "\n" . $this->formatAdapterDetails('SYNC RESPONSE', '🔄', $this->results['SyncResponse']);
        }
        
        return $details;
    }
    
    /**
     * Genera análisis de rendimiento
     * 
     * @return string Análisis de rendimiento
     */
    private function generatePerformanceAnalysis(): string
    {
        $analysis = "\n📊 ANÁLISIS DE RENDIMIENTO\n";
        $analysis .= "==========================\n";
        
        // Análisis por tamaño de datos
        if (isset($this->results['DataSizes'])) {
            $analysis .= $this->formatPerformanceMetrics('ESCALABILIDAD POR TAMAÑO DE DATOS', '📏', $this->results['DataSizes']);
        }
        
        // Análisis por tipo de respuesta
        if (isset($this->results['ResponseTypes'])) {
            $analysis .= $this->formatPerformanceMetrics('RENDIMIENTO POR TIPO DE RESPUESTA', '🔄', $this->results['ResponseTypes']);
        }
        
        return $analysis;
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
        
        // Eficiencia basada en tiempo y memoria (menor es mejor)
        $timeScore = max(0, 100 - ($metrics['time']['average'] * 10000)); // Penalizar tiempos > 0.01 s
        $memoryScore = max(0, 100 - ($metrics['memory']['average'] / 1024)); // Penalizar memoria > 1KB
        
        return ($timeScore + $memoryScore) / 2;
    }
    
    /**
     * Genera gráficos ASCII
     * 
     * @return string Gráficos ASCII
     */
    private function generateAsciiCharts(): string
    {
        $charts = "\n📊 GRÁFICOS DE RENDIMIENTO\n";
        $charts .= "==========================\n";
        
        // Gráfico de tiempo por método
        $charts .= "\n⏱️  TIEMPO POR MÉTODO:\n";
        $charts .= "=====================\n";
        
        $methods = [];
        $times = [];
        
        // Recopilar datos de todos los métodos
        foreach ($this->results as $data) {
            if (is_array($data)) {
                foreach ($data as $method => $metrics) {
                    if (isset($metrics['time']['average'])) {
                        $methods[] = $method;
                        $times[] = $metrics['time']['average'];
                    }
                }
            }
        }
        
        if (!empty($methods) && !empty($times)) {
            $maxTime = max($times);
            $chartWidth = 50;
            
            for ($i = 0; $i < count($methods); $i++) {
                $barLength = (int) (($times[$i] / $maxTime) * $chartWidth);
                $bar = str_repeat('█', $barLength);
                $charts .= sprintf("%-20s %s %s\n", $methods[$i], $bar, $this->formatTime($times[$i]));
            }
        }
        
        return $charts;
    }
    
    /**
     * Genera recomendaciones de optimización
     * 
     * @return string Recomendaciones
     */
    private function generateRecommendations(): string
    {
        $recommendations = "\n💡 RECOMENDACIONES DE OPTIMIZACIÓN\n";
        $recommendations .= "===================================\n";
        
        $issues = [];
        
        // Analizar tiempos altos
        foreach ($this->results as $data) {
            if (is_array($data)) {
                foreach ($data as $method => $metrics) {
                    if (isset($metrics['time']['average'])) {
                        if ($metrics['time']['average'] > 0.01) { // > 10ms
                            $issues[] = "⚠️  $method es lento: " . $this->formatTime($metrics['time']['average']);
                        }
                    }
                }
            }
        }
        
        if (!empty($issues)) {
            $recommendations .= "\n🚨 PROBLEMAS IDENTIFICADOS:\n";
            foreach ($issues as $issue) {
                $recommendations .= "  $issue\n";
            }
        }
        
        // Recomendaciones generales
        $recommendations .= "\n📋 RECOMENDACIONES GENERALES:\n";
        $recommendations .= "  • Considerar implementar caching para respuestas frecuentes\n";
        $recommendations .= "  • Optimizar serialización JSON para datos grandes\n";
        $recommendations .= "  • Implementar lazy loading para objetos pesados\n";
        $recommendations .= "  • Monitorear uso de memoria en producción\n";
        $recommendations .= "  • Considerar pool de objetos para reutilización\n";
        
        return $recommendations;
    }
    
    /**
     * Genera métricas de calidad
     * 
     * @return string Métricas de calidad
     */
    private function generateQualityMetrics(): string
    {
        $metrics = "\n🎯 MÉTRICAS DE CALIDAD\n";
        $metrics .= "=====================\n";
        
        // Calcular estabilidad (baja desviación estándar = alta estabilidad)
        $stabilityScores = [];
        foreach ($this->results as $data) {
            if (is_array($data)) {
                foreach ($data as $methodData) {
                    if (isset($methodData['time']['std_dev']) && isset($methodData['time']['average'])) {
                        $cv = $methodData['time']['std_dev'] / $methodData['time']['average']; // Coeficiente de variación
                        $stabilityScores[] = 100 - ($cv * 100); // Convertir a porcentaje de estabilidad
                    }
                }
            }
        }
        
        if (!empty($stabilityScores)) {
            $avgStability = array_sum($stabilityScores) / count($stabilityScores);
            $metrics .= "Estabilidad promedio: " . number_format($avgStability, 1) . "%\n";
        }
        
        // Calcular eficiencia general
        $efficiencyScores = [];
        foreach ($this->results as $data) {
            if (is_array($data)) {
                foreach ($data as $methodData) {
                    if (isset($methodData['time']['average']) && isset($methodData['memory']['average'])) {
                        $efficiency = $this->calculateEfficiency($methodData);
                        $efficiencyScores[] = $efficiency;
                    }
                }
            }
        }
        
        if (!empty($efficiencyScores)) {
            $avgEfficiency = array_sum($efficiencyScores) / count($efficiencyScores);
            $metrics .= "Eficiencia promedio: " . number_format($avgEfficiency, 1) . "%\n";
        }
        
        $metrics .= "Total de métodos evaluados: " . count($efficiencyScores) . "\n";
        $metrics .= "Iteraciones por método: " . $this->defaultIterations . "\n";
        
        return $metrics;
    }
    
    /**
     * Genera reporte en formato HTML
     * 
     * @param bool $includeCharts Incluir gráficos
     * @param bool $includeRecommendations Incluir recomendaciones
     * @return string Reporte HTML
     */
    private function generateHtmlReport(bool $includeCharts = true, bool $includeRecommendations = true): string
    {
        $html = "<!DOCTYPE html>\n<html>\n<head>\n";
        $html .= "<title>Reporte de Benchmarking - Adaptadores</title>\n";
        $html .= "<style>\n";
        $html .= "body { font-family: Arial, sans-serif; margin: 20px; }\n";
        $html .= ".header { background: #f0f0f0; padding: 20px; border-radius: 5px; }\n";
        $html .= ".section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }\n";
        $html .= ".metric { display: inline-block; margin: 5px 10px; padding: 5px 10px; background: #e9e9e9; border-radius: 3px; }\n";
        $html .= ".chart { background: #f9f9f9; padding: 10px; margin: 10px 0; font-family: monospace; }\n";
        $html .= ".recommendation { background: #fff3cd; padding: 10px; margin: 5px 0; border-left: 4px solid #ffc107; }\n";
        $html .= ".warning { background: #f8d7da; padding: 10px; margin: 5px 0; border-left: 4px solid #dc3545; }\n";
        $html .= "</style>\n</head>\n<body>\n";
        
        $html .= "<div class='header'>\n";
        $html .= "<h1>📊 Reporte de Benchmarking - Adaptadores</h1>\n";
        $html .= "<p>Generado el: " . date('Y-m-d H:i:s') . "</p>\n";
        $html .= "</div>\n";
        
        // Convertir el reporte de texto a HTML
        $textReport = $this->generateTextReport($includeCharts, $includeRecommendations);
        $htmlReport = htmlspecialchars($textReport);
        $htmlReport = str_replace("\n", "<br>\n", $htmlReport);
        
        $html .= "<div class='section'>\n<pre>$htmlReport</pre>\n</div>\n";
        $html .= "</body>\n</html>";
        
        return $html;
    }
    
    /**
     * Genera reporte en formato JSON
     * 
     * @return string Reporte JSON
     */
    private function generateJsonReport(): string
    {
        $report = [
            'metadata' => [
                'generated_at' => date('Y-m-d H:i:s'),
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'iterations' => $this->defaultIterations,
                'peak_memory' => memory_get_peak_usage()
            ],
            'results' => $this->results,
            'summary' => $this->generateSummaryData()
        ];
        
        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * Genera reporte en formato CSV
     * 
     * @return string Reporte CSV
     */
    private function generateCsvReport(): string
    {
        $csv = "Category,Method,Type,Time_Avg,Time_Min,Time_Max,Time_StdDev,Memory_Avg,Memory_Min,Memory_Max,Memory_StdDev,Iterations\n";
        
        foreach ($this->results as $category => $data) {
            if (is_array($data)) {
                foreach ($data as $method => $methodData) {
                    if (isset($methodData['success'])) {
                        $csv .= $this->formatCsvRow($category, $method, 'success', $methodData['success']);
                    }
                    if (isset($methodData['error'])) {
                        $csv .= $this->formatCsvRow($category, $method, 'error', $methodData['error']);
                    }
                }
            }
        }
        
        return $csv;
    }
    
    /**
     * Formatea una fila CSV
     * 
     * @param string $category Categoría
     * @param string $method Método
     * @param string $type Tipo (success/error)
     * @param array $data Datos
     * @return string Fila CSV
     */
    private function formatCsvRow(string $category, string $method, string $type, array $data): string
    {
        return sprintf("%s,%s,%s,%.6f,%.6f,%.6f,%.6f,%.2f,%.2f,%.2f,%.2f,%d\n",
            $category,
            $method,
            $type,
            $data['time']['average'] ?? 0,
            $data['time']['min'] ?? 0,
            $data['time']['max'] ?? 0,
            $data['time']['std_dev'] ?? 0,
            $data['memory']['average'] ?? 0,
            $data['memory']['min'] ?? 0,
            $data['memory']['max'] ?? 0,
            $data['memory']['std_dev'] ?? 0,
            $data['iterations'] ?? 0
        );
    }
    
    /**
     * Genera datos de resumen
     * 
     * @return array Datos de resumen
     */
    private function generateSummaryData(): array
    {
        $metrics = $this->extractAllMetrics();
        $allTimes = $metrics['times'];
        $allMemory = $metrics['memory'];
        
        return [
            'total_methods' => count($allTimes),
            'time_stats' => !empty($allTimes) ? [
                'average' => array_sum($allTimes) / count($allTimes),
                'min' => min($allTimes),
                'max' => max($allTimes)
            ] : [],
            'memory_stats' => !empty($allMemory) ? [
                'average' => array_sum($allMemory) / count($allMemory),
                'min' => min($allMemory),
                'max' => max($allMemory)
            ] : []
        ];
    }
    
    /**
     * Formatea tiempo para el reporte
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
     * Formatea memoria para el reporte
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
     * Obtiene los resultados del benchmarking
     * 
     * @return array Resultados
     */
    public function getResults(): array
    {
        return $this->results;
    }
    
    /**
     * Analiza cuellos de botella en los resultados
     * 
     * @param array $thresholds Umbrales personalizados
     * @return BottleneckAnalyzer Analizador de cuellos de botella
     */
    public function analyzeBottlenecks(array $thresholds = []): BottleneckAnalyzer
    {
        return new BottleneckAnalyzer($this->results, $thresholds);
    }
    
    /**
     * Genera reporte de cuellos de botella
     * 
     * @param array $thresholds Umbrales personalizados
     * @return string Reporte de cuellos de botella
     */
    public function generateBottleneckReport(array $thresholds = []): string
    {
        $analyzer = $this->analyzeBottlenecks($thresholds);
        return $analyzer->generateReport();
    }
    
    /**
     * Establece métricas de referencia basadas en los resultados actuales
     * 
     * @return void
     */
    public function establishPerformanceBaselines(): void
    {
        if (empty($this->results)) {
            return;
        }
        
        $baseline = new PerformanceBaseline();
        $baseline->establishBaselines($this->results);
        
        // Guardar las métricas de referencia para uso futuro
        $this->performanceBaseline = $baseline;
    }
    
    /**
     * Evalúa el rendimiento contra las métricas de referencia
     * 
     * @return array Evaluación del rendimiento
     */
    public function evaluatePerformanceAgainstBaselines(): array
    {
        if (empty($this->performanceBaseline)) {
            $this->establishPerformanceBaselines();
        }
        
        $evaluations = [];
        
        foreach ($this->results as $category => $data) {
            if (is_array($data)) {
                foreach ($data as $method => $metrics) {
                    $evaluation = $this->performanceBaseline->evaluatePerformance($method, $metrics);
                    $evaluations[$category . '.' . $method] = $evaluation;
                }
            }
        }
        
        return $evaluations;
    }
    
    /**
     * Obtiene las métricas de referencia
     * 
     * @return array Métricas de referencia
     */
    public function getPerformanceBaselines(): array
    {
        if (empty($this->performanceBaseline)) {
            $this->establishPerformanceBaselines();
        }
        
        return $this->performanceBaseline->getBaselines();
    }
    
    /**
     * Genera reporte de métricas de referencia
     * 
     * @return string Reporte de métricas de referencia
     */
    public function generateBaselineReport(): string
    {
        if (empty($this->performanceBaseline)) {
            $this->establishPerformanceBaselines();
        }
        
        return $this->performanceBaseline->generateReport();
    }
    
    /**
     * Actualiza las métricas de referencia con nuevos datos
     * 
     * @param array $newResults Nuevos resultados del benchmarking
     * @return void
     */
    public function updateBaselines(array $newResults): void
    {
        if (empty($this->performanceBaseline)) {
            $this->establishPerformanceBaselines();
        }
        
        $this->performanceBaseline->updateBaselines($newResults);
    }
    
    /**
     * Resetea las métricas de referencia de rendimiento
     * 
     * @return bool True si se resetearon correctamente
     */
    public function resetPerformanceBaselines(): bool
    {
        if (empty($this->performanceBaseline)) {
            $this->establishPerformanceBaselines();
        }
        
        $cleared = $this->performanceBaseline->clearPersistentBaselines();
        
        // Reestablecer baselines por defecto
        $this->establishPerformanceBaselines();
        
        return $cleared;
    }
    
    /**
     * Recarga las métricas de referencia desde persistencia
     * 
     * @return void
     */
    public function reloadPerformanceBaselines(): void
    {
        if (empty($this->performanceBaseline)) {
            $this->establishPerformanceBaselines();
        }
        
        $this->performanceBaseline->reloadBaselines();
    }
    
    /**
     * Obtiene los datos de prueba
     * 
     * @return array Datos de prueba
     */
    public function getTestData(): array
    {
        return $this->testData;
    }
    
    /**
     * Obtiene la configuración
     * 
     * @return array Configuración
     */
    public function getConfig(): array
    {
        return $this->config;
    }
    
    /**
     * Establece la configuración
     * 
     * @param array $config Nueva configuración
     * @return void
     */
    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Extrae métricas de tiempo y memoria de todos los resultados
     * 
     * @return array Array con 'times' y 'memory' conteniendo los promedios
     */
    private function extractAllMetrics(): array
    {
        $allTimes = [];
        $allMemory = [];
        
        foreach ($this->results as $data) {
            if (is_array($data)) {
                foreach ($data as $metrics) {
                    if (isset($metrics['time']['average'])) {
                        $allTimes[] = $metrics['time']['average'];
                    }
                    if (isset($metrics['memory']['average'])) {
                        $allMemory[] = $metrics['memory']['average'];
                    }
                }
            }
        }
        
        return [
            'times' => $allTimes,
            'memory' => $allMemory
        ];
    }

    /**
     * Formatea los detalles de un adaptador específico
     * 
     * @param string $adapterName Nombre del adaptador
     * @param string $icon Icono para el adaptador
     * @param array $adapterData Datos del adaptador
     * @return string Detalles formateados del adaptador
     */
    private function formatAdapterDetails(string $adapterName, string $icon, array $adapterData): string
    {
        $details = "$icon $adapterName\n";
        $details .= str_repeat("=", strlen($adapterName) + 2) . "\n";
        
        foreach ($adapterData as $method => $data) {
            $details .= "\n📋 $method:\n";
            $details .= "  ✅ Éxito:\n";
            $details .= "    Tiempo: " . $this->formatTime($data['success']['time']['average']) . " ± " . $this->formatTime($data['success']['time']['std_dev']) . "\n";
            $details .= "    Memoria: " . $this->formatMemory($data['success']['memory']['average']) . " ± " . $this->formatMemory($data['success']['memory']['std_dev']) . "\n";
            $details .= "    Min/Max: " . $this->formatTime($data['success']['time']['min']) . " / " . $this->formatTime($data['success']['time']['max']) . "\n";
            $details .= "  ❌ Error:\n";
            $details .= "    Tiempo: " . $this->formatTime($data['error']['time']['average']) . " ± " . $this->formatTime($data['error']['time']['std_dev']) . "\n";
            $details .= "    Memoria: " . $this->formatMemory($data['error']['memory']['average']) . " ± " . $this->formatMemory($data['error']['memory']['std_dev']) . "\n";
            $details .= "    Min/Max: " . $this->formatTime($data['error']['time']['min']) . " / " . $this->formatTime($data['error']['time']['max']) . "\n";
        }
        
        return $details;
    }

    /**
     * Formatea métricas de rendimiento para análisis
     * 
     * @param string $title Título de la sección
     * @param string $icon Icono para la sección
     * @param array $data Datos de métricas
     * @return string Métricas formateadas
     */
    private function formatPerformanceMetrics(string $title, string $icon, array $data): string
    {
        $analysis = "\n$icon $title:\n";
        $analysis .= str_repeat("=", strlen($title) + 2) . "\n";
        
        foreach ($data as $item => $itemData) {
            $analysis .= "\n📋 $item:\n";
            foreach ($itemData as $method => $metrics) {
                $efficiency = $this->calculateEfficiency($metrics);
                $analysis .= "  $method: " . $this->formatTime($metrics['time']['average']) .
                           " (memoria: " . $this->formatMemory($metrics['memory']['average']) . 
                           ", eficiencia: $efficiency%)\n";
            }
        }
        
        return $analysis;
    }

    /**
     * Redondea un número de forma segura, manejando valores nulos o inválidos.
     *
     * Este método proporciona una forma segura de redondear números, manejando adecuadamente
     * valores nulos o no numéricos. Es especialmente útil cuando se trabaja con datos
     * que pueden provenir de fuentes externas o no confiables.
     *
     * @since   1.0.0
     * @package MiIntegracionApi\ErrorHandling\Adapters
     *
     * @param   float|int|null  $num        El número a redondear. Si es nulo o no numérico,
     *                                      se considerará como 0.0.
     * @param   int             $precision  La precisión decimal deseada. Por defecto es 0.
     *                                      Valores positivos indican decimales, negativos
     *                                      redondean a múltiplos de 10.
     *
     * @return  float  El número redondeado con la precisión especificada.
     *                 Devuelve 0.0 si el valor de entrada es nulo o no numérico.
     *
     * @example
     * ```php
     * // Devuelve 123.46
     * $resultado = $this->safe_round(123.456, 2);
     *
     * // Devuelve 120.0 (redondeo a decenas)
     * $resultado = $this->safe_round(123.456, -1);
     *
     * // Devuelve 0.0 (valor nulo)
     * $resultado = $this->safe_round(null);
     * ```
     */
    private function safe_round(float|int|null $num, int $precision = 0): float
    {
        // Si el número es nulo o no es numérico, devolver 0.0
        if ($num === null || !is_numeric($num)) {
            return 0.0;
        }

        // Convertir a float y redondear usando la función nativa de PHP
        return round((float) $num, $precision);
    }
}


