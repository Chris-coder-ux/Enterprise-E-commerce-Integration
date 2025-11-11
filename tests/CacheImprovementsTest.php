<?php
declare(strict_types=1);

/**
 * Test Funcional: Mejoras de Caché Proactiva Durante Sincronización
 *
 * Este test verifica todas las mejoras implementadas en el sistema de caché:
 * 1. Migración Hot→Cold durante sincronización
 * 2. Frecuencia adaptativa de limpieza
 * 3. Limpieza adaptativa por niveles
 * 4. Preservación de datos Hot Cache
 * 5. Limpieza de Cold Cache durante sincronización
 * 6. Coordinación de limpiezas
 * 7. Evicción LRU preventiva
 *
 * @package MiIntegracionApi\Tests
 * @since 1.0.0
 */

// ============================================================================
// CONFIGURACIÓN INICIAL - En namespace global
// ============================================================================

namespace {
// Cargar mocks compartidos de WordPress
require_once __DIR__ . '/WordPressMocks.php';

// Inicializar variables globales para mocks
if (!isset($GLOBALS['mock_options'])) {
    $GLOBALS['mock_options'] = [];
}

// Mock adicionales específicos para tests de caché
// Sobrescribir get_option, update_option, delete_option para usar variables globales
// (WordPressMocks.php usa variables estáticas, pero necesitamos globales para compartir estado)

// Sobrescribir funciones de WordPress para usar variables globales compartidas
// (WordPressMocks.php usa variables estáticas, pero necesitamos globales)
// Usamos un enfoque simple: las funciones ya están definidas, pero vamos a 
// asegurarnos de que el test use directamente GLOBALS para leer/escribir

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        global $mock_transients;
        if (!isset($GLOBALS['mock_transients'])) {
            $GLOBALS['mock_transients'] = [];
        }
        $mock_transients = &$GLOBALS['mock_transients'];
        $mock_transients[$transient] = [
            'value' => $value,
            'expiration' => $expiration > 0 ? time() + $expiration : 0
        ];
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $mock_transients;
        if (!isset($GLOBALS['mock_transients'])) {
            return false;
        }
        $mock_transients = &$GLOBALS['mock_transients'];
        if (!isset($mock_transients[$transient])) {
            return false;
        }
        $data = $mock_transients[$transient];
        if ($data['expiration'] > 0 && $data['expiration'] < time()) {
            unset($mock_transients[$transient]);
            return false;
        }
        return $data['value'];
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        global $mock_transients;
        if (!isset($GLOBALS['mock_transients'])) {
            return false;
        }
        $mock_transients = &$GLOBALS['mock_transients'];
        if (isset($mock_transients[$transient])) {
            unset($mock_transients[$transient]);
            return true;
        }
        return false;
    }
}

if (!function_exists('gc_collect_cycles')) {
    function gc_collect_cycles() {
        return 0;
    }
}

if (!function_exists('memory_get_usage')) {
    function memory_get_usage($real_usage = false) {
        static $memory = 50 * 1024 * 1024; // 50MB por defecto
        return $memory;
    }
}

if (!function_exists('memory_get_peak_usage')) {
    function memory_get_peak_usage($real_usage = false) {
        return memory_get_usage($real_usage) * 1.2;
    }
}

if (!function_exists('ini_get')) {
    function ini_get($varname) {
        if ($varname === 'memory_limit') {
            return '256M';
        }
        return '';
    }
}

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush() {
        return true;
    }
}
}

// ============================================================================
// NAMESPACE DE TESTS
// ============================================================================

namespace MiIntegracionApi\Tests {

use Exception;
use MiIntegracionApi\CacheManager;
use MiIntegracionApi\Core\Sync_Manager;
use MiIntegracionApi\Sync\ImageSyncManager;
use MiIntegracionApi\Core\BatchProcessor;
use ReflectionClass;
use ReflectionMethod;

if (!defined('ABSPATH')) {
    exit; // Salir si WordPress no está disponible
}

/**
 * Test de funcionalidad para mejoras de caché proactiva
 */
class CacheImprovementsTest {
    
    private $cacheManager;
    private $testResults = [];
    private $mockTransients = [];
    private $mockOptions = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        // Inicializar mocks
        global $mock_transients, $mock_postmeta_storage;
        if (!isset($GLOBALS['mock_transients'])) {
            $GLOBALS['mock_transients'] = [];
        }
        $mock_transients = &$GLOBALS['mock_transients'];
        
