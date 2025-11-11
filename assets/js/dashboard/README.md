# 📁 Estructura del Dashboard Refactorizado

Este directorio contiene la estructura modular del dashboard, dividida en módulos organizados y mantenibles.

## 📂 Estructura de Directorios

```
assets/js/dashboard/
├── dashboard.js                    # Punto de entrada principal (orquestador)
├── README.md                       # Este archivo
├── REFACTORING_PLAN.md            # Plan de refactorización detallado
├── config/                         # Configuración
│   ├── constants.js               # Constantes globales
│   ├── dashboard-config.js        # Configuración del dashboard
│   └── messages.js                # Mensajes del sistema
├── core/                          # Sistemas fundamentales
│   ├── ErrorHandler.js            # Manejo de errores
│   ├── AjaxManager.js             # Peticiones AJAX
│   └── EventManager.js            # Gestión de eventos
├── managers/                      # Gestores de lógica de negocio
│   ├── PollingManager.js         # Polling adaptativo
│   ├── SyncStateManager.js       # Estado de sincronización
│   └── NonceManager.js           # Renovación de nonces
├── components/                    # Componentes UI
│   ├── SyncDashboard.js          # Dashboard de sincronización
│   ├── UnifiedDashboard.js        # Dashboard unificado
│   ├── ProgressBar.js            # Barras de progreso
│   ├── ToastManager.js           # Notificaciones toast
│   └── ConsoleManager.js         # Terminal de consola
├── sync/                          # Sistema de sincronización
│   ├── Phase1Manager.js          # Fase 1: Imágenes
│   ├── Phase2Manager.js          # Fase 2: Productos
│   ├── SyncProgress.js           # Verificación de progreso
│   └── SyncController.js         # Controlador de sincronización
├── utils/                         # Utilidades
│   ├── DomUtils.js               # Utilidades DOM
│   ├── ApiClient.js              # Cliente API (ya existe)
│   └── FormatUtils.js            # Utilidades de formato
├── ui/                            # Componentes de interfaz
│   ├── ResponsiveLayout.js       # Layout responsive
│   ├── CardManager.js            # Gestión de tarjetas
│   └── SidebarController.js      # Control del sidebar
└── controllers/                   # Controladores
    └── UnifiedDashboardController.js  # Controlador principal
```

## 🎯 Estado Actual

Todos los archivos han sido creados vacíos con comentarios TODO indicando qué código debe moverse desde `dashboard.js` original.

## 📋 Próximos Pasos

1. **Revisar el plan**: Consultar `REFACTORING_PLAN.md` para ver el mapeo detallado
2. **Seguir el orden**: Implementar según las fases recomendadas
3. **Mantener compatibilidad**: Preservar variables globales necesarias
4. **Documentar**: Añadir JSDoc a cada módulo

## 🔄 Flujo de Dependencias

```
dashboard.js
  ├── config/ (constantes, configuración, mensajes)
  ├── core/ (ErrorHandler, AjaxManager, EventManager)
  ├── managers/ (PollingManager, SyncStateManager, NonceManager)
  ├── components/ (SyncDashboard, UnifiedDashboard, ToastManager, etc.)
  ├── sync/ (Phase1Manager, Phase2Manager, SyncProgress, SyncController)
  ├── utils/ (DomUtils, FormatUtils)
  ├── ui/ (ResponsiveLayout, CardManager, SidebarController)
  └── controllers/ (UnifiedDashboardController)
```

## 📝 Notas

- Todos los archivos están listos para recibir el código refactorizado
- Cada archivo tiene un comentario TODO indicando su propósito
- La estructura sigue principios SOLID y separación de responsabilidades
- Compatible con el sistema actual (jQuery, WordPress, etc.)

