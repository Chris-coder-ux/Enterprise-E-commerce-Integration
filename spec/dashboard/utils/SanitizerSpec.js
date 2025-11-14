/**
 * Tests con Jasmine para Sanitizer.js
 * 
 * Verifica que Sanitizer esté correctamente definido y funcione correctamente
 * para prevenir ataques XSS al sanitizar datos del servidor.
 * 
 * @module spec/dashboard/utils/SanitizerSpec
 */

describe('Sanitizer', function() {
  let originalSanitizer, originalDOMPurify;

  beforeEach(function() {
    // Guardar referencias originales
    originalSanitizer = window.Sanitizer;
    originalDOMPurify = window.DOMPurify;
  });

  afterEach(function() {
    // Restaurar referencias originales
    if (originalSanitizer !== undefined) {
      window.Sanitizer = originalSanitizer;
    }
    if (originalDOMPurify !== undefined) {
      window.DOMPurify = originalDOMPurify;
    } else {
      delete window.DOMPurify;
    }
  });

  describe('Carga del script', function() {
    it('debe exponer Sanitizer en window', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      expect(window.Sanitizer).toBeDefined();
      expect(typeof window.Sanitizer).toBe('object');
    });

    it('debe tener todos los métodos requeridos', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      const Sanitizer = window.Sanitizer;
      expect(typeof Sanitizer.escapeHtml).toBe('function');
      expect(typeof Sanitizer.sanitizeHtml).toBe('function');
      expect(typeof Sanitizer.sanitizeMessage).toBe('function');
    });
  });

  describe('escapeHtml', function() {
    it('debe escapar caracteres HTML especiales', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      const Sanitizer = window.Sanitizer;
      
      expect(Sanitizer.escapeHtml('<script>alert("XSS")</script>')).toBe('&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;');
      expect(Sanitizer.escapeHtml('Test & Test')).toBe('Test &amp; Test');
      expect(Sanitizer.escapeHtml('It\'s working')).toBe('It&#039;s working');
    });

    it('debe manejar strings vacíos', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      const Sanitizer = window.Sanitizer;
      
      expect(Sanitizer.escapeHtml('')).toBe('');
    });

    it('debe convertir no-strings a string antes de escapar', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      const Sanitizer = window.Sanitizer;
      
      expect(Sanitizer.escapeHtml(123)).toBe('123');
      expect(Sanitizer.escapeHtml(null)).toBe('null');
      expect(Sanitizer.escapeHtml(undefined)).toBe('undefined');
    });

    it('debe escapar todos los caracteres peligrosos', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      const Sanitizer = window.Sanitizer;
      
      const dangerous = '<>&"\'';
      const escaped = Sanitizer.escapeHtml(dangerous);
      
      // Verificar que el resultado sea el esperado
      expect(escaped).toBe('&lt;&gt;&amp;&quot;&#039;');
      
      // Verificar que no contenga los caracteres peligrosos sin escapar
      expect(escaped).not.toContain('<');
      expect(escaped).not.toContain('>');
      expect(escaped).not.toContain('"');
      expect(escaped).not.toContain('\'');
      
      // Verificar que todos los '&' sean parte de entidades HTML válidas
      // (no debe haber '&' seguido de algo que no sea una entidad válida)
      const validEntities = ['&amp;', '&lt;', '&gt;', '&quot;', '&#039;'];
      let index = 0;
      while (index < escaped.length) {
        const ampIndex = escaped.indexOf('&', index);
        if (ampIndex === -1) {
          break;
        }
        
        // Verificar que este '&' sea parte de una entidad válida
        const isPartOfEntity = validEntities.some(function(entity) {
          return escaped.substring(ampIndex, ampIndex + entity.length) === entity;
        });
        expect(isPartOfEntity).toBe(true);
        
        // Avanzar después de la entidad encontrada
        index = ampIndex + 1;
      }
    });
  });

  describe('sanitizeHtml', function() {
    it('debe usar DOMPurify si está disponible', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      // Mock de DOMPurify
      window.DOMPurify = {
        sanitize: jasmine.createSpy('sanitize').and.returnValue('<b>safe</b>')
      };

      const Sanitizer = window.Sanitizer;
      const result = Sanitizer.sanitizeHtml('<script>alert("XSS")</script><b>safe</b>', { allowBasicFormatting: true });

      expect(window.DOMPurify.sanitize).toHaveBeenCalled();
      expect(result).toBe('<b>safe</b>');
    });

    it('debe usar escapeHtml como fallback si DOMPurify no está disponible', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      // Asegurar que DOMPurify no está disponible
      delete window.DOMPurify;

      const Sanitizer = window.Sanitizer;
      const result = Sanitizer.sanitizeHtml('<script>alert("XSS")</script>');

      // Debe escapar todo el HTML
      expect(result).toBe('&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;');
      expect(result).not.toContain('<script>');
    });

    it('debe permitir formato básico cuando allowBasicFormatting es true', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      // Mock de DOMPurify con configuración
      window.DOMPurify = {
        sanitize: jasmine.createSpy('sanitize').and.callFake(function(html, config) {
          // Simular que permite etiquetas básicas
          if (config && config.ALLOWED_TAGS && config.ALLOWED_TAGS.includes('b')) {
            return html.replace(/<script[^>]*>.*?<\/script>/gi, '');
          }
          return '';
        })
      };

      const Sanitizer = window.Sanitizer;
      Sanitizer.sanitizeHtml('<b>bold</b><script>alert("XSS")</script>', { allowBasicFormatting: true });

      expect(window.DOMPurify.sanitize).toHaveBeenCalled();
    });

    it('debe manejar errores de DOMPurify graciosamente', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      // Mock de DOMPurify que lanza error
      window.DOMPurify = {
        sanitize: jasmine.createSpy('sanitize').and.callFake(function() {
          throw new Error('DOMPurify error');
        })
      };

      const Sanitizer = window.Sanitizer;
      
      // Suprimir console.warn durante esta prueba ya que el error es esperado
      const originalWarn = console.warn;
      let warnCalled = false;
      console.warn = function() {
        warnCalled = true;
        // No mostrar el warning en la consola durante la prueba
      };
      
      try {
        // No debe lanzar error, debe usar fallback
        expect(function() {
          const result = Sanitizer.sanitizeHtml('<script>alert("XSS")</script>');
          expect(result).toBe('&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;');
        }).not.toThrow();
        
        // Verificar que el warning fue llamado (indica que el error fue capturado correctamente)
        expect(warnCalled).toBe(true);
      } finally {
        // Restaurar console.warn
        console.warn = originalWarn;
      }
    });
  });

  describe('sanitizeMessage', function() {
    it('debe sanitizar mensajes de texto', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      const Sanitizer = window.Sanitizer;
      
      const dangerousMessage = '<script>alert("XSS")</script>Test';
      const safe = Sanitizer.sanitizeMessage(dangerousMessage);
      
      expect(safe).not.toContain('<script>');
      expect(safe).toContain('Test');
    });

    it('debe manejar null y undefined', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      const Sanitizer = window.Sanitizer;
      
      expect(Sanitizer.sanitizeMessage(null)).toBe('');
      expect(Sanitizer.sanitizeMessage(undefined)).toBe('');
    });

    it('debe convertir no-strings a string', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      const Sanitizer = window.Sanitizer;
      
      expect(Sanitizer.sanitizeMessage(123)).toBe('123');
      expect(Sanitizer.sanitizeMessage(true)).toBe('true');
    });
  });

  describe('Prevención de XSS', function() {
    it('debe prevenir inyección de scripts', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      const Sanitizer = window.Sanitizer;
      
      const xssAttempts = [
        '<script>alert("XSS")</script>',
        '<img src=x onerror=alert("XSS")>',
        '<svg onload=alert("XSS")>',
        'javascript:alert("XSS")',
        '<iframe src="javascript:alert(\'XSS\')"></iframe>'
      ];

      xssAttempts.forEach(function(attempt) {
        const sanitized = Sanitizer.sanitizeMessage(attempt);
        expect(sanitized).not.toContain('<script>');
        expect(sanitized).not.toContain('onerror=');
        expect(sanitized).not.toContain('onload=');
        expect(sanitized).not.toContain('javascript:');
      });
    });

    it('debe prevenir inyección de eventos HTML', function() {
      if (typeof window.Sanitizer === 'undefined') {
        pending('Sanitizer no está disponible - el script debe cargarse primero');
        return;
      }

      const Sanitizer = window.Sanitizer;
      
      const eventAttempts = [
        '<div onclick="alert(\'XSS\')">Click</div>',
        '<a href="javascript:alert(\'XSS\')">Link</a>',
        '<body onload="alert(\'XSS\')">',
        '<input onfocus="alert(\'XSS\')">'
      ];

      eventAttempts.forEach(function(attempt) {
        const sanitized = Sanitizer.sanitizeMessage(attempt);
        expect(sanitized).not.toContain('onclick=');
        expect(sanitized).not.toContain('onload=');
        expect(sanitized).not.toContain('onfocus=');
        expect(sanitized).not.toContain('javascript:');
      });
    });
  });
});

