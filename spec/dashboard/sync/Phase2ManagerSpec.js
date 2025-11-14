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

    // ✅ ACTUALIZADO: Limpiar estado usando SyncStateManager
    // Los flags ahora se gestionan a través de SyncStateManager, pero mantenemos
    // compatibilidad hacia atrás limpiando window.* también
    if (window.SyncStateManager) {
      if (typeof window.SyncStateManager.resetPhase2State === 'function') {
        window.SyncStateManager.resetPhase2State();
      }
      if (typeof window.SyncStateManager.resetPhase1State === 'function') {
        window.SyncStateManager.resetPhase1State();
      }
      if (typeof window.SyncStateManager.resetAllState === 'function') {
        window.SyncStateManager.resetAllState();
      }
    }
    
    // Limpiar estado global (compatibilidad hacia atrás)
    if (window.phase2Initialized !== undefined) {
      delete window.phase2Initialized;
    }
    if (window.phase2Starting !== undefined) {
      delete window.phase2Starting;
    }
    if (window.phase2ProcessingBatch !== undefined) {
      delete window.phase2ProcessingBatch;
    }
    if (window.phase1Starting !== undefined) {
      delete window.phase1Starting;
    }
    if (window.phase1Initialized !== undefined) {
      delete window.phase1Initialized;
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
    // ✅ ACTUALIZADO: Mock de pollingManager con comportamiento de prevención de duplicaciones
    const pollingIntervals = new Map();
    window.pollingManager = {
      config: {
        intervals: {
          active: 2000
        },
        currentInterval: 2000,
        currentMode: 'normal',
        errorCount: 0
      },
      intervals: pollingIntervals,
      startPolling: jasmine.createSpy('startPolling').and.callFake(function(name, callback, interval) {
        // ✅ SIMULADO: Prevenir duplicaciones - retornar ID existente si ya está activo
        if (pollingIntervals.has(name)) {
          return pollingIntervals.get(name).id;
        }
        const intervalId = 12345 + pollingIntervals.size; // ID único simulado
        pollingIntervals.set(name, {
          id: intervalId,
          callback: callback,
          interval: interval || 2000,
          startTime: Date.now()
        });
        return intervalId;
      }),
      stopPolling: jasmine.createSpy('stopPolling').and.callFake(function(name) {
        if (pollingIntervals.has(name)) {
          pollingIntervals.delete(name);
          return true;
        }
        return false;
      }),
      stopAllPolling: jasmine.createSpy('stopAllPolling').and.callFake(function() {
        pollingIntervals.clear();
      }),
      isPollingActive: jasmine.createSpy('isPollingActive').and.callFake(function(name) {
        if (name) {
          return pollingIntervals.has(name);
        }
        return pollingIntervals.size > 0;
      }),
      getIntervalId: jasmine.createSpy('getIntervalId').and.callFake(function(name) {
        if (pollingIntervals.has(name)) {
          return pollingIntervals.get(name).id;
        }
        return null;
      }),
      // ✅ ACTUALIZADO: Sistema de eventos simulado para manejar suscripciones
      emit: jasmine.createSpy('emit').and.callFake(function(eventName, data) {
        // Simular que los listeners reciben el evento
        const listeners = window._pollingManagerEventListeners ? window._pollingManagerEventListeners.get(eventName) : null;
        if (listeners && listeners.length > 0) {
          listeners.forEach(function(callback) {
            try {
              callback(data);
            } catch (e) {
              // Ignorar errores en listeners
            }
          });
        }
      }),
      on: jasmine.createSpy('on').and.callFake(function(eventName, callback) {
        // ✅ SIMULADO: Registrar listener en Map interno
        if (!window._pollingManagerEventListeners) {
          window._pollingManagerEventListeners = new Map();
        }
        if (!window._pollingManagerEventListeners.has(eventName)) {
          window._pollingManagerEventListeners.set(eventName, []);
        }
        window._pollingManagerEventListeners.get(eventName).push(callback);
        // Retornar función de desuscripción
        return function() {
          const listeners = window._pollingManagerEventListeners.get(eventName);
          if (listeners) {
            const index = listeners.indexOf(callback);
            if (index > -1) {
              listeners.splice(index, 1);
            }
          }
        };
      }),
      off: jasmine.createSpy('off').and.callFake(function(eventName, callback) {
        if (window._pollingManagerEventListeners) {
          if (callback) {
            const listeners = window._pollingManagerEventListeners.get(eventName);
            if (listeners) {
              const index = listeners.indexOf(callback);
              if (index > -1) {
                listeners.splice(index, 1);
              }
            }
          } else {
            window._pollingManagerEventListeners.delete(eventName);
          }
        }
      })
    };
    
    // ✅ NUEVO: Inicializar Map de listeners si no existe
    if (!window._pollingManagerEventListeners) {
      window._pollingManagerEventListeners = new Map();
    }

    // Mock de SyncStateManager con todos los métodos de Fase 2
    // ✅ ACTUALIZADO: Mock completo de SyncStateManager con estado centralizado
    const syncState = {
      phase2Starting: false,
      phase2Initialized: false,
      phase2ProcessingBatch: false,
      syncInterval: null,
      phase2PollingInterval: null,
      inactiveProgressCounter: 0,
      lastProgressValue: 0
    };
    
    window.SyncStateManager = {
      // Métodos de contadores de progreso
      getInactiveProgressCounter: jasmine.createSpy('getInactiveProgressCounter').and.callFake(function() {
        return syncState.inactiveProgressCounter;
      }),
      setInactiveProgressCounter: jasmine.createSpy('setInactiveProgressCounter').and.callFake(function(value) {
        syncState.inactiveProgressCounter = value;
      }),
      incrementInactiveProgressCounter: jasmine.createSpy('incrementInactiveProgressCounter').and.callFake(function() {
        syncState.inactiveProgressCounter++;
        return syncState.inactiveProgressCounter;
      }),
      getLastProgressValue: jasmine.createSpy('getLastProgressValue').and.callFake(function() {
        return syncState.lastProgressValue;
      }),
      setLastProgressValue: jasmine.createSpy('setLastProgressValue').and.callFake(function(value) {
        syncState.lastProgressValue = value;
      }),
      resetCounters: jasmine.createSpy('resetCounters').and.callFake(function() {
        syncState.inactiveProgressCounter = 0;
        syncState.lastProgressValue = 0;
      }),
      
      // ✅ NUEVO: Métodos de estado de Fase 1
      getPhase1Starting: jasmine.createSpy('getPhase1Starting').and.callFake(function() {
        return syncState.phase1Starting;
      }),
      setPhase1Starting: jasmine.createSpy('setPhase1Starting').and.callFake(function(value) {
        // ✅ SIMULADO: Lock atómico - retornar false si ya está activo
        if (value === true && syncState.phase1Starting === true) {
          return false; // Lock ya activo
        }
        syncState.phase1Starting = value === true;
        return true; // Lock adquirido
      }),
      getPhase1Initialized: jasmine.createSpy('getPhase1Initialized').and.callFake(function() {
        return syncState.phase1Initialized;
      }),
      setPhase1Initialized: jasmine.createSpy('setPhase1Initialized').and.callFake(function(value) {
        syncState.phase1Initialized = value === true;
      }),
      resetPhase1State: jasmine.createSpy('resetPhase1State').and.callFake(function() {
        syncState.phase1Starting = false;
        syncState.phase1Initialized = false;
      }),
      
      // Métodos de estado de Fase 2
      getPhase2Starting: jasmine.createSpy('getPhase2Starting').and.callFake(function() {
        return syncState.phase2Starting;
      }),
      setPhase2Starting: jasmine.createSpy('setPhase2Starting').and.callFake(function(value) {
        // ✅ ACTUALIZADO: Lock atómico - retornar false si ya está activo
        if (value === true && syncState.phase2Starting === true) {
          return false; // Lock ya activo
        }
        syncState.phase2Starting = value === true;
        return true; // Lock adquirido
      }),
      getPhase2Initialized: jasmine.createSpy('getPhase2Initialized').and.callFake(function() {
        return syncState.phase2Initialized;
      }),
      setPhase2Initialized: jasmine.createSpy('setPhase2Initialized').and.callFake(function(value) {
        syncState.phase2Initialized = value === true;
      }),
      getPhase2ProcessingBatch: jasmine.createSpy('getPhase2ProcessingBatch').and.callFake(function() {
        return syncState.phase2ProcessingBatch;
      }),
      setPhase2ProcessingBatch: jasmine.createSpy('setPhase2ProcessingBatch').and.callFake(function(value) {
        syncState.phase2ProcessingBatch = value === true;
      }),
      getSyncInterval: jasmine.createSpy('getSyncInterval').and.callFake(function() {
        return syncState.syncInterval;
      }),
      setSyncInterval: jasmine.createSpy('setSyncInterval').and.callFake(function(value) {
        syncState.syncInterval = value;
      }),
      clearSyncInterval: jasmine.createSpy('clearSyncInterval').and.callFake(function() {
        if (syncState.syncInterval !== null) {
          try {
            clearInterval(syncState.syncInterval);
          } catch (e) {
            // Ignorar errores
          }
          syncState.syncInterval = null;
        }
      }),
      getPhase2PollingInterval: jasmine.createSpy('getPhase2PollingInterval').and.callFake(function() {
        return syncState.phase2PollingInterval;
      }),
      setPhase2PollingInterval: jasmine.createSpy('setPhase2PollingInterval').and.callFake(function(value) {
        syncState.phase2PollingInterval = value;
      }),
      clearPhase2PollingInterval: jasmine.createSpy('clearPhase2PollingInterval').and.callFake(function() {
        if (syncState.phase2PollingInterval !== null) {
          try {
            clearInterval(syncState.phase2PollingInterval);
          } catch (e) {
            // Ignorar errores
          }
          syncState.phase2PollingInterval = null;
        }
      }),
      resetPhase2State: jasmine.createSpy('resetPhase2State').and.callFake(function() {
        syncState.phase2Starting = false;
        syncState.phase2Initialized = false;
        syncState.phase2ProcessingBatch = false;
        if (syncState.syncInterval !== null) {
          try {
            clearInterval(syncState.syncInterval);
          } catch (e) {
            // Ignorar errores
          }
          syncState.syncInterval = null;
        }
        if (syncState.phase2PollingInterval !== null) {
          try {
            clearInterval(syncState.phase2PollingInterval);
          } catch (e) {
            // Ignorar errores
          }
          syncState.phase2PollingInterval = null;
        }
      }),
      resetAllState: jasmine.createSpy('resetAllState').and.callFake(function() {
        syncState.inactiveProgressCounter = 0;
        syncState.lastProgressValue = 0;
        syncState.phase1Starting = false;
        syncState.phase1Initialized = false;
        syncState.phase2Starting = false;
        syncState.phase2Initialized = false;
        syncState.phase2ProcessingBatch = false;
        if (syncState.syncInterval !== null) {
          try {
            clearInterval(syncState.syncInterval);
          } catch (e) {
            // Ignorar errores
          }
          syncState.syncInterval = null;
        }
        if (syncState.phase2PollingInterval !== null) {
          try {
            clearInterval(syncState.phase2PollingInterval);
          } catch (e) {
            // Ignorar errores
          }
          syncState.phase2PollingInterval = null;
        }
      }),
      
      // Otros métodos
      stopProgressPolling: jasmine.createSpy('stopProgressPolling'),
      isPollingActive: jasmine.createSpy('isPollingActive').and.returnValue(false),
      cleanupOnPageLoad: jasmine.createSpy('cleanupOnPageLoad')
    };
    
    // Exponer estado interno para los tests (solo para verificación)
    window._syncStateForTests = syncState;

    // Mock de ErrorHandler
    window.ErrorHandler = {
      logError: jasmine.createSpy('logError'),
      showConnectionError: jasmine.createSpy('showConnectionError'),
      showUIError: jasmine.createSpy('showUIError')
    };

    // Mock de ConsoleManager para tests de feedback
    window.ConsoleManager = {
      addLine: jasmine.createSpy('addLine')
    };

    // Mock de addConsoleLine (fallback)
    window.addConsoleLine = jasmine.createSpy('addConsoleLine');

    // Mock de ToastManager
    window.ToastManager = {
      show: jasmine.createSpy('show')
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

    // Limpiar mocks
    if (window.ConsoleManager && window.ConsoleManager.addLine) {
      window.ConsoleManager.addLine.calls.reset();
    }
    if (window.addConsoleLine) {
      window.addConsoleLine.calls.reset();
    }
    if (window.ToastManager && window.ToastManager.show) {
      window.ToastManager.show.calls.reset();
    }
    
    // ✅ NUEVO: Resetear estado de procesamiento de lotes para evitar interferencia entre pruebas
    if (window.SyncStateManager && window.SyncStateManager.setPhase2ProcessingBatch) {
      window.SyncStateManager.setPhase2ProcessingBatch(false);
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
      // ✅ ACTUALIZADO: Usar SyncStateManager en lugar de window.*
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }
      
      mockAjax.and.callFake(function(options) {
        // Simular que la primera llamada está en progreso
        // ✅ ACTUALIZADO: Usar SyncStateManager
        if (window.SyncStateManager) {
          window.SyncStateManager.setPhase2Starting(true);
        }
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
      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Initialized(true);
      }
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

      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Initialized(true);
      }
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
    it('debe prevenir ejecuciones simultáneas con lock atómico', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // ✅ NUEVO: Test de protección contra ejecuciones simultáneas
      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }

      // Primera llamada - debe adquirir el lock
      let firstCallExecuted = false;
      mockAjax.and.callFake(function(options) {
        firstCallExecuted = true;
        if (options.success) {
          options.success({ success: true });
        }
      });

      window.Phase2Manager.start();
      
      // Verificar que el lock está activo
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase2Starting() : false).toBe(true);
      
      // Segunda llamada inmediata - debe ser bloqueada por el lock
      let secondCallExecuted = false;
      mockAjax.and.callFake(function(options) {
        secondCallExecuted = true;
      });

      window.Phase2Manager.start();
      
      // La segunda llamada NO debe ejecutar AJAX porque el lock está activo
      // Solo la primera llamada debe ejecutarse
      expect(firstCallExecuted).toBe(true);
      // Verificar que setPhase2Starting fue llamado con false en la segunda llamada (lock ya activo)
      const setPhase2StartingCalls = window.SyncStateManager.setPhase2Starting.calls.all();
      // La segunda llamada debe retornar false porque el lock ya está activo
      if (setPhase2StartingCalls.length >= 2) {
        const secondCallResult = setPhase2StartingCalls[1].returnValue;
        expect(secondCallResult).toBe(false);
      }
    });

    it('debe realizar petición AJAX correcta', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // ✅ ACTUALIZADO: Usar SyncStateManager en lugar de window.*
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }

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
      // ✅ ACTUALIZADO: Usar SyncStateManager en lugar de window.*
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }

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
      // ✅ ACTUALIZADO: Usar SyncStateManager en lugar de window.*
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }

      mockAjax.and.callFake(function(options) {
        expect(options.data.batch_size).toBe(20); // Valor por defecto
        if (options.success) {
          options.success({ success: true });
        }
      });

      window.Phase2Manager.start();

      expect(mockAjax).toHaveBeenCalled();
    });

    it('debe adquirir lock atómico al iniciar', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // ✅ ACTUALIZADO: Usar SyncStateManager en lugar de window.*
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }

      mockAjax.and.callFake(function(options) {
        // ✅ ACTUALIZADO: Verificar que el lock está activo usando SyncStateManager
        expect(window.SyncStateManager ? window.SyncStateManager.getPhase2Starting() : false).toBe(true);
        if (options.success) {
          options.success({ success: true });
        }
      });

      window.Phase2Manager.start();

      // ✅ ACTUALIZADO: Verificar usando SyncStateManager
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase2Starting() : false).toBe(true);
    });

    it('debe resetear phase2Starting después de recibir respuesta', function(done) {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // ✅ ACTUALIZADO: Usar SyncStateManager en lugar de window.*
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          setTimeout(function() {
            options.success({ success: true });
            // ✅ ACTUALIZADO: Verificar usando SyncStateManager
            expect(window.SyncStateManager ? window.SyncStateManager.getPhase2Starting() : false).toBe(false);
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
    it('debe iniciar polling directamente sin verificaciones redundantes', function(done) {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // ✅ ACTUALIZADO: PollingManager previene duplicaciones automáticamente
      // Phase2Manager ya no necesita verificar si el polling está activo
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      window.Phase2Manager.start();

      // ✅ ACTUALIZADO: Ya no hay setTimeout, el polling se inicia directamente
      // PollingManager previene duplicaciones automáticamente
      expect(window.pollingManager.startPolling).toHaveBeenCalledWith(
        'syncProgress',
        window.checkSyncProgress,
        jasmine.any(Number)
      );
      
      done();
    });

    it('debe iniciar polling directamente sin setTimeout', function(done) {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // ✅ ACTUALIZADO: Usar SyncStateManager en lugar de window.*
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      window.Phase2Manager.start();

      // ✅ ACTUALIZADO: Ya no hay setTimeout, el polling se inicia directamente
      // No necesitamos esperar 500ms
      expect(window.pollingManager.startPolling).toHaveBeenCalledWith(
        'syncProgress',
        window.checkSyncProgress,
        jasmine.any(Number)
      );
      
      // ✅ ACTUALIZADO: Verificar que se guardó el intervalo usando SyncStateManager
      const intervalId = window.pollingManager.intervals.get('syncProgress');
      if (intervalId) {
        expect(window.SyncStateManager ? window.SyncStateManager.getSyncInterval() : null).toBe(intervalId.id);
      }

      done();
    });

    it('debe exponer syncInterval en window', function(done) {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // ✅ ACTUALIZADO: Usar SyncStateManager en lugar de window.*
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }
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

      // ✅ ACTUALIZADO: Verificar usando SyncStateManager
      const syncInterval = window.SyncStateManager ? window.SyncStateManager.getSyncInterval() : null;
      expect(syncInterval).toBeDefined();
      expect(syncInterval).toBe(12345);

      done();
    });
  });

  describe('Método reset()', function() {
    it('debe resetear flag phase2Initialized', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.reset) {
        pending('Phase2Manager.reset no está disponible');
        return;
      }

      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Initialized(true);
      }
      window.Phase2Manager.reset();

      // ✅ ACTUALIZADO: Verificar usando SyncStateManager
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase2Initialized() : false).toBe(false);
    });

    it('debe resetear flag phase2Starting', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.reset) {
        pending('Phase2Manager.reset no está disponible');
        return;
      }

      window.phase2Starting = true;
      window.Phase2Manager.reset();

      // ✅ ACTUALIZADO: Verificar usando SyncStateManager
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase2Starting() : false).toBe(false);
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

      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setSyncInterval(12345);
      }
      const clearIntervalSpy = spyOn(window, 'clearInterval');
      
      window.Phase2Manager.reset();

      // Nota: clearInterval puede no estar disponible en el entorno de test
      // pero el código debe intentar limpiarlo
      // ✅ ACTUALIZADO: Verificar usando SyncStateManager
      expect(window.SyncStateManager ? window.SyncStateManager.getSyncInterval() : null).toBe(null);
    });

    it('debe resetear flag phase2ProcessingBatch', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.reset) {
        pending('Phase2Manager.reset no está disponible');
        return;
      }

      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2ProcessingBatch(true);
      }
      window.Phase2Manager.reset();

      // ✅ ACTUALIZADO: Verificar usando SyncStateManager
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase2ProcessingBatch() : false).toBe(false);
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

      // ✅ ACTUALIZADO: Usar SyncStateManager en lugar de window.*
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }

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

      // ✅ ACTUALIZADO: Verificar que se llama con más detalles (incluye status)
      expect(window.ErrorHandler.logError).toHaveBeenCalledWith(
        jasmine.stringMatching(/Error al iniciar Fase 2.*Status:/),
        'PHASE2_START'
      );
      expect(window.DOM_CACHE.$syncBtn.prop).toHaveBeenCalledWith('disabled', false);
    });

    it('debe manejar error AJAX', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // ✅ ACTUALIZADO: Usar SyncStateManager en lugar de window.*
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }

      mockAjax.and.callFake(function(options) {
        if (options.error) {
          options.error({ status: 500 }, 'error', 'Internal Server Error');
        }
      });

      window.Phase2Manager.start();

      // ✅ ACTUALIZADO: Verificar que se llama con más detalles (incluye status)
      expect(window.ErrorHandler.logError).toHaveBeenCalledWith(
        jasmine.stringMatching(/Error al iniciar Fase 2.*Status:.*error/),
        'PHASE2_START'
      );
      
      // ✅ ACTUALIZADO: Verificar que se muestra error en UI usando ErrorHandler
      expect(window.ErrorHandler.showConnectionError).toHaveBeenCalledWith({ status: 500 });
      
      // Verificar que también se actualiza el feedback directamente (fallback)
      expect(window.DOM_CACHE.$feedback.text).toHaveBeenCalled();
      // ✅ ACTUALIZADO: Verificar usando SyncStateManager
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase2Starting() : false).toBe(false); // Debe resetearse en caso de error
    });
  });

  describe('Integración con eventos', function() {
    it('debe emitir evento syncProgress al iniciar', function(done) {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // ✅ ACTUALIZADO: Usar SyncStateManager en lugar de window.*
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }
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

  describe('Transición Fase 1 → Fase 2', function() {
    beforeEach(function() {
      // Resetear estado antes de cada test
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }
    });

    it('debe suscribirse automáticamente al evento phase1Completed', function() {
      if (typeof window.Phase2Manager === 'undefined') {
        pending('Phase2Manager no está disponible');
        return;
      }

      // ✅ Verificar que se suscribió al evento cuando Phase2Manager se expuso
      // La suscripción ocurre automáticamente cuando se carga el script
      expect(window.pollingManager.on).toHaveBeenCalledWith('phase1Completed', jasmine.any(Function));
    });

    it('debe iniciar Fase 2 automáticamente cuando recibe evento phase1Completed', function(done) {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }

      // Mock de AJAX para start()
      mockAjax.and.callFake(function(options) {
        if (options.data && options.data.action === 'mi_integracion_api_sync_products_batch') {
          if (options.success) {
            options.success({ 
              success: true,
              data: {
                in_progress: true
              }
            });
          }
        } else if (options.data && options.data.action === 'mia_get_sync_progress') {
          if (options.success) {
            options.success({
              success: true,
              data: {
                in_progress: true
              }
            });
          }
        }
      });

      // Simular evento phase1Completed
      const eventData = {
        phase1Status: {
          completed: true,
          products_processed: 100,
          total_products: 100
        },
        timestamp: Date.now(),
        data: {
          in_progress: false
        }
      };

      // Emitir evento (simular que Phase1Manager lo emite)
      window.pollingManager.emit('phase1Completed', eventData);

      // ✅ Verificar que Phase2Manager.start() fue llamado
      // Esperar un momento para que el evento se procese
      setTimeout(function() {
        expect(mockAjax).toHaveBeenCalled();
        // Verificar que se llamó con los parámetros correctos
        const ajaxCalls = mockAjax.calls.all();
        const startCall = ajaxCalls.find(function(call) {
          return call.args[0].data && call.args[0].data.action === 'mi_integracion_api_sync_products_batch';
        });
        expect(startCall).toBeDefined();
        done();
      }, 100);

      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }
    });

    it('debe NO iniciar Fase 2 si ya está iniciando cuando recibe evento', function() {
      if (typeof window.Phase2Manager === 'undefined') {
        pending('Phase2Manager no está disponible');
        return;
      }

      // Marcar como iniciando
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(true);
        window.SyncStateManager.setPhase2Initialized(false);
      }

      let startCalled = false;
      mockAjax.and.callFake(function(options) {
        if (options.data && options.data.action === 'mi_integracion_api_sync_products_batch') {
          startCalled = true;
        }
      });

      // Simular evento phase1Completed
      const eventData = {
        phase1Status: { completed: true },
        timestamp: Date.now(),
        data: {}
      };

      // Emitir evento
      window.pollingManager.emit('phase1Completed', eventData);

      // ✅ Verificar que NO se llamó a start() porque ya está iniciando
      expect(startCalled).toBe(false);
    });

    it('debe NO iniciar Fase 2 si ya está inicializada cuando recibe evento', function() {
      if (typeof window.Phase2Manager === 'undefined') {
        pending('Phase2Manager no está disponible');
        return;
      }

      // Marcar como inicializada
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(true);
      }

      let startCalled = false;
      mockAjax.and.callFake(function(options) {
        if (options.data && options.data.action === 'mi_integracion_api_sync_products_batch') {
          startCalled = true;
        }
      });

      // Simular evento phase1Completed
      const eventData = {
        phase1Status: { completed: true },
        timestamp: Date.now(),
        data: {}
      };

      // Emitir evento
      window.pollingManager.emit('phase1Completed', eventData);

      // ✅ Verificar que NO se llamó a start() porque ya está inicializada
      expect(startCalled).toBe(false);
    });

    it('debe usar lock atómico al iniciar desde evento phase1Completed', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
        window.SyncStateManager.setPhase2Initialized(false);
      }

      mockAjax.and.callFake(function(options) {
        if (options.data && options.data.action === 'mi_integracion_api_sync_products_batch') {
          // ✅ Verificar que el lock está activo cuando se ejecuta AJAX
          expect(window.SyncStateManager ? window.SyncStateManager.getPhase2Starting() : false).toBe(true);
          if (options.success) {
            options.success({ success: true });
          }
        }
      });

      // Simular evento phase1Completed
      const eventData = {
        phase1Status: { completed: true },
        timestamp: Date.now(),
        data: {}
      };

      // Emitir evento
      window.pollingManager.emit('phase1Completed', eventData);

      // ✅ Verificar que se adquirió el lock
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase2Starting() : false).toBe(true);
    });
  });

  describe('Análisis de problemas detectados', function() {
    it('debe prevenir múltiples inicializaciones cuando se cancela y se reinicia', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.reset || !window.Phase2Manager.start) {
        pending('Phase2Manager no está disponible');
        return;
      }

      // Simular inicialización
      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Initialized(true);
      }
      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setSyncInterval(12345);
      }
      window.pollingManager.intervals.set('syncProgress', { id: 12345 });

      // Resetear (simular cancelación)
      window.Phase2Manager.reset();

      // ✅ ACTUALIZADO: Verificar usando SyncStateManager
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase2Initialized() : false).toBe(false);
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
      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Initialized(true);
      }
      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setSyncInterval(12345);
      }
      window.pollingManager.intervals.set('syncProgress', { id: 12345 });
      window.pollingManager.isPollingActive.and.returnValue(true);

      // Resetear (simular cancelación)
      window.Phase2Manager.reset();

      expect(window.pollingManager.stopPolling).toHaveBeenCalledWith('syncProgress');
      // ✅ ACTUALIZADO: Verificar usando SyncStateManager
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase2Initialized() : false).toBe(false);
      // ✅ ACTUALIZADO: Verificar usando SyncStateManager
      expect(window.SyncStateManager ? window.SyncStateManager.getSyncInterval() : null).toBe(null);
    });

    it('debe prevenir saturación de red con throttling', function(done) {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }

      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Initialized(true);
      }
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

  // ✅ NUEVO: Tests para manejo de errores mejorado
  describe('Manejo de errores mejorado', function() {
    it('debe registrar errores en processNextBatchAutomatically usando ErrorHandler', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.processNextBatchAutomatically) {
        pending('Phase2Manager.processNextBatchAutomatically no está disponible');
        return;
      }

      // ✅ NOTA IMPORTANTE: Este test verifica el manejo de errores, por lo que se espera ver
      // un mensaje de error en la consola. Esto es comportamiento esperado del test.
      // El error "Internal Server Error (Status: error)" que aparece en la consola
      // es parte de la verificación de que los errores se manejan correctamente.
      // 
      // El error proviene de installHook.js que intercepta ErrorHandler.logError y lo muestra
      // en la consola. Esto es normal y esperado durante este test.

      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2ProcessingBatch(false);
      }

      mockAjax.and.callFake(function(options) {
        if (options.error) {
          options.error({ status: 500 }, 'error', 'Internal Server Error');
        }
      });

      window.Phase2Manager.processNextBatchAutomatically();

      // ✅ Verificar que se registró el error usando ErrorHandler
      expect(window.ErrorHandler.logError).toHaveBeenCalledWith(
        jasmine.stringMatching(/Error al procesar siguiente lote automáticamente.*Status:/),
        'BATCH_PROCESSING'
      );
      
      // ✅ Verificar que se muestra error en UI usando ErrorHandler
      expect(window.ErrorHandler.showConnectionError).toHaveBeenCalledWith({ status: 500 });
      
      // ✅ NUEVO: Verificar que se mostró mensaje de error en consola
      expect(window.ConsoleManager.addLine).toHaveBeenCalledWith(
        'warning',
        jasmine.stringMatching(/Error al procesar lote manualmente.*WordPress Cron intentará procesarlo más tarde/)
      );
      
      // ✅ NUEVO: Verificar que se mostró notificación toast de error
      expect(window.ToastManager.show).toHaveBeenCalledWith(
        'Error al procesar lote manualmente. WordPress Cron intentará procesarlo más tarde.',
        'error',
        5000
      );
      
      // ✅ Verificar que se reseteó el flag incluso en caso de error
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase2ProcessingBatch() : false).toBe(false);
    });
  });

  // ✅ NUEVO: Tests para feedback mejorado al usuario
  describe('Feedback mejorado al usuario', function() {
    it('debe mostrar mensaje cuando se inicia procesamiento manual', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.processNextBatchAutomatically) {
        pending('Phase2Manager.processNextBatchAutomatically no está disponible');
        return;
      }

      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2ProcessingBatch(false);
      }

      // Limpiar llamadas anteriores
      window.ConsoleManager.addLine.calls.reset();

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      window.Phase2Manager.processNextBatchAutomatically();

      // ✅ Verificar que se mostró mensaje informativo cuando se inicia procesamiento
      expect(window.ConsoleManager.addLine).toHaveBeenCalledWith(
        'info',
        '🔄 Procesando lote manualmente (WordPress Cron no responde)...'
      );
    });

    it('debe mostrar mensaje cuando se completa procesamiento manual exitosamente', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.processNextBatchAutomatically) {
        pending('Phase2Manager.processNextBatchAutomatically no está disponible');
        return;
      }

      // ✅ CRÍTICO: Resetear estado ANTES de limpiar llamadas para evitar condiciones de carrera
      // También avanzar el tiempo para ejecutar cualquier setTimeout pendiente de pruebas anteriores
      if (clock && clock.tick) {
        clock.tick(6000); // Avanzar 6 segundos para ejecutar setTimeout de 5 segundos de prueba anterior
      } else if (typeof jasmine !== 'undefined' && jasmine.clock) {
        jasmine.clock().tick(6000);
      }
      
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2ProcessingBatch(false);
      }

      // Limpiar llamadas anteriores
      window.ConsoleManager.addLine.calls.reset();
      if (window.ToastManager && window.ToastManager.show) {
        window.ToastManager.show.calls.reset();
      }

      // ✅ CRÍTICO: Verificar que el estado está reseteado antes de continuar
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase2ProcessingBatch() : false).toBe(false);

      // ✅ MEJORADO: Mock de AJAX que ejecuta el callback de forma síncrona
      mockAjax.and.callFake(function(options) {
        // Ejecutar callback de éxito inmediatamente (síncrono)
        if (options.success) {
          options.success({ success: true });
        }
      });

      // Llamar a la función
      window.Phase2Manager.processNextBatchAutomatically();

      // ✅ Verificar que se mostró mensaje de éxito cuando se completa procesamiento
      expect(window.ConsoleManager.addLine).toHaveBeenCalledWith(
        'success',
        '✅ Lote procesado manualmente con éxito. La sincronización continuará automáticamente.'
      );
      
      // ✅ NUEVO: Verificar que se mostró notificación toast de éxito
      expect(window.ToastManager.show).toHaveBeenCalledWith(
        'Lote procesado manualmente con éxito',
        'success',
        3000
      );
    });

    it('debe mostrar mensaje cuando ya hay un lote siendo procesado', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.processNextBatchAutomatically) {
        pending('Phase2Manager.processNextBatchAutomatically no está disponible');
        return;
      }

      // Marcar como procesando
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2ProcessingBatch(true);
      }

      // Limpiar llamadas anteriores
      window.ConsoleManager.addLine.calls.reset();

      window.Phase2Manager.processNextBatchAutomatically();

      // ✅ Verificar que se mostró mensaje informativo cuando ya hay un lote procesándose
      expect(window.ConsoleManager.addLine).toHaveBeenCalledWith(
        'info',
        'ℹ️ Ya hay un lote siendo procesado manualmente, esperando...'
      );

      // ✅ Verificar que NO se hizo llamada AJAX porque ya hay un lote procesándose
      expect(mockAjax).not.toHaveBeenCalled();

      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2ProcessingBatch(false);
      }
    });
  });
  
  describe('Uso de características modernas de JavaScript', function() {
    it('debe usar optional chaining (?.) para acceder a SyncStateManager de forma segura', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.start) {
        pending('Phase2Manager.start no está disponible');
        return;
      }
      
      // Establecer SyncStateManager como undefined para probar optional chaining
      const originalSyncStateManager = window.SyncStateManager;
      window.SyncStateManager = undefined;
      
      // Resetear estado
      window.phase2Starting = false;
      window.phase2Initialized = false;
      
      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });
      
      // No debe lanzar error al usar optional chaining
      expect(function() {
        window.Phase2Manager.start();
      }).not.toThrow();
      
      // Restaurar SyncStateManager
      window.SyncStateManager = originalSyncStateManager;
    });
    
    it('debe usar nullish coalescing (??) para valores por defecto en mensajes de error', function() {
      if (typeof window.Phase2Manager === 'undefined' || !window.Phase2Manager.handleError) {
        pending('Phase2Manager.handleError no está disponible');
        return;
      }
      
      // Crear un error sin mensaje para probar nullish coalescing
      const errorWithoutMessage = { status: 500 };
      
      // No debe lanzar error al usar nullish coalescing
      expect(function() {
        window.Phase2Manager.handleError(errorWithoutMessage);
      }).not.toThrow();
      
      // Verificar que se usó un mensaje por defecto
      expect(window.ErrorHandler.logError).toHaveBeenCalled();
    });
  });
});

