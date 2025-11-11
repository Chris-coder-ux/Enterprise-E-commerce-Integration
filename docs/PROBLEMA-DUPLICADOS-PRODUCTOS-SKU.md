# 🚨 Problema Crítico: Duplicados de Productos por SKU

**Fecha**: 2025-11-04  
**Problema**: 16,000 productos aparecieron cuando no deberían ser tantos  
**Causa**: La detección de SKUs duplicados no está funcionando correctamente

---

## 🔍 Análisis del Código Actual

### Lógica Actual (Línea 3009-3010 de BatchProcessor.php)

```php
// ✅ BUSCAR PRODUCTO EXISTENTE
if (!empty($sku) && function_exists('wc_get_product_id_by_sku')) {
    $existing_product_id = wc_get_product_id_by_sku($sku);
}

if ($existing_product_id) {
    // Actualizar producto existente
} else {
    // Crear nuevo producto
}
```

### Problemas Identificados

#### 1. **SKU Vacío o Null**

Si `$sku` está vacío o es null, se salta la verificación y se crea un producto nuevo sin SKU.

**Código problemático**:
```php
$sku = $verial_product['ReferenciaBarras'] ?? 'ID_' . ($verial_product['Id'] ?? 'unknown');
```

**Problema**: Si `ReferenciaBarras` está vacío y `Id` también, se genera `'ID_unknown'` que puede ser el mismo para múltiples productos.

#### 2. **SKU con Espacios o Caracteres Especiales**

`wc_get_product_id_by_sku()` puede no encontrar productos si:
- El SKU tiene espacios al inicio/final
- Hay diferencias de mayúsculas/minúsculas
- Hay caracteres especiales codificados diferente

#### 3. **Problemas de Transacciones**

Si múltiples procesos ejecutan simultáneamente:
- Proceso A: Verifica SKU → No existe
- Proceso B: Verifica SKU → No existe (Aún no se ha guardado)
- Proceso A: Crea producto
- Proceso B: Crea producto (DUPLICADO)

#### 4. **Productos sin SKU**

Si un producto se crea sin SKU, cada sincronización puede crear otro producto sin SKU.

#### 5. **Fallos de `wc_get_product_id_by_sku()`**

Si la función falla silenciosamente o retorna `false` en lugar de `0`, se crearán productos duplicados.

---

## 🧪 Verificación del Problema

### Query SQL para Verificar Duplicados

```sql
-- Contar productos totales
SELECT COUNT(*) as total_productos
FROM wp_posts
WHERE post_type = 'product'
  AND post_status IN ('publish', 'draft', 'pending', 'private');

-- Contar productos sin SKU
SELECT COUNT(*) as productos_sin_sku
FROM wp_posts p
LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
WHERE p.post_type = 'product'
  AND (pm.meta_value IS NULL OR pm.meta_value = '');

-- Ver SKUs duplicados
SELECT meta_value as sku, COUNT(*) as count
FROM wp_postmeta
WHERE meta_key = '_sku'
  AND meta_value != ''
GROUP BY meta_value
HAVING COUNT(*) > 1
ORDER BY count DESC
LIMIT 20;

-- Ver productos con SKU 'ID_unknown'
SELECT p.ID, p.post_title, pm.meta_value as sku
FROM wp_posts p
INNER JOIN wp_postmeta pm ON p.ID = pm.post_id
WHERE p.post_type = 'product'
  AND pm.meta_key = '_sku'
  AND (pm.meta_value LIKE 'ID_%' OR pm.meta_value = 'ID_unknown')
LIMIT 50;
```

### Script PHP para Verificar Duplicados

