/**
 * Tests unitarios para SyncStateManager.js
 * 
 * Verifica que SyncStateManager esté correctamente definido y funcione correctamente.
 * 
 * @module tests/dashboard/managers/SyncStateManager
 */

// Mock de PollingManager
const mockPollingManager = {
  stopAllPolling: jest.fn(),
  isPollingActive: jest.fn().mockReturnValue(false),
  config: {
    intervals: {
      normal: 15000
    },
    currentInterval: 15000,
    currentMode: 'normal',
    errorCount: 0
  }
};

// Limpiar el módulo antes de cada test
beforeEach(function() {
  // Asegurar que window existe
  if (typeof global.window === 'undefined') {
    global.window = {};
  }

  // Eliminar SyncStateManager y variables de estado de window primero
  if (global.window.SyncStateManager) {
    delete global.window.SyncStateManager;
  }
  if (global.window.inactiveProgressCounter !== undefined) {
    delete global.window.inactiveProgressCounter;
  }
  if (global.window.lastProgressValue !== undefined) {
    delete global.window.lastProgressValue;
  }

  // Limpiar require cache para forzar recarga
  const syncStateManagerPath = require.resolve('../../../assets/js/dashboard/managers/SyncStateManager.js');
  if (require.cache[syncStateManagerPath]) {
    delete require.cache[syncStateManagerPath];
  }

  // Mock de pollingManager
  global.pollingManager = mockPollingManager;
  mockPollingManager.stopAllPolling.mockClear();
  mockPollingManager.isPollingActive.mockReturnValue(false);
});

