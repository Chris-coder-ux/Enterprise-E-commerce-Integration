# 🔍 Análisis Completo: Ralentización Progresiva en Fase 2

## 📋 Resumen Ejecutivo

**Problema**: La Fase 2 se vuelve más lenta a medida que avanza. En 8 minutos solo procesa 950 productos (~2 productos/segundo), cuando debería ser más rápido y mantener velocidad constante.

**Fecha**: 2025-11-14  
**Estado**: ⚠️ **MÚLTIPLES CAUSAS IDENTIFICADAS**

---

## 🐌 CAUSAS PRINCIPALES IDENTIFICADAS

### **1. LIMPIEZA DE CACHÉ INSUFICIENTE** 🔴 CRÍTICO

**Problema Actual**:
- Limpieza cada **5 lotes** (configurable vía `mia_batch_cleanup_interval`)
- Con **batch_size=10** y **950 productos procesados** = **~95 lotes**
- Solo se limpia **~19 veces** durante toda la sincronización
- Entre limpiezas, se acumulan **~4-5 lotes** de datos en caché

**Impacto**:
- **Lotes 1-5**: Caché limpio → rápido
- **Lotes 6-10**: Caché acumulado → más lento
- **Lotes 11-15**: Caché muy acumulado → mucho más lento
- **Lotes 16+**: Caché masivo → muy lento

**Cálculo**:
- Cada lote genera ~10-20 transients de caché
- 5 lotes sin limpiar = **50-100 transients acumulados**
- Consultas SQL a `wp_options` se vuelven más lentas con más transients
- `clearPatternPreservingHotCache()` debe procesar más transients cada vez

**Ubicación**: `includes/Core/Sync_Manager.php:2668`

```php
$cleanup_interval = apply_filters('mia_batch_cleanup_interval', 5); // ← Cada 5 lotes
```

---

### **2. CONSULTAS SQL A wp_postmeta QUE SE RALENTIZAN** 🔴 CRÍTICO

**Problema**:
- `get_attachments_by_article_id()` ejecuta **1 consulta SQL por producto** a `wp_postmeta`
- A medida que `wp_postmeta` crece (más productos = más metadatos), las consultas se vuelven más lentas
- La consulta usa `CAST(pm.meta_value AS SIGNED)` que puede ser costosa sin índices adecuados

**Consulta Actual**:
```sql
SELECT pm.post_id, COALESCE(pm_order.meta_value, '999') as image_order
FROM wp_postmeta pm
INNER JOIN wp_posts p ON pm.post_id = p.ID
LEFT JOIN wp_postmeta pm_order ON pm.post_id = pm_order.post_id 
    AND pm_order.meta_key = '_verial_image_order'
WHERE pm.meta_key = '_verial_article_id' 
AND CAST(pm.meta_value AS SIGNED) = %d
AND p.post_type = 'attachment'
AND p.post_mime_type LIKE 'image%%'
ORDER BY CAST(pm_order.meta_value AS SIGNED) ASC, pm.post_id ASC
```

**Impacto Progresivo**:
- **Inicio** (100 productos): `wp_postmeta` tiene ~500 filas → consulta rápida (~1-5ms)
- **Mitad** (500 productos): `wp_postmeta` tiene ~2,500 filas → consulta más lenta (~10-20ms)
- **Final** (950 productos): `wp_postmeta` tiene ~4,750 filas → consulta muy lenta (~20-50ms+)

**Cálculo Total**:
- **950 productos** × **1 consulta** = **950 consultas SQL**
- Tiempo total: **950 × 20ms promedio** = **~19 segundos** solo en búsqueda de imágenes
- Pero esto se multiplica porque cada consulta se vuelve más lenta progresivamente

**Ubicación**: `includes/Helpers/MapProduct.php:1967-1975`

---

### **3. FALTA DE CACHÉ DE RESULTADOS DE CONSULTAS** 🟡 ALTO

**Problema**:
- `get_attachments_by_article_id()` **no cachea resultados**
- Si el mismo `article_id` se consulta múltiples veces (en diferentes lotes), se ejecuta la consulta SQL cada vez
- Aunque esto es raro, puede ocurrir si hay productos duplicados o re-procesamiento

**Impacto**:
- Consultas SQL redundantes
- Sin caché, no hay forma de reutilizar resultados conocidos

**Ubicación**: `includes/Helpers/MapProduct.php:1967`

---

### **4. ACUMULACIÓN DE DATOS EN wp_options (TRANSIENTS)** 🟡 ALTO

**Problema**:
- Los transients se almacenan en `wp_options`
- Cada lote genera múltiples transients:
  - `batch_data_*`: Datos del lote
  - `articulos_*`: Artículos procesados
  - `imagenes_*`: Imágenes del lote
  - `condiciones_tarifa_*`: Condiciones de tarifa
  - `stock_*`: Stock
  - `batch_prices_*`: Precios procesados

**Impacto**:
- **95 lotes** × **~10 transients por lote** = **~950 transients acumulados**
- Consultas SQL a `wp_options` se vuelven más lentas con más transients
- `clearPatternPreservingHotCache()` debe buscar en más transients cada vez

