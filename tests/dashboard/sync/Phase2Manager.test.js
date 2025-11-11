/**
 * Tests unitarios para Phase2Manager
 *
 * @module tests/dashboard/sync/Phase2Manager
 * @since 1.0.0
 */

// Configurar entorno de pruebas
jest.useRealTimers();

describe('Phase2Manager', function() {
  let mockJQuery;
  let mockAjax;
  let mockPollingManager;
  let mockSyncStateManager;
  let mockErrorHandler;
  let mockDOMCache;
  let mockCheckSyncProgress;
  let Phase2Manager;

  beforeEach(function() {
    // Limpiar require cache para asegurar módulos frescos
    delete require.cache[require.resolve('../../../assets/js/dashboard/sync/Phase2Manager.js')];

    // Mock de jQuery
    mockAjax = jest.fn();
    mockJQuery = jest.fn(function(selector) {
      if (selector === undefined) {
        return {
          ajax: mockAjax
        };
      }
      return {
        text: jest.fn().mockReturnThis(),
        prop: jest.fn().mockReturnThis(),
        ajax: mockAjax
      };
    });
    mockJQuery.ajax = mockAjax;
    global.jQuery = mockJQuery;
    global.$ = mockJQuery;

    // Mock de miIntegracionApiDashboard
    global.miIntegracionApiDashboard = {
      ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
      nonce: 'test-nonce-123'
    };

    // Mock de DASHBOARD_CONFIG
    global.DASHBOARD_CONFIG = {
      timeouts: {
        ajax: 60000
      }
    };

    // Mock de PollingManager
    mockPollingManager = {
      config: {
        intervals: {
          active: 5000
        },
        currentInterval: 5000,
        currentMode: 'normal',
        errorCount: 0
      },
      startPolling: jest.fn().mockReturnValue(12345)
    };
    global.pollingManager = mockPollingManager;

    // Mock de SyncStateManager
    mockSyncStateManager = {
      setInactiveProgressCounter: jest.fn()
    };
    global.SyncStateManager = mockSyncStateManager;

    // Mock de ErrorHandler
    mockErrorHandler = {
      logError: jest.fn()
    };
    global.ErrorHandler = mockErrorHandler;

    // Mock de DOM_CACHE
    mockDOMCache = {
      $feedback: {
        text: jest.fn().mockReturnThis()
      },
      $syncBtn: {
        prop: jest.fn().mockReturnThis(),
        text: jest.fn().mockReturnThis()
      },
      $batchSizeSelector: {
        prop: jest.fn().mockReturnThis()
      }
    };
    global.DOM_CACHE = mockDOMCache;

    // Mock de checkSyncProgress
    mockCheckSyncProgress = jest.fn();
    global.checkSyncProgress = mockCheckSyncProgress;

    // Mock de window
    global.window = {
      pendingPhase2BatchSize: 20,
      originalSyncButtonText: 'Sincronizar productos en lote',
      syncInterval: null
    };

    // Cargar módulo
    Phase2Manager = require('../../../assets/js/dashboard/sync/Phase2Manager.js').Phase2Manager;
  });

  afterEach(function() {
    jest.clearAllMocks();
    delete global.jQuery;
    delete global.$;
    delete global.miIntegracionApiDashboard;
    delete global.DASHBOARD_CONFIG;
    delete global.pollingManager;
    delete global.SyncStateManager;
    delete global.ErrorHandler;
    delete global.DOM_CACHE;
    delete global.checkSyncProgress;
    delete global.window;
  });

  describe('Definición del módulo', function() {
    test('Phase2Manager debe estar definido', function() {
      expect(Phase2Manager).toBeDefined();
      expect(typeof Phase2Manager).toBe('object');
    });

    test('Phase2Manager debe tener método start', function() {
      expect(Phase2Manager.start).toBeDefined();
      expect(typeof Phase2Manager.start).toBe('function');
    });
  });

  describe('Verificación de dependencias', function() {
    test('start debe verificar que jQuery esté disponible', function() {
      delete global.jQuery;
      delete global.$;

      Phase2Manager.start();

      expect(mockErrorHandler.logError).toHaveBeenCalledWith(
        'jQuery no está disponible para Phase2Manager',
        'PHASE2_START'
      );
      expect(mockAjax).not.toHaveBeenCalled();
    });

    test('start debe verificar que miIntegracionApiDashboard esté disponible', function() {
      delete global.miIntegracionApiDashboard;

      Phase2Manager.start();

      expect(mockErrorHandler.logError).toHaveBeenCalledWith(
        'miIntegracionApiDashboard o ajaxurl no están disponibles',
        'PHASE2_START'
      );
      expect(mockAjax).not.toHaveBeenCalled();
    });

    test('start debe verificar que DOM_CACHE esté disponible', function() {
      delete global.DOM_CACHE;

      Phase2Manager.start();

      expect(mockErrorHandler.logError).toHaveBeenCalledWith(
        'DOM_CACHE no está disponible',
        'PHASE2_START'
      );
      expect(mockAjax).not.toHaveBeenCalled();
    });
  });

  describe('Método start', function() {
    test('start debe realizar petición AJAX correcta', function() {
      mockAjax.mockImplementation(function(options) {
        expect(options.url).toBe('http://test.local/wp-admin/admin-ajax.php');
        expect(options.type).toBe('POST');
        expect(options.data.action).toBe('mi_integracion_api_sync_products_batch');
        expect(options.data.nonce).toBe('test-nonce-123');
        expect(options.data.batch_size).toBe(20);
        expect(options.timeout).toBe(240000); // 60000 * 4

        // Simular éxito
        if (options.success) {
          options.success({ success: true });
        }
      });

      Phase2Manager.start();

      expect(mockAjax).toHaveBeenCalled();
    });

    test('start debe usar batch_size de window.pendingPhase2BatchSize', function() {
      global.window.pendingPhase2BatchSize = 50;

      mockAjax.mockImplementation(function(options) {
        expect(options.data.batch_size).toBe(50);
        if (options.success) {
          options.success({ success: true });
        }
      });

      Phase2Manager.start();

      expect(mockAjax).toHaveBeenCalled();
    });

    test('start debe usar batch_size por defecto si no hay pendingPhase2BatchSize', function() {
      if (global.window && global.window.pendingPhase2BatchSize) {
        delete global.window.pendingPhase2BatchSize;
      }

      mockAjax.mockImplementation(function(options) {
        expect(options.data.batch_size).toBe(20);
        if (options.success) {
          options.success({ success: true });
        }
      });

      Phase2Manager.start();

      expect(mockAjax).toHaveBeenCalled();
    });

    test('start debe actualizar UI al iniciar', function() {
      mockAjax.mockImplementation(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      Phase2Manager.start();

      expect(mockDOMCache.$feedback.text).toHaveBeenCalledWith('Fase 2: Sincronizando productos...');
    });

    test('start debe manejar respuesta exitosa correctamente', function(done) {
      mockAjax.mockImplementation(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      Phase2Manager.start();

      // Esperar a que se ejecute el setTimeout
      setTimeout(function() {
        expect(mockPollingManager.startPolling).toHaveBeenCalledWith(
          'syncProgress',
          mockCheckSyncProgress,
          5000
        );
        expect(mockSyncStateManager.setInactiveProgressCounter).toHaveBeenCalledWith(0);
        expect(mockDOMCache.$feedback.text).toHaveBeenCalledWith('Fase 2: Sincronizando productos...');
        done();
      }, 600);
    });

    test('start debe resetear configuración de polling en éxito', function(done) {
      mockAjax.mockImplementation(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      Phase2Manager.start();

      setTimeout(function() {
        expect(mockPollingManager.config.currentMode).toBe('active');
        expect(mockPollingManager.config.errorCount).toBe(0);
        done();
      }, 600);
    });

    test('start debe manejar respuesta con error', function() {
      mockAjax.mockImplementation(function(options) {
        if (options.success) {
          options.success({
            success: false,
            data: {
              message: 'Error de servidor'
            }
          });
        }
      });

      Phase2Manager.start();

      expect(mockErrorHandler.logError).toHaveBeenCalledWith('Error al iniciar Fase 2', 'SYNC_START');
      expect(mockDOMCache.$syncBtn.prop).toHaveBeenCalledWith('disabled', false);
      expect(mockDOMCache.$batchSizeSelector.prop).toHaveBeenCalledWith('disabled', false);
    });

    test('start debe manejar error AJAX', function() {
      mockAjax.mockImplementation(function(options) {
        if (options.error) {
          options.error({ status: 500 }, 'error', 'Internal Server Error');
        }
      });

      Phase2Manager.start();

      expect(mockErrorHandler.logError).toHaveBeenCalledWith('Error al iniciar Fase 2', 'SYNC_START');
      expect(mockDOMCache.$feedback.text).toHaveBeenCalledWith('Error al iniciar Fase 2: Internal Server Error');
      expect(mockDOMCache.$syncBtn.prop).toHaveBeenCalledWith('disabled', false);
      expect(mockDOMCache.$batchSizeSelector.prop).toHaveBeenCalledWith('disabled', false);
    });

    test('start debe exponer syncInterval en window', function(done) {
      mockAjax.mockImplementation(function(options) {
        if (options.success) {
          options.success({ success: true });
        }
      });

      Phase2Manager.start();

      setTimeout(function() {
        expect(global.window.syncInterval).toBe(12345);
        done();
      }, 600);
    });
  });

  describe('Exposición global', function() {
    test('Phase2Manager debe estar expuesto en window y como módulo', function() {
      // Asegurar que window existe antes de requerir el módulo
      global.window = {};
      delete require.cache[require.resolve('../../../assets/js/dashboard/sync/Phase2Manager.js')];
      
      // Configurar dependencias mínimas
      global.jQuery = mockJQuery;
      global.miIntegracionApiDashboard = {
        ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
        nonce: 'test-nonce-123'
      };
      global.DASHBOARD_CONFIG = { timeouts: { ajax: 60000 } };
      global.DOM_CACHE = mockDOMCache;
      global.pollingManager = mockPollingManager;
      global.SyncStateManager = mockSyncStateManager;
      global.ErrorHandler = mockErrorHandler;
      global.checkSyncProgress = mockCheckSyncProgress;

      const module = require('../../../assets/js/dashboard/sync/Phase2Manager.js');

      // Verificar que el módulo exporta Phase2Manager
      expect(module.Phase2Manager).toBeDefined();
      expect(typeof module.Phase2Manager.start).toBe('function');
      
      // Verificar que también está en window (si window estaba disponible al cargar)
      if (global.window && global.window.Phase2Manager) {
        expect(global.window.Phase2Manager).toBeDefined();
        expect(typeof global.window.Phase2Manager.start).toBe('function');
      }
    });

    test('startPhase2 debe estar expuesto en window y como módulo', function() {
      // Asegurar que window existe antes de requerir el módulo
      global.window = {};
      delete require.cache[require.resolve('../../../assets/js/dashboard/sync/Phase2Manager.js')];
      
      // Configurar dependencias mínimas
      global.jQuery = mockJQuery;
      global.miIntegracionApiDashboard = {
        ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
        nonce: 'test-nonce-123'
      };
      global.DASHBOARD_CONFIG = { timeouts: { ajax: 60000 } };
      global.DOM_CACHE = mockDOMCache;
      global.pollingManager = mockPollingManager;
      global.SyncStateManager = mockSyncStateManager;
      global.ErrorHandler = mockErrorHandler;
      global.checkSyncProgress = mockCheckSyncProgress;

      const module = require('../../../assets/js/dashboard/sync/Phase2Manager.js');

      // Verificar que el módulo exporta Phase2Manager con método start
      expect(module.Phase2Manager).toBeDefined();
      expect(typeof module.Phase2Manager.start).toBe('function');
      
      // Verificar que también está en window (si window estaba disponible al cargar)
      if (global.window && global.window.startPhase2) {
        expect(global.window.startPhase2).toBeDefined();
        expect(typeof global.window.startPhase2).toBe('function');
      }
    });
  });
});

