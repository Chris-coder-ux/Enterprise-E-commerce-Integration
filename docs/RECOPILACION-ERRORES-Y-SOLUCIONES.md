# 📋 Recopilación Completa de Errores y Soluciones

**Fecha de creación**: 2025-11-04  
**Última actualización**: 2025-11-04  
**Estado**: Resumen completo de todos los problemas identificados

**📋 Documento relacionado**: Para ver la lista de prioridades de implementación, consulta [`docs/PRIORIDADES-IMPLEMENTACION.md`](PRIORIDADES-IMPLEMENTACION.md)

---

## 📑 Índice

1. [Errores Críticos](#errores-críticos)
2. [Errores de Duplicación](#errores-de-duplicación)
3. [Errores de Timeout y Base de Datos](#errores-de-timeout-y-base-de-datos)
4. [Errores de Configuración y Automatización](#errores-de-configuración-y-automatización)
5. [Errores de Código y Lógica](#errores-de-código-y-lógica)
6. [Scripts de Solución](#scripts-de-solución)

---

## 🔴 Errores Críticos

### 1. Error: "Lock wait timeout exceeded" en Action Scheduler

**Error completo**:
```
RuntimeException: No se han podido solicitar acciones. 
Error de la base de datos: Lock wait timeout exceeded; 
try restarting transaction.
Ubicación: ActionScheduler_DBStore.php:1019
```

**Causa raíz**:
- Procesamiento de imágenes dentro de transacciones largas (30-60 segundos)
- Múltiples procesos compitiendo por locks en Action Scheduler
- Timeout de MySQL demasiado bajo (50 segundos por defecto)
- Transacciones mantienen locks durante todo el procesamiento de imágenes

**Impacto**:
- ⚠️ Bloquea sincronizaciones
- ⚠️ Puede bloquear el sitio si hay muchos procesos
- ⚠️ Crea productos duplicados debido a fallos

**Soluciones**:

#### Solución Inmediata (CRÍTICA)
```sql
-- Aumentar timeout de MySQL
SET GLOBAL innodb_lock_wait_timeout = 60;
SET GLOBAL lock_wait_timeout = 60;

-- Verificar
SHOW VARIABLES LIKE '%lock_wait_timeout%';
```

#### Solución a Largo Plazo (CRÍTICA)
```php
// Mover procesamiento de imágenes FUERA de la transacción
// En BatchProcessor.php, línea ~4488

// ANTES (problema):
private function handlePostSaveOperations(...) {
    // Se ejecuta DENTRO de la transacción
    $this->setProductImages($product_id, $wc_product_data['images']);
}

// DESPUÉS (solución):
private function handlePostSaveOperations(...) {
    // Guardar producto (transacción corta)
    // ...
    
    // CERRAR transacción antes de procesar imágenes
    $transactionManager->commit("batch_processing", $operationId);
    
    // Procesar imágenes FUERA de la transacción
    $this->setProductImages($product_id, $wc_product_data['images']);
}
```

**Documentación completa**: `docs/SOLUCION-ERROR-ACTION-SCHEDULER-TIMEOUT.md`

---

### 2. Error 500 en Script de Detención de Sincronizaciones

**Error**: Script `detener-todas-sincronizaciones.php` da error 500

**Causa**:
- Variables no inicializadas (`$as_actions_table`)
- Falta de validación de métodos antes de llamarlos
- Consultas SQL sin preparar correctamente
- Falta de manejo de errores

**Soluciones aplicadas**:
- ✅ Inicialización de todas las variables
- ✅ Validación de métodos con `method_exists()`
- ✅ Consultas SQL preparadas con `$wpdb->prepare()`
- ✅ Manejo completo de excepciones (Exception + Throwable)
- ✅ Verificación de funciones de WordPress antes de usarlas

**Estado**: ✅ **CORREGIDO**

**Archivo**: `scripts/detener-todas-sincronizaciones.php`

---

## 🔄 Errores de Duplicación

### 3. Duplicados de Productos (16,000 productos)

**Problema**: 16,000 productos aparecieron cuando no deberían ser tantos

**Causas identificadas**:

1. **SKU Vacío o Null**
   - Si `ReferenciaBarras` está vacío, se genera `'ID_unknown'` que puede duplicarse
   - No se valida correctamente antes de crear

2. **SKU con Espacios o Caracteres Especiales**
   - `wc_get_product_id_by_sku()` puede no encontrar productos si hay diferencias de formato
   - No se normaliza el SKU antes de buscar

3. **Condiciones de Carrera**
   - Múltiples procesos verifican SKU simultáneamente
   - Ambos encuentran que no existe
   - Ambos crean productos → DUPLICADOS

4. **Productos sin SKU**
   - Si un producto se crea sin SKU, cada sincronización crea otro

5. **Fallos Silenciosos de `wc_get_product_id_by_sku()`**
   - Si falla, retorna `false` en lugar de `0`
   - Se interpreta como "no existe" → crea producto duplicado

**Soluciones**:

#### Solución 1: Normalización de SKU
```php
// Normalizar SKU antes de buscar
private function normalizeSKU(string $sku): string {
    // Eliminar espacios
    $sku = trim($sku);
    // Convertir a mayúsculas (opcional, según necesidad)
    $sku = strtoupper($sku);
    // Eliminar caracteres especiales problemáticos
    $sku = preg_replace('/[^A-Z0-9\-_]/', '', $sku);
    return $sku;
}
```

#### Solución 2: Verificación Robusta
```php
// Verificar producto existente con múltiples métodos
private function findExistingProduct(array $verial_product): ?int {
    $sku = $this->normalizeSKU($verial_product['ReferenciaBarras'] ?? '');
    
    // Método 1: Por SKU
    if (!empty($sku) && function_exists('wc_get_product_id_by_sku')) {
        $existing_id = wc_get_product_id_by_sku($sku);
        if ($existing_id && $existing_id > 0) {
            return $existing_id;
        }
    }
    
    // Método 2: Por ID de Verial (metadato)
    if (!empty($verial_product['Id'])) {
        global $wpdb;
        $existing_id = $wpdb->get_var($wpdb->prepare("
            SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_verial_product_id' 
            AND meta_value = %s
            LIMIT 1
        ", $verial_product['Id']));
        
        if ($existing_id) {
            return (int) $existing_id;
        }
    }
    
    // Método 3: Por nombre + SKU (si está disponible)
    // ...
    
    return null;
}
```

#### Solución 3: Lock de Base de Datos
```php
// Usar lock de base de datos para evitar condiciones de carrera
private function createOrUpdateProduct(array $verial_product): int {
    global $wpdb;
    
    // Obtener lock exclusivo
    $lock_name = 'verial_product_' . md5($verial_product['ReferenciaBarras'] ?? '');
    $lock_acquired = $wpdb->get_var($wpdb->prepare("
        SELECT GET_LOCK(%s, 10)
    ", $lock_name));
    
    if (!$lock_acquired) {
        throw new \Exception('No se pudo adquirir lock para crear producto');
    }
    
    try {
        // Verificar si existe
        $existing_id = $this->findExistingProduct($verial_product);
        
        if ($existing_id) {
            // Actualizar
            return $this->updateProduct($existing_id, $verial_product);
        } else {
            // Crear
            return $this->createProduct($verial_product);
        }
    } finally {
        // Liberar lock
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
    }
}
```

**Script de gestión**: `scripts/detectar-duplicados-productos.php`

**Documentación completa**: `docs/PROBLEMA-DUPLICADOS-PRODUCTOS-SKU.md`

---

### 4. Duplicados de Imágenes (Attachments)

**Problema**: Se crean attachments duplicados en cada sincronización

**Causa**:
- `createAttachmentFromBase64()` NO verifica si la imagen ya existe
- Cada sincronización crea nuevos attachments aunque la imagen ya exista

**Impacto**:
- ⚠️ Procesamiento innecesario de imágenes duplicadas
- ⚠️ Transacciones más largas (causa timeouts)
- ⚠️ Espacio desperdiciado en disco
- ⚠️ ~10-15 queries de base de datos innecesarias por imagen duplicada

**Solución**:

```php
// Verificar duplicados por hash MD5 antes de crear
private function createAttachmentFromBase64(
    string $base64_image, 
    int $product_id,
    ?int $article_id = null
): int|false {
    // 1. Calcular hash de la imagen
    $image_hash = md5($base64_image);
    
    // 2. Buscar attachment existente por hash
    $existing_attachment = $this->findAttachmentByHash($image_hash, $article_id);
    
    if ($existing_attachment) {
        // ✅ Ya existe, reutilizar
        $this->getLogger()->debug('Imagen duplicada detectada, reutilizando', [
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

// Método auxiliar para buscar por hash
private function findAttachmentByHash(string $hash, ?int $article_id = null): ?int {
    global $wpdb;
    
    $query = "
        SELECT post_id 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = '_verial_image_hash' 
        AND meta_value = %s
    ";
    
    $params = [$hash];
    
    // Si tenemos article_id, buscar también por ese
    if ($article_id) {
        $query .= " AND post_id IN (
            SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_verial_article_id' 
            AND meta_value = %s
        )";
        $params[] = $article_id;
    }
    
    $query .= " LIMIT 1";
    
    $attachment_id = $wpdb->get_var($wpdb->prepare($query, ...$params));
    
    return $attachment_id ? (int) $attachment_id : null;
}
```

**Documentación completa**: `docs/PROBLEMA-DUPLICADOS-IMAGENES.md`

---

## ⏱️ Errores de Timeout y Base de Datos

### 5. Procesamiento de Imágenes Causa Timeouts

**Problema**: Las transacciones duran 30-60 segundos debido al procesamiento de imágenes

**Causa**:
- Procesamiento de imágenes dentro de la transacción del batch
- Cada imagen requiere múltiples operaciones de base de datos
- 250 imágenes × ~10-15 queries = ~3,000 queries en una transacción

**Soluciones**:

#### Solución 1: Mover Procesamiento Fuera de Transacción
```php
// Procesar imágenes DESPUÉS de commit
$transactionManager->commit("batch_processing", $operationId);

// Luego procesar imágenes (sin transacción)
foreach ($batch as $product) {
    $this->setProductImages($product_id, $product['images']);
}
```

#### Solución 2: Procesar Imágenes en Background
```php
// Programar procesamiento de imágenes para después
wp_schedule_single_event(
    time() + 60, 
    'mia_process_product_images', 
    [$product_id, $images_data]
);
```

#### Solución 3: Desactivar Generación de Thumbnails Durante Sync
```php
// Antes de procesar batch
add_filter('intermediate_image_sizes', '__return_empty_array');

// Procesar productos e imágenes...

// Después
remove_filter('intermediate_image_sizes', '__return_empty_array');

// Generar thumbnails después en background
wp_schedule_single_event(time() + 60, 'mia_generate_thumbnails', [$product_ids]);
```

**Documentación completa**: `docs/ANALISIS-IMAGENES-CAUSA-TIMEOUT.md`

---

### 6. Delay del Plugin Insuficiente

**Problema**: El delay de 5 segundos entre batches puede ser insuficiente

**Causa**:
- WordPress Cron no es exacto en el timing
- Puede acumular múltiples batches y ejecutarlos simultáneamente
- Esto aumenta la competencia por locks

**Solución**:

```php
// En functions.php del tema o en un plugin
add_filter('mia_batch_delay_seconds', function($delay) {
    return 15; // Aumentar a 15 segundos entre batches
});
```

**Ubicación del código**: `includes/Core/Sync_Manager.php` línea 12925-12934

---

## ⚙️ Errores de Configuración y Automatización

### 7. Toggle de Detección Automática No Funciona

**Problema**: El toggle para activar/desactivar la detección automática no controla realmente la sincronización

**Causa**:
- **DOS sistemas diferentes** manejando el cron:
  - `DetectionDashboard` usa `mia_auto_detection_hook`
  - `StockDetector` usa `mia_automatic_stock_detection`
- El toggle controla un hook, pero el otro sigue ejecutándose

**Solución**:

#### Solución 1: Unificar Hooks
```php
// En DetectionDashboard.php
private function scheduleDetectionCron(): void {
    // Usar el hook correcto de StockDetector
    if (!wp_next_scheduled('mia_automatic_stock_detection')) {
        wp_schedule_event(time(), 'mia_detection_interval', 'mia_automatic_stock_detection');
    }
}

private function unscheduleDetectionCron(): void {
    // Eliminar el hook correcto
    wp_clear_scheduled_hook('mia_automatic_stock_detection');
    // También eliminar el hook antiguo por si acaso
    wp_clear_scheduled_hook('mia_auto_detection_hook');
}
```

#### Solución 2: Usar StockDetectorIntegration Directamente
```php
// En DetectionDashboard::handleToggleDetection()
if ($enabled) {
    \MiIntegracionApi\Deteccion\StockDetectorIntegration::activate();
} else {
    \MiIntegracionApi\Deteccion\StockDetectorIntegration::deactivate();
}
```

**Script de verificación y corrección**: `scripts/verificar-corregir-toggle-detection.php`

**Documentación completa**: `docs/PROBLEMA-TOGGLE-DETECCION-AUTOMATICA.md`

---

### 8. Sincronizaciones Automáticas No Controladas

**Problema**: Hay múltiples mecanismos de sincronización automática que pueden ejecutarse en secreto

**Mecanismos identificados**:

1. **StockDetector** - Cada 5 minutos
   ```php
   // Verificar estado
   get_option('mia_automatic_stock_detection_enabled', false)
   
   // Desactivar
   update_option('mia_automatic_stock_detection_enabled', false);
   wp_clear_scheduled_hook('mia_automatic_stock_detection');
   ```

2. **Hooks de WooCommerce** - En tiempo real
   ```php
   // Desactivar
   remove_action('woocommerce_update_product', ['MiIntegracionApi\Hooks\SyncHooks', 'on_product_updated']);
   remove_action('woocommerce_new_product', ['MiIntegracionApi\Hooks\SyncHooks', 'on_product_created']);
   ```

**Script de detención completa**: `scripts/detener-todas-sincronizaciones.php`

**Documentación completa**: `docs/SINCRONIZACIONES-AUTOMATICAS-ENCONTRADAS.md`

---

## 🐛 Errores de Código y Lógica

### 9. Variables No Inicializadas

**Problema**: Variables usadas antes de ser inicializadas

**Ejemplos encontrados**:
- `$as_actions_table` usada fuera del bloque donde se define

**Solución**:
```php
// Siempre inicializar variables antes de usarlas
$as_actions_table = '';
if (isset($wpdb) && $wpdb) {
    $as_actions_table = $wpdb->prefix . 'actionscheduler_actions';
    // ...
}

// Verificar antes de usar
if ($as_actions_exist && !empty($as_actions_table)) {
    // Usar $as_actions_table
}
```

---

### 10. Falta de Validación de Métodos

**Problema**: Se llaman métodos sin verificar si existen

**Solución**:
```php
// Siempre verificar métodos antes de llamarlos
if (class_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper')) {
    if (method_exists('MiIntegracionApi\\Helpers\\SyncStatusHelper', 'getCurrentSyncInfo')) {
        $status = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
    }
}
```

---

### 11. Consultas SQL Sin Preparar

**Problema**: Consultas SQL vulnerables a inyección SQL

**Solución**:
```php
// ANTES (vulnerable)
$result = $wpdb->query("
    SELECT * FROM {$wpdb->prefix}posts 
    WHERE post_title = '$title'
");

// DESPUÉS (seguro)
$result = $wpdb->query($wpdb->prepare("
    SELECT * FROM {$wpdb->prefix}posts 
    WHERE post_title = %s
", $title));
```

---

### 12. Manejo Incompleto de Errores

**Problema**: Solo se capturan `Exception`, no `Throwable`

**Solución**:
```php
try {
    // Código que puede fallar
} catch (\Exception $e) {
    $errores[] = "Error: " . $e->getMessage();
} catch (\Throwable $e) {
    $errores[] = "Error (Throwable): " . $e->getMessage();
}
```

---

## 🛠️ Scripts de Solución

### Scripts Disponibles

1. **`scripts/detener-todas-sincronizaciones.php`**
   - Detiene todas las sincronizaciones en proceso
   - Libera locks, elimina cron jobs, limpia Action Scheduler
   - **Uso**: `wp eval-file scripts/detener-todas-sincronizaciones.php`

2. **`scripts/detectar-duplicados-productos.php`**
   - Detecta y gestiona productos duplicados
   - Interfaz gráfica en WordPress admin
   - **Uso**: Activar como plugin en WordPress

3. **`scripts/verificar-corregir-toggle-detection.php`**
   - Verifica y corrige el problema del toggle
   - **Uso**: `wp eval-file scripts/verificar-corregir-toggle-detection.php`

---

## 📊 Priorización de Soluciones

### Prioridad CRÍTICA (Hacer Primero)

1. ✅ **Aumentar timeout de MySQL a 60 segundos**
   ```sql
   SET GLOBAL innodb_lock_wait_timeout = 60;
   ```

2. ✅ **Mover procesamiento de imágenes fuera de la transacción**
   - Ubicación: `BatchProcessor.php` línea ~4488
   - Impacto: Reducción de 80-85% en tiempo de locks

3. ✅ **Verificar duplicados antes de crear attachments**
   - Ubicación: `BatchProcessor.php` método `createAttachmentFromBase64()`
   - Impacto: Elimina 100% de procesamiento innecesario de imágenes duplicadas

4. ✅ **Corregir detección de SKUs duplicados**
   - Ubicación: `BatchProcessor.php` línea ~3009
   - Impacto: Evita creación de productos duplicados

### Prioridad ALTA

5. ✅ **Aumentar delay del plugin a 10-15 segundos**
   ```php
   add_filter('mia_batch_delay_seconds', function($delay) {
       return 15;
   });
   ```

6. ✅ **Unificar hooks de detección automática**
   - Ubicación: `DetectionDashboard.php`
   - Impacto: Toggle funciona correctamente

7. ✅ **Limpiar cola de Action Scheduler**
   ```sql
   UPDATE wp_actionscheduler_actions
   SET status = 'pending'
   WHERE status = 'in-progress'
   AND last_attempt_date < DATE_SUB(NOW(), INTERVAL 10 MINUTE);
   ```

### Prioridad MEDIA

8. ✅ **Desactivar generación de thumbnails durante sync**
   - Opcional, mejora rendimiento

9. ✅ **Implementar verificación robusta de productos existentes**
   - Múltiples métodos de verificación

10. ✅ **Agregar locks de base de datos para evitar condiciones de carrera**

### Prioridad BAJA

11. ✅ **Monitorear cola de Action Scheduler regularmente**

12. ✅ **Implementar circuit breaker para detectar problemas**

---

## 📝 Checklist de Verificación

### Antes de Sincronizar

- [ ] Verificar que no hay sincronizaciones en proceso
  ```bash
  wp eval-file scripts/detener-todas-sincronizaciones.php
  ```

- [ ] Verificar estado del toggle
  ```bash
  wp option get mia_automatic_stock_detection_enabled
  ```

- [ ] Verificar cron jobs activos
  ```bash
  wp cron event list | grep -i "mia\|verial\|sync"
  ```

- [ ] Verificar timeout de MySQL
  ```sql
  SHOW VARIABLES LIKE '%lock_wait_timeout%';
  ```

### Después de Sincronizar

- [ ] Verificar productos duplicados
  - Usar script: `scripts/detectar-duplicados-productos.php`

- [ ] Verificar logs de errores
  ```bash
  tail -f /var/log/php-fpm/error.log
  ```

- [ ] Verificar Action Scheduler
  ```sql
  SELECT status, COUNT(*) 
  FROM wp_actionscheduler_actions 
  GROUP BY status;
  ```

---

## 🔗 Referencias a Documentos Detallados

- **Timeout de Action Scheduler**: `docs/SOLUCION-ERROR-ACTION-SCHEDULER-TIMEOUT.md`
- **Duplicados de Productos**: `docs/PROBLEMA-DUPLICADOS-PRODUCTOS-SKU.md`
- **Duplicados de Imágenes**: `docs/PROBLEMA-DUPLICADOS-IMAGENES.md`
- **Procesamiento de Imágenes**: `docs/ANALISIS-IMAGENES-CAUSA-TIMEOUT.md`
- **Toggle de Detección**: `docs/PROBLEMA-TOGGLE-DETECCION-AUTOMATICA.md`
- **Sincronizaciones Automáticas**: `docs/SINCRONIZACIONES-AUTOMATICAS-ENCONTRADAS.md`

---

## 📞 Soporte

Si encuentras algún error adicional o necesitas ayuda con las soluciones:

1. Revisa los documentos específicos mencionados arriba
2. Ejecuta los scripts de verificación y corrección
3. Revisa los logs del sistema
4. Consulta los logs de WordPress

---

**Última revisión**: 2025-11-04