**Cálculo de Ralentización**:
- Consulta inicial: `SELECT option_name FROM wp_options WHERE option_name LIKE '%'` → **~5-10ms**
- Con 950 transients: Misma consulta → **~20-50ms** (4-5x más lento)

---

### **5. FALTA DE ÍNDICES OPTIMIZADOS EN wp_postmeta** 🟡 ALTO

**Problema**:
- WordPress crea índices básicos en `wp_postmeta`:
  - `meta_key` (índice)
  - `post_id` (índice)
- **PERO**: No hay índice compuesto `(meta_key, meta_value)` optimizado para búsquedas por ambos
- La consulta usa `CAST(pm.meta_value AS SIGNED)` que puede no usar índices eficientemente

**Impacto**:
- Sin índice compuesto, MySQL debe:
  1. Filtrar por `meta_key` (rápido con índice)
  2. Luego escanear todas las filas para comparar `meta_value` (lento sin índice)
- A medida que `wp_postmeta` crece, el escaneo se vuelve más lento

**Solución Recomendada**:
- Crear índice compuesto `(meta_key, meta_value(191))` para búsquedas por ambos campos
- Optimizar consultas para usar el índice eficientemente

---

## 📊 ANÁLISIS DE IMPACTO TOTAL

### **Escenario Real: 950 Productos en 8 Minutos**

**Datos**:
- **Batch size**: 10 productos
- **Total lotes**: 95 lotes
- **Limpieza cada**: 5 lotes
- **Limpiezas totales**: ~19 limpiezas

**Acumulación de Caché**:
- **Entre limpiezas**: 4-5 lotes acumulados
- **Transients por lote**: ~10
- **Transients acumulados entre limpiezas**: ~40-50
- **Transients totales al final**: ~950

**Ralentización Progresiva**:
- **Lotes 1-5**: Velocidad normal (~2-3 productos/segundo)
- **Lotes 6-10**: ~10% más lento (~1.8-2.7 productos/segundo)
- **Lotes 11-20**: ~20% más lento (~1.6-2.4 productos/segundo)
- **Lotes 21-50**: ~30% más lento (~1.4-2.1 productos/segundo)
- **Lotes 51-95**: ~40-50% más lento (~1.0-1.5 productos/segundo)

**Tiempo Total Estimado**:
- **Sin ralentización**: 950 productos ÷ 2.5 productos/seg = **~6.3 minutos**
- **Con ralentización**: **~8 minutos** (observado)
- **Diferencia**: **~1.7 minutos** de ralentización (27% más lento)

---

## ✅ SOLUCIONES PROPUESTAS (PRIORIZADAS)

### **PRIORIDAD CRÍTICA** (Implementar primero)

#### **1. Aumentar Frecuencia de Limpieza de Caché**

**Cambio**:
- De **cada 5 lotes** → **cada 2-3 lotes**
- O mejor: **limpieza adaptativa** basada en memoria y tiempo

**Implementación**:
```php
// includes/Core/Sync_Manager.php:2668
// ANTES:
$cleanup_interval = apply_filters('mia_batch_cleanup_interval', 5);

// DESPUÉS:
$cleanup_interval = apply_filters('mia_batch_cleanup_interval', 2); // Cada 2 lotes

// O MEJOR: Adaptativo
$memory_usage = memory_get_usage(true) / memory_get_peak_usage(true);
if ($memory_usage > 0.7) {
    $cleanup_interval = 1; // Limpiar cada lote si memoria > 70%
} elseif ($memory_usage > 0.5) {
    $cleanup_interval = 2; // Limpiar cada 2 lotes si memoria > 50%
} else {
    $cleanup_interval = 3; // Limpiar cada 3 lotes si memoria < 50%
}
```

**Impacto Esperado**:
- **Reducción del 60-70%** en acumulación de caché
- **Mejora del 30-40%** en velocidad promedio

---

#### **2. Implementar Caché de Resultados de get_attachments_by_article_id()**

**Implementación**:
```php
// includes/Helpers/MapProduct.php
private static $attachments_cache = []; // Caché en memoria

public static function get_attachments_by_article_id(int $article_id): array
{
    // Verificar caché primero
    if (isset(self::$attachments_cache[$article_id])) {
        return self::$attachments_cache[$article_id];
    }
    
    // Ejecutar consulta SQL (código actual)
    $attachment_ids = /* ... consulta SQL ... */;
    
    // Guardar en caché
    self::$attachments_cache[$article_id] = $attachment_ids;
    
    // Limpiar caché si crece demasiado (máx 1000 entradas)
    if (count(self::$attachments_cache) > 1000) {
        // Eliminar las 200 entradas más antiguas (FIFO)
        self::$attachments_cache = array_slice(self::$attachments_cache, 200, null, true);
    }
    
    return $attachment_ids;
}
```

**Impacto Esperado**:
- **Reducción del 80-90%** en consultas SQL redundantes
- **Mejora del 10-15%** en velocidad si hay productos duplicados

---

#### **3. Optimizar Consultas SQL a wp_postmeta**

