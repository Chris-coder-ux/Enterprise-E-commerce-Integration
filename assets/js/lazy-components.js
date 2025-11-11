/**
 * Script para manejo de componentes cargados de forma diferida
 * Este archivo se encarga de gestionar la carga de componentes cuando son necesarios
 */

jQuery(document).ready(function($) {
    
  // Objeto global para gestión de componentes
  window.miIntegracionApiLazyLoad = window.miIntegracionApiLazyLoad || {
    componentsLoaded: {},
        
    // Función para cargar un componente específico
    loadComponent: function(componentId) {
            
      if (this.componentsLoaded[componentId]) {
        return true;
      }
            
      this.componentsLoaded[componentId] = true;
            
      // Enviar solicitud AJAX para ejecutar el observador
      $.ajax({
        url: (typeof miIntegracionApi !== 'undefined' && miIntegracionApi.ajaxurl) ? miIntegracionApi.ajaxurl : ajaxurl,
        type: 'POST',
        data: {
          action: 'mi_integracion_api_lazyload',
          component: componentId,
          nonce: (typeof miIntegracionApi !== 'undefined' && miIntegracionApi.nonce) ? miIntegracionApi.nonce : ''
        },
        success: function(response) {
                    
          // Disparar evento personalizado
          $(document).trigger('miIntegracionApi:componentLoaded', [componentId, response]);
        },
        error: function(xhr, status, error) {
          console.error('Error al cargar el componente: ' + componentId);
          console.error(error);
        }
      });
            
      return true;
    }
  };
    
  // Detectar componente basado en la URL actual
  var currentUrl = window.location.href;
    
  // Deshabilitado temporalmente - no hay observadores registrados para estos componentes
  // if (currentUrl.includes('page=mi-integracion-api-logs')) {
  //   window.miIntegracionApiLazyLoad.loadComponent('logs-viewer');
  // }
    
  // if (currentUrl.includes('page=mi-integracion-api-endpoints')) {
  //   window.miIntegracionApiLazyLoad.loadComponent('endpoint-tester');
  // }
    
  // if (currentUrl.includes('page=mi-integracion-api-connection')) {
  //   window.miIntegracionApiLazyLoad.loadComponent('connection-checker');
  // }
});
