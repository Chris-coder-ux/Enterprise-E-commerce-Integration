/**
 * Gestor de Progreso de Sincronización
 *
 * Gestiona la verificación del progreso de sincronización mediante peticiones AJAX,
 * actualiza la interfaz de usuario y maneja el estado de la sincronización.
 *
 * IMPORTANTE: Esta función SOLO monitorea el progreso, NO procesa lotes.
 * El backend maneja automáticamente el procesamiento de todos los lotes.
 *
 * @module sync/SyncProgress
 * @namespace SyncProgress
 * @since 1.0.0
 * @author Christian
 */

/* global jQuery, miIntegracionApiDashboard, DASHBOARD_CONFIG, DomUtils, pollingManager, ErrorHandler, AjaxManager, updateSyncConsole, stopProgressPolling, inactiveProgressCounter, lastProgressValue, SyncStateManager, window */

/**
 * Variables de seguimiento del progreso
 *
 * @type {Object}
 * @property {number} lastKnownBatch - Último lote conocido
 * @property {number} lastKnownItemsSynced - Últimos items sincronizados conocidos
 * @property {number} lastKnownTotalBatches - Total de lotes conocido
 * @property {number} lastKnownTotalItems - Total de items conocido
 */
const trackingState = {
  lastKnownBatch: 0,
  lastKnownItemsSynced: 0,
  lastKnownTotalBatches: 0,
  lastKnownTotalItems: 0
};

/**
 * Obtiene el cache DOM de forma segura
 *
 * @returns {Object|null} El objeto DOM_CACHE o null si no está disponible
 * @private
 */
function getDomCache() {
  if (typeof window !== 'undefined' && window.DOM_CACHE) {
    return window.DOM_CACHE;
  }
  if (typeof DomUtils !== 'undefined' && DomUtils && typeof DomUtils.getCache === 'function') {
    return DomUtils.getCache();
  }
  return null;
}

/**
 * Verifica el progreso de la sincronización
 *
 * Realiza una petición AJAX para verificar el estado actual de la
 * sincronización y actualiza la interfaz de usuario en consecuencia.
 * Utiliza un sistema de polling adaptativo para monitorear el progreso.
 *
 * IMPORTANTE: Esta función SOLO monitorea el progreso, NO procesa lotes.
 * El backend maneja automáticamente el procesamiento de todos los lotes.
 *
 * @function check
 * @returns {void}
 *
 * @example
 * // Se ejecuta automáticamente por el sistema de polling
 * SyncProgress.check();
 */
function check() {

  // Verificar dependencias críticas
  if (typeof jQuery === 'undefined') {
    ErrorHandler.logError('jQuery no está disponible para checkSyncProgress', 'SYNC_PROGRESS');
    return;
  }

  // eslint-disable-next-line prefer-optional-chain
  if (typeof miIntegracionApiDashboard === 'undefined' || !miIntegracionApiDashboard || !miIntegracionApiDashboard.ajaxurl) {
    ErrorHandler.logError('miIntegracionApiDashboard o ajaxurl no están disponibles', 'SYNC_PROGRESS');
    return;
  }

  // ✅ CORRECCIÓN: Obtener DOM_CACHE de forma segura
  const DOM_CACHE = getDomCache();
  if (!DOM_CACHE) {
    ErrorHandler.logError('DOM_CACHE no está disponible. Asegúrate de que DomUtils.js se carga antes de SyncProgress.js', 'SYNC_PROGRESS');
    return;
  }

  // Usar AjaxManager si está disponible, sino usar jQuery.ajax directamente
  const ajaxUrl = miIntegracionApiDashboard.ajaxurl;
  const timeout = (DASHBOARD_CONFIG && DASHBOARD_CONFIG.timeouts && DASHBOARD_CONFIG.timeouts.ajax)
    ? DASHBOARD_CONFIG.timeouts.ajax * 2
    : 120000; // Timeout para verificación de progreso

  // Intentar usar AjaxManager primero
  if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
    AjaxManager.call(
      'mia_get_sync_progress',
      {},
      function(response) {
        handleSuccess(response);
      },
      function(xhr, status, error) {
        handleError(xhr, status, error);
      },
      { timeout: timeout }
    );
  } else {
    // Fallback a jQuery.ajax directo
    jQuery.ajax({
      url: ajaxUrl,
      type: 'POST',
      timeout: timeout,
      data: {
        action: 'mia_get_sync_progress',
        nonce: miIntegracionApiDashboard.nonce
      },
      success: function(response) {
        handleSuccess(response);
      },
      error: function(xhr, status, error) {
        handleError(xhr, status, error);
      }
    });
  }
}

