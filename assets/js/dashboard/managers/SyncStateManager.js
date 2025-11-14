/**
 * Gestor de Estado de Sincronización
 * 
 * Gestiona el estado de sincronización, limpieza y contadores relacionados
 * con el proceso de sincronización del dashboard.
 * 
 * @module managers/SyncStateManager
 * @namespace SyncStateManager
 * @since 1.0.0
 * @author Christian
 */

/* global pollingManager */

/**
 * Variables de estado de sincronización
 * 
 * NOTA: Estas variables se usan directamente en otras partes del código
 * (líneas 1269, 1289, 1292, 1347 de dashboard.js), por lo que deben estar
 * disponibles globalmente para mantener compatibilidad.
 * 
 * @type {Object}
 * @property {number} inactiveProgressCounter - Contador de progreso inactivo
 * @property {number} lastProgressValue - Último valor de progreso registrado
 */
let inactiveProgressCounter = 0;
let lastProgressValue = 0;

/**
 * Variables de estado de Fase 1
 * 
 * Centraliza el estado de la Fase 1 de sincronización para evitar
 * el uso disperso de flags globales en window y prevenir ejecuciones simultáneas.
 * 
 * @type {Object}
 * @property {boolean} phase1Starting - Indica si Fase 1 está iniciando (lock para prevenir ejecuciones simultáneas)
 * @property {boolean} phase1Initialized - Indica si Fase 1 está inicializada y en progreso
 */
let phase1Starting = false;
let phase1Initialized = false;

/**
 * Variables de estado de Fase 2
 * 
 * Centraliza el estado de la Fase 2 de sincronización para evitar
 * el uso disperso de flags globales en window.
 * 
 * @type {Object}
 * @property {boolean} phase2Starting - Indica si Fase 2 está iniciando (lock para prevenir ejecuciones simultáneas)
 * @property {boolean} phase2Initialized - Indica si Fase 2 está inicializada
 * @property {boolean} phase2ProcessingBatch - Indica si se está procesando un batch
 * @property {number|null} syncInterval - ID del intervalo de sincronización
 * @property {number|null} phase2PollingInterval - ID del intervalo de polling de Fase 2
 */
let phase2Starting = false;
let phase2Initialized = false;
let phase2ProcessingBatch = false;
let syncInterval = null;
let phase2PollingInterval = null;

/**
 * Detener el polling de progreso
 * 
 * Detiene todos los polling activos relacionados con el progreso de sincronización.
 * El estado es manejado por PHP, por lo que no es necesario marcarlo en JavaScript.
 * 
 * @param {string} [_reason] - Razón para detener el polling (solo para logging, no usado actualmente)
 * @returns {void}
 * 
 * @example
 * SyncStateManager.stopProgressPolling('Usuario canceló');
 */
function stopProgressPolling(_reason) {
  // El parámetro '_reason' está disponible para logging futuro pero no se usa actualmente.
  // No afecta la lógica de la función ni el estado del sistema.
  
  // eslint-disable-next-line no-undef
  if (typeof pollingManager !== 'undefined' && pollingManager) {
    pollingManager.stopAllPolling();
  }
  // Estado manejado por PHP - no necesario marcar en JavaScript
}

/**
 * Verificar si hay polling activo
 * 
 * @returns {boolean} true si hay polling activo, false en caso contrario
 * 
 * @example
 * if (SyncStateManager.isPollingActive()) {
 *   // Hay polling activo
 * }
 */
function isPollingActive() {
  // eslint-disable-next-line no-undef
  if (typeof pollingManager !== 'undefined' && pollingManager) {
    return pollingManager.isPollingActive();
  }
  return false;
}

/**
 * Limpiar estado al cargar la página
 * 
 * Detiene cualquier polling activo y resetea contadores y configuración
 * de polling al estado inicial.
 * 
 * @returns {void}
 * 
 * @example
 * SyncStateManager.cleanupOnPageLoad();
 */
function cleanupOnPageLoad() {
  // Detener cualquier polling activo al cargar la página
  if (isPollingActive()) {
    stopProgressPolling('Limpieza al cargar página');
  }

  // Resetear todo el estado (contadores, flags de Fase 2, intervalos)
  resetAllState();

  // Resetear configuración de polling
  // eslint-disable-next-line no-undef
  if (typeof pollingManager !== 'undefined' && pollingManager && pollingManager.config) {
    // ✅ CORRECCIÓN: Usar intervalo activo como fallback si 'normal' no existe
    const intervals = pollingManager.config.intervals || {};
    // Acceder a propiedades que pueden no estar en el tipo usando notación de corchetes
    const normalInterval = intervals['normal'];
    const activeInterval = intervals.active;
    const defaultInterval = normalInterval || activeInterval || 30000;
    pollingManager.config.currentInterval = defaultInterval;
    pollingManager.config.currentMode = 'normal';
    pollingManager.config.errorCount = 0;
  }
}

