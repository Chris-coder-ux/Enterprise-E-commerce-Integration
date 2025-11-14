/**
 * Controlador Principal de Sincronización
 *
 * Gestiona el flujo principal de sincronización, incluyendo confirmación del usuario,
 * configuración de UI, y orquestación de las fases de sincronización.
 *
 * @module sync/SyncController
 * @namespace SyncController
 * @since 1.0.0
 * @author Christian
 */

/* global jQuery, miIntegracionApiDashboard, DASHBOARD_CONFIG, DOM_CACHE, Phase1Manager, SyncStateManager, ErrorHandler, window */

/**
 * Muestra el diálogo de confirmación para iniciar la sincronización
 *
 * @param {string|null} customMessage - Mensaje personalizado de confirmación
 * @returns {boolean} true si el usuario confirma, false si cancela
 * @private
 */
function showConfirmationDialog(customMessage) {
  const defaultMessage = '¿Estás seguro de que deseas iniciar una sincronización manual ahora?';
  const message = customMessage || defaultMessage;

  try {
    if (typeof confirm === 'function') {
      return confirm(message);
    } else {
      // eslint-disable-next-line no-console
      console.warn('confirm() no está disponible');
      return false;
    }
  } catch (error) {
    // eslint-disable-next-line no-console
    console.error('Error al llamar confirm():', error);
    try {
      return confirm(defaultMessage);
    } catch (fallbackError) {
      // eslint-disable-next-line no-console
      console.error('Error en fallback confirm():', fallbackError);
      // Si todo falla, proceder sin confirmación
      return true;
    }
  }
}

/**
 * Restaura el estado de los controles UI cuando se cancela la sincronización
 *
 * @param {string} originalText - Texto original del botón
 * @returns {void}
 * @private
 */
function restoreUICancelled(originalText) {
  if (DOM_CACHE && DOM_CACHE.$syncBtn) {
    DOM_CACHE.$syncBtn.prop('disabled', false).text(originalText || 'Sincronizar productos en lote');
  }
  if (DOM_CACHE && DOM_CACHE.$batchSizeSelector) {
    DOM_CACHE.$batchSizeSelector.prop('disabled', false);
  }
}

/**
 * Configura la UI para iniciar la sincronización
 *
 * @returns {void}
 * @private
 */
function setupSyncUI() {
  if (DOM_CACHE && DOM_CACHE.$syncBtn) {
    DOM_CACHE.$syncBtn.prop('disabled', true);
  }
  if (DOM_CACHE && DOM_CACHE.$batchSizeSelector) {
    DOM_CACHE.$batchSizeSelector.prop('disabled', true);
  }
  if (DOM_CACHE && DOM_CACHE.$feedback) {
    DOM_CACHE.$feedback.addClass('in-progress').text('Iniciando sincronización...');
  }
  if (DOM_CACHE && DOM_CACHE.$syncStatusContainer) {
    DOM_CACHE.$syncStatusContainer.css('display', 'block');
  }
  if (DOM_CACHE && DOM_CACHE.$cancelBtn) {
    DOM_CACHE.$cancelBtn.prop('disabled', false).removeClass('button-secondary').addClass('button-secondary');
  }
  if (DOM_CACHE && DOM_CACHE.$progressBar) {
    DOM_CACHE.$progressBar.css({
      width: '2%',
      'background-color': '#0073aa',
      transition: 'width 0.3s ease'
    });
  }
  if (DOM_CACHE && DOM_CACHE.$progressInfo && DASHBOARD_CONFIG && DASHBOARD_CONFIG.messages && DASHBOARD_CONFIG.messages.progress) {
    DOM_CACHE.$progressInfo.text(DASHBOARD_CONFIG.messages.progress.preparing);
  }
}

/**
 * Resetea los contadores de sincronización usando SyncStateManager
 *
 * @returns {void}
 * @private
 */
function resetSyncCounters() {
  if (typeof SyncStateManager !== 'undefined' && SyncStateManager) {
    if (typeof SyncStateManager.setInactiveProgressCounter === 'function') {
      SyncStateManager.setInactiveProgressCounter(0);
    }
    if (typeof SyncStateManager.setLastProgressValue === 'function') {
      SyncStateManager.setLastProgressValue(0);
    }
  }
}

/**
 * Inicia el proceso de sincronización
 *
 * Orquesta el flujo completo de sincronización, incluyendo confirmación del usuario,
 * configuración de UI, y inicio de la Fase 1.
 *
 * @param {string} originalText - Texto original del botón de sincronización
 * @returns {void}
 *
 * @example
 * SyncController.proceedWithSync('Sincronizar productos en lote');
 */
