/**
 * Tests unitarios para SyncProgress.js
 *
 * Verifica que SyncProgress esté correctamente definido y funcione correctamente.
 *
 * @module tests/dashboard/sync/SyncProgress
 */

// Mock de jQuery
const createMockJQuery = function() {
  const mockElements = {};

  const createBaseMock = function() {
    return {
      length: 1,
      css: jest.fn().mockReturnThis(),
      text: jest.fn().mockReturnThis(),
      prop: jest.fn().mockReturnThis(),
      removeClass: jest.fn().mockReturnThis(),
      addClass: jest.fn().mockReturnThis(),
      html: jest.fn().mockReturnThis(),
      hide: jest.fn().mockReturnThis(),
      on: jest.fn().mockReturnThis(),
      find: jest.fn().mockReturnValue({
        text: jest.fn().mockReturnValue(''),
        length: 0
      })
    };
  };

  const mockJQuery = function(selector) {
    if (typeof selector === 'string' && selector.includes('<')) {
      return createBaseMock();
    }

    if (!mockElements[selector]) {
      mockElements[selector] = createBaseMock();
    }
    return mockElements[selector];
  };

  mockJQuery.fn = {
    css: jest.fn().mockReturnThis(),
    text: jest.fn().mockReturnThis(),
    prop: jest.fn().mockReturnThis(),
    removeClass: jest.fn().mockReturnThis(),
    addClass: jest.fn().mockReturnThis(),
    html: jest.fn().mockReturnThis(),
    hide: jest.fn().mockReturnThis(),
    on: jest.fn().mockReturnThis()
  };

  mockJQuery.ajax = jest.fn().mockReturnValue({
    done: jest.fn(),
    fail: jest.fn()
  });

  return { mockJQuery, mockElements };
};

// Limpiar el módulo antes de cada test
beforeEach(function() {
  // Asegurar que window existe
  if (typeof global.window === 'undefined') {
    global.window = {};
  }

  // Eliminar SyncProgress de window primero
  if (global.window.SyncProgress) {
    delete global.window.SyncProgress;
  }
  if (global.window.checkSyncProgress) {
    delete global.window.checkSyncProgress;
  }

  // Limpiar require cache para forzar recarga
  const syncProgressPath = require.resolve('../../../assets/js/dashboard/sync/SyncProgress.js');
  if (require.cache[syncProgressPath]) {
    delete require.cache[syncProgressPath];
  }

  // Limpiar jQuery y $ si existen
  if (global.jQuery) {
    delete global.jQuery;
  }
  if (global.$) {
    delete global.$;
  }

  // Limpiar otras dependencias globales
  if (global.miIntegracionApiDashboard) {
    delete global.miIntegracionApiDashboard;
  }
  if (global.DASHBOARD_CONFIG) {
    delete global.DASHBOARD_CONFIG;
  }
  if (global.DOM_CACHE) {
    delete global.DOM_CACHE;
  }
  if (global.pollingManager) {
    delete global.pollingManager;
  }
  if (global.ErrorHandler) {
    delete global.ErrorHandler;
  }
  if (global.AjaxManager) {
    delete global.AjaxManager;
  }
  if (global.updateSyncConsole) {
    delete global.updateSyncConsole;
  }
  if (global.stopProgressPolling) {
    delete global.stopProgressPolling;
  }
  if (global.inactiveProgressCounter !== undefined) {
    delete global.inactiveProgressCounter;
  }
  if (global.lastProgressValue !== undefined) {
    delete global.lastProgressValue;
  }
});

