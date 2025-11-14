/**
 * @fileoverview Definiciones de tipos TypeScript/JSDoc para el dashboard
 * @module types
 */

/**
 * Respuesta estándar de AJAX del backend
 * @typedef {Object} AjaxResponse
 * @property {boolean} success - Indica si la petición fue exitosa
 * @property {*} [data] - Datos de respuesta (estructura variable según el endpoint)
 * @property {string} [message] - Mensaje de respuesta (opcional)
 * @property {AjaxError} [error] - Información de error (si success es false)
 */

/**
 * Información de error en respuesta AJAX
 * @typedef {Object} AjaxError
 * @property {string} message - Mensaje de error
 * @property {string} [code] - Código de error
 * @property {*} [data] - Datos adicionales del error
 */

/**
 * Estado de sincronización de Fase 1 (imágenes)
 * @typedef {Object} Phase1Status
 * @property {boolean} in_progress - Indica si la sincronización está en progreso
 * @property {boolean} completed - Indica si la sincronización está completada
 * @property {number} products_processed - Número de productos procesados
 * @property {number} total_products - Total de productos a procesar
 * @property {number} [images_processed] - Número de imágenes procesadas
 * @property {number} [duplicates_skipped] - Número de duplicados omitidos
 * @property {number} [errors] - Número de errores
 */
// Declaración TypeScript para Phase1Status
// @ts-ignore - Tipo definido para JSDoc
type Phase1Status = {
  in_progress?: boolean;
  completed?: boolean;
  products_processed?: number;
  total_products?: number;
  images_processed?: number;
  duplicates_skipped?: number;
  errors?: number;
  [key: string]: any;
};

/**
 * Estadísticas de sincronización de Fase 2 (productos)
 * @typedef {Object} Phase2Stats
 * @property {number} procesados - Productos procesados
 * @property {number} total - Total de productos
 * @property {number} errores - Errores encontrados
 * @property {number} [creados] - Productos creados
 * @property {number} [actualizados] - Productos actualizados
 */
// Declaración TypeScript para Phase2Stats
// @ts-ignore - Tipo definido para JSDoc
type Phase2Stats = {
  procesados?: number;
  total?: number;
  errores?: number;
  creados?: number;
  actualizados?: number;
  [key: string]: any;
};

/**
 * Datos de progreso de sincronización
 * @typedef {Object} SyncProgressData
 * @property {boolean} in_progress - Indica si hay sincronización en progreso
 * @property {boolean} is_completed - Indica si la sincronización está completada
 * @property {Phase1Status} [phase1_images] - Estado de Fase 1
 * @property {Phase2Stats} [estadisticas] - Estadísticas de Fase 2
 */
// Declaración TypeScript para SyncProgressData
// @ts-ignore - Tipo definido para JSDoc
type SyncProgressData = {
  in_progress?: boolean;
  is_completed?: boolean;
  phase1_images?: Phase1Status;
  estadisticas?: Phase2Stats;
  [key: string]: any;
};

/**
 * Opciones para peticiones AJAX
 * @typedef {Object} AjaxOptions
 * @property {number} [timeout] - Timeout en milisegundos
 * @property {Object} [headers] - Headers HTTP adicionales
 * @property {string} [method] - Método HTTP (GET, POST, etc.)
 * @property {boolean} [cache] - Si se debe cachear la petición
 */

/**
 * Callback de éxito para peticiones AJAX
 * @callback AjaxSuccessCallback
 * @param {AjaxResponse} response - Respuesta de la petición
 * @returns {void}
 */

/**
 * Callback de error para peticiones AJAX
 * @callback AjaxErrorCallback
 * @param {jQuery.jqXHR} xhr - Objeto jqXHR de jQuery
 * @param {string} status - Estado de la petición
 * @param {string} error - Mensaje de error
 * @returns {void}
 */

/**
 * Configuración del dashboard
 * @typedef {Object} DashboardConfig
 * @property {Object} [timeouts] - Configuración de timeouts
 * @property {number} [timeouts.ajax] - Timeout para peticiones AJAX en segundos
 * @property {Object} [messages] - Mensajes del dashboard
 * @property {Object} [messages.progress] - Mensajes de progreso
 * @property {string} [messages.progress.productsPerSec] - Etiqueta para productos por segundo
 * @property {Object} [messages.status] - Mensajes de estado
 */

/**
 * Estado de una fase de sincronización
 * @typedef {'pending'|'running'|'completed'|'error'|'paused'|'cancelled'} PhaseStatus
 */
