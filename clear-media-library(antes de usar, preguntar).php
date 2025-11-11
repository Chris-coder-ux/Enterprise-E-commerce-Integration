<?php
/**
 * Script para vaciar la biblioteca de medios de WordPress
 * 
 * ⚠️ ADVERTENCIA: Este script elimina TODAS las imágenes/archivos de la biblioteca de medios.
 * 
 * Uso:
 *   php clear-media-library.php                    # Modo seguro: muestra qué se eliminaría
 *   php clear-media-library.php --confirm           # Elimina realmente (requiere confirmación)
 *   php clear-media-library.php --filter-verial     # Solo elimina imágenes de Verial
 *   php clear-media-library.php --dry-run           # Simulación (no elimina nada)
 */

// Cargar WordPress
$wp_load_paths = [
    __DIR__ . '/wp-load.php',
    __DIR__ . '/../../../wp-load.php',
    dirname(__DIR__) . '/wp-load.php',
];

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    die("❌ ERROR: No se pudo cargar WordPress. Asegúrate de ejecutar este script desde la raíz del plugin o WordPress.\n");
}

// Verificar que WordPress esté cargado
if (!function_exists('wp_delete_attachment')) {
    die("❌ ERROR: WordPress no está cargado correctamente.\n");
}

// Parsear argumentos
$args = $argv ?? [];
$confirm = in_array('--confirm', $args);
$dry_run = in_array('--dry-run', $args);
$filter_verial = in_array('--filter-verial', $args);
$force = in_array('--force', $args);

// Encabezado
echo "═══════════════════════════════════════════════════════════════\n";
echo "  🗑️  LIMPIEZA DE BIBLIOTECA DE MEDIOS DE WORDPRESS\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

if ($dry_run) {
    echo "🔍 MODO DRY-RUN: No se eliminará nada, solo simulación\n\n";
} elseif (!$confirm && !$force) {
    echo "⚠️  MODO SEGURO: Solo mostrará qué se eliminaría\n";
    echo "   Usa --confirm para eliminar realmente\n\n";
}

// Obtener todos los attachments
$query_args = [
    'post_type' => 'attachment',
    'posts_per_page' => -1,
    'post_status' => 'any',
];

if ($filter_verial) {
    $query_args['meta_query'] = [
        [
            'key' => '_verial_article_id',
            'compare' => 'EXISTS'
        ]
    ];
}

$attachments = get_posts($query_args);
$total = count($attachments);

if ($total === 0) {
    echo "✅ No hay imágenes para eliminar.\n";
    exit(0);
}

// Estadísticas antes de eliminar
$stats = [
    'total' => $total,
    'deleted' => 0,
    'errors' => 0,
    'verial_images' => 0,
    'other_images' => 0,
    'total_size' => 0,
];

echo "📊 Estadísticas iniciales:\n";
echo "   Total de attachments: {$stats['total']}\n";

// Analizar attachments
foreach ($attachments as $attachment) {
    $is_verial = get_post_meta($attachment->ID, '_verial_article_id', true) !== '';
    
    if ($is_verial) {
        $stats['verial_images']++;
    } else {
        $stats['other_images']++;
    }
    
    // Calcular tamaño del archivo
    $file_path = get_attached_file($attachment->ID);
    if ($file_path && file_exists($file_path)) {
        $stats['total_size'] += filesize($file_path);
    }
}

echo "   - Imágenes de Verial: {$stats['verial_images']}\n";
echo "   - Otras imágenes: {$stats['other_images']}\n";
echo "   - Tamaño total: " . format_bytes($stats['total_size']) . "\n\n";

// Confirmación interactiva
if ($confirm && !$force && !$dry_run) {
    echo "⚠️  ADVERTENCIA: Estás a punto de eliminar {$stats['total']} archivos.\n";
    echo "   Esto NO se puede deshacer.\n\n";
    
    echo "¿Estás seguro? Escribe 'SI, ELIMINAR' para continuar: ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);
    
    if ($line !== 'SI, ELIMINAR') {
        echo "\n❌ Operación cancelada.\n";
        exit(0);
    }
    echo "\n";
}

// Procesar eliminación
echo "🔄 Procesando eliminación...\n\n";

$batch_size = 50;
$processed = 0;

foreach ($attachments as $attachment) {
    $processed++;
    
    $attachment_id = $attachment->ID;
    $is_verial = get_post_meta($attachment_id, '_verial_article_id', true) !== '';
    $file_path = get_attached_file($attachment_id);
    $file_size = $file_path && file_exists($file_path) ? filesize($file_path) : 0;
    
    // Mostrar progreso cada 10 archivos
    if ($processed % 10 === 0) {
        $percent = round(($processed / $stats['total']) * 100, 1);
        echo "   Procesando: {$processed}/{$stats['total']} ({$percent}%)\r";
    }
    
    if ($dry_run) {
        // En dry-run, solo contar
        $stats['deleted']++;
    } else {
        // Eliminar realmente
        $result = wp_delete_attachment($attachment_id, true); // true = fuerza eliminación del archivo
        
        if ($result) {
            $stats['deleted']++;
        } else {
            $stats['errors']++;
            echo "\n   ⚠️  Error al eliminar attachment ID: {$attachment_id}\n";
        }
    }
    
    // Límite de memoria: procesar en lotes
    if ($processed % $batch_size === 0) {
        wp_cache_flush();
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
}

echo "\n\n";

// Estadísticas finales
echo "═══════════════════════════════════════════════════════════════\n";
echo "  📊 RESUMEN DE ELIMINACIÓN\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

if ($dry_run) {
    echo "🔍 SIMULACIÓN (no se eliminó nada):\n";
} else {
    echo "✅ Eliminación completada:\n";
}

echo "   Total procesados: {$processed}\n";
echo "   Eliminados exitosamente: {$stats['deleted']}\n";
echo "   Errores: {$stats['errors']}\n";
echo "   Espacio liberado: " . format_bytes($stats['total_size']) . "\n\n";

if ($stats['errors'] > 0) {
    echo "⚠️  Hubo {$stats['errors']} errores durante la eliminación.\n";
    echo "   Revisa los logs de WordPress para más detalles.\n\n";
}

if (!$dry_run && $stats['deleted'] > 0) {
    echo "✅ Biblioteca de medios limpiada exitosamente.\n\n";
}

// Limpiar cache y opciones relacionadas
if (!$dry_run && $stats['deleted'] > 0) {
    echo "🧹 Limpiando cache y opciones relacionadas...\n";
    
    // Limpiar opciones de checkpoint de imágenes
    delete_option('mia_images_download_checkpoint');
    delete_option('mia_image_mappings');
    delete_option('mia_last_images_sync_stats');
    delete_option('mia_products_without_images');
    
    // Limpiar cache
    wp_cache_flush();
    
    echo "✅ Limpieza completada.\n\n";
}

/**
 * Formatea bytes a formato legible
 * 
 * @param int $bytes
 * @return string
 */
function format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

echo "═══════════════════════════════════════════════════════════════\n";



