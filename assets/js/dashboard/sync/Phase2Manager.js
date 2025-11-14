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

/* global jQuery, miIntegracionApiDashboard, DASHBOARD_CONFIG, DOM_CACHE, pollingManager, ErrorHandler, SyncStateManager, window, ToastManager */

// ✅ NUEVO: Sistema de throttling para logs de advertencia
let lastWarningTime = 0;
const WARNING_THROTTLE_MS = 5000; // Solo mostrar advertencia cada 5 segundos

// ✅ NUEVO: Sistema de throttling para logs de debug
let lastDebugTime = 0;
const DEBUG_THROTTLE_MS = 5000; // Solo mostrar debug cada 5 segundos

/**
 * Log de advertencia con throttling para evitar spam en consola
 *
 * @param {string} message - Mensaje a mostrar
 * @returns {void}
 * @private
 */
// eslint-disable-next-line no-unused-vars
function throttledWarn(message) {
  const now = Date.now();
  if (now - lastWarningTime > WARNING_THROTTLE_MS) {
    // eslint-disable-next-line no-console
    console.warn(message);
    lastWarningTime = now;
  }
}

/**
 * Log de debug con throttling para mensajes informativos (no son errores)
 *
 * @param {string} message - Mensaje a mostrar
 * @returns {void}
 * @private
 */
function throttledDebug(message) {
  const now = Date.now();
  if (now - lastDebugTime > DEBUG_THROTTLE_MS) {
    // eslint-disable-next-line no-console
    if (typeof console !== 'undefined' && console.debug) {
      console.debug(message);
    } else if (typeof console !== 'undefined' && console.log) {
      // Fallback para navegadores que no tienen console.debug
      console.log(message);
    }
    lastDebugTime = now;
  }
}

/**
 * Maneja la respuesta exitosa de iniciar Fase 2
 *
 * @returns {void}
 * @private
 */