// Declaración TypeScript para PhaseStatus
// @ts-ignore - Tipo definido para JSDoc
type PhaseStatus = 'pending' | 'running' | 'completed' | 'error' | 'paused' | 'cancelled';

/**
 * Tipo de mensaje de consola
 * @typedef {'info'|'success'|'warning'|'error'|'phase1'|'phase2'} ConsoleMessageType
 */

/**
 * Declaración de tipos globales para variables del navegador
 * Estas variables están disponibles globalmente cuando se carga WordPress
 */

/**
 * jQuery - Biblioteca JavaScript cargada por WordPress
 * @type {jQuery}
 * @global
 */
// @ts-ignore - jQuery está disponible globalmente desde WordPress
declare var jQuery: any;

/**
 * $ - Alias de jQuery
 * @type {jQuery}
 * @global
 */
// @ts-ignore - $ está disponible globalmente desde WordPress
declare var $: any;

/**
 * DomUtils - Utilidades DOM del Dashboard
 * 
 * Centraliza el cacheo de elementos DOM y utilidades relacionadas
 * para optimizar el rendimiento del dashboard.
 * 
 * @global
 */
declare var DomUtils: {
  /**
   * Inicializar el cache de elementos DOM
   * @returns {Object} El objeto DOM_CACHE inicializado
   */
  initCache: () => any;
  
  /**
   * Obtener el cache de elementos DOM
   * Retorna el objeto DOM_CACHE. Si no está inicializado, lo inicializa automáticamente.
   * @returns {Object} El objeto DOM_CACHE
   */
  getCache: () => any;
  
  /**
   * Refrescar el cache de elementos DOM
   * Vuelve a consultar el DOM y actualiza todas las referencias en DOM_CACHE.
   * @returns {Object} El objeto DOM_CACHE actualizado
   */
  refreshCache: () => any;
  
  /**
   * Verificar si el cache está inicializado
   * @returns {boolean} true si el cache está inicializado, false en caso contrario
   */
  isCacheInitialized: () => boolean;
  
  [key: string]: any;
};

/**
 * DOM_CACHE - Objeto global que contiene referencias a elementos jQuery del DOM
 * @type {Object|undefined}
 * @property {jQuery} $progressBar - Referencia jQuery a la barra de progreso
 * @global
 */
// @ts-ignore - DOM_CACHE está disponible globalmente desde DomUtils.js
declare var DOM_CACHE: any;

/**
 * UIOptimizer - Utilidad para optimizar actualizaciones de la UI
 * @global
 */
// @ts-ignore - UIOptimizer está disponible globalmente desde UIOptimizer.js
declare var UIOptimizer: any;

/**
 * EventCleanupManager - Gestor de limpieza de eventos
 * 
 * Gestiona la limpieza de event listeners para evitar fugas de memoria.
 * Rastrea y desvincula listeners de jQuery(document).on() y otros eventos.
 * 
 * @global
 */
declare var EventCleanupManager: {
  /**
   * Registra un listener de document con jQuery para cleanup automático
   * @param event - Tipo de evento (ej: 'click', 'change')
   * @param selector - Selector CSS del elemento
   * @param handler - Función handler del evento
   * @param componentId - ID del componente que registra el listener
   * @returns Función para desvincular manualmente el listener
   */
  registerDocumentListener: (
    event: string,
    selector: string,
    handler: (event: Event) => void,
    componentId: string
  ) => () => void;
  
  /**
   * Registra un listener de elemento específico con jQuery para cleanup automático
   * @param element - Elemento jQuery, DOM o selector CSS
   * @param event - Tipo de evento
   * @param handler - Función handler del evento
   * @param componentId - ID del componente que registra el listener
   * @returns Función para desvincular manualmente el listener
   */
  registerElementListener: (
    element: any, // jQuery object, HTMLElement o string selector
    event: string,
    handler: (event: Event) => void,
    componentId: string
  ) => () => void;
  
  /**
   * Registra un listener de evento personalizado (EventEmitter) para cleanup automático
   * @param emitter - Objeto que emite eventos (debe tener métodos on/off)
   * @param event - Nombre del evento
   * @param handler - Función handler del evento
   * @param componentId - ID del componente que registra el listener
   * @returns Función para desvincular manualmente el listener
   */
  registerCustomEventListener: (
    emitter: { on: (event: string, handler: Function) => void; off?: (event: string, handler: Function) => void },
    event: string,
    handler: (data?: any) => void,
    componentId: string
  ) => () => void;
  
  /**
   * Registra un listener nativo del DOM para cleanup automático
   * @param element - Elemento DOM o Window
   * @param event - Tipo de evento
   * @param handler - Función handler del evento
   * @param componentId - ID del componente que registra el listener
   * @param options - Opciones para addEventListener (passive, once, etc.) o boolean para capture
   * @returns Función para desvincular manualmente el listener
   */
  registerNativeListener: (
    element: HTMLElement | Window,
    event: string,
    handler: (event: Event) => void,
    componentId: string,
    options?: boolean | AddEventListenerOptions
  ) => () => void;
  
  /**
   * Limpia todos los listeners registrados para un componente específico
   * @param componentId - ID del componente a limpiar
   * @returns Número de listeners limpiados
   */
  cleanupComponent: (componentId: string) => number;
  
  /**
   * Limpia todos los listeners registrados de todos los componentes
   * @returns Número total de listeners limpiados
   */
  cleanupAll: () => number;
  
  /**
   * Obtiene la instancia singleton de EventCleanupManager
   * @returns Instancia de EventCleanupManager
   */
  getInstance: () => EventCleanupManager;
  
  [key: string]: any;
};