```php
<?php
require_once('wp-load.php');

// Contar productos totales
$total_products = wp_count_posts('product');
echo "Total productos: " . array_sum((array)$total_products) . "\n\n";

// Contar productos sin SKU
$products_without_sku = get_posts([
    'post_type' => 'product',
    'posts_per_page' => -1,
    'fields' => 'ids',
    'meta_query' => [
        'relation' => 'OR',
        [
            'key' => '_sku',
            'compare' => 'NOT EXISTS'
        ],
        [
            'key' => '_sku',
            'value' => '',
            'compare' => '='
        ]
    ]
]);
echo "Productos sin SKU: " . count($products_without_sku) . "\n\n";

// Encontrar SKUs duplicados
global $wpdb;
$duplicate_skus = $wpdb->get_results("
    SELECT meta_value as sku, COUNT(*) as count
    FROM {$wpdb->postmeta}
    WHERE meta_key = '_sku'
      AND meta_value != ''
    GROUP BY meta_value
    HAVING COUNT(*) > 1
    ORDER BY count DESC
    LIMIT 20
");

echo "SKUs duplicados encontrados:\n";
foreach ($duplicate_skus as $dup) {
    echo "  SKU: {$dup->sku} - {$dup->count} productos\n";
}

// Verificar funcionamiento de wc_get_product_id_by_sku
echo "\nProbando wc_get_product_id_by_sku():\n";
if (function_exists('wc_get_product_id_by_sku')) {
    // Obtener un SKU de prueba
    $test_sku = $wpdb->get_var("
        SELECT meta_value 
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_sku'
          AND meta_value != ''
        LIMIT 1
    ");
    
    if ($test_sku) {
        $product_id = wc_get_product_id_by_sku($test_sku);
        echo "  SKU: $test_sku -> Product ID: " . ($product_id ?: 'NO ENCONTRADO') . "\n";
    }
} else {
    echo "  ERROR: wc_get_product_id_by_sku() no está disponible\n";
}
```

---

## ✅ Soluciones Propuestas

### Solución 1: Normalizar SKU Antes de Verificar (CRÍTICO)

```php
private function normalizeSku(string $sku): string
{
    // Trim espacios
    $sku = trim($sku);
    
    // Convertir a mayúsculas para consistencia
    $sku = strtoupper($sku);
    
    // Eliminar múltiples espacios
    $sku = preg_replace('/\s+/', ' ', $sku);
    
    return $sku;
}

// En processSingleProductFromBatch:
$sku = $verial_product['ReferenciaBarras'] ?? 'ID_' . ($verial_product['Id'] ?? 'unknown');

// Normalizar SKU
$sku = $this->normalizeSku($sku);

// Verificar si está vacío después de normalizar
if (empty($sku) || $sku === 'ID_UNKNOWN') {
    $this->getLogger()->error('SKU inválido o vacío', [
        'verial_product' => $verial_product
    ]);
    return $this->buildErrorResponse('SKU inválido o vacío', 0);
}
```

### Solución 2: Verificación Más Robusta

```php
// ✅ BUSCAR PRODUCTO EXISTENTE (MEJORADO)
$existing_product_id = null;

if (!empty($sku)) {
    // Método 1: Usar función de WooCommerce
    if (function_exists('wc_get_product_id_by_sku')) {
        $existing_product_id = wc_get_product_id_by_sku($sku);
    }
    
    // Método 2: Verificación directa en base de datos (fallback)
    if (!$existing_product_id) {
        global $wpdb;
        $existing_product_id = $wpdb->get_var($wpdb->prepare("
            SELECT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_sku'
              AND meta_value = %s
            LIMIT 1
        ", $sku));
    }
    
    // Método 3: Verificar por ID de Verial (si está mapeado)
    if (!$existing_product_id && !empty($verial_product['Id'])) {
        $existing_product_id = MapProduct::find_wc_product_by_verial_id((int)$verial_product['Id']);
    }
}
```

### Solución 3: Usar Lock para Prevenir Duplicados Simultáneos

```php
// Usar transiente como lock para prevenir duplicados simultáneos
$lock_key = 'mia_product_creation_lock_' . md5($sku);
$lock_timeout = 30; // 30 segundos

if (get_transient($lock_key)) {
    // Ya hay otro proceso creando este producto
    $this->getLogger()->warning('Producto ya está siendo procesado por otro proceso', [
        'sku' => $sku
    ]);
    
    // Esperar un poco y verificar de nuevo
    sleep(2);
    $existing_product_id = wc_get_product_id_by_sku($sku);
    
    if ($existing_product_id) {
        // Actualizar producto existente
        // ... código de actualización ...
    } else {
        return $this->buildErrorResponse('Producto en proceso por otro hilo, reintentar más tarde', 0);
    }
} else {
    // Establecer lock
    set_transient($lock_key, true, $lock_timeout);
    
    try {
        // Procesar producto
        // ... código de creación/actualización ...
    } finally {
        // Liberar lock
        delete_transient($lock_key);
    }
}
```