function handlePhase2StartSuccess() {
  // ✅ PROTECCIÓN: Evitar múltiples inicializaciones con throttling
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager.getPhase2Initialized()) {
    throttledDebug('ℹ️ Fase 2 ya fue inicializada, ignorando llamada duplicada');
    return;
  }

  // Marcar como inicializado usando SyncStateManager
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager.setPhase2Initialized) {
    SyncStateManager.setPhase2Initialized(true);
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

  // ✅ SIMPLIFICADO: PollingManager previene duplicaciones automáticamente
  // Ya no necesitamos verificaciones redundantes ni setTimeout
  // Iniciar polling para monitorear Fase 2 directamente
  // ✅ MEJORADO: Usar window.checkSyncProgress explícitamente para compatibilidad con TypeScript/ESLint
  const checkSyncProgressFn = (typeof window !== 'undefined' && window.checkSyncProgress) 
    ? window.checkSyncProgress 
    : null;
  
  if (typeof pollingManager !== 'undefined' && pollingManager && typeof pollingManager.startPolling === 'function' && checkSyncProgressFn && typeof checkSyncProgressFn === 'function') {
    // Obtener intervalo configurado para modo activo
    const activeInterval = pollingManager.config && pollingManager.config.intervals && pollingManager.config.intervals.active
      ? pollingManager.config.intervals.active
      : 2000; // Fallback a 2 segundos
    
    // ✅ SIMPLIFICADO: Iniciar polling directamente - PollingManager previene duplicaciones
    const intervalId = pollingManager.startPolling('syncProgress', checkSyncProgressFn, activeInterval);
    
    // Guardar syncInterval usando SyncStateManager (mantiene compatibilidad con window.syncInterval)
    if (typeof SyncStateManager !== 'undefined' && SyncStateManager.setSyncInterval) {
      SyncStateManager.setSyncInterval(intervalId);
    }
    
    // eslint-disable-next-line no-console
    console.log('✅ Polling de Fase 2 iniciado con ID:', intervalId);
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
  // ✅ MEJORADO: Registrar error con más detalles usando ErrorHandler
  const errorMessage = error || 'Error de comunicación';
  const errorContext = 'PHASE2_START';
  
  if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
    ErrorHandler.logError(`Error al iniciar Fase 2: ${errorMessage} (Status: ${status || 'unknown'})`, errorContext);
  } else {
    // eslint-disable-next-line no-console
    console.error('❌ Error al iniciar Fase 2:', error);
  }

  // ✅ MEJORADO: Emitir evento de error a través de PollingManager
  if (typeof window !== 'undefined' && window.pollingManager && typeof window.pollingManager.emit === 'function') {
    window.pollingManager.emit('syncError', {
      message: errorMessage,
      status,
      xhr,
      phase: 2,
      timestamp: Date.now()
    });
  }

  // ✅ MEJORADO: Mostrar error en UI usando ErrorHandler
  if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.showConnectionError === 'function') {
    ErrorHandler.showConnectionError(xhr);
  } else if (DOM_CACHE && DOM_CACHE.$feedback) {
    DOM_CACHE.$feedback.text('Error al iniciar Fase 2: ' + errorMessage);
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
  // ✅ PROTECCIÓN: Verificar si ya está inicializada ANTES de adquirir el lock
  // Esto previene llamadas AJAX innecesarias cuando ya está inicializado
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager && typeof SyncStateManager.getPhase2Initialized === 'function') {
    if (SyncStateManager.getPhase2Initialized()) {
      throttledDebug('ℹ️ Fase 2 ya fue inicializada, ignorando llamada duplicada');
      return;
    }
  }
  
  // ✅ PROTECCIÓN CRÍTICA: Lock atómico para prevenir ejecuciones simultáneas
  // Usar SyncStateManager para obtener lock de forma atómica
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager && typeof SyncStateManager.setPhase2Starting === 'function') {
    const lockAcquired = SyncStateManager.setPhase2Starting(true);
    if (!lockAcquired) {
      // Ya hay una ejecución en progreso, ignorar esta llamada
      throttledDebug('ℹ️ Fase 2 ya se está iniciando, ignorando llamada duplicada');
      return;
    }
  } else {
    // ✅ MEJORADO: Usar SyncStateManager API en lugar de acceso directo a window
    // Fallback: Si SyncStateManager no está disponible, no permitir ejecución simultánea
    // (no podemos establecer el lock sin SyncStateManager, así que simplemente retornamos)
    throttledDebug('⚠️ SyncStateManager no está disponible, no se puede prevenir ejecución simultánea');
    return;
  }
  
  // Verificar dependencias críticas
  if (typeof jQuery === 'undefined') {
    if (typeof SyncStateManager !== 'undefined' && SyncStateManager.setPhase2Starting) {
      SyncStateManager.setPhase2Starting(false);
    }
    if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
      ErrorHandler.logError('jQuery no está disponible para Phase2Manager', 'PHASE2_START');
    }
    return;
  }

  if (typeof miIntegracionApiDashboard === 'undefined' || !miIntegracionApiDashboard || !miIntegracionApiDashboard.ajaxurl) {
    if (typeof SyncStateManager !== 'undefined' && SyncStateManager.setPhase2Starting) {
      SyncStateManager.setPhase2Starting(false);
    }
    if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
      ErrorHandler.logError('miIntegracionApiDashboard o ajaxurl no están disponibles', 'PHASE2_START');
    }
    return;
  }

  if (typeof DOM_CACHE === 'undefined' || !DOM_CACHE) {
    if (typeof SyncStateManager !== 'undefined' && SyncStateManager.setPhase2Starting) {
      SyncStateManager.setPhase2Starting(false);
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
    timeout,
    data: {
      action: 'mi_integracion_api_sync_products_batch',
      nonce: miIntegracionApiDashboard.nonce || (typeof window !== 'undefined' && window.miIntegracionApiDashboard && window.miIntegracionApiDashboard.nonce),
      batch_size: batchSize
    },
    success(response) {
      // ✅ Resetear flag de inicio después de recibir respuesta usando SyncStateManager
      if (typeof SyncStateManager !== 'undefined' && SyncStateManager.setPhase2Starting) {
        SyncStateManager.setPhase2Starting(false);
      }
      
      if (response.success) {
        handlePhase2StartSuccess();
      } else {
        // Manejar respuesta con error
        const errorMsg = (response.data && response.data.message) || 'Error desconocido';
        handleError(null, 'error', errorMsg);
      }
    },
    error(xhr, status, error) {
      // ✅ Resetear flag de inicio en caso de error usando SyncStateManager
      if (typeof SyncStateManager !== 'undefined' && SyncStateManager.setPhase2Starting) {
        SyncStateManager.setPhase2Starting(false);
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
  // ✅ MEJORADO: Resetear todo el estado de Fase 2 usando SyncStateManager
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager.resetPhase2State) {
    SyncStateManager.resetPhase2State();
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
  
  // Evitar múltiples llamadas simultáneas usando SyncStateManager
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager.getPhase2ProcessingBatch()) {
    // ✅ MEJORADO: Mostrar mensaje informativo en consola
    const waitingMessage = 'ℹ️ Ya hay un lote siendo procesado manualmente, esperando...';
    if (typeof window !== 'undefined' && window.ConsoleManager && typeof window.ConsoleManager.addLine === 'function') {
      window.ConsoleManager.addLine('info', waitingMessage);
    } else {
      // eslint-disable-next-line no-console
      console.log(waitingMessage);
    }
    return;
  }
  
  // ✅ NUEVO: Mostrar mensaje informativo cuando se inicia el procesamiento manual
  const processingMessage = '🔄 Procesando lote manualmente (WordPress Cron no responde)...';
  if (typeof window !== 'undefined' && window.ConsoleManager && typeof window.ConsoleManager.addLine === 'function') {
    window.ConsoleManager.addLine('info', processingMessage);
  } else if (typeof window !== 'undefined' && window.addConsoleLine && typeof window.addConsoleLine === 'function') {
    window.addConsoleLine('info', processingMessage);
  } else {
    // eslint-disable-next-line no-console
    console.log(processingMessage);
  }
  
  // Marcar como procesando usando SyncStateManager
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager.setPhase2ProcessingBatch) {
    SyncStateManager.setPhase2ProcessingBatch(true);
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
    success(response) {
      // ✅ MEJORADO: Mostrar mensaje de éxito en consola
      const successMessage = '✅ Lote procesado manualmente con éxito. La sincronización continuará automáticamente.';
      if (typeof window !== 'undefined' && window.ConsoleManager && typeof window.ConsoleManager.addLine === 'function') {
        window.ConsoleManager.addLine('success', successMessage);
      } else if (typeof window !== 'undefined' && window.addConsoleLine && typeof window.addConsoleLine === 'function') {
        window.addConsoleLine('success', successMessage);
      } else {
        // eslint-disable-next-line no-console
        console.log('✅ Siguiente lote procesado automáticamente desde Phase2Manager', response);
      }
      
      // ✅ NUEVO: Mostrar notificación toast para mayor visibilidad
      if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
        ToastManager.show('Lote procesado manualmente con éxito', 'success', 3000);
      } else if (typeof window !== 'undefined' && window.ToastManager && typeof window.ToastManager.show === 'function') {
        window.ToastManager.show('Lote procesado manualmente con éxito', 'success', 3000);
      }
      
      // Resetear flag después de un breve delay para permitir siguiente procesamiento usando SyncStateManager
      setTimeout(() => {
        if (typeof SyncStateManager !== 'undefined' && SyncStateManager.setPhase2ProcessingBatch) {
          SyncStateManager.setPhase2ProcessingBatch(false);
        }
      }, 5000); // 5 segundos de cooldown
    },
    error(xhr, status, error) {
      // ✅ MEJORADO: Mostrar mensaje de error en consola
      const errorMessage = `⚠️ Error al procesar lote manualmente: ${error || 'Error de conexión'}. WordPress Cron intentará procesarlo más tarde.`;
      if (typeof window !== 'undefined' && window.ConsoleManager && typeof window.ConsoleManager.addLine === 'function') {
        window.ConsoleManager.addLine('warning', errorMessage);
      } else if (typeof window !== 'undefined' && window.addConsoleLine && typeof window.addConsoleLine === 'function') {
        window.addConsoleLine('warning', errorMessage);
      }
      
      // ✅ NUEVO: Mostrar notificación toast para mayor visibilidad
      const toastErrorMessage = 'Error al procesar lote manualmente. WordPress Cron intentará procesarlo más tarde.';
      if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
        ToastManager.show(toastErrorMessage, 'error', 5000);
      } else if (typeof window !== 'undefined' && window.ToastManager && typeof window.ToastManager.show === 'function') {
        window.ToastManager.show(toastErrorMessage, 'error', 5000);
      }
      
      // ✅ MEJORADO: Registrar error usando ErrorHandler
      if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
        ErrorHandler.logError(
          `Error al procesar siguiente lote automáticamente: ${error || 'Error de conexión'} (Status: ${status || 'unknown'})`,
          'BATCH_PROCESSING'
        );
      } else {
        // eslint-disable-next-line no-console
        console.warn('⚠️ Error al procesar siguiente lote automáticamente', {
          status,
          error,
          xhr: xhr ? xhr.status : 'unknown'
        });
      }
      
      // ✅ MEJORADO: Mostrar error en UI si ErrorHandler está disponible
      if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.showConnectionError === 'function') {
        ErrorHandler.showConnectionError(xhr);
      }
      
      // Resetear flag incluso en caso de error usando SyncStateManager
      if (typeof SyncStateManager !== 'undefined' && SyncStateManager.setPhase2ProcessingBatch) {
        SyncStateManager.setPhase2ProcessingBatch(false);
      }
      
      // No es crítico, WordPress Cron puede procesarlo más tarde
    }
  });
}

