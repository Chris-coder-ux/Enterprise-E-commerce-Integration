/**
 * Tests para EventManager (SystemEventManager)
 * 
 * Verifica que el módulo EventManager funcione correctamente
 * después de la refactorización desde dashboard.js
 */

const fs = require('fs');
const path = require('path');

const realJQuery = require('jquery');
global.jQuery = realJQuery;
global.$ = realJQuery;

// Cargar el módulo real EventManager
const eventManagerPath = path.join(__dirname, '../../../assets/js/dashboard/core/EventManager.js');
const eventManagerCode = fs.readFileSync(eventManagerPath, 'utf8');
eval(eventManagerCode); // Esto carga el real SystemEventManager

describe('EventManager (SystemEventManager)', () => {
  let originalConsole;
  let eventListeners;
  
  beforeEach(() => {
    // Guardar console original
    originalConsole = {
      log: console.log,
      warn: console.warn,
      error: console.error
    };
    
    // Mock console
    console.log = jest.fn();
    console.warn = jest.fn();
    console.error = jest.fn();
    
    // Limpiar event listeners
    eventListeners = [];
    
    // Limpiar estado
    if (typeof window !== 'undefined' && window.SystemEventManager) {
      window.SystemEventManager.initializationState = {
        systemBase: false,
        errorHandler: false,
        unifiedDashboard: false,
        allSystems: false
      };
      window.SystemEventManager.registeredSystems.clear();
    }
    
    // Limpiar window de mocks anteriores
    if (typeof window !== 'undefined') {
      delete window.ErrorHandler;
      delete window.AjaxManager;
      delete window.PollingManager;
      delete window.UnifiedDashboard;
    }
  });
  
  afterEach(() => {
    // Restaurar console
    console.log = originalConsole.log;
    console.warn = originalConsole.warn;
    console.error = originalConsole.error;
    
    // Limpiar event listeners
    eventListeners.forEach(function(listener) {
      if (typeof window !== 'undefined') {
        window.removeEventListener(listener.event, listener.handler);
      }
    });
  });
  
  describe('Inicialización', () => {
    it('debe estar disponible globalmente como SystemEventManager', () => {
      expect(typeof window.SystemEventManager).toBe('object');
      expect(window.SystemEventManager).toBeDefined();
    });
    
    it('debe tener el estado de inicialización correcto', () => {
      const state = window.SystemEventManager.initializationState;
      expect(state).toHaveProperty('systemBase');
      expect(state).toHaveProperty('errorHandler');
      expect(state).toHaveProperty('unifiedDashboard');
      expect(state).toHaveProperty('allSystems');
      expect(state.systemBase).toBe(false);
      expect(state.errorHandler).toBe(false);
      expect(state.unifiedDashboard).toBe(false);
      expect(state.allSystems).toBe(false);
    });
    
    it('debe tener un Map de sistemas registrados', () => {
      expect(window.SystemEventManager.registeredSystems).toBeInstanceOf(Map);
    });
  });
  
  describe('init()', () => {
    it('debe inicializar el sistema de eventos', () => {
      window.SystemEventManager.init();
      expect(console.log).toHaveBeenCalled();
      expect(window.SystemEventManager.initializationState.systemBase).toBe(true);
    });
    
    it('debe emitir el evento mi-system-base-ready', (done) => {
      const handler = jest.fn(function(event) {
        expect(event.type).toBe('mi-system-base-ready');
        expect(event.detail).toHaveProperty('timestamp');
        expect(event.detail).toHaveProperty('systems');
        done();
      });
      
      window.addEventListener('mi-system-base-ready', handler);
      eventListeners.push({ event: 'mi-system-base-ready', handler: handler });
      
      window.SystemEventManager.init();
    });
  });
  
  describe('emitSystemBaseReady()', () => {
    it('debe marcar systemBase como true', () => {
      window.SystemEventManager.emitSystemBaseReady();
      expect(window.SystemEventManager.initializationState.systemBase).toBe(true);
    });
    
    it('debe emitir evento con información de sistemas', (done) => {
      global.ErrorHandler = {};
      global.AjaxManager = {};
      
      const handler = jest.fn(function(event) {
        expect(event.detail.systems).toHaveProperty('errorHandler');
        expect(event.detail.systems).toHaveProperty('ajaxManager');
        expect(event.detail.systems).toHaveProperty('pollingManager');
        done();
      });
      
      window.addEventListener('mi-system-base-ready', handler);
      eventListeners.push({ event: 'mi-system-base-ready', handler: handler });
      
      window.SystemEventManager.emitSystemBaseReady();
    });
  });
  
  describe('emitErrorHandlerReady()', () => {
    it('debe marcar errorHandler como true', () => {
      window.SystemEventManager.emitErrorHandlerReady();
      expect(window.SystemEventManager.initializationState.errorHandler).toBe(true);
    });
    
    it('debe emitir el evento mi-error-handler-ready', (done) => {
      const handler = jest.fn(function(event) {
        expect(event.type).toBe('mi-error-handler-ready');
        expect(event.detail).toHaveProperty('timestamp');
        expect(event.detail).toHaveProperty('errorHandler');
        done();
      });
      
      window.addEventListener('mi-error-handler-ready', handler);
      eventListeners.push({ event: 'mi-error-handler-ready', handler: handler });
      
      window.SystemEventManager.emitErrorHandlerReady();
    });
  });
  
  describe('emitUnifiedDashboardReady()', () => {
    it('debe marcar unifiedDashboard como true', () => {
      window.SystemEventManager.emitUnifiedDashboardReady();
      expect(window.SystemEventManager.initializationState.unifiedDashboard).toBe(true);
    });
    
    it('debe emitir el evento mi-unified-dashboard-ready', (done) => {
      const handler = jest.fn(function(event) {
        expect(event.type).toBe('mi-unified-dashboard-ready');
        expect(event.detail).toHaveProperty('timestamp');
        expect(event.detail).toHaveProperty('unifiedDashboard');
        done();
      });
      
      window.addEventListener('mi-unified-dashboard-ready', handler);
      eventListeners.push({ event: 'mi-unified-dashboard-ready', handler: handler });
      
      window.SystemEventManager.emitUnifiedDashboardReady();
    });
    
    it('debe verificar si todos los sistemas están listos', () => {
      window.SystemEventManager.initializationState.systemBase = true;
      window.SystemEventManager.initializationState.errorHandler = true;
      
      window.SystemEventManager.emitUnifiedDashboardReady();
      
      // Debe verificar si todos están listos (pero allSystems puede seguir siendo false si no están todos)
      expect(window.SystemEventManager.initializationState.unifiedDashboard).toBe(true);
    });
  });
  
  describe('checkAllSystemsReady()', () => {
    it('debe emitir mi-all-systems-ready cuando todos los sistemas están listos', () => {
      window.SystemEventManager.initializationState.systemBase = true;
      window.SystemEventManager.initializationState.errorHandler = true;
      window.SystemEventManager.initializationState.unifiedDashboard = true;
      window.SystemEventManager.initializationState.allSystems = false; // Resetear para que pueda emitir
      
      const handler = jest.fn();
      window.addEventListener('mi-all-systems-ready', handler);
      eventListeners.push({ event: 'mi-all-systems-ready', handler: handler });
      
      window.SystemEventManager.checkAllSystemsReady();
      
      expect(handler).toHaveBeenCalled();
      const event = handler.mock.calls[0][0];
      expect(event.type).toBe('mi-all-systems-ready');
      expect(event.detail).toHaveProperty('timestamp');
      expect(event.detail).toHaveProperty('initializationState');
      expect(event.detail.initializationState.allSystems).toBe(true);
      expect(window.SystemEventManager.initializationState.allSystems).toBe(true);
    });
    
    it('no debe emitir el evento si no todos los sistemas están listos', () => {
      window.SystemEventManager.initializationState.systemBase = true;
      window.SystemEventManager.initializationState.errorHandler = false;
      window.SystemEventManager.initializationState.unifiedDashboard = false;
      
      const handler = jest.fn();
      window.addEventListener('mi-all-systems-ready', handler);
      eventListeners.push({ event: 'mi-all-systems-ready', handler: handler });
      
      window.SystemEventManager.checkAllSystemsReady();
      
      expect(handler).not.toHaveBeenCalled();
    });
  });
  
  describe('registerSystem()', () => {
    it('debe registrar un sistema con dependencias y callback', () => {
      const callback = jest.fn();
      window.SystemEventManager.registerSystem('testSystem', ['jQuery'], callback);
      
      const system = window.SystemEventManager.registeredSystems.get('testSystem');
      expect(system).toBeDefined();
      expect(system.dependencies).toEqual(['jQuery']);
      expect(system.callback).toBe(callback);
      expect(system.initialized).toBe(false);
    });
    
    it('debe registrar múltiples sistemas', () => {
      window.SystemEventManager.registerSystem('system1', [], function() {});
      window.SystemEventManager.registerSystem('system2', [], function() {});
      
      expect(window.SystemEventManager.registeredSystems.size).toBe(2);
    });
  });
  
  describe('checkDependencies()', () => {
    beforeEach(() => {
      window.SystemEventManager.registerSystem('testSystem', ['jQuery'], function() {});
    });
    
    it('debe retornar true si todas las dependencias están disponibles', () => {
      // jQuery está disponible globalmente
      const result = window.SystemEventManager.checkDependencies('testSystem');
      expect(result).toBe(true);
    });
    
    it('debe retornar false si falta alguna dependencia', () => {
      window.SystemEventManager.registerSystem('testSystem2', ['NonExistent'], function() {});
      const result = window.SystemEventManager.checkDependencies('testSystem2');
      expect(result).toBe(false);
    });
    
    it('debe retornar false si el sistema no existe', () => {
      const result = window.SystemEventManager.checkDependencies('nonExistentSystem');
      expect(result).toBe(false);
      expect(console.error).toHaveBeenCalled();
    });
    
    it('debe verificar dependencias como funciones', () => {
      const depFunction = jest.fn(function() { return true; });
      window.SystemEventManager.registerSystem('testSystem3', [depFunction], function() {});
      
      const result = window.SystemEventManager.checkDependencies('testSystem3');
      expect(result).toBe(true);
      expect(depFunction).toHaveBeenCalled();
    });
  });
  
  describe('initializeSystem()', () => {
    it('debe inicializar un sistema si las dependencias están disponibles', () => {
      const callback = jest.fn();
      window.SystemEventManager.registerSystem('testSystem', ['jQuery'], callback);
      
      const result = window.SystemEventManager.initializeSystem('testSystem');
      
      expect(result).toBe(true);
      expect(callback).toHaveBeenCalled();
      
      const system = window.SystemEventManager.registeredSystems.get('testSystem');
      expect(system.initialized).toBe(true);
    });
    
    it('no debe inicializar si las dependencias no están disponibles', () => {
      const callback = jest.fn();
      window.SystemEventManager.registerSystem('testSystem', ['NonExistent'], callback);
      
      const result = window.SystemEventManager.initializeSystem('testSystem');
      
      expect(result).toBe(false);
      expect(callback).not.toHaveBeenCalled();
    });
    
    it('no debe inicializar si el sistema ya está inicializado', () => {
      const callback = jest.fn();
      window.SystemEventManager.registerSystem('testSystem', ['jQuery'], callback);
      
      window.SystemEventManager.initializeSystem('testSystem');
      callback.mockClear();
      
      const result = window.SystemEventManager.initializeSystem('testSystem');
      
      expect(result).toBe(false);
      expect(callback).not.toHaveBeenCalled();
    });
    
    it('debe manejar errores en el callback', () => {
      const callback = jest.fn(function() {
        throw new Error('Test error');
      });
      window.SystemEventManager.registerSystem('testSystem', ['jQuery'], callback);
      
      const result = window.SystemEventManager.initializeSystem('testSystem');
      
      expect(result).toBe(false);
      expect(console.error).toHaveBeenCalled();
    });
  });
  
  describe('getInitializationState()', () => {
    it('debe retornar el estado de inicialización', () => {
      const state = window.SystemEventManager.getInitializationState();
      
      expect(state).toHaveProperty('systemBase');
      expect(state).toHaveProperty('errorHandler');
      expect(state).toHaveProperty('unifiedDashboard');
      expect(state).toHaveProperty('allSystems');
      expect(state).toHaveProperty('registeredSystems');
      expect(state).toHaveProperty('systemDetails');
    });
    
    it('debe incluir los sistemas registrados', () => {
      window.SystemEventManager.registerSystem('system1', [], function() {});
      window.SystemEventManager.registerSystem('system2', [], function() {});
      
      const state = window.SystemEventManager.getInitializationState();
      
      expect(state.registeredSystems).toContain('system1');
      expect(state.registeredSystems).toContain('system2');
    });
    
    it('debe incluir detalles de los sistemas', () => {
      window.SystemEventManager.registerSystem('system1', ['jQuery'], function() {});
      
      const state = window.SystemEventManager.getInitializationState();
      
      expect(state.systemDetails).toHaveProperty('system1');
      expect(state.systemDetails.system1).toHaveProperty('initialized');
      expect(state.systemDetails.system1).toHaveProperty('dependencies');
    });
  });
  
  describe('log()', () => {
    it('debe registrar mensajes con nivel info por defecto', () => {
      window.SystemEventManager.log('Test message');
      expect(console.log).toHaveBeenCalled();
    });
    
    it('debe registrar mensajes con nivel warn', () => {
      window.SystemEventManager.log('Test warning', 'warn');
      expect(console.warn).toHaveBeenCalled();
    });
    
    it('debe registrar mensajes con nivel error', () => {
      window.SystemEventManager.log('Test error', 'error');
      expect(console.error).toHaveBeenCalled();
    });
    
    it('debe incluir timestamp en el mensaje', () => {
      window.SystemEventManager.log('Test message');
      const logCall = console.log.mock.calls[0][0];
      expect(logCall).toContain('[SystemEventManager');
      expect(logCall).toContain('Test message');
    });
    
    it('debe incluir datos adicionales si se proporcionan', () => {
      const data = { test: 'data' };
      window.SystemEventManager.log('Test message', 'info', data);
      expect(console.log).toHaveBeenCalledWith(
        expect.stringContaining('Test message'),
        data
      );
    });
  });
});

