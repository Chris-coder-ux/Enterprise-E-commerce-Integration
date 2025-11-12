/**
 * Dashboard Principal - Punto de Entrada
 * 
 * Este archivo orquesta todos los módulos del dashboard.
 * Mantiene la compatibilidad con el código existente mientras
 * organiza la inicialización de forma modular.
 * 
 * @file dashboard.js
 * @since 1.0.0
 * @author Christian
 */

/* global miIntegracionApiDashboard, ErrorHandler, AjaxManager, SystemEventManager, SELECTORS, DASHBOARD_CONFIG, ConsoleManager, SyncStateManager, NonceManager, SyncProgress */

// ========================================
// VERIFICACIÓN DE DEPENDENCIAS
// ========================================

(function() {
  'use strict';

  // Verificar jQuery
  if (typeof jQuery === 'undefined') {
    console.error('jQuery no está disponible. El dashboard no funcionará.');
    return;
  }

  // Verificar configuración
  if (typeof miIntegracionApiDashboard === 'undefined') {
    console.error('miIntegracionApiDashboard no está definido. El dashboard no funcionará.');
    return;
  }

  // Verificar ErrorHandler (debe estar cargado como dependencia)
  if (typeof ErrorHandler === 'undefined') {
    console.error('ErrorHandler no está disponible. Asegúrate de que ErrorHandler.js se carga antes de dashboard.js.');
    return;
  }

  // Verificar AjaxManager (debe estar cargado como dependencia)
  if (typeof AjaxManager === 'undefined') {
    console.error('AjaxManager no está disponible. Asegúrate de que AjaxManager.js se carga antes de dashboard.js.');
    return;
  }

  // Verificar SystemEventManager (debe estar cargado como dependencia)
  if (typeof SystemEventManager === 'undefined') {
    console.error('SystemEventManager no está disponible. Asegúrate de que EventManager.js se carga antes de dashboard.js.');
    return;
  }

  // Verificar SELECTORS (debe estar cargado como dependencia)
  if (typeof SELECTORS === 'undefined') {
    console.error('SELECTORS no está disponible. Asegúrate de que constants.js se carga antes de dashboard.js.');
    return;
  }

  // Verificar DASHBOARD_CONFIG (debe estar cargado como dependencia)
  if (typeof DASHBOARD_CONFIG === 'undefined') {
    console.error('DASHBOARD_CONFIG no está disponible. Asegúrate de que dashboard-config.js se carga antes de dashboard.js.');
    return;
  }

  // ========================================
  // INICIALIZACIÓN PRINCIPAL
  // ========================================

  jQuery(document).ready(function() {
    // eslint-disable-next-line no-console
    console.log('🚀 Inicializando Dashboard...');

    // ✅ ORDEN CRÍTICO DE INICIALIZACIÓN:
    // 1. Sistemas base (ErrorHandler, AjaxManager, etc.)
    initializeCoreSystems();

    // 2. Managers del sistema (PollingManager, SyncStateManager)
    // IMPORTANTE: PollingManager debe estar disponible antes de ConsoleManager
    initializeManagers();

    // 3. Sistema de sincronización (SyncProgress, Phase1Manager, Phase2Manager)
    initializeSyncSystem();

    // 4. Componentes UI (ConsoleManager - debe ir después de PollingManager)
    // ConsoleManager se suscribe a eventos de PollingManager, por eso va al final
    initializeUIComponents();

    // Nota: Otros módulos se inicializan automáticamente:
    // - SyncDashboard se inicializa automáticamente en SyncDashboard.js
    // - UnifiedDashboardController se inicializa automáticamente en UnifiedDashboardController.js

    // eslint-disable-next-line no-console
    console.log('✅ Dashboard inicializado correctamente');
  });

  // ========================================
  // FUNCIONES DE INICIALIZACIÓN
  // ========================================

  /**
   * Inicializar sistemas core
   * 
   * Verifica y expone globalmente los sistemas base del dashboard.
   */
  function initializeCoreSystems() {
    // eslint-disable-next-line no-console
    console.log('🔧 Inicializando sistemas core...');

    // ✅ NUEVO: Verificar que PollingManager esté disponible
    // PollingManager se crea automáticamente como instancia global en PollingManager.js
    // pero debemos asegurarnos de que esté disponible antes de que ConsoleManager se suscriba
    if (typeof window !== 'undefined' && typeof window.pollingManager === 'undefined') {
      // eslint-disable-next-line no-console
      console.warn('  ⚠️  PollingManager no está disponible. Verificando carga...');
      // Esperar un poco y verificar de nuevo (puede estar cargándose)
      setTimeout(function() {
        if (typeof window.pollingManager !== 'undefined') {
          // eslint-disable-next-line no-console
          console.log('  ✅ PollingManager disponible después de esperar');
        } else {
          // eslint-disable-next-line no-console
          console.error('  ❌ PollingManager no está disponible después de esperar');
        }
      }, 100);
    } else if (typeof window !== 'undefined' && window.pollingManager) {
      // eslint-disable-next-line no-console
      console.log('  ✅ PollingManager disponible');
    }

    // SELECTORS ya está disponible globalmente (cargado como dependencia)
    // Verificación ya realizada arriba, solo asegurar exposición en window
    // Nota: Usamos window en lugar de globalThis para compatibilidad con WordPress
    // eslint-disable-next-line no-restricted-globals
    if (window !== undefined && window.SELECTORS === undefined && typeof SELECTORS !== 'undefined') {
      // eslint-disable-next-line no-restricted-globals
      window.SELECTORS = SELECTORS;
    }
    // eslint-disable-next-line no-console
    console.log('  ✅ SELECTORS inicializado');

    // DASHBOARD_CONFIG ya está disponible globalmente (cargado como dependencia)
    // Verificación ya realizada arriba, solo asegurar exposición en window
    // Nota: Usamos window en lugar de globalThis para compatibilidad con WordPress
    // eslint-disable-next-line no-restricted-globals
    if (window !== undefined && window.DASHBOARD_CONFIG === undefined && typeof DASHBOARD_CONFIG !== 'undefined') {
      // eslint-disable-next-line no-restricted-globals
      window.DASHBOARD_CONFIG = DASHBOARD_CONFIG;
    }
    // eslint-disable-next-line no-console
    console.log('  ✅ DASHBOARD_CONFIG inicializado');

    // ErrorHandler ya está disponible globalmente (cargado como dependencia)
    // Verificación ya realizada arriba, solo asegurar exposición en window
    // Nota: Usamos window en lugar de globalThis para compatibilidad con WordPress
    // eslint-disable-next-line no-restricted-globals
    if (window !== undefined && window.ErrorHandler === undefined) {
      // eslint-disable-next-line no-restricted-globals
      window.ErrorHandler = ErrorHandler;
    }
    // eslint-disable-next-line no-console
    console.log('  ✅ ErrorHandler inicializado');

    // AjaxManager ya está disponible globalmente (cargado como dependencia)
    // Verificación ya realizada arriba, solo asegurar exposición en window
    // Nota: Usamos window en lugar de globalThis para compatibilidad con WordPress
    // eslint-disable-next-line no-restricted-globals
    if (window !== undefined && window.AjaxManager === undefined) {
      // eslint-disable-next-line no-restricted-globals
      window.AjaxManager = AjaxManager;
    }
    // eslint-disable-next-line no-console
    console.log('  ✅ AjaxManager inicializado');

    // SystemEventManager ya está disponible globalmente (cargado como dependencia)
    // Verificación ya realizada arriba, inicializar y emitir eventos
    // eslint-disable-next-line no-restricted-globals
    if (window !== undefined && window.SystemEventManager === undefined) {
      // eslint-disable-next-line no-restricted-globals
      window.SystemEventManager = SystemEventManager;
    }
    // Inicializar el sistema de eventos
    if (typeof SystemEventManager !== 'undefined') {
      SystemEventManager.init();
      SystemEventManager.emitErrorHandlerReady();
      // eslint-disable-next-line no-console
      console.log('  ✅ SystemEventManager inicializado');
    }
  }

  /**
   * Inicializar managers del sistema
   * 
   * Inicializa los gestores del sistema (PollingManager, SyncStateManager, etc.)
   */
  function initializeManagers() {
    // eslint-disable-next-line no-console
    console.log('📊 Inicializando managers...');

    // PollingManager - Gestor de polling unificado
    // NOTA: PollingManager se crea automáticamente como instancia global en PollingManager.js
    // Solo verificamos que esté disponible
    if (typeof window !== 'undefined' && window.pollingManager) {
      // eslint-disable-next-line no-console
      console.log('  ✅ PollingManager disponible');
    } else {
      // eslint-disable-next-line no-console
      console.warn('  ⚠️  PollingManager no está disponible');
    }

    // SyncStateManager - Gestor de estado de sincronización
    if (typeof SyncStateManager !== 'undefined' && SyncStateManager && typeof SyncStateManager.cleanupOnPageLoad === 'function') {
      SyncStateManager.cleanupOnPageLoad();
      // eslint-disable-next-line no-console
      console.log('  ✅ SyncStateManager inicializado');
    } else {
      // eslint-disable-next-line no-console
      console.warn('  ⚠️  SyncStateManager no está disponible');
    }

    // NonceManager - Gestor de renovación de nonces
    if (typeof NonceManager !== 'undefined' && NonceManager && typeof NonceManager.setupAutoRenewal === 'function') {
      NonceManager.setupAutoRenewal();
      // eslint-disable-next-line no-console
      console.log('  ✅ NonceManager inicializado');
    } else {
      // eslint-disable-next-line no-console
      console.warn('  ⚠️  NonceManager no está disponible');
    }
  }

  /**
   * Inicializar sistema de sincronización
   * 
   * Inicializa los componentes relacionados con la sincronización.
   */
  function initializeSyncSystem() {
    // eslint-disable-next-line no-console
    console.log('🔄 Inicializando sistema de sincronización...');

    // SyncProgress - Verificación de progreso de sincronización
    if (typeof SyncProgress !== 'undefined' && SyncProgress && typeof SyncProgress.check === 'function') {
      // Exponer checkSyncProgress globalmente para compatibilidad
      if (typeof window !== 'undefined' && typeof window.checkSyncProgress === 'undefined') {
        // eslint-disable-next-line no-restricted-globals
        window.checkSyncProgress = SyncProgress.check;
      }
      // eslint-disable-next-line no-console
      console.log('  ✅ SyncProgress inicializado');
    } else {
      // eslint-disable-next-line no-console
      console.warn('  ⚠️  SyncProgress no está disponible');
    }

    // Phase1Manager - Gestor de Fase 1 (sincronización de imágenes)
    if (typeof window !== 'undefined' && window.Phase1Manager) {
      // eslint-disable-next-line no-console
      console.log('  ✅ Phase1Manager disponible');
    } else {
      // eslint-disable-next-line no-console
      console.warn('  ⚠️  Phase1Manager no está disponible');
    }

    // Phase2Manager - Gestor de Fase 2 (sincronización de productos)
    if (typeof window !== 'undefined' && window.Phase2Manager) {
      // eslint-disable-next-line no-console
      console.log('  ✅ Phase2Manager disponible');
    } else {
      // eslint-disable-next-line no-console
      console.warn('  ⚠️  Phase2Manager no está disponible');
    }
  }

  /**
   * Inicializar componentes UI
   * 
   * Inicializa los componentes de interfaz de usuario del dashboard.
   * IMPORTANTE: ConsoleManager debe inicializarse DESPUÉS de PollingManager
   * para que pueda suscribirse a eventos correctamente.
   */
  function initializeUIComponents() {
    // eslint-disable-next-line no-console
    console.log('🎨 Inicializando componentes UI...');

    // ConsoleManager - Consola de sincronización en tiempo real
    // ✅ CORRECCIÓN: Esperar a que ConsoleManager esté disponible (puede cargarse después)
    initializeConsoleManager();
  }

  /**
   * Inicializar ConsoleManager con verificación de disponibilidad
   * 
   * Intenta inicializar ConsoleManager, esperando si es necesario a que se cargue.
   * 
   * @returns {void}
   * @private
   */
  function initializeConsoleManager() {
    // ✅ VERIFICACIÓN ADICIONAL: Verificar si el script se ejecutó
    // Buscar en la consola si hay algún log de ConsoleManager
    const scriptElement = typeof document !== 'undefined' 
      ? document.querySelector('script[src*="ConsoleManager.js"]')
      : null;
    
    if (scriptElement) {
      // Verificar si el script tiene el atributo async o defer que podría estar causando problemas
      const isAsync = scriptElement.hasAttribute('async');
      const isDefer = scriptElement.hasAttribute('defer');
      
      // eslint-disable-next-line no-console
      console.log('[dashboard.js] Información del script ConsoleManager:', {
        scriptSrc: scriptElement.src,
        isAsync,
        isDefer,
        scriptLoaded: scriptElement !== null,
        scriptReadyState: scriptElement.readyState || 'N/A',
        hasOnLoad: scriptElement.onload !== null,
        hasOnError: scriptElement.onerror !== null
      });
      
      // ✅ ELIMINADO: Carga manual del script
      // Ya no es necesario porque el script ahora está envuelto en un IIFE
      // que previene redeclaraciones si se carga múltiples veces
    }
    
    // Función auxiliar para intentar inicializar
    function tryInitialize() {
      // Verificar disponibilidad
      const hasConsoleManager = typeof ConsoleManager !== 'undefined' && ConsoleManager && typeof ConsoleManager.initialize === 'function';
      const hasWindowConsoleManager = typeof window !== 'undefined' && window.ConsoleManager && typeof window.ConsoleManager.initialize === 'function';
      const hasPollingManager = typeof window !== 'undefined' && window.pollingManager;

      // eslint-disable-next-line no-console
      console.log('[dashboard.js] Verificando ConsoleManager...', {
        hasConsoleManager,
        hasWindowConsoleManager,
        hasPollingManager,
        ConsoleManagerType: typeof ConsoleManager,
        windowConsoleManagerType: typeof window !== 'undefined' ? typeof window.ConsoleManager : 'window undefined',
        windowKeys: typeof window !== 'undefined' ? Object.keys(window).filter(key => key.toLowerCase().includes('console')) : []
      });

      if (hasConsoleManager || hasWindowConsoleManager) {
        // ConsoleManager está disponible, inicializar
        const consoleManager = hasConsoleManager ? ConsoleManager : window.ConsoleManager;
        
        if (hasPollingManager) {
          consoleManager.initialize();
          // eslint-disable-next-line no-console
          console.log('  ✅ ConsoleManager inicializado (con PollingManager disponible)');
        } else {
          consoleManager.initialize();
          // eslint-disable-next-line no-console
          console.log('  ⚠️  ConsoleManager inicializado sin PollingManager (modo fallback)');
        }
        return true;
      }
      return false;
    }

    // Intentar inicializar inmediatamente
    if (tryInitialize()) {
      return;
    }

    // Si no está disponible, esperar un poco y reintentar
    // eslint-disable-next-line no-console
    console.warn('  ⚠️  ConsoleManager no está disponible inmediatamente, esperando...');
    
    let attempts = 0;
    const maxAttempts = 20; // 20 intentos = 2 segundos (aumentado para dar más tiempo)
    const checkInterval = setInterval(function() {
      attempts++;
      if (tryInitialize()) {
        clearInterval(checkInterval);
        // eslint-disable-next-line no-console
        console.log('  ✅ ConsoleManager disponible después de', attempts * 100, 'ms');
      } else if (attempts >= maxAttempts) {
        clearInterval(checkInterval);
        // Verificar si el script está cargado
        let scriptLoaded = false;
        let scriptSrc = null;
        if (typeof document !== 'undefined') {
          const scriptElement = document.querySelector('script[src*="ConsoleManager.js"]');
          scriptLoaded = scriptElement !== null;
          scriptSrc = scriptElement ? scriptElement.src : null;
        }
        
        // Verificar si hay errores de JavaScript en la consola
        const allScripts = typeof document !== 'undefined' 
          ? Array.from(document.querySelectorAll('script[src*="dashboard"]')).map(s => s.src)
          : [];
        
        // eslint-disable-next-line no-console
        console.error('  ❌ ConsoleManager no está disponible después de', maxAttempts * 100, 'ms', {
          ConsoleManager: typeof ConsoleManager,
          windowConsoleManager: typeof window !== 'undefined' ? typeof window.ConsoleManager : 'window undefined',
          scriptLoaded,
          scriptSrc,
          allDashboardScripts: allScripts,
          windowKeys: typeof window !== 'undefined' ? Object.keys(window).filter(key => 
            key.toLowerCase().includes('console') || 
            key.toLowerCase().includes('manager') ||
            key.toLowerCase().includes('sync')
          ).slice(0, 20) : [],
          suggestion: 'Verifica la consola del navegador para errores de JavaScript en ConsoleManager.js'
        });
      }
    }, 100);
  }

})();
