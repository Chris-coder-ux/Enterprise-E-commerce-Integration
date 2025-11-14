# 🔍 Sistema de Detección de Duplicados de Imágenes

## 📋 Resumen Ejecutivo

El sistema utiliza un mecanismo robusto basado en **hash MD5** para detectar imágenes duplicadas antes de subirlas a la biblioteca de medios de WordPress. Esto previene duplicados y optimiza el uso de espacio en disco.

---

## 🎯 Objetivo

Evitar subir imágenes duplicadas a la biblioteca de medios cuando:
- Se sincroniza el mismo producto múltiples veces
- Varios productos comparten la misma imagen
- Se reanuda una sincronización interrumpida

---

## 🔧 Componentes del Sistema

### 1. **Cálculo del Hash MD5**

**Ubicación**: `includes/Sync/ImageProcessor.php:293`

```php
// Calcular hash para verificar duplicados
$image_hash = md5($base64_image);
```

**Características**:
- ✅ Hash MD5 de **32 caracteres hexadecimales**
- ✅ Calculado sobre la imagen Base64 **completa** (incluyendo prefijo `data:image/...`)
- ✅ Determinístico: misma imagen = mismo hash
- ✅ Rápido de calcular (operación en memoria)

**Ejemplo**:
```php
$base64_image = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAYABgAAD...';
$image_hash = md5($base64_image);
// Resultado: "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
```

---

### 2. **Búsqueda de Duplicados**

**Ubicación**: `includes/Sync/ImageProcessor.php:866-1024`

**Método**: `findAttachmentByHash(string $image_hash, ?int $article_id = null)`

#### **Estrategia de Búsqueda en 3 Niveles**:

##### **Nivel 1: Cache de Instancia** (Más Rápido)
```php
// Cache en memoria de la instancia actual
if (isset($this->hashCache[$cache_key])) {
    return $this->hashCache[$cache_key];
}
```
- ✅ **O(1)** - Acceso instantáneo
- ✅ Cache por instancia de `ImageProcessor`
- ✅ Se limpia al finalizar el procesamiento

##### **Nivel 2: Cache Estático** (Rápido)
```php
// Cache estático compartido entre instancias
if (isset(self::$recent_hashes[$cache_key])) {
    return self::$recent_hashes[$cache_key];
}
```
- ✅ **O(1)** - Acceso instantáneo
- ✅ Compartido entre todas las instancias
- ✅ Tamaño limitado (MAX_CACHE_SIZE = 1000)
- ✅ Evicción FIFO cuando se llena

##### **Nivel 3: Base de Datos** (Más Lento pero Completo)
```php
// Consulta SQL a wp_postmeta
$query = "
    SELECT post_id
    FROM {$wpdb->postmeta}
    WHERE meta_key = '_verial_image_hash'
    AND meta_value = %s
";
```
- ⚠️ **O(n)** - Consulta SQL
- ✅ Búsqueda completa en toda la base de datos
- ✅ Optimizado con índices en `meta_key` y `meta_value`
- ✅ Timeout de 5 segundos para evitar bloqueos

#### **Optimización con `article_id`**:

Si se proporciona `article_id`, la búsqueda se optimiza:

```php
if ($article_id !== null) {
    $query .= " AND post_id IN (
        SELECT post_id
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_verial_article_id'
        AND meta_value = %d
    )";
}
```

**Ventajas**:
- ✅ Reduce el espacio de búsqueda
- ✅ Más rápido cuando se busca por producto específico
- ✅ Útil cuando se procesan imágenes de un mismo producto

---

### 3. **Almacenamiento de Metadatos**

**Ubicación**: `includes/Sync/ImageProcessor.php:698-700`

Cuando se crea un nuevo attachment, se guardan los siguientes metadatos:

```php
\update_post_meta($attachment_id, '_verial_article_id', $article_id);
\update_post_meta($attachment_id, '_verial_image_hash', $image_hash);
\update_post_meta($attachment_id, '_verial_image_order', $order);
```

**Metadatos**:
- ✅ `_verial_image_hash`: Hash MD5 de la imagen (para detección de duplicados)
- ✅ `_verial_article_id`: ID del artículo de Verial (para búsqueda optimizada)
- ✅ `_verial_image_order`: Orden de la imagen en el producto (para galerías)