/**
 * ErrorHandler - Gestor de errores del dashboard
 * 
 * Proporciona métodos estáticos para:
 * - Logging de errores con contexto y timestamp
 * - Mostrar errores en la interfaz de usuario
 * - Manejar diferentes tipos de errores (conexión, cancelación, críticos)
 * 
 * NOTA: La implementación real está en ErrorHandler.js (class ErrorHandler).
 * Esta declaración de tipos es solo para TypeScript/IDE y no crea una variable real.
 * 
 * @global
 */
declare class ErrorHandler {
  // Propiedades privadas estáticas (solo para tipos, no accesibles desde fuera)
  /** @private */
  static _activeIntervals: WeakMap<any, any>;
  /** @private */
  static _cachedFeedbackSelector: string | null;
  /** @private */
  static _HTML_ESCAPE_MAP: {
    '&': string;
    '<': string;
    '>': string;
    '"': string;
    '\'': string;
  };
  
  /**
   * Registra errores en la consola con timestamp y contexto opcional
   * @param message - El mensaje de error
   * @param context - El contexto del error (opcional, puede ser null)
   */
  static logError(message: string, context?: string | null): void;
  
  /**
   * Muestra un error en la interfaz de usuario
   * @param message - El mensaje de error
   * @param type - El tipo de error ('error' o 'warning'), por defecto 'error'
   */
  static showUIError(message: string, type?: 'error' | 'warning'): void;
  
  /**
   * Muestra un error de conexión básico
   * Analiza el objeto XMLHttpRequest y muestra un mensaje de error apropiado
   * @param xhr - El objeto XMLHttpRequest
   */
  static showConnectionError(xhr: { status?: number; statusText?: string } | null): void;
  
  /**
   * Muestra un error de protección
   * Registra errores de protección sin mostrar en la UI para evitar spam
   * @param reason - La razón de la protección
   */
  static showProtectionError(reason: string): void;
  
  /**
   * Muestra un error de cancelación
   * @param message - El mensaje de error de cancelación
   * @param context - El contexto de la cancelación, por defecto 'CANCEL'
   */
  static showCancelError(message: string, context?: string): void;
  
  /**
   * Muestra un error crítico
   * @param message - El mensaje de error
   * @param context - El contexto del error crítico, por defecto 'CRITICAL'
   */
  static showCriticalError(message: string, context?: string): void;
}

/**
 * SyncStateManager - Gestor de estado de sincronización
 * @global
 */
declare var SyncStateManager: {
  setPhase1Starting?: (value: boolean) => boolean;
  getPhase1Initialized?: () => boolean;
  setPhase1Initialized?: (value: boolean) => void;
  setInactiveProgressCounter?: (value: number) => void;
  resetPhase1State?: () => void;
  stopProgressPolling?: (reason?: string) => void;
  setLastProgressValue?: (value: number) => void;
  [key: string]: any;
};

/**
 * NonceManager - Gestor de renovación de nonces
 * 
 * Gestiona la renovación automática de nonces de seguridad para mantener
 * las sesiones activas y prevenir errores de autenticación.
 * 
 * @global
 */
