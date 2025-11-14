/**
 * EventManager - Gestión de Eventos del Sistema
 * 
 * Coordinación de inicialización de sistemas externos mediante eventos personalizados.
 * Gestiona el estado de inicialización y emite eventos cuando los sistemas están listos.
 * 
 * @module core/EventManager
 * @class EventManager
 * @namespace SystemEventManager
 * @description Coordinación de inicialización de sistemas externos
 * @since 1.0.0
 * @author Christian
 * 
 * @example
 * // Inicializar sistema de eventos
 * SystemEventManager.init();
 * 
 * // Registrar un sistema
 * SystemEventManager.registerSystem('miSistema', ['jQuery'], function() {
 *   console.log('Sistema inicializado');
 * });
 * 
 * // Escuchar eventos
 * window.addEventListener('mi-system-base-ready', function(event) {
 *   console.log('Sistema base listo', event.detail);
 * });
 */

/* global window, ErrorHandler, AjaxManager */

/**
 * Polyfill para Object.assign (compatibilidad con IE11 y navegadores antiguos)
 * 
 * WordPress puede ejecutarse en navegadores antiguos que no soportan Object.assign nativamente.
 * Este polyfill garantiza compatibilidad total.
 * 
 * Nota: Usamos 'var' intencionalmente para compatibilidad con IE11 que no soporta let/const.
 * 
 * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Object/assign
 */
if (typeof Object.assign !== 'function') {
  // eslint-disable-next-line no-var
  Object.assign = function(target) {
    'use strict';
    if (target == null) {
      throw new TypeError('Cannot convert undefined or null to object');
    }
    
    // eslint-disable-next-line no-var
    var to = Object(target);
    // eslint-disable-next-line no-var
    for (var index = 1; index < arguments.length; index++) {
      // eslint-disable-next-line no-var
      var nextSource = arguments[index];
      if (nextSource != null) {
        // eslint-disable-next-line no-var
        for (var nextKey in nextSource) {
          if (Object.prototype.hasOwnProperty.call(nextSource, nextKey)) {
            to[nextKey] = nextSource[nextKey];
          }
        }
      }
    }
    return to;
  };
}

/**
 * Clase para gestión de eventos del sistema
 * 
 * Esta clase proporciona métodos para:
 * - Gestionar el estado de inicialización de sistemas
 * - Emitir eventos personalizados cuando los sistemas están listos
 * - Registrar y verificar dependencias de sistemas externos
 * - Coordinar la inicialización ordenada de componentes
 */
