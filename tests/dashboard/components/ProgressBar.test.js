/**
 * Tests unitarios para ProgressBar.js
 * 
 * Verifica que ProgressBar esté correctamente definido y funcione correctamente.
 * 
 * @module tests/dashboard/components/ProgressBar
 */

// Mock de jQuery
const mockJQuery = function(selector) {
  const mockElement = {
    length: selector === '.sync-progress-bar' ? 1 : 0,
    css: jest.fn().mockReturnThis(),
    prop: jest.fn().mockReturnThis(),
    removeClass: jest.fn().mockReturnThis(),
    addClass: jest.fn().mockReturnThis()
  };
  return mockElement;
};

mockJQuery.fn = {
  css: jest.fn().mockReturnThis(),
  prop: jest.fn().mockReturnThis(),
  removeClass: jest.fn().mockReturnThis(),
  addClass: jest.fn().mockReturnThis()
};

// Mock de DOM_CACHE
const mockDomCache = {
  $progressBar: {
    length: 1,
    css: jest.fn().mockReturnThis(),
    prop: jest.fn().mockReturnThis(),
    removeClass: jest.fn().mockReturnThis(),
    addClass: jest.fn().mockReturnThis()
  }
};

// Limpiar el módulo antes de cada test
beforeEach(function() {
  // Asegurar que window existe
  if (typeof global.window === 'undefined') {
    global.window = {};
  }

  // Eliminar ProgressBar de window primero
  if (global.window.ProgressBar) {
    delete global.window.ProgressBar;
  }

  // Limpiar require cache para forzar recarga
  const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
  if (require.cache[progressBarPath]) {
    delete require.cache[progressBarPath];
  }

  // Mock de jQuery
  global.jQuery = mockJQuery;
  global.$ = mockJQuery;

  // Mock de DOM_CACHE
  global.DOM_CACHE = mockDomCache;

  // Resetear mocks
  mockDomCache.$progressBar.css.mockClear();
  mockDomCache.$progressBar.length = 1;
});

