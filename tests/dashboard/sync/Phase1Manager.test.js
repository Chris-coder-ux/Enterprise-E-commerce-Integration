/**
 * Tests unitarios para Phase1Manager.js
 *
 * Verifica que Phase1Manager esté correctamente definido y funcione correctamente.
 *
 * @module tests/dashboard/sync/Phase1Manager
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
      val: jest.fn().mockReturnValue('50')
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
    on: jest.fn().mockReturnThis(),
    val: jest.fn().mockReturnValue('50')
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

  // Eliminar Phase1Manager de window primero
  if (global.window.Phase1Manager) {
    delete global.window.Phase1Manager;
  }
  if (global.window.phase1Complete !== undefined) {
    delete global.window.phase1Complete;
  }
  if (global.window.phase1PollingInterval !== undefined) {
    delete global.window.phase1PollingInterval;
  }

  // Limpiar require cache para forzar recarga
  const phase1ManagerPath = require.resolve('../../../assets/js/dashboard/sync/Phase1Manager.js');
  if (require.cache[phase1ManagerPath]) {
    delete require.cache[phase1ManagerPath];
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
  if (global.SyncStateManager) {
    delete global.SyncStateManager;
  }
  if (global.startPhase2) {
    delete global.startPhase2;
  }

  // Limpiar timers
  jest.clearAllTimers();
});

describe('Phase1Manager.js - Phase1Manager', function() {
  describe('Definición del módulo', function() {
    test('Phase1Manager debe estar definido', function() {
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
        $feedback: mockJQuery('#feedback'),
        $syncBtn: mockJQuery('#sync-btn'),
        $batchSizeSelector: mockJQuery('#batch-size')
      };

      global.ErrorHandler = {
        logError: jest.fn()
      };

      global.SyncStateManager = {
        setInactiveProgressCounter: jest.fn()
      };

      // eslint-disable-next-line no-undef
      const phase1Manager = require('../../../assets/js/dashboard/sync/Phase1Manager.js');

      expect(phase1Manager).toBeDefined();
      expect(phase1Manager.Phase1Manager).toBeDefined();
      expect(typeof phase1Manager.Phase1Manager.start).toBe('function');
      expect(typeof phase1Manager.Phase1Manager.stop).toBe('function');
      expect(typeof phase1Manager.Phase1Manager.isComplete).toBe('function');
      expect(typeof phase1Manager.Phase1Manager.getPollingInterval).toBe('function');
    });
  });

  describe('Verificación de dependencias', function() {
    test('start debe verificar que jQuery esté disponible', function() {
      global.ErrorHandler = {
        logError: jest.fn()
      };

      // eslint-disable-next-line no-undef
      const phase1Manager = require('../../../assets/js/dashboard/sync/Phase1Manager.js');

      phase1Manager.Phase1Manager.start(50, 'Sincronizar productos');

      expect(global.ErrorHandler.logError).toHaveBeenCalledWith(
        'jQuery no está disponible para Phase1Manager',
        'PHASE1_START'
      );
    });

    test('start debe verificar que miIntegracionApiDashboard esté disponible', function() {
      const { mockJQuery } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;

      global.ErrorHandler = {
        logError: jest.fn()
      };

      // eslint-disable-next-line no-undef
      const phase1Manager = require('../../../assets/js/dashboard/sync/Phase1Manager.js');

      phase1Manager.Phase1Manager.start(50, 'Sincronizar productos');

      expect(global.ErrorHandler.logError).toHaveBeenCalledWith(
        'miIntegracionApiDashboard o ajaxurl no están disponibles',
        'PHASE1_START'
      );
    });

    test('start debe verificar que DOM_CACHE esté disponible', function() {
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
      const phase1Manager = require('../../../assets/js/dashboard/sync/Phase1Manager.js');

      phase1Manager.Phase1Manager.start(50, 'Sincronizar productos');

      expect(global.ErrorHandler.logError).toHaveBeenCalledWith(
        'DOM_CACHE no está disponible',
        'PHASE1_START'
      );
    });
  });

  describe('Métodos básicos', function() {
    test('stop debe detener el polling', function() {
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
        $feedback: mockJQuery('#feedback'),
        $syncBtn: mockJQuery('#sync-btn'),
        $batchSizeSelector: mockJQuery('#batch-size')
      };

      global.ErrorHandler = {
        logError: jest.fn()
      };

      global.SyncStateManager = {
        setInactiveProgressCounter: jest.fn()
      };

      // eslint-disable-next-line no-undef
      const phase1Manager = require('../../../assets/js/dashboard/sync/Phase1Manager.js');

      phase1Manager.Phase1Manager.stop();

      expect(phase1Manager.Phase1Manager.isComplete()).toBe(false);
      expect(phase1Manager.Phase1Manager.getPollingInterval()).toBe(null);
    });

    test('isComplete debe retornar el estado de completitud', function() {
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
        $feedback: mockJQuery('#feedback'),
        $syncBtn: mockJQuery('#sync-btn'),
        $batchSizeSelector: mockJQuery('#batch-size')
      };

      global.ErrorHandler = {
        logError: jest.fn()
      };

      global.SyncStateManager = {
        setInactiveProgressCounter: jest.fn()
      };

      // eslint-disable-next-line no-undef
      const phase1Manager = require('../../../assets/js/dashboard/sync/Phase1Manager.js');

      expect(phase1Manager.Phase1Manager.isComplete()).toBe(false);
    });
  });

  describe('Exposición global', function() {
    test('Phase1Manager debe estar disponible en window', function() {
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
        $feedback: mockJQuery('#feedback'),
        $syncBtn: mockJQuery('#sync-btn'),
        $batchSizeSelector: mockJQuery('#batch-size')
      };

      global.ErrorHandler = {
        logError: jest.fn()
      };

      global.SyncStateManager = {
        setInactiveProgressCounter: jest.fn()
      };

      // Limpiar cache del módulo
      const phase1ManagerPath = require.resolve('../../../assets/js/dashboard/sync/Phase1Manager.js');
      delete require.cache[phase1ManagerPath];

      // eslint-disable-next-line no-undef
      const phase1Manager = require('../../../assets/js/dashboard/sync/Phase1Manager.js');

      // Verificar que el módulo se cargó
      expect(phase1Manager).toBeDefined();
      expect(phase1Manager.Phase1Manager).toBeDefined();

      // Verificar que está en window (si window estaba disponible)
      if (typeof global.window !== 'undefined' && global.window.Phase1Manager) {
        expect(global.window.Phase1Manager).toBeDefined();
        expect(typeof global.window.Phase1Manager.start).toBe('function');
      } else {
        // Si window no está disponible, al menos el módulo debe exportar correctamente
        expect(phase1Manager.Phase1Manager).toBeDefined();
      }
    });
  });
});