**Tabla**: `wp_postmeta`
- ✅ Persistente en base de datos
- ✅ **NO** se almacena en caché de transients
- ✅ Indexado para búsquedas rápidas

---

## 🔄 Flujo Completo de Detección

### **Paso 1: Procesar Imagen Base64**

```php
public function processImageFromBase64(string $base64_image, int $article_id, int $order = 0)
{
    // 1. Validar formato Base64
    $parsed = $this->parseBase64ImageFormat($base64_image);
    
    // 2. Validar tipo MIME permitido
    if (!$this->isAllowedImageType($image_type)) {
        return false;
    }
    
    // 3. Validar tamaño máximo
    if (!$this->isBase64SizeValid($base64_data, $maxSize)) {
        return false;
    }
    
    // 4. Calcular hash MD5
    $image_hash = md5($base64_image);
```

### **Paso 2: Buscar Duplicado**

```php
    // 5. Verificar si ya existe
    $existing_attachment = $this->findAttachmentByHash($image_hash, $article_id);
```

**Búsqueda en orden**:
1. ✅ Cache de instancia (`$this->hashCache`)
2. ✅ Cache estático (`self::$recent_hashes`)
3. ✅ Base de datos (`wp_postmeta`)

### **Paso 3: Decisión**

```php
    if ($existing_attachment) {
        // ✅ DUPLICADO DETECTADO
        // Actualizar orden si es necesario
        $current_order = \get_post_meta($existing_attachment, '_verial_image_order', true);
        if ($current_order !== (string)$order) {
            \update_post_meta($existing_attachment, '_verial_image_order', $order);
        }
        return self::DUPLICATE; // Retornar constante especial
    }
    
    // ✅ NO ES DUPLICADO - Continuar con procesamiento
    // ... crear nuevo attachment ...
```

### **Paso 4: Guardar Metadatos (Solo si NO es duplicado)**

```php
    // Guardar hash en metadatos para futuras verificaciones
    \update_post_meta($attachment_id, '_verial_article_id', $article_id);
    \update_post_meta($attachment_id, '_verial_image_hash', $image_hash);
    \update_post_meta($attachment_id, '_verial_image_order', $order);
```

---

## 🛡️ Seguridad y Validaciones

### **Validación de Hash MD5**

```php
// Validar que image_hash es un hash MD5 válido
if (empty($image_hash) || !preg_match('/^[a-f0-9]{32}$/i', $image_hash)) {
    $this->logger->error('Hash MD5 inválido en findAttachmentByHash');
    return false;
}
```

**Validaciones**:
- ✅ Formato: 32 caracteres hexadecimales
- ✅ No vacío
- ✅ Solo caracteres `a-f` y `0-9` (case-insensitive)

### **Validación de `article_id`**

```php
if ($article_id !== null) {
    $article_id = \absint($article_id);
    if ($article_id <= 0) {
        $this->logger->warning('article_id inválido, ignorando filtro');
        $article_id = null;
    }
}
```

### **Timeout de Consulta SQL**

```php
$timeout = 5; // segundos
$start_time = microtime(true);

// Verificar timeout antes y después de ejecutar
if ($elapsed >= $timeout) {
    $this->logger->warning('Timeout alcanzado durante consulta');
    return false;
}
```

**Protecciones**:
- ✅ Timeout de 5 segundos para evitar bloqueos
- ✅ Verificación antes y después de la consulta
- ✅ Logging de timeouts para debugging

---

## 📊 Optimizaciones Implementadas

### 1. **Cache Multi-Nivel**

**Estructura**:
```
Cache Instancia → Cache Estático → Base de Datos
     O(1)            O(1)              O(n)
```

**Beneficios**:
- ✅ Reduce consultas SQL repetidas
- ✅ Acelera búsquedas de imágenes procesadas recientemente
- ✅ Mejora rendimiento en sincronizaciones masivas

### 2. **Clave de Cache Inteligente**

```php
$cache_key = $image_hash . '_' . ($article_id ?? 'all');
```

**Ventajas**:
- ✅ Diferencia búsquedas por producto específico vs. globales
- ✅ Mayor precisión en cache hits
- ✅ Evita falsos positivos

