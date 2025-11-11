<?php
/**
 * Script de Test en Entorno de Desarrollo: Fase 1 - Sincronización de Imágenes
 *
 * Este script ejecuta la Fase 1 de la arquitectura en dos fases en un entorno
 * de desarrollo real, verificando que las imágenes se sincronizan correctamente.
 *
 * USO EN LOCAL:
 *   Opción 1 (WP-CLI desde Local):
 *     wp eval-file scripts/test-desarrollo-fase1.php
 *     wp eval-file scripts/test-desarrollo-fase1.php -- 10 10
 *   
 *   Opción 2 (PHP directo):
 *     php scripts/test-desarrollo-fase1.php 10 10
 *   
 *   Opción 3 (Desde terminal de Local):
 *     cd /ruta/al/plugin
 *     php scripts/test-desarrollo-fase1.php
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
if (!class_exists('MiIntegracionApi\Sync\ImageSyncManager')) {
    die("❌ ERROR: El plugin Mi Integración API no está activo o ImageSyncManager no está disponible.\n");
}

use MiIntegracionApi\Sync\ImageSyncManager;
use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Helpers\Logger;

echo "═══════════════════════════════════════════════════════════\n";
echo "🧪 TEST EN DESARROLLO: Fase 1 - Sincronización de Imágenes\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Configuración del test
$num_productos = isset($argv[1]) ? (int)$argv[1] : 10; // Por defecto 10 productos
$batch_size = isset($argv[2]) ? (int)$argv[2] : 10; // Por defecto batch de 10

echo "📋 Configuración del Test:\n";
echo "   - Productos a procesar: {$num_productos}\n";
echo "   - Tamaño de batch: {$batch_size}\n\n";

// Inicializar componentes
try {
    $logger = new Logger('test-desarrollo-fase1');
    $apiConnector = ApiConnector::get_instance();
    $imageSyncManager = new ImageSyncManager($apiConnector, $logger);
    
    echo "✅ Componentes inicializados correctamente\n\n";
    
    // Obtener IDs de productos
    echo "🔍 Obteniendo IDs de productos desde Verial...\n";
    $product_ids = $imageSyncManager->getAllProductIds();
    
    if (empty($product_ids)) {
        die("❌ ERROR: No se encontraron productos en Verial.\n");
    }
    
    $total_productos = count($product_ids);
    echo "   - Total de productos encontrados: {$total_productos}\n";
    
    // Limitar a los primeros N productos para el test
    $product_ids_test = array_slice($product_ids, 0, min($num_productos, $total_productos));
    echo "   - Productos para test: " . count($product_ids_test) . "\n\n";
    
    // Ejecutar sincronización de imágenes
    echo "🚀 Iniciando sincronización de imágenes...\n";
    echo "─────────────────────────────────────\n";
    
    $start_time = microtime(true);
    $start_memory = memory_get_usage(true);
    
    // Procesar imágenes para los productos de test
    $processed = 0;
    $errors = 0;
    $duplicates = 0;
    
    foreach ($product_ids_test as $product_id) {
        echo "   Procesando producto ID: {$product_id}...\n";
        
        $result = $imageSyncManager->processProductImages($product_id);
        
        if (is_array($result)) {
            $processed++;
            $images_count = count($result['images'] ?? []);
            $duplicates_count = count(array_filter($result['images'] ?? [], function($img) {
                return $img === 'duplicate';
            }));
            
            $duplicates += $duplicates_count;
            
            echo "      ✅ Procesado: {$images_count} imágenes (duplicados: {$duplicates_count})\n";
        } else {
            $errors++;
            echo "      ❌ Error procesando producto\n";
        }
    }
    
    $end_time = microtime(true);
    $end_memory = memory_get_usage(true);
    $duration = $end_time - $start_time;
    $memory_used = $end_memory - $start_memory;
    
    echo "\n─────────────────────────────────────\n";
    echo "📊 RESULTADOS:\n";
    echo "   - Productos procesados: {$processed}\n";
    echo "   - Errores: {$errors}\n";
    echo "   - Duplicados detectados: {$duplicates}\n";
    echo "   - Tiempo total: " . round($duration, 2) . " segundos\n";
    echo "   - Memoria usada: " . round($memory_used / 1024 / 1024, 2) . " MB\n";
    echo "   - Tiempo promedio por producto: " . round($duration / max($processed, 1), 2) . " segundos\n";
    
    // Verificar imágenes en media library
    echo "\n🔍 Verificando imágenes en media library...\n";
    
    $total_attachments = 0;
    foreach ($product_ids_test as $product_id) {
        $attachments = \MiIntegracionApi\Helpers\MapProduct::get_attachments_by_article_id($product_id);
        $total_attachments += count($attachments);
        
        if (count($attachments) > 0) {
            echo "   - Producto {$product_id}: " . count($attachments) . " imágenes\n";
        }
    }
    
    echo "\n   - Total de imágenes en media library: {$total_attachments}\n";
    
    // Verificar metadatos
    echo "\n🔍 Verificando metadatos...\n";
    
    $meta_checks = [
        '_verial_article_id' => 0,
        '_verial_image_hash' => 0,
        '_verial_image_order' => 0
    ];
    
    foreach ($product_ids_test as $product_id) {
        $attachments = \MiIntegracionApi\Helpers\MapProduct::get_attachments_by_article_id($product_id);
        
        foreach ($attachments as $attachment_id) {
            foreach (array_keys($meta_checks) as $meta_key) {
                $meta_value = get_post_meta($attachment_id, $meta_key, true);
                if (!empty($meta_value)) {
                    $meta_checks[$meta_key]++;
                }
            }
        }
    }
    
    echo "   - _verial_article_id: {$meta_checks['_verial_article_id']} attachments\n";
    echo "   - _verial_image_hash: {$meta_checks['_verial_image_hash']} attachments\n";
    echo "   - _verial_image_order: {$meta_checks['_verial_image_order']} attachments\n";
    
    // Resumen final
    echo "\n═══════════════════════════════════════════════════════════\n";
    echo "✅ TEST COMPLETADO\n";
    echo "═══════════════════════════════════════════════════════════\n";
    
    if ($errors === 0 && $processed > 0) {
        echo "✅ ÉXITO: Fase 1 ejecutada correctamente\n";
        exit(0);
    } else {
        echo "⚠️  ADVERTENCIA: Se encontraron errores durante la ejecución\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "\n❌ ERROR CRÍTICO: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