/**
 * Obtener el contador de progreso inactivo
 * 
 * @returns {number} Valor del contador de progreso inactivo
 */
function getInactiveProgressCounter() {
  return inactiveProgressCounter;
}

/**
 * Establecer el contador de progreso inactivo
 * 
 * @param {number} value - Nuevo valor del contador
 * @returns {void}
 */
function setInactiveProgressCounter(value) {
  inactiveProgressCounter = value;
}

/**
 * Incrementar el contador de progreso inactivo
 * 
 * @returns {number} Nuevo valor del contador
 */
function incrementInactiveProgressCounter() {
  inactiveProgressCounter++;
  return inactiveProgressCounter;
}

/**
 * Obtener el último valor de progreso
 * 
 * @returns {number} Último valor de progreso registrado
 */
function getLastProgressValue() {
  return lastProgressValue;
}

/**
 * Establecer el último valor de progreso
 * 
 * @param {number} value - Nuevo valor de progreso
 * @returns {void}
 */
function setLastProgressValue(value) {
  lastProgressValue = value;
}

/**
 * Resetear todos los contadores y estado
 * 
 * @returns {void}
 */
function resetCounters() {
  inactiveProgressCounter = 0;
  lastProgressValue = 0;
}

/**
 * Obtener el estado de inicio de Fase 1
 * 
 * ✅ NUEVO: Lock para prevenir ejecuciones simultáneas de Phase1Manager.start()
 * 
 * @returns {boolean} true si Fase 1 está iniciando, false en caso contrario
 */
function getPhase1Starting() {
  return phase1Starting;
}

/**
 * Establecer el estado de inicio de Fase 1
 * 
 * ✅ NUEVO: Lock atómico para prevenir ejecuciones simultáneas
 * 
 * @param {boolean} value - Nuevo valor del estado
 * @returns {boolean} true si se estableció el lock, false si ya estaba activo
 */
function setPhase1Starting(value) {
  if (value === true && phase1Starting === true) {
    // Ya está iniciando, no permitir otra ejecución simultánea
    return false;
  }
  phase1Starting = value === true;
  return true;
}

/**
 * Obtener el estado de inicialización de Fase 1
 * 
 * @returns {boolean} true si Fase 1 está inicializada, false en caso contrario
 */
function getPhase1Initialized() {
  return phase1Initialized;
}

/**
 * Establecer el estado de inicialización de Fase 1
 * 
 * @param {boolean} value - Nuevo valor del estado
 * @returns {void}
 */
function setPhase1Initialized(value) {
  phase1Initialized = value === true;
}

/**
 * Obtener el estado de inicio de Fase 2
 * 
 * @returns {boolean} true si Fase 2 está iniciando, false en caso contrario
 */
function getPhase2Starting() {
  return phase2Starting;
}

/**
 * Establecer el estado de inicio de Fase 2
 * 
 * ✅ MEJORADO: Lock atómico para prevenir ejecuciones simultáneas
 * 
 * @param {boolean} value - Nuevo valor del estado
 * @returns {boolean} true si se estableció el lock, false si ya estaba activo
 */
function setPhase2Starting(value) {
  if (value === true && phase2Starting === true) {
    // Ya está iniciando, no permitir otra ejecución simultánea
    return false;
  }
  phase2Starting = value === true;
  return true;
}

/**
 * Obtener el estado de inicialización de Fase 2
 * 
 * @returns {boolean} true si Fase 2 está inicializada, false en caso contrario
 */
function getPhase2Initialized() {
  return phase2Initialized;
}

/**
 * Establecer el estado de inicialización de Fase 2
 * 
 * @param {boolean} value - Nuevo valor del estado
 * @returns {void}
 */
function setPhase2Initialized(value) {
  phase2Initialized = value === true;
}

/**
 * Obtener el estado de procesamiento de batch de Fase 2
 * 
 * @returns {boolean} true si se está procesando un batch, false en caso contrario
 */
function getPhase2ProcessingBatch() {
  return phase2ProcessingBatch;
}

/**
 * Establecer el estado de procesamiento de batch de Fase 2
 * 
 * @param {boolean} value - Nuevo valor del estado
 * @returns {void}
 */
function setPhase2ProcessingBatch(value) {
  phase2ProcessingBatch = value === true;
}

/**
 * Obtener el ID del intervalo de sincronización
 * 
 * @returns {number|null} ID del intervalo o null si no hay intervalo activo
 */
function getSyncInterval() {
  return syncInterval;
}

/**
 * Establecer el ID del intervalo de sincronización
 * 
 * @param {number|null} value - ID del intervalo o null para limpiar
 * @returns {void}
 */
function setSyncInterval(value) {
  syncInterval = value;
}