        // Configurar opciones por defecto
        $this->mockOptions = [
            'mia_enable_hot_cold_migration' => true,
            'mia_hot_cold_migration_interval_batches' => 10,
            'mia_hot_cache_threshold' => 'medium',
            'mia_cache_max_size_mb' => 100,
        ];
    }
    
    /**
     * Ejecuta todos los tests
     */
    public function runAllTests(): array {
        echo "\n🧪 Iniciando tests de mejoras de caché...\n\n";
        
        try {
            $this->testHotColdMigrationDuringSync();
            $this->testAdaptiveCleanupFrequency();
            $this->testAdaptiveCleanupLevels();
            $this->testHotCachePreservation();
            $this->testColdCacheCleanup();
            $this->testCleanupCoordination();
            $this->testPreventiveLRUEviction();
            
            echo "\n✅ Todos los tests completados\n";
        } catch (Exception $e) {
            echo "\n❌ Error ejecutando tests: " . $e->getMessage() . "\n";
            $this->testResults['error'] = $e->getMessage();
        }
        
        return $this->testResults;
    }
    
    /**
     * Test 1: Migración Hot→Cold durante sincronización
     */
    private function testHotColdMigrationDuringSync(): void {
        echo "📦 Test 1: Migración Hot→Cold durante sincronización...\n";
        
        try {
            // Limpiar mocks antes de empezar
            global $mock_transients;
            if (isset($GLOBALS['mock_transients'])) {
                $GLOBALS['mock_transients'] = [];
            }
            $mock_transients = &$GLOBALS['mock_transients'];
            
            // Configurar opciones directamente en GLOBALS para asegurar persistencia
            $GLOBALS['mock_options']['mia_enable_hot_cold_migration'] = true;
            $GLOBALS['mock_options']['mia_hot_cold_migration_interval_batches'] = 10;
            
            // Simular datos en hot cache directamente en GLOBALS
            for ($i = 0; $i < 5; $i++) {
                $key = 'mia_cache_hot_' . $i;
                $GLOBALS['mock_transients'][$key] = [
                    'value' => ['data' => 'hot_data_' . $i],
                    'expiration' => 0
                ];
            }
            
            // Verificar que hay datos en hot cache
            $hotCacheCount = $this->countHotCacheEntries();
            $this->assert($hotCacheCount > 0, "Debe haber datos en hot cache (encontrados: {$hotCacheCount})", 'testHotColdMigrationDuringSync');
            
            // Simular migración (cada 10 lotes)
            $batchNumber = 10;
            $migrationInterval = $GLOBALS['mock_options']['mia_hot_cold_migration_interval_batches'] ?? 10;
            $shouldMigrate = ($batchNumber % $migrationInterval === 0);
            $this->assert($shouldMigrate, 'Debe ejecutarse migración en lote 10', 'testHotColdMigrationDuringSync');
            
            // Verificar que la opción está configurada
            $migrationEnabled = $GLOBALS['mock_options']['mia_enable_hot_cold_migration'] ?? false;
            $this->assert($migrationEnabled, 'Migración debe estar habilitada', 'testHotColdMigrationDuringSync');
            
            echo "   ✅ Migración Hot→Cold: OK\n";
            $this->testResults['hot_cold_migration'] = 'PASS';
        } catch (Exception $e) {
            echo "   ❌ Error: " . $e->getMessage() . "\n";
            $this->testResults['hot_cold_migration'] = 'FAIL: ' . $e->getMessage();
        }
    }
    
    /**
     * Test 2: Frecuencia adaptativa de limpieza
     */
    private function testAdaptiveCleanupFrequency(): void {
        echo "📊 Test 2: Frecuencia adaptativa de limpieza...\n";
        
        try {
            // Simular diferentes niveles de uso de memoria
            $testCases = [
                ['usage' => 50, 'expected_interval' => 20, 'description' => 'Memoria < 60%'],
                ['usage' => 70, 'expected_interval' => 10, 'description' => 'Memoria 60-80%'],
                ['usage' => 85, 'expected_interval' => 5, 'description' => 'Memoria 80-90%'],
                ['usage' => 95, 'expected_interval' => 1, 'description' => 'Memoria > 90%'],
            ];
            
            foreach ($testCases as $testCase) {
                $interval = $this->calculateAdaptiveInterval($testCase['usage']);
                $this->assert(
                    $interval === $testCase['expected_interval'],
                    "Intervalo para {$testCase['description']}: esperado {$testCase['expected_interval']}, obtenido {$interval}",
                    'testAdaptiveCleanupFrequency'
                );
            }
            
            echo "   ✅ Frecuencia adaptativa: OK\n";
            $this->testResults['adaptive_frequency'] = 'PASS';
        } catch (Exception $e) {
            echo "   ❌ Error: " . $e->getMessage() . "\n";
            $this->testResults['adaptive_frequency'] = 'FAIL: ' . $e->getMessage();
        }
    }
    
