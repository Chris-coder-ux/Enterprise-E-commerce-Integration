# 🚨 Solución: Evitar Saturación de WordPress con 4900 Imágenes

**Problema**: Con 4900 imágenes, WordPress deja de funcionar correctamente debido a saturación de recursos.

**Objetivo**: Procesar las imágenes de forma que no sature WordPress, manteniendo el sistema funcional durante todo el proceso.

---

## 🔍 Análisis del Problema

### Causas de la Saturación

1. **Memoria agotada**
   - 4900 imágenes × ~2MB promedio = ~9.8GB de datos procesados
   - WordPress intenta mantener todo en memoria
   - PHP memory_limit se agota

2. **Base de datos sobrecargada**
   - Cada imagen genera múltiples queries:
     - `wp_insert_attachment()` → INSERT en `wp_posts`
     - `wp_generate_attachment_metadata()` → Múltiples INSERT en `wp_postmeta`
     - `wp_update_attachment_metadata()` → UPDATE en `wp_postmeta`
   - 4900 imágenes × ~15 queries = ~73,500 queries

3. **Generación de thumbnails**
   - WordPress genera múltiples tamaños por imagen (thumbnail, medium, large, etc.)
   - Cada thumbnail requiere procesamiento de imagen (CPU intensivo)
   - 4900 imágenes × 4 tamaños = ~19,600 imágenes a procesar

4. **Timeouts de ejecución**
   - Procesar 4900 imágenes puede tardar horas
   - PHP `max_execution_time` se agota
   - WordPress Cron puede fallar

---

## ✅ Soluciones Implementadas y Recomendadas

### 1. Reducir Batch Size Drásticamente ⭐ CRÍTICO

**Problema actual**: El batch_size por defecto es 50 productos, lo que puede significar cientos de imágenes por batch.

**Solución**: Reducir a 5-10 productos por batch para 4900 imágenes.

```php
// En el dashboard o configuración
// Cambiar batch_size de 50 a 5-10 para grandes volúmenes

// Opción 1: Desde el código
$batch_size = 5; // Solo 5 productos por batch
$imageSyncManager->syncAllImages(false, $batch_size);

// Opción 2: Configuración automática basada en total de imágenes
$total_images_estimate = 4900;
if ($total_images_estimate > 1000) {
    $batch_size = 5; // Muy conservador para grandes volúmenes
} elseif ($total_images_estimate > 500) {
    $batch_size = 10; // Conservador para volúmenes medianos
} else {
    $batch_size = 50; // Normal para volúmenes pequeños
}
```

**Ubicación**: `includes/Sync/ImageSyncManager.php` línea 275

**Impacto esperado**: 
- Reduce memoria por batch de ~250MB a ~25MB
- Reduce queries por batch de ~3,750 a ~375
- Permite que WordPress respire entre batches

---

### 2. Aumentar Delay Entre Batches ⭐ CRÍTICO

**Problema actual**: Los batches se procesan muy rápido uno tras otro.

**Solución**: Añadir pausas entre batches para dar tiempo a WordPress de recuperarse.

```php
// Añadir después de cada batch en ImageSyncManager.php
// Línea ~390 (después de procesar un batch)

// Pausa entre batches (en segundos)
$delay_between_batches = 5; // 5 segundos entre batches

// Para 4900 imágenes, usar delay más largo
if ($stats['total_processed'] > 100) {
    $delay_between_batches = 10; // 10 segundos si ya llevamos muchas
}

sleep($delay_between_batches);

// O mejor: usar throttling adaptativo
$this->throttler->throttle();
```

**Ubicación**: `includes/Sync/ImageSyncManager.php` después de línea 390

**Impacto esperado**:
- WordPress tiene tiempo de procesar queries pendientes
- Base de datos se recupera entre batches
- Menos competencia por recursos

---

### 3. Desactivar Generación de Thumbnails Durante Sincronización ⭐ CRÍTICO

**Problema actual**: WordPress genera thumbnails automáticamente, multiplicando el trabajo.

**Solución**: Ya implementado, pero verificar que esté activo.

```php
// Ya existe en ImageSyncManager.php línea 792
private function disableThumbnailGeneration(): void
{
    add_filter('intermediate_image_sizes_advanced', '__return_empty_array', 999);
}

// Asegurarse de que se llama al inicio de syncAllImages()
public function syncAllImages(bool $resume = false, int $batch_size = 50): array
{
    // ✅ AÑADIR AL INICIO
    $this->disableThumbnailGeneration();
    
    try {
        // ... resto del código ...
    } finally {
        // ✅ AÑADIR AL FINAL (en finally para asegurar que siempre se ejecute)
        $this->enableThumbnailGeneration();
    }
}
```

**Ubicación**: `includes/Sync/ImageSyncManager.php` líneas 156 y 792