const SystemEventManager = {
  /**
   * Estado de inicialización de los sistemas
   * 
   * @type {Object}
   * @property {boolean} systemBase - Sistema base listo
   * @property {boolean} errorHandler - ErrorHandler listo
   * @property {boolean} unifiedDashboard - UnifiedDashboard listo
   * @property {boolean} allSystems - Todos los sistemas listos
   */
  initializationState: {
    systemBase: false,
    errorHandler: false,
    unifiedDashboard: false,
    allSystems: false
  },
  
  /**
   * Lista de sistemas registrados
   * 
   * @type {Map<string, Object>}
   * @property {Array} dependencies - Dependencias del sistema
   * @property {Function} callback - Callback de inicialización
   * @property {boolean} initialized - Estado de inicialización
   */
  registeredSystems: new Map(),
  
  /**
   * Inicializar el sistema de eventos
   * 
   * Emite el evento inicial de sistema base listo.
   * 
   * @returns {void}
   * @example
   * SystemEventManager.init();
   */
  init() {
    this.log('Sistema de eventos inicializado');
    this.emitSystemBaseReady();
  },
  
  /**
   * Emitir evento de sistema base listo
   * 
   * Marca el sistema base como listo y emite el evento 'mi-system-base-ready'
   * con información sobre los sistemas disponibles.
   * 
   * @returns {void}
   * @example
   * SystemEventManager.emitSystemBaseReady();
   */
  emitSystemBaseReady() {
    this.initializationState.systemBase = true;
    this.log('Sistema base listo - emitiendo evento');
    window.dispatchEvent(new CustomEvent('mi-system-base-ready', {
      detail: {
        timestamp: Date.now(),
        systems: {
          // eslint-disable-next-line no-undef
          errorHandler: typeof ErrorHandler !== 'undefined',
          // eslint-disable-next-line no-undef
          ajaxManager: typeof AjaxManager !== 'undefined',
          pollingManager: window.PollingManager !== undefined
        }
      }
    }));
  },
  
  /**
   * Emitir evento de ErrorHandler listo
   * 
   * Marca ErrorHandler como listo y emite el evento 'mi-error-handler-ready'.
   * 
   * @returns {void}
   * @example
   * SystemEventManager.emitErrorHandlerReady();
   */
  emitErrorHandlerReady() {
    this.initializationState.errorHandler = true;
    this.log('ErrorHandler listo - emitiendo evento');
    window.dispatchEvent(new CustomEvent('mi-error-handler-ready', {
      detail: {
        timestamp: Date.now(),
        // eslint-disable-next-line no-undef
        errorHandler: typeof ErrorHandler !== 'undefined'
      }
    }));
  },
  
  /**
   * Emitir evento de UnifiedDashboard listo
   * 
   * Marca UnifiedDashboard como listo y emite el evento 'mi-unified-dashboard-ready'.
   * Verifica si todos los sistemas están listos después de emitir el evento.
   * 
   * @returns {void}
   * @example
   * SystemEventManager.emitUnifiedDashboardReady();
   */
  emitUnifiedDashboardReady() {
    this.initializationState.unifiedDashboard = true;
    this.log('UnifiedDashboard listo - emitiendo evento');
    window.dispatchEvent(new CustomEvent('mi-unified-dashboard-ready', {
      detail: {
        timestamp: Date.now(),
        unifiedDashboard: window.UnifiedDashboard !== undefined
      }
    }));
    
    // Verificar si todos los sistemas están listos
    this.checkAllSystemsReady();
  },
  
  /**
   * Verificar si todos los sistemas están listos
   * 
   * Comprueba si todos los sistemas han sido inicializados y emite
   * el evento 'mi-all-systems-ready' si es la primera vez que todos están listos.
   * 
   * @returns {void}
   * @example
   * SystemEventManager.checkAllSystemsReady();
   */
  checkAllSystemsReady() {
    // Verificar que todos los estados excepto allSystems sean true
    const states = this.initializationState;
    const allReady = states.systemBase === true &&
                     states.errorHandler === true &&
                     states.unifiedDashboard === true;
    
    if (allReady && !states.allSystems) {
      states.allSystems = true;
      this.log('Todos los sistemas listos - emitiendo evento final');
      window.dispatchEvent(new CustomEvent('mi-all-systems-ready', {
        detail: {
          timestamp: Date.now(),
          initializationState: Object.assign({}, this.initializationState)
        }
      }));
    }
  },
  
  /**
   * Registrar un sistema externo
   * 
   * Registra un sistema con sus dependencias y callback de inicialización.
   * 
   * @param {string} systemName - Nombre del sistema a registrar
   * @param {Array<string|Function>} dependencies - Array de dependencias (strings o funciones)
   * @param {Function} callback - Callback a ejecutar cuando el sistema se inicialice
   * @returns {void}
   * @example
   * SystemEventManager.registerSystem('miSistema', ['jQuery', 'ErrorHandler'], function() {
   *   console.log('Sistema inicializado');
   * });
   */
  registerSystem(systemName, dependencies, callback) {
    this.registeredSystems.set(systemName, {
      dependencies,
      callback,
      initialized: false
    });
    this.log('Sistema registrado: ' + systemName);
  },
  
  /**
   * Verificar dependencias de un sistema
   * 
   * Comprueba si todas las dependencias de un sistema están disponibles.
   * 
   * @param {string} systemName - Nombre del sistema a verificar
   * @returns {boolean} true si todas las dependencias están disponibles, false en caso contrario
   * @example
   * if (SystemEventManager.checkDependencies('miSistema')) {
   *   console.log('Todas las dependencias están disponibles');
   * }
   */
  checkDependencies(systemName) {
    const system = this.registeredSystems.get(systemName);
    if (!system) {
      this.log('Sistema no encontrado: ' + systemName, 'error');
      return false;
    }
    
    const dependencies = system.dependencies;
    const available = dependencies.every(function(dep) {
      if (typeof dep === 'string') {
        // Usar typeof para evitar ReferenceError si la propiedad no existe
        // eslint-disable-next-line no-undef
        return typeof window[dep] !== 'undefined';
      } else if (typeof dep === 'function') {
        return dep();
      }
      return false;
    });
    
    this.log('Dependencias de ' + systemName + ': ' + (available ? 'disponibles' : 'faltantes'));
    return available;
  },
  
  /**
   * Inicializar un sistema si sus dependencias están disponibles
   * 
   * Verifica las dependencias y ejecuta el callback de inicialización si están disponibles.
   * 
   * @param {string} systemName - Nombre del sistema a inicializar
   * @returns {boolean} true si el sistema se inicializó correctamente, false en caso contrario
   * @example
   * if (SystemEventManager.initializeSystem('miSistema')) {
   *   console.log('Sistema inicializado correctamente');
   * }
   */
  initializeSystem(systemName) {
    const system = this.registeredSystems.get(systemName);
    if (!system || system.initialized) {
      return false;
    }
    
    if (this.checkDependencies(systemName)) {
      try {
        system.callback();
        system.initialized = true;
        this.log('Sistema inicializado: ' + systemName);
        return true;
      } catch (error) {
        // ✅ MEJORADO: Registrar error usando ErrorHandler además del log interno
        if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
          ErrorHandler.logError(
            `Error al inicializar sistema '${systemName}': ${error.message || error}`,
            'SYSTEM_INIT'
          );
        }
        this.log('Error al inicializar sistema ' + systemName + ':', 'error', error);
        return false;
      }
    }
    
    return false;
  },
  
  /**
   * Obtener estado de inicialización
   * 
   * Retorna un objeto con el estado completo de inicialización, incluyendo
   * los sistemas registrados y sus detalles.
   * 
   * @returns {Object} Estado de inicialización completo
   * @example
   * const state = SystemEventManager.getInitializationState();
   * console.log('Sistemas registrados:', state.registeredSystems);
   */
  getInitializationState() {
    // eslint-disable-next-line prefer-object-spread
    return Object.assign({}, this.initializationState, {
      registeredSystems: Array.from(this.registeredSystems.keys()),
      systemDetails: Object.fromEntries(
        Array.from(this.registeredSystems.entries()).map(function(entry) {
          const name = entry[0];
          const system = entry[1];
          return [
            name,
            { initialized: system.initialized, dependencies: system.dependencies }
          ];
        })
      )
    });
  },
  
  /**
   * Logging del sistema de eventos
   * 
   * Registra mensajes con timestamp y nivel de log.
   * 
   * @param {string} message - Mensaje a registrar
   * @param {string} [level='info'] - Nivel de log ('info', 'warn', 'error')
   * @param {*} [data=null] - Datos adicionales a registrar
   * @returns {void}
   * @example
   * SystemEventManager.log('Mensaje informativo');
   * SystemEventManager.log('Advertencia', 'warn');
   * SystemEventManager.log('Error', 'error', errorObject);
   */
  log(message, level, data) {
    if (level === undefined) {
      level = 'info';
    }
    if (data === undefined) {
      data = null;
    }
    
    const timestamp = new Date().toISOString();
    const logMessage = '[SystemEventManager ' + timestamp + '] ' + message;
    
    if (level === 'error') {
      // eslint-disable-next-line no-console
      console.error(logMessage, data);
    } else if (level === 'warn') {
      // eslint-disable-next-line no-console
      console.warn(logMessage, data);
    } else {
      // eslint-disable-next-line no-console
      console.log(logMessage, data);
    }
  }
};

