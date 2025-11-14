/**
 * Optimizador de Actualizaciones de UI
 * 
 * Gestiona actualizaciones de UI de forma eficiente evitando cambios innecesarios
 * y usando requestAnimationFrame para actualizaciones visuales frecuentes.
 * 
 * @module utils/UIOptimizer
 * @class UIOptimizer
 * @since 1.0.0
 * @author Christian
 */

/* global ErrorHandler */

/**
 * Clase para optimizar actualizaciones de UI
 * 
 * @class UIOptimizer
 * @description Gestión eficiente de actualizaciones de UI con comparación de datos y requestAnimationFrame
 * 
 * @example
 * // Actualizar texto solo si cambió
 * UIOptimizer.updateTextIfChanged($element, 'Nuevo texto', 'text-key');
 * 
 * // Actualizar con requestAnimationFrame
 * UIOptimizer.scheduleUpdate(() => {
 *   $element.text('Nuevo texto');
 * });
 */
class UIOptimizer {
  /**
   * Constructor de UIOptimizer
   * 
   * @constructor
   */
  constructor() {
    // Cache de valores anteriores para comparación
    this.valueCache = new Map();
    
    // Cache de datos para updateDataIfChanged
    this.dataCache = new Map();
    
    // Cola de actualizaciones pendientes para requestAnimationFrame
    this.updateQueue = [];
    this.rafId = null;
    this.timeoutId = null; // ✅ NUEVO: ID de setTimeout para poder cancelarlo
    this.isProcessingQueue = false;
    
    // Configuración
    this.config = {
      // Umbral mínimo de cambio para considerar significativo (porcentaje)
      changeThreshold: 0.1, // 0.1% de cambio mínimo
      
      // Tiempo máximo entre actualizaciones (ms)
      maxUpdateInterval: 100, // 100ms = ~10 actualizaciones por segundo máximo
      
      // Último tiempo de actualización
      lastUpdateTime: 0,
      
      // Habilitar comparación profunda de objetos
      deepCompare: true
    };
  }

  /**
   * Compara dos valores para determinar si han cambiado significativamente
   * 
   * @param {*} oldValue - Valor anterior
   * @param {*} newValue - Valor nuevo
   * @param {string} _key - Clave única para el valor (opcional, no usado actualmente)
   * @returns {boolean} true si el cambio es significativo, false en caso contrario
   * @private
   */
  hasSignificantChange(oldValue, newValue, _key = null) {
    // Si no hay valor anterior, siempre actualizar
    if (oldValue === undefined || oldValue === null) {
      return true;
    }
    
    // Comparación estricta para valores primitivos
    if (oldValue === newValue) {
      return false;
    }
    
    // ✅ MEJORADO: Detectar strings numéricos y compararlos como números con umbral
    let oldNum = null;
    if (typeof oldValue === 'string') {
      const parsed = parseFloat(oldValue);
      if (!isNaN(parsed) && isFinite(parsed)) {
        oldNum = parsed;
      }
    } else if (typeof oldValue === 'number') {
      oldNum = oldValue;
    }
    
    let newNum = null;
    if (typeof newValue === 'string') {
      const parsed = parseFloat(newValue);
      if (!isNaN(parsed) && isFinite(parsed)) {
        newNum = parsed;
      }
    } else if (typeof newValue === 'number') {
      newNum = newValue;
    }
    
    // ✅ CORREGIDO: Comparación de números con umbral (incluyendo strings numéricos)
    // Si ambos son números (o strings numéricos), usar comparación con umbral
    if (oldNum !== null && newNum !== null) {
      const change = Math.abs(newNum - oldNum);
      const percentChange = oldNum !== 0 ? (change / Math.abs(oldNum)) * 100 : change;
      return percentChange >= this.config.changeThreshold;
    }
    
    // Comparación de strings (no numéricos)
    if (typeof oldValue === 'string' && typeof newValue === 'string') {
      // Si no son numéricos, cualquier diferencia es significativa
      return oldValue !== newValue;
    }
    
    // Comparación profunda de objetos si está habilitada
    if (this.config.deepCompare && typeof oldValue === 'object' && typeof newValue === 'object' && oldValue !== null && newValue !== null) {
      return this.deepCompareObjects(oldValue, newValue);
    }
    
    // Por defecto, considerar como cambio significativo
    return true;
  }

