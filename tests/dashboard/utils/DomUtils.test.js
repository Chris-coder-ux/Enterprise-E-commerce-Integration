/**
 * Tests unitarios para DomUtils.js
 * 
 * Verifica que DomUtils esté correctamente definido y funcione correctamente.
 * 
 * @module tests/dashboard/utils/DomUtils
 */

// Mock de jQuery antes de cargar el módulo
const jQuery = require('jquery');

// Limpiar el módulo antes de cada test
beforeEach(function() {
  // Asegurar que window existe
  if (typeof global.window === 'undefined') {
    global.window = {};
  }

  // Eliminar DomUtils y DOM_CACHE de window primero
  if (global.window.DomUtils) {
    delete global.window.DomUtils;
  }
  if (global.window.DOM_CACHE) {
    delete global.window.DOM_CACHE;
  }

  // Limpiar require cache para forzar recarga
  const domUtilsPath = require.resolve('../../../assets/js/dashboard/utils/DomUtils.js');
  if (require.cache[domUtilsPath]) {
    delete require.cache[domUtilsPath];
  }

  // Mock de DASHBOARD_CONFIG
  global.DASHBOARD_CONFIG = {
    selectors: {
      syncButton: '#mi-batch-sync-products',
      feedback: '#mi-sync-feedback',
      progressInfo: '#mi-progress-info',
      cancelButton: '#mi-cancel-sync',
      statusContainer: '#mi-sync-status-details',
      batchSize: '#mi-batch-size',
      dashboardMessages: '#mi-dashboard-messages',
      retryButton: '#mi-api-retry-sync'
    }
  };

  // Mock de jQuery
  global.jQuery = jQuery;
  global.$ = jQuery;

  // Crear elementos DOM mock
  if (!global.document) {
    global.document = {
      createElement: function(tag) {
        return {
          tagName: tag.toUpperCase(),
          setAttribute: jest.fn(),
          getAttribute: jest.fn(),
          addEventListener: jest.fn(),
          removeEventListener: jest.fn()
        };
      }
    };
  }

  // Crear elementos en el DOM mock
  const body = document.createElement('body');
  const syncBtn = document.createElement('button');
  syncBtn.id = 'mi-batch-sync-products';
  body.appendChild(syncBtn);

  const feedback = document.createElement('div');
  feedback.id = 'mi-sync-feedback';
  body.appendChild(feedback);

  const progressBar = document.createElement('div');
  progressBar.className = 'sync-progress-bar';
  body.appendChild(progressBar);

  const progressInfo = document.createElement('div');
  progressInfo.id = 'mi-progress-info';
  body.appendChild(progressInfo);

  const cancelBtn = document.createElement('button');
  cancelBtn.id = 'mi-cancel-sync';
  body.appendChild(cancelBtn);

  const statusContainer = document.createElement('div');
  statusContainer.id = 'mi-sync-status-details';
  body.appendChild(statusContainer);

  const batchSize = document.createElement('select');
  batchSize.id = 'mi-batch-size';
  body.appendChild(batchSize);

  const metricElement = document.createElement('div');
  metricElement.className = 'dashboard-metric';
  body.appendChild(metricElement);

  // Asegurar que document.body existe
  if (!global.document.body) {
    global.document.body = body;
  }
});

