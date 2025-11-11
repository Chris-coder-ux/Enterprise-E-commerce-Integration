/**
 * Utilidades DOM del Dashboard
 * 
 * Centraliza el cacheo de elementos DOM y utilidades relacionadas
 * para optimizar el rendimiento del dashboard.
 * 
 * @module utils/DomUtils
 * @namespace DomUtils
 * @since 1.0.0
 * @author Christian
 */

/* global jQuery, DASHBOARD_CONFIG */

/**
 * Cache de elementos DOM del dashboard
 * 
 * Cachea elementos jQuery del DOM para evitar consultas repetitivas
 * y mejorar el rendimiento del dashboard.
 * 
 * @type {Object}
 * @property {jQuery} $syncBtn - Botón de sincronización
 * @property {jQuery} $feedback - Área de feedback
 * @property {jQuery} $progressBar - Barra de progreso
 * @property {jQuery} $progressInfo - Información de progreso
 * @property {jQuery} $cancelBtn - Botón de cancelar
 * @property {jQuery} $syncStatusContainer - Contenedor de estado de sincronización
 * @property {jQuery} $batchSizeSelector - Selector de tamaño de lote
 * @property {jQuery} $metricElements - Elementos de métricas
 */
let DOM_CACHE = null;

/**
 * Inicializar el cache de elementos DOM
 * 
 * Crea el objeto DOM_CACHE con todos los elementos jQuery necesarios.
 * Debe llamarse después de que el DOM esté listo y DASHBOARD_CONFIG esté disponible.
 * 
 * @returns {Object} El objeto DOM_CACHE inicializado
 * @throws {Error} Si jQuery no está disponible
 * @throws {Error} Si DASHBOARD_CONFIG no está disponible
 * 
 * @example
 * // Inicializar el cache
 * DomUtils.initCache();
 * 
 * // Usar el cache
 * DomUtils.getCache().$syncBtn.text('Sincronizando...');
 */
function initCache() {
  // Verificar dependencias
  if (typeof jQuery === 'undefined') {
    throw new TypeError('jQuery no está disponible. DomUtils requiere jQuery.');
  }

  // eslint-disable-next-line prefer-optional-chain
  if (typeof DASHBOARD_CONFIG === 'undefined' || !DASHBOARD_CONFIG || !DASHBOARD_CONFIG.selectors) {
    throw new TypeError('DASHBOARD_CONFIG no está disponible. DomUtils requiere DASHBOARD_CONFIG.');
  }

  // Inicializar el cache
  DOM_CACHE = {
    $syncBtn: jQuery(DASHBOARD_CONFIG.selectors.syncButton),
    $feedback: jQuery(DASHBOARD_CONFIG.selectors.feedback),
    $progressBar: jQuery('.sync-progress-bar'),
    $progressInfo: jQuery(DASHBOARD_CONFIG.selectors.progressInfo),
    $cancelBtn: jQuery(DASHBOARD_CONFIG.selectors.cancelButton),
    $syncStatusContainer: jQuery(DASHBOARD_CONFIG.selectors.statusContainer),
    $batchSizeSelector: jQuery(DASHBOARD_CONFIG.selectors.batchSize),
    $metricElements: jQuery('.dashboard-metric:not(.mi-integracion-api-stat-card):not([data-card-type])')
  };

  return DOM_CACHE;
}

/**
 * Obtener el cache de elementos DOM
 * 
 * Retorna el objeto DOM_CACHE. Si no está inicializado, lo inicializa automáticamente.
 * 
 * @returns {Object} El objeto DOM_CACHE
 * 
 * @example
 * // Obtener el cache
 * const cache = DomUtils.getCache();
 * cache.$syncBtn.text('Sincronizando...');
 */
function getCache() {
  if (DOM_CACHE === null) {
    initCache();
  }
  return DOM_CACHE;
}

/**
 * Refrescar el cache de elementos DOM
 * 
 * Reinicializa el cache de elementos DOM. Útil cuando el DOM cambia
 * dinámicamente y necesitamos actualizar las referencias.
 * 
 * @returns {Object} El objeto DOM_CACHE refrescado
 * 
 * @example
 * // Refrescar el cache después de cambios en el DOM
 * DomUtils.refreshCache();
 */
function refreshCache() {
  DOM_CACHE = null;
  return initCache();
}

/**
 * Verificar si el cache está inicializado
 * 
 * @returns {boolean} true si el cache está inicializado, false en caso contrario
 * 
 * @example
 * if (DomUtils.isCacheInitialized()) {
 *   // Usar el cache
 * }
 */
function isCacheInitialized() {
  return DOM_CACHE !== null;
}

/**
 * Objeto DomUtils con métodos públicos
 */
const DomUtils = {
  initCache,
  getCache,
  refreshCache,
  isCacheInitialized
};

/**
 * Exponer DomUtils globalmente para mantener compatibilidad
 * con el código existente que usa window.DomUtils
 */
if (typeof window !== 'undefined') {
  try {
    window.DomUtils = DomUtils;
  } catch (error) {
    try {
      Object.defineProperty(window, 'DomUtils', {
        value: DomUtils,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar DomUtils a window:', defineError, error);
      }
    }
  }
}

/**
 * Exponer DOM_CACHE globalmente para mantener compatibilidad
 * con el código existente que usa DOM_CACHE directamente
 * 
 * NOTA: En el archivo original (dashboard.js línea 709) se define como:
 * const DOM_CACHE = { ... }
 * 
 * Para mantener compatibilidad, exponemos un getter que inicializa
 * el cache si es necesario.
 * 
 * IMPORTANTE: No inicializamos el cache automáticamente aquí para evitar
 * problemas en entornos de testing. El cache se inicializará cuando
 * se acceda a window.DOM_CACHE o cuando se llame explícitamente a initCache().
 */
if (typeof window !== 'undefined') {
  try {
    // Usar Object.defineProperty para crear un getter que inicialice el cache
    Object.defineProperty(window, 'DOM_CACHE', {
      get() {
        return getCache();
      },
      enumerable: true,
      configurable: true
    });
  } catch (error) {
    // eslint-disable-next-line no-console
    if (typeof console !== 'undefined' && console.warn) {
      // eslint-disable-next-line no-console
      console.warn('No se pudo asignar DOM_CACHE a window:', error);
    }
  }
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { DomUtils, DOM_CACHE: getCache };
}