/**
 * Maneja el evento de finalización de Fase 1
 * 
 * ✅ NUEVO: Suscripción al evento phase1Completed para iniciar Fase 2 automáticamente.
 * Verifica que Phase2Manager no esté ya inicializado o en proceso antes de iniciar.
 * 
 * @param {Object} _eventData - Datos del evento phase1Completed
 * @param {Object} _eventData.phase1Status - Estado de Fase 1
 * @param {number} _eventData.timestamp - Timestamp del evento
 * @param {Object} _eventData.data - Datos completos de sincronización
 * @returns {void}
 * @private
 */
function handlePhase1Completed(_eventData) {
  // ✅ PROTECCIÓN CRÍTICA: Verificar que Phase2Manager no esté ya inicializado o en proceso
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager) {
    // Verificar si ya está iniciando
    if (SyncStateManager.getPhase2Starting && SyncStateManager.getPhase2Starting()) {
      throttledDebug('ℹ️ [Phase2Manager] Fase 2 ya se está iniciando, ignorando evento phase1Completed');
      return;
    }
    
    // Verificar si ya está inicializada
    if (SyncStateManager.getPhase2Initialized && SyncStateManager.getPhase2Initialized()) {
      throttledDebug('ℹ️ [Phase2Manager] Fase 2 ya está inicializada, ignorando evento phase1Completed');
      return;
    }
  }

  // ✅ NUEVO: Log informativo cuando se recibe el evento
  // eslint-disable-next-line no-console
  if (typeof console !== 'undefined' && console.log) {
    // eslint-disable-next-line no-console
    console.log('✅ [Phase2Manager] Evento phase1Completed recibido, iniciando Fase 2 automáticamente');
  }

  // Iniciar Fase 2 automáticamente
  start();
}