**Impacto esperado**:
- Reduce trabajo de ~19,600 imágenes a 4,900 imágenes (75% menos)
- Reduce tiempo de procesamiento significativamente
- Los thumbnails se generarán cuando se necesiten (lazy loading)

---

### 4. Procesar en Background con WP-Cron ⭐ RECOMENDADO

**Problema actual**: Todo se procesa en una sola ejecución, agotando recursos.

**Solución**: Dividir el trabajo en múltiples ejecuciones de WP-Cron.

```php
// Nuevo método en ImageSyncManager.php
public function scheduleBatchProcessing(int $total_products, int $batch_size = 5): void
{
    $total_batches = ceil($total_products / $batch_size);
    
    for ($i = 0; $i < $total_batches; $i++) {
        $batch_number = $i;
        $delay = $i * 30; // 30 segundos entre cada batch programado
        
        wp_schedule_single_event(
            time() + $delay,
            'mia_process_image_batch',
            [$batch_number, $batch_size]
        );
    }
    
    $this->logger->info('Procesamiento de imágenes programado en background', [
        'total_batches' => $total_batches,
        'batch_size' => $batch_size,
        'estimated_duration_minutes' => ($total_batches * 30) / 60
    ]);
}

// Hook para procesar cada batch
add_action('mia_process_image_batch', function($batch_number, $batch_size) {
    $imageSyncManager = new ImageSyncManager($apiConnector, $logger);
    
    // Procesar solo este batch
    $start_index = $batch_number * $batch_size;
    $end_index = min($start_index + $batch_size, $total_products);
    
    // Procesar batch específico
    $imageSyncManager->processBatchRange($start_index, $end_index);
}, 10, 2);
```

**Ubicación**: Nuevo método en `includes/Sync/ImageSyncManager.php`

**Impacto esperado**:
- WordPress procesa batches gradualmente
- No satura el sistema en una sola ejecución
- Permite que WordPress funcione normalmente entre batches

---

### 5. Limpiar Memoria Agresivamente ⭐ RECOMENDADO

**Problema actual**: La memoria se acumula durante el procesamiento.

**Solución**: Limpiar memoria después de cada imagen y cada batch.

```php
// Ya existe parcialmente, pero mejorar:

// Después de procesar cada imagen (línea ~761)
unset($base64_image, $imagen_data, $attachment_id);
gc_collect_cycles(); // ✅ AÑADIR: Forzar limpieza inmediata

// Después de cada batch (línea ~390)
unset($current_batch, $product_ids_batch);
gc_collect_cycles(); // ✅ AÑADIR: Limpiar memoria del batch

// Verificar memoria disponible
$memory_usage = memory_get_usage(true);
$memory_limit = ini_get('memory_limit');
$memory_limit_bytes = $this->parseMemoryLimit($memory_limit);
$memory_percent = ($memory_usage / $memory_limit_bytes) * 100;

if ($memory_percent > 80) {
    // Si usamos más del 80% de memoria, hacer pausa más larga
    sleep(15);
    gc_collect_cycles();
}
```

**Ubicación**: `includes/Sync/ImageSyncManager.php` líneas 761 y 390

**Impacto esperado**:
- Memoria se libera inmediatamente
- Previene agotamiento de memoria
- Permite procesar más imágenes sin problemas

---

### 6. Aumentar Límites de PHP Temporalmente ⭐ RECOMENDADO

**Problema actual**: Los límites por defecto de PHP son insuficientes.

**Solución**: Aumentar temporalmente durante la sincronización.

```php
// Al inicio de syncAllImages()
public function syncAllImages(bool $resume = false, int $batch_size = 50): array
{
    // ✅ AÑADIR: Aumentar límites temporalmente
    $original_memory_limit = ini_get('memory_limit');
    $original_max_execution_time = ini_get('max_execution_time');
    
    // Aumentar memoria a 512M o 1G si es posible
    ini_set('memory_limit', '512M');
    
    // Aumentar tiempo de ejecución a 0 (sin límite) o 3600 segundos (1 hora)
    set_time_limit(3600); // 1 hora por batch
    
    try {
        // ... resto del código ...
    } finally {
        // ✅ RESTAURAR: Volver a límites originales
        ini_set('memory_limit', $original_memory_limit);
        set_time_limit($original_max_execution_time);
    }
}
```

**Ubicación**: `includes/Sync/ImageSyncManager.php` línea 156

**Impacto esperado**:
- Permite procesar más imágenes sin agotar memoria
- Evita timeouts prematuros
- Se restaura automáticamente después

---

### 7. Procesar por Chunks Más Pequeños de Imágenes ⭐ OPCIONAL

**Problema actual**: Se procesan todas las imágenes de un producto de golpe.

**Solución**: Procesar imágenes de un producto en grupos más pequeños.