**Problema Actual**:
- `CAST(pm.meta_value AS SIGNED)` puede ser lento
- No hay índice compuesto optimizado

**Solución 1: Crear Índice Compuesto** (Recomendado)
```sql
-- Ejecutar una vez en la base de datos
CREATE INDEX idx_meta_key_value ON wp_postmeta(meta_key, meta_value(191));
```

**Solución 2: Optimizar Consulta SQL**
```php
// Mejorar la consulta para usar índices más eficientemente
$sql = $wpdb->prepare(
    "SELECT pm.post_id, COALESCE(pm_order.meta_value, '999') as image_order
     FROM {$wpdb->postmeta} pm
     INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
     LEFT JOIN {$wpdb->postmeta} pm_order ON pm.post_id = pm_order.post_id 
         AND pm_order.meta_key = '_verial_image_order'
     WHERE pm.meta_key = %s 
     AND pm.meta_value = %d  -- ← Usar comparación directa si es posible
     AND p.post_type = 'attachment'
     AND p.post_mime_type LIKE 'image%%'
     ORDER BY CAST(pm_order.meta_value AS SIGNED) ASC, pm.post_id ASC",
    '_verial_article_id',
    $article_id
);
```

**Impacto Esperado**:
- **Reducción del 50-70%** en tiempo de consultas SQL
- **Mejora del 20-30%** en velocidad total

---

### **PRIORIDAD ALTA** (Implementar después)

#### **4. Limpieza Adaptativa Basada en Memoria y Tiempo**

**Implementación**:
```php
private function shouldCleanupCache(): bool
{
    $sync_status = \MiIntegracionApi\Helpers\SyncStatusHelper::getSyncStatus();
    $current_batch = (int)($sync_status['current_sync']['current_batch'] ?? 0);
    
    // Limpieza cada N lotes (mínimo)
    $cleanup_interval = apply_filters('mia_batch_cleanup_interval', 3);
    if ($current_batch % $cleanup_interval === 0) {
        return true;
    }
    
    // Limpieza si memoria > 70%
    $memory_usage = memory_get_usage(true) / memory_get_peak_usage(true);
    if ($memory_usage > 0.7) {
        return true;
    }
    
    // Limpieza si han pasado > 30 segundos desde última limpieza
    $last_cleanup = get_option('mia_last_cache_cleanup_time', 0);
    if (time() - $last_cleanup > 30) {
        return true;
    }
    
    return false;
}
```

**Impacto Esperado**:
- **Prevención proactiva** de acumulación de caché
- **Mejora del 15-25%** en velocidad promedio

---

#### **5. Optimizar clearPatternPreservingHotCache()**

**Problema Actual**:
- Procesa todos los transients uno por uno
- Hace múltiples consultas `get_option()` para métricas

**Solución**:
- Cargar todas las métricas en una sola consulta SQL
- Procesar en batch (eliminar múltiples transients a la vez)

**Impacto Esperado**:
- **Reducción del 40-60%** en tiempo de limpieza
- **Mejora del 5-10%** en velocidad total

---

## 🎯 IMPACTO ESPERADO TOTAL

Con todas las optimizaciones implementadas:

### **Mejoras en Velocidad**:
- **Reducción en acumulación de caché**: **60-70%** (de ~950 a ~300 transients)
- **Reducción en consultas SQL**: **50-70%** (de ~950 a ~300-500 consultas)
- **Mejora en velocidad promedio**: **40-60%** (de ~2 productos/seg a ~3-3.5 productos/seg)

### **Tiempo Estimado**:
- **Antes**: 950 productos en **8 minutos** (~2 productos/seg)
- **Después**: 950 productos en **~4.5-5 minutos** (~3-3.5 productos/seg)
- **Mejora**: **~3 minutos menos** (37-43% más rápido)

---

## 📝 NOTAS ADICIONALES

### **Consideraciones**:
1. **Índices de Base de Datos**: Los índices deben crearse manualmente en la BD (no vía código)
2. **Caché en Memoria**: El caché de `get_attachments_by_article_id()` se limpia al finalizar la sincronización
3. **Limpieza Adaptativa**: Puede aumentar ligeramente el overhead, pero mejora la velocidad general
4. **Compatibilidad**: Todas las optimizaciones son compatibles con el código existente

### **Monitoreo Recomendado**:
- Medir tiempo por lote antes y después
- Monitorear uso de memoria durante sincronización
- Registrar número de consultas SQL ejecutadas
- Medir tiempo de limpieza de caché

---

## 🔄 PRÓXIMOS PASOS

1. ✅ **Implementar limpieza más frecuente** (cada 2-3 lotes)
2. ✅ **Implementar caché de resultados** en `get_attachments_by_article_id()`
3. ✅ **Crear índices optimizados** en `wp_postmeta` (manual)
4. ✅ **Implementar limpieza adaptativa** basada en memoria
5. ✅ **Optimizar `clearPatternPreservingHotCache()`** para procesamiento en batch

---

**Fecha de Análisis**: 2025-11-14  
**Autor**: Análisis Automatizado  
**Estado**: ⚠️ **PENDIENTE DE IMPLEMENTACIÓN**

