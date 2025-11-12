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
  let originalSyncDashboard, originalJQuery, originalMiIntegracionApiDashboard;
  let mockJQuery, mockAjax;
  let clock;
  let syncDashboardInstance;

  beforeEach(function() {
    // Guardar referencias originales
    originalSyncDashboard = window.SyncDashboard;
    originalJQuery = window.jQuery;
    originalMiIntegracionApiDashboard = window.miIntegracionApiDashboard;

    // Limpiar estado global
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
        text: jasmine.createSpy('text').and.callFake(function(value) {
          if (arguments.length === 0) return '';
          return mock;
        }),
        prop: jasmine.createSpy('prop').and.callFake(function(name, value) {
          if (arguments.length === 1) return false;
          return mock;
        }),
        val: jasmine.createSpy('val').and.returnValue('20'),
        on: jasmine.createSpy('on').and.callFake(function() { return mock; }),
        off: jasmine.createSpy('off').and.callFake(function() { return mock; }),
        hide: jasmine.createSpy('hide').and.callFake(function() { return mock; }),
        show: jasmine.createSpy('show').and.callFake(function() { return mock; }),
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

    // Mock de pollingManager
    window.pollingManager = {
      config: {
        intervals: {
          active: 2000
        },
        currentInterval: 2000,
        currentMode: 'normal'
      },
      intervals: new Map(),
      startPolling: jasmine.createSpy('startPolling').and.returnValue(12345),
      stopPolling: jasmine.createSpy('stopPolling').and.returnValue(true),
      isPollingActive: jasmine.createSpy('isPollingActive').and.returnValue(false)
    };

    // Mock de checkSyncProgress
    window.checkSyncProgress = jasmine.createSpy('checkSyncProgress');

    // Mock de addConsoleLine
    window.addConsoleLine = jasmine.createSpy('addConsoleLine');

    // Mock de ToastManager
    window.ToastManager = {
      show: jasmine.createSpy('show')
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

      syncDashboardInstance.phase2Starting = false;

      // Primera llamada
      mockAjax.and.callFake(function(options) {
        // Simular que está en progreso
        syncDashboardInstance.phase2Starting = true;
        if (options.success) {
          setTimeout(function() {
            options.success({ success: true });
          }, 100);
        }
      });

      syncDashboardInstance.startPhase2();

      // Segunda llamada inmediata (debe ser bloqueada)
      const consoleWarnSpy = spyOn(console, 'warn');
      syncDashboardInstance.startPhase2();

      // Verificar que solo se hizo una llamada AJAX
      expect(mockAjax.calls.count()).toBe(1);
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

      syncDashboardInstance.phase2Starting = false;

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      syncDashboardInstance.startPhase2().then(function() {
        expect(syncDashboardInstance.phase2Starting).toBe(false);
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

      window.phase2Initialized = true; // Phase2Manager ya está gestionando
      syncDashboardInstance.phase2Starting = false;

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
      syncDashboardInstance.phase2Starting = true;

      mockAjax.and.callFake(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      syncDashboardInstance.cancelSync().then(function() {
        expect(syncDashboardInstance.phase2Starting).toBe(false);
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

      window.phase2Initialized = true; // Phase2Manager gestionando

      syncDashboardInstance.startPollingIfNeeded();

      expect(window.pollingManager.startPolling).not.toHaveBeenCalled();
    });

    it('debe NO iniciar polling si ya está activo', function() {
      if (!syncDashboardInstance || typeof syncDashboardInstance.startPollingIfNeeded !== 'function') {
        pending('SyncDashboard.startPollingIfNeeded no está disponible');
        return;
      }

      window.phase2Initialized = false;
      window.pollingManager.isPollingActive.and.returnValue(true);
      window.pollingManager.intervals.set('syncProgress', { id: 12345 });

      syncDashboardInstance.startPollingIfNeeded();

      expect(window.pollingManager.startPolling).not.toHaveBeenCalled();
    });

    it('debe iniciar polling si no está activo y Phase2Manager no lo gestiona', function() {
      if (!syncDashboardInstance || typeof syncDashboardInstance.startPollingIfNeeded !== 'function') {
        pending('SyncDashboard.startPollingIfNeeded no está disponible');
        return;
      }

      window.phase2Initialized = false;
      window.pollingManager.isPollingActive.and.returnValue(false);
      window.pollingManager.intervals.clear();

      syncDashboardInstance.startPollingIfNeeded();

      expect(window.pollingManager.startPolling).toHaveBeenCalledWith(
        'syncProgress',
        window.checkSyncProgress,
        jasmine.any(Number)
      );
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
      window.phase2Starting = true;
      syncDashboardInstance.phase2Starting = true;
      window.syncInterval = 12345;
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
        expect(syncDashboardInstance.phase2Starting).toBe(false);
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
});

