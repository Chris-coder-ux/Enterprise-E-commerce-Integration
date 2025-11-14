/**
 * Gestor de Limpieza de Eventos
 * 
 * Gestiona la limpieza de event listeners para evitar fugas de memoria.
 * Rastrea y desvincula listeners de jQuery(document).on() y otros eventos.
 * 
 * @module managers/EventCleanupManager
 * @class EventCleanupManager
 * @since 1.0.0
 * @author Christian
 */

/* global ErrorHandler */

/**
 * Clase para gestionar la limpieza de eventos
 * 
 * @class EventCleanupManager
 * @description Rastrea y limpia event listeners para prevenir fugas de memoria
 * 
 * @example
 * // Registrar un listener con cleanup automático
 * EventCleanupManager.registerDocumentListener('click', '#my-button', handler, 'my-component');
 * 
 * // Limpiar todos los listeners de un componente
 * EventCleanupManager.cleanupComponent('my-component');
 * 
 * // Limpiar todos los listeners al salir de la página
 * EventCleanupManager.cleanupAll();
 */
class EventCleanupManager {
  /**
   * Constructor de EventCleanupManager
   * 
   * @constructor
   */
  constructor() {
    this.documentListeners = new Map();
    this.elementListeners = new Map();
    this.customEventListeners = new Map();
    this.nativeListeners = new Map();
    this.autoCleanupInitialized = false;
    this.initializeAutoCleanup();
  }

  /**
   * Inicializa el cleanup automático al salir de la página
   * 
   * @returns {void}
   * @private
   */
  initializeAutoCleanup() {
    if (this.autoCleanupInitialized) {
      return;
    }
    
    if (typeof window === 'undefined') {
      return;
    }
    
    this.autoCleanupInitialized = true;
    
    if (typeof window.addEventListener !== 'undefined') {
      // ✅ CORREGIDO: Eliminado 'unload' (deprecado) - usar 'pagehide' en su lugar
      // beforeunload: Se dispara antes de que la página se descargue (navegadores modernos)
      // pagehide: Más confiable en móviles y navegadores modernos, se dispara cuando la página se oculta
      window.addEventListener('beforeunload', () => this.cleanupAll(), { once: true });
      window.addEventListener('pagehide', () => this.cleanupAll(), { once: true });
    }
  }

  /**
   * Registra un listener de document con jQuery para cleanup automático
   * 
   * @param {string} event - Tipo de evento (ej: 'click', 'change')
   * @param {string} selector - Selector CSS del elemento
   * @param {Function} handler - Función handler del evento
   * @param {string} componentId - ID del componente que registra el listener
   * @returns {Function} Función para desvincular manualmente el listener
   * 
   * @example
   * const unsubscribe = EventCleanupManager.registerDocumentListener(
   *   'click',
   *   '#my-button',
   *   (e) => { console.log('clicked'); },
   *   'my-component'
   * );
   */
  static registerDocumentListener(event, selector, handler, componentId) {
    // Verificar jQuery primero
    if (typeof jQuery === 'undefined') {
      if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
        window.ErrorHandler.logError('jQuery no está disponible para registrar listener de document', 'EVENT_CLEANUP');
      }
      return function() {};
    }
    
