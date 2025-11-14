/**
 * Gestor de Fase 1: Sincronización de Imágenes
 *
 * Gestiona la Fase 1 de la sincronización en dos fases, que consiste en
 * sincronizar todas las imágenes antes de proceder con la sincronización
 * de productos (Fase 2).
 *
 * @module sync/Phase1Manager
 * @namespace Phase1Manager
 * @since 1.0.0
 * @author Christian
 */

/* global jQuery, miIntegracionApiDashboard, DASHBOARD_CONFIG, DOM_CACHE, pollingManager, ErrorHandler, SyncStateManager, startPhase2, window */

/**
 * Intervalo de polling para verificar el progreso de Fase 1
 * 
 * ✅ DEPRECADO: Ya no se usa directamente. PollingManager gestiona el intervalo.
 * Se mantiene para compatibilidad hacia atrás.
 *
 * @type {number|null}
 * @private
 * @deprecated Usar PollingManager para gestionar polling
 */
let phase1PollingInterval = null;

/**
 * Estado de completitud de Fase 1
 *
 * @type {boolean}
 * @private
 */
let phase1Complete = false;

/**
 * Verifica si Fase 1 está completa basándose en el estado recibido
 *
 * @param {Object} phase1Status - Estado de Fase 1
 * @returns {boolean} True si Fase 1 está completa
 * @private
 */
function isPhase1Completed(phase1Status) {
  // Solo considerar completada si:
  // 1. Está explícitamente marcada como completada Y tiene productos procesados
  // 2. O no está en progreso Y ha procesado todos los productos (total_products > 0)
  // NO considerar completada si:
  // - Se detectó como proceso huérfano (stale_detected)
  // - No tiene total_products definido o es 0
  // - No ha procesado ningún producto
  const isStale = phase1Status.stale_detected === true;
  const hasTotalProducts = phase1Status.total_products && phase1Status.total_products > 0;
  const hasProcessedProducts = phase1Status.products_processed && phase1Status.products_processed > 0;
  const allProductsProcessed = hasTotalProducts && hasProcessedProducts &&
                               phase1Status.products_processed === phase1Status.total_products;

  return !isStale && (
    (phase1Status.completed === true && hasProcessedProducts) ||
    (!phase1Status.in_progress && allProductsProcessed)
  );
}

/**
 * Verifica el progreso de Fase 1 y determina si está completa
 *
 * @returns {void}
 * @private
 */
