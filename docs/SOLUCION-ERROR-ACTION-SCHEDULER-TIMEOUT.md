# 🔧 Solución: Error "Lock wait timeout exceeded" en Action Scheduler

**Error**: `Lock wait timeout exceeded; try restarting transaction`  
**Ubicación**: `ActionScheduler_DBStore.php:1019`  
**Causa**: Múltiples procesos intentando procesar la cola de Action Scheduler simultáneamente

---

## 🔍 Diagnóstico

### ✅ Aclaración Importante

**El plugin YA tiene un delay de 5 segundos entre lotes** configurado en `Sync_Manager::getBatchDelay()`.  
**El problema NO es el delay del plugin**, sino la competencia en Action Scheduler de WooCommerce.

### Causa Real del Error

**En tu caso específico** (WooCommerce vacío, solo productos):
1. **WordPress Cron NO es exacto en el timing**:
   - Si programas 20 batches con delays de 5, 10, 15... segundos
   - Pero el cron se ejecuta 10 minutos después
   - Puede intentar ejecutar TODOS los batches acumulados al mismo tiempo

2. **Action Scheduler intenta reclamar múltiples acciones simultáneamente**:
   - Cuando WordPress Cron se activa, Action Scheduler intenta "reclamar" todas las acciones programadas
   - Si hay 20 batches programados, intenta reclamarlos todos al mismo tiempo
   - Todos compiten por locks en la base de datos

3. **El timeout de MySQL es demasiado bajo** para manejar esta competencia

4. **`processQueueInBackground()` puede estar creando procesos adicionales** (línea 12906 de Sync_Manager.php)

**Problema principal**: El delay de 5 segundos está bien para programar, pero WordPress Cron puede ejecutar múltiples batches acumulados simultáneamente cuando finalmente se activa.

### 🔍 CAUSA RAÍZ IDENTIFICADA: Procesamiento de Imágenes

**El procesamiento de imágenes está causando el problema**:

1. **Transacciones muy largas** (30-60 segundos):
   - Cada batch procesa 50 productos con ~5 imágenes cada uno = 250 imágenes
   - Cada imagen requiere múltiples operaciones de base de datos
   - La transacción se mantiene abierta durante TODO el procesamiento

2. **Operaciones por imagen**:
   - `wp_insert_attachment()` → INSERT en posts + postmeta
   - `wp_generate_attachment_metadata()` → Procesa imagen (100-500ms)
   - `wp_update_attachment_metadata()` → UPDATE postmeta múltiple
   - `set_post_thumbnail()` → UPDATE postmeta
   - **Total: ~10-15 queries por imagen × 250 imágenes = ~3,000 queries en una transacción**

3. **Competencia por locks**:
   - Múltiples batches procesan imágenes simultáneamente
   - Todos compiten por locks en `wp_posts` y `wp_postmeta`
   - Action Scheduler también usa estas tablas → **Conflicto directo**

**Ver análisis detallado**: `docs/ANALISIS-IMAGENES-CAUSA-TIMEOUT.md`

### ⚠️ PROBLEMA ADICIONAL: Duplicados Innecesarios

**El método `createAttachmentFromBase64()` NO verifica si la imagen ya existe** antes de crear un nuevo attachment.

**Consecuencias**:
- Cada sincronización crea attachments duplicados aunque la imagen ya exista
- Procesamiento innecesario de imágenes duplicadas
- Transacciones más largas de lo necesario
- Más locks en la base de datos

**Ver análisis detallado**: `docs/PROBLEMA-DUPLICADOS-IMAGENES.md`

**Solución**: Agregar verificación de duplicados por hash MD5 antes de crear attachments.

---

## ✅ Soluciones Inmediatas

### 1. Aumentar Timeout de MySQL (RECOMENDADO)

#### Opción A: Configurar en MySQL directamente

```sql
-- Conectar a MySQL
mysql -u root -p

-- Aumentar timeouts
SET GLOBAL innodb_lock_wait_timeout = 60;
SET GLOBAL lock_wait_timeout = 60;

-- Verificar configuración
SHOW VARIABLES LIKE '%lock_wait_timeout%';
SHOW VARIABLES LIKE '%innodb_lock_wait_timeout%';
```

#### Opción B: Configurar en `my.cnf` o `my.ini` (permanente)

```ini
[mysqld]
innodb_lock_wait_timeout = 60
lock_wait_timeout = 60
```

#### Opción C: Configurar en WordPress (temporal)

```php
// En wp-config.php
define('WP_DB_TIMEOUT', 60);
```

### 2. Limpiar Cola de Action Scheduler

#### Ver estado actual

```sql
-- Ver acciones pendientes
SELECT COUNT(*) as pendientes 
FROM wp_actionscheduler_actions 
WHERE status = 'pending';

-- Ver acciones bloqueadas (in-progress)
SELECT COUNT(*) as bloqueadas 
FROM wp_actionscheduler_actions 
WHERE status = 'in-progress';

-- Ver acciones por estado
SELECT status, COUNT(*) as cantidad
FROM wp_actionscheduler_actions
GROUP BY status;
```

