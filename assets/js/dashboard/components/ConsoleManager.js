/**
 * Gestor de Consola de Sincronización
 * 
 * Gestiona el terminal de consola que muestra el progreso y los logs
 * del proceso de sincronización en tiempo real.
 * 
 * @module components/ConsoleManager
 * @namespace ConsoleManager
 * @since 1.0.0
 * @author Christian
 * @requires module:types
 * 
 * @example
 * // Uso con ES Modules
 * import { ConsoleManager } from './ConsoleManager.js';
 * ConsoleManager.initialize();
 * 
 * @example
 * // Uso con CommonJS
 * const { ConsoleManager } = require('./ConsoleManager.js');
 * ConsoleManager.initialize();
 * 
 * @example
 * // Uso global (después de cargar el script)
 * window.ConsoleManager.initialize();
 */

// @ts-check
/// <reference path="../types.d.ts" />

// ✅ CRÍTICO: Envolver todo el código en un IIFE para evitar redeclaraciones
// si el script se carga múltiples veces
// ✅ MEJORADO: Verificar que estamos en un entorno con DOM (navegador)
(function() {
  'use strict';
  
  // ✅ VERIFICACIÓN DE ENTORNO: Verificar que estamos en un entorno con DOM
  // Si no estamos en un entorno con DOM (ej: Node.js), salir temprano
  if (typeof window === 'undefined' && typeof document === 'undefined') {
    // En entornos sin DOM, solo exportar el módulo sin inicializar
    // Esto permite usar el módulo en Node.js para testing
    /* global module */
    if (typeof module !== 'undefined' && module.exports) {
      // Exportar un objeto vacío para testing
      module.exports = { ConsoleManager: {} };
    }
    return;
  }
  
  // ✅ Verificar si ya se ejecutó este script para evitar redeclaraciones
  // @ts-ignore - Propiedad personalizada de Window para tracking de carga
  if (window?.__ConsoleManagerLoaded) {
    return;
  }
  
  // Marcar que el script se está cargando
  if (window) {
    // @ts-ignore - Propiedad personalizada de Window para tracking de carga
    window.__ConsoleManagerLoaded = true;
  }
  
  /**
   * ✅ MÓDULO PRIVADO: Encapsula todo el estado interno y constantes
   * 
   * Este objeto es completamente privado y no es accesible desde fuera del IIFE.
   * Proporciona encapsulamiento completo del estado interno del módulo.
   * 
   * @private
   * @type {Object}
   */
  const _private = {
    /**
     * Selectores CSS para los elementos de la consola
     * 
     * @type {Object<string, string>}
     */
    SELECTORS: {
      console: '#mia-sync-console',
      consoleContent: '#mia-console-content',
      consoleBody: '.mia-console-body',
      phase1Indicator: '#mia-phase1-indicator',
      phase2Indicator: '#mia-phase2-indicator',
      clearButton: '#mia-console-clear',
      toggleButton: '#mia-console-toggle'
    },
    
    /**
     * Mapeo de tipos de mensaje a etiquetas
     * 
     * @type {Object<string, string>}
     */
    LABEL_MAP: {
      info: '[INFO]',
      success: '[SUCCESS]',
      warning: '[WARNING]',
      error: '[ERROR]',
      phase1: '[FASE 1]',
      phase2: '[FASE 2]'
    },
    
    /**
     * Límite máximo de líneas en la consola para evitar problemas de rendimiento
     * 
     * @type {number}
     */
    MAX_LINES: 100,
    
    /**
     * Estado de inicialización para evitar suscripciones duplicadas
     * 
     * @type {Object}
     */
    initializeState: {
      hasSubscribedToEvents: false
    },
    
    /**
     * Resetea el estado de inicialización
     * 
     * @returns {void}
     * @private
     */
    resetInitializeState() {
      this.initializeState.hasSubscribedToEvents = false;
    }
  };
  
  /**
   * ✅ Estado para optimización de scroll con requestAnimationFrame
   * 
   * Evita múltiples scrolls innecesarios cuando se llama frecuentemente.
   * 
   * @private
   * @type {Object}
   */
  const scrollState = {
    rafId: null, // ID del requestAnimationFrame pendiente
    isScrolling: false // Flag para evitar múltiples scrolls simultáneos
  };
  
  /**
   * ✅ TrackingState: Objeto con métodos para manejar el estado de tracking
   * 
   * Encapsula el estado de tracking y proporciona métodos para:
   * - Resetear el estado a valores iniciales
   * - Actualizar valores específicos
   * - Obtener valores
   * - Verificar cambios
   * 
   * @private
   * @type {Object}
   */
  const TrackingState = {
    // Propiedades del estado
    lastProductId: 0,
    lastProductsProcessed: 0,
    lastImagesProcessed: 0,
    lastSummaryProducts: 0,
    wasPaused: false,
    wasCancelled: false,
    wasInProgress: false,
    lastCheckpointSavedId: 0,
    initialCacheClearedShown: false,
    checkpointLoadedShown: false,
    technicalInfoShown: false,
    
    /**
     * Resetea todos los valores del estado a sus valores iniciales
     * 
     * @returns {void}
     */
    reset() {
      this.lastProductId = 0;
      this.lastProductsProcessed = 0;
      this.lastImagesProcessed = 0;
      this.lastSummaryProducts = 0;
      this.lastCheckpointSavedId = 0;
      this.initialCacheClearedShown = false;
      this.checkpointLoadedShown = false;
      this.technicalInfoShown = false;
      this.wasPaused = false;
      this.wasCancelled = false;
      this.wasInProgress = false;
    },
    
    /**
     * Actualiza un valor específico del estado
     * 
     * @param {string} key - Clave de la propiedad a actualizar
     * @param {*} value - Nuevo valor
     * @returns {void}
     */
    update(key, value) {
      if (key in this && typeof this[key] !== 'function') {
        this[key] = value;
      }
    },
    
    /**
     * Obtiene el valor de una propiedad del estado
     * 
     * @param {string} key - Clave de la propiedad
     * @returns {*} Valor de la propiedad o undefined si no existe
     */
    get(key) {
      return (key in this) && typeof this[key] !== 'function' ? this[key] : undefined;
    },
    
    /**
     * Verifica si un valor ha cambiado respecto al estado actual
     * 
     * @param {string} key - Clave de la propiedad a verificar
     * @param {*} newValue - Nuevo valor a comparar
     * @returns {boolean} true si el valor ha cambiado, false en caso contrario
     */
    hasChanged(key, newValue) {
      return this.get(key) !== newValue;
    },
    
    /**
     * Actualiza múltiples valores del estado a la vez
     * 
     * @param {Object} updates - Objeto con las actualizaciones { key: value, ... }
     * @returns {void}
     */
    updateMultiple(updates) {
      if (updates && typeof updates === 'object') {
        Object.keys(updates).forEach(key => {
          this.update(key, updates[key]);
        });
      }
    }
  };
  
  /**
   * Verifica si jQuery está disponible en el entorno
   * 
   * @returns {boolean} true si jQuery está disponible, false en caso contrario
   * @private
   */
  function isJQueryAvailable() {
    return typeof window !== 'undefined' && typeof window.jQuery !== 'undefined';
  }
  
  /**
   * Obtiene jQuery de forma segura
   * 
   * @returns {jQuery|undefined} Instancia de jQuery si está disponible, undefined en caso contrario
   * @private
   */
  function getJQuery() {
    if (isJQueryAvailable()) {
      return window.jQuery;
    }
    return undefined;
  }
  
  /**
   * ✅ HELPER: Verifica si estamos en modo de desarrollo
   * 
   * Comprueba múltiples formas de determinar si estamos en desarrollo:
   * - process.env.NODE_ENV !== 'production' (Node.js)
   * - window.DEBUG o window.DEV_MODE (navegador)
   * - localStorage.debug (navegador)
   * 
   * @returns {boolean} true si estamos en modo desarrollo
   * @private
   */
  function isDevelopmentMode() {
    // Verificar process.env (Node.js)
    if (typeof process !== 'undefined' && process.env && process.env.NODE_ENV) {
      return process.env.NODE_ENV !== 'production';
    }
    
    // Verificar window.DEBUG o window.DEV_MODE (navegador)
    if (typeof window !== 'undefined') {
      if (window.DEBUG === true || window.DEV_MODE === true) {
        return true;
      }
      
      // Verificar localStorage.debug
      try {
        if (typeof localStorage !== 'undefined' && localStorage.getItem('debug') === 'true') {
          return true;
        }
      } catch (e) {
        // localStorage puede no estar disponible en algunos contextos
      }
    }
    
    // Por defecto, asumir producción (más seguro)
    return false;
  }
  
  /**
   * ✅ HELPER: Log de debug que solo se ejecuta en modo desarrollo
   * 
   * @param {string} level - Nivel de log ('log', 'warn', 'error')
   * @param {...*} args - Argumentos a loggear
   * @private
   */
  function debugLog(level, ...args) {
    if (!isDevelopmentMode()) {
      return;
    }
    
    if (typeof console === 'undefined') {
      return;
    }
    
    // eslint-disable-next-line no-console
    const method = console[level];
    if (typeof method === 'function') {
      // eslint-disable-next-line no-console
      method.apply(console, args);
    }
  }
  
  /**
   * ✅ HELPER: Ejecuta una función de forma segura con manejo automático de errores
   * 
   * Envuelve llamadas críticas en try/catch y reporta errores automáticamente
   * a ErrorHandler si está disponible.
   * 
   * @param {Function} fn - Función a ejecutar
   * @param {string} context - Contexto del error para logging
   * @param {*} [defaultReturn] - Valor por defecto a retornar si hay error
   * @returns {*} Resultado de la función o defaultReturn si hay error
   * @private
   */
  function safeExecute(fn, context, defaultReturn) {
    try {
      return fn();
    } catch (error) {
      // Reportar error automáticamente a ErrorHandler si está disponible
      if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
        const errorMessage = error instanceof Error ? error.message : String(error);
        window.ErrorHandler.logError(
          `Error en ${context}: ${errorMessage}`,
          context || 'CONSOLE_MANAGER'
        );
      } else {
        // Fallback: loggear en consola si ErrorHandler no está disponible
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.error) {
          // eslint-disable-next-line no-console
          console.error(`[ConsoleManager] Error en ${context}:`, error);
        }
      }
      
      // Retornar valor por defecto si se proporciona
      return defaultReturn;
    }
  }
  
  /**
   * ✅ HELPER: Sanitiza un mensaje para mostrar en la consola con cadena de fallbacks
   * 
   * Implementa una cadena de fallbacks para máxima seguridad:
   * 1. Sanitizer.sanitizeMessage (preferido, específico para mensajes)
   * 2. DOMPurify.sanitize (si está disponible, sanitización robusta)
   * 3. Escape HTML básico (fallback final, siempre disponible)
   * 
   * @param {string|null|undefined} message - Mensaje a sanitizar
   * @returns {string} Mensaje sanitizado seguro para usar con .text()
   * @private
   */
  function sanitizeMessageForConsole(message) {
    // Validar entrada
    if (message === null || message === undefined) {
      return '';
    }
    
    const messageStr = String(message);
    
    // ✅ Método 1: Sanitizer.sanitizeMessage (preferido)
    if (typeof window !== 'undefined' && window.Sanitizer && typeof window.Sanitizer.sanitizeMessage === 'function') {
      try {
        return window.Sanitizer.sanitizeMessage(messageStr);
      } catch (error) {
        // Si falla, continuar con siguiente método
        if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
          window.ErrorHandler.logError(
            `Error al sanitizar con Sanitizer.sanitizeMessage: ${error.message || error}`,
            'CONSOLE_SANITIZATION'
          );
        }
      }
    }
    
    // ✅ Método 2: DOMPurify.sanitize (fallback robusto)
    // NOTA: DOMPurify es principalmente para HTML, pero podemos usarlo para escapar texto
    // Configurándolo para no permitir etiquetas, solo escapar
    if (typeof window !== 'undefined' && window.DOMPurify && typeof window.DOMPurify.sanitize === 'function') {
      try {
        // Configurar DOMPurify para no permitir etiquetas HTML (solo escapar)
        // @ts-ignore - DOMPurify puede tener opciones adicionales no tipadas
        const sanitized = window.DOMPurify.sanitize(messageStr, {
          ALLOWED_TAGS: [],
          ALLOWED_ATTR: []
        });
        // DOMPurify puede devolver el texto escapado, pero para estar seguros,
        // también aplicamos escape básico como capa adicional de seguridad
        return escapeHtmlBasic(sanitized);
      } catch (error) {
        // Si falla, continuar con siguiente método
        if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
          window.ErrorHandler.logError(
            `Error al sanitizar con DOMPurify: ${error.message || error}`,
            'CONSOLE_SANITIZATION'
          );
        }
      }
    }
    
    // ✅ Método 3: Escape HTML básico (fallback final, siempre disponible)
    return escapeHtmlBasic(messageStr);
  }
  
  /**
   * ✅ HELPER: Escapa caracteres HTML básicos de forma segura
   * 
   * Función de escape básica que siempre está disponible como último recurso.
   * Escapa los caracteres HTML peligrosos: &, <, >, ", '
   * 
   * @param {string} text - Texto a escapar
   * @returns {string} Texto escapado
   * @private
   */
  function escapeHtmlBasic(text) {
    if (typeof text !== 'string') {
      text = String(text);
    }
    
    // IMPORTANTE: Escapar '&' primero para evitar que se procese dentro de otras entidades
    return text
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  /**
   * Inicializar la consola
   * 
   * Configura los event listeners para los controles de la consola
   * (limpiar, minimizar/maximizar).
   * 
   * @returns {void}
   * 
   * @example
   * ConsoleManager.initialize();
   */
  function initialize() {
    // ✅ CRÍTICO: Envolver inicialización en safeExecute para manejo automático de errores
    safeExecute(function() {
      if (!isJQueryAvailable()) {
        // eslint-disable-next-line no-console
        console.error('ConsoleManager requiere jQuery');
        return;
      }

      const jQuery = getJQuery();
      if (!jQuery) {
        return;
      }

      // ✅ DEBUG: Verificar que los elementos existen
      const $console = jQuery(_private.SELECTORS.console);
      const $consoleContent = jQuery(_private.SELECTORS.consoleContent);
      const $clearButton = jQuery(_private.SELECTORS.clearButton);
      const $toggleButton = jQuery(_private.SELECTORS.toggleButton);
      
      
      // ✅ VERIFICACIÓN: Si no se encuentran los elementos, mostrar error detallado
      if ($console.length === 0) {
        // eslint-disable-next-line no-console
        console.error('[ConsoleManager] ❌ CRÍTICO: No se encontró el elemento de la consola', {
          selector: _private.SELECTORS.console,
          suggestion: 'Verifica que el HTML contiene <div id="mia-sync-console">'
        });
        return;
      }
      
      if ($consoleContent.length === 0) {
        // eslint-disable-next-line no-console
        console.error('[ConsoleManager] ❌ CRÍTICO: No se encontró el contenedor de contenido de la consola', {
          selector: _private.SELECTORS.consoleContent,
          suggestion: 'Verifica que el HTML contiene <div id="mia-console-content">'
        });
        return;
      }
      
      // ✅ NUEVO: Añadir mensaje inicial si la consola está vacía
      const existingLines = $consoleContent.find('.mia-console-line');
      if (existingLines.length === 0) {
        addLine('info', 'Consola de sincronización iniciada. Esperando actividad...');
      }
    
      const componentId = 'ConsoleManager';
      
      if ($clearButton.length > 0) {
        const clearHandler = function() {
          clear();
          addLine('info', 'Consola limpiada');
        };
        
        if (typeof window !== 'undefined' && window.EventCleanupManager && typeof window.EventCleanupManager.registerElementListener === 'function') {
          window.EventCleanupManager.registerElementListener($clearButton, 'click', clearHandler, componentId);
        } else {
          $clearButton.on('click', clearHandler);
        }
      }
    
      if ($toggleButton.length > 0) {
        const toggleHandler = function() {
          toggle();
        };
        
        if (typeof window !== 'undefined' && window.EventCleanupManager && typeof window.EventCleanupManager.registerElementListener === 'function') {
          window.EventCleanupManager.registerElementListener($toggleButton, 'click', toggleHandler, componentId);
        } else {
          $toggleButton.on('click', toggleHandler);
        }
      }
    
      const hasSubscribed = _private.initializeState.hasSubscribedToEvents === true;
      if (hasSubscribed) {
        return;
      }
    
      // Suscribirse a eventos de PollingManager
      if (window && window.pollingManager && typeof window.pollingManager.on === 'function') {
        const pm = window.pollingManager;
        
        // Fallback: suscribirse directamente si EventCleanupManager no está disponible
        if (typeof window === 'undefined' || !window.EventCleanupManager || typeof window.EventCleanupManager.registerCustomEventListener !== 'function') {
          if (typeof pm.on === 'function') {
            // ✅ CORRECCIÓN: Usar referencias seguras a través de ConsoleManager para evitar problemas de scope
            pm.on('syncProgress', function(data) {
              if (data && data.syncData) {
                // Usar ConsoleManager.updateSyncConsole para asegurar referencia correcta
                if (typeof window !== 'undefined' && window.ConsoleManager && typeof window.ConsoleManager.updateSyncConsole === 'function') {
                  window.ConsoleManager.updateSyncConsole(data.syncData, data.phase1Status);
                } else if (typeof updateSyncConsole === 'function') {
                  // Fallback a referencia local si window.ConsoleManager no está disponible
                  updateSyncConsole(data.syncData, data.phase1Status);
                }
              }
            });
    
            pm.on('syncError', function(error) {
              // Usar ConsoleManager.addLine para asegurar referencia correcta
              if (typeof window !== 'undefined' && window.ConsoleManager && typeof window.ConsoleManager.addLine === 'function') {
                window.ConsoleManager.addLine('error', error.message || 'Error en sincronización');
              } else if (typeof addLine === 'function') {
                // Fallback a referencia local si window.ConsoleManager no está disponible
                addLine('error', error.message || 'Error en sincronización');
              }
            });
          }
        } else {
          // Usar EventCleanupManager cuando está disponible
          // ✅ CORRECCIÓN: Usar referencias seguras a través de ConsoleManager para evitar problemas de scope
          window.EventCleanupManager.registerCustomEventListener(
            pm,
            'syncProgress',
            function(data) {
              if (data && data.syncData) {
                // Usar ConsoleManager.updateSyncConsole para asegurar referencia correcta
                if (typeof window !== 'undefined' && window.ConsoleManager && typeof window.ConsoleManager.updateSyncConsole === 'function') {
                  window.ConsoleManager.updateSyncConsole(data.syncData, data.phase1Status);
                } else if (typeof updateSyncConsole === 'function') {
                  // Fallback a referencia local si window.ConsoleManager no está disponible
                  updateSyncConsole(data.syncData, data.phase1Status);
                }
              }
            },
            componentId
          );
    
          window.EventCleanupManager.registerCustomEventListener(
            pm,
            'syncError',
            function(error) {
              // Usar ConsoleManager.addLine para asegurar referencia correcta
              if (typeof window !== 'undefined' && window.ConsoleManager && typeof window.ConsoleManager.addLine === 'function') {
                window.ConsoleManager.addLine('error', error.message || 'Error en sincronización');
              } else if (typeof addLine === 'function') {
                // Fallback a referencia local si window.ConsoleManager no está disponible
                addLine('error', error.message || 'Error en sincronización');
              }
            },
            componentId
          );
        }
    
        _private.initializeState.hasSubscribedToEvents = true;
      }
    }, 'initialize');
  }
  
  /**
   * Limpia todos los event listeners de ConsoleManager
   * 
   * Esta función debe llamarse:
   * - Al desmontar el componente
   * - Al salir de la página (se hace automáticamente)
   * - Cuando se necesita limpiar manualmente los listeners
   * 
   * @returns {void}
   * 
   * @example
   * // Limpieza manual
   * ConsoleManager.cleanupEventListeners();
   * 
   * @example
   * // Se llama automáticamente al salir de la página
   * // No es necesario llamarla manualmente en la mayoría de casos
   */
  function cleanupEventListeners() {
    // ✅ CRÍTICO: Envolver cleanup en safeExecute para manejo automático de errores
    safeExecute(function() {
      // ✅ NUEVO: Cancelar cualquier scroll pendiente antes de limpiar
      cancelPendingScroll();
      
      if (typeof window !== 'undefined' && window.EventCleanupManager && typeof window.EventCleanupManager.cleanupComponent === 'function') {
        window.EventCleanupManager.cleanupComponent('ConsoleManager');
      } else {
        if (isJQueryAvailable()) {
          const jQuery = getJQuery();
          if (jQuery) {
            const $clearButton = jQuery(_private.SELECTORS.clearButton);
            const $toggleButton = jQuery(_private.SELECTORS.toggleButton);
          
            if ($clearButton.length > 0) {
              $clearButton.off('click');
            }
            if ($toggleButton.length > 0) {
              $toggleButton.off('click');
            }
          }
        }
        
        if (window?.pollingManager?.off) {
          window.pollingManager.off('syncProgress');
          window.pollingManager.off('syncError');
        }
      }
    }, 'cleanupEventListeners');
  }
  
  /**
   * ✅ INICIALIZACIÓN DE CLEANUP AUTOMÁTICO
   * 
   * Inicializa los listeners de eventos de ciclo de vida para limpiar
   * automáticamente los event listeners al salir de la página.
   * 
   * Se ejecuta automáticamente cuando el módulo se carga.
   * 
   * @private
   * @returns {void}
   */
  function initializeAutoCleanup() {
    if (typeof window === 'undefined') {
      return;
    }
    
    // Evitar múltiples inicializaciones
    // @ts-ignore - Propiedad personalizada para tracking de cleanup
    if (window.__ConsoleManagerCleanupInitialized) {
      return;
    }
    
    // @ts-ignore - Propiedad personalizada para tracking de cleanup
    window.__ConsoleManagerCleanupInitialized = true;
    
    // ✅ Usar múltiples eventos para máxima compatibilidad
    // beforeunload: Se dispara antes de que la página se descargue (navegadores modernos)
    // pagehide: Más confiable en móviles y navegadores modernos, se dispara cuando la página se oculta
    // NOTA: 'unload' está deprecado y ha sido eliminado - usar 'pagehide' en su lugar
    if (typeof window.addEventListener !== 'undefined') {
      // Usar { once: true } para evitar múltiples ejecuciones
      window.addEventListener('beforeunload', function() {
        cleanupEventListeners();
      }, { once: true });
      
      // ✅ CORREGIDO: Eliminado 'unload' (deprecado) - 'pagehide' es más confiable
      window.addEventListener('pagehide', function() {
        cleanupEventListeners();
      }, { once: true });
      
      // ✅ NUEVO: También limpiar en visibilitychange si la página se oculta
      // Útil para aplicaciones SPA que pueden no disparar pagehide
      // NOTA: No limpiamos en visibilitychange porque puede dispararse cuando el usuario
      // simplemente cambia de pestaña, lo cual no significa que el componente deba destruirse.
      // El cleanup se maneja mejor con beforeunload/pagehide que son más definitivos.
    }
  }
  
  // ✅ Inicializar cleanup automático al cargar el módulo
  initializeAutoCleanup();
  
  /**
   * Agregar una línea al terminal de consola
   * 
   * @param {'info'|'success'|'warning'|'error'|'phase1'|'phase2'} type - Tipo de mensaje
   * @param {string} message - Mensaje a mostrar
   * @returns {void}
   * 
   * @example
   * ConsoleManager.addLine('info', 'Procesando productos...');
   * ConsoleManager.addLine('success', 'Sincronización completada');
   */
  function addLine(type, message) {
    // Verificar si jQuery está disponible sin lanzar excepciones
    if (!isJQueryAvailable()) {
      // eslint-disable-next-line no-console
      console.error('ConsoleManager requiere jQuery');
      return;
    }
  
    // ✅ CRÍTICO: Envolver operaciones DOM en safeExecute para manejo automático de errores
    return safeExecute(function() {
      const jQuery = getJQuery();
      if (!jQuery) {
        return;
      }
      
      const $consoleContent = jQuery(_private.SELECTORS.consoleContent);
      
      if ($consoleContent.length === 0) {
        // eslint-disable-next-line no-console
        console.warn('ConsoleManager: No se encontró el contenedor de la consola');
        return;
      }
    
      const now = new Date();
      const timeStr = now.toLocaleTimeString('es-ES', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
      });
    
      const label = _private.LABEL_MAP[type] || _private.LABEL_MAP.info;
    
      // ✅ SEGURIDAD: Sanitizar mensaje antes de insertarlo en el DOM
      // Cadena de fallbacks para máxima seguridad:
      // 1. Sanitizer.sanitizeMessage (preferido)
      // 2. DOMPurify.sanitize (si está disponible)
      // 3. Escape HTML básico (fallback final)
      const sanitizedMessage = sanitizeMessageForConsole(message);
      
      // ✅ SEGURIDAD: Construir HTML de forma segura usando .text() para el mensaje
      const $line = jQuery('<div>')
        .addClass('mia-console-line')
        .addClass(`mia-console-${type}`);
      
      // Crear elementos de forma segura
      jQuery('<span>').addClass('mia-console-time').text(timeStr).appendTo($line);
      jQuery('<span>').addClass('mia-console-label').text(label).appendTo($line);
      jQuery('<span>').addClass('mia-console-message').text(sanitizedMessage).appendTo($line);
    
      $consoleContent.append($line);
    
      // Limitar a MAX_LINES líneas para evitar problemas de rendimiento
      const lines = $consoleContent.find('.mia-console-line');
      if (lines.length > _private.MAX_LINES) {
        lines.first().remove();
      }
    
      // Auto-scroll al final
      scrollToBottom();
    }, 'addLine');
  }
  
  /**
   * Actualizar la consola con datos de sincronización
   * 
   * @param {Object} syncData - Datos de sincronización
   * @param {boolean} syncData.in_progress - Indica si hay sincronización en progreso
   * @param {boolean} [syncData.is_completed] - Indica si la sincronización está completada
   * @param {number} [syncData.porcentaje] - Porcentaje de progreso de Fase 2
   * @param {Object} [syncData.phase1_images] - Estado de Fase 1
   * @param {Object} [syncData.estadisticas] - Estadísticas de Fase 2
   * @param {Object} [syncData.last_cleanup_metrics] - Métricas de limpieza de caché de Fase 2
   * @param {Object} [phase1Status] - Estado de la Fase 1 (imágenes)
   * @param {boolean} [phase1Status.in_progress] - Indica si la sincronización está en progreso
   * @param {boolean} [phase1Status.completed] - Indica si la sincronización está completada
   * @param {boolean} [phase1Status.paused] - Indica si la sincronización está pausada
   * @param {boolean} [phase1Status.cancelled] - Indica si la sincronización está cancelada
   * @param {number} [phase1Status.products_processed] - Número de productos procesados
   * @param {number} [phase1Status.total_products] - Total de productos a procesar
   * @param {number} [phase1Status.images_processed] - Número de imágenes procesadas
   * @param {number} [phase1Status.duplicates_skipped] - Número de duplicados omitidos
   * @param {number} [phase1Status.errors] - Número de errores
   * @param {number} [phase1Status.last_processed_id] - ID del último producto procesado
   * @param {number} [phase1Status.last_product_images] - Imágenes del último producto procesado
   * @param {number} [phase1Status.last_product_duplicates] - Duplicados del último producto procesado
   * @param {number} [phase1Status.last_product_errors] - Errores del último producto procesado
   * @param {number} [phase1Status.start_time] - Timestamp de inicio de la sincronización
   * @param {boolean} [phase1Status.initial_cache_cleared] - Indica si se limpió el caché inicial
   * @param {number} [phase1Status.initial_cache_cleared_count] - Cantidad de entradas limpiadas del caché inicial
   * @param {boolean} [phase1Status.checkpoint_loaded] - Indica si se cargó un checkpoint
   * @param {number} [phase1Status.checkpoint_loaded_from_id] - ID del producto desde el que se cargó el checkpoint
   * @param {number} [phase1Status.checkpoint_loaded_products_processed] - Productos procesados al cargar el checkpoint
   * @param {number} [phase1Status.last_checkpoint_saved_id] - ID del último checkpoint guardado
   * @param {boolean} [phase1Status.thumbnails_disabled] - Indica si los thumbnails están desactivados
   * @param {boolean} [phase1Status.memory_limit_increased] - Indica si se aumentó el límite de memoria
   * @param {string} [phase1Status.memory_limit_original] - Límite de memoria original
   * @param {string} [phase1Status.memory_limit_new] - Nuevo límite de memoria
   * @param {Object} [phase1Status.last_cleanup_metrics] - Métricas de limpieza de caché de Fase 1
   * @returns {void}
   * 
   * @example
   * ConsoleManager.updateSyncConsole({
   *   in_progress: true,
   *   estadisticas: { procesados: 50, total: 100 }
   * }, {
   *   in_progress: false,
   *   completed: true
   * });
   */
  function updateSyncConsole(syncData, phase1Status) {
    if (!isJQueryAvailable()) {
      // eslint-disable-next-line no-console
      console.error('ConsoleManager requiere jQuery');
      return;
    }
  
    const jQuery = getJQuery();
    if (!jQuery) {
      return;
    }
  
    const $console = jQuery(_private.SELECTORS.console);
    const $consoleContent = jQuery(_private.SELECTORS.consoleContent);
  
    if ($console.length === 0 || $consoleContent.length === 0) {
      // eslint-disable-next-line no-console
      console.warn('[ConsoleManager] ⚠️  No se encontraron elementos de la consola', {
        consoleSelector: _private.SELECTORS.console,
        consoleContentSelector: _private.SELECTORS.consoleContent,
        consoleFound: $console.length > 0,
        consoleContentFound: $consoleContent.length > 0
      });
      return;
    }
  
    // ✅ ACTUALIZADO: La consola está siempre visible, no necesita mostrar/ocultar
    // Asegurar que esté visible (por si acaso)
    if ($console.is(':hidden')) {
      $console.show();
    }
  
    // ✅ NUEVO: Limpiar mensaje inicial si hay actividad
    const phase1InProgress = phase1Status && phase1Status.in_progress === true;
    const phase2InProgress = syncData && syncData.in_progress === true && !phase1InProgress;
    const hasActivity = phase1InProgress || phase2InProgress;
    
    if (hasActivity) {
      // Buscar y eliminar el mensaje inicial "Esperando actividad..."
      const $initialMessage = $consoleContent.find('.mia-console-line').first();
      if ($initialMessage.length > 0) {
        const messageText = $initialMessage.find('.mia-console-message').text();
        if (messageText.includes('Esperando actividad') || messageText.includes('Consola de sincronización iniciada')) {
          $initialMessage.remove();
        }
      }
    }
  
    // ✅ REMOVIDO: Debug innecesario que se ejecuta constantemente (cada 2 segundos durante polling)
  
    // Actualizar indicadores de fase
    updatePhaseIndicators(syncData, phase1Status);
  
    // Agregar líneas de log según el estado
    addProgressLines(syncData, phase1Status);
  
    // Auto-scroll al final
    scrollToBottom();
  }
  
  /**
   * Actualizar los indicadores de fase
   * 
   * @param {Object} syncData - Datos de sincronización
   * @param {boolean} syncData.in_progress - Indica si hay sincronización en progreso
   * @param {boolean} [syncData.is_completed] - Indica si la sincronización está completada
   * @param {Object} [syncData.phase1_images] - Estado de Fase 1
   * @param {Object} [syncData.estadisticas] - Estadísticas de Fase 2
   * @param {Object} [phase1Status] - Estado de la Fase 1
   * @param {boolean} [phase1Status.in_progress] - Indica si la sincronización está en progreso
   * @param {boolean} [phase1Status.completed] - Indica si la sincronización está completada
   * @returns {void}
   * @private
   */
  function updatePhaseIndicators(syncData, phase1Status) {
    // ✅ CRÍTICO: Envolver actualización de indicadores en safeExecute para manejo automático de errores
    safeExecute(function() {
      if (!isJQueryAvailable()) {
        return;
      }
      
      const jQuery = getJQuery();
      if (!jQuery) {
        return;
      }
      
      const phase1InProgress = phase1Status && phase1Status.in_progress;
      const phase1Completed = phase1Status && phase1Status.completed;
      const phase2InProgress = syncData.in_progress && !phase1InProgress;
      const phase2Completed = syncData.is_completed;
    
      // Actualizar Fase 1
      const $phase1Indicator = jQuery(_private.SELECTORS.phase1Indicator);
      if ($phase1Indicator.length > 0) {
        if (phase1Completed) {
          $phase1Indicator.attr('data-status', 'completed');
          $phase1Indicator.find('.mia-phase-status').text('Completada').attr('data-status', 'completed');
        } else if (phase1InProgress) {
          $phase1Indicator.attr('data-status', 'active');
          $phase1Indicator.find('.mia-phase-status').text('En Progreso').attr('data-status', 'active');
        } else {
          $phase1Indicator.attr('data-status', 'pending');
          $phase1Indicator.find('.mia-phase-status').text('Pendiente').attr('data-status', 'pending');
        }
      }
    
      // Actualizar Fase 2
      const $phase2Indicator = jQuery(_private.SELECTORS.phase2Indicator);
      if ($phase2Indicator.length > 0) {
        if (phase2Completed) {
          $phase2Indicator.attr('data-status', 'completed');
          $phase2Indicator.find('.mia-phase-status').text('Completada').attr('data-status', 'completed');
        } else if (phase2InProgress) {
          $phase2Indicator.attr('data-status', 'active');
          $phase2Indicator.find('.mia-phase-status').text('En Progreso').attr('data-status', 'active');
        } else {
          $phase2Indicator.attr('data-status', 'pending');
          $phase2Indicator.find('.mia-phase-status').text('Pendiente').attr('data-status', 'pending');
        }
      }
    }, 'updatePhaseIndicators');
  }
  
  /**
   * Agregar líneas de progreso según el estado
   * 
   * @param {Object} syncData - Datos de sincronización
   * @param {boolean} syncData.in_progress - Indica si hay sincronización en progreso
   * @param {boolean} [syncData.is_completed] - Indica si la sincronización está completada
   * @param {number} [syncData.porcentaje] - Porcentaje de progreso de Fase 2
   * @param {Object} [syncData.phase1_images] - Estado de Fase 1
   * @param {Object} [syncData.estadisticas] - Estadísticas de Fase 2
   * @param {Object} [syncData.last_cleanup_metrics] - Métricas de limpieza de caché de Fase 2
   * @param {Object} [phase1Status] - Estado de la Fase 1
   * @param {boolean} [phase1Status.in_progress] - Indica si la sincronización está en progreso
   * @param {boolean} [phase1Status.completed] - Indica si la sincronización está completada
   * @param {boolean} [phase1Status.paused] - Indica si la sincronización está pausada
   * @param {boolean} [phase1Status.cancelled] - Indica si la sincronización está cancelada
   * @param {number} [phase1Status.products_processed] - Número de productos procesados
   * @param {number} [phase1Status.total_products] - Total de productos a procesar
   * @param {number} [phase1Status.images_processed] - Número de imágenes procesadas
   * @param {number} [phase1Status.duplicates_skipped] - Número de duplicados omitidos
   * @param {number} [phase1Status.errors] - Número de errores
   * @param {number} [phase1Status.last_processed_id] - ID del último producto procesado
   * @param {number} [phase1Status.last_product_images] - Imágenes del último producto procesado
   * @param {number} [phase1Status.last_product_duplicates] - Duplicados del último producto procesado
   * @param {number} [phase1Status.last_product_errors] - Errores del último producto procesado
   * @param {number} [phase1Status.start_time] - Timestamp de inicio de la sincronización
   * @param {boolean} [phase1Status.initial_cache_cleared] - Indica si se limpió el caché inicial
   * @param {number} [phase1Status.initial_cache_cleared_count] - Cantidad de entradas limpiadas del caché inicial
   * @param {boolean} [phase1Status.checkpoint_loaded] - Indica si se cargó un checkpoint
   * @param {number} [phase1Status.checkpoint_loaded_from_id] - ID del producto desde el que se cargó el checkpoint
   * @param {number} [phase1Status.checkpoint_loaded_products_processed] - Productos procesados al cargar el checkpoint
   * @param {number} [phase1Status.last_checkpoint_saved_id] - ID del último checkpoint guardado
   * @param {boolean} [phase1Status.thumbnails_disabled] - Indica si los thumbnails están desactivados
   * @param {boolean} [phase1Status.memory_limit_increased] - Indica si se aumentó el límite de memoria
   * @param {string} [phase1Status.memory_limit_original] - Límite de memoria original
   * @param {string} [phase1Status.memory_limit_new] - Nuevo límite de memoria
   * @param {Object} [phase1Status.last_cleanup_metrics] - Métricas de limpieza de caché de Fase 1
   * @returns {void}
   * @private
   */
  function addProgressLines(syncData, phase1Status) {
    if (!isJQueryAvailable()) {
      return;
    }
    
    const jQuery = getJQuery();
    if (!jQuery) {
      return;
    }
    
    const $consoleContent = jQuery(_private.SELECTORS.consoleContent);
    
    // ✅ DEBUG: Log para diagnosticar
    
    // ✅ PROTECCIÓN: Validar que phase1Status existe antes de usarlo
    if (!phase1Status || typeof phase1Status !== 'object') {
      phase1Status = {};
    }
    
    const phase1InProgress = phase1Status.in_progress === true;
    const phase1Completed = phase1Status.completed === true;
    const phase1Paused = phase1Status.paused === true;
    const phase1Cancelled = phase1Status.cancelled === true;
    const phase2InProgress = syncData && syncData.in_progress === true && !phase1InProgress;
    const phase2Completed = syncData && syncData.is_completed === true;
    
    // ✅ NUEVO: Detectar si hay progreso real (valores > 0) incluso si está pausada o cancelada
    const hasRealProgress = (phase1Status.products_processed > 0) || (phase1Status.total_products > 0);
    
  
    // ✅ NUEVO: Mostrar métricas de limpieza de caché para Fase 1
    if (phase1InProgress && phase1Status && phase1Status.last_cleanup_metrics) {
      const cleanup = phase1Status.last_cleanup_metrics;
      const lastCleanupTime = cleanup.timestamp || 0;
      const now = Math.floor(Date.now() / 1000);
      
      // Solo mostrar si la limpieza fue reciente (últimos 30 segundos) para evitar spam
      if (now - lastCleanupTime <= 30) {
        const cleanupMsg = formatCleanupMetrics(cleanup, 'Fase 1');
        const lastLine = $consoleContent.find('.mia-console-line').last();
        const lastMessage = lastLine.find('.mia-console-message').text();
        
        // Solo agregar si no es la misma métrica
        if (!lastMessage.includes('Limpieza de caché') || !lastMessage.includes(cleanup.cleanup_level || cleanup.type)) {
          addLine('info', cleanupMsg);
        }
      }
    }
  
    // ✅ NUEVO: Mostrar métricas de limpieza de caché para Fase 2
    if (phase2InProgress && syncData.last_cleanup_metrics) {
      const cleanup = syncData.last_cleanup_metrics;
      const lastCleanupTime = cleanup.timestamp || 0;
      const now = Math.floor(Date.now() / 1000);
      
      // Solo mostrar si la limpieza fue reciente (últimos 30 segundos) para evitar spam
      if (now - lastCleanupTime <= 30) {
        const cleanupMsg = formatCleanupMetrics(cleanup, 'Fase 2');
        const lastLine = $consoleContent.find('.mia-console-line').last();
        const lastMessage = lastLine.find('.mia-console-message').text();
        
        // Solo agregar si no es la misma métrica
        if (!lastMessage.includes('Limpieza de caché') || !lastMessage.includes(cleanup.type || 'batch')) {
          addLine('info', cleanupMsg);
        }
      }
    }
  
    // ✅ NUEVO: Mostrar mensaje cuando Fase 1 inicia (solo una vez)
    if (phase1InProgress && phase1Status && TrackingState.lastProductsProcessed === 0 && phase1Status.products_processed === 0) {
      const totalProducts = phase1Status.total_products || 0;
      addLine('phase1', `Iniciando Fase 1: Sincronización de imágenes${totalProducts > 0 ? ` para ${totalProducts} productos` : ''}...`);
      TrackingState.lastProductsProcessed = -1; // Marcar que ya mostramos el mensaje inicial
    }
    
    // ✅ NUEVO: Mostrar mensaje de limpieza inicial de caché (solo una vez)
    if (phase1InProgress && phase1Status && phase1Status.initial_cache_cleared && !TrackingState.initialCacheClearedShown) {
      const clearedCount = phase1Status.initial_cache_cleared_count || 0;
      const cacheMsg = clearedCount > 0 
        ? `Caché inicial limpiada: ${clearedCount} entradas eliminadas`
        : 'Caché inicial limpiada';
      addLine('info', cacheMsg);
      TrackingState.initialCacheClearedShown = true;
    }
    
    // ✅ NUEVO: Mostrar mensaje de checkpoint cargado (solo una vez)
    if (phase1InProgress && phase1Status && phase1Status.checkpoint_loaded && phase1Status.checkpoint_loaded_from_id && !TrackingState.checkpointLoadedShown) {
      const checkpointId = phase1Status.checkpoint_loaded_from_id;
      const checkpointProducts = phase1Status.checkpoint_loaded_products_processed || 0;
      addLine('info', `Reanudando desde checkpoint: Producto #${checkpointId} (${checkpointProducts} productos ya procesados)`);
      TrackingState.checkpointLoadedShown = true;
    }
    
    // ✅ NUEVO: Mostrar mensajes informativos técnicos (solo una vez al inicio)
    if (phase1InProgress && phase1Status && phase1Status.products_processed === 0 && !TrackingState.technicalInfoShown) {
      // Mensaje de thumbnails desactivados
      if (phase1Status.thumbnails_disabled) {
        addLine('info', 'Generación de thumbnails desactivada temporalmente (se generarán automáticamente después de la sincronización)');
      }
      
      // Mensaje de límite de memoria aumentado
      if (phase1Status.memory_limit_increased && phase1Status.memory_limit_original && phase1Status.memory_limit_new) {
        addLine('info', `Límite de memoria aumentado temporalmente: ${phase1Status.memory_limit_original} → ${phase1Status.memory_limit_new}`);
      }
      
      TrackingState.technicalInfoShown = true;
    }
    
    // ✅ NUEVO: Mostrar mensaje cuando se guarda un checkpoint (cada vez que cambia)
    if (phase1InProgress && phase1Status && phase1Status.last_checkpoint_saved_id && phase1Status.last_checkpoint_saved_id !== TrackingState.lastCheckpointSavedId) {
      const checkpointId = phase1Status.last_checkpoint_saved_id;
      addLine('info', `Checkpoint guardado: Producto #${checkpointId}`);
      TrackingState.lastCheckpointSavedId = checkpointId;
    }
  
    // ✅ NUEVO: Mostrar estado cuando está pausada o cancelada pero hay progreso real
    // ✅ CORRECCIÓN: Solo mostrar si hay un cambio de estado (de activa a pausada/cancelada)
    // No mostrar si simplemente se carga el estado inicial pausado
    if (!phase1InProgress && hasRealProgress && phase1Status && (phase1Paused || phase1Cancelled)) {
      const phase1Percent = phase1Status.total_products > 0
        ? ((phase1Status.products_processed / phase1Status.total_products) * 100).toFixed(1)
        : 0;
      
      const currentProductsProcessed = phase1Status.products_processed || 0;
      const imagesProcessed = phase1Status.images_processed || 0;
      const duplicatesSkipped = phase1Status.duplicates_skipped || 0;
      const errors = phase1Status.errors || 0;
      
      // ✅ CORRECCIÓN: Solo mostrar si hay un cambio de estado real (de activa a pausada/cancelada)
      // No mostrar si simplemente se carga el estado inicial pausado
      const wasInProgress = TrackingState.wasInProgress === true;
      const stateChanged = (phase1Paused && !TrackingState.wasPaused) || (phase1Cancelled && !TrackingState.wasCancelled);
      const progressChanged = currentProductsProcessed !== TrackingState.lastProductsProcessed ||
                              imagesProcessed !== TrackingState.lastImagesProcessed;
      
      // Solo mostrar si:
      // 1. Estaba en progreso y ahora está pausada/cancelada (cambio de estado)
      // 2. O si hay un cambio significativo en el progreso mientras está pausada/cancelada
      const shouldShow = (wasInProgress && stateChanged) || (progressChanged && wasInProgress);
      
      if (shouldShow) {
        let statusMsg = phase1Paused ? 'Fase 1 pausada' : 'Fase 1 cancelada';
        statusMsg += `: ${currentProductsProcessed}/${phase1Status.total_products || 0} productos procesados`;
        statusMsg += `, ${imagesProcessed} imágenes sincronizadas`;
        if (duplicatesSkipped > 0) {
          statusMsg += `, ${duplicatesSkipped} duplicados omitidos`;
        }
        if (errors > 0) {
          statusMsg += `, ${errors} errores`;
        }
        statusMsg += ` (${phase1Percent}%)`;
        
        addLine(phase1Paused ? 'warning' : 'error', statusMsg);
        
        // Actualizar tracking usando método updateMultiple para mejor mantenibilidad
        TrackingState.updateMultiple({
          lastProductsProcessed: currentProductsProcessed,
          lastImagesProcessed: imagesProcessed,
          wasPaused: phase1Paused,
          wasCancelled: phase1Cancelled,
          wasInProgress: false // Ya no está en progreso
        });
      } else {
        // ✅ NUEVO: Actualizar tracking sin mostrar mensaje si es estado inicial
        // Esto evita mostrar mensajes de sincronizaciones anteriores al cargar la página
        TrackingState.updateMultiple({
          wasPaused: phase1Paused,
          wasCancelled: phase1Cancelled,
          lastProductsProcessed: currentProductsProcessed,
          lastImagesProcessed: imagesProcessed
        });
      }
    } else if (phase1InProgress) {
      // ✅ NUEVO: Marcar que está en progreso y resetear flags de pausa/cancelación
      TrackingState.updateMultiple({
        wasInProgress: true,
        wasPaused: false,
        wasCancelled: false
      });
    } else {
      // ✅ NUEVO: Si no está en progreso y no está pausada/cancelada, resetear flag
      TrackingState.wasInProgress = false;
    }
    
    // Fase 1 en progreso
    if (phase1InProgress && phase1Status) {
      const phase1Percent = phase1Status.total_products > 0
        ? ((phase1Status.products_processed / phase1Status.total_products) * 100).toFixed(1)
        : 0;
  
      // ✅ MEJORADO: Mostrar mensaje detallado de cada producto procesado
      // Verificar si hay un nuevo producto procesado
      const currentProductId = phase1Status.last_processed_id || 0;
      const currentProductsProcessed = phase1Status.products_processed || 0;
      const currentImagesProcessed = phase1Status.images_processed || 0;
      const productChanged = currentProductId > 0 && TrackingState.hasChanged('lastProductId', currentProductId);
      const productsProcessedChanged = TrackingState.hasChanged('lastProductsProcessed', currentProductsProcessed);
      const imagesProcessedChanged = TrackingState.hasChanged('lastImagesProcessed', currentImagesProcessed);
      
      // ✅ CORRECCIÓN: Resetear tracking si products_processed cambió de 0 a un valor positivo
      if (TrackingState.lastProductsProcessed === -1 && currentProductsProcessed > 0) {
        TrackingState.lastProductsProcessed = 0;
      }
      
      // ✅ MEJORADO: Mostrar mensaje por cada producto procesado
      // ✅ CORRECCIÓN: Verificar que tenemos datos del último producto antes de mostrar
      if (productChanged && currentProductId > 0) {
        const lastProductImages = phase1Status.last_product_images !== undefined ? phase1Status.last_product_images : 0;
        const lastProductDuplicates = phase1Status.last_product_duplicates !== undefined ? phase1Status.last_product_duplicates : 0;
        const lastProductErrors = phase1Status.last_product_errors !== undefined ? phase1Status.last_product_errors : 0;
        
        // ✅ CORRECCIÓN: Solo mostrar si tenemos información del producto (incluso si es 0)
        // Esto asegura que siempre mostramos algo cuando se procesa un producto
        let productMsg = `Producto #${currentProductId}: `;
        const parts = [];
        
        if (lastProductImages > 0) {
          parts.push(`${lastProductImages} imagen${lastProductImages > 1 ? 'es' : ''} descargada${lastProductImages > 1 ? 's' : ''}`);
        }
        if (lastProductDuplicates > 0) {
          parts.push(`${lastProductDuplicates} duplicada${lastProductDuplicates > 1 ? 's' : ''} omitida${lastProductDuplicates > 1 ? 's' : ''}`);
        }
        if (lastProductErrors > 0) {
          parts.push(`${lastProductErrors} error${lastProductErrors > 1 ? 'es' : ''}`);
        }
        if (parts.length === 0) {
          parts.push('sin imágenes');
        }
        
        productMsg += parts.join(', ');
        addLine('phase1', productMsg);
        
        // Actualizar tracking
        TrackingState.lastProductId = currentProductId;
      }
  
      // ✅ MEJORADO: Mostrar resumen general cuando cambia el número de productos o imágenes procesados
      // ✅ CORRECCIÓN: Mostrar resumen cada cierto número de productos o cuando cambian significativamente los totales
      if ((productsProcessedChanged || imagesProcessedChanged) && currentProductsProcessed > 0) {
        const imagesProcessed = phase1Status.images_processed || 0;
        const duplicatesSkipped = phase1Status.duplicates_skipped || 0;
        const errors = phase1Status.errors || 0;
        
        // ✅ CORRECCIÓN: Mostrar resumen cada 5 productos o cuando cambian los totales significativamente
        // Esto asegura feedback regular sin saturar la consola
        const shouldShowSummary = 
          currentProductsProcessed % 5 === 0 || // Cada 5 productos
          currentProductsProcessed === 1 || // Primer producto
          currentProductsProcessed === phase1Status.total_products || // Último producto
          (productsProcessedChanged && currentProductsProcessed !== TrackingState.lastSummaryProducts); // Cambio significativo
        
        if (shouldShowSummary) {
          let summaryMsg = `Fase 1: ${currentProductsProcessed}/${phase1Status.total_products || 0} productos procesados`;
          summaryMsg += `, ${imagesProcessed} imagen${imagesProcessed !== 1 ? 'es' : ''} sincronizada${imagesProcessed !== 1 ? 's' : ''}`;
          if (duplicatesSkipped > 0) {
            summaryMsg += `, ${duplicatesSkipped} duplicado${duplicatesSkipped !== 1 ? 's' : ''} omitido${duplicatesSkipped !== 1 ? 's' : ''}`;
          }
          if (errors > 0) {
            summaryMsg += `, ${errors} error${errors !== 1 ? 'es' : ''}`;
          }
          summaryMsg += ` (${phase1Percent}%)`;
          
          // ✅ NUEVO: Agregar velocidad de procesamiento al resumen
          if (phase1Status.start_time && phase1Status.start_time > 0) {
            const elapsedSeconds = (Math.floor(Date.now() / 1000) - phase1Status.start_time);
            if (elapsedSeconds > 0) {
              const speed = (currentProductsProcessed / elapsedSeconds).toFixed(2);
              summaryMsg += ` | Velocidad: ${speed} productos/seg`;
            }
          }
          
          addLine('info', summaryMsg);
          TrackingState.lastSummaryProducts = currentProductsProcessed;
        }
        
        // ✅ IMPORTANTE: Actualizar tracking siempre, incluso si no mostramos el resumen
        // Esto asegura que el tracking esté actualizado para la próxima verificación
        TrackingState.updateMultiple({
          lastProductsProcessed: currentProductsProcessed,
          lastImagesProcessed: currentImagesProcessed
        });
      }
    }
  
    // Fase 2 en progreso
    if (phase2InProgress && syncData.estadisticas) {
      const phase2Percent = syncData.porcentaje || 0;
      const stats = syncData.estadisticas || {};
  
      // Verificar si ya existe una línea similar reciente
      const lastLine = $consoleContent.find('.mia-console-line').last();
      const lastMessage = lastLine.find('.mia-console-message').text();
      const shouldAdd = !lastMessage.includes(`Fase 2: ${stats.procesados}/${stats.total}`);
  
      if (shouldAdd && stats.procesados > 0) {
        addLine('phase2', `Fase 2: ${stats.procesados}/${stats.total} productos sincronizados (${phase2Percent.toFixed(1)}%)`);
      }
    }
  
    // Fase 1 completada
    if (phase1Completed && !phase2InProgress) {
      addLine('success', 'Fase 1 completada exitosamente. Iniciando Fase 2...');
    }
  
    // Fase 2 completada
    if (phase2Completed) {
      addLine('success', 'Sincronización completada exitosamente');
    }
  }
  
  /**
   * Formatea las métricas de limpieza de caché para mostrar en consola
   * 
   * @param {Object} cleanup - Métricas de limpieza
   * @param {string} phase - Fase ('Fase 1' o 'Fase 2')
   * @returns {string} Mensaje formateado
   * @private
   */
  function formatCleanupMetrics(cleanup, phase) {
    if (!cleanup) {
      return '';
    }
  
    const parts = [];
    
    // Memoria liberada
    if (cleanup.memory_freed_mb && cleanup.memory_freed_mb > 0) {
      parts.push(`Memoria liberada: ${cleanup.memory_freed_mb} MB`);
    }
    
    // Uso de memoria
    if (cleanup.memory_usage_percent !== undefined) {
      parts.push(`Uso memoria: ${cleanup.memory_usage_percent}%`);
    }
    
    // Garbage collection
    if (cleanup.gc_cycles_collected !== undefined && cleanup.gc_cycles_collected > 0) {
      parts.push(`GC: ${cleanup.gc_cycles_collected} ciclos`);
    }
    
    // Cache flush
    if (cleanup.cache_flushed) {
      parts.push('Cache WordPress: limpiado');
    }
    
    // Cold cache limpiado
    if (cleanup.cold_cache_cleaned && cleanup.cold_cache_cleaned > 0) {
      parts.push(`Cold cache: ${cleanup.cold_cache_cleaned} entradas`);
    }
    
    // Hot→Cold migrado
    if (cleanup.hot_cold_migrated && cleanup.hot_cold_migrated > 0) {
      parts.push(`Hot→Cold: ${cleanup.hot_cold_migrated} migradas`);
    }
    
    // Nivel de limpieza
    if (cleanup.cleanup_level) {
      const levelNames = {
        light: 'Ligera',
        moderate: 'Moderada',
        aggressive: 'Agresiva',
        critical: 'Crítica'
      };
      parts.push(`Nivel: ${levelNames[cleanup.cleanup_level] || cleanup.cleanup_level}`);
    }
    
    // Total limpiado (Fase 2)
    if (cleanup.total_cleared !== undefined && cleanup.total_cleared > 0) {
      parts.push(`Entradas limpiadas: ${cleanup.total_cleared}`);
    }
    
    if (cleanup.preserved_hot_cache !== undefined && cleanup.preserved_hot_cache > 0) {
      parts.push(`Hot cache preservado: ${cleanup.preserved_hot_cache}`);
    }
  
    const metricsText = parts.length > 0 ? parts.join(' | ') : 'Limpieza ejecutada';
    
    return `${phase} - Limpieza de caché: ${metricsText}`;
  }
  
  /**
   * Limpiar el contenido de la consola
   * 
   * @returns {void}
   * 
   * @example
   * ConsoleManager.clear();
   */
  function clear() {
    // ✅ CRÍTICO: Envolver limpieza en safeExecute para manejo automático de errores
    return safeExecute(function() {
      if (!isJQueryAvailable()) {
        return;
      }
    
      const jQuery = getJQuery();
      if (!jQuery) {
        return;
      }
    
      const $consoleContent = jQuery(_private.SELECTORS.consoleContent);
      if ($consoleContent.length > 0) {
        $consoleContent.empty();
      }
      
      // ✅ NUEVO: Resetear estado de tracking al limpiar usando método del objeto TrackingState
      TrackingState.reset();
    }, 'clear');
  }
  
  /**
   * Alternar entre minimizado y maximizado
   * 
   * @returns {void}
   * 
   * @example
   * ConsoleManager.toggle();
   */
  function toggle() {
    // ✅ CRÍTICO: Envolver toggle en safeExecute para manejo automático de errores
    return safeExecute(function() {
      if (!isJQueryAvailable()) {
        return;
      }
    
      const jQuery = getJQuery();
      if (!jQuery) {
        return;
      }
    
      const $console = jQuery(_private.SELECTORS.console);
      const $toggleButton = jQuery(_private.SELECTORS.toggleButton);
    
      if ($console.length === 0 || $toggleButton.length === 0) {
        return;
      }
    
      $console.toggleClass('minimized');
    
      const $icon = $toggleButton.find('.dashicons');
      if ($console.hasClass('minimized')) {
        $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
      } else {
        $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
      }
    }, 'toggle');
  }
  
  /**
   * Hacer scroll al final de la consola
   * 
   * ✅ OPTIMIZADO: Usa requestAnimationFrame para optimizar el scroll cuando
   * se llama frecuentemente, evitando múltiples scrolls innecesarios.
   * 
   * @returns {void}
   * @private
   */
  function scrollToBottom() {
    // Si ya hay un scroll programado, no programar otro
    if (scrollState.rafId !== null) {
      return;
    }
    
    // Si ya se está ejecutando un scroll, no programar otro
    if (scrollState.isScrolling) {
      return;
    }
    
    // ✅ OPTIMIZACIÓN: Usar requestAnimationFrame para sincronizar con el ciclo de renderizado
    if (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
      scrollState.rafId = window.requestAnimationFrame(function() {
        scrollState.rafId = null;
        scrollState.isScrolling = true;
        
        // ✅ CRÍTICO: Envolver scroll en safeExecute para manejo automático de errores
        safeExecute(function() {
          if (!isJQueryAvailable()) {
            scrollState.isScrolling = false;
            return;
          }
        
          const jQuery = getJQuery();
          if (!jQuery) {
            scrollState.isScrolling = false;
            return;
          }
        
          const $consoleBody = jQuery(_private.SELECTORS.consoleBody);
          if ($consoleBody.length > 0 && $consoleBody[0]) {
            // Usar scrollTop directamente para mejor rendimiento
            const scrollHeight = $consoleBody[0].scrollHeight;
            $consoleBody[0].scrollTop = scrollHeight;
          }
          
          scrollState.isScrolling = false;
        }, 'scrollToBottom');
      });
    } else {
      // Fallback para entornos sin requestAnimationFrame (muy raro en navegadores modernos)
      scrollState.isScrolling = true;
      
      safeExecute(function() {
        if (!isJQueryAvailable()) {
          scrollState.isScrolling = false;
          return;
        }
      
        const jQuery = getJQuery();
        if (!jQuery) {
          scrollState.isScrolling = false;
          return;
        }
      
        const $consoleBody = jQuery(_private.SELECTORS.consoleBody);
        if ($consoleBody.length > 0 && $consoleBody[0]) {
          const scrollHeight = $consoleBody[0].scrollHeight;
          $consoleBody[0].scrollTop = scrollHeight;
        }
        
        scrollState.isScrolling = false;
      }, 'scrollToBottom');
    }
  }
  
  /**
   * ✅ HELPER: Cancela cualquier scroll pendiente
   * 
   * Útil para limpiar scrolls programados cuando sea necesario.
   * 
   * @returns {void}
   * @private
   */
  function cancelPendingScroll() {
    if (scrollState.rafId !== null && typeof window !== 'undefined' && typeof window.cancelAnimationFrame === 'function') {
      window.cancelAnimationFrame(scrollState.rafId);
      scrollState.rafId = null;
    }
    scrollState.isScrolling = false;
  }
  
  // ✅ DEBUG: Log ANTES de crear ConsoleManager para verificar que las funciones están disponibles
  // ✅ MEJORADO: Usar debugLog en lugar de console.log para que solo se ejecute en desarrollo
  debugLog('log', '[ConsoleManager] Verificando funciones antes de crear objeto...', {
    hasInitialize: typeof initialize !== 'undefined',
    hasAddLine: typeof addLine !== 'undefined',
    hasUpdateSyncConsole: typeof updateSyncConsole !== 'undefined',
    hasClear: typeof clear !== 'undefined',
    hasToggle: typeof toggle !== 'undefined'
  });
  
  /**
   * Objeto ConsoleManager con métodos públicos
   * 
   * ✅ CLEANUP AUTOMÁTICO: Los event listeners se limpian automáticamente al:
   * - Salir de la página (beforeunload, unload, pagehide)
   * 
   * El cleanup se ejecuta automáticamente cuando el módulo se carga.
   * También puedes llamar manualmente a cleanupEventListeners() si es necesario.
   */
  const ConsoleManager = {
    initialize,
    addLine,
    updateSyncConsole,
    clear,
    toggle,
    cleanupEventListeners,
    // Exponer MAX_LINES como constante pública (solo lectura)
    get MAX_LINES() {
      return _private.MAX_LINES;
    },
    /**
     * ✅ CONFIGURABLE: Establece el límite máximo de líneas en la consola
     * 
     * @param {number} maxLines - Nuevo límite máximo de líneas (debe ser > 0)
     * @returns {boolean} true si se estableció correctamente, false en caso contrario
     * 
     * @example
     * ConsoleManager.setMaxLines(200); // Establecer límite a 200 líneas
     */
    setMaxLines(maxLines) {
      if (typeof maxLines !== 'number' || maxLines <= 0 || !Number.isInteger(maxLines)) {
        if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
          window.ErrorHandler.logError(
            `ConsoleManager.setMaxLines: Valor inválido. Debe ser un número entero positivo, recibido: ${maxLines}`,
            'CONSOLE_MANAGER_CONFIG'
          );
        }
        return false;
      }
      _private.MAX_LINES = maxLines;
      debugLog('log', `[ConsoleManager] MAX_LINES actualizado a ${maxLines}`);
      return true;
    },
    // Exponer initializeState para que los tests puedan resetearlo (solo lectura)
    get initializeState() {
      return _private.initializeState;
    }
  };
  
  /**
   * ✅ EXPOSICIÓN DE MÓDULOS: Soporte para múltiples sistemas de módulos
   * 
   * ConsoleManager soporta tres formas de importación:
   * 
   * 1. **ES Modules (ESM)** - Para entornos modernos:
   *    ```js
   *    import { ConsoleManager } from './ConsoleManager.js';
   *    ```
   * 
   * 2. **CommonJS** - Para Node.js y bundlers:
   *    ```js
   *    const { ConsoleManager } = require('./ConsoleManager.js');
   *    ```
   * 
   * 3. **Global (window)** - Para scripts tradicionales (compatibilidad):
   *    ```js
   *    // Disponible globalmente después de cargar el script
   *    window.ConsoleManager.initialize();
   *    ```
   * 
   * ✅ MEJORADO: Múltiples intentos de exposición para asegurar que se exponga correctamente
   */
  
  // ✅ ESM (ES Modules) - Exportación para import estático
  // Esto permite usar: import { ConsoleManager } from './ConsoleManager.js'
  // @ts-ignore - AMD define puede estar disponible en algunos entornos
  if (typeof window !== 'undefined' && typeof window.define === 'function' && window.define.amd) {
    // AMD (Asynchronous Module Definition) - RequireJS
    // @ts-ignore - AMD define puede estar disponible en algunos entornos
    window.define([], function() {
      return { ConsoleManager };
    });
  }
  
  // ✅ CommonJS - Exportación para require()
  // Esto permite usar: const { ConsoleManager } = require('./ConsoleManager.js')
  // @ts-ignore - module puede estar disponible en entornos CommonJS
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { ConsoleManager };
    // También exportar funciones individuales para compatibilidad
    module.exports.updateSyncConsole = updateSyncConsole;
    module.exports.addConsoleLine = addLine;
  }
  
  // ✅ ESM (ES Modules) - Exportación para import dinámico
  // Esto permite usar: import { ConsoleManager } from './ConsoleManager.js'
  if (typeof window !== 'undefined') {
    // Crear una propiedad especial que los bundlers pueden detectar
    // @ts-ignore - Propiedad personalizada para detección de ESM
    window.__ConsoleManagerESM = { ConsoleManager, updateSyncConsole, addConsoleLine: addLine };
  }
  
  // Función para exponer ConsoleManager con múltiples métodos de fallback
  function exposeConsoleManager() {
    if (typeof window === 'undefined') {
      return false;
    }
  
    if (typeof ConsoleManager === 'undefined' || !ConsoleManager) {
      return false;
    }
  
    // Método 1: Asignación directa
    try {
      window.ConsoleManager = ConsoleManager;
      window.updateSyncConsole = updateSyncConsole;
      window.addConsoleLine = addLine;
      
      // Verificar que se expuso correctamente
      if (typeof window.ConsoleManager !== 'undefined' && window.ConsoleManager === ConsoleManager) {
        return true;
      }
    } catch (error) {
      // ✅ MEJORADO: Registrar error usando ErrorHandler en lugar de silenciarlo
      if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
        window.ErrorHandler.logError(
          `Error al exponer ConsoleManager (método 1 - asignación directa): ${error.message || error}`,
          'CONSOLE_MANAGER_EXPOSE'
        );
      }
      // Intentar siguiente método
    }
  
    // Método 2: Object.defineProperty
    try {
      Object.defineProperty(window, 'ConsoleManager', {
        value: ConsoleManager,
        writable: true,
        enumerable: true,
        configurable: true
      });
      Object.defineProperty(window, 'updateSyncConsole', {
        value: updateSyncConsole,
        writable: true,
        enumerable: true,
        configurable: true
      });
      Object.defineProperty(window, 'addConsoleLine', {
        value: addLine,
        writable: true,
        enumerable: true,
        configurable: true
      });
      
      // Verificar que se expuso correctamente
      if (typeof window.ConsoleManager !== 'undefined') {
        return true;
      }
    } catch (defineError) {
      // ✅ SEGURIDAD: No usar eval como fallback. Si ambos métodos fallan, registrar error y retornar false
      /* eslint-disable no-console */
      if (typeof console !== 'undefined' && console.warn) {
        console.warn('[ConsoleManager] ⚠️ No se pudo exponer ConsoleManager usando métodos seguros:', defineError);
      }
      /* eslint-enable no-console */
    }
  
    // ✅ SEGURIDAD: No usar eval. Si los métodos seguros fallan, simplemente retornar false
    // Es mejor no exponer el objeto que usar eval, que es un riesgo de seguridad
    return false;
  }
  
  // Intentar exponer inmediatamente
  try {
    if (!exposeConsoleManager()) {
      // Si falla, intentar de nuevo después de un breve delay
      setTimeout(function() {
        try {
          exposeConsoleManager();
        } catch (timeoutError) {
          // ✅ MEJORADO: Registrar error usando ErrorHandler en lugar de silenciarlo
          if (typeof window !== 'undefined' && window.ErrorHandler && typeof window.ErrorHandler.logError === 'function') {
            window.ErrorHandler.logError(
              `Error al exponer ConsoleManager (timeout): ${timeoutError.message || timeoutError}`,
              'CONSOLE_MANAGER_EXPOSE'
            );
          }
        }
      }, 50);
    }
  } catch (exposeError) {
    // Solo loggear error crítico
    if (typeof console !== 'undefined' && console.error) {
      console.error('[ConsoleManager] ❌ Error crítico al exponer:', exposeError);
    }
  }
  
  /**
   * ✅ INICIALIZACIÓN AUTOMÁTICA: DESHABILITADA
   * 
   * NOTA: La inicialización automática está deshabilitada porque dashboard.js
   * ya inicializa ConsoleManager en initializeUIComponents().
   * 
   * Si se necesita inicialización automática independiente, descomentar el código siguiente.
   * 
   * ✅ MEJORADO: El código comentado ahora incluye:
   * - Verificación de dependencias (jQuery, ConsoleManager, PollingManager)
   * - Uso de jQuery(document).ready() como método preferido
   * - Fallback a DOMContentLoaded si jQuery no está disponible
   * - Timeout de seguridad para evitar esperas infinitas
   * - Manejo de errores con ErrorHandler
   * 
   * IMPORTANTE: Asegúrate de que todas las dependencias estén listas antes de habilitar.
   */
  
  // ✅ CÓDIGO DE INICIALIZACIÓN AUTOMÁTICA: DESHABILITADO
  // 
  // El código de inicialización automática ha sido removido para evitar errores de parsing.
  // Si necesitas habilitar la inicialización automática, puedes:
  // 
  // 1. Agregar la inicialización en dashboard.js (recomendado)
  // 2. Crear un archivo separado con el código de inicialización automática
  // 3. Usar el siguiente patrón como referencia:
  //
  // if (typeof jQuery !== 'undefined') {
  //   jQuery(document).ready(function() {
  //     if (typeof ConsoleManager !== 'undefined' && ConsoleManager && typeof ConsoleManager.initialize === 'function') {
  //       // Verificar dependencias antes de inicializar
  //       if (typeof window !== 'undefined' && window.pollingManager) {
  //         ConsoleManager.initialize();
  //       } else {
  //         // Esperar a que PollingManager esté disponible (máximo 5 segundos)
  //         let attempts = 0;
  //         const maxAttempts = 50;
  //         const checkPollingManager = setInterval(function() {
  //           attempts++;
  //           if (typeof window !== 'undefined' && window.pollingManager) {
  //             clearInterval(checkPollingManager);
  //             ConsoleManager.initialize();
  //           } else if (attempts >= maxAttempts) {
  //             clearInterval(checkPollingManager);
  //             if (window.ErrorHandler) {
  //               window.ErrorHandler.logError(
  //                 'ConsoleManager: Timeout esperando PollingManager',
  //                 'CONSOLE_MANAGER_AUTO_INIT'
  //               );
  //             }
  //           }
  //         }, 100);
  //       }
  //     }
  //   });
  // } else if (typeof window !== 'undefined' && typeof window.addEventListener !== 'undefined') {
  //   window.addEventListener('DOMContentLoaded', function() {
  //     setTimeout(function() {
  //       if (typeof jQuery !== 'undefined' && typeof ConsoleManager !== 'undefined' && 
  //           ConsoleManager && typeof ConsoleManager.initialize === 'function') {
  //         ConsoleManager.initialize();
  //       }
  //     }, 200);
  //   });
  // }
  
  // NOTA: El código anterior está comentado para evitar errores de parsing.
  // Si necesitas habilitarlo, cópialo a un archivo separado o descoméntalo cuidadosamente.
  
  // ✅ La exportación de CommonJS ya se maneja arriba en la sección de módulos
})(); // ✅ Cerrar el IIFE
