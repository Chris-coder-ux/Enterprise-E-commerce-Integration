# 🚀 Estrategias de Optimización para Sincronización y Mapeo

**Fecha**: 2025-11-01  
**Base**: Investigación web + Análisis del código existente  
**Objetivo**: Mejorar el rendimiento de sincronización y mapeo de productos

---

## 📊 Estado Actual

### Problemas Identificados

1. **Obtención de Imágenes**: 15 segundos (78.9% del tiempo total)
   - 50 llamadas secuenciales a la API
   - Cada llamada ~0.3 segundos

2. **Mapeo de Productos**: Procesamiento secuencial
   - Sin paralelización
   - Transformación de datos línea por línea

3. **Consultas a Base de Datos**: Múltiples consultas individuales
   - WooCommerce queries no optimizadas
   - Sin batch operations para múltiples productos

---

## 🎯 Estrategias de Optimización Propuestas

### 1. ⚡ Optimización: Llamadas Paralelas a la API

#### Técnica: `curl_multi` para Paralelismo

**Problema Actual**:
```php
// Llamadas secuenciales (lento)
foreach ($product_ids as $product_id) {
    $response = $this->apiConnector->get('GetImagenesArticulosWS', $params);
    // ~0.3s por llamada = 15s total
}
```

**Solución Propuesta**:
```php
protected function get_imagenes_for_products_parallel(array $product_ids, int $concurrency = 10): SyncResponseInterface {
    $all_imagenes = [];
    $errors = [];
    
    // Dividir en chunks para control de concurrencia
    $chunks = array_chunk($product_ids, $concurrency);
    
    foreach ($chunks as $chunk) {
        $multi_handle = curl_multi_init();
        $curl_handles = [];
        
        // Preparar todas las llamadas del chunk
        foreach ($chunk as $product_id) {
            $url = $this->build_api_url('GetImagenesArticulosWS', [
                'x' => $this->apiConnector->get_session_number(),
                'id_articulo' => $product_id,
                'numpixelsladomenor' => 300
            ]);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10
            ]);
            
            curl_multi_add_handle($multi_handle, $ch);
            $curl_handles[$product_id] = $ch;
        }
        
        // Ejecutar todas las llamadas en paralelo
        $running = null;
        do {
            curl_multi_exec($multi_handle, $running);
            curl_multi_select($multi_handle, 0.1);
        } while ($running > 0);
        
        // Procesar respuestas
        foreach ($curl_handles as $product_id => $ch) {
            $response_data = curl_multi_getcontent($ch);
            // Procesar respuesta...
            curl_multi_remove_handle($multi_handle, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($multi_handle);
    }
    
    return ResponseFactory::success(['Imagenes' => $all_imagenes]);
}
```

**Impacto Esperado**:
- **Tiempo actual**: 15 segundos (50 llamadas × 0.3s)
- **Tiempo optimizado**: 2-3 segundos (5 chunks × 0.5s)
- **Mejora**: 80-85% de reducción

**Ventajas**:
- ✅ Mantiene control sobre concurrencia (no sobrecarga API)
- ✅ Manejo de errores granular
- ✅ Compatible con código existente

**Consideraciones**:
- ⚠️ Requiere `curl_multi` (disponible en PHP 5.5+)
- ⚠️ Más complejo que llamadas secuenciales
- ⚠️ Necesita testing exhaustivo

---

### 2. 💾 Optimización: Batch Operations en WooCommerce

#### Técnica: Operaciones Masivas en WordPress

**Problema Actual**:
```php
// Múltiples consultas individuales (lento)
foreach ($products as $product) {
    $wc_product = wc_get_product($sku); // Query individual
    if (!$wc_product) {
        $wc_product = new WC_Product(); // Crear uno por uno
    }
    $wc_product->save(); // Save individual
}
```

**Solución Propuesta**:
```php
protected function batch_update_products(array $products): void {
    global $wpdb;
    
    // Preparar datos para inserción/actualización masiva
    $values = [];
    $placeholders = [];
    
    foreach ($products as $product) {
        $values[] = $product['sku'];
        $values[] = $product['name'];
        $values[] = $product['price'];
        // ... más campos
        
        $placeholders[] = "(%s, %s, %d, ...)";
    }
    
    // Query masivo optimizado
    $query = "INSERT INTO {$wpdb->posts} (post_title, post_status, ...) 
              VALUES " . implode(',', $placeholders) . "
              ON DUPLICATE KEY UPDATE post_title=VALUES(post_title), ...";
    
    $wpdb->query($wpdb->prepare($query, ...$values));
    
    // Actualizar metadatos en batch
    $this->batch_update_meta($products);
}

protected function batch_update_meta(array $products): void {
    global $wpdb;
    
    // Agrupar metadatos por producto_id
    $meta_updates = [];
    foreach ($products as $product) {
        $meta_updates[$product['id']][] = [
            'meta_key' => 'verial_id',
            'meta_value' => $product['verial_id']
        ];
    }
    
    // Query masivo de metadatos
    $values = [];
    foreach ($meta_updates as $product_id => $metas) {
        foreach ($metas as $meta) {
            $values[] = $product_id;
            $values[] = $meta['meta_key'];
            $values[] = $meta['meta_value'];
        }
    }
    
    // INSERT ... ON DUPLICATE KEY UPDATE masivo
    // ...
}
```