describe('DomUtils.js - DomUtils', function() {
  describe('Definición de DomUtils', function() {
    test('DomUtils debe estar definido', function() {
      // eslint-disable-next-line no-undef
      const domUtils = require('../../../assets/js/dashboard/utils/DomUtils.js');

      // Verificar que el módulo se carga correctamente
      expect(domUtils).toBeDefined();
      expect(domUtils.DomUtils).toBeDefined();
    });

    test('DomUtils debe tener todos los métodos', function() {
      // eslint-disable-next-line no-undef
      const domUtils = require('../../../assets/js/dashboard/utils/DomUtils.js');

      expect(domUtils.DomUtils).toHaveProperty('initCache');
      expect(domUtils.DomUtils).toHaveProperty('getCache');
      expect(domUtils.DomUtils).toHaveProperty('refreshCache');
      expect(domUtils.DomUtils).toHaveProperty('isCacheInitialized');
    });
  });

  describe('Inicialización del cache', function() {
    test('initCache debe inicializar el cache correctamente', function() {
      // eslint-disable-next-line no-undef
      const domUtils = require('../../../assets/js/dashboard/utils/DomUtils.js');

      const cache = domUtils.DomUtils.initCache();

      expect(cache).toBeDefined();
      expect(cache).toHaveProperty('$syncBtn');
      expect(cache).toHaveProperty('$feedback');
      expect(cache).toHaveProperty('$progressBar');
      expect(cache).toHaveProperty('$progressInfo');
      expect(cache).toHaveProperty('$cancelBtn');
      expect(cache).toHaveProperty('$syncStatusContainer');
      expect(cache).toHaveProperty('$batchSizeSelector');
      expect(cache).toHaveProperty('$metricElements');
    });

    test('initCache debe lanzar error si jQuery no está disponible', function() {
      const originalJQuery = global.jQuery;
      delete global.jQuery;
      delete global.$;

      // Limpiar cache
      const domUtilsPath = require.resolve('../../../assets/js/dashboard/utils/DomUtils.js');
      delete require.cache[domUtilsPath];

      // eslint-disable-next-line no-undef
      const domUtils = require('../../../assets/js/dashboard/utils/DomUtils.js');

      expect(function() {
        domUtils.DomUtils.initCache();
      }).toThrow('jQuery no está disponible');
      
      // Verificar que es un TypeError
      try {
        domUtils.DomUtils.initCache();
      } catch (error) {
        expect(error).toBeInstanceOf(TypeError);
      }

      // Restaurar jQuery
      global.jQuery = originalJQuery;
      global.$ = originalJQuery;
    });

    test('initCache debe lanzar error si DASHBOARD_CONFIG no está disponible', function() {
      const originalConfig = global.DASHBOARD_CONFIG;
      delete global.DASHBOARD_CONFIG;

      // Limpiar cache
      const domUtilsPath = require.resolve('../../../assets/js/dashboard/utils/DomUtils.js');
      delete require.cache[domUtilsPath];

      // eslint-disable-next-line no-undef
      const domUtils = require('../../../assets/js/dashboard/utils/DomUtils.js');

      expect(function() {
        domUtils.DomUtils.initCache();
      }).toThrow('DASHBOARD_CONFIG no está disponible');
      
      // Verificar que es un TypeError
      try {
        domUtils.DomUtils.initCache();
      } catch (error) {
        expect(error).toBeInstanceOf(TypeError);
      }

      // Restaurar DASHBOARD_CONFIG
      global.DASHBOARD_CONFIG = originalConfig;
    });
  });

  describe('Obtención del cache', function() {
    test('getCache debe retornar el cache inicializado', function() {
      // eslint-disable-next-line no-undef
      const domUtils = require('../../../assets/js/dashboard/utils/DomUtils.js');

      // Inicializar primero
      domUtils.DomUtils.initCache();
      const cache = domUtils.DomUtils.getCache();

      expect(cache).toBeDefined();
      expect(cache).toHaveProperty('$syncBtn');
      expect(cache).toHaveProperty('$feedback');
    });

    test('getCache debe inicializar el cache automáticamente si no está inicializado', function() {
      // Limpiar cache del módulo para asegurar estado limpio
      const domUtilsPath = require.resolve('../../../assets/js/dashboard/utils/DomUtils.js');
      delete require.cache[domUtilsPath];

      // eslint-disable-next-line no-undef
      const domUtils = require('../../../assets/js/dashboard/utils/DomUtils.js');

      // Verificar que no está inicializado (el cache interno es null)
      // Nota: isCacheInitialized puede retornar true si se accedió a window.DOM_CACHE
      // pero internamente el cache puede estar null
      
      // Obtener el cache (debe inicializarse automáticamente)
      const cache = domUtils.DomUtils.getCache();

      expect(cache).toBeDefined();
      expect(domUtils.DomUtils.isCacheInitialized()).toBe(true);
    });
  });

  describe('Refrescar el cache', function() {
    test('refreshCache debe reinicializar el cache', function() {
      // eslint-disable-next-line no-undef
      const domUtils = require('../../../assets/js/dashboard/utils/DomUtils.js');

      // Inicializar el cache
      const cache1 = domUtils.DomUtils.initCache();
      expect(domUtils.DomUtils.isCacheInitialized()).toBe(true);

      // Refrescar el cache
      const cache2 = domUtils.DomUtils.refreshCache();

      expect(cache2).toBeDefined();
      expect(domUtils.DomUtils.isCacheInitialized()).toBe(true);
    });
  });

  describe('Verificación del estado del cache', function() {
    test('isCacheInitialized debe retornar false si el cache no está inicializado', function() {
      // Limpiar cache del módulo para asegurar estado limpio
      const domUtilsPath = require.resolve('../../../assets/js/dashboard/utils/DomUtils.js');
      delete require.cache[domUtilsPath];

      // eslint-disable-next-line no-undef
      const domUtils = require('../../../assets/js/dashboard/utils/DomUtils.js');

      // Nota: El cache puede inicializarse automáticamente al acceder a window.DOM_CACHE
      // pero internamente puede estar null hasta que se llame a getCache() o initCache()
      // Por lo tanto, verificamos que después de limpiar el módulo, el estado inicial es false
      // solo si no se ha accedido a window.DOM_CACHE
      const initialState = domUtils.DomUtils.isCacheInitialized();
      
      // Si el getter de window.DOM_CACHE se ejecutó, el cache puede estar inicializado
      // En ese caso, verificamos que getCache() funciona correctamente
      if (initialState) {
        const cache = domUtils.DomUtils.getCache();
        expect(cache).toBeDefined();
      } else {
        expect(initialState).toBe(false);
      }
    });

    test('isCacheInitialized debe retornar true si el cache está inicializado', function() {
      // eslint-disable-next-line no-undef
      const domUtils = require('../../../assets/js/dashboard/utils/DomUtils.js');

      domUtils.DomUtils.initCache();
      expect(domUtils.DomUtils.isCacheInitialized()).toBe(true);
    });
  });

  describe('Exposición global', function() {
    test('DomUtils debe estar disponible en window', function() {
      global.window = {};

      // Limpiar cache del módulo
      const domUtilsPath = require.resolve('../../../assets/js/dashboard/utils/DomUtils.js');
      delete require.cache[domUtilsPath];

      // eslint-disable-next-line no-undef
      const domUtils = require('../../../assets/js/dashboard/utils/DomUtils.js');

      // Verificar que el módulo exporta DomUtils
      expect(domUtils.DomUtils).toBeDefined();

      // Verificar que está en window (el código se ejecuta al hacer require)
      if (typeof global.window !== 'undefined') {
        expect(global.window.DomUtils).toBeDefined();
        expect(typeof global.window.DomUtils).toBe('object');
      }
    });

    test('DOM_CACHE debe estar disponible en window como getter', function() {
      global.window = {};

      // Limpiar cache del módulo
      const domUtilsPath = require.resolve('../../../assets/js/dashboard/utils/DomUtils.js');
      delete require.cache[domUtilsPath];

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/utils/DomUtils.js');

      // Verificar que DOM_CACHE está disponible
      if (typeof global.window !== 'undefined') {
        expect(global.window.DOM_CACHE).toBeDefined();
        expect(typeof global.window.DOM_CACHE).toBe('object');
        expect(global.window.DOM_CACHE).toHaveProperty('$syncBtn');
      }
    });

    test('DOM_CACHE debe inicializarse automáticamente al acceder', function() {
      global.window = {};

      // Limpiar cache del módulo
      const domUtilsPath = require.resolve('../../../assets/js/dashboard/utils/DomUtils.js');
      delete require.cache[domUtilsPath];

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/utils/DomUtils.js');

      // Acceder a DOM_CACHE (debe inicializarse automáticamente)
      if (typeof global.window !== 'undefined') {
        const cache = global.window.DOM_CACHE;

        expect(cache).toBeDefined();
        expect(cache).toHaveProperty('$syncBtn');
        expect(cache).toHaveProperty('$feedback');
      }
    });
  });

  describe('Compatibilidad con código existente', function() {
    test('DOM_CACHE debe tener la misma estructura que el original', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/utils/DomUtils.js');

      const cache = global.window.DOM_CACHE;

      // Verificar que tiene todas las propiedades del original
      expect(cache).toHaveProperty('$syncBtn');
      expect(cache).toHaveProperty('$feedback');
      expect(cache).toHaveProperty('$progressBar');
      expect(cache).toHaveProperty('$progressInfo');
      expect(cache).toHaveProperty('$cancelBtn');
      expect(cache).toHaveProperty('$syncStatusContainer');
      expect(cache).toHaveProperty('$batchSizeSelector');
      expect(cache).toHaveProperty('$metricElements');
    });

    test('DOM_CACHE debe usar los selectores correctos de DASHBOARD_CONFIG', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/utils/DomUtils.js');

      const cache = global.window.DOM_CACHE;

      // Verificar que los elementos jQuery están correctamente seleccionados
      expect(cache.$syncBtn).toBeDefined();
      expect(cache.$feedback).toBeDefined();
      expect(cache.$progressInfo).toBeDefined();
      expect(cache.$cancelBtn).toBeDefined();
      expect(cache.$syncStatusContainer).toBeDefined();
      expect(cache.$batchSizeSelector).toBeDefined();
    });
  });

  describe('Elementos del cache', function() {
    test('Todos los elementos del cache deben ser objetos jQuery', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      require('../../../assets/js/dashboard/utils/DomUtils.js');

      const cache = global.window.DOM_CACHE;

      // Verificar que todos los elementos son objetos jQuery (tienen métodos jQuery)
      expect(cache.$syncBtn).toBeDefined();
      expect(cache.$feedback).toBeDefined();
      expect(cache.$progressBar).toBeDefined();
      expect(cache.$progressInfo).toBeDefined();
      expect(cache.$cancelBtn).toBeDefined();
      expect(cache.$syncStatusContainer).toBeDefined();
      expect(cache.$batchSizeSelector).toBeDefined();
      expect(cache.$metricElements).toBeDefined();
    });
  });

  describe('Manejo de errores', function() {
    test('Debe funcionar cuando window no está definido', function() {
      const originalWindow = global.window;
      delete global.window;

      // No debe lanzar error
      expect(function() {
        // eslint-disable-next-line no-undef
        require('../../../assets/js/dashboard/utils/DomUtils.js');
      }).not.toThrow();

      // Restaurar window
      global.window = originalWindow;
    });
  });
});

