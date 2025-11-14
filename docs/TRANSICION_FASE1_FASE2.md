# Transición Fase 1 → Fase 2: Sistema de Eventos Robusto

## Arquitectura de Transición

### Flujo de Transición

```
Phase1Manager.checkPhase1Complete()
  ↓ Detecta que Fase 1 está completa
  ↓ Emite evento 'phase1Completed' a través de PollingManager
  ↓ (Opcional) Llama directamente a startPhase2() para compatibilidad
  
PollingManager.emit('phase1Completed', eventData)
  ↓ Notifica a todos los suscriptores
  
Phase2Manager.handlePhase1Completed(eventData)
  ↓ Verifica protecciones (no iniciado, no inicializado)
  ↓ Llama a Phase2Manager.start()
  
SyncDashboard (suscriptor)
  ↓ Actualiza UI automáticamente
  ↓ Marca Fase 1 como completada
  ↓ Habilita botón de Fase 2
```

## Componentes

### Phase1Manager

**Responsabilidad**: Detectar finalización de Fase 1 y emitir evento.

**Evento emitido**: `phase1Completed`

**Estructura del evento**:
```javascript
{
  phase1Status: {
    completed: true,
    products_processed: 100,
    total_products: 100,
    images_processed: 500,
    // ... otros campos
  },
  timestamp: 1234567890,
  data: {
    // Datos completos de sincronización del backend
  }
}
```

**Código relevante**:
```javascript
// En checkPhase1Complete()
if (isPhase1Completed(phase1Status)) {
  // Marcar como completada
  phase1Complete = true;
  SyncStateManager.setPhase1Initialized(false);
  
  // Detener polling
  stopPolling();
  
  // Emitir evento
  pollingManager.emit('phase1Completed', {
    phase1Status: phase1Status,
    timestamp: Date.now(),
    data: progressResponse.data
  });
  
  // Compatibilidad: llamada directa
  if (typeof startPhase2 === 'function') {
    startPhase2();
  }
}
```

### Phase2Manager

**Responsabilidad**: Escuchar evento de finalización de Fase 1 e iniciar Fase 2 automáticamente.

**Suscripción**: Se suscribe automáticamente al evento `phase1Completed` cuando se carga el script.

**Protecciones**:
1. Verifica que no esté iniciando (`getPhase2Starting()`)
2. Verifica que no esté inicializado (`getPhase2Initialized()`)
3. Usa lock atómico para prevenir ejecuciones simultáneas

**Código relevante**:
```javascript
// Suscripción automática al cargar
function initializeEventSubscriptions() {
  pollingManager.on('phase1Completed', handlePhase1Completed);
}

// Manejo del evento
function handlePhase1Completed(eventData) {
  // Protecciones
  if (SyncStateManager.getPhase2Starting()) {
    throttledWarn('Fase 2 ya se está iniciando, ignorando evento');
    return;
  }
  
  if (SyncStateManager.getPhase2Initialized()) {
    throttledWarn('Fase 2 ya está inicializada, ignorando evento');
    return;
  }
  
  // Iniciar Fase 2
  start();
}
```

### SyncDashboard

**Responsabilidad**: Actualizar UI cuando Fase 1 se completa.

**Suscripción**: Se suscribe al evento `phase1Completed` en el constructor.

**Acciones**:
- Actualiza estado de Fase 1 a 'completed'
- Detiene timer de Fase 1
- Habilita botón de Fase 2
- Actualiza dashboard con datos del evento

## Protecciones Implementadas

### 1. Lock Atómico en Phase2Manager.start()

```javascript
const lockAcquired = SyncStateManager.setPhase2Starting(true);
if (!lockAcquired) {
  // Lock ya activo, ignorar
  return;
}
```

### 2. Verificación de Estado Inicializado

```javascript
if (SyncStateManager.getPhase2Initialized()) {
  // Ya inicializado, ignorar
  return;
}
```

### 3. Verificación en handlePhase1Completed()

```javascript
// Verificar antes de iniciar desde evento
if (SyncStateManager.getPhase2Starting()) {
  return; // Ya iniciando
}

if (SyncStateManager.getPhase2Initialized()) {
  return; // Ya inicializado
}
```

## Compatibilidad

### Código Legacy

El código existente que llama directamente a `startPhase2()` sigue funcionando:

```javascript
// Código legacy (sigue funcionando)
if (typeof startPhase2 === 'function') {
  startPhase2();
}
```

### Doble Iniciación

Si tanto el evento como la llamada directa intentan iniciar Fase 2:
- El lock atómico previene ejecuciones simultáneas
- Solo la primera llamada ejecutará
- La segunda será ignorada con un warning throttled

## Eventos del Sistema

### phase1Completed

**Emitido por**: Phase1Manager  
**Suscrito por**: Phase2Manager, SyncDashboard  
**Cuándo**: Cuando Fase 1 se completa exitosamente  
**Datos**: Estado de Fase 1, timestamp, datos completos de sincronización

### syncProgress

**Emitido por**: Phase1Manager, SyncProgress  
**Suscrito por**: SyncDashboard, ConsoleManager  
**Cuándo**: Durante el progreso de sincronización  
**Datos**: Datos de progreso, estado de ambas fases

## Ventajas del Sistema de Eventos

1. **Desacoplamiento**: Phase1Manager no necesita conocer Phase2Manager directamente
2. **Extensibilidad**: Fácil agregar nuevos suscriptores al evento
3. **Robustez**: Protecciones múltiples previenen ejecuciones duplicadas
4. **Compatibilidad**: Código legacy sigue funcionando
5. **Observabilidad**: Logs claros de transición entre fases

## Flujo Completo de Ejemplo

1. Usuario inicia Fase 1 → `Phase1Manager.start()`
2. Phase1Manager inicia polling → `checkPhase1Complete()` cada 2 segundos
3. Backend completa Fase 1 → `checkPhase1Complete()` detecta completitud
4. Phase1Manager emite evento → `pollingManager.emit('phase1Completed')`
5. Phase2Manager recibe evento → `handlePhase1Completed()` verifica protecciones
6. Phase2Manager inicia Fase 2 → `Phase2Manager.start()` con lock atómico
7. SyncDashboard actualiza UI → Marca Fase 1 como completada, habilita botón Fase 2

## Troubleshooting

### Fase 2 no inicia automáticamente

**Posibles causas**:
1. PollingManager no está disponible → Verificar que PollingManager se cargó antes que Phase2Manager
2. Evento no se emitió → Verificar logs de Phase1Manager
3. Protecciones bloqueando → Verificar estado de `phase2Starting` y `phase2Initialized`

**Solución**:
- Verificar en consola: `pollingManager.on` debe estar disponible
- Verificar logs: Debe aparecer "Evento phase1Completed emitido"
- Verificar estado: `SyncStateManager.getPhase2Starting()` y `getPhase2Initialized()`

### Múltiples iniciaciones

**Causa**: Tanto el evento como la llamada directa intentan iniciar

**Solución**: El lock atómico previene esto automáticamente. Solo la primera ejecutará.

### Evento recibido pero Fase 2 no inicia

**Causa**: Protecciones bloqueando (ya iniciando o inicializado)

**Solución**: Verificar estado y resetear si es necesario:
```javascript
SyncStateManager.resetPhase2State();
```