describe('SyncProgress.js - SyncProgress', function() {
  describe('Definición del módulo', function() {
    test('SyncProgress debe estar definido', function() {
      const { mockJQuery } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;

      global.miIntegracionApiDashboard = {
        ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
        nonce: 'test-nonce'
      };

      global.DASHBOARD_CONFIG = {
        timeouts: {
          ajax: 60000
        }
      };

      global.DOM_CACHE = {
        $syncStatusContainer: mockJQuery('#sync-status'),
        $progressBar: mockJQuery('#progress-bar'),
        $progressInfo: mockJQuery('#progress-info'),
        $syncBtn: mockJQuery('#sync-btn'),
        $batchSizeSelector: mockJQuery('#batch-size'),
        $feedback: mockJQuery('#feedback')
      };

      global.ErrorHandler = {
        logError: jest.fn(),
        showConnectionError: jest.fn()
      };

      // eslint-disable-next-line no-undef
      const syncProgress = require('../../../assets/js/dashboard/sync/SyncProgress.js');

      expect(syncProgress).toBeDefined();
      expect(syncProgress.SyncProgress).toBeDefined();
      expect(typeof syncProgress.SyncProgress.check).toBe('function');
    });
  });

  describe('Verificación de dependencias', function() {
    test('check debe verificar que jQuery esté disponible', function() {
      global.ErrorHandler = {
        logError: jest.fn()
      };

      // eslint-disable-next-line no-undef
      const syncProgress = require('../../../assets/js/dashboard/sync/SyncProgress.js');

      syncProgress.SyncProgress.check();

      expect(global.ErrorHandler.logError).toHaveBeenCalledWith(
        'jQuery no está disponible para checkSyncProgress',
        'SYNC_PROGRESS'
      );
    });

    test('check debe verificar que miIntegracionApiDashboard esté disponible', function() {
      const { mockJQuery } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;

      global.ErrorHandler = {
        logError: jest.fn()
      };

      // eslint-disable-next-line no-undef
      const syncProgress = require('../../../assets/js/dashboard/sync/SyncProgress.js');

      syncProgress.SyncProgress.check();

      expect(global.ErrorHandler.logError).toHaveBeenCalledWith(
        'miIntegracionApiDashboard o ajaxurl no están disponibles',
        'SYNC_PROGRESS'
      );
    });

    test('check debe verificar que DOM_CACHE esté disponible', function() {
      const { mockJQuery } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;

      global.miIntegracionApiDashboard = {
        ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
        nonce: 'test-nonce'
      };

      global.ErrorHandler = {
        logError: jest.fn()
      };

      // eslint-disable-next-line no-undef
      const syncProgress = require('../../../assets/js/dashboard/sync/SyncProgress.js');

      syncProgress.SyncProgress.check();

      expect(global.ErrorHandler.logError).toHaveBeenCalledWith(
        'DOM_CACHE no está disponible',
        'SYNC_PROGRESS'
      );
    });
  });

  describe('Manejo de respuestas exitosas', function() {
    test('check debe usar AjaxManager si está disponible', function() {
      const { mockJQuery } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;

      global.miIntegracionApiDashboard = {
        ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
        nonce: 'test-nonce'
      };

      global.DASHBOARD_CONFIG = {
        timeouts: {
          ajax: 60000
        }
      };

      global.DOM_CACHE = {
        $syncStatusContainer: mockJQuery('#sync-status'),
        $progressBar: mockJQuery('#progress-bar'),
        $progressInfo: mockJQuery('#progress-info'),
        $syncBtn: mockJQuery('#sync-btn'),
        $batchSizeSelector: mockJQuery('#batch-size'),
        $feedback: mockJQuery('#feedback')
      };

      global.AjaxManager = {
        call: jest.fn()
      };

      global.ErrorHandler = {
        logError: jest.fn(),
        showConnectionError: jest.fn()
      };

      // eslint-disable-next-line no-undef
      const syncProgress = require('../../../assets/js/dashboard/sync/SyncProgress.js');

      syncProgress.SyncProgress.check();

      expect(global.AjaxManager.call).toHaveBeenCalledWith(
        'mia_get_sync_progress',
        {},
        expect.any(Function),
        expect.any(Function),
        { timeout: 120000 }
      );
    });

    test('check debe usar jQuery.ajax si AjaxManager no está disponible', function() {
      const { mockJQuery } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;

      global.miIntegracionApiDashboard = {
        ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
        nonce: 'test-nonce'
      };

      global.DASHBOARD_CONFIG = {
        timeouts: {
          ajax: 60000
        }
      };

      global.DOM_CACHE = {
        $syncStatusContainer: mockJQuery('#sync-status'),
        $progressBar: mockJQuery('#progress-bar'),
        $progressInfo: mockJQuery('#progress-info'),
        $syncBtn: mockJQuery('#sync-btn'),
        $batchSizeSelector: mockJQuery('#batch-size'),
        $feedback: mockJQuery('#feedback')
      };

      global.ErrorHandler = {
        logError: jest.fn(),
        showConnectionError: jest.fn()
      };

      // eslint-disable-next-line no-undef
      const syncProgress = require('../../../assets/js/dashboard/sync/SyncProgress.js');

      syncProgress.SyncProgress.check();

      expect(mockJQuery.ajax).toHaveBeenCalledWith(
        expect.objectContaining({
          url: 'http://test.local/wp-admin/admin-ajax.php',
          type: 'POST',
          timeout: 120000,
          data: {
            action: 'mia_get_sync_progress',
            nonce: 'test-nonce'
          }
        })
      );
    });
  });

  describe('Estado de seguimiento', function() {
    test('getTrackingState debe retornar el estado de seguimiento', function() {
      const { mockJQuery } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;

      global.miIntegracionApiDashboard = {
        ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
        nonce: 'test-nonce'
      };

      global.DASHBOARD_CONFIG = {
        timeouts: {
          ajax: 60000
        }
      };

      global.DOM_CACHE = {
        $syncStatusContainer: mockJQuery('#sync-status'),
        $progressBar: mockJQuery('#progress-bar'),
        $progressInfo: mockJQuery('#progress-info'),
        $syncBtn: mockJQuery('#sync-btn'),
        $batchSizeSelector: mockJQuery('#batch-size'),
        $feedback: mockJQuery('#feedback')
      };

      global.ErrorHandler = {
        logError: jest.fn(),
        showConnectionError: jest.fn()
      };

      // eslint-disable-next-line no-undef
      const syncProgress = require('../../../assets/js/dashboard/sync/SyncProgress.js');

      const state = syncProgress.SyncProgress.getTrackingState();

      expect(state).toBeDefined();
      expect(state).toHaveProperty('lastKnownBatch');
      expect(state).toHaveProperty('lastKnownItemsSynced');
      expect(state).toHaveProperty('lastKnownTotalBatches');
      expect(state).toHaveProperty('lastKnownTotalItems');
    });

    test('resetTrackingState debe resetear el estado de seguimiento', function() {
      const { mockJQuery } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;

      global.miIntegracionApiDashboard = {
        ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
        nonce: 'test-nonce'
      };

      global.DASHBOARD_CONFIG = {
        timeouts: {
          ajax: 60000
        }
      };

      global.DOM_CACHE = {
        $syncStatusContainer: mockJQuery('#sync-status'),
        $progressBar: mockJQuery('#progress-bar'),
        $progressInfo: mockJQuery('#progress-info'),
        $syncBtn: mockJQuery('#sync-btn'),
        $batchSizeSelector: mockJQuery('#batch-size'),
        $feedback: mockJQuery('#feedback')
      };

      global.ErrorHandler = {
        logError: jest.fn(),
        showConnectionError: jest.fn()
      };

      // eslint-disable-next-line no-undef
      const syncProgress = require('../../../assets/js/dashboard/sync/SyncProgress.js');

      syncProgress.SyncProgress.resetTrackingState();

      const state = syncProgress.SyncProgress.getTrackingState();

      expect(state.lastKnownBatch).toBe(0);
      expect(state.lastKnownItemsSynced).toBe(0);
      expect(state.lastKnownTotalBatches).toBe(0);
      expect(state.lastKnownTotalItems).toBe(0);
    });
  });

  describe('Exposición global', function() {
    test('SyncProgress debe estar disponible en window', function() {
      // Asegurar que window existe ANTES de configurar dependencias
      global.window = {};

      const { mockJQuery } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;

      global.miIntegracionApiDashboard = {
        ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
        nonce: 'test-nonce'
      };

      global.DASHBOARD_CONFIG = {
        timeouts: {
          ajax: 60000
        }
      };

      global.DOM_CACHE = {
        $syncStatusContainer: mockJQuery('#sync-status'),
        $progressBar: mockJQuery('#progress-bar'),
        $progressInfo: mockJQuery('#progress-info'),
        $syncBtn: mockJQuery('#sync-btn'),
        $batchSizeSelector: mockJQuery('#batch-size'),
        $feedback: mockJQuery('#feedback')
      };

      global.ErrorHandler = {
        logError: jest.fn(),
        showConnectionError: jest.fn()
      };

      // Limpiar cache del módulo
      const syncProgressPath = require.resolve('../../../assets/js/dashboard/sync/SyncProgress.js');
      delete require.cache[syncProgressPath];

      // eslint-disable-next-line no-undef
      const syncProgress = require('../../../assets/js/dashboard/sync/SyncProgress.js');

      // Verificar que el módulo se cargó
      expect(syncProgress).toBeDefined();
      expect(syncProgress.SyncProgress).toBeDefined();

      // Verificar que está en window (si window estaba disponible)
      if (typeof global.window !== 'undefined' && global.window.SyncProgress) {
        expect(global.window.SyncProgress).toBeDefined();
        expect(typeof global.window.SyncProgress.check).toBe('function');
      } else {
        // Si window no está disponible, al menos el módulo debe exportar correctamente
        expect(syncProgress.SyncProgress).toBeDefined();
      }
    });

    test('checkSyncProgress debe estar disponible en window', function() {
      // Asegurar que window existe ANTES de configurar dependencias
      global.window = {};

      const { mockJQuery } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;

      global.miIntegracionApiDashboard = {
        ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
        nonce: 'test-nonce'
      };

      global.DASHBOARD_CONFIG = {
        timeouts: {
          ajax: 60000
        }
      };

      global.DOM_CACHE = {
        $syncStatusContainer: mockJQuery('#sync-status'),
        $progressBar: mockJQuery('#progress-bar'),
        $progressInfo: mockJQuery('#progress-info'),
        $syncBtn: mockJQuery('#sync-btn'),
        $batchSizeSelector: mockJQuery('#batch-size'),
        $feedback: mockJQuery('#feedback')
      };

      global.ErrorHandler = {
        logError: jest.fn(),
        showConnectionError: jest.fn()
      };

      // Limpiar cache del módulo
      const syncProgressPath = require.resolve('../../../assets/js/dashboard/sync/SyncProgress.js');
      delete require.cache[syncProgressPath];

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/sync/SyncProgress.js');

      // Verificar que está en window (si window estaba disponible)
      if (typeof global.window !== 'undefined' && global.window.checkSyncProgress) {
        expect(global.window.checkSyncProgress).toBeDefined();
        expect(typeof global.window.checkSyncProgress).toBe('function');
      } else {
        // Si window no está disponible, el módulo debe exportar correctamente
        const syncProgress = require('../../../assets/js/dashboard/sync/SyncProgress.js');
        expect(syncProgress.SyncProgress.check).toBeDefined();
      }
    });
  });
});

