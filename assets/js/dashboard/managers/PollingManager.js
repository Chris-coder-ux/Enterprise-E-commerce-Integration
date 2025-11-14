/**
 * Gestor de Polling del Dashboard
 * 
 * Gestiona todos los sistemas de polling de forma unificada con ajuste adaptativo
 * basado en el progreso y la actividad del sistema.
 * 
 * @module managers/PollingManager
 * @class PollingManager
 * @since 1.0.0
 * @author Christian
 */

/* global miIntegracionApiDashboard, ErrorHandler */

/**
 * Clase para gestionar todos los sistemas de polling de forma unificada
 * 
 * @class PollingManager
 * @description Gestión unificada de sistemas de polling del dashboard
 * 
 * @example
 * // Crear instancia
 * const pollingManager = new PollingManager();
 * 
 * // Iniciar polling
 * pollingManager.startPolling('syncProgress', callback, 30000);
 * 
 * // Detener polling
 * pollingManager.stopPolling('syncProgress');
 */
class PollingManager {
  /**
   * Constructor de PollingManager
   * 
   * @constructor
   * @param {Object} [options={}] - Opciones de configuración
   * @param {boolean} [options.testMode=false] - Modo de testing (permite mocking)
   * @param {Object} [options.testHooks={}] - Hooks personalizados para testing
   * @description Inicializa el gestor de polling con configuración desde PHP
   */
  constructor(options = {}) {
    // ✅ NUEVO: Permitir mocking para tests
    this.isTestMode = options.testMode || false;
    this.testHooks = options.testHooks || {};
    
    this.intervals = new Map();
    // ✅ NUEVO: Sistema de eventos para notificar a suscriptores
    this.eventListeners = new Map();
    // ✅ NUEVO: Timer para debounce de ajustes de polling
    this.adjustmentDebounceTimer = null;
    // ✅ NUEVO: Estado de visibilidad de página
    this.pageWasHidden = false;

    // CENTRALIZADO: Solo usar configuración de PHP - sin fallbacks hardcodeados
    // Nota: Usamos verificaciones tradicionales en lugar de optional chaining
    // para compatibilidad con ESLint 3.0.1
    const phpConfig = (typeof miIntegracionApiDashboard !== 'undefined' &&
                       miIntegracionApiDashboard &&
                       miIntegracionApiDashboard.pollingConfig)
      ? miIntegracionApiDashboard.pollingConfig
      : {}; // Si no hay config PHP, será problema del servidor

    this.config = {
      intervals: phpConfig.intervals || {
        normal: 15000,    // 15 segundos - modo normal
        active: 2000,     // 2 segundos - modo activo (sincronización en progreso)
        fast: 1000,       // 1 segundo - modo rápido (progreso activo)
        slow: 45000,      // 45 segundos - modo lento (sin actividad)
        idle: 120000,     // 2 minutos - modo inactivo
        min: 500,         // ✅ NUEVO: Intervalo mínimo absoluto (500ms) - previene sobrecarga
        max: 300000       // ✅ NUEVO: Intervalo máximo absoluto (5 minutos) - previene timeouts
      },
      thresholds: phpConfig.thresholds || {
        to_slow: 3,
        to_idle: 8,
        max_errors: 5,
        progress_threshold: 0.1,  // Cambio mínimo de progreso para considerar activo
        latency_threshold: 1000,  // ✅ NUEVO: Latencia máxima aceptable (1 segundo)
        error_backoff_base: 2,     // ✅ NUEVO: Base para backoff exponencial
        error_backoff_max: 60000,  // ✅ NUEVO: Backoff máximo (60 segundos)
        consecutive_errors_threshold: 3  // ✅ NUEVO: Errores consecutivos antes de activar backoff
      },
      maxListenersPerEvent: phpConfig.maxListenersPerEvent || 100,  // ✅ NUEVO: Límite máximo de listeners por evento para prevenir memory leaks
      adjustmentDebounceMs: phpConfig.adjustmentDebounceMs || 1000,  // ✅ NUEVO: Tiempo de debounce para ajustes de polling (1 segundo)
      currentInterval: (phpConfig.intervals && phpConfig.intervals.normal) || 10000, // Mantener && para compatibilidad
      currentMode: 'normal',
      errorCount: 0,
      consecutiveErrors: 0,  // ✅ NUEVO: Contador de errores consecutivos
      lastProgress: 0,
      progressStagnantCount: 0,
      lastResponseTime: null,  // ✅ NUEVO: Tiempo de respuesta de la última petición
      averageLatency: null,    // ✅ NUEVO: Latencia promedio calculada
      backoffMultiplier: 1,    // ✅ NUEVO: Multiplicador actual para backoff
      userActive: true,        // ✅ NUEVO: Estado de actividad del usuario
      lastUserActivity: Date.now()  // ✅ NUEVO: Timestamp de última actividad del usuario
    };
    this.counters = {
      inactive: 0,
      lastProgress: 0
    };
    
    // ✅ NUEVO: Métricas de rendimiento
    this.metrics = {
      totalRequests: 0,
      successfulRequests: 0,
      failedRequests: 0,
      averageResponseTime: 0,
      uptime: Date.now()
    };
    
    // ✅ NUEVO: Inicializar detección de actividad del usuario
    this.initializeUserActivityDetection();
    
    // ✅ NUEVO: Inicializar detección de visibilidad de página
    this.initializePageVisibility();
  }

  /**
   * Suscribirse a un evento
   * 
   * ✅ MEJORADO: Previene memory leaks limitando el número máximo de listeners por evento.
   * 
   * @param {string} eventName - Nombre del evento ('syncProgress', 'syncError', etc.)
   * @param {Function} callback - Función a ejecutar cuando se emita el evento
   * @returns {Function} Función para desuscribirse (función vacía si se alcanzó el límite)
   * 
   * @example
   * const unsubscribe = pollingManager.on('syncProgress', (data) => {
   *   console.log('Progreso actualizado:', data);
   * });
   * 
   * // Para desuscribirse:
   * unsubscribe();
   */
  on(eventName, callback) {
    // Validar que el callback sea una función
    if (typeof callback !== 'function') {
      throw new TypeError(`Callback para evento '${eventName}' debe ser una función`);
    }
    
    if (!this.eventListeners.has(eventName)) {
      this.eventListeners.set(eventName, []);
    }
    
    const listeners = this.eventListeners.get(eventName);
    const maxListeners = this.config.maxListenersPerEvent || 100;
    
    // ✅ MEJORADO: Prevenir memory leaks limitando número máximo de listeners
    if (listeners.length >= maxListeners) {
      // Registrar advertencia usando ErrorHandler si está disponible
      if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
        ErrorHandler.logError(
          `Límite máximo de listeners alcanzado para evento '${eventName}' (${maxListeners}). ` +
          'No se puede agregar más listeners. Esto puede indicar un memory leak.',
          'POLLING_EVENT_LIMIT'
        );
      } else if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('Too many listeners for event \'' + eventName + '\' (' + listeners.length + '/' + maxListeners + '). ' +
          'This may indicate a memory leak.');
      }
      
