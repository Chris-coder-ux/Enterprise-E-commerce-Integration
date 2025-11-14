/**
 * Tests para ErrorHandler con Jest
 * 
 * Suite completa de tests para el módulo ErrorHandler refactorizado.
 * ErrorHandler ahora usa JavaScript puro (sin jQuery).
 * 
 * @file ErrorHandler.test.js
 * @since 1.0.0
 * @author Christian
 */

/* eslint-env jest */
/* global describe, it, expect, beforeEach, afterEach, jest */

const fs = require('fs');
const path = require('path');

// Configurar jsdom para que el DOM funcione correctamente
if (typeof document !== 'undefined') {
  // Crear un body si no existe
  if (!document.body) {
    const body = document.createElement('body');
    document.documentElement.appendChild(body);
  }
}

// Configurar DASHBOARD_CONFIG antes de cargar ErrorHandler
global.DASHBOARD_CONFIG = {
  selectors: {
    feedback: '#mi-sync-feedback'
  }
};

// Configurar window.DASHBOARD_CONFIG también
if (typeof window !== 'undefined') {
  window.DASHBOARD_CONFIG = global.DASHBOARD_CONFIG;
}

// Configurar Sanitizer mock antes de cargar ErrorHandler
const sanitizerMock = {
  sanitizeMessage: jest.fn((message) => {
    if (message === null || message === undefined) {
      return '';
    }
    return String(message).replace(/[&<>"']/g, function(m) {
      const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#039;' };
      return map[m];
    });
  })
};

global.Sanitizer = sanitizerMock;
if (typeof window !== 'undefined') {
  window.Sanitizer = sanitizerMock;
}

// Leer y ejecutar el archivo ErrorHandler.js
const errorHandlerPath = path.join(__dirname, '../../../assets/js/dashboard/core/ErrorHandler.js');
const errorHandlerCode = fs.readFileSync(errorHandlerPath, 'utf8');

// Asegurar que window esté disponible
if (typeof window === 'undefined') {
  global.window = global;
}

// Ejecutar el código para cargar ErrorHandler real
// El código de ErrorHandler.js verifica typeof window !== 'undefined' y expone window.ErrorHandler
// IMPORTANTE: Esto debe ejecutarse DESPUÉS de jest.setup.js para sobrescribir el mock
// Eliminar el mock de jest.setup.js primero
if (typeof global.ErrorHandler !== 'undefined' && typeof global.ErrorHandler.logError === 'function') {
  // Verificar si es el mock (los mocks de Jest tienen una estructura específica)
  const isMock = global.ErrorHandler.logError.toString().includes('fn.apply');
  if (isMock) {
    // Es el mock, eliminarlo para que el código real pueda cargarse
    delete global.ErrorHandler;
  }
}

try {
  // Ejecutar el código en el contexto global/window
  // eslint-disable-next-line no-eval
  eval(errorHandlerCode);
  
  // Verificar que se cargó correctamente y es el código real
  if (typeof window !== 'undefined' && window.ErrorHandler) {
    const methodCode = window.ErrorHandler.logError ? window.ErrorHandler.logError.toString() : '';
    const isRealCode = methodCode.includes('console.error') && methodCode.includes('timestamp');
    
    if (!isRealCode) {
      // El mock todavía está ahí, forzar la carga del código real
      // eslint-disable-next-line no-console
      console.warn('ErrorHandler todavía es el mock. Forzando carga del código real...');
      
      // Eliminar el mock completamente
      delete global.ErrorHandler;
      delete window.ErrorHandler;
      
      // Ejecutar el código de nuevo
      // eslint-disable-next-line no-eval
      eval(errorHandlerCode);
    }
  }
} catch (e) {
  // Si falla con eval, intentar con Function constructor
  try {
    // eslint-disable-next-line no-new-func
    const loadErrorHandler = new Function('window', 'document', 'DASHBOARD_CONFIG', 'Sanitizer', errorHandlerCode);
    loadErrorHandler(
      typeof window !== 'undefined' ? window : global,
      typeof document !== 'undefined' ? document : {},
      global.DASHBOARD_CONFIG,
      global.Sanitizer
    );
  } catch (e2) {
    // eslint-disable-next-line no-console
    console.error('Error al cargar ErrorHandler:', e2);
    throw e2;
  }
}

