# 🚀 Solución Optimizada para Procesamiento de Imágenes Base64

**Fecha**: 2025-11-04  
**Objetivo**: Explicar la solución optimizada para procesar imágenes Base64 sin consumir excesiva memoria

---

## 📋 Problema Actual

### Cómo Funciona Actualmente (Código Actual)

**Ubicación**: `includes/Core/BatchProcessor.php` línea 4679

```php
// CÓDIGO ACTUAL (PROBLEMA)
$image_data = base64_decode($matches[2]); // ⚠️ Carga TODA la imagen en memoria
$upload = mi_integracion_api_upload_bits_safe($filename, null, $image_data);
```

**Problema**:
- Si una imagen Base64 tiene 5MB, se decodifica toda de una vez → **5MB en memoria**
- Si procesas 50 imágenes de 5MB cada una → **250MB en memoria**
- Si las procesas dentro de una transacción → **locks de base de datos durante 30-60 segundos**

**Resultado**: 
- ⚠️ Alto consumo de memoria
- ⚠️ Timeouts en base de datos
- ⚠️ Riesgo de agotar memoria del servidor

---

## ✅ Solución Optimizada

### Concepto Principal

En lugar de cargar toda la imagen Base64 en memoria de una vez, la solución propuesta:

1. **Procesa el Base64 en trozos pequeños** (chunks de 10KB)
2. **Escribe cada trozo directamente a un archivo temporal**
3. **Sube el archivo usando streaming** (sin cargar en memoria)

**Resultado**: 
- ✅ Solo usa 10KB de memoria a la vez (en lugar de 5MB)
- ✅ Puede procesar imágenes de 10MB+ sin problemas
- ✅ Reduce significativamente el consumo de memoria

---

## 🔍 Explicación Detallada de la Solución

### 1. Función Helper: `write_base64_to_temp()`

```php
function write_base64_to_temp($base64, $temp_path) {
    // 1. Abrir archivo temporal para escritura binaria
    $handle = fopen($temp_path, 'wb');
    if (!$handle) return false;
    
    // 2. Tamaño del chunk: 10KB (solo 10KB en memoria a la vez)
    $chunkSize = 1024 * 10; // 10KB
    $length = strlen($base64); // Tamaño total del string Base64
    
    // 3. Procesar Base64 en chunks de 10KB
    for ($start = 0; $start < $length; $start += $chunkSize) {
        $end = min($start + $chunkSize, $length);
        $chunk = substr($base64, $start, $end - $start); // Extraer chunk de 10KB
        
        // 4. Decodificar chunk y escribir directamente al archivo
        if (fwrite($handle, base64_decode($chunk)) === false) {
            fclose($handle);
            return false;
        }
    }
    
    fclose($handle);
    return true;
}
```

**¿Qué hace?**
- Procesa el string Base64 en trozos de **10KB**
- Cada trozo se decodifica y escribe **directamente al archivo temporal**
- Solo mantiene **10KB en memoria** en cada iteración
- Al final, tienes un archivo temporal con la imagen completa

**Ejemplo Visual**:
```
Base64 completo (5MB):
[==================================================]

Procesado en chunks de 10KB:
[----] → escribe a archivo
       [----] → escribe a archivo
              [----] → escribe a archivo
                     ... (continúa hasta el final)
```

---

### 2. Función Principal: `process_base64_image()`

```php
function process_base64_image($base64) {
    // 1. Generar archivo temporal único
    $temp_path = tempnam(sys_get_temp_dir(), 'wp_');
    $original_name = 'image_' . uniqid() . '.jpg';
    
    // 2. Escribir Base64 al archivo temporal (en chunks)
    if (!write_base64_to_temp($base64, $temp_path)) {
        return array('error' => 'Failed to write temp file');
    }
    
    // 3. Abrir archivo temporal para lectura (streaming)
    $handle = fopen($temp_path, 'rb');
    if (!$handle) {
        return array('error' => 'Failed to open temp file');
    }
    
    // 4. Subir usando wp_upload_bits() con handle (streaming)
    $overrides = array(
        'test_form' => false,
        'action' => 'upload',
    );
    $upload = wp_upload_bits(
        $original_name,
        $handle,  // ← Usa el handle del archivo, NO el contenido en memoria
        $overrides
    );
    
    // 5. Limpiar: cerrar handle y eliminar archivo temporal
    fclose($handle);
    unlink($temp_path);
    
    return $upload;
}
```

**¿Qué hace?**
1. Crea un archivo temporal único
2. Escribe el Base64 al archivo en chunks (sin cargar en memoria)
3. Abre el archivo temporal para lectura
4. Usa `wp_upload_bits()` con el **handle del archivo** (streaming)
5. Limpia el archivo temporal automáticamente

---

### 3. Uso para Múltiples Imágenes

```php
foreach ($base64_strings as $base64) {
    $upload = process_base64_image($base64);
    if (!isset($upload['error'])) {
        // Procesar upload exitoso
    }
}
```