describe('ProgressBar.js - ProgressBar', function() {
  describe('Definición de ProgressBar', function() {
    test('ProgressBar debe estar definido', function() {
      // eslint-disable-next-line no-undef
      const progressBar = require('../../../assets/js/dashboard/components/ProgressBar.js');

      // Verificar que el módulo se carga correctamente
      expect(progressBar).toBeDefined();
      expect(progressBar.ProgressBar).toBeDefined();
    });

    test('ProgressBar debe tener todos los métodos', function() {
      // eslint-disable-next-line no-undef
      const progressBar = require('../../../assets/js/dashboard/components/ProgressBar.js');

      expect(progressBar.ProgressBar).toHaveProperty('initialize');
      expect(progressBar.ProgressBar).toHaveProperty('setWidth');
      expect(progressBar.ProgressBar).toHaveProperty('setPercentage');
      expect(progressBar.ProgressBar).toHaveProperty('setColor');
      expect(progressBar.ProgressBar).toHaveProperty('reset');
      expect(progressBar.ProgressBar).toHaveProperty('getWidth');
      expect(progressBar.ProgressBar).toHaveProperty('isAvailable');
      expect(progressBar.ProgressBar).toHaveProperty('DEFAULT_SELECTOR');
    });

    test('DEFAULT_SELECTOR debe ser ".sync-progress-bar"', function() {
      // eslint-disable-next-line no-undef
      const progressBar = require('../../../assets/js/dashboard/components/ProgressBar.js');

      expect(progressBar.ProgressBar.DEFAULT_SELECTOR).toBe('.sync-progress-bar');
    });
  });

  describe('initialize', function() {
    test('initialize debe retornar true si la barra de progreso está disponible', function() {
      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.initialize();

      expect(result).toBe(true);
    });

    test('initialize debe retornar false si jQuery no está disponible', function() {
      delete global.jQuery;

      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.initialize();

      expect(result).toBe(false);
    });

    test('initialize debe retornar false si la barra de progreso no está en el DOM', function() {
      global.DOM_CACHE = null;
      global.jQuery = function(selector) {
        return {
          length: 0
        };
      };

      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.initialize();

      expect(result).toBe(false);
    });
  });

  describe('setWidth', function() {
    test('setWidth debe actualizar el ancho de la barra de progreso', function() {
      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.setWidth('50%');

      expect(result).toBe(true);
      expect(mockDomCache.$progressBar.css).toHaveBeenCalledWith('width', '50%');
    });

    test('setWidth debe convertir números a porcentajes', function() {
      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.setWidth(75);

      expect(result).toBe(true);
      expect(mockDomCache.$progressBar.css).toHaveBeenCalledWith('width', '75%');
    });

    test('setWidth debe retornar false si jQuery no está disponible', function() {
      delete global.jQuery;

      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.setWidth('50%');

      expect(result).toBe(false);
    });
  });

  describe('setPercentage', function() {
    test('setPercentage debe actualizar el porcentaje de la barra de progreso', function() {
      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.setPercentage(50);

      expect(result).toBe(true);
      expect(mockDomCache.$progressBar.css).toHaveBeenCalledWith('width', '50%');
    });

    test('setPercentage debe limitar el porcentaje entre 0 y 100', function() {
      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      ProgressBar.setPercentage(150);
      expect(mockDomCache.$progressBar.css).toHaveBeenCalledWith('width', '100%');

      mockDomCache.$progressBar.css.mockClear();

      ProgressBar.setPercentage(-10);
      expect(mockDomCache.$progressBar.css).toHaveBeenCalledWith('width', '0%');
    });
  });

  describe('setColor', function() {
    test('setColor debe actualizar el color de fondo de la barra de progreso', function() {
      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.setColor('#0073aa');

      expect(result).toBe(true);
      expect(mockDomCache.$progressBar.css).toHaveBeenCalledWith('background-color', '#0073aa');
    });

    test('setColor debe retornar false si jQuery no está disponible', function() {
      delete global.jQuery;

      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.setColor('#0073aa');

      expect(result).toBe(false);
    });
  });

  describe('reset', function() {
    test('reset debe resetear la barra de progreso a su estado inicial', function() {
      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.reset();

      expect(result).toBe(true);
      expect(mockDomCache.$progressBar.css).toHaveBeenCalledWith('width', '2%');
      expect(mockDomCache.$progressBar.css).toHaveBeenCalledWith('background-color', '#0073aa');
      expect(mockDomCache.$progressBar.css).toHaveBeenCalledWith('transition', 'width 0.3s ease');
    });

    test('reset debe usar el color personalizado si se proporciona', function() {
      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.reset('#22c55e');

      expect(result).toBe(true);
      expect(mockDomCache.$progressBar.css).toHaveBeenCalledWith('background-color', '#22c55e');
    });
  });

  describe('getWidth', function() {
    test('getWidth debe retornar el ancho actual de la barra de progreso', function() {
      mockDomCache.$progressBar.css.mockReturnValue('50%');

      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.getWidth();

      expect(result).toBe('50%');
      expect(mockDomCache.$progressBar.css).toHaveBeenCalledWith('width');
    });

    test('getWidth debe retornar null si jQuery no está disponible', function() {
      delete global.jQuery;

      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.getWidth();

      expect(result).toBeNull();
    });
  });

  describe('isAvailable', function() {
    test('isAvailable debe retornar true si la barra de progreso está disponible', function() {
      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.isAvailable();

      expect(result).toBe(true);
    });

    test('isAvailable debe retornar false si jQuery no está disponible', function() {
      delete global.jQuery;

      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.isAvailable();

      expect(result).toBe(false);
    });

    test('isAvailable debe retornar false si la barra de progreso no está en el DOM', function() {
      global.DOM_CACHE = null;
      global.jQuery = function(selector) {
        return {
          length: 0
        };
      };

      // Limpiar cache
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      const { ProgressBar } = require('../../../assets/js/dashboard/components/ProgressBar.js');

      const result = ProgressBar.isAvailable();

      expect(result).toBe(false);
    });
  });

  describe('Exposición global', function() {
    test('ProgressBar debe estar disponible en window', function() {
      global.window = {};

      // Limpiar cache del módulo
      const progressBarPath = require.resolve('../../../assets/js/dashboard/components/ProgressBar.js');
      delete require.cache[progressBarPath];

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/components/ProgressBar.js');

      // Verificar que está en window
      if (typeof global.window !== 'undefined') {
        expect(global.window.ProgressBar).toBeDefined();
        expect(typeof global.window.ProgressBar).toBe('object');
      }
    });
  });
});

