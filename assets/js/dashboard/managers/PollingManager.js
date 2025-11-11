/**
 * Gestor de Polling del Dashboard
 * 
 * Gestiona todos los sistemas de polling de forma unificada con ajuste adaptativo
 * basado en el progreso y la actividad del sistema.
 * 
 * @module managers/PollingManager
 * @class PollingManager
 * @since 1.0.0
 * @author Christian
 */

/* global miIntegracionApiDashboard */

/**
 * Clase para gestionar todos los sistemas de polling de forma unificada
 * 
 * @class PollingManager
 * @description Gestión unificada de sistemas de polling del dashboard
 * 
 * @example
 * // Crear instancia
 * const pollingManager = new PollingManager();
 * 
 * // Iniciar polling
 * pollingManager.startPolling('syncProgress', callback, 30000);
 * 
 * // Detener polling
 * pollingManager.stopPolling('syncProgress');
 */
class PollingManager {
  /**
   * Constructor de PollingManager
   * 
   * @constructor
   * @description Inicializa el gestor de polling con configuración desde PHP
   */
  constructor() {
    this.intervals = new Map();
    // ✅ NUEVO: Sistema de eventos para notificar a suscriptores
    this.eventListeners = new Map();

    // CENTRALIZADO: Solo usar configuración de PHP - sin fallbacks hardcodeados
    // Nota: Usamos verificaciones tradicionales en lugar de optional chaining
    // para compatibilidad con ESLint 3.0.1
    // eslint-disable-next-line prefer-optional-chain
    const phpConfig = (typeof miIntegracionApiDashboard !== 'undefined' &&
                       // eslint-disable-next-line prefer-optional-chain
                       miIntegracionApiDashboard &&
                       // eslint-disable-next-line prefer-optional-chain
                       miIntegracionApiDashboard.pollingConfig)
      ? miIntegracionApiDashboard.pollingConfig
      : {}; // Si no hay config PHP, será problema del servidor

    this.config = {
      intervals: phpConfig.intervals || {
        normal: 15000,    // 15 segundos - modo normal
        active: 5000,     // 5 segundos - modo activo (sincronización en progreso)
        fast: 2000,       // 2 segundos - modo rápido (progreso activo)
        slow: 45000,      // 45 segundos - modo lento (sin actividad)
        idle: 120000      // 2 minutos - modo inactivo
      },
      thresholds: phpConfig.thresholds || {
        to_slow: 3,
        to_idle: 8,
        max_errors: 5,
        progress_threshold: 0.1  // Cambio mínimo de progreso para considerar activo
      },
      // eslint-disable-next-line prefer-optional-chain
      currentInterval: (phpConfig.intervals && phpConfig.intervals.normal) || 10000, // Mantener && para compatibilidad
      currentMode: 'normal',
      errorCount: 0,
      lastProgress: 0,
      progressStagnantCount: 0
    };
    this.counters = {
      inactive: 0,
      lastProgress: 0
    };
  }

  /**
   * Suscribirse a un evento
   * 
   * @param {string} eventName - Nombre del evento ('syncProgress', 'syncError', etc.)
   * @param {Function} callback - Función a ejecutar cuando se emita el evento
   * @returns {Function} Función para desuscribirse
   * 
   * @example
   * const unsubscribe = pollingManager.on('syncProgress', (data) => {
   *   console.log('Progreso actualizado:', data);
   * });
   * 
   * // Para desuscribirse:
   * unsubscribe();
   */
  on(eventName, callback) {
    if (!this.eventListeners.has(eventName)) {
      this.eventListeners.set(eventName, []);
    }
    this.eventListeners.get(eventName).push(callback);
    
    // Retornar función para desuscribirse
    return () => {
      const listeners = this.eventListeners.get(eventName);
      if (listeners) {
        const index = listeners.indexOf(callback);
        if (index > -1) {
          listeners.splice(index, 1);
        }
      }
    };
  }