function proceedWithSync(originalText) {
  try {
    // Verificar dependencias críticas
    if (typeof jQuery === 'undefined') {
      if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
        ErrorHandler.logError('jQuery no está disponible para SyncController', 'SYNC_START');
      }
      return;
    }

    if (typeof DOM_CACHE === 'undefined' || !DOM_CACHE) {
      if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
        ErrorHandler.logError('DOM_CACHE no está disponible', 'SYNC_START');
      }
      return;
    }

    // Actualizar la variable global con el texto original
    if (typeof window !== 'undefined') {
      window.originalSyncButtonText = originalText;
    }

    // Obtener batch size del selector
    const batchSize = (DOM_CACHE.$batchSizeSelector && DOM_CACHE.$batchSizeSelector.val())
      ? parseInt(DOM_CACHE.$batchSizeSelector.val(), 10) || 20
      : 20;

    // Verificar si hay mensaje de confirmación y mostrar un diálogo
    const confirmMessage = (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard && miIntegracionApiDashboard.confirmSync)
      ? miIntegracionApiDashboard.confirmSync
      : null;

    const confirmResult = showConfirmationDialog(confirmMessage);

    if (!confirmResult) {
      // Restaurar botón cuando se cancela
      restoreUICancelled(originalText);
      return;
    }

    // Configurar UI una sola vez
    setupSyncUI();

    // Resetear contadores usando SyncStateManager
    resetSyncCounters();

    // ✅ NUEVO: Arquitectura en dos fases - Primero ejecutar Fase 1 (sincronización de imágenes)
    // Usar Phase1Manager para iniciar Fase 1
    if (typeof Phase1Manager !== 'undefined' && Phase1Manager && typeof Phase1Manager.start === 'function') {
      Phase1Manager.start(batchSize, originalText);
    } else {
      // Fallback: si Phase1Manager no está disponible, mostrar error
      // eslint-disable-next-line no-console
      console.error('Phase1Manager no está disponible');
      if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
        ErrorHandler.logError('Phase1Manager no está disponible', 'SYNC_START');
      }
      if (DOM_CACHE && DOM_CACHE.$syncBtn) {
        DOM_CACHE.$syncBtn.prop('disabled', false).text(originalText);
      }
      if (DOM_CACHE && DOM_CACHE.$batchSizeSelector) {
        DOM_CACHE.$batchSizeSelector.prop('disabled', false);
      }
      if (DOM_CACHE && DOM_CACHE.$feedback) {
        DOM_CACHE.$feedback.text('Error: Phase1Manager no está disponible');
      }
    }

  } catch (error) {
    // eslint-disable-next-line no-console
    console.error('Error en proceedWithSync():', error);
    // eslint-disable-next-line no-console
    console.error('Stack trace:', error.stack);

    // Restaurar botón en caso de error
    if (DOM_CACHE && DOM_CACHE.$syncBtn) {
      DOM_CACHE.$syncBtn.prop('disabled', false).text('Sincronizar productos en lote');
    }
    if (DOM_CACHE && DOM_CACHE.$batchSizeSelector) {
      DOM_CACHE.$batchSizeSelector.prop('disabled', false);
    }
    if (DOM_CACHE && DOM_CACHE.$feedback) {
      DOM_CACHE.$feedback.removeClass('in-progress').text('Error: ' + (error.message || 'Error desconocido'));
    }
  }
}

/**
 * Objeto SyncController con métodos públicos
 */
const SyncController = {
  proceedWithSync
};

/**
 * Exponer SyncController globalmente para mantener compatibilidad
 * con el código existente que usa window.SyncController y window.proceedWithSync
 */
// eslint-disable-next-line no-restricted-globals
if (typeof window !== 'undefined') {
  try {
    // eslint-disable-next-line no-restricted-globals
    window.SyncController = SyncController;
    // Exponer también la función proceedWithSync para compatibilidad
    // eslint-disable-next-line no-restricted-globals
    window.proceedWithSync = proceedWithSync;
  } catch (error) {
    try {
      // eslint-disable-next-line no-restricted-globals
      Object.defineProperty(window, 'SyncController', {
        value: SyncController,
        writable: true,
        enumerable: true,
        configurable: true
      });
      // eslint-disable-next-line no-restricted-globals
      Object.defineProperty(window, 'proceedWithSync', {
        value: proceedWithSync,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar SyncController a window:', defineError, error);
      }
    }
  }
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { SyncController };
}
