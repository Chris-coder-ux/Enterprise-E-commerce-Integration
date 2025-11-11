/**
 * Dashboard de Detección Automática - JavaScript
 * Funcionalidades interactivas para el panel de administración
 */

// Variables globales para la integración con WordPress
// window.miaDetectionData será definido por wp_localize_script() de WordPress

// ========================================
// FUNCIONES GLOBALES PARA EL TEMPLATE HTML
// ========================================

/**
 * Abrir modal de solicitud de producto
 * Función global eliminada - se maneja directamente desde bindMenuEvents()
 * No es necesaria porque solo se usa con data-action en el HTML
 */

/**
 * Cerrar modal de solicitud de producto
 * Función global eliminada - se maneja directamente desde bindMenuEvents()
 * con data-action="closeProductRequestModal" en el HTML
 */

/**
 * Vista previa de la solicitud
 */
function previewProductRequest() {
  if (window.dashboard) {
    window.dashboard.previewProductRequest();
  }
}



// Función para verificar que window.miaDetectionData esté disponible
function checkMiaDetectionData() {
  if (typeof window.miaDetectionData === 'undefined') {
    console.warn('miaDetectionData no está inicializado. Algunas funciones pueden no funcionar correctamente.');
    console.log('window.miaDetectionData:', typeof window.miaDetectionData);
    console.log('window keys:', Object.keys(window).filter(key => key.includes('mia')));
    return false;
  }
  
  if (!window.miaDetectionData.ajaxUrl || !window.miaDetectionData.nonce) {
    console.warn('miaDetectionData está definido pero incompleto:', window.miaDetectionData);
    return false;
  }
  
  return true;
}

// Función helper para hacer peticiones AJAX de forma segura
function safeAjaxRequest(action, data = {}) {
  if (!checkMiaDetectionData()) {
    return Promise.reject(new Error('miaDetectionData no está disponible'));
  }
  
  const requestData = Object.assign({
    action,
    nonce: window.miaDetectionData.nonce
  }, data);
  
  return fetch(window.miaDetectionData.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(requestData)
  });
}

// Definir module para compatibilidad con Node.js/CommonJS
if (typeof module === 'undefined') {
  const module = { exports: {} };
}

class DetectionDashboard {
  constructor() {
    this.isSystemActive = false; // Inicializar como inactivo
    this.syncProgress = 75;
    this.currentFilter = 'all';
    this.simulationInterval = null;
    
    // Lista de productos (se cargará dinámicamente)
    this.products = [];
    
    // Verificar que los datos de WordPress estén disponibles
    this.checkMiaDetectionData();
    
    this.init();
  }
  
  /**
   * Verificar que los datos de WordPress estén disponibles
   */
  checkMiaDetectionData() {
    return checkMiaDetectionData();
  }
    
  init() {
    this.bindEvents();
    this.updateProductList(this.products);
    this.loadSystemStatus(); // Cargar estado real del sistema
        
        // Cargar datos iniciales
    this.updateStats();
    this.updateDetectionProgress();
        
        // Inicializar sistema responsive
    this.bindDetectionResponsiveEvents();
    this.adjustDetectionLayout();
  }
    
  bindEvents() {
        // El toggle de detección ahora se maneja en bindMenuEvents()
        
        // Los event listeners de botones de acción ahora se manejan en bindMenuEvents()
        
        // Botones del menú lateral
    this.bindMenuEvents();
        
        // Los event listeners de configuración ahora se manejan en bindMenuEvents()
        
        // Los event listeners de filtros ahora se manejan en bindMenuEvents()
        
        // Los event listeners de modales ahora se manejan en bindMenuEvents()
        
        // Cerrar modal al hacer clic fuera
    window.addEventListener('click', (e) => {
      const modal = document.getElementById('settingsModal');
      if (e.target === modal) {
        this.closeSettings();
      }
    });
  }
    
  bindMenuEvents() {
    // Usar delegación de eventos para manejar todos los clics
    document.addEventListener('click', (e) => {
      const target = e.target.closest('[data-action]');
      if (!target) return;
      
      e.preventDefault();
      
      const action = target.dataset.action;
      console.log('Menu event triggered:', action, 'Target:', target);
      
      // Verificar que el dashboard esté disponible
      if (!window.dashboard) {
        console.error('Dashboard not available');
        return;
      }
      
      // Ejecutar la acción correspondiente
      switch (action) {
      // Navegación principal
      case 'showNotificationsPanel':
        window.dashboard.showNotificationsPanel();
        break;
      case 'showDocumentRequestsPanel':
        console.log('Opening document requests panel');
        window.dashboard.showDocumentRequestsPanel();
        break;
      case 'showNotificationConfigPanel':
        console.log('Opening notification config panel');
        window.dashboard.showNotificationConfigPanel();
        break;
      case 'openProductRequestModal':
        console.log('Opening product request modal');
        console.log('Dashboard available:', !!window.dashboard);
        console.log('Modal exists:', !!document.getElementById('product-request-modal'));
        // Obtener productId del atributo data-product-id si existe
        const productId = target.dataset.productId || null;
        window.dashboard.openProductRequestModal(productId);
        break;
        
      // Acciones principales
      case 'executeNow':
        window.dashboard.executeNow();
        break;
      case 'pauseDetection':
        window.dashboard.pauseDetection();
        break;
      case 'viewReport':
        window.dashboard.viewReport();
        break;
      case 'viewLowStock':
        window.dashboard.viewLowStock();
        break;
      case 'toggleDetection':
        window.dashboard.toggleDetection();
        break;
        
      // Configuración
      case 'openSettings':
        window.dashboard.openSettings();
        break;
      case 'closeSettings':
        window.dashboard.closeSettings();
        break;
      case 'saveSettings':
        window.dashboard.saveSettings();
        break;
        
        
      // Filtros
      case 'filterProducts': {
        const filter = target.dataset.filter;
        if (filter) {
          window.dashboard.filterProducts(filter);
        }
        break;
      }
        
      default:
        console.warn('Unknown action:', action);
      }
      
      // Actualizar estado activo para enlaces de navegación
      if (target.classList.contains('unified-nav-link')) {
        this.updateActiveNavItem(target);
      }
    });
  }
  
  updateActiveNavItem(activeLink) {
    // Remover clase active de todos los enlaces
    document.querySelectorAll('.unified-nav-link').forEach(link => {
      link.classList.remove('active');
    });
    
    // Añadir clase active al enlace clickeado
    activeLink.classList.add('active');
  }
    
  showMainDashboard() {
        // Ocultar todos los paneles
    this.hideAllPanels();
        // Mostrar el dashboard principal
    const mainContent = document.querySelector('.main-content .content');
    if (mainContent) {
      mainContent.style.display = 'block';
    }
  }
    
  showGeneralSettings() {
    this.openSettings();
  }
    
  showLogs() {
    this.showNotification('📋 Logs del sistema - En desarrollo', 'info');
  }
    
  showStatistics() {
    this.showNotification('📈 Estadísticas detalladas - En desarrollo', 'info');
  }
    
  hideAllPanels() {
        // Ocultar panel de notificaciones
    const notificationsPanel = document.getElementById('notifications-panel');
    if (notificationsPanel) {
      notificationsPanel.style.display = 'none';
    }
        
        // Ocultar panel de solicitudes de documentos
    const documentRequestsPanel = document.getElementById('document-requests-panel');
    if (documentRequestsPanel) {
      documentRequestsPanel.style.display = 'none';
    }
        
        // Ocultar panel de configuración de notificaciones
    const notificationConfigPanel = document.getElementById('notification-config-panel');
    if (notificationConfigPanel) {
      notificationConfigPanel.style.display = 'none';
    }
        
        // Ocultar modal de solicitud de productos
    const productRequestModal = document.getElementById('product-request-modal');
    if (productRequestModal) {
      productRequestModal.style.display = 'none';
      productRequestModal.classList.remove('show');
    }
  }
    
    /**
     * Sincroniza el estado visual de todos los botones de control
     */
  syncButtonStates() {
    const toggle = document.querySelector('.detection-modern-toggle') || document.querySelector('.modern-toggle');
    const status = document.querySelector('.detection-status-indicator span') || document.querySelector('.status-indicator span');
    const pauseBtn = document.querySelector('[onclick="pauseDetection()"]');
        
    if (toggle && status) {
      if (this.isSystemActive) {
        toggle.classList.add('active');
        status.textContent = 'Sistema Activo';
        if (pauseBtn) {
          pauseBtn.innerHTML = '⏸️ Pausar';
          pauseBtn.classList.remove('detection-btn-warning', 'btn-warning');
          pauseBtn.classList.add('detection-btn-outline', 'btn-outline');
        }
      } else {
        toggle.classList.remove('active');
        status.textContent = 'Sistema Inactivo';
        if (pauseBtn) {
          pauseBtn.innerHTML = '▶️ Reanudar';
          pauseBtn.classList.remove('detection-btn-outline', 'btn-outline');
          pauseBtn.classList.add('detection-btn-warning', 'btn-warning');
        }
      }
    }
  }
    
    /**
     * Carga el estado real del sistema de detección automática desde el backend
     */
  async loadSystemStatus() {
    try {
      const response = await safeAjaxRequest('mia_get_system_status');
            
      const data = await response.json();
            
      if (data.success) {
        this.isSystemActive = data.data.is_active || false;
        this.syncButtonStates();
      } else {
        console.error('Error cargando estado del sistema:', data.data);
                // Usar estado por defecto
        this.isSystemActive = false;
        this.syncButtonStates();
      }
    } catch (error) {
      console.error('Error en la petición:', error.message);
      // Usar estado por defecto
      this.isSystemActive = false;
      this.syncButtonStates();
    }
  }
    
  async toggleDetection() {
    try {
      // Determinar el nuevo estado (toggle)
      const newState = !this.isSystemActive;
            
      const response = await safeAjaxRequest('mia_toggle_detection', {
        activate: newState ? '1' : '0'
      });
            
      const data = await response.json();
            
      if (data.success) {
        this.isSystemActive = newState;
        this.syncButtonStates();
                
        if (newState) {
          this.startSimulation();
          this.showNotification('✅ Detección automática activada', 'success');
        } else {
          this.stopSimulation();
          this.showNotification('⏸️ Detección automática desactivada', 'info');
        }
      } else {
        this.showNotification(`❌ Error al ${newState ? 'activar' : 'desactivar'} la detección: ` + data.data, 'error');
      }
    } catch (error) {
      console.error(`Error ${this.isSystemActive ? 'desactivando' : 'activando'} detección:`, error);
      this.showNotification(`❌ Error de conexión al ${this.isSystemActive ? 'desactivar' : 'activar'} la detección`, 'error');
    }
  }
    
