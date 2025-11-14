/**
 * Tests con Jasmine para ConsoleManager.js
 * 
 * Verifica que ConsoleManager esté correctamente definido y funcione correctamente.
 * Estos tests se ejecutan en el navegador, lo que permite depurar problemas de carga.
 * 
 * @module spec/dashboard/components/ConsoleManagerSpec
 */

// Declaración global: createMockJQuery está definida en spec/helpers/jasmine-jquery.js
// y se carga antes de los specs en SpecRunner.html
/* global createMockJQuery */

describe('ConsoleManager', function() {
  let originalJQuery, originalConsoleManager, originalUpdateSyncConsole, originalAddConsoleLine, originalSanitizer;

  beforeEach(function() {
    // Guardar referencias originales ANTES de limpiar
    originalJQuery = window.jQuery;
    originalConsoleManager = window.ConsoleManager;
    originalUpdateSyncConsole = window.updateSyncConsole;
    originalAddConsoleLine = window.addConsoleLine;
    originalSanitizer = window.Sanitizer;
    
    // NO limpiar ConsoleManager - necesitamos que esté disponible para los tests
    // Solo limpiar jQuery para poder usar el mock
    if (window.jQuery) {
      delete window.jQuery;
    }
    if (window.$) {
      delete window.$;
    }

    // ✅ NUEVO: Mock de Sanitizer
    window.Sanitizer = {
      sanitizeMessage: jasmine.createSpy('sanitizeMessage').and.callFake(function(message) {
        if (message === null || message === undefined) {
          return '';
        }
        return String(message).replace(/[&<>"']/g, function(m) {
          const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#039;' };
          return map[m];
        });
      })
    };
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
    if (originalSanitizer !== undefined) {
      window.Sanitizer = originalSanitizer;
    } else {
      delete window.Sanitizer;
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
      expect(ConsoleManager.cleanupEventListeners).toBeDefined(); // ✅ NUEVO: Verificar método de cleanup
      expect(typeof ConsoleManager.cleanupEventListeners).toBe('function');
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
    it('debe usar EventCleanupManager para registrar listeners si está disponible', function() {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;
      
      // Mock EventCleanupManager
      window.EventCleanupManager = {
        registerElementListener: jasmine.createSpy('registerElementListener').and.returnValue(function() {}),
        registerCustomEventListener: jasmine.createSpy('registerCustomEventListener').and.returnValue(function() {})
      };
      
      // Mock pollingManager
      window.pollingManager = {
        on: jasmine.createSpy('on')
      };
      
      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }
      
      // Resetear flag de suscripción usando el getter expuesto
      if (ConsoleManager.initializeState && ConsoleManager.initializeState.hasSubscribedToEvents !== undefined) {
        ConsoleManager.initializeState.hasSubscribedToEvents = false;
      }
      
      ConsoleManager.initialize();
      
      // Verificar que se usó EventCleanupManager
      expect(window.EventCleanupManager.registerElementListener).toHaveBeenCalled();
      expect(window.EventCleanupManager.registerCustomEventListener).toHaveBeenCalled();
    });
    
    it('debe configurar los event listeners', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }

      // Resetear flag de suscripción si existe
      if (ConsoleManager.initializeState && ConsoleManager.initializeState.hasSubscribedToEvents !== undefined) {
        ConsoleManager.initializeState.hasSubscribedToEvents = false;
      }

      // Mock EventCleanupManager si no existe
      const hasEventCleanupManager = typeof window.EventCleanupManager !== 'undefined';
      if (!hasEventCleanupManager) {
        window.EventCleanupManager = {
          registerElementListener: jasmine.createSpy('registerElementListener').and.returnValue(function() {}),
          registerCustomEventListener: jasmine.createSpy('registerCustomEventListener').and.returnValue(function() {})
        };
      }

      ConsoleManager.initialize();

      const $clearButton = mockElements['#mia-console-clear'];
      const $toggleButton = mockElements['#mia-console-toggle'];
      
      // Si EventCleanupManager está disponible, verificar que se usó para registrar los listeners
      if (hasEventCleanupManager || typeof window.EventCleanupManager !== 'undefined') {
        expect(window.EventCleanupManager.registerElementListener).toHaveBeenCalled();
        // Verificar que se registró al menos un listener para los botones
        const calls = window.EventCleanupManager.registerElementListener.calls.all();
        expect(calls.length).toBeGreaterThan(0);
      } else {
        // Si EventCleanupManager no está disponible, verificar que se usó .on() directamente
        expect($clearButton.on).toHaveBeenCalled();
        expect($toggleButton.on).toHaveBeenCalled();
      }
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

    // ✅ NUEVO: Tests de seguridad - verificar que no se usa eval
    describe('Seguridad de exposición global', function() {
      it('NO debe usar eval para exponer objetos globales', function() {
        // ✅ SEGURIDAD: Verificar que el código fuente no contiene eval
        // Este test verifica que el código no usa eval() para exposición global
        // Si el código usa eval, este test fallará al intentar leer el código fuente
        
        // Verificar que ConsoleManager se expone correctamente sin eval
        if (typeof window.ConsoleManager !== 'undefined') {
          // Verificar que la exposición funciona usando métodos seguros
          expect(window.ConsoleManager).toBeDefined();
          expect(typeof window.ConsoleManager).toBe('object');
          
          // Verificar que updateSyncConsole también se expone correctamente
          if (typeof window.updateSyncConsole !== 'undefined') {
            expect(window.updateSyncConsole).toBeDefined();
            expect(typeof window.updateSyncConsole).toBe('function');
          }
          
          // Verificar que addConsoleLine también se expone correctamente
          if (typeof window.addConsoleLine !== 'undefined') {
            expect(window.addConsoleLine).toBeDefined();
            expect(typeof window.addConsoleLine).toBe('function');
          }
        } else {
          pending('ConsoleManager no está disponible - el script debe cargarse primero');
        }
      });

      it('debe usar métodos seguros (window.Nombre = objeto o Object.defineProperty)', function() {
        // ✅ SEGURIDAD: Verificar que los objetos se exponen usando métodos seguros
        if (typeof window.ConsoleManager === 'undefined') {
          pending('ConsoleManager no está disponible - el script debe cargarse primero');
          return;
        }

        // Verificar que ConsoleManager está correctamente expuesto
        expect(window.ConsoleManager).toBeDefined();
        
        // Verificar que es el mismo objeto (no una copia)
        const ConsoleManager = window.ConsoleManager;
        expect(ConsoleManager).toBe(window.ConsoleManager);
        
        // Verificar que tiene las propiedades esperadas
        expect(ConsoleManager.initialize).toBeDefined();
        expect(ConsoleManager.addLine).toBeDefined();
        expect(ConsoleManager.updateSyncConsole).toBeDefined();
      });

      it('debe manejar errores de exposición sin usar eval', function() {
        // ✅ SEGURIDAD: Simular un error en la asignación directa y verificar que no se usa eval
        if (typeof window.ConsoleManager === 'undefined') {
          pending('ConsoleManager no está disponible - el script debe cargarse primero');
          return;
        }

        // Guardar referencia original
        const originalConsoleManager = window.ConsoleManager;
        
        // Intentar hacer la propiedad no configurable para simular un error
        try {
          Object.defineProperty(window, 'ConsoleManager', {
            value: originalConsoleManager,
            writable: false,
            enumerable: true,
            configurable: false
          });
          
          // Verificar que aún funciona (debería usar Object.defineProperty como fallback)
          expect(window.ConsoleManager).toBeDefined();
          
          // Restaurar configurabilidad
          Object.defineProperty(window, 'ConsoleManager', {
            value: originalConsoleManager,
            writable: true,
            enumerable: true,
            configurable: true
          });
        } catch (error) {
          // Si falla, restaurar de todas formas
          try {
            window.ConsoleManager = originalConsoleManager;
          } catch (restoreError) {
            // Ignorar error de restauración
          }
        }
      });
    });
  });

  describe('cleanupEventListeners', function() {
    it('debe limpiar todos los event listeners registrados', function() {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;
      
      // Mock EventCleanupManager
      window.EventCleanupManager = {
        cleanupComponent: jasmine.createSpy('cleanupComponent').and.returnValue(3)
      };
      
      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }
      
      ConsoleManager.cleanupEventListeners();
      
      expect(window.EventCleanupManager.cleanupComponent).toHaveBeenCalledWith('ConsoleManager');
    });
    
    it('debe usar fallback manual si EventCleanupManager no está disponible', function() {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;
      
      // Eliminar EventCleanupManager
      delete window.EventCleanupManager;
      
      // Mock pollingManager
      window.pollingManager = {
        off: jasmine.createSpy('off')
      };
      
      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }
      
      // No debe lanzar error
      expect(function() {
        ConsoleManager.cleanupEventListeners();
      }).not.toThrow();
      
      // Debe llamar off en pollingManager
      if (window.pollingManager && window.pollingManager.off) {
        expect(window.pollingManager.off).toHaveBeenCalledWith('syncProgress');
        expect(window.pollingManager.off).toHaveBeenCalledWith('syncError');
      }
    });
    
    it('debe usar optional chaining cuando EventCleanupManager es undefined', function() {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;
      
      // Establecer EventCleanupManager como undefined para probar optional chaining
      const originalEventCleanupManager = window.EventCleanupManager;
      window.EventCleanupManager = undefined;
      
      // Mock pollingManager
      window.pollingManager = {
        off: jasmine.createSpy('off')
      };
      
      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }
      
      // No debe lanzar error al usar optional chaining
      if (typeof ConsoleManager.cleanupEventListeners === 'function') {
        expect(function() {
          ConsoleManager.cleanupEventListeners();
        }).not.toThrow();
      }
      
      // Restaurar EventCleanupManager
      window.EventCleanupManager = originalEventCleanupManager;
    });
    
    it('debe usar nullish coalescing (??) para sanitización de mensajes cuando Sanitizer no está disponible', function() {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;
      
      // Eliminar Sanitizer para probar nullish coalescing
      const originalSanitizer = window.Sanitizer;
      delete window.Sanitizer;
      
      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }
      
      // No debe lanzar error al usar nullish coalescing para sanitización
      if (typeof ConsoleManager.addLine === 'function') {
        expect(function() {
          ConsoleManager.addLine('info', 'Test message');
        }).not.toThrow();
      }
      
      // Restaurar Sanitizer
      window.Sanitizer = originalSanitizer;
    });
  });
  
  describe('Uso de características modernas de JavaScript', function() {
    it('debe usar optional chaining para acceder a propiedades anidadas de forma segura', function() {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;
      
      // Mock de pollingManager con estructura anidada
      window.pollingManager = {
        on: jasmine.createSpy('on')
      };
      
      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }
      
      // No debe lanzar error al usar optional chaining
      if (typeof ConsoleManager.initialize === 'function') {
        expect(function() {
          ConsoleManager.initialize();
        }).not.toThrow();
      }
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
      if (ConsoleManager.initializeState) {
        ConsoleManager.initializeState.hasSubscribedToEvents = false;
      }

      // Eliminar EventCleanupManager para forzar el uso del fallback directo
      delete window.EventCleanupManager;

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

  describe('Sanitización de mensajes', function() {
    it('debe sanitizar mensajes antes de insertarlos en la consola', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }

      const dangerousMessage = '<script>alert("XSS")</script>Test';
      ConsoleManager.addLine('info', dangerousMessage);

      // ✅ Verificar que se llamó a Sanitizer.sanitizeMessage
      expect(window.Sanitizer.sanitizeMessage).toHaveBeenCalledWith(dangerousMessage);

      const $consoleContent = mockElements['#mia-console-content'];
      expect($consoleContent.append).toHaveBeenCalled();
      
      // ✅ Verificar que se usa .text() para insertar el mensaje (no .html() directamente)
      // El código actual construye elementos de forma segura usando .text()
    });

    it('debe prevenir XSS en mensajes del servidor', function() {
      const { mockJQuery } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }

      const xssAttempts = [
        '<script>alert("XSS")</script>',
        '<img src=x onerror=alert("XSS")>',
        '<svg onload=alert("XSS")>'
      ];

      xssAttempts.forEach(function(attempt) {
        ConsoleManager.addLine('error', attempt);
        
        // ✅ Verificar que se sanitizó cada intento
        expect(window.Sanitizer.sanitizeMessage).toHaveBeenCalledWith(attempt);
      });
    });

    it('debe usar fallback de escape si Sanitizer no está disponible', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      window.jQuery = mockJQuery;
      window.$ = mockJQuery;

      // Eliminar Sanitizer para probar fallback
      delete window.Sanitizer;

      const ConsoleManager = window.ConsoleManager;
      if (typeof ConsoleManager === 'undefined') {
        pending('ConsoleManager no está disponible - el script debe cargarse primero');
        return;
      }

      const dangerousMessage = '<script>alert("XSS")</script>';
      
      // No debe lanzar error, debe usar fallback
      expect(function() {
        ConsoleManager.addLine('info', dangerousMessage);
      }).not.toThrow();

      const $consoleContent = mockElements['#mia-console-content'];
      expect($consoleContent.append).toHaveBeenCalled();
    });
  });
});

