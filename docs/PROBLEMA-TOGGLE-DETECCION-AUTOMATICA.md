# 🔴 Problema: Toggle de Detección Automática No Funciona Correctamente

**Fecha**: 2025-11-04  
**Problema**: El toggle para activar/desactivar la detección automática no funciona correctamente  
**Causa**: Desconexión entre múltiples sistemas de cron y hooks diferentes

---

## 🔍 Análisis del Problema

### Problema Identificado: DOS Sistemas Diferentes

Hay **DOS sistemas diferentes** que manejan el cron job de detección automática:

#### Sistema 1: `DetectionDashboard.php`
- **Hook de cron**: `mia_auto_detection_hook`
- **Método de programación**: `scheduleDetectionCron()`
- **Método de eliminación**: `unscheduleDetectionCron()`
- **Ubicación**: Líneas 1849-1892

#### Sistema 2: `StockDetectorIntegration.php` + `StockDetector.php`
- **Hook de cron**: `mia_automatic_stock_detection`
- **Método de programación**: `StockDetector::activate()`
- **Método de eliminación**: `StockDetector::deactivate()`
- **Ubicación**: `StockDetector.php` líneas 71-122

### El Problema

1. **El toggle en `DetectionDashboard`** programa/elimina `mia_auto_detection_hook`
2. **Pero `StockDetector`** usa `mia_automatic_stock_detection`
3. **Son hooks diferentes**, por lo que:
   - El toggle puede desactivar `mia_auto_detection_hook`
   - Pero `mia_automatic_stock_detection` sigue activo
   - La sincronización continúa ejecutándose

### Verificación del Código

**DetectionDashboard.php** (línea 1858):
```php
if (!wp_next_scheduled('mia_auto_detection_hook')) {
    wp_schedule_event(time(), 'mia_every_5_minutes', 'mia_auto_detection_hook');
}
```

**StockDetector.php** (línea 82):
```php
if (!wp_next_scheduled(self::CRON_HOOK)) {  // CRON_HOOK = 'mia_automatic_stock_detection'
    wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
}
```

**Son hooks diferentes** → El toggle no controla el hook real que ejecuta la sincronización.

---

## 🔍 Verificación Adicional

### ¿Cuándo se Activa StockDetector Automáticamente?

**StockDetectorIntegration.php** (línea 48):
```php
// Se crea cuando se inicializa
self::$detector = new StockDetector($api_connector, $sync_manager);
```

**StockDetector.php** (línea 52):
```php
// Se registra el hook automáticamente al crear la instancia
add_action(self::CRON_HOOK, [$this, 'execute_detection']);
```

**Problema**: El hook se registra **automáticamente** cuando se crea la instancia, pero el cron job puede no estar programado.

### ¿Se Programa el Cron Automáticamente?

**StockDetector.php** (línea 71-99):
```php
public function activate(): bool
{
    // Solo programa si no está programado
    if (!wp_next_scheduled(self::CRON_HOOK)) {
        wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
    }
    // ...
}
```

**El cron NO se programa automáticamente** al crear la instancia. Solo se programa cuando se llama `activate()`.

### ¿Quién Llama a `activate()`?

Buscando en el código:
- `DetectionDashboard::handleToggleDetection()` → NO llama a `StockDetector::activate()`
- `DetectionDashboard::scheduleDetectionCron()` → Programa `mia_auto_detection_hook` (hook diferente)
- `StockDetectorIntegration::activate()` → SÍ llama a `StockDetector::activate()`

**Problema**: El toggle en `DetectionDashboard` NO usa `StockDetectorIntegration::activate()`, usa su propio sistema.

---

## 🐛 Bugs Encontrados

### Bug 1: Hooks Diferentes

El toggle programa/elimina `mia_auto_detection_hook`, pero el detector real usa `mia_automatic_stock_detection`.

**Solución**: Unificar a un solo hook.

### Bug 2: `execute_detection()` Verifica el Toggle

