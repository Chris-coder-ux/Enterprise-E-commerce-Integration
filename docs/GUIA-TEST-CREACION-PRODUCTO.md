# 🧪 Guía de Test Manual: Creación de Nuevo Producto

**Test ID**: test-03  
**Objetivo**: Verificar que se puede crear un nuevo producto en WooCommerce con todos los metadatos de Verial correctamente  
**Fecha**: 2025-01-27

---

## 📋 Preparación del Test

### Requisitos Previos

1. ✅ **Ambiente WordPress/WooCommerce funcionando**
2. ✅ **Plugin Mi Integración API activo**
3. ✅ **Conexión a API de Verial configurada**
4. ✅ **Acceso a logs del plugin**

### Producto de Prueba

- **SKU de prueba**: Elegir un SKU que NO exista en WooCommerce (ej: "TEST-NEW-PRODUCT-001")
- **Producto de Verial**: Seleccionar un producto de la API de Verial que tenga:
  - ID válido
  - Nombre
  - SKU (ReferenciaBarras)
  - Precio
  - Categoría (opcional pero recomendado)
  - Tipo de producto definido

---

## 🔍 Verificación Pre-Test

### Paso 1: Confirmar que el producto NO existe

**Acción en WordPress Admin**:
```
WooCommerce → Productos → Buscar por SKU: "TEST-NEW-PRODUCT-001"
```

**Resultado esperado**: ❌ Producto no encontrado

**Verificación con código**:
```php
// En WordPress Admin o WP-CLI
$product_id = wc_get_product_id_by_sku('TEST-NEW-PRODUCT-001');
if ($product_id) {
    echo "❌ ERROR: El producto ya existe (ID: $product_id)";
} else {
    echo "✅ OK: El producto no existe, listo para crear";
}
```

---

## 🚀 Ejecución del Test

### Paso 2: Ejecutar sincronización del producto

**Método 1: Vía Dashboard (si está disponible)**
- Ir a la sección de sincronización del plugin
- Seleccionar el producto de prueba
- Ejecutar sincronización individual

**Método 2: Vía código/API**
```php
// Ejemplo de cómo ejecutar
$batch_processor = new BatchProcessor();
$verial_product = [
    'Id' => 12345, // ID del producto en Verial
    'ReferenciaBarras' => 'TEST-NEW-PRODUCT-001',
    'Nombre' => 'Producto de Prueba',
    // ... otros campos del producto de Verial
];

$result = $batch_processor->processSingleProductFromBatch($verial_product, $batch_data);
```

**Método 3: Vía WP-CLI (si está disponible)**
```bash
wp verial sync-product --sku="TEST-NEW-PRODUCT-001"
```

---

## ✅ Verificaciones Post-Creación

### Paso 3: Verificar que el producto se creó

**En WordPress Admin**:
1. Ir a: `WooCommerce → Productos`
2. Buscar por SKU: `TEST-NEW-PRODUCT-001`
3. Verificar que el producto aparece con:
   - ✅ Nombre correcto
   - ✅ SKU correcto
   - ✅ Precio correcto
   - ✅ Estado: "Publicado" (publish)
   - ✅ Tipo: "Simple" o el tipo correcto

**Verificación con código**:
```php
$product_id = wc_get_product_id_by_sku('TEST-NEW-PRODUCT-001');
if (!$product_id) {
    echo "❌ ERROR: El producto no se creó";
} else {
    $product = wc_get_product($product_id);
    echo "✅ Producto creado:\n";
    echo "   ID: " . $product->get_id() . "\n";
    echo "   Nombre: " . $product->get_name() . "\n";
    echo "   SKU: " . $product->get_sku() . "\n";
    echo "   Precio: " . $product->get_price() . "\n";
    echo "   Estado: " . $product->get_status() . "\n";
}
```

---

### Paso 4: Verificar metadatos de Verial

**Verificaciones obligatorias**:

#### 4.1: Metadato `_verial_id`

```php
$verial_id = get_post_meta($product_id, '_verial_id', true);
if (empty($verial_id)) {
    echo "❌ ERROR: _verial_id no está guardado\n";
} else {
    echo "✅ _verial_id guardado: {$verial_id}\n";
}
```

**Resultado esperado**: ✅ Debe tener el ID de Verial del producto

---

#### 4.2: Otros metadatos de Verial

```php
$metadatos_esperados = [
    '_verial_nombre',
    '_verial_referencia',
    '_verial_categoria',
    '_verial_fabricante',
    '_verial_tipo',
];

foreach ($metadatos_esperados as $meta_key) {
    $valor = get_post_meta($product_id, $meta_key, true);
    if ($valor === '' || $valor === false) {
        echo "⚠️  {$meta_key}: vacío o no encontrado\n";
    } else {
        echo "✅ {$meta_key}: {$valor}\n";
    }
}
```

**Resultado esperado**: ✅ Todos los metadatos deben estar guardados

---

### Paso 5: Verificar atributos dinámicos

**Verificar que se crearon los atributos dinámicos**:

