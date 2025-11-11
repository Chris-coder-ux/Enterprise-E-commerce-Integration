/**
 * JavaScript para la página de sincronización de pedidos
 * Funcionalidad específica para el monitor de pedidos
 * 
 * @package MiIntegracionApi
 * @since 1.0.0
 */

jQuery(document).ready(function($) {
    // console.log('DEBUG: order-sync-dashboard.js cargado');
    // console.log('DEBUG: Elemento #mi-order-sync-monitor encontrado:', $('#mi-order-sync-monitor').length);
    
    if (!$('#mi-order-sync-monitor').length) {
        // console.log('DEBUG: Elemento #mi-order-sync-monitor no encontrado, saliendo');
        return;
    }
    
    // Verificar que las variables necesarias estén disponibles
    if (typeof miIntegracionApiDashboard === 'undefined') {
        console.error('miIntegracionApiDashboard no está definido');
        console.error('Verificando si el script se está cargando en la página correcta...');
        return;
    }
    
    // Verificar que las propiedades necesarias estén disponibles
    // console.log('DEBUG: Verificando variables de configuración...');
    // console.log('miIntegracionApiDashboard:', miIntegracionApiDashboard);
    // console.log('Tipo de miIntegracionApiDashboard:', typeof miIntegracionApiDashboard);
    
    // Manejar tanto ajaxurl como ajaxUrl (compatibilidad)
    const ajaxUrl = miIntegracionApiDashboard.ajaxurl || miIntegracionApiDashboard.ajaxUrl;
    const nonce = miIntegracionApiDashboard.nonce;
    
    console.log('ajaxurl disponible:', !!ajaxUrl);
    console.log('nonce disponible:', !!nonce);
    console.log('ajaxurl valor:', ajaxUrl);
    console.log('nonce valor:', nonce);
    
    if (!ajaxUrl || !nonce) {
        console.error('Variables de configuración AJAX incompletas:');
        console.error('ajaxurl/ajaxUrl:', ajaxUrl);
        console.error('nonce:', nonce);
        console.error('miIntegracionApiDashboard completo:', miIntegracionApiDashboard);
        console.error('Claves disponibles en miIntegracionApiDashboard:', Object.keys(miIntegracionApiDashboard));
        return;
    }
    
    // Normalizar las variables para uso consistente
    miIntegracionApiDashboard.ajaxurl = ajaxUrl;
    
    // Variables globales - con verificación de existencia de elementos
    const orderSyncMonitor = {
        $tableContainer: $('.mi-order-sync-table-container'),
        $loading: $('#order-sync-loading'),
        $paginationContainer: $('.mi-order-sync-pagination-container'),
        $statsCards: $('.mi-order-sync-stat-card'),
        $searchInput: $('.mi-order-sync-search'),
        $filterSelect: $('.mi-order-sync-select'),
        $refreshBtn: $('.mi-order-sync-refresh'),
        $syncAllBtn: $('.mi-order-sync-sync-all'),
        
        // Estado interno
        orderData: [],
        currentPage: 1,
        totalPages: 1,
        totalOrders: 0,
        perPage: 10,
        currentFilter: 'all',
        searchTerm: '',
        isLoading: false,
        
        // Inicialización
        init: function() {
            // console.log('DEBUG: Inicializando orderSyncMonitor...');
            // console.log('DEBUG: Elementos encontrados:');
            // console.log('- $tableContainer:', this.$tableContainer.length);
            // console.log('- $loading:', this.$loading.length);
            // console.log('- $paginationContainer:', this.$paginationContainer.length);
            // console.log('- $statsCards:', this.$statsCards.length);
            // console.log('- $searchInput:', this.$searchInput.length);
            // console.log('- $filterSelect:', this.$filterSelect.length);
            // console.log('- $refreshBtn:', this.$refreshBtn.length);
            // console.log('- $syncAllBtn:', this.$syncAllBtn.length);
            
            this.bindEvents();
            this.loadOrders();
            this.startAutoRefresh();
        },
        
        // Vincular eventos
        bindEvents: function() {
            const self = this;
            
            // Búsqueda
            this.$searchInput.on('input', function() {
                clearTimeout(self.searchTimeout);
                self.searchTimeout = setTimeout(function() {
                    self.searchTerm = self.$searchInput.val();
                    self.currentPage = 1;
                    self.loadOrders();
                }, 500);
            });
            
            // Filtros
            this.$filterSelect.on('change', function() {
                self.currentFilter = $(this).val();
                self.currentPage = 1;
                self.loadOrders();
            });
            
            // Botón de actualizar
            this.$refreshBtn.on('click', function(e) {
                e.preventDefault();
                self.loadOrders();
            });
            
            // Botón de sincronizar todos
            this.$syncAllBtn.on('click', function(e) {
                e.preventDefault();
                self.syncAllOrders();
            });
            
            // Paginación
            this.$paginationContainer.on('click', '.page-number', function(e) {
                e.preventDefault();
                if ($(this).hasClass('disabled')) return;
                
                const page = parseInt($(this).data('page'));
                if (page && page !== self.currentPage) {
                    self.currentPage = page;
                    self.loadOrders();
                }
            });
        },
        
        // Cargar pedidos
        loadOrders: function() {
            if (this.isLoading) return;
            
            this.isLoading = true;
            this.showLoading();
            
            const self = this;
            
            // console.log('DEBUG: Iniciando llamada AJAX a mi_integracion_get_orders_sync_status');
            // console.log('DEBUG: Parámetros:', {
            //     filter: this.currentFilter,
            //     search: this.searchTerm,
            //     page: this.currentPage,
            //     per_page: this.perPage
            // });
            
            AjaxManager.call('mi_integracion_get_orders_sync_status', {
                    filter: this.currentFilter,
                    search: this.searchTerm,
                    page: this.currentPage,
                    per_page: this.perPage
                }, function(response) {
                    console.log('Respuesta AJAX recibida:', response);
                    console.log('Tipo de respuesta:', typeof response);
                    console.log('response.success:', response.success);
                    console.log('response.data:', response.data);
                    
                    if (response.success) {
                        // Guardar datos y actualizar UI
                        self.orderData = response.data.orders || [];
                        self.renderOrdersTable();
                        self.updatePagination(response.data.total_orders, response.data.total_pages);
                        
                        // Crear objeto stats desde los datos recibidos
                        const stats = {
                            total: response.data.total || 0,
                            synced: response.data.synced || 0,
                            failed: response.data.failed || 0,
                            pending: response.data.pending || 0
                        };
                        // console.log('DEBUG: stats calculadas:', stats);
                        self.updateStatusCounts(stats);
                    } else {
                        console.error('Error en respuesta AJAX:', response);
                        console.error('response.data.message:', response.data?.message);
                        console.error('response.data:', response.data);
                        ErrorHandler.logError('Error al cargar pedidos: ' + (response.data?.message || 'Error desconocido - respuesta: ' + JSON.stringify(response)), 'ORDERS_LOAD');
                    }
                    
                    // Cerrar estado de loading
                    self.isLoading = false;
                    self.hideLoading();
                },
                function(xhr, status, error) {
                    console.error('Error AJAX:', {xhr, status, error});
                    console.error('xhr.status:', xhr ? xhr.status : 'N/A');
                    console.error('xhr.responseText:', xhr ? xhr.responseText : 'N/A');
                    console.error('xhr.readyState:', xhr ? xhr.readyState : 'N/A');
                    console.error('status:', status);
                    console.error('error:', error);
                    
                    let errorMessage = 'Error de conexión';
                    if (xhr && xhr.status) {
                        errorMessage = `Error ${xhr.status}: ${error || 'Error de conexión'}`;
                    } else if (status === 'timeout') {
                        errorMessage = 'Timeout: La petición tardó demasiado';
                    } else if (status === 'error' && !error) {
                        errorMessage = 'Error de red: No se pudo conectar al servidor';
                    }
                    
                    ErrorHandler.logError('Error AJAX al cargar pedidos: ' + errorMessage, 'ORDERS_LOAD_AJAX');
                    
                    // Mostrar mensaje de error en la tabla
                    const tbody = self.$tableContainer.find('tbody');
                    tbody.html('<tr><td colspan="7" class="text-center" style="color: red;">Error al cargar pedidos: ' + errorMessage + '</td></tr>');
                    
                    // Cerrar estado de loading
                    self.isLoading = false;
                    self.hideLoading();
                }
            );
        },
        
        // Mostrar loading
        showLoading: function() {
            // Mostrar indicador sutil en la barra de herramientas
            this.$refreshBtn.addClass('loading').prop('disabled', true);
            this.$refreshBtn.find('.dashicons').addClass('spinning');
            
            // Mostrar overlay sutil solo si no hay datos
            if (this.orderData.length === 0) {
                this.$loading.show();
            }
        },
        
        // Ocultar loading
        hideLoading: function() {
            // Restaurar botón de actualizar
            this.$refreshBtn.removeClass('loading').prop('disabled', false);
            this.$refreshBtn.find('.dashicons').removeClass('spinning');
            
            // Ocultar overlay
            this.$loading.hide();
        },
        
        // Renderizar tabla de pedidos
        renderOrdersTable: function() {
            const tbody = this.$tableContainer.find('tbody');
            
            if (this.orderData.length === 0) {
                tbody.html('<tr><td colspan="7" class="text-center">No se encontraron pedidos</td></tr>');
                return;
            }
            
            let html = '';
            // console.log('DEBUG: orderData para renderizar:', this.orderData);
            this.orderData.forEach(function(order) {
                // console.log('DEBUG: Procesando pedido:', order);
                const statusClass = order.sync_status || 'pending';
                const statusText = order.sync_status === 'success' ? 'Sincronizado' : 
                                 order.sync_status === 'error' ? 'Error' : 'Pendiente';
                
                html += `
                    <tr>
                        <td>#${order.order_id}</td>
                        <td>${order.customer_name || 'N/A'}</td>
                        <td>${order.total || 'N/A'}</td>
                        <td>
                            <span class="sync-status sync-${statusClass}">${statusText}</span>
                        </td>
                        <td>${order.date_created || 'N/A'}</td>
                        <td>
                            <span class="last-sync-time">${order.sync_timestamp || 'Nunca'}</span>
                        </td>
                        <td>
                            <div class="order-actions">
                                <button class="button button-small preview-order-btn" data-order-id="${order.order_id}" title="Vista previa del JSON">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                                <button class="button button-small sync-single-order" data-order-id="${order.order_id}" title="Sincronizar pedido">
                                    <span class="dashicons dashicons-update"></span>
                                </button>
                                <a href="${order.edit_url || '#'}" class="button button-small" title="Editar pedido" target="_blank">
                                    <span class="dashicons dashicons-edit"></span>
                                </a>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            // console.log('DEBUG: HTML generado:', html);
            tbody.html(html);
            
            // Vincular eventos de botones de sincronización individual
            tbody.find('.sync-single-order').on('click', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                orderSyncMonitor.syncSingleOrder(orderId, $(this));
            });
        },
        
        // Actualizar paginación
        updatePagination: function(totalOrders, totalPages) {
            this.totalOrders = totalOrders;
            this.totalPages = totalPages;
            
            if (totalPages <= 1) {
                this.$paginationContainer.hide();
                return;
            }
            
            this.$paginationContainer.show();
            
            let html = '<div class="mi-order-sync-pagination">';
            
            // Botón anterior
            html += `<span class="page-number ${this.currentPage === 1 ? 'disabled' : ''}" data-page="${this.currentPage - 1}">‹</span>`;
            
            // Números de página
            const startPage = Math.max(1, this.currentPage - 2);
            const endPage = Math.min(totalPages, this.currentPage + 2);
            
            for (let i = startPage; i <= endPage; i++) {
                const activeClass = i === this.currentPage ? 'active' : '';
                html += `<span class="page-number ${activeClass}" data-page="${i}">${i}</span>`;
            }
            
            // Botón siguiente
            html += `<span class="page-number ${this.currentPage === totalPages ? 'disabled' : ''}" data-page="${this.currentPage + 1}">›</span>`;
            
            html += '</div>';
            this.$paginationContainer.html(html);
        },
        
        // Actualizar contadores de estado
        updateStatusCounts: function(stats) {
            // Validar que stats existe y es un objeto
            if (!stats || typeof stats !== 'object') {
                console.warn('updateStatusCounts: stats no está definido o no es un objeto:', stats);
                return;
            }
            
            this.$statsCards.each(function() {
                const $card = $(this);
                const status = $card.data('status');
                const count = stats[status] || 0;
                
                $card.find('.mi-order-sync-stat-value').text(count);
            });
        },
        
        // Sincronizar un pedido individual
        syncSingleOrder: function(orderId, $btn) {
            if (!orderId) return;
            
            const originalContent = $btn.html();
            $btn.html('<span class="dashicons dashicons-update spinning"></span>').prop('disabled', true);
            
            // Llamada AJAX para sincronizar pedido
            const self = this;
            // console.log('DEBUG: Iniciando sincronización de pedido:', orderId);
            // console.log('DEBUG: AjaxManager disponible:', typeof AjaxManager);
            // console.log('DEBUG: Variables AJAX:', {
            //     ajaxurl: miIntegracionApiDashboard?.ajaxurl,
            //     nonce: miIntegracionApiDashboard?.nonce
            // });
            
            // Obtener referencia a la fila antes de la llamada AJAX
            const $row = $btn.closest('tr');
            
            AjaxManager.call('mi_integracion_sync_single_order', {
                    order_id: orderId
                }, function(response) {
                    console.log('Respuesta de sincronización individual:', response);
                    
                    if (response.success) {
                        // Actualizar la fila del pedido
                        $row.find('.sync-status').removeClass('sync-pending sync-error').addClass('sync-success').text('Sincronizado');
                        
                        // Mostrar notificación de éxito
                        self.showNotification('Pedido sincronizado correctamente', 'success');
                        
                        // Actualizar contadores
                        self.loadOrders();
                    } else {
                        console.error('Error en sincronización individual:', response);
                        $row.find('.sync-status').removeClass('sync-pending sync-success').addClass('sync-error').text('Error');
                        self.showNotification('Error al sincronizar pedido: ' + (response.data?.message || 'Error desconocido'), 'error');
                    }
                    
                    // Restaurar botón
                    $btn.html(originalContent).prop('disabled', false);
                },
                function(xhr, status, error) {
                    console.error('Error AJAX en sincronización individual:', {xhr, status, error});
                    
                    // Restaurar botón
                    $btn.html(originalContent).prop('disabled', false);
                    
                    // Mostrar error
                    self.showNotification('Error de conexión al sincronizar pedido', 'error');
                }
            );
        },
        
        // Sincronizar todos los pedidos
        syncAllOrders: function() {
            if (!confirm('¿Estás seguro de que deseas sincronizar todos los pedidos?')) {
                return;
            }
            
            this.$syncAllBtn.prop('disabled', true).text('Sincronizando...');
            
            const self = this;
            
            AjaxManager.call('mi_integracion_sync_all_orders', {}, function(response) {
                if (response.success) {
                    self.showNotification('Sincronización masiva iniciada', 'success');
                    self.loadOrders(); // Recargar para ver el progreso
                } else {
                    self.showNotification('Error al iniciar sincronización masiva: ' + (response.data?.message || 'Error desconocido'), 'error');
                }
                
                self.$syncAllBtn.prop('disabled', false).text('Sincronizar Todos');
            }, function(xhr, status, error) {
                console.error('Error en sincronización masiva:', {xhr, status, error});
                self.showNotification('Error de conexión en sincronización masiva', 'error');
                self.$syncAllBtn.prop('disabled', false).text('Sincronizar Todos');
            });
        },
        
        // Auto-refresh
        startAutoRefresh: function() {
            const self = this;
            
            // Refrescar cada 30 segundos
            setInterval(function() {
                if (!self.isLoading) {
                    self.loadOrders();
                }
            }, 30000);
        },
        
        // Mostrar notificación
        showNotification: function(message, type) {
            const $notice = $(`<div class="notice notice-${type} is-dismissible"><p>${message}</p></div>`);
            $('.mi-order-sync-main-content').prepend($notice);
            
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        }
    };
    
    // Inicializar el monitor de sincronización con verificación de seguridad
    // console.log('DEBUG: Intentando inicializar orderSyncMonitor...');
    // console.log('DEBUG: orderSyncMonitor definido:', typeof orderSyncMonitor !== 'undefined');
    // console.log('DEBUG: orderSyncMonitor.init existe:', typeof orderSyncMonitor !== 'undefined' && orderSyncMonitor.init);
    
    // Exponer orderSyncMonitor globalmente para que otros scripts puedan acceder
    // eslint-disable-next-line no-undef
    (typeof globalThis !== 'undefined' ? globalThis : window).orderSyncMonitor = orderSyncMonitor;
    
    try {
        if (typeof orderSyncMonitor !== 'undefined' && orderSyncMonitor.init) {
            // console.log('DEBUG: Llamando a orderSyncMonitor.init()');
            orderSyncMonitor.init();
        } else {
            console.error('orderSyncMonitor no está definido correctamente');
        }
    } catch (error) {
        console.error('Error al inicializar orderSyncMonitor:', error);
    }
});
