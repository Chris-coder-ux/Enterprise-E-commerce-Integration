/**
 * Tests unitarios para ResponsiveLayout.js
 *
 * @module tests/dashboard/ui/ResponsiveLayout.test
 * @since 1.0.0
 */

// Configurar timers falsos para evitar problemas con setTimeout
jest.useFakeTimers();

// Función helper para crear elementos mock
const createMockElement = function() {
  return {
    css: jest.fn().mockReturnThis(),
    addClass: jest.fn().mockReturnThis(),
    removeClass: jest.fn().mockReturnThis(),
    width: jest.fn().mockReturnValue(500),
    length: 1
  };
};

// Mock de jQuery
const createMockJQuery = function() {
  const mockElements = {};

  const mockJQuery = jest.fn(function(selector) {
    // Si es un selector de clase, crear o devolver el mock existente
    if (typeof selector === 'string' && selector.startsWith('.')) {
      if (!mockElements[selector]) {
        mockElements[selector] = createMockElement();
      }
      return mockElements[selector];
    }
    if (selector === document) {
      if (!mockElements.document) {
        mockElements.document = {
          ready: jest.fn(function(callback) {
            if (callback) callback();
          })
        };
      }
      return mockElements.document;
    }
    if (selector === window) {
      if (!mockElements.window) {
        mockElements.window = {
          resize: jest.fn(function(callback) {
            if (callback) callback();
          }),
          on: jest.fn(function(event, callback) {
            if (event === 'orientationchange' && callback) {
              setTimeout(callback, 0);
            }
          })
        };
      }
      return mockElements.window;
    }
    return createMockElement();
  });

  mockJQuery.fn = {};
  mockJQuery.ready = jest.fn(function(callback) {
    if (callback) callback();
  });

  return { mockJQuery, mockElements };
};

// Limpiar el módulo antes de cada test
beforeEach(function() {
  // Asegurar que window existe
  if (typeof global.window === 'undefined') {
    global.window = {
      innerWidth: 1024
    };
  }

  // Eliminar ResponsiveLayout de window primero
  if (global.window.ResponsiveLayout) {
    delete global.window.ResponsiveLayout;
  }

  // Limpiar require cache para forzar recarga
  const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
  if (require.cache[responsiveLayoutPath]) {
    delete require.cache[responsiveLayoutPath];
  }

  // Limpiar jQuery y $ si existen
  if (global.jQuery) {
    delete global.jQuery;
  }
  if (global.$) {
    delete global.$;
  }
});

