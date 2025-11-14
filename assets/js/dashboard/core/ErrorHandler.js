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

/* global DASHBOARD_CONFIG, Sanitizer */

/**
 * Clase para manejo centralizado de errores
 * 
 * Esta clase proporciona métodos estáticos para:
 * - Logging de errores con contexto y timestamp
 * - Mostrar errores en la interfaz de usuario
 * - Manejar diferentes tipos de errores (conexión, cancelación, críticos)
 * - Fallback automático si no se encuentra el elemento de feedback
 * 
 * NOTA: La declaración de tipos TypeScript está en types.d.ts (declare class ErrorHandler).
 * Esta es la implementación real de la clase. Ambas declaraciones son necesarias:
 * - Esta clase: implementación real del código
 * - types.d.ts: información de tipos para TypeScript/IDE (no crea una variable real)
 */
// @ts-expect-error - TypeScript detecta duplicación con declare class en types.d.ts,
// pero esto es intencional: types.d.ts es solo para tipos, esta es la implementación real.
class ErrorHandler {
  // ✅ SEGURIDAD: Map para guardar referencias de timeouts/intervals y evitar memory leaks
  // Esto permite limpiar intervalos si el elemento se remueve externamente
  static _activeIntervals = new WeakMap();
  
  // ✅ OPTIMIZACIÓN: Cache del selector de feedback para evitar evaluaciones repetitivas
  static _cachedFeedbackSelector = null;
  