### Solución 4: Verificar Mapeo de Productos

```php
// Verificar si el producto ya está mapeado
$verial_id = (int)($verial_product['Id'] ?? 0);
if ($verial_id > 0) {
    $mapped_product_id = MapProduct::find_wc_product_by_verial_id($verial_id);
    
    if ($mapped_product_id) {
        // Producto ya existe en el mapeo
        $existing_product_id = $mapped_product_id;
    }
}
```

---

## 📊 Impacto del Problema

### Escenario Actual (Con Problema)

- **16,000 productos** cuando deberían ser menos
- Múltiples productos con el mismo SKU
- Productos sin SKU
- Base de datos inflada
- Problemas de rendimiento

### Escenario Corregido

- Productos únicos por SKU
- Actualización en lugar de creación duplicada
- Base de datos limpia
- Mejor rendimiento

---

## 🔧 Implementación Recomendada

### Prioridad 1: Normalizar SKU (CRÍTICO)
- Agregar método `normalizeSku()`
- Normalizar SKU antes de todas las verificaciones
- Validar que SKU no esté vacío después de normalizar

### Prioridad 2: Verificación Robusta
- Implementar múltiples métodos de verificación
- Usar mapeo de productos como fuente adicional
- Agregar verificación directa en base de datos como fallback

### Prioridad 3: Prevenir Duplicados Simultáneos
- Implementar locks con transients
- Agregar reintentos con backoff

### Prioridad 4: Script de Limpieza
- Crear script para identificar y limpiar duplicados
- Opción para fusionar productos duplicados

---

## 🧹 Script de Limpieza de Duplicados

```php
<?php
/**
 * Script para limpiar productos duplicados
 * 
 * USO: wp eval-file limpiar-duplicados.php
 */

require_once('wp-load.php');

global $wpdb;

// Encontrar SKUs duplicados
$duplicate_skus = $wpdb->get_results("
    SELECT meta_value as sku, COUNT(*) as count, GROUP_CONCAT(post_id) as product_ids
    FROM {$wpdb->postmeta}
    WHERE meta_key = '_sku'
      AND meta_value != ''
      AND meta_value NOT LIKE 'ID_%'
    GROUP BY meta_value
    HAVING COUNT(*) > 1
");

echo "Encontrados " . count($duplicate_skus) . " SKUs duplicados\n\n";

foreach ($duplicate_skus as $dup) {
    $product_ids = explode(',', $dup->product_ids);
    $product_ids = array_map('intval', $product_ids);
    
    // Mantener el más antiguo, eliminar los demás
    $keep_id = min($product_ids);
    $delete_ids = array_diff($product_ids, [$keep_id]);
    
    echo "SKU: {$dup->sku}\n";
    echo "  Mantener: Product ID {$keep_id}\n";
    echo "  Eliminar: " . implode(', ', $delete_ids) . "\n\n";
    
    // Descomentar para eliminar realmente:
    // foreach ($delete_ids as $delete_id) {
    //     wp_delete_post($delete_id, true);
    //     echo "  ✓ Eliminado producto {$delete_id}\n";
    // }
}
```

---

## 📝 Checklist de Verificación

- [ ] Verificar productos sin SKU
- [ ] Verificar SKUs duplicados
- [ ] Verificar productos con SKU 'ID_unknown'
- [ ] Probar funcionamiento de `wc_get_product_id_by_sku()`
- [ ] Implementar normalización de SKU
- [ ] Implementar verificación robusta
- [ ] Implementar locks para prevenir duplicados simultáneos
- [ ] Ejecutar script de limpieza (después de corregir)
- [ ] Verificar que no se creen más duplicados