declare var NonceManager: {
  /**
   * Intentar renovar el nonce automáticamente
   * @param showNotification - Función opcional para mostrar notificaciones
   * @returns {void}
   */
  attemptRenewal?: (showNotification?: (message: string, type?: string) => void) => void;
  
  /**
   * Configurar renovación automática de nonces
   * @param interval - Intervalo en milisegundos (opcional, por defecto 30 minutos)
   * @param showNotification - Función opcional para mostrar notificaciones
   * @returns {void}
   */
  setupAutoRenewal?: (interval?: number, showNotification?: (message: string, type?: string) => void) => void;
  
  /**
   * Detener la renovación automática de nonces
   * @returns {void}
   */
  stopAutoRenewal?: () => void;
  
  /**
   * Verificar si la renovación automática está activa
   * @returns {boolean} true si la renovación automática está activa
   */
  isAutoRenewalActive?: () => boolean;
  
  /**
   * Intervalo de renovación automática por defecto (30 minutos en milisegundos)
   */
  DEFAULT_RENEWAL_INTERVAL?: number;
  
  [key: string]: any;
};

/**
 * SyncProgress - Gestor de Progreso de Sincronización
 * 
 * Gestiona la verificación del progreso de sincronización mediante peticiones AJAX,
 * actualiza la interfaz de usuario y maneja el estado de la sincronización.
 * 
 * @global
 */
declare var SyncProgress: {
  /**
   * Verifica el progreso de sincronización mediante petición AJAX
   * @returns {void}
   */
  check?: () => void;
  
  /**
   * Obtiene el estado de tracking del progreso
   * @returns {Object} Estado de tracking con información de lotes y items
   */
  getTrackingState?: () => {
    lastKnownBatch?: number;
    lastKnownItemsSynced?: number;
    lastKnownTotalBatches?: number;
    lastKnownTotalItems?: number;
    lastProgressTimestamp?: number;
    [key: string]: any;
  };
  
  /**
   * Resetea el estado de tracking del progreso
   * @returns {void}
   */
  resetTrackingState?: () => void;
  
  [key: string]: any;
};

/**
 * Phase1Manager - Gestor de Fase 1: Sincronización de Imágenes
 * 
 * Gestiona la Fase 1 de la sincronización en dos fases, que consiste en
 * sincronizar todas las imágenes antes de proceder con la sincronización
 * de productos (Fase 2).
 * 
 * @global
 */
declare var Phase1Manager: {
  /**
   * Inicia el proceso de sincronización de Fase 1
   * @param batchSize - Tamaño del lote para procesar
   * @param originalText - Texto original del botón de sincronización
   * @returns {void}
   */
  start?: (batchSize: number, originalText: string) => void;
  
  /**
   * Detiene el proceso de sincronización de Fase 1
   * @returns {void}
   */
  stop?: () => void;
  
  /**
   * Verifica si Fase 1 está completa
   * @returns {boolean} True si Fase 1 está completa
   */
  isComplete?: () => boolean;
  
  /**
   * Obtiene el ID del intervalo de polling actual
   * @returns {number|null} ID del intervalo o null si no hay polling activo
   */
  getPollingInterval?: () => number | null;
  
  /**
   * Inicia el polling para verificar el progreso de Fase 1
   * @returns {void}
   */
  startPolling?: () => void;
  
  [key: string]: any;
};

/**
 * ToastManager - Gestor de notificaciones toast
 * 
 * Proporciona métodos para mostrar notificaciones toast en la interfaz de usuario.
 * 
 * @global
 */
declare var ToastManager: {
  /**
   * Muestra una notificación toast
   * @param message - Mensaje a mostrar
   * @param type - Tipo de notificación ('success', 'error', 'warning', 'info')
   * @param duration - Duración en milisegundos (opcional)
   */
  show?: (message: string, type?: 'success' | 'error' | 'warning' | 'info', duration?: number) => void;
  
  /**
   * Muestra una notificación de éxito
   * @param message - Mensaje a mostrar
   * @param duration - Duración en milisegundos (opcional)
   */
  success?: (message: string, duration?: number) => void;
  
  /**
   * Muestra una notificación de error
   * @param message - Mensaje a mostrar
   * @param duration - Duración en milisegundos (opcional)
   */
  error?: (message: string, duration?: number) => void;
  
  /**
   * Muestra una notificación de advertencia
   * @param message - Mensaje a mostrar
   * @param duration - Duración en milisegundos (opcional)
   */
  warning?: (message: string, duration?: number) => void;
  
  /**
   * Muestra una notificación informativa
   * @param message - Mensaje a mostrar
   * @param duration - Duración en milisegundos (opcional)
   */
  info?: (message: string, duration?: number) => void;
  
  [key: string]: any;
};

