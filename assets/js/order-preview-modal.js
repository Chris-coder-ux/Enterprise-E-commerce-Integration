/**
 * Funcionalidad del Modal de Vista Previa de Pedidos
 * 
 * Maneja la visualización del JSON que se enviará a Verial
 */

(function($) {
  'use strict';
    
    // Variable global para almacenar el JSON actual
  let currentOrderData = null;
  let currentOrderId = null;
    
    /**
     * Inicializa el modal de vista previa
     */
  function initPreviewModal() {
        // Evento para abrir el modal desde los botones de vista previa
    $(document).on('click', '.preview-order-btn', function(e) {
      e.preventDefault();
      const orderId = $(this).data('order-id');
      openPreviewModal(orderId);
    });
        
        // Eventos para cerrar el modal
    $('#close-preview-modal, #close-preview-btn, .mi-modal-overlay').on('click', function() {
      closePreviewModal();
    });
        
        // Evento para cambiar de tab
    $('.preview-tab').on('click', function() {
      const tab = $(this).data('tab');
      switchTab(tab);
    });
        
        // Evento para copiar JSON
    $('#copy-json').on('click', function() {
      copyJsonToClipboard();
    });
        
        // Evento para descargar JSON
    $('#download-json').on('click', function() {
      downloadJson();
    });
        
        // Evento para sincronizar desde el modal
    $('#sync-from-preview').on('click', function() {
      syncOrderFromPreview();
    });
  }
    
    /**
     * Abre el modal y carga los datos del pedido
     */
  function openPreviewModal(orderId) {
    currentOrderId = orderId;
        
        // Mostrar el modal con loading
    $('#order-preview-modal').fadeIn(300);
    showLoading();
        
        // Hacer petición AJAX para obtener el JSON
    $.ajax({
      url: miIntegracionApiDashboard.ajaxurl,
      type: 'POST',
      data: {
        action: 'mi_integracion_preview_order_json',
        nonce: miIntegracionApiDashboard.nonce,
        order_id: orderId
      },
      success(response) {
        if (response.success) {
          currentOrderData = response.data;
          displayOrderPreview(response.data);
        } else {
          showError(response.data.message || 'Error al cargar la vista previa');
        }
      },
      error(xhr, status, error) {
        showError('Error de conexión: ' + error);
      }
    });
  }
    
    /**
     * Cierra el modal
     */
  function closePreviewModal() {
    $('#order-preview-modal').fadeOut(300);
    currentOrderData = null;
    currentOrderId = null;
  }
    
    /**
     * Muestra el loading en el modal
     */
  function showLoading() {
    const loadingHtml = `
            <div class="preview-loading">
                <div class="sync-spinner"></div>
                <p>Generando vista previa...</p>
            </div>
        `;
    $('.mi-modal-body').html(loadingHtml);
  }
    
    /**
     * Muestra un error en el modal
     */
  function showError(message) {
    const errorHtml = `
            <div class="preview-error">
                <span class="dashicons dashicons-warning"></span>
                <p>${message}</p>
            </div>
        `;
    $('.mi-modal-body').html(errorHtml);
  }
    
    /**
     * Muestra los datos del pedido en el modal
     */
  function displayOrderPreview(data) {
        // Actualizar información del pedido
    $('#preview-order-number').text('#' + data.order_info.number);
    $('#preview-customer').text(data.order_info.customer);
    $('#preview-total').text(data.order_info.total + ' ' + data.order_info.currency);
    $('#preview-status').html(getStatusBadge(data.order_info.status));
    $('#preview-date').text(data.order_info.date);
        
        // Mostrar score de completitud si existe
    if (data.sync_info.completeness_score) {
      const score = parseFloat(data.sync_info.completeness_score);
      const scoreClass = score >= 95 ? 'score-excellent' : score >= 80 ? 'score-good' : 'score-warning';
      $('#preview-completeness').html(`<span class="${scoreClass}">${score}%</span>`);
    } else {
      $('#preview-completeness').text('No calculado');
    }
        
        // Mostrar JSON formateado
    $('#json-preview code').text(data.json_pretty);
    $('#json-size').text(formatBytes(data.json_size));
        
        // Generar vista de estructura
    generateStructureView(data.verial_payload);
        
        // Generar vista de validación
    generateValidationView(data);
        
        // Aplicar syntax highlighting si está disponible
    if (typeof Prism !== 'undefined') {
      Prism.highlightElement($('#json-preview code')[0]);
    }
  }
    
    /**
     * Genera la vista de estructura del JSON
     */
  function generateStructureView(payload) {
    let html = '<div class="structure-section">';
        
        // Información del documento
    html += '<div class="structure-group">';
    html += '<h4><span class="dashicons dashicons-media-document"></span> Documento</h4>';
    html += '<ul class="structure-list">';
    html += `<li><strong>ID:</strong> ${payload.Id || 0}</li>`;
    html += `<li><strong>Tipo:</strong> ${payload.Tipo} (${getTipoDocumento(payload.Tipo)})</li>`;
    html += `<li><strong>Fecha:</strong> ${payload.Fecha}</li>`;
    html += `<li><strong>Total:</strong> ${payload.TotalImporte}</li>`;
    html += '</ul>';
    html += '</div>';
        
        // Información del cliente
    if (payload.Cliente) {
      html += '<div class="structure-group">';
      html += '<h4><span class="dashicons dashicons-admin-users"></span> Cliente</h4>';
      html += '<ul class="structure-list">';
      html += `<li><strong>Nombre:</strong> ${payload.Cliente.Nombre} ${payload.Cliente.Apellido1 || ''}</li>`;
      html += `<li><strong>Email:</strong> ${payload.Cliente.Email}</li>`;
      html += `<li><strong>Teléfono:</strong> ${payload.Cliente.Telefono || '-'}</li>`;
      html += `<li><strong>Dirección:</strong> ${payload.Cliente.Direccion || '-'}</li>`;
      html += '</ul>';
      html += '</div>';
    }
        
        // Líneas de contenido
    if (payload.Contenido && payload.Contenido.length > 0) {
      html += '<div class="structure-group">';
      html += `<h4><span class="dashicons dashicons-cart"></span> Productos (${payload.Contenido.length})</h4>`;
      html += '<ul class="structure-list">';
      payload.Contenido.forEach((item, index) => {
        html += `<li><strong>Artículo ${index + 1}:</strong> ID ${item.ID_Articulo} - ${item.Uds} uds × ${item.Precio}€ = ${item.ImporteLinea}€</li>`;
      });
      html += '</ul>';
      html += '</div>';
    }
        
        // Pagos
    if (payload.Pagos && payload.Pagos.length > 0) {
      html += '<div class="structure-group">';
      html += `<h4><span class="dashicons dashicons-money-alt"></span> Pagos (${payload.Pagos.length})</h4>`;
      html += '<ul class="structure-list">';
      payload.Pagos.forEach((pago, index) => {
        html += `<li><strong>Pago ${index + 1}:</strong> ${pago.Importe}€ - ${pago.Fecha}</li>`;
      });
      html += '</ul>';
      html += '</div>';
    }
        
    html += '</div>';
    $('#structure-preview').html(html);
  }
    
    /**
     * Genera la vista de validación
     */
  function generateValidationView(data) {
    let html = '<div class="validation-section">';
        
        // Estado de sincronización
    html += '<div class="validation-group">';
    html += '<h4><span class="dashicons dashicons-update"></span> Estado de Sincronización</h4>';
    if (data.sync_info.synced) {
      html += '<div class="validation-success">';
      html += '<span class="dashicons dashicons-yes-alt"></span>';
      html += '<p>Pedido sincronizado con Verial</p>';
      html += `<small>ID Verial: ${data.sync_info.verial_id} | ${data.sync_info.sync_timestamp}</small>`;
      html += '</div>';
    } else {
      html += '<div class="validation-warning">';
      html += '<span class="dashicons dashicons-warning"></span>';
      html += '<p>Pedido no sincronizado</p>';
      if (data.sync_info.sync_error) {
        html += `<small>Error: ${data.sync_info.sync_error}</small>`;
      }
      html += '</div>';
    }
    html += '</div>';
        
        // Completitud de datos
    if (data.sync_info.completeness_score) {
      const score = parseFloat(data.sync_info.completeness_score);
      html += '<div class="validation-group">';
      html += '<h4><span class="dashicons dashicons-chart-bar"></span> Completitud de Datos</h4>';
      html += '<div class="completeness-bar">';
      html += `<div class="completeness-fill" style="width: ${score}%"></div>`;
      html += `<span class="completeness-label">${score}%</span>`;
      html += '</div>';
      if (score >= 95) {
        html += '<p class="validation-success"><span class="dashicons dashicons-yes-alt"></span> Excelente completitud de datos</p>';
      } else if (score >= 80) {
        html += '<p class="validation-warning"><span class="dashicons dashicons-info"></span> Completitud aceptable, algunos campos opcionales faltan</p>';
      } else {
        html += '<p class="validation-error"><span class="dashicons dashicons-warning"></span> Completitud baja, revisa los datos del pedido</p>';
      }
      html += '</div>';
    }
        
        // Campos principales
    html += '<div class="validation-group">';
    html += '<h4><span class="dashicons dashicons-yes-alt"></span> Campos Principales</h4>';
    html += '<ul class="validation-checklist">';
    html += getValidationItem('Cliente', data.verial_payload.Cliente);
    html += getValidationItem('Contenido', data.verial_payload.Contenido && data.verial_payload.Contenido.length > 0);
    html += getValidationItem('Pagos', data.verial_payload.Pagos && data.verial_payload.Pagos.length > 0);
    html += getValidationItem('Método de Pago', data.verial_payload.ID_MetodoPago || data.verial_payload.Pagos);
    html += getValidationItem('Forma de Envío', data.verial_payload.ID_FormaEnvio);
    html += getValidationItem('Agentes', data.verial_payload.ID_Agente1);
    html += '</ul>';
    html += '</div>';
        
    html += '</div>';
    $('#validation-preview').html(html);
  }
    
    /**
     * Genera un item de validación
     */
  function getValidationItem(label, isValid) {
    const icon = isValid ? 'yes-alt' : 'warning';
    const className = isValid ? 'valid' : 'invalid';
    return `<li class="${className}"><span class="dashicons dashicons-${icon}"></span> ${label}</li>`;
  }
    
    /**
     * Cambia de tab
     */
  function switchTab(tab) {
    $('.preview-tab').removeClass('active');
    $(`.preview-tab[data-tab="${tab}"]`).addClass('active');
        
    $('.preview-tab-panel').removeClass('active');
    $(`#tab-${tab}`).addClass('active');
  }
    
    /**
     * Copia el JSON al portapapeles
     */
  function copyJsonToClipboard() {
    if (!currentOrderData) return;
        
    const jsonText = currentOrderData.json_pretty;
        
    if (navigator.clipboard) {
      navigator.clipboard.writeText(jsonText).then(() => {
        showNotification('JSON copiado al portapapeles', 'success');
      }).catch(() => {
        fallbackCopyToClipboard(jsonText);
      });
    } else {
      fallbackCopyToClipboard(jsonText);
    }
  }
    
    /**
     * Método fallback para copiar al portapapeles
     */
  function fallbackCopyToClipboard(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
      document.execCommand('copy');
      showNotification('JSON copiado al portapapeles', 'success');
    } catch (err) {
      showNotification('Error al copiar al portapapeles', 'error');
    }
    document.body.removeChild(textarea);
  }
    
    /**
     * Descarga el JSON como archivo
     */
  function downloadJson() {
    if (!currentOrderData) return;
        
    const jsonText = currentOrderData.json_pretty;
    const blob = new Blob([jsonText], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `pedido-${currentOrderData.order_info.number}-verial.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
        
    showNotification('JSON descargado correctamente', 'success');
  }
    
    /**
     * Sincroniza el pedido desde el modal
     */
  function syncOrderFromPreview() {
    if (!currentOrderId) return;
        
    if (!confirm('¿Estás seguro de que deseas sincronizar este pedido con Verial?')) {
      return;
    }
        
        // Deshabilitar botón
    $('#sync-from-preview').prop('disabled', true).text('Sincronizando...');
        
        // Llamar a la función de sincronización existente
    $.ajax({
      url: miIntegracionApiDashboard.ajaxurl,
      type: 'POST',
      data: {
        action: 'mi_integracion_sync_single_order',
        nonce: miIntegracionApiDashboard.nonce,
        order_id: currentOrderId
      },
      success(response) {
        if (response.success) {
          showNotification('Pedido sincronizado correctamente', 'success');
          closePreviewModal();
           // Recargar la tabla de pedidos si existe el objeto orderSyncMonitor
           // eslint-disable-next-line no-undef
          const global = typeof globalThis !== 'undefined' ? globalThis : window;
          if (typeof global.orderSyncMonitor !== 'undefined' && typeof global.orderSyncMonitor.loadOrders === 'function') {
            global.orderSyncMonitor.loadOrders();
          }
           // Trigger evento personalizado para que otros componentes puedan reaccionar
          $(document).trigger('order-synced', [currentOrderId]);
        } else {
          showNotification(response.data.message || 'Error al sincronizar', 'error');
          $('#sync-from-preview').prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sincronizar Ahora');
        }
      },
      error() {
        showNotification('Error de conexión al sincronizar', 'error');
        $('#sync-from-preview').prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sincronizar Ahora');
      }
    });
  }
    
    /**
     * Muestra una notificación
     */
  function showNotification(message, type) {
    const className = type === 'success' ? 'notice-success' : 'notice-error';
    const notification = $(`
            <div class="notice ${className} is-dismissible" style="position: fixed; top: 32px; right: 20px; z-index: 999999; max-width: 400px;">
                <p>${message}</p>
            </div>
        `);
        
    $('body').append(notification);
        
    setTimeout(() => {
      notification.fadeOut(300, function() {
        $(this).remove();
      });
    }, 3000);
  }
    
    /**
     * Obtiene el badge de estado
     */
  function getStatusBadge(status) {
    const badges = {
      'pending': '<span class="status-badge status-pending">Pendiente</span>',
      'processing': '<span class="status-badge status-processing">Procesando</span>',
      'completed': '<span class="status-badge status-completed">Completado</span>',
      'cancelled': '<span class="status-badge status-cancelled">Cancelado</span>',
      'failed': '<span class="status-badge status-failed">Fallido</span>'
    };
    return badges[status] || `<span class="status-badge">${status}</span>`;
  }
    
    /**
     * Obtiene el nombre del tipo de documento
     */
  function getTipoDocumento(tipo) {
    const tipos = {
      1: 'Presupuesto',
      2: 'Albarán',
      3: 'Factura',
      4: 'Factura Rectificativa',
      5: 'Pedido',
      6: 'Ticket'
    };
    return tipos[tipo] || 'Desconocido';
  }
    
    /**
     * Formatea bytes a tamaño legible
     */
  function formatBytes(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
  }
    
    // Inicializar cuando el documento esté listo
  $(document).ready(function() {
    initPreviewModal();
  });
    
})(jQuery);

