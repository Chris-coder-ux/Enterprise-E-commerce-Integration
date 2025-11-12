/**
 * Gestor de Consola de Sincronización
 * 
 * Gestiona el terminal de consola que muestra el progreso y los logs
 * del proceso de sincronización en tiempo real.
 * 
 * @module components/ConsoleManager
 * @namespace ConsoleManager
 * @since 1.0.0
 * @author Christian
 */

/* global jQuery */

// ✅ CRÍTICO: Envolver todo el código en un IIFE para evitar redeclaraciones
// si el script se carga múltiples veces
(function() {
  'use strict';
  
  // ✅ Verificar si ya se ejecutó este script para evitar redeclaraciones
  if (typeof window !== 'undefined' && window.__ConsoleManagerLoaded) {
    return;
  }
  
  // Marcar que el script se está cargando
  if (typeof window !== 'undefined') {
    window.__ConsoleManagerLoaded = true;
  }
  
  /**
   * Selectores CSS para los elementos de la consola
   * 
   * @type {Object<string, string>}
   */
  const SELECTORS = {
    console: '#mia-sync-console',
    consoleContent: '#mia-console-content',
    consoleBody: '.mia-console-body',
    phase1Indicator: '#mia-phase1-indicator',
    phase2Indicator: '#mia-phase2-indicator',
    clearButton: '#mia-console-clear',
    toggleButton: '#mia-console-toggle'
  };
  
  /**
   * Mapeo de tipos de mensaje a etiquetas
   * 
   * @type {Object<string, string>}
   */
  const LABEL_MAP = {
    info: '[INFO]',
    success: '[SUCCESS]',
    warning: '[WARNING]',
    error: '[ERROR]',
    phase1: '[FASE 1]',
    phase2: '[FASE 2]'
  };
  
  /**
   * Límite máximo de líneas en la consola para evitar problemas de rendimiento
   * 
   * @type {number}
   */
  const MAX_LINES = 100;
  
  /**
   * Estado de tracking para detectar cambios y evitar duplicados
   * 
   * @type {Object}
   */
  const trackingState = {
    lastProductId: 0,
    lastProductsProcessed: 0,
    lastImagesProcessed: 0,
    lastSummaryProducts: 0,
    wasPaused: false,
    wasCancelled: false,
    wasInProgress: false, // ✅ NUEVO: Trackear si estaba en progreso para detectar cambios de estado
    lastCheckpointSavedId: 0, // ✅ NUEVO: Trackear último checkpoint guardado para evitar duplicados
    initialCacheClearedShown: false, // ✅ NUEVO: Trackear si ya se mostró mensaje de limpieza inicial
    checkpointLoadedShown: false, // ✅ NUEVO: Trackear si ya se mostró mensaje de checkpoint cargado
    technicalInfoShown: false // ✅ NUEVO: Trackear si ya se mostraron mensajes técnicos informativos
  };
  
  /**
   * Inicializar la consola
   * 
   * Configura los event listeners para los controles de la consola
   * (limpiar, minimizar/maximizar).
   * 
   * @returns {void}
   * 
   * @example
   * ConsoleManager.initialize();
   */
  function initialize() {
    if (typeof jQuery === 'undefined') {
      // eslint-disable-next-line no-console
      console.error('ConsoleManager requiere jQuery');
      return;
    }

    // ✅ DEBUG: Verificar que los elementos existen
    const $console = jQuery(SELECTORS.console);
    const $consoleContent = jQuery(SELECTORS.consoleContent);
    const $consoleBody = jQuery(SELECTORS.consoleBody);
    const $clearButton = jQuery(SELECTORS.clearButton);
    const $toggleButton = jQuery(SELECTORS.toggleButton);
    const $phase1Indicator = jQuery(SELECTORS.phase1Indicator);
    const $phase2Indicator = jQuery(SELECTORS.phase2Indicator);
    
    
    // ✅ VERIFICACIÓN: Si no se encuentran los elementos, mostrar error detallado
    if ($console.length === 0) {
      // eslint-disable-next-line no-console
      console.error('[ConsoleManager] ❌ CRÍTICO: No se encontró el elemento de la consola', {
        selector: SELECTORS.console,
        suggestion: 'Verifica que el HTML contiene <div id="mia-sync-console">'
      });
      return;
    }
    
    if ($consoleContent.length === 0) {
      // eslint-disable-next-line no-console
      console.error('[ConsoleManager] ❌ CRÍTICO: No se encontró el contenedor de contenido de la consola', {
        selector: SELECTORS.consoleContent,
        suggestion: 'Verifica que el HTML contiene <div id="mia-console-content">'
      });
      return;
    }
    
    // ✅ NUEVO: Añadir mensaje inicial si la consola está vacía
    const existingLines = $consoleContent.find('.mia-console-line');
    if (existingLines.length === 0) {
      addLine('info', 'Consola de sincronización iniciada. Esperando actividad...');
    }
  
    // Botón de limpiar consola
    if ($clearButton.length > 0) {
      $clearButton.on('click', function() {
        clear();
        addLine('info', 'Consola limpiada');
      });
    }
  
    // Botón de minimizar/maximizar
    if ($toggleButton.length > 0) {
      $toggleButton.on('click', function() {
        toggle();
      });
    }
  
    // ✅ NUEVO: Suscribirse a eventos de sincronización del PollingManager
    // Prevenir suscripciones duplicadas usando una bandera estática
    const hasSubscribed = initialize.hasSubscribedToEvents === true;
    if (hasSubscribed) {
      return;
    }
  
    // Si no está suscrito, proceder con la suscripción
    if (typeof window !== 'undefined' && window.pollingManager) {
      const hasOnMethod = typeof window.pollingManager.on === 'function';
      
      if (!hasOnMethod) {
        return;
      }
      
      // Suscribirse a eventos de progreso de sincronización
      window.pollingManager.on('syncProgress', function(data) {
        if (data && data.syncData) {
          updateSyncConsole(data.syncData, data.phase1Status);
        }
      });
  
      // Suscribirse a eventos de error
      window.pollingManager.on('syncError', function(error) {
        addLine('error', error.message || 'Error en sincronización');
      });
  
      initialize.hasSubscribedToEvents = true;
    }
  }
  
  /**
   * Agregar una línea al terminal de consola
   * 
   * @param {string} type - Tipo de mensaje: 'info', 'success', 'warning', 'error', 'phase1', 'phase2'
   * @param {string} message - Mensaje a mostrar
   * @returns {void}
   * 
   * @example
   * ConsoleManager.addLine('info', 'Procesando productos...');
   * ConsoleManager.addLine('success', 'Sincronización completada');
   */
  function addLine(type, message) {
    if (typeof jQuery === 'undefined') {
      // eslint-disable-next-line no-console
      console.error('ConsoleManager requiere jQuery');
      return;
    }
  
    const $consoleContent = jQuery(SELECTORS.consoleContent);
    
    if ($consoleContent.length === 0) {
      // eslint-disable-next-line no-console
      console.warn('ConsoleManager: No se encontró el contenedor de la consola');
      return;
    }
  
    const now = new Date();
    const timeStr = now.toLocaleTimeString('es-ES', {
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit'
    });
  
    const label = LABEL_MAP[type] || LABEL_MAP.info;
  
    const $line = jQuery('<div>')
      .addClass('mia-console-line')
      .addClass(`mia-console-${type}`)
      .html(`
        <span class="mia-console-time">${timeStr}</span>
        <span class="mia-console-label">${label}</span>
        <span class="mia-console-message">${message}</span>
      `);
  
    $consoleContent.append($line);
  
    // Limitar a MAX_LINES líneas para evitar problemas de rendimiento
    const lines = $consoleContent.find('.mia-console-line');
    if (lines.length > MAX_LINES) {
      lines.first().remove();
    }
  
    // Auto-scroll al final
    scrollToBottom();
  }
  
  /**
   * Actualizar la consola con datos de sincronización
   * 
   * @param {Object} syncData - Datos de sincronización
   * @param {Object} [phase1Status] - Estado de la Fase 1 (imágenes)
   * @returns {void}
   * 
   * @example
   * ConsoleManager.updateSyncConsole({
   *   in_progress: true,
   *   estadisticas: { procesados: 50, total: 100 }
   * }, {
   *   in_progress: false,
   *   completed: true
   * });
   */
  function updateSyncConsole(syncData, phase1Status) {
    if (typeof jQuery === 'undefined') {
      // eslint-disable-next-line no-console
      console.error('ConsoleManager requiere jQuery');
      return;
    }
  
    const $console = jQuery(SELECTORS.console);
    const $consoleContent = jQuery(SELECTORS.consoleContent);
  
    if ($console.length === 0 || $consoleContent.length === 0) {
      // eslint-disable-next-line no-console
      console.warn('[ConsoleManager] ⚠️  No se encontraron elementos de la consola', {
        consoleSelector: SELECTORS.console,
        consoleContentSelector: SELECTORS.consoleContent,
        consoleFound: $console.length > 0,
        consoleContentFound: $consoleContent.length > 0
      });
      return;
    }
  
    // ✅ ACTUALIZADO: La consola está siempre visible, no necesita mostrar/ocultar
    // Asegurar que esté visible (por si acaso)
    if ($console.is(':hidden')) {
      $console.show();
    }
  
    // ✅ NUEVO: Limpiar mensaje inicial si hay actividad
    const phase1InProgress = phase1Status && phase1Status.in_progress === true;
    const phase2InProgress = syncData && syncData.in_progress === true && !phase1InProgress;
    const hasActivity = phase1InProgress || phase2InProgress;
    
    if (hasActivity) {
      // Buscar y eliminar el mensaje inicial "Esperando actividad..."
      const $initialMessage = $consoleContent.find('.mia-console-line').first();
      if ($initialMessage.length > 0) {
        const messageText = $initialMessage.find('.mia-console-message').text();
        if (messageText.includes('Esperando actividad') || messageText.includes('Consola de sincronización iniciada')) {
          $initialMessage.remove();
        }
      }
    }
  
    // ✅ REMOVIDO: Debug innecesario que se ejecuta constantemente (cada 2 segundos durante polling)
  
    // Actualizar indicadores de fase
    updatePhaseIndicators(syncData, phase1Status);
  
    // Agregar líneas de log según el estado
    addProgressLines(syncData, phase1Status);
  
    // Auto-scroll al final
    scrollToBottom();
  }
  
  /**
   * Actualizar los indicadores de fase
   * 
   * @param {Object} syncData - Datos de sincronización
   * @param {Object} [phase1Status] - Estado de la Fase 1
   * @returns {void}
   * @private
   */
  function updatePhaseIndicators(syncData, phase1Status) {
    const phase1InProgress = phase1Status && phase1Status.in_progress;
    const phase1Completed = phase1Status && phase1Status.completed;
    const phase2InProgress = syncData.in_progress && !phase1InProgress;
    const phase2Completed = syncData.is_completed;
  
    // Actualizar Fase 1
    const $phase1Indicator = jQuery(SELECTORS.phase1Indicator);
    if ($phase1Indicator.length > 0) {
      if (phase1Completed) {
        $phase1Indicator.attr('data-status', 'completed');
        $phase1Indicator.find('.mia-phase-status').text('Completada').attr('data-status', 'completed');
      } else if (phase1InProgress) {
        $phase1Indicator.attr('data-status', 'active');
        $phase1Indicator.find('.mia-phase-status').text('En Progreso').attr('data-status', 'active');
      } else {
        $phase1Indicator.attr('data-status', 'pending');
        $phase1Indicator.find('.mia-phase-status').text('Pendiente').attr('data-status', 'pending');
      }
    }
  
    // Actualizar Fase 2
    const $phase2Indicator = jQuery(SELECTORS.phase2Indicator);
    if ($phase2Indicator.length > 0) {
      if (phase2Completed) {
        $phase2Indicator.attr('data-status', 'completed');
        $phase2Indicator.find('.mia-phase-status').text('Completada').attr('data-status', 'completed');
      } else if (phase2InProgress) {
        $phase2Indicator.attr('data-status', 'active');
        $phase2Indicator.find('.mia-phase-status').text('En Progreso').attr('data-status', 'active');
      } else {
        $phase2Indicator.attr('data-status', 'pending');
        $phase2Indicator.find('.mia-phase-status').text('Pendiente').attr('data-status', 'pending');
      }
    }
  }
  
  /**
   * Agregar líneas de progreso según el estado
   * 
   * @param {Object} syncData - Datos de sincronización
   * @param {Object} [phase1Status] - Estado de la Fase 1
   * @returns {void}
   * @private
   */
  function addProgressLines(syncData, phase1Status) {
    const $consoleContent = jQuery(SELECTORS.consoleContent);
    
    // ✅ DEBUG: Log para diagnosticar
    
    // ✅ PROTECCIÓN: Validar que phase1Status existe antes de usarlo
    if (!phase1Status || typeof phase1Status !== 'object') {
      phase1Status = {};
    }
    
    const phase1InProgress = phase1Status.in_progress === true;
    const phase1Completed = phase1Status.completed === true;
    const phase1Paused = phase1Status.paused === true;
    const phase1Cancelled = phase1Status.cancelled === true;
    const phase2InProgress = syncData && syncData.in_progress === true && !phase1InProgress;
    const phase2Completed = syncData && syncData.is_completed === true;
    
    // ✅ NUEVO: Detectar si hay progreso real (valores > 0) incluso si está pausada o cancelada
    const hasRealProgress = (phase1Status.products_processed > 0) || (phase1Status.total_products > 0);
    const shouldShowProgress = phase1InProgress || (hasRealProgress && (phase1Paused || phase1Cancelled));
    
  
    // ✅ NUEVO: Mostrar métricas de limpieza de caché para Fase 1
    if (phase1InProgress && phase1Status && phase1Status.last_cleanup_metrics) {
      const cleanup = phase1Status.last_cleanup_metrics;
      const lastCleanupTime = cleanup.timestamp || 0;
      const now = Math.floor(Date.now() / 1000);
      
      // Solo mostrar si la limpieza fue reciente (últimos 30 segundos) para evitar spam
      if (now - lastCleanupTime <= 30) {
        const cleanupMsg = formatCleanupMetrics(cleanup, 'Fase 1');
        const lastLine = $consoleContent.find('.mia-console-line').last();
        const lastMessage = lastLine.find('.mia-console-message').text();
        
        // Solo agregar si no es la misma métrica
        if (!lastMessage.includes('Limpieza de caché') || !lastMessage.includes(cleanup.cleanup_level || cleanup.type)) {
          addLine('info', cleanupMsg);
        }
      }
    }
  
    // ✅ NUEVO: Mostrar métricas de limpieza de caché para Fase 2
    if (phase2InProgress && syncData.last_cleanup_metrics) {
      const cleanup = syncData.last_cleanup_metrics;
      const lastCleanupTime = cleanup.timestamp || 0;
      const now = Math.floor(Date.now() / 1000);
      
      // Solo mostrar si la limpieza fue reciente (últimos 30 segundos) para evitar spam
      if (now - lastCleanupTime <= 30) {
        const cleanupMsg = formatCleanupMetrics(cleanup, 'Fase 2');
        const lastLine = $consoleContent.find('.mia-console-line').last();
        const lastMessage = lastLine.find('.mia-console-message').text();
        
        // Solo agregar si no es la misma métrica
        if (!lastMessage.includes('Limpieza de caché') || !lastMessage.includes(cleanup.type || 'batch')) {
          addLine('info', cleanupMsg);
        }
      }
    }
  
      // ✅ NUEVO: Mostrar mensaje cuando Fase 1 inicia (solo una vez)
    if (phase1InProgress && phase1Status && trackingState.lastProductsProcessed === 0 && phase1Status.products_processed === 0) {
      const totalProducts = phase1Status.total_products || 0;
      addLine('phase1', `Iniciando Fase 1: Sincronización de imágenes${totalProducts > 0 ? ` para ${totalProducts} productos` : ''}...`);
      trackingState.lastProductsProcessed = -1; // Marcar que ya mostramos el mensaje inicial
    }
    
    // ✅ NUEVO: Mostrar mensaje de limpieza inicial de caché (solo una vez)
    if (phase1InProgress && phase1Status && phase1Status.initial_cache_cleared && !trackingState.initialCacheClearedShown) {
      const clearedCount = phase1Status.initial_cache_cleared_count || 0;
      const cacheMsg = clearedCount > 0 
        ? `Caché inicial limpiada: ${clearedCount} entradas eliminadas`
        : 'Caché inicial limpiada';
      addLine('info', cacheMsg);
      trackingState.initialCacheClearedShown = true;
    }
    
    // ✅ NUEVO: Mostrar mensaje de checkpoint cargado (solo una vez)
    if (phase1InProgress && phase1Status && phase1Status.checkpoint_loaded && phase1Status.checkpoint_loaded_from_id && !trackingState.checkpointLoadedShown) {
      const checkpointId = phase1Status.checkpoint_loaded_from_id;
      const checkpointProducts = phase1Status.checkpoint_loaded_products_processed || 0;
      addLine('info', `Reanudando desde checkpoint: Producto #${checkpointId} (${checkpointProducts} productos ya procesados)`);
      trackingState.checkpointLoadedShown = true;
    }
    
    // ✅ NUEVO: Mostrar mensajes informativos técnicos (solo una vez al inicio)
    if (phase1InProgress && phase1Status && phase1Status.products_processed === 0 && !trackingState.technicalInfoShown) {
      // Mensaje de thumbnails desactivados
      if (phase1Status.thumbnails_disabled) {
        addLine('info', 'Generación de thumbnails desactivada temporalmente (se generarán automáticamente después de la sincronización)');
      }
      
      // Mensaje de límite de memoria aumentado
      if (phase1Status.memory_limit_increased && phase1Status.memory_limit_original && phase1Status.memory_limit_new) {
        addLine('info', `Límite de memoria aumentado temporalmente: ${phase1Status.memory_limit_original} → ${phase1Status.memory_limit_new}`);
      }
      
      trackingState.technicalInfoShown = true;
    }
    
    // ✅ NUEVO: Mostrar mensaje cuando se guarda un checkpoint (cada vez que cambia)
    if (phase1InProgress && phase1Status && phase1Status.last_checkpoint_saved_id && phase1Status.last_checkpoint_saved_id !== trackingState.lastCheckpointSavedId) {
      const checkpointId = phase1Status.last_checkpoint_saved_id;
      addLine('info', `Checkpoint guardado: Producto #${checkpointId}`);
      trackingState.lastCheckpointSavedId = checkpointId;
    }
  
    // ✅ NUEVO: Mostrar estado cuando está pausada o cancelada pero hay progreso real
    // ✅ CORRECCIÓN: Solo mostrar si hay un cambio de estado (de activa a pausada/cancelada)
    // No mostrar si simplemente se carga el estado inicial pausado
    if (!phase1InProgress && hasRealProgress && phase1Status && (phase1Paused || phase1Cancelled)) {
      const phase1Percent = phase1Status.total_products > 0
        ? ((phase1Status.products_processed / phase1Status.total_products) * 100).toFixed(1)
        : 0;
      
      const currentProductsProcessed = phase1Status.products_processed || 0;
      const imagesProcessed = phase1Status.images_processed || 0;
      const duplicatesSkipped = phase1Status.duplicates_skipped || 0;
      const errors = phase1Status.errors || 0;
      
      // ✅ CORRECCIÓN: Solo mostrar si hay un cambio de estado real (de activa a pausada/cancelada)
      // No mostrar si simplemente se carga el estado inicial pausado
      const wasInProgress = trackingState.wasInProgress === true;
      const stateChanged = (phase1Paused && !trackingState.wasPaused) || (phase1Cancelled && !trackingState.wasCancelled);
      const progressChanged = currentProductsProcessed !== trackingState.lastProductsProcessed ||
                              imagesProcessed !== trackingState.lastImagesProcessed;
      
      // Solo mostrar si:
      // 1. Estaba en progreso y ahora está pausada/cancelada (cambio de estado)
      // 2. O si hay un cambio significativo en el progreso mientras está pausada/cancelada
      const shouldShow = (wasInProgress && stateChanged) || (progressChanged && wasInProgress);
      
      if (shouldShow) {
        let statusMsg = phase1Paused ? 'Fase 1 pausada' : 'Fase 1 cancelada';
        statusMsg += `: ${currentProductsProcessed}/${phase1Status.total_products || 0} productos procesados`;
        statusMsg += `, ${imagesProcessed} imágenes sincronizadas`;
        if (duplicatesSkipped > 0) {
          statusMsg += `, ${duplicatesSkipped} duplicados omitidos`;
        }
        if (errors > 0) {
          statusMsg += `, ${errors} errores`;
        }
        statusMsg += ` (${phase1Percent}%)`;
        
        addLine(phase1Paused ? 'warning' : 'error', statusMsg);
        
        // Actualizar tracking
        trackingState.lastProductsProcessed = currentProductsProcessed;
        trackingState.lastImagesProcessed = imagesProcessed;
        trackingState.wasPaused = phase1Paused;
        trackingState.wasCancelled = phase1Cancelled;
        trackingState.wasInProgress = false; // Ya no está en progreso
      } else {
        // ✅ NUEVO: Actualizar tracking sin mostrar mensaje si es estado inicial
        // Esto evita mostrar mensajes de sincronizaciones anteriores al cargar la página
        trackingState.wasPaused = phase1Paused;
        trackingState.wasCancelled = phase1Cancelled;
        trackingState.lastProductsProcessed = currentProductsProcessed;
        trackingState.lastImagesProcessed = imagesProcessed;
      }
    } else if (phase1InProgress) {
      // ✅ NUEVO: Marcar que está en progreso y resetear flags de pausa/cancelación
      trackingState.wasInProgress = true;
      trackingState.wasPaused = false;
      trackingState.wasCancelled = false;
    } else {
      // ✅ NUEVO: Si no está en progreso y no está pausada/cancelada, resetear flag
      trackingState.wasInProgress = false;
    }
    
    // Fase 1 en progreso
    if (phase1InProgress && phase1Status) {
      const phase1Percent = phase1Status.total_products > 0
        ? ((phase1Status.products_processed / phase1Status.total_products) * 100).toFixed(1)
        : 0;
  
      // ✅ MEJORADO: Mostrar mensaje detallado de cada producto procesado
      // Verificar si hay un nuevo producto procesado
      const currentProductId = phase1Status.last_processed_id || 0;
      const currentProductsProcessed = phase1Status.products_processed || 0;
      const currentImagesProcessed = phase1Status.images_processed || 0;
      const productChanged = currentProductId > 0 && currentProductId !== trackingState.lastProductId;
      const productsProcessedChanged = currentProductsProcessed !== trackingState.lastProductsProcessed;
      const imagesProcessedChanged = currentImagesProcessed !== trackingState.lastImagesProcessed;
      
      // ✅ CORRECCIÓN: Resetear tracking si products_processed cambió de 0 a un valor positivo
      if (trackingState.lastProductsProcessed === -1 && currentProductsProcessed > 0) {
        trackingState.lastProductsProcessed = 0;
      }
      
      // ✅ MEJORADO: Mostrar mensaje por cada producto procesado
      // ✅ CORRECCIÓN: Verificar que tenemos datos del último producto antes de mostrar
      if (productChanged && currentProductId > 0) {
        const lastProductImages = phase1Status.last_product_images !== undefined ? phase1Status.last_product_images : 0;
        const lastProductDuplicates = phase1Status.last_product_duplicates !== undefined ? phase1Status.last_product_duplicates : 0;
        const lastProductErrors = phase1Status.last_product_errors !== undefined ? phase1Status.last_product_errors : 0;
        
        // ✅ CORRECCIÓN: Solo mostrar si tenemos información del producto (incluso si es 0)
        // Esto asegura que siempre mostramos algo cuando se procesa un producto
        let productMsg = `Producto #${currentProductId}: `;
        const parts = [];
        
        if (lastProductImages > 0) {
          parts.push(`${lastProductImages} imagen${lastProductImages > 1 ? 'es' : ''} descargada${lastProductImages > 1 ? 's' : ''}`);
        }
        if (lastProductDuplicates > 0) {
          parts.push(`${lastProductDuplicates} duplicada${lastProductDuplicates > 1 ? 's' : ''} omitida${lastProductDuplicates > 1 ? 's' : ''}`);
        }
        if (lastProductErrors > 0) {
          parts.push(`${lastProductErrors} error${lastProductErrors > 1 ? 'es' : ''}`);
        }
        if (parts.length === 0) {
          parts.push('sin imágenes');
        }
        
        productMsg += parts.join(', ');
        addLine('phase1', productMsg);
        
        // Actualizar tracking
        trackingState.lastProductId = currentProductId;
      }
  
      // ✅ MEJORADO: Mostrar resumen general cuando cambia el número de productos o imágenes procesados
      // ✅ CORRECCIÓN: Mostrar resumen cada cierto número de productos o cuando cambian significativamente los totales
      if ((productsProcessedChanged || imagesProcessedChanged) && currentProductsProcessed > 0) {
        const imagesProcessed = phase1Status.images_processed || 0;
        const duplicatesSkipped = phase1Status.duplicates_skipped || 0;
        const errors = phase1Status.errors || 0;
        
        // ✅ CORRECCIÓN: Mostrar resumen cada 5 productos o cuando cambian los totales significativamente
        // Esto asegura feedback regular sin saturar la consola
        const shouldShowSummary = 
          currentProductsProcessed % 5 === 0 || // Cada 5 productos
          currentProductsProcessed === 1 || // Primer producto
          currentProductsProcessed === phase1Status.total_products || // Último producto
          (productsProcessedChanged && currentProductsProcessed !== trackingState.lastSummaryProducts); // Cambio significativo
        
        if (shouldShowSummary) {
          let summaryMsg = `Fase 1: ${currentProductsProcessed}/${phase1Status.total_products || 0} productos procesados`;
          summaryMsg += `, ${imagesProcessed} imagen${imagesProcessed !== 1 ? 'es' : ''} sincronizada${imagesProcessed !== 1 ? 's' : ''}`;
          if (duplicatesSkipped > 0) {
            summaryMsg += `, ${duplicatesSkipped} duplicado${duplicatesSkipped !== 1 ? 's' : ''} omitido${duplicatesSkipped !== 1 ? 's' : ''}`;
          }
          if (errors > 0) {
            summaryMsg += `, ${errors} error${errors !== 1 ? 'es' : ''}`;
          }
          summaryMsg += ` (${phase1Percent}%)`;
          
          // ✅ NUEVO: Agregar velocidad de procesamiento al resumen
          if (phase1Status.start_time && phase1Status.start_time > 0) {
            const elapsedSeconds = (Math.floor(Date.now() / 1000) - phase1Status.start_time);
            if (elapsedSeconds > 0) {
              const speed = (currentProductsProcessed / elapsedSeconds).toFixed(2);
              summaryMsg += ` | Velocidad: ${speed} productos/seg`;
            }
          }
          
          addLine('info', summaryMsg);
          trackingState.lastSummaryProducts = currentProductsProcessed;
        }
        
        // ✅ IMPORTANTE: Actualizar tracking siempre, incluso si no mostramos el resumen
        // Esto asegura que el tracking esté actualizado para la próxima verificación
        trackingState.lastProductsProcessed = currentProductsProcessed;
        trackingState.lastImagesProcessed = currentImagesProcessed;
      }
    }
  
    // Fase 2 en progreso
    if (phase2InProgress && syncData.estadisticas) {
      const phase2Percent = syncData.porcentaje || 0;
      const stats = syncData.estadisticas || {};
  
      // Verificar si ya existe una línea similar reciente
      const lastLine = $consoleContent.find('.mia-console-line').last();
      const lastMessage = lastLine.find('.mia-console-message').text();
      const shouldAdd = !lastMessage.includes(`Fase 2: ${stats.procesados}/${stats.total}`);
  
      if (shouldAdd && stats.procesados > 0) {
        addLine('phase2', `Fase 2: ${stats.procesados}/${stats.total} productos sincronizados (${phase2Percent.toFixed(1)}%)`);
      }
    }
  
    // Fase 1 completada
    if (phase1Completed && !phase2InProgress) {
      addLine('success', 'Fase 1 completada exitosamente. Iniciando Fase 2...');
    }
  
    // Fase 2 completada
    if (phase2Completed) {
      addLine('success', 'Sincronización completada exitosamente');
    }
  }
  
  /**
   * Formatea las métricas de limpieza de caché para mostrar en consola
   * 
   * @param {Object} cleanup - Métricas de limpieza
   * @param {string} phase - Fase ('Fase 1' o 'Fase 2')
   * @returns {string} Mensaje formateado
   * @private
   */
  function formatCleanupMetrics(cleanup, phase) {
    if (!cleanup) {
      return '';
    }
  
    const parts = [];
    
    // Memoria liberada
    if (cleanup.memory_freed_mb && cleanup.memory_freed_mb > 0) {
      parts.push(`Memoria liberada: ${cleanup.memory_freed_mb} MB`);
    }
    
    // Uso de memoria
    if (cleanup.memory_usage_percent !== undefined) {
      parts.push(`Uso memoria: ${cleanup.memory_usage_percent}%`);
    }
    
    // Garbage collection
    if (cleanup.gc_cycles_collected !== undefined && cleanup.gc_cycles_collected > 0) {
      parts.push(`GC: ${cleanup.gc_cycles_collected} ciclos`);
    }
    
    // Cache flush
    if (cleanup.cache_flushed) {
      parts.push('Cache WordPress: limpiado');
    }
    
    // Cold cache limpiado
    if (cleanup.cold_cache_cleaned && cleanup.cold_cache_cleaned > 0) {
      parts.push(`Cold cache: ${cleanup.cold_cache_cleaned} entradas`);
    }
    
    // Hot→Cold migrado
    if (cleanup.hot_cold_migrated && cleanup.hot_cold_migrated > 0) {
      parts.push(`Hot→Cold: ${cleanup.hot_cold_migrated} migradas`);
    }
    
    // Nivel de limpieza
    if (cleanup.cleanup_level) {
      const levelNames = {
        light: 'Ligera',
        moderate: 'Moderada',
        aggressive: 'Agresiva',
        critical: 'Crítica'
      };
      parts.push(`Nivel: ${levelNames[cleanup.cleanup_level] || cleanup.cleanup_level}`);
    }
    
    // Total limpiado (Fase 2)
    if (cleanup.total_cleared !== undefined && cleanup.total_cleared > 0) {
      parts.push(`Entradas limpiadas: ${cleanup.total_cleared}`);
    }
    
    if (cleanup.preserved_hot_cache !== undefined && cleanup.preserved_hot_cache > 0) {
      parts.push(`Hot cache preservado: ${cleanup.preserved_hot_cache}`);
    }
  
    const metricsText = parts.length > 0 ? parts.join(' | ') : 'Limpieza ejecutada';
    
    return `${phase} - Limpieza de caché: ${metricsText}`;
  }
  
  /**
   * Limpiar el contenido de la consola
   * 
   * @returns {void}
   * 
   * @example
   * ConsoleManager.clear();
   */
  function clear() {
    if (typeof jQuery === 'undefined') {
      return;
    }
  
    const $consoleContent = jQuery(SELECTORS.consoleContent);
    if ($consoleContent.length > 0) {
      $consoleContent.empty();
    }
    
    // ✅ NUEVO: Resetear estado de tracking al limpiar
    trackingState.lastProductId = 0;
    trackingState.lastProductsProcessed = 0;
    trackingState.lastImagesProcessed = 0;
    trackingState.lastSummaryProducts = 0;
    trackingState.lastCheckpointSavedId = 0;
    trackingState.initialCacheClearedShown = false;
    trackingState.checkpointLoadedShown = false;
    trackingState.technicalInfoShown = false;
  }
  
  /**
   * Alternar entre minimizado y maximizado
   * 
   * @returns {void}
   * 
   * @example
   * ConsoleManager.toggle();
   */
  function toggle() {
    if (typeof jQuery === 'undefined') {
      return;
    }
  
    const $console = jQuery(SELECTORS.console);
    const $toggleButton = jQuery(SELECTORS.toggleButton);
  
    if ($console.length === 0 || $toggleButton.length === 0) {
      return;
    }
  
    $console.toggleClass('minimized');
  
    const $icon = $toggleButton.find('.dashicons');
    if ($console.hasClass('minimized')) {
      $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
    } else {
      $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
    }
  }
  
  /**
   * Hacer scroll al final de la consola
   * 
   * @returns {void}
   * @private
   */
  function scrollToBottom() {
    if (typeof jQuery === 'undefined') {
      return;
    }
  
    const $consoleBody = jQuery(SELECTORS.consoleBody);
    if ($consoleBody.length > 0 && $consoleBody[0]) {
      $consoleBody.scrollTop($consoleBody[0].scrollHeight);
    }
  }
  
  // ✅ DEBUG: Log ANTES de crear ConsoleManager para verificar que las funciones están disponibles
  // eslint-disable-next-line no-console
  console.log('[ConsoleManager] Verificando funciones antes de crear objeto...', {
    hasInitialize: typeof initialize !== 'undefined',
    hasAddLine: typeof addLine !== 'undefined',
    hasUpdateSyncConsole: typeof updateSyncConsole !== 'undefined',
    hasClear: typeof clear !== 'undefined',
    hasToggle: typeof toggle !== 'undefined'
  });
  
  /**
   * Objeto ConsoleManager con métodos públicos
   */
  const ConsoleManager = {
    initialize,
    addLine,
    updateSyncConsole,
    clear,
    toggle,
    MAX_LINES
  };
  
  /**
   * Exponer ConsoleManager globalmente para mantener compatibilidad
   * con el código existente que usa window.ConsoleManager, window.updateSyncConsole y window.addConsoleLine
   * 
   * ✅ MEJORADO: Múltiples intentos de exposición para asegurar que se exponga correctamente
   */
  
  // Función para exponer ConsoleManager con múltiples métodos de fallback
  function exposeConsoleManager() {
    if (typeof window === 'undefined') {
      return false;
    }
  
    if (typeof ConsoleManager === 'undefined' || !ConsoleManager) {
      return false;
    }
  
    // Método 1: Asignación directa
    try {
      window.ConsoleManager = ConsoleManager;
      window.updateSyncConsole = updateSyncConsole;
      window.addConsoleLine = addLine;
      
      // Verificar que se expuso correctamente
      if (typeof window.ConsoleManager !== 'undefined' && window.ConsoleManager === ConsoleManager) {
        return true;
      }
    } catch (error) {
      // Silenciar error, intentar siguiente método
    }
  
    // Método 2: Object.defineProperty
    try {
      Object.defineProperty(window, 'ConsoleManager', {
        value: ConsoleManager,
        writable: true,
        enumerable: true,
        configurable: true
      });
      Object.defineProperty(window, 'updateSyncConsole', {
        value: updateSyncConsole,
        writable: true,
        enumerable: true,
        configurable: true
      });
      Object.defineProperty(window, 'addConsoleLine', {
        value: addLine,
        writable: true,
        enumerable: true,
        configurable: true
      });
      
      // Verificar que se expuso correctamente
      if (typeof window.ConsoleManager !== 'undefined') {
        return true;
      }
    } catch (defineError) {
      // Silenciar error, intentar siguiente método
    }
  
    // Método 3: eval (último recurso)
    try {
      // eslint-disable-next-line no-eval
      eval('window.ConsoleManager = ConsoleManager; window.updateSyncConsole = updateSyncConsole; window.addConsoleLine = addLine;');
      
      if (typeof window.ConsoleManager !== 'undefined') {
        return true;
      }
    } catch (evalError) {
      // Solo loggear error crítico si todos los métodos fallan
      if (typeof console !== 'undefined' && console.error) {
        console.error('[ConsoleManager] ❌ Error crítico: No se pudo exponer ConsoleManager', evalError);
      }
    }
  
    return false;
  }
  
  // Intentar exponer inmediatamente
  try {
    if (!exposeConsoleManager()) {
      // Si falla, intentar de nuevo después de un breve delay
      setTimeout(function() {
        try {
          exposeConsoleManager();
        } catch (timeoutError) {
          // Silenciar error de timeout
        }
      }, 50);
    }
  } catch (exposeError) {
    // Solo loggear error crítico
    if (typeof console !== 'undefined' && console.error) {
      console.error('[ConsoleManager] ❌ Error crítico al exponer:', exposeError);
    }
  }
  
  /**
   * ✅ INICIALIZACIÓN AUTOMÁTICA: DESHABILITADA
   * 
   * NOTA: La inicialización automática está deshabilitada porque dashboard.js
   * ya inicializa ConsoleManager en initializeUIComponents().
   * 
   * Si se necesita inicialización automática independiente, descomentar el código siguiente.
   * IMPORTANTE: Asegúrate de que PollingManager esté disponible antes de suscribirse.
   */
  /*
  if (typeof jQuery !== 'undefined') {
    jQuery(document).ready(function() {
      // eslint-disable-next-line no-console
      console.log('[ConsoleManager] DOM listo, intentando inicializar (auto)...');
      // Esperar a que PollingManager esté disponible
      const checkPollingManager = setInterval(function() {
        if (typeof window !== 'undefined' && window.pollingManager) {
          clearInterval(checkPollingManager);
          if (typeof ConsoleManager !== 'undefined' && ConsoleManager && typeof ConsoleManager.initialize === 'function') {
            ConsoleManager.initialize();
          } else {
            // eslint-disable-next-line no-console
            console.error('[ConsoleManager] ❌ ConsoleManager no está disponible para inicialización automática');
          }
        }
      }, 50);
      
      }, 5000);
    });
  } else if (typeof window !== 'undefined' && typeof window.addEventListener !== 'undefined') {
    window.addEventListener('DOMContentLoaded', function() {
      setTimeout(function() {
        if (typeof jQuery !== 'undefined' && typeof ConsoleManager !== 'undefined' && ConsoleManager && typeof ConsoleManager.initialize === 'function') {
          ConsoleManager.initialize();
        }
      }, 100);
    });
  }
  */
  
    /* global module */
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { ConsoleManager };
  }
})(); // ✅ Cerrar el IIFE