#### Limpiar acciones antiguas (cuidado)

```sql
-- Limpiar acciones completadas antiguas (más de 7 días)
DELETE FROM wp_actionscheduler_actions 
WHERE status = 'complete' 
AND scheduled_date < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- Limpiar acciones fallidas antiguas (más de 30 días)
DELETE FROM wp_actionscheduler_actions 
WHERE status = 'failed' 
AND scheduled_date < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Limpiar logs antiguos de acciones
DELETE FROM wp_actionscheduler_logs 
WHERE action_id NOT IN (
    SELECT action_id FROM wp_actionscheduler_actions
);
```

### 3. Desbloquear Acciones Bloqueadas

Si hay acciones "in-progress" que están atascadas:

```sql
-- Ver acciones bloqueadas por más de 10 minutos
SELECT action_id, hook, status, scheduled_date, last_attempt_date
FROM wp_actionscheduler_actions
WHERE status = 'in-progress'
AND last_attempt_date < DATE_SUB(NOW(), INTERVAL 10 MINUTE);

-- Resetear acciones bloqueadas (cuidado - solo si están realmente atascadas)
UPDATE wp_actionscheduler_actions
SET status = 'pending',
    last_attempt_date = NULL
WHERE status = 'in-progress'
AND last_attempt_date < DATE_SUB(NOW(), INTERVAL 10 MINUTE);
```

---

## 🔧 Soluciones a Nivel de Código

### 4. Mover Procesamiento de Imágenes FUERA de la Transacción (CRÍTICO)

**Este es el cambio más importante**. El procesamiento de imágenes está dentro de la transacción del batch, causando transacciones muy largas (30-60 segundos).

**Ubicación**: `includes/Core/BatchProcessor.php` línea 4488-4515

**Cambio recomendado**:

```php
// ANTES (problema):
private function handlePostSaveOperations(...) {
    // TODO se ejecuta DENTRO de la transacción del batch
    $this->setProductImages($product_id, $wc_product_data['images']);
    $this->setProductGallery($product_id, $wc_product_data['gallery']);
}

// DESPUÉS (solución):
private function handlePostSaveOperations(...) {
    // Obtener transaction manager
    $transactionManager = TransactionManager::getInstance();
    
    // Guardar metadatos y mapeo (transacción corta)
    $this->updateVerialProductMetadata($product_id, $verial_product, $batch_data);
    if (!empty($verial_product['Id'])) {
        MapProduct::upsert_product_mapping(...);
    }
    
    // CERRAR transacción antes de procesar imágenes
    // (La transacción del batch debe estar activa aquí)
    // Necesitarás pasar el operationId para cerrarla
    
    // Procesar imágenes FUERA de la transacción principal
    $this->setProductImages($product_id, $wc_product_data['images']);
    $this->setProductGallery($product_id, $wc_product_data['gallery']);
}
```

**Nota**: Esto requiere modificar el flujo para cerrar la transacción después de guardar el producto pero antes de procesar imágenes.

**Alternativa más simple**: Procesar imágenes después de commit:

```php
// En el método que llama a handlePostSaveOperations
// Después de commit de la transacción:
$transactionManager->commit("batch_processing", $operationId);

// Luego procesar imágenes
$this->setProductImages($product_id, $wc_product_data['images']);
$this->setProductGallery($product_id, $wc_product_data['gallery']);
```

**Impacto esperado**: Reducción de 80-85% en tiempo de locks de base de datos.

### 5. Aumentar Delay del Plugin (RECOMENDADO en tu caso)

El plugin tiene un delay de 5 segundos, pero si WordPress Cron se ejecuta tarde, puede acumular muchos batches.  
**Aumenta el delay a 10-15 segundos** para reducir la competencia:

```php
// En functions.php del tema o en un plugin
add_filter('mia_batch_delay_seconds', function($delay) {
    return 15; // Aumentar a 15 segundos entre batches
});
```

**Ubicación del código**: `includes/Core/Sync_Manager.php` línea 12925-12934

**Razón**: Con más delay, aunque WordPress Cron se ejecute tarde, habrá menos batches acumulados esperando ejecución simultánea.

### 6. Desactivar Generación de Thumbnails Durante Sincronización (Opcional)

Para reducir el tiempo de procesamiento de imágenes:

```php
// Antes de procesar batch
add_filter('intermediate_image_sizes', '__return_empty_array');

// Procesar productos e imágenes...

// Después
remove_filter('intermediate_image_sizes', '__return_empty_array');

// Generar thumbnails después en background
wp_schedule_single_event(time() + 60, 'mia_generate_thumbnails', [$product_ids]);
```

**Ventajas**:
- ✅ `wp_generate_attachment_metadata()` es mucho más rápido
- ✅ Menos operaciones de base de datos

### 7. Verificar Antes de Programar (Evitar Duplicados)

Agregar verificación antes de programar nuevas acciones:

```php
// Verificar si ya hay una acción pendiente similar
$pending_actions = wp_get_scheduled_event('mia_execute_async_cleanup', [$jobId]);
if ($pending_actions) {
    // Ya existe, no programar otra
    return ['success' => false, 'message' => 'Ya existe una acción pendiente'];
}
```

