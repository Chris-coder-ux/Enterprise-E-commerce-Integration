/**
 * Script mejorado para la página de endpoints
 * Compatible con clases antiguas (verial-) y nuevas (mi-integracion-api-)
 * Incluye soporte para toast, JSON formatting y UX mejorada
 */
jQuery(document).ready(function($) {
  // Inicialización
  // Ahora usamos el sistema de toast moderno de utils-modern.js
  var endpointToast = {
    show: function(options) {
      // Mapear a nuestro sistema de toast moderno
      var type = options.type || 'info';
      var message = options.message || '';
      var duration = options.duration || 3000;
      
      // Usar el sistema de toast moderno si está disponible
      if (MiIntegracionAPI && MiIntegracionAPI.utils && MiIntegracionAPI.utils.toast) {
        MiIntegracionAPI.utils.toast.show({
          type: type,
          message: message,
          duration: duration
        });
      } else {
        // Fallback al método antiguo
        $('.endpoint-toast').remove();
        var $toast = $('<div class="endpoint-toast ' + type + '">' + message + '</div>');
        $('body').append($toast);
        setTimeout(function() {
          $toast.remove();
        }, duration);
      }
    }
  };
  
  // Registrar objeto toast global (para compatibilidad)
  window.verialToast = endpointToast;
  
  // Gestión de pestañas
  $('.mi-integracion-api-tab-link').on('click', function(e) {
    e.preventDefault();
    var target = $(this).data('tab');
    
    // Actualizar estado activo de las pestañas
    $('.mi-integracion-api-tab-link').removeClass('active');
    $(this).addClass('active');
    
    // Mostrar contenido de la pestaña
    $('.mi-integracion-api-tab-content').removeClass('active').hide();
    $('#' + target).addClass('active').show();
  });
  
  // Formato bonito para JSON usando utils-modern.js
  function formatJsonField(value) {
    try {
      if (typeof value === 'string' && (value.startsWith('{') || value.startsWith('['))) {
        var obj = JSON.parse(value);
        
        // Usar la utilidad moderna de formateo si está disponible
        if (MiIntegracionAPI && MiIntegracionAPI.utils && MiIntegracionAPI.utils.formatter) {
          return '<div class="json-field">' + MiIntegracionAPI.utils.formatter.prettyJSON(obj) + '</div>';
        } else {
          // Fallback al método antiguo
          return '<div class="json-field">' + JSON.stringify(obj, null, 2) + '</div>';
        }
      }
      return value;
    } catch (e) {
      return value;
    }
  }
  
  // Endpoint AJAX
  $('#mi-endpoint-form').on('submit', function(e) {
    e.preventDefault();
    var endpoint = $('#mi_endpoint_select').val();
    var param = $('#mi_endpoint_param').val();
    var $feedback = $('#mi-endpoint-feedback');
    var $table = $('#mi-endpoint-result-table');
    
    // Mostrar indicador de carga
    $feedback.html('<div class="endpoint-loading">' + miEndpointsPage.loading + '</div>');
    $table.hide().empty();
    
    // Ejecutar la llamada AJAX
    $.ajax({
      url: miEndpointsPage.ajaxurl,
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'mi_test_endpoint',
        endpoint: endpoint,
        param: param,
        nonce: miEndpointsPage.nonce
      },
      success: function(response) {
        if (response.success && response.data && response.data.length) {
          // Limpiar feedback y mostrar toast
          $feedback.html('');
          endpointToast.show({type: 'success', message: miEndpointsPage.success});
          
          // Construir tabla con datos
          var keys = Object.keys(response.data[0]);
          var html = '<thead><tr>';
          keys.forEach(function(k) { html += '<th>' + k + '</th>'; });
          html += '</tr></thead><tbody>';
          
          response.data.forEach(function(row) {
            html += '<tr>';
            keys.forEach(function(k) { 
              var cellValue = row[k] !== undefined ? row[k] : '';
              html += '<td>' + formatJsonField(cellValue) + '</td>'; 
            });
            html += '</tr>';
          });
          
          html += '</tbody>';
          $table.html(html).fadeIn(300);
          
          // Añadir conteo de resultados
          $feedback.html('<div class="notice notice-success"><p>' + 
            miEndpointsPage.resultsCount.replace('{count}', response.data.length) + 
            '</p></div>');
            
        } else if (response.success && response.data) {
          // Para respuestas que no son arrays
          $feedback.html('');
          endpointToast.show({type: 'success', message: miEndpointsPage.success});
          
          // Formatear respuesta como JSON
          var jsonStr = JSON.stringify(response.data, null, 2);
          $feedback.html('<div class="notice notice-success"><p>' + miEndpointsPage.success + '</p><pre>' + jsonStr + '</pre></div>');
        } else {
          // Manejo de errores
          var errorMsg = response.data && response.data.message ? response.data.message : miEndpointsPage.error;
          $feedback.html('<div class="notice notice-error"><p>' + errorMsg + '</p></div>');
          endpointToast.show({type: 'error', message: errorMsg});
          $table.hide();
        }
      },
      error: function(xhr) {
        var errorMsg = miEndpointsPage.error;
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          errorMsg = xhr.responseJSON.data.message;
        }
        $feedback.html('<div class="notice notice-error"><p>' + errorMsg + '</p></div>');
        endpointToast.show({type: 'error', message: errorMsg});
        $table.hide();
      }
    });
  });
  
  // Select2 no está disponible - usar select nativo
  // El select nativo ya tiene estilos modernos aplicados via CSS
  
  // Manejar cambios en el endpoint seleccionado
  $('#mi_endpoint_select').on('change', function() {
    var endpoint = $(this).val();
    
    // Configurar placeholder según el tipo de endpoint
    var placeholder = miEndpointsPage.paramPlaceholder || 'Parámetro (opcional)';
    
    switch(endpoint) {
      case 'get_articulos':
        placeholder = 'ID o código de artículo (opcional)';
        break;
      case 'get_clientes':
        placeholder = 'ID o código de cliente (opcional)';
        break;
      case 'get_stock':
        placeholder = 'Código de artículo';
        break;
      case 'get_condiciones_tarifa':
        placeholder = 'Código de artículo';
        break;
    }
    
    $('#mi_endpoint_param').attr('placeholder', placeholder);
  });
  
  // Mostrar mensaje "Copiar al portapapeles" cuando se hace clic en un valor JSON
  $(document).on('click', '.json-field', function() {
    var text = $(this).text();
    navigator.clipboard.writeText(text).then(function() {
      endpointToast.show({
        type: 'success',
        message: miEndpointsPage.copied || 'Copiado al portapapeles',
        duration: 1500
      });
    });
  });
  
  /**
   * Mejoras específicas para endpoints POST
   * Añade funcionalidad para validar y formatear JSON
   */
   
  // Validación de formato JSON en el campo de parámetros
  $('#mi_endpoint_param').on('blur', function() {
    const value = $(this).val().trim();
    if (!value) return; // No validar si está vacío
    
    try {
      // Intentar parsear el JSON
      const parsed = JSON.parse(value);
      // Si es válido, formatear bonito
      $(this).val(JSON.stringify(parsed, null, 2));
    } catch (e) {
      // Mostrar error de formato
      endpointToast.show({
        type: 'error',
        message: 'Formato JSON inválido en los parámetros',
        duration: 5000
      });
      // Focus en el campo para corregir
      $(this).focus();
    }
  });
  
  // Función específica para manejar respuestas de operaciones POST
  function processPostResponse(response) {
    if (!response) return '<div class="notice notice-error">Respuesta vacía del servidor</div>';
    
    let html = '<div class="post-response">';
    
    // Determinar si es éxito o error
    const isSuccess = response.success || 
                     (response.status && (response.status === 'success' || response.status === 200)) ||
                     (!response.error);
                     
    if (isSuccess) {
      html += '<div class="response-success">';
      html += '<h4><span class="dashicons dashicons-yes-alt"></span> Operación completada</h4>';
    } else {
      html += '<div class="response-error">';
      html += '<h4><span class="dashicons dashicons-warning"></span> Error en la operación</h4>';
    }
    
    // Formatear el contenido de la respuesta
    html += '<pre class="json-field">' + JSON.stringify(response, null, 2) + '</pre>';
    html += '</div></div>';
    
    return html;
  }
});
