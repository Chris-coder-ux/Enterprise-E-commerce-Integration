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

/* global jQuery, miIntegracionApiDashboard, DASHBOARD_CONFIG, DomUtils, pollingManager, ErrorHandler, AjaxManager, stopProgressPolling, SyncStateManager, window, Sanitizer, addConsoleLine, ToastManager, EventCleanupManager, UIOptimizer */

/**
 * Variables de seguimiento del progreso
 *
 * @type {Object}
 * @property {number} lastKnownBatch - Último lote conocido
 * @property {number} lastKnownItemsSynced - Últimos items sincronizados conocidos
 * @property {number} lastKnownTotalBatches - Total de lotes conocido
 * @property {number} lastKnownTotalItems - Total de items conocido
 * @property {number} lastProgressTimestamp - Timestamp del último progreso detectado
 * @property {number} autoProcessTimeout - Timeout para procesamiento automático de lotes
 * @property {number} lastBatchStartTime - Timestamp del inicio del último lote procesado
 * @property {Array<number>} batchProcessingTimes - Array de tiempos de procesamiento de lotes (en ms)
 * @property {number} maxBatchTimesHistory - Máximo de tiempos históricos a mantener (10)
 */
const trackingState = {
  lastKnownBatch: 0,
  lastKnownItemsSynced: 0,
  lastKnownTotalBatches: 0,
  lastKnownTotalItems: 0,
  lastProgressTimestamp: 0,
  autoProcessTimeout: null,
  lastBatchStartTime: 0,
  batchProcessingTimes: [],
  maxBatchTimesHistory: 10
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
 * Obtiene la configuración del umbral de stall
 *
 * @returns {Object} Configuración del umbral con valores mínimos, máximos y multiplicador
 * @property {number} min - Umbral mínimo en ms (por defecto: 10000 = 10 segundos)
 * @property {number} max - Umbral máximo en ms (por defecto: 60000 = 60 segundos)
 * @property {number} default - Umbral por defecto en ms (por defecto: 15000 = 15 segundos)
 * @property {number} multiplier - Multiplicador para el promedio dinámico (por defecto: 2.0)
 * @property {number} minSamples - Mínimo de muestras necesarias para usar promedio dinámico (por defecto: 2)
 * @private
 */
function getStallThresholdConfig() {
  // Intentar obtener configuración desde miIntegracionApiDashboard
  if (typeof miIntegracionApiDashboard !== 'undefined' && 
      miIntegracionApiDashboard && 
      miIntegracionApiDashboard.stallThresholdConfig) {
    const config = miIntegracionApiDashboard.stallThresholdConfig;
    return {
      min: config.min || 10000,
      max: config.max || 60000,
      default: config.default || 15000,
      multiplier: config.multiplier || 2.0,
      minSamples: config.minSamples || 2
    };
  }
  
  // Intentar obtener desde DASHBOARD_CONFIG
  if (typeof DASHBOARD_CONFIG !== 'undefined' && 
      DASHBOARD_CONFIG && 
      DASHBOARD_CONFIG.stallThreshold) {
    const config = DASHBOARD_CONFIG.stallThreshold;
    return {
      min: config.min || 10000,
      max: config.max || 60000,
      default: config.default || 15000,
      multiplier: config.multiplier || 2.0,
      minSamples: config.minSamples || 2
    };
  }
  
  // Valores por defecto
  return {
    min: 10000,      // 10 segundos mínimo
    max: 60000,     // 60 segundos máximo
    default: 15000, // 15 segundos por defecto
    multiplier: 2.0, // Multiplicar promedio por 2x
    minSamples: 2    // Mínimo 2 muestras para usar promedio dinámico
  };
}

/**
 * Calcula el promedio de tiempos de procesamiento de lotes
 *
 * @returns {number|null} Promedio en ms o null si no hay suficientes muestras
 * @private
 */
function calculateAverageBatchProcessingTime() {
  const times = trackingState.batchProcessingTimes;
  if (!times || times.length === 0) {
    return null;
  }
  
  const sum = times.reduce(function(acc, time) {
    return acc + time;
  }, 0);
  
  return Math.round(sum / times.length);
}

/**
 * Obtiene el umbral dinámico de stall basado en métricas históricas
 *
 * Calcula el umbral basado en el promedio de tiempos de procesamiento de lotes anteriores.
 * Si no hay suficientes datos históricos, usa el valor por defecto configurado.
 *
 * @returns {number} Umbral de stall en milisegundos
 * @private
 */
function getDynamicStallThreshold() {
  const config = getStallThresholdConfig();
  const averageTime = calculateAverageBatchProcessingTime();
  
  // Si no hay suficientes muestras, usar valor por defecto
  if (averageTime === null || trackingState.batchProcessingTimes.length < config.minSamples) {
    return config.default;
  }
  
  // Calcular umbral dinámico: promedio * multiplicador
  const dynamicThreshold = Math.round(averageTime * config.multiplier);
  
  // Aplicar límites mínimo y máximo
  const clampedThreshold = Math.max(config.min, Math.min(config.max, dynamicThreshold));
  
  return clampedThreshold;
}

/**
 * Registra el tiempo de procesamiento de un lote completado
 *
 * @returns {void}
 * @private
 */
function recordBatchProcessingTime() {
  if (trackingState.lastBatchStartTime === 0) {
    // No hay tiempo de inicio registrado, establecerlo ahora
    trackingState.lastBatchStartTime = Date.now();
    return;
  }
  
  const processingTime = Date.now() - trackingState.lastBatchStartTime;
  
  // Agregar tiempo al historial
  trackingState.batchProcessingTimes.push(processingTime);
  
  // Mantener solo los últimos N tiempos (usando maxBatchTimesHistory)
  if (trackingState.batchProcessingTimes.length > trackingState.maxBatchTimesHistory) {
    trackingState.batchProcessingTimes.shift(); // Eliminar el más antiguo
  }
  
  // Resetear tiempo de inicio para el siguiente lote
  trackingState.lastBatchStartTime = Date.now();
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

  // ✅ NUEVO: Medir tiempo de inicio de la petición para calcular latencia
  const requestStartTime = Date.now();
  
  // ✅ NUEVO: Función para registrar latencia y errores
  const recordLatency = function(success) {
    const responseTime = Date.now() - requestStartTime;
    
    // Registrar latencia usando PollingManager si está disponible
    if (typeof pollingManager !== 'undefined' && pollingManager && typeof pollingManager.recordResponseTime === 'function') {
      pollingManager.recordResponseTime(responseTime);
    }
    
    // Si hay error, registrar usando PollingManager
    if (!success && typeof pollingManager !== 'undefined' && pollingManager && typeof pollingManager.recordError === 'function') {
      pollingManager.recordError();
    }
  };

  // Intentar usar AjaxManager primero
  if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
    AjaxManager.call(
      'mia_get_sync_progress',
      {},
      function(response) {
        recordLatency(true);
        handleSuccess(response);
      },
      function(xhr, status, error) {
        recordLatency(false);
        handleError(xhr, status, error);
      },
      { timeout }
    );
  } else {
    // Fallback a jQuery.ajax directo
    jQuery.ajax({
      url: ajaxUrl,
      type: 'POST',
      timeout,
      data: {
        action: 'mia_get_sync_progress',
        nonce: miIntegracionApiDashboard.nonce
      },
      success(response) {
        recordLatency(true);
        handleSuccess(response);
      },
      error(xhr, status, error) {
        recordLatency(false);
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
  let syncMeta = {};

  // Mostrar headers y status completo para diagnóstico
  if (response.success) {
    if (response.data) {
      // CORRECCIÓN: Usar estructura flexible para diferentes endpoints
      porcentaje = response.data.porcentaje || response.data.progress || 0;

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
        total_batches: response.data.total_batches || response.data.total || 1,
        cancelled: response.data.cancelled === true || response.data.cancelled === 'true' // ✅ NUEVO: Detectar cancelación
      };
      
      // ✅ NUEVO: Si la sincronización fue cancelada, detener polling y resetear estado
      if (syncMeta.cancelled) {
        if (typeof stopProgressPolling === 'function') {
          stopProgressPolling('Sincronización cancelada');
        }
        
        // ✅ NUEVO: Resetear Phase2Manager si está disponible
        if (typeof window !== 'undefined' && window.Phase2Manager && typeof window.Phase2Manager.reset === 'function') {
          window.Phase2Manager.reset();
        }
        
        // ✅ NUEVO: Detener polling directamente si stopProgressPolling no está disponible
        if (typeof pollingManager !== 'undefined' && pollingManager && typeof pollingManager.stopPolling === 'function') {
          pollingManager.stopPolling('syncProgress');
        }
        
        resetTrackingState();
        return; // No procesar más datos si está cancelada
      }

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
          phase1Status,
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
            if (typeof window !== 'undefined' && typeof window.updateSyncConsole === 'function') {
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
          if (typeof window === 'undefined' || !window.pollingManager || typeof window.pollingManager.emit !== 'function') {
            if (typeof window !== 'undefined' && typeof window.updateSyncConsole === 'function') {
              window.updateSyncConsole(response.data, phase1Status);
            } else if (typeof window !== 'undefined' && window.ConsoleManager && typeof window.ConsoleManager.updateSyncConsole === 'function') {
              window.ConsoleManager.updateSyncConsole(response.data, phase1Status);
            }
          }
        }
      } else {
        // ✅ CENTRALIZADO: Si no hay syncDashboard, actualizar consola directamente (solo si no hay eventos)
        if (typeof window === 'undefined' || !window.pollingManager || typeof window.pollingManager.emit !== 'function') {
          if (typeof window !== 'undefined' && typeof window.updateSyncConsole === 'function') {
            window.updateSyncConsole(response.data, phase1Status);
          } else if (typeof window !== 'undefined' && window.ConsoleManager && typeof window.ConsoleManager.updateSyncConsole === 'function') {
            window.ConsoleManager.updateSyncConsole(response.data, phase1Status);
          }
        }
      }

      // ✅ ELIMINADO: Mensajes de progreso para barras - ahora se muestra en consola en tiempo real

      // ACTUALIZAR VARIABLES DE SEGUIMIENTO si hay cambios significativos
      if (hasSignificantChange) {
        // ✅ NUEVO: Registrar tiempo de procesamiento si cambió el lote
        if (batchChanged && syncMeta.current_batch > trackingState.lastKnownBatch) {
          recordBatchProcessingTime();
        }
        
        // Actualizar variables de seguimiento
        trackingState.lastKnownBatch = syncMeta.current_batch;
        trackingState.lastKnownItemsSynced = estadisticas.procesados;
        trackingState.lastKnownTotalBatches = syncMeta.total_batches;
        trackingState.lastKnownTotalItems = estadisticas.total;
        
        // ✅ NUEVO: Actualizar timestamp cuando hay progreso
        trackingState.lastProgressTimestamp = Date.now();
        
        // ✅ NUEVO: Establecer tiempo de inicio del lote si es el primer progreso de este lote
        if (trackingState.lastBatchStartTime === 0) {
          trackingState.lastBatchStartTime = Date.now();
        }
        
        // Limpiar timeout si hay progreso (el backend está funcionando)
        if (trackingState.autoProcessTimeout) {
          clearTimeout(trackingState.autoProcessTimeout);
          trackingState.autoProcessTimeout = null;
        }
      }

      // Actualizar lastProgressValue si cambió usando SyncStateManager
      if (typeof SyncStateManager !== 'undefined' && SyncStateManager && typeof SyncStateManager.setLastProgressValue === 'function') {
        const currentLastProgress = SyncStateManager.getLastProgressValue();
        if (porcentaje !== currentLastProgress) {
          SyncStateManager.setLastProgressValue(porcentaje);
        }
      }

      // ✅ ELIMINADO: Actualización de información de progreso - ahora se muestra en consola en tiempo real

      // Usar campo is_completed calculado por el backend para consistencia
      const isCompleted = response.data.is_completed || false;

      if (isCompleted) {
        // ✅ CORRECCIÓN: Emitir eventos ANTES de detener polling para actualizar frontend inmediatamente
        // Esto asegura que el frontend se actualice automáticamente sin necesidad de interacción del usuario
        if (typeof window !== 'undefined' && window.pollingManager && typeof window.pollingManager.emit === 'function') {
          // Emitir último evento syncProgress con estado final para actualizar ConsoleManager y otros componentes
          window.pollingManager.emit('syncProgress', {
            syncData: response.data,
            phase1Status,
            timestamp: Date.now()
          });

          // Emitir evento syncCompleted para que SyncDashboard actualice el estado de Fase 2
          window.pollingManager.emit('syncCompleted', {
            phase: 2,
            syncData: response.data,
            phase1Status,
            timestamp: Date.now()
          });
        }

        // Detener polling y resetear estado
        if (typeof stopProgressPolling === 'function') {
          stopProgressPolling('Sincronización completada');
        }

        if (DOM_CACHE.$syncBtn) {
          const originalText = (typeof window !== 'undefined' && window.originalSyncButtonText) || 'Sincronizar productos en lote';
          // ✅ OPTIMIZADO: Usar UIOptimizer para evitar actualizaciones innecesarias
          if (typeof UIOptimizer !== 'undefined' && UIOptimizer && typeof UIOptimizer.updateTextIfChanged === 'function') {
            DOM_CACHE.$syncBtn.prop('disabled', false);
            UIOptimizer.updateTextIfChanged(DOM_CACHE.$syncBtn, originalText, 'sync-btn-text');
          } else {
            // Fallback: actualización directa
            DOM_CACHE.$syncBtn.prop('disabled', false).text(originalText);
          }
        }

        if (DOM_CACHE.$batchSizeSelector) {
          DOM_CACHE.$batchSizeSelector.prop('disabled', false);
        }

        if (DOM_CACHE.$feedback) {
          // ✅ OPTIMIZADO: Usar UIOptimizer para evitar actualizaciones innecesarias
          DOM_CACHE.$feedback.removeClass('in-progress');
          if (typeof UIOptimizer !== 'undefined' && UIOptimizer && typeof UIOptimizer.updateTextIfChanged === 'function') {
            UIOptimizer.updateTextIfChanged(DOM_CACHE.$feedback, '¡Sincronización completada!', 'sync-feedback');
          } else {
            // Fallback: actualización directa
            DOM_CACHE.$feedback.text('¡Sincronización completada!');
          }
        }

        // Resetear variables de seguimiento
        resetTrackingState();
      } else if (syncMeta.in_progress) {
        // ✅ NUEVO: Detectar lotes pendientes y procesarlos automáticamente si WordPress Cron no funciona
        const currentBatch = syncMeta.current_batch || 0;
        const totalBatches = syncMeta.total_batches || 1;
        const hasPendingBatches = currentBatch < totalBatches;
        
        if (hasPendingBatches) {
          // ✅ MEJORADO: Verificar si el progreso se ha detenido usando umbral dinámico
          // Solo verificar si no hay cambios significativos (no se actualizó el timestamp)
          if (!hasSignificantChange) {
            const timeSinceLastProgress = Date.now() - (trackingState.lastProgressTimestamp || Date.now());
            
            // ✅ NUEVO: Obtener umbral dinámico basado en métricas históricas
            const stallThreshold = getDynamicStallThreshold();
            const progressStalled = timeSinceLastProgress > stallThreshold;
            
            // Si el progreso se ha detenido y hay lotes pendientes, procesar automáticamente
            if (progressStalled && !trackingState.autoProcessTimeout) {
              const averageTime = calculateAverageBatchProcessingTime();
              const samplesCount = trackingState.batchProcessingTimes.length;
              const thresholdType = samplesCount >= getStallThresholdConfig().minSamples ? 'dinámico' : 'por defecto';
              
              // ✅ NUEVO: Mostrar mensaje informativo en consola para el usuario
              const stallMessage = `⚠️ Progreso detenido detectado (sin cambios por ${Math.round(timeSinceLastProgress / 1000)}s). ` +
                `Procesando lote ${currentBatch}/${totalBatches} manualmente... ` +
                `(Umbral ${thresholdType}: ${Math.round(stallThreshold / 1000)}s)`;
              
              // Agregar mensaje a la consola si ConsoleManager está disponible
              if (typeof window !== 'undefined' && window.ConsoleManager && typeof window.ConsoleManager.addLine === 'function') {
                window.ConsoleManager.addLine('warning', stallMessage);
              } else if (typeof addConsoleLine === 'function') {
                addConsoleLine('warning', stallMessage);
              } else {
                // eslint-disable-next-line no-console
                console.log('⚠️ Progreso detenido detectado, procesando siguiente lote automáticamente...', {
                  currentBatch,
                  totalBatches,
                  timeSinceLastProgress: Math.round(timeSinceLastProgress / 1000) + 's',
                  stallThreshold: Math.round(stallThreshold / 1000) + 's',
                  averageBatchTime: averageTime ? Math.round(averageTime / 1000) + 's' : 'N/A',
                  samplesCount,
                  thresholdType,
                  lastProgressTimestamp: trackingState.lastProgressTimestamp
                });
              }
              
              // ✅ NUEVO: Mostrar notificación toast para mayor visibilidad
              const toastMessage = `Procesando lote ${currentBatch}/${totalBatches} manualmente (WordPress Cron no responde)`;
              if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
                ToastManager.show(toastMessage, 'warning', 5000);
              } else if (typeof window !== 'undefined' && window.ToastManager && typeof window.ToastManager.show === 'function') {
                window.ToastManager.show(toastMessage, 'warning', 5000);
              }
              
              // Procesar siguiente lote automáticamente
              processNextBatchAutomatically();
            }
          }
        } else {
          // No hay lotes pendientes, limpiar timeout
          if (trackingState.autoProcessTimeout) {
            clearTimeout(trackingState.autoProcessTimeout);
            trackingState.autoProcessTimeout = null;
          }
        }
      }
    } else {
      // ✅ ELIMINADO: Mensajes de error en barras - ahora se muestra en consola en tiempo real
    }
  } else {
    // ✅ ELIMINADO: Mensajes de error en barras - ahora se muestra en consola en tiempo real
  }
}

/**
 * Procesa el siguiente lote automáticamente cuando WordPress Cron no funciona
 * 
 * Delega a Phase2Manager para mantener la separación de responsabilidades.
 * Phase2Manager es responsable de la lógica de Fase 2, mientras que SyncProgress
 * solo monitorea el progreso y detecta cuando es necesario procesar lotes.
 *
 * @returns {void}
 * @private
 */
function processNextBatchAutomatically() {
  // Evitar múltiples llamadas simultáneas usando timeout local
  if (trackingState.autoProcessTimeout) {
    return;
  }
  
  // Configurar timeout para evitar llamadas repetidas
  trackingState.autoProcessTimeout = setTimeout(() => {
    trackingState.autoProcessTimeout = null;
  }, 10000); // 10 segundos de cooldown
  
  // Delegar a Phase2Manager si está disponible
  if (typeof window !== 'undefined' && window.Phase2Manager && typeof window.Phase2Manager.processNextBatchAutomatically === 'function') {
    window.Phase2Manager.processNextBatchAutomatically();
    
    // Resetear timestamp para permitir siguiente verificación
    trackingState.lastProgressTimestamp = Date.now();
  } else {
    // eslint-disable-next-line no-console
    console.warn('⚠️ Phase2Manager no está disponible para procesar siguiente lote automáticamente');
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

  const componentId = 'SyncProgress';
  const retryHandler = function() {
    check();
    const DOM_CACHE = getDomCache();
    if (DOM_CACHE && DOM_CACHE.$feedback) {
      DOM_CACHE.$feedback.addClass('in-progress');
      if (typeof UIOptimizer !== 'undefined' && UIOptimizer && typeof UIOptimizer.updateTextIfChanged === 'function') {
        UIOptimizer.updateTextIfChanged(DOM_CACHE.$feedback, 'Verificando estado de la sincronización...', 'sync-feedback');
      } else {
        DOM_CACHE.$feedback.text('Verificando estado de la sincronización...');
      }
    }
  };
  
  if (typeof EventCleanupManager !== 'undefined' && EventCleanupManager && typeof EventCleanupManager.registerElementListener === 'function') {
    EventCleanupManager.registerElementListener('#mi-api-retry-sync', 'click', retryHandler, componentId);
  } else {
    jQuery('#mi-api-retry-sync').on('click', retryHandler);
  }
}

/**
 * Limpia todos los event listeners de SyncProgress
 * 
 * @returns {void}
 * @ignore - Función disponible para uso futuro o limpieza manual
 */
// eslint-disable-next-line no-unused-vars
function cleanupSyncProgressListeners() {
  if (typeof EventCleanupManager !== 'undefined' && EventCleanupManager && typeof EventCleanupManager.cleanupComponent === 'function') {
    EventCleanupManager.cleanupComponent('SyncProgress');
  } else {
    jQuery('#mi-api-retry-sync').off('click');
  }
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

  // ✅ SEGURIDAD: Sanitizar mensaje antes de insertarlo en el DOM
  const sanitizedMessage = (typeof Sanitizer !== 'undefined' && Sanitizer.sanitizeMessage) 
    ? Sanitizer.sanitizeMessage(message) 
    : String(message).replace(/[&<>"']/g, function(m) {
      const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#039;' };
      return map[m];
    });
  
  // ✅ OPTIMIZADO: Usar UIOptimizer para evitar actualizaciones innecesarias
  DOM_CACHE.$feedback.removeClass(removeClasses).addClass(typeClass);
  if (typeof UIOptimizer !== 'undefined' && UIOptimizer && typeof UIOptimizer.updateTextIfChanged === 'function') {
    // ✅ SEGURIDAD: Usar .text() en lugar de .html() para prevenir XSS
    UIOptimizer.updateTextIfChanged(DOM_CACHE.$feedback, sanitizedMessage, 'sync-feedback');
  } else {
    // Fallback: actualización directa
    // ✅ SEGURIDAD: Usar .text() en lugar de .html() para prevenir XSS
    DOM_CACHE.$feedback.text(sanitizedMessage);
  }
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
  const inactiveCounter = typeof SyncStateManager !== 'undefined' && SyncStateManager.getInactiveProgressCounter 
    ? SyncStateManager.getInactiveProgressCounter() 
    : 0;
  
  if (inactiveCounter === errorThreshold + 1) {
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
  const inactiveCounter = typeof SyncStateManager !== 'undefined' && SyncStateManager.getInactiveProgressCounter 
    ? SyncStateManager.getInactiveProgressCounter() 
    : 0;

  if (inactiveCounter <= errorThreshold) {
    return;
  }

  handleTimeoutWarning(errorThreshold);

  const maxErrors = getErrorThreshold('max_errors', 5);
  // ✅ CORREGIDO: Reutilizar inactiveCounter ya declarado arriba en lugar de redeclararlo
  
  if (inactiveCounter > maxErrors) {
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
      status,
      xhr,
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
  } else {
    const inactiveCounter = typeof SyncStateManager !== 'undefined' && SyncStateManager.getInactiveProgressCounter 
      ? SyncStateManager.getInactiveProgressCounter() 
      : 0;
    
    if (inactiveCounter > 3) {
      // Para otros tipos de errores, después de 3 intentos fallidos
      handleGeneralError(xhr);
    }
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
  trackingState.lastProgressTimestamp = 0;
  trackingState.lastBatchStartTime = 0;
  // ✅ NUEVO: Mantener historial de tiempos de procesamiento entre sincronizaciones
  // No resetear batchProcessingTimes para mantener métricas históricas
  // trackingState.batchProcessingTimes = [];
}

/**
 * Obtiene el estado de seguimiento actual
 *
 * @returns {Object} Estado de seguimiento
 */
function getTrackingState() {
  const state = Object.assign({}, trackingState);
  // ✅ NUEVO: Incluir métricas calculadas
  state.averageBatchProcessingTime = calculateAverageBatchProcessingTime();
  state.dynamicStallThreshold = getDynamicStallThreshold();
  state.stallThresholdConfig = getStallThresholdConfig();
  return state;
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
// ✅ SEGURIDAD: Exposición global usando solo métodos seguros (sin eval)
if (typeof window !== 'undefined') {
  // ✅ SEGURIDAD: Método 1: Asignación directa dentro de try...catch
  try {
    window.SyncProgress = SyncProgress;
    // Exponer también la función check como checkSyncProgress para compatibilidad
    window.checkSyncProgress = check;
    
    // Verificar que se expuso correctamente
    if (window.SyncProgress === SyncProgress && window.checkSyncProgress === check) {
      // ✅ Éxito, no hacer nada más
    }
  } catch (error) {
    // ✅ SEGURIDAD: Método 2: Object.defineProperty como fallback seguro
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
      // ✅ SEGURIDAD: Si ambos métodos fallan, registrar advertencia pero no usar eval
      /* eslint-disable no-console */
      if (typeof console !== 'undefined' && console.warn) {
        console.warn('[SyncProgress] ⚠️ No se pudo exponer SyncProgress usando métodos seguros:', defineError);
      }
      /* eslint-enable no-console */
    }
  }
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { SyncProgress };
}