    // Usar la misma instancia de jQuery(document) en todo el método
    // Priorizar window.document para que funcione con mocks en tests
    // En navegadores, window.document y document son la misma referencia,
    // pero en tests window.document puede ser un mock
    let doc;
    if (typeof window !== 'undefined' && window.document) {
      doc = window.document;
    } else if (typeof document !== 'undefined') {
      doc = document;
    } else {
      if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
        window.ErrorHandler.logError('document no está disponible', 'EVENT_CLEANUP');
      }
      return function() {};
    }
    const $doc = jQuery(doc);
    
    if (typeof $doc.on !== 'function') {
      if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
        window.ErrorHandler.logError('jQuery(document).on no está disponible', 'EVENT_CLEANUP');
      }
      return function() {};
    }
    
    // Obtener la instancia del manager
    // Dentro de un método estático, this se refiere a la clase misma
    let manager;
    try {
      manager = this.getInstance();
    } catch (error) {
      if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
        window.ErrorHandler.logError(`Error obteniendo instancia de EventCleanupManager: ${error.message || error}`, 'EVENT_CLEANUP');
      }
      return function() {};
    }
    
    if (!manager) {
      if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
        window.ErrorHandler.logError('No se pudo obtener la instancia de EventCleanupManager', 'EVENT_CLEANUP');
      }
      return function() {};
    }
    
    try {
      $doc.on(event, selector, handler);
    } catch (error) {
      if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
        window.ErrorHandler.logError(`Error registrando listener de document: ${error.message || error}`, 'EVENT_CLEANUP');
      }
      return function() {};
    }
    
    if (!manager.documentListeners.has(componentId)) {
      manager.documentListeners.set(componentId, []);
    }
    
    const listenerInfo = {
      event,
      selector,
      handler,
      componentId
    };
    
    manager.documentListeners.get(componentId).push(listenerInfo);
    
    // Retornar función para desvincular manualmente
    // Reutilizar la misma instancia de $doc
    return function() {
      $doc.off(event, selector, handler);
      const listeners = manager.documentListeners.get(componentId);
      if (listeners) {
        const index = listeners.findIndex(l => 
          l.event === event && 
          l.selector === selector && 
          l.handler === handler
        );
        if (index !== -1) {
          listeners.splice(index, 1);
        }
      }
    };
  }

  /**
   * Registra un listener de elemento específico con jQuery para cleanup automático
   * 
   * @param {jQuery|HTMLElement|string} element - Elemento jQuery, DOM o selector CSS
   * @param {string} event - Tipo de evento
   * @param {Function} handler - Función handler del evento
   * @param {string} componentId - ID del componente que registra el listener
   * @returns {Function} Función para desvincular manualmente el listener
   * 
   * @example
   * const unsubscribe = EventCleanupManager.registerElementListener(
   *   jQuery('#my-button'),
   *   'click',
   *   (e) => { console.log('clicked'); },
   *   'my-component'
   * );
   */
  static registerElementListener(element, event, handler, componentId) {
    // ------------------------------------------------------------------
    // registerElementListener
    // ------------------------------------------------------------------
    // Intenta añadir el listener a un elemento seleccionado con jQuery.
    // Si jQuery no está disponible o de otra manera se produce un error,
    // captura la excepción, registra el error y devuelve una función
    // no-op para evitar efectos colaterales.
    try {
      // Verificar si jQuery está disponible
      if (typeof window === 'undefined' || typeof window.jQuery === 'undefined') {
        if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
          window.ErrorHandler.logError(new Error('jQuery no está disponible para registrar listener de elemento'));
        }
        return function() {};
      }
      
      const $element = typeof element === 'string' 
        ? window.jQuery(element)
        : element instanceof window.jQuery 
          ? element 
          : window.jQuery(element);
      
      // Si el selector no devuelve nada, simplemente devolvemos una no-op.
      if (!$element || $element.length === 0) {
        if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
          window.ErrorHandler.logError(new Error(`EventCleanupManager: selector "${typeof element === 'string' ? element : 'elemento'}" not found`));
        }
        return function() {};
      }
      
      $element.on(event, handler);
      
      // Se guarda una referencia para el clean-up posterior.
      const manager = EventCleanupManager.getInstance();
      if (!manager.elementListeners.has(componentId)) {
        manager.elementListeners.set(componentId, []);
      }
      
      const listenerInfo = {
        element: $element,
        event,
        handler,
        componentId
      };
      
      manager.elementListeners.get(componentId).push(listenerInfo);
      
      // Devolvemos una función que desubscribirá el listener.
      return function() {
        try {
          $element.off(event, handler);
          const listeners = manager.elementListeners.get(componentId);
          if (listeners) {
            const index = listeners.findIndex(l => 
              l.element === $element && 
              l.event === event && 
              l.handler === handler
            );
            if (index !== -1) {
              listeners.splice(index, 1);
            }
          }
        } catch (e) {
          // En caso de que la llamada a off falle (por ejemplo porque
          // el elemento ya no exista) también se registra, pero no se
          // interrumpe el flujo.
          if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
            window.ErrorHandler.logError(e);
          }
        }
      };
    } catch (e) {
      // Si cualquier parte del proceso falla (jQuery no disponible,
      // la llamada a on, etc.), registramos el error y devolvemos
      // una función no-op para mantener el contrato de la API.
      if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
        window.ErrorHandler.logError(e);
      }
      return function() {};
    }
  }

  /**
   * Registra un listener de eventos personalizados (PollingManager, etc.) para cleanup automático
   * 
   * @param {Object} emitter - Objeto que emite eventos (debe tener métodos on/off o similar)
   * @param {string} event - Nombre del evento
   * @param {Function} handler - Función handler del evento
   * @param {string} componentId - ID del componente que registra el listener
   * @returns {Function} Función para desvincular manualmente el listener
   * 
   * @example
   * const unsubscribe = EventCleanupManager.registerCustomEventListener(
   *   pollingManager,
   *   'syncProgress',
   *   (data) => { console.log(data); },
   *   'my-component'
   * );
   */
  static registerCustomEventListener(emitter, event, handler, componentId) {
    if (!emitter || typeof emitter.on !== 'function') {
      if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
        ErrorHandler.logError('Emitter no válido para registrar listener de eventos personalizados', 'EVENT_CLEANUP');
      }
      return function() {};
    }
    
    const manager = EventCleanupManager.getInstance();
    
    let unsubscribe = null;
    if (typeof emitter.on === 'function') {
      unsubscribe = emitter.on(event, handler);
    }
    
    if (!manager.customEventListeners.has(componentId)) {
      manager.customEventListeners.set(componentId, []);
    }
    
    const listenerInfo = {
      emitter,
      event,
      handler,
      unsubscribe,
      componentId
    };
    
    manager.customEventListeners.get(componentId).push(listenerInfo);
    
    // Retornar función para desvincular manualmente
    return function() {
      if (unsubscribe && typeof unsubscribe === 'function') {
        unsubscribe();
      } else if (emitter && typeof emitter.off === 'function') {
        emitter.off(event, handler);
      }
      
      const listeners = manager.customEventListeners.get(componentId);
      if (listeners) {
        const index = listeners.findIndex(l => 
          l.emitter === emitter && 
          l.event === event && 
          l.handler === handler
        );
        if (index !== -1) {
          listeners.splice(index, 1);
        }
      }
    };
  }

  /**
   * Registra un listener nativo (addEventListener) para cleanup automático
   * 
   * @param {HTMLElement|Window} element - Elemento o window donde registrar el listener
   * @param {string} event - Tipo de evento
   * @param {Function} handler - Función handler del evento
   * @param {string} componentId - ID del componente que registra el listener
   * @param {Object|boolean} [options] - Opciones para addEventListener (passive, once, etc.)
   * @returns {Function} Función para desvincular manualmente el listener
   * 
   * @example
   * const unsubscribe = EventCleanupManager.registerNativeListener(
   *   window,
   *   'resize',
   *   () => { console.log('resized'); },
   *   'my-component',
   *   { passive: true }
   * );
   */
  static registerNativeListener(element, event, handler, componentId, options = false) {
    if (!element || typeof element.addEventListener !== 'function') {
      if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
        ErrorHandler.logError('Elemento no válido para registrar listener nativo', 'EVENT_CLEANUP');
      }
      return function() {};
    }
    
    const manager = EventCleanupManager.getInstance();
    // ✅ CORREGIDO: Cast del handler a EventListener para compatibilidad con TypeScript
    // @ts-expect-error - handler es Function pero addEventListener acepta EventListener
    // En runtime son compatibles, pero TypeScript requiere el tipo exacto
    element.addEventListener(event, handler, options);
    
    if (!manager.nativeListeners.has(componentId)) {
      manager.nativeListeners.set(componentId, []);
    }
    
    const listenerInfo = {
      element,
      event,
      handler,
      options,
      componentId
    };
    
    manager.nativeListeners.get(componentId).push(listenerInfo);
    
    // Retornar función para desvincular manualmente
    return function() {
      // @ts-expect-error - handler es Function pero removeEventListener acepta EventListener
      // En runtime son compatibles, pero TypeScript requiere el tipo exacto
      element.removeEventListener(event, handler, options);
      const listeners = manager.nativeListeners.get(componentId);
      if (listeners) {
        const index = listeners.findIndex(l => 
          l.element === element && 
          l.event === event && 
          l.handler === handler
        );
        if (index !== -1) {
          listeners.splice(index, 1);
        }
      }
    };
  }

  /**
   * Limpia todos los listeners de un componente específico
   * 
   * @param {string} componentId - ID del componente a limpiar
   * @returns {number} Número de listeners desvinculados
   */
  static cleanupComponent(componentId) {
    const manager = EventCleanupManager.getInstance();
    let cleanedCount = 0;
    const docListeners = manager.documentListeners.get(componentId);
    if (docListeners && docListeners.length > 0) {
      if (typeof jQuery !== 'undefined') {
        // Reutilizar la misma instancia de jQuery(document) en todo el bucle
        // Usar window.document si está disponible, sino usar document directamente
        const doc = (typeof window !== 'undefined' && window.document) ? window.document : document;
        const $doc = jQuery(doc);
        if (typeof $doc.off === 'function') {
          docListeners.forEach(listener => {
            try {
              $doc.off(listener.event, listener.selector, listener.handler);
              cleanedCount++;
            } catch (error) {
              if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
                window.ErrorHandler.logError(`Error limpiando listener de document: ${error.message || error}`, 'EVENT_CLEANUP');
              }
            }
          });
        }
      }
      manager.documentListeners.delete(componentId);
    }
    
    const elemListeners = manager.elementListeners.get(componentId);
    if (elemListeners && elemListeners.length > 0) {
      elemListeners.forEach(listener => {
        try {
          if (listener.element && typeof listener.element.off === 'function') {
            listener.element.off(listener.event, listener.handler);
            cleanedCount++;
          }
        } catch (error) {
          if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
            ErrorHandler.logError(`Error limpiando listener de elemento: ${error.message || error}`, 'EVENT_CLEANUP');
          }
        }
      });
      manager.elementListeners.delete(componentId);
    }
    
    const customListeners = manager.customEventListeners.get(componentId);
    if (customListeners && customListeners.length > 0) {
      customListeners.forEach(listener => {
        try {
          if (listener.unsubscribe && typeof listener.unsubscribe === 'function') {
            listener.unsubscribe();
            cleanedCount++;
          } else if (listener.emitter && typeof listener.emitter.off === 'function') {
            listener.emitter.off(listener.event, listener.handler);
            cleanedCount++;
          }
        } catch (error) {
          if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
            ErrorHandler.logError(`Error limpiando listener de eventos personalizados: ${error.message || error}`, 'EVENT_CLEANUP');
          }
        }
      });
      manager.customEventListeners.delete(componentId);
    }
    
    const nativeListeners = manager.nativeListeners.get(componentId);
    if (nativeListeners && nativeListeners.length > 0) {
      nativeListeners.forEach(listener => {
        try {
          if (listener.element && typeof listener.element.removeEventListener === 'function') {
            listener.element.removeEventListener(listener.event, listener.handler, listener.options);
            cleanedCount++;
          }
        } catch (error) {
          if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
            ErrorHandler.logError(`Error limpiando listener nativo: ${error.message || error}`, 'EVENT_CLEANUP');
          }
        }
      });
      manager.nativeListeners.delete(componentId);
    }
    
    return cleanedCount;
  }

  /**
   * Limpia todos los listeners registrados
   * 
   * @returns {number} Número total de listeners desvinculados
   */
  cleanupAll() {
    let totalCleaned = 0;
    const componentIds = new Set([
      ...this.documentListeners.keys(),
      ...this.elementListeners.keys(),
      ...this.customEventListeners.keys(),
      ...this.nativeListeners.keys()
    ]);
    
    componentIds.forEach(componentId => {
      totalCleaned += EventCleanupManager.cleanupComponent(componentId);
    });
    
    return totalCleaned;
  }

  /**
   * Obtiene estadísticas de listeners registrados
   * 
   * @returns {Object} Estadísticas de listeners
   */
  getStats() {
    return {
      documentListeners: Array.from(this.documentListeners.values()).reduce((sum, arr) => sum + arr.length, 0),
      elementListeners: Array.from(this.elementListeners.values()).reduce((sum, arr) => sum + arr.length, 0),
      customEventListeners: Array.from(this.customEventListeners.values()).reduce((sum, arr) => sum + arr.length, 0),
      nativeListeners: Array.from(this.nativeListeners.values()).reduce((sum, arr) => sum + arr.length, 0),
      totalComponents: new Set([
        ...this.documentListeners.keys(),
        ...this.elementListeners.keys(),
        ...this.customEventListeners.keys(),
        ...this.nativeListeners.keys()
      ]).size
    };
  }

  /**
   * Obtiene la instancia singleton de EventCleanupManager
   * 
   * @static
   * @returns {EventCleanupManager} Instancia de EventCleanupManager
   */
  static getInstance() {
    if (!EventCleanupManager.instance) {
      EventCleanupManager.instance = new EventCleanupManager();
    }
    return EventCleanupManager.instance;
  }

  /**
   * Limpia todos los listeners registrados de todos los componentes
   * 
   * @static
   * @returns {number} Número total de listeners limpiados
   */
  static cleanupAll() {
    return EventCleanupManager.getInstance().cleanupAll();
  }
}

