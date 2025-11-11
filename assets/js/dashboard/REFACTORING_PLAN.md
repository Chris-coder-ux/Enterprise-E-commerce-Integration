# 📋 Plan de Refactorización - dashboard.js

## 🎯 Objetivo
Dividir el archivo monolítico de 5380 líneas en módulos organizados y mantenibles.

## 📁 Estructura Propuesta (Mejorada)

```
assets/js/dashboard/
├── dashboard.js                    # Punto de entrada principal (orquestador)
├── config/
│   ├── constants.js                # Constantes globales (SELECTORS, etc.)
│   ├── dashboard-config.js         # DASHBOARD_CONFIG
│   └── messages.js                 # Mensajes del sistema
├── core/
│   ├── ErrorHandler.js             # Manejo centralizado de errores
│   ├── AjaxManager.js              # Wrapper de peticiones AJAX
│   └── EventManager.js             # SystemEventManager
├── managers/
│   ├── PollingManager.js            # Gestión de polling adaptativo
│   ├── SyncStateManager.js          # Estado de sincronización
│   └── NonceManager.js             # Renovación de nonces
├── components/
│   ├── SyncDashboard.js             # Dashboard de dos fases
│   ├── UnifiedDashboard.js          # Dashboard unificado
│   ├── ProgressBar.js               # Barra de progreso
│   ├── ToastManager.js              # Notificaciones toast
│   └── ConsoleManager.js            # Terminal de consola
├── sync/
│   ├── Phase1Manager.js             # Fase 1: Sincronización de imágenes
│   ├── Phase2Manager.js             # Fase 2: Sincronización de productos
│   ├── SyncProgress.js              # Verificación de progreso
│   └── SyncController.js            # Controlador principal de sincronización
├── utils/
│   ├── DomUtils.js                  # Utilidades DOM (DOM_CACHE, etc.)
│   ├── ApiClient.js                 # Cliente API (ya existe)
│   └── FormatUtils.js               # Utilidades de formato
├── ui/
│   ├── ResponsiveLayout.js          # Layout responsive
│   ├── CardManager.js                # Gestión de tarjetas de estadísticas
│   └── SidebarController.js         # Control del sidebar
└── controllers/
    └── UnifiedDashboardController.js # Controlador principal

```

## 📦 Mapeo de Código Actual → Nuevos Módulos

### 1. **config/** (Líneas 95-329)
- `constants.js`: SELECTORS (líneas 341-375)
- `dashboard-config.js`: DASHBOARD_CONFIG (líneas 101-329)
- `messages.js`: Mensajes organizados por categorías

### 2. **core/** (Líneas 380-627, 4655-4816)
- `ErrorHandler.js`: Clase ErrorHandler (líneas 397-544)
- `AjaxManager.js`: Clase AjaxManager (líneas 580-627)
- `EventManager.js`: SystemEventManager (líneas 4655-4816)

### 3. **managers/** (Líneas 752-1392, 1395-1436)
- `PollingManager.js`: Clase PollingManager (líneas 752-933)
- `SyncStateManager.js`: Estado y limpieza (líneas 1027-1055)
- `NonceManager.js`: attemptNonceRenewal (líneas 1395-1436)

### 4. **components/** (Líneas 1438-1956, 3152-4597)
- `SyncDashboard.js`: Clase SyncDashboard (líneas 1561-1899)
- `UnifiedDashboard.js`: UnifiedDashboard (líneas 3176-4597)
- `ProgressBar.js`: Gestión de barras de progreso
- `ToastManager.js`: showToast (líneas 2957-3020)
- `ConsoleManager.js`: updateSyncConsole, addConsoleLine (líneas 1438-1558)

### 5. **sync/** (Líneas 1065-2427, 1958-1999)
- `Phase1Manager.js`: Lógica de Fase 1 (líneas 2085-2313)
- `Phase2Manager.js`: startPhase2 (líneas 1958-1999)
- `SyncProgress.js`: checkSyncProgress (líneas 1065-1392)
- `SyncController.js`: proceedWithSync (líneas 2002-2439)

### 6. **utils/** (Líneas 2750-3020, 3065-3136)
- `DomUtils.js`: DOM_CACHE, utilidades DOM (líneas 709-718)
- `FormatUtils.js`: formatBytes, formateo de datos
- `ApiClient.js`: Ya existe (mejorar para usar jQuery.ajax)

### 7. **ui/** (Líneas 2698-3136, 4880-4971)
- `ResponsiveLayout.js`: ResponsiveLayout (líneas 4880-4971)
- `CardManager.js`: updateCardData, updateSpecificCard (líneas 2750-2954)
- `SidebarController.js`: Lógica del sidebar (líneas 5179-5249)

### 8. **controllers/** (Líneas 5041-5368)
- `UnifiedDashboardController.js`: UnifiedDashboardController (líneas 5041-5368)

## 🔄 Flujo de Dependencias

```
dashboard.js (entry point)
  ├── core/ErrorHandler
  ├── core/AjaxManager
  ├── core/EventManager
  ├── managers/PollingManager
  ├── managers/SyncStateManager
  ├── managers/NonceManager
  ├── components/SyncDashboard
  ├── components/UnifiedDashboard
  ├── components/ToastManager
  ├── sync/SyncController
  ├── sync/Phase1Manager
  ├── sync/Phase2Manager
  ├── sync/SyncProgress
  ├── ui/ResponsiveLayout
  ├── ui/CardManager
  └── controllers/UnifiedDashboardController
```

## ✅ Ventajas de esta Estructura

1. **Separación de responsabilidades**: Cada módulo tiene una responsabilidad clara
2. **Reutilización**: Componentes y utilidades pueden reutilizarse fácilmente
3. **Mantenibilidad**: Código más fácil de encontrar y modificar
4. **Testabilidad**: Módulos pequeños son más fáciles de testear
5. **Escalabilidad**: Fácil agregar nuevas funcionalidades sin afectar otras

## 🚀 Orden de Implementación Recomendado

1. **Fase 1: Core** (Base sólida)
   - ErrorHandler
   - AjaxManager
   - EventManager

2. **Fase 2: Config y Utils** (Fundamentos)
   - constants.js
   - dashboard-config.js
   - messages.js
   - DomUtils.js

3. **Fase 3: Managers** (Lógica de negocio)
   - PollingManager
   - SyncStateManager
   - NonceManager

4. **Fase 4: Components** (UI)
   - ToastManager
   - ProgressBar
   - ConsoleManager

5. **Fase 5: Sync** (Funcionalidad principal)
   - SyncProgress
   - Phase1Manager
   - Phase2Manager
   - SyncController

6. **Fase 6: Dashboard** (Vistas)
   - SyncDashboard
   - UnifiedDashboard

7. **Fase 7: UI y Controllers** (Orquestación)
   - ResponsiveLayout
   - CardManager
   - UnifiedDashboardController

8. **Fase 8: Entry Point** (Integración)
   - dashboard.js (orquestador final)

## 📝 Notas Importantes

- Mantener compatibilidad con código existente durante la migración
- Usar exports/imports ES6 o CommonJS según el entorno
- Preservar todas las variables globales necesarias (window.*)
- Mantener la inicialización en jQuery(document).ready donde sea necesario
- Documentar cada módulo con JSDoc

