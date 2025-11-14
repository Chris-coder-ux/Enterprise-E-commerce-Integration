/**
 * Gestión de Tarjetas de Estadísticas
 *
 * Gestiona la actualización y visualización de las tarjetas de estadísticas
 * del dashboard, incluyendo memoria, reintentos, sincronización, productos, etc.
 *
 * @module ui/CardManager
 * @namespace CardManager
 * @since 1.0.0
 * @author Christian
 */

/* global jQuery, SELECTORS, miIntegracionApiDashboard, ToastManager */

/**
 * @typedef {function(string, string=, number=): void} ShowToastFunction
 * Función global para mostrar notificaciones toast
 */

/**
 * @type {ShowToastFunction|undefined}
 * @global
 */
let showToast;

/**
 * Gestor de tarjetas de estadísticas
 *
 * @namespace CardManager
 * @description Gestiona la actualización y visualización de las tarjetas de estadísticas
 * @since 1.0.0
 */
const CardManager = {
  /**
   * Actualiza los datos de una tarjeta específica mediante AJAX
   *
   * @function updateCardData
   * @param {jQuery} $card - Elemento jQuery de la tarjeta a actualizar
   * @returns {void}
   */
  updateCardData($card) {
    const cardType = $card.attr('class').match(/(memory|retries|sync|products|orders|last-sync)/);

    if (!cardType) {
      // eslint-disable-next-line no-console
      console.warn('Tipo de tarjeta no reconocido');
      return;
    }

    const cardTypeName = cardType[1];

    // Mostrar indicador de carga
    const $value = $card.find(SELECTORS.STAT_VALUE);
    const $desc = $card.find(SELECTORS.STAT_DESC);
    const originalValue = $value.text();
    const originalDesc = $desc.text();

    // Guardar valores originales en los datos de la tarjeta
    $card.data('original-value', originalValue);
    $card.data('original-desc', originalDesc);

    // Añadir clase de loading y mostrar indicador
    $card.addClass('loading');
    $value.html('<span class="dashicons dashicons-update spin"></span>');
    $desc.text('Actualizando...');

    // Timeout de seguridad para evitar que se quede en "Actualizando..." indefinidamente
    const timeoutId = setTimeout(function() {
      $card.removeClass('loading');
      $value.text(originalValue);
      $desc.text(originalDesc);
      if (typeof ToastManager !== 'undefined' && ToastManager.show) {
        ToastManager.show('Timeout: La actualización tardó demasiado', 'warning', 3000);
      } else if (typeof showToast !== 'undefined') {
        showToast('Timeout: La actualización tardó demasiado', 'warning', 3000);
      }
    }, 15000); // 15 segundos de timeout (aumentado para operaciones costosas)

    // Usar acción específica para memoria si es la tarjeta de memoria
    const action = (cardTypeName === 'memory') ? 'mia_refresh_memory_stats' : 'miaRefresh_systemStatus';
    const nonce = (cardTypeName === 'memory') ?
      (miIntegracionApiDashboard.memory_nonce || miIntegracionApiDashboard.nonce) :
      (miIntegracionApiDashboard.nonce || miIntegracionApiDashboard.dashboard_nonce);

    // Usar jQuery.ajax directamente para evitar problemas con AjaxManager
    jQuery.ajax({
      url: miIntegracionApiDashboard.ajaxurl,
      type: 'POST',
      data: {
        action,
        nonce
      },
      success(response) {
        clearTimeout(timeoutId);
        $card.removeClass('loading');

        if (response && response.success && response.data) {
          CardManager.updateSpecificCard($card, cardTypeName, response.data);
          if (typeof ToastManager !== 'undefined' && ToastManager.show) {
            ToastManager.show('Datos actualizados correctamente', 'success', 2000);
          } else if (typeof showToast !== 'undefined') {
            showToast('Datos actualizados correctamente', 'success', 2000);
          }
        } else {
          // Restaurar valores originales
          $value.text($card.data('original-value') || originalValue);
          $desc.text($card.data('original-desc') || originalDesc);
          const errorMessage = 'Error al actualizar datos: ' + ((response && response.data && response.data.message) || 'Respuesta inválida');
          if (typeof ToastManager !== 'undefined' && ToastManager.show) {
            ToastManager.show(errorMessage, 'error', 3000);
          } else if (typeof showToast !== 'undefined') {
            showToast(errorMessage, 'error', 3000);
          }
        }
      },
      error(xhr, status, error) {
        clearTimeout(timeoutId);
        $card.removeClass('loading');

        // Restaurar valores originales
        $value.text($card.data('original-value') || originalValue);
        $desc.text($card.data('original-desc') || originalDesc);

        let errorMessage = 'Error de conexión';
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          errorMessage = xhr.responseJSON.data.message;
        } else if (error) {
          errorMessage += ': ' + error;
        }

        if (typeof ToastManager !== 'undefined' && ToastManager.show) {
          ToastManager.show(errorMessage, 'error', 3000);
        } else if (typeof showToast !== 'undefined') {
          showToast(errorMessage, 'error', 3000);
        }
      }
    });
  },

  /**
   * Actualiza una tarjeta específica con los nuevos datos recibidos
   *
   * @function updateSpecificCard
   * @param {jQuery} $card - Elemento jQuery de la tarjeta a actualizar
   * @param {string} cardType - Tipo de tarjeta (memory, retries, sync, products, orders, last-sync)
   * @param {Object} data - Datos recibidos del servidor
   * @returns {void}
   */
  updateSpecificCard($card, cardType, data) {
    const $value = $card.find(SELECTORS.STAT_VALUE);
    const $desc = $card.find(SELECTORS.STAT_DESC);

    // Obtener valores originales como fallback
    const originalValue = $card.data('original-value') || $value.text();
    const originalDesc = $card.data('original-desc') || $desc.text();

    let updated = false;

    try {
      if (cardType === 'memory') {
        // Manejar datos de memoria que vienen directamente de mia_refresh_memory_stats
        if (data.usage_percentage !== undefined && data.status !== undefined) {
          const usage_percentage = data.usage_percentage || '0';
          const status = data.status || 'unknown';

          // Validar estado de memoria - solo aceptar estados válidos
          const validMemoryStates = ['healthy', 'warning', 'critical'];
          const validatedStatus = validMemoryStates.includes(status) ? status : 'unknown';

          // Actualizar contenido de la tarjeta
          $value.text(usage_percentage + '%');
          $desc.text(data.status_message || 'Estado de memoria');

          // Actualizar clases CSS para el estado
          $card.removeClass('healthy warning critical').addClass(validatedStatus);

          // Log de advertencia si se recibió un estado inválido
          if (!validMemoryStates.includes(status)) {
            // eslint-disable-next-line no-console
            console.warn('Estado de memoria inválido recibido:', status, 'Usando estado por defecto: unknown');
          }

          updated = true;
        } else if (data.memory && typeof data.memory === 'object') {
          // Fallback para datos anidados (compatibilidad)
          const usage_percentage = data.memory.usage_percentage || '0';
          const status_message = data.memory.status_message || 'Estado de memoria';

          $value.text(usage_percentage + '%');
          $desc.text(status_message);
          updated = true;
        }
      } else if (cardType === 'retries') {
        if (data.retry && typeof data.retry === 'object') {
          // Los datos vienen con success_rate y status_message
          const success_rate = data.retry.success_rate || '0';
          const status_message = data.retry.status_message || 'Sistema de reintentos';

          $value.text(success_rate + '%');
          $desc.text(status_message);
          updated = true;
        }
      } else if (cardType === 'sync') {
        if (data && data.sync && typeof data.sync === 'object') {
          // Los datos vienen con status_text y progress_message
          const status_text = data.sync.status_text || 'Desconocido';
          const progress_message = data.sync.progress_message || 'Estado de sincronización';

          $value.text(status_text);
          $desc.text(progress_message);
          updated = true;
        }
      } else if (cardType === 'products') {
        // Para productos, usar el conteo del endpoint general
        if (data.sync && data.sync.products_count !== undefined) {
          $value.text(data.sync.products_count);
          $desc.text('Total sincronizados');
          updated = true;
        } else {
          // Fallback: mantener valor original
          $value.text(originalValue);
          $desc.text(originalDesc);
        }
      } else if (cardType === 'orders') {
        // Para errores recientes, usar el conteo de errores del overall_health
        if (data.overall_health && data.overall_health.issues_count !== undefined) {
          $value.text(data.overall_health.issues_count);
          $desc.text('Problemas detectados');
          updated = true;
        } else if (data.sync && data.sync.errors !== undefined) {
          // Fallback: usar errores de sync si están disponibles
          $value.text(data.sync.errors);
          $desc.text('Errores en la última sync');
          updated = true;
        } else {
          // Fallback: mantener valor original
          $value.text(originalValue);
          $desc.text(originalDesc);
        }
      } else if (cardType === 'last-sync') {
        // Para última sincronización - usar status_text y progress_message
        if (data.sync && typeof data.sync === 'object') {
          const status_text = data.sync.status_text || 'Desconocido';
          const progress_message = data.sync.progress_message || 'Sin sincronizaciones recientes';

          $value.text(status_text);
          $desc.text(progress_message);
          updated = true;
        } else {
          // Fallback: mantener valor original
          $value.text(originalValue);
          $desc.text(originalDesc);
        }
      } else {
        // Tipo de tarjeta no manejado - mantener valores originales
      }

      // Si no se pudo actualizar, restaurar valores originales
      if (!updated) {
        $value.text(originalValue);
        $desc.text(originalDesc);
        // eslint-disable-next-line no-console
        console.warn('No se pudo actualizar la tarjeta:', cardType, 'Datos disponibles:', data);
        if (typeof ToastManager !== 'undefined' && ToastManager.show) {
          ToastManager.show('No se encontraron datos para actualizar esta tarjeta', 'warning', 2000);
        } else if (typeof showToast !== 'undefined') {
          showToast('No se encontraron datos para actualizar esta tarjeta', 'warning', 2000);
        }
      }
    } catch (error) {
      // eslint-disable-next-line no-console
      console.error('Error actualizando tarjeta:', error);
      // Restaurar valores originales en caso de error
      $value.text(originalValue);
      $desc.text(originalDesc);
    }
  }
};