/**
 * Maneja la respuesta exitosa de la verificación de progreso
 *
 * @param {Object} response - Respuesta del servidor
 * @returns {void}
 * @private
 */
function handleSuccess(response) {
  // VERIFICACIÓN: Solo procesar respuestas del endpoint mia_get_sync_progress
  if (!response.data) {
    return;
  }

  // ✅ CORRECCIÓN: Obtener DOM_CACHE de forma segura
  const DOM_CACHE = getDomCache();

  // Verificar si hay estadísticas disponibles
  let estadisticas = response.data.estadisticas || response.data.stats || response.data || {};

  // CORRECCIÓN: Declarar variables al inicio para evitar errores de referencia
  let porcentaje = 0;
  let mensaje = '';
  let syncMeta = {};

  // Mostrar headers y status completo para diagnóstico
  if (response.success) {
    if (response.data) {
      // CORRECCIÓN: Usar estructura flexible para diferentes endpoints
      porcentaje = response.data.porcentaje || response.data.progress || 0;
      mensaje = response.data.mensaje || response.data.message || response.data.status || 'Procesando...';

      // Manejar estadísticas de forma flexible
      const statsData = response.data.estadisticas || response.data.stats || response.data;
      estadisticas = {
        procesados: statsData.procesados || statsData.processed || statsData.completed || 0,
        total: statsData.total || statsData.total_items || statsData.items || 0,
        errores: statsData.errores || statsData.errors || statsData.failed || 0
      };

      syncMeta = {
        in_progress: response.data.in_progress !== false,  // Más flexible para diferentes valores
        current_batch: response.data.current_batch || response.data.batch || response.data.current || 0,
        total_batches: response.data.total_batches || response.data.total || 1
      };

      // DETECCIÓN DE CAMBIOS: Verificar si cambió el lote actual o los items procesados
      const batchChanged = syncMeta.current_batch !== trackingState.lastKnownBatch;
      const itemsChanged = estadisticas.procesados !== trackingState.lastKnownItemsSynced;
      const totalBatchesChanged = syncMeta.total_batches !== trackingState.lastKnownTotalBatches;
      const totalItemsChanged = estadisticas.total !== trackingState.lastKnownTotalItems;

      // CORRECCIÓN: Si el lote cambió pero items_synced no, calcular el valor esperado
      if (batchChanged && !itemsChanged && syncMeta.current_batch > trackingState.lastKnownBatch) {
        // Calcular items_synced esperado basado en el lote actual
        const expectedItemsSynced = syncMeta.current_batch * 50; // 50 productos por lote
        if (expectedItemsSynced > trackingState.lastKnownItemsSynced) {
          estadisticas.procesados = expectedItemsSynced;
        }
      }

      const hasSignificantChange = batchChanged || itemsChanged || totalBatchesChanged || totalItemsChanged;

      // SIMPLIFICADO: Solo usar mensaje del servidor - no modificar lógica de negocio
      // PHP ya envía el mensaje correcto procesado

      // Asegurar que el contenedor de progreso es visible
      if (DOM_CACHE.$syncStatusContainer) {
        DOM_CACHE.$syncStatusContainer.css('display', 'block');
      }

      // MEJORADO: Mostrar progreso detallado por lotes
      const productosProcesados = estadisticas.procesados || 0;
      const totalProductos = estadisticas.total || 0;
      const loteActual = syncMeta.current_batch || 0;
      const totalLotes = syncMeta.total_batches || 1;

      // Usar datos calculados por el backend para consistencia
      const porcentajeVisual = response.data.porcentaje_visual || 0;

      // ✅ ELIMINADO: Actualización de barras de progreso - ahora se usa consola en tiempo real

      // ✅ NUEVO: Ajustar polling adaptativamente basado en el progreso
      if (typeof pollingManager !== 'undefined' && pollingManager && typeof pollingManager.adjustPolling === 'function') {
        const isActive = response.data.in_progress || false;
        pollingManager.adjustPolling(porcentajeVisual, isActive);
      }

      // ✅ NUEVO: Mostrar progreso de ambas fases (Fase 1: imágenes, Fase 2: productos)
      const phase1Status = response.data.phase1_images || {};
      const phase1InProgress = phase1Status.in_progress || false;
      const phase1Completed = phase1Status.completed || false;
      const phase1ProductsProcessed = phase1Status.products_processed || 0;
      const phase1TotalProducts = phase1Status.total_products || 0;
      const phase1ImagesProcessed = phase1Status.images_processed || 0;

      // ✅ CORRECCIÓN: NO emitir eventos desde SyncProgress si Phase1Manager está activo
      // Phase1Manager.checkPhase1Complete() ya emite eventos cada 5 segundos
      // Esto evita duplicación de eventos para la misma consulta al backend
      const phase1ManagerActive = typeof window !== 'undefined' && 
                                   window.Phase1Manager && 
                                   typeof window.Phase1Manager.getPollingInterval === 'function' &&
                                   window.Phase1Manager.getPollingInterval() !== null;
      
      if (!phase1ManagerActive && typeof window !== 'undefined' && window.pollingManager && typeof window.pollingManager.emit === 'function') {
        // Solo emitir si Phase1Manager NO está activo (para Fase 2 o cuando no hay polling de Fase 1)
        window.pollingManager.emit('syncProgress', {
          syncData: response.data,
          phase1Status: phase1Status,
          timestamp: Date.now()
        });
      }

      // ✅ CORRECCIÓN: Actualizar dashboard completo usando updateDashboardFromStatus
      // Esta función también actualiza la consola internamente, evitando duplicación
      if (typeof window !== 'undefined' && window.syncDashboard) {
        if (typeof window.syncDashboard.updateDashboardFromStatus === 'function') {
          // Actualizar todo el dashboard desde los datos completos
          // updateDashboardFromStatus NO actualiza la consola directamente, así que lo hacemos aquí
          window.syncDashboard.updateDashboardFromStatus(response.data);
          
          // ✅ CENTRALIZADO: Actualizar consola DESPUÉS de actualizar el dashboard
          // Solo actualizar directamente si no hay sistema de eventos disponible
          if (typeof window === 'undefined' || !window.pollingManager || typeof window.pollingManager.emit !== 'function') {
            if (typeof updateSyncConsole === 'function') {
              updateSyncConsole(response.data, phase1Status);
            } else if (typeof window !== 'undefined' && typeof window.updateSyncConsole === 'function') {
              window.updateSyncConsole(response.data, phase1Status);
            } else if (typeof window !== 'undefined' && window.ConsoleManager && typeof window.ConsoleManager.updateSyncConsole === 'function') {
              window.ConsoleManager.updateSyncConsole(response.data, phase1Status);
            }
          }
        } else {
          // Fallback: actualizar solo las fases individuales
          if (typeof window.syncDashboard.updatePhase1Progress === 'function') {
            window.syncDashboard.updatePhase1Progress(phase1Status);
          }
          if (response.data && response.data.estadisticas && typeof window.syncDashboard.updatePhase2Progress === 'function') {
            window.syncDashboard.updatePhase2Progress(response.data);
          }
          
          // ✅ CENTRALIZADO: Actualizar consola también en el fallback (solo si no hay eventos)
          if ((typeof window === 'undefined' || !window.pollingManager || typeof window.pollingManager.emit !== 'function') && typeof updateSyncConsole === 'function') {
            updateSyncConsole(response.data, phase1Status);
          }
        }
      } else {
        // ✅ CENTRALIZADO: Si no hay syncDashboard, actualizar consola directamente (solo si no hay eventos)
        if ((typeof window === 'undefined' || !window.pollingManager || typeof window.pollingManager.emit !== 'function') && typeof updateSyncConsole === 'function') {
          updateSyncConsole(response.data, phase1Status);
        }
      }

      // ✅ ELIMINADO: Mensajes de progreso para barras - ahora se muestra en consola en tiempo real

      // ACTUALIZAR VARIABLES DE SEGUIMIENTO si hay cambios significativos
      if (hasSignificantChange) {
        // Actualizar variables de seguimiento
        trackingState.lastKnownBatch = syncMeta.current_batch;
        trackingState.lastKnownItemsSynced = estadisticas.procesados;
        trackingState.lastKnownTotalBatches = syncMeta.total_batches;
        trackingState.lastKnownTotalItems = estadisticas.total;
      }

      // Actualizar lastProgressValue si cambió usando SyncStateManager
      if (typeof SyncStateManager !== 'undefined' && SyncStateManager && typeof SyncStateManager.setLastProgressValue === 'function') {
        if (typeof lastProgressValue !== 'undefined' && porcentaje !== lastProgressValue) {
          SyncStateManager.setLastProgressValue(porcentaje);
        }
      }

      // ✅ ELIMINADO: Actualización de información de progreso - ahora se muestra en consola en tiempo real

      // Usar campo is_completed calculado por el backend para consistencia
      const isCompleted = response.data.is_completed || false;

      if (isCompleted) {
        // Detener polling y resetear estado
        if (typeof stopProgressPolling === 'function') {
          stopProgressPolling('Sincronización completada');
        }

        if (DOM_CACHE.$syncBtn) {
          const originalText = (typeof window !== 'undefined' && window.originalSyncButtonText) || 'Sincronizar productos en lote';
          DOM_CACHE.$syncBtn.prop('disabled', false).text(originalText);
        }

        if (DOM_CACHE.$batchSizeSelector) {
          DOM_CACHE.$batchSizeSelector.prop('disabled', false);
        }

        if (DOM_CACHE.$feedback) {
          DOM_CACHE.$feedback.removeClass('in-progress').text('¡Sincronización completada!');
        }

        // Resetear variables de seguimiento
        resetTrackingState();
      } else if (syncMeta.in_progress) {
        // Solo monitorear progreso - el backend maneja la continuación automáticamente
        // No llamar a processNextBatch() - esto causa múltiples PIDs y race conditions
        // El backend procesa todos los lotes automáticamente sin intervención del frontend
      }
    } else {
      // ✅ ELIMINADO: Mensajes de error en barras - ahora se muestra en consola en tiempo real
    }
  } else {
    // ✅ ELIMINADO: Mensajes de error en barras - ahora se muestra en consola en tiempo real
  }
}

