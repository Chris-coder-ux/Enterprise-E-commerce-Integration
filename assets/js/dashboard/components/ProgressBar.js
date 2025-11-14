/// <reference path="../types.d.ts" />

/**
 * Gestor de Barras de Progreso
 * 
 * Gestiona la visualización y actualización de barras de progreso
 * para el proceso de sincronización del dashboard.
 * 
 * @module components/ProgressBar
 * @namespace ProgressBar
 * @since 1.0.0
 * @author Christian
 */

/* global jQuery, DOM_CACHE, UIOptimizer */

/**
 * Selector CSS por defecto para la barra de progreso
 * 
 * @type {string}
 */
const DEFAULT_SELECTOR = '.sync-progress-bar';

/**
 * Inicializar la barra de progreso
 * 
 * Verifica que la barra de progreso esté disponible en el DOM
 * y la prepara para su uso.
 * 
 * @returns {boolean} true si la barra de progreso está disponible, false en caso contrario
 * 
 * @example
 * if (ProgressBar.initialize()) {
 *   // La barra de progreso está lista para usar
 * }
 */
function initialize() {
  if (typeof jQuery === 'undefined') {
    // eslint-disable-next-line no-console
    console.error('ProgressBar requiere jQuery');
    return false;
  }

  // Verificar que DOM_CACHE esté disponible
  if (typeof DOM_CACHE === 'undefined' || !DOM_CACHE || !DOM_CACHE.$progressBar) {
    // Intentar obtener la barra de progreso directamente
    const $progressBar = jQuery(DEFAULT_SELECTOR);
    if ($progressBar.length === 0) {
      // eslint-disable-next-line no-console
      console.warn('ProgressBar: No se encontró la barra de progreso en el DOM');
      return false;
    }
  }

  return true;
}

/**
 * Actualizar el ancho de la barra de progreso
 * 
 * @param {string|number} width - Ancho de la barra (puede ser porcentaje como '50%' o número)
 * @returns {boolean} true si se actualizó correctamente, false en caso contrario
 * 
 * @example
 * ProgressBar.setWidth('50%');
 * ProgressBar.setWidth(75); // Se convertirá a '75%'
 */
function setWidth(width) {
  if (typeof jQuery === 'undefined') {
    // eslint-disable-next-line no-console
    console.error('ProgressBar requiere jQuery');
    return false;
  }

  // Obtener la barra de progreso
  let $progressBar = null;

  // Intentar obtener desde DOM_CACHE primero
  if (typeof DOM_CACHE !== 'undefined' && DOM_CACHE && DOM_CACHE.$progressBar) {
    $progressBar = DOM_CACHE.$progressBar;
  } else {
    $progressBar = jQuery(DEFAULT_SELECTOR);
  }

  if ($progressBar.length === 0) {
    // eslint-disable-next-line no-console
    console.warn('ProgressBar: No se encontró la barra de progreso en el DOM');
    return false;
  }

  // Convertir número a porcentaje si es necesario
  let widthValue = width;
  if (typeof width === 'number') {
    widthValue = width + '%';
  }

  // ✅ OPTIMIZADO: Usar UIOptimizer para evitar actualizaciones innecesarias
  if (typeof UIOptimizer !== 'undefined' && UIOptimizer && typeof UIOptimizer.updateCssIfChanged === 'function') {
    const updated = UIOptimizer.updateCssIfChanged($progressBar, 'width', widthValue, 'progress-bar-width');
    return updated;
  }
  
  // Fallback: actualización directa
  $progressBar.css('width', widthValue);

  return true;
}

/**
 * Actualizar el porcentaje de la barra de progreso
 * 
 * @param {number} percentage - Porcentaje de progreso (0-100)
 * @returns {boolean} true si se actualizó correctamente, false en caso contrario
 * 
 * @example
 * ProgressBar.setPercentage(50); // Establece la barra al 50%
 */
function setPercentage(percentage) {
  // Validar que el porcentaje esté en el rango válido
  const validPercentage = Math.max(0, Math.min(100, percentage));
  return setWidth(validPercentage);
}

/**
 * Establecer el color de fondo de la barra de progreso
 * 
 * @param {string} color - Color en formato CSS (hex, rgb, nombre, etc.)
 * @returns {boolean} true si se actualizó correctamente, false en caso contrario
 * 
 * @example
 * ProgressBar.setColor('#0073aa');
 * ProgressBar.setColor('rgb(0, 115, 170)');
 */
