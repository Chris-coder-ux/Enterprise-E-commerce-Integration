<?php
/**
 * Script para verificar y corregir el problema del toggle de detección automática
 * 
 * USO: wp eval-file verificar-corregir-toggle-detection.php
 * 
 * Este script:
 * 1. Verifica el estado del toggle
 * 2. Verifica qué cron jobs están programados
 * 3. Identifica problemas de sincronización
 * 4. Corrige automáticamente los problemas encontrados
 */

require_once('wp-load.php');

echo "═══════════════════════════════════════════════════════════════\n";
echo "  VERIFICACIÓN Y CORRECCIÓN DEL TOGGLE DE DETECCIÓN AUTOMÁTICA\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// 1. Verificar estado del toggle
$toggle_enabled = get_option('mia_automatic_stock_detection_enabled', false);
echo "📊 Estado del toggle: " . ($toggle_enabled ? '✅ ACTIVADO' : '❌ DESACTIVADO') . "\n\n";

// 2. Verificar hooks de cron programados
$hooks = [
    'mia_automatic_stock_detection' => 'Hook correcto (StockDetector)',
    'mia_auto_detection_hook' => 'Hook antiguo (DetectionDashboard)'
];

echo "═══════════════════════════════════════════════════════════════\n";
echo "  CRON JOBS PROGRAMADOS\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$cron_status = [];
foreach ($hooks as $hook => $description) {
    $timestamp = wp_next_scheduled($hook);
    $cron_status[$hook] = [
        'scheduled' => $timestamp !== false,
        'timestamp' => $timestamp,
        'next_run' => $timestamp ? date('Y-m-d H:i:s', $timestamp) : null,
        'description' => $description
    ];
    
    if ($timestamp) {
        echo "⚠️  $hook\n";
        echo "   Descripción: $description\n";
        echo "   Próxima ejecución: " . date('Y-m-d H:i:s', $timestamp) . "\n";
        echo "   Tiempo hasta ejecución: " . human_time_diff($timestamp, time()) . "\n\n";
    } else {
        echo "✅ $hook\n";
        echo "   Descripción: $description\n";
        echo "   Estado: No programado\n\n";
    }
}

// 3. Diagnóstico
echo "═══════════════════════════════════════════════════════════════\n";
echo "  DIAGNÓSTICO\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$hook_correcto = $cron_status['mia_automatic_stock_detection']['scheduled'];
$hook_antiguo = $cron_status['mia_auto_detection_hook']['scheduled'];

$problemas = [];

if ($toggle_enabled && !$hook_correcto) {
    $problemas[] = "❌ PROBLEMA CRÍTICO: Toggle activado pero cron job NO programado\n   → El toggle no está funcionando correctamente\n";
}

if (!$toggle_enabled && $hook_correcto) {
    $problemas[] = "❌ PROBLEMA CRÍTICO: Toggle desactivado pero cron job SÍ programado\n   → La sincronización seguirá ejecutándose aunque esté desactivada\n";
}

if ($hook_antiguo) {
    $problemas[] = "⚠️  ADVERTENCIA: Hook antiguo (mia_auto_detection_hook) todavía programado\n   → Puede causar confusión y ejecuciones duplicadas\n";
}

if ($hook_correcto && $hook_antiguo) {
    $problemas[] = "⚠️  ADVERTENCIA: Ambos hooks están programados simultáneamente\n   → Puede causar sincronizaciones duplicadas\n";
}

if (empty($problemas)) {
    echo "✅ Estado correcto: Toggle y cron job están sincronizados\n";
} else {
    foreach ($problemas as $problema) {
        echo $problema;
    }
}

// 4. Corrección automática
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  CORRECCIÓN AUTOMÁTICA\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$correcciones = [];

