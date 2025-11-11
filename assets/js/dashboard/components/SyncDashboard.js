/**
 * Dashboard de Sincronización en Dos Fases
 *
 * Gestiona la interfaz de usuario para el dashboard de sincronización en dos fases,
 * incluyendo controles de inicio/pausa, visualización de progreso, y actualización
 * de estadísticas en tiempo real.
 *
 * @module components/SyncDashboard
 * @class SyncDashboard
 * @since 1.0.0
 * @author Christian
 */

/* global jQuery, miIntegracionApiDashboard, DASHBOARD_CONFIG, AjaxManager, pollingManager, checkSyncProgress, addConsoleLine, ajaxurl, ToastManager */

/**
 * Clase SyncDashboard para gestionar el dashboard de dos fases
 *
 * @class SyncDashboard
 * @description Gestión del dashboard de sincronización en dos fases
 */
class SyncDashboard {
  /**
   * Constructor de SyncDashboard
   *
   * @constructor
   * @description Inicializa el dashboard y carga el estado actual
   */
  constructor() {
    this.phase1Timer = null;
    this.phase2Timer = null;
    this.phase1StartTime = null;
    this.phase2StartTime = null;
    this.initializeEventListeners();
    this.loadCurrentStatus();
  }

  /**
   * Inicializa los event listeners para los controles del dashboard
   *
   * @returns {void}
   * @private
   */
  initializeEventListeners() {
    // Fase 1
    jQuery('#start-phase1').on('click', () => this.startPhase1());
    jQuery('#cancel-phase1').on('click', () => this.cancelPhase1());

    // Fase 2
    jQuery('#start-phase2').on('click', () => this.startPhase2());
    jQuery('#pause-phase2').on('click', () => this.pausePhase2());

    // ✅ NUEVO: Botón de cancelar sincronización
    jQuery('#mi-cancel-sync').on('click', () => this.cancelSync());

    // Configuración
    jQuery('#batch-size').on('change', (e) => this.updateConfig('batch_size', e.target.value));
    jQuery('#throttle-delay').on('change', (e) => this.updateConfig('throttle_delay', e.target.value));
    jQuery('#auto-retry').on('change', (e) => this.updateConfig('auto_retry', e.target.checked));
  }

  /**
   * Inicia la Fase 1: Sincronización de imágenes
   *
   * @returns {Promise<void>}
   * @async
   */
  async startPhase1() {
    this.updatePhaseStatus(1, 'running');
    this.disableButton('start-phase1');
    this.enableButton('cancel-phase1');
    this.phase1StartTime = Date.now();
    this.startTimer(1);

    if (typeof addConsoleLine === 'function') {
      addConsoleLine('phase1', 'Iniciando Fase 1: Sincronización de imágenes...');
    }

    try {
      // ✅ CORRECCIÓN: Verificar que ajaxurl esté disponible
      const ajaxUrl = (typeof ajaxurl !== 'undefined' && ajaxurl) 
        ? ajaxurl 
        : (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard && miIntegracionApiDashboard.ajaxurl)
          ? miIntegracionApiDashboard.ajaxurl
          : null;

      if (!ajaxUrl) {
        throw new Error('ajaxurl no está disponible');
      }

      // ✅ CORRECCIÓN: Obtener batch size del selector o usar valor por defecto
      const $batchSize = jQuery('#batch-size');
      const batchSize = ($batchSize.length && $batchSize.val()) 
        ? parseInt($batchSize.val(), 10) || 50
        : 50;

      const response = await jQuery.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: {
          action: 'mia_sync_images',
          nonce: miIntegracionApiDashboard.nonce,
          resume: false,
          batch_size: batchSize
        }
      });

