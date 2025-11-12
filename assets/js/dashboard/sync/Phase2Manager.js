/**
 * Gestor de Fase 2: Sincronización de Productos
 *
 * Gestiona la Fase 2 de la sincronización en dos fases, que consiste en
 * sincronizar los productos después de que se hayan sincronizado las imágenes
 * en la Fase 1.
 *
 * @module sync/Phase2Manager
 * @namespace Phase2Manager
 * @since 1.0.0
 * @author Christian
 */

/* global jQuery, miIntegracionApiDashboard, DASHBOARD_CONFIG, DOM_CACHE, pollingManager, ErrorHandler, SyncStateManager, checkSyncProgress, window */

// ✅ NUEVO: Sistema de throttling para logs de advertencia
let lastWarningTime = 0;
const WARNING_THROTTLE_MS = 5000; // Solo mostrar advertencia cada 5 segundos

/**
 * Log de advertencia con throttling para evitar spam en consola
 *
 * @param {string} message - Mensaje a mostrar
 * @returns {void}
 * @private
 */
function throttledWarn(message) {
  const now = Date.now();
  if (now - lastWarningTime > WARNING_THROTTLE_MS) {
    // eslint-disable-next-line no-console
    console.warn(message);
    lastWarningTime = now;
  }
}

/**
 * Maneja la respuesta exitosa de iniciar Fase 2
 *
 * @returns {void}
 * @private
 */
function handleSuccess() {
  // ✅ PROTECCIÓN: Evitar múltiples inicializaciones con throttling
  if (typeof window !== 'undefined' && window.phase2Initialized) {
    throttledWarn('⚠️ Fase 2 ya fue inicializada, ignorando llamada duplicada');
    return;
  }

  // Marcar como inicializado
  if (typeof window !== 'undefined') {
    window.phase2Initialized = true;
  }

  // eslint-disable-next-line no-console
  console.log('✅ Fase 2 (productos) iniciada correctamente');

  if (DOM_CACHE && DOM_CACHE.$feedback) {
    DOM_CACHE.$feedback.text('Fase 2: Sincronizando productos...');
  }

  // ✅ NUEVO: Emitir evento de inicio de Fase 2 a través de PollingManager
  if (typeof window !== 'undefined' && window.pollingManager && typeof window.pollingManager.emit === 'function') {
    window.pollingManager.emit('syncProgress', {
      syncData: {
        in_progress: true,
        phase: 2,
        message: 'Fase 2: Sincronizando productos...'
      },
      phase1Status: {
        in_progress: false,
        completed: true
      },
      timestamp: Date.now()
    });
    // eslint-disable-next-line no-console
    console.log('[Phase2Manager] ✅ Evento syncProgress emitido (inicio de Fase 2)');
  }

  // Resetear configuración de polling para Fase 2
  if (typeof pollingManager !== 'undefined' && pollingManager && pollingManager.config) {
    // eslint-disable-next-line prefer-optional-chain
    if (pollingManager.config.intervals && pollingManager.config.intervals.active) {
      pollingManager.config.currentInterval = pollingManager.config.intervals.active;
    }
    pollingManager.config.currentMode = 'active';
    pollingManager.config.errorCount = 0;
  }

  // Resetear contador de progreso inactivo usando SyncStateManager
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager && typeof SyncStateManager.setInactiveProgressCounter === 'function') {
    SyncStateManager.setInactiveProgressCounter(0);
  }

  // ✅ PROTECCIÓN: Verificar si ya hay polling activo antes de iniciar uno nuevo
  if (typeof pollingManager !== 'undefined' && pollingManager && typeof pollingManager.isPollingActive === 'function') {
    if (pollingManager.isPollingActive('syncProgress')) {
      throttledWarn('⚠️ Polling de syncProgress ya está activo, no se iniciará uno nuevo');
      return;
    }
  }

  // Iniciar polling para monitorear Fase 2
  // NOTA: checkSyncProgress ya emite eventos automáticamente cuando recibe datos
  if (typeof pollingManager !== 'undefined' && pollingManager && typeof pollingManager.startPolling === 'function' && typeof checkSyncProgress === 'function') {
    setTimeout(function() {
      // ✅ PROTECCIÓN ADICIONAL: Verificar nuevamente antes de iniciar (por si acaso)
      if (pollingManager.isPollingActive('syncProgress')) {
        throttledWarn('⚠️ Polling de syncProgress ya está activo (verificación tardía), no se iniciará uno nuevo');
        return;
      }
      
      const intervalId = pollingManager.startPolling('syncProgress', checkSyncProgress, pollingManager.config.currentInterval);
      // Exponer syncInterval en window si existe (compatibilidad con código original)
      if (typeof window !== 'undefined') {
        try {
          window.syncInterval = intervalId;
        } catch (error) {
          // Ignorar si no se puede asignar
        }
      }
      // eslint-disable-next-line no-console
      console.log('✅ Polling de Fase 2 iniciado con ID:', intervalId);
    }, 500);
  }
}

