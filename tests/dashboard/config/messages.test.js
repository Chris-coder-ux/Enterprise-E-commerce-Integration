/**
 * Tests unitarios para messages.js
 * 
 * Verifica que MESSAGES esté correctamente definido y expuesto globalmente.
 * 
 * @module tests/dashboard/config/messages
 */

// Limpiar el módulo antes de cada test
beforeEach(function() {
  // Asegurar que window existe
  if (typeof global.window === 'undefined') {
    global.window = {};
  }

  // Eliminar MESSAGES de window primero
  if (global.window.MESSAGES) {
    delete global.window.MESSAGES;
  }

  // Limpiar require cache para forzar recarga
  const messagesPath = require.resolve('../../../assets/js/dashboard/config/messages.js');
  if (require.cache[messagesPath]) {
    delete require.cache[messagesPath];
  }
});

describe('messages.js - MESSAGES', function() {
  describe('Definición de MESSAGES', function() {
    test('MESSAGES debe estar definido', function() {
      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      // Verificar que el módulo se carga correctamente
      expect(messages).toBeDefined();
      expect(messages.MESSAGES).toBeDefined();
    });

    test('MESSAGES debe tener todas las categorías principales', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      expect(messages.MESSAGES).toBeDefined();
      expect(messages.MESSAGES).toHaveProperty('errors');
      expect(messages.MESSAGES).toHaveProperty('progress');
      expect(messages.MESSAGES).toHaveProperty('milestones');
      expect(messages.MESSAGES).toHaveProperty('status');
      expect(messages.MESSAGES).toHaveProperty('success');
      expect(messages.MESSAGES).toHaveProperty('tips');
    });
  });

  describe('Mensajes de error', function() {
    test('errors debe tener todos los mensajes de error', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const errors = messages.MESSAGES.errors;
      expect(errors).toHaveProperty('jqueryMissing');
      expect(errors).toHaveProperty('configMissing');
      expect(errors).toHaveProperty('ajaxUrlMissing');
      expect(errors).toHaveProperty('connectionError');
      expect(errors).toHaveProperty('permissionError');
      expect(errors).toHaveProperty('serverError');
      expect(errors).toHaveProperty('timeoutError');
      expect(errors).toHaveProperty('unknownError');

      // Verificar que todos son strings
      Object.keys(errors).forEach(function(key) {
        expect(typeof errors[key]).toBe('string');
        expect(errors[key].length).toBeGreaterThan(0);
      });
    });

    test('errors debe tener los valores correctos', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const errors = messages.MESSAGES.errors;
      expect(errors.jqueryMissing).toBe('jQuery no está disponible. El dashboard no funcionará.');
      expect(errors.configMissing).toBe('Variables de configuración incompletas. La sincronización fallará.');
      expect(errors.connectionError).toBe('Error de conexión. Verifique su conexión a internet.');
    });
  });

  describe('Mensajes de progreso', function() {
    test('progress debe tener todos los mensajes de progreso', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const progress = messages.MESSAGES.progress;
      expect(progress).toHaveProperty('preparing');
      expect(progress).toHaveProperty('verifying');
      expect(progress).toHaveProperty('connecting');
      expect(progress).toHaveProperty('processing');
      expect(progress).toHaveProperty('complete');
      expect(progress).toHaveProperty('productsProcessed');
      expect(progress).toHaveProperty('productsSynced');
      expect(progress).toHaveProperty('productsPerSec');
    });

    test('progress debe tener los valores correctos', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const progress = messages.MESSAGES.progress;
      expect(progress.preparing).toBe('Preparando sincronización... ');
      expect(progress.productsProcessed).toBe('productos procesados');
      expect(progress.productsSynced).toBe('productos sincronizados');
      expect(progress.productsPerSec).toBe('productos/seg');
    });
  });

  describe('Mensajes de hitos', function() {
    test('milestones debe tener todos los hitos', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const milestones = messages.MESSAGES.milestones;
      expect(milestones).toHaveProperty('start');
      expect(milestones).toHaveProperty('quarter');
      expect(milestones).toHaveProperty('half');
      expect(milestones).toHaveProperty('threeQuarters');
      expect(milestones).toHaveProperty('complete');
    });

    test('milestones debe tener los valores correctos', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const milestones = messages.MESSAGES.milestones;
      expect(milestones.start).toBe('Iniciando sincronización...');
      expect(milestones.quarter).toBe('25% completado');
      expect(milestones.half).toBe('50% completado');
      expect(milestones.threeQuarters).toBe('75% completado');
      expect(milestones.complete).toBe('¡Sincronización completada!');
    });
  });

  describe('Mensajes de estado', function() {
    test('status debe tener todos los estados', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const status = messages.MESSAGES.status;
      expect(status).toHaveProperty('pending');
      expect(status).toHaveProperty('running');
      expect(status).toHaveProperty('completed');
      expect(status).toHaveProperty('error');
      expect(status).toHaveProperty('paused');
    });

    test('status debe tener los valores correctos', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const status = messages.MESSAGES.status;
      expect(status.pending).toBe('Pendiente');
      expect(status.running).toBe('En Progreso');
      expect(status.completed).toBe('Completado');
      expect(status.error).toBe('Error');
      expect(status.paused).toBe('Pausado');
    });
  });

  describe('Mensajes de éxito', function() {
    test('success debe tener todos los mensajes de éxito', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const success = messages.MESSAGES.success;
      expect(success).toHaveProperty('batchSizeChanged');
    });

    test('success debe tener el valor correcto', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const success = messages.MESSAGES.success;
      expect(success.batchSizeChanged).toBe('Tamaño de lote cambiado a {size} productos');
    });
  });

  describe('Consejos y tips', function() {
    test('tips debe tener todos los tips', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const tips = messages.MESSAGES.tips;
      expect(tips).toHaveProperty('keyboardShortcut');
      expect(tips).toHaveProperty('generalTip');
    });

    test('tips debe tener los valores correctos', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const tips = messages.MESSAGES.tips;
      expect(tips.keyboardShortcut).toBe('Atajo de teclado: Ctrl+Enter para sincronizar');
      expect(tips.generalTip).toBe('💡 Tip: Usa Ctrl+Enter para iniciar sincronización rápida');
    });
  });

  describe('Exposición global', function() {
    test('MESSAGES debe estar disponible en window', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      // Verificar que el módulo exporta MESSAGES
      expect(messages.MESSAGES).toBeDefined();

      // Verificar que está en window (el código se ejecuta al hacer require)
      // Nota: En algunos entornos de test, window puede no estar disponible
      // pero el módulo debe exportar MESSAGES correctamente
      if (typeof global.window !== 'undefined') {
        expect(global.window.MESSAGES).toBeDefined();
        expect(typeof global.window.MESSAGES).toBe('object');
        expect(global.window.MESSAGES).toBe(messages.MESSAGES);
      }
    });

    test('MESSAGES debe ser el mismo objeto que el exportado', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      // Verificar que el módulo exporta MESSAGES
      expect(messages.MESSAGES).toBeDefined();

      // Verificar que está en window
      if (typeof global.window !== 'undefined') {
        expect(global.window.MESSAGES).toBeDefined();
        expect(global.window.MESSAGES).toBe(messages.MESSAGES);
      }
    });

    test('MESSAGES debe ser enumerable', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      // Verificar que está disponible
      expect(messages.MESSAGES).toBeDefined();

      if (typeof global.window !== 'undefined' && global.window.MESSAGES) {
        expect(global.window.MESSAGES).toBeDefined();

        const descriptor = Object.getOwnPropertyDescriptor(global.window, 'MESSAGES');
        if (descriptor) {
          expect(descriptor.enumerable).toBe(true);
        }
      }
    });
  });

  describe('Compatibilidad con código existente', function() {
    test('MESSAGES debe mantener la misma estructura que el original', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      // Verificar estructura completa
      expect(messages.MESSAGES).toHaveProperty('errors');
      expect(messages.MESSAGES).toHaveProperty('progress');
      expect(messages.MESSAGES).toHaveProperty('milestones');
      expect(messages.MESSAGES).toHaveProperty('status');
      expect(messages.MESSAGES).toHaveProperty('success');
      expect(messages.MESSAGES).toHaveProperty('tips');

      // Verificar que está en window
      expect(messages.MESSAGES).toBeDefined();
      if (typeof global.window !== 'undefined') {
        expect(global.window.MESSAGES).toBeDefined();
        expect(global.window.MESSAGES).toBe(messages.MESSAGES);
      }
    });

    test('MESSAGES.errors debe coincidir con los valores originales', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const errors = messages.MESSAGES.errors;
      expect(errors.jqueryMissing).toBe('jQuery no está disponible. El dashboard no funcionará.');
      expect(errors.configMissing).toBe('Variables de configuración incompletas. La sincronización fallará.');
      expect(errors.connectionError).toBe('Error de conexión. Verifique su conexión a internet.');
    });
  });

  describe('Mensajes adicionales', function() {
    test('progress debe incluir mensajes adicionales usados en el código', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const progress = messages.MESSAGES.progress;
      // Estos mensajes se usan en el código pero no estaban en dashboard-config.js
      expect(progress.productsProcessed).toBe('productos procesados');
      expect(progress.productsSynced).toBe('productos sincronizados');
      expect(progress.productsPerSec).toBe('productos/seg');
    });

    test('status debe incluir todos los estados usados en el código', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      const status = messages.MESSAGES.status;
      // Estos estados se usan en el código pero no estaban en dashboard-config.js
      expect(status.pending).toBe('Pendiente');
      expect(status.running).toBe('En Progreso');
      expect(status.completed).toBe('Completado');
      expect(status.error).toBe('Error');
      expect(status.paused).toBe('Pausado');
    });
  });

  describe('Valores de mensajes', function() {
    test('Todos los mensajes deben ser strings válidos', function() {
      global.window = {};

      // eslint-disable-next-line no-undef
      const messages = require('../../../assets/js/dashboard/config/messages.js');

      function checkMessages(obj) {
        Object.keys(obj).forEach(function(key) {
          const value = obj[key];
          if (typeof value === 'object' && value !== null) {
            checkMessages(value);
          } else {
            expect(typeof value).toBe('string');
            expect(value.length).toBeGreaterThan(0);
          }
        });
      }

      checkMessages(messages.MESSAGES);
    });
  });

  describe('Manejo de errores', function() {
    test('Debe funcionar cuando window no está definido', function() {
      const originalWindow = global.window;
      delete global.window;

      // No debe lanzar error
      expect(function() {
        // eslint-disable-next-line no-undef
        require('../../../assets/js/dashboard/config/messages.js');
      }).not.toThrow();

      // Restaurar window
      global.window = originalWindow;
    });
  });
});