      if (response.success) {
        if (typeof addConsoleLine === 'function') {
          addConsoleLine('success', 'Fase 1 iniciada correctamente');
        }
        
        // ✅ NUEVO: Emitir evento inmediato cuando se inicia la sincronización
        // Esto permite que ConsoleManager muestre el mensaje de inicio inmediatamente
        if (typeof window !== 'undefined' && window.pollingManager && typeof window.pollingManager.emit === 'function') {
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
            phase1Status: phase1Status,
            timestamp: Date.now()
          });
          // eslint-disable-next-line no-console
          console.log('[SyncDashboard] ✅ Evento syncProgress emitido inmediatamente al iniciar Fase 1');
        }
        
        // ✅ NUEVO: Iniciar polling de Phase1Manager para monitorear completitud
        // Esto asegura que checkPhase1Complete() se ejecute y actualice la consola
        if (typeof window !== 'undefined' && window.Phase1Manager) {
          // Verificar si el polling ya está activo
          if (typeof window.Phase1Manager.getPollingInterval === 'function') {
            const existingInterval = window.Phase1Manager.getPollingInterval();
            if (!existingInterval && typeof window.Phase1Manager.startPolling === 'function') {
              // Si no hay polling activo, iniciarlo manualmente
              // Esto asegura que checkPhase1Complete() se ejecute y actualice la consola
              window.Phase1Manager.startPolling();
            }
          }
        }
        
        this.handlePhase1Response(response.data);
      } else {
        const errorMsg = (response.data && response.data.message) || 'Error desconocido';
        if (typeof addConsoleLine === 'function') {
          addConsoleLine('error', 'Error iniciando Fase 1: ' + errorMsg);
        }
        this.updatePhaseStatus(1, 'error');
        this.enableButton('start-phase1');
      }
    } catch (error) {
      if (typeof addConsoleLine === 'function') {
        addConsoleLine('error', 'Error iniciando Fase 1: ' + (error.message || 'Error de comunicación'));
      }
      this.updatePhaseStatus(1, 'error');
      this.enableButton('start-phase1');
    }
  }

  /**
   * Inicia la Fase 2: Sincronización de productos
   *
   * @returns {Promise<void>}
   * @async
   */
  async startPhase2() {
    // ✅ MEJORADO: Validar condiciones antes de iniciar (pero no bloquear el botón)
    // Obtener estado actual de sincronización
    let phase1Status = null;
    let currentStatus = null;
    
    try {
      // Obtener estado actual para validar condiciones
      const statusResponse = await jQuery.ajax({
        url: (typeof ajaxurl !== 'undefined' && ajaxurl) 
          ? ajaxurl 
          : (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard && miIntegracionApiDashboard.ajaxurl)
            ? miIntegracionApiDashboard.ajaxurl
            : null,
        method: 'POST',
        data: {
          action: 'mia_get_sync_progress',
          nonce: miIntegracionApiDashboard.nonce
        }
      });
      
      if (statusResponse.success && statusResponse.data) {
        phase1Status = statusResponse.data.phase1_images || {};
        currentStatus = statusResponse.data;
      }
    } catch (error) {
      // Si falla obtener el estado, continuar de todos modos
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo obtener el estado actual, continuando...', error);
      }
    }
    
    // ✅ NUEVO: Validar condiciones y mostrar advertencias si es necesario
    const phase1Completed = phase1Status && phase1Status.completed === true;
    const phase1InProgress = phase1Status && phase1Status.in_progress === true;
    const inProgress = currentStatus && currentStatus.in_progress === true;
    
    // Advertencia si Fase 1 no está completada
    if (!phase1Completed && !phase1InProgress) {
      const message = typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard && miIntegracionApiDashboard.warningPhase2WithoutPhase1
        ? miIntegracionApiDashboard.warningPhase2WithoutPhase1
        : '⚠️ Advertencia: La Fase 1 (sincronización de imágenes) no se ha completado. Se recomienda completar la Fase 1 primero para obtener mejores resultados. ¿Deseas continuar de todos modos?';
      
      if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
        ToastManager.show('Se recomienda completar la Fase 1 primero', 'warning', 5000);
      }
      
      if (typeof addConsoleLine === 'function') {
        addConsoleLine('warning', 'Advertencia: Iniciando Fase 2 sin completar Fase 1');
      }
      
      // Preguntar confirmación al usuario
      const confirmed = confirm(message);
      if (!confirmed) {
        return; // El usuario canceló
      }
    }
    
    // Advertencia si ya hay una sincronización en progreso
    if (inProgress) {
      const message = typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard && miIntegracionApiDashboard.warningPhase2InProgress
        ? miIntegracionApiDashboard.warningPhase2InProgress
        : 'Ya hay una sincronización en progreso. ¿Deseas continuar de todos modos?';
      
      if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
        ToastManager.show('Ya hay una sincronización en progreso', 'warning', 5000);
      }
      
      const confirmed = confirm(message);
      if (!confirmed) {
        return; // El usuario canceló
      }
    }
    
    // Si el usuario confirma, proceder con la Fase 2
    this.updatePhaseStatus(2, 'running');
    this.disableButton('start-phase2');
    this.phase2StartTime = Date.now();
    this.startTimer(2);
    
    // ✅ NUEVO: Mostrar botón de cancelar cuando inicia la Fase 2
    this.enableButton('mi-cancel-sync');
    jQuery('#mi-cancel-sync').show();

    if (typeof addConsoleLine === 'function') {
      addConsoleLine('phase2', 'Iniciando Fase 2: Sincronización de productos...');
    }

    try {
      // ✅ CORRECCIÓN: Verificar que ajaxurl esté disponible
      const ajaxUrl = (typeof ajaxurl !== 'undefined' && ajaxurl) 
        ? ajaxurl 
        : (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard && miIntegracionApiDashboard.ajaxurl)
          ? miIntegracionApiDashboard.ajaxurl
          : null;

      if (!ajaxUrl) {
        throw new Error('ajaxurl no está disponible');
      }

      // ✅ CORRECCIÓN: Obtener batch size del selector o usar valor por defecto
      const $batchSize = jQuery('#batch-size');
      const batchSize = ($batchSize.length && $batchSize.val()) 
        ? parseInt($batchSize.val(), 10) || 50
        : 50;

      const response = await jQuery.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: {
          action: 'mi_integracion_api_sync_products_batch',
          nonce: miIntegracionApiDashboard.nonce,
          batch_size: batchSize
        }
      });

      if (response.success) {
        if (typeof addConsoleLine === 'function') {
          addConsoleLine('success', 'Fase 2 iniciada correctamente');
        }
        // Iniciar polling para monitorear Fase 2
        if (typeof pollingManager !== 'undefined' && pollingManager) {
          pollingManager.config.currentInterval = pollingManager.config.intervals.active;
          pollingManager.config.currentMode = 'active';
          if (typeof pollingManager.startPolling === 'function' && typeof checkSyncProgress === 'function') {
            const intervalId = pollingManager.startPolling('syncProgress', checkSyncProgress, pollingManager.config.currentInterval);
            // Exponer syncInterval en window si existe (compatibilidad con código original)
            if (typeof window !== 'undefined') {
              try {
                window.syncInterval = intervalId;
              } catch (error) {
                // Ignorar si no se puede asignar
              }
            }
          }
        }
      } else {
        const errorMsg = (response.data && response.data.message) || 'Error desconocido';
        if (typeof addConsoleLine === 'function') {
          addConsoleLine('error', 'Error iniciando Fase 2: ' + errorMsg);
        }
        this.updatePhaseStatus(2, 'error');
        this.enableButton('start-phase2');
      }
    } catch (error) {
      if (typeof addConsoleLine === 'function') {
        addConsoleLine('error', 'Error iniciando Fase 2: ' + (error.message || 'Error de comunicación'));
      }
      this.updatePhaseStatus(2, 'error');
      this.enableButton('start-phase2');
    }
  }

  /**
   * Actualiza las estadísticas de la Fase 1
   *
   * @param {Object} data - Datos de progreso de la Fase 1
   * @param {number} data.total_products - Total de productos
   * @param {number} data.products_processed - Productos procesados
   * @param {number} data.images_processed - Imágenes procesadas
   * @param {number} data.duplicates_skipped - Duplicados omitidos
   * @param {number} data.errors - Errores
   * @returns {void}
   */
  updatePhase1Progress(data) {
    // ✅ MEJORADO: Asegurar que los valores sean números válidos
    const productsProcessed = Number(data.products_processed) || 0;
    const imagesProcessed = Number(data.images_processed) || 0;
    const duplicatesSkipped = Number(data.duplicates_skipped) || 0;
    const errors = Number(data.errors || data.errores) || 0; // ✅ CORRECCIÓN: Manejar ambos nombres

    // Actualizar estadísticas
    jQuery('#phase1-products').text(productsProcessed);
    jQuery('#phase1-images').text(imagesProcessed);
    jQuery('#phase1-duplicates').text(duplicatesSkipped);
    jQuery('#phase1-errors').text(errors);

    // Actualizar velocidad
    if (this.phase1StartTime) {
      const elapsedSeconds = (Date.now() - this.phase1StartTime) / 1000;
      const speed = elapsedSeconds > 0
        ? (productsProcessed / elapsedSeconds).toFixed(2)
        : 0;
      jQuery('#phase1-speed').text(speed + ' ' + ((DASHBOARD_CONFIG && DASHBOARD_CONFIG.messages && DASHBOARD_CONFIG.messages.progress && DASHBOARD_CONFIG.messages.progress.productsPerSec) || 'productos/seg'));
    }
  }

  /**
   * Actualiza las estadísticas de la Fase 2
   *
   * @param {Object} data - Datos de progreso de la Fase 2
   * @param {Object} data.estadisticas - Estadísticas de sincronización
   * @param {number} data.estadisticas.procesados - Productos procesados
   * @param {number} data.estadisticas.total - Total de productos
   * @param {number} data.estadisticas.errores - Errores
   * @param {number} data.estadisticas.creados - Productos creados (opcional)
   * @param {number} data.estadisticas.actualizados - Productos actualizados (opcional)
   * @returns {void}
   */
  updatePhase2Progress(data) {
    // ✅ MEJORADO: Asegurar que los datos estén correctamente estructurados
    const stats = data.estadisticas || {};
    const processed = Number(stats.procesados) || 0;

    // Actualizar estadísticas
    jQuery('#phase2-products').text(processed);
    jQuery('#phase2-errors').text(Number(stats.errores) || 0);
    // ✅ CORRECCIÓN: Actualizar campos de creados y actualizados si están disponibles
    if (stats.creados !== undefined) {
      jQuery('#phase2-created').text(Number(stats.creados) || 0);
    }
    if (stats.actualizados !== undefined) {
      jQuery('#phase2-updated').text(Number(stats.actualizados) || 0);
    }

    // Actualizar velocidad
    // ✅ CORRECCIÓN: Solo calcular velocidad si hay sincronización activa y tiempo válido
    if (this.phase2StartTime && processed > 0) {
      const elapsedSeconds = (Date.now() - this.phase2StartTime) / 1000;
      // ✅ CORRECCIÓN: Validar que el tiempo transcurrido sea razonable (mínimo 1 segundo)
      if (elapsedSeconds >= 1) {
        const speed = (processed / elapsedSeconds).toFixed(2);
        jQuery('#phase2-speed').text(speed + ' ' + ((DASHBOARD_CONFIG && DASHBOARD_CONFIG.messages && DASHBOARD_CONFIG.messages.progress && DASHBOARD_CONFIG.messages.progress.productsPerSec) || 'productos/seg'));
      } else {
        // Si el tiempo es muy corto, mostrar 0 para evitar valores imposibles
        jQuery('#phase2-speed').text('0 ' + ((DASHBOARD_CONFIG && DASHBOARD_CONFIG.messages && DASHBOARD_CONFIG.messages.progress && DASHBOARD_CONFIG.messages.progress.productsPerSec) || 'productos/seg'));
      }
    } else {
      // Si no hay sincronización activa, mostrar 0
      jQuery('#phase2-speed').text('0 ' + ((DASHBOARD_CONFIG && DASHBOARD_CONFIG.messages && DASHBOARD_CONFIG.messages.progress && DASHBOARD_CONFIG.messages.progress.productsPerSec) || 'productos/seg'));
    }
  }

  /**
   * Resetea las estadísticas de la Fase 1 a valores iniciales
   *
   * @returns {void}
   * @private
   */
  resetPhase1Progress() {
    // Resetear estadísticas
    jQuery('#phase1-products').text('0');
    jQuery('#phase1-images').text('0');
    jQuery('#phase1-duplicates').text('0');
    jQuery('#phase1-errors').text('0');

    // Resetear velocidad y timer
    jQuery('#phase1-speed').text('0 ' + ((DASHBOARD_CONFIG && DASHBOARD_CONFIG.messages && DASHBOARD_CONFIG.messages.progress && DASHBOARD_CONFIG.messages.progress.productsPerSec) || 'productos/seg'));
    jQuery('#phase1-timer').text('00:00:00');

    // Resetear tiempo de inicio
    this.phase1StartTime = null;
  }

  /**
   * Resetea las estadísticas de la Fase 2 a valores iniciales
   *
   * @returns {void}
   * @private
   */
  resetPhase2Progress() {
    // Resetear estadísticas
    jQuery('#phase2-products').text('0');
    jQuery('#phase2-errors').text('0');
    // ✅ CORRECCIÓN: Resetear también campos de creados y actualizados
    jQuery('#phase2-created').text('0');
    jQuery('#phase2-updated').text('0');

    // Resetear velocidad y timer
    jQuery('#phase2-speed').text('0 ' + ((DASHBOARD_CONFIG && DASHBOARD_CONFIG.messages && DASHBOARD_CONFIG.messages.progress && DASHBOARD_CONFIG.messages.progress.productsPerSec) || 'productos/seg'));
    jQuery('#phase2-timer').text('00:00:00');

    // Resetear tiempo de inicio
    this.phase2StartTime = null;
  }

  /**
   * Actualiza el estado visual de una fase
   *
   * @param {number} phase - Número de fase (1 o 2)
   * @param {string} status - Estado de la fase (pending, running, completed, error, paused)
   * @returns {void}
   */
  updatePhaseStatus(phase, status) {
    const $statusElement = jQuery(`#phase${phase}-status`);
    $statusElement.removeClass('phase-status-pending phase-status-running phase-status-completed phase-status-error phase-status-paused');
    $statusElement.addClass(`phase-status-${status}`);

    const getStatusMessage = function(key, defaultValue) {
      if (DASHBOARD_CONFIG && DASHBOARD_CONFIG.messages && DASHBOARD_CONFIG.messages.status && DASHBOARD_CONFIG.messages.status[key]) {
        return DASHBOARD_CONFIG.messages.status[key];
      }
      return defaultValue;
    };

    const statusText = {
      'pending': '⏳ ' + getStatusMessage('pending', 'Pendiente'),
      'running': '🔄 ' + getStatusMessage('running', 'En Progreso'),
      'completed': '✅ ' + getStatusMessage('completed', 'Completado'),
      'error': '❌ ' + getStatusMessage('error', 'Error'),
      'paused': '⏸ ' + getStatusMessage('paused', 'Pausado')
    };

    $statusElement.text(statusText[status] || statusText['pending']);
  }

  /**
   * Inicia el timer para una fase
   *
   * @param {number} phase - Número de fase (1 o 2)
   * @returns {void}
   */
  startTimer(phase) {
    const timerElement = jQuery(`#phase${phase}-timer`);
    let seconds = 0;

    const timer = setInterval(() => {
      seconds++;
      const hours = Math.floor(seconds / 3600);
      const minutes = Math.floor((seconds % 3600) / 60);
      const secs = seconds % 60;

      timerElement.text(
        `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
      );
    }, 1000);

    if (phase === 1) {
      this.phase1Timer = timer;
    } else {
      this.phase2Timer = timer;
    }
  }

  /**
   * Detiene el timer de una fase
   *
   * @param {number} phase - Número de fase (1 o 2)
   * @returns {void}
   */
  stopTimer(phase) {
    if (phase === 1 && this.phase1Timer) {
      clearInterval(this.phase1Timer);
      this.phase1Timer = null;
    } else if (phase === 2 && this.phase2Timer) {
      clearInterval(this.phase2Timer);
      this.phase2Timer = null;
    }
  }

  /**
   * Cancela la Fase 1
   *
   * @returns {Promise<void>}
   * @async
   */
  async cancelPhase1() {
    const ajaxUrl = (typeof ajaxurl !== 'undefined' && ajaxurl) 
      ? ajaxurl 
      : (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard && miIntegracionApiDashboard.ajaxurl)
        ? miIntegracionApiDashboard.ajaxurl
        : null;

    if (!ajaxUrl) {
      if (typeof addConsoleLine === 'function') {
        addConsoleLine('error', 'Error: ajaxurl no está disponible');
      }
      return;
    }

    // Confirmar cancelación
    if (!confirm('¿Estás seguro de que deseas cancelar la sincronización de imágenes? El progreso actual se perderá.')) {
      return;
    }

    try {
      const response = await jQuery.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: {
          action: 'mia_cancel_images_sync',
          nonce: miIntegracionApiDashboard.nonce
        }
      });

      if (response.success) {
        this.updatePhaseStatus(1, 'cancelled');
        this.stopTimer(1);
        this.disableButton('cancel-phase1');
        this.enableButton('start-phase1');
        if (typeof addConsoleLine === 'function') {
          addConsoleLine('warning', 'Fase 1 cancelada');
        }
        if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
          ToastManager.show('Fase 1 cancelada correctamente', 'success', 2000);
        }
      } else {
        const errorMsg = (response.data && response.data.message) || 'Error desconocido';
        if (typeof addConsoleLine === 'function') {
          addConsoleLine('error', 'Error al cancelar Fase 1: ' + errorMsg);
        }
        if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
          ToastManager.show('Error al cancelar Fase 1: ' + errorMsg, 'error', 3000);
        }
      }
    } catch (error) {
      if (typeof addConsoleLine === 'function') {
        addConsoleLine('error', 'Error al cancelar Fase 1: ' + (error.message || 'Error de comunicación'));
      }
      if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
        ToastManager.show('Error al cancelar Fase 1: ' + (error.message || 'Error de comunicación'), 'error', 3000);
      }
    }
  }

  /**
   * Reanuda la Fase 1 (DEPRECADO - Ya no se usa, se reemplazó por cancelar)
   *
   * @returns {Promise<void>}
   * @async
   * @deprecated Este método ya no se usa. La Fase 1 ahora solo se puede cancelar, no pausar/reanudar.
   */
  async resumePhase1() {
    const ajaxUrl = (typeof ajaxurl !== 'undefined' && ajaxurl) 
      ? ajaxurl 
      : (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard && miIntegracionApiDashboard.ajaxurl)
        ? miIntegracionApiDashboard.ajaxurl
        : null;

    if (!ajaxUrl) {
      if (typeof addConsoleLine === 'function') {
        addConsoleLine('error', 'Error: ajaxurl no está disponible');
      }
      return;
    }

    try {
      // Obtener batch size del selector
      const $batchSize = jQuery('#batch-size');
      const batchSize = ($batchSize.length && $batchSize.val()) 
        ? parseInt($batchSize.val(), 10) || 50
        : 50;

      const response = await jQuery.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: {
          action: 'mia_resume_images_sync',
          nonce: miIntegracionApiDashboard.nonce,
          batch_size: batchSize
        }
      });

      if (response.success) {
        // ✅ CORRECCIÓN: Llamar al endpoint de sincronización para reanudar realmente
        const $batchSize = jQuery('#batch-size');
        const resumeBatchSize = ($batchSize.length && $batchSize.val()) 
          ? parseInt($batchSize.val(), 10) || 50
          : 50;

        // Llamar al endpoint de sincronización con resume=true
        jQuery.ajax({
          url: ajaxUrl,
          method: 'POST',
          data: {
            action: 'mia_sync_images',
            nonce: miIntegracionApiDashboard.nonce,
            resume: true,
            batch_size: resumeBatchSize
          },
          success: (syncResponse) => {
            if (syncResponse.success) {
              this.updatePhaseStatus(1, 'running');
              this.phase1StartTime = Date.now() - (this.phase1StartTime ? (Date.now() - this.phase1StartTime) : 0);
              this.startTimer(1);
              this.disableButton('start-phase1');
              this.enableButton('cancel-phase1');
              if (typeof addConsoleLine === 'function') {
                addConsoleLine('info', 'Fase 1 reanudada');
              }
              if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
                ToastManager.show('Fase 1 reanudada correctamente', 'success', 2000);
              }
              // Iniciar polling si no está activo
              if (typeof pollingManager !== 'undefined' && pollingManager) {
                pollingManager.config.currentInterval = pollingManager.config.intervals.active;
                pollingManager.config.currentMode = 'active';
                if (typeof pollingManager.startPolling === 'function' && typeof checkSyncProgress === 'function') {
                  const intervalId = pollingManager.startPolling('syncProgress', checkSyncProgress, pollingManager.config.currentInterval);
                  if (typeof window !== 'undefined') {
                    try {
                      window.syncInterval = intervalId;
                    } catch (error) {
                      // Ignorar si no se puede asignar
                    }
                  }
                }
              }
            } else {
              const errorMsg = (syncResponse.data && syncResponse.data.message) || 'Error desconocido';
              if (typeof addConsoleLine === 'function') {
                addConsoleLine('error', 'Error al reanudar Fase 1: ' + errorMsg);
              }
              if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
                ToastManager.show('Error al reanudar Fase 1: ' + errorMsg, 'error', 3000);
              }
            }
          },
          error: (xhr, status, error) => {
            const errorMsg = error || 'Error de conexión';
            if (typeof addConsoleLine === 'function') {
              addConsoleLine('error', 'Error al reanudar Fase 1: ' + errorMsg);
            }
            if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
              ToastManager.show('Error al reanudar Fase 1: ' + errorMsg, 'error', 3000);
            }
          }
        });
      } else {
        const errorMsg = (response.data && response.data.message) || 'Error desconocido';
        if (typeof addConsoleLine === 'function') {
          addConsoleLine('error', 'Error al reanudar Fase 1: ' + errorMsg);
        }
        if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
          ToastManager.show('Error al reanudar Fase 1: ' + errorMsg, 'error', 3000);
        }
      }
    } catch (error) {
      if (typeof addConsoleLine === 'function') {
        addConsoleLine('error', 'Error al reanudar Fase 1: ' + (error.message || 'Error de comunicación'));
      }
      if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
        ToastManager.show('Error al reanudar Fase 1: ' + (error.message || 'Error de comunicación'), 'error', 3000);
      }
    }
  }

  /**
   * Pausa la Fase 2
   *
   * @returns {void}
   */
  pausePhase2() {
    this.updatePhaseStatus(2, 'paused');
    this.stopTimer(2);
    if (typeof addConsoleLine === 'function') {
      addConsoleLine('warning', 'Fase 2 pausada');
    }
  }

  /**
   * Cancela la sincronización en curso (Fase 2)
   *
   * @returns {Promise<void>}
   * @async
   */
  async cancelSync() {
    // ✅ NUEVO: Confirmar cancelación
    const confirmMessage = typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard && miIntegracionApiDashboard.confirmCancel
      ? miIntegracionApiDashboard.confirmCancel
      : '¿Seguro que deseas cancelar la sincronización?';
    
    if (!confirm(confirmMessage)) {
      return; // El usuario canceló
    }

    if (typeof addConsoleLine === 'function') {
      addConsoleLine('warning', 'Cancelando sincronización...');
    }

    if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
      ToastManager.show('Cancelando sincronización...', 'info', 2000);
    }

    try {
      // ✅ NUEVO: Verificar que ajaxurl esté disponible
      const ajaxUrl = (typeof ajaxurl !== 'undefined' && ajaxurl) 
        ? ajaxurl 
        : (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard && miIntegracionApiDashboard.ajaxurl)
          ? miIntegracionApiDashboard.ajaxurl
          : null;

      if (!ajaxUrl) {
        throw new Error('No se pudo obtener la URL de AJAX');
      }

      // ✅ NUEVO: Llamar al endpoint de cancelación
      const response = await jQuery.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: {
          action: 'mia_sync_cancel',
          nonce: miIntegracionApiDashboard.nonce
        }
      });

      if (response.success) {
        // ✅ NUEVO: Actualizar estado del dashboard
        this.updatePhaseStatus(2, 'pending');
        this.stopTimer(2);
        this.resetPhase2Progress();
        this.disableButton('mi-cancel-sync');
        jQuery('#mi-cancel-sync').hide();
        this.enableButton('start-phase2');

        if (typeof addConsoleLine === 'function') {
          addConsoleLine('info', 'Sincronización cancelada correctamente');
        }

        if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
          ToastManager.show('Sincronización cancelada correctamente', 'success', 3000);
        }

        // ✅ NUEVO: Recargar estado para asegurar sincronización
        await this.loadCurrentState();
      } else {
        const errorMsg = response.data && response.data.message 
          ? response.data.message 
          : 'Error al cancelar la sincronización';
        
        if (typeof addConsoleLine === 'function') {
          addConsoleLine('error', 'Error cancelando sincronización: ' + errorMsg);
        }

        if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
          ToastManager.show('Error al cancelar: ' + errorMsg, 'error', 5000);
        }
      }
    } catch (error) {
      if (typeof addConsoleLine === 'function') {
        addConsoleLine('error', 'Error cancelando sincronización: ' + (error.message || 'Error de comunicación'));
      }

      if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
        ToastManager.show('Error al cancelar: ' + (error.message || 'Error de comunicación'), 'error', 5000);
      }
    }
  }

  /**
   * Habilita un botón
   *
   * @param {string} id - ID del botón
   * @returns {void}
   */
  enableButton(id) {
    const $button = jQuery('#' + id);
    if ($button.length) {
      $button.prop('disabled', false);
      $button.show();
    }
  }

  /**
   * Deshabilita un botón
   *
   * @param {string} id - ID del botón
   * @returns {void}
   */
  disableButton(id) {
    const $button = jQuery('#' + id);
    if ($button.length) {
      $button.prop('disabled', true);
    }
  }

  /**
   * Actualiza la configuración del dashboard
   *
   * @param {string} key - Clave de configuración
   * @param {string|boolean} value - Valor de configuración
   * @returns {void}
   */
  updateConfig(key, value) {
    // ✅ CORRECCIÓN: Verificar que ajaxurl esté disponible
    const ajaxUrl = (typeof ajaxurl !== 'undefined' && ajaxurl) 
      ? ajaxurl 
      : (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard && miIntegracionApiDashboard.ajaxurl)
        ? miIntegracionApiDashboard.ajaxurl
        : null;

    if (!ajaxUrl) {
      if (typeof addConsoleLine === 'function') {
        addConsoleLine('error', 'Error: ajaxurl no está disponible');
      }
      return;
    }

    if (key === 'batch_size') {
      // ✅ CORRECCIÓN: Usar AjaxManager si está disponible, sino usar jQuery.ajax directamente
      if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
        AjaxManager.call('mi_integracion_api_save_batch_size', {
          entity: 'productos',
          batch_size: value
        }, function(response) {
          if (response.success) {
            if (typeof addConsoleLine === 'function') {
              addConsoleLine('info', 'Configuración de batch size actualizada: ' + value);
            }
            if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
              ToastManager.show('Tamaño de lote actualizado correctamente', 'success', 2000);
            }
          } else {
            const errorMsg = (response.data && response.data.message) || 'Error desconocido';
            if (typeof addConsoleLine === 'function') {
              addConsoleLine('error', 'Error al actualizar batch size: ' + errorMsg);
            }
            if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
              ToastManager.show('Error al actualizar tamaño de lote: ' + errorMsg, 'error', 3000);
            }
          }
        });
      } else {
        // Fallback: usar jQuery.ajax directamente
        jQuery.ajax({
          url: ajaxUrl,
          method: 'POST',
          data: {
            action: 'mi_integracion_api_save_batch_size',
            nonce: miIntegracionApiDashboard.nonce,
            entity: 'productos',
            batch_size: value
          },
          success: function(response) {
            if (response.success) {
              if (typeof addConsoleLine === 'function') {
                addConsoleLine('info', 'Configuración de batch size actualizada: ' + value);
              }
              if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
                ToastManager.show('Tamaño de lote actualizado correctamente', 'success', 2000);
              }
            } else {
              const errorMsg = (response.data && response.data.message) || 'Error desconocido';
              if (typeof addConsoleLine === 'function') {
                addConsoleLine('error', 'Error al actualizar batch size: ' + errorMsg);
              }
              if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
                ToastManager.show('Error al actualizar tamaño de lote: ' + errorMsg, 'error', 3000);
              }
            }
          },
          error: function(xhr, status, error) {
            const errorMsg = error || 'Error de conexión';
            if (typeof addConsoleLine === 'function') {
              addConsoleLine('error', 'Error al actualizar batch size: ' + errorMsg);
            }
            if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
              ToastManager.show('Error al actualizar tamaño de lote: ' + errorMsg, 'error', 3000);
            }
          }
        });
      }
    } else if (key === 'throttle_delay') {
      // ✅ CORRECCIÓN: Validar valor antes de enviar
      const delayMs = parseFloat(value);
      if (isNaN(delayMs) || delayMs < 0 || delayMs > 5000) {
        if (typeof addConsoleLine === 'function') {
          addConsoleLine('error', 'El delay de throttling debe estar entre 0 y 5000 ms');
        }
        if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
          ToastManager.show('El delay de throttling debe estar entre 0 y 5000 ms', 'error', 3000);
        }
        return;
      }

      const delaySeconds = delayMs / 1000;
      jQuery.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: {
          action: 'mia_update_throttle_delay',
          nonce: miIntegracionApiDashboard.nonce,
          delay: delaySeconds
        },
        success: function(response) {
          if (response.success) {
            if (typeof addConsoleLine === 'function') {
              addConsoleLine('info', 'Delay de throttling actualizado: ' + value + 'ms');
            }
            if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
              ToastManager.show('Delay de throttling actualizado correctamente', 'success', 2000);
            }
          } else {
            const errorMsg = (response.data && response.data.message) || 'Error desconocido';
            if (typeof addConsoleLine === 'function') {
              addConsoleLine('error', 'Error al actualizar throttle delay: ' + errorMsg);
            }
            if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
              ToastManager.show('Error al actualizar delay de throttling: ' + errorMsg, 'error', 3000);
            }
          }
        },
        error: function(xhr, status, error) {
          const errorMsg = error || 'Error de conexión';
          if (typeof addConsoleLine === 'function') {
            addConsoleLine('error', 'Error al actualizar throttle delay: ' + errorMsg);
          }
          if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
            ToastManager.show('Error al actualizar delay de throttling: ' + errorMsg, 'error', 3000);
          }
        }
      });
    } else if (key === 'auto_retry') {
      // ✅ CORRECCIÓN: Guardar configuración de reintento automático
      const autoRetryValue = value === true || value === 'true' || value === 1 || value === '1';
      jQuery.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: {
          action: 'mia_update_auto_retry',
          nonce: miIntegracionApiDashboard.nonce,
          auto_retry: autoRetryValue
        },
        success: function(response) {
          if (response.success) {
            if (typeof addConsoleLine === 'function') {
              addConsoleLine('info', 'Configuración de reintento automático actualizada: ' + (autoRetryValue ? 'Activado' : 'Desactivado'));
            }
            if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
              ToastManager.show(
                autoRetryValue ? 'Reintento automático activado' : 'Reintento automático desactivado',
                'success',
                2000
              );
            }
          } else {
            const errorMsg = (response.data && response.data.message) || 'Error desconocido';
            if (typeof addConsoleLine === 'function') {
              addConsoleLine('error', 'Error al actualizar auto retry: ' + errorMsg);
            }
            if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
              ToastManager.show('Error al actualizar reintento automático: ' + errorMsg, 'error', 3000);
            }
          }
        },
        error: function(xhr, status, error) {
          const errorMsg = error || 'Error de conexión';
          if (typeof addConsoleLine === 'function') {
            addConsoleLine('error', 'Error al actualizar auto retry: ' + errorMsg);
          }
          if (typeof ToastManager !== 'undefined' && ToastManager && typeof ToastManager.show === 'function') {
            ToastManager.show('Error al actualizar reintento automático: ' + errorMsg, 'error', 3000);
          }
        }
      });
    }
  }

  /**
   * Carga el estado actual de sincronización
   *
   * @returns {Promise<void>}
   * @async
   */
  async loadCurrentStatus() {
    try {
      const response = await jQuery.ajax({
        url: ajaxurl,
        method: 'POST',
        data: {
          action: 'mia_get_sync_progress',
          nonce: miIntegracionApiDashboard.nonce
        }
      });

      if (response.success && response.data) {
        this.updateDashboardFromStatus(response.data);
        
        // ✅ CORRECCIÓN: NO emitir eventos desde loadCurrentStatus si hay polling activo
        // loadCurrentStatus se llama al cargar la página, pero no debería emitir eventos
        // si Phase1Manager o SyncProgress ya están manejando el polling
        // Solo emitir si es la carga inicial y no hay polling activo
        const phase1Status = response.data.phase1_images || {};
        const phase1ManagerActive = typeof window !== 'undefined' && 
                                     window.Phase1Manager && 
                                     typeof window.Phase1Manager.getPollingInterval === 'function' &&
                                     window.Phase1Manager.getPollingInterval() !== null;
        const syncProgressActive = typeof window !== 'undefined' && 
                                    window.pollingManager && 
                                    window.pollingManager.config &&
                                    window.pollingManager.config.currentMode === 'active';
        
        // Solo emitir si NO hay polling activo (carga inicial de la página)
        if (!phase1ManagerActive && !syncProgressActive && typeof window !== 'undefined' && window.pollingManager && typeof window.pollingManager.emit === 'function') {
          window.pollingManager.emit('syncProgress', {
            syncData: response.data,
            phase1Status: phase1Status,
            timestamp: Date.now()
          });
          // eslint-disable-next-line no-console
          console.log('[SyncDashboard] ✅ Evento syncProgress emitido a través de PollingManager (carga inicial, sin polling activo)');
        } else if (phase1ManagerActive || syncProgressActive) {
          // eslint-disable-next-line no-console
          console.log('[SyncDashboard] ⏭️  Omitiendo emisión de evento (ya hay polling activo)');
        } else {
          // Fallback: Solo si no hay sistema de eventos disponible
          // eslint-disable-next-line no-console
          console.warn('[SyncDashboard] ⚠️  PollingManager no está disponible, usando fallback directo');
          if (typeof window !== 'undefined' && typeof window.updateSyncConsole === 'function') {
            window.updateSyncConsole(response.data, phase1Status);
          } else if (typeof window !== 'undefined' && window.ConsoleManager && typeof window.ConsoleManager.updateSyncConsole === 'function') {
            window.ConsoleManager.updateSyncConsole(response.data, phase1Status);
          }
        }
      }
    } catch (error) {
      // eslint-disable-next-line no-console
      console.error('Error cargando estado:', error);
    }
  }

  /**
   * Actualiza el dashboard desde los datos de estado
   *
   * @param {Object} data - Datos de estado de sincronización
   * @returns {void}
   */
  updateDashboardFromStatus(data) {
    // ✅ CORRECCIÓN: Validar que data existe y tiene la estructura correcta
    if (!data || typeof data !== 'object') {
      // Si no hay datos válidos, resetear todo a estado pendiente
      this.updatePhaseStatus(1, 'pending');
      this.updatePhaseStatus(2, 'pending');
      this.resetPhase1Progress();
      this.resetPhase2Progress();
      return;
    }

    // Actualizar estado de Fase 1
    const phase1Status = data.phase1_images || {};
    // ✅ ACTUALIZADO: Manejar estado cancelado
    if (phase1Status.cancelled || phase1Status.completed === false && !phase1Status.in_progress) {
      this.updatePhaseStatus(1, 'cancelled');
      this.stopTimer(1);
      this.disableButton('cancel-phase1');
      this.enableButton('start-phase1');
      this.updatePhase1Progress(phase1Status);
    } else if (phase1Status.in_progress === true) {
      // ✅ ACTUALIZADO: Verificar explícitamente que in_progress sea true
      this.updatePhaseStatus(1, 'running');
      this.updatePhase1Progress(phase1Status);
      // ✅ ACTUALIZADO: Asegurar que los botones estén en el estado correcto
      this.disableButton('start-phase1');
      this.enableButton('cancel-phase1');
      if (!this.phase1Timer) {
        this.phase1StartTime = Date.now();
        this.startTimer(1);
      }
      // ✅ CORRECCIÓN: Iniciar polling automáticamente si hay sincronización en progreso
      this.startPollingIfNeeded();
    } else if (phase1Status.completed === true) {
      // ✅ CORRECCIÓN: Verificar explícitamente que completed sea true
      this.updatePhaseStatus(1, 'completed');
      this.stopTimer(1);
      // ✅ MEJORADO: No bloquear el botón, solo asegurar que esté habilitado
      this.enableButton('start-phase2');
      this.updatePhase1Progress(phase1Status);
    } else {
      // Si no está en progreso ni completada, resetear a estado pendiente
      this.updatePhaseStatus(1, 'pending');
      this.stopTimer(1);
      this.resetPhase1Progress();
    }

    // Actualizar estado de Fase 2
    const stats = data.estadisticas || {};
    const phase2Total = Number(stats.total) || 0;
    const phase2Processed = Number(stats.procesados) || 0;
    // ✅ CORRECCIÓN: Verificar explícitamente que in_progress sea true
    const phase2IsInProgress = data.in_progress === true && !phase1Status.in_progress;
    const phase2IsCompleted = data.is_completed === true && phase2Total > 0 && phase2Processed >= phase2Total;
    
    if (phase2IsInProgress) {
      // ✅ CORRECCIÓN: Solo actualizar si realmente está en progreso
      this.updatePhaseStatus(2, 'running');
      this.updatePhase2Progress(data);
      if (!this.phase2Timer) {
        this.phase2StartTime = Date.now();
        this.startTimer(2);
      }
      // ✅ NUEVO: Mostrar botón de cancelar cuando hay sincronización en progreso
      this.enableButton('mi-cancel-sync');
      jQuery('#mi-cancel-sync').show();
      // ✅ CORRECCIÓN: Iniciar polling automáticamente si hay sincronización en progreso
      this.startPollingIfNeeded();
    } else if (phase2IsCompleted) {
      // ✅ CORRECCIÓN: Solo marcar como completado si realmente se completó (total > 0 y procesados >= total)
      this.updatePhaseStatus(2, 'completed');
      this.stopTimer(2);
      this.updatePhase2Progress(data);
      // ✅ NUEVO: Ocultar botón de cancelar cuando está completado
      this.disableButton('mi-cancel-sync');
      jQuery('#mi-cancel-sync').hide();
    } else {
      // Si no está en progreso ni completada, resetear a estado pendiente
      this.updatePhaseStatus(2, 'pending');
      this.stopTimer(2);
      this.resetPhase2Progress();
      // ✅ CORRECCIÓN: Resetear también el tiempo de inicio para evitar cálculos incorrectos
      this.phase2StartTime = null;
      // ✅ NUEVO: Ocultar botón de cancelar cuando no hay sincronización
      this.disableButton('mi-cancel-sync');
      jQuery('#mi-cancel-sync').hide();
    }
  }

  /**
   * Inicia el polling automáticamente si hay sincronización en progreso
   *
   * @returns {void}
   * @private
   */
  startPollingIfNeeded() {
    // ✅ CORRECCIÓN: Verificar si el polling ya está activo
    if (typeof pollingManager !== 'undefined' && pollingManager) {
      // Verificar si ya hay un polling activo para 'syncProgress'
      const existingPolling = pollingManager.intervals && pollingManager.intervals.get && pollingManager.intervals.get('syncProgress');
      
      if (!existingPolling && typeof checkSyncProgress === 'function') {
        // Configurar intervalo de 5 segundos (5000ms) para modo activo
        pollingManager.config.currentInterval = pollingManager.config.intervals.active || 5000;
        pollingManager.config.currentMode = 'active';
        
        // Iniciar polling
        const intervalId = pollingManager.startPolling('syncProgress', checkSyncProgress, pollingManager.config.currentInterval);
        
        // Exponer syncInterval en window si existe (compatibilidad con código original)
        if (typeof window !== 'undefined') {
          try {
            window.syncInterval = intervalId;
          } catch (error) {
            // Ignorar si no se puede asignar
          }
        }
        
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && console.log) {
          // eslint-disable-next-line no-console
          console.log('✅ Polling automático iniciado cada', pollingManager.config.currentInterval / 1000, 'segundos');
        }
      }
    }
  }

  /**
   * Maneja la respuesta de inicio de Fase 1
   *
   * @param {Object} data - Datos de respuesta
   * @returns {void}
   */
  handlePhase1Response(data) {
    if (data && data.in_progress) {
      // ✅ NUEVO: Iniciar polling de Phase1Manager para monitorear completitud y actualizar consola
      // Phase1Manager.checkPhase1Complete() actualiza la consola y detecta cuando Fase 1 está completa
      if (typeof window !== 'undefined' && window.Phase1Manager) {
        // Verificar si el polling ya está activo
        if (typeof window.Phase1Manager.getPollingInterval === 'function') {
          const existingInterval = window.Phase1Manager.getPollingInterval();
          if (!existingInterval && typeof window.Phase1Manager.startPolling === 'function') {
            // Si no hay polling activo, iniciarlo manualmente
            // Esto asegura que checkPhase1Complete() se ejecute cada 5 segundos
            // y actualice la consola con el progreso de Fase 1
            window.Phase1Manager.startPolling();
          }
        }
      }

      // ✅ CORRECCIÓN: Iniciar polling de SyncProgress para actualizar dashboard y consola
      if (typeof pollingManager !== 'undefined' && pollingManager) {
        pollingManager.config.currentInterval = pollingManager.config.intervals.active;
        pollingManager.config.currentMode = 'active';
        // ✅ CORRECCIÓN: Iniciar el polling real para monitorear el progreso
        if (typeof pollingManager.startPolling === 'function' && typeof checkSyncProgress === 'function') {
          const intervalId = pollingManager.startPolling('syncProgress', checkSyncProgress, pollingManager.config.currentInterval);
          // Exponer syncInterval en window si existe (compatibilidad con código original)
          if (typeof window !== 'undefined') {
            try {
              window.syncInterval = intervalId;
            } catch (error) {
              // Ignorar si no se puede asignar
            }
          }
        }
      }
    }
  }
}

/**
 * Exponer SyncDashboard globalmente para mantener compatibilidad
 * con el código existente que usa window.SyncDashboard
 */
// eslint-disable-next-line no-restricted-globals
if (typeof window !== 'undefined') {
  try {
    // eslint-disable-next-line no-restricted-globals
    window.SyncDashboard = SyncDashboard;
  } catch (error) {
    try {
      // eslint-disable-next-line no-restricted-globals
      Object.defineProperty(window, 'SyncDashboard', {
        value: SyncDashboard,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar SyncDashboard a window:', defineError, error);
      }
    }
  }
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { SyncDashboard };
}

/**
 * Función auxiliar para inicializar SyncDashboard
 * @returns {void}
 */
function initializeSyncDashboard() {
  // Solo inicializar si existe el elemento del dashboard y no hay una instancia previa
  // eslint-disable-next-line no-restricted-globals
  if (typeof window !== 'undefined' && typeof jQuery !== 'undefined' && jQuery('#sync-two-phase-dashboard').length && !window.syncDashboard) {
    try {
      // eslint-disable-next-line no-restricted-globals
      window.syncDashboard = new SyncDashboard();
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.log) {
        // eslint-disable-next-line no-console
        console.log('✅ SyncDashboard inicializado automáticamente');
      }
    } catch (error) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.error) {
        // eslint-disable-next-line no-console
        console.error('❌ Error inicializando SyncDashboard:', error);
      }
    }
  }
}

/**
 * ✅ INICIALIZACIÓN AUTOMÁTICA: Crear instancia de SyncDashboard cuando el DOM esté listo
 * Esto asegura que los event listeners se registren correctamente
 */
// eslint-disable-next-line no-restricted-globals
if (typeof window !== 'undefined') {
  // Usar múltiples métodos para asegurar que se ejecute cuando jQuery esté disponible
  if (typeof jQuery !== 'undefined') {
    jQuery(document).ready(function() {
      initializeSyncDashboard();
    });
  } else {
    // Fallback: esperar a que jQuery esté disponible
    // eslint-disable-next-line no-restricted-globals
    if (typeof window.addEventListener !== 'undefined') {
      // eslint-disable-next-line no-restricted-globals
      window.addEventListener('DOMContentLoaded', function() {
        // Esperar un poco más para que jQuery se cargue
        setTimeout(function() {
          if (typeof jQuery !== 'undefined') {
            initializeSyncDashboard();
          }
        }, 100);
      });
    }
  }
}