/**
 * Maneja errores al iniciar Fase 2
 *
 * @param {Object} xhr - Objeto XMLHttpRequest
 * @param {string} status - Estado de la petición
 * @param {string} error - Mensaje de error
 * @returns {void}
 * @private
 */
function handleError(xhr, status, error) {
  // eslint-disable-next-line no-console
  console.error('❌ Error al iniciar Fase 2:', error);

  // ✅ NUEVO: Emitir evento de error a través de PollingManager
  if (typeof window !== 'undefined' && window.pollingManager && typeof window.pollingManager.emit === 'function') {
    window.pollingManager.emit('syncError', {
      message: error || 'Error al iniciar Fase 2',
      status: status,
      xhr: xhr,
      phase: 2,
      timestamp: Date.now()
    });
    // eslint-disable-next-line no-console
    console.log('[Phase2Manager] ✅ Evento syncError emitido a través de PollingManager');
  }

  if (DOM_CACHE && DOM_CACHE.$feedback) {
    DOM_CACHE.$feedback.text('Error al iniciar Fase 2: ' + (error || 'Error de comunicación'));
  }

  if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
    ErrorHandler.logError('Error al iniciar Fase 2', 'SYNC_START');
  }

  const originalText = (typeof window !== 'undefined' && window.originalSyncButtonText) || 'Sincronizar productos en lote';

  if (DOM_CACHE && DOM_CACHE.$syncBtn) {
    DOM_CACHE.$syncBtn.prop('disabled', false).text(originalText);
  }

  if (DOM_CACHE && DOM_CACHE.$batchSizeSelector) {
    DOM_CACHE.$batchSizeSelector.prop('disabled', false);
  }
}

/**
 * Inicia la Fase 2: Sincronización de Productos
 *
 * @returns {void}
 *
 * @example
 * Phase2Manager.start();
 */