if (!$toggle_enabled) {
    // Desactivado: eliminar TODOS los hooks
    foreach ($hooks as $hook => $description) {
        if ($cron_status[$hook]['scheduled']) {
            wp_clear_scheduled_hook($hook);
            $correcciones[] = "✅ Eliminado: $hook ($description)";
        }
    }
    
    // También eliminar cualquier otro hook relacionado
    $all_hooks = [
        'mia_automatic_stock_detection',
        'mia_auto_detection_hook',
        'mia_every_5_minutes'
    ];
    
    foreach ($all_hooks as $hook) {
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_clear_scheduled_hook($hook);
            $correcciones[] = "✅ Eliminado adicional: $hook";
        }
    }
    
} else {
    // Activado: asegurar que SOLO el hook correcto está programado
    
    // Eliminar hook antiguo
    if ($hook_antiguo) {
        wp_clear_scheduled_hook('mia_auto_detection_hook');
        $correcciones[] = "✅ Eliminado hook antiguo: mia_auto_detection_hook";
    }
    
    // Programar hook correcto si no está programado
    if (!$hook_correcto) {
        // Registrar intervalo si no existe
        add_filter('cron_schedules', function($schedules) {
            $schedules['mia_detection_interval'] = [
                'interval' => 300, // 5 minutos
                'display' => __('Cada 5 minutos (Detección Automática)', 'mi-integracion-api')
            ];
            return $schedules;
        });
        
        $scheduled = wp_schedule_event(time(), 'mia_detection_interval', 'mia_automatic_stock_detection');
        if ($scheduled !== false) {
            $correcciones[] = "✅ Programado: mia_automatic_stock_detection";
        } else {
            $correcciones[] = "❌ Error programando: mia_automatic_stock_detection";
        }
    }
}

if (empty($correcciones)) {
    echo "✅ No se requieren correcciones\n";
} else {
    foreach ($correcciones as $correccion) {
        echo "$correccion\n";
    }
}

// 5. Verificación final
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  VERIFICACIÓN FINAL\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$final_toggle = get_option('mia_automatic_stock_detection_enabled', false);
$final_hook_correcto = wp_next_scheduled('mia_automatic_stock_detection');
$final_hook_antiguo = wp_next_scheduled('mia_auto_detection_hook');

echo "Toggle: " . ($final_toggle ? 'ACTIVADO' : 'DESACTIVADO') . "\n";
echo "Hook correcto programado: " . ($final_hook_correcto ? '✅ SÍ' : '❌ NO') . "\n";
echo "Hook antiguo programado: " . ($final_hook_antiguo ? '⚠️  SÍ (debe eliminarse)' : '✅ NO') . "\n\n";

if ($final_toggle && $final_hook_correcto && !$final_hook_antiguo) {
    echo "✅ Estado correcto después de la corrección\n";
} elseif (!$final_toggle && !$final_hook_correcto && !$final_hook_antiguo) {
    echo "✅ Estado correcto después de la corrección\n";
} else {
    echo "⚠️  Aún hay problemas. Revisar manualmente.\n";
}

// 6. Información adicional
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  INFORMACIÓN ADICIONAL\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Verificar si StockDetector verifica el toggle correctamente
if (class_exists('MiIntegracionApi\\Deteccion\\StockDetectorIntegration')) {
    $detector = \MiIntegracionApi\Deteccion\StockDetectorIntegration::getDetector();
    if ($detector && method_exists($detector, 'isEnabled')) {
        $detector_enabled = $detector->isEnabled();
        echo "StockDetector::isEnabled(): " . ($detector_enabled ? 'true' : 'false') . "\n";
        
        if ($detector_enabled !== $toggle_enabled) {
            echo "⚠️  ADVERTENCIA: El toggle y StockDetector::isEnabled() no coinciden\n";
        }
    }
}

// Verificar opciones relacionadas
$options = [
    'mia_automatic_stock_detection_enabled',
    'mia_detection_auto_active'
];

echo "\nOpciones relacionadas:\n";
foreach ($options as $option) {
    $value = get_option($option, 'NO CONFIGURADO');
    echo "  $option: " . ($value === 'NO CONFIGURADO' ? $value : ($value ? 'true' : 'false')) . "\n";
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  VERIFICACIÓN COMPLETADA\n";
echo "═══════════════════════════════════════════════════════════════\n\n";


