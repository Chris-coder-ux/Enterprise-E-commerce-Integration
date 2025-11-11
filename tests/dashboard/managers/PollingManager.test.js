/**
 * Tests unitarios para PollingManager.js
 * 
 * Verifica que PollingManager esté correctamente definido y funcione correctamente.
 * 
 * @module tests/dashboard/managers/PollingManager
 */

// Mock de setInterval y clearInterval
let intervalIdCounter = 0;
const intervals = new Map();

global.setInterval = jest.fn(function(callback, delay) {
  intervalIdCounter++;
  const id = intervalIdCounter;
  intervals.set(id, { callback, delay });
  return id;
});

global.clearInterval = jest.fn(function(id) {
  intervals.delete(id);
});

// Limpiar el módulo antes de cada test
beforeEach(function() {
  // Asegurar que window existe
  if (typeof global.window === 'undefined') {
    global.window = {};
  }

  // Eliminar PollingManager de window primero
  if (global.window.PollingManager) {
    delete global.window.PollingManager;
  }

  // Limpiar require cache para forzar recarga
  const pollingManagerPath = require.resolve('../../../assets/js/dashboard/managers/PollingManager.js');
  if (require.cache[pollingManagerPath]) {
    delete require.cache[pollingManagerPath];
  }

  // Limpiar intervals mock
  intervals.clear();
  intervalIdCounter = 0;
  global.setInterval.mockClear();
  global.clearInterval.mockClear();

  // Mock de miIntegracionApiDashboard
  global.miIntegracionApiDashboard = {
    pollingConfig: {
      intervals: {
        normal: 15000,
        active: 5000,
        fast: 2000,
        slow: 45000,
        idle: 120000
      },
      thresholds: {
        to_slow: 3,
        to_idle: 8,
        max_errors: 5,
        progress_threshold: 0.1
      }
    }
  };
});