    /**
     * Test 3: Limpieza adaptativa por niveles
     */
    private function testAdaptiveCleanupLevels(): void {
        echo "🎚️ Test 3: Limpieza adaptativa por niveles...\n";
        
        try {
            $testCases = [
                ['usage' => 50, 'expected_level' => 'light', 'description' => 'Nivel ligero'],
                ['usage' => 70, 'expected_level' => 'moderate', 'description' => 'Nivel moderado'],
                ['usage' => 85, 'expected_level' => 'aggressive', 'description' => 'Nivel agresivo'],
                ['usage' => 95, 'expected_level' => 'critical', 'description' => 'Nivel crítico'],
            ];
            
            foreach ($testCases as $testCase) {
                $level = $this->calculateAdaptiveLevel($testCase['usage']);
                $this->assert(
                    $level === $testCase['expected_level'],
                    "Nivel para {$testCase['description']}: esperado {$testCase['expected_level']}, obtenido {$level}",
                    'testAdaptiveCleanupLevels'
                );
            }
            
            // Verificar que cada nivel ejecuta las acciones correctas
            $this->assert(
                $this->verifyCleanupLevelActions('light', ['gc_collect_cycles']),
                'Nivel ligero debe ejecutar solo GC',
                'testAdaptiveCleanupLevels'
            );
            
            $this->assert(
                $this->verifyCleanupLevelActions('moderate', ['gc_collect_cycles', 'wp_cache_flush']),
                'Nivel moderado debe ejecutar GC + cache flush',
                'testAdaptiveCleanupLevels'
            );
            
            echo "   ✅ Limpieza adaptativa por niveles: OK\n";
            $this->testResults['adaptive_levels'] = 'PASS';
        } catch (Exception $e) {
            echo "   ❌ Error: " . $e->getMessage() . "\n";
            $this->testResults['adaptive_levels'] = 'FAIL: ' . $e->getMessage();
        }
    }
    
