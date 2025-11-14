/**
 * Configuración Global del Dashboard
 * 
 * Configuración centralizada para el dashboard de Mi Integración API.
 * Incluye timeouts, límites, selectores, mensajes, UI y paginación.
 * 
 * @module config/dashboard-config
 * @namespace DASHBOARD_CONFIG
 * @since 1.0.0
 * @author Christian
 * 
 * @example
 * // Acceder a configuración de timeouts
 * const defaultTimeout = DASHBOARD_CONFIG.timeouts.default;
 * 
 * // Acceder a selectores
 * const syncButton = DASHBOARD_CONFIG.selectors.syncButton;
 * 
 * // Acceder a mensajes
 * const errorMsg = DASHBOARD_CONFIG.messages.errors.connectionError;
 */

/* global miIntegracionApiDashboard */

// ========================================
// CONFIGURACIÓN GLOBAL DEL DASHBOARD
// ========================================

/**
 * Configuración global del dashboard
 * 
 * @type {Object}
 * @namespace DASHBOARD_CONFIG
 * @description Configuración centralizada para el dashboard de Mi Integración API
 */
const DASHBOARD_CONFIG = {
  /**
   * Configuración de timeouts para operaciones del dashboard
   * 
   * Obtiene la configuración desde miIntegracionApiDashboard.timeoutConfig.ui
   * o usa valores por defecto si no está disponible.
   * 
   * @type {Object}
   * @property {number} default - Timeout por defecto (2000ms)
   * @property {number} long - Timeout para operaciones largas (5000ms)
   * @property {number} short - Timeout para operaciones cortas (1000ms)
   * @property {number} ajax - Timeout para peticiones AJAX (60000ms)
   * @property {number} connection - Timeout para verificación de conexión (30000ms)
   */
  timeouts: (() => {
    try {
      // Verificar si miIntegracionApiDashboard existe y tiene timeoutConfig
      // Nota: Usamos verificaciones tradicionales en lugar de optional chaining
      // para compatibilidad con ESLint 3.0.1
      if (typeof miIntegracionApiDashboard !== 'undefined' &&
          miIntegracionApiDashboard &&
          miIntegracionApiDashboard.timeoutConfig &&
          miIntegracionApiDashboard.timeoutConfig.ui) {
        return miIntegracionApiDashboard.timeoutConfig.ui;
      }
    } catch (error) {
      // eslint-disable-next-line no-console
      console.warn('Error accediendo a miIntegracionApiDashboard.timeoutConfig:', error);
    }

    // Fallback por defecto si no hay configuración disponible
    return {
      default: 2000,
      long: 5000,
      short: 1000,
      ajax: 60000,
      connection: 30000  // Reducido de 60000 a 30000 para mejor UX
    };
  })(),

  /**
   * Límites y configuraciones de rendimiento
   * 
   * Obtiene la configuración desde miIntegracionApiDashboard.limitsConfig.ui
   * o usa valores por defecto si no está disponible.
   * 
   * @type {Object}
   * @property {number} historyLimit - Límite de historial (10)
   * @property {number[]} progressMilestones - Hitos de progreso [25, 50, 75, 100]
   */
  limits: (() => {
    try {
      // Verificar si miIntegracionApiDashboard existe y tiene limitsConfig
      // Nota: Usamos verificaciones tradicionales en lugar de optional chaining
      // para compatibilidad con ESLint 3.0.1
      if (typeof miIntegracionApiDashboard !== 'undefined' &&
          miIntegracionApiDashboard &&
          miIntegracionApiDashboard.limitsConfig &&
          miIntegracionApiDashboard.limitsConfig.ui) {
        return miIntegracionApiDashboard.limitsConfig.ui;
      }
    } catch (error) {
      // eslint-disable-next-line no-console
      console.warn('Error accediendo a miIntegracionApiDashboard.limitsConfig:', error);
    }

    // Fallback por defecto si no hay configuración disponible
    return {
      historyLimit: 10,
      progressMilestones: [25, 50, 75, 100]
    };
  })(),

  /**
   * Configuración del umbral de detección de stalls (bloqueos)
   * 
   * Obtiene la configuración desde miIntegracionApiDashboard.stallThresholdConfig
   * o usa valores por defecto si no está disponible.
   * 
   * @type {Object}
   * @property {number} min - Umbral mínimo en ms (10000 = 10 segundos)
   * @property {number} max - Umbral máximo en ms (60000 = 60 segundos)
   * @property {number} default - Umbral por defecto en ms (15000 = 15 segundos)
   * @property {number} multiplier - Multiplicador para el promedio dinámico (2.0)
   * @property {number} minSamples - Mínimo de muestras necesarias para usar promedio dinámico (2)
   */
  stallThreshold: (() => {
    try {
      if (typeof miIntegracionApiDashboard !== 'undefined' &&
          miIntegracionApiDashboard &&
          miIntegracionApiDashboard.stallThresholdConfig) {
        return miIntegracionApiDashboard.stallThresholdConfig;
      }
    } catch (error) {
      // eslint-disable-next-line no-console
      console.warn('Error accediendo a miIntegracionApiDashboard.stallThresholdConfig:', error);
    }

    // Fallback por defecto si no hay configuración disponible
    return {
      min: 10000,      // 10 segundos mínimo
      max: 60000,     // 60 segundos máximo
      default: 15000, // 15 segundos por defecto
      multiplier: 2.0, // Multiplicar promedio por 2x
      minSamples: 2    // Mínimo 2 muestras para usar promedio dinámico
    };
  })(),

  /**
   * Selectores CSS para elementos del dashboard
   * 
   * @type {Object}
   * @property {string} syncButton - Selector del botón de sincronización
   * @property {string} feedback - Selector del área de feedback
   * @property {string} progressInfo - Selector de información de progreso
   * @property {string} cancelButton - Selector del botón de cancelar
   * @property {string} statusContainer - Selector del contenedor de estado
   * @property {string} batchSize - Selector del selector de tamaño de lote
   * @property {string} dashboardMessages - Selector de mensajes del dashboard
   * @property {string} retryButton - Selector del botón de reintento
   */
  selectors: {
    syncButton: '#mi-batch-sync-products',
    feedback: '#mi-sync-feedback',
    progressInfo: '#mi-progress-info',
    cancelButton: '#mi-cancel-sync',
    statusContainer: '#mi-sync-status-details',
    batchSize: '#mi-batch-size',
    dashboardMessages: '#mi-dashboard-messages',
    retryButton: '#mi-api-retry-sync'
  },

  /**
   * Mensajes del sistema organizados por categorías
   * 
   * @type {Object}
   * @property {Object} errors - Mensajes de error
   * @property {Object} progress - Mensajes de progreso
   * @property {Object} milestones - Mensajes de hitos
   * @property {Object} success - Mensajes de éxito
   * @property {Object} tips - Consejos y tips
   */
  messages: {
    /**
     * Mensajes de error del sistema
     * 
     * @type {Object}
     * @property {string} jqueryMissing - Error cuando jQuery no está disponible
     * @property {string} configMissing - Error de configuración incompleta
     * @property {string} ajaxUrlMissing - Error cuando ajaxurl no está definido
     * @property {string} connectionError - Error de conexión
     * @property {string} permissionError - Error de permisos (403)
     * @property {string} serverError - Error del servidor (500)
     * @property {string} timeoutError - Error de timeout
     * @property {string} unknownError - Error desconocido
     */
    errors: {
      jqueryMissing: 'jQuery no está disponible. El dashboard no funcionará.',
      configMissing: 'Variables de configuración incompletas. La sincronización fallará.',
      ajaxUrlMissing: 'Variable ajaxurl no está definida. La sincronización AJAX fallará.',
      connectionError: 'Error de conexión. Verifique su conexión a internet.',
      permissionError: 'Error de permisos (403). Por favor, recarga la página o inicia sesión nuevamente.',
      serverError: 'Error del servidor (500). Contacte al administrador.',
      timeoutError: 'Tiempo de espera agotado. La operación tardó demasiado.',
      unknownError: 'Error desconocido. Verifique la consola para más detalles.'
    },
    /**
     * Mensajes de progreso de sincronización
     * 
     * @type {Object}
     * @property {string} preparing - Mensaje de preparación
     * @property {string} verifying - Mensaje de verificación
     * @property {string} connecting - Mensaje de conexión
     * @property {string} processing - Mensaje de procesamiento
     * @property {string} complete - Mensaje de completado
     */
    progress: {
      preparing: 'Preparando sincronización... ',
      verifying: 'Verificando estado del servidor...',
      connecting: 'Conectando con el servidor...',
      processing: 'Procesando datos...',
      complete: 'Sincronización completada exitosamente'
    },
    /**
     * Mensajes de hitos de progreso
     * 
     * @type {Object}
     * @property {string} start - Mensaje de inicio
     * @property {string} quarter - Mensaje de 25% completado
     * @property {string} half - Mensaje de 50% completado
     * @property {string} threeQuarters - Mensaje de 75% completado
     * @property {string} complete - Mensaje de completado
     */
    milestones: {
      start: 'Iniciando sincronización...',
      quarter: '25% completado',
      half: '50% completado',
      threeQuarters: '75% completado',
      complete: '¡Sincronización completada!'
    },
    /**
     * Mensajes de éxito del sistema
     * 
     * @type {Object}
     * @property {string} batchSizeChanged - Mensaje cuando se cambia el tamaño de lote
     */
    success: {
      batchSizeChanged: 'Tamaño de lote cambiado a {size} productos'
    },
    /**
     * Consejos y tips para el usuario
     * 
     * @type {Object}
     * @property {string} keyboardShortcut - Atajo de teclado
     * @property {string} generalTip - Tip general
     */
    tips: {
      keyboardShortcut: 'Atajo de teclado: Ctrl+Enter para sincronizar',
      generalTip: '💡 Tip: Usa Ctrl+Enter para iniciar sincronización rápida'
    }
  },

  /**
   * Configuración de interfaz de usuario
   * 
   * @type {Object}
   * @property {Object} progress - Configuración de barras de progreso
   * @property {Object} animation - Configuración de animaciones
   * @property {Object} toastDuration - Duración de notificaciones toast
   */
  ui: {
    /**
     * Configuración de barras de progreso
     * 
     * @type {Object}
     * @property {number} defaultWidth - Ancho por defecto (2px)
     * @property {number} animationDuration - Duración de animación (300ms)
     * @property {Object} colorScheme - Esquema de colores
     */
    progress: {
      defaultWidth: 2,
      animationDuration: 300,
      colorScheme: {
        normal: '#0073aa',
        success: '#22c55e',
        warning: '#f59e0b',
        error: '#ef4444'
      }
    },
    /**
     * Configuración de animaciones
     * 
     * @type {Object}
     * @property {number} duration - Duración de animación (300ms)
     * @property {string} easing - Tipo de easing ('swing')
     */
    animation: {
      duration: 300,
      easing: 'swing'
    },
    /**
     * Duración de notificaciones toast
     * 
     * @type {Object}
     * @property {number} short - Duración corta (3000ms)
     * @property {number} medium - Duración media (5000ms)
     * @property {number} long - Duración larga (8000ms)
     * @property {number} extraLong - Duración extra larga (10000ms)
     */
    toastDuration: {
      short: 3000,
      medium: 5000,
      long: 8000,
      extraLong: 10000
    }
  },

  /**
   * Configuración de paginación
   * 
   * @type {Object}
   * @property {number} defaultPerPage - Elementos por página por defecto (10)
   * @property {number} debounceDelay - Delay de debounce (500ms)
   * @property {number} maxVisiblePages - Máximo de páginas visibles (5)
   */
  pagination: {
    defaultPerPage: 10,
    debounceDelay: 500,
    maxVisiblePages: 5
  }
};

