/**
 * Tests unitarios para dashboard-config.js
 * 
 * Verifica que DASHBOARD_CONFIG esté correctamente definido y expuesto globalmente.
 * 
 * @module tests/dashboard/config/dashboard-config
 */

// Limpiar el módulo antes de cada test
beforeEach(function() {
  // Asegurar que window existe
  if (typeof global.window === 'undefined') {
    global.window = {};
  }

  // Limpiar require cache PRIMERO para forzar recarga
  const configPath = require.resolve('../../../assets/js/dashboard/config/dashboard-config.js');
  if (require.cache[configPath]) {
    delete require.cache[configPath];
  }

  // Eliminar DASHBOARD_CONFIG de window después de limpiar el cache
  if (global.window.DASHBOARD_CONFIG) {
    delete global.window.DASHBOARD_CONFIG;
  }

  // Limpiar miIntegracionApiDashboard
  if (global.miIntegracionApiDashboard) {
    delete global.miIntegracionApiDashboard;
  }
});

describe('dashboard-config.js - DASHBOARD_CONFIG', function() {
  describe('Definición de DASHBOARD_CONFIG', function() {
    test('DASHBOARD_CONFIG debe estar definido', function() {
      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      // Verificar que el módulo se carga correctamente
      expect(config).toBeDefined();
      expect(config.DASHBOARD_CONFIG).toBeDefined();
    });

    test('DASHBOARD_CONFIG debe tener todas las propiedades principales', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      expect(config.DASHBOARD_CONFIG).toBeDefined();
      expect(config.DASHBOARD_CONFIG).toHaveProperty('timeouts');
      expect(config.DASHBOARD_CONFIG).toHaveProperty('limits');
      expect(config.DASHBOARD_CONFIG).toHaveProperty('selectors');
      expect(config.DASHBOARD_CONFIG).toHaveProperty('messages');
      expect(config.DASHBOARD_CONFIG).toHaveProperty('ui');
      expect(config.DASHBOARD_CONFIG).toHaveProperty('pagination');
    });
  });

  describe('Configuración de timeouts', function() {
    test('timeouts debe tener valores por defecto cuando miIntegracionApiDashboard no está disponible', function() {
      global.window = {};
      global.miIntegracionApiDashboard = undefined;

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      expect(config.DASHBOARD_CONFIG.timeouts).toBeDefined();
      expect(config.DASHBOARD_CONFIG.timeouts.default).toBe(2000);
      expect(config.DASHBOARD_CONFIG.timeouts.long).toBe(5000);
      expect(config.DASHBOARD_CONFIG.timeouts.short).toBe(1000);
      expect(config.DASHBOARD_CONFIG.timeouts.ajax).toBe(60000);
      expect(config.DASHBOARD_CONFIG.timeouts.connection).toBe(30000);
    });

    test('timeouts debe usar valores de miIntegracionApiDashboard cuando están disponibles', function() {
      global.window = {};
      
      // Limpiar cache PRIMERO
      const configPath = require.resolve('../../../assets/js/dashboard/config/dashboard-config.js');
      delete require.cache[configPath];
      
      // Establecer miIntegracionApiDashboard DESPUÉS de limpiar el cache
      // pero ANTES de hacer require
      // En Node.js, establecer en global hace que esté disponible globalmente
      global.miIntegracionApiDashboard = {
        timeoutConfig: {
          ui: {
            default: 3000,
            long: 6000,
            short: 500,
            ajax: 120000,
            connection: 60000
          }
        }
      };

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      expect(config.DASHBOARD_CONFIG.timeouts.default).toBe(3000);
      expect(config.DASHBOARD_CONFIG.timeouts.long).toBe(6000);
      expect(config.DASHBOARD_CONFIG.timeouts.short).toBe(500);
      expect(config.DASHBOARD_CONFIG.timeouts.ajax).toBe(120000);
      expect(config.DASHBOARD_CONFIG.timeouts.connection).toBe(60000);
    });

    test('timeouts debe usar valores por defecto si miIntegracionApiDashboard.timeoutConfig.ui no existe', function() {
      global.window = {};
      global.miIntegracionApiDashboard = {
        timeoutConfig: {}
      };

      // Limpiar cache para forzar recarga
      const configPath = require.resolve('../../../assets/js/dashboard/config/dashboard-config.js');
      delete require.cache[configPath];

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      expect(config.DASHBOARD_CONFIG.timeouts.default).toBe(2000);
      expect(config.DASHBOARD_CONFIG.timeouts.connection).toBe(30000);
    });
  });

  describe('Configuración de limits', function() {
    test('limits debe tener valores por defecto cuando miIntegracionApiDashboard no está disponible', function() {
      global.window = {};
      global.miIntegracionApiDashboard = undefined;

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      expect(config.DASHBOARD_CONFIG.limits).toBeDefined();
      expect(config.DASHBOARD_CONFIG.limits.historyLimit).toBe(10);
      expect(config.DASHBOARD_CONFIG.limits.progressMilestones).toEqual([25, 50, 75, 100]);
    });

    test('limits debe usar valores de miIntegracionApiDashboard cuando están disponibles', function() {
      global.window = {};
      
      // Limpiar cache PRIMERO
      const configPath = require.resolve('../../../assets/js/dashboard/config/dashboard-config.js');
      delete require.cache[configPath];
      
      // Establecer miIntegracionApiDashboard DESPUÉS de limpiar el cache
      // pero ANTES de hacer require
      global.miIntegracionApiDashboard = {
        limitsConfig: {
          ui: {
            historyLimit: 20,
            progressMilestones: [10, 30, 50, 70, 90, 100]
          }
        }
      };

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      expect(config.DASHBOARD_CONFIG.limits.historyLimit).toBe(20);
      expect(config.DASHBOARD_CONFIG.limits.progressMilestones).toEqual([10, 30, 50, 70, 90, 100]);
    });
  });

  describe('Selectores CSS', function() {
    test('selectors debe tener todos los selectores definidos', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      expect(config.DASHBOARD_CONFIG.selectors).toBeDefined();
      expect(config.DASHBOARD_CONFIG.selectors.syncButton).toBe('#mi-batch-sync-products');
      expect(config.DASHBOARD_CONFIG.selectors.feedback).toBe('#mi-sync-feedback');
      expect(config.DASHBOARD_CONFIG.selectors.progressInfo).toBe('#mi-progress-info');
      expect(config.DASHBOARD_CONFIG.selectors.cancelButton).toBe('#mi-cancel-sync');
      expect(config.DASHBOARD_CONFIG.selectors.statusContainer).toBe('#mi-sync-status-details');
      expect(config.DASHBOARD_CONFIG.selectors.batchSize).toBe('#mi-batch-size');
      expect(config.DASHBOARD_CONFIG.selectors.dashboardMessages).toBe('#mi-dashboard-messages');
      expect(config.DASHBOARD_CONFIG.selectors.retryButton).toBe('#mi-api-retry-sync');
    });

    test('todos los selectores deben ser strings válidos', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      Object.keys(config.DASHBOARD_CONFIG.selectors).forEach(function(key) {
        const selector = config.DASHBOARD_CONFIG.selectors[key];
        expect(typeof selector).toBe('string');
        expect(selector.length).toBeGreaterThan(0);
        expect(selector).toMatch(/^[#.]/); // Debe empezar con # o .
      });
    });
  });

  describe('Mensajes del sistema', function() {
    test('messages debe tener todas las categorías', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      expect(config.DASHBOARD_CONFIG.messages).toBeDefined();
      expect(config.DASHBOARD_CONFIG.messages).toHaveProperty('errors');
      expect(config.DASHBOARD_CONFIG.messages).toHaveProperty('progress');
      expect(config.DASHBOARD_CONFIG.messages).toHaveProperty('milestones');
      expect(config.DASHBOARD_CONFIG.messages).toHaveProperty('success');
      expect(config.DASHBOARD_CONFIG.messages).toHaveProperty('tips');
    });

    test('messages.errors debe tener todos los mensajes de error', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      const errors = config.DASHBOARD_CONFIG.messages.errors;
      expect(errors).toHaveProperty('jqueryMissing');
      expect(errors).toHaveProperty('configMissing');
      expect(errors).toHaveProperty('ajaxUrlMissing');
      expect(errors).toHaveProperty('connectionError');
      expect(errors).toHaveProperty('permissionError');
      expect(errors).toHaveProperty('serverError');
      expect(errors).toHaveProperty('timeoutError');
      expect(errors).toHaveProperty('unknownError');

      // Verificar que todos son strings
      Object.keys(errors).forEach(function(key) {
        expect(typeof errors[key]).toBe('string');
        expect(errors[key].length).toBeGreaterThan(0);
      });
    });

    test('messages.progress debe tener todos los mensajes de progreso', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      const progress = config.DASHBOARD_CONFIG.messages.progress;
      expect(progress).toHaveProperty('preparing');
      expect(progress).toHaveProperty('verifying');
      expect(progress).toHaveProperty('connecting');
      expect(progress).toHaveProperty('processing');
      expect(progress).toHaveProperty('complete');
    });

    test('messages.milestones debe tener todos los hitos', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      const milestones = config.DASHBOARD_CONFIG.messages.milestones;
      expect(milestones).toHaveProperty('start');
      expect(milestones).toHaveProperty('quarter');
      expect(milestones).toHaveProperty('half');
      expect(milestones).toHaveProperty('threeQuarters');
      expect(milestones).toHaveProperty('complete');
    });
  });

  describe('Configuración de UI', function() {
    test('ui debe tener todas las propiedades', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      expect(config.DASHBOARD_CONFIG.ui).toBeDefined();
      expect(config.DASHBOARD_CONFIG.ui).toHaveProperty('progress');
      expect(config.DASHBOARD_CONFIG.ui).toHaveProperty('animation');
      expect(config.DASHBOARD_CONFIG.ui).toHaveProperty('toastDuration');
    });

    test('ui.progress debe tener la configuración correcta', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      const progress = config.DASHBOARD_CONFIG.ui.progress;
      expect(progress.defaultWidth).toBe(2);
      expect(progress.animationDuration).toBe(300);
      expect(progress.colorScheme).toBeDefined();
      expect(progress.colorScheme.normal).toBe('#0073aa');
      expect(progress.colorScheme.success).toBe('#22c55e');
      expect(progress.colorScheme.warning).toBe('#f59e0b');
      expect(progress.colorScheme.error).toBe('#ef4444');
    });

    test('ui.animation debe tener la configuración correcta', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      const animation = config.DASHBOARD_CONFIG.ui.animation;
      expect(animation.duration).toBe(300);
      expect(animation.easing).toBe('swing');
    });

    test('ui.toastDuration debe tener todas las duraciones', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      const toastDuration = config.DASHBOARD_CONFIG.ui.toastDuration;
      expect(toastDuration.short).toBe(3000);
      expect(toastDuration.medium).toBe(5000);
      expect(toastDuration.long).toBe(8000);
      expect(toastDuration.extraLong).toBe(10000);
    });
  });

  describe('Configuración de paginación', function() {
    test('pagination debe tener todos los valores correctos', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      expect(config.DASHBOARD_CONFIG.pagination).toBeDefined();
      expect(config.DASHBOARD_CONFIG.pagination.defaultPerPage).toBe(10);
      expect(config.DASHBOARD_CONFIG.pagination.debounceDelay).toBe(500);
      expect(config.DASHBOARD_CONFIG.pagination.maxVisiblePages).toBe(5);
    });
  });

  describe('Exposición global', function() {
    test('DASHBOARD_CONFIG debe estar disponible en window', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      // Verificar que el módulo exporta DASHBOARD_CONFIG
      expect(config.DASHBOARD_CONFIG).toBeDefined();
      
      // Verificar que está en window (el código se ejecuta al hacer require)
      expect(global.window.DASHBOARD_CONFIG).toBeDefined();
      expect(typeof global.window.DASHBOARD_CONFIG).toBe('object');
    });

    test('DASHBOARD_CONFIG debe ser el mismo objeto que el exportado', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      // Verificar que está en window
      expect(global.window.DASHBOARD_CONFIG).toBeDefined();
      expect(global.window.DASHBOARD_CONFIG).toBe(config.DASHBOARD_CONFIG);
    });

    test('DASHBOARD_CONFIG debe ser enumerable', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/config/dashboard-config.js');

      // Verificar que está disponible
      expect(global.window.DASHBOARD_CONFIG).toBeDefined();
      
      const descriptor = Object.getOwnPropertyDescriptor(global.window, 'DASHBOARD_CONFIG');
      if (descriptor) {
        expect(descriptor.enumerable).toBe(true);
      }
    });
  });

  describe('Manejo de errores', function() {
    test('Debe manejar errores al acceder a miIntegracionApiDashboard', function() {
      global.window = {};
      // Simular un error al acceder a miIntegracionApiDashboard
      Object.defineProperty(global, 'miIntegracionApiDashboard', {
        get: function() {
          throw new Error('Error de acceso');
        },
        configurable: true
      });

      // Limpiar cache para forzar recarga
      const configPath = require.resolve('../../../assets/js/dashboard/config/dashboard-config.js');
      delete require.cache[configPath];

      // No debe lanzar error, debe usar valores por defecto
      expect(function() {
        // eslint-disable-next-line no-undef
        require('../../../assets/js/dashboard/config/dashboard-config.js');
      }).not.toThrow();

      // Limpiar
      delete global.miIntegracionApiDashboard;
    });

    test('Debe funcionar cuando window no está definido', function() {
      const originalWindow = global.window;
      delete global.window;

      // No debe lanzar error
      expect(function() {
        // eslint-disable-next-line no-undef
        require('../../../assets/js/dashboard/config/dashboard-config.js');
      }).not.toThrow();

      // Restaurar window
      global.window = originalWindow;
    });
  });

  describe('Compatibilidad con código existente', function() {
    test('DASHBOARD_CONFIG debe mantener la misma estructura que el original', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      // Verificar estructura completa
      expect(config.DASHBOARD_CONFIG).toHaveProperty('timeouts');
      expect(config.DASHBOARD_CONFIG).toHaveProperty('limits');
      expect(config.DASHBOARD_CONFIG).toHaveProperty('selectors');
      expect(config.DASHBOARD_CONFIG).toHaveProperty('messages');
      expect(config.DASHBOARD_CONFIG).toHaveProperty('ui');
      expect(config.DASHBOARD_CONFIG).toHaveProperty('pagination');

      // Verificar que está en window
      expect(global.window.DASHBOARD_CONFIG).toBeDefined();
      expect(global.window.DASHBOARD_CONFIG).toBe(config.DASHBOARD_CONFIG);
    });

    test('DASHBOARD_CONFIG.selectors debe coincidir con los valores originales', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const config = require('../../../assets/js/dashboard/config/dashboard-config.js');

      const selectors = config.DASHBOARD_CONFIG.selectors;
      expect(selectors.syncButton).toBe('#mi-batch-sync-products');
      expect(selectors.feedback).toBe('#mi-sync-feedback');
      expect(selectors.progressInfo).toBe('#mi-progress-info');
      expect(selectors.cancelButton).toBe('#mi-cancel-sync');
      expect(selectors.statusContainer).toBe('#mi-sync-status-details');
      expect(selectors.batchSize).toBe('#mi-batch-size');
      expect(selectors.dashboardMessages).toBe('#mi-dashboard-messages');
      expect(selectors.retryButton).toBe('#mi-api-retry-sync');
    });
  });
});