/**
 * Inicializa las suscripciones a eventos de Phase2Manager
 * 
 * ✅ NUEVO: Suscribe Phase2Manager al evento phase1Completed para iniciar automáticamente
 * cuando Fase 1 se completa. Esto robustece la transición entre fases.
 * 
 * ✅ MEJORADO: Intenta suscribirse inmediatamente, y si PollingManager no está disponible,
 * espera un breve tiempo antes de reintentar (útil si los scripts se cargan en orden diferente).
 * 
 * @returns {void}
 * @private
 */
function initializeEventSubscriptions() {
  // Suscribirse al evento de finalización de Fase 1
  if (typeof window !== 'undefined' && window.pollingManager && typeof window.pollingManager.on === 'function') {
    window.pollingManager.on('phase1Completed', handlePhase1Completed);
    // eslint-disable-next-line no-console
    if (typeof console !== 'undefined' && console.log) {
      // eslint-disable-next-line no-console
      console.log('✅ [Phase2Manager] Suscrito al evento phase1Completed');
    }
  } else {
    // ✅ MEJORADO: Si PollingManager no está disponible, esperar un momento y reintentar
    // Esto maneja casos donde los scripts se cargan en orden diferente
    setTimeout(function() {
      if (typeof window !== 'undefined' && window.pollingManager && typeof window.pollingManager.on === 'function') {
        window.pollingManager.on('phase1Completed', handlePhase1Completed);
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.log) {
          // eslint-disable-next-line no-console
          console.log('✅ [Phase2Manager] Suscrito al evento phase1Completed (reintento exitoso)');
        }
      } else {
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.warn) {
          // eslint-disable-next-line no-console
          console.warn('⚠️ [Phase2Manager] PollingManager no está disponible para suscribirse a eventos');
        }
      }
    }, 100); // Esperar 100ms antes de reintentar
  }
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
    
    // ✅ NUEVO: Inicializar suscripciones a eventos cuando Phase2Manager se expone
    // Esto asegura que Phase2Manager escuche el evento phase1Completed
    // Se ejecuta después de que el DOM esté listo o inmediatamente si ya lo está
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initializeEventSubscriptions);
    } else {
      // DOM ya está listo, inicializar inmediatamente
      initializeEventSubscriptions();
    }
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
      
      // ✅ NUEVO: Inicializar suscripciones a eventos también en el fallback
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeEventSubscriptions);
      } else {
        initializeEventSubscriptions();
      }
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