function checkPhase1Complete() {
  if (typeof jQuery === 'undefined' || typeof miIntegracionApiDashboard === 'undefined') {
    return;
  }

  jQuery.ajax({
    url: 
      (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard.ajaxurl) 
        ? miIntegracionApiDashboard.ajaxurl 
        : (typeof window !== 'undefined' && window.miIntegracionApiDashboard && window.miIntegracionApiDashboard.ajaxurl)
          ? window.miIntegracionApiDashboard.ajaxurl
          : '',
    type: 'POST',
    data: {
      action: 'mia_get_sync_progress',
      nonce: (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard.nonce) 
        ? miIntegracionApiDashboard.nonce 
        : (typeof window !== 'undefined' && window.miIntegracionApiDashboard && window.miIntegracionApiDashboard.nonce)
          ? window.miIntegracionApiDashboard.nonce
          : ''
    },
    success(progressResponse) {
      if (progressResponse.success && progressResponse.data) {
        const phase1Status = progressResponse.data.phase1_images || {};


        // ✅ NUEVO: Emitir evento de progreso a través de PollingManager
        // Esto permite que ConsoleManager y otros suscriptores reciban actualizaciones
        if (typeof window !== 'undefined' && window.pollingManager && typeof window.pollingManager.emit === 'function') {
          window.pollingManager.emit('syncProgress', {
            syncData: progressResponse.data,
            phase1Status,
            timestamp: Date.now()
          });
        } else {
          // Fallback: Intentar actualizar consola directamente si no hay sistema de eventos
          if (typeof window !== 'undefined') {
            if (typeof window.updateSyncConsole === 'function') {
              window.updateSyncConsole(progressResponse.data, phase1Status);
            } else if (window.ConsoleManager && typeof window.ConsoleManager.updateSyncConsole === 'function') {
              window.ConsoleManager.updateSyncConsole(progressResponse.data, phase1Status);
            }
          }
        }

        // ✅ NUEVO: Actualizar dashboard también
        if (typeof window !== 'undefined' && window.syncDashboard && typeof window.syncDashboard.updateDashboardFromStatus === 'function') {
          window.syncDashboard.updateDashboardFromStatus(progressResponse.data);
        }

        if (isPhase1Completed(phase1Status)) {
          phase1Complete = true;

          // ✅ PROTECCIÓN: Marcar Fase 1 como completada usando SyncStateManager
          if (typeof SyncStateManager !== 'undefined' && SyncStateManager && typeof SyncStateManager.setPhase1Initialized === 'function') {
            SyncStateManager.setPhase1Initialized(false); // Ya no está en progreso
          }

          // Detener polling de Fase 1
          stopPolling();

          // ✅ NUEVO: Emitir evento de finalización de Fase 1 a través de PollingManager
          // Esto permite que Phase2Manager y otros componentes se suscriban y actúen
          if (typeof window !== 'undefined' && window.pollingManager && typeof window.pollingManager.emit === 'function') {
            window.pollingManager.emit('phase1Completed', {
              phase1Status,
              timestamp: Date.now(),
              data: progressResponse.data
            });
            // eslint-disable-next-line no-console
            if (typeof console !== 'undefined' && console.log) {
              // eslint-disable-next-line no-console
              console.log('✅ [Phase1Manager] Evento phase1Completed emitido');
            }
          }

          // ✅ COMPATIBILIDAD: Mantener llamada directa a startPhase2 para código legacy
          // Phase2Manager también escuchará el evento phase1Completed, pero mantenemos
          // esta llamada para compatibilidad con código existente
          if (typeof startPhase2 === 'function') {
            startPhase2();
          } else if (typeof console !== 'undefined' && console.error) {
            // eslint-disable-next-line no-console
            console.error('startPhase2 no está disponible');
          }
        }
      }
    },
    error(xhr, status, error) {
      // ✅ MEJORADO: Registrar error usando ErrorHandler en lugar de silenciarlo
      if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
        ErrorHandler.logError(
          `Error al verificar progreso de Fase 1: ${error || 'Error de conexión'} (Status: ${status || 'unknown'})`,
          'PHASE1_POLLING'
        );
      }
      // El polling continuará, pero ahora tenemos registro del error
    }
  });
}

/**
 * Inicializa el polling para monitorear Fase 1
 * 
 * ✅ MIGRADO: Ahora usa PollingManager en lugar de setInterval directo.
 * PollingManager es el único responsable de gestionar intervalos.
 *
 * @returns {void}
 * @private
 */
function startPolling() {
  // ✅ MIGRADO: Usar PollingManager en lugar de setInterval directo
  if (typeof pollingManager === 'undefined' || !pollingManager || typeof pollingManager.startPolling !== 'function') {
    // eslint-disable-next-line no-console
    if (typeof console !== 'undefined' && console.error) {
      // eslint-disable-next-line no-console
      console.error('PollingManager no está disponible para Phase1Manager');
    }
    return;
  }

  // Configurar polling
  if (pollingManager.config) {
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

  // ✅ MIGRADO: Usar PollingManager para iniciar polling con nombre único 'phase1'
  // PollingManager previene duplicaciones automáticamente
  const intervalId = pollingManager.startPolling('phase1', checkPhase1Complete, 2000);
  
  // Mantener compatibilidad hacia atrás guardando el ID
  phase1PollingInterval = intervalId;

  // También verificar inmediatamente después de 1 segundo para feedback instantáneo
  setTimeout(checkPhase1Complete, 1000);
}

/**
 * Detiene el polling de Fase 1
 * 
 * ✅ MIGRADO: Ahora usa PollingManager para detener el polling.
 * PollingManager es el único responsable de gestionar intervalos.
 *
 * @returns {void}
 * @private
 */
function stopPolling() {
  // ✅ MIGRADO: Usar PollingManager para detener polling
  if (typeof pollingManager !== 'undefined' && pollingManager && typeof pollingManager.stopPolling === 'function') {
    pollingManager.stopPolling('phase1');
  }
  
  // Mantener compatibilidad hacia atrás limpiando la variable
  phase1PollingInterval = null;
}

/**
 * Maneja la respuesta exitosa de iniciar Fase 1
 *
 * @param {Object} response - Respuesta del servidor
 * @returns {void}
 * @private
 */
function handleSuccess(response) {
  // ✅ PROTECCIÓN: Liberar lock de inicio después de recibir respuesta exitosa
  // ✅ MEJORADO: Usar SyncStateManager API en lugar de acceso directo a window
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager && typeof SyncStateManager.setPhase1Starting === 'function') {
    SyncStateManager.setPhase1Starting(false);
  }
  // Nota: Si SyncStateManager no está disponible, el lock no se puede liberar, pero esto es un caso de error

  // ✅ PROTECCIÓN: Marcar como inicializada usando SyncStateManager
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager && typeof SyncStateManager.setPhase1Initialized === 'function') {
    SyncStateManager.setPhase1Initialized(true);
  }

  // Verificar si el proceso ya está en progreso o se acaba de iniciar
  if (response.data && response.data.in_progress) {
    if (DOM_CACHE && DOM_CACHE.$feedback) {
      DOM_CACHE.$feedback.text('Fase 1: Sincronización iniciada (proceso en segundo plano)...');
    }
  } else {
    if (DOM_CACHE && DOM_CACHE.$feedback) {
      DOM_CACHE.$feedback.text('Fase 1: Sincronizando imágenes...');
    }
  }

  // Inicializar estado
  phase1Complete = false;

  // ✅ NUEVO: Emitir evento inmediato cuando se inicia la sincronización
  // Esto permite que ConsoleManager muestre el mensaje de inicio inmediatamente
  if (typeof window !== 'undefined' && window.pollingManager && typeof window.pollingManager.emit === 'function') {
    // Obtener datos iniciales de la respuesta o hacer una consulta rápida
    const phase1Status = response.data && response.data.phase1_images ? response.data.phase1_images : {
      in_progress: true,
      completed: false,
      products_processed: 0,
      total_products: response.data && response.data.total_products ? response.data.total_products : 0
    };
    
    window.pollingManager.emit('syncProgress', {
      syncData: response.data || {
        in_progress: false,
        is_completed: false
      },
      phase1Status,
      timestamp: Date.now()
    });
  }

  // Iniciar polling para monitorear Fase 1
  startPolling();
}

