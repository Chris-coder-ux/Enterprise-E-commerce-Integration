/**
 * JavaScript para la página de monitoreo de memoria
 * 
 * Maneja las interacciones del sidebar unificado y las acciones de memoria
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
    if (typeof miIntegracionApiMemory === 'undefined') {
      console.error('miIntegracionApiMemory no está definido');
      return;
    }
        
    initMemoryActions();
    initSearchFunctionality();
    initFormValidation();
    initMemoryChart();
    initAutoRefresh();
  });

    /**
     * Inicializa los botones de acciones rápidas del sidebar
     */
  function initMemoryActions() {
    $('.unified-action-btn').on('click', function(e) {
      e.preventDefault();
            
      const action = $(this).data('action');
      const button = $(this);
            
            // Agregar estado de carga
      button.addClass('loading');
            
      switch(action) {
      case 'cleanup-memory':
        handleCleanupMemory();
        break;
                    
      case 'refresh-stats':
        handleRefreshStats();
        break;
                    
      case 'reset-history':
        handleResetHistory();
        break;
                    
      case 'export-stats':
        handleExportStats();
        break;
      }
            
            // Remover estado de carga después de un breve delay
      setTimeout(() => {
        button.removeClass('loading');
      }, 1000);
    });
  }

    /**
     * Maneja la acción de limpiar memoria
     */
  function handleCleanupMemory() {
    if (confirm(miIntegracionApiMemory.confirmCleanup)) {
      showNotification('Limpiando memoria...', 'info');
            
            // Simular limpieza de memoria
      setTimeout(() => {
        showNotification('Memoria limpiada exitosamente', 'success');
                // Recargar página para mostrar estadísticas actualizadas
        setTimeout(() => {
          window.location.reload();
        }, 1000);
      }, 2000);
    }
  }

    /**
     * Maneja la acción de refrescar estadísticas
     */
  function handleRefreshStats() {
    showNotification('Refrescando estadísticas...', 'info');
        
        // Simular refresco
    setTimeout(() => {
      showNotification('Estadísticas actualizadas', 'success');
      window.location.reload();
    }, 1500);
  }

    /**
     * Maneja la acción de resetear historial
     */
  function handleResetHistory() {
    if (confirm(miIntegracionApiMemory.confirmReset)) {
      showNotification('Reseteando historial...', 'info');
            
            // Simular reset
      setTimeout(() => {
        showNotification('Historial reseteado exitosamente', 'success');
                // Recargar página para mostrar historial limpio
        setTimeout(() => {
          window.location.reload();
        }, 1000);
      }, 1500);
    }
  }

    /**
     * Maneja la acción de exportar estadísticas
     */
  function handleExportStats() {
    showNotification('Exportando estadísticas...', 'info');
        
        // Simular exportación
    setTimeout(() => {
      showNotification('Estadísticas exportadas exitosamente', 'success');
            
            // Crear y descargar archivo de estadísticas
      const stats = {
        timestamp: new Date().toISOString(),
        memory_usage: $('.usage-text strong').text(),
        peak_memory: $('.peak-value strong').text(),
        status: $('.status-info h3').text(),
        recommendations: []
      };
            
      const blob = new Blob([JSON.stringify(stats, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'memory-stats-' + new Date().toISOString().split('T')[0] + '.json';
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
    const searchableElements = $('.mi-integracion-api-card, .form-group, .recommendation-item, .alert-item');
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
    $('.memory-config-form').on('submit', function(e) {
      let isValid = true;
      const errors = [];
            
            // Validar umbral de advertencia
      const warningThreshold = parseInt($('#mia_memory_warning_threshold').val());
      if (warningThreshold < 50 || warningThreshold > 95) {
        errors.push('El umbral de advertencia debe estar entre 50% y 95%');
        isValid = false;
      }
            
            // Validar umbral crítico
      const criticalThreshold = parseInt($('#mia_memory_critical_threshold').val());
      if (criticalThreshold < 70 || criticalThreshold > 98) {
        errors.push('El umbral crítico debe estar entre 70% y 98%');
        isValid = false;
      }
            
            // Validar umbral de limpieza
      const cleanupThreshold = parseInt($('#mia_memory_cleanup_threshold').val());
      if (cleanupThreshold < 60 || cleanupThreshold > 90) {
        errors.push('El umbral de limpieza debe estar entre 60% y 90%');
        isValid = false;
      }
            
            // Validar intervalo de refresco
      const refreshInterval = parseInt($('#mia_memory_dashboard_refresh_interval').val());
      if (refreshInterval < 10 || refreshInterval > 300) {
        errors.push('El intervalo de refresco debe estar entre 10 y 300 segundos');
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
     * Inicializa el gráfico de memoria
     */
  function initMemoryChart() {
    const canvas = document.getElementById('memoryChart');
    if (!canvas) return;
        
    const ctx = canvas.getContext('2d');
        
        // Datos de ejemplo para el gráfico
    const memoryData = {
      labels: ['00:00', '04:00', '08:00', '12:00', '16:00', '20:00'],
      datasets: [{
        label: 'Uso de Memoria (%)',
        data: [45, 52, 48, 65, 58, 42],
        borderColor: '#3498db',
        backgroundColor: 'rgba(52, 152, 219, 0.1)',
        borderWidth: 2,
        fill: true,
        tension: 0.4
      }]
    };
        
        // Crear gráfico simple con canvas
    drawMemoryChart(ctx, memoryData);
  }

    /**
     * Dibuja el gráfico de memoria
     */
  function drawMemoryChart(ctx, data) {
    const width = ctx.canvas.width;
    const height = ctx.canvas.height;
    const padding = 40;
    const chartWidth = width - (padding * 2);
    const chartHeight = height - (padding * 2);
        
        // Limpiar canvas
    ctx.clearRect(0, 0, width, height);
        
        // Dibujar fondo
    ctx.fillStyle = 'rgba(255, 255, 255, 0.1)';
    ctx.fillRect(0, 0, width, height);
        
        // Dibujar líneas de referencia
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.2)';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
      const y = padding + (chartHeight / 4) * i;
      ctx.beginPath();
      ctx.moveTo(padding, y);
      ctx.lineTo(width - padding, y);
      ctx.stroke();
    }
        
        // Dibujar línea de datos
    ctx.strokeStyle = '#3498db';
    ctx.lineWidth = 3;
    ctx.beginPath();
        
    data.datasets[0].data.forEach((value, index) => {
      const x = padding + (chartWidth / (data.labels.length - 1)) * index;
      const y = padding + chartHeight - (chartHeight * value / 100);
            
      if (index === 0) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    });
        
    ctx.stroke();
        
        // Dibujar puntos
    ctx.fillStyle = '#3498db';
    data.datasets[0].data.forEach((value, index) => {
      const x = padding + (chartWidth / (data.labels.length - 1)) * index;
      const y = padding + chartHeight - (chartHeight * value / 100);
            
      ctx.beginPath();
      ctx.arc(x, y, 4, 0, 2 * Math.PI);
      ctx.fill();
    });
        
        // Dibujar etiquetas
    ctx.fillStyle = 'white';
    ctx.font = '12px Inter, sans-serif';
    ctx.textAlign = 'center';
        
    data.labels.forEach((label, index) => {
      const x = padding + (chartWidth / (data.labels.length - 1)) * index;
      ctx.fillText(label, x, height - 10);
    });
  }

    /**
     * Inicializa el refresco automático
     */
  function initAutoRefresh() {
    const refreshInterval = parseInt($('#mia_memory_dashboard_refresh_interval').val()) || 30;
        
    if (refreshInterval > 0) {
      setInterval(() => {
                // Solo refrescar si no hay búsqueda activa
        if ($('.unified-search-input').val().trim() === '') {
          handleRefreshStats();
        }
      }, refreshInterval * 1000);
    }
  }

    /**
     * Muestra una notificación al usuario
     */
  function showNotification(message, type = 'info') {
    const notification = $(`
            <div class="memory-notification memory-notification-${type}">
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