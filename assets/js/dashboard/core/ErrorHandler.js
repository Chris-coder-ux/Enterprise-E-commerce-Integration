/**
 * ErrorHandler - Manejo Centralizado de Errores
 * 
 * Gestiona todos los errores del dashboard de forma centralizada.
 * Proporciona logging y notificaciones UI consistentes.
 * 
 * @module core/ErrorHandler
 * @class ErrorHandler
 * @namespace ErrorHandler
 * @description Gestión centralizada de errores y notificaciones UI
 * @since 1.0.0
 * @author Christian
 * 
 * @example
 * // Uso básico
 * ErrorHandler.logError('Error message', 'CONTEXT');
 * ErrorHandler.showUIError('Error message', 'error');
 * 
 * // Manejo de errores de conexión
 * ErrorHandler.showConnectionError(xhr);
 * 
 * // Errores específicos
 * ErrorHandler.showCancelError('Error al cancelar');
 * ErrorHandler.showCriticalError('Error crítico del sistema');
 */

/* global jQuery, DASHBOARD_CONFIG, globalThis */

/**
 * Clase para manejo centralizado de errores
 * 
 * Esta clase proporciona métodos estáticos para:
 * - Logging de errores con contexto y timestamp
 * - Mostrar errores en la interfaz de usuario
 * - Manejar diferentes tipos de errores (conexión, cancelación, críticos)
 * - Fallback automático si no se encuentra el elemento de feedback
 */
class ErrorHandler {
  
  /**
   * Logging básico para debugging
   * 
   * Registra errores en la consola con timestamp y contexto opcional.
   * Útil para debugging y seguimiento de errores.
   * 
   * @static
   * @param {string} message - El mensaje de error
   * @param {string|null} [context=null] - El contexto del error (opcional)
   * @returns {void}
   * @example
   * ErrorHandler.logError('Error de conexión', 'AJAX');
   * // Output: [2024-01-15T10:30:00.000Z] [AJAX] Error de conexión
   */
  static logError(message, context = null) {
    const timestamp = new Date().toISOString();
    const contextStr = context ? ` [${context}]` : '';
    console.error(`[${timestamp}]${contextStr} ${message}`);
  }
  
  /**
   * Muestra un error en la interfaz de usuario
   * 
   * Muestra un mensaje de error o advertencia en el elemento de feedback
   * del dashboard. Si el elemento no existe, crea uno temporal con fallback.
   * 
   * @static
   * @param {string} message - El mensaje de error
   * @param {string} [type='error'] - El tipo de error ('error' o 'warning')
   * @returns {void}
   * @example
   * ErrorHandler.showUIError('Error de conexión', 'error');
   * ErrorHandler.showUIError('Advertencia', 'warning');
   */
  static showUIError(message, type = 'error') {
    const $feedback = jQuery(DASHBOARD_CONFIG.selectors.feedback);
    
    if (!$feedback.length) {
      // Fallback: mostrar en consola y crear elemento temporal
      console.error('No se encontró elemento de feedback, usando fallback');
      console.error(`Error: ${message}`);
      
      // Crear elemento temporal si no existe
      const $tempFeedback = jQuery('<div id="mi-sync-feedback" class="mi-api-feedback"></div>')
        .appendTo('body')
        .css({
          position: 'fixed',
          top: '20px',
          right: '20px',
          zIndex: 9999,
          padding: '10px',
          backgroundColor: type === 'error' ? '#f8d7da' : '#fff3cd',
          border: `1px solid ${type === 'error' ? '#f5c6cb' : '#ffeaa7'}`,
          borderRadius: '4px',
          maxWidth: '300px'
        });
      
      const errorClass = type === 'error' ? 'mi-api-error' : 'mi-api-warning';
      const icon = type === 'error' ? '❌' : '⚠️';
      
      $tempFeedback.html(`<div class="${errorClass}"><strong>${icon}:</strong> ${message}</div>`);
      
      // Auto-ocultar después de 5 segundos
      setTimeout(() => $tempFeedback.fadeOut(500, () => $tempFeedback.remove()), 5000);
      return;
    }
    
    const errorClass = type === 'error' ? 'mi-api-error' : 'mi-api-warning';
    const icon = type === 'error' ? '❌' : '⚠️';
    
    $feedback.removeClass('in-progress').html(
      `<div class="${errorClass}"><strong>${icon}:</strong> ${message}</div>`
    );
  }
  