/**
 * stopProgressPolling - Función global para detener el polling de progreso
 * 
 * Detiene todos los polling activos relacionados con el progreso de sincronización.
 * Disponible globalmente para compatibilidad con código existente.
 * 
 * @param {string} [reason] - Razón para detener el polling (opcional, solo para logging)
 * @returns {void}
 * @global
 */
declare var stopProgressPolling: ((reason?: string) => void) | undefined;

/**
 * addConsoleLine - Función global para agregar una línea a la consola de sincronización
 * 
 * Agrega una línea de mensaje a la consola de sincronización con el tipo especificado.
 * Disponible globalmente para compatibilidad con código existente.
 * 
 * @param {string} type - Tipo de mensaje ('info', 'success', 'warning', 'error', 'phase1', 'phase2')
 * @param {string} message - Mensaje a mostrar en la consola
 * @returns {void}
 * @global
 */
declare var addConsoleLine: ((type: string, message: string) => void) | undefined;

/**
 * pollingManager - Gestor de polling para sincronización
 * @global
 */
declare var pollingManager: {
  startPolling?: (name: string, callback: () => void, interval?: number) => number | null;
  stopPolling?: (name: string) => void;
  getIntervalId?: (name: string) => number | null;
  emit?: (event: string, data?: any) => void;
  recordResponseTime?: (responseTime: number) => void;
  recordError?: () => void;
  adjustPolling?: (progress: number, isActive: boolean) => void;
  config?: {
    intervals?: {
      normal?: number;
      active?: number;
      fast?: number;
      slow?: number;
      idle?: number;
      min?: number;
      max?: number;
    };
    thresholds?: {
      to_slow?: number;
      to_idle?: number;
      max_errors?: number;
      progress_threshold?: number;
      latency_threshold?: number;
      error_backoff_base?: number;
      error_backoff_max?: number;
      consecutive_errors_threshold?: number;
    };
    currentInterval?: number;
    currentMode?: string;
    errorCount?: number;
    consecutiveErrors?: number;
    lastProgress?: number;
    progressStagnantCount?: number;
    lastResponseTime?: number | null;
    averageLatency?: number | null;
    backoffMultiplier?: number;
    userActive?: boolean;
    lastUserActivity?: number;
    maxListenersPerEvent?: number;
    adjustmentDebounceMs?: number;
  };
  [key: string]: any;
};

/**
 * DASHBOARD_CONFIG - Configuración del dashboard
 * @global
 */
declare var DASHBOARD_CONFIG: {
  timeouts?: {
    ajax?: number;
  };
  [key: string]: any;
};

/**
 * SELECTORS - Selectores CSS optimizados para el dashboard
 * 
 * Define todos los selectores CSS reutilizables del sistema para optimizar
 * el rendimiento y facilitar el mantenimiento.
 * 
 * @global
 */
declare var SELECTORS: {
  /**
   * Selector de tarjeta de estadística base
   */
  STAT_CARD: string;
  
  /**
   * Selector de valor de estadística
   */
  STAT_VALUE: string;
  
  /**
   * Selector de descripción de estadística
   */
  STAT_DESC: string;
  
  /**
   * Selector de tarjeta de memoria
   */
  STAT_CARD_MEMORY: string;
  
  /**
   * Selector de tarjeta de reintentos
   */
  STAT_CARD_RETRIES: string;
  
  /**
   * Selector de tarjeta de sincronización
   */
  STAT_CARD_SYNC: string;
  
  /**
   * Selector compuesto de tarjetas (compatibilidad)
   */
  DASHBOARD_CARDS: string;
  
  /**
   * Selector compuesto de elementos de métricas
   */
  METRIC_ELEMENTS: string;
  
  [key: string]: any;
};

/**
 * MESSAGES - Mensajes del Sistema del Dashboard
 * 
 * Centraliza todos los mensajes del sistema organizados por categorías.
 * 
 * @global
 */