/**
 * Verifica si es un error de timeout
 *
 * @param {Object} xhr - Objeto XMLHttpRequest
 * @param {string} error - Mensaje de error
 * @returns {boolean} True si es un error de timeout
 * @private
 */
function isTimeoutError(xhr, error) {
  return (xhr && xhr.readyState === 0 && xhr.status === 0) || (xhr && xhr.status === 0 && !error);
}

/**
 * Verifica si es un error crítico
 *
 * @param {Object} xhr - Objeto XMLHttpRequest
 * @returns {boolean} True si es un error crítico
 * @private
 */
function isCriticalError(xhr) {
  return !navigator.onLine || (xhr && xhr.status === 403);
}

/**
 * Obtiene el umbral de errores desde la configuración
 *
 * @param {string} thresholdType - Tipo de umbral ('to_slow' o 'max_errors')
 * @param {number} defaultValue - Valor por defecto
 * @returns {number} Umbral de errores
 * @private
 */
function getErrorThreshold(thresholdType, defaultValue) {
  // eslint-disable-next-line prefer-optional-chain
  if (typeof pollingManager !== 'undefined' && pollingManager && pollingManager.config && pollingManager.config.thresholds && pollingManager.config.thresholds[thresholdType]) {
    return pollingManager.config.thresholds[thresholdType];
  }
  return defaultValue;
}