```php
// Modificar processProductImages() en ImageSyncManager.php
private function processProductImages(int $product_id): array
{
    // ... obtener imágenes ...
    
    $images_per_chunk = 3; // Procesar 3 imágenes a la vez
    $total_images = count($imagenes);
    
    for ($i = 0; $i < $total_images; $i += $images_per_chunk) {
        $chunk = array_slice($imagenes, $i, $images_per_chunk);
        
        foreach ($chunk as $index => $imagen_data) {
            // Procesar imagen
            $attachment_id = $this->imageProcessor->processImageFromBase64(...);
            
            // Limpiar después de cada imagen
            unset($imagen_data, $attachment_id);
        }
        
        // Pausa entre chunks de imágenes
        if ($i + $images_per_chunk < $total_images) {
            sleep(1); // 1 segundo entre chunks
            gc_collect_cycles();
        }
    }
}
```

**Ubicación**: `includes/Sync/ImageSyncManager.php` método `processProductImages()`

**Impacto esperado**:
- Reduce memoria usada simultáneamente
- Permite pausas entre grupos de imágenes
- Más control sobre el proceso

---

## 📋 Configuración Recomendada para 4900 Imágenes

### Configuración Óptima

```php
// Configuración para 4900 imágenes
$config = [
    'batch_size' => 5,                    // Solo 5 productos por batch
    'delay_between_batches' => 10,        // 10 segundos entre batches
    'images_per_chunk' => 3,              // 3 imágenes por chunk dentro de un producto
    'disable_thumbnails' => true,         // Desactivar thumbnails
    'memory_limit' => '512M',             // Aumentar memoria
    'max_execution_time' => 3600,        // 1 hora por batch
    'gc_collect_cycles' => true,          // Limpiar memoria agresivamente
    'throttle_delay' => 0.5               // 500ms entre imágenes
];
```

### Cálculo de Tiempo Estimado

- **Total de batches**: 4900 imágenes / 5 productos = ~980 batches (asumiendo ~5 imágenes por producto)
- **Tiempo por batch**: ~30 segundos (procesamiento + delay)
- **Tiempo total**: 980 × 30 segundos = ~29,400 segundos = **~8.2 horas**

**Nota**: Esto es normal para 4900 imágenes. El sistema seguirá funcionando durante todo el proceso.

---

## 🚀 Implementación Inmediata (Quick Fix)

### Opción 1: Cambiar Batch Size Manualmente

```php
// En el dashboard, cuando inicies la sincronización de imágenes
// Cambiar batch_size de 50 a 5

// O desde código:
$imageSyncManager = new ImageSyncManager($apiConnector, $logger);
$imageSyncManager->syncAllImages(false, 5); // batch_size = 5
```

### Opción 2: Añadir Delay Entre Batches

Editar `includes/Sync/ImageSyncManager.php` línea ~390:

```php
// Después de procesar un batch, añadir:
sleep(10); // 10 segundos de pausa
gc_collect_cycles(); // Limpiar memoria
```

### Opción 3: Verificar que Thumbnails Están Desactivados

Verificar que `disableThumbnailGeneration()` se llama al inicio de `syncAllImages()`.

---

## 📊 Monitoreo Durante el Proceso

### Verificar que No Se Sature

```php
// Añadir logging de memoria
$memory_usage = memory_get_usage(true) / 1024 / 1024; // MB
$memory_limit = ini_get('memory_limit');

$this->logger->info('Estado de memoria durante procesamiento', [
    'memory_usage_mb' => round($memory_usage, 2),
    'memory_limit' => $memory_limit,
    'products_processed' => $stats['total_processed'],
    'images_processed' => $stats['total_attachments']
]);
```

### Señales de Alerta

- **Memoria > 80%**: Aumentar delay entre batches
- **Tiempo por batch > 60 segundos**: Reducir batch_size
- **Errores frecuentes**: Aumentar throttle_delay

---

## ✅ Checklist de Implementación

- [ ] Reducir batch_size a 5-10 para grandes volúmenes
- [ ] Añadir delay de 10 segundos entre batches
- [ ] Verificar que thumbnails están desactivados
- [ ] Aumentar memory_limit a 512M temporalmente
- [ ] Añadir gc_collect_cycles() después de cada batch
- [ ] Implementar procesamiento en background (opcional pero recomendado)
- [ ] Añadir logging de memoria para monitoreo
- [ ] Probar con un lote pequeño primero (100 imágenes)

---

## 🎯 Resultado Esperado

Con estas optimizaciones:

- ✅ WordPress seguirá funcionando durante todo el proceso
- ✅ No habrá saturación de memoria
- ✅ Base de datos no se sobrecargará
- ✅ El proceso completará en ~8 horas (normal para 4900 imágenes)
- ✅ Se pueden hacer pausas y reanudar sin problemas

---

**Última actualización**: 2025-01-XX  
**Prioridad**: CRÍTICA  
**Estado**: Soluciones listas para implementar