declare var MESSAGES: {
  /**
   * Mensajes de error del sistema
   */
  errors: {
    jqueryMissing: string;
    configMissing: string;
    ajaxUrlMissing: string;
    connectionError: string;
    permissionError: string;
    serverError: string;
    timeoutError: string;
    unknownError: string;
  };
  
  /**
   * Mensajes de progreso de sincronización
   */
  progress: {
    preparing: string;
    verifying: string;
    connecting: string;
    processing: string;
    complete: string;
    productsProcessed: string;
    productsSynced: string;
    productsPerSec: string;
  };
  
  /**
   * Mensajes de hitos de progreso
   */
  milestones: {
    start: string;
    quarter: string;
    half: string;
    threeQuarters: string;
    complete: string;
  };
  
  /**
   * Mensajes de estado del sistema
   */
  status: {
    pending: string;
    running: string;
    completed: string;
    error: string;
    paused: string;
  };
  
  /**
   * Mensajes de éxito del sistema
   */
  success: {
    batchSizeChanged: string;
  };
  
  /**
   * Consejos y tips para el usuario
   */
  tips: {
    keyboardShortcut: string;
    generalTip: string;
  };
};

/**
 * startPhase2 - Función para iniciar la Fase 2 de sincronización
 * @global
 */
declare var startPhase2: (() => void) | undefined;

/**
 * Sanitizer - Utilidades de Sanitización de HTML
 * 
 * Proporciona funciones para sanitizar datos del servidor antes de insertarlos en el DOM.
 * Previene ataques XSS (Cross-Site Scripting) al sanitizar o escapar contenido HTML.
 * 
 * @global
 */
declare var Sanitizer: {
  /**
   * Escapa caracteres HTML especiales para prevenir XSS
   * 
   * Convierte caracteres HTML especiales (<, >, &, ", ') en entidades HTML,
   * haciendo que el texto sea seguro para insertar en el DOM con .text().
   * 
   * @param text - Texto a escapar
   * @returns Texto escapado seguro para usar con .text()
   */
  escapeHtml: (text: string) => string;
  
  /**
   * Sanitiza HTML permitiendo solo etiquetas seguras
   * 
   * Si DOMPurify está disponible, lo usa para sanitización robusta.
   * Si no está disponible, usa escapeHtml como fallback seguro.
   * 
   * ⚠️ ADVERTENCIA: Solo usar cuando realmente necesites renderizar HTML.
   * Para texto plano, siempre usar .text() con escapeHtml().
   * 
   * @param html - HTML a sanitizar
   * @param options - Opciones de sanitización
   * @param options.allowBasicFormatting - Permitir etiquetas básicas (b, i, u, strong, em)
   * @returns HTML sanitizado seguro para usar con .html()
   */
  sanitizeHtml: (html: string, options?: { allowBasicFormatting?: boolean }) => string;
  
  /**
   * Sanitiza un mensaje de texto para mostrar en la UI
   * 
   * Método de conveniencia que escapa HTML y prepara el texto para usar con .text().
   * 
   * @param message - Mensaje a sanitizar
   * @returns Mensaje sanitizado seguro para usar con .text()
   */
  sanitizeMessage: (message: string | null | undefined) => string;
};

/**
 * miIntegracionApiDashboard - Objeto global con configuración del dashboard desde PHP
 * @type {Object|undefined}
 * @property {string} ajaxurl - URL para peticiones AJAX
 * @property {string} nonce - Nonce de seguridad para peticiones AJAX
 * @property {string} [restUrl] - URL base para API REST
 * @property {Object} [pollingConfig] - Configuración de polling
 * @property {Object} [timeoutConfig] - Configuración de timeouts
 * @property {Object} [limitsConfig] - Configuración de límites
 * @property {Object} [stallThresholdConfig] - Configuración de umbrales de estancamiento
 * @property {string} [confirmSync] - Mensaje de confirmación para sincronización
 * @property {string} [warningPhase2WithoutPhase1] - Advertencia para Fase 2 sin Fase 1
 * @property {string} [warningPhase2InProgress] - Advertencia para Fase 2 en progreso
 * @property {string} [confirmCancel] - Mensaje de confirmación para cancelar
 * @global
 */
// @ts-ignore - miIntegracionApiDashboard está disponible globalmente desde PHP (wp_localize_script)
declare var miIntegracionApiDashboard: any;

/**
 * ajaxurl - URL para peticiones AJAX de WordPress
 * Variable global definida por WordPress en el área de administración
 * @type {string|undefined}
 * @global
 */
// @ts-ignore - ajaxurl está disponible globalmente desde WordPress (admin-ajax.php)
declare var ajaxurl: string | undefined;