/**
 * Exponer CardManager globalmente para mantener compatibilidad
 * con el código existente que usa window.CardManager, window.updateCardData y window.updateSpecificCard
 */
// eslint-disable-next-line no-restricted-globals
if (typeof window !== 'undefined') {
  try {
    // eslint-disable-next-line no-restricted-globals
    // @ts-ignore - CardManager se expone globalmente para compatibilidad
    window.CardManager = CardManager;
    // Exponer también las funciones individuales para compatibilidad
    // eslint-disable-next-line no-restricted-globals
    // @ts-ignore - updateCardData se expone globalmente para compatibilidad
    window.updateCardData = CardManager.updateCardData;
    // eslint-disable-next-line no-restricted-globals
    // @ts-ignore - updateSpecificCard se expone globalmente para compatibilidad
    window.updateSpecificCard = CardManager.updateSpecificCard;
  } catch (error) {
    try {
      // eslint-disable-next-line no-restricted-globals
      Object.defineProperty(window, 'CardManager', {
        value: CardManager,
        writable: true,
        enumerable: true,
        configurable: true
      });
      // eslint-disable-next-line no-restricted-globals
      Object.defineProperty(window, 'updateCardData', {
        value: CardManager.updateCardData,
        writable: true,
        enumerable: true,
        configurable: true
      });
      // eslint-disable-next-line no-restricted-globals
      Object.defineProperty(window, 'updateSpecificCard', {
        value: CardManager.updateSpecificCard,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar CardManager a window:', defineError, error);
      }
    }
  }
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { CardManager };
}