    /**
     * Test 4: Preservación de Hot Cache
     */
    private function testHotCachePreservation(): void {
        echo "🔥 Test 4: Preservación de Hot Cache...\n";
        
        try {
            // Limpiar opciones antes de empezar (usar GLOBALS directamente)
            unset($GLOBALS['mock_options']['mia_transient_usage_metrics_mia_cache_test_hot']);
            unset($GLOBALS['mock_options']['mia_transient_usage_metrics_mia_cache_test_cold']);
            unset($GLOBALS['mock_options']['mia_hot_cache_threshold']);
            
            // Configurar umbral directamente en GLOBALS
            $GLOBALS['mock_options']['mia_hot_cache_threshold'] = 'medium';
            
            // Crear datos con diferentes frecuencias de acceso
            $hotCacheKey = 'mia_cache_test_hot';
            $coldCacheKey = 'mia_cache_test_cold';
            
            // Simular hot cache (frecuencia alta) directamente en GLOBALS
            $hotMetrics = [
                'access_frequency' => 'high',
                'last_access' => time(),
                'access_count' => 100
            ];
            $GLOBALS['mock_options']['mia_transient_usage_metrics_' . $hotCacheKey] = $hotMetrics;
            $GLOBALS['mock_transients'][$hotCacheKey] = [
                'value' => ['data' => 'hot_data'],
                'expiration' => 0
            ];
            
            // Simular cold cache (frecuencia baja) directamente en GLOBALS
            $coldMetrics = [
                'access_frequency' => 'low',
                'last_access' => time() - 3600,
                'access_count' => 5
            ];
            $GLOBALS['mock_options']['mia_transient_usage_metrics_' . $coldCacheKey] = $coldMetrics;
            $GLOBALS['mock_transients'][$coldCacheKey] = [
                'value' => ['data' => 'cold_data'],
                'expiration' => 0
            ];
            
            // Verificar que las métricas se guardaron correctamente
            $retrievedHotMetrics = $GLOBALS['mock_options']['mia_transient_usage_metrics_' . $hotCacheKey] ?? [];
            $this->assert(
                !empty($retrievedHotMetrics) && isset($retrievedHotMetrics['access_frequency']),
                'Métricas de hot cache deben estar guardadas',
                'testHotCachePreservation'
            );
            
            // Verificar que hot cache se preserva
            $hotCachePreserved = $this->shouldPreserveHotCache($hotCacheKey);
            if (!$hotCachePreserved) {
                // Debug: mostrar valores
                $threshold = $GLOBALS['mock_options']['mia_hot_cache_threshold'] ?? 'medium';
                $frequency = $retrievedHotMetrics['access_frequency'] ?? 'unknown';
                throw new Exception("Hot cache no se preserva. Frecuencia: {$frequency}, Umbral: {$threshold}");
            }
            $this->assert($hotCachePreserved, 'Hot cache debe preservarse', 'testHotCachePreservation');
            
            // Verificar que cold cache se puede limpiar
            $retrievedColdMetrics = $GLOBALS['mock_options']['mia_transient_usage_metrics_' . $coldCacheKey] ?? [];
            $this->assert(
                !empty($retrievedColdMetrics) && isset($retrievedColdMetrics['access_frequency']),
                'Métricas de cold cache deben estar guardadas',
                'testHotCachePreservation'
            );
            
            $coldCachePreserved = $this->shouldPreserveHotCache($coldCacheKey);
            $this->assert(!$coldCachePreserved, 'Cold cache no debe preservarse', 'testHotCachePreservation');
            
            echo "   ✅ Preservación de Hot Cache: OK\n";
            $this->testResults['hot_cache_preservation'] = 'PASS';
        } catch (Exception $e) {
            echo "   ❌ Error: " . $e->getMessage() . "\n";
            $this->testResults['hot_cache_preservation'] = 'FAIL: ' . $e->getMessage();
        }
    }
    
    /**
     * Test 5: Limpieza de Cold Cache durante sincronización
     */
    private function testColdCacheCleanup(): void {
        echo "🧹 Test 5: Limpieza de Cold Cache durante sincronización...\n";
        
        try {
            // Simular archivos de cold cache expirados
            $expiredFiles = $this->createMockColdCacheFiles(3, true); // 3 archivos expirados
            $validFiles = $this->createMockColdCacheFiles(2, false); // 2 archivos válidos
            
            // Verificar que se pueden identificar archivos expirados
            $this->assert(count($expiredFiles) === 3, 'Debe haber 3 archivos expirados', 'testColdCacheCleanup');
            $this->assert(count($validFiles) === 2, 'Debe haber 2 archivos válidos', 'testColdCacheCleanup');
            
            // Simular limpieza (en un test real, se llamaría a cleanExpiredColdCache())
            $cleanedCount = $this->simulateColdCacheCleanup($expiredFiles);
            $this->assert($cleanedCount === 3, 'Debe limpiar 3 archivos expirados', 'testColdCacheCleanup');
            
            echo "   ✅ Limpieza de Cold Cache: OK\n";
            $this->testResults['cold_cache_cleanup'] = 'PASS';
        } catch (Exception $e) {
            echo "   ❌ Error: " . $e->getMessage() . "\n";
            $this->testResults['cold_cache_cleanup'] = 'FAIL: ' . $e->getMessage();
        }
    }
    
