/**
 * Sanitizer - Utilidades de Sanitización de HTML
 * 
 * Proporciona funciones para sanitizar datos del servidor antes de insertarlos en el DOM.
 * Previene ataques XSS (Cross-Site Scripting) al sanitizar o escapar contenido HTML.
 * 
 * @module utils/Sanitizer
 * @class Sanitizer
 * @namespace Sanitizer
 * @description Utilidades de sanitización de HTML para prevenir XSS
 * @since 1.0.0
 * @author Christian
 * 
 * @example
 * // Escapar HTML (recomendado para texto plano)
 * const safeText = Sanitizer.escapeHtml(userInput);
 * jQuery('#element').text(safeText);
 * 
 * // Sanitizar HTML (si realmente necesitas HTML)
 * const safeHtml = Sanitizer.sanitizeHtml(serverHtml);
 * jQuery('#element').html(safeHtml);
 */

/* global window */

/**
 * Utilidades de sanitización de HTML
 */
const Sanitizer = {
  /**
   * Escapa caracteres HTML especiales para prevenir XSS
   * 
   * Convierte caracteres HTML especiales (<, >, &, ", ') en entidades HTML,
   * haciendo que el texto sea seguro para insertar en el DOM con .text().
   * 
   * @static
   * @param {string} text - Texto a escapar
   * @returns {string} Texto escapado seguro para usar con .text()
   * 
   * @example
   * const userInput = '<script>alert("XSS")</script>';
   * const safe = Sanitizer.escapeHtml(userInput);
   * // Resultado: "&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;"
   */
  escapeHtml(text) {
    if (typeof text !== 'string') {
      // Convertir a string si no lo es
      text = String(text);
    }

    // IMPORTANTE: Escapar '&' primero para evitar que se procese dentro de otras entidades
    // Luego escapar los demás caracteres peligrosos
    let escaped = text
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');

    // ✅ SEGURIDAD ADICIONAL: Neutralizar patrones peligrosos incluso después del escape
    // Esto previene que patrones como 'onerror=', 'onload=', 'javascript:' aparezcan en el texto
    // aunque estén escapados, proporcionando una capa adicional de seguridad
    
    // Neutralizar atributos de eventos HTML (case-insensitive)
    const eventAttributes = [
      'onerror', 'onload', 'onclick', 'onfocus', 'onblur', 'onchange',
      'ondblclick', 'onkeydown', 'onkeypress', 'onkeyup', 'onmousedown',
      'onmouseup', 'onmouseover', 'onmouseout', 'onmousemove', 'onsubmit',
      'onreset', 'onselect', 'onunload', 'onabort', 'onresize',
      'onscroll', 'oncontextmenu', 'ondrag', 'ondragend', 'ondragenter',
      'ondragleave', 'ondragover', 'ondragstart', 'ondrop'
    ];
    
    eventAttributes.forEach(function(attr) {
      // Escapar el signo igual después del atributo para neutralizarlo
      // Buscar el patrón con el signo igual escapado o sin escapar
      const pattern = new RegExp(attr + '\\s*=', 'gi');
      escaped = escaped.replace(pattern, attr + '&#61;');
    });
    
    // Neutralizar protocolos peligrosos (javascript:, data:, vbscript:, etc.)
    const dangerousProtocols = ['javascript', 'data', 'vbscript'];
    dangerousProtocols.forEach(function(protocol) {
      // Escapar los dos puntos después del protocolo
      const pattern = new RegExp(protocol + '\\s*:', 'gi');
      escaped = escaped.replace(pattern, protocol + '&#58;');
    });

    return escaped;
  },

  /**
   * Sanitiza HTML permitiendo solo etiquetas seguras
   * 
   * Si DOMPurify está disponible, lo usa para sanitización robusta.
   * Si no está disponible, usa escapeHtml como fallback seguro.
   * 
   * ⚠️ ADVERTENCIA: Solo usar cuando realmente necesites renderizar HTML.
   * Para texto plano, siempre usar .text() con escapeHtml().
   * 
   * @static
   * @param {string} html - HTML a sanitizar
   * @param {Object} [options] - Opciones de sanitización
   * @param {boolean} [options.allowBasicFormatting=false] - Permitir etiquetas básicas (b, i, u, strong, em)
   * @returns {string} HTML sanitizado seguro para usar con .html()
   * 
   * @example
   * // Con DOMPurify disponible
   * const safeHtml = Sanitizer.sanitizeHtml('<b>Texto</b><script>alert("XSS")</script>');
   * 
   * // Sin DOMPurify (fallback seguro)
   * const safeHtml = Sanitizer.sanitizeHtml('<b>Texto</b>');
   * // Resultado: "&lt;b&gt;Texto&lt;/b&gt;" (todo escapado)
   */
  sanitizeHtml(html, options = {}) {
    if (typeof html !== 'string') {
      html = String(html);
    }

    // Si DOMPurify está disponible, usarlo para sanitización robusta
    if (typeof window !== 'undefined' && window.DOMPurify && typeof window.DOMPurify.sanitize === 'function') {
      try {
        const config = {
          ALLOWED_TAGS: [],
          ALLOWED_ATTR: []
        };

        // Si se permite formato básico, agregar etiquetas seguras
        if (options.allowBasicFormatting) {
          config.ALLOWED_TAGS = ['b', 'i', 'u', 'strong', 'em', 'span', 'br', 'p'];
          config.ALLOWED_ATTR = ['class'];
        }

        return window.DOMPurify.sanitize(html, config);
      } catch (error) {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn) {
          // eslint-disable-next-line no-console
          console.warn('Error al sanitizar HTML con DOMPurify, usando escapeHtml:', error);
        }
        // Fallback seguro: escapar todo
        return this.escapeHtml(html);
      }
    }

    // Fallback seguro: escapar todo el HTML si no hay DOMPurify
    // Esto es más seguro que permitir HTML sin sanitizar
    return this.escapeHtml(html);
  },

  /**
   * Sanitiza un mensaje de texto para mostrar en la UI
   * 
   * Método de conveniencia que escapa HTML y prepara el texto para usar con .text().
   * 
   * @static
   * @param {string} message - Mensaje a sanitizar
   * @returns {string} Mensaje sanitizado seguro para usar con .text()
   * 
   * @example
   * const serverMessage = response.data.message; // Puede contener HTML malicioso
   * const safeMessage = Sanitizer.sanitizeMessage(serverMessage);
   * jQuery('#feedback').text(safeMessage);
   */
  sanitizeMessage(message) {
    if (message === null || message === undefined) {
      return '';
    }
    return this.escapeHtml(String(message));
  }
};

/**
 * Exponer Sanitizer globalmente
 */
if (typeof window !== 'undefined') {
  window.Sanitizer = Sanitizer;
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { Sanitizer };
}

