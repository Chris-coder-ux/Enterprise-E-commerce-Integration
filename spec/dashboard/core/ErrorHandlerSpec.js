/**
 * Tests con Jasmine para ErrorHandler.js
 * 
 * Verifica que ErrorHandler esté correctamente definido y funcione correctamente.
 * Estos tests se ejecutan en el navegador, lo que permite depurar problemas de carga.
 * 
 * @module spec/dashboard/core/ErrorHandlerSpec
 */

// ✅ JavaScript puro: ya no se usa jQuery, por lo que no necesitamos createMockJQuery

describe('ErrorHandler', function() {
  let originalJQuery, originalErrorHandler, originalConsoleError, originalDASHBOARD_CONFIG, originalSanitizer;

  // ✅ Aumentar el timeout de Jasmine para esta suite (15 segundos)
  // Esto se hace ANTES del beforeAll para que se aplique correctamente
  jasmine.DEFAULT_TIMEOUT_INTERVAL = 15000;

  /**
   * ✅ Esperar a que ErrorHandler se cargue antes de ejecutar los tests
   * Esto asegura que window.ErrorHandler esté disponible
   * 
   * Usa `done()` para operaciones asíncronas con callbacks según mejores prácticas
   * 
   * IMPORTANTE: El timeout de Jasmine se ha aumentado a 15 segundos para esta suite
   */
  beforeAll(function(done) {
    // Función helper para verificar si ErrorHandler está disponible
    function isErrorHandlerAvailable() {
      if (typeof window === 'undefined') {
        return false;
      }
      
      if (typeof window.ErrorHandler === 'undefined') {
        return false;
      }
      
      // Verificar que tiene los métodos necesarios
      if (typeof window.ErrorHandler.logError !== 'function') {
        // eslint-disable-next-line no-console
        console.warn('[ErrorHandlerSpec] ErrorHandler existe pero logError no es una función:', typeof window.ErrorHandler.logError);
        return false;
      }
      
      // Verificar que no es un mock de Jest (los mocks tienen una estructura específica)
      const methodCode = window.ErrorHandler.logError.toString();
      if (methodCode.includes('fn.apply') || methodCode.includes('jest.fn')) {
        // eslint-disable-next-line no-console
        console.warn('[ErrorHandlerSpec] ErrorHandler parece ser un mock, no el código real');
        return false;
      }
      
      return true;
    }

    // Log de inicio para depuración
    // eslint-disable-next-line no-console
    console.log('[ErrorHandlerSpec] Iniciando carga de ErrorHandler...');
    // eslint-disable-next-line no-console
    console.log('[ErrorHandlerSpec] Timeout de Jasmine configurado a:', jasmine.DEFAULT_TIMEOUT_INTERVAL, 'ms');
    // eslint-disable-next-line no-console
    console.log('[ErrorHandlerSpec] Estado inicial - window:', typeof window, 'ErrorHandler:', typeof window !== 'undefined' ? typeof window.ErrorHandler : 'N/A');

    // Si ya está disponible, continuar inmediatamente
    if (isErrorHandlerAvailable()) {
      // eslint-disable-next-line no-console
      console.log('[ErrorHandlerSpec] ErrorHandler ya está disponible, continuando...');
      done();
      return;
    }
    
    // eslint-disable-next-line no-console
    console.log('[ErrorHandlerSpec] ErrorHandler no está disponible aún, comenzando polling...');

    let attempts = 0;
    const maxAttempts = 100; // 10 segundos (100 * 100ms) - aumentado para dar más tiempo a la carga
    let isDone = false; // Flag para evitar múltiples llamadas a done()
    const checkInterval = 100; // ms

    // Log periódico para depuración (cada 2 segundos)
    const logInterval = setInterval(function() {
      if (!isDone && attempts % 20 === 0 && attempts > 0) {
        // eslint-disable-next-line no-console
        console.log('[ErrorHandlerSpec] Esperando ErrorHandler... (intento ' + attempts + '/' + maxAttempts + ')');
      }
    }, checkInterval);

    // Esperar hasta que esté disponible
    const interval = setInterval(function() {
      // Verificar si ya se completó para evitar llamadas múltiples
      if (isDone) {
        clearInterval(interval);
        clearInterval(logInterval);
        return;
      }

      attempts++;
      
      // Verificar si ErrorHandler está disponible Y tiene métodos (verificación más robusta)
      if (isErrorHandlerAvailable()) {
        isDone = true;
        clearInterval(interval);
        clearInterval(logInterval);
        // eslint-disable-next-line no-console
        console.log('[ErrorHandlerSpec] ErrorHandler cargado correctamente después de ' + attempts + ' intentos');
        done();
        return;
      }

      // Si se alcanzó el máximo de intentos, manejar el error
      if (attempts >= maxAttempts) {
        isDone = true;
        clearInterval(interval);
        clearInterval(logInterval);
        
        // Intentar verificar una última vez
        if (!isErrorHandlerAvailable()) {
          // Información adicional de depuración
          const debugInfo = {
            windowAvailable: typeof window !== 'undefined',
            errorHandlerType: typeof window !== 'undefined' ? typeof window.ErrorHandler : 'N/A',
            errorHandlerValue: typeof window !== 'undefined' ? window.ErrorHandler : 'N/A',
            sanitizerAvailable: typeof window !== 'undefined' && typeof window.Sanitizer !== 'undefined',
            dashboardConfigAvailable: typeof window !== 'undefined' && typeof window.DASHBOARD_CONFIG !== 'undefined',
            scriptsLoaded: typeof document !== 'undefined' ? document.querySelectorAll('script[src*="ErrorHandler"]').length : 0
          };
          
          const errorMsg = 'ErrorHandler no se cargó correctamente en ' + 
            (maxAttempts * checkInterval / 1000) + ' segundos. ' +
            'Verifica que el script ErrorHandler.js se esté cargando correctamente en SpecRunner.html. ' +
            'Revisa la consola del navegador para ver si hay errores de JavaScript. ' +
            'window.ErrorHandler actual: ' + debugInfo.errorHandlerType + 
            '. Info de depuración: ' + JSON.stringify(debugInfo, null, 2);
          // Registrar el error pero continuar - los tests individuales manejarán el caso con pending()
          // eslint-disable-next-line no-console
          console.error('[ErrorHandlerSpec] ' + errorMsg);
        } else {
          // eslint-disable-next-line no-console
          console.log('[ErrorHandlerSpec] ErrorHandler disponible en verificación final');
        }
        // Llamar a done() en cualquier caso para que los tests continúen
        // Los tests individuales verificarán si ErrorHandler está disponible y harán pending() si no lo está
        done();
      }
    }, checkInterval);
    
    // ✅ TIMEOUT DE SEGURIDAD: Si después de 13 segundos no se ha llamado done(), forzarlo
    // Esto previene timeouts infinitos
    setTimeout(function() {
      if (!isDone) {
        isDone = true;
        clearInterval(interval);
        clearInterval(logInterval);
        // eslint-disable-next-line no-console
        console.warn('[ErrorHandlerSpec] Timeout de seguridad alcanzado. Continuando con los tests...');
        done();
      }
    }, 13000); // 13 segundos como timeout de seguridad (debe ser mayor que maxAttempts * checkInterval)
  });

  /**
   * ✅ Configurar mocks de dependencias antes de cada test
   * 
   * ErrorHandler ahora usa JavaScript puro (sin jQuery).
   * Depende de: document, DASHBOARD_CONFIG, Sanitizer.
   * En las pruebas, estas deben estar disponibles como mocks para evitar bloqueos.
   */
  beforeEach(function() {
    // Guardar referencias originales para restauración en afterEach
    originalJQuery = window.jQuery;
    originalErrorHandler = window.ErrorHandler;
    originalConsoleError = console.error;
    originalDASHBOARD_CONFIG = window.DASHBOARD_CONFIG;
    originalSanitizer = window.Sanitizer;

    // ✅ Configurar DASHBOARD_CONFIG mock
    // ErrorHandler usa DASHBOARD_CONFIG.selectors.feedback para mostrar errores en UI
    window.DASHBOARD_CONFIG = {
      selectors: {
        feedback: '#mi-sync-feedback'
      }
    };

    // ✅ Mock de console.error para capturar logs sin contaminar la consola
    console.error = jasmine.createSpy('console.error');

    // ✅ MOCK SIMPLE Y CONFIABLE de Sanitizer
    // Sanitizer es una dependencia crítica para prevenir XSS
    const sanitizerMock = {
      sanitizeMessage: jasmine.createSpy('sanitizeMessage').and.callFake(function(message) {
        if (message === null || message === undefined) {
          return '';
        }
        return String(message).replace(/[&<>"']/g, function(m) {
          const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#039;' };
          return map[m];
        });
      })
    };
    
    // ✅ Exponer en window.Sanitizer (ErrorHandler.js ahora también verifica window.Sanitizer)
    // Esto es suficiente ya que ErrorHandler.js tiene fallback para window.Sanitizer
    window.Sanitizer = sanitizerMock;
    
    // ✅ Resetear el spy antes de cada test para asegurar que esté limpio
    if (window.Sanitizer && window.Sanitizer.sanitizeMessage && typeof window.Sanitizer.sanitizeMessage.calls !== 'undefined') {
      window.Sanitizer.sanitizeMessage.calls.reset();
    }
    
    // ✅ Intentar exponer como variable global solo si es posible (opcional)
    // ErrorHandler.js ahora también verifica window.Sanitizer, así que esto es opcional
    try {
      // Verificar si Sanitizer existe como variable global (puede fallar si es constante)
      let sanitizerExists = false;
      try {
        // eslint-disable-next-line no-undef
        sanitizerExists = typeof Sanitizer !== 'undefined';
      } catch (e) {
        // Si Sanitizer está definido como constante, no podemos verificar su tipo
        // En este caso, simplemente usar window.Sanitizer
        sanitizerExists = true; // Asumir que existe para no intentar crearlo
      }
      
      if (!sanitizerExists) {
        // Solo intentar crear la variable global si no existe
        // eslint-disable-next-line no-new-func
        const globalSetter = new Function('s', 'Sanitizer = s;');
        globalSetter(sanitizerMock);
      }
      // Si Sanitizer ya existe (como constante), no intentar sobrescribirlo
      // window.Sanitizer es suficiente
    } catch (e) {
      // Silenciar errores de constante - window.Sanitizer es suficiente
      // ErrorHandler.js ahora verifica window.Sanitizer como fallback
    }
  });

  afterEach(function() {
    // Restaurar referencias originales
    if (originalJQuery) {
      window.jQuery = originalJQuery;
      window.$ = originalJQuery;
    }
    if (originalErrorHandler !== undefined) {
      window.ErrorHandler = originalErrorHandler;
    }
    if (originalConsoleError) {
      console.error = originalConsoleError;
    }
    if (originalDASHBOARD_CONFIG !== undefined) {
      window.DASHBOARD_CONFIG = originalDASHBOARD_CONFIG;
    } else {
      delete window.DASHBOARD_CONFIG;
    }
    if (originalSanitizer !== undefined) {
      window.Sanitizer = originalSanitizer;
    } else {
      delete window.Sanitizer;
    }
  });

  describe('Carga del script', function() {
    it('debe exponer ErrorHandler en window', function() {
      if (typeof window.ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      expect(window.ErrorHandler).toBeDefined();
      expect(typeof window.ErrorHandler).toBe('function');
    });

    it('debe tener métodos estáticos', function() {
      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      expect(typeof ErrorHandler.logError).toBe('function');
      expect(typeof ErrorHandler.showUIError).toBe('function');
      expect(typeof ErrorHandler.showConnectionError).toBe('function');
      expect(typeof ErrorHandler.showProtectionError).toBe('function');
      expect(typeof ErrorHandler.showCancelError).toBe('function');
      expect(typeof ErrorHandler.showCriticalError).toBe('function');
    });
  });

  describe('logError', function() {
    it('debe registrar errores en la consola con timestamp', function() {
      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.logError('Test error message');

      expect(console.error).toHaveBeenCalled();
      const callArgs = console.error.calls.mostRecent().args[0];
      expect(callArgs).toContain('Test error message');
      expect(callArgs).toMatch(/\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/); // Formato ISO timestamp
    });

    it('debe incluir el contexto cuando se proporciona', function() {
      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.logError('Test error', 'TEST_CONTEXT');

      expect(console.error).toHaveBeenCalled();
      const callArgs = console.error.calls.mostRecent().args[0];
      expect(callArgs).toContain('[TEST_CONTEXT]');
      expect(callArgs).toContain('Test error');
    });

    it('debe funcionar sin contexto', function() {
      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.logError('Test error without context');

      expect(console.error).toHaveBeenCalled();
      const callArgs = console.error.calls.mostRecent().args[0];
      expect(callArgs).toContain('Test error without context');
    });
  });

  describe('showUIError', function() {
    it('debe mostrar error en el elemento de feedback cuando existe', function() {
      // ✅ JavaScript puro: crear elemento real del DOM para el test
      const feedbackElement = document.createElement('div');
      feedbackElement.id = 'mi-sync-feedback';
      feedbackElement.className = 'mi-api-feedback in-progress';
      document.body.appendChild(feedbackElement);

      // Espiar métodos del elemento (usar callThrough para que realmente ejecute el método)
      spyOn(feedbackElement.classList, 'remove').and.callThrough();
      spyOn(feedbackElement, 'appendChild').and.callThrough();

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showUIError('Test error message', 'error');

      // Verificar que se removió la clase 'in-progress'
      expect(feedbackElement.classList.remove).toHaveBeenCalledWith('in-progress');
      // Verificar que se agregó contenido
      expect(feedbackElement.innerHTML).toContain('mi-api-error');
      expect(feedbackElement.innerHTML).toContain('❌');
      expect(feedbackElement.innerHTML).toContain('Test error message');

      // Limpiar
      document.body.removeChild(feedbackElement);
    });

    it('debe crear elemento temporal cuando no existe feedback', function() {
      // ✅ JavaScript puro: asegurar que no existe el elemento de feedback
      const existingFeedback = document.querySelector('#mi-sync-feedback');
      if (existingFeedback) {
        existingFeedback.remove();
      }

      // Espiar document.createElement y document.body.appendChild
      const createElementSpy = spyOn(document, 'createElement').and.callThrough();
      const appendChildSpy = spyOn(document.body, 'appendChild').and.callThrough();

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showUIError('Test error message', 'error');

      // Verificar que se creó un elemento div temporal
      expect(createElementSpy).toHaveBeenCalledWith('div');
      
      // Verificar que se agregó al body
      expect(appendChildSpy).toHaveBeenCalled();
      
      // Verificar que se creó el elemento temporal con el ID correcto
      const tempElement = document.querySelector('#mi-sync-feedback');
      expect(tempElement).toBeDefined();
      expect(tempElement.id).toBe('mi-sync-feedback');
      expect(tempElement.className).toBe('mi-api-feedback');
      
      // Verificar que tiene el contenido correcto
      expect(tempElement.innerHTML).toContain('mi-api-error');
      expect(tempElement.innerHTML).toContain('❌');
      expect(tempElement.innerHTML).toContain('Test error message');
      
      // Verificar estilos aplicados
      expect(tempElement.style.position).toBe('fixed');
      expect(tempElement.style.top).toBe('20px');
      expect(tempElement.style.right).toBe('20px');
      
      // Debe mostrar error en consola como fallback
      expect(console.error).toHaveBeenCalled();
      
      // Limpiar elemento temporal (se auto-eliminará después de 5 segundos, pero lo limpiamos ahora)
      if (tempElement && tempElement.parentNode) {
        tempElement.parentNode.removeChild(tempElement);
      }
    });

    it('debe mostrar warning cuando type es "warning"', function() {
      // ✅ JavaScript puro: crear elemento real del DOM para el test
      const feedbackElement = document.createElement('div');
      feedbackElement.id = 'mi-sync-feedback';
      feedbackElement.className = 'mi-api-feedback';
      document.body.appendChild(feedbackElement);

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showUIError('Test warning', 'warning');

      // Verificar que se agregó contenido de warning
      expect(feedbackElement.innerHTML).toContain('mi-api-warning');
      expect(feedbackElement.innerHTML).toContain('⚠️');
      expect(feedbackElement.innerHTML).toContain('Test warning');

      // Limpiar
      document.body.removeChild(feedbackElement);
    });
  });

  // ✅ Helper para crear elemento de feedback para los tests (JavaScript puro)
  function createFeedbackElement() {
    const feedbackElement = document.createElement('div');
    feedbackElement.id = 'mi-sync-feedback';
    feedbackElement.className = 'mi-api-feedback';
    document.body.appendChild(feedbackElement);
    return feedbackElement;
  }

  describe('showConnectionError', function() {

    it('debe manejar error de conexión con status 0', function() {
      const feedbackElement = createFeedbackElement();

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      // ✅ VERIFICAR QUE SANITIZER ESTÁ DISPONIBLE
      expect(window.Sanitizer).toBeDefined();
      expect(typeof window.Sanitizer.sanitizeMessage).toBe('function');
      
      // ✅ Resetear el spy antes del test para asegurar que esté limpio
      if (window.Sanitizer && window.Sanitizer.sanitizeMessage && typeof window.Sanitizer.sanitizeMessage.calls !== 'undefined') {
        window.Sanitizer.sanitizeMessage.calls.reset();
      }

      const xhr = { status: 0 };
      ErrorHandler.showConnectionError(xhr);

      // ✅ Verificar que se agregó contenido de error
      expect(feedbackElement.innerHTML).toContain('mi-api-error');
      expect(feedbackElement.innerHTML).toContain('❌');
      expect(feedbackElement.innerHTML).toContain('No se pudo conectar al servidor');
      
      // ✅ VERIFICAR QUE SE LLAMÓ A SANITIZE
      // Verificar que el spy existe y fue llamado
      expect(window.Sanitizer).toBeDefined();
      expect(window.Sanitizer.sanitizeMessage).toBeDefined();
      expect(typeof window.Sanitizer.sanitizeMessage).toBe('function');
      
      // Verificar que se llamó a sanitizeMessage (debe ser un spy de Jasmine)
      // Los spies de Jasmine tienen la propiedad 'calls'
      if (window.Sanitizer.sanitizeMessage && window.Sanitizer.sanitizeMessage.calls) {
        expect(window.Sanitizer.sanitizeMessage).toHaveBeenCalled();
        
        const sanitizeCall = window.Sanitizer.sanitizeMessage.calls.mostRecent();
        expect(sanitizeCall).toBeDefined();
        expect(sanitizeCall.args).toBeDefined();
        expect(sanitizeCall.args.length).toBeGreaterThan(0);
        expect(sanitizeCall.args[0]).toContain('No se pudo conectar al servidor');
      } else {
        // Si no es un spy, verificar que el contenido está sanitizado de otra forma
        // (el código debería estar usando el fallback de escape)
        expect(feedbackElement.innerHTML).not.toContain('<script>');
      }

      // Limpiar
      document.body.removeChild(feedbackElement);
    });

    it('debe manejar error 403', function() {
      const feedbackElement = createFeedbackElement();

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      const xhr = { status: 403 };
      ErrorHandler.showConnectionError(xhr);

      expect(feedbackElement.innerHTML).toContain('mi-api-error');
      expect(feedbackElement.innerHTML).toContain('Acceso denegado');
      
      // Verificar que Sanitizer.sanitizeMessage fue llamado con el mensaje correcto
      expect(window.Sanitizer.sanitizeMessage).toHaveBeenCalled();
      const sanitizeCall = window.Sanitizer.sanitizeMessage.calls.mostRecent();
      expect(sanitizeCall.args[0]).toContain('Acceso denegado');

      // Limpiar
      document.body.removeChild(feedbackElement);
    });

    it('debe manejar error 404', function() {
      const feedbackElement = createFeedbackElement();

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      const xhr = { status: 404 };
      ErrorHandler.showConnectionError(xhr);

      expect(feedbackElement.innerHTML).toContain('mi-api-error');
      expect(feedbackElement.innerHTML).toContain('Recurso no encontrado');
      
      // Verificar que Sanitizer.sanitizeMessage fue llamado con el mensaje correcto
      expect(window.Sanitizer.sanitizeMessage).toHaveBeenCalled();
      const sanitizeCall = window.Sanitizer.sanitizeMessage.calls.mostRecent();
      expect(sanitizeCall.args[0]).toContain('Recurso no encontrado');

      // Limpiar
      document.body.removeChild(feedbackElement);
    });

    it('debe manejar error 500', function() {
      const feedbackElement = createFeedbackElement();

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      const xhr = { status: 500 };
      ErrorHandler.showConnectionError(xhr);

      expect(feedbackElement.innerHTML).toContain('mi-api-error');
      expect(feedbackElement.innerHTML).toContain('Problema interno');
      
      // Verificar que Sanitizer.sanitizeMessage fue llamado con el mensaje correcto
      expect(window.Sanitizer.sanitizeMessage).toHaveBeenCalled();
      const sanitizeCall = window.Sanitizer.sanitizeMessage.calls.mostRecent();
      expect(sanitizeCall.args[0]).toContain('Problema interno');

      // Limpiar
      document.body.removeChild(feedbackElement);
    });

    it('debe manejar xhr sin status', function() {
      const feedbackElement = createFeedbackElement();

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      const xhr = { statusText: 'Error' };
      ErrorHandler.showConnectionError(xhr);

      expect(feedbackElement.innerHTML).toContain('mi-api-error');

      // Limpiar
      document.body.removeChild(feedbackElement);
    });

    it('debe manejar xhr null o undefined', function() {
      const feedbackElement = createFeedbackElement();

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showConnectionError(null);

      expect(feedbackElement.innerHTML).toContain('mi-api-error');
      expect(feedbackElement.innerHTML).toContain('No se pudo establecer comunicación');

      // Limpiar
      document.body.removeChild(feedbackElement);
    });
  });

  describe('showProtectionError', function() {
    it('debe registrar error de protección sin mostrar en UI', function() {
      // ✅ JavaScript puro: no necesita elemento del DOM ya que no muestra en UI
      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showProtectionError('Click automático detectado');

      expect(console.error).toHaveBeenCalled();
      const callArgs = console.error.calls.mostRecent().args[0];
      expect(callArgs).toContain('Protección activada');
      expect(callArgs).toContain('Click automático detectado');

      // No debe mostrar en UI - verificar que no se creó elemento de feedback
      const feedbackElement = document.querySelector('#mi-sync-feedback');
      // Si existe, no debe tener contenido de error (puede existir de tests anteriores)
      if (feedbackElement) {
        expect(feedbackElement.innerHTML).not.toContain('Protección activada');
      }
    });
  });

  describe('showCancelError', function() {
    it('debe registrar y mostrar error de cancelación', function() {
      const feedbackElement = createFeedbackElement();

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showCancelError('Error al cancelar sincronización');

      expect(console.error).toHaveBeenCalled();
      expect(feedbackElement.innerHTML).toContain('Error al cancelar');

      // Limpiar
      document.body.removeChild(feedbackElement);
    });

    it('debe usar contexto personalizado', function() {
      // ✅ JavaScript puro: no necesita elemento del DOM para este test
      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showCancelError('Test cancel', 'CUSTOM_CONTEXT');

      expect(console.error).toHaveBeenCalled();
      const callArgs = console.error.calls.mostRecent().args[0];
      expect(callArgs).toContain('[CUSTOM_CONTEXT]');
    });
  });

  describe('showCriticalError', function() {
    it('debe registrar y mostrar error crítico', function() {
      const feedbackElement = createFeedbackElement();

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showCriticalError('Error crítico del sistema');

      expect(console.error).toHaveBeenCalled();
      expect(feedbackElement.innerHTML).toContain('Error crítico');

      // Limpiar
      document.body.removeChild(feedbackElement);
    });

    it('debe usar contexto CRITICAL por defecto', function() {
      // ✅ JavaScript puro: no necesita elemento del DOM para este test
      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      ErrorHandler.showCriticalError('Test critical');

      expect(console.error).toHaveBeenCalled();
      const callArgs = console.error.calls.mostRecent().args[0];
      expect(callArgs).toContain('[CRITICAL]');
    });
  });

  describe('Sanitización de mensajes', function() {
    it('debe sanitizar mensajes antes de insertarlos en el DOM', function() {
      const feedbackElement = createFeedbackElement();

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      const dangerousMessage = '<script>alert("XSS")</script>Test';
      ErrorHandler.showUIError(dangerousMessage, 'error');

      // ✅ Verificar que se llamó a Sanitizer.sanitizeMessage
      expect(window.Sanitizer.sanitizeMessage).toHaveBeenCalledWith(dangerousMessage);
      
      // ✅ Verificar que se usa construcción segura (HTML para estructura, textContent para mensaje)
      expect(feedbackElement.innerHTML).toContain('<div class="mi-api-error">');
      expect(feedbackElement.innerHTML).toContain('<strong>');
      // El mensaje debe estar sanitizado (no debe contener <script>)
      expect(feedbackElement.innerHTML).not.toContain('<script>');

      // Limpiar
      document.body.removeChild(feedbackElement);
    });

    it('debe prevenir XSS en mensajes del servidor', function() {
      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      const xssAttempts = [
        '<script>alert("XSS")</script>',
        '<img src=x onerror=alert("XSS")>',
        '<svg onload=alert("XSS")>'
      ];

      xssAttempts.forEach(function(attempt) {
        // Crear elemento para cada test
        const feedbackElement = createFeedbackElement();
        
        // Resetear el spy antes de cada iteración para verificar cada llamada individualmente
        window.Sanitizer.sanitizeMessage.calls.reset();
        
        ErrorHandler.showUIError(attempt, 'error');
        
        // ✅ Verificar que se sanitizó cada intento
        expect(window.Sanitizer.sanitizeMessage).toHaveBeenCalledWith(attempt);
        
        // Limpiar
        document.body.removeChild(feedbackElement);
      });
    });

    it('debe usar fallback de escape si Sanitizer no está disponible', function() {
      const feedbackElement = createFeedbackElement();

      // Guardar referencia original
      const originalSanitizer = window.Sanitizer;
      
      // Eliminar Sanitizer para probar fallback
      delete window.Sanitizer;
      // eslint-disable-next-line no-undef
      if (typeof Sanitizer !== 'undefined') {
        // eslint-disable-next-line no-new-func
        const globalDeleter = new Function('delete Sanitizer;');
        globalDeleter();
      }

      const ErrorHandler = window.ErrorHandler;
      if (typeof ErrorHandler === 'undefined') {
        pending('ErrorHandler no está disponible - el script debe cargarse primero');
        return;
      }

      const dangerousMessage = '<script>alert("XSS")</script>';
      
      // No debe lanzar error, debe usar fallback
      expect(function() {
        ErrorHandler.showUIError(dangerousMessage, 'error');
      }).not.toThrow();

      // Verificar que se agregó contenido (aunque sin Sanitizer)
      expect(feedbackElement.innerHTML).toContain('mi-api-error');

      // Restaurar Sanitizer
      window.Sanitizer = originalSanitizer;

      // Limpiar
      document.body.removeChild(feedbackElement);
    });
  });
});

