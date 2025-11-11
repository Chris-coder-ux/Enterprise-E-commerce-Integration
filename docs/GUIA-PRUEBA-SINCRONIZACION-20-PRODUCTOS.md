# 🧪 Guía de Prueba: Sincronización con Lotes de 20 Productos

**Objetivo**: Verificar si ocurren los problemas identificados (duplicados de imágenes y timeouts) antes de implementar soluciones.

---

## ⚙️ Configuración del Tamaño de Lote

### Opción 1: Verificar/Configurar el Tamaño Actual

El tamaño por defecto ya es **20 productos**, pero puedes verificar o configurarlo:

**Vía WP-CLI**:
```bash
# Ver tamaño actual
wp option get mi_integracion_api_batch_size_productos

# Configurar a 20 productos (si no está configurado)
wp option set mi_integracion_api_batch_size_productos 20
```

**Vía Código PHP** (en `wp-config.php` o `functions.php`):
```php
// Configurar temporalmente para la prueba
add_action('init', function() {
    if (!get_option('mi_integracion_api_batch_size_productos')) {
        \MiIntegracionApi\Helpers\BatchSizeHelper::setBatchSize('productos', 20);
    }
}, 1);
```

**Vía Dashboard de WordPress**:
- Ir a la configuración del plugin
- Buscar "Tamaño de lote" o "Batch Size"
- Establecer a 20

---

## 📊 Qué Observar Durante la Prueba

### 1. **Errores de Timeout en Logs**

**Dónde buscar**:
- Logs de WordPress: `wp-content/debug.log`
- Logs del plugin (si tiene sistema de logging)
- Errores de PHP en el servidor

**Qué buscar**:
```
Lock wait timeout exceeded
ActionScheduler_DBStore
RuntimeException: No se han podido solicitar acciones
```

### 2. **Duplicados de Imágenes en Media Library**

**Dónde verificar**:
- WordPress Admin → Media → Library
- Buscar imágenes con nombres como: `verial-image-{product_id}-{uniqid}`

**Qué buscar**:
- Múltiples attachments con el mismo contenido visual
- Imágenes duplicadas del mismo producto
- Múltiples archivos con mismo nombre base pero diferentes `uniqid`

**Cómo verificar**:
```sql
-- Ver attachments creados recientemente
SELECT p.ID, p.post_title, p.post_date, 
       pm.meta_value as product_id
FROM wp_posts p
LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
WHERE p.post_type = 'attachment'
  AND p.post_mime_type LIKE 'image%'
  AND p.post_title LIKE 'verial-image-%'
  AND p.post_date >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY p.post_date DESC;
```

### 3. **Tiempo de Procesamiento por Lote**

**Qué medir**:
- Tiempo desde que empieza un batch hasta que termina
- Tiempo de procesamiento de imágenes
- Tiempo total de sincronización

**Dónde buscar**:
- Logs del plugin con timestamps
- Logs de WordPress con tiempos de ejecución

### 4. **Locks en Base de Datos**

**Cómo verificar** (MySQL):
```sql
-- Ver transacciones activas
SHOW PROCESSLIST;

-- Ver locks de InnoDB
SHOW ENGINE INNODB STATUS;

-- Ver procesos bloqueados
SELECT * FROM information_schema.innodb_locks;
SELECT * FROM information_schema.innodb_lock_waits;
```

### 5. **Acciones en Action Scheduler**

**Dónde verificar**:
- WordPress Admin → WooCommerce → Status → Scheduled Actions
- O vía SQL:

```sql
-- Ver acciones pendientes
SELECT COUNT(*) as pending_count
FROM wp_actionscheduler_actions
WHERE status = 'pending'
  AND hook LIKE '%mia%';

-- Ver acciones en progreso
SELECT COUNT(*) as in_progress_count
FROM wp_actionscheduler_actions
WHERE status = 'in-progress'
  AND hook LIKE '%mia%';

-- Ver acciones bloqueadas (más de 10 minutos)
SELECT COUNT(*) as stuck_count
FROM wp_actionscheduler_actions
WHERE status = 'in-progress'
  AND last_attempt_date < DATE_SUB(NOW(), INTERVAL 10 MINUTE);
```

---

## 📝 Checklist de Observación

### Antes de la Sincronización

- [ ] Verificar tamaño de lote configurado (20 productos)
- [ ] Contar attachments existentes en media library
- [ ] Verificar acciones pendientes en Action Scheduler
- [ ] Revisar logs anteriores para errores previos
- [ ] Verificar espacio en disco disponible

### Durante la Sincronización

- [ ] Monitorear logs en tiempo real
- [ ] Verificar tiempo de procesamiento de cada lote
- [ ] Contar errores de timeout (si ocurren)
- [ ] Observar uso de memoria del servidor
- [ ] Verificar locks en base de datos

### Después de la Sincronización

- [ ] Contar attachments nuevos creados
- [ ] Verificar si hay duplicados (mismo producto, múltiples attachments)
- [ ] Revisar logs completos para errores
- [ ] Verificar acciones en Action Scheduler (pendientes, bloqueadas)
- [ ] Comparar espacio en disco antes/después
- [ ] Verificar si productos tienen imágenes asignadas correctamente

---

## 🔍 Scripts de Verificación

### Script 1: Contar Duplicados de Imágenes

