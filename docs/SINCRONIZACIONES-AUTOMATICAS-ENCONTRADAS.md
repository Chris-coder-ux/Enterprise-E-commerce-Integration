# 🔍 Sincronizaciones Automáticas Encontradas

**Fecha**: 2025-11-04  
**Objetivo**: Identificar código que ejecute sincronizaciones automáticas sin intervención manual

---

## ⚠️ Sincronizaciones Automáticas Detectadas

### 1. **StockDetector - Detección Automática de Stock** ⚠️ ACTIVO

**Ubicación**: `includes/Deteccion/StockDetector.php`

**Cron Hook**: `mia_automatic_stock_detection`  
**Frecuencia**: Cada 5 minutos (`mia_stock_detection_interval`)

**Estado**: Verificar con:
```php
get_option('mia_automatic_stock_detection_enabled', false)
```

**Qué hace**:
- Se ejecuta automáticamente cada 5 minutos
- Consulta Verial para detectar cambios de stock
- Sincroniza productos que han cambiado
- Usa `Sync_Manager` para sincronizar productos

**Cómo desactivar**:
```php
// Desactivar detección automática
update_option('mia_automatic_stock_detection_enabled', false);

// Eliminar cron job
wp_clear_scheduled_hook('mia_automatic_stock_detection');
```

**Verificar si está activo**:
```sql
SELECT option_value 
FROM wp_options 
WHERE option_name = 'mia_automatic_stock_detection_enabled';
```

```php
// Ver próxima ejecución
wp_next_scheduled('mia_automatic_stock_detection');
```

---

### 2. **Hooks de WooCommerce - Sincronización en Tiempo Real** ⚠️ ACTIVO

**Ubicación**: `includes/Hooks/SyncHooks.php`

**Hooks registrados**:
- `woocommerce_update_product` → `on_product_updated()`
- `woocommerce_new_product` → `on_product_created()`
- `woocommerce_trash_product` → `on_product_deleted()`

**Qué hace**:
- Se ejecuta automáticamente cuando:
  - Se crea un producto en WooCommerce
  - Se actualiza un producto en WooCommerce
  - Se elimina un producto en WooCommerce

**Problema potencial**:
- Si hay scripts o plugins que crean productos masivamente, esto puede disparar sincronizaciones automáticas
- Si hay imports masivos, cada producto dispara una sincronización

**Cómo desactivar**:
```php
// En functions.php o en un plugin
remove_action('woocommerce_update_product', ['MiIntegracionApi\Hooks\SyncHooks', 'on_product_updated']);
remove_action('woocommerce_new_product', ['MiIntegracionApi\Hooks\SyncHooks', 'on_product_created']);
remove_action('woocommerce_trash_product', ['MiIntegracionApi\Hooks\SyncHooks', 'on_product_deleted']);
```

---

### 3. **Cron Job de Sincronización Diaria** ✅ DESACTIVADO

**Ubicación**: `includes/Hooks/SyncHooks.php`

**Cron Hook**: `mi_integracion_api_daily_sync`

**Estado**: **COMENTADO/DESACTIVADO** según el código:
```php
// Hook de sincronización diaria eliminado - solo sincronización manual
```

**Verificar si está programado**:
```php
$next_scheduled = wp_next_scheduled('mi_integracion_api_daily_sync');
if ($next_scheduled) {
    echo "Está programado para: " . date('Y-m-d H:i:s', $next_scheduled);
} else {
    echo "No está programado";
}
```

---

### 4. **BatchProcessor::executeBatchSync()** ⚠️ VERIFICAR

**Ubicación**: `includes/Core/BatchProcessor.php` línea 6658

**Qué hace**:
- Ejecuta sincronización de productos pendientes
- Ejecuta sincronización de clientes pendientes
- Usa `batch_mode => true`

**Cuándo se ejecuta**: Buscar dónde se llama este método

**Búsqueda**:
```bash
grep -r "executeBatchSync\|BatchProcessor.*sync" includes/
```

---

## 🔍 Cómo Verificar Todas las Sincronizaciones Activas

### Script SQL para Verificar Cron Jobs

```sql
-- Ver todos los cron jobs relacionados con el plugin
SELECT 
    option_name,
    option_value
FROM wp_options
WHERE option_name LIKE '%mia%'
   OR option_name LIKE '%verial%'
   OR option_name LIKE '%sync%'
ORDER BY option_name;
```

### Script PHP para Verificar Cron Jobs

