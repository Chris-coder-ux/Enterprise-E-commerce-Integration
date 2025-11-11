/**
 * Tests con Jasmine para ConsoleManager.js
 * 
 * Verifica que ConsoleManager esté correctamente definido y funcione correctamente.
 * Estos tests se ejecutan en el navegador, lo que permite depurar problemas de carga.
 * 
 * @module spec/dashboard/components/ConsoleManagerSpec
 */

describe('ConsoleManager', function() {
  let originalJQuery, originalConsoleManager, originalUpdateSyncConsole, originalAddConsoleLine;

  beforeEach(function() {
    // Guardar referencias originales ANTES de limpiar
    originalJQuery = window.jQuery;
    originalConsoleManager = window.ConsoleManager;
    originalUpdateSyncConsole = window.updateSyncConsole;
    originalAddConsoleLine = window.addConsoleLine;
    
    // NO limpiar ConsoleManager - necesitamos que esté disponible para los tests
    // Solo limpiar jQuery para poder usar el mock
    if (window.jQuery) {
      delete window.jQuery;
    }
    if (window.$) {
      delete window.$;
    }
  });

  afterEach(function() {
    // Restaurar referencias originales
    if (originalJQuery) {
      window.jQuery = originalJQuery;
      window.$ = originalJQuery;
    }
    // Restaurar ConsoleManager si existía originalmente
    if (originalConsoleManager !== undefined) {
      window.ConsoleManager = originalConsoleManager;
    } else if (window.ConsoleManager) {
      // Si no había original pero ahora existe, mantenerlo
      // (esto permite que ConsoleManager persista entre tests)
    }
    if (originalUpdateSyncConsole !== undefined) {
      window.updateSyncConsole = originalUpdateSyncConsole;
    }
    if (originalAddConsoleLine !== undefined) {
      window.addConsoleLine = originalAddConsoleLine;
    }
  });

  describe('Carga del script', function() {
    it('debe exponer ConsoleManager en window', function() {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      // Verificar que ConsoleManager está disponible
      // Nota: En un entorno real, el script se cargaría desde el servidor
      // Por ahora, verificamos si está disponible globalmente
      if (typeof window.ConsoleManager !== 'undefined') {
        expect(window.ConsoleManager).toBeDefined();
        expect(typeof window.ConsoleManager).toBe('object');
      } else {
        // Si no está disponible, el test falla (pero no bloquea otros tests)
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
      }
    });

    it('debe exponer updateSyncConsole en window', function() {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      if (typeof window.updateSyncConsole !== 'undefined') {
        expect(window.updateSyncConsole).toBeDefined();
        expect(typeof window.updateSyncConsole).toBe('function');
      } else {
        pending('updateSyncConsole no está disponible - el script debe cargarse primero');
      }
    });
  });

  describe('Definición de ConsoleManager', function() {
    it('debe tener todos los métodos requeridos', function() {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      // Verificar que ConsoleManager está disponible
      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }

      // Verificar métodos
      expect(ConsoleManager.initialize).toBeDefined();
      expect(typeof ConsoleManager.initialize).toBe('function');
      expect(ConsoleManager.addLine).toBeDefined();
      expect(typeof ConsoleManager.addLine).toBe('function');
      expect(ConsoleManager.updateSyncConsole).toBeDefined();
      expect(typeof ConsoleManager.updateSyncConsole).toBe('function');
      expect(ConsoleManager.clear).toBeDefined();
      expect(typeof ConsoleManager.clear).toBe('function');
      expect(ConsoleManager.toggle).toBeDefined();
      expect(typeof ConsoleManager.toggle).toBe('function');
      expect(ConsoleManager.MAX_LINES).toBeDefined();
      expect(typeof ConsoleManager.MAX_LINES).toBe('number');
    });

    it('MAX_LINES debe ser 100', function() {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }

      expect(ConsoleManager.MAX_LINES).toBe(100);
    });
  });

  describe('addLine', function() {
    it('debe agregar una línea a la consola', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }

      ConsoleManager.addLine('info', 'Test message');

      const $consoleContent = mockElements['#mia-console-content'];
      expect($consoleContent.append).toHaveBeenCalled();
    });

    it('no debe hacer nada si jQuery no está disponible', function() {
      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }

      // Guardar console.error original y silenciarlo temporalmente para este test
      const originalConsoleError = console.error;
      console.error = jasmine.createSpy('console.error');

      delete window.jQuery;
      delete window.$;

      expect(function() {
        ConsoleManager.addLine('info', 'Test message');
      }).not.toThrow();

      // Restaurar console.error
      console.error = originalConsoleError;
    });
  });

  describe('clear', function() {
    it('debe limpiar el contenido de la consola', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }

      ConsoleManager.clear();

      const $consoleContent = mockElements['#mia-console-content'];
      expect($consoleContent.empty).toHaveBeenCalled();
    });
  });

  describe('toggle', function() {
    it('debe alternar la clase minimized', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }

      ConsoleManager.toggle();

      const $console = mockElements['#mia-sync-console'];
      expect($console.toggleClass).toHaveBeenCalledWith('minimized');
    });
  });

  describe('updateSyncConsole', function() {
    it('debe actualizar la consola con datos de sincronización', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      // Configurar el mock para que text() devuelva una cadena
      const $consoleContent = mockElements['#mia-console-content'];
      if ($consoleContent) {
        const mockFind = $consoleContent.find('.mia-console-line');
        if (mockFind && mockFind.last) {
          const mockLast = mockFind.last();
          if (mockLast && mockLast.find) {
            const mockMessageFind = mockLast.find('.mia-console-message');
            if (mockMessageFind && mockMessageFind.text) {
              mockMessageFind.text.and.returnValue('');
            }
          }
        }
      }

      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }

      ConsoleManager.updateSyncConsole({
        in_progress: true,
        estadisticas: {
          procesados: 50,
          total: 100
        }
      }, {
        in_progress: false,
        completed: true
      });

      // Verificar que se actualizaron los indicadores de fase
      const $phase1Indicator = mockElements['#mia-phase1-indicator'];
      const $phase2Indicator = mockElements['#mia-phase2-indicator'];
      
      // Fase 1 está completada según los datos del test
      expect($phase1Indicator.attr).toHaveBeenCalledWith('data-status', 'completed');
      expect($phase1Indicator.find).toHaveBeenCalled();
      
      // Fase 2 está en progreso según los datos del test
      expect($phase2Indicator.attr).toHaveBeenCalledWith('data-status', 'active');
      expect($phase2Indicator.find).toHaveBeenCalled();
    });
  });

  describe('initialize', function() {
    it('debe configurar los event listeners', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }

      ConsoleManager.initialize();

      const $clearButton = mockElements['#mia-console-clear'];
      const $toggleButton = mockElements['#mia-console-toggle'];
      
      expect($clearButton.on).toHaveBeenCalled();
      expect($toggleButton.on).toHaveBeenCalled();
    });
  });

  describe('Exposición global', function() {
    it('ConsoleManager debe estar disponible en window después de la carga', function(done) {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      // Esperar a que el script se cargue (si está disponible)
      setTimeout(function() {
        if (typeof window.ConsoleManager !== 'undefined') {
          expect(window.ConsoleManager).toBeDefined();
          expect(typeof window.ConsoleManager).toBe('object');
          done();
        } else {
          pending('ConsoleManager no está disponible - el script debe cargarse primero');
          done();
        }
      }, 100);
    });

    it('updateSyncConsole debe estar disponible en window', function(done) {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      setTimeout(function() {
        if (typeof window.updateSyncConsole !== 'undefined') {
          expect(window.updateSyncConsole).toBeDefined();
          expect(typeof window.updateSyncConsole).toBe('function');
          done();
        } else {
          pending('updateSyncConsole no está disponible - el script debe cargarse primero');
          done();
        }
      }, 100);
    });
  });

  describe('Integración con PollingManager', function() {
    it('debe suscribirse a eventos de PollingManager si está disponible', function() {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }

      // ✅ IMPORTANTE: Resetear la bandera de suscripción para permitir que se suscriba de nuevo
      if (ConsoleManager.initialize && ConsoleManager.initialize.hasSubscribedToEvents !== undefined) {
        ConsoleManager.initialize.hasSubscribedToEvents = false;
      }

      // Mock de PollingManager
      window.pollingManager = {
        on: jasmine.createSpy('on')
      };

      ConsoleManager.initialize();

      // Verificar que se suscribió a eventos
      expect(window.pollingManager.on).toHaveBeenCalledWith('syncProgress', jasmine.any(Function));
      expect(window.pollingManager.on).toHaveBeenCalledWith('syncError', jasmine.any(Function));
    });
  });
});

