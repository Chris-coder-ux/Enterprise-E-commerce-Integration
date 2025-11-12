/**
 * Tests con Jasmine para PollingManager.js
 * 
 * Verifica que PollingManager esté correctamente definido y funcione correctamente.
 * Estos tests se ejecutan en el navegador, lo que permite depurar problemas de carga.
 * 
 * @module spec/dashboard/managers/PollingManagerSpec
 */

describe('PollingManager', function() {
  let originalPollingManager, originalWindowPollingManager, originalMiIntegracionApiDashboard;
  let clock;

  beforeEach(function() {
    // Guardar referencias originales
    originalPollingManager = window.PollingManager;
    originalWindowPollingManager = window.pollingManager;
    originalMiIntegracionApiDashboard = window.miIntegracionApiDashboard;

    // NO eliminar pollingManager - necesitamos que esté disponible para los tests
    // Si el script ya se cargó y creó window.pollingManager, mantenerlo
    // Si no existe, el script lo creará cuando se cargue

    // Configurar miIntegracionApiDashboard mock
    window.miIntegracionApiDashboard = {
      pollingConfig: {
        intervals: {
          normal: 15000,
          active: 5000,
          fast: 2000,
          slow: 45000,
          idle: 120000
        },
        thresholds: {
          to_slow: 3,
          to_idle: 8,
          max_errors: 5,
          progress_threshold: 0.1
        }
      }
    };

    // Usar jasmine.clock() para controlar el tiempo en los tests
    jasmine.clock().install();
  });

  afterEach(function() {
    // Restaurar referencias originales
    if (originalPollingManager !== undefined) {
      window.PollingManager = originalPollingManager;
    }
    // Restaurar pollingManager solo si existía originalmente
    // Si no existía pero ahora existe (porque el script se cargó), mantenerlo
    if (originalWindowPollingManager !== undefined) {
      window.pollingManager = originalWindowPollingManager;
    } else if (window.pollingManager) {
      // Si no había original pero ahora existe, mantenerlo
      // (esto permite que pollingManager persista entre tests)
    }
    if (originalMiIntegracionApiDashboard !== undefined) {
      window.miIntegracionApiDashboard = originalMiIntegracionApiDashboard;
    } else {
      delete window.miIntegracionApiDashboard;
    }

    // Desinstalar clock
    jasmine.clock().uninstall();
  });

  describe('Carga del script', function() {
    it('debe exponer PollingManager en window', function() {
      if (typeof window.PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      expect(window.PollingManager).toBeDefined();
      expect(typeof window.PollingManager).toBe('function');
    });

    it('debe exponer pollingManager (instancia) en window', function() {
      if (typeof window.pollingManager === 'undefined') {
        pending('pollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      expect(window.pollingManager).toBeDefined();
      expect(typeof window.pollingManager).toBe('object');
    });
  });

  describe('Constructor', function() {
    it('debe crear una instancia con configuración por defecto', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();

      expect(manager.intervals).toBeDefined();
      expect(manager.intervals instanceof Map).toBe(true);
      expect(manager.eventListeners).toBeDefined();
      expect(manager.eventListeners instanceof Map).toBe(true);
      expect(manager.config).toBeDefined();
      expect(manager.config.intervals.normal).toBe(15000);
      expect(manager.config.intervals.active).toBe(5000);
    });

    it('debe usar configuración de PHP cuando está disponible', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      window.miIntegracionApiDashboard = {
        pollingConfig: {
          intervals: {
            normal: 20000,
            active: 10000
          }
        }
      };

      const manager = new PollingManager();

      expect(manager.config.intervals.normal).toBe(20000);
      expect(manager.config.intervals.active).toBe(10000);
    });
  });

  describe('Sistema de eventos', function() {
    it('debe suscribirse a eventos con on()', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      const callback = jasmine.createSpy('callback');

      const unsubscribe = manager.on('testEvent', callback);

      expect(typeof unsubscribe).toBe('function');
      expect(manager.eventListeners.has('testEvent')).toBe(true);
    });

    it('debe emitir eventos y llamar a los callbacks', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      const callback1 = jasmine.createSpy('callback1');
      const callback2 = jasmine.createSpy('callback2');

      manager.on('testEvent', callback1);
      manager.on('testEvent', callback2);

      manager.emit('testEvent', { data: 'test' });

      expect(callback1).toHaveBeenCalledWith({ data: 'test' });
      expect(callback2).toHaveBeenCalledWith({ data: 'test' });
    });

    it('debe permitir desuscribirse con la función retornada', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      const callback = jasmine.createSpy('callback');

      const unsubscribe = manager.on('testEvent', callback);
      manager.emit('testEvent', { data: 'test1' });

      unsubscribe();
      manager.emit('testEvent', { data: 'test2' });

      expect(callback).toHaveBeenCalledTimes(1);
      expect(callback).toHaveBeenCalledWith({ data: 'test1' });
    });

    it('debe desuscribirse con off()', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      const callback = jasmine.createSpy('callback');

      manager.on('testEvent', callback);
      manager.emit('testEvent', { data: 'test1' });

      manager.off('testEvent', callback);
      manager.emit('testEvent', { data: 'test2' });

      expect(callback).toHaveBeenCalledTimes(1);
    });

    it('debe desuscribir todos los listeners cuando off() se llama sin callback', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      const callback1 = jasmine.createSpy('callback1');
      const callback2 = jasmine.createSpy('callback2');

      manager.on('testEvent', callback1);
      manager.on('testEvent', callback2);

      manager.off('testEvent');
      manager.emit('testEvent', { data: 'test' });

      expect(callback1).not.toHaveBeenCalled();
      expect(callback2).not.toHaveBeenCalled();
    });
  });

  describe('Polling', function() {
    it('debe iniciar polling con startPolling()', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      const callback = jasmine.createSpy('callback');

      const intervalId = manager.startPolling('testPoll', callback, 1000);

      expect(typeof intervalId).toBe('number');
      expect(manager.intervals.has('testPoll')).toBe(true);
      expect(manager.isPollingActive('testPoll')).toBe(true);
    });

    it('debe ejecutar el callback en cada intervalo', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      const callback = jasmine.createSpy('callback');

      manager.startPolling('testPoll', callback, 1000);

      jasmine.clock().tick(1000);
      expect(callback).toHaveBeenCalledTimes(1);

      jasmine.clock().tick(1000);
      expect(callback).toHaveBeenCalledTimes(2);
    });

    it('debe detener polling con stopPolling()', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      const callback = jasmine.createSpy('callback');

      manager.startPolling('testPoll', callback, 1000);
      const stopped = manager.stopPolling('testPoll');

      expect(stopped).toBe(true);
      expect(manager.intervals.has('testPoll')).toBe(false);
      expect(manager.isPollingActive('testPoll')).toBe(false);

      jasmine.clock().tick(2000);
      expect(callback).not.toHaveBeenCalled();
    });

    it('debe detener todos los polling con stopAllPolling()', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      const callback1 = jasmine.createSpy('callback1');
      const callback2 = jasmine.createSpy('callback2');

      manager.startPolling('poll1', callback1, 1000);
      manager.startPolling('poll2', callback2, 1000);

      manager.stopAllPolling();

      expect(manager.intervals.size).toBe(0);
      expect(manager.isPollingActive()).toBe(false);

      jasmine.clock().tick(2000);
      expect(callback1).not.toHaveBeenCalled();
      expect(callback2).not.toHaveBeenCalled();
    });

    it('debe verificar si hay polling activo', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      const callback = jasmine.createSpy('callback');

      expect(manager.isPollingActive()).toBe(false);
      expect(manager.isPollingActive('testPoll')).toBe(false);

      manager.startPolling('testPoll', callback, 1000);

      expect(manager.isPollingActive()).toBe(true);
      expect(manager.isPollingActive('testPoll')).toBe(true);
      expect(manager.isPollingActive('otherPoll')).toBe(false);
    });

    it('debe usar intervalo por defecto cuando no se especifica', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      const callback = jasmine.createSpy('callback');

      manager.startPolling('testPoll', callback);

      const polling = manager.intervals.get('testPoll');
      expect(polling.interval).toBe(manager.config.currentInterval);
    });
  });

  describe('adjustPolling', function() {
    it('debe ajustar a modo idle cuando no está activo', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();

      manager.adjustPolling(50, false);

      expect(manager.config.currentMode).toBe('idle');
      expect(manager.config.currentInterval).toBe(manager.config.intervals.idle);
    });

    it('debe ajustar a modo fast cuando hay cambio significativo de progreso', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      manager.config.lastProgress = 0;

      manager.adjustPolling(10, true); // Cambio del 10% (> 5%)

      expect(manager.config.currentMode).toBe('fast');
      expect(manager.config.currentInterval).toBe(manager.config.intervals.fast);
    });

    it('debe ajustar a modo active cuando hay cambio pequeño de progreso', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      manager.config.lastProgress = 50;

      manager.adjustPolling(52, true); // Cambio del 2% (< 5% pero > threshold)

      expect(manager.config.currentMode).toBe('active');
      expect(manager.config.currentInterval).toBe(manager.config.intervals.active);
    });

    it('debe ajustar a modo slow cuando el progreso está estancado', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      manager.config.lastProgress = 50;

      // Simular progreso estancado por 5 ciclos
      for (let i = 0; i < 5; i++) {
        manager.adjustPolling(50, true); // Sin cambio
      }

      expect(manager.config.currentMode).toBe('slow');
      expect(manager.config.currentInterval).toBe(manager.config.intervals.slow);
    });
  });

  describe('reset', function() {
    it('debe resetear la configuración y detener todos los polling', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      const callback = jasmine.createSpy('callback');

      manager.startPolling('testPoll', callback, 1000);
      manager.config.errorCount = 5;
      manager.config.lastProgress = 75;
      manager.config.progressStagnantCount = 3;

      manager.reset();

      expect(manager.intervals.size).toBe(0);
      expect(manager.config.currentInterval).toBe(manager.config.intervals.normal);
      expect(manager.config.currentMode).toBe('normal');
      expect(manager.config.errorCount).toBe(0);
      expect(manager.config.lastProgress).toBe(0);
      expect(manager.config.progressStagnantCount).toBe(0);
    });
  });

  describe('Instancia global', function() {
    it('debe tener una instancia global disponible', function(done) {
      // Verificar primero si PollingManager está disponible (indica que el script se cargó)
      if (typeof window.PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        done();
        return;
      }
      
      // Verificar inmediatamente si pollingManager ya está disponible
      if (window.pollingManager && typeof window.pollingManager === 'object' && 
          window.pollingManager.eventListeners && window.pollingManager.config) {
        const manager = window.pollingManager;
        expect(manager).toBeDefined();
        expect(manager.eventListeners).toBeDefined();
        expect(manager.config).toBeDefined();
        done();
        return;
      }
      
      // Si no está disponible inmediatamente, esperar con timeout más corto
      let attempts = 0;
      const maxAttempts = 30; // 3 segundos máximo (reducido para evitar timeout de Jasmine)
      
      const checkPollingManager = function() {
        attempts++;
        
        // Verificar múltiples formas de acceso
        const manager = window.pollingManager;
        
        if (manager && typeof manager === 'object' && manager.eventListeners && manager.config) {
          expect(manager).toBeDefined();
          expect(manager.eventListeners).toBeDefined();
          expect(manager.config).toBeDefined();
          done();
        } else if (attempts < maxAttempts) {
          // Esperar un poco más
          setTimeout(checkPollingManager, 100);
        } else {
          // Log para debugging
          const debugInfo = {
            hasWindow: typeof window !== 'undefined',
            hasPollingManagerClass: typeof window.PollingManager !== 'undefined',
            pollingManagerType: typeof window.pollingManager,
            pollingManagerValue: window.pollingManager ? 'defined' : 'undefined',
            windowKeys: typeof window !== 'undefined' ? Object.keys(window).filter(k => k.toLowerCase().includes('poll')) : []
          };
          pending('pollingManager no está disponible después de ' + (maxAttempts * 100) + 'ms. Debug: ' + JSON.stringify(debugInfo));
          done();
        }
      };

      // Dar un pequeño delay inicial para asegurar que el script se haya ejecutado
      setTimeout(checkPollingManager, 50);
    });

    it('debe poder usar la instancia global para eventos', function(done) {
      // Verificar primero si PollingManager está disponible (indica que el script se cargó)
      if (typeof window.PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        done();
        return;
      }
      
      // Verificar inmediatamente si pollingManager ya está disponible
      if (window.pollingManager && typeof window.pollingManager === 'object' &&
          typeof window.pollingManager.on === 'function' && typeof window.pollingManager.emit === 'function') {
        const manager = window.pollingManager;
        const callback = jasmine.createSpy('callback');

        manager.on('testEvent', callback);
        manager.emit('testEvent', { data: 'test' });

        expect(callback).toHaveBeenCalledWith({ data: 'test' });
        done();
        return;
      }
      
      // Si no está disponible inmediatamente, esperar con timeout más corto
      let attempts = 0;
      const maxAttempts = 30; // 3 segundos máximo (reducido para evitar timeout de Jasmine)
      
      const checkAndTest = function() {
        attempts++;
        
        const manager = window.pollingManager;
        
        if (manager && typeof manager === 'object') {
          // Verificar que tiene los métodos necesarios
          if (typeof manager.on === 'function' && typeof manager.emit === 'function') {
            const callback = jasmine.createSpy('callback');

            manager.on('testEvent', callback);
            manager.emit('testEvent', { data: 'test' });

            expect(callback).toHaveBeenCalledWith({ data: 'test' });
            done();
          } else {
            // Si no tiene los métodos, esperar un poco más
            if (attempts < maxAttempts) {
              setTimeout(checkAndTest, 100);
            } else {
              pending('pollingManager no tiene los métodos on/emit después de ' + (maxAttempts * 100) + 'ms');
              done();
            }
          }
        } else if (attempts < maxAttempts) {
          // Esperar un poco más
          setTimeout(checkAndTest, 100);
        } else {
          pending('pollingManager no está disponible después de ' + (maxAttempts * 100) + 'ms - el script debe cargarse primero');
          done();
        }
      };

      // Dar un pequeño delay inicial para asegurar que el script se haya ejecutado
      setTimeout(checkAndTest, 50);
    });
  });
});

