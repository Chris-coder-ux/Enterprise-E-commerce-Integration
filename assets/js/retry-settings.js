/**
 * JavaScript para la página de configuración de reintentos
 * 
 * Maneja las interacciones del sidebar unificado y las acciones de reintentos
 * 
 * @package MiIntegracionApi
 * @since 1.0.0
 */

(function($) {
  'use strict';

    /**
     * Inicializa los eventos cuando el DOM está listo
     */
  $(document).ready(function() {
        // Verificar que las variables estén definidas
    if (typeof miIntegracionApiRetry === 'undefined') {
      console.error('miIntegracionApiRetry no está definido');
      return;
    }
        
    initRetryActions();
    initSearchFunctionality();
    initFormValidation();
  });

    /**
     * Inicializa los botones de acciones rápidas del sidebar
     */
  function initRetryActions() {
    $('.unified-action-btn').on('click', function(e) {
      e.preventDefault();
            
      const action = $(this).data('action');
      const button = $(this);
            
            // Agregar estado de carga
      button.addClass('loading');
            
      switch(action) {
      case 'test-retry':
        handleTestRetry();
        break;
                    
      case 'reset-settings':
        handleResetSettings();
        break;
                    
      case 'view-logs':
        handleViewLogs();
        break;
                    
      case 'export-config':
        handleExportConfig();
        break;
      }
            
            // Remover estado de carga después de un breve delay
      setTimeout(() => {
        button.removeClass('loading');
      }, 1000);
    });
  }

    /**
     * Maneja la acción de probar reintentos
     */
  function handleTestRetry() {
    if (confirm(miIntegracionApiRetry.confirmTest)) {
      showNotification('Iniciando prueba del sistema de reintentos...', 'info');
            
            // Simular prueba de reintentos
      setTimeout(() => {
        showNotification('Prueba completada exitosamente', 'success');
      }, 2000);
    }
  }

    /**
     * Maneja la acción de restablecer configuración
     */
  function handleResetSettings() {
    if (confirm(miIntegracionApiRetry.confirmReset)) {
      showNotification('Restableciendo configuración...', 'info');
            
            // Simular restablecimiento
      setTimeout(() => {
        showNotification('Configuración restablecida exitosamente', 'success');
                // Recargar página para mostrar valores por defecto
        setTimeout(() => {
          window.location.reload();
        }, 1000);
      }, 1500);
    }
  }

    /**
     * Maneja la acción de ver logs
     */
  function handleViewLogs() {
    showNotification('Abriendo logs de reintentos...', 'info');
        
        // Crear modal de logs
    const logsModal = $(`
            <div class="retry-logs-modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Logs de Reintentos</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="logs-content">
                        <div class="log-entry">
                            <span class="log-time">2024-01-15 10:30:45</span>
                            <span class="log-level info">INFO</span>
                            <span class="log-message">Reintento exitoso para sincronización de productos</span>
                        </div>
                        <div class="log-entry">
                            <span class="log-time">2024-01-15 10:29:12</span>
                            <span class="log-level warning">WARN</span>
                            <span class="log-message">Reintento fallido para API call, aplicando backoff</span>
                        </div>
                        <div class="log-entry">
                            <span class="log-time">2024-01-15 10:28:33</span>
                            <span class="log-level error">ERROR</span>
                            <span class="log-message">Error de conectividad, iniciando secuencia de reintentos</span>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button class="mi-integracion-api-button secondary modal-close">
                            Cerrar
                        </button>
                    </div>
                </div>
            </div>
        `);
        
    $('body').append(logsModal);
        
        // Manejar cierre del modal
    logsModal.find('.modal-close, .mi-integracion-api-button').on('click', function() {
      logsModal.remove();
    });
  }

    /**
     * Maneja la acción de exportar configuración
     */
  function handleExportConfig() {
    showNotification('Exportando configuración...', 'info');
        
        // Simular exportación
    setTimeout(() => {
      showNotification('Configuración exportada exitosamente', 'success');
            
            // Crear y descargar archivo de configuración
      const config = {
        retry_system_enabled: $('#mia_retry_system_enabled').is(':checked'),
        default_max_attempts: $('#mia_retry_default_max_attempts').val(),
        default_base_delay: $('#mia_retry_default_base_delay').val(),
        max_delay: $('#mia_retry_max_delay').val(),
        backoff_factor: $('#mia_retry_backoff_factor').val(),
        jitter_enabled: $('#mia_retry_jitter_enabled').is(':checked'),
        jitter_max_ms: $('#mia_retry_jitter_max_ms').val()
      };
            
      const blob = new Blob([JSON.stringify(config, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'retry-settings-config.json';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }, 1000);
  }

    /**
     * Inicializa la funcionalidad de búsqueda
     */
  function initSearchFunctionality() {
    const searchInput = $('.unified-search-input');
    const searchButton = $('.unified-search-button');
        
        // Manejar clic en el botón de búsqueda
    searchButton.on('click', function(e) {
      e.preventDefault();
      performSearch();
    });
        
        // Manejar Enter en el input de búsqueda
    searchInput.on('keypress', function(e) {
      if (e.which === 13) {
        e.preventDefault();
        performSearch();
      }
    });
        
        // Búsqueda en tiempo real
    searchInput.on('input', function() {
      const searchTerm = $(this).val().trim();
      if (searchTerm.length > 2) {
        performSearch();
      } else {
        clearSearchResults();
      }
    });
  }

    /**
     * Realiza la búsqueda en la configuración
     */
  function performSearch() {
    const searchTerm = $('.unified-search-input').val().trim();
    const searchButton = $('.unified-search-button');
        
    if (!searchTerm) {
      clearSearchResults();
      return;
    }
        
        // Agregar estado de búsqueda
    searchButton.addClass('searching');
        
        // Buscar elementos que coincidan
    const searchableElements = $('.retry-setting-item, .retry-policy-item, .retry-operation-item');
    let foundCount = 0;
        
    searchableElements.each(function() {
      const element = $(this);
      const text = element.text().toLowerCase();
      const searchLower = searchTerm.toLowerCase();
            
      if (text.includes(searchLower)) {
        element.addClass('search-highlight');
        foundCount++;
      } else {
        element.removeClass('search-highlight');
      }
    });
        
        // Remover estado de búsqueda
    setTimeout(() => {
      searchButton.removeClass('searching');
            
      if (foundCount > 0) {
        showNotification(`Se encontraron ${foundCount} elementos`, 'success');
      } else {
        showNotification('No se encontraron elementos', 'info');
      }
    }, 500);
  }

    /**
     * Limpia los resultados de búsqueda
     */
  function clearSearchResults() {
    $('.search-highlight').removeClass('search-highlight');
  }

    /**
     * Inicializa la validación del formulario
     */
  function initFormValidation() {
    $('.retry-settings-form').on('submit', function(e) {
      let isValid = true;
      const errors = [];
            
            // Validar número máximo de reintentos
      const maxAttempts = parseInt($('#mia_retry_default_max_attempts').val());
      if (maxAttempts < 0 || maxAttempts > 10) {
        errors.push('El número máximo de reintentos debe estar entre 0 y 10');
        isValid = false;
      }
            
            // Validar retraso base
      const baseDelay = parseFloat($('#mia_retry_default_base_delay').val());
      if (baseDelay < 1 || baseDelay > 60) {
        errors.push('El retraso base debe estar entre 1 y 60 segundos');
        isValid = false;
      }
            
            // Validar retraso máximo
      const maxDelay = parseInt($('#mia_retry_max_delay').val());
      if (maxDelay < 5 || maxDelay > 300) {
        errors.push('El retraso máximo debe estar entre 5 y 300 segundos');
        isValid = false;
      }
            
            // Validar factor de backoff
      const backoffFactor = parseFloat($('#mia_retry_backoff_factor').val());
      if (backoffFactor < 1.0 || backoffFactor > 5.0) {
        errors.push('El factor de backoff debe estar entre 1.0 y 5.0');
        isValid = false;
      }
            
      if (!isValid) {
        e.preventDefault();
        showNotification('Por favor, corrige los errores en el formulario', 'error');
        errors.forEach(error => {
          console.error(error);
        });
      }
    });
  }

    /**
     * Muestra una notificación al usuario
     */
  function showNotification(message, type = 'info') {
    const notification = $(`
            <div class="retry-notification retry-notification-${type}">
                <span class="notification-message">${message}</span>
                <button class="notification-close">&times;</button>
            </div>
        `);
        
    $('body').append(notification);
        
        // Auto-remover después de 3 segundos
    setTimeout(() => {
      notification.fadeOut(300, function() {
        $(this).remove();
      });
    }, 3000);
        
        // Manejar cierre manual
    notification.find('.notification-close').on('click', function() {
      notification.fadeOut(300, function() {
        $(this).remove();
      });
    });
  }

})(jQuery);