```php
$product = wc_get_product($product_id);
$attributes = $product->get_attributes();

echo "Atributos del producto:\n";
foreach ($attributes as $attribute_name => $attribute) {
    echo "  - {$attribute_name}\n";
}

// Verificar atributos específicos si aplica
// (depende de los campos auxiliares del producto de Verial)
```

**Resultado esperado**: ✅ Los atributos dinámicos deben estar creados

---

### Paso 6: Verificar visibilidad basada en fechas

**Si el producto tiene fechas de inicio/fin**:

```php
// Verificar que la visibilidad se aplicó correctamente
$catalog_visibility = $product->get_catalog_visibility();
echo "Visibilidad del catálogo: {$catalog_visibility}\n";

// Verificar fechas si aplica
$fecha_inicio = get_post_meta($product_id, '_verial_fecha_inicio', true);
$fecha_fin = get_post_meta($product_id, '_verial_fecha_fin', true);
```

**Resultado esperado**: ✅ La visibilidad debe estar configurada según las fechas

---

### Paso 7: Verificar clases de impuestos dinámicas

```php
$tax_class = $product->get_tax_class();
echo "Clase de impuestos: " . ($tax_class ?: 'standard') . "\n";
```

**Resultado esperado**: ✅ La clase de impuestos debe estar configurada si aplica

---

### Paso 8: Verificar unidades dinámicas

```php
// Verificar unidades si el producto las tiene configuradas
$unidad = get_post_meta($product_id, '_verial_unidad', true);
echo "Unidad: " . ($unidad ?: 'N/A') . "\n";
```

**Resultado esperado**: ✅ Las unidades deben estar guardadas si aplica

---

### Paso 9: Verificar imágenes

**En WordPress Admin**:
1. Abrir el producto editado
2. Ir a la sección "Galería de productos"
3. Verificar que:
   - ✅ Imagen destacada está asignada (si existe)
   - ✅ Galería de imágenes está poblada (si existe)

**Verificación con código**:
```php
$product = wc_get_product($product_id);
$image_id = $product->get_image_id();
$gallery_ids = $product->get_gallery_image_ids();

echo "Imagen destacada: " . ($image_id ? "Sí (ID: {$image_id})" : "No") . "\n";
echo "Galería: " . count($gallery_ids) . " imágenes\n";
```

**Resultado esperado**: ✅ Las imágenes deben estar asignadas si existen en Verial

---

## 📊 Revisión de Logs

### Paso 10: Examinar logs del plugin

**Ubicación de logs**:
- Generalmente en: `wp-content/uploads/mi-integracion-api/logs/`
- O según configuración del plugin

**Búsqueda de mensajes clave**:

```bash
# Buscar logs relacionados con el SKU de prueba
grep -i "TEST-NEW-PRODUCT-001" logs/*.log

# Buscar mensajes de creación exitosa
grep -i "Nuevo producto creado\|producto creado exitosamente" logs/*.log

# Buscar errores
grep -i "error\|exception\|failed" logs/*.log | grep -i "TEST-NEW-PRODUCT-001"

# Buscar mensajes de metadatos
grep -i "metadatos de verial\|updateVerialProductMetadata" logs/*.log | grep -i "TEST-NEW-PRODUCT-001"
```

**Mensajes esperados en logs**:

✅ **Deben aparecer**:
- `"🆕 Creando nuevo producto en WooCommerce"`
- `"✅ Nuevo producto creado exitosamente"`
- `"🔧 Actualizando metadatos de Verial"`
- `"✅ Metadatos de Verial guardados exitosamente"`
- `"✅ Guardado _verial_id"`

❌ **NO deben aparecer**:
- `"verial_product vacío"`
- `"undefined variable: verial_product"`
- `"Error: verial_product"`
- `"TypeError"`
- `"Fatal error"`

---

## 🔍 Verificación del Flujo Completo

### Verificar cadena de ejecución en logs:

El flujo completo debe verse así en los logs:

```
1. processSingleProductFromBatch llamado
   ↓
2. ✅ CORREGIDO: Mapeo correcto del producto con batch_cache
   ↓
3. Buscar producto existente por SKU → NO ENCONTRADO
   ↓
4. createNewWooCommerceProduct() llamado
   ↓
5. 🆕 Creando nuevo producto en WooCommerce
   ↓
6. ✅ Nuevo producto creado exitosamente
   ↓
7. handlePostSaveOperations() llamado
   ↓
8. 🔧 Actualizando metadatos de Verial
   ↓
9. ✅ Guardado _verial_id
   ↓
10. applyDateBasedVisibility() ejecutado
11. createDynamicAttributesFromAuxFields() ejecutado
12. manageDynamicTaxClasses() ejecutado
13. manageDynamicUnits() ejecutado
14. manageOtherFields() ejecutado
   ↓
15. ✅ Metadatos de Verial guardados exitosamente
```

---

## ✅ Checklist de Verificación