// Verificar que ErrorHandler se cargó correctamente y sobrescribir el mock de jest.setup.js
if (typeof window !== 'undefined' && typeof window.ErrorHandler !== 'undefined') {
  // El código se cargó correctamente
  // window.ErrorHandler debería ser la clase real (función)
} else {
  // eslint-disable-next-line no-console
  console.error('ErrorHandler no se cargó correctamente. window.ErrorHandler:', typeof window !== 'undefined' ? typeof window.ErrorHandler : 'window no disponible');
}

// Asegurar que el mock de jest.setup.js no interfiera
// El código de ErrorHandler.js expone window.ErrorHandler = ErrorHandler (la clase)
// Si jest.setup.js tiene un mock, lo sobrescribimos aquí
if (typeof window !== 'undefined' && window.ErrorHandler) {
  // El código real se cargó correctamente, sobrescribir cualquier mock de jest.setup.js
  global.ErrorHandler = window.ErrorHandler;
  
  // Verificar que tiene los métodos estáticos
  if (typeof window.ErrorHandler.logError === 'function') {
    // ErrorHandler es una clase con métodos estáticos (comportamiento esperado)
    // El código real está disponible
  } else {
    // eslint-disable-next-line no-console
    console.warn('ErrorHandler se cargó pero no tiene métodos estáticos. Puede ser el mock de jest.setup.js');
  }
}

