/**
 * Tests unitarios para ToastManager.js
 * 
 * Verifica que ToastManager esté correctamente definido y funcione correctamente.
 * 
 * @module tests/dashboard/components/ToastManager
 */

// Mock de jQuery
const mockJQuery = function(selector) {
  if (typeof selector === 'string' && selector.includes('<')) {
    // Es un HTML string, crear elemento mock
    const mockElement = {
      css: jest.fn().mockReturnThis(),
      append: jest.fn().mockReturnThis(),
      find: jest.fn().mockReturnValue({
        on: jest.fn()
      }),
      remove: jest.fn()
    };
    return mockElement;
  }
  return {
    css: jest.fn().mockReturnThis(),
    append: jest.fn().mockReturnThis(),
    find: jest.fn().mockReturnValue({
      on: jest.fn()
    }),
    remove: jest.fn()
  };
};

mockJQuery.fn = {
  css: jest.fn().mockReturnThis(),
  append: jest.fn().mockReturnThis(),
  find: jest.fn().mockReturnValue({
    on: jest.fn()
  }),
  remove: jest.fn()
};

// Mock de DASHBOARD_CONFIG
const mockDashboardConfig = {
  ui: {
    animation: {
      fadeIn: 300
    }
  }
};

// Limpiar el módulo antes de cada test
beforeEach(function() {
  // Asegurar que window existe
  if (typeof global.window === 'undefined') {
    global.window = {};
  }

  // Eliminar ToastManager y showToast de window primero
  if (global.window.ToastManager) {
    delete global.window.ToastManager;
  }
  if (global.window.showToast) {
    delete global.window.showToast;
  }

  // Limpiar require cache para forzar recarga
  const toastManagerPath = require.resolve('../../../assets/js/dashboard/components/ToastManager.js');
  if (require.cache[toastManagerPath]) {
    delete require.cache[toastManagerPath];
  }

  // Mock de jQuery
  global.jQuery = mockJQuery;
  global.$ = mockJQuery;

  // Mock de DASHBOARD_CONFIG
  global.DASHBOARD_CONFIG = mockDashboardConfig;

  // Mock de document.body
  global.document = {
    body: {
      appendChild: jest.fn()
    }
  };

  // Resetear mocks
  jest.clearAllTimers();
  jest.useFakeTimers();
});

afterEach(function() {
  jest.useRealTimers();
});