### Producto Creado
- [ ] El producto se creó en WooCommerce
- [ ] Tiene el SKU correcto
- [ ] Tiene el nombre correcto
- [ ] Tiene el precio correcto
- [ ] Estado es "Publicado"

### Metadatos de Verial
- [ ] `_verial_id` está guardado
- [ ] `_verial_nombre` está guardado
- [ ] `_verial_referencia` está guardado
- [ ] `_verial_categoria` está guardado (si aplica)
- [ ] `_verial_fabricante` está guardado (si aplica)
- [ ] `_verial_tipo` está guardado

### Funcionalidades Dinámicas
- [ ] Atributos dinámicos creados (si aplica)
- [ ] Visibilidad basada en fechas configurada (si aplica)
- [ ] Clases de impuestos dinámicas configuradas (si aplica)
- [ ] Unidades dinámicas guardadas (si aplica)
- [ ] Campos otros (Nexo, Ecotasas) guardados (si aplica)

### Imágenes
- [ ] Imagen destacada asignada (si existe)
- [ ] Galería de imágenes poblada (si existe)

### Logs
- [ ] No hay errores relacionados con `verial_product`
- [ ] No hay mensajes de "undefined" o "vacío"
- [ ] Todos los pasos del flujo aparecen en logs
- [ ] Mensajes de éxito están presentes

---

## 🎯 Criterios de Éxito

El test pasa si:

1. ✅ El producto se crea exitosamente en WooCommerce
2. ✅ Todos los metadatos de Verial se guardan correctamente
3. ✅ No hay errores en los logs relacionados con `verial_product`
4. ✅ Las funcionalidades dinámicas se ejecutan correctamente
5. ✅ El flujo es idéntico al flujo de actualización (consistencia)

---

## ❌ Posibles Problemas y Soluciones

### Problema 1: Producto no se crea

**Síntomas**: No aparece en WooCommerce después de la sincronización

**Verificación**:
- Revisar logs para ver mensajes de error
- Verificar que `createNewWooCommerceProduct()` retorna un producto válido
- Verificar que no hay excepciones

**Soluciones posibles**:
- Verificar que WooCommerce está activo
- Verificar permisos de usuario
- Revisar datos del producto (nombre, precio, etc.)

---

### Problema 2: Metadatos no se guardan

**Síntomas**: El producto se crea pero los metadatos de Verial están vacíos

**Verificación**:
- Revisar logs para ver si `updateVerialProductMetadata()` se ejecuta
- Verificar que `$verial_product` no está vacío en los logs
- Revisar línea 4635 donde se llama a `updateVerialProductMetadata()`

**Soluciones posibles**:
- Verificar que `$verial_product` se pasa correctamente desde `processSingleProductFromBatch()`
- Verificar que `handlePostSaveOperations()` recibe `$verial_product` correctamente

---

### Problema 3: Error "undefined variable: verial_product"

**Síntomas**: Error en logs indicando que `$verial_product` no está definido

**Causa**: El parámetro no se está pasando correctamente

**Verificación**:
- Confirmar que la línea 3060 pasa `$verial_product`: 
  ```php
  $this->createNewWooCommerceProduct($wc_product_data, $verial_product);
  ```
- Confirmar que la línea 3528 pasa `$verial_product`:
  ```php
  $verial_product, // Datos originales de Verial
  ```

**Solución**: Ya corregido en esta refactorización

---

### Problema 4: Atributos dinámicos no se crean

**Síntomas**: El producto se crea pero no tiene atributos dinámicos

**Verificación**:
- Revisar logs para ver si `createDynamicAttributesFromAuxFields()` se ejecuta
- Verificar que `updateVerialProductMetadata()` se llama correctamente
- Verificar que `$verial_product` contiene los campos auxiliares necesarios

---

## 📝 Registro de Resultados

Completa este formulario después de ejecutar el test:

```
SKU del producto de prueba: _________________________
ID de Verial: _________________________
Fecha del test: _________________________

✅ Producto creado: [ ] Sí [ ] No
✅ Metadatos guardados: [ ] Sí [ ] No
✅ Atributos dinámicos creados: [ ] Sí [ ] No
✅ Sin errores en logs: [ ] Sí [ ] No

Errores encontrados:
___________________________________________________
___________________________________________________

Notas:
___________________________________________________
___________________________________________________
```

---

## 🔗 Referencias

- **Flujo de código**: `processSingleProductFromBatch()` → `createNewWooCommerceProduct()` → `handlePostSaveOperations()` → `updateVerialProductMetadata()`
- **Líneas clave**:
  - Línea 3078-3080: Llamada a `createNewWooCommerceProduct()` cuando no existe producto
  - Línea 3525-3530: `handlePostSaveOperations()` en creación
  - Línea 3528: Pasa `$verial_product` correctamente
  - Línea 4635: Llama a `updateVerialProductMetadata($product_id, $verial_product, ...)`
  - Líneas 4954-4966: Ejecuta los 5 métodos dinámicos

---

**Última actualización**: 2025-01-27  
**Estado**: 📋 Listo para ejecutar

