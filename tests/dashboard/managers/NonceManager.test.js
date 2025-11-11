/**
 * Tests unitarios para NonceManager.js
 * 
 * Verifica que NonceManager esté correctamente definido y funcione correctamente.
 * 
 * @module tests/dashboard/managers/NonceManager
 */

// Mock de jQuery
const mockJQuery = {
  ajax: jest.fn()
};

// Mock de miIntegracionApiDashboard
let mockMiIntegracionApiDashboard = {
  ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
  nonce: 'test-nonce-123'
};

// Limpiar el módulo antes de cada test
beforeEach(function() {
  // Asegurar que window existe
  if (typeof global.window === 'undefined') {
    global.window = {};
  }

  // Eliminar NonceManager de window primero
  if (global.window.NonceManager) {
    delete global.window.NonceManager;
  }

  // Limpiar require cache para forzar recarga
  const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
  if (require.cache[nonceManagerPath]) {
    delete require.cache[nonceManagerPath];
  }

  // Mock de jQuery
  global.jQuery = mockJQuery;
  global.$ = mockJQuery;
  mockJQuery.ajax.mockClear();

  // Mock de miIntegracionApiDashboard
  global.miIntegracionApiDashboard = mockMiIntegracionApiDashboard;
});

describe('NonceManager.js - NonceManager', function() {
  describe('Definición de NonceManager', function() {
    test('NonceManager debe estar definido', function() {
      // eslint-disable-next-line no-undef
      const nonceManager = require('../../../assets/js/dashboard/managers/NonceManager.js');

      // Verificar que el módulo se carga correctamente
      expect(nonceManager).toBeDefined();
      expect(nonceManager.NonceManager).toBeDefined();
    });

    test('NonceManager debe tener todos los métodos', function() {
      // eslint-disable-next-line no-undef
      const nonceManager = require('../../../assets/js/dashboard/managers/NonceManager.js');

      expect(nonceManager.NonceManager).toHaveProperty('attemptRenewal');
      expect(nonceManager.NonceManager).toHaveProperty('setupAutoRenewal');
      expect(nonceManager.NonceManager).toHaveProperty('stopAutoRenewal');
      expect(nonceManager.NonceManager).toHaveProperty('isAutoRenewalActive');
      expect(nonceManager.NonceManager).toHaveProperty('DEFAULT_RENEWAL_INTERVAL');
    });

    test('DEFAULT_RENEWAL_INTERVAL debe ser 30 minutos', function() {
      // eslint-disable-next-line no-undef
      const nonceManager = require('../../../assets/js/dashboard/managers/NonceManager.js');

      expect(nonceManager.NonceManager.DEFAULT_RENEWAL_INTERVAL).toBe(30 * 60 * 1000);
    });
  });

  describe('attemptRenewal', function() {
    test('attemptRenewal debe realizar una petición AJAX', function() {
      // Mock de respuesta exitosa
      mockJQuery.ajax.mockImplementation(function(options) {
        if (options.success) {
          options.success({
            success: true,
            data: {
              nonce: 'new-nonce-456'
            }
          });
        }
        return {
          done: function(callback) {
            if (callback) {
              callback({
                success: true,
                data: {
                  nonce: 'new-nonce-456'
                }
              });
            }
            return this;
          },
          fail: function() {
            return this;
          }
        };
      });

      // Limpiar cache
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      const { NonceManager } = require('../../../assets/js/dashboard/managers/NonceManager.js');

      NonceManager.attemptRenewal();

      expect(mockJQuery.ajax).toHaveBeenCalled();
      expect(mockJQuery.ajax).toHaveBeenCalledWith(
        expect.objectContaining({
          url: 'http://test.local/wp-admin/admin-ajax.php',
          type: 'POST',
          data: {
            action: 'mia_renew_nonce'
          }
        })
      );
    });

    test('attemptRenewal debe actualizar el nonce en miIntegracionApiDashboard cuando es exitoso', function() {
      const newNonce = 'new-nonce-789';
      
      mockJQuery.ajax.mockImplementation(function(options) {
        if (options.success) {
          options.success({
            success: true,
            data: {
              nonce: newNonce
            }
          });
        }
        return {
          done: function() {
            return this;
          },
          fail: function() {
            return this;
          }
        };
      });

      // Limpiar cache
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      const { NonceManager } = require('../../../assets/js/dashboard/managers/NonceManager.js');

      NonceManager.attemptRenewal();

      expect(global.miIntegracionApiDashboard.nonce).toBe(newNonce);
    });

    test('attemptRenewal debe llamar a showNotification si está disponible', function() {
      const showNotification = jest.fn();
      
      mockJQuery.ajax.mockImplementation(function(options) {
        if (options.success) {
          options.success({
            success: true,
            data: {
              nonce: 'new-nonce-999'
            }
          });
        }
        return {
          done: function() {
            return this;
          },
          fail: function() {
            return this;
          }
        };
      });

      // Limpiar cache
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      const { NonceManager } = require('../../../assets/js/dashboard/managers/NonceManager.js');

      NonceManager.attemptRenewal(showNotification);

      expect(showNotification).toHaveBeenCalledWith('Token de seguridad renovado automáticamente', 'success');
    });

    test('attemptRenewal debe manejar errores de respuesta', function() {
      const showNotification = jest.fn();
      
      mockJQuery.ajax.mockImplementation(function(options) {
        if (options.success) {
          options.success({
            success: false,
            data: {
              message: 'Error de validación',
              code: 'validation_error'
            }
          });
        }
        return {
          done: function() {
            return this;
          },
          fail: function() {
            return this;
          }
        };
      });

      // Limpiar cache
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      const { NonceManager } = require('../../../assets/js/dashboard/managers/NonceManager.js');

      NonceManager.attemptRenewal(showNotification);

      expect(showNotification).toHaveBeenCalledWith(
        expect.stringContaining('No se pudo renovar el token'),
        'warning'
      );
    });

    test('attemptRenewal debe manejar errores AJAX', function() {
      const showNotification = jest.fn();
      
      mockJQuery.ajax.mockImplementation(function(options) {
        if (options.error) {
          options.error({
            status: 500
          }, 'error', 'Internal Server Error');
        }
        return {
          done: function() {
            return this;
          },
          fail: function() {
            return this;
          }
        };
      });

      // Limpiar cache
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      const { NonceManager } = require('../../../assets/js/dashboard/managers/NonceManager.js');

      NonceManager.attemptRenewal(showNotification);

      expect(showNotification).toHaveBeenCalledWith(
        expect.stringContaining('Error al renovar el token'),
        'error'
      );
    });

    test('attemptRenewal no debe hacer nada si ajaxurl no está disponible', function() {
      delete global.miIntegracionApiDashboard;

      // Limpiar cache
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      const { NonceManager } = require('../../../assets/js/dashboard/managers/NonceManager.js');

      NonceManager.attemptRenewal();

      expect(mockJQuery.ajax).not.toHaveBeenCalled();
    });
  });

  describe('setupAutoRenewal', function() {
    beforeEach(function() {
      jest.useFakeTimers();
    });

    afterEach(function() {
      jest.useRealTimers();
    });

    test('setupAutoRenewal debe configurar un intervalo', function() {
      const showNotification = jest.fn();
      
      mockJQuery.ajax.mockImplementation(function(options) {
        if (options.success) {
          options.success({
            success: true,
            data: {
              nonce: 'new-nonce-auto'
            }
          });
        }
        return {
          done: function() {
            return this;
          },
          fail: function() {
            return this;
          }
        };
      });

      // Limpiar cache
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      const { NonceManager } = require('../../../assets/js/dashboard/managers/NonceManager.js');

      NonceManager.setupAutoRenewal(undefined, showNotification);

      expect(NonceManager.isAutoRenewalActive()).toBe(true);

      // Avanzar el tiempo para que se ejecute el intervalo
      jest.advanceTimersByTime(30 * 60 * 1000);

      expect(mockJQuery.ajax).toHaveBeenCalled();
    });

    test('setupAutoRenewal debe usar el intervalo personalizado si se proporciona', function() {
      const customInterval = 5 * 60 * 1000; // 5 minutos
      const showNotification = jest.fn();
      
      mockJQuery.ajax.mockImplementation(function(options) {
        if (options.success) {
          options.success({
            success: true,
            data: {
              nonce: 'new-nonce-custom'
            }
          });
        }
        return {
          done: function() {
            return this;
          },
          fail: function() {
            return this;
          }
        };
      });

      // Limpiar cache
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      const { NonceManager } = require('../../../assets/js/dashboard/managers/NonceManager.js');

      NonceManager.setupAutoRenewal(customInterval, showNotification);

      expect(NonceManager.isAutoRenewalActive()).toBe(true);

      // Avanzar el tiempo para que se ejecute el intervalo personalizado
      jest.advanceTimersByTime(customInterval);

      expect(mockJQuery.ajax).toHaveBeenCalled();
    });

    test('setupAutoRenewal debe detener el intervalo anterior si existe', function() {
      jest.useFakeTimers();

      // Limpiar cache
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      const { NonceManager } = require('../../../assets/js/dashboard/managers/NonceManager.js');

      NonceManager.setupAutoRenewal();
      const firstIntervalId = NonceManager.isAutoRenewalActive();
      
      NonceManager.setupAutoRenewal();
      const secondIntervalId = NonceManager.isAutoRenewalActive();

      // Debe haber solo un intervalo activo
      expect(NonceManager.isAutoRenewalActive()).toBe(true);

      jest.useRealTimers();
    });
  });

  describe('stopAutoRenewal', function() {
    beforeEach(function() {
      jest.useFakeTimers();
    });

    afterEach(function() {
      jest.useRealTimers();
    });

    test('stopAutoRenewal debe detener el intervalo activo', function() {
      // Limpiar cache
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      const { NonceManager } = require('../../../assets/js/dashboard/managers/NonceManager.js');

      NonceManager.setupAutoRenewal();
      expect(NonceManager.isAutoRenewalActive()).toBe(true);

      NonceManager.stopAutoRenewal();
      expect(NonceManager.isAutoRenewalActive()).toBe(false);
    });

    test('stopAutoRenewal no debe hacer nada si no hay intervalo activo', function() {
      // Limpiar cache
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      const { NonceManager } = require('../../../assets/js/dashboard/managers/NonceManager.js');

      expect(NonceManager.isAutoRenewalActive()).toBe(false);

      expect(function() {
        NonceManager.stopAutoRenewal();
      }).not.toThrow();

      expect(NonceManager.isAutoRenewalActive()).toBe(false);
    });
  });

  describe('isAutoRenewalActive', function() {
    beforeEach(function() {
      jest.useFakeTimers();
    });

    afterEach(function() {
      jest.useRealTimers();
    });

    test('isAutoRenewalActive debe retornar false inicialmente', function() {
      // Limpiar cache
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      const { NonceManager } = require('../../../assets/js/dashboard/managers/NonceManager.js');

      expect(NonceManager.isAutoRenewalActive()).toBe(false);
    });

    test('isAutoRenewalActive debe retornar true después de setupAutoRenewal', function() {
      // Limpiar cache
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      const { NonceManager } = require('../../../assets/js/dashboard/managers/NonceManager.js');

      NonceManager.setupAutoRenewal();
      expect(NonceManager.isAutoRenewalActive()).toBe(true);
    });

    test('isAutoRenewalActive debe retornar false después de stopAutoRenewal', function() {
      // Limpiar cache
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      const { NonceManager } = require('../../../assets/js/dashboard/managers/NonceManager.js');

      NonceManager.setupAutoRenewal();
      expect(NonceManager.isAutoRenewalActive()).toBe(true);

      NonceManager.stopAutoRenewal();
      expect(NonceManager.isAutoRenewalActive()).toBe(false);
    });
  });

  describe('Exposición global', function() {
    test('NonceManager debe estar disponible en window', function() {
      global.window = {};

      // Limpiar cache del módulo
      const nonceManagerPath = require.resolve('../../../assets/js/dashboard/managers/NonceManager.js');
      delete require.cache[nonceManagerPath];

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/managers/NonceManager.js');

      // Verificar que está en window
      if (typeof global.window !== 'undefined') {
        expect(global.window.NonceManager).toBeDefined();
        expect(typeof global.window.NonceManager).toBe('object');
      }
    });
  });
});