### 6. Usar Transacciones Más Cortas

Si las transacciones duran demasiado, dividirlas en transacciones más pequeñas:

```php
// En lugar de una transacción grande:
$transactionManager->beginTransaction("batch_processing", $operationId);
// ... procesar todo el lote ...
$transactionManager->commit("batch_processing", $operationId);

// Usar transacciones por producto:
foreach ($batch as $item) {
    $transactionManager->beginTransaction("batch_item", $itemId);
    // ... procesar un solo item ...
    $transactionManager->commit("batch_item", $itemId);
}
```

---

## 📊 Monitoreo y Prevención

### 7. Monitorear Cola de Action Scheduler

Crear un script de monitoreo:

```php
// scripts/monitor-action-scheduler.php
<?php
require_once __DIR__ . '/../wp-load.php';

global $wpdb;

$stats = [
    'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'pending'"),
    'in_progress' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'in-progress'"),
    'failed' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'failed'"),
    'blocked_long' => $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions 
        WHERE status = 'in-progress' 
        AND last_attempt_date < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ")
];

echo "📊 Estado de Action Scheduler:\n";
echo "  Pendientes: {$stats['pending']}\n";
echo "  En progreso: {$stats['in_progress']}\n";
echo "  Fallidas: {$stats['failed']}\n";
echo "  Bloqueadas (>10 min): {$stats['blocked_long']}\n";

if ($stats['blocked_long'] > 0) {
    echo "\n⚠️  ADVERTENCIA: Hay acciones bloqueadas\n";
}
```

### 8. Configurar Límites de Procesamiento

Limitar cuántas acciones se procesan simultáneamente:

```php
// En wp-config.php
define('ACTION_SCHEDULER_CONCURRENT_BATCHES', 1); // Procesar solo 1 batch a la vez
define('ACTION_SCHEDULER_BATCH_SIZE', 25); // Reducir tamaño de batch
```

---

## 🚨 Acciones de Emergencia

Si el error es crítico y está bloqueando el sitio:

### Paso 1: Detener Procesos Activos

```sql
-- Ver procesos de MySQL bloqueados
SHOW PROCESSLIST;

-- Matar procesos bloqueados (reemplazar ID con el ID real)
KILL <process_id>;
```

### Paso 2: Resetear Action Scheduler

```sql
-- Resetear todas las acciones bloqueadas
UPDATE wp_actionscheduler_actions
SET status = 'pending'
WHERE status = 'in-progress';
```

### Paso 3: Limpiar Cola Completamente

```sql
-- CUIDADO: Esto elimina todas las acciones pendientes
TRUNCATE TABLE wp_actionscheduler_actions;
TRUNCATE TABLE wp_actionscheduler_logs;
TRUNCATE TABLE wp_actionscheduler_claims;
```

---

## ✅ Prevención a Largo Plazo

### 9. Implementar Circuit Breaker

Detectar cuando hay demasiados errores y pausar temporalmente:

```php
// En BatchProcessor
private function checkActionSchedulerHealth(): bool {
    global $wpdb;
    
    $blocked = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions 
        WHERE status = 'in-progress' 
        AND last_attempt_date < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    
    if ($blocked > 10) {
        // Demasiadas acciones bloqueadas, pausar
        return false;
    }
    
    return true;
}
```

### 10. Usar Alternativa a Action Scheduler

Si el problema persiste, considerar usar WordPress Cron directamente:

```php
// En lugar de Action Scheduler
if (class_exists('ActionScheduler')) {
    as_schedule_single_action(...);
} else {
    // Fallback a WordPress Cron
    wp_schedule_single_event(...);
}
```

---

## 📝 Checklist de Verificación (Caso Específico: Solo Productos)

### Prioridad ALTA (Hacer Primero)

- [ ] **Aumentar timeout de MySQL a 60 segundos** (CRÍTICO)
- [ ] **Verificar duplicados antes de crear attachments** (CRÍTICO - Ver `docs/PROBLEMA-DUPLICADOS-IMAGENES.md`)
- [ ] **Mover procesamiento de imágenes fuera de la transacción** (CRÍTICO - Solución 4)
- [ ] Limpiar cola de Action Scheduler

### Prioridad MEDIA

- [ ] **Aumentar delay del plugin a 10-15 segundos** (RECOMENDADO)
- [ ] Verificar acciones bloqueadas
- [ ] Desactivar generación de thumbnails durante sync (Opcional - Solución 6)

### Prioridad BAJA

- [ ] Desactivar `processQueueInBackground()` si no es necesario
- [ ] Monitorear cola regularmente
- [ ] Verificar que no haya múltiples sincronizaciones iniciándose simultáneamente

---

## 🔗 Referencias

- [WooCommerce Action Scheduler Documentation](https://actionscheduler.org/)
- [MySQL Lock Wait Timeout](https://dev.mysql.com/doc/refman/8.0/en/innodb-parameters.html#sysvar_innodb_lock_wait_timeout)
- [WordPress Scheduled Events](https://developer.wordpress.org/reference/functions/wp_schedule_event/)

