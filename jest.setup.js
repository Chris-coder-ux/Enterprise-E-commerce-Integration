// Configuración global de Jest para el proyecto
require('@testing-library/jest-dom');

// Mock global de jQuery
global.$ = global.jQuery = require('jquery');

// Mock de window.confirm
global.confirm = jest.fn();

// Mock de window.alert
global.alert = jest.fn();

// Mock de console.log para evitar ruido en las pruebas
global.console = {
  ...console,
  log: jest.fn(),
  warn: jest.fn(),
  error: jest.fn(),
  info: jest.fn(),
  debug: jest.fn()
};

// Mock de DOM_CACHE
global.DOM_CACHE = {
  $syncBtn: {
    prop: jest.fn().mockReturnThis(),
    text: jest.fn().mockReturnThis(),
    addClass: jest.fn().mockReturnThis(),
    removeClass: jest.fn().mockReturnThis(),
    on: jest.fn()
  },
  $batchSizeSelector: {
    val: jest.fn().mockReturnValue('50'),
    prop: jest.fn().mockReturnThis()
  },
  $feedback: {
    addClass: jest.fn().mockReturnThis(),
    removeClass: jest.fn().mockReturnThis(),
    text: jest.fn().mockReturnThis()
  },
  $syncStatusContainer: {
    css: jest.fn().mockReturnThis(),
    hide: jest.fn().mockReturnThis()
  },
  $cancelBtn: {
    prop: jest.fn().mockReturnThis(),
    addClass: jest.fn().mockReturnThis(),
    removeClass: jest.fn().mockReturnThis()
  },
  $progressBar: {
    css: jest.fn().mockReturnThis()
  },
  $progressInfo: {
    text: jest.fn().mockReturnThis()
  }
};

// Mock de miIntegracionApiDashboard
global.miIntegracionApiDashboard = {
  ajaxurl: 'https://test.com/wp-admin/admin-ajax.php',
  nonce: 'test-nonce-123',
  restUrl: 'https://test.com/wp-json/mi-integracion-api/v1/',
  confirmSync: '¿Iniciar sincronización de productos? Esta acción puede tomar varios minutos.',
  confirmCancel: '¿Seguro que deseas cancelar la sincronización?',
  debug: '1'
};

// Mock de DASHBOARD_CONFIG
global.DASHBOARD_CONFIG = {
  timeouts: {
    ajax: 30000
  },
  messages: {
    progress: {
      preparing: 'Preparando sincronización...'
    }
  }
};

// Mock de ajaxurl
global.ajaxurl = 'https://test.com/wp-admin/admin-ajax.php';

// Mock de pollingManager
global.pollingManager = {
  config: {
    currentInterval: 1000,
    intervals: {
      active: 1000
    },
    currentMode: 'active',
    errorCount: 0
  },
  startPolling: jest.fn()
};

// Mock de syncInterval
global.syncInterval = null;

// Mock de inactiveProgressCounter y lastProgressValue
global.inactiveProgressCounter = 0;
global.lastProgressValue = 0;

// Mock de ErrorHandler
global.ErrorHandler = {
  logError: jest.fn(),
  showUIError: jest.fn(),
  showConnectionError: jest.fn()
};

// Mock de checkSyncProgress
global.checkSyncProgress = jest.fn();

// Mock de navigator.onLine
Object.defineProperty(navigator, 'onLine', {
  writable: true,
  value: true
});

// Mock de jQuery.ajax
global.jQuery = {
  ajax: jest.fn()
};

// Mock de setTimeout y clearTimeout
global.setTimeout = jest.fn((fn, delay) => {
  return setTimeout(fn, delay);
});

global.clearTimeout = jest.fn();