      // Retornar función vacía de desuscripción para mantener la API consistente
      return () => {};
    }
    
    listeners.push(callback);
    
    // Retornar función para desuscribirse
    return () => {
      const currentListeners = this.eventListeners.get(eventName);
      if (currentListeners) {
        const index = currentListeners.indexOf(callback);
        if (index > -1) {
          currentListeners.splice(index, 1);
        }
      }
    };
  }

  /**
   * Emitir un evento
   * 
   * @param {string} eventName - Nombre del evento
   * @param {*} data - Datos a pasar a los listeners
   * @returns {void}
   * 
   * @example
   * pollingManager.emit('syncProgress', { progress: 50, status: 'active' });
   */
  emit(eventName, data) {
    const listeners = this.eventListeners.get(eventName);
    
    if (listeners && listeners.length > 0) {
      listeners.forEach((callback) => {
        try {
          callback(data);
        } catch (error) {
          // ✅ MEJORADO: Registrar error usando ErrorHandler en lugar de silenciarlo
          // Buscar ErrorHandler primero en window (para compatibilidad con tests)
          // y luego como variable global
          let errorHandler = null;
          if (typeof window !== 'undefined' && window.ErrorHandler) {
            errorHandler = window.ErrorHandler;
          } else if (typeof ErrorHandler !== 'undefined' && ErrorHandler) {
            errorHandler = ErrorHandler;
          }
          
          if (errorHandler && typeof errorHandler.logError === 'function') {
            // Extraer mensaje del error de forma segura
            let errorMessage = 'Error desconocido';
            if (error) {
              if (error.message) {
                errorMessage = error.message;
              } else if (typeof error.toString === 'function') {
                errorMessage = error.toString();
              } else {
                errorMessage = String(error);
              }
            }
            
            errorHandler.logError(
              `Error en listener del evento '${eventName}': ${errorMessage}`,
              'POLLING_EVENT'
            );
          }
          // El listener falló pero no afecta otros listeners
        }
      });
    }
  }

  /**
   * Desuscribirse de un evento
   * 
   * @param {string} eventName - Nombre del evento
   * @param {Function} callback - Función a desuscribir (opcional, si no se proporciona se desuscriben todos)
   * @returns {void}
   * 
   * @example
   * pollingManager.off('syncProgress', myCallback);
   * pollingManager.off('syncProgress'); // Desuscribir todos los listeners
   */
  off(eventName, callback) {
    if (!callback) {
      // Desuscribir todos los listeners del evento
      this.eventListeners.delete(eventName);
      return;
    }
    
    const listeners = this.eventListeners.get(eventName);
    if (listeners) {
      const index = listeners.indexOf(callback);
      if (index > -1) {
        listeners.splice(index, 1);
      }
    }
  }

  /**
   * Iniciar polling con nombre específico
   * 
   * ✅ MEJORADO: Si el polling ya está activo, retorna el ID existente en lugar de recrearlo.
   * Esto previene duplicaciones y permite que múltiples componentes soliciten el mismo polling
   * sin conflictos.
   * 
   * @param {string} name - Nombre único del polling
   * @param {Function} callback - Función a ejecutar en cada intervalo
   * @param {number|null} [interval=null] - Intervalo en milisegundos (usa configuración por defecto si es null)
   * @param {*} [context=undefined] - Contexto (this) para ejecutar el callback (opcional)
   * @returns {number|NodeJS.Timeout} ID del intervalo creado o existente
   * 
   * @example
   * pollingManager.startPolling('syncProgress', checkSyncProgress, 30000);
   * 
   * @example
   * // Para métodos de objetos, usar arrow functions o bind:
   * pollingManager.startPolling('syncProgress', () => obj.method(), 30000);
   * // o
   * pollingManager.startPolling('syncProgress', obj.method.bind(obj), 30000);
   * 
   * @example
   * // O pasar el contexto directamente:
   * pollingManager.startPolling('syncProgress', obj.method, 30000, obj);
   */
  startPolling(name, callback, interval = null, context = undefined) {
    // ✅ MEJORADO: Validación exhaustiva de parámetros de entrada
    // Validar nombre
    if (!name || typeof name !== 'string' || name.trim().length === 0) {
      throw new TypeError('Polling name must be a valid non-empty string');
    }
    
    // Validar callback
    if (typeof callback !== 'function') {
      throw new TypeError('Callback must be a function');
    }
    
    // Validar intervalo
    if (interval !== null && (typeof interval !== 'number' || !isFinite(interval) || interval <= 0)) {
      throw new TypeError('Interval must be a positive number or null');
    }
    
    // ✅ MEJORADO: Si el polling ya está activo, retornar el ID existente
    // Esto previene duplicaciones cuando múltiples componentes solicitan el mismo polling
    if (this.intervals.has(name)) {
      const existingPolling = this.intervals.get(name);
      // Opcional: Actualizar callback si es diferente (útil para debugging)
      // Pero mantener el intervalo existente para evitar recreaciones innecesarias
      return existingPolling.id;
    }

    // ✅ MEJORADO: Aplicar límites mínimo y máximo al intervalo
    const requestedInterval = interval || this.config.currentInterval;
    const minInterval = this.config.intervals.min || 500;
    const maxInterval = this.config.intervals.max || 300000;
    const actualInterval = Math.max(minInterval, Math.min(maxInterval, requestedInterval));
    
    // ✅ CORREGIDO: Si se proporciona un contexto, bindear el callback para preservar 'this'
    // Esto permite pasar métodos de objetos directamente sin necesidad de bindear manualmente
    const boundCallback = (context !== undefined && context !== null)
      ? callback.bind(context)
      : callback;
    
    // ✅ MEJORADO: Medir tiempo de ejecución del callback para detectar latencia
    const intervalId = setInterval(() => {
      const startTime = Date.now();
      try {
        // ✅ CORREGIDO: Ejecutar callback preservando su contexto original
        // Si se proporcionó un contexto, el callback ya está bindeado con .bind()
        // Si no, usamos .call() con undefined para ser explícitos sobre el contexto
        // (equivalente a callback() pero más claro sobre la intención)
        if (context !== undefined && context !== null) {
          // Callback ya bindeado, ejecutar normalmente
          boundCallback();
        } else {
          // Ejecutar con .call() para ser explícitos sobre el contexto
          callback.call(undefined);
        }
        // ✅ NUEVO: Registrar tiempo de respuesta si el callback es asíncrono
        // (Para callbacks síncronos, esto será ~0ms)
        const responseTime = Date.now() - startTime;
        if (responseTime > 10) { // Solo registrar si toma más de 10ms
          this.recordResponseTime(responseTime);
        }
      } catch (error) {
        // ✅ MEJORADO: Registrar error y aplicar backoff
        if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
          ErrorHandler.logError(
            `Error en callback de polling '${name}': ${error.message || error}`,
            'POLLING_CALLBACK'
          );
        }
        
        // ✅ NUEVO: Aplicar backoff exponencial para errores
        this.recordError();
        
        // Emitir evento de error si el callback falla
        this.emit('pollingError', {
          name,
          error: error.message,
          stack: error.stack,
          timestamp: Date.now(),
          consecutiveErrors: this.config.consecutiveErrors
        });
      }
    }, actualInterval);

    this.intervals.set(name, {
      id: intervalId,
      callback: boundCallback, // ✅ CORREGIDO: Guardar callback bindeado si se proporcionó contexto
      originalCallback: callback, // Guardar callback original para referencia
      context, // Guardar contexto para referencia
      interval: actualInterval,
      startTime: Date.now()
    });

    // ✅ NUEVO: Emitir evento cuando se inicia un polling
    this.emit('pollingStarted', {
      name,
      interval: actualInterval,
      timestamp: Date.now()
    });

    return intervalId;
  }

  /**
   * Detener polling específico
   * 
   * ✅ MEJORADO: Emite evento cuando se detiene un polling para notificar a suscriptores.
   * 
   * @param {string} name - Nombre del polling a detener
   * @returns {boolean} true si se detuvo correctamente, false si no existía
   * 
   * @example
   * pollingManager.stopPolling('syncProgress');
   */
  stopPolling(name) {
    if (this.intervals.has(name)) {
      const polling = this.intervals.get(name);
      clearInterval(polling.id);
      this.intervals.delete(name);
      
      // ✅ NUEVO: Emitir evento cuando se detiene un polling
      this.emit('pollingStopped', {
        name,
        timestamp: Date.now()
      });
      
      return true;
    }
    return false;
  }

  /**
   * Detener todos los polling
   * 
   * @returns {void}
   * 
   * @example
   * pollingManager.stopAllPolling();
   */
  stopAllPolling() {
    for (const polling of this.intervals.values()) {
      clearInterval(polling.id);
    }
    this.intervals.clear();
  }

  /**
   * Verificar si hay polling activo
   * 
   * @param {string|null} [name=null] - Nombre específico del polling a verificar (opcional)
   * @returns {boolean} true si hay polling activo, false en caso contrario
   * 
   * @example
   * // Verificar si hay algún polling activo
   * pollingManager.isPollingActive();
   * 
   * // Verificar polling específico
   * pollingManager.isPollingActive('syncProgress');
   */
  isPollingActive(name = null) {
    if (name) {
      return this.intervals.has(name);
    }
    return this.intervals.size > 0;
  }

  /**
   * Obtener el ID del intervalo de un polling específico
   * 
   * @param {string} name - Nombre del polling
   * @returns {number|null} ID del intervalo o null si no existe
   * 
   * @example
   * const intervalId = pollingManager.getIntervalId('syncProgress');
   */
  getIntervalId(name) {
    if (this.intervals.has(name)) {
      return this.intervals.get(name).id;
    }
    return null;
  }

  /**
   * ✅ NUEVO: Inicializa la detección de actividad del usuario
   * 
   * @returns {void}
   * @private
   */
  initializeUserActivityDetection() {
    if (typeof window === 'undefined') {
      return;
    }
    
    const activityEvents = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
    const updateActivity = () => {
      this.config.lastUserActivity = Date.now();
      if (!this.config.userActive) {
        this.config.userActive = true;
        // Si el usuario vuelve a estar activo, ajustar polling si es necesario
        this.adjustPollingForUserActivity();
      }
    };
    
    activityEvents.forEach(event => {
      window.addEventListener(event, updateActivity, { passive: true });
    });
    
    // Verificar actividad periódicamente
    setInterval(() => {
      const timeSinceActivity = Date.now() - this.config.lastUserActivity;
      const inactiveThreshold = 60000; // 1 minuto sin actividad = inactivo
      
      if (timeSinceActivity > inactiveThreshold && this.config.userActive) {
        this.config.userActive = false;
        this.adjustPollingForUserActivity();
      }
    }, 10000); // Verificar cada 10 segundos
  }

  /**
   * ✅ NUEVO: Ajusta el polling basándose en la actividad del usuario
   * 
   * @returns {void}
   * @private
   */
  adjustPollingForUserActivity() {
    if (!this.config.userActive && this.config.currentMode !== 'idle') {
      // Usuario inactivo - reducir frecuencia de polling
      const currentInterval = this.config.currentInterval;
      const newInterval = Math.min(currentInterval * 2, this.config.intervals.idle);
      this.updatePollingInterval(newInterval, 'low-power');
    } else if (this.config.userActive && this.config.currentMode === 'low-power') {
      // Usuario activo de nuevo - restaurar intervalo normal
      this.config.currentMode = 'normal';
      this.config.currentInterval = this.config.intervals.normal;
      this.updatePollingInterval(this.config.intervals.normal, 'normal');
    }
  }

  /**
   * ✅ NUEVO: Inicializa la detección de visibilidad de página
   * 
   * Pausa o reduce la frecuencia del polling cuando la página no está visible
   * para ahorrar recursos y mejorar el rendimiento.
   * 
   * @returns {void}
   * @private
   */
  initializePageVisibility() {
    if (typeof document === 'undefined') {
      return;
    }
    
    const handleVisibilityChange = () => {
      if (document.hidden) {
        this.pageWasHidden = true;
        // Reducir frecuencia cuando la página no está visible
        const currentInterval = this.config.currentInterval;
        const idleInterval = this.config.intervals.idle || 120000;
        const newInterval = Math.min(currentInterval * 3, idleInterval);
        
        // Aplicar límites mínimo y máximo
        const minInterval = this.config.intervals.min || 500;
        const maxInterval = this.config.intervals.max || 300000;
        const clampedInterval = Math.max(minInterval, Math.min(maxInterval, newInterval));
        
        this.updatePollingInterval(clampedInterval, 'page-hidden');
        
        // Emitir evento
        this.emit('pageHidden', {
          timestamp: Date.now(),
          newInterval: clampedInterval
        });
      } else if (this.pageWasHidden) {
        this.pageWasHidden = false;
        // Restaurar intervalo normal cuando la página vuelve a estar visible
        this.adjustPolling(this.config.lastProgress, this.isPollingActive());
        
        // Emitir evento
        this.emit('pageVisible', {
          timestamp: Date.now(),
          restoredInterval: this.config.currentInterval
        });
      }
    };
    
    document.addEventListener('visibilitychange', handleVisibilityChange);
  }

  /**
   * ✅ OPTIMIZADO: Actualiza el intervalo de todos los polling activos
   * 
   * En lugar de detener y reiniciar todos los polling, actualiza cada intervalo
   * individualmente para mejorar el rendimiento y evitar interrupciones innecesarias.
   * 
   * @param {number} newInterval - Nuevo intervalo en milisegundos
   * @param {string} mode - Modo actual
   * @returns {void}
   * @private
   */
  updatePollingInterval(newInterval, mode) {
    // Aplicar límites mínimo y máximo
    const minInterval = this.config.intervals.min || 500;
    const maxInterval = this.config.intervals.max || 300000;
    const clampedInterval = Math.max(minInterval, Math.min(maxInterval, newInterval));
    
    this.config.currentInterval = clampedInterval;
    this.config.currentMode = mode;
    
    // ✅ OPTIMIZADO: Actualizar intervalos individualmente sin recrear desde cero
    // Esto es más eficiente que stopAllPolling + startPolling porque:
    // 1. No necesita validaciones adicionales de startPolling
    // 2. No emite eventos de stop/start innecesarios
    // 3. Mantiene el estado de cada polling intacto
    if (this.intervals.size > 0) {
      for (const [name, polling] of this.intervals.entries()) {
        // Limpiar el intervalo anterior
        clearInterval(polling.id);
        
        // ✅ MEJORADO: Usar el callback bindeado que ya tenemos guardado
        // El callback ya tiene el contexto correcto (boundCallback guardado en startPolling)
        const callbackToUse = polling.callback || polling.originalCallback;
        
        if (typeof callbackToUse !== 'function') {
          // Si no hay callback válido, eliminar el polling
          this.intervals.delete(name);
          continue;
        }
        
        // ✅ OPTIMIZADO: Crear nuevo intervalo directamente sin pasar por startPolling
        // El callback ya está bindeado, solo necesitamos ejecutarlo
        const newId = setInterval(() => {
          const startTime = Date.now();
          try {
            // Ejecutar callback (ya está bindeado con el contexto correcto)
            callbackToUse();
            
            // Registrar tiempo de respuesta si es significativo
            const responseTime = Date.now() - startTime;
            if (responseTime > 10) {
              this.recordResponseTime(responseTime);
            }
          } catch (error) {
            // Registrar error y aplicar backoff
            if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
              ErrorHandler.logError(
                `Error en callback de polling '${name}': ${error.message || error}`,
                'POLLING_CALLBACK'
              );
            }
            
            this.recordError();
            
            // Emitir evento de error
            this.emit('pollingError', {
              name,
              error: error.message,
              stack: error.stack,
              timestamp: Date.now(),
              consecutiveErrors: this.config.consecutiveErrors
            });
          }
        }, clampedInterval);
        
        // Actualizar el polling con el nuevo ID e intervalo
        this.intervals.set(name, {
          ...polling,
          id: newId,
          interval: clampedInterval
        });
      }
    }
  }

  /**
   * ✅ MEJORADO: Registra el tiempo de respuesta de una petición y actualiza métricas
   * 
   * @param {number} responseTime - Tiempo de respuesta en milisegundos
   * @returns {void}
   */
  recordResponseTime(responseTime) {
    // ✅ NUEVO: Actualizar métricas de rendimiento
    this.metrics.totalRequests++;
    this.metrics.successfulRequests++;
    
    // Calcular promedio móvil de tiempo de respuesta
    if (this.metrics.averageResponseTime === 0) {
      this.metrics.averageResponseTime = responseTime;
    } else {
      // Media móvil exponencial con factor de suavizado 0.1 (90% anterior, 10% nuevo)
      this.metrics.averageResponseTime = 
        (this.metrics.averageResponseTime * 0.9) + (responseTime * 0.1);
    }
    
    this.config.lastResponseTime = responseTime;
    
    // Calcular latencia promedio (media móvil simple)
    if (this.config.averageLatency === null) {
      this.config.averageLatency = responseTime;
    } else {
      // Media móvil exponencial con factor de suavizado 0.3
      this.config.averageLatency = this.config.averageLatency * 0.7 + responseTime * 0.3;
    }
    
    // Ajustar intervalo basándose en latencia
    this.adjustPollingForLatency();
    
    // Resetear contador de errores consecutivos si la petición fue exitosa
    if (responseTime < this.config.thresholds.latency_threshold) {
      this.config.consecutiveErrors = 0;
      this.config.backoffMultiplier = 1;
    }
  }

  /**
   * ✅ NUEVO: Ajusta el polling basándose en la latencia del servidor
   * 
   * @returns {void}
   * @private
   */
  adjustPollingForLatency() {
    if (this.config.averageLatency === null) {
      return;
    }
    
    const latencyThreshold = this.config.thresholds.latency_threshold;
    const currentInterval = this.config.currentInterval;
    
    if (this.config.averageLatency > latencyThreshold * 2) {
      // Latencia muy alta - aumentar intervalo significativamente
      const newInterval = Math.min(currentInterval * 1.5, this.config.intervals.max);
      if (newInterval !== currentInterval) {
        this.updatePollingInterval(newInterval, 'high-latency');
      }
    } else if (this.config.averageLatency > latencyThreshold) {
      // Latencia moderada - aumentar intervalo ligeramente
      const newInterval = Math.min(currentInterval * 1.2, this.config.intervals.max);
      if (newInterval !== currentInterval && currentInterval < this.config.intervals.slow) {
        this.updatePollingInterval(newInterval, 'moderate-latency');
      }
    } else if (this.config.averageLatency < latencyThreshold * 0.5 && currentInterval > this.config.intervals.active) {
      // Latencia baja - reducir intervalo si es seguro
      const newInterval = Math.max(currentInterval * 0.9, this.config.intervals.active);
      if (newInterval !== currentInterval) {
        this.updatePollingInterval(newInterval, this.config.currentMode);
      }
    }
  }

  /**
   * ✅ MEJORADO: Registra un error y aplica backoff exponencial con jitter
   * También actualiza las métricas de rendimiento
   * 
   * @returns {number} Nuevo intervalo con backoff aplicado
   */
  recordError() {
    // ✅ NUEVO: Actualizar métricas de errores
    this.metrics.totalRequests++;
    this.metrics.failedRequests++;
    
    this.config.errorCount++;
    this.config.consecutiveErrors++;
    
    // Solo aplicar backoff si hay múltiples errores consecutivos
    if (this.config.consecutiveErrors >= this.config.thresholds.consecutive_errors_threshold) {
      // Backoff exponencial: base^consecutiveErrors
      const base = this.config.thresholds.error_backoff_base;
      const exponentialBackoff = Math.pow(base, this.config.consecutiveErrors - this.config.thresholds.consecutive_errors_threshold + 1);
      
      // Aplicar jitter aleatorio (±20%) para evitar sincronización de múltiples clientes
      const jitter = 1 + (Math.random() * 0.4 - 0.2); // Entre 0.8 y 1.2
      const backoffInterval = this.config.currentInterval * exponentialBackoff * jitter;
      
      // Limitar al máximo configurado
      const maxBackoff = this.config.thresholds.error_backoff_max;
      const newInterval = Math.min(backoffInterval, maxBackoff);
      
      // Aplicar límites mínimo y máximo
      const minInterval = this.config.intervals.min || 500;
      const maxInterval = this.config.intervals.max || 300000;
      const clampedInterval = Math.max(minInterval, Math.min(maxInterval, newInterval));
      
      this.config.backoffMultiplier = exponentialBackoff;
      this.updatePollingInterval(clampedInterval, 'error-backoff');
      
      // Registrar error usando ErrorHandler si está disponible
      if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
        ErrorHandler.logError(
          `Polling backoff aplicado: ${this.config.consecutiveErrors} errores consecutivos, nuevo intervalo: ${Math.round(clampedInterval / 1000)}s`,
          'POLLING_BACKOFF'
        );
      }
      
      return clampedInterval;
    }
    
    return this.config.currentInterval;
  }

  /**
   * Ajusta el polling adaptativamente basado en el progreso
   * 
   * ✅ MEJORADO: Ahora usa debounce para evitar ajustes demasiado frecuentes
   * y considera latencia, actividad del usuario y errores
   * 
   * @param {number} currentProgress - Progreso actual (0-100)
   * @param {boolean} isActive - Si la sincronización está activa
   * @returns {void}
   * 
   * @example
   * pollingManager.adjustPolling(75, true);
   */
  adjustPolling(currentProgress, isActive) {
    // ✅ MEJORADO: Debounce para evitar ajustes demasiado frecuentes
    // Si hay un timer pendiente, cancelarlo y crear uno nuevo
    if (this.adjustmentDebounceTimer) {
      clearTimeout(this.adjustmentDebounceTimer);
    }
    
    // Guardar los parámetros para usarlos en el callback
    const debounceMs = this.config.adjustmentDebounceMs || 1000;
    
    this.adjustmentDebounceTimer = setTimeout(() => {
      this.performAdjustment(currentProgress, isActive);
      this.adjustmentDebounceTimer = null;
    }, debounceMs);
  }

  /**
   * Realiza el ajuste del polling basado en el progreso
   * 
   * ✅ NUEVO: Método interno que contiene la lógica de ajuste.
   * Se llama después del debounce para evitar ajustes demasiado frecuentes.
   * 
   * @param {number} currentProgress - Progreso actual (0-100)
   * @param {boolean} isActive - Si la sincronización está activa
   * @returns {void}
   * @private
   */
  performAdjustment(currentProgress, isActive) {
    // ✅ MEJORADO: Obtener límites con valores por defecto al inicio
    const minInterval = this.config.intervals.min || 500;
    const maxInterval = this.config.intervals.max || 300000;

    if (!isActive) {
      this.config.currentMode = 'idle';
      const idleInterval = this.config.intervals.idle || 120000;
      this.config.currentInterval = Math.max(minInterval, Math.min(maxInterval, idleInterval));
      this.updatePollingInterval(this.config.currentInterval, 'idle');
      return;
    }

    // ✅ MEJORADO: Considerar actividad del usuario
    if (!this.config.userActive) {
      // Usuario inactivo - usar modo de bajo consumo
      const slowInterval = this.config.intervals.slow || 45000;
      const lowPowerInterval = Math.min(slowInterval, this.config.currentInterval * 2);
      const clampedLowPowerInterval = Math.max(minInterval, Math.min(maxInterval, lowPowerInterval));
      this.updatePollingInterval(clampedLowPowerInterval, 'low-power');
      return;
    }

    const progressChange = Math.abs(currentProgress - this.config.lastProgress);
    const threshold = this.config.thresholds.progress_threshold;

    // ✅ MEJORADO: Considerar latencia antes de ajustar por progreso
    const latencyFactor = this.config.averageLatency 
      ? Math.min(this.config.averageLatency / this.config.thresholds.latency_threshold, 2)
      : 1;

    // OPTIMIZACIÓN: Lógica más conservadora para reducir peticiones
    if (progressChange > threshold) {
      // Progreso activo - usar modo rápido solo si el cambio es significativo
      if (progressChange > 5) { // Cambio de más del 5%
        const fastIntervalBase = (this.config.intervals.fast || 1000) * latencyFactor;
        const fastInterval = Math.max(fastIntervalBase, minInterval);
        this.config.currentMode = 'fast';
        this.config.currentInterval = Math.min(fastInterval, maxInterval);
      } else {
        // Cambio pequeño - mantener modo activo
        const activeIntervalBase = (this.config.intervals.active || 2000) * latencyFactor;
        const activeInterval = Math.max(activeIntervalBase, minInterval);
        this.config.currentMode = 'active';
        this.config.currentInterval = Math.min(activeInterval, maxInterval);
      }
      this.config.progressStagnantCount = 0;
    } else {
      // Progreso estancado - incrementar contador
      this.config.progressStagnantCount++;

      if (this.config.progressStagnantCount >= 5) { // Aumentado de 3 a 5
        // Progreso estancado por 5 ciclos - usar modo lento
        const slowInterval = this.config.intervals.slow || 45000;
        this.config.currentMode = 'slow';
        this.config.currentInterval = Math.max(minInterval, Math.min(maxInterval, slowInterval));
      } else if (this.config.progressStagnantCount >= 2) {
        // Después de 2 ciclos sin progreso - usar modo normal
        const normalInterval = this.config.intervals.normal || 15000;
        this.config.currentMode = 'normal';
        this.config.currentInterval = Math.max(minInterval, Math.min(maxInterval, normalInterval));
      } else {
        // Mantener modo activo normal
        const activeInterval = this.config.intervals.active || 2000;
        this.config.currentMode = 'active';
        this.config.currentInterval = Math.max(minInterval, Math.min(maxInterval, activeInterval));
      }
    }

    // ✅ MEJORADO: Aplicar límites mínimo y máximo (garantía final)
    this.config.currentInterval = Math.max(minInterval, Math.min(maxInterval, this.config.currentInterval));

    this.config.lastProgress = currentProgress;
  }

  /**
   * Obtener métricas de rendimiento
   * 
   * ✅ NUEVO: Retorna un objeto con las métricas actuales del sistema de polling
   * 
   * @returns {Object} Objeto con métricas de rendimiento
   * @property {number} totalRequests - Total de peticiones realizadas
   * @property {number} successfulRequests - Peticiones exitosas
   * @property {number} failedRequests - Peticiones fallidas
   * @property {number} averageResponseTime - Tiempo promedio de respuesta en ms
   * @property {number} uptime - Tiempo de actividad en milisegundos
   * @property {number} successRate - Tasa de éxito (0-1)
   * 
   * @example
   * const metrics = pollingManager.getMetrics();
   * console.log(`Tasa de éxito: ${(metrics.successRate * 100).toFixed(2)}%`);
   */
  getMetrics() {
    const uptime = Date.now() - this.metrics.uptime;
    const successRate = this.metrics.totalRequests > 0
      ? this.metrics.successfulRequests / this.metrics.totalRequests
      : 0;
    
    return {
      totalRequests: this.metrics.totalRequests,
      successfulRequests: this.metrics.successfulRequests,
      failedRequests: this.metrics.failedRequests,
      averageResponseTime: Math.round(this.metrics.averageResponseTime * 100) / 100, // Redondear a 2 decimales
      uptime,
      successRate: Math.round(successRate * 10000) / 10000 // Redondear a 4 decimales
    };
  }

  /**
   * Obtener estado completo del sistema de polling
   * 
   * ✅ NUEVO: Retorna un objeto con el estado actual del sistema de polling.
   * Útil para debugging y monitoreo del sistema.
   * 
   * @returns {Object} Objeto con el estado del sistema de polling
   * @property {string[]} activePollings - Array con los nombres de los polling activos
   * @property {string} currentMode - Modo actual del polling ('normal', 'active', 'fast', 'slow', 'idle', etc.)
   * @property {number} currentInterval - Intervalo actual en milisegundos
   * @property {number} errorCount - Contador total de errores
   * @property {number} consecutiveErrors - Contador de errores consecutivos
   * @property {boolean} userActive - Si el usuario está activo
   * @property {number|null} averageLatency - Latencia promedio en milisegundos (null si no hay datos)
   * @property {Object} metrics - Métricas de rendimiento (copia del objeto metrics)
   * 
   * @example
   * const status = pollingManager.getPollingStatus();
   * console.log(`Polling activos: ${status.activePollings.join(', ')}`);
   * console.log(`Modo actual: ${status.currentMode}`);
   * console.log(`Intervalo: ${status.currentInterval}ms`);
   */
  getPollingStatus() {
    return {
      activePollings: Array.from(this.intervals.keys()),
      currentMode: this.config.currentMode,
      currentInterval: this.config.currentInterval,
      errorCount: this.config.errorCount,
      consecutiveErrors: this.config.consecutiveErrors,
      userActive: this.config.userActive,
      averageLatency: this.config.averageLatency,
      metrics: { ...this.metrics }
    };
  }

  /**
   * Esperar el próximo evento de un tipo específico
   * 
   * ✅ NUEVO: Método útil para testing que retorna una Promise que se resuelve
   * cuando se emite el próximo evento del tipo especificado.
   * 
   * @param {string} eventName - Nombre del evento a esperar
   * @param {number} [timeout=5000] - Timeout en milisegundos (solo en testMode)
   * @returns {Promise<*>} Promise que se resuelve con los datos del evento
   * 
   * @example
   * // En un test
   * const pollingManager = new PollingManager({ testMode: true });
   * 
   * // Esperar el próximo evento 'syncProgress'
   * const data = await pollingManager.waitForNextTick('syncProgress');
   * console.log('Datos recibidos:', data);
   * 
   * @example
   * // Con timeout personalizado
   * try {
   *   const data = await pollingManager.waitForNextTick('syncCompleted', 10000);
   * } catch (error) {
   *   console.log('Timeout esperando evento');
   * }
   */
  waitForNextTick(eventName, timeout = 5000) {
    return new Promise((resolve, reject) => {
      // Validar que el nombre del evento sea válido
      if (!eventName || typeof eventName !== 'string') {
        reject(new TypeError('eventName debe ser un string válido'));
        return;
      }
      
      // Suscribirse al evento primero
      let unsubscribe = null;
      let timeoutId = null;
      
      // Función para limpiar
      const cleanup = () => {
        if (timeoutId) {
          clearTimeout(timeoutId);
          timeoutId = null;
        }
        if (unsubscribe) {
          unsubscribe();
          unsubscribe = null;
        }
      };
      
      // Suscribirse al evento
      unsubscribe = this.on(eventName, (data) => {
        cleanup();
        resolve(data);
      });
      
      // En modo test, agregar timeout
      if (this.isTestMode && timeout > 0) {
        timeoutId = setTimeout(() => {
          cleanup();
          reject(new Error(`Timeout esperando evento '${eventName}' después de ${timeout}ms`));
        }, timeout);
      }
    });
  }

  /**
   * Obtener información de un polling específico para testing
   * 
   * ✅ NUEVO: Método útil para testing que retorna información detallada
   * de un polling específico.
   * 
   * @param {string} name - Nombre del polling
   * @returns {Object|null} Información del polling o null si no existe
   * @property {number} id - ID del intervalo
   * @property {number} interval - Intervalo actual en ms
   * @property {number} startTime - Timestamp de inicio
   * 
   * @example
   * const info = pollingManager.getPollingInfo('syncProgress');
   * if (info) {
   *   console.log(`Intervalo: ${info.interval}ms`);
   * }
   */
  getPollingInfo(name) {
    if (!name || typeof name !== 'string') {
      return null;
    }
    
    if (!this.intervals.has(name)) {
      return null;
    }
    
    const polling = this.intervals.get(name);
    return {
      id: polling.id,
      interval: polling.interval,
      startTime: polling.startTime,
      hasContext: polling.context !== undefined && polling.context !== null
    };
  }

  /**
   * Forzar ejecución de un callback de polling (solo en testMode)
   * 
   * ✅ NUEVO: Método útil para testing que permite ejecutar manualmente
   * el callback de un polling sin esperar al intervalo.
   * 
   * @param {string} name - Nombre del polling
   * @returns {Promise<*>} Promise que se resuelve con el resultado del callback
   * 
   * @example
   * const pollingManager = new PollingManager({ testMode: true });
   * pollingManager.startPolling('test', () => console.log('Callback ejecutado'));
   * 
   * // Forzar ejecución
   * await pollingManager.triggerPollingCallback('test');
   */
  triggerPollingCallback(name) {
    if (!this.isTestMode) {
      throw new Error('triggerPollingCallback solo está disponible en testMode');
    }
    
    if (!name || typeof name !== 'string') {
      throw new TypeError('name debe ser un string válido');
    }
    
    if (!this.intervals.has(name)) {
      throw new Error(`Polling '${name}' no existe`);
    }
    
    const polling = this.intervals.get(name);
    const callback = polling.callback || polling.originalCallback;
    
    if (typeof callback !== 'function') {
      throw new Error(`Polling '${name}' no tiene un callback válido`);
    }
    
    try {
      const result = callback();
      // Si el callback retorna una Promise, retornarla
      if (result && typeof result.then === 'function') {
        return result;
      }
      return Promise.resolve(result);
    } catch (error) {
      return Promise.reject(error);
    }
  }

  /**
   * Actualizar configuración dinámicamente en tiempo real
   * 
   * ✅ NUEVO: Permite actualizar la configuración del sistema de polling sin reiniciar.
   * Realiza un merge seguro de la configuración, actualizando solo las propiedades existentes.
   * 
   * @param {Object} newConfig - Objeto con las nuevas configuraciones a aplicar
   * @param {Object} [newConfig.intervals] - Nuevos intervalos (merge profundo)
   * @param {Object} [newConfig.thresholds] - Nuevos umbrales (merge profundo)
   * @param {number} [newConfig.maxListenersPerEvent] - Nuevo límite de listeners por evento
   * @param {number} [newConfig.adjustmentDebounceMs] - Nuevo tiempo de debounce
   * @returns {boolean} true si la actualización fue exitosa, false si hubo errores
   * 
   * @example
   * // Actualizar intervalos
   * pollingManager.updateConfig({
   *   intervals: {
   *     normal: 20000,
   *     active: 3000
   *   }
   * });
   * 
   * @example
   * // Actualizar umbrales
   * pollingManager.updateConfig({
   *   thresholds: {
   *     latency_threshold: 2000,
   *     max_errors: 10
   *   }
   * });
   * 
   * @example
   * // Actualizar múltiples propiedades
   * pollingManager.updateConfig({
   *   maxListenersPerEvent: 150,
   *   adjustmentDebounceMs: 2000
   * });
   */
  updateConfig(newConfig) {
    if (!newConfig || typeof newConfig !== 'object') {
      // ✅ MEJORADO: Buscar ErrorHandler primero en window (para compatibilidad con tests)
      // y luego como variable global
      let errorHandler = null;
      if (typeof window !== 'undefined' && window.ErrorHandler) {
        errorHandler = window.ErrorHandler;
      } else if (typeof ErrorHandler !== 'undefined' && ErrorHandler) {
        errorHandler = ErrorHandler;
      }
      
      if (errorHandler && typeof errorHandler.logError === 'function') {
        errorHandler.logError(
          'updateConfig: newConfig debe ser un objeto válido',
          'POLLING_CONFIG'
        );
      }
      return false;
    }
    
    try {
      // ✅ MEJORADO: Merge profundo para propiedades anidadas (intervals, thresholds)
      Object.keys(newConfig).forEach(key => {
        if (key === 'intervals' && typeof newConfig.intervals === 'object' && newConfig.intervals !== null) {
          // Merge profundo de intervals
          if (!this.config.intervals) {
            this.config.intervals = {};
          }
          Object.keys(newConfig.intervals).forEach(intervalKey => {
            if (Object.prototype.hasOwnProperty.call(this.config.intervals, intervalKey)) {
              const newValue = newConfig.intervals[intervalKey];
              if (typeof newValue === 'number' && newValue > 0) {
                this.config.intervals[intervalKey] = newValue;
              }
            }
          });
        } else if (key === 'thresholds' && typeof newConfig.thresholds === 'object' && newConfig.thresholds !== null) {
          // Merge profundo de thresholds
          if (!this.config.thresholds) {
            this.config.thresholds = {};
          }
          Object.keys(newConfig.thresholds).forEach(thresholdKey => {
            if (Object.prototype.hasOwnProperty.call(this.config.thresholds, thresholdKey)) {
              const newValue = newConfig.thresholds[thresholdKey];
              if (typeof newValue === 'number' && newValue >= 0) {
                this.config.thresholds[thresholdKey] = newValue;
              }
            }
          });
        } else if (Object.prototype.hasOwnProperty.call(this.config, key)) {
          // Actualizar propiedades simples
          const newValue = newConfig[key];
          const currentValue = this.config[key];
          
          // Validación de tipos según la propiedad
          if (key === 'maxListenersPerEvent' || key === 'adjustmentDebounceMs') {
            if (typeof newValue === 'number' && newValue > 0) {
              this.config[key] = newValue;
            }
          } else if (key === 'currentInterval') {
            if (typeof newValue === 'number' && newValue > 0) {
              this.config[key] = newValue;
            }
          } else if (key === 'currentMode') {
            if (typeof newValue === 'string') {
              this.config[key] = newValue;
            }
          } else if (typeof newValue === typeof currentValue) {
            // Solo actualizar si el tipo coincide
            this.config[key] = newValue;
          }
        }
      });
      
      // ✅ MEJORADO: Ajustar polling con nueva configuración si hay polling activo
      if (this.isPollingActive()) {
        this.adjustPolling(this.config.lastProgress, true);
      }
      
      // Emitir evento de actualización de configuración
      this.emit('configUpdated', {
        config: { ...this.config },
        timestamp: Date.now()
      });
      
      return true;
    } catch (error) {
      if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
        ErrorHandler.logError(
          `Error actualizando configuración: ${error.message || error}`,
          'POLLING_CONFIG'
        );
      }
      return false;
    }
  }

  /**
   * Resetear configuración
   * 
   * ✅ MEJORADO: Ahora resetea también métricas de latencia, backoff, timer de debounce y métricas de rendimiento
   * 
   * @returns {void}
   * 
   * @example
   * pollingManager.reset();
   */
  reset() {
    this.stopAllPolling();
    
    // ✅ MEJORADO: Limpiar timer de debounce si existe
    if (this.adjustmentDebounceTimer) {
      clearTimeout(this.adjustmentDebounceTimer);
      this.adjustmentDebounceTimer = null;
    }
    
    this.config.currentInterval = this.config.intervals.normal;
    this.config.currentMode = 'normal';
    this.config.errorCount = 0;
    this.config.consecutiveErrors = 0;
    this.config.lastProgress = 0;
    this.config.progressStagnantCount = 0;
    this.config.lastResponseTime = null;
    this.config.averageLatency = null;
    this.config.backoffMultiplier = 1;
    this.config.userActive = true;
    this.config.lastUserActivity = Date.now();
    this.counters.inactive = 0;
    this.counters.lastProgress = 0;
    
    // ✅ NUEVO: Resetear estado de visibilidad de página
    this.pageWasHidden = false;
    
    // ✅ NUEVO: Resetear métricas de rendimiento
    this.metrics.totalRequests = 0;
    this.metrics.successfulRequests = 0;
    this.metrics.failedRequests = 0;
    this.metrics.averageResponseTime = 0;
    this.metrics.uptime = Date.now();
  }
}

