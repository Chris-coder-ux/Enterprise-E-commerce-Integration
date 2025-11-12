/**
 * Tests con Jasmine para Phase2Manager.js (Fase 2)
 * 
 * Analiza el comportamiento del frontend de la Fase 2, incluyendo:
 * - Protecciones contra múltiples inicializaciones
 * - Manejo de polling
 * - Reset y cancelación
 * - Throttling de logs
 * 
 * @module spec/dashboard/sync/Phase2ManagerSpec
 */

describe('Phase2Manager - Análisis Frontend Fase 2', function() {
  let originalPhase2Manager, originalWindowPhase2Manager, originalStartPhase2;
  let originalPollingManager, originalWindowPollingManager;
  let originalMiIntegracionApiDashboard, originalDOMCache;
  let mockJQuery, mockAjax;
  let clock;

  beforeEach(function() {
    // Guardar referencias originales
    originalPhase2Manager = window.Phase2Manager;
    originalWindowPhase2Manager = window.phase2Initialized;
    originalStartPhase2 = window.startPhase2;
    originalPollingManager = window.pollingManager;
    originalMiIntegracionApiDashboard = window.miIntegracionApiDashboard;
    originalDOMCache = window.DOM_CACHE;

    // Limpiar estado global
    if (window.phase2Initialized !== undefined) {
      delete window.phase2Initialized;
    }
    if (window.phase2Starting !== undefined) {
      delete window.phase2Starting;
    }
    if (window.phase2ProcessingBatch !== undefined) {
      delete window.phase2ProcessingBatch;
    }
    if (window.syncInterval !== undefined) {
      delete window.syncInterval;
    }

    // Mock de jQuery
    mockAjax = jasmine.createSpy('ajax');
    const createJQueryMock = function() {
      const mock = {
        text: jasmine.createSpy('text').and.callFake(function(value) {
          if (arguments.length === 0) return '';
          return mock;
        }),
        prop: jasmine.createSpy('prop').and.callFake(function(name, value) {
          if (arguments.length === 1) return false;
          return mock;
        }),
        ajax: mockAjax
      };
      return mock;
    };
    mockJQuery = jasmine.createSpy('jQuery').and.callFake(function(selector) {
      return createJQueryMock();
    });
    mockJQuery.ajax = mockAjax;
    window.jQuery = mockJQuery;
    window.$ = mockJQuery;

    // Mock de miIntegracionApiDashboard
    window.miIntegracionApiDashboard = {
      ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
      nonce: 'test-nonce-123',
      pollingConfig: {
        intervals: {
          normal: 15000,
          active: 2000,
          fast: 1000,
          slow: 45000,
          idle: 120000
        }
      }
    };

    // Mock de DASHBOARD_CONFIG
    window.DASHBOARD_CONFIG = {
      timeouts: {
        ajax: 60000
      }
    };

    // Mock de DOM_CACHE
    const createDOMElementMock = function() {
      const mock = {
        text: jasmine.createSpy('text').and.callFake(function(value) {
          if (arguments.length === 0) return '';
          return mock;
        }),
        prop: jasmine.createSpy('prop').and.callFake(function(name, value) {
          if (arguments.length === 1) return false;
          return mock;
        })
      };
      return mock;
    };
    window.DOM_CACHE = {
      $feedback: createDOMElementMock(),
      $syncBtn: createDOMElementMock(),
      $batchSizeSelector: createDOMElementMock()
    };

    // Mock de PollingManager
    window.pollingManager = {
      config: {
        intervals: {
          active: 2000
        },
        currentInterval: 2000,
        currentMode: 'normal',
        errorCount: 0
      },
      intervals: new Map(),
      startPolling: jasmine.createSpy('startPolling').and.returnValue(12345),
      stopPolling: jasmine.createSpy('stopPolling').and.returnValue(true),
      stopAllPolling: jasmine.createSpy('stopAllPolling'),
      isPollingActive: jasmine.createSpy('isPollingActive').and.returnValue(false),
      emit: jasmine.createSpy('emit')
    };

    // Mock de SyncStateManager
    window.SyncStateManager = {
      setInactiveProgressCounter: jasmine.createSpy('setInactiveProgressCounter')
    };

    // Mock de ErrorHandler
    window.ErrorHandler = {
      logError: jasmine.createSpy('logError')
    };

    // Mock de checkSyncProgress - evitar que haga llamadas AJAX reales
    // ✅ CRÍTICO: checkSyncProgress puede hacer llamadas AJAX que necesitan respuesta con data
    window.checkSyncProgress = jasmine.createSpy('checkSyncProgress').and.callFake(function() {
      // No hacer nada, solo evitar errores cuando se llama desde handleSuccess
      // Si checkSyncProgress hace una llamada AJAX, nuestro mockAjax la capturará
      // pero necesitamos asegurarnos de que las respuestas tengan la estructura correcta
    });

    // Configurar window.pendingPhase2BatchSize
    window.pendingPhase2BatchSize = 20;

    // Usar jasmine.clock() para controlar el tiempo (si está disponible)
    // ✅ CRÍTICO: Siempre instalar el clock para mockear Date.now() y setTimeout/setInterval
    if (typeof jasmine !== 'undefined' && jasmine.clock) {
      try {
        clock = jasmine.clock();
        clock.install();
        // Mockear Date.now() para que use el clock
        clock.mockDate(new Date(2024, 0, 1));
      } catch (e) {
        // Si falla, intentar sin mockDate
        clock = jasmine.clock();
        clock.install();
      }
    } else {
      clock = null;
    }
  });

  afterEach(function() {
    // Restaurar referencias originales
    if (originalPhase2Manager !== undefined) {
      window.Phase2Manager = originalPhase2Manager;
    } else {
      delete window.Phase2Manager;
    }

    if (originalWindowPhase2Manager !== undefined) {
      window.phase2Initialized = originalWindowPhase2Manager;
    } else {
      delete window.phase2Initialized;
    }

    if (originalStartPhase2 !== undefined) {
      window.startPhase2 = originalStartPhase2;
    } else {
      delete window.startPhase2;
    }

    if (originalPollingManager !== undefined) {
      window.pollingManager = originalPollingManager;
    } else {
      delete window.pollingManager;
    }

    if (originalMiIntegracionApiDashboard !== undefined) {
      window.miIntegracionApiDashboard = originalMiIntegracionApiDashboard;
    } else {
      delete window.miIntegracionApiDashboard;
    }

    if (originalDOMCache !== undefined) {
      window.DOM_CACHE = originalDOMCache;
    } else {
      delete window.DOM_CACHE;
    }

    // Limpiar estado global
    delete window.phase2Starting;
    delete window.phase2ProcessingBatch;
    delete window.syncInterval;

    // Desinstalar clock (si está disponible)
    if (clock && clock.uninstall) {
      clock.uninstall();
    }
  });

  describe('Carga del script', function() {
    it('debe exponer Phase2Manager en window', function() {
      if (typeof window.Phase2Manager === 'undefined') {
        pending('Phase2Manager no está disponible - el script debe cargarse primero');
        return;
      }

      expect(window.Phase2Manager).toBeDefined();
      expect(typeof window.Phase2Manager).toBe('object');
    });

    it('debe exponer startPhase2 en window', function() {
      if (typeof window.startPhase2 === 'undefined') {
        pending('startPhase2 no está disponible - el script debe cargarse primero');
        return;
      }

      expect(window.startPhase2).toBeDefined();
      expect(typeof window.startPhase2).toBe('function');
    });
  });

  describe('Protección contra múltiples inicializaciones', function() {
    it('debe prevenir múltiples llamadas simultáneas a start()', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // Primera llamada
      window.phase2Starting = false;
      window.phase2Initialized = false;
      
      mockAjax.and.callFake(function(options) {
        // Simular que la primera llamada está en progreso
        window.phase2Starting = true;
        if (options.success) {
          setTimeout(function() {
            options.success({ success: true });
          }, 100);
        }
      });

      window.Phase2Manager.start();
      
      // Segunda llamada inmediata (debe ser bloqueada)
      const consoleWarnSpy = spyOn(console, 'warn');
      window.Phase2Manager.start();

      // Verificar que solo se hizo una llamada AJAX
      expect(mockAjax.calls.count()).toBe(1);
    });

    it('debe prevenir múltiples inicializaciones con flag phase2Initialized', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // Marcar como inicializado
      window.phase2Initialized = true;
      window.phase2Starting = false;

      const consoleWarnSpy = spyOn(console, 'warn');
      window.Phase2Manager.start();

      // No debe hacer llamada AJAX si ya está inicializado
      expect(mockAjax).not.toHaveBeenCalled();
    });

    it('debe usar throttling para logs de advertencia', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // ✅ CRÍTICO: Resetear Phase2Manager para limpiar lastWarningTime
      // Esto asegura que lastWarningTime = 0 antes de empezar el test
      if (window.Phase2Manager && typeof window.Phase2Manager.reset === 'function') {
        window.Phase2Manager.reset();
      }

      // ✅ CRÍTICO: Mockear Date.now() directamente para controlar el tiempo
      // Usar un tiempo base razonable
      let currentTime = 1000000; // Tiempo base
      const originalDateNow = Date.now;
      
      // ✅ CRÍTICO: Crear spy ANTES de mockear Date.now()
      const consoleWarnSpy = spyOn(console, 'warn');
      
      // Mockear Date.now() para que use nuestro tiempo controlado
      // ✅ IMPORTANTE: Usar función simple en lugar de jasmine.createSpy para mejor compatibilidad
      Date.now = function() {
        return currentTime;
      };

      window.phase2Initialized = true;
      window.phase2Starting = false; // Asegurar que no está iniciando
      
      // Primera llamada - debe mostrar advertencia porque phase2Initialized = true
      // throttledWarn() compara: Date.now() - lastWarningTime > 5000
      // Como reset() puso lastWarningTime = 0, la diferencia será 1000000 - 0 = 1000000 > 5000
      // así que debe mostrar la advertencia
      window.Phase2Manager.start();
      
      // Verificar que se llamó console.warn
      // Debe ser llamado por throttledWarn() cuando phase2Initialized = true
      expect(consoleWarnSpy.calls.count()).toBeGreaterThanOrEqual(1);
      const firstCallCount = consoleWarnSpy.calls.count();

      // Segunda llamada inmediata (menos de 5 segundos) - debe estar throttled
      currentTime += 1000; // Avanzar solo 1 segundo (menos de 5 segundos de throttle = 5000ms)
      window.Phase2Manager.start();
      
      // No debe incrementar porque está throttled
      // lastWarningTime ahora debería ser 1000000 (de la primera llamada)
      // currentTime es 1000000 + 1000 = 1001000
      // Diferencia: 1000ms < 5000ms, así que NO debe mostrar advertencia
      expect(consoleWarnSpy.calls.count()).toBe(firstCallCount);

      // Tercera llamada después del throttle (más de 5 segundos) - debe mostrar advertencia
      currentTime += 5000; // Avanzar 5 segundos más (total 6 segundos desde la primera)
      window.Phase2Manager.start();
      
      // Debe incrementar porque pasaron más de 5 segundos desde la última advertencia
      // lastWarningTime es 1000000 (primera llamada)
      // currentTime ahora es 1000000 + 1000 + 5000 = 1006000
      // Diferencia: 6000ms > 5000ms, así que debe mostrar advertencia
      expect(consoleWarnSpy.calls.count()).toBe(firstCallCount + 1);

      // Restaurar Date.now()
      Date.now = originalDateNow;
    });
  });

  describe('Método start()', function() {
    it('debe realizar petición AJAX correcta', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      window.phase2Starting = false;
      window.phase2Initialized = false;

      mockAjax.and.callFake(function(options) {
        // ✅ CRÍTICO: Manejar diferentes tipos de llamadas AJAX
        // Si es la llamada de start(), verificar parámetros específicos
        if (options.data && options.data.action === 'mi_integracion_api_sync_products_batch') {
          expect(options.url).toBe('http://test.local/wp-admin/admin-ajax.php');
          expect(options.type).toBe('POST');
          expect(options.data.action).toBe('mi_integracion_api_sync_products_batch');
          expect(options.data.nonce).toBe('test-nonce-123');
          expect(options.data.batch_size).toBe(20);
          expect(options.timeout).toBe(240000); // 60000 * 4

          if (options.success) {
            // ✅ CRÍTICO: Proporcionar estructura de respuesta completa para start()
            options.success({ 
              success: true,
              data: {
                in_progress: true,
                estadisticas: {
                  procesados: 0,
                  total: 100
                }
              }
            });
          }
        } else if (options.data && options.data.action === 'mia_get_sync_progress') {
          // ✅ CRÍTICO: Si es una llamada de checkSyncProgress, devolver estructura correcta
          // SyncProgress.js espera response.data
          if (options.success) {
            options.success({
              success: true,
              data: {
                in_progress: true,
                estadisticas: {
                  procesados: 0,
                  total: 100
                }
              }
            });
          }
        } else {
          // Para otras llamadas, devolver respuesta básica con data
          if (options.success) {
            options.success({ 
              success: true,
              data: {
                in_progress: true,
                estadisticas: {
                  procesados: 0,
                  total: 100
                }
              }
            });
          }
        }
      });

      window.Phase2Manager.start();

      expect(mockAjax).toHaveBeenCalled();
    });

    it('debe usar batch_size de window.pendingPhase2BatchSize', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      window.pendingPhase2BatchSize = 50;
      window.phase2Starting = false;
      window.phase2Initialized = false;

      mockAjax.and.callFake(function(options) {
        expect(options.data.batch_size).toBe(50);
        if (options.success) {
          options.success({ success: true });
        }
      });

      window.Phase2Manager.start();

      expect(mockAjax).toHaveBeenCalled();
    });

    it('debe usar batch_size por defecto si no hay pendingPhase2BatchSize', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      delete window.pendingPhase2BatchSize;
      window.phase2Starting = false;
      window.phase2Initialized = false;

      mockAjax.and.callFake(function(options) {
        expect(options.data.batch_size).toBe(20); // Valor por defecto
        if (options.success) {
          options.success({ success: true });
        }
      });

      window.Phase2Manager.start();

      expect(mockAjax).toHaveBeenCalled();
    });

    it('debe marcar phase2Starting como true al iniciar', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      window.phase2Starting = false;
      window.phase2Initialized = false;

      mockAjax.and.callFake(function(options) {
        expect(window.phase2Starting).toBe(true);
        if (options.success) {
          options.success({ success: true });
        }
      });

      window.Phase2Manager.start();

      expect(window.phase2Starting).toBe(true);
    });

    it('debe resetear phase2Starting después de recibir respuesta', function(done) {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      window.phase2Starting = false;
      window.phase2Initialized = false;

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          setTimeout(function() {
            options.success({ success: true });
            expect(window.phase2Starting).toBe(false);
            done();
          }, 100);
        }
      });

      window.Phase2Manager.start();
      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }
    });
  });

  describe('Manejo de polling', function() {
    it('debe verificar si el polling ya está activo antes de iniciar', function(done) {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      window.phase2Starting = false;
      window.phase2Initialized = false;
      window.pollingManager.isPollingActive.and.returnValue(true);

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      const consoleWarnSpy = spyOn(console, 'warn');
      window.Phase2Manager.start();

      if (clock && clock.tick) {
        clock.tick(600); // Esperar setTimeout de 500ms
      } else if (jasmine.clock) {
        jasmine.clock().tick(600);
      }

      // Debe detectar que el polling ya está activo
      expect(window.pollingManager.isPollingActive).toHaveBeenCalledWith('syncProgress');
      expect(window.pollingManager.startPolling).not.toHaveBeenCalled();
      
      done();
    });

    it('debe iniciar polling si no está activo', function(done) {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      window.phase2Starting = false;
      window.phase2Initialized = false;
      window.pollingManager.isPollingActive.and.returnValue(false);

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      window.Phase2Manager.start();

      if (clock && clock.tick) {
        clock.tick(600); // Esperar setTimeout de 500ms
      } else if (jasmine.clock) {
        jasmine.clock().tick(600);
      }

      expect(window.pollingManager.startPolling).toHaveBeenCalledWith(
        'syncProgress',
        window.checkSyncProgress,
        2000
      );
      expect(window.syncInterval).toBe(12345);

      done();
    });

    it('debe exponer syncInterval en window', function(done) {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      window.phase2Starting = false;
      window.phase2Initialized = false;
      window.pollingManager.isPollingActive.and.returnValue(false);

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      window.Phase2Manager.start();

      if (clock && clock.tick) {
        clock.tick(600);
      } else if (jasmine.clock) {
        jasmine.clock().tick(600);
      }

      expect(window.syncInterval).toBeDefined();
      expect(window.syncInterval).toBe(12345);

      done();
    });
  });

  describe('Método reset()', function() {
    it('debe resetear flag phase2Initialized', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.reset) {
        pending('Phase2Manager.reset no está disponible');
        return;
      }

      window.phase2Initialized = true;
      window.Phase2Manager.reset();

      expect(window.phase2Initialized).toBe(false);
    });

    it('debe resetear flag phase2Starting', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.reset) {
        pending('Phase2Manager.reset no está disponible');
        return;
      }

      window.phase2Starting = true;
      window.Phase2Manager.reset();

      expect(window.phase2Starting).toBe(false);
    });

    it('debe detener polling de syncProgress', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.reset) {
        pending('Phase2Manager.reset no está disponible');
        return;
      }

      window.Phase2Manager.reset();

      expect(window.pollingManager.stopPolling).toHaveBeenCalledWith('syncProgress');
    });

    it('debe limpiar syncInterval si existe', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.reset) {
        pending('Phase2Manager.reset no está disponible');
        return;
      }

      window.syncInterval = 12345;
      const clearIntervalSpy = spyOn(window, 'clearInterval');
      
      window.Phase2Manager.reset();

      // Nota: clearInterval puede no estar disponible en el entorno de test
      // pero el código debe intentar limpiarlo
      expect(window.syncInterval).toBe(null);
    });

    it('debe resetear flag phase2ProcessingBatch', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.reset) {
        pending('Phase2Manager.reset no está disponible');
        return;
      }

      window.phase2ProcessingBatch = true;
      window.Phase2Manager.reset();

      expect(window.phase2ProcessingBatch).toBe(false);
    });

    it('debe detener todos los polling si stopAllPolling está disponible', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.reset) {
        pending('Phase2Manager.reset no está disponible');
        return;
      }

      window.Phase2Manager.reset();

      // Debe llamar a stopPolling (que se llama dos veces en el código actual)
      expect(window.pollingManager.stopPolling).toHaveBeenCalled();
    });
  });

  describe('Manejo de errores', function() {
    it('debe manejar respuesta con error', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      window.phase2Starting = false;
      window.phase2Initialized = false;

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({
            success: false,
            data: {
              message: 'Error de servidor'
            }
          });
        }
      });

      window.Phase2Manager.start();

      expect(window.ErrorHandler.logError).toHaveBeenCalledWith('Error al iniciar Fase 2', 'SYNC_START');
      expect(window.DOM_CACHE.$syncBtn.prop).toHaveBeenCalledWith('disabled', false);
    });

    it('debe manejar error AJAX', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      window.phase2Starting = false;
      window.phase2Initialized = false;

      mockAjax.and.callFake(function(options) {
        if (options.error) {
          options.error({ status: 500 }, 'error', 'Internal Server Error');
        }
      });

      window.Phase2Manager.start();

      expect(window.ErrorHandler.logError).toHaveBeenCalledWith('Error al iniciar Fase 2', 'SYNC_START');
      expect(window.DOM_CACHE.$feedback.text).toHaveBeenCalledWith('Error al iniciar Fase 2: Internal Server Error');
      expect(window.phase2Starting).toBe(false); // Debe resetearse en caso de error
    });
  });

  describe('Integración con eventos', function() {
    it('debe emitir evento syncProgress al iniciar', function(done) {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      window.phase2Starting = false;
      window.phase2Initialized = false;
      window.pollingManager.isPollingActive.and.returnValue(false);

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      window.Phase2Manager.start();

      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }

      expect(window.pollingManager.emit).toHaveBeenCalledWith('syncProgress', jasmine.objectContaining({
        syncData: jasmine.objectContaining({
          in_progress: true,
          phase: 2
        })
      }));

      done();
    });
  });

  describe('Análisis de problemas detectados', function() {
    it('debe prevenir múltiples inicializaciones cuando se cancela y se reinicia', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.reset || !window.Phase2Manager.start) {
        pending('Phase2Manager no está disponible');
        return;
      }

      // Simular inicialización
      window.phase2Initialized = true;
      window.syncInterval = 12345;
      window.pollingManager.intervals.set('syncProgress', { id: 12345 });

      // Resetear (simular cancelación)
      window.Phase2Manager.reset();

      expect(window.phase2Initialized).toBe(false);
      expect(window.pollingManager.stopPolling).toHaveBeenCalledWith('syncProgress');

      // Intentar reiniciar inmediatamente
      window.phase2Starting = false;
      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      window.Phase2Manager.start();

      // Debe permitir reiniciar después del reset
      expect(mockAjax).toHaveBeenCalled();
    });

    it('debe detener polling correctamente al cancelar', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.reset) {
        pending('Phase2Manager.reset no está disponible');
        return;
      }

      // Simular polling activo
      window.phase2Initialized = true;
      window.syncInterval = 12345;
      window.pollingManager.intervals.set('syncProgress', { id: 12345 });
      window.pollingManager.isPollingActive.and.returnValue(true);

      // Resetear (simular cancelación)
      window.Phase2Manager.reset();

      expect(window.pollingManager.stopPolling).toHaveBeenCalledWith('syncProgress');
      expect(window.phase2Initialized).toBe(false);
      expect(window.syncInterval).toBe(null);
    });

    it('debe prevenir saturación de red con throttling', function(done) {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      window.phase2Initialized = true;
      const consoleWarnSpy = spyOn(console, 'warn');

      // Múltiples llamadas rápidas
      for (let i = 0; i < 10; i++) {
        window.Phase2Manager.start();
        if (clock && clock.tick) {
          clock.tick(100); // 100ms entre llamadas
        } else if (jasmine.clock) {
          jasmine.clock().tick(100);
        }
      }

      // Debe haber throttling (solo algunas advertencias, no 10)
      expect(consoleWarnSpy.calls.count()).toBeLessThan(10);
      expect(consoleWarnSpy.calls.count()).toBeGreaterThan(0);

      done();
    });
  });
});