  /**
   * Emitir un evento
   * 
   * @param {string} eventName - Nombre del evento
   * @param {*} data - Datos a pasar a los listeners
   * @returns {void}
   * 
   * @example
   * pollingManager.emit('syncProgress', { progress: 50, status: 'active' });
   */
  emit(eventName, data) {
    const listeners = this.eventListeners.get(eventName);
    // ✅ DEBUG: Log para diagnosticar emisión de eventos
    // eslint-disable-next-line no-console
    console.log('[PollingManager] emit llamado', {
      eventName,
      listenersCount: listeners ? listeners.length : 0,
      hasData: !!data,
      dataKeys: data ? Object.keys(data).slice(0, 5) : []
    });
    
    if (listeners && listeners.length > 0) {
      listeners.forEach((callback, index) => {
        try {
          // eslint-disable-next-line no-console
          console.log(`[PollingManager] Ejecutando listener ${index + 1}/${listeners.length} para evento ${eventName}`);
          callback(data);
        } catch (error) {
          // eslint-disable-next-line no-console
          if (typeof console !== 'undefined' && console.error) {
            // eslint-disable-next-line no-console
            console.error('[PollingManager] Error en listener de evento', eventName, error);
          }
        }
      });
    } else {
      // eslint-disable-next-line no-console
      console.warn(`[PollingManager] ⚠️  No hay listeners registrados para el evento ${eventName}`);
    }
  }

  /**
   * Desuscribirse de un evento
   * 
   * @param {string} eventName - Nombre del evento
   * @param {Function} callback - Función a desuscribir (opcional, si no se proporciona se desuscriben todos)
   * @returns {void}
   * 
   * @example
   * pollingManager.off('syncProgress', myCallback);
   * pollingManager.off('syncProgress'); // Desuscribir todos los listeners
   */
  off(eventName, callback) {
    if (!callback) {
      // Desuscribir todos los listeners del evento
      this.eventListeners.delete(eventName);
      return;
    }
    
    const listeners = this.eventListeners.get(eventName);
    if (listeners) {
      const index = listeners.indexOf(callback);
      if (index > -1) {
        listeners.splice(index, 1);
      }
    }
  }

  /**
   * Iniciar polling con nombre específico
   * 
   * @param {string} name - Nombre único del polling
   * @param {Function} callback - Función a ejecutar en cada intervalo
   * @param {number|null} [interval=null] - Intervalo en milisegundos (usa configuración por defecto si es null)
   * @returns {number} ID del intervalo creado
   * 
   * @example
   * pollingManager.startPolling('syncProgress', checkSyncProgress, 30000);
   */
  startPolling(name, callback, interval = null) {
    this.stopPolling(name);

    const actualInterval = interval || this.config.currentInterval;
    const intervalId = setInterval(callback, actualInterval);

    this.intervals.set(name, {
      id: intervalId,
      callback,
      interval: actualInterval,
      startTime: Date.now()
    });

    return intervalId;
  }

  /**
   * Detener polling específico
   * 
   * @param {string} name - Nombre del polling a detener
   * @returns {boolean} true si se detuvo correctamente, false si no existía
   * 
   * @example
   * pollingManager.stopPolling('syncProgress');
   */
  stopPolling(name) {
    if (this.intervals.has(name)) {
      const polling = this.intervals.get(name);
      clearInterval(polling.id);
      this.intervals.delete(name);
      return true;
    }
    return false;
  }

  /**
   * Detener todos los polling
   * 
   * @returns {void}
   * 
   * @example
   * pollingManager.stopAllPolling();
   */
  stopAllPolling() {
    // eslint-disable-next-line prefer-for-of
    for (const polling of this.intervals.values()) {
      clearInterval(polling.id);
    }
    this.intervals.clear();
  }