// Instancia singleton
EventCleanupManager.instance = null;

/* ------------------------------------------------------------------*/
/*  1. Exponer la clase en el ámbito global                         */
/* ------------------------------------------------------------------*/
(function exposeEventCleanupManager() {
  // Primero la versión más universal (globalThis)
  try {
    // @ts-expect-error - TypeScript es estricto con los tipos de retorno de métodos
    // En runtime, Function y () => void son compatibles
    globalThis.EventCleanupManager = EventCleanupManager;
  } catch (e) {
    // Nada, seguirá intentando con window/global en el siguiente paso
  }

  // En navegadores (window) – muy usado en nuestras pruebas
  if (typeof window !== 'undefined') {
    try {
      // @ts-ignore - TypeScript es estricto con los tipos de retorno de métodos
      // En runtime, Function y () => void son compatibles
      window.EventCleanupManager = EventCleanupManager;
    } catch (e) {
      // Ignore
    }
  }

  // En Node/JS-dom (global) – por si el test lo cae allí
  if (typeof global !== 'undefined') {
    try {
      // @ts-expect-error - TypeScript es estricto con los tipos de retorno de métodos
      // En runtime, Function y () => void son compatibles
      global.EventCleanupManager = EventCleanupManager;
    } catch (e) {
      // Ignore
    }
  }
})();

/* ------------------------------------------------------------------*/
/*  2. Exportamos a CommonJS (para imports con require)              */
/* ------------------------------------------------------------------*/
if (typeof module !== 'undefined' && module.exports) {
  module.exports = EventCleanupManager;
}

