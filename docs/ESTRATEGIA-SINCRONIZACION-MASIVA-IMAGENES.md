# 🎯 Estrategia: Sincronización Masiva de Imágenes (Previa)

## 📋 Concepto

**Obtener TODAS las imágenes de TODOS los productos ANTES de sincronizar productos, y luego asignarlas durante el mapeo normal.**

### Flujo Propuesto

```
┌─────────────────────────────────────────┐
│  FASE 1: Obtener TODAS las imágenes     │
│  - Iterar por todos los productos       │
│  - GetImagenesArticulosWS por cada ID   │
│  - Guardar en cache/almacenamiento      │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  FASE 2: Sincronización normal          │
│  - Sincronizar productos por lotes      │
│  - Durante mapeo: buscar imágenes       │
│    desde cache (ya obtenidas)           │
└─────────────────────────────────────────┘
```

---

## 🏗️ Arquitectura

### Fase 1: Sincronización Masiva de Imágenes

```php
/**
 * Obtiene TODAS las imágenes de TODOS los productos
 * Se ejecuta UNA VEZ o periódicamente
 */
public function sync_all_product_images(): void
{
    // 1. Obtener total de productos
    $total_response = $this->apiConnector->get('GetNumArticulosWS');
    $total = $total_response->getData()['Numero'] ?? 0;
    
    // 2. Obtener todos los IDs de productos (en lotes para no cargar todo en memoria)
    $batch_size = 50;
    $all_product_ids = [];
    
    for ($inicio = 1; $inicio <= $total; $inicio += $batch_size) {
        $fin = min($inicio + $batch_size - 1, $total);
        
        $productos_response = $this->get_articulos_batch($inicio, $fin);
        $productos = $productos_response->getData()['Articulos'] ?? [];
        
        $ids = array_column($productos, 'Id');
        $all_product_ids = array_merge($all_product_ids, $ids);
    }
    
    // 3. Obtener imágenes por cada producto (con cache)
    $this->getLogger()->info('Iniciando sincronización masiva de imágenes', [
        'total_productos' => count($all_product_ids)
    ]);
    
    $processed = 0;
    $cached = 0;
    $errors = 0;
    
    foreach ($all_product_ids as $product_id) {
        // Intentar desde cache
        $cache_key = "images_product_{$product_id}_300";
        $cached_images = get_transient($cache_key);
        
        if ($cached_images !== false) {
            $cached++;
            continue;
        }
        
        // Obtener desde API
        $params = [
            'x' => $this->apiConnector->get_session_number(),
            'id_articulo' => $product_id,
            'numpixelsladomenor' => 300
        ];
        
        $response = $this->apiConnector->get('GetImagenesArticulosWS', $params);
        
        if ($response->isSuccess()) {
            $data = $response->getData();
            $imagenes = $data['Imagenes'] ?? [];
            
            // Cachear por 48 horas (o TTL configurable)
            set_transient($cache_key, $imagenes, 48 * HOUR_IN_SECONDS);
            
            $processed++;
        } else {
            $errors++;
            $this->getLogger()->warning('Error obteniendo imágenes', [
                'product_id' => $product_id,
                'error' => $response->getMessage()
            ]);
        }
        
        // Log progreso cada 100 productos
        if (($processed + $cached) % 100 === 0) {
            $this->getLogger()->info('Progreso sincronización imágenes', [
                'procesados' => $processed,
                'desde_cache' => $cached,
                'errores' => $errors,
                'total' => count($all_product_ids),
                'porcentaje' => round(($processed + $cached) / count($all_product_ids) * 100, 2)
            ]);
        }
    }
    
    $this->getLogger()->info('Sincronización masiva de imágenes completada', [
        'total_productos' => count($all_product_ids),
        'procesados' => $processed,
        'desde_cache' => $cached,
        'errores' => $errors
    ]);
}
```

### Fase 2: Sincronización Normal (Modificada)

