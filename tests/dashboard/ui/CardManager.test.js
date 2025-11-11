/**
 * Tests unitarios para CardManager.js
 *
 * @module tests/dashboard/ui/CardManager.test
 * @since 1.0.0
 */

// Configurar entorno de pruebas
jest.useRealTimers();

describe('CardManager', function() {
  let CardManager;
  let mockJQuery;
  let mockAjax;
  let mockToastManager;
  let mockSelectors;
  let mockMiIntegracionApiDashboard;

  beforeEach(function() {
    // Limpiar require cache para asegurar módulos frescos
    delete require.cache[require.resolve('../../../assets/js/dashboard/ui/CardManager.js')];

    // Mock de jQuery
    mockAjax = jest.fn();
    mockJQuery = jest.fn(function(selector) {
      if (selector === undefined) {
        return {
          ajax: mockAjax
        };
      }
      return {
        attr: jest.fn().mockReturnValue('mi-integracion-api-stat-card memory'),
        find: jest.fn().mockReturnValue({
          text: jest.fn().mockReturnValue('50%'),
          html: jest.fn().mockReturnThis()
        }),
        addClass: jest.fn().mockReturnThis(),
        removeClass: jest.fn().mockReturnThis(),
        data: jest.fn().mockReturnValue('50%'),
        ajax: mockAjax
      };
    });
    mockJQuery.ajax = mockAjax;
    global.jQuery = mockJQuery;
    global.$ = mockJQuery;

    // Mock de SELECTORS
    mockSelectors = {
      STAT_VALUE: '.stat-value',
      STAT_DESC: '.stat-desc'
    };
    global.SELECTORS = mockSelectors;

    // Mock de miIntegracionApiDashboard
    mockMiIntegracionApiDashboard = {
      ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
      nonce: 'test-nonce-123',
      memory_nonce: 'memory-nonce-123',
      dashboard_nonce: 'dashboard-nonce-123'
    };
    global.miIntegracionApiDashboard = mockMiIntegracionApiDashboard;

    // Mock de ToastManager
    mockToastManager = {
      show: jest.fn()
    };
    global.ToastManager = mockToastManager;

    // Mock de showToast (fallback)
    global.showToast = jest.fn();

    // Cargar módulo
    CardManager = require('../../../assets/js/dashboard/ui/CardManager.js').CardManager;
  });

  afterEach(function() {
    jest.clearAllMocks();
    delete global.jQuery;
    delete global.$;
    delete global.SELECTORS;
    delete global.miIntegracionApiDashboard;
    delete global.ToastManager;
    delete global.showToast;
  });

  describe('Módulo', function() {
    test('debe exportar CardManager', function() {
      expect(CardManager).toBeDefined();
      expect(typeof CardManager).toBe('object');
    });

    test('debe tener los métodos esperados', function() {
      expect(typeof CardManager.updateCardData).toBe('function');
      expect(typeof CardManager.updateSpecificCard).toBe('function');
    });
  });

  describe('updateCardData', function() {
    test('debe hacer una petición AJAX para actualizar la tarjeta', function() {
      const $card = mockJQuery('.card');
      const $value = {
        text: jest.fn().mockReturnValue('50%'),
        html: jest.fn().mockReturnThis()
      };
      const $desc = {
        text: jest.fn().mockReturnValue('Estado de memoria')
      };

      $card.find.mockImplementation(function(selector) {
        if (selector === mockSelectors.STAT_VALUE) {
          return $value;
        }
        if (selector === mockSelectors.STAT_DESC) {
          return $desc;
        }
        return {};
      });

      $card.attr.mockReturnValue('mi-integracion-api-stat-card memory');
      $card.data.mockReturnValue('50%');

      mockAjax.mockImplementation(function(options) {
        // Simular respuesta exitosa
        if (options.success) {
          options.success({
            success: true,
            data: {
              usage_percentage: '75',
              status: 'healthy',
              status_message: 'Memoria saludable'
            }
          });
        }
      });

      CardManager.updateCardData($card);

      expect(mockAjax).toHaveBeenCalled();
      expect($card.addClass).toHaveBeenCalledWith('loading');
      expect($value.html).toHaveBeenCalled();
      expect($desc.text).toHaveBeenCalledWith('Actualizando...');
    });

    test('debe usar la acción correcta para tarjeta de memoria', function() {
      const $value = {
        text: jest.fn().mockReturnValue('50%'),
        html: jest.fn().mockReturnThis()
      };
      const $desc = {
        text: jest.fn().mockReturnValue('Estado de memoria')
      };

      const $card = {
        attr: jest.fn().mockReturnValue('mi-integracion-api-stat-card memory'),
        find: jest.fn().mockImplementation(function(selector) {
          if (selector === mockSelectors.STAT_VALUE) {
            return $value;
          }
          if (selector === mockSelectors.STAT_DESC) {
            return $desc;
          }
          return {};
        }),
        addClass: jest.fn().mockReturnThis(),
        removeClass: jest.fn().mockReturnThis(),
        data: jest.fn().mockReturnValue('50%')
      };

      mockJQuery.mockReturnValue($card);

      mockAjax.mockImplementation(function(options) {
        expect(options.data.action).toBe('mia_refresh_memory_stats');
        expect(options.data.nonce).toBe('memory-nonce-123');
        if (options.success) {
          options.success({
            success: true,
            data: {}
          });
        }
      });

      CardManager.updateCardData($card);
    });

    test('debe usar la acción correcta para otras tarjetas', function() {
      const $value = {
        text: jest.fn().mockReturnValue('50%'),
        html: jest.fn().mockReturnThis()
      };
      const $desc = {
        text: jest.fn().mockReturnValue('Estado')
      };

      const $card = {
        attr: jest.fn().mockReturnValue('mi-integracion-api-stat-card retries'),
        find: jest.fn().mockImplementation(function(selector) {
          if (selector === mockSelectors.STAT_VALUE) {
            return $value;
          }
          if (selector === mockSelectors.STAT_DESC) {
            return $desc;
          }
          return {};
        }),
        addClass: jest.fn().mockReturnThis(),
        removeClass: jest.fn().mockReturnThis(),
        data: jest.fn().mockReturnValue('50%')
      };

      mockJQuery.mockReturnValue($card);

      mockAjax.mockImplementation(function(options) {
        expect(options.data.action).toBe('miaRefresh_systemStatus');
        expect(options.data.nonce).toBe('test-nonce-123');
        if (options.success) {
          options.success({
            success: true,
            data: {}
          });
        }
      });

      CardManager.updateCardData($card);
    });

    test('debe manejar errores de AJAX correctamente', function() {
      const $value = {
        text: jest.fn().mockReturnValue('50%'),
        html: jest.fn().mockReturnThis()
      };
      const $desc = {
        text: jest.fn().mockReturnValue('Estado')
      };

      const $card = {
        attr: jest.fn().mockReturnValue('mi-integracion-api-stat-card sync'),
        find: jest.fn().mockImplementation(function(selector) {
          if (selector === mockSelectors.STAT_VALUE) {
            return $value;
          }
          if (selector === mockSelectors.STAT_DESC) {
            return $desc;
          }
          return {};
        }),
        addClass: jest.fn().mockReturnThis(),
        removeClass: jest.fn().mockReturnThis(),
        data: jest.fn().mockReturnValue('50%')
      };

      mockJQuery.mockReturnValue($card);

      mockAjax.mockImplementation(function(options) {
        if (options.error) {
          options.error({
            responseJSON: {
              data: {
                message: 'Error de servidor'
              }
            }
          }, 'error', 'Error de conexión');
        }
      });

      CardManager.updateCardData($card);

      expect($card.removeClass).toHaveBeenCalledWith('loading');
      expect(mockToastManager.show).toHaveBeenCalledWith('Error de servidor', 'error', 3000);
    });

    test('debe restaurar valores originales en caso de error', function() {
      const $value = {
        text: jest.fn().mockReturnValue('50%'),
        html: jest.fn().mockReturnThis()
      };
      const $desc = {
        text: jest.fn().mockReturnValue('Estado original')
      };

      const $card = {
        attr: jest.fn().mockReturnValue('mi-integracion-api-stat-card sync'),
        find: jest.fn().mockImplementation(function(selector) {
          if (selector === mockSelectors.STAT_VALUE) {
            return $value;
          }
          if (selector === mockSelectors.STAT_DESC) {
            return $desc;
          }
          return {};
        }),
        addClass: jest.fn().mockReturnThis(),
        removeClass: jest.fn().mockReturnThis(),
        data: jest.fn().mockImplementation(function(key) {
          if (key === 'original-value') {
            return '50%';
          }
          if (key === 'original-desc') {
            return 'Estado original';
          }
          return null;
        })
      };

      mockJQuery.mockReturnValue($card);

      mockAjax.mockImplementation(function(options) {
        if (options.error) {
          options.error({}, 'error', 'Error de conexión');
        }
      });

      CardManager.updateCardData($card);

      expect($value.text).toHaveBeenCalledWith('50%');
      expect($desc.text).toHaveBeenCalledWith('Estado original');
    });
  });

  describe('updateSpecificCard', function() {
    test('debe actualizar tarjeta de memoria correctamente', function() {
      const $value = {
        text: jest.fn().mockReturnThis()
      };
      const $desc = {
        text: jest.fn().mockReturnThis()
      };

      const $card = {
        find: jest.fn().mockImplementation(function(selector) {
          if (selector === mockSelectors.STAT_VALUE) {
            return $value;
          }
          if (selector === mockSelectors.STAT_DESC) {
            return $desc;
          }
          return {};
        }),
        data: jest.fn().mockReturnValue('50%'),
        removeClass: jest.fn().mockReturnThis(),
        addClass: jest.fn().mockReturnThis()
      };

      mockJQuery.mockReturnValue($card);

      const data = {
        usage_percentage: '75',
        status: 'healthy',
        status_message: 'Memoria saludable'
      };

      CardManager.updateSpecificCard($card, 'memory', data);

      expect($value.text).toHaveBeenCalledWith('75%');
      expect($desc.text).toHaveBeenCalledWith('Memoria saludable');
      expect($card.removeClass).toHaveBeenCalledWith('healthy warning critical');
      expect($card.addClass).toHaveBeenCalledWith('healthy');
    });

    test('debe validar estados de memoria inválidos', function() {
      const $value = {
        text: jest.fn().mockReturnThis()
      };
      const $desc = {
        text: jest.fn().mockReturnThis()
      };

      const $card = {
        find: jest.fn().mockImplementation(function(selector) {
          if (selector === mockSelectors.STAT_VALUE) {
            return $value;
          }
          if (selector === mockSelectors.STAT_DESC) {
            return $desc;
          }
          return {};
        }),
        data: jest.fn().mockReturnValue('50%'),
        removeClass: jest.fn().mockReturnThis(),
        addClass: jest.fn().mockReturnThis()
      };

      mockJQuery.mockReturnValue($card);

      const data = {
        usage_percentage: '75',
        status: 'invalid-status',
        status_message: 'Estado inválido'
      };

      // eslint-disable-next-line no-console
      const consoleWarnSpy = jest.spyOn(console, 'warn').mockImplementation();

      CardManager.updateSpecificCard($card, 'memory', data);

      expect($card.addClass).toHaveBeenCalledWith('unknown');
      expect(consoleWarnSpy).toHaveBeenCalled();

      consoleWarnSpy.mockRestore();
    });

    test('debe actualizar tarjeta de reintentos correctamente', function() {
      const $value = {
        text: jest.fn().mockReturnThis()
      };
      const $desc = {
        text: jest.fn().mockReturnThis()
      };

      const $card = {
        find: jest.fn().mockImplementation(function(selector) {
          if (selector === mockSelectors.STAT_VALUE) {
            return $value;
          }
          if (selector === mockSelectors.STAT_DESC) {
            return $desc;
          }
          return {};
        }),
        data: jest.fn().mockReturnValue('50%')
      };

      mockJQuery.mockReturnValue($card);

      const data = {
        retry: {
          success_rate: '95',
          status_message: 'Sistema de reintentos funcionando'
        }
      };

      CardManager.updateSpecificCard($card, 'retries', data);

      expect($value.text).toHaveBeenCalledWith('95%');
      expect($desc.text).toHaveBeenCalledWith('Sistema de reintentos funcionando');
    });

    test('debe actualizar tarjeta de sincronización correctamente', function() {
      const $value = {
        text: jest.fn().mockReturnThis()
      };
      const $desc = {
        text: jest.fn().mockReturnThis()
      };

      const $card = {
        find: jest.fn().mockImplementation(function(selector) {
          if (selector === mockSelectors.STAT_VALUE) {
            return $value;
          }
          if (selector === mockSelectors.STAT_DESC) {
            return $desc;
          }
          return {};
        }),
        data: jest.fn().mockReturnValue('50%')
      };

      mockJQuery.mockReturnValue($card);

      const data = {
        sync: {
          status_text: 'Completado',
          progress_message: 'Sincronización exitosa'
        }
      };

      CardManager.updateSpecificCard($card, 'sync', data);

      expect($value.text).toHaveBeenCalledWith('Completado');
      expect($desc.text).toHaveBeenCalledWith('Sincronización exitosa');
    });

    test('debe restaurar valores originales si no se puede actualizar', function() {
      const $value = {
        text: jest.fn().mockReturnValue('50%')
      };
      const $desc = {
        text: jest.fn().mockReturnValue('Estado original')
      };

      const $card = {
        find: jest.fn().mockImplementation(function(selector) {
          if (selector === mockSelectors.STAT_VALUE) {
            return $value;
          }
          if (selector === mockSelectors.STAT_DESC) {
            return $desc;
          }
          return {};
        }),
        data: jest.fn().mockReturnValue('50%')
      };

      mockJQuery.mockReturnValue($card);

      // eslint-disable-next-line no-console
      const consoleWarnSpy = jest.spyOn(console, 'warn').mockImplementation();

      CardManager.updateSpecificCard($card, 'unknown-type', {});

      expect($value.text).toHaveBeenCalledWith('50%');
      expect($desc.text).toHaveBeenCalledWith('Estado original');
      expect(consoleWarnSpy).toHaveBeenCalled();

      consoleWarnSpy.mockRestore();
    });
  });

  describe('Exposición global', function() {
    test('debe exponer CardManager en window si está disponible', function() {
      // Limpiar cache
      jest.resetModules();
      delete require.cache[require.resolve('../../../assets/js/dashboard/ui/CardManager.js')];

      // Configurar window
      global.window = {};

      // Mock jQuery
      global.jQuery = mockJQuery;
      global.$ = mockJQuery;
      global.SELECTORS = mockSelectors;
      global.miIntegracionApiDashboard = mockMiIntegracionApiDashboard;

      // Cargar módulo
      require('../../../assets/js/dashboard/ui/CardManager.js');

      // Verificar que se expuso en window
      expect(global.window.CardManager).toBeDefined();
      expect(global.window.updateCardData).toBeDefined();
      expect(global.window.updateSpecificCard).toBeDefined();
    });

    test('debe exportar módulo CommonJS si está disponible', function() {
      const module = require('../../../assets/js/dashboard/ui/CardManager.js');
      expect(module).toBeDefined();
      expect(module.CardManager).toBeDefined();
    });
  });
});

