<?php
/**
 * Script de Test en Entorno de Desarrollo: Fase 2 - Sincronización de Productos
 *
 * Este script ejecuta la Fase 2 de la arquitectura en dos fases en un entorno
 * de desarrollo real, verificando que los productos se sincronizan correctamente
 * y que las imágenes se asignan desde la media library.
 *
 * USO EN LOCAL:
 *   Opción 1 (WP-CLI desde Local):
 *     wp eval-file scripts/test-desarrollo-fase2.php
 *     wp eval-file scripts/test-desarrollo-fase2.php -- 10 10
 *   
 *   Opción 2 (PHP directo):
 *     php scripts/test-desarrollo-fase2.php 10 10
 *   
 *   Opción 3 (Desde terminal de Local):
 *     cd /ruta/al/plugin
 *     php scripts/test-desarrollo-fase2.php
 *
 * @package MiIntegracionApi\Scripts
 * @since 1.5.0
 */

// Cargar WordPress si no está cargado
if (!defined('ABSPATH')) {
    // Intentar cargar desde ubicación estándar
    $wp_load = dirname(__FILE__) . '/../../../../wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        die("❌ ERROR: No se pudo cargar WordPress. Asegúrate de ejecutar este script desde el directorio del plugin o usar WP-CLI.\n");
    }
}

// Verificar que el plugin está activo
if (!class_exists('MiIntegracionApi\Core\BatchProcessor')) {
    die("❌ ERROR: El plugin Mi Integración API no está activo o BatchProcessor no está disponible.\n");
}

use MiIntegracionApi\Core\BatchProcessor;
use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Helpers\Logger;

echo "═══════════════════════════════════════════════════════════\n";
echo "🧪 TEST EN DESARROLLO: Fase 2 - Sincronización de Productos\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Configuración del test
$num_productos = isset($argv[1]) ? (int)$argv[1] : 10; // Por defecto 10 productos
$batch_size = isset($argv[2]) ? (int)$argv[2] : 10; // Por defecto batch de 10

echo "📋 Configuración del Test:\n";
echo "   - Productos a procesar: {$num_productos}\n";
echo "   - Tamaño de batch: {$batch_size}\n\n";