/**
 * Maneja errores al iniciar Fase 1
 *
 * @param {Object} response - Respuesta del servidor con error
 * @param {string} originalText - Texto original del botón de sincronización
 * @returns {void}
 * @private
 */
function handleErrorResponse(response, originalText) {
  // ✅ PROTECCIÓN: Liberar lock de inicio después de error
  // ✅ MEJORADO: Usar SyncStateManager API en lugar de acceso directo a window
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager && typeof SyncStateManager.setPhase1Starting === 'function') {
    SyncStateManager.setPhase1Starting(false);
  }
  // Nota: Si SyncStateManager no está disponible, el lock no se puede liberar, pero esto es un caso de error

  const errorMsg = (response.data && response.data.message) || 'Error desconocido';

  if (DOM_CACHE && DOM_CACHE.$feedback) {
    DOM_CACHE.$feedback.text('Error al iniciar Fase 1: ' + errorMsg);
  }

  if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
    ErrorHandler.logError('Error al iniciar Fase 1', 'SYNC_START');
  }

  if (DOM_CACHE && DOM_CACHE.$syncBtn) {
    DOM_CACHE.$syncBtn.prop('disabled', false).text(originalText);
  }

  if (DOM_CACHE && DOM_CACHE.$batchSizeSelector) {
    DOM_CACHE.$batchSizeSelector.prop('disabled', false);
  }
}

/**
 * Maneja errores AJAX al iniciar Fase 1
 *
 * @param {Object} xhr - Objeto XMLHttpRequest
 * @param {string} status - Estado de la petición
 * @param {string} error - Mensaje de error
 * @param {string} originalText - Texto original del botón de sincronización
 * @returns {boolean} True si se manejó el error (timeout), false en caso contrario
 * @private
 */