**Impacto Esperado**:
- **Consultas actuales**: 50 productos = 100+ queries
- **Consultas optimizadas**: 50 productos = 2-3 queries
- **Mejora**: 95%+ reducción de queries

**Ventajas**:
- ✅ Reduce carga en base de datos
- ✅ Transacciones más rápidas
- ✅ Menor uso de memoria

**Consideraciones**:
- ⚠️ Bypassa hooks de WooCommerce (puede afectar funcionalidades)
- ⚠️ Requiere manejo manual de transacciones
- ⚠️ Necesita validación exhaustiva

---

### 3. 🗂️ Optimización: Caché Inteligente con Estrategias Avanzadas

#### Técnica: Multi-Level Caching

**Ya Implementado**:
- ✅ Caché de datos globales (categorías, fabricantes)
- ✅ Caché de batch data
- ✅ TTL diferenciado por tipo de dato

**Mejora Propuesta**:
```php
/**
 * Sistema de caché multi-nivel con invalidación inteligente
 */
class AdvancedCacheManager {
    
    // Nivel 1: Memory cache (array en memoria)
    private static $memory_cache = [];
    
    // Nivel 2: Transient cache (WordPress)
    // Nivel 3: Persistent cache (opcional: Redis, Memcached)
    
    public function get_with_fallback(string $key, callable $fetch, int $ttl = 3600): mixed {
        // 1. Intentar memory cache
        if (isset(self::$memory_cache[$key])) {
            return self::$memory_cache[$key];
        }
        
        // 2. Intentar transient cache
        $cached = get_transient($key);
        if ($cached !== false) {
            self::$memory_cache[$key] = $cached;
            return $cached;
        }
        
        // 3. Fetch y cache en todos los niveles
        $data = $fetch();
        self::$memory_cache[$key] = $data;
        set_transient($key, $data, $ttl);
        
        return $data;
    }
    
    /**
     * Caché de resultados parciales (para imágenes)
     */
    public function cache_product_images(int $product_id, array $images): void {
        // Cachear imágenes por producto individual
        $key = "product_images_{$product_id}";
        set_transient($key, $images, 7200); // 2 horas
        
        // También cachear en memoria para este request
        self::$memory_cache[$key] = $images;
    }
    
    /**
     * Invalidación selectiva
     */
    public function invalidate_product_cache(int $product_id): void {
        delete_transient("product_images_{$product_id}");
        delete_transient("product_data_{$product_id}");
        unset(self::$memory_cache["product_images_{$product_id}"]);
    }
}
```

**Impacto Esperado**:
- **Consultas a API reducidas**: 70-80%
- **Tiempo de respuesta**: 30-40% más rápido en requests repetidos

**Ventajas**:
- ✅ Reducción drástica de llamadas a API
- ✅ Mejor experiencia de usuario
- ✅ Menor carga en servidor externo

---

### 4. 📦 Optimización: Procesamiento en Queue/Background

#### Técnica: WordPress Action Scheduler

**Problema Actual**:
- Sincronización síncrona (bloquea ejecución)
- Timeout de PHP puede interrumpir proceso

**Solución Propuesta**:
```php
use ActionScheduler_Store;
use ActionScheduler;

/**
 * Programar sincronización en background
 */
public function schedule_batch_sync(int $batch_number, int $total_batches): void {
    $args = [
        'batch_number' => $batch_number,
        'total_batches' => $total_batches,
        'timestamp' => time()
    ];
    
    // Usar Action Scheduler (si está disponible)
    if (class_exists('ActionScheduler')) {
        as_schedule_single_action(
            time() + ($batch_number * 5), // Espaciar batches por 5 segundos
            'mi_integracion_sync_batch',
            [$args]
        );
    } else {
        // Fallback: WordPress Cron
        wp_schedule_single_event(
            time() + ($batch_number * 5),
            'mi_integracion_sync_batch',
            [$args]
        );
    }
}

/**
 * Procesar batch en background
 */
public function process_scheduled_batch(array $args): void {
    $batch_number = $args['batch_number'];
    $inicio = ($batch_number - 1) * 50 + 1;
    $fin = $batch_number * 50;
    
    // Procesar batch sin bloquear
    $this->processProductsWithPreparedBatch($inicio, $fin, ...);
    
    // Programar siguiente batch si existe
    if ($args['batch_number'] < $args['total_batches']) {
        $this->schedule_batch_sync($batch_number + 1, $args['total_batches']);
    }
}
```