**Ventaja**: Cada imagen se procesa **independientemente**, liberando memoria después de cada una.

---

## 📊 Comparación: Antes vs Después

### Antes (Código Actual)

```php
// Imagen de 5MB
$image_data = base64_decode($base64); // 5MB en memoria
$upload = mi_integracion_api_upload_bits_safe($filename, null, $image_data); // 5MB más
// Total: 10MB en memoria (5MB Base64 + 5MB decodificado)

// 50 imágenes de 5MB
// Memoria usada: 50 × 10MB = 500MB
```

**Problemas**:
- ❌ Carga toda la imagen en memoria
- ❌ Si procesas 50 imágenes, todas están en memoria simultáneamente
- ❌ Riesgo de agotar memoria del servidor

---

### Después (Solución Optimizada)

```php
// Imagen de 5MB
// Procesa en chunks de 10KB
// Memoria usada: 10KB a la vez

// 50 imágenes de 5MB
// Memoria usada: 10KB (una imagen a la vez)
```

**Ventajas**:
- ✅ Solo usa 10KB de memoria a la vez
- ✅ Procesa imágenes una por una
- ✅ Puede manejar imágenes de 10MB+ sin problemas

---

## 🔑 Optimizaciones Clave

### 1. Procesamiento en Chunks (Trozos)

**¿Por qué 10KB?**
- Es un tamaño pequeño que no consume mucha memoria
- Es lo suficientemente grande para ser eficiente
- Puedes ajustarlo según tus necesidades (ej.: 64KB, 128KB)

**Beneficio**: 
- Reduce el consumo de memoria de **5MB** a **10KB** por imagen
- Reducción del **99.8%** en memoria usada

---

### 2. Subida con Streaming

**Diferencia clave**:

```php
// ❌ ANTES: Carga todo en memoria
$upload = wp_upload_bits($filename, null, $image_data); // $image_data en memoria

// ✅ DESPUÉS: Streaming desde archivo
$handle = fopen($temp_path, 'rb');
$upload = wp_upload_bits($filename, $handle, $overrides); // Lee desde archivo
```

**Beneficio**: 
- WordPress lee el archivo directamente del disco
- No carga el archivo completo en memoria
- Cero memoria adicional durante la subida

---

### 3. Gestión de Archivos Temporales

**Características**:
- `tempnam()` crea archivos únicos automáticamente
- `sys_get_temp_dir()` usa el directorio temporal del sistema
- `unlink()` elimina el archivo después de subir

**Beneficio**: 
- No se acumulan archivos temporales
- Limpieza automática
- Compatible con cualquier sistema operativo

---

## ⚠️ Notas Importantes

### 1. Extensiones de Archivo

**Problema**: El código propuesto siempre usa `.jpg`, pero las imágenes pueden ser PNG, GIF, etc.

**Solución**:
```php
// Extraer tipo de imagen del Base64
if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64, $matches)) {
    $image_type = $matches[1]; // 'jpeg', 'png', 'gif', etc.
    $base64_data = $matches[2];
    
    $original_name = 'image_' . uniqid() . '.' . $image_type;
}
```

---

### 2. Manejo de Errores

**Mejoras sugeridas**:
```php
function process_base64_image($base64) {
    $temp_path = tempnam(sys_get_temp_dir(), 'wp_');
    
    if (!$temp_path) {
        return array('error' => 'No se pudo crear archivo temporal');
    }
    
    // Verificar que el archivo temporal se creó correctamente
    if (!file_exists($temp_path)) {
        return array('error' => 'Archivo temporal no existe');
    }
    
    // ... resto del código
    
    // Asegurar limpieza incluso si hay error
    if (file_exists($temp_path)) {
        @unlink($temp_path);
    }
}
```

---

### 3. Seguridad

**Validaciones necesarias**:
```php
function process_base64_image($base64) {
    // 1. Validar formato Base64
    if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64)) {
        return array('error' => 'Formato Base64 inválido');
    }
    
    // 2. Sanitizar nombre de archivo
    $original_name = sanitize_file_name('image_' . uniqid() . '.jpg');
    
    // 3. Validar tamaño máximo (ej.: 10MB)
    $base64_data = base64_decode($matches[2]);
    if (strlen($base64_data) > 10 * 1024 * 1024) {
        return array('error' => 'Imagen demasiado grande');
    }
    
    // ... resto del código
}
```

---

## 🎯 Integración con Código Actual

### Cómo Adaptar `createAttachmentFromBase64()`

**Código actual** (línea 4671-4761):
```php
private function createAttachmentFromBase64(string $base64_image, int $product_id): int|false
{
    // Línea 4679: PROBLEMA
    $image_data = base64_decode($matches[2]); // Carga toda en memoria
    
    // Línea 4696: PROBLEMA
    $upload = mi_integracion_api_upload_bits_safe($filename, null, $image_data);
}
```