/* ---------------------------------- */
/* 1. Instanciar el PollingManager   */
/* ---------------------------------- */
/* Instancia global del PollingManager (para los tests) */
const pollingManager = new PollingManager();

/* ---------------------------------- */
/* 2. Exponer la clase y la instancia */
/* ---------------------------------- */
if (typeof window !== 'undefined') {
  // 1. Exponer la clase primero
  window.PollingManager = PollingManager;
  
  // 2. Crear una instancia limpia
  const createInstance = () => {
    try {
      return new PollingManager();
    } catch (e) {
      // En caso de error, crear un objeto con la estructura mínima requerida
      const instance = Object.create(PollingManager.prototype);
      
      // Propiedades requeridas
      instance.intervals = new Map();
      instance.eventListeners = new Map();
      instance.config = {
        intervals: {
          normal: 15000,
          active: 2000,
          fast: 1000,
          slow: 45000,
          idle: 120000,
          min: 500,
          max: 300000
        },
        thresholds: {
          to_slow: 3,
          to_idle: 8,
          max_errors: 5,
          progress_threshold: 0.1,
          latency_threshold: 1000,
          error_backoff_base: 2,
          error_backoff_max: 60000,
          consecutive_errors_threshold: 3
        },
        maxListenersPerEvent: 100,
        adjustmentDebounceMs: 1000,
        currentInterval: 10000,
        currentMode: 'normal',
        errorCount: 0,
        consecutiveErrors: 0,
        lastProgress: 0,
        progressStagnantCount: 0,
        lastResponseTime: null,
        averageLatency: null,
        backoffMultiplier: 1,
        userActive: true,
        lastUserActivity: Date.now()
      };
      instance.counters = { inactive: 0, lastProgress: 0 };
      instance.metrics = {
        totalRequests: 0,
        successfulRequests: 0,
        failedRequests: 0,
        averageResponseTime: 0,
        uptime: Date.now()
      };
      
      return instance;
    }
  };
  
  // 3. Crear y exponer la instancia
  try {
    // Crear una nueva instancia
    const instance = createInstance();
    
    // Asegurarse de que todos los métodos estén disponibles
    Object.getOwnPropertyNames(PollingManager.prototype)
      .filter(prop => prop !== 'constructor' && typeof PollingManager.prototype[prop] === 'function')
      .forEach(method => {
        if (typeof instance[method] !== 'function') {
          instance[method] = PollingManager.prototype[method].bind(instance);
        }
      });
    
    // Exponer la instancia
    window.pollingManager = instance;
    
  } catch (e) {
    // En caso de error crítico, exponer un objeto con la estructura mínima
    window.pollingManager = {
      intervals: new Map(),
      eventListeners: new Map(),
      config: {},
      counters: { inactive: 0, lastProgress: 0 },
      metrics: {
        totalRequests: 0,
        successfulRequests: 0,
        failedRequests: 0,
        averageResponseTime: 0,
        uptime: Date.now()
      },
      isTestMode: false,
      testHooks: {},
      // Métodos básicos
      on() {},
      off() {},
      startPolling() {},
      stopPolling() {},
      getMetrics() { return {}; },
      getPollingStatus() { return {}; },
      updateConfig() { return false; },
      waitForNextTick() { return Promise.reject(new Error('Not available')); },
      getPollingInfo() { return null; },
      triggerPollingCallback() { return Promise.reject(new Error('Not available')); }
    };
  }
}

/* ---------------------------------- */
/* 3. Exportar para entornos no navegador (Node.js, etc.) */
/* ---------------------------------- */
/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { PollingManager, pollingManager };
}
