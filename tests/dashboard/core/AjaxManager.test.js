/**
 * Tests para AjaxManager
 * 
 * Verifica que AjaxManager funcione correctamente como wrapper
 * de jQuery.ajax con manejo automático de nonce y configuración.
 * 
 * @file tests/dashboard/core/AjaxManager.test.js
 * @since 1.0.0
 */

const fs = require('fs');
const path = require('path');

// Configurar jQuery real para las pruebas
const realJQuery = require('jquery');
global.jQuery = realJQuery;
global.$ = realJQuery;

// Configurar jsdom si es necesario
if (typeof document !== 'undefined') {
  if (!document.body) {
    const body = document.createElement('body');
    document.documentElement.appendChild(body);
  }
}

// Cargar el código real de AjaxManager (sobrescribiendo el mock de jest.setup.js)
const ajaxManagerPath = path.join(__dirname, '../../../assets/js/dashboard/core/AjaxManager.js');
const ajaxManagerCode = fs.readFileSync(ajaxManagerPath, 'utf8');
eval(ajaxManagerCode); // Esto carga el real AjaxManager

describe('AjaxManager', () => {
  
  // Mock de miIntegracionApiDashboard
  let originalConfig;
  
  beforeEach(() => {
    // Guardar configuración original
    originalConfig = global.miIntegracionApiDashboard;
    
    // Configurar miIntegracionApiDashboard para las pruebas
    global.miIntegracionApiDashboard = {
      ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
      nonce: 'test-nonce-12345'
    };
    
    // Mock de jQuery.ajax - usar implementación simple para evitar recursión
    global.jQuery.ajax = jest.fn((options) => {
      // Simular una promesa jQuery simple
      const deferred = {
        done: jest.fn(function() { return this; }),
        fail: jest.fn(function() { return this; }),
        always: jest.fn(function() { return this; }),
        then: jest.fn(function() { return this; }),
        catch: jest.fn(function() { return this; }),
        promise: function() { return this; }
      };
      
      // Simular éxito por defecto (síncrono para tests)
      // En un entorno real sería asíncrono, pero para tests lo hacemos síncrono
      if (options.success) {
        try {
          // Ejecutar callback de forma síncrona para los tests
          options.success({ success: true, data: { message: 'Test response' } });
        } catch (e) {
          // Ignorar errores en callbacks
        }
      }
      
      return deferred;
    });
    
    // Mock de jQuery.Deferred - implementación simple
    global.jQuery.Deferred = jest.fn(() => {
      const deferred = {
        resolve: jest.fn(function() { return this; }),
        reject: jest.fn(function() {
          return {
            promise: function() { return this; }
          };
        }),
        promise: function() { return this; }
      };
      return deferred;
    });
  });
  
  afterEach(() => {
    // Restaurar configuración original
    global.miIntegracionApiDashboard = originalConfig;
    jest.clearAllMocks();
  });
  
  describe('call()', () => {
    
    it('debe realizar una petición AJAX con configuración correcta', () => {
      const action = 'mia_get_sync_progress';
      const data = { test: 'data' };
      const successCallback = jest.fn();
      const errorCallback = jest.fn();
      
      AjaxManager.call(action, data, successCallback, errorCallback);
      
      expect(jQuery.ajax).toHaveBeenCalledTimes(1);
      const ajaxCall = jQuery.ajax.mock.calls[0][0];
      
      expect(ajaxCall.url).toBe('http://test.local/wp-admin/admin-ajax.php');
      expect(ajaxCall.type).toBe('POST');
      expect(ajaxCall.data.action).toBe(action);
      expect(ajaxCall.data.nonce).toBe('test-nonce-12345');
      expect(ajaxCall.data.test).toBe('data');
      expect(ajaxCall.success).toBe(successCallback);
      expect(ajaxCall.error).toBe(errorCallback);
    });
    
    it('debe usar datos vacíos por defecto si no se proporcionan', () => {
      const action = 'mia_get_sync_progress';
      
      AjaxManager.call(action);
      
      expect(jQuery.ajax).toHaveBeenCalledTimes(1);
      const ajaxCall = jQuery.ajax.mock.calls[0][0];
      
      expect(ajaxCall.data.action).toBe(action);
      expect(ajaxCall.data.nonce).toBe('test-nonce-12345');
      expect(Object.keys(ajaxCall.data).length).toBe(2); // Solo action y nonce
    });
    
    it('debe aplicar opciones adicionales si se proporcionan', () => {
      const action = 'mia_get_sync_progress';
      const options = {
        timeout: 30000,
        beforeSend: jest.fn()
      };
      
      AjaxManager.call(action, {}, null, null, options);
      
      expect(jQuery.ajax).toHaveBeenCalledTimes(1);
      const ajaxCall = jQuery.ajax.mock.calls[0][0];
      
      expect(ajaxCall.timeout).toBe(30000);
      expect(ajaxCall.beforeSend).toBe(options.beforeSend);
    });
    
    it('debe retornar una promesa jQuery', () => {
      const result = AjaxManager.call('mia_get_sync_progress');
      
      expect(result).toBeDefined();
      expect(typeof result.promise).toBe('function');
    });
    
    it('debe manejar error cuando miIntegracionApiDashboard no está disponible', () => {
      global.miIntegracionApiDashboard = undefined;
      const errorCallback = jest.fn();
      const consoleErrorSpy = jest.spyOn(console, 'error').mockImplementation();
      
      const result = AjaxManager.call('mia_get_sync_progress', {}, null, errorCallback);
      
      expect(consoleErrorSpy).toHaveBeenCalled();
      expect(errorCallback).toHaveBeenCalledWith(
        null,
        'error',
        'Configuración AJAX incompleta - Variables no disponibles'
      );
      expect(jQuery.ajax).not.toHaveBeenCalled();
      expect(jQuery.Deferred).toHaveBeenCalled();
      
      consoleErrorSpy.mockRestore();
    });
    
    it('debe manejar error cuando ajaxurl no está disponible', () => {
      global.miIntegracionApiDashboard = {
        nonce: 'test-nonce'
        // Sin ajaxurl
      };
      const errorCallback = jest.fn();
      const consoleErrorSpy = jest.spyOn(console, 'error').mockImplementation();
      
      AjaxManager.call('mia_get_sync_progress', {}, null, errorCallback);
      
      expect(consoleErrorSpy).toHaveBeenCalled();
      expect(errorCallback).toHaveBeenCalled();
      expect(jQuery.ajax).not.toHaveBeenCalled();
      
      consoleErrorSpy.mockRestore();
    });
    
    it('debe manejar error cuando nonce no está disponible', () => {
      global.miIntegracionApiDashboard = {
        ajaxurl: 'http://test.local/wp-admin/admin-ajax.php'
        // Sin nonce
      };
      const errorCallback = jest.fn();
      const consoleErrorSpy = jest.spyOn(console, 'error').mockImplementation();
      
      AjaxManager.call('mia_get_sync_progress', {}, null, errorCallback);
      
      expect(consoleErrorSpy).toHaveBeenCalled();
      expect(errorCallback).toHaveBeenCalled();
      expect(jQuery.ajax).not.toHaveBeenCalled();
      
      consoleErrorSpy.mockRestore();
    });
    
    it('debe no llamar error callback si no se proporciona', () => {
      global.miIntegracionApiDashboard = undefined;
      const consoleErrorSpy = jest.spyOn(console, 'error').mockImplementation();
      
      AjaxManager.call('mia_get_sync_progress', {}, null, null);
      
      expect(consoleErrorSpy).toHaveBeenCalled();
      expect(jQuery.ajax).not.toHaveBeenCalled();
      
      consoleErrorSpy.mockRestore();
    });
    
    it('debe combinar datos proporcionados con action y nonce', () => {
      const action = 'mia_get_sync_progress';
      const data = {
        customParam: 'customValue',
        anotherParam: 123
      };
      
      AjaxManager.call(action, data);
      
      expect(jQuery.ajax).toHaveBeenCalledTimes(1);
      const ajaxCall = jQuery.ajax.mock.calls[0][0];
      
      expect(ajaxCall.data.action).toBe(action);
      expect(ajaxCall.data.nonce).toBe('test-nonce-12345');
      expect(ajaxCall.data.customParam).toBe('customValue');
      expect(ajaxCall.data.anotherParam).toBe(123);
    });
    
    it('debe sobrescribir opciones base con opciones adicionales', () => {
      const action = 'mia_get_sync_progress';
      const options = {
        type: 'GET', // Sobrescribir POST
        url: 'http://custom.url', // Sobrescribir ajaxurl
        timeout: 60000
      };
      
      AjaxManager.call(action, {}, null, null, options);
      
      expect(jQuery.ajax).toHaveBeenCalledTimes(1);
      const ajaxCall = jQuery.ajax.mock.calls[0][0];
      
      expect(ajaxCall.type).toBe('GET');
      expect(ajaxCall.url).toBe('http://custom.url');
      expect(ajaxCall.timeout).toBe(60000);
    });
    
    it('debe estar disponible globalmente como window.AjaxManager', () => {
      expect(typeof window.AjaxManager).toBe('function');
      expect(window.AjaxManager).toBe(AjaxManager);
    });
    
    it('debe tener el método call disponible', () => {
      expect(typeof AjaxManager.call).toBe('function');
      expect(typeof window.AjaxManager.call).toBe('function');
    });
  });
  
  describe('Casos edge', () => {
    
    it('debe manejar action vacío', () => {
      AjaxManager.call('');
      
      expect(jQuery.ajax).toHaveBeenCalledTimes(1);
      const ajaxCall = jQuery.ajax.mock.calls[0][0];
      expect(ajaxCall.data.action).toBe('');
    });
    
    it('debe manejar data null', () => {
      AjaxManager.call('mia_get_sync_progress', null);
      
      expect(jQuery.ajax).toHaveBeenCalledTimes(1);
      const ajaxCall = jQuery.ajax.mock.calls[0][0];
      expect(ajaxCall.data.action).toBe('mia_get_sync_progress');
    });
    
    it('debe manejar options null', () => {
      AjaxManager.call('mia_get_sync_progress', {}, null, null, null);
      
      expect(jQuery.ajax).toHaveBeenCalledTimes(1);
      // No debe lanzar error
    });
    
    it('debe manejar miIntegracionApiDashboard como objeto vacío', () => {
      global.miIntegracionApiDashboard = {};
      const errorCallback = jest.fn();
      const consoleErrorSpy = jest.spyOn(console, 'error').mockImplementation();
      
      AjaxManager.call('mia_get_sync_progress', {}, null, errorCallback);
      
      expect(consoleErrorSpy).toHaveBeenCalled();
      expect(errorCallback).toHaveBeenCalled();
      
      consoleErrorSpy.mockRestore();
    });
  });
  
});