/**
 * Resetea la UI de sincronización (botones y selectores)
 *
 * @param {string} [buttonText] - Texto para el botón de sincronización
 * @returns {void}
 * @private
 */
function resetSyncUI(buttonText) {
  const originalText = buttonText || (typeof window !== 'undefined' && window.originalSyncButtonText) || 'Sincronizar productos en lote';
  const DOM_CACHE = getDomCache();

  if (DOM_CACHE && DOM_CACHE.$syncBtn) {
    DOM_CACHE.$syncBtn.prop('disabled', false).text(originalText);
  }

  if (DOM_CACHE && DOM_CACHE.$batchSizeSelector) {
    DOM_CACHE.$batchSizeSelector.prop('disabled', false);
  }
}

/**
 * Configura el botón de reintento
 *
 * @returns {void}
 * @private
 */
function setupRetryButton() {
  if (typeof jQuery === 'undefined') {
    return;
  }

  jQuery('#mi-api-retry-sync').on('click', function() {
    check();
    const DOM_CACHE = getDomCache();
    if (DOM_CACHE && DOM_CACHE.$feedback) {
      DOM_CACHE.$feedback.addClass('in-progress').text('Verificando estado de la sincronización...');
    }
  });
}

/**
 * Muestra un mensaje de error en el feedback
 *
 * @param {string} message - Mensaje de error
 * @param {string} [type='error'] - Tipo de mensaje ('error' o 'warning')
 * @returns {void}
 * @private
 */