  /**
   * Compara dos objetos de forma profunda
   * 
   * @param {Object} obj1 - Primer objeto
   * @param {Object} obj2 - Segundo objeto
   * @returns {boolean} true si son diferentes, false si son iguales
   * @private
   */
  deepCompareObjects(obj1, obj2) {
    // Comparación rápida de referencias
    if (obj1 === obj2) {
      return false;
    }
    
    // Comparar claves
    const keys1 = Object.keys(obj1);
    const keys2 = Object.keys(obj2);
    
    if (keys1.length !== keys2.length) {
      return true;
    }
    
    // Comparar valores de cada clave
    for (const key of keys1) {
      const val1 = obj1[key];
      const val2 = obj2[key];
      
      // Comparar tipos
      if (typeof val1 !== typeof val2) {
        return true;
      }
      
      // Comparar valores primitivos
      if (val1 !== val2) {
        // Si son números, usar umbral
        if (typeof val1 === 'number' && typeof val2 === 'number') {
          const change = Math.abs(val2 - val1);
          const percentChange = val1 !== 0 ? (change / Math.abs(val1)) * 100 : change;
          if (percentChange >= this.config.changeThreshold) {
            return true;
          }
        } else {
          // Para otros tipos, cualquier diferencia es significativa
          return true;
        }
      }
    }
    
    return false;
  }

  /**
   * Obtiene jQuery disponible (global o window.jQuery)
   * 
   * @returns {Function|null} Función jQuery o null si no está disponible
   * @private
   */
  static getJQuery() {
    if (typeof jQuery !== 'undefined') {
      return jQuery;
    }
    if (typeof window !== 'undefined' && typeof window.jQuery !== 'undefined') {
      return window.jQuery;
    }
    return null;
  }

  /**
   * Convierte un elemento a jQuery wrapper
   * 
   * @param {jQuery|HTMLElement} element - Elemento a convertir
   * @returns {jQuery|null} Elemento jQuery o null si jQuery no está disponible
   * @private
   */
  static toJQuery(element) {
    if (!element) {
      return null;
    }
    
    const jQueryFn = UIOptimizer.getJQuery();
    if (!jQueryFn) {
      return null;
    }
    
    // Verificar si ya es un objeto jQuery
    if (element instanceof jQueryFn) {
      return element;
    }
    
    // Convertir elemento DOM a jQuery
    return jQueryFn(element);
  }

  /**
   * Actualiza el texto de un elemento solo si cambió significativamente
   * 
   * @param {jQuery|HTMLElement} element - Elemento jQuery o DOM a actualizar
   * @param {string} newText - Nuevo texto
   * @param {string} cacheKey - Clave única para cache (opcional, se genera automáticamente si no se proporciona)
   * @returns {boolean} true si se actualizó, false si no había cambio significativo
   * 
   * @example
   * UIOptimizer.updateTextIfChanged($('#progress'), '50%', 'progress-text');
   */
  static updateTextIfChanged(element, newText, cacheKey = null) {
    if (!element) {
      return false;
    }
    
    const $element = UIOptimizer.toJQuery(element);
    
    if (!$element || $element.length === 0) {
      return false;
    }
    
    // Generar clave de cache si no se proporciona
    const key = cacheKey || `text-${$element.attr('id') || $element.attr('class') || 'unknown'}`;
    
    // Obtener instancia singleton
    const optimizer = UIOptimizer.getInstance();
    
    // Obtener valor anterior del cache
    const oldText = optimizer.valueCache.get(key);
    
    // Comparar valores
    if (!optimizer.hasSignificantChange(oldText, newText, key)) {
      return false; // No hay cambio significativo
    }
    
    // Actualizar cache
    optimizer.valueCache.set(key, newText);
    
    // Programar actualización con requestAnimationFrame
    optimizer.scheduleUpdate(() => {
      try {
        $element.text(newText);
      } catch (error) {
        // ✅ CORREGIDO: El error también se captura en processUpdateQueue, pero aquí lo registramos también
        if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
          ErrorHandler.logError(`Error actualizando texto: ${error.message || error}`, 'UI_UPDATE');
        }
        // Re-lanzar el error para que también se capture en processUpdateQueue
        throw error;
      }
    });
    
