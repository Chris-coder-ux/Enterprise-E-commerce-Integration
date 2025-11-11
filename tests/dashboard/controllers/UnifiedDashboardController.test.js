/**
 * Tests unitarios para UnifiedDashboardController.js
 *
 * @module tests/dashboard/controllers/UnifiedDashboardController.test
 * @since 1.0.0
 */

// Configurar entorno de pruebas
jest.useFakeTimers();

describe('UnifiedDashboardController', function() {
  let UnifiedDashboardController;
  let mockJQuery;
  let mockWindow;
  let mockLocalStorage;

  beforeEach(function() {
    // Limpiar require cache para asegurar módulos frescos
    delete require.cache[require.resolve('../../../assets/js/dashboard/controllers/UnifiedDashboardController.js')];

    // Mock de localStorage
    mockLocalStorage = {
      getItem: jest.fn(),
      setItem: jest.fn()
    };

    // Mock de window
    mockWindow = {
      localStorage: mockLocalStorage,
      UnifiedDashboard: {
        init: jest.fn()
      },
      syncDashboard: {
        loadCurrentStatus: jest.fn()
      },
      SystemEventManager: {
        init: jest.fn()
      },
      ResponsiveLayout: {},
      AjaxManager: {}
    };
    global.window = mockWindow;

    // Mock de jQuery
    const createMockElement = function() {
      return {
        length: 1,
        fadeOut: jest.fn().mockReturnThis(),
        fadeIn: jest.fn().mockReturnThis(),
        on: jest.fn().mockReturnThis(),
        attr: jest.fn().mockReturnValue('test-button'),
        closest: jest.fn().mockReturnThis()
      };
    };

    mockJQuery = jest.fn(function(selector) {
      if (selector === document) {
        return {
          ready: jest.fn(function(callback) {
            if (callback) callback();
          })
        };
      }
      return createMockElement();
    });
    mockJQuery.fn = {};
    mockJQuery.ready = jest.fn(function(callback) {
      if (callback) callback();
    });
    global.jQuery = mockJQuery;
    global.$ = mockJQuery;

    // Mock de dependencias globales
    global.ErrorHandler = {};
    global.AjaxManager = {};
    global.pollingManager = {};

    // Cargar módulo
    UnifiedDashboardController = require('../../../assets/js/dashboard/controllers/UnifiedDashboardController.js').UnifiedDashboardController;
  });

  afterEach(function() {
    jest.clearAllMocks();
    jest.clearAllTimers();
    delete global.jQuery;
    delete global.$;
    delete global.window;
    delete global.ErrorHandler;
    delete global.AjaxManager;
    delete global.pollingManager;
  });

  describe('Módulo', function() {
    test('debe exportar UnifiedDashboardController', function() {
      expect(UnifiedDashboardController).toBeDefined();
      expect(typeof UnifiedDashboardController).toBe('object');
    });

    test('debe tener los métodos esperados', function() {
      expect(typeof UnifiedDashboardController.init).toBe('function');
      expect(typeof UnifiedDashboardController.toggleSidebar).toBe('function');
      expect(typeof UnifiedDashboardController.hideSidebar).toBe('function');
      expect(typeof UnifiedDashboardController.showSidebar).toBe('function');
      expect(typeof UnifiedDashboardController.getState).toBe('function');
      expect(typeof UnifiedDashboardController.reinit).toBe('function');
    });
  });

  describe('init', function() {
    test('debe inicializar el controlador correctamente', function() {
      UnifiedDashboardController.init();

      expect(UnifiedDashboardController.initialized).toBe(true);
      expect(UnifiedDashboardController.systems.errorHandler).toBe(true);
      expect(UnifiedDashboardController.systems.ajaxManager).toBe(true);
      expect(UnifiedDashboardController.systems.pollingManager).toBe(true);
    });

    test('no debe inicializar dos veces', function() {
      UnifiedDashboardController.init();
      const firstInit = UnifiedDashboardController.initialized;

      UnifiedDashboardController.init();

      expect(UnifiedDashboardController.initialized).toBe(firstInit);
    });

    test('debe inicializar sistemas de alto nivel', function() {
      UnifiedDashboardController.init();

      expect(mockWindow.UnifiedDashboard.init).toHaveBeenCalled();
      expect(mockWindow.SystemEventManager.init).toHaveBeenCalled();
    });
  });

  describe('initSidebar', function() {
    test('debe configurar eventos del sidebar', function() {
      UnifiedDashboardController.init();

      // Verificar que se registró el evento click
      const sidebarToggle = mockJQuery('.sidebar-toggle');
      expect(sidebarToggle.on).toHaveBeenCalled();
    });

    test('debe restaurar estado del sidebar desde localStorage', function() {
      mockLocalStorage.getItem.mockReturnValue('false');

      UnifiedDashboardController.init();

      expect(mockLocalStorage.getItem).toHaveBeenCalledWith('dashboard-sidebar-visible');
      expect(UnifiedDashboardController.isSidebarVisible).toBe(false);
    });
  });

  describe('toggleSidebar', function() {
    test('debe cambiar visibilidad del sidebar', function() {
      UnifiedDashboardController.isSidebarVisible = true;

      UnifiedDashboardController.toggleSidebar();

      expect(UnifiedDashboardController.isSidebarVisible).toBe(false);
    });

    test('debe mostrar sidebar si está oculto', function() {
      UnifiedDashboardController.isSidebarVisible = false;

      UnifiedDashboardController.toggleSidebar();

      expect(UnifiedDashboardController.isSidebarVisible).toBe(true);
    });
  });

  describe('hideSidebar', function() {
    test('debe ocultar el sidebar', function() {
      const $sidebar = mockJQuery('.mi-integracion-api-sidebar');

      UnifiedDashboardController.hideSidebar();

      expect($sidebar.fadeOut).toHaveBeenCalledWith(300);
      expect(UnifiedDashboardController.isSidebarVisible).toBe(false);
      expect(mockLocalStorage.setItem).toHaveBeenCalledWith('dashboard-sidebar-visible', 'false');
    });
  });

  describe('showSidebar', function() {
    test('debe mostrar el sidebar', function() {
      const $sidebar = mockJQuery('.mi-integracion-api-sidebar');

      UnifiedDashboardController.showSidebar();

      expect($sidebar.fadeIn).toHaveBeenCalledWith(300);
      expect(UnifiedDashboardController.isSidebarVisible).toBe(true);
      expect(mockLocalStorage.setItem).toHaveBeenCalledWith('dashboard-sidebar-visible', 'true');
    });
  });

  describe('loadData', function() {
    test('debe cargar datos iniciales', function() {
      UnifiedDashboardController.systems.syncDashboard = true;

      UnifiedDashboardController.loadData();

      // Avanzar timers para ejecutar setTimeout
      jest.advanceTimersByTime(1100);

      expect(mockWindow.syncDashboard.loadCurrentStatus).toHaveBeenCalled();
      expect(UnifiedDashboardController.lastSyncedData).toBeDefined();
      expect(UnifiedDashboardController.lastSyncedData.systems).toBeDefined();
    });
  });

  describe('getState', function() {
    test('debe devolver el estado actual del controlador', function() {
      UnifiedDashboardController.initialized = true;
      UnifiedDashboardController.isSidebarVisible = false;
      UnifiedDashboardController.lastSyncedData = { test: 'data' };

      const state = UnifiedDashboardController.getState();

      expect(state.initialized).toBe(true);
      expect(state.isSidebarVisible).toBe(false);
      expect(state.lastSyncedData).toEqual({ test: 'data' });
      expect(state.systems).toBeDefined();
    });
  });

  describe('reinit', function() {
    test('debe reinicializar el controlador', function() {
      UnifiedDashboardController.initialized = true;

      UnifiedDashboardController.reinit();

      expect(UnifiedDashboardController.initialized).toBe(false);
      // Después de reinit, init() se llama automáticamente
      expect(UnifiedDashboardController.initialized).toBe(true);
    });
  });

  describe('Exposición global', function() {
    test('debe exponer UnifiedDashboardController en window si está disponible', function() {
      // Limpiar cache
      jest.resetModules();
      delete require.cache[require.resolve('../../../assets/js/dashboard/controllers/UnifiedDashboardController.js')];

      // Configurar window
      global.window = {
        localStorage: mockLocalStorage
      };

      // Mock jQuery
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.ErrorHandler = {};
      global.AjaxManager = {};
      global.pollingManager = {};

      // Cargar módulo
      require('../../../assets/js/dashboard/controllers/UnifiedDashboardController.js');

      // Verificar que se expuso en window
      expect(global.window.UnifiedDashboardController).toBeDefined();
      expect(typeof global.window.UnifiedDashboardController).toBe('object');
    });

    test('debe exportar módulo CommonJS si está disponible', function() {
      const module = require('../../../assets/js/dashboard/controllers/UnifiedDashboardController.js');
      expect(module).toBeDefined();
      expect(module.UnifiedDashboardController).toBeDefined();
    });
  });
});