  // ✅ OPTIMIZACIÓN: Map de caracteres HTML escapados (constante para evitar recreación)
  static _HTML_ESCAPE_MAP = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    '\'': '&#039;'
  };
  
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
   * **Seguridad**: El mensaje se sanitiza usando `Sanitizer.sanitizeMessage()` antes de
   * insertarlo con `.text()`. Aunque `.text()` escapa automáticamente HTML básico,
   * `Sanitizer.sanitizeMessage()` proporciona protección adicional al neutralizar:
   * - Atributos de eventos HTML (onerror, onclick, etc.)
   * - Protocolos peligrosos (javascript:, data:, vbscript:)
   * 
   * Esta defensa en profundidad (Sanitizer + .text()) previene ataques XSS incluso
   * si una de las capas falla o es bypassed.
   * 
   * @static
   * @param {string} message - El mensaje de error
   * @param {string} [type='error'] - El tipo de error ('error' o 'warning')
   * @returns {void}
   * @example
   * ErrorHandler.showUIError('Error de conexión', 'error');
   * ErrorHandler.showUIError('Advertencia', 'warning');
   */
  
  /**
   * Sanitiza un mensaje para prevenir XSS
   * 
   * @private
   * @static
   * @param {string} message - El mensaje a sanitizar
   * @returns {string} El mensaje sanitizado
   */
  static _sanitizeMessage(message) {
    // ✅ SEGURIDAD: Sanitizar mensaje antes de insertarlo en el DOM
    // NOTA: Aunque textContent escapa automáticamente HTML básico, usamos Sanitizer.sanitizeMessage
    // porque proporciona protección adicional:
    // - Neutraliza atributos de eventos HTML (onerror, onclick, etc.) incluso si están escapados
    // - Neutraliza protocolos peligrosos (javascript:, data:, vbscript:)
    // - Proporciona una capa de defensa en profundidad contra XSS
    // Si Sanitizer no está disponible, usamos escape básico como fallback.
    let sanitizer = null;
    try {
      // eslint-disable-next-line no-undef
      if (typeof Sanitizer !== 'undefined' && typeof Sanitizer.sanitizeMessage === 'function') {
        // eslint-disable-next-line no-undef
        sanitizer = Sanitizer;
      }
    } catch (e) {
      // Si Sanitizer está definido como constante y no podemos accederlo, usar window.Sanitizer
    }
    if (!sanitizer && typeof window !== 'undefined' && window.Sanitizer && typeof window.Sanitizer.sanitizeMessage === 'function') {
      sanitizer = window.Sanitizer;
    }
    if (sanitizer) {
      return sanitizer.sanitizeMessage(message);
    } else {
      // Fallback: escape básico de HTML (menos seguro que Sanitizer pero mejor que nada)
      // ✅ OPTIMIZACIÓN: Usar constante estática en lugar de recrear el map en cada llamada
      return String(message).replace(/[&<>"']/g, function(m) {
        return ErrorHandler._HTML_ESCAPE_MAP[m];
      });
    }
  }

  /**
   * Crea un elemento DOM para mostrar el error
   * 
   * @private
   * @static
   * @param {string} sanitizedMessage - El mensaje ya sanitizado
   * @param {string} type - Tipo de error ('error' o 'warning')
   * @returns {HTMLElement} El elemento div creado
   */
  static _createErrorElement(sanitizedMessage, type) {
    const errorClass = type === 'error' ? 'mi-api-error' : 'mi-api-warning';
    const icon = type === 'error' ? '❌' : '⚠️';
    
    // ✅ JavaScript puro: construir estructura HTML de forma segura
    const errorDiv = document.createElement('div');
    errorDiv.className = errorClass;
    
    const strongElement = document.createElement('strong');
    strongElement.textContent = `${icon}: `;
    
    const spanElement = document.createElement('span');
    // ✅ SEGURIDAD: Usar textContent en lugar de innerHTML para prevenir XSS
    // textContent escapa automáticamente HTML, proporcionando una segunda capa de protección
    // La combinación de Sanitizer + textContent ofrece defensa en profundidad
    spanElement.textContent = sanitizedMessage;
    
    errorDiv.appendChild(strongElement);
    errorDiv.appendChild(spanElement);
    
    return errorDiv;
  }

  /**
   * Obtiene el selector de feedback desde DASHBOARD_CONFIG
   * 
   * @private
   * @static
   * @returns {string} El selector CSS del elemento de feedback
   */
  static _getFeedbackSelector() {
    // ✅ OPTIMIZACIÓN: Cachear el selector para evitar evaluaciones repetitivas
    // Esto mejora el rendimiento cuando showUIError se llama múltiples veces
    if (this._cachedFeedbackSelector !== null) {
      return this._cachedFeedbackSelector;
    }
    
    const selector = DASHBOARD_CONFIG && DASHBOARD_CONFIG.selectors && DASHBOARD_CONFIG.selectors.feedback
      ? DASHBOARD_CONFIG.selectors.feedback
      : '#mi-sync-feedback';
    
    this._cachedFeedbackSelector = selector;
    return selector;
  }

  /**
   * Invalida el cache del selector de feedback
   * 
   * Útil si DASHBOARD_CONFIG cambia dinámicamente (raro en la práctica).
   * 
   * @private
   * @static
   * @returns {void}
   */
  static _invalidateFeedbackSelectorCache() {
    this._cachedFeedbackSelector = null;
  }

  /**
   * Muestra el error en un elemento de feedback existente
   * 
   * @private
   * @static
   * @param {Element} feedbackElement - El elemento de feedback existente
   * @param {string} sanitizedMessage - El mensaje ya sanitizado
   * @param {string} type - Tipo de error ('error' o 'warning')
   * @returns {void}
   */
  static _showErrorInFeedback(feedbackElement, sanitizedMessage, type) {
    // ✅ JavaScript puro: remover clase y actualizar contenido
    feedbackElement.classList.remove('in-progress');
    
    // ✅ REFACTORIZADO: Usar función helper para crear elemento de error (eliminada duplicación)
    const errorDiv = this._createErrorElement(sanitizedMessage, type);
    
    // ✅ JavaScript puro: reemplazar contenido del elemento
    feedbackElement.innerHTML = '';
    feedbackElement.appendChild(errorDiv);
  }

  /**
   * Maneja el caso cuando no existe el elemento de feedback (fallback)
   * 
   * @private
   * @static
   * @param {string} message - El mensaje de error original
   * @param {string} sanitizedMessage - El mensaje ya sanitizado
   * @param {string} type - Tipo de error ('error' o 'warning')
   * @returns {void}
   */
  static _handleFeedbackFallback(message, sanitizedMessage, type) {
    // Fallback: mostrar en consola y crear elemento temporal
    console.error('No se encontró elemento de feedback, usando fallback');
    console.error(`Error: ${message}`);
  
    // ✅ OPTIMIZADO: Usar función helper para crear elemento temporal (eliminada duplicación de estilos)
    const tempFeedback = this._createTempFeedbackElement(type);
  
    // ✅ REFACTORIZADO: Usar función helper para crear elemento de error (eliminada duplicación)
    const errorDiv = this._createErrorElement(sanitizedMessage, type);
    tempFeedback.appendChild(errorDiv);
    document.body.appendChild(tempFeedback);
  
    // ✅ REFACTORIZADO: Usar función helper para auto-ocultado (eliminada duplicación)
    this._setupAutoHide(tempFeedback);
  }

  /**
   * Crea un elemento temporal de feedback con estilos aplicados
   * 
   * @private
   * @static
   * @param {string} type - Tipo de error ('error' o 'warning')
   * @returns {HTMLElement} El elemento temporal creado
   */
  static _createTempFeedbackElement(type) {
    const tempFeedback = document.createElement('div');
    tempFeedback.id = 'mi-sync-feedback';
    tempFeedback.className = 'mi-api-feedback';
    
    // ✅ JavaScript puro: aplicar estilos usando style
    Object.assign(tempFeedback.style, {
      position: 'fixed',
      top: '20px',
      right: '20px',
      zIndex: '9999',
      padding: '10px',
      backgroundColor: type === 'error' ? '#f8d7da' : '#fff3cd',
      border: `1px solid ${type === 'error' ? '#f5c6cb' : '#ffeaa7'}`,
      borderRadius: '4px',
      maxWidth: '300px'
    });
    
    return tempFeedback;
  }

  /**
   * Configura el auto-ocultado de un elemento después de 5 segundos
   * 
   * @private
   * @static
   * @param {HTMLElement} element - El elemento a ocultar
   * @returns {void}
   */
  static _setupAutoHide(element) {
    // ✅ JavaScript puro: auto-ocultar después de 5 segundos con fadeOut manual
    // ✅ SEGURIDAD: Guardar referencia del timeout para poder cancelarlo si es necesario
    const hideTimeout = setTimeout(() => {
      // ✅ SEGURIDAD: Verificar que el elemento aún existe antes de iniciar el fade
      if (!element || !element.parentNode) {
        // El elemento ya fue removido, no hacer nada
        return;
      }

      let opacity = 1;
      let iterations = 0;
      const maxIterations = 20; // 20 * 50ms = 1 segundo máximo (timeout de seguridad)
      
      const fadeInterval = setInterval(() => {
        // ✅ SEGURIDAD: Verificar que el elemento aún existe en cada iteración
        if (!element || !element.parentNode) {
          clearInterval(fadeInterval);
          return;
        }

        // ✅ SEGURIDAD: Timeout de seguridad para evitar intervalos infinitos
        iterations++;
        if (iterations >= maxIterations) {
          clearInterval(fadeInterval);
          // Forzar remoción si el timeout se alcanza
          if (element.parentNode) {
            element.parentNode.removeChild(element);
          }
          return;
        }

        opacity -= 0.05;
        element.style.opacity = String(opacity);
        
        if (opacity <= 0) {
          clearInterval(fadeInterval);
          // ✅ SEGURIDAD: Verificar que el elemento aún tiene parentNode antes de remover
          if (element.parentNode) {
            element.parentNode.removeChild(element);
          }
        }
      }, 50);

      // ✅ SEGURIDAD: Guardar referencia del interval usando WeakMap para evitar memory leaks
      // Esto permite limpiar el interval si el elemento se remueve externamente
      if (element) {
        ErrorHandler._activeIntervals.set(element, {
          fadeInterval,
          hideTimeout
        });
      }
    }, 5000);

    // ✅ SEGURIDAD: Guardar referencia del timeout usando WeakMap
    if (element) {
      const existing = ErrorHandler._activeIntervals.get(element) || {};
      existing.hideTimeout = hideTimeout;
      ErrorHandler._activeIntervals.set(element, existing);
    }
  }

  static showUIError(message, type = 'error') {
    // ✅ OPTIMIZACIÓN: Obtener selector cacheado (evita evaluaciones repetitivas)
    const feedbackSelector = this._getFeedbackSelector();
    const feedbackElement = document.querySelector(feedbackSelector);
  
    // ✅ REFACTORIZADO: Sanitizar mensaje una sola vez (eliminada duplicación)
    const sanitizedMessage = this._sanitizeMessage(message);
  
    // ✅ OPTIMIZADO: Reducir complejidad ciclomática extrayendo lógica a métodos privados
    if (!feedbackElement) {
      this._handleFeedbackFallback(message, sanitizedMessage, type);
      return;
    }
  
    // ✅ OPTIMIZADO: Extraer lógica de elemento existente para reducir complejidad
    this._showErrorInFeedback(feedbackElement, sanitizedMessage, type);
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
   * fetch('...')
   *   .catch(error => {
   *     ErrorHandler.showConnectionError({ status: 0 });
   *   });
   * // O con XMLHttpRequest:
   * const xhr = new XMLHttpRequest();
   * xhr.onerror = function() {
   *   ErrorHandler.showConnectionError(xhr);
   * };
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
 * ✅ SEGURIDAD: Solo se expone si no existe ya para evitar duplicados
 * cuando el script se carga múltiples veces o en entornos de tests.
 */
if (typeof window !== 'undefined') {
  // ✅ SEGURIDAD: Verificar si ya existe antes de sobrescribir
  if (typeof window.ErrorHandler === 'undefined') {
    try {
      window.ErrorHandler = ErrorHandler;
      // ✅ DEBUG: Confirmar que se expuso correctamente (solo en desarrollo/tests)
      if (typeof console !== 'undefined' && console.log) {
        /* eslint-disable-next-line no-console */
        console.log('[ErrorHandler] ✅ ErrorHandler expuesto correctamente en window');
      }
    } catch (error) {
    // ✅ DEBUG: Registrar error si falla la exposición (solo en desarrollo)
      if (typeof console !== 'undefined' && console.error) {
        // eslint-disable-next-line no-console
        console.error('[ErrorHandler] Error al exponer ErrorHandler:', error);
      }
    }
  } else {
  // ✅ DEBUG: ErrorHandler ya existe, usar la versión previa (solo en desarrollo/tests)
    if (typeof console !== 'undefined' && console.warn) {
    /* eslint-disable-next-line no-console */
      console.warn('[ErrorHandler] Ya existe, usando la versión previa. No se sobrescribirá.');
    }
  }
}

// Si usas ES6 modules, descomentar:
// export { ErrorHandler };

// Si usas CommonJS, descomentar:
// module.exports = { ErrorHandler };