    return true;
  }

  /**
   * Actualiza el HTML de un elemento solo si cambió significativamente
   * 
   * ⚠️ ADVERTENCIA: Solo usar con HTML seguro (ya sanitizado)
   * 
   * @param {jQuery|HTMLElement} element - Elemento jQuery o DOM a actualizar
   * @param {string} newHtml - Nuevo HTML
   * @param {string} cacheKey - Clave única para cache
   * @returns {boolean} true si se actualizó, false si no había cambio significativo
   */
  static updateHtmlIfChanged(element, newHtml, cacheKey) {
    if (!element || !cacheKey) {
      return false;
    }
    
    const $element = UIOptimizer.toJQuery(element);
    
    if (!$element || $element.length === 0) {
      return false;
    }
    
    // Obtener instancia singleton
    const optimizer = UIOptimizer.getInstance();
    
    // Obtener valor anterior del cache
    const oldHtml = optimizer.valueCache.get(cacheKey);
    
    // Comparar valores
    if (!optimizer.hasSignificantChange(oldHtml, newHtml, cacheKey)) {
      return false; // No hay cambio significativo
    }
    
    // Actualizar cache
    optimizer.valueCache.set(cacheKey, newHtml);
    
    // Programar actualización con requestAnimationFrame
    optimizer.scheduleUpdate(() => {
      try {
        $element.html(newHtml);
      } catch (error) {
        if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
          ErrorHandler.logError(`Error actualizando HTML: ${error.message || error}`, 'UI_UPDATE');
        }
      }
    });
    
    return true;
  }

  /**
   * Actualiza el valor de un elemento solo si cambió significativamente
   * 
   * @param {jQuery|HTMLElement} element - Elemento jQuery o DOM a actualizar
   * @param {string|number} newValue - Nuevo valor
   * @param {string} cacheKey - Clave única para cache
   * @returns {boolean} true si se actualizó, false si no había cambio significativo
   */
  static updateValueIfChanged(element, newValue, cacheKey) {
    if (!element || !cacheKey) {
      return false;
    }
    
    const $element = UIOptimizer.toJQuery(element);
    
    if (!$element || $element.length === 0) {
      return false;
    }
    
    // Obtener instancia singleton
    const optimizer = UIOptimizer.getInstance();
    
    // Obtener valor anterior del cache
    const oldValue = optimizer.valueCache.get(cacheKey);
    
    // Comparar valores
    if (!optimizer.hasSignificantChange(oldValue, newValue, cacheKey)) {
      return false; // No hay cambio significativo
    }
    
    // Actualizar cache
    optimizer.valueCache.set(cacheKey, newValue);
    
    // Programar actualización con requestAnimationFrame
    optimizer.scheduleUpdate(() => {
      try {
        $element.val(newValue);
      } catch (error) {
        if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
          ErrorHandler.logError(`Error actualizando valor: ${error.message || error}`, 'UI_UPDATE');
        }
      }
    });
    
    return true;
  }

  /**
   * Actualiza el CSS de un elemento solo si cambió significativamente
   * 
   * @param {jQuery|HTMLElement} element - Elemento jQuery o DOM a actualizar
   * @param {string|Object} property - Propiedad CSS o objeto con propiedades
   * @param {string|number} value - Valor CSS (si property es string)
   * @param {string} cacheKey - Clave única para cache
   * @returns {boolean} true si se actualizó, false si no había cambio significativo
   */
  static updateCssIfChanged(element, property, value, cacheKey) {
    if (!element) {
      return false;
    }
    
    const $element = UIOptimizer.toJQuery(element);
    
    if (!$element || $element.length === 0) {
      return false;
    }
    
    // Obtener instancia singleton
    const optimizer = UIOptimizer.getInstance();
    
    // ✅ MEJORADO: Generar clave de cache si no se proporciona
    const key = cacheKey || `css-${$element.attr('id') || $element.attr('class') || 'unknown'}`;
    
    // Construir valor de cache
    const newCssValue = typeof property === 'object' ? JSON.stringify(property) : `${property}:${value}`;
    
    // Obtener valor anterior del cache
    const oldCssValue = optimizer.valueCache.get(key);
    
    // Comparar valores
    if (!optimizer.hasSignificantChange(oldCssValue, newCssValue, key)) {
      return false; // No hay cambio significativo
    }
    
    // Actualizar cache
    optimizer.valueCache.set(key, newCssValue);
    
    // Programar actualización con requestAnimationFrame
    optimizer.scheduleUpdate(() => {
      try {
        if (typeof property === 'object') {
          $element.css(property);
        } else {
          $element.css(property, value);
        }
      } catch (error) {
        if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
          ErrorHandler.logError(`Error actualizando CSS: ${error.message || error}`, 'UI_UPDATE');
        }
      }
    });
    
    return true;
  }

  /**
   * Actualiza un objeto de datos completo comparando cambios significativos
   * 
   * @param {Object} data - Objeto con los nuevos datos
   * @param {string} cacheKey - Clave única para cache
   * @param {Function} updateCallback - Función callback que recibe los datos y actualiza la UI
   * @returns {boolean} true si hubo cambios significativos y se actualizó, false en caso contrario
   */
  static updateDataIfChanged(data, cacheKey, updateCallback) {
    if (!data || !cacheKey || typeof updateCallback !== 'function') {
      return false;
    }
    
    // Obtener instancia singleton
    const optimizer = UIOptimizer.getInstance();
    
    // Obtener datos anteriores del cache de datos
    const oldData = optimizer.dataCache.get(cacheKey);
    
    // Si no hay datos anteriores, es la primera vez
    if (oldData === undefined) {
      // Guardar una copia profunda para evitar cambios externos
      optimizer.dataCache.set(cacheKey, JSON.parse(JSON.stringify(data)));
      
      // Ejecutar callback de forma síncrona
      try {
        updateCallback(data);
      } catch (error) {
        // Delegar al error handler global (si existe)
        if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
          window.ErrorHandler.logError(error);
        } else {
          // Si no existe un logger, lanzar la excepción para no perder información depurativa
          throw error;
        }
      }
      return true;
    }
    
    // Comparar profundamente usando JSON.stringify
    const prevStr = JSON.stringify(oldData);
    const currStr = JSON.stringify(data);
    
    // Si hay cambios, actualizar
    if (prevStr !== currStr) {
      // Guardar una copia profunda para evitar cambios externos
      optimizer.dataCache.set(cacheKey, JSON.parse(JSON.stringify(data)));
      
      // Ejecutar callback de forma síncrona
      try {
        updateCallback(data);
      } catch (error) {
        // Delegar al error handler global (si existe)
        if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
          window.ErrorHandler.logError(error);
        } else {
          // Si no existe un logger, lanzar la excepción para no perder información depurativa
          throw error;
        }
      }
      return true;
    }
    
    // No hubo cambio significativo
    return false;
  }

  /**
   * Programa una actualización usando requestAnimationFrame
   * 
   * @param {Function} updateFunction - Función a ejecutar en el próximo frame
   * @returns {void}
   */
  scheduleUpdate(updateFunction) {
    if (typeof updateFunction !== 'function') {
      return;
    }
    
    // Agregar a la cola
    this.updateQueue.push(updateFunction);
    
    // Si ya hay un RAF o timeout programado, no programar otro
    // El callback existente procesará todas las actualizaciones en la cola
    if (this.rafId !== null || this.timeoutId !== null) {
      return;
    }
    
    // Verificar throttling basado en tiempo
    const now = Date.now();
    const timeSinceLastUpdate = now - this.config.lastUpdateTime;
    
    // ✅ MEJORADO: Solo aplicar throttling si lastUpdateTime > 0 (ya se ha actualizado antes)
    // Si es la primera actualización (lastUpdateTime === 0), no aplicar throttling
    // ✅ CORREGIDO: Si el delay es 0 o negativo, procesar inmediatamente
    if (this.config.lastUpdateTime > 0 && timeSinceLastUpdate < this.config.maxUpdateInterval && this.updateQueue.length > 0) {
      // Esperar hasta que pase el intervalo mínimo
      const delay = this.config.maxUpdateInterval - timeSinceLastUpdate;
      if (delay > 0 && typeof window !== 'undefined' && typeof window.setTimeout === 'function') {
        this.timeoutId = window.setTimeout(() => {
          this.timeoutId = null;
          this.processUpdateQueue();
        }, delay);
        return;
      }
      // Si delay <= 0, procesar inmediatamente
    }
    
    // Programar procesamiento con requestAnimationFrame
    if (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
      this.rafId = window.requestAnimationFrame(() => {
        // ✅ CORREGIDO: Resetear rafId antes de procesar para permitir nuevas programaciones
        this.rafId = null;
        this.processUpdateQueue();
      });
    } else if (typeof window !== 'undefined' && typeof window.setTimeout === 'function') {
      // Fallback para entornos sin requestAnimationFrame
      this.timeoutId = window.setTimeout(() => {
        this.timeoutId = null;
        this.processUpdateQueue();
      }, 16); // ~60fps
    }
  }

  /**
   * Procesa la cola de actualizaciones pendientes
   * 
   * @returns {void}
   * @private
   */
  processUpdateQueue() {
    if (this.isProcessingQueue) {
      return; // Ya se está procesando
    }
    
    this.isProcessingQueue = true;
    // ✅ CORREGIDO: Resetear IDs antes de procesar para permitir nuevas programaciones
    this.rafId = null;
    this.timeoutId = null;
    this.config.lastUpdateTime = Date.now();
    
    // Procesar todas las actualizaciones en la cola
    const queue = this.updateQueue.slice(); // Copiar cola
    this.updateQueue = []; // Limpiar cola
    
    queue.forEach((updateFunction) => {
      try {
        updateFunction();
      } catch (error) {
        // Delegar al error handler global (si existe)
        if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
          window.ErrorHandler.logError(error);
        } else {
          // Si no existe un logger, lanzar la excepción para no perder información depurativa
          throw error;
        }
      }
    });
    
    this.isProcessingQueue = false;
    
    // Si se agregaron más actualizaciones mientras se procesaba, programar otra pasada
    if (this.updateQueue.length > 0) {
      // ✅ CORREGIDO: Verificar que no haya un RAF o timeout ya programado
      if (this.rafId === null && this.timeoutId === null) {
        if (typeof requestAnimationFrame !== 'undefined' && typeof window !== 'undefined' && window.requestAnimationFrame) {
          this.rafId = requestAnimationFrame(() => {
            this.rafId = null;
            this.processUpdateQueue();
          });
        } else {
          this.timeoutId = setTimeout(() => {
            this.timeoutId = null;
            this.processUpdateQueue();
          }, 16);
        }
      }
    }
  }

  /**
   * Limpia el cache de valores
   * 
   * @param {string} [key] - Clave específica a limpiar (opcional, si no se proporciona limpia todo)
   * @returns {void}
   */
  clearCache(key = null) {
    if (key) {
      this.valueCache.delete(key);
      this.dataCache.delete(key);
    } else {
      this.valueCache.clear();
      this.dataCache.clear();
    }
  }

  /**
   * Cancela todas las actualizaciones pendientes
   * 
   * @returns {void}
   */
  cancelPendingUpdates() {
    // Limpiar cola primero
    this.updateQueue = [];
    
    // Cancelar requestAnimationFrame si existe
    if (this.rafId !== null) {
      if (typeof window !== 'undefined' && typeof window.cancelAnimationFrame === 'function') {
        try {
          window.cancelAnimationFrame(this.rafId);
        } catch (error) {
          // Ignorar errores de cancelación
        }
      }
      this.rafId = null;
    }
    
    // Cancelar setTimeout si existe
    if (this.timeoutId !== null) {
      if (typeof window !== 'undefined' && typeof window.clearTimeout === 'function') {
        try {
          window.clearTimeout(this.timeoutId);
        } catch (error) {
          // Ignorar errores de cancelación
        }
      }
      this.timeoutId = null;
    }
    
    // Resetear estado de procesamiento
    this.isProcessingQueue = false;
  }

  /**
   * Obtiene la instancia singleton de UIOptimizer
   * 
   * @static
   * @returns {UIOptimizer} Instancia de UIOptimizer
   */
  static getInstance() {
    if (!UIOptimizer.instance) {
      UIOptimizer.instance = new UIOptimizer();
    }
    return UIOptimizer.instance;
  }
}

// Instancia singleton
UIOptimizer.instance = null;

/**
 * Exponer UIOptimizer globalmente
 */
(function exposeUIOptimizer() {
  if (typeof window === 'undefined') {
    return;
  }
  
  // Método 1: Asignación directa
  try {
    window.UIOptimizer = UIOptimizer;
    if (window.UIOptimizer === UIOptimizer) {
      return; // ✅ Éxito
    }
  } catch (error) {
    // Continuar con siguiente método
  }
  
  // Método 2: Object.defineProperty
  try {
    Object.defineProperty(window, 'UIOptimizer', {
      value: UIOptimizer,
      writable: true,
      enumerable: true,
      configurable: true
    });
    if (window.UIOptimizer === UIOptimizer) {
      return; // ✅ Éxito
    }
  } catch (defineError) {
    // ✅ SEGURIDAD: No usar eval como fallback
    /* eslint-disable no-console */
    if (typeof console !== 'undefined' && console.warn) {
      console.warn('[UIOptimizer] ⚠️ No se pudo exponer UIOptimizer usando métodos seguros:', defineError);
    }
    /* eslint-enable no-console */
  }
})();

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { UIOptimizer };
}

