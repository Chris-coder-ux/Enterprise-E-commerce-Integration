/**
 * Helper para integrar jQuery con Jasmine
 * 
 * Proporciona funciones auxiliares para trabajar con jQuery en los tests
 */

// Mock de jQuery para Jasmine
(function() {
  'use strict';

  /**
   * Crear un mock de jQuery para los tests
   * 
   * @returns {Object} Mock de jQuery
   */
  window.createMockJQuery = function() {
    const mockElements = {};
    
    // Crear un objeto mock base reutilizable
    // Primero definimos las funciones que retornan this
    const createBaseMock = function() {
      const baseMock = {
        length: 1,
        scrollHeight: 100,
        0: {
          scrollHeight: 100
        }
      };
      
      // Configurar métodos que retornan this
      baseMock.slideDown = jasmine.createSpy('slideDown').and.returnValue(baseMock);
      baseMock.toggleClass = jasmine.createSpy('toggleClass').and.returnValue(baseMock);
      baseMock.hasClass = jasmine.createSpy('hasClass').and.returnValue(false);
      baseMock.addClass = jasmine.createSpy('addClass').and.returnValue(baseMock);
      baseMock.removeClass = jasmine.createSpy('removeClass').and.returnValue(baseMock);
      baseMock.html = jasmine.createSpy('html').and.returnValue(baseMock);
      baseMock.attr = jasmine.createSpy('attr').and.returnValue(baseMock);
      // text() debe retornar string cuando es getter, y baseMock cuando es setter
      baseMock.text = jasmine.createSpy('text').and.callFake(function(value) {
        if (arguments.length === 0) {
          return ''; // Getter: retorna string vacío
        }
        return baseMock; // Setter: retorna el objeto para encadenamiento
      });
      baseMock.append = jasmine.createSpy('append').and.returnValue(baseMock);
      baseMock.empty = jasmine.createSpy('empty').and.returnValue(baseMock);
      baseMock.on = jasmine.createSpy('on').and.returnValue(baseMock);
      baseMock.scrollTop = jasmine.createSpy('scrollTop').and.returnValue(baseMock);
      baseMock.is = jasmine.createSpy('is').and.returnValue(false);
      baseMock.show = jasmine.createSpy('show').and.returnValue(baseMock);
      baseMock.fadeOut = jasmine.createSpy('fadeOut').and.returnValue(baseMock);
      
      // Configurar find, last y first después de crear baseMock
      baseMock.find = jasmine.createSpy('find').and.returnValue(baseMock);
      baseMock.last = jasmine.createSpy('last').and.returnValue(baseMock);
      baseMock.first = jasmine.createSpy('first').and.returnValue({
        remove: jasmine.createSpy('remove'),
        find: jasmine.createSpy('find').and.returnValue(baseMock)
      });
      
      return baseMock;
    };
    
    const mockJQuery = function(selector) {
      // Si es un selector HTML (contiene '<'), crear un nuevo elemento mock
      if (typeof selector === 'string' && selector.includes('<')) {
        return createBaseMock();
      }
      
      // Si no existe en el mapa, usar el base mock
      if (!mockElements[selector]) {
        mockElements[selector] = createBaseMock();
      }
      return mockElements[selector];
    };

    // Crear un baseMock para usar en fn
    const baseMockForFn = createBaseMock();

    mockJQuery.fn = {
      slideDown: jasmine.createSpy('fn.slideDown').and.returnValue(baseMockForFn),
      toggleClass: jasmine.createSpy('fn.toggleClass').and.returnValue(baseMockForFn),
      hasClass: jasmine.createSpy('fn.hasClass').and.returnValue(false),
      addClass: jasmine.createSpy('fn.addClass').and.returnValue(baseMockForFn),
      removeClass: jasmine.createSpy('fn.removeClass').and.returnValue(baseMockForFn),
      html: jasmine.createSpy('fn.html').and.returnValue(baseMockForFn),
      attr: jasmine.createSpy('fn.attr').and.returnValue(baseMockForFn),
      // text() debe retornar string cuando es getter, y baseMockForFn cuando es setter
      text: jasmine.createSpy('fn.text').and.callFake(function(value) {
        if (arguments.length === 0) {
          return ''; // Getter: retorna string vacío
        }
        return baseMockForFn; // Setter: retorna el objeto para encadenamiento
      }),
      find: jasmine.createSpy('fn.find').and.returnValue(baseMockForFn),
      append: jasmine.createSpy('fn.append').and.returnValue(baseMockForFn),
      empty: jasmine.createSpy('fn.empty').and.returnValue(baseMockForFn),
      on: jasmine.createSpy('fn.on').and.returnValue(baseMockForFn),
      scrollTop: jasmine.createSpy('fn.scrollTop').and.returnValue(baseMockForFn),
      is: jasmine.createSpy('fn.is').and.returnValue(false),
      show: jasmine.createSpy('fn.show').and.returnValue(baseMockForFn),
      fadeOut: jasmine.createSpy('fn.fadeOut').and.returnValue(baseMockForFn)
    };

    return { mockJQuery: mockJQuery, mockElements: mockElements };
  };
})();
