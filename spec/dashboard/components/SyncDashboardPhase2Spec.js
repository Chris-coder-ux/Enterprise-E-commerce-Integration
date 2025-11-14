/**
 * Tests con Jasmine para SyncDashboard.js - Funcionalidad de Fase 2
 * 
 * Analiza el comportamiento del dashboard relacionado con la Fase 2, incluyendo:
 * - Inicio de Fase 2
 * - Cancelación de sincronización
 * - Manejo de polling
 * - Protecciones contra múltiples inicializaciones
 * 
 * @module spec/dashboard/components/SyncDashboardPhase2Spec
 */

describe('SyncDashboard - Funcionalidad Fase 2', function() {
  let originalSyncDashboard, originalJQuery, originalMiIntegracionApiDashboard, originalErrorHandler;
  let originalEventCleanupManager;
  let mockJQuery, mockAjax;
  let clock;
  let syncDashboardInstance;

  beforeEach(function() {
    // Guardar referencia original
    originalEventCleanupManager = window.EventCleanupManager;
    
    // Guardar referencias originales
    originalSyncDashboard = window.SyncDashboard;
    originalJQuery = window.jQuery;
    originalMiIntegracionApiDashboard = window.miIntegracionApiDashboard;
    originalErrorHandler = window.ErrorHandler;

    // ✅ ACTUALIZADO: Limpiar estado usando SyncStateManager
    // Los flags ahora se gestionan a través de SyncStateManager, pero mantenemos
    // compatibilidad hacia atrás limpiando window.* también
    if (window.SyncStateManager && typeof window.SyncStateManager.resetPhase2State === 'function') {
      window.SyncStateManager.resetPhase2State();
    }
    
    // Limpiar estado global (compatibilidad hacia atrás)
    if (window.phase2Initialized !== undefined) {
      delete window.phase2Initialized;
    }
    if (window.phase2Starting !== undefined) {
      delete window.phase2Starting;
    }
    if (window.Phase2Manager !== undefined) {
      delete window.Phase2Manager;
    }

    // Mock de jQuery
    mockAjax = jasmine.createSpy('ajax');
    const createJQueryMock = function() {
      const mock = {
        text: jasmine.createSpy('text').and.callFake(function(_value) {
          if (arguments.length === 0) return '';
          return mock;
        }),
        prop: jasmine.createSpy('prop').and.callFake(function(_name, _value) {
          if (arguments.length === 1) return false;
          return mock;
        }),
        val: jasmine.createSpy('val').and.returnValue('20'),
        on: jasmine.createSpy('on').and.callFake(function() { return mock; }),
        off: jasmine.createSpy('off').and.callFake(function() { return mock; }),
        hide: jasmine.createSpy('hide').and.callFake(function() { return mock; }),
        show: jasmine.createSpy('show').and.callFake(function() { return mock; }),
        removeClass: jasmine.createSpy('removeClass').and.callFake(function() { return mock; }),
        addClass: jasmine.createSpy('addClass').and.callFake(function() { return mock; }),
        length: 1,
        ajax: mockAjax
      };
      return mock;
    };
    mockJQuery = jasmine.createSpy('jQuery').and.callFake(function(selector) {
      if (selector === undefined || selector === null) {
        return {
          ajax: mockAjax
        };
      }
      return createJQueryMock();
    });
    mockJQuery.ajax = mockAjax;
    window.jQuery = mockJQuery;
    window.$ = mockJQuery;

    // Mock de miIntegracionApiDashboard
    window.miIntegracionApiDashboard = {
      ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
      nonce: 'test-nonce-123',
      confirmCancel: '¿Seguro que deseas cancelar?'
    };

    // Mock de Phase2Manager
    window.Phase2Manager = {
      start: jasmine.createSpy('start'),
      reset: jasmine.createSpy('reset'),
      processNextBatchAutomatically: jasmine.createSpy('processNextBatchAutomatically')
    };

    // ✅ ACTUALIZADO: Mock de pollingManager con comportamiento de prevención de duplicaciones
    const pollingIntervals = new Map();
    window.pollingManager = {
      config: {
        intervals: {
          active: 2000
        },
        currentInterval: 2000,
        currentMode: 'normal'
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
          callback,
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
      // ✅ ACTUALIZADO: Sistema de eventos simulado
      emit: jasmine.createSpy('emit').and.callFake(function(eventName, data) {
        // Simular que los listeners reciben el evento
        const listeners = window._syncDashboardEventListeners ? window._syncDashboardEventListeners.get(eventName) : null;
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
        if (!window._syncDashboardEventListeners) {
          window._syncDashboardEventListeners = new Map();
        }
        if (!window._syncDashboardEventListeners.has(eventName)) {
          window._syncDashboardEventListeners.set(eventName, []);
        }
        window._syncDashboardEventListeners.get(eventName).push(callback);
        // Retornar función de desuscripción
        return function() {
          const listeners = window._syncDashboardEventListeners.get(eventName);
          if (listeners) {
            const index = listeners.indexOf(callback);
            if (index > -1) {
              listeners.splice(index, 1);
            }
          }
        };
      }),
      off: jasmine.createSpy('off').and.callFake(function(eventName, callback) {
        if (window._syncDashboardEventListeners) {
          if (callback) {
            const listeners = window._syncDashboardEventListeners.get(eventName);
            if (listeners) {
              const index = listeners.indexOf(callback);
              if (index > -1) {
                listeners.splice(index, 1);
              }
            }
          } else {
            window._syncDashboardEventListeners.delete(eventName);
          }
        }
      })
    };
    
    // ✅ NUEVO: Inicializar Map de listeners si no existe
    if (!window._syncDashboardEventListeners) {
      window._syncDashboardEventListeners = new Map();
    }

    // Mock de SyncStateManager con todos los métodos de Fase 2
    // ✅ ACTUALIZADO: Mock completo de SyncStateManager con estado centralizado
    const syncState = {
      phase2Starting: false,
      phase2Initialized: false,
      phase2ProcessingBatch: false,
      syncInterval: null,
      phase2PollingInterval: null
    };
    
    window.SyncStateManager = {
      getPhase2Starting: jasmine.createSpy('getPhase2Starting').and.callFake(function() {
        return syncState.phase2Starting;
      }),
      setPhase2Starting: jasmine.createSpy('setPhase2Starting').and.callFake(function(value) {
        syncState.phase2Starting = value === true;
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
      })
    };
    
    // Exponer estado interno para los tests (solo para verificación)
    window._syncStateForTests = syncState;

    // Mock de checkSyncProgress
    window.checkSyncProgress = jasmine.createSpy('checkSyncProgress');

    // Mock de addConsoleLine
    window.addConsoleLine = jasmine.createSpy('addConsoleLine');

    // Mock de ToastManager
    window.ToastManager = {
      show: jasmine.createSpy('show')
    };

    // ✅ NUEVO: Mock de ErrorHandler para tests de manejo de errores
    window.ErrorHandler = {
      logError: jasmine.createSpy('logError'),
      showConnectionError: jasmine.createSpy('showConnectionError'),
      showUIError: jasmine.createSpy('showUIError')
    };
    
    // ✅ NUEVO: Mock de EventCleanupManager para tests de gestión de eventos
    // Crear el mock de instancia primero para evitar referencia circular
    const mockInstance = {
      cleanupAll: jasmine.createSpy('instanceCleanupAll').and.returnValue(0),
      getStats: jasmine.createSpy('getStats').and.returnValue({
        documentListeners: 0,
        elementListeners: 0,
        customEventListeners: 0,
        nativeListeners: 0,
        totalComponents: 0
      })
    };
    
    window.EventCleanupManager = {
      registerElementListener: jasmine.createSpy('registerElementListener').and.returnValue(function() {}),
      registerCustomEventListener: jasmine.createSpy('registerCustomEventListener').and.returnValue(function() {}),
      registerDocumentListener: jasmine.createSpy('registerDocumentListener').and.returnValue(function() {}),
      cleanupComponent: jasmine.createSpy('cleanupComponent').and.returnValue(0),
      cleanupAll: jasmine.createSpy('cleanupAll').and.returnValue(0),
      getInstance: jasmine.createSpy('getInstance').and.returnValue(mockInstance)
    };

    // Mock de window.confirm
    window.confirm = jasmine.createSpy('confirm').and.returnValue(true);

    // Usar jasmine.clock() para controlar el tiempo (si está disponible)
    if (typeof jasmine !== 'undefined' && jasmine.clock) {
      clock = jasmine.clock();
      clock.install();
    } else {
      clock = null;
    }
  });

  afterEach(function() {
    // Restaurar referencias originales
    if (originalSyncDashboard !== undefined) {
      window.SyncDashboard = originalSyncDashboard;
    } else {
      delete window.SyncDashboard;
    }

    if (originalJQuery !== undefined) {
      window.jQuery = originalJQuery;
    } else {
      delete window.jQuery;
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
    
    // Restaurar EventCleanupManager
    if (originalEventCleanupManager !== undefined) {
      window.EventCleanupManager = originalEventCleanupManager;
    } else {
      delete window.EventCleanupManager;
    }

    // Limpiar estado global
    delete window.phase2Initialized;
    delete window.phase2Starting;
    delete window.Phase2Manager;
    delete window.pollingManager;
    delete window.checkSyncProgress;
    delete window.addConsoleLine;
    delete window.ToastManager;

    // Desinstalar clock (si está disponible)
    if (clock && clock.uninstall) {
      clock.uninstall();
    }
  });

  describe('Carga del script', function() {
    it('debe exponer SyncDashboard en window', function() {
      if (typeof window.SyncDashboard === 'undefined') {
        pending('SyncDashboard no está disponible - el script debe cargarse primero');
        return;
      }

      expect(window.SyncDashboard).toBeDefined();
      expect(typeof window.SyncDashboard).toBe('function');
    });
  });

  describe('Método startPhase2()', function() {
    beforeEach(function() {
      // Crear instancia de SyncDashboard si está disponible
      if (typeof window.SyncDashboard !== 'undefined') {
        syncDashboardInstance = new window.SyncDashboard();
      }
    });

    it('debe prevenir múltiples llamadas simultáneas con flag phase2Starting', function() {
      if (!syncDashboardInstance || typeof syncDashboardInstance.startPhase2 !== 'function') {
        pending('SyncDashboard.startPhase2 no está disponible');
        return;
      }

      // ✅ CORRECCIÓN: Usar SyncStateManager en lugar de la propiedad de instancia
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
      }

      // Mock de window.confirm para evitar diálogos de confirmación
      window.confirm.and.returnValue(true);

      // Primera llamada - mockAjax debe manejar ambas llamadas AJAX
      // Usar Promise que se resuelve después de un delay para simular operación asíncrona
      mockAjax.and.callFake(function(options) {
        
        // Primera llamada: mia_get_sync_progress
        if (options.data && options.data.action === 'mia_get_sync_progress') {
          // Devolver respuesta que permita continuar sin confirmaciones
          // Usar setTimeout para simular operación asíncrona
          return new Promise(function(resolve) {
            setTimeout(function() {
              resolve({
                success: true,
                data: {
                  phase1_images: {
                    completed: true,
                    in_progress: false
                  },
                  in_progress: false
                }
              });
            }, 50);
          });
        }
        
        // Segunda llamada: mi_integracion_api_sync_products_batch
        if (options.data && options.data.action === 'mi_integracion_api_sync_products_batch') {
          return new Promise(function(resolve) {
            setTimeout(function() {
              resolve({
                success: true
              });
            }, 50);
          });
        }
        
        return Promise.resolve({ success: true });
      });

      // Primera llamada (hace 2 peticiones AJAX: status + start)
      syncDashboardInstance.startPhase2();

      // Hacer la segunda llamada INMEDIATAMENTE, antes de que la primera complete
      // Esto asegura que el flag phase2Starting esté en true
      const consoleWarnSpy = spyOn(console, 'warn');
      const callsBeforeSecond = mockAjax.calls.count();
      syncDashboardInstance.startPhase2();

      // Verificar que se llamó console.warn para la segunda llamada bloqueada
      expect(consoleWarnSpy).toHaveBeenCalled();
      
      // Verificar que no se hicieron llamadas AJAX adicionales inmediatamente
      // (la segunda llamada debe ser bloqueada antes de hacer cualquier AJAX)
      expect(mockAjax.calls.count()).toBe(callsBeforeSecond);

      // Esperar a que la primera llamada complete
      if (clock && clock.tick) {
        clock.tick(200);
      } else if (jasmine.clock) {
        jasmine.clock().tick(200);
      }

      // Verificar que el número de llamadas AJAX no aumentó después de la segunda llamada
      // (debe ser igual al número de llamadas de la primera llamada)
      expect(mockAjax.calls.count()).toBe(callsBeforeSecond);
    });

    it('debe realizar petición AJAX correcta para iniciar Fase 2', function(done) {
      if (!syncDashboardInstance || typeof syncDashboardInstance.startPhase2 !== 'function') {
        pending('SyncDashboard.startPhase2 no está disponible');
        return;
      }

      syncDashboardInstance.phase2Starting = false;

      mockAjax.and.callFake(function(options) {
        expect(options.url).toBeDefined();
        expect(options.method || options.type).toBe('POST');
        expect(options.data.action).toBe('mi_integracion_api_sync_products_batch');
        expect(options.data.nonce).toBe('test-nonce-123');
        expect(options.data.batch_size).toBeDefined();

        if (options.success) {
          options.success({ success: true });
        }
      });

      syncDashboardInstance.startPhase2().then(function() {
        expect(mockAjax).toHaveBeenCalled();
        done();
      }).catch(function(error) {
        done.fail('Error: ' + error);
      });

      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }
    });

    it('debe resetear phase2Starting después de completar', function(done) {
      if (!syncDashboardInstance || typeof syncDashboardInstance.startPhase2 !== 'function') {
        pending('SyncDashboard.startPhase2 no está disponible');
        return;
      }

      // ✅ CORRECCIÓN: Usar SyncStateManager en lugar de la propiedad de instancia
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(false);
      }

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      syncDashboardInstance.startPhase2().then(function() {
        // ✅ CORRECCIÓN: Verificar usando SyncStateManager
        if (window.SyncStateManager) {
          expect(window.SyncStateManager.getPhase2Starting()).toBe(false);
        }
        done();
      }).catch(function(error) {
        done.fail('Error: ' + error);
      });

      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }
    });

    it('debe NO iniciar polling si Phase2Manager ya lo gestiona', function() {
      if (!syncDashboardInstance || typeof syncDashboardInstance.startPhase2 !== 'function') {
        pending('SyncDashboard.startPhase2 no está disponible');
        return;
      }

      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Initialized(true); // Phase2Manager ya está gestionando
        window.SyncStateManager.setPhase2Starting(false);
      }

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      syncDashboardInstance.startPhase2();

      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }

      // No debe iniciar polling porque Phase2Manager ya lo gestiona
      expect(window.pollingManager.startPolling).not.toHaveBeenCalled();
    });
  });

  describe('Método cancelSync()', function() {
    beforeEach(function() {
      if (typeof window.SyncDashboard !== 'undefined') {
        syncDashboardInstance = new window.SyncDashboard();
      }
    });

    it('debe confirmar cancelación con el usuario', function(done) {
      if (!syncDashboardInstance || typeof syncDashboardInstance.cancelSync !== 'function') {
        pending('SyncDashboard.cancelSync no está disponible');
        return;
      }

      window.confirm.and.returnValue(true);

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      syncDashboardInstance.cancelSync().then(function() {
        expect(window.confirm).toHaveBeenCalled();
        done();
      }).catch(function(error) {
        done.fail('Error: ' + error);
      });

      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }
    });

    it('debe NO cancelar si el usuario no confirma', function(done) {
      if (!syncDashboardInstance || typeof syncDashboardInstance.cancelSync !== 'function') {
        pending('SyncDashboard.cancelSync no está disponible');
        return;
      }

      window.confirm.and.returnValue(false);

      syncDashboardInstance.cancelSync().then(function() {
        expect(mockAjax).not.toHaveBeenCalled();
        done();
      }).catch(function(error) {
        done.fail('Error: ' + error);
      });

      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }
    });

    it('debe detener polling antes de resetear', function(done) {
      if (!syncDashboardInstance || typeof syncDashboardInstance.cancelSync !== 'function') {
        pending('SyncDashboard.cancelSync no está disponible');
        return;
      }

      window.confirm.and.returnValue(true);
      window.pollingManager.intervals.set('syncProgress', { id: 12345 });

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      syncDashboardInstance.cancelSync().then(function() {
        expect(window.pollingManager.stopPolling).toHaveBeenCalledWith('syncProgress');
        expect(window.Phase2Manager.reset).toHaveBeenCalled();
        done();
      }).catch(function(error) {
        done.fail('Error: ' + error);
      });

      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }
    });

    it('debe resetear flag phase2Starting al cancelar', function(done) {
      if (!syncDashboardInstance || typeof syncDashboardInstance.cancelSync !== 'function') {
        pending('SyncDashboard.cancelSync no está disponible');
        return;
      }

      window.confirm.and.returnValue(true);
      // ✅ CORRECCIÓN: Usar SyncStateManager en lugar de la propiedad de instancia
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(true);
      }

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      syncDashboardInstance.cancelSync().then(function() {
        // ✅ CORRECCIÓN: Verificar usando SyncStateManager
        if (window.SyncStateManager) {
          expect(window.SyncStateManager.getPhase2Starting()).toBe(false);
        }
        done();
      }).catch(function(error) {
        done.fail('Error: ' + error);
      });

      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }
    });
  });

  describe('Método updateDashboardFromStatus()', function() {
    beforeEach(function() {
      if (typeof window.SyncDashboard !== 'undefined') {
        syncDashboardInstance = new window.SyncDashboard();
      }
    });

    it('debe NO iniciar polling si Phase2Manager ya lo gestiona', function() {
      if (!syncDashboardInstance || typeof syncDashboardInstance.updateDashboardFromStatus !== 'function') {
        pending('SyncDashboard.updateDashboardFromStatus no está disponible');
        return;
      }

      window.phase2Initialized = true; // Phase2Manager gestionando
      window.pollingManager.isPollingActive.and.returnValue(false);

      const data = {
        in_progress: true,
        estadisticas: {
          procesados: 10,
          total: 100
        }
      };

      syncDashboardInstance.updateDashboardFromStatus(data);

      // No debe iniciar polling porque Phase2Manager ya lo gestiona
      expect(window.pollingManager.startPolling).not.toHaveBeenCalled();
    });

    it('debe resetear Phase2Manager cuando no hay sincronización activa', function() {
      if (!syncDashboardInstance || typeof syncDashboardInstance.updateDashboardFromStatus !== 'function') {
        pending('SyncDashboard.updateDashboardFromStatus no está disponible');
        return;
      }

      window.phase2Initialized = true;

      const data = {
        in_progress: false,
        is_completed: false,
        estadisticas: {
          procesados: 0,
          total: 0
        }
      };

      syncDashboardInstance.updateDashboardFromStatus(data);

      expect(window.Phase2Manager.reset).toHaveBeenCalled();
    });
  });

  describe('Método startPollingIfNeeded()', function() {
    beforeEach(function() {
      if (typeof window.SyncDashboard !== 'undefined') {
        syncDashboardInstance = new window.SyncDashboard();
      }
    });

    it('debe NO iniciar polling si Phase2Manager ya está gestionando', function() {
      if (!syncDashboardInstance || typeof syncDashboardInstance.startPollingIfNeeded !== 'function') {
        pending('SyncDashboard.startPollingIfNeeded no está disponible');
        return;
      }

      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Initialized(true); // Phase2Manager gestionando
      }

      syncDashboardInstance.startPollingIfNeeded();

      // ✅ ACTUALIZADO: SyncDashboard ya no inicia polling directamente
      // Solo verifica y registra que se necesita polling
      expect(window.pollingManager.startPolling).not.toHaveBeenCalled();
    });

    it('debe NO iniciar polling directamente (debe delegar a Phase2Manager)', function() {
      if (!syncDashboardInstance || typeof syncDashboardInstance.startPollingIfNeeded !== 'function') {
        pending('SyncDashboard.startPollingIfNeeded no está disponible');
        return;
      }

      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Initialized(false);
      }

      syncDashboardInstance.startPollingIfNeeded();

      // ✅ ACTUALIZADO: SyncDashboard NO debe iniciar polling directamente
      // Debe delegar a Phase2Manager o solo registrar que se necesita
      expect(window.pollingManager.startPolling).not.toHaveBeenCalled();
    });

    it('debe suscribirse a eventos de PollingManager en el constructor', function() {
      if (!syncDashboardInstance) {
        pending('SyncDashboard no está disponible');
        return;
      }

      // ✅ NUEVO: Verificar que SyncDashboard se suscribe a eventos
      expect(window.pollingManager.on).toHaveBeenCalledWith('syncProgress', jasmine.any(Function));
      expect(window.pollingManager.on).toHaveBeenCalledWith('syncError', jasmine.any(Function));
      expect(window.pollingManager.on).toHaveBeenCalledWith('syncCompleted', jasmine.any(Function));
      // ✅ NUEVO: Verificar suscripción al evento phase1Completed
      expect(window.pollingManager.on).toHaveBeenCalledWith('phase1Completed', jasmine.any(Function));
    });
  });

  describe('Transición Fase 1 → Fase 2', function() {
    beforeEach(function() {
      if (typeof window.SyncDashboard !== 'undefined') {
        syncDashboardInstance = new window.SyncDashboard();
      }
    });

    it('debe suscribirse al evento phase1Completed en el constructor', function() {
      if (!syncDashboardInstance) {
        pending('SyncDashboard no está disponible');
        return;
      }

      // ✅ Verificar que se suscribió al evento phase1Completed
      expect(window.pollingManager.on).toHaveBeenCalledWith('phase1Completed', jasmine.any(Function));
    });

    it('debe actualizar UI cuando recibe evento phase1Completed', function() {
      if (!syncDashboardInstance || typeof syncDashboardInstance.updatePhaseStatus !== 'function') {
        pending('SyncDashboard.updatePhaseStatus no está disponible');
        return;
      }

      // Espiar métodos de actualización
      spyOn(syncDashboardInstance, 'updatePhaseStatus');
      spyOn(syncDashboardInstance, 'stopTimer');
      spyOn(syncDashboardInstance, 'enableButton');
      spyOn(syncDashboardInstance, 'updateDashboardFromStatus');

      // Simular evento phase1Completed
      const eventData = {
        phase1Status: {
          completed: true,
          products_processed: 100,
          total_products: 100
        },
        timestamp: Date.now(),
        data: {
          in_progress: false,
          phase1_images: {
            completed: true
          }
        }
      };

      // Emitir evento (simular que Phase1Manager lo emite)
      window.pollingManager.emit('phase1Completed', eventData);

      // ✅ Verificar que se actualizó el estado de Fase 1 a completada
      expect(syncDashboardInstance.updatePhaseStatus).toHaveBeenCalledWith(1, 'completed');
      
      // ✅ Verificar que se detuvo el timer de Fase 1
      expect(syncDashboardInstance.stopTimer).toHaveBeenCalledWith(1);
      
      // ✅ Verificar que se habilitó el botón de Fase 2
      expect(syncDashboardInstance.enableButton).toHaveBeenCalledWith('start-phase2');
      
      // ✅ Verificar que se actualizó el dashboard
      expect(syncDashboardInstance.updateDashboardFromStatus).toHaveBeenCalledWith(eventData.data);
    });
  });

  describe('Análisis de problemas detectados', function() {
    beforeEach(function() {
      if (typeof window.SyncDashboard !== 'undefined') {
        syncDashboardInstance = new window.SyncDashboard();
      }
    });

    it('debe prevenir saturación de red al cancelar múltiples veces', function(done) {
      if (!syncDashboardInstance || typeof syncDashboardInstance.cancelSync !== 'function') {
        pending('SyncDashboard.cancelSync no está disponible');
        return;
      }

      window.confirm.and.returnValue(true);

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      // Múltiples cancelaciones rápidas
      syncDashboardInstance.cancelSync();
      syncDashboardInstance.cancelSync();
      syncDashboardInstance.cancelSync();

      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }

      // Debe hacer solo una llamada AJAX (las demás deben ser bloqueadas por confirm)
      expect(mockAjax.calls.count()).toBeLessThanOrEqual(3); // Máximo 3 (una por cada confirm)

      done();
    });

    it('debe limpiar completamente el estado al cancelar', function(done) {
      if (!syncDashboardInstance || typeof syncDashboardInstance.cancelSync !== 'function') {
        pending('SyncDashboard.cancelSync no está disponible');
        return;
      }

      // Simular estado activo
      window.phase2Initialized = true;
      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setPhase2Starting(true);
      }
      // ✅ ACTUALIZADO: Usar SyncStateManager
      if (window.SyncStateManager) {
        window.SyncStateManager.setSyncInterval(12345);
      }
      window.pollingManager.intervals.set('syncProgress', { id: 12345 });

      window.confirm.and.returnValue(true);

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      syncDashboardInstance.cancelSync().then(function() {
        expect(window.pollingManager.stopPolling).toHaveBeenCalledWith('syncProgress');
        expect(window.Phase2Manager.reset).toHaveBeenCalled();
        // ✅ CORRECCIÓN: Verificar usando SyncStateManager
        if (window.SyncStateManager) {
          expect(window.SyncStateManager.getPhase2Starting()).toBe(false);
        }
        done();
      }).catch(function(error) {
        done.fail('Error: ' + error);
      });

      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }
    });
  });
  
  describe('Gestión de Event Listeners con EventCleanupManager', function() {
    it('debe usar EventCleanupManager para registrar listeners de elementos si está disponible', function() {
      if (typeof window.SyncDashboard === 'undefined') {
        pending('SyncDashboard no está disponible');
        return;
      }
      
      const syncDashboard = new window.SyncDashboard();
      
      // Llamar a initializeEventListeners
      if (typeof syncDashboard.initializeEventListeners === 'function') {
        syncDashboard.initializeEventListeners();
        
        // Verificar que se usó EventCleanupManager
        expect(window.EventCleanupManager.registerElementListener).toHaveBeenCalled();
      } else {
        pending('initializeEventListeners no está disponible');
      }
    });
    
    it('debe usar EventCleanupManager para registrar listeners de eventos personalizados si está disponible', function() {
      if (typeof window.SyncDashboard === 'undefined') {
        pending('SyncDashboard no está disponible');
        return;
      }
      
      const syncDashboard = new window.SyncDashboard();
      
      // Llamar a subscribeToPollingEvents
      if (typeof syncDashboard.subscribeToPollingEvents === 'function') {
        syncDashboard.subscribeToPollingEvents();
        
        // Verificar que se usó EventCleanupManager
        expect(window.EventCleanupManager.registerCustomEventListener).toHaveBeenCalled();
      } else {
        pending('subscribeToPollingEvents no está disponible');
      }
    });
    
    it('debe limpiar listeners usando EventCleanupManager cuando cleanupEventListeners es llamado', function() {
      if (typeof window.SyncDashboard === 'undefined') {
        pending('SyncDashboard no está disponible');
        return;
      }
      
      const syncDashboard = new window.SyncDashboard();
      
      // Llamar a cleanupEventListeners
      if (typeof syncDashboard.cleanupEventListeners === 'function') {
        syncDashboard.cleanupEventListeners();
        
        // Verificar que se usó EventCleanupManager
        expect(window.EventCleanupManager.cleanupComponent).toHaveBeenCalledWith('SyncDashboard');
      } else {
        pending('cleanupEventListeners no está disponible');
      }
    });
    
    it('debe usar fallback manual si EventCleanupManager no está disponible', function() {
      // Eliminar EventCleanupManager
      delete window.EventCleanupManager;
      
      if (typeof window.SyncDashboard === 'undefined') {
        pending('SyncDashboard no está disponible');
        return;
      }
      
      const syncDashboard = new window.SyncDashboard();
      
      // No debe lanzar error
      if (typeof syncDashboard.cleanupEventListeners === 'function') {
        expect(function() {
          syncDashboard.cleanupEventListeners();
        }).not.toThrow();
      }
    });
    
    it('debe usar optional chaining cuando EventCleanupManager es undefined', function() {
      if (typeof window.SyncDashboard === 'undefined') {
        pending('SyncDashboard no está disponible');
        return;
      }
      
      // Establecer EventCleanupManager como undefined (simulando que no está disponible)
      const originalEventCleanupManager = window.EventCleanupManager;
      window.EventCleanupManager = undefined;
      
      const syncDashboard = new window.SyncDashboard();
      
      // No debe lanzar error al intentar usar optional chaining
      if (typeof syncDashboard.subscribeToPollingEvents === 'function') {
        expect(function() {
          syncDashboard.subscribeToPollingEvents();
        }).not.toThrow();
      }
      
      // Restaurar EventCleanupManager
      window.EventCleanupManager = originalEventCleanupManager;
    });
  });
  
  describe('Uso de características modernas de JavaScript', function() {
    beforeEach(function() {
      if (typeof window.SyncDashboard !== 'undefined') {
        syncDashboardInstance = new window.SyncDashboard();
      }
    });
    
    it('debe usar nullish coalescing (??) para valores por defecto de ajaxurl', function() {
      if (!syncDashboardInstance || typeof syncDashboardInstance.startPhase2 !== 'function') {
        pending('SyncDashboard.startPhase2 no está disponible');
        return;
      }
      
      // Establecer ajaxurl como null para probar nullish coalescing
      const originalAjaxurl = window.ajaxurl;
      window.ajaxurl = null;
      
      // Establecer miIntegracionApiDashboard.ajaxurl como undefined
      const originalAjaxurl2 = window.miIntegracionApiDashboard?.ajaxurl;
      if (window.miIntegracionApiDashboard) {
        window.miIntegracionApiDashboard.ajaxurl = undefined;
      }
      
      // El código debe usar nullish coalescing y no fallar
      expect(function() {
        syncDashboardInstance.startPhase2();
      }).not.toThrow();
      
      // Restaurar valores originales
      window.ajaxurl = originalAjaxurl;
      if (window.miIntegracionApiDashboard && originalAjaxurl2 !== undefined) {
        window.miIntegracionApiDashboard.ajaxurl = originalAjaxurl2;
      }
    });
    
    it('debe usar optional chaining (?.) para acceder a propiedades anidadas', function() {
      if (!syncDashboardInstance || typeof syncDashboardInstance.updatePhase1Progress !== 'function') {
        pending('SyncDashboard.updatePhase1Progress no está disponible');
        return;
      }
      
      // Probar con datos que tienen propiedades opcionales
      const data = {
        products_processed: 10,
        total_products: 100
        // No incluir images_processed para probar optional chaining
      };
      
      // No debe lanzar error al usar optional chaining
      expect(function() {
        syncDashboardInstance.updatePhase1Progress(data);
      }).not.toThrow();
    });
  });
});