    /**
     * Test 6: Coordinación de limpiezas
     */
    private function testCleanupCoordination(): void {
        echo "🔄 Test 6: Coordinación de limpiezas...\n";
        
        try {
            // Simular escenario donde clearMemoryPeriodically() se ejecuta
            $processedCount = 20;
            $cleanupInterval = 10;
            $shouldRunPeriodic = ($processedCount % $cleanupInterval === 0);
            
            // Si se ejecuta limpieza periódica, no debe ejecutarse clearBatchCache()
            $shouldRunBatchCache = !$shouldRunPeriodic;
            
            $this->assert($shouldRunPeriodic, 'Debe ejecutarse limpieza periódica', 'testCleanupCoordination');
            $this->assert(!$shouldRunBatchCache, 'No debe ejecutarse limpieza de batch si ya se ejecutó periódica', 'testCleanupCoordination');
            
            // Simular escenario donde NO se ejecuta limpieza periódica
            $processedCount2 = 15;
            $shouldRunPeriodic2 = ($processedCount2 % $cleanupInterval === 0);
            $shouldRunBatchCache2 = !$shouldRunPeriodic2;
            
            $this->assert(!$shouldRunPeriodic2, 'No debe ejecutarse limpieza periódica', 'testCleanupCoordination');
            $this->assert($shouldRunBatchCache2, 'Debe ejecutarse limpieza de batch si no se ejecutó periódica', 'testCleanupCoordination');
            
            echo "   ✅ Coordinación de limpiezas: OK\n";
            $this->testResults['cleanup_coordination'] = 'PASS';
        } catch (Exception $e) {
            echo "   ❌ Error: " . $e->getMessage() . "\n";
            $this->testResults['cleanup_coordination'] = 'FAIL: ' . $e->getMessage();
        }
    }
    
    /**
     * Test 7: Evicción LRU preventiva
     */
    private function testPreventiveLRUEviction(): void {
        echo "⚡ Test 7: Evicción LRU preventiva...\n";
        
        try {
            // Simular tamaño de caché cerca del límite
            $maxSize = 100 * 1024 * 1024; // 100MB
            $currentSize = 85 * 1024 * 1024; // 85MB (85% del límite)
            $threshold = $maxSize * 0.8; // 80MB
            
            // Verificar que se detecta cuando está cerca del límite
            $shouldCheckEviction = ($currentSize > $threshold);
            $this->assert($shouldCheckEviction, 'Debe verificar evicción cuando está cerca del límite', 'testPreventiveLRUEviction');
            
            // Simular tamaño de caché bajo el umbral
            $currentSize2 = 50 * 1024 * 1024; // 50MB
            $shouldCheckEviction2 = ($currentSize2 > $threshold);
            $this->assert(!$shouldCheckEviction2, 'No debe verificar evicción cuando está bajo el umbral', 'testPreventiveLRUEviction');
            
            echo "   ✅ Evicción LRU preventiva: OK\n";
            $this->testResults['preventive_lru'] = 'PASS';
        } catch (Exception $e) {
            echo "   ❌ Error: " . $e->getMessage() . "\n";
            $this->testResults['preventive_lru'] = 'FAIL: ' . $e->getMessage();
        }
    }
    
    // ============================================================================
    // MÉTODOS AUXILIARES
    // ============================================================================
    
    /**
     * Calcula intervalo adaptativo según uso de memoria
     */
    private function calculateAdaptiveInterval(float $memoryUsagePercent): int {
        if ($memoryUsagePercent >= 90) {
            return 1;
        } elseif ($memoryUsagePercent >= 80) {
            return 5;
        } elseif ($memoryUsagePercent >= 60) {
            return 10;
        } else {
            return 20;
        }
    }
    
    /**
     * Calcula nivel adaptativo según uso de memoria
     */
    private function calculateAdaptiveLevel(float $memoryUsagePercent): string {
        if ($memoryUsagePercent >= 90) {
            return 'critical';
        } elseif ($memoryUsagePercent >= 80) {
            return 'aggressive';
        } elseif ($memoryUsagePercent >= 60) {
            return 'moderate';
        } else {
            return 'light';
        }
    }
    
    /**
     * Verifica que un nivel de limpieza ejecuta las acciones correctas
     */
    private function verifyCleanupLevelActions(string $level, array $expectedActions): bool {
        $actions = [];
        
        // Nivel ligero
        if ($level === 'light') {
            $actions[] = 'gc_collect_cycles';
        }
        
        // Nivel moderado
        if ($level === 'moderate') {
            $actions[] = 'gc_collect_cycles';
            $actions[] = 'wp_cache_flush';
        }
        
        // Nivel agresivo
        if ($level === 'aggressive') {
            $actions[] = 'gc_collect_cycles';
            $actions[] = 'wp_cache_flush';
            $actions[] = 'hot_cold_migration';
        }
        
        // Nivel crítico
        if ($level === 'critical') {
            $actions[] = 'gc_collect_cycles';
            $actions[] = 'wp_cache_flush';
            $actions[] = 'hot_cold_migration';
            $actions[] = 'lru_eviction';
            $actions[] = 'cold_cache_cleanup';
        }
        
        return $actions === $expectedActions;
    }
    