### 3. **Evicción FIFO del Cache Estático**

```php
if (count(self::$recent_hashes) >= self::MAX_CACHE_SIZE) {
    // Eliminar el 20% más antiguo del cache (FIFO)
    $keys_to_remove = array_slice(array_keys(self::$recent_hashes), 0, (int)(self::MAX_CACHE_SIZE * 0.2));
    foreach ($keys_to_remove as $key) {
        unset(self::$recent_hashes[$key]);
    }
}
```

**Características**:
- ✅ Tamaño máximo: 1000 entradas
- ✅ Evicción del 20% más antiguo cuando se llena
- ✅ Mantiene los hashes más recientes en memoria

### 4. **Búsqueda Optimizada con `article_id`**

Cuando se proporciona `article_id`, la consulta SQL se optimiza:

```sql
-- Sin article_id (búsqueda global)
SELECT post_id FROM wp_postmeta 
WHERE meta_key = '_verial_image_hash' 
AND meta_value = 'hash123...'

-- Con article_id (búsqueda optimizada)
SELECT post_id FROM wp_postmeta 
WHERE meta_key = '_verial_image_hash' 
AND meta_value = 'hash123...'
AND post_id IN (
    SELECT post_id FROM wp_postmeta 
    WHERE meta_key = '_verial_article_id' 
    AND meta_value = 12345
)
```

**Beneficios**:
- ✅ Reduce espacio de búsqueda
- ✅ Más rápido cuando se procesan imágenes de un mismo producto
- ✅ Menos carga en la base de datos

---

## 🔗 Relación con Limpieza de Caché

### **¿Afecta la limpieza de caché a la detección de duplicados?**

**Respuesta**: **NO**, porque:

1. **Metadatos en Base de Datos**:
   - ✅ Los hashes se almacenan en `wp_postmeta` (BD)
   - ✅ **NO** se almacenan en caché de transients
   - ✅ Limpiar caché `imagenes_*` NO afecta los metadatos

2. **Cache de Búsqueda**:
   - ⚠️ Los caches de instancia y estático se pierden al limpiar memoria
   - ✅ Pero se reconstruyen automáticamente en la siguiente búsqueda
   - ✅ La búsqueda en BD siempre funciona (fuente de verdad)

3. **Qué se Almacena en Caché `imagenes_*`**:
   - Respuestas temporales de la API `GetImagenesArticulosWS`
   - Datos Base64 de las imágenes
   - **NO** son los metadatos de detección de duplicados

### **Conclusión**:

✅ **SEGURO limpiar caché `imagenes_*`** porque:
- La detección de duplicados usa metadatos en BD (`_verial_image_hash`)
- El caché solo almacena respuestas temporales de la API
- Limpiar este caché NO causará duplicados

---

## 📈 Métricas y Rendimiento

### **Casos de Uso**

#### **Caso 1: Primera Sincronización**
- Hash calculado: ✅
- Búsqueda en cache: ❌ (vacío)
- Búsqueda en BD: ✅ (no encuentra)
- Resultado: **Nueva imagen creada**

#### **Caso 2: Segunda Sincronización (Mismo Producto)**
- Hash calculado: ✅
- Búsqueda en cache: ✅ (hit en cache estático)
- Búsqueda en BD: ❌ (no necesaria)
- Resultado: **Duplicado detectado** (O(1))

#### **Caso 3: Reanudación de Sincronización**
- Hash calculado: ✅
- Búsqueda en cache: ❌ (cache limpiado)
- Búsqueda en BD: ✅ (encuentra hash existente)
- Resultado: **Duplicado detectado** (O(n) pero solo una vez)

### **Rendimiento Esperado**

| Escenario | Cache Hit | Tiempo | Consultas SQL |
|-----------|-----------|--------|---------------|
| Primera vez | ❌ | ~5-10ms | 1 |
| Segunda vez (mismo producto) | ✅ | <1ms | 0 |
| Reanudación | ❌ | ~5-10ms | 1 |
| Producto con muchas imágenes | ✅ | <1ms | 0-1 |

---

## 🐛 Manejo de Errores

### **Errores Posibles**