function setColor(color) {
  if (typeof jQuery === 'undefined') {
    // eslint-disable-next-line no-console
    console.error('ProgressBar requiere jQuery');
    return false;
  }

  // Obtener la barra de progreso
  let $progressBar = null;

  // Intentar obtener desde DOM_CACHE primero
  if (typeof DOM_CACHE !== 'undefined' && DOM_CACHE && DOM_CACHE.$progressBar) {
    $progressBar = DOM_CACHE.$progressBar;
  } else {
    $progressBar = jQuery(DEFAULT_SELECTOR);
  }

  if ($progressBar.length === 0) {
    // eslint-disable-next-line no-console
    console.warn('ProgressBar: No se encontró la barra de progreso en el DOM');
    return false;
  }

  // ✅ OPTIMIZADO: Usar UIOptimizer para evitar actualizaciones innecesarias
  if (typeof UIOptimizer !== 'undefined' && UIOptimizer && typeof UIOptimizer.updateCssIfChanged === 'function') {
    const updated = UIOptimizer.updateCssIfChanged($progressBar, 'background-color', color, 'progress-bar-color');
    return updated;
  }
  
  // Fallback: actualización directa
  $progressBar.css('background-color', color);

  return true;
}

/**
 * Resetear la barra de progreso a su estado inicial
 * 
 * @param {string} [initialColor='#0073aa'] - Color inicial de la barra
 * @returns {boolean} true si se reseteó correctamente, false en caso contrario
 * 
 * @example
 * ProgressBar.reset();
 * ProgressBar.reset('#22c55e'); // Resetear con color verde
 */
function reset(initialColor) {
  const color = initialColor || '#0073aa';
  const success = setWidth('2%') && setColor(color);

  if (success) {
    // Aplicar transición suave
    let $progressBar = null;

    if (typeof DOM_CACHE !== 'undefined' && DOM_CACHE && DOM_CACHE.$progressBar) {
      $progressBar = DOM_CACHE.$progressBar;
    } else {
      $progressBar = jQuery(DEFAULT_SELECTOR);
    }

    if ($progressBar && $progressBar.length > 0) {
      $progressBar.css('transition', 'width 0.3s ease');
    }
  }

  return success;
}

/**
 * Obtener el ancho actual de la barra de progreso
 * 
 * @returns {string|null} El ancho actual de la barra o null si no está disponible
 * 
 * @example
 * const currentWidth = ProgressBar.getWidth();
 */
function getWidth() {
  if (typeof jQuery === 'undefined') {
    return null;
  }

  // Obtener la barra de progreso
  let $progressBar = null;

  if (typeof DOM_CACHE !== 'undefined' && DOM_CACHE && DOM_CACHE.$progressBar) {
    $progressBar = DOM_CACHE.$progressBar;
  } else {
    $progressBar = jQuery(DEFAULT_SELECTOR);
  }

  if ($progressBar.length === 0) {
    return null;
  }

  return $progressBar.css('width');
}

/**
 * Verificar si la barra de progreso está disponible
 * 
 * @returns {boolean} true si la barra de progreso está disponible, false en caso contrario
 * 
 * @example
 * if (ProgressBar.isAvailable()) {
 *   // La barra de progreso está disponible
 * }
 */
function isAvailable() {
  if (typeof jQuery === 'undefined') {
    return false;
  }

  if (typeof DOM_CACHE !== 'undefined' && DOM_CACHE && DOM_CACHE.$progressBar) {
    return DOM_CACHE.$progressBar.length > 0;
  }

  return jQuery(DEFAULT_SELECTOR).length > 0;
}

/**
 * Objeto ProgressBar con métodos públicos
 */
const ProgressBar = {
  initialize,
  setWidth,
  setPercentage,
  setColor,
  reset,
  getWidth,
  isAvailable,
  DEFAULT_SELECTOR
};

/**
 * Exponer ProgressBar globalmente para mantener compatibilidad
 * con el código existente que usa window.ProgressBar
 */
if (typeof window !== 'undefined') {
  try {
    window.ProgressBar = ProgressBar;
  } catch (error) {
    try {
      Object.defineProperty(window, 'ProgressBar', {
        value: ProgressBar,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar ProgressBar a window:', defineError, error);
      }
    }
  }
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { ProgressBar };
}