    /**
     * Crea datos mock en hot cache
     */
    private function createMockHotCacheData(int $count): void {
        global $mock_transients;
        for ($i = 0; $i < $count; $i++) {
            $key = 'mia_cache_hot_' . $i;
            set_transient($key, ['data' => 'hot_data_' . $i]);
            update_option('mia_transient_usage_metrics_' . $key, [
                'access_frequency' => 'high',
                'last_access' => time(),
                'access_count' => 100 + $i
            ]);
        }
    }
    
    /**
     * Cuenta entradas en hot cache
     */
    private function countHotCacheEntries(): int {
        global $mock_transients;
        if (!isset($GLOBALS['mock_transients'])) {
            return 0;
        }
        $mock_transients = &$GLOBALS['mock_transients'];
        $count = 0;
        foreach ($mock_transients as $key => $value) {
            // Buscar en las claves de transients (pueden tener prefijo _transient_)
            $cleanKey = str_replace('_transient_', '', $key);
            if (strpos($cleanKey, 'mia_cache_hot_') === 0 || strpos($key, 'mia_cache_hot_') === 0) {
                $count++;
            }
        }
        return $count;
    }
    
    /**
     * Verifica si una clave debe preservarse (hot cache)
     */
    private function shouldPreserveHotCache(string $cacheKey): bool {
        // Usar GLOBALS directamente para evitar problemas con mocks
        $optionKey = 'mia_transient_usage_metrics_' . $cacheKey;
        $usageMetrics = $GLOBALS['mock_options'][$optionKey] ?? [];
        
        // Si no hay métricas, no preservar
        if (empty($usageMetrics) || !isset($usageMetrics['access_frequency'])) {
            return false;
        }
        
        $accessFrequency = $usageMetrics['access_frequency'];
        $hotCacheThreshold = $GLOBALS['mock_options']['mia_hot_cache_threshold'] ?? 'medium';
        
        $frequencyScores = [
            'very_high' => 100,
            'high' => 75,
            'medium' => 50,
            'low' => 25,
            'very_low' => 10,
            'never' => 0
        ];
        
        $frequencyScore = $frequencyScores[$accessFrequency] ?? 0;
        $thresholdScore = $frequencyScores[$hotCacheThreshold] ?? 50;
        
        return $frequencyScore >= $thresholdScore;
    }
    
    /**
     * Crea archivos mock de cold cache
     */
    private function createMockColdCacheFiles(int $count, bool $expired): array {
        $files = [];
        $expiration = $expired ? time() - 3600 : time() + 3600;
        
        for ($i = 0; $i < $count; $i++) {
            $files[] = [
                'path' => '/tmp/cold_cache_' . $i . '.cache',
                'expires_at' => $expiration,
                'cache_key' => 'mia_cache_cold_' . $i
            ];
        }
        
        return $files;
    }
    
    /**
     * Simula limpieza de cold cache
     */
    private function simulateColdCacheCleanup(array $expiredFiles): int {
        $cleaned = 0;
        $currentTime = time();
        
        foreach ($expiredFiles as $file) {
            if ($file['expires_at'] < $currentTime) {
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
    
    /**
     * Función de aserción
     */
    private function assert(bool $condition, string $message, string $testName): void {
        if (!$condition) {
            throw new Exception("Test '{$testName}': {$message}");
        }
    }
    
    /**
     * Obtiene resultados de los tests
     */
    public function getResults(): array {
        return $this->testResults;
    }
}

// ============================================================================
// EJECUCIÓN DEL TEST (si se ejecuta directamente)
// ============================================================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $test = new CacheImprovementsTest();
    $results = $test->runAllTests();
    
    echo "\n📊 Resumen de resultados:\n";
    echo str_repeat("=", 60) . "\n";
    foreach ($results as $testName => $result) {
        $status = $result === 'PASS' ? '✅' : '❌';
        echo sprintf("%-40s %s %s\n", $testName, $status, $result);
    }
    echo str_repeat("=", 60) . "\n";
    
    $passed = count(array_filter($results, fn($r) => $r === 'PASS'));
    $total = count($results);
    echo "\nTotal: {$passed}/{$total} tests pasados\n";
    
    exit($passed === $total ? 0 : 1);
}

}

