# Arquitectura de Polling Unificado

## Problema Actual

Actualmente hay múltiples componentes que gestionan polling de forma independiente, causando:
- Duplicación de polling (múltiples intervalos para 'syncProgress')
- Conflictos entre componentes
- Lógica dispersa y difícil de mantener
- Verificaciones redundantes

## Arquitectura Propuesta

### Principio: PollingManager como Único Gestor

**PollingManager** es el único responsable de:
- Crear y gestionar intervalos (`setInterval`/`clearInterval`)
- Mantener el registro de polling activos
- Emitir eventos cuando se ejecutan callbacks

**Otros componentes** solo:
- Solicitan inicio/detención de polling a PollingManager
- Se suscriben a eventos para recibir actualizaciones
- NO crean intervalos directamente

## Responsabilidades por Componente

### PollingManager
- ✅ Único responsable de `setInterval`/`clearInterval`
- ✅ Gestiona registro de polling activos (`intervals` Map)
- ✅ Emite eventos cuando se ejecutan callbacks
- ✅ Métodos: `startPolling()`, `stopPolling()`, `stopAllPolling()`, `isPollingActive()`
- ✅ Sistema de eventos: `on()`, `off()`, `emit()`

### Phase1Manager
- ❌ NO debe usar `setInterval` directamente
- ✅ Debe solicitar a PollingManager: `pollingManager.startPolling('phase1', checkPhase1Complete, interval)`
- ✅ Debe suscribirse a eventos si necesita actualizaciones de otros componentes
- ✅ Método público: `startPolling()` → delega a PollingManager

### Phase2Manager
- ❌ NO debe verificar si el polling ya está activo (PollingManager lo hace)
- ❌ NO debe usar `setTimeout` para iniciar polling
- ✅ Debe solicitar directamente: `pollingManager.startPolling('syncProgress', checkSyncProgress, interval)`
- ✅ Debe suscribirse a eventos si necesita actualizaciones

### SyncDashboard
- ❌ NO debe iniciar polling directamente
- ✅ Debe solicitar a Phase1Manager o Phase2Manager que inicien su polling
- ✅ Debe suscribirse a eventos para actualizar UI
- ✅ Método `startPollingIfNeeded()` → debe delegar a los Managers correspondientes

### SyncProgress
- ❌ NO debe iniciar polling
- ✅ Solo ejecuta la lógica de verificación cuando es llamado por PollingManager
- ✅ Emite eventos a través de PollingManager después de verificar

## Flujo de Polling Unificado

### Fase 1 (Imágenes)
```
SyncDashboard.startPhase1()
  → Phase1Manager.start()
    → Phase1Manager.startPolling()
      → PollingManager.startPolling('phase1', checkPhase1Complete, 2000)
        → PollingManager crea intervalo
        → PollingManager ejecuta checkPhase1Complete cada 2s
          → checkPhase1Complete emite evento 'syncProgress'
            → Suscriptores reciben actualización
```

### Fase 2 (Productos)
```
SyncDashboard.startPhase2() o Phase2Manager.start()
  → Phase2Manager.handleSuccess()
    → PollingManager.startPolling('syncProgress', checkSyncProgress, interval)
      → PollingManager crea intervalo
      → PollingManager ejecuta checkSyncProgress cada Xs
        → checkSyncProgress emite evento 'syncProgress'
          → Suscriptores reciben actualización
```

## Eventos del Sistema

### Eventos Emitidos por PollingManager

1. **'syncProgress'** - Progreso de sincronización actualizado
   - Emitido por: `checkSyncProgress`, `checkPhase1Complete`
   - Datos: `{ syncData, phase1Status, phase2Status, timestamp }`
   - Suscriptores: ConsoleManager, SyncDashboard

2. **'syncError'** - Error en sincronización
   - Emitido por: `checkSyncProgress`, `checkPhase1Complete`
   - Datos: `{ error, xhr, status, timestamp }`
   - Suscriptores: ConsoleManager, ErrorHandler

3. **'syncCompleted'** - Sincronización completada
   - Emitido por: `checkSyncProgress`, `checkPhase1Complete`
   - Datos: `{ phase, data, timestamp }`
   - Suscriptores: SyncDashboard, ConsoleManager

## Reglas de Inicio/Detención

### Inicio de Polling
- Solo un componente puede iniciar cada tipo de polling
- PollingManager verifica si ya existe antes de crear uno nuevo
- Si ya existe, retorna el ID existente (no crea duplicado)

### Detención de Polling
- Cualquier componente puede solicitar detención
- PollingManager limpia el intervalo y lo elimina del registro
- Los eventos dejan de emitirse automáticamente

### Nombres de Polling
- `'phase1'` - Polling de Fase 1 (verificación de completitud)
- `'syncProgress'` - Polling de progreso general (Fase 2)

## Migración

### Paso 1: Migrar Phase1Manager
- Reemplazar `setInterval` directo por `PollingManager.startPolling()`
- Usar nombre único: `'phase1'`

### Paso 2: Simplificar Phase2Manager
- Eliminar verificaciones redundantes
- Eliminar `setTimeout` innecesario
- Iniciar polling directamente

### Paso 3: Unificar SyncDashboard
- Eliminar inicio directo de polling
- Delegar a Phase1Manager/Phase2Manager
- Suscribirse a eventos para actualizar UI

### Paso 4: Verificar SyncProgress
- Asegurar que solo emite eventos, no inicia polling
- Verificar que los eventos se emiten correctamente

## Beneficios Esperados

1. **Sin duplicaciones**: Solo un intervalo por tipo de polling
2. **Claridad**: Responsabilidades bien definidas
3. **Mantenibilidad**: Cambios en un solo lugar (PollingManager)
4. **Testabilidad**: Fácil mockear PollingManager
5. **Escalabilidad**: Fácil agregar nuevos tipos de polling