describe('SyncStateManager.js - SyncStateManager', function() {
  describe('Definición de SyncStateManager', function() {
    test('SyncStateManager debe estar definido', function() {
      // eslint-disable-next-line no-undef
      const syncStateManager = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      // Verificar que el módulo se carga correctamente
      expect(syncStateManager).toBeDefined();
      expect(syncStateManager.SyncStateManager).toBeDefined();
    });

    test('SyncStateManager debe tener todos los métodos', function() {
      // eslint-disable-next-line no-undef
      const syncStateManager = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      expect(syncStateManager.SyncStateManager).toHaveProperty('stopProgressPolling');
      expect(syncStateManager.SyncStateManager).toHaveProperty('isPollingActive');
      expect(syncStateManager.SyncStateManager).toHaveProperty('cleanupOnPageLoad');
      expect(syncStateManager.SyncStateManager).toHaveProperty('getInactiveProgressCounter');
      expect(syncStateManager.SyncStateManager).toHaveProperty('setInactiveProgressCounter');
      expect(syncStateManager.SyncStateManager).toHaveProperty('incrementInactiveProgressCounter');
      expect(syncStateManager.SyncStateManager).toHaveProperty('getLastProgressValue');
      expect(syncStateManager.SyncStateManager).toHaveProperty('setLastProgressValue');
      expect(syncStateManager.SyncStateManager).toHaveProperty('resetCounters');
    });
  });

  describe('stopProgressPolling', function() {
    test('stopProgressPolling debe detener todos los polling', function() {
      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      SyncStateManager.stopProgressPolling();

      expect(mockPollingManager.stopAllPolling).toHaveBeenCalled();
    });

    test('stopProgressPolling debe funcionar sin parámetros', function() {
      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      expect(function() {
        SyncStateManager.stopProgressPolling();
      }).not.toThrow();
    });

    test('stopProgressPolling debe funcionar con parámetro reason', function() {
      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      expect(function() {
        SyncStateManager.stopProgressPolling('Test reason');
      }).not.toThrow();

      expect(mockPollingManager.stopAllPolling).toHaveBeenCalled();
    });
  });

  describe('isPollingActive', function() {
    test('isPollingActive debe retornar false cuando no hay polling activo', function() {
      mockPollingManager.isPollingActive.mockReturnValue(false);

      // Limpiar cache
      const syncStateManagerPath = require.resolve('../../../assets/js/dashboard/managers/SyncStateManager.js');
      delete require.cache[syncStateManagerPath];

      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      const result = SyncStateManager.isPollingActive();

      expect(result).toBe(false);
      expect(mockPollingManager.isPollingActive).toHaveBeenCalled();
    });

    test('isPollingActive debe retornar true cuando hay polling activo', function() {
      mockPollingManager.isPollingActive.mockReturnValue(true);

      // Limpiar cache
      const syncStateManagerPath = require.resolve('../../../assets/js/dashboard/managers/SyncStateManager.js');
      delete require.cache[syncStateManagerPath];

      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      const result = SyncStateManager.isPollingActive();

      expect(result).toBe(true);
      expect(mockPollingManager.isPollingActive).toHaveBeenCalled();
    });

    test('isPollingActive debe retornar false si pollingManager no está disponible', function() {
      delete global.pollingManager;

      // Limpiar cache
      const syncStateManagerPath = require.resolve('../../../assets/js/dashboard/managers/SyncStateManager.js');
      delete require.cache[syncStateManagerPath];

      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      const result = SyncStateManager.isPollingActive();

      expect(result).toBe(false);
    });
  });

  describe('cleanupOnPageLoad', function() {
    test('cleanupOnPageLoad debe detener polling si está activo', function() {
      mockPollingManager.isPollingActive.mockReturnValue(true);

      // Limpiar cache
      const syncStateManagerPath = require.resolve('../../../assets/js/dashboard/managers/SyncStateManager.js');
      delete require.cache[syncStateManagerPath];

      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      SyncStateManager.cleanupOnPageLoad();

      expect(mockPollingManager.stopAllPolling).toHaveBeenCalled();
    });

    test('cleanupOnPageLoad debe resetear contadores', function() {
      // Limpiar cache
      const syncStateManagerPath = require.resolve('../../../assets/js/dashboard/managers/SyncStateManager.js');
      delete require.cache[syncStateManagerPath];

      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      // Establecer valores iniciales
      SyncStateManager.setInactiveProgressCounter(5);
      SyncStateManager.setLastProgressValue(75);

      SyncStateManager.cleanupOnPageLoad();

      expect(SyncStateManager.getInactiveProgressCounter()).toBe(0);
      expect(SyncStateManager.getLastProgressValue()).toBe(0);
    });

    test('cleanupOnPageLoad debe resetear configuración de polling', function() {
      mockPollingManager.config.currentInterval = 5000;
      mockPollingManager.config.currentMode = 'active';
      mockPollingManager.config.errorCount = 3;

      // Limpiar cache
      const syncStateManagerPath = require.resolve('../../../assets/js/dashboard/managers/SyncStateManager.js');
      delete require.cache[syncStateManagerPath];

      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      SyncStateManager.cleanupOnPageLoad();

      expect(mockPollingManager.config.currentInterval).toBe(15000);
      expect(mockPollingManager.config.currentMode).toBe('normal');
      expect(mockPollingManager.config.errorCount).toBe(0);
    });
  });

  describe('Gestión de contadores', function() {
    test('getInactiveProgressCounter debe retornar el valor actual', function() {
      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      expect(SyncStateManager.getInactiveProgressCounter()).toBe(0);
    });

    test('setInactiveProgressCounter debe establecer el valor', function() {
      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      SyncStateManager.setInactiveProgressCounter(10);
      expect(SyncStateManager.getInactiveProgressCounter()).toBe(10);
    });

    test('incrementInactiveProgressCounter debe incrementar el contador', function() {
      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      SyncStateManager.setInactiveProgressCounter(5);
      const result = SyncStateManager.incrementInactiveProgressCounter();

      expect(result).toBe(6);
      expect(SyncStateManager.getInactiveProgressCounter()).toBe(6);
    });

    test('getLastProgressValue debe retornar el valor actual', function() {
      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      expect(SyncStateManager.getLastProgressValue()).toBe(0);
    });

    test('setLastProgressValue debe establecer el valor', function() {
      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      SyncStateManager.setLastProgressValue(50);
      expect(SyncStateManager.getLastProgressValue()).toBe(50);
    });

    test('resetCounters debe resetear ambos contadores', function() {
      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      SyncStateManager.setInactiveProgressCounter(10);
      SyncStateManager.setLastProgressValue(75);

      SyncStateManager.resetCounters();

      expect(SyncStateManager.getInactiveProgressCounter()).toBe(0);
      expect(SyncStateManager.getLastProgressValue()).toBe(0);
    });
  });

  describe('Exposición global', function() {
    test('SyncStateManager debe estar disponible en window', function() {
      global.window = {};

      // Limpiar cache del módulo
      const syncStateManagerPath = require.resolve('../../../assets/js/dashboard/managers/SyncStateManager.js');
      delete require.cache[syncStateManagerPath];

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      // Verificar que está en window
      if (typeof global.window !== 'undefined') {
        expect(global.window.SyncStateManager).toBeDefined();
        expect(typeof global.window.SyncStateManager).toBe('object');
      }
    });

    test('inactiveProgressCounter debe estar disponible en window', function() {
      global.window = {};

      // Limpiar cache del módulo
      const syncStateManagerPath = require.resolve('../../../assets/js/dashboard/managers/SyncStateManager.js');
      delete require.cache[syncStateManagerPath];

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      // Verificar que está en window
      if (typeof global.window !== 'undefined') {
        expect(global.window.inactiveProgressCounter).toBeDefined();
        expect(typeof global.window.inactiveProgressCounter).toBe('number');
        expect(global.window.inactiveProgressCounter).toBe(0);
      }
    });

    test('lastProgressValue debe estar disponible en window', function() {
      global.window = {};

      // Limpiar cache del módulo
      const syncStateManagerPath = require.resolve('../../../assets/js/dashboard/managers/SyncStateManager.js');
      delete require.cache[syncStateManagerPath];

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      // Verificar que está en window
      if (typeof global.window !== 'undefined') {
        expect(global.window.lastProgressValue).toBeDefined();
        expect(typeof global.window.lastProgressValue).toBe('number');
        expect(global.window.lastProgressValue).toBe(0);
      }
    });

    test('Las variables globales deben poder modificarse', function() {
      global.window = {};

      // Limpiar cache del módulo
      const syncStateManagerPath = require.resolve('../../../assets/js/dashboard/managers/SyncStateManager.js');
      delete require.cache[syncStateManagerPath];

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      if (typeof global.window !== 'undefined') {
        global.window.inactiveProgressCounter = 5;
        global.window.lastProgressValue = 50;

        expect(global.window.inactiveProgressCounter).toBe(5);
        expect(global.window.lastProgressValue).toBe(50);
      }
    });
  });

  describe('Compatibilidad con código existente', function() {
    test('SyncStateManager debe mantener la misma estructura que el original', function() {
      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      // Verificar que tiene todos los métodos del original
      expect(typeof SyncStateManager.stopProgressPolling).toBe('function');
      expect(typeof SyncStateManager.isPollingActive).toBe('function');
      expect(typeof SyncStateManager.cleanupOnPageLoad).toBe('function');
    });

    test('cleanupOnPageLoad debe funcionar igual que el original', function() {
      mockPollingManager.isPollingActive.mockReturnValue(true);

      // Limpiar cache
      const syncStateManagerPath = require.resolve('../../../assets/js/dashboard/managers/SyncStateManager.js');
      delete require.cache[syncStateManagerPath];

      // eslint-disable-next-line no-undef
      const { SyncStateManager } = require('../../../assets/js/dashboard/managers/SyncStateManager.js');

      SyncStateManager.cleanupOnPageLoad();

      expect(mockPollingManager.stopAllPolling).toHaveBeenCalled();
      expect(SyncStateManager.getInactiveProgressCounter()).toBe(0);
      expect(SyncStateManager.getLastProgressValue()).toBe(0);
    });
  });
});