  executeNow() {
    this.showNotification('🚀 Iniciando detección manual...', 'info');
    this.simulateSyncProgress();
  }
    
  async pauseDetection() {
    try {
      const newState = !this.isSystemActive;
            
      const response = await safeAjaxRequest('mia_toggle_detection', {
        activate: newState ? '1' : '0'
      });
            
      const data = await response.json();
            
      if (data.success) {
        this.isSystemActive = newState;
        this.syncButtonStates();
                
        if (newState) {
          this.showNotification('▶️ Detección reanudada', 'info');
        } else {
          this.showNotification('⏸️ Detección pausada temporalmente', 'warning');
        }
      } else {
        this.showNotification('❌ Error al cambiar estado: ' + data.data, 'error');
      }
    } catch (error) {
      console.error('Error cambiando estado:', error);
      this.showNotification('❌ Error de conexión al cambiar estado', 'error');
    }
  }
    
  viewReport() {
    this.showNotification('📊 Generando reporte detallado...', 'info');
        // Aquí se abriría el reporte real
  }
    
  openSettings() {
    const modal = document.getElementById('settingsModal');
    if (modal) {
      modal.style.display = 'flex';
    }
  }
    
  closeSettings() {
    const modal = document.getElementById('settingsModal');
    if (modal) {
      modal.style.display = 'none';
    }
  }
    
  saveSettings() {
    this.showNotification('💾 Configuración guardada correctamente', 'success');
    this.closeSettings();
  }
    
  filterProducts(filter) {
    this.currentFilter = filter;
        
        // Actualizar botones de filtro - usar tanto la clase antigua como la nueva
    document.querySelectorAll('.detection-filter-btn, .filter-btn').forEach(btn => {
      btn.classList.remove('active');
    });
        
    const activeBtn = document.querySelector(`[data-filter="${filter}"]`);
    if (activeBtn) {
      activeBtn.classList.add('active');
    }
        
        // Filtrar productos
    const filteredProducts = filter === 'all' ? this.products : 
            this.products.filter(p => p.status === filter);
        
    this.updateProductList(filteredProducts);
  }
    
  updateProductList(productsToShow) {
    const productList = document.querySelector('.detection-product-list') || document.querySelector('.product-list');
    if (!productList) return;
        
    productList.innerHTML = '';
        
    productsToShow.forEach(product => {
      const productItem = this.createProductItem(product);
      productList.appendChild(productItem);
    });
  }
    
  createProductItem(product) {
    const item = document.createElement('div');
    item.className = 'detection-product-item';
        
    const statusClass = product.status === 'updated' ? 'updated' : 
                          product.status === 'new' ? 'new' : 
                          product.status === 'error' ? 'error' : 'pending';
        
    const stockClass = product.stockChange > 0 ? 'positive' : 
                          product.stockChange < 0 ? 'negative' : 'neutral';
        
    item.innerHTML = `
            <div class="detection-product-info">
                <div class="detection-product-name">${product.name}</div>
                <div class="detection-product-sku">SKU: ${product.sku}</div>
            </div>
            <div class="detection-product-status">
                <span class="detection-status-badge ${statusClass}">${this.getStatusText(product.status)}</span>
                <span class="detection-stock-change ${stockClass}">${product.stockChange > 0 ? '+' : ''}${product.stockChange}</span>
            </div>
        `;
        
    return item;
  }
    
  getStatusText(status) {
    const statusMap = {
      updated: 'Actualizado',
      new: 'Nuevo',
      error: 'Error',
      pending: 'Pendiente'
    };
    return statusMap[status] || status;
  }
    
    
    
  showNotification(message, type) {
        // Crear notificación temporal
    const notification = document.createElement('div');
    notification.className = `detection-alert-banner ${type}`;
    notification.innerHTML = `
            <div class="detection-alert-icon">${this.getNotificationIcon(type)}</div>
            <div class="detection-alert-message">${message}</div>
        `;
        
        // Insertar al inicio del contenido
    const content = document.querySelector('.content-grid');
    if (content) {
      content.parentNode.insertBefore(notification, content);
            
            // Remover después de 3 segundos
      setTimeout(() => {
        notification.remove();
      }, 3000);
    }
  }
    
  getNotificationIcon(type) {
    const icons = {
      success: '✅',
      error: '❌',
      warning: '⚠️',
      info: 'ℹ️'
    };
    return icons[type] || 'ℹ️';
  }
    
  viewLowStock() {
    this.showNotification('📦 Cargando productos con stock bajo...', 'info');
    
    // Filtrar productos para mostrar solo los de stock bajo
    const productCards = document.querySelectorAll('.product-card');
    let lowStockCount = 0;
    
    productCards.forEach(card => {
      const stockElement = card.querySelector('.product-stock');
      if (stockElement) {
        const stockText = stockElement.textContent;
        const stockMatch = stockText.match(/(\d+)/);
        if (stockMatch) {
          const stock = parseInt(stockMatch[1]);
          if (stock <= 10) {
            card.style.border = '2px solid #f1c40f';
            card.style.backgroundColor = 'rgba(241, 196, 15, 0.1)';
            lowStockCount++;
          } else {
            card.style.opacity = '0.3';
          }
        }
      }
    });
    
    if (lowStockCount > 0) {
      this.showNotification(`📦 ${lowStockCount} productos con stock bajo resaltados`, 'warning');
    } else {
      this.showNotification('📦 No se encontraron productos con stock bajo en la vista actual', 'info');
    }
  }
    
  startSimulation() {
    if (this.simulationInterval) {
      clearInterval(this.simulationInterval);
    }
        
    this.simulationInterval = setInterval(() => {
      if (this.isSystemActive) {
        this.updateApiStatus();
        this.updateStats();
        this.updateDetectionProgress();
      }
    }, 5000);
  }
    
  stopSimulation() {
    if (this.simulationInterval) {
      clearInterval(this.simulationInterval);
      this.simulationInterval = null;
    }
  }
    
  updateApiStatus() {
    const apiStatus = document.querySelector('.detection-status-indicator.api-online, .detection-status-indicator.api-offline, .api-status');
    if (!apiStatus) return;
        
    const random = Math.random();
    if (random < 0.1) {
      apiStatus.className = 'detection-status-indicator api-slow';
      apiStatus.innerHTML = '<span>🟡</span><span>Verial API</span>';
    } else if (random < 0.05) {
      apiStatus.className = 'detection-status-indicator api-offline';
      apiStatus.innerHTML = '<span>🔴</span><span>Verial API</span>';
    } else {
      apiStatus.className = 'detection-status-indicator api-online';
      apiStatus.innerHTML = '<span>🟢</span><span>Verial API</span>';
    }
  }
    