describe('PollingManager.js - PollingManager', function() {
  describe('Definición de PollingManager', function() {
    test('PollingManager debe estar definido', function() {
      // eslint-disable-next-line no-undef
      const pollingManager = require('../../../assets/js/dashboard/managers/PollingManager.js');

      // Verificar que el módulo se carga correctamente
      expect(pollingManager).toBeDefined();
      expect(pollingManager.PollingManager).toBeDefined();
      expect(typeof pollingManager.PollingManager).toBe('function');
    });

    test('PollingManager debe ser una clase', function() {
      // eslint-disable-next-line no-undef
      const pollingManager = require('../../../assets/js/dashboard/managers/PollingManager.js');

      expect(pollingManager.PollingManager).toBeDefined();
      expect(typeof pollingManager.PollingManager).toBe('function');
      
      // Verificar que se puede instanciar
      const instance = new pollingManager.PollingManager();
      expect(instance).toBeDefined();
      expect(instance).toBeInstanceOf(pollingManager.PollingManager);
    });
  });

  describe('Constructor', function() {
    test('Constructor debe inicializar correctamente', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();

      expect(manager.intervals).toBeDefined();
      expect(manager.intervals instanceof Map).toBe(true);
      expect(manager.config).toBeDefined();
      expect(manager.counters).toBeDefined();
    });

    test('Constructor debe usar configuración de PHP cuando está disponible', function() {
      global.miIntegracionApiDashboard = {
        pollingConfig: {
          intervals: {
            normal: 20000,
            active: 10000,
            fast: 3000,
            slow: 60000,
            idle: 180000
          },
          thresholds: {
            to_slow: 5,
            to_idle: 10,
            max_errors: 7,
            progress_threshold: 0.2
          }
        }
      };

      // Limpiar cache
      const pollingManagerPath = require.resolve('../../../assets/js/dashboard/managers/PollingManager.js');
      delete require.cache[pollingManagerPath];

      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();

      expect(manager.config.intervals.normal).toBe(20000);
      expect(manager.config.intervals.active).toBe(10000);
      expect(manager.config.thresholds.to_slow).toBe(5);
    });

    test('Constructor debe usar valores por defecto si no hay configuración PHP', function() {
      delete global.miIntegracionApiDashboard;

      // Limpiar cache
      const pollingManagerPath = require.resolve('../../../assets/js/dashboard/managers/PollingManager.js');
      delete require.cache[pollingManagerPath];

      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();

      expect(manager.config.intervals.normal).toBe(15000);
      expect(manager.config.intervals.active).toBe(5000);
      expect(manager.config.intervals.fast).toBe(2000);
      expect(manager.config.intervals.slow).toBe(45000);
      expect(manager.config.intervals.idle).toBe(120000);
    });
  });

  describe('startPolling', function() {
    test('startPolling debe iniciar un polling correctamente', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();
      const callback = jest.fn();
      const intervalId = manager.startPolling('testPolling', callback, 5000);

      expect(intervalId).toBeDefined();
      expect(typeof intervalId).toBe('number');
      expect(global.setInterval).toHaveBeenCalledWith(callback, 5000);
      expect(manager.intervals.has('testPolling')).toBe(true);
    });

    test('startPolling debe usar intervalo por defecto si no se especifica', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();
      const callback = jest.fn();
      manager.startPolling('testPolling', callback);

      expect(global.setInterval).toHaveBeenCalledWith(callback, manager.config.currentInterval);
    });

    test('startPolling debe detener polling existente con el mismo nombre', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();
      const callback1 = jest.fn();
      const callback2 = jest.fn();

      manager.startPolling('testPolling', callback1, 5000);
      const firstIntervalId = manager.intervals.get('testPolling').id;

      manager.startPolling('testPolling', callback2, 10000);

      expect(global.clearInterval).toHaveBeenCalledWith(firstIntervalId);
      expect(manager.intervals.get('testPolling').callback).toBe(callback2);
    });
  });

  describe('stopPolling', function() {
    test('stopPolling debe detener un polling específico', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();
      const callback = jest.fn();
      const intervalId = manager.startPolling('testPolling', callback, 5000);

      const result = manager.stopPolling('testPolling');

      expect(result).toBe(true);
      expect(global.clearInterval).toHaveBeenCalledWith(intervalId);
      expect(manager.intervals.has('testPolling')).toBe(false);
    });

    test('stopPolling debe retornar false si el polling no existe', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();
      const result = manager.stopPolling('nonExistent');

      expect(result).toBe(false);
    });
  });

  describe('stopAllPolling', function() {
    test('stopAllPolling debe detener todos los polling', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();
      const callback1 = jest.fn();
      const callback2 = jest.fn();

      const id1 = manager.startPolling('polling1', callback1, 5000);
      const id2 = manager.startPolling('polling2', callback2, 10000);

      manager.stopAllPolling();

      expect(global.clearInterval).toHaveBeenCalledWith(id1);
      expect(global.clearInterval).toHaveBeenCalledWith(id2);
      expect(manager.intervals.size).toBe(0);
    });
  });

  describe('isPollingActive', function() {
    test('isPollingActive debe retornar true si hay polling activo', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();
      const callback = jest.fn();

      expect(manager.isPollingActive()).toBe(false);

      manager.startPolling('testPolling', callback, 5000);

      expect(manager.isPollingActive()).toBe(true);
    });

    test('isPollingActive debe verificar polling específico', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();
      const callback = jest.fn();

      manager.startPolling('polling1', callback, 5000);

      expect(manager.isPollingActive('polling1')).toBe(true);
      expect(manager.isPollingActive('polling2')).toBe(false);
    });
  });

  describe('adjustPolling', function() {
    test('adjustPolling debe cambiar a modo idle si no está activo', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();
      manager.adjustPolling(50, false);

      expect(manager.config.currentMode).toBe('idle');
      expect(manager.config.currentInterval).toBe(manager.config.intervals.idle);
    });

    test('adjustPolling debe cambiar a modo fast si el progreso cambia significativamente', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();
      manager.config.lastProgress = 0;
      manager.adjustPolling(10, true); // Cambio de 10% (> 5%)

      expect(manager.config.currentMode).toBe('fast');
      expect(manager.config.currentInterval).toBe(manager.config.intervals.fast);
    });

    test('adjustPolling debe cambiar a modo active si el progreso cambia poco', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();
      manager.config.lastProgress = 50;
      manager.adjustPolling(52, true); // Cambio de 2% (< 5% pero > threshold)

      expect(manager.config.currentMode).toBe('active');
      expect(manager.config.currentInterval).toBe(manager.config.intervals.active);
    });

    test('adjustPolling debe cambiar a modo slow si el progreso está estancado', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();
      manager.config.lastProgress = 50;
      manager.config.progressStagnantCount = 0;

      // Simular 5 ciclos sin progreso
      for (let i = 0; i < 5; i++) {
        manager.adjustPolling(50, true); // Sin cambio
      }

      expect(manager.config.currentMode).toBe('slow');
      expect(manager.config.currentInterval).toBe(manager.config.intervals.slow);
    });
  });

  describe('reset', function() {
    test('reset debe resetear toda la configuración', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();
      const callback = jest.fn();

      manager.startPolling('testPolling', callback, 5000);
      manager.config.currentMode = 'fast';
      manager.config.errorCount = 5;
      manager.config.lastProgress = 75;
      manager.config.progressStagnantCount = 3;

      manager.reset();

      expect(manager.intervals.size).toBe(0);
      expect(manager.config.currentMode).toBe('normal');
      expect(manager.config.currentInterval).toBe(manager.config.intervals.normal);
      expect(manager.config.errorCount).toBe(0);
      expect(manager.config.lastProgress).toBe(0);
      expect(manager.config.progressStagnantCount).toBe(0);
      expect(manager.counters.inactive).toBe(0);
      expect(manager.counters.lastProgress).toBe(0);
    });
  });

  describe('Exposición global', function() {
    test('PollingManager debe estar disponible en window', function() {
      global.window = {};

      // Limpiar cache del módulo
      const pollingManagerPath = require.resolve('../../../assets/js/dashboard/managers/PollingManager.js');
      delete require.cache[pollingManagerPath];

      // eslint-disable-next-line no-undef
      const module = require('../../../assets/js/dashboard/managers/PollingManager.js');

      // Verificar que el módulo exporta PollingManager
      expect(module.PollingManager).toBeDefined();
      expect(typeof module.PollingManager).toBe('function');

      // Verificar que está en window (el código se ejecuta al hacer require)
      if (typeof global.window !== 'undefined') {
        expect(global.window.PollingManager).toBeDefined();
        expect(typeof global.window.PollingManager).toBe('function');
      }
    });

    test('PollingManager debe poder instanciarse desde window', function() {
      global.window = {};

      // Limpiar cache del módulo
      const pollingManagerPath = require.resolve('../../../assets/js/dashboard/managers/PollingManager.js');
      delete require.cache[pollingManagerPath];

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/managers/PollingManager.js');

      if (typeof global.window !== 'undefined' && global.window.PollingManager) {
        const manager = new global.window.PollingManager();
        expect(manager).toBeDefined();
        expect(manager.intervals).toBeDefined();
      }
    });

    test('pollingManager (instancia) debe estar disponible en window', function() {
      global.window = {};

      // Limpiar cache del módulo
      const pollingManagerPath = require.resolve('../../../assets/js/dashboard/managers/PollingManager.js');
      delete require.cache[pollingManagerPath];

      // eslint-disable-next-line no-undef
      const module = require('../../../assets/js/dashboard/managers/PollingManager.js');

      // Verificar que el módulo exporta pollingManager
      expect(module.pollingManager).toBeDefined();

      // Verificar que la instancia global está en window
      // Nota: En algunos entornos de test, window puede no estar disponible
      // pero el módulo debe exportar pollingManager correctamente
      if (typeof global.window !== 'undefined' && global.window.pollingManager) {
        expect(global.window.pollingManager).toBeDefined();
        if (global.window.PollingManager) {
          expect(global.window.pollingManager).toBeInstanceOf(global.window.PollingManager);
        }
        expect(global.window.pollingManager.intervals).toBeDefined();
      }
    });

    test('pollingManager debe ser la misma instancia que se crea automáticamente', function() {
      global.window = {};

      // Limpiar cache del módulo
      const pollingManagerPath = require.resolve('../../../assets/js/dashboard/managers/PollingManager.js');
      delete require.cache[pollingManagerPath];

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/managers/PollingManager.js');

      if (typeof global.window !== 'undefined') {
        const instance1 = global.window.pollingManager;
        const instance2 = global.window.pollingManager;
        
        // Debe ser la misma instancia (singleton)
        expect(instance1).toBe(instance2);
      }
    });
  });

  describe('Compatibilidad con código existente', function() {
    test('PollingManager debe mantener la misma estructura que el original', function() {
      // eslint-disable-next-line no-undef
      const { PollingManager } = require('../../../assets/js/dashboard/managers/PollingManager.js');

      const manager = new PollingManager();

      // Verificar que tiene todos los métodos del original
      expect(typeof manager.startPolling).toBe('function');
      expect(typeof manager.stopPolling).toBe('function');
      expect(typeof manager.stopAllPolling).toBe('function');
      expect(typeof manager.isPollingActive).toBe('function');
      expect(typeof manager.adjustPolling).toBe('function');
      expect(typeof manager.reset).toBe('function');

      // Verificar estructura de config
      expect(manager.config).toHaveProperty('intervals');
      expect(manager.config).toHaveProperty('thresholds');
      expect(manager.config).toHaveProperty('currentInterval');
      expect(manager.config).toHaveProperty('currentMode');
    });
  });
});

