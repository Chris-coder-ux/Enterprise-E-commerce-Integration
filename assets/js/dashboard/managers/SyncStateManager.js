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
 * Variables de estado de Fase 2
 * 
 * Centraliza el estado de la Fase 2 de sincronización para evitar
 * el uso disperso de flags globales en window.
 * 
 * @type {Object}
 * @property {boolean} phase2Starting - Indica si Fase 2 está iniciando
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
    pollingManager.config.currentInterval = pollingManager.config.intervals.normal;
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
 * @param {boolean} value - Nuevo valor del estado
 * @returns {void}
 */
function setPhase2Starting(value) {
  phase2Starting = value === true;
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
 * Resetea todos los contadores, flags y limpia los intervalos.
 * 
 * @returns {void}
 */
function resetAllState() {
  resetCounters();
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
 * NOTA: Estas variables se usan directamente en otras partes del código
 * (líneas 1269, 1289, 1292, 1347 de dashboard.js), por lo que deben estar
 * disponibles globalmente. Los getters/setters aseguran que los cambios
 * se reflejen en el estado centralizado de SyncStateManager.
 */
if (typeof window !== 'undefined') {
  try {
    // Contadores de progreso (compatibilidad hacia atrás)
    Object.defineProperty(window, 'inactiveProgressCounter', {
      get() {
        return inactiveProgressCounter;
      },
      set(value) {
        inactiveProgressCounter = value;
      },
      enumerable: true,
      configurable: true
    });

    Object.defineProperty(window, 'lastProgressValue', {
      get() {
        return lastProgressValue;
      },
      set(value) {
        lastProgressValue = value;
      },
      enumerable: true,
      configurable: true
    });

    // Flags de Fase 2 (compatibilidad hacia atrás)
    Object.defineProperty(window, 'phase2Starting', {
      get() {
        return phase2Starting;
      },
      set(value) {
        phase2Starting = value === true;
      },
      enumerable: true,
      configurable: true
    });

    Object.defineProperty(window, 'phase2Initialized', {
      get() {
        return phase2Initialized;
      },
      set(value) {
        phase2Initialized = value === true;
      },
      enumerable: true,
      configurable: true
    });

    Object.defineProperty(window, 'phase2ProcessingBatch', {
      get() {
        return phase2ProcessingBatch;
      },
      set(value) {
        phase2ProcessingBatch = value === true;
      },
      enumerable: true,
      configurable: true
    });

    Object.defineProperty(window, 'syncInterval', {
      get() {
        return syncInterval;
      },
      set(value) {
        syncInterval = value;
      },
      enumerable: true,
      configurable: true
    });

    Object.defineProperty(window, 'phase2PollingInterval', {
      get() {
        return phase2PollingInterval;
      },
      set(value) {
        phase2PollingInterval = value;
      },
      enumerable: true,
      configurable: true
    });
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