/**
 * nonce - Nonce de seguridad para peticiones AJAX
 * Variable global opcional que puede estar disponible en algunos contextos
 * @type {string|undefined}
 * @global
 */
// @ts-ignore - nonce puede estar disponible globalmente en algunos contextos
declare var nonce: string | undefined;

/**
 * Configuración de DOMPurify para sanitización
 * @typedef {Object} DOMPurifyConfig
 * @property {string[]} [ALLOWED_TAGS] - Etiquetas HTML permitidas
 * @property {string[]} [ALLOWED_ATTR] - Atributos HTML permitidos
 */

/**
 * DOMPurify - Biblioteca para sanitización de HTML
 * @typedef {Object} DOMPurify
 * @property {function(string, DOMPurifyConfig): string} sanitize - Sanitiza HTML
 */

/**
 * SystemEventManager - Gestor de eventos del sistema
 * 
 * Coordinación de inicialización de sistemas externos mediante eventos personalizados.
 * Gestiona el estado de inicialización y emite eventos cuando los sistemas están listos.
 * 
 * @global
 */
interface SystemEventManagerType {
  /**
   * Estado de inicialización de los sistemas
   */
  initializationState: {
    systemBase: boolean;
    errorHandler: boolean;
    unifiedDashboard: boolean;
    allSystems: boolean;
  };
  
  /**
   * Lista de sistemas registrados
   */
  registeredSystems: Map<string, {
    dependencies: Array<string | Function>;
    callback: Function;
    initialized: boolean;
  }>;
  
  /**
   * Inicializar el sistema de eventos
   * @returns {void}
   */
  init(): void;
  
  /**
   * Emitir evento de sistema base listo
   * @returns {void}
   */
  emitSystemBaseReady(): void;
  
  /**
   * Emitir evento de ErrorHandler listo
   * @returns {void}
   */
  emitErrorHandlerReady(): void;
  
  /**
   * Emitir evento de UnifiedDashboard listo
   * @returns {void}
   */
  emitUnifiedDashboardReady(): void;
  
  /**
   * Verificar si todos los sistemas están listos
   * @returns {void}
   */
  checkAllSystemsReady(): void;
  
  /**
   * Registrar un sistema externo
   * @param systemName - Nombre del sistema a registrar
   * @param dependencies - Array de dependencias (strings o funciones)
   * @param callback - Callback a ejecutar cuando el sistema se inicialice
   * @returns {void}
   */
  registerSystem(systemName: string, dependencies: Array<string | Function>, callback: Function): void;
  
  /**
   * Verificar dependencias de un sistema
   * @param systemName - Nombre del sistema a verificar
   * @returns {boolean} true si todas las dependencias están disponibles
   */
  checkDependencies(systemName: string): boolean;
  
  /**
   * Inicializar un sistema si sus dependencias están disponibles
   * @param systemName - Nombre del sistema a inicializar
   * @returns {boolean} true si el sistema se inicializó correctamente
   */
  initializeSystem(systemName: string): boolean;
  
  /**
   * Obtener estado de inicialización
   * @returns {Object} Estado de inicialización completo
   */
  getInitializationState(): {
    systemBase: boolean;
    errorHandler: boolean;
    unifiedDashboard: boolean;
    allSystems: boolean;
    registeredSystems: string[];
    systemDetails: Record<string, {
      initialized: boolean;
      dependencies: Array<string | Function>;
    }>;
  };
  
  /**
   * Logging del sistema de eventos
   * @param message - Mensaje a registrar
   * @param level - Nivel de log ('info', 'warn', 'error')
   * @param data - Datos adicionales a registrar
   * @returns {void}
   */
  log(message: string, level?: 'info' | 'warn' | 'error', data?: any): void;
  
  [key: string]: any;
}

/**
 * Extensión de Window para propiedades personalizadas
 */