function showErrorFeedback(message, type) {
  const DOM_CACHE = getDomCache();
  if (!DOM_CACHE || !DOM_CACHE.$feedback) {
    return;
  }

  const typeClass = type === 'warning' ? 'warning' : '';
  const removeClasses = type === 'warning' ? 'in-progress' : 'in-progress warning';

  DOM_CACHE.$feedback.removeClass(removeClasses).addClass(typeClass).html(message);
}

/**
 * Maneja errores críticos (offline, 403)
 *
 * @returns {void}
 * @private
 */
function handleCriticalError() {
  if (typeof stopProgressPolling === 'function') {
    stopProgressPolling('Error crítico de conexión');
  }

  resetSyncUI();
}

/**
 * Maneja errores de timeout cuando se alcanza el máximo de errores
 *
 * @returns {void}
 * @private
 */
function handleMaxErrorsReached() {
  if (typeof stopProgressPolling === 'function') {
    stopProgressPolling('Demasiados errores consecutivos');
  }

  resetSyncUI('Sincronizar productos en lote');

  showErrorFeedback(
    '<div class="mi-api-error"><strong>Error de comunicación:</strong> El servidor no responde después de varios intentos. ' +
    '<p>La sincronización podría estar funcionando en segundo plano o haberse detenido. Verifique los registros del sistema.</p>' +
    '<button id="mi-api-retry-sync" class="button">Reintentar verificación</button></div>',
    'error'
  );

  setupRetryButton();

  const DOM_CACHE = getDomCache();
  if (DOM_CACHE && DOM_CACHE.$syncStatusContainer) {
    DOM_CACHE.$syncStatusContainer.hide();
  }
}

/**
 * Maneja errores de timeout cuando se supera el umbral
 *
 * @param {number} errorThreshold - Umbral de errores
 * @returns {void}
 * @private
 */
function handleTimeoutWarning(errorThreshold) {
  if (inactiveProgressCounter === errorThreshold + 1) {
    showErrorFeedback(
      '<div class="mi-api-warning"><strong>El servidor está tardando en responder</strong><p>La sincronización podría estar funcionando en segundo plano. ' +
      'Espere unos minutos o verifique los registros para confirmar el estado.</p></div>',
      'warning'
    );
  }
}

/**
 * Maneja errores de timeout
 *
 * @returns {void}
 * @private
 */
function handleTimeoutError() {
  const errorThreshold = getErrorThreshold('to_slow', 3);

  if (typeof inactiveProgressCounter === 'undefined' || inactiveProgressCounter <= errorThreshold) {
    return;
  }

  handleTimeoutWarning(errorThreshold);

  const maxErrors = getErrorThreshold('max_errors', 5);
  if (inactiveProgressCounter > maxErrors) {
    handleMaxErrorsReached();
  }
}

