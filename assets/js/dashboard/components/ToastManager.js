/**
 * Gestor de Notificaciones Toast
 * 
 * Gestiona las notificaciones toast modernas del dashboard con animaciones
 * y soporte para diferentes tipos de mensajes.
 * 
 * @module components/ToastManager
 * @namespace ToastManager
 * @since 1.0.0
 * @author Christian
 */

/* global jQuery, DASHBOARD_CONFIG */

/**
 * Duración por defecto de las notificaciones toast (en milisegundos)
 * 
 * @type {number}
 */
const DEFAULT_DURATION = 4000;

/**
 * Obtiene la duración de la animación de fade (en milisegundos)
 * Se usa de DASHBOARD_CONFIG si está disponible, o 300ms por defecto
 * 
 * @returns {number} Duración de la animación en milisegundos
 */
function getFadeInDuration() {
  if (typeof DASHBOARD_CONFIG !== 'undefined' &&
      DASHBOARD_CONFIG?.ui?.animation?.fadeIn) {
    return DASHBOARD_CONFIG.ui.animation.fadeIn;
  }
  return 300;
}

/**
 * Mapeo de tipos de toast a clases CSS
 * 
 * @type {Object<string, string>}
 */
const TOAST_CLASSES = {
  success: 'toast-success',
  error: 'toast-error',
  warning: 'toast-warning',
  info: 'toast-info'
};

/**
 * Mapeo de tipos de toast a iconos
 * 
 * @type {Object<string, string>}
 */
const TOAST_ICONS = {
  success: '✅',
  error: '❌',
  warning: '⚠️',
  info: 'ℹ️'
};

/**
 * Mapeo de tipos de toast a colores de borde
 * 
 * @type {Object<string, string>}
 */
const TOAST_COLORS = {
  success: '#22c55e',
  error: '#ef4444',
  warning: '#f59e0b',
  info: '#3b82f6'
};

/**
 * Mostrar una notificación toast
 * 
 * Crea y muestra una notificación toast con animación de entrada,
 * auto-cierre y botón de cierre manual.
 * 
 * @param {string} message - Mensaje a mostrar
 * @param {string} [type='info'] - Tipo de toast: 'success', 'error', 'warning', 'info'
 * @param {number} [duration=DEFAULT_DURATION] - Duración en milisegundos antes del auto-cierre
 * @returns {jQuery} El elemento jQuery del toast creado
 * 
 * @example
 * ToastManager.show('Operación completada', 'success');
 * ToastManager.show('Error al procesar', 'error', 5000);
 */
function show(message, type, duration) {
  // Validar que jQuery esté disponible
  if (typeof jQuery === 'undefined') {
    // eslint-disable-next-line no-console
    console.error('ToastManager requiere jQuery');
    return null;
  }

  // Validar mensaje
  if (!message || typeof message !== 'string') {
    // eslint-disable-next-line no-console
    console.warn('ToastManager.show: Se requiere un mensaje válido');
    return null;
  }

  // Valores por defecto
  const toastType = type || 'info';
  const toastDuration = duration || DEFAULT_DURATION;

  // Obtener clase CSS y icono según el tipo
  const toastClass = TOAST_CLASSES[toastType] || TOAST_CLASSES.info;
  const toastIcon = TOAST_ICONS[toastType] || TOAST_ICONS.info;
  const toastColor = TOAST_COLORS[toastType] || TOAST_COLORS.info;

  // Crear el elemento toast
  const $toast = jQuery(`
    <div class="mi-toast ${toastClass}" style="
      position: fixed;
      top: 32px;
      right: 20px;
      background: white;
      padding: 1rem 1.5rem;
      border-radius: 0.5rem;
      box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1);
      border-left: 4px solid var(--dashboard-primary);
      z-index: 9999;
      max-width: 400px;
      transform: translateX(100%);
      transition: transform 0.3s ease;
    ">
      <div style="display: flex; align-items: center; gap: 0.75rem;">
        <span class="toast-icon" style="font-size: 1.25rem;">
          ${toastIcon}
        </span>
        <span class="toast-message" style="color: #374151; font-weight: 500;">${message}</span>
        <button class="toast-close" style="
          background: none;
          border: none;
          font-size: 1.25rem;
          cursor: pointer;
          color: #9ca3af;
          margin-left: auto;
        ">×</button>
      </div>
    </div>
  `);

  // Aplicar color de borde según el tipo
  $toast.css('border-left-color', toastColor);

  // Agregar al body
  jQuery('body').append($toast);

  // Animación de entrada
  setTimeout(function() {
    $toast.css('transform', 'translateX(0)');
  }, 100);

  // Función para cerrar el toast
  const closeToast = function() {
    $toast.css('transform', 'translateX(100%)');
    const fadeDuration = getFadeInDuration();
    setTimeout(function() {
      $toast.remove();
    }, fadeDuration);
  };

  // Auto-cierre después de la duración especificada
  const autoCloseTimeout = setTimeout(closeToast, toastDuration);

  // Click en el botón de cerrar
  $toast.find('.toast-close').on('click', function() {
    clearTimeout(autoCloseTimeout);
    closeToast();
  });

  return $toast;
}

/**
 * Mostrar un toast de éxito
 * 
 * @param {string} message - Mensaje a mostrar
 * @param {number} [duration] - Duración en milisegundos
 * @returns {jQuery} El elemento jQuery del toast creado
 * 
 * @example
 * ToastManager.success('Operación completada exitosamente');
 */
function success(message, duration) {
  return show(message, 'success', duration);
}

/**
 * Mostrar un toast de error
 * 
 * @param {string} message - Mensaje a mostrar
 * @param {number} [duration] - Duración en milisegundos
 * @returns {jQuery} El elemento jQuery del toast creado
 * 
 * @example
 * ToastManager.error('Error al procesar la solicitud');
 */
function error(message, duration) {
  return show(message, 'error', duration);
}

/**
 * Mostrar un toast de advertencia
 * 
 * @param {string} message - Mensaje a mostrar
 * @param {number} [duration] - Duración en milisegundos
 * @returns {jQuery} El elemento jQuery del toast creado
 * 
 * @example
 * ToastManager.warning('Atención: Esta acción no se puede deshacer');
 */
function warning(message, duration) {
  return show(message, 'warning', duration);
}

/**
 * Mostrar un toast informativo
 * 
 * @param {string} message - Mensaje a mostrar
 * @param {number} [duration] - Duración en milisegundos
 * @returns {jQuery} El elemento jQuery del toast creado
 * 
 * @example
 * ToastManager.info('Procesando solicitud...');
 */
function info(message, duration) {
  return show(message, 'info', duration);
}

/**
 * Objeto ToastManager con métodos públicos
 */
const ToastManager = {
  show,
  success,
  error,
  warning,
  info,
  DEFAULT_DURATION
};

/**
 * Exponer ToastManager globalmente para mantener compatibilidad
 * con el código existente que usa window.ToastManager y window.showToast
 */
if (typeof window !== 'undefined') {
  try {
    window.ToastManager = ToastManager;
    // Exponer también como showToast para compatibilidad con código existente
    window.showToast = show;
  } catch (error) {
    try {
      Object.defineProperty(window, 'ToastManager', {
        value: ToastManager,
        writable: true,
        enumerable: true,
        configurable: true
      });
      Object.defineProperty(window, 'showToast', {
        value: show,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar ToastManager a window:', defineError, error);
      }
    }
  }
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { ToastManager };
}