**Impacto Esperado**:
- **Tiempo de respuesta del usuario**: Inmediato
- **Procesamiento**: En background sin timeouts
- **Experiencia**: No bloquea interfaz

**Ventajas**:
- ✅ No bloquea ejecución principal
- ✅ Permite procesar grandes volúmenes
- ✅ Recuperación automática de fallos

**Consideraciones**:
- ⚠️ Requiere Action Scheduler plugin o implementación custom
- ⚠️ Más complejo de debuggear
- ⚠️ Necesita sistema de monitoreo

---

### 5. 🔄 Optimización: Incremental Sync

#### Técnica: Sincronización Basada en Timestamps

**Problema Actual**:
- Sincronización completa cada vez
- Procesa todos los productos aunque no hayan cambiado

**Solución Propuesta**:
```php
/**
 * Sincronización incremental basada en fecha de modificación
 */
public function sync_incremental(?string $since_date = null): void {
    // Si no hay fecha, usar última sincronización exitosa
    if ($since_date === null) {
        $since_date = get_option('mi_integracion_last_sync', date('Y-m-d H:i:s', strtotime('-7 days')));
    }
    
    // Obtener solo productos modificados desde fecha
    $params = [
        'x' => $this->apiConnector->get_session_number(),
        'fecha' => date('Y-m-d', strtotime($since_date)),
        'hora' => date('H:i:s', strtotime($since_date))
    ];
    
    $response = $this->apiConnector->get('GetArticulosWS', $params);
    // Solo procesar productos modificados
    
    // Actualizar timestamp de última sincronización
    update_option('mi_integracion_last_sync', current_time('mysql'));
}
```

**Impacto Esperado**:
- **Productos a sincronizar**: 95%+ reducción (solo modificados)
- **Tiempo de sincronización**: 90%+ más rápido en syncs subsecuentes

**Ventajas**:
- ✅ Sincronización mucho más rápida
- ✅ Menor carga en servidor
- ✅ Datos siempre actualizados

**Consideraciones**:
- ⚠️ Requiere que API soporte filtros por fecha (ya implementado)
- ⚠️ Primera sincronización siempre debe ser completa

---

### 6. 🔍 Optimización: Query Optimization en WooCommerce

#### Técnica: Prepared Queries y Batch Updates

**Problema Actual**:
```php
// Múltiples queries individuales
$product = wc_get_product($sku); // SELECT individual
$product->set_price($price); 
$product->save(); // UPDATE individual
```

**Solución Propuesta**:
```php
/**
 * Optimizar queries usando WP_Query con parámetros batch
 */
protected function batch_get_products_by_sku(array $skus): array {
    global $wpdb;
    
    $placeholders = implode(',', array_fill(0, count($skus), '%s'));
    $query = $wpdb->prepare(
        "SELECT p.ID, pm.meta_value as sku
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         WHERE pm.meta_key = '_sku'
         AND pm.meta_value IN ($placeholders)
         AND p.post_type = 'product'",
        ...$skus
    );
    
    $results = $wpdb->get_results($query, OBJECT_K);
    
    // Pre-cargar objetos WC_Product solo para los encontrados
    $products = [];
    foreach ($results as $id => $row) {
        $products[$row->sku] = wc_get_product($id);
    }
    
    return $products;
}
```

**Impacto Esperado**:
- **Queries actuales**: N queries (una por SKU)
- **Queries optimizadas**: 1 query para todos los SKUs
- **Mejora**: 95%+ reducción

---

### 7. 🧠 Optimización: Memory Management

#### Técnica: Unset y Garbage Collection

**Problema Actual**:
- Arrays grandes se mantienen en memoria
- Sin limpieza entre batches

