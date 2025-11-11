# 🔍 Análisis: Procesamiento de Imágenes como Causa del Timeout

**Fecha**: 2025-11-04  
**Problema**: Error "Lock wait timeout exceeded" en Action Scheduler  
**Causa Raíz Identificada**: Procesamiento de imágenes dentro de transacciones largas

---

## 🎯 Problema Identificado

### Flujo Actual (PROBLEMÁTICO)

1. **Transacción de base de datos se abre** (línea 858 de BatchProcessor.php):
   ```php
   $transactionManager->beginTransaction("batch_processing", $operationId);
   ```

2. **Se procesan 50 productos** en el batch

3. **Para cada producto, se procesan imágenes DENTRO de la transacción**:
   - `wp_insert_attachment()` → INSERT en `wp_posts` + INSERT en `wp_postmeta`
   - `wp_generate_attachment_metadata()` → **PROCESA LA IMAGEN** (redimensiona, genera thumbnails)
   - `wp_update_attachment_metadata()` → UPDATE en `wp_postmeta`
   - `set_post_thumbnail()` → UPDATE en `wp_postmeta`
   - `update_post_meta()` para galería → UPDATE en `wp_postmeta`

4. **Transacción se cierra** solo al final del batch (línea 932)

### Cálculo del Problema

**Escenario típico**:
- Batch de 50 productos
- Cada producto tiene 5 imágenes promedio
- **Total: 250 operaciones de imágenes**

**Operaciones de base de datos por imagen**:
- `wp_insert_attachment()`: ~2-3 queries (INSERT posts + INSERT postmeta)
- `wp_generate_attachment_metadata()`: Procesamiento CPU (100-500ms)
- `wp_update_attachment_metadata()`: ~5-10 queries (UPDATE postmeta múltiples veces)
- `set_post_thumbnail()`: ~2 queries (UPDATE postmeta)
- `update_post_meta()` para galería: ~1 query

**Total por imagen: ~10-15 queries de base de datos**  
**Total por batch: 250 imágenes × 12 queries = ~3,000 queries DENTRO de UNA transacción**

**Tiempo estimado**:
- Procesamiento de imagen: 200-500ms (especialmente `wp_generate_attachment_metadata`)
- Queries de base de datos: ~50ms total por imagen
- **Tiempo total por batch: 30-60 segundos con la transacción abierta**

---

## ⚠️ Por Qué Esto Causa el Error

### Problema 1: Transacciones Muy Largas

La transacción se mantiene abierta durante **30-60 segundos** mientras se procesan todas las imágenes. Esto bloquea recursos en la base de datos.

### Problema 2: Múltiples Batches Simultáneos

Si WordPress Cron ejecuta múltiples batches acumulados:
- Batch 1: Transacción abierta 40 segundos procesando imágenes
- Batch 2: Intenta abrir transacción mientras Batch 1 está activa
- Batch 3: Intenta abrir transacción mientras Batch 1 y 2 están activas
- **Resultado**: Competencia por locks en `wp_posts` y `wp_postmeta`

### Problema 3: Locks en Tablas Compartidas

Todas las imágenes se guardan en:
- `wp_posts` (tabla de posts/attachments)
- `wp_postmeta` (metadatos)
- **Múltiples batches = múltiples procesos intentando escribir en las mismas tablas simultáneamente**

### Problema 4: Action Scheduler También Usa Estas Tablas

Action Scheduler guarda sus acciones en:
- `wp_posts` (tipo 'scheduled-action')
- `wp_postmeta` (metadatos de acciones)

**Conflicto**: El procesamiento de imágenes y Action Scheduler compiten por locks en las mismas tablas.

---

## 🔧 Soluciones Propuestas

### Solución 1: Procesar Imágenes FUERA de la Transacción (RECOMENDADO)

**Cambio en `BatchProcessor.php`**:

```php
// ANTES (línea 4488-4515):
private function handlePostSaveOperations(...) {
    // Esto se ejecuta DENTRO de la transacción
    $this->setProductImages($product_id, $wc_product_data['images']);
    $this->setProductGallery($product_id, $wc_product_data['gallery']);
}

// DESPUÉS (separar transacciones):
private function handlePostSaveOperations(...) {
    // Guardar producto (transacción corta)
    // ... código de guardado de producto ...
    
    // CERRAR transacción antes de procesar imágenes
    $transactionManager->commit("batch_processing", $operationId);
    
    // Procesar imágenes FUERA de la transacción principal
    $this->setProductImages($product_id, $wc_product_data['images']);
    $this->setProductGallery($product_id, $wc_product_data['gallery']);
}
```

**Ventajas**:
- ✅ Transacciones más cortas (solo guardado de producto)
- ✅ Imágenes no bloquean la transacción principal
- ✅ Menor competencia por locks

**Desventajas**:
- ⚠️ Si falla el procesamiento de imágenes, el producto ya está guardado (pero esto es aceptable)

### Solución 2: Procesar Imágenes de Forma Asíncrona

**Cambiar flujo**:
1. Guardar producto (sin imágenes)
2. Programar procesamiento de imágenes en background
3. Asignar imágenes después

**Ventajas**:
- ✅ No bloquea sincronización principal
- ✅ Puede ejecutarse en paralelo
- ✅ Menor carga en base de datos

**Desventajas**:
- ⚠️ Imágenes aparecen después del producto
- ⚠️ Más complejo de implementar

### Solución 3: Reducir Tamaño de Batch cuando hay Imágenes

**Ajustar dinámicamente**:
```php
// Si hay muchas imágenes, reducir tamaño de batch
$image_count = count($batch_data['imagenes_productos'] ?? []);
if ($image_count > 100) {
    $batch_size = max(10, $batch_size / 2); // Reducir a la mitad
}
```

### Solución 4: Desactivar Generación de Thumbnails Temporalmente

**Para sincronizaciones masivas**:
```php
// Antes de procesar imágenes
add_filter('intermediate_image_sizes', '__return_empty_array');

// Procesar imágenes (sin thumbnails)

// Después
remove_filter('intermediate_image_sizes', '__return_empty_array');
```

**Ventajas**:
- ✅ `wp_generate_attachment_metadata()` es mucho más rápido
- ✅ Menos operaciones de base de datos

**Desventajas**:
- ⚠️ Thumbnails se generan después (puede causar problemas temporales)

---

## 📊 Impacto Estimado

### Escenario Actual (Con Problema)

| Métrica | Valor |
|---------|-------|
| Tiempo de transacción | 30-60 segundos |
| Queries en transacción | ~3,000 |
| Locks mantenidos | 30-60 segundos |
| Competencia | Alta (múltiples batches) |

### Escenario Optimizado (Solución 1)

| Métrica | Valor |
|---------|-------|
| Tiempo de transacción principal | 5-10 segundos |
| Imágenes procesadas fuera | Sí |
| Locks mantenidos | 5-10 segundos |
| Competencia | Baja |

**Reducción de locks**: **80-85%**  
**Reducción de competencia**: **Significativa**

---

## ✅ Recomendación Final

**Implementar Solución 1 + Aumentar Timeout de MySQL**:

1. **Corto plazo**: Aumentar timeout de MySQL a 60 segundos
2. **Medio plazo**: Mover procesamiento de imágenes fuera de la transacción principal
3. **Largo plazo**: Considerar procesamiento asíncrono de imágenes

Esto resuelve el problema sin cambiar la funcionalidad, solo optimizando el orden de operaciones.