```php
<?php
require_once('wp-load.php');

echo "=== CRON JOBS RELACIONADOS CON VERIAL ===\n\n";

$cron_hooks = [
    'mia_automatic_stock_detection',
    'mi_integracion_api_daily_sync',
    'mia_execute_async_cleanup',
    'mia_automatic_lock_cleanup',
    'mia_automatic_heartbeat',
    'mia_cleanup_transients',
    'mi_integracion_api_clean_expired_cache',
    'verial_daily_maintenance',
    'mia_auto_memory_cleanup',
    'miapi_ssl_save_latency_stats',
    'miapi_ssl_certificate_rotation'
];

foreach ($cron_hooks as $hook) {
    $timestamp = wp_next_scheduled($hook);
    if ($timestamp) {
        echo "✅ $hook: " . date('Y-m-d H:i:s', $timestamp) . "\n";
    } else {
        echo "❌ $hook: No programado\n";
    }
}

echo "\n=== OPCIONES DE CONFIGURACIÓN ===\n\n";

$options = [
    'mia_automatic_stock_detection_enabled',
    'mia_automatic_heartbeat',
    'mia_auto_sync'
];

foreach ($options as $option) {
    $value = get_option($option, 'NO CONFIGURADO');
    echo "$option: " . ($value ? 'true' : 'false') . "\n";
}
```

---

## 🎯 Verificación Rápida

### 1. Verificar StockDetector

```bash
# Desde WP-CLI
wp option get mia_automatic_stock_detection_enabled

# Ver próxima ejecución
wp cron event list | grep stock
```

### 2. Verificar Hooks de WooCommerce

```php
// Verificar si los hooks están registrados
has_action('woocommerce_update_product', ['MiIntegracionApi\Hooks\SyncHooks', 'on_product_updated']);
has_action('woocommerce_new_product', ['MiIntegracionApi\Hooks\SyncHooks', 'on_product_created']);
```

### 3. Verificar Todos los Cron Jobs

```bash
# Desde WP-CLI
wp cron event list

# Buscar específicamente los relacionados con Verial
wp cron event list | grep -i "mia\|verial\|sync"
```

---

## 🛠️ Cómo Desactivar Todas las Sincronizaciones Automáticas

### Script de Desactivación Completa

```php
<?php
/**
 * Script para desactivar TODAS las sincronizaciones automáticas
 * 
 * USO: wp eval-file desactivar-sync-automaticas.php
 */

require_once('wp-load.php');

echo "Desactivando sincronizaciones automáticas...\n\n";

// 1. Desactivar StockDetector
update_option('mia_automatic_stock_detection_enabled', false);
wp_clear_scheduled_hook('mia_automatic_stock_detection');
echo "✅ StockDetector desactivado\n";

// 2. Eliminar cron job de sincronización diaria (por si acaso)
wp_clear_scheduled_hook('mi_integracion_api_daily_sync');
echo "✅ Cron diario eliminado\n";

// 3. Desactivar auto-sync general
update_option('mia_auto_sync', false);
echo "✅ Auto-sync general desactivado\n";

// 4. Desactivar heartbeat automático
update_option('mia_automatic_heartbeat', false);
echo "✅ Heartbeat automático desactivado\n";

// 5. Verificar que no quedan cron jobs activos
$cron_hooks = [
    'mia_automatic_stock_detection',
    'mi_integracion_api_daily_sync',
    'mia_execute_async_cleanup',
    'mia_automatic_lock_cleanup',
    'mia_automatic_heartbeat'
];

echo "\nVerificando cron jobs restantes:\n";
foreach ($cron_hooks as $hook) {
    if (wp_next_scheduled($hook)) {
        echo "⚠️  $hook todavía está programado\n";
        wp_clear_scheduled_hook($hook);
        echo "   → Eliminado\n";
    }
}

echo "\n✅ Todas las sincronizaciones automáticas han sido desactivadas\n";
```

---

## 📊 Impacto en los 16,000 Productos

### Posibles Causas

1. **StockDetector activo cada 5 minutos**:
   - Si está activo, sincroniza productos automáticamente
   - Puede crear duplicados si la detección de SKUs no funciona

2. **Hooks de WooCommerce**:
   - Si hay un script que crea productos masivamente
   - Cada producto dispara `on_product_created()`
   - Puede crear productos duplicados si la verificación de SKU falla

3. **Múltiples procesos simultáneos**:
   - Si hay múltiples sincronizaciones ejecutándose al mismo tiempo
   - Condiciones de carrera pueden crear duplicados

---

## ✅ Recomendación Inmediata

1. **Verificar StockDetector**:
   ```bash
   wp option get mia_automatic_stock_detection_enabled
   ```

2. **Si está activo, desactivarlo temporalmente**:
   ```bash
   wp option update mia_automatic_stock_detection_enabled false
   wp cron event delete mia_automatic_stock_detection
   ```

3. **Verificar hooks de WooCommerce**:
   - Revisar logs para ver si hay muchas ejecuciones de `on_product_created`
   - Considerar desactivarlos temporalmente

4. **Verificar todos los cron jobs**:
   ```bash
   wp cron event list | grep -i "mia\|verial\|sync"
   ```

---

## 🔗 Archivos Relacionados

- `includes/Deteccion/StockDetector.php` - Detección automática de stock
- `includes/Hooks/SyncHooks.php` - Hooks de sincronización
- `includes/Hooks/RobustnessHooks.php` - Tareas programadas generales
- `includes/Core/BatchProcessor.php` - Procesamiento por lotes