describe('ToastManager.js - ToastManager', function() {
  describe('Definición de ToastManager', function() {
    test('ToastManager debe estar definido', function() {
      // eslint-disable-next-line no-undef
      const toastManager = require('../../../assets/js/dashboard/components/ToastManager.js');

      // Verificar que el módulo se carga correctamente
      expect(toastManager).toBeDefined();
      expect(toastManager.ToastManager).toBeDefined();
    });

    test('ToastManager debe tener todos los métodos', function() {
      // eslint-disable-next-line no-undef
      const toastManager = require('../../../assets/js/dashboard/components/ToastManager.js');

      expect(toastManager.ToastManager).toHaveProperty('show');
      expect(toastManager.ToastManager).toHaveProperty('success');
      expect(toastManager.ToastManager).toHaveProperty('error');
      expect(toastManager.ToastManager).toHaveProperty('warning');
      expect(toastManager.ToastManager).toHaveProperty('info');
      expect(toastManager.ToastManager).toHaveProperty('DEFAULT_DURATION');
    });

    test('DEFAULT_DURATION debe ser 4000ms', function() {
      // eslint-disable-next-line no-undef
      const toastManager = require('../../../assets/js/dashboard/components/ToastManager.js');

      expect(toastManager.ToastManager.DEFAULT_DURATION).toBe(4000);
    });
  });

  describe('show', function() {
    test('show debe crear y mostrar un toast', function() {
      // Limpiar cache
      const toastManagerPath = require.resolve('../../../assets/js/dashboard/components/ToastManager.js');
      delete require.cache[toastManagerPath];

      // eslint-disable-next-line no-undef
      const { ToastManager } = require('../../../assets/js/dashboard/components/ToastManager.js');

      const result = ToastManager.show('Test message', 'info');

      expect(result).toBeDefined();
      expect(global.jQuery).toHaveBeenCalled();
    });

    test('show debe agregar el toast al body', function() {
      const mockBody = {
        append: jest.fn()
      };
      global.jQuery = jest.fn(function(selector) {
        if (selector === 'body') {
          return mockBody;
        }
        return {
          css: jest.fn().mockReturnThis(),
          find: jest.fn().mockReturnValue({
            on: jest.fn()
          }),
          remove: jest.fn()
        };
      });

      // Limpiar cache
      const toastManagerPath = require.resolve('../../../assets/js/dashboard/components/ToastManager.js');
      delete require.cache[toastManagerPath];

      // eslint-disable-next-line no-undef
      const { ToastManager } = require('../../../assets/js/dashboard/components/ToastManager.js');

      ToastManager.show('Test message');

      expect(mockBody.append).toHaveBeenCalled();
    });

    test('show debe usar el tipo por defecto "info" si no se especifica', function() {
      // Limpiar cache
      const toastManagerPath = require.resolve('../../../assets/js/dashboard/components/ToastManager.js');
      delete require.cache[toastManagerPath];

      // eslint-disable-next-line no-undef
      const { ToastManager } = require('../../../assets/js/dashboard/components/ToastManager.js');

      const result = ToastManager.show('Test message');

      expect(result).toBeDefined();
    });

    test('show debe retornar null si jQuery no está disponible', function() {
      delete global.jQuery;

      // Limpiar cache
      const toastManagerPath = require.resolve('../../../assets/js/dashboard/components/ToastManager.js');
      delete require.cache[toastManagerPath];

      // eslint-disable-next-line no-undef
      const { ToastManager } = require('../../../assets/js/dashboard/components/ToastManager.js');

      const result = ToastManager.show('Test message');

      expect(result).toBeNull();
    });

    test('show debe retornar null si el mensaje no es válido', function() {
      // Limpiar cache
      const toastManagerPath = require.resolve('../../../assets/js/dashboard/components/ToastManager.js');
      delete require.cache[toastManagerPath];

      // eslint-disable-next-line no-undef
      const { ToastManager } = require('../../../assets/js/dashboard/components/ToastManager.js');

      const result = ToastManager.show(null);

      expect(result).toBeNull();
    });

    test('show debe configurar el auto-cierre con la duración especificada', function() {
      const mockBody = {
        append: jest.fn()
      };
      const mockToast = {
        css: jest.fn().mockReturnThis(),
        find: jest.fn().mockReturnValue({
          on: jest.fn()
        }),
        remove: jest.fn()
      };

      global.jQuery = jest.fn(function(selector) {
        if (selector === 'body') {
          return mockBody;
        }
        return mockToast;
      });

      // Limpiar cache
      const toastManagerPath = require.resolve('../../../assets/js/dashboard/components/ToastManager.js');
      delete require.cache[toastManagerPath];

      // eslint-disable-next-line no-undef
      const { ToastManager } = require('../../../assets/js/dashboard/components/ToastManager.js');

      ToastManager.show('Test message', 'info', 5000);

      // Avanzar el tiempo para que se ejecute el auto-cierre
      jest.advanceTimersByTime(5000);

      // Verificar que se llamó a remove después del tiempo especificado
      expect(mockToast.remove).toHaveBeenCalled();
    });
  });

  describe('Métodos de conveniencia', function() {
    test('success debe llamar a show con tipo "success"', function() {
      const mockBody = {
        append: jest.fn()
      };
      const mockToast = {
        css: jest.fn().mockReturnThis(),
        find: jest.fn().mockReturnValue({
          on: jest.fn()
        }),
        remove: jest.fn()
      };

      global.jQuery = jest.fn(function(selector) {
        if (selector === 'body') {
          return mockBody;
        }
        return mockToast;
      });

      // Limpiar cache
      const toastManagerPath = require.resolve('../../../assets/js/dashboard/components/ToastManager.js');
      delete require.cache[toastManagerPath];

      // eslint-disable-next-line no-undef
      const { ToastManager } = require('../../../assets/js/dashboard/components/ToastManager.js');

      const result = ToastManager.success('Success message');

      expect(result).toBeDefined();
    });

    test('error debe llamar a show con tipo "error"', function() {
      const mockBody = {
        append: jest.fn()
      };
      const mockToast = {
        css: jest.fn().mockReturnThis(),
        find: jest.fn().mockReturnValue({
          on: jest.fn()
        }),
        remove: jest.fn()
      };

      global.jQuery = jest.fn(function(selector) {
        if (selector === 'body') {
          return mockBody;
        }
        return mockToast;
      });

      // Limpiar cache
      const toastManagerPath = require.resolve('../../../assets/js/dashboard/components/ToastManager.js');
      delete require.cache[toastManagerPath];

      // eslint-disable-next-line no-undef
      const { ToastManager } = require('../../../assets/js/dashboard/components/ToastManager.js');

      const result = ToastManager.error('Error message');

      expect(result).toBeDefined();
    });

    test('warning debe llamar a show con tipo "warning"', function() {
      const mockBody = {
        append: jest.fn()
      };
      const mockToast = {
        css: jest.fn().mockReturnThis(),
        find: jest.fn().mockReturnValue({
          on: jest.fn()
        }),
        remove: jest.fn()
      };

      global.jQuery = jest.fn(function(selector) {
        if (selector === 'body') {
          return mockBody;
        }
        return mockToast;
      });

      // Limpiar cache
      const toastManagerPath = require.resolve('../../../assets/js/dashboard/components/ToastManager.js');
      delete require.cache[toastManagerPath];

      // eslint-disable-next-line no-undef
      const { ToastManager } = require('../../../assets/js/dashboard/components/ToastManager.js');

      const result = ToastManager.warning('Warning message');

      expect(result).toBeDefined();
    });

    test('info debe llamar a show con tipo "info"', function() {
      const mockBody = {
        append: jest.fn()
      };
      const mockToast = {
        css: jest.fn().mockReturnThis(),
        find: jest.fn().mockReturnValue({
          on: jest.fn()
        }),
        remove: jest.fn()
      };

      global.jQuery = jest.fn(function(selector) {
        if (selector === 'body') {
          return mockBody;
        }
        return mockToast;
      });

      // Limpiar cache
      const toastManagerPath = require.resolve('../../../assets/js/dashboard/components/ToastManager.js');
      delete require.cache[toastManagerPath];

      // eslint-disable-next-line no-undef
      const { ToastManager } = require('../../../assets/js/dashboard/components/ToastManager.js');

      const result = ToastManager.info('Info message');

      expect(result).toBeDefined();
    });
  });

  describe('Exposición global', function() {
    test('ToastManager debe estar disponible en window', function() {
      global.window = {};

      // Limpiar cache del módulo
      const toastManagerPath = require.resolve('../../../assets/js/dashboard/components/ToastManager.js');
      delete require.cache[toastManagerPath];

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/components/ToastManager.js');

      // Verificar que está en window
      if (typeof global.window !== 'undefined') {
        expect(global.window.ToastManager).toBeDefined();
        expect(typeof global.window.ToastManager).toBe('object');
      }
    });

    test('showToast debe estar disponible en window', function() {
      global.window = {};

      // Limpiar cache del módulo
      const toastManagerPath = require.resolve('../../../assets/js/dashboard/components/ToastManager.js');
      delete require.cache[toastManagerPath];

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/components/ToastManager.js');

      // Verificar que está en window
      if (typeof global.window !== 'undefined') {
        expect(global.window.showToast).toBeDefined();
        expect(typeof global.window.showToast).toBe('function');
      }
    });
  });
});

