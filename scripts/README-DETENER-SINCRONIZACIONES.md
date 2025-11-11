# Script para Detener Todas las Sincronizaciones

## 📋 Descripción

Este script detiene de forma segura todas las sincronizaciones activas en el sistema, incluyendo:

- ✅ Cancela sincronizaciones en progreso
- ✅ Libera todos los locks de sincronización
- ✅ Elimina cron jobs relacionados
- ✅ Cancela acciones en Action Scheduler
- ✅ Limpia transients relacionados
- ✅ Desactiva detección automática
- ✅ Limpia opciones de estado
- ✅ Resetea recovery points

## 🚀 Uso

### Opción 1: WP-CLI (Recomendado)

```bash
wp eval-file scripts/detener-todas-sincronizaciones.php
```

### Opción 2: Ejecución directa (si WordPress está en la ruta correcta)

```bash
php scripts/detener-todas-sincronizaciones.php
```

## ⚠️ Solución de Problemas

### Error 500

Si obtienes un error 500, verifica:

1. **WordPress está cargado correctamente:**
   ```bash
   wp core version
   ```

2. **El plugin está activo:**
   ```bash
   wp plugin list | grep mi-integracion-api
   ```

3. **Verifica los logs de PHP:**
   ```bash
   tail -f /var/log/php-fpm/error.log
   # O según tu configuración:
   tail -f /var/log/apache2/error.log
   ```

4. **Ejecuta con WP-CLI para ver errores:**
   ```bash
   wp eval-file scripts/detener-todas-sincronizaciones.php --debug
   ```

### Error: "WordPress no está cargado correctamente"

Si ves este error, asegúrate de:

1. Ejecutar desde el directorio raíz de WordPress
2. Usar WP-CLI en lugar de ejecución directa
3. Verificar que `wp-load.php` existe en la ruta esperada

### Error: "No se pudo cargar WordPress"

El script intenta encontrar `wp-load.php` en estas rutas:
- `../../wp-load.php` (desde `scripts/`)
- `../../../wp-load.php`
- Una ruta relativa adicional

Si ninguna funciona, usa WP-CLI que maneja esto automáticamente.

## 📊 Qué hace el script

### 1. Verificación del estado actual
- Muestra el estado de sincronización actual
- Indica si hay sincronizaciones en progreso

### 2. Cancelación de sincronizaciones
- Cancela vía `Sync_Manager`
- Cancela vía `SyncStatusHelper`
- Limpia el estado de sincronización

### 3. Liberación de locks
- Libera locks vía `SyncLock::release()`
- Libera locks directamente desde la base de datos
- Limpia todos los locks activos

### 4. Eliminación de cron jobs
Elimina estos hooks:
- `mia_automatic_stock_detection`
- `mia_auto_detection_hook`
- `mi_integracion_api_daily_sync`
- `mia_process_sync_batch`
- Y otros relacionados...

### 5. Limpieza de Action Scheduler
- Cancela acciones pendientes relacionadas con el plugin
- Resetea acciones bloqueadas (más de 10 minutos)

### 6. Limpieza de transients
- Elimina todos los transients relacionados con sincronización

### 7. Desactivación de detección automática
- Desactiva el toggle de detección automática
- Desactiva `StockDetector`

### 8. Limpieza de opciones
- Elimina opciones temporales de estado

### 9. Reseteo de recovery points
- Limpia todos los puntos de recuperación

### 10. Verificación final
- Verifica que todo esté detenido
- Elimina cualquier proceso restante

## 📝 Salida del Script

El script muestra:

```
═══════════════════════════════════════════════════════════════
  DETENCIÓN DE TODAS LAS SINCRONIZACIONES EN PROCESO
═══════════════════════════════════════════════════════════════

📊 VERIFICANDO ESTADO ACTUAL...
🛑 CANCELANDO SINCRONIZACIÓN ACTUAL...
🔓 LIBERANDO LOCKS...
⏰ ELIMINANDO CRON JOBS...
📋 CANCELANDO ACCIONES EN ACTION SCHEDULER...
🧹 LIMPIANDO TRANSIENTS...
🔌 DESACTIVANDO DETECCIÓN AUTOMÁTICA...
🗑️  LIMPIANDO OPCIONES DE ESTADO...
🔄 RESETEANDO RECOVERY POINTS...

═══════════════════════════════════════════════════════════════
  VERIFICACIÓN FINAL
═══════════════════════════════════════════════════════════════

═══════════════════════════════════════════════════════════════
  RESUMEN
═══════════════════════════════════════════════════════════════

✅ Acciones realizadas: X
⚠️  Errores encontrados: Y (si los hay)
```

## ⚠️ Advertencias Importantes

1. **Este script detiene TODAS las sincronizaciones** - No hay vuelta atrás una vez ejecutado
2. **No elimina datos** - Solo detiene procesos, no borra productos ni información
3. **Revisa los logs** después de ejecutar para verificar que no hay procesos ejecutándose
4. **Corrige los problemas** antes de reactivar sincronizaciones
5. **Usa el script de verificación de toggle** antes de reactivar

## 🔄 Después de Ejecutar

1. Verifica que no se creen más productos duplicados
2. Revisa los logs del sistema
3. Corrige los problemas encontrados (duplicados, timeouts, etc.)
4. Usa `scripts/verificar-corregir-toggle-detection.php` para verificar el toggle
5. Solo reactiva sincronizaciones cuando todo esté corregido

## 📚 Scripts Relacionados

- `scripts/detectar-duplicados-productos.php` - Detectar y gestionar productos duplicados
- `scripts/verificar-corregir-toggle-detection.php` - Verificar y corregir el toggle de detección automática


