/**
 * Sidebar Unificado - Mi Integración API
 * JavaScript para funcionalidades del sidebar unificado
 * 
 * @package MiIntegracionApi
 * @subpackage Admin
 */

(function($) {
  'use strict';

    /**
     * Clase principal del Sidebar Unificado
     */
  class UnifiedSidebar {
    constructor() {
      this.sidebar = $('.mi-integracion-api-sidebar');
      this.sidebarToggle = $('.sidebar-toggle');
      this.sidebarContent = $('.unified-sidebar-content');
      this.navLinks = $('.unified-nav-link');
      this.actionBtns = $('.unified-action-btn');
      this.searchInput = $('.unified-search-input');
      this.themeSwitcher = $('#theme-switcher');
      this.precisionInput = $('#precision');
      this.floatingToggle = null; // Botón flotante para cuando el sidebar está oculto
      this.isSidebarVisible = true; // Estado de visibilidad (para UnifiedDashboardController)
            
      this.init();
    }

        /**
         * Inicializa todas las funcionalidades del sidebar
         */
    init() {
      console.log('Inicializando UnifiedSidebar...');
      this.bindEvents();
      this.setActiveMenuItem();
      this.loadSavedSettings();
      console.log('UnifiedSidebar inicializado correctamente');
    }

        /**
         * Vincula todos los eventos
         */
    bindEvents() {
            // Toggle del sidebar
      this.sidebarToggle.on('click', this.toggleSidebar.bind(this));

            // Navegación activa
      this.navLinks.on('click', this.setActiveNavItem.bind(this));

            // Acciones rápidas
      this.actionBtns.on('click', this.handleQuickAction.bind(this));

            // Búsqueda en menú
      this.searchInput.on('input', this.handleSearch.bind(this));

            // Cambio de tema
      console.log('Vinculando evento de cambio de tema...', this.themeSwitcher.length, 'elementos encontrados');
      this.themeSwitcher.on('change', this.handleThemeChange.bind(this));

            // Configuración de precisión
      this.precisionInput.on('change', this.handlePrecisionChange.bind(this));

            // Eventos de teclado
      $(document).on('keydown', this.handleKeyboardShortcuts.bind(this));

            // Redimensionamiento de ventana
      $(window).on('resize', this.handleResize.bind(this));
    }

        /**
         * Toggle del sidebar (colapsar/expandir u ocultar/mostrar)
         */
    toggleSidebar() {
      // Si el sidebar está completamente oculto (display: none), mostrarlo
      if (!this.sidebar.is(':visible')) {
        this.showSidebar();
        return;
      }
      
      // Si está visible, colapsarlo (mantener visible pero reducido)
      this.sidebar.toggleClass('collapsed');
            
            // Cambiar icono
      const icon = this.sidebarToggle.find('i');
      if (this.sidebar.hasClass('collapsed')) {
        icon.removeClass('fa-chevron-left').addClass('fa-chevron-right');
      } else {
        icon.removeClass('fa-chevron-right').addClass('fa-chevron-left');
      }

            // Guardar estado
      this.saveSidebarState();
            
            // Trigger evento personalizado
      $(document).trigger('sidebar:toggled', [this.sidebar.hasClass('collapsed')]);
    }
    
    /**
     * Ocultar sidebar completamente (usado por UnifiedDashboardController)
     */
    hideSidebar() {
      const self = this;
      this.sidebar.fadeOut(300, function() {
        // Crear botón flotante si no existe
        self.createFloatingToggle();
      });
      this.isSidebarVisible = false;
      this.saveSidebarState('hidden');
    }
    
    /**
     * Mostrar sidebar (usado por UnifiedDashboardController)
     */
    showSidebar() {
      const self = this;
      // Ocultar botón flotante si existe
      if (this.floatingToggle && this.floatingToggle.length) {
        this.floatingToggle.fadeOut(200, function() {
          $(this).remove();
          self.floatingToggle = null;
        });
      }
      
      this.sidebar.fadeIn(300);
      this.sidebar.removeClass('collapsed');
      // Restaurar icono
      const icon = this.sidebarToggle.find('i');
      icon.removeClass('fa-chevron-right').addClass('fa-chevron-left');
      this.isSidebarVisible = true;
      this.saveSidebarState('visible');
    }
    
    /**
     * Crear botón flotante para mostrar el sidebar cuando está oculto
     */
    createFloatingToggle() {
      // Si ya existe, no crear otro
      if (this.floatingToggle && this.floatingToggle.length) {
        this.floatingToggle.fadeIn(200);
        return;
      }
      
      // Crear botón flotante
      this.floatingToggle = $('<button>')
        .addClass('sidebar-toggle-floating')
        .attr('title', 'Mostrar menú')
        .html('<i class="fas fa-bars"></i>')
        .css({
          position: 'fixed',
          top: '32px',
          left: '20px',
          zIndex: 10000,
          background: '#667eea',
          color: 'white',
          border: 'none',
          borderRadius: '50%',
          width: '50px',
          height: '50px',
          cursor: 'pointer',
          boxShadow: '0 4px 12px rgba(0, 0, 0, 0.15)',
          transition: 'all 0.3s ease',
          display: 'none',
          alignItems: 'center',
          justifyContent: 'center',
          fontSize: '20px'
        })
        .on('click', () => {
          this.showSidebar();
        })
        .on('mouseenter', function() {
          $(this).css({
            background: '#5568d3',
            transform: 'scale(1.1)',
            boxShadow: '0 6px 16px rgba(0, 0, 0, 0.2)'
          });
        })
        .on('mouseleave', function() {
          $(this).css({
            background: '#667eea',
            transform: 'scale(1)',
            boxShadow: '0 4px 12px rgba(0, 0, 0, 0.15)'
          });
        });
      
      $('body').append(this.floatingToggle);
      this.floatingToggle.fadeIn(200);
    }

        /**
         * Establece el elemento de menú activo
         */
    setActiveMenuItem() {
      const currentPage = this.getCurrentPage();
      this.navLinks.removeClass('active');
      this.navLinks.filter(`[data-page="${currentPage}"]`).addClass('active');
    }

        /**
         * Maneja el clic en elementos de navegación
         */
    setActiveNavItem(e) {
      e.preventDefault();
            
            // Remover activo de todos los elementos
      this.navLinks.removeClass('active');
            
            // Activar elemento actual
      $(e.currentTarget).addClass('active');
            
            // Obtener URL del enlace
      const url = $(e.currentTarget).attr('href');
            
            // Navegar si es necesario
      if (url && url !== '#') {
        window.location.href = url;
      }
    }

        /**
         * Maneja las acciones rápidas
         */
    handleQuickAction(e) {
      e.preventDefault();
            
      const action = $(e.currentTarget).data('action');
      const button = $(e.currentTarget);
            
            // Añadir efecto de clic
      button.addClass('clicked');
      setTimeout(() => button.removeClass('clicked'), 200);
            
            // Ejecutar acción específica
      switch(action) {
      case 'sync':
        this.handleSyncAction();
        break;
      case 'refresh':
        this.handleRefreshAction();
        break;
      case 'export':
        this.handleExportAction();
        break;
      case 'settings':
        this.handleSettingsAction();
        break;
      default:
        console.warn('Acción no reconocida:', action);
      }
    }

        /**
         * Maneja la búsqueda en el menú
         */
    handleSearch(e) {
      const searchTerm = $(e.currentTarget).val().toLowerCase();
      const navItems = $('.unified-nav-item');
            
      navItems.each(function() {
        const linkText = $(this).find('.nav-text').text().toLowerCase();
        if (linkText.includes(searchTerm)) {
          $(this).show();
        } else {
          $(this).hide();
        }
      });
    }

        /**
         * Maneja el cambio de tema
         */
    handleThemeChange(e) {
      const theme = $(e.currentTarget).val();
      console.log('Tema cambiado a:', theme);
      console.log('Elemento que disparó el evento:', e.currentTarget);
            
            // Aplicar tema
      this.applyTheme(theme);
            
            // Guardar configuración
      this.saveSettings('theme', theme);
            
            // Trigger evento personalizado
      $(document).trigger('theme:changed', [theme]);
    }

        /**
         * Maneja el cambio de precisión
         */
    handlePrecisionChange(e) {
      const precision = $(e.currentTarget).val();
      console.log('Precisión cambiada a:', precision);
            
            // Guardar configuración
      this.saveSettings('precision', precision);
            
            // Trigger evento personalizado
      $(document).trigger('precision:changed', [precision]);
    }

        /**
         * Maneja atajos de teclado
         */
    handleKeyboardShortcuts(e) {
            // Ctrl/Cmd + B para toggle del sidebar
      if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
        e.preventDefault();
        this.toggleSidebar();
      }
            
            // Escape para cerrar búsqueda
      if (e.key === 'Escape') {
        this.searchInput.val('').trigger('input');
        this.searchInput.blur();
      }
    }

        /**
         * Maneja el redimensionamiento de ventana
         */
    handleResize() {
      const windowWidth = $(window).width();
            
            // En móviles, expandir sidebar automáticamente
      if (windowWidth <= 768) {
        this.sidebar.removeClass('collapsed');
        this.sidebarToggle.find('i').removeClass('fa-chevron-right').addClass('fa-chevron-left');
      }
    }

        /**
         * Obtiene la página actual
         */
    getCurrentPage() {
      const url = window.location.href;
      const pageMatch = url.match(/[?&]page=([^&]+)/);
      return pageMatch ? pageMatch[1] : 'dashboard';
    }

        /**
         * Aplica un tema específico
         */
    applyTheme(theme) {
      console.log('Aplicando tema:', theme);
      console.log('Body antes:', $('body').attr('class'));
            
            // Remover clases de tema existentes
      $('body').removeClass('theme-dark theme-light theme-default');
            
            // Aplicar nuevo tema
      if (theme !== 'default') {
        $('body').addClass(`theme-${theme}`);
        console.log('Clase añadida:', `theme-${theme}`);
      }
            
      console.log('Body después:', $('body').attr('class'));
    }

        /**
         * Guarda el estado del sidebar
         */
    saveSidebarState(state) {
      if (state === 'hidden' || state === 'visible') {
        // Estado de visibilidad completa (usado por UnifiedDashboardController)
        localStorage.setItem('dashboard-sidebar-visible', state === 'visible');
      } else {
        // Estado de colapsado (usado por toggle normal)
        const isCollapsed = this.sidebar.hasClass('collapsed');
        localStorage.setItem('mia_sidebar_collapsed', isCollapsed);
      }
    }

        /**
         * Carga el estado guardado del sidebar
         */
    loadSidebarState() {
      const isCollapsed = localStorage.getItem('mia_sidebar_collapsed') === 'true';
      if (isCollapsed) {
        this.sidebar.addClass('collapsed');
        this.sidebarToggle.find('i').removeClass('fa-chevron-left').addClass('fa-chevron-right');
      }
    }

        /**
         * Guarda configuraciones
         */
    saveSettings(key, value) {
      const settings = this.getSettings();
      settings[key] = value;
      localStorage.setItem('mia_sidebar_settings', JSON.stringify(settings));
    }

        /**
         * Obtiene configuraciones guardadas
         */
    getSettings() {
      const settings = localStorage.getItem('mia_sidebar_settings');
      return settings ? JSON.parse(settings) : {};
    }

        /**
         * Carga configuraciones guardadas
         */
    loadSavedSettings() {
      const settings = this.getSettings();
            
            // Aplicar tema guardado
      if (settings.theme) {
        this.themeSwitcher.val(settings.theme);
        this.applyTheme(settings.theme);
      }
            
            // Aplicar precisión guardada
      if (settings.precision) {
        this.precisionInput.val(settings.precision);
      }
            
            // Cargar estado del sidebar
      // Primero verificar si está oculto completamente (UnifiedDashboardController)
      const dashboardSidebarVisible = localStorage.getItem('dashboard-sidebar-visible');
      if (dashboardSidebarVisible === 'false') {
        // Sidebar está oculto completamente, crear botón flotante
        this.sidebar.hide();
        this.isSidebarVisible = false;
        this.createFloatingToggle();
      } else {
        // Cargar estado de colapsado normal
        this.loadSidebarState();
      }
    }

        /**
         * Acción de sincronización
         */
    handleSyncAction() {
      if (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard.confirmSync) {
        if (confirm(miIntegracionApiDashboard.confirmSync)) {
          this.triggerSync();
        }
      } else {
        this.triggerSync();
      }
    }

        /**
         * Dispara la sincronización
         */
    triggerSync() {
            // Buscar botón de sincronización en el dashboard
      const syncButton = $('#mi-batch-sync-products');
      if (syncButton.length) {
        syncButton.trigger('click');
      } else {
                // Fallback: recargar página
        window.location.reload();
      }
    }

        /**
         * Acción de actualización
         */
    handleRefreshAction() {
      window.location.reload();
    }

        /**
         * Acción de exportación
         */
    handleExportAction() {
            // Implementar lógica de exportación
      console.log('Exportando datos...');
      alert('Funcionalidad de exportación en desarrollo');
    }

        /**
         * Acción de configuración
         */
    handleSettingsAction() {
            // Navegar a página de configuración de caché (configuración principal)
      window.location.href = 'admin.php?page=mi-integracion-api-cache';
    }

        /**
         * API pública para controlar el sidebar
         */
    collapse() {
      if (!this.sidebar.hasClass('collapsed')) {
        this.toggleSidebar();
      }
    }

    expand() {
      if (this.sidebar.hasClass('collapsed')) {
        this.toggleSidebar();
      }
    }

    isCollapsed() {
      return this.sidebar.hasClass('collapsed');
    }
    }

    /**
     * Inicialización cuando el DOM está listo
     */
  $(document).ready(function() {
        // Inicializar sidebar unificado
    window.unifiedSidebar = new UnifiedSidebar();
        
        // Exponer API global
    window.MiIntegracionApiSidebar = {
      collapse: () => window.unifiedSidebar.collapse(),
      expand: () => window.unifiedSidebar.expand(),
      isCollapsed: () => window.unifiedSidebar.isCollapsed(),
      toggle: () => window.unifiedSidebar.toggleSidebar()
    };
  });

    /**
     * Eventos personalizados del sidebar
     */
  $(document).on('sidebar:toggled', function(e, isCollapsed) {
    console.log('Sidebar toggled:', isCollapsed ? 'collapsed' : 'expanded');
  });

  $(document).on('theme:changed', function(e, theme) {
    console.log('Theme changed to:', theme);
  });

  $(document).on('precision:changed', function(e, precision) {
    console.log('Precision changed to:', precision);
  });

})(jQuery);