```php
/**
 * Durante prepare_complete_batch_data(): NO obtener imágenes del batch
 * Las imágenes ya están en cache
 */
protected function prepare_complete_batch_data(int $inicio, int $fin): array
{
    // ... obtener productos, stock, condiciones, etc. ...
    
    // ❌ ELIMINAR: get_imagenes_batch() - ya no se necesita
    // ✅ Las imágenes se obtendrán desde cache durante el mapeo
    
    return $batch_data;
}

/**
 * En MapProduct::processProductImages(): buscar desde cache
 */
private static function processProductImages(
    array $verial_product, 
    array $product_data, 
    array $batch_cache
): array {
    $verial_product_id = (int)($verial_product['Id'] ?? 0);
    
    // ✅ Obtener imágenes desde cache (pre-sincronizadas)
    $cache_key = "images_product_{$verial_product_id}_300";
    $product_images = get_transient($cache_key);
    
    if ($product_images === false) {
        // Imágenes no disponibles en cache (puede pasar si producto nuevo)
        // Opcional: obtener ahora o dejar sin imágenes
        self::getLogger()->debug('Imágenes no encontradas en cache', [
            'product_id' => $verial_product_id
        ]);
        $product_images = [];
    }
    
    $images = [];
    $gallery = [];
    
    foreach ($product_images as $imagen_data) {
        if (empty($imagen_data['Imagen'])) {
            continue;
        }
        
        $image_url = 'data:image/jpeg;base64,' . $imagen_data['Imagen'];
        
        if (empty($images)) {
            $images[] = $image_url;
        } else {
            $gallery[] = $image_url;
        }
    }
    
    $product_data['images'] = $images;
    $product_data['gallery'] = $gallery;
    
    return $product_data;
}
```

---

## 🔄 Cuándo Ejecutar la Sincronización Masiva

### Opción 1: Manual (Comando/WP-CLI)

```php
// wp-admin o WP-CLI command
add_action('wp_ajax_sync_all_images', function() {
    $batchProcessor = new BatchProcessor($apiConnector);
    $batchProcessor->sync_all_product_images();
    wp_send_json_success(['message' => 'Sincronización completada']);
});
```

### Opción 2: Automática (WordPress Cron)

```php
// Programar ejecución diaria/semanal
if (!wp_next_scheduled('mia_sync_all_images')) {
    wp_schedule_event(time(), 'daily', 'mia_sync_all_images');
}

add_action('mia_sync_all_images', function() {
    $batchProcessor = new BatchProcessor($apiConnector);
    $batchProcessor->sync_all_product_images();
});
```

### Opción 3: Durante Sincronización Inicial

```php
// En la primera sincronización completa
if ($this->is_first_sync()) {
    $this->sync_all_product_images();
    // Luego continuar con sincronización normal
}
```

---

## 📊 Ventajas

### 1. Separación Completa

- ✅ Imágenes sincronizadas independientemente
- ✅ Productos sincronizados sin esperar imágenes
- ✅ Actualización de imágenes sin tocar productos

### 2. Eficiencia

- ✅ **Una sola pasada masiva** vs múltiples por lote
- ✅ **Cache persistente**: Imágenes disponibles para múltiples sincronizaciones
- ✅ **Sin duplicación**: Cada imagen se obtiene una vez

### 3. Escalabilidad

- ✅ **Sincronización incremental**: Solo productos nuevos necesitan imágenes
- ✅ **Actualización selectiva**: Actualizar solo productos modificados
- ✅ **Paralelización futura**: Fácil hacer requests paralelos

### 4. Flexibilidad

- ✅ **Sincronización programada**: Ejecutar cuando haya menos carga
- ✅ **Actualización manual**: Forzar actualización cuando sea necesario
- ✅ **TTL configurable**: Control sobre cuándo expira cache

---

## ⚠️ Consideraciones

### 1. Tiempo de Ejecución

**Problema:** Si hay 1000 productos, son 1000 llamadas API
- A ~200ms por llamada = **~200 segundos (3.3 minutos)**

**Soluciones:**
- Ejecutar en background (WordPress Cron, WP-CLI)
- Paralelización si API lo soporta
- Incremental: solo productos sin imágenes o actualizados

### 2. Memoria

**Problema:** Guardar todas las imágenes en transients puede consumir mucha memoria

**Soluciones:**
- Usar transients con expiración (automáticamente limpiados)
- Alternativa: Base de datos dedicada para imágenes
- Compresión de imágenes Base64 antes de guardar

### 3. Invalidación de Cache

**Problema:** ¿Cuándo actualizar imágenes?

**Estrategias:**
- TTL largo (48h-7d): Imágenes cambian poco
- Invalidación manual: Botón "Actualizar imágenes"
- Detección de cambios: Si producto se actualiza, invalidar sus imágenes
- Sincronización periódica: Diaria o semanal automática

