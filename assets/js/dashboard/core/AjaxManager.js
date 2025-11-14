/**
 * AjaxManager - Gestión Centralizada de Peticiones AJAX
 * 
 * Wrapper centralizado para todas las peticiones AJAX del dashboard.
 * Proporciona una interfaz unificada para realizar peticiones AJAX
 * con manejo automático de nonce y configuración.
 * 
 * @module core/AjaxManager
 * @class AjaxManager
 * @namespace AjaxManager
 * @description Wrapper centralizado para todas las peticiones AJAX del dashboard
 * @since 1.0.0
 * @author Christian
 * @requires module:types
 * 
 * @example
 * // Uso básico
 * AjaxManager.call('mia_get_sync_progress', {}, 
 *   function(response) { console.log(response); },
 *   function(xhr, status, error) { console.error(error); }
 * );
 * 
 * // Con opciones adicionales
 * AjaxManager.call('mia_get_sync_progress', {}, 
 *   function(response) { console.log(response); },
 *   function(xhr, status, error) { console.error(error); },
 *   { timeout: 30000 }
 * );
 */

// @ts-check
/* global jQuery, miIntegracionApiDashboard */

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
 * Clase para gestión centralizada de peticiones AJAX
 * 
 * Esta clase proporciona un método estático para realizar peticiones AJAX
 * con configuración automática de nonce y manejo de errores.
 */
class AjaxManager {
  
  /**
   * Wrapper básico de jQuery.ajax
   * 
   * Realiza una petición AJAX con configuración automática de nonce y URL.
   * Valida que la configuración necesaria esté disponible antes de realizar la petición.
   * 
   * @static
   * @param {string} action - Acción a ejecutar (nombre del endpoint AJAX)
   * @param {Object} [data={}] - Datos adicionales a enviar en la petición
   * @param {Function} [success=null] - Callback de éxito (response)
   * @param {Function} [error=null] - Callback de error (xhr, status, error)
   * @param {Object} [options={}] - Opciones adicionales de jQuery.ajax (timeout, beforeSend, etc.)
   * @returns {any} Objeto jQuery jqXHR que implementa la interfaz Promise (jQuery.ajax devuelve jqXHR)
   * @example
   * // Petición simple
   * AjaxManager.call('mia_get_sync_progress', {}, 
   *   function(response) { 
   *     if (response.success) {
   *       console.log('Éxito:', response.data);
   *     }
   *   },
   *   function(xhr, status, error) { 
   *     console.error('Error:', error);
   *   }
   * );
   * 
   * // Petición con datos adicionales
   * AjaxManager.call('mi_integracion_api_save_batch_size', {
   *   entity: 'productos',
   *   batch_size: 50
   * }, successCallback, errorCallback);
   * 
   * // Petición con opciones adicionales
   * AjaxManager.call('mia_get_sync_progress', {}, 
   *   successCallback, errorCallback,
   *   { timeout: 60000, beforeSend: function() { console.log('Enviando...'); } }
   * );
   */
  static call(action, data = {}, success = null, error = null, options = {}) {
    // Verificar que ajaxurl y nonce estén disponibles
    // Usamos verificación moderna y segura (compatible con ES2017+)
    // Extraemos config para mejor legibilidad y evitar múltiples accesos
    const config = miIntegracionApiDashboard;
    if (!config || !config.ajaxurl || !config.nonce) {
      console.error('miIntegracionApiDashboard, ajaxurl o nonce no están disponibles');
      console.error('miIntegracionApiDashboard:', typeof miIntegracionApiDashboard);
      if (error) {
        error(null, 'error', 'Configuración AJAX incompleta - Variables no disponibles');
      }
      // Retornar una promesa jQuery rechazada para mantener el tipo de retorno
      // @type {any}
      return jQuery.Deferred().reject(null, 'error', 'Configuración AJAX incompleta - Variables no disponibles').promise();
    }
    
    // SIMPLE: Solo wrapper básico de jQuery.ajax
    // Usamos Object.assign para compatibilidad con ES2017 (ESLint 3.0.1)
    // Nota: Object spread ({...}) requiere ES2018+ y no es compatible con el parser actual
    const ajaxOptions = Object.assign({
      url: config.ajaxurl,
      type: 'POST',
      data: Object.assign({
        action,
        nonce: config.nonce
      }, data),
      success,
      error
    }, options);
    
    return jQuery.ajax(ajaxOptions);
  }
}

// ========================================
// EXPOSICIÓN GLOBAL
// ========================================

/**
 * Exponer AjaxManager globalmente para mantener compatibilidad
 * con el código existente que usa window.AjaxManager
 * 
 * NOTA: En el archivo original (dashboard.js línea 580) se expone como:
 * window.AjaxManager = class AjaxManager { ... }
 * 
 * Mantenemos la misma lógica simple para compatibilidad exacta.
 */
if (typeof window !== 'undefined') {
  window.AjaxManager = AjaxManager;
}

// Si usas ES6 modules, descomentar:
// export { AjaxManager };

// Si usas CommonJS, descomentar:
// module.exports = { AjaxManager };