Aunque el cron job esté programado, `execute_detection()` verifica el toggle (línea 156):

```php
if (!$this->isEnabled()) {
    return; // Se salta la ejecución
}
```

**Pero**: Si el cron job está programado, se ejecuta cada 5 minutos, solo que no hace nada si está desactivado. Esto es ineficiente.

### Bug 3: El Toggle No Elimina el Cron Job Correcto

El toggle puede eliminar `mia_auto_detection_hook`, pero si `mia_automatic_stock_detection` está programado por otro lado, sigue ejecutándose.

---

## ✅ Solución Propuesta

### Solución 1: Unificar los Hooks (CRÍTICO)

Modificar `DetectionDashboard` para usar el mismo hook que `StockDetector`:

```php
// En DetectionDashboard.php, cambiar:
private function scheduleDetectionCron(): void
{
    $this->unscheduleDetectionCron();
    
    // Usar el hook correcto de StockDetector
    if (!wp_next_scheduled('mia_automatic_stock_detection')) {
        wp_schedule_event(time(), 'mia_detection_interval', 'mia_automatic_stock_detection');
    }
    
    $this->logger->info('Cron job de detección automática programado');
}

private function unscheduleDetectionCron(): void
{
    // Eliminar el hook correcto
    wp_clear_scheduled_hook('mia_automatic_stock_detection');
    
    // También eliminar el hook antiguo por si acaso
    wp_clear_scheduled_hook('mia_auto_detection_hook');
    
    $this->logger->info('Cron job de detección automática desprogramado');
}
```

### Solución 2: Usar StockDetectorIntegration Directamente

Modificar `DetectionDashboard::handleToggleDetection()` para usar `StockDetectorIntegration`:

```php
public function handleToggleDetection(): void
{
    // ... verificación de nonce y permisos ...
    
    $enabled = ($activate === '1');
    
    // Usar StockDetectorIntegration en lugar de programar manualmente
    if ($enabled) {
        $result = \MiIntegracionApi\Deteccion\StockDetectorIntegration::activate();
    } else {
        $result = \MiIntegracionApi\Deteccion\StockDetectorIntegration::deactivate();
    }
    
    // Actualizar opción también
    update_option('mia_automatic_stock_detection_enabled', $enabled);
    
    // ...
}
```

### Solución 3: Verificar y Limpiar Ambos Hooks

Agregar verificación y limpieza de ambos hooks:

```php
private function unscheduleDetectionCron(): void
{
    // Eliminar todos los hooks posibles
    $hooks = [
        'mia_automatic_stock_detection',  // Hook correcto
        'mia_auto_detection_hook'          // Hook antiguo
    ];
    
    foreach ($hooks as $hook) {
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
        }
        wp_clear_scheduled_hook($hook);
    }
    
    $this->logger->info('Todos los cron jobs de detección automática desprogramados');
}
```

---

## 🔧 Script de Verificación y Corrección