describe('ResponsiveLayout.js - ResponsiveLayout', function() {
  describe('Definición de ResponsiveLayout', function() {
    test('ResponsiveLayout debe estar definido', function() {
      const { mockJQuery } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.window = {
        innerWidth: 1024
      };
      global.document = {};

      // eslint-disable-next-line no-undef
      const responsiveLayout = require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');

      // Verificar que el módulo se carga correctamente
      expect(responsiveLayout).toBeDefined();
      expect(responsiveLayout.ResponsiveLayout).toBeDefined();
    });

    test('ResponsiveLayout debe tener todos los métodos', function() {
      const { mockJQuery } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.window = {
        innerWidth: 1024
      };
      global.document = {};

      // Limpiar cache
      const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      delete require.cache[responsiveLayoutPath];

      // eslint-disable-next-line no-undef
      const responsiveLayout = require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');

      expect(responsiveLayout.ResponsiveLayout).toHaveProperty('init');
      expect(responsiveLayout.ResponsiveLayout).toHaveProperty('adjustLayout');
      expect(responsiveLayout.ResponsiveLayout).toHaveProperty('initResponsiveMenu');
      expect(responsiveLayout.ResponsiveLayout).toHaveProperty('timeout');
    });
  });

  describe('adjustLayout', function() {
    test('debe ajustar el sidebar para móviles (width < 769)', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.window = {
        innerWidth: 600
      };
      global.document = {};

      // Limpiar cache
      const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      delete require.cache[responsiveLayoutPath];

      // eslint-disable-next-line no-undef
      const { ResponsiveLayout } = require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');

      ResponsiveLayout.adjustLayout();

      // Avanzar timers para ejecutar el callback (100ms delay en el código)
      jest.advanceTimersByTime(150);

      // Verificar que se ajustó el sidebar para móviles
      const $sidebar = mockElements['.mi-integracion-api-sidebar'];
      expect($sidebar.css).toHaveBeenCalledWith({
        'position': 'relative',
        'top': 'auto',
        'height': 'auto',
        'max-height': 'none'
      });
    });

    test('debe ajustar el sidebar para desktop (width >= 769)', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.window = {
        innerWidth: 1024
      };
      global.document = {};

      // Limpiar cache
      const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      delete require.cache[responsiveLayoutPath];

      // eslint-disable-next-line no-undef
      const { ResponsiveLayout } = require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');

      ResponsiveLayout.adjustLayout();

      // Avanzar timers para ejecutar el callback (100ms delay en el código)
      jest.advanceTimersByTime(150);

      // Verificar que se ajustó el sidebar para desktop
      const $sidebar = mockElements['.mi-integracion-api-sidebar'];
      expect($sidebar.css).toHaveBeenCalledWith({
        'position': 'sticky',
        'top': '20px',
        'height': 'fit-content',
        'max-height': 'none'
      });
    });

    test('debe ajustar el grid de estadísticas según el ancho', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.window = {
        innerWidth: 1024
      };
      global.document = {};

      // Limpiar cache
      const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      delete require.cache[responsiveLayoutPath];

      // eslint-disable-next-line no-undef
      const { ResponsiveLayout } = require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      const $statsGrid = mockElements['.mi-integracion-api-stats-grid'];
      $statsGrid.width.mockReturnValue(300); // < 400

      ResponsiveLayout.adjustLayout();

      // Avanzar timers para ejecutar el callback (100ms delay en el código)
      jest.advanceTimersByTime(150);

      expect($statsGrid.addClass).toHaveBeenCalledWith('single-column');
    });

    test('debe agregar clase two-columns para ancho entre 400 y 600', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.window = {
        innerWidth: 1024
      };
      global.document = {};

      // Limpiar cache
      const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      delete require.cache[responsiveLayoutPath];

      // eslint-disable-next-line no-undef
      const { ResponsiveLayout } = require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      const $statsGrid = mockElements['.mi-integracion-api-stats-grid'];
      $statsGrid.width.mockReturnValue(500); // Entre 400 y 600

      ResponsiveLayout.adjustLayout();

      // Avanzar timers para ejecutar el callback (100ms delay en el código)
      jest.advanceTimersByTime(150);

      expect($statsGrid.addClass).toHaveBeenCalledWith('two-columns');
    });

    test('debe agregar clase three-columns para ancho entre 600 y 900', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.window = {
        innerWidth: 1024
      };
      global.document = {};

      // Limpiar cache
      const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      delete require.cache[responsiveLayoutPath];

      // eslint-disable-next-line no-undef
      const { ResponsiveLayout } = require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      const $statsGrid = mockElements['.mi-integracion-api-stats-grid'];
      $statsGrid.width.mockReturnValue(750); // Entre 600 y 900

      ResponsiveLayout.adjustLayout();

      // Avanzar timers para ejecutar el callback (100ms delay en el código)
      jest.advanceTimersByTime(150);

      expect($statsGrid.addClass).toHaveBeenCalledWith('three-columns');
    });

    test('debe remover clases para ancho >= 900', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.window = {
        innerWidth: 1024
      };
      global.document = {};

      // Limpiar cache
      const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      delete require.cache[responsiveLayoutPath];

      // eslint-disable-next-line no-undef
      const { ResponsiveLayout } = require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      const $statsGrid = mockElements['.mi-integracion-api-stats-grid'];
      $statsGrid.width.mockReturnValue(1000); // >= 900

      ResponsiveLayout.adjustLayout();

      // Avanzar timers para ejecutar el callback (100ms delay en el código)
      jest.advanceTimersByTime(150);

      expect($statsGrid.removeClass).toHaveBeenCalledWith('single-column two-columns three-columns');
    });
  });

  describe('initResponsiveMenu', function() {
    test('debe agregar clase scrollable-horizontal para width < 577', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.window = {
        innerWidth: 500
      };
      global.document = {};

      // Limpiar cache
      const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      delete require.cache[responsiveLayoutPath];

      // eslint-disable-next-line no-undef
      const { ResponsiveLayout } = require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');

      ResponsiveLayout.initResponsiveMenu();

      const $menu = mockElements['.mi-integracion-api-nav-menu ul'];
      expect($menu.addClass).toHaveBeenCalledWith('scrollable-horizontal');
    });

    test('debe remover clase scrollable-horizontal para width >= 577', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.window = {
        innerWidth: 800
      };
      global.document = {};

      // Limpiar cache
      const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      delete require.cache[responsiveLayoutPath];

      // eslint-disable-next-line no-undef
      const { ResponsiveLayout } = require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');

      ResponsiveLayout.initResponsiveMenu();

      const $menu = mockElements['.mi-integracion-api-nav-menu ul'];
      expect($menu.removeClass).toHaveBeenCalledWith('scrollable-horizontal');
    });
  });

  describe('init', function() {
    test('debe inicializar el sistema responsive', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      const mockReady = jest.fn(function(callback) {
        if (callback) callback();
      });
      mockJQuery.ready = mockReady;
      mockJQuery.mockImplementation(function(selector) {
        if (selector === document) {
          if (!mockElements.document) {
            mockElements.document = {
              ready: mockReady
            };
          }
          return mockElements.document;
        }
        if (selector === window) {
          if (!mockElements.window) {
            mockElements.window = {
              resize: jest.fn(function(callback) {
                if (callback) callback();
              }),
              on: jest.fn(function(event, callback) {
                if (event === 'orientationchange' && callback) {
                  setTimeout(callback, 0);
                }
              })
            };
          }
          return mockElements.window;
        }
        return createMockElement();
      });

      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.window = {
        innerWidth: 1024
      };
      global.document = {};

      // Limpiar cache
      const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      delete require.cache[responsiveLayoutPath];

      // eslint-disable-next-line no-undef
      const { ResponsiveLayout } = require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');

      ResponsiveLayout.init();

      // Avanzar timers para ejecutar callbacks
      jest.advanceTimersByTime(10);

      // Verificar que se llamó jQuery.ready
      expect(mockReady).toHaveBeenCalled();
    });

    test('debe registrar event listener para resize', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      const mockResizeHandler = jest.fn(function(callback) {
        if (callback) callback();
      });
      mockJQuery.mockImplementation(function(selector) {
        if (selector === document) {
          if (!mockElements.document) {
            mockElements.document = {
              ready: jest.fn(function(callback) { if (callback) callback(); })
            };
          }
          return mockElements.document;
        }
        if (selector === window) {
          if (!mockElements.window) {
            mockElements.window = {
              resize: mockResizeHandler,
              on: jest.fn()
            };
          }
          return mockElements.window;
        }
        return createMockElement();
      });

      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.window = {
        innerWidth: 1024
      };
      global.document = {};

      // Limpiar cache
      const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      delete require.cache[responsiveLayoutPath];

      // eslint-disable-next-line no-undef
      const { ResponsiveLayout } = require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');

      ResponsiveLayout.init();

      // Avanzar timers para ejecutar callbacks
      jest.advanceTimersByTime(10);

      // Verificar que se registró el handler de resize
      expect(mockResizeHandler).toHaveBeenCalled();
    });

    test('debe registrar event listener para orientationchange', function() {
      const { mockJQuery, mockElements } = createMockJQuery();
      const mockOrientationHandler = jest.fn();
      mockJQuery.mockImplementation(function(selector) {
        if (selector === document) {
          if (!mockElements.document) {
            mockElements.document = {
              ready: jest.fn(function(callback) { if (callback) callback(); })
            };
          }
          return mockElements.document;
        }
        if (selector === window) {
          if (!mockElements.window) {
            mockElements.window = {
              resize: jest.fn(function(callback) {
                if (callback) callback();
              }),
              on: mockOrientationHandler
            };
          }
          return mockElements.window;
        }
        return createMockElement();
      });

      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.window = {
        innerWidth: 1024
      };
      global.document = {};

      // Limpiar cache
      const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      delete require.cache[responsiveLayoutPath];

      // eslint-disable-next-line no-undef
      const { ResponsiveLayout } = require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');

      ResponsiveLayout.init();

      // Avanzar timers para ejecutar callbacks
      jest.advanceTimersByTime(10);

      // Verificar que se registró el handler de orientationchange
      expect(mockOrientationHandler).toHaveBeenCalledWith('orientationchange', expect.any(Function));
    });
  });

  describe('Exposición global', function() {
    test('debe exponer ResponsiveLayout en window si está disponible', function() {
      // Limpiar cache
      jest.resetModules();
      const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      delete require.cache[responsiveLayoutPath];

      // Configurar window
      global.window = {
        innerWidth: 1024
      };

      // Mock jQuery
      const { mockJQuery } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.document = {};

      // Cargar módulo
      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');

      // Verificar que se expuso en window
      expect(global.window.ResponsiveLayout).toBeDefined();
      expect(typeof global.window.ResponsiveLayout).toBe('object');
    });

    test('debe exportar módulo CommonJS si está disponible', function() {
      const { mockJQuery } = createMockJQuery();
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.window = {
        innerWidth: 1024
      };
      global.document = {};

      // Limpiar cache
      const responsiveLayoutPath = require.resolve('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      delete require.cache[responsiveLayoutPath];

      // eslint-disable-next-line no-undef
      const module = require('../../../assets/js/dashboard/ui/ResponsiveLayout.js');
      expect(module).toBeDefined();
      expect(module.ResponsiveLayout).toBeDefined();
    });
  });
});