function handleAjaxError(xhr, status, error, originalText) {
  // ✅ PROTECCIÓN: Liberar lock de inicio después de error AJAX
  // ✅ MEJORADO: Usar SyncStateManager API en lugar de acceso directo a window
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager && typeof SyncStateManager.setPhase1Starting === 'function') {
    SyncStateManager.setPhase1Starting(false);
  }
  // Nota: Si SyncStateManager no está disponible, el lock no se puede liberar, pero esto es un caso de error

  if (DOM_CACHE && DOM_CACHE.$feedback) {
    DOM_CACHE.$feedback.text('Error al iniciar Fase 1: ' + (error || 'Error de comunicación'));
  }

  if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
    ErrorHandler.logError('Error AJAX al iniciar Fase 1', 'SYNC_START');
  }

  if (DOM_CACHE && DOM_CACHE.$syncBtn) {
    DOM_CACHE.$syncBtn.prop('disabled', false).text(originalText);
  }

  if (DOM_CACHE && DOM_CACHE.$batchSizeSelector) {
    DOM_CACHE.$batchSizeSelector.prop('disabled', false);
  }

  // Verificar si es un error de nonce y recargar página (patrón existente)
  if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.error_type === 'invalid_nonce') {
    // Usar el patrón existente de recarga de página
    setTimeout(() => {
      if (typeof window !== 'undefined' && window.location) {
        window.location.reload();
      }
    }, 1000);
    return false;
  }

  // Manejar timeout específicamente
  if (status === 'timeout') {
    if (DOM_CACHE && DOM_CACHE.$feedback) {
      DOM_CACHE.$feedback.text('Fase 1: Sincronización en curso (proceso largo, puede tardar varios minutos)...');
    }

    // ✅ MEJORADO: Si hay timeout, asumir que el proceso se inició correctamente y monitorear progreso
    // Inicializar estado
    phase1Complete = false;

    // Iniciar polling inmediatamente para monitorear el progreso
    startPolling();

    return true; // Indicar que se manejó el timeout
  }

  return false; // No se manejó como timeout
}

/**
 * Inicia la Fase 1: Sincronización de Imágenes
 *
 * @param {number} batchSize - Tamaño del lote para la sincronización
 * @param {string} originalText - Texto original del botón de sincronización
 * @returns {void}
 *
 * @example
 * Phase1Manager.start(50, 'Sincronizar productos en lote');
 */
function start(batchSize, originalText) {
  // ✅ PROTECCIÓN CRÍTICA: Lock atómico para prevenir ejecuciones simultáneas
  // Usar SyncStateManager para obtener lock de forma atómica
  // Verificar tanto variable global como window.SyncStateManager para compatibilidad con tests
  const stateManager = (typeof SyncStateManager !== 'undefined' && SyncStateManager) 
    ? SyncStateManager 
    : (typeof window !== 'undefined' && window.SyncStateManager) 
      ? window.SyncStateManager 
      : null;
  
  if (stateManager && typeof stateManager.setPhase1Starting === 'function') {
    const lockAcquired = stateManager.setPhase1Starting(true);
    if (!lockAcquired) {
      // Ya hay una ejecución en progreso, ignorar esta llamada
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('⚠️ Fase 1 ya se está iniciando, ignorando llamada duplicada');
      }
      return;
    }
  } else {
    // ✅ MEJORADO: Usar SyncStateManager API en lugar de acceso directo a window
    // Fallback: Si SyncStateManager no está disponible, no permitir ejecución simultánea
    // (no podemos establecer el lock sin SyncStateManager, así que simplemente retornamos)
    // eslint-disable-next-line no-console
    if (typeof console !== 'undefined' && console.warn) {
      // eslint-disable-next-line no-console
      console.warn('⚠️ SyncStateManager no está disponible, no se puede prevenir ejecución simultánea');
    }
    return;
  }
  
  // ✅ PROTECCIÓN: Verificar si ya está inicializada y en progreso
  if (stateManager && typeof stateManager.getPhase1Initialized === 'function') {
    if (stateManager.getPhase1Initialized()) {
      // Liberar lock antes de retornar
      if (typeof stateManager.setPhase1Starting === 'function') {
        stateManager.setPhase1Starting(false);
      }
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('⚠️ Fase 1 ya está inicializada y en progreso, ignorando llamada duplicada');
      }
      return;
    }
  }

  // Verificar dependencias críticas
  if (typeof jQuery === 'undefined') {
    // Liberar lock antes de retornar
    if (stateManager && typeof stateManager.setPhase1Starting === 'function') {
      stateManager.setPhase1Starting(false);
    }
    if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
      ErrorHandler.logError('jQuery no está disponible para Phase1Manager', 'PHASE1_START');
    }
    return;
  }

  if (typeof miIntegracionApiDashboard === 'undefined' || !miIntegracionApiDashboard || !miIntegracionApiDashboard.ajaxurl) {
    // Liberar lock antes de retornar
    if (stateManager && typeof stateManager.setPhase1Starting === 'function') {
      stateManager.setPhase1Starting(false);
    }
    if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
      ErrorHandler.logError('miIntegracionApiDashboard o ajaxurl no están disponibles', 'PHASE1_START');
    }
    return;
  }

  if (typeof DOM_CACHE === 'undefined' || !DOM_CACHE) {
    // Liberar lock antes de retornar
    if (stateManager && typeof stateManager.setPhase1Starting === 'function') {
      stateManager.setPhase1Starting(false);
    }
    if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
      ErrorHandler.logError('DOM_CACHE no está disponible', 'PHASE1_START');
    }
    return;
  }

  if (DOM_CACHE.$feedback) {
    DOM_CACHE.$feedback.text('Fase 1: Sincronizando imágenes...');
  }

  // Variable para almacenar el batch_size para Fase 2
  if (typeof window !== 'undefined') {
    window.pendingPhase2BatchSize = batchSize;
  }

  // Lanzar AJAX para iniciar Fase 1 (sincronización de imágenes)
  const timeout = (DASHBOARD_CONFIG && DASHBOARD_CONFIG.timeouts && DASHBOARD_CONFIG.timeouts.ajax)
    ? DASHBOARD_CONFIG.timeouts.ajax * 4
    : 240000;

  jQuery.ajax({
    url: miIntegracionApiDashboard.ajaxurl,
    type: 'POST',
    timeout,
    data: {
      action: 'mia_sync_images',
      nonce: miIntegracionApiDashboard.nonce || (typeof window !== 'undefined' && window.miIntegracionApiDashboard && window.miIntegracionApiDashboard.nonce),
      resume: false,
      batch_size: batchSize
    },
    success(response) {
      // ✅ FASE 1: Manejar respuesta de sincronización de imágenes
      if (response.success) {
        handleSuccess(response);
      } else {
        handleErrorResponse(response, originalText);
      }
    },
    error(xhr, status, error) {
      const handled = handleAjaxError(xhr, status, error, originalText);
      // Si se manejó como timeout, no hacer nada más (el polling ya está iniciado)
      if (!handled) {
        // Error no manejado como timeout, ya se mostró el mensaje de error
      }
    }
  });
}