function start() {
  // ✅ PROTECCIÓN CRÍTICA: Evitar múltiples llamadas simultáneas
  if (typeof window !== 'undefined' && window.phase2Starting) {
    throttledWarn('⚠️ Fase 2 ya se está iniciando, ignorando llamada duplicada');
    return;
  }
  
  // ✅ PROTECCIÓN: Verificar si ya está inicializada
  if (typeof window !== 'undefined' && window.phase2Initialized) {
    throttledWarn('⚠️ Fase 2 ya fue inicializada, ignorando llamada duplicada');
    return;
  }
  
  // Marcar como iniciando
  if (typeof window !== 'undefined') {
    window.phase2Starting = true;
  }
  
  // Verificar dependencias críticas
  if (typeof jQuery === 'undefined') {
    if (typeof window !== 'undefined') {
      window.phase2Starting = false;
    }
    if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
      ErrorHandler.logError('jQuery no está disponible para Phase2Manager', 'PHASE2_START');
    }
    return;
  }

  // eslint-disable-next-line prefer-optional-chain
  if (typeof miIntegracionApiDashboard === 'undefined' || !miIntegracionApiDashboard || !miIntegracionApiDashboard.ajaxurl) {
    if (typeof window !== 'undefined') {
      window.phase2Starting = false;
    }
    if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
      ErrorHandler.logError('miIntegracionApiDashboard o ajaxurl no están disponibles', 'PHASE2_START');
    }
    return;
  }

  // eslint-disable-next-line prefer-optional-chain
  if (typeof DOM_CACHE === 'undefined' || !DOM_CACHE) {
    if (typeof window !== 'undefined') {
      window.phase2Starting = false;
    }
    if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
      ErrorHandler.logError('DOM_CACHE no está disponible', 'PHASE2_START');
    }
    return;
  }

  // eslint-disable-next-line no-console
  console.log('🚀 Iniciando Fase 2 (sincronización de productos)...');

  if (DOM_CACHE.$feedback) {
    DOM_CACHE.$feedback.text('Fase 2: Sincronizando productos...');
  }

  // Obtener batch_size desde window.pendingPhase2BatchSize o usar valor por defecto
  const batchSize = (typeof window !== 'undefined' && window.pendingPhase2BatchSize) || 20;

  // Lanzar AJAX para iniciar Fase 2 (sincronización de productos)
  const timeout = (DASHBOARD_CONFIG && DASHBOARD_CONFIG.timeouts && DASHBOARD_CONFIG.timeouts.ajax)
    ? DASHBOARD_CONFIG.timeouts.ajax * 4
    : 240000;

  jQuery.ajax({
    url: miIntegracionApiDashboard.ajaxurl,
    type: 'POST',
    timeout: timeout,
    data: {
      action: 'mi_integracion_api_sync_products_batch',
      nonce: miIntegracionApiDashboard.nonce || (typeof window !== 'undefined' && window.miIntegracionApiDashboard && window.miIntegracionApiDashboard.nonce),
      batch_size: batchSize
    },
    success: function(response) {
      // ✅ Resetear flag de inicio después de recibir respuesta
      if (typeof window !== 'undefined') {
        window.phase2Starting = false;
      }
      
      if (response.success) {
        handleSuccess();
      } else {
        // Manejar respuesta con error
        const errorMsg = (response.data && response.data.message) || 'Error desconocido';
        handleError(null, 'error', errorMsg);
      }
    },
    error: function(xhr, status, error) {
      // ✅ Resetear flag de inicio en caso de error
      if (typeof window !== 'undefined') {
        window.phase2Starting = false;
      }
      handleError(xhr, status, error);
    }
  });
}

/**
 * Resetea el estado de inicialización de Fase 2
 * Útil cuando la sincronización se completa o se cancela
 *
 * @returns {void}
 * @public
 */
function reset() {
  // ✅ MEJORADO: Resetear flags de inicialización e inicio
  if (typeof window !== 'undefined') {
    window.phase2Initialized = false;
    window.phase2Starting = false;
  }
  
  // ✅ NUEVO: Detener polling de syncProgress si está activo
  if (typeof pollingManager !== 'undefined' && pollingManager) {
    if (typeof pollingManager.stopPolling === 'function') {
      pollingManager.stopPolling('syncProgress');
    }
    // ✅ NUEVO: También detener todos los polling relacionados con syncProgress
    if (typeof pollingManager.stopAllPolling === 'function') {
      // Detener todos y luego reiniciar solo los necesarios si es necesario
      pollingManager.stopAllPolling();
    }
  }
  
  // ✅ NUEVO: Limpiar intervalos globales si existen
  if (typeof window !== 'undefined') {
    if (window.syncInterval) {
      try {
        clearInterval(window.syncInterval);
        window.syncInterval = null;
      } catch (error) {
        // Ignorar errores al limpiar
      }
    }
    
    // ✅ NUEVO: Limpiar cualquier otro intervalo relacionado
    if (window.phase2PollingInterval) {
      try {
        clearInterval(window.phase2PollingInterval);
        window.phase2PollingInterval = null;
      } catch (error) {
        // Ignorar errores al limpiar
      }
    }
  }
  
  // ✅ NUEVO: Resetear flag de procesamiento de batch
  if (typeof window !== 'undefined') {
    window.phase2ProcessingBatch = false;
  }
  
  // ✅ NUEVO: Resetear contador de throttling
  lastWarningTime = 0;
  
  // ✅ NUEVO: Solo loguear si realmente se hizo algo (evitar spam)
  // eslint-disable-next-line no-console
  console.log('🔄 Estado de Fase 2 reseteado (polling detenido)');
}

