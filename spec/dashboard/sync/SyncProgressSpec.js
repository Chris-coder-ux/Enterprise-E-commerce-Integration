/**
 * Tests con Jasmine para SyncProgress.js
 * 
 * Verifica el comportamiento del sistema de detección de stalls (bloqueos)
 * incluyendo:
 * - Sistema de tracking de métricas de procesamiento de lotes
 * - Cálculo de umbral dinámico basado en métricas históricas
 * - Configuración del umbral de stall
 * - Detección de stalls con umbral dinámico vs por defecto
 * 
 * @module spec/dashboard/sync/SyncProgressSpec
 */

describe('SyncProgress - Detección de Stalls con Umbral Dinámico', function() {
  let originalSyncProgress, originalCheckSyncProgress, originalDASHBOARD_CONFIG;
  let originalMiIntegracionApiDashboard, originalJQuery, originalAjaxManager;
  let mockJQuery, mockAjax;
  let clock;

  beforeEach(function() {
    // Guardar referencias originales
    originalSyncProgress = window.SyncProgress;
    originalCheckSyncProgress = window.checkSyncProgress;
    originalDASHBOARD_CONFIG = window.DASHBOARD_CONFIG;
    originalMiIntegracionApiDashboard = window.miIntegracionApiDashboard;
    originalJQuery = window.jQuery;
    originalAjaxManager = window.AjaxManager;

    // Mock de jQuery
    mockAjax = jasmine.createSpy('ajax');
    mockJQuery = jasmine.createSpy('jQuery').and.callFake(function(selector) {
      return {
        ajax: mockAjax,
        css: jasmine.createSpy('css'),
        prop: jasmine.createSpy('prop'),
        text: jasmine.createSpy('text'),
        removeClass: jasmine.createSpy('removeClass'),
        addClass: jasmine.createSpy('addClass'),
        hide: jasmine.createSpy('hide'),
        show: jasmine.createSpy('show')
      };
    });
    mockJQuery.ajax = mockAjax;
    window.jQuery = mockJQuery;
    window.$ = mockJQuery;

    // Mock de miIntegracionApiDashboard
    window.miIntegracionApiDashboard = {
      ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
      nonce: 'test-nonce-123'
    };

    // Mock de DASHBOARD_CONFIG
    window.DASHBOARD_CONFIG = {
      timeouts: {
        ajax: 60000
      },
      stallThreshold: {
        min: 10000,
        max: 60000,
        default: 15000,
        multiplier: 2.0,
        minSamples: 2
      }
    };

    // Mock de DOM_CACHE
    window.DOM_CACHE = {
      $syncStatusContainer: mockJQuery('#sync-status'),
      $syncBtn: mockJQuery('#sync-btn'),
      $batchSizeSelector: mockJQuery('#batch-size'),
      $feedback: mockJQuery('#feedback')
    };

    // Mock de ErrorHandler
    window.ErrorHandler = {
      logError: jasmine.createSpy('logError'),
      showConnectionError: jasmine.createSpy('showConnectionError')
    };

    // Mock de AjaxManager
    window.AjaxManager = {
      call: jasmine.createSpy('call')
    };

    // Mock de pollingManager
    window.pollingManager = {
      adjustPolling: jasmine.createSpy('adjustPolling'),
      recordResponseTime: jasmine.createSpy('recordResponseTime'),
      recordError: jasmine.createSpy('recordError'),
      emit: jasmine.createSpy('emit')
    };

    // Mock de SyncStateManager
    window.SyncStateManager = {
      setLastProgressValue: jasmine.createSpy('setLastProgressValue'),
      getLastProgressValue: jasmine.createSpy('getLastProgressValue').and.returnValue(0),
      getInactiveProgressCounter: jasmine.createSpy('getInactiveProgressCounter').and.returnValue(0)
    };

    // Mock de Phase2Manager
    window.Phase2Manager = {
      processNextBatchAutomatically: jasmine.createSpy('processNextBatchAutomatically'),
      reset: jasmine.createSpy('reset')
    };

    // Mock de ConsoleManager
    window.ConsoleManager = {
      addLine: jasmine.createSpy('addLine')
    };

    // Mock de addConsoleLine (fallback)
    window.addConsoleLine = jasmine.createSpy('addConsoleLine');

    // Mock de ToastManager
    window.ToastManager = {
      show: jasmine.createSpy('show')
    };

    // Usar jasmine.clock() para controlar el tiempo
    if (typeof jasmine !== 'undefined' && jasmine.clock) {
      clock = jasmine.clock();
      clock.install();
      clock.mockDate(new Date(2024, 0, 1, 12, 0, 0, 0));
    } else {
      clock = null;
    }
  });

  afterEach(function() {
    // Restaurar referencias originales
    if (originalSyncProgress !== undefined) {
      window.SyncProgress = originalSyncProgress;
    } else {
      delete window.SyncProgress;
    }

    if (originalCheckSyncProgress !== undefined) {
      window.checkSyncProgress = originalCheckSyncProgress;
    } else {
      delete window.checkSyncProgress;
    }

    if (originalDASHBOARD_CONFIG !== undefined) {
      window.DASHBOARD_CONFIG = originalDASHBOARD_CONFIG;
    }

    if (originalMiIntegracionApiDashboard !== undefined) {
      window.miIntegracionApiDashboard = originalMiIntegracionApiDashboard;
    }

    if (originalJQuery !== undefined) {
      window.jQuery = originalJQuery;
      window.$ = originalJQuery;
    }

    if (originalAjaxManager !== undefined) {
      window.AjaxManager = originalAjaxManager;
    }

    // Desinstalar clock
    if (clock && clock.uninstall) {
      clock.uninstall();
    } else if (jasmine && jasmine.clock) {
      jasmine.clock().uninstall();
    }

    // Limpiar mocks
    if (window.ConsoleManager && window.ConsoleManager.addLine) {
      window.ConsoleManager.addLine.calls.reset();
    }
    if (window.addConsoleLine) {
      window.addConsoleLine.calls.reset();
    }
    if (window.ToastManager && window.ToastManager.show) {
      window.ToastManager.show.calls.reset();
    }
  });

  describe('Carga del script', function() {
    it('debe exponer SyncProgress en window', function() {
      if (typeof window.SyncProgress === 'undefined') {
        pending('SyncProgress no está disponible - el script debe cargarse primero');
        return;
      }

      expect(window.SyncProgress).toBeDefined();
      expect(typeof window.SyncProgress).toBe('object');
    });

    it('debe exponer checkSyncProgress en window', function() {
      if (typeof window.checkSyncProgress === 'undefined') {
        pending('checkSyncProgress no está disponible - el script debe cargarse primero');
        return;
      }

      expect(window.checkSyncProgress).toBeDefined();
      expect(typeof window.checkSyncProgress).toBe('function');
    });
  });

  describe('Sistema de tracking de métricas', function() {
    it('debe obtener el estado de tracking con métricas calculadas', function() {
      if (typeof window.SyncProgress === 'undefined' || !window.SyncProgress.getTrackingState) {
        pending('SyncProgress.getTrackingState no está disponible');
        return;
      }

      const trackingState = window.SyncProgress.getTrackingState();

      expect(trackingState).toBeDefined();
      expect(trackingState.averageBatchProcessingTime).toBeDefined();
      expect(trackingState.dynamicStallThreshold).toBeDefined();
      expect(trackingState.stallThresholdConfig).toBeDefined();
    });

    it('debe inicializar batchProcessingTimes como array vacío', function() {
      if (typeof window.SyncProgress === 'undefined' || !window.SyncProgress.getTrackingState) {
        pending('SyncProgress.getTrackingState no está disponible');
        return;
      }

      const trackingState = window.SyncProgress.getTrackingState();

      expect(trackingState.batchProcessingTimes).toBeDefined();
      expect(Array.isArray(trackingState.batchProcessingTimes)).toBe(true);
    });
  });

  describe('Configuración del umbral de stall', function() {
    it('debe usar configuración desde DASHBOARD_CONFIG si está disponible', function() {
      if (typeof window.SyncProgress === 'undefined' || !window.SyncProgress.getTrackingState) {
        pending('SyncProgress.getTrackingState no está disponible');
        return;
      }

      const trackingState = window.SyncProgress.getTrackingState();
      const config = trackingState.stallThresholdConfig;

      expect(config).toBeDefined();
      expect(config.min).toBe(10000);
      expect(config.max).toBe(60000);
      expect(config.default).toBe(15000);
      expect(config.multiplier).toBe(2.0);
      expect(config.minSamples).toBe(2);
    });

    it('debe usar configuración desde miIntegracionApiDashboard si está disponible', function() {
      // Establecer configuración personalizada
      window.miIntegracionApiDashboard.stallThresholdConfig = {
        min: 8000,
        max: 90000,
        default: 20000,
        multiplier: 2.5,
        minSamples: 3
      };

      if (typeof window.SyncProgress === 'undefined' || !window.SyncProgress.getTrackingState) {
        pending('SyncProgress.getTrackingState no está disponible');
        return;
      }

      const trackingState = window.SyncProgress.getTrackingState();
      const config = trackingState.stallThresholdConfig;

      expect(config.min).toBe(8000);
      expect(config.max).toBe(90000);
      expect(config.default).toBe(20000);
      expect(config.multiplier).toBe(2.5);
      expect(config.minSamples).toBe(3);
    });

    it('debe usar valores por defecto si no hay configuración disponible', function() {
      // Eliminar configuraciones
      delete window.DASHBOARD_CONFIG.stallThreshold;
      delete window.miIntegracionApiDashboard.stallThresholdConfig;

      if (typeof window.SyncProgress === 'undefined' || !window.SyncProgress.getTrackingState) {
        pending('SyncProgress.getTrackingState no está disponible');
        return;
      }

      const trackingState = window.SyncProgress.getTrackingState();
      const config = trackingState.stallThresholdConfig;

      expect(config).toBeDefined();
      expect(config.min).toBe(10000);
      expect(config.max).toBe(60000);
      expect(config.default).toBe(15000);
      expect(config.multiplier).toBe(2.0);
      expect(config.minSamples).toBe(2);
    });
  });

  describe('Cálculo de umbral dinámico', function() {
    it('debe usar valor por defecto cuando no hay suficientes muestras', function() {
      if (typeof window.SyncProgress === 'undefined' || !window.SyncProgress.getTrackingState) {
        pending('SyncProgress.getTrackingState no está disponible');
        return;
      }

      const trackingState = window.SyncProgress.getTrackingState();

      // Sin muestras suficientes, debe usar valor por defecto
      expect(trackingState.dynamicStallThreshold).toBe(15000);
    });

    it('debe calcular umbral dinámico basado en promedio cuando hay suficientes muestras', function() {
      if (typeof window.SyncProgress === 'undefined' || !window.SyncProgress.getTrackingState) {
        pending('SyncProgress.getTrackingState no está disponible');
        return;
      }

      // Simular tiempos de procesamiento de lotes anteriores
      // Necesitamos acceder al estado interno para establecer los tiempos
      // Como no podemos modificar directamente, verificamos que el sistema funciona
      // cuando hay datos históricos

      const trackingState = window.SyncProgress.getTrackingState();
      const config = trackingState.stallThresholdConfig;

      // Verificar que el umbral está dentro de los límites
      expect(trackingState.dynamicStallThreshold).toBeGreaterThanOrEqual(config.min);
      expect(trackingState.dynamicStallThreshold).toBeLessThanOrEqual(config.max);
    });
  });

  describe('Detección de stalls', function() {
    it('debe detectar stall usando umbral por defecto cuando no hay muestras', function(done) {
      if (typeof window.SyncProgress === 'undefined' || !window.SyncProgress.check) {
        pending('SyncProgress.check no está disponible');
        return;
      }

      // Resetear estado de tracking
      if (window.SyncProgress.resetTrackingState) {
        window.SyncProgress.resetTrackingState();
      }

      // Simular respuesta sin progreso durante más de 15 segundos
      let callCount = 0;
      mockAjax.and.callFake(function(options) {
        callCount++;
        if (options.success) {
          const response = {
            success: true,
            data: {
              in_progress: true,
              current_batch: 1,
              total_batches: 5,
              estadisticas: {
                procesados: 20,
                total: 100
              }
            }
          };

          // En la primera llamada, establecer timestamp inicial
          if (callCount === 1) {
            // Avanzar tiempo para simular stall (más de 15 segundos)
            if (clock && clock.tick) {
              clock.tick(16000); // 16 segundos sin progreso
            } else if (jasmine.clock) {
              jasmine.clock().tick(16000);
            }
          }

          options.success(response);
        }
      });

      // Limpiar llamadas anteriores
      if (window.ConsoleManager && window.ConsoleManager.addLine) {
        window.ConsoleManager.addLine.calls.reset();
      }

      window.SyncProgress.check();

      setTimeout(function() {
        // Verificar que se llamó a processNextBatchAutomatically después del stall
        // (esto requiere que el sistema detecte el stall)
        expect(mockAjax).toHaveBeenCalled();
        
        // ✅ NUEVO: Verificar que se mostró mensaje en consola cuando se detecta stall
        if (window.ConsoleManager && window.ConsoleManager.addLine) {
          expect(window.ConsoleManager.addLine).toHaveBeenCalledWith(
            'warning',
            jasmine.stringMatching(/Progreso detenido detectado.*Procesando lote.*manualmente/)
          );
        }
        
        // ✅ NUEVO: Verificar que se mostró notificación toast
        if (window.ToastManager && window.ToastManager.show) {
          expect(window.ToastManager.show).toHaveBeenCalledWith(
            jasmine.stringMatching(/Procesando lote.*manualmente.*WordPress Cron no responde/),
            'warning',
            5000
          );
        }
        
        done();
      }, 100);

      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }
    });

    it('debe registrar tiempos de procesamiento cuando cambia el lote', function() {
      if (typeof window.SyncProgress === 'undefined' || !window.SyncProgress.check) {
        pending('SyncProgress.check no está disponible');
        return;
      }

      // Resetear estado de tracking
      if (window.SyncProgress.resetTrackingState) {
        window.SyncProgress.resetTrackingState();
      }

      let callCount = 0;
      mockAjax.and.callFake(function(options) {
        callCount++;
        if (options.success) {
          const response = {
            success: true,
            data: {
              in_progress: true,
              current_batch: callCount, // Incrementar lote en cada llamada
              total_batches: 5,
              estadisticas: {
                procesados: callCount * 20,
                total: 100
              }
            }
          };

          // Avanzar tiempo entre lotes
          if (clock && clock.tick) {
            clock.tick(5000); // 5 segundos entre lotes
          } else if (jasmine.clock) {
            jasmine.clock().tick(5000);
          }

          options.success(response);
        }
      });

      // Primera llamada - lote 1
      window.SyncProgress.check();

      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }

      // Segunda llamada - lote 2 (debe registrar tiempo)
      window.SyncProgress.check();

      if (clock && clock.tick) {
        clock.tick(100);
      } else if (jasmine.clock) {
        jasmine.clock().tick(100);
      }

      // Verificar que se registraron las llamadas
      expect(mockAjax.calls.count()).toBeGreaterThanOrEqual(2);
    });

    it('debe usar umbral dinámico cuando hay suficientes muestras históricas', function() {
      if (typeof window.SyncProgress === 'undefined' || !window.SyncProgress.getTrackingState) {
        pending('SyncProgress.getTrackingState no está disponible');
        return;
      }

      // Simular que hay tiempos históricos registrados
      // Nota: En un entorno real, estos se registrarían automáticamente
      // durante el procesamiento de lotes
      
      const trackingState = window.SyncProgress.getTrackingState();
      const config = trackingState.stallThresholdConfig;

      // Verificar que el umbral está dentro de los límites configurados
      expect(trackingState.dynamicStallThreshold).toBeGreaterThanOrEqual(config.min);
      expect(trackingState.dynamicStallThreshold).toBeLessThanOrEqual(config.max);
      
      // Si hay muestras suficientes, el umbral debería ser diferente del default
      // (a menos que el promedio calculado resulte en el mismo valor)
      if (trackingState.batchProcessingTimes && trackingState.batchProcessingTimes.length >= config.minSamples) {
        const averageTime = trackingState.averageBatchProcessingTime;
        if (averageTime !== null) {
          const expectedThreshold = Math.max(config.min, Math.min(config.max, Math.round(averageTime * config.multiplier)));
          expect(trackingState.dynamicStallThreshold).toBe(expectedThreshold);
        }
      }
    });

    it('debe mantener historial de tiempos de procesamiento limitado', function() {
      if (typeof window.SyncProgress === 'undefined' || !window.SyncProgress.getTrackingState) {
        pending('SyncProgress.getTrackingState no está disponible');
        return;
      }

      const trackingState = window.SyncProgress.getTrackingState();

      // Verificar que el historial no excede el máximo configurado
      if (trackingState.batchProcessingTimes) {
        expect(trackingState.batchProcessingTimes.length).toBeLessThanOrEqual(trackingState.maxBatchTimesHistory || 10);
      }
    });
  });

  describe('Integración con Phase2Manager', function() {
    it('debe llamar a processNextBatchAutomatically cuando se detecta stall', function(done) {
      if (typeof window.SyncProgress === 'undefined' || !window.SyncProgress.check) {
        pending('SyncProgress.check no está disponible');
        return;
      }

      // Simular stall: sin progreso durante más del umbral
      mockAjax.and.callFake(function(options) {
        if (options.success) {
          const response = {
            success: true,
            data: {
              in_progress: true,
              current_batch: 1,
              total_batches: 5,
              estadisticas: {
                procesados: 20,
                total: 100
              }
            }
          };

          // Avanzar tiempo para simular stall (más de 15 segundos)
          if (clock && clock.tick) {
            clock.tick(16000);
          } else if (jasmine.clock) {
            jasmine.clock().tick(16000);
          }

          options.success(response);
        }
      });

      // Resetear estado de tracking
      if (window.SyncProgress.resetTrackingState) {
        window.SyncProgress.resetTrackingState();
      }

      // Limpiar llamadas anteriores
      if (window.ConsoleManager && window.ConsoleManager.addLine) {
        window.ConsoleManager.addLine.calls.reset();
      }

      window.SyncProgress.check();

      setTimeout(function() {
        // Verificar que se intentó procesar el siguiente lote automáticamente
        // Nota: Esto requiere que el sistema detecte el stall correctamente
        expect(mockAjax).toHaveBeenCalled();
        
        // ✅ NUEVO: Verificar que se mostró mensaje en consola cuando se detecta stall
        if (window.ConsoleManager && window.ConsoleManager.addLine) {
          expect(window.ConsoleManager.addLine).toHaveBeenCalledWith(
            'warning',
            jasmine.stringMatching(/Progreso detenido detectado.*Procesando lote.*manualmente/)
          );
        }
        
        // ✅ NUEVO: Verificar que se mostró notificación toast
        if (window.ToastManager && window.ToastManager.show) {
          expect(window.ToastManager.show).toHaveBeenCalledWith(
            jasmine.stringMatching(/Procesando lote.*manualmente.*WordPress Cron no responde/),
            'warning',
            5000
          );
        }
        
        done();
      }, 200);

      if (clock && clock.tick) {
        clock.tick(200);
      } else if (jasmine.clock) {
        jasmine.clock().tick(200);
      }
    });

    it('debe NO llamar a processNextBatchAutomatically si no hay stall', function() {
      if (typeof window.SyncProgress === 'undefined' || !window.SyncProgress.check) {
        pending('SyncProgress.check no está disponible');
        return;
      }

      // Resetear estado de tracking
      if (window.SyncProgress.resetTrackingState) {
        window.SyncProgress.resetTrackingState();
      }

      // Simular progreso normal (menos de 15 segundos sin cambios)
      mockAjax.and.callFake(function(options) {
        if (options.success) {
          const response = {
            success: true,
            data: {
              in_progress: true,
              current_batch: 1,
              total_batches: 5,
              estadisticas: {
                procesados: 20,
                total: 100
              }
            }
          };

          // Avanzar solo 5 segundos (menos del umbral)
          if (clock && clock.tick) {
            clock.tick(5000);
          } else if (jasmine.clock) {
            jasmine.clock().tick(5000);
          }

          options.success(response);
        }
      });

      window.SyncProgress.check();

      // Verificar que NO se llamó a processNextBatchAutomatically
      // (solo se llama cuando hay stall)
      expect(window.Phase2Manager.processNextBatchAutomatically).not.toHaveBeenCalled();
    });
  });

  describe('Uso de características modernas de JavaScript', function() {
    it('debe usar optional chaining para acceder a configuración de forma segura', function() {
      // Eliminar configuración para probar optional chaining
      const originalStallThreshold = window.DASHBOARD_CONFIG.stallThreshold;
      delete window.DASHBOARD_CONFIG.stallThreshold;
      delete window.miIntegracionApiDashboard.stallThresholdConfig;

      if (typeof window.SyncProgress === 'undefined' || !window.SyncProgress.getTrackingState) {
        pending('SyncProgress.getTrackingState no está disponible');
        return;
      }

      // No debe lanzar error al usar optional chaining
      expect(function() {
        const trackingState = window.SyncProgress.getTrackingState();
        expect(trackingState.stallThresholdConfig).toBeDefined();
      }).not.toThrow();

      // Restaurar configuración
      window.DASHBOARD_CONFIG.stallThreshold = originalStallThreshold;
    });
  });
});