// ========================================
// EXPOSICIÓN GLOBAL
// ========================================

/**
 * Exponer DASHBOARD_CONFIG globalmente para mantener compatibilidad
 * con el código existente que usa DASHBOARD_CONFIG directamente
 * 
 * NOTA: En el archivo original (dashboard.js línea 101) se define como:
 * const DASHBOARD_CONFIG = { ... }
 * 
 * Mantenemos la misma lógica para compatibilidad exacta.
 */
if (typeof window !== 'undefined') {
  try {
    // Asignar a window.DASHBOARD_CONFIG
    window.DASHBOARD_CONFIG = DASHBOARD_CONFIG;
  } catch (error) {
    // Si falla, usar defineProperty como alternativa
    // Nota: Capturamos el error para proporcionar un fallback seguro
    try {
      Object.defineProperty(window, 'DASHBOARD_CONFIG', {
        value: DASHBOARD_CONFIG,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // Si también falla defineProperty, registrar el error pero no lanzar excepción
      // El error se maneja silenciosamente para no interrumpir la ejecución
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar DASHBOARD_CONFIG a window:', defineError);
        // Usar también el error original para evitar warning
        // eslint-disable-next-line no-console
        console.warn('Error original:', error);
      }
    }
  }
}

// Si usas ES6 modules, descomentar:
// export { DASHBOARD_CONFIG };

// Si usas CommonJS (para tests):
/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { DASHBOARD_CONFIG };
}
