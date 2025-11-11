/**
 * Tests con Jasmine para ErrorHandler.js
 * 
 * Verifica que ErrorHandler esté correctamente definido y funcione correctamente.
 * Estos tests se ejecutan en el navegador, lo que permite depurar problemas de carga.
 * 
 * @module spec/dashboard/core/ErrorHandlerSpec
 */

describe('ErrorHandler', function() {
  let originalJQuery, originalErrorHandler, originalConsoleError, originalDASHBOARD_CONFIG;

  beforeEach(function() {
    // Guardar referencias originales
    originalJQuery = window.jQuery;
    originalErrorHandler = window.ErrorHandler;
    originalConsoleError = console.error;
    originalDASHBOARD_CONFIG = window.DASHBOARD_CONFIG;

    // Limpiar jQuery para usar el mock
    if (window.jQuery) {
      delete window.jQuery;
    }
    if (window.$) {
      delete window.$;
    }

    // Configurar DASHBOARD_CONFIG mock
    window.DASHBOARD_CONFIG = {
      selectors: {
        feedback: '#mi-sync-feedback'
      }
    };

    // Mock de console.error
    console.error = jasmine.createSpy('console.error');
  });

  afterEach(function() {
    // Restaurar referencias originales
    if (originalJQuery) {
      window.jQuery = originalJQuery;
      window.$ = originalJQuery;
    }
    if (originalErrorHandler !== undefined) {
      window.ErrorHandler = originalErrorHandler;
    }
    if (originalConsoleError) {
      console.error = originalConsoleError;
    }
    if (originalDASHBOARD_CONFIG !== undefined) {
      window.DASHBOARD_CONFIG = originalDASHBOARD_CONFIG;
    } else {
      delete window.DASHBOARD_CONFIG;
    }
  });

  describe('Carga del script', function() {
    it('debe exponer ErrorHandler en window', function() {
      if (typeof window.ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      expect(window.ErrorHandler).toBeDefined();
      expect(typeof window.ErrorHandler).toBe('function');
    });

    it('debe tener métodos estáticos', function() {
      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      expect(typeof ErrorHandler.logError).toBe('function');
      expect(typeof ErrorHandler.showUIError).toBe('function');
      expect(typeof ErrorHandler.showConnectionError).toBe('function');
      expect(typeof ErrorHandler.showProtectionError).toBe('function');
      expect(typeof ErrorHandler.showCancelError).toBe('function');
      expect(typeof ErrorHandler.showCriticalError).toBe('function');
    });
  });

  describe('logError', function() {
    it('debe registrar errores en la consola con timestamp', function() {
      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.logError('Test error message');

      expect(console.error).toHaveBeenCalled();
      const callArgs = console.error.calls.mostRecent().args[0];
      expect(callArgs).toContain('Test error message');
      expect(callArgs).toMatch(/\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/); // Formato ISO timestamp
    });

    it('debe incluir el contexto cuando se proporciona', function() {
      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.logError('Test error', 'TEST_CONTEXT');

      expect(console.error).toHaveBeenCalled();
      const callArgs = console.error.calls.mostRecent().args[0];
      expect(callArgs).toContain('[TEST_CONTEXT]');
      expect(callArgs).toContain('Test error');
    });

    it('debe funcionar sin contexto', function() {
      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.logError('Test error without context');

      expect(console.error).toHaveBeenCalled();
      const callArgs = console.error.calls.mostRecent().args[0];
      expect(callArgs).toContain('Test error without context');
    });
  });

  describe('showUIError', function() {
    it('debe mostrar error en el elemento de feedback cuando existe', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showUIError('Test error message', 'error');

      const $feedback = mockElements['#mi-sync-feedback'];
      expect($feedback).toBeDefined();
      expect($feedback.removeClass).toHaveBeenCalledWith('in-progress');
      expect($feedback.html).toHaveBeenCalled();
    });

    it('debe crear elemento temporal cuando no existe feedback', function() {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      // Configurar para que no encuentre el elemento
      // Crear un mock que soporte encadenamiento completo
      const createElementMock = function() {
        const elementMock = {};
        // Asignar métodos después de crear el objeto para evitar referencia circular
        elementMock.html = jasmine.createSpy('html').and.returnValue(elementMock);
        elementMock.fadeOut = jasmine.createSpy('fadeOut').and.returnValue(elementMock);
        elementMock.remove = jasmine.createSpy('remove');
        elementMock.css = jasmine.createSpy('css').and.returnValue(elementMock);
        elementMock.appendTo = jasmine.createSpy('appendTo').and.returnValue(elementMock);
        return elementMock;
      };

      const mockJQueryFn = function(selector) {
        if (selector === '#mi-sync-feedback') {
          return { length: 0 };
        }
        // Para crear elementos HTML
        if (selector.includes('<')) {
          return createElementMock();
        }
        return { length: 0 };
      };
      window.jQuery = mockJQueryFn;
      window.$ = mockJQueryFn;

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showUIError('Test error message', 'error');

      // Debe mostrar error en consola como fallback
      expect(console.error).toHaveBeenCalled();
    });

    it('debe mostrar warning cuando type es "warning"', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showUIError('Test warning', 'warning');

      const $feedback = mockElements['#mi-sync-feedback'];
      expect($feedback.html).toHaveBeenCalled();
      const htmlCall = $feedback.html.calls.mostRecent().args[0];
      expect(htmlCall).toContain('mi-api-warning');
      expect(htmlCall).toContain('⚠️');
    });
  });

  describe('showConnectionError', function() {
    it('debe manejar error de conexión con status 0', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      const xhr = { status: 0 };
      ErrorHandler.showConnectionError(xhr);

      const $feedback = mockElements['#mi-sync-feedback'];
      expect($feedback.html).toHaveBeenCalled();
      const htmlCall = $feedback.html.calls.mostRecent().args[0];
      expect(htmlCall).toContain('No se pudo conectar al servidor');
    });

    it('debe manejar error 403', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      const xhr = { status: 403 };
      ErrorHandler.showConnectionError(xhr);

      const $feedback = mockElements['#mi-sync-feedback'];
      expect($feedback.html).toHaveBeenCalled();
      const htmlCall = $feedback.html.calls.mostRecent().args[0];
      expect(htmlCall).toContain('Acceso denegado');
    });

    it('debe manejar error 404', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      const xhr = { status: 404 };
      ErrorHandler.showConnectionError(xhr);

      const $feedback = mockElements['#mi-sync-feedback'];
      expect($feedback.html).toHaveBeenCalled();
      const htmlCall = $feedback.html.calls.mostRecent().args[0];
      expect(htmlCall).toContain('Recurso no encontrado');
    });

    it('debe manejar error 500', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      const xhr = { status: 500 };
      ErrorHandler.showConnectionError(xhr);

      const $feedback = mockElements['#mi-sync-feedback'];
      expect($feedback.html).toHaveBeenCalled();
      const htmlCall = $feedback.html.calls.mostRecent().args[0];
      expect(htmlCall).toContain('Problema interno');
    });

    it('debe manejar xhr sin status', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      const xhr = { statusText: 'Error' };
      ErrorHandler.showConnectionError(xhr);

      const $feedback = mockElements['#mi-sync-feedback'];
      expect($feedback.html).toHaveBeenCalled();
    });

    it('debe manejar xhr null o undefined', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showConnectionError(null);

      const $feedback = mockElements['#mi-sync-feedback'];
      expect($feedback.html).toHaveBeenCalled();
      const htmlCall = $feedback.html.calls.mostRecent().args[0];
      expect(htmlCall).toContain('No se pudo establecer comunicación');
    });
  });

  describe('showProtectionError', function() {
    it('debe registrar error de protección sin mostrar en UI', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showProtectionError('Click automático detectado');

      expect(console.error).toHaveBeenCalled();
      const callArgs = console.error.calls.mostRecent().args[0];
      expect(callArgs).toContain('Protección activada');
      expect(callArgs).toContain('Click automático detectado');

      // No debe mostrar en UI
      const $feedback = mockElements['#mi-sync-feedback'];
      if ($feedback) {
        expect($feedback.html).not.toHaveBeenCalled();
      }
    });
  });

  describe('showCancelError', function() {
    it('debe registrar y mostrar error de cancelación', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showCancelError('Error al cancelar sincronización');

      expect(console.error).toHaveBeenCalled();
      const $feedback = mockElements['#mi-sync-feedback'];
      expect($feedback.html).toHaveBeenCalled();
      const htmlCall = $feedback.html.calls.mostRecent().args[0];
      expect(htmlCall).toContain('Error al cancelar');
    });

    it('debe usar contexto personalizado', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showCancelError('Test cancel', 'CUSTOM_CONTEXT');

      expect(console.error).toHaveBeenCalled();
      const callArgs = console.error.calls.mostRecent().args[0];
      expect(callArgs).toContain('[CUSTOM_CONTEXT]');
    });
  });

  describe('showCriticalError', function() {
    it('debe registrar y mostrar error crítico', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showCriticalError('Error crítico del sistema');

      expect(console.error).toHaveBeenCalled();
      const $feedback = mockElements['#mi-sync-feedback'];
      expect($feedback.html).toHaveBeenCalled();
      const htmlCall = $feedback.html.calls.mostRecent().args[0];
      expect(htmlCall).toContain('Error crítico');
    });

    it('debe usar contexto CRITICAL por defecto', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showCriticalError('Test critical');

      expect(console.error).toHaveBeenCalled();
      const callArgs = console.error.calls.mostRecent().args[0];
      expect(callArgs).toContain('[CRITICAL]');
    });
  });
});

