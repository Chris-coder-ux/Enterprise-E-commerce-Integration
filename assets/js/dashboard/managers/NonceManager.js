/**
 * Gestor de Renovación de Nonces
 * 
 * Gestiona la renovación automática de nonces de seguridad para mantener
 * las sesiones activas y prevenir errores de autenticación.
 * 
 * @module managers/NonceManager
 * @namespace NonceManager
 * @since 1.0.0
 * @author Christian
 */

/* global jQuery, miIntegracionApiDashboard */

/**
 * Intervalo de renovación automática (en milisegundos)
 * Por defecto: 30 minutos
 * 
 * @type {number}
 */
const DEFAULT_RENEWAL_INTERVAL = 30 * 60 * 1000; // 30 minutos

/**
 * ID del intervalo de renovación automática
 * 
 * @type {number|NodeJS.Timeout|null}
 */
let renewalIntervalId = null;

/**
 * Intentar renovar el nonce automáticamente
 * 
 * Realiza una petición AJAX al servidor para renovar el nonce de seguridad.
 * Actualiza el nonce en `miIntegracionApiDashboard.nonce` si la renovación
 * es exitosa.
 * 
 * @param {Function} [showNotification] - Función opcional para mostrar notificaciones
 * @returns {void}
 * 
 * @example
 * NonceManager.attemptRenewal();
 */
function attemptRenewal(showNotification) {
  // eslint-disable-next-line no-console
  console.log('Intentando renovar nonce automáticamente...');

  // Verificar que ajaxurl esté disponible
  if (typeof miIntegracionApiDashboard === 'undefined' ||
      !miIntegracionApiDashboard ||
      !miIntegracionApiDashboard.ajaxurl) {
    // eslint-disable-next-line no-console
    console.error('miIntegracionApiDashboard.ajaxurl no está disponible');
    return;
  }

  jQuery.ajax({
    url: miIntegracionApiDashboard.ajaxurl,
    type: 'POST',
    data: {
      action: 'mia_renew_nonce'
    },
    success(response) {
      // eslint-disable-next-line no-console
      console.log('Respuesta de renovación de nonce:', response);

      if (response.success && response.data && response.data.nonce) {
        // eslint-disable-next-line no-console
        console.log('Nonce renovado exitosamente:', response.data.nonce);
        
        // Actualizar el nonce en el objeto global
        if (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard) {
          miIntegracionApiDashboard.nonce = response.data.nonce;
        }

        // Mostrar notificación de éxito si está disponible
        if (typeof showNotification === 'function') {
          showNotification('Token de seguridad renovado automáticamente', 'success');
        }
      } else {
        // eslint-disable-next-line no-console
        console.warn('No se pudo renovar el nonce:', response);
        
        const errorMessage = (response.data && response.data.message) || 'Error desconocido';
        const errorCode = (response.data && response.data.code) || 'unknown_error';
        
        // Mostrar notificación de advertencia si está disponible
        if (typeof showNotification === 'function') {
          showNotification(`No se pudo renovar el token: ${errorMessage} (${errorCode})`, 'warning');
        }
      }
    },
    error(xhr, status, error) {
      // eslint-disable-next-line no-console
      console.error('Error AJAX al renovar nonce:', {xhr, status, error});

      let errorMessage = 'Error de conexión';
      if (xhr && xhr.status) {
        errorMessage = `Error ${xhr.status}: ${error || 'Error de conexión'}`;
      } else if (status === 'timeout') {
        errorMessage = 'Timeout: La petición tardó demasiado';
      } else if (status === 'error' && !error) {
        errorMessage = 'Error de red: No se pudo conectar al servidor';
      }

      // Mostrar notificación de error si está disponible
      if (typeof showNotification === 'function') {
        showNotification(`Error al renovar el token: ${errorMessage}`, 'error');
      }
    }
  });
}

/**
 * Configurar renovación automática de nonces
 * 
 * Configura un intervalo que renueva el nonce automáticamente cada cierto tiempo.
 * Si ya existe un intervalo activo, lo detiene antes de crear uno nuevo.
 * 
 * @param {number} [interval=DEFAULT_RENEWAL_INTERVAL] - Intervalo en milisegundos
 * @param {Function} [showNotification] - Función opcional para mostrar notificaciones
 * @returns {void}
 * 
 * @example
 * // Configurar renovación automática cada 30 minutos (por defecto)
 * NonceManager.setupAutoRenewal();
 * 
 * // Configurar renovación automática cada 15 minutos
 * NonceManager.setupAutoRenewal(15 * 60 * 1000);
 */
function setupAutoRenewal(interval, showNotification) {
  // Detener cualquier intervalo existente
  stopAutoRenewal();

  // Usar intervalo por defecto si no se especifica
  const renewalInterval = interval || DEFAULT_RENEWAL_INTERVAL;

  // Configurar el intervalo
  renewalIntervalId = setInterval(function() {
    attemptRenewal(showNotification);
  }, renewalInterval);

  // eslint-disable-next-line no-console
  console.log(`Renovación automática de nonce configurada cada ${renewalInterval / 1000 / 60} minutos`);
}

/**
 * Detener la renovación automática de nonces
 * 
 * Detiene el intervalo de renovación automática si está activo.
 * 
 * @returns {void}
 * 
 * @example
 * NonceManager.stopAutoRenewal();
 */
function stopAutoRenewal() {
  if (renewalIntervalId !== null) {
    clearInterval(renewalIntervalId);
    renewalIntervalId = null;
    // eslint-disable-next-line no-console
    console.log('Renovación automática de nonce detenida');
  }
}

/**
 * Verificar si la renovación automática está activa
 * 
 * @returns {boolean} true si la renovación automática está activa, false en caso contrario
 * 
 * @example
 * if (NonceManager.isAutoRenewalActive()) {
 *   // La renovación automática está activa
 * }
 */
function isAutoRenewalActive() {
  return renewalIntervalId !== null;
}

/**
 * Objeto NonceManager con métodos públicos
 */
const NonceManager = {
  attemptRenewal,
  setupAutoRenewal,
  stopAutoRenewal,
  isAutoRenewalActive,
  DEFAULT_RENEWAL_INTERVAL
};

/**
 * Exponer NonceManager globalmente para mantener compatibilidad
 * con el código existente que usa window.NonceManager
 */
if (typeof window !== 'undefined') {
  try {
    // @ts-ignore - Asignación dinámica a window para compatibilidad
    window.NonceManager = NonceManager;
  } catch (error) {
    try {
      Object.defineProperty(window, 'NonceManager', {
        value: NonceManager,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar NonceManager a window:', defineError, error);
      }
    }
  }
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { NonceManager };
}
