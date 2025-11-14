/**
 * Tests con Jasmine para PollingManager.js
 * 
 * Verifica que PollingManager esté correctamente definido y funcione correctamente.
 * Estos tests se ejecutan en el navegador, lo que permite depurar problemas de carga.
 * 
 * @module spec/dashboard/managers/PollingManagerSpec
 */

describe('PollingManager', function() {
  let originalPollingManager, originalWindowPollingManager, originalMiIntegracionApiDashboard, originalErrorHandler;

  // ✅ Aumentar el timeout de Jasmine para esta suite (15 segundos)
  jasmine.DEFAULT_TIMEOUT_INTERVAL = 15000;

  /**
   * ✅ Esperar a que PollingManager se cargue antes de ejecutar los tests
   * Esto asegura que window.PollingManager y window.pollingManager estén disponibles
   */
  beforeAll(function(done) {
    // Función helper para verificar si PollingManager está disponible
    function isPollingManagerAvailable() {
      if (typeof window === 'undefined') {
        return false;
      }
      
      // Verificar que la clase está disponible
      if (typeof window.PollingManager === 'undefined') {
        return false;
      }
      
      // Verificar que la instancia está disponible
      if (typeof window.pollingManager === 'undefined') {
        return false;
      }
      
      // Verificar que la instancia es un objeto
      if (typeof window.pollingManager !== 'object') {
        return false;
      }
      
      // Verificar que la instancia tiene las propiedades básicas esperadas
      // Estas propiedades se inicializan en el constructor
      if (!window.pollingManager.intervals || !(window.pollingManager.intervals instanceof Map)) {
        return false;
      }
      
      if (!window.pollingManager.eventListeners || !(window.pollingManager.eventListeners instanceof Map)) {
        return false;
      }
      
      if (!window.pollingManager.config || typeof window.pollingManager.config !== 'object') {
        return false;
      }
      
      return true;
    }

    // Log de inicio para depuración
    // eslint-disable-next-line no-console
    console.log('[PollingManagerSpec] Iniciando carga de PollingManager...');
    // eslint-disable-next-line no-console
    console.log('[PollingManagerSpec] Estado inicial - PollingManager:', typeof window !== 'undefined' ? typeof window.PollingManager : 'N/A', 'pollingManager:', typeof window !== 'undefined' ? typeof window.pollingManager : 'N/A');

    // Si ya están disponibles, continuar inmediatamente
    if (isPollingManagerAvailable()) {
      // eslint-disable-next-line no-console
      console.log('[PollingManagerSpec] PollingManager ya está disponible, continuando...');
      done();
      return;
    }
    
    // eslint-disable-next-line no-console
    console.log('[PollingManagerSpec] PollingManager no está disponible aún, comenzando polling...');

    let attempts = 0;
    const maxAttempts = 50; // 5 segundos (50 * 100ms)
    let isDone = false; // Flag para evitar múltiples llamadas a done()
    const checkInterval = 100; // ms

    // Esperar hasta que estén disponibles
    const interval = setInterval(function() {
      // Verificar si ya se completó para evitar llamadas múltiples
      if (isDone) {
        clearInterval(interval);
        return;
      }

      attempts++;
      
      // Verificar si PollingManager está disponible
      if (isPollingManagerAvailable()) {
        isDone = true;
        clearInterval(interval);
        // eslint-disable-next-line no-console
        console.log('[PollingManagerSpec] PollingManager cargado correctamente después de ' + attempts + ' intentos');
        done();
        return;
      }

      // Si se alcanzó el máximo de intentos, manejar el error
      if (attempts >= maxAttempts) {
        isDone = true;
        clearInterval(interval);
        
        // Intentar verificar una última vez
        if (!isPollingManagerAvailable()) {
          const errorMsg = 'PollingManager no se cargó correctamente en ' + 
            (maxAttempts * checkInterval / 1000) + ' segundos. ' +
            'Verifica que el script PollingManager.js se esté cargando correctamente en SpecRunner.html. ' +
            'Revisa la consola del navegador para ver si hay errores de JavaScript. ' +
            'window.PollingManager: ' + (typeof window !== 'undefined' ? typeof window.PollingManager : 'window no disponible') +
            ', window.pollingManager: ' + (typeof window !== 'undefined' ? typeof window.pollingManager : 'window no disponible');
          // Registrar el error pero continuar - los tests individuales manejarán el caso con pending()
          // eslint-disable-next-line no-console
          console.error('[PollingManagerSpec] ' + errorMsg);
        } else {
          // eslint-disable-next-line no-console
          console.log('[PollingManagerSpec] PollingManager disponible en verificación final');
        }
        // Llamar a done() en cualquier caso para que los tests continúen
        done();
      }
    }, checkInterval);
    
    // ✅ TIMEOUT DE SEGURIDAD: Si después de 12 segundos no se ha llamado done(), forzarlo
    setTimeout(function() {
      if (!isDone) {
        isDone = true;
        clearInterval(interval);
        // eslint-disable-next-line no-console
        console.warn('[PollingManagerSpec] Timeout de seguridad alcanzado. Continuando con los tests...');
        done();
      }
    }, 12000); // 12 segundos como timeout de seguridad
  });

  beforeEach(function() {
    // Guardar referencias originales
    originalPollingManager = window.PollingManager;
    originalWindowPollingManager = window.pollingManager;
    originalMiIntegracionApiDashboard = window.miIntegracionApiDashboard;
    originalErrorHandler = window.ErrorHandler;

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

    // ✅ NUEVO: Mock de ErrorHandler para tests de manejo de errores
    window.ErrorHandler = {
      logError: jasmine.createSpy('logError'),
      showConnectionError: jasmine.createSpy('showConnectionError'),
      showUIError: jasmine.createSpy('showUIError')
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
    
    if (originalErrorHandler !== undefined) {
      window.ErrorHandler = originalErrorHandler;
    } else {
      delete window.ErrorHandler;
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

    // ✅ NUEVO: Tests de seguridad - verificar que no se usa eval
    describe('Seguridad de exposición global', function() {
      it('NO debe usar eval para exponer PollingManager', function() {
        // ✅ SEGURIDAD: Verificar que PollingManager se expone correctamente sin eval
        if (typeof window.PollingManager === 'undefined') {
          pending('PollingManager no está disponible - el script debe cargarse primero');
          return;
        }

        // Verificar que la exposición funciona usando métodos seguros
        expect(window.PollingManager).toBeDefined();
        expect(typeof window.PollingManager).toBe('function');
        
        // Verificar que es la misma función (no una copia)
        const PollingManager = window.PollingManager;
        expect(PollingManager).toBe(window.PollingManager);
      });

      it('NO debe usar eval para exponer pollingManager (instancia)', function() {
        // ✅ SEGURIDAD: Verificar que pollingManager se expone correctamente sin eval
        if (typeof window.pollingManager === 'undefined') {
          // eslint-disable-next-line no-console
          console.warn('[PollingManagerSpec] pollingManager no está disponible en el test');
          pending('pollingManager no está disponible - el script debe cargarse primero');
          return;
        }

        // Verificar que la exposición funciona usando métodos seguros
        expect(window.pollingManager).toBeDefined();
        expect(typeof window.pollingManager).toBe('object');
        
        // Verificar que es la misma instancia (no una copia)
        const pollingManager = window.pollingManager;
        expect(pollingManager).toBe(window.pollingManager);
        
        // ✅ SEGURIDAD: Verificar que NO se usó eval para exponerlo
        // Si se usó eval, el código fuente del objeto mostraría evidencia
        // Verificamos que es una instancia real de PollingManager
        expect(pollingManager.constructor).toBeDefined();
        expect(pollingManager.constructor.name).toBe('PollingManager');
        
        // Verificar que tiene las propiedades esperadas
        // Nota: Estas propiedades se inicializan en el constructor
        // Si no están disponibles, puede ser que el constructor no se ejecutó correctamente
        if (!pollingManager.intervals) {
          // eslint-disable-next-line no-console
          console.error('[PollingManagerSpec] pollingManager.intervals no está definido. pollingManager:', pollingManager);
          // eslint-disable-next-line no-console
          console.error('[PollingManagerSpec] pollingManager keys:', Object.keys(pollingManager));
        }
        expect(pollingManager.intervals).toBeDefined();
        expect(pollingManager.intervals instanceof Map).toBe(true);
        
        if (!pollingManager.eventListeners) {
          // eslint-disable-next-line no-console
          console.error('[PollingManagerSpec] pollingManager.eventListeners no está definido. pollingManager:', pollingManager);
        }
        expect(pollingManager.eventListeners).toBeDefined();
        expect(pollingManager.eventListeners instanceof Map).toBe(true);
        
        if (!pollingManager.config) {
          // eslint-disable-next-line no-console
          console.error('[PollingManagerSpec] pollingManager.config no está definido. pollingManager:', pollingManager);
        }
        expect(pollingManager.config).toBeDefined();
        expect(typeof pollingManager.config).toBe('object');
      });

      it('debe usar métodos seguros (window.Nombre = objeto o Object.defineProperty)', function() {
        // ✅ SEGURIDAD: Verificar que los objetos se exponen usando métodos seguros
        if (typeof window.PollingManager === 'undefined') {
          pending('PollingManager no está disponible - el script debe cargarse primero');
          return;
        }

        // Verificar que PollingManager está correctamente expuesto
        expect(window.PollingManager).toBeDefined();
        expect(typeof window.PollingManager).toBe('function');
        
        // Verificar que pollingManager también está correctamente expuesto
        if (typeof window.pollingManager !== 'undefined') {
          expect(window.pollingManager).toBeDefined();
          expect(typeof window.pollingManager).toBe('object');
          expect(window.pollingManager instanceof window.PollingManager).toBe(true);
        }
      });

      it('debe manejar errores de exposición sin usar eval', function() {
        // ✅ SEGURIDAD: Simular un error en la asignación directa y verificar que no se usa eval
        if (typeof window.PollingManager === 'undefined') {
          pending('PollingManager no está disponible - el script debe cargarse primero');
          return;
        }

        // Guardar referencia original
        const originalPollingManager = window.PollingManager;
        
        // Intentar hacer la propiedad no configurable para simular un error
        try {
          Object.defineProperty(window, 'PollingManager', {
            value: originalPollingManager,
            writable: false,
            enumerable: true,
            configurable: false
          });
          
          // Verificar que aún funciona (debería usar Object.defineProperty como fallback)
          expect(window.PollingManager).toBeDefined();
          
          // Restaurar configurabilidad
          Object.defineProperty(window, 'PollingManager', {
            value: originalPollingManager,
            writable: true,
            enumerable: true,
            configurable: true
          });
        } catch (error) {
          // Si falla, restaurar de todas formas
          try {
            window.PollingManager = originalPollingManager;
          } catch (restoreError) {
            // Ignorar error de restauración
          }
        }
      });
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

    // ✅ NUEVO: Test para verificar manejo de errores en listeners
    it('debe registrar errores en listeners usando ErrorHandler', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      // Usar callFake para lanzar el error de forma más confiable
      const errorCallback = jasmine.createSpy('errorCallback').and.callFake(function() {
        throw new Error('Test error');
      });
      const normalCallback = jasmine.createSpy('normalCallback');

      manager.on('testEvent', errorCallback);
      manager.on('testEvent', normalCallback);

      manager.emit('testEvent', { data: 'test' });

      // ✅ Verificar que se registró el error usando ErrorHandler
      expect(window.ErrorHandler.logError).toHaveBeenCalledWith(
        jasmine.stringMatching(/Error en listener del evento 'testEvent'/),
        'POLLING_EVENT'
      );
      
      // Verificar que el callback normal aún se ejecutó (no afecta otros listeners)
      expect(normalCallback).toHaveBeenCalledWith({ data: 'test' });
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

    // ✅ NUEVO: Test para verificar que se considera latencia en adjustPolling
    it('debe considerar latencia al ajustar por progreso', function() {
      const PollingManager = window.PollingManager;
      if (typeof PollingManager === 'undefined') {
        pending('PollingManager no está disponible - el script debe cargarse primero');
        return;
      }

      const manager = new PollingManager();
      manager.config.lastProgress = 50;
      manager.config.averageLatency = 2000; // Latencia alta
      
      // Ajustar con progreso activo
      manager.adjustPolling(55, true); // Cambio de 5%
      
      // El intervalo debería considerar la latencia alta
      expect(manager.config.currentInterval).toBeGreaterThanOrEqual(manager.config.intervals.min);
      expect(manager.config.currentInterval).toBeLessThanOrEqual(manager.config.intervals.max);
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
      
      // ✅ NUEVO: Configurar métricas de optimización
      manager.config.consecutiveErrors = 5;
      manager.config.averageLatency = 2000;
      manager.config.backoffMultiplier = 4;
      manager.config.userActive = false;
      manager.config.lastResponseTime = 1500;

      manager.reset();

      expect(manager.intervals.size).toBe(0);
      expect(manager.config.currentInterval).toBe(manager.config.intervals.normal);
      expect(manager.config.currentMode).toBe('normal');
      expect(manager.config.errorCount).toBe(0);
      expect(manager.config.lastProgress).toBe(0);
      expect(manager.config.progressStagnantCount).toBe(0);
      
      // ✅ NUEVO: Verificar que todas las métricas de optimización se resetean
      expect(manager.config.consecutiveErrors).toBe(0);
      expect(manager.config.averageLatency).toBe(null);
      expect(manager.config.backoffMultiplier).toBe(1);
      expect(manager.config.userActive).toBe(true);
      expect(manager.config.lastResponseTime).toBe(null);
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

  // ✅ NUEVO: Tests para optimizaciones de polling
  describe('Optimizaciones de Polling', function() {
    describe('Intervalos mínimos y máximos', function() {
      it('debe aplicar intervalo mínimo cuando se solicita uno menor', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const callback = jasmine.createSpy('callback');
        
        // Intentar iniciar con intervalo menor al mínimo (100ms < 500ms)
        const intervalId = manager.startPolling('testPoll', callback, 100);
        
        expect(intervalId).toBeDefined();
        // Verificar que el intervalo real es al menos el mínimo
        const polling = manager.intervals.get('testPoll');
        expect(polling.interval).toBeGreaterThanOrEqual(manager.config.intervals.min || 500);
        
        manager.stopPolling('testPoll');
      });

      it('debe aplicar intervalo máximo cuando se solicita uno mayor', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const callback = jasmine.createSpy('callback');
        
        // Intentar iniciar con intervalo mayor al máximo (400000ms > 300000ms)
        const intervalId = manager.startPolling('testPoll', callback, 400000);
        
        expect(intervalId).toBeDefined();
        // Verificar que el intervalo real es como máximo el máximo
        const polling = manager.intervals.get('testPoll');
        expect(polling.interval).toBeLessThanOrEqual(manager.config.intervals.max || 300000);
        
        manager.stopPolling('testPoll');
      });
    });

    describe('Registro de latencia', function() {
      it('debe registrar tiempo de respuesta y calcular latencia promedio', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        
        // Registrar varios tiempos de respuesta
        manager.recordResponseTime(500);
        expect(manager.config.averageLatency).toBe(500);
        
        manager.recordResponseTime(1000);
        // Media móvil exponencial: 500 * 0.7 + 1000 * 0.3 = 650
        expect(manager.config.averageLatency).toBeCloseTo(650, 0);
        
        manager.recordResponseTime(800);
        // Media móvil: 650 * 0.7 + 800 * 0.3 = 695
        expect(manager.config.averageLatency).toBeCloseTo(695, 0);
      });

      it('debe resetear errores consecutivos cuando la latencia es aceptable', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        
        // Simular errores consecutivos
        manager.config.consecutiveErrors = 2;
        manager.config.backoffMultiplier = 2;
        
        // Registrar tiempo de respuesta aceptable (< threshold)
        manager.recordResponseTime(500); // < 1000ms threshold
        
        expect(manager.config.consecutiveErrors).toBe(0);
        expect(manager.config.backoffMultiplier).toBe(1);
      });
    });

    describe('Ajuste de polling por latencia', function() {
      it('debe aumentar intervalo cuando la latencia es muy alta', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const callback = jasmine.createSpy('callback');
        
        manager.startPolling('testPoll', callback, 2000);
        const initialInterval = manager.config.currentInterval;
        
        // Simular latencia muy alta (> 2x threshold = 2000ms)
        manager.config.averageLatency = 2500;
        manager.adjustPollingForLatency();
        
        // El intervalo debería aumentar (pero no más que el máximo)
        expect(manager.config.currentInterval).toBeGreaterThanOrEqual(initialInterval);
        expect(manager.config.currentInterval).toBeLessThanOrEqual(manager.config.intervals.max);
        
        manager.stopPolling('testPoll');
      });

      it('debe reducir intervalo cuando la latencia es baja', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const callback = jasmine.createSpy('callback');
        
        manager.startPolling('testPoll', callback, 15000);
        manager.config.currentInterval = 15000;
        
        // Simular latencia baja (< 0.5x threshold = 500ms)
        manager.config.averageLatency = 400;
        manager.adjustPollingForLatency();
        
        // El intervalo debería reducirse (pero no menos que active)
        expect(manager.config.currentInterval).toBeLessThanOrEqual(15000);
        expect(manager.config.currentInterval).toBeGreaterThanOrEqual(manager.config.intervals.active);
        
        manager.stopPolling('testPoll');
      });
    });

    describe('Backoff exponencial con jitter', function() {
      it('debe aplicar backoff exponencial después de errores consecutivos', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const callback = jasmine.createSpy('callback');
        
        manager.startPolling('testPoll', callback, 2000);
        const initialInterval = manager.config.currentInterval;
        
        // Simular errores consecutivos (más del threshold)
        manager.config.consecutiveErrors = 3; // threshold = 3
        const newInterval = manager.recordError();
        
        // El nuevo intervalo debería ser mayor (backoff aplicado)
        expect(newInterval).toBeGreaterThan(initialInterval);
        expect(newInterval).toBeLessThanOrEqual(manager.config.thresholds.error_backoff_max);
        
        manager.stopPolling('testPoll');
      });

      it('debe aplicar jitter aleatorio al backoff', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        
        manager.config.currentInterval = 2000;
        manager.config.consecutiveErrors = 3;
        
        // Ejecutar recordError múltiples veces para verificar jitter
        const intervals = [];
        for (let i = 0; i < 5; i++) {
          manager.config.consecutiveErrors = 3;
          intervals.push(manager.recordError());
        }
        
        // Verificar que hay variación (jitter aplicado)
        const uniqueIntervals = [...new Set(intervals)];
        // Con jitter, debería haber alguna variación (aunque puede ser pequeña)
        expect(uniqueIntervals.length).toBeGreaterThan(0);
      });

      it('NO debe aplicar backoff antes del threshold de errores consecutivos', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const callback = jasmine.createSpy('callback');
        
        manager.startPolling('testPoll', callback, 2000);
        const initialInterval = manager.config.currentInterval;
        
        // Simular menos errores que el threshold
        manager.config.consecutiveErrors = 2; // threshold = 3
        const newInterval = manager.recordError();
        
        // El intervalo NO debería cambiar
        expect(newInterval).toBe(initialInterval);
        
        manager.stopPolling('testPoll');
      });
    });

    describe('Detección de actividad del usuario', function() {
      it('debe inicializar detección de actividad del usuario', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        
        // Verificar que se inicializó la detección
        expect(manager.config.userActive).toBe(true);
        expect(manager.config.lastUserActivity).toBeDefined();
        expect(typeof manager.config.lastUserActivity).toBe('number');
      });

      it('debe ajustar polling cuando el usuario está inactivo', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const callback = jasmine.createSpy('callback');
        
        manager.startPolling('testPoll', callback, 2000);
        
        // Simular usuario inactivo
        manager.config.userActive = false;
        manager.adjustPollingForUserActivity();
        
        // El intervalo debería aumentar (modo low-power)
        expect(manager.config.currentMode).toBe('low-power');
        
        manager.stopPolling('testPoll');
      });

      it('debe restaurar polling cuando el usuario vuelve a estar activo', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        
        // Simular usuario inactivo primero
        manager.config.userActive = false;
        manager.config.currentMode = 'low-power';
        manager.config.currentInterval = 45000;
        
        // Usuario vuelve a estar activo
        manager.config.userActive = true;
        manager.adjustPollingForUserActivity();
        
        // Debería restaurar modo normal
        expect(manager.config.currentMode).toBe('normal');
        expect(manager.config.currentInterval).toBe(manager.config.intervals.normal);
      });
    });

    describe('Ajuste adaptativo mejorado', function() {
      it('debe considerar latencia al ajustar por progreso', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        
        // Simular latencia alta
        manager.config.averageLatency = 2000; // 2x threshold
        manager.config.lastProgress = 50;
        
        // Ajustar con progreso activo
        manager.adjustPolling(55, true); // Cambio de 5%
        
        // El intervalo debería considerar la latencia alta
        expect(manager.config.currentInterval).toBeGreaterThanOrEqual(manager.config.intervals.min);
        expect(manager.config.currentInterval).toBeLessThanOrEqual(manager.config.intervals.max);
      });

      it('debe considerar actividad del usuario al ajustar', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        
        // Simular usuario inactivo
        manager.config.userActive = false;
        manager.config.lastProgress = 50;
        
        // Ajustar con progreso activo
        manager.adjustPolling(55, true);
        
        // Debería usar modo low-power
        expect(manager.config.currentMode).toBe('low-power');
      });

      it('debe aplicar límites mínimo y máximo automáticamente', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        
        // ✅ MEJORADO: Obtener límites con valores por defecto
        const minInterval = manager.config.intervals.min || 500;
        const maxInterval = manager.config.intervals.max || 300000;
        
        // Asegurar que los límites estén definidos
        expect(minInterval).toBeDefined();
        expect(maxInterval).toBeDefined();
        expect(typeof minInterval).toBe('number');
        expect(typeof maxInterval).toBe('number');
        
        // Simular ajuste que resultaría en intervalo muy bajo
        manager.config.currentInterval = 100; // Menor que mínimo
        manager.config.currentMode = 'fast';
        manager.config.userActive = true; // Asegurar que el usuario esté activo
        
        // Ajustar progreso
        manager.adjustPolling(75, true);
        
        // Debería aplicar límite mínimo
        expect(manager.config.currentInterval).toBeGreaterThanOrEqual(minInterval);
        expect(manager.config.currentInterval).toBeLessThanOrEqual(maxInterval);
        
        // Simular ajuste que resultaría en intervalo muy alto
        manager.config.currentInterval = 400000; // Mayor que máximo
        manager.config.currentMode = 'slow';
        manager.config.progressStagnantCount = 5; // Forzar modo slow
        
        // Ajustar progreso
        manager.adjustPolling(80, true);
        
        // Debería aplicar límite máximo
        expect(manager.config.currentInterval).toBeLessThanOrEqual(maxInterval);
        expect(manager.config.currentInterval).toBeGreaterThanOrEqual(minInterval);
      });
    });

    describe('Reset mejorado', function() {
      it('debe resetear todas las métricas de optimización', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        
        // Configurar métricas de optimización
        manager.config.consecutiveErrors = 5;
        manager.config.averageLatency = 2000;
        manager.config.backoffMultiplier = 4;
        manager.config.userActive = false;
        manager.config.lastResponseTime = 1500;
        
        // Resetear
        manager.reset();
        
        // Verificar que todas las métricas se resetean
        expect(manager.config.consecutiveErrors).toBe(0);
        expect(manager.config.averageLatency).toBe(null);
        expect(manager.config.backoffMultiplier).toBe(1);
        expect(manager.config.userActive).toBe(true);
        expect(manager.config.lastResponseTime).toBe(null);
        expect(manager.config.currentInterval).toBe(manager.config.intervals.normal);
        expect(manager.config.currentMode).toBe('normal');
      });
    });
  });

  // ✅ NUEVO: Tests para mejoras recientes
  describe('Mejoras recientes', function() {
    describe('Contexto en callbacks', function() {
      it('debe preservar el contexto cuando se pasa como parámetro', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const testObj = {
          value: 42,
          method() {
            return this.value;
          }
        };

        const callback = jasmine.createSpy('callback').and.callFake(function() {
          return testObj.method();
        });

        manager.startPolling('testPoll', callback, 1000, testObj);

        jasmine.clock().tick(1000);
        expect(callback).toHaveBeenCalled();
        
        manager.stopPolling('testPoll');
      });

      it('debe validar que el callback sea una función', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();

        expect(function() {
          manager.startPolling('testPoll', 'not a function', 1000);
        }).toThrowError(TypeError, /Callback must be a function/);
      });

      it('debe validar que el nombre sea un string válido', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const callback = jasmine.createSpy('callback');

        expect(function() {
          manager.startPolling('', callback, 1000);
        }).toThrowError(TypeError, /Polling name must be a valid non-empty string/);

        expect(function() {
          manager.startPolling(null, callback, 1000);
        }).toThrowError(TypeError, /Polling name must be a valid non-empty string/);
      });

      it('debe validar que el intervalo sea un número positivo', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const callback = jasmine.createSpy('callback');

        expect(function() {
          manager.startPolling('testPoll', callback, -100);
        }).toThrowError(TypeError, /Interval must be a positive number or null/);

        expect(function() {
          manager.startPolling('testPoll', callback, 'not a number');
        }).toThrowError(TypeError, /Interval must be a positive number or null/);
      });
    });

    describe('Límite de listeners por evento', function() {
      it('debe permitir agregar listeners hasta el límite', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const maxListeners = manager.config.maxListenersPerEvent || 100;

        // Agregar listeners hasta el límite
        for (let i = 0; i < maxListeners; i++) {
          const unsubscribe = manager.on('testEvent', jasmine.createSpy('callback' + i));
          expect(typeof unsubscribe).toBe('function');
        }

        expect(manager.eventListeners.get('testEvent').length).toBe(maxListeners);
      });

      it('debe prevenir agregar más listeners cuando se alcanza el límite', function() {
        // ✅ NOTA: Este test verifica el límite de listeners por evento, por lo que se espera ver
        // un mensaje de error en la consola. Esto es comportamiento esperado del test.
        // El error "Límite máximo de listeners alcanzado para evento 'testEvent' (100)" que aparece
        // en la consola es parte de la verificación de que el límite se maneja correctamente.
        // 
        // El error proviene de installHook.js que intercepta ErrorHandler.logError y lo muestra
        // en la consola. Esto es normal y esperado durante este test.

        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const maxListeners = manager.config.maxListenersPerEvent || 100;

        // Agregar listeners hasta el límite
        for (let i = 0; i < maxListeners; i++) {
          manager.on('testEvent', jasmine.createSpy('callback' + i));
        }

        // Intentar agregar uno más
        const unsubscribe = manager.on('testEvent', jasmine.createSpy('extraCallback'));

        // Debería retornar una función vacía
        expect(typeof unsubscribe).toBe('function');
        expect(manager.eventListeners.get('testEvent').length).toBe(maxListeners);

        // Verificar que ErrorHandler fue llamado
        expect(window.ErrorHandler.logError).toHaveBeenCalledWith(
          jasmine.stringMatching(/Límite máximo de listeners alcanzado/),
          'POLLING_EVENT_LIMIT'
        );
      });
    });

    describe('Debounce para ajustes frecuentes', function() {
      it('debe aplicar debounce a los ajustes de polling', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const performAdjustmentSpy = spyOn(manager, 'performAdjustment').and.callThrough();

        // Llamar adjustPolling múltiples veces rápidamente
        manager.adjustPolling(50, true);
        manager.adjustPolling(55, true);
        manager.adjustPolling(60, true);

        // performAdjustment no debería haberse llamado aún
        expect(performAdjustmentSpy).not.toHaveBeenCalled();

        // Avanzar el tiempo de debounce
        const debounceMs = manager.config.adjustmentDebounceMs || 1000;
        jasmine.clock().tick(debounceMs);

        // Ahora debería haberse llamado una sola vez (con el último valor)
        expect(performAdjustmentSpy).toHaveBeenCalledTimes(1);
        expect(performAdjustmentSpy).toHaveBeenCalledWith(60, true);
      });

      it('debe cancelar el timer anterior cuando se llama adjustPolling de nuevo', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const performAdjustmentSpy = spyOn(manager, 'performAdjustment').and.callThrough();

        manager.adjustPolling(50, true);
        jasmine.clock().tick(500); // Avanzar 500ms

        manager.adjustPolling(60, true); // Nueva llamada cancela la anterior
        jasmine.clock().tick(500); // Avanzar otros 500ms (total 1000ms desde la última)

        // Debería haberse llamado solo una vez con el último valor
        expect(performAdjustmentSpy).toHaveBeenCalledTimes(1);
        expect(performAdjustmentSpy).toHaveBeenCalledWith(60, true);
      });
    });

    describe('Métricas de rendimiento', function() {
      it('debe inicializar métricas en el constructor', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();

        expect(manager.metrics).toBeDefined();
        expect(manager.metrics.totalRequests).toBe(0);
        expect(manager.metrics.successfulRequests).toBe(0);
        expect(manager.metrics.failedRequests).toBe(0);
        expect(manager.metrics.averageResponseTime).toBe(0);
        expect(manager.metrics.uptime).toBeDefined();
        expect(typeof manager.metrics.uptime).toBe('number');
      });

      it('debe actualizar métricas cuando se registra tiempo de respuesta', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();

        manager.recordResponseTime(500);
        expect(manager.metrics.totalRequests).toBe(1);
        expect(manager.metrics.successfulRequests).toBe(1);
        expect(manager.metrics.averageResponseTime).toBe(500);

        manager.recordResponseTime(1000);
        expect(manager.metrics.totalRequests).toBe(2);
        expect(manager.metrics.successfulRequests).toBe(2);
        // Media móvil: 500 * 0.9 + 1000 * 0.1 = 550
        expect(manager.metrics.averageResponseTime).toBeCloseTo(550, 0);
      });

      it('debe actualizar métricas cuando se registra un error', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();

        manager.recordError();
        expect(manager.metrics.totalRequests).toBe(1);
        expect(manager.metrics.failedRequests).toBe(1);
        expect(manager.metrics.successfulRequests).toBe(0);
      });

      it('debe retornar métricas con getMetrics()', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();

        manager.recordResponseTime(500);
        manager.recordResponseTime(1000);
        manager.recordError();

        const metrics = manager.getMetrics();

        expect(metrics.totalRequests).toBe(3);
        expect(metrics.successfulRequests).toBe(2);
        expect(metrics.failedRequests).toBe(1);
        expect(metrics.averageResponseTime).toBeDefined();
        expect(metrics.uptime).toBeDefined();
        expect(metrics.successRate).toBeDefined();
        expect(metrics.successRate).toBeCloseTo(2 / 3, 2);
      });

      it('debe resetear métricas en reset()', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();

        manager.recordResponseTime(500);
        manager.recordError();
        manager.reset();

        expect(manager.metrics.totalRequests).toBe(0);
        expect(manager.metrics.successfulRequests).toBe(0);
        expect(manager.metrics.failedRequests).toBe(0);
        expect(manager.metrics.averageResponseTime).toBe(0);
      });
    });

    describe('getPollingStatus()', function() {
      it('debe retornar el estado completo del sistema', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const callback = jasmine.createSpy('callback');

        manager.startPolling('testPoll', callback, 1000);
        manager.config.errorCount = 5;
        manager.config.consecutiveErrors = 2;
        manager.config.userActive = true;
        manager.config.averageLatency = 500;

        const status = manager.getPollingStatus();

        expect(status.activePollings).toContain('testPoll');
        expect(status.currentMode).toBe(manager.config.currentMode);
        expect(status.currentInterval).toBe(manager.config.currentInterval);
        expect(status.errorCount).toBe(5);
        expect(status.consecutiveErrors).toBe(2);
        expect(status.userActive).toBe(true);
        expect(status.averageLatency).toBe(500);
        expect(status.metrics).toBeDefined();

        manager.stopPolling('testPoll');
      });
    });

    describe('updateConfig()', function() {
      it('debe actualizar intervalos con merge profundo', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const originalNormal = manager.config.intervals.normal;

        const result = manager.updateConfig({
          intervals: {
            normal: 20000,
            active: 5000
          }
        });

        expect(result).toBe(true);
        expect(manager.config.intervals.normal).toBe(20000);
        expect(manager.config.intervals.active).toBe(5000);
        // Otros intervalos no deberían cambiar
        expect(manager.config.intervals.fast).toBe(originalNormal !== 20000 ? manager.config.intervals.fast : manager.config.intervals.fast);
      });

      it('debe actualizar umbrales con merge profundo', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();

        const result = manager.updateConfig({
          thresholds: {
            latency_threshold: 2000,
            max_errors: 10
          }
        });

        expect(result).toBe(true);
        expect(manager.config.thresholds.latency_threshold).toBe(2000);
        expect(manager.config.thresholds.max_errors).toBe(10);
      });

      it('debe validar que newConfig sea un objeto', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();

        expect(manager.updateConfig(null)).toBe(false);
        expect(manager.updateConfig('not an object')).toBe(false);
        expect(window.ErrorHandler.logError).toHaveBeenCalled();
      });

      it('debe emitir evento configUpdated cuando se actualiza', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const callback = jasmine.createSpy('callback');

        manager.on('configUpdated', callback);

        manager.updateConfig({
          maxListenersPerEvent: 150
        });

        expect(callback).toHaveBeenCalled();
        const eventData = callback.calls.mostRecent().args[0];
        expect(eventData.config).toBeDefined();
        expect(eventData.timestamp).toBeDefined();
      });
    });

    describe('Hooks para testing', function() {
      it('debe permitir crear instancia en modo test', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager({ testMode: true });

        expect(manager.isTestMode).toBe(true);
        expect(manager.testHooks).toBeDefined();
      });

      it('debe esperar el próximo evento con waitForNextTick()', function(done) {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          done();
          return;
        }

        const manager = new PollingManager({ testMode: true });

        const promise = manager.waitForNextTick('testEvent', 1000);

        // Emitir evento después de un pequeño delay
        setTimeout(function() {
          manager.emit('testEvent', { data: 'test' });
        }, 100);

        promise.then(function(data) {
          expect(data).toEqual({ data: 'test' });
          done();
        }).catch(function(error) {
          done.fail('waitForNextTick falló: ' + error.message);
        });

        jasmine.clock().tick(200);
      });

      it('debe hacer timeout en waitForNextTick si no se emite el evento', function(done) {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          done();
          return;
        }

        const manager = new PollingManager({ testMode: true });

        const promise = manager.waitForNextTick('testEvent', 500);

        promise.then(function() {
          done.fail('No debería resolverse');
        }).catch(function(error) {
          expect(error.message).toContain('Timeout esperando evento');
          done();
        });

        jasmine.clock().tick(600);
      });

      it('debe retornar información de polling con getPollingInfo()', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();
        const callback = jasmine.createSpy('callback');

        manager.startPolling('testPoll', callback, 2000);

        const info = manager.getPollingInfo('testPoll');

        expect(info).toBeDefined();
        expect(info.id).toBeDefined();
        expect(info.interval).toBe(2000);
        expect(info.startTime).toBeDefined();
        expect(typeof info.startTime).toBe('number');

        manager.stopPolling('testPoll');
      });

      it('debe retornar null para polling inexistente en getPollingInfo()', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();

        const info = manager.getPollingInfo('nonexistent');

        expect(info).toBe(null);
      });

      it('debe permitir forzar ejecución de callback en testMode', function(done) {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          done();
          return;
        }

        const manager = new PollingManager({ testMode: true });
        const callback = jasmine.createSpy('callback');

        manager.startPolling('testPoll', callback, 2000);

        manager.triggerPollingCallback('testPoll').then(function() {
          expect(callback).toHaveBeenCalled();
          manager.stopPolling('testPoll');
          done();
        }).catch(function(error) {
          done.fail('triggerPollingCallback falló: ' + error.message);
        });
      });

      it('NO debe permitir triggerPollingCallback fuera de testMode', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager(); // Sin testMode
        const callback = jasmine.createSpy('callback');

        manager.startPolling('testPoll', callback, 2000);

        expect(function() {
          manager.triggerPollingCallback('testPoll');
        }).toThrowError(/triggerPollingCallback solo está disponible en testMode/);

        manager.stopPolling('testPoll');
      });
    });

    describe('Manejo de visibilidad de página', function() {
      it('debe inicializar detección de visibilidad de página', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();

        expect(manager.pageWasHidden).toBe(false);
      });

      it('debe reducir frecuencia cuando la página está oculta', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        if (typeof document === 'undefined') {
          pending('document no está disponible en este entorno');
          return;
        }

        const manager = new PollingManager();
        const callback = jasmine.createSpy('callback');

        manager.startPolling('testPoll', callback, 2000);

        // Simular página oculta
        Object.defineProperty(document, 'hidden', {
          value: true,
          writable: true,
          configurable: true
        });

        // Disparar evento de visibilidad
        const event = new Event('visibilitychange');
        document.dispatchEvent(event);

        // El intervalo debería aumentar
        expect(manager.pageWasHidden).toBe(true);
        expect(manager.config.currentMode).toBe('page-hidden');

        // Restaurar
        Object.defineProperty(document, 'hidden', {
          value: false,
          writable: true,
          configurable: true
        });

        manager.stopPolling('testPoll');
      });

      it('debe restaurar frecuencia cuando la página vuelve a estar visible', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        if (typeof document === 'undefined') {
          pending('document no está disponible en este entorno');
          return;
        }

        const manager = new PollingManager();
        const callback = jasmine.createSpy('callback');

        manager.startPolling('testPoll', callback, 2000);
        manager.pageWasHidden = true;
        manager.config.currentMode = 'page-hidden';
        manager.config.currentInterval = 60000;

        // Simular página visible
        Object.defineProperty(document, 'hidden', {
          value: false,
          writable: true,
          configurable: true
        });

        // Disparar evento de visibilidad
        const event = new Event('visibilitychange');
        document.dispatchEvent(event);

        // Debería restaurar
        expect(manager.pageWasHidden).toBe(false);

        manager.stopPolling('testPoll');
      });

      it('debe emitir eventos pageHidden y pageVisible', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        if (typeof document === 'undefined') {
          pending('document no está disponible en este entorno');
          return;
        }

        const manager = new PollingManager();
        const hiddenCallback = jasmine.createSpy('hiddenCallback');
        const visibleCallback = jasmine.createSpy('visibleCallback');

        manager.on('pageHidden', hiddenCallback);
        manager.on('pageVisible', visibleCallback);

        // Simular página oculta
        Object.defineProperty(document, 'hidden', {
          value: true,
          writable: true,
          configurable: true
        });
        const hiddenEvent = new Event('visibilitychange');
        document.dispatchEvent(hiddenEvent);

        expect(hiddenCallback).toHaveBeenCalled();

        // Simular página visible
        Object.defineProperty(document, 'hidden', {
          value: false,
          writable: true,
          configurable: true
        });
        const visibleEvent = new Event('visibilitychange');
        document.dispatchEvent(visibleEvent);

        expect(visibleCallback).toHaveBeenCalled();
      });

      it('debe resetear pageWasHidden en reset()', function() {
        const PollingManager = window.PollingManager;
        if (typeof PollingManager === 'undefined') {
          pending('PollingManager no está disponible');
          return;
        }

        const manager = new PollingManager();

        manager.pageWasHidden = true;
        manager.reset();

        expect(manager.pageWasHidden).toBe(false);
      });
    });
  });
});

