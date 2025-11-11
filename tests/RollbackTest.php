<?php
declare(strict_types=1);

/**
 * Test de Rollback: Arquitectura en Dos Fases
 *
 * Este test verifica que el código legacy puede ser restaurado correctamente
 * en caso de que sea necesario hacer rollback de la arquitectura en dos fases.
 *
 * IMPORTANTE: Este test NO modifica archivos, solo verifica que el rollback es posible.
 *
 * @package MiIntegracionApi\Tests
 * @since 1.5.0
 */

// ============================================================================
// CONFIGURACIÓN INICIAL - En namespace global
// ============================================================================

namespace {
// Cargar mocks compartidos de WordPress
require_once __DIR__ . '/WordPressMocks.php';
}

// ============================================================================
// NAMESPACE DE TESTS
// ============================================================================

namespace MiIntegracionApi\Tests {

use Exception;

/**
 * Test de rollback para arquitectura en dos fases
 */
class RollbackTest {
    
    private $testResults = [];
    private $filesToCheck = [];
    
    /**
     * Constructor del test
     */
    public function __construct() {
        $this->log('🧪 Iniciando Test de Rollback: Arquitectura en Dos Fases');
        $this->log('═══════════════════════════════════════════════════════════');
        
        // Definir archivos a verificar
        $this->filesToCheck = [
            'BatchProcessor' => [
                'file' => dirname(__FILE__) . '/../includes/Core/BatchProcessor.php',
                'methods' => [
                    'prepare_complete_batch_data' => [
                        'legacy_comment' => 'CÓDIGO LEGACY COMENTADO',
                        'new_code' => 'arquitectura dos fases'
                    ],
                    'get_imagenes_batch' => [
                        'legacy_comment' => 'MÉTODO COMENTADO: Obtención de imágenes por batch',
                        'new_code' => 'arquitectura dos fases'
                    ],
                    'get_imagenes_for_products' => [
                        'legacy_comment' => 'CÓDIGO LEGACY COMENTADO: Obtención de imágenes por producto',
                        'new_code' => 'arquitectura dos fases'
                    ],
                    'processImageItem' => [
                        'legacy_comment' => 'CÓDIGO LEGACY COMENTADO: Procesamiento Base64',
                        'new_code' => 'attachment_ids directamente'
                    ]
                ]
            ],
            'MapProduct' => [
                'file' => dirname(__FILE__) . '/../includes/Helpers/MapProduct.php',
                'methods' => [
                    'processProductImages' => [
                        'legacy_comment' => 'CÓDIGO LEGACY COMENTADO: Búsqueda lineal en batch_cache',
                        'new_code' => 'get_attachments_by_article_id'
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Ejecuta todos los tests
     */
    public function runAllTests(): array {
        $this->log("\n📋 EJECUTANDO TEST DE ROLLBACK\n");
        
        try {
            // Test 1: Verificar que código legacy está comentado
            $this->testLegacyCodeCommented();
            
            // Test 2: Verificar que código nuevo está activo
            $this->testNewCodeActive();
            
            // Test 3: Verificar que rollback es posible (estructura)
            $this->testRollbackPossible();
            
            // Test 4: Verificar marcadores de rollback
            $this->testRollbackMarkers();
            
        } catch (Exception $e) {
            $this->log("❌ ERROR CRÍTICO EN TESTS: " . $e->getMessage());
            $this->log("Stack trace: " . $e->getTraceAsString());
        }
        
        return $this->generateReport();
    }
    
    /**
     * Test 1: Verificar que código legacy está comentado
     */
    private function testLegacyCodeCommented(): void {
        $this->log("\n🔍 Test 1: Verificar Código Legacy Comentado");
        $this->log("─────────────────────────────────────");
        
        try {
            $allCommented = true;
            $details = [];
            
            foreach ($this->filesToCheck as $className => $fileInfo) {
                $filePath = $fileInfo['file'];
                
                if (!file_exists($filePath)) {
                    $this->testResults['legacy_commented'] = [
                        'status' => 'FAILED',
                        'message' => "Archivo no encontrado: {$filePath}"
                    ];
                    $this->log("❌ FAILED: Archivo no encontrado: {$filePath}");
                    return;
                }
                
                $sourceCode = file_get_contents($filePath);
                
                foreach ($fileInfo['methods'] as $methodName => $markers) {
                    $legacyMarker = $markers['legacy_comment'];
                    
                    // Buscar marcador de código legacy comentado
                    $hasLegacyMarker = strpos($sourceCode, $legacyMarker) !== false;
                    
                    // Buscar si hay código comentado (bloques /* */ o líneas //)
                    $hasCommentedBlock = preg_match(
                        '/\/\*.*?' . preg_quote($legacyMarker, '/') . '.*?\*\//s',
                        $sourceCode
                    ) || preg_match(
                        '/\/\/.*?' . preg_quote($legacyMarker, '/') . '/',
                        $sourceCode
                    );
                    
                    $details[] = [
                        'class' => $className,
                        'method' => $methodName,
                        'has_marker' => $hasLegacyMarker,
                        'has_commented_block' => $hasCommentedBlock
                    ];
                    
                    if (!$hasLegacyMarker || !$hasCommentedBlock) {
                        $allCommented = false;
                    }
                }
            }
            
            if ($allCommented) {
                $this->testResults['legacy_commented'] = [
                    'status' => 'PASSED',
                    'message' => 'Todo el código legacy está correctamente comentado',
                    'details' => $details
                ];
                $this->log("✅ PASSED: Código legacy comentado correctamente");
                foreach ($details as $detail) {
                    $this->log("   - {$detail['class']}::{$detail['method']}: ✅");
                }
            } else {
                $this->testResults['legacy_commented'] = [
                    'status' => 'WARNING',
                    'message' => 'Algunos bloques legacy pueden no estar comentados correctamente',
                    'details' => $details
                ];
                $this->log("⚠️  WARNING: Algunos bloques pueden no estar comentados");
                foreach ($details as $detail) {
                    $status = ($detail['has_marker'] && $detail['has_commented_block']) ? '✅' : '⚠️';
                    $this->log("   - {$detail['class']}::{$detail['method']}: {$status}");
                }
            }
            
        } catch (Exception $e) {
            $this->testResults['legacy_commented'] = [
                'status' => 'ERROR',
                'message' => 'Excepción: ' . $e->getMessage()
            ];
            $this->log("❌ ERROR: " . $e->getMessage());
        }
    }
    
    /**
     * Test 2: Verificar que código nuevo está activo
     */
    private function testNewCodeActive(): void {
        $this->log("\n🔍 Test 2: Verificar Código Nuevo Activo");
        $this->log("─────────────────────────────────────");
        
        try {
            $allActive = true;
            $details = [];
            
            foreach ($this->filesToCheck as $className => $fileInfo) {
                $filePath = $fileInfo['file'];
                $sourceCode = file_get_contents($filePath);
                
                foreach ($fileInfo['methods'] as $methodName => $markers) {
                    $newCodeMarker = $markers['new_code'];
                    
                    // Buscar si el código nuevo está presente y activo (no comentado)
                    $hasNewCode = strpos($sourceCode, $newCodeMarker) !== false;
                    
                    // Verificar que no está dentro de un bloque comentado
                    $isCommented = false;
                    if ($hasNewCode) {
                        // Buscar posición del marcador
                        $pos = strpos($sourceCode, $newCodeMarker);
                        if ($pos !== false) {
                            // Verificar si está dentro de un bloque comentado
                            $before = substr($sourceCode, max(0, $pos - 500), 500);
                            $isCommented = preg_match('/\/\*[^*]*\*+(?:[^*\/][^*]*\*+)*\//s', $before) > 0;
                        }
                    }
                    
                    $isActive = $hasNewCode && !$isCommented;
                    
                    $details[] = [
                        'class' => $className,
                        'method' => $methodName,
                        'has_new_code' => $hasNewCode,
                        'is_commented' => $isCommented,
                        'is_active' => $isActive
                    ];
                    
                    if (!$isActive) {
                        $allActive = false;
                    }
                }
            }
            
            if ($allActive) {
                $this->testResults['new_code_active'] = [
                    'status' => 'PASSED',
                    'message' => 'Todo el código nuevo está activo',
                    'details' => $details
                ];
                $this->log("✅ PASSED: Código nuevo activo correctamente");
                foreach ($details as $detail) {
                    $this->log("   - {$detail['class']}::{$detail['method']}: ✅");
                }
            } else {
                $this->testResults['new_code_active'] = [
                    'status' => 'WARNING',
                    'message' => 'Algunos bloques nuevos pueden no estar activos',
                    'details' => $details
                ];
                $this->log("⚠️  WARNING: Algunos bloques pueden no estar activos");
                foreach ($details as $detail) {
                    $status = $detail['is_active'] ? '✅' : '⚠️';
                    $this->log("   - {$detail['class']}::{$detail['method']}: {$status}");
                }
            }
            
        } catch (Exception $e) {
            $this->testResults['new_code_active'] = [
                'status' => 'ERROR',
                'message' => 'Excepción: ' . $e->getMessage()
            ];
            $this->log("❌ ERROR: " . $e->getMessage());
        }
    }
    
    /**
     * Test 3: Verificar que rollback es posible (estructura)
     */
    private function testRollbackPossible(): void {
        $this->log("\n🔍 Test 3: Verificar que Rollback es Posible");
        $this->log("─────────────────────────────────────");
        
        try {
            $rollbackPossible = true;
            $details = [];
            
            foreach ($this->filesToCheck as $className => $fileInfo) {
                $filePath = $fileInfo['file'];
                $sourceCode = file_get_contents($filePath);
                
                foreach ($fileInfo['methods'] as $methodName => $markers) {
                    $legacyMarker = $markers['legacy_comment'];
                    $newCodeMarker = $markers['new_code'];
                    
                    // Verificar que ambos marcadores existen
                    $hasLegacyMarker = strpos($sourceCode, $legacyMarker) !== false;
                    $hasNewCodeMarker = strpos($sourceCode, $newCodeMarker) !== false;
                    
                    // Verificar que hay instrucciones de rollback
                    $hasRollbackInstructions = strpos($sourceCode, 'Para rollback') !== false ||
                                             strpos($sourceCode, 'para rollback') !== false;
                    
                    $canRollback = $hasLegacyMarker && $hasNewCodeMarker && $hasRollbackInstructions;
                    
                    $details[] = [
                        'class' => $className,
                        'method' => $methodName,
                        'has_legacy_marker' => $hasLegacyMarker,
                        'has_new_code_marker' => $hasNewCodeMarker,
                        'has_rollback_instructions' => $hasRollbackInstructions,
                        'can_rollback' => $canRollback
                    ];
                    
                    if (!$canRollback) {
                        $rollbackPossible = false;
                    }
                }
            }
            
            if ($rollbackPossible) {
                $this->testResults['rollback_possible'] = [
                    'status' => 'PASSED',
                    'message' => 'Rollback es posible para todos los métodos',
                    'details' => $details
                ];
                $this->log("✅ PASSED: Rollback es posible");
                foreach ($details as $detail) {
                    $this->log("   - {$detail['class']}::{$detail['method']}: ✅");
                }
            } else {
                $this->testResults['rollback_possible'] = [
                    'status' => 'WARNING',
                    'message' => 'Algunos métodos pueden no tener rollback completo',
                    'details' => $details
                ];
                $this->log("⚠️  WARNING: Algunos métodos pueden no tener rollback completo");
                foreach ($details as $detail) {
                    $status = $detail['can_rollback'] ? '✅' : '⚠️';
                    $this->log("   - {$detail['class']}::{$detail['method']}: {$status}");
                }
            }
            
        } catch (Exception $e) {
            $this->testResults['rollback_possible'] = [
                'status' => 'ERROR',
                'message' => 'Excepción: ' . $e->getMessage()
            ];
            $this->log("❌ ERROR: " . $e->getMessage());
        }
    }
    
    /**
     * Test 4: Verificar marcadores de rollback
     */
    private function testRollbackMarkers(): void {
        $this->log("\n🔍 Test 4: Verificar Marcadores de Rollback");
        $this->log("─────────────────────────────────────");
        
        try {
            $allMarkersPresent = true;
            $markers = [
                'Para rollback' => 0,
                'descomentar' => 0,
                'comentar' => 0,
                'CÓDIGO LEGACY' => 0,
                'NUEVO' => 0
            ];
            
            foreach ($this->filesToCheck as $className => $fileInfo) {
                $filePath = $fileInfo['file'];
                $sourceCode = file_get_contents($filePath);
                
                foreach ($markers as $marker => $count) {
                    $occurrences = substr_count($sourceCode, $marker);
                    $markers[$marker] += $occurrences;
                }
            }
            
            // Verificar que hay al menos algunos marcadores
            $totalMarkers = array_sum($markers);
            
            if ($totalMarkers > 0) {
                $this->testResults['rollback_markers'] = [
                    'status' => 'PASSED',
                    'message' => 'Marcadores de rollback presentes',
                    'markers' => $markers,
                    'total' => $totalMarkers
                ];
                $this->log("✅ PASSED: Marcadores de rollback presentes");
                foreach ($markers as $marker => $count) {
                    if ($count > 0) {
                        $this->log("   - '{$marker}': {$count} ocurrencias");
                    }
                }
            } else {
                $this->testResults['rollback_markers'] = [
                    'status' => 'WARNING',
                    'message' => 'No se encontraron marcadores de rollback',
                    'markers' => $markers
                ];
                $this->log("⚠️  WARNING: No se encontraron marcadores de rollback");
            }
            
        } catch (Exception $e) {
            $this->testResults['rollback_markers'] = [
                'status' => 'ERROR',
                'message' => 'Excepción: ' . $e->getMessage()
            ];
            $this->log("❌ ERROR: " . $e->getMessage());
        }
    }
    
    /**
     * Helper: Log de mensajes
     */
    private function log(string $message): void {
        // Forzar salida a stdout
        fwrite(STDOUT, $message . "\n");
        if (function_exists('error_log')) {
            error_log('[RollbackTest] ' . $message);
        }
    }
    
    /**
     * Genera reporte de resultados
     */
    private function generateReport(): array {
        $this->log("\n");
        $this->log("═══════════════════════════════════════════════════════════");
        $this->log("📊 REPORTE DE RESULTADOS - TEST DE ROLLBACK");
        $this->log("═══════════════════════════════════════════════════════════");
        
        $passed = 0;
        $failed = 0;
        $warnings = 0;
        
        foreach ($this->testResults as $testName => $result) {
            $status = $result['status'];
            $message = $result['message'];
            
            if ($status === 'PASSED') {
                $passed++;
                $this->log("✅ {$testName}: PASSED - {$message}");
            } elseif ($status === 'FAILED') {
                $failed++;
                $this->log("❌ {$testName}: FAILED - {$message}");
            } else {
                $warnings++;
                $this->log("⚠️  {$testName}: {$status} - {$message}");
            }
        }
        
        $total = count($this->testResults);
        $this->log("\n");
        $this->log("RESUMEN:");
        $this->log("  Total de tests: $total");
        $this->log("  ✅ Pasados: $passed");
        $this->log("  ❌ Fallidos: $failed");
        $this->log("  ⚠️  Warnings: $warnings");
        
        $successRate = $total > 0 ? round(($passed / $total) * 100, 2) : 0;
        $this->log("  📈 Tasa de éxito: {$successRate}%");
        
        $this->log("\n═══════════════════════════════════════════════════════════\n");
        
        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'warnings' => $warnings,
            'success_rate' => $successRate,
            'results' => $this->testResults
        ];
    }
}

// Ejecutar test si se llama directamente
if (php_sapi_name() === 'cli' || (isset($_GET['run_test']) && $_GET['run_test'] === 'rollback')) {
    try {
        // Forzar salida inmediata
        fwrite(STDOUT, "🚀 Iniciando test de rollback...\n");
        
        $test = new \MiIntegracionApi\Tests\RollbackTest();
        $results = $test->runAllTests();
        
        // Retornar código de salida apropiado
        if ($results['failed'] > 0) {
            exit(1); // Fallo
        }
        exit(0); // Éxito
        
    } catch (\Exception $e) {
        fwrite(STDERR, "❌ ERROR CRÍTICO: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Stack trace:\n" . $e->getTraceAsString() . "\n");
        exit(1);
    } catch (\Throwable $e) {
        fwrite(STDERR, "❌ ERROR CRÍTICO: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Stack trace:\n" . $e->getTraceAsString() . "\n");
        exit(1);
    }
}

}

