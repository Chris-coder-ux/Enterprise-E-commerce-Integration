# ⚠️ Problema Crítico: Duplicados de Imágenes Innecesarios

**Fecha**: 2025-11-04  
**Prioridad**: ALTA  
**Impacto**: Causa procesamiento innecesario, transacciones más largas, y locks en base de datos

---

## 🔍 Problema Identificado

### Situación Actual

El método `createAttachmentFromBase64()` en `BatchProcessor.php` **NO verifica si la imagen ya existe** antes de crear un nuevo attachment.

**Código problemático** (línea 4671):
```php
private function createAttachmentFromBase64(string $base64_image, int $product_id): int|false
{
    // ❌ NO verifica si ya existe la imagen
    // Crea un nuevo attachment cada vez
    
    $attachment_id = wp_insert_attachment($attachment, $upload['file'], $product_id);
    // ...
}
```

### Consecuencias

1. **Attachments duplicados en media library**:
   - Cada sincronización crea nuevos attachments aunque la imagen ya exista
   - Si sincronizas 100 productos 10 veces = 1000 attachments duplicados

2. **Procesamiento innecesario**:
   - `wp_generate_attachment_metadata()` se ejecuta para imágenes que ya existen
   - `wp_update_attachment_metadata()` se ejecuta innecesariamente
   - Cada imagen duplicada = ~10-15 queries de base de datos innecesarias

3. **Transacciones más largas**:
   - Procesamiento de imágenes duplicadas aumenta el tiempo de transacción
   - Más locks mantenidos en la base de datos

4. **Espacio desperdiciado**:
   - Múltiples copias de la misma imagen en el servidor
   - Archivos duplicados en `wp-content/uploads/`

---

## 📊 Impacto Estimado

### Escenario: 100 productos con 5 imágenes cada uno

| Métrica | Sin verificación | Con verificación |
|---------|------------------|------------------|
| Attachments creados (primera sync) | 500 | 500 |
| Attachments creados (segunda sync) | 500 **duplicados** | 0 (reutiliza) |
| Queries de base de datos (segunda sync) | ~7,500 | ~0 |
| Tiempo de procesamiento (segunda sync) | 30-60 segundos | 0 segundos |
| Espacio en disco (segunda sync) | ~50-100 MB duplicados | 0 MB |

**Ahorro**: **100% de procesamiento innecesario eliminado**

---

## ✅ Solución Propuesta

### 1. Verificar Duplicados por Hash

Antes de crear un attachment, verificar si ya existe uno con el mismo hash MD5:

```php
private function createAttachmentFromBase64(
    string $base64_image, 
    int $product_id,
    ?int $article_id = null  // ID_Articulo de Verial (opcional)
): int|false {
    // 1. Calcular hash de la imagen
    $image_hash = md5($base64_image);
    
    // 2. Buscar attachment existente por hash
    $existing_attachment = $this->findAttachmentByHash($image_hash, $article_id);
    
    if ($existing_attachment) {
        // ✅ Ya existe, reutilizar
        $this->getLogger()->debug('Imagen duplicada detectada, reutilizando attachment existente', [
            'product_id' => $product_id,
            'existing_attachment_id' => $existing_attachment,
            'hash' => substr($image_hash, 0, 8)
        ]);
        return $existing_attachment;
    }
    
    // 3. Si no existe, crear nuevo attachment
    // ... código actual ...
    
    // 4. Guardar hash en metadatos para futuras verificaciones
    update_post_meta($attachment_id, '_verial_image_hash', $image_hash);
    if ($article_id) {
        update_post_meta($attachment_id, '_verial_article_id', $article_id);
    }
    
    return $attachment_id;
}
```

### 2. Método Helper para Buscar por Hash

```php
/**
 * Busca un attachment existente por hash MD5 de la imagen
 * 
 * @param string $image_hash Hash MD5 de la imagen
 * @param int|null $article_id ID_Articulo de Verial (opcional, para optimizar búsqueda)
 * @return int|false Attachment ID o false si no existe
 */
private function findAttachmentByHash(string $image_hash, ?int $article_id = null): int|false
{
    // Si tenemos article_id, buscar primero por ese campo (más rápido)
    if ($article_id !== null) {
        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_verial_article_id',
                    'value' => $article_id,
                    'compare' => '='
                ],
                [
                    'key' => '_verial_image_hash',
                    'value' => $image_hash,
                    'compare' => '='
                ]
            ],
            'posts_per_page' => 1,
            'fields' => 'ids'
        ];
    } else {
        // Buscar solo por hash (más lento pero funciona)
        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'meta_query' => [
                [
                    'key' => '_verial_image_hash',
                    'value' => $image_hash,
                    'compare' => '='
                ]
            ],
            'posts_per_page' => 1,
            'fields' => 'ids'
        ];
    }
    
    $attachments = get_posts($args);
    
    if (!empty($attachments)) {
        return (int) $attachments[0];
    }
    
    return false;
}
```

### 3. Obtener article_id del Producto

Necesitamos pasar el `article_id` (ID_Articulo de Verial) al método. Esto se puede obtener del mapeo de productos:

```php
// En handlePostSaveOperations o donde se llama a setProductImages
$article_id = null;
if (!empty($verial_product['Id'])) {
    $article_id = (int) $verial_product['Id'];
}

// Pasar article_id al procesar imágenes
$this->setProductImages($product_id, $wc_product_data['images'], $article_id);
```

---

## 📈 Beneficios Esperados

1. **Reducción de procesamiento innecesario**: 100% en sincronizaciones repetidas
2. **Reducción de transacciones**: 80-90% menos tiempo con locks
3. **Reducción de espacio**: No duplicar imágenes ya existentes
4. **Mejor rendimiento**: Reutilización inmediata de attachments existentes

---

## ⚠️ Consideraciones

### Compatibilidad con Imágenes Existentes

Las imágenes ya creadas NO tendrán el hash guardado. Esto significa:
- Primera ejecución después del cambio: Algunas imágenes pueden duplicarse
- Solución: Script de migración para calcular y guardar hashes de imágenes existentes

### Performance de la Búsqueda

Buscar por hash puede ser lento si hay muchas imágenes. Consideraciones:
- Usar `article_id` cuando esté disponible (búsqueda más rápida)
- Considerar índice en `wp_postmeta` para `_verial_image_hash`
- Cachear resultados de búsqueda si se procesan múltiples imágenes del mismo producto

---

## 🔧 Implementación Recomendada

1. **Fase 1**: Agregar verificación de duplicados en `createAttachmentFromBase64()`
2. **Fase 2**: Pasar `article_id` desde `handlePostSaveOperations()`
3. **Fase 3**: Script de migración para calcular hashes de imágenes existentes
4. **Fase 4**: Monitorear reducción de procesamiento innecesario

---

## 📝 Checklist de Implementación

- [ ] Crear método `findAttachmentByHash()`
- [ ] Modificar `createAttachmentFromBase64()` para verificar duplicados
- [ ] Guardar hash en metadatos al crear nuevo attachment
- [ ] Pasar `article_id` desde `handlePostSaveOperations()`
- [ ] Actualizar `setProductImages()` y `setProductGallery()` para aceptar `article_id`
- [ ] Script de migración para imágenes existentes (opcional)
- [ ] Testing con productos nuevos y existentes
- [ ] Monitorear logs para verificar reutilización