interface Window {
  __ConsoleManagerLoaded?: boolean;
  AjaxManager?: any;
  ConsoleManager?: any;
  ErrorHandler?: any;
  EventCleanupManager?: any;
  Sanitizer?: any;
  SystemEventManager?: SystemEventManagerType;
  DOMPurify?: {
    sanitize: (dirty: string, config?: {
      ALLOWED_TAGS?: string[];
      ALLOWED_ATTR?: string[];
    }) => string;
  };
  pollingManager?: any;
  SyncStateManager?: any;
  NonceManager?: {
    attemptRenewal?: (showNotification?: (message: string, type?: string) => void) => void;
    setupAutoRenewal?: (interval?: number, showNotification?: (message: string, type?: string) => void) => void;
    stopAutoRenewal?: () => void;
    isAutoRenewalActive?: () => boolean;
    DEFAULT_RENEWAL_INTERVAL?: number;
    [key: string]: any;
  };
  Phase1Manager?: any;
  Phase2Manager?: any;
  SyncProgress?: any;
  SyncController?: {
    proceedWithSync: (originalText: string) => void;
    [key: string]: any;
  };
  proceedWithSync?: (originalText: string) => void;
  checkSyncProgress?: any;
  updateSyncConsole?: any;
  addConsoleLine?: any;
  syncDashboard?: any;
  SyncDashboard?: any;
  UnifiedDashboard?: any;
  ToastManager?: any;
  showToast?: (message: string, type?: string, duration?: number) => any;
  CardManager?: {
    updateCardData?: ($card: JQuery) => void;
    updateSpecificCard?: ($card: JQuery, cardType: string, data: any) => void;
    [key: string]: any;
  };
  updateCardData?: ($card: JQuery) => void;
  updateSpecificCard?: ($card: JQuery, cardType: string, data: any) => void;
  UIOptimizer?: any;
  DOM_CACHE?: any;
  ProgressBar?: any;
  miIntegracionApiDashboard?: any;
  DASHBOARD_CONFIG?: any;
  SELECTORS?: {
    STAT_CARD?: string;
    STAT_VALUE?: string;
    STAT_DESC?: string;
    STAT_CARD_MEMORY?: string;
    STAT_CARD_RETRIES?: string;
    STAT_CARD_SYNC?: string;
    DASHBOARD_CARDS?: string;
    METRIC_ELEMENTS?: string;
    [key: string]: any;
  };
  MESSAGES?: {
    errors?: {
      jqueryMissing?: string;
      configMissing?: string;
      ajaxUrlMissing?: string;
      connectionError?: string;
      permissionError?: string;
      serverError?: string;
      timeoutError?: string;
      unknownError?: string;
    };
    progress?: {
      preparing?: string;
      verifying?: string;
      connecting?: string;
      processing?: string;
      complete?: string;
      productsProcessed?: string;
      productsSynced?: string;
      productsPerSec?: string;
    };
    milestones?: {
      start?: string;
      quarter?: string;
      half?: string;
      threeQuarters?: string;
      complete?: string;
    };
    status?: {
      pending?: string;
      running?: string;
      completed?: string;
      error?: string;
      paused?: string;
    };
    success?: {
      batchSizeChanged?: string;
    };
    tips?: {
      keyboardShortcut?: string;
      generalTip?: string;
    };
  };
  PollingManager?: any;
  inactiveProgressCounter?: number;
  lastProgressValue?: number;
  phase1Starting?: boolean;
  phase1Initialized?: boolean;
  phase2Starting?: boolean;
  phase2Initialized?: boolean;
  phase2ProcessingBatch?: boolean;
  syncInterval?: number | null;
  phase2PollingInterval?: number | null;
  phase1PollingInterval?: number | null;
  phase1Complete?: boolean;
  originalSyncButtonText?: string;
  pendingPhase2BatchSize?: number;
  DEBUG?: boolean;
  DEV_MODE?: boolean;
  __ConsoleManagerESM?: any;
  __ConsoleManagerCleanupInitialized?: boolean;
  /**
   * Flag para suprimir advertencias de deprecación de acceso directo a variables de estado
   * Útil para testing o cuando se necesita acceso directo temporal
   */
  __SYNC_STATE_SUPPRESS_WARNINGS?: boolean;
  /**
   * ResponsiveLayout - Sistema de layout responsive para el dashboard
   * 
   * Gestiona el layout responsive del dashboard, ajustando el sidebar y el grid
   * de estadísticas según el tamaño de la ventana y la orientación del dispositivo.
   */
  ResponsiveLayout?: {
    /**
     * Timeout para debounce de ajustes de layout
     */
    timeout: number | null;
    /**
     * Ajusta el layout basado en el tamaño de la ventana
     * @returns {void}
     */
    adjustLayout: () => void;
    /**
     * Inicializa el menú responsive
     * @returns {void}
     */
    initResponsiveMenu: () => void;
    /**
     * Inicializa el sistema responsive
     * @returns {void}
     */
    init: () => void;
    [key: string]: any;
  };
}