/**
 * Detiene el polling de Fase 1
 *
 * @returns {void}
 */
function stop() {
  stopPolling();
  phase1Complete = false;
  
  // ✅ PROTECCIÓN: Resetear flags de estado al detener
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager) {
    if (typeof SyncStateManager.resetPhase1State === 'function') {
      SyncStateManager.resetPhase1State();
    } else {
      // Fallback: resetear flags individualmente
      if (typeof SyncStateManager.setPhase1Starting === 'function') {
        SyncStateManager.setPhase1Starting(false);
      }
      if (typeof SyncStateManager.setPhase1Initialized === 'function') {
        SyncStateManager.setPhase1Initialized(false);
      }
    }
  }
  // ✅ MEJORADO: Usar SyncStateManager API en lugar de acceso directo a window
  // Nota: Si SyncStateManager no está disponible, el lock no se puede liberar, pero esto es un caso de error
}

/**
 * Verifica si Fase 1 está completa
 *
 * @returns {boolean} True si Fase 1 está completa
 */
function isComplete() {
  return phase1Complete;
}

/**
 * Obtiene el intervalo de polling actual
 *
 * @returns {number|null} ID del intervalo de polling o null si no está activo
 */
function getPollingInterval() {
  // ✅ MIGRADO: Obtener ID del intervalo desde PollingManager si está disponible
  if (typeof pollingManager !== 'undefined' && pollingManager && typeof pollingManager.getIntervalId === 'function') {
    return pollingManager.getIntervalId('phase1');
  }
  // Fallback a variable local para compatibilidad hacia atrás
  return phase1PollingInterval;
}

/**
 * Objeto Phase1Manager con métodos públicos
 */
const Phase1Manager = {
  start,
  stop,
  isComplete,
  getPollingInterval,
  // ✅ NUEVO: Exponer startPolling para que SyncDashboard pueda iniciarlo
  startPolling
};

/**
 * Exponer Phase1Manager globalmente para mantener compatibilidad
 * con el código existente que usa window.Phase1Manager
 */
if (typeof window !== 'undefined') {
  try {
    window.Phase1Manager = Phase1Manager;
    // Exponer también variables globales para compatibilidad
    Object.defineProperty(window, 'phase1Complete', {
      get() {
        return phase1Complete;
      },
      set(value) {
        phase1Complete = value;
      },
      enumerable: true,
      configurable: true
    });

    Object.defineProperty(window, 'phase1PollingInterval', {
      get() {
        return phase1PollingInterval;
      },
      set(value) {
        phase1PollingInterval = value;
      },
      enumerable: true,
      configurable: true
    });
  } catch (error) {
    try {
      Object.defineProperty(window, 'Phase1Manager', {
        value: Phase1Manager,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar Phase1Manager a window:', defineError, error);
      }
    }
  }
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { Phase1Manager };
}