### 4. Productos Nuevos

**Problema:** Producto nuevo no tiene imágenes en cache

**Soluciones:**
- **Opción A**: Obtener imágenes al vuelo (fallback)
- **Opción B**: Marcar producto para sincronización de imágenes posterior
- **Opción C**: Sincronización incremental automática

---

## 🔧 Implementación Recomendada

### Estructura de Cache

```php
// Cache individual por producto
$cache_key = "images_product_{$product_id}_300";
$cache_data = [
    'imagenes' => [...],
    'timestamp' => time(),
    'version' => 1 // Para invalidación futura
];

// TTL: 48 horas (configurable)
set_transient($cache_key, $cache_data, 48 * HOUR_IN_SECONDS);
```

### Comando WP-CLI (Opcional)

```php
WP_CLI::add_command('mia sync-images', function($args, $assoc_args) {
    $batchProcessor = new BatchProcessor($apiConnector);
    $batchProcessor->sync_all_product_images();
    WP_CLI::success('Imágenes sincronizadas');
});
```

### Invalidación Inteligente

```php
/**
 * Invalidar imágenes de un producto cuando se actualiza
 */
public function invalidate_product_images(int $product_id): void
{
    $cache_key = "images_product_{$product_id}_300";
    delete_transient($cache_key);
    
    $this->getLogger()->info('Imágenes invalidadas para producto', [
        'product_id' => $product_id
    ]);
}

// Llamar cuando producto se actualiza
add_action('mia_product_updated', function($product_id) {
    $batchProcessor->invalidate_product_images($product_id);
});
```

---

## 📈 Análisis de Rendimiento

### Escenario Actual (por lote)

```
Lote 1: 5 llamadas base + 50 imágenes = 55 llamadas
Lote 2: 5 llamadas base + 50 imágenes = 55 llamadas
Lote 3: 5 llamadas base + 50 imágenes = 55 llamadas
...
Total 10 lotes: ~550 llamadas
```

### Escenario Propuesto (sincronización masiva)

```
Fase 1 (una vez): 500 llamadas (todas las imágenes)
Fase 2 (lotes): 5 llamadas base × 10 lotes = 50 llamadas
Total: 550 llamadas (igual cantidad, pero mejor distribuido)
```

### Escenario Óptimo (con cache persistente)

```
Fase 1 (una vez): 500 llamadas (todas las imágenes)
Fase 2 (lotes, desde cache): 5 llamadas base × 10 lotes = 50 llamadas
Sincronizaciones siguientes: Solo 5 llamadas base por lote (sin imágenes)
Total siguiente sincronización: 50 llamadas (90% reducción)
```

---

## ✅ Plan de Implementación

### Fase 1: Preparación (Semana 1)

1. ✅ Crear método `sync_all_product_images()`
2. ✅ Implementar cache individual por producto
3. ✅ Agregar comando/manual para ejecutar sincronización masiva

### Fase 2: Integración (Semana 2)

1. ✅ Modificar `prepare_complete_batch_data()` para NO obtener imágenes
2. ✅ Modificar `MapProduct::processProductImages()` para usar cache
3. ✅ Mantener compatibilidad con estructura antigua

### Fase 3: Optimización (Semana 3)

1. ✅ Implementar sincronización incremental
2. ✅ Agregar invalidación inteligente
3. ✅ Programar sincronización automática (cron)

### Fase 4: Limpieza (Semana 4)

1. ✅ Remover código de paginación de imágenes obsoleto
2. ✅ Limpiar estructura `imagenes_productos` si no se usa
3. ✅ Documentación y pruebas

---

## 🎯 Conclusión

**Esta estrategia es ideal porque:**

1. ✅ **Separación completa**: Imágenes independientes de productos
2. ✅ **Eficiencia a largo plazo**: Cache persistente reduce llamadas futuras
3. ✅ **Flexibilidad**: Sincronización programable y manual
4. ✅ **Escalabilidad**: Funciona con cualquier cantidad de productos
5. ✅ **Mantenibilidad**: Lógica clara y separada

**ROI:** ⭐⭐⭐⭐⭐ Muy Alto  
**Complejidad:** ⭐⭐⭐ Media  
**Riesgo:** ⭐⭐ Bajo (implementación gradual)

---

**Fecha de creación:** 2025-11-02  
**Estado:** Propuesta para implementación  
**Prioridad:** Alta
