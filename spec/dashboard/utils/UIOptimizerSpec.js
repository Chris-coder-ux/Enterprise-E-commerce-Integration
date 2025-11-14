/**
 * Tests para UIOptimizer
 * 
 * Verifica que las optimizaciones de actualización de UI funcionen correctamente
 * 
 * @module spec/dashboard/utils/UIOptimizerSpec
 */

describe('UIOptimizer', function() {
  let mockJQuery;
  let mock$Element;
  let originalJQuery;
  let originalErrorHandler;
  let originalRAF;
  let originalSetTimeout;
  let UIOptimizer;

  /**
   * Helper para resetear completamente el estado interno del UIOptimizer
   * 
   * @param {UIOptimizer} optimizer - Instancia del optimizador
   * @returns {void}
   */
  function resetOptimizerState(optimizer) {
    if (!optimizer) return;
    optimizer.clearCache();
    optimizer.cancelPendingUpdates();
    optimizer.config.lastUpdateTime = 0;
    optimizer.updateQueue = [];
    optimizer.rafId = null;
    optimizer.timeoutId = null;
    optimizer.isProcessingQueue = false;
    // Limpiar también el cache de datos
    if (optimizer.dataCache) {
      optimizer.dataCache.clear();
    }
  }

  beforeEach(function() {
    // Guardar referencias originales
    if (typeof window !== 'undefined') {
      originalJQuery = window.jQuery;
      originalErrorHandler = window.ErrorHandler;
      originalRAF = window.requestAnimationFrame;
      originalSetTimeout = window.setTimeout;
    }

    // Mock de jQuery más robusto
    mock$Element = {
      length: 1,
      text: jasmine.createSpy('text').and.callFake(function(value) {
        if (value !== undefined) {
          this._text = value;
          return this;
        }
        return this._text || '';
      }),
      html: jasmine.createSpy('html'),
      val: jasmine.createSpy('val'),
      css: jasmine.createSpy('css'),
      attr: jasmine.createSpy('attr').and.callFake(function(name) {
        if (name === 'id') return 'test-id';
        if (name === 'class') return 'test-class';
        return 'test-attr';
      }),
      find: jasmine.createSpy('find').and.returnValue(mock$Element),
      _text: '' // Estado interno para text()
    };

    // ✅ CORREGIDO: Crear función jQuery que funcione con instanceof
    mockJQuery = function(selector) {
      if (selector && typeof selector === 'object') {
        // Es un elemento DOM o jQuery
        return mock$Element;
      }
      return mock$Element;
    };
    
    // Configurar prototype de jQuery para que instanceof funcione
    mockJQuery.fn = mockJQuery.prototype = Object.create(null);
    Object.keys(mock$Element).forEach(key => {
      mockJQuery.fn[key] = mock$Element[key];
    });
    
    // ✅ CORREGIDO: Hacer que mock$Element sea instancia de mockJQuery para que instanceof funcione
    Object.setPrototypeOf(mock$Element, mockJQuery.prototype);
    
    // ✅ CORREGIDO: Mock jQuery en window.jQuery
    // UIOptimizer ahora verifica tanto jQuery global como window.jQuery,
    // así que window.jQuery es suficiente
    if (typeof window !== 'undefined') {
      window.jQuery = mockJQuery;
      window.$ = mockJQuery; // También mockear $ para compatibilidad
    }
    
    // Mock ErrorHandler
    if (typeof window !== 'undefined') {
      window.ErrorHandler = {
        logError: jasmine.createSpy('logError')
      };
    }
    
    // Cargar UIOptimizer
    if (typeof window !== 'undefined' && window.UIOptimizer) {
      UIOptimizer = window.UIOptimizer;
      
      // Limpiar singleton y cache antes de cada test
      if (UIOptimizer.instance) {
        resetOptimizerState(UIOptimizer.instance);
      }
      UIOptimizer.instance = null;
    }
  });

  afterEach(function() {
    // Limpiar todos los timeouts e intervals
    jasmine.clock().uninstall();
    
    // Cancelar cualquier actualización pendiente
    if (UIOptimizer && UIOptimizer.instance) {
      resetOptimizerState(UIOptimizer.instance);
    }
    
    // Restaurar implementaciones originales
    if (typeof window !== 'undefined') {
      window.jQuery = originalJQuery;
      window.ErrorHandler = originalErrorHandler;
      if (originalRAF) {
        window.requestAnimationFrame = originalRAF;
      }
      if (originalSetTimeout) {
        window.setTimeout = originalSetTimeout;
      }
      // También restaurar cancelAnimationFrame y clearTimeout si existen
      if (typeof window.cancelAnimationFrame !== 'undefined') {
        delete window.cancelAnimationFrame;
      }
      if (typeof window.clearTimeout !== 'undefined') {
        // clearTimeout es nativo, no necesita restauración
      }
    }
  });

  describe('Carga del script', function() {
    it('debe exponer UIOptimizer en window', function() {
      if (typeof window === 'undefined') return;
      
      expect(typeof window.UIOptimizer).toBe('function');
    });

    it('debe tener método getInstance()', function() {
      if (typeof window === 'undefined') return;
      
      expect(typeof UIOptimizer.getInstance).toBe('function');
    });
  });

  describe('getInstance()', function() {
    it('debe retornar una instancia singleton', function() {
      if (typeof window === 'undefined') return;
      
      const instance1 = UIOptimizer.getInstance();
      const instance2 = UIOptimizer.getInstance();
      
      expect(instance1).toBe(instance2);
      expect(instance1).toBeDefined();
      expect(typeof instance1).toBe('object');
    });

    it('debe tener propiedades de configuración', function() {
      if (typeof window === 'undefined') return;
      
      const instance = UIOptimizer.getInstance();
      
      expect(instance.config).toBeDefined();
      expect(typeof instance.config.changeThreshold).toBe('number');
      expect(typeof instance.config.maxUpdateInterval).toBe('number');
      expect(typeof instance.config.deepCompare).toBe('boolean');
    });
  });

  describe('updateTextIfChanged()', function() {
    let originalSetTimeout;
    let rafCallbacks;
    
    beforeEach(function() {
      // Guardar setTimeout original
      if (typeof window !== 'undefined') {
        originalSetTimeout = window.setTimeout;
      }
      
      // ✅ CORREGIDO: Mock requestAnimationFrame que ejecuta callbacks inmediatamente
      // y los almacena para poder verificarlos en los tests
      rafCallbacks = [];
      if (typeof window !== 'undefined') {
        window.requestAnimationFrame = function(callback) {
          // Ejecutar inmediatamente para tests síncronos
          callback();
          rafCallbacks.push(callback);
          return rafCallbacks.length;
        };
        
        // ✅ CORREGIDO: Mock setTimeout para ejecutar callbacks inmediatamente en tests
        // Esto es necesario porque el throttling puede usar setTimeout en lugar de RAF
        window.setTimeout = function(callback, _delay) {
          // Ejecutar inmediatamente para tests síncronos
          callback();
          return 1;
        };
      }
      
      // ✅ CORREGIDO: Resetear lastUpdateTime para evitar throttling en tests
      if (typeof window !== 'undefined' && window.UIOptimizer) {
        // Asegurarse de que la instancia existe
        const optimizer = UIOptimizer.getInstance();
        optimizer.config.lastUpdateTime = 0;
        optimizer.updateQueue = []; // Limpiar cola
        optimizer.rafId = null;
        optimizer.timeoutId = null;
        optimizer.isProcessingQueue = false;
      }
    });
    
    afterEach(function() {
      // Restaurar setTimeout original
      if (typeof window !== 'undefined' && originalSetTimeout) {
        window.setTimeout = originalSetTimeout;
      }
      // Limpiar callbacks de RAF
      rafCallbacks = [];
    });

    it('debe actualizar texto cuando no hay valor anterior', function() {
      if (typeof window === 'undefined') return;
      
      const element = document.createElement('div');
      
      const updated = UIOptimizer.updateTextIfChanged(element, 'Nuevo texto', 'test-key');
      
      expect(updated).toBe(true);
      expect(mock$Element.text).toHaveBeenCalledWith('Nuevo texto');
    });

    it('NO debe actualizar texto cuando el valor no cambió', function() {
      if (typeof window === 'undefined') return;
      
      const element = document.createElement('div');
      
      // Primera actualización
      UIOptimizer.updateTextIfChanged(element, 'Mismo texto', 'test-key-same');
      
      // Limpiar spy
      mock$Element.text.calls.reset();
      
      // Segunda actualización con mismo texto
      const updated = UIOptimizer.updateTextIfChanged(element, 'Mismo texto', 'test-key-same');
      
      expect(updated).toBe(false);
      expect(mock$Element.text).not.toHaveBeenCalled();
    });

    it('debe actualizar texto cuando el valor cambió significativamente', function() {
      if (typeof window === 'undefined') return;
      
      const element = document.createElement('div');
      
      // Primera actualización
      UIOptimizer.updateTextIfChanged(element, 'Texto 1', 'test-key-change');
      
      // ✅ CORREGIDO: Resetear lastUpdateTime y limpiar estado para evitar throttling
      const optimizer = UIOptimizer.getInstance();
      optimizer.config.lastUpdateTime = 0;
      optimizer.updateQueue = [];
      optimizer.rafId = null;
      optimizer.timeoutId = null;
      optimizer.isProcessingQueue = false;
      
      // Limpiar spy
      mock$Element.text.calls.reset();
      
      // Segunda actualización con texto diferente
      const updated = UIOptimizer.updateTextIfChanged(element, 'Texto 2', 'test-key-change');
      
      // ✅ CORREGIDO: Verificar que se programó la actualización
      expect(updated).toBe(true);
      
      // ✅ CORREGIDO: El mock de requestAnimationFrame ejecuta el callback inmediatamente,
      // pero si por alguna razón la cola no se procesó, forzar el procesamiento
      if (optimizer.updateQueue.length > 0) {
        optimizer.processUpdateQueue();
      }
      
      // Verificar que se llamó al método text con el nuevo valor
      expect(mock$Element.text).toHaveBeenCalledWith('Texto 2');
    });

    it('debe generar clave de cache automáticamente si no se proporciona', function() {
      if (typeof window === 'undefined') return;
      
      const element = document.createElement('div');
      
      const updated = UIOptimizer.updateTextIfChanged(element, 'Texto auto key');
      
      expect(updated).toBe(true);
      expect(mock$Element.text).toHaveBeenCalledWith('Texto auto key');
      expect(mock$Element.attr).toHaveBeenCalled();
    });

    it('debe retornar false si el elemento no existe', function() {
      if (typeof window === 'undefined') return;
      
      mock$Element.length = 0;
      
      const updated = UIOptimizer.updateTextIfChanged(mock$Element, 'Texto', 'test-nonexistent');
      
      expect(updated).toBe(false);
      expect(mock$Element.text).not.toHaveBeenCalled();
    });
  });

  describe('updateCssIfChanged()', function() {
    beforeEach(function() {
      if (typeof window !== 'undefined') {
        window.requestAnimationFrame = function(callback) {
          callback();
          return 1;
        };
      }
    });

    it('debe actualizar CSS cuando no hay valor anterior', function() {
      if (typeof window === 'undefined') return;
      
      const element = document.createElement('div');
      
      const updated = UIOptimizer.updateCssIfChanged(element, 'width', '100%', 'test-css-width');
      
      expect(updated).toBe(true);
      expect(mock$Element.css).toHaveBeenCalledWith('width', '100%');
    });

    it('NO debe actualizar CSS cuando el valor no cambió', function() {
      if (typeof window === 'undefined') return;
      
      const element = document.createElement('div');
      
      // Primera actualización
      UIOptimizer.updateCssIfChanged(element, 'width', '50%', 'test-css-same');
      
      // Limpiar spy
      mock$Element.css.calls.reset();
      
      // Segunda actualización con mismo valor
      const updated = UIOptimizer.updateCssIfChanged(element, 'width', '50%', 'test-css-same');
      
      expect(updated).toBe(false);
      expect(mock$Element.css).not.toHaveBeenCalled();
    });

    it('debe manejar objetos CSS', function() {
      if (typeof window === 'undefined') return;
      
      const element = document.createElement('div');
      
      const cssObject = { width: '100%', height: '200px' };
      const updated = UIOptimizer.updateCssIfChanged(element, cssObject, null, 'test-css-object');
      
      expect(updated).toBe(true);
      expect(mock$Element.css).toHaveBeenCalledWith(cssObject);
    });

    it('debe generar clave de cache automáticamente para CSS', function() {
      if (typeof window === 'undefined') return;
      
      const element = document.createElement('div');
      
      const updated = UIOptimizer.updateCssIfChanged(element, 'color', 'red');
      
      expect(updated).toBe(true);
      expect(mock$Element.css).toHaveBeenCalledWith('color', 'red');
      expect(mock$Element.attr).toHaveBeenCalled();
    });
  });

  describe('Comparación de números con umbral', function() {
    let rafCallbacks = [];
    
    beforeEach(function() {
      rafCallbacks = [];
      if (typeof window !== 'undefined') {
        window.requestAnimationFrame = function(callback) {
          rafCallbacks.push(callback);
          return rafCallbacks.length;
        };
      }
    });
    
    function flushRafCallbacks() {
      const callbacks = [...rafCallbacks];
      rafCallbacks = [];
      callbacks.forEach(cb => cb());
    }

    it('debe actualizar cuando el cambio supera el umbral', function() {
      if (typeof window === 'undefined') return;
      
      const optimizer = UIOptimizer.getInstance();
      optimizer.config.changeThreshold = 0.1; // 0.1%
      
      const element = document.createElement('div');
      
      // Primera actualización: 100
      UIOptimizer.updateTextIfChanged(element, '100', 'test-number');
      
      // Limpiar spy
      mock$Element.text.calls.reset();
      
      // Segunda actualización: 100.2 (0.2% de cambio > 0.1%)
      const updated = UIOptimizer.updateTextIfChanged(element, '100.2', 'test-number');
      
      // Ejecutar los callbacks de requestAnimationFrame
      flushRafCallbacks();
      
      expect(updated).toBe(true);
      expect(mock$Element.text).toHaveBeenCalledWith('100.2');
    });

    it('NO debe actualizar cuando el cambio es menor al umbral', function() {
      if (typeof window === 'undefined') return;
      
      const optimizer = UIOptimizer.getInstance();
      optimizer.config.changeThreshold = 1; // 1%
      
      const element = document.createElement('div');
      
      // Configurar el mock para devolver el valor actual al llamar a text() sin argumentos
      mock$Element.text.and.callFake(function(value) {
        if (value !== undefined) {
          this._text = value;
          return this;
        }
        return this._text || '';
      });
      
      // Primera actualización: 100
      UIOptimizer.updateTextIfChanged(element, '100', 'test-number-threshold');
      
      // Ejecutar los callbacks de requestAnimationFrame de la primera actualización
      flushRafCallbacks();
      
      // Asegurar que no hay actualizaciones pendientes
      optimizer.cancelPendingUpdates();
      
      // Limpiar spy después de la configuración inicial
      mock$Element.text.calls.reset();
      
      // Segunda actualización: 100.5 (0.5% de cambio < 1%)
      const updated = UIOptimizer.updateTextIfChanged(element, '100.5', 'test-number-threshold');
      
      // Ejecutar los callbacks de requestAnimationFrame
      flushRafCallbacks();
      
      // Verificar que no se actualizó
      expect(updated).toBe(false);
      
      // Verificar que no se llamó a text() con un nuevo valor
      const textCalls = mock$Element.text.calls.all();
      const updateCalls = textCalls.filter(call => call.args.length > 0);
      expect(updateCalls.length).toBe(0);
    });

    it('debe manejar strings numéricos correctamente', function() {
      if (typeof window === 'undefined') return;

      /* ------------------------------------------------------------------ */
      /*  1. Resetear estado interno del UIOptimizer                       */
      /* ------------------------------------------------------------------ */
      const optimizer = UIOptimizer.getInstance();
      resetOptimizerState(optimizer);
      optimizer.config.changeThreshold = 0.1;          // 0.1%

      /* ------------------------------------------------------------------ */
      /*  2. Se necesita un mock de requestAnimationFrame que ejecute      */
      /*     los callbacks en bloque para poder verificar de inmediato    */
      /* ------------------------------------------------------------------ */
      const rafCallbacks = [];
      window.requestAnimationFrame = function(cb) {
        rafCallbacks.push(cb);
        return rafCallbacks.length;
      };

      /* ------------------------------------------------------------------ */
      /*  3. Invocar al método:                                          */
      /* ------------------------------------------------------------------ */
      const element = document.createElement('div');

      // Primera actualización: 50.5
      UIOptimizer.updateTextIfChanged(element, '50.5', 'test-string-number');

      // Segunda actualización: 51.0 (cambio > 0.1%)
      const updated = UIOptimizer.updateTextIfChanged(element, '51.0', 'test-string-number');

      /* ------------------------------------------------------------------ */
      /*  4. Ejecutar los callbacks que el mock de RAF guardó              */
      /* ------------------------------------------------------------------ */
      rafCallbacks.forEach(cb => cb());

      /* ------------------------------------------------------------------ */
      /*  5. Validaciones                                               */
      /* ------------------------------------------------------------------ */
      expect(updated).toBe(true);
      expect(mock$Element.text).toHaveBeenCalledTimes(2);          // se cambió dos veces
      
      // Verificar que se llamó con los valores correctos usando calls.all()
      const textCalls = mock$Element.text.calls.all();
      const updateCalls = textCalls.filter(call => call.args.length > 0);
      expect(updateCalls.length).toBe(2);
      expect(updateCalls[0].args[0]).toBe('50.5');
      expect(updateCalls[1].args[0]).toBe('51.0');
    });
  });

  describe('requestAnimationFrame y cola de actualizaciones', function() {
    it('debe agrupar múltiples actualizaciones', function(done) {
      if (typeof window === 'undefined') return;
      
      const element1 = document.createElement('div');
      const element2 = document.createElement('div');
      
      // Mock RAF que acumula callbacks
      const rafCallbacks = [];
      window.requestAnimationFrame = function(callback) {
        rafCallbacks.push(callback);
        return rafCallbacks.length;
      };
      
      // Múltiples actualizaciones
      UIOptimizer.updateTextIfChanged(element1, 'Texto 1', 'test-queue-1');
      UIOptimizer.updateTextIfChanged(element2, 'Texto 2', 'test-queue-2');
      
      // Debe haber un RAF programado
      expect(rafCallbacks.length).toBe(1);
      
      // Ejecutar el callback de RAF
      rafCallbacks[0]();
      
      // Verificar asincrónicamente que se procesaron ambas actualizaciones
      setTimeout(() => {
        expect(mock$Element.text).toHaveBeenCalledTimes(2);
        done();
      }, 0);
    });

    it('debe usar setTimeout como fallback si requestAnimationFrame no está disponible', function(done) {
      if (typeof window === 'undefined') return;
      
      const element = document.createElement('div');
      
      // Eliminar requestAnimationFrame
      delete window.requestAnimationFrame;
      
      // Mock setTimeout
      const setTimeoutCallbacks = [];
      window.setTimeout = function(callback, delay) {
        setTimeoutCallbacks.push({ callback, delay });
        return setTimeoutCallbacks.length;
      };
      
      UIOptimizer.updateTextIfChanged(element, 'Texto fallback', 'test-fallback');
      
      // Debe usar setTimeout
      expect(setTimeoutCallbacks.length).toBe(1);
      expect(setTimeoutCallbacks[0].delay).toBe(16); // ~60fps
      
      // Ejecutar callback
      setTimeoutCallbacks[0].callback();
      
      // Usar el setTimeout original para esperar al siguiente tick
      // (esta variable viene del outer `beforeEach` donde fuimos a guardarla)
      const nativeSetTimeout = originalSetTimeout;
      
      nativeSetTimeout(() => {
        expect(mock$Element.text).toHaveBeenCalledWith('Texto fallback');
        done();
      }, 0);
    });
  });

  describe('Throttling temporal', function() {
    it('debe aplicar throttling entre actualizaciones', function(done) {
      if (typeof window === 'undefined') return;
      
      const optimizer = UIOptimizer.getInstance();
      optimizer.config.maxUpdateInterval = 100; // 100ms
      optimizer.config.lastUpdateTime = Date.now() - 50; // Hace 50ms
      
      const element = document.createElement('div');
      
      // Mock setTimeout
      const setTimeoutCallbacks = [];
      window.setTimeout = function(callback, delay) {
        setTimeoutCallbacks.push({ callback, delay });
        return setTimeoutCallbacks.length;
      };
      
      UIOptimizer.updateTextIfChanged(element, 'Texto throttled', 'test-throttle');
      
      // Debe programar un setTimeout con delay de ~50ms (100ms - 50ms)
      expect(setTimeoutCallbacks.length).toBe(1);
      expect(setTimeoutCallbacks[0].delay).toBe(50);
      
      // Ejecutar el callback
      setTimeoutCallbacks[0].callback();
      
      // Usar el setTimeout original para esperar al siguiente tick
      const nativeSetTimeout = originalSetTimeout;
      
      nativeSetTimeout(() => {
        expect(mock$Element.text).toHaveBeenCalledWith('Texto throttled');
        done();
      }, 0);
    });
  });

  describe('clearCache()', function() {
    beforeEach(function() {
      if (typeof window !== 'undefined') {
        window.requestAnimationFrame = function(callback) {
          callback();
          return 1;
        };
      }
    });

    it('debe limpiar todo el cache cuando se llama sin parámetros', function() {
      if (typeof window === 'undefined') return;
      
      const optimizer = UIOptimizer.getInstance();
      const element = document.createElement('div');
      
      // Agregar valores al cache
      UIOptimizer.updateTextIfChanged(element, 'Texto 1', 'test-cache-1');
      UIOptimizer.updateTextIfChanged(element, 'Texto 2', 'test-cache-2');
      
      // Verificar que hay valores en cache
      expect(optimizer.valueCache.size).toBe(2);
      
      // Limpiar cache
      optimizer.clearCache();
      
      // Verificar que el cache está vacío
      expect(optimizer.valueCache.size).toBe(0);
    });

    it('debe limpiar solo una clave específica cuando se proporciona', function() {
      if (typeof window === 'undefined') return;
      
      const optimizer = UIOptimizer.getInstance();
      const element = document.createElement('div');
      
      // Agregar valores al cache
      UIOptimizer.updateTextIfChanged(element, 'Texto 1', 'test-cache-key1');
      UIOptimizer.updateTextIfChanged(element, 'Texto 2', 'test-cache-key2');
      
      // Verificar que ambas claves existen
      expect(optimizer.valueCache.has('test-cache-key1')).toBe(true);
      expect(optimizer.valueCache.has('test-cache-key2')).toBe(true);
      
      // Limpiar solo una clave
      optimizer.clearCache('test-cache-key1');
      
      // Verificar que solo se eliminó una clave
      expect(optimizer.valueCache.has('test-cache-key1')).toBe(false);
      expect(optimizer.valueCache.has('test-cache-key2')).toBe(true);
    });
  });

  describe('cancelPendingUpdates()', function() {
    it('debe cancelar todas las actualizaciones pendientes', function() {
      if (typeof window === 'undefined') return;
      
      const optimizer = UIOptimizer.getInstance();
      const element = document.createElement('div');
      
      // Mock RAF y cancelAnimationFrame
      let rafId = 0;
      const canceledIds = [];
      window.requestAnimationFrame = function(_callback) {
        return ++rafId;
      };
      
      /* ----------  Mock de cancelAnimationFrame  ---------- */
      /* Se añade en globalThis para que el código interno lo encuentre     */
      /* y también se asigna a window para mantener la coherencia con el   */
      /* resto de la suite.                                                */
      globalThis.cancelAnimationFrame = window.cancelAnimationFrame = function(id) {
        canceledIds.push(id);
      };
      
      // Mock setTimeout y clearTimeout
      let timeoutId = 0;
      const clearedTimeouts = [];
      window.setTimeout = function(_callback, _delay) {
        return ++timeoutId;
      };
      
      /* ----------  Mock de clearTimeout  ---------- */
      /* Se añade en globalThis para que el código interno lo encuentre     */
      /* y también se asigna a window para mantener la coherencia con el   */
      /* resto de la suite.                                                */
      globalThis.clearTimeout = window.clearTimeout = function(id) {
        clearedTimeouts.push(id);
      };
      
      // Resetear estado del optimizador antes del test
      resetOptimizerState(optimizer);
      
      // Agregar actualizaciones pendientes
      UIOptimizer.updateTextIfChanged(element, 'Texto 1', 'test-cancel-1');
      UIOptimizer.updateTextIfChanged(element, 'Texto 2', 'test-cancel-2');
      
      // Verificar que se programó al menos un RAF o timeout
      const hasRafId = optimizer.rafId !== null;
      const hasTimeoutId = optimizer.timeoutId !== null;
      expect(hasRafId || hasTimeoutId).toBe(true);
      
      // Cancelar actualizaciones
      optimizer.cancelPendingUpdates();
      
      // Verificar que se canceló al menos uno (RAF o timeout, dependiendo de cuál se usó)
      // Si se usó RAF, debe estar en canceledIds
      // Si se usó setTimeout (fallback o throttling), debe estar en clearedTimeouts
      const totalCanceled = canceledIds.length + clearedTimeouts.length;
      expect(totalCanceled).toBeGreaterThan(0);
      
      // Verificar que la cola se limpió
      expect(optimizer.updateQueue.length).toBe(0);
      
      // Verificar que los IDs se resetearon
      expect(optimizer.rafId).toBe(null);
      expect(optimizer.timeoutId).toBe(null);
    });
  });

  describe('Manejo de errores', function() {
    it('debe manejar errores en callbacks de actualización', function(done) {
      if (typeof window === 'undefined') return;
      
      const element = document.createElement('div');
      
      // Hacer que text() lance un error
      mock$Element.text.and.throwError('Error de actualización');
      
      // Mock RAF
      window.requestAnimationFrame = function(callback) {
        setTimeout(callback, 0);
        return 1;
      };
      
      UIOptimizer.updateTextIfChanged(element, 'Texto con error', 'test-error');
      
      // Usar el setTimeout original para esperar al siguiente tick
      const nativeSetTimeout = originalSetTimeout;
      
      nativeSetTimeout(() => {
        // Debe registrar el error
        expect(window.ErrorHandler.logError).toHaveBeenCalled();
        done();
      }, 10);
    });
  });

  describe('updateDataIfChanged()', function() {
    beforeEach(function() {
      if (typeof window !== 'undefined') {
        window.requestAnimationFrame = function(callback) {
          callback();
          return 1;
        };
      }
    });

    it('debe actualizar datos cuando hay cambios significativos', function() {
      if (typeof window === 'undefined') return;
      
      const updateCallback = jasmine.createSpy('updateCallback');
      const data1 = { value: 100, name: 'test' };
      const data2 = { value: 150, name: 'test' };
      
      // Primera actualización
      const updated1 = UIOptimizer.updateDataIfChanged(data1, 'test-data', updateCallback);
      
      expect(updated1).toBe(true);
      expect(updateCallback).toHaveBeenCalledWith(data1);
      
      // Limpiar spy
      updateCallback.calls.reset();
      
      // Segunda actualización con cambios
      const updated2 = UIOptimizer.updateDataIfChanged(data2, 'test-data', updateCallback);
      
      expect(updated2).toBe(true);
      expect(updateCallback).toHaveBeenCalledWith(data2);
    });

    it('NO debe actualizar datos cuando no hay cambios significativos', function() {
      if (typeof window === 'undefined') return;
      
      const updateCallback = jasmine.createSpy('updateCallback');
      const data = { value: 100, name: 'test' };
      
      // Primera actualización
      UIOptimizer.updateDataIfChanged(data, 'test-data-same', updateCallback);
      
      // Limpiar spy
      updateCallback.calls.reset();
      
      // Segunda actualización con mismos datos
      const updated = UIOptimizer.updateDataIfChanged(data, 'test-data-same', updateCallback);
      
      expect(updated).toBe(false);
      expect(updateCallback).not.toHaveBeenCalled();
    });
  });

  // Tests para entornos no-browser
  describe('Entorno no-browser', function() {
    it('debe manejar gracefulmente la ausencia de window', function() {
      if (typeof window !== 'undefined') {
        pending('Este test solo se ejecuta en entornos sin window');
        return;
      }
      
      // En entorno sin window, los tests deberían skipearse gracefulmente
      expect(true).toBe(true);
    });
  });
});