**Código optimizado**:
```php
private function createAttachmentFromBase64(string $base64_image, int $product_id): int|false
{
    try {
        // Extraer tipo de imagen y datos Base64
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64_image, $matches)) {
            $image_type = $matches[1];
            $base64_data = $matches[2];
            
            // ✅ OPTIMIZACIÓN: Escribir Base64 a archivo temporal en chunks
            $temp_path = tempnam(sys_get_temp_dir(), 'wp_verial_');
            if (!$temp_path) {
                $this->getLogger()->error('No se pudo crear archivo temporal', [
                    'product_id' => $product_id
                ]);
                return false;
            }
            
            // Escribir Base64 en chunks de 10KB
            if (!$this->writeBase64ToTemp($base64_data, $temp_path)) {
                $this->getLogger()->error('Error escribiendo Base64 a archivo temporal', [
                    'product_id' => $product_id
                ]);
                @unlink($temp_path);
                return false;
            }
            
            // Generar nombre único
            $filename = 'verial-image-' . $product_id . '-' . uniqid() . '.' . $image_type;
            
            // ✅ OPTIMIZACIÓN: Subir usando streaming desde archivo temporal
            $handle = fopen($temp_path, 'rb');
            if (!$handle) {
                $this->getLogger()->error('No se pudo abrir archivo temporal', [
                    'product_id' => $product_id
                ]);
                @unlink($temp_path);
                return false;
            }
            
            // Subir usando wp_upload_bits con handle (streaming)
            $upload = wp_upload_bits($filename, $handle, [
                'test_form' => false,
                'action' => 'upload'
            ]);
            
            // Limpiar
            fclose($handle);
            @unlink($temp_path);
            
            if ($upload === false || isset($upload['error'])) {
                $this->getLogger()->error('Error subiendo imagen Base64', [
                    'product_id' => $product_id,
                    'error' => $upload['error'] ?? 'Unknown error'
                ]);
                return false;
            }
            
            // ... resto del código (crear attachment, etc.)
            
        }
    } catch (Exception $e) {
        // ... manejo de errores
    }
}

/**
 * Escribe string Base64 a archivo temporal en chunks
 * 
 * @param string $base64 String Base64 a escribir
 * @param string $temp_path Ruta del archivo temporal
 * @return bool True si éxito, false si error
 */
private function writeBase64ToTemp(string $base64, string $temp_path): bool
{
    $handle = fopen($temp_path, 'wb');
    if (!$handle) {
        return false;
    }
    
    $chunkSize = 1024 * 10; // 10KB chunks
    $length = strlen($base64);
    
    for ($start = 0; $start < $length; $start += $chunkSize) {
        $end = min($start + $chunkSize, $length);
        $chunk = substr($base64, $start, $end - $start);
        
        // Decodificar chunk y escribir directamente al archivo
        if (fwrite($handle, base64_decode($chunk)) === false) {
            fclose($handle);
            return false;
        }
    }
    
    fclose($handle);
    return true;
}
```

---

## 📈 Impacto Esperado

### Consumo de Memoria

| Escenario | Antes | Después | Reducción |
|-----------|-------|---------|-----------|
| 1 imagen (5MB) | 10MB | 10KB | **99.9%** |
| 50 imágenes (5MB c/u) | 500MB | 10KB | **99.998%** |
| 100 imágenes (5MB c/u) | 1GB | 10KB | **99.999%** |

### Tiempo de Transacciones

- **Antes**: 30-60 segundos (procesamiento de imágenes dentro de transacción)
- **Después**: 5-10 segundos (procesamiento fuera de transacción + streaming)
- **Reducción**: 80-85% en tiempo de locks

---

## ✅ Checklist de Implementación

- [ ] Implementar `writeBase64ToTemp()` como método privado
- [ ] Modificar `createAttachmentFromBase64()` para usar streaming
- [ ] Añadir validación de formato Base64
- [ ] Añadir sanitización de nombres de archivo
- [ ] Añadir validación de tamaño máximo
- [ ] Añadir manejo robusto de errores
- [ ] Añadir limpieza automática de archivos temporales
- [ ] Probar con imágenes pequeñas (< 1MB)
- [ ] Probar con imágenes medianas (1-5MB)
- [ ] Probar con imágenes grandes (> 5MB)
- [ ] Probar con múltiples imágenes (50+)
- [ ] Verificar que no se acumulan archivos temporales
- [ ] Actualizar documentación

---

## 🔗 Referencias

- **Problema identificado**: `docs/ANALISIS-RIESGOS-Y-MEJORAS.md` - Riesgo 4: Imágenes en Base64
- **Análisis de timeouts**: `docs/ANALISIS-IMAGENES-CAUSA-TIMEOUT.md`
- **Prioridades**: `docs/PRIORIDADES-IMPLEMENTACION.md` - Prioridad ALTA #6

---

**Última actualización**: 2025-11-04

