/**
 * Sistema de Layout Responsive
 *
 * Gestiona el layout responsive del dashboard, ajustando el sidebar y el grid
 * de estadísticas según el tamaño de la ventana y la orientación del dispositivo.
 *
 * @module ui/ResponsiveLayout
 * @namespace ResponsiveLayout
 * @since 1.0.0
 * @author Christian
 */

/* global jQuery, window */

/**
 * Sistema de layout responsive para el dashboard
 *
 * @namespace ResponsiveLayout
 * @description Gestiona el layout responsive del dashboard
 * @since 1.0.0
 */
const ResponsiveLayout = {
  timeout: null,

  /**
   * Ajusta el layout basado en el tamaño de la ventana
   * @function adjustLayout
   * @returns {void}
   */
  adjustLayout() {
    clearTimeout(this.timeout);
    this.timeout = setTimeout(() => {
      const $sidebar = jQuery('.mi-integracion-api-sidebar');

      // Ajustar sidebar en móviles
      // eslint-disable-next-line no-restricted-globals
      if (window.innerWidth < 769) {
        // Remover estilos sticky y ajustar altura
        $sidebar.css({
          'position': 'relative',
          'top': 'auto',
          'height': 'auto',
          'max-height': 'none'
        });
      } else {
        // Restaurar estilos sticky para desktop
        $sidebar.css({
          'position': 'sticky',
          'top': '20px',
          'height': 'fit-content',
          'max-height': 'none'
        });
      }

      // Ajustar grid de estadísticas basado en tamaño real
      const statsGrid = jQuery('.mi-integracion-api-stats-grid');
      const gridWidth = statsGrid.width();

      if (gridWidth < 400) {
        statsGrid.addClass('single-column');
      } else if (gridWidth < 600) {
        statsGrid.addClass('two-columns');
      } else if (gridWidth < 900) {
        statsGrid.addClass('three-columns');
      } else {
        statsGrid.removeClass('single-column two-columns three-columns');
      }
    }, 100);
  },

  /**
   * Inicializa el menú responsive
   * @function initResponsiveMenu
   * @returns {void}
   */
  initResponsiveMenu() {
    const $menu = jQuery('.mi-integracion-api-nav-menu ul');

    // eslint-disable-next-line no-restricted-globals
    if (window.innerWidth < 577) {
      // Añadir indicador de scroll horizontal
      $menu.addClass('scrollable-horizontal');
    } else {
      $menu.removeClass('scrollable-horizontal');
    }
  },

  /**
   * Inicializa el sistema responsive
   * @function init
   * @returns {void}
   */
  init() {
    const self = this;

    // Ejecutar al cargar y al redimensionar
    jQuery(document).ready(function() {
      self.adjustLayout();
      self.initResponsiveMenu();
    });

    // eslint-disable-next-line no-restricted-globals
    jQuery(window).resize(function() {
      self.adjustLayout();
      self.initResponsiveMenu();
    });

    // eslint-disable-next-line no-restricted-globals
    jQuery(window).on('orientationchange', function() {
      // Pequeño delay para que el navegador ajuste las dimensiones
      setTimeout(function() {
        self.adjustLayout();
        self.initResponsiveMenu();
      }, 100);
    });
  }
};

/**
 * Exponer ResponsiveLayout globalmente para mantener compatibilidad
 * con el código existente que usa window.ResponsiveLayout
 */
// eslint-disable-next-line no-restricted-globals
if (typeof window !== 'undefined') {
  try {
    // eslint-disable-next-line no-restricted-globals
    window.ResponsiveLayout = ResponsiveLayout;
  } catch (error) {
    try {
      // eslint-disable-next-line no-restricted-globals
      Object.defineProperty(window, 'ResponsiveLayout', {
        value: ResponsiveLayout,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar ResponsiveLayout a window:', defineError, error);
      }
    }
  }
}

// Exponer también directamente para uso sin window.
// Esto permite usar ResponsiveLayout.init() directamente como en la documentación
// eslint-disable-next-line no-restricted-globals
if (typeof globalThis !== 'undefined') {
  // eslint-disable-next-line no-restricted-globals
  globalThis.ResponsiveLayout = ResponsiveLayout;
} else if (typeof global !== 'undefined') {
  global.ResponsiveLayout = ResponsiveLayout;
} else if (typeof window !== 'undefined') {
  // Para navegadores, usar una función que exponga la variable
  (function() {
    // Crear una variable global directa
    // eslint-disable-next-line no-restricted-globals
    const globalScope = (function() { return this; })();
    if (globalScope) {
      globalScope.ResponsiveLayout = ResponsiveLayout;
    }
  })();
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { ResponsiveLayout };
}