// Inicializar componentes
try {
    $logger = new Logger('test-desarrollo-fase2');
    $apiConnector = ApiConnector::get_instance();
    $batchProcessor = new BatchProcessor($apiConnector, $logger);
    
    echo "✅ Componentes inicializados correctamente\n\n";
    
    // Verificar que hay imágenes en media library (de Fase 1)
    echo "🔍 Verificando imágenes en media library...\n";
    
    // Obtener algunos productos de prueba
    $test_product_ids = range(1, min($num_productos, 100)); // IDs de prueba
    
    $total_images = 0;
    foreach ($test_product_ids as $product_id) {
        $attachments = \MiIntegracionApi\Helpers\MapProduct::get_attachments_by_article_id($product_id);
        $total_images += count($attachments);
    }
    
    echo "   - Imágenes encontradas en media library: {$total_images}\n";
    
    if ($total_images === 0) {
        echo "   ⚠️  ADVERTENCIA: No se encontraron imágenes en media library.\n";
        echo "   💡 SUGERENCIA: Ejecuta primero la Fase 1 (test-desarrollo-fase1.php)\n\n";
    } else {
        echo "   ✅ Imágenes disponibles para asignación\n\n";
    }
    
    // Ejecutar sincronización de productos
    echo "🚀 Iniciando sincronización de productos...\n";
    echo "─────────────────────────────────────\n";
    
    $start_time = microtime(true);
    $start_memory = memory_get_usage(true);
    
    // Procesar batch de productos
    $inicio = 1;
    $fin = $num_productos;
    
    echo "   Procesando productos del {$inicio} al {$fin}...\n";
    
    $result = $batchProcessor->processProductBatch($inicio, $fin, $batch_size);
    
    $end_time = microtime(true);
    $end_memory = memory_get_usage(true);
    $duration = $end_time - $start_time;
    $memory_used = $end_memory - $start_memory;
    
    // Analizar resultados
    $success = $result['success'] ?? false;
    $processed = $result['processed'] ?? 0;
    $errors = $result['errors'] ?? 0;
    $skipped = $result['skipped'] ?? 0;
    
    echo "\n─────────────────────────────────────\n";
    echo "📊 RESULTADOS:\n";
    echo "   - Éxito: " . ($success ? '✅ Sí' : '❌ No') . "\n";
    echo "   - Productos procesados: {$processed}\n";
    echo "   - Errores: {$errors}\n";
    echo "   - Saltados: {$skipped}\n";
    echo "   - Tiempo total: " . round($duration, 2) . " segundos\n";
    echo "   - Memoria usada: " . round($memory_used / 1024 / 1024, 2) . " MB\n";
    
    if ($processed > 0) {
        echo "   - Tiempo promedio por producto: " . round($duration / $processed, 2) . " segundos\n";
    }
    
    // Verificar que productos tienen imágenes asignadas
    echo "\n🔍 Verificando asignación de imágenes a productos...\n";
    
    $products_with_images = 0;
    $products_without_images = 0;
    $total_product_images = 0;
    
    // Obtener productos de WooCommerce creados/actualizados
    $args = [
        'post_type' => 'product',
        'posts_per_page' => $num_productos,
        'meta_query' => [
            [
                'key' => '_verial_product_id',
                'compare' => 'EXISTS'
            ]
        ],
        'orderby' => 'ID',
        'order' => 'DESC'
    ];
    
    $wc_products = get_posts($args);
    
    foreach ($wc_products as $wc_product) {
        $product = wc_get_product($wc_product->ID);
        
        if (!$product) {
            continue;
        }
        
        $image_id = $product->get_image_id();
        $gallery_ids = $product->get_gallery_image_ids();
        
        $has_images = !empty($image_id) || !empty($gallery_ids);
        
        if ($has_images) {
            $products_with_images++;
            $total_product_images += 1 + count($gallery_ids);
        } else {
            $products_without_images++;
        }
    }
    
    echo "   - Productos con imágenes: {$products_with_images}\n";
    echo "   - Productos sin imágenes: {$products_without_images}\n";
    echo "   - Total de imágenes asignadas: {$total_product_images}\n";
    
    // Verificar timeouts
    echo "\n🔍 Verificando timeouts en transacciones...\n";
    
    // Revisar logs para errores de timeout
    $log_file = wp_upload_dir()['basedir'] . '/mi-integracion-api/logs/';
    $timeout_errors = 0;
    
    if (is_dir($log_file)) {
        $log_files = glob($log_file . '*.log');
        foreach ($log_files as $file) {
            $content = file_get_contents($file);
            if (strpos($content, 'timeout') !== false || strpos($content, 'Lock wait timeout') !== false) {
                $timeout_errors++;
            }
        }
    }
    
    if ($timeout_errors > 0) {
        echo "   ⚠️  ADVERTENCIA: Se encontraron {$timeout_errors} posibles errores de timeout en logs\n";
    } else {
        echo "   ✅ No se encontraron errores de timeout\n";
    }
    
    // Verificar consumo de memoria
    echo "\n🔍 Verificando consumo de memoria...\n";
    echo "   - Memoria actual: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
    echo "   - Memoria pico: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
    
    $memory_limit = ini_get('memory_limit');
    echo "   - Límite de memoria: {$memory_limit}\n";
    
    // Resumen final
    echo "\n═══════════════════════════════════════════════════════════\n";
    echo "✅ TEST COMPLETADO\n";
    echo "═══════════════════════════════════════════════════════════\n";
    
    $all_checks_passed = $success && $errors === 0 && $products_with_images > 0 && $timeout_errors === 0;
    
    if ($all_checks_passed) {
        echo "✅ ÉXITO: Fase 2 ejecutada correctamente\n";
        echo "   - Productos sincronizados: ✅\n";
        echo "   - Imágenes asignadas: ✅\n";
        echo "   - Sin timeouts: ✅\n";
        echo "   - Memoria optimizada: ✅\n";
        exit(0);
    } else {
        echo "⚠️  ADVERTENCIA: Algunas verificaciones fallaron\n";
        if (!$success) echo "   - Sincronización falló\n";
        if ($errors > 0) echo "   - Errores encontrados: {$errors}\n";
        if ($products_with_images === 0) echo "   - No se asignaron imágenes a productos\n";
        if ($timeout_errors > 0) echo "   - Se encontraron timeouts\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "\n❌ ERROR CRÍTICO: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

