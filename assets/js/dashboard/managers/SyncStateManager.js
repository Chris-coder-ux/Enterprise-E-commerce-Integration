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
 * Detener el polling de progreso
 * 
 * Detiene todos los polling activos relacionados con el progreso de sincronización.
 * El estado es manejado por PHP, por lo que no es necesario marcarlo en JavaScript.
 * 
 * @param {string} [reason] - Razón para detener el polling (solo para logging)
 * @returns {void}
 * 
 * @example
 * SyncStateManager.stopProgressPolling('Usuario canceló');
 */
function stopProgressPolling(reason) {
  // El parámetro 'reason' solo sirve para logging y diagnóstico.
  // No afecta la lógica de la función ni el estado del sistema.
  // Se puede eliminar porque no se utiliza para modificar el comportamiento,
  // y el motivo puede registrarse directamente en el log si es necesario.
  
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

  // Resetear contadores
  inactiveProgressCounter = 0;
  lastProgressValue = 0;

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
 * Objeto SyncStateManager con métodos públicos
 */
const SyncStateManager = {
  stopProgressPolling,
  isPollingActive,
  cleanupOnPageLoad,
  getInactiveProgressCounter,
  setInactiveProgressCounter,
  incrementInactiveProgressCounter,
  getLastProgressValue,
  setLastProgressValue,
  resetCounters
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
 * con el código existente que usa inactiveProgressCounter y lastProgressValue directamente
 * 
 * NOTA: Estas variables se usan directamente en otras partes del código
 * (líneas 1269, 1289, 1292, 1347 de dashboard.js), por lo que deben estar
 * disponibles globalmente.
 */
if (typeof window !== 'undefined') {
  try {
    // Usar Object.defineProperty para crear getters/setters que accedan a las variables internas
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