  updateStats() {
    // Obtener estadísticas reales via AJAX
    safeAjaxRequest('mia_get_detection_stats')
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.updateStatsDisplay(data.data);
          } else {
            console.error('Error obteniendo estadísticas:', data.data);
          }
        })
        .catch(error => {
          console.error('Error en la petición:', error);
        });
  }
    
  updateStatsDisplay(statsData) {
    const statValues = document.querySelectorAll('.detection-stat-value, .stat-value');
    if (statValues.length < 4) return;
        
        // Actualizar tiempo de última ejecución
    statValues[0].textContent = statsData.last_execution || 'Nunca';
        
        // Actualizar productos sincronizados
    statValues[1].textContent = (statsData.total_synced || 0).toLocaleString();
        
        // Actualizar tiempo promedio
    statValues[2].textContent = statsData.avg_time || '0s';
        
        // Actualizar precisión
    statValues[3].textContent = (statsData.accuracy || 0) + '%';
  }
    
    /**
     * Actualiza el progreso de sincronización de detección automática
     * 
     * Este método es específico para el dashboard de detección automática
     * y actualiza los elementos DOM correspondientes con datos del servidor
     */
  updateDetectionProgress() {
        // Verificar que los datos AJAX estén disponibles
    if (typeof window.miaDetectionData === 'undefined') {
      console.warn('window.miaDetectionData no está definido - no se puede actualizar progreso de detección');
      return;
    }
        
    console.log('=== DEBUGGING DETECTION PROGRESS ===');
    console.log('window.miaDetectionData:', window.miaDetectionData);
    console.log('URL AJAX:', window.miaDetectionData.ajaxUrl);
    console.log('Nonce:', window.miaDetectionData.nonce);
        
        // Hacer petición AJAX para obtener progreso de sincronización
    fetch(window.miaDetectionData.ajaxUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        action: 'mia_get_detection_sync_progress',
        nonce: window.miaDetectionData.nonce
      })
    })
        .then(response => {
            // Verificar si la respuesta es exitosa
          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }
          return response.json();
        })
        .then(data => {
          if (data.success) {
            this.updateDetectionProgressDisplay(data.data);
          } else {
            console.warn('Error obteniendo progreso de detección:', data.data);
                // Si el error es por nonce expirado, recargar la página
            if (data.data && typeof data.data === 'string' && data.data.includes('nonce')) {
              console.warn('Nonce expirado, recargando página...');
              window.location.reload();
            }
          }
        })
        .catch(error => {
          console.error('Error en petición de progreso de detección:', error);
            // Si es error 403, probablemente es nonce expirado
          if (error.message.includes('403')) {
            console.warn('Error 403 - Nonce expirado, recargando página...');
            console.log('Datos de la petición:', {
              ajaxUrl: window.miaDetectionData.ajaxUrl,
              nonce: window.miaDetectionData.nonce,
              action: 'mia_get_detection_sync_progress'
            });
                // TEMPORAL: Comentar recarga para debugging
                // window.location.reload();
          }
        });
  }
    
    /**
     * Actualiza la visualización del progreso de detección
     * 
     * @param {Object} progressData Datos de progreso del servidor
     */
  updateDetectionProgressDisplay(progressData) {
        // Elementos DOM específicos del detection dashboard
    const progressPercentage = document.querySelector('#progress-percentage');
    const progressFill = document.querySelector('#progress-fill');
    const progressText = document.querySelector('#progress-text');
    const timeRemaining = document.querySelector('#time-remaining');
    const syncProgress = document.querySelector('.detection-sync-progress');
    const syncStatus = document.querySelector('.detection-sync-status');
        
        // Si no hay elementos, no hacer nada
    if (!progressPercentage || !progressFill || !progressText) {
      return;
    }
        
    if (progressData.in_progress) {
            // Mostrar barra de progreso
      if (syncProgress) syncProgress.style.display = 'block';
      if (syncStatus) syncStatus.style.display = 'none';
            
            // Actualizar porcentaje
      const percentage = Math.round(progressData.percentage || 0);
      progressPercentage.textContent = percentage + '%';
            
            // Actualizar barra de progreso con animación suave
      progressFill.style.width = percentage + '%';
            
            // Actualizar texto de progreso
      const processed = progressData.processed || 0;
      const total = progressData.total || 0;
      progressText.textContent = `${processed} de ${total} productos procesados`;
            
            // Actualizar tiempo restante
      if (timeRemaining) {
        const timeRemainingValue = progressData.time_remaining || 0;
        timeRemaining.textContent = `Tiempo restante: ${timeRemainingValue > 0 ? timeRemainingValue + ' min' : 'calculando...'}`;
      }
            
            // Efectos visuales en hitos importantes
      if (percentage >= 25 && percentage < 27) {
        this.showNotification('🔄 25% completado - Detección en progreso', 'info');
      } else if (percentage >= 50 && percentage < 52) {
        this.showNotification('⚡ 50% completado - Mitad del proceso', 'success');
      } else if (percentage >= 75 && percentage < 77) {
        this.showNotification('🚀 75% completado - Casi terminado', 'info');
      } else if (percentage >= 100) {
        this.showNotification('✅ 100% completado - Detección finalizada', 'success');
                
                // Restaurar estado de espera después de un momento
        setTimeout(() => {
          if (syncProgress) syncProgress.style.display = 'none';
          if (syncStatus) syncStatus.style.display = 'block';
        }, 2000);
      }
    } else {
            // Mostrar estado de espera
      if (syncProgress) syncProgress.style.display = 'none';
      if (syncStatus) syncStatus.style.display = 'block';
    }
  }
    
    // Métodos para integración con WordPress
  getDetectionStatus() {
    return {
      active: this.isSystemActive,
      progress: this.syncProgress,
      filter: this.currentFilter,
      products: this.products.length
    };
  }
    
  setDetectionStatus(status) {
    this.isSystemActive = status.active;
    this.syncProgress = status.progress;
    this.currentFilter = status.filter;
        
        // Actualizar UI
    const toggle = document.querySelector('.modern-toggle');
    if (toggle) {
      toggle.classList.toggle('active', status.active);
    }
        
    const statusText = document.querySelector('.status-indicator span');
    if (statusText) {
      statusText.textContent = status.active ? 'Sistema Activo' : 'Sistema Inactivo';
    }
  }
    
    // ===== SISTEMA DE NOTIFICACIONES =====
    
    /**
     * Mostrar panel de notificaciones
     */
  showNotificationsPanel() {
        // Ocultar otros paneles primero
    this.hideAllPanels();
        
    const panel = document.getElementById('notifications-panel');
    if (panel) {
      panel.style.display = 'block';
      panel.classList.add('show');
      this.loadNotifications();
      this.loadNotificationStats();
    }
  }
    
    /**
     * Ocultar panel de notificaciones
     */
  hideNotificationsPanel() {
    const panel = document.getElementById('notifications-panel');
    if (panel) {
      panel.classList.remove('show');
      setTimeout(() => {
        panel.style.display = 'none';
      }, 300);
    }
  }
    
    /**
     * Cargar notificaciones desde el servidor
     */
  loadNotifications(offset = 0, append = false) {
    const statusFilterElem = document.getElementById('notification-status-filter');
    const typeFilterElem = document.getElementById('notification-type-filter');
    const statusFilter = statusFilterElem ? statusFilterElem.value : 'all';
    const typeFilter = typeFilterElem ? typeFilterElem.value : 'all';
        
    fetch(window.miaDetectionData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'mia_get_notifications',
        nonce: window.miaDetectionData.nonce,
        status: statusFilter,
        type: typeFilter,
        limit: 20,
        offset
      })
    })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.renderNotifications(data.data.notifications, append);
            this.updatePagination(data.data.has_more);
          } else {
            this.showNotification('Error al cargar notificaciones: ' + data.data, 'error');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          this.showNotification('Error de conexión al cargar notificaciones', 'error');
        });
  }
    
    /**
     * Renderizar lista de notificaciones
     */
  renderNotifications(notifications, append = false) {
    const container = document.getElementById('notifications-list');
    if (!container) return;
        
    if (!append) {
      container.innerHTML = '';
    }
        
    if (notifications.length === 0) {
      container.innerHTML = `
                <div class="empty-notifications">
                    <div class="empty-notifications-icon">🔔</div>
                    <h3>No hay notificaciones</h3>
                    <p>No se encontraron notificaciones con los filtros seleccionados.</p>
                </div>
            `;
      return;
    }
        
    notifications.forEach(notification => {
      const notificationElement = this.createNotificationElement(notification);
      container.appendChild(notificationElement);
    });
  }
    
    /**
     * Crear elemento HTML para una notificación
     */
  createNotificationElement(notification) {
    const div = document.createElement('div');
    div.className = `notification-item ${!notification.read ? 'unread' : ''} ${notification.archived ? 'archived' : ''}`;
    div.dataset.notificationId = notification.id;
        
    const typeLabels = {
      created: 'NUEVO',
      updated: 'ACTUALIZADO',
      deleted: 'ELIMINADO',
      sync_success: 'SINCRONIZACIÓN',
      sync_error: 'ERROR',
      stock_low: 'STOCK BAJO',
      document_request: 'SOLICITUD'
    };
        
    const typeIcons = {
      created: '🆕',
      updated: '✏️',
      deleted: '🗑️',
      sync_success: '✅',
      sync_error: '❌',
      stock_low: '⚠️',
      document_request: '📄'
    };
        
    const priorityClasses = {
      info: 'priority-info',
      warning: 'priority-warning',
      error: 'priority-error',
      success: 'priority-success'
    };
        
    const priorityClass = priorityClasses[notification.priority] || 'priority-info';
    const priorityIcon = notification.priority === 'error' ? '🔴' : 
                           notification.priority === 'warning' ? '🟡' : 
                           notification.priority === 'success' ? '🟢' : '🔵';
        
    div.innerHTML = `
            <div class="notification-header">
                <h4 class="notification-title">
                    <span class="notification-type-badge ${notification.type} ${priorityClass}">
                        ${typeLabels[notification.type] || notification.type.toUpperCase()}
                    </span>
                    ${typeIcons[notification.type] || '📦'} ${notification.title}
                    <span class="notification-priority">${priorityIcon}</span>
                </h4>
                <span class="notification-timestamp">${this.formatTimestamp(notification.timestamp)}</span>
            </div>
            <div class="notification-message">${notification.message}</div>
            ${notification.data && Object.keys(notification.data).length > 0 ? `
                <div class="notification-data">
                    ${this.renderNotificationData(notification.data, notification.type)}
                </div>
            ` : ''}
            <div class="notification-actions">
                ${!notification.read ? '<button class="notification-action primary" onclick="markNotificationAsRead(\'' + notification.id + '\')">Marcar como leída</button>' : ''}
                <button class="notification-action" onclick="archiveNotification(\'' + notification.id + '\')">Archivar</button>
                ${notification.product_id ? '<button class="notification-action" onclick="viewProduct(' + notification.product_id + ')">Ver producto</button>' : ''}
                ${notification.type === 'sync_success' ? '<button class="notification-action" onclick="viewSyncDetails(\'' + notification.id + '\')">Ver detalles</button>' : ''}
            </div>
        `;
        
    return div;
  }
    
    /**
     * Renderizar datos adicionales de la notificación
     */
  renderNotificationData(data, type) {
    let html = '<div class="notification-data-content">';
        
    switch (type) {
    case 'sync_success':
      html += `
                    <div class="data-item">
                        <strong>Productos sincronizados:</strong> ${data.synced_count || 0}
                    </div>
                    <div class="data-item">
                        <strong>Errores:</strong> ${data.error_count || 0}
                    </div>
                    <div class="data-item">
                        <strong>Cache usado:</strong> ${data.cache_used ? 'Sí' : 'No'}
                    </div>
                `;
      break;
                
    case 'stock_low':
      html += `
                    <div class="data-item">
                        <strong>Stock actual:</strong> ${data.current_stock || 0} unidades
                    </div>
                    <div class="data-item">
                        <strong>SKU:</strong> ${data.sku || 'N/A'}
                    </div>
                `;
      break;
                
    case 'sync_error':
      html += `
                    <div class="data-item">
                        <strong>Tipo de detección:</strong> ${data.detection_type || 'Desconocido'}
                    </div>
                    <div class="data-item">
                        <strong>Error:</strong> ${data.error_message || 'Error desconocido'}
                    </div>
                `;
      break;
                
    default:
                // Mostrar datos genéricos
      Object.entries(data).forEach(([key, value]) => {
        if (key !== 'timestamp' && key !== 'product_id' && key !== 'verial_id') {
          html += `
                            <div class="data-item">
                                <strong>${this.formatDataKey(key)}:</strong> ${this.formatDataValue(value)}
                            </div>
                        `;
        }
      });
      break;
    }
        
    html += '</div>';
    return html;
  }
    
    /**
     * Formatear clave de datos para mostrar
     */
  formatDataKey(key) {
    const keyMap = {
      synced_count: 'Productos sincronizados',
      error_count: 'Errores',
      cache_used: 'Cache usado',
      current_stock: 'Stock actual',
      error_message: 'Mensaje de error',
      detection_type: 'Tipo de detección'
    };
    return keyMap[key] || key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
  }
    
    /**
     * Formatear valor de datos para mostrar
     */
  formatDataValue(value) {
    if (typeof value === 'boolean') {
      return value ? 'Sí' : 'No';
    }
    if (typeof value === 'object') {
      return JSON.stringify(value);
    }
    return value;
  }
    
    /**
     * Cargar estadísticas de notificaciones
     */
  loadNotificationStats() {
    fetch(window.miaDetectionData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'mia_get_notification_stats',
        nonce: window.miaDetectionData.nonce
      })
    })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.updateNotificationStats(data.data);
          }
        })
        .catch(error => {
          console.error('Error cargando estadísticas:', error);
        });
  }
    
    /**
     * Actualizar estadísticas de notificaciones en la UI
     */
  updateNotificationStats(stats) {
        // Actualizar contador en el menú
    const badge = document.getElementById('notification-count');
    if (badge) {
      badge.textContent = stats.pending || 0;
      badge.style.display = (stats.pending || 0) > 0 ? 'flex' : 'none';
    }
        
        // Actualizar estadísticas en el panel
    const totalEl = document.getElementById('total-notifications');
    const unreadEl = document.getElementById('unread-notifications');
    const todayEl = document.getElementById('today-notifications');
        
    if (totalEl) totalEl.textContent = stats.total || 0;
    if (unreadEl) unreadEl.textContent = stats.pending || 0;
    if (todayEl) todayEl.textContent = stats.today || 0;
  }
    
    /**
     * Marcar notificación como leída
     */
  markNotificationAsRead(notificationId) {
        // Verificar que los datos necesarios existan
    if (!window.miaDetectionData || !window.miaDetectionData.ajaxUrl || !window.miaDetectionData.nonce) {
      console.error('Datos AJAX no configurados correctamente');
      this.showNotification('Error de configuración', 'error');
      return;
    }

    fetch(window.miaDetectionData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'mia_mark_notification_read',
        nonce: window.miaDetectionData.nonce,
        notification_id: notificationId
      })
    })
        .then(response => {
          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }
          return response.json();
        })
        .then(data => {
          if (data.success) {
                // Actualizar UI
            const notificationEl = document.querySelector(`[data-notification-id="${notificationId}"]`);
            if (notificationEl) {
              notificationEl.classList.remove('unread');
              const markReadBtn = notificationEl.querySelector('.notification-action.primary');
              if (markReadBtn) markReadBtn.remove();
            }
                
                // Recargar estadísticas
            this.loadNotificationStats();
            this.showNotification('Notificación marcada como leída', 'success');
          } else {
            const errorMessage = data.data || 'Error desconocido';
            this.showNotification('Error al marcar notificación: ' + errorMessage, 'error');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          this.showNotification('Error de conexión: ' + error.message, 'error');
        });
  }
    
    /**
     * Archivar notificación
     */
  archiveNotification(notificationId) {
    fetch(window.miaDetectionData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'mia_archive_notification',
        nonce: window.miaDetectionData.nonce,
        notification_id: notificationId
      })
    })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
                // Actualizar UI
            const notificationEl = document.querySelector(`[data-notification-id="${notificationId}"]`);
            if (notificationEl) {
              notificationEl.classList.add('archived');
            }
                
            this.showNotification('Notificación archivada', 'success');
          } else {
            this.showNotification('Error al archivar notificación: ' + data.data, 'error');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          this.showNotification('Error de conexión', 'error');
        });
  }
    
    /**
     * Limpiar todas las notificaciones
     */
  clearAllNotifications() {
    if (!confirm('¿Estás seguro de que quieres limpiar todas las notificaciones?')) {
      return;
    }
        
    fetch(window.miaDetectionData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'mia_clear_all_notifications',
        nonce: window.miaDetectionData.nonce
      })
    })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.loadNotifications();
            this.loadNotificationStats();
            this.showNotification('Todas las notificaciones han sido limpiadas', 'success');
          } else {
            this.showNotification('Error al limpiar notificaciones: ' + data.data, 'error');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          this.showNotification('Error de conexión', 'error');
        });
  }
    
    /**
     * Marcar todas las notificaciones como leídas
     */
  markAllAsRead() {
        // Obtener todas las notificaciones no leídas
    const unreadNotifications = document.querySelectorAll('.notification-item.unread');
        
    if (unreadNotifications.length === 0) {
      this.showNotification('No hay notificaciones sin leer', 'info');
      return;
    }
        
        // Marcar cada una como leída
    let processed = 0;
    unreadNotifications.forEach(notificationEl => {
      const notificationId = notificationEl.dataset.notificationId;
      this.markNotificationAsRead(notificationId);
      processed++;
    });
        
    this.showNotification(`${processed} notificaciones marcadas como leídas`, 'success');
  }
    
    /**
     * Cargar más notificaciones (paginación)
     */
  loadMoreNotifications() {
    const currentNotifications = document.querySelectorAll('.notification-item').length;
    this.loadNotifications(currentNotifications, true);
  }
    
    /**
     * Actualizar paginación
     */
  updatePagination(hasMore) {
    const paginationEl = document.getElementById('notifications-pagination');
    if (paginationEl) {
      paginationEl.style.display = hasMore ? 'block' : 'none';
    }
  }
    
    /**
     * Formatear timestamp para mostrar
     */
  formatTimestamp(timestamp) {
    const date = new Date(timestamp);
    const now = new Date();
    const diff = now - date;
        
    if (diff < 60000) { // Menos de 1 minuto
      return 'Hace un momento';
    } else if (diff < 3600000) { // Menos de 1 hora
      return `Hace ${Math.floor(diff / 60000)} min`;
    } else if (diff < 86400000) { // Menos de 1 día
      return `Hace ${Math.floor(diff / 3600000)}h`;
    } else {
      return date.toLocaleDateString('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
      });
    }
  }
    
    /**
     * Ver producto en WooCommerce
     */
  viewProduct(productId) {
    const editUrl = `${window.location.origin}/wp-admin/post.php?post=${productId}&action=edit`;
    window.open(editUrl, '_blank');
  }
    
    /**
     * Ver detalles de sincronización
     */
  viewSyncDetails(notificationId) {
        // Buscar la notificación en la lista actual
    const notificationElement = document.querySelector(`[data-notification-id="${notificationId}"]`);
    if (!notificationElement) {
      this.showNotification('Notificación no encontrada', 'error');
      return;
    }
        
        // Obtener datos de la notificación
    const notificationData = notificationElement.dataset;
        
        // Crear modal con detalles
    this.showSyncDetailsModal(notificationData);
  }
    
    /**
     * Mostrar modal con detalles de sincronización
     */
  showSyncDetailsModal(notificationData) {
        // Crear modal
    const modal = document.createElement('div');
    modal.className = 'sync-details-modal';
    modal.innerHTML = `
            <div class="modal-overlay" onclick="this.parentElement.remove()"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Detalles de Sincronización</h3>
                    <button class="modal-close" onclick="this.closest('.sync-details-modal').remove()">×</button>
                </div>
                <div class="modal-body">
                    <div class="sync-details">
                        <div class="detail-row">
                            <strong>Productos sincronizados:</strong>
                            <span>${notificationData.syncedCount || 0}</span>
                        </div>
                        <div class="detail-row">
                            <strong>Errores:</strong>
                            <span>${notificationData.errorCount || 0}</span>
                        </div>
                        <div class="detail-row">
                            <strong>Cache usado:</strong>
                            <span>${notificationData.cacheUsed === 'true' ? 'Sí' : 'No'}</span>
                        </div>
                        <div class="detail-row">
                            <strong>Timestamp:</strong>
                            <span>${notificationData.timestamp || 'N/A'}</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="this.closest('.sync-details-modal').remove()">Cerrar</button>
                </div>
            </div>
        `;
        
        // Agregar estilos
    const style = document.createElement('style');
    style.textContent = `
            .sync-details-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 10000;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .modal-overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
            }
            
            .modal-content {
                position: relative;
                background: white;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
                max-width: 500px;
                width: 90%;
                max-height: 80vh;
                overflow: hidden;
            }
            
            .modal-header {
                padding: 20px;
                border-bottom: 1px solid #e0e0e0;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .modal-header h3 {
                margin: 0;
                color: #2c3e50;
            }
            
            .modal-close {
                background: none;
                border: none;
                font-size: 24px;
                cursor: pointer;
                color: #999;
            }
            
            .modal-body {
                padding: 20px;
            }
            
            .sync-details {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }
            
            .detail-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 8px 0;
                border-bottom: 1px solid #f0f0f0;
            }
            
            .detail-row:last-child {
                border-bottom: none;
            }
            
            .modal-footer {
                padding: 20px;
                border-top: 1px solid #e0e0e0;
                text-align: right;
            }
        `;
        
    document.head.appendChild(style);
    document.body.appendChild(modal);
        
        // Limpiar estilos cuando se cierre el modal
    modal.addEventListener('click', function(e) {
      if (e.target === modal || e.target.classList.contains('modal-close')) {
        style.remove();
      }
    });
  }
    
    // ===== SISTEMA DE SOLICITUDES DE DOCUMENTOS =====
    
    /**
     * Mostrar panel de solicitudes de documentos
     */
  showDocumentRequestsPanel() {
    console.log('🔍 showDocumentRequestsPanel called');
    // Ocultar otros paneles primero
    this.hideAllPanels();
        
    const modal = document.getElementById('document-requests-panel');
    console.log('🔍 Document requests modal found:', modal);
    if (modal) {
      console.log('✅ Opening document requests panel');
      modal.style.display = 'flex';
      modal.classList.add('show');
      this.loadDocumentRequests();
      this.loadDocumentStats();
    } else {
      console.error('❌ Document requests panel not found');
    }
  }
    
    /**
     * Ocultar panel de solicitudes de documentos
     */
  hideDocumentRequestsPanel() {
    const modal = document.getElementById('document-requests-panel');
    if (modal) {
      modal.classList.remove('show');
      setTimeout(() => {
        modal.style.display = 'none';
      }, 300);
    }
  }
    
    /**
     * Cargar solicitudes de documentos desde el servidor
     */
  loadDocumentRequests(offset = 0, append = false) {
    const statusFilter = document.getElementById('document-status-filter') ? document.getElementById('document-status-filter').value : 'all';
        
    fetch(window.miaDetectionData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'mia_get_document_requests',
        nonce: window.miaDetectionData.nonce,
        status: statusFilter,
        limit: 20,
        offset
      })
    })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.renderDocumentRequests(data.data.requests, append);
            this.updateDocumentPagination(data.data.has_more);
          } else {
            this.showNotification('Error al cargar solicitudes: ' + data.data, 'error');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          this.showNotification('Error de conexión al cargar solicitudes', 'error');
        });
  }
    
    /**
     * Renderizar lista de solicitudes de documentos
     */
  renderDocumentRequests(requests, append = false) {
    const container = document.getElementById('document-requests-list');
    if (!container) return;
        
    if (!append) {
      container.innerHTML = '';
    }
        
    if (requests.length === 0) {
      container.innerHTML = `
                <div class="empty-documents">
                    <div class="empty-documents-icon">📄</div>
                    <h3>No hay solicitudes</h3>
                    <p>No se encontraron solicitudes de documentos con los filtros seleccionados.</p>
                </div>
            `;
      return;
    }
        
    requests.forEach(request => {
      const requestElement = this.createDocumentRequestElement(request);
      container.appendChild(requestElement);
    });
  }
    
    /**
     * Crear elemento HTML para una solicitud de documento
     */
  createDocumentRequestElement(request) {
    const div = document.createElement('div');
    div.className = `document-request-item ${request.status}`;
    div.dataset.requestId = request.id;
        
    const statusLabels = {
      pending: 'PENDIENTE',
      completed: 'COMPLETADA',
      failed: 'FALLIDA'
    };
        
    const statusIcons = {
      pending: '⏳',
      completed: '✅',
      failed: '❌'
    };
        
    div.innerHTML = `
            <div class="document-request-header">
                <h4 class="document-request-title">
                    ${statusIcons[request.status] || '📄'} ${request.product_name}
                </h4>
                <span class="document-request-status ${request.status}">
                    ${statusLabels[request.status] || request.status.toUpperCase()}
                </span>
            </div>
            <div class="document-request-info">
                <div class="document-request-info-item">
                    <span class="document-request-info-label">SKU:</span>
                    <span>${request.product_sku}</span>
                </div>
                <div class="document-request-info-item">
                    <span class="document-request-info-label">Referencia:</span>
                    <span>${request.reference}</span>
                </div>
                <div class="document-request-info-item">
                    <span class="document-request-info-label">ID Documento:</span>
                    <span>${request.document_id || 'N/A'}</span>
                </div>
                <div class="document-request-info-item">
                    <span class="document-request-info-label">Creado:</span>
                    <span>${this.formatTimestamp(request.created_at)}</span>
                </div>
            </div>
            <div class="document-request-actions">
                ${request.status === 'pending' ? '<button class="document-request-action primary" onclick="markDocumentCompleted(\'' + request.id + '\', \'' + request.reference + '\')">Marcar Completada</button>' : ''}
                ${request.status === 'pending' ? '<button class="document-request-action danger" onclick="markDocumentFailed(\'' + request.id + '\', \'' + request.reference + '\')">Marcar Fallida</button>' : ''}
                <button class="document-request-action" onclick="viewProduct(${request.product_id})">Ver Producto</button>
            </div>
        `;
        
    return div;
  }
    
    /**
     * Cargar estadísticas de solicitudes de documentos
     */
  loadDocumentStats() {
    fetch(window.miaDetectionData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'mia_get_document_stats',
        nonce: window.miaDetectionData.nonce
      })
    })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.updateDocumentStats(data.data);
          }
        })
        .catch(error => {
          console.error('Error cargando estadísticas de documentos:', error);
        });
  }
    
    /**
     * Actualizar estadísticas de solicitudes de documentos en la UI
     */
  updateDocumentStats(stats) {
        // Actualizar contador en el menú
    const badge = document.getElementById('document-count');
    if (badge) {
      badge.textContent = stats.pending || 0;
      badge.style.display = (stats.pending || 0) > 0 ? 'flex' : 'none';
    }
        
        // Actualizar estadísticas en el panel
    const totalEl = document.getElementById('total-documents');
    const pendingEl = document.getElementById('pending-documents');
    const completedEl = document.getElementById('completed-documents');
    const todayEl = document.getElementById('today-documents');
        
    if (totalEl) totalEl.textContent = stats.total || 0;
    if (pendingEl) pendingEl.textContent = stats.pending || 0;
    if (completedEl) completedEl.textContent = stats.completed || 0;
    if (todayEl) todayEl.textContent = stats.today || 0;
  }
    
    /**
     * Marcar solicitud de documento como completada
     */
  markDocumentCompleted(requestId, reference) {
    this.updateDocumentStatus(requestId, reference, 'completed');
  }
    
    /**
     * Marcar solicitud de documento como fallida
     */
  markDocumentFailed(requestId, reference) {
    this.updateDocumentStatus(requestId, reference, 'failed');
  }
    
    /**
     * Actualizar estado de solicitud de documento
     */
  updateDocumentStatus(requestId, reference, status) {
    const productId = requestId.split('_')[0];
        
    fetch(window.miaDetectionData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'mia_update_document_status',
        nonce: window.miaDetectionData.nonce,
        product_id: productId,
        reference,
        status
      })
    })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.loadDocumentRequests();
            this.loadDocumentStats();
            this.showNotification('Estado actualizado correctamente', 'success');
          } else {
            this.showNotification('Error al actualizar estado: ' + data.data, 'error');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          this.showNotification('Error de conexión', 'error');
        });
  }
    
    /**
     * Crear solicitud de documento manual
     */
  createDocumentRequest() {
    const form = document.getElementById('document-request-form');
    const list = document.getElementById('document-requests-list');
    
    if (form && list) {
      // Mostrar formulario y ocultar lista
      form.style.display = 'block';
      list.style.display = 'none';
      
      // Limpiar formulario
      this.clearDocumentRequestForm();
      
      // Enfocar el primer campo
      const emailInput = document.getElementById('document-request-email');
      if (emailInput) {
        emailInput.focus();
      }
    }
  }
  
  /**
   * Cancelar creación de solicitud de documento
   */
  cancelDocumentRequest() {
    const form = document.getElementById('document-request-form');
    const list = document.getElementById('document-requests-list');
    
    if (form && list) {
      // Ocultar formulario y mostrar lista
      form.style.display = 'none';
      list.style.display = 'block';
      
      // Limpiar formulario
      this.clearDocumentRequestForm();
    }
  }
  
  /**
   * Limpiar formulario de solicitud de documento
   */
  clearDocumentRequestForm() {
    const emailInput = document.getElementById('document-request-email');
    const typeSelect = document.getElementById('document-request-type');
    const notesTextarea = document.getElementById('document-request-notes');
    
    if (emailInput) emailInput.value = '';
    if (typeSelect) typeSelect.value = '';
    if (notesTextarea) notesTextarea.value = '';
  }
  
  /**
   * Enviar solicitud de documento
   */
  submitDocumentRequest() {
    const emailInput = document.getElementById('document-request-email');
    const typeSelect = document.getElementById('document-request-type');
    const notesTextarea = document.getElementById('document-request-notes');
    
    if (!emailInput || !typeSelect) {
      console.error('Elementos del formulario no encontrados');
      return;
    }
    
    const email = emailInput.value.trim();
    const type = typeSelect.value;
    const notes = notesTextarea ? notesTextarea.value.trim() : '';
    
    // Validar campos requeridos
    if (!email) {
      alert('Por favor, ingresa un email válido');
      emailInput.focus();
      return;
    }
    
    if (!type) {
      alert('Por favor, selecciona un tipo de documento');
      typeSelect.focus();
      return;
    }
    
    // Validar email
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
      alert('Por favor, ingresa un email válido');
      emailInput.focus();
      return;
    }
    
    // Mostrar loading
    const submitBtn = document.querySelector('[onclick="submitDocumentRequest()"]');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '⏳ Enviando...';
    }
    
    // Simular envío (aquí se haría la llamada AJAX real)
    setTimeout(() => {
      console.log('Enviando solicitud:', { email, type, notes });
      
      // Mostrar mensaje de éxito
      this.showNotification('Solicitud de documento enviada correctamente', 'success');
      
      // Ocultar formulario y mostrar lista
      this.cancelDocumentRequest();
      
      // Recargar lista de solicitudes
      this.loadDocumentRequests();
      
      // Restaurar botón
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '📤 Enviar Solicitud';
      }
    }, 1500);
  }
    
    /**
     * Abrir modal de solicitud de producto
     */
  openProductRequestModal(productId = null) {
    console.log('🔍 openProductRequestModal called with productId:', productId);
    
    // Ocultar otros paneles primero
    this.hideAllPanels();
    
    const modal = document.getElementById('product-request-modal');
    console.log('🔍 Modal element found:', modal);
    if (!modal) {
      console.error('❌ Modal product-request-modal not found');
      return;
    }
        
    console.log('✅ Setting modal display to flex');
    // Remover el style inline que tiene display: none
    modal.removeAttribute('style');
    modal.style.display = 'flex';
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    console.log('✅ Modal should now be visible');
        
        // Si se proporciona un ID de producto, cargar sus datos de forma asíncrona
    if (productId) {
      this.loadProductData(productId);
    } else {
            // Limpiar formulario
      this.clearProductRequestForm();
    }
        
        // Cargar datos de Verial (categorías, fabricantes, etc.) de forma asíncrona
    this.loadVerialData();
  }
    
    /**
     * Cerrar modal de solicitud de producto
     */
  closeProductRequestModal() {
    const modal = document.getElementById('product-request-modal');
    if (modal) {
      modal.style.display = 'none';
      modal.classList.remove('show');
      document.body.style.overflow = 'auto';
    }
  }
    
    /**
     * Cargar datos del producto de WooCommerce
     */
  async loadProductData(productId) {
    try {
      const response = await fetch(window.miaDetectionData.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: 'mia_get_product_data',
          nonce: window.miaDetectionData.nonce,
          product_id: productId
        })
      });
            
      const data = await response.json();
      if (data.success) {
        this.populateProductForm(data.data);
      } else {
        this.showNotification('Error al cargar producto: ' + data.data, 'error');
      }
    } catch (error) {
      console.error('Error:', error);
      this.showNotification('Error de conexión', 'error');
    }
  }
    
    /**
     * Poblar formulario con datos del producto
     */
  populateProductForm(productData) {
        // Datos básicos de WooCommerce
    const wcId = document.getElementById('wc-product-id');
    if (wcId) wcId.value = productData.id || '';
    
    const wcName = document.getElementById('wc-product-name');
    if (wcName) wcName.value = productData.name || '';
    
    const wcSku = document.getElementById('wc-product-sku');
    if (wcSku) wcSku.value = productData.sku || '';
    
    const wcPrice = document.getElementById('wc-product-price');
    if (wcPrice) wcPrice.value = productData.price || '';
    
    const wcStock = document.getElementById('wc-product-stock');
    if (wcStock) wcStock.value = productData.stock_quantity || '';
    
    const wcDescription = document.getElementById('wc-product-description');
    if (wcDescription) wcDescription.value = productData.description || '';
        
        // Mapeo a Verial (copiar datos básicos)
    const verialName = document.getElementById('verial-name');
    if (verialName) verialName.value = productData.name || '';
    
    const verialDescription = document.getElementById('verial-description');
    if (verialDescription) verialDescription.value = productData.description || '';
    
    const verialSku = document.getElementById('verial-sku');
    if (verialSku) verialSku.value = productData.sku || '';
    
    const verialPrice = document.getElementById('verial-price');
    if (verialPrice) verialPrice.value = productData.price || '';
    
    const verialStock = document.getElementById('verial-stock');
    if (verialStock) verialStock.value = productData.stock_quantity || '';
        
        // Cargar imágenes
    this.loadProductImages(productData.images || []);
  }
    
    /**
     * Cargar imágenes del producto
     */
  loadProductImages(images) {
    const container = document.getElementById('images-preview');
    if (!container) return;
    
    if (!images || images.length === 0) {
      container.innerHTML = '<div class="no-images">No hay imágenes disponibles</div>';
      return;
    }
        
    container.innerHTML = images.map(img => `
            <div class="image-preview">
                <img src="${img.src}" alt="${img.alt || 'Imagen del producto'}" />
            </div>
        `).join('');
  }
    
    /**
     * Limpiar formulario de solicitud
     */
  clearProductRequestForm() {
    const form = document.getElementById('product-request-form');
    if (form) {
      form.reset();
    }
    const imagesPreview = document.getElementById('images-preview');
    if (imagesPreview) {
      imagesPreview.innerHTML = '<div class="no-images">No hay imágenes disponibles</div>';
    }
  }
    
    /**
     * Cargar datos de Verial (categorías, fabricantes, etc.)
     */
  async loadVerialData() {
    try {
            // Verificar que window.miaDetectionData esté disponible
      if (typeof window.miaDetectionData === 'undefined') {
        console.error('window.miaDetectionData no está disponible');
        return;
      }
            
            // Cargar categorías
      const categoriesResponse = await fetch(window.miaDetectionData.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: 'mia_get_verial_categories',
          nonce: window.miaDetectionData.nonce
        })
      });
            
      const categoriesData = await categoriesResponse.json();
      if (categoriesData.success) {
        this.populateSelect('verial-category', categoriesData.data);
      }
            
            // Cargar fabricantes
      const manufacturersResponse = await fetch(window.miaDetectionData.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: 'mia_get_verial_manufacturers',
          nonce: window.miaDetectionData.nonce
        })
      });
            
      const manufacturersData = await manufacturersResponse.json();
      if (manufacturersData.success) {
        this.populateSelect('verial-manufacturer', manufacturersData.data);
      }
            
            // Cargar campos configurables
      const fieldsResponse = await fetch(window.miaDetectionData.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: 'mia_get_verial_configurable_fields',
          nonce: window.miaDetectionData.nonce
        })
      });
            
      const fieldsData = await fieldsResponse.json();
      if (fieldsData.success) {
        this.populateConfigurableFields(fieldsData.data);
      }
            
    } catch (error) {
      console.error('Error cargando datos de Verial:', error);
    }
  }
    
    /**
     * Poblar select con opciones
     */
  populateSelect(selectId, options) {
    const select = document.getElementById(selectId);
    if (!select) return;
        
        // Limpiar opciones existentes (excepto la primera)
    while (select.children.length > 1) {
      select.removeChild(select.lastChild);
    }
        
    options.forEach(option => {
      const optionElement = document.createElement('option');
      optionElement.value = option.id;
      optionElement.textContent = option.name;
      select.appendChild(optionElement);
    });
  }
    
    /**
     * Poblar campos configurables
     */
  populateConfigurableFields(fields) {
    const container = document.getElementById('configurable-fields');
    if (!container || !fields || fields.length === 0) {
      container.innerHTML = '<div class="no-fields">No hay campos configurables disponibles</div>';
      return;
    }
        
    container.innerHTML = fields.map(field => `
            <div class="configurable-field">
                <label for="field_${field.id}">${field.descripcion}</label>
                ${this.renderConfigurableField(field)}
            </div>
        `).join('');
  }
    
    /**
     * Renderizar campo configurable según su tipo
     */
  renderConfigurableField(field) {
    switch (field.tipo_dato) {
    case 1: // Texto
      return `<input type="text" id="field_${field.id}" name="configurable_${field.id}" />`;
    case 2: // Número
      return `<input type="number" id="field_${field.id}" name="configurable_${field.id}" step="0.01" />`;
    case 3: // Fecha
      return `<input type="date" id="field_${field.id}" name="configurable_${field.id}" />`;
    case 4: // Lógico
      return `<select id="field_${field.id}" name="configurable_${field.id}">
                    <option value="">Seleccionar...</option>
                    <option value="true">Sí</option>
                    <option value="false">No</option>
                </select>`;
    case 5: // Lista desplegable
      return `<select id="field_${field.id}" name="configurable_${field.id}">
                    <option value="">Seleccionar...</option>
                    ${field.valores ? field.valores.map(val => 
                        `<option value="${val}">${val}</option>`
                    ).join('') : ''}
                </select>`;
    default:
      return `<input type="text" id="field_${field.id}" name="configurable_${field.id}" />`;
    }
  }
    
    /**
     * Vista previa de la solicitud
     */
  previewProductRequest() {
    const formData = this.collectFormData();
    const jsonData = this.generateProductRequestJSON(formData);
        
        // Mostrar JSON
    document.getElementById('json-content').textContent = JSON.stringify(jsonData, null, 2);
        
        // Mostrar formato legible
    document.getElementById('formatted-content').innerHTML = this.generateFormattedPreview(jsonData);
        
        // Mostrar modal de vista previa
    document.getElementById('product-request-preview-modal').style.display = 'flex';
  }
    
    /**
     * Cerrar modal de vista previa
     */
  closePreviewModal() {
    document.getElementById('product-request-preview-modal').style.display = 'none';
  }
    
    /**
     * Cambiar pestaña de vista previa
     */
  switchPreviewTab(tabName) {
        // Ocultar todas las pestañas
    document.querySelectorAll('.preview-tab').forEach(tab => {
      tab.classList.remove('active');
    });
    document.querySelectorAll('.tab-button').forEach(button => {
      button.classList.remove('active');
    });
        
        // Mostrar pestaña seleccionada
    document.getElementById(tabName + '-preview').classList.add('active');
    event.target.classList.add('active');
  }
    
    /**
     * Recopilar datos del formulario
     */
  collectFormData() {
    const form = document.getElementById('product-request-form');
    const formData = new FormData(form);
    const data = {};
        
    for (const [key, value] of formData.entries()) {
      data[key] = value;
    }
        
        // Recopilar campos configurables
    data.configurable_fields = {};
    document.querySelectorAll('[name^="configurable_"]').forEach(field => {
      const fieldId = field.name.replace('configurable_', '');
      data.configurable_fields[fieldId] = field.value;
    });
        
    return data;
  }
    
    /**
     * Generar JSON de solicitud de producto
     */
  generateProductRequestJSON(formData) {
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const reference = `SOLICITUD_PRODUCTO_${formData.wc_product_id}_${timestamp}`;
        
    return {
      sesionwcf: 18, // TODO: Obtener de configuración
      Tipo: 6, // Presupuesto (solicitud)
      Referencia: reference,
      Fecha: new Date().toISOString().split('T')[0],
      Cliente: {
        Tipo: 1,
        Nombre: `Sistema WooCommerce - ${window.miaDetectionData.siteName || 'Mi Tienda'}`,
        Email: window.miaDetectionData.adminEmail || 'admin@example.com'
      },
      BaseImponible: 0.00,
      TotalImporte: 0.00,
      Contenido: [
        {
          TipoRegistro: 2, // Comentario
          Comentario: this.generateProductRequestComment(formData)
        }
      ],
      Pagos: [],
      Aux1: 'SOLICITUD_CREACION_ARTICULO_WOOCOMMERCE',
      Aux2: `Producto ID: ${formData.wc_product_id}`,
      Aux3: `SKU: ${formData.wc_product_sku}`,
      Aux4: `Fecha solicitud: ${new Date().toLocaleDateString()}`,
      Aux5: `Usuario: ${window.miaDetectionData.currentUser || 'Sistema'}`,
      Aux6: `Sitio: ${window.miaDetectionData.siteUrl || window.location.origin}`
    };
  }
    
    /**
     * Generar comentario de solicitud
     */
  generateProductRequestComment(formData) {
    return `SOLICITUD DE CREACIÓN DE ARTÍCULO EN VERIAL

DATOS DEL PRODUCTO WOOCOMMERCE:
ID: ${formData.wc_product_id}
Nombre: ${formData.wc_product_name}
SKU: ${formData.wc_product_sku}
Descripción: ${formData.wc_product_description}
Precio: ${formData.wc_product_price}
Stock: ${formData.wc_product_stock}

DATOS PARA VERIAL:
Nombre: ${formData.verial_name}
Descripción: ${formData.verial_description}
Referencia de Barras: ${formData.verial_sku}
Tipo: ${formData.verial_type}
Categoría ID: ${formData.verial_category}
Fabricante ID: ${formData.verial_manufacturer}
Precio: ${formData.verial_price}
Stock: ${formData.verial_stock}
IVA: ${formData.verial_iva}%
RE: ${formData.verial_re}%

CAMPOS CONFIGURABLES:
${Object.entries(formData.configurable_fields || {}).map(([key, value]) => 
    `- ${key}: ${value}`
).join('\n')}

COMENTARIOS ADICIONALES:
${formData.additional_comments || 'Ninguno'}

SOLICITUD: Por favor, crear este artículo en Verial con los datos proporcionados.`;
  }
    
    /**
     * Generar vista previa formateada
     */
  generateFormattedPreview(jsonData) {
    return `
            <div class="formatted-preview">
                <h3>📋 Resumen de la Solicitud</h3>
                <div class="preview-section">
                    <h4>Información Básica</h4>
                    <p><strong>Referencia:</strong> ${jsonData.Referencia}</p>
                    <p><strong>Fecha:</strong> ${jsonData.Fecha}</p>
                    <p><strong>Tipo:</strong> Presupuesto (Solicitud)</p>
                </div>
                
                <div class="preview-section">
                    <h4>Cliente</h4>
                    <p><strong>Nombre:</strong> ${jsonData.Cliente.Nombre}</p>
                    <p><strong>Email:</strong> ${jsonData.Cliente.Email}</p>
                </div>
                
                <div class="preview-section">
                    <h4>Contenido</h4>
                    <div class="comment-preview">
                        <pre>${jsonData.Contenido[0].Comentario}</pre>
                    </div>
                </div>
                
                <div class="preview-section">
                    <h4>Campos Auxiliares</h4>
                    <ul>
                        <li><strong>Aux1:</strong> ${jsonData.Aux1}</li>
                        <li><strong>Aux2:</strong> ${jsonData.Aux2}</li>
                        <li><strong>Aux3:</strong> ${jsonData.Aux3}</li>
                        <li><strong>Aux4:</strong> ${jsonData.Aux4}</li>
                        <li><strong>Aux5:</strong> ${jsonData.Aux5}</li>
                        <li><strong>Aux6:</strong> ${jsonData.Aux6}</li>
                    </ul>
                </div>
            </div>
        `;
  }
    
    /**
     * Enviar solicitud de producto
     */
  async submitProductRequest() {
    const formData = this.collectFormData();
    const jsonData = this.generateProductRequestJSON(formData);
        
    try {
      const response = await fetch(window.miaDetectionData.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: 'mia_submit_product_request',
          nonce: window.miaDetectionData.nonce,
          product_data: JSON.stringify(jsonData)
        })
      });
            
      const data = await response.json();
      if (data.success) {
        this.showNotification('Solicitud enviada exitosamente', 'success');
        this.closeProductRequestModal();
        this.loadDocumentRequests();
        this.loadDocumentStats();
      } else {
        this.showNotification('Error al enviar solicitud: ' + data.data, 'error');
      }
    } catch (error) {
      console.error('Error:', error);
      this.showNotification('Error de conexión', 'error');
    }
  }
    
    /**
     * Confirmar y enviar solicitud desde vista previa
     */
  confirmProductRequest() {
    this.closePreviewModal();
    this.submitProductRequest();
  }
    
    /**
     * Limpiar solicitudes de documentos antiguas
     */
  cleanupDocumentRequests() {
    const days = prompt('Ingrese el número de días de retención (por defecto 90):', '90');
    if (!days || isNaN(days)) {
      this.showNotification('Número de días inválido', 'error');
      return;
    }
        
    if (!confirm(`¿Estás seguro de que quieres eliminar las solicitudes más antiguas de ${days} días?`)) {
      return;
    }
        
    fetch(window.miaDetectionData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'mia_cleanup_document_requests',
        nonce: window.miaDetectionData.nonce,
        days
      })
    })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.loadDocumentRequests();
            this.loadDocumentStats();
            this.showNotification(data.data.message, 'success');
          } else {
            this.showNotification('Error al limpiar solicitudes: ' + data.data, 'error');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          this.showNotification('Error de conexión', 'error');
        });
  }
    
    /**
     * Cargar más solicitudes de documentos (paginación)
     */
  loadMoreDocumentRequests() {
    const currentRequests = document.querySelectorAll('.document-request-item').length;
    this.loadDocumentRequests(currentRequests, true);
  }
    
    /**
     * Actualizar paginación de solicitudes de documentos
     */
  updateDocumentPagination(hasMore) {
    const paginationEl = document.getElementById('document-requests-pagination');
    if (paginationEl) {
      paginationEl.style.display = hasMore ? 'block' : 'none';
    }
  }
    
    // ===== SISTEMA DE CONFIGURACIÓN DE NOTIFICACIONES =====
    
    /**
     * Mostrar panel de configuración de notificaciones
     */
  showNotificationConfigPanel() {
    // Ocultar otros paneles primero
    this.hideAllPanels();
        
    const modal = document.getElementById('notification-config-panel');
    if (modal) {
      modal.style.display = 'flex';
      modal.classList.add('show');
      this.loadNotificationConfig();
    }
  }
    
    /**
     * Ocultar panel de configuración de notificaciones
     */
  hideNotificationConfigPanel() {
    const modal = document.getElementById('notification-config-panel');
    if (modal) {
      modal.classList.remove('show');
      setTimeout(() => {
        modal.style.display = 'none';
      }, 300);
    }
  }
    
    /**
     * Cargar configuración de notificaciones desde el servidor
     */
  loadNotificationConfig() {
    fetch(window.miaDetectionData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'mia_get_notification_config',
        nonce: window.miaDetectionData.nonce
      })
    })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.renderNotificationConfig(data.data);
          } else {
            this.showNotification('Error al cargar configuración: ' + data.data, 'error');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          this.showNotification('Error de conexión al cargar configuración', 'error');
        });
  }
    
    /**
     * Renderizar configuración de notificaciones en la UI
     */
  renderNotificationConfig(config) {
        // Configuración general
    const notificationsEnabled = document.getElementById('notifications-enabled');
    const autoDocumentRequests = document.getElementById('auto-document-requests');
    const retentionDays = document.getElementById('notification-retention-days');
        
    if (notificationsEnabled) notificationsEnabled.checked = config.notifications_enabled;
    if (autoDocumentRequests) autoDocumentRequests.checked = config.auto_document_requests;
    if (retentionDays) retentionDays.value = config.notification_retention_days;
        
        // Tipos de notificaciones
    this.renderNotificationTypes(config.notification_types, config.available_types);
        
        // Configuración de horarios
    this.renderNotificationSchedule(config.notification_schedule);
        
        // Configuración de emails
    this.renderNotificationEmails(config.notification_emails);
        
        // Umbrales
    this.renderNotificationThresholds(config.notification_thresholds);
  }
    
    /**
     * Renderizar tipos de notificaciones
     */
  renderNotificationTypes(types, availableTypes) {
    const container = document.getElementById('notification-types-grid');
    if (!container) return;
        
    container.innerHTML = '';
        
    Object.entries(availableTypes).forEach(([type, info]) => {
      const status = types[type] || 'disabled';
      const isEnabled = status === 'enabled';
            
      const typeElement = document.createElement('div');
      typeElement.className = 'notification-type-item';
      typeElement.innerHTML = `
                <div class="notification-type-header">
                    <span class="notification-type-icon">${info.icon}</span>
                    <h4 class="notification-type-title">${info.label}</h4>
                </div>
                <p class="notification-type-description">${info.description}</p>
                <div class="notification-type-status">
                    <div class="status-toggle ${isEnabled ? 'active' : ''}" onclick="toggleNotificationType('${type}')"></div>
                    <span class="status-label">${isEnabled ? 'Habilitado' : 'Deshabilitado'}</span>
                </div>
            `;
            
      container.appendChild(typeElement);
    });
  }
    
    /**
     * Renderizar configuración de horarios
     */
  renderNotificationSchedule(schedule) {
    const scheduleType = document.getElementById('notification-schedule-type');
    const scheduleTime = document.getElementById('notification-schedule-time');
    const scheduleDays = document.getElementById('schedule-days-option');
        
    if (scheduleType) {
      scheduleType.value = schedule.type;
      this.updateScheduleOptions(schedule.type);
    }
        
    if (scheduleTime) scheduleTime.value = schedule.time;
        
    if (scheduleDays) {
      const checkboxes = scheduleDays.querySelectorAll('input[type="checkbox"]');
      checkboxes.forEach(checkbox => {
        checkbox.checked = schedule.days.includes(checkbox.value);
      });
    }
  }
    
    /**
     * Renderizar configuración de emails
     */
  renderNotificationEmails(emails) {
    const adminEmail = document.getElementById('notification-admin-email');
    const additionalEmails = document.getElementById('notification-additional-emails');
        
    if (adminEmail) adminEmail.value = emails.admin || '';
    if (additionalEmails) additionalEmails.value = emails.additional.join(', ');
  }
    
    /**
     * Renderizar umbrales de notificaciones
     */
  renderNotificationThresholds(thresholds) {
    const stockLow = document.getElementById('stock-low-quantity');
    const frequencyLimit = document.getElementById('notification-frequency-limit');
    const batchSize = document.getElementById('batch-size');
        
    if (stockLow) stockLow.value = thresholds.stock_low_quantity;
    if (frequencyLimit) frequencyLimit.value = thresholds.notification_frequency_limit;
    if (batchSize) batchSize.value = thresholds.batch_size;
  }
    
    /**
     * Actualizar opciones de horario según el tipo seleccionado
     */
  updateScheduleOptions(scheduleType) {
    const timeOption = document.getElementById('schedule-time-option');
    const daysOption = document.getElementById('schedule-days-option');
        
    if (timeOption) {
      timeOption.style.display = ['daily', 'weekly'].includes(scheduleType) ? 'block' : 'none';
    }
        
    if (daysOption) {
      daysOption.style.display = scheduleType === 'weekly' ? 'block' : 'none';
    }
  }
    
    /**
     * Guardar configuración de notificaciones
     */
  saveNotificationConfig() {
    const config = this.collectNotificationConfig();
        
    fetch(window.miaDetectionData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'mia_save_notification_config',
        nonce: window.miaDetectionData.nonce,
        config: JSON.stringify(config)
      })
    })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.showNotification('Configuración guardada correctamente', 'success');
          } else {
            this.showNotification('Error al guardar configuración: ' + data.data, 'error');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          this.showNotification('Error de conexión', 'error');
        });
  }
    
    /**
     * Recopilar configuración de notificaciones del formulario
     */
  collectNotificationConfig() {
    const config = {};
        
        // Configuración general
    const notificationsEnabledEl = document.getElementById('notifications-enabled');
    config.notifications_enabled = notificationsEnabledEl ? !!notificationsEnabledEl.checked : false;

    const autoDocumentRequestsEl = document.getElementById('auto-document-requests');
    config.auto_document_requests = autoDocumentRequestsEl ? !!autoDocumentRequestsEl.checked : false;

    const notificationRetentionDaysEl = document.getElementById('notification-retention-days');
    let retentionVal = notificationRetentionDaysEl ? notificationRetentionDaysEl.value : '';
    retentionVal = retentionVal === '' ? 30 : retentionVal;
    config.notification_retention_days = Number.isNaN(parseInt(retentionVal)) ? 30 : parseInt(retentionVal, 10);

        // Tipos de notificaciones (se maneja por separado)
    config.notification_types = this.collectNotificationTypes();
        
        // Configuración de horarios
    const scheduleTypeEl = document.getElementById('notification-schedule-type');
    const scheduleTimeEl = document.getElementById('notification-schedule-time');

    config.notification_schedule = {
      type: scheduleTypeEl ? scheduleTypeEl.value : 'immediate',
      time: scheduleTimeEl ? scheduleTimeEl.value : '09:00',
      days: this.collectSelectedDays(),
      timezone: typeof Intl !== 'undefined' && Intl.DateTimeFormat && typeof Intl.DateTimeFormat().resolvedOptions === 'function'
                ? Intl.DateTimeFormat().resolvedOptions().timeZone
                : 'UTC'
    };
        
        // Configuración de emails
    const adminEmailEl = document.getElementById('notification-admin-email');
    const additionalEmailsEl = document.getElementById('notification-additional-emails');

    config.notification_emails = {
      admin: adminEmailEl ? adminEmailEl.value : '',
      additional: this.parseEmailList(additionalEmailsEl ? additionalEmailsEl.value : ''),
      by_type: {}
    };
        // Umbrales
    config.notification_thresholds = {
      stock_low_quantity: (() => {
        const element = document.getElementById('stock-low-quantity');
        const val = element ? element.value : '';
        const num = parseInt(val, 10);
        return Number.isNaN(num) ? 5 : num;
      })(),
      notification_frequency_limit: (() => {
        const element = document.getElementById('notification-frequency-limit');
        const val = element ? element.value : '';
        const num = parseInt(val, 10);
        return Number.isNaN(num) ? 10 : num;
      })(),
      batch_size: (() => {
        const element = document.getElementById('batch-size');
        const val = element ? element.value : '';
        const num = parseInt(val, 10);
        return Number.isNaN(num) ? 50 : num;
      })()
    };
    return config;
  }
    
    /**
     * Recopilar tipos de notificaciones seleccionados
     */
  collectNotificationTypes() {
    const types = {};
    const toggles = document.querySelectorAll('.status-toggle');
        
    toggles.forEach(toggle => {
      const onclickAttr = toggle.getAttribute('onclick');
            
      if (onclickAttr) {
        const match = onclickAttr.match(/toggleNotificationType\('([^']+)'\)/);
        if (match && match[1]) {
          types[match[1]] = toggle.classList.contains('active') ? 'enabled' : 'disabled';
        }
      }
    });
    
    return types;   
  }
    
    /**
     * Recopilar días seleccionados
     */
  collectSelectedDays() {
    const days = [];
    const checkboxes = document.querySelectorAll('#schedule-days-option input[type="checkbox"]:checked');
    checkboxes.forEach(checkbox => {
      days.push(checkbox.value);
    });
    return days;
  }
    
    /**
     * Parsear lista de emails
     */
  parseEmailList(emailString) {
    return emailString.split(',')
            .map(email => email.trim())
            .filter(email => email.length > 0);
  }
    
    /**
     * Resetear configuración de notificaciones
     */
  resetNotificationConfig() {
    if (!confirm('¿Estás seguro de que quieres resetear la configuración a valores por defecto?')) {
      return;
    }
        
    fetch(window.miaDetectionData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'mia_reset_notification_config',
        nonce: window.miaDetectionData.nonce
      })
    })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.showNotification('Configuración reseteada correctamente', 'success');
            this.loadNotificationConfig();
          } else {
            this.showNotification('Error al resetear configuración: ' + data.data, 'error');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          this.showNotification('Error de conexión', 'error');
        });
  }
    
    /**
     * Validar configuración de notificaciones
     */
  validateNotificationConfig() {
    fetch(window.miaDetectionData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'mia_validate_notification_config',
        nonce: window.miaDetectionData.nonce
      })
    })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            this.showValidationResults(data.data);
          } else {
            this.showNotification('Error al validar configuración: ' + data.data, 'error');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          this.showNotification('Error de conexión', 'error');
        });
  }
    
    /**
     * Mostrar resultados de validación
     */
  showValidationResults(validation) {
    const container = document.querySelector('.notification-config-content');
    if (!container) return;
        
    let validationHtml = '<div class="config-validation ' + (validation.valid ? 'success' : 'error') + '">';
    validationHtml += '<div class="validation-title">' + (validation.valid ? '✅ Configuración Válida' : '❌ Errores de Configuración') + '</div>';
        
    if (validation.errors.length > 0) {
      validationHtml += '<ul class="validation-list">';
      validation.errors.forEach(error => {
        validationHtml += '<li>' + error + '</li>';
      });
      validationHtml += '</ul>';
    }
        
    if (validation.warnings.length > 0) {
      validationHtml += '<div class="validation-title">⚠️ Advertencias:</div>';
      validationHtml += '<ul class="validation-list">';
      validation.warnings.forEach(warning => {
        validationHtml += '<li>' + warning + '</li>';
      });
      validationHtml += '</ul>';
    }
        
    validationHtml += '</div>';
        
        // Insertar al principio del contenido
    container.insertAdjacentHTML('afterbegin', validationHtml);
        
        // Remover después de 10 segundos
    setTimeout(() => {
      const validationEl = container.querySelector('.config-validation');
      if (validationEl) {
        validationEl.remove();
      }
    }, 10000);
  }

    /**
     * ========================================
     * SISTEMA RESPONSIVE AVANZADO PARA DETECCIÓN
     * ========================================
     */

    /**
     * Ajustar layout de detección basado en tamaño de ventana
     * @returns {void}
     */
  adjustDetectionLayout() {
    clearTimeout(this.detectionResizeTimeout);
    this.detectionResizeTimeout = setTimeout(() => {
      const windowWidth = window.innerWidth;
      const windowHeight = window.innerHeight;
            
            // Ajustar altura del sidebar
      const sidebar = document.querySelector('.detection-sidebar');
      if (sidebar) {
        if (windowWidth < 769) {
          sidebar.style.height = 'auto';
          sidebar.style.position = 'relative';
        } else {
          sidebar.style.height = 'calc(100vh - 40px)';
          sidebar.style.position = 'sticky';
        }
      }
            
            // Ajustar grid de estadísticas basado en tamaño real
      const statsGrid = document.querySelector('.detection-stats-grid');
      if (statsGrid) {
        const gridWidth = statsGrid.offsetWidth;
                
                // Remover clases existentes
        statsGrid.classList.remove('mobile-grid', 'tablet-grid', 'desktop-grid');
                
        if (gridWidth < 400) {
          statsGrid.classList.add('mobile-grid');
        } else if (gridWidth < 800) {
          statsGrid.classList.add('tablet-grid');
        } else {
          statsGrid.classList.add('desktop-grid');
        }
      }
            
            // Ajustar menú de navegación para móviles
      const navMenu = document.querySelector('.detection-nav-menu');
      if (navMenu) {
        if (windowWidth < 577) {
          navMenu.classList.add('horizontal-scroll');
        } else {
          navMenu.classList.remove('horizontal-scroll');
        }
      }
            
            // Ajustar para tablets en landscape
      if (windowWidth > 768 && windowHeight < 500) {
        document.body.classList.add('landscape-mode');
      } else {
        document.body.classList.remove('landscape-mode');
      }
            
            // Mejorar scroll en móviles
      if ('ontouchstart' in window) {
        if (navMenu) {
          navMenu.classList.add('touch-device');
        }
      }
            
    }, 100);
  }

    /**
     * Vincular eventos responsive para detección
     * @returns {void}
     */
  bindDetectionResponsiveEvents() {
    window.addEventListener('resize', () => {
      this.adjustDetectionLayout();
    });

    window.addEventListener('orientationchange', () => {
      setTimeout(() => {
        this.adjustDetectionLayout();
      }, 100);
    });

  }
}