/**
 * Procesa el siguiente lote automáticamente cuando WordPress Cron no funciona
 * 
 * Esta función se llama desde SyncProgress.js cuando se detecta que hay lotes
 * pendientes y el progreso se ha detenido (más de 15 segundos sin cambios).
 * 
 * @returns {void}
 * @public
 */
function processNextBatchAutomatically() {
  // Verificar que jQuery y miIntegracionApiDashboard estén disponibles
  if (typeof jQuery === 'undefined' || typeof miIntegracionApiDashboard === 'undefined' || !miIntegracionApiDashboard.ajaxurl) {
    // eslint-disable-next-line no-console
    console.warn('⚠️ No se puede procesar siguiente lote automáticamente: jQuery o ajaxurl no disponibles');
    return;
  }
  
  // Evitar múltiples llamadas simultáneas usando un flag global
  if (typeof window !== 'undefined' && window.phase2ProcessingBatch) {
    // eslint-disable-next-line no-console
    console.log('ℹ️ Ya hay un lote siendo procesado, esperando...');
    return;
  }
  
  // Marcar como procesando
  if (typeof window !== 'undefined') {
    window.phase2ProcessingBatch = true;
  }
  
  // Llamar al endpoint de procesamiento de cola en background
  jQuery.ajax({
    url: miIntegracionApiDashboard.ajaxurl,
    type: 'POST',
    timeout: 30000, // 30 segundos de timeout
    data: {
      action: 'mia_process_queue_background',
      nonce: miIntegracionApiDashboard.nonce || ''
    },
    success: function(response) {
      // eslint-disable-next-line no-console
      console.log('✅ Siguiente lote procesado automáticamente desde Phase2Manager', response);
      
      // Resetear flag después de un breve delay para permitir siguiente procesamiento
      setTimeout(() => {
        if (typeof window !== 'undefined') {
          window.phase2ProcessingBatch = false;
        }
      }, 5000); // 5 segundos de cooldown
    },
    error: function(xhr, status, error) {
      // eslint-disable-next-line no-console
      console.warn('⚠️ Error al procesar siguiente lote automáticamente', {
        status,
        error,
        xhr: xhr.status
      });
      
      // Resetear flag incluso en caso de error
      if (typeof window !== 'undefined') {
        window.phase2ProcessingBatch = false;
      }
      
      // No es crítico, WordPress Cron puede procesarlo más tarde
    }
  });
}

/**
 * Objeto Phase2Manager con métodos públicos
 */
const Phase2Manager = {
  start,
  reset,
  processNextBatchAutomatically
};

/**
 * Exponer Phase2Manager globalmente para mantener compatibilidad
 * con el código existente que usa window.Phase2Manager y window.startPhase2
 */
// eslint-disable-next-line no-restricted-globals
if (typeof window !== 'undefined') {
  try {
    // eslint-disable-next-line no-restricted-globals
    window.Phase2Manager = Phase2Manager;
    // Exponer también la función start como startPhase2 para compatibilidad
    // eslint-disable-next-line no-restricted-globals
    window.startPhase2 = start;
  } catch (error) {
    try {
      // eslint-disable-next-line no-restricted-globals
      Object.defineProperty(window, 'Phase2Manager', {
        value: Phase2Manager,
        writable: true,
        enumerable: true,
        configurable: true
      });
      // eslint-disable-next-line no-restricted-globals
      Object.defineProperty(window, 'startPhase2', {
        value: start,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar Phase2Manager a window:', defineError, error);
      }
    }
  }
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { Phase2Manager };
}