// ========================================
// EXPOSICIÓN GLOBAL
// ========================================

/**
 * Exponer SystemEventManager globalmente para mantener compatibilidad
 * con el código existente que usa window.SystemEventManager
 * 
 * NOTA: En el archivo original (dashboard.js línea 4819) se expone como:
 * window.SystemEventManager = SystemEventManager
 * 
 * Mantenemos la misma lógica para compatibilidad exacta.
 */
if (typeof window !== 'undefined') {
  // ✅ SEGURIDAD: Método 1: Asignación directa dentro de try...catch
  try {
    window.SystemEventManager = SystemEventManager;
    if (window.SystemEventManager === SystemEventManager) {
      // ✅ Éxito, no hacer nada más
    }
  } catch (error) {
    // ✅ SEGURIDAD: Método 2: Object.defineProperty como fallback seguro
    try {
      Object.defineProperty(window, 'SystemEventManager', {
        value: SystemEventManager,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // ✅ SEGURIDAD: Si ambos métodos fallan, registrar advertencia pero no usar eval
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('[SystemEventManager] ⚠️ No se pudo exponer SystemEventManager usando métodos seguros:', defineError);
      }
    }
  }
}

// Si usas ES6 modules, descomentar:
// export { SystemEventManager };

// Si usas CommonJS, descomentar:
// module.exports = { SystemEventManager };