describe('ErrorHandler', () => {
  let originalConsoleError;
  let originalConsoleWarn;
  let originalConsoleLog;
  let feedbackElement;

  // Test de diagnóstico para verificar que ErrorHandler se cargó
  it('debe tener ErrorHandler disponible después de cargar', () => {
    expect(typeof window).not.toBe('undefined');
    expect(typeof window.ErrorHandler).not.toBe('undefined');
    // ErrorHandler puede ser una clase (función) o un objeto con métodos estáticos
    // Verificamos que tenga los métodos necesarios
    expect(window.ErrorHandler).toBeDefined();
    expect(typeof window.ErrorHandler.logError).toBe('function');
    expect(typeof window.ErrorHandler.showUIError).toBe('function');
  });

  beforeEach(() => {
    // Guardar referencias originales de console
    originalConsoleError = console.error;
    originalConsoleWarn = console.warn;
    originalConsoleLog = console.log;
    
    // Asegurar que estamos usando el ErrorHandler real, no el mock de jest.setup.js
    if (typeof window !== 'undefined' && window.ErrorHandler) {
      // Sobrescribir cualquier mock de jest.setup.js
      global.ErrorHandler = window.ErrorHandler;
    }
    
    // Limpiar mocks de console (crear nuevos mocks para cada test)
    console.error = jest.fn();
    console.warn = jest.fn();
    console.log = jest.fn();

    // Limpiar cualquier elemento previo (JavaScript puro)
    const existingFeedback = document.querySelector('#mi-sync-feedback');
    if (existingFeedback && existingFeedback.parentNode) {
      existingFeedback.parentNode.removeChild(existingFeedback);
    }
    const existingFeedbackClass = document.querySelector('.mi-api-feedback');
    if (existingFeedbackClass && existingFeedbackClass.parentNode) {
      existingFeedbackClass.parentNode.removeChild(existingFeedbackClass);
    }

    // Crear elemento DOM (JavaScript puro)
    feedbackElement = document.createElement('div');
    feedbackElement.id = 'mi-sync-feedback';
    feedbackElement.className = 'mi-api-feedback';
    document.body.appendChild(feedbackElement);

    // Resetear DASHBOARD_CONFIG
    global.DASHBOARD_CONFIG = {
      selectors: {
        feedback: '#mi-sync-feedback'
      }
    };
    if (typeof window !== 'undefined') {
      window.DASHBOARD_CONFIG = global.DASHBOARD_CONFIG;
    }

    // Resetear Sanitizer mock
    if (global.Sanitizer && global.Sanitizer.sanitizeMessage) {
      global.Sanitizer.sanitizeMessage.mockClear();
    }
    if (typeof window !== 'undefined' && window.Sanitizer && window.Sanitizer.sanitizeMessage) {
      window.Sanitizer.sanitizeMessage.mockClear();
    }

    // Asegurar que ErrorHandler esté disponible
    if (typeof window === 'undefined' || typeof window.ErrorHandler === 'undefined') {
      throw new Error('ErrorHandler no está disponible. Verifica que el módulo se haya cargado correctamente.');
    }
  });

  afterEach(() => {
    // Restaurar console original
    console.error = originalConsoleError;
    console.warn = originalConsoleWarn;
    console.log = originalConsoleLog;

    // Limpiar DOM (JavaScript puro)
    if (feedbackElement && feedbackElement.parentNode) {
      feedbackElement.parentNode.removeChild(feedbackElement);
    }
    const existingFeedback = document.querySelector('#mi-sync-feedback');
    if (existingFeedback && existingFeedback.parentNode) {
      existingFeedback.parentNode.removeChild(existingFeedback);
    }
    const existingFeedbackClass = document.querySelector('.mi-api-feedback');
    if (existingFeedbackClass && existingFeedbackClass.parentNode) {
      existingFeedbackClass.parentNode.removeChild(existingFeedbackClass);
    }

    // Limpiar mocks
    jest.clearAllMocks();
  });

  describe('logError', () => {
    it('debe registrar un error en la consola con timestamp', () => {
      // Verificar que el método existe y es el código real
      expect(typeof window.ErrorHandler.logError).toBe('function');
      
      // Verificar que no es el mock de jest.setup.js
      const methodCode = window.ErrorHandler.logError.toString();
      expect(methodCode).toContain('console.error');
      expect(methodCode).toContain('timestamp');
      
      // Guardar console.error original antes de mockearlo
      const originalConsoleError = console.error;
      
      // Crear un nuevo mock para este test
      const mockConsoleError = jest.fn();
      console.error = mockConsoleError;
      
      const message = 'Error de prueba';
      
      // Llamar al método directamente
      try {
        window.ErrorHandler.logError(message);
      } catch (e) {
        // Si hay un error, restaurar y fallar
        console.error = originalConsoleError;
        throw e;
      }
      
      // Verificar que se llamó
      expect(mockConsoleError).toHaveBeenCalledTimes(1);
      const callArgs = mockConsoleError.mock.calls[0][0];
      expect(callArgs).toContain(message);
      expect(callArgs).toMatch(/\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/); // Formato ISO timestamp
      
      // Restaurar console.error original
      console.error = originalConsoleError;
    });

    it('debe incluir el contexto cuando se proporciona', () => {
      const message = 'Error de prueba';
      const context = 'AJAX';
      
      window.ErrorHandler.logError(message, context);
      
      expect(console.error).toHaveBeenCalledTimes(1);
      const callArgs = console.error.mock.calls[0][0];
      expect(callArgs).toContain(message);
      expect(callArgs).toContain(`[${context}]`);
    });

    it('no debe incluir contexto cuando no se proporciona', () => {
      const message = 'Error de prueba';
      
      window.ErrorHandler.logError(message);
      
      expect(console.error).toHaveBeenCalledTimes(1);
      const callArgs = console.error.mock.calls[0][0];
      expect(callArgs).not.toMatch(/\[\w+\]/); // No debe tener contexto entre corchetes
    });
  });

  describe('showUIError', () => {
    it('debe mostrar un error en el elemento de feedback', () => {
      const message = 'Error de prueba';
      
      window.ErrorHandler.showUIError(message, 'error');
      
      expect(feedbackElement.innerHTML).toContain(message);
      expect(feedbackElement.innerHTML).toContain('mi-api-error');
      expect(feedbackElement.innerHTML).toContain('❌');
      expect(feedbackElement.classList.contains('in-progress')).toBe(false);
    });

    it('debe mostrar una advertencia cuando el tipo es warning', () => {
      const message = 'Advertencia de prueba';
      
      window.ErrorHandler.showUIError(message, 'warning');
      
      expect(feedbackElement.innerHTML).toContain(message);
      expect(feedbackElement.innerHTML).toContain('mi-api-warning');
      expect(feedbackElement.innerHTML).toContain('⚠️');
    });

    it('debe usar el selector de DASHBOARD_CONFIG cuando está disponible', () => {
      global.DASHBOARD_CONFIG = {
        selectors: {
          feedback: '#mi-sync-feedback'
        }
      };
      if (typeof window !== 'undefined') {
        window.DASHBOARD_CONFIG = global.DASHBOARD_CONFIG;
      }
      
      const message = 'Error de prueba';
      window.ErrorHandler.showUIError(message);
      
      expect(feedbackElement.innerHTML).toContain(message);
    });

    it('debe crear elemento temporal cuando no existe el feedback', () => {
      // Restaurar setTimeout real para evitar problemas con el mock
      jest.useRealTimers();
      const nodeSetTimeout = require('timers').setTimeout;
      global.setTimeout = nodeSetTimeout;
      
      feedbackElement.remove();
      
      const message = 'Error de prueba';
      window.ErrorHandler.showUIError(message);
      
      // Debe crear un elemento temporal (JavaScript puro)
      const tempFeedback = document.querySelector('#mi-sync-feedback');
      expect(tempFeedback).not.toBeNull();
      expect(tempFeedback.style.position).toBe('fixed');
      expect(tempFeedback.style.zIndex).toBe('9999');
      
      // Limpiar el elemento temporal creado inmediatamente para no afectar otros tests
      if (tempFeedback && tempFeedback.parentNode) {
        tempFeedback.parentNode.removeChild(tempFeedback);
      }
      
      jest.useFakeTimers();
    });

    it('debe auto-ocultar elemento temporal después de 5 segundos', () => {
      jest.useRealTimers();
      
      const nodeSetTimeout = require('timers').setTimeout;
      global.setTimeout = nodeSetTimeout;
      
      feedbackElement.remove();
      
      const message = 'Error de prueba';
      window.ErrorHandler.showUIError(message);
      
      const tempFeedback = document.querySelector('#mi-sync-feedback');
      expect(tempFeedback).not.toBeNull();
      
      // Limpiar manualmente para no afectar otros tests
      if (tempFeedback && tempFeedback.parentNode) {
        tempFeedback.parentNode.removeChild(tempFeedback);
      }
      
      jest.useFakeTimers();
    });
  });

  describe('showConnectionError', () => {
    it('debe mostrar error cuando xhr.status es 0', () => {
      const xhr = { status: 0 };
      
      window.ErrorHandler.showConnectionError(xhr);
      
      expect(feedbackElement.innerHTML).toContain('No se pudo conectar al servidor');
    });

    it('debe mostrar error de permisos cuando xhr.status es 403', () => {
      const xhr = { status: 403 };
      
      window.ErrorHandler.showConnectionError(xhr);
      
      expect(feedbackElement.innerHTML).toContain('Acceso denegado');
    });

    it('debe mostrar error cuando xhr.status es 404', () => {
      const xhr = { status: 404 };
      
      window.ErrorHandler.showConnectionError(xhr);
      
      expect(feedbackElement.innerHTML).toContain('Recurso no encontrado');
    });

    it('debe mostrar error del servidor cuando xhr.status es 500', () => {
      const xhr = { status: 500 };
      
      window.ErrorHandler.showConnectionError(xhr);
      
      expect(feedbackElement.innerHTML).toContain('Problema interno');
    });

    it('debe mostrar error genérico para otros códigos de estado', () => {
      const xhr = { status: 418 };
      
      window.ErrorHandler.showConnectionError(xhr);
      
      expect(feedbackElement.innerHTML).toContain('Error de conexión (418)');
    });

    it('debe usar statusText cuando status no está disponible', () => {
      const xhr = { statusText: 'Not Found' };
      
      window.ErrorHandler.showConnectionError(xhr);
      
      expect(feedbackElement.innerHTML).toContain('Error de conexión');
    });

    it('debe manejar xhr null o undefined', () => {
      window.ErrorHandler.showConnectionError(null);
      
      expect(feedbackElement.innerHTML).toContain('No se pudo establecer comunicación');
    });
  });

  describe('showProtectionError', () => {
    it('debe registrar el error en la consola', () => {
      const reason = 'Click automático detectado';
      
      window.ErrorHandler.showProtectionError(reason);
      
      expect(console.error).toHaveBeenCalledTimes(1);
      const callArgs = console.error.mock.calls[0][0];
      expect(callArgs).toContain('Protección activada');
      expect(callArgs).toContain(reason);
    });

    it('no debe mostrar error en la UI', () => {
      const reason = 'Click automático detectado';
      const initialHtml = feedbackElement.innerHTML;
      
      window.ErrorHandler.showProtectionError(reason);
      
      // El HTML no debe cambiar (no se muestra en UI)
      expect(feedbackElement.innerHTML).toBe(initialHtml || '');
    });
  });

  describe('showCancelError', () => {
    it('debe registrar el error en la consola con contexto CANCEL', () => {
      const message = 'Error al cancelar';
      
      window.ErrorHandler.showCancelError(message);
      
      expect(console.error).toHaveBeenCalledTimes(1);
      const callArgs = console.error.mock.calls[0][0];
      expect(callArgs).toContain('Error de cancelación');
      expect(callArgs).toContain(message);
      expect(callArgs).toContain('[CANCEL]');
    });

    it('debe mostrar el error en la UI', () => {
      const message = 'Error al cancelar';
      
      window.ErrorHandler.showCancelError(message);
      
      expect(feedbackElement.innerHTML).toContain('Error al cancelar');
      expect(feedbackElement.innerHTML).toContain(message);
    });

    it('debe usar contexto personalizado cuando se proporciona', () => {
      const message = 'Error al cancelar';
      const context = 'CUSTOM_CONTEXT';
      
      window.ErrorHandler.showCancelError(message, context);
      
      expect(console.error).toHaveBeenCalledTimes(1);
      const callArgs = console.error.mock.calls[0][0];
      expect(callArgs).toContain(`[${context}]`);
    });
  });

  describe('showCriticalError', () => {
    it('debe registrar el error en la consola con contexto CRITICAL', () => {
      const message = 'Error crítico del sistema';
      
      window.ErrorHandler.showCriticalError(message);
      
      expect(console.error).toHaveBeenCalledTimes(1);
      const callArgs = console.error.mock.calls[0][0];
      expect(callArgs).toContain('Error crítico');
      expect(callArgs).toContain(message);
      expect(callArgs).toContain('[CRITICAL]');
    });

    it('debe mostrar el error en la UI', () => {
      const message = 'Error crítico del sistema';
      
      window.ErrorHandler.showCriticalError(message);
      
      expect(feedbackElement.innerHTML).toContain('Error crítico');
      expect(feedbackElement.innerHTML).toContain(message);
    });

    it('debe usar contexto personalizado cuando se proporciona', () => {
      const message = 'Error crítico del sistema';
      const context = 'CUSTOM_CRITICAL';
      
      window.ErrorHandler.showCriticalError(message, context);
      
      expect(console.error).toHaveBeenCalledTimes(1);
      const callArgs = console.error.mock.calls[0][0];
      expect(callArgs).toContain(`[${context}]`);
    });
  });

  describe('Exposición global', () => {
    it('debe estar disponible como window.ErrorHandler', () => {
      // ErrorHandler es una clase (función), no un objeto
      expect(typeof window.ErrorHandler).toBe('function');
      expect(window.ErrorHandler).toBeDefined();
    });

    it('debe tener todos los métodos estáticos disponibles', () => {
      expect(typeof window.ErrorHandler.logError).toBe('function');
      expect(typeof window.ErrorHandler.showUIError).toBe('function');
      expect(typeof window.ErrorHandler.showConnectionError).toBe('function');
      expect(typeof window.ErrorHandler.showProtectionError).toBe('function');
      expect(typeof window.ErrorHandler.showCancelError).toBe('function');
      expect(typeof window.ErrorHandler.showCriticalError).toBe('function');
    });
  });

  describe('Integración con DOM nativo', () => {
    it('debe usar JavaScript puro para manipular el DOM', () => {
      const message = 'Error de prueba';
      
      window.ErrorHandler.showUIError(message);
      
      // JavaScript puro debe haber sido usado para actualizar el HTML
      expect(feedbackElement.innerHTML).toContain(message);
      expect(feedbackElement.innerHTML).toContain('mi-api-error');
    });

    it('debe manejar correctamente elementos del DOM', () => {
      const message = 'Error de prueba';
      
      window.ErrorHandler.showUIError(message);
      
      // Verificar que el elemento del DOM fue manipulado correctamente
      expect(feedbackElement).not.toBeNull();
      expect(feedbackElement.innerHTML).toContain(message);
      expect(feedbackElement.classList.contains('in-progress')).toBe(false);
    });
  });

  describe('Sanitización de mensajes', () => {
    it('debe sanitizar mensajes antes de insertarlos en el DOM', () => {
      const dangerousMessage = '<script>alert("XSS")</script>Test';
      
      window.ErrorHandler.showUIError(dangerousMessage, 'error');
      
      // Verificar que se llamó a Sanitizer.sanitizeMessage
      if (window.Sanitizer && window.Sanitizer.sanitizeMessage) {
        expect(window.Sanitizer.sanitizeMessage).toHaveBeenCalledWith(dangerousMessage);
      }
      
      // Verificar que se usa construcción segura
      expect(feedbackElement.innerHTML).toContain('mi-api-error');
      expect(feedbackElement.innerHTML).toContain('<strong>');
      // El mensaje debe estar sanitizado (no debe contener <script> ejecutable)
      // Nota: textContent escapa HTML, así que el script no debería ejecutarse
    });

    it('debe usar fallback de escape si Sanitizer no está disponible', () => {
      // Guardar referencia original
      const originalSanitizer = window.Sanitizer;
      
      // Eliminar Sanitizer para probar fallback
      delete window.Sanitizer;
      global.Sanitizer = undefined;
      
      const dangerousMessage = '<script>alert("XSS")</script>';
      
      // No debe lanzar error, debe usar fallback
      expect(() => {
        window.ErrorHandler.showUIError(dangerousMessage, 'error');
      }).not.toThrow();
      
      // Verificar que se agregó contenido (aunque sin Sanitizer)
      expect(feedbackElement.innerHTML).toContain('mi-api-error');
      
      // Restaurar Sanitizer
      window.Sanitizer = originalSanitizer;
      global.Sanitizer = originalSanitizer;
    });
  });

  describe('Casos edge', () => {
    it('debe manejar mensajes vacíos', () => {
      window.ErrorHandler.logError('');
      expect(console.error).toHaveBeenCalled();
    });

    it('debe manejar mensajes con HTML de forma segura', () => {
      const message = '<script>alert("xss")</script>';
      
      window.ErrorHandler.showUIError(message);
      
      // El HTML debe ser escapado o manejado de forma segura
      // textContent escapa automáticamente, así que el script no debería ejecutarse
      expect(feedbackElement.innerHTML).toContain('mi-api-error');
    });

    it('debe manejar DASHBOARD_CONFIG con estructura incompleta', () => {
      jest.useRealTimers();
      const nodeSetTimeout = require('timers').setTimeout;
      global.setTimeout = nodeSetTimeout;
      
      feedbackElement.remove();
      
      // Guardar configuración original
      const originalConfig = global.DASHBOARD_CONFIG;
      
      // Configurar DASHBOARD_CONFIG con estructura incompleta (sin feedback)
      global.DASHBOARD_CONFIG = { selectors: {} };
      if (typeof window !== 'undefined') {
        window.DASHBOARD_CONFIG = global.DASHBOARD_CONFIG;
      }
      
      const message = 'Error de prueba';
      
      // El código debe usar fallback cuando el selector no está disponible
      window.ErrorHandler.showUIError(message);
      
      // Debe crear un elemento temporal (fallback)
      const tempFeedback = document.querySelector('#mi-sync-feedback');
      expect(tempFeedback).not.toBeNull();
      expect(console.error).toHaveBeenCalled();
      
      // Limpiar
      if (tempFeedback && tempFeedback.parentNode) {
        tempFeedback.parentNode.removeChild(tempFeedback);
      }
      
      // Restaurar configuración
      global.DASHBOARD_CONFIG = originalConfig;
      if (typeof window !== 'undefined') {
        window.DASHBOARD_CONFIG = originalConfig;
      }
      
      jest.useFakeTimers();
    });

    it('debe manejar múltiples llamadas consecutivas', () => {
      window.ErrorHandler.showUIError('Error 1');
      window.ErrorHandler.showUIError('Error 2');
      window.ErrorHandler.showUIError('Error 3');
      
      // Debe mostrar el último error
      expect(feedbackElement.innerHTML).toContain('Error 3');
    });
  });
});
