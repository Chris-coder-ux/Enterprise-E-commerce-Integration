# Análisis: Error `isDebugEnabled()` y Posible Problema con Polling

## 📋 Resumen Ejecutivo

El error `Call to undefined method MiIntegracionApi\Logging\Core\LoggerBasic::isDebugEnabled()` sigue ocurriendo en producción porque **el código del servidor no está actualizado**. Sin embargo, el usuario reporta que "todo empieza a fallar una vez que el sistema de rellamada del polling empieza a funcionar", lo que sugiere un posible problema de concurrencia.

## 🔍 Análisis del Error

### Estado Actual del Código

**✅ Código Local (Correcto):**
```php
// includes/Helpers/MapProduct.php:719
if (defined('WP_DEBUG') && WP_DEBUG) {
    // Logging detallado solo en modo DEBUG
}
```

**❌ Código en Producción (Desactualizado):**
```php
// El servidor todavía tiene código antiguo con:
if ($this->logger->isDebugEnabled()) { // ❌ Método no existe
    // ...
}
```

### Comportamiento Actual

1. **✅ Captura de excepciones funciona**: El batch procesa 8/10 productos exitosamente
2. **❌ Error en 2 productos**: Los productos con `isDebugEnabled()` fallan pero no causan rollback completo
3. **✅ Transacción se confirma**: Los 8 productos exitosos se guardan correctamente

## 🔄 Análisis del Polling

### Flujo del Polling

1. **`get_sync_progress_callback()`** (cada 2 segundos):
   - ✅ Solo lee estado (`SyncStatusHelper::getCurrentSyncInfo()`)
   - ✅ NO inicia sincronizaciones
   - ✅ NO adquiere locks
   - ✅ Es seguro y no causa problemas de concurrencia

2. **`sync_products_batch()`** (solo al iniciar Fase 2):
   - ✅ Tiene protección de lock en `handle_sync_request()`
   - ✅ Solo se llama una vez al iniciar Fase 2
   - ✅ No se llama desde el polling

### Posibles Problemas de Concurrencia

**Escenario 1: Múltiples llamadas a `sync_products_batch`**
- **Protección**: `this.phase2Starting` flag en `SyncDashboard.js`
- **Protección**: `window.phase2Initialized` flag en `Phase2Manager.js`
- **Protección**: Lock en `handle_sync_request()` (línea 1010)
- **Conclusión**: ✅ Protegido contra múltiples llamadas

**Escenario 2: Polling interfiriendo con procesamiento**
- **Análisis**: El polling solo lee estado, no procesa lotes
- **Conclusión**: ✅ No hay interferencia

**Escenario 3: WordPress Cron procesando lotes mientras polling está activo**
- **Análisis**: El procesamiento de lotes usa el mismo lock (`sync_global`)
- **Conclusión**: ✅ Protegido por lock

## 🎯 Causa Raíz del Problema

### Problema Principal: Código Desactualizado en Producción

El error `isDebugEnabled()` ocurre porque:
1. El código en producción tiene la versión antigua de `MapProduct.php`
2. El método `isDebugEnabled()` no existe en `LoggerBasic`
3. La captura de excepciones funciona, pero el error sigue ocurriendo

### Posible Problema Secundario: Timing del Polling

Si el usuario reporta que "todo empieza a fallar cuando el polling funciona", podría ser:
1. **Coincidencia temporal**: El polling se activa justo cuando el batch procesa productos con errores
2. **Carga adicional**: El polling hace consultas cada 2 segundos que podrían aumentar la carga del servidor
3. **Race condition**: Aunque está protegido, podría haber un timing issue

## ✅ Solución Inmediata

### 1. Subir Código Actualizado

**Archivo crítico a subir:**
```
includes/Helpers/MapProduct.php
```

**Verificación antes de subir:**
```bash
grep -n "isDebugEnabled" includes/Helpers/MapProduct.php
# No debe encontrar nada
```

**Verificación después de subir:**
```bash
# En el servidor, verificar que la línea 719 tiene:
if (defined('WP_DEBUG') && WP_DEBUG) {
```

### 2. Verificar Protecciones de Concurrencia

**Verificar que el lock funciona:**
- El log muestra: `"Bloqueo adquirido atómicamente en tabla"`
- El lock se mantiene durante el procesamiento
- El lock se libera al finalizar

**Verificar flags de protección:**
- `this.phase2Starting` en `SyncDashboard.js`
- `window.phase2Initialized` en `Phase2Manager.js`
- `pollingManager.isPollingActive()` antes de iniciar polling

## 🔧 Mejoras Adicionales Recomendadas

### 1. Añadir Logging de Concurrencia

Añadir logging cuando se detectan múltiples llamadas simultáneas:

```php
// includes/Core/Sync_Manager.php:1010
if (!SyncLock::acquire($lockEntity, 7200, 3, [...])) {
    $this->logger->warning('Intento de adquirir lock mientras otro proceso está activo', [
        'lock_entity' => $lockEntity,
        'lock_info' => SyncLock::getLockInfo($lockEntity),
        'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
    ]);
    // ...
}
```

### 2. Añadir Protección en `get_sync_progress_callback()`

Aunque es seguro, añadir logging si se detecta una sincronización en progreso:

```php
// includes/Admin/AjaxSync.php:770
$sync_info = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
if (!empty($sync_info['in_progress'])) {
    // Logging opcional para debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MIA DEBUG] Polling verificando progreso mientras sincronización está activa');
    }
}
```

### 3. Reducir Frecuencia del Polling Durante Errores

Si hay errores, reducir la frecuencia del polling para evitar sobrecarga:

```javascript
// assets/js/dashboard/sync/SyncProgress.js
if (response.data.errors > 0) {
    // Reducir frecuencia si hay errores
    pollingManager.config.currentInterval = pollingManager.config.intervals.error || 5000;
}
```

## 📊 Conclusión

1. **Problema Principal**: Código desactualizado en producción con `isDebugEnabled()`
2. **Solución Inmediata**: Subir `MapProduct.php` actualizado
3. **Problema Secundario**: Posible coincidencia temporal entre polling y errores
4. **Protecciones**: El sistema tiene protecciones adecuadas contra concurrencia

## 🚀 Acción Requerida

1. ✅ **Subir `includes/Helpers/MapProduct.php` actualizado al servidor**
2. ✅ **Verificar que no hay `isDebugEnabled()` en el servidor**
3. ✅ **Probar sincronización completa después de actualizar**
4. ⚠️ **Si el problema persiste después de actualizar**, investigar timing del polling

