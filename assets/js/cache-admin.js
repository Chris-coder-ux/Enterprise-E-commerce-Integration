/**
 * JavaScript para la página de administración de caché
 * 
 * Maneja las interacciones del sidebar unificado y las acciones de caché
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
        if (typeof miIntegracionApiCache === 'undefined') {
            console.error('miIntegracionApiCache no está definido');
            return;
        }
        
        initCacheActions();
        initGroupSelector();
        initSearchFunctionality();
    });

    /**
     * Inicializa los botones de acciones rápidas del sidebar
     */
  function initCacheActions() {
    $('.unified-action-btn').on('click', function(e) {
      e.preventDefault();
            
      const action = $(this).data('action');
      const button = $(this);
            
            // Agregar estado de carga
      button.addClass('loading');
            
      switch(action) {
      case 'clear-cache':
        handleClearCache();
        break;
                    
      case 'toggle-cache':
        handleToggleCache();
        break;
                    
      case 'clear-group':
        showGroupSelector();
        break;
                    
      case 'refresh-stats':
        handleRefreshStats();
        break;
      }
            
            // Remover estado de carga después de un breve delay
      setTimeout(() => {
        button.removeClass('loading');
      }, 1000);
    });
  }

    /**
     * Maneja la acción de limpiar caché
     */
  function handleClearCache() {
    if (confirm(miIntegracionApiCache.confirmClear)) {
      const form = $('<form>', {
        method: 'POST',
        action: ''
      });
            
      form.append($('<input>', {
        type: 'hidden',
        name: 'mi_integracion_api_nonce',
        value: miIntegracionApiCache.nonce
      }));
            
      form.append($('<input>', {
        type: 'hidden',
        name: 'action',
        value: 'clear_cache'
      }));
            
      $('body').append(form);
      form.submit();
    }
  }

    /**
     * Maneja la acción de activar/desactivar caché
     */
  function handleToggleCache() {
    if (confirm(miIntegracionApiCache.confirmToggle)) {
      const form = $('<form>', {
        method: 'POST',
        action: ''
      });
            
      form.append($('<input>', {
        type: 'hidden',
        name: 'mi_integracion_api_nonce',
        value: miIntegracionApiCache.nonce
      }));
            
      form.append($('<input>', {
        type: 'hidden',
        name: 'action',
        value: 'toggle_cache'
      }));
            
      $('body').append(form);
      form.submit();
    }
  }

    /**
     * Maneja la actualización de estadísticas
     */
  function handleRefreshStats() {
    window.location.reload();
  }

    /**
     * Inicializa el selector de grupo
     */
  function initGroupSelector() {
        // Cerrar modal al hacer clic fuera
    $(document).on('click', '.cache-group-modal', function(e) {
      if (e.target === this) {
        $(this).remove();
      }
    });
        
        // Manejar envío del formulario de grupo
    $(document).on('submit', '.cache-group-form', function(e) {
      e.preventDefault();
            
      const form = $(this);
      const formData = form.serialize();
            
      $.post(miIntegracionApiCache.ajaxurl, formData)
                .done(function(response) {
                  if (response.success) {
                    showNotification('Grupo de caché limpiado correctamente', 'success');
                    $('.cache-group-modal').remove();
                        // Recargar página para actualizar estadísticas
                    setTimeout(() => {
                      window.location.reload();
                    }, 1000);
                  } else {
                    showNotification('Error al limpiar el grupo de caché', 'error');
                  }
                })
                .fail(function() {
                  showNotification('Error de conexión', 'error');
                });
    });
  }

    /**
     * Muestra el modal de selección de grupo
     */
  function showGroupSelector() {
    const modal = $(`
            <div class="cache-group-modal">
                <div class="modal-content">
                    <h3>Seleccionar Grupo de Caché</h3>
                    <form class="cache-group-form" method="post" action="">
                        <input type="hidden" name="mi_integracion_api_nonce" value="${miIntegracionApiCache.nonce}">
                        <input type="hidden" name="action" value="clear_group">
                        <select name="cache_group" required class="mi-integracion-api-select">
                            <option value="">Seleccionar grupo...</option>
                            <option value="products">Productos</option>
                            <option value="categories">Categorías</option>
                            <option value="clients">Clientes</option>
                            <option value="prices">Precios</option>
                            <option value="stock">Stock</option>
                        </select>
                        <div class="modal-actions">
                            <button type="submit" class="mi-integracion-api-button primary">
                                Limpiar Grupo
                            </button>
                            <button type="button" class="mi-integracion-api-button secondary modal-close">
                                Cancelar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        `);
        
    $('body').append(modal);
        
        // Manejar cierre del modal
    modal.find('.modal-close').on('click', function() {
      modal.remove();
    });
  }

    /**
     * Muestra una notificación al usuario
     */
  function showNotification(message, type = 'info') {
    const notification = $(`
            <div class="cache-notification cache-notification-${type}">
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

    /**
     * Maneja el reset de valores predeterminados
     */
  window.resetToDefaults = function() {
    if (confirm('¿Está seguro de que desea restablecer todos los valores a los predeterminados?')) {
      const defaultValues = {
        GetArticulosWS: 3600,
        GetCategoriasArticulosWS: 7200,
        GetClientesWS: 3600,
        GetPreciosArticulosWS: 1800,
        GetStockArticulosWS: 300
      };
            
            // Establecer valores predeterminados en el formulario
      for (const endpoint in defaultValues) {
        const ttlInput = document.querySelector(`input[name="cache_ttl[${endpoint}]"]`);
        const enabledInput = document.querySelector(`input[name="cache_enabled[${endpoint}]"]`);
                
        if (ttlInput) {
          ttlInput.value = defaultValues[endpoint];
        }
                
        if (enabledInput) {
          enabledInput.checked = true;
        }
      }
        }
    };

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
        
        // Limpiar búsqueda al hacer clic en el input
        searchInput.on('focus', function() {
            if ($(this).val().trim() === '') {
                clearSearchResults();
            }
        });
    }

    /**
     * Realiza la búsqueda en la caché
     */
    function performSearch() {
        const searchTerm = $('.unified-search-input').val().trim();
        const searchButton = $('.unified-search-button');
        
        if (!searchTerm) {
            showNotification('Por favor, ingresa un término de búsqueda', 'info');
            return;
        }
        
        // Agregar estado de búsqueda
        searchButton.addClass('searching');
        
        // Simular búsqueda (aquí puedes implementar la lógica real)
        setTimeout(() => {
            searchButton.removeClass('searching');
            
            // Mostrar resultados de búsqueda
            showSearchResults(searchTerm);
            
            showNotification(`Búsqueda completada para: "${searchTerm}"`, 'success');
        }, 1500);
    }

    /**
     * Muestra los resultados de búsqueda
     */
    function showSearchResults(searchTerm) {
        // Crear modal de resultados
        const resultsModal = $(`
            <div class="cache-search-results-modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Resultados de Búsqueda</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="search-results">
                        <p class="search-term">Buscando: <strong>"${searchTerm}"</strong></p>
                        <div class="results-list">
                            <div class="result-item">
                                <span class="result-icon">🗂️</span>
                                <div class="result-content">
                                    <h4>Cache Entry 1</h4>
                                    <p>Grupo: productos | TTL: 3600s</p>
                                </div>
                            </div>
                            <div class="result-item">
                                <span class="result-icon">🗂️</span>
                                <div class="result-content">
                                    <h4>Cache Entry 2</h4>
                                    <p>Grupo: categorías | TTL: 7200s</p>
                                </div>
                            </div>
                        </div>
                        <div class="search-actions">
                            <button class="mi-integracion-api-button secondary" onclick="this.closest('.cache-search-results-modal').remove()">
                                Cerrar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `);
        
        $('body').append(resultsModal);
        
        // Manejar cierre del modal
        resultsModal.find('.modal-close, .mi-integracion-api-button').on('click', function() {
            resultsModal.remove();
        });
    }

    /**
     * Limpia los resultados de búsqueda
     */
    function clearSearchResults() {
        $('.cache-search-results-modal').remove();
    }

})(jQuery);