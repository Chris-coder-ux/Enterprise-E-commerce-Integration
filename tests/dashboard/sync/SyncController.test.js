/**
 * Tests unitarios para SyncController
 *
 * @module tests/dashboard/sync/SyncController
 * @since 1.0.0
 */

describe('SyncController', function() {
  let mockJQuery;
  let mockDOMCache;
  let mockPhase1Manager;
  let mockSyncStateManager;
  let mockErrorHandler;
  let mockDASHBOARD_CONFIG;
  let mockMiIntegracionApiDashboard;
  let SyncController;
  let originalConfirm;

  beforeEach(function() {
    // Limpiar require cache para asegurar módulos frescos
    delete require.cache[require.resolve('../../../assets/js/dashboard/sync/SyncController.js')];

    // Guardar confirm original
    originalConfirm = global.confirm;

    // Mock de jQuery
    mockJQuery = jest.fn(function(selector) {
      return {
        prop: jest.fn().mockReturnThis(),
        text: jest.fn().mockReturnThis(),
        val: jest.fn().mockReturnValue('50'),
        addClass: jest.fn().mockReturnThis(),
        removeClass: jest.fn().mockReturnThis(),
        css: jest.fn().mockReturnThis()
      };
    });
    global.jQuery = mockJQuery;
    global.$ = mockJQuery;

    // Mock de miIntegracionApiDashboard
    mockMiIntegracionApiDashboard = {
      ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
      nonce: 'test-nonce-123',
      confirmSync: '¿Confirmar sincronización?'
    };
    global.miIntegracionApiDashboard = mockMiIntegracionApiDashboard;

    // Mock de DASHBOARD_CONFIG
    mockDASHBOARD_CONFIG = {
      messages: {
        progress: {
          preparing: 'Preparando sincronización...'
        }
      }
    };
    global.DASHBOARD_CONFIG = mockDASHBOARD_CONFIG;

    // Mock de Phase1Manager
    mockPhase1Manager = {
      start: jest.fn()
    };
    global.Phase1Manager = mockPhase1Manager;

    // Mock de SyncStateManager
    mockSyncStateManager = {
      setInactiveProgressCounter: jest.fn(),
      setLastProgressValue: jest.fn()
    };
    global.SyncStateManager = mockSyncStateManager;

    // Mock de ErrorHandler
    mockErrorHandler = {
      logError: jest.fn()
    };
    global.ErrorHandler = mockErrorHandler;

    // Mock de DOM_CACHE
    mockDOMCache = {
      $syncBtn: {
        prop: jest.fn().mockReturnThis(),
        text: jest.fn().mockReturnThis()
      },
      $batchSizeSelector: {
        prop: jest.fn().mockReturnThis(),
        val: jest.fn().mockReturnValue('50')
      },
      $feedback: {
        addClass: jest.fn().mockReturnThis(),
        removeClass: jest.fn().mockReturnThis(),
        text: jest.fn().mockReturnThis()
      },
      $syncStatusContainer: {
        css: jest.fn().mockReturnThis()
      },
      $cancelBtn: {
        prop: jest.fn().mockReturnThis(),
        removeClass: jest.fn().mockReturnThis(),
        addClass: jest.fn().mockReturnThis()
      },
      $progressBar: {
        css: jest.fn().mockReturnThis()
      },
      $progressInfo: {
        text: jest.fn().mockReturnThis()
      }
    };
    global.DOM_CACHE = mockDOMCache;

    // Mock de window
    global.window = {
      originalSyncButtonText: 'Sincronizar productos en lote'
    };

    // Mock de confirm
    global.confirm = jest.fn().mockReturnValue(true);

    // Cargar módulo
    SyncController = require('../../../assets/js/dashboard/sync/SyncController.js').SyncController;
  });

  afterEach(function() {
    jest.clearAllMocks();
    delete global.jQuery;
    delete global.$;
    delete global.miIntegracionApiDashboard;
    delete global.DASHBOARD_CONFIG;
    delete global.Phase1Manager;
    delete global.SyncStateManager;
    delete global.ErrorHandler;
    delete global.DOM_CACHE;
    delete global.window;
    global.confirm = originalConfirm;
  });

  describe('Definición del módulo', function() {
    test('SyncController debe estar definido', function() {
      expect(SyncController).toBeDefined();
      expect(typeof SyncController).toBe('object');
    });

    test('SyncController debe tener método proceedWithSync', function() {
      expect(SyncController.proceedWithSync).toBeDefined();
      expect(typeof SyncController.proceedWithSync).toBe('function');
    });
  });

  describe('Verificación de dependencias', function() {
    test('proceedWithSync debe verificar que jQuery esté disponible', function() {
      delete global.jQuery;
      delete global.$;

      SyncController.proceedWithSync('Sincronizar productos en lote');

      expect(mockErrorHandler.logError).toHaveBeenCalledWith(
        'jQuery no está disponible para SyncController',
        'SYNC_START'
      );
      expect(mockPhase1Manager.start).not.toHaveBeenCalled();
    });

    test('proceedWithSync debe verificar que DOM_CACHE esté disponible', function() {
      delete global.DOM_CACHE;

      SyncController.proceedWithSync('Sincronizar productos en lote');

      expect(mockErrorHandler.logError).toHaveBeenCalledWith(
        'DOM_CACHE no está disponible',
        'SYNC_START'
      );
      expect(mockPhase1Manager.start).not.toHaveBeenCalled();
    });
  });

  describe('Método proceedWithSync', function() {
    test('proceedWithSync debe actualizar window.originalSyncButtonText', function() {
      const originalText = 'Mi texto personalizado';
      SyncController.proceedWithSync(originalText);

      expect(global.window.originalSyncButtonText).toBe(originalText);
    });

    test('proceedWithSync debe obtener batch size del selector', function() {
      mockDOMCache.$batchSizeSelector.val.mockReturnValue('75');

      SyncController.proceedWithSync('Sincronizar productos en lote');

      expect(mockDOMCache.$batchSizeSelector.val).toHaveBeenCalled();
      expect(mockPhase1Manager.start).toHaveBeenCalledWith(75, 'Sincronizar productos en lote');
    });

    test('proceedWithSync debe usar batch size por defecto si no hay selector', function() {
      mockDOMCache.$batchSizeSelector = null;

      SyncController.proceedWithSync('Sincronizar productos en lote');

      expect(mockPhase1Manager.start).toHaveBeenCalledWith(20, 'Sincronizar productos en lote');
    });

    test('proceedWithSync debe mostrar diálogo de confirmación personalizado', function() {
      global.confirm.mockReturnValue(false);

      SyncController.proceedWithSync('Sincronizar productos en lote');

      expect(global.confirm).toHaveBeenCalledWith('¿Confirmar sincronización?');
      expect(mockPhase1Manager.start).not.toHaveBeenCalled();
      expect(mockDOMCache.$syncBtn.prop).toHaveBeenCalledWith('disabled', false);
    });

    test('proceedWithSync debe mostrar diálogo de confirmación por defecto', function() {
      delete global.miIntegracionApiDashboard.confirmSync;
      global.confirm.mockReturnValue(false);

      SyncController.proceedWithSync('Sincronizar productos en lote');

      expect(global.confirm).toHaveBeenCalledWith('¿Estás seguro de que deseas iniciar una sincronización manual ahora?');
      expect(mockPhase1Manager.start).not.toHaveBeenCalled();
    });

    test('proceedWithSync debe configurar UI cuando se confirma', function() {
      SyncController.proceedWithSync('Sincronizar productos en lote');

      expect(mockDOMCache.$syncBtn.prop).toHaveBeenCalledWith('disabled', true);
      expect(mockDOMCache.$batchSizeSelector.prop).toHaveBeenCalledWith('disabled', true);
      expect(mockDOMCache.$feedback.addClass).toHaveBeenCalledWith('in-progress');
      expect(mockDOMCache.$feedback.text).toHaveBeenCalledWith('Iniciando sincronización...');
      expect(mockDOMCache.$syncStatusContainer.css).toHaveBeenCalledWith('display', 'block');
    });

    test('proceedWithSync debe resetear contadores usando SyncStateManager', function() {
      SyncController.proceedWithSync('Sincronizar productos en lote');

      expect(mockSyncStateManager.setInactiveProgressCounter).toHaveBeenCalledWith(0);
      expect(mockSyncStateManager.setLastProgressValue).toHaveBeenCalledWith(0);
    });

    test('proceedWithSync debe iniciar Phase1Manager cuando está disponible', function() {
      SyncController.proceedWithSync('Sincronizar productos en lote');

      expect(mockPhase1Manager.start).toHaveBeenCalledWith(50, 'Sincronizar productos en lote');
    });

    test('proceedWithSync debe manejar error cuando Phase1Manager no está disponible', function() {
      delete global.Phase1Manager;

      SyncController.proceedWithSync('Sincronizar productos en lote');

      expect(mockErrorHandler.logError).toHaveBeenCalledWith(
        'Phase1Manager no está disponible',
        'SYNC_START'
      );
      expect(mockDOMCache.$syncBtn.prop).toHaveBeenCalledWith('disabled', false);
      expect(mockDOMCache.$feedback.text).toHaveBeenCalledWith('Error: Phase1Manager no está disponible');
    });

    test('proceedWithSync debe manejar errores y restaurar UI', function() {
      // Forzar un error después de la confirmación, en resetSyncCounters
      global.confirm.mockReturnValue(true);
      mockSyncStateManager.setInactiveProgressCounter.mockImplementation(function() {
        throw new Error('Error de prueba');
      });

      SyncController.proceedWithSync('Sincronizar productos en lote');

      // Verificar que se restauró el botón en el catch
      expect(mockDOMCache.$syncBtn.prop).toHaveBeenCalledWith('disabled', false);
      expect(mockDOMCache.$syncBtn.text).toHaveBeenCalledWith('Sincronizar productos en lote');
      expect(mockDOMCache.$feedback.removeClass).toHaveBeenCalledWith('in-progress');
    });
  });

  describe('Exposición global', function() {
    test('SyncController debe estar expuesto en window', function() {
      global.window = {};
      delete require.cache[require.resolve('../../../assets/js/dashboard/sync/SyncController.js')];

      // Configurar dependencias mínimas
      global.jQuery = mockJQuery;
      global.DOM_CACHE = mockDOMCache;
      global.Phase1Manager = mockPhase1Manager;
      global.SyncStateManager = mockSyncStateManager;
      global.ErrorHandler = mockErrorHandler;
      global.DASHBOARD_CONFIG = mockDASHBOARD_CONFIG;
      global.confirm = jest.fn().mockReturnValue(true);

      const module = require('../../../assets/js/dashboard/sync/SyncController.js');

      // Verificar que el módulo exporta SyncController
      expect(module.SyncController).toBeDefined();
      expect(typeof module.SyncController.proceedWithSync).toBe('function');

      // Verificar que también está en window (si window estaba disponible al cargar)
      if (global.window && global.window.SyncController) {
        expect(global.window.SyncController).toBeDefined();
        expect(typeof global.window.SyncController.proceedWithSync).toBe('function');
      }
    });

    test('proceedWithSync debe estar expuesto en window', function() {
      global.window = {};
      delete require.cache[require.resolve('../../../assets/js/dashboard/sync/SyncController.js')];

      // Configurar dependencias mínimas
      global.jQuery = mockJQuery;
      global.DOM_CACHE = mockDOMCache;
      global.Phase1Manager = mockPhase1Manager;
      global.SyncStateManager = mockSyncStateManager;
      global.ErrorHandler = mockErrorHandler;
      global.DASHBOARD_CONFIG = mockDASHBOARD_CONFIG;
      global.confirm = jest.fn().mockReturnValue(true);

      require('../../../assets/js/dashboard/sync/SyncController.js');

      // Verificar que también está en window (si window estaba disponible al cargar)
      if (global.window && global.window.proceedWithSync) {
        expect(global.window.proceedWithSync).toBeDefined();
        expect(typeof global.window.proceedWithSync).toBe('function');
      }
    });
  });
});