// Prevenir carga múltiple del script
(function() {
  'use strict';
  
  // Verificar que el script se está cargando
  if (typeof window.window.miaDetectionDataLoaded !== 'undefined') {
    console.log('admin-dashboard.js already loaded, skipping reinitialization');
    return;
  }
  
  console.log('admin-dashboard.js loaded');
  window.window.miaDetectionDataLoaded = true;

  // Definir funciones globales inmediatamente para evitar errores de referencia
  window.closeSettings = function() {
    console.log('closeSettings called, dashboard available:', !!window.dashboard);
    
    // Intentar cerrar el modal directamente primero
    const modal = document.getElementById('settingsModal');
    if (modal) {
      console.log('Closing modal directly');
      modal.style.display = 'none';
      return;
    }
    
    // Si el modal no existe, intentar usar el dashboard
    if (window.dashboard && typeof window.dashboard.closeSettings === 'function') {
      window.dashboard.closeSettings();
    } else {
      console.error('closeSettings function not available - dashboard not initialized and modal not found');
    }
  };

  window.saveSettings = function() {
    if (window.dashboard && typeof window.dashboard.saveSettings === 'function') {
      window.dashboard.saveSettings();
    } else {
      console.error('saveSettings function not available - dashboard not initialized');
    }
  };

  // Inicializar cuando el DOM esté listo
  document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing DetectionDashboard');
    
    // Verificar que el modal existe
    const modal = document.getElementById('settingsModal');
    console.log('Settings modal found:', !!modal);
    
    window.dashboard = new DetectionDashboard();
    console.log('DetectionDashboard initialized:', !!window.dashboard);
    
    // Hacer funciones disponibles globalmente para compatibilidad con onclick
    window.toggleDetection = () => window.dashboard.toggleDetection();
    window.executeNow = () => window.dashboard.executeNow();
    window.showDetectionConfig = () => window.dashboard.showDetectionConfig();
    window.hideDetectionConfig = () => window.dashboard.hideDetectionConfig();
    window.saveDetectionConfig = () => window.dashboard.saveDetectionConfig();
    
    // Funciones adicionales para el template HTML
        // Las funciones globales ahora se manejan a través de data attributes en bindMenuEvents()
    
    // Event listener para cambio de tipo de horario
    const scheduleTypeSelect = document.getElementById('notification-schedule-type');
    if (scheduleTypeSelect) {
      scheduleTypeSelect.addEventListener('change', function() {
        if (window.dashboard) {
          window.dashboard.updateScheduleOptions(this.value);
        }
      });
    }
  });

// ===== FUNCIONES GLOBALES PARA NOTIFICACIONES =====

/**
 * Mostrar panel de notificaciones
 */
  function showNotificationsPanel() {
    if (window.dashboard) {
      window.dashboard.showNotificationsPanel();
    }
  }

/**
 * Ocultar panel de notificaciones
 */
  function hideNotificationsPanel() {
    if (window.dashboard) {
      window.dashboard.hideNotificationsPanel();
    }
  }


/**
 * Ver producto en WooCommerce
 */
  function viewProduct(productId) {
    if (window.dashboard) {
      window.dashboard.viewProduct(productId);
    }
  }

/**
 * Ver detalles de sincronización
 */
  function viewSyncDetails(notificationId) {
    if (window.dashboard) {
      window.dashboard.viewSyncDetails(notificationId);
    }
  }
  // Exportar para uso en módulos
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = DetectionDashboard;
  }

})(); // Cerrar función auto-ejecutable
