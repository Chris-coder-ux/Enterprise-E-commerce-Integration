/**
 * Tests con Jasmine para Phase1Manager.js (Fase 1)
 * 
 * Analiza el comportamiento del frontend de la Fase 1, incluyendo:
 * - Protecciones contra múltiples inicializaciones con lock atómico
 * - Manejo de polling
 * - Reset y cancelación
 * - Liberación de locks en casos de error
 * 
 * @module spec/dashboard/sync/Phase1ManagerSpec
 */

describe('Phase1Manager - Protecciones contra Inicializaciones Duplicadas', function() {
  let originalPhase1Manager, originalPollingManager, originalMiIntegracionApiDashboard;
  let originalDOMCache, originalJQuery;
  let mockJQuery, mockAjax;
  let clock;

  beforeEach(function() {
    // Guardar referencias originales
    originalPhase1Manager = window.Phase1Manager;
    originalPollingManager = window.pollingManager;
    originalMiIntegracionApiDashboard = window.miIntegracionApiDashboard;
    originalDOMCache = window.DOM_CACHE;
    originalJQuery = window.jQuery;

    // ✅ Limpiar estado usando SyncStateManager
    if (window.SyncStateManager) {
      if (typeof window.SyncStateManager.resetPhase1State === 'function') {
        window.SyncStateManager.resetPhase1State();
      }
      if (typeof window.SyncStateManager.resetAllState === 'function') {
        window.SyncStateManager.resetAllState();
      }
    }
    
    // Limpiar estado global (compatibilidad hacia atrás)
    if (window.phase1Starting !== undefined) {
      delete window.phase1Starting;
    }
    if (window.phase1Initialized !== undefined) {
      delete window.phase1Initialized;
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
        css: jasmine.createSpy('css').and.callFake(function() {
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
        }),
        css: jasmine.createSpy('css').and.callFake(function() {
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

    // Mock de PollingManager con sistema de eventos
    const pollingIntervals = new Map();
    const eventListeners = new Map(); // ✅ NUEVO: Simular listeners de eventos
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
        if (pollingIntervals.has(name)) {
          return pollingIntervals.get(name).id;
        }
        const intervalId = 12345 + pollingIntervals.size;
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
      // ✅ NUEVO: Sistema de eventos simulado
      emit: jasmine.createSpy('emit').and.callFake(function(eventName, data) {
        const listeners = eventListeners.get(eventName);
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
        if (!eventListeners.has(eventName)) {
          eventListeners.set(eventName, []);
        }
        eventListeners.get(eventName).push(callback);
        // Retornar función de desuscripción
        return function() {
          const listeners = eventListeners.get(eventName);
          if (listeners) {
            const index = listeners.indexOf(callback);
            if (index > -1) {
              listeners.splice(index, 1);
            }
          }
        };
      }),
      off: jasmine.createSpy('off').and.callFake(function(eventName, callback) {
        if (callback) {
          const listeners = eventListeners.get(eventName);
          if (listeners) {
            const index = listeners.indexOf(callback);
            if (index > -1) {
              listeners.splice(index, 1);
            }
          }
        } else {
          eventListeners.delete(eventName);
        }
      })
    };
    
    // ✅ NUEVO: Exponer eventListeners para tests
    window._pollingManagerEventListeners = eventListeners;

    // Mock de SyncStateManager con lock atómico
    const syncState = {
      phase1Starting: false,
      phase1Initialized: false,
      phase2Starting: false,
      phase2Initialized: false,
      inactiveProgressCounter: 0,
      lastProgressValue: 0
    };
    
    window.SyncStateManager = {
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
      resetAllState: jasmine.createSpy('resetAllState').and.callFake(function() {
        syncState.phase1Starting = false;
        syncState.phase1Initialized = false;
        syncState.phase2Starting = false;
        syncState.phase2Initialized = false;
        syncState.inactiveProgressCounter = 0;
        syncState.lastProgressValue = 0;
      }),
      setInactiveProgressCounter: jasmine.createSpy('setInactiveProgressCounter').and.callFake(function(value) {
        syncState.inactiveProgressCounter = value;
      })
    };
    
    // Exponer estado interno para los tests
    window._syncStateForTests = syncState;

    // Mock de ErrorHandler
    window.ErrorHandler = {
      logError: jasmine.createSpy('logError'),
      showConnectionError: jasmine.createSpy('showConnectionError'),
      showUIError: jasmine.createSpy('showUIError')
    };

    // Usar jasmine.clock() para controlar el tiempo
    if (typeof jasmine !== 'undefined' && jasmine.clock) {
      try {
        clock = jasmine.clock();
        clock.install();
      } catch (e) {
        clock = null;
      }
    } else {
      clock = null;
    }
  });

  afterEach(function() {
    // Restaurar referencias originales
    if (originalPhase1Manager !== undefined) {
      window.Phase1Manager = originalPhase1Manager;
    } else {
      delete window.Phase1Manager;
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

    if (originalJQuery !== undefined) {
      window.jQuery = originalJQuery;
    } else {
      delete window.jQuery;
    }

    // Desinstalar clock
    if (clock && clock.uninstall) {
      try {
        clock.uninstall();
      } catch (e) {
        // Ignorar errores
      }
    } else if (jasmine.clock) {
      try {
        jasmine.clock().uninstall();
      } catch (e) {
        // Ignorar errores
      }
    }
  });

  describe('Protección contra inicializaciones duplicadas', function() {
    it('debe prevenir ejecuciones simultáneas con lock atómico', function() {
      if (typeof window.Phase1Manager === 'undefined' || !window.Phase1Manager.start) {
        pending('Phase1Manager.start no está disponible');
        return;
      }

      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase1Starting(false);
        window.SyncStateManager.setPhase1Initialized(false);
      }

      // Primera llamada - debe adquirir el lock
      // ✅ IMPORTANTE: NO ejecutar success inmediatamente para poder verificar el lock
      let firstCallExecuted = false;
      let firstCallSuccessCallback = null;
      mockAjax.and.callFake(function(options) {
        firstCallExecuted = true;
        // Guardar el callback de éxito para ejecutarlo después de verificar el lock
        if (options.success) {
          firstCallSuccessCallback = options.success;
        }
      });

      window.Phase1Manager.start(50, 'Test');
      
      // ✅ Verificar que el lock está activo ANTES de ejecutar el callback de éxito
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase1Starting() : false).toBe(true);
      
      // Segunda llamada inmediata - debe ser bloqueada por el lock
      // ✅ IMPORTANTE: Intentar segunda llamada ANTES de ejecutar el callback de éxito
      mockAjax.and.callFake(function(_options) {
        // Esta función no debería ejecutarse porque el lock está activo
        // Si se ejecuta, significa que el lock no está funcionando correctamente
      });

      window.Phase1Manager.start(50, 'Test');
      
      // La segunda llamada NO debe ejecutar AJAX porque el lock está activo
      // Solo la primera llamada debe ejecutarse
      expect(firstCallExecuted).toBe(true);
      // La segunda llamada no debe ejecutarse porque el lock la bloquea
      expect(mockAjax.calls.count()).toBe(1);
      
      // ✅ Ahora ejecutar el callback de éxito para simular la respuesta del servidor
      // Esto liberará el lock, pero ya verificamos que la segunda llamada fue bloqueada
      if (firstCallSuccessCallback) {
        firstCallSuccessCallback({ 
          success: true,
          data: {
            in_progress: true
          }
        });
      }
    });

    it('debe prevenir reinicializaciones si ya está inicializada', function() {
      if (typeof window.Phase1Manager === 'undefined' || !window.Phase1Manager.start) {
        pending('Phase1Manager.start no está disponible');
        return;
      }

      // Marcar como inicializada
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase1Initialized(true);
        window.SyncStateManager.setPhase1Starting(false);
      }

      let ajaxCalled = false;
      mockAjax.and.callFake(function(options) {
        ajaxCalled = true;
      });

      window.Phase1Manager.start(50, 'Test');

      // No debe ejecutar AJAX porque ya está inicializada
      expect(ajaxCalled).toBe(false);
      expect(mockAjax).not.toHaveBeenCalled();
    });

    it('debe liberar lock después de recibir respuesta exitosa', function(done) {
      if (typeof window.Phase1Manager === 'undefined' || !window.Phase1Manager.start) {
        pending('Phase1Manager.start no está disponible');
        return;
      }

      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase1Starting(false);
        window.SyncStateManager.setPhase1Initialized(false);
      }

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          setTimeout(function() {
            options.success({ 
              success: true,
              data: {
                in_progress: true
              }
            });
            // ✅ Verificar que el lock se liberó después de éxito
            expect(window.SyncStateManager ? window.SyncStateManager.getPhase1Starting() : false).toBe(false);
            // ✅ Verificar que se marcó como inicializada
            expect(window.SyncStateManager ? window.SyncStateManager.getPhase1Initialized() : false).toBe(true);
            done();
          }, 100);
        }
      });

      window.Phase1Manager.start(50, 'Test');
      
      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }
    });

    it('debe liberar lock después de error en respuesta', function() {
      if (typeof window.Phase1Manager === 'undefined' || !window.Phase1Manager.start) {
        pending('Phase1Manager.start no está disponible');
        return;
      }

      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase1Starting(false);
        window.SyncStateManager.setPhase1Initialized(false);
      }

      mockAjax.and.callFake(function(options) {
        if (options.error) {
          options.error({}, 'error', 'Test error');
        }
      });

      window.Phase1Manager.start(50, 'Test');

      // ✅ Verificar que el lock se liberó después de error
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase1Starting() : false).toBe(false);
      
      // ✅ NUEVO: Verificar que se registró el error usando ErrorHandler
      expect(window.ErrorHandler.logError).toHaveBeenCalledWith(
        jasmine.stringMatching(/Error AJAX al iniciar Fase 1/),
        'SYNC_START'
      );
    });

    it('debe liberar lock si faltan dependencias críticas', function() {
      if (typeof window.Phase1Manager === 'undefined' || !window.Phase1Manager.start) {
        pending('Phase1Manager.start no está disponible');
        return;
      }

      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase1Starting(false);
        window.SyncStateManager.setPhase1Initialized(false);
      }

      // Eliminar jQuery temporalmente
      const originalJQuery = window.jQuery;
      delete window.jQuery;

      window.Phase1Manager.start(50, 'Test');

      // ✅ Verificar que el lock se liberó después de detectar dependencia faltante
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase1Starting() : false).toBe(false);

      // Restaurar jQuery
      window.jQuery = originalJQuery;
    });

    it('debe resetear flags al detener', function() {
      if (typeof window.Phase1Manager === 'undefined' || !window.Phase1Manager.stop) {
        pending('Phase1Manager.stop no está disponible');
        return;
      }

      // Marcar como iniciando e inicializada
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase1Starting(true);
        window.SyncStateManager.setPhase1Initialized(true);
      }

      window.Phase1Manager.stop();

      // ✅ Verificar que los flags se resetean
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase1Starting() : false).toBe(false);
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase1Initialized() : false).toBe(false);
    });
  });

  describe('Método start()', function() {
    it('debe realizar petición AJAX correcta', function() {
      if (typeof window.Phase1Manager === 'undefined' || !window.Phase1Manager.start) {
        pending('Phase1Manager.start no está disponible');
        return;
      }

      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase1Starting(false);
        window.SyncStateManager.setPhase1Initialized(false);
      }

      mockAjax.and.callFake(function(options) {
        expect(options.url).toBe('http://test.local/wp-admin/admin-ajax.php');
        expect(options.type).toBe('POST');
        expect(options.data.action).toBe('mia_sync_images');
        expect(options.data.nonce).toBe('test-nonce-123');
        expect(options.data.batch_size).toBe(50);
        expect(options.data.resume).toBe(false);
        expect(options.timeout).toBe(240000); // 60000 * 4

        if (options.success) {
          options.success({ 
            success: true,
            data: {
              in_progress: true
            }
          });
        }
      });

      window.Phase1Manager.start(50, 'Test');

      expect(mockAjax).toHaveBeenCalled();
    });

    it('debe adquirir lock atómico al iniciar', function() {
      if (typeof window.Phase1Manager === 'undefined' || !window.Phase1Manager.start) {
        pending('Phase1Manager.start no está disponible');
        return;
      }

      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase1Starting(false);
        window.SyncStateManager.setPhase1Initialized(false);
      }

      mockAjax.and.callFake(function(options) {
        // ✅ Verificar que el lock está activo
        expect(window.SyncStateManager ? window.SyncStateManager.getPhase1Starting() : false).toBe(true);
        if (options.success) {
          options.success({ 
            success: true,
            data: {
              in_progress: true
            }
          });
        }
      });

      window.Phase1Manager.start(50, 'Test');

      // ✅ Verificar usando SyncStateManager
      expect(window.SyncStateManager ? window.SyncStateManager.getPhase1Starting() : false).toBe(true);
    });
  });

  describe('Transición Fase 1 → Fase 2', function() {
    it('debe emitir evento phase1Completed cuando Fase 1 se completa', function(done) {
      if (typeof window.Phase1Manager === 'undefined' || !window.Phase1Manager.start) {
        pending('Phase1Manager.start no está disponible');
        return;
      }

      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase1Starting(false);
        window.SyncStateManager.setPhase1Initialized(false);
      }

      // Mock de checkPhase1Complete que simula Fase 1 completada
      let checkPhase1CompleteCalled = false;
      mockAjax.and.callFake(function(options) {
        // Primera llamada: start() de Phase1Manager
        if (options.data && options.data.action === 'mia_sync_images') {
          if (options.success) {
            options.success({ 
              success: true,
              data: {
                in_progress: true
              }
            });
          }
        }
        // Segunda llamada: checkPhase1Complete() detecta completitud
        else if (options.data && options.data.action === 'mia_get_sync_progress') {
          checkPhase1CompleteCalled = true;
          if (options.success) {
            // Simular que Fase 1 está completa
            options.success({
              success: true,
              data: {
                phase1_images: {
                  completed: true,
                  in_progress: false,
                  products_processed: 100,
                  total_products: 100,
                  images_processed: 500
                }
              }
            });
          }
        }
      });

      // Iniciar Fase 1
      window.Phase1Manager.start(50, 'Test');

      // Esperar a que checkPhase1Complete se ejecute
      setTimeout(function() {
        // Verificar que se emitió el evento phase1Completed
        expect(window.pollingManager.emit).toHaveBeenCalledWith('phase1Completed', jasmine.objectContaining({
          phase1Status: jasmine.objectContaining({
            completed: true,
            products_processed: 100,
            total_products: 100
          }),
          timestamp: jasmine.any(Number),
          data: jasmine.any(Object)
        }));

        // Verificar que se llamó a startPhase2 (compatibilidad)
        expect(typeof window.startPhase2 === 'function').toBe(true);

        done();
      }, 200);

      if (clock && clock.tick) {
        clock.tick(200);
      } else if (jasmine.clock) {
        jasmine.clock().tick(200);
      }
    });

    it('debe marcar Fase 1 como completada usando SyncStateManager', function(done) {
      if (typeof window.Phase1Manager === 'undefined' || !window.Phase1Manager.start) {
        pending('Phase1Manager.start no está disponible');
        return;
      }

      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase1Starting(false);
        window.SyncStateManager.setPhase1Initialized(true); // Marcar como inicializada primero
      }

      mockAjax.and.callFake(function(options) {
        if (options.data && options.data.action === 'mia_sync_images') {
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
                phase1_images: {
                  completed: true,
                  in_progress: false,
                  products_processed: 100,
                  total_products: 100
                }
              }
            });
          }
        }
      });

      window.Phase1Manager.start(50, 'Test');

      setTimeout(function() {
        // ✅ Verificar que se marcó como no inicializada (completada)
        expect(window.SyncStateManager ? window.SyncStateManager.getPhase1Initialized() : false).toBe(false);
        done();
      }, 200);

      if (clock && clock.tick) {
        clock.tick(200);
      } else if (jasmine.clock) {
        jasmine.clock().tick(200);
      }
    });

    it('debe detener polling de Fase 1 cuando se completa', function(done) {
      if (typeof window.Phase1Manager === 'undefined' || !window.Phase1Manager.start) {
        pending('Phase1Manager.start no está disponible');
        return;
      }

      // Resetear estado
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase1Starting(false);
        window.SyncStateManager.setPhase1Initialized(false);
      }

      mockAjax.and.callFake(function(options) {
        if (options.data && options.data.action === 'mia_sync_images') {
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
                phase1_images: {
                  completed: true,
                  in_progress: false,
                  products_processed: 100,
                  total_products: 100
                }
              }
            });
          }
        }
      });

      window.Phase1Manager.start(50, 'Test');

      setTimeout(function() {
        // ✅ Verificar que se detuvo el polling de Fase 1
        expect(window.pollingManager.stopPolling).toHaveBeenCalledWith('phase1');
        done();
      }, 200);

      if (clock && clock.tick) {
        clock.tick(200);
      } else if (jasmine.clock) {
        jasmine.clock().tick(200);
      }
    });
  });
});