/**
 * Limpiar el intervalo de sincronización
 * 
 * Si hay un intervalo activo, lo detiene y limpia el ID.
 * 
 * @returns {void}
 */
function clearSyncInterval() {
  if (syncInterval !== null) {
    try {
      clearInterval(syncInterval);
    } catch (error) {
      // Ignorar errores al limpiar intervalo
    }
    syncInterval = null;
  }
}

/**
 * Obtener el ID del intervalo de polling de Fase 2
 * 
 * @returns {number|null} ID del intervalo o null si no hay intervalo activo
 */
function getPhase2PollingInterval() {
  return phase2PollingInterval;
}

/**
 * Establecer el ID del intervalo de polling de Fase 2
 * 
 * @param {number|null} value - ID del intervalo o null para limpiar
 * @returns {void}
 */
function setPhase2PollingInterval(value) {
  phase2PollingInterval = value;
}

/**
 * Limpiar el intervalo de polling de Fase 2
 * 
 * Si hay un intervalo activo, lo detiene y limpia el ID.
 * 
 * @returns {void}
 */
function clearPhase2PollingInterval() {
  if (phase2PollingInterval !== null) {
    try {
      clearInterval(phase2PollingInterval);
    } catch (error) {
      // Ignorar errores al limpiar intervalo
    }
    phase2PollingInterval = null;
  }
}

/**
 * Resetear el estado de Fase 1
 * 
 * ✅ NUEVO: Limpia todos los flags relacionados con Fase 1.
 * 
 * @returns {void}
 */
function resetPhase1State() {
  phase1Starting = false;
  phase1Initialized = false;
}

/**
 * Resetear todo el estado de Fase 2
 * 
 * Resetea todos los flags y limpia los intervalos de Fase 2.
 * 
 * @returns {void}
 */
function resetPhase2State() {
  phase2Starting = false;
  phase2Initialized = false;
  phase2ProcessingBatch = false;
  clearSyncInterval();
  clearPhase2PollingInterval();
}

/**
 * Resetear todo el estado de sincronización
 * 
 * Resetea todos los contadores, flags y limpia los intervalos de ambas fases.
 * 
 * @returns {void}
 */
function resetAllState() {
  resetCounters();
  resetPhase1State();
  resetPhase2State();
}

/**
 * Objeto SyncStateManager con métodos públicos
 */
const SyncStateManager = {
  // Métodos de polling
  stopProgressPolling,
  isPollingActive,
  cleanupOnPageLoad,
  
  // Métodos de contadores de progreso
  getInactiveProgressCounter,
  setInactiveProgressCounter,
  incrementInactiveProgressCounter,
  getLastProgressValue,
  setLastProgressValue,
  resetCounters,
  
  // ✅ NUEVO: Métodos de estado de Fase 1
  getPhase1Starting,
  setPhase1Starting,
  getPhase1Initialized,
  setPhase1Initialized,
  resetPhase1State,
  
  // Métodos de estado de Fase 2
  getPhase2Starting,
  setPhase2Starting,
  getPhase2Initialized,
  setPhase2Initialized,
  getPhase2ProcessingBatch,
  setPhase2ProcessingBatch,
  getSyncInterval,
  setSyncInterval,
  clearSyncInterval,
  getPhase2PollingInterval,
  setPhase2PollingInterval,
  clearPhase2PollingInterval,
  resetPhase2State,
  resetAllState
};

/**
 * Exponer SyncStateManager globalmente para mantener compatibilidad
 * con el código existente que usa window.SyncStateManager
 */