1. **Hash MD5 Inválido**:
   ```php
   if (!preg_match('/^[a-f0-9]{32}$/i', $image_hash)) {
       return false;
   }
   ```
   - ✅ Validación previa
   - ✅ Logging de error
   - ✅ Retorna `false` (no procesa imagen)

2. **Timeout en Consulta SQL**:
   ```php
   if ($elapsed >= $timeout) {
       return false;
   }
   ```
   - ✅ Timeout de 5 segundos
   - ✅ Logging de warning
   - ✅ Retorna `false` (no procesa imagen)

3. **Error en Preparación SQL**:
   ```php
   if ($prepared_query === false) {
       return false;
   }
   ```
   - ✅ Validación de `wpdb->prepare()`
   - ✅ Logging de error
   - ✅ Retorna `false` (no procesa imagen)

4. **Excepción Durante Consulta**:
   ```php
   catch (\Exception $e) {
       $this->logger->error('Excepción en findAttachmentByHash');
       return false;
   }
   ```
   - ✅ Try-catch completo
   - ✅ Logging de error
   - ✅ Retorna `false` (no procesa imagen)

---

## ✅ Constante de Retorno

### **`ImageProcessor::DUPLICATE`**

**Valor**: `'duplicate'`

**Uso**:
```php
if ($existing_attachment) {
    return self::DUPLICATE;
}
```

**Ventajas**:
- ✅ Distingue entre "duplicado" y "error" (`false`)
- ✅ Permite manejo específico de duplicados
- ✅ Facilita logging y métricas

**Ejemplo de Uso**:
```php
$result = $imageProcessor->processImageFromBase64($base64_image, $article_id, $order);

if ($result === ImageProcessor::DUPLICATE) {
    $stats['duplicates']++;
} elseif ($result === false) {
    $stats['errors']++;
} else {
    $stats['attachments']++;
}
```

---

## 📝 Resumen de Metadatos

### **Metadatos Almacenados en Attachments**

| Meta Key | Tipo | Descripción | Uso |
|----------|------|-------------|-----|
| `_verial_image_hash` | string (32 chars) | Hash MD5 de la imagen | Detección de duplicados |
| `_verial_article_id` | int | ID del artículo de Verial | Búsqueda optimizada |
| `_verial_image_order` | int | Orden de la imagen en el producto | Galerías ordenadas |

### **Tabla de Base de Datos**

**Tabla**: `wp_postmeta`

**Estructura**:
```sql
meta_id (BIGINT) - ID único
post_id (BIGINT) - ID del attachment
meta_key (VARCHAR) - '_verial_image_hash', '_verial_article_id', etc.
meta_value (LONGTEXT) - Valor del metadato
```

**Índices**:
- ✅ `meta_key` (indexado)
- ✅ `meta_value` (indexado para búsquedas rápidas)

---

## 🎯 Conclusión

### **Características Clave**:

1. ✅ **Detección Robusta**: Hash MD5 de 32 caracteres
2. ✅ **Búsqueda Optimizada**: Cache multi-nivel + BD
3. ✅ **Persistencia**: Metadatos en BD (no en caché)
4. ✅ **Seguridad**: Validaciones y timeouts
5. ✅ **Rendimiento**: O(1) con cache, O(n) sin cache

### **Ventajas del Sistema**:

- ✅ **Previene duplicados** antes de subir imágenes
- ✅ **Optimiza espacio** en disco
- ✅ **Acelera sincronizaciones** con cache
- ✅ **Seguro** con limpieza de caché
- ✅ **Robusto** con manejo de errores completo

### **Relación con Limpieza de Caché**:

✅ **NO hay conflicto**:
- Metadatos en BD (persistentes)
- Caché solo para respuestas temporales de API
- Limpiar caché NO afecta detección de duplicados

---

## 📚 Referencias

- **Implementación**: `includes/Sync/ImageProcessor.php`
- **Método principal**: `processImageFromBase64()` (línea 236)
- **Búsqueda de duplicados**: `findAttachmentByHash()` (línea 866)
- **Constante**: `ImageProcessor::DUPLICATE` (línea 158)
- **Documentación relacionada**: `docs/ANALISIS_LIMPIEZA_CACHE_2_FASES.md`

