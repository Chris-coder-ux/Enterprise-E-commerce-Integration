/**
 * Mensajes del Sistema del Dashboard
 * 
 * Centraliza todos los mensajes del sistema organizados por categorías.
 * Este módulo puede ser usado independientemente o como parte de DASHBOARD_CONFIG.
 * 
 * @module config/messages
 * @namespace MESSAGES
 * @since 1.0.0
 * @author Christian
 */

const MESSAGES = {
  /**
   * Mensajes de error del sistema
   * 
   * @type {Object}
   * @property {string} jqueryMissing - Error cuando jQuery no está disponible
   * @property {string} configMissing - Error de configuración incompleta
   * @property {string} ajaxUrlMissing - Error cuando ajaxurl no está definido
   * @property {string} connectionError - Error de conexión
   * @property {string} permissionError - Error de permisos (403)
   * @property {string} serverError - Error del servidor (500)
   * @property {string} timeoutError - Error de timeout
   * @property {string} unknownError - Error desconocido
   */
  errors: {
    jqueryMissing: 'jQuery no está disponible. El dashboard no funcionará.',
    configMissing: 'Variables de configuración incompletas. La sincronización fallará.',
    ajaxUrlMissing: 'Variable ajaxurl no está definida. La sincronización AJAX fallará.',
    connectionError: 'Error de conexión. Verifique su conexión a internet.',
    permissionError: 'Error de permisos (403). Por favor, recarga la página o inicia sesión nuevamente.',
    serverError: 'Error del servidor (500). Contacte al administrador.',
    timeoutError: 'Tiempo de espera agotado. La operación tardó demasiado.',
    unknownError: 'Error desconocido. Verifique la consola para más detalles.'
  },

  /**
   * Mensajes de progreso de sincronización
   * 
   * @type {Object}
   * @property {string} preparing - Mensaje de preparación
   * @property {string} verifying - Mensaje de verificación
   * @property {string} connecting - Mensaje de conexión
   * @property {string} processing - Mensaje de procesamiento
   * @property {string} complete - Mensaje de completado
   * @property {string} productsProcessed - Mensaje para productos procesados
   * @property {string} productsSynced - Mensaje para productos sincronizados
   * @property {string} productsPerSec - Mensaje para velocidad de procesamiento
   */
  progress: {
    preparing: 'Preparando sincronización... ',
    verifying: 'Verificando estado del servidor...',
    connecting: 'Conectando con el servidor...',
    processing: 'Procesando datos...',
    complete: 'Sincronización completada exitosamente',
    productsProcessed: 'productos procesados',
    productsSynced: 'productos sincronizados',
    productsPerSec: 'productos/seg'
  },

  /**
   * Mensajes de hitos de progreso
   * 
   * @type {Object}
   * @property {string} start - Mensaje de inicio
   * @property {string} quarter - Mensaje de 25% completado
   * @property {string} half - Mensaje de 50% completado
   * @property {string} threeQuarters - Mensaje de 75% completado
   * @property {string} complete - Mensaje de completado
   */
  milestones: {
    start: 'Iniciando sincronización...',
    quarter: '25% completado',
    half: '50% completado',
    threeQuarters: '75% completado',
    complete: '¡Sincronización completada!'
  },

  /**
   * Mensajes de estado del sistema
   * 
   * @type {Object}
   * @property {string} pending - Estado pendiente
   * @property {string} running - Estado en progreso
   * @property {string} completed - Estado completado
   * @property {string} error - Estado de error
   * @property {string} paused - Estado pausado
   */
  status: {
    pending: 'Pendiente',
    running: 'En Progreso',
    completed: 'Completado',
    error: 'Error',
    paused: 'Pausado'
  },

  /**
   * Mensajes de éxito del sistema
   * 
   * @type {Object}
   * @property {string} batchSizeChanged - Mensaje cuando se cambia el tamaño de lote
   */
  success: {
    batchSizeChanged: 'Tamaño de lote cambiado a {size} productos'
  },

  /**
   * Consejos y tips para el usuario
   * 
   * @type {Object}
   * @property {string} keyboardShortcut - Atajo de teclado
   * @property {string} generalTip - Tip general
   */
  tips: {
    keyboardShortcut: 'Atajo de teclado: Ctrl+Enter para sincronizar',
    generalTip: '💡 Tip: Usa Ctrl+Enter para iniciar sincronización rápida'
  }
};

/**
 * Exponer MESSAGES globalmente para mantener compatibilidad
 * con el código existente que usa window.MESSAGES
 */
if (typeof window !== 'undefined') {
  try {
    window.MESSAGES = MESSAGES;
  } catch (error) {
    void error;
    try {
      Object.defineProperty(window, 'MESSAGES', {
        value: MESSAGES,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      void defineError;
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar MESSAGES a window:', defineError);
      }
    }
  }
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { MESSAGES };
}