```php
<?php
// Contar attachments duplicados por producto
require_once('wp-load.php');

$attachments = get_posts([
    'post_type' => 'attachment',
    'post_mime_type' => 'image',
    'post_title' => 'verial-image-%',
    'posts_per_page' => -1,
    'orderby' => 'post_date',
    'order' => 'DESC'
]);

$by_product = [];
foreach ($attachments as $attachment) {
    // Extraer product_id del título
    if (preg_match('/verial-image-(\d+)-/', $attachment->post_title, $matches)) {
        $product_id = $matches[1];
        if (!isset($by_product[$product_id])) {
            $by_product[$product_id] = [];
        }
        $by_product[$product_id][] = $attachment->ID;
    }
}

$duplicates = [];
foreach ($by_product as $product_id => $attachment_ids) {
    if (count($attachment_ids) > 1) {
        $duplicates[$product_id] = count($attachment_ids);
    }
}

echo "Total de productos con imágenes: " . count($by_product) . "\n";
echo "Productos con duplicados: " . count($duplicates) . "\n";
echo "Total de attachments duplicados: " . array_sum($duplicates) . "\n";

if (!empty($duplicates)) {
    echo "\nTop 10 productos con más duplicados:\n";
    arsort($duplicates);
    $top10 = array_slice($duplicates, 0, 10, true);
    foreach ($top10 as $product_id => $count) {
        echo "  Producto $product_id: $count attachments\n";
    }
}
```

### Script 2: Verificar Timeouts en Logs

```bash
#!/bin/bash
# Buscar errores de timeout en logs

LOG_FILE="wp-content/debug.log"

if [ -f "$LOG_FILE" ]; then
    echo "Buscando errores de timeout en $LOG_FILE..."
    echo ""
    
    grep -i "timeout" "$LOG_FILE" | tail -20
    echo ""
    
    echo "Buscando errores de Action Scheduler..."
    grep -i "ActionScheduler" "$LOG_FILE" | tail -20
    echo ""
    
    echo "Buscando errores de lock..."
    grep -i "lock wait" "$LOG_FILE" | tail -20
else
    echo "Log file no encontrado: $LOG_FILE"
fi
```

---

## 📈 Métricas a Registrar

Crea un documento con las siguientes métricas:

### Tiempos

- **Tiempo total de sincronización**: _____ segundos
- **Tiempo promedio por lote**: _____ segundos
- **Tiempo máximo de un lote**: _____ segundos
- **Tiempo mínimo de un lote**: _____ segundos

### Cantidades

- **Total de productos sincronizados**: _____
- **Total de lotes procesados**: _____
- **Attachments creados**: _____
- **Attachments duplicados detectados**: _____
- **Errores de timeout**: _____

### Errores

- **Errores de "Lock wait timeout"**: _____
- **Errores de Action Scheduler**: _____
- **Errores de procesamiento de imágenes**: _____

---

## ✅ Resultados Esperados vs. Observados

### Si el Problema Existe (sin soluciones)

**Esperado**:
- ❌ Múltiples attachments duplicados para el mismo producto
- ❌ Errores de "Lock wait timeout exceeded"
- ❌ Transacciones que duran más de 30 segundos
- ❌ Acciones bloqueadas en Action Scheduler

### Si las Soluciones Funcionan (después de implementar)

**Esperado**:
- ✅ Reutilización de attachments existentes (no duplicados)
- ✅ Sin errores de timeout
- ✅ Transacciones cortas (< 10 segundos)
- ✅ Sin acciones bloqueadas

---

## 🚀 Pasos para Ejecutar la Prueba

1. **Preparación**:
   ```bash
   # Configurar tamaño de lote a 20
   wp option set mi_integracion_api_batch_size_productos 20
   
   # Limpiar logs anteriores
   > wp-content/debug.log
   
   # Verificar estado inicial
   wp verial sync status products
   ```

2. **Ejecutar Sincronización**:
   ```bash
   # Iniciar sincronización
   wp verial sync start products verial_to_wc --batch-size=20
   
   # O desde el dashboard de WordPress
   ```

3. **Monitorear en Tiempo Real**:
   ```bash
   # Ver logs en tiempo real
   tail -f wp-content/debug.log | grep -i "verial\|timeout\|lock"
   
   # Ver estado de sincronización
   watch -n 5 'wp verial sync status products'
   ```

4. **Verificar Resultados**:
   - Ejecutar scripts de verificación
   - Revisar media library en WordPress
   - Revisar Action Scheduler
   - Comparar métricas antes/después

---

## 📝 Template de Reporte

```markdown
# Reporte de Prueba: Sincronización con Lotes de 20 Productos

**Fecha**: _____
**Hora de inicio**: _____
**Hora de finalización**: _____
**Duración total**: _____ segundos

## Configuración
- Tamaño de lote: 20 productos
- Total de productos: _____
- Total de lotes: _____

## Resultados

### Tiempos
- Tiempo total: _____ segundos
- Tiempo promedio por lote: _____ segundos
- Tiempo máximo: _____ segundos
- Tiempo mínimo: _____ segundos

### Attachments
- Attachments creados: _____
- Attachments duplicados: _____
- Productos con imágenes: _____

### Errores
- Errores de timeout: _____
- Errores de lock: _____
- Errores de Action Scheduler: _____

## Observaciones

### Problemas Encontrados
1. _____
2. _____
3. _____

### Notas Adicionales
_____
```

---

## 🎯 Siguiente Paso

Después de la prueba, compara los resultados con los problemas identificados:
- Si hay duplicados → Confirma necesidad de implementar verificación de duplicados
- Si hay timeouts → Confirma necesidad de mover imágenes fuera de transacciones
- Si ambos → Confirma necesidad de ambas soluciones

Usa estos resultados para priorizar las soluciones a implementar.

