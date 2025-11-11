/**
 * Constantes Globales del Dashboard
 * 
 * Define todas las constantes reutilizables del sistema, incluyendo selectores CSS
 * optimizados para mejorar el rendimiento del dashboard.
 * 
 * @module config/constants
 * @namespace SELECTORS
 * @since 1.0.0
 * @author Christian
 * 
 * @example
 * // Uso de selectores
 * jQuery(SELECTORS.STAT_CARD).each(function() {
 *   // Procesar tarjeta
 * });
 * 
 * // Selector compuesto
 * jQuery(SELECTORS.DASHBOARD_CARDS).addClass('active');
 */

// ========================================
// SELECTORES COMUNES PARA OPTIMIZACIÓN
// ========================================

/**
 * Selectores CSS optimizados para el dashboard
 * 
 * Estos selectores están centralizados para:
 * - Optimizar el rendimiento (evitar repetición de strings)
 * - Facilitar el mantenimiento (cambios en un solo lugar)
 * - Mejorar la legibilidad del código
 * 
 * @type {Object}
 * @namespace SELECTORS
 * @description Selectores CSS reutilizables para optimizar el rendimiento
 * 
 * @property {string} STAT_CARD - Selector de tarjeta de estadística base
 * @property {string} STAT_VALUE - Selector de valor de estadística
 * @property {string} STAT_DESC - Selector de descripción de estadística
 * @property {string} STAT_CARD_MEMORY - Selector de tarjeta de memoria
 * @property {string} STAT_CARD_RETRIES - Selector de tarjeta de reintentos
 * @property {string} STAT_CARD_SYNC - Selector de tarjeta de sincronización
 * @property {string} DASHBOARD_CARDS - Selector compuesto de tarjetas (compatibilidad)
 * @property {string} METRIC_ELEMENTS - Selector compuesto de elementos de métricas
 */
const SELECTORS = {
  /**
   * Selectores base para tarjetas de estadísticas
   * 
   * @type {Object}
   * @property {string} STAT_CARD - Selector de tarjeta de estadística
   * @property {string} STAT_VALUE - Selector de valor de estadística
   * @property {string} STAT_DESC - Selector de descripción de estadística
   */
  // Selectores base
  STAT_CARD: '.mi-integracion-api-stat-card',
  STAT_VALUE: '.mi-integracion-api-stat-value',
  STAT_DESC: '.mi-integracion-api-stat-desc',
  
  /**
   * Selectores específicos por tipo de tarjeta
   * 
   * @type {Object}
   * @property {string} STAT_CARD_MEMORY - Selector de tarjeta de memoria
   * @property {string} STAT_CARD_RETRIES - Selector de tarjeta de reintentos
   * @property {string} STAT_CARD_SYNC - Selector de tarjeta de sincronización
   */
  // Selectores específicos
  STAT_CARD_MEMORY: '.mi-integracion-api-stat-card.memory',
  STAT_CARD_RETRIES: '.mi-integracion-api-stat-card.retries',
  STAT_CARD_SYNC: '.mi-integracion-api-stat-card.sync',
  
  /**
   * Selectores compuestos para compatibilidad
   * 
   * Estos selectores permiten mantener compatibilidad con diferentes versiones
   * del dashboard y diferentes estructuras HTML.
   * 
   * @type {Object}
   * @property {string} DASHBOARD_CARDS - Selector compuesto de tarjetas
   * @property {string} METRIC_ELEMENTS - Selector compuesto de elementos de métricas
   */
  // Selectores compuestos (mantener flexibilidad)
  DASHBOARD_CARDS: '.dashboard-card, .verial-stat-card, .mi-integracion-api-stat-card',
  METRIC_ELEMENTS: '.dashboard-metric, .verial-stat-value, .mi-integracion-api-stat-value'
};

// ========================================
// EXPOSICIÓN GLOBAL
// ========================================

/**
 * Exponer SELECTORS globalmente para mantener compatibilidad
 * con el código existente que usa SELECTORS directamente
 * 
 * NOTA: En el archivo original (dashboard.js línea 341) se define como:
 * const SELECTORS = { ... }
 * 
 * Mantenemos la misma lógica para compatibilidad exacta.
 */
if (typeof window !== 'undefined') {
  try {
    // Asignar a window.SELECTORS
    window.SELECTORS = SELECTORS;
  } catch (error) {
    // Si falla, usar defineProperty como alternativa
    // Nota: Capturamos el error para proporcionar un fallback seguro
    // El error se ignora intencionalmente ya que tenemos un fallback
    // Usamos void para indicar que el error se ignora intencionalmente
    void error;
    try {
      Object.defineProperty(window, 'SELECTORS', {
        value: SELECTORS,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // Si también falla defineProperty, registrar el error pero no lanzar excepción
      // El error se maneja silenciosamente para no interrumpir la ejecución
      // Usamos void para indicar que el error se ignora intencionalmente
      void defineError;
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar SELECTORS a window:', defineError);
      }
    }
  }
}

// Si usas ES6 modules, descomentar:
// export { SELECTORS };

// Si usas CommonJS (para tests):
/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { SELECTORS };
}