/**
 * Maneja errores generales después de múltiples intentos
 *
 * @param {Object} xhr - Objeto XMLHttpRequest
 * @returns {void}
 * @private
 */
function handleGeneralError(xhr) {
  if (typeof stopProgressPolling === 'function') {
    stopProgressPolling('Errores consecutivos');
  }

  resetSyncUI('Sincronizar productos en lote');

  const statusMessage = xhr.status ? `Código HTTP: ${xhr.status}` : 'Verifique la conexión al servidor.';
  showErrorFeedback(
    '<div class="mi-api-error"><strong>Error de conexión:</strong> No se puede verificar el progreso. ' +
    statusMessage +
    '<p>Intente recargar la página o esperar unos minutos.</p></div>',
    'error'
  );

  const DOM_CACHE = getDomCache();
  if (DOM_CACHE && DOM_CACHE.$syncStatusContainer) {
    DOM_CACHE.$syncStatusContainer.hide();
  }
}

/**
 * Maneja los errores de la verificación de progreso
 *
 * @param {Object} xhr - Objeto XMLHttpRequest
 * @param {string} status - Estado de la petición
 * @param {string} error - Mensaje de error
 * @returns {void}
 * @private
 */
function handleError(xhr, status, error) {
  // ✅ NUEVO: Emitir evento de error a través de PollingManager
  if (typeof window !== 'undefined' && window.pollingManager && typeof window.pollingManager.emit === 'function') {
    window.pollingManager.emit('syncError', {
      message: error || 'Error en sincronización',
      status: status,
      xhr: xhr,
      timestamp: Date.now()
    });
  }

  if (typeof ErrorHandler === 'undefined' || !ErrorHandler) {
    return;
  }

  ErrorHandler.logError(`Error AJAX al verificar progreso: ${error}`, 'POLLING');

  // SIMPLIFICADO: Solo mostrar error de conexión básico
  if (typeof ErrorHandler.showConnectionError === 'function') {
    ErrorHandler.showConnectionError(xhr);
  }

  // SIMPLIFICADO: Solo casos críticos que requieren detener polling
  if (isCriticalError(xhr)) {
    handleCriticalError();
    return;
  }

  // Si es un error de timeout (readyState 0 o status 0 con error vacío), dar un mensaje específico
  if (isTimeoutError(xhr, error)) {
    handleTimeoutError();
  } else if (typeof inactiveProgressCounter !== 'undefined' && inactiveProgressCounter > 3) {
    // Para otros tipos de errores, después de 3 intentos fallidos
    handleGeneralError(xhr);
  }
}

/**
 * Resetea el estado de seguimiento
 *
 * @returns {void}
 * @private
 */
function resetTrackingState() {
  trackingState.lastKnownBatch = 0;
  trackingState.lastKnownItemsSynced = 0;
  trackingState.lastKnownTotalBatches = 0;
  trackingState.lastKnownTotalItems = 0;
}

/**
 * Obtiene el estado de seguimiento actual
 *
 * @returns {Object} Estado de seguimiento
 */
function getTrackingState() {
  return Object.assign({}, trackingState);
}

/**
 * Objeto SyncProgress con métodos públicos
 */
const SyncProgress = {
  check,
  getTrackingState,
  resetTrackingState
};

/**
 * Exponer SyncProgress globalmente para mantener compatibilidad
 * con el código existente que usa window.checkSyncProgress
 */
if (typeof window !== 'undefined') {
  try {
    window.SyncProgress = SyncProgress;
    // Exponer también la función check como checkSyncProgress para compatibilidad
    window.checkSyncProgress = check;
  } catch (error) {
    try {
      Object.defineProperty(window, 'SyncProgress', {
        value: SyncProgress,
        writable: true,
        enumerable: true,
        configurable: true
      });
      Object.defineProperty(window, 'checkSyncProgress', {
        value: check,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // Silenciar error de asignación
    }
  }
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { SyncProgress };
}