  /**
   * Verificar si hay polling activo
   * 
   * @param {string|null} [name=null] - Nombre específico del polling a verificar (opcional)
   * @returns {boolean} true si hay polling activo, false en caso contrario
   * 
   * @example
   * // Verificar si hay algún polling activo
   * pollingManager.isPollingActive();
   * 
   * // Verificar polling específico
   * pollingManager.isPollingActive('syncProgress');
   */
  isPollingActive(name = null) {
    if (name) {
      return this.intervals.has(name);
    }
    return this.intervals.size > 0;
  }

  /**
   * Ajusta el polling adaptativamente basado en el progreso
   * 
   * @param {number} currentProgress - Progreso actual (0-100)
   * @param {boolean} isActive - Si la sincronización está activa
   * @returns {void}
   * 
   * @example
   * pollingManager.adjustPolling(75, true);
   */
  adjustPolling(currentProgress, isActive) {
    if (!isActive) {
      this.config.currentMode = 'idle';
      this.config.currentInterval = this.config.intervals.idle;
      return;
    }

    const progressChange = Math.abs(currentProgress - this.config.lastProgress);
    const threshold = this.config.thresholds.progress_threshold;

    // OPTIMIZACIÓN: Lógica más conservadora para reducir peticiones
    if (progressChange > threshold) {
      // Progreso activo - usar modo rápido solo si el cambio es significativo
      if (progressChange > 5) { // Cambio de más del 5%
        this.config.currentMode = 'fast';
        this.config.currentInterval = this.config.intervals.fast;
      } else {
        // Cambio pequeño - mantener modo activo
        this.config.currentMode = 'active';
        this.config.currentInterval = this.config.intervals.active;
      }
      this.config.progressStagnantCount = 0;
    } else {
      // Progreso estancado - incrementar contador
      this.config.progressStagnantCount++;

      if (this.config.progressStagnantCount >= 5) { // Aumentado de 3 a 5
        // Progreso estancado por 5 ciclos - usar modo lento
        this.config.currentMode = 'slow';
        this.config.currentInterval = this.config.intervals.slow;
      } else if (this.config.progressStagnantCount >= 2) {
        // Después de 2 ciclos sin progreso - usar modo normal
        this.config.currentMode = 'normal';
        this.config.currentInterval = this.config.intervals.normal;
      } else {
        // Mantener modo activo normal
        this.config.currentMode = 'active';
        this.config.currentInterval = this.config.intervals.active;
      }
    }

    this.config.lastProgress = currentProgress;
  }

  /**
   * Resetear configuración
   * 
   * @returns {void}
   * 
   * @example
   * pollingManager.reset();
   */
  reset() {
    this.stopAllPolling();
    this.config.currentInterval = this.config.intervals.normal;
    this.config.currentMode = 'normal';
    this.config.errorCount = 0;
    this.config.lastProgress = 0;
    this.config.progressStagnantCount = 0;
    this.counters.inactive = 0;
    this.counters.lastProgress = 0;
  }
}

/**
 * Exponer PollingManager globalmente para mantener compatibilidad
 * con el código existente que usa window.PollingManager
 */
if (typeof window !== 'undefined') {
  try {
    window.PollingManager = PollingManager;
  } catch (error) {
    try {
      Object.defineProperty(window, 'PollingManager', {
        value: PollingManager,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar PollingManager a window:', defineError, error);
      }
    }
  }
}

/**
 * Instancia global del PollingManager
 * 
 * NOTA: En el archivo original (dashboard.js línea 936) se crea:
 * const pollingManager = new PollingManager();
 * 
 * El código existente usa directamente esta instancia global, no crea nuevas instancias.
 * Por lo tanto, creamos y exponemos solo esta instancia global.
 */
const pollingManager = new PollingManager();

/**
 * Exponer pollingManager (instancia) globalmente para mantener compatibilidad
 * con el código existente que usa pollingManager directamente
 */
if (typeof window !== 'undefined') {
  try {
    window.pollingManager = pollingManager;
  } catch (error) {
    try {
      Object.defineProperty(window, 'pollingManager', {
        value: pollingManager,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar pollingManager a window:', defineError, error);
      }
    }
  }
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { PollingManager, pollingManager };
}