if (typeof window !== 'undefined') {
  try {
    window.SyncStateManager = SyncStateManager;
  } catch (error) {
    try {
      Object.defineProperty(window, 'SyncStateManager', {
        value: SyncStateManager,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar SyncStateManager a window:', defineError, error);
      }
    }
  }
}

/**
 * Exponer variables de estado globalmente para mantener compatibilidad
 * con el código existente que usa inactiveProgressCounter, lastProgressValue
 * y flags de Fase 2 directamente en window.
 * 
 * ⚠️ DEPRECADO: El acceso directo a estas variables en window está deprecado.
 * Usa SyncStateManager.getPhase2Initialized() en lugar de window.phase2Initialized.
 * 
 * Los getters/setters aseguran que los cambios se reflejen en el estado centralizado
 * de SyncStateManager, pero se recomienda migrar al uso de la API de SyncStateManager.
 * 
 * @deprecated Usa SyncStateManager API en su lugar
 */
if (typeof window !== 'undefined') {
  try {
    // Contadores de progreso (compatibilidad hacia atrás con advertencias)
    Object.defineProperty(window, 'inactiveProgressCounter', {
      get() {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.inactiveProgressCounter está deprecado. Usa SyncStateManager.getInactiveProgressCounter() en su lugar.');
        }
        return inactiveProgressCounter;
      },
      set(value) {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.inactiveProgressCounter está deprecado. Usa SyncStateManager.setInactiveProgressCounter() en su lugar.');
        }
        inactiveProgressCounter = value;
      },
      enumerable: true,
      configurable: true
    });

    Object.defineProperty(window, 'lastProgressValue', {
      get() {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.lastProgressValue está deprecado. Usa SyncStateManager.getLastProgressValue() en su lugar.');
        }
        return lastProgressValue;
      },
      set(value) {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.lastProgressValue está deprecado. Usa SyncStateManager.setLastProgressValue() en su lugar.');
        }
        lastProgressValue = value;
      },
      enumerable: true,
      configurable: true
    });

    // ⚠️ DEPRECADO: Flags de Fase 1 (compatibilidad hacia atrás con advertencias)
    Object.defineProperty(window, 'phase1Starting', {
      get() {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.phase1Starting está deprecado. Usa SyncStateManager.getPhase1Starting() en su lugar.');
        }
        return phase1Starting;
      },
      set(value) {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.phase1Starting está deprecado. Usa SyncStateManager.setPhase1Starting() en su lugar.');
        }
        phase1Starting = value === true;
      },
      enumerable: true,
      configurable: true
    });

    Object.defineProperty(window, 'phase1Initialized', {
      get() {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.phase1Initialized está deprecado. Usa SyncStateManager.getPhase1Initialized() en su lugar.');
        }
        return phase1Initialized;
      },
      set(value) {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.phase1Initialized está deprecado. Usa SyncStateManager.setPhase1Initialized() en su lugar.');
        }
        phase1Initialized = value === true;
      },
      enumerable: true,
      configurable: true
    });

    // ⚠️ DEPRECADO: Flags de Fase 2 (compatibilidad hacia atrás con advertencias)
    Object.defineProperty(window, 'phase2Starting', {
      get() {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.phase2Starting está deprecado. Usa SyncStateManager.getPhase2Starting() en su lugar.');
        }
        return phase2Starting;
      },
      set(value) {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.phase2Starting está deprecado. Usa SyncStateManager.setPhase2Starting() en su lugar.');
        }
        phase2Starting = value === true;
      },
      enumerable: true,
      configurable: true
    });

    Object.defineProperty(window, 'phase2Initialized', {
      get() {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.phase2Initialized está deprecado. Usa SyncStateManager.getPhase2Initialized() en su lugar.');
        }
        return phase2Initialized;
      },
      set(value) {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.phase2Initialized está deprecado. Usa SyncStateManager.setPhase2Initialized() en su lugar.');
        }
        phase2Initialized = value === true;
      },
      enumerable: true,
      configurable: true
    });

    Object.defineProperty(window, 'phase2ProcessingBatch', {
      get() {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.phase2ProcessingBatch está deprecado. Usa SyncStateManager.getPhase2ProcessingBatch() en su lugar.');
        }
        return phase2ProcessingBatch;
      },
      set(value) {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.phase2ProcessingBatch está deprecado. Usa SyncStateManager.setPhase2ProcessingBatch() en su lugar.');
        }
        phase2ProcessingBatch = value === true;
      },
      enumerable: true,
      configurable: true
    });

    Object.defineProperty(window, 'syncInterval', {
      get() {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.syncInterval está deprecado. Usa SyncStateManager.getSyncInterval() en su lugar.');
        }
        return syncInterval;
      },
      set(value) {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.syncInterval está deprecado. Usa SyncStateManager.setSyncInterval() en su lugar.');
        }
        syncInterval = value;
      },
      enumerable: true,
      configurable: true
    });

    Object.defineProperty(window, 'phase2PollingInterval', {
      get() {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.phase2PollingInterval está deprecado. Usa SyncStateManager.getPhase2PollingInterval() en su lugar.');
        }
        return phase2PollingInterval;
      },
      set(value) {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn && typeof window !== 'undefined' && !window.__SYNC_STATE_SUPPRESS_WARNINGS) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [DEPRECADO] window.phase2PollingInterval está deprecado. Usa SyncStateManager.setPhase2PollingInterval() en su lugar.');
        }
        phase2PollingInterval = value;
      },
      enumerable: true,
      configurable: true
    });

    // ✅ NUEVO: Exponer stopProgressPolling globalmente para compatibilidad con código existente
    // SyncProgress.js y otros archivos lo usan como función global
    try {
      window.stopProgressPolling = stopProgressPolling;
    } catch (stopPollingError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo exponer stopProgressPolling a window:', stopPollingError);
      }
    }
  } catch (error) {
    // eslint-disable-next-line no-console
    if (typeof console !== 'undefined' && console.warn) {
      // eslint-disable-next-line no-console
      console.warn('No se pudieron exponer variables de estado a window:', error);
    }
  }
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { SyncStateManager };
}
