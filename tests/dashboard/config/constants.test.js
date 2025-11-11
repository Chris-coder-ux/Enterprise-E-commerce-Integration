/**
 * Tests unitarios para constants.js
 * 
 * Verifica que los SELECTORS estén correctamente definidos y expuestos globalmente.
 * 
 * @module tests/dashboard/config/constants
 */

// Limpiar el módulo antes de cada test
beforeEach(function() {
  // Asegurar que window existe
  if (typeof global.window === 'undefined') {
    global.window = {};
  }
  
  // Eliminar SELECTORS de window primero
  if (global.window.SELECTORS) {
    delete global.window.SELECTORS;
  }
  
  // Limpiar require cache para forzar recarga
  const constantsPath = require.resolve('../../../assets/js/dashboard/config/constants.js');
  if (require.cache[constantsPath]) {
    delete require.cache[constantsPath];
  }
});

describe('constants.js - SELECTORS', function() {
  describe('Definición de SELECTORS', function() {
    test('SELECTORS debe estar definido', function() {
      // eslint-disable-next-line no-undef
      const constants = require('../../../assets/js/dashboard/config/constants.js');
      
      // Verificar que el módulo se carga correctamente
      expect(constants).toBeDefined();
      expect(constants.SELECTORS).toBeDefined();
    });
    
    test('SELECTORS debe tener todas las propiedades base', function() {
      // Simular window para el test
      global.window = {};
      
      // eslint-disable-next-line no-undef
      const constants = require('../../../assets/js/dashboard/config/constants.js');
      
      // Verificar que el módulo exporta SELECTORS
      expect(constants.SELECTORS).toBeDefined();
      expect(constants.SELECTORS.STAT_CARD).toBe('.mi-integracion-api-stat-card');
      expect(constants.SELECTORS.STAT_VALUE).toBe('.mi-integracion-api-stat-value');
      expect(constants.SELECTORS.STAT_DESC).toBe('.mi-integracion-api-stat-desc');
      
      // Verificar que está en window (el código se ejecuta al hacer require)
      expect(global.window.SELECTORS).toBeDefined();
      expect(global.window.SELECTORS).toBe(constants.SELECTORS);
      expect(global.window.SELECTORS.STAT_CARD).toBe('.mi-integracion-api-stat-card');
    });
    
    test('SELECTORS debe tener todas las propiedades específicas', function() {
      global.window = {};
      
      // eslint-disable-next-line no-undef
      const constants = require('../../../assets/js/dashboard/config/constants.js');
      
      expect(constants.SELECTORS.STAT_CARD_MEMORY).toBe('.mi-integracion-api-stat-card.memory');
      expect(constants.SELECTORS.STAT_CARD_RETRIES).toBe('.mi-integracion-api-stat-card.retries');
      expect(constants.SELECTORS.STAT_CARD_SYNC).toBe('.mi-integracion-api-stat-card.sync');
      
      // Verificar que está en window
      expect(global.window.SELECTORS).toBeDefined();
      expect(global.window.SELECTORS).toBe(constants.SELECTORS);
      expect(global.window.SELECTORS.STAT_CARD_MEMORY).toBe('.mi-integracion-api-stat-card.memory');
    });
    
    test('SELECTORS debe tener selectores compuestos', function() {
      global.window = {};
      
      // eslint-disable-next-line no-undef
      const constants = require('../../../assets/js/dashboard/config/constants.js');
      
      expect(constants.SELECTORS.DASHBOARD_CARDS).toBe('.dashboard-card, .verial-stat-card, .mi-integracion-api-stat-card');
      expect(constants.SELECTORS.METRIC_ELEMENTS).toBe('.dashboard-metric, .verial-stat-value, .mi-integracion-api-stat-value');
      
      // Verificar que está en window
      expect(global.window.SELECTORS).toBeDefined();
      expect(global.window.SELECTORS).toBe(constants.SELECTORS);
      expect(global.window.SELECTORS.DASHBOARD_CARDS).toBe('.dashboard-card, .verial-stat-card, .mi-integracion-api-stat-card');
    });
    
    test('SELECTORS debe tener todas las propiedades esperadas', function() {
      global.window = {};
      
      // eslint-disable-next-line no-undef
      const constants = require('../../../assets/js/dashboard/config/constants.js');
      
      const expectedProperties = [
        'STAT_CARD',
        'STAT_VALUE',
        'STAT_DESC',
        'STAT_CARD_MEMORY',
        'STAT_CARD_RETRIES',
        'STAT_CARD_SYNC',
        'DASHBOARD_CARDS',
        'METRIC_ELEMENTS'
      ];
      
      expectedProperties.forEach(function(prop) {
        expect(constants.SELECTORS).toHaveProperty(prop);
        expect(typeof constants.SELECTORS[prop]).toBe('string');
        expect(constants.SELECTORS[prop].length).toBeGreaterThan(0);
        
        // También verificar window
        expect(global.window.SELECTORS).toBeDefined();
        expect(global.window.SELECTORS).toBe(constants.SELECTORS);
        expect(global.window.SELECTORS).toHaveProperty(prop);
      });
    });
  });
  
  describe('Exposición global', function() {
    test('SELECTORS debe estar disponible en window', function() {
      global.window = {};
      
      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/config/constants.js');
      
      expect(global.window.SELECTORS).toBeDefined();
      expect(typeof global.window.SELECTORS).toBe('object');
    });
    
    test('SELECTORS debe ser un objeto no nulo', function() {
      global.window = {};
      
      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/config/constants.js');
      
      expect(global.window.SELECTORS).not.toBeNull();
      expect(global.window.SELECTORS).not.toBeUndefined();
    });
    
    test('SELECTORS debe ser enumerable', function() {
      global.window = {};
      
      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/config/constants.js');
      
      const descriptor = Object.getOwnPropertyDescriptor(global.window, 'SELECTORS');
      if (descriptor) {
        expect(descriptor.enumerable).toBe(true);
      } else {
        // Si no hay descriptor, verificar que existe
        expect(global.window.SELECTORS).toBeDefined();
      }
    });
    
    test('SELECTORS debe ser configurable', function() {
      global.window = {};
      
      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/config/constants.js');
      
      const descriptor = Object.getOwnPropertyDescriptor(global.window, 'SELECTORS');
      if (descriptor) {
        expect(descriptor.configurable).toBe(true);
      } else {
        // Si no hay descriptor, verificar que existe
        expect(global.window.SELECTORS).toBeDefined();
      }
    });
    
    test('SELECTORS debe ser writable', function() {
      global.window = {};
      
      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/config/constants.js');
      
      // Verificar que SELECTORS está disponible
      expect(global.window.SELECTORS).toBeDefined();
      
      const descriptor = Object.getOwnPropertyDescriptor(global.window, 'SELECTORS');
      if (descriptor) {
        expect(descriptor.writable).toBe(true);
      } else {
        // Si no hay descriptor, verificar que existe y se puede modificar
        const original = global.window.SELECTORS;
        expect(original).toBeDefined();
        global.window.SELECTORS = {};
        expect(global.window.SELECTORS).not.toBe(original);
        global.window.SELECTORS = original; // Restaurar
      }
    });
  });
  
  describe('Compatibilidad con código existente', function() {
    test('SELECTORS debe mantener la misma estructura que el original', function() {
      global.window = {};
      
      // eslint-disable-next-line no-undef
      const constants = require('../../../assets/js/dashboard/config/constants.js');
      
      // Verificar que los valores coinciden con el código original
      expect(constants.SELECTORS).toBeDefined();
      expect(constants.SELECTORS.STAT_CARD).toBe('.mi-integracion-api-stat-card');
      expect(constants.SELECTORS.STAT_VALUE).toBe('.mi-integracion-api-stat-value');
      expect(constants.SELECTORS.STAT_DESC).toBe('.mi-integracion-api-stat-desc');
      expect(constants.SELECTORS.STAT_CARD_MEMORY).toBe('.mi-integracion-api-stat-card.memory');
      expect(constants.SELECTORS.STAT_CARD_RETRIES).toBe('.mi-integracion-api-stat-card.retries');
      expect(constants.SELECTORS.STAT_CARD_SYNC).toBe('.mi-integracion-api-stat-card.sync');
      expect(constants.SELECTORS.DASHBOARD_CARDS).toBe('.dashboard-card, .verial-stat-card, .mi-integracion-api-stat-card');
      expect(constants.SELECTORS.METRIC_ELEMENTS).toBe('.dashboard-metric, .verial-stat-value, .mi-integracion-api-stat-value');
      
      // Verificar que está en window (el código se ejecuta al hacer require)
      expect(global.window.SELECTORS).toBeDefined();
      expect(global.window.SELECTORS).toBe(constants.SELECTORS);
      expect(global.window.SELECTORS.STAT_CARD).toBe('.mi-integracion-api-stat-card');
    });
    
    test('SELECTORS debe poder usarse con jQuery', function() {
      global.window = {};
      global.jQuery = jest.fn(function(selector) {
        return {
          each: jest.fn(),
          find: jest.fn(),
          addClass: jest.fn(),
          removeClass: jest.fn()
        };
      });
      global.$ = global.jQuery;
      
      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/config/constants.js');
      
      // Verificar que SELECTORS está disponible en window
      expect(global.window.SELECTORS).toBeDefined();
      
      // Simular uso con jQuery
      const $cards = jQuery(global.window.SELECTORS.STAT_CARD);
      expect(jQuery).toHaveBeenCalledWith('.mi-integracion-api-stat-card');
    });
  });
  
  describe('Manejo de errores', function() {
    test('Debe manejar errores al asignar a window', function() {
      global.window = {};
      
      // Hacer que window.SELECTORS sea no configurable para forzar el fallback
      Object.defineProperty(window, 'SELECTORS', {
        value: null,
        writable: false,
        enumerable: true,
        configurable: false
      });
      
      // El código debe usar defineProperty como fallback
      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/config/constants.js');
      
      // Verificar que el fallback funciona
      // Nota: En este caso, el fallback no funcionará porque no es configurable,
      // pero el código no debe lanzar un error
      expect(() => {
        // eslint-disable-next-line no-undef
        require('../../../assets/js/dashboard/config/constants.js');
      }).not.toThrow();
    });
    
    test('Debe funcionar cuando window no está definido', function() {
      const originalWindow = global.window;
      delete global.window;
      
      // No debe lanzar error
      expect(() => {
        // eslint-disable-next-line no-undef
        require('../../../assets/js/dashboard/config/constants.js');
      }).not.toThrow();
      
      // Restaurar window
      global.window = originalWindow;
    });
  });
  
  describe('Valores de selectores', function() {
    test('Todos los selectores deben ser strings válidos', function() {
      global.window = {};
      
      // eslint-disable-next-line no-undef
      const constants = require('../../../assets/js/dashboard/config/constants.js');
      
      Object.keys(constants.SELECTORS).forEach(function(key) {
        const selector = constants.SELECTORS[key];
        expect(typeof selector).toBe('string');
        expect(selector.length).toBeGreaterThan(0);
        // Los selectores CSS deben empezar con punto, #, o ser selectores válidos
        expect(selector.match(/^[.#\[]|^[a-zA-Z]/)).toBeTruthy();
      });
    });
    
    test('Selectores base deben ser selectores simples', function() {
      global.window = {};
      
      // eslint-disable-next-line no-undef
      const constants = require('../../../assets/js/dashboard/config/constants.js');
      
      expect(constants.SELECTORS.STAT_CARD).toMatch(/^\./);
      expect(constants.SELECTORS.STAT_VALUE).toMatch(/^\./);
      expect(constants.SELECTORS.STAT_DESC).toMatch(/^\./);
    });
    
    test('Selectores compuestos deben contener comas', function() {
      global.window = {};
      
      // eslint-disable-next-line no-undef
      const constants = require('../../../assets/js/dashboard/config/constants.js');
      
      expect(constants.SELECTORS.DASHBOARD_CARDS).toContain(',');
      expect(constants.SELECTORS.METRIC_ELEMENTS).toContain(',');
    });
  });
});

