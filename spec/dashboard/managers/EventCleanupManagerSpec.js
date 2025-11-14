/**
 * Tests para EventCleanupManager
 * 
 * Verifica que la gestión de limpieza de event listeners funcione correctamente
 * 
 * @module spec/dashboard/managers/EventCleanupManagerSpec
 */

describe('EventCleanupManager', function() {
  let mockJQuery;
  let mockDocument;
  let mockElement;
  let mock$Element;
  let mock$Document;
  let mockPollingManager;

  beforeEach(function() {
    // Mock de jQuery
    mock$Element = {
      length: 1,
      on: jasmine.createSpy('on'),
      off: jasmine.createSpy('off'),
      text: jasmine.createSpy('text'),
      html: jasmine.createSpy('html'),
      val: jasmine.createSpy('val'),
      css: jasmine.createSpy('css')
    };

    // Crear una instancia única de $doc que se reutilizará
    mock$Document = {
      on: jasmine.createSpy('documentOn'),
      off: jasmine.createSpy('documentOff')
    };
    
    mockJQuery = jasmine.createSpy('jQuery').and.callFake(function(selector) {
      // Reconocer tanto el document real como el mock
      if (selector === document || selector === mockDocument || (typeof window !== 'undefined' && selector === window.document)) {
        return mock$Document;
      }
      return mock$Element;
    });
    
    mockJQuery.fn = {};
    
    // Mock document
    mockDocument = {
      addEventListener: jasmine.createSpy('addEventListener'),
      removeEventListener: jasmine.createSpy('removeEventListener')
    };
    
    // Mock window
    if (typeof window !== 'undefined') {
      window.jQuery = mockJQuery;
      window.document = mockDocument;
      window.addEventListener = jasmine.createSpy('windowAddEventListener');
      window.removeEventListener = jasmine.createSpy('windowRemoveEventListener');
    }
    
    // Mock PollingManager
    mockPollingManager = {
      on: jasmine.createSpy('on').and.returnValue(function() {}), // Retorna función unsubscribe
      off: jasmine.createSpy('off')
    };
    
    if (typeof window !== 'undefined') {
      window.pollingManager = mockPollingManager;
    }
    
    // Mock ErrorHandler
    if (typeof window !== 'undefined') {
      window.ErrorHandler = {
        logError: jasmine.createSpy('logError')
      };
    }
    
    // Limpiar instancia singleton antes de cada test
    if (typeof window !== 'undefined' && window.EventCleanupManager && window.EventCleanupManager.instance) {
      window.EventCleanupManager.instance = null;
    }
  });

  afterEach(function() {
    // Limpiar mocks
    if (typeof window !== 'undefined') {
      delete window.jQuery;
      delete window.document;
      delete window.pollingManager;
      delete window.ErrorHandler;
      // NO eliminar window.EventCleanupManager porque se necesita para los tests
      // La clase se expone al final del archivo y debe estar disponible
    }
    
    // Limpiar instancia singleton después de cada test
    if (typeof window !== 'undefined' && window.EventCleanupManager && window.EventCleanupManager.instance) {
      window.EventCleanupManager.instance = null;
    }
  });

  describe('Carga del script', function() {
    it('debe exponer EventCleanupManager en window', function() {
      expect(typeof window).not.toBe('undefined');
      expect(typeof window.EventCleanupManager).toBe('function');
    });

    it('debe tener método getInstance()', function() {
      const EventCleanupManager = window.EventCleanupManager;
      expect(typeof EventCleanupManager.getInstance).toBe('function');
    });
  });

  describe('getInstance()', function() {
    it('debe retornar una instancia singleton', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const instance1 = EventCleanupManager.getInstance();
      const instance2 = EventCleanupManager.getInstance();
      
      expect(instance1).toBe(instance2);
      expect(instance1).toBeDefined();
      expect(typeof instance1).toBe('object');
    });
  });

  describe('registerDocumentListener()', function() {
    it('debe registrar un listener de document con jQuery', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      
      const unsubscribe = EventCleanupManager.registerDocumentListener(
        'click',
        '#my-button',
        handler,
        'test-component'
      );
      
      expect(typeof unsubscribe).toBe('function');
      // Verificar que jQuery fue llamado (puede ser con document real o mockDocument)
      // El código usa window.document cuando está disponible, que es mockDocument en el test
      expect(mockJQuery).toHaveBeenCalled();
      // Verificar que se usó la instancia correcta de $doc (mock$Document)
      expect(mock$Document.on).toHaveBeenCalledWith('click', '#my-button', handler);
    });

    it('debe retornar función no-op si jQuery no está disponible', function() {
      delete window.jQuery;
      
      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      
      const unsubscribe = EventCleanupManager.registerDocumentListener(
        'click',
        '#my-button',
        handler,
        'test-component'
      );
      
      expect(typeof unsubscribe).toBe('function');
      // No debe lanzar error al llamar unsubscribe
      expect(function() {
        unsubscribe();
      }).not.toThrow();
    });

    it('debe permitir desvincular manualmente el listener', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      
      const unsubscribe = EventCleanupManager.registerDocumentListener(
        'click',
        '#my-button',
        handler,
        'test-component'
      );
      
      unsubscribe();
      
      const $doc = mockJQuery(mockDocument);
      expect($doc.off).toHaveBeenCalledWith('click', '#my-button', handler);
    });
  });

  describe('registerElementListener()', function() {
    it('debe registrar un listener de elemento con jQuery', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      
      const unsubscribe = EventCleanupManager.registerElementListener(
        '#my-button',
        'click',
        handler,
        'test-component'
      );
      
      expect(typeof unsubscribe).toBe('function');
      expect(mockJQuery).toHaveBeenCalledWith('#my-button');
      expect(mock$Element.on).toHaveBeenCalledWith('click', handler);
    });

    it('debe retornar función no-op si el elemento no existe', function() {
      const EventCleanupManager = window.EventCleanupManager;
      mock$Element.length = 0;
      
      const handler = jasmine.createSpy('handler');
      
      const unsubscribe = EventCleanupManager.registerElementListener(
        '#nonexistent',
        'click',
        handler,
        'test-component'
      );
      
      expect(typeof unsubscribe).toBe('function');
      expect(mock$Element.on).not.toHaveBeenCalled();
    });

    it('debe permitir desvincular manualmente el listener', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      
      const unsubscribe = EventCleanupManager.registerElementListener(
        '#my-button',
        'click',
        handler,
        'test-component'
      );
      
      unsubscribe();
      
      expect(mock$Element.off).toHaveBeenCalledWith('click', handler);
    });
  });

  describe('registerCustomEventListener()', function() {
    it('debe registrar un listener de eventos personalizados', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      
      const unsubscribe = EventCleanupManager.registerCustomEventListener(
        mockPollingManager,
        'syncProgress',
        handler,
        'test-component'
      );
      
      expect(typeof unsubscribe).toBe('function');
      expect(mockPollingManager.on).toHaveBeenCalledWith('syncProgress', handler);
    });

    it('debe retornar función no-op si el emitter no es válido', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      
      const unsubscribe = EventCleanupManager.registerCustomEventListener(
        null,
        'syncProgress',
        handler,
        'test-component'
      );
      
      expect(typeof unsubscribe).toBe('function');
      expect(mockPollingManager.on).not.toHaveBeenCalled();
    });

    it('debe permitir desvincular manualmente el listener', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      const mockUnsubscribe = jasmine.createSpy('mockUnsubscribe');
      mockPollingManager.on.and.returnValue(mockUnsubscribe);
      
      const unsubscribe = EventCleanupManager.registerCustomEventListener(
        mockPollingManager,
        'syncProgress',
        handler,
        'test-component'
      );
      
      unsubscribe();
      
      expect(mockUnsubscribe).toHaveBeenCalled();
    });
  });

  describe('registerNativeListener()', function() {
    it('debe registrar un listener nativo con addEventListener', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      
      const unsubscribe = EventCleanupManager.registerNativeListener(
        window,
        'resize',
        handler,
        'test-component',
        { passive: true }
      );
      
      expect(typeof unsubscribe).toBe('function');
      expect(window.addEventListener).toHaveBeenCalledWith('resize', handler, { passive: true });
    });

    it('debe retornar función no-op si el elemento no es válido', function() {
      // ✅ NOTA: Este test verifica el manejo de elementos inválidos, por lo que se espera ver
      // un mensaje de error en la consola. Esto es comportamiento esperado del test.
      // El error "Elemento no válido para registrar listener nativo" que aparece en la consola
      // es parte de la verificación de que los elementos inválidos se manejan correctamente.
      // 
      // El error proviene de installHook.js que intercepta ErrorHandler.logError y lo muestra
      // en la consola. Esto es normal y esperado durante este test.

      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      
      const unsubscribe = EventCleanupManager.registerNativeListener(
        null,
        'resize',
        handler,
        'test-component'
      );
      
      expect(typeof unsubscribe).toBe('function');
      expect(window.addEventListener).not.toHaveBeenCalled();
      
      // ✅ Verificar que se registró el error usando ErrorHandler
      expect(window.ErrorHandler.logError).toHaveBeenCalledWith(
        'Elemento no válido para registrar listener nativo',
        'EVENT_CLEANUP'
      );
    });

    it('debe permitir desvincular manualmente el listener', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      const options = { passive: true };
      
      const unsubscribe = EventCleanupManager.registerNativeListener(
        window,
        'resize',
        handler,
        'test-component',
        options
      );
      
      unsubscribe();
      
      expect(window.removeEventListener).toHaveBeenCalledWith('resize', handler, options);
    });
  });

  describe('cleanupComponent()', function() {
    it('debe limpiar todos los listeners de un componente', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler1 = jasmine.createSpy('handler1');
      const handler2 = jasmine.createSpy('handler2');
      
      // Registrar listeners
      EventCleanupManager.registerDocumentListener('click', '#btn1', handler1, 'test-component');
      EventCleanupManager.registerElementListener('#btn2', 'click', handler2, 'test-component');
      
      // Limpiar componente
      const cleanedCount = EventCleanupManager.cleanupComponent('test-component');
      
      expect(cleanedCount).toBe(2);
      
      const $doc = mockJQuery(mockDocument);
      expect($doc.off).toHaveBeenCalledWith('click', '#btn1', handler1);
      expect(mock$Element.off).toHaveBeenCalledWith('click', handler2);
    });

    it('debe retornar 0 si el componente no tiene listeners', function() {
      const EventCleanupManager = window.EventCleanupManager;
      
      const cleanedCount = EventCleanupManager.cleanupComponent('nonexistent-component');
      
      expect(cleanedCount).toBe(0);
    });

    it('debe manejar errores durante el cleanup sin lanzar excepciones', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      
      // Registrar listener
      EventCleanupManager.registerDocumentListener('click', '#btn', handler, 'test-component');
      
      // Hacer que off() lance un error
      const $doc = mockJQuery(mockDocument);
      $doc.off.and.throwError('Error de cleanup');
      
      // No debe lanzar excepción
      expect(function() {
        EventCleanupManager.cleanupComponent('test-component');
      }).not.toThrow();
      
      // Debe registrar el error
      expect(window.ErrorHandler.logError).toHaveBeenCalled();
    });
  });

  describe('cleanupAll()', function() {
    it('debe limpiar todos los listeners de todos los componentes', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler1 = jasmine.createSpy('handler1');
      const handler2 = jasmine.createSpy('handler2');
      
      // Registrar listeners en diferentes componentes
      EventCleanupManager.registerDocumentListener('click', '#btn1', handler1, 'component1');
      EventCleanupManager.registerElementListener('#btn2', 'click', handler2, 'component2');
      
      // Limpiar todos
      const totalCleaned = EventCleanupManager.getInstance().cleanupAll();
      
      expect(totalCleaned).toBe(2);
    });
  });

  describe('getStats()', function() {
    it('debe retornar estadísticas de listeners registrados', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler1 = jasmine.createSpy('handler1');
      const handler2 = jasmine.createSpy('handler2');
      
      // Registrar diferentes tipos de listeners
      EventCleanupManager.registerDocumentListener('click', '#btn1', handler1, 'test-component');
      EventCleanupManager.registerElementListener('#btn2', 'click', handler2, 'test-component');
      
      const stats = EventCleanupManager.getInstance().getStats();
      
      expect(stats).toBeDefined();
      expect(typeof stats.documentListeners).toBe('number');
      expect(typeof stats.elementListeners).toBe('number');
      expect(typeof stats.customEventListeners).toBe('number');
      expect(typeof stats.nativeListeners).toBe('number');
      expect(typeof stats.totalComponents).toBe('number');
      
      expect(stats.documentListeners).toBeGreaterThanOrEqual(1);
      expect(stats.elementListeners).toBeGreaterThanOrEqual(1);
    });
  });

  describe('Cleanup automático al salir de la página', function() {
    it('debe registrar listeners de beforeunload, unload y pagehide', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const instance = EventCleanupManager.getInstance();
      
      // Verificar que se registraron los listeners automáticos
      expect(window.addEventListener).toHaveBeenCalledWith('beforeunload', jasmine.any(Function), { once: true });
      expect(window.addEventListener).toHaveBeenCalledWith('unload', jasmine.any(Function), { once: true });
      expect(window.addEventListener).toHaveBeenCalledWith('pagehide', jasmine.any(Function), { once: true });
    });

    it('debe ejecutar cleanupAll() cuando se dispara beforeunload', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      
      // Registrar listener
      EventCleanupManager.registerDocumentListener('click', '#btn', handler, 'test-component');
      
      // Obtener el callback de beforeunload
      const beforeunloadCall = window.addEventListener.calls.all().find(call => 
        call.args[0] === 'beforeunload'
      );
      
      expect(beforeunloadCall).toBeDefined();
      const cleanupCallback = beforeunloadCall.args[1];
      
      // Ejecutar callback
      cleanupCallback();
      
      // Verificar que se llamó cleanup
      const $doc = mockJQuery(mockDocument);
      expect($doc.off).toHaveBeenCalled();
    });
  });

  describe('Manejo de errores', function() {
    it('debe manejar errores en registerDocumentListener sin jQuery', function() {
      delete window.jQuery;
      
      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      
      // No debe lanzar error
      expect(function() {
        EventCleanupManager.registerDocumentListener('click', '#btn', handler, 'test-component');
      }).not.toThrow();
      
      // Debe registrar el error
      expect(window.ErrorHandler.logError).toHaveBeenCalled();
    });

    it('debe manejar errores en registerElementListener sin jQuery', function() {
      delete window.jQuery;
      
      const EventCleanupManager = window.EventCleanupManager;
      const handler = jasmine.createSpy('handler');
      
      // No debe lanzar error
      expect(function() {
        EventCleanupManager.registerElementListener('#btn', 'click', handler, 'test-component');
      }).not.toThrow();
      
      // Debe registrar el error
      expect(window.ErrorHandler.logError).toHaveBeenCalled();
    });
  });

  describe('Integración con componentes', function() {
    it('debe rastrear múltiples listeners del mismo componente', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler1 = jasmine.createSpy('handler1');
      const handler2 = jasmine.createSpy('handler2');
      const handler3 = jasmine.createSpy('handler3');
      
      // Registrar múltiples listeners
      EventCleanupManager.registerDocumentListener('click', '#btn1', handler1, 'my-component');
      EventCleanupManager.registerElementListener('#btn2', 'click', handler2, 'my-component');
      EventCleanupManager.registerCustomEventListener(
        mockPollingManager,
        'syncProgress',
        handler3,
        'my-component'
      );
      
      // Limpiar componente
      const cleanedCount = EventCleanupManager.cleanupComponent('my-component');
      
      expect(cleanedCount).toBe(3);
    });

    it('debe permitir limpiar componentes individualmente sin afectar otros', function() {
      const EventCleanupManager = window.EventCleanupManager;
      const handler1 = jasmine.createSpy('handler1');
      const handler2 = jasmine.createSpy('handler2');
      
      // Registrar listeners en diferentes componentes
      EventCleanupManager.registerDocumentListener('click', '#btn1', handler1, 'component1');
      EventCleanupManager.registerDocumentListener('click', '#btn2', handler2, 'component2');
      
      // Limpiar solo component1
      const cleaned1 = EventCleanupManager.cleanupComponent('component1');
      expect(cleaned1).toBe(1);
      
      // Verificar que component2 aún tiene su listener
      const stats = EventCleanupManager.getInstance().getStats();
      expect(stats.documentListeners).toBeGreaterThanOrEqual(1);
    });
  });
});