  /**
   * Muestra un error de conexión básico
   * 
   * Analiza el objeto XMLHttpRequest y muestra un mensaje de error
   * apropiado según el código de estado HTTP.
   * 
   * @static
   * @param {Object} xhr - El objeto XMLHttpRequest
   * @returns {void}
   * @example
   * jQuery.ajax({
   *   url: '...',
   *   error: function(xhr, status, error) {
   *     ErrorHandler.showConnectionError(xhr);
   *   }
   * });
   */
  static showConnectionError(xhr) {
    let status = 'Error';
    let message;
    
    if (xhr) {
      if (xhr.status) {
        status = xhr.status;
      } else if (xhr.statusText) {
        status = xhr.statusText;
      }
      
      // Manejar casos específicos
      if (xhr.status === 0) {
        message = 'Error de conexión: No se pudo conectar al servidor';
      } else if (xhr.status === 403) {
        message = 'Error de permisos: Acceso denegado';
      } else if (xhr.status === 404) {
        message = 'Error: Recurso no encontrado';
      } else if (xhr.status === 500) {
        message = 'Error del servidor: Problema interno';
      } else {
        message = `Error de conexión (${status})`;
      }
    } else {
      message = 'Error de conexión: No se pudo establecer comunicación';
    }
    
    this.showUIError(message);
  }
  
  /**
   * Muestra un error de protección
   * 
   * Registra errores de protección (como clicks automáticos detectados)
   * sin mostrar en la UI para evitar spam de notificaciones.
   * 
   * @static
   * @param {string} reason - La razón de la protección
   * @returns {void}
   * @example
   * ErrorHandler.showProtectionError('Click automático detectado');
   */
  static showProtectionError(reason) {
    this.logError(`Protección activada: ${reason}`);
    // No mostrar en UI para evitar spam
  }
  
  /**
   * Muestra un error de cancelación
   * 
   * Registra y muestra un error relacionado con la cancelación
   * de operaciones (como cancelar una sincronización).
   * 
   * @static
   * @param {string} message - El mensaje de error de cancelación
   * @param {string} [context='CANCEL'] - El contexto de la cancelación (opcional)
   * @returns {void}
   * @example
   * ErrorHandler.showCancelError('Error al cancelar sincronización');
   */
  static showCancelError(message, context = 'CANCEL') {
    this.logError(`Error de cancelación: ${message}`, context);
    this.showUIError(`Error al cancelar: ${message}`, 'error');
  }
  
  /**
   * Muestra un error crítico
   * 
   * Registra y muestra errores críticos del sistema que requieren
   * atención inmediata.
   * 
   * @static
   * @param {string} message - El mensaje de error
   * @param {string} [context='CRITICAL'] - El contexto del error crítico (opcional)
   * @returns {void}
   * @example
   * ErrorHandler.showCriticalError('Error crítico del sistema');
   */
  static showCriticalError(message, context = 'CRITICAL') {
    this.logError(`Error crítico: ${message}`, context);
    this.showUIError(`Error crítico: ${message}`, 'error');
  }
}

// ========================================
// EXPOSICIÓN GLOBAL
// ========================================

/**
 * Exponer ErrorHandler globalmente para mantener compatibilidad
 * con el código existente que usa window.ErrorHandler
 * 
 * NOTA: En el archivo original (dashboard.js línea 630) se expone simplemente como:
 * window.ErrorHandler = ErrorHandler;
 * 
 * Mantenemos la misma lógica simple para compatibilidad exacta.
 */
if (typeof window !== 'undefined') {
  window.ErrorHandler = ErrorHandler;
}

// Si usas ES6 modules, descomentar:
// export { ErrorHandler };

// Si usas CommonJS, descomentar:
// module.exports = { ErrorHandler };