```php
<?php
/**
 * Script para verificar y corregir el problema del toggle
 * 
 * USO: wp eval-file verificar-toggle-detection.php
 */

require_once('wp-load.php');

echo "=== VERIFICACIÓN DE TOGGLE DE DETECCIÓN AUTOMÁTICA ===\n\n";

// 1. Verificar estado del toggle
$toggle_enabled = get_option('mia_automatic_stock_detection_enabled', false);
echo "Estado del toggle: " . ($toggle_enabled ? 'ACTIVADO' : 'DESACTIVADO') . "\n\n";

// 2. Verificar hooks de cron programados
$hooks = [
    'mia_automatic_stock_detection',  // Hook correcto
    'mia_auto_detection_hook'          // Hook antiguo
];

echo "=== CRON JOBS PROGRAMADOS ===\n";
foreach ($hooks as $hook) {
    $timestamp = wp_next_scheduled($hook);
    if ($timestamp) {
        echo "⚠️  $hook: Programado para " . date('Y-m-d H:i:s', $timestamp) . "\n";
    } else {
        echo "✅ $hook: No programado\n";
    }
}

echo "\n=== DIAGNÓSTICO ===\n";

$hook_correcto = wp_next_scheduled('mia_automatic_stock_detection');
$hook_antiguo = wp_next_scheduled('mia_auto_detection_hook');

if ($toggle_enabled && !$hook_correcto) {
    echo "❌ PROBLEMA: Toggle activado pero cron job NO programado\n";
    echo "   → El toggle no está funcionando correctamente\n";
} elseif (!$toggle_enabled && $hook_correcto) {
    echo "❌ PROBLEMA: Toggle desactivado pero cron job SÍ programado\n";
    echo "   → El toggle no eliminó el cron job\n";
} elseif ($hook_antiguo) {
    echo "⚠️  ADVERTENCIA: Hook antiguo (mia_auto_detection_hook) todavía programado\n";
    echo "   → Puede causar confusión\n";
} else {
    echo "✅ Estado correcto: Toggle y cron job están sincronizados\n";
}

echo "\n=== CORRECCIÓN ===\n";

if (!$toggle_enabled) {
    // Desactivado: eliminar todos los hooks
    foreach ($hooks as $hook) {
        wp_clear_scheduled_hook($hook);
        echo "✅ Eliminado: $hook\n";
    }
} else {
    // Activado: asegurar que el hook correcto está programado
    if (!$hook_correcto) {
        wp_schedule_event(time(), 'mia_detection_interval', 'mia_automatic_stock_detection');
        echo "✅ Programado: mia_automatic_stock_detection\n";
    }
    
    // Eliminar hook antiguo si existe
    if ($hook_antiguo) {
        wp_clear_scheduled_hook('mia_auto_detection_hook');
        echo "✅ Eliminado hook antiguo: mia_auto_detection_hook\n";
    }
}

echo "\n✅ Verificación completada\n";
```

---

## 📊 Verificación Manual

### 1. Verificar Estado del Toggle

```bash
wp option get mia_automatic_stock_detection_enabled
```

### 2. Verificar Cron Jobs

```bash
# Ver todos los cron jobs relacionados
wp cron event list | grep -i "mia\|detection"

# Verificar hooks específicos
wp cron event list | grep "mia_automatic_stock_detection"
wp cron event list | grep "mia_auto_detection_hook"
```

### 3. Verificar si se Ejecuta Aunque Esté Desactivado

```php
// Agregar esto temporalmente en StockDetector::execute_detection()
error_log('StockDetector ejecutado - Enabled: ' . ($this->isEnabled() ? 'YES' : 'NO'));
```

Luego revisar logs para ver si se ejecuta cuando está desactivado.

---

## ✅ Recomendación Inmediata

1. **Ejecutar script de verificación** para identificar el problema exacto
2. **Unificar hooks** para usar solo `mia_automatic_stock_detection`
3. **Modificar toggle** para usar `StockDetectorIntegration` directamente
4. **Verificar logs** para confirmar que no se ejecuta cuando está desactivado

---

## 🔗 Archivos Afectados

- `includes/Admin/DetectionDashboard.php` - Toggle UI
- `includes/Deteccion/StockDetector.php` - Lógica de detección
- `includes/Deteccion/StockDetectorIntegration.php` - Integración

---

## 🛠️ Script de Verificación y Corrección

He creado un script completo en `scripts/verificar-corregir-toggle-detection.php` que:

1. ✅ Verifica el estado del toggle
2. ✅ Verifica qué cron jobs están programados
3. ✅ Identifica problemas de sincronización
4. ✅ Corrige automáticamente los problemas

**Uso**:
```bash
wp eval-file scripts/verificar-corregir-toggle-detection.php
```

El script mostrará:
- Estado del toggle
- Qué cron jobs están programados
- Problemas encontrados
- Correcciones aplicadas
- Verificación final