**Solución Propuesta**:
```php
protected function process_batch_with_memory_management(int $inicio, int $fin): void {
    // Procesar batch
    $batch_data = $this->prepare_complete_batch_data($inicio, $fin);
    
    foreach ($batch_data['productos'] as $index => $product) {
        // Procesar producto
        $this->process_single_product($product, $batch_data);
        
        // Limpiar memoria cada 10 productos
        if ($index % 10 === 0) {
            unset($batch_data['productos'][$index - 10]);
            gc_collect_cycles(); // Forzar garbage collection
        }
    }
    
    // Limpiar al final
    unset($batch_data);
    gc_collect_cycles();
}
```

**Impacto Esperado**:
- **Uso de memoria**: 30-40% reducción
- **Estabilidad**: Menor probabilidad de timeouts por memoria

---

### 8. 📊 Optimización: Data Preprocessing

#### Técnica: Transformación de Datos Optimizada

**Problema Actual**:
- Transformación de datos en tiempo de mapeo
- Cálculos repetitivos

**Solución Propuesta**:
```php
/**
 * Preprocesar y cachear datos transformados
 */
protected function preprocess_batch_data(array $raw_batch_data): array {
    $processed = [];
    
    // Pre-calcular índices para búsquedas O(1)
    $processed['stock_index'] = [];
    foreach ($raw_batch_data['stock_productos'] as $stock) {
        $processed['stock_index'][$stock['ID_Articulo']] = $stock;
    }
    
    $processed['images_index'] = [];
    foreach ($raw_batch_data['imagenes_productos'] as $image) {
        $processed['images_index'][$image['ID_Articulo']][] = $image;
    }
    
    $processed['prices_index'] = [];
    foreach ($raw_batch_data['condiciones_tarifa'] as $price) {
        $processed['prices_index'][$price['ID_Articulo']] = $price;
    }
    
    return array_merge($raw_batch_data, $processed);
}
```

**Impacto Esperado**:
- **Búsquedas**: De O(n) a O(1)
- **Tiempo de mapeo**: 40-50% más rápido

---

## 🎯 Plan de Implementación Recomendado

### Fase 1: Quick Wins (Implementar Primero) ⚡

1. **Llamadas Paralelas para Imágenes** (Alto Impacto, Medio Esfuerzo)
   - Implementar `get_imagenes_for_products_parallel()`
   - Testing exhaustivo
   - **Impacto**: 15s → 2-3s (80% mejora)

2. **Data Preprocessing** (Alto Impacto, Bajo Esfuerzo)
   - Pre-calcular índices de búsqueda
   - **Impacto**: 40-50% mejora en mapeo

### Fase 2: Optimizaciones de Base de Datos 🗄️

3. **Batch Queries en WooCommerce** (Alto Impacto, Alto Esfuerzo)
   - Implementar batch operations
   - **Impacto**: 95% reducción de queries

4. **Query Optimization** (Medio Impacto, Bajo Esfuerzo)
   - Optimizar queries existentes
   - **Impacto**: 30-40% mejora

### Fase 3: Arquitectura Avanzada 🏗️

5. **Background Processing** (Alto Impacto, Alto Esfuerzo)
   - Implementar queue system
   - **Impacto**: Elimina timeouts, mejor UX

6. **Incremental Sync** (Alto Impacto, Medio Esfuerzo)
   - Sincronización solo de cambios
   - **Impacto**: 90%+ mejora en syncs subsecuentes

7. **Advanced Caching** (Medio Impacto, Bajo Esfuerzo)
   - Multi-level caching
   - **Impacto**: 30-40% mejora en requests repetidos

---

## 📈 Impacto Total Esperado

| Optimización | Tiempo Actual | Tiempo Optimizado | Mejora |
|--------------|---------------|-------------------|--------|
| **Obtención Imágenes** | 15s | 2-3s | 80-85% |
| **Mapeo de Productos** | 10s | 5-6s | 40-50% |
| **Queries DB** | 5s | 0.5s | 90% |
| **Tiempo Total (50 productos)** | ~44s | **~8-10s** | **75-80%** |

---

## 🔬 Consideraciones Técnicas

### Requisitos

- ✅ PHP 7.4+ (para `curl_multi`)
- ✅ Extensión `curl` habilitada
- ✅ Memoria PHP suficiente (128MB+ recomendado)
- ✅ Action Scheduler (opcional, para background processing)

### Testing

Cada optimización debe incluir:
1. ✅ Tests unitarios
2. ✅ Tests de integración
3. ✅ Tests de rendimiento (benchmarks)
4. ✅ Tests de regresión

### Monitoreo

Implementar métricas para:
- Tiempo de sincronización por batch
- Número de llamadas a API
- Uso de memoria
- Errores y fallos

---

**Fecha de Documento**: 2025-11-01  
**Próximos Pasos**: Implementar Fase 1 (Quick Wins)

